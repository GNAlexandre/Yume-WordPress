#!/usr/bin/env node
/**
 * Parcours de bout en bout « mise en page » (lot E, 2.9.0), dans Chromium (Playwright) :
 * connexion admin, vue « Mettre en page » du chapitre 1 de la démo, sélection de trois
 * paragraphes (Maj + clic), touche D (classe et tiret vérifiés), double clic et correction d'un
 * mot, Entrée pour scinder, annuler / rétablir, enregistrement (Ctrl+S) ; puis page du chapitre
 * côté lecteur (rendu) ; puis conflit simulé (deux onglets : le second enregistrement, sur une
 * base périmée, affiche le message et « Recharger ») ; enfin la palette en bas sur mobile.
 *
 *   node tools/ci/mise-en-page.js --base http://127.0.0.1:8111 --vue URL --lecture URL [--captures DOSSIER]
 *
 * tools/ci/mise-en-page.sh fait tout (base locale, préparation, serveur, ce script). Code de
 * sortie : 0 conforme, 1 écart, 2 erreur d'utilisation ou technique. Aucune requête hors du site
 * local : tout autre hôte est refusé (route).
 */
'use strict';

const path = require( 'path' );

function usage( message ) {
	if ( message ) {
		console.error( `[mise-en-page] erreur : ${ message }` );
	}
	console.error( 'Usage : node tools/ci/mise-en-page.js --base URL --vue URL --lecture URL [--captures DOSSIER]' );
	process.exit( 2 );
}

function lireArguments( argv ) {
	const options = { base: '', vue: '', lecture: '', captures: '' };
	for ( let i = 0; i < argv.length; i++ ) {
		const valeur = () => {
			if ( i + 1 >= argv.length ) {
				usage( `valeur manquante pour ${ argv[ i ] }` );
			}
			return argv[ ++i ];
		};
		switch ( argv[ i ] ) {
			case '--base': options.base = valeur().replace( /\/$/, '' ); break;
			case '--vue': options.vue = valeur(); break;
			case '--lecture': options.lecture = valeur(); break;
			case '--captures': options.captures = path.resolve( valeur() ); break;
			case '-h': case '--aide': usage(); break;
			default: usage( `option inconnue : ${ argv[ i ] }` );
		}
	}
	if ( ! options.base || ! options.vue || ! options.lecture ) {
		usage( '--base, --vue et --lecture sont obligatoires' );
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

/** Texte normalisé (espaces, insécables). */
function texte( s ) {
	return String( s || '' ).replace( /[\s  ]+/g, ' ' ).trim();
}

const UNITES = '[data-yn-mep-unites] .yn-mep__unite';

/** Ouvre la vue et attend les unités. */
async function ouvrirVue( page, url ) {
	await page.goto( url );
	await page.waitForSelector( '[data-yn-mep-barre]:not([hidden])' );
	await page.waitForSelector( UNITES );
}

/** Lettres des étiquettes (R, D, P, C, —…). */
function lettres( page ) {
	return page.$$eval( UNITES + ' .yn-mep__lettre', ( l ) => l.map( ( x ) => x.textContent.trim() ) );
}

/** Sélectionne dans la saisie en cours le texte « mot » (première occurrence) ou place le curseur après. */
async function choisirTexte( page, mot, apres ) {
	return page.evaluate( ( [ m, a ] ) => {
		const el = document.querySelector( '.yn-mep__texte[contenteditable]' );
		if ( ! el ) {
			return false;
		}
		const marcheur = document.createTreeWalker( el, NodeFilter.SHOW_TEXT );
		let n = marcheur.nextNode();
		while ( n ) {
			const i = n.nodeValue.indexOf( m );
			if ( -1 !== i ) {
				const r = document.createRange();
				if ( a ) {
					r.setStart( n, i + m.length );
					r.collapse( true );
				} else {
					r.setStart( n, i );
					r.setEnd( n, i + m.length );
				}
				const s = window.getSelection();
				s.removeAllRanges();
				s.addRange( r );
				return true;
			}
			n = marcheur.nextNode();
		}
		return false;
	}, [ mot, apres ] );
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
	const suivre = ( p ) => {
		p.on( 'console', ( m ) => {
			if ( 'error' === m.type() && ! /Failed to load resource/.test( m.text() ) ) {
				erreursConsole.push( m.text() );
			}
		} );
		p.on( 'pageerror', ( e ) => erreursConsole.push( String( e ) ) );
		p.on( 'dialog', ( d ) => d.accept() );
	};
	suivre( page );
	const capture = async ( p, nom ) => {
		if ( options.captures ) {
			await p.screenshot( { path: path.join( options.captures, nom + '.png' ), fullPage: true } );
		}
	};
	const modif = process.platform === 'darwin' ? 'Meta' : 'Control';

	try {
		console.log( '[mise-en-page] connexion' );
		await page.goto( options.base + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		console.log( '[mise-en-page] vue « Mettre en page »' );
		await ouvrirVue( page, options.vue );
		const titre = texte( await page.textContent( '.yn-team__bonjour' ) );
		verifier( /^Mettre en page · Chapitre 1/.test( titre ), 'titre de la vue', titre );
		verifier( 3 === await page.locator( '.yn-mep__fil li' ).count(), 'fil d’Ariane œuvre › tome › chapitre' );
		verifier( await page.locator( '[data-yn-mep-sansjs]' ).isHidden(), 'message « sans JavaScript » masqué' );
		verifier( JSON.stringify( await lettres( page ) ) === JSON.stringify( [ 'R', 'D', 'D', 'P', '—', 'R' ] ), 'étiquettes R / D / P / — dans la marge', await lettres( page ) );
		verifier( 1 === await page.locator( '.yn-mep__lecture.yn-reader' ).count(), 'colonne de lecture .yn-reader' );

		// Sélection de trois paragraphes (clic puis Maj + clic), puis D.
		const unites = page.locator( UNITES );
		await unites.nth( 0 ).click();
		await unites.nth( 2 ).click( { modifiers: [ 'Shift' ] } );
		verifier( 3 === await page.locator( UNITES + '[aria-selected="true"]' ).count(), 'Maj + clic : trois paragraphes sélectionnés' );
		await page.keyboard.press( 'd' );
		const premier = page.locator( UNITES ).nth( 0 );
		verifier( /\byn-dialogue\b/.test( await premier.locator( 'p' ).getAttribute( 'class' ) ), 'D : classe yn-dialogue posée' );
		verifier( '—' === texte( await premier.locator( '.yn-tiret' ).textContent() ), 'D : tiret de dialogue affiché' );
		verifier( /^Le vent/.test( texte( await premier.locator( '.yn-mep__texte' ).textContent() ) ), 'D : le tiret n’est pas dans le texte saisi' );
		verifier( 'true' === await page.getAttribute( '[data-yn-mep-style="dialogue"]', 'aria-pressed' ), 'palette : Dialogue enfoncé (aria-pressed)' );
		await page.waitForFunction( () => /passé en Dialogue/.test( document.querySelector( '[data-yn-mep-annonce]' ).textContent ) );
		verifier( true, 'annonce aria-live « 1 paragraphe passé en Dialogue »' );
		verifier( '1 paragraphe modifié' === texte( await page.textContent( '[data-yn-mep-compteur]' ) ), 'compteur : 1 paragraphe modifié' );

		// Double clic sur un mot du dernier paragraphe, correction, puis Entrée pour scinder.
		const dernier = page.locator( UNITES ).nth( 5 );
		const boite = await dernier.evaluate( ( el ) => {
			const t = el.querySelector( '.yn-mep__texte' ).firstChild;
			const i = t.nodeValue.indexOf( 'givre' );
			const r = document.createRange();
			r.setStart( t, i );
			r.setEnd( t, i + 5 );
			const b = r.getBoundingClientRect();
			return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
		} );
		await page.mouse.dblclick( boite.x, boite.y );
		await page.waitForSelector( '.yn-mep__texte[contenteditable="true"]' );
		verifier( await dernier.evaluate( ( el ) => el.classList.contains( 'is-edition' ) ), 'double clic : paragraphe en saisie (contenteditable)' );
		verifier( await choisirTexte( page, 'givre', false ), 'mot à corriger trouvé' );
		await page.keyboard.type( 'neige' );
		verifier( await choisirTexte( page, 'neige,', true ), 'curseur placé après « neige, »' );
		await page.keyboard.press( 'Enter' );
		await page.waitForFunction( ( sel ) => 7 === document.querySelectorAll( sel ).length, UNITES );
		await page.keyboard.press( 'Escape' );
		const textes = await page.$$eval( UNITES + ' .yn-mep__texte', ( t ) => t.map( ( x ) => x.textContent ) );
		verifier( /de neige,$/.test( texte( textes[ 4 ] ) ), 'Entrée : premier morceau « … de neige, »', textes[ 4 ] );
		verifier( /^et personne/.test( texte( textes[ 5 ] ) ), 'Entrée : second paragraphe « et personne… »', textes[ 5 ] );
		verifier( '3 paragraphes modifiés' === texte( await page.textContent( '[data-yn-mep-compteur]' ) ), 'compteur : 3 paragraphes modifiés', texte( await page.textContent( '[data-yn-mep-compteur]' ) ) );

		// Annuler / rétablir (pile en mémoire).
		await page.locator( UNITES ).nth( 0 ).click();
		await page.keyboard.press( modif + '+z' );
		verifier( 6 === await page.locator( UNITES ).count(), 'Ctrl+Z : coupure annulée' );
		await page.keyboard.press( modif + '+Shift+z' );
		verifier( 7 === await page.locator( UNITES ).count(), 'Ctrl+Maj+Z : coupure rétablie' );

		// Ajouter un paragraphe après la pensée et taper une phrase ; Entrée en fin crée un
		// paragraphe vide du même style, retiré quand il perd le focus.
		await page.locator( UNITES ).nth( 3 ).click();
		await page.click( '[data-yn-mep-op="ajouter"]' );
		await page.waitForSelector( '.yn-mep__texte[contenteditable="true"]', { state: 'attached' } );
		verifier( 8 === await page.locator( UNITES ).count(), '« Ajouter un paragraphe après » : nouveau paragraphe en saisie' );
		await page.keyboard.type( 'Elle sourit dans le noir.' );
		await page.keyboard.press( 'Enter' );
		verifier( 9 === await page.locator( UNITES ).count(), 'Entrée en fin de paragraphe : nouveau paragraphe vide' );
		const styleNouveau = await page.$eval( UNITES + '.is-edition .yn-mep__lettre', ( e ) => e.textContent.trim() ).catch( () => '' );
		verifier( 'P' === styleNouveau, 'nouveau paragraphe du même style (Pensée)', styleNouveau );
		await page.locator( '.yn-team__bonjour' ).click();
		await page.waitForFunction( ( sel ) => 8 === document.querySelectorAll( sel ).length, UNITES );
		verifier( true, 'paragraphe vide retiré à la perte du focus' );
		const ajoute = await page.$$eval( UNITES, ( us ) => us.map( ( u ) => u.querySelector( '.yn-mep__lettre' ).textContent.trim() + ' ' + ( u.querySelector( '.yn-mep__texte' ) ? u.querySelector( '.yn-mep__texte' ).textContent : '' ) ) );
		verifier( 'P Elle sourit dans le noir.' === ajoute[ 4 ], 'phrase tapée dans le paragraphe ajouté (Pensée)', ajoute[ 4 ] );
		verifier( '4 paragraphes modifiés' === texte( await page.textContent( '[data-yn-mep-compteur]' ) ), 'compteur : 4 paragraphes modifiés', texte( await page.textContent( '[data-yn-mep-compteur]' ) ) );
		await capture( page, 'vue-avant-enregistrement' );

		console.log( '[mise-en-page] enregistrement' );
		const envoi = page.waitForResponse( ( r ) => /mise-en-page/.test( r.url() ) && 'PUT' === r.request().method() );
		await page.keyboard.press( modif + '+s' );
		const reponse = await envoi;
		verifier( 200 === reponse.status(), 'Ctrl+S : enregistrement accepté', reponse.status() );
		await page.waitForFunction( () => /Enregistré à \d+ h \d\d/.test( document.querySelector( '[data-yn-mep-etat]' ).textContent ) );
		verifier( true, 'état « Enregistré à HH h MM »' );
		verifier( 'Aucune modification' === texte( await page.textContent( '[data-yn-mep-compteur]' ) ), 'compteur remis à zéro' );
		verifier( await page.locator( '[data-yn-mep-revisions]' ).count() > 0, 'lien « Historique des révisions »' );

		console.log( '[mise-en-page] page du chapitre (lecteur)' );
		const lecteur = await contexte.newPage();
		suivre( lecteur );
		await lecteur.goto( options.lecture );
		await lecteur.waitForSelector( '.yn-reader' );
		const paragraphes = await lecteur.$$eval( '.yn-reader .wp-block-post-content > p', ( ps ) => ps.map( ( p ) => ( { classe: p.className, texte: p.textContent, tiret: !! p.querySelector( '.yn-tiret' ) } ) ) );
		verifier( paragraphes.length >= 6 && /\byn-dialogue\b/.test( paragraphes[ 0 ].classe ) && paragraphes[ 0 ].tiret, 'lecteur : premier paragraphe en dialogue, tiret isolé (.yn-tiret)', paragraphes[ 0 ] );
		const tout = paragraphes.map( ( p ) => texte( p.texte ) );
		verifier( tout.some( ( t ) => /de neige,$/.test( t ) ) && ! tout.some( ( t ) => /givre/.test( t ) ), 'lecteur : mot corrigé', tout );
		verifier( tout.some( ( t ) => /^et personne n’osait parler de la lumière\.$/.test( t ) ), 'lecteur : paragraphe scindé', tout );
		verifier( paragraphes.some( ( p ) => /\byn-thought\b/.test( p.classe ) && 'Elle sourit dans le noir.' === texte( p.texte ) ), 'lecteur : paragraphe ajouté en pensée', tout );
		await capture( lecteur, 'lecteur' );
		await lecteur.close();

		console.log( '[mise-en-page] conflit simulé' );
		await ouvrirVue( page, options.vue );
		const autre = await contexte.newPage();
		suivre( autre );
		await ouvrirVue( autre, options.vue );
		await autre.locator( UNITES ).nth( 0 ).click();
		await autre.keyboard.press( 'r' );
		const envoiAutre = autre.waitForResponse( ( r ) => /mise-en-page/.test( r.url() ) && 'PUT' === r.request().method() );
		await autre.click( '[data-yn-mep-enregistrer]' );
		verifier( 200 === ( await envoiAutre ).status(), 'second onglet : enregistrement accepté' );
		await autre.close();

		await page.locator( UNITES ).nth( 3 ).click();
		await page.keyboard.press( 'c' );
		const envoiPerime = page.waitForResponse( ( r ) => /mise-en-page/.test( r.url() ) && 'PUT' === r.request().method() );
		await page.click( '[data-yn-mep-enregistrer]' );
		verifier( 409 === ( await envoiPerime ).status(), 'base périmée : 409' );
		await page.waitForSelector( '[data-yn-mep-alerte]:not([hidden])' );
		const message = texte( await page.textContent( '[data-yn-mep-alerte]' ) );
		verifier( /Quelqu’un a enregistré ce chapitre entre-temps \(.+, \d+ h \d\d\) : rechargez pour voir sa version/.test( message ), 'message de conflit', message );
		await capture( page, 'conflit' );
		await page.click( '[data-yn-mep-alerte] button' );
		await page.waitForSelector( '[data-yn-mep-alerte][hidden]', { state: 'attached' } );
		await page.waitForFunction( ( sel ) => 'R' === document.querySelector( sel + ' .yn-mep__lettre' ).textContent.trim(), UNITES );
		verifier( 'R' === ( await lettres( page ) )[ 0 ], 'Recharger : version de l’autre onglet affichée' );
		verifier( 'Aucune modification' === texte( await page.textContent( '[data-yn-mep-compteur]' ) ), 'Recharger : modifications locales abandonnées' );

		console.log( '[mise-en-page] mobile' );
		await page.setViewportSize( { width: 390, height: 844 } );
		await ouvrirVue( page, options.vue );
		const palette = await page.$eval( '[data-yn-mep-palette]', ( p ) => ( { position: getComputedStyle( p ).position, bas: Math.round( window.innerHeight - p.getBoundingClientRect().bottom ) } ) );
		verifier( 'fixed' === palette.position && palette.bas <= 1, 'mobile : palette fixée en bas', palette );
		// Barre d'administration de WordPress exclue (son nom d'utilisateur déborde d'un pixel partout).
		const debordement = await page.evaluate( () => Array.from( document.querySelectorAll( '.yn-team *' ) ).filter( ( e ) => ! e.closest( '[data-yn-mep-palette]' ) && e.getBoundingClientRect().right > window.innerWidth + 1 ).map( ( e ) => e.className ) );
		verifier( 0 === debordement.length, 'mobile : rien ne déborde de l’écran (hors palette défilante)', debordement );
		await capture( page, 'mobile' );
		verifier( 0 === erreursConsole.length, 'aucune erreur JavaScript', erreursConsole );
	} finally {
		await navigateur.close();
	}
	console.log( `[mise-en-page] ${ controles - ecarts.length } contrôle(s) réussi(s), ${ ecarts.length } écart(s)` );
	return ecarts.length ? 1 : 0;
}

principal().then( ( code ) => process.exit( code ) ).catch( ( e ) => {
	console.error( `[mise-en-page] erreur technique : ${ e && e.stack ? e.stack : e }` );
	process.exit( 2 );
} );
