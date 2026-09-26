<?php
/**
 * Rendu du bloc yume/account (voir compte.php) (module lecteurs, voir blocs.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Social\rendu_compte(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
