/**
 * Styles calculés attendus sur les pages de démonstration (tools/playground/demo.php), vérifiés par
 * tools/ci/rendu.js sur WordPress 6.6 et sur la dernière version (job « Rendu » de la CI).
 *
 * Origine (revue RC-1) : sous WordPress 6.6, les styles globaux du cœur (éléments h1-h6, liens,
 * boutons) avaient une spécificité supérieure ou égale aux sélecteurs de bloc non préfixés et les
 * écrasaient : titre du panneau de lecture en 28px/700 au lieu de 20px/800, lien de retour du lecteur
 * en rose accent au lieu de texte-fort… Les sélecteurs sont depuis préfixés par la classe du bloc
 * (`.yn-reader-panel .yn-reader-panel__titre`). Ce tableau fige le résultat attendu.
 *
 * Format :
 *   pages[].nom        libellé dans les messages
 *   pages[].chemin     URL relative au site (slugs créés par tools/playground/demo.php)
 *   pages[].avant      action facultative avant la mesure : 'parametres' ouvre le panneau
 *                      « Paramètres de lecture » (<dialog>) du lecteur
 *   pages[].controles  { selecteur, attendu: { propriété CSS: valeur } }
 *                      selecteur : premier élément correspondant (doit exister et être visible)
 *                      valeur    : valeur calculée exacte (getComputedStyle), ou 'preset:<slug>'
 *                                  pour une couleur de la palette du thème (theme.json), résolue
 *                                  dans le contexte de l'élément (thèmes de lecture Nuit/Papier/Sépia)
 *
 * Fenêtre fixe 1280×900 et schéma de couleurs clair : les tailles en clamp() (titres d'en-tête)
 * valent alors leur maximum, 44px.
 */
'use strict';

module.exports = {
	fenetre: { width: 1280, height: 900 },
	schema: 'light',
	pages: [
		{
			nom: 'œuvre',
			chemin: '/oeuvres/lanternes-de-brume-haute/',
			controles: [
				{
					selecteur: '.yn-oeuvre-header__titre',
					attendu: { 'font-size': '44px', 'font-weight': '800', color: 'preset:texte-fort' },
				},
				{
					selecteur: '.yn-tome-list__titre',
					attendu: { 'font-size': '15px', 'font-weight': '600', color: 'preset:texte-fort' },
				},
				{
					selecteur: '.yn-tome-list__nom',
					attendu: { 'font-size': '16px', 'font-weight': '600', color: 'preset:texte-fort' },
				},
				{
					// Lien du nom de tome : hérite de texte-fort (les styles globaux le passaient en accent).
					selecteur: '.yn-tome-list__nom a',
					attendu: { 'font-size': '16px', 'font-weight': '600', color: 'preset:texte-fort' },
				},
			],
		},
		{
			nom: 'tome',
			chemin: '/oeuvres/lanternes-de-brume-haute/tome-1/',
			controles: [
				{
					selecteur: '.yn-tome-header__titre',
					attendu: { 'font-size': '44px', 'font-weight': '800', color: 'preset:texte-fort' },
				},
				{
					// Taille « grand » du theme.json (20px), graisse des titres h2 du thème.
					selecteur: '.yn-toc__titre',
					attendu: { 'font-size': '20px', 'font-weight': '700', color: 'preset:texte-fort' },
				},
				{
					// Lien du sommaire : couleur texte, pas l'accent des liens des styles globaux.
					selecteur: '.yn-toc__lien',
					attendu: { 'font-size': '15px', 'font-weight': '400', color: 'preset:texte' },
				},
			],
		},
		{
			nom: 'chapitre (panneau Paramètres ouvert)',
			chemin: '/lire/lanternes-de-brume-haute/tome-1/1/',
			avant: 'parametres',
			controles: [
				{
					selecteur: '.yn-reader-tools__retour',
					attendu: { 'font-size': '14px', 'font-weight': '600', color: 'preset:texte-fort' },
				},
				{
					selecteur: '.yn-reader-panel__titre',
					attendu: { 'font-size': '20px', 'font-weight': '800', color: 'preset:texte-fort' },
				},
				{
					selecteur: '.yn-reader-panel__option:not(:has(> input:checked))',
					attendu: { 'font-size': '14px', 'font-weight': '400', color: 'preset:texte-fort' },
				},
				{
					selecteur: '.yn-reader-panel__option:has(> input:checked)',
					attendu: { 'font-size': '14px', 'font-weight': '700', color: 'preset:accent-texte' },
				},
			],
		},
	],
};
