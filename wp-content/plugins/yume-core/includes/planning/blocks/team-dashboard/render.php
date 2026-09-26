<?php
/**
 * Rendu du bloc yume/team-dashboard (voir equipe.php).
 *
 * @package Yume\Core
 *
 * Le bloc n'a pas d'attribut.
 */

defined( 'ABSPATH' ) || exit;

echo \Yume\Core\Planning\rendu_team_dashboard(); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML échappé à la construction.
