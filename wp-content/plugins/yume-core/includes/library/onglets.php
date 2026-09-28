<?php
/**
 * Onglets de la fiche d'une œuvre (PAGE-01) : « Présentation » (la fiche) puis les sous-pages
 * /oeuvres/{oeuvre}/{onglet}/ qui ont du contenu pour cette œuvre (Actualités, Glossaire…).
 *
 * - onglets_oeuvre( $oeuvre_id ) : slug => { libelle, url }, filtre yume_onglets_oeuvre ; la clé
 *   « fiche » est toujours présente et en premier ;
 * - bloc yume/oeuvre-onglets : navigation entre ces onglets (rien s'il n'y en a qu'un) ;
 * - référencement commun à toutes les sous-pages : titre « {Onglet} — {Œuvre} », adresse
 *   canonique (et og:url) = celle de la sous-page (?pg=N compris), <link rel="prev|next"> quand
 *   le module de l'onglet déclare plusieurs pages (filtre yume_pages_onglet_oeuvre).
 *
 * Le module qui déclare une sous-page (filtre yume_sous_pages_oeuvre, includes/core/routing.php)
 * ajoute son onglet ici seulement quand l'œuvre a du contenu, et renvoie lui-même une 404 sinon.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/** Paramètre de pagination des sous-pages (comme la grille de la bibliothèque). */
const PARAM_PAGE_ONGLET = 'pg';

/**
 * Onglets d'une œuvre, dans l'ordre d'affichage.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<string,array{libelle:string,url:string}> Vide si l'œuvre n'a pas d'adresse.
 */
function onglets_oeuvre( int $oeuvre_id ): array {
	$lien = TYPE_OEUVRE === get_post_type( $oeuvre_id ) ? get_permalink( $oeuvre_id ) : false;
	if ( ! is_string( $lien ) || '' === $lien ) {
		return array();
	}
	$fiche = array(
		'libelle' => __( 'Présentation', 'yume-core' ),
		'url'     => $lien,
	);
	/**
	 * Filtre les onglets de la fiche d'une œuvre. Un module n'ajoute son onglet que si
	 * l'œuvre a du contenu pour lui (sinon sa sous-page est une 404).
	 *
	 * @param array<string,array{libelle:string,url:string}> $onglets   Slug => libellé et adresse ; « fiche » (Présentation) d'abord.
	 * @param int                                            $oeuvre_id Œuvre.
	 */
	$filtres = apply_filters( 'yume_onglets_oeuvre', array( 'fiche' => $fiche ), $oeuvre_id );
	$onglets = array( 'fiche' => $fiche );
	foreach ( is_array( $filtres ) ? $filtres : array() as $slug => $onglet ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || 'fiche' === $slug || ! is_array( $onglet ) ) {
			continue;
		}
		$libelle = trim( wp_strip_all_tags( (string) ( $onglet['libelle'] ?? '' ) ) );
		$url     = (string) ( $onglet['url'] ?? '' );
		if ( '' !== $libelle && '' !== $url ) {
			$onglets[ $slug ] = array(
				'libelle' => $libelle,
				'url'     => $url,
			);
		}
	}
	return $onglets;
}

/**
 * Sous-page de l'œuvre affichée par la requête principale ('' sur la fiche ou ailleurs).
 */
function onglet_courant(): string {
	return function_exists( '\\Yume\\Core\\Core\\onglet_oeuvre' ) ? \Yume\Core\Core\onglet_oeuvre() : '';
}

/**
 * Adresse d'une sous-page d'œuvre ('' si inconnue).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Slug.
 * @param int    $page      Page (?pg=N au-delà de la première).
 */
function url_onglet( int $oeuvre_id, string $onglet, int $page = 1 ): string {
	$url = function_exists( '\\Yume\\Core\\Core\\url_onglet_oeuvre' ) ? \Yume\Core\Core\url_onglet_oeuvre( $oeuvre_id, $onglet ) : '';
	return '' !== $url && $page > 1 ? add_query_arg( PARAM_PAGE_ONGLET, $page, $url ) : $url;
}

/**
 * Page demandée d'une sous-page (paramètre pg, 1 par défaut).
 */
function page_onglet(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination publique, lecture seule.
	$page = isset( $_GET[ PARAM_PAGE_ONGLET ] ) && is_string( $_GET[ PARAM_PAGE_ONGLET ] ) ? absint( wp_unslash( $_GET[ PARAM_PAGE_ONGLET ] ) ) : 1;
	return max( 1, $page );
}

/**
 * Nombre de pages de la sous-page affichée (1 par défaut ; le module de l'onglet le déclare).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Slug.
 */
function pages_onglet( int $oeuvre_id, string $onglet ): int {
	/**
	 * Filtre le nombre de pages d'une sous-page d'œuvre (liens rel=prev/next, canonique).
	 *
	 * @param int    $pages     Pages (1).
	 * @param int    $oeuvre_id Œuvre.
	 * @param string $onglet    Slug de la sous-page.
	 */
	return max( 1, (int) apply_filters( 'yume_pages_onglet_oeuvre', 1, $oeuvre_id, $onglet ) );
}

/**
 * Libellé d'une sous-page (onglet déclaré, sinon slug mis en forme).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Slug.
 */
function libelle_onglet( int $oeuvre_id, string $onglet ): string {
	$onglets = onglets_oeuvre( $oeuvre_id );
	return isset( $onglets[ $onglet ] ) ? $onglets[ $onglet ]['libelle'] : ucfirst( str_replace( '-', ' ', $onglet ) );
}

/**
 * Titre d'une sous-page : « Actualités — Œuvre » (« · page 2 » au-delà de la première).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Slug.
 */
function titre_onglet( int $oeuvre_id, string $onglet ): string {
	return libelle_onglet( $oeuvre_id, $onglet ) . ' — ' . titre( $oeuvre_id );
}

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/oeuvre-onglets
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu de yume/oeuvre-onglets : liens vers les onglets de l'œuvre, aria-current sur celui
 * qui est affiché. Rien si l'œuvre n'a que sa fiche.
 *
 * @param array          $attributs Attributs (aucun).
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_oeuvre_onglets( array $attributs = array(), $bloc = null ): string {
	unset( $attributs );
	$oeuvre_id = id_contexte( $bloc, array( TYPE_OEUVRE ) );
	if ( ! $oeuvre_id || ! est_visible( $oeuvre_id ) ) {
		return rendu_sans_contexte( 'yn-onglets-oeuvre', __( 'Onglets de l’œuvre : visibles sur la fiche d’une œuvre qui a des actualités ou un glossaire.', 'yume-core' ) );
	}
	$onglets = onglets_oeuvre( $oeuvre_id );
	if ( count( $onglets ) < 2 ) {
		return '';
	}
	$actif = '';
	if ( (int) get_queried_object_id() === $oeuvre_id ) {
		$actif = onglet_courant();
		$actif = '' === $actif ? 'fiche' : $actif;
	}
	$items = '';
	foreach ( $onglets as $slug => $onglet ) {
		$courant = $slug === $actif;
		$items  .= '<li class="yn-onglets-oeuvre__item"><a class="yn-onglets-oeuvre__lien" href="' . esc_url( $onglet['url'] ) . '"'
			. ( $courant ? ' aria-current="page"' : '' ) . '>' . esc_html( $onglet['libelle'] ) . '</a></li>';
	}
	return '<nav ' . attributs_racine( 'yn-onglets-oeuvre', array( 'aria-label' => __( 'Sections de l’œuvre', 'yume-core' ) ) ) . '>'
		. '<ul class="yn-onglets-oeuvre__liste">' . $items . '</ul></nav>';
}

/*
 * -----------------------------------------------------------------------------
 * Référencement des sous-pages
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre publiée dont la requête principale affiche une sous-page, ou 0.
 */
function oeuvre_sous_page(): int {
	if ( '' === onglet_courant() ) {
		return 0;
	}
	$id = (int) get_queried_object_id();
	return $id && 'publish' === get_post_status( $id ) ? $id : 0;
}

/**
 * Titre du document : « Actualités — Œuvre · Page 2 · Yume Novel ».
 *
 * @param array<string,string> $parties Parties du titre.
 * @return array<string,string>
 */
function titre_document_onglet( $parties ) {
	$oeuvre_id = oeuvre_sous_page();
	if ( ! is_array( $parties ) || ! $oeuvre_id ) {
		return $parties;
	}
	$parties['title'] = titre_onglet( $oeuvre_id, onglet_courant() );
	$page             = page_onglet();
	if ( $page > 1 ) {
		/* translators: %s : numéro de page. */
		$parties['page'] = sprintf( __( 'Page %s', 'yume-core' ), number_format_i18n( $page ) );
	}
	return $parties;
}
add_filter( 'document_title_parts', __NAMESPACE__ . '\\titre_document_onglet' );

/**
 * Adresse canonique d'une sous-page : la sienne (et sa page), pas celle de la fiche. Sert aussi
 * à og:url (url_partage() passe par wp_get_canonical_url()).
 *
 * @param string        $url  Adresse canonique calculée.
 * @param \WP_Post|null $post Contenu.
 * @return string
 */
function canonique_onglet( $url, $post = null ) {
	$oeuvre_id = oeuvre_sous_page();
	if ( ! $oeuvre_id || ! $post instanceof \WP_Post || (int) $post->ID !== $oeuvre_id ) {
		return $url;
	}
	$onglet = onglet_courant();
	$page   = min( page_onglet(), pages_onglet( $oeuvre_id, $onglet ) );
	$propre = url_onglet( $oeuvre_id, $onglet, $page );
	return '' !== $propre ? $propre : $url;
}
add_filter( 'get_canonical_url', __NAMESPACE__ . '\\canonique_onglet', 10, 2 );

/**
 * Balises <link rel="prev|next"> d'une sous-page paginée.
 */
function balises_pages_onglet(): string {
	$oeuvre_id = oeuvre_sous_page();
	if ( ! $oeuvre_id ) {
		return '';
	}
	$onglet = onglet_courant();
	$pages  = pages_onglet( $oeuvre_id, $onglet );
	$page   = min( page_onglet(), $pages );
	$html   = '';
	if ( $page > 1 ) {
		$html .= '<link rel="prev" href="' . esc_url( url_onglet( $oeuvre_id, $onglet, $page - 1 ) ) . "\" />\n";
	}
	if ( $page < $pages ) {
		$html .= '<link rel="next" href="' . esc_url( url_onglet( $oeuvre_id, $onglet, $page + 1 ) ) . "\" />\n";
	}
	return $html;
}

/**
 * Affiche dans <head> les liens rel=prev/next d'une sous-page paginée.
 */
function afficher_pages_onglet(): void {
	echo balises_pages_onglet(); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé à la construction.
}
add_action( 'wp_head', __NAMESPACE__ . '\\afficher_pages_onglet', 9 );

/**
 * Aperçu de partage d'une sous-page : titre « Actualités — Œuvre », type website (og:url suit
 * la canonique).
 *
 * @param array<string,string> $balises Balises.
 * @param int                  $post_id Contenu affiché.
 * @return array<string,string>
 */
function open_graph_onglet( $balises, $post_id = 0 ) {
	$oeuvre_id = oeuvre_sous_page();
	if ( ! is_array( $balises ) || ! $balises || ! $oeuvre_id || (int) $post_id !== $oeuvre_id ) {
		return $balises;
	}
	$balises['og:title'] = titre_onglet( $oeuvre_id, onglet_courant() );
	$balises['og:type']  = 'website';
	return $balises;
}
add_filter( 'yume_open_graph', __NAMESPACE__ . '\\open_graph_onglet', 10, 2 );
