/**
 * WordEnd — moteur : niveau (monde, joueur, objectif, fin de partie, étoiles).
 *
 * ynWE.niveau.demarrer(univers, niveauJson, personnage, entrees) → partie. La partie avance le
 * joueur, les ennemis, les ondes, les projectiles et l'objectif ; elle émet 'niveau:fin' une
 * seule fois (perdu : joueur mort depuis 2,2 s ; gagne : objectif atteint).
 *
 * Objectifs : « arcade » (vagues v1 à l'identique : 3 + 2n Timeres, bonus 50n, soin toutes les
 * 2 vagues ; jamais gagné), « vagues » (liste du niveau), « survie » (tenir « duree » s),
 * « boss » (vaincre le boss). ynWE.niveau.avancer(partie, secondes) sert au banc d'essai.
 * Interface : docs/wordend-formats.md (§3.10, §4.4).
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

	/* ------------------------------------------------------------------ */
	/* Apparitions (vagues, survie)                                         */
	/* ------------------------------------------------------------------ */

	/* Côté d'apparition : gauche, droite, alterne (selon le rang dans le groupe), aleatoire. */
	function coteGauche( cote, rang ) {
		if ( cote === 'gauche' ) {
			return true;
		}
		if ( cote === 'droite' ) {
			return false;
		}
		if ( cote === 'alterne' ) {
			return rang % 2 === 0;
		}
		return Math.random() < 0.5;
	}

	/*
	 * Fait apparaître un ennemi d'un groupe {ennemi, type, cote, apparition {x, y}?, pvBonus?,
	 * vitesseFacteur?} : juste hors de l'écran du côté demandé (bornes du niveau ± 50), ou au
	 * point « apparition » (sur une plateforme : y = hauteur de la plateforme), dans une bouffée.
	 */
	function apparaitre( partie, groupe, rang ) {
		var monde = partie.monde;
		var j = monde.joueur;
		var definition = definitionEnnemi( partie, groupe.ennemi );
		var options = { pvBonus: groupe.pvBonus || 0, vitesseFacteur: groupe.vitesseFacteur || 1 };
		var point = groupe.apparition && typeof groupe.apparition.x === 'number';
		if ( point ) {
			options.x = groupe.apparition.x;
			if ( typeof groupe.apparition.y === 'number' ) {
				options.y = groupe.apparition.y;
			}
			options.dir = j && j.x < options.x ? -1 : 1;
		} else {
			var gauche = coteGauche( groupe.cote, rang );
			options.x = gauche ? Math.max( -50, monde.camera.x - 50 ) : Math.min( monde.largeur + 50, monde.camera.x + ynWE.LARGEUR + 50 );
			options.dir = gauche ? 1 : -1;
		}
		var e = ynWE.ennemis.creer( definition, groupe.type || 'normal', options, monde );
		monde.ennemis.push( e );
		if ( point ) {
			var n = monde.mouvementReduit ? 3 : 10;
			for ( var i = 0; i < n; i++ ) {
				ynWE.rendu.ajouterParticule( monde, e.x + hasard( -14, 14 ), e.y - hasard( 0, 30 ), hasard( -30, 30 ), hasard( -60, -10 ), hasard( 0.4, 0.8 ), i % 2 ? '#eae26e' : '#36502a', 2, true );
			}
		}
		return e;
	}

	/* ------------------------------------------------------------------ */
	/* Objectif vagues (liste écrite dans le niveau)                        */
	/* ------------------------------------------------------------------ */

	/* Délai entre le départ du dernier ennemi et la victoire (s). */
	var DELAI_VICTOIRE = 1;

	/*
	 * objectif : {vagues: [{delai, ennemis: [{ennemi, type, n, cote, intervalle, apparition?}]}]}.
	 * Dans une vague, le rang k d'un groupe apparaît à k × intervalle s. Vague finie (tout est
	 * apparu, plus aucun ennemi) : bonus 50n, soin « soinEntreVagues », pause « delai » de la
	 * suivante ; après la dernière : gagné.
	 */
	var vagues = {
		demarrer: function ( partie ) {
			var liste = partie.niveau.objectif.vagues || [];
			partie.vague = {
				numero: 0,
				total: liste.length,
				aFaire: 0,
				minuterie: liste.length && typeof liste[ 0 ].delai === 'number' ? liste[ 0 ].delai : 1.2,
				banniere: 0,
				pause: true,
				file: [],
				ecoule: 0,
				fin: liste.length ? -1 : 0,
			};
		},
		lancer: function ( partie ) {
			var vague = partie.vague;
			var def = partie.niveau.objectif.vagues[ vague.numero ];
			var file = [];
			vague.numero++;
			( def.ennemis || [] ).forEach( function ( groupe, g ) {
				var intervalle = typeof groupe.intervalle === 'number' ? groupe.intervalle : 1.5;
				for ( var k = 0; k < ( groupe.n || 1 ); k++ ) {
					file.push( { t: k * intervalle, groupe: groupe, rang: k, ordre: g * 1000 + k } );
				}
			} );
			file.sort( function ( a, b ) {
				return a.t - b.t || a.ordre - b.ordre;
			} );
			vague.file = file;
			vague.ecoule = 0;
			vague.aFaire = file.length;
			vague.pause = false;
			vague.banniere = 2;
			var textes = partie.niveau.textes || {};
			ynWE.annoncer( gabarit( textes.vague || 'Vague {n} sur {total} : {k} ennemis.', { n: vague.numero, total: vague.total, k: file.length } ) );
			ynWE.evenements.emettre( 'vague:debut', { numero: vague.numero, total: vague.total } );
		},
		mettreAJour: function ( partie, dt ) {
			var vague = partie.vague;
			var monde = partie.monde;
			var joueur = monde.joueur;
			vague.banniere = Math.max( 0, vague.banniere - dt );
			if ( vague.fin >= 0 ) {
				vague.fin += dt;
				return;
			}
			if ( joueur.etat === 'mort' ) {
				return;
			}
			if ( vague.pause ) {
				vague.minuterie -= dt;
				if ( vague.minuterie <= 0 ) {
					vagues.lancer( partie );
				}
				return;
			}
			vague.ecoule += dt;
			while ( vague.file.length && vague.file[ 0 ].t <= vague.ecoule ) {
				var suivant = vague.file.shift();
				apparaitre( partie, suivant.groupe, suivant.rang );
			}
			vague.aFaire = vague.file.length;
			if ( vague.aFaire === 0 && monde.ennemis.length === 0 ) {
				ynWE.ajouterScore( monde, 50 * vague.numero );
				if ( vague.numero >= vague.total ) {
					vague.fin = 0;
					ynWE.annoncer( ( partie.niveau.textes || {} ).victoire || 'Toutes les vagues sont repoussées !' );
					return;
				}
				joueur.pv = Math.min( joueur.pvMax, joueur.pv + ( partie.niveau.soinEntreVagues || 0 ) );
				vague.pause = true;
				var prochaine = partie.niveau.objectif.vagues[ vague.numero ];
				vague.minuterie = typeof prochaine.delai === 'number' ? prochaine.delai : 1.8;
			}
		},
		fini: function ( partie ) {
			return partie.vague.fin >= DELAI_VICTOIRE ? 'gagne' : '';
		},
	};

	/* ------------------------------------------------------------------ */
	/* Objectif survie (tenir « duree » s face à un générateur)             */
	/* ------------------------------------------------------------------ */

	/* Tirage pondéré d'un groupe du générateur ({ennemi, type, poids}). */
	function tirerGroupe( groupes ) {
		var poids = function ( g ) {
			return typeof g.poids === 'number' ? g.poids : 1;
		};
		var tirage = Math.random() * groupes.reduce( function ( somme, g ) {
			return somme + poids( g );
		}, 0 );
		for ( var i = 0; i < groupes.length; i++ ) {
			tirage -= poids( groupes[ i ] );
			if ( tirage < 0 ) {
				return groupes[ i ];
			}
		}
		return groupes[ groupes.length - 1 ];
	}

	/*
	 * objectif : {duree, generateur: {ennemis: [{ennemi, type, poids?, cote?}], intervalle (s),
	 * delai? (1,5 s), max? (8 en vie), acceleration? (0,4 : l'intervalle baisse jusqu'à 40 % à la
	 * fin)}, bonus? (200 points)}. Annonces à 30 s et 10 s de la fin (textes.restant, « {s} »).
	 */
	var survie = {
		demarrer: function ( partie ) {
			var objectif = partie.niveau.objectif;
			var generateur = objectif.generateur || {};
			var duree = objectif.duree || 60;
			partie.vague = { numero: 0, banniere: 0 };
			partie.survie = {
				duree: duree,
				restant: duree,
				minuterie: typeof generateur.delai === 'number' ? generateur.delai : 1.5,
				annonces: [ 30, 10 ].filter( function ( s ) {
					return s < duree;
				} ),
				fini: false,
			};
		},
		mettreAJour: function ( partie, dt ) {
			var s = partie.survie;
			var monde = partie.monde;
			var objectif = partie.niveau.objectif;
			var generateur = objectif.generateur || {};
			var textes = partie.niveau.textes || {};
			if ( s.fini || monde.joueur.etat === 'mort' ) {
				return;
			}
			s.restant = Math.max( 0, s.duree - monde.temps );
			if ( s.restant <= 0 ) {
				s.fini = true;
				ynWE.ajouterScore( monde, typeof objectif.bonus === 'number' ? objectif.bonus : 200 );
				ynWE.annoncer( textes.victoire || 'Vous avez tenu jusqu’au bout !' );
				return;
			}
			if ( s.annonces.length && s.restant <= s.annonces[ 0 ] ) {
				ynWE.annoncer( gabarit( textes.restant || 'Plus que {s} secondes !', { s: s.annonces.shift() } ) );
			}
			s.minuterie -= dt;
			var groupes = generateur.ennemis || [];
			if ( s.minuterie <= 0 && groupes.length ) {
				var vivants = monde.ennemis.filter( function ( e ) {
					return e.etat !== 'mort';
				} ).length;
				if ( vivants < ( generateur.max || 8 ) ) {
					apparaitre( partie, tirerGroupe( groupes ), 0 );
				}
				var acceleration = typeof generateur.acceleration === 'number' ? generateur.acceleration : 0.4;
				s.minuterie = ( generateur.intervalle || 3 ) * ( 1 - acceleration * ( 1 - s.restant / s.duree ) ) * hasard( 0.8, 1.2 );
			}
		},
		fini: function ( partie ) {
			return partie.survie.fini ? 'gagne' : '';
		},
	};

	/* ------------------------------------------------------------------ */
	/* Objectif boss                                                        */
	/* ------------------------------------------------------------------ */

	/*
	 * objectif : {ennemi, typeEnnemi? (« boss »), x? (largeur − 80)}. Le boss apparaît au
	 * lancement (partie.boss : barre de PV du HUD) ; gagné 1,6 s après sa mort.
	 */
	var boss = {
		demarrer: function ( partie ) {
			var monde = partie.monde;
			var objectif = partie.niveau.objectif;
			var x = typeof objectif.x === 'number' ? objectif.x : monde.largeur - 80;
			var e = ynWE.ennemis.creer( definitionEnnemi( partie, objectif.ennemi ), objectif.typeEnnemi || 'boss', {
				x: x,
				dir: monde.joueur && monde.joueur.x > x ? 1 : -1,
			}, monde );
			monde.ennemis.push( e );
			partie.boss = e;
			partie.vague = { numero: 0, banniere: 0 };
			partie.finBoss = -1;
		},
		mettreAJour: function ( partie, dt ) {
			if ( partie.finBoss >= 0 ) {
				partie.finBoss += dt;
			} else if ( partie.boss.etat === 'mort' ) {
				partie.finBoss = 0;
				ynWE.annoncer( ( partie.niveau.textes || {} ).victoire || partie.boss.nom + ' est vaincu !' );
			}
		},
		fini: function ( partie ) {
			return partie.finBoss >= 1.6 ? 'gagne' : '';
		},
	};

	var objectifs = {
		arcade: arcade,
		vagues: vagues,
		survie: survie,
		boss: boss,
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
		// Caméra déjà centrée sur le joueur (niveaux plus larges que l'écran).
		monde.camera.x = ynWE.limiter( joueur.x - ynWE.LARGEUR / 2, 0, Math.max( 0, monde.largeur - ynWE.LARGEUR ) );
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

		/* restant (ajout lot C) : secondes à tenir en survie, sinon null. */
		partie.hud = function () {
			var vague = partie.vague || {};
			return {
				vague: vague.numero || 0,
				total: typeof vague.total === 'number' ? vague.total : null,
				objectif: typeObjectif,
				boss: partie.boss ? { pv: Math.max( 0, partie.boss.pv ), pvMax: partie.boss.pvMax, nom: partie.boss.nom || '' } : null,
				temps: monde.temps,
				banniere: vague.banniere || 0,
				restant: partie.survie ? partie.survie.restant : null,
			};
		};

		objectif.demarrer( partie );
		return partie;
	}

	/*
	 * Banc d'essai seulement : avance la partie de « secondes » par pas fixes (ynWE.DT), sans
	 * affichage. Renvoie la partie.
	 */
	function avancer( partie, secondes ) {
		var pas = Math.round( secondes / ynWE.DT );
		for ( var i = 0; i < pas && partie.etat === 'enCours'; i++ ) {
			partie.mettreAJour( ynWE.DT );
		}
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
		avancer: avancer,
	};
}() );
