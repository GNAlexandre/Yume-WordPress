<?php
/**
 * Rendu du bloc yume/rattrapage, « Reprendre la lecture » (module rattrapage, voir fonctions.php) :
 * chaîne vide pour un visiteur, rendu serveur pour un membre connecté.
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Rattrapage\rendu_bloc( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
