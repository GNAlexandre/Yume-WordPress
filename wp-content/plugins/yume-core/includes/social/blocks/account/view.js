/**
 * Page compte (bloc yume/account), amélioration progressive :
 * - rubriques transformées en onglets accessibles (tablist / tab / tabpanel, flèches,
 *   Début / Fin, adresse synchronisée avec l'ancre) ; sans JavaScript, sections empilées ;
 * - alertes par œuvre enregistrées dès le choix (PUT /moi/alertes/{oeuvre}) ;
 * - « Retirer » un favori sans recharger (DELETE /moi/favoris/{oeuvre}) ;
 * - « Mot de passe oublié ? » ouvert quand l'adresse vise #yn-oubli.
 * En cas d'échec d'un appel REST, le formulaire est envoyé normalement (admin-post).
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	const racine = document.querySelector( '.yn-account[data-yn-compte]' );
	if ( ! racine ) {
		return;
	}
	let config = {};
	try {
		config = JSON.parse( racine.getAttribute( 'data-yn-compte' ) || '{}' );
	} catch ( e ) {
		config = {};
	}
	const annonce = racine.querySelector( '[data-yn-annonce]' );
	const libelles = config.libelles || {};

	function annoncer( texte ) {
		if ( annonce ) {
			annonce.textContent = '';
			setTimeout( function () {
				annonce.textContent = texte;
			}, 60 );
		}
	}

	function requete( methode, route, donnees ) {
		if ( ! config.connecte || ! window.fetch ) {
			return Promise.reject( new Error( 'indisponible' ) );
		}
		const init = {
			method: methode,
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
		};
		if ( donnees ) {
			init.body = JSON.stringify( donnees );
		}
		return window.fetch( config.rest + route, init ).then( function ( reponse ) {
			if ( ! reponse.ok ) {
				throw new Error( 'HTTP ' + reponse.status );
			}
			return reponse.json();
		} );
	}

	/* Mot de passe oublié. */
	const oubli = document.getElementById( 'yn-oubli' );
	function ouvrirOubli() {
		if ( oubli && window.location.hash === '#yn-oubli' ) {
			oubli.open = true;
			const champ = oubli.querySelector( 'input:not([type="hidden"])' );
			if ( champ ) {
				champ.focus();
			}
		}
	}
	ouvrirOubli();
	window.addEventListener( 'hashchange', ouvrirOubli );

	/* Onglets. */
	const liste = racine.querySelector( '[data-yn-onglets]' );
	const onglets = Array.prototype.slice.call( racine.querySelectorAll( '[data-yn-onglet]' ) );
	const panneaux = onglets.map( function ( onglet ) {
		return document.getElementById( onglet.getAttribute( 'data-yn-onglet' ) );
	} );
	const avecOnglets = !! ( liste && onglets.length && panneaux.every( Boolean ) );

	function activer( index, focus, majAdresse ) {
		onglets.forEach( function ( onglet, i ) {
			const actif = i === index;
			onglet.setAttribute( 'aria-selected', actif ? 'true' : 'false' );
			onglet.setAttribute( 'tabindex', actif ? '0' : '-1' );
			onglet.classList.toggle( 'est-actif', actif );
			panneaux[ i ].hidden = ! actif;
		} );
		if ( focus ) {
			onglets[ index ].focus();
		}
		if ( majAdresse && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + panneaux[ index ].id );
		}
	}

	function indexDepuisAdresse() {
		const ancre = decodeURIComponent( ( window.location.hash || '' ).slice( 1 ) );
		if ( ! ancre ) {
			return -1;
		}
		const cible = document.getElementById( ancre );
		if ( ! cible ) {
			return -1;
		}
		for ( let i = 0; i < panneaux.length; i++ ) {
			if ( panneaux[ i ] === cible || panneaux[ i ].contains( cible ) ) {
				return i;
			}
		}
		return -1;
	}

	if ( avecOnglets ) {
		liste.setAttribute( 'role', 'tablist' );
		liste.setAttribute( 'aria-orientation', 'vertical' );
		liste.setAttribute( 'aria-label', 'Rubriques du compte' );
		Array.prototype.forEach.call( liste.children, function ( li ) {
			li.setAttribute( 'role', 'presentation' );
		} );
		onglets.forEach( function ( onglet, i ) {
			onglet.setAttribute( 'role', 'tab' );
			onglet.setAttribute( 'aria-controls', panneaux[ i ].id );
			panneaux[ i ].setAttribute( 'role', 'tabpanel' );
			panneaux[ i ].setAttribute( 'aria-labelledby', onglet.id );
			panneaux[ i ].setAttribute( 'tabindex', '0' );
			onglet.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				activer( i, false, true );
			} );
			onglet.addEventListener( 'keydown', function ( e ) {
				const n = onglets.length;
				let cible = null;
				if ( e.key === 'ArrowDown' || e.key === 'ArrowRight' ) {
					cible = ( i + 1 ) % n;
				} else if ( e.key === 'ArrowUp' || e.key === 'ArrowLeft' ) {
					cible = ( i - 1 + n ) % n;
				} else if ( e.key === 'Home' ) {
					cible = 0;
				} else if ( e.key === 'End' ) {
					cible = n - 1;
				}
				if ( cible !== null ) {
					e.preventDefault();
					activer( cible, true, true );
				}
			} );
		} );
		const initial = indexDepuisAdresse();
		activer( initial > -1 ? initial : 0, false, false );
		if ( initial > -1 ) {
			// Retour d'un formulaire : on amène le lecteur au message et à la rubrique.
			const messages = racine.querySelector( '.yn-account__messages' );
			( messages && messages.children.length ? messages : panneaux[ initial ] ).scrollIntoView( { block: 'start' } );
		}
		window.addEventListener( 'hashchange', function () {
			const index = indexDepuisAdresse();
			if ( index > -1 ) {
				activer( index, false, false );
			}
		} );
	}

	/* Alertes par œuvre. */
	racine.querySelectorAll( 'form[data-yn-form="alerte"]' ).forEach( function ( formulaire ) {
		const select = formulaire.querySelector( 'select[data-yn-frequence]' );
		const oeuvre = formulaire.querySelector( 'input[name="yn_oeuvre"]' ).value;
		const titre = formulaire.closest( 'tr' ) ? formulaire.closest( 'tr' ).querySelector( '.yn-account__cellule-oeuvre a' ) : null;
		function envoyer() {
			select.setAttribute( 'aria-busy', 'true' );
			requete( 'PUT', 'moi/alertes/' + oeuvre, { frequence: select.value } )
				.then( function () {
					annoncer( 'Alerte ' + String( libelles[ select.value ] || select.value ).toLowerCase() + ( titre ? ' pour « ' + titre.textContent + ' »' : '' ) + '.' );
				} )
				.catch( function () {
					formulaire.submit();
				} )
				.then( function () {
					select.removeAttribute( 'aria-busy' );
				} );
		}
		select.addEventListener( 'change', envoyer );
		formulaire.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			envoyer();
		} );
	} );

	/* Retirer un favori. */
	function majCompteFavoris() {
		const tableau = racine.querySelector( '[data-yn-favoris]' );
		const n = tableau ? tableau.querySelectorAll( 'tbody tr' ).length : 0;
		const onglet = racine.querySelector( '[data-yn-onglet="yn-favoris"]' );
		if ( onglet ) {
			onglet.textContent = n > 0 ? 'Favoris et alertes (' + n + ')' : 'Favoris et alertes';
		}
		if ( tableau && n === 0 ) {
			const vide = document.createElement( 'p' );
			vide.className = 'yn-account__vide';
			vide.textContent = 'Aucun favori pour l’instant : ajoutez une œuvre avec le bouton « Favori » de sa fiche.';
			tableau.closest( '.yn-account__tableau-conteneur' ).replaceWith( vide );
		}
	}

	racine.querySelectorAll( 'form[data-yn-form="retirer"]' ).forEach( function ( formulaire ) {
		formulaire.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const ligne = formulaire.closest( 'tr' );
			const oeuvre = formulaire.querySelector( 'input[name="yn_oeuvre"]' ).value;
			const lien = ligne ? ligne.querySelector( '.yn-account__cellule-oeuvre a' ) : null;
			const titre = lien ? lien.textContent : '';
			requete( 'DELETE', 'moi/favoris/' + oeuvre )
				.then( function () {
					const voisine = ligne ? ligne.nextElementSibling || ligne.previousElementSibling : null;
					if ( ligne ) {
						ligne.remove();
					}
					majCompteFavoris();
					annoncer( '« ' + titre + ' » a été retirée de vos favoris.' );
					const suite = voisine ? voisine.querySelector( 'form[data-yn-form="retirer"] button' ) : null;
					const titreSection = document.getElementById( 'yn-favoris-titre' );
					if ( suite ) {
						suite.focus();
					} else if ( titreSection ) {
						titreSection.setAttribute( 'tabindex', '-1' );
						titreSection.focus();
					}
				} )
				.catch( function () {
					formulaire.submit();
				} );
		} );
	} );

	racine.classList.add( 'est-dynamique' );
}() );
