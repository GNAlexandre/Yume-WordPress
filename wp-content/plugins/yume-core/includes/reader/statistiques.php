<?php
/**
 * Statistiques de lecture d'un membre (PAGE-06), affichées dans la rubrique « Mes statistiques »
 * de la page compte : tomes terminés, chapitres lus, temps de lecture estimé, séries en cours et
 * badge « À jour » par série.
 *
 * Aucune donnée nouvelle : la table progression garde une position par œuvre (le dernier
 * chapitre ouvert). Les chapitres publiés qui la précèdent dans l'ordre de lecture (tomes par
 * numéro, chapitres par menu_order puis numéro, comme yume_get_tomes() / yume_get_chapitres())
 * comptent comme lus, ainsi que le chapitre courant s'il est lu à SEUIL_CHAPITRE_LU % au moins.
 * Deux requêtes agrégées (tomes puis chapitres de toutes les œuvres du membre, méta jointes),
 * quel que soit le nombre de séries.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/** Pourcentage à partir duquel le chapitre courant compte comme lu. */
const SEUIL_CHAPITRE_LU = 90;

/** Mots lus par minute (même base que yume_temps_lecture, contrat §4). */
const MOTS_PAR_MINUTE = 230;

/**
 * Numéro de tri (null si absent), comme numero_ou_null() du module core.
 *
 * @param mixed $valeur Valeur de méta.
 */
function numero_tri( $valeur ): ?float {
	if ( is_string( $valeur ) ) {
		$valeur = str_replace( ',', '.', trim( $valeur ) );
	}
	if ( '' === $valeur || null === $valeur || ! is_numeric( $valeur ) ) {
		return null;
	}
	return round( (float) $valeur, 3 );
}

/**
 * Compare deux numéros de tri (sans numéro : en dernier) ; 0 si égaux.
 *
 * @param float|null $a Numéro.
 * @param float|null $b Numéro.
 */
function comparer_numeros( ?float $a, ?float $b ): int {
	if ( $a === $b ) {
		return 0;
	}
	if ( null === $a ) {
		return 1;
	}
	if ( null === $b ) {
		return -1;
	}
	return $a <=> $b;
}

/**
 * Tomes et chapitres publiés de plusieurs œuvres, dans l'ordre de lecture.
 *
 * @param int[] $oeuvres Œuvres.
 * @return array<int,array<int,array{id:int,chapitres:array<int,array{id:int,minutes:int}>}>> œuvre => tomes ordonnés.
 */
function plan_de_lecture( array $oeuvres ): array {
	global $wpdb;
	$oeuvres = array_values( array_unique( array_filter( array_map( 'intval', $oeuvres ) ) ) );
	if ( ! $oeuvres ) {
		return array();
	}
	$marques = implode( ', ', array_fill( 0, count( $oeuvres ), '%d' ) );

	// Tomes publiés : œuvre, numéro, ordre.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
	$tomes = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.menu_order, p.post_date, CAST(mo.meta_value AS UNSIGNED) AS oeuvre, mn.meta_value AS numero
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} mo ON mo.post_id = p.ID AND mo.meta_key = 'yume_oeuvre_id'
			LEFT JOIN {$wpdb->postmeta} mn ON mn.post_id = p.ID AND mn.meta_key = 'yume_numero'
			WHERE p.post_type = 'yume_tome' AND p.post_status = 'publish' AND CAST(mo.meta_value AS UNSIGNED) IN ($marques)",
			$oeuvres
		),
		ARRAY_A
	);
	// Chapitres publiés de ces œuvres : tome, numéro, ordre, durée.
	$chapitres = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.menu_order, p.post_date, CAST(mt.meta_value AS UNSIGNED) AS tome, mn.meta_value AS numero,
				ml.meta_value AS minutes, mm.meta_value AS mots
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} mo ON mo.post_id = p.ID AND mo.meta_key = 'yume_oeuvre_id'
			INNER JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = 'yume_tome_id'
			LEFT JOIN {$wpdb->postmeta} mn ON mn.post_id = p.ID AND mn.meta_key = 'yume_numero'
			LEFT JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = 'yume_temps_lecture'
			LEFT JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID AND mm.meta_key = 'yume_nb_mots'
			WHERE p.post_type = 'yume_chapitre' AND p.post_status = 'publish' AND CAST(mo.meta_value AS UNSIGNED) IN ($marques)",
			$oeuvres
		),
		ARRAY_A
	);
	// phpcs:enable

	$par_tome = array();
	foreach ( (array) $chapitres as $c ) {
		$minutes = (int) $c['minutes'];
		if ( $minutes <= 0 && (int) $c['mots'] > 0 ) {
			$minutes = (int) max( 1, ceil( (int) $c['mots'] / MOTS_PAR_MINUTE ) );
		}
		$par_tome[ (int) $c['tome'] ][ (int) $c['ID'] ] = array(
			'id'      => (int) $c['ID'],
			'ordre'   => (int) $c['menu_order'],
			'numero'  => numero_tri( $c['numero'] ),
			'date'    => (string) $c['post_date'],
			'minutes' => $minutes,
		);
	}
	$trier_chapitres = static function ( array $a, array $b ): int {
		$cmp = $a['ordre'] <=> $b['ordre'];
		if ( 0 === $cmp ) {
			$cmp = comparer_numeros( $a['numero'], $b['numero'] );
		}
		if ( 0 === $cmp ) {
			$cmp = strcmp( $a['date'], $b['date'] );
		}
		return 0 !== $cmp ? $cmp : ( $a['id'] <=> $b['id'] );
	};
	$trier_tomes     = static function ( array $a, array $b ): int {
		$cmp = comparer_numeros( numero_tri( $a['numero'] ), numero_tri( $b['numero'] ) );
		if ( 0 === $cmp ) {
			$cmp = (int) $a['menu_order'] <=> (int) $b['menu_order'];
		}
		if ( 0 === $cmp ) {
			$cmp = strcmp( (string) $a['post_date'], (string) $b['post_date'] );
		}
		return 0 !== $cmp ? $cmp : ( (int) $a['ID'] <=> (int) $b['ID'] );
	};

	$tomes = (array) $tomes;
	usort( $tomes, $trier_tomes );
	$plan = array_fill_keys( $oeuvres, array() );
	foreach ( $tomes as $t ) {
		$liste = $par_tome[ (int) $t['ID'] ] ?? array();
		usort( $liste, $trier_chapitres );
		$plan[ (int) $t['oeuvre'] ][] = array(
			'id'        => (int) $t['ID'],
			'chapitres' => array_map(
				static fn( array $c ): array => array(
					'id'      => $c['id'],
					'minutes' => $c['minutes'],
				),
				$liste
			),
		);
	}
	return $plan;
}

/**
 * Statistiques de lecture d'un membre.
 *
 * @param int $user_id Membre.
 * @return array{tomes_termines:int,chapitres_lus:int,minutes:int,series_en_cours:int,series_a_jour:int,series:array<int,array<string,mixed>>}
 */
function statistiques_lecture( int $user_id ): array {
	$stats = array(
		'tomes_termines'  => 0,
		'chapitres_lus'   => 0,
		'minutes'         => 0,
		'series_en_cours' => 0,
		'series_a_jour'   => 0,
		'series'          => array(),
	);
	if ( $user_id <= 0 ) {
		return $stats;
	}
	$lignes = lignes_progression( $user_id );
	if ( ! $lignes ) {
		return $stats;
	}
	$plan = plan_de_lecture( wp_list_pluck( $lignes, 'oeuvre_id' ) );
	foreach ( $lignes as $ligne ) {
		$oeuvre_id = (int) $ligne['oeuvre_id'];
		if ( 'publish' !== get_post_status( $oeuvre_id ) || empty( $plan[ $oeuvre_id ] ) ) {
			continue;
		}
		$serie = array(
			'oeuvre_id'      => $oeuvre_id,
			'titre'          => wp_strip_all_tags( get_the_title( $oeuvre_id ) ),
			'url'            => (string) get_permalink( $oeuvre_id ),
			'chapitres_lus'  => 0,
			'chapitres'      => 0,
			'tomes_termines' => 0,
			'tomes'          => 0,
			'minutes'        => 0,
			'a_jour'         => false,
			'trouve'         => false,
		);
		// Parcours dans l'ordre : tout ce qui précède la position est lu.
		$avant = true;
		foreach ( $plan[ $oeuvre_id ] as $tome ) {
			if ( ! $tome['chapitres'] ) {
				continue;
			}
			++$serie['tomes'];
			$tome_lu = true;
			foreach ( $tome['chapitres'] as $chapitre ) {
				++$serie['chapitres'];
				$lu = $avant;
				if ( $chapitre['id'] === (int) $ligne['chapitre_id'] ) {
					$serie['trouve'] = true;
					$lu              = (int) $ligne['pourcentage'] >= SEUIL_CHAPITRE_LU;
					$avant           = false;
				}
				if ( $lu ) {
					++$serie['chapitres_lus'];
					$serie['minutes'] += $chapitre['minutes'];
				} else {
					$tome_lu = false;
				}
			}
			if ( $tome_lu ) {
				++$serie['tomes_termines'];
			}
		}
		// Position sur un chapitre dépublié ou déplacé : rien ne peut être déduit.
		if ( ! $serie['trouve'] ) {
			continue;
		}
		unset( $serie['trouve'] );
		$serie['a_jour']          = $serie['chapitres'] > 0 && $serie['chapitres_lus'] >= $serie['chapitres'];
		$serie['reste']           = max( 0, $serie['chapitres'] - $serie['chapitres_lus'] );
		$stats['tomes_termines'] += $serie['tomes_termines'];
		$stats['chapitres_lus']  += $serie['chapitres_lus'];
		$stats['minutes']        += $serie['minutes'];
		if ( $serie['a_jour'] ) {
			++$stats['series_a_jour'];
		} else {
			++$stats['series_en_cours'];
		}
		$stats['series'][] = $serie;
	}
	return $stats;
}

/**
 * Durée lisible : « 45 min », « 3 h 20 », « 12 h ».
 *
 * @param int $minutes Minutes.
 */
function duree_lisible( int $minutes ): string {
	$minutes = max( 0, $minutes );
	if ( $minutes < 60 ) {
		/* translators: %d : minutes. */
		return sprintf( __( '%d min', 'yume-core' ), $minutes );
	}
	$heures = intdiv( $minutes, 60 );
	$reste  = $minutes % 60;
	/* translators: 1 : heures, 2 : minutes (deux chiffres). */
	return 0 === $reste ? sprintf( __( '%d h', 'yume-core' ), $heures ) : sprintf( __( '%1$d h %2$02d', 'yume-core' ), $heures, $reste );
}
