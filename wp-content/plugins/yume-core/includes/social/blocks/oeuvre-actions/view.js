/**
 * Actions de la fiche œuvre (bloc yume/oeuvre-actions), amélioration progressive :
 * sans JavaScript, les formulaires admin-post fonctionnent ; avec, les actions passent par la
 * REST (/yume/v1/moi/favoris|notes|alertes/{oeuvre}, nonce en X-WP-Nonce) sans recharger.
 * Visiteur : le bouton « Reprendre » est rempli depuis localStorage['yn.progression'].
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	function lireProgression( oeuvre ) {
		try {
			const toutes = JSON.parse( window.localStorage.getItem( 'yn.progression' ) || 'null' );
			const entree = toutes && typeof toutes === 'object' ? toutes[ oeuvre ] : null;
			return entree && typeof entree === 'object' ? entree : null;
		} catch ( e ) {
			return null;
		}
	}

	/** Adresse locale uniquement (le stockage peut avoir été modifié à la main). */
	function urlLocale( brute ) {
		try {
			const url = new URL( String( brute ), window.location.href );
			return url.origin === window.location.origin ? url : null;
		} catch ( e ) {
			return null;
		}
	}

	function initialiser( racine ) {
		let config;
		try {
			config = JSON.parse( racine.getAttribute( 'data-yn-actions' ) || '{}' );
		} catch ( e ) {
			return;
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

		// Reprise mémorisée sur cet appareil (visiteur, ou membre sans position enregistrée).
		const lienReprise = racine.querySelector( '[data-yn-reprendre]' );
		if ( lienReprise ) {
			const entree = lireProgression( config.oeuvre );
			const url = entree ? urlLocale( entree.url ) : null;
			if ( entree && url && entree.titre ) {
				const morceaux = String( entree.titre ).split( ' · ' ).slice( 1 );
				if ( morceaux.length ) {
					const dernier = morceaux[ morceaux.length - 1 ];
					morceaux[ morceaux.length - 1 ] = dernier.charAt( 0 ).toLowerCase() + dernier.slice( 1 );
				}
				const paragraphe = parseInt( entree.paragraphe, 10 ) || 0;
				url.hash = paragraphe > 0 ? 'yn-p-' + ( paragraphe + 1 ) : '';
				lienReprise.href = url.href;
				lienReprise.querySelector( '[data-yn-reprendre-texte]' ).textContent = 'Reprendre' + ( morceaux.length ? ' · ' + morceaux.join( ', ' ) : '' );
				lienReprise.hidden = false;
				const commencer = racine.querySelector( '[data-yn-commencer]' );
				if ( commencer ) {
					commencer.hidden = true;
				}
			}
		}

		// Menus <details> : un seul ouvert, Échap et clic à l'extérieur les ferment.
		const menus = Array.prototype.slice.call( racine.querySelectorAll( 'details[data-yn-menu]' ) );
		menus.forEach( function ( menu ) {
			menu.addEventListener( 'toggle', function () {
				if ( menu.open ) {
					menus.forEach( function ( autre ) {
						if ( autre !== menu ) {
							autre.open = false;
						}
					} );
				}
			} );
			menu.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && menu.open ) {
					e.preventDefault();
					menu.open = false;
					menu.querySelector( 'summary' ).focus();
				}
			} );
		} );
		document.addEventListener( 'click', function ( e ) {
			menus.forEach( function ( menu ) {
				if ( menu.open && ! menu.contains( e.target ) ) {
					menu.open = false;
				}
			} );
		} );

		if ( ! config.connecte ) {
			racine.classList.add( 'est-dynamique' );
			return;
		}

		function requete( methode, route, donnees ) {
			if ( ! window.fetch ) {
				return Promise.reject( new Error( 'fetch' ) );
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
				return reponse.json().then( function ( corps ) {
					if ( ! reponse.ok ) {
						const erreur = new Error( corps && corps.message ? corps.message : 'HTTP ' + reponse.status );
						erreur.statut = reponse.status;
						throw erreur;
					}
					return corps;
				} );
			} );
		}

		const formFavori = racine.querySelector( 'form[data-yn-form="favori"]' );
		const boutonFavori = formFavori ? formFavori.querySelector( '[data-yn-favori]' ) : null;
		const formNote = racine.querySelector( 'form[data-yn-form="note"]' );
		const formAlerte = racine.querySelector( 'form[data-yn-form="alerte"]' );
		const horsFavori = racine.querySelector( '[data-yn-alerte-hors-favori]' );
		const resumeAlerte = racine.querySelector( '[data-yn-alerte-resume]' );

		function nombre( n ) {
			return new Intl.NumberFormat( 'fr-FR' ).format( n );
		}

		/** Met l'interface à jour à partir de l'état renvoyé par la REST. */
		function majEtat( etat ) {
			if ( boutonFavori ) {
				boutonFavori.setAttribute( 'aria-pressed', etat.favori ? 'true' : 'false' );
				const compteur = boutonFavori.querySelector( '[data-yn-compteur]' );
				if ( compteur ) {
					compteur.textContent = nombre( etat.nb_favoris );
				}
				const texte = boutonFavori.querySelector( '[data-yn-compteur-texte]' );
				if ( texte ) {
					texte.textContent = ' — ' + etat.nb_favoris + ( etat.nb_favoris > 1 ? ' lecteurs l’ont en favori' : ' lecteur l’a en favori' );
				}
				const faire = formFavori.querySelector( '[data-yn-faire]' );
				if ( faire ) {
					faire.value = etat.favori ? 'retirer' : 'ajouter';
				}
			}
			if ( formAlerte ) {
				formAlerte.hidden = ! etat.favori;
				formAlerte.querySelectorAll( 'input[name="yn_frequence"]' ).forEach( function ( radio ) {
					radio.checked = radio.value === etat.frequence;
				} );
			}
			if ( horsFavori ) {
				horsFavori.hidden = !! etat.favori;
			}
			if ( resumeAlerte ) {
				resumeAlerte.textContent = etat.favori && etat.frequence ? 'Alerte : ' + String( libelles[ etat.frequence ] || etat.frequence ).toLowerCase() : 'Alerte';
			}
			const moyenne = racine.querySelector( '[data-yn-moyenne]' );
			const nbNotes = racine.querySelector( '[data-yn-nb-notes]' );
			if ( moyenne && nbNotes ) {
				if ( etat.nb_notes > 0 ) {
					moyenne.textContent = Number( etat.moyenne ).toFixed( 1 ).replace( '.', ',' );
					nbNotes.textContent = '(' + etat.nb_notes + ( etat.nb_notes > 1 ? ' notes)' : ' note)' );
				} else {
					moyenne.textContent = 'Noter';
					nbNotes.textContent = '';
				}
			}
			if ( formNote ) {
				formNote.querySelectorAll( '.yn-etoiles__etoile' ).forEach( function ( etoile, i ) {
					etoile.classList.toggle( 'est-pleine', i < etat.note );
					const radio = etoile.querySelector( 'input' );
					radio.checked = i + 1 === etat.note;
				} );
				const retirer = formNote.querySelector( '[data-yn-retirer-note]' );
				if ( retirer ) {
					retirer.hidden = ! etat.note;
				}
			}
		}

		function occupe( element, oui ) {
			if ( element ) {
				element.setAttribute( 'aria-busy', oui ? 'true' : 'false' );
			}
		}

		// Favori.
		if ( formFavori && boutonFavori ) {
			formFavori.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				if ( boutonFavori.getAttribute( 'aria-busy' ) === 'true' ) {
					return;
				}
				const actif = boutonFavori.getAttribute( 'aria-pressed' ) === 'true';
				occupe( boutonFavori, true );
				requete( actif ? 'DELETE' : 'POST', 'moi/favoris/' + config.oeuvre )
					.then( function ( etat ) {
						majEtat( etat );
						annoncer( etat.favori ? 'Œuvre ajoutée à vos favoris. Vous pouvez régler son alerte.' : 'Œuvre retirée de vos favoris.' );
					} )
					.catch( function () {
						formFavori.submit();
					} )
					.then( function () {
						occupe( boutonFavori, false );
					} );
			} );
		}

		// Note : enregistrement à chaque choix (clavier : flèches du groupe radio).
		if ( formNote ) {
			let minuterie = null;
			const envoyerNote = function ( valeur ) {
				clearTimeout( minuterie );
				minuterie = setTimeout( function () {
					requete( 'PUT', 'moi/notes/' + config.oeuvre, { note: valeur } )
						.then( function ( etat ) {
							majEtat( etat );
							annoncer( valeur ? 'Note enregistrée : ' + valeur + ' sur 5.' : 'Votre note a été retirée.' );
						} )
						.catch( function () {
							annoncer( 'La note n’a pas pu être enregistrée. Réessayez.' );
						} );
				}, 350 );
			};
			formNote.addEventListener( 'change', function ( e ) {
				if ( e.target && e.target.name === 'yn_note' ) {
					const valeur = parseInt( e.target.value, 10 ) || 0;
					formNote.querySelectorAll( '.yn-etoiles__etoile' ).forEach( function ( etoile, i ) {
						etoile.classList.toggle( 'est-pleine', i < valeur );
					} );
					envoyerNote( valeur );
				}
			} );
			formNote.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				const retirer = e.submitter && e.submitter.hasAttribute( 'data-yn-retirer-note' );
				const coche = formNote.querySelector( 'input[name="yn_note"]:checked' );
				envoyerNote( retirer || ! coche ? 0 : parseInt( coche.value, 10 ) );
				if ( retirer ) {
					formNote.querySelectorAll( 'input[name="yn_note"]' ).forEach( function ( radio ) {
						radio.checked = false;
					} );
					const premiere = formNote.querySelector( 'input[name="yn_note"]' );
					if ( premiere ) {
						premiere.focus();
					}
				}
			} );
		}

		// Alerte.
		if ( formAlerte ) {
			const envoyerAlerte = function ( frequence ) {
				requete( 'PUT', 'moi/alertes/' + config.oeuvre, { frequence: frequence } )
					.then( function ( etat ) {
						majEtat( etat );
						annoncer( 'Alerte mise à jour : ' + String( libelles[ frequence ] || frequence ).toLowerCase() + '.' );
					} )
					.catch( function ( erreur ) {
						annoncer( erreur && erreur.statut === 409 ? 'Ajoutez d’abord l’œuvre à vos favoris.' : 'L’alerte n’a pas pu être enregistrée. Réessayez.' );
					} );
			};
			formAlerte.addEventListener( 'change', function ( e ) {
				if ( e.target && e.target.name === 'yn_frequence' ) {
					envoyerAlerte( e.target.value );
				}
			} );
			formAlerte.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				const coche = formAlerte.querySelector( 'input[name="yn_frequence"]:checked' );
				if ( coche ) {
					envoyerAlerte( coche.value );
				}
			} );
		}

		racine.classList.add( 'est-dynamique' );
	}

	document.querySelectorAll( '.yn-oeuvre-actions[data-yn-actions]' ).forEach( initialiser );
}() );
