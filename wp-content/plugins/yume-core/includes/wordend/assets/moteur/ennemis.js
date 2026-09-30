/**
 * WordEnd — moteur : ennemis génériques (JSON d'ennemi + type) et comportements.
 *
 * Un ennemi = un type d'une définition (ennemis/<slug>.json) : taille (±6 %), pv, vitesse,
 * points, attaques (portée et hauteur dans la définition), stoique. États : marche, course,
 * repos, attaque, degats, mort (fondu 0,6 s après l'animation). Les comportements décident du
 * déplacement hors attaque/dégâts : { entrer(e, monde), mettreAJour(e, dt, monde) → vx }.
 *
 * Lot 0 : marcheur et coureur (Timeres v1) ; volant, tireur, bouclier, boss et projectiles au
 * lot C. Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var hasard = ynWE.hasard;
	var DUREE_FONDU = 0.6;
	var COULEURS_MORT = [ '#eae26e', '#36502a', '#36502a' ];

	/**
	 * Crée un ennemi (sans l'ajouter au monde).
	 *
	 * @param {Object} definition JSON de l'ennemi chargé (avec planche).
	 * @param {string} typeNom    Clé de definition.types.
	 * @param {Object} options    {x, dir, taille, pvBonus, vitesseFacteur}.
	 * @param {Object} monde      Monde.
	 */
	function creer( definition, typeNom, options, monde ) {
		var type = definition.types[ typeNom ];
		if ( ! type ) {
			throw new Error( 'type d’ennemi inconnu : ' + typeNom );
		}
		options = options || {};
		var comportement = comportements[ type.comportement || 'marcheur' ];
		var e = ynWE.physique.corps( {
			x: typeof options.x === 'number' ? options.x : 0,
			y: monde.sol,
			l: ( definition.boite || { l: 46 } ).l,
			h: ( definition.boite || { h: 44 } ).h,
		} );
		e.id = monde.prochainId++;
		e.definition = definition;
		e.planche = definition.planche;
		e.typeNom = typeNom;
		e.type = type;
		e.comportement = type.comportement || 'marcheur';
		e.taille = typeof options.taille === 'number' ? options.taille : type.taille * hasard( 0.94, 1.06 );
		e.dir = options.dir || 1;
		e.etat = e.comportement === 'coureur' ? 'course' : 'marche';
		e.t = hasard( 0, 1 );
		e.attaque = '';
		e.pv = type.pv + ( options.pvBonus || 0 );
		e.pvMax = e.pv;
		e.vitesse = type.vitesse * ( options.vitesseFacteur || 1 );
		e.points = type.points;
		e.recharge = hasard( 0.2, 0.8 );
		e.flash = 0;
		e.recul = 0;
		e.touche = false;
		e.stoique = !! type.stoique;
		if ( comportement && comportement.entrer ) {
			comportement.entrer( e, monde );
		}
		return e;
	}

	function changerEtat( e, etat ) {
		e.etat = etat;
		e.t = 0;
	}

	/* Corps (ancre au sol, au milieu des pattes). */
	function boite( e ) {
		var l = e.l * e.taille;
		var h = e.h * e.taille;
		return { x: e.x - l / 2, y: e.y - h, l: l, h: h };
	}

	/* Zone touchée par l'attaque en cours. */
	function boiteAttaque( e ) {
		var attaque = e.definition.attaques[ e.attaque ] || { portee: 40, hauteur: 34 };
		var portee = attaque.portee * e.taille;
		var debut = 6 * e.taille;
		var h = attaque.hauteur * e.taille;
		return {
			x: e.dir > 0 ? e.x + debut : e.x - debut - portee,
			y: e.y - h - 4 * e.taille,
			l: portee,
			h: h,
		};
	}

	/**
	 * Inflige des dégâts. sens : côté vers lequel l'ennemi est repoussé (±1). source
	 * (facultatif) : {type, dir} (bouclier, lot C).
	 */
	function blesser( e, degats, sens, monde, source ) {
		var centre = e.y - 22 * e.taille;
		e.pv -= degats;
		e.flash = 0.1;
		var n = monde.mouvementReduit ? 2 : 6;
		for ( var i = 0; i < n; i++ ) {
			ynWE.rendu.ajouterParticule( monde, e.x, centre, sens * hasard( 20, 120 ), hasard( -120, -20 ), 0.4, '#bfe6ff', 2 );
		}
		if ( e.pv <= 0 ) {
			ynWE.ajouterScore( monde, e.points );
			changerEtat( e, 'mort' );
			e.recul = sens * 90;
			var couleurs = e.definition.couleursMort || COULEURS_MORT;
			var m = monde.mouvementReduit ? 4 : 12;
			for ( var k = 0; k < m; k++ ) {
				ynWE.rendu.ajouterParticule( monde, e.x + hasard( -16, 16 ) * e.taille, centre + hasard( -14, 14 ) * e.taille, hasard( -60, 60 ), hasard( -110, -10 ), hasard( 0.4, 0.9 ), couleurs[ k % 3 ], hasard( 2, 3 ) );
			}
			ynWE.evenements.emettre( 'ennemi:mort', { ennemi: e, points: e.points } );
			return;
		}
		// Un ennemi stoïque ne recule que sous un coup de plus d'un point (onde magique).
		if ( ! e.stoique || degats > 1 ) {
			e.recul = sens * 170;
			changerEtat( e, 'degats' );
		}
	}

	function mettreAJour( e, dt, monde ) {
		var meta = e.planche.meta;
		var j = monde.joueur;
		e.t += dt;
		e.flash = Math.max( 0, e.flash - dt );
		e.recharge = Math.max( 0, e.recharge - dt );
		e.recul *= 0.86;
		var vx = 0;

		if ( e.etat === 'mort' ) {
			e.x += e.recul * dt;
			return;
		}
		if ( e.etat === 'degats' ) {
			if ( e.t >= Math.min( 0.45, ynWE.dureeAnimation( meta, 'degats' ) ) ) {
				changerEtat( e, 'repos' );
			}
		} else if ( e.etat === 'attaque' ) {
			var i = ynWE.imageCourante( meta, e.attaque, e.t );
			if ( ! e.touche && ( ynWE.animation( meta, e.attaque ).coup || [] ).indexOf( i ) !== -1 && j && j.etat !== 'mort' && j.invincible <= 0 && ynWE.chevauche( j.boite(), boiteAttaque( e ) ) ) {
				e.touche = true;
				j.blesser( e.dir, ( e.definition.attaques[ e.attaque ] || {} ).degats || 1, monde );
			}
			if ( e.t >= ynWE.dureeAnimation( meta, e.attaque ) ) {
				changerEtat( e, 'repos' );
				e.recharge = hasard( 0.8, 1.5 ) * ( e.type.rechargeFacteur || 1 );
			}
		} else {
			var comportement = comportements[ e.comportement ];
			vx = comportement ? comportement.mettreAJour( e, dt, monde ) || 0 : 0;
		}
		e.x += ( vx + e.recul ) * dt;
	}

	/* ------------------------------------------------------------------ */
	/* Comportements                                                       */
	/* ------------------------------------------------------------------ */

	/*
	 * Marcheur (v1) : approche le joueur, attend à portée, attaque (morsure ou fouet selon la
	 * distance) ; fuit quand le joueur est mort ; n'attaque pas hors de l'écran.
	 */
	function approcher( e, monde, coureur ) {
		var j = monde.joueur;
		var attaques = e.type.attaques;
		var portees = e.definition.attaques;
		var distance = Math.abs( j.x - e.x );
		var fuite = j.etat === 'mort';
		var gauche = monde.camera.x;
		if ( ! fuite ) {
			e.dir = j.x > e.x ? 1 : -1;
		} else {
			e.dir = e.x < gauche + ynWE.LARGEUR / 2 ? -1 : 1;
		}
		var attaque = attaques[ Math.floor( Math.random() * attaques.length ) ];
		var portee = portees[ attaques[ 0 ] ].portee * e.taille + 6;
		// L'ancre (milieu des pattes) doit être dans le cadre : au moins la moitié du corps visible.
		var visible = e.x > gauche + 4 && e.x < gauche + ynWE.LARGEUR - 4;
		if ( ! fuite && visible && distance <= portee + 4 && e.recharge <= 0 ) {
			var morsure = portees.morsure ? portees.morsure.portee : portees[ attaques[ 0 ] ].portee;
			e.attaque = distance > morsure * e.taille + 6 && attaques.indexOf( 'fouet' ) !== -1 ? 'fouet' : attaque;
			e.touche = false;
			changerEtat( e, 'attaque' );
			return 0;
		}
		if ( fuite || distance > portee || ! visible ) {
			var court = coureur && distance > 50;
			var voulu = court ? 'course' : 'marche';
			if ( e.etat !== voulu ) {
				changerEtat( e, voulu );
			}
			return e.dir * ( court ? e.vitesse : Math.min( e.vitesse, 46 ) );
		}
		if ( e.etat !== 'repos' ) {
			changerEtat( e, 'repos' );
		}
		return 0;
	}

	function aVenir( nom ) {
		return {
			entrer: ynWE.nonImplemente( 'ennemis.comportements.' + nom ),
			mettreAJour: ynWE.nonImplemente( 'ennemis.comportements.' + nom + '.mettreAJour' ),
		};
	}

	var comportements = {
		marcheur: {
			entrer: function () {},
			mettreAJour: function ( e, dt, monde ) {
				return approcher( e, monde, false );
			},
		},
		coureur: {
			entrer: function () {},
			mettreAJour: function ( e, dt, monde ) {
				return approcher( e, monde, true );
			},
		},
		volant: aVenir( 'volant' ),
		tireur: aVenir( 'tireur' ),
		bouclier: aVenir( 'bouclier' ),
		boss: aVenir( 'boss' ),
	};

	/* Les ennemis ne se superposent pas tout à fait. */
	function separer( monde ) {
		var liste = monde.ennemis;
		liste.forEach( function ( a, ia ) {
			liste.forEach( function ( b, ib ) {
				if ( ib <= ia || a.etat === 'mort' || b.etat === 'mort' ) {
					return;
				}
				var ecart = ( 18 * ( a.taille + b.taille ) ) - Math.abs( a.x - b.x );
				if ( ecart > 0 ) {
					var sens = a.x < b.x ? -1 : 1;
					a.x += sens * ecart * 0.25;
					b.x -= sens * ecart * 0.25;
				}
			} );
		} );
	}

	/* Retire les ennemis morts (animation et fondu finis) ou sortis loin du niveau. */
	function retirerFinis( monde ) {
		monde.ennemis = monde.ennemis.filter( function ( e ) {
			var fini = e.etat === 'mort' && e.t > ynWE.dureeAnimation( e.planche.meta, 'mort' ) + DUREE_FONDU;
			return ! fini && e.x > -140 && e.x < monde.largeur + 140;
		} );
	}

	function dessiner( r, e, monde ) {
		var meta = e.planche.meta;
		var nom = e.etat === 'attaque' ? e.attaque : e.etat;
		var alpha = 1;
		if ( e.etat === 'mort' ) {
			alpha = Math.max( 0, 1 - Math.max( 0, e.t - ynWE.dureeAnimation( meta, 'mort' ) ) / DUREE_FONDU );
		}
		r.ombre( e.x, monde.sol + 1, 28 * e.taille, 3, 0.22 );
		r.sprite( e.planche, nom, ynWE.imageCourante( meta, nom, e.t ), e.x, e.y, e.dir, e.taille, e.definition.lissage !== false, alpha, e.flash > 0 ? 1 : 0 );
	}

	/* Projectiles (lot C) : aucun n'est créé au lot 0. */
	function mettreAJourProjectiles( monde ) {
		if ( monde.projectiles.length ) {
			throw new Error( 'non implémenté : ennemis.mettreAJourProjectiles' );
		}
	}

	function dessinerProjectiles() {}

	ynWE.ennemis = {
		DUREE_FONDU: DUREE_FONDU,
		creer: creer,
		mettreAJour: mettreAJour,
		blesser: blesser,
		boite: boite,
		boiteAttaque: boiteAttaque,
		dessiner: dessiner,
		comportements: comportements,
		enregistrerComportement: function ( nom, impl ) {
			comportements[ nom ] = impl;
		},
		mettreAJourProjectiles: mettreAJourProjectiles,
		dessinerProjectiles: dessinerProjectiles,
		separer: separer,
		retirerFinis: retirerFinis,
		changerEtat: changerEtat,
	};
}() );
