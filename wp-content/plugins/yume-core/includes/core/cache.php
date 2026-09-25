<?php
/**
 * Métadonnées dérivées (caches) et dénormalisation :
 *
 * - chapitre : yume_nb_mots et yume_temps_lecture (230 mots/min) calculés s'ils sont absents
 *   ou si le texte a changé ; yume_oeuvre_id recopié depuis le tome ;
 * - tome : yume_nb_chapitres (chapitres publiés) ;
 * - œuvre : yume_derniere_sortie (date GMT du dernier tome ou chapitre publié).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Compte les mots d'un contenu (balises, commentaires de blocs et notes exclus du compte
 * des balises ; « l’homme » compte pour un mot, comme dans un traitement de texte).
 *
 * @param string $contenu Contenu HTML / blocs.
 */
function compter_mots( string $contenu ): int {
	$texte = wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', ' ', $contenu ) );
	$texte = html_entity_decode( $texte, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$nb    = preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $texte );
	return false === $nb ? 0 : (int) $nb;
}

/**
 * Temps de lecture en minutes (arrondi au supérieur, 1 minute au moins pour un texte non vide).
 *
 * @param int $mots Nombre de mots.
 */
function temps_lecture( int $mots ): int {
	return $mots > 0 ? max( 1, (int) ceil( $mots / MOTS_PAR_MINUTE ) ) : 0;
}

/**
 * Nombre de mots et temps de lecture d'un chapitre : calculés s'ils sont absents, recalculés
 * si le texte a changé depuis l'enregistrement précédent.
 *
 * @param \WP_Post      $chapitre Chapitre.
 * @param \WP_Post|null $avant    État précédent (null à la création).
 */
function calculer_lecture( \WP_Post $chapitre, ?\WP_Post $avant ): void {
	$texte_change = $avant instanceof \WP_Post && $avant->post_content !== $chapitre->post_content;
	if ( $texte_change || ! metadata_exists( 'post', $chapitre->ID, 'yume_nb_mots' ) ) {
		$mots = compter_mots( (string) $chapitre->post_content );
		update_post_meta( $chapitre->ID, 'yume_nb_mots', $mots );
		update_post_meta( $chapitre->ID, 'yume_temps_lecture', temps_lecture( $mots ) );
		return;
	}
	if ( ! metadata_exists( 'post', $chapitre->ID, 'yume_temps_lecture' ) ) {
		update_post_meta( $chapitre->ID, 'yume_temps_lecture', temps_lecture( (int) get_post_meta( $chapitre->ID, 'yume_nb_mots', true ) ) );
	}
}

/**
 * Recalcule le nombre de chapitres publiés d'un tome.
 *
 * @param int $tome_id ID du tome.
 * @return int Nombre de chapitres publiés.
 */
function recalculer_nb_chapitres( int $tome_id ): int {
	global $wpdb;
	if ( $tome_id <= 0 || CPT_TOME !== get_post_type( $tome_id ) ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$nb = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_tome_id'"
			. " WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_value = %s",
			CPT_CHAPITRE,
			(string) $tome_id
		)
	);
	update_post_meta( $tome_id, 'yume_nb_chapitres', $nb );
	return $nb;
}

/**
 * Recalcule la date de dernière sortie d'une œuvre (tome ou chapitre publié le plus récent).
 *
 * @param int $oeuvre_id ID de l'œuvre.
 * @return string Date GMT « Y-m-d H:i:s » ou chaîne vide.
 */
function recalculer_derniere_sortie( int $oeuvre_id ): string {
	global $wpdb;
	if ( $oeuvre_id <= 0 || CPT_OEUVRE !== get_post_type( $oeuvre_id ) ) {
		return '';
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_oeuvre_id'"
			. " WHERE p.post_type IN (%s, %s) AND p.post_status = 'publish' AND m.meta_value = %s ORDER BY p.post_date DESC LIMIT 10",
			CPT_TOME,
			CPT_CHAPITRE,
			(string) $oeuvre_id
		)
	);
	$max = 0;
	foreach ( (array) $ids as $id ) {
		$post = get_post( (int) $id );
		if ( $post ) {
			$max = max( $max, horodatage_gmt( $post ) );
		}
	}
	if ( ! $max ) {
		delete_post_meta( $oeuvre_id, 'yume_derniere_sortie' );
		return '';
	}
	$date = gmdate( 'Y-m-d H:i:s', $max );
	update_post_meta( $oeuvre_id, 'yume_derniere_sortie', $date );
	return $date;
}

/**
 * Recopie l'œuvre du tome sur un chapitre (ou la retire si le chapitre n'a plus de tome).
 *
 * @param int $chapitre_id ID du chapitre.
 */
function synchroniser_oeuvre_chapitre( int $chapitre_id ): void {
	$tome_id   = (int) get_post_meta( $chapitre_id, 'yume_tome_id', true );
	$oeuvre_id = $tome_id && CPT_TOME === get_post_type( $tome_id ) ? (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ) : 0;
	$actuel    = metadata_exists( 'post', $chapitre_id, 'yume_oeuvre_id' ) ? (int) get_post_meta( $chapitre_id, 'yume_oeuvre_id', true ) : null;
	if ( $oeuvre_id ) {
		if ( $actuel !== $oeuvre_id ) {
			update_post_meta( $chapitre_id, 'yume_oeuvre_id', $oeuvre_id );
		}
	} elseif ( null !== $actuel ) {
		delete_post_meta( $chapitre_id, 'yume_oeuvre_id' );
	}
}

/**
 * Après enregistrement d'une œuvre, d'un tome ou d'un chapitre (métadonnées écrites) :
 * met à jour les caches concernés.
 *
 * @param int           $post_id ID.
 * @param \WP_Post      $post    Contenu.
 * @param bool          $update  Mise à jour.
 * @param \WP_Post|null $avant   État précédent.
 */
function apres_enregistrement_caches( $post_id, $post, $update, $avant ): void {
	if ( ! $post instanceof \WP_Post || in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
		return;
	}
	if ( CPT_CHAPITRE === $post->post_type ) {
		synchroniser_oeuvre_chapitre( (int) $post->ID );
		calculer_lecture( $post, $avant instanceof \WP_Post ? $avant : null );
		recalculer_nb_chapitres( (int) get_post_meta( $post->ID, 'yume_tome_id', true ) );
		recalculer_derniere_sortie( (int) get_post_meta( $post->ID, 'yume_oeuvre_id', true ) );
	} elseif ( CPT_TOME === $post->post_type ) {
		recalculer_nb_chapitres( (int) $post->ID );
		recalculer_derniere_sortie( (int) get_post_meta( $post->ID, 'yume_oeuvre_id', true ) );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement_caches', 10, 4 );

/**
 * Avant la mise à jour d'une métadonnée de rattachement : mémorise l'ancienne valeur pour
 * recalculer aussi les caches de l'ancien parent.
 *
 * @param int    $meta_id   ID de la métadonnée.
 * @param int    $object_id ID du contenu.
 * @param string $meta_key  Clé.
 */
function avant_maj_rattachement( $meta_id, $object_id, $meta_key ): void {
	if ( ! in_array( $meta_key, array( 'yume_tome_id', 'yume_oeuvre_id' ), true ) ) {
		return;
	}
	$type = get_post_type( (int) $object_id );
	if ( ( CPT_CHAPITRE === $type && 'yume_tome_id' === $meta_key ) || ( CPT_TOME === $type && 'yume_oeuvre_id' === $meta_key ) ) {
		etat_set( 'ancien_parent_' . (int) $object_id . '_' . $meta_key, (int) get_post_meta( (int) $object_id, $meta_key, true ) );
	}
}
add_action( 'update_post_meta', __NAMESPACE__ . '\\avant_maj_rattachement', 10, 3 );
add_action( 'delete_post_meta', __NAMESPACE__ . '\\avant_suppression_rattachement', 10, 3 );

/**
 * Avant la suppression d'une métadonnée de rattachement (delete_post_meta reçoit une liste d'IDs).
 *
 * @param int[]  $meta_ids  IDs des métadonnées.
 * @param int    $object_id ID du contenu.
 * @param string $meta_key  Clé.
 */
function avant_suppression_rattachement( $meta_ids, $object_id, $meta_key ): void {
	avant_maj_rattachement( 0, $object_id, $meta_key );
}

/**
 * Après l'ajout, la modification ou la suppression d'un rattachement (chapitre → tome,
 * tome → œuvre) : dénormalisation et caches de l'ancien et du nouveau parent.
 *
 * @param int|int[] $meta_id   ID(s) de la métadonnée.
 * @param int       $object_id ID du contenu.
 * @param string    $meta_key  Clé.
 */
function apres_maj_rattachement( $meta_id, $object_id, $meta_key ): void {
	$object_id = (int) $object_id;
	$type      = get_post_type( $object_id );
	if ( CPT_CHAPITRE === $type && 'yume_tome_id' === $meta_key ) {
		$ancien = (int) etat_get( 'ancien_parent_' . $object_id . '_' . $meta_key, 0 );
		etat_set( 'ancien_parent_' . $object_id . '_' . $meta_key, 0 );
		synchroniser_oeuvre_chapitre( $object_id );
		foreach ( array_unique( array( $ancien, (int) get_post_meta( $object_id, 'yume_tome_id', true ) ) ) as $tome_id ) {
			if ( $tome_id ) {
				recalculer_nb_chapitres( $tome_id );
				recalculer_derniere_sortie( (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ) );
			}
		}
		return;
	}
	if ( CPT_TOME === $type && 'yume_oeuvre_id' === $meta_key ) {
		$ancien = (int) etat_get( 'ancien_parent_' . $object_id . '_' . $meta_key, 0 );
		etat_set( 'ancien_parent_' . $object_id . '_' . $meta_key, 0 );
		foreach ( ids_par_meta( CPT_CHAPITRE, 'yume_tome_id', $object_id, array_merge( statuts_actifs(), array( 'trash' ) ) ) as $chapitre_id ) {
			synchroniser_oeuvre_chapitre( $chapitre_id );
		}
		foreach ( array_unique( array( $ancien, (int) get_post_meta( $object_id, 'yume_oeuvre_id', true ) ) ) as $oeuvre_id ) {
			if ( $oeuvre_id ) {
				recalculer_derniere_sortie( $oeuvre_id );
			}
		}
	}
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\apres_maj_rattachement', 20, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\apres_maj_rattachement', 20, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\apres_maj_rattachement', 20, 3 );

/**
 * Avant la suppression définitive d'un tome ou d'un chapitre : mémorise ses parents.
 *
 * @param int $post_id ID.
 */
function avant_suppression_contenu( $post_id ): void {
	$post_id = (int) $post_id;
	$type    = get_post_type( $post_id );
	if ( CPT_CHAPITRE === $type || CPT_TOME === $type ) {
		etat_set(
			'parents_supprime_' . $post_id,
			array(
				'type'   => $type,
				'tome'   => CPT_CHAPITRE === $type ? (int) get_post_meta( $post_id, 'yume_tome_id', true ) : 0,
				'oeuvre' => (int) get_post_meta( $post_id, 'yume_oeuvre_id', true ),
			)
		);
	}
}
add_action( 'before_delete_post', __NAMESPACE__ . '\\avant_suppression_contenu' );

/**
 * Après la suppression définitive : recalcul des caches des parents.
 *
 * @param int $post_id ID.
 */
function apres_suppression_contenu( $post_id ): void {
	$parents = etat_get( 'parents_supprime_' . (int) $post_id );
	if ( ! is_array( $parents ) ) {
		return;
	}
	etat_set( 'parents_supprime_' . (int) $post_id, null );
	if ( $parents['tome'] ) {
		recalculer_nb_chapitres( (int) $parents['tome'] );
	}
	if ( $parents['oeuvre'] ) {
		recalculer_derniere_sortie( (int) $parents['oeuvre'] );
	}
}
add_action( 'deleted_post', __NAMESPACE__ . '\\apres_suppression_contenu' );
