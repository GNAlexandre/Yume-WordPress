<?php
/**
 * Statistiques, œuvres : compteurs par œuvre (favoris, suiveurs alertés, listes de lecture,
 * lecteurs, lecteurs actifs sur 30 jours, chapitres lus estimés), classements, fiche statistique
 * d'une œuvre et résumé des lecteurs du site. Agrégats seulement : aucun lecteur n'est nommé.
 *
 * Définitions :
 * - favoris : lignes de yume_favoris ; suiveurs (alertés) : favoris dont la fréquence n'est pas
 *   « jamais » ;
 * - listes : listes de lecture qui contiennent l'œuvre, par liste système (À lire, En cours,
 *   Terminé) ou personnelle ;
 * - lecteurs : comptes ayant une position de lecture sur l'œuvre ; lecteurs_30j : position mise à
 *   jour depuis 30 jours ;
 * - chapitres lus (estimation, comme « Mes statistiques ») : pour chaque position, les chapitres
 *   publiés qui la précèdent dans l'ordre de lecture, plus le chapitre courant s'il est lu à
 *   Reader\SEUIL_CHAPITRE_LU % au moins. Une requête agrégée par (œuvre, chapitre) et le plan de
 *   lecture (Reader\plan_de_lecture(), deux requêtes) : jamais une boucle par lecteur.
 *
 * Toutes les œuvres en au plus cinq requêtes agrégées (GROUP BY oeuvre_id), SQL compatible
 * MariaDB et SQLite. Résultats mis en cache 5 minutes (cache_lire() / cache_ecrire()), cache vidé
 * quand un favori est ajouté ou retiré et quand un tome ou un chapitre est publié. Les vues
 * viennent de l'historique quotidien (historique.php), alimenté par le trafic Jetpack quand il est
 * disponible : null sinon.
 *
 * Les tables des modules social et reader sont lues via leurs fonctions, gardées par
 * function_exists (module absent : compteurs à zéro).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Durée du cache des compteurs, classements, fiches et résumés (secondes). */
const DUREE_CACHE_OEUVRES = 300;

/** Fenêtre des « lecteurs actifs » des compteurs, en jours. */
const JOURS_LECTEURS_ACTIFS = 30;

/** Seuil de lecture du chapitre courant si le module reader ne le définit pas. */
const SEUIL_CHAPITRE_LU_DEFAUT = 90;

/** Borne GMT « sans limite » des compteurs en direct. */
const BORNE_GMT_MAX = '9999-12-31 23:59:59';

/** Tri du classement => champ comparé. */
const CHAMPS_TRI_CLASSEMENT = array(
	'favoris'    => 'favoris',
	'suiveurs'   => 'suiveurs',
	'lecteurs'   => 'lecteurs',
	'vues'       => 'vues',
	'nouveaux'   => 'nouveaux_favoris',
	'croissance' => 'croissance_favoris',
);

/*
 * Invalidation du cache : un favori ajouté ou retiré, un tome ou un chapitre publié. Pas sur
 * yume_progression_enregistree (bien trop fréquent : les compteurs restent en cache 5 minutes).
 */
add_action( 'yume_favori_ajoute', __NAMESPACE__ . '\\cache_vider', 10, 0 );
add_action( 'yume_favori_retire', __NAMESPACE__ . '\\cache_vider', 10, 0 );
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\cache_vider', 10, 0 );
add_action( 'yume_chapitre_publie', __NAMESPACE__ . '\\cache_vider', 10, 0 );

/*
 * -----------------------------------------------------------------------------
 * Aides
 * -----------------------------------------------------------------------------
 */

/**
 * Valeur en cache (5 minutes) ou calculée puis mise en cache.
 *
 * @param string   $cle    Clé (sans préfixe).
 * @param callable $calcul Calcul (renvoie un tableau).
 * @return mixed
 */
function avec_cache_oeuvres( string $cle, callable $calcul ) {
	$valeur = cache_lire( $cle );
	if ( false !== $valeur ) {
		return $valeur;
	}
	$valeur = $calcul();
	cache_ecrire( $cle, $valeur, DUREE_CACHE_OEUVRES );
	return $valeur;
}

/**
 * Compteurs à zéro (forme commune de compteurs_oeuvres() et compteurs_oeuvre()).
 *
 * @return array{favoris:int,suiveurs:int,listes:array{a_lire:int,en_cours:int,termine:int,perso:int},lecteurs:int,lecteurs_30j:int,chapitres_lus:int}
 */
function compteurs_vides(): array {
	return array(
		'favoris'       => 0,
		'suiveurs'      => 0,
		'listes'        => array(
			'a_lire'   => 0,
			'en_cours' => 0,
			'termine'  => 0,
			'perso'    => 0,
		),
		'lecteurs'      => 0,
		'lecteurs_30j'  => 0,
		'chapitres_lus' => 0,
	);
}

/**
 * La table des favoris est-elle lisible (module social chargé et installé) ?
 */
function favoris_lisibles(): bool {
	return function_exists( '\\Yume\\Core\\Social\\table_favoris' ) && function_exists( '\\Yume\\Core\\Social\\tables_pretes' ) && \Yume\Core\Social\tables_pretes();
}

/**
 * Les tables des listes de lecture sont-elles lisibles ?
 */
function listes_lisibles(): bool {
	return function_exists( '\\Yume\\Core\\Social\\table_listes_oeuvres' ) && function_exists( '\\Yume\\Core\\Social\\tables_lecteur_pretes' ) && \Yume\Core\Social\tables_lecteur_pretes();
}

/**
 * La table de progression est-elle lisible (module reader chargé et installé) ?
 */
function progression_lisible(): bool {
	return function_exists( '\\Yume\\Core\\Reader\\table_progression' ) && function_exists( '\\Yume\\Core\\Reader\\table_prete' ) && \Yume\Core\Reader\table_prete();
}

/**
 * Date GMT « Y-m-d H:i:s » d'un horodatage décalé de $jours jours.
 *
 * @param int $ts    Horodatage.
 * @param int $jours Décalage en jours (négatif : dans le passé).
 */
function gmt_decale( int $ts, int $jours ): string {
	return gmdate( 'Y-m-d H:i:s', $ts + $jours * DAY_IN_SECONDS );
}

/**
 * Titre brut d'une œuvre (sans balises ni entités).
 *
 * @param int $oeuvre_id Œuvre.
 */
function titre_oeuvre_stats( int $oeuvre_id ): string {
	$publiees = oeuvres_publiees();
	if ( isset( $publiees[ $oeuvre_id ] ) ) {
		return $publiees[ $oeuvre_id ];
	}
	return trim( wp_strip_all_tags( html_entity_decode( (string) get_post_field( 'post_title', $oeuvre_id ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Identité d'une œuvre pour les réponses : ID, titre brut, adresse publique.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{id:int,titre:string,url:string}
 */
function identite_oeuvre( int $oeuvre_id ): array {
	return array(
		'id'    => $oeuvre_id,
		'titre' => titre_oeuvre_stats( $oeuvre_id ),
		'url'   => (string) get_permalink( $oeuvre_id ),
	);
}

/**
 * Marqueurs %d d'une liste d'ID (requêtes IN).
 *
 * @param int[] $ids ID.
 */
function marques_ids( array $ids ): string {
	return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
}

/**
 * Seuil (%) à partir duquel le chapitre courant compte comme lu.
 */
function seuil_chapitre_lu(): int {
	return defined( 'Yume\\Core\\Reader\\SEUIL_CHAPITRE_LU' ) ? (int) constant( 'Yume\\Core\\Reader\\SEUIL_CHAPITRE_LU' ) : SEUIL_CHAPITRE_LU_DEFAUT;
}

/*
 * -----------------------------------------------------------------------------
 * Compteurs
 * -----------------------------------------------------------------------------
 */

/**
 * Rang de chaque chapitre publié dans l'ordre de lecture de son œuvre (1 pour le premier).
 * Deux requêtes (Reader\plan_de_lecture()), quel que soit le nombre d'œuvres.
 *
 * @param int[] $ids Œuvres.
 * @return array<int,array<int,int>> œuvre => (chapitre => rang).
 */
function rangs_chapitres( array $ids ): array {
	if ( ! $ids || ! function_exists( '\\Yume\\Core\\Reader\\plan_de_lecture' ) ) {
		return array();
	}
	$rangs = array();
	foreach ( \Yume\Core\Reader\plan_de_lecture( $ids ) as $oeuvre_id => $tomes ) {
		$rang = 0;
		foreach ( (array) $tomes as $tome ) {
			foreach ( (array) ( $tome['chapitres'] ?? array() ) as $chapitre ) {
				$rangs[ (int) $oeuvre_id ][ (int) $chapitre['id'] ] = ++$rang;
			}
		}
	}
	return $rangs;
}

/**
 * Compteurs de plusieurs œuvres, en au plus cinq requêtes agrégées : favoris, listes, positions
 * de lecture (par œuvre et chapitre), plan de lecture (deux requêtes, seulement s'il y a des
 * positions).
 *
 * @param int[]  $ids           Œuvres.
 * @param string $actifs_depuis Début GMT de la fenêtre des lecteurs actifs (lecteurs_30j).
 * @param string $jusqua        Borne GMT : favoris et ajouts aux listes postérieurs ignorés
 *                              (historique d'un jour passé). BORNE_GMT_MAX : aucune borne.
 * @return array<int,array<string,mixed>> œuvre => compteurs (forme de compteurs_vides()).
 */
function compteurs_calcules( array $ids, string $actifs_depuis, string $jusqua = BORNE_GMT_MAX ): array {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
	if ( ! $ids ) {
		return array();
	}
	$resultat = array_fill_keys( $ids, compteurs_vides() );
	$marques  = marques_ids( $ids );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	if ( favoris_lisibles() ) {
		$table  = \Yume\Core\Social\table_favoris();
		$lignes = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.oeuvre_id, COUNT(*) AS favoris, SUM(CASE WHEN f.frequence <> 'jamais' THEN 1 ELSE 0 END) AS suiveurs
				FROM {$table} f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id
				WHERE f.oeuvre_id IN ($marques) AND f.created_at <= %s GROUP BY f.oeuvre_id",
				array_merge( $ids, array( $jusqua ) )
			),
			ARRAY_A
		);
		foreach ( $lignes as $ligne ) {
			$resultat[ (int) $ligne['oeuvre_id'] ]['favoris']  = (int) $ligne['favoris'];
			$resultat[ (int) $ligne['oeuvre_id'] ]['suiveurs'] = (int) $ligne['suiveurs'];
		}
	}

	if ( listes_lisibles() ) {
		$listes  = \Yume\Core\Social\table_listes();
		$contenu = \Yume\Core\Social\table_listes_oeuvres();
		$lignes  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lo.oeuvre_id, l.systeme, COUNT(*) AS nb
				FROM {$contenu} lo INNER JOIN {$listes} l ON l.id = lo.liste_id
				WHERE lo.oeuvre_id IN ($marques) AND lo.ajoute_le <= %s GROUP BY lo.oeuvre_id, l.systeme",
				array_merge( $ids, array( $jusqua ) )
			),
			ARRAY_A
		);
		foreach ( $lignes as $ligne ) {
			$systeme = (string) $ligne['systeme'];
			$cle     = in_array( $systeme, array( 'a_lire', 'en_cours', 'termine' ), true ) ? $systeme : 'perso';
			$resultat[ (int) $ligne['oeuvre_id'] ]['listes'][ $cle ] += (int) $ligne['nb'];
		}
	}

	if ( progression_lisible() ) {
		$table  = \Yume\Core\Reader\table_progression();
		$lignes = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oeuvre_id, chapitre_id, COUNT(*) AS nb,
					SUM(CASE WHEN updated_at >= %s THEN 1 ELSE 0 END) AS actifs,
					SUM(CASE WHEN pourcentage >= %d THEN 1 ELSE 0 END) AS finis
				FROM {$table} WHERE oeuvre_id IN ($marques) GROUP BY oeuvre_id, chapitre_id",
				array_merge( array( $actifs_depuis, seuil_chapitre_lu() ), $ids )
			),
			ARRAY_A
		);
		$rangs  = $lignes ? rangs_chapitres( array_values( array_unique( array_map( 'intval', wp_list_pluck( $lignes, 'oeuvre_id' ) ) ) ) ) : array();
		foreach ( $lignes as $ligne ) {
			$oeuvre_id = (int) $ligne['oeuvre_id'];
			$nb        = (int) $ligne['nb'];
			// La clé primaire (user_id, oeuvre_id) : une ligne = un lecteur de l'œuvre.
			$resultat[ $oeuvre_id ]['lecteurs']     += $nb;
			$resultat[ $oeuvre_id ]['lecteurs_30j'] += (int) $ligne['actifs'];
			// Position sur un chapitre dépublié ou inconnu : rien ne peut en être déduit.
			$rang = $rangs[ $oeuvre_id ][ (int) $ligne['chapitre_id'] ] ?? 0;
			if ( $rang > 0 ) {
				$resultat[ $oeuvre_id ]['chapitres_lus'] += $nb * ( $rang - 1 ) + (int) $ligne['finis'];
			}
		}
	}
	// phpcs:enable

	return $resultat;
}

/**
 * Compteurs de toutes les œuvres publiées (en cache 5 minutes).
 *
 * @return array<int,array{favoris:int,suiveurs:int,listes:array{a_lire:int,en_cours:int,termine:int,perso:int},lecteurs:int,lecteurs_30j:int,chapitres_lus:int}>
 */
function compteurs_oeuvres(): array {
	return (array) avec_cache_oeuvres(
		'compteurs',
		static function (): array {
			$ids = array_keys( oeuvres_publiees() );
			return compteurs_calcules( $ids, gmt_decale( maintenant(), -JOURS_LECTEURS_ACTIFS ) );
		}
	);
}

/**
 * Compteurs d'une œuvre (en cache 5 minutes), même forme que compteurs_oeuvres().
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{favoris:int,suiveurs:int,listes:array{a_lire:int,en_cours:int,termine:int,perso:int},lecteurs:int,lecteurs_30j:int,chapitres_lus:int}
 */
function compteurs_oeuvre( int $oeuvre_id ): array {
	if ( $oeuvre_id <= 0 ) {
		return compteurs_vides();
	}
	return (array) avec_cache_oeuvres(
		'compteurs_' . $oeuvre_id,
		static function () use ( $oeuvre_id ): array {
			$compteurs = compteurs_calcules( array( $oeuvre_id ), gmt_decale( maintenant(), -JOURS_LECTEURS_ACTIFS ) );
			return $compteurs[ $oeuvre_id ] ?? compteurs_vides();
		}
	);
}

/**
 * Favoris ajoutés pendant une période, par œuvre (une requête).
 *
 * @param string $debut     Début GMT (inclus).
 * @param string $fin       Fin GMT (incluse).
 * @param int    $oeuvre_id Une seule œuvre (0 : toutes).
 * @return array<int,int> œuvre => nouveaux favoris.
 */
function nouveaux_favoris( string $debut, string $fin, int $oeuvre_id = 0 ): array {
	global $wpdb;
	if ( ! favoris_lisibles() ) {
		return array();
	}
	$table   = \Yume\Core\Social\table_favoris();
	$filtre  = $oeuvre_id > 0 ? ' AND f.oeuvre_id = %d' : '';
	$valeurs = $oeuvre_id > 0 ? array( $debut, $fin, $oeuvre_id ) : array( $debut, $fin );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$lignes   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT f.oeuvre_id, COUNT(*) AS nb FROM {$table} f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id WHERE f.created_at BETWEEN %s AND %s{$filtre} GROUP BY f.oeuvre_id", $valeurs ), ARRAY_A );
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		$resultat[ (int) $ligne['oeuvre_id'] ] = (int) $ligne['nb'];
	}
	return $resultat;
}

/**
 * Agrégats des favoris : total, suiveurs alertés, lecteurs distincts, répartition des fréquences.
 * Pour une œuvre, ou pour toutes les œuvres publiées (comptes existants seulement).
 *
 * @param int $oeuvre_id Œuvre (0 : toutes les œuvres publiées).
 * @return array{favoris:int,suiveurs:int,lecteurs:int,frequences:array{immediat:int,hebdo:int,jamais:int}}
 */
function agregats_favoris( int $oeuvre_id = 0 ): array {
	global $wpdb;
	$resultat = array(
		'favoris'    => 0,
		'suiveurs'   => 0,
		'lecteurs'   => 0,
		'frequences' => array(
			'immediat' => 0,
			'hebdo'    => 0,
			'jamais'   => 0,
		),
	);
	if ( ! favoris_lisibles() ) {
		return $resultat;
	}
	$table = \Yume\Core\Social\table_favoris();
	$sql   = "SELECT COUNT(*) AS favoris, COUNT(DISTINCT f.user_id) AS lecteurs,
			SUM(CASE WHEN f.frequence = 'hebdo' THEN 1 ELSE 0 END) AS hebdo,
			SUM(CASE WHEN f.frequence = 'jamais' THEN 1 ELSE 0 END) AS jamais
		FROM {$table} f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id";
	if ( $oeuvre_id > 0 ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ligne = $wpdb->get_row( $wpdb->prepare( $sql . ' WHERE f.oeuvre_id = %d', $oeuvre_id ), ARRAY_A );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ligne = $wpdb->get_row( $wpdb->prepare( $sql . " INNER JOIN {$wpdb->posts} p ON p.ID = f.oeuvre_id WHERE p.post_type = %s AND p.post_status = %s", 'yume_oeuvre', 'publish' ), ARRAY_A );
	}
	$ligne   = is_array( $ligne ) ? $ligne : array();
	$favoris = (int) ( $ligne['favoris'] ?? 0 );
	$hebdo   = (int) ( $ligne['hebdo'] ?? 0 );
	$jamais  = (int) ( $ligne['jamais'] ?? 0 );
	// Une fréquence inconnue vaut « immédiat » (comme Social\frequence_favori()).
	$resultat['favoris']    = $favoris;
	$resultat['suiveurs']   = $favoris - $jamais;
	$resultat['lecteurs']   = (int) ( $ligne['lecteurs'] ?? 0 );
	$resultat['frequences'] = array(
		'immediat' => max( 0, $favoris - $hebdo - $jamais ),
		'hebdo'    => $hebdo,
		'jamais'   => $jamais,
	);
	return $resultat;
}

/**
 * Vues d'une période et de la période précédente, par œuvre, d'après l'historique quotidien
 * (null : aucune vue connue sur la période, trafic indisponible). Une requête.
 *
 * @param array $bornes    Bornes (bornes_periode()).
 * @param int   $oeuvre_id Une seule œuvre (0 : toutes).
 * @return array<int,array{vues:?int,vues_prec:?int}>
 */
function vues_historique( array $bornes, int $oeuvre_id = 0 ): array {
	global $wpdb;
	if ( ! table_prete() ) {
		return array();
	}
	$table   = table_stats_jour();
	$filtre  = $oeuvre_id > 0 ? 'oeuvre_id = %d' : 'oeuvre_id > %d';
	$valeurs = array( $bornes['debut'], $bornes['debut'], $bornes['debut'], $bornes['debut'], $bornes['debut_prec'], $bornes['fin'], max( 0, $oeuvre_id ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT oeuvre_id,
				SUM(CASE WHEN jour >= %s THEN vues END) AS vues, COUNT(CASE WHEN jour >= %s THEN vues END) AS n,
				SUM(CASE WHEN jour < %s THEN vues END) AS vues_prec, COUNT(CASE WHEN jour < %s THEN vues END) AS n_prec
			FROM {$table} WHERE jour BETWEEN %s AND %s AND {$filtre} GROUP BY oeuvre_id",
			$valeurs
		),
		ARRAY_A
	);
	// phpcs:enable
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		$resultat[ (int) $ligne['oeuvre_id'] ] = array(
			'vues'      => (int) $ligne['n'] > 0 ? (int) $ligne['vues'] : null,
			'vues_prec' => (int) $ligne['n_prec'] > 0 ? (int) $ligne['vues_prec'] : null,
		);
	}
	return $resultat;
}

/**
 * Favoris de chaque œuvre au début d'une fenêtre d'historique : première ligne dont le jour est
 * compris entre $debut et $fin. Deux requêtes (premier jour par œuvre, puis valeurs).
 *
 * @param string $debut Premier jour « Y-m-d » de la fenêtre.
 * @param string $fin   Dernier jour « Y-m-d ».
 * @return array<int,int> œuvre => favoris.
 */
function favoris_debut_historique( string $debut, string $fin ): array {
	global $wpdb;
	if ( ! table_prete() ) {
		return array();
	}
	$table = table_stats_jour();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$premiers = (array) $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, MIN(jour) AS jour FROM {$table} WHERE jour BETWEEN %s AND %s AND oeuvre_id > 0 GROUP BY oeuvre_id", $debut, $fin ), ARRAY_A );
	if ( ! $premiers ) {
		return array();
	}
	$attendus = array();
	foreach ( $premiers as $ligne ) {
		$attendus[ (int) $ligne['oeuvre_id'] ] = substr( (string) $ligne['jour'], 0, 10 );
	}
	$jours  = array_values( array_unique( $attendus ) );
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT oeuvre_id, jour, favoris FROM {$table} WHERE oeuvre_id > 0 AND jour IN (" . implode( ', ', array_fill( 0, count( $jours ), '%s' ) ) . ')',
			$jours
		),
		ARRAY_A
	);
	// phpcs:enable
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		$oeuvre_id = (int) $ligne['oeuvre_id'];
		if ( ( $attendus[ $oeuvre_id ] ?? '' ) === substr( (string) $ligne['jour'], 0, 10 ) ) {
			$resultat[ $oeuvre_id ] = (int) $ligne['favoris'];
		}
	}
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Classement
 * -----------------------------------------------------------------------------
 */

/**
 * Lignes du classement d'une période, toutes œuvres publiées, sans adresse (en cache 5 minutes).
 *
 * Croissance des favoris : favoris actuels moins les favoris de la première ligne d'historique de la
 * fenêtre [veille de la période, aujourd'hui] (null sans historique). Croissance des vues : vues
 * de la période moins vues de la période précédente (null si l'une est inconnue).
 *
 * @param int $jours Période en jours.
 * @return array<int,array<string,mixed>>
 */
function lignes_classement( int $jours ): array {
	return (array) avec_cache_oeuvres(
		'classement_' . $jours,
		static function () use ( $jours ): array {
			$bornes    = bornes_periode( $jours );
			$compteurs = compteurs_oeuvres();
			$nouveaux  = nouveaux_favoris( $bornes['debut_gmt'], $bornes['fin_gmt'] );
			$vues      = vues_historique( $bornes );
			$depart    = favoris_debut_historique( $bornes['fin_prec'], $bornes['fin'] );
			$lignes    = array();
			foreach ( oeuvres_publiees() as $oeuvre_id => $titre ) {
				$c        = $compteurs[ $oeuvre_id ] ?? compteurs_vides();
				$v        = $vues[ $oeuvre_id ] ?? array(
					'vues'      => null,
					'vues_prec' => null,
				);
				$lignes[] = array(
					'oeuvre_id'          => (int) $oeuvre_id,
					'titre'              => (string) $titre,
					'url'                => '',
					'favoris'            => (int) $c['favoris'],
					'suiveurs'           => (int) $c['suiveurs'],
					'lecteurs'           => (int) $c['lecteurs'],
					'lecteurs_30j'       => (int) $c['lecteurs_30j'],
					'vues'               => $v['vues'],
					'nouveaux_favoris'   => (int) ( $nouveaux[ $oeuvre_id ] ?? 0 ),
					'croissance_favoris' => isset( $depart[ $oeuvre_id ] ) ? (int) $c['favoris'] - $depart[ $oeuvre_id ] : null,
					'croissance_vues'    => null !== $v['vues'] && null !== $v['vues_prec'] ? $v['vues'] - $v['vues_prec'] : null,
				);
			}
			return $lignes;
		}
	);
}

/**
 * Classement des œuvres publiées sur une période.
 *
 * @param int    $jours  Période en jours (ramenée à PERIODES).
 * @param string $tri    favoris|suiveurs|lecteurs|vues|nouveaux|croissance (décroissant ; valeurs
 *                       inconnues en dernier ; à égalité : favoris, puis titre).
 * @param int    $limite Œuvres par page (1 à OEUVRES_PAGE_MAX).
 * @param int    $page   Page (1 pour la première).
 * @return array{tri:string,total:int,page:int,pages:int,oeuvres:array<int,array<string,mixed>>}
 */
function classement( int $jours, string $tri = 'favoris', int $limite = 20, int $page = 1 ): array {
	$jours  = periode_valide( $jours );
	$tri    = isset( CHAMPS_TRI_CLASSEMENT[ $tri ] ) ? $tri : 'favoris';
	$champ  = CHAMPS_TRI_CLASSEMENT[ $tri ];
	$limite = min( OEUVRES_PAGE_MAX, max( 1, $limite ) );
	$page   = max( 1, $page );
	$lignes = lignes_classement( $jours );
	usort(
		$lignes,
		static function ( array $a, array $b ) use ( $champ ): int {
			if ( null === $a[ $champ ] || null === $b[ $champ ] ) {
				$cmp = ( null === $a[ $champ ] ) <=> ( null === $b[ $champ ] );
			} else {
				$cmp = $b[ $champ ] <=> $a[ $champ ];
			}
			if ( 0 === $cmp ) {
				$cmp = $b['favoris'] <=> $a['favoris'];
			}
			if ( 0 === $cmp ) {
				$cmp = strnatcasecmp( $a['titre'], $b['titre'] );
			}
			return 0 !== $cmp ? $cmp : $a['oeuvre_id'] <=> $b['oeuvre_id'];
		}
	);
	$total   = count( $lignes );
	$tranche = array_slice( $lignes, ( $page - 1 ) * $limite, $limite );
	if ( $tranche ) {
		_prime_post_caches( wp_list_pluck( $tranche, 'oeuvre_id' ), false, false );
	}
	foreach ( $tranche as $i => $ligne ) {
		$tranche[ $i ]['url'] = (string) get_permalink( $ligne['oeuvre_id'] );
	}
	return array(
		'tri'     => $tri,
		'total'   => $total,
		'page'    => $page,
		'pages'   => max( 1, (int) ceil( $total / $limite ) ),
		'oeuvres' => array_values( $tranche ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Fiche d'une œuvre
 * -----------------------------------------------------------------------------
 */

/**
 * Tomes publiés d'une œuvre (ordre de lecture) : chapitres publiés et lecteurs dont la position
 * est dans le tome. Trois requêtes.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array{tome_id:int,titre:string,chapitres:int,lecteurs_positionnes:int}>
 */
function tomes_oeuvre_stats( int $oeuvre_id ): array {
	global $wpdb;
	if ( ! function_exists( '\\Yume\\Core\\Reader\\plan_de_lecture' ) ) {
		return array();
	}
	$plan = \Yume\Core\Reader\plan_de_lecture( array( $oeuvre_id ) );
	$plan = (array) ( $plan[ $oeuvre_id ] ?? array() );
	if ( ! $plan ) {
		return array();
	}
	$positionnes = array();
	if ( progression_lisible() ) {
		$table = \Yume\Core\Reader\table_progression();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT tome_id, COUNT(*) AS nb FROM {$table} WHERE oeuvre_id = %d GROUP BY tome_id", $oeuvre_id ), ARRAY_A ) as $ligne ) {
			$positionnes[ (int) $ligne['tome_id'] ] = (int) $ligne['nb'];
		}
	}
	_prime_post_caches( array_map( 'intval', wp_list_pluck( $plan, 'id' ) ), false, false );
	$tomes = array();
	foreach ( $plan as $tome ) {
		$tome_id = (int) $tome['id'];
		$tomes[] = array(
			'tome_id'              => $tome_id,
			'titre'                => trim( wp_strip_all_tags( html_entity_decode( (string) get_post_field( 'post_title', $tome_id ), ENT_QUOTES, 'UTF-8' ) ) ),
			'chapitres'            => count( (array) $tome['chapitres'] ),
			'lecteurs_positionnes' => $positionnes[ $tome_id ] ?? 0,
		);
	}
	return $tomes;
}

/**
 * Fiche statistique d'une œuvre sur une période (en cache 5 minutes). Null si le contenu n'est pas
 * une œuvre (ou est à la corbeille).
 *
 * @param int $oeuvre_id Œuvre.
 * @param int $jours     Période en jours (ramenée à PERIODES).
 * @return array{oeuvre:array,compteurs:array,periode:array{nouveaux_favoris:int,lecteurs_actifs:int,vues:?int,vues_prec:?int},frequences:array{immediat:int,hebdo:int,jamais:int},serie:array,tomes:array,depuis_historique:?string}|null
 */
function fiche_oeuvre( int $oeuvre_id, int $jours ): ?array {
	$post = $oeuvre_id > 0 ? get_post( $oeuvre_id ) : null;
	if ( ! $post instanceof \WP_Post || 'yume_oeuvre' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		return null;
	}
	$jours = periode_valide( $jours );
	return (array) avec_cache_oeuvres(
		'fiche_' . $oeuvre_id . '_' . $jours,
		static function () use ( $oeuvre_id, $jours ): array {
			global $wpdb;
			$bornes = bornes_periode( $jours );
			$actifs = 0;
			if ( progression_lisible() ) {
				$table = \Yume\Core\Reader\table_progression();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$actifs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE oeuvre_id = %d AND updated_at BETWEEN %s AND %s", $oeuvre_id, $bornes['debut_gmt'], $bornes['fin_gmt'] ) );
			}
			$vues  = vues_historique( $bornes, $oeuvre_id )[ $oeuvre_id ] ?? array(
				'vues'      => null,
				'vues_prec' => null,
			);
			$serie = array_map(
				static fn( array $ligne ): array => array(
					'date'         => $ligne['jour'],
					'favoris'      => $ligne['favoris'],
					'suiveurs'     => $ligne['suiveurs'],
					'lecteurs_30j' => $ligne['lecteurs_30j'],
					'vues'         => $ligne['vues'],
				),
				serie( $oeuvre_id, $bornes['debut'], $bornes['fin'] )
			);
			return array(
				'oeuvre'            => identite_oeuvre( $oeuvre_id ),
				'compteurs'         => compteurs_oeuvre( $oeuvre_id ),
				'periode'           => array(
					'nouveaux_favoris' => (int) ( nouveaux_favoris( $bornes['debut_gmt'], $bornes['fin_gmt'], $oeuvre_id )[ $oeuvre_id ] ?? 0 ),
					'lecteurs_actifs'  => $actifs,
					'vues'             => $vues['vues'],
					'vues_prec'        => $vues['vues_prec'],
				),
				'frequences'        => agregats_favoris( $oeuvre_id )['frequences'],
				'serie'             => $serie,
				'tomes'             => tomes_oeuvre_stats( $oeuvre_id ),
				'depuis_historique' => premier_jour_historique( $oeuvre_id ),
			);
		}
	);
}

/*
 * -----------------------------------------------------------------------------
 * Lecteurs du site
 * -----------------------------------------------------------------------------
 */

/**
 * Jointure SQL des comptes lecteurs (rôle subscriber du site) sur {$wpdb->users} u, et ses
 * valeurs à préparer.
 *
 * @return array{0:string,1:array<int,string>}
 */
function jointure_comptes_lecteurs(): array {
	global $wpdb;
	return array(
		"INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s AND cap.meta_value LIKE %s",
		array( $wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like( '"subscriber"' ) . '%' ),
	);
}

/**
 * Comptes lecteurs inscrits au plus tard à $jusqua, et inscriptions de deux intervalles. Une
 * requête.
 *
 * @param string $jusqua     Borne GMT des comptes (BORNE_GMT_MAX : tous).
 * @param string $debut      Début GMT des inscriptions.
 * @param string $fin        Fin GMT des inscriptions.
 * @param string $debut_prec Début GMT des inscriptions de la période précédente.
 * @param string $fin_prec   Fin GMT des inscriptions de la période précédente.
 * @return array{comptes:int,inscriptions:int,inscriptions_prec:int}
 */
function chiffres_comptes( string $jusqua, string $debut, string $fin, string $debut_prec = '', string $fin_prec = '' ): array {
	global $wpdb;
	list( $jointure, $valeurs ) = jointure_comptes_lecteurs();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$ligne = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT SUM(CASE WHEN u.user_registered <= %s THEN 1 ELSE 0 END) AS comptes,
				SUM(CASE WHEN u.user_registered BETWEEN %s AND %s THEN 1 ELSE 0 END) AS inscriptions,
				SUM(CASE WHEN u.user_registered BETWEEN %s AND %s THEN 1 ELSE 0 END) AS inscriptions_prec
			FROM {$wpdb->users} u {$jointure}",
			array_merge( array( $jusqua, $debut, $fin, $debut_prec, $fin_prec ), $valeurs )
		),
		ARRAY_A
	);
	// phpcs:enable
	return array(
		'comptes'           => (int) ( $ligne['comptes'] ?? 0 ),
		'inscriptions'      => (int) ( $ligne['inscriptions'] ?? 0 ),
		'inscriptions_prec' => (int) ( $ligne['inscriptions_prec'] ?? 0 ),
	);
}

/**
 * Inscriptions de comptes lecteurs par jour (heure du site) sur une période, jours sans
 * inscription compris. Une requête agrégée par heure GMT, ventilée ensuite par jour du site
 * (exact pour les fuseaux décalés d'heures entières).
 *
 * @param array $bornes Bornes (bornes_periode()).
 * @return array<int,array{date:string,n:int}>
 */
function serie_inscriptions( array $bornes ): array {
	global $wpdb;
	$serie = array();
	$jour  = new \DateTimeImmutable( $bornes['debut'], wp_timezone() );
	$fin   = new \DateTimeImmutable( $bornes['fin'], wp_timezone() );
	while ( $jour <= $fin ) {
		$serie[ $jour->format( 'Y-m-d' ) ] = 0;
		$jour                              = $jour->modify( '+1 day' );
	}
	list( $jointure, $valeurs ) = jointure_comptes_lecteurs();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT SUBSTR(u.user_registered, 1, 13) AS heure, COUNT(*) AS nb FROM {$wpdb->users} u {$jointure}
			WHERE u.user_registered BETWEEN %s AND %s GROUP BY SUBSTR(u.user_registered, 1, 13)",
			array_merge( $valeurs, array( $bornes['debut_gmt'], $bornes['fin_gmt'] ) )
		),
		ARRAY_A
	);
	// phpcs:enable
	foreach ( $lignes as $ligne ) {
		$ts = strtotime( $ligne['heure'] . ':00:00 UTC' );
		if ( false === $ts ) {
			continue;
		}
		$date = wp_date( 'Y-m-d', $ts );
		if ( isset( $serie[ $date ] ) ) {
			$serie[ $date ] += (int) $ligne['nb'];
		}
	}
	$resultat = array();
	foreach ( $serie as $date => $n ) {
		$resultat[] = array(
			'date' => (string) $date,
			'n'    => $n,
		);
	}
	return $resultat;
}

/**
 * Lecteurs actifs (comptes distincts dont une position a été mise à jour) entre deux dates GMT.
 *
 * @param string $debut Début GMT.
 * @param string $fin   Fin GMT.
 */
function lecteurs_actifs_entre( string $debut, string $fin ): int {
	global $wpdb;
	if ( ! progression_lisible() ) {
		return 0;
	}
	$table = \Yume\Core\Reader\table_progression();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE updated_at BETWEEN %s AND %s", $debut, $fin ) );
}

/**
 * Résumé des lecteurs du site sur une période (en cache 5 minutes) : comptes lecteurs (rôle
 * subscriber), inscriptions et lecteurs actifs comparés à la période précédente, favoris et
 * suiveurs (œuvres publiées), comptes ayant au moins un favori, inscriptions par jour,
 * répartition des fréquences d'alerte.
 *
 * @param int $jours Période en jours (ramenée à PERIODES).
 * @return array{comptes:int,inscriptions:int,inscriptions_prec:int,variation_inscriptions:?float,actifs:int,actifs_prec:int,variation_actifs:?float,favoris_total:int,suiveurs_total:int,lecteurs_avec_favori:int,serie_inscriptions:array,frequences:array{immediat:int,hebdo:int,jamais:int}}
 */
function resume_lecteurs( int $jours ): array {
	$jours = periode_valide( $jours );
	return (array) avec_cache_oeuvres(
		'lecteurs_' . $jours,
		static function () use ( $jours ): array {
			$bornes  = bornes_periode( $jours );
			$comptes = chiffres_comptes( BORNE_GMT_MAX, $bornes['debut_gmt'], $bornes['fin_gmt'], $bornes['debut_prec_gmt'], $bornes['fin_prec_gmt'] );
			$actifs  = lecteurs_actifs_entre( $bornes['debut_gmt'], $bornes['fin_gmt'] );
			$prec    = lecteurs_actifs_entre( $bornes['debut_prec_gmt'], $bornes['fin_prec_gmt'] );
			$favoris = agregats_favoris();
			return array(
				'comptes'                => $comptes['comptes'],
				'inscriptions'           => $comptes['inscriptions'],
				'inscriptions_prec'      => $comptes['inscriptions_prec'],
				'variation_inscriptions' => variation( $comptes['inscriptions'], $comptes['inscriptions_prec'] ),
				'actifs'                 => $actifs,
				'actifs_prec'            => $prec,
				'variation_actifs'       => variation( $actifs, $prec ),
				'favoris_total'          => $favoris['favoris'],
				'suiveurs_total'         => $favoris['suiveurs'],
				'lecteurs_avec_favori'   => $favoris['lecteurs'],
				'serie_inscriptions'     => serie_inscriptions( $bornes ),
				'frequences'             => $favoris['frequences'],
			);
		}
	);
}
