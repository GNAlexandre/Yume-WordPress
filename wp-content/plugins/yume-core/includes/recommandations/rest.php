<?php
/**
 * Routes REST des recommandations (docs/06 §12), lecture seule :
 *
 * - GET /yume/v1/recommandations/{oeuvre}  publique : {oeuvre, recommandations: [{id, titre, url,
 *   image, type, genres_communs, cofavoris, score}]}, même réponse pour tous (jamais
 *   personnalisée, même pour un membre connecté), « Cache-Control: public, max-age=3600 » ;
 *   404 yume_oeuvre_introuvable si l'œuvre n'est pas publiée ;
 * - GET /yume/v1/moi/recommandations       membre connecté : recommandations_membre() (avec
 *   parce_que), « Cache-Control: private, no-store ».
 *
 * @package Yume\Core
 */

namespace Yume\Core\Recommandations;

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
 * Enregistre les routes du module.
 */
function enregistrer_routes(): void {
	register_rest_route(
		REST_NS,
		'/recommandations/(?P<oeuvre>\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_oeuvre',
				'permission_callback' => '__return_true',
				'args'                => array(
					'oeuvre' => array(
						'description'       => __( 'Identifiant de l’œuvre.', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			),
		)
	);
	register_rest_route(
		REST_NS,
		'/moi/recommandations',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_membre',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * GET /recommandations/{oeuvre} : recommandations publiques d'une œuvre publiée.
 *
 * @param \WP_REST_Request $request Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_oeuvre( \WP_REST_Request $request ) {
	$oeuvre_id = (int) $request['oeuvre'];
	if ( TYPE_OEUVRE !== get_post_type( $oeuvre_id ) || 'publish' !== get_post_status( $oeuvre_id ) ) {
		return new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	$reponse = rest_ensure_response(
		array(
			'oeuvre'          => $oeuvre_id,
			// Toujours la liste des visiteurs : la réponse est mise en cache publiquement.
			'recommandations' => recommandations_oeuvre( $oeuvre_id, 4, 0 ),
		)
	);
	$reponse->header( 'Cache-Control', 'public, max-age=3600' );
	return $reponse;
}

/**
 * GET /moi/recommandations : rubrique « Pour vous » du membre connecté.
 *
 * @return \WP_REST_Response
 */
function rest_membre() {
	$reponse = rest_ensure_response( recommandations_membre( get_current_user_id(), 6 ) );
	$reponse->header( 'Cache-Control', 'private, no-store' );
	return $reponse;
}
