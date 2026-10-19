<?php
/**
 * Données personnelles de la connexion Discord : métas yume_discord_id, yume_discord_pseudo et
 * yume_discord_lie_le (aucun jeton, aucun avatar). Export (clé discord de GET /moi/export,
 * exportateur WordPress yume-core-discord) et effacement (yume_effacer_donnees,
 * yume_compte_suppression, deleted_user, effaceur WordPress yume-core-discord).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

/**
 * Données Discord d'un membre (null s'il n'est pas lié).
 *
 * @param int $user_id Membre.
 * @return array{id:string,pseudo:string,lie_le:string}|null
 */
function donnees_discord( int $user_id ): ?array {
	$discord_id = discord_lie( $user_id );
	if ( '' === $discord_id ) {
		return null;
	}
	$le = (string) get_user_meta( $user_id, META_LIE_LE, true );
	return array(
		'id'     => $discord_id,
		'pseudo' => (string) get_user_meta( $user_id, META_PSEUDO, true ),
		'lie_le' => '' !== $le && false !== strtotime( $le . ' UTC' ) ? gmdate( 'c', (int) strtotime( $le . ' UTC' ) ) : '',
	);
}

/**
 * Export JSON du compte (GET /moi/export) : clé discord.
 *
 * @param array $donnees Données exportées.
 * @param int   $user_id Membre.
 * @return array
 */
function exporter_json( $donnees, $user_id = 0 ) {
	if ( ! is_array( $donnees ) ) {
		return $donnees;
	}
	$donnees['discord'] = donnees_discord( (int) $user_id );
	return $donnees;
}
add_filter( 'yume_donnees_personnelles', __NAMESPACE__ . '\\exporter_json', 10, 2 );

/**
 * Efface la liaison Discord (sans action yume_discord_delie : effacement des données).
 *
 * @param int $user_id Membre.
 */
function effacer( $user_id ): void {
	effacer_metas( (int) $user_id );
}
add_action( 'yume_effacer_donnees', __NAMESPACE__ . '\\effacer' );
add_action( 'yume_compte_suppression', __NAMESPACE__ . '\\effacer' );
add_action( 'deleted_user', __NAMESPACE__ . '\\effacer' );

/**
 * Déclare l'exportateur WordPress.
 *
 * @param array $exportateurs Exportateurs.
 * @return array
 */
function declarer_exportateur( $exportateurs ): array {
	$exportateurs                      = (array) $exportateurs;
	$exportateurs['yume-core-discord'] = array(
		'exporter_friendly_name' => __( 'Yume Novel — connexion Discord', 'yume-core' ),
		'callback'               => __NAMESPACE__ . '\\exporter_donnees',
	);
	return $exportateurs;
}
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\declarer_exportateur' );

/**
 * Exportateur WordPress : compte Discord lié.
 *
 * @param string $email Adresse.
 * @param int    $page  Page (tout tient sur une page).
 * @return array{data:array,done:bool}
 */
function exporter_donnees( $email, $page = 1 ): array {
	unset( $page );
	$user    = get_user_by( 'email', (string) $email );
	$donnees = $user ? donnees_discord( (int) $user->ID ) : null;
	if ( null === $donnees ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
	return array(
		'data' => array(
			array(
				'group_id'    => 'yume-discord',
				'group_label' => __( 'Yume Novel — connexion Discord', 'yume-core' ),
				'item_id'     => 'yume-discord-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Identifiant Discord', 'yume-core' ),
						'value' => $donnees['id'],
					),
					array(
						'name'  => __( 'Nom d’utilisateur Discord', 'yume-core' ),
						'value' => $donnees['pseudo'],
					),
					array(
						'name'  => __( 'Lié le', 'yume-core' ),
						'value' => $donnees['lie_le'],
					),
				),
			),
		),
		'done' => true,
	);
}

/**
 * Déclare l'effaceur WordPress.
 *
 * @param array $effaceurs Effaceurs.
 * @return array
 */
function declarer_effaceur( $effaceurs ): array {
	$effaceurs                      = (array) $effaceurs;
	$effaceurs['yume-core-discord'] = array(
		'eraser_friendly_name' => __( 'Yume Novel — connexion Discord', 'yume-core' ),
		'callback'             => __NAMESPACE__ . '\\effacer_donnees_email',
	);
	return $effaceurs;
}
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\declarer_effaceur' );

/**
 * Effaceur WordPress : liaison Discord retirée.
 *
 * @param string $email Adresse.
 * @param int    $page  Page.
 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
 */
function effacer_donnees_email( $email, $page = 1 ): array {
	unset( $page );
	$user = get_user_by( 'email', (string) $email );
	$n    = $user ? effacer_metas( (int) $user->ID ) : 0;
	return array(
		'items_removed'  => $n > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}
