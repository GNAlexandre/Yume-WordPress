/**
 * Lecteur du prototype MangaDex ([yume_mangadex], [yume_mangadex_serie]).
 *
 * Lit GET /wp-json/yume-proto/v1/chapitre/{id} (crédits, adresses relayées des pages) et affiche
 * le chapitre : défilement vertical (images en loading="lazy") ou page par page (clic sur une
 * moitié de l'image, flèches du clavier, deux pages préchargées), sens de lecture droite → gauche
 * (manga) ou gauche → droite, qualité « économie de données » (data-saver) ou originale.
 * Réglages gardés dans localStorage['yn.mdx-proto'] (try/catch). Bandeau de mesure : pages
 * chargées, volume et durée lus dans l'API Resource Timing du navigateur.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var CLE = 'yn.mdx-proto';

	function lireReglages() {
		try {
			var r = JSON.parse( window.localStorage.getItem( CLE ) || 'null' );
			return r && typeof r === 'object' ? r : {};
		} catch ( e ) {
			return {};
		}
	}

	function ecrireReglages( r ) {
		try {
			window.localStorage.setItem( CLE, JSON.stringify( r ) );
		} catch ( e ) {}
	}

	function el( balise, attributs, texte ) {
		var n = document.createElement( balise );
		Object.keys( attributs || {} ).forEach( function ( k ) {
			n.setAttribute( k, attributs[ k ] );
		} );
		if ( texte ) {
			n.textContent = texte;
		}
		return n;
	}

	function bouton( texte, presse ) {
		var b = el( 'button', { type: 'button', class: 'ymdx-btn', 'aria-pressed': presse ? 'true' : 'false' }, texte );
		return b;
	}

	function monter( racine ) {
		var api = racine.getAttribute( 'data-ymdx-api' );
		var id = racine.getAttribute( 'data-ymdx-chapitre' );
		var voie = racine.getAttribute( 'data-ymdx-voie' ) || 'rest';
		var reglages = lireReglages();
		var mode = reglages.mode === 'page' ? 'page' : 'defilement';
		var sens = reglages.sens === 'ltr' ? 'ltr' : 'rtl';
		var qualite = reglages.qualite || racine.getAttribute( 'data-ymdx-qualite' ) || 'data-saver';
		var chapitre = null;
		var courante = 0;
		var debut = 0;
		var etat = racine.querySelector( '.ymdx-lecteur__etat' );

		function annoncer( texte ) {
			if ( etat ) {
				etat.textContent = texte;
			}
		}

		function lienMangadex() {
			return el( 'a', { href: 'https://mangadex.org/chapter/' + id, rel: 'noopener', target: '_blank' }, 'Lire sur MangaDex (nouvel onglet)' );
		}

		function charger() {
			annoncer( 'Chargement du chapitre…' );
			debut = window.performance ? window.performance.now() : Date.now();
			window.fetch( api + id + '?qualite=' + encodeURIComponent( qualite ) + '&voie=' + encodeURIComponent( voie ), { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json().then( function ( d ) {
						if ( ! r.ok ) {
							throw new Error( d && d.message ? d.message : 'HTTP ' + r.status );
						}
						return d;
					} );
				} )
				.then( function ( d ) {
					chapitre = d;
					courante = 0;
					afficher();
				} )
				.catch( function ( erreur ) {
					racine.textContent = '';
					var p = el( 'p', { class: 'ymdx-lecteur__erreur', role: 'alert' }, 'Ce chapitre ne peut pas être affiché ici : ' + erreur.message + ' ' );
					p.appendChild( lienMangadex() );
					racine.appendChild( p );
				} );
		}

		function enregistrer() {
			ecrireReglages( { mode: mode, sens: sens, qualite: qualite } );
		}

		function entete() {
			var tete = el( 'div', { class: 'ymdx-lecteur__tete' } );
			var titre = ( chapitre.manga && chapitre.manga.titre ? chapitre.manga.titre + ' — ' : '' ) +
				( chapitre.volume ? 'Vol. ' + chapitre.volume + ' · ' : '' ) +
				( chapitre.chapitre ? 'Chapitre ' + chapitre.chapitre : 'Oneshot' ) +
				( chapitre.titre ? ' : ' + chapitre.titre : '' );
			tete.appendChild( el( 'h2', { class: 'ymdx-lecteur__titre' }, titre ) );
			var credit = el( 'p', { class: 'ymdx-credit' } );
			var groupes = ( chapitre.groupes || [] ).map( function ( g ) {
				return g.nom;
			} ).filter( Boolean ).join( ', ' );
			credit.appendChild( document.createTextNode( ( groupes ? 'Traduction : ' + groupes + ' · ' : '' ) + 'Images hébergées par MangaDex · ' ) );
			credit.appendChild( lienMangadex() );
			tete.appendChild( credit );

			var outils = el( 'div', { class: 'ymdx-lecteur__outils', role: 'group', 'aria-label': 'Réglages de lecture' } );
			var bDefil = bouton( 'Défilement', mode === 'defilement' );
			var bPage = bouton( 'Page par page', mode === 'page' );
			var bSens = bouton( sens === 'rtl' ? 'Sens : droite → gauche' : 'Sens : gauche → droite', false );
			var bQualite = bouton( qualite === 'data' ? 'Qualité : originale' : 'Qualité : économie de données', false );
			bDefil.addEventListener( 'click', function () {
				mode = 'defilement';
				enregistrer();
				afficher();
			} );
			bPage.addEventListener( 'click', function () {
				mode = 'page';
				enregistrer();
				afficher();
			} );
			bSens.addEventListener( 'click', function () {
				sens = sens === 'rtl' ? 'ltr' : 'rtl';
				enregistrer();
				afficher();
			} );
			bQualite.addEventListener( 'click', function () {
				qualite = qualite === 'data' ? 'data-saver' : 'data';
				enregistrer();
				charger();
			} );
			[ bDefil, bPage, bSens, bQualite ].forEach( function ( b ) {
				outils.appendChild( b );
			} );
			tete.appendChild( outils );
			return tete;
		}

		function image( n, chargement ) {
			var img = el( 'img', {
				class: 'ymdx-lecteur__image',
				src: chapitre.pages[ n ],
				alt: 'Page ' + ( n + 1 ) + ' sur ' + chapitre.pages.length,
				loading: chargement,
				decoding: 'async',
			} );
			// Bande de webtoon (bien plus haute que large) : pleine largeur, sans limite de hauteur.
			img.addEventListener( 'load', function () {
				if ( img.naturalHeight > 2 * img.naturalWidth ) {
					img.classList.add( 'is-longue' );
				}
			} );
			img.addEventListener( 'error', function () {
				img.replaceWith( el( 'p', { class: 'ymdx-lecteur__erreur' }, 'Page ' + ( n + 1 ) + ' indisponible.' ) );
			} );
			return img;
		}

		function mesure() {
			var bandeau = el( 'p', { class: 'ymdx-lecteur__mesure', 'aria-live': 'polite' } );
			function maj() {
				if ( ! window.performance || ! window.performance.getEntriesByType ) {
					return;
				}
				var entrees = window.performance.getEntriesByType( 'resource' ).filter( function ( e ) {
					return e.name.indexOf( id ) !== -1 && /image|yume_mdx_image/.test( e.name );
				} );
				var octets = entrees.reduce( function ( s, e ) {
					return s + ( e.transferSize || e.encodedBodySize || 0 );
				}, 0 );
				var duree = entrees.reduce( function ( s, e ) {
					return Math.max( s, e.responseEnd );
				}, 0 ) - debut;
				bandeau.textContent = 'Mesure (prototype) : ' + entrees.length + ' page(s) chargée(s), ' + Math.round( octets / 1024 ) + ' Ko transférés' + ( entrees.length ? ', dernière arrivée ' + Math.max( 0, Math.round( duree ) ) + ' ms après l’ouverture' : '' ) + '.';
			}
			window.setInterval( maj, 1500 );
			maj();
			return bandeau;
		}

		function afficher() {
			racine.textContent = '';
			racine.setAttribute( 'data-mode', mode );
			racine.setAttribute( 'dir', 'ltr' );
			racine.appendChild( entete() );
			var scene = el( 'div', { class: 'ymdx-lecteur__scene' } );
			if ( mode === 'defilement' ) {
				chapitre.pages.forEach( function ( url, n ) {
					scene.appendChild( image( n, n < 2 ? 'eager' : 'lazy' ) );
				} );
			} else {
				var page = el( 'div', { class: 'ymdx-lecteur__page', tabindex: '0', 'aria-label': 'Page du chapitre : flèches ou clic sur un côté pour tourner' } );
				var compteur = el( 'p', { class: 'ymdx-lecteur__compteur', 'aria-live': 'polite' } );
				var montrer = function () {
					page.textContent = '';
					page.appendChild( image( courante, 'eager' ) );
					compteur.textContent = 'Page ' + ( courante + 1 ) + ' sur ' + chapitre.pages.length;
					// Préchargement des deux pages suivantes.
					[ 1, 2 ].forEach( function ( d ) {
						if ( chapitre.pages[ courante + d ] ) {
							( new window.Image() ).src = chapitre.pages[ courante + d ];
						}
					} );
				};
				var tourner = function ( avance ) {
					var cible = courante + ( avance ? 1 : -1 );
					if ( cible >= 0 && cible < chapitre.pages.length ) {
						courante = cible;
						montrer();
					}
				};
				page.addEventListener( 'click', function ( e ) {
					var boite = page.getBoundingClientRect();
					var gauche = e.clientX < boite.left + boite.width / 2;
					// Manga (droite → gauche) : la moitié gauche avance.
					tourner( sens === 'rtl' ? gauche : ! gauche );
				} );
				page.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
						e.preventDefault();
						var gauche = e.key === 'ArrowLeft';
						tourner( sens === 'rtl' ? gauche : ! gauche );
					}
				} );
				scene.appendChild( page );
				scene.appendChild( compteur );
				montrer();
			}
			racine.appendChild( scene );
			racine.appendChild( mesure() );
		}

		charger();
	}

	document.querySelectorAll( '.ymdx-lecteur[data-ymdx-chapitre]' ).forEach( monter );
}() );
