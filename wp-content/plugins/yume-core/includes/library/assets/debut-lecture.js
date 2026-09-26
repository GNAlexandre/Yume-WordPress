/**
 * Boutons « Commencer la lecture » / « Lire en ligne » d'un tome qui a une page Illustrations
 * (bouton_commencer(), module bibliothèque) : ils ouvrent cette page, sauf si le lecteur a déjà
 * une position dans ce tome. La position d'un visiteur n'existe que dans son navigateur
 * (localStorage yn.progression, clé : œuvre, contrat §14) : le lien revient alors au premier
 * chapitre du tome. Sans JavaScript, le bouton ouvre la page Illustrations.
 */
( function () {
	'use strict';

	let progression = null;
	try {
		progression = JSON.parse( window.localStorage.getItem( 'yn.progression' ) || 'null' );
	} catch ( e ) {
		progression = null;
	}
	if ( ! progression || typeof progression !== 'object' ) {
		return;
	}
	document.querySelectorAll( 'a[data-yn-debut-chapitre][data-yn-debut-tome][data-yn-debut-oeuvre]' ).forEach( function ( lien ) {
		const position = progression[ lien.getAttribute( 'data-yn-debut-oeuvre' ) ];
		const chapitre = lien.getAttribute( 'data-yn-debut-chapitre' );
		if ( position && chapitre && String( position.tome_id ) === lien.getAttribute( 'data-yn-debut-tome' ) ) {
			lien.setAttribute( 'href', chapitre );
		}
	} );
}() );
