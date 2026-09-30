/**
 * WordEnd — moteur : physique (corps, sol, bords ; gravité et plateformes au lot B).
 *
 * Lot 0 : sol plat sans gravité (comportement v1) : le corps avance de vx·dt, reste posé sur
 * monde.sol et entre les bords [18, largeur − 18]. Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var BORD = 18;

	/* Corps physique ; un objet existant (joueur, ennemi) peut être passé pour être complété. */
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
		return c;
	}

	/**
	 * Avance un corps d'un pas.
	 *
	 * @param {Object} c       Corps.
	 * @param {number} dt      Pas (s).
	 * @param {Object} monde   Monde (sol, largeur, gravite, plateformes).
	 * @param {Object} options {gravite: true, plateformes: true, bords: true}.
	 */
	function appliquer( c, dt, monde, options ) {
		options = options || {};
		c.x += c.vx * dt;
		if ( options.bords !== false ) {
			c.x = ynWE.limiter( c.x, BORD, monde.largeur - BORD );
		}
		// Lot 0 : sol plat, sans gravité ni plateformes.
		c.y = monde.sol;
		c.vy = 0;
		c.auSol = true;
		c.traverse = Math.max( 0, c.traverse - dt );
	}

	/* Boîte d'un corps : ancre au sol, centrée horizontalement. */
	function boite( c ) {
		return { x: c.x - c.l / 2, y: c.y - c.h, l: c.l, h: c.h };
	}

	ynWE.physique = {
		BORD: BORD,
		corps: corps,
		appliquer: appliquer,
		boite: boite,
		chevauche: ynWE.chevauche,
	};
}() );
