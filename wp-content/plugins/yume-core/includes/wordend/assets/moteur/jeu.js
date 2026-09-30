/**
 * WordEnd — moteur, dernier fichier : point d'entrée.
 *
 * Expose window.ynWordEndJeu = { ouvrir( config, univers? ), fermer(), estOuvert() } (API
 * attendue par declencheur.js, qui attend cet objet pour résoudre le chargement). Fichier FIGÉ
 * après le lot 0 (intégrateur seulement). Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	if ( ! ynWE || ! ynWE.modale ) {
		return;
	}
	window.ynWordEndJeu = ynWE.modale;
}() );
