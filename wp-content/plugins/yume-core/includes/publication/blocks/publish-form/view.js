/**
 * Bloc yume/publish-form : amélioration progressive du formulaire de publication.
 *
 * Sans JavaScript, le formulaire est envoyé à admin-post.php. Avec JavaScript :
 * - glisser-déposer de la couverture et du DOCX/EPUB (le champ fichier reste utilisable
 *   au clavier) ;
 * - analyse immédiate du fichier déposé (POST /yume/v1/publications/analyse) : chapitres
 *   détectés, nombre de mots, avertissements, rien n'est créé ;
 * - enregistrement via l'API REST (POST /yume/v1/publications) avec barre de progression,
 *   puis publication ou programmation (POST /yume/v1/publications/{id}/publier) ;
 * - aperçu du chapitre 1 dans un nouvel onglet ;
 * - liste « Tome du planning » limitée aux tomes de l'œuvre choisie, qui préremplit nature,
 *   numéro et titre et cible ce tome (pas de doublon) ;
 * - tome sans chapitre ni lien PDF/EPUB : confirmation explicite avant de publier.
 *
 * JavaScript ES2019 sans dépendance ; nonce wp_rest envoyé en X-WP-Nonce.
 */
( function () {
	'use strict';

	var NF = typeof Intl !== 'undefined' ? new Intl.NumberFormat( 'fr-FR' ) : null;

	function nombre( n ) {
		return NF ? NF.format( n ) : String( n );
	}

	function taille( octets ) {
		if ( octets >= 1048576 ) {
			return String( Math.round( octets / 104857.6 ) / 10 ).replace( '.', ',' ) + ' Mo';
		}
		return Math.max( 1, Math.round( octets / 1024 ) ) + ' ko';
	}

	function numeroFr( n ) {
		return String( n ).replace( '.', ',' );
	}

	function el( balise, classe, texte ) {
		var noeud = document.createElement( balise );
		if ( classe ) {
			noeud.className = classe;
		}
		if ( typeof texte === 'string' ) {
			noeud.textContent = texte;
		}
		return noeud;
	}

	function lien( url, texte ) {
		var a = el( 'a', '', texte );
		a.href = url;
		return a;
	}

	/**
	 * Requête multipart (XMLHttpRequest pour suivre la progression de l'envoi).
	 */
	function requete( url, donnees, nonce, surProgression ) {
		return new Promise( function ( resoudre, rejeter ) {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', url );
			xhr.setRequestHeader( 'X-WP-Nonce', nonce );
			xhr.setRequestHeader( 'Accept', 'application/json' );
			xhr.responseType = 'text';
			if ( surProgression && xhr.upload ) {
				xhr.upload.addEventListener( 'progress', function ( e ) {
					if ( e.lengthComputable ) {
						surProgression( e.loaded / e.total );
					}
				} );
			}
			xhr.addEventListener( 'load', function () {
				var json = null;
				try {
					json = JSON.parse( xhr.responseText );
				} catch ( err ) {
					json = null;
				}
				if ( xhr.status >= 200 && xhr.status < 300 && json ) {
					resoudre( json );
					return;
				}
				var message = json && json.message ? json.message : '';
				if ( ! message ) {
					message = xhr.status === 413
						? 'Fichier trop volumineux pour le serveur.'
						: 'Le serveur n’a pas pu traiter la demande (erreur ' + xhr.status + ').';
				}
				var erreur = new Error( message );
				erreur.code = json && json.code ? json.code : '';
				rejeter( erreur );
			} );
			xhr.addEventListener( 'error', function () {
				rejeter( new Error( 'Connexion au site impossible : vérifiez votre connexion et réessayez.' ) );
			} );
			xhr.send( donnees );
		} );
	}

	function initialiser( racine ) {
		var form = racine.querySelector( '[data-yn-formulaire]' );
		if ( ! form || ! window.FormData || ! window.XMLHttpRequest || ! window.Promise ) {
			return;
		}
		var rest = racine.getAttribute( 'data-rest' ) || '';
		var nonce = racine.getAttribute( 'data-nonce' ) || '';
		var max = parseInt( racine.getAttribute( 'data-taille-max' ) || '0', 10 );
		var source = form.querySelector( '[data-yn-fichier="source"]' );
		var couverture = form.querySelector( '[data-yn-fichier="couverture"]' );
		var tome = form.querySelector( '[data-yn-tome]' );
		var messages = racine.querySelector( '[data-yn-messages]' );
		var fiche = racine.querySelector( '[data-yn-fiche]' );
		var ficheFormat = racine.querySelector( '[data-yn-fiche-format]' );
		var ficheNom = racine.querySelector( '[data-yn-fiche-nom]' );
		var ficheDetails = racine.querySelector( '[data-yn-fiche-details]' );
		var retirer = racine.querySelector( '[data-yn-retirer]' );
		var liste = racine.querySelector( '[data-yn-liste]' );
		var avertissements = racine.querySelector( '[data-yn-avertissements]' );
		var progression = racine.querySelector( '[data-yn-progression]' );
		var resultat = racine.querySelector( '[data-yn-resultat]' );
		var etat = racine.querySelector( '[data-yn-etat]' );
		var date = form.querySelector( '[data-yn-date]' );
		var dateLibelle = form.querySelector( '[data-yn-date-libelle]' );
		var vignette = racine.querySelector( '[data-yn-vignette]' );
		var nomCouverture = racine.querySelector( '[data-yn-nom-couverture]' );
		var recapTome = racine.querySelector( '[data-yn-recap-tome]' );
		var recapChapitres = racine.querySelector( '[data-yn-recap-chapitres]' );
		var boutons = form.querySelectorAll( 'button[type="submit"]' );
		var planning = form.querySelector( '[data-yn-planning]' );
		var planningOrigine = planning ? planning.cloneNode( true ) : null;
		var confirmerVide = form.querySelector( '[data-yn-confirmer-vide]' );
		var declencheur = null;
		var sourceAEnvoyer = false;
		var analyseCourante = 0;

		racine.classList.add( 'is-js' );

		function annoncer( texte, type, liens ) {
			messages.textContent = '';
			var carte = el( 'div', 'yn-card yn-publish__message yn-publish__message--' + ( type === 'erreur' ? 'erreur' : 'succes' ) );
			if ( type === 'erreur' ) {
				carte.setAttribute( 'role', 'alert' );
			}
			var p = el( 'p' );
			p.appendChild( el( 'strong', '', texte ) );
			carte.appendChild( p );
			if ( liens && liens.length ) {
				var pl = el( 'p', 'yn-publish__liens' );
				liens.forEach( function ( l ) {
					pl.appendChild( lien( l[ 0 ], l[ 1 ] ) );
				} );
				carte.appendChild( pl );
			}
			messages.appendChild( carte );
		}

		function progres( valeur ) {
			if ( ! progression ) {
				return;
			}
			if ( valeur === null ) {
				progression.hidden = true;
				return;
			}
			progression.hidden = false;
			progression.firstElementChild.style.setProperty( '--v', Math.round( valeur * 100 ) + '%' );
		}

		function occupe( oui ) {
			form.setAttribute( 'aria-busy', oui ? 'true' : 'false' );
			Array.prototype.forEach.call( boutons, function ( b ) {
				b.disabled = oui;
			} );
		}

		function libelleChapitre( c ) {
			var numero = c.nature === 'chapitre' && c.numero !== null ? numeroFr( c.numero ) : c.titre;
			var titre = c.sous_titre ? c.sous_titre : ( c.nature === 'chapitre' ? '' : '' );
			return { numero: numero, titre: titre };
		}

		function afficherChapitres( chapitres ) {
			liste.textContent = '';
			if ( ! chapitres.length ) {
				liste.appendChild( el( 'span', 'yn-muted yn-publish__vide', 'Aucun chapitre détecté : vérifiez les styles Titre 1 du document.' ) );
				return;
			}
			chapitres.slice( 0, 7 ).forEach( function ( c ) {
				var l = libelleChapitre( c );
				var span = el( 'span' );
				span.appendChild( el( 'strong', '', l.numero ) );
				if ( l.titre ) {
					span.appendChild( document.createTextNode( ' · ' + l.titre ) );
				}
				span.appendChild( document.createTextNode( ' ' ) );
				span.appendChild( el( 'span', 'yn-muted', nombre( c.nb_mots ) + ' mots' ) );
				liste.appendChild( span );
			} );
			if ( chapitres.length > 7 ) {
				var reste = chapitres.slice( 7 );
				var speciaux = reste.filter( function ( c ) {
					return c.nature !== 'chapitre';
				} ).map( function ( c ) {
					return c.titre;
				} );
				var autres = reste.length - speciaux.length;
				var texte = '… ' + ( autres > 0 ? autres + ( autres > 1 ? ' autres' : ' autre' ) : '' );
				if ( speciaux.length ) {
					texte += ( autres > 0 ? ' · ' : '' ) + speciaux.join( ', ' );
				}
				liste.appendChild( el( 'span', 'yn-muted', texte ) );
			}
		}

		function afficherAvertissements( liste ) {
			avertissements.textContent = '';
			( liste || [] ).forEach( function ( texte ) {
				var li = el( 'li' );
				li.appendChild( el( 'span', 'yn-chip yn-chip--warn', '▲ ' + texte ) );
				avertissements.appendChild( li );
			} );
			avertissements.hidden = ! ( liste && liste.length );
		}

		function majRecap( chapitres ) {
			var nature = form.querySelector( '[data-yn-nature]' );
			var numero = form.querySelector( '[data-yn-numero]' );
			var oeuvre = form.querySelector( '[data-yn-oeuvre]' );
			if ( recapTome && nature && numero ) {
				recapTome.textContent = '';
				var libelle = nature.options[ nature.selectedIndex ].text + ( numero.value ? ' ' + numero.value.replace( '.', ',' ) : '' );
				recapTome.appendChild( el( 'strong', '', libelle ) );
				var nomOeuvre = oeuvre && oeuvre.value ? oeuvre.options[ oeuvre.selectedIndex ].text.replace( / \([^)]*\)$/, '' ) : '';
				recapTome.appendChild( document.createTextNode( ( nomOeuvre ? ' de ' + nomOeuvre : '' ) + ' avec sa couverture et ses liens PDF et EPUB' ) );
			}
			if ( recapChapitres && chapitres ) {
				var numerotes = chapitres.filter( function ( c ) {
					return c.nature === 'chapitre';
				} ).length;
				var speciaux = chapitres.filter( function ( c ) {
					return c.nature !== 'chapitre';
				} ).map( function ( c ) {
					return c.titre.toLowerCase();
				} );
				recapChapitres.textContent = '';
				recapChapitres.appendChild( el( 'strong', '', chapitres.length + ( chapitres.length > 1 ? ' pages de lecture' : ' page de lecture' ) ) );
				var detail = numerotes + ( numerotes > 1 ? ' chapitres' : ' chapitre' ) + ( speciaux.length ? ' + ' + speciaux.join( ', ' ) : '' );
				recapChapitres.appendChild( document.createTextNode( ' (' + detail + '), navigation et sommaire' ) );
			}
		}

		function afficherFiche( fichier, format, details, type ) {
			fiche.hidden = false;
			ficheFormat.className = 'yn-chip yn-chip--' + ( type || 'info' );
			ficheFormat.textContent = ( type === 'ok' ? '✓ ' : ( type === 'err' ? '✕ ' : '' ) ) + format.toUpperCase();
			ficheNom.textContent = fichier;
			ficheDetails.textContent = details;
			if ( retirer ) {
				retirer.hidden = false;
			}
		}

		function extension( nom ) {
			var m = /\.([a-z0-9]+)$/i.exec( nom || '' );
			return m ? m[ 1 ].toLowerCase() : '';
		}

		function analyser() {
			var fichier = source.files && source.files[ 0 ];
			if ( ! fichier ) {
				return;
			}
			var ext = extension( fichier.name );
			if ( ext !== 'docx' && ext !== 'epub' ) {
				source.value = '';
				afficherFiche( fichier.name, ext || '?', 'Format refusé : déposez le fichier Word du tome (.docx) ou son EPUB.', 'err' );
				annoncer( 'Format refusé : déposez le fichier Word du tome (.docx) ou, à défaut, son EPUB.', 'erreur' );
				return;
			}
			if ( max && fichier.size > max ) {
				source.value = '';
				afficherFiche( fichier.name, ext, 'Trop volumineux : ' + taille( fichier.size ) + ' (maximum ' + taille( max ) + ').', 'err' );
				annoncer( 'Fichier trop volumineux (' + taille( fichier.size ) + ' ; maximum ' + taille( max ) + ').', 'erreur' );
				return;
			}
			sourceAEnvoyer = true;
			var numeroAnalyse = ++analyseCourante;
			afficherFiche( fichier.name, ext, taille( fichier.size ) + ' · analyse en cours…', 'info' );
			liste.setAttribute( 'aria-busy', 'true' );
			var donnees = new FormData();
			donnees.append( 'source', fichier );
			[ 'oeuvre_id', 'nature', 'numero' ].forEach( function ( nom ) {
				var champ = form.elements.namedItem( nom );
				if ( champ && champ.value ) {
					donnees.append( nom, champ.value );
				}
			} );
			requete( rest + 'publications/analyse', donnees, nonce, progres ).then( function ( rapport ) {
				if ( numeroAnalyse !== analyseCourante ) {
					return;
				}
				afficherFiche( fichier.name, ext, taille( fichier.size ) + ' · analysé : ' + rapport.resume, 'ok' );
				afficherChapitres( rapport.chapitres || [] );
				afficherAvertissements( rapport.avertissements || [] );
				majRecap( rapport.chapitres || [] );
				var texte = 'Analyse terminée : ' + rapport.resume + '.';
				if ( rapport.tome_existant && etat ) {
					etat.textContent = 'Tome existant (' + rapport.tome_existant.etat.toLowerCase() + ') : mise à jour';
				}
				if ( rapport.tome_existant ) {
					texte += ' ' + rapport.tome_existant.titre + ' existe déjà (' + rapport.tome_existant.etat.toLowerCase() + ') : il sera mis à jour, ses adresses sont conservées.';
				}
				annoncer( texte, 'succes' );
			} ).catch( function ( erreur ) {
				if ( numeroAnalyse !== analyseCourante ) {
					return;
				}
				afficherFiche( fichier.name, ext, erreur.message, 'err' );
				annoncer( erreur.message, 'erreur' );
			} ).finally( function () {
				liste.removeAttribute( 'aria-busy' );
				progres( null );
			} );
		}

		function majDate() {
			if ( ! dateLibelle ) {
				return;
			}
			if ( ! date.value ) {
				dateLibelle.textContent = '';
				return;
			}
			var d = new Date( date.value );
			if ( isNaN( d.getTime() ) || typeof Intl === 'undefined' ) {
				dateLibelle.textContent = '· ' + date.value.replace( 'T', ' ' );
				return;
			}
			var jour = new Intl.DateTimeFormat( 'fr-FR', { weekday: 'short', day: 'numeric', month: 'short' } ).format( d );
			var heure = new Intl.DateTimeFormat( 'fr-FR', { hour: '2-digit', minute: '2-digit' } ).format( d );
			dateLibelle.textContent = '· ' + jour + ' ' + heure;
		}

		function afficherResultat( rapport, sortie ) {
			resultat.textContent = '';
			var titre = sortie
				? ( sortie.statut === 'publish' ? 'Publié !' : 'Sortie programmée' )
				: 'Brouillon enregistré';
			resultat.appendChild( el( 'span', 'yn-label', titre ) );
			var ul = el( 'ul' );
			var tomeInfo = sortie ? sortie.tome : rapport.tome;
			var li = el( 'li' );
			li.appendChild( lien( tomeInfo.statut === 'publish' ? tomeInfo.lien : tomeInfo.apercu, tomeInfo.statut === 'publish' ? 'Voir le tome' : 'Prévisualiser le tome' ) );
			ul.appendChild( li );
			if ( rapport && rapport.chapitres && rapport.chapitres.length ) {
				var c = rapport.chapitres[ 0 ];
				li = el( 'li' );
				li.appendChild( lien( sortie && sortie.statut === 'publish' ? c.lien : c.apercu, 'Lire « ' + c.titre + ' »' ) );
				ul.appendChild( li );
			}
			var article = sortie && sortie.article ? sortie.article : ( rapport ? rapport.article : null );
			if ( article ) {
				li = el( 'li' );
				li.appendChild( lien( article.edition || article.apercu, 'Modifier l’annonce (' + article.etat.toLowerCase() + ')' ) );
				ul.appendChild( li );
			}
			li = el( 'li' );
			li.appendChild( lien( tomeInfo.edition, 'Modifier le tome dans l’administration' ) );
			ul.appendChild( li );
			resultat.appendChild( ul );
			resultat.hidden = false;
		}

		/**
		 * Liste « Tome du planning » : seulement les tomes de l'œuvre choisie (toutes les
		 * œuvres, groupées, si aucune n'est choisie).
		 */
		function filtrerPlanning() {
			if ( ! planning ) {
				return;
			}
			var oeuvre = form.querySelector( '[data-yn-oeuvre]' );
			var choisie = oeuvre ? oeuvre.value : '';
			var actuelle = planning.value;
			planning.textContent = '';
			if ( ! choisie ) {
				Array.prototype.forEach.call( planningOrigine.children, function ( enfant ) {
					planning.appendChild( enfant.cloneNode( true ) );
				} );
			} else {
				planning.appendChild( planningOrigine.querySelector( 'option[value=""]' ).cloneNode( true ) );
				var trouves = 0;
				Array.prototype.forEach.call( planningOrigine.querySelectorAll( 'option[data-oeuvre]' ), function ( option ) {
					if ( option.getAttribute( 'data-oeuvre' ) === choisie ) {
						planning.appendChild( option.cloneNode( true ) );
						trouves++;
					}
				} );
				if ( ! trouves ) {
					var vide = el( 'option', '', 'Aucun tome en préparation pour cette œuvre' );
					vide.disabled = true;
					vide.value = '-';
					planning.appendChild( vide );
				}
			}
			var garde = actuelle && planning.querySelector( 'option[value="' + actuelle + '"]' );
			planning.value = garde ? actuelle : '';
			if ( actuelle && ! garde && tome.value === actuelle ) {
				tome.value = '';
			}
		}

		function choisirPlanning() {
			var option = planning.options[ planning.selectedIndex ];
			if ( ! option || ! option.value || option.value === '-' ) {
				tome.value = '';
				if ( etat ) {
					etat.textContent = 'Nouveau tome';
				}
				majRecap( null );
				return;
			}
			var oeuvre = form.querySelector( '[data-yn-oeuvre]' );
			var nature = form.querySelector( '[data-yn-nature]' );
			var numero = form.querySelector( '[data-yn-numero]' );
			var titre = form.elements.namedItem( 'titre' );
			if ( oeuvre && ! oeuvre.value ) {
				oeuvre.value = option.getAttribute( 'data-oeuvre' ) || '';
				filtrerPlanning();
				planning.value = option.value;
			}
			if ( nature && option.getAttribute( 'data-nature' ) ) {
				nature.value = option.getAttribute( 'data-nature' );
			}
			if ( numero ) {
				numero.value = option.getAttribute( 'data-numero' ) || '';
			}
			if ( titre && option.getAttribute( 'data-titre' ) ) {
				titre.value = option.getAttribute( 'data-titre' );
			}
			if ( date && option.getAttribute( 'data-date' ) && ! date.value ) {
				date.value = option.getAttribute( 'data-date' );
				majDate();
			}
			tome.value = option.value;
			if ( etat ) {
				etat.textContent = 'Tome du planning : mise à jour';
			}
			majRecap( null );
		}

		function envoyer( evenement ) {
			var etape = declencheur && declencheur.value ? declencheur.value : 'brouillon';
			declencheur = null;
			if ( typeof form.reportValidity === 'function' && ! form.reportValidity() ) {
				evenement.preventDefault();
				return;
			}
			evenement.preventDefault();
			if ( etape === 'programmer' && ! date.value ) {
				annoncer( 'Indiquez la date et l’heure de sortie pour programmer la publication.', 'erreur' );
				date.focus();
				return;
			}
			if ( etape === 'publier' && ! window.confirm( 'Publier maintenant ? Les chapitres seront en ligne et les lecteurs qui suivent l’œuvre seront prévenus.' ) ) {
				return;
			}
			var fenetre = etape === 'apercu' ? window.open( '', '_blank' ) : null;
			var donnees = new FormData( form );
			[ 'action', '_yume_nonce', '_wp_http_referer', 'etape' ].forEach( function ( nom ) {
				donnees.delete( nom );
			} );
			if ( ! sourceAEnvoyer || ! ( source.files && source.files.length ) ) {
				donnees.delete( 'source' );
			}
			if ( ! ( couverture.files && couverture.files.length ) ) {
				donnees.delete( 'couverture' );
			}
			occupe( true );
			annoncer( sourceAEnvoyer ? 'Envoi et découpage du fichier en cours… (les illustrations sont redimensionnées, cela peut prendre une minute)' : 'Enregistrement en cours…', 'succes' );
			var rapportCourant = null;
			requete( rest + 'publications', donnees, nonce, sourceAEnvoyer ? progres : null ).then( function ( rapport ) {
				rapportCourant = rapport;
				tome.value = rapport.tome.id;
				if ( planning && planning.querySelector( 'option[value="' + rapport.tome.id + '"]' ) ) {
					planning.value = String( rapport.tome.id );
				}
				sourceAEnvoyer = false;
				source.value = '';
				couverture.value = '';
				if ( rapport.import ) {
					afficherFiche( rapport.import.fichier.nom, rapport.import.fichier.format, rapport.import.fichier.taille + ' · importé : ' + rapport.import.resume, 'ok' );
				}
				afficherAvertissements( rapport.avertissements || [] );
				if ( rapport.chapitres && rapport.chapitres.length ) {
					afficherChapitres( rapport.chapitres.map( function ( c ) {
						return { numero: c.numero, nature: c.nature, titre: c.libelle, sous_titre: c.sous_titre, nb_mots: c.nb_mots };
					} ) );
				}
				if ( etat ) {
					etat.textContent = 'Brouillon enregistré à ' + new Date().toLocaleTimeString( 'fr-FR', { hour: '2-digit', minute: '2-digit' } );
				}
				if ( etape === 'apercu' ) {
					var cible = rapport.chapitres && rapport.chapitres.length ? rapport.chapitres[ 0 ].apercu : rapport.tome.apercu;
					if ( fenetre ) {
						fenetre.location.href = cible;
					} else {
						window.location.href = cible;
					}
					annoncer( 'Brouillon enregistré : l’aperçu du chapitre 1 s’ouvre dans un nouvel onglet.', 'succes', [ [ cible, 'Ouvrir l’aperçu du chapitre 1' ] ] );
					afficherResultat( rapport, null );
					return null;
				}
				if ( etape === 'publier' || etape === 'programmer' ) {
					var sortir = function ( confirme ) {
						var sortieDonnees = new FormData();
						sortieDonnees.append( 'quand', etape === 'publier' ? 'maintenant' : date.value );
						if ( confirme ) {
							sortieDonnees.append( 'confirmer_vide', '1' );
						}
						return requete( rest + 'publications/' + rapport.tome.id + '/publier', sortieDonnees, nonce, null ).catch( function ( erreur ) {
							// Tome sans chapitre ni lien PDF/EPUB : confirmation explicite.
							if ( ! confirme && erreur.code === 'yume_tome_vide' && window.confirm( erreur.message + '\n\nPublier quand même ce tome vide ?' ) ) {
								return sortir( true );
							}
							throw erreur;
						} );
					};
					return sortir( !! ( confirmerVide && confirmerVide.checked ) ).then( function ( sortie ) {
						if ( etat ) {
							etat.textContent = sortie.statut === 'publish' ? 'Tome publié' : 'Sortie programmée';
						}
						annoncer(
							sortie.statut === 'publish'
								? sortie.tome.titre + ' est en ligne ! Les chapitres, l’annonce et les notifications sont partis.'
								: sortie.tome.titre + ' sortira le ' + new Date( sortie.date ).toLocaleString( 'fr-FR', { dateStyle: 'full', timeStyle: 'short' } ) + '.',
							'succes',
							[ [ sortie.statut === 'publish' ? sortie.tome.lien : sortie.tome.apercu, sortie.statut === 'publish' ? 'Voir le tome' : 'Prévisualiser le tome' ] ]
						);
						afficherResultat( rapportCourant, sortie );
					} );
				}
				annoncer( 'Brouillon enregistré : ' + rapport.tome.titre + ', ' + rapport.chapitres.length + ( rapport.chapitres.length > 1 ? ' chapitres.' : ' chapitre.' ), 'succes' );
				afficherResultat( rapport, null );
				return null;
			} ).catch( function ( erreur ) {
				if ( fenetre ) {
					fenetre.close();
				}
				annoncer( rapportCourant ? 'Le brouillon est enregistré, mais la publication a échoué : ' + erreur.message : erreur.message, 'erreur' );
			} ).finally( function () {
				occupe( false );
				progres( null );
			} );
		}

		// Glisser-déposer.
		Array.prototype.forEach.call( racine.querySelectorAll( '[data-yn-depot]' ), function ( zone ) {
			var champ = zone.querySelector( 'input[type="file"]' );
			[ 'dragenter', 'dragover' ].forEach( function ( type ) {
				zone.addEventListener( type, function ( e ) {
					e.preventDefault();
					zone.classList.add( 'is-survol' );
				} );
			} );
			[ 'dragleave', 'dragend', 'drop' ].forEach( function ( type ) {
				zone.addEventListener( type, function ( e ) {
					if ( type === 'dragleave' && e.relatedTarget && zone.contains( e.relatedTarget ) ) {
						return;
					}
					zone.classList.remove( 'is-survol' );
				} );
			} );
			zone.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				var fichiers = e.dataTransfer && e.dataTransfer.files;
				if ( ! fichiers || ! fichiers.length || ! champ ) {
					return;
				}
				try {
					var dt = new DataTransfer();
					dt.items.add( fichiers[ 0 ] );
					champ.files = dt.files;
				} catch ( err ) {
					annoncer( 'Le glisser-déposer n’est pas disponible dans ce navigateur : utilisez « Choisir un fichier ».', 'erreur' );
					return;
				}
				champ.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		} );

		source.addEventListener( 'change', analyser );
		couverture.addEventListener( 'change', function () {
			var fichier = couverture.files && couverture.files[ 0 ];
			if ( ! fichier ) {
				return;
			}
			if ( ! /^image\/(jpeg|png|webp)$/.test( fichier.type ) ) {
				couverture.value = '';
				annoncer( 'Couverture refusée : image JPG, PNG ou WebP attendue.', 'erreur' );
				return;
			}
			nomCouverture.textContent = fichier.name + ' · ' + taille( fichier.size );
			if ( vignette && window.URL && URL.createObjectURL ) {
				vignette.textContent = '';
				var img = el( 'img' );
				img.src = URL.createObjectURL( fichier );
				img.alt = 'Aperçu de la nouvelle couverture';
				vignette.appendChild( img );
				vignette.hidden = false;
			}
		} );
		if ( retirer ) {
			retirer.addEventListener( 'click', function () {
				source.value = '';
				sourceAEnvoyer = false;
				analyseCourante++;
				fiche.hidden = true;
				afficherAvertissements( [] );
				liste.textContent = '';
				liste.appendChild( el( 'span', 'yn-muted yn-publish__vide', 'Déposez le fichier du tome : ses chapitres apparaîtront ici avant tout enregistrement.' ) );
				messages.textContent = '';
				source.focus();
			} );
		}
		if ( date ) {
			date.addEventListener( 'change', majDate );
			date.addEventListener( 'input', majDate );
		}
		[ 'oeuvre_id', 'nature', 'numero' ].forEach( function ( nom ) {
			var champ = form.elements.namedItem( nom );
			if ( champ ) {
				champ.addEventListener( 'change', function () {
					if ( nom === 'oeuvre_id' ) {
						filtrerPlanning();
					}
					majRecap( null );
				} );
			}
		} );
		if ( planning ) {
			planning.addEventListener( 'change', choisirPlanning );
			filtrerPlanning();
		}
		form.addEventListener( 'click', function ( e ) {
			var bouton = e.target.closest ? e.target.closest( 'button[type="submit"]' ) : null;
			if ( bouton ) {
				declencheur = bouton;
			}
		} );
		form.addEventListener( 'submit', envoyer );
	}

	function demarrer() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-yn-publish]' ), initialiser );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
