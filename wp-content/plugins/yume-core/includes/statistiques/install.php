<?php
/**
 * Installation du module statistiques : table d'historique quotidien {$wpdb->prefix}yume_stats_jour
 * (dbDelta, SQL compatible MySQL/MariaDB et intégration SQLite), tâche planifiée quotidienne
 * HOOK_JOUR (03:10, heure du site) et nettoyage (désactivation, œuvre supprimée).
 *
 * Une ligne par jour et par œuvre publiée ; oeuvre_id = 0 : ligne « site » (vues et visiteurs
 * Jetpack, favoris et suiveurs totaux, lecteurs ayant une position, comptes lecteurs,
 * inscriptions du jour). vues/visiteurs NULL : trafic indisponible ce jour-là. Aucune donnée
 * personnelle : uniquement des compteurs.
 *
 * Le calcul lui-même (tache_quotidienne(), historique.php) est appelé par lancer_tache_jour(),
 * seul callback accroché à HOOK_JOUR.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Heure de la tâche quotidienne (heure du site). */
const HEURE_TACHE_JOUR = 3;

/** Minute de la tâche quotidienne. */
const MINUTE_TACHE_JOUR = 10;

/**
 * Crée ou met à jour la table d'historique (idempotent).
 */
function installer_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$table   = table_stats_jour();
	dbDelta(
		"CREATE TABLE {$table} (
jour date NOT NULL,
oeuvre_id bigint(20) unsigned NOT NULL DEFAULT 0,
vues int(10) unsigned DEFAULT NULL,
visiteurs int(10) unsigned DEFAULT NULL,
favoris int(10) unsigned NOT NULL DEFAULT 0,
suiveurs int(10) unsigned NOT NULL DEFAULT 0,
listes int(10) unsigned NOT NULL DEFAULT 0,
lecteurs int(10) unsigned NOT NULL DEFAULT 0,
lecteurs_30j int(10) unsigned NOT NULL DEFAULT 0,
chapitres_lus int(10) unsigned NOT NULL DEFAULT 0,
comptes int(10) unsigned NOT NULL DEFAULT 0,
inscriptions int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (jour,oeuvre_id),
KEY oeuvre_id (oeuvre_id)
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

/*
 * -----------------------------------------------------------------------------
 * Tâche planifiée quotidienne
 * -----------------------------------------------------------------------------
 */

/**
 * Prochain 03:10 (heure du site) strictement après $apres.
 *
 * @param int $apres Horodatage de référence.
 */
function prochaine_tache_jour( int $apres ): int {
	$date = ( new \DateTimeImmutable( '@' . $apres ) )->setTimezone( wp_timezone() )->setTime( HEURE_TACHE_JOUR, MINUTE_TACHE_JOUR, 0 );
	if ( $date->getTimestamp() <= $apres ) {
		$date = $date->modify( '+1 day' )->setTime( HEURE_TACHE_JOUR, MINUTE_TACHE_JOUR, 0 );
	}
	return $date->getTimestamp();
}

/**
 * Un horodatage tombe-t-il à 03:10 pile (heure du site) ?
 *
 * @param int $ts Horodatage.
 */
function est_heure_tache_jour( int $ts ): bool {
	$date = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
	return sprintf( '%02d:%02d:00', HEURE_TACHE_JOUR, MINUTE_TACHE_JOUR ) === $date->format( 'H:i:s' );
}

/**
 * Planifie la tâche quotidienne (03:10, heure du site) : si elle ne l'est pas, ou si elle ne
 * tombe plus à 03:10 (programmée avant le réglage du fuseau, changement d'heure avec une
 * récurrence fixe de 24 h), elle est recalée sur le prochain 03:10. Les jours éventuellement
 * sautés sont rattrapés par la tâche elle-même.
 */
function planifier(): void {
	if ( wp_installing() ) {
		return;
	}
	$evenement = wp_get_scheduled_event( HOOK_JOUR );
	if ( $evenement && 'daily' === $evenement->schedule && est_heure_tache_jour( (int) $evenement->timestamp ) ) {
		return;
	}
	wp_clear_scheduled_hook( HOOK_JOUR );
	wp_schedule_event( prochaine_tache_jour( time() ), 'daily', HOOK_JOUR );
}
add_action( 'init', __NAMESPACE__ . '\\planifier', 99 );

/**
 * Callback de la tâche quotidienne : calcul de l'historique (historique.php) s'il est chargé.
 */
function lancer_tache_jour(): void {
	if ( function_exists( __NAMESPACE__ . '\\tache_quotidienne' ) ) {
		tache_quotidienne();
	}
}
add_action( HOOK_JOUR, __NAMESPACE__ . '\\lancer_tache_jour' );

/**
 * Libellé de la tâche dans « Santé du site » (filtre yume_taches_cron).
 *
 * @param array<string,string> $taches Hook => libellé.
 * @return array<string,string>
 */
function libeller_tache_jour( $taches ): array {
	$taches              = is_array( $taches ) ? $taches : array();
	$taches[ HOOK_JOUR ] = __( 'Statistiques quotidiennes (historique)', 'yume-core' );
	return $taches;
}
add_filter( 'yume_taches_cron', __NAMESPACE__ . '\\libeller_tache_jour' );

/**
 * Désactivation : suppression de la tâche planifiée.
 */
function desactiver(): void {
	wp_clear_scheduled_hook( HOOK_JOUR );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver' );

/*
 * -----------------------------------------------------------------------------
 * Nettoyage
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre supprimée définitivement : ses lignes d'historique aussi, et le cache est vidé.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu.
 */
function oeuvre_supprimee( $post_id, $post = null ): void {
	global $wpdb;
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( 'yume_oeuvre' !== $type || ! table_prete() ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_stats_jour(), array( 'oeuvre_id' => (int) $post_id ), array( '%d' ) );
	cache_vider();
}
add_action( 'deleted_post', __NAMESPACE__ . '\\oeuvre_supprimee', 10, 2 );
