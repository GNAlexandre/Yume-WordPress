/**
 * Thème Yume : bascule de thème de couleurs (Nuit ↔ Papier) et synchronisation.
 *
 * - Le thème actif est porté par html[data-yn-theme] (nuit | papier | sepia), posé avant le
 *   premier rendu par le script d'initialisation en ligne (inc/assets.php).
 * - Le choix est mémorisé dans localStorage['yn.theme'] (contrat §14), partagé avec le
 *   panneau de réglages du lecteur.
 * - Les boutons [data-yn-theme-toggle] alternent Nuit ↔ Papier ; depuis Sépia, ils reviennent à Nuit.
 * - API publique : window.ynTheme.get() et window.ynTheme.set( 'nuit' | 'papier' | 'sepia' ).
 * - Chaque changement émet l'événement document « yn:theme » (detail.theme).
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	var CLE = 'yn.theme';
	var THEMES = [ 'nuit', 'papier', 'sepia' ];
	var COULEURS_NAVIGATEUR = { nuit: '#241740', papier: '#f6eef3', sepia: '#efe2cc' };
	var racine = document.documentElement;

	function valide( theme ) {
		return THEMES.indexOf( theme ) > -1 ? theme : 'nuit';
	}

	function lire() {
		return valide( racine.getAttribute( 'data-yn-theme' ) );
	}

	function enregistrer( theme ) {
		try {
			window.localStorage.setItem( CLE, theme );
		} catch ( e ) {
			// Stockage indisponible (navigation privée stricte) : le choix vaut pour la page.
		}
	}

	function majInterface( theme ) {
		var clair = theme !== 'nuit';
		var boutons = document.querySelectorAll( '[data-yn-theme-toggle]' );
		Array.prototype.forEach.call( boutons, function ( bouton ) {
			bouton.setAttribute( 'aria-pressed', clair ? 'true' : 'false' );
			bouton.setAttribute( 'title', clair ? 'Revenir au thème Nuit (sombre)' : 'Passer au thème Papier (clair)' );
		} );
		var meta = document.querySelector( 'meta[name="theme-color"]' );
		if ( meta ) {
			meta.setAttribute( 'content', COULEURS_NAVIGATEUR[ theme ] );
		}
	}

	function appliquer( theme, options ) {
		var reglages = options || {};
		theme = valide( theme );
		var precedent = lire();
		racine.setAttribute( 'data-yn-theme', theme );
		majInterface( theme );
		if ( reglages.enregistrer !== false ) {
			enregistrer( theme );
		}
		if ( precedent !== theme && reglages.evenement !== false ) {
			var evenement;
			try {
				evenement = new CustomEvent( 'yn:theme', { detail: { theme: theme } } );
			} catch ( e ) {
				evenement = document.createEvent( 'CustomEvent' );
				evenement.initCustomEvent( 'yn:theme', false, false, { theme: theme } );
			}
			document.dispatchEvent( evenement );
		}
	}

	// Clic sur une bascule (délégation : fonctionne aussi pour les boutons ajoutés plus tard).
	document.addEventListener( 'click', function ( e ) {
		var cible = e.target;
		var bouton = cible && cible.closest ? cible.closest( '[data-yn-theme-toggle]' ) : null;
		if ( ! bouton ) {
			return;
		}
		e.preventDefault();
		appliquer( lire() === 'nuit' ? 'papier' : 'nuit' );
	} );

	// Autre onglet : on suit le choix sans le réenregistrer.
	window.addEventListener( 'storage', function ( e ) {
		if ( e.key === CLE && e.newValue ) {
			appliquer( e.newValue, { enregistrer: false } );
		}
	} );

	// Changement fait ailleurs (panneau du lecteur) : l'état des boutons suit l'attribut.
	if ( 'MutationObserver' in window ) {
		new MutationObserver( function () {
			majInterface( lire() );
		} ).observe( racine, { attributes: true, attributeFilter: [ 'data-yn-theme' ] } );
	}

	window.ynTheme = {
		get: lire,
		set: function ( theme ) {
			appliquer( theme );
		}
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			majInterface( lire() );
		} );
	} else {
		majInterface( lire() );
	}
}() );
