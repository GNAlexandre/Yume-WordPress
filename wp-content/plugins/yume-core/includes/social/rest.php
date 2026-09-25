<?php
/**
 * Routes REST du module lecteurs (§12), toutes réservées au membre connecté :
 *
 * - GET    /yume/v1/moi                      résumé du compte ;
 * - POST   /yume/v1/moi/favoris/{oeuvre}     ajouter aux favoris ;
 * - DELETE /yume/v1/moi/favoris/{oeuvre}     retirer des favoris ;
 * - PUT    /yume/v1/moi/notes/{oeuvre}       noter (1–5, 0 = retirer) ;
 * - PUT    /yume/v1/moi/alertes/{oeuvre}     fréquence d'alerte (immediat, hebdo, jamais) ;
 * - GET    /yume/v1/moi/export               export JSON des données (RGPD) ;
 * - DELETE /yume/v1/moi                      suppression du compte (confirmation obligatoire).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

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
 * Argument « oeuvre » des routes /moi/…/{oeuvre}.
 */
function argument_oeuvre(): array {
	return array(
		'description'       => __( 'Identifiant de l’œuvre.', 'yume-core' ),
		'type'              => 'integer',
		'minimum'           => 1,
		'required'          => true,
		'validate_callback' => 'rest_validate_request_arg',
		'sanitize_callback' => 'rest_sanitize_request_arg',
	);
}

/**
 * Enregistre les routes du module.
 */
function enregistrer_routes(): void {
	register_rest_route(
		REST_NS,
		'/moi',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_moi',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\rest_supprimer_compte',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'confirmation' => array(
						'description'       => __( 'Saisir exactement SUPPRIMER.', 'yume-core' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'mot_de_passe' => array(
						'description' => __( 'Mot de passe actuel.', 'yume-core' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/favoris/(?P<oeuvre>\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_ajouter_favori',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array( 'oeuvre' => argument_oeuvre() ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\rest_retirer_favori',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array( 'oeuvre' => argument_oeuvre() ),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/notes/(?P<oeuvre>\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => __NAMESPACE__ . '\\rest_noter',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'oeuvre' => argument_oeuvre(),
					'note'   => array(
						'description'       => __( 'Note de 1 à 5 ; 0 retire la note.', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 0,
						'maximum'           => 5,
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
		'/moi/alertes/(?P<oeuvre>\d+)',
		array(
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => __NAMESPACE__ . '\\rest_alerte',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'oeuvre'    => argument_oeuvre(),
					'frequence' => array(
						'description'       => __( 'Fréquence des alertes pour cette œuvre.', 'yume-core' ),
						'type'              => 'string',
						'enum'              => FREQUENCES,
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
		'/moi/export',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_export',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * État d'une œuvre pour le membre courant (réponse des routes d'action).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<string,mixed>
 */
function etat_oeuvre( int $oeuvre_id ): array {
	$user_id   = get_current_user_id();
	$frequence = frequence_favori( $user_id, $oeuvre_id );
	$caches    = caches( $oeuvre_id );
	return array(
		'oeuvre'     => $oeuvre_id,
		'favori'     => '' !== $frequence,
		'frequence'  => '' !== $frequence ? $frequence : null,
		'note'       => note( $user_id, $oeuvre_id ),
		'nb_favoris' => $caches['favoris'],
		'moyenne'    => $caches['moyenne'],
		'nb_notes'   => $caches['notes'],
	);
}

/**
 * GET /moi : résumé du compte.
 *
 * @return \WP_REST_Response
 */
function rest_moi() {
	$user_id = get_current_user_id();
	$user    = wp_get_current_user();

	$favoris = array();
	foreach ( favoris_utilisateur( $user_id ) as $ligne ) {
		if ( ! oeuvre_publiee( $ligne['oeuvre_id'] ) ) {
			continue;
		}
		$favoris[] = array(
			'oeuvre'    => $ligne['oeuvre_id'],
			'titre'     => wp_strip_all_tags( get_the_title( $ligne['oeuvre_id'] ) ),
			'url'       => (string) get_permalink( $ligne['oeuvre_id'] ),
			'frequence' => $ligne['frequence'],
			'note'      => note( $user_id, $ligne['oeuvre_id'] ),
			'ajoute_le' => iso( $ligne['created_at'] ),
		);
	}

	$notes = array();
	foreach ( notes_utilisateur( $user_id ) as $ligne ) {
		if ( ! oeuvre_publiee( $ligne['oeuvre_id'] ) ) {
			continue;
		}
		$notes[] = array(
			'oeuvre' => $ligne['oeuvre_id'],
			'titre'  => wp_strip_all_tags( get_the_title( $ligne['oeuvre_id'] ) ),
			'note'   => $ligne['note'],
			'le'     => iso( $ligne['updated_at'] ),
		);
	}

	$lecture = array();
	if ( function_exists( '\Yume\Core\Reader\enrichir_ligne' ) ) {
		foreach ( yume_get_progression( $user_id ) as $ligne ) {
			$enrichie = \Yume\Core\Reader\enrichir_ligne( $ligne );
			if ( $enrichie ) {
				$lecture[] = $enrichie;
			}
		}
	}

	return rest_ensure_response(
		array(
			'id'          => $user_id,
			'pseudo'      => (string) $user->display_name,
			'email'       => (string) $user->user_email,
			'inscrit_le'  => iso( (string) $user->user_registered ),
			'equipe'      => est_equipe( $user_id ),
			'suppression' => peut_supprimer_compte( $user_id ),
			'favoris'     => $favoris,
			'notes'       => $notes,
			'lecture'     => $lecture,
			'reglages'    => function_exists( '\Yume\Core\Reader\reglages_utilisateur' ) ? \Yume\Core\Reader\reglages_utilisateur( $user_id ) : null,
			'alertes'     => preferences_alertes( $user_id ),
			'emails'      => emails_actifs(),
			'liens'       => array(
				'compte' => url_compte(),
				'export' => esc_url_raw( rest_url( REST_NS . '/moi/export' ) ),
			),
		)
	);
}

/**
 * POST /moi/favoris/{oeuvre}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_ajouter_favori( \WP_REST_Request $requete ) {
	$oeuvre_id = (int) $requete['oeuvre'];
	$deja      = est_favori( get_current_user_id(), $oeuvre_id );
	$resultat  = ajouter_favori( get_current_user_id(), $oeuvre_id );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	$reponse = rest_ensure_response( etat_oeuvre( $oeuvre_id ) );
	$reponse->set_status( $deja ? 200 : 201 );
	return $reponse;
}

/**
 * DELETE /moi/favoris/{oeuvre}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_retirer_favori( \WP_REST_Request $requete ) {
	$oeuvre_id = (int) $requete['oeuvre'];
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return erreur_oeuvre();
	}
	retirer_favori( get_current_user_id(), $oeuvre_id );
	return rest_ensure_response( etat_oeuvre( $oeuvre_id ) );
}

/**
 * PUT /moi/notes/{oeuvre}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_noter( \WP_REST_Request $requete ) {
	$oeuvre_id = (int) $requete['oeuvre'];
	$resultat  = noter( get_current_user_id(), $oeuvre_id, (int) $requete['note'] );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	return rest_ensure_response( etat_oeuvre( $oeuvre_id ) );
}

/**
 * PUT /moi/alertes/{oeuvre}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_alerte( \WP_REST_Request $requete ) {
	$oeuvre_id = (int) $requete['oeuvre'];
	$resultat  = definir_frequence( get_current_user_id(), $oeuvre_id, (string) $requete['frequence'] );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	return rest_ensure_response( etat_oeuvre( $oeuvre_id ) );
}

/**
 * GET /moi/export : toutes les données du membre (téléchargement JSON).
 *
 * @return \WP_REST_Response
 */
function rest_export() {
	$reponse = rest_ensure_response( donnees_personnelles( get_current_user_id() ) );
	$reponse->header( 'Content-Disposition', 'attachment; filename="yume-mes-donnees-' . gmdate( 'Y-m-d' ) . '.json"' );
	$reponse->header( 'Cache-Control', 'no-store, private' );
	return $reponse;
}

/**
 * DELETE /moi : suppression du compte. Exige confirmation = « SUPPRIMER » et le mot de passe
 * actuel ; toujours refusée pour l'équipe et les administrateurs.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_supprimer_compte( \WP_REST_Request $requete ) {
	$user = wp_get_current_user();
	if ( ! peut_supprimer_compte( (int) $user->ID ) ) {
		return new \WP_Error( 'yume_suppression_interdite', __( 'Les comptes de l’équipe et des administrateurs ne peuvent pas être supprimés depuis cette page.', 'yume-core' ), array( 'status' => 403 ) );
	}
	if ( CONFIRMATION_SUPPRESSION !== trim( (string) $requete['confirmation'] ) ) {
		return new \WP_Error( 'yume_confirmation', __( 'Confirmation manquante : saisissez exactement SUPPRIMER.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! wp_check_password( (string) $requete['mot_de_passe'], $user->user_pass, $user->ID ) ) {
		return new \WP_Error( 'yume_mot_de_passe', __( 'Mot de passe actuel incorrect.', 'yume-core' ), array( 'status' => 403 ) );
	}
	$user_id  = (int) $user->ID;
	$resultat = supprimer_compte( $user_id );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	wp_clear_auth_cookie();
	wp_set_current_user( 0 );
	return rest_ensure_response(
		array(
			'supprime' => true,
			'id'       => $user_id,
		)
	);
}
