<?php
/**
 * Contrôle des fichiers téléversés pour une publication.
 *
 * - Source (DOCX ou EPUB) : extension, type réel (finfo + signature ZIP), taille maximale
 *   (filtre yume_publication_taille_max), nom nettoyé. Le fichier n'est jamais déplacé
 *   dans la médiathèque : il est lu sur place puis supprimé (Fichiers::supprimer()).
 * - Couverture (JPG, PNG, WebP) : extension, type réel, dimensions lisibles, taille maximale
 *   (filtre yume_publication_taille_max_couverture).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

/**
 * Fichiers téléversés.
 */
final class Fichiers {

	/** Types réels acceptés pour un DOCX (selon la version de libmagic). */
	private const MIMES_DOCX = array(
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/zip',
		'application/x-zip-compressed',
		'application/octet-stream',
	);

	/** Types réels acceptés pour un EPUB. */
	private const MIMES_EPUB = array( 'application/epub+zip', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' );

	/** Types d'image acceptés pour la couverture : extension => type. */
	private const IMAGES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/**
	 * Taille maximale d'un fichier source (octets), bornée par la configuration du serveur.
	 */
	public static function taille_max_source(): int {
		/**
		 * Taille maximale du DOCX ou de l'EPUB déposé (octets).
		 *
		 * @param int $octets Défaut : 128 Mo.
		 */
		$max     = (int) apply_filters( 'yume_publication_taille_max', 128 * MB_IN_BYTES );
		$serveur = (int) wp_max_upload_size();
		return $serveur > 0 ? min( $max, $serveur ) : $max;
	}

	/**
	 * Taille maximale d'une couverture (octets).
	 */
	public static function taille_max_couverture(): int {
		/**
		 * Taille maximale de l'image de couverture (octets).
		 *
		 * @param int $octets Défaut : 15 Mo.
		 */
		$max     = (int) apply_filters( 'yume_publication_taille_max_couverture', 15 * MB_IN_BYTES );
		$serveur = (int) wp_max_upload_size();
		return $serveur > 0 ? min( $max, $serveur ) : $max;
	}

	/**
	 * Taille lisible (« 26,2 Mo »).
	 *
	 * @param int $octets Octets.
	 */
	public static function taille_lisible( int $octets ): string {
		if ( $octets >= MB_IN_BYTES ) {
			return str_replace( '.', ',', (string) round( $octets / MB_IN_BYTES, 1 ) ) . ' Mo';
		}
		return max( 1, (int) round( $octets / KB_IN_BYTES ) ) . ' ko';
	}

	/**
	 * Un fichier a-t-il été fourni (hors « aucun fichier ») ?
	 *
	 * @param mixed $fichier Entrée de $_FILES.
	 */
	public static function fourni( $fichier ): bool {
		return is_array( $fichier ) && isset( $fichier['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $fichier['error'] && '' !== (string) ( $fichier['tmp_name'] ?? '' );
	}

	/**
	 * Message d'une erreur de téléversement PHP.
	 *
	 * @param int $code Code UPLOAD_ERR_*.
	 */
	private static function message_erreur( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'Le fichier dépasse la taille maximale acceptée par le serveur.', 'yume-core' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Le fichier n’a été reçu qu’en partie : réessayez.', 'yume-core' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Aucun fichier reçu.', 'yume-core' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Le serveur n’a pas pu enregistrer le fichier (dossier temporaire indisponible).', 'yume-core' );
			default:
				return __( 'Le téléversement du fichier a échoué.', 'yume-core' );
		}
	}

	/**
	 * Le fichier temporaire provient-il bien d'un téléversement ?
	 *
	 * @param string $chemin Chemin temporaire.
	 */
	private static function televerse( string $chemin ): bool {
		/**
		 * Accepte un fichier local qui n'a pas été téléversé par HTTP (tests, outils).
		 *
		 * @param bool   $accepte Accepter.
		 * @param string $chemin  Chemin.
		 */
		return is_uploaded_file( $chemin ) || (bool) apply_filters( 'yume_publication_fichier_local', false, $chemin );
	}

	/**
	 * Type réel d'un fichier (finfo).
	 *
	 * @param string $chemin Chemin.
	 */
	private static function type_reel( string $chemin ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return '';
		}
		$type = (string) finfo_file( $finfo, $chemin );
		finfo_close( $finfo );
		return strtolower( $type );
	}

	/**
	 * Contrôle le fichier source (DOCX ou EPUB).
	 *
	 * @param array<string,mixed> $fichier Entrée de $_FILES (name, tmp_name, error, size).
	 * @return array{chemin:string,nom:string,format:string,octets:int,televerse:bool}|\WP_Error
	 */
	public static function source( array $fichier ) {
		$erreur = (int) ( $fichier['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $erreur ) {
			return new \WP_Error( 'yume_fichier_televersement', self::message_erreur( $erreur ), array( 'status' => 400 ) );
		}
		$chemin = (string) ( $fichier['tmp_name'] ?? '' );
		if ( '' === $chemin || ! is_file( $chemin ) || ! self::televerse( $chemin ) ) {
			return new \WP_Error( 'yume_fichier_invalide', __( 'Fichier reçu invalide.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$infos = array(
			'chemin'    => $chemin,
			'nom'       => sanitize_file_name( wp_basename( (string) ( $fichier['name'] ?? 'fichier' ) ) ),
			'format'    => '',
			'octets'    => (int) filesize( $chemin ),
			'televerse' => true,
		);
		$ext   = strtolower( pathinfo( $infos['nom'], PATHINFO_EXTENSION ) );
		$max   = self::taille_max_source();
		if ( $infos['octets'] > $max ) {
			return new \WP_Error(
				'yume_fichier_trop_grand',
				/* translators: 1: taille du fichier, 2: taille maximale */
				sprintf( __( 'Fichier trop volumineux (%1$s ; maximum %2$s).', 'yume-core' ), self::taille_lisible( $infos['octets'] ), self::taille_lisible( $max ) ),
				array( 'status' => 413 )
			);
		}
		if ( ! in_array( $ext, array( 'docx', 'epub' ), true ) ) {
			return new \WP_Error( 'yume_fichier_format', __( 'Format refusé : déposez le fichier Word du tome (.docx) ou, à défaut, son EPUB (.epub). Les PDF ne sont pas découpés en chapitres.', 'yume-core' ), array( 'status' => 415 ) );
		}
		$type   = self::type_reel( $chemin );
		$entete = (string) file_get_contents( $chemin, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( str_starts_with( $type, 'application/cdfv2' ) || str_starts_with( $type, 'application/x-ole-storage' ) || "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" === $entete ) {
			return new \WP_Error( 'yume_fichier_chiffre', __( 'Ce document est protégé par un mot de passe ou enregistré au format Word 97-2003 (.doc). Enregistrez-le au format .docx, sans mot de passe, puis déposez-le à nouveau.', 'yume-core' ), array( 'status' => 415 ) );
		}
		$permis = 'docx' === $ext ? self::MIMES_DOCX : self::MIMES_EPUB;
		if ( ( '' !== $type && ! in_array( $type, $permis, true ) ) || 0 !== strncmp( $entete, "PK\x03\x04", 4 ) ) {
			return new \WP_Error(
				'yume_fichier_type',
				/* translators: %s : extension attendue */
				sprintf( __( 'Le contenu du fichier ne correspond pas à un %s valide.', 'yume-core' ), strtoupper( $ext ) ),
				array( 'status' => 415 )
			);
		}
		$infos['format'] = $ext;
		return $infos;
	}

	/**
	 * Contrôle l'image de couverture.
	 *
	 * @param array<string,mixed> $fichier Entrée de $_FILES.
	 * @return array{chemin:string,nom:string,type:string,octets:int}|\WP_Error
	 */
	public static function couverture( array $fichier ) {
		$erreur = (int) ( $fichier['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $erreur ) {
			return new \WP_Error( 'yume_couverture_televersement', self::message_erreur( $erreur ), array( 'status' => 400 ) );
		}
		$chemin = (string) ( $fichier['tmp_name'] ?? '' );
		if ( '' === $chemin || ! is_file( $chemin ) || ! self::televerse( $chemin ) ) {
			return new \WP_Error( 'yume_couverture_invalide', __( 'Image de couverture reçue invalide.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$nom    = sanitize_file_name( wp_basename( (string) ( $fichier['name'] ?? 'couverture' ) ) );
		$ext    = strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) );
		$octets = (int) filesize( $chemin );
		if ( $octets > self::taille_max_couverture() ) {
			return new \WP_Error(
				'yume_couverture_trop_grande',
				/* translators: %s : taille maximale */
				sprintf( __( 'Image de couverture trop volumineuse (maximum %s).', 'yume-core' ), self::taille_lisible( self::taille_max_couverture() ) ),
				array( 'status' => 413 )
			);
		}
		$type   = self::type_reel( $chemin );
		$taille = wp_getimagesize( $chemin );
		if ( ! isset( self::IMAGES[ $ext ] ) || ! is_array( $taille ) || ! in_array( $type, array_unique( array_values( self::IMAGES ) ), true ) || self::IMAGES[ $ext ] !== $type || ( $taille['mime'] ?? '' ) !== $type ) {
			return new \WP_Error( 'yume_couverture_format', __( 'Couverture refusée : image JPG, PNG ou WebP attendue.', 'yume-core' ), array( 'status' => 415 ) );
		}
		return array(
			'chemin' => $chemin,
			'nom'    => $nom,
			'type'   => $type,
			'octets' => $octets,
		);
	}

	/**
	 * Supprime un fichier source téléversé (jamais un autre fichier du serveur).
	 *
	 * @param mixed $fichier Entrée de $_FILES ou résultat de Fichiers::source().
	 */
	public static function supprimer( $fichier ): void {
		if ( ! is_array( $fichier ) ) {
			return;
		}
		$chemin = (string) ( $fichier['chemin'] ?? ( $fichier['tmp_name'] ?? '' ) );
		if ( '' !== $chemin && is_file( $chemin ) && self::televerse( $chemin ) ) {
			wp_delete_file( $chemin );
		}
	}
}
