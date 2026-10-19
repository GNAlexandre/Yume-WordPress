<?php
/**
 * Route REST du module corrections :
 *
 * - POST /yume/v1/chapitres/{chapitre}/coquilles  signaler une coquille (membre connecté, nonce
 *   REST en X-WP-Nonce ; visiteur seulement avec le réglage coquilles_anonymes, jeton horodaté et
 *   pot de miel). Corps {paragraphe, extrait, contexte, commentaire} → 201 {id, message}.
 *
 * Erreurs : voir signaler_coquille() (fonctions.php) et docs/06-contrat-technique.md §12.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

/** Espace de noms REST commun. */
const REST_NS = 'yume/v1';

/**
 * Permission : membre connecté, ou visiteur quand le mode anonyme est activé.
 *
 * @return true|\WP_Error
 */
function permission_signaler() {
	if ( is_user_logged_in() || anonymes_autorises() ) {
		return true;
	}
	return new \WP_Error( 'rest_forbidden', __( 'Connectez-vous pour signaler une coquille.', 'yume-core' ), array( 'status' => rest_authorization_required_code() ) );
}

/**
 * Déclare la route.
 */
function enregistrer_routes(): void {
	$texte = static function ( string $description ): array {
		return array(
			'description'       => $description,
			'type'              => 'string',
			'default'           => '',
			'validate_callback' => 'rest_validate_request_arg',
		);
	};
	register_rest_route(
		REST_NS,
		'/chapitres/(?P<chapitre>\d+)/coquilles',
		array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_signaler',
				'permission_callback' => __NAMESPACE__ . '\\permission_signaler',
				'args'                => array(
					'chapitre'    => array(
						'description'       => __( 'Identifiant du chapitre.', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'paragraphe'  => array(
						'description'       => __( 'Index du paragraphe (0 pour le premier).', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 0,
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'extrait'     => array_merge( $texte( __( 'Passage signalé (3 à 300 caractères).', 'yume-core' ) ), array( 'required' => true ) ),
					'contexte'    => $texte( __( 'Texte du paragraphe (600 caractères au plus).', 'yume-core' ) ),
					'commentaire' => array_merge( $texte( __( 'Commentaire facultatif (500 caractères au plus).', 'yume-core' ) ), array( 'maxLength' => COMMENTAIRE_MAX ) ),
					'jeton'       => $texte( __( 'Jeton horodaté du formulaire (mode anonyme).', 'yume-core' ) ),
					'yn_site_web' => $texte( __( 'Champ piège, toujours vide.', 'yume-core' ) ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * POST /chapitres/{chapitre}/coquilles.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_signaler( \WP_REST_Request $requete ) {
	$donnees = array();
	foreach ( array( 'paragraphe', 'extrait', 'contexte', 'commentaire', 'jeton', 'yn_site_web' ) as $cle ) {
		if ( null !== $requete[ $cle ] ) {
			$donnees[ $cle ] = $requete[ $cle ];
		}
	}
	$resultat = signaler_coquille( (int) $requete['chapitre'], get_current_user_id(), $donnees );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	$reponse = new \WP_REST_Response( $resultat, 201 );
	$reponse->header( 'Cache-Control', 'no-store, private' );
	return $reponse;
}
