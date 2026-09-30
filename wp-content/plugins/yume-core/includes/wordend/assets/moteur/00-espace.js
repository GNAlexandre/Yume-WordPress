/**
 * WordEnd — moteur, fichier 00 : espace de noms window.ynWordEndMoteur (alias interne ynWE),
 * constantes de l'écran, outils, bus d'événements, création du monde, lecture des planches.
 *
 * Premier des scripts du moteur (ordre : configuration()['scripts'], voir fonctions.php) ;
 * chaque fichier suivant enrichit ynWE. Fichier FIGÉ : toute modification passe par
 * l'intégrateur. Contrat des interfaces : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur = window.ynWordEndMoteur || {};

	/* Écran logique (px logiques) et densité : la planche est dessinée pour 2×. */
	ynWE.LARGEUR = 480;
	ynWE.HAUTEUR = 270;
	ynWE.DENSITE = 2;
	ynWE.DT = 1 / 60;

	/* ------------------------------------------------------------------ */
	/* Outils                                                              */
	/* ------------------------------------------------------------------ */

	ynWE.hasard = function ( min, max ) {
		return min + Math.random() * ( max - min );
	};

	/* Recouvrement de deux boîtes {x, y, l, h}. */
	ynWE.chevauche = function ( a, b ) {
		return a.x < b.x + b.l && a.x + a.l > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
	};

	ynWE.limiter = function ( valeur, min, max ) {
		return Math.max( min, Math.min( max, valeur ) );
	};

	/* ------------------------------------------------------------------ */
	/* Événements                                                          */
	/* ------------------------------------------------------------------ */

	/*
	 * Bus d'événements interne (pas d'événement DOM). Noms : 'annonce' {texte}, 'score' {delta},
	 * 'secousse' {duree}, 'joueur:blesse' {pv}, 'joueur:mort', 'ennemi:mort' {ennemi, points},
	 * 'vague:debut' {numero, total}, 'niveau:fin' {resultat, score, etoiles, temps, pv},
	 * 'partie:etat' {etat}, 'son:changement' {volume, muet}.
	 */
	var abonnes = {};
	ynWE.evenements = {
		sur: function ( nom, fn ) {
			( abonnes[ nom ] = abonnes[ nom ] || [] ).push( fn );
		},
		retirer: function ( nom, fn ) {
			abonnes[ nom ] = ( abonnes[ nom ] || [] ).filter( function ( f ) {
				return f !== fn;
			} );
		},
		emettre: function ( nom, detail ) {
			( abonnes[ nom ] || [] ).slice().forEach( function ( fn ) {
				fn( detail || {}, nom );
			} );
		},
	};

	/* Annonce aux lecteurs d'écran (zone role="status" de la modale). */
	ynWE.annoncer = function ( texte ) {
		ynWE.evenements.emettre( 'annonce', { texte: texte } );
	};

	/* Secousse de l'écran (ignorée en mouvement réduit). */
	ynWE.secouer = function ( monde, duree ) {
		if ( monde && ! monde.mouvementReduit ) {
			monde.secousse = Math.max( monde.secousse, duree );
			ynWE.evenements.emettre( 'secousse', { duree: duree } );
		}
	};

	/* Ajoute des points au score du monde. */
	ynWE.ajouterScore = function ( monde, delta ) {
		monde.score += delta;
		ynWE.evenements.emettre( 'score', { delta: delta } );
	};

	/* ------------------------------------------------------------------ */
	/* Monde                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * État d'une partie, partagé par tous les modules (données seulement).
	 *
	 * @param {Object} univers    Univers chargé (ressources.chargerUnivers).
	 * @param {Object} niveau     JSON du niveau.
	 * @param {Object} personnage Personnage chargé (JSON + planche).
	 * @return {Object} monde
	 */
	ynWE.creerMonde = function ( univers, niveau, personnage ) {
		niveau = niveau || {};
		return {
			univers: univers,
			niveau: niveau,
			personnage: personnage,
			joueur: null,
			ennemis: [],
			projectiles: [],
			ondes: [],
			particules: [],
			camera: { x: 0, y: 0 },
			temps: 0,
			score: 0,
			secousse: 0,
			mouvementReduit: false,
			palette: {},
			prochainId: 1,
			sol: typeof niveau.sol === 'number' ? niveau.sol : 238,
			largeur: typeof niveau.largeur === 'number' ? niveau.largeur : ynWE.LARGEUR,
			gravite: typeof niveau.gravite === 'number' ? niveau.gravite : 900,
			plateformes: Array.isArray( niveau.plateformes ) ? niveau.plateformes : [],
		};
	};

	/* ------------------------------------------------------------------ */
	/* Planches (format v1 : {echelle, planche, animations{ips, boucle, coup, onde, images}}) */
	/* ------------------------------------------------------------------ */

	ynWE.animation = function ( meta, nom ) {
		return meta && meta.animations ? meta.animations[ nom ] : undefined;
	};

	/* Indice de l'image à l'instant t (s) : en boucle ou figée sur la dernière. */
	ynWE.imageCourante = function ( meta, nom, t ) {
		var anim = ynWE.animation( meta, nom );
		var n = anim.images.length;
		var i = Math.floor( t * anim.ips );
		return anim.boucle ? i % n : Math.min( n - 1, i );
	};

	ynWE.dureeAnimation = function ( meta, nom ) {
		var anim = ynWE.animation( meta, nom );
		return anim.images.length / anim.ips;
	};

	/* ------------------------------------------------------------------ */
	/* Débogage (tests, banc) : la modale branche sa source.               */
	/* ------------------------------------------------------------------ */

	ynWE.debug = {
		source: null,
		etat: function () {
			return ynWE.debug.source ? ynWE.debug.source.etat() : 'ferme';
		},
		monde: function () {
			return ynWE.debug.source ? ynWE.debug.source.monde() : null;
		},
		partie: function () {
			return ynWE.debug.source && ynWE.debug.source.partie ? ynWE.debug.source.partie() : null;
		},
		ips: 0,
	};
}() );
