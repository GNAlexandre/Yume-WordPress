/**
 * Vitrine du planning (vitrine.php) : onglets « Chapitres » | « Tomes » de la section Planning de
 * l'accueil, sous 900 px. Amélioration progressive : sans JavaScript, la liste d'onglets reste
 * masquée et les deux files sont affichées l'une sous l'autre ; à partir de 900 px, la feuille
 * de style masque les onglets et montre les deux colonnes.
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	function initialiser( conteneur ) {
		const liste = conteneur.querySelector( '[role="tablist"]' );
		if ( ! liste ) {
			return;
		}
		const onglets = Array.prototype.slice.call( liste.querySelectorAll( '[role="tab"]' ) );
		const panneaux = onglets.map( function ( onglet ) {
			return document.getElementById( onglet.getAttribute( 'aria-controls' ) );
		} );
		if ( ! onglets.length || panneaux.indexOf( null ) !== -1 ) {
			return;
		}

		function choisir( index, focus ) {
			onglets.forEach( function ( onglet, i ) {
				const actif = i === index;
				onglet.setAttribute( 'aria-selected', actif ? 'true' : 'false' );
				onglet.tabIndex = actif ? 0 : -1;
				panneaux[ i ].classList.toggle( 'est-actif', actif );
			} );
			if ( focus ) {
				onglets[ index ].focus();
			}
		}

		// Rôles d'onglets seulement quand les onglets sont visibles (sous 900 px).
		const etroit = window.matchMedia ? window.matchMedia( '(max-width: 899.98px)' ) : null;
		function roles() {
			const actifs = ! etroit || etroit.matches;
			panneaux.forEach( function ( panneau, i ) {
				if ( actifs ) {
					panneau.setAttribute( 'role', 'tabpanel' );
					panneau.setAttribute( 'aria-labelledby', onglets[ i ].id );
				} else {
					panneau.removeAttribute( 'role' );
					panneau.setAttribute( 'aria-labelledby', panneau.id + '-titre' );
				}
			} );
		}
		roles();
		if ( etroit && etroit.addEventListener ) {
			etroit.addEventListener( 'change', roles );
		}
		onglets.forEach( function ( onglet, i ) {
			onglet.addEventListener( 'click', function () {
				choisir( i, false );
			} );
			onglet.addEventListener( 'keydown', function ( e ) {
				let cible = -1;
				if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
					cible = ( i + 1 ) % onglets.length;
				} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
					cible = ( i - 1 + onglets.length ) % onglets.length;
				} else if ( e.key === 'Home' ) {
					cible = 0;
				} else if ( e.key === 'End' ) {
					cible = onglets.length - 1;
				}
				if ( cible !== -1 ) {
					e.preventDefault();
					choisir( cible, true );
				}
			} );
		} );

		choisir( 0, false );
		conteneur.classList.add( 'est-onglets' );
		liste.hidden = false;
	}

	function demarrer() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-yn-onglets]' ), initialiser );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
} )();
