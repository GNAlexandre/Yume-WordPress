<?php
/**
 * Connecteur Yume-Trad : envoi ponctuel d'un glossaire (aucune liaison permanente, aucun
 * webhook, aucune interrogation de l'application).
 *
 *   POST /yume/v1/oeuvres/{oeuvre}/glossaire   (oeuvre = ID ou slug ; capacité yume_glossaire)
 *     corps : YAML brut (Content-Type application/yaml, text/yaml ou text/plain), JSON
 *     { "yaml": "…", "note": "…" }, ou multipart avec un fichier « glossaire » ;
 *     simulation=1 : bilan sans rien écrire.
 *     → { statut: importe|inchange|simulation, oeuvre, nb_entrees, total, anglicismes,
 *         avertissements, version, … }
 *   GET  /yume/v1/oeuvres/{oeuvre}/glossaire   → métadonnées de la version courante.
 *
 * Authentification WordPress standard : mot de passe d'application (HTTP Basic) ou cookie +
 * nonce wp_rest. Débit : ENVOIS_PAR_HEURE envois par heure et par compte. docs/glossaire.md.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare les routes.
 */
function enregistrer_routes(): void {
	register_rest_route(
		'yume/v1',
		'/oeuvres/(?P<oeuvre>[\w-]+)/glossaire',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\rest_lire',
				'permission_callback' => __NAMESPACE__ . '\\permission_glossaire',
			),
			array(
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\rest_envoyer',
				'permission_callback' => __NAMESPACE__ . '\\permission_glossaire',
				'args'                => array(
					'simulation' => array(
						'description' => __( 'Analyse seulement : rien n’est enregistré (1, true).', 'yume-core' ),
						'required'    => false,
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * Permission : capacité yume_glossaire (401 anonyme, 403 connecté sans la capacité).
 *
 * @return true|\WP_Error
 */
function permission_glossaire() {
	if ( current_user_can( CAPACITE ) ) {
		return true;
	}
	return new \WP_Error(
		'rest_forbidden',
		is_user_logged_in() ? __( 'Votre compte ne peut pas gérer les glossaires.', 'yume-core' ) : __( 'Authentification requise (mot de passe d’application).', 'yume-core' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Œuvre de la route, ou erreur 404.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return int|\WP_Error
 */
function oeuvre_requete( \WP_REST_Request $requete ) {
	$oeuvre_id = oeuvre_depuis( (string) $requete->get_param( 'oeuvre' ) );
	return $oeuvre_id ? $oeuvre_id : new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable (ID ou slug).', 'yume-core' ), array( 'status' => 404 ) );
}

/**
 * GET : version courante (date, empreinte, nombre d'entrées), sans le contenu.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_lire( \WP_REST_Request $requete ) {
	$oeuvre_id = oeuvre_requete( $requete );
	if ( is_wp_error( $oeuvre_id ) ) {
		return $oeuvre_id;
	}
	$etat = etat_glossaire( $oeuvre_id );
	return rest_ensure_response(
		array(
			'oeuvre'      => array(
				'id'            => $oeuvre_id,
				'titre'         => titre_oeuvre( $oeuvre_id ),
				'url_glossaire' => $etat['version'] ? url_glossaire( $oeuvre_id ) : '',
			),
			'version'     => version_courante( $oeuvre_id ),
			'entrees'     => $etat['entrees'],
			'publiques'   => $etat['publiques'],
			'anglicismes' => $etat['anglicismes'],
		)
	);
}

/**
 * Document YAML envoyé : corps brut, champ JSON ou formulaire « yaml », ou fichier « glossaire ».
 *
 * @param \WP_REST_Request $requete Requête.
 * @return string|\WP_Error
 */
function yaml_requete( \WP_REST_Request $requete ) {
	$fichiers = $requete->get_file_params();
	if ( ! empty( $fichiers['glossaire'] ) && is_array( $fichiers['glossaire'] ) ) {
		return lire_fichier_televerse( $fichiers['glossaire'] );
	}
	$type = $requete->get_content_type();
	$type = is_array( $type ) ? (string) ( $type['value'] ?? '' ) : '';
	if ( in_array( $type, array( 'application/yaml', 'application/x-yaml', 'text/yaml', 'text/x-yaml', 'text/plain' ), true ) ) {
		return (string) $requete->get_body();
	}
	$yaml = $requete->get_param( 'yaml' );
	if ( is_string( $yaml ) && '' !== $yaml ) {
		return $yaml;
	}
	return new \WP_Error( 'yume_glossaire_absent', __( 'Aucun glossaire reçu : envoyez le YAML brut (Content-Type: application/yaml), un JSON { "yaml": "…" } ou un fichier « glossaire ».', 'yume-core' ), array( 'status' => 400 ) );
}

/**
 * Compte un envoi du compte courant ; vrai si la limite horaire est dépassée.
 *
 * @param int $user_id Compte.
 */
function limite_envois( int $user_id ): bool {
	/**
	 * Filtre le nombre d'envois de glossaire autorisés par heure et par compte.
	 *
	 * @param int $max     Envois par heure.
	 * @param int $user_id Compte.
	 */
	$max  = (int) apply_filters( 'yume_glossaire_envois_par_heure', ENVOIS_PAR_HEURE, $user_id );
	$cle  = 'yume_glossaire_envois_' . $user_id;
	$etat = get_transient( $cle );
	$etat = is_array( $etat ) && (int) ( $etat['fin'] ?? 0 ) > time() ? $etat : array(
		'n'   => 0,
		'fin' => time() + HOUR_IN_SECONDS,
	);
	if ( (int) $etat['n'] >= $max ) {
		return true;
	}
	$etat['n'] = (int) $etat['n'] + 1;
	set_transient( $cle, $etat, max( 1, (int) $etat['fin'] - time() ) );
	return false;
}

/**
 * POST : importe (ou simule) le glossaire envoyé.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_envoyer( \WP_REST_Request $requete ) {
	$oeuvre_id = oeuvre_requete( $requete );
	if ( is_wp_error( $oeuvre_id ) ) {
		return $oeuvre_id;
	}
	$user_id = get_current_user_id();
	if ( limite_envois( $user_id ) ) {
		return new \WP_Error(
			'yume_glossaire_limite',
			/* translators: %d : envois par heure */
			sprintf( __( 'Trop d’envois : %d par heure au plus. Réessayez plus tard.', 'yume-core' ), ENVOIS_PAR_HEURE ),
			array( 'status' => 429 )
		);
	}
	$yaml = yaml_requete( $requete );
	if ( is_wp_error( $yaml ) ) {
		return $yaml;
	}
	$note  = $requete->get_param( 'note' );
	$bilan = importer(
		$oeuvre_id,
		$yaml,
		array(
			'user_id'    => $user_id,
			'source'     => 'api',
			'note'       => is_scalar( $note ) ? (string) $note : '',
			'simulation' => booleen( $requete->get_param( 'simulation' ) ),
		)
	);
	if ( is_wp_error( $bilan ) ) {
		return $bilan;
	}
	return rest_ensure_response( $bilan );
}
