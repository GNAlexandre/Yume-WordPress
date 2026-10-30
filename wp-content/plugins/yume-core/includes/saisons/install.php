<?php
/**
 * Tâche planifiée du module saisons : yume_saisons_quotidien, chaque jour à 00:05 heure du site,
 * vérifie le changement de saison (verifier_changement()). Planification idempotente sur init,
 * reprogrammée si l'heure dérive (changement d'heure été/hiver) ; supprimée à la désactivation.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Saisons;

defined( 'ABSPATH' ) || exit;

/** Heure et minute de la vérification quotidienne (heure du site). */
const HEURE_QUOTIDIEN  = 0;
const MINUTE_QUOTIDIEN = 5;

/**
 * Prochaine occurrence de 00:05 (heure du site) strictement après un horodatage.
 *
 * @param int $apres Horodatage de référence.
 */
function prochaine_verification( int $apres ): int {
	$jour = ( new \DateTimeImmutable( '@' . $apres ) )->setTimezone( wp_timezone() )->setTime( HEURE_QUOTIDIEN, MINUTE_QUOTIDIEN );
	if ( $jour->getTimestamp() <= $apres ) {
		$jour = $jour->modify( '+1 day' )->setTime( HEURE_QUOTIDIEN, MINUTE_QUOTIDIEN );
	}
	return $jour->getTimestamp();
}

/**
 * Un horodatage tombe-t-il à 00:05 heure du site ?
 *
 * @param int $ts Horodatage.
 */
function heure_conforme( int $ts ): bool {
	$d = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
	return HEURE_QUOTIDIEN === (int) $d->format( 'G' ) && MINUTE_QUOTIDIEN === (int) $d->format( 'i' );
}

/**
 * Planification idempotente de la tâche quotidienne (init).
 */
function planifier(): void {
	if ( wp_installing() ) {
		return;
	}
	$evenement = wp_get_scheduled_event( HOOK_QUOTIDIEN );
	if ( $evenement && 'daily' === $evenement->schedule && heure_conforme( (int) $evenement->timestamp ) ) {
		return;
	}
	wp_clear_scheduled_hook( HOOK_QUOTIDIEN );
	wp_schedule_event( prochaine_verification( time() ), 'daily', HOOK_QUOTIDIEN );
}
add_action( 'init', __NAMESPACE__ . '\\planifier', 99 );

/**
 * Installation ou montée de version (yume_core_install) : saison courante enregistrée, et
 * caches purgés si elle diffère de celle connue (première installation du module comprise).
 */
function installer(): void {
	verifier_changement();
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer' );

/**
 * Désactivation (yume_core_deactivate) : suppression de la tâche planifiée.
 */
function desactiver(): void {
	wp_clear_scheduled_hook( HOOK_QUOTIDIEN );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver' );
