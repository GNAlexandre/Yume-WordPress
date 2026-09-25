<?php
/**
 * Chargement et normalisation de l'export de l'ancien site.
 *
 * Format d'un export (tableau PHP, identique aux fichiers JSON de tools/migrate/export/) :
 *
 *   site           : { domaine, domaines_alias[], exporte_le, … }
 *   pages[]        : { id, slug, status, title, link, date, modified, parent, menu_order,
 *                      featured_media, excerpt, content (balisage de blocs brut, ou null) }
 *   posts[]        : { id, slug, status, title, link, date, modified, author, categories[],
 *                      tags[], featured_media, excerpt, content (ou null) }
 *   categories[]   : { id, name, slug, description, parent, count, link }
 *   media[]        : { id, date, mime_type, source_url, title, alt_text, post, width, height, file }
 *   navigations[]  : { id, title, status, content }
 *   template_parts[] : { id, slug, title, area, content }
 *
 * Les dates sont locales (Europe/Paris) au format « Y-m-d\TH:i:s » ou « Y-m-d H:i:s ».
 * Le même tableau peut être construit depuis la base WordPress (get_posts…) par le module
 * d'exécution : normaliser() garantit alors les mêmes types.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture d'un export JSON et normalisation des types.
 */
final class Export_Loader {

	/** Fichiers d'un export et clé correspondante. */
	public const FICHIERS = array(
		'site'           => 'site.json',
		'pages'          => 'pages.json',
		'posts'          => 'posts.json',
		'categories'     => 'categories.json',
		'media'          => 'media.json',
		'navigations'    => 'navigations.json',
		'template_parts' => 'template-parts.json',
	);

	/**
	 * Charge un export depuis un dossier (pages.json et posts.json obligatoires).
	 *
	 * @param string $dossier Dossier contenant les fichiers JSON.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Dossier ou fichier obligatoire illisible, JSON invalide.
	 */
	public static function depuis_dossier( string $dossier ): array {
		$dossier = rtrim( $dossier, '/\\' );
		if ( ! is_dir( $dossier ) ) {
			throw new \RuntimeException( sprintf( 'Dossier d’export introuvable : %s', $dossier ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message pour la ligne de commande (classe sans WordPress).
		}
		$export = array();
		foreach ( self::FICHIERS as $cle => $fichier ) {
			$chemin = $dossier . '/' . $fichier;
			if ( ! is_readable( $chemin ) ) {
				if ( in_array( $cle, array( 'pages', 'posts' ), true ) ) {
					throw new \RuntimeException( sprintf( 'Fichier obligatoire manquant : %s', $chemin ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message pour la ligne de commande (classe sans WordPress).
				}
				$export[ $cle ] = array();
				continue;
			}
			$donnees = json_decode( (string) file_get_contents( $chemin ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
			if ( ! is_array( $donnees ) ) {
				throw new \RuntimeException( sprintf( 'JSON invalide : %s (%s)', $chemin, json_last_error_msg() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message pour la ligne de commande (classe sans WordPress).
			}
			$export[ $cle ] = $donnees;
		}
		return self::normaliser( $export );
	}

	/**
	 * Normalise les types d'un export (valeurs par défaut, entiers, chaînes).
	 *
	 * @param array $export Export brut.
	 * @return array<string,mixed>
	 */
	public static function normaliser( array $export ): array {
		$site = is_array( $export['site'] ?? null ) ? $export['site'] : array();
		$site = array_merge(
			array(
				'domaine'        => 'yumenovel.fr',
				'domaines_alias' => array( 'yumenovel.wordpress.com' ),
				'exporte_le'     => null,
			),
			$site
		);

		$pages = array();
		foreach ( (array) ( $export['pages'] ?? array() ) as $page ) {
			if ( is_array( $page ) && ! empty( $page['id'] ) ) {
				$pages[] = self::normaliser_contenu( $page );
			}
		}
		$posts = array();
		foreach ( (array) ( $export['posts'] ?? array() ) as $post ) {
			if ( is_array( $post ) && ! empty( $post['id'] ) ) {
				$post               = self::normaliser_contenu( $post );
				$post['categories'] = array_values( array_map( 'intval', (array) ( $post['categories'] ?? array() ) ) );
				$post['tags']       = array_values( array_map( 'intval', (array) ( $post['tags'] ?? array() ) ) );
				$posts[]            = $post;
			}
		}
		$media = array();
		foreach ( (array) ( $export['media'] ?? array() ) as $m ) {
			if ( is_array( $m ) && ! empty( $m['id'] ) ) {
				$media[] = array(
					'id'         => (int) $m['id'],
					'date'       => isset( $m['date'] ) ? (string) $m['date'] : '',
					'mime_type'  => (string) ( $m['mime_type'] ?? '' ),
					'source_url' => (string) ( $m['source_url'] ?? '' ),
					'title'      => (string) ( $m['title'] ?? '' ),
					'alt_text'   => (string) ( $m['alt_text'] ?? '' ),
					'post'       => (int) ( $m['post'] ?? 0 ),
					'width'      => (int) ( $m['width'] ?? 0 ),
					'height'     => (int) ( $m['height'] ?? 0 ),
					'file'       => (string) ( $m['file'] ?? '' ),
				);
			}
		}
		$categories = array();
		foreach ( (array) ( $export['categories'] ?? array() ) as $c ) {
			if ( is_array( $c ) && ! empty( $c['id'] ) ) {
				$categories[] = array(
					'id'          => (int) $c['id'],
					'name'        => Html::decoder( (string) ( $c['name'] ?? '' ) ),
					'slug'        => (string) ( $c['slug'] ?? '' ),
					'description' => (string) ( $c['description'] ?? '' ),
					'parent'      => (int) ( $c['parent'] ?? 0 ),
					'count'       => (int) ( $c['count'] ?? 0 ),
					'link'        => (string) ( $c['link'] ?? '' ),
				);
			}
		}

		return array(
			'site'           => $site,
			'pages'          => $pages,
			'posts'          => $posts,
			'categories'     => $categories,
			'media'          => $media,
			'navigations'    => array_values( (array) ( $export['navigations'] ?? array() ) ),
			'template_parts' => array_values( (array) ( $export['template_parts'] ?? array() ) ),
		);
	}

	/**
	 * Champs communs d'une page ou d'un article.
	 *
	 * @param array $item Élément brut.
	 * @return array<string,mixed>
	 */
	private static function normaliser_contenu( array $item ): array {
		$titre = $item['title'] ?? '';
		if ( is_array( $titre ) ) {
			$titre = $titre['raw'] ?? $titre['rendered'] ?? '';
		}
		$contenu = $item['content'] ?? null;
		if ( is_array( $contenu ) ) {
			$contenu = $contenu['raw'] ?? $contenu['rendered'] ?? null;
		}
		$extrait = $item['excerpt'] ?? '';
		if ( is_array( $extrait ) ) {
			$extrait = $extrait['raw'] ?? $extrait['rendered'] ?? '';
		}
		return array_merge(
			$item,
			array(
				'id'             => (int) $item['id'],
				'slug'           => (string) ( $item['slug'] ?? '' ),
				'status'         => (string) ( $item['status'] ?? 'publish' ),
				'title'          => trim( Html::decoder( (string) $titre ) ),
				'link'           => (string) ( $item['link'] ?? '' ),
				'date'           => self::date( $item['date'] ?? '' ),
				'modified'       => self::date( $item['modified'] ?? '' ),
				'author'         => (int) ( $item['author'] ?? 0 ),
				'parent'         => (int) ( $item['parent'] ?? 0 ),
				'menu_order'     => (int) ( $item['menu_order'] ?? 0 ),
				'featured_media' => (int) ( $item['featured_media'] ?? 0 ),
				'excerpt'        => (string) $extrait,
				'content'        => null === $contenu ? null : (string) $contenu,
			)
		);
	}

	/**
	 * Date au format « Y-m-d H:i:s » (chaîne vide si absente ou invalide).
	 *
	 * @param mixed $valeur Date ISO ou MySQL.
	 */
	public static function date( $valeur ): string {
		$valeur = trim( (string) $valeur );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $valeur, $m ) ) {
			return $m[1] . ' ' . $m[2];
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valeur ) ) {
			return $valeur . ' 00:00:00';
		}
		return '';
	}
}
