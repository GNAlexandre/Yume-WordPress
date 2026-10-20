<?php
/**
 * Rattrapage : calcul, cache et rendu (bloc yume/rattrapage, rubrique du compte).
 *
 * Bloc de l'accueil « Reprendre la lecture » (2.5.1) : la dernière lecture du membre en première
 * carte (même position que yume/resume-reading, même si la série est à jour), puis les séries à
 * rattraper sans elle, NOMBRE_BLOC cartes au plus. Le bloc note la lecture affichée
 * (noter_reprise_affichee()) : le bandeau yume/resume-reading du bas de l'accueil, rendu ensuite,
 * ne la répète pas (filtre yume_resume_reading_afficher) et montre son repli « Communauté ».
 *
 * Calcul (rattrapage()) : pour chaque ligne de progression du membre (œuvre publiée, chapitre
 * de la position encore publié dans un tome publié), l'ordre de lecture vient de
 * Reader\plan_de_lecture() (deux requêtes agrégées pour toutes les séries) ; les « restants »
 * sont les chapitres publiés qui suivent la position, le chapitre courant compris s'il est lu à
 * moins de Reader\SEUIL_CHAPITRE_LU %. Une série sans restant (à jour) est omise. Une troisième
 * requête lit les dates de publication des restants (nouveaux depuis la dernière lecture, tri par
 * le plus récent). Au plus SERIES_MAX séries (les plus récemment lues) sont examinées et
 * LIMITE_MAX lignes enrichies : le calcul est borné quel que soit l'historique du membre.
 *
 * Cache : transient yume_rattrapage_{user} (filtre yume_rattrapage_cache, 900 s) portant la
 * version globale (option yume_rattrapage_version) et l'empreinte des lignes de progression du
 * membre ; effacé sur yume_progression_enregistree (ce membre), périmé pour tous quand la
 * version change (yume_chapitre_publie, yume_tome_publie, publication ou dépublication d'un
 * chapitre, d'un tome ou d'une œuvre).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Rattrapage;

defined( 'ABSPATH' ) || exit;

/** Option de version du cache (incrémentée à chaque publication ou dépublication). */
const OPTION_VERSION = 'yume_rattrapage_version';

/** Préfixe du transient par membre : yume_rattrapage_{user}. */
const PREFIXE_CACHE = 'yume_rattrapage_';

/** Lignes au plus renvoyées (REST : paramètre limite de 1 à 50). */
const LIMITE_MAX = 50;

/** Cartes au plus dans la rubrique « À rattraper » du compte. */
const LIMITE_COMPTE = 12;

/** Cartes au plus dans le bloc (attribut count). */
const LIMITE_BLOC = 12;

/** Cartes du bloc par défaut (attribut count) : une rangée de trois sur ordinateur. */
const NOMBRE_BLOC = 3;

/** Séries examinées au plus (les plus récemment lues) : borne du calcul. */
const SERIES_MAX = 100;

/** Ancre de la rubrique du compte. */
const ANCRE = 'yn-rattrapage';

/** Version des fichiers statiques (style du bloc). */
const VERSION_STYLE = '2';

/*
 * -----------------------------------------------------------------------------
 * Réglages par filtres
 * -----------------------------------------------------------------------------
 */

/**
 * Le rattrapage est-il actif (filtre yume_rattrapage_actif, vrai par défaut) ?
 */
function actif(): bool {
	/**
	 * Active ou coupe le rattrapage (bloc, rubrique du compte, route REST).
	 *
	 * @param bool $actif Vrai par défaut.
	 */
	return (bool) apply_filters( 'yume_rattrapage_actif', true );
}

/**
 * Durée du cache par membre, en secondes (filtre yume_rattrapage_cache, 900 s ; 0 = sans cache).
 */
function duree_cache(): int {
	/**
	 * Durée du transient yume_rattrapage_{user}, en secondes (0 : pas de cache).
	 *
	 * @param int $duree 900 par défaut.
	 */
	return max( 0, (int) apply_filters( 'yume_rattrapage_cache', 15 * MINUTE_IN_SECONDS ) );
}

/*
 * -----------------------------------------------------------------------------
 * Cache et invalidation
 * -----------------------------------------------------------------------------
 */

/**
 * Version globale du cache.
 */
function version(): int {
	return (int) get_option( OPTION_VERSION, 0 );
}

/**
 * Efface le cache d'un membre.
 *
 * @param int $user_id Membre.
 */
function invalider_membre( int $user_id ): void {
	if ( $user_id > 0 ) {
		delete_transient( PREFIXE_CACHE . $user_id );
	}
}

/**
 * Périme le cache de tous les membres (nouvelle version).
 */
function invalider_tous(): void {
	update_option( OPTION_VERSION, version() + 1 );
}

/**
 * Position de lecture enregistrée : cache du membre effacé.
 *
 * @param int $user_id Membre.
 */
function sur_progression( $user_id ): void {
	invalider_membre( (int) $user_id );
}
add_action( 'yume_progression_enregistree', __NAMESPACE__ . '\\sur_progression' );
add_action( 'deleted_user', __NAMESPACE__ . '\\sur_progression' );
add_action( 'yume_effacer_donnees', __NAMESPACE__ . '\\sur_progression' );

/**
 * Chapitre ou tome publié (événements métier) : cache de tous périmé.
 */
function sur_publication(): void {
	invalider_tous();
}
add_action( 'yume_chapitre_publie', __NAMESPACE__ . '\\sur_publication' );
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\sur_publication' );

/**
 * Chapitre, tome ou œuvre qui entre en ligne ou en sort (dépublication, corbeille, publication
 * silencieuse d'une migration, programmation arrivée à échéance) : cache de tous périmé.
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function sur_transition( $nouveau, $ancien, $post ): void {
	if ( ! $post instanceof \WP_Post || $nouveau === $ancien || ( 'publish' !== $nouveau && 'publish' !== $ancien ) ) {
		return;
	}
	if ( in_array( $post->post_type, array( 'yume_chapitre', 'yume_tome', 'yume_oeuvre' ), true ) ) {
		invalider_tous();
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\sur_transition', 10, 3 );

/**
 * Lignes de progression du membre (les plus récentes d'abord), SERIES_MAX au plus.
 *
 * @param int $user_id Membre.
 * @return array<int,array>
 */
function lignes_membre( int $user_id ): array {
	if ( $user_id <= 0 || ! function_exists( '\Yume\Core\Reader\lignes_progression' ) ) {
		return array();
	}
	return array_slice( \Yume\Core\Reader\lignes_progression( $user_id ), 0, SERIES_MAX );
}

/**
 * Données de rattrapage d'un membre, en cache : séries commencées examinées, total des séries
 * à rattraper, et les LIMITE_MAX premières lignes (tableau de la REST).
 *
 * @param int $user_id Membre.
 * @return array{series:int,total:int,oeuvres:array<int,array>}
 */
function donnees_membre( int $user_id ): array {
	$vide = array(
		'series'  => 0,
		'total'   => 0,
		'oeuvres' => array(),
	);
	if ( $user_id <= 0 || ! actif() ) {
		return $vide;
	}
	$lignes    = lignes_membre( $user_id );
	$signature = md5( (string) wp_json_encode( $lignes ) );
	$version   = version();
	$duree     = duree_cache();
	if ( $duree > 0 ) {
		$cache = get_transient( PREFIXE_CACHE . $user_id );
		if ( is_array( $cache ) && isset( $cache['version'], $cache['signature'], $cache['donnees'] )
			&& (int) $cache['version'] === $version && $cache['signature'] === $signature && is_array( $cache['donnees'] ) ) {
			return $cache['donnees'];
		}
	}
	$donnees = $lignes ? calculer( $lignes ) : $vide;
	if ( $duree > 0 ) {
		set_transient(
			PREFIXE_CACHE . $user_id,
			array(
				'version'   => $version,
				'signature' => $signature,
				'donnees'   => $donnees,
			),
			$duree
		);
	}
	return $donnees;
}

/*
 * -----------------------------------------------------------------------------
 * Calcul
 * -----------------------------------------------------------------------------
 */

/**
 * Rattrapage d'un membre : séries commencées où des chapitres publiés restent à lire, de la
 * plus récemment alimentée à la plus ancienne. Même tableau que GET /yume/v1/moi/rattrapage.
 *
 * @param int $user_id Membre (0 : rien).
 * @param int $limite  Séries au plus (1 à LIMITE_MAX).
 * @return array{total:int,oeuvres:array<int,array>}
 */
function rattrapage( int $user_id, int $limite = 6 ): array {
	$donnees = donnees_membre( $user_id );
	return array(
		'total'   => (int) $donnees['total'],
		'oeuvres' => array_slice( $donnees['oeuvres'], 0, max( 1, min( LIMITE_MAX, $limite ) ) ),
	);
}

/**
 * Dernière lecture du membre : sa position la plus récente encore lisible, enrichie par
 * Reader\enrichir_ligne() (la même que yume/resume-reading), ou null.
 *
 * @param int $user_id Membre.
 */
function derniere_lecture( int $user_id ): ?array {
	if ( ! function_exists( '\Yume\Core\Reader\enrichir_ligne' ) ) {
		return null;
	}
	foreach ( lignes_membre( $user_id ) as $ligne ) {
		$position = \Yume\Core\Reader\enrichir_ligne( $ligne );
		if ( $position ) {
			return $position;
		}
	}
	return null;
}

/**
 * Cartes du bloc de l'accueil : la dernière lecture (avec sa ligne de rattrapage quand la série a
 * des chapitres à lire), puis les autres séries à rattraper, $nombre cartes au plus en tout.
 *
 * @param int $user_id Membre (0 : rien).
 * @param int $nombre  Cartes au plus (1 à LIMITE_BLOC).
 * @return array{reprise:array{position:array,ligne:?array}|null,oeuvres:array<int,array>,autres:int}
 *         autres : séries à rattraper non affichées (hors dernière lecture).
 */
function donnees_accueil( int $user_id, int $nombre = NOMBRE_BLOC ): array {
	$resultat = array(
		'reprise' => null,
		'oeuvres' => array(),
		'autres'  => 0,
	);
	if ( $user_id <= 0 || ! actif() ) {
		return $resultat;
	}
	$nombre   = max( 1, min( LIMITE_BLOC, $nombre ) );
	$position = derniere_lecture( $user_id );
	$donnees  = donnees_membre( $user_id );
	$suivie   = null;
	$autres   = array();
	foreach ( $donnees['oeuvres'] as $ligne ) {
		if ( $position && (int) $ligne['oeuvre_id'] === (int) $position['oeuvre_id'] ) {
			$suivie = $ligne;
			continue;
		}
		$autres[] = $ligne;
	}
	if ( $position ) {
		$resultat['reprise'] = array(
			'position' => $position,
			'ligne'    => $suivie,
		);
	}
	$resultat['oeuvres'] = array_slice( $autres, 0, max( 0, $nombre - ( $position ? 1 : 0 ) ) );
	$resultat['autres']  = max( 0, (int) $donnees['total'] - ( $suivie ? 1 : 0 ) - count( $resultat['oeuvres'] ) );
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Dernière lecture affichée (bandeau de l'accueil)
 * -----------------------------------------------------------------------------
 */

/**
 * Registre de la requête : membre => œuvre de la dernière lecture affichée par le bloc.
 *
 * @return array<int,int>
 */
function &registre_reprises(): array {
	static $registre = array();
	return $registre;
}

/**
 * Note que le bloc vient d'afficher la dernière lecture de ce membre.
 *
 * @param int $user_id   Membre.
 * @param int $oeuvre_id Œuvre de la dernière lecture.
 */
function noter_reprise_affichee( int $user_id, int $oeuvre_id ): void {
	$registre             = &registre_reprises();
	$registre[ $user_id ] = $oeuvre_id;
}

/**
 * Œuvre de la dernière lecture déjà affichée pour ce membre pendant la requête (0 : aucune).
 *
 * @param int $user_id Membre.
 */
function reprise_affichee( int $user_id ): int {
	return (int) ( registre_reprises()[ $user_id ] ?? 0 );
}

/**
 * Vide le registre (tests, rendus successifs dans une même requête).
 */
function oublier_reprises(): void {
	$registre = &registre_reprises();
	$registre = array();
}

/**
 * Filtre yume_resume_reading_afficher : en « bandeau », pas de reprise déjà montrée par le bloc
 * (même membre, même œuvre). Le bloc yume/resume-reading ne rend alors rien et le bandeau de
 * l'accueil montre son repli « Communauté ». Jamais appliqué à un visiteur (pas de position).
 *
 * @param mixed  $afficher Afficher la reprise (vrai).
 * @param mixed  $position Position du membre (Reader\enrichir_ligne()).
 * @param string $layout   bandeau | carte.
 * @return mixed
 */
function filtre_resume( $afficher, $position = null, $layout = '' ) {
	if ( ! $afficher || 'bandeau' !== $layout || ! is_array( $position ) ) {
		return $afficher;
	}
	$oeuvre_id = reprise_affichee( get_current_user_id() );
	return ! ( $oeuvre_id > 0 && (int) ( $position['oeuvre_id'] ?? 0 ) === $oeuvre_id );
}
add_filter( 'yume_resume_reading_afficher', __NAMESPACE__ . '\\filtre_resume', 10, 3 );

/**
 * Seuil (pourcentage) à partir duquel le chapitre courant compte comme lu.
 */
function seuil_lu(): int {
	return defined( 'Yume\Core\Reader\SEUIL_CHAPITRE_LU' ) ? (int) constant( 'Yume\Core\Reader\SEUIL_CHAPITRE_LU' ) : 90;
}

/**
 * Horodatage d'une date GMT « Y-m-d H:i:s » (0 si vide ou invalide).
 *
 * @param string $gmt Date GMT.
 */
function horodatage( string $gmt ): int {
	if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return 0;
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? 0 : (int) $ts;
}

/**
 * Dates de publication (horodatage GMT) de chapitres : une requête par lot de 500.
 *
 * @param int[] $ids Chapitres.
 * @return array<int,int> ID => horodatage.
 */
function dates_publication( array $ids ): array {
	global $wpdb;
	$dates = array();
	$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	foreach ( array_chunk( $ids, 500 ) as $lot ) {
		$marques = implode( ', ', array_fill( 0, count( $lot ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
		$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ($marques)", $lot ), ARRAY_A );
		foreach ( (array) $lignes as $ligne ) {
			$gmt = (string) $ligne['post_date_gmt'];
			if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
				$gmt = get_gmt_from_date( (string) $ligne['post_date'] );
			}
			$dates[ (int) $ligne['ID'] ] = horodatage( $gmt );
		}
	}
	return $dates;
}

/**
 * Libellé d'un chapitre (« Chapitre 3 », « Prologue »), sinon son titre.
 *
 * @param int $chapitre_id Chapitre.
 */
function libelle_chapitre( int $chapitre_id ): string {
	$libelle = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : '';
	return '' !== $libelle ? $libelle : wp_strip_all_tags( get_the_title( $chapitre_id ) );
}

/**
 * Lien vers un chapitre : {id, titre, url}.
 *
 * @param int $chapitre_id Chapitre.
 * @return array{id:int,titre:string,url:string}
 */
function lien_chapitre( int $chapitre_id ): array {
	return array(
		'id'    => $chapitre_id,
		'titre' => libelle_chapitre( $chapitre_id ),
		'url'   => (string) get_permalink( $chapitre_id ),
	);
}

/**
 * Calcule le rattrapage à partir des lignes de progression (sans cache).
 *
 * @param array $lignes Lignes de progression (normalisées, les plus récentes d'abord).
 * @return array{series:int,total:int,oeuvres:array<int,array>}
 */
function calculer( array $lignes ): array {
	$resultat = array(
		'series'  => 0,
		'total'   => 0,
		'oeuvres' => array(),
	);
	if ( ! $lignes || ! function_exists( '\Yume\Core\Reader\plan_de_lecture' ) ) {
		return $resultat;
	}
	$oeuvres = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $lignes, 'oeuvre_id' ) ) ) ) );
	_prime_post_caches( $oeuvres, false, false );
	$oeuvres = array_values(
		array_filter(
			$oeuvres,
			static fn( int $id ): bool => 'yume_oeuvre' === get_post_type( $id ) && 'publish' === get_post_status( $id )
		)
	);
	$plan    = \Yume\Core\Reader\plan_de_lecture( $oeuvres );
	$seuil   = seuil_lu();

	// Restants de chaque série, dans l'ordre de lecture.
	$candidats = array();
	$a_dater   = array();
	foreach ( $lignes as $ligne ) {
		$oeuvre_id = (int) $ligne['oeuvre_id'];
		$position  = (int) $ligne['chapitre_id'];
		if ( empty( $plan[ $oeuvre_id ] ) || isset( $candidats[ $oeuvre_id ] ) ) {
			continue;
		}
		$trouve   = false;
		$restants = array();
		$tome_de  = array();
		$premiers = array();
		foreach ( $plan[ $oeuvre_id ] as $tome ) {
			foreach ( array_values( $tome['chapitres'] ) as $rang => $chapitre ) {
				$id = (int) $chapitre['id'];
				if ( $trouve ) {
					$restants[]      = $id;
					$tome_de[ $id ]  = (int) $tome['id'];
					$premiers[ $id ] = 0 === $rang;
					continue;
				}
				if ( $id === $position ) {
					$trouve = true;
					if ( (int) $ligne['pourcentage'] < $seuil ) {
						$restants[]      = $id;
						$tome_de[ $id ]  = (int) $tome['id'];
						$premiers[ $id ] = 0 === $rang;
					}
				}
			}
		}
		// Position sur un chapitre dépublié, déplacé ou dans un tome hors ligne : rien à déduire.
		if ( ! $trouve ) {
			continue;
		}
		++$resultat['series'];
		if ( ! $restants ) {
			continue; // À jour.
		}
		$prochain                = $restants[0];
		$suivant                 = $prochain === $position ? ( $restants[1] ?? 0 ) : $prochain;
		$candidats[ $oeuvre_id ] = array(
			'ligne'    => $ligne,
			'restants' => $restants,
			'prochain' => $prochain,
			'suivant'  => $suivant,
			'tome'     => $tome_de[ $prochain ],
			'commence' => $premiers[ $prochain ] && $prochain !== $position,
		);
		array_push( $a_dater, ...$restants );
	}
	if ( ! $candidats ) {
		return $resultat;
	}

	// Dates de publication : nouveaux depuis la dernière lecture, plus récent des restants.
	$dates = dates_publication( $a_dater );
	foreach ( $candidats as $oeuvre_id => $candidat ) {
		$lu_le   = horodatage( (string) $candidat['ligne']['updated_at'] );
		$dernier = 0;
		$nouveau = 0;
		foreach ( $candidat['restants'] as $id ) {
			$ts      = $dates[ $id ] ?? 0;
			$dernier = max( $dernier, $ts );
			if ( $ts > $lu_le ) {
				++$nouveau;
			}
		}
		$candidats[ $oeuvre_id ]['lu_le']   = $lu_le;
		$candidats[ $oeuvre_id ]['dernier'] = $dernier;
		$candidats[ $oeuvre_id ]['nouveau'] = $nouveau;
	}
	uasort(
		$candidats,
		static function ( array $a, array $b ): int {
			$cmp = $b['dernier'] <=> $a['dernier'];
			if ( 0 === $cmp ) {
				$cmp = $b['lu_le'] <=> $a['lu_le'];
			}
			return 0 !== $cmp ? $cmp : ( (int) $a['ligne']['oeuvre_id'] <=> (int) $b['ligne']['oeuvre_id'] );
		}
	);
	$resultat['total'] = count( $candidats );
	$candidats         = array_slice( $candidats, 0, LIMITE_MAX, true );

	// Enrichissement des lignes gardées (contenus puis couvertures amorcés par lots).
	$ids = array();
	foreach ( $candidats as $oeuvre_id => $candidat ) {
		array_push( $ids, $oeuvre_id, $candidat['prochain'], $candidat['suivant'], $candidat['tome'], (int) $candidat['ligne']['chapitre_id'] );
	}
	_prime_post_caches( array_values( array_unique( array_filter( $ids ) ) ), false, true );
	$images = array();
	foreach ( $candidats as $oeuvre_id => $candidat ) {
		$images[ $oeuvre_id ] = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $candidat['prochain'] ) : 0;
	}
	_prime_post_caches( array_values( array_unique( array_filter( $images ) ) ), false, true );
	foreach ( $candidats as $oeuvre_id => $candidat ) {
		$position = function_exists( '\Yume\Core\Reader\enrichir_ligne' ) ? \Yume\Core\Reader\enrichir_ligne( $candidat['ligne'] ) : null;
		if ( ! $position ) {
			--$resultat['total'];
			continue;
		}
		$image                 = $images[ $oeuvre_id ] ? wp_get_attachment_image_url( $images[ $oeuvre_id ], 'yume-couverture' ) : '';
		$resultat['oeuvres'][] = array(
			'oeuvre_id'         => $oeuvre_id,
			'titre'             => wp_strip_all_tags( get_the_title( $oeuvre_id ) ),
			'url'               => (string) get_permalink( $oeuvre_id ),
			'image'             => is_string( $image ) ? $image : '',
			'restants'          => count( $candidat['restants'] ),
			'nouveaux_depuis'   => $candidat['nouveau'],
			'tome'              => array(
				'id'       => $candidat['tome'],
				'libelle'  => function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $candidat['tome'] ) : '',
				'commence' => $candidat['commence'],
			),
			'prochain'          => lien_chapitre( $candidat['prochain'] ),
			'suivant'           => $candidat['suivant'] ? lien_chapitre( $candidat['suivant'] ) : null,
			'reprise'           => array(
				'url'         => (string) $position['url_reprise'],
				'titre'       => '' !== (string) $position['chapitre'] ? (string) $position['chapitre'] : libelle_chapitre( (int) $position['chapitre_id'] ),
				'pourcentage' => (int) $position['pourcentage'],
			),
			'dernier_publie_le' => $candidat['dernier'] ? gmdate( 'c', $candidat['dernier'] ) : '',
		);
	}
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Attributs de la racine du bloc (classes de l'éditeur et alignement compris).
 *
 * @param string $classe Classe racine.
 * @param array  $extra  Attributs supplémentaires.
 */
function attributs_racine( string $classe, array $extra = array() ): string {
	$extra['class'] = trim( $classe . ' ' . ( $extra['class'] ?? '' ) );
	if ( class_exists( '\WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render ) ) {
		return get_block_wrapper_attributes( $extra );
	}
	$html = '';
	foreach ( $extra as $nom => $valeur ) {
		$html .= ' ' . esc_attr( (string) $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return ltrim( $html );
}

/**
 * Rendu en cours dans l'éditeur de blocs (aperçu serveur) ?
 */
function apercu_editeur(): bool {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return false;
	}
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
	return '' === $route || str_contains( $route, '/block-renderer/' );
}

/**
 * Enregistre la feuille de style (chargée seulement quand des cartes sont rendues : jamais sur
 * une page anonyme) puis le bloc.
 */
function enregistrer_bloc(): void {
	wp_register_style( 'yume-rattrapage', plugins_url( 'blocks/rattrapage/style.css', __FILE__ ), array(), VERSION_STYLE );
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/rattrapage' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc' );

/**
 * Adresse d'une rubrique du compte (« Reprendre la lecture » : yn-lecture par défaut).
 *
 * @param string $ancre Ancre de la rubrique.
 */
function url_lectures( string $ancre = 'yn-lecture' ): string {
	$compte = function_exists( '\Yume\Core\Social\url_compte' ) ? \Yume\Core\Social\url_compte() : ( function_exists( 'yume_url_page' ) ? yume_url_page( 'compte' ) : home_url( '/compte/' ) );
	return $compte . '#' . $ancre;
}

/**
 * Couverture réduite et décorative (image du tome du prochain chapitre, sinon de l'œuvre ;
 * sinon dégradé du thème avec les initiales).
 *
 * @param int    $chapitre_id Prochain chapitre.
 * @param string $titre       Titre de l'œuvre.
 */
function couverture( int $chapitre_id, string $titre ): string {
	$image_id = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $chapitre_id ) : 0;
	$image    = $image_id && function_exists( 'yume_image_couverture' ) ? yume_image_couverture(
		$image_id,
		'yume-couverture',
		array(
			'alt'     => '',
			'loading' => 'lazy',
		)
	) : '';
	if ( '' !== $image ) {
		return '<span class="yn-cover yn-rattrapage__couverture" aria-hidden="true">' . $image . '</span>';
	}
	$initiales = '';
	foreach ( array_slice( (array) preg_split( '/[\s\-]+/u', trim( $titre ), -1, PREG_SPLIT_NO_EMPTY ), 0, 2 ) as $mot ) {
		$initiales .= mb_strtoupper( mb_substr( (string) $mot, 0, 1 ) );
	}
	return '<span class="yn-cover yn-rattrapage__couverture yn-rattrapage__couverture--substitution" aria-hidden="true"><span>' . esc_html( $initiales ) . '</span></span>';
}

/**
 * Date courte du dernier chapitre publié, au format du site : « 18 oct. », « 2 sept. 2025 »
 * (année ajoutée si elle diffère ; Library\date_courte(), mois abrégés en minuscules).
 *
 * @param string $iso Date ISO 8601.
 */
function date_courte( string $iso ): string {
	$ts = '' !== $iso ? strtotime( $iso ) : false;
	if ( ! $ts ) {
		return '';
	}
	$annee = wp_date( 'Y', $ts ) !== wp_date( 'Y' );
	$texte = function_exists( '\Yume\Core\Library\date_courte' )
		? \Yume\Core\Library\date_courte( (int) $ts, $annee )
		: (string) wp_date( $annee ? 'd/m/Y' : 'd/m', $ts );
	return '<time datetime="' . esc_attr( $iso ) . '">' . esc_html( $texte ) . '</time>';
}

/**
 * Ligne d'avancement : « Tome 2 · 3 nouveaux chapitres », « Tome 3 commence · 12 chapitres à
 * lire » (HTML échappé).
 *
 * @param array  $ligne         Ligne du rattrapage.
 * @param string $layout        section | carte (carte : « … depuis votre dernière lecture »).
 * @param bool   $tome_nouveau  Tome seulement quand il commence (carte de la dernière lecture,
 *                              dont la position nomme déjà le tome).
 */
function texte_avancement( array $ligne, string $layout, bool $tome_nouveau = false ): string {
	$tome = (string) ( $ligne['tome']['libelle'] ?? '' );
	if ( '' !== $tome && ! empty( $ligne['tome']['commence'] ) ) {
		/* translators: %s : tome ou arc (« Tome 3 »). */
		$tome = sprintf( __( '%s commence', 'yume-core' ), $tome );
	} elseif ( $tome_nouveau ) {
		$tome = '';
	}
	$nouveaux = (int) $ligne['nouveaux_depuis'];
	if ( $nouveaux > 0 ) {
		/* translators: %d : nombre de chapitres publiés depuis la dernière lecture. */
		$compte = '<strong class="yn-rattrapage__nombre">' . esc_html( sprintf( _n( '%d nouveau chapitre', '%d nouveaux chapitres', $nouveaux, 'yume-core' ), $nouveaux ) ) . '</strong>';
		if ( 'carte' === $layout ) {
			$compte .= ' ' . esc_html__( 'depuis votre dernière lecture', 'yume-core' );
		}
	} else {
		$restants = (int) $ligne['restants'];
		/* translators: %d : nombre de chapitres publiés restant à lire. */
		$compte = '<strong class="yn-rattrapage__nombre">' . esc_html( sprintf( _n( '%d chapitre à lire', '%d chapitres à lire', $restants, 'yume-core' ), $restants ) ) . '</strong>';
	}
	return ( '' !== $tome ? esc_html( $tome ) . ' · ' : '' ) . $compte;
}

/**
 * Texte du bouton « Reprendre » : « Reprendre (chapitre 2, 41 %) », « Reprendre ».
 *
 * @param array $reprise Reprise : url, titre, pourcentage.
 */
function texte_reprise( array $reprise ): string {
	$chapitre    = mb_strtolower( (string) ( $reprise['titre'] ?? '' ) );
	$pourcentage = (int) ( $reprise['pourcentage'] ?? 0 );
	if ( '' === $chapitre || $pourcentage >= seuil_lu() ) {
		return __( 'Reprendre', 'yume-core' );
	}
	if ( $pourcentage <= 0 ) {
		/* translators: %s : chapitre en cours (« chapitre 2 »). */
		return sprintf( __( 'Reprendre (%s)', 'yume-core' ), $chapitre );
	}
	/* translators: 1 : chapitre en cours (« chapitre 2 »), 2 : pourcentage lu. */
	return sprintf( __( 'Reprendre (%1$s, %2$d %%)', 'yume-core' ), $chapitre, $pourcentage );
}

/**
 * Bouton « Chapitre suivant → » d'une ligne (vide sans chapitre suivant).
 *
 * @param array $ligne Ligne du rattrapage.
 */
function bouton_suivant( array $ligne ): string {
	if ( empty( $ligne['suivant']['url'] ) ) {
		return '';
	}
	return '<a class="yn-btn yn-rattrapage__suivant" href="' . esc_url( (string) $ligne['suivant']['url'] ) . '">'
		. esc_html__( 'Chapitre suivant', 'yume-core' ) . ' <span aria-hidden="true">→</span>'
		. '<span class="yn-visually-hidden"> : ' . esc_html( (string) $ligne['titre'] . ', ' . (string) $ligne['suivant']['titre'] ) . '</span></a>';
}

/**
 * Ligne « dernier publié le 18 oct. » (vide sans date).
 *
 * @param array $ligne Ligne du rattrapage.
 */
function ligne_date( array $ligne ): string {
	$date = date_courte( (string) ( $ligne['dernier_publie_le'] ?? '' ) );
	/* translators: %s : date du dernier chapitre publié (balise time). */
	return '' !== $date ? '<p class="yn-rattrapage__date yn-muted">' . sprintf( esc_html__( 'dernier publié le %s', 'yume-core' ), $date ) . '</p>' : '';
}

/**
 * Carte d'une série à rattraper : couverture et texte, puis les boutons sur toute la largeur.
 *
 * @param array  $ligne  Ligne du rattrapage.
 * @param string $layout section | carte.
 */
function rendu_carte( array $ligne, string $layout ): string {
	$titre   = (string) $ligne['titre'];
	$actions = '<a class="yn-btn yn-btn--primary yn-rattrapage__reprendre" href="' . esc_url( (string) $ligne['reprise']['url'] ) . '">'
		. esc_html( texte_reprise( (array) $ligne['reprise'] ) ) . '<span class="yn-visually-hidden"> : ' . esc_html( $titre ) . '</span></a>'
		. bouton_suivant( $ligne );
	return '<li class="yn-rattrapage__item" data-yn-oeuvre="' . esc_attr( (string) (int) $ligne['oeuvre_id'] ) . '">'
		. couverture( (int) $ligne['prochain']['id'], $titre )
		. '<div class="yn-rattrapage__corps">'
		. '<h3 class="yn-rattrapage__oeuvre"><a href="' . esc_url( (string) $ligne['url'] ) . '">' . esc_html( $titre ) . '</a></h3>'
		. '<p class="yn-rattrapage__avancement">' . texte_avancement( $ligne, $layout ) . '</p>'
		. ligne_date( $ligne )
		. '</div>'
		. '<p class="yn-rattrapage__actions">' . $actions . '</p>'
		. '</li>';
}

/**
 * Carte de la dernière lecture (bloc de l'accueil) : « Tome 4 · chapitre 27 · 81 % du
 * chapitre », barre de lecture, bouton « Continuer » ; quand la série a des chapitres après la
 * position, « 3 nouveaux chapitres » (ou « N chapitres à lire »), la date et « Chapitre suivant ».
 *
 * @param array      $position Position (Reader\enrichir_ligne()).
 * @param array|null $ligne    Ligne du rattrapage de la même œuvre, ou null (série à jour).
 */
function rendu_carte_reprise( array $position, ?array $ligne ): string {
	$titre       = (string) $position['oeuvre'];
	$pourcentage = max( 0, min( 100, (int) $position['pourcentage'] ) );
	/* translators: %s : pourcentage lu du chapitre en cours. */
	$part   = sprintf( __( '%s %% du chapitre', 'yume-core' ), (string) $pourcentage );
	$detail = implode( ' · ', array_filter( array( (string) $position['tome'], mb_strtolower( (string) $position['chapitre'] ), $part ), 'strlen' ) );
	$suite  = $ligne && ! empty( $ligne['suivant']['url'] );
	$html   = '<li class="yn-rattrapage__item yn-rattrapage__item--reprise" data-yn-oeuvre="' . esc_attr( (string) (int) $position['oeuvre_id'] ) . '" data-yn-reprise>'
		. couverture( (int) $position['chapitre_id'], $titre )
		. '<div class="yn-rattrapage__corps">'
		. '<p class="yn-label yn-rattrapage__surtitre">' . esc_html__( 'Dernière lecture', 'yume-core' ) . '</p>'
		. '<h3 class="yn-rattrapage__oeuvre"><a href="' . esc_url( (string) get_permalink( (int) $position['oeuvre_id'] ) ) . '">' . esc_html( $titre ) . '</a></h3>'
		. '<p class="yn-rattrapage__position">' . esc_html( $detail ) . '</p>'
		. '<span class="yn-bar yn-rattrapage__barre" aria-hidden="true"><span style="--v:' . esc_attr( (string) $pourcentage ) . '%"></span></span>';
	if ( $suite ) {
		$html .= '<p class="yn-rattrapage__avancement">' . texte_avancement( $ligne, 'section', true ) . '</p>' . ligne_date( $ligne );
	}
	$html .= '</div><p class="yn-rattrapage__actions">'
		. '<a class="yn-btn yn-btn--primary yn-rattrapage__continuer" href="' . esc_url( (string) $position['url_reprise'] ) . '">' . esc_html__( 'Continuer', 'yume-core' )
		. '<span class="yn-visually-hidden"> : ' . esc_html( '' !== (string) $position['titre'] ? (string) $position['titre'] : $titre ) . '</span></a>'
		. ( $suite ? bouton_suivant( $ligne ) : '' )
		. '</p></li>';
	return $html;
}

/**
 * Liste de cartes, partagée par le bloc et la rubrique du compte ; charge la feuille de style.
 *
 * @param array  $lignes  Lignes du rattrapage (oeuvres de rattrapage()).
 * @param string $layout  section (accueil) | carte (compte, carte isolée).
 * @param array  $reprise Carte de la dernière lecture en tête (bloc de l'accueil) :
 *                        {position, ligne} de donnees_accueil() ; vide : aucune.
 */
function rendu_cartes( array $lignes, string $layout, array $reprise = array() ): string {
	$layout = 'carte' === $layout ? 'carte' : 'section';
	$items  = '';
	$ids    = array();
	foreach ( $lignes as $ligne ) {
		if ( is_array( $ligne ) && isset( $ligne['oeuvre_id'], $ligne['prochain']['id'] ) ) {
			$ids[] = (int) $ligne['prochain']['id'];
			$ids[] = (int) ( $ligne['tome']['id'] ?? 0 );
		}
	}
	$position = isset( $reprise['position']['oeuvre_id'], $reprise['position']['chapitre_id'] ) ? (array) $reprise['position'] : array();
	if ( $position ) {
		$ids[] = (int) $position['chapitre_id'];
	}
	if ( ! $ids ) {
		return '';
	}
	_prime_post_caches( array_values( array_unique( array_filter( $ids ) ) ), false, true );
	if ( $position ) {
		$items .= rendu_carte_reprise( $position, is_array( $reprise['ligne'] ?? null ) ? $reprise['ligne'] : null );
	}
	foreach ( $lignes as $ligne ) {
		if ( is_array( $ligne ) && isset( $ligne['oeuvre_id'], $ligne['prochain']['id'] ) ) {
			$items .= rendu_carte( $ligne, $layout );
		}
	}
	wp_enqueue_style( 'yume-rattrapage' );
	return '<ul class="yn-rattrapage__liste yn-rattrapage__liste--' . esc_attr( $layout ) . '">' . $items . '</ul>';
}

/**
 * Rendu du bloc yume/rattrapage, « Reprendre la lecture » : la dernière lecture du membre puis
 * ses séries à rattraper (donnees_accueil()). Visiteur, rattrapage coupé ou aucune lecture :
 * chaîne vide (la section de l'accueil disparaît ; aucune donnée ni gabarit dans une page
 * anonyme). La dernière lecture affichée est notée pour le bandeau du bas de l'accueil.
 *
 * @param array $attributes Attributs : count (1 à LIMITE_BLOC, NOMBRE_BLOC), layout (section | carte).
 */
function rendu_bloc( array $attributes = array() ): string {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || ! actif() ) {
		return '';
	}
	$layout  = 'carte' === ( $attributes['layout'] ?? '' ) ? 'carte' : 'section';
	$nombre  = isset( $attributes['count'] ) && is_numeric( $attributes['count'] ) ? (int) $attributes['count'] : NOMBRE_BLOC;
	$donnees = donnees_accueil( $user_id, $nombre );
	$cartes  = rendu_cartes( $donnees['oeuvres'], $layout, (array) $donnees['reprise'] );
	if ( '' === $cartes ) {
		if ( apercu_editeur() ) {
			return '<div ' . attributs_racine( 'yn-rattrapage yn-apercu-editeur' ) . '><p class="yn-muted">'
				. esc_html__( 'Reprendre la lecture : dernière lecture et nouveaux chapitres, affichés pour un lecteur connecté qui a commencé une série (rien pour les visiteurs).', 'yume-core' )
				. '</p></div>';
		}
		return '';
	}
	if ( $donnees['reprise'] ) {
		noter_reprise_affichee( $user_id, (int) $donnees['reprise']['position']['oeuvre_id'] );
	}
	$id_titre = wp_unique_id( 'yn-rattrapage-titre-' );
	$titre    = __( 'Reprendre la lecture', 'yume-core' );
	$classe   = 'yn-rattrapage yn-rattrapage--' . $layout . ( 'carte' === $layout ? ' yn-card' : ' yn-section' );
	$plus     = '';
	if ( $donnees['autres'] > 0 ) {
		$autres = (int) $donnees['autres'];
		/* translators: %d : nombre de séries à rattraper non affichées. */
		$plus = '<p class="yn-rattrapage__plus"><a href="' . esc_url( url_lectures( ANCRE ) ) . '">' . esc_html( sprintf( _n( 'Et %d autre série à rattraper', 'Et %d autres séries à rattraper', $autres, 'yume-core' ), $autres ) ) . ' <span aria-hidden="true">→</span></a></p>';
	}
	return '<section ' . attributs_racine( $classe, array( 'aria-labelledby' => $id_titre ) ) . '>'
		. '<div class="yn-rattrapage__tete">'
		. '<h2 class="' . ( 'carte' === $layout ? 'yn-rattrapage__titre' : 'yn-section__titre yn-rattrapage__titre' ) . '" id="' . esc_attr( $id_titre ) . '">' . esc_html( $titre ) . '</h2>'
		. '<p class="yn-section__lien yn-rattrapage__lien"><a href="' . esc_url( url_lectures() ) . '">' . esc_html__( 'Toutes mes lectures', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>'
		. '</div>'
		. $cartes
		. $plus
		. '</section>';
}
