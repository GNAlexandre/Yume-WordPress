/**
 * Banc d'essai WordEnd — vérifications du lot C (ennemis et niveaux), avec Playwright.
 *
 *   php -S 127.0.0.1:8083 -t <racine du dépôt>
 *   node tools/wordend/banc/verifier-C.js [adresse du serveur] [dossier des captures]
 *
 * Adresse par défaut : http://127.0.0.1:8083 ; captures : dossier temporaire du système.
 * Les simulations passent par ynWordEndMoteur.niveau.avancer(partie, secondes) (pas fixes, sans
 * affichage) sur la partie de la modale ou sur des parties isolées (niveau.demarrer, sans entrées).
 * Code de sortie 1 si une vérification échoue ou si la console affiche une erreur.
 */
'use strict';

const path = require( 'path' );
const os = require( 'os' );
const { chromium } = require( '/opt/node22/lib/node_modules/playwright' );

const BASE = ( process.argv[ 2 ] || 'http://127.0.0.1:8083' ).replace( /\/$/, '' );
const SORTIE = process.argv[ 3 ] || os.tmpdir();
const NIVEAUX = [ 'arcade', '01-plage', '02-dunes', '03-falaise', '04-nuit', '05-boss' ];

let echecs = 0;
let reussites = 0;
const erreursConsole = [];

function verifier( condition, message, detail ) {
	if ( condition ) {
		reussites++;
		console.log( '  ok   ' + message );
	} else {
		echecs++;
		console.log( '  ÉCHEC ' + message + ( detail !== undefined ? ' — ' + JSON.stringify( detail ) : '' ) );
	}
}

async function nouvellePage( navigateur ) {
	const page = await navigateur.newPage( { viewport: { width: 1100, height: 800 } } );
	page.on( 'pageerror', ( e ) => erreursConsole.push( 'pageerror : ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			erreursConsole.push( 'console : ' + m.text() );
		}
	} );
	return page;
}

/* Banc en mode jeu, niveau lancé depuis l'écran titre (Entrée). */
async function ouvrirNiveau( navigateur, niveau ) {
	const page = await nouvellePage( navigateur );
	await page.goto( `${ BASE }/tools/wordend/banc/?univers=sukasuka&niveau=${ niveau }&personnage=chtholly&auto=1` );
	await page.waitForFunction( () => window.ynWordEndMoteur && window.ynWordEndMoteur.debug && window.ynWordEndMoteur.debug.etat() === 'titre', null, { timeout: 15000 } );
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( () => window.ynWordEndMoteur.debug.etat() === 'jeu', null, { timeout: 15000 } );
	return page;
}

/*
 * Outils installés dans la page : parties isolées (sans entrées) et journal des événements.
 * window.__C.partie(slug) → Promise<partie> ; window.__C.evenements : [{nom, detail}].
 */
async function installerOutils( page ) {
	await page.evaluate( () => {
		const M = window.ynWordEndMoteur;
		const C = { evenements: [] };
		[ 'niveau:fin', 'vague:debut', 'ennemi:mort', 'joueur:blesse' ].forEach( ( nom ) => {
			M.evenements.sur( nom, ( detail ) => {
				C.evenements.push( { nom, detail: nom === 'ennemi:mort' ? { type: detail.ennemi.typeNom } : detail } );
			} );
		} );
		C.univers = () => M.debug.monde().univers;
		C.partie = ( slug ) => M.ressources.chargerNiveau( C.univers(), slug ).then( ( n ) => M.niveau.demarrer( C.univers(), n, M.debug.monde().personnage, null ) );
		window.__C = C;
	} );
}

async function capture( page, nom ) {
	const fichier = path.join( SORTIE, nom );
	await page.screenshot( { path: fichier } );
	console.log( '  capture ' + fichier );
}

( async () => {
	const navigateur = await chromium.launch();
	console.log( 'WordEnd lot C — ' + BASE );

	/* ------------------------------------------------------------ */
	console.log( '\nJSON des niveaux et de l’ennemi' );
	{
		const page = await ouvrirNiveau( navigateur, 'arcade' );
		const r = await page.evaluate( ( niveaux ) => {
			const M = window.ynWordEndMoteur;
			const u = M.debug.monde().univers;
			return Promise.all( niveaux.map( ( s ) => M.ressources.chargerNiveau( u, s ) ) ).then( ( liste ) => ( {
				niveaux: liste.map( ( n ) => ( { slug: n.slug, version: n.version, objectif: n.objectif.type, largeur: n.largeur, suivant: n.suivant || '', plateformes: n.plateformes.map( ( p ) => [ p.x, p.y, p.l ] ) } ) ),
				types: Object.keys( u.ennemis.timere.types ),
				comportements: Object.keys( u.ennemis.timere.types ).map( ( t ) => u.ennemis.timere.types[ t ].comportement ),
			} ) );
		}, NIVEAUX );
		verifier( r.niveaux.length === 6 && r.niveaux.every( ( n, i ) => n.slug === NIVEAUX[ i ] && n.version === 2 ), 'les 6 niveaux du manifeste se chargent (slug = nom du fichier, version 2)' );
		verifier( JSON.stringify( r.niveaux.map( ( n ) => n.objectif ) ) === JSON.stringify( [ 'arcade', 'vagues', 'vagues', 'vagues', 'survie', 'boss' ] ), 'objectifs arcade, vagues ×3, survie, boss', r.niveaux.map( ( n ) => n.objectif ) );
		verifier( r.niveaux[ 2 ].largeur === 960, '02-dunes fait deux écrans (960 px)' );
		verifier( r.niveaux.every( ( n ) => n.plateformes.every( ( p ) => p[ 0 ] >= 0 && p[ 0 ] + p[ 2 ] <= n.largeur && p[ 1 ] >= 238 - 55 * 3 ) ), 'plateformes dans [0, largeur]' );
		verifier( r.niveaux.every( ( n ) => n.plateformes.every( ( p ) => {
			// Chaque plateforme est à 55 px au plus d'une surface inférieure qui la chevauche (sol ou plateforme).
			const surfaces = [ 238 ].concat( n.plateformes.filter( ( q ) => q[ 1 ] > p[ 1 ] && q[ 0 ] < p[ 0 ] + p[ 2 ] + 40 && q[ 0 ] + q[ 2 ] > p[ 0 ] - 40 ).map( ( q ) => q[ 1 ] ) );
			return surfaces.some( ( s ) => s - p[ 1 ] <= 55 );
		} ) ), 'plateformes accessibles (≤ 55 px au-dessus d’une surface voisine)' );
		verifier( r.niveaux.slice( 1, 5 ).every( ( n, i ) => n.suivant === NIVEAUX[ i + 2 ] ), 'chaîne « suivant » 01 → 05' );
		verifier( JSON.stringify( r.types ) === JSON.stringify( [ 'petit', 'normal', 'coureur', 'grand', 'volant', 'tireur', 'bouclier', 'boss' ] ), 'timere.json : 8 types' );
		verifier( JSON.stringify( r.comportements.slice( 4 ) ) === JSON.stringify( [ 'volant', 'tireur', 'bouclier', 'boss' ] ), 'comportements volant, tireur, bouclier, boss' );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\nArcade (générateur v1)' );
	{
		const page = await ouvrirNiveau( navigateur, 'arcade' );
		await installerOutils( page );
		const r = await page.evaluate( () => {
			const M = window.ynWordEndMoteur;
			const partie = M.debug.partie();
			// Partie isolée (même JSON) pour mesurer la première vague sans interférence.
			const p = M.niveau.demarrer( partie.monde.univers, partie.niveau, partie.monde.personnage, null );
			const crees = [];
			const creer = M.ennemis.creer;
			M.ennemis.creer = function ( def, type, options, monde ) {
				const e = creer.apply( this, arguments );
				if ( monde === p.monde ) {
					crees.push( { type, x: options.x, vitesse: e.vitesse, base: def.types[ type ].vitesse, vague: p.vague.numero } );
				}
				return e;
			};
			try {
				M.niveau.avancer( p, 1.1 );
				const avant = p.vague.numero;
				M.niveau.avancer( p, 0.2 );
				const apres = p.vague.numero;
				const aFaire = p.vague.aFaire;
				for ( let i = 0; i < 20 * 60 && p.vague.numero === 1; i++ ) {
					p.monde.joueur.invincible = 1;
					p.mettreAJour( M.DT );
				}
				return { avant, apres, aFaire, crees, hud: p.hud(), objectif: p.hud().objectif, pv: p.monde.joueur.pv };
			} finally {
				M.ennemis.creer = creer;
			}
		} );
		verifier( r.avant === 0 && r.apres === 1, 'première vague à 1,2 s' );
		verifier( r.aFaire === 5, 'vague 1 annoncée : 5 Timeres (3 + 2n)', r.aFaire );
		const v1 = r.crees.filter( ( c ) => c.vague === 1 );
		verifier( v1.length === 5, 'vague 1 : 5 Timeres créés', v1.length );
		verifier( v1.every( ( c ) => [ 'petit', 'normal' ].includes( c.type ) ), 'vague 1 : types petit/normal', v1.map( ( c ) => c.type ) );
		verifier( v1.every( ( c ) => c.x === -50 || c.x === 530 ), 'apparition à −50 ou largeur + 50' );
		verifier( v1.every( ( c ) => Math.abs( c.vitesse - c.base * 1.04 ) < 1e-9 ), 'vitesse × (1 + 0,04n)' );
		verifier( r.hud.total === null && r.objectif === 'arcade', 'hud : total null en arcade' );
		await capture( page, 'banc-C-arcade-debut.png' );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\n01-plage (vagues)' );
	{
		const page = await ouvrirNiveau( navigateur, '01-plage' );
		await installerOutils( page );
		const r = await page.evaluate( () => {
			const M = window.ynWordEndMoteur;
			const p = M.debug.partie();
			M.niveau.avancer( p, 40 );
			const h = p.hud();
			const vagues = window.__C.evenements.filter( ( e ) => e.nom === 'vague:debut' ).map( ( e ) => e.detail );
			return { total: h.total, vague: h.vague, vagues, niveau: p.niveau.slug, plateformes: p.monde.plateformes.length };
		} );
		verifier( r.niveau === '01-plage' && r.plateformes === 2, 'partie de la modale : 01-plage, 2 plateformes' );
		verifier( r.total === 3, 'hud().total === 3', r.total );
		verifier( r.vague >= 1 && r.vagues.length >= 1 && r.vagues[ 0 ].numero === 1 && r.vagues[ 0 ].total === 3, 'vague 1 apparue (vague:debut {1, 3})', r.vagues );

		// Partie complète : le banc abat chaque ennemi visible ; 3 vagues puis victoire.
		const g = await page.evaluate( () => window.__C.partie( '01-plage' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			window.__C.evenements.length = 0;
			let soins = 0;
			let pvAvant = 0;
			for ( let i = 0; i < 180 * 60 && p.etat === 'enCours'; i++ ) {
				const j = p.monde.joueur;
				if ( i === 0 ) {
					j.pv = 3;
				}
				j.invincible = 1;
				pvAvant = j.pv;
				p.monde.ennemis.forEach( ( e ) => {
					if ( e.etat !== 'mort' && e.x > 30 && e.x < 450 ) {
						M.ennemis.blesser( e, 99, 1, p.monde, { type: 'onde', dir: 1 } );
					}
				} );
				p.mettreAJour( M.DT );
				if ( j.pv > pvAvant ) {
					soins++;
				}
			}
			const fin = window.__C.evenements.filter( ( e ) => e.nom === 'niveau:fin' ).map( ( e ) => e.detail );
			const vagues = window.__C.evenements.filter( ( e ) => e.nom === 'vague:debut' ).map( ( e ) => e.detail.numero );
			const morts = window.__C.evenements.filter( ( e ) => e.nom === 'ennemi:mort' ).length;
			return { etat: p.etat, fin, vagues, morts, soins, score: p.monde.score, temps: p.monde.temps };
		} ) );
		verifier( JSON.stringify( g.vagues ) === '[1,2,3]', 'trois vagues successives', g.vagues );
		verifier( g.morts === 3 + 4 + 5, '12 Timeres (3 + 4 + 5)', g.morts );
		verifier( g.soins === 2, 'soinEntreVagues : +1 PV après les vagues 1 et 2', g.soins );
		verifier( g.etat === 'gagne' && g.fin.length === 1 && g.fin[ 0 ].resultat === 'gagne' && g.fin[ 0 ].niveau === '01-plage', 'gagné : un seul niveau:fin', g.fin );
		// Score : 3×10 + (2×10 + 2×15) + (3×15 + 2×10) + bonus 50 + 100 + 150 = 445 ≥ 400 ; pv 5 ≥ 4 : 3 étoiles.
		verifier( g.score === 445 && g.fin[ 0 ].etoiles === 3, 'score 445, 3 étoiles', { score: g.score, etoiles: g.fin[ 0 ] && g.fin[ 0 ].etoiles } );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\nÉtoiles et gabarit' );
	{
		const page = await ouvrirNiveau( navigateur, 'arcade' );
		const r = await page.evaluate( () => {
			const N = window.ynWordEndMoteur.niveau;
			const n = { etoiles: { score: 400, pvRestants: 4, temps: 75 } };
			return [
				N.calculerEtoiles( n, { resultat: 'perdu', score: 999, pv: 5, temps: 1 } ),
				N.calculerEtoiles( n, { resultat: 'gagne', score: 10, pv: 1, temps: 100 } ),
				N.calculerEtoiles( n, { resultat: 'gagne', score: 400, pv: 1, temps: 100 } ),
				N.calculerEtoiles( n, { resultat: 'gagne', score: 10, pv: 1, temps: 70 } ),
				N.calculerEtoiles( n, { resultat: 'gagne', score: 500, pv: 4, temps: 100 } ),
				N.gabarit( 'Vague {n} sur {total} : {k} Timeres {x}.', { n: 2, total: 3, k: 4 } ),
			];
		} );
		verifier( JSON.stringify( r.slice( 0, 5 ) ) === '[0,1,2,2,3]', 'calculerEtoiles', r );
		verifier( r[ 5 ] === 'Vague 2 sur 3 : 4 Timeres {x}.', 'gabarit' );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\n02-dunes (caméra, coureurs, volants)' );
	{
		const page = await ouvrirNiveau( navigateur, '02-dunes' );
		await installerOutils( page );
		await page.evaluate( () => {
			const j = window.ynWordEndMoteur.debug.monde().joueur;
			j.x = 900;
		} );
		await page.waitForTimeout( 1500 );
		const cam = await page.evaluate( () => window.ynWordEndMoteur.debug.monde().camera.x );
		verifier( cam > 400 && cam <= 480, 'caméra : suit le joueur jusqu’au bord droit (≤ 480)', cam );
		const r = await page.evaluate( () => window.__C.partie( '02-dunes' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			const m = p.monde;
			const j = m.joueur;
			j.x = 240;
			const v = M.ennemis.creer( m.univers.ennemis.timere, 'volant', { x: 330, dir: -1 }, m );
			m.ennemis.push( v );
			const y0 = v.y;
			let plongee = false;
			let yMax = v.y;
			let remonte = false;
			let blesse = false;
			let yVol = [];
			for ( let i = 0; i < 8 * 60; i++ ) {
				j.x = 240;
				p.mettreAJour( M.DT );
				if ( v.phase === 'plongee' ) {
					plongee = true;
				}
				if ( plongee && v.phase === 'remonte' ) {
					remonte = true;
				}
				if ( v.phase === 'vol' ) {
					yVol.push( v.y );
				}
				yMax = Math.max( yMax, v.y );
				if ( j.pv < j.pvMax ) {
					blesse = true;
				}
			}
			return { y0, sol: m.sol, plongee, remonte, yMax, blesse, yVolMin: Math.min.apply( null, yVol ), yVolMax: Math.max.apply( null, yVol ), planche: v.planche === m.univers.ennemis.timere.planche, typePlanche: !! ( v.type.planche && v.type.planche.image ) };
		} ) );
		verifier( r.y0 === r.sol - 80, 'volant : apparaît à 80 px du sol', r.y0 );
		verifier( r.yVolMin >= r.sol - 80 - 14.01 && r.yVolMax <= r.sol - 80 + 14.01, 'volant : ondule de ±14 px sans gravité', [ r.yVolMin, r.yVolMax ] );
		verifier( r.plongee && r.remonte && r.yMax > r.sol - 40, 'volant : plonge sur le joueur puis remonte', r );
		verifier( r.blesse, 'volant : la plongée blesse le joueur' );
		console.log( '  info planche du type résolue par ressources (lot A) : ' + r.typePlanche );
		// Coureurs de la vague 1, venus de la droite hors écran (caméra à 0).
		const c = await page.evaluate( () => window.__C.partie( '02-dunes' ).then( ( p ) => {
			const xs = [];
			const creer = window.ynWordEndMoteur.ennemis.creer;
			window.ynWordEndMoteur.ennemis.creer = function ( def, type, options ) {
				xs.push( [ type, options.x ] );
				return creer.apply( this, arguments );
			};
			try {
				window.ynWordEndMoteur.niveau.avancer( p, 6 );
			} finally {
				window.ynWordEndMoteur.ennemis.creer = creer;
			}
			return { xs, largeur: p.monde.largeur, camera: p.monde.camera.x };
		} ) );
		verifier( c.camera === 0 && c.xs.some( ( e ) => e[ 0 ] === 'coureur' && e[ 1 ] === 530 ) && c.xs.some( ( e ) => e[ 0 ] === 'petit' && e[ 1 ] === -50 ), 'apparitions juste hors de l’écran (caméra + 530, −50)', c.xs );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\n03-falaise (tireurs, projectiles)' );
	{
		const page = await ouvrirNiveau( navigateur, '03-falaise' );
		await installerOutils( page );
		const r = await page.evaluate( () => window.__C.partie( '03-falaise' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			const m = p.monde;
			let tir = null;
			let tireur = null;
			for ( let i = 0; i < 12 * 60 && ! tir; i++ ) {
				m.joueur.invincible = 1;
				p.mettreAJour( M.DT );
				tireur = tireur || m.ennemis.find( ( e ) => e.typeNom === 'tireur' );
				tir = m.projectiles.find( ( q ) => q.camp === 'ennemi' ) || null;
			}
			return {
				tir: tir && { camp: tir.camp, vx: tir.vx, degats: tir.degats, couleur: tir.couleur },
				tireur: tireur && { x: tireur.x, y: tireur.y, geste: tireur.attaque },
				sol: m.sol,
			};
		} ) );
		verifier( !! r.tireur && r.tireur.x > 380, 'tireur apparu au point {x: 420, y: 188}', r.tireur );
		console.log( '  info y du tireur : ' + ( r.tireur && r.tireur.y ) + ' (188 avec la physique du lot B, ' + r.sol + ' avec le stub du lot 0)' );
		verifier( !! r.tir && r.tir.camp === 'ennemi' && r.tir.vx < 0 && r.tir.degats === 1 && r.tir.couleur === '#c9f26a', 'un projectile camp « ennemi » part vers le joueur', r.tir );

		const u = await page.evaluate( () => window.__C.partie( '03-falaise' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			const m = p.monde;
			const j = m.joueur;
			j.x = 100;
			// Projectile ennemi : blesse le joueur et disparaît.
			M.ennemis.ajouterProjectile( m, { camp: 'ennemi', x: 150, y: j.y - 28, vx: -170, vy: 0, degats: 1 } );
			for ( let i = 0; i < 30; i++ ) {
				M.ennemis.mettreAJourProjectiles( m, M.DT );
			}
			const apresTouche = { pv: j.pv, n: m.projectiles.length };
			// Joueur invincible : le projectile le traverse.
			M.ennemis.ajouterProjectile( m, { camp: 'ennemi', x: 150, y: j.y - 28, vx: -170, vy: 0, degats: 1 } );
			for ( let i = 0; i < 30; i++ ) {
				M.ennemis.mettreAJourProjectiles( m, M.DT );
			}
			const traverse = { pv: j.pv, n: m.projectiles.length };
			m.projectiles.length = 0;
			// Parade : le projectile est renvoyé (camp joueur) et frappe un Timere.
			j.invincible = 0;
			j.pare = true;
			const e = M.ennemis.creer( m.univers.ennemis.timere, 'normal', { x: 220, dir: -1 }, m );
			m.ennemis.push( e );
			M.ennemis.ajouterProjectile( m, { camp: 'ennemi', x: 150, y: j.y - 28, vx: -170, vy: 0, degats: 1 } );
			let renvoye = null;
			for ( let i = 0; i < 90; i++ ) {
				M.ennemis.mettreAJourProjectiles( m, M.DT );
				renvoye = renvoye || ( m.projectiles[ 0 ] && m.projectiles[ 0 ].camp === 'joueur' ? { vx: m.projectiles[ 0 ].vx } : null );
			}
			j.pare = false;
			return { apresTouche, traverse, renvoye, pvJoueur: j.pv, pvEnnemi: e.pv, reste: m.projectiles.length };
		} ) );
		verifier( u.apresTouche.pv === 4 && u.apresTouche.n === 0, 'projectile ennemi : −1 PV au joueur, puis disparaît', u.apresTouche );
		verifier( u.traverse.pv === 4 && u.traverse.n === 1, 'joueur invincible : le projectile le traverse', u.traverse );
		verifier( !! u.renvoye && u.renvoye.vx > 0 && u.pvJoueur === 4 && u.pvEnnemi === 1 && u.reste === 0, 'parade (j.pare) : projectile renvoyé qui frappe un Timere', u );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\n04-nuit (survie, boucliers)' );
	{
		const page = await ouvrirNiveau( navigateur, '04-nuit' );
		await installerOutils( page );
		const r = await page.evaluate( () => window.__C.partie( '04-nuit' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			const m = p.monde;
			window.__C.evenements.length = 0;
			const types = {};
			const restants = [];
			let max = 0;
			for ( let i = 0; i < 80 * 60 && p.etat === 'enCours'; i++ ) {
				m.joueur.invincible = 1;
				p.mettreAJour( M.DT );
				m.ennemis.forEach( ( e ) => {
					types[ e.typeNom ] = true;
				} );
				max = Math.max( max, m.ennemis.filter( ( e ) => e.etat !== 'mort' ).length );
				if ( i % 600 === 0 ) {
					restants.push( Math.round( p.hud().restant ) );
				}
			}
			const fin = window.__C.evenements.filter( ( e ) => e.nom === 'niveau:fin' ).map( ( e ) => e.detail );
			return { etat: p.etat, temps: m.temps, fin, types: Object.keys( types ), max, restants, objectif: p.hud().objectif, voile: p.niveau.voile };
		} ) );
		verifier( r.objectif === 'survie' && r.restants[ 0 ] === 75 && r.restants[ 1 ] === 65, 'hud : objectif survie, restant 75 → 65…', r.restants );
		verifier( r.etat === 'gagne' && Math.abs( r.temps - 75 ) < 0.05, 'gagné après 75 s', r.temps );
		verifier( r.fin.length === 1 && r.fin[ 0 ].etoiles >= 1, 'niveau:fin gagné avec étoiles', r.fin );
		verifier( r.types.includes( 'bouclier' ), 'des boucliers apparaissent', r.types );
		verifier( r.max <= 7, 'au plus 7 ennemis en vie (générateur.max)', r.max );
		verifier( typeof r.voile === 'string' && r.voile.indexOf( 'rgba' ) === 0, 'champ voile présent' );

		const b = await page.evaluate( () => window.__C.partie( '04-nuit' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			const m = p.monde;
			const j = m.joueur;
			j.x = 200;
			const e = M.ennemis.creer( m.univers.ennemis.timere, 'bouclier', { x: 300, dir: -1 }, m );
			m.ennemis.push( e );
			const res = {};
			M.ennemis.blesser( e, 1, 1, m, { type: 'melee', dir: -1 } );
			res.face = e.pv;
			M.ennemis.blesser( e, 1, -1, m, { type: 'melee', dir: -1 } );
			res.dos = e.pv;
			M.ennemis.blesser( e, 1, 1, m, { type: 'onde', dir: -1 } );
			res.onde = e.pv;
			// Le joueur passe derrière : demi-tour après 0,4 s.
			e.pv = 3;
			M.ennemis.changerEtat( e, 'marche' );
			e.recul = 0;
			j.x = 420;
			const dirs = [];
			for ( let i = 0; i < 40; i++ ) {
				j.x = 420;
				M.ennemis.mettreAJour( e, M.DT, m );
				dirs.push( e.dir );
			}
			res.dir02 = dirs[ 11 ];
			res.dir05 = dirs[ 32 ];
			return res;
		} ) );
		verifier( b.face === 3, 'bouclier : coup de face paré', b );
		verifier( b.dos === 2, 'bouclier : coup dans le dos porté', b );
		verifier( b.onde === 1, 'bouclier : l’onde traverse la garde', b );
		verifier( b.dir02 === -1 && b.dir05 === 1, 'bouclier : demi-tour en 0,4 s', b );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\n05-boss' );
	{
		const page = await ouvrirNiveau( navigateur, '05-boss' );
		await installerOutils( page );
		const h = await page.evaluate( () => window.ynWordEndMoteur.debug.partie().hud() );
		verifier( h.boss && h.boss.pvMax === 40 && h.boss.pv === 40 && h.boss.nom === 'Timere géant', 'hud().boss : {pv 40, pvMax 40, nom}', h.boss );
		const r = await page.evaluate( () => window.__C.partie( '05-boss' ).then( ( p ) => {
			const M = window.ynWordEndMoteur;
			const m = p.monde;
			const boss = p.boss;
			window.__C.evenements.length = 0;
			let charge = false;
			let xMin = boss.x;
			for ( let i = 0; i < 10 * 60; i++ ) {
				m.joueur.invincible = 1;
				m.joueur.x = 110;
				p.mettreAJour( M.DT );
				charge = charge || boss.charge === 'ruee';
				xMin = Math.min( xMin, boss.x );
			}
			// Coups : ni recul ni état « degats ».
			M.ennemis.blesser( boss, 1, 1, m, { type: 'melee', dir: 1 } );
			M.ennemis.blesser( boss, 3, 1, m, { type: 'onde', dir: 1 } );
			p.mettreAJour( M.DT );
			const stoique = { recul: boss.recul, etat: boss.etat, pv: boss.pv };
			// Phase 2 (PV ≤ 50 %) : vitesse × 1,3 et invocations de petits Timeres.
			M.ennemis.blesser( boss, boss.pv - 20, 1, m, { type: 'onde', dir: 1 } );
			for ( let i = 0; i < 3 * 60; i++ ) {
				m.joueur.invincible = 1;
				p.mettreAJour( M.DT );
			}
			const invoques = m.ennemis.filter( ( e ) => e.invoquePar === boss.id ).map( ( e ) => e.typeNom );
			const phase = { indice: boss.phaseBoss, vitesse: boss.vitesse, base: boss.vitesseBase };
			// Victoire 1,6 s après la mort du boss.
			M.ennemis.blesser( boss, 999, 1, m, { type: 'onde', dir: 1 } );
			M.niveau.avancer( p, 1.5 );
			const avant = p.etat;
			M.niveau.avancer( p, 0.2 );
			const fin = window.__C.evenements.filter( ( e ) => e.nom === 'niveau:fin' ).map( ( e ) => e.detail );
			return { charge, xMin, stoique, invoques, phase, avant, etat: p.etat, fin, hud: p.hud().boss };
		} ) );
		verifier( r.charge && r.xMin < 200, 'charge traversante dans les 10 premières secondes', { charge: r.charge, xMin: r.xMin } );
		verifier( r.stoique.recul === 0 && r.stoique.etat !== 'degats' && r.stoique.pv === 36, 'le boss ne recule jamais', r.stoique );
		verifier( r.phase.indice === 1 && Math.abs( r.phase.vitesse - r.phase.base * 1.3 ) < 1e-9, 'phase 2 sous 50 % : vitesse × 1,3', r.phase );
		verifier( r.invoques.length >= 2 && r.invoques.every( ( t ) => t === 'petit' ), 'phase 2 : invocation de petits Timeres', r.invoques );
		verifier( r.avant === 'enCours' && r.etat === 'gagne' && r.fin.length === 1 && r.fin[ 0 ].resultat === 'gagne' && r.fin[ 0 ].score >= 500, 'mort du boss → niveau:fin gagné (après 1,6 s)', r.fin );
		verifier( r.hud.pv === 0, 'hud().boss.pv borné à 0', r.hud );
		await page.close();
	}

	/* ------------------------------------------------------------ */
	console.log( '\nParties jouées au clavier et captures' );
	for ( const niveau of NIVEAUX ) {
		const page = await ouvrirNiveau( navigateur, niveau );
		const touches = [ 'ArrowRight', 'KeyJ', 'ArrowLeft', 'KeyJ', 'KeyK' ];
		for ( const t of touches ) {
			await page.keyboard.down( t );
			await page.waitForTimeout( 350 );
			await page.keyboard.up( t );
		}
		// Quelques secondes de plus, joueur protégé, pour peupler l'écran.
		await page.evaluate( ( n ) => {
			const M = window.ynWordEndMoteur;
			const p = M.debug.partie();
			for ( let i = 0; i < ( n === '05-boss' ? 3 : 7 ) * 60; i++ ) {
				p.monde.joueur.invincible = 1;
				p.mettreAJour( M.DT );
			}
			p.monde.joueur.invincible = 0;
		}, niveau );
		await page.waitForTimeout( 400 );
		const etat = await page.evaluate( () => window.ynWordEndMoteur.debug.etat() );
		verifier( etat === 'jeu', niveau + ' : partie en cours après ~9 s', etat );
		await capture( page, 'banc-C-' + niveau + '.png' );
		await page.close();
	}
	{
		const page = await nouvellePage( navigateur );
		for ( const niveau of [ '02-dunes', '03-falaise' ] ) {
			await page.goto( `${ BASE }/tools/wordend/banc/?univers=sukasuka&apercu=niveaux/${ niveau }` );
			await page.waitForSelector( 'canvas.banc__vue' );
			await capture( page, 'banc-C-apercu-' + niveau + '.png' );
		}
		await page.close();
	}

	await navigateur.close();
	console.log( '\nConsole : ' + ( erreursConsole.length ? erreursConsole.join( '\n  ' ) : 'aucune erreur' ) );
	verifier( erreursConsole.length === 0, 'aucune erreur de console ni exception' );
	console.log( `\n${ reussites } vérification(s) réussie(s), ${ echecs } échec(s).` );
	process.exit( echecs ? 1 : 0 );
} )().catch( ( erreur ) => {
	console.error( erreur );
	process.exit( 1 );
} );
