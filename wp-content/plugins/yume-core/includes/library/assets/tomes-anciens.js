/**
 * Ligne « Tomes 1 à 4 » de la liste des tomes (yume/tome-list) : une fois ouverte, elle est
 * masquée (style.css) et les tomes précédents prennent sa place. Le focus, qui était sur la
 * ligne disparue, passe au premier lien du premier tome affiché.
 */
( function () {
	'use strict';

	document.addEventListener(
		'toggle',
		function ( evenement ) {
			const details = evenement.target;
			if ( ! details.matches || ! details.matches( '.yn-tome-list__anciens' ) || ! details.open ) {
				return;
			}
			const actif = document.activeElement;
			if ( actif && actif !== document.body && ! details.contains( actif ) ) {
				return;
			}
			const cible = details.querySelector( '.yn-tome-list__liste a[href], .yn-tome-list__liste button' );
			if ( cible ) {
				cible.focus( { preventScroll: true } );
			}
		},
		true
	);
}() );
