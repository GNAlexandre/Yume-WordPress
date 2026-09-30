/**
 * WordEnd — moteur : joueur générique piloté par le JSON du personnage.
 *
 * États : repos, marche, course, saut, chute (lot B), attaque (compétence principale),
 * competence (secondaire), degats, mort. Le joueur est son propre corps physique (j.corps === j).
 * Commandes lues : gauche, droite, courir (+ in.courirTactile), epee (impulsion → principale),
 * competence (maintenue → secondaire), saut (lot B).
 *
 * Lot 0 : reproduit Chtholly v1 (vitesses, PV, invincibilité 1,2 s, recul 150, dégâts 0,35 s,
 * mort 2,2 s). Interface : docs/wordend-formats.md.
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

	/* Bouton associé à chaque emplacement de compétence. */
	var BOUTONS = { principale: 'epee', secondaire: 'competence' };

	function creer( personnage, monde, entrees ) {
		var boite = personnage.boite || { l: 20, h: 56 };
		var j = ynWE.physique.corps( { x: ynWE.LARGEUR / 2, y: monde.sol, l: boite.l, h: boite.h } );
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

		j.mettreAJour = function ( dt ) {
			var appui = impulsion( 'epee' );
			impulsion( 'saut' ); // Lot B : saut.
			var libre = j.etat === 'repos' || j.etat === 'marche' || j.etat === 'course';
			j.t += dt;
			j.invincible = Math.max( 0, j.invincible - dt );
			Object.keys( j.recharges ).forEach( function ( cle ) {
				j.recharges[ cle ] = Math.max( 0, j.recharges[ cle ] - dt );
			} );

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
				if ( ! ( appui && lancer( 'principale' ) ) && ! ( commande( 'competence' ) && lancer( 'secondaire' ) ) ) {
					var sens = ( commande( 'droite' ) ? 1 : 0 ) - ( commande( 'gauche' ) ? 1 : 0 );
					var court = commande( 'courir' ) || !! ( entrees && entrees.courirTactile );
					if ( sens !== 0 ) {
						j.dir = sens;
						j.vx = sens * ( court ? personnage.vitesseCourse || 138 : personnage.vitesseMarche || 72 );
						var voulu = court ? 'course' : 'marche';
						if ( j.etat !== voulu ) {
							j.changerEtat( voulu );
						}
					} else {
						j.vx = 0;
						if ( j.etat !== 'repos' ) {
							j.changerEtat( 'repos' );
						}
					}
				}
			}

			ynWE.physique.appliquer( j, dt, monde, { gravite: true, plateformes: true, bords: true } );
		};

		/* Corps touchable (v1 : 20 × 56, 2 px au-dessus du sol). */
		j.boite = function () {
			return { x: j.x - j.l / 2, y: j.y - j.h - 2, l: j.l, h: j.h };
		};

		/* Encaisse un coup venu du côté sens (±1). Renvoie vrai si le coup a porté. */
		j.blesser = function ( sens, degats ) {
			if ( j.etat === 'mort' || j.invincible > 0 ) {
				return false;
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
			r.ombre( j.x, monde.sol + 1, anim[ 0 ] === 'mort' ? 34 : 18, 4, 0.25 );
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
	};
}() );
