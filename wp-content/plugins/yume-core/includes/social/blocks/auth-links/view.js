/**
 * Cloche des notifications (bloc yume/auth-links, notifications-lecteur.php) et activation des
 * notifications navigateur (rubrique « Notifications » du compte, push.php).
 *
 * Chargé seulement pour un membre connecté : un visiteur ne reçoit ni ce script ni aucune
 * requête. Sans JavaScript, la cloche est un lien vers la rubrique « Notifications ».
 *
 * - Cloche : bouton qui ouvre un panneau (aria-expanded, Échap et clic à l'extérieur le
 *   ferment) ; nombre de non lues rendu par le serveur, rafraîchi à l'ouverture et toutes les
 *   5 minutes quand l'onglet est visible (GET /moi/notifications) ; « Tout marquer comme lu »
 *   et clic sur une notification non lue : POST /moi/notifications/lues.
 * - Notifications navigateur : permission, service worker du site (/?yume_sw=1), abonnement
 *   PushManager (clé VAPID publique), puis POST /moi/push ; désactivation : DELETE /moi/push.
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	function lireConfig( element, attribut ) {
		try {
			return JSON.parse( element.getAttribute( attribut ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function requete( config, methode, route, donnees, garder ) {
		if ( ! window.fetch || ! config.rest ) {
			return Promise.reject( new Error( 'fetch' ) );
		}
		const init = {
			method: methode,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
		};
		if ( donnees ) {
			init.body = JSON.stringify( donnees );
		}
		if ( garder ) {
			init.keepalive = true;
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

	function annoncer( zone, texte ) {
		if ( zone ) {
			zone.textContent = '';
			setTimeout( function () {
				zone.textContent = texte;
			}, 60 );
		}
	}

	/** Durée écoulée lisible : « il y a 5 min », « il y a 3 h », « il y a 2 jours ». */
	function ilYa( iso ) {
		const date = Date.parse( iso );
		if ( ! date ) {
			return '';
		}
		const minutes = Math.max( 1, Math.round( ( Date.now() - date ) / 60000 ) );
		if ( minutes < 60 ) {
			return 'il y a ' + minutes + ' min';
		}
		const heures = Math.round( minutes / 60 );
		if ( heures < 24 ) {
			return 'il y a ' + heures + ' h';
		}
		const jours = Math.round( heures / 24 );
		return 'il y a ' + jours + ( jours > 1 ? ' jours' : ' jour' );
	}

	/** Adresse http(s) seulement. */
	function urlSure( brute ) {
		try {
			const url = new URL( String( brute ), window.location.href );
			return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
		} catch ( e ) {
			return '';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Cloche                                                              */
	/* ------------------------------------------------------------------ */

	function initialiserCloche( racine ) {
		const config = lireConfig( racine, 'data-yn-cloche' );
		const lien = racine.querySelector( '[data-yn-cloche-lien]' );
		const bouton = racine.querySelector( '[data-yn-cloche-bouton]' );
		const panneau = racine.querySelector( '.yn-cloche__panneau' );
		const liste = racine.querySelector( '[data-yn-cloche-liste]' );
		const vide = racine.querySelector( '[data-yn-cloche-vide]' );
		const toutLu = racine.querySelector( '[data-yn-cloche-tout-lu]' );
		const zone = racine.querySelector( '[data-yn-cloche-annonce]' );
		if ( ! config.connecte || ! bouton || ! panneau || ! liste ) {
			return;
		}
		const intervalle = Math.max( 60, parseInt( config.intervalle, 10 ) || 300 ) * 1000;
		let dernier = Date.now();
		let actif = true;

		lien.hidden = true;
		bouton.hidden = false;

		function majNombre( nb ) {
			nb = Math.max( 0, parseInt( nb, 10 ) || 0 );
			const texte = nb > 0 ? 'Notifications : ' + nb + ( nb > 1 ? ' non lues' : ' non lue' ) : 'Notifications : aucune non lue';
			racine.querySelectorAll( '[data-yn-cloche-pastille]' ).forEach( function ( pastille ) {
				pastille.textContent = nb > 99 ? '99+' : String( nb );
				pastille.hidden = nb === 0;
			} );
			racine.querySelectorAll( '[data-yn-cloche-libelle]' ).forEach( function ( libelle ) {
				libelle.textContent = texte;
			} );
			if ( toutLu ) {
				toutLu.hidden = nb === 0;
			}
		}

		function element( notification ) {
			const li = document.createElement( 'li' );
			li.className = 'yn-notification' + ( notification.lu ? '' : ' est-non-lue' );
			li.setAttribute( 'data-yn-notification', String( notification.id ) );
			const a = document.createElement( 'a' );
			a.className = 'yn-notification__lien';
			a.href = urlSure( notification.url ) || '#';
			if ( ! notification.lu ) {
				const marque = document.createElement( 'span' );
				marque.className = 'yn-visually-hidden';
				marque.textContent = 'Non lue : ';
				a.appendChild( marque );
			}
			a.appendChild( document.createTextNode( String( notification.titre || '' ) ) );
			li.appendChild( a );
			const date = ilYa( notification.cree_le );
			if ( date ) {
				const span = document.createElement( 'span' );
				span.className = 'yn-muted yn-notification__date';
				span.textContent = date;
				li.appendChild( document.createTextNode( ' ' ) );
				li.appendChild( span );
			}
			return li;
		}

		function rafraichir( avecListe ) {
			dernier = Date.now();
			return requete( config, 'GET', 'moi/notifications?limite=' + ( parseInt( config.nombre, 10 ) || 8 ) )
				.then( function ( donnees ) {
					majNombre( donnees.non_lues );
					if ( avecListe || ! panneau.hidden ) {
						liste.textContent = '';
						( donnees.notifications || [] ).forEach( function ( notification ) {
							liste.appendChild( element( notification ) );
						} );
						if ( vide ) {
							vide.hidden = liste.children.length > 0;
						}
					}
				} )
				.catch( function ( erreur ) {
					// Nonce expiré ou session terminée : plus de rafraîchissement automatique.
					if ( erreur && ( erreur.statut === 401 || erreur.statut === 403 ) ) {
						actif = false;
					}
				} );
		}

		function ouvrir( oui ) {
			bouton.setAttribute( 'aria-expanded', oui ? 'true' : 'false' );
			panneau.hidden = ! oui;
			if ( oui ) {
				rafraichir( true );
			}
		}

		bouton.addEventListener( 'click', function () {
			ouvrir( panneau.hidden );
		} );
		racine.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! panneau.hidden ) {
				e.preventDefault();
				ouvrir( false );
				bouton.focus();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! panneau.hidden && ! racine.contains( e.target ) ) {
				ouvrir( false );
			}
		} );

		if ( toutLu ) {
			toutLu.addEventListener( 'click', function () {
				requete( config, 'POST', 'moi/notifications/lues', {} )
					.then( function ( donnees ) {
						majNombre( donnees.non_lues );
						liste.querySelectorAll( '.est-non-lue' ).forEach( function ( li ) {
							li.classList.remove( 'est-non-lue' );
							const marque = li.querySelector( '.yn-visually-hidden' );
							if ( marque ) {
								marque.remove();
							}
						} );
						annoncer( zone, 'Toutes vos notifications sont marquées comme lues.' );
						bouton.focus();
					} )
					.catch( function () {
						annoncer( zone, 'Les notifications n’ont pas pu être marquées comme lues. Réessayez.' );
					} );
			} );
		}

		// Clic sur une notification non lue : marquée lue (la navigation continue).
		liste.addEventListener( 'click', function ( e ) {
			const a = e.target && e.target.closest ? e.target.closest( 'a' ) : null;
			const li = a ? a.closest( '.est-non-lue' ) : null;
			if ( li ) {
				requete( config, 'POST', 'moi/notifications/lues', { ids: [ parseInt( li.getAttribute( 'data-yn-notification' ), 10 ) ] }, true ).catch( function () {} );
			}
		} );

		function peutEtre() {
			if ( actif && document.visibilityState === 'visible' && Date.now() - dernier >= intervalle ) {
				rafraichir( false );
			}
		}
		setInterval( peutEtre, 60000 );
		document.addEventListener( 'visibilitychange', peutEtre );
	}

	/* ------------------------------------------------------------------ */
	/* Notifications navigateur                                            */
	/* ------------------------------------------------------------------ */

	function cleServeur( base64 ) {
		const remplissage = '='.repeat( ( 4 - ( base64.length % 4 ) ) % 4 );
		const brut = window.atob( ( base64 + remplissage ).replace( /-/g, '+' ).replace( /_/g, '/' ) );
		const octets = new Uint8Array( brut.length );
		for ( let i = 0; i < brut.length; i++ ) {
			octets[ i ] = brut.charCodeAt( i );
		}
		return octets;
	}

	function empreinte( texte ) {
		if ( ! window.crypto || ! window.crypto.subtle || ! window.TextEncoder ) {
			return Promise.resolve( '' );
		}
		return window.crypto.subtle.digest( 'SHA-256', new TextEncoder().encode( texte ) ).then( function ( tampon ) {
			return Array.prototype.map.call( new Uint8Array( tampon ), function ( o ) {
				return ( '0' + o.toString( 16 ) ).slice( -2 );
			} ).join( '' );
		} );
	}

	function initialiserPush( bloc ) {
		const config = lireConfig( bloc, 'data-yn-push' );
		const etat = bloc.querySelector( '[data-yn-push-etat]' );
		const bouton = bloc.querySelector( '[data-yn-push-bouton]' );
		const zone = bloc.querySelector( '[data-yn-push-annonce]' );
		if ( ! etat || ! bouton || ! config.cle ) {
			return;
		}
		const compatible = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
		if ( ! compatible ) {
			etat.textContent = 'Ce navigateur ne permet pas les notifications sur ce site (navigateur récent et connexion HTTPS nécessaires ; sur iPhone et iPad, ajoutez d’abord le site à l’écran d’accueil).';
			return;
		}
		let abonne = false;

		function afficher( oui, message ) {
			abonne = oui;
			bouton.hidden = false;
			bouton.disabled = false;
			bouton.textContent = oui ? 'Désactiver sur cet appareil' : 'Activer sur cet appareil';
			bouton.classList.toggle( 'yn-btn--primary', ! oui );
			if ( Notification.permission === 'denied' ) {
				etat.textContent = 'Les notifications sont bloquées pour ce site dans les réglages de votre navigateur : autorisez-les pour pouvoir les activer.';
				bouton.hidden = ! oui;
			} else {
				etat.textContent = oui ? 'Activées sur cet appareil.' : 'Désactivées sur cet appareil.';
			}
			if ( message ) {
				annoncer( zone, message );
			}
		}

		function enregistrement() {
			return navigator.serviceWorker.getRegistration( config.portee ).then( function ( existant ) {
				return existant || navigator.serviceWorker.register( config.sw, { scope: config.portee } );
			} ).then( function () {
				return navigator.serviceWorker.ready;
			} );
		}

		function activer() {
			return Notification.requestPermission().then( function ( permission ) {
				if ( permission !== 'granted' ) {
					throw new Error( 'permission' );
				}
				return enregistrement();
			} ).then( function ( reg ) {
				const options = { userVisibleOnly: true, applicationServerKey: cleServeur( config.cle ) };
				return reg.pushManager.subscribe( options ).catch( function () {
					// Abonnement existant créé avec une autre clé : on le remplace.
					return reg.pushManager.getSubscription().then( function ( ancien ) {
						return ancien ? ancien.unsubscribe() : null;
					} ).then( function () {
						return reg.pushManager.subscribe( options );
					} );
				} );
			} ).then( function ( abonnement ) {
				const json = abonnement.toJSON();
				return requete( config, 'POST', 'moi/push', { endpoint: json.endpoint, keys: json.keys || {} } );
			} );
		}

		function desactiver() {
			return navigator.serviceWorker.getRegistration( config.portee ).then( function ( reg ) {
				return reg ? reg.pushManager.getSubscription() : null;
			} ).then( function ( abonnement ) {
				if ( ! abonnement ) {
					return null;
				}
				return requete( config, 'DELETE', 'moi/push', { endpoint: abonnement.endpoint } ).catch( function () {} ).then( function () {
					return abonnement.unsubscribe();
				} );
			} );
		}

		bouton.addEventListener( 'click', function () {
			bouton.disabled = true;
			( abonne ? desactiver() : activer() )
				.then( function () {
					afficher( ! abonne, abonne ? 'Notifications navigateur désactivées sur cet appareil.' : 'Notifications navigateur activées sur cet appareil.' );
				} )
				.catch( function ( erreur ) {
					afficher( abonne, erreur && erreur.message === 'permission' ? 'Autorisation refusée par le navigateur.' : 'L’opération n’a pas pu aboutir. Réessayez.' );
				} );
		} );

		// État initial : abonnement de cet appareil connu du site pour ce compte ?
		navigator.serviceWorker.getRegistration( config.portee ).then( function ( reg ) {
			return reg ? reg.pushManager.getSubscription() : null;
		} ).then( function ( abonnement ) {
			if ( ! abonnement ) {
				return false;
			}
			return empreinte( abonnement.endpoint ).then( function ( valeur ) {
				return ( config.empreintes || [] ).indexOf( valeur ) > -1;
			} );
		} ).then( function ( oui ) {
			afficher( !! oui );
		} ).catch( function () {
			afficher( false );
		} );
	}

	document.querySelectorAll( '[data-yn-cloche]' ).forEach( initialiserCloche );
	document.querySelectorAll( '[data-yn-push]' ).forEach( initialiserPush );
}() );
