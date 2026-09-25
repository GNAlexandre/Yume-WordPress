<?php
/**
 * Données de la bibliothèque et cache.
 *
 * Les listes coûteuses (index des œuvres publiées, dernières sorties, statistiques des tomes
 * d'une œuvre) sont mises en cache dans des transients (cache objet persistant s'il existe).
 * Leur nom contient une « version » (option yume_bibliotheque_cache) renouvelée à chaque
 * enregistrement, changement de statut ou suppression d'une œuvre, d'un tome ou d'un chapitre,
 * et à chaque changement de leurs métadonnées de rattachement ou de leurs termes : les anciens
 * transients ne sont plus lus et expirent d'eux-mêmes.
 *
 * Seuls des identifiants et des nombres sont mis en cache, jamais du HTML (le rendu dépend de
 * l'utilisateur et de la date : badge « Nouveau »).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/** Option contenant la version courante du cache. */
const OPTION_VERSION_CACHE = 'yume_bibliotheque_cache';

/** Préfixe des transients du module. */
const PREFIXE_CACHE = 'yume_bib_';

/** Durée de vie des transients (filet de sécurité : l'invalidation est explicite). */
const DUREE_CACHE = 12 * HOUR_IN_SECONDS;

/*
 * -----------------------------------------------------------------------------
 * Cache versionné
 * -----------------------------------------------------------------------------
 */

/**
 * État du cache pour la requête en cours.
 *
 * @return array{renouvele:bool,lu:bool,en_attente:bool,memoire:array}
 */
function &etat_cache(): array {
	static $etat = array(
		'renouvele'  => false,
		'lu'         => false,
		'en_attente' => false,
		'memoire'    => array(),
	);
	return $etat;
}

/**
 * Version courante du cache (créée au premier appel).
 */
function version_cache(): string {
	$version = get_option( OPTION_VERSION_CACHE );
	if ( ! is_string( $version ) || '' === $version ) {
		$version = '1';
		add_option( OPTION_VERSION_CACHE, $version, '', true );
	}
	return $version;
}

/**
 * Nom du transient d'une donnée.
 *
 * @param string $nom  Nom de la donnée.
 * @param array  $args Arguments qui la distinguent.
 */
function cle_cache( string $nom, array $args = array() ): string {
	return PREFIXE_CACHE . $nom . '_' . substr( md5( wp_json_encode( $args ) . '|' . version_cache() ), 0, 16 );
}

/**
 * Lit une donnée en cache, ou la calcule et l'enregistre.
 *
 * @param string   $nom    Nom de la donnée.
 * @param array    $args   Arguments.
 * @param callable $calcul Fonction de calcul (renvoie un tableau).
 * @return array
 */
function en_cache( string $nom, array $args, callable $calcul ): array {
	$etat       = &etat_cache();
	$etat['lu'] = true;
	$cle        = cle_cache( $nom, $args );
	if ( isset( $etat['memoire'][ $cle ] ) ) {
		return $etat['memoire'][ $cle ];
	}
	/**
	 * Désactive le cache du module (développement, diagnostic).
	 *
	 * @param bool $actif Cache actif.
	 */
	$actif  = (bool) apply_filters( 'yume_bibliotheque_cache_actif', true );
	$valeur = $actif ? get_transient( $cle ) : false;
	if ( ! is_array( $valeur ) ) {
		$valeur = (array) $calcul();
		if ( $actif ) {
			set_transient( $cle, $valeur, DUREE_CACHE );
		}
	}
	$etat['memoire'][ $cle ] = $valeur;
	return $valeur;
}

/**
 * Invalide le cache : nouvelle version, mémoire de la requête vidée.
 *
 * Pendant un traitement en masse (migration, publication d'un tome et de ses chapitres),
 * la version n'est renouvelée qu'une fois tant qu'aucune donnée n'a été relue entre-temps ;
 * un dernier renouvellement a lieu en fin de requête si des changements ont suivi.
 */
function invalider(): void {
	$etat            = &etat_cache();
	$etat['memoire'] = array();
	if ( $etat['renouvele'] && ! $etat['lu'] ) {
		$etat['en_attente'] = true;
		return;
	}
	renouveler_version();
}

/**
 * Écrit une nouvelle version du cache.
 */
function renouveler_version(): void {
	$etat               = &etat_cache();
	$etat['renouvele']  = true;
	$etat['lu']         = false;
	$etat['en_attente'] = false;
	$etat['memoire']    = array();
	$version            = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 );
	if ( false === get_option( OPTION_VERSION_CACHE ) ) {
		add_option( OPTION_VERSION_CACHE, $version, '', true );
	} else {
		update_option( OPTION_VERSION_CACHE, $version, true );
	}
}

/**
 * Fin de requête : renouvellement différé (changements survenus après le dernier).
 */
function terminer_cache(): void {
	$etat = &etat_cache();
	if ( $etat['en_attente'] ) {
		renouveler_version();
	}
}
add_action( 'shutdown', __NAMESPACE__ . '\\terminer_cache' );

/**
 * Le contenu est-il un contenu de la bibliothèque ?
 *
 * @param int|\WP_Post $post Contenu.
 */
function est_contenu_yume( $post ): bool {
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post );
	return in_array( $type, array( TYPE_OEUVRE, TYPE_TOME, TYPE_CHAPITRE ), true );
}

/**
 * Enregistrement d'un contenu (métadonnées comprises, y compris en REST).
 *
 * @param int      $post_id ID.
 * @param \WP_Post $post    Contenu.
 */
function invalider_apres_enregistrement( $post_id, $post = null ): void {
	if ( est_contenu_yume( $post instanceof \WP_Post ? $post : (int) $post_id ) ) {
		invalider();
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\invalider_apres_enregistrement', 20, 2 );
add_action( 'deleted_post', __NAMESPACE__ . '\\invalider_apres_enregistrement', 20, 2 );

/**
 * Changement de statut (publication, programmation, corbeille…).
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function invalider_transition( $nouveau, $ancien, $post ): void {
	if ( $nouveau !== $ancien && $post instanceof \WP_Post && est_contenu_yume( $post ) ) {
		invalider();
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\invalider_transition', 20, 3 );

/**
 * Métadonnées qui changent les listes (rattachements, numéros, natures, caches de core).
 *
 * @param int|int[] $meta_id   ID(s) de la métadonnée.
 * @param int       $object_id ID du contenu.
 * @param string    $meta_key  Clé.
 */
function invalider_meta( $meta_id, $object_id, $meta_key ): void {
	$cles = array( 'yume_oeuvre_id', 'yume_tome_id', 'yume_numero', 'yume_nature', 'yume_derniere_sortie', 'yume_nb_chapitres', 'yume_nb_mots', 'yume_temps_lecture', 'yume_etape' );
	if ( in_array( $meta_key, $cles, true ) && est_contenu_yume( (int) $object_id ) ) {
		invalider();
	}
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\invalider_meta', 20, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\invalider_meta', 20, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\invalider_meta', 20, 3 );

/**
 * Termes d'une œuvre modifiés (type, statut, genres).
 *
 * @param int    $object_id ID du contenu.
 * @param array  $terms     Termes.
 * @param array  $tt_ids    Identifiants terme-taxonomie.
 * @param string $taxonomy  Taxonomie.
 */
function invalider_termes_objet( $object_id, $terms, $tt_ids, $taxonomy ): void {
	if ( in_array( $taxonomy, array( TAX_TYPE, TAX_STATUT, TAX_GENRE ), true ) ) {
		invalider();
	}
}
add_action( 'set_object_terms', __NAMESPACE__ . '\\invalider_termes_objet', 20, 4 );

/**
 * Terme créé, modifié ou supprimé dans une taxonomie des œuvres.
 *
 * @param int    $term_id  ID du terme.
 * @param int    $tt_id    ID terme-taxonomie.
 * @param string $taxonomy Taxonomie.
 */
function invalider_terme( $term_id, $tt_id, $taxonomy ): void {
	if ( in_array( $taxonomy, array( TAX_TYPE, TAX_STATUT, TAX_GENRE ), true ) ) {
		invalider();
	}
}
add_action( 'created_term', __NAMESPACE__ . '\\invalider_terme', 20, 3 );
add_action( 'edited_term', __NAMESPACE__ . '\\invalider_terme', 20, 3 );
add_action( 'delete_term', __NAMESPACE__ . '\\invalider_terme', 20, 3 );

/*
 * -----------------------------------------------------------------------------
 * Index des œuvres publiées (menu, grille, filtres)
 * -----------------------------------------------------------------------------
 */

/**
 * Clé de tri alphabétique d'un titre (sans accents ni ponctuation initiale).
 *
 * @param string $titre Titre.
 */
function cle_tri( string $titre ): string {
	$cle = remove_accents( mb_strtolower( $titre ) );
	$cle = preg_replace( '/^[^\p{L}\p{N}]+/u', '', $cle );
	return is_string( $cle ) ? $cle : '';
}

/**
 * Index des œuvres publiées : types, statuts, genres, date de dernière sortie, nombre de
 * tomes publiés par nature ; et noms des termes utilisés.
 *
 * @return array{oeuvres:array<int,array>,termes:array<string,array<string,string>>}
 */
function index_oeuvres(): array {
	return en_cache( 'index', array(), __NAMESPACE__ . '\\calculer_index_oeuvres' );
}

/**
 * Calcule l'index des œuvres publiées (sans cache).
 *
 * @return array{oeuvres:array<int,array>,termes:array<string,array<string,string>>}
 */
function calculer_index_oeuvres(): array {
	global $wpdb;
	$index = array(
		'oeuvres' => array(),
		'termes'  => array(
			TAX_TYPE   => array(),
			TAX_STATUT => array(),
			TAX_GENRE  => array(),
		),
	);
	$ids   = get_posts(
		array(
			'post_type'              => TYPE_OEUVRE,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$ids   = array_map( 'intval', $ids );
	if ( ! $ids ) {
		return $index;
	}
	_prime_post_caches( $ids, false, true );

	foreach ( $ids as $id ) {
		$post                    = get_post( $id );
		$titre                   = titre( $id );
		$sortie                  = (string) get_post_meta( $id, 'yume_derniere_sortie', true );
		$ts                      = '' !== $sortie ? strtotime( $sortie . ' UTC' ) : false;
		$ts                      = false !== $ts && $ts > 0 ? $ts : horodatage( $post );
		$index['oeuvres'][ $id ] = array(
			'id'      => $id,
			'titre'   => $titre,
			'tri'     => cle_tri( $titre ),
			'types'   => array(),
			'statuts' => array(),
			'genres'  => array(),
			'date'    => (int) $ts,
			'tomes'   => array(),
		);
	}

	$termes = wp_get_object_terms(
		$ids,
		array( TAX_TYPE, TAX_STATUT, TAX_GENRE ),
		array(
			'fields'                 => 'all_with_object_id',
			'update_term_meta_cache' => false,
		)
	);
	if ( ! is_wp_error( $termes ) ) {
		$champs = array(
			TAX_TYPE   => 'types',
			TAX_STATUT => 'statuts',
			TAX_GENRE  => 'genres',
		);
		foreach ( $termes as $terme ) {
			$objet = (int) $terme->object_id;
			if ( ! isset( $index['oeuvres'][ $objet ], $champs[ $terme->taxonomy ] ) ) {
				continue;
			}
			$index['oeuvres'][ $objet ][ $champs[ $terme->taxonomy ] ][]  = (string) $terme->slug;
			$index['termes'][ $terme->taxonomy ][ (string) $terme->slug ] = html_entity_decode( (string) $terme->name, ENT_QUOTES, 'UTF-8' );
		}
	}

	// Tomes publiés par œuvre et par nature (une seule requête, MySQL et SQLite).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.meta_value AS oeuvre, n.meta_value AS nature, COUNT(DISTINCT p.ID) AS nombre FROM {$wpdb->posts} p"
			. " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_oeuvre_id'"
			. " LEFT JOIN {$wpdb->postmeta} n ON n.post_id = p.ID AND n.meta_key = 'yume_nature'"
			. " WHERE p.post_type = %s AND p.post_status = 'publish' GROUP BY m.meta_value, n.meta_value",
			TYPE_TOME
		),
		ARRAY_A
	);
	foreach ( (array) $lignes as $ligne ) {
		$oeuvre = (int) $ligne['oeuvre'];
		if ( isset( $index['oeuvres'][ $oeuvre ] ) ) {
			$nature = '' !== (string) $ligne['nature'] ? (string) $ligne['nature'] : 'tome';
			$index['oeuvres'][ $oeuvre ]['tomes'][ $nature ] = ( $index['oeuvres'][ $oeuvre ]['tomes'][ $nature ] ?? 0 ) + (int) $ligne['nombre'];
		}
	}

	foreach ( $index['termes'] as $taxonomie => $noms ) {
		ksort( $noms );
		$index['termes'][ $taxonomie ] = $noms;
	}
	return $index;
}

/**
 * Filtres de la bibliothèque normalisés.
 *
 * @param array $brut Paramètres (type, statut, genre, tri) : chaînes non assainies.
 * @return array{type:string,statuts:string[],genre:string,tri:string}
 */
function normaliser_filtres( array $brut ): array {
	$type    = isset( $brut['type'] ) && is_string( $brut['type'] ) ? sanitize_title( wp_unslash( $brut['type'] ) ) : '';
	$genre   = isset( $brut['genre'] ) && is_string( $brut['genre'] ) ? sanitize_title( wp_unslash( $brut['genre'] ) ) : '';
	$statuts = array();
	if ( isset( $brut['statut'] ) && is_string( $brut['statut'] ) ) {
		$statuts = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $brut['statut'] ) ) ) ) ) ), 0, 10 );
		sort( $statuts );
	}
	$tri = isset( $brut['tri'] ) && is_string( $brut['tri'] ) && 'az' === sanitize_key( $brut['tri'] ) ? 'az' : 'recent';
	return array(
		'type'    => $type,
		'statuts' => $statuts,
		'genre'   => $genre,
		'tri'     => $tri,
	);
}

/**
 * Œuvres de l'index qui correspondent aux filtres.
 *
 * @param array<int,array> $oeuvres Œuvres de l'index.
 * @param array            $filtres Filtres normalisés.
 * @param string           $sauf    Filtre ignoré ('type', 'statut', 'genre') pour les compteurs.
 * @return array<int,array>
 */
function filtrer_oeuvres( array $oeuvres, array $filtres, string $sauf = '' ): array {
	return array_filter(
		$oeuvres,
		static function ( array $oeuvre ) use ( $filtres, $sauf ): bool {
			if ( 'type' !== $sauf && '' !== $filtres['type'] && ! in_array( $filtres['type'], $oeuvre['types'], true ) ) {
				return false;
			}
			if ( 'statut' !== $sauf && $filtres['statuts'] && ! array_intersect( $filtres['statuts'], $oeuvre['statuts'] ) ) {
				return false;
			}
			if ( 'genre' !== $sauf && '' !== $filtres['genre'] && ! in_array( $filtres['genre'], $oeuvre['genres'], true ) ) {
				return false;
			}
			return true;
		}
	);
}

/**
 * Trie des œuvres de l'index.
 *
 * @param array<int,array> $oeuvres Œuvres.
 * @param string           $tri     'recent' (dernière sortie d'abord) ou 'az'.
 * @return array<int,array>
 */
function trier_oeuvres( array $oeuvres, string $tri ): array {
	uasort(
		$oeuvres,
		static function ( array $a, array $b ) use ( $tri ): int {
			if ( 'recent' === $tri && $a['date'] !== $b['date'] ) {
				return $b['date'] <=> $a['date'];
			}
			$cmp = strnatcasecmp( $a['tri'], $b['tri'] );
			return 0 !== $cmp ? $cmp : ( $a['id'] <=> $b['id'] );
		}
	);
	return $oeuvres;
}

/**
 * Nombre d'œuvres (publiées) par valeur d'un groupe de filtres, les autres filtres appliqués.
 *
 * @param array<int,array> $oeuvres Œuvres de l'index.
 * @param array            $filtres Filtres normalisés.
 * @param string           $groupe  'type', 'statut' ou 'genre'.
 * @return array<string,int> slug => nombre.
 */
function compter_par( array $oeuvres, array $filtres, string $groupe ): array {
	$champ   = array(
		'type'   => 'types',
		'statut' => 'statuts',
		'genre'  => 'genres',
	)[ $groupe ] ?? 'types';
	$comptes = array();
	foreach ( filtrer_oeuvres( $oeuvres, $filtres, $groupe ) as $oeuvre ) {
		foreach ( array_unique( $oeuvre[ $champ ] ) as $slug ) {
			$comptes[ $slug ] = ( $comptes[ $slug ] ?? 0 ) + 1;
		}
	}
	return $comptes;
}

/**
 * Nombre d'œuvres (distinctes) ayant au moins un des statuts donnés, autres filtres appliqués.
 *
 * @param array<int,array> $oeuvres Œuvres de l'index.
 * @param array            $filtres Filtres normalisés.
 * @param string[]         $statuts Statuts du groupe.
 */
function compter_groupe_statuts( array $oeuvres, array $filtres, array $statuts ): int {
	$nombre = 0;
	foreach ( filtrer_oeuvres( $oeuvres, $filtres, 'statut' ) as $oeuvre ) {
		if ( array_intersect( $statuts, $oeuvre['statuts'] ) ) {
			++$nombre;
		}
	}
	return $nombre;
}

/*
 * -----------------------------------------------------------------------------
 * Tomes et chapitres : statistiques
 * -----------------------------------------------------------------------------
 */

/**
 * Le tome sort-il chapitre par chapitre (arc, recueil de chapitres, ou œuvre web novel) ?
 *
 * @param int $tome_id ID du tome.
 */
function sortie_progressive( int $tome_id ): bool {
	$nature = (string) get_post_meta( $tome_id, 'yume_nature', true );
	if ( in_array( $nature, array( 'arc', 'chapitres' ), true ) ) {
		return true;
	}
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
	return $oeuvre_id > 0 && has_term( 'web-novel', TAX_TYPE, $oeuvre_id );
}

/**
 * Statistiques d'un tome, calculées sans cache.
 *
 * @param int $tome_id ID du tome.
 * @return array{chapitres:int,speciaux:array<string,int>,publies:int,mots:int,minutes:int,premier:int,dernier:int,dernier_ts:int,a_venir:int,en_cours:bool}
 */
function calculer_stats_tome( int $tome_id ): array {
	$stats = array(
		'chapitres'  => 0,
		'speciaux'   => array(),
		'publies'    => 0,
		'mots'       => 0,
		'minutes'    => 0,
		'premier'    => 0,
		'dernier'    => 0,
		'dernier_ts' => 0,
		'a_venir'    => 0,
		'en_cours'   => false,
	);
	if ( $tome_id <= 0 || ! function_exists( 'yume_get_chapitres' ) ) {
		return $stats;
	}
	$chapitres = yume_get_chapitres( $tome_id, array( 'status' => array( 'publish', 'future', 'draft', 'pending' ) ) );
	foreach ( $chapitres as $chapitre ) {
		if ( 'publish' !== $chapitre->post_status ) {
			++$stats['a_venir'];
			continue;
		}
		$id     = (int) $chapitre->ID;
		$nature = (string) get_post_meta( $id, 'yume_nature', true );
		++$stats['publies'];
		if ( '' === $nature || 'chapitre' === $nature ) {
			++$stats['chapitres'];
		} else {
			$stats['speciaux'][ $nature ] = ( $stats['speciaux'][ $nature ] ?? 0 ) + 1;
		}
		$stats['mots']    += max( 0, (int) get_post_meta( $id, 'yume_nb_mots', true ) );
		$stats['minutes'] += max( 0, (int) get_post_meta( $id, 'yume_temps_lecture', true ) );
		if ( ! $stats['premier'] ) {
			$stats['premier'] = $id;
		}
		$stats['dernier']    = $id;
		$stats['dernier_ts'] = max( $stats['dernier_ts'], horodatage( $chapitre ) );
	}
	$etape             = (string) get_post_meta( $tome_id, 'yume_etape', true );
	$stats['en_cours'] = sortie_progressive( $tome_id ) && ( $stats['a_venir'] > 0 || ( '' !== $etape && 'publie' !== $etape ) );
	return $stats;
}

/**
 * Statistiques des tomes publiés d'une œuvre (en cache), indexées par ID de tome.
 *
 * @param int $oeuvre_id ID de l'œuvre.
 * @return array<int,array>
 */
function stats_oeuvre( int $oeuvre_id ): array {
	if ( $oeuvre_id <= 0 ) {
		return array();
	}
	return en_cache(
		'stats',
		array( $oeuvre_id ),
		static function () use ( $oeuvre_id ): array {
			$stats = array();
			if ( ! function_exists( 'yume_get_tomes' ) ) {
				return $stats;
			}
			foreach ( yume_get_tomes( $oeuvre_id ) as $tome ) {
				$stats[ (int) $tome->ID ] = calculer_stats_tome( (int) $tome->ID );
			}
			return $stats;
		}
	);
}

/**
 * Statistiques d'un tome : depuis le cache de son œuvre s'il est publié, sinon calculées.
 *
 * @param int $tome_id ID du tome.
 * @return array
 */
function stats_tome( int $tome_id ): array {
	if ( 'publish' === get_post_status( $tome_id ) ) {
		$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
		$stats     = $oeuvre_id && 'publish' === get_post_status( $oeuvre_id ) ? stats_oeuvre( $oeuvre_id ) : array();
		if ( isset( $stats[ $tome_id ] ) ) {
			return $stats[ $tome_id ];
		}
	}
	return calculer_stats_tome( $tome_id );
}

/*
 * -----------------------------------------------------------------------------
 * Dernières sorties
 * -----------------------------------------------------------------------------
 */

/**
 * Dernières sorties : une entrée par tome (le plus récent d'abord). La date d'un tome publié
 * d'un coup est celle du tome ; celle d'un arc ou d'un tome de web novel publié chapitre par
 * chapitre est celle de son dernier chapitre publié.
 *
 * @param int $nombre Nombre d'entrées.
 * @return array<int,array{tome:int,ts:int,oeuvre:int,stats:array}>
 */
function dernieres_sorties( int $nombre ): array {
	$nombre = max( 1, min( 48, $nombre ) );
	return en_cache( 'sorties', array( $nombre ), static fn(): array => calculer_dernieres_sorties( $nombre ) );
}

/**
 * Calcule les dernières sorties (sans cache).
 *
 * @param int $nombre Nombre d'entrées.
 * @return array<int,array{tome:int,ts:int}>
 */
function calculer_dernieres_sorties( int $nombre ): array {
	global $wpdb;
	$publiees = index_oeuvres()['oeuvres'];
	if ( ! $publiees ) {
		return array();
	}
	$limite = max( 12, $nombre * 3 );

	// Derniers tomes publiés.
	$tomes = array_map(
		'intval',
		get_posts(
			array(
				'post_type'              => TYPE_TOME,
				'post_status'            => 'publish',
				'posts_per_page'         => $limite,
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		)
	);

	// Tomes ayant reçu les chapitres publiés les plus récents.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$recents = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT m.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_tome_id'"
			. " WHERE p.post_type = %s AND p.post_status = 'publish' GROUP BY m.meta_value ORDER BY MAX(p.post_date) DESC LIMIT %d",
			TYPE_CHAPITRE,
			$limite
		)
	);

	$candidats = array_values( array_unique( array_merge( $tomes, array_map( 'intval', (array) $recents ) ) ) );
	if ( ! $candidats ) {
		return array();
	}
	_prime_post_caches( $candidats, false, true );

	$sorties = array();
	foreach ( $candidats as $tome_id ) {
		$tome = get_post( $tome_id );
		if ( ! $tome instanceof \WP_Post || TYPE_TOME !== $tome->post_type || 'publish' !== $tome->post_status ) {
			continue;
		}
		$oeuvre_id = (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true );
		if ( ! isset( $publiees[ $oeuvre_id ] ) ) {
			continue;
		}
		$ts = horodatage( $tome );
		if ( sortie_progressive( $tome_id ) ) {
			$stats = stats_tome( $tome_id );
			$ts    = max( $ts, (int) $stats['dernier_ts'] );
		}
		$sorties[] = array(
			'tome' => $tome_id,
			'ts'   => $ts,
		);
	}
	usort(
		$sorties,
		static function ( array $a, array $b ): int {
			return 0 !== ( $b['ts'] <=> $a['ts'] ) ? ( $b['ts'] <=> $a['ts'] ) : ( $b['tome'] <=> $a['tome'] );
		}
	);
	$sorties = array_slice( $sorties, 0, $nombre );
	// Statistiques embarquées : le rendu n'a pas à relire le cache de chaque œuvre.
	foreach ( $sorties as $i => $sortie ) {
		$sorties[ $i ]['oeuvre'] = (int) get_post_meta( $sortie['tome'], 'yume_oeuvre_id', true );
		$sorties[ $i ]['stats']  = stats_tome( (int) $sortie['tome'] );
	}
	return $sorties;
}

/**
 * Charge en quelques requêtes les contenus (avec métadonnées et termes) et les couvertures
 * nécessaires au rendu d'une liste, au lieu d'une requête par élément.
 *
 * @param int[] $ids IDs d'œuvres, de tomes ou de chapitres.
 */
function amorcer_caches( array $ids ): void {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	if ( ! $ids ) {
		return;
	}
	_prime_post_caches( $ids, true, true );
	$couvertures = array();
	foreach ( $ids as $id ) {
		$couvertures[] = (int) get_post_meta( $id, '_thumbnail_id', true );
	}
	$couvertures = array_values( array_unique( array_filter( $couvertures ) ) );
	if ( $couvertures ) {
		_prime_post_caches( $couvertures, false, true );
	}
}
