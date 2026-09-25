/**
 * Yume → Migrer : exécution et annulation par lots via l'API REST (yume/v1/migration),
 * barre de progression, journal annoncé aux lecteurs d'écran, reprise après interruption.
 * Sans JavaScript, les formulaires passent par admin-post.php (un lot par envoi).
 *
 * JavaScript « vanilla » (ES2019), sans étape de construction.
 */
( function () {
	'use strict';

	var racine = document.querySelector( '[data-yume-migrer]' );
	if ( ! racine || ! window.fetch ) {
		return;
	}
	var api = racine.getAttribute( 'data-api' );
	var nonce = racine.getAttribute( 'data-nonce' );
	var pageMigrer = racine.getAttribute( 'data-page' ) || window.location.href;
	var enCours = false;

	function recharger( delai ) {
		window.setTimeout( function () {
			window.location.replace( pageMigrer );
		}, delai );
	}

	function annoncer( texte ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( texte, 'polite' );
		}
	}

	function zone( operation ) {
		return racine.querySelector( '[data-yume-progression="' + operation + '"]' );
	}

	function formater( n ) {
		try {
			return new Intl.NumberFormat( 'fr-FR' ).format( n );
		} catch ( e ) {
			return String( n );
		}
	}

	function afficher( operation, etat ) {
		var bloc = zone( operation );
		if ( ! bloc ) {
			return;
		}
		bloc.hidden = false;
		var etape = bloc.querySelector( '[data-yume-etape]' );
		var barre = bloc.querySelector( '[data-yume-barre]' );
		var compteur = bloc.querySelector( '[data-yume-compteur]' );
		var journal = bloc.querySelector( '[data-yume-journal]' );
		var erreur = bloc.querySelector( '[data-yume-erreur]' );
		var libelle = etat.etape_libelle || etat.statut_libelle;
		if ( etape && etape.textContent !== libelle ) {
			etape.textContent = libelle;
			annoncer( libelle );
		}
		if ( barre ) {
			barre.value = etat.pourcentage;
			barre.textContent = etat.pourcentage + ' %';
		}
		if ( compteur ) {
			compteur.textContent = formater( etat.fait ) + ' / ' + formater( etat.total ) + ' éléments (' + etat.pourcentage + ' %)';
		}
		if ( journal && Array.isArray( etat.messages ) ) {
			journal.textContent = '';
			etat.messages.slice( -8 ).forEach( function ( m ) {
				var li = document.createElement( 'li' );
				li.className = 'yume-migrer__message--' + m.type;
				li.textContent = m.texte;
				journal.appendChild( li );
			} );
		}
		if ( erreur ) {
			erreur.hidden = ! etat.erreur;
			var p = erreur.querySelector( 'p' );
			if ( p ) {
				p.textContent = etat.erreur || '';
			}
		}
		var pastille = racine.querySelector( '[data-yume-pastille] span' );
		if ( pastille && etat.statut_libelle ) {
			pastille.textContent = etat.statut_libelle;
		}
	}

	function erreurVisible( operation, texte ) {
		var bloc = zone( operation );
		if ( ! bloc ) {
			window.alert( texte );
			return;
		}
		bloc.hidden = false;
		var erreur = bloc.querySelector( '[data-yume-erreur]' );
		if ( erreur ) {
			erreur.hidden = false;
			erreur.querySelector( 'p' ).textContent = texte;
		}
		annoncer( texte );
	}

	function appeler( route, corps ) {
		return window.fetch( api + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( corps || {} ),
		} ).then( function ( reponse ) {
			return reponse.json().catch( function () {
				return null;
			} ).then( function ( donnees ) {
				if ( ! reponse.ok ) {
					var message = donnees && donnees.message ? donnees.message : 'Erreur ' + reponse.status + '.';
					throw new Error( message );
				}
				return donnees;
			} );
		} );
	}

	function verrouillerFormulaires( actif ) {
		racine.querySelectorAll( '[data-yume-form] button, [data-yume-form] input' ).forEach( function ( el ) {
			el.disabled = actif;
		} );
	}

	/**
	 * Enchaîne les lots jusqu'à la fin, une erreur ou une coupure.
	 *
	 * @param {string} operation executer ou annuler.
	 * @param {Object} premier   Corps du premier appel.
	 */
	function boucle( operation, premier ) {
		if ( enCours ) {
			return;
		}
		enCours = true;
		verrouillerFormulaires( true );
		var corps = premier || {};
		var fin = function () {
			enCours = false;
			verrouillerFormulaires( false );
		};
		var suivant = function () {
			appeler( operation, corps ).then( function ( etat ) {
				corps = {};
				afficher( operation, etat );
				if ( etat.erreur ) {
					fin();
					annoncer( etat.erreur );
					recharger( 2500 );
					return;
				}
				if ( etat.termine ) {
					annoncer( 'executer' === operation ? 'Migration terminée.' : 'Migration annulée.' );
					recharger( 1200 );
					return;
				}
				window.setTimeout( suivant, 150 );
			} ).catch( function ( e ) {
				fin();
				erreurVisible( operation, e.message + ' L’opération reprendra où elle s’est arrêtée : rechargez la page et cliquez sur « Reprendre ».' );
			} );
		};
		suivant();
	}

	racine.querySelectorAll( '[data-yume-form]' ).forEach( function ( formulaire ) {
		formulaire.addEventListener( 'submit', function ( evenement ) {
			var operation = formulaire.getAttribute( 'data-yume-form' );
			var reprise = formulaire.hasAttribute( 'data-yume-reprise' );
			var bouton = evenement.submitter || document.activeElement;
			var ignorer = !! ( bouton && bouton.hasAttribute && bouton.hasAttribute( 'data-yume-ignorer' ) );
			evenement.preventDefault();
			if ( reprise ) {
				boucle( operation, ignorer ? { ignorer: true } : {} );
				return;
			}
			if ( 'executer' === operation ) {
				var champ = formulaire.querySelector( '[data-yume-confirmation]' );
				if ( ! champ || 'MIGRER' !== champ.value.trim() ) {
					erreurVisible( operation, 'Tapez MIGRER (en majuscules) pour confirmer l’exécution.' );
					if ( champ ) {
						champ.setAttribute( 'aria-invalid', 'true' );
						champ.focus();
					}
					return;
				}
				champ.removeAttribute( 'aria-invalid' );
				boucle( 'executer', { confirmation: 'MIGRER' } );
				return;
			}
			if ( 'annuler' === operation ) {
				var caseConfirmation = formulaire.querySelector( '[data-yume-confirmation-annulation]' );
				if ( ! caseConfirmation || ! caseConfirmation.checked ) {
					erreurVisible( operation, 'Cochez la case de confirmation pour annuler la migration.' );
					if ( caseConfirmation ) {
						caseConfirmation.focus();
					}
					return;
				}
				if ( ! window.confirm( 'Annuler la migration ? Les œuvres, tomes, chapitres et pages créés seront supprimés et l’ancien site remis en ligne.' ) ) {
					return;
				}
				boucle( 'annuler', { confirmation: 'ANNULER' } );
			}
		} );
	} );
}() );
