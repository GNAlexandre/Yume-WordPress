<?php
/**
 * Rendu du bloc yume/recrutement (voir recrutement.php).
 *
 * @package Yume\Core
 *
 * @var array $attributes Attributs du bloc.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Planning\rendu_recrutement(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
