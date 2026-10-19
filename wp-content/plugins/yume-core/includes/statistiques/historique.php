<?php
/**
 * Statistiques, historique quotidien : calcul des lignes de la table yume_stats_jour (site et
 * chaque œuvre publiée), rattrapage des jours manquants, purge au-delà de la rétention, séries
 * pour les graphiques et reconstitution approximative des favoris passés.
 *
 * Un jour = une ligne « site » (oeuvre_id 0) et une ligne par œuvre publiée, écrites par
 * $wpdb->replace() (clé primaire jour + oeuvre_id : recalculer un jour le remplace). Aucune donnée
 * personnelle : uniquement des compteurs.
 *
 * Calcul d'un jour passé : favoris, suiveurs et ajouts aux listes sont bornés à la fin du jour
 * (created_at, ajoute_le) ; lecteurs = positions actuelles (la table progression ne garde que la
 * dernière position) ; lecteurs_30j = positions mises à jour dans les 30 jours précédant la fin du
 * jour ou depuis ; comptes et inscriptions = comptes lecteurs (rôle subscriber) existants. Les
 * vues et visiteurs viennent du trafic (trafic_vues_par_oeuvre_jour(), trafic_visites_jour(),
 * trafic.php) s'il est disponible, NULL sinon.
 *
 * tache_quotidienne() est appelée par lancer_tache_jour() (install.php), seul callback de
 * HOOK_JOUR : elle n'est pas accrochée ici.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Colonnes de compteurs de la table d'historique (séries, points). */
const COLONNES_HISTORIQUE = array( 'vues', 'visiteurs', 'favoris', 'suiveurs', 'listes', 'lecteurs', 'lecteurs_30j', 'chapitres_lus', 'comptes', 'inscriptions' );

/** Colonnes pouvant valoir NULL (trafic indisponible). */
const COLONNES_HISTORIQUE_NULLES = array( 'vues', 'visiteurs' );

/** Nombre maximal de jours calculés par la tâche quotidienne (veille comprise). */
const RATTRAPAGE_JOURS = 7;

/** Lignes par requête INSERT de la reconstitution des favoris. */
const LIGNES_PAR_INSERTION = 200;

/*
 * -----------------------------------------------------------------------------
 * Aides
 * -----------------------------------------------------------------------------
 */

/**
 * Jour « Y-m-d » valide, à minuit heure du site ; null sinon.
 *
 * @param string $jour Jour.
 */
function jour_historique( string $jour ): ?\DateTimeImmutable {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $jour ) ) {
		return null;
	}
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $jour, wp_timezone() );
	return $date && $date->format( 'Y-m-d' ) === $jour ? $date : null;
}

/**
 * Aujourd'hui à minuit, heure du site (d'après maintenant()).
 */
function aujourdhui_historique(): \DateTimeImmutable {
	return ( new \DateTimeImmutable( '@' . maintenant() ) )->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );
}

/**
 * Bornes GMT incluses d'un jour du site (00:00:00 – 23:59:59, changements d'heure compris).
 *
 * @param \DateTimeImmutable $jour Jour à minuit, heure du site.
 * @return array{debut:string,fin:string}
 */
function bornes_jour_gmt( \DateTimeImmutable $jour ): array {
	$utc = new \DateTimeZone( 'UTC' );
	return array(
		'debut' => $jour->setTime( 0, 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		'fin'   => $jour->modify( '+1 day' )->setTime( 0, 0, 0 )->setTimezone( $utc )->modify( '-1 second' )->format( 'Y-m-d H:i:s' ),
	);
}

/**
 * Ligne d'historique normalisée : jour « Y-m-d », entiers, NULL conservé pour le trafic.
 *
 * @param array $ligne Ligne brute.
 * @return array<string,mixed>
 */
function normaliser_ligne_historique( array $ligne ): array {
	$resultat = array(
		'jour'      => substr( (string) ( $ligne['jour'] ?? '' ), 0, 10 ),
		'oeuvre_id' => (int) ( $ligne['oeuvre_id'] ?? 0 ),
	);
	foreach ( COLONNES_HISTORIQUE as $colonne ) {
		$valeur               = $ligne[ $colonne ] ?? null;
		$resultat[ $colonne ] = null === $valeur && in_array( $colonne, COLONNES_HISTORIQUE_NULLES, true ) ? null : (int) $valeur;
	}
	return $resultat;
}

/**
 * Entier positif ou null (valeur de trafic).
 *
 * @param mixed $valeur Valeur.
 */
function entier_ou_null( $valeur ): ?int {
	return is_numeric( $valeur ) ? max( 0, (int) $valeur ) : null;
}

/*
 * -----------------------------------------------------------------------------
 * Calcul d'un jour
 * -----------------------------------------------------------------------------
 */

/**
 * Lecteurs du site (comptes distincts ayant une position) et lecteurs actifs depuis une date.
 * Une requête.
 *
 * @param string $actifs_depuis Début GMT de la fenêtre des lecteurs actifs.
 * @return array{lecteurs:int,lecteurs_30j:int}
 */
function lecteurs_site( string $actifs_depuis ): array {
	global $wpdb;
	if ( ! progression_lisible() ) {
		return array(
			'lecteurs'     => 0,
			'lecteurs_30j' => 0,
		);
	}
	$table = \Yume\Core\Reader\table_progression();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) AS lecteurs, COUNT(DISTINCT CASE WHEN updated_at >= %s THEN user_id END) AS actifs FROM {$table}", $actifs_depuis ), ARRAY_A );
	return array(
		'lecteurs'     => (int) ( $ligne['lecteurs'] ?? 0 ),
		'lecteurs_30j' => (int) ( $ligne['actifs'] ?? 0 ),
	);
}

/**
 * Calcule et enregistre (REPLACE) l'historique d'un jour : ligne du site et ligne de chaque œuvre
 * publiée. Requêtes agrégées (compteurs_calcules()), puis une écriture par ligne. Vide le cache.
 *
 * @param string $jour Jour « Y-m-d » (heure du site).
 * @return int Nombre de lignes écrites.
 */
function calculer_jour( string $jour ): int {
	global $wpdb;
	$date = jour_historique( $jour );
	if ( null === $date || ! table_prete() ) {
		return 0;
	}
	$bornes        = bornes_jour_gmt( $date );
	$fin_ts        = (int) strtotime( $bornes['fin'] . ' UTC' );
	$actifs_depuis = gmt_decale( $fin_ts + 1, -JOURS_LECTEURS_ACTIFS );
	$ids           = array_keys( oeuvres_publiees() );
	$compteurs     = compteurs_calcules( $ids, $actifs_depuis, $bornes['fin'] );

	// Trafic (trafic.php) : null si indisponible ce jour-là.
	$vues    = function_exists( __NAMESPACE__ . '\\trafic_vues_par_oeuvre_jour' ) ? trafic_vues_par_oeuvre_jour( $jour ) : null;
	$visites = function_exists( __NAMESPACE__ . '\\trafic_visites_jour' ) ? trafic_visites_jour( $jour ) : null;
	$vues    = is_array( $vues ) ? $vues : null;
	$visites = is_array( $visites ) ? $visites : null;

	$lecteurs = lecteurs_site( $actifs_depuis );
	$comptes  = chiffres_comptes( $bornes['fin'], $bornes['debut'], $bornes['fin'] );
	$site     = array(
		'jour'          => $jour,
		'oeuvre_id'     => 0,
		'vues'          => null === $visites ? null : entier_ou_null( $visites['vues'] ?? null ),
		'visiteurs'     => null === $visites ? null : entier_ou_null( $visites['visiteurs'] ?? null ),
		'favoris'       => 0,
		'suiveurs'      => 0,
		'listes'        => 0,
		'lecteurs'      => $lecteurs['lecteurs'],
		'lecteurs_30j'  => $lecteurs['lecteurs_30j'],
		'chapitres_lus' => 0,
		'comptes'       => $comptes['comptes'],
		'inscriptions'  => $comptes['inscriptions'],
	);
	$lignes   = array();
	foreach ( $ids as $oeuvre_id ) {
		$c                      = $compteurs[ $oeuvre_id ] ?? compteurs_vides();
		$listes                 = array_sum( array_map( 'intval', (array) $c['listes'] ) );
		$lignes[]               = array(
			'jour'          => $jour,
			'oeuvre_id'     => (int) $oeuvre_id,
			'vues'          => null === $vues ? null : (int) ( entier_ou_null( $vues[ $oeuvre_id ] ?? 0 ) ?? 0 ),
			'visiteurs'     => null,
			'favoris'       => (int) $c['favoris'],
			'suiveurs'      => (int) $c['suiveurs'],
			'listes'        => $listes,
			'lecteurs'      => (int) $c['lecteurs'],
			'lecteurs_30j'  => (int) $c['lecteurs_30j'],
			'chapitres_lus' => (int) $c['chapitres_lus'],
			'comptes'       => 0,
			'inscriptions'  => 0,
		);
		$site['favoris']       += (int) $c['favoris'];
		$site['suiveurs']      += (int) $c['suiveurs'];
		$site['listes']        += $listes;
		$site['chapitres_lus'] += (int) $c['chapitres_lus'];
	}
	array_unshift( $lignes, $site );

	$table   = table_stats_jour();
	$formats = array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' );
	$ecrites = 0;
	foreach ( $lignes as $ligne ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false !== $wpdb->replace( $table, $ligne, $formats ) ) {
			++$ecrites;
		}
	}
	cache_vider();
	return $ecrites;
}

/**
 * Supprime les lignes plus anciennes que la rétention (RETENTION_JOURS, filtre
 * yume_statistiques_retention).
 *
 * @return int Lignes supprimées.
 */
function purger_historique(): int {
	global $wpdb;
	if ( ! table_prete() ) {
		return 0;
	}
	/**
	 * Filtre la rétention de l'historique quotidien des statistiques, en jours.
	 *
	 * @param int $jours Rétention (400 par défaut).
	 */
	$retention = max( 1, (int) apply_filters( 'yume_statistiques_retention', RETENTION_JOURS ) );
	$limite    = aujourdhui_historique()->modify( '-' . $retention . ' days' )->format( 'Y-m-d' );
	$table     = table_stats_jour();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE jour < %s", $limite ) );
}

/**
 * Tâche quotidienne (appelée par lancer_tache_jour()) : calcule la veille et les jours manquants
 * depuis OPTION_DERNIER_JOUR (RATTRAPAGE_JOURS au plus, veille comprise), puis purge les lignes
 * au-delà de la rétention.
 */
function tache_quotidienne(): void {
	if ( ! table_prete() ) {
		return;
	}
	$hier    = aujourdhui_historique()->modify( '-1 day' );
	$premier = $hier->modify( '-' . ( RATTRAPAGE_JOURS - 1 ) . ' days' );
	$depart  = $hier;
	$dernier = jour_historique( (string) get_option( OPTION_DERNIER_JOUR, '' ) );
	if ( null !== $dernier ) {
		$depart = max( $premier, $dernier->modify( '+1 day' ) );
	}
	if ( $depart <= $hier ) {
		for ( $jour = $depart; $jour <= $hier; $jour = $jour->modify( '+1 day' ) ) {
			calculer_jour( $jour->format( 'Y-m-d' ) );
		}
		update_option( OPTION_DERNIER_JOUR, $hier->format( 'Y-m-d' ), false );
	}
	purger_historique();
}

/*
 * -----------------------------------------------------------------------------
 * Séries
 * -----------------------------------------------------------------------------
 */

/**
 * Lignes d'historique d'une œuvre (0 : site) entre deux jours inclus, triées par jour ; les
 * jours sans ligne ne sont pas comblés.
 *
 * @param int    $oeuvre_id Œuvre (0 : site).
 * @param string $debut     Premier jour « Y-m-d ».
 * @param string $fin       Dernier jour « Y-m-d ».
 * @return array<int,array<string,mixed>> Lignes (jour, oeuvre_id et colonnes de compteurs).
 */
function serie( int $oeuvre_id, string $debut, string $fin ): array {
	global $wpdb;
	if ( ! table_prete() || null === jour_historique( $debut ) || null === jour_historique( $fin ) ) {
		return array();
	}
	$table = table_stats_jour();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE oeuvre_id = %d AND jour BETWEEN %s AND %s ORDER BY jour ASC", max( 0, $oeuvre_id ), $debut, $fin ), ARRAY_A );
	return array_map( __NAMESPACE__ . '\\normaliser_ligne_historique', $lignes );
}

/**
 * Points d'une colonne de l'historique (graphiques) : [{date, valeur}], valeur null si le trafic
 * était indisponible. Colonne inconnue : aucun point.
 *
 * @param int    $oeuvre_id Œuvre (0 : site).
 * @param string $debut     Premier jour « Y-m-d ».
 * @param string $fin       Dernier jour « Y-m-d ».
 * @param string $colonne   Colonne (COLONNES_HISTORIQUE).
 * @return array<int,array{date:string,valeur:?int}>
 */
function points( int $oeuvre_id, string $debut, string $fin, string $colonne ): array {
	if ( ! in_array( $colonne, COLONNES_HISTORIQUE, true ) ) {
		return array();
	}
	return array_map(
		static fn( array $ligne ): array => array(
			'date'   => $ligne['jour'],
			'valeur' => $ligne[ $colonne ],
		),
		serie( $oeuvre_id, $debut, $fin )
	);
}

/**
 * Premier jour d'historique d'une œuvre (0 : site), ou null s'il n'y en a pas.
 *
 * @param int $oeuvre_id Œuvre (0 : site).
 */
function premier_jour_historique( int $oeuvre_id ): ?string {
	global $wpdb;
	if ( ! table_prete() ) {
		return null;
	}
	$table = table_stats_jour();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$jour = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(jour) FROM {$table} WHERE oeuvre_id = %d", max( 0, $oeuvre_id ) ) );
	return null === $jour || '' === (string) $jour ? null : substr( (string) $jour, 0, 10 );
}

/*
 * -----------------------------------------------------------------------------
 * Reconstitution
 * -----------------------------------------------------------------------------
 */

/**
 * Reconstitue approximativement les favoris des $jours derniers jours (jusqu'à la veille) à
 * partir de la date d'ajout des favoris actuels : les favoris retirés depuis ne sont pas datés et
 * manquent donc (estimation à présenter comme telle). Seulement si la table est vide ; seule la
 * colonne favoris est remplie (œuvres publiées et ligne du site). Une requête agrégée par heure
 * GMT, puis des INSERT de LIGNES_PAR_INSERTION lignes.
 *
 * @param int $jours Nombre de jours (1 à RETENTION_JOURS).
 * @return int Lignes écrites (0 si la table n'était pas vide).
 */
function reconstituer_favoris( int $jours = 90 ): int {
	global $wpdb;
	if ( ! table_prete() || ! favoris_lisibles() ) {
		return 0;
	}
	$table = table_stats_jour();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
		return 0;
	}
	$ids = array_keys( oeuvres_publiees() );
	if ( ! $ids ) {
		return 0;
	}
	$jours   = min( RETENTION_JOURS, max( 1, $jours ) );
	$hier    = aujourdhui_historique()->modify( '-1 day' );
	$premier = $hier->modify( '-' . ( $jours - 1 ) . ' days' );
	$debut   = $premier->format( 'Y-m-d' );
	$fin     = $hier->format( 'Y-m-d' );

	// Ajouts par œuvre et par jour du site ; ceux d'avant le premier jour forment le départ.
	$favoris = \Yume\Core\Social\table_favoris();
	$marques = marques_ids( $ids );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.oeuvre_id, SUBSTR(f.created_at, 1, 13) AS heure, COUNT(*) AS nb
			FROM {$favoris} f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id
			WHERE f.oeuvre_id IN ($marques) GROUP BY f.oeuvre_id, SUBSTR(f.created_at, 1, 13)",
			$ids
		),
		ARRAY_A
	);
	// phpcs:enable
	$depart = array_fill_keys( $ids, 0 );
	$ajouts = array();
	foreach ( $lignes as $ligne ) {
		$ts = strtotime( $ligne['heure'] . ':00:00 UTC' );
		if ( false === $ts ) {
			continue;
		}
		$date      = wp_date( 'Y-m-d', $ts );
		$oeuvre_id = (int) $ligne['oeuvre_id'];
		if ( $date < $debut ) {
			$depart[ $oeuvre_id ] += (int) $ligne['nb'];
		} elseif ( $date <= $fin ) {
			$ajouts[ $date ][ $oeuvre_id ] = ( $ajouts[ $date ][ $oeuvre_id ] ?? 0 ) + (int) $ligne['nb'];
		}
	}

	// Lignes cumulées, jour par jour.
	$valeurs = array();
	$cumul   = $depart;
	for ( $jour = $premier; $jour <= $hier; $jour = $jour->modify( '+1 day' ) ) {
		$date = $jour->format( 'Y-m-d' );
		foreach ( (array) ( $ajouts[ $date ] ?? array() ) as $oeuvre_id => $nb ) {
			$cumul[ $oeuvre_id ] += $nb;
		}
		$valeurs[] = array( $date, 0, array_sum( $cumul ) );
		foreach ( $cumul as $oeuvre_id => $nb ) {
			$valeurs[] = array( $date, (int) $oeuvre_id, $nb );
		}
	}

	$ecrites = 0;
	foreach ( array_chunk( $valeurs, LIGNES_PAR_INSERTION ) as $paquet ) {
		$sql = "INSERT INTO {$table} (jour, oeuvre_id, favoris) VALUES " . implode( ', ', array_fill( 0, count( $paquet ), '(%s, %d, %d)' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$n        = $wpdb->query( $wpdb->prepare( $sql, array_merge( ...$paquet ) ) );
		$ecrites += false === $n ? 0 : (int) $n;
	}
	cache_vider();
	return $ecrites;
}
