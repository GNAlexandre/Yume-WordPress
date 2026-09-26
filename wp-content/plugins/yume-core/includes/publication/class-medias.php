<?php
/**
 * Versement des images d'un tome dans la médiathèque.
 *
 * - Image extraite de l'archive source en continu, contrôlée (type réel), redimensionnée
 *   à 1600 px au plus (plus grand côté) et convertie en WebP si l'éditeur d'images le permet
 *   (les GIF restent en GIF pour garder leur animation).
 * - Pièce jointe rattachée au chapitre (ou au tome pour la galerie), métadonnées et tailles
 *   générées, texte alternatif renseigné.
 * - Une image déjà versée pour le même tome (même empreinte) est réutilisée : réimporter un
 *   DOCX ne duplique pas la médiathèque.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

use Yume\Core\Import\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Médiathèque.
 */
final class Medias {

	/** Côté maximal d'une illustration (px). */
	public const COTE_MAX = 1600;

	/** Méta : empreinte SHA-1 de l'image d'origine. */
	public const META_EMPREINTE = '_yume_import_image';

	/** Méta : tome d'origine de l'image. */
	public const META_TOME = '_yume_import_tome';

	/**
	 * Charge les fonctions d'administration nécessaires (hors écran d'administration).
	 */
	private static function charger(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	/**
	 * Pièce jointe déjà versée pour ce tome avec cette empreinte, ou 0.
	 *
	 * @param int    $tome_id   Tome.
	 * @param string $empreinte SHA-1 de l'image d'origine.
	 */
	private static function existante( int $tome_id, string $empreinte ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_EMPREINTE,
						'value' => $empreinte,
					),
					array(
						'key'   => self::META_TOME,
						'value' => (string) $tome_id,
					),
				),
			)
		);
		$id  = $ids ? (int) $ids[0] : 0;
		return $id && get_attached_file( $id ) && file_exists( (string) get_attached_file( $id ) ) ? $id : 0;
	}

	/**
	 * Verse une image du Result dans la médiathèque.
	 *
	 * @param Result $resultat Résultat de conversion (archive source encore présente).
	 * @param string $cle      Clé de l'image.
	 * @param int    $rattachement Contenu de rattachement (chapitre ou tome).
	 * @param int    $tome_id  Tome (réutilisation des images).
	 * @param string $alt      Texte alternatif.
	 * @param string $titre    Titre de la pièce jointe.
	 * @return int|\WP_Error ID de la pièce jointe.
	 */
	public static function importer( Result $resultat, string $cle, int $rattachement, int $tome_id, string $alt, string $titre ) {
		self::charger();
		$image = $resultat->images[ $cle ] ?? null;
		if ( null === $image ) {
			return new \WP_Error( 'yume_image_inconnue', __( 'Image inconnue.', 'yume-core' ) );
		}
		$tmp = wp_tempnam( 'yume-image' );
		if ( ! $tmp || ! $resultat->copier_image( $cle, $tmp ) ) {
			if ( $tmp ) {
				wp_delete_file( $tmp );
			}
			/* translators: %s : nom de l'image */
			return new \WP_Error( 'yume_image_extraction', sprintf( __( 'Image « %s » impossible à extraire du fichier.', 'yume-core' ), $image['nom'] ) );
		}
		$fichiers = array( $tmp );
		try {
			$empreinte = (string) sha1_file( $tmp );
			$deja      = self::existante( $tome_id, $empreinte );
			if ( $deja ) {
				if ( $rattachement && (int) wp_get_post_parent_id( $deja ) !== $rattachement ) {
					wp_update_post(
						array(
							'ID'          => $deja,
							'post_parent' => $rattachement,
						)
					);
				}
				return $deja;
			}
			$taille = wp_getimagesize( $tmp );
			$mime   = is_array( $taille ) ? (string) ( $taille['mime'] ?? '' ) : '';
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
				/* translators: %s : nom de l'image */
				return new \WP_Error( 'yume_image_type', sprintf( __( 'Image « %s » refusée : ce n’est pas une image JPG, PNG, WebP ou GIF.', 'yume-core' ), $image['nom'] ) );
			}
			$base   = sanitize_file_name( (string) pathinfo( (string) $image['nom'], PATHINFO_FILENAME ) );
			$base   = '' !== $base ? $base : 'illustration';
			$sortie = self::optimiser( $tmp, $mime );
			$chemin = $sortie['chemin'];
			$mime   = $sortie['mime'];
			$ext    = wp_get_default_extension_for_mime_type( $mime );
			$ext    = $ext ? $ext : 'jpg';
			if ( $chemin !== $tmp ) {
				$fichiers[] = $chemin;
			}
			$fichier = array(
				'name'     => sanitize_file_name( $base . '.' . $ext ),
				'type'     => $mime,
				'tmp_name' => $chemin,
				'error'    => 0,
				'size'     => (int) filesize( $chemin ),
			);
			$deplace = wp_handle_sideload(
				$fichier,
				array(
					'test_form' => false,
					'test_size' => true,
				)
			);
			if ( ! empty( $deplace['error'] ) ) {
				return new \WP_Error( 'yume_image_versement', (string) $deplace['error'] );
			}
			$id = wp_insert_attachment(
				array(
					'post_mime_type' => (string) $deplace['type'],
					'post_title'     => $titre,
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_author'    => get_current_user_id(),
				),
				(string) $deplace['file'],
				$rattachement,
				true
			);
			if ( is_wp_error( $id ) ) {
				wp_delete_file( (string) $deplace['file'] );
				return $id;
			}
			wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, (string) $deplace['file'] ) );
			update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			update_post_meta( (int) $id, self::META_EMPREINTE, $empreinte );
			update_post_meta( (int) $id, self::META_TOME, (string) $tome_id );
			return (int) $id;
		} finally {
			foreach ( $fichiers as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
	}

	/**
	 * Redimensionne (≤ 1600 px) et convertit en WebP si possible.
	 *
	 * @param string $chemin Fichier d'origine.
	 * @param string $mime   Type d'origine.
	 * @return array{chemin:string,mime:string}
	 */
	public static function optimiser( string $chemin, string $mime ): array {
		$origine = array(
			'chemin' => $chemin,
			'mime'   => $mime,
		);
		if ( 'image/gif' === $mime ) {
			return $origine; // Animation conservée.
		}
		$editeur = wp_get_image_editor( $chemin );
		if ( is_wp_error( $editeur ) ) {
			return $origine;
		}
		$taille = $editeur->get_size();
		$grand  = max( (int) ( $taille['width'] ?? 0 ), (int) ( $taille['height'] ?? 0 ) ) > self::COTE_MAX;
		/**
		 * Convertit les illustrations importées en WebP quand l'éditeur d'images le permet.
		 *
		 * @param bool $webp Convertir.
		 */
		$webp = 'image/webp' !== $mime && apply_filters( 'yume_publication_webp', true ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		if ( ! $grand && ! $webp ) {
			return $origine;
		}
		if ( $grand ) {
			$redim = $editeur->resize( self::COTE_MAX, self::COTE_MAX, false );
			if ( is_wp_error( $redim ) ) {
				return $origine;
			}
		}
		$editeur->set_quality( 82 );
		$cible  = $webp ? 'image/webp' : $mime;
		$ext    = (string) wp_get_default_extension_for_mime_type( $cible );
		$sortie = $editeur->save( $chemin . '-yume.' . $ext, $cible );
		if ( is_wp_error( $sortie ) || empty( $sortie['path'] ) || ! is_file( (string) $sortie['path'] ) ) {
			return $origine;
		}
		return array(
			'chemin' => (string) $sortie['path'],
			'mime'   => (string) ( $sortie['mime-type'] ?? $cible ),
		);
	}

	/**
	 * Verse une couverture téléversée (sans conversion) et la rattache au tome.
	 *
	 * @param array<string,mixed> $couverture Résultat de Fichiers::couverture().
	 * @param int                 $tome_id    Tome.
	 * @param string              $alt        Texte alternatif.
	 * @return int|\WP_Error ID de la pièce jointe.
	 */
	public static function couverture( array $couverture, int $tome_id, string $alt ) {
		self::charger();
		$fichier = array(
			'name'     => $couverture['nom'],
			'type'     => $couverture['type'],
			'tmp_name' => $couverture['chemin'],
			'error'    => 0,
			'size'     => (int) $couverture['octets'],
		);
		// Copie : le fichier téléversé reste sous la responsabilité de PHP.
		$copie = wp_tempnam( 'yume-couverture' );
		if ( ! $copie || ! copy( (string) $couverture['chemin'], $copie ) ) {
			return new \WP_Error( 'yume_couverture_copie', __( 'Impossible de lire l’image de couverture.', 'yume-core' ) );
		}
		$fichier['tmp_name'] = $copie;
		$deplace             = wp_handle_sideload(
			$fichier,
			array(
				'test_form' => false,
				'test_size' => true,
			)
		);
		if ( ! empty( $deplace['error'] ) ) {
			wp_delete_file( $copie );
			return new \WP_Error( 'yume_couverture_versement', (string) $deplace['error'] );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $deplace['type'],
				'post_title'     => $alt,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			(string) $deplace['file'],
			$tome_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( (string) $deplace['file'] );
			return $id;
		}
		wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, (string) $deplace['file'] ) );
		update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		return (int) $id;
	}
}
