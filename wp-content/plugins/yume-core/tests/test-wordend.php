<?php
/**
 * Tests du module wordend (easter egg WordEnd) : fichiers livrés (moteur, univers), planches de
 * Chtholly et du Timere, données de l'univers SukaSuka (manifeste, personnage, ennemi, niveau),
 * configuration du jeu (scripts dans l'ordre, URLs versionnées, univers, filtres), script
 * déclencheur en façade (defer, configuration posée avant), coupure par filtre, papillon de la
 * fiche d'œuvre.
 *
 * Commande : tools/localenv/test.sh wordend
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\WordEnd\ajouter_papillon;
use function Yume\Core\WordEnd\configuration;
use function Yume\Core\WordEnd\chemin_manifeste;
use function Yume\Core\WordEnd\enfiler_declencheur;
use function Yume\Core\WordEnd\fichiers_requis;
use function Yume\Core\WordEnd\oeuvres_declencheuses;
use function Yume\Core\WordEnd\univers;
use const Yume\Core\WordEnd\POIGNEE;
use const Yume\Core\WordEnd\POIGNEE_SECRET;
use const Yume\Core\WordEnd\SCRIPTS_MOTEUR;

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

/** Dossier de l'univers SukaSuka. */
const YUME_TWE_SUKASUKA = YUME_CORE_DIR . 'includes/wordend/assets/univers/sukasuka/';

/**
 * Fichiers référencés par le manifeste que les lots B (personnages) et C (niveaux) de WordEnd v2
 * créent : tolérés absents jusqu'à leur intégration (liste à vider à l'intégration).
 */
const YUME_TWE_ATTENDUS_LOTS = array(
	'personnages/nephren.json',
	'personnages/ithea.json',
	'niveaux/01-plage.json',
	'niveaux/02-dunes.json',
	'niveaux/03-falaise.json',
	'niveaux/04-nuit.json',
	'niveaux/05-boss.json',
);

/**
 * Lit un JSON de l'univers SukaSuka.
 *
 * @param string $relatif Chemin relatif au dossier de l'univers.
 */
function yume_twe_json( string $relatif ): array {
	$donnees = json_decode( (string) file_get_contents( YUME_TWE_SUKASUKA . $relatif ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	yume_assert_true( is_array( $donnees ), "$relatif : JSON valide" );
	return is_array( $donnees ) ? $donnees : array();
}

/**
 * Vérifie une planche (PNG + .planche.json) : nombre d'images par animation, dimensions, cadres.
 *
 * @param string $base    Chemin relatif sans extension (personnages/chtholly, ennemis/timere).
 * @param array  $attendu animation => nombre d'images.
 */
function yume_twe_verifier_planche( string $base, array $attendu ): array {
	$meta = yume_twe_json( $base . '.planche.json' );
	foreach ( $attendu as $animation => $nombre ) {
		yume_assert_same( $nombre, count( $meta['animations'][ $animation ]['images'] ?? array() ), "$base : images de « $animation »" );
	}
	$taille = getimagesize( YUME_TWE_SUKASUKA . $base . '.png' );
	yume_assert_same( $meta['planche'], array( $taille[0], $taille[1] ), "$base : dimensions de la planche" );
	foreach ( $meta['animations'] as $animation => $donnees ) {
		foreach ( $donnees['images'] as $cadre ) {
			yume_assert_true( $cadre[0] + $cadre[2] <= $taille[0] && $cadre[1] + $cadre[3] <= $taille[1], "$base : cadre de « $animation » dans la planche" );
			yume_assert_true( $cadre[4] >= 0 && $cadre[4] <= $cadre[2] && $cadre[5] >= 0 && $cadre[5] <= $cadre[3], "$base : ancre de « $animation » dans son cadre" );
		}
		foreach ( $donnees['coup'] ?? array() as $indice ) {
			yume_assert_true( $indice < count( $donnees['images'] ), "$base : image de coup de « $animation » existante" );
		}
	}
	return $meta;
}

yume_test(
	'wordend : fichiers livrés (moteur, univers), planches de Chtholly et du Timere cohérentes',
	function () {
		foreach ( fichiers_requis() as $chemin ) {
			$relatif = str_replace( YUME_TWE_SUKASUKA, '', $chemin );
			if ( in_array( $relatif, YUME_TWE_ATTENDUS_LOTS, true ) && ! file_exists( $chemin ) ) {
				continue; // Créé par un lot de WordEnd v2 pas encore intégré.
			}
			yume_assert_true( is_readable( $chemin ), $relatif . ' présent' );
		}
		yume_twe_verifier_planche(
			'personnages/chtholly',
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
		$timere = yume_twe_verifier_planche(
			'ennemis/timere',
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
		foreach ( array( 'fouet', 'morsure' ) as $attaque ) {
			yume_assert_true( ! empty( $timere['animations'][ $attaque ]['coup'] ), "timere : images de coup de « $attaque »" );
		}
	}
);

yume_test(
	'wordend : univers SukaSuka (manifeste v2, personnage, ennemi et niveau arcade cohérents)',
	function () {
		$manifeste = yume_twe_json( 'manifeste.json' );
		yume_assert_same( 2, $manifeste['version'] ?? 0, 'manifeste en version 2' );
		yume_assert_same( 'sukasuka', $manifeste['slug'] ?? '', 'slug du manifeste' );
		yume_assert_same( 'personnages/chtholly.json', $manifeste['personnages'][0] ?? '', 'Chtholly, personnage par défaut' );
		yume_assert_true( in_array( 'niveaux/arcade.json', $manifeste['niveaux'] ?? array(), true ), 'niveau arcade listé' );

		$chtholly = yume_twe_json( 'personnages/chtholly.json' );
		$planche  = yume_twe_json( 'personnages/' . $chtholly['planche'] . '.planche.json' );
		yume_assert_same( 2, $chtholly['version'] ?? 0, 'personnage en version 2' );
		foreach ( $chtholly['poses'] ?? array() as $pose => $cible ) {
			yume_assert_true( isset( $planche['animations'][ $cible[0] ]['images'][ $cible[1] ] ), "chtholly : pose « $pose » existante" );
		}
		foreach ( $chtholly['competences'] ?? array() as $emplacement => $competence ) {
			yume_assert_true( in_array( $competence['type'] ?? '', array( 'melee', 'onde', 'projectile', 'ruee', 'parade' ), true ), "chtholly : type de la compétence « $emplacement »" );
			if ( ! empty( $competence['animation'] ) ) {
				yume_assert_true( isset( $planche['animations'][ $competence['animation'] ] ), "chtholly : animation de la compétence « $emplacement »" );
			}
		}

		$timere = yume_twe_json( 'ennemis/timere.json' );
		$meta   = yume_twe_json( 'ennemis/' . $timere['planche'] . '.planche.json' );
		yume_assert_same( array( 'petit', 'normal', 'coureur', 'grand' ), array_slice( array_keys( $timere['types'] ?? array() ), 0, 4 ), 'types v1 du Timere' );
		foreach ( $timere['types'] as $nom => $type ) {
			yume_assert_true( in_array( $type['comportement'] ?? '', array( 'marcheur', 'coureur', 'volant', 'tireur', 'bouclier', 'boss' ), true ), "timere : comportement du type « $nom »" );
			foreach ( $type['attaques'] ?? array() as $attaque ) {
				yume_assert_true( isset( $timere['attaques'][ $attaque ], $meta['animations'][ $attaque ] ), "timere : attaque « $attaque » du type « $nom »" );
			}
		}

		$arcade = yume_twe_json( 'niveaux/arcade.json' );
		yume_assert_same( 'arcade', $arcade['objectif']['type'] ?? '', 'objectif du niveau arcade' );
		yume_assert_true( isset( $manifeste['decors'][ $arcade['decor'] ] ), 'décor du niveau arcade déclaré' );
		yume_assert_true( isset( $manifeste['musiques'][ $arcade['musique'] ] ), 'musique du niveau arcade déclarée' );
		yume_assert_same( 480, $arcade['largeur'] ?? 0, 'arcade : largeur d’un écran' );
	}
);

yume_test(
	'wordend : configuration (scripts dans l’ordre, URLs versionnées, univers) et filtre des œuvres',
	function () {
		$config = configuration();
		$base   = YUME_CORE_URL . 'includes/wordend/assets/';
		yume_assert_contains( $base . 'jeu.css', $config['style'], 'feuille du jeu' );
		yume_assert_contains( 'ver=', $config['style'], 'version de la feuille' );
		yume_assert_same( count( SCRIPTS_MOTEUR ), count( $config['scripts'] ), 'un script par fichier du moteur' );
		yume_assert_same( 'moteur/00-espace.js', SCRIPTS_MOTEUR[0], 'espace de noms en premier' );
		yume_assert_same( 'moteur/jeu.js', SCRIPTS_MOTEUR[ count( SCRIPTS_MOTEUR ) - 1 ], 'point d’entrée en dernier' );
		foreach ( SCRIPTS_MOTEUR as $i => $script ) {
			yume_assert_contains( $base . $script . '?ver=', $config['scripts'][ $i ], "script « $script » versionné, à son rang" );
		}
		yume_assert_same( array( 'sukasuka' ), array_keys( $config['univers'] ), 'univers intégrés' );
		yume_assert_same( $config['univers'], univers() );
		yume_assert_contains( $base . 'univers/sukasuka/manifeste.json?ver=', $config['univers']['sukasuka']['manifeste'], 'manifeste versionné' );
		yume_assert_true( is_readable( chemin_manifeste( 'sukasuka' ) ), 'manifeste lisible' );
		yume_assert_same( 'sukasuka', $config['univers']['sukasuka']['oeuvre'] );
		yume_assert_same( 'sukasuka', $config['universParDefaut'] );
		yume_assert_same( '', $config['universPage'], 'aucun univers de page (lot 0)' );
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
			yume_assert_contains( 'moteur/00-espace.js', $avant );
			yume_assert_contains( 'univers/sukasuka/manifeste.json', $avant );
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
