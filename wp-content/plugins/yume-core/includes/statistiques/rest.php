<?php
/**
 * Routes REST des statistiques (lecture seule, toutes en GET) :
 *
 *   GET /yume/v1/statistiques/resume?periode=                       aperçu de la période
 *   GET /yume/v1/statistiques/trafic?periode=&section=              contenus|referents|recherches|pays
 *   GET /yume/v1/statistiques/oeuvres?periode=&tri=&page=&limite=   classement des œuvres
 *   GET /yume/v1/statistiques/oeuvres/{oeuvre}?periode=             fiche d'une œuvre
 *   GET /yume/v1/statistiques/oeuvres/{oeuvre}/lecteurs?type=&q=&page=&limite=&tri=&ordre=
 *                                                                    liste nominative (gérants)
 *
 * Capacité yume_voir_statistiques (équipe), yume_voir_lecteurs pour la liste nominative ; 401 en
 * anonyme, 403 sans la capacité. Toutes les réponses (erreurs comprises) portent
 * « Cache-Control: private, no-store ». Authentification WordPress standard : cookie + nonce
 * wp_rest (en-tête X-WP-Nonce) ou mot de passe d'application.
 *
 * Les données viennent des autres fichiers du module (trafic.php, oeuvres.php, lecteurs.php),
 * appelés seulement s'ils définissent la fonction attendue : sinon 503
 * yume_statistiques_indisponible.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Préfixe des routes du module (contrôle de l'en-tête Cache-Control). */
const ROUTE_STATISTIQUES = '/yume/v1/statistiques';

/** Sections de la route /trafic : section => fonction de trafic.php. */
const SECTIONS_TRAFIC = array(
	'contenus'   => 'trafic_contenus',
	'referents'  => 'trafic_referents',
	'recherches' => 'trafic_recherches',
	'pays'       => 'trafic_pays',
);

/** Tris du classement des œuvres. */
const TRIS_OEUVRES = array( 'favoris', 'suiveurs', 'lecteurs', 'vues', 'nouveaux', 'croissance' );

/** Œuvres par page du classement (défaut et plafond). */
const OEUVRES_PAR_PAGE = 20;

/** Plafond du nombre d'œuvres par page du classement. */
const OEUVRES_PAGE_MAX = 100;

/** Œuvres des tops de l'aperçu. */
const TOP_RESUME = 5;

/**
 * Argument « periode » commun à toutes les routes.
 *
 * @return array<string,mixed>
 */
function argument_periode(): array {
	return array(
		'description' => __( 'Période en jours : 7, 30, 90 ou 365 (30 par défaut ; toute autre valeur donne 30).', 'yume-core' ),
		'type'        => 'integer',
		'default'     => PERIODE_DEFAUT,
	);
}

/**
 * Déclare les routes.
 */
function enregistrer_routes(): void {
	$oeuvre = array(
		'description' => __( 'ID de l’œuvre.', 'yume-core' ),
		'type'        => 'integer',
	);
	register_rest_route(
		'yume/v1',
		'/statistiques/resume',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_resume',
			'permission_callback' => __NAMESPACE__ . '\\permission_stats',
			'args'                => array( 'periode' => argument_periode() ),
		)
	);
	register_rest_route(
		'yume/v1',
		'/statistiques/trafic',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_trafic',
			'permission_callback' => __NAMESPACE__ . '\\permission_stats',
			'args'                => array(
				'periode' => argument_periode(),
				'section' => array(
					'description' => __( 'Section du trafic.', 'yume-core' ),
					'type'        => 'string',
					'enum'        => array_keys( SECTIONS_TRAFIC ),
					'default'     => 'contenus',
				),
			),
		)
	);
	register_rest_route(
		'yume/v1',
		'/statistiques/oeuvres',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_oeuvres',
			'permission_callback' => __NAMESPACE__ . '\\permission_stats',
			'args'                => array(
				'periode' => argument_periode(),
				'tri'     => array(
					'description' => __( 'Tri du classement.', 'yume-core' ),
					'type'        => 'string',
					'enum'        => TRIS_OEUVRES,
					'default'     => 'favoris',
				),
				'page'    => array(
					'description' => __( 'Page (1 pour la première).', 'yume-core' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
				),
				'limite'  => array(
					'description' => __( 'Œuvres par page.', 'yume-core' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => OEUVRES_PAGE_MAX,
					'default'     => OEUVRES_PAR_PAGE,
				),
			),
		)
	);
	register_rest_route(
		'yume/v1',
		'/statistiques/oeuvres/(?P<oeuvre>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_oeuvre',
			'permission_callback' => __NAMESPACE__ . '\\permission_stats',
			'args'                => array(
				'oeuvre'  => $oeuvre,
				'periode' => argument_periode(),
			),
		)
	);
	register_rest_route(
		'yume/v1',
		'/statistiques/oeuvres/(?P<oeuvre>\d+)/lecteurs',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_lecteurs',
			'permission_callback' => __NAMESPACE__ . '\\permission_lecteurs',
			'args'                => array(
				'oeuvre' => $oeuvre,
				'type'   => array(
					'description' => __( 'Liste : favoris (tous) ou suiveurs (alertes actives).', 'yume-core' ),
					'type'        => 'string',
					'enum'        => array( 'favoris', 'suiveurs' ),
					'default'     => 'favoris',
				),
				'q'      => array(
					'description' => __( 'Recherche sur le pseudo (2 caractères au moins, sinon ignorée).', 'yume-core' ),
					'type'        => 'string',
					'default'     => '',
				),
				'page'   => array(
					'description' => __( 'Page (1 pour la première).', 'yume-core' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
				),
				'limite' => array(
					'description' => __( 'Lecteurs par page.', 'yume-core' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => LECTEURS_PAGE_MAX,
					'default'     => LECTEURS_PAR_PAGE,
				),
				'tri'    => array(
					'description' => __( 'Tri : date d’ajout ou pseudo.', 'yume-core' ),
					'type'        => 'string',
					'enum'        => array( 'date', 'pseudo' ),
					'default'     => 'date',
				),
				'ordre'  => array(
					'description' => __( 'Ordre : desc ou asc.', 'yume-core' ),
					'type'        => 'string',
					'enum'        => array( 'desc', 'asc' ),
					'default'     => 'desc',
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/*
 * -----------------------------------------------------------------------------
 * Permissions
 * -----------------------------------------------------------------------------
 */

/**
 * Refus : 401 en anonyme, 403 connecté sans la capacité.
 *
 * @param string $message Message pour un compte connecté.
 */
function refus( string $message ): \WP_Error {
	return new \WP_Error(
		'rest_forbidden',
		is_user_logged_in() ? $message : __( 'Connectez-vous pour consulter les statistiques.', 'yume-core' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Permission des statistiques agrégées : capacité yume_voir_statistiques.
 *
 * @return true|\WP_Error
 */
function permission_stats() {
	return current_user_can( CAPACITE_STATS ) ? true : refus( __( 'Votre compte ne peut pas consulter les statistiques.', 'yume-core' ) );
}

/**
 * Permission des listes nominatives : capacité yume_voir_lecteurs.
 *
 * @return true|\WP_Error
 */
function permission_lecteurs() {
	return current_user_can( CAPACITE_LECTEURS ) ? true : refus( __( 'Votre compte ne peut pas consulter la liste des lecteurs.', 'yume-core' ) );
}

/*
 * -----------------------------------------------------------------------------
 * Aides
 * -----------------------------------------------------------------------------
 */

/**
 * Erreur 503 : la fonction de données n'est pas (encore) disponible.
 */
function indisponible(): \WP_Error {
	return new \WP_Error( 'yume_statistiques_indisponible', __( 'Ces statistiques ne sont pas encore disponibles.', 'yume-core' ), array( 'status' => 503 ) );
}

/**
 * La fonction du module existe-t-elle ?
 *
 * @param string $fonction Nom court (dans l'espace de noms du module).
 */
function fonction_disponible( string $fonction ): bool {
	return function_exists( __NAMESPACE__ . '\\' . $fonction );
}

/**
 * Œuvre de la route (yume_oeuvre), ou erreur 404.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return int|\WP_Error
 */
function oeuvre_route( \WP_REST_Request $requete ) {
	$oeuvre_id = (int) $requete->get_param( 'oeuvre' );
	if ( $oeuvre_id > 0 && 'yume_oeuvre' === get_post_type( $oeuvre_id ) ) {
		return $oeuvre_id;
	}
	return new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), array( 'status' => 404 ) );
}

/**
 * En-tête « Cache-Control: private, no-store » sur toutes les réponses des routes du module,
 * erreurs et refus compris (filtre rest_request_after_callbacks : appliqué aussi par
 * rest_do_request(), contrairement à rest_post_dispatch).
 *
 * @param mixed            $reponse Réponse ou erreur.
 * @param array            $handler Gestionnaire de la route.
 * @param \WP_REST_Request $requete Requête.
 * @return mixed
 */
function entete_prive( $reponse, $handler, $requete ) {
	if ( ! $requete instanceof \WP_REST_Request || ! str_starts_with( $requete->get_route(), ROUTE_STATISTIQUES . '/' ) ) {
		return $reponse;
	}
	$reponse = is_wp_error( $reponse ) ? rest_convert_error_to_response( $reponse ) : rest_ensure_response( $reponse );
	if ( $reponse instanceof \WP_REST_Response ) {
		$reponse->header( 'Cache-Control', 'private, no-store' );
	}
	return $reponse;
}
add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\\entete_prive', 10, 3 );

/*
 * -----------------------------------------------------------------------------
 * Rappels
 * -----------------------------------------------------------------------------
 */

/**
 * GET /statistiques/resume : aperçu de la période (trafic, lecteurs, œuvres les plus suivies et
 * les plus vues). Le trafic est facultatif (null si trafic.php n'est pas disponible).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_resume( \WP_REST_Request $requete ) {
	if ( ! fonction_disponible( 'resume_lecteurs' ) || ! fonction_disponible( 'classement' ) ) {
		return indisponible();
	}
	$periode    = periode_valide( $requete->get_param( 'periode' ) );
	$classement = classement( $periode, 'favoris', TOP_RESUME );
	$contenus   = fonction_disponible( 'trafic_contenus' ) ? trafic_contenus( $periode ) : null;
	return rest_ensure_response(
		array(
			'periode'       => $periode,
			'bornes'        => bornes_periode( $periode ),
			'calcule'       => maintenant(),
			'trafic'        => fonction_disponible( 'trafic_resume' ) ? trafic_resume( $periode ) : null,
			'source_trafic' => fonction_disponible( 'source_trafic' ) ? source_trafic() : 'aucune',
			'lecteurs'      => resume_lecteurs( $periode ),
			'top_suivies'   => array_values( (array) ( $classement['oeuvres'] ?? array() ) ),
			'top_vues'      => array_slice( array_values( (array) ( $contenus['oeuvres'] ?? array() ) ), 0, TOP_RESUME ),
		)
	);
}

/**
 * GET /statistiques/trafic : une section du trafic (trafic_contenus(), trafic_referents(),
 * trafic_recherches(), trafic_pays()), ou { source: 'aucune', message } si le trafic est
 * indisponible.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_trafic( \WP_REST_Request $requete ) {
	$section  = (string) $requete->get_param( 'section' );
	$fonction = SECTIONS_TRAFIC[ $section ] ?? SECTIONS_TRAFIC['contenus'];
	if ( ! fonction_disponible( $fonction ) ) {
		return indisponible();
	}
	$resultat = call_user_func( __NAMESPACE__ . '\\' . $fonction, periode_valide( $requete->get_param( 'periode' ) ) );
	if ( null === $resultat ) {
		$resultat = array(
			'source'  => 'aucune',
			'message' => __( 'Trafic indisponible : Jetpack n’est pas connecté sur ce site.', 'yume-core' ),
		);
	}
	return rest_ensure_response( $resultat );
}

/**
 * GET /statistiques/oeuvres : classement des œuvres (classement()).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_oeuvres( \WP_REST_Request $requete ) {
	if ( ! fonction_disponible( 'classement' ) ) {
		return indisponible();
	}
	$tri = (string) $requete->get_param( 'tri' );
	return rest_ensure_response(
		classement(
			periode_valide( $requete->get_param( 'periode' ) ),
			in_array( $tri, TRIS_OEUVRES, true ) ? $tri : 'favoris',
			min( OEUVRES_PAGE_MAX, max( 1, (int) $requete->get_param( 'limite' ) ) ),
			max( 1, (int) $requete->get_param( 'page' ) )
		)
	);
}

/**
 * GET /statistiques/oeuvres/{oeuvre} : fiche statistique d'une œuvre (fiche_oeuvre()) ; 404 si
 * l'œuvre n'existe pas ou n'a pas de fiche.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_oeuvre( \WP_REST_Request $requete ) {
	$oeuvre_id = oeuvre_route( $requete );
	if ( is_wp_error( $oeuvre_id ) ) {
		return $oeuvre_id;
	}
	if ( ! fonction_disponible( 'fiche_oeuvre' ) ) {
		return indisponible();
	}
	$fiche = fiche_oeuvre( $oeuvre_id, periode_valide( $requete->get_param( 'periode' ) ) );
	if ( null === $fiche ) {
		return new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( $fiche );
}

/**
 * GET /statistiques/oeuvres/{oeuvre}/lecteurs : liste nominative des favoris ou des suiveurs
 * d'une œuvre (lecteurs_oeuvre() : pseudo, date, fréquence, badge équipe ; jamais d'e-mail ni
 * d'identifiant de connexion).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_lecteurs( \WP_REST_Request $requete ) {
	$oeuvre_id = oeuvre_route( $requete );
	if ( is_wp_error( $oeuvre_id ) ) {
		return $oeuvre_id;
	}
	if ( ! fonction_disponible( 'lecteurs_oeuvre' ) ) {
		return indisponible();
	}
	return rest_ensure_response(
		lecteurs_oeuvre(
			$oeuvre_id,
			array(
				'type'   => 'suiveurs' === $requete->get_param( 'type' ) ? 'suiveurs' : 'favoris',
				'q'      => sanitize_text_field( (string) $requete->get_param( 'q' ) ),
				'page'   => max( 1, (int) $requete->get_param( 'page' ) ),
				'limite' => min( LECTEURS_PAGE_MAX, max( 1, (int) $requete->get_param( 'limite' ) ) ),
				'tri'    => 'pseudo' === $requete->get_param( 'tri' ) ? 'pseudo' : 'date',
				'ordre'  => 'asc' === $requete->get_param( 'ordre' ) ? 'asc' : 'desc',
			)
		)
	);
}
