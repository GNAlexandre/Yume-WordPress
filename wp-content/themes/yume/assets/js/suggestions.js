/**
 * Suggestions instantanées du champ de recherche de l'en-tête (AMEL-04).
 *
 * Liste déroulante ARIA 1.2 (motif « combobox » avec liste) : le champ garde le focus,
 * l'option active est désignée par aria-activedescendant ; flèches haut et bas pour parcourir,
 * Entrée pour ouvrir l'option active (sinon la recherche classique est envoyée), Échap pour
 * fermer (puis vider le champ). Le nombre de suggestions est annoncé (aria-live).
 * Données : GET /yume/v1/suggestions?q=… (2 caractères au moins), réponses gardées en mémoire.
 * Sans ce script, le formulaire reste la recherche classique (/?s=).
 */
( function () {
	'use strict';

	const MIN = 2;
	const DELAI = 180;

	function initialiser( champ ) {
		const url = champ.getAttribute( 'data-yn-suggestions' );
		const liste = document.getElementById( champ.getAttribute( 'aria-controls' ) || '' );
		const annonce = document.getElementById( champ.getAttribute( 'data-yn-annonce' ) || '' );
		const formulaire = champ.form;
		if ( ! url || ! liste || ! formulaire ) {
			return;
		}
		const panneau = liste.parentElement;
		const memoire = new Map();
		let options = [];
		let actif = -1;
		let minuteur = 0;
		let controleur = null;
		let dernier = '';

		function message( cle, valeur ) {
			const modele = champ.getAttribute( 'data-yn-msg-' + cle ) || '';
			return modele.replace( '%d', String( valeur ) ).replace( '%s', String( valeur ) );
		}

		function annoncer( texte ) {
			if ( annonce ) {
				annonce.textContent = texte;
			}
		}

		function estOuvert() {
			return 'true' === champ.getAttribute( 'aria-expanded' );
		}

		function fermer() {
			champ.setAttribute( 'aria-expanded', 'false' );
			champ.removeAttribute( 'aria-activedescendant' );
			panneau.hidden = true;
			actif = -1;
		}

		function ouvrir() {
			if ( ! options.length ) {
				return;
			}
			panneau.hidden = false;
			champ.setAttribute( 'aria-expanded', 'true' );
		}

		function activer( index ) {
			if ( ! options.length ) {
				return;
			}
			if ( index < 0 ) {
				index = options.length - 1;
			} else if ( index >= options.length ) {
				index = 0;
			}
			options.forEach( function ( option, i ) {
				option.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
				option.classList.toggle( 'is-active', i === index );
			} );
			actif = index;
			champ.setAttribute( 'aria-activedescendant', options[ index ].id );
			options[ index ].scrollIntoView( { block: 'nearest' } );
		}

		function valider( option ) {
			if ( option.dataset.recherche ) {
				fermer();
				formulaire.submit();
				return;
			}
			if ( option.dataset.url ) {
				window.location.assign( option.dataset.url );
			}
		}

		function creerOption( index, titre, detail, image, classe ) {
			const option = document.createElement( 'li' );
			option.id = liste.id + '-' + index;
			option.className = 'yn-suggest__option' + ( classe ? ' ' + classe : '' );
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			if ( null !== image ) {
				const vignette = document.createElement( image ? 'img' : 'span' );
				vignette.className = 'yn-suggest__vignette';
				vignette.setAttribute( 'aria-hidden', 'true' );
				if ( image ) {
					vignette.src = image;
					vignette.alt = '';
					vignette.loading = 'lazy';
					vignette.decoding = 'async';
				}
				option.appendChild( vignette );
			}
			const texte = document.createElement( 'span' );
			texte.className = 'yn-suggest__texte';
			const nom = document.createElement( 'span' );
			nom.className = 'yn-suggest__titre';
			nom.textContent = titre;
			texte.appendChild( nom );
			if ( detail ) {
				const precision = document.createElement( 'span' );
				precision.className = 'yn-suggest__detail';
				precision.textContent = detail;
				texte.appendChild( precision );
			}
			option.appendChild( texte );
			// Le champ garde le focus : le clic ne doit pas le lui retirer avant la validation.
			option.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
			} );
			option.addEventListener( 'click', function () {
				valider( option );
			} );
			return option;
		}

		function afficher( donnees, terme ) {
			liste.textContent = '';
			options = [];
			actif = -1;
			champ.removeAttribute( 'aria-activedescendant' );
			const suggestions = donnees && Array.isArray( donnees.suggestions ) ? donnees.suggestions : [];
			suggestions.forEach( function ( s, i ) {
				const option = creerOption( i, String( s.titre || '' ), String( s.detail || '' ), s.image ? String( s.image ) : '', '' );
				option.dataset.url = String( s.url || '' );
				liste.appendChild( option );
				options.push( option );
			} );
			if ( suggestions.length ) {
				const tout = creerOption( suggestions.length, message( 'tout', terme ), '', null, 'yn-suggest__option--tout' );
				tout.dataset.recherche = '1';
				liste.appendChild( tout );
				options.push( tout );
			}
			if ( suggestions.length ) {
				ouvrir();
			} else {
				fermer();
			}
			annoncer( suggestions.length > 1 ? message( 'plus', suggestions.length ) : message( suggestions.length ? 'une' : 'aucune', 1 ) );
		}

		function chercher() {
			const terme = champ.value.trim();
			if ( terme === dernier ) {
				return;
			}
			dernier = terme;
			if ( terme.length < MIN ) {
				liste.textContent = '';
				options = [];
				fermer();
				annoncer( '' );
				return;
			}
			if ( memoire.has( terme ) ) {
				afficher( memoire.get( terme ), terme );
				return;
			}
			if ( controleur ) {
				controleur.abort();
			}
			controleur = 'AbortController' in window ? new AbortController() : null;
			const adresse = url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'q=' + encodeURIComponent( terme );
			window
				.fetch( adresse, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controleur ? controleur.signal : undefined } )
				.then( function ( reponse ) {
					return reponse.ok ? reponse.json() : null;
				} )
				.then( function ( donnees ) {
					if ( ! donnees ) {
						return;
					}
					memoire.set( terme, donnees );
					if ( champ.value.trim() === terme ) {
						afficher( donnees, terme );
					}
				} )
				.catch( function () {} );
		}

		champ.addEventListener( 'input', function () {
			window.clearTimeout( minuteur );
			minuteur = window.setTimeout( chercher, DELAI );
		} );

		champ.addEventListener( 'keydown', function ( e ) {
			switch ( e.key ) {
				case 'ArrowDown':
					if ( ! options.length ) {
						return;
					}
					e.preventDefault();
					if ( ! estOuvert() ) {
						ouvrir();
					}
					activer( actif + 1 );
					break;
				case 'ArrowUp':
					if ( ! options.length ) {
						return;
					}
					e.preventDefault();
					if ( ! estOuvert() ) {
						ouvrir();
					}
					activer( actif - 1 );
					break;
				case 'Enter':
					if ( estOuvert() && actif > -1 && options[ actif ] ) {
						e.preventDefault();
						valider( options[ actif ] );
					}
					break;
				case 'Escape':
					if ( estOuvert() ) {
						e.preventDefault();
						fermer();
					} else if ( champ.value ) {
						e.preventDefault();
						champ.value = '';
						dernier = '';
						liste.textContent = '';
						options = [];
						annoncer( '' );
					}
					break;
				case 'Tab':
					fermer();
					break;
			}
		} );

		champ.addEventListener( 'focus', function () {
			if ( options.length && champ.value.trim() === dernier ) {
				ouvrir();
			}
		} );

		champ.addEventListener( 'blur', function () {
			window.setTimeout( fermer, 120 );
		} );

		fermer();
	}

	function demarrer() {
		Array.prototype.forEach.call( document.querySelectorAll( 'input[data-yn-suggestions][role="combobox"]' ), initialiser );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
