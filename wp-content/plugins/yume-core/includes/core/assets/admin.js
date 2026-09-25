/**
 * Administration Yume (module core) : sélecteurs de médias (bannière, illustrations) et
 * lignes de liens répétables. JavaScript sans étape de build (ES2019) ; sans lui, les
 * formulaires restent utilisables (saisie des IDs, lignes vides fournies par le serveur).
 */
( function () {
	'use strict';

	var textes = window.yumeAdmin || {};

	/**
	 * Annonce un message aux lecteurs d'écran (région vivante de WordPress).
	 *
	 * @param {string} message Message.
	 */
	function annoncer( message ) {
		if ( window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function' ) {
			window.wp.a11y.speak( message );
		}
	}

	/**
	 * Met à jour l'aperçu d'un sélecteur de médias.
	 *
	 * @param {HTMLElement} bloc    Conteneur [data-yume-media].
	 * @param {Array}       medias  Pièces jointes { id, url }.
	 */
	function apercu( bloc, medias ) {
		var zone = bloc.querySelector( '.yume-media__apercu' );
		var retirer = bloc.querySelector( '.yume-media__retirer' );
		zone.textContent = '';
		medias.forEach( function ( media ) {
			if ( ! media.url ) {
				return;
			}
			var img = document.createElement( 'img' );
			img.className = 'yume-media__vignette';
			img.src = media.url;
			img.alt = media.alt || '';
			zone.appendChild( img );
		} );
		if ( retirer ) {
			retirer.hidden = medias.length === 0;
		}
	}

	/**
	 * Active un sélecteur de médias (wp.media).
	 *
	 * @param {HTMLElement} bloc Conteneur [data-yume-media].
	 */
	function selecteurMedia( bloc ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var champ = bloc.querySelector( 'input' );
		var choisir = bloc.querySelector( '.yume-media__choisir' );
		var retirer = bloc.querySelector( '.yume-media__retirer' );
		var multiple = bloc.getAttribute( 'data-multiple' ) === '1';
		var cadre = null;

		bloc.classList.add( 'is-enhanced' );

		choisir.addEventListener( 'click', function () {
			if ( ! cadre ) {
				cadre = window.wp.media( {
					title: bloc.getAttribute( 'data-titre' ) || '',
					library: { type: 'image' },
					multiple: multiple ? 'add' : false,
					button: { text: multiple ? textes.utiliser_plusieurs : textes.utiliser },
				} );
				cadre.on( 'open', function () {
					var selection = cadre.state().get( 'selection' );
					selection.reset();
					champ.value.split( ',' ).forEach( function ( id ) {
						id = parseInt( id, 10 );
						if ( id > 0 ) {
							var piece = window.wp.media.attachment( id );
							piece.fetch();
							selection.add( piece );
						}
					} );
				} );
				cadre.on( 'select', function () {
					var choix = cadre.state().get( 'selection' ).toJSON();
					var medias = choix.map( function ( piece ) {
						var taille = piece.sizes && piece.sizes.thumbnail ? piece.sizes.thumbnail : piece;
						return { id: piece.id, url: taille.url, alt: piece.alt };
					} );
					champ.value = medias.map( function ( m ) {
						return m.id;
					} ).join( ',' );
					apercu( bloc, medias );
					annoncer(
						medias.length > 1
							? ( textes.images_choisies || '%d' ).replace( '%d', String( medias.length ) )
							: textes.image_choisie || ''
					);
					choisir.focus();
				} );
			}
			cadre.open();
		} );

		if ( retirer ) {
			retirer.addEventListener( 'click', function () {
				champ.value = '';
				apercu( bloc, [] );
				choisir.focus();
			} );
		}
	}

	/**
	 * Lignes répétables (liens externes) : bouton « Ajouter un lien ».
	 *
	 * @param {HTMLElement} groupe Conteneur [data-yume-lignes].
	 */
	function lignesRepetables( groupe ) {
		var corps = groupe.querySelector( '[data-yume-lignes-corps]' );
		var modele = groupe.querySelector( '[data-yume-lignes-modele]' );
		var bouton = groupe.querySelector( '[data-yume-lignes-ajouter]' );
		if ( ! corps || ! modele || ! bouton ) {
			return;
		}
		bouton.addEventListener( 'click', function () {
			var index = 'n' + Date.now();
			var html = modele.innerHTML.replace( /__i__/g, index );
			corps.insertAdjacentHTML( 'beforeend', html );
			var premier = corps.lastElementChild ? corps.lastElementChild.querySelector( 'input' ) : null;
			if ( premier ) {
				premier.focus();
			}
			annoncer( textes.ligne_ajoutee || '' );
		} );
	}

	function initialiser() {
		document.querySelectorAll( '[data-yume-media]' ).forEach( selecteurMedia );
		document.querySelectorAll( '[data-yume-lignes]' ).forEach( lignesRepetables );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialiser );
	} else {
		initialiser();
	}
} )();
