<?php
/**
 * Préparation du parcours de bout en bout « mise en page » (tools/ci/mise-en-page.js) : contenu
 * de démonstration (tools/playground/demo.php) puis texte du chapitre 1 du tome 1 remis à son
 * état de référence (récit, dialogues, pensée, séparateur), pour que le parcours se rejoue sur
 * la même base.
 *
 * Lancement : tools/localenv/wp.sh eval-file tools/ci/mise-en-page.php
 *
 * Écrit sur la sortie une ligne JSON : { chapitre, vue (adresse de la vue « Mettre en page »),
 * lecture (page publique du chapitre) }. Refuse de tourner hors environnement local (YUME_ENV).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

if ( '' === (string) getenv( 'YUME_ENV' ) ) {
	fwrite( STDERR, "[mise-en-page] YUME_ENV absent : environnement local seulement.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 2 );
}

ob_start();
require dirname( __DIR__ ) . '/playground/demo.php';
ob_end_clean();
flush_rewrite_rules( false );

$yume_e2e_ids = get_posts(
	array(
		'post_type'      => 'yume_chapitre',
		'post_status'    => 'publish',
		'name'           => 'chapitre-1',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
);
if ( ! $yume_e2e_ids || ! function_exists( 'yume_mise_en_page_url' ) ) {
	fwrite( STDERR, "[mise-en-page] chapitre de démonstration ou module mise-en-page introuvable.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 1 );
}
$yume_e2e_chapitre = (int) $yume_e2e_ids[0];

// Texte de référence du chapitre 1 de la démo (le parcours le modifie à chaque passage).
$yume_e2e_p       = static fn( string $texte, array $classes = array() ): string => \Yume\Core\Import\Blocks::paragraphe( esc_html( $texte ), $classes );
$yume_e2e_contenu = \Yume\Core\Import\Blocks::assembler(
	array(
		$yume_e2e_p( 'Le vent descendait des crêtes en sifflant entre les toits. Sora referma la porte de l’atelier et souffla sur ses doigts gelés.' ),
		$yume_e2e_p( "—\u{00A0}Tu as vu la lanterne des Aoki ? demanda sa sœur depuis l’escalier.", array( 'yn-dialogue' ) ),
		$yume_e2e_p( "—\u{00A0}Elle est éteinte depuis des années.", array( 'yn-dialogue' ) ),
		$yume_e2e_p( 'Pourtant, ce soir, elle brillait.', array( 'yn-thought' ) ),
		\Yume\Core\Import\Blocks::separateur(),
		$yume_e2e_p( 'Au matin, la place du village était couverte de givre, et personne n’osait parler de la lumière.' ),
	)
);
$yume_e2e_maj     = wp_update_post(
	wp_slash(
		array(
			'ID'           => $yume_e2e_chapitre,
			'post_content' => $yume_e2e_contenu,
		)
	),
	true
);
if ( is_wp_error( $yume_e2e_maj ) ) {
	fwrite( STDERR, '[mise-en-page] ' . $yume_e2e_maj->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sortie console.
	exit( 1 );
}
delete_transient( 'yume_mep_' . $yume_e2e_chapitre );

echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sortie console (JSON).
	array(
		'chapitre' => $yume_e2e_chapitre,
		'vue'      => yume_mise_en_page_url( $yume_e2e_chapitre ),
		'lecture'  => (string) get_permalink( $yume_e2e_chapitre ),
	)
) . "\n";
