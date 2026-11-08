<?php
/**
 * Sous-chapitres à la publication (lot B, 2.8.0) : découpage « Couper aux Titre 2 » envoyé à
 * l'analyse et à la préparation (REST), contrôle du découpage, métas écrites (nature
 * sous-chapitre, numéro du parent, rang, titre du parent), titres et adresses
 * (chapitre-2-partie-1), ordre de lecture, comparaison avec le tome (clés
 * « sous-chapitre:2-1#1 ») puis ajout de chapitres, libellés des annonces et résumés.
 *
 * DOCX synthétique : yume_tisc_docx() (tests/test-import-sous-chapitres.php).
 *
 * Lancement : tools/localenv/test.sh publication-sous-chapitres
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Docx_Converter;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_tpub' ) ) {
	// Fonctions d'aide du module publication (fixtures, enveloppe de test) sans ses tests.
	$GLOBALS['yume_tests_publication_aides_seules'] = true;
	require __DIR__ . '/test-publication.php';
	unset( $GLOBALS['yume_tests_publication_aides_seules'] );
}
if ( ! function_exists( 'yume_tisc_docx' ) ) {
	$GLOBALS['yume_tests_sous_chapitres_aides_seules'] = true;
	require __DIR__ . '/test-import-sous-chapitres.php';
	unset( $GLOBALS['yume_tests_sous_chapitres_aides_seules'] );
}

if ( ! function_exists( 'yume_tpsc_fichier' ) ) {
	/**
	 * DOCX synthétique (structure Miss Medic) et son entrée $_FILES.
	 *
	 * @param stdClass $ctx Contexte (fichiers supprimés à la fin).
	 * @return array<string,mixed>
	 */
	function yume_tpsc_fichier( stdClass $ctx ): array {
		$docx            = yume_tisc_docx();
		$ctx->fichiers[] = $docx;
		return yume_tpub_fichier( $ctx, $docx, 'Journal_T2.docx' );
	}

	/**
	 * Découpage « Couper aux Titre 2 » du DOCX synthétique (premier numéro : 1).
	 *
	 * @return array<string,mixed>
	 */
	function yume_tpsc_plan(): array {
		$docx = yume_tisc_docx();
		try {
			return yume_tisc_plan_titre2( Docx_Converter::convert_file( $docx ), 1.0 );
		} finally {
			wp_delete_file( $docx );
		}
	}
}

yume_test(
	'sous-chapitres : analyse REST (découpages titres / sous_chapitres), découpage contrôlé, aperçu et comparaison',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			$analyse = yume_rest( 'POST', '/yume/v1/publications/analyse', array(), $editeur, array( 'source' => yume_tpsc_fichier( $ctx ) ) );
			yume_assert_same( 200, $analyse->get_status(), wp_json_encode( $analyse->get_data() ) );
			$data = $analyse->get_data();
			yume_assert_same( 7, count( $data['decoupages']['titres'] ) );
			yume_assert_same( 6, count( $data['decoupages']['sous_chapitres'] ) );
			yume_assert_same( array(), array_values( array_filter( array_column( $data['chapitres'], 'nature' ), static fn( $n ) => 'sous-chapitre' === $n ) ), 'détection automatique : aucun sous-chapitre' );

			// Le découpage est contrôlé : sous-chapitres acceptés (une clé par partie).
			$plan = yume_tpsc_plan();
			$ok   = Service::plan( $plan );
			yume_assert_false( is_wp_error( $ok ), is_wp_error( $ok ) ? $ok->get_error_message() : '' );
			yume_assert_same( 'sous-chapitre', $ok['debuts'][4]['nature'] );
			// Deux chapitres au même numéro restent refusés.
			$double                        = $plan;
			$double['debuts'][6]['numero'] = 3; // Chapitre 4 (Année 1, été 2) renuméroté 3, comme le parent des parties.
			$refus                         = Service::plan( $double );
			yume_assert_true( is_wp_error( $refus ) );
			yume_assert_contains( 'même numéro', $refus->get_error_message() );

			$essai = yume_rest( 'POST', '/yume/v1/publications/analyse', array( 'plan' => wp_json_encode( $plan ) ), $editeur, array( 'source' => yume_tpsc_fichier( $ctx ) ) );
			yume_assert_same( 200, $essai->get_status(), wp_json_encode( $essai->get_data() ) );
			$data = $essai->get_data();
			yume_assert_same(
				array( 'Prologue', 'Chapitre 1 — Souvenir lointain', 'Chapitre 2 — Année 1, été 1', 'Chapitre 3, partie 1 — Premier jour, soir', 'Chapitre 3, partie 2 — Premier jour, nuit', 'Chapitre 4 — Année 1, été 2', 'Chapitre 5, partie 1 — Quatrième jour', 'Chapitre 6 — Évacuation du village', 'Chapitre 6, partie 1 — Cinquième jour', 'Chapitre 6, partie 2 — Sixième jour', 'Postface' ),
				array_column( $data['chapitres'], 'libelle' )
			);
			yume_assert_same( 'Bataille de la colline', $data['chapitres'][3]['chapitre_parent'] );
			yume_assert_same( 2, $data['chapitres'][4]['sous_numero'] );
			yume_assert_same( '6 chapitres (5 sous-chapitres) + prologue, postface · 1 illustration', $data['resume'] );
			yume_assert_contains( 'Sous-chapitre « Souvenir lointain » sans chapitre parent : traité comme un chapitre.', implode( "\n", $data['avertissements'] ) );
			$lignes = $data['comparaison']['lignes'];
			yume_assert_same( array( 'prologue:0#1', 'chapitre:1#1', 'chapitre:2#1', 'sous-chapitre:3-1#1', 'sous-chapitre:3-2#1', 'chapitre:4#1', 'sous-chapitre:5-1#1', 'chapitre:6#1', 'sous-chapitre:6-1#1', 'sous-chapitre:6-2#1', 'postface:#1' ), array_column( $lignes, 'cle' ) );
			yume_assert_same( 'Chapitre 3, partie 1', $lignes[3]['libelle'] );
			yume_assert_same( 'Chapitre 3, partie 1 — Premier jour, soir', $lignes[3]['titre'] );
			yume_assert_same( 1, $lignes[3]['sous_numero'] );
			yume_assert_same( 'Bataille de la colline', $lignes[3]['chapitre_parent'] );
			yume_assert_same( null, $lignes[2]['sous_numero'] );
		}
	)
);

yume_test(
	'sous-chapitres : préparation (REST) — métas, titres, adresses, ordre ; ajout de chapitres comparé ; libellés groupés',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre = yume_tpub_oeuvre( 'Journal de guerre' );
			$plan   = yume_tpsc_plan();
			$res    = yume_rest(
				'POST',
				'/yume/v1/publications',
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '2',
					'plan'      => wp_json_encode( $plan ),
				),
				$editeur,
				array( 'source' => yume_tpsc_fichier( $ctx ) )
			);
			yume_assert_same( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
			$data = $res->get_data();
			$tome = (int) $data['tome']['id'];
			yume_assert_same( 1, $data['chapitres'][3]['sous_numero'], 'résumé du contenu : rang de la partie' );
			yume_assert_same( 'Bataille de la colline', $data['chapitres'][3]['chapitre_parent'] );
			yume_assert_false( isset( $data['chapitres'][2]['sous_numero'] ), 'chapitre ordinaire : pas de rang' );

			$chapitres = yume_get_chapitres( $tome, array( 'status' => 'any' ) );
			yume_assert_same( 11, count( $chapitres ) );
			yume_assert_same( range( 1, 11 ), array_map( static fn( $c ) => (int) $c->menu_order, $chapitres ), 'menu_order séquentiel, ordre du fichier' );
			$partie = $chapitres[3];
			yume_assert_same( 'sous-chapitre', get_post_meta( $partie->ID, 'yume_nature', true ) );
			yume_assert_same( 3.0, (float) get_post_meta( $partie->ID, 'yume_numero', true ) );
			yume_assert_same( 1, (int) get_post_meta( $partie->ID, 'yume_sous_numero', true ) );
			yume_assert_same( 'Premier jour, soir', get_post_meta( $partie->ID, 'yume_sous_titre', true ) );
			yume_assert_same( 'Bataille de la colline', get_post_meta( $partie->ID, 'yume_chapitre_parent', true ) );
			yume_assert_same( 'Chapitre 3, partie 1 — Premier jour, soir', $partie->post_title );
			yume_assert_same( 'chapitre-3-partie-1', $partie->post_name );
			yume_assert_contains( 'wp:image', $partie->post_content, 'illustration du parent sans texte' );
			yume_assert_same( 'chapitre-6-partie-2', $chapitres[9]->post_name );
			yume_assert_same( 'Chapitre 6 — Évacuation du village', $chapitres[7]->post_title );
			yume_assert_false( metadata_exists( 'post', $chapitres[7]->ID, 'yume_sous_numero' ), 'chapitre ordinaire sans rang' );
			yume_assert_same( 'Chapitre 3, partie 1', yume_libelle_chapitre( $partie->ID ) );

			// Libellés groupés (annonces de plusieurs chapitres).
			$ids = wp_list_pluck( $chapitres, 'ID' );
			yume_assert_same( 'Chapitre 3, parties 1 et 2', Service::libelle_groupe( array( $ids[3], $ids[4] ) ) );
			yume_assert_same( 'Chapitre 6 et ses parties 1 à 2', Service::libelle_groupe( array( $ids[7], $ids[8], $ids[9] ) ) );
			yume_assert_same( 'Chapitres 2 à 4', Service::libelle_groupe( array( $ids[2], $ids[3], $ids[4], $ids[5] ) ) );

			// Second passage du même fichier (ajout de chapitres) : chaque partie retrouve son brouillon.
			wp_set_current_user( $editeur );
			$analyse = Service::analyser(
				yume_tpsc_fichier( $ctx ),
				array(
					'tome_id' => $tome,
					'plan'    => $plan,
				)
			);
			yume_assert_false( is_wp_error( $analyse ) );
			yume_assert_same( array( 'brouillon' ), array_values( array_unique( array_column( $analyse['comparaison']['lignes'], 'etat' ) ) ) );

			// Sortie, puis même fichier : tout est identique, rien n'est recréé.
			Service::publier( $tome, 'maintenant' );
			// Nouvelle requête simulée : la publication précédente est terminée.
			unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
			\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
			\Yume\Core\Core\etat_set( 'publies_yume_chapitre', array() );
			$analyse = Service::analyser(
				yume_tpsc_fichier( $ctx ),
				array(
					'tome_id' => $tome,
					'plan'    => $plan,
				)
			);
			yume_assert_same( array( 'identique' ), array_values( array_unique( array_column( $analyse['comparaison']['lignes'], 'etat' ) ) ) );
			$r = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => $tome,
					'mode'      => Service::MODE_CHAPITRES,
					'plan'      => $plan,
				),
				array( 'source' => yume_tpsc_fichier( $ctx ) )
			);
			yume_assert_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
			yume_assert_same( 11, count( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) ) );
			yume_assert_same( array( 'inchange' ), array_values( array_unique( array_column( $r['chapitres'], 'action' ) ) ) );
		}
	)
);
