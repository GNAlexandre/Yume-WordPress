<?php
/**
 * DOCX malveillants (audit AMEL-14, lot P2-E) : des documents piégés, fabriqués ici avec
 * ZipArchive, doivent être refusés ou neutralisés par l'import (Docx_Converter et service de
 * publication) sans erreur fatale, sans fichier écrit hors du dossier prévu et sans requête
 * réseau.
 *
 * Cas : entité externe (XXE) dans document.xml et les parties annexes, y compris quand la
 * déclaration DOCTYPE est repoussée au-delà des premiers octets ou encodée en UTF-16,
 * expansion d'entités (« billion laughs »), bombe de décompression, chemins « ../ » dans
 * l'archive (zip slip), image SVG avec script, HTML (<script>, onerror) dans le texte,
 * relations externes (TargetMode="External") vers file:// ou http.
 *
 * Lancement : tools/localenv/test.sh securite-import
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Epub_Converter;
use Yume\Core\Import\Import_Exception;
use Yume\Core\Import\Result;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

require_once YUME_CORE_DIR . 'includes/import/autoload.php';

if ( ! function_exists( 'yume_tsimp_docx' ) ) {

	/**
	 * Espaces de noms WordprocessingML utilisés dans les parties fabriquées.
	 */
	function yume_tsimp_ns(): string {
		return 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
			. 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
			. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
			. 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
	}

	/**
	 * Paragraphe WordprocessingML (texte déjà échappé pour XML, ou entités volontaires).
	 *
	 * @param string $texte Contenu XML du w:t.
	 * @param string $style Style de paragraphe (Heading1…).
	 */
	function yume_tsimp_p( string $texte, string $style = '' ): string {
		$ppr = '' !== $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
		return '<w:p>' . $ppr . '<w:r><w:t xml:space="preserve">' . $texte . '</w:t></w:r></w:p>';
	}

	/**
	 * Image incorporée (w:drawing) référencée par une relation.
	 *
	 * @param string $rid     Identifiant de relation.
	 * @param string $attribut « embed » (incorporée) ou « link » (liée).
	 */
	function yume_tsimp_image( string $rid, string $attribut = 'embed' ): string {
		return '<w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="Image" descr="Illustration"/>'
			. '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
			. '<pic:blipFill><a:blip r:' . $attribut . '="' . $rid . '"/></pic:blipFill>'
			. '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
	}

	/**
	 * Document principal : premier chapitre (titre) puis le corps donné.
	 *
	 * @param string $corps   Paragraphes.
	 * @param string $prefixe Contenu inséré entre la déclaration XML et l'élément racine (DOCTYPE…).
	 */
	function yume_tsimp_document( string $corps, string $prefixe = '' ): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $prefixe
			. '<w:document ' . yume_tsimp_ns() . '><w:body>'
			. yume_tsimp_p( 'Chapitre 1 : Le piège', 'Heading1' ) . $corps
			. '</w:body></w:document>';
	}

	/**
	 * Relations de document.xml.
	 *
	 * @param string $relations Éléments <Relationship> supplémentaires.
	 */
	function yume_tsimp_rels( string $relations = '' ): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relations . '</Relationships>';
	}

	/**
	 * Relation d'image (interne ou externe).
	 *
	 * @param string $id      Identifiant.
	 * @param string $cible   Cible.
	 * @param bool   $externe TargetMode="External".
	 * @param string $type    Fin du type de relation.
	 */
	function yume_tsimp_rel( string $id, string $cible, bool $externe = false, string $type = 'image' ): string {
		return '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type . '" Target="' . esc_attr( $cible ) . '"' . ( $externe ? ' TargetMode="External"' : '' ) . '/>';
	}

	/**
	 * Fabrique un DOCX dans un fichier temporaire et renvoie son chemin.
	 *
	 * @param array<string,string> $parties Entrées supplémentaires ou remplacées (nom → contenu).
	 * @param int|null             $methode Méthode de compression (défaut : deflate).
	 * @throws Yume_Test_Failure ZipArchive indisponible.
	 */
	function yume_tsimp_docx( array $parties, ?int $methode = null ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Yume_Test_Failure( 'Extension PHP zip absente.' );
		}
		$parties = array_merge(
			array(
				'[Content_Types].xml'          => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="svg" ContentType="image/svg+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
				'_rels/.rels'                  => yume_tsimp_rels( yume_tsimp_rel( 'rId1', 'word/document.xml', false, 'officeDocument' ) ),
				'word/document.xml'            => yume_tsimp_document( yume_tsimp_p( 'Un paragraphe.' ) ),
				'word/_rels/document.xml.rels' => yume_tsimp_rels(),
			),
			$parties
		);
		$chemin  = wp_tempnam( 'yume-piege' ) . '.docx';
		$zip     = new ZipArchive();
		$zip->open( $chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $parties as $nom => $contenu ) {
			$zip->addFromString( $nom, $contenu );
			if ( null !== $methode ) {
				$zip->setCompressionName( $nom, $methode );
			}
		}
		$zip->close();
		$GLOBALS['yume_tsimp_fichiers'][] = $chemin;
		return $chemin;
	}

	/**
	 * PNG valide de 1 × 1 pixel.
	 */
	function yume_tsimp_png(): string {
		return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image de test.
	}

	/**
	 * Fichier « secret » hors du document, que les entités externes tentent de lire.
	 */
	function yume_tsimp_secret(): string {
		$chemin = get_temp_dir() . 'yume-secret-' . getmypid() . '.txt';
		if ( ! is_file( $chemin ) ) {
			file_put_contents( $chemin, 'SECRET-SERVEUR-7f3a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$GLOBALS['yume_tsimp_fichiers'][] = $chemin;
		}
		return $chemin;
	}

	/**
	 * Convertit un DOCX piégé : Result, ou Import_Exception attendue ; toute autre exception ou
	 * erreur PHP (fatale comprise) fait échouer le test. Les requêtes HTTP sont interceptées.
	 *
	 * @param string $chemin Fichier.
	 * @return Result|Import_Exception
	 * @throws Yume_Test_Failure Exception inattendue ou requête réseau.
	 */
	function yume_tsimp_convertir( string $chemin ) {
		$requetes = array();
		$bloquer  = static function ( $pre, $args, $url ) use ( &$requetes ) {
			$requetes[] = $url;
			return new WP_Error( 'yume_test_reseau', 'Requête réseau interdite pendant l’import.' );
		};
		add_filter( 'pre_http_request', $bloquer, PHP_INT_MAX, 3 );
		try {
			$sortie = Docx_Converter::convert_file( $chemin );
		} catch ( Import_Exception $e ) {
			$sortie = $e;
		} catch ( Throwable $e ) {
			throw new Yume_Test_Failure( 'Exception inattendue : ' . get_class( $e ) . ' : ' . $e->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $bloquer, PHP_INT_MAX );
		}
		if ( $requetes ) {
			throw new Yume_Test_Failure( 'Requête réseau pendant l’import : ' . implode( ', ', $requetes ) );
		}
		return $sortie;
	}

	/**
	 * Tout le contenu produit par une conversion (chapitres, images, avertissements) en JSON.
	 *
	 * @param Result $r Résultat.
	 */
	function yume_tsimp_json( Result $r ): string {
		return (string) wp_json_encode( $r->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * HTML des chapitres d'une conversion.
	 *
	 * @param Result $r Résultat.
	 */
	function yume_tsimp_html( Result $r ): string {
		$html = '';
		foreach ( $r->chapters as $chapitre ) {
			foreach ( $chapitre as $valeur ) {
				$html .= is_string( $valeur ) ? $valeur . "\n" : '';
			}
		}
		return $html;
	}

	/**
	 * Vérifie qu'une conversion est refusée avec l'un des codes donnés, ou neutralisée (le
	 * secret n'apparaît nulle part dans le résultat).
	 *
	 * @param Result|Import_Exception $sortie Sortie de yume_tsimp_convertir().
	 * @param string                  $cas    Libellé du cas.
	 * @throws Yume_Test_Failure Contenu du fichier secret présent dans le résultat.
	 */
	function yume_tsimp_sans_secret( $sortie, string $cas ): void {
		if ( $sortie instanceof Result ) {
			yume_assert_not_contains( 'SECRET-SERVEUR', yume_tsimp_json( $sortie ), $cas );
			yume_assert_not_contains( 'lollollol', yume_tsimp_json( $sortie ), $cas . ' (entités développées)' );
		}
	}

	/**
	 * Liste récursive des fichiers d'un dossier (chemins réels).
	 *
	 * @param string $dossier Dossier.
	 * @return string[]
	 */
	function yume_tsimp_fichiers( string $dossier ): array {
		$liste = array();
		if ( ! is_dir( $dossier ) ) {
			return $liste;
		}
		$iterateur = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dossier, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterateur as $fichier ) {
			$liste[] = (string) $fichier->getPathname();
		}
		return $liste;
	}

	/**
	 * Supprime les fichiers temporaires créés par ces tests.
	 */
	function yume_tsimp_nettoyer(): void {
		foreach ( (array) ( $GLOBALS['yume_tsimp_fichiers'] ?? array() ) as $f ) {
			if ( is_file( $f ) ) {
				wp_delete_file( $f );
			}
			$sans = preg_replace( '/\.docx$/', '', $f );
			if ( $sans !== $f && is_file( $sans ) ) {
				wp_delete_file( $sans );
			}
		}
		$GLOBALS['yume_tsimp_fichiers'] = array();
	}
}

/*
 * -----------------------------------------------------------------------------
 * Entités XML (XXE, billion laughs)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-14 : XXE dans document.xml (file:// et http) refusée',
	function () {
		$secret = yume_tsimp_secret();
		$dtd    = '<!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file://' . $secret . '"><!ENTITY web SYSTEM "http://example.test/xxe">]>';
		$sortie = yume_tsimp_convertir( yume_tsimp_docx( array( 'word/document.xml' => yume_tsimp_document( yume_tsimp_p( 'Contenu : &xxe; &web; fin' ), $dtd ) ) ) );
		yume_tsimp_nettoyer();
		yume_assert_true( $sortie instanceof Import_Exception, 'refus attendu' );
		yume_assert_same( 'docx_suspect', $sortie->code_erreur() );
	}
);

yume_test(
	'AMEL-14 : XXE avec DOCTYPE repoussé au-delà des 2 premiers Ko (commentaire de remplissage) refusée',
	function () {
		$secret  = yume_tsimp_secret();
		$dtd     = '<!-- ' . str_repeat( 'remplissage ', 400 ) . '-->'
			. '<!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file://' . $secret . '"><!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]>';
		$corps   = yume_tsimp_p( 'Contenu : &xxe; fin &lol2;' );
		$sorties = array(
			'document.xml' => yume_tsimp_convertir( yume_tsimp_docx( array( 'word/document.xml' => yume_tsimp_document( $corps, $dtd ) ) ) ),
			'styles.xml'   => yume_tsimp_convertir(
				yume_tsimp_docx(
					array(
						'word/_rels/document.xml.rels' => yume_tsimp_rels( yume_tsimp_rel( 'rId2', 'styles.xml', false, 'styles' ) ),
						'word/styles.xml'              => '<?xml version="1.0" encoding="UTF-8"?>' . $dtd . '<w:styles ' . yume_tsimp_ns() . '><w:style w:type="paragraph" w:styleId="x"><w:name w:val="&xxe;"/></w:style></w:styles>',
					)
				)
			),
		);
		yume_tsimp_nettoyer();
		foreach ( $sorties as $cas => $sortie ) {
			yume_tsimp_sans_secret( $sortie, $cas );
			yume_assert_true( $sortie instanceof Import_Exception, $cas . ' : refus attendu' );
			// styles.xml : l'entité externe dans un attribut rend déjà la partie illisible.
			yume_assert_true( in_array( $sortie->code_erreur(), array( 'docx_suspect', 'docx_endommage' ), true ), $cas . ' : ' . $sortie->code_erreur() );
		}
		yume_assert_same( 'docx_suspect', $sorties['document.xml']->code_erreur() );
	}
);

yume_test(
	'AMEL-14 : XXE dans un document encodé en UTF-16 refusée',
	function () {
		$secret = yume_tsimp_secret();
		$xml    = str_replace(
			'encoding="UTF-8"',
			'encoding="UTF-16"',
			yume_tsimp_document( yume_tsimp_p( 'Contenu : &xxe; fin' ), '<!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>' )
		);
		$utf16  = "\xFF\xFE" . mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' );
		$sortie = yume_tsimp_convertir( yume_tsimp_docx( array( 'word/document.xml' => $utf16 ) ) );
		yume_tsimp_nettoyer();
		yume_tsimp_sans_secret( $sortie, 'UTF-16' );
		yume_assert_true( $sortie instanceof Import_Exception, 'refus attendu' );
		yume_assert_same( 'docx_suspect', $sortie->code_erreur() );
	}
);

yume_test(
	'AMEL-14 : XXE dans les relations (_rels) refusée',
	function () {
		$secret = yume_tsimp_secret();
		$rels   = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE Relationships [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml&xxe;"/></Relationships>';
		$sortie = yume_tsimp_convertir( yume_tsimp_docx( array( '_rels/.rels' => $rels ) ) );
		yume_tsimp_nettoyer();
		yume_assert_true( $sortie instanceof Import_Exception, 'refus attendu' );
		yume_assert_same( 'docx_suspect', $sortie->code_erreur() );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Archive (bombe de décompression, zip slip)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-14 : bombe de décompression (document.xml ~24 Mo, taux > 100:1) refusée',
	function () {
		$corps  = '<w:p><w:r><w:t xml:space="preserve">' . str_repeat( ' ', 24 * 1024 * 1024 ) . '</w:t></w:r></w:p>';
		$docx   = yume_tsimp_docx( array( 'word/document.xml' => yume_tsimp_document( $corps ) ) );
		$taille = (int) filesize( $docx );
		$sortie = yume_tsimp_convertir( $docx );
		yume_tsimp_nettoyer();
		yume_assert_true( $taille < 1024 * 1024, 'archive compacte (' . $taille . ' octets)' );
		yume_assert_true( $sortie instanceof Import_Exception, 'refus attendu' );
		yume_assert_same( 'archive_suspecte', $sortie->code_erreur() );
	}
);

yume_test(
	'AMEL-14 : image-bombe (PNG suivi de ~24 Mo de zéros) ignorée sans être copiée',
	function () {
		$docx   = yume_tsimp_docx(
			array(
				'word/document.xml'            => yume_tsimp_document( yume_tsimp_image( 'rId5' ) ),
				'word/_rels/document.xml.rels' => yume_tsimp_rels( yume_tsimp_rel( 'rId5', 'media/bombe.png' ) ),
				'word/media/bombe.png'         => yume_tsimp_png() . str_repeat( "\0", 24 * 1024 * 1024 ),
			)
		);
		$sortie = yume_tsimp_convertir( $docx );
		$copie  = false;
		if ( $sortie instanceof Result ) {
			$tmp = wp_tempnam( 'yume-bombe' );
			foreach ( array_keys( $sortie->images ) as $cle ) {
				$copie = $copie || $sortie->copier_image( (string) $cle, $tmp );
			}
			wp_delete_file( $tmp );
		}
		yume_tsimp_nettoyer();
		yume_assert_false( $copie, 'image anormalement compressée jamais copiée' );
	}
);

yume_test(
	'AMEL-14 : zip slip (entrées « ../ », relation vers /etc) : rien n’est lu ni écrit hors de l’archive',
	function () {
		$temp     = get_temp_dir();
		$marqueur = 'yume-slip-' . wp_rand( 100000, 999999 );
		$docx     = yume_tsimp_docx(
			array(
				'word/document.xml'            => yume_tsimp_document( yume_tsimp_image( 'rId5' ) . yume_tsimp_image( 'rId6' ) . yume_tsimp_image( 'rId7' ) ),
				'word/_rels/document.xml.rels' => yume_tsimp_rels(
					yume_tsimp_rel( 'rId5', '../../../../../../etc/hostname' )
					. yume_tsimp_rel( 'rId6', 'media/../../../../' . $marqueur . '.png' )
					. yume_tsimp_rel( 'rId7', '/etc/passwd' )
				),
				'../../' . $marqueur . '.txt'  => 'slip',
				'word/media/../../../../' . $marqueur . '.png' => yume_tsimp_png(),
			)
		);
		$sortie   = yume_tsimp_convertir( $docx );
		yume_tsimp_nettoyer();
		yume_assert_true( $sortie instanceof Result || $sortie instanceof Import_Exception );
		if ( $sortie instanceof Result ) {
			yume_assert_same( array(), $sortie->images, 'aucune image hors de l’archive retenue' );
			yume_assert_not_contains( 'root:', yume_tsimp_json( $sortie ) );
		}
		foreach ( array( $temp, dirname( $temp ), ABSPATH, dirname( ABSPATH ), wp_upload_dir()['basedir'] ) as $dossier ) {
			yume_assert_false( file_exists( trailingslashit( $dossier ) . $marqueur . '.txt' ), 'fichier écrit hors de l’archive : ' . $dossier );
			yume_assert_false( file_exists( trailingslashit( $dossier ) . $marqueur . '.png' ), 'fichier écrit hors de l’archive : ' . $dossier );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Contenu actif (SVG, HTML, relations externes)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-14 : image SVG avec script (et SVG déguisé en .png) ignorée avec un avertissement',
	function () {
		$svg    = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(document.cookie)</script><rect width="10" height="10"/></svg>';
		$sortie = yume_tsimp_convertir(
			yume_tsimp_docx(
				array(
					'word/document.xml'            => yume_tsimp_document( yume_tsimp_image( 'rId5' ) . yume_tsimp_image( 'rId6' ) ),
					'word/_rels/document.xml.rels' => yume_tsimp_rels( yume_tsimp_rel( 'rId5', 'media/piege.svg' ) . yume_tsimp_rel( 'rId6', 'media/deguise.png' ) ),
					'word/media/piege.svg'         => $svg,
					'word/media/deguise.png'       => $svg,
				)
			)
		);
		yume_tsimp_nettoyer();
		yume_assert_true( $sortie instanceof Result, 'document accepté, images écartées' );
		yume_assert_same( array(), $sortie->images );
		yume_assert_not_contains( '<svg', yume_tsimp_html( $sortie ) );
		yume_assert_not_contains( '<script', yume_tsimp_html( $sortie ) );
		yume_assert_contains( 'SVG', implode( ' ', $sortie->warnings ) );
		yume_assert_contains( 'deguise.png', implode( ' ', $sortie->warnings ) );
	}
);

yume_test(
	'AMEL-14 : HTML dans le texte (<script>, onerror, liens javascript:/file:) neutralisé',
	function () {
		$corps  = yume_tsimp_p( 'Texte &lt;script&gt;alert(1)&lt;/script&gt; et &lt;img src=x onerror=alert(2)&gt; puis &lt;svg/onload=alert(3)&gt; "guillemets" &amp; fin.' )
			. '<w:p><w:hyperlink r:id="rId8"><w:r><w:t>lien javascript</w:t></w:r></w:hyperlink></w:p>'
			. '<w:p><w:hyperlink r:id="rId9"><w:r><w:t>lien fichier</w:t></w:r></w:hyperlink></w:p>'
			. '<w:p><w:hyperlink r:id="rId10"><w:r><w:t>lien normal</w:t></w:r></w:hyperlink></w:p>';
		$titre  = yume_tsimp_p( 'Chapitre 2 &lt;script&gt;alert(4)&lt;/script&gt;', 'Heading1' ) . yume_tsimp_p( 'Suite.' );
		$sortie = yume_tsimp_convertir(
			yume_tsimp_docx(
				array(
					'word/document.xml'            => yume_tsimp_document( $corps . $titre ),
					'word/_rels/document.xml.rels' => yume_tsimp_rels(
						yume_tsimp_rel( 'rId8', 'javascript:alert(document.domain)', true, 'hyperlink' )
						. yume_tsimp_rel( 'rId9', 'file:///etc/passwd', true, 'hyperlink' )
						. yume_tsimp_rel( 'rId10', 'https://example.test/?a="><script>', true, 'hyperlink' )
					),
				)
			)
		);
		yume_tsimp_nettoyer();
		yume_assert_true( $sortie instanceof Result );
		$html = yume_tsimp_html( $sortie );
		foreach ( array( '<script', '<img', '<svg', 'javascript:', 'file:', '"><' ) as $interdit ) {
			yume_assert_not_contains( $interdit, $html, $interdit );
		}
		yume_assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'texte conservé, échappé' );
		yume_assert_contains( 'href="https://example.test/?a=&quot;&gt;&lt;script&gt;"', $html, 'lien http conservé, attribut échappé' );
		foreach ( $sortie->chapters as $chapitre ) {
			yume_assert_not_contains( '<', (string) $chapitre['titre'] . (string) $chapitre['sous_titre'], 'titres en texte brut' );
		}
	}
);

yume_test(
	'AMEL-14 : relations externes (images file:// et http, styles http) ignorées sans requête réseau',
	function () {
		$secret = yume_tsimp_secret();
		$sortie = yume_tsimp_convertir(
			yume_tsimp_docx(
				array(
					'word/document.xml'            => yume_tsimp_document( yume_tsimp_image( 'rId5' ) . yume_tsimp_image( 'rId6' ) . yume_tsimp_image( 'rId7', 'link' ) ),
					'word/_rels/document.xml.rels' => yume_tsimp_rels(
						yume_tsimp_rel( 'rId5', 'file://' . $secret, true )
						. yume_tsimp_rel( 'rId6', 'http://example.test/pisteur.png', true )
						. yume_tsimp_rel( 'rId7', 'https://example.test/lie.png', true )
						. yume_tsimp_rel( 'rId2', 'http://example.test/styles.xml', true, 'styles' )
						. yume_tsimp_rel( 'rId3', 'file://' . $secret, true, 'footnotes' )
					),
				)
			)
		);
		yume_tsimp_nettoyer();
		yume_assert_true( $sortie instanceof Result );
		yume_assert_same( array(), $sortie->images );
		yume_tsimp_sans_secret( $sortie, 'relations externes' );
		yume_assert_contains( 'image liée', implode( ' ', $sortie->warnings ) );
		yume_assert_not_contains( 'example.test', yume_tsimp_html( $sortie ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Chaîne complète (service de publication)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-14 : service de publication : DOCX piégé importé sans script, fichiers seulement dans uploads, source supprimée',
	function () {
		$uploads   = wp_upload_dir()['basedir'];
		$temp      = get_temp_dir();
		$avant_tmp = (array) glob( $temp . 'yume-image*' );
		$avant_up  = yume_tsimp_fichiers( $uploads );
		$medias    = array();
		$suivre    = static function ( $id ) use ( &$medias ) {
			$medias[] = (int) $id;
		};
		$requetes  = array();
		$bloquer   = static function ( $pre, $args, $url ) use ( &$requetes ) {
			$requetes[] = $url;
			return new WP_Error( 'yume_test_reseau', 'Requête réseau interdite.' );
		};
		$docx      = yume_tsimp_docx(
			array(
				'word/document.xml'            => yume_tsimp_document(
					yume_tsimp_p( '&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;' )
					. yume_tsimp_image( 'rId5' ) . yume_tsimp_image( 'rId6' ) . yume_tsimp_image( 'rId7' ) . yume_tsimp_image( 'rId8' )
				),
				'word/_rels/document.xml.rels' => yume_tsimp_rels(
					yume_tsimp_rel( 'rId5', 'media/vraie.png' )
					. yume_tsimp_rel( 'rId6', 'media/piege.svg' )
					. yume_tsimp_rel( 'rId7', '../../../../etc/hostname' )
					. yume_tsimp_rel( 'rId8', 'http://example.test/pisteur.png', true )
				),
				'word/media/vraie.png'         => yume_tsimp_png(),
				'word/media/piege.svg'         => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
				'../../yume-slip-service.txt'  => 'slip',
			)
		);
		$source    = array(
			'name'     => 'piege.docx',
			'type'     => 'application/octet-stream',
			'tmp_name' => $docx,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $docx ),
		);
		wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
		add_filter( 'yume_publication_fichier_local', '__return_true' );
		add_action( 'add_attachment', $suivre );
		add_filter( 'pre_http_request', $bloquer, PHP_INT_MAX, 3 );
		try {
			$oeuvre  = yume_factory_post(
				array(
					'post_type'  => 'yume_oeuvre',
					'post_title' => 'Œuvre piégée',
				)
			);
			$rapport = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '31',
				),
				array( 'source' => $source )
			);
			yume_assert_false( is_wp_error( $rapport ), is_wp_error( $rapport ) ? $rapport->get_error_message() : '' );
			yume_assert_same( array(), $requetes, 'aucune requête réseau' );
			yume_assert_false( file_exists( $docx ), 'fichier source supprimé après import' );
			yume_assert_same( 1, count( $medias ), 'seule la vraie image est versée' );
			foreach ( $medias as $id ) {
				$fichier = (string) get_attached_file( $id );
				yume_assert_same( 0, strpos( (string) realpath( $fichier ), (string) realpath( $uploads ) ), 'pièce jointe dans uploads : ' . $fichier );
				yume_assert_same( 'image/', substr( (string) get_post_mime_type( $id ), 0, 6 ) );
			}
			foreach ( yume_get_chapitres( (int) $rapport['tome']['id'], array( 'status' => 'any' ) ) as $chapitre ) {
				foreach ( array( '<script', '<img src=x', '<svg', 'hostname', 'example.test' ) as $interdit ) {
					yume_assert_not_contains( $interdit, $chapitre->post_content, $interdit );
				}
			}
			foreach ( array( $temp, dirname( $temp ), ABSPATH, dirname( ABSPATH ) ) as $dossier ) {
				yume_assert_false( file_exists( trailingslashit( $dossier ) . 'yume-slip-service.txt' ), 'zip slip : ' . $dossier );
			}
			$nouveaux = array_diff( (array) glob( $temp . 'yume-image*' ), $avant_tmp );
			yume_assert_same( array(), array_values( $nouveaux ), 'aucune image temporaire laissée' );
			$ecrits = array_diff( yume_tsimp_fichiers( $uploads ), $avant_up );
			foreach ( $ecrits as $ecrit ) {
				yume_assert_contains( 'vraie', basename( $ecrit ), 'seule la vraie image (et ses tailles) est écrite dans uploads' );
			}
		} finally {
			remove_filter( 'yume_publication_fichier_local', '__return_true' );
			remove_action( 'add_attachment', $suivre );
			remove_filter( 'pre_http_request', $bloquer, PHP_INT_MAX );
			wp_set_current_user( 0 );
			foreach ( $medias as $id ) {
				wp_delete_attachment( $id, true );
			}
			yume_tsimp_nettoyer();
		}
	}
);

yume_test(
	'EPUB : une DTD que sans_doctype() ne sait pas retirer (sous-ensemble interne piégé) est refusée',
	function () {
		$source = dirname( YUME_CORE_DIR, 3 ) . '/tools/fixtures/livre-epub3.epub';
		yume_assert_true( is_readable( $source ), 'fixture EPUB' );
		// « ] » dans la valeur d'une entité : l'expression de sans_doctype() ne reconnaît pas la
		// déclaration, qui arrive entière à loadXML().
		$dtd = '<!DOCTYPE package [<!ENTITY x "a]b"><!ENTITY xxe SYSTEM "file:///etc/hostname">]>';
		$cas = array(
			'OEBPS/content.opf'           => 'epub_suspect',
			'OEBPS/texte/chapitre1.xhtml' => null,
		);
		foreach ( $cas as $partie => $code ) {
			$chemin = wp_tempnam( 'yume-piege' ) . '.epub';
			copy( $source, $chemin );
			$GLOBALS['yume_tsimp_fichiers'][] = $chemin;
			$zip                              = new ZipArchive();
			$zip->open( $chemin );
			$xml = (string) $zip->getFromName( $partie );
			$xml = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $xml );
			$xml = preg_replace( '/(<\?xml[^>]*\?>)/', '$1' . $dtd, $xml, 1, $n );
			if ( ! $n ) {
				$xml = $dtd . $xml;
			}
			$zip->addFromString( $partie, $xml );
			$zip->close();
			try {
				$resultat = Epub_Converter::convert_file( $chemin );
				yume_assert_same( null, $code, $partie . ' aurait dû être refusé' );
				$html = '';
				foreach ( $resultat->chapters as $chapitre ) {
					$html .= is_array( $chapitre ) ? (string) ( $chapitre['html'] ?? '' ) : (string) ( $chapitre->html ?? '' );
				}
				yume_assert_not_contains( 'a]b', $html, $partie );
			} catch ( Import_Exception $e ) {
				yume_assert_same( $code, $e->code_erreur(), $partie );
			}
		}
		yume_tsimp_nettoyer();
	}
);
