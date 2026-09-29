/**
 * Bloc yume/publish-form : amélioration progressive du formulaire de publication.
 *
 * Sans JavaScript, le formulaire est envoyé à admin-post.php. Avec JavaScript :
 * - glisser-déposer de la couverture et du DOCX/EPUB (le champ fichier reste utilisable
 *   au clavier) ;
 * - analyse immédiate du fichier déposé (POST /yume/v1/publications/analyse) : chapitres
 *   détectés, nombre de mots, avertissements, rien n'est créé ;
 * - « Délimiter les chapitres moi-même » : tableau des débuts de chapitre possibles renvoyés par
 *   l'analyse (case, nature, titre), découpages rapides (chaque illustration, chaque saut de
 *   page), texte d'ouverture conservé ou non, nombre de chapitres obtenus annoncé ; le découpage
 *   est écrit dans le champ caché « plan » et envoyé AVEC le fichier (le serveur ne garde jamais
 *   le fichier : pour redécouper, il faut le choisir à nouveau) ;
 * - enregistrement via l'API REST (POST /yume/v1/publications) avec barre de progression,
 *   puis publication ou programmation (POST /yume/v1/publications/{id}/publier) ;
 * - aperçu du chapitre 1 dans un nouvel onglet ;
 * - liste « Tome du planning » limitée aux tomes de l'œuvre choisie, qui préremplit nature,
 *   numéro et titre et cible ce tome (pas de doublon) ;
 * - tome sans chapitre ni lien PDF/EPUB : confirmation explicite avant de publier ;
 * - case « Ajout au catalogue » (sans annonce) : cochée d'office pour un tome déjà publié
 *   (tant qu'elle n'a pas été touchée), récapitulatif et messages adaptés, valeur envoyée
 *   explicitement à la publication ;
 * - tome paru avec sa lecture en ligne : « Vérifier (sans rien changer en ligne) » prépare une
 *   version en attente (rapport et aperçus affichés dans l'encadré), « Remplacer la lecture en
 *   ligne maintenant » l'applique (POST …/publier), « Annuler le remplacement » la supprime
 *   (DELETE /yume/v1/publications/{id}/remplacement).
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
	function requete( url, donnees, nonce, surProgression, methode ) {
		return new Promise( function ( resoudre, rejeter ) {
			var xhr = new XMLHttpRequest();
			xhr.open( methode || 'POST', url );
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
		// Boutons du formulaire, y compris ceux de l'encadré de remplacement (attribut form).
		var boutons = racine.querySelectorAll( 'button[type="submit"]' );
		var attente = racine.querySelector( '[data-yn-attente]' );
		var attenteTexte = racine.querySelector( '[data-yn-attente-texte]' );
		var attenteListe = racine.querySelector( '[data-yn-attente-liste]' );
		var planning = form.querySelector( '[data-yn-planning]' );
		var planningOrigine = planning ? planning.cloneNode( true ) : null;
		var confirmerVide = form.querySelector( '[data-yn-confirmer-vide]' );
		var sansAnnonce = form.querySelector( '[data-yn-sans-annonce]' );
		var sansAnnonceTouchee = false;
		var recapAnnonce = racine.querySelector( '[data-yn-recap-annonce]' );
		var recapNotifications = racine.querySelector( '[data-yn-recap-notifications]' );
		var recapCatalogue = racine.querySelector( '[data-yn-recap-catalogue]' );
		var declencheur = null;
		var sourceAEnvoyer = false;
		var analyseCourante = 0;
		// Découpage manuel.
		var decoupage = racine.querySelector( '[data-yn-decoupage]' );
		var planChamp = form.querySelector( '[data-yn-plan]' );
		var planActif = racine.querySelector( '[data-yn-plan-actif]' );
		var planAvant = racine.querySelector( '[data-yn-plan-avant]' );
		var planPremier = racine.querySelector( '[data-yn-plan-premier]' );
		var planFiltre = racine.querySelector( '[data-yn-plan-filtre]' );
		var planCompte = racine.querySelector( '[data-yn-plan-compte]' );
		var planLignes = racine.querySelector( '[data-yn-plan-lignes]' );
		var planVerifier = racine.querySelector( '[data-yn-plan-verifier]' );
		var natures = {};
		try {
			natures = JSON.parse( ( decoupage && decoupage.getAttribute( 'data-natures' ) ) || '{}' );
		} catch ( err ) {
			natures = { chapitre: 'Chapitre' };
		}
		var lignes = [];
		var decoupagesRapides = {};
		var RAISONS = {
			marqueur: 'Marqueur',
			titre: 'Titre',
			image: 'Illustration',
			saut_page: 'Saut de page',
			separateur: 'Après un séparateur',
			gras: 'En gras',
			centre: 'Centré',
			ligne_courte: 'Ligne courte',
			debut: 'Début du fichier',
		};

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
				recapTome.appendChild( document.createTextNode( ( nomOeuvre ? ' de ' + nomOeuvre : '' ) + ' avec sa couverture et ses liens de téléchargement' ) );
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

		function enAttente() {
			return !! ( attente && ! attente.hidden );
		}

		/**
		 * Version en attente d'un remplacement de lecture en ligne (état renvoyé par le serveur,
		 * textes compris) : bilan, aperçu de chaque chapitre, boutons Remplacer / Annuler.
		 */
		function afficherAttente( etatAttente ) {
			if ( ! attente ) {
				return;
			}
			if ( ! etatAttente ) {
				attente.hidden = true;
				return;
			}
			attenteTexte.textContent = etatAttente.texte;
			attenteListe.textContent = '';
			( etatAttente.chapitres || [] ).forEach( function ( c ) {
				var li = el( 'li' );
				li.appendChild( el( 'span', 'yn-chip yn-chip--' + ( c.action === 'inchange' ? 'info' : ( c.action === 'cree' ? 'ok' : 'warn' ) ), c.etat ) );
				li.appendChild( el( 'span', 'yn-publish__attente-titre', c.titre ) );
				[ [ c.apercu, 'Aperçu' ], [ c.lien, 'Version en ligne' ] ].forEach( function ( l ) {
					if ( ! l[ 0 ] ) {
						return;
					}
					var a = lien( l[ 0 ], l[ 1 ] );
					a.target = '_blank';
					a.rel = 'noopener';
					a.appendChild( el( 'span', 'yn-visually-hidden', ' — ' + c.titre ) );
					li.appendChild( a );
				} );
				attenteListe.appendChild( li );
			} );
			attente.hidden = false;
		}

		function modeCatalogue() {
			return !! ( sansAnnonce && sansAnnonce.checked );
		}

		function majModeAnnonce() {
			var muet = modeCatalogue();
			if ( recapAnnonce ) {
				recapAnnonce.hidden = muet;
			}
			if ( recapNotifications ) {
				recapNotifications.hidden = muet;
			}
			if ( recapCatalogue ) {
				recapCatalogue.hidden = ! muet;
			}
		}

		/**
		 * Valeur par défaut de la case « Ajout au catalogue » : cochée pour un tome déjà
		 * publié, décochée sinon, tant que l'utilisateur ne l'a pas changée lui-même.
		 */
		function defautAnnonce( publie ) {
			if ( ! sansAnnonce || sansAnnonceTouchee ) {
				return;
			}
			sansAnnonce.checked = !! publie;
			majModeAnnonce();
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

		/**
		 * Découpage manuel : ligne du tableau d'un début de chapitre possible.
		 */
		function lignePlan( c, i ) {
			var tr = el( 'tr', 'yn-publish__decoupage-ligne' );
			var id = 'yn-publish-plan-' + i;
			var td = el( 'td', 'yn-publish__decoupage-debut' );
			var coche = el( 'input' );
			coche.type = 'checkbox';
			coche.id = id;
			coche.checked = !! c.auto;
			var etiquette = el( 'label', '', 'Début de chapitre' );
			etiquette.htmlFor = id;
			etiquette.appendChild( el( 'span', 'yn-visually-hidden', ' : ' + c.extrait ) );
			td.appendChild( coche );
			td.appendChild( etiquette );
			var raisons = el( 'span', 'yn-publish__decoupage-raisons' );
			( c.raisons || [] ).forEach( function ( r ) {
				raisons.appendChild( el( 'span', 'yn-chip yn-chip--info', RAISONS[ r ] || r ) );
			} );
			td.appendChild( raisons );
			tr.appendChild( td );

			td = el( 'td' );
			var nature = el( 'select' );
			nature.id = id + '-nature';
			Object.keys( natures ).forEach( function ( cle ) {
				var option = el( 'option', '', natures[ cle ] );
				option.value = cle;
				nature.appendChild( option );
			} );
			nature.value = natures[ c.nature ] ? c.nature : 'chapitre';
			var etiquetteNature = el( 'label', 'yn-visually-hidden', 'Nature — ' + c.extrait );
			etiquetteNature.htmlFor = nature.id;
			td.appendChild( etiquetteNature );
			td.appendChild( nature );
			tr.appendChild( td );

			td = el( 'td' );
			var titre = el( 'input' );
			titre.type = 'text';
			titre.id = id + '-titre';
			titre.maxLength = 200;
			titre.value = c.titre || '';
			titre.placeholder = 'Titre (facultatif)';
			var etiquetteTitre = el( 'label', 'yn-visually-hidden', 'Titre du chapitre — ' + c.extrait );
			etiquetteTitre.htmlFor = titre.id;
			td.appendChild( etiquetteTitre );
			td.appendChild( titre );
			tr.appendChild( td );

			td = el( 'td', 'yn-muted yn-publish__decoupage-extrait' );
			td.appendChild( el( 'span', '', c.extrait ) );
			var avant = el( 'span', 'yn-publish__decoupage-avant', ' · avant le premier chapitre' );
			td.appendChild( avant );
			tr.appendChild( td );

			// La ligne est affichée en grille : les rôles gardent la sémantique du tableau.
			tr.setAttribute( 'role', 'row' );
			Array.prototype.forEach.call( tr.children, function ( cellule ) {
				cellule.setAttribute( 'role', 'cell' );
			} );

			var principal = !! c.auto || ( c.raisons || [] ).some( function ( r ) {
				return [ 'marqueur', 'titre', 'image', 'saut_page', 'debut' ].indexOf( r ) >= 0;
			} );
			return { c: c, tr: tr, coche: coche, nature: nature, titre: titre, avant: avant, principal: principal };
		}

		/**
		 * Découpage manuel : tableau construit après l'analyse du fichier choisi.
		 */
		function construirePlan( rapport ) {
			if ( ! decoupage ) {
				return;
			}
			var candidats = rapport.candidats || [];
			planLignes.textContent = '';
			lignes = [];
			decoupagesRapides = rapport.decoupages || {};
			if ( ! candidats.length ) {
				effacerPlan();
				return;
			}
			var fragment = document.createDocumentFragment();
			candidats.forEach( function ( c, i ) {
				var l = lignePlan( c, i );
				lignes.push( l );
				fragment.appendChild( l.tr );
			} );
			planLignes.appendChild( fragment );
			// Premier numéro : celui du premier chapitre détecté (sinon 1).
			var premier = ( rapport.chapitres || [] ).filter( function ( c ) {
				return c.nature === 'chapitre' && c.numero !== null;
			} )[ 0 ];
			planPremier.value = premier ? String( Math.floor( premier.numero ) ) : '1';
			planActif.checked = false;
			planAvant.checked = false;
			decoupage.hidden = false;
			majPlan();
		}

		function effacerPlan() {
			if ( ! decoupage ) {
				return;
			}
			decoupage.hidden = true;
			planLignes.textContent = '';
			lignes = [];
			planChamp.value = '';
			planActif.checked = false;
		}

		/**
		 * Découpage manuel : débuts cochés (le premier chapitre numéroté porte le premier numéro).
		 */
		function serialiserPlan() {
			var debuts = [];
			var numeroPose = false;
			var premier = planPremier.value.replace( ',', '.' );
			lignes.forEach( function ( l ) {
				if ( ! l.coche.checked ) {
					return;
				}
				var debut = { ancre: l.c.ancre, nature: l.nature.value, titre: l.titre.value.trim() };
				if ( ! numeroPose && debut.nature === 'chapitre' && premier !== '' && ! isNaN( Number( premier ) ) && Number( premier ) >= 0 ) {
					debut.numero = Number( premier );
					numeroPose = true;
				}
				debuts.push( debut );
			} );
			return { debuts: debuts, garder_avant: !! planAvant.checked };
		}

		/**
		 * Découpage manuel : champs de chaque ligne, lignes placées avant le premier début
		 * coché, filtre, nombre de chapitres obtenus et champ caché « plan ».
		 */
		function majPlan() {
			var premierCoche = -1;
			var parNature = {};
			var nb = 0;
			lignes.forEach( function ( l, i ) {
				var coche = l.coche.checked;
				l.nature.disabled = ! coche;
				l.titre.disabled = ! coche;
				l.tr.classList.toggle( 'is-coche', coche );
				if ( coche ) {
					nb++;
					parNature[ l.nature.value ] = ( parNature[ l.nature.value ] || 0 ) + 1;
					if ( premierCoche < 0 ) {
						premierCoche = i;
					}
				}
			} );
			var filtre = planFiltre ? planFiltre.value : 'principaux';
			lignes.forEach( function ( l, i ) {
				var avant = premierCoche >= 0 && i < premierCoche;
				l.tr.classList.toggle( 'is-avant', avant );
				l.avant.hidden = ! avant;
				l.tr.hidden = filtre === 'coches' ? ! l.coche.checked : ( filtre === 'principaux' ? ! ( l.principal || l.coche.checked || avant ) : false );
			} );
			var texte;
			if ( ! nb ) {
				texte = 'Aucun début coché : cochez au moins un début de chapitre (sinon la détection automatique s’applique).';
			} else {
				var numerotes = parNature.chapitre || 0;
				var speciaux = Object.keys( parNature ).filter( function ( n ) {
					return n !== 'chapitre';
				} ).map( function ( n ) {
					return ( parNature[ n ] > 1 ? parNature[ n ] + ' × ' : '' ) + ( natures[ n ] || n ).toLowerCase();
				} );
				var premier = Number( planPremier.value.replace( ',', '.' ) ) || 0;
				texte = nb + ( nb > 1 ? ' chapitres' : ' chapitre' ) + ' avec ce découpage : ' + numerotes + ( numerotes > 1 ? ' chapitres numérotés' : ' chapitre numéroté' )
					+ ( numerotes ? ' (' + numeroFr( premier ) + ( numerotes > 1 ? ' à ' + numeroFr( Math.floor( premier ) + numerotes - 1 ) : '' ) + ')' : '' )
					+ ( speciaux.length ? ' + ' + speciaux.join( ', ' ) : '' ) + '.';
				if ( premierCoche > 0 ) {
					texte += planAvant.checked ? ' Le texte d’ouverture rejoint le premier chapitre.' : ' Le texte d’ouverture (lignes surlignées) ne sera pas publié.';
				}
				texte += planActif.checked ? '' : ' Cochez « Utiliser ce découpage » pour l’appliquer.';
			}
			planCompte.textContent = texte;
			planChamp.value = planActif.checked && nb ? JSON.stringify( serialiserPlan() ) : '';
		}

		/**
		 * Découpages rapides (chaque illustration, chaque saut de page) ou retour à la détection
		 * automatique.
		 */
		function decoupageRapide( action ) {
			if ( action === 'auto' ) {
				lignes.forEach( function ( l ) {
					l.coche.checked = !! l.c.auto;
					l.nature.value = natures[ l.c.nature ] ? l.c.nature : 'chapitre';
					l.titre.value = l.c.titre || '';
				} );
				planActif.checked = false;
				majPlan();
				return;
			}
			var ancres = decoupagesRapides[ action ] || [];
			if ( ! ancres.length ) {
				planCompte.textContent = {
					images: 'Aucune illustration repérée dans le fichier.',
					ouvertures: 'Aucune suite de plusieurs illustrations repérée dans le fichier.',
					sauts: 'Aucun saut de page repéré dans le fichier.',
				}[ action ] || '';
				return;
			}
			lignes.forEach( function ( l ) {
				var debut = ancres.indexOf( l.c.ancre ) >= 0;
				l.coche.checked = debut;
				if ( debut ) {
					l.nature.value = 'chapitre';
					l.titre.value = l.c.raisons.indexOf( 'titre' ) >= 0 || l.c.raisons.indexOf( 'marqueur' ) >= 0 ? l.c.titre || '' : '';
				}
			} );
			planActif.checked = true;
			majPlan();
		}

		function analyser( avecPlan ) {
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
			// Vérification d'un découpage manuel : même fichier, envoyé avec le découpage.
			var essai = avecPlan === true ? serialiserPlan() : null;
			if ( essai ) {
				if ( ! essai.debuts.length ) {
					planCompte.textContent = 'Aucun début coché : cochez au moins un début de chapitre.';
					liste.removeAttribute( 'aria-busy' );
					afficherFiche( fichier.name, ext, taille( fichier.size ), 'ok' );
					return;
				}
				donnees.append( 'plan', JSON.stringify( essai ) );
			}
			requete( rest + 'publications/analyse', donnees, nonce, progres ).then( function ( rapport ) {
				if ( numeroAnalyse !== analyseCourante ) {
					return;
				}
				afficherFiche( fichier.name, ext, taille( fichier.size ) + ' · analysé : ' + rapport.resume, 'ok' );
				afficherChapitres( rapport.chapitres || [] );
				afficherAvertissements( rapport.avertissements || [] );
				majRecap( rapport.chapitres || [] );
				if ( essai ) {
					annoncer( 'Découpage vérifié (rien n’est enregistré) : ' + rapport.resume + '.' + ( planActif.checked ? '' : ' Cochez « Utiliser ce découpage » pour l’appliquer à l’enregistrement.' ), 'succes' );
					return;
				}
				construirePlan( rapport );
				var texte = 'Analyse terminée : ' + rapport.resume + '.';
				if ( rapport.tome_existant && etat ) {
					etat.textContent = 'Tome existant (' + rapport.tome_existant.etat.toLowerCase() + ') : mise à jour';
				}
				defautAnnonce( rapport.tome_existant ? rapport.tome_existant.statut === 'publish' : false );
				if ( rapport.tome_existant ) {
					texte += ' ' + rapport.tome_existant.titre + ' existe déjà (' + rapport.tome_existant.etat.toLowerCase() + ') : il sera mis à jour, ses adresses sont conservées.';
				}
				annoncer( texte, 'succes' );
			} ).catch( function ( erreur ) {
				if ( numeroAnalyse !== analyseCourante ) {
					return;
				}
				if ( ! essai ) {
					effacerPlan();
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
				: ( rapport && rapport.remplacement ? 'Version en attente (rien n’a changé en ligne)' : 'Brouillon enregistré' );
			resultat.appendChild( el( 'span', 'yn-label', titre ) );
			var ul = el( 'ul' );
			var tomeInfo = sortie ? sortie.tome : rapport.tome;
			var li = el( 'li' );
			li.appendChild( lien( tomeInfo.statut === 'publish' ? tomeInfo.lien : tomeInfo.apercu, tomeInfo.statut === 'publish' ? 'Voir le tome' : 'Prévisualiser le tome' ) );
			ul.appendChild( li );
			if ( rapport && rapport.chapitres && rapport.chapitres.length ) {
				var c = rapport.chapitres[ 0 ];
				li = el( 'li' );
				li.appendChild( lien( sortie && sortie.statut === 'publish' ? ( c.lien || tomeInfo.lien ) : c.apercu, 'Lire « ' + c.titre + ' »' ) );
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
				defautAnnonce( false );
				majRecap( null );
				return;
			}
			defautAnnonce( option.getAttribute( 'data-publie' ) === '1' );
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
			if ( etape === 'annuler_remplacement' ) {
				annulerRemplacement();
				return;
			}
			var aVerifier = !! ( sourceAEnvoyer && source.files && source.files.length );
			if ( etape === 'verifier' && ! aVerifier ) {
				annoncer( 'Déposez le nouveau DOCX ou EPUB du tome, puis cliquez sur « Vérifier (sans rien changer en ligne) ».', 'erreur' );
				source.focus();
				return;
			}
			if ( etape === 'remplacer' && ! enAttente() && ! aVerifier ) {
				annoncer( 'Aucun remplacement n’est en attente : déposez le nouveau DOCX ou EPUB, puis cliquez sur « Vérifier (sans rien changer en ligne) ».', 'erreur' );
				return;
			}
			var remplace = etape === 'remplacer' || ( etape === 'publier' && ( enAttente() || ( aVerifier && !! racine.querySelector( '[data-yn-mode-remplacement]' ) ) ) );
			if ( ( etape === 'publier' || etape === 'remplacer' ) && ! window.confirm(
				remplace
					? 'Remplacer maintenant la lecture en ligne par la nouvelle version ? Les lecteurs verront aussitôt les chapitres remplacés (mêmes adresses, commentaires conservés).' + ( modeCatalogue() ? ' Ajout au catalogue : aucune annonce (ni article, ni Discord, ni e-mail).' : '' )
					: ( modeCatalogue()
						? 'Mettre la lecture en ligne maintenant ? Ajout au catalogue : aucune annonce (ni article, ni Discord, ni e-mail).'
						: 'Publier maintenant ? Les chapitres seront en ligne et les lecteurs qui suivent l’œuvre seront prévenus.' )
			) ) {
				return;
			}
			var fenetre = etape === 'apercu' ? window.open( '', '_blank' ) : null;
			var donnees = new FormData( form );
			[ 'action', '_yume_nonce', '_wp_http_referer', 'etape' ].forEach( function ( nom ) {
				donnees.delete( nom );
			} );
			if ( ! sourceAEnvoyer || ! ( source.files && source.files.length ) ) {
				donnees.delete( 'source' );
				donnees.delete( 'plan' );
			} else if ( ! planChamp || ! planChamp.value ) {
				// Détection automatique.
				donnees.delete( 'plan' );
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
				// Le fichier n'est pas conservé : son découpage non plus.
				effacerPlan();
				if ( rapport.import ) {
					afficherFiche( rapport.import.fichier.nom, rapport.import.fichier.format, rapport.import.fichier.taille + ' · importé : ' + rapport.import.resume, 'ok' );
				}
				afficherAvertissements( rapport.avertissements || [] );
				if ( rapport.chapitres && rapport.chapitres.length ) {
					afficherChapitres( rapport.chapitres.map( function ( c ) {
						return { numero: c.numero, nature: c.nature, titre: c.libelle, sous_titre: c.sous_titre, nb_mots: c.nb_mots };
					} ) );
				}
				afficherAttente( rapport.remplacement );
				if ( etat ) {
					etat.textContent = rapport.remplacement
						? 'Version en attente : rien n’a changé en ligne'
						: 'Brouillon enregistré à ' + new Date().toLocaleTimeString( 'fr-FR', { hour: '2-digit', minute: '2-digit' } );
				}
				if ( etape === 'remplacer' && ! rapport.remplacement ) {
					annoncer( 'Aucun remplacement n’est en attente : déposez le nouveau DOCX ou EPUB, puis cliquez sur « Vérifier (sans rien changer en ligne) ».', 'erreur' );
					return null;
				}
				if ( etape === 'apercu' ) {
					// Remplacement en attente : aperçu de la nouvelle version, jamais du chapitre en ligne.
					var versions = rapport.remplacement ? rapport.remplacement.chapitres : null;
					var cible = versions && versions.length ? versions[ 0 ].apercu : ( rapport.chapitres && rapport.chapitres.length ? rapport.chapitres[ 0 ].apercu : rapport.tome.apercu );
					if ( fenetre ) {
						fenetre.location.href = cible;
					} else {
						window.location.href = cible;
					}
					annoncer( 'Brouillon enregistré : l’aperçu du chapitre 1 s’ouvre dans un nouvel onglet.', 'succes', [ [ cible, 'Ouvrir l’aperçu du chapitre 1' ] ] );
					afficherResultat( rapport, null );
					return null;
				}
				if ( etape === 'publier' || etape === 'programmer' || etape === 'remplacer' ) {
					var sortir = function ( confirme ) {
						var sortieDonnees = new FormData();
						sortieDonnees.append( 'quand', etape === 'programmer' ? date.value : 'maintenant' );
						// Toujours explicite : sans ce champ, l'API choisit selon le statut du tome.
						sortieDonnees.append( 'sans_annonce', rapport.sans_annonce ? '1' : '0' );
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
						if ( sortie.remplacement_applique ) {
							afficherAttente( null );
							var titreRemplacement = racine.querySelector( '#yn-publish-remplacer-titre' );
							if ( titreRemplacement && sortie.en_ligne ) {
								titreRemplacement.textContent = 'Remplacer la lecture en ligne (' + sortie.en_ligne + ( sortie.en_ligne > 1 ? ' chapitres actuels)' : ' chapitre actuel)' );
							}
						}
						var dateSortie = new Date( sortie.date ).toLocaleString( 'fr-FR', { dateStyle: 'full', timeStyle: 'short' } );
						var texteSortie;
						if ( sortie.sans_annonce && sortie.remplacement ) {
							texteSortie = sortie.tome.titre + ' : lecture en ligne remplacée (' + sortie.en_ligne + ( sortie.en_ligne > 1 ? ' chapitres' : ' chapitre' ) + ' en ligne'
								+ ( sortie.chapitres > 0 ? ' ; ' + sortie.chapitres + ( sortie.chapitres > 1 ? ' nouveaux' : ' nouveau' ) : '' )
								+ '), sans annonce : ni article, ni Discord, ni e-mail. La date de sortie du tome ne change pas.';
						} else if ( sortie.sans_annonce ) {
							texteSortie = sortie.statut === 'publish'
								? sortie.tome.titre + ' : lecture en ligne ajoutée (' + sortie.chapitres + ( sortie.chapitres > 1 ? ' chapitres' : ' chapitre' ) + '), sans annonce : ni article, ni Discord, ni e-mail.'
								: sortie.tome.titre + ' : lecture en ligne programmée le ' + dateSortie + ', sans annonce (ni article, ni Discord, ni e-mail).';
						} else {
							texteSortie = sortie.statut === 'publish'
								? ( sortie.chapitres > 0
									? sortie.tome.titre + ' est en ligne ! Les chapitres, l’annonce et les notifications sont partis.'
									: sortie.tome.titre + ' est en ligne ! L’annonce et les notifications sont parties ; la lecture en ligne reste à ajouter (Lecture à compléter).' )
								: sortie.tome.titre + ' sortira le ' + dateSortie + '.';
						}
						if ( etat ) {
							etat.textContent = sortie.sans_annonce
								? ( sortie.remplacement ? 'Lecture en ligne remplacée' : ( sortie.statut === 'publish' ? 'Lecture en ligne ajoutée' : 'Lecture en ligne programmée' ) )
								: ( sortie.statut === 'publish' ? 'Tome publié' : 'Sortie programmée' );
						}
						annoncer(
							texteSortie,
							'succes',
							[ [ sortie.statut === 'publish' ? sortie.tome.lien : sortie.tome.apercu, sortie.statut === 'publish' ? 'Voir le tome' : 'Prévisualiser le tome' ] ]
						);
						afficherResultat( rapportCourant, sortie );
					} );
				}
				if ( rapport.remplacement && ( etape === 'verifier' || rapport.import ) ) {
					annoncer( rapport.remplacement.message, 'succes' );
					afficherResultat( rapport, null );
					if ( attente && attente.scrollIntoView ) {
						attente.scrollIntoView( { block: 'nearest' } );
					}
					return null;
				}
				if ( etape === 'verifier' ) {
					annoncer( 'Déposez le nouveau DOCX ou EPUB du tome, puis cliquez sur « Vérifier (sans rien changer en ligne) ».', 'erreur' );
					return null;
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

		/**
		 * « Annuler le remplacement » : versions en attente et images supprimées, rien ne change
		 * en ligne.
		 */
		function annulerRemplacement() {
			if ( ! tome.value || ! window.confirm( 'Annuler le remplacement ? La version en attente et ses images seront supprimées ; la lecture en ligne ne change pas.' ) ) {
				return;
			}
			occupe( true );
			requete( rest + 'publications/' + tome.value + '/remplacement', null, nonce, null, 'DELETE' ).then( function ( reponse ) {
				afficherAttente( null );
				resultat.hidden = true;
				if ( etat ) {
					etat.textContent = 'Tome publié';
				}
				annoncer( reponse.message, 'succes' );
			} ).catch( function ( erreur ) {
				annoncer( erreur.message, 'erreur' );
			} ).finally( function () {
				occupe( false );
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

		source.addEventListener( 'change', function () {
			analyser( false );
		} );
		if ( decoupage ) {
			// Toute modification du tableau active le découpage manuel.
			var modifierPlan = function ( e ) {
				var cible = e.target;
				if ( cible !== planActif && cible !== planFiltre && cible !== planPremier && cible !== planAvant && cible.closest && cible.closest( '[data-yn-plan-lignes]' ) ) {
					planActif.checked = true;
				}
				majPlan();
			};
			decoupage.addEventListener( 'change', modifierPlan );
			decoupage.addEventListener( 'input', function ( e ) {
				if ( e.target.type === 'text' || e.target.type === 'number' ) {
					modifierPlan( e );
				}
			} );
			Array.prototype.forEach.call( decoupage.querySelectorAll( '[data-yn-plan-action]' ), function ( bouton ) {
				bouton.addEventListener( 'click', function () {
					decoupageRapide( bouton.getAttribute( 'data-yn-plan-action' ) );
				} );
			} );
			if ( planVerifier ) {
				planVerifier.addEventListener( 'click', function () {
					if ( ! ( source.files && source.files.length ) ) {
						annoncer( 'Choisissez à nouveau le fichier du tome : il n’est pas conservé sur le serveur.', 'erreur' );
						return;
					}
					analyser( true );
				} );
			}
		}
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
				effacerPlan();
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
		if ( sansAnnonce ) {
			sansAnnonce.addEventListener( 'change', function () {
				sansAnnonceTouchee = true;
				majModeAnnonce();
			} );
			majModeAnnonce();
		}
		racine.addEventListener( 'click', function ( e ) {
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
