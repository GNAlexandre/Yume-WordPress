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
 * - POST   /yume/v1/commentaires/{id}/signalement  signaler un commentaire publié (motif
 *                                            facultatif ; voir moderation.php).
 * - GET    /yume/v1/moi/listes               listes de lecture (oeuvre : présence de l'œuvre) ;
 * - POST   /yume/v1/moi/listes               créer une liste (nom, description, publique) ;
 * - PATCH  /yume/v1/moi/listes/{id}          modifier une liste ;
 * - DELETE /yume/v1/moi/listes/{id}          supprimer une liste personnelle ;
 * - PUT    /yume/v1/moi/listes/{id}/oeuvres/{oeuvre}  ajouter une œuvre à une liste ;
 * - DELETE /yume/v1/moi/listes/{id}/oeuvres/{oeuvre}  retirer une œuvre (listes.php) ;
 * - GET    /yume/v1/moi/notifications        notifications (page, limite, non_lues) ;
 * - POST   /yume/v1/moi/notifications/lues   marquer comme lues (ids, ou toutes) ;
 * - POST   /yume/v1/moi/push                 abonnement Web Push de l'appareil ;
 * - DELETE /yume/v1/moi/push                 désabonnement de l'appareil ;
 * - GET    /yume/v1/push/cle                 clé publique VAPID (publique ; push.php).
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

	register_rest_route(
		REST_NS,
		'/commentaires/(?P<id>\\d+)/signalement',
		array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_signaler_commentaire',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'id'    => array(
						'description'       => __( 'Identifiant du commentaire.', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'motif' => array(
						'description'       => __( 'Motif du signalement (facultatif, 200 caractères au plus).', 'yume-core' ),
						'type'              => 'string',
						'maxLength'         => MOTIF_SIGNALEMENT_MAX,
						'default'           => '',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
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

/**
 * POST /commentaires/{id}/signalement : signale un commentaire publié (voir
 * signaler_commentaire()).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_signaler_commentaire( \WP_REST_Request $requete ) {
	$resultat = signaler_commentaire( (int) $requete['id'], get_current_user_id(), (string) $requete['motif'] );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	return rest_ensure_response(
		array(
			'signale' => true,
			'attente' => $resultat['attente'],
			'message' => $resultat['message'],
		)
	);
}

/*
 * -----------------------------------------------------------------------------
 * Listes de lecture, notifications et Web Push (lot P3-D)
 * -----------------------------------------------------------------------------
 */

/**
 * Argument « id » d'une liste.
 */
function argument_liste(): array {
	return array(
		'description'       => __( 'Identifiant de la liste.', 'yume-core' ),
		'type'              => 'integer',
		'minimum'           => 1,
		'required'          => true,
		'validate_callback' => 'rest_validate_request_arg',
		'sanitize_callback' => 'rest_sanitize_request_arg',
	);
}

/**
 * Arguments modifiables d'une liste (nom, description, publique).
 *
 * @param bool $creation Nom obligatoire (création).
 */
function arguments_liste( bool $creation ): array {
	return array(
		'nom'         => array(
			'description'       => __( 'Nom de la liste.', 'yume-core' ),
			'type'              => 'string',
			'minLength'         => 1,
			'maxLength'         => LISTE_NOM_MAX,
			'required'          => $creation,
			'validate_callback' => 'rest_validate_request_arg',
		),
		'description' => array(
			'description'       => __( 'Description courte.', 'yume-core' ),
			'type'              => 'string',
			'maxLength'         => LISTE_DESCRIPTION_MAX,
			'validate_callback' => 'rest_validate_request_arg',
		),
		'publique'    => array(
			'description'       => __( 'Liste publique (partageable) ?', 'yume-core' ),
			'type'              => 'boolean',
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		),
	);
}

/**
 * Enregistre les routes des listes, des notifications du lecteur et du Web Push.
 */
function enregistrer_routes_lecteur(): void {
	register_rest_route(
		REST_NS,
		'/moi/listes',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_listes',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'oeuvre' => array(
						'description'       => __( 'Œuvre dont on veut savoir si elle est dans chaque liste.', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_creer_liste',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => arguments_liste( true ),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/listes/(?P<id>\\d+)',
		array(
			array(
				'methods'             => 'PATCH',
				'callback'            => __NAMESPACE__ . '\\rest_modifier_liste',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array_merge( array( 'id' => argument_liste() ), arguments_liste( false ) ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\rest_supprimer_liste',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array( 'id' => argument_liste() ),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/listes/(?P<id>\\d+)/oeuvres/(?P<oeuvre>\\d+)',
		array(
			array(
				'methods'             => 'PUT',
				'callback'            => __NAMESPACE__ . '\\rest_ajouter_a_liste',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'id'     => argument_liste(),
					'oeuvre' => argument_oeuvre(),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\rest_retirer_de_liste',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'id'     => argument_liste(),
					'oeuvre' => argument_oeuvre(),
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/notifications',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_notifications',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'page'     => array(
						'description'       => __( 'Page (à partir de 1).', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'default'           => 1,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'limite'   => array(
						'description'       => __( 'Notifications par page (1 à 50).', 'yume-core' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 50,
						'default'           => 20,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'non_lues' => array(
						'description'       => __( 'Seulement les notifications non lues.', 'yume-core' ),
						'type'              => 'boolean',
						'default'           => false,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/moi/notifications/lues',
		array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_notifications_lues',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'ids' => array(
						'description'       => __( 'Notifications à marquer comme lues (toutes si absent).', 'yume-core' ),
						'type'              => 'array',
						'items'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems'          => 200,
						'default'           => array(),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			),
		)
	);

	$endpoint = array(
		'description'       => __( 'Point d’accès du service push (https).', 'yume-core' ),
		'type'              => 'string',
		'maxLength'         => 1000,
		'required'          => true,
		'validate_callback' => 'rest_validate_request_arg',
	);
	register_rest_route(
		REST_NS,
		'/moi/push',
		array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_push_abonner',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array(
					'endpoint' => $endpoint,
					'p256dh'   => array(
						'description' => __( 'Clé publique de l’abonnement (base64url).', 'yume-core' ),
						'type'        => 'string',
						'maxLength'   => 200,
					),
					'auth'     => array(
						'description' => __( 'Secret d’authentification de l’abonnement (base64url).', 'yume-core' ),
						'type'        => 'string',
						'maxLength'   => 100,
					),
					'keys'     => array(
						'description' => __( 'Clés au format PushSubscription.toJSON() (p256dh, auth).', 'yume-core' ),
						'type'        => 'object',
					),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\rest_push_desabonner',
				'permission_callback' => __NAMESPACE__ . '\\permission_connecte',
				'args'                => array( 'endpoint' => $endpoint ),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/push/cle',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_push_cle',
				'permission_callback' => '__return_true',
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes_lecteur' );

/**
 * Liste du membre courant visée par la route, ou erreur 404 (inconnue ou d'un autre membre).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return array|\WP_Error
 */
function liste_de_la_requete( \WP_REST_Request $requete ) {
	$liste = liste_du_membre( (int) $requete['id'], get_current_user_id() );
	return $liste ? $liste : erreur_liste();
}

/**
 * GET /moi/listes.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_listes( \WP_REST_Request $requete ) {
	$user_id = get_current_user_id();
	$oeuvre  = (int) $requete['oeuvre'];
	$listes  = array();
	foreach ( listes_utilisateur( $user_id ) as $liste ) {
		$listes[] = liste_pour_api( $liste, $oeuvre );
	}
	return rest_ensure_response(
		array(
			'listes' => $listes,
			'auto'   => listes_auto( $user_id ),
			'max'    => LISTES_MAX,
			'reste'  => max( 0, LISTES_MAX - nb_listes_personnelles( $user_id ) ),
		)
	);
}

/**
 * POST /moi/listes.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_creer_liste( \WP_REST_Request $requete ) {
	$liste = creer_liste( get_current_user_id(), (string) $requete['nom'], (string) $requete['description'], (bool) $requete['publique'] );
	if ( is_wp_error( $liste ) ) {
		return $liste;
	}
	$reponse = rest_ensure_response( liste_pour_api( $liste ) );
	$reponse->set_status( 201 );
	return $reponse;
}

/**
 * PATCH /moi/listes/{id}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_modifier_liste( \WP_REST_Request $requete ) {
	$liste = liste_de_la_requete( $requete );
	if ( is_wp_error( $liste ) ) {
		return $liste;
	}
	$champs = array();
	foreach ( array( 'nom', 'description', 'publique' ) as $cle ) {
		if ( $requete->has_param( $cle ) ) {
			$champs[ $cle ] = $requete[ $cle ];
		}
	}
	$liste = modifier_liste( $liste, $champs );
	return is_wp_error( $liste ) ? $liste : rest_ensure_response( liste_pour_api( $liste ) );
}

/**
 * DELETE /moi/listes/{id}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_supprimer_liste( \WP_REST_Request $requete ) {
	$liste = liste_de_la_requete( $requete );
	if ( is_wp_error( $liste ) ) {
		return $liste;
	}
	$resultat = supprimer_liste( $liste );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	return rest_ensure_response(
		array(
			'supprime' => true,
			'id'       => $liste['id'],
		)
	);
}

/**
 * Présence d'une œuvre dans les listes du membre courant (réponse des routes d'œuvre).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<string,mixed>
 */
function etat_listes_oeuvre( int $oeuvre_id ): array {
	$contient = listes_contenant( get_current_user_id(), $oeuvre_id );
	return array(
		'oeuvre' => $oeuvre_id,
		'listes' => array_map( 'intval', array_keys( $contient ) ),
	);
}

/**
 * PUT /moi/listes/{id}/oeuvres/{oeuvre}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_ajouter_a_liste( \WP_REST_Request $requete ) {
	$liste = liste_de_la_requete( $requete );
	if ( is_wp_error( $liste ) ) {
		return $liste;
	}
	$oeuvre_id = (int) $requete['oeuvre'];
	$resultat  = ajouter_a_liste( $liste, $oeuvre_id );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	$reponse = rest_ensure_response( etat_listes_oeuvre( $oeuvre_id ) );
	$reponse->set_status( $resultat ? 201 : 200 );
	return $reponse;
}

/**
 * DELETE /moi/listes/{id}/oeuvres/{oeuvre}.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_retirer_de_liste( \WP_REST_Request $requete ) {
	$liste = liste_de_la_requete( $requete );
	if ( is_wp_error( $liste ) ) {
		return $liste;
	}
	$oeuvre_id = (int) $requete['oeuvre'];
	retirer_de_liste( $liste, $oeuvre_id );
	return rest_ensure_response( etat_listes_oeuvre( $oeuvre_id ) );
}
