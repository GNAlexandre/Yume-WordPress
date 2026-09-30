/**
 * WordEnd — moteur : ressources (images, JSON, univers, personnages).
 *
 * chargerUnivers({manifeste, version}) lit le manifeste d'un univers et charge : le premier
 * personnage de la liste (les autres à la demande, chargerPersonnage), tous les ennemis
 * (obligatoires), le niveau par défaut (« arcade » s'il est listé, sinon le premier ; les autres
 * à la demande, chargerNiveau/chargerNiveaux : un fichier absent est ignoré avec un
 * avertissement), les décors (échec toléré : null) et l'adresse des musiques. Chaque adresse
 * reçoit ?ver=<version>.
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;

	function avertir( message ) {
		// eslint-disable-next-line no-console
		console.warn( '[WordEnd] ' + message );
	}

	function chargerImage( url ) {
		return new Promise( function ( resoudre, rejeter ) {
			var img = new Image();
			img.onload = function () {
				resoudre( img );
			};
			img.onerror = function () {
				rejeter( new Error( 'image introuvable : ' + url ) );
			};
			img.src = url;
		} );
	}

	function chargerJson( url ) {
		return window.fetch( url, { credentials: 'same-origin' } ).then( function ( reponse ) {
			if ( ! reponse.ok ) {
				throw new Error( 'fichier introuvable : ' + url );
			}
			return reponse.json();
		} );
	}

	/* Adresse d'un fichier de l'univers (chemin relatif au manifeste), versionnée. */
	function urlRelative( univers, chemin ) {
		return univers.base + chemin + ( univers.ver ? '?ver=' + encodeURIComponent( univers.ver ) : '' );
	}

	function dossierDe( chemin ) {
		var i = chemin.lastIndexOf( '/' );
		return i === -1 ? '' : chemin.slice( 0, i + 1 );
	}

	/* Slug d'une entrée du manifeste : nom du fichier sans « .json » (= champ slug du JSON). */
	function slugDe( chemin ) {
		return chemin.slice( chemin.lastIndexOf( '/' ) + 1 ).replace( /\.json$/, '' );
	}

	/*
	 * Personnage ou ennemi : JSON puis sa planche (<planche>.png et <planche>.planche.json dans
	 * le même dossier). Le champ planche (nom) devient {nom, image, meta}.
	 */
	function chargerEntite( univers, chemin ) {
		return chargerJson( urlRelative( univers, chemin ) ).then( function ( json ) {
			var nom = String( json.planche || slugDe( chemin ) );
			var dossier = dossierDe( chemin );
			return Promise.all( [
				chargerImage( urlRelative( univers, dossier + nom + '.png' ) ),
				chargerJson( urlRelative( univers, dossier + nom + '.planche.json' ) ),
			] ).then( function ( planche ) {
				json.planche = { nom: nom, image: planche[ 0 ], meta: planche[ 1 ] };
				json.slug = json.slug || slugDe( chemin );
				return json;
			} );
		} );
	}

	function chargerPersonnage( univers, slug ) {
		if ( univers.personnages[ slug ] ) {
			return Promise.resolve( univers.personnages[ slug ] );
		}
		var chemin = univers.chemins.personnages[ slug ];
		if ( ! chemin ) {
			return Promise.reject( new Error( 'personnage inconnu : ' + slug ) );
		}
		return chargerEntite( univers, chemin ).then( function ( personnage ) {
			univers.personnages[ slug ] = personnage;
			return personnage;
		} );
	}

	/* Niveau (JSON) par son slug ; mis en cache dans univers.niveaux. */
	function chargerNiveau( univers, slug ) {
		if ( univers.niveaux[ slug ] ) {
			return Promise.resolve( univers.niveaux[ slug ] );
		}
		var chemin = univers.chemins.niveaux[ slug ];
		if ( ! chemin ) {
			return Promise.reject( new Error( 'niveau inconnu : ' + slug ) );
		}
		return chargerJson( urlRelative( univers, chemin ) ).then( function ( niveau ) {
			niveau.slug = niveau.slug || slug;
			univers.niveaux[ slug ] = niveau;
			return niveau;
		} );
	}

	/*
	 * Tous les niveaux du manifeste (écran de sélection) : un fichier absent est ignoré avec un
	 * avertissement et retiré de univers.ordre.niveaux. Résolue avec univers.niveaux.
	 */
	function chargerNiveaux( univers ) {
		return Promise.all( univers.ordre.niveaux.map( function ( slug ) {
			return chargerNiveau( univers, slug ).then( null, function () {
				avertir( 'niveau ignoré (fichier absent) : ' + univers.chemins.niveaux[ slug ] );
				return null;
			} );
		} ) ).then( function () {
			univers.ordre.niveaux = univers.ordre.niveaux.filter( function ( slug ) {
				return !! univers.niveaux[ slug ];
			} );
			return univers.niveaux;
		} );
	}

	/**
	 * Charge un univers.
	 *
	 * @param {Object} entree {manifeste: URL du manifeste.json, version}.
	 * @return {Promise<Object>} univers.
	 */
	function chargerUnivers( entree ) {
		var adresse = String( entree.manifeste || '' );
		var sansRequete = adresse.split( '#' )[ 0 ].split( '?' )[ 0 ];
		var univers = {
			slug: entree.slug || '',
			manifeste: null,
			base: sansRequete.slice( 0, sansRequete.lastIndexOf( '/' ) + 1 ),
			ver: entree.version ? String( entree.version ) : '',
			personnages: {},
			ennemis: {},
			niveaux: {},
			decors: {},
			musiques: {},
			ordre: { personnages: [], niveaux: [] },
			chemins: { personnages: {}, niveaux: {} },
		};
		var url = adresse.indexOf( '?' ) === -1 && univers.ver ? adresse + '?ver=' + encodeURIComponent( univers.ver ) : adresse;
		return chargerJson( url ).then( function ( manifeste ) {
			univers.manifeste = manifeste;
			univers.slug = manifeste.slug || univers.slug;
			( manifeste.personnages || [] ).forEach( function ( chemin ) {
				var slug = slugDe( chemin );
				univers.ordre.personnages.push( slug );
				univers.chemins.personnages[ slug ] = chemin;
			} );
			( manifeste.niveaux || [] ).forEach( function ( chemin ) {
				var slug = slugDe( chemin );
				univers.ordre.niveaux.push( slug );
				univers.chemins.niveaux[ slug ] = chemin;
			} );
			Object.keys( manifeste.musiques || {} ).forEach( function ( nom ) {
				var musique = manifeste.musiques[ nom ];
				univers.musiques[ nom ] = { url: urlRelative( univers, musique.fichier ), credit: musique.credit || '' };
			} );

			var taches = [];
			// Personnage par défaut (le premier) : obligatoire.
			if ( ! univers.ordre.personnages.length ) {
				throw new Error( 'aucun personnage dans le manifeste' );
			}
			taches.push( chargerPersonnage( univers, univers.ordre.personnages[ 0 ] ) );
			// Ennemis : obligatoires.
			( manifeste.ennemis || [] ).forEach( function ( chemin ) {
				taches.push( chargerEntite( univers, chemin ).then( function ( ennemi ) {
					univers.ennemis[ ennemi.slug ] = ennemi;
				} ) );
			} );
			// Niveau par défaut : obligatoire (les autres à la demande).
			if ( ! univers.ordre.niveaux.length ) {
				throw new Error( 'aucun niveau dans le manifeste' );
			}
			taches.push( chargerNiveau( univers, univers.chemins.niveaux.arcade ? 'arcade' : univers.ordre.niveaux[ 0 ] ) );
			// Décors : facultatifs (sans image, le ciel dégradé du thème les remplace).
			Object.keys( manifeste.decors || {} ).forEach( function ( nom ) {
				taches.push( chargerImage( urlRelative( univers, manifeste.decors[ nom ].image ) ).then(
					function ( image ) {
						univers.decors[ nom ] = image;
					},
					function () {
						avertir( 'décor ignoré : ' + nom );
						univers.decors[ nom ] = null;
					}
				) );
			} );
			return Promise.all( taches ).then( function () {
				return univers;
			} );
		} );
	}

	ynWE.ressources = {
		chargerImage: chargerImage,
		chargerJson: chargerJson,
		chargerUnivers: chargerUnivers,
		chargerPersonnage: chargerPersonnage,
		chargerNiveau: chargerNiveau,
		chargerNiveaux: chargerNiveaux,
		urlRelative: urlRelative,
	};
}() );
