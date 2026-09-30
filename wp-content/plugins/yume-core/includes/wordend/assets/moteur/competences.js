/**
 * WordEnd — moteur : compétences du joueur (registre).
 *
 * Chaque compétence : { demarrer(joueur, monde, def) → bool, mettreAJour(joueur, dt, monde, def,
 * commandes), dessiner(r, joueur, monde, def), animation(joueur, monde, def) → [nom, i] }.
 * def = objet competences.principale|secondaire du JSON du personnage ; commandes = {maintenu}
 * (bouton de la compétence maintenu). Pendant la compétence, joueur.emplacement vaut
 * 'principale' ou 'secondaire' (clé de joueur.recharges).
 *
 * Lot 0 : melee (coup d'épée v1) et onde (charge magique v1) ; projectile, ruee, parade au lot B.
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var hasard = ynWE.hasard;

	/*
	 * Frappe les ennemis vivants qui chevauchent la boîte, une fois chacun (dejaTouches : ids).
	 * source (facultatif) : {type: 'melee'|'onde'|'projectile'|…, dir} transmis à ennemis.blesser.
	 */
	function frapper( monde, boite, degats, dejaTouches, source ) {
		monde.ennemis.forEach( function ( e ) {
			if ( e.etat !== 'mort' && dejaTouches.indexOf( e.id ) === -1 && ynWE.chevauche( boite, ynWE.ennemis.boite( e ) ) ) {
				dejaTouches.push( e.id );
				ynWE.ennemis.blesser( e, degats, boite.x + boite.l / 2 < e.x ? 1 : -1, monde, source );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* melee : coup porté sur les images « coup » de l'animation            */
	/* ------------------------------------------------------------------ */

	function boiteMelee( j, def ) {
		var b = def.boite || { x: 4, y: -62, l: 60, h: 58 };
		return j.dir > 0 ?
			{ x: j.x + b.x, y: j.y + b.y, l: b.l, h: b.h } :
			{ x: j.x - b.x - b.l, y: j.y + b.y, l: b.l, h: b.h };
	}

	var melee = {
		demarrer: function ( j ) {
			j.touches = [];
			j.changerEtat( 'attaque' );
			return true;
		},
		mettreAJour: function ( j, dt, monde, def ) {
			var meta = j.planche.meta;
			var nom = def.animation || 'attaque';
			j.vx = 0;
			var i = ynWE.imageCourante( meta, nom, j.t );
			if ( ( ynWE.animation( meta, nom ).coup || [] ).indexOf( i ) !== -1 ) {
				frapper( monde, boiteMelee( j, def ), def.degats || 1, j.touches, { type: 'melee', dir: j.dir } );
			}
			if ( j.t >= ynWE.dureeAnimation( meta, nom ) ) {
				j.changerEtat( 'repos' );
			}
		},
		animation: function ( j, monde, def ) {
			var nom = def.animation || 'attaque';
			return [ nom, ynWE.imageCourante( j.planche.meta, nom, j.t ) ];
		},
		dessiner: function () {},
		boite: boiteMelee,
	};

	/* ------------------------------------------------------------------ */
	/* onde : concentration (maintenu), puis onde qui traverse les ennemis */
	/* ------------------------------------------------------------------ */

	var onde = {
		demarrer: function ( j ) {
			if ( ( j.recharges[ j.emplacement ] || 0 ) > 0 ) {
				return false;
			}
			j.changerEtat( 'competence' );
			j.phase = 'concentration';
			return true;
		},
		mettreAJour: function ( j, dt, monde, def, commandes ) {
			var chargeMin = def.chargeMin || 0.55;
			j.vx = 0;
			if ( j.phase === 'concentration' ) {
				if ( ! commandes.maintenu ) {
					if ( j.t >= chargeMin ) {
						j.changerEtat( 'competence' );
						j.phase = 'onde';
						onde.lancer( j, monde, def );
					} else {
						j.changerEtat( 'repos' );
					}
				} else if ( j.t >= chargeMin && ! monde.mouvementReduit && Math.random() < 0.5 ) {
					ynWE.rendu.ajouterParticule( monde, j.x + j.dir * hasard( 10, 40 ), j.y - hasard( 30, 60 ), hasard( -20, 20 ), hasard( -40, -10 ), 0.4, '#bfe6ff', 2 );
				}
			} else if ( j.t >= ( def.duree || 0.42 ) ) {
				j.changerEtat( 'repos' );
			}
		},
		lancer: function ( j, monde, def ) {
			var depart = def.depart || { x: 40, y: -34 };
			j.recharges[ j.emplacement ] = def.recharge || 0;
			monde.ondes.push( {
				x: j.x + j.dir * depart.x,
				y: j.y + depart.y,
				dir: j.dir,
				vie: def.vie || 1.1,
				t: 0,
				touches: [],
				degats: def.degats || 3,
				vitesse: def.vitesse || 250,
				boite: def.boiteOnde || { l: 28, h: 52 },
			} );
			ynWE.secouer( monde, 0.12 );
		},
		animation: function ( j, monde, def ) {
			var nom = def.animation || 'charge';
			var chargeMin = def.chargeMin || 0.55;
			var anim = ynWE.animation( j.planche.meta, nom );
			if ( j.phase === 'onde' ) {
				return [ nom, typeof anim.onde === 'number' ? anim.onde : anim.images.length - 1 ];
			}
			if ( j.t < 0.2 ) {
				return [ nom, 0 ];
			}
			if ( j.t < chargeMin ) {
				return [ nom, 1 ];
			}
			return [ nom, monde.mouvementReduit || Math.floor( j.t * 8 ) % 2 ? 2 : 1 ];
		},
		/* Jauge de concentration au-dessus de la tête. */
		dessiner: function ( r, j, monde, def ) {
			if ( j.phase !== 'concentration' ) {
				return;
			}
			var part = Math.min( 1, j.t / ( def.chargeMin || 0.55 ) );
			r.jauge( j.x - 16, j.y - 82, 32, 4, part, part >= 1 ? '#8fd0ff' : r.palette.texteFaible );
		},
	};

	/* Ondes lancées (monde.ondes) : avancent, frappent, puis s'éteignent. */
	function mettreAJourOndes( monde, dt ) {
		monde.ondes.forEach( function ( o ) {
			o.t += dt;
			o.vie -= dt;
			o.x += o.dir * o.vitesse * dt;
			frapper( monde, { x: o.x - o.boite.l / 2, y: o.y - o.boite.h / 2, l: o.boite.l, h: o.boite.h }, o.degats, o.touches, { type: 'onde', dir: o.dir } );
			if ( ! monde.mouvementReduit && Math.random() < 0.6 ) {
				ynWE.rendu.ajouterParticule( monde, o.x - o.dir * 10, o.y + hasard( -20, 20 ), -o.dir * hasard( 10, 40 ), hasard( -20, 20 ), 0.35, '#d8f1ff', 2 );
			}
		} );
		monde.ondes = monde.ondes.filter( function ( o ) {
			return o.vie > 0 && o.x > -40 && o.x < monde.largeur + 40;
		} );
	}

	function dessinerOndes( r, monde ) {
		var ctx = r.ctx;
		monde.ondes.forEach( function ( o ) {
			ctx.save();
			ctx.translate( o.x, o.y );
			ctx.scale( o.dir, 1 );
			ctx.globalAlpha = Math.min( 1, o.vie * 3 );
			for ( var k = 0; k < 3; k++ ) {
				ctx.strokeStyle = k === 0 ? '#e8f7ff' : ( k === 1 ? '#9fdcff' : '#5aa9e6' );
				ctx.lineWidth = 5 - k * 1.5;
				ctx.beginPath();
				ctx.arc( -10 - k * 6, 0, 26 - k * 2, -1.1, 1.1 );
				ctx.stroke();
			}
			ctx.restore();
		} );
		ctx.globalAlpha = 1;
	}

	/* Compétences du lot B (stubs). */
	function aVenir( nom ) {
		return {
			demarrer: ynWE.nonImplemente( 'competences.' + nom ),
			mettreAJour: ynWE.nonImplemente( 'competences.' + nom + '.mettreAJour' ),
			dessiner: function () {},
			animation: function () {
				return [ 'repos', 0 ];
			},
		};
	}

	ynWE.competences = {
		melee: melee,
		onde: onde,
		projectile: aVenir( 'projectile' ),
		ruee: aVenir( 'ruee' ),
		parade: aVenir( 'parade' ),
		enregistrer: function ( nom, impl ) {
			ynWE.competences[ nom ] = impl;
		},
		frapper: frapper,
		mettreAJourOndes: mettreAJourOndes,
		dessinerOndes: dessinerOndes,
	};
}() );
