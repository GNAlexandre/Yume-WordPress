#!/usr/bin/env node
/**
 * Captures des maquettes du guide de l'équipe.
 *
 *   node tools/guide/captures.js [--seulement Main,Chapitre]
 *
 * Rend chaque maquette de docs/guide-equipe/maquettes (*.dc.html dans l'ordre de canvas.json, puis
 * les schémas *.html) dans Chromium sans fenêtre, en 1440 px de large, et enregistre dans
 * docs/guide-equipe/images/ :
 *   - <nom>.png : la page entière, à la hauteur notée dans canvas.json (schémas : leur contenu) ;
 *   - <nom>-<zone>.png : les recadrages de captures.json (la zone utile d'un écran, sans le menu,
 *     pour qu'elle reste lisible une fois réduite à la largeur d'une page A4).
 * captures.json peut aussi corriger un défaut d'affichage (css) ou aligner un libellé de la
 * maquette sur celui du site (remplacer, retirer) : le code fait foi.
 *
 * Chromium : CHROMIUM (chemin de l'exécutable) s'il est défini ; sinon celui de Playwright s'il est
 * installé ; sinon le plus récent de PLAYWRIGHT_BROWSERS_PATH (par défaut /opt/pw-browsers), quelle
 * que soit la version attendue par Playwright. Ne lance jamais « playwright install ».
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const RACINE = path.resolve( __dirname, '..', '..' );
const MAQUETTES = path.join( RACINE, 'docs', 'guide-equipe', 'maquettes' );
const IMAGES = path.join( RACINE, 'docs', 'guide-equipe', 'images' );
const LARGEUR = 1440;
const MARGE = 16;

/** Exécutable Chromium à utiliser (undefined : celui de Playwright). */
function executableChromium() {
	if ( process.env.CHROMIUM ) {
		return process.env.CHROMIUM;
	}
	try {
		if ( fs.existsSync( chromium.executablePath() ) ) {
			return undefined;
		}
	} catch ( e ) {
		// Playwright ne connaît pas de navigateur installé : on cherche plus loin.
	}
	const dossier = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
	const candidats = fs.existsSync( dossier )
		? fs.readdirSync( dossier )
			.filter( ( d ) => /^chromium-\d+$/.test( d ) )
			.sort( ( a, b ) => Number( b.split( '-' )[ 1 ] ) - Number( a.split( '-' )[ 1 ] ) )
			.map( ( d ) => path.join( dossier, d, 'chrome-linux', 'chrome' ) )
			.filter( ( f ) => fs.existsSync( f ) )
		: [];
	if ( ! candidats.length ) {
		throw new Error( 'Chromium introuvable : installez-le pour Playwright ou indiquez son chemin dans CHROMIUM.' );
	}
	return candidats[ 0 ];
}

/** Maquettes à rendre : [nom, fichier, hauteur ou null]. */
function maquettes() {
	const canvas = JSON.parse( fs.readFileSync( path.join( MAQUETTES, 'canvas.json' ), 'utf8' ) );
	const liste = canvas.order.map( ( f ) => [ f.replace( /\.dc\.html$/, '' ), f, ( canvas.boards[ f ] || {} ).h || null ] );
	fs.readdirSync( MAQUETTES )
		.filter( ( f ) => f.endsWith( '.html' ) && ! f.endsWith( '.dc.html' ) )
		.sort()
		.forEach( ( f ) => liste.push( [ f.replace( /\.html$/, '' ), f, null ] ) );
	return liste;
}

/**
 * Dans la page : applique remplacer / retirer, puis renvoie les rectangles des zones et la hauteur
 * du contenu. Exécuté par page.evaluate (pas d'accès aux variables du script).
 */
function preparerPage( reglages ) {
	const norm = ( t ) => ( t || '' ).replace( /[  ]/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	const plusPetits = ( css, texte, exact ) => {
		const tous = Array.from( document.querySelectorAll( css ) ).filter( ( e ) => {
			const t = norm( e.textContent );
			return exact ? t === texte : t.includes( texte );
		} );
		return tous.filter( ( e ) => ! tous.some( ( autre ) => autre !== e && e.contains( autre ) ) );
	};
	const manquants = [];
	( reglages.remplacer || [] ).forEach( ( r ) => {
		const cibles = plusPetits( 'body *', norm( r.de ), !! r.exact );
		if ( ! cibles.length ) {
			manquants.push( r.de );
		}
		cibles.forEach( ( e ) => {
			if ( r.html !== undefined ) {
				e.innerHTML = r.html;
			} else {
				e.textContent = r.par;
			}
		} );
	} );
	( reglages.retirer || [] ).forEach( ( texte ) => {
		const cibles = plusPetits( 'body *', norm( texte ), false );
		if ( ! cibles.length ) {
			manquants.push( texte );
		}
		cibles.forEach( ( e ) => e.remove() );
	} );

	const rect = ( elements ) => {
		let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
		elements.forEach( ( e ) => {
			const r = e.getBoundingClientRect();
			if ( r.width && r.height ) {
				x1 = Math.min( x1, r.left );
				y1 = Math.min( y1, r.top );
				x2 = Math.max( x2, r.right );
				y2 = Math.max( y2, r.bottom );
			}
		} );
		return x1 === Infinity ? null : { x: x1, y: y1, l: x2 - x1, h: y2 - y1 };
	};
	const zones = {};
	Object.entries( reglages.zones || {} ).forEach( ( [ nom, designations ] ) => {
		const elements = [];
		designations.forEach( ( d ) => {
			const trouves = typeof d === 'string' ? Array.from( document.querySelectorAll( d ) ) : plusPetits( d.css, norm( d.contient ), false );
			if ( ! trouves.length ) {
				manquants.push( nom + ' : ' + JSON.stringify( d ) );
			}
			elements.push( ...trouves );
		} );
		zones[ nom ] = rect( elements );
	} );
	const contenu = rect( Array.from( document.querySelectorAll( 'body *' ) ).filter( ( e ) => e.children.length === 0 ) );
	return { zones, bas: contenu ? Math.ceil( contenu.y + contenu.h ) : 800, manquants };
}

async function main() {
	const i = process.argv.indexOf( '--seulement' );
	const seulement = i > 0 && process.argv[ i + 1 ] ? process.argv[ i + 1 ].split( ',' ) : null;
	const config = JSON.parse( fs.readFileSync( path.join( __dirname, 'captures.json' ), 'utf8' ) );
	fs.mkdirSync( IMAGES, { recursive: true } );

	// Rendu logiciel, sans lissage sous-pixel ni accélération : mêmes pixels d'une exécution à l'autre.
	const navigateur = await chromium.launch( {
		executablePath: executableChromium(),
		args: [ '--disable-gpu', '--disable-lcd-text', '--font-render-hinting=none', '--disable-partial-raster', '--disable-skia-runtime-opts' ],
	} );
	let erreurs = 0;
	try {
		// Mise en route : le tout premier rendu d'un navigateur neuf peut différer d'un pixel
		// (polices de repli, champs de formulaire) ; on le jette pour des captures reproductibles.
		const chauffe = await navigateur.newPage( { viewport: { width: LARGEUR, height: 800 } } );
		await chauffe.route( /^https?:\/\//, ( route ) => route.abort() );
		await chauffe.goto( 'file://' + path.join( MAQUETTES, maquettes()[ 0 ][ 1 ] ) );
		await chauffe.screenshot();
		await chauffe.close();
		for ( const [ nom, fichier, hauteurCanvas ] of maquettes() ) {
			if ( seulement && ! seulement.includes( nom ) ) {
				continue;
			}
			const reglages = config[ nom ] || {};
			const page = await navigateur.newPage( { viewport: { width: LARGEUR, height: reglages.hauteur || hauteurCanvas || 1000 }, deviceScaleFactor: 1 } );
			// Le script ./support.js de l'outil de maquettes n'existe pas ici : sans effet sur le rendu.
			await page.route( '**/support.js', ( route ) => route.fulfill( { status: 200, contentType: 'text/javascript', body: '' } ) );
			// Aucune ressource externe (polices Google…) : même rendu avec ou sans réseau.
			await page.route( /^https?:\/\//, ( route ) => route.abort() );
			await page.goto( 'file://' + path.join( MAQUETTES, fichier ) );
			if ( reglages.css ) {
				await page.addStyleTag( { content: reglages.css } );
			}
			await page.waitForLoadState( 'networkidle' );
			await page.evaluate( () => document.fonts.ready.then( () => new Promise( ( fin ) => requestAnimationFrame( () => requestAnimationFrame( fin ) ) ) ) );
			const { zones, bas, manquants } = await page.evaluate( preparerPage, reglages );
			manquants.forEach( ( m ) => {
				console.error( `${ nom } : introuvable dans la maquette : ${ m }` );
				erreurs++;
			} );

			const hauteur = reglages.hauteur || hauteurCanvas || bas + 40;
			await page.setViewportSize( { width: LARGEUR, height: Math.max( hauteur, bas + 40 ) } );
			const sorties = [ [ nom + '.png', { x: 0, y: 0, width: LARGEUR, height: hauteur } ] ];
			for ( const [ zone, r ] of Object.entries( zones ) ) {
				if ( r ) {
					const x = Math.max( 0, Math.floor( r.x - MARGE ) );
					const y = Math.max( 0, Math.floor( r.y - MARGE ) );
					sorties.push( [ `${ nom }-${ zone }.png`, { x, y, width: Math.min( LARGEUR - x, Math.ceil( r.l + 2 * MARGE ) ), height: Math.ceil( r.h + 2 * MARGE ) } ] );
				}
			}
			for ( const [ sortie, clip ] of sorties ) {
				await page.screenshot( { path: path.join( IMAGES, sortie ), clip } );
				console.log( `docs/guide-equipe/images/${ sortie } (${ clip.width } × ${ clip.height })` );
			}
			await page.close();
		}
	} finally {
		await navigateur.close();
	}
	if ( erreurs ) {
		console.error( `${ erreurs } réglage(s) de captures.json sans effet : la maquette a changé ?` );
		process.exit( 1 );
	}
}

main().catch( ( e ) => {
	console.error( e.message || e );
	process.exit( 1 );
} );
