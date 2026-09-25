<?php
/**
 * Termes : synchronisation de la taxonomie yume_oeuvre_liee avec les œuvres (création,
 * renommage, suppression) et liens de termes.
 *
 * Chaque œuvre possède un terme yume_oeuvre_liee de même slug et de même nom ; le lien est
 * gardé dans la méta d'œuvre _yume_terme_lie et la méta de terme yume_oeuvre_id.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Slug voulu pour le terme d'une œuvre (slug de l'œuvre, ou slug provisoire d'un brouillon).
 *
 * @param \WP_Post $oeuvre Œuvre.
 */
function slug_terme_oeuvre( \WP_Post $oeuvre ): string {
	if ( '' !== (string) $oeuvre->post_name ) {
		return (string) $oeuvre->post_name;
	}
	$base = sanitize_title( $oeuvre->post_title );
	if ( '' === $base ) {
		return '';
	}
	// Slug qu'aura l'œuvre une fois publiée.
	return wp_unique_post_slug( $base, (int) $oeuvre->ID, 'publish', CPT_OEUVRE, 0 );
}

/**
 * Le terme peut-il être rattaché à cette œuvre (libre ou déjà à elle) ?
 *
 * @param \WP_Term $terme     Terme.
 * @param int      $oeuvre_id Œuvre.
 */
function terme_adoptable( \WP_Term $terme, int $oeuvre_id ): bool {
	$lie = (int) get_term_meta( $terme->term_id, 'yume_oeuvre_id', true );
	if ( ! $lie || $lie === $oeuvre_id ) {
		return true;
	}
	$autre = get_post( $lie );
	return ! $autre || CPT_OEUVRE !== $autre->post_type || 'trash' === $autre->post_status;
}

/**
 * Crée ou met à jour le terme yume_oeuvre_liee d'une œuvre.
 *
 * @param int $oeuvre_id ID de l'œuvre.
 * @return int ID du terme (0 si l'œuvre n'en a pas besoin ou en cas d'échec).
 */
function synchroniser_terme( int $oeuvre_id ): int {
	$oeuvre = get_post( $oeuvre_id );
	if ( ! $oeuvre || CPT_OEUVRE !== $oeuvre->post_type || ! taxonomy_exists( TAX_OEUVRE_LIEE ) ) {
		return 0;
	}
	if ( in_array( $oeuvre->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
		return 0;
	}
	$slug = slug_terme_oeuvre( $oeuvre );
	if ( '' === $slug ) {
		return 0;
	}
	$nom = '' !== trim( $oeuvre->post_title ) ? wp_strip_all_tags( $oeuvre->post_title ) : $slug;

	// 1. Terme déjà lié.
	$terme    = null;
	$terme_id = (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true );
	if ( $terme_id ) {
		$t = get_term( $terme_id, TAX_OEUVRE_LIEE );
		if ( $t instanceof \WP_Term ) {
			$terme = $t;
		}
	}
	// 2. Terme portant la méta de l'œuvre (lien perdu côté œuvre).
	if ( ! $terme ) {
		$trouves = get_terms(
			array(
				'taxonomy'   => TAX_OEUVRE_LIEE,
				'hide_empty' => false,
				'number'     => 1,
				'meta_key'   => 'yume_oeuvre_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $oeuvre_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( is_array( $trouves ) && $trouves && $trouves[0] instanceof \WP_Term ) {
			$terme = $trouves[0];
		}
	}
	// 3. Terme de même slug, libre (créé à la main ou par une migration) : adopté.
	if ( ! $terme ) {
		$t = get_term_by( 'slug', $slug, TAX_OEUVRE_LIEE );
		if ( $t instanceof \WP_Term && terme_adoptable( $t, $oeuvre_id ) ) {
			$terme = $t;
		}
	}

	if ( $terme ) {
		$modifs = array();
		if ( $terme->name !== $nom ) {
			$modifs['name'] = $nom;
		}
		if ( $terme->slug !== $slug ) {
			$occupe = get_term_by( 'slug', $slug, TAX_OEUVRE_LIEE );
			if ( ! $occupe instanceof \WP_Term || (int) $occupe->term_id === (int) $terme->term_id ) {
				$modifs['slug'] = $slug;
			}
		}
		if ( $modifs ) {
			$res = wp_update_term( (int) $terme->term_id, TAX_OEUVRE_LIEE, $modifs );
			if ( is_wp_error( $res ) && isset( $modifs['slug'] ) ) {
				unset( $modifs['slug'] );
				if ( $modifs ) {
					wp_update_term( (int) $terme->term_id, TAX_OEUVRE_LIEE, $modifs );
				}
			}
		}
		$terme_id = (int) $terme->term_id;
	} else {
		$res = wp_insert_term( $nom, TAX_OEUVRE_LIEE, array( 'slug' => $slug ) );
		if ( is_wp_error( $res ) ) {
			// Nom ou slug déjà pris par un autre terme : slug distinct garanti par l'ID de l'œuvre.
			$res = wp_insert_term(
				$nom . ' (' . $oeuvre_id . ')',
				TAX_OEUVRE_LIEE,
				array( 'slug' => $slug . '-' . $oeuvre_id )
			);
		}
		if ( is_wp_error( $res ) ) {
			return 0;
		}
		$terme_id = (int) $res['term_id'];
	}

	update_term_meta( $terme_id, 'yume_oeuvre_id', $oeuvre_id );
	if ( (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true ) !== $terme_id ) {
		update_post_meta( $oeuvre_id, '_yume_terme_lie', $terme_id );
	}
	return $terme_id;
}

/**
 * Supprime le terme lié à une œuvre.
 *
 * @param int $oeuvre_id ID de l'œuvre.
 */
function supprimer_terme( int $oeuvre_id ): void {
	if ( ! taxonomy_exists( TAX_OEUVRE_LIEE ) ) {
		return;
	}
	$ids      = array();
	$terme_id = (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true );
	if ( $terme_id ) {
		$ids[] = $terme_id;
	}
	$trouves = get_terms(
		array(
			'taxonomy'   => TAX_OEUVRE_LIEE,
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => 'yume_oeuvre_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => (string) $oeuvre_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( is_array( $trouves ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $trouves ) );
	}
	foreach ( array_unique( $ids ) as $id ) {
		$terme = get_term( $id, TAX_OEUVRE_LIEE );
		if ( $terme instanceof \WP_Term && (int) get_term_meta( $id, 'yume_oeuvre_id', true ) === $oeuvre_id ) {
			wp_delete_term( $id, TAX_OEUVRE_LIEE );
		}
	}
}

/**
 * wp_after_insert_post : synchronise le terme d'une œuvre créée, renommée ou restaurée.
 *
 * @param int      $post_id ID.
 * @param \WP_Post $post    Contenu.
 */
function apres_enregistrement_oeuvre( $post_id, $post ): void {
	if ( $post instanceof \WP_Post && CPT_OEUVRE === $post->post_type ) {
		synchroniser_terme( (int) $post_id );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement_oeuvre', 10, 2 );

/**
 * Suppression définitive d'une œuvre : son terme disparaît.
 *
 * @param int $post_id ID.
 */
function avant_suppression_oeuvre( $post_id ): void {
	if ( CPT_OEUVRE === get_post_type( (int) $post_id ) ) {
		supprimer_terme( (int) $post_id );
	}
}
add_action( 'before_delete_post', __NAMESPACE__ . '\\avant_suppression_oeuvre' );

/**
 * Synchronise les termes de toutes les œuvres existantes (installation, mise à jour).
 */
function synchroniser_tous_les_termes(): void {
	$ids = get_posts(
		array(
			'post_type'        => CPT_OEUVRE,
			'post_status'      => statuts_actifs(),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);
	foreach ( $ids as $id ) {
		synchroniser_terme( (int) $id );
	}
}

/**
 * Liens de termes : les types, statuts et genres mènent à la bibliothèque filtrée
 * (/bibliotheque/?type=…, ?statut=…, ?genre=…) ; une œuvre liée mène à la fiche de l'œuvre.
 *
 * @param string   $lien     Lien.
 * @param \WP_Term $terme    Terme.
 * @param string   $taxonomy Taxonomie.
 */
function filtre_term_link( $lien, $terme, $taxonomy ) {
	$parametres = array(
		TAX_TYPE   => 'type',
		TAX_STATUT => 'statut',
		TAX_GENRE  => 'genre',
	);
	if ( isset( $parametres[ $taxonomy ] ) && $terme instanceof \WP_Term ) {
		return add_query_arg( $parametres[ $taxonomy ], rawurlencode( $terme->slug ), yume_url_page( 'bibliotheque' ) );
	}
	if ( TAX_OEUVRE_LIEE === $taxonomy && $terme instanceof \WP_Term ) {
		$oeuvre_id = (int) get_term_meta( $terme->term_id, 'yume_oeuvre_id', true );
		if ( $oeuvre_id && CPT_OEUVRE === get_post_type( $oeuvre_id ) ) {
			$url = get_permalink( $oeuvre_id );
			if ( $url ) {
				return $url;
			}
		}
	}
	return $lien;
}
add_filter( 'term_link', __NAMESPACE__ . '\\filtre_term_link', 10, 3 );
