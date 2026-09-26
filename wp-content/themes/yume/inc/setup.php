<?php
/**
 * Réglages du thème : supports, tailles d'image, styles de blocs, compositions, emojis.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Déclare les fonctionnalités prises en charge par le thème.
 */
function yume_theme_setup(): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support(
		'html5',
		array( 'comment-form', 'comment-list', 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 88,
			'width'       => 88,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	// Les compositions du thème remplacent celles de WordPress, trop génériques pour Yume.
	remove_theme_support( 'core-block-patterns' );

	// Mêmes feuilles de style dans l'éditeur qu'en façade (classes utilitaires et lecteur).
	add_editor_style( array( 'assets/css/yume.css', 'assets/css/reader.css' ) );

	// Couverture de tome ou d'œuvre (2:3) et vignette d'actualité (16:9).
	add_image_size( 'yume-couverture', 480, 720, true );
	add_image_size( 'yume-vignette', 800, 450, true );
}
add_action( 'after_setup_theme', 'yume_theme_setup' );

/**
 * Libellés français des tailles d'image proposées dans l'éditeur.
 *
 * @param array<string,string> $tailles Tailles connues (slug => libellé).
 * @return array<string,string>
 */
function yume_theme_noms_tailles_image( array $tailles ): array {
	$tailles['yume-couverture'] = __( 'Couverture (2:3)', 'yume' );
	$tailles['yume-vignette']   = __( 'Vignette d’actualité (16:9)', 'yume' );
	return $tailles;
}
add_filter( 'image_size_names_choose', 'yume_theme_noms_tailles_image' );

/**
 * Catégorie de compositions « Yume Novel » et styles de blocs du thème.
 */
function yume_theme_enregistrer_styles_et_compositions(): void {
	register_block_pattern_category(
		'yume',
		array(
			'label'       => __( 'Yume Novel', 'yume' ),
			'description' => __( 'Compositions aux couleurs de Yume Novel : cartes, bandeaux, en-têtes de page.', 'yume' ),
		)
	);

	$styles = array(
		'core/group'     => array(
			'yn-card'    => __( 'Carte Yume', 'yume' ),
			'yn-bandeau' => __( 'Bandeau dégradé', 'yume' ),
		),
		'core/button'    => array(
			'yn-secondaire' => __( 'Secondaire', 'yume' ),
			'yn-kofi'       => __( 'Ko-fi (pêche)', 'yume' ),
		),
		'core/paragraph' => array(
			'yn-label' => __( 'Surtitre', 'yume' ),
		),
		'core/image'     => array(
			'yn-couverture' => __( 'Couverture 2:3', 'yume' ),
		),
		'core/separator' => array(
			'yn-fleurs' => __( 'Séparateur de scène', 'yume' ),
		),
	);

	foreach ( $styles as $bloc => $variantes ) {
		foreach ( $variantes as $nom => $libelle ) {
			register_block_style(
				$bloc,
				array(
					'name'  => $nom,
					'label' => $libelle,
				)
			);
		}
	}
}
add_action( 'init', 'yume_theme_enregistrer_styles_et_compositions' );

// Pas de compositions distantes du répertoire WordPress.org : l'inserteur reste centré sur Yume.
add_filter( 'should_load_remote_block_patterns', '__return_false' );

/**
 * Désactive le script et les styles de conversion des emojis (inutiles : les navigateurs
 * affichent les emojis nativement) ainsi que la préconnexion à s.w.org.
 */
function yume_theme_desactiver_emojis(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'embed_head', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
	add_filter(
		'tiny_mce_plugins',
		static function ( $extensions ) {
			return is_array( $extensions ) ? array_values( array_diff( $extensions, array( 'wpemoji' ) ) ) : array();
		}
	);
}
add_action( 'init', 'yume_theme_desactiver_emojis' );

/**
 * Retire la préconnexion vers le CDN des emojis.
 *
 * @param array  $urls          URL des indications de ressources.
 * @param string $relation_type Type de relation (dns-prefetch, preconnect…).
 * @return array
 */
function yume_theme_indications_ressources( $urls, $relation_type ) {
	if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
		return $urls;
	}
	return array_values(
		array_filter(
			$urls,
			static function ( $url ) {
				$href = is_array( $url ) ? ( $url['href'] ?? '' ) : (string) $url;
				return ! str_contains( $href, 's.w.org/images/core/emoji' );
			}
		)
	);
}
add_filter( 'wp_resource_hints', 'yume_theme_indications_ressources', 10, 2 );

/**
 * Séparateur du titre de document : « Page · Yume Novel ».
 *
 * @return string
 */
function yume_theme_separateur_titre(): string {
	return '·';
}
add_filter( 'document_title_separator', 'yume_theme_separateur_titre' );

/**
 * Points de suspension typographiques à la fin des extraits.
 *
 * @return string
 */
function yume_theme_suite_extrait(): string {
	return '…';
}
add_filter( 'excerpt_more', 'yume_theme_suite_extrait' );
