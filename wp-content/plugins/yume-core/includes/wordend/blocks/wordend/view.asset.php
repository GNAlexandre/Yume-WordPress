<?php
/**
 * Dépendances et version du script de la page WordEnd (aucune dépendance : JavaScript natif).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(),
	'version'      => (string) ( filemtime( __DIR__ . '/view.js' ) ?: '2.2.0' ),
);
