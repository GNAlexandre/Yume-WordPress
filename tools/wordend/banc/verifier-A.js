/**
 * WordEnd v2 — vérification du lot A (moteur : ressources, rendu, audio, stockage) sur le banc.
 *
 *   php -S 127.0.0.1:8081 -t <racine du dépôt> &
 *   node tools/wordend/banc/verifier-A.js [dossier des captures]
 *
 * Variables : WORDEND_BANC (défaut http://127.0.0.1:8081/tools/wordend/banc/).
 * Sort avec le code 1 au premier échec. Outil de développement, jamais livré.
 */
'use strict';

const path = require( 'path' );
const fs = require( 'fs' );
const { chromium } = require( '/opt/node22/lib/node_modules/playwright' );

const BANC = process.env.WORDEND_BANC || 'http://127.0.0.1:8081/tools/wordend/banc/';
const CAPTURES = path.resolve( process.argv[ 2 ] || '.' );
const JEU = BANC + '?univers=sukasuka&niveau=arcade&personnage=chtholly&auto=1';
const MANIFESTE = '../../../wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka/manifeste.json';

let echecs = 0;
function verifier( condition, message, detail ) {
	if ( condition ) {
		console.log( '  ok   ' + message );
	} else {
		echecs++;
		console.log( '  ÉCHEC ' + message + ( detail !== undefined ? ' — ' + JSON.stringify( detail ) : '' ) );
	}
}

/* Page du banc, jeu ouvert et prêt (écran titre) ; relève les erreurs de la console. */
async function ouvrirBanc( contexte, adresse, erreurs, tolerees ) {
	const page = await contexte.newPage();
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			const texte = m.text();
			const url = ( m.location() || {} ).url || '';
			if ( ! ( tolerees || [] ).some( ( motif ) => texte.includes( motif ) || url.includes( motif ) ) ) {
				erreurs.push( texte + ( url ? ' @ ' + url : '' ) );
			}
		}
	} );
	page.on( 'pageerror', ( e ) => erreurs.push( 'exception : ' + e.message ) );
	await page.goto( adresse );
	await page.waitForFunction( () => window.ynWordEndMoteur && window.ynWordEndMoteur.debug && window.ynWordEndMoteur.debug.etat() === 'titre', null, { timeout: 15000 } );
	return page;
}

( async () => {
	fs.mkdirSync( CAPTURES, { recursive: true } );
	const navigateur = await chromium.launch( { args: [ '--autoplay-policy=no-user-gesture-required' ] } );
	const contexte = await navigateur.newContext( { viewport: { width: 1100, height: 800 } } );

	/* ------------------------------------------------------------------ */
	console.log( '1. Ressources (chargerUnivers, à la demande, tolérance, teinte)' );
	/* ------------------------------------------------------------------ */
	// Fichiers des lots B et C peut-être absents : leurs 404 sont attendus ici.
	const erreurs1 = [];
	const page1 = await ouvrirBanc( contexte, JEU, erreurs1, [ '404', 'nephren', 'ithea', '01-plage', '02-dunes', '03-falaise', '04-nuit', '05-boss' ] );
	const res = await page1.evaluate( async ( manifeste ) => {
		const W = window.ynWordEndMoteur;
		const u = await W.ressources.chargerUnivers( { manifeste: manifeste, version: '1.2.3', slug: 'sukasuka' } );
		const sortie = {
			slug: u.slug,
			ver: u.ver,
			baseFinieParSlash: /\/$/.test( u.base ),
			personnages: Object.keys( u.personnages ),
			ordrePersonnages: u.ordre.personnages.slice(),
			ennemis: Object.keys( u.ennemis ),
			niveaux: Object.keys( u.niveaux ),
			decors: Object.keys( u.decors ),
			decorLargeur: u.decors.dunes ? u.decors.dunes.naturalWidth : 0,
			musique: u.musiques.principale ? u.musiques.principale.url : '',
			url: W.ressources.urlRelative( u, 'x/y.json' ),
			plancheChtholly: !! ( u.personnages.chtholly && u.personnages.chtholly.planche.image && u.personnages.chtholly.planche.meta.animations.repos ),
			plancheNom: u.personnages.chtholly.planche.nom,
			plancheTypePetit: u.ennemis.timere.types.petit.planche === u.ennemis.timere.planche,
			chemins: Object.keys( u.chemins.niveaux ).length,
		};
		// À la demande : même promesse, mis en cache.
		const n1 = await W.ressources.chargerNiveau( u, 'arcade' );
		sortie.cacheNiveau = n1 === u.niveaux.arcade;
		const p1 = await W.ressources.chargerPersonnage( u, 'chtholly' );
		sortie.cachePersonnage = p1 === u.personnages.chtholly;
		// Tolérance : fichiers listés mais absents retirés de l'ordre, sans rejet.
		await W.ressources.chargerNiveaux( u );
		await W.ressources.chargerPersonnages( u );
		sortie.ordreNiveauxApres = u.ordre.niveaux.slice();
		sortie.ordrePersonnagesApres = u.ordre.personnages.slice();
		// Personnage inconnu : rejet.
		sortie.inconnuRejete = await W.ressources.chargerPersonnage( u, 'personne' ).then( () => false, () => true );
		// Teinte : copie teintée calculée une fois, pixels différents.
		const image = u.ennemis.timere.planche.image;
		const t1 = W.ressources.teinter( image, 'hue-rotate(120deg)' );
		const t2 = W.ressources.teinter( image, 'hue-rotate(120deg)' );
		sortie.teinteCanvas = t1 instanceof HTMLCanvasElement && t1.width === image.naturalWidth;
		sortie.teinteCache = t1 === t2;
		sortie.teinteSansFiltre = W.ressources.teinter( image, '' ) === image;
		const lire = ( src ) => {
			const c = document.createElement( 'canvas' );
			c.width = src.width || src.naturalWidth;
			c.height = src.height || src.naturalHeight;
			const ctx = c.getContext( '2d' );
			ctx.drawImage( src, 0, 0 );
			return ctx.getImageData( 0, 0, c.width, c.height ).data;
		};
		const a = lire( image );
		const b = lire( t1 );
		let differences = 0;
		for ( let i = 0; i < a.length; i += 4 ) {
			if ( a[ i + 3 ] > 0 && ( a[ i ] !== b[ i ] || a[ i + 1 ] !== b[ i + 1 ] || a[ i + 2 ] !== b[ i + 2 ] ) ) {
				differences++;
			}
		}
		sortie.teintePixels = differences;
		return sortie;
	}, MANIFESTE );
	verifier( res.slug === 'sukasuka' && res.ver === '1.2.3' && res.baseFinieParSlash, 'univers : slug, version, base', res );
	verifier( res.personnages.join() === 'chtholly', 'seul le premier personnage est chargé à l’ouverture', res.personnages );
	verifier( res.ordrePersonnages[ 0 ] === 'chtholly' && res.ordrePersonnages.length === 3, 'ordre des personnages du manifeste', res.ordrePersonnages );
	verifier( res.ennemis.join() === 'timere', 'ennemis chargés', res.ennemis );
	verifier( res.niveaux.join() === 'arcade', 'seul le niveau par défaut est chargé', res.niveaux );
	verifier( res.decors.join() === 'dunes' && res.decorLargeur === 960, 'décor du niveau par défaut (960 px)', res );
	verifier( /scarborough\.mp3\?ver=1\.2\.3$/.test( res.musique ), 'adresse de la musique versionnée', res.musique );
	verifier( /x\/y\.json\?ver=1\.2\.3$/.test( res.url ), 'urlRelative', res.url );
	verifier( res.plancheChtholly && res.plancheNom === 'chtholly', 'planche du personnage {nom, image, meta}' );
	verifier( res.plancheTypePetit, 'types.<t>.planche résolu (planche de l’ennemi par défaut)' );
	verifier( res.cacheNiveau && res.cachePersonnage, 'cache des niveaux et des personnages' );
	verifier( res.ordreNiveauxApres[ 0 ] === 'arcade' && res.ordreNiveauxApres.length >= 1, 'chargerNiveaux tolère les absents', res.ordreNiveauxApres );
	verifier( res.ordrePersonnagesApres[ 0 ] === 'chtholly', 'chargerPersonnages tolère les absents', res.ordrePersonnagesApres );
	verifier( res.inconnuRejete, 'personnage inconnu : promesse rejetée' );
	verifier( res.teinteCanvas && res.teinteCache && res.teinteSansFiltre && res.teintePixels > 1000, 'teinte : canvas hors écran, mis en cache, pixels modifiés', res.teintePixels );

	// Teinte déclarée dans le JSON (entité et type) : JSON de Timere modifié à la volée.
	await page1.route( '**/ennemis/timere.json*', async ( route ) => {
		const reponse = await route.fetch();
		const json = await reponse.json();
		json.types.coureur.teinte = 'hue-rotate(200deg)';
		await route.fulfill( { response: reponse, json: json } );
	} );
	const teinteJson = await page1.evaluate( async ( manifeste ) => {
		const W = window.ynWordEndMoteur;
		const u = await W.ressources.chargerUnivers( { manifeste: manifeste, version: 'teinte' } );
		const e = u.ennemis.timere;
		return {
			coureurTeinte: e.types.coureur.planche.teinte,
			coureurImage: e.types.coureur.planche.image instanceof HTMLCanvasElement,
			coureurMeta: e.types.coureur.planche.meta === e.planche.meta,
			normalPartage: e.types.normal.planche === e.planche,
		};
	}, MANIFESTE );
	await page1.unroute( '**/ennemis/timere.json*' );
	verifier( teinteJson.coureurTeinte === 'hue-rotate(200deg)' && teinteJson.coureurImage && teinteJson.coureurMeta && teinteJson.normalPartage, 'types.<t>.teinte : planche teintée propre au type', teinteJson );
	verifier( erreurs1.length === 0, 'aucune erreur console (hors 404 attendus)', erreurs1 );
	await page1.close();

	/* ------------------------------------------------------------------ */
	console.log( '2. Stockage v2 et migration v1' );
	/* ------------------------------------------------------------------ */
	const erreurs2 = [];
	const page2 = await ouvrirBanc( contexte, JEU, erreurs2 );
	const sto = await page2.evaluate( () => {
		const S = window.ynWordEndMoteur.stockage;
		const brut = () => JSON.parse( localStorage.getItem( 'yn.wordend' ) );
		const s = {};
		localStorage.setItem( 'yn.wordend', JSON.stringify( { meilleur: 1234, parties: 7, maj: '2026-01-02', volume: 0.3, muet: true, autre: 'garde' } ) );
		s.meilleur = S.progression( 'sukasuka' ).arcade.meilleur;
		s.parties = S.progression( 'sukasuka' ).parties;
		s.son = S.reglagesSon();
		s.apres = brut();
		S.migrer();
		S.migrer();
		s.idempotent = JSON.stringify( brut() ) === JSON.stringify( s.apres );
		s.structure = S.progression( 'autre-univers' );
		S.enregistrerResultat( 'sukasuka', 'arcade', { score: 1000 } );
		s.garde = S.progression( 'sukasuka' ).arcade.meilleur;
		S.enregistrerResultat( 'sukasuka', 'arcade', { score: 2000 } );
		s.nouveau = S.progression( 'sukasuka' ).arcade.meilleur;
		S.enregistrerResultat( 'sukasuka', '01-plage', { score: 500, etoiles: 2, fini: true } );
		S.enregistrerResultat( 'sukasuka', '01-plage', { score: 300, etoiles: 3, fini: false } );
		S.enregistrerResultat( 'sukasuka', '02-dunes', { score: 90, etoiles: 0, fini: false } );
		s.plage = S.progression( 'sukasuka' ).niveaux[ '01-plage' ];
		s.dunes = S.progression( 'sukasuka' ).niveaux[ '02-dunes' ];
		s.partiesApres = S.progression( 'sukasuka' ).parties;
		S.choisirPersonnage( 'sukasuka', 'nephren' );
		s.personnage = S.progression( 'sukasuka' ).personnage;
		const univers = {
			ordre: { niveaux: [ 'arcade', '01-plage', '02-dunes', '03-falaise' ], personnages: [ 'chtholly', 'nephren', 'ithea' ] },
			niveaux: {},
			personnages: { chtholly: {}, nephren: { debloque: { niveau: '03-falaise' } }, ithea: { debloque: true } },
		};
		s.debloques = S.progression( 'sukasuka', univers ).debloques;
		s.debloquesSansUnivers = S.progression( 'sukasuka' ).debloques;
		S.enregistrerSon( { volume: 0.8, muet: false } );
		s.final = brut();
		// Données illisibles : pas d'exception, structure complète.
		localStorage.setItem( 'yn.wordend', '{pas du json' );
		s.illisible = S.progression( 'sukasuka' );
		s.sonIllisible = S.reglagesSon();
		localStorage.removeItem( 'yn.wordend' );
		s.vide = S.progression( 'sukasuka' );
		s.rienEcrit = localStorage.getItem( 'yn.wordend' ) === null;
		return s;
	} );
	verifier( sto.meilleur === 1234 && sto.parties === 7, 'v1 → v2 : record et parties conservés', sto );
	verifier( sto.son.volume === 0.3 && sto.son.muet === true, 'v1 → v2 : réglages du son conservés', sto.son );
	verifier( sto.apres.version === 2 && ! ( 'meilleur' in sto.apres ) && ! ( 'parties' in sto.apres ) && sto.apres.autre === 'garde' && sto.apres.maj === '2026-01-02', 'v2 écrit, champs v1 racine retirés, champs inconnus gardés', sto.apres );
	verifier( sto.apres.univers.sukasuka.arcade.meilleur === 1234, 'univers.sukasuka.arcade.meilleur', sto.apres.univers );
	verifier( sto.idempotent, 'migrer() idempotent' );
	verifier( sto.structure.parties === 0 && sto.structure.arcade.meilleur === 0 && typeof sto.structure.niveaux === 'object' && sto.structure.personnage === '' && Array.isArray( sto.structure.debloques.niveaux ), 'progression : structure complète pour un univers inconnu', sto.structure );
	verifier( sto.garde === 1234 && sto.nouveau === 2000, 'arcade : meilleur score gardé' );
	verifier( sto.plage.meilleur === 500 && sto.plage.etoiles === 3 && sto.plage.fini === true, 'niveau : meilleur, max d’étoiles, fini monotone', sto.plage );
	verifier( sto.dunes.fini === false && sto.dunes.meilleur === 90, 'niveau non fini enregistré', sto.dunes );
	verifier( sto.partiesApres === 7 + 5, 'parties comptées', sto.partiesApres );
	verifier( sto.personnage === 'nephren', 'choisirPersonnage' );
	verifier( sto.debloques.niveaux.join() === 'arcade,01-plage,02-dunes' && sto.debloques.personnages.join() === 'chtholly,ithea', 'déblocages (avec l’univers)', sto.debloques );
	verifier( sto.debloquesSansUnivers.niveaux.join() === 'arcade,01-plage', 'déblocages (sans l’univers : arcade + finis)', sto.debloquesSansUnivers );
	verifier( sto.final.volume === 0.8 && sto.final.muet === false && sto.final.univers.sukasuka.arcade.meilleur === 2000, 'enregistrerSon garde la progression', sto.final );
	verifier( sto.illisible.arcade.meilleur === 0 && sto.sonIllisible.volume === 0.5, 'données illisibles : valeurs par défaut' );
	verifier( sto.vide.parties === 0 && sto.rienEcrit, 'lecture sans données : rien n’est écrit' );
	verifier( erreurs2.length === 0, 'aucune erreur console', erreurs2 );
	await page2.close();

	/* ------------------------------------------------------------------ */
	console.log( '3. Rendu (caméra, décor, parallaxe, voile, plateformes) et audio' );
	/* ------------------------------------------------------------------ */
	const erreurs3 = [];
	const page3 = await ouvrirBanc( contexte, JEU, erreurs3 );
	const ren = await page3.evaluate( async ( manifeste ) => {
		const W = window.ynWordEndMoteur;
		const s = {};
		const u = await W.ressources.chargerUnivers( { manifeste: manifeste, version: 'rendu' } );
		const toile = document.createElement( 'canvas' );
		toile.width = 960;
		toile.height = 540;
		const r = W.rendu.creer( toile );
		r.mouvementReduit = false;
		// Caméra : largeur 1200, joueur en 900 → 660 après convergence (lissage 0,15).
		const monde = W.creerMonde( u, { largeur: 1200, sol: 238, voile: 'rgba(255,0,0,0.5)', plateformes: [ { x: 700, y: 180, l: 96 }, { x: 860, y: 150, l: 64, type: 'solide' } ] }, u.personnages.chtholly );
		monde.joueur = { x: 900, y: 238 };
		r.camera( monde );
		s.premierPas = monde.camera.x;
		for ( let i = 0; i < 300; i++ ) {
			r.camera( monde );
		}
		s.converge = monde.camera.x;
		monde.joueur.x = 100;
		for ( let i = 0; i < 300; i++ ) {
			r.camera( monde );
		}
		s.bordGauche = monde.camera.x;
		monde.joueur.x = 5000;
		monde.mouvementReduit = true;
		r.camera( monde );
		s.reduit = monde.camera.x;
		monde.mouvementReduit = false;
		const etroit = W.creerMonde( u, { largeur: 480 }, u.personnages.chtholly );
		etroit.joueur = { x: 470 };
		r.camera( etroit );
		s.etroit = etroit.camera.x;

		// Décor + voile rouge + plateformes, caméra en 660.
		monde.camera.x = 660;
		r.commencer( monde );
		r.decor( u.decors.dunes, monde.camera, 0.25, { sol: monde.sol } );
		r.plateformes( monde );
		r.finir();
		const px = ( x, y ) => Array.from( toile.getContext( '2d' ).getImageData( x * 2, y * 2, 1, 1 ).data );
		s.voile = px( 10, 20 );
		s.plateforme = px( 700 - 660 + 48, 183 );
		s.solide = px( 860 - 660 + 32, 200 );
		s.image = toile.toDataURL( 'image/png' );

		// Parallaxe : le décor défile à 0,25 × la caméra (sans voile).
		const sansVoile = W.creerMonde( u, { largeur: 2000 }, u.personnages.chtholly );
		const ligne = ( cx, p ) => {
			sansVoile.camera.x = cx;
			r.commencer( sansVoile );
			r.decor( u.decors.dunes, sansVoile.camera, p, { sol: 238 } );
			r.finir();
			return Array.from( toile.getContext( '2d' ).getImageData( 0, 300, 960, 1 ).data );
		};
		const egal = ( a, b ) => a.every( ( v, i ) => v === b[ i ] );
		const decale = ( a, b, n ) => {
			// b décalé de n px d'écran (densité 2) vers la gauche par rapport à a.
			for ( let x = 0; x < 960 - n; x++ ) {
				for ( let c = 0; c < 3; c++ ) {
					if ( Math.abs( a[ ( x + n ) * 4 + c ] - b[ x * 4 + c ] ) > 2 ) {
						return false;
					}
				}
			}
			return true;
		};
		const a0 = ligne( 0, 0.25 );
		const a1 = ligne( 80, 0.25 );
		s.parallaxe = decale( a0, a1, 40 ); // 80 × 0,25 = 20 px logiques = 40 px d'écran.
		s.fixe = egal( ligne( 0, 0 ), ligne( 300, 0 ) );
		r.mouvementReduit = true;
		s.reduitFixe = egal( ligne( 0, 0.25 ), ligne( 300, 0.25 ) );
		r.mouvementReduit = false;
		// Répétition au-delà de 480 : pas de trou (pixel opaque au bord droit, caméra lointaine).
		const loin = ligne( 1500, 1 );
		s.repete = loin[ 959 * 4 + 3 ] === 255 && loin[ 3 ] === 255;
		// Décor absent : ciel et sol du thème.
		r.commencer( null );
		r.decor( null, null, 0, { sol: 238 } );
		r.finir();
		s.secours = px( 240, 250 );

		// Sprite : cadre absent sans exception.
		r.commencer( null );
		r.sprite( u.personnages.chtholly.planche, 'repos', 999, 10, 10, 1 );
		r.sprite( u.personnages.chtholly.planche, 'inexistante', 0, 10, 10, 1 );
		r.finir();
		s.spriteSur = true;

		// Particules : plafond 300.
		const mp = W.creerMonde( u, {}, null );
		for ( let i = 0; i < 400; i++ ) {
			W.rendu.ajouterParticule( mp, 0, 0, 0, 0, 1, '#fff', 2, false );
		}
		s.particules = mp.particules.length;
		W.rendu.mettreAJourParticules( mp, 2 );
		s.particulesFin = mp.particules.length;

		// Audio : réglages mémorisés, événement, changement de piste.
		const evenements = [];
		W.evenements.sur( 'son:changement', ( d ) => evenements.push( d ) );
		const audio = W.audio.creer();
		audio.regler( { volume: 0.4, muet: false } );
		s.sonMemorise = W.stockage.reglagesSon();
		s.evenement = evenements[ evenements.length - 1 ];
		audio.musique( u.musiques.principale.url );
		s.piste1 = audio.etat();
		audio.musique( u.musiques.principale.url + '&autre=1' );
		s.piste2 = audio.etat();
		audio.musique( null );
		s.arret = audio.etat();
		audio.regler( { muet: true } );
		audio.reprendre();
		s.muet = audio.etat();
		audio.regler( { muet: false, volume: 0.5 } );
		audio.pauser();
		return s;
	}, MANIFESTE );
	fs.writeFileSync( path.join( CAPTURES, 'rendu-A.png' ), Buffer.from( ren.image.split( ',' )[ 1 ], 'base64' ) );
	delete ren.image;
	verifier( ren.premierPas > 0 && ren.premierPas < 660, 'caméra lissée (premier pas partiel)', ren.premierPas );
	verifier( ren.converge === 660, 'caméra : largeur 1200, joueur en 900 → 660', ren.converge );
	verifier( ren.bordGauche === 0 && ren.reduit === 720 && ren.etroit === 0, 'caméra bornée ; sans lissage en mouvement réduit ; fixe si largeur 480', ren );
	verifier( ren.voile[ 0 ] > ren.voile[ 2 ] + 40, 'voile du niveau (rouge) posé sur le décor', ren.voile );
	verifier( ren.plateforme[ 3 ] === 255 && ren.solide[ 3 ] === 255, 'plateformes traversable et solide dessinées', [ ren.plateforme, ren.solide ] );
	verifier( ren.parallaxe, 'parallaxe : décor décalé de camera.x × 0,25' );
	verifier( ren.fixe && ren.reduitFixe, 'parallaxe 0 et mouvement réduit : décor fixe' );
	verifier( ren.repete, 'décor répété sur un niveau large' );
	verifier( ren.secours[ 3 ] === 255, 'décor de secours (thème)' );
	verifier( ren.spriteSur, 'sprite : cadre ou animation absents ignorés' );
	verifier( ren.particules === 300 && ren.particulesFin === 0, 'particules : plafond 300, expiration', ren.particules );
	verifier( ren.sonMemorise.volume === 0.4 && ren.evenement && ren.evenement.volume === 0.4, 'audio.regler : mémorisé et son:changement', ren.sonMemorise );
	verifier( /scarborough/.test( ren.piste1.url ) && ren.piste1.voulue, 'audio.musique(url) : piste choisie', ren.piste1 );
	verifier( /autre=1/.test( ren.piste2.url ), 'audio.musique(autre url) : piste remplacée', ren.piste2 );
	verifier( ! ren.arret.voulue && ! ren.arret.joue, 'audio.musique(null) : pause', ren.arret );
	verifier( ! ren.muet.joue, 'audio muet : pas de lecture', ren.muet );
	verifier( erreurs3.length === 0, 'aucune erreur console', erreurs3 );
	await page3.close();

	/* ------------------------------------------------------------------ */
	console.log( '4. Arcade jouable (stubs des autres lots)' );
	/* ------------------------------------------------------------------ */
	const erreurs4 = [];
	const page4 = await ouvrirBanc( contexte, JEU, erreurs4 );
	await page4.keyboard.press( 'Enter' );
	await page4.waitForFunction( () => window.ynWordEndMoteur.debug.etat() === 'jeu', null, { timeout: 5000 } );
	for ( let i = 0; i < 40; i++ ) {
		await page4.keyboard.down( i % 8 < 4 ? 'ArrowRight' : 'ArrowLeft' );
		await page4.keyboard.press( 'KeyJ' );
		await page4.waitForTimeout( 150 );
		await page4.keyboard.up( i % 8 < 4 ? 'ArrowRight' : 'ArrowLeft' );
	}
	const jeu = await page4.evaluate( () => {
		const d = window.ynWordEndMoteur.debug;
		const m = d.monde();
		return { etat: d.etat(), ips: d.ips, temps: m.temps, camera: m.camera.x, ennemis: m.ennemis.length, score: m.score, pv: m.joueur.pv };
	} );
	await page4.screenshot( { path: path.join( CAPTURES, 'banc-A.png' ) } );
	verifier( [ 'jeu', 'fin' ].includes( jeu.etat ) && jeu.temps > 4, 'partie en cours', jeu );
	verifier( jeu.camera === 0, 'arcade (largeur 480) : caméra fixe', jeu.camera );
	verifier( jeu.ennemis > 0 || jeu.score > 0, 'vagues de Timeres', jeu );
	verifier( erreurs4.length === 0, 'aucune erreur console', erreurs4 );
	await page4.close();

	/* ------------------------------------------------------------------ */
	console.log( '5. Niveau large (factice) : caméra, parallaxe, voile, plateformes en jeu' );
	/* ------------------------------------------------------------------ */
	const erreurs5 = [];
	await contexte.route( '**/niveaux/01-plage.json*', ( route ) => route.fulfill( {
		contentType: 'application/json',
		body: JSON.stringify( {
			version: 2, slug: '01-plage', numero: 1, titre: 'Plage (factice lot A)', decor: 'dunes', musique: 'principale',
			largeur: 1440, sol: 238, gravite: 900, voile: 'rgba(20,10,60,0.35)',
			plateformes: [ { x: 300, y: 190, l: 96 }, { x: 560, y: 160, l: 64 }, { x: 900, y: 180, l: 120, type: 'solide' }, { x: 1200, y: 170, l: 80 } ],
			apparition: { x: 240 }, objectif: { type: 'arcade', ennemi: 'timere' },
			textes: { intro: 'Niveau factice.', vague: 'Vague {n} : {k} Timeres.' },
		} ),
	} ) );
	const page5 = await ouvrirBanc( contexte, BANC + '?univers=sukasuka&niveau=01-plage&personnage=chtholly&auto=1', erreurs5 );
	await page5.keyboard.press( 'Enter' );
	await page5.waitForFunction( () => window.ynWordEndMoteur.debug.etat() === 'jeu', null, { timeout: 5000 } );
	await page5.keyboard.down( 'ShiftLeft' );
	await page5.keyboard.down( 'ArrowRight' );
	await page5.waitForTimeout( 4000 );
	await page5.keyboard.up( 'ArrowRight' );
	await page5.keyboard.up( 'ShiftLeft' );
	const large = await page5.evaluate( () => {
		const m = window.ynWordEndMoteur.debug.monde();
		return { x: m.joueur.x, camera: m.camera.x, largeur: m.largeur, niveau: m.niveau.slug };
	} );
	await page5.screenshot( { path: path.join( CAPTURES, 'banc-A-large.png' ) } );
	verifier( large.niveau === '01-plage' && large.largeur === 1440, 'niveau large chargé', large );
	verifier( large.camera > 100 && Math.abs( large.camera - Math.min( 960, Math.max( 0, large.x - 240 ) ) ) < 40, 'la caméra suit le joueur', large );
	verifier( erreurs5.length === 0, 'aucune erreur console', erreurs5 );
	await page5.close();

	await navigateur.close();
	console.log( echecs ? '\n' + echecs + ' échec(s).' : '\nLot A : tout est vert.' );
	process.exit( echecs ? 1 : 0 );
} )().catch( ( erreur ) => {
	console.error( erreur );
	process.exit( 1 );
} );
