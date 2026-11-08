<?php
/**
 * Préparation du parcours de bout en bout « sous-chapitres » (tools/ci/sous-chapitres.js) :
 * œuvre publiée et tome 2 en brouillon (planning), DOCX SYNTHÉTIQUE à la structure du tome 2 de
 * Miss Medic's Diary at War (chapitres au style Titre 1, dont deux sans texte propre, parties au
 * style Titre 2 ; texte neutre généré, jamais la traduction).
 *
 *   YUME_E2E_DOCX=/chemin/fichier.docx tools/localenv/wp.sh eval-file tools/ci/sous-chapitres.php
 *
 * Écrit sur la sortie une ligne JSON : { oeuvre, tome, publier (adresse du formulaire, tome
 * choisi), docx }. Refuse de tourner hors environnement local (YUME_ENV).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

if ( '' === (string) getenv( 'YUME_ENV' ) ) {
	fwrite( STDERR, "[sous-chapitres] YUME_ENV absent : environnement local seulement.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 2 );
}

require_once dirname( __DIR__ ) . '/fixtures/build-fixtures.php';

// Pages Yume (formulaire « Ajouter des chapitres » sur /equipe/publier/) : créées au besoin,
// comme la démo (tools/playground/demo.php).
if ( ! function_exists( 'Yume\\Core\\Core\\recreer_pages_yume' ) ) {
	fwrite( STDERR, "[sous-chapitres] Yume Core ne sait pas créer ses pages.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 2 );
}
\Yume\Core\Core\recreer_pages_yume();
flush_rewrite_rules( false );

$yume_e2e_docx = (string) getenv( 'YUME_E2E_DOCX' );
if ( '' === $yume_e2e_docx ) {
	$yume_e2e_docx = get_temp_dir() . 'yume-e2e-sous-chapitres.docx';
}

// DOCX synthétique (même structure que tests/test-import-sous-chapitres.php).
$yume_e2e_t1    = static fn( string $t ): string => yume_fx_t( $t, array( 'style' => 'Titre1' ) );
$yume_e2e_t2    = static fn( string $t ): string => yume_fx_t( $t, array( 'style' => 'Titre2' ) );
$yume_e2e_texte = static function ( string $sujet, int $n ): string {
	$html = '';
	for ( $i = 1; $i <= $n; $i++ ) {
		$html .= yume_fx_t( sprintf( 'Paragraphe %1$d de « %2$s » : la colonne avance sous la pluie, chacun garde le silence et compte ses pas jusqu’au prochain village.', $i, $sujet ) );
	}
	return $html;
};
$yume_e2e_corps = $yume_e2e_t1( 'Année 1, été 1' ) . $yume_e2e_texte( 'été 1', 6 )
	. $yume_e2e_t1( 'Bataille de la colline' ) . yume_fx_p( yume_fx_dessin( yume_fx_rid( 'carte.png' ), 'Carte de la colline' ) )
	. $yume_e2e_t2( 'Premier jour, soir' ) . $yume_e2e_texte( 'premier soir', 5 )
	. $yume_e2e_t2( 'Premier jour, nuit' ) . $yume_e2e_texte( 'première nuit', 5 )
	. $yume_e2e_t2( 'Deuxième jour, matin' ) . $yume_e2e_texte( 'deuxième matin', 4 )
	. $yume_e2e_t1( 'Année 1, été 2' ) . $yume_e2e_texte( 'été 2', 4 )
	. $yume_e2e_t1( 'Évacuation du village' ) . $yume_e2e_t2( 'Troisième jour' ) . $yume_e2e_texte( 'troisième jour', 4 )
	. $yume_e2e_t1( 'Postface' ) . $yume_e2e_texte( 'postface', 2 );
yume_fx_docx( $yume_e2e_docx, $yume_e2e_corps, yume_fx_styles_fr(), '', array( 'carte.png' => yume_fx_image( 'png', 600, 800 ) ) );

$yume_e2e_oeuvre = wp_insert_post(
	array(
		'post_type'   => 'yume_oeuvre',
		'post_status' => 'publish',
		'post_title'  => 'Journal de guerre (essai ' . gmdate( 'His' ) . ')',
	),
	true
);
if ( is_wp_error( $yume_e2e_oeuvre ) ) {
	fwrite( STDERR, '[sous-chapitres] ' . $yume_e2e_oeuvre->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 1 );
}
$yume_e2e_tome = wp_insert_post(
	array(
		'post_type'   => 'yume_tome',
		'post_status' => 'draft',
		'post_title'  => get_the_title( $yume_e2e_oeuvre ) . ' — Tome 2',
		'meta_input'  => array(
			'yume_oeuvre_id' => (int) $yume_e2e_oeuvre,
			'yume_numero'    => 2,
			'yume_nature'    => 'tome',
		),
	),
	true
);
if ( is_wp_error( $yume_e2e_tome ) ) {
	fwrite( STDERR, '[sous-chapitres] ' . $yume_e2e_tome->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 1 );
}

echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sortie console (JSON).
	array(
		'oeuvre'  => (int) $yume_e2e_oeuvre,
		'tome'    => (int) $yume_e2e_tome,
		'publier' => add_query_arg( 'tome', (int) $yume_e2e_tome, yume_url_page( 'publier' ) ),
		'docx'    => $yume_e2e_docx,
	)
) . "\n";
