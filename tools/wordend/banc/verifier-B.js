/**
 * WordEnd — vérifications du lot B (physique, joueur, compétences, personnages) sur le banc.
 *
 *   php -S 127.0.0.1:8082 -t <racine du dépôt>
 *   node tools/wordend/banc/verifier-B.js [adresse du banc] [dossier des captures]
 *
 * Deux familles de contrôles :
 * - partie réelle (arcade, clavier simulé) : vitesses v1, épée, onde, saut, Ithea qui tire ;
 * - monde factice (ynWordEndMoteur.creerMonde + joueur.creer, entrées simulées, pas fixes) :
 *   plateformes, traversée, saut écourté, tolérance de bord, double saut, projectile, ruée, parade.
 * Code de sortie 1 si un contrôle échoue ou si la console signale une erreur.
 */
'use strict';

const { chromium } = require( '/opt/node22/lib/node_modules/playwright' );
const path = require( 'path' );

const BASE = process.argv[ 2 ] || 'http://127.0.0.1:8082/tools/wordend/banc/';
const CAPTURES = process.argv[ 3 ] || process.cwd();
const pause = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

let echecs = 0;
function verifier( nom, condition, detail ) {
	if ( condition ) {
		console.log( '  ok   ' + nom );
	} else {
		echecs++;
		console.log( '  ÉCHEC ' + nom + ( detail !== undefined ? ' → ' + JSON.stringify( detail ) : '' ) );
	}
}

/* Ouvre le banc et lance la partie (Entrée sur l'écran titre). */
async function ouvrirPartie( page, personnage ) {
	await page.goto( BASE + '?univers=sukasuka&niveau=arcade&personnage=' + personnage + '&auto=1' );
	await page.waitForSelector( 'dialog.yn-wordend[open]' );
	await page.waitForFunction( () => window.ynWordEndMoteur.debug.etat() === 'titre' );
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( ( p ) => {
		const m = window.ynWordEndMoteur.debug.monde();
		return window.ynWordEndMoteur.debug.etat() === 'jeu' && m && m.personnage && m.personnage.slug === p;
	}, personnage );
	await page.focus( 'canvas.yn-wordend__ecran' );
}

const joueur = ( page ) => page.evaluate( () => {
	const j = window.ynWordEndMoteur.debug.monde().joueur;
	return { etat: j.etat, x: j.x, y: j.y, vx: j.vx, vy: j.vy, auSol: j.auSol, phase: j.phase, pv: j.pv };
} );

/*
 * Outils injectés dans la page : monde factice et pas de simulation.
 * Exécuté dans le navigateur (après ouverture du jeu, pour disposer de l'univers chargé).
 */
function installerOutils() {
	const ynWE = window.ynWordEndMoteur;
	const univers = ynWE.debug.monde().univers;
	window.banc = {
		univers: univers,
		entrees: function () {
			const maintenu = {};
			const impulsions = {};
			return {
				maintenu: maintenu,
				appuyer: function ( nom ) {
					maintenu[ nom ] = true;
					impulsions[ nom ] = true;
				},
				relacher: function ( nom ) {
					maintenu[ nom ] = false;
				},
				commande: function ( nom ) {
					return !! maintenu[ nom ];
				},
				impulsion: function ( nom ) {
					const v = !! impulsions[ nom ];
					impulsions[ nom ] = false;
					return v;
				},
				courirTactile: false,
			};
		},
		monde: function ( slugPersonnage, niveau ) {
			const personnage = univers.personnages[ slugPersonnage ];
			const monde = ynWE.creerMonde( univers, Object.assign( { largeur: 480, sol: 238, gravite: 900, plateformes: [] }, niveau || {} ), personnage );
			monde.palette = { accent: '#f3a6c8', texteFaible: '#b7a9cc' };
			const entrees = window.banc.entrees();
			monde.joueur = ynWE.joueur.creer( personnage, monde, entrees );
			return { monde: monde, j: monde.joueur, in: entrees };
		},
		/* n pas de DT : joueur, ennemis, ondes et projectiles du joueur. */
		pas: function ( s, n ) {
			for ( let k = 0; k < n; k++ ) {
				s.j.mettreAJour( ynWE.DT, s.monde );
				s.monde.ennemis.forEach( ( e ) => ynWE.ennemis.mettreAJour( e, ynWE.DT, s.monde ) );
				ynWE.competences.mettreAJourOndes( s.monde, ynWE.DT );
				s.monde.projectiles = s.monde.projectiles.filter( ( p ) => p.camp !== 'joueur' || ynWE.competences.projectile.avancer( p, ynWE.DT, s.monde ) );
			}
		},
	};
}

/*
 * Tant que ennemis.mettreAJourProjectiles est le stub du lot 0 (qui lève dès qu'un projectile
 * existe), le banc le remplace pour les projectiles du joueur : le lot C le remplacera.
 */
function remplacerStubProjectiles() {
	const ynWE = window.ynWordEndMoteur;
	if ( String( ynWE.ennemis.mettreAJourProjectiles ).indexOf( 'non implémenté' ) === -1 ) {
		return false;
	}
	ynWE.ennemis.mettreAJourProjectiles = function ( monde, dt ) {
		monde.projectiles = monde.projectiles.filter( ( p ) => p.camp === 'joueur' && ynWE.competences.projectile.avancer( p, dt, monde ) );
	};
	ynWE.ennemis.dessinerProjectiles = function ( r, monde ) {
		monde.projectiles.forEach( ( p ) => ynWE.competences.projectile.dessinerTir( r, p ) );
	};
	return true;
}

( async () => {
	const nav = await chromium.launch();
	const contexte = await nav.newContext( { viewport: { width: 1200, height: 900 }, reducedMotion: 'no-preference' } );
	const page = await contexte.newPage();
	const erreurs = [];
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			erreurs.push( m.text() );
		}
	} );
	page.on( 'pageerror', ( e ) => erreurs.push( 'pageerror: ' + e.message ) );

	/* ---------------------------------------------------------------- */
	console.log( 'Arcade, Chtholly (partie réelle)' );
	await ouvrirPartie( page, 'chtholly' );
	let j = await joueur( page );
	verifier( 'départ au sol (y = 238, repos)', j.y === 238 && j.auSol && j.etat === 'repos', j );

	await page.keyboard.down( 'ArrowRight' );
	await pause( 200 );
	j = await joueur( page );
	verifier( 'marche à 72 px/s', j.etat === 'marche' && j.vx === 72, j );
	await page.keyboard.down( 'ShiftLeft' );
	await pause( 200 );
	j = await joueur( page );
	verifier( 'course à 138 px/s', j.etat === 'course' && j.vx === 138, j );
	await page.keyboard.up( 'ShiftLeft' );
	await page.keyboard.up( 'ArrowRight' );
	await pause( 100 );
	j = await joueur( page );
	verifier( 'arrêt : repos, y = 238', j.etat === 'repos' && j.vx === 0 && j.y === 238, j );

	await page.keyboard.press( 'KeyJ' );
	await pause( 40 );
	j = await joueur( page );
	verifier( 'J : attaque (épée)', j.etat === 'attaque', j );
	await pause( 400 );

	await page.keyboard.down( 'KeyK' );
	await pause( 750 );
	j = await joueur( page );
	verifier( 'K maintenu : concentration', j.etat === 'competence' && j.phase === 'concentration', j );
	await page.keyboard.up( 'KeyK' );
	await pause( 50 );
	const onde = await page.evaluate( () => {
		const m = window.ynWordEndMoteur.debug.monde();
		return { ondes: m.ondes.length, recharge: m.joueur.recharges.secondaire, degats: m.ondes[ 0 ] && m.ondes[ 0 ].degats };
	} );
	verifier( 'K relâché : onde (dégâts 3, recharge posée)', onde.ondes === 1 && onde.degats === 3 && onde.recharge > 1, onde );
	await pause( 500 );

	// Saut : ↑ maintenu → saut, puis chute, puis retour au sol ; hauteur ≈ 330² / (2 × 900) ≈ 60 px.
	await page.evaluate( () => {
		const j = window.ynWordEndMoteur.debug.monde().joueur;
		window.suiviSaut = { etats: [], yMin: j.y };
		( function suivre() {
			const k = window.ynWordEndMoteur.debug.monde().joueur;
			if ( window.suiviSaut.etats[ window.suiviSaut.etats.length - 1 ] !== k.etat ) {
				window.suiviSaut.etats.push( k.etat );
			}
			window.suiviSaut.yMin = Math.min( window.suiviSaut.yMin, k.y );
			if ( window.suiviSaut.etats.length < 6 ) {
				window.requestAnimationFrame( suivre );
			}
		}() );
	} );
	await page.keyboard.down( 'ArrowUp' );
	await pause( 120 );
	j = await joueur( page );
	verifier( '↑ : état saut, en l’air', j.etat === 'saut' && ! j.auSol && j.y < 238, j );
	await page.screenshot( { path: path.join( CAPTURES, 'banc-B-saut.png' ), clip: await page.locator( 'canvas.yn-wordend__ecran' ).boundingBox() } );
	await pause( 400 );
	await page.keyboard.up( 'ArrowUp' );
	await page.waitForFunction( () => window.ynWordEndMoteur.debug.monde().joueur.auSol, null, { timeout: 3000 } );
	const suivi = await page.evaluate( () => window.suiviSaut );
	j = await joueur( page );
	verifier( 'saut → chute → sol', suivi.etats.join( ',' ).indexOf( 'saut,chute,repos' ) !== -1 && j.y === 238, suivi );
	verifier( 'hauteur du saut ≈ 60 px', 238 - suivi.yMin > 55 && 238 - suivi.yMin < 62, 238 - suivi.yMin );

	// Plateforme ajoutée au monde réel (capture) : saut dessus depuis x = 248.
	await page.evaluate( () => {
		const m = window.ynWordEndMoteur.debug.monde();
		m.plateformes = [ { x: 200, y: 180, l: 96 } ];
		m.joueur.x = 248;
		m.joueur.vx = 0;
	} );
	await page.keyboard.down( 'ArrowUp' );
	await pause( 700 );
	await page.keyboard.up( 'ArrowUp' );
	j = await joueur( page );
	verifier( 'partie réelle : atterrit sur la plateforme (y = 180)', j.y === 180 && j.auSol, j );
	await page.keyboard.press( 'KeyJ' );
	await pause( 90 );
	await page.screenshot( { path: path.join( CAPTURES, 'banc-B-plateforme.png' ), clip: await page.locator( 'canvas.yn-wordend__ecran' ).boundingBox() } );
	await pause( 400 );
	await page.keyboard.down( 'ArrowDown' );
	await page.keyboard.down( 'ArrowUp' );
	await pause( 60 );
	await page.keyboard.up( 'ArrowUp' );
	await page.keyboard.up( 'ArrowDown' );
	await page.waitForFunction( () => window.ynWordEndMoteur.debug.monde().joueur.y === 238, null, { timeout: 3000 } ).catch( () => {} );
	j = await joueur( page );
	verifier( 'partie réelle : ↓ + ↑ traverse la plateforme', j.y === 238 && j.auSol, j );

	/* ---------------------------------------------------------------- */
	console.log( 'Monde factice (pas fixes)' );
	await page.evaluate( installerOutils );
	await page.evaluate( () => Promise.all( [
		window.ynWordEndMoteur.ressources.chargerPersonnage( window.banc.univers, 'nephren' ),
		window.ynWordEndMoteur.ressources.chargerPersonnage( window.banc.univers, 'ithea' ),
	] ) );
	const factice = await page.evaluate( () => {
		const b = window.banc;
		const ynWE = window.ynWordEndMoteur;
		const r = {};
		const PLATEFORME = { x: 200, y: 180, l: 96 };

		// Arcade : sol plat, aucun déplacement vertical au repos.
		let s = b.monde( 'chtholly' );
		b.pas( s, 30 );
		r.sol = { y: s.j.y, vy: s.j.vy, auSol: s.j.auSol, etat: s.j.etat };

		// Saut sur la plateforme depuis x = 248 (touche maintenue).
		s = b.monde( 'chtholly', { plateformes: [ PLATEFORME ] } );
		s.j.x = 248;
		b.pas( s, 1 );
		s.in.appuyer( 'saut' );
		let etats = [];
		for ( let k = 0; k < 90; k++ ) {
			b.pas( s, 1 );
			if ( etats[ etats.length - 1 ] !== s.j.etat ) {
				etats.push( s.j.etat );
			}
		}
		s.in.relacher( 'saut' );
		r.plateforme = { y: s.j.y, auSol: s.j.auSol, support: s.j.support === s.monde.plateformes[ 0 ], etats: etats };

		// Marche sur la plateforme puis chute par le bord.
		s.in.appuyer( 'droite' );
		b.pas( s, 40 );
		r.bord = { x: Math.round( s.j.x ), y: s.j.y, etat: s.j.etat };
		b.pas( s, 60 );
		s.in.relacher( 'droite' );
		b.pas( s, 30 );
		r.apresBord = { y: s.j.y, auSol: s.j.auSol };

		// Bas + saut : traverse la plateforme.
		s = b.monde( 'chtholly', { plateformes: [ PLATEFORME ] } );
		s.j.x = 248;
		s.j.y = 180;
		b.pas( s, 2 );
		const surPlateforme = s.j.y === 180 && s.j.auSol;
		s.in.appuyer( 'bas' );
		s.in.appuyer( 'saut' );
		b.pas( s, 1 );
		s.in.relacher( 'saut' );
		b.pas( s, 60 );
		s.in.relacher( 'bas' );
		r.traverse = { surPlateforme: surPlateforme, y: s.j.y, auSol: s.j.auSol };

		// Plateforme solide : pas de traversée, la tête bute par-dessous.
		s = b.monde( 'chtholly', { plateformes: [ { x: 200, y: 180, l: 96, type: 'solide' } ] } );
		s.j.x = 248;
		s.j.y = 180;
		b.pas( s, 2 );
		s.in.appuyer( 'bas' );
		s.in.appuyer( 'saut' );
		b.pas( s, 1 );
		s.in.relacher( 'saut' );
		s.in.relacher( 'bas' );
		b.pas( s, 60 );
		const resteDessus = s.j.y === 180;
		s = b.monde( 'chtholly', { plateformes: [ { x: 200, y: 150, l: 96, type: 'solide' } ] } );
		s.j.x = 248;
		b.pas( s, 1 );
		s.in.appuyer( 'saut' );
		let yMin = 238;
		for ( let k = 0; k < 60; k++ ) {
			b.pas( s, 1 );
			yMin = Math.min( yMin, s.j.y );
		}
		r.solide = { resteDessus: resteDessus, yMin: yMin, attendu: 150 + ynWE.physique.EPAISSEUR + s.j.h, yFinal: s.j.y };

		// Saut écourté : touche relâchée après 3 pas.
		s = b.monde( 'chtholly' );
		b.pas( s, 1 );
		s.in.appuyer( 'saut' );
		b.pas( s, 3 );
		s.in.relacher( 'saut' );
		yMin = 238;
		for ( let k = 0; k < 60; k++ ) {
			b.pas( s, 1 );
			yMin = Math.min( yMin, s.j.y );
		}
		r.ecourte = 238 - yMin;

		// Tolérance de bord : saut 3 pas après avoir quitté la plateforme.
		s = b.monde( 'chtholly', { plateformes: [ PLATEFORME ] } );
		s.j.x = 294;
		s.j.y = 180;
		b.pas( s, 2 );
		s.in.appuyer( 'droite' );
		let pasHors = 0;
		for ( let k = 0; k < 20 && pasHors < 3; k++ ) {
			b.pas( s, 1 );
			if ( ! s.j.auSol ) {
				pasHors++;
			}
		}
		s.in.appuyer( 'saut' );
		b.pas( s, 1 );
		r.coyote = { vy: s.j.vy, etat: s.j.etat };
		// Trop tard (0,25 s) : pas de saut pour un personnage à un seul saut.
		s = b.monde( 'chtholly', { plateformes: [ PLATEFORME ] } );
		s.j.x = 294;
		s.j.y = 180;
		b.pas( s, 2 );
		s.in.appuyer( 'droite' );
		pasHors = 0;
		for ( let k = 0; k < 40 && pasHors < 15; k++ ) {
			b.pas( s, 1 );
			if ( ! s.j.auSol ) {
				pasHors++;
			}
		}
		s.in.appuyer( 'saut' );
		b.pas( s, 1 );
		r.tropTard = { vy: s.j.vy };

		// Double saut : Chtholly (1 saut) puis Nephren (2 sauts).
		function doubleSaut( slug ) {
			const t = b.monde( slug );
			b.pas( t, 1 );
			t.in.appuyer( 'saut' );
			b.pas( t, 15 );
			const vyAvant = t.j.vy;
			t.in.appuyer( 'saut' );
			b.pas( t, 1 );
			const vyDeuxieme = t.j.vy;
			b.pas( t, 10 );
			t.in.appuyer( 'saut' );
			b.pas( t, 1 );
			return { vyAvant: vyAvant, vyDeuxieme: vyDeuxieme, vyTroisieme: t.j.vy, sautsFaits: t.j.sautsFaits };
		}
		r.doubleChtholly = doubleSaut( 'chtholly' );
		r.doubleNephren = doubleSaut( 'nephren' );

		// Attaque en l'air : garde l'élan.
		s = b.monde( 'chtholly' );
		s.in.appuyer( 'droite' );
		b.pas( s, 2 );
		s.in.appuyer( 'saut' );
		b.pas( s, 5 );
		s.in.appuyer( 'epee' );
		b.pas( s, 2 );
		r.attaqueAir = { etat: s.j.etat, vx: s.j.vx, auSol: s.j.auSol };

		// Épée v1 : frappe un Timere une fois par coup (dégâts 1 ; Nephren : 2).
		function frappe( slug ) {
			const t = b.monde( slug );
			const e = ynWE.ennemis.creer( b.univers.ennemis.timere, 'grand', { x: t.j.x + 40, dir: -1, taille: 1.3 }, t.monde );
			e.recharge = 99;
			t.monde.ennemis.push( e );
			const pv = e.pv;
			t.in.appuyer( 'epee' );
			b.pas( t, 30 );
			return pv - e.pv;
		}
		r.degatsChtholly = frappe( 'chtholly' );
		r.degatsNephren = frappe( 'nephren' );

		// Ithea : projectile camp joueur, qui touche un Timere.
		s = b.monde( 'ithea' );
		r.pvIthea = s.j.pvMax;
		const cible = ynWE.ennemis.creer( b.univers.ennemis.timere, 'normal', { x: s.j.x + 120, dir: -1, taille: 1 }, s.monde );
		cible.recharge = 99;
		s.monde.ennemis.push( cible );
		s.in.appuyer( 'epee' );
		const pvCible = cible.pv;
		let tir = null;
		for ( let k = 0; k < 10 && ! tir; k++ ) {
			b.pas( s, 1 );
			tir = s.monde.projectiles[ 0 ] || null;
		}
		r.tir = tir ? { camp: tir.camp, vx: tir.vx, degats: tir.degats, etat: s.j.etat, recharge: s.j.recharges.principale } : null;
		b.pas( s, 40 );
		r.tirTouche = { pv: pvCible - cible.pv, restants: s.monde.projectiles.length };

		// Ithea : ruée de 90 px en 0,2 s, invulnérable.
		s = b.monde( 'ithea' );
		const x0 = s.j.x;
		s.in.appuyer( 'competence' );
		b.pas( s, 3 );
		s.in.relacher( 'competence' );
		const pendant = { etat: s.j.etat, phase: s.j.phase, blesse: s.j.blesser( 1, 1 ), pv: s.j.pv };
		b.pas( s, 20 );
		r.ruee = { distance: Math.round( s.j.x - x0 ), pendant: pendant, etat: s.j.etat, recharge: s.j.recharges.secondaire };

		// Nephren : parade (0,6 s) : coup absorbé, attaquant repoussé ; ensuite le coup porte.
		s = b.monde( 'nephren' );
		const attaquant = ynWE.ennemis.creer( b.univers.ennemis.timere, 'normal', { x: s.j.x - 30, dir: 1, taille: 1 }, s.monde );
		attaquant.recharge = 99;
		s.monde.ennemis.push( attaquant );
		s.in.appuyer( 'competence' );
		b.pas( s, 2 );
		s.in.relacher( 'competence' );
		r.parade = { pare: s.j.pare, etat: s.j.etat, blesse: s.j.blesser( 1, 1 ), pv: s.j.pv, recul: attaquant.recul, etatAttaquant: attaquant.etat };
		b.pas( s, 45 );
		r.apresParade = { pare: s.j.pare, etat: s.j.etat, blesse: s.j.blesser( 1, 1 ), pv: s.j.pv };

		// Mort en l'air : retombe au sol.
		s = b.monde( 'chtholly' );
		s.j.pv = 1;
		b.pas( s, 1 );
		s.in.appuyer( 'saut' );
		b.pas( s, 10 );
		s.j.blesser( 1, 1 );
		b.pas( s, 80 );
		r.mortAir = { etat: s.j.etat, y: s.j.y };

		// Animations : pose figée en l'air ; mêmes planches pour les trois personnages.
		s = b.monde( 'chtholly' );
		b.pas( s, 1 );
		s.in.appuyer( 'saut' );
		b.pas( s, 2 );
		r.animSaut = s.j.animationCourante();
		b.pas( s, 30 );
		r.animChute = s.j.animationCourante();
		r.planches = [ 'chtholly', 'nephren', 'ithea' ].map( ( p ) => {
			const pl = b.univers.personnages[ p ].planche;
			return p + ':' + pl.image.naturalWidth + 'x' + pl.image.naturalHeight + ':' + Object.keys( pl.meta.animations ).length;
		} );
		return r;
	} );

	verifier( 'sol plat : y = 238, vy = 0, au sol', factice.sol.y === 238 && factice.sol.vy === 0 && factice.sol.auSol && factice.sol.etat === 'repos', factice.sol );
	verifier( 'saut depuis x = 248 : atterrit à y = 180 sur la plateforme', factice.plateforme.y === 180 && factice.plateforme.auSol && factice.plateforme.support, factice.plateforme );
	verifier( 'états saut → chute → repos', factice.plateforme.etats.join( ',' ) === 'repos,saut,chute,repos' || factice.plateforme.etats.join( ',' ) === 'saut,chute,repos', factice.plateforme.etats );
	verifier( 'quitte la plateforme par le bord et retombe au sol', factice.bord.y === 180 && factice.apresBord.y === 238 && factice.apresBord.auSol, [ factice.bord, factice.apresBord ] );
	verifier( 'bas + saut : traverse la plateforme', factice.traverse.surPlateforme && factice.traverse.y === 238 && factice.traverse.auSol, factice.traverse );
	verifier( 'plateforme solide : pas de traversée', factice.solide.resteDessus, factice.solide );
	verifier( 'plateforme solide : la tête bute dessous', Math.abs( factice.solide.yMin - factice.solide.attendu ) < 0.01 && factice.solide.yFinal === 238, factice.solide );
	verifier( 'saut écourté (touche relâchée tôt) : plus bas', factice.ecourte > 10 && factice.ecourte < 40, factice.ecourte );
	verifier( 'tolérance de bord : saut 3 pas après la chute', factice.coyote.vy < -300 && factice.coyote.etat === 'saut', factice.coyote );
	verifier( 'trop tard après le bord : pas de saut (Chtholly)', factice.tropTard.vy > 0, factice.tropTard );
	verifier( 'Chtholly : pas de double saut', factice.doubleChtholly.vyDeuxieme > factice.doubleChtholly.vyAvant, factice.doubleChtholly );
	verifier( 'Nephren : double saut, pas de triple', factice.doubleNephren.vyDeuxieme < -290 && factice.doubleNephren.vyTroisieme > -200 && factice.doubleNephren.sautsFaits === 2, factice.doubleNephren );
	verifier( 'attaque en l’air : garde l’élan', factice.attaqueAir.etat === 'attaque' && factice.attaqueAir.vx === 72 && ! factice.attaqueAir.auSol, factice.attaqueAir );
	verifier( 'épée : 1 dégât par coup (Chtholly), 2 (Nephren)', factice.degatsChtholly === 1 && factice.degatsNephren === 2, [ factice.degatsChtholly, factice.degatsNephren ] );
	verifier( 'Ithea : 4 PV', factice.pvIthea === 4, factice.pvIthea );
	verifier( 'Ithea : J crée un projectile camp « joueur »', factice.tir && factice.tir.camp === 'joueur' && factice.tir.vx === 300 && factice.tir.etat === 'attaque' && factice.tir.recharge > 0.3, factice.tir );
	verifier( 'projectile : touche le Timere puis disparaît', factice.tirTouche.pv === 1 && factice.tirTouche.restants === 0, factice.tirTouche );
	verifier( 'Ithea : ruée ≈ 90 px, invulnérable', Math.abs( factice.ruee.distance - 90 ) <= 4 && factice.ruee.pendant.phase === 'ruee' && ! factice.ruee.pendant.blesse && factice.ruee.pendant.pv === 4 && factice.ruee.etat === 'repos' && factice.ruee.recharge > 1, factice.ruee );
	verifier( 'Nephren : parade absorbe et repousse l’attaquant', factice.parade.pare && ! factice.parade.blesse && factice.parade.pv === 5 && factice.parade.recul < 0 && factice.parade.etatAttaquant === 'degats', factice.parade );
	verifier( 'après la parade, le coup porte', ! factice.apresParade.pare && factice.apresParade.blesse && factice.apresParade.pv === 4, factice.apresParade );
	verifier( 'mort en l’air : retombe au sol', factice.mortAir.etat === 'mort' && factice.mortAir.y === 238, factice.mortAir );
	verifier( 'poses figées : saut = course 1, chute = course 3', factice.animSaut.join() === 'course,1' && factice.animChute.join() === 'course,3', [ factice.animSaut, factice.animChute ] );
	verifier( 'planches des 3 personnages chargées (809 × 1048, 7 animations)', factice.planches.every( ( p ) => /:809x1048:7$/.test( p ) ), factice.planches );

	/* ---------------------------------------------------------------- */
	console.log( 'Nephren et Ithea (parties réelles)' );
	await ouvrirPartie( page, 'nephren' );
	await page.keyboard.down( 'ArrowUp' );
	await pause( 250 );
	await page.keyboard.up( 'ArrowUp' );
	await pause( 50 );
	const avantDouble = await joueur( page );
	await page.keyboard.down( 'ArrowUp' );
	await pause( 40 );
	j = await joueur( page );
	verifier( 'Nephren : double saut au clavier', ! avantDouble.auSol && j.vy < -200, [ avantDouble, j ] );
	await pause( 200 );
	await page.screenshot( { path: path.join( CAPTURES, 'banc-B-nephren.png' ), clip: await page.locator( 'canvas.yn-wordend__ecran' ).boundingBox() } );
	await page.keyboard.up( 'ArrowUp' );
	await page.waitForFunction( () => window.ynWordEndMoteur.debug.monde().joueur.auSol, null, { timeout: 3000 } );
	await page.keyboard.down( 'KeyK' );
	await pause( 60 );
	j = await joueur( page );
	await page.keyboard.up( 'KeyK' );
	verifier( 'Nephren : K → parade', j.etat === 'competence' && j.phase === 'parade', j );
	await page.screenshot( { path: path.join( CAPTURES, 'banc-B-parade.png' ), clip: await page.locator( 'canvas.yn-wordend__ecran' ).boundingBox() } );

	await ouvrirPartie( page, 'ithea' );
	const stub = await page.evaluate( remplacerStubProjectiles );
	console.log( '  (stub ennemis.mettreAJourProjectiles du lot 0 remplacé par le banc : ' + stub + ')' );
	await page.keyboard.press( 'KeyJ' );
	await page.waitForFunction( () => window.ynWordEndMoteur.debug.monde().projectiles.length > 0, null, { timeout: 2000 } ).catch( () => {} );
	const projectiles = await page.evaluate( () => window.ynWordEndMoteur.debug.monde().projectiles.map( ( p ) => p.camp ) );
	verifier( 'Ithea : KeyJ crée un projectile camp « joueur »', projectiles.length === 1 && projectiles[ 0 ] === 'joueur', projectiles );
	await pause( 120 );
	await page.screenshot( { path: path.join( CAPTURES, 'banc-B-ithea.png' ), clip: await page.locator( 'canvas.yn-wordend__ecran' ).boundingBox() } );
	const xAvant = ( await joueur( page ) ).x;
	await page.keyboard.down( 'KeyK' );
	await pause( 60 );
	j = await joueur( page );
	await page.screenshot( { path: path.join( CAPTURES, 'banc-B-ruee.png' ), clip: await page.locator( 'canvas.yn-wordend__ecran' ).boundingBox() } );
	await page.keyboard.up( 'KeyK' );
	await pause( 300 );
	const xApres = ( await joueur( page ) ).x;
	verifier( 'Ithea : K → ruée (≈ 90 px)', j.phase === 'ruee' && Math.abs( xApres - xAvant - 90 ) <= 6, { phase: j.phase, distance: xApres - xAvant } );

	// Quelques secondes de jeu (vagues de Timeres) avec des commandes variées.
	const touches = [ 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'KeyJ', 'KeyK' ];
	for ( let k = 0; k < 40; k++ ) {
		const t = touches[ k % touches.length ];
		await page.keyboard.down( t );
		await pause( 80 );
		await page.keyboard.up( t );
	}
	const etatFinal = await page.evaluate( () => window.ynWordEndMoteur.debug.etat() );
	verifier( 'partie Ithea toujours en cours ou finie proprement', [ 'jeu', 'fin' ].indexOf( etatFinal ) !== -1, etatFinal );

	/* ---------------------------------------------------------------- */
	verifier( 'aucune erreur console', erreurs.length === 0, erreurs );
	await nav.close();
	console.log( echecs ? echecs + ' contrôle(s) en échec.' : 'Tous les contrôles du lot B passent.' );
	process.exit( echecs ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
