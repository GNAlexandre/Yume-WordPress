<?php
/**
 * Rendu du bloc yume/auth-links (module lecteurs, voir blocs.php).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

// Cloche des notifications (notifications-lecteur.php) : membres connectés seulement.
echo \Yume\Core\Social\inserer_cloche( \Yume\Core\Social\rendu_auth_links() ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
