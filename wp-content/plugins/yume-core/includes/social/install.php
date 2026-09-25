<?php
/**
 * Installation du module lecteurs : tables favoris et notes (§13, dbDelta compatible MySQL et
 * SQLite), planification du récapitulatif hebdomadaire (dimanche) et nettoyage des données
 * quand un utilisateur ou une œuvre disparaît.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Crée ou met à jour les tables du module (idempotent).
 */
function installer_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$favoris = table_favoris();
	$notes   = table_notes();

	dbDelta(
		"CREATE TABLE {$favoris} (
user_id bigint(20) unsigned NOT NULL,
oeuvre_id bigint(20) unsigned NOT NULL,
frequence varchar(10) NOT NULL DEFAULT 'immediat',
created_at datetime NOT NULL,
PRIMARY KEY  (user_id,oeuvre_id),
KEY oeuvre_id (oeuvre_id)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$notes} (
user_id bigint(20) unsigned NOT NULL,
oeuvre_id bigint(20) unsigned NOT NULL,
note tinyint(3) unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (user_id,oeuvre_id)
) {$charset};"
	);

	update_option( OPTION_SCHEMA, VERSION_SCHEMA, true );
}

/**
 * Installation (action yume_core_install) : tables et tâche planifiée.
 */
function installer(): void {
	installer_tables();
	planifier();
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer' );

/**
 * Filet de sécurité : module ajouté sans changement de version du plugin.
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
 * Prochain dimanche à 10 h (heure du site) strictement après $apres.
 *
 * @param int $apres Horodatage de référence.
 */
function prochain_dimanche( int $apres ): int {
	$fuseau = wp_timezone();
	$date   = ( new \DateTimeImmutable( '@' . $apres ) )->setTimezone( $fuseau )->setTime( 10, 0, 0 );
	for ( $i = 0; $i < 8; $i++ ) {
		if ( $date->getTimestamp() > $apres && '0' === $date->format( 'w' ) ) {
			return $date->getTimestamp();
		}
		$date = $date->modify( '+1 day' )->setTime( 10, 0, 0 );
	}
	return $date->getTimestamp();
}

/**
 * Un horodatage tombe-t-il un dimanche à 10 h pile (heure du site) ?
 *
 * @param int $ts Horodatage.
 */
function est_dimanche_10h( int $ts ): bool {
	$date = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
	return '0' === $date->format( 'w' ) && '10:00' === $date->format( 'H:i' );
}

/**
 * Planifie le récapitulatif hebdomadaire (dimanche 10 h, heure du site) : s'il ne l'est pas,
 * ou s'il ne tombe plus un dimanche à 10 h (programmé avant le réglage du fuseau, passage à
 * l'heure d'hiver ou d'été avec une récurrence fixe de 7 jours, exécution anticipée), il est
 * recalé sur le prochain dimanche 10 h.
 */
function planifier(): void {
	if ( wp_installing() ) {
		return;
	}
	$evenement = wp_get_scheduled_event( HOOK_RECAP );
	if ( $evenement && 'weekly' === $evenement->schedule && est_dimanche_10h( (int) $evenement->timestamp ) ) {
		return;
	}
	wp_clear_scheduled_hook( HOOK_RECAP );
	wp_schedule_event( prochain_dimanche( time() ), 'weekly', HOOK_RECAP );
}
add_action( 'init', __NAMESPACE__ . '\\planifier', 99 );

/**
 * Désactivation : suppression de la tâche planifiée.
 */
function desactiver(): void {
	wp_clear_scheduled_hook( HOOK_RECAP );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver' );

/**
 * Supprime les favoris et notes d'un utilisateur et recalcule les caches des œuvres touchées.
 *
 * @param int $user_id Utilisateur.
 * @return int Nombre de lignes supprimées.
 */
function effacer_lignes_utilisateur( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_pretes() ) {
		return 0;
	}
	$oeuvres = array_unique(
		array_merge(
			wp_list_pluck( favoris_utilisateur( $user_id ), 'oeuvre_id' ),
			wp_list_pluck( notes_utilisateur( $user_id ), 'oeuvre_id' )
		)
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$n = (int) $wpdb->delete( table_favoris(), array( 'user_id' => $user_id ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$n += (int) $wpdb->delete( table_notes(), array( 'user_id' => $user_id ), array( '%d' ) );
	foreach ( $oeuvres as $oeuvre_id ) {
		recalculer_caches( (int) $oeuvre_id );
	}
	return $n;
}

/**
 * Utilisateur supprimé (par l'administration ou en façade) : ses favoris et notes aussi.
 *
 * @param int $user_id Utilisateur.
 */
function utilisateur_supprime( $user_id ): void {
	effacer_lignes_utilisateur( (int) $user_id );
}
add_action( 'deleted_user', __NAMESPACE__ . '\\utilisateur_supprime' );

/**
 * Œuvre supprimée définitivement : ses favoris et notes aussi.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu.
 */
function contenu_supprime( $post_id, $post = null ): void {
	global $wpdb;
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( 'yume_oeuvre' !== $type || ! tables_pretes() ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_favoris(), array( 'oeuvre_id' => (int) $post_id ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_notes(), array( 'oeuvre_id' => (int) $post_id ), array( '%d' ) );
}
add_action( 'deleted_post', __NAMESPACE__ . '\\contenu_supprime', 10, 2 );
