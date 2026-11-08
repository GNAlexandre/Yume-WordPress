#!/usr/bin/env node
/**
 * Test de bout en bout du lecteur manga (lot A 2.8.0 : doubles pages et zoom, contrat §8 bis) :
 * ouvre dans Chromium (Playwright) le chapitre MangaDex fabriqué par tools/ci/manga-zoom.php et
 * remplace les images du proxy (?yume_mdx_image=…) par deux PNG dessinés dans le navigateur :
 * portrait 700 × 1000 (pages 1 et 3) et paysage 2000 × 1400 (page 2). Aucune requête vers
 * MangaDex.
 *
 * Vérifie : double page plus large que la colonne et centrée sur la fenêtre (défilement) ; « + »
 * multiplie la largeur rendue par 1,25 ; touches − 0 + ; « Auto » revient ; réglage gardé après
 * un rechargement (localStorage yn.manga) ; en mode page, la double page tient entière à l'écran ;
 * double clic Auto ↔ 200 % sans tourner la page, simple clic qui tourne la page ; jamais de
 * défilement horizontal du document (bureau 1440 × 900 et mobile 390 × 844) ; aucune erreur de
 * console.
 *
 *   node tools/ci/manga-zoom.js --url http://127.0.0.1:8101/lire/essai-manga-zoom/tome-1/1/
 *
 * tools/ci/manga-zoom.sh fait tout (données, serveur php -S, ce script). Code de sortie : 0
 * conforme, 1 échec, 2 erreur d'utilisation ou technique.
 */
'use strict';

let playwright;
try {
	playwright = require( 'playwright' );
} catch ( e ) {
	console.error( '[manga-zoom] Playwright introuvable (npm ci --prefix tools/ci, ou NODE_PATH).' );
	process.exit( 2 );
}

function lireArguments( argv ) {
	const options = { url: process.env.YUME_MANGA_URL || '' };
	for ( let i = 0; i < argv.length; i++ ) {
		if ( argv[ i ] === '--url' && i + 1 < argv.length ) {
			options.url = argv[ ++i ];
		} else {
			console.error( 'Usage : node tools/ci/manga-zoom.js --url ADRESSE_DU_CHAPITRE' );
			process.exit( 2 );
		}
	}
	if ( ! options.url ) {
		console.error( 'Usage : node tools/ci/manga-zoom.js --url ADRESSE_DU_CHAPITRE' );
		process.exit( 2 );
	}
	return options;
}

let echecs = 0;
let reussis = 0;

function verifier( condition, libelle, detail ) {
	if ( condition ) {
		reussis++;
		console.log( `  ✓ ${ libelle }` );
	} else {
		echecs++;
		console.log( `  ✗ ${ libelle }${ detail ? ` (${ detail })` : '' }` );
	}
}

function proche( a, b, tolerance ) {
	return Math.abs( a - b ) <= ( tolerance || 1.5 );
}

/**
 * Dessine un PNG (dégradé, cadre, numéro) dans le navigateur.
 *
 * @param {Object} page    Page Playwright.
 * @param {number} largeur Largeur.
 * @param {number} hauteur Hauteur.
 * @return {Promise<Buffer>} PNG.
 */
async function dessinerPng( page, largeur, hauteur ) {
	const donnees = await page.evaluate( ( [ l, h ] ) => {
		const canvas = document.createElement( 'canvas' );
		canvas.width = l;
		canvas.height = h;
		const ctx = canvas.getContext( '2d' );
		const degrade = ctx.createLinearGradient( 0, 0, l, h );
		degrade.addColorStop( 0, '#f6b9d3' );
		degrade.addColorStop( 1, '#3b2a4f' );
		ctx.fillStyle = degrade;
		ctx.fillRect( 0, 0, l, h );
		ctx.strokeStyle = '#ffffff';
		ctx.lineWidth = 12;
		ctx.strokeRect( 6, 6, l - 12, h - 12 );
		ctx.fillStyle = '#ffffff';
		ctx.font = 'bold 120px sans-serif';
		ctx.fillText( l + ' × ' + h, 60, 200 );
		return canvas.toDataURL( 'image/png' ).split( ',' )[ 1 ];
	}, [ largeur, hauteur ] );
	return Buffer.from( donnees, 'base64' );
}

/** Mesures du lecteur dans la page. */
function mesures() {
	const doc = document.documentElement;
	const zone = document.querySelector( '.yn-manga__pages' ).getBoundingClientRect();
	const figure = ( n ) => document.querySelector( `.yn-manga__page[data-yn-page="${ n }"]` );
	const rect = ( el ) => {
		const r = el.getBoundingClientRect();
		return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height };
	};
	const info = ( n ) => {
		const f = figure( n );
		const img = f.querySelector( 'img' );
		return {
			figure: rect( f ),
			img: rect( img ),
			chargee: img.classList.contains( 'is-chargee' ),
			paysage: f.classList.contains( 'is-paysage' ) && img.classList.contains( 'is-paysage' ),
			defile: f.scrollWidth > f.clientWidth,
			visible: getComputedStyle( f ).display !== 'none',
		};
	};
	let stocke = null;
	try {
		stocke = JSON.parse( localStorage.getItem( 'yn.manga' ) || 'null' );
	} catch ( e ) {}
	return {
		fenetre: doc.clientWidth,
		hauteur: window.innerHeight,
		debordement: doc.scrollWidth - doc.clientWidth,
		colonne: { left: zone.left, width: zone.width },
		valeur: document.querySelector( '[data-yn-manga-zoom-valeur]' ).textContent.trim(),
		auto: document.querySelector( '[data-yn-manga-zoom="auto"]' ).getAttribute( 'aria-pressed' ),
		attribut: document.querySelector( '.yn-manga' ).getAttribute( 'data-yn-zoom' ),
		courante: document.querySelector( '.yn-manga' ).getAttribute( 'data-yn-courante' ),
		stocke: stocke,
		p1: info( 1 ),
		p2: info( 2 ),
		p3: info( 3 ),
	};
}

async function attendreImages( page ) {
	await page.waitForFunction( () => Array.prototype.every.call( document.querySelectorAll( '.yn-manga__page img' ), ( img ) => img.classList.contains( 'is-chargee' ) ), null, { timeout: 15000 } );
	await page.waitForTimeout( 100 );
}

async function lecteur( contexte, url, png ) {
	const page = await contexte.newPage();
	const erreurs = [];
	page.on( 'pageerror', ( e ) => erreurs.push( e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			erreurs.push( m.text() );
		}
	} );
	await page.route( /[?&]yume_mdx_image=/, ( route ) => {
		const chemin = decodeURIComponent( new URL( route.request().url() ).searchParams.get( 'yume_mdx_image' ) || '' );
		const numero = parseInt( chemin.split( '/' ).pop(), 10 );
		route.fulfill( { status: 200, contentType: 'image/png', body: numero === 2 ? png.paysage : png.portrait } );
	} );
	await page.goto( url, { waitUntil: 'load' } );
	await page.waitForSelector( '.yn-manga__outils:not([hidden])' );
	// Toutes les pages chargées (le chargement différé n'est pas l'objet de ce test).
	await page.evaluate( () => document.querySelectorAll( '.yn-manga__page img[loading="lazy"]' ).forEach( ( img ) => img.setAttribute( 'loading', 'eager' ) ) );
	await attendreImages( page );
	return { page, erreurs };
}

async function principal() {
	const options = lireArguments( process.argv.slice( 2 ) );
	const navigateur = await playwright.chromium.launch();
	let statut = 0;
	try {
		const brouillon = await navigateur.newPage();
		const png = { portrait: await dessinerPng( brouillon, 700, 1000 ), paysage: await dessinerPng( brouillon, 2000, 1400 ) };
		await brouillon.close();

		// ------------------------------------------------------------- Bureau, défilement.
		console.log( '[manga-zoom] bureau 1440 × 900, défilement' );
		const contexte = await navigateur.newContext( { viewport: { width: 1440, height: 900 } } );
		let { page, erreurs } = await lecteur( contexte, options.url, png );
		let m = await page.evaluate( mesures );
		verifier( m.valeur === 'Auto' && m.auto === 'true' && m.attribut === 'auto', 'zoom Auto par défaut (valeur, bouton enfoncé, data-yn-zoom)', `${ m.valeur } / ${ m.auto } / ${ m.attribut }` );
		verifier( m.p2.paysage && ! m.p1.paysage, 'is-paysage posé sur la figure et l’image de la page paysage seulement' );
		verifier( m.p2.figure.width > m.colonne.width + 100, 'double page plus large que la colonne', `figure ${ m.p2.figure.width } px, colonne ${ m.colonne.width } px` );
		verifier( proche( m.p2.figure.left + m.p2.figure.width / 2, m.fenetre / 2 ), 'double page centrée sur la fenêtre', `centre ${ m.p2.figure.left + m.p2.figure.width / 2 }, fenêtre ${ m.fenetre / 2 }` );
		verifier( m.p2.figure.left >= 15 && m.p2.figure.right <= m.fenetre - 15, 'double page dans la fenêtre (marges de 16 px)', `${ m.p2.figure.left } → ${ m.p2.figure.right }` );
		verifier( proche( m.p2.img.width, Math.min( 2000, m.p2.figure.width ) ), 'image paysage à la largeur de son cadre', `${ m.p2.img.width }` );
		verifier( proche( m.p1.img.width, Math.min( 700, m.colonne.width ) ), 'page portrait inchangée (largeur naturelle dans la colonne)', `${ m.p1.img.width }` );
		verifier( m.debordement <= 0, 'aucun défilement horizontal du document (Auto)', `${ m.debordement } px` );
		const auto = m;
		if ( process.env.YUME_MANGA_CAPTURES ) {
			await page.locator( '.yn-manga__page[data-yn-page="2"]' ).scrollIntoViewIfNeeded();
			await page.screenshot( { path: `${ process.env.YUME_MANGA_CAPTURES }/bureau-defilement.png` } );
		}

		await page.click( '[data-yn-manga-zoom="plus"]' );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '125 %' && m.auto === 'false' && m.attribut === 'manuel', '« + » : 125 %, Auto relâché, data-yn-zoom="manuel"', m.valeur );
		verifier( proche( m.p2.img.width, auto.p2.img.width * 1.25 ), '« + » : largeur rendue de la double page × 1,25', `${ auto.p2.img.width } → ${ m.p2.img.width }` );
		verifier( proche( m.p1.img.width, auto.p1.img.width * 1.25 ), '« + » : largeur rendue de la page portrait × 1,25', `${ auto.p1.img.width } → ${ m.p1.img.width }` );
		verifier( m.p2.defile, 'double page zoomée : défilement horizontal dans sa figure' );
		verifier( m.debordement <= 0, 'aucun défilement horizontal du document (125 %)', `${ m.debordement } px` );
		verifier( m.stocke && m.stocke.zoom === 125, 'zoom gardé dans localStorage yn.manga', JSON.stringify( m.stocke ) );

		await page.keyboard.press( '-' );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '100 %' && proche( m.p2.img.width, auto.p2.img.width ), 'touche − : 100 %', m.valeur );
		await page.keyboard.press( '0' );
		m = await page.evaluate( mesures );
		verifier( m.valeur === 'Auto' && m.auto === 'true', 'touche 0 : Auto', m.valeur );
		await page.keyboard.press( '+' );
		await page.keyboard.press( '=' );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '150 %' && proche( m.p1.img.width, auto.p1.img.width * 1.5 ), 'touches + et = : 150 %', m.valeur );

		await page.click( '[data-yn-manga-zoom="auto"]' );
		m = await page.evaluate( mesures );
		verifier( m.valeur === 'Auto' && proche( m.p2.img.width, auto.p2.img.width ) && proche( m.p1.img.width, auto.p1.img.width ), '« Auto » : retour aux largeurs automatiques', m.valeur );
		verifier( m.stocke && ! ( 'zoom' in m.stocke ), 'Auto : zoom absent de yn.manga', JSON.stringify( m.stocke ) );

		for ( let i = 0; i < 12; i++ ) {
			await page.click( '[data-yn-manga-zoom="plus"]', { force: true } );
		}
		m = await page.evaluate( mesures );
		verifier( m.valeur === '300 %' && await page.isDisabled( '[data-yn-manga-zoom="plus"]' ), 'zoom borné à 300 % (« + » désactivé)', m.valeur );
		verifier( m.debordement <= 0, 'aucun défilement horizontal du document (300 %)', `${ m.debordement } px` );
		await page.click( '[data-yn-manga-zoom="auto"]' );
		await page.click( '[data-yn-manga-zoom="plus"]' );
		await page.click( '[data-yn-manga-zoom="plus"]' );

		await page.reload( { waitUntil: 'load' } );
		await page.evaluate( () => document.querySelectorAll( '.yn-manga__page img[loading="lazy"]' ).forEach( ( img ) => img.setAttribute( 'loading', 'eager' ) ) );
		await attendreImages( page );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '150 %' && m.attribut === 'manuel' && proche( m.p2.img.width, auto.p2.img.width * 1.5 ), 'réglage (150 %) gardé après un rechargement', `${ m.valeur }, ${ m.p2.img.width }` );
		verifier( m.debordement <= 0, 'aucun défilement horizontal du document après rechargement', `${ m.debordement } px` );

		// ------------------------------------------------------------- Bureau, page par page.
		console.log( '[manga-zoom] bureau 1440 × 900, page par page' );
		await page.click( '[data-yn-manga-zoom="auto"]' );
		await page.click( '[data-yn-manga-mode="page"]' );
		await page.click( '[data-yn-manga-aller="suivante"]' );
		await page.waitForTimeout( 100 );
		m = await page.evaluate( mesures );
		verifier( m.courante === '1' && m.p2.visible && ! m.p1.visible, 'mode page : page 2 (paysage) affichée seule', m.courante );
		await page.evaluate( () => {
			const f = document.querySelector( '.yn-manga__page[data-yn-page="2"]' );
			window.scrollTo( 0, f.getBoundingClientRect().top + window.pageYOffset - 80 );
		} );
		m = await page.evaluate( mesures );
		verifier( m.p2.img.height <= m.hauteur - 140 + 1, 'mode page : double page entière en hauteur (100dvh − 140 px)', `${ m.p2.img.height } / ${ m.hauteur }` );
		verifier( m.p2.img.left >= 0 && m.p2.img.right <= m.fenetre, 'mode page : double page entière en largeur', `${ m.p2.img.left } → ${ m.p2.img.right }` );
		verifier( m.p2.img.width > m.colonne.width, 'mode page : double page plus large que la colonne (et qu’une page portrait)', `${ m.p2.img.width } / ${ m.colonne.width }` );
		verifier( proche( m.p2.img.left + m.p2.img.width / 2, m.fenetre / 2 ), 'mode page : double page centrée sur la fenêtre' );
		verifier( m.debordement <= 0, 'mode page : aucun défilement horizontal du document', `${ m.debordement } px` );

		const centre = { x: m.p2.img.left + m.p2.img.width * 0.75, y: m.p2.img.top + m.p2.img.height / 2 };
		await page.mouse.dblclick( centre.x, centre.y );
		await page.waitForTimeout( 450 );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '200 %' && m.courante === '1', 'double clic : 200 %, la page ne tourne pas', `${ m.valeur }, page ${ m.courante }` );
		verifier( m.p2.defile && m.debordement <= 0, 'double clic : la page zoomée défile dans sa figure, pas le document', `${ m.debordement } px` );
		const r2 = ( await page.evaluate( mesures ) ).p2.figure;
		await page.mouse.dblclick( r2.left + r2.width / 2, Math.max( r2.top + 20, 200 ) );
		await page.waitForTimeout( 450 );
		m = await page.evaluate( mesures );
		verifier( m.valeur === 'Auto' && m.courante === '1', 'second double clic : retour à Auto, toujours la page 2', `${ m.valeur }, page ${ m.courante }` );
		await page.mouse.click( m.colonne.left + m.colonne.width * 0.9, m.p2.img.top + m.p2.img.height / 2 );
		await page.waitForTimeout( 450 );
		m = await page.evaluate( mesures );
		verifier( m.courante === '2', 'simple clic sur la moitié droite : page suivante', `page ${ m.courante }` );
		await page.keyboard.press( 'ArrowLeft' );
		m = await page.evaluate( mesures );
		verifier( m.courante === '1', 'flèche ← : page précédente (inchangé)', `page ${ m.courante }` );
		verifier( erreurs.length === 0, 'aucune erreur de console (bureau)', erreurs.join( ' | ' ) );
		await contexte.close();

		// ------------------------------------------------------------- Mobile.
		console.log( '[manga-zoom] mobile 390 × 844' );
		const mobile = await navigateur.newContext( { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 } );
		( { page, erreurs } = await lecteur( mobile, options.url, png ) );
		m = await page.evaluate( mesures );
		verifier( m.debordement <= 0, 'mobile : aucun défilement horizontal (Auto)', `${ m.debordement } px` );
		verifier( m.p2.figure.left >= 0 && m.p2.figure.right <= m.fenetre, 'mobile : double page dans la fenêtre', `${ m.p2.figure.left } → ${ m.p2.figure.right }` );
		await page.tap( '[data-yn-manga-zoom="plus"]' );
		await page.tap( '[data-yn-manga-zoom="plus"]' );
		await page.tap( '[data-yn-manga-zoom="plus"]' );
		await page.tap( '[data-yn-manga-zoom="plus"]' );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '200 %' && m.p1.defile && m.debordement <= 0, 'mobile : 200 %, la page défile dans sa figure, pas le document', `${ m.valeur }, ${ m.debordement } px` );
		await page.tap( '[data-yn-manga-zoom="auto"]' );
		await page.locator( '.yn-manga__page[data-yn-page="1"] img' ).scrollIntoViewIfNeeded();
		const img1 = await page.locator( '.yn-manga__page[data-yn-page="1"] img' ).boundingBox();
		await page.touchscreen.tap( img1.x + img1.width / 2, img1.y + img1.height / 2 );
		await page.waitForTimeout( 80 );
		await page.touchscreen.tap( img1.x + img1.width / 2, img1.y + img1.height / 2 );
		await page.waitForTimeout( 300 );
		m = await page.evaluate( mesures );
		verifier( m.valeur === '200 %' && m.debordement <= 0, 'mobile : double appui sur une page, Auto → 200 %', m.valeur );
		if ( process.env.YUME_MANGA_CAPTURES ) {
			await page.screenshot( { path: `${ process.env.YUME_MANGA_CAPTURES }/mobile-200.png` } );
		}
		const viewport = await page.evaluate( () => ( document.querySelector( 'meta[name="viewport"]' ) || {} ).content || '' );
		verifier( ! /user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\D|$)/.test( viewport ), 'mobile : pincement du navigateur permis (viewport)', viewport );
		verifier( erreurs.length === 0, 'aucune erreur de console (mobile)', erreurs.join( ' | ' ) );
		await mobile.close();
	} catch ( e ) {
		console.error( `[manga-zoom] erreur : ${ e && e.stack ? e.stack : e }` );
		statut = 2;
	} finally {
		await navigateur.close();
	}
	console.log( `[manga-zoom] ${ reussis } réussi(s), ${ echecs } échec(s)` );
	process.exit( statut || ( echecs ? 1 : 0 ) );
}

principal();
