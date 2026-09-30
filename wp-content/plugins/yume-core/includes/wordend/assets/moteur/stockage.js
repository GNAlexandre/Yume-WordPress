/**
 * WordEnd — moteur : stockage local (localStorage['yn.wordend'], try/catch partout).
 *
 * Lot 0 : format v1 conservé ({meilleur, parties, maj, volume, muet}, contrat §14) ; la
 * progression est reconstruite à partir de ce format. Le lot A passe au format v2 (par univers)
 * avec migrer(). Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var CLE = 'yn.wordend';

	function lire() {
		try {
			var donnees = JSON.parse( window.localStorage.getItem( CLE ) || 'null' );
			if ( donnees && typeof donnees === 'object' ) {
				return donnees;
			}
		} catch ( e ) {}
		return {};
	}

	/* Fusionne des champs dans la clé (score et son la partagent). */
	function fusionner( champs ) {
		try {
			var donnees = lire();
			Object.keys( champs ).forEach( function ( cle ) {
				donnees[ cle ] = champs[ cle ];
			} );
			window.localStorage.setItem( CLE, JSON.stringify( donnees ) );
		} catch ( e ) {}
	}

	function entier( valeur ) {
		return Math.max( 0, parseInt( valeur, 10 ) || 0 );
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

	/* Progression d'un univers : structure toujours complète. */
	function progression( universSlug ) {
		var donnees = lire();
		return {
			parties: entier( donnees.parties ),
			arcade: { meilleur: entier( donnees.meilleur ) },
			niveaux: {},
			personnage: '',
			debloques: { niveaux: [], personnages: [] },
		};
	}

	/* Lot 0 : seul le mode arcade est enregistré (format v1 : meilleur, parties, maj). */
	function enregistrerResultat( universSlug, niveauSlug, resultat ) {
		if ( niveauSlug !== 'arcade' ) {
			return;
		}
		var donnees = lire();
		fusionner( {
			meilleur: Math.max( entier( donnees.meilleur ), entier( resultat && resultat.score ) ),
			parties: entier( donnees.parties ) + 1,
			maj: new Date().toISOString().slice( 0, 10 ),
		} );
	}

	/* Lot 0 : sans effet (lot A : mémorisé par univers). */
	function choisirPersonnage() {}

	/* Lot 0 : format v1 inchangé (lot A : migration v1 → v2, idempotente). */
	function migrer() {
		return lire();
	}

	ynWE.stockage = {
		CLE: CLE,
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
