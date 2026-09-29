/**
 * Bouton « Signaler » des commentaires (lecteurs connectés) : ouvre un petit formulaire (motif
 * facultatif) puis envoie POST /yume/v1/commentaires/{id}/signalement avec le nonce REST.
 * Sans JavaScript, le bouton reste masqué (attribut hidden posé par le serveur).
 */
( function () {
	'use strict';

	function initialiser( zone ) {
		var bouton = zone.querySelector( '.yn-signaler__bouton' );
		var form = zone.querySelector( '.yn-signaler__form' );
		var retour = zone.querySelector( '.yn-signaler__retour' );
		var annuler = zone.querySelector( '[data-yn-annuler]' );
		if ( ! bouton || ! form || ! retour ) {
			return;
		}
		zone.hidden = false;

		function basculer( ouvrir ) {
			form.hidden = ! ouvrir;
			bouton.setAttribute( 'aria-expanded', ouvrir ? 'true' : 'false' );
			if ( ouvrir ) {
				var champ = form.querySelector( 'input' );
				if ( champ ) {
					champ.focus();
				}
			} else {
				bouton.focus();
			}
		}

		bouton.addEventListener( 'click', function () {
			basculer( form.hidden );
		} );
		if ( annuler ) {
			annuler.addEventListener( 'click', function () {
				basculer( false );
			} );
		}
		form.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				basculer( false );
			}
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var envoi = form.querySelector( '[type="submit"]' );
			var motif = form.querySelector( 'input[name="motif"]' );
			if ( envoi ) {
				envoi.disabled = true;
			}
			fetch( zone.getAttribute( 'data-yn-signaler' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': zone.getAttribute( 'data-yn-nonce' ) || '',
				},
				body: JSON.stringify( { motif: motif ? motif.value : '' } ),
			} )
				.then( function ( reponse ) {
					return reponse.json().then( function ( donnees ) {
						return { ok: reponse.ok, donnees: donnees || {} };
					} );
				} )
				.then( function ( r ) {
					retour.textContent = r.donnees.message || zone.getAttribute( 'data-yn-msg-erreur' );
					if ( r.ok || 'yume_deja_signale' === r.donnees.code ) {
						form.hidden = true;
						bouton.hidden = true;
					} else if ( envoi ) {
						envoi.disabled = false;
					}
				} )
				.catch( function () {
					retour.textContent = zone.getAttribute( 'data-yn-msg-erreur' );
					if ( envoi ) {
						envoi.disabled = false;
					}
				} );
		} );
	}

	document.querySelectorAll( '[data-yn-signaler]' ).forEach( initialiser );
}() );
