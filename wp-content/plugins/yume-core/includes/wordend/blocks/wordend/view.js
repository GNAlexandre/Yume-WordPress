/**
 * Page du jeu WordEnd (bloc yume/wordend) : l'iframe du jeu n'est créée qu'au clic sur
 * « Lancer le jeu » (le jeu pèse plusieurs dizaines de Mo), puis reçoit le focus pour le
 * clavier ; bouton « Plein écran » sur l'iframe, masqué quand le navigateur ne le permet pas
 * (iPhone : pas de plein écran pour une iframe).
 *
 * Balisage : [data-yn-jeu] (racine), [data-yn-jeu-cadre] (zone 16:9, data-src = adresse du
 * jeu), [data-yn-jeu-lancer], [data-yn-jeu-plein-ecran]. ES2019, sans dépendance.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-yn-jeu]' ).forEach( function ( racine ) {
		var zone = racine.querySelector( '[data-yn-jeu-cadre]' );
		var lancer = racine.querySelector( '[data-yn-jeu-lancer]' );
		var plein = racine.querySelector( '[data-yn-jeu-plein-ecran]' );
		if ( ! zone || ! lancer ) {
			return;
		}
		var cadre = null;

		function creerCadre() {
			if ( cadre ) {
				return cadre;
			}
			cadre = document.createElement( 'iframe' );
			cadre.src = zone.getAttribute( 'data-src' );
			cadre.title = 'WordEnd';
			cadre.setAttribute( 'allow', 'fullscreen; gamepad; autoplay' );
			cadre.className = 'yn-wordend-page__iframe';
			zone.textContent = '';
			zone.appendChild( cadre );
			racine.classList.add( 'is-lance' );
			cadre.addEventListener( 'load', function () {
				cadre.focus();
			} );
			cadre.focus();
			return cadre;
		}

		lancer.addEventListener( 'click', function () {
			creerCadre();
		} );

		if ( ! plein ) {
			return;
		}
		var permis = document.fullscreenEnabled || document.webkitFullscreenEnabled;
		var demande = zone.requestFullscreen || zone.webkitRequestFullscreen;
		if ( ! permis || ! demande ) {
			return;
		}
		plein.hidden = false;
		plein.addEventListener( 'click', function () {
			var cible = creerCadre();
			var methode = cible.requestFullscreen || cible.webkitRequestFullscreen;
			var promesse = methode.call( cible );
			if ( promesse && promesse.then ) {
				promesse.then( function () {
					cible.focus();
				}, function () {} );
			} else {
				cible.focus();
			}
		} );
	} );
}() );
