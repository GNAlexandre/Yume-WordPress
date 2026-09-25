<?php
/**
 * Source « base de données » de la migration : lit l'ancien contenu directement dans la base
 * WordPress du site (pages, articles, catégories, médias, navigations, parties de modèle) et
 * produit exactement la même entrée qu'Export_Loader (format des fichiers de
 * tools/migrate/export/), pour que Migration_Planner calcule le même plan en local (export
 * JSON) et en production (base du site, où il n'existe aucun export).
 *
 * Correspondance avec l'export du connecteur WordPress.com (API REST) :
 *
 * - pages, posts : contenu brut (post_content), titre brut, dates locales, statuts publish,
 *   future, draft, pending et private, lien = permalien ; l'extrait d'un article est l'extrait
 *   rendu comme le fait l'API REST (extrait manuel ou automatique, filtres the_excerpt) ;
 *   catégories et étiquettes dans l'ordre de get_the_terms() (celui de l'API) ;
 * - media : toutes les pièces jointes, `file` = _wp_attached_file, `source_url` =
 *   wp_get_attachment_url() (ou `url_medias` + fichier) ;
 * - navigations : contenus wp_navigation (du plus récent au plus ancien, ordre de l'API) ;
 * - template_parts : parties de modèle enregistrées en base (personnalisées), identifiant
 *   « thème//slug ». Les parties fournies par les fichiers d'un thème ne sont pas lues : seule
 *   la partie « header » personnalisée (bannière du site) sert au plan.
 *
 * Les contenus créés par la migration elle-même (méta _yume_migration_cle) sont exclus.
 * Champs qui diffèrent entre plan(base) et plan(export) : genere_le, source.exporte_le,
 * source.site_id ; source.domaine, source.domaines_alias, les URL des liens et des médias
 * suivent les options `domaine`, `domaines_alias`, `home` et `url_medias`.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture de l'ancien site dans la base.
 */
final class Site_Source {

	/** Statuts lus (identiques à ceux de l'export). */
	public const STATUTS = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/** Méta posée sur tout contenu créé par la migration. */
	public const META_CLE = '_yume_migration_cle';

	/**
	 * Domaines de l'ancien site reconnus par défaut comme « internes ».
	 */
	public const DOMAINES = array( 'yumenovel.fr', 'yumenovel.wordpress.com' );

	/**
	 * Construit l'export depuis la base.
	 *
	 * @param array $options 'domaine' (défaut : hôte de home_url()),
	 *                       'domaines_alias' (défaut : yumenovel.fr, yumenovel.wordpress.com
	 *                       et l'hôte de site_url(), filtre yume_migration_domaines),
	 *                       'home' (base des liens à la place de home_url(), ex.
	 *                       « https://yumenovel.fr »), 'url_medias' (base des URL des
	 *                       médias, ex. « https://yumenovel.wordpress.com/wp-content/uploads/ »).
	 * @return array<string,mixed> Export normalisé (Export_Loader::normaliser()).
	 */
	public static function export( array $options = array() ): array {
		$hote    = self::hote( (string) home_url() );
		$domaine = isset( $options['domaine'] ) ? (string) $options['domaine'] : $hote;
		$alias   = $options['domaines_alias'] ?? null;
		if ( ! is_array( $alias ) ) {
			$alias = array_merge( self::DOMAINES, array( $hote, self::hote( (string) site_url() ) ) );
			/**
			 * Domaines considérés comme l'ancien site (liens internes) en plus du domaine principal.
			 *
			 * @param string[] $alias   Domaines.
			 * @param string   $domaine Domaine principal.
			 */
			$alias = (array) apply_filters( 'yume_migration_domaines', $alias, $domaine );
		}
		$alias = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $d ) => strtolower( trim( (string) $d ) ), $alias ),
					static fn( $d ) => '' !== $d && strtolower( $domaine ) !== $d
				)
			)
		);

		$liens = array(
			'home'       => isset( $options['home'] ) ? untrailingslashit( (string) $options['home'] ) : '',
			'url_medias' => isset( $options['url_medias'] ) ? trailingslashit( (string) $options['url_medias'] ) : '',
		);

		$pages      = self::contenus( 'page', $liens );
		$posts      = self::contenus( 'post', $liens );
		$categories = self::categories( $liens );
		$medias     = self::medias( $liens );
		$navs       = self::navigations();
		$parties    = self::parties_de_modele();

		$jetpack = get_option( 'jetpack_options' );
		$site_id = is_array( $jetpack ) && ! empty( $jetpack['id'] ) ? (int) $jetpack['id'] : null;

		return Export_Loader::normaliser(
			array(
				'site'           => array(
					'site_id'        => $site_id,
					'domaine'        => $domaine,
					'domaines_alias' => $alias,
					'exporte_le'     => current_time( 'mysql' ),
					'source'         => 'Base WordPress du site (lecture directe, Site_Source)',
					'comptes'        => array(
						'pages'          => count( $pages ),
						'posts'          => count( $posts ),
						'categories'     => count( $categories ),
						'media'          => count( $medias ),
						'navigations'    => count( $navs ),
						'template_parts' => count( $parties ),
					),
				),
				'pages'          => $pages,
				'posts'          => $posts,
				'categories'     => $categories,
				'media'          => $medias,
				'navigations'    => $navs,
				'template_parts' => $parties,
			)
		);
	}

	/**
	 * Hôte d'une URL, sans « www. », en minuscules.
	 *
	 * @param string $url URL.
	 */
	private static function hote( string $url ): string {
		$hote = (string) wp_parse_url( $url, PHP_URL_HOST );
		return strtolower( (string) preg_replace( '/^www\./i', '', $hote ) );
	}

	/**
	 * Lien public d'un contenu, éventuellement rebasé sur `home`.
	 *
	 * @param string $url   URL calculée par WordPress.
	 * @param array  $liens Options de liens.
	 */
	private static function lien( string $url, array $liens ): string {
		if ( '' === $liens['home'] ) {
			return $url;
		}
		$base = untrailingslashit( (string) home_url() );
		if ( '' !== $base && str_starts_with( $url, $base ) ) {
			return $liens['home'] . substr( $url, strlen( $base ) );
		}
		return $url;
	}

	/**
	 * IDs des contenus d'un type, hors contenus créés par la migration, triés par ID.
	 *
	 * @param string   $type    Type de contenu.
	 * @param string[] $statuts Statuts.
	 * @param string   $ordre   Clause ORDER BY (valeur fixe interne).
	 * @return int[]
	 */
	private static function ids( string $type, array $statuts, string $ordre = 'p.ID ASC' ): array {
		global $wpdb;
		$ordres = array(
			'p.ID ASC'                    => 'p.ID ASC',
			'p.post_date DESC, p.ID DESC' => 'p.post_date DESC, p.ID DESC',
		);
		$ordre  = $ordres[ $ordre ] ?? 'p.ID ASC';
		$places = implode( ', ', array_fill( 0, count( $statuts ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $places et $ordre sont construits ici (liste fixe).
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ( {$places} ) AND m.meta_id IS NULL
			ORDER BY {$ordre}",
			array_merge( array( self::META_CLE, $type ), $statuts )
		);
		// phpcs:enable
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Pages ou articles au format de l'export.
	 *
	 * @param string $type  'page' ou 'post'.
	 * @param array  $liens Options de liens.
	 * @return array<int,array<string,mixed>>
	 */
	private static function contenus( string $type, array $liens ): array {
		$ids = self::ids( $type, self::STATUTS );
		if ( ! $ids ) {
			return array();
		}
		_prime_post_caches( $ids, true, true );
		$sortie         = array();
		$post_precedent = $GLOBALS['post'] ?? null;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$element = array(
				'id'             => (int) $post->ID,
				'slug'           => (string) $post->post_name,
				'status'         => (string) $post->post_status,
				'title'          => (string) $post->post_title,
				'link'           => self::lien( (string) get_permalink( $post ), $liens ),
				'date'           => (string) $post->post_date,
				'modified'       => (string) $post->post_modified,
				'author'         => (int) $post->post_author,
				'featured_media' => (int) get_post_thumbnail_id( $post ),
				'content'        => (string) $post->post_content,
			);
			if ( 'page' === $type ) {
				$element['parent']     = (int) $post->post_parent;
				$element['menu_order'] = (int) $post->menu_order;
				$element['template']   = (string) get_page_template_slug( $post );
				$element['excerpt']    = (string) $post->post_excerpt;
			} else {
				$element['categories'] = self::termes( $post, 'category' );
				$element['tags']       = self::termes( $post, 'post_tag' );
				$element['excerpt']    = self::extrait( $post );
			}
			$sortie[] = $element;
		}
		$GLOBALS['post'] = $post_precedent; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $post_precedent instanceof \WP_Post ) {
			setup_postdata( $post_precedent );
		}
		return $sortie;
	}

	/**
	 * IDs des termes d'un contenu, dans l'ordre de get_the_terms() (celui de l'API REST).
	 *
	 * @param \WP_Post $post     Contenu.
	 * @param string   $taxonomy Taxonomie.
	 * @return int[]
	 */
	private static function termes( \WP_Post $post, string $taxonomy ): array {
		$termes = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $termes ) ) {
			return array();
		}
		return array_values( array_map( static fn( $t ) => (int) $t->term_id, $termes ) );
	}

	/**
	 * Extrait rendu d'un article, calculé comme le fait l'API REST (champ excerpt.rendered).
	 *
	 * @param \WP_Post $post Article.
	 */
	private static function extrait( \WP_Post $post ): string {
		if ( post_password_required( $post ) ) {
			return '';
		}
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		// Filtres du cœur, appliqués comme l'API REST (champ excerpt.rendered).
		$extrait = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return (string) apply_filters( 'the_excerpt', $extrait ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Catégories au format de l'export.
	 *
	 * @param array $liens Options de liens.
	 * @return array<int,array<string,mixed>>
	 */
	private static function categories( array $liens ): array {
		$termes = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $termes ) ) {
			return array();
		}
		$sortie = array();
		foreach ( $termes as $terme ) {
			$lien     = get_term_link( $terme );
			$sortie[] = array(
				'id'          => (int) $terme->term_id,
				'name'        => (string) $terme->name,
				'slug'        => (string) $terme->slug,
				'description' => (string) $terme->description,
				'parent'      => (int) $terme->parent,
				'count'       => (int) $terme->count,
				'link'        => is_string( $lien ) ? self::lien( $lien, $liens ) : '',
			);
		}
		return $sortie;
	}

	/**
	 * Médias (pièces jointes) au format de l'export.
	 *
	 * @param array $liens Options de liens.
	 * @return array<int,array<string,mixed>>
	 */
	private static function medias( array $liens ): array {
		$ids = self::ids( 'attachment', array( 'inherit', 'private', 'publish' ) );
		if ( ! $ids ) {
			return array();
		}
		_prime_post_caches( $ids, false, true );
		$sortie = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$fichier  = (string) get_post_meta( $id, '_wp_attached_file', true );
			$meta     = wp_get_attachment_metadata( $id );
			$meta     = is_array( $meta ) ? $meta : array();
			$url      = '' !== $liens['url_medias'] && '' !== $fichier ? $liens['url_medias'] . ltrim( $fichier, '/' ) : (string) wp_get_attachment_url( $id );
			$sortie[] = array(
				'id'         => (int) $id,
				'date'       => (string) $post->post_date,
				'mime_type'  => (string) $post->post_mime_type,
				'source_url' => $url,
				'title'      => (string) $post->post_title,
				'alt_text'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'post'       => (int) $post->post_parent,
				'width'      => (int) ( $meta['width'] ?? 0 ),
				'height'     => (int) ( $meta['height'] ?? 0 ),
				'file'       => $fichier,
			);
		}
		return $sortie;
	}

	/**
	 * Navigations (wp_navigation), de la plus récente à la plus ancienne.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function navigations(): array {
		$sortie = array();
		foreach ( self::ids( 'wp_navigation', array( 'publish', 'draft', 'private' ), 'p.post_date DESC, p.ID DESC' ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$sortie[] = array(
					'id'      => (int) $post->ID,
					'title'   => (string) $post->post_title,
					'status'  => (string) $post->post_status,
					'content' => (string) $post->post_content,
				);
			}
		}
		return $sortie;
	}

	/**
	 * Parties de modèle enregistrées en base (wp_template_part).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function parties_de_modele(): array {
		$sortie = array();
		foreach ( self::ids( 'wp_template_part', array( 'publish' ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$themes   = taxonomy_exists( 'wp_theme' ) ? wp_get_post_terms( $id, 'wp_theme', array( 'fields' => 'names' ) ) : array();
			$zones    = taxonomy_exists( 'wp_template_part_area' ) ? wp_get_post_terms( $id, 'wp_template_part_area', array( 'fields' => 'names' ) ) : array();
			$theme    = is_array( $themes ) && $themes ? (string) $themes[0] : '';
			$sortie[] = array(
				'id'      => ( '' !== $theme ? $theme . '//' : '' ) . $post->post_name,
				'slug'    => (string) $post->post_name,
				'title'   => (string) $post->post_title,
				'area'    => is_array( $zones ) && $zones ? (string) $zones[0] : 'uncategorized',
				'content' => (string) $post->post_content,
			);
		}
		return $sortie;
	}
}
