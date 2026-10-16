<?php
/**
 * Module « mangadex » : lecture sur le site des mangas que l'équipe publie sur MangaDex
 * (stockage et visibilité chez MangaDex, aucune image gardée par le site).
 *
 * - Client de l'API MangaDex (class-client.php) : chapitres en français du groupe Yume Novel
 *   (paginés), serveur MD@Home d'un chapitre (en cache), User-Agent réel, jamais de nouvel
 *   essai après une limite (429) ou un blocage (403).
 * - Réglage « Groupe MangaDex » (Yume → Réglages, section Mangas).
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
