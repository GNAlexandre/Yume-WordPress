<?php
/**
 * Images Word au format EMF/WMF (et leurs variantes compressées EMZ/WMZ) → PNG.
 *
 * Un métafichier Windows est une suite d'enregistrements de dessin. Word y range souvent une
 * simple image bitmap : un en-tête, un enregistrement EMR_STRETCHDIBITS portant un DIB
 * (BITMAPINFO + pixels), puis EMR_EOF. On extrait ici le plus grand bitmap porté par
 * EMR_BITBLT, EMR_STRETCHBLT, EMR_SETDIBITSTODEVICE, EMR_STRETCHDIBITS, EMR_ALPHABLEND,
 * EMR_TRANSPARENTBLT (EMF) ou META_DIBBITBLT, META_DIBSTRETCHBLT, META_STRETCHDIB (WMF) :
 * - DIB non compressé (BI_RGB, 1/4/8/16/24/32 bits) ou à masques (BI_BITFIELDS) : fichier BMP
 *   reconstitué puis lu par GD, enregistré en PNG (le module « publication » le redimensionne
 *   et le convertit ensuite en WebP comme toute image) ;
 * - DIB BI_JPEG / BI_PNG : le flux JPEG ou PNG est extrait tel quel ;
 * - dessin purement vectoriel, fichier tronqué, tailles incohérentes, image démesurée : refus
 *   motivé (la raison est rendue à l'appelant, qui avertit l'utilisateur).
 *
 * Sécurité : le métafichier est copié de l'archive dans un fichier temporaire (taille bornée),
 * jamais lu en entier en mémoire ; chaque taille ou position déclarée est confrontée à la taille
 * réelle ; dimensions, nombre de pixels et mémoire estimée (largeur × hauteur × 4) sont bornés
 * avant tout décodage ; aucun code n'est exécuté (lecture d'octets uniquement).
 *
 * Aucune fonction WordPress (sauf get_temp_dir(), si elle existe, pour le dossier temporaire).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

// Lecture binaire de fichiers temporaires (flux PHP natifs, hors WP_Filesystem).
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Extraction du bitmap d'un métafichier Word.
 */
final class Metafichier {

	/** Côté maximal d'une image extraite (pixels). */
	public const COTE_MAX = 12000;

	/** Nombre maximal de pixels d'une image extraite. */
	public const PIXELS_MAX = 60000000;

	/** Mémoire maximale estimée pour le décodage (largeur × hauteur × 4 octets). */
	public const MEMOIRE_MAX = 256 * 1024 * 1024;

	/** Taille maximale du métafichier (décompressé pour EMZ/WMZ). */
	public const TAILLE_MAX = Zip::IMAGE_MAX;

	/** Nombre maximal d'enregistrements parcourus. */
	private const ENREGISTREMENTS_MAX = 2000000;

	/** Taille maximale d'un en-tête BITMAPINFO lu (en-tête V5, masques et palette compris). */
	private const BMI_MAX = 65536;

	/** Pixels maximaux convertis en PHP (masques BI_BITFIELDS non standard, lent). */
	private const LENT_MAX = 16000000;

	/**
	 * Enregistrements EMF porteurs d'un bitmap : type => positions (dans l'enregistrement) de
	 * UsageSrc et de offBmiSrc (suivi de cbBmiSrc, offBitsSrc, cbBitsSrc), taille minimale.
	 */
	private const EMF_BITMAPS = array(
		76  => array( 80, 84, 100 ), // EMR_BITBLT.
		77  => array( 80, 84, 108 ), // EMR_STRETCHBLT.
		114 => array( 80, 84, 108 ), // EMR_ALPHABLEND.
		116 => array( 80, 84, 108 ), // EMR_TRANSPARENTBLT.
		80  => array( 64, 48, 76 ),  // EMR_SETDIBITSTODEVICE.
		81  => array( 64, 48, 80 ),  // EMR_STRETCHDIBITS.
	);

	/** Enregistrements WMF porteurs d'un DIB : fonction => position du DIB dans l'enregistrement. */
	private const WMF_BITMAPS = array(
		0x0940 => 22, // META_DIBBITBLT.
		0x0B41 => 26, // META_DIBSTRETCHBLT.
		0x0F43 => 28, // META_STRETCHDIB.
	);

	/**
	 * Analyse un métafichier de l'archive, sans le convertir.
	 *
	 * @param Zip    $zip    Archive.
	 * @param string $entree Chemin dans l'archive.
	 * @return array{largeur?:int,hauteur?:int,mime?:string,erreur?:string} Dimensions et type de
	 *         l'image produite, ou raison du refus (« dessin purement vectoriel… »).
	 */
	public static function analyser( Zip $zip, string $entree ): array {
		return self::traiter( $zip, $entree, null );
	}

	/**
	 * Convertit un métafichier de l'archive en image PNG (ou JPEG/PNG d'origine).
	 *
	 * @param Zip    $zip         Archive.
	 * @param string $entree      Chemin dans l'archive.
	 * @param string $destination Fichier écrit.
	 * @return array{largeur?:int,hauteur?:int,mime?:string,erreur?:string}
	 */
	public static function convertir( Zip $zip, string $entree, string $destination ): array {
		return self::traiter( $zip, $entree, $destination );
	}

	/**
	 * Copie, décompression éventuelle, localisation du bitmap et conversion.
	 *
	 * @param Zip         $zip         Archive.
	 * @param string      $entree      Chemin dans l'archive.
	 * @param string|null $destination Fichier écrit (null : analyse seule).
	 * @return array{largeur?:int,hauteur?:int,mime?:string,erreur?:string}
	 */
	private static function traiter( Zip $zip, string $entree, ?string $destination ): array {
		$temporaires = array();
		$flux        = null;
		try {
			$fichier       = self::temporaire();
			$temporaires[] = $fichier;
			if ( '' === $fichier || ! $zip->copier( $entree, $fichier, self::TAILLE_MAX, false ) ) {
				return self::erreur( 'fichier illisible ou trop volumineux' );
			}
			if ( "\x1F\x8B" === (string) file_get_contents( $fichier, false, null, 0, 2 ) ) {
				$clair         = self::temporaire();
				$temporaires[] = $clair;
				if ( '' === $clair || ! self::decompresser( $fichier, $clair ) ) {
					return self::erreur( 'compression EMZ/WMZ illisible ou contenu trop volumineux' );
				}
				$fichier = $clair;
			}
			$flux = fopen( $fichier, 'rb' );
			if ( false === $flux ) {
				return self::erreur( 'fichier illisible' );
			}
			$bitmap = self::localiser( $flux, (int) filesize( $fichier ) );
			if ( is_string( $bitmap ) ) {
				return self::erreur( $bitmap );
			}
			if ( null !== $destination ) {
				$erreur = self::ecrire( $flux, $bitmap, $destination );
				if ( null !== $erreur ) {
					if ( is_file( $destination ) ) {
						@unlink( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
					return self::erreur( $erreur );
				}
			}
			return array(
				'largeur' => (int) $bitmap['largeur'],
				'hauteur' => (int) $bitmap['hauteur'],
				'mime'    => (string) $bitmap['mime'],
			);
		} finally {
			if ( is_resource( $flux ) ) {
				fclose( $flux );
			}
			foreach ( $temporaires as $t ) {
				if ( '' !== $t && is_file( $t ) ) {
					@unlink( $t ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
	}

	/**
	 * Réponse de refus.
	 *
	 * @param string $raison Raison lisible.
	 * @return array{erreur:string}
	 */
	private static function erreur( string $raison ): array {
		return array( 'erreur' => $raison );
	}

	/**
	 * Fichier temporaire vide (chaîne vide en cas d'échec).
	 */
	private static function temporaire(): string {
		$dossier = function_exists( 'get_temp_dir' ) ? (string) get_temp_dir() : sys_get_temp_dir();
		$chemin  = @tempnam( $dossier, 'yume-emf' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $chemin ? '' : $chemin;
	}

	/**
	 * Décompresse un fichier gzip (EMZ, WMZ) en continu, taille bornée.
	 *
	 * @param string $source      Fichier compressé.
	 * @param string $destination Fichier décompressé.
	 */
	private static function decompresser( string $source, string $destination ): bool {
		if ( ! function_exists( 'inflate_init' ) ) {
			return false;
		}
		$contexte = inflate_init( ZLIB_ENCODING_GZIP );
		$entree   = fopen( $source, 'rb' );
		$sortie   = fopen( $destination, 'wb' );
		$total    = 0;
		$fini     = false;
		$ok       = false !== $contexte && false !== $entree && false !== $sortie;
		while ( $ok && ! $fini ) {
			$morceau = (string) fread( $entree, 65536 );
			$dernier = feof( $entree ) || '' === $morceau;
			$clair   = @inflate_add( $contexte, $morceau, $dernier ? ZLIB_FINISH : ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $clair ) {
				$ok = false;
				break;
			}
			$total += strlen( $clair );
			if ( $total > self::TAILLE_MAX ) {
				$ok = false;
				break;
			}
			fwrite( $sortie, $clair );
			$fini = ZLIB_STREAM_END === inflate_get_status( $contexte ) || $dernier;
		}
		if ( $ok && ZLIB_STREAM_END !== inflate_get_status( $contexte ) ) {
			$ok = false; // Flux gzip tronqué.
		}
		if ( false !== $entree ) {
			fclose( $entree );
		}
		if ( false !== $sortie ) {
			fclose( $sortie );
		}
		return $ok && $total > 0;
	}

	/**
	 * Lit des octets à une position donnée (chaîne vide si hors limites).
	 *
	 * @param resource $flux     Fichier.
	 * @param int      $position Position.
	 * @param int      $longueur Nombre d'octets.
	 */
	private static function lire( $flux, int $position, int $longueur ): string {
		if ( $position < 0 || $longueur <= 0 || 0 !== fseek( $flux, $position ) ) {
			return '';
		}
		$octets = (string) fread( $flux, $longueur );
		return strlen( $octets ) === $longueur ? $octets : '';
	}

	/**
	 * Reconnaît le format et localise le plus grand bitmap.
	 *
	 * @param resource $flux   Fichier.
	 * @param int      $taille Taille réelle.
	 * @return array<string,mixed>|string Bitmap, ou raison du refus.
	 */
	private static function localiser( $flux, int $taille ) {
		$debut = self::lire( $flux, 0, min( 88, $taille ) );
		if ( strlen( $debut ) >= 88 && 1 === unpack( 'V', $debut )[1] && ' EMF' === substr( $debut, 40, 4 ) ) {
			return self::localiser_emf( $flux, $taille, $debut );
		}
		if ( strlen( $debut ) >= 22 && "\xD7\xCD\xC6\x9A" === substr( $debut, 0, 4 ) ) {
			return self::localiser_wmf( $flux, $taille, 22 );
		}
		if ( strlen( $debut ) >= 18 ) {
			$entete = unpack( 'vtype/vtaille/vversion', $debut );
			if ( in_array( $entete['type'], array( 1, 2 ), true ) && 9 === $entete['taille'] && in_array( $entete['version'], array( 0x0100, 0x0300 ), true ) ) {
				return self::localiser_wmf( $flux, $taille, 0 );
			}
		}
		return 'fichier endommagé ou format non reconnu (ni EMF ni WMF)';
	}

	/**
	 * EMF : parcourt les enregistrements.
	 *
	 * @param resource $flux   Fichier.
	 * @param int      $taille Taille réelle.
	 * @param string   $debut  88 premiers octets (EMR_HEADER).
	 * @return array<string,mixed>|string
	 */
	private static function localiser_emf( $flux, int $taille, string $debut ) {
		$entete = unpack( 'Vtype/Vtaille', $debut );
		$borne  = (int) unpack( 'V', $debut, 48 )[1]; // nBytes : taille déclarée du fichier.
		if ( $borne > $taille ) {
			return sprintf( 'fichier EMF tronqué (%1$d octets déclarés, %2$d présents)', $borne, $taille );
		}
		if ( $entete['taille'] < 88 || $entete['taille'] > $borne ) {
			return 'fichier EMF endommagé (en-tête incohérent)';
		}
		$meilleur = null;
		$refus    = '';
		$position = 0;
		$n        = 0;
		while ( $position + 8 <= $borne ) {
			if ( ++$n > self::ENREGISTREMENTS_MAX ) {
				return 'fichier EMF trop complexe';
			}
			$octets = self::lire( $flux, $position, 8 );
			if ( '' === $octets ) {
				return 'fichier EMF tronqué';
			}
			$rec = unpack( 'Vtype/Vtaille', $octets );
			if ( $rec['taille'] < 8 || 0 !== $rec['taille'] % 4 || $position + $rec['taille'] > $borne ) {
				return 'fichier EMF endommagé (taille d’enregistrement incohérente)';
			}
			if ( isset( self::EMF_BITMAPS[ $rec['type'] ] ) ) {
				$bitmap = self::bitmap_emf( $flux, $position, (int) $rec['type'], (int) $rec['taille'] );
				if ( is_string( $bitmap ) ) {
					$refus = '' === $refus ? $bitmap : $refus;
				} elseif ( is_array( $bitmap ) && ( null === $meilleur || $bitmap['largeur'] * $bitmap['hauteur'] > $meilleur['largeur'] * $meilleur['hauteur'] ) ) {
					$meilleur = $bitmap;
				}
			}
			if ( 14 === $rec['type'] ) {
				break; // EMR_EOF.
			}
			$position += $rec['taille'];
		}
		return $meilleur ?? ( '' !== $refus ? $refus : 'dessin purement vectoriel, sans image bitmap à extraire' );
	}

	/**
	 * EMF : bitmap d'un enregistrement de transfert de pixels.
	 *
	 * @param resource $flux     Fichier.
	 * @param int      $position Début de l'enregistrement.
	 * @param int      $type     Type d'enregistrement.
	 * @param int      $taille   Taille de l'enregistrement.
	 * @return array<string,mixed>|string|null Bitmap, raison du refus, ou null (pas de bitmap source).
	 */
	private static function bitmap_emf( $flux, int $position, int $type, int $taille ) {
		list( $p_usage, $p_champs, $minimum ) = self::EMF_BITMAPS[ $type ];
		$fixe                                 = $taille >= $minimum ? self::lire( $flux, $position, $minimum ) : '';
		if ( '' === $fixe ) {
			return 'fichier EMF endommagé (enregistrement d’image trop court)';
		}
		$usage = (int) unpack( 'V', $fixe, $p_usage )[1];
		$champ = unpack( 'Voff_bmi/Vcb_bmi/Voff_bits/Vcb_bits', $fixe, $p_champs );
		if ( 0 === $champ['cb_bmi'] || 0 === $champ['cb_bits'] ) {
			return null; // Opération sans bitmap source (remplissage).
		}
		if ( $champ['off_bmi'] < $minimum || $champ['off_bmi'] + $champ['cb_bmi'] > $taille
			|| $champ['off_bits'] < $minimum || $champ['off_bits'] + $champ['cb_bits'] > $taille ) {
			return 'fichier EMF endommagé (image hors de son enregistrement)';
		}
		if ( $champ['cb_bmi'] < 40 || $champ['cb_bmi'] > self::BMI_MAX ) {
			return 'image intégrée endommagée (en-tête BITMAPINFO de taille incohérente)';
		}
		$bmi = self::lire( $flux, $position + $champ['off_bmi'], $champ['cb_bmi'] );
		return self::dib( $bmi, $usage, $position + $champ['off_bits'], $champ['cb_bits'], false );
	}

	/**
	 * WMF : parcourt les enregistrements.
	 *
	 * @param resource $flux   Fichier.
	 * @param int      $taille Taille réelle.
	 * @param int      $base   Début de META_HEADER (22 après un en-tête « placeable »).
	 * @return array<string,mixed>|string
	 */
	private static function localiser_wmf( $flux, int $taille, int $base ) {
		$octets = self::lire( $flux, $base, 18 );
		if ( '' === $octets ) {
			return 'fichier WMF tronqué';
		}
		$entete = unpack( 'vtype/vtaille_entete/vversion/Vmots', $octets );
		$borne  = $base + 2 * (int) $entete['mots'];
		if ( 9 !== $entete['taille_entete'] || $entete['mots'] < 9 ) {
			return 'fichier WMF endommagé (en-tête incohérent)';
		}
		if ( $borne > $taille ) {
			return sprintf( 'fichier WMF tronqué (%1$d octets déclarés, %2$d présents)', $borne - $base, $taille - $base );
		}
		$meilleur = null;
		$refus    = '';
		$position = $base + 18;
		$n        = 0;
		while ( $position + 6 <= $borne ) {
			if ( ++$n > self::ENREGISTREMENTS_MAX ) {
				return 'fichier WMF trop complexe';
			}
			$rec    = unpack( 'Vmots/vfonction', self::lire( $flux, $position, 6 ) . str_repeat( "\0", 6 ) );
			$octets = 2 * (int) $rec['mots'];
			if ( $rec['mots'] < 3 || $position + $octets > $borne ) {
				return 'fichier WMF endommagé (taille d’enregistrement incohérente)';
			}
			if ( 0 === $rec['fonction'] ) {
				break; // META_EOF.
			}
			$decalage = self::WMF_BITMAPS[ $rec['fonction'] ] ?? 0;
			// Variante sans bitmap de META_DIBBITBLT / META_DIBSTRETCHBLT : taille (fonction >> 8) + 3.
			if ( $decalage && ( $rec['fonction'] >> 8 ) + 3 !== $rec['mots'] && $octets > $decalage + 40 ) {
				$longueur = $octets - $decalage;
				$usage    = 0x0F43 === $rec['fonction'] ? (int) unpack( 'v', self::lire( $flux, $position + 10, 2 ) . "\0\0" )[1] : 0;
				$bmi      = self::lire( $flux, $position + $decalage, min( $longueur, self::BMI_MAX ) );
				$bitmap   = self::dib( $bmi, $usage, $position + $decalage, $longueur, true );
				if ( is_string( $bitmap ) ) {
					$refus = '' === $refus ? $bitmap : $refus;
				} elseif ( is_array( $bitmap ) && ( null === $meilleur || $bitmap['largeur'] * $bitmap['hauteur'] > $meilleur['largeur'] * $meilleur['hauteur'] ) ) {
					$meilleur = $bitmap;
				}
			}
			$position += $octets;
		}
		return $meilleur ?? ( '' !== $refus ? $refus : 'dessin purement vectoriel, sans image bitmap à extraire' );
	}

	/**
	 * Analyse un DIB (BITMAPINFO puis pixels).
	 *
	 * @param string $bmi    En-tête (et suite, pour un DIB « compact » WMF).
	 * @param int    $usage  0 : palette RVB (DIB_RGB_COLORS) ; sinon palette indirecte.
	 * @param int    $bits   Position des pixels (EMF) ou du DIB compact (WMF) dans le fichier.
	 * @param int    $octets Taille des pixels (EMF) ou du DIB compact (WMF).
	 * @param bool   $compact DIB compact : les pixels suivent l'en-tête, la palette et les masques.
	 * @return array<string,mixed>|string Bitmap ou raison du refus.
	 */
	private static function dib( string $bmi, int $usage, int $bits, int $octets, bool $compact ) {
		if ( strlen( $bmi ) < 40 ) {
			return 'image intégrée endommagée (en-tête tronqué)';
		}
		$h = unpack( 'Vtaille/llargeur/lhauteur/vplans/vbpp/Vcompression/Vimage/lx/ly/Vcouleurs', $bmi );
		if ( 12 === $h['taille'] || $h['taille'] < 40 || $h['taille'] > strlen( $bmi ) ) {
			return 'image intégrée endommagée ou au format BITMAPCOREHEADER non pris en charge';
		}
		$largeur = (int) $h['largeur'];
		$hauteur = abs( (int) $h['hauteur'] );
		if ( $largeur <= 0 || 0 === $hauteur ) {
			return 'image intégrée endommagée (dimensions nulles ou négatives)';
		}
		if ( $largeur > self::COTE_MAX || $hauteur > self::COTE_MAX || $largeur * $hauteur > self::PIXELS_MAX || $largeur * $hauteur * 4 > self::MEMOIRE_MAX ) {
			return sprintf( 'image intégrée trop grande (%1$d × %2$d pixels)', $largeur, $hauteur );
		}
		$bpp  = (int) $h['bpp'];
		$comp = (int) $h['compression'];
		if ( in_array( $comp, array( 4, 5 ), true ) ) {
			// BI_JPEG / BI_PNG : flux d'image complet après l'en-tête.
			$debut = $compact ? $h['taille'] : 0;
			return self::flux_image( $bits + $debut, $octets - $debut, 4 === $comp ? 'image/jpeg' : 'image/png', $largeur, $hauteur );
		}
		if ( ! in_array( $bpp, array( 1, 4, 8, 16, 24, 32 ), true ) ) {
			return sprintf( 'profondeur de couleur de %d bits non prise en charge', $bpp );
		}
		if ( 0 !== $comp && 3 !== $comp ) {
			return in_array( $comp, array( 1, 2 ), true ) ? 'image intégrée compressée en RLE, non prise en charge' : sprintf( 'compression d’image %d non prise en charge', $comp );
		}
		if ( 3 === $comp && ! in_array( $bpp, array( 16, 32 ), true ) ) {
			return 'image intégrée endommagée (masques de couleur sur une profondeur incompatible)';
		}
		$position = (int) $h['taille'];
		$masques  = array();
		if ( 3 === $comp ) {
			// Masques juste après l'en-tête de 40 octets, ou à la même position dans un en-tête V2 à V5.
			if ( strlen( $bmi ) < 52 ) {
				return 'image intégrée endommagée (masques de couleur absents)';
			}
			$masques   = array_values( unpack( 'V3', $bmi, 40 ) );
			$position += 40 === $h['taille'] ? 12 : 0;
		}
		$couleurs = (int) $h['couleurs'];
		$palette  = '';
		if ( $bpp <= 8 ) {
			$couleurs = 0 === $couleurs ? 1 << $bpp : $couleurs;
			if ( $couleurs > 1 << $bpp ) {
				return 'image intégrée endommagée (palette trop grande)';
			}
			if ( 0 !== $usage ) {
				return 'image intégrée à palette indirecte, non prise en charge';
			}
			$palette = substr( $bmi, $position, 4 * $couleurs );
			if ( strlen( $palette ) !== 4 * $couleurs ) {
				return 'image intégrée endommagée (palette tronquée)';
			}
		} elseif ( $couleurs > 256 ) {
			return 'image intégrée endommagée (palette trop grande)';
		}
		$ligne   = intdiv( $largeur * $bpp + 31, 32 ) * 4;
		$attendu = $ligne * $hauteur;
		if ( $compact ) {
			$saut    = $position + 4 * $couleurs;
			$bits   += $saut;
			$octets -= $saut;
		}
		if ( $octets < $attendu ) {
			return sprintf( 'image intégrée tronquée (%1$d octets de pixels, %2$d attendus)', max( 0, $octets ), $attendu );
		}
		return array(
			'type'    => 'dib',
			'mime'    => 'image/png',
			'largeur' => $largeur,
			'hauteur' => $hauteur,
			'signe'   => (int) $h['hauteur'],
			'bpp'     => $bpp,
			'comp'    => $comp,
			'masques' => $masques,
			'palette' => $palette,
			'ligne'   => $ligne,
			'bits'    => $bits,
			'octets'  => $attendu,
		);
	}

	/**
	 * DIB BI_JPEG / BI_PNG : vérifie le flux incorporé.
	 *
	 * @param int    $bits    Position du flux.
	 * @param int    $octets  Taille du flux.
	 * @param string $mime    Type attendu.
	 * @param int    $largeur Largeur déclarée.
	 * @param int    $hauteur Hauteur déclarée.
	 * @return array<string,mixed>|string
	 */
	private static function flux_image( int $bits, int $octets, string $mime, int $largeur, int $hauteur ) {
		if ( $octets <= 8 || $octets > self::TAILLE_MAX ) {
			return 'image intégrée JPEG/PNG vide ou démesurée';
		}
		return array(
			'type'    => 'flux',
			'mime'    => $mime,
			'largeur' => $largeur,
			'hauteur' => $hauteur,
			'bits'    => $bits,
			'octets'  => $octets,
		);
	}

	/**
	 * Écrit l'image extraite.
	 *
	 * @param resource            $flux        Métafichier.
	 * @param array<string,mixed> $b           Bitmap localisé.
	 * @param string              $destination Fichier écrit.
	 * @return string|null Raison de l'échec, ou null.
	 */
	private static function ecrire( $flux, array $b, string $destination ): ?string {
		if ( 'flux' === $b['type'] ) {
			return self::ecrire_flux( $flux, $b, $destination );
		}
		if ( ! function_exists( 'imagecreatefrombmp' ) || ! function_exists( 'imagepng' ) ) {
			return 'bibliothèque d’images GD (avec BMP) absente du serveur';
		}
		$pixels = (int) $b['largeur'] * (int) $b['hauteur'];
		$limite = self::octets( (string) ini_get( 'memory_limit' ) );
		if ( $limite > 0 && memory_get_usage( true ) + $pixels * 5 + 8 * 1024 * 1024 > $limite ) {
			return sprintf( 'mémoire du serveur insuffisante pour décoder %1$d × %2$d pixels', $b['largeur'], $b['hauteur'] );
		}
		$bpp     = (int) $b['bpp'];
		$comp    = (int) $b['comp'];
		$masques = $b['masques'];
		$lent    = false;
		if ( 3 === $comp ) {
			if ( ( 32 === $bpp && array( 0xFF0000, 0xFF00, 0xFF ) === $masques ) || ( 16 === $bpp && array( 0x7C00, 0x3E0, 0x1F ) === $masques ) ) {
				$comp = 0; // Masques standard : identiques à BI_RGB.
			} elseif ( $pixels > self::LENT_MAX ) {
				return 'image intégrée à masques de couleur non standard trop grande';
			} else {
				$lent = true;
			}
		}
		$bmp = self::temporaire();
		if ( '' === $bmp ) {
			return 'dossier temporaire inaccessible';
		}
		try {
			$sortie_bpp = $lent ? 24 : $bpp;
			$ligne      = $lent ? intdiv( (int) $b['largeur'] * 24 + 31, 32 ) * 4 : (int) $b['ligne'];
			$donnees    = $ligne * (int) $b['hauteur'];
			$entete     = pack( 'VllvvVVllVV', 40, (int) $b['largeur'], (int) $b['signe'], 1, $sortie_bpp, 0, $donnees, 2835, 2835, intdiv( strlen( (string) $b['palette'] ), 4 ), 0 ) . $b['palette'];
			$cible      = fopen( $bmp, 'wb' );
			if ( false === $cible ) {
				return 'dossier temporaire inaccessible';
			}
			fwrite( $cible, 'BM' . pack( 'VvvV', 14 + strlen( $entete ) + $donnees, 0, 0, 14 + strlen( $entete ) ) . $entete );
			fseek( $flux, (int) $b['bits'] );
			if ( $lent ) {
				$ok = self::convertir_masques( $flux, $cible, $b, $ligne );
			} else {
				$ok = stream_copy_to_stream( $flux, $cible, (int) $b['octets'] ) === (int) $b['octets'];
			}
			fclose( $cible );
			if ( ! $ok ) {
				return 'image intégrée tronquée';
			}
			$image = @imagecreatefrombmp( $bmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $image ) {
				return 'image intégrée illisible';
			}
			$ok = imagepng( $image, $destination );
			unset( $image );
			return $ok ? null : 'écriture de l’image convertie impossible';
		} finally {
			if ( is_file( $bmp ) ) {
				@unlink( $bmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * Copie un flux JPEG/PNG incorporé et vérifie son type réel.
	 *
	 * @param resource            $flux        Métafichier.
	 * @param array<string,mixed> $b           Bitmap localisé.
	 * @param string              $destination Fichier écrit.
	 */
	private static function ecrire_flux( $flux, array $b, string $destination ): ?string {
		$cible = fopen( $destination, 'wb' );
		if ( false === $cible ) {
			return 'écriture de l’image impossible';
		}
		fseek( $flux, (int) $b['bits'] );
		$copie = stream_copy_to_stream( $flux, $cible, (int) $b['octets'] );
		fclose( $cible );
		if ( $copie !== (int) $b['octets'] ) {
			return 'image intégrée tronquée';
		}
		$infos = @getimagesize( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $infos ) || ( $infos['mime'] ?? '' ) !== $b['mime'] ) {
			return 'image JPEG/PNG intégrée illisible';
		}
		return null;
	}

	/**
	 * Convertit en BGR 24 bits des pixels à masques de couleur non standard (ligne par ligne).
	 *
	 * @param resource            $flux  Source (positionnée sur les pixels).
	 * @param resource            $cible BMP en cours d'écriture.
	 * @param array<string,mixed> $b     Bitmap localisé.
	 * @param int                 $ligne Longueur d'une ligne de sortie (octets, alignée sur 4).
	 */
	private static function convertir_masques( $flux, $cible, array $b, int $ligne ): bool {
		$canaux = array();
		foreach ( (array) $b['masques'] as $masque ) {
			$masque   = (int) $masque;
			$decalage = 0;
			while ( $masque && 0 === ( ( $masque >> $decalage ) & 1 ) ) {
				++$decalage;
			}
			$canaux[] = array( $masque, $decalage, $masque ? $masque >> $decalage : 0 );
		}
		$largeur = (int) $b['largeur'];
		$format  = 16 === (int) $b['bpp'] ? 'v' : 'V';
		$utile   = $largeur * ( 16 === (int) $b['bpp'] ? 2 : 4 );
		for ( $y = 0; $y < (int) $b['hauteur']; $y++ ) {
			$source = (string) fread( $flux, (int) $b['ligne'] );
			if ( strlen( $source ) !== (int) $b['ligne'] ) {
				return false;
			}
			$sortie = '';
			foreach ( unpack( $format . '*', substr( $source, 0, $utile ) ) as $px ) {
				$rvb = array();
				foreach ( $canaux as $c ) {
					$rvb[] = $c[2] ? intdiv( ( ( $px & $c[0] ) >> $c[1] ) * 255, $c[2] ) : 0;
				}
				$sortie .= chr( $rvb[2] ) . chr( $rvb[1] ) . chr( $rvb[0] );
			}
			fwrite( $cible, str_pad( $sortie, $ligne, "\0" ) );
		}
		return true;
	}

	/**
	 * Valeur de php.ini en octets (« 256M » → 268435456 ; -1 : illimité → 0).
	 *
	 * @param string $valeur Valeur.
	 */
	private static function octets( string $valeur ): int {
		$valeur = trim( $valeur );
		if ( '' === $valeur || '-1' === $valeur ) {
			return 0;
		}
		$n = (int) $valeur;
		switch ( strtolower( substr( $valeur, -1 ) ) ) {
			case 'g':
				$n *= 1024;
				// Continue.
			case 'm':
				$n *= 1024;
				// Continue.
			case 'k':
				$n *= 1024;
		}
		return $n;
	}
}
