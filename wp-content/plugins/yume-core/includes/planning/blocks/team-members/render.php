<?php
/**
 * Rendu du bloc yume/team-members (voir membres.php).
 *
 * @package Yume\Core
 *
 * Le bloc n'a pas d'attribut.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Planning\rendu_team_members(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
