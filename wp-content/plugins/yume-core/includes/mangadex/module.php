<?php
/**
 * Module « mangadex » : lecture sur le site des mangas que l'équipe publie sur MangaDex
 * (stockage et visibilité chez MangaDex, aucune image gardée par le site).
 *
 * - Client de l'API MangaDex (class-client.php) : chapitres en français du groupe Yume Novel
 *   (paginés), serveur MD@Home d'un chapitre (en cache), User-Agent réel, jamais de nouvel
 *   essai après une limite (429) ou un blocage (403).
 * - Réglage « Groupe MangaDex » (Yume → Réglages, section Mangas).
 * - Modèle de données (métas déclarées par core, contrat §4) : série MangaDex de l'œuvre
 *   (yume_mdx_serie) ; chapitre hébergé sur MangaDex (yume_mdx_chapitre, yume_mdx_pages,
 *   yume_mdx_version, yume_mdx_groupes). Un volume MangaDex est un tome (yume_numero) ; les
 *   chapitres sans volume vont dans un tome de nature « chapitres ». API : api.php ; boîtes
 *   d'administration : admin.php.
 *
 * Voir tools/mangadex-prototype/README.md (essai, règles de MangaDex) et
 * docs/06-contrat-technique.md. Au chargement, les fichiers ne font qu'accrocher des hooks (§0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/class-client.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/admin.php';
