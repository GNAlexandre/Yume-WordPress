/**
 * Parcours et captures de la préproduction (Playwright + Chromium), comparables aux maquettes
 * de design/maquettes/ : thèmes Nuit et Papier (Sépia en plus pour le lecteur), 1440 et 390 px.
 *
 *   npm i playwright@1.56 @axe-core/playwright     (dans un dossier de travail, hors du dépôt)
 *   NODE_PATH=<ce dossier>/node_modules node tools/preprod/parcours.js [dossier-captures] [filtre]
 *
 * Variables : YUME_PREPROD_URL (défaut http://127.0.0.1:8090), YUME_PREPROD_MDP (défaut preprod),
 * YUME_ADMIN_MDP (défaut admin). Le filtre (facultatif) limite aux captures dont le nom le contient.
 *
 * Pour chaque page : statut HTTP, erreurs de la console et du script, requêtes en échec
 * (4xx/5xx du site), audit axe-core WCAG 2.1 AA (bureau). Rapport : rapport.json et rapport.txt
 * dans le dossier des captures ; code de sortie 1 si un défaut est relevé.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );
let AxeBuilder = null;
try {
	AxeBuilder = require( '@axe-core/playwright' ).default;
} catch ( e ) {
	AxeBuilder = null;
}

const BASE = ( process.env.YUME_PREPROD_URL || 'http://127.0.0.1:8090' ).replace( /\/$/, '' );
const MDP = process.env.YUME_PREPROD_MDP || 'preprod';
const ADMIN = process.env.YUME_ADMIN_MDP || 'admin';
const SORTIE = path.resolve( process.argv[ 2 ] || 'captures-preprod' );
const FILTRE = process.argv[ 3 ] || '';
fs.mkdirSync( SORTIE, { recursive: true } );

const LARGEURS = [ 1440, 390 ];
const THEMES = [ 'nuit', 'papier' ];
const LECTEUR = [ 'nuit', 'papier', 'sepia' ];

/** Anciennes URL de l'ancien site et leur nouvelle adresse (301). */
const ANCIENNES = [
	[ '/grimgar-of-fantasy-and-ash-ln/', '/oeuvres/grimgar-of-fantasy-and-ash/' ],
	[ '/arc-7-tournoi-dechec-silent-witch/', '/oeuvres/secrets-of-the-silent-witch/arc-7/' ],
	[ '/secrets-of-the-silent-witch-t-1-chapitre-1/', '/lire/secrets-of-the-silent-witch/arc-1/1/' ],
	[ '/raven-of-the-inner-palace/', '/oeuvres/raven-of-the-inner-palace/' ],
	[ '/category/yume-news/', '/category/sorties/' ],
	[ '/yume-ln/', '/bibliotheque/?type=light-novel' ],
];

/**
 * Pages du parcours : nom, chemin, compte, thèmes, action avant la capture.
 */
const PAGES = [
	{ nom: '01-accueil', chemin: '/' },
	{ nom: '01b-accueil-lectrice', chemin: '/', compte: 'kaede' },
	{ nom: '02-menu-bibliotheque-ouvert', chemin: '/', action: 'menu', plein: false },
	{ nom: '03-bibliotheque-filtree', chemin: '/bibliotheque/?type=light-novel&statut=en-cours,en-pause' },
	{ nom: '04-fiche-grimgar-lectrice', chemin: '/oeuvres/grimgar-of-fantasy-and-ash/', compte: 'kaede' },
	{ nom: '04b-fiche-grimgar-visiteur', chemin: '/oeuvres/grimgar-of-fantasy-and-ash/', largeurs: [ 1440 ] },
	{ nom: '05-tome-7-grimgar', chemin: '/oeuvres/grimgar-of-fantasy-and-ash/tome-7/' },
	{ nom: '06-chapitre-1-grimgar', chemin: '/lire/grimgar-of-fantasy-and-ash/tome-7/1/', themes: LECTEUR },
	{ nom: '06b-chapitre-1-parametres', chemin: '/lire/grimgar-of-fantasy-and-ash/tome-7/1/', themes: LECTEUR, action: 'parametres', plein: false },
	{ nom: '07-chapitre-silent-witch-migre', chemin: '/lire/secrets-of-the-silent-witch/arc-7/1/', themes: LECTEUR },
	{ nom: '08-planning', chemin: '/planning/' },
	{ nom: '09-equipe-traducteur', chemin: '/equipe/', compte: 'calumi' },
	{ nom: '09b-equipe-gerant', chemin: '/equipe/', compte: 'angeloids' },
	{ nom: '10-publier-un-tome', chemin: '/equipe/publier/', compte: 'jojogg' },
	{ nom: '11-compte-lecteur', chemin: '/compte/', compte: 'kaede' },
	{ nom: '12-connexion-inscription', chemin: '/connexion/' },
	{ nom: '13-actualites', chemin: '/actualites/' },
	{ nom: '14-article-migre', chemin: '/2026/09/20/tome-9-de-grimgar-of-fantasy-and-ash-disponible/' },
	{ nom: '15-recherche', chemin: '/?s=grimgar' },
	{ nom: '16-erreur-404', chemin: '/page-qui-n-existe-pas/', statut: 404 },
	{ nom: '17-yume-migrer-etat-migre', chemin: '/wp-admin/admin.php?page=yume-migrer', compte: 'admin', themes: [ 'nuit' ], axe: false },
	{ nom: '18-ancienne-url-301', chemin: '/grimgar-of-fantasy-and-ash-ln/', themes: [ 'nuit' ], largeurs: [ 1440 ] },
];

const rapport = { base: BASE, date: new Date().toISOString(), pages: [], redirections: [], liens: 0, defauts: [] };

/** Liens internes relevés sur les pages (adresse → première page où il apparaît). */
const LIENS = new Map();

/** Avatar neutre servi à la place de Gravatar (injoignable en local). */
const AVATAR = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#3a2a63"/></svg>';

/**
 * Connexion par wp-login.php.
 *
 * @param {import('playwright').Page} page  Page.
 * @param {string}                    login Identifiant.
 */
async function connecter( page, login ) {
	await page.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', 'admin' === login ? ADMIN : MDP );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), page.click( '#wp-submit' ) ] );
}

/**
 * Action avant la capture.
 *
 * @param {import('playwright').Page} page    Page.
 * @param {string}                    action  Action.
 * @param {number}                    largeur Largeur.
 */
async function agir( page, action, largeur ) {
	if ( 'menu' === action ) {
		if ( largeur < 1360 ) {
			await page.click( '.wp-block-navigation__responsive-container-open' );
			await page.waitForTimeout( 300 );
		}
		const bouton = page.locator( '.yn-library-menu > summary:visible' ).first();
		await bouton.click();
		await page.waitForTimeout( 300 );
	} else if ( 'parametres' === action ) {
		await page.click( '[data-yn-action="parametres"]' );
		await page.waitForTimeout( 500 );
	}
}

/**
 * Capture une page dans un thème et une largeur.
 *
 * @param {import('playwright').Browser} navigateur Navigateur.
 * @param {object}                       def        Définition de la page.
 * @param {string}                       theme      Thème.
 * @param {number}                       largeur    Largeur.
 */
async function capturer( navigateur, def, theme, largeur ) {
	const fichier = `${ def.nom }-${ theme }-${ largeur }.png`;
	if ( FILTRE && ! fichier.includes( FILTRE ) ) {
		return;
	}
	const contexte = await navigateur.newContext( {
		viewport: { width: largeur, height: largeur > 800 ? 900 : 844 },
		deviceScaleFactor: 1,
		locale: 'fr-FR',
		timezoneId: 'Europe/Paris',
	} );
	await contexte.route( /gravatar\.com/, ( route ) => route.fulfill( { status: 200, contentType: 'image/svg+xml', body: AVATAR } ) );
	await contexte.addInitScript( ( t ) => {
		try {
			if ( ! sessionStorage.getItem( 'yn-init' ) ) {
				localStorage.setItem( 'yn.theme', t );
				sessionStorage.setItem( 'yn-init', '1' );
			}
		} catch ( e ) {}
	}, theme );
	if ( def.compte ) {
		// Connexion dans un onglet à part : les messages de wp-login.php et de la page
		// d'arrivée (wp-admin) ne sont pas ceux de la page capturée.
		const connexion = await contexte.newPage();
		await connecter( connexion, def.compte );
		await connexion.close();
	}
	const page = await contexte.newPage();
	const entree = { capture: fichier, chemin: def.chemin, compte: def.compte || '', console: [], requetes: [], externes: [], axe: [] };
	page.on( 'console', ( m ) => {
		const texte = m.text();
		// Ressources externes (réseau bloqué en local) et document 404 attendu : relevés à part.
		if ( /ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|ERR_CONNECTION_REFUSED/.test( texte ) ) {
			return;
		}
		if ( def.statut && /status of 404/.test( texte ) && m.location().url === BASE + def.chemin ) {
			return;
		}
		if ( [ 'error', 'warning' ].includes( m.type() ) ) {
			entree.console.push( `[${ m.type() }] ${ texte }` );
		}
	} );
	page.on( 'requestfailed', ( r ) => {
		if ( ! r.url().startsWith( BASE ) ) {
			entree.externes.push( new URL( r.url() ).host );
		} else {
			entree.requetes.push( `échec ${ r.url().slice( BASE.length ) } (${ r.failure() ? r.failure().errorText : '' })` );
		}
	} );
	page.on( 'pageerror', ( e ) => entree.console.push( `[pageerror] ${ e.message }` ) );
	page.on( 'response', ( r ) => {
		const url = r.url();
		if ( url.startsWith( BASE ) && r.status() >= 400 && url !== BASE + def.chemin ) {
			entree.requetes.push( `${ r.status() } ${ url.slice( BASE.length ) }` );
		}
	} );
	try {
		const reponse = await page.goto( BASE + def.chemin, { waitUntil: 'networkidle' } );
		entree.statut = reponse ? reponse.status() : 0;
		entree.url = page.url().slice( BASE.length );
		await page.evaluate( () => document.fonts.ready );
		if ( 1440 === largeur && ! def.action ) {
			for ( const href of await page.$$eval( 'a[href]', ( as ) => as.map( ( a ) => a.href ) ) ) {
				const url = href.split( '#' )[ 0 ];
				if ( url.startsWith( BASE ) && url !== BASE + def.chemin && ! /\/wp-admin\/|wp-login\.php|action=logout|replytocom=|_wpnonce=/.test( url ) && ! LIENS.has( url ) ) {
					LIENS.set( url, fichier );
				}
			}
		}
		if ( def.action ) {
			await agir( page, def.action, largeur );
		}
		// Aucun débordement horizontal (page plus large que la fenêtre).
		const deborde = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
		if ( deborde > 1 ) {
			entree.console.push( `[mise en page] débordement horizontal de ${ deborde } px` );
		}
		// Aucune animation au moment de la capture.
		await page.addStyleTag( { content: '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' } );
		await page.screenshot( { path: path.join( SORTIE, fichier ), fullPage: false !== def.plein } );
		if ( AxeBuilder && false !== def.axe && 1440 === largeur && ! def.action ) {
			const resultat = await new AxeBuilder( { page } ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] ).analyze();
			entree.axe = resultat.violations.map( ( v ) => `${ v.id } (${ v.nodes.length }) : ${ v.nodes.slice( 0, 3 ).map( ( n ) => n.target.join( ' ' ) ).join( ' | ' ) }` );
		}
	} catch ( e ) {
		entree.console.push( `[parcours] ${ e.message.split( '\n' )[ 0 ] }` );
	}
	await contexte.close();
	entree.externes = [ ...new Set( entree.externes ) ];
	const attendu = def.statut || 200;
	if ( entree.statut !== attendu ) {
		rapport.defauts.push( `${ fichier } : HTTP ${ entree.statut } (attendu ${ attendu })` );
	}
	for ( const ligne of [ ...entree.console, ...entree.requetes, ...entree.axe.map( ( a ) => 'axe ' + a ) ] ) {
		rapport.defauts.push( `${ fichier } : ${ ligne }` );
	}
	rapport.pages.push( entree );
	process.stdout.write( `${ entree.statut } ${ fichier }${ entree.console.length + entree.requetes.length + entree.axe.length ? ' ⚠' : '' }\n` );
}

( async () => {
	const navigateur = await chromium.launch( { args: [ '--lang=fr-FR' ] } );
	for ( const def of PAGES ) {
		for ( const theme of def.themes || THEMES ) {
			for ( const largeur of def.largeurs || LARGEURS ) {
				await capturer( navigateur, def, theme, largeur );
			}
		}
	}
	// Anciennes URL : 301 vers la nouvelle adresse (sans suivre la redirection).
	if ( ! FILTRE || '301'.includes( FILTRE ) || FILTRE.includes( '301' ) ) {
		const contexte = await navigateur.newContext();
		for ( const [ ancienne, nouvelle ] of ANCIENNES ) {
			const r = await contexte.request.get( BASE + ancienne, { maxRedirects: 0 } );
			const cible = ( r.headers().location || '' ).replace( BASE, '' );
			const ok = 301 === r.status() && cible === nouvelle;
			rapport.redirections.push( `${ r.status() } ${ ancienne } → ${ cible }${ ok ? '' : ' (attendu 301 ' + nouvelle + ')' }` );
			if ( ! ok ) {
				rapport.defauts.push( `redirection ${ ancienne } : ${ r.status() } ${ cible }` );
			}
		}
		await contexte.close();
	}
	// Capture du thème (screenshot.png, 1200 × 900) : accueil 1440 × 1080 réduit, thème Nuit.
	if ( process.env.YUME_CAPTURE_THEME ) {
		const contexte = await navigateur.newContext( { viewport: { width: 1440, height: 1080 }, locale: 'fr-FR' } );
		await contexte.route( /gravatar\.com/, ( route ) => route.fulfill( { status: 200, contentType: 'image/svg+xml', body: AVATAR } ) );
		const page = await contexte.newPage();
		await page.goto( BASE + '/', { waitUntil: 'networkidle' } );
		await page.evaluate( () => document.fonts.ready );
		const brut = await page.screenshot( { type: 'png' } );
		const reduction = await contexte.newPage();
		await reduction.setViewportSize( { width: 1200, height: 900 } );
		await reduction.setContent( `<body style="margin:0"><img src="data:image/png;base64,${ brut.toString( 'base64' ) }" style="width:1200px;height:900px;display:block"></body>` );
		await reduction.screenshot( { path: process.env.YUME_CAPTURE_THEME, type: 'png' } );
		await contexte.close();
		process.stdout.write( `capture du thème : ${ process.env.YUME_CAPTURE_THEME }\n` );
	}
	// Liens internes : aucune adresse en erreur (redirections suivies).
	if ( ! FILTRE ) {
		const contexte = await navigateur.newContext();
		for ( const [ url, source ] of LIENS ) {
			const r = await contexte.request.get( url, { maxRedirects: 5, failOnStatusCode: false } );
			++rapport.liens;
			if ( r.status() >= 400 ) {
				rapport.defauts.push( `lien cassé ${ url.slice( BASE.length ) } (${ r.status() }) sur ${ source }` );
			}
		}
		await contexte.close();
	}
	await navigateur.close();
	const externes = [ ...new Set( rapport.pages.flatMap( ( p ) => p.externes ) ) ].sort();
	fs.writeFileSync( path.join( SORTIE, 'rapport.json' ), JSON.stringify( rapport, null, 2 ) );
	const texte = [
		`Parcours de la préproduction ${ BASE } — ${ rapport.date }`,
		`${ rapport.pages.length } captures, ${ rapport.liens } liens internes vérifiés, ${ rapport.defauts.length } défaut(s).`,
		'',
		`Hôtes externes injoignables en local (non comptés comme défauts) : ${ externes.join( ', ' ) || 'aucun' }`,
		'',
		'Anciennes URL :',
		...rapport.redirections.map( ( l ) => '  ' + l ),
		'',
		'Défauts :',
		...( rapport.defauts.length ? rapport.defauts.map( ( l ) => '  ' + l ) : [ '  aucun' ] ),
	].join( '\n' );
	fs.writeFileSync( path.join( SORTIE, 'rapport.txt' ), texte + '\n' );
	process.stdout.write( '\n' + texte + '\n' );
	process.exit( rapport.defauts.length ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( String( e && e.stack ? e.stack : e ) + '\n' );
	process.exit( 2 );
} );
