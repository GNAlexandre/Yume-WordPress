<?php
/**
 * Empreinte des contenus touchés par la migration : pages de l'ancien site, articles,
 * catégories, options de lecture et de Yume, nombre de contenus par type. Prise au début de
 * l'exécution et recalculée après l'annulation, elle vérifie que la base est revenue à son
 * état d'origine sur tout ce que la migration modifie.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Empreinte (clé => hash) et comparaison.
 */
final class Migration_Empreinte {

	/** Options sauvegardées, modifiées puis restaurées par la migration. */
	public const OPTIONS = array(
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'default_category',
		'yume_pages',
		'yume_reglages',
		'yume_redirections',
		'yume_redirections_ids',
		'users_can_register',
		'default_role',
		'posts_per_page',
		'comment_registration',
	);

	/** Types dont le nombre de contenus est contrôlé. */
	public const TYPES = array( 'page', 'post', 'attachment', 'yume_oeuvre', 'yume_tome', 'yume_chapitre' );

	/**
	 * Calcule l'empreinte des contenus concernés par un plan.
	 *
	 * @param array $plan Plan de migration.
	 * @return array<string,string> Clé => hash.
	 */
	public static function calculer( array $plan ): array {
		global $wpdb;
		$empreinte = array();
		$pages     = array();
		foreach ( array( 'conserver', 'remplacer', 'ignorer' ) as $liste ) {
			foreach ( (array) ( $plan['pages'][ $liste ] ?? array() ) as $page ) {
				$pages[] = (int) $page['id'];
			}
		}
		foreach ( $pages as $id ) {
			$post                       = get_post( $id );
			$empreinte[ 'page:' . $id ] = $post instanceof \WP_Post ? self::hash(
				array(
					$post->post_status,
					$post->post_name,
					$post->post_title,
					md5( $post->post_content ),
					$post->post_excerpt,
					$post->post_date,
					$post->post_modified,
					(int) $post->post_parent,
					(int) $post->menu_order,
				)
			) : 'absent';
		}
		foreach ( (array) ( $plan['articles'] ?? array() ) as $article ) {
			$id   = (int) $article['source_id'];
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				$empreinte[ 'article:' . $id ] = 'absent';
				continue;
			}
			$cats = wp_get_object_terms( $id, 'category', array( 'fields' => 'ids' ) );
			$lies = taxonomy_exists( 'yume_oeuvre_liee' ) ? wp_get_object_terms( $id, 'yume_oeuvre_liee', array( 'fields' => 'ids' ) ) : array();
			$cats = is_array( $cats ) ? array_map( 'intval', $cats ) : array();
			$lies = is_array( $lies ) ? array_map( 'intval', $lies ) : array();
			sort( $cats );
			sort( $lies );
			$empreinte[ 'article:' . $id ] = self::hash( array( $post->post_status, $post->post_modified, md5( $post->post_content ), $cats, $lies ) );
		}
		foreach ( (array) ( $plan['categories'] ?? array() ) as $cat ) {
			if ( empty( $cat['id'] ) ) {
				continue;
			}
			$terme                                        = get_term( (int) $cat['id'], 'category' );
			$empreinte[ 'categorie:' . (int) $cat['id'] ] = $terme instanceof \WP_Term
				? self::hash( array( $terme->name, $terme->slug, $terme->description, (int) $terme->parent ) )
				: 'absent';
		}
		$slugs = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'id=>slug',
			)
		);
		$slugs = is_array( $slugs ) ? $slugs : array();
		ksort( $slugs );
		$empreinte['categories'] = self::hash( $slugs );
		foreach ( self::OPTIONS as $option ) {
			// Valeur lue en base (et non dans le cache, où update_option() garde la valeur
			// assainie, 12 au lieu de « 12 ») ; les scalaires sont comparés en texte.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$brut                             = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
			$valeur                           = null === $brut ? '__absente__' : maybe_unserialize( $brut );
			$empreinte[ 'option:' . $option ] = self::hash( is_scalar( $valeur ) ? (string) $valeur : $valeur );
		}
		foreach ( self::TYPES as $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$lignes                         = $wpdb->get_results(
				$wpdb->prepare( "SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status ORDER BY post_status", $type ),
				ARRAY_A
			);
			$empreinte[ 'nombre:' . $type ] = self::hash( $lignes );
		}
		return $empreinte;
	}

	/**
	 * Clés de l'empreinte d'origine dont la valeur a changé.
	 *
	 * @param array $avant Empreinte d'origine.
	 * @param array $apres Empreinte actuelle.
	 * @return string[]
	 */
	public static function differences( array $avant, array $apres ): array {
		$diff = array();
		// Clés de l'empreinte d'origine seulement : une empreinte prise par une version antérieure
		// ne couvre pas les options ajoutées depuis (yume_redirections_ids…).
		foreach ( array_keys( $avant ) as $cle ) {
			if ( ( $avant[ $cle ] ?? null ) !== ( $apres[ $cle ] ?? null ) ) {
				$diff[] = (string) $cle;
			}
		}
		sort( $diff );
		return $diff;
	}

	/**
	 * Hash stable d'une valeur.
	 *
	 * @param mixed $valeur Valeur.
	 */
	private static function hash( $valeur ): string {
		return md5( (string) wp_json_encode( $valeur ) );
	}
}
