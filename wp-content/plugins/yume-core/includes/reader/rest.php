<?php
/**
 * Routes REST du module lecture (§12) :
 *
 * - GET, PUT /yume/v1/moi/reglages    : réglages de lecture du membre connecté ;
 * - GET, PUT /yume/v1/moi/progression : positions de lecture (une par œuvre).
 *
 * Authentification par cookie + nonce wp_rest (en-tête X-WP-Nonce) ou mot de passe
 * d'application. Les valeurs hors bornes sont refusées (400) par la validation du schéma.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/** Espace de noms REST commun. */
const REST_NS = 'yume/v1';

/**
 * Permission : membre connecté.
 *
 * @return true|\WP_Error
 */
function permission_connecte() {
	if ( is_user_logged_in() ) {
		return true;
	}
	return new \WP_Error( 'rest_forbidden', __( 'Connectez-vous pour accéder à cette ressource.', 'yume-core' ), array( 'status' => rest_authorization_required_code() ) );
}

/**
 * Arguments de validation d'un champ (validation et assainissement standards de la REST).
 *
 * @param array $schema Schéma JSON du champ.
 */
function argument( array $schema ): array {
	return array_merge(
		$schema,
		array(
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		)
	);
}

/**
 * Schéma des paramètres de PUT /moi/reglages.
 */
function arguments_reglages(): array {
	$bornes = bornes_reglages();
	return array(
		'size'          => argument(
			array(
				'description' => __( 'Taille du texte en pixels.', 'yume-core' ),
				'type'        => 'integer',
				'minimum'     => (int) $bornes['size'][0],
				'maximum'     => (int) $bornes['size'][1],
			)
		),
		'lh'            => argument(
			array(
				'description' => __( 'Interligne (sans unité).', 'yume-core' ),
				'type'        => 'number',
				'minimum'     => $bornes['lh'][0],
				'maximum'     => $bornes['lh'][1],
			)
		),
		'font'          => argument(
			array(
				'description' => __( 'Police de lecture (liste blanche).', 'yume-core' ),
				'type'        => 'string',
				'enum'        => array_keys( polices() ),
			)
		),
		'width'         => argument(
			array(
				'description' => __( 'Largeur de colonne en caractères.', 'yume-core' ),
				'type'        => 'integer',
				'minimum'     => (int) $bornes['width'][0],
				'maximum'     => (int) $bornes['width'][1],
			)
		),
		'bgAlpha'       => argument(
			array(
				'description' => __( 'Opacité du fond de la colonne (0,6 à 1).', 'yume-core' ),
				'type'        => 'number',
				'minimum'     => $bornes['bgAlpha'][0],
				'maximum'     => $bornes['bgAlpha'][1],
			)
		),
		'theme'         => argument(
			array(
				'description' => __( 'Thème de lecture.', 'yume-core' ),
				'type'        => 'string',
				'enum'        => THEMES,
			)
		),
		'reinitialiser' => argument(
			array(
				'description' => __( 'Revenir aux réglages par défaut.', 'yume-core' ),
				'type'        => 'boolean',
				'default'     => false,
			)
		),
	);
}

/**
 * Enregistre les routes du module.
 */
function enregistrer_routes(): void {
	register_rest_route(
		REST_NS,
		'/moi/reglages',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_lire_reglages',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => __NAMESPACE__ . '\\rest_ecrire_reglages',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => arguments_reglages(),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/progression',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_lire_progression',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'oeuvre' => argument(
						array(
							'description' => __( 'Limiter à une œuvre.', 'yume-core' ),
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
						)
					),
				),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => __NAMESPACE__ . '\\rest_ecrire_progression',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'chapitre_id' => argument(
						array(
							'description' => __( 'Chapitre publié en cours de lecture.', 'yume-core' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'required'    => true,
						)
					),
					'paragraphe'  => argument(
						array(
							'description' => __( 'Index (à partir de 0) du paragraphe atteint.', 'yume-core' ),
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => PARAGRAPHE_MAX,
							'default'     => 0,
						)
					),
					'pourcentage' => argument(
						array(
							'description' => __( 'Pourcentage lu du chapitre.', 'yume-core' ),
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => 100,
							'default'     => 0,
						)
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * GET /moi/reglages.
 *
 * @return \WP_REST_Response
 */
function rest_lire_reglages() {
	return rest_ensure_response( reglages_utilisateur( get_current_user_id() ) );
}

/**
 * PUT /moi/reglages : mise à jour partielle ou réinitialisation.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_ecrire_reglages( \WP_REST_Request $requete ) {
	$user_id = get_current_user_id();
	if ( $requete->get_param( 'reinitialiser' ) ) {
		effacer_reglages( $user_id );
		return rest_ensure_response( defauts_reglages() );
	}
	$valeurs = array();
	foreach ( array( 'size', 'lh', 'font', 'width', 'bgAlpha', 'theme' ) as $cle ) {
		$valeur = $requete->get_param( $cle );
		if ( null !== $valeur ) {
			$valeurs[ $cle ] = $valeur;
		}
	}
	return rest_ensure_response( enregistrer_reglages( $user_id, $valeurs ) );
}

/**
 * GET /moi/progression : positions (enrichies) du membre, de la plus récente à la plus
 * ancienne ; les chapitres qui ne sont plus publiés sont omis.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_lire_progression( \WP_REST_Request $requete ) {
	$lignes = array();
	foreach ( lignes_progression( get_current_user_id(), (int) $requete->get_param( 'oeuvre' ) ) as $ligne ) {
		$enrichie = enrichir_ligne( $ligne );
		if ( $enrichie ) {
			$lignes[] = $enrichie;
		}
	}
	return rest_ensure_response( $lignes );
}

/**
 * PUT /moi/progression : enregistre la position (œuvre et tome déduits du chapitre).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_ecrire_progression( \WP_REST_Request $requete ) {
	$ligne = enregistrer_progression(
		get_current_user_id(),
		(int) $requete->get_param( 'chapitre_id' ),
		(int) $requete->get_param( 'paragraphe' ),
		(int) $requete->get_param( 'pourcentage' )
	);
	if ( is_wp_error( $ligne ) ) {
		return $ligne;
	}
	return rest_ensure_response( enrichir_ligne( $ligne ) ?? $ligne );
}
