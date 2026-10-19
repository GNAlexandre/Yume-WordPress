<?php
/**
 * Rendu du bloc yume/recommandations (module recommandations, voir rendu.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Recommandations\rendu_bloc( (array) $attributes, $block ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
