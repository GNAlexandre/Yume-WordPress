<?php
/**
 * Routes REST du module mise-en-page (contrat §12) :
 *
 * - GET  /yume/v1/chapitres/{id}/mise-en-page           état : chapitre, unités, droits, liens,
 *   présence d'un autre membre (verrou souple posé ici) ;
 * - PUT  /yume/v1/chapitres/{id}/mise-en-page           { base, empreinte, unites } : enregistre
 *   (révision, sans annonce, journal « mise_en_page » privé) → nouvel état ; 409
 *   yume_mise_en_page_conflit si le chapitre a changé depuis « base » ; 400 unité invalide ;
 * - POST /yume/v1/chapitres/{id}/mise-en-page/presence  rafraîchit le verrou souple (2 min).
 *
 * Permission : compte connecté avec yume_publier et edit_post sur le contenu, filtre
 * yume_mise_en_page_peut( bool, $chapitre_id, $user_id ). Le type (chapitre) est contrôlé dans
 * les rappels (404 yume_mise_en_page_introuvable).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MiseEnPage;

use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

/** Espace de noms REST. */
const REST_NS = 'yume/v1';

/** Durée du verrou souple (secondes) : un autre membre est signalé s'il a ouvert le chapitre depuis moins de 10 minutes. */
const PRESENCE_DUREE = 600;

/** Champ du journal du planning. */
const CHAMP_JOURNAL = 'mise_en_page';

/**
 * Le compte peut-il mettre en page ce contenu ?
 *
 * @param int $chapitre_id Chapitre.
 * @param int $user_id     Compte (0 : compte courant).
 */
function peut( int $chapitre_id, int $user_id = 0 ): bool {
	$user_id = $user_id ? $user_id : get_current_user_id();
	$peut    = $user_id > 0 && $chapitre_id > 0 && user_can( $user_id, 'yume_publier' ) && user_can( $user_id, 'edit_post', $chapitre_id );
	/**
	 * Filtre le droit de mettre en page un chapitre (ouverture future aux traducteurs de leurs tomes).
	 *
	 * @param bool $peut        Droit calculé (yume_publier et edit_post).
	 * @param int  $chapitre_id Chapitre.
	 * @param int  $user_id     Compte.
	 */
	return $user_id > 0 && (bool) apply_filters( 'yume_mise_en_page_peut', $peut, $chapitre_id, $user_id );
}

/**
 * Chapitre modifiable par la vue (texte importé, hors corbeille et versions en attente), ou null.
 *
 * @param int $chapitre_id Chapitre.
 */
function chapitre( int $chapitre_id ): ?\WP_Post {
	$post = $chapitre_id > 0 ? get_post( $chapitre_id ) : null;
	if ( ! $post || 'yume_chapitre' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit', 'yume_remplacement' ), true ) ) {
		return null;
	}
	return $post;
}

/**
 * Le chapitre est-il hébergé sur MangaDex (aucun texte à mettre en page) ?
 *
 * @param int $chapitre_id Chapitre.
 */
function est_mangadex( int $chapitre_id ): bool {
	return '' !== (string) get_post_meta( $chapitre_id, 'yume_mdx_chapitre', true );
}

/**
 * Titre affiché d'un chapitre : « Chapitre 2, partie 1 — 10 septembre, soir ».
 *
 * @param int $chapitre_id Chapitre.
 */
function titre_chapitre( int $chapitre_id ): string {
	$libelle = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : '';
	$sous    = trim( (string) get_post_meta( $chapitre_id, 'yume_sous_titre', true ) );
	if ( '' === $libelle ) {
		return (string) get_the_title( $chapitre_id );
	}
	return '' !== $sous && 0 !== strcasecmp( $sous, $libelle ) ? $libelle . ' — ' . $sous : $libelle;
}

/**
 * Nom affiché d'un compte.
 *
 * @param int $user_id Compte.
 */
function nom_membre( int $user_id ): string {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	return $user ? (string) $user->display_name : __( 'un membre de l’équipe', 'yume-core' );
}

/**
 * Heure du site au format « 18 h 02 ».
 *
 * @param int $ts Horodatage.
 */
function heure( int $ts ): string {
	return (string) wp_date( 'G \h i', $ts );
}

/**
 * Verrou souple : note la présence du compte sur le chapitre (transient yume_mep_<id> =
 * {user, depuis, vu}) et renvoie celle d'un autre membre qui l'a ouvert depuis moins de
 * 10 minutes (sans l'écraser), ou null. Information seulement : le 409 protège l'enregistrement.
 *
 * @param int $chapitre_id Chapitre.
 * @param int $user_id     Compte.
 * @return array{nom:string,depuis:int,message:string}|null
 */
function presence( int $chapitre_id, int $user_id ): ?array {
	$cle    = 'yume_mep_' . $chapitre_id;
	$valeur = get_transient( $cle );
	$now    = time();
	if ( is_array( $valeur ) && isset( $valeur['user'], $valeur['vu'] ) && (int) $valeur['user'] !== $user_id && (int) $valeur['vu'] >= $now - PRESENCE_DUREE ) {
		$depuis  = (int) ( $valeur['depuis'] ?? $valeur['vu'] );
		$minutes = max( 1, (int) round( ( $now - $depuis ) / MINUTE_IN_SECONDS ) );
		return array(
			'nom'     => nom_membre( (int) $valeur['user'] ),
			'depuis'  => $depuis,
			'message' => sprintf(
				/* translators: 1: membre, 2: minutes */
				_n( '%1$s a ouvert ce chapitre il y a %2$d minute : ses modifications et les vôtres pourraient se croiser. Vous pouvez enregistrer quand même, un conflit serait signalé.', '%1$s a ouvert ce chapitre il y a %2$d minutes : ses modifications et les vôtres pourraient se croiser. Vous pouvez enregistrer quand même, un conflit serait signalé.', $minutes, 'yume-core' ),
				nom_membre( (int) $valeur['user'] ),
				$minutes
			),
		);
	}
	$depuis = is_array( $valeur ) && (int) ( $valeur['user'] ?? 0 ) === $user_id ? (int) ( $valeur['depuis'] ?? $now ) : $now;
	set_transient(
		$cle,
		array(
			'user'   => $user_id,
			'depuis' => $depuis,
			'vu'     => $now,
		),
		PRESENCE_DUREE
	);
	return null;
}

/**
 * Lien vers un chapitre voisin dans le même tome (tous statuts vivants), pour la vue.
 *
 * @param \WP_Post $post Chapitre.
 * @param int      $pas  -1 : précédent, 1 : suivant.
 * @return array{id:int,libelle:string,url:string}|null
 */
function voisin( \WP_Post $post, int $pas ): ?array {
	$tome_id = (int) get_post_meta( $post->ID, 'yume_tome_id', true );
	if ( ! $tome_id || ! function_exists( 'yume_get_chapitres' ) ) {
		return null;
	}
	$ids  = array_map( 'intval', wp_list_pluck( yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ), 'ID' ) );
	$rang = array_search( (int) $post->ID, $ids, true );
	if ( false === $rang || ! isset( $ids[ $rang + $pas ] ) ) {
		return null;
	}
	$id = $ids[ $rang + $pas ];
	return array(
		'id'      => $id,
		'libelle' => titre_chapitre( $id ),
		'url'     => url_vue( $id ),
	);
}

/**
 * Adresse de lecture d'un chapitre : page publique s'il est en ligne, sinon aperçu.
 *
 * @param \WP_Post $post Chapitre.
 */
function url_lecture( \WP_Post $post ): string {
	return in_array( $post->post_status, array( 'publish', 'private' ), true ) ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post );
}

/**
 * Historique des révisions (écran WordPress revision.php), ou ''.
 *
 * @param int $chapitre_id Chapitre.
 */
function url_revisions( int $chapitre_id ): string {
	$revisions = wp_get_post_revisions(
		$chapitre_id,
		array(
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	$derniere  = $revisions ? (int) reset( $revisions ) : 0;
	return $derniere ? admin_url( 'revision.php?revision=' . $derniere ) : '';
}

/**
 * État du chapitre envoyé à la vue (réponse du GET et du PUT).
 *
 * @param \WP_Post $post    Chapitre.
 * @param int      $user_id Compte.
 * @return array<string,mixed>
 */
function etat( \WP_Post $post, int $user_id ): array {
	$tome_id   = (int) get_post_meta( $post->ID, 'yume_tome_id', true );
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? (int) yume_get_oeuvre_id( (int) $post->ID ) : (int) get_post_meta( $post->ID, 'yume_oeuvre_id', true );
	$dernier   = (int) get_post_meta( $post->ID, '_edit_last', true );
	$unites    = est_mangadex( (int) $post->ID ) ? array() : Unites::decouper( (string) $post->post_content );
	return array(
		'chapitre'         => array(
			'id'        => (int) $post->ID,
			'titre'     => (string) $post->post_title,
			'libelle'   => titre_chapitre( (int) $post->ID ),
			'url'       => url_lecture( $post ),
			'tome'      => array(
				'id'    => $tome_id,
				'titre' => $tome_id ? (string) get_the_title( $tome_id ) : '',
				'url'   => $tome_id && function_exists( 'Yume\\Core\\Planning\\url_modifier_tome' ) ? \Yume\Core\Planning\url_modifier_tome( $tome_id ) : '',
			),
			'oeuvre'    => array(
				'id'    => $oeuvre_id,
				'titre' => $oeuvre_id ? (string) get_the_title( $oeuvre_id ) : '',
			),
			'statut'    => (string) $post->post_status,
			'modifie'   => (string) $post->post_modified_gmt,
			'empreinte' => md5( (string) $post->post_content ),
			'mots'      => (int) get_post_meta( $post->ID, 'yume_nb_mots', true ),
			'par'       => $dernier ? nom_membre( $dernier ) : '',
		),
		'unites'           => Unites::publiques( $unites ),
		'peut_enregistrer' => peut( (int) $post->ID, $user_id ) && ! est_mangadex( (int) $post->ID ),
		'liens'            => array(
			'voir'      => url_lecture( $post ),
			'precedent' => voisin( $post, -1 ),
			'suivant'   => voisin( $post, 1 ),
			'revisions' => url_revisions( (int) $post->ID ),
			'avancee'   => (string) get_edit_post_link( $post->ID, 'raw' ),
		),
	);
}

/**
 * Permission des routes.
 *
 * @param \WP_REST_Request $request Requête.
 * @return true|\WP_Error
 */
function permission( \WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error( 'rest_forbidden', __( 'Connectez-vous pour mettre en page un chapitre.', 'yume-core' ), array( 'status' => rest_authorization_required_code() ) );
	}
	if ( ! peut( (int) $request['id'] ) ) {
		return new \WP_Error( 'rest_forbidden', __( 'Votre rôle ne permet pas de mettre en page ce chapitre.', 'yume-core' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Chapitre de la requête, ou erreur 404 / 409 (MangaDex).
 *
 * @param \WP_REST_Request $request Requête.
 * @return \WP_Post|\WP_Error
 */
function chapitre_requete( \WP_REST_Request $request ) {
	$post = chapitre( (int) $request['id'] );
	if ( ! $post ) {
		return new \WP_Error( 'yume_mise_en_page_introuvable', __( 'Ce chapitre n’existe pas ou a été supprimé.', 'yume-core' ), array( 'status' => 404 ) );
	}
	return $post;
}

/**
 * Déclare les routes.
 */
function enregistrer_routes(): void {
	$id = array(
		'description'       => __( 'Identifiant du chapitre.', 'yume-core' ),
		'type'              => 'integer',
		'minimum'           => 1,
		'required'          => true,
		'validate_callback' => 'rest_validate_request_arg',
		'sanitize_callback' => 'absint',
	);
	register_rest_route(
		REST_NS,
		'/chapitres/(?P<id>\d+)/mise-en-page',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_lire',
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'args'                => array( 'id' => $id ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => __NAMESPACE__ . '\\rest_enregistrer',
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'args'                => array(
					'id'        => $id,
					'base'      => array(
						'description' => __( 'Date de dernière modification (GMT) du chapitre reçu.', 'yume-core' ),
						'type'        => 'string',
						'required'    => true,
					),
					'empreinte' => array(
						'description' => __( 'Empreinte du contenu reçu (md5).', 'yume-core' ),
						'type'        => 'string',
						'default'     => '',
					),
					'unites'    => array(
						'description' => __( 'Unités de mise en page, dans l’ordre.', 'yume-core' ),
						'type'        => 'array',
						'required'    => true,
					),
				),
			),
		)
	);
	register_rest_route(
		REST_NS,
		'/chapitres/(?P<id>\d+)/mise-en-page/presence',
		array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\rest_presence',
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'args'                => array( 'id' => $id ),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_routes' );

/**
 * GET : état du chapitre et présence d'un autre membre (verrou souple posé).
 *
 * @param \WP_REST_Request $request Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_lire( \WP_REST_Request $request ) {
	$post = chapitre_requete( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$user_id             = get_current_user_id();
	$reponse             = etat( $post, $user_id );
	$reponse['presence'] = presence( (int) $post->ID, $user_id );
	return rest_ensure_response( $reponse );
}

/**
 * POST …/presence : rafraîchit le verrou souple.
 *
 * @param \WP_REST_Request $request Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_presence( \WP_REST_Request $request ) {
	$post = chapitre_requete( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	return rest_ensure_response( array( 'presence' => presence( (int) $post->ID, get_current_user_id() ) ) );
}

/**
 * Message d'un nombre de paragraphes modifiés.
 *
 * @param int $n Nombre.
 */
function texte_modifies( int $n ): string {
	/* translators: %d : nombre de paragraphes */
	return sprintf( _n( '%d paragraphe modifié', '%d paragraphes modifiés', $n, 'yume-core' ), $n );
}

/**
 * Enregistre une nouvelle mise en page d'un chapitre (sans contrôle de droit : voir permission()).
 *
 * - 409 yume_mise_en_page_conflit si post_modified_gmt (ou l'empreinte du contenu, si elle est
 *   envoyée) a changé depuis « base » : données {etat, par, a} ;
 * - 400 yume_mise_en_page_unite (Unites::appliquer()) ;
 * - wp_update_post du seul contenu (statut, date et métas de publication inchangés), révision
 *   créée (la version d'origine aussi, si le chapitre n'en avait pas), aucune notification
 *   (yume_core_notifier coupé pendant l'enregistrement, comme « Mettre à jour (sans annonce) ») ;
 *   yume_nb_mots et yume_temps_lecture recalculés par core (wp_after_insert_post) ;
 * - empreinte de comparaison rafraîchie et marquée « mise en page » : le prochain dépôt du Word
 *   signale le chapitre « En ligne, modifié » ;
 * - journal du planning « mise_en_page » (privé).
 *
 * @param int    $chapitre_id Chapitre.
 * @param string $base        post_modified_gmt reçu.
 * @param string $empreinte   md5 du contenu reçu ('' : non contrôlée).
 * @param mixed  $unites      Unités saisies.
 * @param int    $user_id     Compte.
 * @return array{enregistre:bool,modifies:int,message:string}|\WP_Error
 */
function enregistrer( int $chapitre_id, string $base, string $empreinte, $unites, int $user_id ) {
	$post = chapitre( $chapitre_id );
	if ( ! $post ) {
		return new \WP_Error( 'yume_mise_en_page_introuvable', __( 'Ce chapitre n’existe pas ou a été supprimé.', 'yume-core' ), array( 'status' => 404 ) );
	}
	if ( est_mangadex( $chapitre_id ) ) {
		return new \WP_Error( 'yume_mise_en_page_mangadex', __( 'Ce chapitre est hébergé sur MangaDex : il n’a pas de texte à mettre en page.', 'yume-core' ), array( 'status' => 409 ) );
	}
	if ( $base !== (string) $post->post_modified_gmt || ( '' !== $empreinte && md5( (string) $post->post_content ) !== $empreinte ) ) {
		$dernier = (int) get_post_meta( $chapitre_id, '_edit_last', true );
		$a       = heure( (int) strtotime( $post->post_modified_gmt . ' UTC' ) );
		$par     = $dernier ? nom_membre( $dernier ) : '';
		return new \WP_Error(
			'yume_mise_en_page_conflit',
			'' !== $par
				/* translators: 1: membre, 2: heure (« 18 h 01 ») */
				? sprintf( __( 'Quelqu’un a enregistré ce chapitre entre-temps (%1$s, %2$s) : rechargez pour voir sa version.', 'yume-core' ), $par, $a )
				/* translators: %s : heure (« 18 h 01 ») */
				: sprintf( __( 'Quelqu’un a enregistré ce chapitre entre-temps (%s) : rechargez pour voir sa version.', 'yume-core' ), $a ),
			array(
				'status' => 409,
				'par'    => $par,
				'a'      => $a,
				'etat'   => etat( $post, $user_id ),
			)
		);
	}
	$resultat = Unites::appliquer( (string) $post->post_content, $unites );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	if ( $resultat['contenu'] === (string) $post->post_content ) {
		return array(
			'enregistre' => false,
			'modifies'   => 0,
			'message'    => __( 'Aucune modification à enregistrer.', 'yume-core' ),
		);
	}
	// Première retouche : la version d'origine entre dans l'historique avant d'être remplacée.
	if ( post_type_supports( $post->post_type, 'revisions' ) && ! wp_get_post_revisions( $chapitre_id, array( 'fields' => 'ids' ) ) ) {
		wp_save_post_revision( $chapitre_id );
	}
	$muet = static function (): bool {
		return false;
	};
	add_filter( 'yume_core_notifier', $muet, 99 );
	try {
		$maj = wp_update_post(
			wp_slash(
				array(
					'ID'           => $chapitre_id,
					'post_content' => $resultat['contenu'],
				)
			),
			true
		);
	} finally {
		remove_filter( 'yume_core_notifier', $muet, 99 );
	}
	if ( is_wp_error( $maj ) ) {
		return new \WP_Error( 'yume_mise_en_page_echec', $maj->get_error_message(), array( 'status' => 500 ) );
	}
	update_post_meta( $chapitre_id, '_edit_last', $user_id );
	noter_empreinte( $chapitre_id );
	$tome_id = (int) get_post_meta( $chapitre_id, 'yume_tome_id', true );
	if ( function_exists( 'yume_journal_planning' ) ) {
		yume_journal_planning(
			$tome_id,
			$user_id,
			CHAMP_JOURNAL,
			'',
			array(
				'chapitre' => $chapitre_id,
				'libelle'  => titre_chapitre( $chapitre_id ),
				'modifies' => (int) $resultat['modifies'],
			)
		);
	}
	/**
	 * Un chapitre vient d'être mis en page depuis la vue d'équipe (aucune annonce).
	 *
	 * @param int $chapitre_id Chapitre.
	 * @param int $modifies    Paragraphes modifiés.
	 * @param int $user_id     Compte.
	 */
	do_action( 'yume_mise_en_page_enregistree', $chapitre_id, (int) $resultat['modifies'], $user_id );
	return array(
		'enregistre' => true,
		'modifies'   => (int) $resultat['modifies'],
		/* translators: %s : « 12 paragraphes modifiés » */
		'message'    => sprintf( __( 'Mise en page enregistrée : %s.', 'yume-core' ), texte_modifies( (int) $resultat['modifies'] ) ),
	);
}

/**
 * Empreinte de comparaison du chapitre (Publication\Service) rafraîchie, puis marquée : même si
 * seul le style a changé (texte identique), le prochain dépôt du Word signale « En ligne,
 * modifié » et non « identique », pour que l'équipe choisisse en connaissance de cause.
 *
 * @param int $chapitre_id Chapitre.
 */
function noter_empreinte( int $chapitre_id ): void {
	if ( ! class_exists( Service::class ) ) {
		return;
	}
	Service::noter_empreinte( $chapitre_id );
	$meta = get_post_meta( $chapitre_id, Service::META_EMPREINTE, true );
	if ( is_array( $meta ) && is_string( $meta['texte'] ?? null ) ) {
		$meta['texte'] = md5( $meta['texte'] . "\n" . CHAMP_JOURNAL );
		update_post_meta( $chapitre_id, Service::META_EMPREINTE, $meta );
	}
}

/**
 * PUT : enregistre la mise en page et renvoie le nouvel état.
 *
 * @param \WP_REST_Request $request Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_enregistrer( \WP_REST_Request $request ) {
	$post = chapitre_requete( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$user_id  = get_current_user_id();
	$resultat = enregistrer(
		(int) $post->ID,
		is_string( $request['base'] ) ? $request['base'] : '',
		is_string( $request['empreinte'] ) ? $request['empreinte'] : '',
		$request['unites'],
		$user_id
	);
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	clean_post_cache( (int) $post->ID );
	$reponse = array_merge( etat( get_post( (int) $post->ID ), $user_id ), $resultat );
	return rest_ensure_response( $reponse );
}
