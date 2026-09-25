<?php
/**
 * Peuplement d'une base WordPress LOCALE avec l'ancien site yumenovel.fr, depuis l'export
 * JSON (tools/migrate/export/ ou tools/migrate/fixtures/).
 *
 * Reproduit ce que la migration trouvera en production, dans la base du site :
 * - pages et articles avec leur contenu brut, leurs titres, slugs, statuts, dates (création et
 *   modification), parents, ordres et images mises en avant d'origine, aux MÊMES ID
 *   (import_id) ;
 * - catégories aux mêmes ID de terme (insertion directe), catégorie par défaut « Non classé » ;
 * - pièces jointes factices aux mêmes ID et au même _wp_attached_file, avec une image de
 *   substitution générée (GD) aux proportions de l'original ;
 * - navigations (wp_navigation) et parties de modèle personnalisées (wp_template_part,
 *   rattachées au thème d'origine) ;
 * - réglages : fuseau Europe/Paris, permaliens /%year%/%monthnum%/%day%/%postname%/,
 *   page d'accueil = derniers articles.
 *
 * L'extrait d'un article est celui de l'export (extrait rendu par WordPress.com), posé comme
 * extrait manuel : un extrait automatique recalculé localement pourrait différer.
 *
 * DESTRUCTIF : la purge supprime tous les contenus de la base courante. Refuse de tourner
 * hors d'un environnement local (YUME_DEV ou type d'environnement local/development) et sur
 * un domaine de production.
 *
 * Ce fichier n'est pas livré avec l'extension (outil de développement). Utilisé par
 * tools/migrate/seed-local.php et par les tests du module migration.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Export_Loader;
use Yume\Core\Migration\Html;

/**
 * Peuplement local de l'ancien site.
 */
final class Yume_Seed_Local {

	/** Types de contenu supprimés par la purge. */
	public const TYPES_PURGES = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_navigation',
		'wp_template_part',
		'wp_block',
		'oembed_cache',
		'customize_changeset',
		'yume_oeuvre',
		'yume_tome',
		'yume_chapitre',
	);

	/** Taxonomies vidées par la purge. */
	public const TAXONOMIES_PURGEES = array( 'category', 'post_tag', 'yume_oeuvre_liee', 'nav_menu', 'post_format' );

	/** Options de la migration remises à zéro par la purge. */
	public const OPTIONS_PURGEES = array(
		'yume_migration_etat',
		'yume_migration_journal',
		'yume_migration_plan',
		'yume_migration_choix',
		'yume_migration_verrou',
		'yume_redirections',
		'yume_pages',
		'sticky_posts',
	);

	/** Structure des permaliens de l'ancien site. */
	public const PERMALIENS = '/%year%/%monthnum%/%day%/%postname%/';

	/** Taille maximale (px) du grand côté d'une image de substitution. */
	public const TAILLE_IMAGE = 480;

	/**
	 * Vérifie que la base courante est une base locale de développement.
	 *
	 * @throws \RuntimeException Environnement de production ou domaine de l'ancien site.
	 */
	public static function verifier_environnement(): void {
		$local = ( defined( 'YUME_DEV' ) && YUME_DEV ) || in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		if ( ! $local ) {
			throw new \RuntimeException( 'Refusé : le peuplement ne tourne que sur une base locale (YUME_DEV ou WP_ENVIRONMENT_TYPE local/development).' );
		}
		$hote = strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
		if ( preg_match( '/(^|\.)yumenovel\.fr$|\.wordpress\.com$|\.wpcomstaging\.com$/', $hote ) ) {
			throw new \RuntimeException( sprintf( 'Refusé : %s ressemble à un site de production.', $hote ) );
		}
	}

	/**
	 * Peuple la base.
	 *
	 * @param string $dossier Dossier de l'export (pages.json, posts.json…).
	 * @param array  $options 'purger' (bool, défaut vrai), 'images' (bool, défaut vrai),
	 *                        'reglages' (bool, défaut vrai : fuseau, permaliens, lecture),
	 *                        'dossier_medias' (chemin relatif à ABSPATH : option upload_path,
	 *                        pour isoler les fichiers d'une base locale), 'auteur' (ID).
	 * @return array<string,mixed> Rapport (comptes, avertissements, durée).
	 * @throws \RuntimeException Environnement refusé ou export illisible.
	 */
	public static function executer( string $dossier, array $options = array() ): array {
		self::verifier_environnement();
		$options = array_merge(
			array(
				'purger'         => true,
				'images'         => true,
				'reglages'       => true,
				'dossier_medias' => '',
				'auteur'         => 0,
			),
			$options
		);
		$debut  = microtime( true );
		$export = Export_Loader::depuis_dossier( $dossier );
		$avert  = array();

		// Les contenus de l'ancien site sont insérés tels quels (blocs, styles en ligne).
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		wp_defer_term_counting( true );

		try {
			if ( $options['purger'] ) {
				self::purger();
			}
			if ( '' !== (string) $options['dossier_medias'] ) {
				update_option( 'upload_path', trim( (string) $options['dossier_medias'], '/' ) );
				update_option( 'upload_url_path', '' );
			}
			if ( $options['reglages'] ) {
				self::reglages();
			}
			$auteur     = (int) $options['auteur'] ? (int) $options['auteur'] : self::auteur();
			$categories = self::categories( $export['categories'], $avert );
			$ids        = array();
			foreach ( $export['pages'] as $page ) {
				$ids[] = self::contenu( $page, 'page', $auteur, $categories, $avert );
			}
			foreach ( $export['posts'] as $post ) {
				$ids[] = self::contenu( $post, 'post', $auteur, $categories, $avert );
			}
			$medias = 0;
			foreach ( $export['media'] as $media ) {
				$medias += (int) (bool) self::media( $media, (bool) $options['images'], $avert );
			}
			$navigations = self::navigations( $export['navigations'], $auteur );
			$parties     = self::parties_de_modele( $export['template_parts'], $auteur );
			self::categorie_par_defaut( $export['categories'], $categories );
		} finally {
			wp_defer_term_counting( false );
			if ( $kses ) {
				kses_init_filters();
			}
		}

		$rapport = array(
			'date'           => current_time( 'mysql' ),
			'dossier'        => $dossier,
			'comptes'        => array(
				'pages'          => count( $export['pages'] ),
				'articles'       => count( $export['posts'] ),
				'categories'     => count( $categories ),
				'medias'         => $medias,
				'navigations'    => $navigations,
				'template_parts' => $parties,
			),
			'ids_conserves'  => count( array_filter( $ids ) ),
			'avertissements' => $avert,
			'duree'          => round( microtime( true ) - $debut, 1 ),
		);
		update_option( 'yume_migration_seed', $rapport, false );
		wp_cache_flush();
		return $rapport;
	}

	/**
	 * Premier administrateur (auteur des contenus insérés).
	 */
	private static function auteur(): int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		return $admins ? (int) $admins[0] : 1;
	}

	/**
	 * Supprime tous les contenus, termes et états de migration de la base courante (SQL
	 * direct, sans hooks : c'est une remise à zéro de base locale).
	 */
	public static function purger(): void {
		global $wpdb;
		self::verifier_environnement();
		$types = implode( ', ', array_fill( 0, count( self::TYPES_PURGES ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( {$types} )", self::TYPES_PURGES ) ) );
		foreach ( array_chunk( $ids, 500 ) as $lot ) {
			$liste = implode( ',', array_map( 'intval', $lot ) );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( {$liste} )" );
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ( {$liste} )" );
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ( SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ( {$liste} ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ( {$liste} )" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ( {$liste} )" );
		}

		$taxos = implode( ', ', array_fill( 0, count( self::TAXONOMIES_PURGEES ), '%s' ) );
		$tt    = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( {$taxos} )", self::TAXONOMIES_PURGEES ) );
		foreach ( (array) $tt as $ligne ) {
			$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $ligne->term_taxonomy_id ) );
			$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $ligne->term_taxonomy_id ) );
			$reste = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $ligne->term_id ) );
			if ( 0 === $reste ) {
				$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $ligne->term_id ) );
				$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $ligne->term_id ) );
			}
		}
		// phpcs:enable

		foreach ( self::OPTIONS_PURGEES as $option ) {
			delete_option( $option );
		}
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );
		wp_cache_flush();
	}

	/**
	 * Réglages de l'ancien site : fuseau, permaliens, page d'accueil.
	 */
	private static function reglages(): void {
		update_option( 'timezone_string', 'Europe/Paris' );
		update_option( 'gmt_offset', '' );
		update_option( 'blogname', 'Yume Novel' );
		if ( get_option( 'permalink_structure' ) !== self::PERMALIENS ) {
			update_option( 'permalink_structure', self::PERMALIENS );
			global $wp_rewrite;
			if ( $wp_rewrite instanceof \WP_Rewrite ) {
				$wp_rewrite->set_permalink_structure( self::PERMALIENS );
			}
		}
	}

	/**
	 * Catégories aux mêmes ID de terme que la production (insertion directe si l'ID est libre).
	 *
	 * @param array $categories Catégories de l'export.
	 * @param array $avert      Avertissements (par référence).
	 * @return array<int,int> ID d'origine => ID local.
	 */
	private static function categories( array $categories, array &$avert ): array {
		global $wpdb;
		$map = array();
		foreach ( $categories as $cat ) {
			$id = (int) $cat['id'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pris = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d", $id ) );
			$slug_pris = get_term_by( 'slug', $cat['slug'], 'category' );
			if ( $slug_pris instanceof \WP_Term ) {
				$map[ $id ] = (int) $slug_pris->term_id;
				if ( (int) $slug_pris->term_id !== $id ) {
					$avert[] = sprintf( 'Catégorie « %s » : déjà présente sous l’ID %d.', $cat['slug'], $slug_pris->term_id );
				}
				continue;
			}
			if ( 0 === $pris ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$wpdb->terms,
					array(
						'term_id'    => $id,
						'name'       => $cat['name'],
						'slug'       => $cat['slug'],
						'term_group' => 0,
					)
				);
				$wpdb->insert(
					$wpdb->term_taxonomy,
					array(
						'term_id'     => $id,
						'taxonomy'    => 'category',
						'description' => $cat['description'],
						'parent'      => (int) $cat['parent'],
						'count'       => 0,
					)
				);
				// phpcs:enable
				clean_term_cache( $id, 'category' );
				$map[ $id ] = $id;
				continue;
			}
			$res = wp_insert_term(
				$cat['name'],
				'category',
				array(
					'slug'        => $cat['slug'],
					'description' => $cat['description'],
				)
			);
			if ( is_wp_error( $res ) ) {
				$avert[] = sprintf( 'Catégorie « %s » non créée : %s', $cat['slug'], $res->get_error_message() );
				continue;
			}
			$map[ $id ] = (int) $res['term_id'];
			$avert[]    = sprintf( 'Catégorie « %s » : ID %d occupé, créée sous l’ID %d.', $cat['slug'], $id, $res['term_id'] );
		}
		clean_term_cache( array_values( $map ), 'category' );
		return $map;
	}

	/**
	 * Catégorie par défaut : « Non classé » de l'export (comme sur le site d'origine), puis
	 * suppression de la catégorie « uncategorized » d'une installation neuve si elle existe.
	 *
	 * @param array $categories Catégories de l'export.
	 * @param array $map        ID d'origine => ID local.
	 */
	private static function categorie_par_defaut( array $categories, array $map ): void {
		foreach ( $categories as $cat ) {
			if ( in_array( $cat['slug'], array( 'non-classe', 'uncategorized' ), true ) && isset( $map[ $cat['id'] ] ) ) {
				update_option( 'default_category', $map[ $cat['id'] ] );
				break;
			}
		}
		wp_update_term_count_now( array_values( $map ), 'category' );
	}

	/**
	 * Date GMT d'une date locale (fuseau du site), ou date nulle pour un brouillon.
	 *
	 * @param string $date   Date locale « Y-m-d H:i:s ».
	 * @param string $statut Statut.
	 */
	private static function gmt( string $date, string $statut ): string {
		if ( '' === $date || in_array( $statut, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return '0000-00-00 00:00:00';
		}
		return get_gmt_from_date( $date );
	}

	/**
	 * Insère une page ou un article à son ID d'origine.
	 *
	 * @param array  $item       Élément de l'export.
	 * @param string $type       'page' ou 'post'.
	 * @param int    $auteur     Auteur local.
	 * @param array  $categories ID d'origine => ID local.
	 * @param array  $avert      Avertissements (par référence).
	 * @return bool Vrai si l'ID d'origine a été conservé.
	 */
	private static function contenu( array $item, string $type, int $auteur, array $categories, array &$avert ): bool {
		global $wpdb;
		$date    = '' !== $item['date'] ? $item['date'] : current_time( 'mysql' );
		$donnees = array(
			'import_id'      => (int) $item['id'],
			'post_type'      => $type,
			'post_status'    => $item['status'],
			'post_title'     => $item['title'],
			'post_name'      => $item['slug'],
			'post_content'   => (string) $item['content'],
			'post_excerpt'   => 'post' === $type ? Html::texte( (string) $item['excerpt'] ) : (string) $item['excerpt'],
			'post_date'      => $date,
			'post_date_gmt'  => self::gmt( $date, $item['status'] ),
			'post_author'    => $auteur,
			'post_parent'    => (int) ( $item['parent'] ?? 0 ),
			'menu_order'     => (int) ( $item['menu_order'] ?? 0 ),
			'comment_status' => 'post' === $type ? 'open' : 'closed',
			'ping_status'    => 'closed',
		);
		if ( 'post' === $type ) {
			$cats = array();
			foreach ( (array) $item['categories'] as $cat ) {
				if ( isset( $categories[ (int) $cat ] ) ) {
					$cats[] = $categories[ (int) $cat ];
				}
			}
			$donnees['post_category'] = $cats;
		}
		$id = wp_insert_post( wp_slash( $donnees ), true );
		if ( is_wp_error( $id ) ) {
			$avert[] = sprintf( '%s %d non inséré(e) : %s', 'page' === $type ? 'Page' : 'Article', $item['id'], $id->get_error_message() );
			return false;
		}
		$id = (int) $id;
		if ( $id !== (int) $item['id'] ) {
			$avert[] = sprintf( '%s %d insérée sous l’ID %d (ID occupé).', 'page' === $type ? 'Page' : 'Article', $item['id'], $id );
		}
		if ( ! empty( $item['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', $item['template'] );
		}
		if ( ! empty( $item['featured_media'] ) ) {
			update_post_meta( $id, '_thumbnail_id', (int) $item['featured_media'] );
		}
		// Slug, dates de modification et GUID exacts (WordPress les recalcule à l'insertion).
		$modifie = '' !== $item['modified'] ? $item['modified'] : $date;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_name'         => $item['slug'],
				'post_modified'     => $modifie,
				'post_modified_gmt' => get_gmt_from_date( $modifie ),
				'guid'              => home_url( '/?' . ( 'page' === $type ? 'page_id' : 'p' ) . '=' . $item['id'] ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		return $id === (int) $item['id'];
	}

	/**
	 * Insère une pièce jointe factice (même ID, même _wp_attached_file) et son image.
	 *
	 * @param array $media  Média de l'export.
	 * @param bool  $images Générer le fichier image.
	 * @param array $avert  Avertissements (par référence).
	 * @return bool Vrai si insérée.
	 */
	private static function media( array $media, bool $images, array &$avert ): bool {
		global $wpdb;
		$fichier = ltrim( (string) $media['file'], '/' );
		if ( '' === $fichier ) {
			$chemin  = (string) wp_parse_url( (string) $media['source_url'], PHP_URL_PATH );
			$fichier = (string) preg_replace( '#^.*/uploads/#', '', $chemin );
		}
		$uploads = wp_get_upload_dir();
		$absolu  = trailingslashit( $uploads['basedir'] ) . $fichier;
		$taille  = array( (int) $media['width'], (int) $media['height'] );
		$poids   = 0;
		if ( $images && str_starts_with( (string) $media['mime_type'], 'image/' ) ) {
			$poids = self::generer_image( $absolu, (string) $media['mime_type'], $taille[0], $taille[1], (string) $media['title'], (int) $media['id'] );
		}
		$date = '' !== $media['date'] ? $media['date'] : current_time( 'mysql' );
		$id   = wp_insert_attachment(
			wp_slash(
				array(
					'import_id'      => (int) $media['id'],
					'post_mime_type' => $media['mime_type'],
					'post_title'     => $media['title'],
					'post_status'    => 'inherit',
					'post_date'      => $date,
					'post_date_gmt'  => get_gmt_from_date( $date ),
					'post_parent'    => (int) $media['post'],
					'guid'           => trailingslashit( $uploads['baseurl'] ) . $fichier,
				)
			),
			false,
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			$avert[] = sprintf( 'Média %d non inséré : %s', $media['id'], $id->get_error_message() );
			return false;
		}
		$id = (int) $id;
		if ( $id !== (int) $media['id'] ) {
			$avert[] = sprintf( 'Média %d inséré sous l’ID %d (ID occupé).', $media['id'], $id );
		}
		// post_parent peut viser un contenu absent de l'export : valeur d'origine conservée.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->posts, array( 'post_parent' => (int) $media['post'] ), array( 'ID' => $id ) );
		update_post_meta( $id, '_wp_attached_file', $fichier );
		if ( '' !== (string) $media['alt_text'] ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $media['alt_text'] );
		}
		wp_update_attachment_metadata(
			$id,
			array(
				'width'      => $taille[0],
				'height'     => $taille[1],
				'file'       => $fichier,
				'filesize'   => $poids,
				'sizes'      => array(),
				'image_meta' => array(),
			)
		);
		clean_post_cache( $id );
		return true;
	}

	/**
	 * Génère une image de substitution (dégradé « couverture » du thème, numéro et titre) aux
	 * proportions de l'original, réduite à TAILLE_IMAGE px sur le grand côté.
	 *
	 * @param string $chemin Chemin absolu du fichier.
	 * @param string $mime   Type MIME.
	 * @param int    $l      Largeur d'origine.
	 * @param int    $h      Hauteur d'origine.
	 * @param string $titre  Titre du média.
	 * @param int    $id     ID du média.
	 * @return int Taille du fichier écrit (0 si rien n'a été écrit).
	 */
	private static function generer_image( string $chemin, string $mime, int $l, int $h, string $titre, int $id ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}
		if ( is_file( $chemin ) ) {
			return (int) filesize( $chemin );
		}
		$l       = max( 1, $l ? $l : 600 );
		$h       = max( 1, $h ? $h : 900 );
		$echelle = min( 1, self::TAILLE_IMAGE / max( $l, $h ) );
		$lr      = max( 8, (int) round( $l * $echelle ) );
		$hr      = max( 8, (int) round( $h * $echelle ) );
		$image   = imagecreatetruecolor( $lr, $hr );
		if ( false === $image ) {
			return 0;
		}
		// Dégradé #5b3a7a → #2d1f4f → #c2437e, teinte décalée selon l'ID pour distinguer les images.
		$haut   = array( 91, 58, 122 );
		$milieu = array( 45, 31, 79 );
		$bas    = array( 194, 67, 126 );
		$decal  = ( $id * 37 ) % 60 - 30;
		for ( $y = 0; $y < $hr; $y++ ) {
			$t = $hr > 1 ? $y / ( $hr - 1 ) : 0;
			if ( $t < 0.5 ) {
				$a = $haut;
				$b = $milieu;
				$u = $t * 2;
			} else {
				$a = $milieu;
				$b = $bas;
				$u = ( $t - 0.5 ) * 2;
			}
			$c = array();
			for ( $i = 0; $i < 3; $i++ ) {
				$c[ $i ] = max( 0, min( 255, (int) round( $a[ $i ] + ( $b[ $i ] - $a[ $i ] ) * $u ) + ( 0 === $i ? $decal : 0 ) ) );
			}
			imageline( $image, 0, $y, $lr - 1, $y, (int) imagecolorallocate( $image, $c[0], $c[1], $c[2] ) );
		}
		$blanc = (int) imagecolorallocate( $image, 255, 248, 251 );
		$texte = strtoupper( (string) remove_accents( $titre ) );
		$texte = (string) preg_replace( '/[^A-Z0-9 ._-]+/', ' ', $texte );
		$par   = max( 4, (int) floor( ( $lr - 16 ) / imagefontwidth( 3 ) ) );
		$y     = 8;
		imagestring( $image, 5, 8, $y, '#' . $id, $blanc );
		$y += imagefontheight( 5 ) + 4;
		foreach ( array_slice( explode( "\n", wordwrap( $texte, $par, "\n", true ) ), 0, 12 ) as $ligne ) {
			imagestring( $image, 3, 8, $y, $ligne, $blanc );
			$y += imagefontheight( 3 ) + 2;
		}
		wp_mkdir_p( dirname( $chemin ) );
		switch ( $mime ) {
			case 'image/png':
				$ok = imagepng( $image, $chemin, 6 );
				break;
			case 'image/webp':
				$ok = function_exists( 'imagewebp' ) && imagewebp( $image, $chemin, 80 );
				break;
			case 'image/gif':
				$ok = imagegif( $image, $chemin );
				break;
			default:
				$ok = imagejpeg( $image, $chemin, 82 );
		}
		imagedestroy( $image );
		return $ok && is_file( $chemin ) ? (int) filesize( $chemin ) : 0;
	}

	/**
	 * Navigations de l'ancien site (du plus récent au plus ancien dans l'export).
	 *
	 * @param array $navigations Navigations de l'export.
	 * @param int   $auteur      Auteur.
	 */
	private static function navigations( array $navigations, int $auteur ): int {
		$n     = 0;
		$total = count( $navigations );
		foreach ( array_values( $navigations ) as $i => $nav ) {
			// Dates décroissantes dans l'ordre de l'export, pour retrouver le même ordre à la lecture.
			$date = gmdate( 'Y-m-d H:i:s', strtotime( '2024-10-14 12:00:00' ) + ( $total - $i ) * 60 );
			$id   = wp_insert_post(
				wp_slash(
					array(
						'import_id'     => (int) $nav['id'],
						'post_type'     => 'wp_navigation',
						'post_status'   => (string) ( $nav['status'] ?? 'publish' ),
						'post_title'    => (string) ( $nav['title'] ?? '' ),
						'post_content'  => (string) ( $nav['content'] ?? '' ),
						'post_author'   => $auteur,
						'post_date'     => $date,
						'post_date_gmt' => get_gmt_from_date( $date ),
					)
				),
				true
			);
			$n += (int) ! is_wp_error( $id );
		}
		return $n;
	}

	/**
	 * Parties de modèle personnalisées de l'ancien thème (wp_template_part en base).
	 *
	 * @param array $parties Parties de l'export (id « thème//slug »).
	 * @param int   $auteur  Auteur.
	 */
	private static function parties_de_modele( array $parties, int $auteur ): int {
		$n = 0;
		foreach ( $parties as $partie ) {
			$ident = (string) ( $partie['id'] ?? '' );
			$theme = str_contains( $ident, '//' ) ? substr( $ident, 0, (int) strpos( $ident, '//' ) ) : 'twentytwentyfour';
			$id    = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'wp_template_part',
						'post_status'  => 'publish',
						'post_name'    => (string) ( $partie['slug'] ?? '' ),
						'post_title'   => (string) ( $partie['title'] ?? '' ),
						'post_content' => (string) ( $partie['content'] ?? '' ),
						'post_author'  => $auteur,
					)
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			wp_set_object_terms( (int) $id, $theme, 'wp_theme' );
			wp_set_object_terms( (int) $id, (string) ( $partie['area'] ?? 'uncategorized' ), 'wp_template_part_area' );
			++$n;
		}
		return $n;
	}
}
