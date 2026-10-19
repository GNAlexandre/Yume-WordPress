<?php
/**
 * Module « statistiques » : page « Statistiques » de l'espace équipe (?vue=statistiques).
 * Audience du site et des œuvres : trafic Jetpack (WordPress.com Stats) ou données simulées hors
 * production, favoris, suiveurs alertés, listes de lecture, lecteurs et chapitres lus (agrégats),
 * historique quotidien (table yume_stats_jour, tâche planifiée yume_statistiques_jour), listes
 * nominatives des favoris réservées aux gérants (capacité yume_voir_lecteurs) et exports CSV.
 *
 * Capacités : yume_voir_statistiques (tous les rôles de l'équipe et les administrateurs, agrégats
 * seulement) et yume_voir_lecteurs (gérants et administrateurs, listes nominatives).
 *
 * Chargé après planning, reader, social et library : il consomme leurs fonctions dans des
 * callbacks seulement, gardés par function_exists. Au chargement, les fichiers ne font
 * qu'accrocher des hooks (contrat §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

// Socle : constantes, période, cache, table (contrats partagés par tous les fichiers du module).
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/rest.php';
// Trafic Jetpack et simulation hors production.
require_once __DIR__ . '/trafic.php';
require_once __DIR__ . '/trafic-simule.php';
// Compteurs par œuvre, classements, historique quotidien.
require_once __DIR__ . '/oeuvres.php';
require_once __DIR__ . '/historique.php';
// Listes nominatives (gérants) et exports CSV.
require_once __DIR__ . '/lecteurs.php';
require_once __DIR__ . '/export.php';
// Vue de l'espace équipe et graphiques SVG.
require_once __DIR__ . '/equipe.php';
require_once __DIR__ . '/graphiques.php';
