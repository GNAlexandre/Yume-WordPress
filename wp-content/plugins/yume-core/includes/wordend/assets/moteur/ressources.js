/**
 * WordEnd — moteur : ressources (images, JSON, univers, personnages, niveaux, décors).
 *
 * chargerUnivers({manifeste, version}) lit le manifeste d'un univers et charge : le premier
 * personnage de la liste (les autres à la demande : chargerPersonnage, chargerPersonnages), tous
 * les ennemis (obligatoires), le niveau par défaut (« arcade » s'il est listé, sinon le premier)
 * avec son décor (les autres niveaux à la demande : chargerNiveau, chargerNiveaux ; un fichier
 * absent est ignoré avec un avertissement), et l'adresse des musiques (le fichier n'est lu qu'à
 * la lecture). Un décor n'est chargé qu'avec un niveau qui l'utilise ; échec toléré : null.
 * Chaque adresse reçoit ?ver=<version>.
 *
 * Teinte : « teinte » (filtre CSS, ex. "hue-rotate(40deg)") d'une entité ou d'un type d'ennemi
 * est appliquée une seule fois sur un canvas hors écran, qui remplace l'image de la planche.
 * Interface : docs/wordend-formats.md §3.3.
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

	function verifierSlug( json, slug, chemin ) {
		if ( json.slug && json.slug !== slug ) {
			avertir( 'slug « ' + json.slug + ' » différent du nom du fichier : ' + chemin );
		}
		json.slug = json.slug || slug;
	}

	/* ------------------------------------------------------------------ */
	/* Planches et teintes                                                 */
	/* ------------------------------------------------------------------ */

	/*
	 * Copie teintée d'une image (canvas hors écran passé par ctx.filter), calculée une fois par
	 * couple (image, filtre). Navigateur sans ctx.filter ou filtre invalide : image d'origine.
	 */
	var teintes = new WeakMap();
	function teinter( image, filtre ) {
		filtre = String( filtre || '' ).trim();
		if ( ! image || ! filtre || filtre === 'none' ) {
			return image;
		}
		var cache = teintes.get( image ) || {};
		if ( cache[ filtre ] ) {
			return cache[ filtre ];
		}
		var toile = document.createElement( 'canvas' );
		toile.width = image.naturalWidth || image.width;
		toile.height = image.naturalHeight || image.height;
		var ctx = toile.getContext( '2d' );
		if ( ! ctx || ! ( 'filter' in ctx ) ) {
			return image;
		}
		ctx.filter = filtre;
		if ( ! ctx.filter || ctx.filter === 'none' ) {
			avertir( 'teinte ignorée (filtre invalide) : ' + filtre );
			return image;
		}
		ctx.drawImage( image, 0, 0 );
		cache[ filtre ] = toile;
		teintes.set( image, cache );
		return toile;
	}

	/* Planche <dossier><nom>.png + <dossier><nom>.planche.json → {nom, image, meta}. */
	function chargerPlanche( univers, dossier, nom ) {
		return Promise.all( [
			chargerImage( urlRelative( univers, dossier + nom + '.png' ) ),
			chargerJson( urlRelative( univers, dossier + nom + '.planche.json' ) ),
		] ).then( function ( resultats ) {
			return { nom: nom, image: resultats[ 0 ], meta: resultats[ 1 ] };
		} );
	}

	/*
	 * Planches des types d'ennemis : chaque types.<t>.planche devient {nom, image, meta} — la
	 * planche propre au type (champ « planche » du type ; introuvable : avertissement et planche
	 * de l'ennemi), sinon celle de l'ennemi (même objet) ; copie teintée si le type a « teinte ».
	 */
	function chargerPlanchesTypes( univers, json, dossier ) {
		var types = json.types && typeof json.types === 'object' ? json.types : {};
		return Promise.all( Object.keys( types ).map( function ( t ) {
			var type = types[ t ] || {};
			var base = Promise.resolve( json.planche );
			if ( typeof type.planche === 'string' && type.planche && type.planche !== json.planche.nom ) {
				base = chargerPlanche( univers, dossier, type.planche ).then( null, function () {
					avertir( 'planche du type ' + json.slug + '.' + t + ' introuvable : ' + type.planche );
					return json.planche;
				} );
			}
			return base.then( function ( planche ) {
				if ( type.teinte ) {
					planche = { nom: planche.nom, image: teinter( planche.image, type.teinte ), meta: planche.meta, teinte: String( type.teinte ) };
				}
				type.planche = planche;
			} );
		} ) );
	}

	/*
	 * Personnage ou ennemi : JSON puis sa planche (<planche>.png et <planche>.planche.json dans
	 * le même dossier). Le champ planche (nom) devient {nom, image, meta} ; la « teinte » de
	 * l'entité remplace l'image par sa copie teintée ; types.<t>.planche (ennemis) est résolu.
	 */
	function chargerEntite( univers, chemin ) {
		return chargerJson( urlRelative( univers, chemin ) ).then( function ( json ) {
			var dossier = dossierDe( chemin );
			verifierSlug( json, slugDe( chemin ), chemin );
			return chargerPlanche( univers, dossier, String( json.planche || json.slug ) ).then( function ( planche ) {
				if ( json.teinte ) {
					planche.image = teinter( planche.image, json.teinte );
					planche.teinte = String( json.teinte );
				}
				json.planche = planche;
				return json.types ? chargerPlanchesTypes( univers, json, dossier ) : null;
			} ).then( function () {
				return json;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Personnages                                                         */
	/* ------------------------------------------------------------------ */

	/* Personnage par son slug ; mis en cache dans univers.personnages (promesse partagée). */
	var enCours = new WeakMap();
	function promesseUnique( univers, cle, fabrique ) {
		var table = enCours.get( univers ) || {};
		enCours.set( univers, table );
		if ( ! table[ cle ] ) {
			table[ cle ] = fabrique().then(
				function ( valeur ) {
					delete table[ cle ];
					return valeur;
				},
				function ( erreur ) {
					delete table[ cle ];
					throw erreur;
				}
			);
		}
		return table[ cle ];
	}

	function chargerPersonnage( univers, slug ) {
		if ( univers.personnages[ slug ] ) {
			return Promise.resolve( univers.personnages[ slug ] );
		}
		var chemin = univers.chemins.personnages[ slug ];
		if ( ! chemin ) {
			return Promise.reject( new Error( 'personnage inconnu : ' + slug ) );
		}
		return promesseUnique( univers, 'personnage:' + slug, function () {
			return chargerEntite( univers, chemin ).then( function ( personnage ) {
				univers.personnages[ slug ] = personnage;
				return personnage;
			} );
		} );
	}

	/*
	 * Tous les personnages du manifeste (écran de sélection ; ajout lot A) : un fichier absent est
	 * ignoré avec un avertissement et retiré de univers.ordre.personnages. Résolue avec
	 * univers.personnages.
	 */
	function chargerPersonnages( univers ) {
		return Promise.all( univers.ordre.personnages.map( function ( slug ) {
			return chargerPersonnage( univers, slug ).then( null, function () {
				avertir( 'personnage ignoré (fichier absent) : ' + univers.chemins.personnages[ slug ] );
				return null;
			} );
		} ) ).then( function () {
			univers.ordre.personnages = univers.ordre.personnages.filter( function ( slug ) {
				return !! univers.personnages[ slug ];
			} );
			return univers.personnages;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Décors et niveaux                                                   */
	/* ------------------------------------------------------------------ */

	/*
	 * Décor du manifeste par son nom (ajout lot A) : univers.decors[nom] = Image, ou null si
	 * l'image manque (avertissement ; le rendu dessine alors le ciel du thème). Une seule fois.
	 */
	function chargerDecor( univers, nom ) {
		var decors = ( univers.manifeste && univers.manifeste.decors ) || {};
		if ( ! nom || ! decors[ nom ] ) {
			return Promise.resolve( null );
		}
		if ( Object.prototype.hasOwnProperty.call( univers.decors, nom ) ) {
			return Promise.resolve( univers.decors[ nom ] );
		}
		return promesseUnique( univers, 'decor:' + nom, function () {
			return chargerImage( urlRelative( univers, decors[ nom ].image ) ).then( null, function () {
				avertir( 'décor ignoré : ' + nom );
				return null;
			} ).then( function ( image ) {
				univers.decors[ nom ] = image;
				return image;
			} );
		} );
	}

	/* JSON d'un niveau (sans son décor), mis en cache dans univers.niveaux. */
	function chargerJsonNiveau( univers, slug ) {
		if ( univers.niveaux[ slug ] ) {
			return Promise.resolve( univers.niveaux[ slug ] );
		}
		var chemin = univers.chemins.niveaux[ slug ];
		if ( ! chemin ) {
			return Promise.reject( new Error( 'niveau inconnu : ' + slug ) );
		}
		return promesseUnique( univers, 'niveau:' + slug, function () {
			return chargerJson( urlRelative( univers, chemin ) ).then( function ( niveau ) {
				verifierSlug( niveau, slug, chemin );
				univers.niveaux[ slug ] = niveau;
				return niveau;
			} );
		} );
	}

	/* Niveau (JSON) par son slug, avec son décor ; mis en cache dans univers.niveaux. */
	function chargerNiveau( univers, slug ) {
		return chargerJsonNiveau( univers, slug ).then( function ( niveau ) {
			return chargerDecor( univers, niveau.decor ).then( function () {
				return niveau;
			} );
		} );
	}

	/*
	 * Tous les niveaux du manifeste (écran de sélection, sans leurs décors) : un fichier absent est
	 * ignoré avec un avertissement et retiré de univers.ordre.niveaux. Résolue avec univers.niveaux.
	 */
	function chargerNiveaux( univers ) {
		return Promise.all( univers.ordre.niveaux.map( function ( slug ) {
			return chargerJsonNiveau( univers, slug ).then( null, function () {
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

	/* ------------------------------------------------------------------ */
	/* Univers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Charge un univers.
	 *
	 * @param {Object} entree {manifeste: URL du manifeste.json, version, slug?}.
	 * @return {Promise<Object>} univers.
	 */
	function chargerUnivers( entree ) {
		entree = entree || {};
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
		if ( ! adresse ) {
			return Promise.reject( new Error( 'manifeste absent' ) );
		}
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
				var musique = manifeste.musiques[ nom ] || {};
				if ( musique.fichier ) {
					univers.musiques[ nom ] = { url: urlRelative( univers, musique.fichier ), credit: musique.credit || '' };
				}
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
			// Niveau par défaut et son décor : obligatoire (les autres à la demande).
			if ( ! univers.ordre.niveaux.length ) {
				throw new Error( 'aucun niveau dans le manifeste' );
			}
			taches.push( chargerNiveau( univers, univers.chemins.niveaux.arcade ? 'arcade' : univers.ordre.niveaux[ 0 ] ) );
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
		chargerPersonnages: chargerPersonnages,
		chargerNiveau: chargerNiveau,
		chargerNiveaux: chargerNiveaux,
		chargerDecor: chargerDecor,
		teinter: teinter,
		urlRelative: urlRelative,
	};
}() );
