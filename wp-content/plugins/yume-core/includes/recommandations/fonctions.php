<?php
/**
 * Calcul des recommandations « Si vous avez aimé… » (docs/06 §8 sexies) : candidates, score,
 * co-favoris agrégés, cache versionné et personnalisation d'un membre.
 *
 * - Candidates : œuvres publiées, hors l'œuvre de départ, hors statuts exclus (licenciée par
 *   défaut), hors séries à venir ; filtre yume_recommandations_candidates.
 * - Score : poids.genres × Jaccard(genres) + poids.type × [même type] + poids.cofavoris × cofav,
 *   cofav = n(A∩B) / √(n(A) × n(B)) compté seulement si n(A∩B) ≥ SEUIL_COFAVORIS (k-anonymat :
 *   aucun lien ne se déduit d'un ou deux lecteurs). Égalités : yume_nb_favoris puis
 *   yume_derniere_sortie (décroissants), puis identifiant (ordre stable).
 * - Cache : la liste triée de chaque œuvre (identifiants et nombres seulement, jamais de HTML ni
 *   d'identifiant de lecteur) dans le transient yume_reco_{oeuvre}_{version} ; la version (option
 *   yume_reco_version) change sur les événements écoutés en bas de fichier, une fois par minute
 *   au plus (transient yume_reco_tampon) : un changement survenu pendant ce délai est noté
 *   (option yume_reco_attente) et appliqué à la première lecture qui suit.
 * - Personnalisation (membre connecté) : calculée à chaque appel, jamais mise en cache, à partir
 *   des listes par œuvre ; un visiteur reçoit toujours la même liste (cache de page, §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Recommandations;

defined( 'ABSPATH' ) || exit;

/** Nombre minimal de lecteurs communs pour qu'un co-favori compte (k-anonymat). */
const SEUIL_COFAVORIS = 3;

/** Option : version du cache des recommandations. */
const OPTION_VERSION = 'yume_reco_version';

/** Option : une invalidation attend la fin du délai minimal entre deux versions. */
const OPTION_ATTENTE = 'yume_reco_attente';

/** Transient posé à chaque nouvelle version (une version par minute au plus). */
const TAMPON = 'yume_reco_tampon';

/** Préfixe des transients des listes par œuvre (yume_reco_{oeuvre}_{version}). */
const PREFIXE_CACHE = 'yume_reco_';

/** Durée de vie des listes en cache (filtre yume_recommandations_cache). */
const DUREE_CACHE = 6 * HOUR_IN_SECONDS;

/** Nombre maximal de suggestions demandées (bloc, compte). */
const LIMITE_MAX = 12;

/** Nombre maximal d'œuvres d'un membre (favoris, puis lectures) prises comme point de départ. */
const SOURCES_MAX = 20;

/** Type de contenu et taxonomies lus. */
const TYPE_OEUVRE = 'yume_oeuvre';
const TAX_TYPE    = 'yume_type';
const TAX_GENRE   = 'yume_genre';
const TAX_STATUT  = 'yume_statut';

/*
 * -----------------------------------------------------------------------------
 * Réglages par filtres
 * -----------------------------------------------------------------------------
 */

/**
 * Recommandations actives (filtre yume_recommandations_actives, vrai par défaut).
 */
function actives(): bool {
	/**
	 * Active les recommandations (bloc de la fiche, rubrique « Pour vous », routes REST).
	 *
	 * @param bool $actives Vrai par défaut.
	 */
	return (bool) apply_filters( 'yume_recommandations_actives', true );
}

/**
 * Poids du score : genres, type, co-favoris (filtre yume_recommandations_poids).
 *
 * @return array{genres:float,type:float,cofavoris:float}
 */
function poids(): array {
	$defaut = array(
		'genres'    => 3.0,
		'type'      => 1.0,
		'cofavoris' => 4.0,
	);
	/**
	 * Poids des trois composantes du score.
	 *
	 * @param array{genres:float,type:float,cofavoris:float} $poids Poids (positifs ou nuls).
	 */
	$filtre = apply_filters( 'yume_recommandations_poids', $defaut );
	$filtre = is_array( $filtre ) ? $filtre : array();
	foreach ( $defaut as $cle => $valeur ) {
		$defaut[ $cle ] = isset( $filtre[ $cle ] ) && is_numeric( $filtre[ $cle ] ) ? max( 0.0, (float) $filtre[ $cle ] ) : $valeur;
	}
	return $defaut;
}

/**
 * Statuts de traduction (slugs de yume_statut) dont les œuvres ne sont jamais recommandées.
 *
 * @return string[]
 */
function statuts_exclus(): array {
	/**
	 * Statuts exclus des recommandations.
	 *
	 * @param string[] $statuts Slugs (défaut : licenciée).
	 */
	$statuts = apply_filters( 'yume_recommandations_statuts_exclus', array( 'licenciee' ) );
	return array_values( array_filter( array_map( 'strval', is_array( $statuts ) ? $statuts : array() ), 'strlen' ) );
}

/**
 * Nombre minimal d'œuvres de score positif pour afficher des recommandations.
 */
function minimum(): int {
	/**
	 * Seuil minimal de données : en dessous de ce nombre d'œuvres de score positif, aucune
	 * recommandation n'est proposée pour l'œuvre.
	 *
	 * @param int $minimum Défaut : 2.
	 */
	return max( 1, (int) apply_filters( 'yume_recommandations_minimum', 2 ) );
}

/**
 * Durée de vie des listes en cache, en secondes (0 : pas de cache).
 */
function duree_cache(): int {
	/**
	 * Durée de vie des transients yume_reco_{oeuvre}_{version}.
	 *
	 * @param int $duree Secondes (défaut : 6 heures ; 0 désactive le cache).
	 */
	return max( 0, (int) apply_filters( 'yume_recommandations_cache', DUREE_CACHE ) );
}

/*
 * -----------------------------------------------------------------------------
 * Cache versionné
 * -----------------------------------------------------------------------------
 */

/**
 * Données déjà lues pendant la requête (listes par œuvre, clé : transient ; œuvres publiées),
 * vidées à chaque invalidation.
 *
 * @return array<string,array>
 */
function &memoire(): array {
	static $memoire = array();
	return $memoire;
}

/**
 * Version courante du cache (créée au premier appel). Une invalidation mise en attente est
 * appliquée ici dès que le délai minimal est écoulé.
 */
function version(): string {
	$version = get_option( OPTION_VERSION );
	if ( ! is_scalar( $version ) || '' === (string) $version ) {
		$version = '1';
		add_option( OPTION_VERSION, $version, '', true );
	}
	if ( '1' === (string) get_option( OPTION_ATTENTE, '' ) && false === get_transient( TAMPON ) ) {
		return renouveler_version();
	}
	return (string) $version;
}

/**
 * Invalide les listes en cache : nouvelle version, au plus une fois par minute (sinon,
 * invalidation notée et appliquée par version() à la fin du délai).
 */
function invalider(): void {
	$memoire = &memoire();
	$memoire = array();
	if ( false !== get_transient( TAMPON ) ) {
		if ( '1' !== (string) get_option( OPTION_ATTENTE, '' ) ) {
			update_option( OPTION_ATTENTE, '1', true );
		}
		return;
	}
	renouveler_version();
}

/**
 * Écrit une nouvelle version du cache et pose le tampon d'une minute.
 *
 * @return string Nouvelle version.
 */
function renouveler_version(): string {
	$memoire  = &memoire();
	$memoire  = array();
	$actuelle = get_option( OPTION_VERSION );
	$version  = (string) ( ( is_numeric( $actuelle ) ? (int) $actuelle : 0 ) + 1 );
	update_option( OPTION_VERSION, $version, true );
	if ( false !== get_option( OPTION_ATTENTE ) ) {
		delete_option( OPTION_ATTENTE );
	}
	set_transient( TAMPON, '1', MINUTE_IN_SECONDS );
	return $version;
}

/**
 * Nom du transient de la liste d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function cle_cache( int $oeuvre_id ): string {
	return PREFIXE_CACHE . $oeuvre_id . '_' . version();
}

/*
 * -----------------------------------------------------------------------------
 * Données : favoris agrégés, termes, candidates
 * -----------------------------------------------------------------------------
 */

/**
 * Table des favoris (module lecteurs), ou chaîne vide si elle n'est pas installée.
 */
function table_favoris(): string {
	if ( ! function_exists( '\Yume\Core\Social\table_favoris' ) ) {
		return '';
	}
	if ( function_exists( '\Yume\Core\Social\tables_pretes' ) && ! \Yume\Core\Social\tables_pretes() ) {
		return '';
	}
	return \Yume\Core\Social\table_favoris();
}

/**
 * Co-favoris d'une œuvre : pour chaque autre œuvre, nombre de lecteurs qui ont les deux en
 * favori, seulement à partir de SEUIL_COFAVORIS lecteurs (jamais d'identifiant de lecteur).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,int> oeuvre_id => n (n ≥ SEUIL_COFAVORIS), par identifiant croissant.
 */
function cofavoris( int $oeuvre_id ): array {
	global $wpdb;
	$table = table_favoris();
	if ( $oeuvre_id <= 0 || '' === $table ) {
		return array();
	}
	// Requête agrégée compatible MySQL/MariaDB et SQLite (docs/06 §8 sexies).
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes    = $wpdb->get_results( $wpdb->prepare( "SELECT f2.oeuvre_id, COUNT(*) AS n FROM {$table} f1 INNER JOIN {$table} f2 ON f2.user_id = f1.user_id AND f2.oeuvre_id <> f1.oeuvre_id WHERE f1.oeuvre_id = %d GROUP BY f2.oeuvre_id HAVING COUNT(*) >= %d", $oeuvre_id, SEUIL_COFAVORIS ), ARRAY_A );
	$cofavoris = array();
	foreach ( (array) $lignes as $ligne ) {
		$n = (int) $ligne['n'];
		if ( $n >= SEUIL_COFAVORIS ) {
			$cofavoris[ (int) $ligne['oeuvre_id'] ] = $n;
		}
	}
	ksort( $cofavoris );
	return $cofavoris;
}

/**
 * Nombre de lecteurs ayant chaque œuvre en favori (normalisation des co-favoris).
 *
 * @return array<int,int> oeuvre_id => n.
 */
function favoris_par_oeuvre(): array {
	global $wpdb;
	$table = table_favoris();
	if ( '' === $table ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results( "SELECT oeuvre_id, COUNT(*) AS n FROM {$table} GROUP BY oeuvre_id", ARRAY_A );
	$nombre = array();
	foreach ( (array) $lignes as $ligne ) {
		$nombre[ (int) $ligne['oeuvre_id'] ] = (int) $ligne['n'];
	}
	return $nombre;
}

/**
 * Indice de Jaccard de deux ensembles : |A ∩ B| / |A ∪ B| (0 si les deux sont vides).
 *
 * @param string[] $a Ensemble A.
 * @param string[] $b Ensemble B.
 */
function jaccard( array $a, array $b ): float {
	$a     = array_unique( array_map( 'strval', $a ) );
	$b     = array_unique( array_map( 'strval', $b ) );
	$union = count( array_unique( array_merge( $a, $b ) ) );
	return $union > 0 ? count( array_intersect( $a, $b ) ) / $union : 0.0;
}

/**
 * Slugs des termes d'une œuvre dans une taxonomie.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $taxonomie Taxonomie.
 * @return string[]
 */
function slugs_termes( int $oeuvre_id, string $taxonomie ): array {
	$termes = get_the_terms( $oeuvre_id, $taxonomie );
	return is_array( $termes ) ? array_values( array_map( static fn( $t ): string => (string) $t->slug, $termes ) ) : array();
}

/**
 * Similarité des genres de deux œuvres (indice de Jaccard, entre 0 et 1).
 *
 * @param int $a Œuvre A.
 * @param int $b Œuvre B.
 */
function similarite_genres( int $a, int $b ): float {
	if ( $a <= 0 || $b <= 0 ) {
		return 0.0;
	}
	return jaccard( slugs_termes( $a, TAX_GENRE ), slugs_termes( $b, TAX_GENRE ) );
}

/**
 * Œuvres publiées et leurs termes (types, genres, statuts), lues une fois par version du cache
 * et par requête.
 *
 * @return array<int,array{types:string[],genres:string[],statuts:string[]}>
 */
function oeuvres_publiees(): array {
	$cle     = 'oeuvres|' . version();
	$memoire = &memoire();
	if ( isset( $memoire[ $cle ] ) ) {
		return $memoire[ $cle ];
	}
	$ids     = array_map(
		'intval',
		get_posts(
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
		)
	);
	$oeuvres = array();
	foreach ( $ids as $id ) {
		$oeuvres[ $id ] = array(
			'types'   => array(),
			'genres'  => array(),
			'statuts' => array(),
		);
	}
	if ( $ids ) {
		$termes = wp_get_object_terms(
			$ids,
			array( TAX_TYPE, TAX_GENRE, TAX_STATUT ),
			array(
				'fields'                 => 'all_with_object_id',
				'update_term_meta_cache' => false,
			)
		);
		$champs = array(
			TAX_TYPE   => 'types',
			TAX_GENRE  => 'genres',
			TAX_STATUT => 'statuts',
		);
		foreach ( is_wp_error( $termes ) ? array() : $termes as $terme ) {
			$objet = (int) $terme->object_id;
			if ( isset( $oeuvres[ $objet ], $champs[ $terme->taxonomy ] ) ) {
				$oeuvres[ $objet ][ $champs[ $terme->taxonomy ] ][] = (string) $terme->slug;
			}
		}
	}
	$memoire[ $cle ] = $oeuvres;
	return $oeuvres;
}

/**
 * Candidates d'une œuvre : publiées, hors elle-même, hors statuts exclus et séries à venir,
 * puis filtre yume_recommandations_candidates (qui ne peut que retirer des œuvres).
 *
 * @param int   $oeuvre_id Œuvre de départ.
 * @param array $oeuvres   Œuvres publiées (oeuvres_publiees()).
 * @return int[]
 */
function candidates( int $oeuvre_id, array $oeuvres ): array {
	$exclus = statuts_exclus();
	$ids    = array();
	foreach ( $oeuvres as $id => $donnees ) {
		if ( (int) $id === $oeuvre_id || array_intersect( $donnees['statuts'], $exclus ) ) {
			continue;
		}
		if ( function_exists( 'yume_oeuvre_a_venir' ) && yume_oeuvre_a_venir( (int) $id ) ) {
			continue;
		}
		$ids[] = (int) $id;
	}
	/**
	 * Filtre les œuvres candidates aux recommandations d'une œuvre. Une œuvre ajoutée qui n'est
	 * pas une candidate de base (non publiée, licenciée…) est ignorée.
	 *
	 * @param int[] $ids       Candidates.
	 * @param int   $oeuvre_id Œuvre de départ.
	 */
	$filtrees = apply_filters( 'yume_recommandations_candidates', $ids, $oeuvre_id );
	$filtrees = array_map( 'intval', is_array( $filtrees ) ? $filtrees : $ids );
	return array_values( array_intersect( $ids, $filtrees ) );
}

/**
 * Date de dernière sortie d'une œuvre (horodatage, 0 si inconnue).
 *
 * @param int $oeuvre_id Œuvre.
 */
function derniere_sortie( int $oeuvre_id ): int {
	$date = (string) get_post_meta( $oeuvre_id, 'yume_derniere_sortie', true );
	$ts   = '' !== $date ? strtotime( $date . ' UTC' ) : false;
	return false !== $ts && $ts > 0 ? (int) $ts : 0;
}

/**
 * Ordre des recommandations : score, puis favoris, puis dernière sortie (décroissants), puis
 * identifiant croissant.
 *
 * @param array $a Entrée.
 * @param array $b Entrée.
 */
function comparer( array $a, array $b ): int {
	return array( $b['score'], $b['favoris'], $b['sortie'], $a['id'] ) <=> array( $a['score'], $a['favoris'], $a['sortie'], $b['id'] );
}

/**
 * Liste complète et triée des œuvres de score positif pour une œuvre (sans cache).
 *
 * Entrée : id, score (arrondi à 6 décimales), cofavoris (int|null), genres (slugs communs),
 * favoris (yume_nb_favoris), sortie (horodatage de yume_derniere_sortie).
 *
 * @param int $oeuvre_id Œuvre publiée.
 * @return array<int,array{id:int,score:float,cofavoris:int|null,genres:string[],favoris:int,sortie:int}>
 */
function calculer( int $oeuvre_id ): array {
	$oeuvres = oeuvres_publiees();
	if ( ! isset( $oeuvres[ $oeuvre_id ] ) ) {
		return array();
	}
	$ids = candidates( $oeuvre_id, $oeuvres );
	if ( ! $ids ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	$depart    = $oeuvres[ $oeuvre_id ];
	$poids     = poids();
	$cofavoris = $poids['cofavoris'] > 0 ? cofavoris( $oeuvre_id ) : array();
	$nombres   = $cofavoris ? favoris_par_oeuvre() : array();
	$n_depart  = (int) ( $nombres[ $oeuvre_id ] ?? 0 );
	$liste     = array();
	foreach ( $ids as $id ) {
		$autre   = $oeuvres[ $id ];
		$genres  = array_values( array_intersect( array_unique( $depart['genres'] ), $autre['genres'] ) );
		$score   = $poids['genres'] * jaccard( $depart['genres'], $autre['genres'] );
		$score  += array_intersect( $depart['types'], $autre['types'] ) ? $poids['type'] : 0.0;
		$communs = null;
		$n_autre = (int) ( $nombres[ $id ] ?? 0 );
		if ( isset( $cofavoris[ $id ] ) && $n_depart > 0 && $n_autre > 0 ) {
			$communs = (int) $cofavoris[ $id ];
			$score  += $poids['cofavoris'] * $communs / sqrt( $n_depart * $n_autre );
		}
		$score = round( $score, 6 );
		if ( $score <= 0 ) {
			continue;
		}
		$liste[] = array(
			'id'        => $id,
			'score'     => $score,
			'cofavoris' => $communs,
			'genres'    => $genres,
			'favoris'   => (int) get_post_meta( $id, 'yume_nb_favoris', true ),
			'sortie'    => derniere_sortie( $id ),
		);
	}
	usort( $liste, __NAMESPACE__ . '\\comparer' );
	return $liste;
}

/**
 * Liste triée d'une œuvre, depuis le cache (transient versionné) ou calculée.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array>
 */
function liste( int $oeuvre_id ): array {
	$cle     = cle_cache( $oeuvre_id );
	$memoire = &memoire();
	if ( isset( $memoire[ $cle ] ) ) {
		return $memoire[ $cle ];
	}
	$duree = duree_cache();
	$liste = $duree > 0 ? get_transient( $cle ) : false;
	if ( ! is_array( $liste ) ) {
		$liste = calculer( $oeuvre_id );
		if ( $duree > 0 ) {
			set_transient( $cle, $liste, $duree );
		}
	}
	$memoire[ $cle ] = $liste;
	return $liste;
}

/*
 * -----------------------------------------------------------------------------
 * Membre : œuvres suivies et lectures
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvres d'un membre : favoris (du plus récent) puis lectures commencées (de la plus récente),
 * sans doublon, publiées ou non.
 *
 * @param int $user_id Membre.
 * @return int[]
 */
function oeuvres_membre( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array();
	}
	$ids = array();
	if ( function_exists( '\Yume\Core\Social\favoris_utilisateur' ) ) {
		foreach ( \Yume\Core\Social\favoris_utilisateur( $user_id ) as $ligne ) {
			$ids[] = (int) $ligne['oeuvre_id'];
		}
	}
	if ( function_exists( 'yume_get_progression' ) ) {
		foreach ( yume_get_progression( $user_id ) as $ligne ) {
			$ids[] = (int) $ligne['oeuvre_id'];
		}
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Œuvres de départ de la rubrique « Pour vous » : favoris et lectures publiés (SOURCES_MAX au plus).
 *
 * @param int $user_id Membre.
 * @return int[]
 */
function sources_membre( int $user_id ): array {
	$sources = array_values( array_filter( oeuvres_membre( $user_id ), static fn( int $id ): bool => 'publish' === get_post_status( $id ) && TYPE_OEUVRE === get_post_type( $id ) ) );
	return array_slice( $sources, 0, SOURCES_MAX );
}

/*
 * -----------------------------------------------------------------------------
 * Résultats
 * -----------------------------------------------------------------------------
 */

/**
 * Titre d'une œuvre en texte brut (entités décodées), à échapper en sortie.
 *
 * @param int $oeuvre_id Œuvre.
 */
function titre( int $oeuvre_id ): string {
	$titre = wp_strip_all_tags( (string) get_the_title( $oeuvre_id ) );
	return trim( html_entity_decode( $titre, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Image de couverture d'une œuvre (identifiant de la pièce jointe, 0 sinon).
 *
 * @param int $oeuvre_id Œuvre.
 */
function couverture_id( int $oeuvre_id ): int {
	return function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $oeuvre_id ) : (int) get_post_thumbnail_id( $oeuvre_id );
}

/**
 * Libellé du type d'une œuvre (« Light novel »), vide sans type.
 *
 * @param int $oeuvre_id Œuvre.
 */
function libelle_type( int $oeuvre_id ): string {
	$termes = get_the_terms( $oeuvre_id, TAX_TYPE );
	if ( ! is_array( $termes ) || ! $termes ) {
		return '';
	}
	$noms = function_exists( 'yume_types' ) ? (array) yume_types() : array();
	$slug = (string) $termes[0]->slug;
	return isset( $noms[ $slug ] ) ? (string) $noms[ $slug ] : html_entity_decode( (string) $termes[0]->name, ENT_QUOTES, 'UTF-8' );
}

/**
 * Noms des genres d'une œuvre parmi des slugs donnés (dans l'ordre des termes de l'œuvre).
 *
 * @param int      $oeuvre_id Œuvre.
 * @param string[] $slugs     Slugs retenus.
 * @return string[]
 */
function noms_genres( int $oeuvre_id, array $slugs ): array {
	$termes = $slugs ? get_the_terms( $oeuvre_id, TAX_GENRE ) : array();
	$noms   = array();
	foreach ( is_array( $termes ) ? $termes : array() as $terme ) {
		if ( in_array( (string) $terme->slug, $slugs, true ) ) {
			$noms[] = html_entity_decode( (string) $terme->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	return $noms;
}

/**
 * Recommandation publique (format de la REST et de rendu_cartes()).
 *
 * @param array $entree Entrée d'une liste (calculer()).
 * @return array{id:int,titre:string,url:string,image:string,type:string,genres_communs:string[],cofavoris:int|null,score:float}
 */
function enrichir( array $entree ): array {
	$id    = (int) $entree['id'];
	$image = couverture_id( $id );
	$url   = $image ? wp_get_attachment_image_url( $image, 'yume-couverture' ) : '';
	return array(
		'id'             => $id,
		'titre'          => titre( $id ),
		'url'            => (string) get_permalink( $id ),
		'image'          => is_string( $url ) ? $url : '',
		'type'           => libelle_type( $id ),
		'genres_communs' => noms_genres( $id, (array) $entree['genres'] ),
		'cofavoris'      => null === $entree['cofavoris'] ? null : (int) $entree['cofavoris'],
		'score'          => round( (float) $entree['score'], 4 ),
	);
}

/**
 * Borne une limite demandée (1 à LIMITE_MAX).
 *
 * @param int $limite Limite.
 */
function borner( int $limite ): int {
	return max( 1, min( LIMITE_MAX, $limite ) );
}

/**
 * Recommandations « Si vous avez aimé {œuvre} ».
 *
 * Visiteur ($user_id = 0) : même liste pour tous. Membre : ses favoris et ses lectures
 * commencées sont retirés et remplacés par les suivantes. Vide si l'œuvre n'est pas publiée, si
 * moins de yume_recommandations_minimum œuvres ont un score positif, ou si le module est inactif.
 *
 * @param int $oeuvre_id Œuvre de départ.
 * @param int $limite    Nombre maximal (1 à 12).
 * @param int $user_id   Membre (0 : visiteur).
 * @return array<int,array{id:int,titre:string,url:string,image:string,type:string,genres_communs:string[],cofavoris:int|null,score:float}>
 */
function recommandations_oeuvre( int $oeuvre_id, int $limite = 4, int $user_id = 0 ): array {
	if ( ! actives() || $oeuvre_id <= 0 || TYPE_OEUVRE !== get_post_type( $oeuvre_id ) || 'publish' !== get_post_status( $oeuvre_id ) ) {
		return array();
	}
	$liste = liste( $oeuvre_id );
	if ( count( $liste ) < minimum() ) {
		return array();
	}
	$exclues   = oeuvres_membre( $user_id );
	$resultats = array();
	foreach ( $liste as $entree ) {
		$id = (int) $entree['id'];
		if ( in_array( $id, $exclues, true ) || 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$resultats[] = enrichir( $entree );
		if ( count( $resultats ) >= borner( $limite ) ) {
			break;
		}
	}
	return $resultats;
}

/**
 * Recommandations d'un membre (rubrique « Pour vous ») : agrégat des listes de ses favoris et de
 * ses lectures commencées (scores additionnés), sans ses propres œuvres.
 *
 * @param int $user_id Membre.
 * @param int $limite  Nombre maximal (1 à 12).
 * @return array<int,array{id:int,titre:string,url:string,image:string,type:string,genres_communs:string[],cofavoris:int|null,score:float,parce_que:int[]}>
 */
function recommandations_membre( int $user_id, int $limite = 6 ): array {
	if ( ! actives() || $user_id <= 0 ) {
		return array();
	}
	$sources = sources_membre( $user_id );
	if ( ! $sources ) {
		return array();
	}
	$exclues  = oeuvres_membre( $user_id );
	$minimum  = minimum();
	$agregats = array();
	foreach ( $sources as $source ) {
		$liste = liste( $source );
		if ( count( $liste ) < $minimum ) {
			continue;
		}
		foreach ( $liste as $entree ) {
			$id = (int) $entree['id'];
			if ( in_array( $id, $exclues, true ) ) {
				continue;
			}
			if ( ! isset( $agregats[ $id ] ) ) {
				$agregats[ $id ] = array(
					'meilleure' => $entree,
					'score'     => 0.0,
					'parts'     => array(),
				);
			} elseif ( $entree['score'] > $agregats[ $id ]['meilleure']['score'] ) {
				$agregats[ $id ]['meilleure'] = $entree;
			}
			$agregats[ $id ]['score']           += (float) $entree['score'];
			$agregats[ $id ]['parts'][ $source ] = (float) $entree['score'];
		}
	}
	$ordre = array();
	foreach ( $agregats as $id => $agregat ) {
		$ordre[] = array_merge( $agregat['meilleure'], array( 'score' => round( $agregat['score'], 6 ) ) );
	}
	usort( $ordre, __NAMESPACE__ . '\\comparer' );

	$resultats = array();
	foreach ( $ordre as $entree ) {
		$id = (int) $entree['id'];
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$parts = $agregats[ $id ]['parts'];
		// Contribution décroissante, puis ordre des sources (favori le plus récent d'abord).
		$rang = array_flip( $sources );
		uksort( $parts, static fn( $a, $b ): int => array( $parts[ $b ], $rang[ $a ] ) <=> array( $parts[ $a ], $rang[ $b ] ) );
		$resultats[] = array_merge( enrichir( $entree ), array( 'parce_que' => array_map( 'intval', array_keys( $parts ) ) ) );
		if ( count( $resultats ) >= borner( $limite ) ) {
			break;
		}
	}
	return $resultats;
}

/*
 * -----------------------------------------------------------------------------
 * Invalidation (événements, docs/06 §8 sexies)
 * -----------------------------------------------------------------------------
 */

/**
 * Favori ajouté ou retiré, état d'une œuvre changé, tome publié, compte supprimé ou données
 * d'un membre effacées (les co-favoris agrégés changent) : nouvelle version.
 */
function sur_evenement(): void {
	invalider();
}
add_action( 'yume_favori_ajoute', __NAMESPACE__ . '\\sur_evenement', 10, 0 );
add_action( 'yume_favori_retire', __NAMESPACE__ . '\\sur_evenement', 10, 0 );
add_action( 'yume_oeuvre_etat_change', __NAMESPACE__ . '\\sur_evenement', 10, 0 );
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\sur_evenement', 10, 0 );
add_action( 'deleted_user', __NAMESPACE__ . '\\sur_evenement', 10, 0 );
add_action( 'yume_effacer_donnees', __NAMESPACE__ . '\\sur_evenement', 10, 0 );

/**
 * Œuvre enregistrée (création, modification, changement de statut).
 */
function sur_oeuvre_enregistree(): void {
	invalider();
}
add_action( 'save_post_' . TYPE_OEUVRE, __NAMESPACE__ . '\\sur_oeuvre_enregistree', 10, 0 );

/**
 * Contenu supprimé définitivement : nouvelle version si c'est une œuvre.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu supprimé.
 */
function sur_suppression( $post_id, $post = null ): void {
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( TYPE_OEUVRE === $type ) {
		invalider();
	}
}
add_action( 'deleted_post', __NAMESPACE__ . '\\sur_suppression', 10, 2 );

/**
 * Termes d'un contenu modifiés : nouvelle version si c'est une œuvre.
 *
 * @param int $object_id ID.
 */
function sur_termes( $object_id ): void {
	if ( TYPE_OEUVRE === get_post_type( (int) $object_id ) ) {
		invalider();
	}
}
add_action( 'set_object_terms', __NAMESPACE__ . '\\sur_termes', 10, 1 );
