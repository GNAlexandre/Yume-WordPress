<?php
/**
 * Rendu du bloc yume/oeuvre-infos (module bibliothèque, voir rendus-*.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Library\rendu_oeuvre_infos( (array) $attributes, $block ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
