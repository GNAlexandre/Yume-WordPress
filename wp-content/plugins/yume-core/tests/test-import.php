<?php
/**
 * Tests du module import : Docx_Converter, Epub_Converter et outils (Texte, Inline, Blocks, Zip).
 *
 * Fixtures synthétiques : tools/fixtures/*.docx|*.epub (générées par
 * tools/fixtures/build-fixtures.php). Test conditionnel sur le DOCX de travail
 * tools/fixtures/private/grimgar-t7.docx s'il est présent (jamais commité).
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Blocks;
use Yume\Core\Import\Chapter_Builder;
use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Epub_Converter;
use Yume\Core\Import\Import_Exception;
use Yume\Core\Import\Inline;
use Yume\Core\Import\Result;
use Yume\Core\Import\Texte;
use Yume\Core\Import\Zip;

defined( 'ABSPATH' ) || exit;

require_once YUME_CORE_DIR . 'includes/import/autoload.php';

if ( ! function_exists( 'yume_timp_fixture' ) ) {
	/**
	 * Chemin d'une fixture synthétique ; régénère les fixtures dans un dossier temporaire si
	 * le dépôt n'est pas disponible (installation par archive).
	 *
	 * @param string $nom Nom du fichier.
	 * @throws Yume_Test_Failure Fixture introuvable.
	 */
	function yume_timp_fixture( string $nom ): string {
		static $dossier = null;
		if ( null === $dossier ) {
			$depot   = dirname( YUME_CORE_DIR, 3 ) . '/tools/fixtures';
			$dossier = is_file( $depot . '/regles.docx' ) ? $depot : '';
			if ( '' === $dossier && is_file( $depot . '/build-fixtures.php' ) ) {
				require_once $depot . '/build-fixtures.php';
				$dossier = get_temp_dir() . 'yume-fixtures-' . getmypid();
				yume_fixtures_construire( $dossier );
			}
		}
		if ( '' === $dossier || ! is_file( $dossier . '/' . $nom ) ) {
			throw new Yume_Test_Failure( 'Fixture introuvable : ' . $nom . ' (lancez php tools/fixtures/build-fixtures.php).' );
		}
		return $dossier . '/' . $nom;
	}

	/**
	 * Chemin du DOCX de travail privé, ou chaîne vide s'il est absent.
	 */
	function yume_timp_docx_prive(): string {
		$chemin = dirname( YUME_CORE_DIR, 3 ) . '/tools/fixtures/private/grimgar-t7.docx';
		return is_readable( $chemin ) ? $chemin : '';
	}

	/**
	 * Conversion mise en cache pour la durée des tests.
	 *
	 * @param string $nom Fixture.
	 */
	function yume_timp_conversion( string $nom ): Result {
		static $cache = array();
		if ( ! isset( $cache[ $nom ] ) ) {
			$chemin        = is_file( $nom ) ? $nom : yume_timp_fixture( $nom );
			$cache[ $nom ] = str_ends_with( $nom, '.epub' ) ? Epub_Converter::convert_file( $chemin ) : Docx_Converter::convert_file( $chemin );
		}
		return $cache[ $nom ];
	}

	/**
	 * Chapitre d'un Result par titre.
	 *
	 * @param Result $r     Résultat.
	 * @param string $titre Titre (« Chapitre 1 », « Postface »).
	 * @return array<string,mixed>
	 * @throws Yume_Test_Failure Chapitre absent.
	 */
	function yume_timp_chapitre( Result $r, string $titre ): array {
		foreach ( $r->chapters as $c ) {
			if ( $c['titre'] === $titre ) {
				return $c;
			}
		}
		throw new Yume_Test_Failure( 'Chapitre absent : ' . $titre );
	}

	/**
	 * Vérifie que des blocs sont valides : aller-retour parse/serialize identique, aucun
	 * contenu libre hors bloc, uniquement des blocs attendus.
	 *
	 * @param string $blocs Balisage.
	 * @param string $nom   Nom pour les messages.
	 */
	function yume_timp_blocs_valides( string $blocs, string $nom ): void {
		$analyse = parse_blocks( $blocs );
		yume_assert_same( $blocs, serialize_blocks( $analyse ), $nom . ' : aller-retour parse_blocks/serialize_blocks' );
		$permis = array( 'core/paragraph', 'core/separator', 'core/image', 'core/list', 'core/list-item', 'core/heading', 'core/quote' );
		$pile   = $analyse;
		while ( $pile ) {
			$bloc = array_shift( $pile );
			if ( null === $bloc['blockName'] ) {
				yume_assert_same( '', trim( $bloc['innerHTML'] ), $nom . ' : contenu hors bloc' );
				continue;
			}
			yume_assert_true( in_array( $bloc['blockName'], $permis, true ), $nom . ' : bloc inattendu ' . $bloc['blockName'] );
			$pile = array_merge( $pile, $bloc['innerBlocks'] );
		}
	}
}

if ( ! function_exists( 'yume_timp_docx' ) ) {
	/**
	 * Charge les fonctions de construction de fixtures (yume_fx_*).
	 *
	 * @throws Yume_Test_Failure Outil de fixtures absent.
	 */
	function yume_timp_outils_fixtures(): void {
		if ( function_exists( 'yume_fx_docx' ) ) {
			return;
		}
		$outil = dirname( YUME_CORE_DIR, 3 ) . '/tools/fixtures/build-fixtures.php';
		if ( ! is_file( $outil ) ) {
			throw new Yume_Test_Failure( 'tools/fixtures/build-fixtures.php introuvable.' );
		}
		require_once $outil;
	}

	/**
	 * Construit un DOCX temporaire (styles français, titres Titre1) à partir du contenu de
	 * w:body, avec les fonctions de tools/fixtures/build-fixtures.php.
	 *
	 * @param string $corps Contenu de w:body (yume_fx_t(), yume_fx_p()…).
	 * @return string Chemin du fichier (à supprimer par l'appelant).
	 * @throws Yume_Test_Failure Outil de fixtures absent.
	 */
	function yume_timp_docx( string $corps ): string {
		yume_timp_outils_fixtures();
		$fichier = get_temp_dir() . 'yume-test-' . wp_generate_password( 8, false ) . '.docx';
		yume_fx_docx( $fichier, $corps, yume_fx_styles_fr(), '', array() );
		return $fichier;
	}

	/**
	 * Construit un EPUB 3 minimal (un fichier XHTML par page, feuilles de style de
	 * OEBPS/styles/) dans un fichier temporaire.
	 *
	 * @param array<string,array{0:string,1:string}> $pages   Nom → [ajout dans <head>, contenu de <body>].
	 * @param array<string,string>                   $feuilles Nom → CSS.
	 * @return string Chemin (à supprimer par l'appelant).
	 */
	function yume_timp_epub( array $pages, array $feuilles = array() ): string {
		$manifeste = '';
		$spine     = '';
		$entrees   = array(
			'mimetype'               => 'application/epub+zip',
			'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
		);
		$i         = 0;
		foreach ( $feuilles as $nom => $css ) {
			$manifeste                        .= '<item id="css' . ( ++$i ) . '" href="styles/' . $nom . '" media-type="text/css"/>';
			$entrees[ 'OEBPS/styles/' . $nom ] = $css;
		}
		foreach ( $pages as $nom => $page ) {
			$manifeste                       .= '<item id="p' . ( ++$i ) . '" href="texte/' . $nom . '" media-type="application/xhtml+xml"/>';
			$spine                           .= '<itemref idref="p' . $i . '"/>';
			$entrees[ 'OEBPS/texte/' . $nom ] = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>' . $nom . '</title>' . $page[0] . '</head><body>' . $page[1] . '</body></html>';
		}
		$entrees['OEBPS/content.opf'] = '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">yume-test-css</dc:identifier><dc:title>Styles</dc:title><dc:language>fr</dc:language></metadata><manifest>' . $manifeste . '</manifest><spine>' . $spine . '</spine></package>';
		$fichier                      = get_temp_dir() . 'yume-test-' . wp_generate_password( 8, false ) . '.epub';
		$zip                          = new ZipArchive();
		$zip->open( $fichier, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $entrees as $nom => $contenu ) {
			$zip->addFromString( $nom, $contenu );
		}
		$zip->close();
		return $fichier;
	}
}

// Inclusion par test-publication.php pour ses seules fonctions d'aide.
if ( ! empty( $GLOBALS['yume_tests_import_aides_seules'] ) ) {
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Outils de texte
 * -----------------------------------------------------------------------------
 */

yume_test(
	'analyse des titres irréguliers',
	function () {
		$cas = array(
			'Chapitre 1'            => array( 'chapitre', 'chapitre', 1.0, 'Chapitre 1', '' ),
			'Chapitre2 '            => array( 'chapitre', 'chapitre', 2.0, 'Chapitre 2', '' ),
			' Chapitre 13'          => array( 'chapitre', 'chapitre', 13.0, 'Chapitre 13', '' ),
			'Chapitre 12 bis'       => array( 'chapitre', 'chapitre', 12.5, 'Chapitre 12 bis', '' ),
			'Chapitre 12.5'         => array( 'chapitre', 'chapitre', 12.5, 'Chapitre 12,5', '' ),
			'Chapitre IV'           => array( 'chapitre', 'chapitre', 4.0, 'Chapitre 4', '' ),
			'chapitre dix-sept'     => array( 'chapitre', 'chapitre', 17.0, 'Chapitre 17', '' ),
			'Chapter 3'             => array( 'chapitre', 'chapitre', 3.0, 'Chapitre 3', '' ),
			'Chapitre 3 : Le titre' => array( 'chapitre', 'chapitre', 3.0, 'Chapitre 3', 'Le titre' ),
			'Prologue'              => array( 'special', 'prologue', 0.0, 'Prologue', '' ),
			'ÉPILOGUE'              => array( 'special', 'epilogue', null, 'Épilogue', '' ),
			'Interlude 2'           => array( 'special', 'interlude', 2.0, 'Interlude 2', '' ),
			'PostFace'              => array( 'special', 'postface', null, 'Postface', '' ),
			'Extraordinaire'        => array( 'inconnu', 'chapitre', null, 'Extraordinaire', '' ),
			'La Crête Brumeuse'     => array( 'inconnu', 'chapitre', null, 'La Crête Brumeuse', '' ),
		);
		foreach ( $cas as $brut => $attendu ) {
			$a = Texte::analyser_titre( $brut );
			yume_assert_same( $attendu, array( $a['motif'], $a['nature'], $a['numero'], $a['titre'], $a['sous_titre'] ), 'Titre « ' . $brut . ' »' );
		}
		yume_assert_true( in_array( 'espace_manquante', Texte::analyser_titre( 'Chapitre2' )['corrections'], true ) );
		yume_assert_true( in_array( 'libelle', Texte::analyser_titre( 'Chapter 3' )['corrections'], true ) );
	}
);

yume_test(
	'séparateurs, tirets et typographie française',
	function () {
		foreach ( array( '***', '* * *', '◇', '✿ ✿ ✿', '#', '◆◆◆' ) as $sep ) {
			yume_assert_true( Texte::est_separateur( $sep ), 'Séparateur ' . $sep );
		}
		foreach ( array( '…', 'Ok', '— Oui', '' ) as $pas ) {
			yume_assert_false( Texte::est_separateur( $pas ), 'Pas un séparateur : ' . $pas );
		}
		yume_assert_true( Texte::commence_par_tiret( '— Oui' ) );
		yume_assert_true( Texte::commence_par_tiret( '-Non' ) );
		yume_assert_true( Texte::commence_par_tiret( '– Peut-être' ) );
		yume_assert_false( Texte::commence_par_tiret( '-1 degré' ) );
		yume_assert_false( Texte::commence_par_tiret( '—' ) );
		yume_assert_same( "—\u{00A0}Quoi", Texte::normaliser_dialogue( '- Quoi', false ) );
		yume_assert_same( "<em>—\u{00A0}Quoi</em>", Texte::normaliser_dialogue( '<em>-Quoi</em>', false ) );
		yume_assert_same( "—\u{00A0}Quoi", Texte::normaliser_dialogue( 'Quoi', true ) );
		yume_assert_same( 'Quoi', Texte::normaliser_dialogue( 'Quoi', false ) );
		yume_assert_same( "Oui\u{00A0}? «\u{00A0}Non\u{00A0}» et<em>\u{00A0}!</em>", Texte::typographie( 'Oui ? « Non » et<em> !</em>' ) );
		yume_assert_same( "a\u{00A0}<em>?</em>", Texte::typographie( 'a <em>?</em>' ) );
		yume_assert_same( 6, Texte::compter_mots( '<p>L’homme est là, c’est-à-dire 12 fois.</p><!-- wp:x -->' ) );
		if ( function_exists( 'Yume\Core\Core\compter_mots' ) ) {
			$texte = '<p>Réplique — « Oui ! » dit-elle, l’air ravi : 3 fois.</p>';
			yume_assert_same( \Yume\Core\Core\compter_mots( $texte ), Texte::compter_mots( $texte ), 'Même décompte que core' );
		}
	}
);

yume_test(
	'runs : fusion, imbrication minimale, ponctuation isolée neutralisée',
	function () {
		$html = Inline::html(
			array(
				Inline::texte( 'Il ' ),
				Inline::texte( 'était ' ),
				Inline::texte( 'je ', array( 'i' => true ) ),
				Inline::texte(
					'dois',
					array(
						'i' => true,
						'b' => true,
					)
				),
				Inline::texte( ' partir', array( 'i' => true ) ),
				Inline::texte( ',', array( 'b' => true ) ),
				Inline::texte( ' 1' ),
				Inline::texte( 'er', array( 'va' => 'sup' ) ),
				Inline::saut(),
				Inline::texte( "\tfin  <b> & ", array() ),
			)
		);
		yume_assert_same( 'Il était <em>je <strong>dois</strong> partir</em>, 1<sup>er</sup><br>fin &lt;b&gt; &amp;', $html );
		yume_assert_same( '<em>Pensa-t-il</em>, vite.', Inline::html( array( Inline::texte( 'Pensa-t-il' ), Inline::texte( ', vite.', array( 'i' => true ) ) ), true ) );
		yume_assert_same( '', Inline::html( array( Inline::texte( '   ' ), Inline::saut() ) ) );
	}
);

yume_test(
	'blocs : attributs sérialisés comme WordPress et remplacement des jetons d’image',
	function () {
		$attrs = array(
			'className' => 'a--b <c> & "d"',
			'align'     => 'center',
		);
		yume_assert_same( serialize_block_attributes( $attrs ), Blocks::attributs( $attrs ) );
		$blocs = Blocks::assembler( array( Blocks::paragraphe( 'Texte' ), Blocks::image_jeton( 'img-1', 'Alt « x »' ), Blocks::paragraphe( 'Suite' ) ) );
		yume_assert_same( array( 'img-1' ), Blocks::cles_images( $blocs ) );
		$remplace = Blocks::remplacer_images(
			$blocs,
			static function ( string $cle, string $alt ): string {
				return Blocks::image( 42, 'https://exemple.test/i.webp', $alt );
			}
		);
		yume_assert_contains( '<!-- wp:image {"id":42,"sizeSlug":"large","linkDestination":"none","className":"yn-illustration"} -->', $remplace );
		yume_assert_contains( 'class="wp-image-42"', $remplace );
		yume_assert_contains( 'alt="Alt « x »"', $remplace );
		yume_assert_not_contains( '{{yume-image', $remplace );
		$retire = Blocks::remplacer_images( $blocs, static fn() => '' );
		yume_assert_same( Blocks::assembler( array( Blocks::paragraphe( 'Texte' ), Blocks::paragraphe( 'Suite' ) ) ), $retire );
		yume_timp_blocs_valides( $remplace, 'remplacement' );
	}
);

yume_test(
	'zip : chemins normalisés, jamais hors de l’archive',
	function () {
		yume_assert_same( 'word/media/image1.png', Zip::resoudre( 'word/document.xml', 'media/image1.png' ) );
		yume_assert_same( 'word/media/image1.png', Zip::resoudre( 'word/document.xml', '/word/media/image1.png' ) );
		yume_assert_same( 'OEBPS/images/a.jpg', Zip::resoudre( 'OEBPS/texte/c1.xhtml', '../images/a.jpg#x' ) );
		yume_assert_same( '', Zip::resoudre( 'word/document.xml', '../../../etc/passwd' ) );
		yume_assert_same( 'a b.png', Zip::normaliser( 'a%20b.png' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * DOCX synthétique : toutes les règles
 * -----------------------------------------------------------------------------
 */

yume_test(
	'docx : découpage en chapitres, titres irréguliers et PostFace',
	function () {
		$r      = yume_timp_conversion( 'regles.docx' );
		$titres = array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters );
		yume_assert_same(
			array(
				array( 'prologue', 0.0, 'Prologue', '' ),
				array( 'chapitre', 1.0, 'Chapitre 1', 'La Crête Brumeuse' ),
				array( 'chapitre', 2.0, 'Chapitre 2', 'Le Deuxième Jour' ),
				array( 'chapitre', 3.0, 'Chapitre 3', '' ),
				array( 'chapitre', 3.0, 'Chapitre 3', '' ),
				array( 'chapitre', 5.0, 'Chapitre 5', '' ),
				array( 'chapitre', 5.5, 'Chapitre 5 bis', '' ),
				array( 'chapitre', 6.0, 'Chapitre 6', '' ),
				array( 'interlude', null, 'Interlude', 'Pendant ce temps' ),
				array( 'chapitre', 7.0, 'Chapitre 7', 'Le titre sur une ligne' ),
				array( 'chapitre', 8.0, 'Chapitre 8', 'La Chambre Rouge' ),
				array( 'epilogue', null, 'Épilogue', '' ),
				array( 'postface', null, 'Postface', '' ),
			),
			$titres
		);
		$avert = implode( "\n", $r->warnings );
		foreach ( array(
			'Chapitre 2 : titre « Chapitre2 » sans espace — corrigé automatiquement.',
			'Chapitre 3 : titre précédé d’une espace — corrigé automatiquement.',
			'Chapitre 3 : numéro en double',
			'Chapitre 4 absent',
			'Chapitre 5 bis : numéroté 5,5',
			'Chapitre 6 : chapitre vide',
			'Titre « La Chambre Rouge » sans numéro de chapitre : numéroté 8',
			'1 paragraphe placé avant le premier chapitre a été ignoré.',
			'zone(s) de texte',
			'image liée',
		) as $attendu ) {
			yume_assert_contains( $attendu, $avert );
		}
		yume_assert_same( 13, $r->stats['chapitres'] );
		yume_assert_same( 'docx', $r->stats['format'] );
		yume_assert_same( sha1_file( yume_timp_fixture( 'regles.docx' ) ), $r->stats['hash'] );
	}
);

yume_test(
	'docx : dialogues (puces « — », « - », « – », style de numérotation, tirets tapés)',
	function () {
		$c = yume_timp_chapitre( yume_timp_conversion( 'regles.docx' ), 'Chapitre 1' );
		yume_assert_same( 6, $c['stats']['dialogues'] );
		preg_match_all( '#<p class="yn-dialogue">([^<]*)</p>#u', $c['blocks'], $m );
		yume_assert_same( 6, count( $m[1] ) );
		foreach ( $m[1] as $texte ) {
			yume_assert_true( str_starts_with( $texte, "—\u{00A0}Réplique" ), 'Tiret normalisé : ' . $texte );
		}
		yume_assert_contains( '<!-- wp:paragraph {"className":"yn-dialogue"} -->', $c['blocks'] );
	}
);

yume_test(
	'docx : pensées (style Pensée, style de caractère) et italique inversé',
	function () {
		$c = yume_timp_chapitre( yume_timp_conversion( 'regles.docx' ), 'Chapitre 1' );
		yume_assert_same( 2, $c['stats']['pensees'] );
		yume_assert_contains( '<p class="yn-thought">Quelle étrange brume, <em>pensa-t-il</em>. Je dois avancer.</p>', $c['blocks'] );
		yume_assert_contains( '<p class="yn-thought">Allez, <em>se dit-elle</em>, encore un effort.</p>', $c['blocks'] );
	}
);

yume_test(
	'docx : centrage, séparateurs, vides compactés, listes',
	function () {
		$c = yume_timp_chapitre( yume_timp_conversion( 'regles.docx' ), 'Chapitre 1' );
		yume_assert_contains( "<!-- wp:paragraph {\"align\":\"center\",\"className\":\"yn-center\"} -->\n<p class=\"has-text-align-center yn-center\">Texte centré.</p>", $c['blocks'] );
		yume_assert_same( 5, $c['stats']['separateurs'], '***, * * *, ◇, ✿ et deux lignes vides' );
		yume_assert_same( 5, substr_count( $c['blocks'], '<hr class="wp-block-separator has-alpha-channel-opacity yn-scene-break"/>' ) );
		yume_assert_contains( "Après deux lignes vides.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Après une seule ligne vide.", $c['blocks'], 'Une ligne vide seule est ignorée' );
		yume_assert_false( str_starts_with( $c['blocks'], '<!-- wp:separator' ) );
		yume_assert_contains( "<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Premier terme = définition.</li>", $c['blocks'] );
		yume_assert_contains( "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Étape numérotée.</li>", $c['blocks'] );
		yume_assert_contains( '<h3 class="wp-block-heading">Intermède</h3>', $c['blocks'] );
	}
);

yume_test(
	'docx : mise en forme des runs, entités, texte masqué, champs, liens',
	function () {
		$r = yume_timp_conversion( 'regles.docx' );
		$p = yume_timp_chapitre( $r, 'Prologue' )['blocks'];
		yume_assert_contains( '<p>Il était <strong>une</strong> fois, <em>dans un <strong>rêve</strong> lointain</em>, un <u>serment</u> prononcé le 1<sup>er</sup> jour, et H<sub>2</sub>O.</p>', $p );
		yume_assert_contains( '<p>Première ligne<br>seconde ligne après une tabulation.</p>', $p );
		yume_assert_contains( "<p>Vraiment\u{00A0}? Oui\u{00A0}! «\u{00A0}Bonjour\u{00A0}» dit-elle\u{00A0}; puis\u{00A0}: &lt;Yume&gt; &amp; Cie.</p>", $p );
		yume_assert_contains( '<p>Texte visible et suite.</p>', $p );
		$c = yume_timp_chapitre( $r, 'Chapitre 1' )['blocks'];
		yume_assert_contains( '<p>Texte du champ puis <a href="https://yumenovel.fr/">un lien</a>.</p>', $c );
		yume_assert_contains( '<p>Avant le saut de page. Après le saut de page.</p>', $c );
		$tout = implode( '', array_column( $r->chapters, 'blocks' ) );
		foreach ( array( 'MASQUÉ', 'EN-TÊTE', 'TEXTE DE LA ZONE', 'HYPERLINK', 'Table des matières', 'Traduction : équipe Yume' ) as $absent ) {
			yume_assert_not_contains( $absent, $tout );
		}
		yume_assert_same( 1, $r->stats['sauts_de_page'] );
	}
);

yume_test(
	'docx : notes de bas de page (appels et liste yn-notes)',
	function () {
		$c = yume_timp_chapitre( yume_timp_conversion( 'regles.docx' ), 'Chapitre 1' );
		yume_assert_same( 2, $c['stats']['notes'] );
		yume_assert_contains( '<p>Le mot yume<sup class="yn-note"><a href="#yn-note-1" id="yn-ref-1">1</a></sup> et le mot kizuna<sup class="yn-note"><a href="#yn-note-2" id="yn-ref-2">2</a></sup>.</p>', $c['blocks'] );
		yume_assert_contains( '<!-- wp:list {"ordered":true,"className":"yn-notes"} -->', $c['blocks'] );
		yume_assert_contains( "<li id=\"yn-note-1\">Yume signifie «\u{00A0}rêve\u{00A0}». <a href=\"#yn-ref-1\" aria-label=\"Retour au texte\">↩</a></li>", $c['blocks'] );
		yume_assert_contains( '<li id="yn-note-2">Le lien, en <em>japonais</em>.', $c['blocks'] );
		yume_assert_true( str_ends_with( $c['blocks'], '<!-- /wp:list -->' ), 'Notes en fin de chapitre' );
	}
);

yume_test(
	'docx : images gardées, EMF sans bitmap ignorées (raison précise), galerie avant le premier chapitre',
	function () {
		$r = yume_timp_conversion( 'regles.docx' );
		yume_assert_same( array( 'couverture', 'couleur' ), $r->front_images );
		yume_assert_same( array( 'couverture', 'couleur', 'grande', 'petite', 'photo' ), array_keys( $r->images ) );
		yume_assert_same(
			array(
				'nom'        => 'grande.png',
				'mime'       => 'image/png',
				'chemin_zip' => 'word/media/grande.png',
				'largeur'    => 2400,
				'hauteur'    => 1600,
			),
			array_intersect_key( $r->images['grande'], array_flip( array( 'nom', 'mime', 'chemin_zip', 'largeur', 'hauteur' ) ) )
		);
		yume_assert_same( 'Illustration de la brume', $r->images['grande']['alt'] );
		yume_assert_same( 'image/gif', $r->images['petite']['mime'] );
		yume_assert_same( 'image/webp', $r->images['photo']['mime'] );
		yume_assert_same( 2, $r->stats['images_emf'] );
		yume_assert_same( 0, $r->stats['images_emf_converties'] );
		yume_assert_same( 5, $r->stats['images_gardees'] );
		// ornement.emf de la fixture : faux EMF (en-tête sans signature), jamais converti.
		yume_assert_contains( '2 images au format Word EMF/WMF ignorées (aucune image convertible) : ornement.emf (avant le premier chapitre : fichier endommagé ou format non reconnu (ni EMF ni WMF)), ornement.emf (Chapitre 1 : fichier endommagé ou format non reconnu (ni EMF ni WMF)).', implode( "\n", $r->warnings ) );
		$c = yume_timp_chapitre( $r, 'Chapitre 1' );
		yume_assert_same( array( 'grande', 'petite', 'photo' ), $c['images'] );
		yume_assert_same( $c['images'], Blocks::cles_images( $c['blocks'] ) );
		yume_assert_contains( '<img src="{{yume-image:grande}}" alt="Illustration de la brume"/>', $c['blocks'] );
		yume_assert_contains( "<p>Une image en ligne</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:image", $c['blocks'], 'Paragraphe découpé autour de l’image' );
		$copie = wp_tempnam( 'yume-test' );
		yume_assert_true( $r->copier_image( 'grande', $copie ) );
		yume_assert_same( 'image/png', wp_getimagesize( $copie )['mime'] );
		wp_delete_file( $copie );
	}
);

yume_test(
	'docx : nombre de mots par chapitre et blocs Gutenberg valides',
	function () {
		foreach ( array( 'regles.docx', 'styles-variantes.docx', 'sans-titre.docx' ) as $fixture ) {
			$r     = yume_timp_conversion( $fixture );
			$total = 0;
			foreach ( $r->chapters as $c ) {
				yume_assert_same( Texte::compter_mots( $c['blocks'] ), $c['nb_mots'], $fixture . ' ' . $c['titre'] );
				yume_timp_blocs_valides( $c['blocks'], $fixture . ' ' . $c['titre'] );
				$total += $c['nb_mots'];
			}
			yume_assert_same( $total, $r->stats['mots'] );
		}
		yume_assert_same( 0, yume_timp_chapitre( yume_timp_conversion( 'regles.docx' ), 'Chapitre 6' )['nb_mots'] );
	}
);

yume_test(
	'docx : styles anglais, outlineLvl, Thought, Dialogue et puce Symbol',
	function () {
		$r = yume_timp_conversion( 'styles-variantes.docx' );
		yume_assert_same( array( 'Chapitre 1', 'Chapitre 2', 'Chapitre 3', 'Chapitre 4' ), array_column( $r->chapters, 'titre' ) );
		yume_assert_same( 'The Beginning', $r->chapters[0]['sous_titre'] );
		yume_assert_same( 2, $r->chapters[0]['stats']['dialogues'] );
		yume_assert_same( 1, $r->chapters[0]['stats']['pensees'] );
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Réplique avec une puce Symbol.</p>", $r->chapters[0]['blocks'] );
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Réplique au style Dialogue.</p>", $r->chapters[0]['blocks'] );
		yume_assert_contains( '<p class="yn-thought">Pensée au style anglais.</p>', $r->chapters[0]['blocks'] );
		yume_assert_contains( 'Titre « Chapter 1 » renommé « Chapitre 1 ».', implode( "\n", $r->warnings ) );
	}
);

yume_test(
	'docx : sans titre, le document forme un chapitre unique',
	function () {
		$r = yume_timp_conversion( 'sans-titre.docx' );
		yume_assert_same( 1, count( $r->chapters ) );
		yume_assert_same( array( 'chapitre', 1.0, 'Chapitre 1' ), array( $r->chapters[0]['nature'], $r->chapters[0]['numero'], $r->chapters[0]['titre'] ) );
		yume_assert_same( array(), $r->front_images );
		yume_assert_same( array( 'image' ), $r->chapters[0]['images'] );
		yume_assert_same( 1, $r->chapters[0]['stats']['dialogues'] );
		yume_assert_contains( 'Aucun titre de chapitre (style Titre 1) : le document entier forme un seul chapitre.', implode( "\n", $r->warnings ) );
	}
);

yume_test(
	'docx : fichiers refusés avec un message en français',
	function () {
		$cas = array(
			'invalide.docx'    => 'docx_invalide',
			'faux.docx'        => 'archive_invalide',
			'chiffre.docx'     => 'fichier_chiffre',
			'livre-epub3.epub' => 'mauvais_format',
		);
		foreach ( $cas as $fixture => $code ) {
			try {
				Docx_Converter::convert_file( yume_timp_fixture( $fixture ) );
				throw new Yume_Test_Failure( $fixture . ' aurait dû être refusé' );
			} catch ( Import_Exception $e ) {
				yume_assert_same( $code, $e->code_erreur(), $fixture );
				yume_assert_true( '' !== $e->getMessage() );
			}
		}
		try {
			Docx_Converter::convert_file( '/chemin/inexistant.docx' );
			throw new Yume_Test_Failure( 'Fichier absent accepté' );
		} catch ( Import_Exception $e ) {
			yume_assert_same( 'fichier_illisible', $e->code_erreur() );
		}
		try {
			Epub_Converter::convert_file( yume_timp_fixture( 'regles.docx' ) );
			throw new Yume_Test_Failure( 'DOCX accepté comme EPUB' );
		} catch ( Import_Exception $e ) {
			yume_assert_same( 'mauvais_format', $e->code_erreur() );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * EPUB
 * -----------------------------------------------------------------------------
 */

yume_test(
	'epub 3 : spine, landmarks, découpe sur h1, suite sans h1, sous-titres',
	function () {
		$r = yume_timp_conversion( 'livre-epub3.epub' );
		yume_assert_same(
			array(
				array( 'prologue', 0.0, 'Prologue', '' ),
				array( 'chapitre', 1.0, 'Chapitre 1', 'La Crête Brumeuse' ),
				array( 'chapitre', 2.0, 'Chapitre 2', 'Le Deuxième Jour' ),
				array( 'chapitre', 3.0, 'Chapitre 3', 'Le Troisième Jour' ),
				array( 'postface', null, 'Postface', '' ),
			),
			array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters )
		);
		$tout = implode( '', array_column( $r->chapters, 'blocks' ) );
		foreach ( array( 'COLOPHON', 'Sommaire', 'Traduction : équipe Yume', 'Livre de test' ) as $absent ) {
			yume_assert_not_contains( $absent, $tout );
		}
		$c1 = yume_timp_chapitre( $r, 'Chapitre 1' )['blocks'];
		yume_assert_contains( 'Suite du chapitre un, sans titre.', $c1, 'Fichier sans h1 rattaché au chapitre en cours' );
		yume_assert_same( 'epub', $r->stats['format'] );
	}
);

yume_test(
	'epub 3 : classes connues, heuristiques, nettoyage et notes',
	function () {
		$r  = yume_timp_conversion( 'livre-epub3.epub' );
		$c1 = yume_timp_chapitre( $r, 'Chapitre 1' );
		$b  = $c1['blocks'];
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Réplique par la classe dialogue.</p>", $b );
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Réplique tapée avec un demi-cadratin.</p>", $b );
		yume_assert_contains( '<p class="yn-thought">Pensée par la classe, <em>dit-il</em>.</p>', $b );
		yume_assert_contains( '<p class="yn-thought">Pensée entièrement en italique.</p>', $b );
		yume_assert_same( 2, substr_count( $b, 'class="has-text-align-center yn-center"' ) );
		yume_assert_contains( '<p>Un mot en <em>italique</em>, un en <strong>gras</strong> et un appel<sup class="yn-note"><a href="#yn-note-1" id="yn-ref-1">1</a></sup>.</p>', $b );
		yume_assert_same( 2, $c1['stats']['separateurs'] );
		yume_assert_contains( "Après les astérisques\u{00A0}: la suite…", $b, 'Entités nommées (&nbsp; &hellip;) décodées' );
		yume_assert_contains( '<!-- wp:quote -->', $b );
		yume_assert_contains( '<li>Premier élément</li>', $b );
		yume_assert_contains( '<li id="yn-note-1">Note du chapitre un.', $b );
		yume_assert_same( 1, substr_count( $b, 'Note du chapitre un.' ), 'La note n’est pas répétée dans le texte' );
		$c2 = yume_timp_chapitre( $r, 'Chapitre 2' )['blocks'];
		yume_assert_contains( '<li id="yn-note-1">Note de fin du chapitre deux.', $c2, 'Note de fin prise dans un autre fichier' );
		yume_assert_not_contains( 'Notes', implode( '', array_column( $r->chapters, 'titre' ) ) );
		foreach ( $r->chapters as $c ) {
			yume_timp_blocs_valides( $c['blocks'], 'epub3 ' . $c['titre'] );
			yume_assert_same( Texte::compter_mots( $c['blocks'] ), $c['nb_mots'] );
		}
	}
);

yume_test(
	'epub 3 : couverture et images (galerie, illustrations, SVG ignoré)',
	function () {
		$r = yume_timp_conversion( 'livre-epub3.epub' );
		yume_assert_same( array( 'couverture', 'couleur' ), $r->front_images );
		yume_assert_same( 'couverture', $r->stats['couverture'] );
		yume_assert_same( array( 'illustration', 'encart' ), yume_timp_chapitre( $r, 'Chapitre 1' )['images'] );
		yume_assert_same( 'OEBPS/images/illustration.png', $r->images['illustration']['chemin_zip'] );
		yume_assert_same( 'Illustration du chapitre', $r->images['illustration']['alt'] );
		yume_assert_same( array( 800, 1200 ), array( $r->images['illustration']['largeur'], $r->images['illustration']['hauteur'] ) );
		yume_assert_contains( 'Image vectorielle « schema.svg » (SVG) ignorée', implode( "\n", $r->warnings ) );
	}
);

yume_test(
	'epub 2 : NCX, guide, aucun h1 (découpe par la table des matières)',
	function () {
		$r = yume_timp_conversion( 'livre-epub2.epub' );
		yume_assert_same(
			array(
				array( 'chapitre', 1.0, 'Chapitre 1', 'Le Départ' ),
				array( 'chapitre', 2.0, 'Chapitre 2', '' ),
			),
			array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters )
		);
		$b = $r->chapters[0]['blocks'];
		yume_assert_contains( "<p>Premier paragraphe\u{00A0}!</p>", $b );
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Réplique au trait d’union.</p>", $b );
		yume_assert_contains( '<p class="yn-thought">Pensée en italique par la classe.</p>', $b );
		yume_assert_contains( '<p class="has-text-align-center yn-center">Centré.</p>', $b );
		yume_assert_contains( 'Suite du chapitre un.', $b );
		yume_assert_contains( '<p>Texte flottant dans un bloc.</p>', $r->chapters[1]['blocks'] );
		$tout = implode( '', array_column( $r->chapters, 'blocks' ) );
		yume_assert_not_contains( 'SOMMAIRE', $tout );
		yume_assert_not_contains( 'ANNEXE', $tout );
		yume_assert_same( array( 'couv' ), $r->front_images );
		yume_assert_same( 'couv', $r->stats['couverture'] );
	}
);

yume_test(
	'epub : italique, gras et centrage lus dans les feuilles de style (lien externe, <style>, p.classe, @media, héritage)',
	function () {
		$css    = "@charset \"utf-8\";\n/* Calibre */\n.calibre5 { font-style: italic } /* commentaire { } */\n"
			. ".calibre6{text-align:center}\n@media amzn-kf8 { .calibre7 { font-weight: bold } }\n@media print { .calibre9 { font-style: italic } }\n"
			. ".calibre8, .autre { font-style: oblique !important }\nspan.normal { font-style: normal }\n"
			. "@font-face { font-family: x; src: url(x.ttf) }\n.chap p, p:first-child, p[lang] { font-style: italic }\n* { font-style: normal }\n"
			. "em { font: inherit }\n@import url(\"autre.css\");";
		$chemin = yume_timp_epub(
			array(
				'c1.xhtml' => array(
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- contenu d'un EPUB de test.
					'<link rel="stylesheet" type="text/css" href="../styles/stylesheet.css"/><style type="text/css">p.p1 { font-style: italic } span.s1 { font-style: italic } .p2 { text-align: center; } .boite { font-style: italic }</style>',
					'<h1 class="calibre2">Chapitre 1</h1>'
					. '<p class="calibre5">Je dois partir, pensa-t-il.</p>'
					. '<p class="calibre1">Un mot en <span class="calibre5">italique</span> ici.</p>'
					. '<p class="calibre6">Centré par la feuille.</p>'
					. '<p class="calibre1">Un <span class="calibre7">gras</span> dans un @media.</p>'
					. '<p class="calibre8">Oblique entière.</p>'
					. '<p class="calibre5">Pensée avec un <span class="normal">mot droit</span> au milieu.</p>'
					. '<p class="calibre9">Italique réservé à l’impression.</p>'
					. '<p class="p1">Pensée par style interne.</p>'
					. '<p class="s1">Pas en italique.</p>'
					. '<p>Un <span class="s1">span</span> et un <em>em</em>.</p>'
					. '<p class="p2">Centré par style interne.</p>'
					. '<div class="boite"><p>Hérité du conteneur.</p></div>'
					. '<div class="chap"><p>Paragraphe normal.</p></div>',
				),
			),
			array(
				'stylesheet.css' => $css,
				'autre.css'      => '.importee { font-weight: bold }',
			)
		);
		$r      = Epub_Converter::convert_file( $chemin );
		wp_delete_file( $chemin );
		$b = $r->chapters[0]['blocks'];
		yume_assert_contains( '<p class="yn-thought">Je dois partir, pensa-t-il.</p>', $b, 'Paragraphe entièrement en italique (classe externe) → pensée' );
		yume_assert_contains( '<p>Un mot en <em>italique</em> ici.</p>', $b, 'Italique partiel → em' );
		yume_assert_contains( '<p class="has-text-align-center yn-center">Centré par la feuille.</p>', $b );
		yume_assert_contains( '<p>Un <strong>gras</strong> dans un @media.</p>', $b, '@media aplati' );
		yume_assert_contains( '<p class="yn-thought">Oblique entière.</p>', $b, 'Sélecteurs multiples, oblique, !important' );
		yume_assert_contains( '<p class="yn-thought">Pensée avec un <em>mot droit</em> au milieu.</p>', $b, 'font-style: normal dans une pensée → italique inversé' );
		yume_assert_contains( '<p>Italique réservé à l’impression.</p>', $b, '@media print ignoré' );
		yume_assert_contains( '<p class="yn-thought">Pensée par style interne.</p>', $b, '<style> et p.classe' );
		yume_assert_contains( '<p>Pas en italique.</p>', $b, 'span.classe ne s’applique pas à un p' );
		yume_assert_contains( '<p>Un <em>span</em> et un <em>em</em>.</p>', $b, 'span.classe ; « * » et « em { font: inherit } » ignorés' );
		yume_assert_contains( '<p class="has-text-align-center yn-center">Centré par style interne.</p>', $b );
		yume_assert_contains( '<p class="yn-thought">Hérité du conteneur.</p>', $b, 'Italique hérité d’un div' );
		yume_assert_contains( '<p>Paragraphe normal.</p>', $b, 'Sélecteurs descendants ignorés' );
	}
);

yume_test(
	'epub : tiret de dialogue après espace insécable, cadratin, ancre, dans un span ou une puce Calibre ; guillemets sans règle (comme le DOCX)',
	function () {
		$chemin = yume_timp_epub(
			array(
				'c1.xhtml' => array(
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- contenu d'un EPUB de test.
					'<link href="../styles/stylesheet.css" rel="stylesheet" type="text/css"/>',
					'<h1>Chapitre 1</h1>'
					// Liste à puces Word convertie par Calibre : bloc div, tiret dans le span de puce.
					. '<div class="block_7"><span class="bullet_">— </span><span class="calibre6">Réplique en puce Calibre.</span></div>'
					. '<p class="block_6">Pensée Calibre, <span class="calibre9">en italique</span> par la feuille.</p>'
					. "<p>\u{00A0}— Réplique après insécable.</p>"
					. '<p><span class="calibre1">— Réplique dans un span.</span></p>'
					. '<p><a id="p12"></a>– Réplique après une ancre.</p>'
					. "<p>\u{2003}\u{FEFF}― Réplique après un cadratin.</p>"
					. "<p><span class=\"x\">\u{202F}</span><span>-\u{00A0}Réplique au trait d’union.</span></p>"
					. '<p>« Guillemets » sans tiret.</p>',
				),
			),
			array( 'stylesheet.css' => ".block_6 {\n  display: block;\n  font-style: italic;\n  page-break-inside: avoid\n}\n.calibre9 { font-style: italic }\n.bullet_ { margin-left: -1em }" )
		);
		$r      = Epub_Converter::convert_file( $chemin );
		wp_delete_file( $chemin );
		$b = $r->chapters[0]['blocks'];
		yume_assert_contains( '<p class="yn-thought">Pensée Calibre, en italique par la feuille.</p>', $b );
		foreach ( array( 'en puce Calibre.', 'après insécable.', 'dans un span.', 'après une ancre.', 'après un cadratin.', 'au trait d’union.' ) as $fin ) {
			yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Réplique " . $fin . '</p>', $b, $fin );
		}
		yume_assert_contains( "<p>«\u{00A0}Guillemets\u{00A0}» sans tiret.</p>", $b );
		yume_assert_same( 6, $r->chapters[0]['stats']['dialogues'] ?? $r->stats['dialogues'] );
	}
);

yume_test(
	'epub : feuille de style démesurée ignorée avec un avertissement, feuille externe (http) jamais chargée',
	function () {
		$chemin = yume_timp_epub(
			array(
				'c1.xhtml' => array(
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- contenu d'un EPUB de test.
					'<link rel="stylesheet" href="../styles/grande.css"/><link rel="stylesheet" href="https://example.test/x.css"/><link rel="alternate stylesheet" href="../styles/alt.css"/>',
					'<h1>Chapitre 1</h1><p class="a">Texte.</p>',
				),
			),
			array(
				'grande.css' => str_repeat( '.a { font-style: italic } ', 60000 ),
				'alt.css'    => '.a { font-style: italic }',
			)
		);
		$r      = Epub_Converter::convert_file( $chemin );
		wp_delete_file( $chemin );
		yume_assert_contains( 'Feuille de style « grande.css » trop volumineuse ou illisible : ignorée', implode( "\n", $r->warnings ) );
		yume_assert_contains( '<p>Texte.</p>', $r->chapters[0]['blocks'], 'Ni la feuille démesurée ni la feuille alternative ne s’appliquent' );
	}
);

yume_test(
	'epub : fichiers refusés',
	function () {
		foreach ( array(
			'invalide.docx' => 'epub_invalide',
			'faux.docx'     => 'archive_invalide',
		) as $fixture => $code ) {
			try {
				Epub_Converter::convert_file( yume_timp_fixture( $fixture ) );
				throw new Yume_Test_Failure( $fixture . ' accepté' );
			} catch ( Import_Exception $e ) {
				yume_assert_same( $code, $e->code_erreur(), $fixture );
			}
		}
	}
);

yume_test(
	'result : rapport sans contenu, JSON, libellés',
	function () {
		$r       = yume_timp_conversion( 'regles.docx' );
		$rapport = $r->rapport();
		yume_assert_same( 13, count( $rapport['chapitres'] ) );
		yume_assert_false( isset( $rapport['chapitres'][0]['blocks'] ) );
		yume_assert_false( isset( $rapport['images']['grande']['chemin_zip'] ) );
		$json = json_decode( (string) wp_json_encode( $r ), true );
		yume_assert_same( array( 'chapters', 'front_images', 'images', 'warnings', 'stats' ), array_keys( $json ) );
		yume_assert_same( 'Chapitre 1 — La Crête Brumeuse', Result::libelle( $r->chapters[1] ) );
	}
);

yume_test(
	'docx : bombe de compression et texte converti démesuré refusés proprement (sans épuiser la mémoire)',
	function () {
		yume_timp_outils_fixtures();
		// Partie document.xml anormalement compressée (bombe ZIP) : refus avant lecture.
		$para  = yume_fx_t( str_repeat( 'Encore et toujours la même phrase. ', 10 ) );
		$bombe = yume_timp_docx( str_repeat( $para, (int) ceil( ( Zip::TAUX_SEUIL + MB_IN_BYTES ) / strlen( $para ) ) ) );
		try {
			$avant = memory_get_usage();
			Docx_Converter::convert_file( $bombe );
			throw new Yume_Test_Failure( 'bombe acceptée' );
		} catch ( Import_Exception $e ) {
			yume_assert_same( 'archive_suspecte', $e->code_erreur() );
			yume_assert_contains( 'anormalement compressée', $e->getMessage() );
			yume_assert_true( memory_get_usage() - $avant < 8 * MB_IN_BYTES, 'rien n’est chargé en mémoire' );
		} finally {
			wp_delete_file( $bombe );
		}

		// Texte converti au-delà du volume maximal : Import_Exception, pas d'erreur fatale.
		$corps = yume_fx_t( 'Chapitre 1', array( 'style' => 'Titre1' ) );
		for ( $i = 0; $i < 2000; $i++ ) {
			$corps .= yume_fx_t( 'Paragraphe numéro ' . $i . ' : ' . md5( (string) $i ) . ' ' . sha1( (string) $i ) . '.' );
		}
		$gros = yume_timp_docx( $corps );
		try {
			try {
				Docx_Converter::convert_file( $gros, array( 'volume_max' => 64 * 1024 ) );
				throw new Yume_Test_Failure( 'volume dépassé accepté' );
			} catch ( Import_Exception $e ) {
				yume_assert_same( 'document_trop_grand', $e->code_erreur() );
			}
			// Limite par défaut : le même document passe.
			$r = Docx_Converter::convert_file( $gros );
			yume_assert_same( 1, count( $r->chapters ) );
			yume_assert_same( 2000, $r->chapters[0]['stats']['paragraphes'] );
		} finally {
			wp_delete_file( $gros );
		}
		yume_assert_true( Zip::DOCUMENT_MAX <= 128 * MB_IN_BYTES, 'texte Word décompressé borné à 128 Mo' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * DOCX de travail (privé, facultatif)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'docx de référence (privé) : 19 chapitres + PostFace, dialogues, pensées, images',
	function () {
		$chemin = yume_timp_docx_prive();
		if ( '' === $chemin ) {
			echo "    (tools/fixtures/private/grimgar-t7.docx absent : test ignoré)\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		$avant = memory_get_usage();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$r = Docx_Converter::convert_file( $chemin );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			// Fichier de 26 Mo (74 Mo décompressés) : lecture en continu, mémoire bornée.
			yume_assert_true( memory_get_peak_usage() - $avant < 48 * MB_IN_BYTES, 'Mémoire bornée : ' . round( ( memory_get_peak_usage() - $avant ) / MB_IN_BYTES ) . ' Mo' );
		}
		yume_assert_same( 20, count( $r->chapters ) );
		yume_assert_same( 19, count( array_filter( $r->chapters, static fn( $c ) => 'chapitre' === $c['nature'] ) ) );
		yume_assert_same( range( 1, 19 ), array_map( 'intval', array_column( array_slice( $r->chapters, 0, 19 ), 'numero' ) ) );
		yume_assert_same( array( 'postface', 'Postface' ), array( $r->chapters[19]['nature'], $r->chapters[19]['titre'] ) );
		yume_assert_same( 'La Crête Brumeuse', $r->chapters[0]['sous_titre'] );
		// 1 453 paragraphes au style « Paragraphe de liste » : 1 449 répliques (puce « — »),
		// 3 puces carrées d'un glossaire (liste) et 1 paragraphe sans puce au style de caractère
		// Pensée (pensée).
		yume_assert_same( 1449, $r->stats['dialogues'] );
		yume_assert_same( 3, $r->stats['listes'] );
		yume_assert_true( $r->stats['pensees'] >= 305 && $r->stats['pensees'] <= 310, 'Pensées ~307 : ' . $r->stats['pensees'] );
		// 16 images, dont 6 EMF (bitmap 1400 × ~1960 en 32 bits) converties en PNG.
		yume_assert_same( 16, count( $r->images ) );
		yume_assert_same( 0, $r->stats['images_emf'] );
		yume_assert_same( 6, $r->stats['images_emf_converties'] );
		yume_assert_same( array( 'image/png', 1400, 1978 ), array( $r->images['image1']['mime'], $r->images['image1']['largeur'], $r->images['image1']['hauteur'] ) );
		yume_assert_same( 2, $r->stats['sauts_de_page'] );
		yume_assert_same( array( 'image1', 'image2', 'image3', 'image4', 'image5', 'image6', 'image7', 'image8' ), $r->front_images );
		yume_assert_same( 8, array_sum( array_map( static fn( $c ) => count( $c['images'] ), $r->chapters ) ) );
		yume_assert_same( array( 'image14', 'image15', 'image16' ), yume_timp_chapitre( $r, 'Chapitre 19' )['images'] );
		$avert = implode( "\n", $r->warnings );
		yume_assert_contains( 'Chapitre 2 : titre « Chapitre2 » sans espace', $avert );
		yume_assert_contains( 'Chapitre 13 : titre précédé d’une espace', $avert );
		yume_assert_not_contains( 'EMF', $avert );
		foreach ( $r->chapters as $c ) {
			yume_assert_true( $c['nb_mots'] > 0, $c['titre'] . ' non vide' );
			yume_timp_blocs_valides( $c['blocks'], 'grimgar ' . $c['titre'] );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Découpage manuel : ancres, débuts possibles, découpage, marqueurs
 * -----------------------------------------------------------------------------
 */

if ( ! function_exists( 'yume_timp_candidat' ) ) {
	/**
	 * Début de chapitre possible dont l'extrait commence par un texte.
	 *
	 * @param Result $r      Résultat.
	 * @param string $debut  Début de l'extrait.
	 * @return array<string,mixed>
	 * @throws Yume_Test_Failure Candidat absent.
	 */
	function yume_timp_candidat( Result $r, string $debut ): array {
		foreach ( $r->candidats as $c ) {
			if ( str_starts_with( $c['extrait'], $debut ) ) {
				return $c;
			}
		}
		throw new Yume_Test_Failure( 'Début possible absent : ' . $debut );
	}

	/**
	 * Découpage manuel : extrait => [nature, titre] (ou nature seule).
	 *
	 * @param Result                     $r      Analyse du fichier.
	 * @param array<string,string|array> $debuts Débuts (dans l'ordre du document).
	 * @param bool                       $garder Conserver le texte d'ouverture.
	 * @return array<string,mixed>
	 */
	function yume_timp_plan( Result $r, array $debuts, bool $garder = false ): array {
		$plan = array(
			'debuts'       => array(),
			'garder_avant' => $garder,
		);
		foreach ( $debuts as $extrait => $choix ) {
			$choix            = (array) $choix;
			$plan['debuts'][] = array(
				'ancre'  => yume_timp_candidat( $r, (string) $extrait )['ancre'],
				'nature' => (string) ( $choix[0] ?? 'chapitre' ),
				'titre'  => (string) ( $choix[1] ?? '' ),
			);
		}
		return $plan;
	}

	/**
	 * DOCX temporaire avec images (word/media/a.png, b.png…).
	 *
	 * @param string   $corps  Contenu de w:body.
	 * @param string[] $images Noms des images PNG.
	 */
	function yume_timp_docx_images( string $corps, array $images ): string {
		yume_timp_outils_fixtures();
		$medias = array();
		foreach ( $images as $nom ) {
			$medias[ $nom ] = yume_fx_image( 'png', 400, 300 );
		}
		$fichier = get_temp_dir() . 'yume-test-' . wp_generate_password( 8, false ) . '.docx';
		yume_fx_docx( $fichier, $corps, yume_fx_styles_fr(), '', $medias );
		return $fichier;
	}
}

yume_test(
	'découpage : ancres identiques d’une conversion à l’autre (DOCX et EPUB), avec ou sans découpage',
	function () {
		foreach ( array( 'regles.docx', 'livre-epub3.epub', 'livre-epub2.epub' ) as $nom ) {
			$chemin = yume_timp_fixture( $nom );
			$lire   = static fn( array $o = array() ) => str_ends_with( $nom, '.epub' ) ? Epub_Converter::convert_file( $chemin, $o ) : Docx_Converter::convert_file( $chemin, $o );
			$a      = $lire();
			$b      = $lire();
			yume_assert_true( count( $a->candidats ) > 3, $nom . ' : débuts possibles relevés' );
			yume_assert_same( array_column( $a->candidats, 'ancre' ), array_column( $b->candidats, 'ancre' ), $nom . ' : mêmes ancres' );
			foreach ( $a->candidats as $c ) {
				yume_assert_same( 1, preg_match( Chapter_Builder::MOTIF_ANCRE, $c['ancre'] ), $nom . ' : format de l’ancre ' . $c['ancre'] );
				yume_assert_true( mb_strlen( $c['extrait'] ) <= 80, $nom . ' : extrait borné' );
			}
			// Débuts automatiques : un par chapitre produit, repris comme débuts manuels.
			$auto = array_values( array_filter( $a->candidats, static fn( $c ) => $c['auto'] ) );
			yume_assert_same( count( $a->chapters ), count( $auto ), $nom . ' : un début automatique par chapitre' );
			$plan = array(
				'debuts'       => array_map(
					static fn( $c ) => array(
						'ancre'  => $c['ancre'],
						'nature' => $c['nature'],
						'titre'  => $c['titre'],
					),
					$auto
				),
				'garder_avant' => false,
			);
			$c    = $lire( array( 'plan' => $plan ) );
			yume_assert_same( array_column( $a->candidats, 'ancre' ), array_column( $c->candidats, 'ancre' ), $nom . ' : mêmes ancres avec un découpage' );
			yume_assert_same( $a->stats['elements'], $c->stats['elements'], $nom . ' : mêmes éléments' );
			yume_assert_same( count( $a->chapters ), count( $c->chapters ), $nom . ' : même nombre de chapitres' );
			yume_assert_same( array_column( $a->chapters, 'nature' ), array_column( $c->chapters, 'nature' ), $nom . ' : mêmes natures' );
			yume_assert_not_contains( 'introuvable', implode( "\n", $c->warnings ), $nom );
			yume_assert_true( $c->stats['decoupage_manuel'] );
			$rapport = $a->rapport();
			yume_assert_same( $a->candidats, $rapport['candidats'] );
			yume_assert_same( array( 'images', 'ouvertures', 'sauts' ), array_keys( $rapport['decoupages'] ) );
		}
	}
);

yume_test(
	'découpage : début manuel au premier élément (préface et bonus conservés), titres détectés en intertitres',
	function () {
		yume_timp_outils_fixtures();
		$fichier = yume_timp_docx(
			yume_fx_t( 'Préface du traducteur' )
			. yume_fx_t( 'Merci de lire ce tome, voici quelques mots avant de commencer.' )
			. yume_fx_t( 'Histoire bonus : la veille' )
			. yume_fx_t( str_repeat( 'Une petite histoire offerte avant le premier chapitre. ', 5 ) )
			. yume_fx_t( 'Chapitre 1 : Le départ', array( 'style' => 'Titre1' ) )
			. yume_fx_t( 'Texte du premier chapitre.' )
			. yume_fx_t( 'Chapitre 2', array( 'style' => 'Titre1' ) )
			. yume_fx_t( 'Texte du deuxième chapitre.' )
		);
		try {
			$auto = Docx_Converter::convert_file( $fichier );
			yume_assert_same( 2, count( $auto->chapters ) );
			yume_assert_contains( '4 paragraphes placés avant le premier chapitre', implode( "\n", $auto->warnings ), 'sans découpage, texte d’ouverture ignoré' );
			$debut = yume_timp_candidat( $auto, 'Préface' );
			yume_assert_true( in_array( 'debut', $debut['raisons'], true ) && $debut['avant'] && ! $debut['auto'] );
			yume_assert_true( yume_timp_candidat( $auto, 'Chapitre 1' )['auto'] );
			yume_assert_same( array( 'chapitre', 'Le départ' ), array( yume_timp_candidat( $auto, 'Chapitre 1' )['nature'], yume_timp_candidat( $auto, 'Chapitre 1' )['titre'] ) );

			// Préface (prologue), bonus, puis un seul chapitre : le titre « Chapitre 2 » devient un intertitre.
			$plan = yume_timp_plan(
				$auto,
				array(
					'Préface'         => array( 'prologue', 'Préface du traducteur' ),
					'Histoire bonus'  => array( 'bonus', 'La veille' ),
					'Chapitre 1 : Le' => array( 'chapitre', '' ),
				)
			);
			$r    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same(
				array(
					array( 'prologue', 0.0, 'Prologue', 'Préface du traducteur' ),
					array( 'bonus', null, 'Bonus', 'La veille' ),
					array( 'chapitre', 1.0, 'Chapitre 1', 'Le départ' ),
				),
				array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters )
			);
			// Ligne identique au titre choisi : titre, non répétée ; texte gardé.
			yume_assert_not_contains( 'Préface du traducteur', $r->chapters[0]['blocks'] );
			yume_assert_contains( 'Merci de lire ce tome', $r->chapters[0]['blocks'] );
			yume_assert_contains( 'Une petite histoire offerte', $r->chapters[1]['blocks'] );
			// « Histoire bonus : la veille » reconnu comme titre (bonus) : non répété.
			yume_assert_not_contains( 'Histoire bonus', $r->chapters[1]['blocks'] );
			yume_assert_not_contains( 'Chapitre 1', $r->chapters[2]['blocks'] );
			yume_assert_contains( '<h2 class="wp-block-heading">Chapitre 2</h2>', $r->chapters[2]['blocks'] );
			yume_assert_contains( 'Texte du deuxième chapitre.', $r->chapters[2]['blocks'] );
			yume_assert_not_contains( 'placé avant le premier chapitre', implode( "\n", $r->warnings ) );
			foreach ( $r->chapters as $c ) {
				yume_timp_blocs_valides( $c['blocks'], 'plan ' . $c['titre'] );
			}

			// Texte d'ouverture conservé : il rejoint le premier début manuel.
			$plan = yume_timp_plan( $auto, array( 'Chapitre 1 : Le' => array( 'chapitre', 'Mon titre' ) ), true );
			$r    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same( 1, count( $r->chapters ) );
			yume_assert_same( array( 'Chapitre 1', 'Mon titre' ), array( $r->chapters[0]['titre'], $r->chapters[0]['sous_titre'] ) );
			yume_assert_contains( 'Préface du traducteur', $r->chapters[0]['blocks'] );
			yume_assert_contains( 'Une petite histoire offerte', $r->chapters[0]['blocks'] );
			yume_assert_contains( 'Texte du deuxième chapitre.', $r->chapters[0]['blocks'] );
			// Titre détecté sur le début manuel, un autre titre saisi : pas d'intertitre « Chapitre 1 ».
			yume_assert_not_contains( '>Chapitre 1', $r->chapters[0]['blocks'] );

			// Sans conserver : le texte d'ouverture est ignoré (et signalé), comme sans découpage.
			$plan = yume_timp_plan( $auto, array( 'Chapitre 1 : Le' => 'chapitre' ) );
			$r    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same( 'Le départ', $r->chapters[0]['sous_titre'], 'titre détecté par défaut' );
			yume_assert_contains( '4 paragraphes placés avant le premier chapitre', implode( "\n", $r->warnings ) );
		} finally {
			wp_delete_file( $fichier );
		}
	}
);

yume_test(
	'découpage : chapitres délimités par les illustrations (découpage rapide), natures spéciales et numérotation',
	function () {
		yume_timp_outils_fixtures();
		$corps = yume_fx_t( 'Mon roman illustré' );
		foreach ( array( 'a.png', 'b.png', 'c.png', 'd.png' ) as $i => $nom ) {
			$corps .= yume_fx_p( yume_fx_dessin( yume_fx_rid( $nom ), 'Illustration ' . ( $i + 1 ) ) );
			if ( 1 === $i ) {
				// Deux illustrations consécutives : un seul début.
				continue;
			}
			$corps .= yume_fx_t( 'Texte de la partie ' . ( $i + 1 ) . '.' );
		}
		$fichier = yume_timp_docx_images( $corps, array( 'a.png', 'b.png', 'c.png', 'd.png' ) );
		try {
			$auto = Docx_Converter::convert_file( $fichier );
			yume_assert_same( 1, count( $auto->chapters ), 'sans titre : un seul chapitre' );
			$images = $auto->decoupages['images'];
			yume_assert_same( 3, count( $images ), 'une coupure par suite d’illustrations' );
			$plan = array(
				'debuts'       => array_map(
					static fn( $a ) => array(
						'ancre'  => $a,
						'nature' => 'chapitre',
						'titre'  => '',
					),
					$images
				),
				'garder_avant' => false,
			);
			$r    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same( array( 'Chapitre 1', 'Chapitre 2', 'Chapitre 3' ), array_column( $r->chapters, 'titre' ) );
			yume_assert_same( array( array( 'a' ), array( 'b', 'c' ), array( 'd' ) ), array_column( $r->chapters, 'images' ) );
			yume_assert_contains( 'Texte de la partie 1', $r->chapters[0]['blocks'] );
			yume_assert_contains( 'Texte de la partie 3', $r->chapters[1]['blocks'] );
			yume_assert_same( array(), $r->front_images );
			yume_assert_contains( '1 paragraphe placé avant le premier chapitre', implode( "\n", $r->warnings ) );

			// Numéros saisis et natures spéciales : Chapitre 12, Interlude, Chapitre 13, deux bonus.
			$plan['debuts'][0]['numero'] = 12;
			$plan['debuts'][1]['nature'] = 'interlude';
			$plan['debuts'][1]['titre']  = '<b>Pause</b>';
			$plan['debuts'][]            = array(
				'ancre'  => yume_timp_candidat( $auto, 'Texte de la partie 4' )['ancre'],
				'nature' => 'bonus',
				'titre'  => '',
			);
			array_splice(
				$plan['debuts'],
				2,
				0,
				array(
					array(
						'ancre'  => yume_timp_candidat( $auto, 'Texte de la partie 3' )['ancre'],
						'nature' => 'bonus',
						'titre'  => 'Bonus',
					),
				)
			);
			$r = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same(
				array(
					array( 'chapitre', 12.0, 'Chapitre 12', '' ),
					array( 'interlude', null, 'Interlude', 'Pause' ),
					array( 'bonus', 1.0, 'Bonus 1', '' ),
					array( 'chapitre', 13.0, 'Chapitre 13', '' ),
					array( 'bonus', 2.0, 'Bonus 2', '' ),
				),
				array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters )
			);
			yume_assert_same(
				array(
					array(
						'numero' => 3.0,
						'titre'  => 'Chapitre 3',
					),
					array(
						'numero' => 0.0,
						'titre'  => 'Prologue',
					),
					array(
						'numero' => 4.0,
						'titre'  => 'Chapitre 4',
					),
				),
				Chapter_Builder::numeroter(
					array(
						array(
							'nature' => 'chapitre',
							'numero' => 3.0,
						),
						array(
							'nature' => 'prologue',
							'numero' => null,
						),
						array(
							'nature' => 'chapitre',
							'numero' => null,
						),
					)
				)
			);
		} finally {
			wp_delete_file( $fichier );
		}
	}
);

yume_test(
	'découpage : début manuel introuvable ou ne correspondant plus au texte signalé',
	function () {
		$auto  = yume_timp_conversion( 'regles.docx' );
		$vrai  = yume_timp_candidat( $auto, 'Chapitre 1' )['ancre'];
		$rang  = (int) substr( $vrai, 1 );
		$faux  = 'e' . $rang . '-000000';
		$plan  = array(
			'debuts' => array(
				array(
					'ancre'  => $vrai,
					'nature' => 'chapitre',
					'titre'  => '',
				),
				array(
					'ancre'  => $faux,
					'nature' => 'chapitre',
					'titre'  => '',
				),
				array(
					'ancre'  => 'e99999-abcdef',
					'nature' => 'chapitre',
					'titre'  => '',
				),
			),
		);
		$r     = Docx_Converter::convert_file( yume_timp_fixture( 'regles.docx' ), array( 'plan' => $plan ) );
		$avert = implode( "\n", $r->warnings );
		yume_assert_contains( 'Début de chapitre manuel introuvable : ' . $faux, $avert );
		yume_assert_contains( 'Début de chapitre manuel introuvable : e99999-abcdef', $avert );
		yume_assert_not_contains( 'introuvable : ' . $vrai, $avert );
		yume_assert_same( 1, count( $r->chapters ), 'un seul début trouvé' );
		// Entrées invalides ignorées par le convertisseur (contrôle strict à la réception).
		yume_assert_same(
			null,
			Chapter_Builder::normaliser_plan(
				array(
					'debuts' => array(
						array(
							'ancre'  => '<b>',
							'nature' => 'chapitre',
						),
						array(
							'ancre'  => 'e1-abcdef',
							'nature' => 'inconnue',
						),
					),
				)
			)
		);
		yume_assert_same( null, Chapter_Builder::normaliser_plan( 'e1-abcdef' ) );
	}
);

yume_test(
	'découpage : notes de bas de page dans les chapitres manuels (numérotées dans leur chapitre)',
	function () {
		$auto = yume_timp_conversion( 'regles.docx' );
		// Coupure sur le paragraphe qui porte les deux appels de note.
		$plan = yume_timp_plan(
			$auto,
			array(
				'Chapitre 1'        => 'chapitre',
				'Le mot yume et le' => array( 'chapitre', 'Les notes' ),
			)
		);
		$r    = Docx_Converter::convert_file( yume_timp_fixture( 'regles.docx' ), array( 'plan' => $plan ) );
		yume_assert_same( 2, count( $r->chapters ) );
		yume_assert_same( 0, $r->chapters[0]['stats']['notes'] );
		yume_assert_not_contains( 'yn-notes', $r->chapters[0]['blocks'] );
		$c = $r->chapters[1];
		yume_assert_same( 2, $c['stats']['notes'] );
		yume_assert_true( str_starts_with( $c['blocks'], '<!-- wp:paragraph -->' . "\n" . '<p>Le mot yume<sup class="yn-note"><a href="#yn-note-1" id="yn-ref-1">1</a></sup> et le mot kizuna<sup class="yn-note"><a href="#yn-note-2" id="yn-ref-2">2</a></sup>.</p>' ), 'appels numérotés dans le nouveau chapitre' );
		yume_assert_contains( '<li id="yn-note-2">Le lien, en <em>japonais</em>.', $c['blocks'] );
		yume_assert_not_contains( 'data-yn-attente', $c['blocks'] );

		// Constructeur seul : note avant le premier chapitre gardée avec le texte d'ouverture.
		$resultat = new Result();
		$b        = new Chapter_Builder(
			$resultat,
			Chapter_Builder::VOLUME_MAX,
			array(
				'debuts'       => array( 'e2-' . substr( hash( 'crc32b', 'deuxième.' ), 0, 6 ) => array( 'nature' => 'chapitre' ) ),
				'garder_avant' => true,
			)
		);
		$b->paragraphe( 'Premier' . $b->note( 'Note A' ) . '.' );
		$b->paragraphe( 'Deuxième' . $b->note( 'Note B' ) . '.' );
		$b->terminer();
		yume_assert_same( 1, count( $resultat->chapters ) );
		yume_assert_same( 2, $resultat->chapters[0]['stats']['notes'] );
		yume_assert_contains( '<li id="yn-note-2">Note B', $resultat->chapters[0]['blocks'] );
	}
);

yume_test(
	'découpage : marqueurs [chapitre], [bonus], [prologue]… dans le document (sans JavaScript)',
	function () {
		yume_assert_same(
			array(
				'nature' => 'epilogue',
				'titre'  => 'La fin',
			),
			Texte::marqueur( '[ÉPILOGUE] La fin' )
		);
		yume_assert_same(
			array(
				'nature' => 'chapitre',
				'titre'  => '',
			),
			Texte::marqueur( ' [Chapitre] ' )
		);
		yume_assert_same(
			array(
				'nature' => 'bonus',
				'titre'  => 'Le festival',
			),
			Texte::marqueur( '[bonus] : Le festival' )
		);
		yume_assert_same( null, Texte::marqueur( '[note du traducteur] rien' ) );
		yume_assert_same( null, Texte::marqueur( 'Il dit [chapitre] au milieu.' ) );
		yume_timp_outils_fixtures();
		$fichier = yume_timp_docx(
			yume_fx_t( 'Page de titre' )
			. yume_fx_t( '[bonus] Le festival' )
			. yume_fx_t( 'Une histoire bonus placée avant le premier chapitre.' )
			. yume_fx_t( '[Prologue]' )
			. yume_fx_t( 'Texte du prologue.' )
			. yume_fx_t( 'Chapitre 1', array( 'style' => 'Titre1' ) )
			. yume_fx_t( 'Texte du chapitre un.' )
			. yume_fx_t( '[chapitre] Sans titre de style' )
			. yume_fx_t( 'Texte du chapitre deux.' )
			. yume_fx_t( '[interlude]' )
			. yume_fx_t( 'Chapitre 3 : Le retour', array( 'style' => 'Titre1' ) )
			. yume_fx_t( 'Texte de l’interlude.' )
			. yume_fx_t( '[épilogue] La fin' )
			. yume_fx_t( 'Texte de l’épilogue.' )
		);
		try {
			$r = Docx_Converter::convert_file( $fichier );
			yume_assert_same(
				array(
					array( 'bonus', null, 'Bonus', 'Le festival' ),
					array( 'prologue', 0.0, 'Prologue', '' ),
					array( 'chapitre', 1.0, 'Chapitre 1', '' ),
					array( 'chapitre', 2.0, 'Chapitre 2', 'Sans titre de style' ),
					array( 'interlude', null, 'Interlude', 'Le retour' ),
					array( 'epilogue', null, 'Épilogue', 'La fin' ),
				),
				array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters )
			);
			yume_assert_contains( 'Une histoire bonus placée avant le premier chapitre.', $r->chapters[0]['blocks'], 'bonus jamais ignoré' );
			foreach ( $r->chapters as $c ) {
				yume_assert_not_contains( '[', $c['blocks'], 'marqueur non publié' );
			}
			$m = yume_timp_candidat( $r, '[bonus]' );
			yume_assert_same( array( 'marqueur', true, 'bonus', 'Le festival' ), array( $m['type'], $m['auto'], $m['nature'], $m['titre'] ) );
			yume_assert_contains( '1 paragraphe placé avant le premier chapitre', implode( "\n", $r->warnings ) );

			// Avec un découpage, seuls ses débuts comptent ; les marqueurs ne sont jamais publiés.
			$plan = yume_timp_plan( $r, array( 'Chapitre 1' => 'chapitre' ) );
			$p    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same( 1, count( $p->chapters ) );
			yume_assert_not_contains( '[', $p->chapters[0]['blocks'] );
			yume_assert_contains( 'Texte de l’épilogue.', $p->chapters[0]['blocks'] );
		} finally {
			wp_delete_file( $fichier );
		}
		// EPUB : marqueur dans un paragraphe.
		$epub = yume_timp_epub(
			array(
				'a.xhtml' => array( '', '<p>[bonus] Hors série</p><p>Texte bonus.</p>' ),
				'b.xhtml' => array( '', '<h1>Chapitre 1</h1><p>Texte un.</p>' ),
			)
		);
		try {
			$r = Epub_Converter::convert_file( $epub );
			yume_assert_same( array( 'Bonus', 'Chapitre 1' ), array_column( $r->chapters, 'titre' ) );
			yume_assert_same( 'Hors série', $r->chapters[0]['sous_titre'] );
		} finally {
			wp_delete_file( $epub );
		}
	}
);

yume_test(
	'découpage : débuts possibles (sauts de page, gras, centré, séparateur) et plafond',
	function () {
		yume_timp_outils_fixtures();
		$fichier = yume_timp_docx(
			yume_fx_t( 'Ouverture du livre, un paragraphe comme un autre, assez long pour ne pas être une ligne courte du tout.' )
			. yume_fx_p( '<w:r><w:br w:type="page"/></w:r>' )
			. yume_fx_t( 'Après le saut de page, un long paragraphe qui ne ressemble pas du tout à un titre de chapitre.' )
			. yume_fx_p( yume_fx_r( 'Un titre en gras', array( 'b' => true ) ) )
			. yume_fx_t( 'Un titre centré', array( 'jc' => 'center' ) )
			. yume_fx_t( '* * *' )
			. yume_fx_t( 'Après le séparateur, encore un long paragraphe qui ne ressemble pas à un titre.' )
			. yume_fx_p( yume_fx_r( 'Texte avant le saut.' ) . '<w:r><w:br w:type="page"/></w:r>' )
			. yume_fx_t( 'Page suivante, un long paragraphe encore une fois sans rien de particulier ici.' )
			. yume_fx_t( '— Une réplique courte.' )
		);
		try {
			$r = Docx_Converter::convert_file( $fichier );
			yume_assert_same( array( 'debut' ), yume_timp_candidat( $r, 'Ouverture' )['raisons'] );
			yume_assert_same( array( 'saut_page' ), yume_timp_candidat( $r, 'Après le saut de page' )['raisons'] );
			yume_assert_same( array( 'gras', 'ligne_courte' ), yume_timp_candidat( $r, 'Un titre en gras' )['raisons'] );
			yume_assert_same( 'Un titre en gras', yume_timp_candidat( $r, 'Un titre en gras' )['titre'], 'titre proposé' );
			yume_assert_same( array( 'centre', 'ligne_courte' ), yume_timp_candidat( $r, 'Un titre centré' )['raisons'] );
			yume_assert_same( array( 'separateur' ), yume_timp_candidat( $r, 'Après le séparateur' )['raisons'] );
			yume_assert_same( array( 'saut_page' ), yume_timp_candidat( $r, 'Page suivante' )['raisons'], 'saut après le texte : paragraphe suivant' );
			yume_assert_same( 2, count( $r->decoupages['sauts'] ) );
			foreach ( $r->candidats as $c ) {
				yume_assert_false( str_contains( $c['extrait'], 'réplique' ), 'réplique : pas une ligne courte' );
			}
			// Découpage « à chaque saut de page » avec le texte d'ouverture conservé.
			$plan = array(
				'debuts'       => array_map(
					static fn( $a ) => array(
						'ancre'  => $a,
						'nature' => 'chapitre',
					),
					$r->decoupages['sauts']
				),
				'garder_avant' => true,
			);
			$p    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same( 2, count( $p->chapters ) );
			yume_assert_contains( 'Ouverture du livre', $p->chapters[0]['blocks'] );
			yume_assert_contains( 'Page suivante', $p->chapters[1]['blocks'] );
		} finally {
			wp_delete_file( $fichier );
		}

		// Plafond : au plus Result::CANDIDATS_MAX débuts possibles.
		$resultat = new Result();
		$b        = new Chapter_Builder( $resultat );
		for ( $i = 0; $i < Result::CANDIDATS_MAX + 50; $i++ ) {
			$b->paragraphe( 'Ligne ' . $i );
		}
		$b->terminer();
		yume_assert_same( Result::CANDIDATS_MAX, count( $resultat->candidats ) );
		yume_assert_true( $resultat->stats['candidats_tronques'] );
		yume_assert_same( Result::CANDIDATS_MAX + 50, $resultat->stats['elements'] );
	}
);

yume_test(
	'DOCX : étiquette de chapitre (« Prologue », « 1 », « Bonus ») centrée ou en gras juste avant un Titre 1 (Survival in Another World)',
	function () {
		yume_timp_outils_fixtures();
		$texte  = static fn( string $mot, int $n ): string => str_repeat( yume_fx_t( $mot . ' lorem ipsum dolor sit amet, consectetur adipiscing elit sed do eiusmod tempor.' ), $n );
		$corps  = yume_fx_t( 'Prologue', array( 'jc' => 'center' ) ) . yume_fx_t( 'Le Début soudain de ma vie de survie', array( 'style' => 'Titre1' ) ) . $texte( 'Prologue', 40 );
		$corps .= yume_fx_p( yume_fx_r( '1', array( 'b' => true ) ) ) . yume_fx_t( 'Le Début soudain : Jours 1 et 2', array( 'style' => 'Titre1' ) ) . $texte( 'Un', 40 );
		$corps .= yume_fx_t( '3', array( 'jc' => 'center' ) ) . $texte( 'Suite', 2 );
		$corps .= yume_fx_t( 'Bonus', array( 'jc' => 'center' ) ) . yume_fx_t( '', array( 'jc' => 'center' ) ) . yume_fx_t( 'L’Histoire d’Ira', array( 'style' => 'Titre1' ) ) . $texte( 'Ira', 5 );
		$corps .= yume_fx_t( 'Postface', array( 'style' => 'Titre1' ) ) . $texte( 'Fin', 3 );
		$docx   = yume_timp_docx( $corps );
		try {
			$r = Docx_Converter::convert_file( $docx );
		} finally {
			wp_delete_file( $docx );
		}
		$resume = array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['titre'], $c['sous_titre'] ), $r->chapters );
		yume_assert_same(
			array(
				array( 'prologue', 0.0, 'Prologue', 'Le Début soudain de ma vie de survie' ),
				array( 'chapitre', 1.0, 'Chapitre 1', 'Le Début soudain : Jours 1 et 2' ),
				array( 'bonus', null, 'Bonus', 'L’Histoire d’Ira' ),
				array( 'postface', null, 'Postface', '' ),
			),
			$resume
		);
		yume_assert_same( array(), preg_grep( '/sans numéro|liminaire/', $r->warnings ), implode( ' | ', $r->warnings ) );
		yume_assert_not_contains( '<p class="has-text-align-center">Prologue</p>', $r->chapters[0]['blocks'], 'étiquette absorbée par le titre' );
		yume_assert_contains( '>3</p>', $r->chapters[1]['blocks'], 'étiquette sans titre après elle : paragraphe conservé' );
		yume_assert_contains( 'Ira lorem', $r->chapters[2]['blocks'] );
	}
);

yume_test(
	'Pages liminaires : une section courte placée après un chapitre gardé (histoire bonus sans numéro) est toujours publiée',
	function () {
		yume_timp_outils_fixtures();
		$long  = str_repeat( yume_fx_t( 'Texte du long chapitre lorem ipsum dolor sit amet, consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore.' ), 40 );
		$corps = yume_fx_t( 'Crédits', array( 'style' => 'Titre1' ) ) . yume_fx_t( 'Traduction : équipe.' )
			. yume_fx_t( 'La Crête', array( 'style' => 'Titre1' ) ) . $long
			. yume_fx_t( 'Une petite histoire', array( 'style' => 'Titre1' ) ) . yume_fx_t( 'Quelques lignes bonus.' )
			. yume_fx_t( 'Postface', array( 'style' => 'Titre1' ) ) . yume_fx_t( 'Merci.' );
		$docx  = yume_timp_docx( $corps );
		try {
			$r = Docx_Converter::convert_file( $docx );
		} finally {
			wp_delete_file( $docx );
		}
		yume_assert_same( array( 'La Crête', 'Une petite histoire', '' ), array_map( static fn( $c ) => (string) $c['sous_titre'], $r->chapters ) );
		yume_assert_same( 1, count( preg_grep( '/« Crédits » placée avant le premier chapitre/', $r->warnings ) ), 'la page de crédits reste liminaire' );
	}
);

yume_test(
	'Découpage manuel : ornements (petites images) jamais proposés ; « ouvertures illustrées » = première image de chaque suite de plusieurs illustrations (SukaMoka)',
	function () {
		$r = new Result();
		foreach ( array( 'couverture', 'ouv1a', 'ouv1b', 'seule', 'ouv2a', 'ouv2b', 'ouv2c' ) as $cle ) {
			$r->images[ $cle ] = array(
				'nom'     => $cle,
				'largeur' => 1200,
				'hauteur' => 1800,
			);
		}
		$r->images['fleuron'] = array(
			'nom'     => 'fleuron',
			'largeur' => 26,
			'hauteur' => 49,
		);
		$b                    = new Chapter_Builder( $r );
		$b->image( 'couverture' );
		$b->paragraphe( 'Prologue sans titre.' );
		$b->image( 'ouv1a' );
		$b->image( 'fleuron' );
		$b->image( 'ouv1b' );
		$b->paragraphe( 'Premier chapitre.' );
		$b->image( 'fleuron' );
		$b->paragraphe( 'Suite.' );
		$b->image( 'seule' );
		$b->paragraphe( 'Encore.' );
		$b->image( 'ouv2a' );
		$b->image( 'ouv2b' );
		$b->image( 'ouv2c' );
		$b->paragraphe( 'Deuxième chapitre.' );
		$b->terminer();
		$images = array_values( array_filter( $r->candidats, static fn( $c ) => 'image' === $c['type'] ) );
		yume_assert_same( 7, count( $images ), 'fleurons exclus' );
		yume_assert_same( array(), preg_grep( '/fleuron/', array_column( $r->candidats, 'extrait' ) ) );
		$par_ancre = array_column( $r->candidats, 'extrait', 'ancre' );
		yume_assert_same( array( 'Illustration : ouv1a', 'Illustration : ouv2a' ), array_map( static fn( $a ) => $par_ancre[ $a ], $r->decoupages['ouvertures'] ), 'le fleuron n’interrompt pas la suite ; image seule et couverture exclues' );
	}
);
