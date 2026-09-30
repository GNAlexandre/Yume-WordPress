/**
 * Déclencheur de l'easter egg WordEnd (chargé en defer sur les pages publiques).
 *
 * Ouvre le jeu par :
 * - le code Konami au clavier (↑ ↑ ↓ ↓ ← → ← → B A), hors champs de saisie ;
 * - un appui long (1,2 s) sur la bascule de thème [data-yn-theme-toggle] (écrans tactiles) ;
 * - un clic sur un élément [data-yn-wordend-ouvrir] (papillon de la fiche d'œuvre).
 *
 * Le jeu (jeu.js, jeu.css) n'est chargé qu'à la première ouverture. Configuration :
 * window.ynWordEnd (URLs posées par le module) ; API : window.ynWordEnd.ouvrir().
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var config = window.ynWordEnd;
	if ( ! config || ! config.jeu ) {
		return;
	}

	var SEQUENCE = [ 'arrowup', 'arrowup', 'arrowdown', 'arrowdown', 'arrowleft', 'arrowright', 'arrowleft', 'arrowright', 'b', 'a' ];
	var DELAI_SEQUENCE = 3000;
	var APPUI_LONG = 1200;
	var position = 0;
	var derniere = 0;
	var chargement = null;

	function estChampSaisie( el ) {
		if ( ! el || ! el.tagName ) {
			return false;
		}
		if ( el.isContentEditable ) {
			return true;
		}
		return /^(INPUT|TEXTAREA|SELECT)$/.test( el.tagName ) || !! ( el.closest && el.closest( '[contenteditable="true"], [role="textbox"], [role="combobox"], [role="slider"]' ) );
	}

	function jeuOuvert() {
		return !! ( window.ynWordEndJeu && window.ynWordEndJeu.estOuvert() );
	}

	/**
	 * Charge la feuille et le script du jeu une seule fois.
	 *
	 * @return {Promise} Résolue quand window.ynWordEndJeu existe.
	 */
	function charger() {
		if ( window.ynWordEndJeu ) {
			return Promise.resolve();
		}
		if ( chargement ) {
			return chargement;
		}
		chargement = new Promise( function ( resoudre, rejeter ) {
			var feuille = document.createElement( 'link' );
			feuille.rel = 'stylesheet';
			feuille.href = config.style;
			document.head.appendChild( feuille );

			var script = document.createElement( 'script' );
			script.src = config.jeu;
			script.async = true;
			script.onload = function () {
				if ( window.ynWordEndJeu ) {
					resoudre();
				} else {
					rejeter( new Error( 'jeu absent' ) );
				}
			};
			script.onerror = function () {
				rejeter( new Error( 'chargement impossible' ) );
			};
			document.head.appendChild( script );
		} ).catch( function ( erreur ) {
			chargement = null;
			throw erreur;
		} );
		return chargement;
	}

	function ouvrir() {
		if ( jeuOuvert() ) {
			return;
		}
		charger().then(
			function () {
				window.ynWordEndJeu.ouvrir( config );
			},
			function ( erreur ) {
				// eslint-disable-next-line no-console
				console.warn( '[WordEnd] ' + erreur.message );
			}
		);
	}
	config.ouvrir = ouvrir;

	// Code Konami.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.altKey || e.ctrlKey || e.metaKey || e.isComposing || jeuOuvert() || estChampSaisie( e.target ) ) {
			return;
		}
		var touche = String( e.key || '' ).toLowerCase();
		var maintenant = Date.now();
		if ( maintenant - derniere > DELAI_SEQUENCE ) {
			position = 0;
		}
		derniere = maintenant;
		if ( touche === SEQUENCE[ position ] ) {
			position++;
		} else {
			position = touche === SEQUENCE[ 0 ] ? 1 : 0;
		}
		if ( position === SEQUENCE.length ) {
			position = 0;
			e.preventDefault();
			ouvrir();
		}
	} );

	// Papillon (et tout élément [data-yn-wordend-ouvrir]).
	document.addEventListener( 'click', function ( e ) {
		var cible = e.target && e.target.closest ? e.target.closest( '[data-yn-wordend-ouvrir]' ) : null;
		if ( cible ) {
			e.preventDefault();
			ouvrir();
		}
	} );

	// Appui long sur la bascule de thème (tactile). Le clic qui suit est annulé (phase de
	// capture) pour ne pas changer de thème en même temps.
	var minuterie = 0;
	var depart = null;
	var annulerClic = false;

	function arreter() {
		window.clearTimeout( minuterie );
		minuterie = 0;
		depart = null;
	}

	document.addEventListener(
		'touchstart',
		function ( e ) {
			var bouton = e.target && e.target.closest ? e.target.closest( '[data-yn-theme-toggle]' ) : null;
			if ( ! bouton || e.touches.length !== 1 ) {
				return;
			}
			depart = { x: e.touches[ 0 ].clientX, y: e.touches[ 0 ].clientY };
			minuterie = window.setTimeout( function () {
				minuterie = 0;
				annulerClic = true;
				window.setTimeout( function () {
					annulerClic = false;
				}, 800 );
				ouvrir();
			}, APPUI_LONG );
		},
		{ passive: true }
	);
	document.addEventListener(
		'touchmove',
		function ( e ) {
			if ( depart && e.touches.length === 1 && Math.abs( e.touches[ 0 ].clientX - depart.x ) + Math.abs( e.touches[ 0 ].clientY - depart.y ) > 10 ) {
				arreter();
			}
		},
		{ passive: true }
	);
	document.addEventListener( 'touchend', arreter, { passive: true } );
	document.addEventListener( 'touchcancel', arreter, { passive: true } );
	window.addEventListener(
		'click',
		function ( e ) {
			if ( annulerClic && e.target && e.target.closest && e.target.closest( '[data-yn-theme-toggle]' ) ) {
				annulerClic = false;
				e.preventDefault();
				e.stopPropagation();
			}
		},
		true
	);
	document.addEventListener(
		'contextmenu',
		function ( e ) {
			if ( minuterie && e.target && e.target.closest && e.target.closest( '[data-yn-theme-toggle]' ) ) {
				e.preventDefault();
			}
		}
	);
}() );
