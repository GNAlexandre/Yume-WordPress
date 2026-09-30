/**
 * Vérification du lot D de WordEnd v2 (entrées, écrans, modale, jeu.css) sur le banc d'essai.
 *
 *   php -S 127.0.0.1:8084 -t <racine du dépôt>
 *   node tools/wordend/banc/verifier-D.js [--port=8084] [--captures=<dossier>]
 *
 * Parcours : ynWordEnd.ouvrir() → titre → Entrée → personnage → Entrée → niveaux → Entrée → jeu ;
 * listes <select> de la modale ↔ écrans ; manettes (Saut) et aide ; partie arcade jusqu'à la fin,
 * rejouer, victoire (étoiles), R → niveaux ; Tab piégé ; Échap ×2 ferme et rend le focus ;
 * axe-core sans violation serious/critical ; thèmes nuit, papier, sépia (captures banc-D-*.png) ;
 * tactile (manettes), mouvement réduit, écran univers (deux univers). Aucune erreur console
 * (les 404 des fichiers listés par le manifeste mais pas encore créés par les lots B/C sont
 * tolérés tant qu'ils sont réellement absents du disque).
 *
 * Code de sortie 1 au premier échec.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( '/opt/node22/lib/node_modules/playwright' );

const RACINE = path.resolve( __dirname, '../../..' );
const argument = ( nom, defaut ) => {
	const a = process.argv.find( ( x ) => x.startsWith( '--' + nom + '=' ) );
	return a ? a.slice( nom.length + 3 ) : defaut;
};
const PORT = argument( 'port', '8084' );
const CAPTURES = argument( 'captures', path.join( require( 'os' ).tmpdir(), 'wordend-D' ) );
const BASE = 'http://127.0.0.1:' + PORT;
const BANC = BASE + '/tools/wordend/banc/?univers=sukasuka';
const AXE = argument( 'axe', '/home/user/Yume-WordPress/tools/ci/node_modules/axe-core/axe.min.js' );

fs.mkdirSync( CAPTURES, { recursive: true } );

let echecs = 0;
function ok( condition, message ) {
	if ( condition ) {
		console.log( '  ok   ' + message );
	} else {
		console.log( '  ÉCHEC ' + message );
		echecs++;
	}
}

/* Surveille les erreurs de la page ; les 404 de fichiers absents du disque sont tolérés. */
function surveiller( page ) {
	const erreurs = [];
	let absentsTolérés = 0;
	page.on( 'pageerror', ( e ) => erreurs.push( 'exception : ' + e.message ) );
	page.on( 'response', ( reponse ) => {
		if ( reponse.status() === 404 ) {
			const chemin = decodeURIComponent( new URL( reponse.url() ).pathname );
			if ( chemin.includes( '/assets/univers/' ) && ! fs.existsSync( path.join( RACINE, chemin ) ) ) {
				absentsTolérés++;
			} else {
				erreurs.push( '404 : ' + chemin );
			}
		}
	} );
	page.on( 'console', ( m ) => {
		if ( m.type() !== 'error' ) {
			return;
		}
		if ( /Failed to load resource: .*404/.test( m.text() ) && absentsTolérés > 0 ) {
			absentsTolérés--;
			return;
		}
		erreurs.push( 'console : ' + m.text() );
	} );
	return erreurs;
}

const etat = ( page ) => page.evaluate( () => window.ynWordEndMoteur && window.ynWordEndMoteur.debug ? window.ynWordEndMoteur.debug.etat() : 'absent' );

async function attendreEtat( page, attendu, delai ) {
	try {
		await page.waitForFunction( ( e ) => window.ynWordEndMoteur && window.ynWordEndMoteur.debug.etat() === e, attendu, { timeout: delai || 8000 } );
		return true;
	} catch ( e ) {
		return false;
	}
}

async function capture( page, nom ) {
	await page.locator( 'dialog.yn-wordend' ).screenshot( { path: path.join( CAPTURES, 'banc-D-' + nom + '.png' ) } );
}

async function axe( page, contexte ) {
	await page.addScriptTag( { path: AXE } );
	const violations = await page.evaluate( async () => {
		const r = await window.axe.run( document.querySelector( 'dialog.yn-wordend' ), { resultTypes: [ 'violations' ] } );
		return r.violations.map( ( v ) => ( { id: v.id, impact: v.impact, n: v.nodes.length, cible: v.nodes.map( ( n ) => n.target.join( ' ' ) ).slice( 0, 3 ) } ) );
	} );
	const graves = violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
	ok( graves.length === 0, 'axe (' + contexte + ') : aucune violation serious/critical' + ( graves.length ? ' → ' + JSON.stringify( graves ) : '' ) );
	if ( violations.length > graves.length ) {
		console.log( '       (mineures : ' + JSON.stringify( violations.filter( ( v ) => graves.indexOf( v ) === -1 ) ) + ')' );
	}
}

async function theme( page, nom ) {
	await page.evaluate( ( t ) => {
		document.documentElement.setAttribute( 'data-yn-theme', t );
		document.dispatchEvent( new CustomEvent( 'yn:theme', { detail: { theme: t } } ) );
	}, nom );
	await page.waitForTimeout( 150 );
}

async function parcoursPrincipal( navigateur ) {
	console.log( 'Parcours principal (clavier, nuit/papier/sépia)' );
	const contexte = await navigateur.newContext( { viewport: { width: 1100, height: 900 } } );
	const page = await contexte.newPage();
	const erreurs = surveiller( page );
	await page.goto( BANC );
	await page.addStyleTag( { url: BASE + '/wp-content/themes/yume/assets/css/yume.css' } );
	await page.evaluate( () => window.localStorage.removeItem( 'yn.wordend' ) );
	await page.waitForSelector( '#banc-ouvrir' );
	await page.waitForFunction( () => window.ynWordEnd && typeof window.ynWordEnd.ouvrir === 'function' );
	await page.focus( '#banc-ouvrir' );
	await page.evaluate( () => window.ynWordEnd.ouvrir() );
	ok( await attendreEtat( page, 'titre' ), 'ouverture → écran titre' );
	ok( await page.locator( 'dialog.yn-wordend[open]' ).count() === 1, 'dialog.yn-wordend[open]' );
	ok( await page.evaluate( () => document.activeElement && document.activeElement.classList.contains( 'yn-wordend__ecran' ) ), 'focus sur le canvas' );
	await page.waitForTimeout( 300 );
	await capture( page, 'titre-nuit' );

	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'personnage' ), 'Entrée → écran personnage' );
	await page.waitForFunction( () => document.querySelector( 'dialog select[name=personnage]' ).options.length > 0 );
	await page.waitForTimeout( 500 );
	const persos = await page.$$eval( 'dialog select[name=personnage] option', ( o ) => o.map( ( x ) => x.value ) );
	ok( persos.indexOf( 'chtholly' ) !== -1, 'liste Personnage : ' + persos.join( ', ' ) );
	await capture( page, 'personnage-nuit' );
	await page.keyboard.press( 'ArrowRight' );
	await page.keyboard.press( 'ArrowLeft' );
	ok( await etat( page ) === 'personnage', 'flèches : reste sur l’écran personnage' );

	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'niveaux' ), 'Entrée → écran niveaux' );
	await page.waitForTimeout( 400 );
	const niveaux = await page.$$eval( 'dialog select[name=niveau] option', ( o ) => o.map( ( x ) => x.value + ( x.disabled ? '(verrou)' : '' ) ) );
	ok( niveaux[ 0 ] === 'arcade', 'liste Niveau (Arcade en premier) : ' + niveaux.join( ', ' ) );
	await capture( page, 'niveaux-nuit' );

	// Listes DOM ↔ écrans.
	await page.selectOption( 'dialog select[name=personnage]', 'chtholly' );
	ok( await attendreEtat( page, 'personnage', 2000 ), 'select[name=personnage] → écran personnage' );
	await page.selectOption( 'dialog select[name=niveau]', 'arcade' );
	ok( await attendreEtat( page, 'niveaux', 2000 ), 'select[name=niveau] → écran niveaux' );
	ok( await page.evaluate( () => ! document.querySelector( 'dialog select[name=niveau]' ).disabled ), 'listes actives hors partie' );

	await page.focus( 'dialog .yn-wordend__ecran' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'jeu' ), 'Entrée → jeu (arcade)' );
	ok( await page.evaluate( () => document.querySelector( 'dialog select[name=niveau]' ).disabled ), 'listes désactivées en jeu' );
	ok( await page.locator( 'dialog .yn-wordend__manette[data-commande=saut][aria-label="Sauter"]' ).count() === 1, 'manette « Saut » présente' );
	ok( /sauter/.test( await page.textContent( '#yn-wordend-aide' ) ), 'aide : saut mentionné' );

	// Un peu de jeu : déplacements, saut, épée, charge.
	await page.keyboard.down( 'ArrowRight' );
	await page.waitForTimeout( 500 );
	await page.keyboard.up( 'ArrowRight' );
	await page.keyboard.press( 'ArrowUp' );
	await page.keyboard.press( 'Space' );
	for ( let i = 0; i < 4; i++ ) {
		await page.keyboard.press( 'KeyJ' );
		await page.waitForTimeout( 250 );
	}
	await page.keyboard.down( 'KeyK' );
	await page.waitForTimeout( 700 );
	await page.keyboard.up( 'KeyK' );
	await page.waitForTimeout( 3500 );
	ok( await etat( page ) === 'jeu', '5 s de jeu sans quitter l’état jeu' );
	await capture( page, 'jeu-nuit' );

	// Pause / reprise (P, Entrée).
	await page.keyboard.press( 'p' );
	ok( await etat( page ) === 'pause', 'P → pause' );
	await page.keyboard.press( 'Enter' );
	ok( await etat( page ) === 'jeu', 'Entrée → reprise' );

	// Fin de partie : le joueur est mis à terre.
	await page.evaluate( () => {
		const m = window.ynWordEndMoteur.debug.monde();
		m.joueur.invincible = 0;
		m.joueur.blesser( 1, m.joueur.pv, m );
	} );
	ok( await attendreEtat( page, 'fin', 8000 ), 'joueur à terre → écran fin' );
	await page.waitForTimeout( 300 );
	await capture( page, 'fin-nuit' );
	const prog = await page.evaluate( () => window.ynWordEndMoteur.stockage.progression( 'sukasuka' ) );
	ok( prog.parties >= 1, 'partie enregistrée (parties = ' + prog.parties + ')' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'jeu' ), 'Entrée sur fin → rejouer' );

	// Victoire simulée (étoiles) puis Entrée → niveaux (arcade n'a pas de suivant).
	await page.evaluate( () => window.ynWordEndMoteur.evenements.emettre( 'niveau:fin', { resultat: 'gagne', score: 420, etoiles: 2, temps: 50, pv: 4, niveau: 'arcade' } ) );
	ok( await attendreEtat( page, 'victoire' ), 'niveau:fin gagné → victoire' );
	await page.waitForTimeout( 1400 );
	await capture( page, 'victoire-nuit' );
	const annonceArcade = await page.textContent( 'dialog .yn-visually-hidden[role=status]' );
	ok( /Victoire/.test( annonceArcade ) && ! /débloqué|Nouveau personnage/.test( annonceArcade ), 'arcade gagnée : aucun déblocage annoncé' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'niveaux' ), 'Entrée sur victoire (sans suivant) → niveaux' );

	// Victoire sur 01-plage : Nephren et « Les dunes » débloqués, affichés et annoncés.
	await page.selectOption( 'dialog select[name=niveau]', '01-plage' );
	await page.focus( 'dialog .yn-wordend__ecran' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'jeu' ), '01-plage lancé' );
	await page.evaluate( () => window.ynWordEndMoteur.evenements.emettre( 'niveau:fin', { resultat: 'gagne', score: 300, etoiles: 2, temps: 60, pv: 3, niveau: '01-plage' } ) );
	ok( await attendreEtat( page, 'victoire' ), '01-plage gagné → victoire' );
	await page.waitForTimeout( 1400 );
	const annonce = await page.textContent( 'dialog .yn-visually-hidden[role=status]' );
	ok( /Nouveau personnage : Nephren/.test( annonce ) && /Niveau débloqué : Les dunes/.test( annonce ), 'déblocages annoncés : « ' + annonce + ' »' );
	await capture( page, 'victoire-deblocage-nuit' );
	const nephren = await page.$$eval( 'dialog select[name=personnage] option', ( o ) => o.filter( ( x ) => x.value === 'nephren' ).map( ( x ) => x.textContent ) );
	ok( nephren.length === 1 && ! /verrouill/.test( nephren[ 0 ] ), 'Nephren débloquée dans la liste : ' + nephren[ 0 ] );
	await page.keyboard.press( 'r' );
	ok( await attendreEtat( page, 'niveaux' ), 'R sur victoire → niveaux' );
	await page.selectOption( 'dialog select[name=niveau]', 'arcade' );
	await page.focus( 'dialog .yn-wordend__ecran' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'jeu' ), 'niveaux → jeu' );
	await page.keyboard.press( 'Escape' );
	await page.keyboard.press( 'r' );
	ok( await attendreEtat( page, 'niveaux' ), 'pause puis R → niveaux' );

	// Tab reste dans la modale.
	let dedans = true;
	for ( let i = 0; i < 24; i++ ) {
		await page.keyboard.press( i % 5 === 4 ? 'Shift+Tab' : 'Tab' );
		dedans = dedans && await page.evaluate( () => !! document.activeElement && !! document.activeElement.closest( 'dialog.yn-wordend' ) );
	}
	ok( dedans, 'Tab / Maj+Tab restent dans la modale' );

	// Thèmes : captures et axe.
	await axe( page, 'nuit, niveaux' );
	for ( const t of [ 'papier', 'sepia' ] ) {
		await theme( page, t );
		await page.focus( 'dialog .yn-wordend__ecran' );
		await capture( page, 'niveaux-' + t );
		await page.keyboard.press( 'Enter' );
		await attendreEtat( page, 'jeu' );
		await page.waitForTimeout( 1500 );
		await capture( page, 'jeu-' + t );
		await axe( page, t + ', jeu' );
		await page.keyboard.press( 'Escape' );
		await page.keyboard.press( 'r' );
		await attendreEtat( page, 'niveaux' );
	}
	await theme( page, 'nuit' );

	// Échap ×2 depuis le jeu : pause puis fermeture, focus rendu.
	await page.focus( 'dialog .yn-wordend__ecran' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'jeu' ), 'relance du jeu' );
	await page.keyboard.press( 'Escape' );
	ok( await etat( page ) === 'pause', 'Échap → pause' );
	await page.keyboard.press( 'Escape' );
	ok( await etat( page ) === 'ferme', 'Échap → fermé' );
	ok( await page.locator( 'dialog.yn-wordend[open]' ).count() === 0, 'dialog fermé' );
	ok( await page.evaluate( () => document.activeElement && document.activeElement.id === 'banc-ouvrir' ), 'focus rendu au bouton d’ouverture' );

	// Réouverture : écran titre.
	await page.evaluate( () => window.ynWordEnd.ouvrir() );
	ok( await attendreEtat( page, 'titre' ), 'réouverture → titre' );
	await page.keyboard.press( 'Escape' );
	ok( await etat( page ) === 'ferme', 'Échap sur le titre → fermé' );

	ok( erreurs.length === 0, 'aucune erreur console' + ( erreurs.length ? ' → ' + erreurs.join( ' | ' ) : '' ) );
	await contexte.close();
}

async function parcoursTactile( navigateur ) {
	console.log( 'Tactile (manettes) et mouvement réduit' );
	const contexte = await navigateur.newContext( { viewport: { width: 740, height: 400 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' } );
	const page = await contexte.newPage();
	const erreurs = surveiller( page );
	await page.goto( BANC );
	await page.addStyleTag( { url: BASE + '/wp-content/themes/yume/assets/css/yume.css' } );
	await page.waitForFunction( () => window.ynWordEnd && typeof window.ynWordEnd.ouvrir === 'function' );
	await page.evaluate( () => window.ynWordEnd.ouvrir() );
	ok( await attendreEtat( page, 'titre' ), 'titre' );
	ok( await page.locator( 'dialog .yn-wordend__manette[data-commande=saut]' ).isVisible(), 'manette Saut visible (pointeur grossier)' );
	const groupes = await page.$$eval( 'dialog .yn-wordend__manettes-groupe', ( g ) => g.map( ( x ) => Array.from( x.children ).map( ( b ) => b.dataset.commande ).join( '+' ) ) );
	ok( groupes.length === 2 && /gauche\+droite\+bas\+courir/.test( groupes[ 0 ] ) && /saut\+epee\+competence/.test( groupes[ 1 ] ), 'groupes (▼ Bas à gauche) : ' + groupes.join( ' / ' ) );
	const tailles = await page.$$eval( 'dialog .yn-wordend__manette', ( b ) => b.map( ( x ) => Math.min( x.getBoundingClientRect().width, x.getBoundingClientRect().height ) ) );
	ok( Math.min.apply( null, tailles ) >= 44, 'boutons tactiles ≥ 44 px (min ' + Math.round( Math.min.apply( null, tailles ) ) + ')' );
	await page.tap( 'dialog .yn-wordend__manette[data-commande=saut]' );
	ok( await attendreEtat( page, 'personnage', 3000 ), 'appui tactile sur le titre → personnage' );
	await page.tap( 'dialog .yn-wordend__manette[data-commande=epee]' );
	ok( await attendreEtat( page, 'niveaux', 3000 ), 'appui Épée → niveaux' );
	// Toucher la carte Arcade (déjà choisie) : lance le niveau.
	await page.locator( 'dialog .yn-wordend__ecran' ).scrollIntoViewIfNeeded();
	const boite = await page.locator( 'dialog .yn-wordend__ecran' ).boundingBox();
	const hauteurVue = page.viewportSize().height;
	ok( boite.y >= 0 && boite.y + boite.height <= hauteurVue, 'paysage 740×400 : écran de jeu entièrement visible' );
	const manettes = await page.locator( 'dialog .yn-wordend__manettes' ).boundingBox();
	ok( manettes.y + manettes.height <= hauteurVue + 1, 'paysage 740×400 : manettes visibles avec l’écran' );
	await page.touchscreen.tap( boite.x + boite.width * ( 88 / 480 ), boite.y + boite.height * ( 80 / 270 ) );
	ok( await attendreEtat( page, 'jeu', 3000 ), 'toucher la carte Arcade → jeu' );
	ok( await page.evaluate( () => window.ynWordEndMoteur.debug.monde().mouvementReduit === true ), 'mouvement réduit transmis au monde' );
	await page.evaluate( () => window.ynWordEndMoteur.evenements.emettre( 'niveau:fin', { resultat: 'gagne', score: 900, etoiles: 3, temps: 40, pv: 5, niveau: 'arcade' } ) );
	await attendreEtat( page, 'victoire' );
	await page.waitForTimeout( 100 );
	await capture( page, 'victoire-reduit-tactile' );
	await axe( page, 'tactile' );
	ok( erreurs.length === 0, 'aucune erreur console' + ( erreurs.length ? ' → ' + erreurs.join( ' | ' ) : '' ) );
	await contexte.close();
}

async function parcoursUnivers( navigateur ) {
	console.log( 'Écran univers (deux univers configurés)' );
	const contexte = await navigateur.newContext( { viewport: { width: 1100, height: 900 } } );
	const page = await contexte.newPage();
	const erreurs = surveiller( page );
	await page.goto( BANC );
	await page.waitForFunction( () => window.ynWordEnd && typeof window.ynWordEnd.ouvrir === 'function' );
	await page.evaluate( () => {
		const u = window.ynWordEnd.univers.sukasuka;
		window.ynWordEnd.univers.copie = { titre: 'Copie de test', oeuvre: 'copie', manifeste: u.manifeste, version: u.version + '-copie' };
		window.ynWordEnd.univers.sukasuka.titre = 'WordEnd';
		window.ynWordEnd.ouvrir();
	} );
	ok( await attendreEtat( page, 'titre' ), 'titre' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'univers' ), 'Entrée → écran univers' );
	ok( await page.locator( 'dialog select[name=univers]' ).isVisible(), 'liste Univers visible' );
	await page.waitForTimeout( 200 );
	await capture( page, 'univers-nuit' );
	await page.keyboard.press( 'ArrowRight' );
	await page.keyboard.press( 'Enter' );
	ok( await attendreEtat( page, 'personnage', 8000 ), 'univers « copie » chargé → personnage' );
	ok( erreurs.length === 0, 'aucune erreur console' + ( erreurs.length ? ' → ' + erreurs.join( ' | ' ) : '' ) );
	await contexte.close();
}

( async () => {
	const navigateur = await chromium.launch();
	try {
		await parcoursPrincipal( navigateur );
		await parcoursTactile( navigateur );
		await parcoursUnivers( navigateur );
	} catch ( e ) {
		console.log( '  ÉCHEC exception : ' + ( e && e.stack || e ) );
		echecs++;
	}
	await navigateur.close();
	console.log( echecs ? echecs + ' échec(s).' : 'Lot D : tout est vert. Captures : ' + CAPTURES );
	process.exit( echecs ? 1 : 0 );
} )();
