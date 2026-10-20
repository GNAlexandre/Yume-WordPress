<?php
/**
 * Statistiques, listes nominatives : favoris et suiveurs alertés d'une œuvre, réservés à la
 * capacité yume_voir_lecteurs (gérants et administrateurs), et journal des exports nominatifs.
 *
 * Minimisation (RGPD) : chaque lecteur est décrit par exactement quatre champs, pseudo
 * (display_name), depuis (date d'ajout du favori, ISO 8601 UTC), frequence (immediat, hebdo ou
 * jamais) et equipe (badge « Équipe »). Ni e-mail, ni colonne user_login, ni adresse IP, ni
 * avatar (un Gravatar est une empreinte de l'e-mail). La recherche porte sur le pseudo seulement.
 * Depuis 2.5.0, l'identifiant de connexion d'un nouveau compte est généré (yn-xxxxxxxx) et
 * distinct du pseudo ; pour un compte créé avant, display_name vaut l'identifiant tant que le
 * lecteur n'a pas changé de pseudo dans Mon compte. Dans les deux cas, c'est le nom déjà affiché
 * publiquement sous ses commentaires.
 * La jointure interne sur la table des utilisateurs écarte les lignes d'un compte supprimé. Les
 * listes de lecture ne sont jamais nominatives (agrégats seulement, oeuvres.php).
 *
 * Journal : les 100 derniers exports nominatifs (qui, quand, quelle œuvre, quelle liste, combien
 * de lignes) dans l'option OPTION_EXPORTS, non chargée automatiquement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Nombre maximal d'entrées du journal des exports nominatifs. */
const EXPORTS_MAX = 100;

/** Longueur minimale (en caractères) d'une recherche sur le pseudo. */
const RECHERCHE_MIN = 2;

/** Longueur maximale (en caractères) d'une recherche sur le pseudo. */
const RECHERCHE_MAX = 60;

/*
 * -----------------------------------------------------------------------------
 * Droits
 * -----------------------------------------------------------------------------
 */

/**
 * Le compte peut-il consulter les listes nominatives (capacité yume_voir_lecteurs) ?
 *
 * @param int $user_id Utilisateur (0 : utilisateur courant).
 */
function peut_voir_lecteurs( int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	return $user_id > 0 && user_can( $user_id, CAPACITE_LECTEURS );
}

/*
 * -----------------------------------------------------------------------------
 * Listes nominatives
 * -----------------------------------------------------------------------------
 */

/**
 * Arguments normalisés d'une liste nominative.
 *
 * Valeurs par défaut : type favoris, aucune recherche, page 1, LECTEURS_PAR_PAGE lecteurs,
 * tri par date. Sans ordre explicite : les plus récents d'abord (tri par date), ordre
 * alphabétique (tri par pseudo).
 *
 * @param array $args Arguments bruts (type, q, page, limite, tri, ordre).
 * @return array{type:string,q:string,page:int,limite:int,tri:string,ordre:string}
 */
function args_lecteurs( array $args ): array {
	$q = is_scalar( $args['q'] ?? null ) ? trim( sanitize_text_field( (string) $args['q'] ) ) : '';
	$q = mb_strlen( $q ) >= RECHERCHE_MIN ? mb_substr( $q, 0, RECHERCHE_MAX ) : '';
	// Une valeur non scalaire (tableau injecté) vaut la valeur par défaut.
	$texte = static fn( string $cle ): string => is_scalar( $args[ $cle ] ?? null ) ? (string) $args[ $cle ] : '';
	$tri   = 'pseudo' === $texte( 'tri' ) ? 'pseudo' : 'date';
	$ordre = $texte( 'ordre' );
	if ( ! in_array( $ordre, array( 'asc', 'desc' ), true ) ) {
		$ordre = 'pseudo' === $tri ? 'asc' : 'desc';
	}
	$limite = is_numeric( $args['limite'] ?? null ) ? (int) $args['limite'] : LECTEURS_PAR_PAGE;
	$page   = is_numeric( $args['page'] ?? null ) ? (int) $args['page'] : 1;
	return array(
		'type'   => 'suiveurs' === $texte( 'type' ) ? 'suiveurs' : 'favoris',
		'q'      => $q,
		'page'   => max( 1, $page ),
		'limite' => min( LECTEURS_PAGE_MAX, max( 1, $limite ) ),
		'tri'    => $tri,
		'ordre'  => $ordre,
	);
}

/**
 * Œuvre d'une liste nominative : { id, titre (brut, sans balises ni entités), url }. Titre et
 * adresse vides si le contenu n'est pas une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{id:int,titre:string,url:string}
 */
function oeuvre_lecteurs( int $oeuvre_id ): array {
	$post = $oeuvre_id > 0 ? get_post( $oeuvre_id ) : null;
	if ( ! $post instanceof \WP_Post || 'yume_oeuvre' !== $post->post_type ) {
		return array(
			'id'    => max( 0, $oeuvre_id ),
			'titre' => '',
			'url'   => '',
		);
	}
	$url = 'publish' === $post->post_status ? get_permalink( $post ) : '';
	return array(
		'id'    => (int) $post->ID,
		'titre' => trim( wp_strip_all_tags( html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) ) ),
		'url'   => is_string( $url ) ? $url : '',
	);
}

/**
 * Le compte fait-il partie de l'équipe (badge « Équipe ») ? Même définition que le module
 * lecteurs (Social\est_equipe()), sinon capacité yume_voir_equipe.
 *
 * @param int $user_id Utilisateur.
 */
function lecteur_equipe( int $user_id ): bool {
	if ( function_exists( '\\Yume\\Core\\Social\\est_equipe' ) ) {
		return \Yume\Core\Social\est_equipe( $user_id );
	}
	return $user_id > 0 && user_can( $user_id, 'yume_voir_equipe' );
}

/**
 * Date GMT « Y-m-d H:i:s » en ISO 8601 UTC (« 2026-10-12T08:30:00+00:00 »), chaîne vide si
 * invalide.
 *
 * @param string $gmt Date GMT.
 */
function depuis_iso( string $gmt ): string {
	if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? '' : gmdate( 'c', $ts );
}

/**
 * Liste nominative des favoris (type favoris) ou des suiveurs alertés (type suiveurs : fréquence
 * autre que « jamais ») d'une œuvre.
 *
 * Seuls le pseudo (display_name), la date d'ajout, la fréquence et l'ID (pour le badge équipe,
 * jamais renvoyé) sont lus ; la jointure interne écarte les comptes supprimés. La recherche q
 * (2 caractères au moins, sinon ignorée) cherche le texte dans le pseudo (LIKE échappé : % et _
 * sont des caractères ordinaires).
 *
 * @param int   $oeuvre_id Œuvre.
 * @param array $args      type 'favoris'|'suiveurs', q, page (≥ 1), limite (1 à 100, 50),
 *                         tri 'date'|'pseudo', ordre 'desc'|'asc'.
 * @return array{oeuvre:array{id:int,titre:string,url:string},type:string,total:int,page:int,pages:int,limite:int,q:string,lecteurs:array<int,array{pseudo:string,depuis:string,frequence:string,equipe:bool}>}
 */
function lecteurs_oeuvre( int $oeuvre_id, array $args ): array {
	global $wpdb;
	$a        = args_lecteurs( $args );
	$oeuvre   = oeuvre_lecteurs( $oeuvre_id );
	$resultat = array(
		'oeuvre'   => $oeuvre,
		'type'     => $a['type'],
		'total'    => 0,
		'page'     => $a['page'],
		'pages'    => 1,
		'limite'   => $a['limite'],
		'q'        => $a['q'],
		'lecteurs' => array(),
	);
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre['id'] ) || ! function_exists( '\\Yume\\Core\\Social\\table_favoris' )
		|| ! function_exists( '\\Yume\\Core\\Social\\tables_pretes' ) || ! \Yume\Core\Social\tables_pretes() ) {
		return $resultat;
	}

	$table   = \Yume\Core\Social\table_favoris();
	$where   = 'f.oeuvre_id = %d';
	$valeurs = array( $oeuvre['id'] );
	if ( 'suiveurs' === $a['type'] ) {
		$where    .= ' AND f.frequence <> %s';
		$valeurs[] = 'jamais';
	}
	if ( '' !== $a['q'] ) {
		$where    .= ' AND u.display_name LIKE %s';
		$valeurs[] = '%' . $wpdb->esc_like( $a['q'] ) . '%';
	}
	$sql_base = "FROM {$table} f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id WHERE {$where}";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql_base : tables et clauses fixes, valeurs préparées.
	$total             = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql_base}", $valeurs ) );
	$resultat['total'] = $total;
	$resultat['pages'] = max( 1, (int) ceil( $total / $a['limite'] ) );
	if ( 0 === $total ) {
		return $resultat;
	}

	// Tri : colonnes et sens pris dans une liste fermée ; l'ID départage les égalités.
	$sens     = 'asc' === $a['ordre'] ? 'ASC' : 'DESC';
	$ordre    = 'pseudo' === $a['tri']
		? "u.display_name {$sens}, f.user_id ASC"
		: "f.created_at {$sens}, f.user_id {$sens}";
	$args_sql = array_merge( $valeurs, array( $a['limite'], ( $a['page'] - 1 ) * $a['limite'] ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- tables et clauses fixes, valeurs préparées.
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT f.user_id, u.display_name, f.created_at, f.frequence {$sql_base} ORDER BY {$ordre} LIMIT %d OFFSET %d", $args_sql ) );

	$ids = array_map( static fn( $l ): int => (int) $l->user_id, $lignes );
	if ( $ids ) {
		cache_users( $ids );
	}
	$frequences = function_exists( '\\Yume\\Core\\Social\\libelles_frequences' ) ? array_keys( \Yume\Core\Social\libelles_frequences() ) : array( 'immediat', 'hebdo', 'jamais' );
	foreach ( $lignes as $l ) {
		$frequence              = (string) $l->frequence;
		$resultat['lecteurs'][] = array(
			'pseudo'    => (string) $l->display_name,
			'depuis'    => depuis_iso( (string) $l->created_at ),
			'frequence' => in_array( $frequence, $frequences, true ) ? $frequence : 'immediat',
			'equipe'    => lecteur_equipe( (int) $l->user_id ),
		);
	}
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Journal des exports nominatifs
 * -----------------------------------------------------------------------------
 */

/**
 * Entrées du journal, les plus récentes d'abord, normalisées.
 *
 * @return array<int,array{quand:int,user_id:int,oeuvre_id:int,type:string,nb:int}>
 */
function journal_exports(): array {
	$journal = get_option( OPTION_EXPORTS, array() );
	$entrees = array();
	foreach ( is_array( $journal ) ? $journal : array() as $e ) {
		if ( ! is_array( $e ) ) {
			continue;
		}
		$entrees[] = array(
			'quand'     => (int) ( $e['quand'] ?? 0 ),
			'user_id'   => (int) ( $e['user_id'] ?? 0 ),
			'oeuvre_id' => (int) ( $e['oeuvre_id'] ?? 0 ),
			'type'      => sanitize_key( (string) ( $e['type'] ?? '' ) ),
			'nb'        => (int) ( $e['nb'] ?? 0 ),
		);
	}
	return array_slice( $entrees, 0, EXPORTS_MAX );
}

/**
 * Journalise un export nominatif : qui, quand (maintenant()), quelle œuvre, quelle liste
 * (favoris ou suiveurs), combien de lignes. Les EXPORTS_MAX entrées les plus récentes sont
 * conservées (option non chargée automatiquement).
 *
 * @param int    $user_id   Auteur de l'export.
 * @param int    $oeuvre_id Œuvre.
 * @param string $type      Liste exportée (favoris ou suiveurs).
 * @param int    $nb        Nombre de lecteurs exportés.
 */
function journaliser_export( int $user_id, int $oeuvre_id, string $type, int $nb ): void {
	$journal = journal_exports();
	array_unshift(
		$journal,
		array(
			'quand'     => maintenant(),
			'user_id'   => $user_id,
			'oeuvre_id' => $oeuvre_id,
			'type'      => sanitize_key( $type ),
			'nb'        => max( 0, $nb ),
		)
	);
	update_option( OPTION_EXPORTS, array_slice( $journal, 0, EXPORTS_MAX ), false );
}

/**
 * Derniers exports nominatifs, les plus récents d'abord : { quand (horodatage), user_id,
 * oeuvre_id, type, nb }.
 *
 * @param int $max Nombre d'entrées (1 à EXPORTS_MAX).
 * @return array<int,array{quand:int,user_id:int,oeuvre_id:int,type:string,nb:int}>
 */
function exports_recents( int $max = 20 ): array {
	return array_slice( journal_exports(), 0, min( EXPORTS_MAX, max( 1, $max ) ) );
}
