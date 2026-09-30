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
 * chargement est résolu quand window.ynWordEndJeu existe ; un échec (script introuvable) rejette
 * le chargement et permet de réessayer à la demande suivante.
 * Le papillon passe son univers (data-yn-wordend-univers) ; un slug inconnu de config.univers est
 * ignoré (la modale ouvre alors config.universPage, puis config.universParDefaut).
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
	var pret = false; // Moteur chargé en entier par charger().
	var essaye = false; // charger() a déjà inséré des scripts.

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
	 * Charge la feuille puis les scripts du moteur une seule fois. Les scripts sont insérés tous
	 * ensemble dans l'ordre (async = false : téléchargés en parallèle, exécutés dans l'ordre). Le
	 * premier échec (réseau, 404) rejette le chargement : les éléments de cette tentative sont
	 * retirés et un nouvel appel recommence depuis le début.
	 *
	 * @return {Promise} Résolue quand window.ynWordEndJeu existe.
	 */
	function charger() {
		// Moteur déjà chargé : par ce script, ou par la page elle-même (banc d'essai) si ce script
		// n'a encore rien inséré (après un échec, window.ynWordEndJeu peut exister sans le moteur
		// complet : seul un chargement réussi compte).
		if ( pret || ( ! essaye && window.ynWordEndJeu ) ) {
			return Promise.resolve();
		}
		if ( chargement ) {
			return chargement;
		}
		essaye = true;
		var inseres = [];
		chargement = new Promise( function ( resoudre, rejeter ) {
			if ( config.style && ! document.querySelector( 'link[data-yn-wordend]' ) ) {
				var feuille = document.createElement( 'link' );
				feuille.rel = 'stylesheet';
				feuille.href = config.style;
				feuille.setAttribute( 'data-yn-wordend', '' );
				document.head.appendChild( feuille );
			}
			var restants = config.scripts.length;
			var termine = false;

			function echouer( erreur ) {
				if ( ! termine ) {
					termine = true;
					rejeter( erreur );
				}
			}

			config.scripts.forEach( function ( adresse ) {
				var script = document.createElement( 'script' );
				script.src = adresse;
				script.async = false; // Exécution dans l'ordre d'insertion.
				script.setAttribute( 'data-yn-wordend', '' );
				script.onload = function () {
					restants--;
					if ( restants === 0 && ! termine ) {
						if ( window.ynWordEndJeu && typeof window.ynWordEndJeu.ouvrir === 'function' ) {
							termine = true;
							pret = true;
							resoudre();
						} else {
							echouer( new Error( 'jeu absent après le chargement du moteur' ) );
						}
					}
				};
				script.onerror = function () {
					echouer( new Error( 'chargement impossible : ' + adresse ) );
				};
				inseres.push( script );
				document.head.appendChild( script );
			} );
		} ).catch( function ( erreur ) {
			// Réessai possible : on repart d'un état propre (scripts de cette tentative retirés).
			inseres.forEach( function ( script ) {
				script.onload = null;
				script.onerror = null;
				if ( script.parentNode ) {
					script.parentNode.removeChild( script );
				}
			} );
			chargement = null;
			throw erreur;
		} );
		return chargement;
	}

	/**
	 * Slug d'univers connu de la configuration, sinon '' (la modale choisit alors universPage,
	 * puis universParDefaut).
	 *
	 * @param {*} univers Slug demandé.
	 * @return {string} Slug retenu.
	 */
	function universConnu( univers ) {
		return typeof univers === 'string' && univers && config.univers && Object.prototype.hasOwnProperty.call( config.univers, univers ) ? univers : '';
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
		var slug = universConnu( univers );
		charger().then(
			function () {
				// Deux demandes pendant le même chargement : une seule ouverture.
				if ( ! jeuOuvert() ) {
					window.ynWordEndJeu.ouvrir( config, slug );
				}
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
