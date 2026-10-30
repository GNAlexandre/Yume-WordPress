/**
 * Thème Yume : décors légers des thèmes saisonniers dans la bande d'en-tête.
 *
 * - Chargé (defer, après yume.js) par inc/saisons.php seulement si une saison est active
 *   (html[data-yn-saison]) et que le réglage « Décors animés » (saison_decor) est coché.
 * - Ajoute dans la bande .yn-header de l'en-tête du site un <div class="yn-decor" aria-hidden="true">
 *   d'au plus 12 particules <span class="yn-decor__p"> placées et rythmées au hasard ; la forme
 *   (pétale, feuille, flocon, chauve-souris) et l'animation sont en CSS (assets/css/yume.css).
 *   Été : aucun décor.
 * - Rien si le lecteur préfère moins d'animations : préférence système prefers-reduced-motion,
 *   option « Réduire les animations » du lecteur (localStorage['yn.a11y'].animations === 'reduites',
 *   html[data-yn-animations]). La feuille de style coupe aussi les décors dans ces cas.
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	var FORMES = {
		printemps: 'petale',
		automne: 'feuille',
		hiver: 'flocon',
		noel: 'flocon',
		halloween: 'chauve-souris'
	};
	var MAXIMUM = 12;
	var racine = document.documentElement;

	function animationsReduites() {
		try {
			if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
				return true;
			}
		} catch ( e ) {
			// matchMedia indisponible : on s'en tient aux autres signaux.
		}
		if ( racine.getAttribute( 'data-yn-animations' ) === 'reduites' ) {
			return true;
		}
		try {
			var a11y = JSON.parse( window.localStorage.getItem( 'yn.a11y' ) || 'null' );
			return !! ( a11y && a11y.animations === 'reduites' );
		} catch ( e ) {
			return false;
		}
	}

	function hasard( min, max ) {
		return min + Math.random() * ( max - min );
	}

	function arrondi( valeur ) {
		return Math.round( valeur * 10 ) / 10;
	}

	function particule( forme ) {
		var p = document.createElement( 'span' );
		var duree = hasard( 8, 16 );
		p.className = 'yn-decor__p';
		if ( forme === 'chauve-souris' ) {
			p.style.setProperty( '--yn-decor-hauteur', arrondi( hasard( 8, 62 ) ) + '%' );
		} else {
			p.style.left = arrondi( hasard( 0, 100 ) ) + '%';
			p.style.setProperty( '--yn-decor-derive', Math.round( hasard( -40, 40 ) ) + 'px' );
			p.style.setProperty( '--yn-decor-rotation', Math.round( hasard( -240, 240 ) ) + 'deg' );
		}
		p.style.setProperty( '--yn-decor-taille', Math.round( hasard( 8, 16 ) ) + 'px' );
		p.style.setProperty( '--yn-decor-duree', arrondi( duree ) + 's' );
		// Délai négatif : les particules sont déjà en mouvement au chargement de la page.
		p.style.setProperty( '--yn-decor-delai', '-' + arrondi( hasard( 0, duree ) ) + 's' );
		return p;
	}

	function installer() {
		var saison = racine.getAttribute( 'data-yn-saison' );
		var forme = saison && Object.prototype.hasOwnProperty.call( FORMES, saison ) ? FORMES[ saison ] : '';
		if ( ! forme || animationsReduites() ) {
			return;
		}
		var bande = document.querySelector( '.yn-site-header .yn-header' );
		if ( ! bande || bande.querySelector( '.yn-decor' ) ) {
			return;
		}
		// Moins de particules sur un petit écran (et moins de chauves-souris, plus grandes).
		var nombre = window.innerWidth < 600 ? 6 : MAXIMUM;
		if ( forme === 'chauve-souris' ) {
			nombre = Math.ceil( nombre / 2 );
		}
		var decor = document.createElement( 'div' );
		decor.className = 'yn-decor yn-decor--' + forme;
		decor.setAttribute( 'aria-hidden', 'true' );
		for ( var i = 0; i < Math.min( nombre, MAXIMUM ); i++ ) {
			decor.appendChild( particule( forme ) );
		}
		bande.insertBefore( decor, bande.firstChild );
		bande.classList.add( 'yn-avec-decor' );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', installer );
	} else {
		installer();
	}
}() );
