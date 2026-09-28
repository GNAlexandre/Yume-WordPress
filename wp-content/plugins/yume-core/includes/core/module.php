<?php
/**
 * Module « core » : types de contenu, taxonomies, URL et routage, métadonnées, rôles,
 * réglages, événements métier et administration de base.
 *
 * Tous les autres modules dépendent de ce module. Au chargement, les fichiers ne font
 * qu'accrocher des hooks (voir docs/06-contrat-technique.md §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/routing.php';
require_once __DIR__ . '/illustrations.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/securite.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/admin/menu.php';
require_once __DIR__ . '/admin/metaboxes.php';
require_once __DIR__ . '/admin/columns.php';
require_once __DIR__ . '/admin/acces.php';
require_once __DIR__ . '/admin/notices.php';
require_once __DIR__ . '/admin/sante.php';
