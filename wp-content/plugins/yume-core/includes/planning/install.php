<?php
/**
 * Installation du module planning : tables planning_journal et notifications (§13, dbDelta
 * compatible MySQL et SQLite), planification idempotente des tâches cron (rappels quotidiens,
 * récapitulatif hebdomadaire, envoi des e-mails toutes les 5 minutes) et nettoyage à la
 * désactivation.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Crée ou met à jour les tables du module (idempotent).
 */
function installer_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$journal = table_journal();
	$notifs  = table_notifications();

	dbDelta(
		"CREATE TABLE {$journal} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
tome_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
champ varchar(40) NOT NULL DEFAULT '',
ancien text NULL,
nouveau text NULL,
public tinyint(1) NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY tome_id (tome_id),
KEY created_at (created_at)
) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$notifs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
destinataire varchar(190) NOT NULL DEFAULT '',
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
sujet varchar(255) NOT NULL DEFAULT '',
html longtext NOT NULL,
contexte varchar(60) NOT NULL DEFAULT '',
statut varchar(10) NOT NULL DEFAULT 'attente',
tentatives tinyint(3) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
envoye_le datetime NULL,
PRIMARY KEY  (id),
KEY statut (statut)
) {$charset};"
	);

	update_option( OPTION_SCHEMA, VERSION_SCHEMA, true );
}

/**
 * Installation (action yume_core_install) : tables et tâches planifiées.
 */
function installer(): void {
	installer_tables();
	planifier();
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer' );

/**
 * Filet de sécurité : si le module est ajouté à un site dont la version du plugin n'a pas
 * changé (yume_core_install non rejoué), ses tables sont créées au premier chargement.
 */
function verifier_schema(): void {
	if ( wp_installing() ) {
		return;
	}
	if ( get_option( OPTION_SCHEMA ) !== VERSION_SCHEMA ) {
		installer_tables();
	}
}
add_action( 'init', __NAMESPACE__ . '\\verifier_schema', 98 );

/**
 * Récurrence « toutes les 5 minutes » de l'envoi des e-mails.
 *
 * @param array $recurrences Récurrences.
 * @return array
 */
function recurrences( $recurrences ): array {
	$recurrences = (array) $recurrences;
	if ( ! isset( $recurrences[ RECURRENCE_ENVOI ] ) ) {
		$recurrences[ RECURRENCE_ENVOI ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Toutes les 5 minutes (Yume)', 'yume-core' ),
		);
	}
	return $recurrences;
}
add_filter( 'cron_schedules', __NAMESPACE__ . '\\recurrences' ); // phpcs:ignore WordPress.WP.CronInterval -- 5 minutes : file d'e-mails.

/**
 * Heure des rappels (0–23, heure de Paris).
 */
function heure_rappels(): int {
	$heure = function_exists( 'yume_setting' ) ? yume_setting( 'rappel_heure', 9 ) : 9;
	return is_numeric( $heure ) ? max( 0, min( 23, (int) $heure ) ) : 9;
}

/**
 * Jour du récapitulatif (0 = dimanche … 6 = samedi).
 */
function jour_digest(): int {
	$jour = function_exists( 'yume_setting' ) ? yume_setting( 'digest_jour', 1 ) : 1;
	return is_numeric( $jour ) ? max( 0, min( 6, (int) $jour ) ) : 1;
}

/**
 * Prochaine occurrence (strictement après $apres) d'une heure pile à Paris, un jour donné
 * de la semaine ou n'importe quel jour.
 *
 * @param int $heure Heure (0–23).
 * @param int $jour  Jour de la semaine (0 = dimanche) ou -1 pour tous les jours.
 * @param int $apres Horodatage de référence.
 */
function prochaine_occurrence( int $heure, int $jour, int $apres ): int {
	$d = ( new \DateTimeImmutable( '@' . $apres ) )->setTimezone( fuseau() )->setTime( $heure, 0, 0 );
	for ( $i = 0; $i < 8; $i++ ) {
		if ( $d->getTimestamp() > $apres && ( $jour < 0 || (int) $d->format( 'w' ) === $jour ) ) {
			return $d->getTimestamp();
		}
		$d = $d->modify( '+1 day' )->setTime( $heure, 0, 0 );
	}
	return $d->getTimestamp();
}

/**
 * Un horodatage tombe-t-il à l'heure pile attendue (Paris), le bon jour ?
 *
 * @param int $ts    Horodatage.
 * @param int $heure Heure attendue.
 * @param int $jour  Jour attendu (-1 : tous).
 */
function est_conforme( int $ts, int $heure, int $jour ): bool {
	$d = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( fuseau() );
	return (int) $d->format( 'G' ) === $heure && 0 === (int) $d->format( 'i' ) && ( $jour < 0 || (int) $d->format( 'w' ) === $jour );
}

/**
 * Vérifie qu'un événement récurrent est programmé à la bonne heure ; sinon le reprogramme
 * (réglage modifié, changement d'heure été/hiver).
 *
 * @param string $hook       Événement.
 * @param string $recurrence Récurrence (daily, weekly).
 * @param int    $heure      Heure (Paris).
 * @param int    $jour       Jour de la semaine ou -1.
 */
function verifier_evenement( string $hook, string $recurrence, int $heure, int $jour ): void {
	$evenement = wp_get_scheduled_event( $hook );
	if ( $evenement && $recurrence === $evenement->schedule && est_conforme( (int) $evenement->timestamp, $heure, $jour ) ) {
		return;
	}
	wp_clear_scheduled_hook( $hook );
	wp_schedule_event( prochaine_occurrence( $heure, $jour, maintenant() ), $recurrence, $hook );
}

/**
 * Planification idempotente des tâches du module.
 */
function planifier(): void {
	if ( wp_installing() ) {
		return;
	}
	verifier_evenement( HOOK_RAPPELS, 'daily', heure_rappels(), -1 );
	verifier_evenement( HOOK_DIGEST, 'weekly', heure_rappels(), jour_digest() );
	if ( ! wp_next_scheduled( HOOK_ENVOI ) ) {
		wp_schedule_event( maintenant() + MINUTE_IN_SECONDS, RECURRENCE_ENVOI, HOOK_ENVOI );
	}
}
add_action( 'init', __NAMESPACE__ . '\\planifier', 99 );

/**
 * Réglages Yume modifiés : l'heure ou le jour ont pu changer.
 */
function reglages_modifies(): void {
	planifier();
}
add_action( 'update_option_yume_reglages', __NAMESPACE__ . '\\reglages_modifies', 20, 0 );
add_action( 'add_option_yume_reglages', __NAMESPACE__ . '\\reglages_modifies', 20, 0 );

/**
 * Désactivation (action yume_core_deactivate) : suppression des tâches planifiées du module.
 */
function desactiver(): void {
	foreach ( array( HOOK_RAPPELS, HOOK_DIGEST, HOOK_ENVOI ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver' );
