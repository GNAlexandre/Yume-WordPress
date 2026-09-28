/**
 * Glossaire d'une œuvre (bloc yume/glossaire).
 *
 * Sans JavaScript, toutes les entrées restent affichées. Ce script ajoute :
 * - la recherche instantanée (nom, terme original, description : attribut data-yn-recherche,
 *   déjà en minuscules sans accents) et le filtre par catégorie, avec le nombre de résultats
 *   annoncé (role="status") ;
 * - pour l'équipe, la bascule « Afficher les notes de traduction » sans recharger la page
 *   (le lien ?notes=1 reste la solution sans script).
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	/**
	 * Texte normalisé comme côté serveur : minuscules, sans accents, espaces réduites.
	 *
	 * @param {string} texte Texte.
	 * @return {string} Texte normalisé.
	 */
	function normaliser( texte ) {
		return String( texte || '' )
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /œ/g, 'oe' )
			.replace( /æ/g, 'ae' )
			.replace( /ß/g, 'ss' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	/**
	 * Active un glossaire.
	 *
	 * @param {HTMLElement} racine Racine du bloc.
	 */
	function initialiser( racine ) {
		var outils = racine.querySelector( '[data-yn-glossaire-outils]' );
		var champ = racine.querySelector( '[data-yn-glossaire-q]' );
		var choix = racine.querySelector( '[data-yn-glossaire-cat]' );
		var compte = racine.querySelector( '[data-yn-glossaire-compte]' );
		var vide = racine.querySelector( '[data-yn-glossaire-vide]' );
		var bascule = racine.querySelector( '[data-yn-glossaire-bascule]' );
		var sections = Array.prototype.slice.call( racine.querySelectorAll( '[data-yn-categorie]' ) );
		var notes = !! bascule && 'true' === bascule.getAttribute( 'aria-pressed' );
		var minuteur = 0;

		/**
		 * L'élément n'est-il visible qu'avec les notes de traduction ?
		 *
		 * @param {Element} el Élément.
		 * @return {boolean} Vrai pour une note ou une entrée « à définir ».
		 */
		function reserveNotes( el ) {
			return el.hasAttribute( 'data-yn-glossaire-note' );
		}

		/**
		 * Applique la recherche, le filtre et l'état des notes.
		 *
		 * @param {boolean} annoncer Mettre à jour le nombre de résultats.
		 */
		function appliquer( annoncer ) {
			var q = champ ? normaliser( champ.value ) : '';
			var cat = choix ? choix.value : '';
			var mots = q ? q.split( ' ' ) : [];
			var total = 0;

			sections.forEach( function ( section ) {
				var elements = section.querySelectorAll( '[data-yn-recherche]' );
				var visibles = 0;
				Array.prototype.forEach.call( elements, function ( el ) {
					var texte = el.getAttribute( 'data-yn-recherche' ) || '';
					var ok = ( notes || ! reserveNotes( el ) ) && mots.every( function ( mot ) {
						return -1 !== texte.indexOf( mot );
					} );
					el.hidden = ! ok;
					if ( ok ) {
						visibles++;
					}
				} );
				var affichee = visibles > 0 && ( ! cat || cat === section.getAttribute( 'data-yn-categorie' ) ) && ( notes || ! reserveNotes( section ) );
				section.hidden = ! affichee;
				if ( affichee ) {
					total += visibles;
				}
			} );
			racine.querySelectorAll( '.yn-glossaire__notes' ).forEach( function ( note ) {
				note.hidden = ! notes;
			} );
			if ( vide ) {
				vide.hidden = total > 0;
			}
			if ( compte && annoncer ) {
				var modele = 0 === total ? racine.getAttribute( 'data-yn-msg-aucun' ) : ( 1 === total ? racine.getAttribute( 'data-yn-msg-un' ) : racine.getAttribute( 'data-yn-msg-n' ) );
				compte.textContent = q || cat ? String( modele || '' ).replace( '%d', String( total ) ) : '';
			}
		}

		if ( outils ) {
			outils.hidden = false;
			outils.addEventListener( 'submit', function ( evenement ) {
				evenement.preventDefault();
			} );
		}
		if ( champ ) {
			champ.addEventListener( 'input', function () {
				window.clearTimeout( minuteur );
				minuteur = window.setTimeout( function () {
					appliquer( true );
				}, 120 );
			} );
			champ.addEventListener( 'keydown', function ( evenement ) {
				if ( 'Escape' === evenement.key && champ.value ) {
					champ.value = '';
					appliquer( true );
				}
			} );
		}
		if ( choix ) {
			choix.addEventListener( 'change', function () {
				appliquer( true );
			} );
		}
		if ( bascule ) {
			bascule.addEventListener( 'click', function ( evenement ) {
				evenement.preventDefault();
				notes = ! notes;
				bascule.setAttribute( 'aria-pressed', notes ? 'true' : 'false' );
				bascule.textContent = racine.getAttribute( notes ? 'data-yn-msg-masquer' : 'data-yn-msg-afficher' );
				appliquer( !! ( champ && champ.value ) || !! ( choix && choix.value ) );
			} );
			bascule.addEventListener( 'keydown', function ( evenement ) {
				// Lien présenté comme un bouton : Espace l'active aussi.
				if ( ' ' === evenement.key ) {
					evenement.preventDefault();
					bascule.click();
				}
			} );
		}
	}

	function demarrer() {
		document.querySelectorAll( '[data-yn-glossaire]' ).forEach( initialiser );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
