/**
 * Menu « Bibliothèque » de l'en-tête (bloc yume/library-menu).
 *
 * Sans JavaScript, le <details> s'ouvre et se ferme nativement. Ce script ajoute :
 * - la fermeture au clic extérieur, à la perte du focus et à Échap (panneau déroulant, bureau) ;
 *   dans la fenêtre du menu mobile, Échap referme d'abord le panneau, pas le menu ;
 * - un seul panneau ouvert à la fois ;
 * - pour un visiteur, « Reprendre ma lecture » mène au dernier chapitre lu sur cet appareil
 *   (localStorage['yn.progression'], écrit par le lecteur).
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var CLE_PROGRESSION = 'yn.progression';
	var menus = [];

	/**
	 * Le panneau est-il déroulant (positionné sous l'en-tête, bureau) ?
	 *
	 * @param {HTMLDetailsElement} menu Menu.
	 * @return {boolean} Vrai en mode déroulant.
	 */
	function estDeroulant( menu ) {
		var panneau = menu.querySelector( '.yn-library-menu__panneau' );
		return !! panneau && 'absolute' === window.getComputedStyle( panneau ).position;
	}

	/**
	 * Referme un menu.
	 *
	 * @param {HTMLDetailsElement} menu        Menu.
	 * @param {boolean}            rendreFocus Replacer le focus sur le bouton.
	 */
	function fermer( menu, rendreFocus ) {
		if ( ! menu.open ) {
			return;
		}
		menu.open = false;
		if ( rendreFocus ) {
			var bouton = menu.querySelector( 'summary' );
			if ( bouton ) {
				bouton.focus();
			}
		}
	}

	/**
	 * Dernière lecture enregistrée sur cet appareil (même origine uniquement).
	 *
	 * @return {?{url: string, titre: string}} Lecture la plus récente.
	 */
	function derniereLecture() {
		var brut = null;
		try {
			brut = window.localStorage.getItem( CLE_PROGRESSION );
		} catch ( e ) {
			return null;
		}
		if ( ! brut ) {
			return null;
		}
		var donnees = null;
		try {
			donnees = JSON.parse( brut );
		} catch ( e ) {
			return null;
		}
		if ( ! donnees || 'object' !== typeof donnees ) {
			return null;
		}
		var meilleure = null;
		Object.keys( donnees ).forEach( function ( cle ) {
			var entree = donnees[ cle ];
			if ( ! entree || 'string' !== typeof entree.url ) {
				return;
			}
			var adresse;
			try {
				adresse = new URL( entree.url, window.location.href );
			} catch ( e ) {
				return;
			}
			if ( adresse.origin !== window.location.origin ) {
				return;
			}
			var date = Date.parse( entree.updated_at || '' );
			if ( isNaN( date ) ) {
				date = Number( entree.updated_at ) || 0;
			}
			if ( ! meilleure || date > meilleure.date ) {
				meilleure = {
					url: adresse.href,
					titre: 'string' === typeof entree.titre ? entree.titre : '',
					date: date,
				};
			}
		} );
		return meilleure;
	}

	/**
	 * Visiteur : « Reprendre ma lecture » pointe vers le dernier chapitre lu.
	 *
	 * @param {HTMLDetailsElement} menu Menu.
	 */
	function preparerReprise( menu ) {
		var lien = menu.querySelector( '[data-yn-reprendre]' );
		var lecture = lien ? derniereLecture() : null;
		if ( ! lecture ) {
			return;
		}
		lien.setAttribute( 'href', lecture.url );
		if ( lecture.titre ) {
			var precision = document.createElement( 'span' );
			precision.className = 'yn-visually-hidden';
			precision.textContent = ' : ' + lecture.titre;
			lien.appendChild( precision );
		}
	}

	/**
	 * Prépare un menu.
	 *
	 * @param {HTMLDetailsElement} menu Menu.
	 */
	function initialiser( menu ) {
		if ( menu.hasAttribute( 'data-yn-pret' ) ) {
			return;
		}
		menu.setAttribute( 'data-yn-pret', '' );
		menus.push( menu );

		menu.addEventListener( 'toggle', function () {
			if ( menu.open ) {
				menus.forEach( function ( autre ) {
					if ( autre !== menu ) {
						fermer( autre, false );
					}
				} );
			}
		} );

		menu.addEventListener( 'keydown', function ( evenement ) {
			if ( 'Escape' === evenement.key && menu.open ) {
				evenement.preventDefault();
				evenement.stopPropagation();
				fermer( menu, true );
			}
		} );

		menu.addEventListener( 'focusout', function ( evenement ) {
			var suivant = evenement.relatedTarget;
			if ( menu.open && suivant && ! menu.contains( suivant ) && estDeroulant( menu ) ) {
				fermer( menu, false );
			}
		} );

		preparerReprise( menu );
	}

	document.addEventListener( 'pointerdown', function ( evenement ) {
		menus.forEach( function ( menu ) {
			if ( menu.open && ! menu.contains( evenement.target ) && estDeroulant( menu ) ) {
				fermer( menu, false );
			}
		} );
	} );

	document.addEventListener( 'keydown', function ( evenement ) {
		if ( 'Escape' !== evenement.key ) {
			return;
		}
		menus.forEach( function ( menu ) {
			if ( menu.open && estDeroulant( menu ) ) {
				fermer( menu, menu.contains( document.activeElement ) );
			}
		} );
	} );

	function demarrer() {
		document.querySelectorAll( 'details[data-yn-library-menu]' ).forEach( initialiser );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
