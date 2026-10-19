<?php
/**
 * Statistiques, socle : constantes, capacités, périodes, cache et table d'historique.
 *
 * Ces noms sont des contrats partagés par tous les fichiers du module (trafic, œuvres,
 * historique, lecteurs, export, vue de l'espace équipe) : ne pas les renommer.
 *
 * Périodes : 7, 30, 90 ou 365 jours se terminant aujourd'hui (heure du site, jour courant
 * inclus), comparées à la période de même durée qui la précède immédiatement (bornes_periode()).
 *
 * Cache : transients préfixés yume_stats_ (cache_lire(), cache_ecrire(), cache_vider()). Avec un
 * cache d'objets persistant (WordPress.com), les transients ne sont pas dans la table des options :
 * les clés écrites sont alors mémorisées dans OPTION_CACHE_CLES pour que cache_vider() les retrouve.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Capacité : agrégats et trafic (tous les rôles de l'équipe, administrateurs). */
const CAPACITE_STATS = 'yume_voir_statistiques';

/** Capacité : listes nominatives et export nominatif (gérants, administrateurs). */
const CAPACITE_LECTEURS = 'yume_voir_lecteurs';

/** Périodes proposées, en jours. */
const PERIODES = array( 7, 30, 90, 365 );

/** Période par défaut, en jours. */
const PERIODE_DEFAUT = 30;

/** Tâche planifiée quotidienne : historique de la veille (03:10, heure du site). */
const HOOK_JOUR = 'yume_statistiques_jour';

/** Option : version du schéma de la table d'historique. */
const OPTION_SCHEMA = 'yume_statistiques_schema';

/** Version du schéma de la table d'historique. */
const VERSION_SCHEMA = '1';

/** Option : dernier jour calculé par l'historique (« Y-m-d », heure du site). */
const OPTION_DERNIER_JOUR = 'yume_statistiques_dernier_jour';

/** Option : 100 derniers exports nominatifs (qui, quand, quelle œuvre, combien). */
const OPTION_EXPORTS = 'yume_statistiques_exports';

/** Préfixe des transients du module. */
const TRANSIENT_PREFIXE = 'yume_stats_';

/** Option interne : clés de cache écrites quand un cache d'objets persistant est actif. */
const OPTION_CACHE_CLES = 'yume_statistiques_cache_cles';

/** Rétention de l'historique quotidien, en jours (filtre yume_statistiques_retention). */
const RETENTION_JOURS = 400;

/** Lecteurs par page des listes nominatives. */
const LECTEURS_PAR_PAGE = 50;

/** Plafond du nombre de lecteurs par page des listes nominatives. */
const LECTEURS_PAGE_MAX = 100;

/*
 * -----------------------------------------------------------------------------
 * Table d'historique
 * -----------------------------------------------------------------------------
 */

/**
 * Nom complet de la table d'historique quotidien.
 */
function table_stats_jour(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_stats_jour';
}

/**
 * La table d'historique est-elle installée ?
 */
function table_prete(): bool {
	return VERSION_SCHEMA === get_option( OPTION_SCHEMA );
}

/*
 * -----------------------------------------------------------------------------
 * Temps et périodes
 * -----------------------------------------------------------------------------
 */

/**
 * Horodatage courant. Le filtre permet aux tests de simuler une date donnée.
 */
function maintenant(): int {
	/**
	 * Filtre l'horodatage « maintenant » du module statistiques (tests, simulations).
	 *
	 * @param int $ts Horodatage Unix.
	 */
	return (int) apply_filters( 'yume_statistiques_maintenant', time() );
}

/**
 * Période valide en jours : une valeur de PERIODES, sinon PERIODE_DEFAUT.
 *
 * @param mixed $p Période demandée (entier ou chaîne numérique, par exemple un paramètre GET).
 */
function periode_valide( $p ): int {
	if ( is_int( $p ) ) {
		$jours = $p;
	} elseif ( is_string( $p ) && preg_match( '/^\d{1,4}$/', trim( $p ) ) ) {
		$jours = (int) trim( $p );
	} else {
		return PERIODE_DEFAUT;
	}
	return in_array( $jours, PERIODES, true ) ? $jours : PERIODE_DEFAUT;
}

/**
 * Bornes d'une période de $jours jours se terminant aujourd'hui (heure du site, jour courant
 * inclus) et de la période précédente, de même durée et contiguë.
 *
 * Les bornes GMT sont incluses (« BETWEEN ») et alignées sur les jours de l'heure du site :
 * debut_gmt = 00:00:00 du premier jour, fin_gmt = 23:59:59 du dernier jour, fin_prec_gmt une
 * seconde avant debut_gmt. Les changements d'heure sont pris en compte.
 *
 * @param int $jours      Durée en jours (1 au minimum).
 * @param int $maintenant Horodatage de référence (0 : maintenant()).
 * @return array{debut:string,fin:string,debut_prec:string,fin_prec:string,debut_gmt:string,fin_gmt:string,debut_prec_gmt:string,fin_prec_gmt:string,jours:int}
 */
function bornes_periode( int $jours, int $maintenant = 0 ): array {
	$jours      = max( 1, $jours );
	$maintenant = $maintenant > 0 ? $maintenant : maintenant();
	$fuseau     = wp_timezone();
	$fin        = ( new \DateTimeImmutable( '@' . $maintenant ) )->setTimezone( $fuseau )->setTime( 0, 0, 0 );
	$debut      = $fin->modify( '-' . ( $jours - 1 ) . ' days' );
	$fin_prec   = $debut->modify( '-1 day' );
	$debut_prec = $fin_prec->modify( '-' . ( $jours - 1 ) . ' days' );
	$utc        = new \DateTimeZone( 'UTC' );
	// Début d'un jour (heure du site) en GMT.
	$gmt_debut = static fn( \DateTimeImmutable $jour ): string => $jour->setTime( 0, 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	// Fin d'un jour (heure du site) en GMT : une seconde avant le début du jour suivant.
	$gmt_fin = static fn( \DateTimeImmutable $jour ): string => $jour->modify( '+1 day' )->setTime( 0, 0, 0 )->setTimezone( $utc )->modify( '-1 second' )->format( 'Y-m-d H:i:s' );
	return array(
		'debut'          => $debut->format( 'Y-m-d' ),
		'fin'            => $fin->format( 'Y-m-d' ),
		'debut_prec'     => $debut_prec->format( 'Y-m-d' ),
		'fin_prec'       => $fin_prec->format( 'Y-m-d' ),
		'debut_gmt'      => $gmt_debut( $debut ),
		'fin_gmt'        => $gmt_fin( $fin ),
		'debut_prec_gmt' => $gmt_debut( $debut_prec ),
		'fin_prec_gmt'   => $gmt_fin( $fin_prec ),
		'jours'          => $jours,
	);
}

/**
 * Variation en pourcentage d'une valeur par rapport à la période précédente, arrondie à une
 * décimale (+12,5 % → 12.5). Null si la valeur précédente est nulle (variation non définie).
 *
 * @param int $actuel    Valeur de la période.
 * @param int $precedent Valeur de la période précédente.
 */
function variation( int $actuel, int $precedent ): ?float {
	if ( 0 === $precedent ) {
		return null;
	}
	return round( ( $actuel - $precedent ) / abs( $precedent ) * 100, 1 );
}

/*
 * -----------------------------------------------------------------------------
 * Cache (transients yume_stats_*)
 * -----------------------------------------------------------------------------
 */

/**
 * Nom complet du transient d'une clé (préfixe ajouté s'il manque).
 *
 * @param string $cle Clé (« trafic_<md5> » ou « yume_stats_trafic_<md5> »).
 */
function nom_transient( string $cle ): string {
	return str_starts_with( $cle, TRANSIENT_PREFIXE ) ? $cle : TRANSIENT_PREFIXE . $cle;
}

/**
 * Valeur en cache, ou false si elle est absente ou expirée (comme get_transient()).
 *
 * @param string $cle Clé, avec ou sans le préfixe TRANSIENT_PREFIXE.
 * @return mixed
 */
function cache_lire( string $cle ) {
	return get_transient( nom_transient( $cle ) );
}

/**
 * Met une valeur en cache (ne pas mettre en cache la valeur false : elle signifie « absent »).
 *
 * @param string $cle    Clé, avec ou sans le préfixe TRANSIENT_PREFIXE.
 * @param mixed  $valeur Valeur.
 * @param int    $duree  Durée en secondes.
 */
function cache_ecrire( string $cle, $valeur, int $duree ): void {
	$nom = nom_transient( $cle );
	set_transient( $nom, $valeur, max( 1, $duree ) );
	if ( ! wp_using_ext_object_cache() ) {
		return;
	}
	// Cache d'objets persistant : la clé est mémorisée pour cache_vider() (clés expirées oubliées).
	$cles = get_option( OPTION_CACHE_CLES, array() );
	$cles = is_array( $cles ) ? $cles : array();
	$now  = time();
	$cles = array_filter( $cles, static fn( $fin ): bool => (int) $fin > $now );
	if ( (int) ( $cles[ $nom ] ?? 0 ) < $now + $duree ) {
		$cles[ $nom ] = $now + max( 1, $duree );
		update_option( OPTION_CACHE_CLES, $cles, false );
	}
}

/**
 * Vide tout le cache du module : transients yume_stats_* de la table des options et clés
 * mémorisées (cache d'objets persistant).
 */
function cache_vider(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$options = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . TRANSIENT_PREFIXE ) . '%'
		)
	);
	$noms    = array_map( static fn( $option ): string => substr( (string) $option, strlen( '_transient_' ) ), $options );
	$cles    = get_option( OPTION_CACHE_CLES, array() );
	if ( is_array( $cles ) && $cles ) {
		$noms = array_merge( $noms, array_map( 'strval', array_keys( $cles ) ) );
		delete_option( OPTION_CACHE_CLES );
	}
	foreach ( array_unique( $noms ) as $nom ) {
		delete_transient( $nom );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Œuvres
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvres publiées : ID => titre brut (sans balises ni entités), triées par titre. Une requête,
 * mémorisée pour la requête HTTP tant qu'aucun contenu n'est modifié (last_changed des posts).
 *
 * @return array<int,string>
 */
function oeuvres_publiees(): array {
	static $memo = array();
	$version     = (string) wp_cache_get_last_changed( 'posts' );
	if ( isset( $memo[ $version ] ) ) {
		return $memo[ $version ];
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes   = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY post_title ASC, ID ASC",
			'yume_oeuvre',
			'publish'
		)
	);
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		$resultat[ (int) $ligne->ID ] = trim( wp_strip_all_tags( html_entity_decode( (string) $ligne->post_title, ENT_QUOTES, 'UTF-8' ) ) );
	}
	$memo = array( $version => $resultat );
	return $resultat;
}

/**
 * Œuvre d'un contenu (œuvre, tome, chapitre, article lié), ou 0.
 *
 * @param int $post_id Contenu.
 */
function oeuvre_de( int $post_id ): int {
	if ( $post_id <= 0 || ! function_exists( 'yume_get_oeuvre_id' ) ) {
		return 0;
	}
	return (int) yume_get_oeuvre_id( $post_id );
}
