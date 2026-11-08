<?php
/**
 * Données du parcours Playwright « Programmer les sorties » (tools/ci/programmer.js), sur le
 * WordPress LOCAL uniquement (tools/localenv).
 *
 * Usage :
 *   tools/localenv/wp.sh eval-file tools/ci/programmer-donnees.php preparer
 *   tools/localenv/wp.sh eval-file tools/ci/programmer-donnees.php verifier <tome>
 *   tools/localenv/wp.sh eval-file tools/ci/programmer-donnees.php nettoyer <oeuvre>
 *
 * - preparer : œuvre et tome 2 (brouillon) de test, DOCX synthétique de 45 unités construit avec
 *   tools/fixtures/build-fixtures.php (structure du tome 2 de Miss Medic : titres neutres, textes
 *   inventés aux nombres de mots de tests/fixtures/repartition-miss-medic.json) ; affiche
 *   {oeuvre, tome, docx, publier} en JSON ;
 * - verifier : chapitres du tome (statut, date locale, mots) et sorties groupées, en JSON ;
 * - nettoyer : supprime l'œuvre, ses tomes, leurs chapitres et articles d'annonce.
 *
 * @package Yume\Core
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions

defined( 'ABSPATH' ) || exit;

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) && ! defined( 'YUME_LOCALENV' ) && false === strpos( (string) home_url(), '127.0.0.1' ) ) {
	fwrite( STDERR, "programmer-donnees : réservé au WordPress local.\n" );
	exit( 2 );
}

$yume_args   = isset( $args ) && is_array( $args ) ? $args : array();
$yume_etape  = (string) ( $yume_args[0] ?? '' );
$yume_racine = dirname( __DIR__, 2 );

if ( 'preparer' === $yume_etape ) {
	require_once $yume_racine . '/tools/fixtures/build-fixtures.php';
	$yume_fixture = json_decode( (string) file_get_contents( $yume_racine . '/wp-content/plugins/yume-core/tests/fixtures/repartition-miss-medic.json' ), true );
	$yume_mots    = array( 'le', 'convoi', 'avance', 'sous', 'la', 'pluie', 'et', 'chacun', 'garde', 'le', 'silence', 'jusqu', 'au', 'soir' );
	$yume_corps   = '';
	foreach ( $yume_fixture['unites'] as $yume_u ) {
		$yume_corps .= yume_fx_t( (string) $yume_u['titre'], array( 'style' => 'Titre1' ) );
		$yume_reste  = (int) $yume_u['mots'];
		$yume_i      = 0;
		while ( $yume_reste > 0 ) {
			$yume_n      = min( 120, $yume_reste );
			$yume_phrase = array();
			for ( $yume_k = 0; $yume_k < $yume_n; $yume_k++ ) {
				$yume_phrase[] = $yume_mots[ ( $yume_i++ ) % count( $yume_mots ) ];
			}
			$yume_corps .= yume_fx_t( ucfirst( implode( ' ', $yume_phrase ) ) . '.' );
			$yume_reste -= $yume_n;
		}
	}
	$yume_docx = get_temp_dir() . 'yume-programmer-' . wp_generate_password( 6, false ) . '.docx';
	yume_fx_docx( $yume_docx, $yume_corps, yume_fx_styles_fr(), '', array() );

	$yume_oeuvre = wp_insert_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_status' => 'publish',
			'post_title'  => 'Programmation de test ' . wp_generate_password( 4, false ),
		)
	);
	$yume_tome   = wp_insert_post(
		array(
			'post_type'   => 'yume_tome',
			'post_status' => 'draft',
			'post_title'  => get_the_title( $yume_oeuvre ) . ' — Tome 2',
			'meta_input'  => array(
				'yume_oeuvre_id' => $yume_oeuvre,
				'yume_numero'    => 2,
				'yume_nature'    => 'tome',
			),
		)
	);
	echo wp_json_encode(
		array(
			'oeuvre'  => (int) $yume_oeuvre,
			'tome'    => (int) $yume_tome,
			'docx'    => $yume_docx,
			'publier' => add_query_arg( 'tome', (int) $yume_tome, yume_url_page( 'publier' ) ),
			'vue'     => \Yume\Core\Publication\Calendrier::url_vue( (int) $yume_tome ),
		)
	) . "\n";
	return;
}

if ( 'verifier' === $yume_etape ) {
	$yume_tome  = (int) ( $yume_args[1] ?? 0 );
	$yume_liste = array();
	foreach ( yume_get_chapitres( $yume_tome, array( 'status' => 'any' ) ) as $yume_c ) {
		$yume_liste[] = array(
			'id'     => (int) $yume_c->ID,
			'titre'  => get_the_title( $yume_c ),
			'statut' => $yume_c->post_status,
			'date'   => substr( str_replace( ' ', 'T', $yume_c->post_date ), 0, 16 ),
			'mots'   => (int) get_post_meta( $yume_c->ID, 'yume_nb_mots', true ),
		);
	}
	echo wp_json_encode(
		array(
			'tome'      => get_post_status( $yume_tome ),
			'chapitres' => $yume_liste,
			'groupes'   => array_map( 'count', array_column( \Yume\Core\Publication\Calendrier::groupes( $yume_tome ), 'ids' ) ),
		)
	) . "\n";
	return;
}

if ( 'nettoyer' === $yume_etape ) {
	$yume_oeuvre = (int) ( $yume_args[1] ?? 0 );
	if ( 'yume_oeuvre' !== get_post_type( $yume_oeuvre ) ) {
		return;
	}
	foreach ( get_posts(
		array(
			'post_type'      => 'yume_tome',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_key'       => 'yume_oeuvre_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $yume_oeuvre, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'         => 'ids',
		)
	) as $yume_t ) {
		\Yume\Core\Publication\Calendrier::annuler_groupes( (int) $yume_t );
		foreach ( yume_get_chapitres( (int) $yume_t, array( 'status' => 'any' ) ) as $yume_c ) {
			wp_delete_post( $yume_c->ID, true );
		}
		foreach ( get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => \Yume\Core\Publication\Annonce::META_TOME, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (int) $yume_t, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
			)
		) as $yume_article ) {
			wp_delete_post( (int) $yume_article, true );
		}
		wp_delete_post( (int) $yume_t, true );
	}
	wp_delete_post( $yume_oeuvre, true );
	return;
}

fwrite( STDERR, "Usage : wp eval-file tools/ci/programmer-donnees.php preparer | verifier <tome> | nettoyer <oeuvre>\n" );
