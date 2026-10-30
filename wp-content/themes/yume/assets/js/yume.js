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
 * - Sur un chapitre, l'arbre décoratif (.yn-sprite--lecture) reçoit la marge libre à droite de la
 *   colonne de lecture (--yn-marge-lecture) pour ne jamais passer sous le texte.
 * - Couleur de l'interface du navigateur (meta theme-color) : bande du thème actif, celle de la
 *   saison quand html[data-yn-saison] est posé (inc/saisons.php ; Sépia ne change pas).
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	var CLE = 'yn.theme';
	var THEMES = [ 'nuit', 'papier', 'sepia' ];
	var COULEURS_NAVIGATEUR = { nuit: '#241740', papier: '#f6eef3', sepia: '#efe2cc' };
	// Bande des thèmes saisonniers (assets/css/yume.css, section 1 bis ; inc/saisons.php).
	var COULEURS_SAISONS = {
		ete: { nuit: '#142a44', papier: '#e6f2fb' },
		automne: { nuit: '#2d1a14', papier: '#f8ebdd' },
		hiver: { nuit: '#1b2537', papier: '#e9f0f7' },
		halloween: { nuit: '#211537', papier: '#f3e8fb' },
		noel: { nuit: '#162b22', papier: '#e7f3ea' }
	};
	var racine = document.documentElement;

	function valide( theme ) {
		return THEMES.indexOf( theme ) > -1 ? theme : 'nuit';
	}

	function lire() {
		return valide( racine.getAttribute( 'data-yn-theme' ) );
	}

	function couleurNavigateur( theme ) {
		var saison = racine.getAttribute( 'data-yn-saison' );
		var couleurs = saison && Object.prototype.hasOwnProperty.call( COULEURS_SAISONS, saison ) ? COULEURS_SAISONS[ saison ] : null;
		return couleurs && couleurs[ theme ] ? couleurs[ theme ] : COULEURS_NAVIGATEUR[ theme ];
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
			meta.setAttribute( 'content', couleurNavigateur( theme ) );
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

	// Arbre décoratif sur un chapitre (.yn-sprite--lecture, inc/saisons.php) : il ne passe jamais
	// sous la colonne de lecture. On mesure la marge libre à droite de .yn-reader (largeur réglable
	// par le panneau de lecture) et on la transmet en variable CSS ; sous 160 px, l'arbre est masqué.
	function majSpriteLecture() {
		var sprite = document.querySelector( '.yn-sprite--lecture' );
		var colonne = document.querySelector( '.yn-reader' );
		if ( ! sprite || ! colonne ) {
			return;
		}
		var marge = Math.floor( document.documentElement.clientWidth - colonne.getBoundingClientRect().right - 24 );
		sprite.style.setProperty( '--yn-marge-lecture', Math.max( 0, marge ) + 'px' );
		sprite.classList.toggle( 'yn-sprite--cache', marge < 160 );
	}

	function surveillerSpriteLecture() {
		var colonne = document.querySelector( '.yn-reader' );
		if ( ! document.querySelector( '.yn-sprite--lecture' ) || ! colonne ) {
			return;
		}
		var prevu = false;
		var replanifier = function () {
			if ( prevu ) {
				return;
			}
			prevu = true;
			window.requestAnimationFrame( function () {
				prevu = false;
				majSpriteLecture();
			} );
		};
		majSpriteLecture();
		window.addEventListener( 'resize', replanifier );
		window.addEventListener( 'load', replanifier );
		if ( 'MutationObserver' in window ) {
			// Le panneau de lecture écrit --yn-width (et la taille du texte) dans l'attribut style.
			new MutationObserver( replanifier ).observe( colonne, { attributes: true, attributeFilter: [ 'style', 'class' ] } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			majInterface( lire() );
			surveillerSpriteLecture();
		} );
	} else {
		majInterface( lire() );
		surveillerSpriteLecture();
	}
}() );
