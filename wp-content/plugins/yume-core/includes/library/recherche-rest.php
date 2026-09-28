<?php
/**
 * Route REST publique des suggestions instantanées de la recherche (AMEL-04, contrat §12) :
 *
 * GET /yume/v1/suggestions?q=… : 8 suggestions au plus (œuvres par titre et titres
 * alternatifs, puis tomes), contenus publiés seulement, à partir de 2 caractères.
 *
 * Réponses mises en cache 5 minutes (transient, voir suggestions()) ; débit limité par adresse
 * IP (hachée avec le sel du site, jamais stockée en clair) : 60 demandes par minute par défaut
 * (filtre yume_suggestions_limite), au-delà 429.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre la route des suggestions.
 */
function enregistrer_route_suggestions(): void {
	register_rest_route(
		'yume/v1',
		'/suggestions',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_suggestions',
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'description'       => __( 'Début de la recherche (2 caractères au moins).', 'yume-core' ),
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 200,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => __NAMESPACE__ . '\\nettoyer_terme',
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_route_suggestions' );

/**
 * Limitation de débit des suggestions : compte la demande et indique si la limite est dépassée.
 */
function debit_suggestions_depasse(): bool {
	/**
	 * Nombre de demandes de suggestions autorisées par minute et par adresse IP (0 : sans limite).
	 *
	 * @param int $max Défaut : 60.
	 */
	$max = (int) apply_filters( 'yume_suggestions_limite', 60 );
	if ( $max <= 0 ) {
		return false;
	}
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip   = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	$cle  = 'yume_sugg_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	$etat = get_transient( $cle );
	$etat = is_array( $etat ) && isset( $etat['n'], $etat['fin'] ) && (int) $etat['fin'] > time() ? $etat : array(
		'n'   => 0,
		'fin' => time() + MINUTE_IN_SECONDS,
	);
	if ( (int) $etat['n'] >= $max ) {
		return true;
	}
	$etat['n'] = (int) $etat['n'] + 1;
	set_transient( $cle, $etat, max( 1, (int) $etat['fin'] - time() ) );
	return false;
}

/**
 * GET /yume/v1/suggestions.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_suggestions( \WP_REST_Request $requete ) {
	if ( debit_suggestions_depasse() ) {
		return new \WP_Error( 'yume_trop_de_requetes', __( 'Trop de demandes : réessayez dans un instant.', 'yume-core' ), array( 'status' => 429 ) );
	}
	$terme     = nettoyer_terme( $requete->get_param( 'q' ) );
	$resultats = suggestions( $terme );
	$reponse   = rest_ensure_response(
		array(
			'q'           => $terme,
			'total'       => count( $resultats ),
			'suggestions' => $resultats,
			'recherche'   => '' !== $terme ? add_query_arg( 's', rawurlencode( $terme ), home_url( '/' ) ) : '',
		)
	);
	$reponse->header( 'X-Robots-Tag', 'noindex' );
	return $reponse;
}
