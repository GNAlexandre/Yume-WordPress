#!/usr/bin/env node
/**
 * Contrôle de rendu : ouvre les pages de démonstration dans Chromium (Playwright) et compare les
 * styles calculés (font-size, font-weight, color…) des blocs Yume au tableau tools/ci/rendu-attendus.js.
 * Détecte les régressions de spécificité CSS face aux styles globaux du cœur (revue RC-1, WordPress 6.6).
 * Sur les mêmes pages, exécute axe-core (règles WCAG 2.1 A et AA) : toute violation d'impact
 * « serious » ou « critical » fait échouer le contrôle, sauf exception listée dans AXE_EXCEPTIONS
 * (audit AMEL-14). --sans-axe désactive ce second contrôle (diagnostic local seulement).
 *
 *   node tools/ci/rendu.js --base http://127.0.0.1:8080 [--sortie mesures.json] [--libelle "WP 6.6"]
 *   node tools/ci/rendu.js --comparer mesures-6.6.json mesures-latest.json
 *
 * Le site doit être servi et contenir la démo (tools/playground/demo.php) : tools/ci/rendu.sh fait
 * tout (serveur php -S + router.php, puis ce script). Code de sortie : 0 conforme, 1 écarts,
 * 2 erreur d'utilisation ou technique. Voir docs/guide-developpeur.md, « Contrôle de rendu ».
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

/** Règles axe exécutées : WCAG 2.0 et 2.1, niveaux A et AA. */
const AXE_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ];

/** Impacts qui font échouer le contrôle. */
const AXE_IMPACTS_BLOQUANTS = [ 'serious', 'critical' ];

/**
 * Violations tolérées, chacune justifiée : seulement pour un balisage produit par le cœur de
 * WordPress (ou un tiers) que Yume ne peut pas corriger. { regle, page (facultatif : nom de la
 * page de rendu-attendus.js), cible (facultatif : sous-chaîne du sélecteur axe), raison }.
 * Les pages sont visitées en anonyme : pas de barre d'administration (#wp-skip-link, #wpadminbar),
 * donc aucune exception à ce titre aujourd'hui.
 */
const AXE_EXCEPTIONS = [];

function usage( message ) {
	if ( message ) {
		console.error( `[rendu] erreur : ${ message }` );
	}
	console.error( 'Usage : node tools/ci/rendu.js --base URL [--sortie FICHIER] [--libelle TEXTE] [--attendus FICHIER] [--sans-axe]' );
	console.error( '        node tools/ci/rendu.js --comparer A.json B.json' );
	process.exit( 2 );
}

function lireArguments( argv ) {
	const options = { base: process.env.YUME_RENDU_BASE || '', sortie: '', libelle: '', attendus: path.join( __dirname, 'rendu-attendus.js' ), comparer: null, axe: true };
	for ( let i = 0; i < argv.length; i++ ) {
		const valeur = () => {
			if ( i + 1 >= argv.length ) {
				usage( `valeur manquante pour ${ argv[ i ] }` );
			}
			return argv[ ++i ];
		};
		switch ( argv[ i ] ) {
			case '--base': options.base = valeur(); break;
			case '--sortie': options.sortie = valeur(); break;
			case '--libelle': options.libelle = valeur(); break;
			case '--attendus': options.attendus = path.resolve( valeur() ); break;
			case '--comparer': options.comparer = [ valeur(), valeur() ]; break;
			case '--sans-axe': options.axe = false; break;
			case '-h': case '--aide': usage(); break;
			default: usage( `option inconnue : ${ argv[ i ] }` );
		}
	}
	return options;
}

/**
 * Mesure, dans la page, les propriétés demandées pour chaque contrôle. S'exécute dans le navigateur.
 *
 * @param {Array} controles Contrôles de la page (selecteur, attendu).
 * @return {Array} Pour chaque contrôle : { selecteur, erreur?, valeurs: { prop: { obtenu, attendu } } }.
 */
function mesurerDansLaPage( controles ) {
	const resoudrePreset = ( el, slug ) => {
		const variable = `--wp--preset--color--${ slug }`;
		if ( '' === getComputedStyle( el ).getPropertyValue( variable ).trim() ) {
			return null;
		}
		const sonde = document.createElement( 'span' );
		sonde.style.color = `var(${ variable })`;
		sonde.style.transition = 'none';
		( el.parentElement || el ).appendChild( sonde );
		const couleur = getComputedStyle( sonde ).color;
		sonde.remove();
		return couleur;
	};
	return controles.map( ( controle ) => {
		const resultat = { selecteur: controle.selecteur, valeurs: {} };
		const el = document.querySelector( controle.selecteur );
		if ( ! el ) {
			resultat.erreur = 'élément introuvable';
			return resultat;
		}
		const style = getComputedStyle( el );
		if ( 0 === el.getClientRects().length || 'hidden' === style.visibility ) {
			resultat.erreur = 'élément invisible';
			return resultat;
		}
		for ( const [ propriete, attendu ] of Object.entries( controle.attendu ) ) {
			let valeurAttendue = attendu;
			if ( 'string' === typeof attendu && attendu.startsWith( 'preset:' ) ) {
				valeurAttendue = resoudrePreset( el, attendu.slice( 7 ) );
				if ( null === valeurAttendue ) {
					resultat.erreur = `couleur de palette inconnue : ${ attendu }`;
				}
			}
			resultat.valeurs[ propriete ] = { obtenu: style.getPropertyValue( propriete ), attendu: valeurAttendue, reference: attendu };
		}
		return resultat;
	} );
}

/**
 * Source d'axe-core (tools/ci/node_modules), injectée dans chaque page contrôlée.
 *
 * @return {string} Script axe.min.js.
 */
function sourceAxe() {
	try {
		return fs.readFileSync( require.resolve( 'axe-core/axe.min.js' ), 'utf8' );
	} catch ( e ) {
		usage( 'module axe-core introuvable : npm ci --prefix tools/ci (ou --sans-axe)' );
	}
}

/**
 * Exécute axe dans la page et renvoie les violations bloquantes non exemptées.
 *
 * @param {Object} onglet  Page Playwright.
 * @param {string} nomPage Nom de la page (rendu-attendus.js).
 * @param {string} axe     Source d'axe-core.
 * @return {Promise<Array>} Violations : { regle, impact, aide, cibles }.
 */
async function auditerAccessibilite( onglet, nomPage, axe ) {
	await onglet.addScriptTag( { content: axe } );
	const resultat = await onglet.evaluate(
		( tags ) => window.axe.run( document, { runOnly: { type: 'tag', values: tags }, resultTypes: [ 'violations' ] } ),
		AXE_TAGS
	);
	const violations = [];
	for ( const v of resultat.violations ) {
		if ( ! AXE_IMPACTS_BLOQUANTS.includes( v.impact ) ) {
			continue;
		}
		const cibles = v.nodes.map( ( n ) => n.target.join( ' ' ) ).filter( ( cible ) => ! AXE_EXCEPTIONS.some(
			( ex ) => ex.regle === v.id && ( ! ex.page || ex.page === nomPage ) && ( ! ex.cible || cible.includes( ex.cible ) )
		) );
		if ( cibles.length ) {
			violations.push( { regle: v.id, impact: v.impact, aide: v.help, cibles } );
		}
	}
	return violations;
}

async function controler( options ) {
	let playwright;
	try {
		playwright = require( 'playwright' );
	} catch ( e ) {
		usage( 'module playwright introuvable : npm install --prefix tools/ci, puis npx playwright install chromium dans tools/ci' );
	}
	const table = require( options.attendus );
	const axe = options.axe ? sourceAxe() : '';
	const base = options.base.replace( /\/+$/, '' );
	const navigateur = await playwright.chromium.launch();
	const contexte = await navigateur.newContext( { viewport: table.fenetre, colorScheme: table.schema, locale: 'fr-FR' } );
	const ecarts = [];
	const mesures = { libelle: options.libelle || base, pages: {}, accessibilite: {} };

	for ( const page of table.pages ) {
		const onglet = await contexte.newPage();
		const erreursJs = [];
		onglet.on( 'pageerror', ( e ) => erreursJs.push( e.message ) );
		const url = base + page.chemin;
		mesures.pages[ page.nom ] = {};
		try {
			const reponse = await onglet.goto( url, { waitUntil: 'load' } );
			if ( ! reponse || 200 !== reponse.status() ) {
				ecarts.push( `${ page.nom } : ${ url } répond ${ reponse ? reponse.status() : 'rien' } (attendu 200)` );
				continue;
			}
			await onglet.evaluate( () => document.fonts.ready );
			if ( 'parametres' === page.avant ) {
				await onglet.click( '[data-yn-action="parametres"]' );
				await onglet.waitForSelector( 'dialog.yn-reader-panel[open]', { state: 'visible', timeout: 5000 } );
				// Laisse finir l'animation d'ouverture du panneau.
				await onglet.waitForTimeout( 400 );
			}
			const resultats = await onglet.evaluate( mesurerDansLaPage, page.controles );
			for ( const r of resultats ) {
				mesures.pages[ page.nom ][ r.selecteur ] = {};
				if ( r.erreur ) {
					ecarts.push( `${ page.nom } : ${ r.selecteur } : ${ r.erreur }` );
					mesures.pages[ page.nom ][ r.selecteur ].erreur = r.erreur;
				}
				for ( const [ propriete, v ] of Object.entries( r.valeurs ) ) {
					mesures.pages[ page.nom ][ r.selecteur ][ propriete ] = v.obtenu;
					if ( null !== v.attendu && v.obtenu !== v.attendu ) {
						const detail = v.reference !== v.attendu ? ` (${ v.reference })` : '';
						ecarts.push( `${ page.nom } : ${ r.selecteur } { ${ propriete } } = ${ v.obtenu }, attendu ${ v.attendu }${ detail }` );
					}
				}
			}
			if ( axe ) {
				const violations = await auditerAccessibilite( onglet, page.nom, axe );
				mesures.accessibilite[ page.nom ] = violations;
				for ( const v of violations ) {
					ecarts.push( `${ page.nom } : accessibilité (axe) ${ v.impact } « ${ v.regle } » : ${ v.aide } — ${ v.cibles.slice( 0, 5 ).join( ', ' ) }${ v.cibles.length > 5 ? ` (+${ v.cibles.length - 5 })` : '' }` );
				}
			}
		} catch ( e ) {
			ecarts.push( `${ page.nom } : ${ url } : ${ e.message.split( '\n' )[ 0 ] }` );
		} finally {
			for ( const message of erreursJs ) {
				console.log( `[rendu] attention : erreur JavaScript sur ${ page.nom } : ${ message }` );
			}
			await onglet.close();
		}
	}
	await navigateur.close();

	for ( const [ nom, controles ] of Object.entries( mesures.pages ) ) {
		console.log( `[rendu] ${ nom }` );
		for ( const [ selecteur, valeurs ] of Object.entries( controles ) ) {
			const texte = Object.entries( valeurs ).map( ( [ p, v ] ) => `${ p }: ${ v }` ).join( '; ' );
			console.log( `         ${ selecteur } { ${ texte } }` );
		}
	}
	if ( axe ) {
		const total = Object.values( mesures.accessibilite ).reduce( ( n, l ) => n + l.length, 0 );
		console.log( `[rendu] axe-core (${ AXE_TAGS.join( ', ' ) }) : ${ total } violation(s) sérieuse(s) ou critique(s)${ AXE_EXCEPTIONS.length ? `, ${ AXE_EXCEPTIONS.length } exception(s) déclarée(s)` : '' }.` );
	}
	if ( options.sortie ) {
		fs.writeFileSync( options.sortie, JSON.stringify( mesures, null, '\t' ) + '\n' );
	}
	return ecarts;
}

function comparer( [ fichierA, fichierB ] ) {
	const a = JSON.parse( fs.readFileSync( fichierA, 'utf8' ) );
	const b = JSON.parse( fs.readFileSync( fichierB, 'utf8' ) );
	const ecarts = [];
	const pages = new Set( [ ...Object.keys( a.pages ), ...Object.keys( b.pages ) ] );
	for ( const page of pages ) {
		const ca = a.pages[ page ] || {};
		const cb = b.pages[ page ] || {};
		for ( const selecteur of new Set( [ ...Object.keys( ca ), ...Object.keys( cb ) ] ) ) {
			const va = ca[ selecteur ] || {};
			const vb = cb[ selecteur ] || {};
			for ( const propriete of new Set( [ ...Object.keys( va ), ...Object.keys( vb ) ] ) ) {
				if ( va[ propriete ] !== vb[ propriete ] ) {
					ecarts.push( `${ page } : ${ selecteur } { ${ propriete } } : ${ a.libelle } = ${ va[ propriete ] ?? '(absent)' }, ${ b.libelle } = ${ vb[ propriete ] ?? '(absent)' }` );
				}
			}
		}
	}
	if ( ! ecarts.length ) {
		console.log( `[rendu] mêmes styles calculés sur ${ a.libelle } et ${ b.libelle }.` );
	}
	return ecarts;
}

( async () => {
	const options = lireArguments( process.argv.slice( 2 ) );
	let ecarts;
	if ( options.comparer ) {
		ecarts = comparer( options.comparer );
	} else {
		if ( ! options.base ) {
			usage( '--base manquant (ou YUME_RENDU_BASE)' );
		}
		ecarts = await controler( options );
	}
	if ( ecarts.length ) {
		console.error( `\n[rendu] ÉCHEC : ${ ecarts.length } écart(s) de rendu${ options.libelle ? ` (${ options.libelle })` : '' } :` );
		for ( const ecart of ecarts ) {
			console.error( `  - ${ ecart }` );
			if ( process.env.GITHUB_ACTIONS ) {
				console.log( `::error title=Rendu::${ ecart }` );
			}
		}
		console.error( '\nStyle écarté : un style de bloc est probablement écrasé par les styles globaux de WordPress, préfixer le sélecteur par la classe du bloc (voir tools/ci/rendu-attendus.js).' );
		console.error( 'Accessibilité (axe) : corriger le balisage ; seule une violation venant du cœur de WordPress peut être ajoutée, justifiée, à AXE_EXCEPTIONS (tools/ci/rendu.js).' );
		process.exit( 1 );
	}
	if ( ! options.comparer ) {
		console.log( `[rendu] styles calculés conformes à tools/ci/rendu-attendus.js${ options.axe ? ', aucune violation d’accessibilité bloquante' : '' }.` );
	}
} )().catch( ( e ) => {
	console.error( `[rendu] erreur : ${ e.stack || e }` );
	process.exit( 2 );
} );
