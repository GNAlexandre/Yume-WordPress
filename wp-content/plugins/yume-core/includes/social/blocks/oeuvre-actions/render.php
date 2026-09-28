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

// Menu « Ajouter à une liste » (listes.php) inséré à côté de Favori et Alerte.
echo \Yume\Core\Social\inserer_menu_listes( \Yume\Core\Social\rendu_oeuvre_actions( (array) $attributes, $block ?? null ), $block ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
