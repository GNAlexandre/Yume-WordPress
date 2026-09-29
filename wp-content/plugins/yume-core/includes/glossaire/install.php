<?php
/**
 * Installation du module glossaire : tables {$wpdb->prefix}yume_glossaire (entrées) et
 * {$wpdb->prefix}yume_glossaire_versions (5 dernières versions du YAML par œuvre), créées avec
 * dbDelta (SQL compatible MySQL/MariaDB et intégration SQLite) ; nettoyage quand une œuvre est
 * supprimée définitivement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

/**
 * Crée ou met à jour les tables (idempotent).
 */
function installer_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset  = $wpdb->get_charset_collate();
	$entrees  = table_entrees();
	$versions = table_versions();
	dbDelta(
		"CREATE TABLE {$entrees} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
oeuvre_id bigint(20) unsigned NOT NULL DEFAULT 0,
categorie varchar(40) NOT NULL DEFAULT '',
ordre int(10) unsigned NOT NULL DEFAULT 0,
nom varchar(255) NOT NULL DEFAULT '',
termes_source text NOT NULL,
recherche text NOT NULL,
donnees longtext NOT NULL,
PRIMARY KEY  (id),
KEY oeuvre_categorie (oeuvre_id,categorie)
) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$versions} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
oeuvre_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
source varchar(20) NOT NULL DEFAULT '',
cree_le datetime NOT NULL,
sha256 char(64) NOT NULL DEFAULT '',
nb_entrees int(10) unsigned NOT NULL DEFAULT 0,
yaml longtext NOT NULL,
note varchar(255) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
KEY oeuvre_id (oeuvre_id)
) {$charset};"
	);
	update_option( OPTION_SCHEMA, VERSION_SCHEMA, true );
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer_tables' );

/**
 * Filet de sécurité : module ajouté sans changement de version du plugin (yume_core_install
 * non rejoué) → les tables sont créées au premier chargement.
 */
function verifier_schema(): void {
	if ( wp_installing() ) {
		return;
	}
	if ( VERSION_SCHEMA !== get_option( OPTION_SCHEMA ) ) {
		installer_tables();
	}
}
add_action( 'init', __NAMESPACE__ . '\\verifier_schema', 98 );

/**
 * Œuvre supprimée définitivement : son glossaire et ses versions aussi.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu.
 */
function contenu_supprime( $post_id, $post = null ): void {
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( 'yume_oeuvre' === $type ) {
		supprimer_glossaire( (int) $post_id );
	}
}
add_action( 'deleted_post', __NAMESPACE__ . '\\contenu_supprime', 10, 2 );
