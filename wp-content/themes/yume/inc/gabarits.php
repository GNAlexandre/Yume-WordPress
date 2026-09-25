<?php
/**
 * Choix des modèles et classes du document.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identifiants des pages fonctionnelles créées par la migration (option `yume_pages`,
 * contrat §11) qui s'affichent en pleine largeur : bibliothèque, planning, équipe,
 * publication, compte, connexion.
 *
 * @return int[]
 */
function yume_theme_pages_larges(): array {
	$pages = get_option( 'yume_pages', array() );
	if ( ! is_array( $pages ) ) {
		return array();
	}
	$cles = array( 'bibliotheque', 'planning', 'equipe', 'publier', 'compte', 'connexion' );
	$ids  = array();
	foreach ( $cles as $cle ) {
		if ( ! empty( $pages[ $cle ] ) ) {
			$ids[] = (int) $pages[ $cle ];
		}
	}
	return array_values( array_filter( $ids ) );
}

/**
 * Les pages fonctionnelles utilisent le modèle « page-large » sans qu'il soit nécessaire
 * de le choisir à la main. Un modèle choisi explicitement dans l'éditeur reste prioritaire.
 *
 * @param string[] $modeles Hiérarchie des modèles de page (page-{slug}.php, page-{id}.php, page.php).
 * @return string[]
 */
function yume_theme_hierarchie_page( $modeles ) {
	if ( ! is_array( $modeles ) ) {
		return $modeles;
	}
	$page = get_queried_object();
	if ( ! $page instanceof WP_Post ) {
		return $modeles;
	}
	$slugs = array( 'bibliotheque', 'planning', 'equipe', 'publier', 'compte', 'connexion' );
	if ( ! in_array( (int) $page->ID, yume_theme_pages_larges(), true ) && ! in_array( $page->post_name, $slugs, true ) ) {
		return $modeles;
	}
	$position = array_search( 'page.php', $modeles, true );
	if ( false === $position ) {
		$modeles[] = 'page-large.php';
		return $modeles;
	}
	array_splice( $modeles, (int) $position, 0, array( 'page-large.php' ) );
	return $modeles;
}
add_filter( 'page_template_hierarchy', 'yume_theme_hierarchie_page' );

/**
 * Classes du <body> : thème Yume et lecteur.
 *
 * @param string[] $classes Classes existantes.
 * @return string[]
 */
function yume_theme_classes_document( $classes ) {
	$classes   = is_array( $classes ) ? $classes : array();
	$classes[] = 'yume';
	if ( is_singular( 'yume_chapitre' ) ) {
		$classes[] = 'yume-lecture';
	}
	return $classes;
}
add_filter( 'body_class', 'yume_theme_classes_document' );
