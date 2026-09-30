/**
 * WordEnd — moteur : stockage local (localStorage['yn.wordend'], try/catch partout).
 *
 * Format v2 (par univers) :
 *   { version: 2, volume: 0…1, muet, maj: 'AAAA-MM-JJ',
 *     univers: { <slug>: { parties, personnage, arcade: {meilleur},
 *                          niveaux: { <slug>: {meilleur, etoiles, fini} } } } }
 * Le format v1 ({meilleur, parties, maj, volume, muet}, contrat §14) est migré à la lecture
 * (migrer(), idempotent, sans perte : le record v1 devient celui de l'arcade de « sukasuka »).
 * Interface : docs/wordend-formats.md §3.2 et §6.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var CLE = 'yn.wordend';
	var VERSION = 2;
	/* Univers qui reçoit le record et le nombre de parties du format v1 (seul univers de la v1). */
	var UNIVERS_V1 = 'sukasuka';

	function estObjet( valeur ) {
		return !! valeur && typeof valeur === 'object' && ! Array.isArray( valeur );
	}

	function entier( valeur ) {
		return Math.max( 0, parseInt( valeur, 10 ) || 0 );
	}

	function aujourdhui() {
		return new Date().toISOString().slice( 0, 10 );
	}

	/* Contenu brut de la clé (objet, {} si absent ou illisible). */
	function lireBrut() {
		try {
			var donnees = JSON.parse( window.localStorage.getItem( CLE ) || 'null' );
			if ( estObjet( donnees ) ) {
				return donnees;
			}
		} catch ( e ) {}
		return {};
	}

	function ecrire( donnees ) {
		try {
			window.localStorage.setItem( CLE, JSON.stringify( donnees ) );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	/* Normalise l'entrée d'un univers (structure complète, valeurs bornées). */
	function normaliserUnivers( u ) {
		u = estObjet( u ) ? u : {};
		u.parties = entier( u.parties );
		u.personnage = typeof u.personnage === 'string' ? u.personnage : '';
		u.arcade = estObjet( u.arcade ) ? u.arcade : {};
		u.arcade.meilleur = entier( u.arcade.meilleur );
		u.niveaux = estObjet( u.niveaux ) ? u.niveaux : {};
		Object.keys( u.niveaux ).forEach( function ( slug ) {
			var n = estObjet( u.niveaux[ slug ] ) ? u.niveaux[ slug ] : {};
			n.meilleur = entier( n.meilleur );
			n.etoiles = Math.min( 3, entier( n.etoiles ) );
			n.fini = n.fini === true;
			u.niveaux[ slug ] = n;
		} );
		return u;
	}

	/*
	 * Convertit un objet brut au format v2 (sans écrire). Idempotent : un objet déjà en v2 est
	 * seulement normalisé. Les champs inconnus de la racine sont conservés.
	 */
	function versV2( donnees ) {
		donnees = estObjet( donnees ) ? donnees : {};
		if ( typeof donnees.version === 'number' && donnees.version > VERSION ) {
			return donnees; // Format futur : laissé tel quel.
		}
		if ( donnees.version !== VERSION ) {
			var ancien = donnees;
			donnees = {};
			Object.keys( ancien ).forEach( function ( cle ) {
				if ( cle !== 'meilleur' && cle !== 'parties' ) {
					donnees[ cle ] = ancien[ cle ];
				}
			} );
			donnees.univers = estObjet( ancien.univers ) ? ancien.univers : {};
			if ( 'meilleur' in ancien || 'parties' in ancien ) {
				var u = normaliserUnivers( donnees.univers[ UNIVERS_V1 ] );
				u.arcade.meilleur = Math.max( u.arcade.meilleur, entier( ancien.meilleur ) );
				u.parties += entier( ancien.parties );
				donnees.univers[ UNIVERS_V1 ] = u;
			}
			donnees.version = VERSION;
		}
		donnees.univers = estObjet( donnees.univers ) ? donnees.univers : {};
		Object.keys( donnees.univers ).forEach( function ( slug ) {
			donnees.univers[ slug ] = normaliserUnivers( donnees.univers[ slug ] );
		} );
		return donnees;
	}

	/*
	 * Migration v1 → v2 : réécrit la clé si elle existe et n'est pas encore en v2. Idempotente.
	 * Renvoie les données en v2.
	 */
	function migrer() {
		var brut = lireBrut();
		var aMigrer = Object.keys( brut ).length > 0 && brut.version !== VERSION && ! ( brut.version > VERSION );
		var donnees = versV2( brut );
		if ( aMigrer ) {
			ecrire( donnees );
		}
		return donnees;
	}

	/* Données au format v2 (migrées à la lecture). */
	function lire() {
		return migrer();
	}

	/* Fusionne des champs à la racine (réglages du son, date). */
	function fusionner( champs ) {
		var donnees = lire();
		Object.keys( champs || {} ).forEach( function ( cle ) {
			donnees[ cle ] = champs[ cle ];
		} );
		ecrire( donnees );
	}

	function reglagesSon() {
		var donnees = lire();
		var volume = parseFloat( donnees.volume );
		return {
			volume: isFinite( volume ) ? Math.min( 1, Math.max( 0, volume ) ) : 0.5,
			muet: donnees.muet === true,
		};
	}

	function enregistrerSon( reglages ) {
		fusionner( { volume: reglages.volume, muet: !! reglages.muet } );
	}

	/* Modifie l'entrée d'un univers (fn reçoit l'entrée normalisée) puis écrit. */
	function modifierUnivers( universSlug, fn ) {
		var slug = String( universSlug || '' );
		if ( ! slug ) {
			return;
		}
		var donnees = lire();
		if ( donnees.version !== VERSION ) {
			return; // Format futur : pas d'écriture.
		}
		var u = normaliserUnivers( donnees.univers[ slug ] );
		fn( u );
		donnees.univers[ slug ] = u;
		donnees.maj = aujourdhui();
		ecrire( donnees );
	}

	/* Slugs des niveaux de l'univers chargé (hors arcade), par numéro puis ordre du manifeste. */
	function niveauxOrdonnes( univers ) {
		var ordre = ( univers.ordre && univers.ordre.niveaux ) || [];
		var niveaux = univers.niveaux || {};
		var liste = ordre.filter( function ( slug ) {
			return slug !== 'arcade';
		} ).map( function ( slug, i ) {
			var n = niveaux[ slug ];
			return { slug: slug, cle: n && typeof n.numero === 'number' ? n.numero : 1000 + i, i: i };
		} );
		liste.sort( function ( a, b ) {
			return a.cle - b.cle || a.i - b.i;
		} );
		return liste.map( function ( n ) {
			return n.slug;
		} );
	}

	/*
	 * Éléments débloqués. Sans l'univers chargé : l'arcade et les niveaux finis. Avec lui : le
	 * niveau n est débloqué quand n − 1 est fini (le premier toujours) ; un personnage l'est si
	 * c'est le premier, si son JSON chargé porte « debloque: true » (ou n'a pas le champ), ou
	 * si le niveau de « debloque: {niveau} » est fini. Un personnage non chargé n'est listé que
	 * s'il est le premier.
	 */
	function debloques( u, univers ) {
		var niveaux = [ 'arcade' ];
		var personnages = [];
		function fini( slug ) {
			return !! ( u.niveaux[ slug ] && u.niveaux[ slug ].fini );
		}
		if ( univers && univers.ordre ) {
			niveauxOrdonnes( univers ).forEach( function ( slug, i, liste ) {
				if ( i === 0 || fini( liste[ i - 1 ] ) || fini( slug ) ) {
					niveaux.push( slug );
				}
			} );
			( univers.ordre.personnages || [] ).forEach( function ( slug, i ) {
				var p = univers.personnages && univers.personnages[ slug ];
				var regle = p ? p.debloque : undefined;
				var ok = i === 0 ||
					( p && ( regle === undefined || regle === true ) ) ||
					( estObjet( regle ) && fini( regle.niveau ) );
				if ( ok ) {
					personnages.push( slug );
				}
			} );
		} else {
			Object.keys( u.niveaux ).forEach( function ( slug ) {
				if ( fini( slug ) && niveaux.indexOf( slug ) === -1 ) {
					niveaux.push( slug );
				}
			} );
		}
		return { niveaux: niveaux, personnages: personnages };
	}

	/**
	 * Progression d'un univers : structure toujours complète (copie, modifiable sans effet).
	 *
	 * @param {string} universSlug Slug de l'univers.
	 * @param {Object} univers     Facultatif : univers chargé (ressources), pour les déblocages.
	 * @return {Object} {parties, arcade: {meilleur}, niveaux, personnage, debloques}
	 */
	function progression( universSlug, univers ) {
		var donnees = lire();
		var brut = estObjet( donnees.univers ) ? donnees.univers[ String( universSlug || '' ) ] : null;
		var u = normaliserUnivers( JSON.parse( JSON.stringify( brut || {} ) ) );
		return {
			parties: u.parties,
			arcade: { meilleur: u.arcade.meilleur },
			niveaux: u.niveaux,
			personnage: u.personnage,
			debloques: debloques( u, univers ),
		};
	}

	/*
	 * Résultat d'une partie : parties + 1 ; arcade : meilleur score ; niveau : meilleur score,
	 * maximum d'étoiles, « fini » ne redevient jamais faux.
	 */
	function enregistrerResultat( universSlug, niveauSlug, resultat ) {
		resultat = resultat || {};
		modifierUnivers( universSlug, function ( u ) {
			var score = entier( resultat.score );
			u.parties += 1;
			if ( niveauSlug === 'arcade' ) {
				u.arcade.meilleur = Math.max( u.arcade.meilleur, score );
				return;
			}
			var slug = String( niveauSlug || '' );
			if ( ! slug ) {
				return;
			}
			var n = u.niveaux[ slug ] || { meilleur: 0, etoiles: 0, fini: false }; // Déjà normalisé.
			n.meilleur = Math.max( n.meilleur, score );
			n.etoiles = Math.max( n.etoiles, Math.min( 3, entier( resultat.etoiles ) ) );
			n.fini = n.fini || resultat.fini === true;
			u.niveaux[ slug ] = n;
		} );
	}

	/* Mémorise le dernier personnage choisi dans un univers. */
	function choisirPersonnage( universSlug, slug ) {
		modifierUnivers( universSlug, function ( u ) {
			u.personnage = String( slug || '' );
		} );
	}

	ynWE.stockage = {
		CLE: CLE,
		VERSION: VERSION,
		lire: lire,
		fusionner: fusionner,
		reglagesSon: reglagesSon,
		enregistrerSon: enregistrerSon,
		progression: progression,
		enregistrerResultat: enregistrerResultat,
		choisirPersonnage: choisirPersonnage,
		migrer: migrer,
	};
}() );
