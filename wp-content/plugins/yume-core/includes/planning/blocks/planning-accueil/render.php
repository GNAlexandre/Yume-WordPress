<?php
/**
 * Rendu du bloc yume/planning-accueil (voir vitrine.php).
 *
 * @package Yume\Core
 *
 * @var array $attributes Attributs du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Planning\rendu_planning_accueil( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
