<?php
/**
 * Routes REST de la publication (contrat §12), capacité yume_publier :
 *
 * - POST /yume/v1/publications/analyse          multipart « source » → rapport, rien n'est créé ;
 * - POST /yume/v1/publications                  multipart (source, couverture facultatives) + champs →
 *                                               tome et chapitres en brouillon (ou mis à jour), rapport ;
 * - POST /yume/v1/publications/(?P<id>\d+)/publier  « quand » = maintenant | date ISO (applique
 *                                               d'abord un remplacement de lecture en ligne en attente) ;
 * - DELETE /yume/v1/publications/(?P<id>\d+)/remplacement  annule le remplacement en attente
 *                                               (versions et images supprimées, rien ne change en ligne).
 *
 * Paramètre « plan » (analyse et création) : découpage manuel en chapitres, en JSON ou en objet
 * (Service::plan()), appliqué au fichier « source » envoyé dans la même requête : le fichier n'est
 * jamais conservé entre deux requêtes.
 *
 * Paramètre booléen « sans_annonce » (création et publication) : ajout au catalogue, sans
 * article d'annonce, ni Discord, ni e-mail (Service::ajouter_au_catalogue()). Absent, il vaut
 * vrai pour un tome déjà paru (statut publish) et faux sinon (nouveau tome, brouillon, tome
 * programmé) : Service::sans_annonce_par_defaut(). « annoncer » (booléen) en est l'inverse
 * (case « Annoncer les nouveaux chapitres » ; sans_annonce l'emporte s'ils sont tous deux là).
 *
 * Ajout de chapitres à un tome (paramètre « mode » = chapitres, création et publication ; voir
 * Service, en-tête) : « tome_id » (tome choisi, jamais renommé), « choix » (objet clé de
 * chapitre => garder | maj, d'après la comparaison renvoyée par l'analyse), « complet »
 * (booléen : le tome est complet avec ces chapitres ; liens « lien_pdf » / « lien_epub »),
 * « sortie » (maintenant | rythme | date) et « intervalle » (jours, un par un sans rythme). Sans
 * « mode » : comportement historique (outil en ligne de commande, intégrations).
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
					'tome_id'   => $champs['tome_id'],
					'nature'    => $champs['nature'],
					'numero'    => $champs['numero'],
					'plan'      => $champs['plan'],
					'choix'     => $champs['choix'],
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
					'sans_annonce'   => self::argument_sans_annonce(),
					'annoncer'       => $champs['annoncer'],
					'mode'           => $champs['mode'],
					'complet'        => $champs['complet'],
					'lien_pdf'       => $champs['lien_pdf'],
					'lien_epub'      => $champs['lien_epub'],
					'sortie'         => $champs['sortie'],
					'intervalle'     => $champs['intervalle'],
				),
			)
		);
		register_rest_route(
			self::ESPACE,
			'/publications/(?P<id>\d+)/remplacement',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'annuler_remplacement' ),
				'permission_callback' => array( self::class, 'peut_modifier_tome' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Identifiant du tome.', 'yume-core' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
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
			'sans_annonce'    => self::argument_sans_annonce(),
			'annoncer'        => array(
				'description' => __( 'Annoncer les nouveaux chapitres (inverse de sans_annonce, qui l’emporte).', 'yume-core' ),
				'type'        => 'boolean',
			),
			'mode'            => array(
				'description' => __( 'chapitres : ajouter des chapitres au tome choisi (tome_id), comparés au tome, rien n’est retiré ; remplacement : remplacer la lecture en ligne en deux temps ; absent : comportement historique.', 'yume-core' ),
				'type'        => 'string',
				'enum'        => array( '', Service::MODE_CHAPITRES, Service::MODE_REMPLACEMENT ),
			),
			'choix'           => array(
				'description'       => __( 'Chapitres en ligne modifiés : clé de chapitre (comparaison de l’analyse) => garder (défaut) ou maj (mettre à jour, sans annonce). Objet ou JSON.', 'yume-core' ),
				'validate_callback' => static function ( $valeur ) {
					$choix = Service::choix( $valeur );
					return is_wp_error( $choix ) ? $choix : true;
				},
				'sanitize_callback' => static function ( $valeur ) {
					return is_array( $valeur ) || is_string( $valeur ) ? $valeur : array();
				},
			),
			'complet'         => array(
				'description' => __( 'Le tome est complet avec ces chapitres (mode chapitres) : liens PDF/EPUB posés, parution « complet », planning publié, annonce de fin.', 'yume-core' ),
				'type'        => 'boolean',
			),
			'sortie'          => array(
				'description' => __( 'Sortie des nouveaux chapitres (mode chapitres) : maintenant (ensemble, une annonce), rythme (un par un au rythme du tome, ou tous les « intervalle » jours depuis « quand »), date (ensemble à la date « quand »).', 'yume-core' ),
				'type'        => 'string',
				'enum'        => Service::SORTIES,
			),
			'intervalle'      => array(
				'description' => __( 'Jours entre deux chapitres, sortie un par un d’un tome sans rythme (défaut 7).', 'yume-core' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 60,
			),
			'plan'            => array(
				'description'       => __( 'Découpage manuel du fichier source (JSON ou objet) : {"debuts": [{"ancre": "e12-3fa9c1", "nature": "chapitre", "titre": "…", "numero": 3 (facultatif)}], "garder_avant": false}. Les repères viennent de l’analyse du même fichier.', 'yume-core' ),
				// Contrôle strict par Service::plan() (erreur 400 lisible) ; valeur transmise telle quelle.
				'validate_callback' => static function ( $valeur ) {
					$plan = Service::plan( $valeur );
					return is_wp_error( $plan ) ? $plan : true;
				},
				'sanitize_callback' => static function ( $valeur ) {
					return is_array( $valeur ) || is_string( $valeur ) ? $valeur : null;
				},
			),
		);
	}

	/**
	 * Argument « sans_annonce » (sans valeur par défaut : absent, il dépend du tome).
	 *
	 * @return array<string,mixed>
	 */
	private static function argument_sans_annonce(): array {
		return array(
			'description' => __( 'Ajout au catalogue sans annonce (ni article, ni Discord, ni e-mail). Absent : vrai pour un tome déjà publié, faux sinon.', 'yume-core' ),
			'type'        => 'boolean',
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
	 * Permission : yume_publier et droit de modifier ce tome.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return true|\WP_Error
	 */
	public static function peut_modifier_tome( \WP_REST_Request $requete ) {
		$ok = self::peut_publier();
		if ( true !== $ok ) {
			return $ok;
		}
		$id = (int) $requete['id'];
		if ( 'yume_tome' !== get_post_type( $id ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Tome introuvable.', 'yume-core' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Votre compte ne peut pas modifier ce tome.', 'yume-core' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * DELETE /publications/{id}/remplacement.
	 *
	 * @param \WP_REST_Request $requete Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function annuler_remplacement( \WP_REST_Request $requete ) {
		$id = (int) $requete['id'];
		if ( ! Remplacement::annuler( $id ) ) {
			return new \WP_Error( 'yume_remplacement_absent', __( 'Aucun remplacement de la lecture en ligne n’est en attente pour ce tome.', 'yume-core' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'annule'  => true,
				'message' => Formulaire::message_annulation(),
			)
		);
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
		foreach ( array( 'oeuvre_id', 'tome_id', 'nature', 'numero', 'titre', 'date_sortie', 'lien_pdf', 'lien_epub', 'credits', 'couverture_id', 'retirer_absents', 'sans_annonce', 'annoncer', 'credits_traduction', 'credits_relecture', 'credits_edition', 'plan', 'mode', 'choix', 'complet' ) as $cle ) {
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
		if ( is_array( $rapport['comparaison'] ?? null ) ) {
			// Ajout de chapitres : message du brouillon (affiché par le formulaire).
			$rapport['message'] = Formulaire::message_brouillon_chapitres( $rapport );
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
		$id           = (int) $requete['id'];
		$sans_annonce = $requete->get_param( 'sans_annonce' );
		if ( null === $sans_annonce && null !== $requete->get_param( 'annoncer' ) ) {
			$sans_annonce = ! $requete->get_param( 'annoncer' );
		}
		$options = array(
			'confirmer_vide' => (bool) $requete->get_param( 'confirmer_vide' ),
			'sans_annonce'   => null === $sans_annonce ? Service::sans_annonce_par_defaut( $id ) : (bool) $sans_annonce,
		);
		if ( Service::MODE_CHAPITRES === $requete->get_param( 'mode' ) ) {
			$liens   = array_filter(
				array(
					'lien_pdf'  => $requete->get_param( 'lien_pdf' ),
					'lien_epub' => $requete->get_param( 'lien_epub' ),
				),
				static fn( $lien ): bool => null !== $lien
			);
			$options = array_merge(
				$options,
				array(
					'mode'       => Service::MODE_CHAPITRES,
					'sortie'     => (string) $requete->get_param( 'sortie' ),
					'intervalle' => null === $requete->get_param( 'intervalle' ) ? Service::INTERVALLE_DEFAUT : (int) $requete->get_param( 'intervalle' ),
					'complet'    => null === $requete->get_param( 'complet' ) ? null : (bool) $requete->get_param( 'complet' ),
					'liens'      => $liens,
				)
			);
		}
		$resultat = Service::publier( $id, (string) $requete->get_param( 'quand' ), $options );
		if ( is_wp_error( $resultat ) ) {
			return self::erreur( $resultat );
		}
		if ( Service::MODE_CHAPITRES === ( $resultat['mode'] ?? '' ) ) {
			$resultat['message'] = Formulaire::message_chapitres( $resultat );
		}
		return rest_ensure_response( $resultat );
	}
}
