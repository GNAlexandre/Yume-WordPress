<?php
/**
 * Rendu du bloc yume/library-menu (module bibliothèque, voir rendus-*.php).
 *
 * @package Yume\Core
 *
 * Bloc sans attribut ni contexte.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Library\rendu_library_menu(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
