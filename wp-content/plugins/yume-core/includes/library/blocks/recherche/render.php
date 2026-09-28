<?php
/**
 * Rendu du bloc yume/recherche (module bibliothèque, voir recherche-rendu.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Library\rendu_recherche( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
