#!/usr/bin/env node
/**
 * Parcours de bout en bout « Programmer les sorties » (docs/09, lot C) sur le WordPress LOCAL :
 * formulaire « Ajouter des chapitres à un tome » avec un DOCX synthétique de 45 unités
 * (structure du tome 2 de Miss Medic, textes inventés : tools/ci/programmer-donnees.php),
 * « Calendrier sur mesure », 10 publications, « Répartir », date de la première publication
 * puis « Recalculer les dates », date de la publication 3 modifiée à la main, publication ;
 * vérification des dates enregistrées (post_date) ; puis vue de l'espace équipe « Programmer
 * les sorties » : mêmes 10 publications, date de la dernière publication changée, enregistrement
 * par POST /yume/v1/publications/{id}/calendrier, dates vérifiées. Aucune dépendance nouvelle :
 * require('playwright') (Chromium préinstallé).
 *
 *   . <dossier>/env.sh ; YUME_ENV=e2e tools/localenv/serve.sh 8103 &
 *   YUME_ENV=e2e node tools/ci/programmer.js --base http://127.0.0.1:8103
 *
 * Code de sortie : 0 réussi, 1 échec, 2 erreur d'utilisation. Les données créées sont supprimées
 * à la fin (sauf --garder).
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( 'playwright' );

const RACINE = path.resolve( __dirname, '../..' );
const programmer = require( path.join( RACINE, 'wp-content/plugins/yume-core/includes/publication/blocks/publish-form/programmer.js' ) );
const fixture = require( path.join( RACINE, 'wp-content/plugins/yume-core/tests/fixtures/repartition-miss-medic.json' ) );

function lireArguments( argv ) {
	const options = { base: 'http://127.0.0.1:8103', utilisateur: 'admin', mdp: 'admin', garder: false };
	for ( let i = 0; i < argv.length; i++ ) {
		switch ( argv[ i ] ) {
			case '--base': options.base = argv[ ++i ]; break;
			case '--utilisateur': options.utilisateur = argv[ ++i ]; break;
			case '--mdp': options.mdp = argv[ ++i ]; break;
			case '--garder': options.garder = true; break;
			default:
				console.error( 'Usage : node tools/ci/programmer.js [--base URL] [--utilisateur admin] [--mdp admin] [--garder]' );
				process.exit( 2 );
		}
	}
	options.base = options.base.replace( /\/$/, '' );
	return options;
}

function wp( ...args ) {
	const sortie = execFileSync( path.join( RACINE, 'tools/localenv/wp.sh' ), [ 'eval-file', path.join( RACINE, 'tools/ci/programmer-donnees.php' ), ...args.map( String ) ], { encoding: 'utf8', cwd: RACINE } );
	const ligne = sortie.trim().split( '\n' ).pop() || '';
	return ligne.startsWith( '{' ) ? JSON.parse( ligne ) : null;
}

let echecs = 0;
function verifier( condition, message ) {
	console.log( `${ condition ? '  ✓' : '  ✗' } ${ message }` );
	if ( ! condition ) {
		echecs++;
	}
}

/** Date locale « AAAA-MM-JJTHH:MM » dans j jours à h:m (calendrier naïf, comme programmer.js). */
function dans( maintenant, jours, h, m ) {
	const base = programmer.lireDate( maintenant );
	const d = new Date( base + jours * 86400000 );
	return programmer.ecrireDate( Date.UTC( d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), h, m ) );
}

/** Tailles des publications affichées par le panneau (lignes d'unités par groupe). */
function taillesAffichees( page, panneau ) {
	return page.$$eval( panneau + ' .yn-programmer__groupe', ( groupes ) => groupes.map( ( g ) => g.querySelectorAll( '.yn-programmer__unite' ).length ) );
}

async function main() {
	const options = lireArguments( process.argv.slice( 2 ) );
	const donnees = wp( 'preparer' );
	if ( ! donnees ) {
		console.error( '[programmer] préparation des données impossible' );
		process.exit( 2 );
	}
	const local = ( url ) => url.replace( /^https?:\/\/[^/]+/, options.base );
	const attendu = fixture.attendu[ '10' ];
	const navigateur = await chromium.launch();
	const page = await navigateur.newPage( { viewport: { width: 1360, height: 900 } } );
	const erreursConsole = [];
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			erreursConsole.push( m.text() );
		}
	} );
	page.on( 'pageerror', ( e ) => erreursConsole.push( String( e ) ) );
	page.on( 'dialog', ( d ) => d.accept() );
	try {
		console.log( '[programmer] connexion' );
		await page.goto( options.base + '/wp-login.php' );
		await page.fill( '#user_login', options.utilisateur );
		await page.fill( '#user_pass', options.mdp );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		console.log( '[programmer] formulaire « Ajouter des chapitres à un tome »' );
		await page.goto( local( donnees.publier ) );
		await page.setInputFiles( '[data-yn-fichier="source"]', donnees.docx );
		await page.waitForSelector( '[data-yn-comparaison]:not([hidden])', { timeout: 60000 } );
		await page.check( '#yn-publish-sortie-calendrier' );
		const panneau = '#yume-publication [data-yn-programmer]';
		await page.waitForSelector( panneau + ':not([hidden]) .yn-programmer__table' );
		const unites = await page.$$eval( panneau + ' .yn-programmer__unite', ( l ) => l.length );
		verifier( unites === 45, `45 unités à sortir dans le panneau (${ unites })` );
		await page.fill( panneau + ' [data-yn-programmer-n]', '10' );
		await page.click( panneau + ' [data-yn-programmer-action="repartir"]' );
		const tailles = await taillesAffichees( page, panneau );
		verifier( JSON.stringify( tailles ) === JSON.stringify( attendu ), `10 publications, mêmes groupes que la fixture : ${ JSON.stringify( tailles ) }` );
		const resume = await page.textContent( panneau + ' [data-yn-programmer-resume]' );
		verifier( /^10 publications · 5\s?526 mots en moyenne · de 4\s?252 à 8\s?453$/.test( resume.trim() ), `résumé : « ${ resume.trim() } »` );

		// Date de la première publication, puis « Recalculer les dates » (tous les 7 jours).
		const config = JSON.parse( await page.getAttribute( panneau, 'data-yn-programmer-config' ) );
		const premiere = dans( config.maintenant, 2, 18, 0 );
		await page.fill( panneau + ' #yn-programmer-date-0', premiere );
		await page.click( panneau + ' [data-yn-programmer-action="recalculer"]' );
		const attendues = programmer.datesDepuis( { rythme: null, maintenant: config.maintenant }, 10, premiere, 7 );
		const p3 = dans( config.maintenant, 17, 9, 30 );
		attendues[ 2 ] = p3;
		await page.fill( panneau + ' #yn-programmer-date-2', p3 );
		const affichees = await page.$$eval( panneau + ' input[type="datetime-local"]', ( l ) => l.map( ( c ) => c.value ) );
		verifier( JSON.stringify( affichees ) === JSON.stringify( attendues ), 'dates affichées : première choisie, suivantes recalculées, publication 3 modifiée' );

		console.log( '[programmer] publication' );
		await page.click( '[data-yn-etape="publier"]' );
		await page.waitForSelector( '[data-yn-resultat]:not([hidden])', { timeout: 120000 } );
		const message = await page.textContent( '[data-yn-messages]' );
		verifier( /10 publications/.test( message ), `message : « ${ message.trim().slice( 0, 160 ) } »` );

		let etat = wp( 'verifier', donnees.tome );
		const datesChapitres = etat.chapitres.map( ( c ) => c.date );
		const attenduesChapitres = [];
		attendu.forEach( ( t, g ) => {
			for ( let i = 0; i < t; i++ ) {
				attenduesChapitres.push( attendues[ g ] );
			}
		} );
		verifier( etat.chapitres.every( ( c ) => c.statut === 'future' ), '45 chapitres programmés' );
		verifier( JSON.stringify( datesChapitres ) === JSON.stringify( attenduesChapitres ), 'post_date de chaque chapitre = date de sa publication' );
		verifier( etat.tome === 'future', 'le tome sort avec la première publication' );
		verifier( etat.groupes.length === 9, `une annonce groupée par publication suivante (${ etat.groupes.length })` );

		console.log( '[programmer] vue « Programmer les sorties »' );
		await page.goto( local( donnees.vue ) );
		const vue = 'form[data-yn-programmer-vue] [data-yn-programmer]';
		await page.waitForSelector( vue + ' .yn-programmer__table' );
		const taillesVue = await taillesAffichees( page, vue );
		verifier( JSON.stringify( taillesVue ) === JSON.stringify( attendu ), 'la vue regroupe les chapitres programmés par publication' );
		const datesVue = await page.$$eval( vue + ' input[type="datetime-local"]', ( l ) => l.map( ( c ) => c.value ) );
		verifier( JSON.stringify( datesVue ) === JSON.stringify( attendues ), 'la vue affiche les dates enregistrées' );
		const derniere = dans( config.maintenant, 80, 20, 0 );
		await page.fill( vue + ' #yn-programmer-vue-date-9', derniere );
		await page.click( 'form[data-yn-programmer-vue] button[type="submit"]' );
		await page.waitForSelector( 'form[data-yn-programmer-vue] .yn-team__retour--ok', { timeout: 60000 } );
		etat = wp( 'verifier', donnees.tome );
		const fin = etat.chapitres.slice( -attendu[ 9 ] ).map( ( c ) => c.date );
		verifier( fin.every( ( d ) => d === derniere ), 'dernière publication reprogrammée par la route /calendrier' );
		verifier( etat.chapitres[ 0 ].date === premiere, 'les autres publications gardent leur date' );
		// Le panneau tient dans la page (l'en-tête du thème, hors de ce lot, n'est pas mesuré).
		const tient = await page.$eval( vue, ( p ) => p.getBoundingClientRect().right <= window.innerWidth && p.scrollWidth <= p.clientWidth + 1 );
		verifier( tient, 'le panneau tient dans la largeur de la page' );
		verifier( erreursConsole.length === 0, `aucune erreur JavaScript${ erreursConsole.length ? ' : ' + erreursConsole.join( ' | ' ) : '' }` );
	} catch ( e ) {
		echecs++;
		console.error( '[programmer] erreur :', e.message );
	} finally {
		await navigateur.close();
		fs.rmSync( donnees.docx, { force: true } );
		if ( ! options.garder ) {
			wp( 'nettoyer', donnees.oeuvre );
		}
	}
	console.log( echecs ? `[programmer] ${ echecs } échec(s)` : '[programmer] parcours réussi' );
	process.exit( echecs ? 1 : 0 );
}

main();
