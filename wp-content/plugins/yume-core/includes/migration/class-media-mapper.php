<?php
/**
 * Correspondance des médias du plan avec les pièces jointes de la base : par ID d'abord
 * (migration sur place, les pièces jointes gardent leur ID), puis par suffixe de
 * _wp_attached_file (« 2024/10/logo.png ») tiré de l'URL du plan, puis par nom de fichier
 * s'il est unique. Réécrit aussi les références d'images d'un contenu de blocs quand un ID
 * change.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Correspondance des pièces jointes.
 */
final class Media_Mapper {

	/**
	 * Chemin relatif au dossier des médias tiré d'une URL (« 2024/10/logo.png »), ou ''.
	 *
	 * @param string $url URL d'un média.
	 */
	public static function suffixe( string $url ): string {
		$chemin = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $chemin ) {
			return '';
		}
		if ( preg_match( '#/uploads/(?:sites/\d+/)?(.+)$#', $chemin, $m ) ) {
			return rawurldecode( $m[1] );
		}
		return '';
	}

	/**
	 * Pièce jointe existante d'un ID ?
	 *
	 * @param int $id ID.
	 */
	private static function est_piece_jointe( int $id ): bool {
		return $id > 0 && 'attachment' === get_post_type( $id );
	}

	/**
	 * Pièce jointe dont _wp_attached_file vaut exactement ce chemin.
	 *
	 * @param string $fichier Chemin relatif.
	 */
	private static function par_fichier( string $fichier ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
				WHERE m.meta_key = '_wp_attached_file' AND m.meta_value = %s ORDER BY m.post_id ASC LIMIT 2",
				$fichier
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Pièce jointe unique dont _wp_attached_file se termine par ce nom de fichier.
	 *
	 * @param string $nom Nom de fichier (logo.png).
	 */
	private static function par_nom( string $nom ): int {
		global $wpdb;
		if ( '' === $nom ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
				WHERE m.meta_key = '_wp_attached_file' AND ( m.meta_value = %s OR m.meta_value LIKE %s ) LIMIT 2",
				$nom,
				'%/' . $wpdb->esc_like( $nom )
			)
		);
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Associe chaque média référencé par le plan à une pièce jointe locale.
	 *
	 * @param array $references Références du plan (medias.references[] : id, url).
	 * @param array $avert      Avertissements (par référence).
	 * @return array<int,int> ID du plan => ID local (0 si introuvable).
	 */
	public static function correspondances( array $references, array &$avert ): array {
		$map = array();
		foreach ( $references as $ref ) {
			$id      = (int) ( $ref['id'] ?? 0 );
			$suffixe = self::suffixe( (string) ( $ref['url'] ?? '' ) );
			if ( ! $id ) {
				continue;
			}
			$map[ $id ] = self::trouver( $id, $suffixe, $avert );
		}
		return $map;
	}

	/**
	 * Pièce jointe locale d'un média du plan.
	 *
	 * @param int    $id      ID dans le plan.
	 * @param string $suffixe Chemin relatif attendu (peut être vide).
	 * @param array  $avert   Avertissements (par référence).
	 */
	public static function trouver( int $id, string $suffixe, array &$avert ): int {
		if ( self::est_piece_jointe( $id ) ) {
			$fichier = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( '' === $suffixe || $fichier === $suffixe || basename( $fichier ) === basename( $suffixe ) ) {
				return $id;
			}
			$autre = self::par_fichier( $suffixe );
			if ( $autre ) {
				$avert[] = sprintf( 'Média %d : l’ID existe mais désigne « %s » ; « %s » trouvé sous l’ID %d.', $id, $fichier, $suffixe, $autre );
				return $autre;
			}
			$avert[] = sprintf( 'Média %d : fichier « %s » attendu, « %s » trouvé ; ID conservé.', $id, $suffixe, $fichier );
			return $id;
		}
		if ( '' !== $suffixe ) {
			$autre = self::par_fichier( $suffixe );
			if ( ! $autre ) {
				$autre = self::par_nom( basename( $suffixe ) );
			}
			if ( $autre ) {
				$avert[] = sprintf( 'Média %d absent : « %s » retrouvé sous l’ID %d.', $id, $suffixe, $autre );
				return $autre;
			}
		}
		$avert[] = sprintf( 'Média %d introuvable%s : image laissée vide.', $id, '' !== $suffixe ? ' (« ' . $suffixe . ' »)' : '' );
		return 0;
	}

	/**
	 * ID local d'un média du plan (0 si inconnu ou introuvable).
	 *
	 * @param array $map Correspondances (ID du plan => ID local).
	 * @param int   $id  ID dans le plan.
	 */
	public static function local( array $map, int $id ): int {
		if ( ! $id ) {
			return 0;
		}
		if ( array_key_exists( $id, $map ) ) {
			return (int) $map[ $id ];
		}
		return self::est_piece_jointe( $id ) ? $id : 0;
	}

	/**
	 * Réécrit les blocs image d'un contenu dont la pièce jointe a changé d'ID : attribut
	 * « id », classe wp-image-N et URL de l'image.
	 *
	 * @param string $contenu Contenu de blocs.
	 * @param array  $map     ID du plan => ID local.
	 */
	public static function remapper_contenu( string $contenu, array $map ): string {
		$changes = array_filter( $map, static fn( $local, $plan ) => (int) $local !== (int) $plan && (int) $local > 0, ARRAY_FILTER_USE_BOTH );
		if ( ! $changes || false === strpos( $contenu, 'wp:image' ) ) {
			return $contenu;
		}
		return (string) preg_replace_callback(
			'#<!-- wp:image (\{.*?\}) -->(.*?)<!-- /wp:image -->#s',
			static function ( array $m ) use ( $changes ): string {
				$attrs = json_decode( $m[1], true );
				$id    = is_array( $attrs ) ? (int) ( $attrs['id'] ?? 0 ) : 0;
				if ( ! $id || ! isset( $changes[ $id ] ) ) {
					return $m[0];
				}
				$nouveau     = (int) $changes[ $id ];
				$attrs['id'] = $nouveau;
				$html        = str_replace( 'wp-image-' . $id . '"', 'wp-image-' . $nouveau . '"', $m[2] );
				$url         = wp_get_attachment_url( $nouveau );
				if ( $url ) {
					$html = (string) preg_replace_callback(
						'#(<img\s[^>]*src=")[^"]*(")#i',
						static fn( array $i ): string => $i[1] . esc_url( $url ) . $i[2],
						$html,
						1
					);
				}
				return '<!-- wp:image ' . serialize_block_attributes( $attrs ) . ' -->' . $html . '<!-- /wp:image -->';
			},
			$contenu
		);
	}
}
