/**
 * Espace équipe (yume/team-dashboard) : amélioration progressive des formulaires.
 *
 * - Curseurs : valeur affichée et remplissage (--v) mis à jour pendant le glissement.
 * - « Enregistrer » : PATCH /yume/v1/tomes/{id}/planning (nonce wp_rest en X-WP-Nonce),
 *   résultat annoncé dans la zone aria-live du formulaire. « Nouveau tome » est un formulaire
 *   ordinaire (admin-post.php) : il mène à la fiche du tome ou au formulaire de publication.
 *
 * - Confirmation (data-yn-confirmer sur le formulaire ou le bouton) : « Retirer du planning »,
 *   « Retirer de l’équipe » d'un membre encore responsable de tomes.
 * - Lien direct vers une ligne (#yn-tome-ID) : la ligne est dépliée.
 * - Cadrage de la couverture (formulaire d'œuvre) : aperçu mis à jour par les curseurs, clic
 *   dans l'aperçu pour placer le point, aperçu de l'image choisie avant l'envoi, « Recentrer ».
 *
 * Sans JavaScript, les mêmes formulaires sont envoyés à admin-post.php.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-yn-cadrage]' ).forEach( function ( zone ) {
		var apercu = zone.querySelector( '[data-yn-cadrage-apercu]' );
		var image = zone.querySelector( '[data-yn-cadrage-image]' );
		var curseurs = {
			x: zone.querySelector( '[data-yn-cadrage-axe="x"]' ),
			y: zone.querySelector( '[data-yn-cadrage-axe="y"]' ),
		};
		var centrer = zone.querySelector( '[data-yn-cadrage-centrer]' );
		var formulaire = zone.closest( 'form' );
		var fichier = formulaire ? formulaire.querySelector( '[data-yn-cadrage-fichier]' ) : null;
		var adresse = null;
		if ( ! apercu || ! curseurs.x || ! curseurs.y ) {
			return;
		}
		function appliquer() {
			zone.style.setProperty( '--yn-cadrage-x', curseurs.x.value + '%' );
			zone.style.setProperty( '--yn-cadrage-y', curseurs.y.value + '%' );
		}
		curseurs.x.addEventListener( 'input', appliquer );
		curseurs.y.addEventListener( 'input', appliquer );
		apercu.addEventListener( 'click', function ( evenement ) {
			var cadre = apercu.getBoundingClientRect();
			if ( ! cadre.width || ! cadre.height ) {
				return;
			}
			curseurs.x.value = String( Math.round( Math.min( 1, Math.max( 0, ( evenement.clientX - cadre.left ) / cadre.width ) ) * 100 ) );
			curseurs.y.value = String( Math.round( Math.min( 1, Math.max( 0, ( evenement.clientY - cadre.top ) / cadre.height ) ) * 100 ) );
			appliquer();
		} );
		if ( centrer ) {
			centrer.hidden = false;
			centrer.addEventListener( 'click', function () {
				curseurs.x.value = '50';
				curseurs.y.value = '50';
				appliquer();
			} );
		}
		if ( fichier && image ) {
			fichier.addEventListener( 'change', function () {
				var choisi = fichier.files && fichier.files[ 0 ];
				if ( ! choisi || ! /^image\//.test( choisi.type ) ) {
					return;
				}
				if ( adresse ) {
					URL.revokeObjectURL( adresse );
				}
				adresse = URL.createObjectURL( choisi );
				image.src = adresse;
				image.hidden = false;
				zone.removeAttribute( 'data-yn-cadrage-vide' );
				curseurs.x.value = '50';
				curseurs.y.value = '50';
				appliquer();
			} );
		}
	} );

	document.addEventListener(
		'submit',
		function ( evenement ) {
			var formulaire = evenement.target;
			var bouton = evenement.submitter;
			var source = bouton && bouton.hasAttribute && bouton.hasAttribute( 'data-yn-confirmer' ) ? bouton : formulaire;
			if ( ! source || ! source.hasAttribute || ! source.hasAttribute( 'data-yn-confirmer' ) ) {
				return;
			}
			if ( ! window.confirm( source.getAttribute( 'data-yn-confirmer' ) ) ) {
				evenement.preventDefault();
				evenement.stopImmediatePropagation();
			}
		},
		true
	);

	function deplierAncre() {
		var id = window.location.hash.replace( /^#/, '' );
		if ( ! /^yn-tome-\d+$/.test( id ) ) {
			return;
		}
		var cible = document.getElementById( id );
		if ( cible && cible.tagName === 'DETAILS' ) {
			cible.open = true;
		}
	}
	deplierAncre();
	window.addEventListener( 'hashchange', deplierAncre );

	var racine = document.querySelector( '.yn-team[data-yn-rest]' );
	if ( ! racine || ! window.fetch || ! window.FormData ) {
		return;
	}

	var base = racine.getAttribute( 'data-yn-rest' ) || '/wp-json/';
	var nonce = racine.getAttribute( 'data-yn-nonce' ) || '';
	var icones = { a_lheure: '●', en_retard: '▲', bloque: '■', publie: '✓' };
	var variantes = { a_lheure: 'ok', en_retard: 'warn', bloque: 'err', publie: 'ok' };
	var champsTexte = [ 'etape', 'date_cible', 'note_equipe', 'bloque_raison' ];

	function message( cle, defaut ) {
		return racine.getAttribute( 'data-yn-msg-' + cle ) || defaut;
	}

	/* Adresse d'une route : fonctionne avec /wp-json/ comme avec ?rest_route=/. */
	function route( chemin ) {
		return base + chemin.replace( /^\//, '' );
	}

	function majCurseur( curseur ) {
		curseur.style.setProperty( '--v', curseur.value + '%' );
		var sortie = document.getElementById( curseur.getAttribute( 'data-yn-sortie' ) || '' );
		if ( sortie ) {
			sortie.textContent = curseur.value + ' %';
		}
	}

	racine.addEventListener( 'input', function ( evenement ) {
		var cible = evenement.target;
		if ( cible && cible.hasAttribute && cible.hasAttribute( 'data-yn-avancement' ) ) {
			majCurseur( cible );
		}
	} );

	function donnees( formulaire ) {
		var d = {};
		new window.FormData( formulaire ).forEach( function ( valeur, cle ) {
			var m = /^(avancement|responsables)\[(traduction|relecture|edition)\]$/.exec( cle );
			if ( m ) {
				d[ m[ 1 ] ] = d[ m[ 1 ] ] || {};
				d[ m[ 1 ] ][ m[ 2 ] ] = parseInt( valeur, 10 ) || 0;
				return;
			}
			if ( champsTexte.indexOf( cle ) !== -1 ) {
				d[ cle ] = String( valeur );
			}
		} );
		if ( formulaire.querySelector( '[name="bloque_present"]' ) ) {
			var caseBloque = formulaire.querySelector( '[name="bloque"]' );
			d.bloque = !! ( caseBloque && caseBloque.checked );
		}
		return d;
	}

	function annoncer( zone, texte, type ) {
		if ( ! zone ) {
			return;
		}
		zone.classList.remove( 'yn-team__retour--ok', 'yn-team__retour--erreur' );
		if ( type ) {
			zone.classList.add( 'yn-team__retour--' + type );
		}
		zone.textContent = texte;
		if ( type === 'erreur' && zone.scrollIntoView ) {
			zone.scrollIntoView( { block: 'nearest' } );
		}
	}

	function majPastille( formulaire, tome ) {
		var carte = formulaire.closest( '[data-yn-carte]' ) || formulaire;
		var puce = carte.querySelector( '[data-yn-puce]' );
		if ( ! puce || ! tome || ! tome.etat ) {
			return;
		}
		/* Sortie programmée : « Programmé le … », style distinct (comme pastille_ligne()). */
		var programme = !! tome.programme && tome.etat !== 'publie';
		puce.className = programme ? 'yn-chip yn-chip--new yn-chip--programme' : 'yn-chip yn-chip--' + ( variantes[ tome.etat ] || 'info' );
		puce.textContent = '';
		var icone = document.createElement( 'span' );
		icone.setAttribute( 'aria-hidden', 'true' );
		icone.textContent = programme ? '◷' : icones[ tome.etat ] || '●';
		puce.appendChild( icone );
		puce.appendChild( document.createTextNode( ' ' + ( tome.etat_libelle || '' ) ) );
		carte.classList.toggle( 'yn-team__tache--retard', tome.etat === 'en_retard' );
	}

	function envoyer( formulaire, url, methode ) {
		var zone = formulaire.querySelector( '[data-yn-retour]' );
		var bouton = formulaire.querySelector( '[type="submit"]' );
		if ( bouton ) {
			bouton.disabled = true;
			bouton.setAttribute( 'aria-busy', 'true' );
		}
		annoncer( zone, message( 'envoi', 'Enregistrement…' ), '' );

		window.fetch( url, {
			method: methode,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( donnees( formulaire ) ),
		} )
			.then( function ( reponse ) {
				return reponse
					.json()
					.catch( function () {
						return {};
					} )
					.then( function ( json ) {
						return { ok: reponse.ok, json: json || {} };
					} );
			} )
			.then( function ( resultat ) {
				if ( resultat.ok ) {
					annoncer( zone, resultat.json.message || '', 'ok' );
					majPastille( formulaire, resultat.json.tome );
					return;
				}
				var texte = resultat.json.message || message( 'erreur', 'L’enregistrement a échoué.' );
				if ( resultat.json.code === 'rest_cookie_invalid_nonce' ) {
					texte = message( 'session', texte );
				}
				annoncer( zone, texte, 'erreur' );
			} )
			.catch( function () {
				annoncer( zone, message( 'reseau', 'Connexion impossible.' ), 'erreur' );
			} )
			.then( function () {
				if ( bouton ) {
					bouton.disabled = false;
					bouton.removeAttribute( 'aria-busy' );
				}
			} );
	}

	racine.addEventListener( 'submit', function ( evenement ) {
		var formulaire = evenement.target;
		if ( ! formulaire || ! formulaire.hasAttribute ) {
			return;
		}
		if ( formulaire.hasAttribute( 'data-yn-planning' ) ) {
			evenement.preventDefault();
			envoyer( formulaire, route( 'yume/v1/tomes/' + encodeURIComponent( formulaire.getAttribute( 'data-yn-planning' ) ) + '/planning' ), 'PATCH' );
		}
	} );
} )();
