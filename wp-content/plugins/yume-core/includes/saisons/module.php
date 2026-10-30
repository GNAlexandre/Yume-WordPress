<?php
/**
 * Module « saisons » (2.7.0) : thèmes saisonniers du site (Printemps, Été, Automne, Hiver, et
 * les fêtes Halloween et Noël, qui l'emportent sur la saison).
 *
 * Le plugin décide quelle variante est active pour la requête (yume_saison_active()) ; le thème
 * l'applique (attribut data-yn-saison sur <html>, logo, palette). Programmation dans les réglages
 * (section « Thème saisonnier » : mode, thème forcé, calendrier de six lignes, décors), aperçu
 * ?yn-saison=<slug|aucun> réservé à la capacité yume_reglages (jamais mis en cache), événement
 * yume_saison_changee vérifié par la tâche quotidienne yume_saisons_quotidien (00:05, heure du
 * site) et à l'enregistrement des réglages, purge des caches de page sur cet événement.
 *
 * Fichiers : fonctions.php (calendrier, résolution, aperçu, événement, purge), api.php (fonctions
 * publiques yume_saison_*), reglages.php (section et champs de Yume → Réglages, assainissement),
 * install.php (tâche planifiée). Cahier des charges : docs/07-themes-saisonniers.md.
 * Au chargement, les fichiers du module ne font qu'accrocher des hooks (contrat §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Saisons;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/reglages.php';
require_once __DIR__ . '/install.php';
