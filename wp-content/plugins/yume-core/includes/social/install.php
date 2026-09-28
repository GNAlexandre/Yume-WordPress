<?php
/**
 * Installation du module lecteurs : tables favoris et notes (§13, dbDelta compatible MySQL et
 * SQLite), tables des listes de lecture, du centre de notifications et des abonnements Web Push
 * (schéma distinct yume_social_schema_lecteur, lot P3-D), planification du récapitulatif hebdomadaire (dimanche) et nettoyage des données
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
	installer_tables_lecteur();
}

/** Option : version du schéma des tables listes, notifications du lecteur et Web Push. */
const OPTION_SCHEMA_LECTEUR = 'yume_social_schema_lecteur';

/** Version du schéma des tables listes, listes_oeuvres, notifications_lecteur et push. */
const VERSION_SCHEMA_LECTEUR = '1';

/**
 * Crée ou met à jour les tables des listes de lecture (PAGE-07), du centre de notifications
 * (AMEL-11) et des abonnements Web Push (AMEL-06). Idempotent.
 */
function installer_tables_lecteur(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$listes  = table_listes();
	$contenu = table_listes_oeuvres();
	$notifs  = table_notifications_lecteur();
	$push    = table_push();

	dbDelta(
		"CREATE TABLE {$listes} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
nom varchar(80) NOT NULL DEFAULT '',
slug varchar(100) NOT NULL DEFAULT '',
description varchar(300) NOT NULL DEFAULT '',
publique tinyint(1) unsigned NOT NULL DEFAULT 0,
systeme varchar(20) NOT NULL DEFAULT '',
cree_le datetime NOT NULL,
maj_le datetime NOT NULL,
PRIMARY KEY  (id),
KEY user_id (user_id)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$contenu} (
liste_id bigint(20) unsigned NOT NULL,
oeuvre_id bigint(20) unsigned NOT NULL,
ajoute_le datetime NOT NULL,
ordre int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (liste_id,oeuvre_id),
KEY oeuvre_id (oeuvre_id)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$notifs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
type varchar(20) NOT NULL DEFAULT '',
objet_id bigint(20) unsigned NOT NULL DEFAULT 0,
titre varchar(255) NOT NULL DEFAULT '',
url varchar(500) NOT NULL DEFAULT '',
cree_le datetime NOT NULL,
lu_le datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY user_lu (user_id,lu_le),
KEY cree_le (cree_le)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$push} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
endpoint varchar(1000) NOT NULL DEFAULT '',
empreinte char(64) NOT NULL DEFAULT '',
p256dh varchar(200) NOT NULL DEFAULT '',
auth varchar(100) NOT NULL DEFAULT '',
cree_le datetime NOT NULL,
dernier_envoi datetime DEFAULT NULL,
echecs smallint(5) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY empreinte (empreinte),
KEY user_id (user_id)
) {$charset};"
	);

	update_option( OPTION_SCHEMA_LECTEUR, VERSION_SCHEMA_LECTEUR, true );
}

/**
 * Les tables listes, notifications du lecteur et Web Push sont-elles installées ?
 */
function tables_lecteur_pretes(): bool {
	return VERSION_SCHEMA_LECTEUR === get_option( OPTION_SCHEMA_LECTEUR );
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
	} elseif ( ! tables_lecteur_pretes() ) {
		installer_tables_lecteur();
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
