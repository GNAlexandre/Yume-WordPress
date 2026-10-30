<?php
/**
 * Thèmes saisonniers (Printemps, Été, Automne, Hiver, Halloween, Noël) : variante du thème
 * « Crépuscule sakura » choisie par l'extension Yume Core (docs/07-themes-saisonniers.md).
 *
 * - attribut html[data-yn-saison="<slug>"] (absent sans saison) et classe body.yn-saison-<slug> ;
 *   les palettes Nuit et Papier de chaque saison sont dans assets/css/yume.css (section 1 bis) ;
 * - logo saisonnier : fichier du thème assets/img/saisons/<slug>.webp à la place du logo du
 *   personnalisateur (filtre get_custom_logo, utilisé par le bloc core/site-logo de l'en-tête) ;
 * - couleur de l'interface du navigateur (meta theme-color) : bande Nuit de la saison ;
 * - décors légers de l'en-tête (assets/js/saisons.js), si le réglage saison_decor est actif.
 *
 * Le thème reste autonome : la saison vient de yume_saison_active(), appelée derrière un
 * function_exists(). Sans l'extension, il n'y a aucune saison et le rendu est celui du thème de base.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saisons connues du thème, avec la couleur de la bande de leurs thèmes Nuit et Papier
 * (meta theme-color). À garder synchronisé avec assets/css/yume.css (section 1 bis) et
 * COULEURS_SAISONS dans assets/js/yume.js. Printemps garde la palette de base.
 *
 * @return array<string,array{nuit:string,papier:string}> Slug => couleurs de bande.
 */
function yume_theme_saisons(): array {
	return array(
		'printemps' => array(
			'nuit'   => '#241740',
			'papier' => '#f6eef3',
		),
		'ete'       => array(
			'nuit'   => '#142a44',
			'papier' => '#e6f2fb',
		),
		'automne'   => array(
			'nuit'   => '#2d1a14',
			'papier' => '#f8ebdd',
		),
		'hiver'     => array(
			'nuit'   => '#1b2537',
			'papier' => '#e9f0f7',
		),
		'halloween' => array(
			'nuit'   => '#211537',
			'papier' => '#f3e8fb',
		),
		'noel'      => array(
			'nuit'   => '#162b22',
			'papier' => '#e7f3ea',
		),
	);
}

/**
 * Saison active pour la requête courante, telle que le thème l'applique : slug connu du thème,
 * ou '' (thème de base) sans l'extension, dans l'administration ou pour un slug inconnu.
 *
 * @return string
 */
function yume_theme_saison(): string {
	if ( is_admin() || ! function_exists( 'yume_saison_active' ) ) {
		return '';
	}
	$saison = yume_saison_active();
	if ( ! is_string( $saison ) || ! array_key_exists( $saison, yume_theme_saisons() ) ) {
		return '';
	}
	return $saison;
}

/**
 * Attribut data-yn-saison sur la balise <html>, après le thème de couleurs
 * (yume_theme_attributs_html, priorité 10).
 *
 * @param string $attributs Attributs de langue déjà calculés.
 * @return string
 */
function yume_theme_attribut_saison( $attributs ) {
	if ( ! is_string( $attributs ) || str_contains( $attributs, 'data-yn-saison' ) ) {
		return $attributs;
	}
	$saison = yume_theme_saison();
	if ( '' === $saison ) {
		return $attributs;
	}
	return $attributs . ' data-yn-saison="' . esc_attr( $saison ) . '"';
}
add_filter( 'language_attributes', 'yume_theme_attribut_saison', 20 );

/**
 * Classe yn-saison-<slug> sur la balise <body>.
 *
 * @param string[] $classes Classes du body.
 * @return string[]
 */
function yume_theme_classe_saison( $classes ) {
	$saison = yume_theme_saison();
	if ( '' === $saison || ! is_array( $classes ) ) {
		return $classes;
	}
	$classes[] = 'yn-saison-' . $saison;
	return $classes;
}
add_filter( 'body_class', 'yume_theme_classe_saison' );

/**
 * Couleur de l'interface du navigateur : bande du thème Nuit de la saison.
 *
 * @param string $couleur Couleur du thème de base.
 * @return string
 */
function yume_theme_couleur_navigateur_saison( $couleur ) {
	$saison = yume_theme_saison();
	if ( '' === $saison ) {
		return $couleur;
	}
	return yume_theme_saisons()[ $saison ]['nuit'];
}
add_filter( 'yume_theme_couleur_navigateur', 'yume_theme_couleur_navigateur_saison' );

/**
 * Balise <img> du logo d'une saison : fichier du thème versionné (?v=), classe custom-logo
 * comme le logo de WordPress, texte de remplacement = nom du site. Les dimensions sont celles
 * du fichier (320 px de large, visuels non carrés) : l'en-tête affiche le logo sur 44 px de haut
 * (40 px sur mobile) par la règle .yn-header__logo img.yn-logo-saison de yume.css.
 *
 * @param string $saison Slug de la saison.
 * @return string Balise <img>, ou '' si le fichier manque.
 */
function yume_theme_image_logo_saison( string $saison ): string {
	$relatif = 'assets/img/saisons/' . $saison . '.webp';
	$chemin  = get_theme_file_path( $relatif );
	if ( ! is_readable( $chemin ) ) {
		return '';
	}
	$taille  = wp_getimagesize( $chemin );
	$largeur = is_array( $taille ) && ! empty( $taille[0] ) ? (int) $taille[0] : 320;
	$hauteur = is_array( $taille ) && ! empty( $taille[1] ) ? (int) $taille[1] : 275;
	$source  = add_query_arg( 'v', yume_theme_version_fichier( $relatif ), get_theme_file_uri( $relatif ) );
	return sprintf(
		'<img src="%1$s" class="custom-logo yn-logo-saison" alt="%2$s" width="%3$d" height="%4$d" decoding="async">',
		esc_url( $source ),
		esc_attr( get_bloginfo( 'name', 'display' ) ),
		$largeur,
		$hauteur
	);
}

/**
 * Logo saisonnier à la place du logo du personnalisateur (filtre get_custom_logo, appelé par
 * le bloc core/site-logo) : même balisage que WordPress, l'image seule change. Quand aucun logo
 * n'est défini dans le personnalisateur, le logo de la saison est affiché quand même, dans le
 * même lien vers l'accueil que celui de WordPress.
 *
 * @param string $html Balisage du logo calculé par WordPress ('' sans logo).
 * @return string
 */
function yume_theme_logo_saisonnier( $html ) {
	$saison = yume_theme_saison();
	if ( '' === $saison || ! is_string( $html ) ) {
		return $html;
	}
	$image = yume_theme_image_logo_saison( $saison );
	if ( '' === $image ) {
		return $html;
	}
	if ( str_contains( $html, '<img' ) ) {
		$remplace = preg_replace( '/<img\b[^>]*>/i', $image, $html, 1 );
		return is_string( $remplace ) ? $remplace : $html;
	}
	// Aucun logo dans le personnalisateur : même lien que get_custom_logo().
	$page_courante = ! is_paged() && ( is_front_page() || ( is_home() && (int) get_option( 'page_for_posts' ) !== get_queried_object_id() ) );
	return sprintf(
		'<a href="%1$s" class="custom-logo-link" rel="home"%2$s>%3$s</a>',
		esc_url( home_url( '/' ) ),
		$page_courante ? ' aria-current="page"' : '',
		$image
	);
}
add_filter( 'get_custom_logo', 'yume_theme_logo_saisonnier' );

/**
 * Décors légers de l'en-tête (pétales, feuilles, flocons, chauves-souris) : script chargé
 * seulement si une saison qui en a est active et que le réglage saison_decor de l'extension
 * est actif (défaut : oui). Le script lui-même s'abstient si le lecteur a demandé moins
 * d'animations (préférence système ou option yn.a11y du lecteur).
 */
function yume_theme_charger_decors(): void {
	$saison = yume_theme_saison();
	if ( '' === $saison || 'ete' === $saison ) {
		return;
	}
	$decor = function_exists( 'yume_setting' ) ? yume_setting( 'saison_decor', true ) : true;
	if ( ! $decor ) {
		return;
	}
	wp_enqueue_script(
		'yume-saisons',
		get_theme_file_uri( 'assets/js/saisons.js' ),
		array( 'yume' ),
		yume_theme_version_fichier( 'assets/js/saisons.js' ),
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'yume_theme_charger_decors', 21 );
