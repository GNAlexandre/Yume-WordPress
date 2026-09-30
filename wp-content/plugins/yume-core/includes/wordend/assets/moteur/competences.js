/**
 * WordEnd — moteur : compétences du joueur (registre).
 *
 * Chaque compétence : { demarrer(joueur, monde, def) → bool, mettreAJour(joueur, dt, monde, def,
 * commandes), dessiner(r, joueur, monde, def), animation(joueur, monde, def) → [nom, i] }.
 * def = objet competences.principale|secondaire du JSON du personnage ; commandes = {maintenu}
 * (bouton de la compétence maintenu). Pendant la compétence, joueur.emplacement vaut
 * 'principale' ou 'secondaire' (clé de joueur.recharges).
 *
 * Une compétence peut aussi fournir encaisser(joueur, sens, degats, monde, def) → vrai si elle
 * absorbe un coup reçu pendant qu'elle est active (parade, ruée : joueur.blesser l'appelle).
 *
 * melee (coup d'épée v1), onde (charge magique v1), projectile (tir, camp « joueur »), ruee
 * (déplacement rapide invulnérable), parade (j.pare : coups absorbés, attaquant repoussé).
 * Interface : docs/wordend-formats.md (§3.7).
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

	var PORTEE_BAS = 32;

	/*
	 * Boîte du coup. En l'air ou debout sur une plateforme (j.support), elle s'allonge vers le
	 * bas de def.porteeBas px (32 par défaut : depuis une plateforme à 50–58 px du sol, atteint
	 * le haut d'un petit Timere de 35 px) : un ennemi au sol juste sous une plateforme basse
	 * reste frappable (sinon joueur et ennemi ne pourraient plus s'atteindre).
	 */
	function boiteMelee( j, def ) {
		var b = def.boite || { x: 4, y: -62, l: 60, h: 58 };
		var bas = ! j.auSol || j.support ? ( typeof def.porteeBas === 'number' ? def.porteeBas : PORTEE_BAS ) : 0;
		return j.dir > 0 ?
			{ x: j.x + b.x, y: j.y + b.y, l: b.l, h: b.h + bas } :
			{ x: j.x - b.x - b.l, y: j.y + b.y, l: b.l, h: b.h + bas };
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
			if ( j.auSol ) {
				j.vx = 0; // En l'air, le coup garde l'élan du saut.
			}
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

	/* ------------------------------------------------------------------ */
	/* projectile : tir lancé sur la première image « coup » de l'animation */
	/* ------------------------------------------------------------------ */

	/*
	 * Projectile du joueur dans monde.projectiles : {id, camp: 'joueur', x, y, vx, vy, dir, degats,
	 * vie, t, l, h, couleur, touches, source {type: 'projectile', dir}, perce}. Il est avancé par
	 * ennemis.mettreAJourProjectiles (lot C), qui peut s'appuyer sur projectile.avancer(p, dt, monde).
	 */
	function boiteProjectile( p ) {
		return { x: p.x - p.l / 2, y: p.y - p.h / 2, l: p.l, h: p.h };
	}

	var projectile = {
		demarrer: function ( j ) {
			j.changerEtat( 'attaque' );
			j.phase = 'visee';
			return true;
		},
		mettreAJour: function ( j, dt, monde, def ) {
			var meta = j.planche.meta;
			var nom = def.animation || 'attaque';
			var anim = ynWE.animation( meta, nom );
			if ( j.auSol ) {
				j.vx = 0;
			}
			var coups = anim.coup && anim.coup.length ? anim.coup : [ 0 ];
			if ( j.phase === 'visee' && ynWE.imageCourante( meta, nom, j.t ) >= coups[ 0 ] ) {
				j.phase = 'tir';
				projectile.tirer( j, monde, def );
			}
			if ( j.t >= ynWE.dureeAnimation( meta, nom ) ) {
				j.changerEtat( 'repos' );
			}
		},
		tirer: function ( j, monde, def ) {
			var depart = def.depart || { x: 22, y: -30 };
			var vitesse = def.vitesse || 300;
			j.recharges[ j.emplacement ] = def.recharge || 0;
			var p = {
				id: monde.prochainId++,
				camp: 'joueur',
				x: j.x + j.dir * depart.x,
				y: j.y + depart.y,
				vx: j.dir * vitesse,
				vy: 0,
				dir: j.dir,
				degats: def.degats || 1,
				vie: def.vie || 1.4,
				t: 0,
				l: 14,
				h: 6,
				couleur: def.couleur || '#ffe28a',
				touches: [],
				source: { type: 'projectile', dir: j.dir },
				perce: !! def.perce,
			};
			monde.projectiles.push( p );
			return p;
		},
		/* Avance un projectile du joueur ; renvoie faux quand il doit disparaître. */
		avancer: function ( p, dt, monde ) {
			p.t += dt;
			p.vie -= dt;
			p.x += p.vx * dt;
			p.y += ( p.vy || 0 ) * dt;
			var avant = p.touches.length;
			frapper( monde, boiteProjectile( p ), p.degats, p.touches, p.source );
			if ( p.touches.length > avant && ! p.perce ) {
				p.vie = 0;
			}
			if ( ! monde.mouvementReduit && Math.random() < 0.4 ) {
				ynWE.rendu.ajouterParticule( monde, p.x - p.dir * 8, p.y, -p.dir * hasard( 10, 30 ), hasard( -10, 10 ), 0.25, p.couleur, 2 );
			}
			return p.vie > 0 && p.x > -40 && p.x < monde.largeur + 40;
		},
		/* Dessin simple : trait lumineux (coordonnées du monde). */
		dessinerTir: function ( r, p ) {
			var ctx = r.ctx;
			ctx.save();
			ctx.globalAlpha = Math.min( 1, p.vie * 4 );
			ctx.strokeStyle = p.couleur;
			ctx.lineWidth = 3;
			ctx.lineCap = 'round';
			ctx.beginPath();
			ctx.moveTo( p.x - p.dir * p.l / 2, p.y );
			ctx.lineTo( p.x + p.dir * p.l / 2, p.y );
			ctx.stroke();
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( p.x + p.dir * p.l / 2 - 1.5, p.y - 1.5, 3, 3 );
			ctx.restore();
		},
		boite: boiteProjectile,
		animation: function ( j, monde, def ) {
			var nom = def.animation || 'attaque';
			return [ nom, ynWE.imageCourante( j.planche.meta, nom, j.t ) ];
		},
		dessiner: function () {},
	};

	/* ------------------------------------------------------------------ */
	/* ruee : déplacement rapide et invulnérable de distance px en duree s */
	/* ------------------------------------------------------------------ */

	var ruee = {
		demarrer: function ( j, monde, def ) {
			j.changerEtat( 'competence' );
			j.phase = 'ruee';
			j.recharges[ j.emplacement ] = def.recharge || 1.5;
			j.vx = j.dir * ( def.distance || 90 ) / ( def.duree || 0.2 );
			return true;
		},
		mettreAJour: function ( j, dt, monde, def ) {
			j.vx = j.dir * ( def.distance || 90 ) / ( def.duree || 0.2 );
			j.vy = Math.min( j.vy, 0 ); // Ruée horizontale, même en l'air.
			if ( ! monde.mouvementReduit && Math.random() < 0.7 ) {
				ynWE.rendu.ajouterParticule( monde, j.x - j.dir * hasard( 6, 16 ), j.y - hasard( 6, 50 ), -j.dir * hasard( 20, 60 ), hasard( -10, 10 ), 0.3, '#ffe9a8', 2 );
			}
			if ( j.t >= ( def.duree || 0.2 ) - 1e-6 ) { // Arrondi des pas : exactement duree / DT pas.
				j.vx = 0;
				j.changerEtat( 'repos' );
			}
		},
		/* Invulnérable pendant la ruée : le coup passe à travers. */
		encaisser: function () {
			return true;
		},
		animation: function ( j, monde, def ) {
			var nom = def.animation || 'course';
			return [ nom, ynWE.imageCourante( j.planche.meta, nom, j.t * 2 ) ];
		},
		/* Image rémanente derrière le personnage. */
		dessiner: function ( r, j, monde, def ) {
			if ( monde.mouvementReduit ) {
				return;
			}
			var nom = def.animation || 'course';
			r.sprite( j.planche, nom, ynWE.imageCourante( j.planche.meta, nom, j.t * 2 ), j.x - j.dir * 14, j.y, j.dir, 1, j.personnage.lissage !== false, 0.3 );
		},
	};

	/* ------------------------------------------------------------------ */
	/* parade : pendant duree, j.pare ; les coups sont absorbés et renvoyés */
	/* ------------------------------------------------------------------ */

	var PORTEE_RENVOI = 90;

	var parade = {
		demarrer: function ( j, monde, def ) {
			j.changerEtat( 'competence' );
			j.phase = 'parade';
			j.pare = true;
			j.recharges[ j.emplacement ] = def.recharge || 2;
			return true;
		},
		mettreAJour: function ( j, dt, monde, def ) {
			if ( j.auSol ) {
				j.vx = 0;
			}
			j.pare = true;
			if ( j.t >= ( def.duree || 0.6 ) ) {
				j.changerEtat( 'repos' );
			}
		},
		/* Coup venu du côté −sens : l'attaquant le plus proche de ce côté est repoussé. */
		encaisser: function ( j, sens, degats, monde ) {
			var cible = null;
			monde.ennemis.forEach( function ( e ) {
				var ecart = ( e.x - j.x ) * -sens;
				if ( e.etat !== 'mort' && ecart > -10 && ecart < PORTEE_RENVOI && ( ! cible || Math.abs( e.x - j.x ) < Math.abs( cible.x - j.x ) ) ) {
					cible = e;
				}
			} );
			if ( cible ) {
				cible.recul = -sens * 220;
				if ( ! cible.stoique && ynWE.ennemis && ynWE.ennemis.changerEtat ) {
					ynWE.ennemis.changerEtat( cible, 'degats' );
				}
			}
			var n = monde.mouvementReduit ? 3 : 10;
			for ( var i = 0; i < n; i++ ) {
				ynWE.rendu.ajouterParticule( monde, j.x - sens * 14, j.y - hasard( 20, 44 ), -sens * hasard( 30, 120 ), hasard( -80, 20 ), 0.35, i % 2 ? '#ffffff' : '#bfe6ff', 2 );
			}
			ynWE.secouer( monde, 0.08 );
			return true;
		},
		animation: function ( j, monde, def ) {
			var pose = j.personnage.poses && j.personnage.poses.parade;
			if ( def.animation && ynWE.animation( j.planche.meta, def.animation ) ) {
				return [ def.animation, ynWE.imageCourante( j.planche.meta, def.animation, j.t ) ];
			}
			return pose ? [ pose[ 0 ], pose[ 1 ] ] : [ 'degats', 0 ];
		},
		/* Bouclier lumineux devant le personnage. */
		dessiner: function ( r, j, monde, def ) {
			var ctx = r.ctx;
			var reste = Math.max( 0, 1 - j.t / ( def.duree || 0.6 ) );
			ctx.save();
			ctx.translate( j.x + j.dir * 12, j.y - 30 );
			ctx.scale( j.dir, 1 );
			ctx.globalAlpha = 0.35 + 0.5 * reste;
			ctx.strokeStyle = '#bfe6ff';
			ctx.lineWidth = 3;
			ctx.beginPath();
			ctx.arc( -8, 0, 26, -1.2, 1.2 );
			ctx.stroke();
			ctx.restore();
		},
	};

	ynWE.competences = {
		melee: melee,
		onde: onde,
		projectile: projectile,
		ruee: ruee,
		parade: parade,
		enregistrer: function ( nom, impl ) {
			ynWE.competences[ nom ] = impl;
		},
		frapper: frapper,
		mettreAJourOndes: mettreAJourOndes,
		dessinerOndes: dessinerOndes,
	};
}() );
