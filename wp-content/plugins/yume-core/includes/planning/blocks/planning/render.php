<?php
/**
 * Rendu du bloc yume/planning (voir blocs.php).
 *
 * @package Yume\Core
 *
 * @var array $attributes Attributs du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Planning\rendu_planning( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
