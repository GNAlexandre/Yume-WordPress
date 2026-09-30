/**
 * WordEnd — moteur : physique (corps, gravité, sol, plateformes, bords).
 *
 * Un corps est ancré au sol, au milieu des pieds : (x, y) ; sa boîte est {x − l/2, y − h, l, h}.
 * Gravité monde.gravite (px/s²), vitesse de chute plafonnée à VITESSE_MAX, sol monde.sol,
 * plateformes monde.plateformes [{x, y, l, type?, h?}] :
 * - « traversable » (défaut) : on n'y atterrit qu'en descente, quand les pieds étaient au-dessus
 *   au pas précédent ; on la traverse vers le bas tant que corps.traverse > 0 (bas + saut) ;
 * - « solide » : même atterrissage, jamais traversée vers le bas, bloque la tête par-dessous et
 *   les côtés (épaisseur p.h, défaut EPAISSEUR).
 * Bords du monde : [BORD, largeur − BORD]. Interface : docs/wordend-formats.md (§3.6).
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var BORD = 18;
	var VITESSE_MAX = 600;
	var EPAISSEUR = 6;

	/* Corps physique ; un objet existant (joueur, ennemi) est complété et renvoyé tel quel. */
	function corps( proprietes ) {
		var c = proprietes || {};
		c.x = typeof c.x === 'number' ? c.x : 0;
		c.y = typeof c.y === 'number' ? c.y : 0;
		c.vx = c.vx || 0;
		c.vy = c.vy || 0;
		c.l = c.l || 0;
		c.h = c.h || 0;
		c.auSol = !! c.auSol;
		c.traverse = c.traverse || 0;
		c.support = c.support || null; // Plateforme sous les pieds (null : sol ou en l'air).
		return c;
	}

	function estSolide( p ) {
		return p.type === 'solide';
	}

	function epaisseur( p ) {
		return typeof p.h === 'number' && p.h > 0 ? p.h : EPAISSEUR;
	}

	/* Vrai si l'abscisse x (milieu des pieds) est au-dessus de la plateforme. */
	function dessus( p, x ) {
		return x >= p.x && x <= p.x + p.l;
	}

	/* Repousse le corps hors des plateformes solides qu'il chevauche de côté. */
	function bloquerCotes( c, monde, xAvant ) {
		monde.plateformes.forEach( function ( p ) {
			if ( ! estSolide( p ) ) {
				return;
			}
			var haut = c.y - c.h;
			var bas = c.y;
			if ( bas <= p.y + 0.5 || haut >= p.y + epaisseur( p ) ) {
				return; // Posé dessus, ou entièrement dessous.
			}
			var gauche = c.x - c.l / 2;
			var droite = c.x + c.l / 2;
			if ( droite <= p.x || gauche >= p.x + p.l ) {
				return;
			}
			if ( xAvant <= p.x + p.l / 2 ) {
				c.x = p.x - c.l / 2;
			} else {
				c.x = p.x + p.l + c.l / 2;
			}
			c.vx = 0;
		} );
	}

	/**
	 * Avance un corps d'un pas.
	 *
	 * @param {Object} c       Corps.
	 * @param {number} dt      Pas (s).
	 * @param {Object} monde   Monde (sol, largeur, gravite, plateformes).
	 * @param {Object} options {gravite: true, plateformes: true, bords: true} (false pour désactiver).
	 */
	function appliquer( c, dt, monde, options ) {
		options = options || {};
		var plateformes = options.plateformes !== false ? monde.plateformes || [] : [];
		var xAvant = c.x;
		c.traverse = Math.max( 0, c.traverse - dt );

		c.x += c.vx * dt;
		if ( plateformes.length ) {
			bloquerCotes( c, { plateformes: plateformes }, xAvant );
		}
		if ( options.bords !== false ) {
			c.x = ynWE.limiter( c.x, BORD, monde.largeur - BORD );
		}

		if ( options.gravite === false ) {
			// Corps libre (volant) : suit sa vitesse verticale, sans sol ni plateformes.
			c.y += c.vy * dt;
			c.auSol = false;
			c.support = null;
			return;
		}

		// Intégration exacte sur le pas (vitesse moyenne) : hauteur de saut = impulsion² / (2 × gravité).
		var yAvant = c.y;
		var vyAvant = c.vy;
		c.vy = Math.min( VITESSE_MAX, c.vy + monde.gravite * dt );
		c.y += ( vyAvant + c.vy ) / 2 * dt;
		c.auSol = false;
		c.support = null;

		if ( c.vy < 0 ) {
			// Montée : la tête bute sous une plateforme solide.
			plateformes.forEach( function ( p ) {
				var dessous = p.y + epaisseur( p );
				if ( estSolide( p ) && dessus( p, c.x ) && yAvant - c.h >= dessous && c.y - c.h < dessous ) {
					c.y = dessous + c.h;
					c.vy = 0;
				}
			} );
		} else {
			// Descente : atterrissage sur la plus haute plateforme franchie pendant ce pas.
			var cible = null;
			plateformes.forEach( function ( p ) {
				if ( ! dessus( p, c.x ) || yAvant > p.y + 0.01 || c.y < p.y ) {
					return;
				}
				if ( c.traverse > 0 && ! estSolide( p ) ) {
					return;
				}
				if ( ! cible || p.y < cible.y ) {
					cible = p;
				}
			} );
			if ( cible && cible.y < monde.sol ) {
				c.y = cible.y;
				c.vy = 0;
				c.auSol = true;
				c.support = cible;
			}
		}

		if ( c.y >= monde.sol ) {
			c.y = monde.sol;
			c.vy = 0;
			c.auSol = true;
			c.support = null;
		}
	}

	/* Hauteur de la surface (sol ou plateforme) juste sous le point (x, y) : pour l'ombre. */
	function surfaceSous( monde, x, y ) {
		var surface = monde.sol;
		( monde.plateformes || [] ).forEach( function ( p ) {
			if ( dessus( p, x ) && p.y >= y - 0.5 && p.y < surface ) {
				surface = p.y;
			}
		} );
		return surface;
	}

	/* Boîte d'un corps : ancre au sol, centrée horizontalement. */
	function boite( c ) {
		return { x: c.x - c.l / 2, y: c.y - c.h, l: c.l, h: c.h };
	}

	ynWE.physique = {
		BORD: BORD,
		VITESSE_MAX: VITESSE_MAX,
		EPAISSEUR: EPAISSEUR,
		corps: corps,
		appliquer: appliquer,
		boite: boite,
		surfaceSous: surfaceSous,
		estSolide: estSolide,
		chevauche: ynWE.chevauche,
	};
}() );
