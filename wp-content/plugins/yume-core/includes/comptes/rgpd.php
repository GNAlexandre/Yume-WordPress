<?php
/**
 * Données personnelles du module comptes : métas META_ACTIVITE (jour de dernière activité) et
 * META_SOURCE (source d'inscription). Export : clés activite et source_inscription de l'export
 * JSON du compte (filtre yume_donnees_personnelles, GET /moi/export) et exportateur WordPress
 * yume-core-comptes (Outils → Exporter les données personnelles). Effacement : action
 * yume_effacer_donnees (émise par Social\effacer_donnees(), donc aussi par l'effaceur WordPress
 * du module social et par la suppression du compte en façade).
 *
 * Seules les valeurs enregistrées sont exportées : une source « déduite » (source_compte())
 * n'est pas une donnée conservée. Le journal des consultations (OPTION_JOURNAL) concerne
 * l'équipe qui consulte, pas le compte consulté.
 *
 * Contrat : docs/06-contrat-technique.md §8 septies.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

defined( 'ABSPATH' ) || exit;

/**
 * Données conservées par le module pour un compte : jour de dernière activité ('Y-m-d', heure du
 * site) et source d'inscription (inscription|discord|equipe), null quand absentes.
 *
 * @param int $user_id Compte.
 * @return array{activite:?string,source_inscription:?string}
 */
function rgpd_donnees( int $user_id ): array {
	$activite = derniere_activite( $user_id );
	$source   = $user_id > 0 ? get_user_meta( $user_id, META_SOURCE, true ) : '';
	return array(
		'activite'           => '' !== $activite ? $activite : null,
		'source_inscription' => is_string( $source ) && in_array( $source, SOURCES, true ) ? $source : null,
	);
}

/**
 * Export JSON du compte (filtre yume_donnees_personnelles) : clés activite et
 * source_inscription, sans modifier les clés existantes.
 *
 * @param array|mixed $donnees Données exportées.
 * @param int|mixed   $user_id Compte.
 * @return array|mixed
 */
function rgpd_export_json( $donnees, $user_id = 0 ) {
	if ( ! is_array( $donnees ) ) {
		return $donnees;
	}
	foreach ( rgpd_donnees( (int) $user_id ) as $cle => $valeur ) {
		if ( ! array_key_exists( $cle, $donnees ) ) {
			$donnees[ $cle ] = $valeur;
		}
	}
	return $donnees;
}
add_filter( 'yume_donnees_personnelles', __NAMESPACE__ . '\\rgpd_export_json', 10, 2 );

/**
 * Efface les métas du module (action yume_effacer_donnees).
 *
 * @param int|mixed $user_id Compte.
 * @return int Nombre de métas supprimées.
 */
function rgpd_effacer( $user_id ): int {
	$user_id = (int) $user_id;
	$n       = 0;
	if ( $user_id <= 0 ) {
		return 0;
	}
	foreach ( array( META_ACTIVITE, META_SOURCE ) as $cle ) {
		if ( metadata_exists( 'user', $user_id, $cle ) && delete_user_meta( $user_id, $cle ) ) {
			++$n;
		}
	}
	return $n;
}
add_action( 'yume_effacer_donnees', __NAMESPACE__ . '\\rgpd_effacer' );

/**
 * Déclare l'exportateur WordPress du module.
 *
 * @param array|mixed $exportateurs Exportateurs.
 * @return array
 */
function rgpd_declarer_exportateur( $exportateurs ): array {
	$exportateurs                      = (array) $exportateurs;
	$exportateurs['yume-core-comptes'] = array(
		'exporter_friendly_name' => __( 'Yume Novel — activité du compte', 'yume-core' ),
		'callback'               => __NAMESPACE__ . '\\rgpd_exportateur',
	);
	return $exportateurs;
}
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\rgpd_declarer_exportateur' );

/**
 * Exportateur WordPress : jour de dernière activité et source d'inscription (rien si aucune
 * des deux n'est enregistrée).
 *
 * @param string $email Adresse.
 * @param int    $page  Page (tout tient sur une page).
 * @return array{data:array,done:bool}
 */
function rgpd_exportateur( $email, $page = 1 ): array {
	unset( $page ); // Tout tient sur une seule page.
	$user    = get_user_by( 'email', (string) $email );
	$donnees = $user ? rgpd_donnees( (int) $user->ID ) : array();
	$champs  = array();
	if ( null !== ( $donnees['activite'] ?? null ) ) {
		$champs[] = array(
			'name'  => __( 'Dernière activité (jour)', 'yume-core' ),
			'value' => $donnees['activite'],
		);
	}
	if ( null !== ( $donnees['source_inscription'] ?? null ) ) {
		$champs[] = array(
			'name'  => __( 'Source d’inscription', 'yume-core' ),
			'value' => libelles_sources()[ $donnees['source_inscription'] ] ?? $donnees['source_inscription'],
		);
	}
	if ( ! $user || ! $champs ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
	return array(
		'data' => array(
			array(
				'group_id'    => 'yume-comptes',
				'group_label' => __( 'Yume Novel — activité du compte', 'yume-core' ),
				'item_id'     => 'yume-comptes-' . $user->ID,
				'data'        => $champs,
			),
		),
		'done' => true,
	);
}
