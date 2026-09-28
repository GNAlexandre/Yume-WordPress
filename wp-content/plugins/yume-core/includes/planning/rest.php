<?php
/**
 * Routes REST yume/v1 du planning (§12) :
 *
 * - GET   /planning                   public (champs publics seulement ; filtres type, etat, oeuvre…)
 * - PATCH /tomes/(?P<id>\d+)/planning yume_user_can_edit_planning
 * - DELETE /tomes/(?P<id>\d+)/planning yume_maj_planning_tous + delete_post : retire un tome
 *                                     du planning (corbeille ; brouillon sans chapitre publié)
 * - GET   /planning/journal           public, sans notes d'équipe (JSON, ou RSS avec format=rss)
 * - POST  /planning/tomes             yume_maj_planning_tous : ajoute un tome au planning (brouillon)
 * - GET   /planning.ics               public : calendrier iCalendar (RFC 5545) des sorties, ?oeuvre=<id|slug>
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Espace de noms REST. */
const REST_NS = 'yume/v1';

/**
 * Schéma d'un trio {traduction, relecture, edition} d'entiers.
 *
 * @param string $description Description.
 * @return array<string,mixed>
 */
function schema_trio_entiers( string $description ): array {
	$prop = array( 'type' => 'integer' );
	return array(
		'description'          => $description,
		'type'                 => 'object',
		'properties'           => array(
			'traduction' => $prop,
			'relecture'  => $prop,
			'edition'    => $prop,
		),
		'additionalProperties' => false,
	);
}

/**
 * Enregistre les routes.
 */
function routes(): void {
	register_rest_route(
		REST_NS,
		'/planning',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\rest_planning',
			'permission_callback' => '__return_true',
			'args'                => array(
				'type'                   => array(
					'description'       => __( 'Type d’œuvre (slug yume_type).', 'yume-core' ),
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => __NAMESPACE__ . '\\valider_type',
				),
				'etat'                   => array(
					'description' => __( 'État : a_lheure, en_retard, bloque, publie.', 'yume-core' ),
					'type'        => 'string',
					'default'     => '',
					'enum'        => array( '', 'a_lheure', 'en_retard', 'bloque', 'publie' ),
				),
				'oeuvre'                 => array(
					'description' => __( 'ID de l’œuvre.', 'yume-core' ),
					'type'        => 'integer',
					'default'     => 0,
					'minimum'     => 0,
				),
				'a_venir'                => array(
					'description' => __( 'Exclure les tomes publiés.', 'yume-core' ),
					'type'        => 'boolean',
					'default'     => false,
				),
				'limit'                  => array(
					'description' => __( 'Nombre maximal de lignes (0 : toutes).', 'yume-core' ),
					'type'        => 'integer',
					'default'     => 0,
					'minimum'     => 0,
					'maximum'     => 200,
				),
				'inclure_publies_depuis' => array(
					'description' => __( 'Inclure les tomes publiés depuis ce nombre de jours.', 'yume-core' ),
					'type'        => 'integer',
					'default'     => 14,
					'minimum'     => 0,
					'maximum'     => 365,
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/planning/journal',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\rest_journal',
			'permission_callback' => '__return_true',
			'args'                => array(
				'tome'   => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'oeuvre' => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'limit'  => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
				'page'   => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'format' => array(
					'type'    => 'string',
					'default' => 'json',
					'enum'    => array( 'json', 'rss' ),
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/planning/tomes',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\rest_ajouter_tome',
			'permission_callback' => __NAMESPACE__ . '\\permission_ajout',
			'args'                => array(
				'oeuvre_id'    => array(
					'description' => __( 'Œuvre du tome.', 'yume-core' ),
					'type'        => 'integer',
					'required'    => true,
					'minimum'     => 1,
				),
				'nature'       => array(
					'type'    => 'string',
					'default' => 'tome',
					'enum'    => array_keys( yume_natures_tome() ),
				),
				'numero'       => array(
					'description' => __( 'Numéro (ex. 9 ou 26,5).', 'yume-core' ),
					'type'        => array( 'number', 'string' ),
				),
				'titre'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'responsables' => schema_trio_entiers( __( 'Responsables (ID utilisateur, 0 = personne).', 'yume-core' ) ),
				'date_cible'   => array(
					'description' => __( 'Date de sortie visée (AAAA-MM-JJ).', 'yume-core' ),
					'type'        => 'string',
				),
				'etape'        => array(
					'type' => 'string',
					'enum' => array( 'a_faire', 'traduction', 'relecture', 'edition' ),
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/tomes/(?P<id>\d+)/planning',
		array(
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => __NAMESPACE__ . '\\rest_retirer_tome',
				'permission_callback' => __NAMESPACE__ . '\\permission_retrait',
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => __NAMESPACE__ . '\\rest_maj_planning',
				'permission_callback' => __NAMESPACE__ . '\\permission_maj',
				'args'                => array(
					'id'            => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'etape'         => array(
						'type' => 'string',
						'enum' => array_keys( yume_etapes() ),
					),
					'avancement'    => schema_trio_entiers( __( 'Avancement de chaque étape (0 à 100 ; valeurs hors bornes ramenées).', 'yume-core' ) ),
					'date_cible'    => array(
						'description' => __( 'Date de sortie visée (AAAA-MM-JJ, vide pour retirer).', 'yume-core' ),
						'type'        => 'string',
					),
					'bloque'        => array( 'type' => 'boolean' ),
					'bloque_raison' => array( 'type' => 'string' ),
					'responsables'  => schema_trio_entiers( __( 'Responsables (yume_maj_planning_tous).', 'yume-core' ) ),
					'note_equipe'   => array( 'type' => 'string' ),
				),
			),
		)
	);

	register_rest_route(
		REST_NS,
		'/planning\\.ics',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\rest_planning_ics',
			'permission_callback' => '__return_true',
			'args'                => array(
				'oeuvre' => array(
					'description'       => __( 'ID ou slug de l’œuvre (vide : toutes).', 'yume-core' ),
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static function ( $valeur ): string {
						return is_scalar( $valeur ) ? sanitize_title( (string) $valeur ) : '';
					},
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );

/**
 * Validation du filtre de type.
 *
 * @param mixed $valeur Valeur.
 * @return true|\WP_Error
 */
function valider_type( $valeur ) {
	$valeur = is_scalar( $valeur ) ? sanitize_key( (string) $valeur ) : '';
	if ( '' === $valeur || array_key_exists( $valeur, yume_types() ) ) {
		return true;
	}
	return new \WP_Error( 'rest_invalid_param', __( 'Type d’œuvre inconnu.', 'yume-core' ), array( 'status' => 400 ) );
}

/**
 * GET /planning.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_planning( \WP_REST_Request $requete ) {
	$lignes  = yume_get_planning(
		array(
			'oeuvre_id'              => (int) $requete['oeuvre'],
			'type'                   => (string) $requete['type'],
			'etat'                   => (string) $requete['etat'],
			'a_venir'                => (bool) $requete['a_venir'],
			'limit'                  => (int) $requete['limit'],
			'inclure_publies_depuis' => (int) $requete['inclure_publies_depuis'],
		)
	);
	$reponse = rest_ensure_response( array_map( __NAMESPACE__ . '\\ligne_publique', $lignes ) );
	$reponse->header( 'X-WP-Total', (string) count( $lignes ) );
	return $reponse;
}

/**
 * Permission de PATCH /tomes/{id}/planning.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return true|\WP_Error
 */
function permission_maj( \WP_REST_Request $requete ) {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error( 'rest_forbidden', __( 'Connectez-vous pour mettre à jour le planning.', 'yume-core' ), array( 'status' => 401 ) );
	}
	$id = (int) $requete['id'];
	if ( 'yume_tome' !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
		return new \WP_Error( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	if ( ! yume_user_can_edit_planning( $id ) ) {
		return new \WP_Error( 'rest_forbidden', __( 'Vous n’êtes pas responsable de ce tome.', 'yume-core' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * PATCH /tomes/{id}/planning.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_maj_planning( \WP_REST_Request $requete ) {
	$id     = (int) $requete['id'];
	$params = $requete->get_params();
	$saisie = array();
	foreach ( array( 'etape', 'avancement', 'date_cible', 'bloque', 'bloque_raison', 'responsables', 'note_equipe' ) as $cle ) {
		if ( array_key_exists( $cle, $params ) ) {
			$saisie[ $cle ] = $requete->get_param( $cle );
		}
	}
	$resultat = mettre_a_jour( $id, $saisie, get_current_user_id() );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	return rest_ensure_response(
		array(
			'succes'      => true,
			'message'     => message_mise_a_jour( $id, $resultat['changements'] ),
			'changements' => array_keys( $resultat['changements'] ),
			'tome'        => ligne_equipe( $id ),
		)
	);
}

/**
 * Permission de DELETE /tomes/{id}/planning (le détail des droits est contrôlé par
 * retirer_tome()).
 *
 * @return true|\WP_Error
 */
function permission_retrait() {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error( 'rest_forbidden', __( 'Connectez-vous pour retirer un tome du planning.', 'yume-core' ), array( 'status' => 401 ) );
	}
	if ( ! current_user_can( 'yume_maj_planning_tous' ) ) {
		return new \WP_Error( 'rest_forbidden', __( 'Seuls les éditeurs et les gérants peuvent retirer un tome du planning.', 'yume-core' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * DELETE /tomes/{id}/planning : retire un tome du planning (voir retirer_tome()).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_retirer_tome( \WP_REST_Request $requete ) {
	$id      = (int) $requete['id'];
	$libelle = 'yume_tome' === get_post_type( $id ) ? cible_journal( $id ) : '';
	$retrait = retirer_tome( $id, get_current_user_id() );
	if ( is_wp_error( $retrait ) ) {
		return $retrait;
	}
	return rest_ensure_response(
		array(
			'succes'  => true,
			/* translators: %s : tome */
			'message' => sprintf( __( '%s retiré du planning.', 'yume-core' ), $libelle ),
			'tome_id' => $id,
		)
	);
}

/**
 * Permission de POST /planning/tomes.
 *
 * @return true|\WP_Error
 */
function permission_ajout() {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error( 'rest_forbidden', __( 'Connectez-vous pour ajouter un tome au planning.', 'yume-core' ), array( 'status' => 401 ) );
	}
	if ( ! current_user_can( 'yume_maj_planning_tous' ) ) {
		return new \WP_Error( 'rest_forbidden', __( 'Seuls les éditeurs et les gérants peuvent ajouter un tome au planning.', 'yume-core' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * POST /planning/tomes.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_ajouter_tome( \WP_REST_Request $requete ) {
	$params = $requete->get_params();
	$saisie = array();
	foreach ( array( 'oeuvre_id', 'nature', 'numero', 'titre', 'responsables', 'date_cible', 'etape' ) as $cle ) {
		if ( array_key_exists( $cle, $params ) ) {
			$saisie[ $cle ] = $requete->get_param( $cle );
		}
	}
	$tome_id = ajouter_tome( $saisie, get_current_user_id() );
	if ( is_wp_error( $tome_id ) ) {
		return $tome_id;
	}
	$reponse = rest_ensure_response(
		array(
			'succes'  => true,
			/* translators: %s : tome */
			'message' => sprintf( __( '%s ajouté au planning.', 'yume-core' ), cible_journal( $tome_id ) ),
			'tome_id' => $tome_id,
			'tome'    => ligne_equipe( $tome_id ),
		)
	);
	$reponse->set_status( 201 );
	return $reponse;
}

/**
 * Valeur publique d'un champ du journal (IDs d'utilisateurs remplacés par les pseudos).
 *
 * @param string $champ  Champ.
 * @param mixed  $valeur Valeur brute.
 * @return mixed
 */
function valeur_publique( string $champ, $valeur ) {
	$valeur = lire_valeur( $valeur );
	if ( 'responsables' === $champ ) {
		$noms = array();
		foreach ( norm_responsables( is_array( $valeur ) ? $valeur : array() ) as $etape => $uid ) {
			$noms[ $etape ] = $uid ? nom_utilisateur( $uid ) : '';
		}
		return $noms;
	}
	if ( 'rappel' === $champ && is_array( $valeur ) ) {
		$valeur['destinataires'] = array_map( __NAMESPACE__ . '\\nom_utilisateur', array_map( 'intval', (array) ( $valeur['destinataires'] ?? array() ) ) );
		return $valeur;
	}
	if ( 'bloque' === $champ ) {
		return '1' === (string) $valeur;
	}
	return $valeur;
}

/**
 * GET /planning/journal.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_journal( \WP_REST_Request $requete ) {
	$limite = (int) $requete['limit'];
	$lignes = lire_journal(
		array(
			'tome_id'   => (int) $requete['tome'],
			'oeuvre_id' => (int) $requete['oeuvre'],
			'public'    => true,
			'limit'     => $limite,
			'offset'    => ( max( 1, (int) $requete['page'] ) - 1 ) * $limite,
		)
	);
	if ( 'rss' === $requete['format'] ) {
		$reponse = new \WP_REST_Response( flux_rss( $lignes ) );
		$reponse->header( 'Content-Type', 'application/rss+xml; charset=UTF-8' );
		return $reponse;
	}
	$sortie = array();
	foreach ( $lignes as $ligne ) {
		$tome_id   = (int) $ligne->tome_id;
		$oeuvre_id = $tome_id ? yume_get_oeuvre_id( $tome_id ) : 0;
		$texte     = texte_changement( $ligne, false );
		if ( in_array( $ligne->champ, array( 'publie', 'chapitre_publie' ), true ) ) {
			$texte = trim( __( 'Publié', 'yume-core' ) . ( '' !== $texte ? ' · ' . $texte : '' ) );
		}
		$sortie[] = array(
			'id'        => (int) $ligne->id,
			'date'      => iso( (string) $ligne->created_at ),
			'tome_id'   => $tome_id,
			'oeuvre_id' => $oeuvre_id,
			'oeuvre'    => $oeuvre_id ? titre_brut( $oeuvre_id ) : '',
			'tome'      => $tome_id && 'yume_tome' === get_post_type( $tome_id ) ? yume_libelle_tome( $tome_id ) : '',
			'auteur'    => nom_utilisateur( (int) $ligne->user_id ),
			'champ'     => (string) $ligne->champ,
			'ancien'    => valeur_publique( (string) $ligne->champ, $ligne->ancien ),
			'nouveau'   => valeur_publique( (string) $ligne->champ, $ligne->nouveau ),
			'texte'     => $texte,
		);
	}
	return rest_ensure_response( $sortie );
}

/**
 * Flux RSS 2.0 du journal public.
 *
 * @param object[] $lignes Lignes publiques.
 */
function flux_rss( array $lignes ): string {
	$x       = static function ( string $texte ): string {
		return htmlspecialchars( $texte, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};
	$page    = yume_url_page( 'planning' );
	$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$entrees = grouper_journal( $lignes, false );
	$xml     = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml    .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
	/* translators: %s : nom du site */
	$xml .= '<title>' . $x( sprintf( __( 'Planning des traductions — %s', 'yume-core' ), $site ) ) . '</title>';
	$xml .= '<link>' . $x( $page ) . '</link>';
	$xml .= '<atom:link href="' . $x( rest_url( REST_NS . '/planning/journal?format=rss' ) ) . '" rel="self" type="application/rss+xml"/>';
	$xml .= '<description>' . $x( __( 'Mises à jour du planning : avancement, dates, sorties.', 'yume-core' ) ) . '</description>';
	$xml .= '<language>fr-FR</language>';
	if ( $entrees ) {
		$xml .= '<lastBuildDate>' . $x( gmdate( 'D, d M Y H:i:s', $entrees[0]['ts'] ) . ' +0000' ) . '</lastBuildDate>';
	}
	foreach ( $entrees as $entree ) {
		$lien = $entree['tome_id'] && 'publish' === get_post_status( $entree['tome_id'] ) ? (string) get_permalink( $entree['tome_id'] ) : $page;
		$xml .= '<item><title>' . $x( texte_entree( $entree ) ) . '</title>';
		$xml .= '<link>' . $x( $lien ) . '</link>';
		$xml .= '<guid isPermaLink="false">' . $x( 'yume-planning-' . min( $entree['ids'] ) ) . '</guid>';
		$xml .= '<pubDate>' . $x( gmdate( 'D, d M Y H:i:s', $entree['ts'] ) . ' +0000' ) . '</pubDate></item>';
	}
	return $xml . '</channel></rss>';
}

/**
 * Sert le flux RSS tel quel (et non encodé en JSON).
 *
 * @param bool              $servi   Déjà servi.
 * @param \WP_HTTP_Response $reponse Réponse.
 * @param \WP_REST_Request  $requete Requête.
 * @param \WP_REST_Server   $serveur Serveur.
 */
function servir_rss( $servi, $reponse, $requete, $serveur ): bool {
	if ( $servi || ! $requete instanceof \WP_REST_Request || '/' . REST_NS . '/planning/journal' !== $requete->get_route() || 'rss' !== $requete->get_param( 'format' ) ) {
		return (bool) $servi;
	}
	$donnees = $reponse instanceof \WP_HTTP_Response ? $reponse->get_data() : null;
	if ( ! is_string( $donnees ) ) {
		return (bool) $servi;
	}
	$serveur->send_header( 'Content-Type', 'application/rss+xml; charset=UTF-8' );
	echo $donnees; // phpcs:ignore WordPress.Security.EscapeOutput -- XML construit et échappé par flux_rss().
	return true;
}
add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\servir_rss', 10, 4 );

/*
 * -----------------------------------------------------------------------------
 * Calendrier iCalendar (PAGE-02, AMEL-05)
 * -----------------------------------------------------------------------------
 */

/** Transient du calendrier ICS : tableau clé d'œuvre (0 = toutes) => texte. */
const TRANSIENT_ICS = 'yume_planning_ics';

/**
 * Œuvre publiée désignée par un ID ou un slug (0 si vide, WP_Error 404 si inconnue).
 *
 * @param string $valeur ID ou slug.
 * @return int|\WP_Error
 */
function oeuvre_du_parametre( string $valeur ) {
	if ( '' === $valeur ) {
		return 0;
	}
	$id = 0;
	if ( ctype_digit( $valeur ) ) {
		$id = (int) $valeur;
	} else {
		$post = get_page_by_path( $valeur, OBJECT, 'yume_oeuvre' );
		$id   = $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
	if ( ! $id || 'yume_oeuvre' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || post_password_required( $id ) ) {
		return new \WP_Error( 'yume_oeuvre_inconnue', __( 'Œuvre inconnue.', 'yume-core' ), array( 'status' => 404 ) );
	}
	return $id;
}

/**
 * GET /planning.ics.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_planning_ics( \WP_REST_Request $requete ) {
	$oeuvre_id = oeuvre_du_parametre( (string) $requete['oeuvre'] );
	if ( is_wp_error( $oeuvre_id ) ) {
		return $oeuvre_id;
	}
	$reponse = new \WP_REST_Response( calendrier_ics( $oeuvre_id ) );
	$reponse->header( 'Content-Type', 'text/calendar; charset=utf-8' );
	$reponse->header( 'Content-Disposition', 'inline; filename="planning.ics"' );
	return $reponse;
}

/**
 * Sert le calendrier tel quel (et non encodé en JSON).
 *
 * @param bool              $servi   Déjà servi.
 * @param \WP_HTTP_Response $reponse Réponse.
 * @param \WP_REST_Request  $requete Requête.
 * @param \WP_REST_Server   $serveur Serveur.
 */
function servir_ics( $servi, $reponse, $requete, $serveur ): bool {
	if ( $servi || ! $requete instanceof \WP_REST_Request || '/' . REST_NS . '/planning.ics' !== $requete->get_route() ) {
		return (bool) $servi;
	}
	$donnees = $reponse instanceof \WP_HTTP_Response && 200 === $reponse->get_status() ? $reponse->get_data() : null;
	if ( ! is_string( $donnees ) ) {
		return (bool) $servi;
	}
	$serveur->send_header( 'Content-Type', 'text/calendar; charset=utf-8' );
	$serveur->send_header( 'Content-Disposition', 'inline; filename="planning.ics"' );
	echo $donnees; // phpcs:ignore WordPress.Security.EscapeOutput -- iCalendar construit et échappé par calendrier_ics().
	return true;
}
add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\servir_ics', 10, 4 );

/**
 * Adresse du calendrier ICS (webcal:// pour l'abonnement).
 *
 * @param int  $oeuvre_id Œuvre (0 : toutes).
 * @param bool $webcal    Schéma webcal:// (abonnement dans l'application d'agenda).
 */
function url_ics( int $oeuvre_id = 0, bool $webcal = false ): string {
	$url = rest_url( REST_NS . '/planning.ics' );
	if ( $oeuvre_id ) {
		$url = add_query_arg( 'oeuvre', $oeuvre_id, $url );
	}
	return $webcal ? (string) preg_replace( '#^https?://#i', 'webcal://', $url ) : $url;
}

/**
 * Échappe une valeur TEXT (RFC 5545 §3.3.11) : \ ; , et retours à la ligne.
 *
 * @param string $texte Texte.
 */
function texte_ics( string $texte ): string {
	return str_replace( array( '\\', ';', ',', "\r\n", "\r", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n', '\n' ), $texte );
}

/**
 * Plie une ligne de contenu à 75 octets (RFC 5545 §3.1) sans couper un caractère UTF-8 ;
 * les lignes de suite commencent par une espace. Renvoie la ligne terminée par CRLF.
 *
 * @param string $ligne Ligne.
 */
function plier_ics( string $ligne ): string {
	$sortie   = '';
	$courante = '';
	$max      = 75;
	foreach ( mb_str_split( $ligne, 1, 'UTF-8' ) as $car ) {
		if ( strlen( $courante ) + strlen( $car ) > $max ) {
			$sortie  .= $courante . "\r\n ";
			$courante = '';
			$max      = 74; // L'espace de suite compte dans les 75 octets.
		}
		$courante .= $car;
	}
	return $sortie . $courante . "\r\n";
}

/**
 * Calendrier ICS des sorties (mis en cache ; invalidé par invalider_ics()).
 *
 * @param int $oeuvre_id Œuvre (0 : toutes).
 */
function calendrier_ics( int $oeuvre_id = 0 ): string {
	$cache = get_transient( TRANSIENT_ICS );
	$cache = is_array( $cache ) ? $cache : array();
	if ( isset( $cache[ $oeuvre_id ] ) && is_string( $cache[ $oeuvre_id ] ) ) {
		return $cache[ $oeuvre_id ];
	}
	$ics                 = construire_ics( $oeuvre_id );
	$cache[ $oeuvre_id ] = $ics;
	// Une heure au plus : l'état (« en retard ») dépend du jour.
	set_transient( TRANSIENT_ICS, $cache, HOUR_IN_SECONDS );
	return $ics;
}

/**
 * Vide le cache du calendrier ICS (mise à jour du planning, pause ou reprise d'un tome,
 * sortie, tome ou œuvre modifiés).
 */
function invalider_ics(): void {
	delete_transient( TRANSIENT_ICS );
}
add_action( 'yume_planning_mis_a_jour', __NAMESPACE__ . '\\invalider_ics' );
// Pause et reprise d'un tome : action distincte (sans « changements » de champs), même effet.
add_action( 'yume_planning_pause', __NAMESPACE__ . '\\invalider_ics' );
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\invalider_ics' );

/**
 * Invalide le cache ICS quand un tome ou une œuvre change (statut, date, titre, suppression).
 *
 * @param int $post_id Contenu.
 */
function invalider_ics_contenu( $post_id ): void {
	if ( in_array( get_post_type( (int) $post_id ), array( 'yume_tome', 'yume_oeuvre' ), true ) ) {
		invalider_ics();
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\invalider_ics_contenu' );
add_action( 'deleted_post', __NAMESPACE__ . '\\invalider_ics_contenu' );

/**
 * Construit le calendrier ICS (RFC 5545) : un VEVENT par tome daté du planning public.
 *
 * - prévu (date cible indicative) : DTSTART;VALUE=DATE, STATUS:TENTATIVE, « (prévision) » ;
 * - programmé (statut future) : DTSTART en UTC à l'heure programmée, STATUS:CONFIRMED ;
 * - sorti : DTSTART en UTC à la date de sortie, STATUS:CONFIRMED.
 *
 * @param int $oeuvre_id Œuvre (0 : toutes).
 */
function construire_ics( int $oeuvre_id = 0 ): string {
	$hote = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$nom  = $oeuvre_id
		/* translators: 1: œuvre, 2: site */
		? sprintf( __( 'Sorties — %1$s (%2$s)', 'yume-core' ), titre_brut( $oeuvre_id ), $site )
		/* translators: %s : site */
		: sprintf( __( 'Sorties — %s', 'yume-core' ), $site );
	$l     = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Yume Novel//Planning des sorties//FR',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'X-WR-CALNAME:' . texte_ics( $nom ),
		'X-WR-CALDESC:' . texte_ics( __( 'Sorties prévues (dates indicatives), programmées et parues.', 'yume-core' ) ),
		'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
		'X-PUBLISHED-TTL:PT6H',
	);
	$maint = gmdate( 'Ymd\THis\Z', maintenant() );
	foreach ( evenements_calendrier( $oeuvre_id ) as $e ) {
		$l[] = 'BEGIN:VEVENT';
		$l[] = 'UID:tome-' . (int) $e['tome_id'] . '@' . $hote;
		$l[] = 'DTSTAMP:' . ( $e['maj'] > 0 ? gmdate( 'Ymd\THis\Z', $e['maj'] ) : $maint );
		if ( 'prevu' === $e['nature'] ) {
			$jour = str_replace( '-', '', $e['jour'] );
			$l[]  = 'DTSTART;VALUE=DATE:' . $jour;
			$l[]  = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', (int) strtotime( $e['jour'] . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
			$l[]  = 'STATUS:TENTATIVE';
			$l[]  = 'TRANSP:TRANSPARENT';
			/* translators: %s : « Œuvre T.2 » */
			$resume = sprintf( __( '%s (prévision)', 'yume-core' ), $e['titre'] );
		} else {
			$l[]    = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $e['ts'] );
			$l[]    = 'DTEND:' . gmdate( 'Ymd\THis\Z', $e['ts'] + HOUR_IN_SECONDS );
			$l[]    = 'STATUS:CONFIRMED';
			$resume = $e['titre'];
		}
		$l[] = 'SUMMARY:' . texte_ics( $resume );
		/* translators: %s : état (« Programmé le sam. 3 oct. », « En retard de 3 j ») */
		$description = sprintf( __( 'État : %s', 'yume-core' ), $e['etat'] );
		if ( 'prevu' === $e['nature'] ) {
			$description .= "\n" . __( 'Date indicative : la relecture décide.', 'yume-core' );
		}
		if ( '' !== $e['url'] ) {
			$description .= "\n" . $e['url'];
		}
		$l[] = 'DESCRIPTION:' . texte_ics( $description );
		if ( '' !== $e['url'] ) {
			$l[] = 'URL:' . esc_url_raw( $e['url'] );
		}
		$l[] = 'END:VEVENT';
	}
	$l[] = 'END:VCALENDAR';
	return implode( '', array_map( __NAMESPACE__ . '\\plier_ics', $l ) );
}

/**
 * <link rel="alternate" type="text/calendar"> sur la page du planning public.
 */
function lien_ics_entete(): void {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post || ( ! has_block( 'yume/planning', $post ) && ! has_block( 'yume/calendrier', $post ) ) ) {
		return;
	}
	printf(
		'<link rel="alternate" type="text/calendar" title="%s" href="%s" />' . "\n",
		esc_attr__( 'Calendrier des sorties (ICS)', 'yume-core' ),
		esc_url( url_ics() )
	);
}
add_action( 'wp_head', __NAMESPACE__ . '\\lien_ics_entete', 4 );
