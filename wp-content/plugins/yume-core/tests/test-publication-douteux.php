<?php
/**
 * Paragraphes douteux dans le rapport d'analyse du formulaire de publication (lot F, 2.9.0) :
 * résumé « N paragraphes à vérifier », entrées avec la clé du chapitre et l'explication du motif,
 * adresse de la vue « Mettre en page » (/equipe/?vue=chapitre&id=ID) pour un chapitre déjà en
 * ligne, réponse REST, encart du formulaire après un envoi sans JavaScript.
 *
 * Lancement : tools/localenv/test.sh publication-douteux
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Result;
use Yume\Core\Publication\Formulaire;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_tpub' ) ) {
	// Fonctions d'aide du module publication (fixtures, enveloppe de test) sans ses tests.
	$GLOBALS['yume_tests_publication_aides_seules'] = true;
	require __DIR__ . '/test-publication.php';
	unset( $GLOBALS['yume_tests_publication_aides_seules'] );
}

if ( ! function_exists( 'yume_tist_docx' ) ) {
	// DOCX synthétique aux styles nommés et aux paragraphes douteux (tests de l'import).
	$GLOBALS['yume_tests_import_styles_aides_seules'] = true;
	require __DIR__ . '/test-import-styles.php';
	unset( $GLOBALS['yume_tests_import_styles_aides_seules'] );
}

if ( ! function_exists( 'yume_tpdt_fichier' ) ) {
	/**
	 * Entrée $_FILES du DOCX de démonstration (deux chapitres, quatre paragraphes douteux dans
	 * le second).
	 *
	 * @param stdClass $ctx Contexte (fichiers temporaires supprimés à la fin).
	 * @return array<string,mixed>
	 */
	function yume_tpdt_fichier( stdClass $ctx ): array {
		$docx            = yume_tist_docx( yume_tist_corps() );
		$ctx->fichiers[] = $docx;
		return yume_tpub_fichier( $ctx, $docx, 'Essai styles.docx' );
	}
}

yume_test(
	'publication douteux : rapport d’analyse (clé du chapitre, motif expliqué, résumé « 4 paragraphes à vérifier »)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$rapport = Service::analyser( yume_tpdt_fichier( $ctx ) );
			yume_assert_false( is_wp_error( $rapport ) );
			yume_assert_contains( '4 paragraphes à vérifier', $rapport['resume'] );
			yume_assert_same( 4, $rapport['stats']['douteux'] );
			yume_assert_same( 4, count( $rapport['douteux'] ) );
			$premier = $rapport['douteux'][0];
			yume_assert_same( 'chapitre:2#1', $premier['chapitre'] );
			yume_assert_same( 1, $premier['index'] );
			yume_assert_same( 'tiret', $premier['motif'] );
			yume_assert_same( Result::MOTIFS_DOUTEUX['tiret'], $premier['motif_libelle'] );
			yume_assert_contains( 'Tu viens', $premier['extrait'] );
			yume_assert_same( Result::MOTIFS_DOUTEUX, $rapport['motifs_douteux'] );
			yume_assert_false( isset( $rapport['comparaison']['lignes'][1]['mise_en_page'] ), 'chapitre nouveau : pas de lien' );
		}
	)
);

yume_test(
	'publication douteux : chapitre en ligne → lien « Mettre en page » (/equipe/?vue=chapitre&id=ID), aussi par REST',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre = yume_tpub_oeuvre( 'Œuvre des styles' );
			$prep   = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '2',
				),
				array( 'source' => yume_tpdt_fichier( $ctx ) )
			);
			yume_assert_false( is_wp_error( $prep ), is_wp_error( $prep ) ? $prep->get_error_message() : '' );
			$tome = (int) $prep['tome']['id'];
			$ids  = array_column( $prep['chapitres'], 'id' );
			yume_assert_same( 2, count( $ids ) );
			// Seul le chapitre 2 (celui des paragraphes douteux) est en ligne.
			wp_update_post(
				array(
					'ID'          => (int) $ids[1],
					'post_status' => 'publish',
				)
			);
			$rapport = Service::analyser( yume_tpdt_fichier( $ctx ), array( 'tome_id' => $tome ) );
			yume_assert_false( is_wp_error( $rapport ) );
			$lignes = $rapport['comparaison']['lignes'];
			yume_assert_false( isset( $lignes[0]['mise_en_page'] ), 'chapitre en brouillon : pas de lien' );
			$url = (string) ( $lignes[1]['mise_en_page'] ?? '' );
			yume_assert_contains( 'vue=chapitre', $url );
			yume_assert_contains( 'id=' . (int) $ids[1], $url );
			yume_assert_same( $url, Service::url_mise_en_page( (int) $ids[1] ) );
			yume_assert_same( $lignes[1]['index'], $rapport['douteux'][0]['index'], 'même chapitre' );
			yume_assert_same( $lignes[1]['cle'], $rapport['douteux'][0]['chapitre'], 'même clé' );

			$reponse = yume_rest( 'POST', '/yume/v1/publications/analyse', array( 'tome_id' => $tome ), $editeur, array( 'source' => yume_tpdt_fichier( $ctx ) ) );
			yume_assert_same( 200, $reponse->get_status() );
			$data = $reponse->get_data();
			yume_assert_same( 4, count( $data['douteux'] ) );
			yume_assert_same( 'italique', $data['douteux'][1]['motif'] );
			yume_assert_same( $url, $data['comparaison']['lignes'][1]['mise_en_page'] );
		}
	)
);

yume_test(
	'publication douteux : encart replié du formulaire (motifs et extraits) après un envoi sans JavaScript',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$rapport = Service::analyser( yume_tpdt_fichier( $ctx ) );
			set_transient(
				Formulaire::RETOUR . get_current_user_id(),
				array(
					'type'    => 'succes',
					'message' => 'Tome préparé.',
					'rapport' => array(
						'avertissements' => array(),
						'import'         => array(
							'resume'    => $rapport['resume'],
							'chapitres' => $rapport['chapitres'],
							'fichier'   => $rapport['fichier'],
							'douteux'   => $rapport['douteux'],
							'stats'     => array( 'douteux' => 4 ),
						),
					),
				),
				60
			);
			$html = Formulaire::rendu();
			yume_assert_contains( 'data-yn-douteux', $html );
			yume_assert_contains( '4 paragraphes à vérifier', $html );
			yume_assert_contains( esc_html( Result::MOTIFS_DOUTEUX['centre'] ), $html );
			yume_assert_contains( 'Une date centrée', $html );
			yume_assert_contains( '<details class="yn-publish__douteux"', $html );
			yume_assert_not_contains( '<details class="yn-publish__douteux" data-yn-douteux hidden', $html );
		}
	)
);
