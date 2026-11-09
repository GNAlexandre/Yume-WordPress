<?php
/**
 * Styles Word nommés et paragraphes douteux à l'import (lot F, 2.9.0). DOCX SYNTHÉTIQUES
 * fabriqués avec ZipArchive (tools/fixtures/build-fixtures.php), texte neutre :
 * - styles nommés Récit / Narration / Narratif (récit forcé, même avec une puce tiret, un tiret
 *   tapé ou un italique entier), Dialogue (dialogue même sans puce, ou avec une numérotation),
 *   Centré / Centered / Centrer, et « - Paragraphe » (tiret puis un mot : dialogue) ;
 * - « Corps de texte » (Body Text) : récit reconnu mais non explicite, déductions inchangées ;
 *   un « Paragraphe de liste » à puce tiret basé sur lui reste un dialogue (cas de Miss Medic) ;
 * - paragraphes douteux (Result::$douteux, stats['douteux']) : tiret tapé, paragraphe de liste
 *   sans puce tiret, italique entier, centrage à la main ; extrait de 60 caractères ; plafond.
 *
 * Lancement : tools/localenv/test.sh import-styles
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Result;

defined( 'ABSPATH' ) || exit;

require_once YUME_CORE_DIR . 'includes/import/autoload.php';

if ( ! function_exists( 'yume_timp_docx' ) ) {
	// Fonctions d'aide du module import (fixtures) sans ses tests.
	$GLOBALS['yume_tests_import_aides_seules'] = true;
	require __DIR__ . '/test-import.php';
	unset( $GLOBALS['yume_tests_import_aides_seules'] );
}

if ( ! function_exists( 'yume_tist_docx' ) ) {
	/**
	 * Styles du DOCX de test : styles français de base, plus Corps de texte (Body Text),
	 * Récit, Narration, Dialogue, Centré, « - Paragraphe », « – Paragraphe », « -Paragraphe »,
	 * « Paragraphe » et un paragraphe de liste à puce tiret basé sur Corps de texte.
	 */
	function yume_tist_styles(): string {
		$style = static fn( string $id, string $nom, string $base = 'Normal', string $ppr = '' ): string => '<w:style w:type="paragraph" w:customStyle="1" w:styleId="' . $id . '"><w:name w:val="' . $nom . '"/><w:basedOn w:val="' . $base . '"/>' . ( '' !== $ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '' ) . '</w:style>';
		$extra = $style( 'Corpsdetexte', 'Body Text' )
			. $style( 'Rcit', 'Récit', 'Corpsdetexte' )
			. $style( 'Narration', 'Narration' )
			. $style( 'Dialogue', 'Dialogue' )
			. $style( 'Centr', 'Centré', 'Normal', '<w:jc w:val="center"/>' )
			. $style( '-Paragraphe', '- Paragraphe' )
			. $style( 'Paragraphetiret', '– Paragraphe' )
			. $style( 'Paragraphecolle', '-Paragraphe' )
			. $style( 'Paragraphe', 'Paragraphe' )
			. $style( 'ListeCorps', 'List Paragraph', 'Corpsdetexte', '<w:numPr><w:numId w:val="1"/></w:numPr>' );
		return str_replace( '</w:styles>', $extra . '</w:styles>', yume_fx_styles_fr() );
	}

	/**
	 * Construit un DOCX temporaire avec ces styles et la numérotation française des fixtures
	 * (numId 1 : puce « — », 4 : puce Wingdings, 5 : numéros).
	 *
	 * @param string $corps Contenu de w:body.
	 * @return string Chemin du fichier (à supprimer par l'appelant).
	 */
	function yume_tist_docx( string $corps ): string {
		yume_timp_outils_fixtures();
		$fichier = get_temp_dir() . 'yume-test-' . wp_generate_password( 8, false ) . '.docx';
		yume_fx_docx( $fichier, $corps, yume_tist_styles(), yume_fx_numerotation_fr(), array() );
		return $fichier;
	}

	/**
	 * Convertit un corps de document et supprime le fichier.
	 *
	 * @param string $corps Contenu de w:body.
	 */
	function yume_tist_convertir( string $corps ): Result {
		$fichier = yume_tist_docx( $corps );
		try {
			return Docx_Converter::convert_file( $fichier );
		} finally {
			wp_delete_file( $fichier );
		}
	}

	/**
	 * Paragraphes d'un chapitre : [classes, texte] dans l'ordre.
	 *
	 * @param array<string,mixed> $chapitre Chapitre du Result.
	 * @return array<int,array{0:string,1:string}>
	 */
	function yume_tist_paragraphes( array $chapitre ): array {
		$liste = array();
		foreach ( parse_blocks( (string) $chapitre['blocks'] ) as $bloc ) {
			if ( 'core/paragraph' === $bloc['blockName'] ) {
				$liste[] = array( (string) ( $bloc['attrs']['className'] ?? '' ), trim( wp_strip_all_tags( $bloc['innerHTML'] ) ) );
			} elseif ( null !== $bloc['blockName'] ) {
				$liste[] = array( $bloc['blockName'], trim( wp_strip_all_tags( render_block( $bloc ) ) ) );
			}
		}
		return $liste;
	}

	/**
	 * Document de démonstration : un chapitre aux styles nommés, un chapitre aux paragraphes
	 * douteux.
	 */
	function yume_tist_corps(): string {
		yume_timp_outils_fixtures();
		$t1 = static fn( string $t ): string => yume_fx_t( $t, array( 'style' => 'Titre1' ) );
		$p  = static fn( string $t, array $o = array() ): string => yume_fx_t( $t, $o );
		return $t1( 'Chapitre 1 : Les styles' )
			. $p(
				'Elle marchait sous la pluie.',
				array(
					'style' => 'Rcit',
					'num'   => array( 1 ),
				)
			)
			. $p( '— Ceci reste du récit.', array( 'style' => 'Rcit' ) )
			. yume_fx_p( yume_fx_r( 'Un souvenir en italique, gardé en récit.', array( 'i' => true ) ), array( 'style' => 'Rcit' ) )
			. $p(
				'La ville dormait encore.',
				array(
					'style' => 'Narration',
					'num'   => array( 1 ),
				)
			)
			. $p( 'Bonjour, dit-elle.', array( 'style' => 'Dialogue' ) )
			. $p(
				'Je compte jusqu’à trois.',
				array(
					'style' => 'Dialogue',
					'num'   => array( 5 ),
				)
			)
			. $p( 'Paris, le 3 mai', array( 'style' => 'Centr' ) )
			. $p( 'Réplique au style tiret.', array( 'style' => '-Paragraphe' ) )
			. $p( 'Réplique au demi-cadratin.', array( 'style' => 'Paragraphetiret' ) )
			. $p( 'Réplique au tiret collé.', array( 'style' => 'Paragraphecolle' ) )
			. $p( 'Un paragraphe ordinaire.', array( 'style' => 'Paragraphe' ) )
			. $p( 'Réplique en liste.', array( 'style' => 'ListeCorps' ) )
			. $p( 'Le récit au corps de texte.', array( 'style' => 'Corpsdetexte' ) )
			. $t1( 'Chapitre 2 : Les douteux' )
			. $p( '— Tu viens ? demanda-t-il.', array( 'style' => 'Corpsdetexte' ) )
			. yume_fx_p( yume_fx_r( 'Une pensée tapée en italique, sans le style Pensée, bien plus longue que soixante caractères.', array( 'i' => true ) ), array( 'style' => 'Corpsdetexte' ) )
			. $p( 'Une date centrée', array( 'jc' => 'center' ) )
			. $p(
				'Une liste sans puce.',
				array(
					'style' => 'Paragraphedeliste',
					'num'   => array( 0 ),
				)
			)
			. $p( 'Réplique à puce tiret.', array( 'style' => 'Paragraphedeliste' ) )
			. yume_fx_p( yume_fx_r( 'Une vraie pensée.' ), array( 'style' => 'Pense' ) );
	}
}

if ( ! empty( $GLOBALS['yume_tests_import_styles_aides_seules'] ) ) {
	return;
}

yume_test(
	'import styles : Récit, Narration, Dialogue, Centré et « - Paragraphe » nommés l’emportent sur la puce et le tiret',
	function () {
		$r = yume_tist_convertir( yume_tist_corps() );
		yume_assert_same( 2, count( $r->chapters ) );
		yume_assert_same(
			array(
				array( '', 'Elle marchait sous la pluie.' ),
				array( '', '— Ceci reste du récit.' ),
				array( '', 'Un souvenir en italique, gardé en récit.' ),
				array( '', 'La ville dormait encore.' ),
				array( 'yn-dialogue', '— Bonjour, dit-elle.' ),
				array( 'yn-dialogue', '— Je compte jusqu’à trois.' ),
				array( 'yn-center', 'Paris, le 3 mai' ),
				array( 'yn-dialogue', '— Réplique au style tiret.' ),
				array( 'yn-dialogue', '— Réplique au demi-cadratin.' ),
				array( 'yn-dialogue', '— Réplique au tiret collé.' ),
				array( '', 'Un paragraphe ordinaire.' ),
				array( 'yn-dialogue', '— Réplique en liste.' ),
				array( '', 'Le récit au corps de texte.' ),
			),
			array_map(
				static fn( $l ) => array( $l[0], str_replace( "\u{00A0}", ' ', $l[1] ) ),
				yume_tist_paragraphes( $r->chapters[0] )
			)
		);
		yume_assert_same( 0, $r->chapters[0]['stats']['douteux'], 'styles nommés : rien à vérifier' );
	}
);

yume_test(
	'import styles : paragraphes douteux (tiret tapé, italique, centrage, liste sans puce) par chapitre',
	function () {
		$r = yume_tist_convertir( yume_tist_corps() );
		yume_assert_same(
			array(
				array( 'yn-dialogue', '— Tu viens ? demanda-t-il.' ),
				array( '', 'Une pensée tapée en italique, sans le style Pensée, bien plus longue que soixante caractères.' ),
				array( 'yn-center', 'Une date centrée' ),
				array( '', 'Une liste sans puce.' ),
				array( 'yn-dialogue', '— Réplique à puce tiret.' ),
				array( 'yn-thought', 'Une vraie pensée.' ),
			),
			array_map(
				static fn( $l ) => array( $l[0], str_replace( "\u{00A0}", ' ', $l[1] ) ),
				yume_tist_paragraphes( $r->chapters[1] )
			)
		);
		yume_assert_same( array( 'tiret', 'italique', 'centre', 'liste' ), array_column( $r->douteux, 'motif' ) );
		yume_assert_same( array( 1, 1, 1, 1 ), array_column( $r->douteux, 'chapitre' ), 'index du chapitre' );
		yume_assert_same( 4, $r->stats['douteux'] );
		yume_assert_same( 4, $r->chapters[1]['stats']['douteux'] );
		yume_assert_false( $r->stats['douteux_tronques'] );
		$rangs = array_column( $r->douteux, 'rang' );
		yume_assert_true( array_values( array_unique( $rangs ) ) === $rangs && $rangs[0] > 0, 'rangs distincts' );
		$extrait = $r->douteux[1]['extrait'];
		yume_assert_same( 60, mb_strlen( $extrait ), 'extrait de 60 caractères' );
		yume_assert_true( str_ends_with( $extrait, '…' ) && str_starts_with( $extrait, 'Une pensée tapée en italique' ) );
		yume_assert_same( 'Une date centrée', $r->douteux[2]['extrait'] );
		foreach ( $r->douteux as $d ) {
			yume_assert_true( isset( Result::MOTIFS_DOUTEUX[ $d['motif'] ] ) );
		}
		yume_assert_same( $r->douteux, $r->rapport()['douteux'], 'rapport' );
	}
);

yume_test(
	'import styles : un style « Paragraphe » seul n’est pas un dialogue, « - Paragraphe » l’est (nom ou identifiant)',
	function () {
		yume_timp_outils_fixtures();
		$t1 = yume_fx_t( 'Chapitre 1 : Essai', array( 'style' => 'Titre1' ) );
		$r  = yume_tist_convertir(
			$t1 . yume_fx_t( 'Texte simple.', array( 'style' => 'Paragraphe' ) )
			. yume_fx_t( 'Texte réplique.', array( 'style' => '-Paragraphe' ) )
		);
		yume_assert_same( array( '', 'yn-dialogue' ), array_column( yume_tist_paragraphes( $r->chapters[0] ), 0 ) );
		yume_assert_same( 0, $r->stats['douteux'] );
	}
);

yume_test(
	'import styles : paragraphes douteux plafonnés (Result::DOUTEUX_MAX), tous comptés',
	function () {
		yume_timp_outils_fixtures();
		$corps = yume_fx_t( 'Chapitre 1 : Beaucoup de répliques', array( 'style' => 'Titre1' ) );
		for ( $i = 1; $i <= Result::DOUTEUX_MAX + 30; $i++ ) {
			$corps .= yume_fx_t( '— Réplique tapée numéro ' . $i . '.' );
		}
		$r = yume_tist_convertir( $corps );
		yume_assert_same( Result::DOUTEUX_MAX, count( $r->douteux ) );
		yume_assert_same( Result::DOUTEUX_MAX + 30, $r->stats['douteux'] );
		yume_assert_true( $r->stats['douteux_tronques'] );
		yume_assert_same( Result::DOUTEUX_MAX + 30, $r->stats['dialogues'], 'conversion inchangée' );
	}
);

yume_test(
	'import styles : sans style nommé, aucune différence de rendu (regles.docx : douteux cohérents)',
	function () {
		$r = yume_timp_conversion( 'regles.docx' );
		yume_assert_true( $r->stats['douteux'] >= 0 );
		yume_assert_same( count( $r->douteux ), min( Result::DOUTEUX_MAX, $r->stats['douteux'] ) );
		foreach ( $r->douteux as $d ) {
			yume_assert_true( isset( $r->chapters[ $d['chapitre'] ] ), 'chapitre existant' );
			yume_assert_true( mb_strlen( $d['extrait'] ) <= Result::EXTRAIT_DOUTEUX );
		}
	}
);
