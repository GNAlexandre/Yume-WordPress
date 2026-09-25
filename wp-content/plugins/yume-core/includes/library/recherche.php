<?php
/**
 * Recherche du site : la fiche d'une œuvre passe avant ses tomes et ses annonces.
 *
 * Par défaut, WordPress classe les résultats d'une recherche par pertinence du titre, puis
 * par date : chercher « grimgar » listait d'abord les annonces et les tomes les plus récents,
 * et la fiche de l'œuvre (page d'entrée voulue, contrat §10) n'arrivait qu'en page 2.
 * Pour la recherche principale du site public, l'ordre devient :
 * 1. les œuvres dont le titre (ou un titre alternatif) contient la recherche ;
 * 2. puis l'ordre de pertinence de WordPress (titre contenant la phrase, tous les mots…) ;
 * 3. à pertinence égale : œuvres, puis tomes, puis le reste ; enfin la date.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * La requête est-elle la recherche principale du site public ?
 *
 * @param \WP_Query $requete Requête.
 */
function est_recherche_publique( $requete ): bool {
	return $requete instanceof \WP_Query
		&& $requete->is_main_query()
		&& $requete->is_search()
		&& ! $requete->is_feed()
		&& ! is_admin()
		&& '' !== trim( (string) $requete->get( 's' ) )
		&& in_array( $requete->get( 'orderby' ), array( '', 'relevance' ), true );
}

/**
 * Ordre des résultats de la recherche principale : œuvres correspondantes d'abord.
 *
 * @param string    $ordre   Clause ORDER BY de pertinence calculée par WordPress (peut être vide).
 * @param \WP_Query $requete Requête.
 * @return string
 */
function ordre_recherche( $ordre, $requete ): string {
	$ordre = (string) $ordre;
	if ( ! est_recherche_publique( $requete ) ) {
		return $ordre;
	}
	global $wpdb;
	$phrase = trim( (string) $requete->get( 's' ) );
	$phrase = trim( $phrase, "\"' \t\n\r" );
	if ( '' === $phrase ) {
		return $ordre;
	}
	$like = '%' . $wpdb->esc_like( $phrase ) . '%';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms de tables uniquement.
	$oeuvre = $wpdb->prepare(
		"CASE WHEN {$wpdb->posts}.post_type = %s AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'yume_titres_alt' AND meta_value LIKE %s ) ) THEN 0 ELSE 1 END ASC",
		TYPE_OEUVRE,
		$like,
		$like
	);
	$type   = $wpdb->prepare(
		"CASE {$wpdb->posts}.post_type WHEN %s THEN 0 WHEN %s THEN 1 ELSE 2 END ASC",
		TYPE_OEUVRE,
		TYPE_TOME
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return implode( ', ', array_filter( array( $oeuvre, $ordre, $type ) ) );
}
add_filter( 'posts_search_orderby', __NAMESPACE__ . '\\ordre_recherche', 10, 2 );
