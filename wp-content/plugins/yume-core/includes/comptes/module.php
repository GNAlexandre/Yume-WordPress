<?php
/**
 * Module « comptes » (2.6.0) : page « Comptes inscrits » de l'espace équipe (?vue=comptes,
 * rubrique Équipe, après « Membres et rôles »), réservée aux gérants et aux administrateurs
 * (capacité yume_voir_lecteurs) : liste de tous les comptes avec filtres, tris et indicateurs,
 * fiche d'un compte (identité, lecture, favoris, listes, notes, commentaires, coquilles,
 * notifications, « Ajouter à l'équipe »), export CSV journalisé.
 *
 * Socle (lot 0) : fonctions.php (constantes, droits, adresses, journal des consultations,
 * arguments de la liste, libellés, vue_donnees()) et activite.php (méta de dernière activité au
 * jour près, amorçage depuis les positions de lecture, source d'inscription). Lots suivants :
 * donnees.php (requêtes), equipe.php (vue liste), fiche.php (fiche), export.php (CSV),
 * rgpd.php (export et effacement des données personnelles). Un fichier absent est ignoré.
 *
 * Contrat : docs/06-contrat-technique.md §8 septies. Chargé après rattrapage : les autres modules
 * (Planning\*, Social\*, Reader\*, Discord\*, Statistiques\*) ne sont appelés que dans des
 * callbacks, gardés par function_exists. Au chargement, les fichiers ne font qu'accrocher des
 * hooks (§0). Aucune route REST.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

defined( 'ABSPATH' ) || exit;

foreach ( array( 'fonctions', 'activite', 'donnees', 'equipe', 'fiche', 'export', 'rgpd' ) as $yume_comptes_fichier ) {
	if ( is_readable( __DIR__ . '/' . $yume_comptes_fichier . '.php' ) ) {
		require_once __DIR__ . '/' . $yume_comptes_fichier . '.php';
	}
}
unset( $yume_comptes_fichier );
