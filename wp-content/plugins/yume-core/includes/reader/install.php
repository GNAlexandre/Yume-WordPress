<?php
/**
 * Installation du module lecture : table {$wpdb->prefix}yume_progression (§13), créée avec
 * dbDelta (SQL compatible MySQL/MariaDB et intégration SQLite), et nettoyage des lignes quand
 * un utilisateur, une œuvre ou un chapitre disparaît.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Crée ou met à jour la table de progression (idempotent).
 */
function installer_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$table   = table_progression();
	dbDelta(
		"CREATE TABLE {$table} (
user_id bigint(20) unsigned NOT NULL,
oeuvre_id bigint(20) unsigned NOT NULL,
tome_id bigint(20) unsigned NOT NULL DEFAULT 0,
chapitre_id bigint(20) unsigned NOT NULL DEFAULT 0,
paragraphe int(10) unsigned NOT NULL DEFAULT 0,
pourcentage tinyint(3) unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (user_id,oeuvre_id)
) {$charset};"
	);
	update_option( OPTION_SCHEMA, VERSION_SCHEMA, true );
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer_tables' );

/**
 * Filet de sécurité : module ajouté sans changement de version du plugin (yume_core_install
 * non rejoué) → la table est créée au premier chargement.
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
 * Utilisateur supprimé : ses positions de lecture aussi.
 *
 * @param int $user_id Utilisateur.
 */
function utilisateur_supprime( $user_id ): void {
	supprimer_progression_par( 'user_id', (int) $user_id );
}
add_action( 'deleted_user', __NAMESPACE__ . '\\utilisateur_supprime' );

/**
 * Contenu supprimé définitivement : œuvre ou chapitre → lignes correspondantes.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu.
 */
function contenu_supprime( $post_id, $post = null ): void {
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( 'yume_oeuvre' === $type ) {
		supprimer_progression_par( 'oeuvre_id', (int) $post_id );
	} elseif ( 'yume_chapitre' === $type ) {
		supprimer_progression_par( 'chapitre_id', (int) $post_id );
	}
}
add_action( 'deleted_post', __NAMESPACE__ . '\\contenu_supprime', 10, 2 );
