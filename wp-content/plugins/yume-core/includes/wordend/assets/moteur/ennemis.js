/**
 * WordEnd — moteur : ennemis génériques (JSON d'ennemi + type), comportements et projectiles.
 *
 * Un ennemi = un type d'une définition (ennemis/<slug>.json) : taille (±6 %), pv, vitesse,
 * points, attaques (portée et hauteur dans la définition), stoique. États : marche, course,
 * repos, attaque, degats, mort (fondu 0,6 s après l'animation). Les comportements décident du
 * déplacement hors attaque/dégâts :
 *   { entrer(e, monde), mettreAJour(e, dt, monde) → vx, attaquer?(e, monde) → bool,
 *     avant?(e, dt, monde) }
 * - attaquer (facultatif) : appelé sur la première image « coup » d'une attaque ; vrai = le coup
 *   est traité par le comportement (tir du tireur) au lieu du coup au corps à corps.
 * - avant (facultatif) : minuteries du comportement, appelé à chaque pas quel que soit l'état
 *   (sauf mort).
 *
 * Comportements : marcheur et coureur (Timeres v1, à l'identique), volant (vol ondulant,
 * plongée sur le joueur), tireur (garde ses distances, tire des projectiles), bouclier (pare les
 * coups de face sauf l'onde, se retourne lentement), boss (phases, invocations, charge).
 * Les ennemis au sol passent par ynWE.physique.appliquer (gravité, plateformes) ; les volants
 * gèrent eux-mêmes leur hauteur.
 *
 * Projectiles (monde.projectiles, tous camps) : {camp: 'ennemi'|'joueur', x, y, vx, vy, degats,
 * vie (s), couleur?, rayon? | l?, h?, gravite?, perce? (ancien nom lu en secours : percant), touches?, mettreAJour?(p, dt, monde),
 * dessiner?(r, p, monde)}. Un projectile ennemi blesse le joueur (sauf invincible) ou lui est
 * renvoyé s'il pare (j.pare) ; un projectile du joueur frappe les ennemis (competences.frapper).
 *
 * Interface : docs/wordend-formats.md (§3.9).
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var hasard = ynWE.hasard;
	var LARGEUR = ynWE.LARGEUR;
	var DUREE_FONDU = 0.6;
	var COULEURS_MORT = [ '#eae26e', '#36502a', '#36502a' ];
	var ETINCELLES = [ '#fff6c2', '#ffd36e', '#ffffff' ];
	var MAX_PROJECTILES = 60;

	/* ------------------------------------------------------------------ */
	/* Ennemi générique                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Crée un ennemi (sans l'ajouter au monde).
	 *
	 * @param {Object} definition JSON de l'ennemi chargé (avec planche).
	 * @param {string} typeNom    Clé de definition.types.
	 * @param {Object} options    {x, y?, dir, taille?, pvBonus, vitesseFacteur}.
	 * @param {Object} monde      Monde.
	 */
	function creer( definition, typeNom, options, monde ) {
		var type = definition.types[ typeNom ];
		if ( ! type ) {
			throw new Error( 'type d’ennemi inconnu : ' + typeNom );
		}
		options = options || {};
		var nomComportement = comportements[ type.comportement ] ? type.comportement : 'marcheur';
		var comportement = comportements[ nomComportement ];
		var e = ynWE.physique.corps( {
			x: typeof options.x === 'number' ? options.x : 0,
			y: typeof options.y === 'number' ? options.y : monde.sol,
			l: ( definition.boite || { l: 46 } ).l,
			h: ( definition.boite || { h: 44 } ).h,
		} );
		e.id = monde.prochainId++;
		e.definition = definition;
		// Planche du type résolue par ressources ({nom, image, meta}, teintée), sinon celle de l'ennemi.
		e.planche = type.planche && type.planche.image ? type.planche : definition.planche;
		e.typeNom = typeNom;
		e.type = type;
		e.comportement = nomComportement;
		e.nom = type.nom || definition.nom || typeNom;
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
		e.vole = false;
		e.tir = false;
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

	/* Gerbe d'étincelles (coup paré, projectile renvoyé). */
	function etincelles( monde, x, y, sens, n ) {
		var total = monde.mouvementReduit ? Math.min( 2, n ) : n;
		for ( var i = 0; i < total; i++ ) {
			ynWE.rendu.ajouterParticule( monde, x, y, sens * hasard( 30, 140 ), hasard( -140, -20 ), hasard( 0.2, 0.4 ), ETINCELLES[ i % 3 ], 2 );
		}
	}

	/* Un bouclier arrête les coups de face, sauf l'onde magique. sens : côté du recul. */
	function coupPare( e, sens, source ) {
		if ( e.comportement !== 'bouclier' || ( source && source.type === 'onde' ) ) {
			return false;
		}
		return sens === -e.dir;
	}

	/**
	 * Inflige des dégâts. sens : côté vers lequel l'ennemi est repoussé (±1). source
	 * (facultatif) : {type: 'melee'|'onde'|'projectile'|…, dir}. Renvoie vrai si le coup a porté.
	 */
	function blesser( e, degats, sens, monde, source ) {
		if ( e.etat === 'mort' ) {
			return false;
		}
		var centre = e.y - 22 * e.taille;
		if ( coupPare( e, sens, source ) ) {
			etincelles( monde, e.x - sens * 20 * e.taille, centre, -sens, 6 );
			e.recul = sens * 40;
			return false;
		}
		var boss = e.comportement === 'boss';
		e.pv -= degats;
		e.flash = 0.1;
		var n = monde.mouvementReduit ? 2 : 6;
		for ( var i = 0; i < n; i++ ) {
			ynWE.rendu.ajouterParticule( monde, e.x, centre, sens * hasard( 20, 120 ), hasard( -120, -20 ), 0.4, '#bfe6ff', 2 );
		}
		if ( e.pv <= 0 ) {
			ynWE.ajouterScore( monde, e.points );
			changerEtat( e, 'mort' );
			e.recul = boss ? 0 : sens * 90;
			e.vy = 0;
			var couleurs = e.definition.couleursMort || COULEURS_MORT;
			var m = monde.mouvementReduit ? 4 : 12;
			for ( var k = 0; k < m; k++ ) {
				ynWE.rendu.ajouterParticule( monde, e.x + hasard( -16, 16 ) * e.taille, centre + hasard( -14, 14 ) * e.taille, hasard( -60, 60 ), hasard( -110, -10 ), hasard( 0.4, 0.9 ), couleurs[ k % 3 ], hasard( 2, 3 ) );
			}
			if ( boss ) {
				ynWE.secouer( monde, 0.6 );
			}
			ynWE.evenements.emettre( 'ennemi:mort', { ennemi: e, points: e.points } );
			return true;
		}
		// Le boss ne recule jamais ; un ennemi stoïque ne recule que sous un coup de plus d'un point.
		if ( ! boss && ( ! e.stoique || degats > 1 ) ) {
			e.recul = sens * 170;
			changerEtat( e, 'degats' );
		}
		return true;
	}

	/*
	 * Coup de l'ennemi e sur le joueur. Pendant une parade (j.pare, lot B), j.blesser absorbe le
	 * coup et repousse l'attaquant : renvoie 'pare'. Sinon 'touche', ou '' si le joueur est
	 * invincible. types.<t>.repit (s, facultatif) : invincibilité du joueur après un coup porté
	 * (au moins joueur.INVINCIBILITE, 1,2 s).
	 */
	function toucherJoueur( e, j, degats, monde ) {
		if ( j.pare ) {
			j.blesser( e.dir, degats, monde );
			return 'pare';
		}
		if ( j.invincible > 0 ) {
			return '';
		}
		if ( j.blesser( e.dir, degats, monde ) && typeof e.type.repit === 'number' && j.etat !== 'mort' ) {
			// Répit (s) accordé après un coup de ce type : plus long que l'invincibilité par défaut.
			j.invincible = Math.max( j.invincible, e.type.repit );
		}
		return 'touche';
	}

	/*
	 * Temps écoulé dans l'animation d'attaque : types.<t>.preavis (s, facultatif) retient la
	 * première image (l'ennemi clignote, dessiner) avant le geste, pour laisser le temps de
	 * s'écarter. Le tir du tireur n'en tient pas compte (il a déjà sa propre recharge).
	 */
	function tempsAttaque( e ) {
		var preavis = typeof e.type.preavis === 'number' && ! e.tir ? e.type.preavis : 0;
		return Math.max( 0, e.t - preavis );
	}

	/* Déplacement d'un pas : physique pour les ennemis au sol, direct pour les volants. */
	function deplacer( e, vitesse, dt, monde ) {
		if ( e.vole ) {
			e.x += vitesse * dt;
			return;
		}
		e.vx = vitesse;
		ynWE.physique.appliquer( e, dt, monde, { gravite: true, plateformes: true, bords: false } );
	}

	function mettreAJour( e, dt, monde ) {
		var meta = e.planche.meta;
		var j = monde.joueur;
		var comportement = comportements[ e.comportement ];
		e.t += dt;
		e.flash = Math.max( 0, e.flash - dt );
		e.recharge = Math.max( 0, e.recharge - dt );
		e.recul *= 0.86;
		var vx = 0;

		if ( e.etat === 'mort' ) {
			if ( e.vole ) {
				// Un volant abattu tombe au sol.
				e.vy = ( e.vy || 0 ) + 700 * dt;
				e.y = Math.min( monde.sol, e.y + e.vy * dt );
			}
			deplacer( e, e.recul, dt, monde );
			return;
		}
		if ( comportement && comportement.avant ) {
			comportement.avant( e, dt, monde );
		}
		if ( e.etat === 'degats' ) {
			if ( e.t >= Math.min( 0.45, ynWE.dureeAnimation( meta, 'degats' ) ) ) {
				changerEtat( e, 'repos' );
			}
		} else if ( e.etat === 'attaque' ) {
			var i = ynWE.imageCourante( meta, e.attaque, tempsAttaque( e ) );
			if ( ! e.touche && ( ynWE.animation( meta, e.attaque ).coup || [] ).indexOf( i ) !== -1 ) {
				if ( comportement && comportement.attaquer && comportement.attaquer( e, monde ) ) {
					e.touche = true;
				} else if ( j && j.etat !== 'mort' && ( j.pare || j.invincible <= 0 ) && ynWE.chevauche( j.boite(), boiteAttaque( e ) ) ) {
					e.touche = true;
					toucherJoueur( e, j, ( e.definition.attaques[ e.attaque ] || {} ).degats || 1, monde );
				}
			}
			if ( e.etat === 'attaque' && tempsAttaque( e ) >= ynWE.dureeAnimation( meta, e.attaque ) ) {
				changerEtat( e, 'repos' );
				e.tir = false;
				e.recharge = hasard( 0.8, 1.5 ) * ( e.type.rechargeFacteur || 1 );
			}
		} else {
			vx = comportement ? comportement.mettreAJour( e, dt, monde ) || 0 : 0;
		}
		deplacer( e, vx + e.recul, dt, monde );
	}

	/* ------------------------------------------------------------------ */
	/* Comportements                                                       */
	/* ------------------------------------------------------------------ */

	/* L'ancre (milieu des pattes) est dans le cadre : au moins la moitié du corps visible. */
	function estVisible( e, monde ) {
		var gauche = monde.camera.x;
		return e.x > gauche + 4 && e.x < gauche + LARGEUR - 4;
	}

	/*
	 * Marcheur (v1) : approche le joueur, attend à portée, attaque (morsure ou fouet selon la
	 * distance) ; fuit quand le joueur est mort ; n'attaque pas hors de l'écran. garderDir : le
	 * comportement a déjà orienté l'ennemi (bouclier).
	 */
	function approcher( e, monde, coureur, garderDir ) {
		var j = monde.joueur;
		var attaques = e.type.attaques;
		var portees = e.definition.attaques;
		var distance = Math.abs( j.x - e.x );
		var fuite = j.etat === 'mort';
		var gauche = monde.camera.x;
		if ( ! fuite ) {
			if ( ! garderDir ) {
				e.dir = j.x > e.x ? 1 : -1;
			}
		} else {
			e.dir = e.x < gauche + LARGEUR / 2 ? -1 : 1;
		}
		var attaque = attaques[ Math.floor( Math.random() * attaques.length ) ];
		var portee = portees[ attaques[ 0 ] ].portee * e.taille + 6;
		var visible = e.x > gauche + 4 && e.x < gauche + LARGEUR - 4;
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

	function mettreEtat( e, etat ) {
		if ( e.etat !== etat ) {
			changerEtat( e, etat );
		}
	}

	/* Plateforme sur laquelle l'ennemi se tient (null au sol). */
	function plateformeSous( e, monde ) {
		if ( e.y >= monde.sol - 0.5 ) {
			return null;
		}
		var trouvee = null;
		( monde.plateformes || [] ).forEach( function ( p ) {
			if ( Math.abs( p.y - e.y ) < 1.5 && e.x >= p.x - 2 && e.x <= p.x + p.l + 2 ) {
				trouvee = p;
			}
		} );
		return trouvee;
	}

	/*
	 * Volant : vole à « altitude » au-dessus du sol en ondulant (« amplitude »), se place
	 * au-dessus du joueur puis plonge à « plongee » px/s ; remonte après la plongée ou un coup.
	 * Ni gravité ni plateformes.
	 */
	var volant = {
		entrer: function ( e, monde ) {
			e.vole = true;
			e.base = e.y < monde.sol - 1 ? e.y : monde.sol - ( e.type.altitude || 80 );
			e.y = e.base;
			e.phase = 'vol';
			e.onde = 0;
			e.plongee = { vx: 0, vy: 0, finY: monde.sol };
		},
		mettreAJour: function ( e, dt, monde ) {
			var j = monde.joueur;
			var fuite = j.etat === 'mort';
			var amplitude = typeof e.type.amplitude === 'number' ? e.type.amplitude : 14;
			if ( e.phase === 'plongee' && e.etat !== 'course' ) {
				e.phase = 'remonte'; // Touché pendant la plongée.
			}
			if ( e.phase === 'plongee' ) {
				e.y += e.plongee.vy * dt;
				if ( ! e.touche && ! fuite && ( j.pare || j.invincible <= 0 ) && ynWE.chevauche( j.boite(), boite( e ) ) ) {
					e.touche = true;
					if ( toucherJoueur( e, j, ( e.definition.attaques[ e.type.attaques[ 0 ] ] || {} ).degats || 1, monde ) === 'pare' ) {
						e.phase = 'remonte';
					}
				}
				if ( e.y >= e.plongee.finY ) {
					e.y = Math.min( e.y, monde.sol );
					e.phase = 'remonte';
				}
				return e.phase === 'plongee' ? e.plongee.vx : 0;
			}
			if ( e.phase === 'remonte' ) {
				mettreEtat( e, 'marche' );
				e.y -= ( e.type.remontee || 110 ) * dt;
				if ( e.y <= e.base ) {
					e.y = e.base;
					e.phase = 'vol';
					e.onde = 0;
					e.recharge = hasard( 1.2, 2.2 ) * ( e.type.rechargeFacteur || 1 );
				}
				return 0;
			}
			// Vol : ondule et se place au-dessus du joueur (ou s'enfuit s'il est à terre).
			mettreEtat( e, 'marche' );
			e.onde += dt;
			e.y = e.base + Math.sin( e.onde * 3 ) * amplitude;
			var cible = fuite ? ( e.x < monde.camera.x + LARGEUR / 2 ? -200 : monde.largeur + 200 ) : j.x;
			var dx = cible - e.x;
			if ( Math.abs( dx ) > 2 ) {
				e.dir = dx > 0 ? 1 : -1;
			}
			if ( ! fuite && estVisible( e, monde ) && Math.abs( dx ) < 28 && e.recharge <= 0 && j.y - 20 > e.y ) {
				var cx = j.x + ( j.vx || 0 ) * 0.15;
				var cy = j.y - 14;
				var ddx = cx - e.x;
				var ddy = cy - e.y;
				var d = Math.sqrt( ddx * ddx + ddy * ddy ) || 1;
				var v = e.type.plongee || 260;
				e.plongee = { vx: ddx / d * v, vy: ddy / d * v, finY: Math.min( monde.sol, cy + 10 ) };
				e.phase = 'plongee';
				e.touche = false;
				changerEtat( e, 'course' );
				return e.plongee.vx;
			}
			return ( dx > 0 ? 1 : -1 ) * Math.min( e.vitesse, Math.abs( dx ) * 2.5 );
		},
	};

	/* Tir d'un projectile vers le joueur (tireur). */
	function tirer( e, monde ) {
		var def = e.type.projectile || {};
		var j = monde.joueur;
		var x = e.x + e.dir * 22 * e.taille;
		var y = e.y - ( def.hauteur || 30 ) * e.taille;
		var dx = j ? j.x - x : e.dir * 100;
		var dy = j ? ( j.y - j.h * 0.5 ) - y : 0;
		if ( dx * e.dir < 20 ) {
			dx = e.dir * 20; // Toujours vers l'avant.
		}
		// Angle limité (±35°) : un tir plongeant depuis une plateforme reste esquivable.
		dy = ynWE.limiter( dy, -Math.abs( dx ) * 0.7, Math.abs( dx ) * 0.7 );
		var d = Math.sqrt( dx * dx + dy * dy ) || 1;
		var v = def.vitesse || 170;
		ajouterProjectile( monde, {
			camp: 'ennemi',
			x: x,
			y: y,
			vx: dx / d * v,
			vy: dy / d * v,
			degats: def.degats || 1,
			vie: def.vie || 3,
			couleur: def.couleur || '#c9f26a',
			rayon: def.rayon || 3,
			source: e.id,
		} );
		var n = monde.mouvementReduit ? 1 : 4;
		for ( var i = 0; i < n; i++ ) {
			ynWE.rendu.ajouterParticule( monde, x, y, e.dir * hasard( 10, 60 ), hasard( -40, 10 ), 0.3, def.couleur || '#c9f26a', 2 );
		}
	}

	/*
	 * Tireur : garde « distance » avec le joueur (sans quitter l'écran ni sa plateforme), tire un
	 * projectile toutes les « recharge » s (geste : animation « geste », sinon fouet), mord si le
	 * joueur le colle.
	 */
	var tireur = {
		entrer: function ( e ) {
			e.tirRecharge = hasard( 0.8, 1.6 );
		},
		avant: function ( e, dt ) {
			e.tirRecharge = Math.max( 0, e.tirRecharge - dt );
		},
		mettreAJour: function ( e, dt, monde ) {
			var j = monde.joueur;
			if ( j.etat === 'mort' ) {
				return approcher( e, monde, false );
			}
			var attaques = e.type.attaques;
			var portees = e.definition.attaques;
			var distance = Math.abs( j.x - e.x );
			var visible = estVisible( e, monde );
			var garde = e.type.distance || 150;
			e.dir = j.x > e.x ? 1 : -1;
			var porteeMelee = portees[ attaques[ 0 ] ].portee * e.taille + 6;
			if ( visible && distance <= porteeMelee + 4 && e.recharge <= 0 && Math.abs( j.y - e.y ) < 30 ) {
				e.attaque = attaques[ 0 ];
				e.tir = false;
				e.touche = false;
				changerEtat( e, 'attaque' );
				return 0;
			}
			if ( visible && e.tirRecharge <= 0 && distance <= garde + 120 ) {
				var geste = e.type.geste || 'fouet';
				e.attaque = ynWE.animation( e.planche.meta, geste ) ? geste : attaques[ 0 ];
				e.tir = true;
				e.touche = false;
				changerEtat( e, 'attaque' );
				return 0;
			}
			var sens = 0;
			if ( ! visible ) {
				sens = e.dir;
			} else if ( distance < garde - 24 ) {
				sens = -e.dir;
			} else if ( distance > garde + 24 ) {
				sens = e.dir;
			}
			if ( sens && visible ) {
				var suivant = e.x + sens * 10;
				var p = plateformeSous( e, monde );
				if ( suivant < monde.camera.x + 16 || suivant > monde.camera.x + LARGEUR - 16 || ( p && ( suivant < p.x + 8 || suivant > p.x + p.l - 8 ) ) ) {
					sens = 0;
				}
			}
			if ( ! sens ) {
				mettreEtat( e, 'repos' );
				return 0;
			}
			mettreEtat( e, 'marche' );
			return sens * Math.min( e.vitesse, 46 );
		},
		attaquer: function ( e, monde ) {
			if ( ! e.tir ) {
				return false;
			}
			tirer( e, monde );
			e.tirRecharge = ( e.type.recharge || 2.4 ) * hasard( 0.85, 1.15 );
			return true;
		},
	};

	/*
	 * Bouclier : marcheur qui pare les coups de face (sauf l'onde) ; il met « retournement »
	 * (0,4 s) à faire demi-tour quand le joueur passe derrière lui.
	 */
	var bouclier = {
		entrer: function ( e ) {
			e.retournement = 0;
		},
		mettreAJour: function ( e, dt, monde ) {
			var j = monde.joueur;
			if ( j.etat !== 'mort' ) {
				var voulu = j.x > e.x ? 1 : -1;
				if ( voulu !== e.dir && Math.abs( j.x - e.x ) > 4 ) {
					e.retournement += dt;
					if ( e.retournement < ( e.type.retournement || 0.4 ) ) {
						mettreEtat( e, 'repos' );
						return 0;
					}
					e.dir = voulu;
				}
				e.retournement = 0;
			}
			return approcher( e, monde, false, true );
		},
	};

	/* Invocations du boss : n ennemis aux bords de l'écran (au plus « max » en vie). */
	function invoquer( e, monde, inv ) {
		var definition = ( monde.univers && monde.univers.ennemis && monde.univers.ennemis[ inv.ennemi ] ) || e.definition;
		if ( ! definition.types[ inv.type ] ) {
			return;
		}
		var vivants = monde.ennemis.filter( function ( m ) {
			return m.invoquePar === e.id && m.etat !== 'mort';
		} ).length;
		var n = Math.min( inv.n || 1, ( inv.max || 6 ) - vivants );
		for ( var k = 0; k < n; k++ ) {
			var gauche = k % 2 === 0;
			var x = gauche ? Math.max( -50, monde.camera.x - 40 ) : Math.min( monde.largeur + 50, monde.camera.x + LARGEUR + 40 );
			var m = creer( definition, inv.type, { x: x, dir: gauche ? 1 : -1 }, monde );
			m.invoquePar = e.id;
			monde.ennemis.push( m );
		}
		if ( n > 0 ) {
			ynWE.secouer( monde, 0.15 );
		}
	}

	/* Fin d'une charge du boss. */
	function finirCharge( e ) {
		var cfg = e.type.charge || {};
		e.charge = '';
		e.chargeMinuterie = cfg.toutesLes || 6;
		e.recharge = Math.max( e.recharge, 0.6 );
		changerEtat( e, 'repos' );
	}

	/*
	 * Boss : marcheur géant qui ne recule jamais ; phases selon ses PV (« phases[].pvSous » :
	 * vitesseFacteur, invocations {ennemi, type, n, toutesLes, max}) ; charge traversante toutes
	 * les « charge.toutesLes » s (6) après une préparation visible.
	 */
	var boss = {
		entrer: function ( e ) {
			var cfg = e.type.charge || {};
			e.stoique = true;
			e.vitesseBase = e.vitesse;
			e.phaseBoss = 0;
			e.invocation = 0;
			e.charge = '';
			e.chargeT = 0;
			e.chargeCible = e.x;
			e.chargeMinuterie = cfg.toutesLes || 6;
			e.entree = typeof e.type.entree === 'number' ? e.type.entree : 1.2;
			e.etat = 'repos';
		},
		avant: function ( e, dt, monde ) {
			e.recul = 0;
			var phases = e.type.phases || [];
			var part = e.pv / e.pvMax;
			var indice = 0;
			phases.forEach( function ( phase, i ) {
				if ( part <= phase.pvSous ) {
					indice = i;
				}
			} );
			var phase = phases[ indice ] || {};
			if ( indice !== e.phaseBoss ) {
				e.phaseBoss = indice;
				e.invocation = 1;
				ynWE.secouer( monde, 0.3 );
				ynWE.annoncer( phase.texte || ( e.nom + ' entre en rage !' ) );
			}
			e.vitesse = e.vitesseBase * ( phase.vitesseFacteur || 1 );
			if ( phase.invocations && monde.joueur.etat !== 'mort' ) {
				e.invocation -= dt;
				if ( e.invocation <= 0 ) {
					e.invocation = phase.invocations.toutesLes || 8;
					invoquer( e, monde, phase.invocations );
				}
			}
			if ( ! e.charge && e.entree <= 0 ) {
				e.chargeMinuterie = Math.max( 0, e.chargeMinuterie - dt );
			}
		},
		mettreAJour: function ( e, dt, monde ) {
			var j = monde.joueur;
			var cfg = e.type.charge || {};
			if ( e.entree > 0 || j.etat === 'mort' ) {
				e.entree -= dt;
				if ( j.etat !== 'mort' ) {
					e.dir = j.x > e.x ? 1 : -1;
				}
				if ( e.charge ) {
					finirCharge( e );
				}
				mettreEtat( e, 'repos' );
				return 0;
			}
			if ( e.charge === 'preparation' ) {
				e.chargeT += dt;
				if ( ! monde.mouvementReduit && Math.random() < 0.4 ) {
					ynWE.rendu.ajouterParticule( monde, e.x - e.dir * hasard( 10, 40 ), e.y - 2, -e.dir * hasard( 20, 60 ), hasard( -40, -10 ), 0.4, '#d9c7a0', 3 );
				}
				if ( e.chargeT >= ( cfg.preparation || 0.6 ) ) {
					e.charge = 'ruee';
					e.chargeT = 0;
					e.touche = false;
					// Traversante : la charge dépasse le joueur.
					e.chargeCible = j.x + e.dir * ( cfg.depassement || 90 );
					changerEtat( e, 'course' );
				}
				return 0;
			}
			if ( e.charge === 'ruee' ) {
				e.chargeT += dt;
				if ( ! e.touche && ( j.pare || j.invincible <= 0 ) && ynWE.chevauche( j.boite(), boite( e ) ) ) {
					e.touche = true;
					if ( toucherJoueur( e, j, cfg.degats || 1, monde ) === 'pare' ) {
						finirCharge( e ); // Parée : la charge s'arrête net.
						return 0;
					}
				}
				var fin = e.dir > 0 ? e.x >= e.chargeCible : e.x <= e.chargeCible;
				if ( fin || e.chargeT > 3 || ( e.dir > 0 ? e.x > monde.largeur - 30 : e.x < 30 ) ) {
					finirCharge( e );
					ynWE.secouer( monde, 0.15 );
					return 0;
				}
				if ( ! monde.mouvementReduit && Math.random() < 0.5 ) {
					ynWE.rendu.ajouterParticule( monde, e.x - e.dir * 30 * e.taille, e.y - 2, -e.dir * hasard( 30, 90 ), hasard( -60, -10 ), 0.4, '#d9c7a0', 3 );
				}
				return e.dir * ( cfg.vitesse || 230 );
			}
			if ( e.chargeMinuterie <= 0 && estVisible( e, monde ) && Math.abs( j.x - e.x ) > 70 ) {
				e.dir = j.x > e.x ? 1 : -1;
				e.charge = 'preparation';
				e.chargeT = 0;
				mettreEtat( e, 'repos' );
				return 0;
			}
			return approcher( e, monde, false );
		},
	};

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
		volant: volant,
		tireur: tireur,
		bouclier: bouclier,
		boss: boss,
	};

	/*
	 * Les ennemis ne se superposent pas tout à fait (v1). Un volant et un marcheur ne se
	 * gênent pas, ni deux ennemis à des étages différents ; le boss n'est pas déplacé.
	 */
	function separer( monde ) {
		var liste = monde.ennemis;
		liste.forEach( function ( a, ia ) {
			liste.forEach( function ( b, ib ) {
				if ( ib <= ia || a.etat === 'mort' || b.etat === 'mort' ) {
					return;
				}
				if ( a.vole !== b.vole || Math.abs( a.y - b.y ) > 20 ) {
					return;
				}
				var ecart = ( 18 * ( a.taille + b.taille ) ) - Math.abs( a.x - b.x );
				if ( ecart > 0 ) {
					var sens = a.x < b.x ? -1 : 1;
					var partA = 0.25;
					var partB = 0.25;
					if ( a.comportement === 'boss' ) {
						partA = 0;
						partB = 0.5;
					} else if ( b.comportement === 'boss' ) {
						partA = 0.5;
						partB = 0;
					}
					a.x += sens * ecart * partA;
					b.x -= sens * ecart * partB;
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

	/* Bouclier dessiné devant l'ennemi (arc lumineux). */
	function dessinerBouclier( r, e ) {
		var ctx = r.ctx;
		ctx.save();
		ctx.translate( e.x + e.dir * 24 * e.taille, e.y - 22 * e.taille );
		ctx.scale( e.dir, 1 );
		ctx.globalAlpha = 0.85;
		ctx.lineCap = 'round';
		ctx.strokeStyle = '#6d7f96';
		ctx.lineWidth = 5;
		ctx.beginPath();
		ctx.arc( -8 * e.taille, 0, 20 * e.taille, -0.95, 0.95 );
		ctx.stroke();
		ctx.strokeStyle = '#d8e6f5';
		ctx.lineWidth = 1.5;
		ctx.beginPath();
		ctx.arc( -8 * e.taille, 0, 20 * e.taille, -0.8, 0.8 );
		ctx.stroke();
		ctx.restore();
	}

	function dessiner( r, e, monde ) {
		var meta = e.planche.meta;
		var nom = e.etat === 'attaque' ? e.attaque : e.etat;
		var alpha = 1;
		if ( e.etat === 'mort' ) {
			alpha = Math.max( 0, 1 - Math.max( 0, e.t - ynWE.dureeAnimation( meta, 'mort' ) ) / DUREE_FONDU );
		}
		if ( e.vole ) {
			// Ombre sur la surface sous le volant (plateforme ou sol).
			var surface = ynWE.physique.surfaceSous ? ynWE.physique.surfaceSous( monde, e.x, e.y ) : monde.sol;
			var hauteur = Math.max( 0, surface - e.y );
			r.ombre( e.x, surface + 1,28 * e.taille * Math.max( 0.4, 1 - hauteur / 200 ), 3, 0.14 );
		} else {
			r.ombre( e.x, e.y + 1, 28 * e.taille, 3, 0.22 );
		}
		var flash = e.flash > 0 ? 1 : 0;
		var tAnim = e.etat === 'attaque' ? tempsAttaque( e ) : e.t;
		if ( e.charge === 'preparation' ) {
			flash = monde.mouvementReduit ? 0.5 : ( Math.floor( e.chargeT * 12 ) % 2 ? 0.8 : 0 );
		} else if ( e.etat === 'attaque' && ! e.tir && e.t < ( e.type.preavis || 0 ) ) {
			flash = Math.max( flash, monde.mouvementReduit ? 0.4 : ( Math.floor( e.t * 10 ) % 2 ? 0.6 : 0 ) ); // Préavis.
		}
		r.sprite( e.planche, nom, ynWE.imageCourante( meta, nom, tAnim ), e.x, e.y, e.dir, e.taille, e.definition.lissage !== false, alpha, flash );
		if ( e.comportement === 'bouclier' && e.etat !== 'mort' ) {
			dessinerBouclier( r, e );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Projectiles                                                         */
	/* ------------------------------------------------------------------ */

	/* Ajoute un projectile au monde (champs manquants complétés) ; renvoie le projectile. */
	function ajouterProjectile( monde, p ) {
		p.camp = p.camp || 'ennemi';
		p.vx = p.vx || 0;
		p.vy = p.vy || 0;
		p.t = p.t || 0;
		p.vie = typeof p.vie === 'number' ? p.vie : 2;
		p.degats = p.degats || 1;
		p.touches = p.touches || [];
		if ( monde.projectiles.length < MAX_PROJECTILES ) {
			monde.projectiles.push( p );
		}
		return p;
	}

	function boiteProjectile( p ) {
		if ( p.l && p.h ) {
			return { x: p.x - p.l / 2, y: p.y - p.h / 2, l: p.l, h: p.h };
		}
		var rayon = p.rayon || 3;
		return { x: p.x - rayon, y: p.y - rayon, l: rayon * 2, h: rayon * 2 };
	}

	/* Projectile ennemi paré : il repart vers les ennemis. */
	function renvoyer( p, monde ) {
		p.camp = 'joueur';
		p.vx = -p.vx * 1.2;
		p.vy = -p.vy;
		p.touches = [];
		p.renvoye = true;
		p.vie = Math.max( p.vie, 1.5 );
		etincelles( monde, p.x, p.y, p.vx >= 0 ? 1 : -1, 6 );
	}

	function eteindre( p, monde ) {
		p.vie = 0;
		var n = monde.mouvementReduit ? 1 : 4;
		for ( var i = 0; i < n; i++ ) {
			ynWE.rendu.ajouterParticule( monde, p.x, p.y, hasard( -60, 60 ), hasard( -80, -10 ), 0.3, p.couleur || '#ffffff', 2 );
		}
	}

	/*
	 * Tir du joueur au format du lot B : avancé et dessiné par competences.projectile
	 * (avancer(p, dt, monde) → faux quand il disparaît ; dessinerTir(r, p)). Un projectile
	 * ennemi renvoyé par une parade reste géré ici.
	 */
	function tirJoueur( p ) {
		var impl = ynWE.competences && ynWE.competences.projectile;
		return p.camp === 'joueur' && ! p.renvoye && impl && typeof impl.avancer === 'function' ? impl : null;
	}

	function mettreAJourProjectiles( monde, dt ) {
		var j = monde.joueur;
		monde.projectiles.slice().forEach( function ( p ) {
			var impl = tirJoueur( p );
			if ( impl ) {
				if ( impl.avancer( p, dt, monde ) === false ) {
					p.vie = 0;
				}
				return;
			}
			p.t = ( p.t || 0 ) + dt;
			p.vie = ( typeof p.vie === 'number' ? p.vie : 2 ) - dt;
			if ( typeof p.mettreAJour === 'function' ) {
				p.mettreAJour( p, dt, monde );
			} else {
				if ( p.gravite ) {
					p.vy = ( p.vy || 0 ) + p.gravite * dt;
				}
				p.x += ( p.vx || 0 ) * dt;
				p.y += ( p.vy || 0 ) * dt;
			}
			if ( p.vie <= 0 ) {
				return;
			}
			var b = boiteProjectile( p );
			var sens = ( p.vx || 0 ) >= 0 ? 1 : -1;
			if ( p.camp === 'ennemi' ) {
				if ( j && j.etat !== 'mort' && ynWE.chevauche( b, j.boite() ) ) {
					if ( j.pare ) {
						renvoyer( p, monde );
					} else if ( j.invincible <= 0 ) {
						j.blesser( sens, p.degats || 1, monde );
						eteindre( p, monde );
						return;
					}
				}
			} else {
				p.touches = p.touches || [];
				var avant = p.touches.length;
				ynWE.competences.frapper( monde, b, p.degats || 1, p.touches, { type: p.type || 'projectile', dir: sens } );
				if ( p.touches.length > avant && ! ( p.perce || p.percant ) ) {
					eteindre( p, monde );
					return;
				}
			}
			if ( p.y >= monde.sol ) {
				eteindre( p, monde );
				return;
			}
			if ( ! monde.mouvementReduit && Math.random() < 0.35 ) {
				ynWE.rendu.ajouterParticule( monde, p.x, p.y, -sens * hasard( 5, 25 ), hasard( -15, 15 ), 0.25, p.couleur || '#ffffff', 2, true );
			}
		} );
		monde.projectiles = monde.projectiles.filter( function ( p ) {
			return p.vie > 0 && p.x > -40 && p.x < monde.largeur + 40 && p.y > -80;
		} );
	}

	/* Trait coloré orienté selon la vitesse, cœur clair. */
	function dessinerProjectiles( r, monde ) {
		var ctx = r.ctx;
		monde.projectiles.forEach( function ( p ) {
			var impl = tirJoueur( p );
			if ( impl && typeof impl.dessinerTir === 'function' ) {
				impl.dessinerTir( r, p );
				return;
			}
			if ( typeof p.dessiner === 'function' ) {
				p.dessiner( r, p, monde );
				return;
			}
			var couleur = p.couleur || ( p.camp === 'ennemi' ? '#c9f26a' : '#bfe6ff' );
			var v = Math.sqrt( p.vx * p.vx + p.vy * p.vy ) || 1;
			var lx = p.vx / v * 10;
			var ly = p.vy / v * 10;
			var rayon = p.rayon || 3;
			ctx.save();
			ctx.globalAlpha = Math.min( 1, p.vie * 4 );
			ctx.lineCap = 'round';
			ctx.strokeStyle = couleur;
			ctx.lineWidth = rayon;
			ctx.beginPath();
			ctx.moveTo( p.x - lx, p.y - ly );
			ctx.lineTo( p.x, p.y );
			ctx.stroke();
			ctx.fillStyle = couleur;
			ctx.beginPath();
			ctx.arc( p.x, p.y, rayon, 0, Math.PI * 2 );
			ctx.fill();
			ctx.fillStyle = '#ffffff';
			ctx.beginPath();
			ctx.arc( p.x, p.y, rayon * 0.45, 0, Math.PI * 2 );
			ctx.fill();
			ctx.restore();
		} );
	}

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
		ajouterProjectile: ajouterProjectile,
		separer: separer,
		retirerFinis: retirerFinis,
		changerEtat: changerEtat,
	};
}() );
