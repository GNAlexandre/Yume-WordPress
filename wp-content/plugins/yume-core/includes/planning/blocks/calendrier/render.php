<?php
/**
 * Rendu du bloc yume/calendrier (voir blocs.php).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Planning\rendu_calendrier(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
