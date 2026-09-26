<?php
/**
 * Génère les fichiers DOCX et EPUB synthétiques utilisés par les tests du convertisseur
 * (tests/test-import.php, tests/test-publication.php) et par l'outil docx2chapters.
 *
 *   php tools/fixtures/build-fixtures.php [dossier]     (défaut : tools/fixtures/)
 *
 * Chaque fichier couvre une famille de règles de docs/04 §2 et §3 :
 * - regles.docx           : styles localisés (Titre1, Titre2, Pense, Paragraphedeliste), puces
 *                           « — » par le style et directes (-, –, via style de numérotation),
 *                           tirets tapés, pensées (style et style de caractère), centrage,
 *                           séparateurs, vides multiples, runs (gras, italique, souligné,
 *                           exposant, fusion, sauts de ligne, tabulation, espaces insécables,
 *                           entités), notes, images PNG/JPG/GIF/WebP, EMF ignoré, images avant
 *                           le premier chapitre, titres irréguliers, doublons, trous,
 *                           chapitre vide, PostFace, en-tête, table, champ, lien, zone de texte ;
 * - styles-variantes.docx : styles anglais, titre par outlineLvl, style « Thought »,
 *                           « Dialogue », puce Symbol, titres « Chapter 4 » et romains ;
 * - sans-titre.docx       : aucun titre → chapitre unique ;
 * - livre-epub3.epub      : EPUB 3 (nav, landmarks, couverture, découpe sur <h1>, suite sans
 *                           <h1>, deux <h1> dans un fichier, notes, classes, heuristiques) ;
 * - livre-epub2.epub      : EPUB 2 (NCX, guide, aucun <h1> : découpe par la table des matières) ;
 * - invalide.docx, faux.docx, chiffre.docx : fichiers refusés.
 *
 * Tout le texte est inventé (aucun extrait d'œuvre). Les images sont générées avec GD.
 *
 * @package Yume\Core
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput

/** Date fixe des entrées ZIP (fichiers reproductibles). */
const YUME_FX_DATE = 1735689600; // 2025-01-01.

/** Espaces de noms du document Word. */
const YUME_FX_NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"';

/**
 * Échappe un texte XML.
 *
 * @param string $t Texte.
 */
function yume_fx_x( string $t ): string {
	return htmlspecialchars( $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * Run Word.
 *
 * @param string $texte Texte.
 * @param array  $f     b, i, u, va (superscript|subscript), style, vanish, i0 (italique forcé à 0).
 */
function yume_fx_r( string $texte, array $f = array() ): string {
	$rpr = '';
	if ( ! empty( $f['style'] ) ) {
		$rpr .= '<w:rStyle w:val="' . $f['style'] . '"/>';
	}
	if ( ! empty( $f['b'] ) ) {
		$rpr .= '<w:b/><w:bCs/>';
	}
	if ( ! empty( $f['i'] ) ) {
		$rpr .= '<w:i/><w:iCs/>';
	}
	if ( ! empty( $f['i0'] ) ) {
		$rpr .= '<w:i w:val="0"/><w:iCs w:val="0"/>';
	}
	if ( ! empty( $f['u'] ) ) {
		$rpr .= '<w:u w:val="single"/>';
	}
	if ( ! empty( $f['vanish'] ) ) {
		$rpr .= '<w:vanish/>';
	}
	if ( ! empty( $f['va'] ) ) {
		$rpr .= '<w:vertAlign w:val="' . $f['va'] . '"/>';
	}
	return '<w:r>' . ( '' !== $rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '' ) . '<w:t xml:space="preserve">' . yume_fx_x( $texte ) . '</w:t></w:r>';
}

/**
 * Paragraphe Word.
 *
 * @param string $contenu Runs.
 * @param array  $o       style, num [numId, ilvl], jc, outline, sect (saut de section).
 */
function yume_fx_p( string $contenu, array $o = array() ): string {
	$ppr = '';
	if ( ! empty( $o['style'] ) ) {
		$ppr .= '<w:pStyle w:val="' . $o['style'] . '"/>';
	}
	if ( isset( $o['num'] ) ) {
		$ppr .= '<w:numPr><w:ilvl w:val="' . (int) ( $o['num'][1] ?? 0 ) . '"/><w:numId w:val="' . (int) $o['num'][0] . '"/></w:numPr>';
	}
	if ( isset( $o['outline'] ) ) {
		$ppr .= '<w:outlineLvl w:val="' . (int) $o['outline'] . '"/>';
	}
	if ( ! empty( $o['jc'] ) ) {
		$ppr .= '<w:jc w:val="' . $o['jc'] . '"/>';
	}
	if ( ! empty( $o['sect'] ) ) {
		$ppr .= '<w:sectPr><w:headerReference w:type="default" r:id="rIdEntete"/><w:pgSz w:w="11906" w:h="16838"/></w:sectPr>';
	}
	return '<w:p>' . ( '' !== $ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '' ) . $contenu . '</w:p>';
}

/**
 * Paragraphe simple.
 *
 * @param string $texte Texte.
 * @param array  $o     Options de yume_fx_p().
 */
function yume_fx_t( string $texte, array $o = array() ): string {
	return yume_fx_p( yume_fx_r( $texte ), $o );
}

/**
 * Dessin Word (image incorporée).
 *
 * @param string $rid   Relation.
 * @param string $descr Texte alternatif.
 * @param bool   $ancre Image ancrée (pleine page) plutôt qu'en ligne.
 */
function yume_fx_dessin( string $rid, string $descr = '', bool $ancre = false ): string {
	static $n = 0;
	++$n;
	$graphique = '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="' . $n . '" name="Image ' . $n . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="3000000" cy="2000000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic>';
	$doc_pr    = '<wp:docPr id="' . $n . '" name="Image ' . $n . '"' . ( '' !== $descr ? ' descr="' . yume_fx_x( $descr ) . '"' : '' ) . '/>';
	if ( $ancre ) {
		return '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="1" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:align>center</wp:align></wp:positionH><wp:positionV relativeFrom="page"><wp:align>center</wp:align></wp:positionV><wp:extent cx="7560000" cy="10692000"/><wp:wrapTopAndBottom/>' . $doc_pr . $graphique . '</wp:anchor></w:drawing></w:r>';
	}
	return '<w:r><w:drawing><wp:inline><wp:extent cx="3000000" cy="2000000"/>' . $doc_pr . $graphique . '</wp:inline></w:drawing></w:r>';
}

/**
 * Image PNG, JPEG, GIF ou WebP générée avec GD (deux bandes de couleur).
 *
 * @param string $format png, jpeg, gif, webp.
 * @param int    $l      Largeur.
 * @param int    $h      Hauteur.
 */
function yume_fx_image( string $format, int $l, int $h ): string {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		fwrite( STDERR, "L'extension GD est nécessaire pour générer les images des fixtures.\n" );
		exit( 1 );
	}
	$img  = imagecreatetruecolor( $l, $h );
	$rose = imagecolorallocate( $img, 243, 166, 200 );
	$nuit = imagecolorallocate( $img, 45, 31, 79 );
	imagefilledrectangle( $img, 0, 0, $l - 1, $h - 1, $nuit );
	imagefilledrectangle( $img, 0, 0, (int) ( $l / 2 ), $h - 1, $rose );
	ob_start();
	switch ( $format ) {
		case 'jpeg':
			imagejpeg( $img, null, 80 );
			break;
		case 'gif':
			imagegif( $img );
			break;
		case 'webp':
			imagewebp( $img, null, 80 );
			break;
		default:
			imagepng( $img, null, 9 );
	}
	imagedestroy( $img );
	return (string) ob_get_clean();
}

/**
 * Écrit une archive ZIP reproductible.
 *
 * @param string               $fichier  Chemin.
 * @param array<string,string> $entrees  Nom => contenu (dans l'ordre).
 * @param string[]             $stockees Entrées non compressées (mimetype EPUB).
 */
function yume_fx_zip( string $fichier, array $entrees, array $stockees = array() ): void {
	if ( file_exists( $fichier ) ) {
		unlink( $fichier );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $fichier, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Impossible d'écrire $fichier\n" );
		exit( 1 );
	}
	foreach ( $entrees as $nom => $contenu ) {
		$zip->addFromString( $nom, $contenu );
		if ( in_array( $nom, $stockees, true ) ) {
			$zip->setCompressionName( $nom, ZipArchive::CM_STORE );
		}
		if ( method_exists( $zip, 'setMtimeName' ) ) {
			$zip->setMtimeName( $nom, YUME_FX_DATE );
		}
	}
	$zip->close();
}

/**
 * Assemble un DOCX.
 *
 * @param string               $fichier Chemin.
 * @param string               $corps   Contenu de w:body.
 * @param string               $styles  word/styles.xml.
 * @param string               $numero  word/numbering.xml ('' : aucun).
 * @param array<string,string> $medias  Nom dans word/media/ => contenu.
 * @param array<string,string> $extra   Autres parties (chemin => contenu) et relations spéciales.
 */
function yume_fx_docx( string $fichier, string $corps, string $styles, string $numero, array $medias, array $extra = array() ): void {
	$types = array(
		'png'  => 'image/png',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'emf'  => 'image/x-emf',
	);
	$ct    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>';
	foreach ( $types as $ext => $mime ) {
		$ct .= '<Default Extension="' . $ext . '" ContentType="' . $mime . '"/>';
	}
	$ct .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
	if ( '' !== $numero ) {
		$ct .= '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>';
	}
	if ( isset( $extra['word/footnotes.xml'] ) ) {
		$ct .= '<Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/>';
	}
	if ( isset( $extra['word/header1.xml'] ) ) {
		$ct .= '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>';
	}
	$ct .= '</Types>';

	$rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
	$rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
	if ( '' !== $numero ) {
		$rels .= '<Relationship Id="rIdNum" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>';
	}
	if ( isset( $extra['word/footnotes.xml'] ) ) {
		$rels .= '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>';
	}
	if ( isset( $extra['word/header1.xml'] ) ) {
		$rels .= '<Relationship Id="rIdEntete" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>';
	}
	foreach ( array_keys( $medias ) as $nom ) {
		$rels .= '<Relationship Id="rId_' . preg_replace( '/[^a-z0-9]/i', '_', $nom ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $nom . '"/>';
	}
	$rels .= '<Relationship Id="rIdLien" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://yumenovel.fr/" TargetMode="External"/>';
	$rels .= '<Relationship Id="rIdImageLiee" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://exemple.test/image.png" TargetMode="External"/>';
	$rels .= '</Relationships>';

	$entrees = array(
		'[Content_Types].xml'          => $ct,
		'_rels/.rels'                  => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
		'word/document.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . YUME_FX_NS . '><w:body>' . $corps . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>',
		'word/_rels/document.xml.rels' => $rels,
		'word/styles.xml'              => $styles,
	);
	if ( '' !== $numero ) {
		$entrees['word/numbering.xml'] = $numero;
	}
	foreach ( $extra as $nom => $contenu ) {
		$entrees[ $nom ] = $contenu;
	}
	foreach ( $medias as $nom => $contenu ) {
		$entrees[ 'word/media/' . $nom ] = $contenu;
	}
	yume_fx_zip( $fichier, $entrees );
}

/**
 * Relation d'une image de word/media/.
 *
 * @param string $nom Nom du fichier.
 */
function yume_fx_rid( string $nom ): string {
	return 'rId_' . preg_replace( '/[^a-z0-9]/i', '_', $nom );
}

/**
 * Styles « à la française » (identifiants localisés, comme le DOCX de référence).
 */
function yume_fx_styles_fr(): string {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
		. '<w:docDefaults><w:rPrDefault><w:rPr><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault></w:docDefaults>'
		. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:jc w:val="both"/></w:pPr></w:style>'
		. '<w:style w:type="paragraph" w:styleId="Titre1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>'
		. '<w:style w:type="paragraph" w:styleId="Titre2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>'
		. '<w:style w:type="paragraph" w:styleId="Titre3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:pPr><w:outlineLvl w:val="2"/></w:pPr></w:style>'
		. '<w:style w:type="paragraph" w:styleId="Paragraphedeliste"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr><w:ind w:left="1349" w:hanging="357"/></w:pPr></w:style>'
		. '<w:style w:type="paragraph" w:customStyle="1" w:styleId="Pense"><w:name w:val="Pensée"/><w:basedOn w:val="Paragraphedeliste"/><w:link w:val="PenseCar"/><w:pPr><w:numPr><w:numId w:val="0"/></w:numPr></w:pPr><w:rPr><w:i/><w:iCs/></w:rPr></w:style>'
		. '<w:style w:type="character" w:customStyle="1" w:styleId="PenseCar"><w:name w:val="Pensée Car"/><w:link w:val="Pense"/><w:rPr><w:i/><w:iCs/></w:rPr></w:style>'
		. '<w:style w:type="paragraph" w:styleId="TM1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/></w:style>'
		. '<w:style w:type="numbering" w:styleId="TiretListe"><w:name w:val="Tiret liste"/><w:pPr><w:numPr><w:numId w:val="7"/></w:numPr></w:pPr></w:style>'
		. '</w:styles>';
}

/**
 * Niveau de liste.
 *
 * @param string $format numFmt.
 * @param string $texte  lvlText.
 * @param string $police Police de la puce ('' : aucune).
 */
function yume_fx_niveau( string $format, string $texte, string $police = '' ): string {
	return '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="' . $format . '"/><w:lvlText w:val="' . yume_fx_x( $texte ) . '"/><w:lvlJc w:val="left"/>'
		. ( '' !== $police ? '<w:rPr><w:rFonts w:ascii="' . $police . '" w:hAnsi="' . $police . '"/></w:rPr>' : '' ) . '</w:lvl>';
}

/**
 * Numérotations « à la française ».
 */
function yume_fx_numerotation_fr(): string {
	$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
	$x .= '<w:abstractNum w:abstractNumId="0">' . yume_fx_niveau( 'bullet', '—', 'Calibri' ) . '</w:abstractNum>';
	$x .= '<w:abstractNum w:abstractNumId="1">' . yume_fx_niveau( 'bullet', '-', 'Calibri' ) . '</w:abstractNum>';
	$x .= '<w:abstractNum w:abstractNumId="2">' . yume_fx_niveau( 'bullet', '–', 'Calibri' ) . '</w:abstractNum>';
	$x .= '<w:abstractNum w:abstractNumId="3">' . yume_fx_niveau( 'bullet', "\u{F0A7}", 'Wingdings' ) . '</w:abstractNum>';
	$x .= '<w:abstractNum w:abstractNumId="4">' . yume_fx_niveau( 'decimal', '%1.' ) . '</w:abstractNum>';
	$x .= '<w:abstractNum w:abstractNumId="5"><w:numStyleLink w:val="TiretListe"/></w:abstractNum>';
	$x .= '<w:abstractNum w:abstractNumId="6"><w:styleLink w:val="TiretListe"/>' . yume_fx_niveau( 'bullet', '—' ) . '</w:abstractNum>';
	foreach ( array(
		1 => 0,
		2 => 1,
		3 => 2,
		4 => 3,
		5 => 4,
		6 => 5,
		7 => 6,
	) as $num => $abstrait ) {
		$x .= '<w:num w:numId="' . $num . '"><w:abstractNumId w:val="' . $abstrait . '"/></w:num>';
	}
	return $x . '</w:numbering>';
}

/**
 * regles.docx : toutes les règles de docs/04 §2.
 *
 * @param string $dossier Dossier de sortie.
 */
function yume_fx_regles( string $dossier ): string {
	$c = '';
	// --- Avant le premier chapitre : galerie du tome, table des matières, crédits ignorés.
	$c .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'ornement.emf' ), '', true ) );
	$c .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'couverture.jpeg' ), 'Couverture du tome', true ) );
	$c .= yume_fx_t( 'Table des matières', array( 'style' => 'TM1' ) );
	$c .= yume_fx_t( 'Chapitre 1 ........ 3', array( 'style' => 'TM1' ) );
	$c .= yume_fx_t( 'Traduction : équipe Yume' );
	$c .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'couleur.png' ), '', true ), array( 'style' => 'Titre1' ) );

	// --- Prologue : runs et typographie.
	$c .= yume_fx_t( 'Prologue', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_p(
		yume_fx_r( 'Il ' ) . yume_fx_r( 'était ' ) . yume_fx_r( 'une', array( 'b' => true ) ) . yume_fx_r( ' fois, ' ) . yume_fx_r( 'dans un ', array( 'i' => true ) )
		. yume_fx_r( 'rêve', array( 'i' => true, 'b' => true ) ) . yume_fx_r( ' lointain', array( 'i' => true ) ) . yume_fx_r( ', un ' ) . yume_fx_r( 'serment', array( 'u' => true ) )
		. yume_fx_r( ' prononcé le 1' ) . yume_fx_r( 'er', array( 'va' => 'superscript' ) ) . yume_fx_r( ' jour' ) . yume_fx_r( ',', array( 'b' => true ) ) . yume_fx_r( ' et H' ) . yume_fx_r( '2', array( 'va' => 'subscript' ) ) . yume_fx_r( 'O.' )
	);
	$c .= yume_fx_p( yume_fx_r( 'Première ligne' ) . '<w:r><w:br/></w:r>' . yume_fx_r( 'seconde ligne' ) . '<w:r><w:tab/></w:r>' . yume_fx_r( 'après une tabulation.' ) );
	$c .= yume_fx_t( "Vraiment\u{00A0}? Oui ! « Bonjour » dit-elle ; puis : <Yume> & Cie." );
	$c .= yume_fx_p( yume_fx_r( 'Texte visible' ) . yume_fx_r( ' TEXTE MASQUÉ', array( 'vanish' => true ) ) . yume_fx_r( ' et suite.' ) );

	// --- Chapitre 1 : dialogues, pensées, centrage, séparateurs, images, notes, listes.
	$c .= yume_fx_t( 'Chapitre 1', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_p( '', array() ); // Vide entre titre et sous-titre.
	$c .= yume_fx_t( 'La Crête Brumeuse', array( 'style' => 'Titre2' ) );
	$c .= yume_fx_t( 'Réplique par le style de liste.', array( 'style' => 'Paragraphedeliste' ) );
	$c .= yume_fx_t( 'Réplique par une puce trait d’union.', array( 'num' => array( 2 ) ) );
	$c .= yume_fx_t( 'Réplique par une puce demi-cadratin.', array( 'num' => array( 3 ) ) );
	$c .= yume_fx_t( 'Réplique par un style de numérotation.', array( 'num' => array( 7 ) ) );
	$c .= yume_fx_t( '- Réplique tapée avec un trait d’union.' );
	$c .= yume_fx_t( '—Réplique tapée sans espace.' );
	$c .= yume_fx_p( yume_fx_r( 'Quelle étrange brume, ' ) . yume_fx_r( 'pensa-t-il', array( 'i0' => true ) ) . yume_fx_r( '. Je dois avancer.' ), array( 'style' => 'Pense' ) );
	$c .= yume_fx_p( yume_fx_r( 'Allez, ', array( 'style' => 'PenseCar' ) ) . yume_fx_r( 'se dit-elle', array( 'style' => 'PenseCar', 'i0' => true ) ) . yume_fx_r( ', encore un effort.', array( 'style' => 'PenseCar' ) ), array( 'style' => 'Paragraphedeliste', 'num' => array( 0 ) ) );
	$c .= yume_fx_t( 'Texte centré.', array( 'jc' => 'center' ) );
	$c .= yume_fx_t( '***' );
	$c .= yume_fx_t( 'Après le premier séparateur.' );
	$c .= yume_fx_t( '* * *' );
	$c .= yume_fx_t( 'Après le deuxième séparateur.' );
	$c .= yume_fx_t( '◇' );
	$c .= yume_fx_t( 'Après le losange.' );
	$c .= yume_fx_t( '✿', array( 'jc' => 'center' ) );
	$c .= yume_fx_t( 'Après la fleur.' );
	$c .= yume_fx_p( '' ) . yume_fx_p( '' );
	$c .= yume_fx_t( 'Après deux lignes vides.' );
	$c .= yume_fx_p( '' );
	$c .= yume_fx_t( 'Après une seule ligne vide.' );
	$c .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'grande.png' ), 'Illustration de la brume' ) );
	$c .= yume_fx_p( yume_fx_r( 'Une image en ligne ' ) . yume_fx_dessin( yume_fx_rid( 'petite.gif' ) ) . yume_fx_r( ' au milieu du texte.' ) );
	$c .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'photo.webp' ) ) );
	$c .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'ornement.emf' ) ) );
	$c .= yume_fx_p( yume_fx_dessin( 'rIdImageLiee' ) );
	$c .= yume_fx_p( yume_fx_r( 'Le mot yume' ) . '<w:r><w:rPr><w:rStyle w:val="Appelnotedebasdep"/></w:rPr><w:footnoteReference w:id="1"/></w:r>' . yume_fx_r( ' et le mot kizuna' ) . '<w:r><w:footnoteReference w:id="2"/></w:r>' . yume_fx_r( '.' ) );
	$c .= yume_fx_t( 'Premier terme = définition.', array( 'style' => 'Paragraphedeliste', 'num' => array( 4 ) ) );
	$c .= yume_fx_t( 'Second terme = définition.', array( 'style' => 'Paragraphedeliste', 'num' => array( 4 ) ) );
	$c .= yume_fx_t( 'Étape numérotée.', array( 'num' => array( 5 ) ) );
	$c .= yume_fx_t( 'Autre étape.', array( 'num' => array( 5 ) ) );
	$c .= yume_fx_p( yume_fx_r( 'Avant le saut de page.' ) . '<w:r><w:br w:type="page"/></w:r>' . yume_fx_r( 'Après le saut de page.' ) );
	$c .= yume_fx_p( '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> HYPERLINK "https://exemple.test" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>' . yume_fx_r( 'Texte du champ' ) . '<w:r><w:fldChar w:fldCharType="end"/></w:r>' . yume_fx_r( ' puis ' ) . '<w:hyperlink r:id="rIdLien">' . yume_fx_r( 'un lien' ) . '</w:hyperlink>' . yume_fx_r( '.' ) );
	$c .= '<w:tbl><w:tr><w:tc>' . yume_fx_t( 'Cellule A' ) . '</w:tc><w:tc>' . yume_fx_t( 'Cellule B' ) . '</w:tc></w:tr></w:tbl>';
	$c .= yume_fx_p( '<w:r><w:pict><v:shape><v:textbox><w:txbxContent>' . yume_fx_t( 'TEXTE DE LA ZONE' ) . '</w:txbxContent></v:textbox></v:shape></w:pict></w:r>' );
	$c .= yume_fx_t( 'Intermède', array( 'style' => 'Titre3' ) );
	$c .= yume_fx_t( 'Fin du chapitre un.' );
	$c .= yume_fx_p( '', array( 'sect' => true ) );

	// --- Titres irréguliers et contrôles de numérotation.
	$c .= yume_fx_t( 'Chapitre2 ', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Le Deuxième Jour', array( 'style' => 'Titre2' ) );
	$c .= yume_fx_t( 'Texte du chapitre deux.' );
	$c .= yume_fx_t( ' Chapitre 3', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte du chapitre trois.' );
	$c .= yume_fx_t( 'Chapitre 3', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte du chapitre trois en double.' );
	$c .= yume_fx_t( 'Chapitre 5', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte du chapitre cinq.' );
	$c .= yume_fx_t( 'Chapitre 5 bis', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte du chapitre cinq bis.' );
	$c .= yume_fx_t( 'Chapitre 6', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_p( '' );
	$c .= yume_fx_t( 'Interlude', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Pendant ce temps', array( 'style' => 'Titre2' ) );
	$c .= yume_fx_t( 'Texte de l’interlude.' );
	$c .= yume_fx_t( 'Chapitre 7 : Le titre sur une ligne', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte du chapitre sept.' );
	$c .= yume_fx_t( 'La Chambre Rouge', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte d’un chapitre sans numéro.' );
	$c .= yume_fx_t( 'Épilogue', array( 'style' => 'Titre1' ) );
	$c .= yume_fx_t( 'Texte de l’épilogue.' );
	$c .= yume_fx_t( 'PostFace', array( 'style' => 'Titre2' ) );
	$c .= yume_fx_t( 'Merci de nous avoir lus !' );
	$c .= yume_fx_t( 'L’équipe', array( 'jc' => 'right' ) );

	$notes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:footnotes ' . YUME_FX_NS . '>'
		. '<w:footnote w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:footnote>'
		. '<w:footnote w:type="continuationSeparator" w:id="0"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:footnote>'
		. '<w:footnote w:id="1"><w:p><w:r><w:footnoteRef/></w:r>' . yume_fx_r( ' Yume signifie « rêve ».' ) . '</w:p></w:footnote>'
		. '<w:footnote w:id="2"><w:p><w:r><w:footnoteRef/></w:r>' . yume_fx_r( ' Le lien, en ' ) . yume_fx_r( 'japonais', array( 'i' => true ) ) . yume_fx_r( '.' ) . '</w:p></w:footnote>'
		. '</w:footnotes>';
	$entete = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . YUME_FX_NS . '>' . yume_fx_t( 'EN-TÊTE À NE PAS REPRENDRE' ) . '</w:hdr>';

	$fichier = $dossier . '/regles.docx';
	yume_fx_docx(
		$fichier,
		$c,
		yume_fx_styles_fr(),
		yume_fx_numerotation_fr(),
		array(
			'ornement.emf'    => "\x01\x00\x00\x00\x6C\x00\x00\x00" . str_repeat( "\x00", 100 ),
			'couverture.jpeg' => yume_fx_image( 'jpeg', 400, 600 ),
			'couleur.png'     => yume_fx_image( 'png', 600, 400 ),
			'grande.png'      => yume_fx_image( 'png', 2400, 1600 ),
			'petite.gif'      => yume_fx_image( 'gif', 40, 30 ),
			'photo.webp'      => yume_fx_image( 'webp', 320, 200 ),
		),
		array(
			'word/footnotes.xml' => $notes,
			'word/header1.xml'   => $entete,
		)
	);
	return $fichier;
}

/**
 * styles-variantes.docx : styles anglais, outlineLvl, « Thought », « Dialogue », Symbol.
 *
 * @param string $dossier Dossier de sortie.
 */
function yume_fx_variantes( string $dossier ): string {
	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
		. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
		. '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/></w:style>'
		. '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/></w:style>'
		. '<w:style w:type="paragraph" w:customStyle="1" w:styleId="ChapterTitle"><w:name w:val="Chapter Title"/><w:basedOn w:val="Normal"/><w:pPr><w:outlineLvl w:val="0"/></w:pPr></w:style>'
		. '<w:style w:type="paragraph" w:customStyle="1" w:styleId="MonTitre"><w:name w:val="Mon titre"/><w:basedOn w:val="Heading1"/></w:style>'
		. '<w:style w:type="paragraph" w:customStyle="1" w:styleId="Thought"><w:name w:val="Thought"/><w:basedOn w:val="Normal"/><w:rPr><w:i/></w:rPr></w:style>'
		. '<w:style w:type="paragraph" w:customStyle="1" w:styleId="Dialogue"><w:name w:val="Dialogue"/><w:basedOn w:val="Normal"/></w:style>'
		. '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/></w:style>'
		. '</w:styles>';
	$num    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
		. '<w:abstractNum w:abstractNumId="10">' . yume_fx_niveau( 'bullet', "\u{F02D}", 'Symbol' ) . '</w:abstractNum>'
		. '<w:num w:numId="3"><w:abstractNumId w:val="10"/></w:num></w:numbering>';
	$c      = yume_fx_t( 'Chapter 1', array( 'style' => 'ChapterTitle' ) );
	$c     .= yume_fx_t( 'The Beginning', array( 'style' => 'Heading2' ) );
	$c     .= yume_fx_t( 'Réplique avec une puce Symbol.', array( 'style' => 'ListParagraph', 'num' => array( 3 ) ) );
	$c     .= yume_fx_t( 'Pensée au style anglais.', array( 'style' => 'Thought' ) );
	$c     .= yume_fx_t( 'Réplique au style Dialogue.', array( 'style' => 'Dialogue' ) );
	$c     .= yume_fx_t( 'Chapitre II', array( 'style' => 'Heading1' ) );
	$c     .= yume_fx_t( 'Texte du chapitre deux.' );
	$c     .= yume_fx_t( 'Chapitre 3', array( 'outline' => 0 ) );
	$c     .= yume_fx_t( 'Texte du chapitre trois.' );
	$c     .= yume_fx_t( 'Chapitre 4', array( 'style' => 'MonTitre' ) );
	$c     .= yume_fx_t( 'Texte du chapitre quatre.' );
	$fichier = $dossier . '/styles-variantes.docx';
	yume_fx_docx( $fichier, $c, $styles, $num, array() );
	return $fichier;
}

/**
 * sans-titre.docx : aucun titre de chapitre.
 *
 * @param string $dossier Dossier de sortie.
 */
function yume_fx_sans_titre( string $dossier ): string {
	$styles  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
	$c       = yume_fx_t( 'Un court texte sans aucun titre.' );
	$c      .= yume_fx_p( yume_fx_dessin( yume_fx_rid( 'image.png' ) ) );
	$c      .= yume_fx_t( '— Une réplique.' );
	$c      .= yume_fx_t( 'Fin du texte.' );
	$fichier = $dossier . '/sans-titre.docx';
	yume_fx_docx( $fichier, $c, $styles, '', array( 'image.png' => yume_fx_image( 'png', 300, 200 ) ) );
	return $fichier;
}

/**
 * Page XHTML d'un EPUB.
 *
 * @param string $titre Titre de la page.
 * @param string $corps Contenu du body.
 * @param bool   $dtd   Avec une déclaration DOCTYPE XHTML 1.1 (entités nommées).
 */
function yume_fx_xhtml( string $titre, string $corps, bool $dtd = false ): string {
	return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. ( $dtd ? '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">' . "\n" : '<!DOCTYPE html>' . "\n" )
		. '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="fr"><head><title>' . yume_fx_x( $titre ) . '</title></head><body>' . $corps . '</body></html>';
}

/**
 * livre-epub3.epub.
 *
 * @param string $dossier Dossier de sortie.
 */
function yume_fx_epub3( string $dossier ): string {
	$opf = '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">yume-test-epub3</dc:identifier><dc:title>Livre de test</dc:title><dc:language>fr</dc:language></metadata><manifest>'
		. '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
		. '<item id="couv-img" href="images/couverture.jpg" media-type="image/jpeg" properties="cover-image"/>'
		. '<item id="couv" href="couverture.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="titre" href="titre.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="couleur" href="couleur.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="p1" href="texte/prologue.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="c1" href="texte/chapitre1.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="c1b" href="texte/chapitre1-suite.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="c23" href="texte/chapitres2-3.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="notes" href="texte/notes.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="post" href="texte/postface.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="colophon" href="colophon.xhtml" media-type="application/xhtml+xml"/>'
		. '<item id="img1" href="images/illustration.png" media-type="image/png"/>'
		. '<item id="img2" href="images/encart.jpg" media-type="image/jpeg"/>'
		. '<item id="img3" href="images/couleur.png" media-type="image/png"/>'
		. '<item id="svg" href="images/schema.svg" media-type="image/svg+xml"/>'
		. '</manifest><spine><itemref idref="couv"/><itemref idref="nav"/><itemref idref="titre"/><itemref idref="couleur"/><itemref idref="p1"/><itemref idref="c1"/><itemref idref="c1b"/><itemref idref="c23"/><itemref idref="notes"/><itemref idref="post"/><itemref idref="colophon"/></spine></package>';
	$nav = yume_fx_xhtml(
		'Sommaire',
		'<nav epub:type="toc"><h1>Sommaire</h1><ol><li><a href="texte/prologue.xhtml">Prologue</a></li><li><a href="texte/chapitre1.xhtml">Chapitre 1</a></li><li><a href="texte/chapitres2-3.xhtml">Chapitre 2</a></li><li><a href="texte/chapitres2-3.xhtml#c3">Chapitre 3</a></li><li><a href="texte/postface.xhtml">Postface</a></li></ol></nav>'
		. '<nav epub:type="landmarks"><ol><li><a epub:type="cover" href="couverture.xhtml">Couverture</a></li><li><a epub:type="titlepage" href="titre.xhtml">Titre</a></li><li><a epub:type="bodymatter" href="texte/prologue.xhtml">Début</a></li><li><a epub:type="copyright-page" href="colophon.xhtml">Colophon</a></li></ol></nav>'
	);
	$ch1 = yume_fx_xhtml(
		'Chapitre 1',
		'<section epub:type="chapter"><h1>Chapitre 1</h1><h2>La Crête Brumeuse</h2>'
		. '<p class="dialogue">Réplique par la classe dialogue.</p>'
		. '<p>– Réplique tapée avec un demi-cadratin.</p>'
		. '<p class="pensee">Pensée par la classe, <em>dit-il</em>.</p>'
		. '<p><em>Pensée entièrement en italique.</em></p>'
		. '<p class="centre">Texte centré par la classe.</p>'
		. '<p style="text-align: center">Texte centré par le style.</p>'
		. '<p>Un mot en <span class="italic">italique</span>, un en <b>gras</b> et un appel<a epub:type="noteref" href="#n1">1</a>.</p>'
		. '<hr/>'
		. '<p>Après le filet.</p>'
		. '<p>* * *</p>'
		. '<p>Après les astérisques&nbsp;: la suite&hellip;</p>'
		. '<figure><img src="../images/illustration.png" alt="Illustration du chapitre"/></figure>'
		. '<ul><li>Premier élément</li><li>Second élément</li></ul>'
		. '<blockquote><p>Une citation.</p></blockquote>'
		. '<aside epub:type="footnote" id="n1"><p><a href="#r1">1</a> Note du chapitre un.</p></aside>'
		. '</section>',
		true
	);
	$suite = yume_fx_xhtml( 'Chapitre 1 (suite)', '<div class="illus"><img src="../images/encart.jpg" alt=""/></div><p>Suite du chapitre un, sans titre.</p><p>Une image vectorielle : <img src="../images/schema.svg" alt="Schéma"/></p>' );
	$ch23  = yume_fx_xhtml( 'Chapitres 2 et 3', '<h1>Chapitre 2<br/>Le Deuxième Jour</h1><p>Texte du chapitre deux<a href="notes.xhtml#en1" epub:type="noteref"><sup>1</sup></a>.</p><h1 id="c3">Chapitre 3</h1><p class="subtitle">Le Troisième Jour</p><p>Texte du chapitre trois.</p>' );
	$notes = yume_fx_xhtml( 'Notes', '<section epub:type="endnotes"><h1>Notes</h1><ol><li id="en1"><p>Note de fin du chapitre deux. <a epub:type="backlink" href="chapitres2-3.xhtml">↩</a></p></li></ol></section>' );
	$files = array(
		'mimetype'                   => 'application/epub+zip',
		'META-INF/container.xml'     => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
		'OEBPS/content.opf'          => $opf,
		'OEBPS/nav.xhtml'            => $nav,
		'OEBPS/couverture.xhtml'     => yume_fx_xhtml( 'Couverture', '<div><img src="images/couverture.jpg" alt="Couverture"/></div>' ),
		'OEBPS/titre.xhtml'          => yume_fx_xhtml( 'Titre', '<h1>Livre de test</h1><p>Traduction : équipe Yume</p>' ),
		'OEBPS/couleur.xhtml'        => yume_fx_xhtml( 'Illustration couleur', '<div><img src="images/couleur.png" alt=""/></div>' ),
		'OEBPS/texte/prologue.xhtml' => yume_fx_xhtml( 'Prologue', '<h1>Prologue</h1><p>Texte du prologue.</p>' ),
		'OEBPS/texte/chapitre1.xhtml' => $ch1,
		'OEBPS/texte/chapitre1-suite.xhtml' => $suite,
		'OEBPS/texte/chapitres2-3.xhtml' => $ch23,
		'OEBPS/texte/notes.xhtml'    => $notes,
		'OEBPS/texte/postface.xhtml' => yume_fx_xhtml( 'Postface', '<h1>Postface</h1><p>Merci de nous avoir lus.</p>' ),
		'OEBPS/colophon.xhtml'       => yume_fx_xhtml( 'Colophon', '<p>COLOPHON À NE PAS REPRENDRE</p>' ),
		'OEBPS/images/couverture.jpg' => yume_fx_image( 'jpeg', 300, 450 ),
		'OEBPS/images/couleur.png'   => yume_fx_image( 'png', 450, 300 ),
		'OEBPS/images/illustration.png' => yume_fx_image( 'png', 800, 1200 ),
		'OEBPS/images/encart.jpg'    => yume_fx_image( 'jpeg', 600, 400 ),
		'OEBPS/images/schema.svg'    => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
	);
	$fichier = $dossier . '/livre-epub3.epub';
	yume_fx_zip( $fichier, $files, array( 'mimetype' ) );
	return $fichier;
}

/**
 * livre-epub2.epub : EPUB 2, NCX, guide, aucun <h1>.
 *
 * @param string $dossier Dossier de sortie.
 */
function yume_fx_epub2( string $dossier ): string {
	$opf = '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf"><dc:identifier id="id">yume-test-epub2</dc:identifier><dc:title>Livre EPUB 2</dc:title><dc:language>fr</dc:language><meta name="cover" content="couv-img"/></metadata><manifest>'
		. '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
		. '<item id="couv-img" href="couv.png" media-type="image/png"/>'
		. '<item id="couv" href="couv.html" media-type="application/xhtml+xml"/>'
		. '<item id="sommaire" href="sommaire.html" media-type="application/xhtml+xml"/>'
		. '<item id="c1" href="c1.html" media-type="application/xhtml+xml"/>'
		. '<item id="c1b" href="c1b.html" media-type="application/xhtml+xml"/>'
		. '<item id="c2" href="c2.html" media-type="application/xhtml+xml"/>'
		. '<item id="annexe" href="annexe.html" media-type="application/xhtml+xml"/>'
		. '</manifest><spine toc="ncx"><itemref idref="couv"/><itemref idref="sommaire"/><itemref idref="c1"/><itemref idref="c1b"/><itemref idref="c2"/><itemref idref="annexe" linear="no"/></spine>'
		. '<guide><reference type="cover" href="couv.html" title="Couverture"/><reference type="toc" href="sommaire.html" title="Sommaire"/><reference type="text" href="c1.html" title="Début"/></guide></package>';
	$ncx = '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap>'
		. '<navPoint id="n1" playOrder="1"><navLabel><text>Chapitre 1 : Le Départ</text></navLabel><content src="c1.html"/></navPoint>'
		. '<navPoint id="n2" playOrder="2"><navLabel><text>Chapitre 2</text></navLabel><content src="c2.html"/></navPoint>'
		. '</navMap></ncx>';
	$files = array(
		'mimetype'               => 'application/epub+zip',
		'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
		'content.opf'            => $opf,
		'toc.ncx'                => $ncx,
		'couv.png'               => yume_fx_image( 'png', 300, 450 ),
		'couv.html'              => yume_fx_xhtml( 'Couverture', '<div><img src="couv.png" alt=""/></div>', true ),
		'sommaire.html'          => yume_fx_xhtml( 'Sommaire', '<p>SOMMAIRE À NE PAS REPRENDRE</p>', true ),
		'c1.html'                => yume_fx_xhtml( 'Chapitre 1', '<p>Premier paragraphe&nbsp;!</p><p>- Réplique au trait d’union.</p><p><span class="italic">Pensée en italique par la classe.</span></p><p style="text-align:center">Centré.</p>', true ),
		'c1b.html'               => yume_fx_xhtml( 'Suite', '<p>Suite du chapitre un.</p>', true ),
		'c2.html'                => yume_fx_xhtml( 'Chapitre 2', '<div class="texte"><p>Texte du chapitre deux.</p>Texte flottant dans un bloc.</div>', true ),
		'annexe.html'            => yume_fx_xhtml( 'Annexe', '<p>ANNEXE NON LINÉAIRE</p>', true ),
	);
	$fichier = $dossier . '/livre-epub2.epub';
	yume_fx_zip( $fichier, $files, array( 'mimetype' ) );
	return $fichier;
}

/**
 * Fichiers refusés par le convertisseur.
 *
 * @param string $dossier Dossier de sortie.
 * @return string[]
 */
function yume_fx_invalides( string $dossier ): array {
	yume_fx_zip( $dossier . '/invalide.docx', array( 'lisez-moi.txt' => 'Archive ZIP sans document Word.' ) );
	file_put_contents( $dossier . '/faux.docx', "Ceci n'est pas une archive.\n" );
	file_put_contents( $dossier . '/chiffre.docx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\x00", 504 ) );
	return array( $dossier . '/invalide.docx', $dossier . '/faux.docx', $dossier . '/chiffre.docx' );
}

/**
 * Génère toutes les fixtures dans un dossier.
 *
 * @param string $dossier Dossier de sortie (créé au besoin).
 * @return string[] Fichiers écrits.
 */
function yume_fixtures_construire( string $dossier ): array {
	if ( ! is_dir( $dossier ) ) {
		mkdir( $dossier, 0775, true );
	}
	return array_merge(
		array(
			yume_fx_regles( $dossier ),
			yume_fx_variantes( $dossier ),
			yume_fx_sans_titre( $dossier ),
			yume_fx_epub3( $dossier ),
			yume_fx_epub2( $dossier ),
		),
		yume_fx_invalides( $dossier )
	);
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$yume_fx_dossier = $argv[1] ?? __DIR__;
	foreach ( yume_fixtures_construire( $yume_fx_dossier ) as $yume_fx_fichier ) {
		echo basename( $yume_fx_fichier ), ' (', filesize( $yume_fx_fichier ), " octets)\n";
	}
}
