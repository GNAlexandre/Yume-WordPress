<?php
/**
 * Tests du module wordend (easter egg WordEnd) : fichiers livrés et planche de Chtholly,
 * configuration du jeu (URLs versionnées, filtres), script déclencheur en façade (defer,
 * configuration posée avant), coupure par filtre, papillon de la fiche d'œuvre.
 *
 * Commande : tools/localenv/test.sh wordend
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\WordEnd\ajouter_papillon;
use function Yume\Core\WordEnd\configuration;
use function Yume\Core\WordEnd\enfiler_declencheur;
use function Yume\Core\WordEnd\fichiers_requis;
use function Yume\Core\WordEnd\oeuvres_declencheuses;
use const Yume\Core\WordEnd\POIGNEE;
use const Yume\Core\WordEnd\POIGNEE_SECRET;

// Module non chargé (YUME_ONLY_MODULES sans « wordend ») : rien à tester.
if ( ! function_exists( 'Yume\Core\WordEnd\configuration' ) ) {
	return;
}

/**
 * Retire le déclencheur et la feuille du papillon des files (état global des scripts).
 */
function yume_twe_vider_files(): void {
	wp_dequeue_script( POIGNEE );
	wp_dequeue_style( POIGNEE_SECRET );
	$scripts = wp_scripts();
	if ( isset( $scripts->registered[ POIGNEE ] ) ) {
		unset( $scripts->registered[ POIGNEE ]->extra['before'] );
	}
}

/**
 * Rendu simulé de yume/oeuvre-header passé au filtre du papillon.
 *
 * @param int $oeuvre_id Œuvre (contexte du bloc).
 */
function yume_twe_papillon( int $oeuvre_id ): string {
	$html     = '<div class="wp-block-yume-oeuvre-header yn-oeuvre-header"><div class="yn-oeuvre-header__tete"><h1 class="yn-oeuvre-header__titre">Titre</h1></div></div>';
	$instance = new WP_Block(
		array(
			'blockName'    => 'yume/oeuvre-header',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array(
			'postId'   => $oeuvre_id,
			'postType' => 'yume_oeuvre',
		)
	);
	return (string) ajouter_papillon( $html, array( 'blockName' => 'yume/oeuvre-header' ), $instance );
}

yume_test(
	'wordend : module chargé, déclencheur accroché en façade seulement',
	function () {
		yume_assert_true( in_array( 'wordend', YUME_CORE_MODULES, true ), 'module déclaré' );
		yume_assert_true( false !== has_action( 'wp_enqueue_scripts', 'Yume\Core\WordEnd\enfiler_declencheur' ), 'accroché à wp_enqueue_scripts' );
		yume_assert_false( has_action( 'admin_enqueue_scripts', 'Yume\Core\WordEnd\enfiler_declencheur' ), 'jamais en administration' );
		yume_assert_true( wp_script_is( POIGNEE, 'registered' ), 'script enregistré' );
		yume_assert_same( 'defer', wp_scripts()->get_data( POIGNEE, 'strategy' ), 'chargé en defer' );
	}
);

/**
 * Vérifie une planche (PNG + JSON) : nombre d'images par animation, dimensions, cadres.
 *
 * @param string $nom     Nom de base (chtholly, timere).
 * @param array  $attendu animation => nombre d'images.
 */
function yume_twe_verifier_planche( string $nom, array $attendu ): void {
	$dossier = YUME_CORE_DIR . 'includes/wordend/assets/';
	$meta    = json_decode( (string) file_get_contents( $dossier . $nom . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	yume_assert_true( is_array( $meta ), "$nom : JSON valide" );
	foreach ( $attendu as $animation => $nombre ) {
		yume_assert_same( $nombre, count( $meta['animations'][ $animation ]['images'] ?? array() ), "$nom : images de « $animation »" );
	}
	$taille = getimagesize( $dossier . $nom . '.png' );
	yume_assert_same( $meta['planche'], array( $taille[0], $taille[1] ), "$nom : dimensions de la planche" );
	foreach ( $meta['animations'] as $animation => $donnees ) {
		foreach ( $donnees['images'] as $cadre ) {
			yume_assert_true( $cadre[0] + $cadre[2] <= $taille[0] && $cadre[1] + $cadre[3] <= $taille[1], "$nom : cadre de « $animation » dans la planche" );
			yume_assert_true( $cadre[4] >= 0 && $cadre[4] <= $cadre[2] && $cadre[5] >= 0 && $cadre[5] <= $cadre[3], "$nom : ancre de « $animation » dans son cadre" );
		}
		foreach ( $donnees['coup'] ?? array() as $indice ) {
			yume_assert_true( $indice < count( $donnees['images'] ), "$nom : image de coup de « $animation » existante" );
		}
	}
}

yume_test(
	'wordend : fichiers livrés, planches de Chtholly et du Timere cohérentes',
	function () {
		foreach ( fichiers_requis() as $chemin ) {
			yume_assert_true( is_readable( $chemin ), basename( $chemin ) . ' présent' );
		}
		yume_twe_verifier_planche(
			'chtholly',
			array(
				'repos'   => 2,
				'marche'  => 6,
				'course'  => 5,
				'attaque' => 4,
				'charge'  => 4,
				'degats'  => 1,
				'mort'    => 1,
			)
		);
		$timere = json_decode( (string) file_get_contents( YUME_CORE_DIR . 'includes/wordend/assets/timere.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( array( 'fouet', 'morsure' ) as $attaque ) {
			yume_assert_true( ! empty( $timere['animations'][ $attaque ]['coup'] ), "timere : images de coup de « $attaque »" );
		}
		yume_twe_verifier_planche(
			'timere',
			array(
				'repos'   => 5,
				'marche'  => 4,
				'course'  => 6,
				'fouet'   => 4,
				'morsure' => 4,
				'degats'  => 5,
				'mort'    => 6,
			)
		);
	}
);

yume_test(
	'wordend : configuration (URLs versionnées) et filtre des œuvres',
	function () {
		$config = configuration();
		foreach ( array( 'jeu', 'style', 'planche', 'meta', 'timere', 'timereMeta' ) as $cle ) {
			yume_assert_contains( YUME_CORE_URL . 'includes/wordend/assets/', $config[ $cle ], "URL « $cle »" );
			yume_assert_contains( 'ver=', $config[ $cle ], "version de « $cle »" );
		}
		yume_assert_same( array( 'sukasuka' ), oeuvres_declencheuses() );

		$filtre = static function () {
			return array( 'Grimgar !', 'sukasuka', '' );
		};
		add_filter( 'yume_wordend_oeuvres', $filtre );
		try {
			yume_assert_same( array( 'grimgar', 'sukasuka' ), oeuvres_declencheuses(), 'slugs assainis et dédoublonnés' );
		} finally {
			remove_filter( 'yume_wordend_oeuvres', $filtre );
		}
	}
);

yume_test(
	'wordend : déclencheur mis en file avec sa configuration, coupé par yume_wordend_actif',
	function () {
		yume_twe_vider_files();
		try {
			enfiler_declencheur();
			yume_assert_true( wp_script_is( POIGNEE, 'enqueued' ), 'mis en file' );
			$avant = implode( "\n", (array) wp_scripts()->get_data( POIGNEE, 'before' ) );
			yume_assert_contains( 'window.ynWordEnd = ', $avant );
			yume_assert_contains( 'chtholly.png', $avant );
			yume_assert_not_contains( '<', $avant, 'aucune balise dans la configuration' );

			yume_twe_vider_files();
			add_filter( 'yume_wordend_actif', '__return_false' );
			enfiler_declencheur();
			yume_assert_false( wp_script_is( POIGNEE, 'enqueued' ), 'coupé par le filtre' );
		} finally {
			remove_filter( 'yume_wordend_actif', '__return_false' );
			yume_twe_vider_files();
		}
	}
);

yume_test(
	'wordend : papillon sur la fiche des œuvres déclencheuses seulement',
	function () {
		yume_twe_vider_files();
		$sukasuka = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => 'SukaSuka',
				'post_name'   => 'sukasuka',
				'post_status' => 'publish',
			)
		);
		$autre    = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => 'Autre œuvre',
				'post_name'   => 'autre-oeuvre',
				'post_status' => 'publish',
			)
		);
		// Slug réel (sukasuka-2 si la base locale a déjà une œuvre SukaSuka).
		$slugs = static function () use ( $sukasuka ) {
			return array( get_post_field( 'post_name', $sukasuka ) );
		};
		add_filter( 'yume_wordend_oeuvres', $slugs );
		try {
			$html = yume_twe_papillon( $sukasuka );
			yume_assert_contains( 'data-yn-wordend-ouvrir', $html );
			yume_assert_contains( 'aria-label="', $html );
			yume_assert_contains( '</h1><button type="button" class="yn-wordend-secret"', $html, 'juste après le titre' );
			yume_assert_true( wp_style_is( POIGNEE_SECRET, 'enqueued' ), 'feuille du papillon en file' );

			yume_assert_not_contains( 'data-yn-wordend-ouvrir', yume_twe_papillon( $autre ), 'autre œuvre' );

			add_filter( 'yume_wordend_actif', '__return_false' );
			yume_assert_not_contains( 'data-yn-wordend-ouvrir', yume_twe_papillon( $sukasuka ), 'easter egg coupé' );
		} finally {
			remove_filter( 'yume_wordend_actif', '__return_false' );
			remove_filter( 'yume_wordend_oeuvres', $slugs );
			yume_twe_vider_files();
		}
	}
);
