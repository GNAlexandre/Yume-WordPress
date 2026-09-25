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
	'docx : images gardées, EMF ignorées, galerie avant le premier chapitre',
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
		yume_assert_same( 5, $r->stats['images_gardees'] );
		yume_assert_contains( '2 images au format Word EMF/WMF ignorées (format non convertible) : ornement.emf (avant le premier chapitre), ornement.emf (Chapitre 1).', implode( "\n", $r->warnings ) );
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
		yume_assert_same( 10, count( $r->images ) );
		yume_assert_same( 6, $r->stats['images_emf'] );
		yume_assert_same( 2, $r->stats['sauts_de_page'] );
		yume_assert_same( array( 'image3', 'image4', 'image5', 'image6', 'image7', 'image8' ), $r->front_images );
		yume_assert_same( 4, array_sum( array_map( static fn( $c ) => count( $c['images'] ), $r->chapters ) ) );
		$avert = implode( "\n", $r->warnings );
		yume_assert_contains( 'Chapitre 2 : titre « Chapitre2 » sans espace', $avert );
		yume_assert_contains( 'Chapitre 13 : titre précédé d’une espace', $avert );
		yume_assert_contains( '6 images au format Word EMF/WMF ignorées', $avert );
		foreach ( $r->chapters as $c ) {
			yume_assert_true( $c['nb_mots'] > 0, $c['titre'] . ' non vide' );
			yume_timp_blocs_valides( $c['blocks'], 'grimgar ' . $c['titre'] );
		}
	}
);
