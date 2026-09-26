<?php
/**
 * Page « Illustrations » d'un tome : page de lecture virtuelle placée avant le premier chapitre,
 * comme les planches couleur au début d'un light novel imprimé.
 *
 *   /lire/{oeuvre}/{slug-tome}/illustrations/
 *
 * Aucun contenu n'est créé : l'adresse est résolue par routing.php (resoudre_requete()) vers le
 * tome lui-même (p + post_type yume_tome) avec la variable de requête privée QV_ILLUSTRATIONS.
 * Le thème choisit alors son modèle « yume-illustrations » (filtre single_template_hierarchy)
 * et les blocs du lecteur s'adaptent (yume_est_page_illustrations()).
 *
 * La page existe quand le tome a une galerie (métadonnée yume_illustrations : images placées
 * avant le premier chapitre du DOCX / EPUB) et qu'aucun de ses chapitres n'occupe déjà le
 * segment « illustrations » (chapitre spécial de nature « illustrations ») : le vrai chapitre
 * l'emporte toujours. Mêmes règles de visibilité que le tome (hiérarchie comprise).
 *
 * Référencement : URL canonique propre, mais « noindex, follow » et absente du plan du site —
 * les images sont déjà indexables sur la page du tome (galerie), la page n'a pas de texte.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Segment d'URL de la page Illustrations, à la place d'un numéro de chapitre. */
const SEGMENT_ILLUSTRATIONS = 'illustrations';

/** Variable de requête privée : la requête principale affiche la page Illustrations du tome. */
const QV_ILLUSTRATIONS = 'yume_page_illustrations';

/**
 * Images de la galerie d'un tome, dans l'ordre de lecture (pièces jointes images existantes,
 * sans doublon).
 *
 * @param int $tome_id Tome.
 * @return int[]
 */
function images_galerie( int $tome_id ): array {
	if ( $tome_id <= 0 || CPT_TOME !== get_post_type( $tome_id ) ) {
		return array();
	}
	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) get_post_meta( $tome_id, 'yume_illustrations', true ) ) ) ) );
	if ( ! $ids ) {
		return array();
	}
	_prime_post_caches( $ids, false, true );
	return array_values( array_filter( $ids, 'wp_attachment_is_image' ) );
}

/**
 * Le tome a-t-il une page Illustrations (galerie non vide, segment libre) ?
 *
 * @param int $tome_id Tome.
 */
function a_page_illustrations( int $tome_id ): bool {
	if ( ! images_galerie( $tome_id ) ) {
		return false;
	}
	// Un chapitre du tome (quel que soit son statut actif) adressé « illustrations » l'emporte.
	return ! in_array( SEGMENT_ILLUSTRATIONS, segments_tome( $tome_id ), true );
}

/**
 * Adresse de la page Illustrations d'un tome, ou chaîne vide (pas de page, liens simples,
 * tome sans adresse jolie).
 *
 * @param int $tome_id Tome.
 */
function url_illustrations( int $tome_id ): string {
	$tome = get_post( $tome_id );
	if ( ! $tome instanceof \WP_Post || CPT_TOME !== $tome->post_type || ! a_page_illustrations( $tome_id ) ) {
		return '';
	}
	$lien = permalien_tome( $tome );
	if ( '' === $lien ) {
		return '';
	}
	$oeuvre = slug_oeuvre( (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ) );
	if ( '' === $oeuvre ) {
		return '';
	}
	return url_depuis_chemin( 'lire/' . $oeuvre . '/' . $tome->post_name . '/' . SEGMENT_ILLUSTRATIONS );
}

/**
 * La requête principale affiche-t-elle une page Illustrations ?
 */
function est_page_illustrations(): bool {
	return is_singular( CPT_TOME ) && (bool) get_query_var( QV_ILLUSTRATIONS );
}

/**
 * Page Illustrations qui précède un chapitre dans l'ordre de lecture : celle de son tome quand
 * le chapitre est le premier publié du tome (aucun chapitre publié avant lui dans ce tome).
 *
 * @param int $chapitre_id Chapitre.
 */
function url_illustrations_avant( int $chapitre_id ): string {
	if ( CPT_CHAPITRE !== get_post_type( $chapitre_id ) ) {
		return '';
	}
	$tome_id = yume_get_tome_id( $chapitre_id );
	if ( ! $tome_id ) {
		return '';
	}
	$precedent = yume_chapitre_voisin( $chapitre_id, 'prev' );
	if ( $precedent instanceof \WP_Post && yume_get_tome_id( (int) $precedent->ID ) === $tome_id ) {
		return '';
	}
	return url_illustrations( $tome_id );
}

/*
 * -----------------------------------------------------------------------------
 * Balises de la page : titre, adresse canonique, robots
 * -----------------------------------------------------------------------------
 */

/**
 * Titre du document : « Illustrations · Œuvre — Tome 9 · Yume Novel ».
 *
 * @param array<string,string> $parties Parties du titre.
 * @return array<string,string>
 */
function titre_document_illustrations( $parties ) {
	if ( ! is_array( $parties ) || ! est_page_illustrations() ) {
		return $parties;
	}
	$tome             = wp_strip_all_tags( (string) get_the_title( (int) get_queried_object_id() ) );
	$parties['title'] = trim( __( 'Illustrations', 'yume-core' ) . ( '' !== $tome ? ' · ' . $tome : '' ) );
	return $parties;
}
add_filter( 'document_title_parts', __NAMESPACE__ . '\\titre_document_illustrations' );

/**
 * Adresse canonique : celle de la page Illustrations, pas celle du tome.
 *
 * @param string   $url  Adresse canonique calculée.
 * @param \WP_Post $post Contenu.
 * @return string
 */
function canonique_illustrations( $url, $post = null ) {
	if ( ! $post instanceof \WP_Post || ! est_page_illustrations() || (int) get_queried_object_id() !== (int) $post->ID ) {
		return $url;
	}
	$illustrations = url_illustrations( (int) $post->ID );
	return '' !== $illustrations ? $illustrations : $url;
}
add_filter( 'get_canonical_url', __NAMESPACE__ . '\\canonique_illustrations', 10, 2 );

/**
 * Robots : page sans texte, images déjà présentes sur la page du tome → noindex, follow.
 *
 * @param array<string,bool|string> $robots Directives.
 * @return array<string,bool|string>
 */
function robots_illustrations( $robots ) {
	if ( ! is_array( $robots ) || ! est_page_illustrations() ) {
		return $robots;
	}
	$robots['noindex'] = true;
	$robots['follow']  = true;
	unset( $robots['index'], $robots['nofollow'] );
	return $robots;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\robots_illustrations' );

/**
 * Pas de lien court (?p=ID du tome) sur la page Illustrations (filtre pre_get_shortlink :
 * false = calcul normal, chaîne vide = aucun lien).
 *
 * @param false|string $court Valeur de court-circuit.
 * @return false|string
 */
function lien_court_illustrations_pre( $court ) {
	return est_page_illustrations() ? '' : $court;
}
add_filter( 'pre_get_shortlink', __NAMESPACE__ . '\\lien_court_illustrations_pre' );
