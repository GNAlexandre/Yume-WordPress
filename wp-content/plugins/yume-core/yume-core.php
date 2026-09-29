<?php
/**
 * Plugin Name:       Yume Core
 * Plugin URI:        https://github.com/GNAlexandre/Yume-WordPress
 * Description:       Bibliothèque Yume Novel : œuvres, tomes, chapitres, lecture en ligne, planning, espace équipe, publication DOCX, comptes lecteurs.
 * Version:           2.1.3
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Yume Novel
 * License:           GPL-2.0-or-later
 * Text Domain:       yume-core
 * Update URI:        https://github.com/GNAlexandre/Yume-WordPress
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

define( 'YUME_CORE_VERSION', '2.1.3' );
define( 'YUME_CORE_FILE', __FILE__ );
define( 'YUME_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'YUME_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'YUME_CORE_DB_VERSION_OPTION', 'yume_core_db_version' );

/**
 * Ordre de chargement des modules. Chaque module vit dans includes/<module>/module.php
 * et ne fait qu'accrocher des hooks : aucun appel inter-modules au chargement du fichier.
 * Voir docs/06-contrat-technique.md.
 */
const YUME_CORE_MODULES = array(
	'core',
	'import',
	'publication',
	'planning',
	'reader',
	'social',
	'library',
	'glossaire',
	'migration',
	'updater',
);

require_once YUME_CORE_DIR . 'includes/blocks-support.php';

/**
 * Charge un fichier de module. En développement local (YUME_DEV), une erreur de syntaxe
 * dans un module n'empêche pas les autres de se charger.
 *
 * @param string $module Nom du module.
 */
function yume_core_load_module( string $module ): void {
	$file = YUME_CORE_DIR . 'includes/' . $module . '/module.php';
	if ( ! is_readable( $file ) ) {
		return;
	}
	$only = getenv( 'YUME_ONLY_MODULES' );
	if ( defined( 'YUME_DEV' ) && YUME_DEV && $only && ! in_array( $module, array_map( 'trim', explode( ',', $only ) ), true ) ) {
		return;
	}
	if ( defined( 'YUME_DEV' ) && YUME_DEV ) {
		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[yume-core] module %s non chargé : %s (%s:%d)', $module, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return;
	}
	require_once $file;
}

foreach ( YUME_CORE_MODULES as $yume_module ) {
	yume_core_load_module( $yume_module );
}
unset( $yume_module );

/**
 * Installation et montée de version : chaque module crée ses tables et ses données
 * dans un callback accroché à `yume_core_install` (dbDelta idempotent).
 */
function yume_core_install(): void {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	do_action( 'yume_core_install' );
	// Autochargée : elle est relue à chaque requête (crochet init ci-dessous).
	update_option( YUME_CORE_DB_VERSION_OPTION, YUME_CORE_VERSION, true );
	if ( function_exists( 'wp_set_option_autoload' ) ) {
		wp_set_option_autoload( YUME_CORE_DB_VERSION_OPTION, true ); // Installations antérieures (autoload off).
	}
	flush_rewrite_rules( false );
}

/**
 * Désactivation : chaque module nettoie (yume_core_deactivate), puis les règles de réécriture
 * sont supprimées plutôt que régénérées. Dans cette requête, les types Yume et les règles
 * /oeuvres/… et /lire/… sont encore enregistrés : un flush les réécrirait en base. WordPress
 * régénère l'option à la requête suivante, sans le plugin.
 */
function yume_core_deactivate(): void {
	do_action( 'yume_core_deactivate' );
	delete_option( 'rewrite_rules' );
	// À la réactivation, les règles Yume seront de nouveau écrites (routing.php).
	delete_option( 'yume_core_regles' );
}

register_activation_hook(
	__FILE__,
	static function (): void {
		// Les types de contenu doivent exister avant le flush des règles de réécriture.
		do_action( 'yume_core_register_content' );
		yume_core_install();
	}
);

register_deactivation_hook( __FILE__, 'yume_core_deactivate' );

add_action(
	'init',
	static function (): void {
		if ( get_option( YUME_CORE_DB_VERSION_OPTION ) !== YUME_CORE_VERSION ) {
			yume_core_install();
		}
	},
	99
);
