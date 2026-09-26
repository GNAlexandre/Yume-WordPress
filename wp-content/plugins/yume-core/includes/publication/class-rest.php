<?php
/**
 * Routes REST de la publication (contrat §12), capacité yume_publier :
 *
 * - POST /yume/v1/publications/analyse          multipart « source » → rapport, rien n'est créé ;
 * - POST /yume/v1/publications                  multipart (source, couverture facultatives) + champs →
 *                                               tome et chapitres en brouillon (ou mis à jour), rapport ;
 * - POST /yume/v1/publications/(?P<id>\d+)/publier  « quand » = maintenant | date ISO.
 *
 * Les fichiers téléversés sont contrôlés (type réel, extension, taille) et supprimés après
 * traitement. Authentification : cookie + nonce wp_rest (formulaire) ou mot de passe
 * d'application (outil en ligne de commande).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

/**
 * Contrôleur REST.
 */
final class Rest {

	/** Espace de noms. */
	public const ESPACE = 'yume/v1';

	/**
	 * Enregistre les routes.
	 */
	public static function enregistrer_routes(): void {
		$champs = self::arguments();
		register_rest_route(
			self::ESPACE,
			'/publications/analyse',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'analyse' ),
				'permission_callback' => array( self::class, 'peut_publier' ),
				'args'                => array(
					'oeuvre_id' => $champs['oeuvre_id'],
					'nature'    => $champs['nature'],
					'numero'    => $champs['numero'],
				),
			)
		);
		register_rest_route(
			self::ESPACE,
			'/publications',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'creer' ),
				'permission_callback' => array( self::class, 'peut_publier' ),
				'args'                => array_merge( $champs, array( 'oeuvre_id' => array_merge( $champs['oeuvre_id'], array( 'required' => true ) ) ) ),
			)
		);
		register_rest_route(
			self::ESPACE,
			'/publications/(?P<id>\d+)/publier',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'publier' ),
				'permission_callback' => array( self::class, 'peut_publier_tome' ),
				'args'                => array(
					'id'             => array(
						'description' => __( 'Identifiant du tome.', 'yume-core' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					),
					'quand'          => array(
						'description'       => __( '« maintenant » ou date de sortie ISO 8601 (heure du site si aucun fuseau).', 'yume-core' ),
						'type'              => 'string',
						'default'           => 'maintenant',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'confirmer_vide' => array(
						'description' => __( 'Confirme la publication d’un tome sans chapitre ni lien PDF/EPUB (sinon erreur yume_tome_vide, 409).', 'yume-core' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);
	}

	/**
	 * Arguments communs des champs de publication.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function arguments(): array {
		$texte = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
		$lien  = array(
			'type'              => 'string',
			'description'       => __( 'Lien externe de téléchargement (http ou https).', 'yume-core' ),
			'validate_callback' => static function ( $valeur, $requete, $param ) {
				$ok = Service::lien_externe( $valeur, 'lien_pdf' === $param ? 'PDF' : 'EPUB' );
				return is_wp_error( $ok ) ? $ok : true;
			},
		);
		return array(
			'oeuvre_id'       => array(
				'description' => __( 'Œuvre du tome.', 'yume-core' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'tome_id'         => array(
				'description' => __( 'Tome déjà préparé (facultatif).', 'yume-core' ),
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'nature'          => array(
				'description' => __( 'Nature du tome.', 'yume-core' ),
				'type'        => 'string',
				'enum'        => array_keys( yume_natures_tome() ),
				'default'     => 'tome',
			),
			'numero'          => array(
				'description'       => __( 'Numéro du tome (10, 26.5…).', 'yume-core' ),
				'type'              => array( 'string', 'number' ),
				'validate_callback' => static function ( $valeur ) {
					if ( '' === $valeur || null === $valeur || null !== Service::numero( $valeur ) ) {
						return true;
					}
					return new \WP_Error( 'rest_invalid_param', __( 'Numéro invalide : indiquez un nombre (10, 26,5…).', 'yume-core' ) );
				},
			),
			'titre'           => array_merge( $texte, array( 'description' => __( 'Titre du tome (facultatif).', 'yume-core' ) ) ),
			'date_sortie'     => array_merge( $texte, array( 'description' => __( 'Date de sortie prévue (AAAA-MM-JJTHH:MM).', 'yume-core' ) ) ),
			'lien_pdf'        => $lien,
			'lien_epub'       => $lien,
			'credits'         => array(
				'description' => __( 'Crédits du tome.', 'yume-core' ),
				'type'        => 'object',
				'properties'  => array(
					'traduction' => array( 'type' => 'string' ),
					'relecture'  => array( 'type' => 'string' ),
					'edition'    => array( 'type' => 'string' ),
				),
			),
			'couverture_id'   => array(
				'description' => __( 'Image de couverture déjà présente dans la médiathèque.', 'yume-core' ),
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'retirer_absents' => array(
				'description' => __( 'Mettre en brouillon les chapitres absents du nouveau fichier.', 'yume-core' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}

	/**
	 * Permission : capacité yume_publier.
	 *
	 * @return true|\WP_Error
	 */
	public static function peut_publier() {
		if ( current_user_can( 'yume_publier' ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			is_user_logged_in() ? __( 'Votre compte n’a pas le droit de publier un tome.', 'yume-core' ) : __( 'Connectez-vous pour publier un tome.', 'yume-core' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Permission : yume_publier et droit de modifier ce tome.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return true|\WP_Error
	 */
	public static function peut_publier_tome( \WP_REST_Request $requete ) {
		$ok = self::peut_publier();
		if ( true !== $ok ) {
			return $ok;
		}
		$id = (int) $requete['id'];
		if ( 'yume_tome' !== get_post_type( $id ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Tome introuvable.', 'yume-core' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'publish_yume_tomes' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Votre compte ne peut pas publier ce tome.', 'yume-core' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Fichier téléversé d'une requête.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @param string           $nom     Nom du champ.
	 * @return array<string,mixed>|null
	 */
	private static function fichier( \WP_REST_Request $requete, string $nom ): ?array {
		$fichiers = $requete->get_file_params();
		return Fichiers::fourni( $fichiers[ $nom ] ?? null ) ? (array) $fichiers[ $nom ] : null;
	}

	/**
	 * Champs de publication d'une requête (paramètres présents seulement).
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return array<string,mixed>
	 */
	private static function champs( \WP_REST_Request $requete ): array {
		$champs = array();
		foreach ( array( 'oeuvre_id', 'tome_id', 'nature', 'numero', 'titre', 'date_sortie', 'lien_pdf', 'lien_epub', 'credits', 'couverture_id', 'retirer_absents', 'credits_traduction', 'credits_relecture', 'credits_edition' ) as $cle ) {
			if ( null !== $requete->get_param( $cle ) ) {
				$champs[ $cle ] = $requete->get_param( $cle );
			}
		}
		return $champs;
	}

	/**
	 * Réponse d'erreur (WP_Error avec statut HTTP).
	 *
	 * @param \WP_Error $erreur Erreur.
	 */
	private static function erreur( \WP_Error $erreur ): \WP_Error {
		$donnees = $erreur->get_error_data();
		if ( ! is_array( $donnees ) || empty( $donnees['status'] ) ) {
			$erreur->add_data( array( 'status' => 400 ) );
		}
		return $erreur;
	}

	/**
	 * POST /publications/analyse.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function analyse( \WP_REST_Request $requete ) {
		$fichier = self::fichier( $requete, 'source' );
		if ( null === $fichier ) {
			return new \WP_Error( 'yume_source_manquante', __( 'Déposez le fichier DOCX (ou EPUB) du tome.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$rapport = Service::analyser( $fichier, self::champs( $requete ) );
		return is_wp_error( $rapport ) ? self::erreur( $rapport ) : rest_ensure_response( $rapport );
	}

	/**
	 * POST /publications.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function creer( \WP_REST_Request $requete ) {
		$fichiers = array_filter(
			array(
				'source'     => self::fichier( $requete, 'source' ),
				'couverture' => self::fichier( $requete, 'couverture' ),
			)
		);
		$rapport  = Service::preparer( self::champs( $requete ), $fichiers );
		if ( is_wp_error( $rapport ) ) {
			return self::erreur( $rapport );
		}
		$reponse = rest_ensure_response( $rapport );
		$reponse->set_status( $rapport['tome']['reutilise'] ? 200 : 201 );
		return $reponse;
	}

	/**
	 * POST /publications/{id}/publier.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function publier( \WP_REST_Request $requete ) {
		$resultat = Service::publier( (int) $requete['id'], (string) $requete->get_param( 'quand' ), array( 'confirmer_vide' => (bool) $requete->get_param( 'confirmer_vide' ) ) );
		return is_wp_error( $resultat ) ? self::erreur( $resultat ) : rest_ensure_response( $resultat );
	}
}
