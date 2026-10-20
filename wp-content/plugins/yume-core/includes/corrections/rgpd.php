<?php
/**
 * RGPD du module corrections :
 *
 * - export : clé signalements_coquilles de GET /moi/export (filtre yume_donnees_personnelles) et
 *   exportateur WordPress yume-core-coquilles (Outils → Exporter les données personnelles) ;
 * - suppression ou effacement du compte (action yume_effacer_donnees, deleted_user, effaceur
 *   WordPress yume-core-coquilles) : les signalements sont détachés du compte (user_id = 0), comme
 *   un commentaire anonymisé ; ils restent utiles à l'équipe ;
 * - la purge des signalements traités et des empreintes d'IP est faite par la tâche quotidienne
 *   (install.php).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

/**
 * Signalements d'un compte, pour l'export (paragraphe numéroté à partir de 1, dates GMT).
 *
 * @param int $user_id Compte.
 * @return array<int,array<string,mixed>>
 */
function signalements_du_compte( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array();
	}
	$lignes = lister_coquilles(
		array(
			'statut'  => 'tous',
			'user_id' => $user_id,
			'limite'  => 0,
		)
	)['lignes'];
	return array_map(
		static function ( array $ligne ): array {
			return array(
				'id'          => $ligne['id'],
				'chapitre_id' => $ligne['chapitre_id'],
				'chapitre'    => implode( ' › ', fil( $ligne ) ),
				'paragraphe'  => $ligne['paragraphe'] + 1,
				'extrait'     => $ligne['extrait'],
				'commentaire' => $ligne['commentaire'],
				'statut'      => $ligne['statut'],
				'cree_le'     => $ligne['cree_le'],
				'traite_le'   => $ligne['traite_le'],
			);
		},
		$lignes
	);
}

/**
 * Ajoute les signalements à l'export JSON du compte (filtre yume_donnees_personnelles).
 *
 * @param array $donnees Données exportées.
 * @param int   $user_id Compte.
 * @return array
 */
function exporter_dans_compte( $donnees, $user_id = 0 ): array {
	$donnees                           = is_array( $donnees ) ? $donnees : array();
	$donnees['signalements_coquilles'] = signalements_du_compte( (int) $user_id );
	return $donnees;
}
add_filter( 'yume_donnees_personnelles', __NAMESPACE__ . '\\exporter_dans_compte', 10, 2 );

/**
 * Déclare l'exportateur WordPress.
 *
 * @param array $exportateurs Exportateurs.
 * @return array
 */
function declarer_exportateur( $exportateurs ): array {
	$exportateurs                        = (array) $exportateurs;
	$exportateurs['yume-core-coquilles'] = array(
		'exporter_friendly_name' => __( 'Yume Novel — coquilles signalées', 'yume-core' ),
		'callback'               => __NAMESPACE__ . '\\exporter_donnees',
	);
	return $exportateurs;
}
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\declarer_exportateur' );

/**
 * Exportateur : signalements de coquilles du compte.
 *
 * @param string $email Adresse.
 * @param int    $page  Page (tout tient sur une page).
 * @return array{data:array,done:bool}
 */
function exporter_donnees( $email, $page = 1 ): array {
	unset( $page );
	$user  = get_user_by( 'email', (string) $email );
	$items = array();
	foreach ( $user ? signalements_du_compte( (int) $user->ID ) : array() as $s ) {
		$items[] = array(
			'group_id'    => 'yume-coquilles',
			'group_label' => __( 'Yume Novel — coquilles signalées', 'yume-core' ),
			'item_id'     => 'yume-coquille-' . $s['id'],
			'data'        => array(
				array(
					'name'  => __( 'Chapitre', 'yume-core' ),
					'value' => $s['chapitre'],
				),
				array(
					'name'  => __( 'Paragraphe', 'yume-core' ),
					'value' => (string) $s['paragraphe'],
				),
				array(
					'name'  => __( 'Passage', 'yume-core' ),
					'value' => $s['extrait'],
				),
				array(
					'name'  => __( 'Commentaire', 'yume-core' ),
					'value' => $s['commentaire'],
				),
				array(
					'name'  => __( 'Statut', 'yume-core' ),
					'value' => $s['statut'],
				),
				array(
					'name'  => __( 'Date (UTC)', 'yume-core' ),
					'value' => $s['cree_le'],
				),
			),
		);
	}
	return array(
		'data' => $items,
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
	$effaceurs                        = (array) $effaceurs;
	$effaceurs['yume-core-coquilles'] = array(
		'eraser_friendly_name' => __( 'Yume Novel — coquilles signalées', 'yume-core' ),
		'callback'             => __NAMESPACE__ . '\\effacer_donnees_email',
	);
	return $effaceurs;
}
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\declarer_effaceur' );

/**
 * Effaceur : les signalements sont détachés du compte.
 *
 * @param string $email Adresse.
 * @param int    $page  Page.
 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
 */
function effacer_donnees_email( $email, $page = 1 ): array {
	unset( $page );
	$user = get_user_by( 'email', (string) $email );
	$n    = $user ? anonymiser_coquilles( (int) $user->ID ) : 0;
	return array(
		'items_removed'  => $n > 0,
		'items_retained' => false,
		'messages'       => $n > 0 ? array( __( 'Les coquilles signalées sont conservées pour l’équipe, sans lien avec le compte.', 'yume-core' ) ) : array(),
		'done'           => true,
	);
}

/**
 * Effacement des données (action yume_effacer_donnees) ou compte supprimé (deleted_user).
 *
 * @param int $user_id Compte.
 */
function anonymiser_compte( $user_id ): void {
	anonymiser_coquilles( (int) $user_id );
}
add_action( 'yume_effacer_donnees', __NAMESPACE__ . '\\anonymiser_compte' );
add_action( 'deleted_user', __NAMESPACE__ . '\\anonymiser_compte' );
