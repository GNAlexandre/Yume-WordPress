<?php
/**
 * Dépendances et version du script de la barre de lecture (JavaScript natif, sans dépendance).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(),
	'version'      => (string) ( filemtime( __DIR__ . '/view.js' ) ?: '2.0.0' ),
);
