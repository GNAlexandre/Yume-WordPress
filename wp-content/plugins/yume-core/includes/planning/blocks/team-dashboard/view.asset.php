<?php
/**
 * Dépendances et version du script de l'espace équipe (aucune dépendance : JavaScript natif).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(),
	'version'      => (string) ( filemtime( __DIR__ . '/view.js' ) ?: '2.0.0' ),
);
