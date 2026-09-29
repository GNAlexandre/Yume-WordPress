<?php
/**
 * Accès sécurisé à une archive ZIP (DOCX et EPUB sont des archives ZIP).
 *
 * - Les entrées sont lues à la demande, jamais extraites sur le disque en bloc.
 * - La taille décompressée de chaque entrée, son taux de compression et le volume cumulé lu
 *   en mémoire sont contrôlés avant lecture (bombes ZIP).
 * - Les chemins internes sont normalisés et ne peuvent pas sortir de l'archive.
 *
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

// Propriétés natives de DOM / XMLReader / ZipArchive (camelCase imposé par PHP).
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
// Messages d'exception en texte brut : échappés à l'affichage (esc_html, réponse JSON, terminal).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Archive ZIP en lecture seule.
 */
final class Zip {

	/** Nombre maximal d'entrées acceptées dans une archive. */
	public const ENTREES_MAX = 20000;

	/** Taille décompressée maximale d'une partie XML lue en mémoire (octets). */
	public const XML_MAX = 64 * 1024 * 1024;

	/** Taille décompressée maximale d'une image copiée (octets). */
	public const IMAGE_MAX = 64 * 1024 * 1024;

	/** Taille décompressée maximale du texte d'un document Word (word/document.xml), lu en continu. */
	public const DOCUMENT_MAX = 128 * 1024 * 1024;

	/**
	 * Taux de compression maximal d'une entrée (taille décompressée / taille compressée). Un
	 * document réel dépasse rarement 10:1 ; une bombe ZIP atteint plusieurs centaines.
	 */
	public const TAUX_MAX = 100;

	/** En dessous de cette taille décompressée, le taux de compression n'est pas contrôlé. */
	public const TAUX_SEUIL = 8 * 1024 * 1024;

	/** Volume décompressé cumulé maximal des parties lues en mémoire (octets). */
	public const LECTURE_MAX = 256 * 1024 * 1024;

	/**
	 * Volume décompressé déjà lu en mémoire (octets).
	 *
	 * @var int
	 */
	private int $lu = 0;

	/**
	 * Archive ouverte.
	 *
	 * @var \ZipArchive
	 */
	private \ZipArchive $zip;

	/**
	 * Chemin absolu du fichier.
	 *
	 * @var string
	 */
	private string $chemin;

	/**
	 * Index nom normalisé → nom réel (recherche insensible à la casse).
	 *
	 * @var array<string,string>
	 */
	private array $noms = array();

	/**
	 * Constructeur privé : utiliser Zip::ouvrir().
	 *
	 * @param \ZipArchive $zip    Archive ouverte.
	 * @param string      $chemin Chemin absolu.
	 */
	private function __construct( \ZipArchive $zip, string $chemin ) {
		$this->zip    = $zip;
		$this->chemin = $chemin;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$nom = $zip->getNameIndex( $i );
			if ( false !== $nom ) {
				$this->noms[ strtolower( $nom ) ] = $nom;
			}
		}
	}

	/**
	 * Ouvre une archive en lecture.
	 *
	 * @param string $chemin  Chemin du fichier.
	 * @param string $libelle Libellé du format pour les messages (« DOCX », « EPUB »).
	 * @throws Import_Exception Fichier absent, illisible, chiffré ou qui n'est pas une archive.
	 */
	public static function ouvrir( string $chemin, string $libelle ): self {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new Import_Exception( 'L’extension PHP « zip » est absente du serveur : impossible de lire le fichier.', 'zip_absent' );
		}
		$reel = realpath( $chemin );
		if ( false === $reel || ! is_file( $reel ) || ! is_readable( $reel ) ) {
			throw new Import_Exception( 'Fichier introuvable ou illisible.', 'fichier_illisible' );
		}
		if ( 0 === (int) filesize( $reel ) ) {
			throw new Import_Exception( 'Le fichier est vide.', 'fichier_vide' );
		}
		$entete = (string) file_get_contents( $reel, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" === $entete ) {
			throw new Import_Exception(
				'Ce document est protégé par un mot de passe ou enregistré au format Word 97-2003 (.doc). Enregistrez-le au format .docx, sans mot de passe, puis déposez-le à nouveau.',
				'fichier_chiffre'
			);
		}
		if ( 0 !== strncmp( $entete, "PK\x03\x04", 4 ) && 0 !== strncmp( $entete, "PK\x05\x06", 4 ) ) {
			throw new Import_Exception( sprintf( 'Ce fichier n’est pas un %s valide (ce n’est pas une archive ZIP).', $libelle ), 'archive_invalide' );
		}
		$zip = new \ZipArchive();
		$ok  = $zip->open( $reel, \ZipArchive::RDONLY );
		if ( true !== $ok ) {
			throw new Import_Exception( sprintf( 'Ce fichier n’est pas un %s valide (archive illisible ou endommagée).', $libelle ), 'archive_invalide' );
		}
		if ( $zip->numFiles > self::ENTREES_MAX ) {
			$zip->close();
			throw new Import_Exception( sprintf( 'Archive %s refusée : elle contient trop de fichiers.', $libelle ), 'archive_trop_grande' );
		}
		return new self( $zip, $reel );
	}

	/**
	 * Chemin absolu de l'archive.
	 */
	public function chemin(): string {
		return $this->chemin;
	}

	/**
	 * Normalise un chemin interne (barres, « . » et « .. ») ; chaîne vide s'il sort de l'archive.
	 *
	 * @param string $chemin Chemin brut (éventuellement encodé en URL).
	 */
	public static function normaliser( string $chemin ): string {
		$chemin = str_replace( '\\', '/', rawurldecode( $chemin ) );
		$sortie = array();
		foreach ( explode( '/', $chemin ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! $sortie ) {
					return '';
				}
				array_pop( $sortie );
				continue;
			}
			$sortie[] = $segment;
		}
		return implode( '/', $sortie );
	}

	/**
	 * Résout un chemin relatif à un fichier de l'archive (ex. cible d'une relation).
	 *
	 * @param string $base   Fichier de référence (ex. « word/document.xml »).
	 * @param string $cible  Cible relative (« media/image1.png ») ou absolue (« /word/media/… »).
	 */
	public static function resoudre( string $base, string $cible ): string {
		$cible = (string) preg_replace( '/[#?].*$/s', '', $cible );
		if ( '' === $cible ) {
			return '';
		}
		if ( '/' === $cible[0] ) {
			return self::normaliser( $cible );
		}
		$dossier = str_contains( $base, '/' ) ? substr( $base, 0, (int) strrpos( $base, '/' ) + 1 ) : '';
		return self::normaliser( $dossier . $cible );
	}

	/**
	 * Nom réel d'une entrée (recherche exacte puis insensible à la casse), ou null.
	 *
	 * @param string $entree Chemin interne.
	 */
	public function nom_reel( string $entree ): ?string {
		$entree = self::normaliser( $entree );
		if ( '' === $entree ) {
			return null;
		}
		if ( false !== $this->zip->locateName( $entree ) ) {
			return $entree;
		}
		return $this->noms[ strtolower( $entree ) ] ?? null;
	}

	/**
	 * L'entrée existe-t-elle ?
	 *
	 * @param string $entree Chemin interne.
	 */
	public function existe( string $entree ): bool {
		return null !== $this->nom_reel( $entree );
	}

	/**
	 * Taille décompressée d'une entrée (-1 si absente).
	 *
	 * @param string $entree Chemin interne.
	 */
	public function taille( string $entree ): int {
		$nom = $this->nom_reel( $entree );
		if ( null === $nom ) {
			return -1;
		}
		$stat = $this->zip->statName( $nom );
		return is_array( $stat ) ? (int) $stat['size'] : -1;
	}

	/**
	 * Refuse une entrée dont le taux de compression est anormal (bombe ZIP).
	 *
	 * @param string $entree Chemin interne.
	 * @throws Import_Exception Taux de compression anormal.
	 */
	public function controler_taux( string $entree ): void {
		$nom = $this->nom_reel( $entree );
		if ( null === $nom ) {
			return;
		}
		$stat = $this->zip->statName( $nom );
		if ( ! is_array( $stat ) ) {
			return;
		}
		$taille    = (int) $stat['size'];
		$compresse = max( 1, (int) $stat['comp_size'] );
		if ( $taille > self::TAUX_SEUIL && $taille / $compresse > self::TAUX_MAX ) {
			throw new Import_Exception(
				sprintf( 'Fichier refusé : la partie « %s » est anormalement compressée (%d:1), comme une bombe de décompression.', $nom, (int) ( $taille / $compresse ) ),
				'archive_suspecte'
			);
		}
	}

	/**
	 * Lit une entrée en mémoire après contrôle de sa taille décompressée.
	 *
	 * @param string $entree Chemin interne.
	 * @param int    $max    Taille maximale (octets).
	 * @return string|null Contenu, ou null si l'entrée est absente.
	 * @throws Import_Exception Entrée trop volumineuse ou illisible.
	 */
	public function lire( string $entree, int $max = self::XML_MAX ): ?string {
		$nom = $this->nom_reel( $entree );
		if ( null === $nom ) {
			return null;
		}
		$taille = $this->taille( $nom );
		if ( $taille > $max ) {
			throw new Import_Exception( sprintf( 'La partie « %s » du fichier est trop volumineuse pour être lue.', $nom ), 'partie_trop_grande' );
		}
		$this->controler_taux( $nom );
		$this->lu += max( 0, $taille );
		if ( $this->lu > self::LECTURE_MAX ) {
			throw new Import_Exception( 'Fichier refusé : son contenu décompressé est trop volumineux. Découpez-le en plusieurs fichiers.', 'archive_trop_grande' );
		}
		$contenu = $this->zip->getFromName( $nom );
		if ( false === $contenu ) {
			throw new Import_Exception( sprintf( 'La partie « %s » du fichier est illisible (archive endommagée).', $nom ), 'partie_illisible' );
		}
		return $contenu;
	}

	/**
	 * URI de flux PHP (zip://…#entrée) pour une lecture en continu (XMLReader, getimagesize).
	 *
	 * @param string $entree Chemin interne (doit exister).
	 */
	public function uri( string $entree ): string {
		return 'zip://' . $this->chemin . '#' . ( $this->nom_reel( $entree ) ?? $entree );
	}

	/**
	 * Premiers octets d'une entrée (signature d'image, par exemple).
	 *
	 * @param string $entree Chemin interne.
	 * @param int    $n      Nombre d'octets.
	 */
	public function debut( string $entree, int $n = 64 ): string {
		$nom = $this->nom_reel( $entree );
		if ( null === $nom ) {
			return '';
		}
		$flux = $this->zip->getStream( $nom );
		if ( false === $flux ) {
			return '';
		}
		$octets = (string) fread( $flux, $n ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $flux ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $octets;
	}

	/**
	 * Copie une entrée vers un fichier, en continu (mémoire bornée).
	 *
	 * @param string $entree      Chemin interne.
	 * @param string $destination Fichier de destination (créé ou écrasé).
	 * @param int    $max         Taille maximale (octets).
	 * @param bool   $taux        Contrôler le taux de compression (faux pour un métafichier Word :
	 *                            un bitmap brut uni se compresse légitimement très bien ; la
	 *                            taille copiée reste bornée par $max).
	 * @return bool Vrai si la copie a réussi.
	 */
	public function copier( string $entree, string $destination, int $max = self::IMAGE_MAX, bool $taux = true ): bool {
		$nom = $this->nom_reel( $entree );
		if ( null === $nom || $this->taille( $nom ) > $max ) {
			return false;
		}
		try {
			if ( $taux ) {
				$this->controler_taux( $nom );
			}
		} catch ( Import_Exception $e ) {
			return false; // Image anormalement compressée : ignorée.
		}
		$source = $this->zip->getStream( $nom );
		if ( false === $source ) {
			return false;
		}
		$cible = fopen( $destination, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $cible ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		$copie = stream_copy_to_stream( $source, $cible, $max + 1 );
		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $cible ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $copie || $copie > $max ) {
			@unlink( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		return true;
	}

	/**
	 * Noms de toutes les entrées.
	 *
	 * @return string[]
	 */
	public function entrees(): array {
		return array_values( $this->noms );
	}

	/**
	 * Ferme l'archive.
	 */
	public function fermer(): void {
		$this->zip->close();
	}
}
