/**
 * WordEnd — moteur : machine d'écrans (dessin du monde, HUD, surcouches, actions).
 *
 * États : chargement, titre, univers, personnage, niveaux, jeu, pause, fin, victoire, erreur.
 * Chaque changement émet 'partie:etat' {etat}. Actions (ec.surAction) : echap, pause, valider,
 * epee, jouer, tactile, muet, gauche, droite, haut, bas.
 *
 * Lot 0 : titre, jeu (HUD v1), pause, fin et erreur réels ; univers, personnage et niveaux sont
 * traversés directement (personnage et niveau par défaut, ou config.depart) ; victoire minimale.
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var LARGEUR = ynWE.LARGEUR;
	var HAUTEUR = ynWE.HAUTEUR;

	/**
	 * Crée la machine d'écrans.
	 *
	 * @param {Object} contexte {r, stockage, config, univers() → univers|null,
	 *                          personnage() → personnage|null, ouvrirUnivers(slug),
	 *                          demarrerNiveau(universSlug, personnageSlug, niveauSlug), fermer()}.
	 */
	function creer( contexte ) {
		var r = contexte.r;
		var ec = {
			etat: 'chargement',
			temps: 0,
			meilleur: 0,
			bilan: null,
		};

		function annoncer( texte ) {
			ynWE.annoncer( texte );
		}

		function univers() {
			return contexte.univers ? contexte.univers() : null;
		}

		function lireRecord() {
			var u = univers();
			ec.meilleur = u ? contexte.stockage.progression( u.slug ).arcade.meilleur : 0;
		}

		/* Choix du personnage et du niveau (lot D : écrans de sélection). */
		function choisirEtDemarrer() {
			var u = univers();
			if ( ! u ) {
				return;
			}
			var depart = ( contexte.config && contexte.config.depart ) || {};
			var personnage = depart.personnage && u.chemins.personnages[ depart.personnage ] ? depart.personnage : u.ordre.personnages[ 0 ];
			var niveau = depart.niveau && u.chemins.niveaux[ depart.niveau ] ? depart.niveau : ( u.chemins.niveaux.arcade ? 'arcade' : u.ordre.niveaux[ 0 ] );
			contexte.demarrerNiveau( u.slug, personnage, niveau );
		}

		ec.aller = function ( etat, donnees ) {
			if ( etat === 'univers' || etat === 'personnage' || etat === 'niveaux' ) {
				// Lot 0 : écrans « à venir », traversés directement.
				choisirEtDemarrer();
				return;
			}
			if ( etat === 'titre' || ( etat === 'jeu' && ec.etat !== 'pause' ) ) {
				lireRecord();
			}
			if ( etat === 'fin' || etat === 'victoire' ) {
				var bilan = donnees || {};
				bilan.record = bilan.score > ec.meilleur;
				ec.meilleur = Math.max( ec.meilleur, bilan.score || 0 );
				ec.bilan = bilan;
				if ( etat === 'fin' ) {
					annoncer( 'Fin de partie. Score : ' + bilan.score + ( bilan.record ? '. Nouveau record !' : '. Meilleur score : ' + ec.meilleur + '.' ) + ' Appuyez sur Entrée pour rejouer.' );
				} else {
					annoncer( 'Victoire ! Score : ' + bilan.score + '. Étoiles : ' + ( bilan.etoiles || 0 ) + ' sur 3.' );
				}
			}
			ec.etat = etat;
			ynWE.evenements.emettre( 'partie:etat', { etat: etat } );
		};

		ec.surAction = function ( action ) {
			var etat = ec.etat;
			if ( action === 'echap' ) {
				if ( etat === 'jeu' ) {
					ec.aller( 'pause' );
					annoncer( 'Pause. Échap de nouveau pour fermer le jeu, P ou Entrée pour reprendre.' );
				} else {
					contexte.fermer();
				}
			} else if ( action === 'pause' ) {
				if ( etat === 'jeu' ) {
					ec.aller( 'pause' );
					annoncer( 'Pause.' );
				} else if ( etat === 'pause' ) {
					ec.aller( 'jeu' );
					annoncer( 'Reprise.' );
				}
			} else if ( action === 'valider' ) {
				if ( etat === 'titre' || etat === 'fin' || etat === 'victoire' ) {
					ec.aller( 'personnage' );
				} else if ( etat === 'pause' ) {
					ec.aller( 'jeu' );
					annoncer( 'Reprise.' );
				}
			} else if ( action === 'epee' ) {
				if ( etat === 'titre' ) {
					ec.aller( 'personnage' );
				}
			} else if ( action === 'tactile' ) {
				if ( etat === 'titre' || etat === 'fin' ) {
					ec.aller( 'personnage' );
				}
			} else if ( action === 'jouer' ) {
				if ( etat !== 'chargement' && etat !== 'erreur' ) {
					ec.aller( 'personnage' );
				}
			}
		};

		/* HUD (coordonnées de l'écran). */
		ec.hud = function ( monde, partie ) {
			var j = monde.joueur;
			var p = r.palette;
			var infos = partie ? partie.hud() : { vague: 0, banniere: 0 };
			for ( var i = 0; i < j.pvMax; i++ ) {
				r.coeur( 16 + i * 13, 10, i < j.pv );
			}
			r.texte( 'Score ' + monde.score, LARGEUR / 2, 15, 12, 'center' );
			r.texte( 'Record ' + Math.max( ec.meilleur, monde.score ), LARGEUR - 10, 15, 11, 'right', p.texteFaible );
			if ( infos.vague > 0 ) {
				r.texte( 'Vague ' + infos.vague + ( infos.total ? ' / ' + infos.total : '' ), LARGEUR - 10, 30, 11, 'right', p.texteFaible );
			}
			var secondaire = ( monde.personnage.competences || {} ).secondaire;
			if ( secondaire && secondaire.recharge && j.recharges.secondaire > 0 ) {
				r.jauge( 16, 26, 60, 3, 1 - j.recharges.secondaire / secondaire.recharge, '#8fd0ff' );
			}
			if ( infos.banniere > 0 && ec.etat === 'jeu' ) {
				var decalage = monde.mouvementReduit ? 0 : Math.max( 0, infos.banniere - 1.7 ) * 200;
				r.ctx.globalAlpha = Math.min( 1, infos.banniere * 2 );
				r.texte( 'Vague ' + infos.vague, LARGEUR / 2 - decalage, 96, 26, 'center', p.accent, 800 );
				r.ctx.globalAlpha = 1;
			}
		};

		function surcouche() {
			var p = r.palette;
			var u = univers();
			var manifeste = u ? u.manifeste : {};
			var etat = ec.etat;
			if ( etat === 'titre' ) {
				r.voile();
				r.texte( manifeste.titre || 'WordEnd', LARGEUR / 2, 62, 34, 'center', p.accent, 800 );
				r.texte( manifeste.sousTitre || '', LARGEUR / 2, 94, 15, 'center' );
				var personnage = contexte.personnage ? contexte.personnage() : null;
				if ( personnage ) {
					r.sprite( personnage.planche, 'repos', ynWE.imageCourante( personnage.planche.meta, 'repos', ec.temps ), LARGEUR / 2 - 20, 196, 1, 1, personnage.lissage !== false, 1 );
				}
				r.texte( 'Entrée ou « Jouer » pour commencer', LARGEUR / 2, 222, 12, 'center' );
				r.texte( 'Record : ' + ec.meilleur, LARGEUR / 2, 244, 11, 'center', p.texteFaible );
			} else if ( etat === 'pause' ) {
				r.voile();
				r.texte( 'Pause', LARGEUR / 2, HAUTEUR / 2 - 10, 28, 'center', p.accent, 800 );
				r.texte( 'P ou Entrée pour reprendre · Échap pour fermer', LARGEUR / 2, HAUTEUR / 2 + 18, 12, 'center' );
			} else if ( etat === 'fin' || etat === 'victoire' ) {
				var bilan = ec.bilan || { score: 0 };
				r.voile();
				r.texte( etat === 'fin' ? 'Fin de partie' : 'Victoire !', LARGEUR / 2, 86, 28, 'center', p.accent, 800 );
				r.texte( 'Score : ' + bilan.score, LARGEUR / 2, 122, 16, 'center' );
				if ( etat === 'victoire' ) {
					r.texte( '★★★☆☆☆'.substr( 3 - ( bilan.etoiles || 0 ), 3 ), LARGEUR / 2, 146, 16, 'center', p.accent2 );
				} else {
					r.texte( bilan.score >= ec.meilleur && bilan.score > 0 ? 'Nouveau record !' : 'Record : ' + ec.meilleur, LARGEUR / 2, 146, 12, 'center', p.accent2 );
				}
				r.texte( 'Entrée ou « Rejouer » pour recommencer', LARGEUR / 2, 184, 12, 'center' );
			} else if ( etat === 'chargement' ) {
				r.texte( 'Chargement…', LARGEUR / 2, HAUTEUR / 2, 14, 'center' );
			} else if ( etat === 'erreur' ) {
				r.texte( 'Le jeu n’a pas pu être chargé.', LARGEUR / 2, HAUTEUR / 2, 14, 'center', p.erreur );
			} else if ( etat === 'univers' || etat === 'personnage' || etat === 'niveaux' ) {
				r.voile();
				r.texte( 'Bientôt…', LARGEUR / 2, HAUTEUR / 2, 16, 'center' );
			}
		}

		/* Dessine une image : décor, monde (en coordonnées du monde), HUD, surcouche. */
		ec.dessiner = function ( monde, partie ) {
			var u = univers();
			var etat = ec.etat;
			if ( monde ) {
				r.camera( monde );
			}
			r.commencer( monde );
			var nomDecor = monde && monde.niveau ? monde.niveau.decor : '';
			var decors = u && u.manifeste ? u.manifeste.decors || {} : {};
			r.decor( u && nomDecor ? u.decors[ nomDecor ] : null, monde ? monde.camera : null, decors[ nomDecor ] ? decors[ nomDecor ].parallaxe : 0, { sol: monde ? monde.sol : 238 } );
			if ( monde && monde.joueur ) {
				r.plateformes( monde );
				monde.ennemis.forEach( function ( e ) {
					ynWE.ennemis.dessiner( r, e, monde );
				} );
				if ( etat !== 'titre' ) {
					monde.joueur.dessiner( r, monde );
				}
				ynWE.competences.dessinerOndes( r, monde );
				ynWE.ennemis.dessinerProjectiles( r, monde );
				r.particules( monde );
			}
			r.finir();
			if ( monde && monde.joueur && etat !== 'titre' ) {
				ec.hud( monde, partie );
			}
			surcouche();
		};

		return ec;
	}

	ynWE.ecrans = { creer: creer };
}() );
