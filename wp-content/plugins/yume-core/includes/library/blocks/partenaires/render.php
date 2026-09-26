<?php
/**
 * Rendu du bloc yume/partenaires (module bibliothèque, voir partenaires.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Library\rendu_partenaires( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
