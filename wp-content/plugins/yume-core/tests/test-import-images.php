<?php
/**
 * Images Word EMF/WMF/EMZ (Metafichier) : métafichiers SYNTHÉTIQUES fabriqués ici (petits
 * bitmaps 4 × 3 en 1, 8, 16, 24 et 32 bits, BI_BITFIELDS, BI_PNG, WMF, EMZ), intégrés dans des
 * DOCX fabriqués avec ZipArchive :
 * - conversion en PNG à l'extraction (Result::copier_image(), point d'entrée des images du
 *   module « publication »), orientation et couleurs vérifiées pixel par pixel ;
 * - position : avant le premier chapitre → galerie (front_images), dans un chapitre → à sa
 *   place, comme une image PNG ;
 * - refus motivé (avertissement précis) des EMF vectoriels, tronqués, aux tailles
 *   incohérentes ou démesurées ; aucun fichier temporaire laissé, mémoire stable.
 *
 * Lancement : tools/localenv/test.sh import-images
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Metafichier;
use Yume\Core\Import\Result;
use Yume\Core\Import\Zip;
use Yume\Core\Publication\Medias;

defined( 'ABSPATH' ) || exit;

require_once YUME_CORE_DIR . 'includes/import/autoload.php';

if ( ! function_exists( 'yume_timg_dib' ) ) {

	/**
	 * Palette de l'image de test : rouge, vert, bleu, blanc (RVB 0xRRGGBB).
	 *
	 * @return int[]
	 */
	function yume_timg_couleurs(): array {
		return array( 0xFF0000, 0x00FF00, 0x0000FF, 0xFFFFFF );
	}

	/**
	 * Couleur attendue du pixel (x, y) de l'image de test 4 × 3 (y = 0 en haut) : rouge en haut
	 * à gauche, vert en haut à droite, bleu en bas à gauche, blanc ailleurs.
	 *
	 * @param int $x Colonne.
	 * @param int $y Ligne.
	 */
	function yume_timg_pixel( int $x, int $y ): int {
		if ( 0 === $y && 0 === $x ) {
			return 0xFF0000;
		}
		if ( 0 === $y && 3 === $x ) {
			return 0x00FF00;
		}
		return 2 === $y && 0 === $x ? 0x0000FF : 0xFFFFFF;
	}

	/**
	 * DIB 4 × 3 : [BITMAPINFO, pixels].
	 *
	 * @param int  $bpp       Bits par pixel (1, 8, 16, 24, 32).
	 * @param bool $haut_bas  Lignes de haut en bas (hauteur négative).
	 * @param int  $comp      Compression (0 BI_RGB, 3 BI_BITFIELDS).
	 * @param bool $masque565 Pour 16 bits BI_BITFIELDS : masques 5-6-5 (non standard).
	 * @return array{0:string,1:string}
	 */
	function yume_timg_dib( int $bpp, bool $haut_bas = false, int $comp = 0, bool $masque565 = false ): array {
		$palette = '';
		$masques = '';
		if ( $bpp <= 8 ) {
			foreach ( yume_timg_couleurs() as $c ) {
				$palette .= pack( 'CCCC', $c & 0xFF, ( $c >> 8 ) & 0xFF, ( $c >> 16 ) & 0xFF, 0 );
			}
		}
		if ( 3 === $comp ) {
			$masques = 16 === $bpp ? ( $masque565 ? pack( 'VVV', 0xF800, 0x07E0, 0x001F ) : pack( 'VVV', 0x7C00, 0x03E0, 0x001F ) ) : pack( 'VVV', 0xFF0000, 0xFF00, 0xFF );
		}
		$ligne = intdiv( 4 * $bpp + 31, 32 ) * 4;
		$bits  = '';
		for ( $r = 0; $r < 3; $r++ ) {
			$y      = $haut_bas ? $r : 2 - $r;
			$octets = '';
			$acc    = 0;
			for ( $x = 0; $x < 4; $x++ ) {
				$c   = yume_timg_pixel( $x, $y );
				$idx = (int) array_search( $c, yume_timg_couleurs(), true );
				$rr  = ( $c >> 16 ) & 0xFF;
				$vv  = ( $c >> 8 ) & 0xFF;
				$bb  = $c & 0xFF;
				switch ( $bpp ) {
					case 1:
						// Palette à 2 entrées : blanc (1) ou autre (0) ; testé sur la seule ligne du haut.
						$acc |= ( 0xFFFFFF === $c ? 1 : 0 ) << ( 7 - $x );
						break;
					case 8:
						$octets .= chr( $idx );
						break;
					case 16:
						$octets .= pack( 'v', $masque565 ? ( ( $rr >> 3 ) << 11 ) | ( ( $vv >> 2 ) << 5 ) | ( $bb >> 3 ) : ( ( $rr >> 3 ) << 10 ) | ( ( $vv >> 3 ) << 5 ) | ( $bb >> 3 ) );
						break;
					case 24:
						$octets .= chr( $bb ) . chr( $vv ) . chr( $rr );
						break;
					case 32:
						$octets .= chr( $bb ) . chr( $vv ) . chr( $rr ) . "\0";
						break;
				}
			}
			if ( 1 === $bpp ) {
				$octets = chr( $acc );
			}
			$bits .= str_pad( $octets, $ligne, "\0" );
		}
		if ( 1 === $bpp ) {
			$palette = pack( 'CCCC', 0, 0, 0, 0 ) . pack( 'CCCC', 255, 255, 255, 0 );
		}
		$couleurs = (int) ( strlen( $palette ) / 4 );
		$entete   = pack( 'VllvvVVllVV', 40, 4, $haut_bas ? -3 : 3, 1, $bpp, $comp, strlen( $bits ), 2835, 2835, $couleurs, 0 );
		return array( $entete . $masques . $palette, $bits );
	}

	/**
	 * EMF : EMR_HEADER, enregistrements donnés, EMR_EOF.
	 *
	 * @param string[] $enregistrements Enregistrements.
	 * @param int|null $n_octets        Taille déclarée (défaut : taille réelle).
	 */
	function yume_timg_emf( array $enregistrements, ?int $n_octets = null ): string {
		$corps  = implode( '', $enregistrements ) . pack( 'VVVVV', 14, 20, 0, 16, 20 );
		$taille = 88 + strlen( $corps );
		$entete = pack( 'VV', 1, 88 ) . pack( 'l4', 0, 0, 3, 2 ) . pack( 'l4', 0, 0, 100, 75 )
			. pack( 'VVVVvvVVV', 0x464D4520, 0x10000, $n_octets ?? $taille, count( $enregistrements ) + 2, 1, 0, 0, 0, 0 )
			. pack( 'l4', 1920, 1080, 508, 286 );
		return $entete . $corps;
	}

	/**
	 * EMR_STRETCHDIBITS (81) portant un DIB.
	 *
	 * @param array{0:string,1:string} $dib     BITMAPINFO et pixels.
	 * @param int|null                 $cb_bits Taille des pixels déclarée (défaut : réelle).
	 */
	function yume_timg_stretchdibits( array $dib, ?int $cb_bits = null ): string {
		list( $bmi, $bits ) = $dib;
		$bits               = str_pad( $bits, (int) ( ceil( strlen( $bits ) / 4 ) * 4 ), "\0" );
		$taille             = 80 + strlen( $bmi ) + strlen( $bits );
		return pack( 'VV', 81, $taille ) . pack( 'l4', 0, 0, 3, 2 ) . pack( 'l6', 0, 0, 0, 0, 4, 3 )
			. pack( 'VVVV', 80, strlen( $bmi ), 80 + strlen( $bmi ), $cb_bits ?? strlen( $bits ) )
			. pack( 'VVll', 0, 0x00CC0020, 4, 3 ) . $bmi . $bits;
	}

	/**
	 * EMR_BITBLT (76) portant un DIB.
	 *
	 * @param array{0:string,1:string} $dib BITMAPINFO et pixels.
	 */
	function yume_timg_bitblt( array $dib ): string {
		list( $bmi, $bits ) = $dib;
		$taille             = 100 + strlen( $bmi ) + strlen( $bits );
		return pack( 'VV', 76, $taille ) . pack( 'l4', 0, 0, 3, 2 ) . pack( 'l4', 0, 0, 4, 3 ) . pack( 'V', 0x00CC0020 )
			. pack( 'll', 0, 0 ) . str_repeat( "\0", 24 ) . pack( 'VV', 0, 0 )
			. pack( 'VVVV', 100, strlen( $bmi ), 100 + strlen( $bmi ), strlen( $bits ) ) . $bmi . $bits;
	}

	/**
	 * WMF (en-tête « placeable » + META_HEADER + META_STRETCHDIB + META_EOF).
	 *
	 * @param array{0:string,1:string} $dib BITMAPINFO et pixels.
	 */
	function yume_timg_wmf( array $dib ): string {
		$dib    = $dib[0] . $dib[1];
		$dib   .= strlen( $dib ) % 2 ? "\0" : '';
		$record = pack( 'Vv', ( 28 + strlen( $dib ) ) / 2, 0x0F43 ) . pack( 'Vv', 0x00CC0020, 0 ) . pack( 'v8', 3, 4, 0, 0, 3, 4, 0, 0 ) . $dib;
		$eof    = pack( 'Vv', 3, 0 );
		$mots   = ( 18 + strlen( $record ) + strlen( $eof ) ) / 2;
		$entete = pack( 'vvvVvVv', 1, 9, 0x0300, $mots, 0, strlen( $record ) / 2, 0 );
		$place  = pack( 'VvssssvVv', 0x9AC6CDD7, 0, 0, 0, 4, 3, 96, 0, 0 );
		return $place . $entete . $record . $eof;
	}

	/**
	 * Paragraphe WordprocessingML.
	 *
	 * @param string $texte Texte.
	 * @param string $style Style (Heading1 : niveau hiérarchique 1, le document n'a pas de styles.xml).
	 */
	function yume_timg_p( string $texte, string $style = '' ): string {
		$ppr = '' !== $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/><w:outlineLvl w:val="0"/></w:pPr>' : '';
		return '<w:p>' . $ppr . '<w:r><w:t xml:space="preserve">' . $texte . '</w:t></w:r></w:p>';
	}

	/**
	 * Paragraphe contenant une image (w:drawing).
	 *
	 * @param string $rid Relation.
	 */
	function yume_timg_image( string $rid ): string {
		return '<w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="Image" descr="Dessin ' . $rid . '"/>'
			. '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
			. '<pic:blipFill><a:blip r:embed="' . $rid . '"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
	}

	/**
	 * DOCX : médias (nom → contenu) et corps (paragraphes) ; relation « rId<nom> » par média.
	 *
	 * @param array<string,string> $medias Fichiers de word/media.
	 * @param string               $corps  Contenu de w:body.
	 */
	function yume_timg_docx( array $medias, string $corps ): string {
		$rels = '';
		foreach ( array_keys( $medias ) as $nom ) {
			$rels .= '<Relationship Id="r' . md5( $nom ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $nom . '"/>';
		}
		$ns      = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
		$parties = array(
			'[Content_Types].xml'          => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="emf" ContentType="image/x-emf"/><Default Extension="wmf" ContentType="image/x-wmf"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
			'_rels/.rels'                  => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
			'word/document.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $corps . '</w:body></w:document>',
			'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>',
		);
		foreach ( $medias as $nom => $contenu ) {
			$parties[ 'word/media/' . $nom ] = $contenu;
		}
		$chemin = wp_tempnam( 'yume-emf-test' ) . '.docx';
		$zip    = new ZipArchive();
		$zip->open( $chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $parties as $nom => $contenu ) {
			$zip->addFromString( $nom, $contenu );
		}
		$zip->close();
		$GLOBALS['yume_timg_fichiers'][] = $chemin;
		return $chemin;
	}

	/**
	 * Relation d'un média de yume_timg_docx().
	 *
	 * @param string $nom Nom du fichier.
	 */
	function yume_timg_rid( string $nom ): string {
		return 'r' . md5( $nom );
	}

	/**
	 * Convertit une image du Result et vérifie ses pixels (image 4 × 3 de yume_timg_pixel()).
	 *
	 * @param Result $r      Résultat.
	 * @param string $cle    Clé.
	 * @param string $cas    Libellé.
	 * @param bool   $ligne0 Ne vérifier que la ligne du haut en noir et blanc (1 bit).
	 */
	function yume_timg_verifier_pixels( Result $r, string $cle, string $cas, bool $ligne0 = false ): void {
		$sortie = wp_tempnam( 'yume-emf-sortie' );
		yume_assert_true( $r->copier_image( $cle, $sortie ), $cas . ' : conversion' );
		$infos = getimagesize( $sortie );
		yume_assert_same( array( 4, 3, 'image/png' ), array( $infos[0], $infos[1], $infos['mime'] ), $cas . ' : PNG 4 × 3' );
		$image = imagecreatefrompng( $sortie );
		wp_delete_file( $sortie );
		for ( $y = 0; $y < ( $ligne0 ? 1 : 3 ); $y++ ) {
			for ( $x = 0; $x < 4; $x++ ) {
				$c = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
				$v = ( $c['red'] << 16 ) | ( $c['green'] << 8 ) | $c['blue'];
				$a = yume_timg_pixel( $x, $y );
				if ( $ligne0 ) {
					$a = 0xFFFFFF === $a ? 0xFFFFFF : 0;
				}
				// 16 bits : composantes sur 5 ou 6 bits (0xF8 pour 0xFF).
				$proche = abs( ( $v >> 16 ) - ( $a >> 16 ) ) <= 8 && abs( ( ( $v >> 8 ) & 0xFF ) - ( ( $a >> 8 ) & 0xFF ) ) <= 8 && abs( ( $v & 0xFF ) - ( $a & 0xFF ) ) <= 8;
				yume_assert_true( $proche, sprintf( '%s : pixel (%d, %d) = %06X, attendu %06X', $cas, $x, $y, $v, $a ) );
			}
		}
	}

	/**
	 * Fichiers temporaires du convertisseur (yume-emf*) présents dans le dossier temporaire.
	 */
	function yume_timg_temporaires(): array {
		$liste = glob( trailingslashit( get_temp_dir() ) . 'yume-emf*' );
		$liste = false === $liste ? array() : $liste;
		return array_values( array_filter( $liste, static fn( $f ) => ! in_array( $f, (array) ( $GLOBALS['yume_timg_fichiers'] ?? array() ), true ) && ! str_contains( $f, 'yume-emf-test' ) && ! str_contains( $f, 'yume-emf-sortie' ) ) );
	}

	/**
	 * Supprime les DOCX fabriqués.
	 */
	function yume_timg_nettoyer(): void {
		foreach ( (array) ( $GLOBALS['yume_timg_fichiers'] ?? array() ) as $f ) {
			foreach ( array( $f, (string) preg_replace( '/\.docx$/', '', $f ) ) as $g ) {
				if ( is_file( $g ) ) {
					wp_delete_file( $g );
				}
			}
		}
		$GLOBALS['yume_timg_fichiers'] = array();
	}
}

yume_test(
	'emf : bitmaps 1, 8, 16, 24 et 32 bits, BI_BITFIELDS, BITBLT, WMF et EMZ convertis en PNG (orientation, couleurs)',
	function () {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
			yume_assert_true( true );
			return; // GD sans BMP : conversion impossible, couverte par le test de refus.
		}
		$cas   = array(
			'rgb24.emf'     => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 24 ) ) ) ),
			'rgb24-hb.emf'  => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 24, true ) ) ) ),
			'rgb32.emf'     => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 32 ) ) ) ),
			'champs32.emf'  => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 32, true, 3 ) ) ) ),
			'champs16.emf'  => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 16, false, 3 ) ) ) ),
			'champs565.emf' => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 16, false, 3, true ) ) ) ),
			'rgb16.emf'     => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 16 ) ) ) ),
			'palette8.emf'  => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 8 ) ) ) ),
			'bitblt.emf'    => yume_timg_emf( array( yume_timg_bitblt( yume_timg_dib( 24 ) ) ) ),
			'dessin.wmf'    => yume_timg_wmf( yume_timg_dib( 24 ) ),
			'compresse.emz' => (string) gzencode( yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 32 ) ) ) ) ),
			'mono.emf'      => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 1 ) ) ) ),
		);
		$corps = yume_timg_p( 'Chapitre 1 : Les dessins', 'Heading1' );
		foreach ( array_keys( $cas ) as $nom ) {
			$corps .= yume_timg_image( yume_timg_rid( $nom ) );
		}
		$r = Docx_Converter::convert_file( yume_timg_docx( $cas, $corps ) );
		yume_assert_same( count( $cas ), $r->stats['images_emf_converties'] );
		yume_assert_same( 0, $r->stats['images_emf'] );
		yume_assert_not_contains( 'EMF', implode( "\n", $r->warnings ) );
		foreach ( array_keys( $cas ) as $nom ) {
			$cle = (string) pathinfo( $nom, PATHINFO_FILENAME );
			yume_assert_true( isset( $r->images[ $cle ] ), $nom . ' gardée' );
			yume_assert_same( array( 'image/png', 4, 3, 'metafichier' ), array( $r->images[ $cle ]['mime'], $r->images[ $cle ]['largeur'], $r->images[ $cle ]['hauteur'], $r->images[ $cle ]['conversion'] ), $nom );
			yume_timg_verifier_pixels( $r, $cle, $nom, 'mono.emf' === $nom );
		}
		yume_timg_nettoyer();
	}
);

yume_test(
	'emf : position (galerie avant le premier chapitre, à sa place dans un chapitre), plus grand bitmap retenu, BI_PNG extrait tel quel',
	function () {
		$petit  = yume_timg_stretchdibits( yume_timg_dib( 24 ) );
		$grand  = pack( 'VllvvVVllVV', 40, 40, 30, 1, 24, 0, 0, 2835, 2835, 0, 0 );
		$grand  = yume_timg_stretchdibits( array( $grand, str_repeat( "\xFF", 120 * 30 ) ) );
		$png    = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image de test.
		$bi_png = yume_timg_stretchdibits( array( pack( 'VllvvVVllVV', 40, 1, 1, 1, 0, 5, strlen( $png ), 0, 0, 0, 0 ), $png ), strlen( $png ) );
		$medias = array(
			'couverture.emf' => yume_timg_emf( array( $petit ) ),
			'double.emf'     => yume_timg_emf( array( $petit, $grand, $petit ) ),
			'flux.emf'       => yume_timg_emf( array( $bi_png ) ),
		);
		$corps  = yume_timg_image( yume_timg_rid( 'couverture.emf' ) )
			. yume_timg_p( 'Chapitre 1 : Le début', 'Heading1' )
			. yume_timg_p( 'Avant le dessin.' )
			. yume_timg_image( yume_timg_rid( 'double.emf' ) )
			. yume_timg_p( 'Après le dessin.' )
			. yume_timg_image( yume_timg_rid( 'flux.emf' ) );
		$r      = Docx_Converter::convert_file( yume_timg_docx( $medias, $corps ) );
		yume_assert_same( array( 'couverture' ), $r->front_images, 'Galerie Illustrations' );
		$c = $r->chapters[0];
		yume_assert_same( array( 'double', 'flux' ), $c['images'] );
		yume_assert_true( strpos( $c['blocks'], 'Avant le dessin.' ) < strpos( $c['blocks'], '{{yume-image:double}}' ) && strpos( $c['blocks'], '{{yume-image:double}}' ) < strpos( $c['blocks'], 'Après le dessin.' ), 'Image à sa place' );
		yume_assert_contains( '<img src="{{yume-image:double}}" alt="Dessin ' . yume_timg_rid( 'double.emf' ) . '"/>', $c['blocks'] );
		yume_assert_same( array( 40, 30 ), array( $r->images['double']['largeur'], $r->images['double']['hauteur'] ), 'Le plus grand bitmap' );
		yume_assert_same( array( 'image/png', 1, 1 ), array( $r->images['flux']['mime'], $r->images['flux']['largeur'], $r->images['flux']['hauteur'] ) );
		$sortie = wp_tempnam( 'yume-emf-sortie' );
		yume_assert_true( $r->copier_image( 'flux', $sortie ) );
		yume_assert_same( $png, (string) file_get_contents( $sortie ), 'BI_PNG : flux extrait tel quel' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $sortie );
		yume_timg_nettoyer();
	}
);

yume_test(
	'emf : vectoriel, tronqué, tailles incohérentes, démesuré ou endommagé → ignoré avec un avertissement précis, sans fichier temporaire',
	function () {
		$avant   = yume_timg_temporaires();
		$dib     = yume_timg_dib( 24 );
		$ligne   = pack( 'VVll', 54, 16, 10, 10 ); // EMR_LINETO.
		$enorme  = yume_timg_stretchdibits( array( pack( 'VllvvVVllVV', 40, 20000, 20000, 1, 32, 0, 0, 0, 0, 0, 0 ), str_repeat( "\0", 64 ) ) );
		$tronque = yume_timg_emf( array( yume_timg_stretchdibits( $dib ) ) );
		$hors    = yume_timg_stretchdibits( $dib, 1 << 30 );
		$court   = yume_timg_stretchdibits( $dib, 8 );
		$mauvais = pack( 'VV', 81, 13 ) . str_repeat( "\0", 5 );
		$rle     = yume_timg_stretchdibits( array( pack( 'VllvvVVllVV', 40, 4, 3, 1, 8, 1, 0, 0, 0, 0, 0 ), str_repeat( "\0", 12 ) ) );
		$medias  = array(
			'vectoriel.emf' => yume_timg_emf( array( $ligne ) ),
			'tronque.emf'   => substr( $tronque, 0, (int) ( strlen( $tronque ) / 2 ) ),
			'declare.emf'   => yume_timg_emf( array( yume_timg_stretchdibits( $dib ) ), 1 << 20 ),
			'hors.emf'      => yume_timg_emf( array( $hors ) ),
			'court.emf'     => yume_timg_emf( array( $court ) ),
			'enorme.emf'    => yume_timg_emf( array( $enorme ) ),
			'record.emf'    => yume_timg_emf( array( $mauvais ) ),
			'rle.emf'       => yume_timg_emf( array( $rle ) ),
			'bruit.emf'     => str_repeat( "\xAB", 300 ),
			'gzip.emz'      => substr( (string) gzencode( yume_timg_emf( array( yume_timg_stretchdibits( $dib ) ) ) ), 0, 40 ),
		);
		$corps   = yume_timg_image( yume_timg_rid( 'vectoriel.emf' ) ) . yume_timg_p( 'Chapitre 1 : Les pièges', 'Heading1' );
		foreach ( array_keys( $medias ) as $nom ) {
			if ( 'vectoriel.emf' !== $nom ) {
				$corps .= yume_timg_image( yume_timg_rid( $nom ) );
			}
		}
		$r = Docx_Converter::convert_file( yume_timg_docx( $medias, $corps ) );
		yume_assert_same( array(), $r->images );
		yume_assert_same( array(), $r->front_images );
		yume_assert_same( count( $medias ), $r->stats['images_emf'] );
		yume_assert_same( 0, $r->stats['images_emf_converties'] );
		$avert = implode( "\n", $r->warnings );
		yume_assert_contains( count( $medias ) . ' images au format Word EMF/WMF ignorées (aucune image convertible)', $avert );
		yume_assert_contains( 'vectoriel.emf (avant le premier chapitre : dessin purement vectoriel, sans image bitmap à extraire)', $avert );
		yume_assert_contains( 'tronque.emf (Chapitre 1 : fichier EMF tronqué', $avert );
		yume_assert_contains( 'declare.emf (Chapitre 1 : fichier EMF tronqué (1048576 octets déclarés', $avert );
		yume_assert_contains( 'hors.emf (Chapitre 1 : fichier EMF endommagé (image hors de son enregistrement))', $avert );
		yume_assert_contains( 'court.emf (Chapitre 1 : image intégrée tronquée (8 octets de pixels, 36 attendus))', $avert );
		yume_assert_contains( 'enorme.emf (Chapitre 1 : image intégrée trop grande (20000 × 20000 pixels))', $avert );
		yume_assert_contains( 'record.emf (Chapitre 1 : fichier EMF endommagé (taille d’enregistrement incohérente))', $avert );
		yume_assert_contains( 'rle.emf (Chapitre 1 : image intégrée compressée en RLE, non prise en charge)', $avert );
		yume_assert_contains( 'bruit.emf (Chapitre 1 : fichier endommagé ou format non reconnu (ni EMF ni WMF))', $avert );
		yume_assert_contains( 'gzip.emz (Chapitre 1 : compression EMZ/WMZ illisible', $avert );
		yume_assert_contains( 'Enregistrer en tant qu’image', $avert );
		yume_assert_not_contains( 'Exportez-les', $avert );
		// Le chapitre reste propre : ni image ni ligne vide pour les dessins ignorés.
		yume_assert_not_contains( 'yume-image', $r->chapters[0]['blocks'] );
		yume_assert_same( $avant, yume_timg_temporaires(), 'Aucun fichier temporaire laissé' );
		yume_timg_nettoyer();
	}
);

yume_test(
	'emf : conversions répétées sans fuite de mémoire ni de fichier temporaire',
	function () {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
			yume_assert_true( true );
			return;
		}
		$chemin = yume_timg_docx(
			array( 'dessin.emf' => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 32 ) ) ) ) ),
			yume_timg_p( 'Chapitre 1 : Boucle', 'Heading1' ) . yume_timg_image( yume_timg_rid( 'dessin.emf' ) )
		);
		$avant  = yume_timg_temporaires();
		$sortie = wp_tempnam( 'yume-emf-sortie' );
		$zip    = Zip::ouvrir( $chemin, 'DOCX' );
		Metafichier::convertir( $zip, 'word/media/dessin.emf', $sortie );
		gc_collect_cycles();
		$memoire = memory_get_usage();
		for ( $i = 0; $i < 30; $i++ ) {
			$infos = Metafichier::convertir( $zip, 'word/media/dessin.emf', $sortie );
			yume_assert_same( 'image/png', $infos['mime'] ?? '' );
		}
		$zip->fermer();
		gc_collect_cycles();
		yume_assert_true( memory_get_usage() - $memoire < 64 * 1024, 'Mémoire stable : ' . ( memory_get_usage() - $memoire ) . ' octets' );
		wp_delete_file( $sortie );
		yume_assert_same( $avant, yume_timg_temporaires(), 'Aucun fichier temporaire laissé' );
		yume_timg_nettoyer();
	}
);

yume_test(
	'emf : versé dans la médiathèque par le même point d’entrée qu’une image PNG (Medias::importer)',
	function () {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
			yume_assert_true( true );
			return;
		}
		wp_set_current_user( yume_factory_user( 'administrator' ) );
		$r  = Docx_Converter::convert_file(
			yume_timg_docx(
				array( 'ornement.emf' => yume_timg_emf( array( yume_timg_stretchdibits( yume_timg_dib( 24 ) ) ) ) ),
				yume_timg_image( yume_timg_rid( 'ornement.emf' ) ) . yume_timg_p( 'Chapitre 1 : Médias', 'Heading1' ) . yume_timg_p( 'Texte.' )
			)
		);
		$id = Medias::importer( $r, 'ornement', 0, 0, 'Ornement', 'Ornement' );
		yume_assert_false( is_wp_error( $id ), is_wp_error( $id ) ? $id->get_error_message() : '' );
		$fichier = (string) get_attached_file( (int) $id );
		yume_assert_true( is_file( $fichier ), 'Fichier versé' );
		yume_assert_true( in_array( get_post_mime_type( (int) $id ), array( 'image/png', 'image/webp' ), true ), 'Type : ' . get_post_mime_type( (int) $id ) );
		yume_assert_true( str_starts_with( basename( $fichier ), 'ornement' ), basename( $fichier ) );
		$taille = getimagesize( $fichier );
		yume_assert_same( array( 4, 3 ), array( $taille[0], $taille[1] ) );
		wp_delete_attachment( (int) $id, true );
		yume_timg_nettoyer();
	}
);
