/**
 * WordEnd — moteur : niveau (monde, joueur, objectif, fin de partie, étoiles).
 *
 * ynWE.niveau.demarrer(univers, niveauJson, personnage, entrees) → partie. La partie avance le
 * joueur, les ennemis, les ondes, les projectiles et l'objectif ; elle émet 'niveau:fin' une
 * seule fois (perdu : joueur mort depuis 2,2 s ; gagne : objectif atteint).
 *
 * Lot 0 : objectif « arcade » (vagues v1 : 3 + 2n Timeres, bonus 50n, soin toutes les
 * 2 vagues) ; vagues, survie et boss au lot C. Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var hasard = ynWE.hasard;

	/* Remplace {n}, {k}… dans un texte du niveau. */
	function gabarit( texte, valeurs ) {
		return String( texte ).replace( /\{(\w+)\}/g, function ( tout, cle ) {
			return Object.prototype.hasOwnProperty.call( valeurs, cle ) ? String( valeurs[ cle ] ) : tout;
		} );
	}

	/* Définition d'ennemi d'un objectif (champ ennemi, sinon la première de l'univers). */
	function definitionEnnemi( partie, slug ) {
		var ennemis = partie.monde.univers.ennemis;
		return ennemis[ slug ] || ennemis[ Object.keys( ennemis )[ 0 ] ];
	}

	/* ------------------------------------------------------------------ */
	/* Objectif arcade (générateur v1, à l'identique)                       */
	/* ------------------------------------------------------------------ */

	var arcade = {
		demarrer: function ( partie ) {
			partie.vague = { numero: 0, aFaire: 0, minuterie: 1.2, banniere: 0, pause: true };
		},
		nouvelleVague: function ( partie ) {
			var vague = partie.vague;
			vague.numero++;
			vague.aFaire = 3 + 2 * vague.numero;
			vague.minuterie = 0.8;
			vague.banniere = 2;
			vague.pause = false;
			var textes = partie.niveau.textes || {};
			ynWE.annoncer( gabarit( textes.vague || 'Vague {n} : {k} ennemis.', { n: vague.numero, k: vague.aFaire } ) );
			ynWE.evenements.emettre( 'vague:debut', { numero: vague.numero, total: null } );
		},
		faireApparaitre: function ( partie ) {
			var monde = partie.monde;
			var n = partie.vague.numero;
			var choix = [ 'petit', 'petit', 'normal', 'normal' ];
			if ( n >= 2 ) {
				choix.push( 'coureur', 'normal' );
			}
			if ( n >= 3 && ! monde.ennemis.some( function ( e ) {
				return e.typeNom === 'grand' && e.etat !== 'mort';
			} ) ) {
				choix.push( 'grand' );
			}
			var type = choix[ Math.floor( Math.random() * choix.length ) ];
			var gauche = Math.random() < 0.5;
			monde.ennemis.push( ynWE.ennemis.creer( definitionEnnemi( partie, partie.niveau.objectif.ennemi ), type, {
				x: gauche ? -50 : monde.largeur + 50,
				dir: gauche ? 1 : -1,
				pvBonus: n >= 6 && type === 'normal' ? 1 : 0,
				vitesseFacteur: 1 + Math.min( 0.5, n * 0.04 ),
			}, monde ) );
		},
		mettreAJour: function ( partie, dt ) {
			var vague = partie.vague;
			var joueur = partie.monde.joueur;
			vague.banniere = Math.max( 0, vague.banniere - dt );
			if ( joueur.etat === 'mort' ) {
				return;
			}
			vague.minuterie -= dt;
			if ( vague.pause ) {
				if ( vague.minuterie <= 0 ) {
					arcade.nouvelleVague( partie );
				}
				return;
			}
			if ( vague.aFaire > 0 && vague.minuterie <= 0 ) {
				arcade.faireApparaitre( partie );
				vague.aFaire--;
				vague.minuterie = Math.max( 0.5, 2.2 - 0.15 * vague.numero ) * hasard( 0.7, 1.2 );
			}
			if ( vague.aFaire === 0 && partie.monde.ennemis.length === 0 ) {
				ynWE.ajouterScore( partie.monde, 50 * vague.numero );
				vague.pause = true;
				vague.minuterie = 1.8;
				if ( joueur.pv < joueur.pvMax && vague.numero % 2 === 0 ) {
					joueur.pv++;
				}
			}
		},
		fini: function () {
			return ''; // Le mode arcade ne se gagne pas.
		},
	};

	function aVenir( nom ) {
		return {
			demarrer: ynWE.nonImplemente( 'niveau.objectifs.' + nom ),
			mettreAJour: ynWE.nonImplemente( 'niveau.objectifs.' + nom + '.mettreAJour' ),
			fini: function () {
				return '';
			},
		};
	}

	var objectifs = {
		arcade: arcade,
		vagues: aVenir( 'vagues' ),
		survie: aVenir( 'survie' ),
		boss: aVenir( 'boss' ),
	};

	/* 1 étoile pour finir, +1 si score ≥ etoiles.score, +1 si pv ≥ pvRestants ou temps ≤ temps. */
	function calculerEtoiles( niveauJson, resultat ) {
		if ( ! resultat || resultat.resultat !== 'gagne' ) {
			return 0;
		}
		var cfg = niveauJson.etoiles || {};
		var etoiles = 1;
		if ( typeof cfg.score === 'number' && resultat.score >= cfg.score ) {
			etoiles++;
		}
		if ( ( typeof cfg.pvRestants === 'number' && resultat.pv >= cfg.pvRestants ) || ( typeof cfg.temps === 'number' && resultat.temps <= cfg.temps ) ) {
			etoiles++;
		}
		return etoiles;
	}

	/**
	 * Démarre un niveau.
	 *
	 * @param {Object} univers    Univers chargé.
	 * @param {Object} niveauJson JSON du niveau.
	 * @param {Object} personnage Personnage chargé.
	 * @param {Object} entrees    Entrées (entrees.creer).
	 * @return {Object} partie
	 */
	function demarrer( univers, niveauJson, personnage, entrees ) {
		var monde = ynWE.creerMonde( univers, niveauJson, personnage );
		var joueur = ynWE.joueur.creer( personnage, monde, entrees );
		monde.joueur = joueur;
		if ( niveauJson.apparition && typeof niveauJson.apparition.x === 'number' ) {
			joueur.x = niveauJson.apparition.x;
		}
		var typeObjectif = ( niveauJson.objectif && niveauJson.objectif.type ) || 'arcade';
		var objectif = objectifs[ typeObjectif ];
		if ( ! objectif ) {
			throw new Error( 'objectif inconnu : ' + typeObjectif );
		}

		var partie = {
			monde: monde,
			niveau: niveauJson,
			etat: 'enCours',
			objectif: objectif,
		};

		function terminer( resultat ) {
			partie.etat = resultat === 'gagne' ? 'gagne' : 'perdu';
			var bilan = {
				resultat: partie.etat,
				score: monde.score,
				temps: monde.temps,
				pv: joueur.pv,
				niveau: niveauJson.slug,
			};
			bilan.etoiles = calculerEtoiles( niveauJson, bilan );
			ynWE.evenements.emettre( 'niveau:fin', bilan );
		}

		partie.mettreAJour = function ( dt ) {
			if ( partie.etat !== 'enCours' ) {
				return;
			}
			monde.temps += dt;
			joueur.mettreAJour( dt, monde );
			monde.ennemis.forEach( function ( e ) {
				ynWE.ennemis.mettreAJour( e, dt, monde );
			} );
			ynWE.ennemis.separer( monde );
			ynWE.ennemis.retirerFinis( monde );
			ynWE.competences.mettreAJourOndes( monde, dt );
			ynWE.ennemis.mettreAJourProjectiles( monde, dt );
			objectif.mettreAJour( partie, dt );
			monde.secousse = Math.max( 0, monde.secousse - dt );
			ynWE.rendu.mettreAJourParticules( monde, dt );
			var fin = objectif.fini( partie ) || ( joueur.estFini() ? 'perdu' : '' );
			if ( fin ) {
				terminer( fin );
			}
		};

		partie.hud = function () {
			var vague = partie.vague || {};
			return {
				vague: vague.numero || 0,
				total: typeof vague.total === 'number' ? vague.total : null,
				objectif: typeObjectif,
				boss: partie.boss ? { pv: partie.boss.pv, pvMax: partie.boss.pvMax, nom: partie.boss.nom || '' } : null,
				temps: monde.temps,
				banniere: vague.banniere || 0,
			};
		};

		objectif.demarrer( partie );
		return partie;
	}

	ynWE.niveau = {
		demarrer: demarrer,
		objectifs: objectifs,
		enregistrerObjectif: function ( nom, impl ) {
			objectifs[ nom ] = impl;
		},
		calculerEtoiles: calculerEtoiles,
		gabarit: gabarit,
	};
}() );
