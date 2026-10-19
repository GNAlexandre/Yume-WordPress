<?php
/**
 * Installation du module corrections : table {$wpdb->prefix}yume_coquilles (dbDelta, SQL
 * compatible MySQL/MariaDB et intégration SQLite), tâche quotidienne HOOK_PURGE (purge des
 * signalements traités depuis plus de 180 jours, effacement des empreintes d'IP de plus de
 * 30 jours) et nettoyage (désactivation, chapitre supprimé définitivement).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

/**
 * Crée ou met à jour la table des signalements (idempotent).
 */
function installer_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$table   = table_coquilles();
	dbDelta(
		"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
chapitre_id bigint(20) unsigned NOT NULL,
tome_id bigint(20) unsigned NOT NULL DEFAULT 0,
oeuvre_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
paragraphe int(10) unsigned NOT NULL DEFAULT 0,
extrait varchar(300) NOT NULL DEFAULT '',
contexte text NOT NULL,
empreinte char(32) NOT NULL DEFAULT '',
commentaire varchar(500) NOT NULL DEFAULT '',
statut varchar(10) NOT NULL DEFAULT 'nouveau',
ip_hash char(64) NOT NULL DEFAULT '',
cree_le datetime NOT NULL,
traite_le datetime DEFAULT NULL,
traite_par bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY chapitre_statut (chapitre_id,statut),
KEY oeuvre_statut (oeuvre_id,statut),
KEY user_id (user_id),
KEY cree_le (cree_le)
) {$charset};"
	);
	update_option( OPTION_SCHEMA, VERSION_SCHEMA, true );
}

/**
 * Installation (action yume_core_install) : table et tâche planifiée.
 */
function installer(): void {
	installer_table();
	planifier();
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer' );

/**
 * Filet de sécurité : module ajouté sans changement de version du plugin (yume_core_install
 * non rejoué) → la table est créée au premier chargement.
 */
function verifier_schema(): void {
	if ( wp_installing() ) {
		return;
	}
	if ( ! table_prete() ) {
		installer_table();
	}
}
add_action( 'init', __NAMESPACE__ . '\\verifier_schema', 98 );

/**
 * Planifie la purge quotidienne si elle ne l'est pas.
 */
function planifier(): void {
	if ( wp_installing() || wp_next_scheduled( HOOK_PURGE ) ) {
		return;
	}
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', HOOK_PURGE );
}
add_action( 'init', __NAMESPACE__ . '\\planifier', 99 );

/**
 * Tâche quotidienne : purge des signalements traités (filtre yume_coquilles_retention).
 */
function lancer_purge(): void {
	/**
	 * Durée de conservation des signalements de coquilles traités (corrigés ou rejetés), en jours.
	 *
	 * @param int $jours Défaut 180.
	 */
	purger_coquilles( max( 1, (int) apply_filters( 'yume_coquilles_retention', RETENTION_JOURS ) ) );
}
add_action( HOOK_PURGE, __NAMESPACE__ . '\\lancer_purge' );

/**
 * Libellé de la tâche dans « Santé du site » (filtre yume_taches_cron).
 *
 * @param array<string,string> $taches Hook => libellé.
 * @return array<string,string>
 */
function libeller_tache( $taches ): array {
	$taches               = is_array( $taches ) ? $taches : array();
	$taches[ HOOK_PURGE ] = __( 'Purge des signalements de coquilles traités (180 jours)', 'yume-core' );
	return $taches;
}
add_filter( 'yume_taches_cron', __NAMESPACE__ . '\\libeller_tache' );

/**
 * Désactivation : suppression de la tâche planifiée.
 */
function desactiver(): void {
	wp_clear_scheduled_hook( HOOK_PURGE );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver' );

/**
 * Chapitre supprimé définitivement : ses signalements aussi.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu.
 */
function chapitre_supprime( $post_id, $post = null ): void {
	global $wpdb;
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( 'yume_chapitre' !== $type || ! table_prete() ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_coquilles(), array( 'chapitre_id' => (int) $post_id ), array( '%d' ) );
}
add_action( 'deleted_post', __NAMESPACE__ . '\\chapitre_supprime', 10, 2 );
