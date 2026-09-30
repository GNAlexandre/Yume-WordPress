/**
 * WordEnd — moteur : joueur générique piloté par le JSON du personnage.
 *
 * États : repos, marche, course, saut, chute, attaque (compétence principale), competence
 * (secondaire), degats, mort. Le joueur est son propre corps physique (j.corps === j).
 * Commandes lues : gauche, droite, courir (+ in.courirTactile), bas, saut (impulsion ; maintenu :
 * hauteur), epee (impulsion → principale), competence (impulsion ou maintenue → secondaire : un
 * appui bref, même relâché dans la même image, déclenche parade et ruée ; l'onde démarre sa
 * concentration sur l'appui et ne part que si la touche est tenue au moins chargeMin).
 *
 * Reproduit Chtholly v1 en arcade (vitesses, PV, invincibilité 1,2 s, recul 150, dégâts 0,35 s,
 * mort 2,2 s) et ajoute le saut : impulsion personnage.saut.impulsion, sautsMax sauts (double saut
 * si 2), tolérance de 0,08 s après avoir quitté un bord, saut écourté (vy × 0,5) si la touche
 * maintenue au départ est relâchée pendant la montée, bas + saut sur une plateforme traversable :
 * descente. Interface : docs/wordend-formats.md (§3.8).
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var hasard = ynWE.hasard;

	var INVINCIBILITE = 1.2;
	var DUREE_DEGATS = 0.35;
	var RECUL = 150;
	var DUREE_MORT = 2.2;
	var COYOTE = 0.08;
	var DUREE_TRAVERSE = 0.25;
	var COUPE_SAUT = 0.5;

	/* Bouton associé à chaque emplacement de compétence. */
	var BOUTONS = { principale: 'epee', secondaire: 'competence' };

	/* États où le joueur est libre de ses mouvements. */
	var LIBRES = { repos: true, marche: true, course: true, saut: true, chute: true };

	function creer( personnage, monde, entrees ) {
		var boite = personnage.boite || { l: 20, h: 56 };
		var saut = personnage.saut || {};
		var impulsionSaut = typeof saut.impulsion === 'number' ? saut.impulsion : 330;
		var sautsMax = typeof saut.sautsMax === 'number' ? saut.sautsMax : 1;
		var j = ynWE.physique.corps( { x: ynWE.LARGEUR / 2, y: monde.sol, l: boite.l, h: boite.h, auSol: true } );
		j.corps = j;
		j.personnage = personnage;
		j.planche = personnage.planche;
		j.entrees = entrees;
		j.dir = 1;
		j.etat = 'repos';
		j.t = 0;
		j.pvMax = personnage.pv || 5;
		j.pv = j.pvMax;
		j.invincible = 0;
		j.recharges = { principale: 0, secondaire: 0 };
		j.touches = [];
		j.emplacement = '';
		j.phase = '';
		j.pare = false;
		j.sautsFaits = 0;
		j.enLair = 0; // Temps passé hors du sol (s).
		j.sautMaintenu = false; // Saut lancé touche maintenue : relâcher l'écourte.

		function commande( nom ) {
			return !! ( entrees && entrees.commande( nom ) );
		}

		function impulsion( nom ) {
			return !! ( entrees && entrees.impulsion( nom ) );
		}

		function competence( emplacement ) {
			var def = ( personnage.competences || {} )[ emplacement ];
			return def ? { def: def, impl: ynWE.competences[ def.type ] } : null;
		}

		j.changerEtat = function ( nouvel ) {
			j.etat = nouvel;
			j.t = 0;
			j.pare = false;
			if ( nouvel !== 'attaque' && nouvel !== 'competence' ) {
				j.emplacement = '';
				j.phase = '';
			}
		};

		function lancer( emplacement ) {
			var c = competence( emplacement );
			if ( ! c || ! c.impl || ( j.recharges[ emplacement ] || 0 ) > 0 ) {
				return false;
			}
			j.emplacement = emplacement;
			if ( c.impl.demarrer( j, monde, c.def ) ) {
				return true;
			}
			j.emplacement = '';
			return false;
		}

		function sauter() {
			j.vy = -impulsionSaut;
			j.sautsFaits++;
			j.auSol = false;
			j.support = null;
			j.enLair = COYOTE;
			j.sautMaintenu = commande( 'saut' );
			j.changerEtat( 'saut' );
		}

		/* Impulsion de saut : descente d'une plateforme, saut depuis le sol ou saut en l'air. */
		function gererSaut() {
			if ( commande( 'bas' ) && j.auSol && j.support && ! ynWE.physique.estSolide( j.support ) ) {
				j.traverse = DUREE_TRAVERSE;
				j.auSol = false;
				j.support = null;
				return;
			}
			if ( j.auSol || ( j.enLair < COYOTE && j.sautsFaits === 0 ) ) {
				sauter();
			} else if ( j.sautsFaits < sautsMax ) {
				sauter();
			}
		}

		j.mettreAJour = function ( dt ) {
			var appui = impulsion( 'epee' );
			var appuiCompetence = impulsion( 'competence' ); // Lue à chaque image : pas d'appui périmé.
			var appuiSaut = impulsion( 'saut' );
			var libre = !! LIBRES[ j.etat ];
			var sens = 0;
			j.t += dt;
			j.invincible = Math.max( 0, j.invincible - dt );
			Object.keys( j.recharges ).forEach( function ( cle ) {
				j.recharges[ cle ] = Math.max( 0, j.recharges[ cle ] - dt );
			} );

			if ( j.auSol ) {
				j.enLair = 0;
				j.sautsFaits = 0;
				j.sautMaintenu = false;
			} else {
				j.enLair += dt;
				if ( j.enLair >= COYOTE && j.sautsFaits === 0 ) {
					j.sautsFaits = 1; // Tombé d'un bord : le premier saut est perdu.
				}
			}

			if ( j.etat === 'mort' ) {
				j.vx *= 0.9;
			} else if ( j.etat === 'degats' ) {
				j.vx *= 0.88;
				if ( j.t >= DUREE_DEGATS ) {
					j.changerEtat( 'repos' );
				}
			} else if ( ( j.etat === 'attaque' || j.etat === 'competence' ) && j.emplacement ) {
				var c = competence( j.emplacement );
				c.impl.mettreAJour( j, dt, monde, c.def, { maintenu: commande( BOUTONS[ j.emplacement ] ) } );
			}

			if ( libre ) {
				if ( ! ( appui && lancer( 'principale' ) ) && ! ( ( appuiCompetence || commande( 'competence' ) ) && lancer( 'secondaire' ) ) ) {
					sens = ( commande( 'droite' ) ? 1 : 0 ) - ( commande( 'gauche' ) ? 1 : 0 );
					var court = commande( 'courir' ) || !! ( entrees && entrees.courirTactile );
					if ( sens !== 0 ) {
						j.dir = sens;
						j.vx = sens * ( court ? personnage.vitesseCourse || 138 : personnage.vitesseMarche || 72 );
					} else {
						j.vx = 0;
					}
					if ( appuiSaut ) {
						gererSaut();
					}
				}
			}

			// Saut écourté : touche relâchée pendant la montée.
			if ( j.sautMaintenu && j.vy < 0 && ! commande( 'saut' ) ) {
				j.vy *= COUPE_SAUT;
				j.sautMaintenu = false;
			}

			ynWE.physique.appliquer( j, dt, monde, { gravite: true, plateformes: true, bords: true } );

			if ( LIBRES[ j.etat ] ) {
				var voulu;
				if ( ! j.auSol ) {
					voulu = j.vy < 0 ? 'saut' : 'chute';
				} else if ( j.vx !== 0 && sens !== 0 ) {
					voulu = commande( 'courir' ) || ( entrees && entrees.courirTactile ) ? 'course' : 'marche';
				} else {
					voulu = 'repos';
				}
				if ( j.etat !== voulu ) {
					j.changerEtat( voulu );
				}
			}
		};

		/* Corps touchable (v1 : 20 × 56, 2 px au-dessus du sol). */
		j.boite = function () {
			return { x: j.x - j.l / 2, y: j.y - j.h - 2, l: j.l, h: j.h };
		};

		/*
		 * Encaisse un coup venu du côté sens (±1 : sens du recul). Renvoie vrai si le coup a porté.
		 * Une compétence en cours peut l'absorber (impl.encaisser : parade, ruée).
		 */
		j.blesser = function ( sens, degats ) {
			if ( j.etat === 'mort' || j.invincible > 0 ) {
				return false;
			}
			if ( ( j.etat === 'attaque' || j.etat === 'competence' ) && j.emplacement ) {
				var c = competence( j.emplacement );
				if ( c && c.impl.encaisser && c.impl.encaisser( j, sens, degats || 1, monde, c.def ) ) {
					return false;
				}
			}
			j.pv -= degats || 1;
			j.invincible = INVINCIBILITE;
			j.vx = sens * RECUL;
			ynWE.secouer( monde, 0.25 );
			ynWE.evenements.emettre( 'joueur:blesse', { pv: j.pv } );
			if ( j.pv <= 0 ) {
				j.pv = 0;
				j.changerEtat( 'mort' );
				ynWE.annoncer( ( personnage.textes && personnage.textes.mort ) || ( personnage.nom + ' est à terre.' ) );
				var n = monde.mouvementReduit ? 6 : 24;
				for ( var i = 0; i < n; i++ ) {
					ynWE.rendu.ajouterParticule( monde, j.x + hasard( -30, 30 ), j.y - hasard( 0, 50 ), hasard( -30, 30 ), hasard( -50, -10 ), hasard( 1.2, 2.2 ), i % 2 ? monde.palette.accent || '#f3a6c8' : '#8fd0ff', 3, true );
				}
				ynWE.evenements.emettre( 'joueur:mort', {} );
			} else {
				j.changerEtat( 'degats' );
			}
			return true;
		};

		/* Mort depuis assez longtemps pour finir la partie. */
		j.estFini = function () {
			return j.etat === 'mort' && j.t >= DUREE_MORT;
		};

		/* [animation, indice] : compétence, animation de l'état, sinon pose figée du JSON. */
		j.animationCourante = function () {
			var meta = j.planche.meta;
			if ( ( j.etat === 'attaque' || j.etat === 'competence' ) && j.emplacement ) {
				var c = competence( j.emplacement );
				return c.impl.animation( j, monde, c.def );
			}
			if ( ynWE.animation( meta, j.etat ) ) {
				return [ j.etat, ynWE.imageCourante( meta, j.etat, j.t ) ];
			}
			var pose = personnage.poses && personnage.poses[ j.etat ];
			return pose ? [ pose[ 0 ], pose[ 1 ] ] : [ 'repos', 0 ];
		};

		j.dessiner = function ( r ) {
			var anim = j.animationCourante();
			var surface = ynWE.physique.surfaceSous( monde, j.x, j.y );
			var proche = Math.max( 0.4, 1 - ( surface - j.y ) / 120 ); // Ombre plus petite en l'air.
			r.ombre( j.x, surface + 1, ( anim[ 0 ] === 'mort' ? 34 : 18 ) * proche, 4 * proche, 0.25 * proche );
			var alpha = 1;
			if ( j.invincible > 0 && anim[ 0 ] !== 'mort' ) {
				alpha = monde.mouvementReduit ? 0.6 : ( Math.floor( j.invincible * 12 ) % 2 ? 0.35 : 1 );
			}
			r.sprite( j.planche, anim[ 0 ], anim[ 1 ], j.x, j.y, j.dir, 1, personnage.lissage !== false, alpha );
			if ( ( j.etat === 'attaque' || j.etat === 'competence' ) && j.emplacement ) {
				var c = competence( j.emplacement );
				c.impl.dessiner( r, j, monde, c.def );
			}
		};

		return j;
	}

	ynWE.joueur = {
		creer: creer,
		DUREE_MORT: DUREE_MORT,
		INVINCIBILITE: INVINCIBILITE,
		COYOTE: COYOTE,
	};
}() );
