<?php
/**
 * Droits d'écriture du planning hors des routes du module (constat SEC-E-3) :
 *
 * - créer un tome, c'est l'ajouter au planning public (un brouillon y figure aussitôt) :
 *   create_posts du type yume_tome est réservé à yume_maj_planning_tous, comme
 *   POST /yume/v1/planning/tomes ;
 * - les méta de planning (étape, avancements, responsables, date cible, blocage, date et
 *   auteur de mise à jour) ne s'écrivent par l'API générique /wp/v2/tomes qu'avec
 *   yume_maj_planning_tous : les membres passent par PATCH /yume/v1/tomes/{id}/planning,
 *   validé (étape « publié », membres de l'équipe) et journalisé ;
 * - les responsables écrits par /wp/v2/tomes doivent être des membres de l'équipe.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Méta de planning protégées sur l'API générique.
 *
 * @return string[]
 */
function metas_planning_protegees(): array {
	return array( 'yume_etape', 'yume_avancement', 'yume_responsables', 'yume_date_cible', 'yume_bloque', 'yume_bloque_raison', 'yume_derniere_maj', 'yume_maj_par' );
}

/**
 * Création d'un tome réservée à yume_maj_planning_tous (register_post_type_args).
 *
 * @param array  $args      Arguments du type.
 * @param string $post_type Type.
 * @return array
 */
function args_type_tome( $args, $post_type ) {
	if ( 'yume_tome' !== $post_type || ! is_array( $args ) ) {
		return $args;
	}
	$caps                 = isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) ? $args['capabilities'] : array();
	$caps['create_posts'] = 'yume_maj_planning_tous';
	$args['capabilities'] = $caps;
	return $args;
}
add_filter( 'register_post_type_args', __NAMESPACE__ . '\\args_type_tome', 10, 2 );

/**
 * Écriture d'une méta de planning (auth_post_meta_{clé}_for_yume_tome), après le rappel du
 * cœur : il faut en plus yume_maj_planning_tous.
 *
 * @param bool   $autorise  Décision précédente.
 * @param string $meta_key  Clé.
 * @param int    $object_id Tome.
 * @param int    $user_id   Utilisateur.
 */
function auth_meta_planning( $autorise, $meta_key = '', $object_id = 0, $user_id = 0 ): bool {
	return (bool) $autorise && user_can( (int) $user_id, 'yume_maj_planning_tous' );
}

/**
 * Accroche les contrôles de méta (init, après la déclaration des méta par le cœur).
 */
function accrocher_auth_metas(): void {
	foreach ( metas_planning_protegees() as $cle ) {
		add_filter( 'auth_post_meta_' . $cle . '_for_yume_tome', __NAMESPACE__ . '\\auth_meta_planning', 20, 4 );
	}
}
add_action( 'init', __NAMESPACE__ . '\\accrocher_auth_metas', 7 );

/**
 * Responsables envoyés par /wp/v2/tomes : chaque ID doit être un membre de l'équipe
 * (rest_pre_insert_yume_tome, après le contrôle des droits, avant toute écriture).
 *
 * @param \stdClass|\WP_Error $prepare Contenu préparé.
 * @param \WP_REST_Request    $requete Requête.
 * @return \stdClass|\WP_Error
 */
function valider_responsables_rest( $prepare, $requete ) {
	if ( is_wp_error( $prepare ) || ! $requete instanceof \WP_REST_Request ) {
		return $prepare;
	}
	$meta = $requete->get_param( 'meta' );
	if ( ! is_array( $meta ) || ! array_key_exists( 'yume_responsables', $meta ) ) {
		return $prepare;
	}
	if ( ! current_user_can( 'yume_maj_planning_tous' ) ) {
		return $prepare; // Refus 403 par auth_meta_planning() à l'écriture de la méta.
	}
	$valeurs = is_object( $meta['yume_responsables'] ) ? (array) $meta['yume_responsables'] : $meta['yume_responsables'];
	foreach ( (array) $valeurs as $uid ) {
		$uid = is_numeric( $uid ) ? (int) $uid : -1;
		if ( $uid < 0 || ( $uid > 0 && ! est_membre( $uid ) ) ) {
			return erreur( 'yume_responsable_invalide', __( 'Un responsable doit être un membre de l’équipe.', 'yume-core' ), 400 );
		}
	}
	return $prepare;
}
add_filter( 'rest_pre_insert_yume_tome', __NAMESPACE__ . '\\valider_responsables_rest', 10, 2 );
