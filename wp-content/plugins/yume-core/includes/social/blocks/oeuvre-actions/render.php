<?php
/**
 * Rendu du bloc yume/oeuvre-actions (module lecteurs, voir blocs.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Social\rendu_oeuvre_actions( (array) $attributes, $block ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
