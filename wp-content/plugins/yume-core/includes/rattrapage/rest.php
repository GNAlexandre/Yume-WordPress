<?php
/**
 * Route REST du rattrapage (§12) : GET /yume/v1/moi/rattrapage?limite= (membres connectés,
 * limite de 1 à 50, 6 par défaut) → {total, oeuvres: […]} (tableau de rattrapage()), en-tête
 * Cache-Control: private, no-store. Même calcul et même cache que le bloc et le compte.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Rattrapage;

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
 * Enregistre la route.
 */
function enregistrer_routes(): void {
	register_rest_route(
		REST_NS,
		'/moi/rattrapage',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_rattrapage',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'limite' => array(
						'description'       => __( 'Nombre de séries au plus.', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => LIMITE_MAX,
						'default'           => 6,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * GET /moi/rattrapage.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_rattrapage( \WP_REST_Request $requete ) {
	$reponse = rest_ensure_response( rattrapage( get_current_user_id(), (int) $requete->get_param( 'limite' ) ) );
	$reponse->header( 'Cache-Control', 'private, no-store' );
	return $reponse;
}
