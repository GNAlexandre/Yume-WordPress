<?php
/**
 * Rendu du bloc yume/liste-publique (module lecteurs, voir listes.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Social\rendu_liste_publique(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
