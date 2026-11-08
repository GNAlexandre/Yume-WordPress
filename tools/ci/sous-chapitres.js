#!/usr/bin/env node
/**
 * Parcours de bout en bout « sous-chapitres » (lot B, 2.8.0), dans Chromium (Playwright) :
 * connexion admin, formulaire « Ajouter des chapitres » (tome choisi), dépôt d'un DOCX synthétique
 * à la structure de Miss Medic (tools/ci/sous-chapitres.php), « Couper aux Titre 2
 * (sous-chapitres) », vérification du découpage (aperçu groupé), publication ; puis page du tome
 * (sommaire groupé, listes imbriquées) et page d'une partie (surtitre, titre, précédent / suivant).
 *
 *   node tools/ci/sous-chapitres.js --base http://127.0.0.1:8102 --publier URL --docx FICHIER
 *
 * tools/ci/sous-chapitres.sh fait tout (base locale, préparation, serveur, ce script). Code de
 * sortie : 0 conforme, 1 écart, 2 erreur d'utilisation ou technique. Aucune requête hors du site
 * local : tout autre hôte est refusé (page.route).
 */
'use strict';

const path = require( 'path' );

function usage( message ) {
	if ( message ) {
		console.error( `[sous-chapitres] erreur : ${ message }` );
	}
	console.error( 'Usage : node tools/ci/sous-chapitres.js --base URL --publier URL --docx FICHIER [--captures DOSSIER]' );
	process.exit( 2 );
}

function lireArguments( argv ) {
	const options = { base: '', publier: '', docx: '', captures: '' };
	for ( let i = 0; i < argv.length; i++ ) {
		const valeur = () => {
			if ( i + 1 >= argv.length ) {
				usage( `valeur manquante pour ${ argv[ i ] }` );
			}
			return argv[ ++i ];
		};
		switch ( argv[ i ] ) {
			case '--base': options.base = valeur().replace( /\/$/, '' ); break;
			case '--publier': options.publier = valeur(); break;
			case '--docx': options.docx = path.resolve( valeur() ); break;
			case '--captures': options.captures = path.resolve( valeur() ); break;
			case '-h': case '--aide': usage(); break;
			default: usage( `option inconnue : ${ argv[ i ] }` );
		}
	}
	if ( ! options.base || ! options.publier || ! options.docx ) {
		usage( '--base, --publier et --docx sont obligatoires' );
	}
	return options;
}

const ecarts = [];
let controles = 0;

/** Vérifie une condition ; un écart est noté sans arrêter le parcours. */
function verifier( condition, message, detail ) {
	controles++;
	if ( condition ) {
		console.log( `  ✓ ${ message }` );
		return;
	}
	ecarts.push( message + ( undefined !== detail ? ` (obtenu : ${ JSON.stringify( detail ) })` : '' ) );
	console.log( `  ✗ ${ message }` + ( undefined !== detail ? ` — obtenu : ${ JSON.stringify( detail ) }` : '' ) );
}

/** Texte normalisé (espaces, insécables) d'un élément. */
function texte( s ) {
	return String( s || '' ).replace( /[\s  ]+/g, ' ' ).trim();
}

async function principal() {
	const options = lireArguments( process.argv.slice( 2 ) );
	const { chromium } = require( 'playwright' );
	const navigateur = await chromium.launch();
	const contexte = await navigateur.newContext( { viewport: { width: 1440, height: 1000 }, locale: 'fr-FR' } );
	const page = await contexte.newPage();
	const hote = new URL( options.base ).host;
	const erreursConsole = [];
	await contexte.route( '**/*', ( route ) => {
		const url = new URL( route.request().url() );
		return url.host === hote || 'data:' === url.protocol ? route.continue() : route.abort();
	} );
	page.on( 'console', ( m ) => {
		if ( 'error' === m.type() && ! /Failed to load resource/.test( m.text() ) ) {
			erreursConsole.push( m.text() );
		}
	} );
	page.on( 'pageerror', ( e ) => erreursConsole.push( String( e ) ) );
	page.on( 'dialog', ( d ) => d.accept() );
	const capture = async ( nom ) => {
		if ( options.captures ) {
			await page.screenshot( { path: path.join( options.captures, nom + '.png' ), fullPage: true } );
		}
	};

	try {
		console.log( '[sous-chapitres] connexion' );
		await page.goto( options.base + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		console.log( '[sous-chapitres] formulaire de publication' );
		await page.goto( options.publier );
		await page.waitForSelector( '[data-yn-oeuvre]' );
		const analyse = page.waitForResponse( ( r ) => /publications\/analyse/.test( r.url() ) && 'POST' === r.request().method() );
		await page.setInputFiles( '#yn-publish-source', options.docx );
		const reponse = await analyse;
		verifier( 200 === reponse.status(), 'analyse du fichier', reponse.status() );
		await page.waitForSelector( '[data-yn-decoupage]:not([hidden])' );
		const bouton = page.locator( '[data-yn-plan-action="sous_chapitres"]' );
		verifier( await bouton.isVisible(), 'bouton « Couper aux Titre 2 (sous-chapitres) » affiché' );
		verifier( 'Couper aux Titre 2 (sous-chapitres)' === texte( await bouton.textContent() ), 'libellé du bouton', texte( await bouton.textContent() ) );

		await bouton.click();
		verifier( await page.isChecked( '#yn-publish-plan-actif' ), '« Utiliser ce découpage » coché' );
		const natures = await page.$$eval( '[data-yn-plan-lignes] tr.is-coche select', ( s ) => s.map( ( x ) => x.value ) );
		verifier(
			JSON.stringify( natures ) === JSON.stringify( [ 'chapitre', 'chapitre', 'sous-chapitre', 'sous-chapitre', 'sous-chapitre', 'chapitre', 'chapitre', 'sous-chapitre', 'postface' ] ),
			'débuts cochés : Titre 1 → chapitre (postface gardée), Titre 2 → sous-chapitre',
			natures
		);
		const compte = texte( await page.textContent( '[data-yn-plan-compte]' ) );
		verifier( /9 chapitres avec ce découpage/.test( compte ) && /4 × sous-chapitre/.test( compte ), 'décompte du découpage', compte );

		const essai = page.waitForResponse( ( r ) => /publications\/analyse/.test( r.url() ) && 'POST' === r.request().method() );
		await page.click( '[data-yn-plan-verifier]' );
		await essai;
		await page.waitForSelector( '[data-yn-liste] .yn-publish__groupe' );
		const groupes = await page.$$eval( '[data-yn-liste] .yn-publish__groupe', ( g ) => g.map( ( x ) => Array.from( x.children ).map( ( e ) => e.textContent.replace( /[\s  ]+/g, ' ' ).trim() ) ) );
		verifier( groupes.length >= 1 && /^Chapitre 2 · Bataille de la colline$/.test( groupes[ 0 ][ 0 ] ), 'aperçu : ligne du chapitre parent sans texte', groupes[ 0 ] );
		verifier( groupes.length >= 1 && /^Partie 1 · Premier jour, soir \d+ mots$/.test( groupes[ 0 ][ 1 ] || '' ), 'aperçu : partie en retrait sous son chapitre', groupes[ 0 ] );
		const retrait = await page.$eval( '[data-yn-liste] .yn-publish__partie', ( e ) => parseFloat( getComputedStyle( e ).paddingLeft ) );
		verifier( retrait > 0, 'aperçu : parties en retrait', retrait );
		await capture( 'formulaire-decoupage' );

		console.log( '[sous-chapitres] publication' );
		await page.check( '#yn-publish-sortie-maintenant' );
		const publication = page.waitForResponse( ( r ) => /publications\/\d+\/publier/.test( r.url() ) && 'POST' === r.request().method(), { timeout: 120000 } );
		await page.click( '[data-yn-etape="publier"]' );
		const sortie = await publication;
		verifier( 200 === sortie.status(), 'publication des chapitres', sortie.status() );
		const lienTome = page.locator( '[data-yn-resultat] a', { hasText: 'Voir le tome' } );
		await lienTome.first().waitFor( { timeout: 30000 } ).catch( () => {} );
		let urlTome = await lienTome.first().getAttribute( 'href' ).catch( () => '' );
		if ( ! urlTome ) {
			urlTome = ( await sortie.json() ).tome.lien;
		}

		console.log( '[sous-chapitres] page du tome' );
		await page.goto( urlTome );
		await page.waitForSelector( '.yn-toc' );
		const sommaire = await page.$$eval( '.yn-toc__liste > li', ( items ) => items.map( ( li ) => ( {
			ligne: Array.from( li.querySelector( '.yn-toc__lien' ).children ).slice( 0, 2 ).map( ( e ) => e.textContent.replace( /[\s  ]+/g, ' ' ).trim() ).join( ' · ' ),
			parties: Array.from( li.querySelectorAll( ':scope > ol.yn-toc__parties > li' ) ).map( ( p ) => Array.from( p.querySelector( '.yn-toc__lien' ).children ).slice( 0, 2 ).map( ( e ) => e.textContent.replace( /[\s  ]+/g, ' ' ).trim() ).join( ' · ' ) ),
		} ) ) );
		verifier(
			JSON.stringify( sommaire ) === JSON.stringify( [
				{ ligne: 'Chapitre 1 · Année 1, été 1', parties: [] },
				{ ligne: 'Chapitre 2 · Bataille de la colline', parties: [ 'Partie 1 · Premier jour, soir', 'Partie 2 · Premier jour, nuit', 'Partie 3 · Deuxième jour, matin' ] },
				{ ligne: 'Chapitre 3 · Année 1, été 2', parties: [] },
				{ ligne: 'Chapitre 4 · Évacuation du village', parties: [ 'Partie 1 · Troisième jour' ] },
				{ ligne: 'Postface · ', parties: [] },
			] ),
			'sommaire groupé : parties dans des listes imbriquées sous leur chapitre',
			sommaire
		);
		verifier( 0 === await page.locator( '.yn-toc__intitule' ).evaluateAll( ( e ) => e.filter( ( x ) => 'A' === x.tagName ).length ), 'chapitre sans page : intitulé sans lien' );
		const durees = await page.$$eval( '.yn-toc__item--partie .yn-toc__duree', ( d ) => d.map( ( x ) => x.textContent.trim() ) );
		verifier( durees.length === 4 && durees.every( ( d ) => /min/.test( d ) ), 'durée de lecture de chaque partie', durees );
		const decalage = await page.$eval( '.yn-toc__item--partie .yn-toc__lien', ( e ) => parseFloat( getComputedStyle( e ).paddingLeft ) );
		verifier( decalage > 30, 'parties en retrait dans le sommaire', decalage );
		const lienPartie = await page.$eval( '.yn-toc__parties a[href*="chapitre-2-partie-2"]', ( a ) => a.href ).catch( () => '' );
		verifier( /\/chapitre-2-partie-2\/$/.test( lienPartie ), 'adresse d’une partie : …/chapitre-2-partie-2/', lienPartie );
		await capture( 'tome' );

		console.log( '[sous-chapitres] page d’une partie' );
		await page.goto( lienPartie );
		await page.waitForSelector( '.yn-chapter-header' );
		verifier( 'Chapitre 2 — Bataille de la colline' === texte( await page.textContent( '.yn-chapter-header__surtitre' ) ), 'en-tête : surtitre du chapitre parent' );
		verifier( 'Partie 2' === texte( await page.textContent( '.yn-chapter-header__titre' ) ), 'en-tête : « Partie 2 » en titre' );
		verifier( 'Premier jour, nuit' === texte( await page.textContent( '.yn-chapter-header .yn-subtitle' ) ), 'en-tête : sous-titre de la partie' );
		const titreDocument = await page.title();
		verifier( titreDocument.includes( 'Chapitre 2, partie 2' ) && titreDocument.includes( 'Premier jour, nuit' ), 'titre du document cohérent', titreDocument );
		const precedent = await page.$eval( '.yn-chapter-nav a[rel="prev"]', ( a ) => ( { href: a.href, texte: a.textContent } ) );
		const suivant = await page.$eval( '.yn-chapter-nav a[rel="next"]', ( a ) => ( { href: a.href, texte: a.textContent } ) );
		verifier( /chapitre-2-partie-1\/$/.test( precedent.href ) && texte( precedent.texte ).includes( 'Chapitre 2, partie 1' ), 'précédent : partie 1', precedent );
		verifier( /chapitre-2-partie-3\/$/.test( suivant.href ) && texte( suivant.texte ).includes( 'Chapitre 2, partie 3 · Deuxième jour, matin' ), 'suivant : partie 3', suivant );
		await capture( 'partie' );

		// Dernière partie du chapitre 2 → chapitre 3 ; première partie : illustration du parent.
		await page.goto( suivant.href );
		const suivantChapitre = await page.$eval( '.yn-chapter-nav a[rel="next"]', ( a ) => a.textContent );
		verifier( texte( suivantChapitre ).includes( 'Chapitre 3 · Année 1, été 2' ), 'suivant de la dernière partie : chapitre 3', texte( suivantChapitre ) );
		await page.goto( precedent.href );
		verifier( 1 === await page.locator( '.yn-reader figure img' ).count(), 'partie 1 : illustration du chapitre parent sans texte' );
		const debordement = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
		verifier( ! debordement, 'aucun défilement horizontal' );
		verifier( 0 === erreursConsole.length, 'aucune erreur JavaScript', erreursConsole );
	} finally {
		await navigateur.close();
	}
	console.log( `[sous-chapitres] ${ controles - ecarts.length } contrôle(s) réussi(s), ${ ecarts.length } écart(s)` );
	return ecarts.length ? 1 : 0;
}

principal().then( ( code ) => process.exit( code ) ).catch( ( e ) => {
	console.error( `[sous-chapitres] erreur technique : ${ e && e.stack ? e.stack : e }` );
	process.exit( 2 );
} );
