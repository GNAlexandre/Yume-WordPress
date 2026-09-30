/**
 * Déclencheur de l'easter egg WordEnd (chargé en defer sur les pages publiques).
 *
 * Ouvre le jeu par :
 * - le code Konami au clavier (↑ ↑ ↓ ↓ ← → ← → B A), hors champs de saisie ;
 * - un appui long (1,2 s) sur la bascule de thème [data-yn-theme-toggle] (écrans tactiles) ;
 * - un clic sur un élément [data-yn-wordend-ouvrir] (papillon de la fiche d'œuvre).
 *
 * Le jeu n'est chargé qu'à la première ouverture : feuille config.style, puis les scripts du
 * moteur config.scripts, insérés dans l'ordre (async = false : exécutés dans cet ordre). Le
 * chargement est résolu quand window.ynWordEndJeu existe ; un échec permet de réessayer.
 * Configuration : window.ynWordEnd (posée par le module) ; API : window.ynWordEnd.ouvrir( univers? ).
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var config = window.ynWordEnd;
	if ( ! config || ! Array.isArray( config.scripts ) || ! config.scripts.length ) {
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
	 * Charge la feuille puis les scripts du moteur une seule fois.
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
			if ( config.style && ! document.querySelector( 'link[data-yn-wordend]' ) ) {
				var feuille = document.createElement( 'link' );
				feuille.rel = 'stylesheet';
				feuille.href = config.style;
				feuille.setAttribute( 'data-yn-wordend', '' );
				document.head.appendChild( feuille );
			}
			var restants = config.scripts.length;
			var echoue = false;
			config.scripts.forEach( function ( adresse ) {
				var script = document.createElement( 'script' );
				script.src = adresse;
				script.async = false; // Exécution dans l'ordre d'insertion.
				script.setAttribute( 'data-yn-wordend', '' );
				script.onload = function () {
					restants--;
					if ( restants === 0 && ! echoue ) {
						if ( window.ynWordEndJeu ) {
							resoudre();
						} else {
							rejeter( new Error( 'jeu absent' ) );
						}
					}
				};
				script.onerror = function () {
					if ( ! echoue ) {
						echoue = true;
						rejeter( new Error( 'chargement impossible : ' + adresse ) );
					}
				};
				document.head.appendChild( script );
			} );
		} ).catch( function ( erreur ) {
			chargement = null;
			throw erreur;
		} );
		return chargement;
	}

	/**
	 * Ouvre le jeu.
	 *
	 * @param {string} univers Slug de l'univers (facultatif : universPage, puis universParDefaut).
	 */
	function ouvrir( univers ) {
		if ( jeuOuvert() ) {
			return;
		}
		charger().then(
			function () {
				window.ynWordEndJeu.ouvrir( config, typeof univers === 'string' ? univers : '' );
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
			ouvrir( cible.getAttribute( 'data-yn-wordend-univers' ) || '' );
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
