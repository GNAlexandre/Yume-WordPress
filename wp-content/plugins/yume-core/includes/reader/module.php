<?php
/**
 * Module « lecture » : réglages de lecture (user_meta yume_reglages), progression de lecture
 * (table yume_progression), routes REST /moi/reglages et /moi/progression, et bloc
 * yume/reader-tools (barre de lecture, marque-page, thème, panneau Paramètres avec options
 * d'accessibilité, raccourcis), statistiques de lecture (page compte) et lecture hors ligne
 * (manifeste web, service worker : pwa.php).
 *
 * Contrat : docs/06-contrat-technique.md §10, §12, §13, §14 ; docs/04 §4.
 * Au chargement, les fichiers ne font qu'accrocher des hooks (§0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/lecteur.php';
require_once __DIR__ . '/statistiques.php';
require_once __DIR__ . '/pwa.php';
require_once __DIR__ . '/tiret.php';
