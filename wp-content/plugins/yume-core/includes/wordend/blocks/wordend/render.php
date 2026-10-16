<?php
/**
 * Rendu du bloc yume/wordend (voir page.php).
 *
 * @package Yume\Core
 *
 * @var array $attributes Attributs du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\WordEnd\rendu_page(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
