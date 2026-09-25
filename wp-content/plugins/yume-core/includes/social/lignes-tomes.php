<?php
/**
 * Progression personnelle sur les lignes de tome de la fiche œuvre (maquette Oeuvre), par le
 * filtre yume_bibliotheque_ligne_tome du module bibliothèque (§10) :
 * - tome en cours de lecture : ligne mise en avant, pastille « En cours · 41 % », détail
 *   « vous en êtes au chapitre 3 » et bouton « Reprendre » vers le paragraphe retenu (#yn-p-N) ;
 * - tomes précédents dans l'ordre de lecture : « lu ✓ » et bouton « Relire » ;
 * - tomes suivants : inchangés.
 *
 * Membres connectés seulement : la position d'un visiteur n'existe que dans son navigateur.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Position de lecture du membre courant dans une œuvre, et rang des tomes publiés (ordre de
 * lecture), mis en cache pour la requête.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{position:array,rangs:array<int,int>}|null
 */
function lecture_oeuvre( int $oeuvre_id ): ?array {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! function_exists( 'yume_get_tomes' ) ) {
		return null;
	}
	// Cache de la requête (groupe non persistant) : une seule lecture pour toutes les lignes.
	wp_cache_add_non_persistent_groups( array( 'yume_social_requete' ) );
	$cle    = 'lecture_' . $user_id . '_' . $oeuvre_id;
	$trouve = false;
	$valeur = wp_cache_get( $cle, 'yume_social_requete', false, $trouve );
	if ( $trouve ) {
		return is_array( $valeur ) ? $valeur : null;
	}
	$lecture  = null;
	$position = position_membre( $oeuvre_id );
	if ( $position && (int) $position['oeuvre_id'] === $oeuvre_id ) {
		$rangs = array();
		foreach ( yume_get_tomes( $oeuvre_id, array( 'order' => 'ASC' ) ) as $rang => $tome ) {
			$rangs[ (int) $tome->ID ] = (int) $rang;
		}
		if ( isset( $rangs[ (int) $position['tome_id'] ] ) ) {
			$lecture = array(
				'position' => $position,
				'rangs'    => $rangs,
			);
		}
	}
	wp_cache_set( $cle, $lecture ? $lecture : 0, 'yume_social_requete' );
	return $lecture;
}

/**
 * Avancement dans un tome (0–100) : chapitres déjà lus et part lue du chapitre courant,
 * pondérés par leur nombre de mots (à défaut, chaque chapitre compte autant).
 *
 * @param int $tome_id     Tome.
 * @param int $chapitre_id Chapitre courant.
 * @param int $pourcentage Part lue du chapitre courant (0–100).
 */
function avancement_tome( int $tome_id, int $chapitre_id, int $pourcentage ): int {
	$chapitres = function_exists( 'yume_get_chapitres' ) ? yume_get_chapitres( $tome_id ) : array();
	$ids       = array_map( static fn( $c ): int => (int) $c->ID, $chapitres );
	$index     = array_search( $chapitre_id, $ids, true );
	if ( false === $index || ! $ids ) {
		return 0;
	}
	$poids = array();
	foreach ( $ids as $id ) {
		$poids[ $id ] = max( 0, (int) get_post_meta( $id, 'yume_nb_mots', true ) );
	}
	$total = array_sum( $poids );
	if ( $total <= 0 ) {
		$poids = array_fill_keys( $ids, 1 );
		$total = count( $ids );
	}
	$part    = max( 0, min( 100, $pourcentage ) ) / 100;
	$lus     = array_sum( array_slice( $poids, 0, (int) $index, true ) ) + $poids[ $chapitre_id ] * $part;
	$dernier = count( $ids ) - 1 === (int) $index;
	if ( $dernier && $pourcentage >= 98 ) {
		return 100;
	}
	return (int) max( 0, min( 99, round( 100 * $lus / $total ) ) );
}

/**
 * « vous en êtes au chapitre 3 », « vous en êtes à la postface »…
 *
 * @param int $chapitre_id Chapitre.
 */
function texte_position_tome( int $chapitre_id ): string {
	$libelle = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : '';
	$libelle = '' !== $libelle ? mb_strtolower( mb_substr( $libelle, 0, 1 ) ) . mb_substr( $libelle, 1 ) : '';
	switch ( (string) get_post_meta( $chapitre_id, 'yume_nature', true ) ) {
		case 'prologue':
			return __( 'vous en êtes au prologue', 'yume-core' );
		case 'epilogue':
			return __( 'vous en êtes à l’épilogue', 'yume-core' );
		case 'interlude':
			return __( 'vous en êtes à l’interlude', 'yume-core' );
		case 'postface':
			return __( 'vous en êtes à la postface', 'yume-core' );
		case 'illustrations':
			return __( 'vous en êtes aux illustrations', 'yume-core' );
	}
	/* translators: %s : « chapitre 3 », « bonus ». */
	return '' !== $libelle ? sprintf( __( 'vous en êtes au %s', 'yume-core' ), $libelle ) : '';
}

/**
 * Bouton de lecture d'une ligne de tome.
 *
 * @param string $url     Adresse.
 * @param string $texte   Texte du bouton.
 * @param string $tome    Libellé du tome (précision pour les lecteurs d'écran).
 * @param bool   $primaire Bouton principal.
 */
function bouton_ligne_tome( string $url, string $texte, string $tome, bool $primaire ): string {
	return '<a class="' . esc_attr( 'yn-btn yn-btn--sm yn-lire' . ( $primaire ? ' yn-btn--primary' : '' ) ) . '" href="' . esc_url( $url ) . '">'
		. esc_html( $texte )
		. ( '' !== $tome ? '<span class="yn-visually-hidden"> — ' . esc_html( $tome ) . '</span>' : '' )
		. '</a>';
}

/**
 * Filtre yume_bibliotheque_ligne_tome : progression du membre sur une ligne de tome.
 *
 * @param array $ligne   classes (string[]), nom (HTML), details (string[]), lire (HTML).
 * @param int   $tome_id Tome.
 * @param array $stats   Statistiques du tome (premier, chapitres…).
 * @return array
 */
function ligne_tome_progression( $ligne, $tome_id = 0, $stats = array() ) {
	$ligne   = is_array( $ligne ) ? $ligne : array();
	$tome_id = (int) $tome_id;
	$stats   = is_array( $stats ) ? $stats : array();
	if ( $tome_id <= 0 || ! is_user_logged_in() || ! function_exists( 'yume_get_oeuvre_id' ) ) {
		return $ligne;
	}
	$lecture = lecture_oeuvre( yume_get_oeuvre_id( $tome_id ) );
	if ( ! $lecture || ! isset( $lecture['rangs'][ $tome_id ] ) ) {
		return $ligne;
	}
	$position = $lecture['position'];
	$rang     = $lecture['rangs'][ $tome_id ];
	$courant  = $lecture['rangs'][ (int) $position['tome_id'] ];
	if ( $rang > $courant ) {
		return $ligne;
	}

	$libelle = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $tome_id ) : '';
	$details = array_values( array_filter( array_map( 'strval', (array) ( $ligne['details'] ?? array() ) ), 'strlen' ) );
	$resume  = $details ? array( $details[0] ) : array();
	$classes = array_values( (array) ( $ligne['classes'] ?? array( 'yn-tome-list__ligne' ) ) );

	$avancement = $rang === $courant ? avancement_tome( $tome_id, (int) $position['chapitre_id'], (int) $position['pourcentage'] ) : 100;
	if ( $avancement < 100 ) {
		// Tome en cours de lecture.
		$classes[] = 'yn-tome-list__ligne--lecture';
		$format    = ! empty( $stats['en_cours'] )
			/* translators: %s : pourcentage lu du tome (arc encore en cours de sortie). */
			? __( 'Lecture · %s %% du tome', 'yume-core' )
			/* translators: %s : pourcentage lu du tome. */
			: __( 'En cours · %s %% du tome', 'yume-core' );

		$ligne['nom']     = (string) ( $ligne['nom'] ?? '' ) . ' <span class="yn-chip yn-chip--ok yn-tome-list__progression"><span class="yn-visually-hidden">' . esc_html__( 'Votre lecture :', 'yume-core' ) . ' </span>' . esc_html( sprintf( $format, (string) $avancement ) ) . '</span>';
		$ligne['details'] = array_values( array_filter( array_merge( $resume, array( texte_position_tome( (int) $position['chapitre_id'] ) ) ), 'strlen' ) );
		$ligne['lire']    = bouton_ligne_tome( (string) $position['url_reprise'], __( 'Reprendre', 'yume-core' ), (string) $position['titre'], true );
	} else {
		// Tome déjà lu (précédent dans l'ordre de lecture, ou terminé).
		$classes[]        = 'yn-tome-list__ligne--lu';
		$ligne['details'] = array_merge( $resume, array( __( 'lu ✓', 'yume-core' ) ) );
		$premier          = (int) ( $stats['premier'] ?? 0 );
		$url              = $premier ? (string) get_permalink( $premier ) : '';
		if ( '' !== $url ) {
			$ligne['lire'] = bouton_ligne_tome( $url, __( 'Relire', 'yume-core' ), $libelle, false );
		}
	}
	$ligne['classes'] = array_values( array_unique( $classes ) );
	return $ligne;
}
add_filter( 'yume_bibliotheque_ligne_tome', __NAMESPACE__ . '\\ligne_tome_progression', 10, 3 );
