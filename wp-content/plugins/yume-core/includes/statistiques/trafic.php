<?php
/**
 * Statistiques, trafic : adaptateur Jetpack (WordPress.com Stats).
 *
 * Source du trafic (source_trafic()) :
 * - « jetpack » : la classe Automattic\Jetpack\Stats\WPCOM_Stats (paquet automattic/jetpack-stats,
 *   présent avec Jetpack sur WordPress.com / Atomic) existe et le site a un identifiant WordPress.com
 *   et un jeton (jetpack_disponible()) ;
 * - « simule » : Jetpack indisponible HORS production (wp_get_environment_type() ≠ « production ») :
 *   données de démonstration déterministes (trafic-simule.php), toujours étiquetées « simule » ;
 * - « aucune » : Jetpack indisponible en production. Toutes les fonctions trafic_*() renvoient null.
 *
 * Toutes les lectures passent par requete_stats( $ressource, $args ) :
 * 1. cache yume_stats_trafic_<md5(source|ressource|args)> : 15 minutes (2 minutes pour une erreur) ;
 * 2. filtre yume_statistiques_requete_stats( null, $ressource, $args ) : une valeur non nulle
 *    (charge utile brute de l'API ou WP_Error) court-circuite Jetpack (tests, simulation) ;
 * 3. appel de la méthode WPCOM_Stats correspondante, enveloppé (is_wp_error, try/catch \Throwable).
 * Les réponses brutes sont ensuite normalisées en tableaux PHP simples par les fonctions trafic_*().
 *
 * Vérifié sur le code source de Jetpack (projects/packages/stats/src/class-wpcom-stats.php, 2026) :
 * méthodes get_visits(), get_top_posts(), get_referrers(), get_search_terms(),
 * get_views_by_country(), get_post_views( $post_id, $args ), get_stats_summary() ; chemin
 * /sites/{Jetpack_Options::get_option('id')}/stats/{ressource} (API REST v1.1), arguments passés
 * tels quels en http_build_query(), requête signée par le jeton du site
 * (Client::wpcom_json_api_request_as_blog, aucune capacité view_stats requise), réponse JSON
 * décodée en tableau associatif, WP_Error sinon (jeton absent ou invalide : « site_not_connected »),
 * cache Jetpack de 5 minutes (clé « cached_at » ajoutée aux réponses servies par ce cache).
 *
 * Formats de réponse (normaliseurs de Calypso, client/state/stats/lists/utils.js, et bloc « Top
 * Posts » de Jetpack) : avec summarize=1, les listes sont sous « summary », sinon sous
 * « days[date] » (les deux sont lus) :
 * - visits        : { fields: ['period','views','visitors',…], data: [[période, vues, visiteurs,…]], unit }
 * - top-posts     : summary.postviews[ { id, title, type, views, href, public } ] (+ total_views ?)
 * - referrers     : summary.groups[ { name, url, icon, total, results[ { name, url, views, children } ] } ]
 * - search-terms  : summary.search_terms[ { term, views } ] (+ encrypted_search_terms ?)
 * - country-views : summary.views[ { country_code, views } ] + « country-info »[code].country_full
 * - post/{id}     : { views, data: [[ 'Y-m-d', vues ], …] } (tout l'historique du contenu)
 *
 * INCERTAIN (à vérifier sur yumenovel.fr, impossible à tester hors WordPress.com) :
 * - bornes de « max » (top-posts : 30 dans le bloc Top Posts de Jetpack ; 100 au moins demandé ici
 *   pour toutes les listes, coupées localement ; l'API peut renvoyer plus d'entrées que demandé, et
 *   « max » n'est peut-être pas pris en compte par country-views ou search-terms), de « num »
 *   (365 pour la période d'un an) et de « quantity » (visits : 180 jours ou 24 mois) ;
 * - « date » : dernier jour inclus, au fuseau du site (hypothèse) ;
 * - « stat_fields » de visits (« views,visitors ») : si ignoré, les colonnes sont lues par leur nom
 *   dans « fields », quelle que soit leur position ;
 * - format de la période mensuelle de visits (« Y-m-d » ou « Y-m » : les deux sont acceptés) ;
 * - présence et nom des totaux « total_views » (top-posts, referrers), « encrypted_search_terms »
 *   (search-terms) : à défaut, totaux recalculés par somme des lignes reçues ;
 * - forme de post/{id} quand le contenu n'a aucune vue (data vide supposé).
 * Toute correction se fait dans ce fichier seul (normaliseurs testés sur des charges utiles brutes).
 *
 * Pays : seule la ressource country-views est demandée (jamais location-views, jamais de ville).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Sources possibles du trafic. */
const TRAFIC_SOURCES = array( 'jetpack', 'simule', 'aucune' );

/** Durée du cache d'une réponse de l'API, en secondes (15 minutes). */
const TRAFIC_CACHE_DUREE = 900;

/** Durée du cache d'une erreur de l'API, en secondes (2 minutes). */
const TRAFIC_CACHE_DUREE_ERREUR = 120;

/**
 * Lignes demandées au minimum aux listes de l'API (top-posts, referrers, search-terms, country-views) :
 * le top affiché est coupé localement, et les totaux (regroupement par œuvre, « autres ») portent
 * sur toutes les lignes reçues. Une seule réponse en cache sert aussi tous les « max » ≤ 100.
 */
const TRAFIC_LIGNES_API = 100;

/** Au-delà de ce nombre de jours, la série de visits est mensuelle. */
const TRAFIC_JOURS_SERIE_MAX = 90;

/** Mois de la série mensuelle (période d'un an), comparés aux mêmes mois de l'année précédente. */
const TRAFIC_MOIS = 12;

/** Codes pays inconnus ou génériques de l'API (proxy anonyme, satellite, inconnu) : « autres ». */
const TRAFIC_PAYS_INCONNUS = array( 'A1', 'A2', 'ZZ' );

/** Ressource de l'API => méthode de WPCOM_Stats (post/{id} : get_post_views()). */
const TRAFIC_METHODES = array(
	'visits'        => 'get_visits',
	'top-posts'     => 'get_top_posts',
	'referrers'     => 'get_referrers',
	'search-terms'  => 'get_search_terms',
	'country-views' => 'get_views_by_country',
	'summary'       => 'get_stats_summary',
);

/** Type de contenu WordPress => type affiché dans les contenus les plus vus. */
const TRAFIC_TYPES = array(
	'yume_oeuvre'   => 'oeuvre',
	'yume_tome'     => 'tome',
	'yume_chapitre' => 'chapitre',
	'page'          => 'page',
	'post'          => 'article',
);

/*
 * -----------------------------------------------------------------------------
 * Source du trafic
 * -----------------------------------------------------------------------------
 */

/**
 * Jetpack Stats est-il utilisable ? Classe WPCOM_Stats présente, identifiant WordPress.com du site
 * (utilisé par WPCOM_Stats pour construire le chemin de l'API) et connexion active (jeton du site,
 * Connection\Manager::is_connected() quand la classe existe).
 */
function jetpack_disponible(): bool {
	try {
		if ( ! class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) || ! class_exists( '\Jetpack_Options' ) ) {
			return false;
		}
		if ( (int) \Jetpack_Options::get_option( 'id' ) <= 0 ) {
			return false;
		}
		if ( class_exists( '\Automattic\Jetpack\Connection\Manager' ) ) {
			$connexion = new \Automattic\Jetpack\Connection\Manager();
			if ( method_exists( $connexion, 'is_connected' ) ) {
				return (bool) $connexion->is_connected();
			}
		}
		return true;
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Source choisie d'après l'état de Jetpack et l'environnement (sans filtre).
 *
 * @param bool   $jetpack       Jetpack Stats utilisable.
 * @param string $environnement wp_get_environment_type().
 */
function trafic_source_choisie( bool $jetpack, string $environnement ): string {
	if ( $jetpack ) {
		return 'jetpack';
	}
	return 'production' === $environnement ? 'aucune' : 'simule';
}

/**
 * Source finale après le filtre : valeur inconnue → « aucune » ; jamais de simulation en
 * production, même forcée par le filtre.
 *
 * @param mixed  $source        Valeur filtrée.
 * @param string $environnement wp_get_environment_type().
 */
function trafic_source_finale( $source, string $environnement ): string {
	if ( ! is_string( $source ) || ! in_array( $source, TRAFIC_SOURCES, true ) ) {
		return 'aucune';
	}
	return ( 'simule' === $source && 'production' === $environnement ) ? 'aucune' : $source;
}

/**
 * Source du trafic : « jetpack », « simule » (hors production, Jetpack indisponible) ou « aucune ».
 */
function source_trafic(): string {
	$environnement = wp_get_environment_type();
	$jetpack       = jetpack_disponible();
	/**
	 * Filtre la source du trafic des statistiques (tests, recette).
	 *
	 * Une valeur hors de « jetpack », « simule », « aucune » donne « aucune » ; « simule » est
	 * refusé en production.
	 *
	 * @param string $source        Source choisie.
	 * @param bool   $jetpack       Jetpack Stats utilisable.
	 * @param string $environnement Type d'environnement (wp_get_environment_type()).
	 */
	$source = apply_filters( 'yume_statistiques_source_trafic', trafic_source_choisie( $jetpack, $environnement ), $jetpack, $environnement );
	return trafic_source_finale( $source, $environnement );
}

/*
 * -----------------------------------------------------------------------------
 * Requêtes (cache, filtre, Jetpack)
 * -----------------------------------------------------------------------------
 */

/**
 * État du trafic pour la requête HTTP courante : dernière erreur rencontrée.
 *
 * @param array|null $maj Valeurs à mettre à jour (clé « erreur »).
 * @return array{erreur:string}
 */
function trafic_etat( ?array $maj = null ): array {
	static $etat = array( 'erreur' => '' );
	if ( null !== $maj ) {
		$etat = array_merge( $etat, array_intersect_key( $maj, $etat ) );
	}
	return $etat;
}

/**
 * Message de la dernière erreur de l'API (WP_Error) rencontrée pendant la requête HTTP, ou ''.
 * Contient des détails techniques : à n'afficher qu'aux administrateurs.
 */
function derniere_erreur_trafic(): string {
	return (string) trafic_etat()['erreur'];
}

/**
 * Clé de cache d'une requête (la source en fait partie : un passage de la simulation à Jetpack ne
 * sert jamais de données simulées).
 *
 * @param string $source    Source.
 * @param string $ressource Ressource.
 * @param array  $args      Arguments.
 */
function trafic_cle_cache( string $source, string $ressource, array $args ): string {
	return 'trafic_' . md5( $source . '|' . $ressource . '|' . wp_json_encode( $args ) );
}

/**
 * Message d'erreur lisible et borné.
 *
 * @param \WP_Error $erreur Erreur.
 */
function trafic_message_erreur( \WP_Error $erreur ): string {
	$message = trim( wp_strip_all_tags( (string) $erreur->get_error_message() ) );
	if ( '' === $message ) {
		$message = (string) $erreur->get_error_code();
	}
	return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 300 ) : substr( $message, 0, 300 );
}

/**
 * Vérifie une réponse brute : tableau associatif, ou WP_Error. Un objet est converti en tableau ;
 * un tableau d'erreur de l'API ({ error, message }) devient une WP_Error.
 *
 * @param mixed $reponse Réponse.
 * @return array|\WP_Error
 */
function trafic_valider_reponse( $reponse ) {
	if ( is_wp_error( $reponse ) ) {
		return $reponse;
	}
	if ( is_object( $reponse ) ) {
		$reponse = json_decode( (string) wp_json_encode( $reponse ), true );
	}
	if ( ! is_array( $reponse ) ) {
		return new \WP_Error( 'yume_trafic_reponse_invalide', __( 'Réponse inattendue de WordPress.com Stats.', 'yume-core' ) );
	}
	if ( isset( $reponse['error'] ) && is_string( $reponse['error'] ) && ! isset( $reponse['summary'] ) && ! isset( $reponse['data'] ) && ! isset( $reponse['days'] ) ) {
		$message = isset( $reponse['message'] ) && is_string( $reponse['message'] ) ? $reponse['message'] : $reponse['error'];
		return new \WP_Error( 'yume_trafic_' . sanitize_key( $reponse['error'] ), $message );
	}
	return $reponse;
}

/**
 * Appelle la méthode WPCOM_Stats d'une ressource. Aucune exception ne sort : WP_Error à la place.
 *
 * @param string      $ressource Ressource (« visits », « top-posts », « post/123 »…).
 * @param array       $args      Arguments de l'API.
 * @param object|null $client    Client (tests) ; null : new WPCOM_Stats().
 * @return mixed Réponse brute de Jetpack (tableau, WP_Error…).
 */
function trafic_appeler_jetpack( string $ressource, array $args, ?object $client = null ) {
	try {
		if ( preg_match( '#^post/(\d+)$#', $ressource, $m ) ) {
			$methode    = 'get_post_views';
			$parametres = array( (int) $m[1], $args );
		} elseif ( isset( TRAFIC_METHODES[ $ressource ] ) ) {
			$methode    = TRAFIC_METHODES[ $ressource ];
			$parametres = array( $args );
		} else {
			return new \WP_Error( 'yume_trafic_ressource_inconnue', __( 'Ressource de statistiques inconnue.', 'yume-core' ) );
		}
		if ( null === $client ) {
			$classe = '\Automattic\Jetpack\Stats\WPCOM_Stats';
			if ( ! class_exists( $classe ) ) {
				return new \WP_Error( 'yume_trafic_jetpack_absent', __( 'Jetpack Stats n’est pas installé sur ce site.', 'yume-core' ) );
			}
			$client = new $classe();
		}
		if ( ! method_exists( $client, $methode ) ) {
			return new \WP_Error(
				'yume_trafic_methode_absente',
				/* translators: %s: nom de la méthode Jetpack. */
				sprintf( __( 'Méthode Jetpack absente : %s.', 'yume-core' ), $methode )
			);
		}
		return call_user_func_array( array( $client, $methode ), $parametres );
	} catch ( \Throwable $e ) {
		return new \WP_Error(
			'yume_trafic_exception',
			/* translators: %s: message de l'exception. */
			sprintf( __( 'Erreur de Jetpack Stats : %s', 'yume-core' ), $e->getMessage() )
		);
	}
}

/**
 * Requête à l'API avec son horodatage de calcul (cache, filtre, Jetpack).
 *
 * @param string $ressource Ressource.
 * @param array  $args      Arguments.
 * @return array{resultat:array|\WP_Error,calcule:int}
 */
function trafic_requete( string $ressource, array $args ): array {
	$source = source_trafic();
	$cle    = trafic_cle_cache( $source, $ressource, $args );
	$cache  = cache_lire( $cle );
	if ( is_array( $cache ) && isset( $cache['calcule'] ) ) {
		if ( isset( $cache['erreur'] ) ) {
			$erreur = new \WP_Error( (string) ( $cache['code'] ?? 'yume_trafic_erreur' ), (string) $cache['erreur'] );
			trafic_etat( array( 'erreur' => (string) $cache['erreur'] ) );
			return array(
				'resultat' => $erreur,
				'calcule'  => (int) $cache['calcule'],
			);
		}
		if ( isset( $cache['donnees'] ) && is_array( $cache['donnees'] ) ) {
			return array(
				'resultat' => $cache['donnees'],
				'calcule'  => (int) $cache['calcule'],
			);
		}
	}

	/**
	 * Court-circuite une requête à WordPress.com Stats (tests, simulation hors production).
	 *
	 * Renvoyer la charge utile brute de l'API (tableau au format de l'API REST v1.1) ou une
	 * WP_Error ; null laisse passer la requête vers Jetpack.
	 *
	 * @param null|array|\WP_Error $pre       Null par défaut.
	 * @param string               $ressource Ressource (« visits », « top-posts », « post/123 »…).
	 * @param array                $args      Arguments de l'API.
	 */
	$pre = apply_filters( 'yume_statistiques_requete_stats', null, $ressource, $args );
	if ( null !== $pre ) {
		$resultat = $pre;
	} elseif ( 'jetpack' === $source ) {
		$resultat = trafic_appeler_jetpack( $ressource, $args );
	} else {
		$resultat = new \WP_Error( 'yume_trafic_indisponible', __( 'Trafic indisponible : Jetpack n’est pas connecté sur ce site.', 'yume-core' ) );
	}
	$resultat = trafic_valider_reponse( $resultat );
	$calcule  = maintenant();
	if ( is_wp_error( $resultat ) ) {
		$message = trafic_message_erreur( $resultat );
		trafic_etat( array( 'erreur' => $message ) );
		cache_ecrire(
			$cle,
			array(
				'erreur'  => $message,
				'code'    => (string) $resultat->get_error_code(),
				'calcule' => $calcule,
			),
			TRAFIC_CACHE_DUREE_ERREUR
		);
	} else {
		cache_ecrire(
			$cle,
			array(
				'donnees' => $resultat,
				'calcule' => $calcule,
			),
			TRAFIC_CACHE_DUREE
		);
	}
	return array(
		'resultat' => $resultat,
		'calcule'  => $calcule,
	);
}

/**
 * Requête à WordPress.com Stats (ou à la simulation), avec cache.
 *
 * @param string $ressource Ressource : visits, top-posts, referrers, search-terms, country-views,
 *                          summary ou post/{id}.
 * @param array  $args      Arguments de l'API (period, date, num, max, summarize, unit, quantity…).
 * @return array|\WP_Error Charge utile brute (tableau associatif) ou erreur.
 */
function requete_stats( string $ressource, array $args ) {
	return trafic_requete( $ressource, $args )['resultat'];
}

/*
 * -----------------------------------------------------------------------------
 * Aides de normalisation
 * -----------------------------------------------------------------------------
 */

/**
 * Texte brut d'une valeur de l'API (balises retirées, entités décodées).
 *
 * @param mixed $valeur Valeur.
 */
function trafic_texte( $valeur ): string {
	if ( ! is_scalar( $valeur ) ) {
		return '';
	}
	return trim( wp_strip_all_tags( html_entity_decode( (string) $valeur, ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Entier positif d'une valeur de l'API (0 sinon).
 *
 * @param mixed $valeur Valeur.
 */
function trafic_entier( $valeur ): int {
	return is_numeric( $valeur ) ? max( 0, (int) $valeur ) : 0;
}

/**
 * Adresse http(s) d'une valeur de l'API, ou ''.
 *
 * @param mixed $valeur Valeur.
 */
function trafic_url( $valeur ): string {
	if ( ! is_string( $valeur ) || '' === $valeur ) {
		return '';
	}
	return (string) esc_url_raw( $valeur, array( 'http', 'https' ) );
}

/**
 * Jour « Y-m-d » (ou premier jour du mois « Y-m-01 » pour l'unité « mois ») d'une période de
 * l'API, ou '' si elle n'est pas reconnue.
 *
 * @param mixed  $periode Période (« 2026-09-15 », « 2026-09-01 », « 2026-09 »).
 * @param string $unite   « jour » ou « mois ».
 */
function trafic_date( $periode, string $unite = 'jour' ): string {
	if ( ! is_string( $periode ) || ! preg_match( '/^(\d{4})-(\d{2})(?:-(\d{2}))?/', $periode, $m ) ) {
		return '';
	}
	if ( 'mois' === $unite ) {
		return checkdate( (int) $m[2], 1, (int) $m[1] ) ? $m[1] . '-' . $m[2] . '-01' : '';
	}
	if ( empty( $m[3] ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return '';
	}
	return $m[1] . '-' . $m[2] . '-' . $m[3];
}

/**
 * Jours « Y-m-d » de $debut à $fin inclus.
 *
 * @param string $debut Premier jour.
 * @param string $fin   Dernier jour.
 * @return string[]
 */
function trafic_jours( string $debut, string $fin ): array {
	$utc   = new \DateTimeZone( 'UTC' );
	$jour  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $debut, $utc );
	$der   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fin, $utc );
	$jours = array();
	if ( ! $jour || ! $der ) {
		return $jours;
	}
	// Garde-fou : 1 000 jours au plus.
	for ( $n = 0; $jour <= $der && $n < 1000; $n++ ) {
		$jours[] = $jour->format( 'Y-m-d' );
		$jour    = $jour->modify( '+1 day' );
	}
	return $jours;
}

/**
 * Premiers jours des $nombre mois se terminant par le mois de $fin, du plus ancien au plus récent.
 *
 * @param string $fin    Jour du dernier mois.
 * @param int    $nombre Nombre de mois.
 * @return string[] « Y-m-01 ».
 */
function trafic_mois( string $fin, int $nombre ): array {
	$mois = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $fin, 0, 7 ) . '-01', new \DateTimeZone( 'UTC' ) );
	if ( ! $mois ) {
		return array();
	}
	$liste = array();
	for ( $i = $nombre - 1; $i >= 0; $i-- ) {
		$liste[] = $mois->modify( '-' . $i . ' months' )->format( 'Y-m-d' );
	}
	return $liste;
}

/**
 * Jour valide « Y-m-d » ?
 *
 * @param string $jour Jour.
 */
function trafic_jour_valide( string $jour ): bool {
	return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $jour ) && trafic_date( $jour ) === $jour;
}

/**
 * Liste d'une réponse « résumée » : summary[$cle] (summarize=1), sinon concaténation des
 * days[date][$cle] (lignes à additionner par le normaliseur).
 *
 * @param array  $donnees Réponse brute.
 * @param string $cle     Clé de la liste (postviews, groups, search_terms, views).
 * @return array
 */
function trafic_liste_resume( array $donnees, string $cle ): array {
	if ( isset( $donnees['summary'] ) && is_array( $donnees['summary'] ) && isset( $donnees['summary'][ $cle ] ) && is_array( $donnees['summary'][ $cle ] ) ) {
		return $donnees['summary'][ $cle ];
	}
	$liste = array();
	if ( isset( $donnees['days'] ) && is_array( $donnees['days'] ) ) {
		foreach ( $donnees['days'] as $jour ) {
			if ( is_array( $jour ) && isset( $jour[ $cle ] ) && is_array( $jour[ $cle ] ) ) {
				$liste = array_merge( $liste, array_values( $jour[ $cle ] ) );
			}
		}
	}
	return $liste;
}

/**
 * Total numérique d'une réponse résumée (summary[$cle], sinon somme des days[date][$cle]), ou
 * null s'il est absent.
 *
 * @param array  $donnees Réponse brute.
 * @param string $cle     Clé du total (total_views, encrypted_search_terms…).
 */
function trafic_total_resume( array $donnees, string $cle ): ?int {
	if ( isset( $donnees['summary'] ) && is_array( $donnees['summary'] ) && isset( $donnees['summary'][ $cle ] ) && is_numeric( $donnees['summary'][ $cle ] ) ) {
		return max( 0, (int) $donnees['summary'][ $cle ] );
	}
	$total = null;
	if ( isset( $donnees['days'] ) && is_array( $donnees['days'] ) ) {
		foreach ( $donnees['days'] as $jour ) {
			if ( is_array( $jour ) && isset( $jour[ $cle ] ) && is_numeric( $jour[ $cle ] ) ) {
				$total = (int) $total + max( 0, (int) $jour[ $cle ] );
			}
		}
	}
	return $total;
}

/**
 * Lignes de visits : date (jour, ou premier jour du mois) => { vues, visiteurs }. Les colonnes
 * sont repérées par leur nom dans « fields ». Null si la réponse n'a pas de colonne « period ».
 *
 * @param array  $donnees Réponse brute de visits.
 * @param string $unite   « jour » ou « mois ».
 * @return array<string,array{vues:int,visiteurs:int}>|null
 */
function trafic_lignes_visites( array $donnees, string $unite ): ?array {
	$champs = $donnees['fields'] ?? null;
	$lignes = $donnees['data'] ?? null;
	if ( ! is_array( $champs ) || ! is_array( $lignes ) ) {
		return null;
	}
	$champs    = array_values( $champs );
	$i_periode = array_search( 'period', $champs, true );
	$i_vues    = array_search( 'views', $champs, true );
	$i_visites = array_search( 'visitors', $champs, true );
	if ( false === $i_periode ) {
		return null;
	}
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$ligne = array_values( $ligne );
		$date  = trafic_date( $ligne[ $i_periode ] ?? null, $unite );
		if ( '' === $date ) {
			continue;
		}
		$resultat[ $date ] = $resultat[ $date ] ?? array(
			'vues'      => 0,
			'visiteurs' => 0,
		);
		if ( false !== $i_vues ) {
			$resultat[ $date ]['vues'] += trafic_entier( $ligne[ $i_vues ] ?? 0 );
		}
		if ( false !== $i_visites ) {
			$resultat[ $date ]['visiteurs'] += trafic_entier( $ligne[ $i_visites ] ?? 0 );
		}
	}
	return $resultat;
}

/**
 * Contenus de top-posts normalisés, fusionnés par contenu et triés par vues décroissantes : titre
 * et adresse locaux quand le contenu existe (ceux de l'API peuvent être périmés), type, œuvre.
 *
 * @param array $liste Lignes brutes (postviews).
 * @return array<int,array{post_id:int,titre:string,url:string,type:string,oeuvre_id:int,vues:int}>
 */
function trafic_normaliser_contenus( array $liste ): array {
	$contenus = array();
	foreach ( $liste as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$post_id = trafic_entier( $item['id'] ?? 0 );
		$url     = trafic_url( $item['href'] ?? '' );
		$cle     = $post_id > 0 ? 'p' . $post_id : 'u' . $url;
		$vues    = trafic_entier( $item['views'] ?? 0 );
		if ( isset( $contenus[ $cle ] ) ) {
			$contenus[ $cle ]['vues'] += $vues;
			continue;
		}
		$contenus[ $cle ] = array(
			'post_id'   => $post_id,
			'titre'     => trafic_texte( $item['title'] ?? '' ),
			'url'       => $url,
			'type'      => 'autre',
			'oeuvre_id' => 0,
			'vues'      => $vues,
		);
	}
	$ids = array_values( array_filter( array_column( $contenus, 'post_id' ) ) );
	if ( $ids && function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $ids, false, true );
	}
	foreach ( $contenus as $cle => $contenu ) {
		if ( 0 === $contenu['post_id'] ) {
			// Page d'accueil et archives (type « homepage » de l'API).
			$contenus[ $cle ]['titre'] = '' !== $contenu['titre'] ? $contenu['titre'] : __( 'Accueil et archives', 'yume-core' );
			$contenus[ $cle ]['url']   = '' !== $contenu['url'] ? $contenu['url'] : home_url( '/' );
			continue;
		}
		$post = get_post( $contenu['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			continue; // Contenu supprimé : titre et adresse de l'API.
		}
		$titre                         = trafic_texte( $post->post_title );
		$contenus[ $cle ]['titre']     = '' !== $titre ? $titre : $contenu['titre'];
		$contenus[ $cle ]['type']      = TRAFIC_TYPES[ $post->post_type ] ?? 'autre';
		$contenus[ $cle ]['oeuvre_id'] = oeuvre_de( $contenu['post_id'] );
		if ( 'publish' === $post->post_status ) {
			$lien                    = get_permalink( $post );
			$contenus[ $cle ]['url'] = is_string( $lien ) ? $lien : $contenu['url'];
		}
	}
	$contenus = array_values( $contenus );
	usort(
		$contenus,
		static fn( array $a, array $b ): int => array( $b['vues'], $a['titre'] ) <=> array( $a['vues'], $b['titre'] )
	);
	return $contenus;
}

/**
 * Vues regroupées par œuvre (fiche, tomes, chapitres et articles liés), triées par vues.
 *
 * @param array $contenus Contenus normalisés.
 * @return array<int,array{oeuvre_id:int,titre:string,url:string,vues:int}>
 */
function trafic_regrouper_oeuvres( array $contenus ): array {
	$publiees = oeuvres_publiees();
	$oeuvres  = array();
	foreach ( $contenus as $contenu ) {
		$oeuvre_id = (int) $contenu['oeuvre_id'];
		if ( $oeuvre_id <= 0 ) {
			continue;
		}
		if ( ! isset( $oeuvres[ $oeuvre_id ] ) ) {
			$lien                  = get_permalink( $oeuvre_id );
			$oeuvres[ $oeuvre_id ] = array(
				'oeuvre_id' => $oeuvre_id,
				'titre'     => $publiees[ $oeuvre_id ] ?? trafic_texte( get_post_field( 'post_title', $oeuvre_id ) ),
				'url'       => is_string( $lien ) ? $lien : '',
				'vues'      => 0,
			);
		}
		$oeuvres[ $oeuvre_id ]['vues'] += (int) $contenu['vues'];
	}
	$oeuvres = array_values( $oeuvres );
	usort(
		$oeuvres,
		static fn( array $a, array $b ): int => array( $b['vues'], $a['titre'] ) <=> array( $a['vues'], $b['titre'] )
	);
	return $oeuvres;
}

/**
 * Vues d'un référent (groupe de l'API) : « total » s'il est fourni, sinon somme de ses
 * « results » (liste de { views, children } ou ancien format { nom: vues }).
 *
 * @param mixed $resultats Résultats du groupe.
 */
function trafic_somme_resultats( $resultats ): int {
	if ( ! is_array( $resultats ) ) {
		return trafic_entier( $resultats );
	}
	$somme = 0;
	foreach ( $resultats as $resultat ) {
		if ( is_array( $resultat ) ) {
			$somme += isset( $resultat['views'] ) ? trafic_entier( $resultat['views'] ) : trafic_somme_resultats( $resultat['children'] ?? array() );
		} else {
			$somme += trafic_entier( $resultat );
		}
	}
	return $somme;
}

/**
 * Nom d'un pays : nom localisé (extension intl) si disponible, sinon celui de l'API (anglais),
 * sinon le code.
 *
 * @param string $code    Code ISO 3166-1 alpha-2.
 * @param string $nom_api Nom fourni par l'API (country-info.country_full).
 */
function trafic_nom_pays( string $code, string $nom_api ): string {
	if ( class_exists( '\Locale' ) && ! in_array( $code, TRAFIC_PAYS_INCONNUS, true ) ) {
		$nom = (string) \Locale::getDisplayRegion( 'und-' . $code, get_locale() );
		if ( '' !== $nom && $code !== $nom ) {
			return $nom;
		}
	}
	return '' !== $nom_api ? $nom_api : $code;
}

/**
 * Arguments communs des listes résumées sur une période (top-posts, referrers, search-terms,
 * country-views) : jours se terminant aujourd'hui (heure du site).
 *
 * @param int $jours Jours.
 * @param int $max   Lignes voulues (au moins TRAFIC_LIGNES_API sont demandées).
 * @return array<string,mixed>
 */
function trafic_args_liste( int $jours, int $max ): array {
	$jours = max( 1, $jours );
	return array(
		'period'    => 'day',
		'num'       => $jours,
		'date'      => bornes_periode( $jours )['fin'],
		'summarize' => 1,
		'max'       => max( TRAFIC_LIGNES_API, $max ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Données de trafic (contrats §3.4)
 * -----------------------------------------------------------------------------
 */

/**
 * Vues et visiteurs de la période et de la période précédente, avec la série par jour (7, 30 ou
 * 90 jours) ou par mois (au-delà : les 12 derniers mois, mois courant inclus, comparés aux 12
 * précédents). Null si le trafic est indisponible.
 *
 * @param int $jours Période en jours.
 * @return array{source:string,unite:string,vues:int,visiteurs:int,vues_prec:int,visiteurs_prec:int,variation_vues:?float,variation_visiteurs:?float,serie:array,calcule:int}|null
 */
function trafic_resume( int $jours ): ?array {
	$source = source_trafic();
	if ( 'aucune' === $source ) {
		return null;
	}
	$jours   = max( 1, $jours );
	$bornes  = bornes_periode( $jours );
	$mensuel = $jours > TRAFIC_JOURS_SERIE_MAX;
	$args    = array(
		'unit'        => $mensuel ? 'month' : 'day',
		'quantity'    => $mensuel ? 2 * TRAFIC_MOIS : 2 * $jours,
		'date'        => $bornes['fin'],
		'stat_fields' => 'views,visitors',
	);
	$requete = trafic_requete( 'visits', $args );
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$lignes = trafic_lignes_visites( $requete['resultat'], $mensuel ? 'mois' : 'jour' );
	if ( null === $lignes ) {
		trafic_etat( array( 'erreur' => __( 'Réponse inattendue de WordPress.com Stats (visites).', 'yume-core' ) ) );
		return null;
	}
	if ( $mensuel ) {
		$tous      = trafic_mois( $bornes['fin'], 2 * TRAFIC_MOIS );
		$periode   = array_slice( $tous, TRAFIC_MOIS );
		$precedent = array_slice( $tous, 0, TRAFIC_MOIS );
	} else {
		$periode   = trafic_jours( $bornes['debut'], $bornes['fin'] );
		$precedent = trafic_jours( $bornes['debut_prec'], $bornes['fin_prec'] );
	}
	$serie     = array();
	$vues      = 0;
	$visiteurs = 0;
	foreach ( $periode as $date ) {
		$ligne      = $lignes[ $date ] ?? array(
			'vues'      => 0,
			'visiteurs' => 0,
		);
		$serie[]    = array(
			'date'      => $date,
			'vues'      => $ligne['vues'],
			'visiteurs' => $ligne['visiteurs'],
		);
		$vues      += $ligne['vues'];
		$visiteurs += $ligne['visiteurs'];
	}
	$vues_prec      = 0;
	$visiteurs_prec = 0;
	foreach ( $precedent as $date ) {
		$vues_prec      += $lignes[ $date ]['vues'] ?? 0;
		$visiteurs_prec += $lignes[ $date ]['visiteurs'] ?? 0;
	}
	return array(
		'source'              => $source,
		'unite'               => $mensuel ? 'mois' : 'jour',
		'vues'                => $vues,
		'visiteurs'           => $visiteurs,
		'vues_prec'           => $vues_prec,
		'visiteurs_prec'      => $visiteurs_prec,
		'variation_vues'      => variation( $vues, $vues_prec ),
		'variation_visiteurs' => variation( $visiteurs, $visiteurs_prec ),
		'serie'               => $serie,
		'calcule'             => $requete['calcule'],
	);
}

/**
 * Contenus les plus vus de la période (top $max) et vues regroupées par œuvre (calculées sur les
 * TRAFIC_LIGNES_API premiers contenus au moins, voir trafic_args_liste()). autres_vues : vues hors œuvres
 * (accueil, pages, articles non liés, contenus au-delà de ceux reçus) ; total_vues : total de
 * l'API s'il est fourni, sinon somme des contenus reçus. Null si le trafic est indisponible.
 *
 * @param int $jours Période en jours.
 * @param int $max   Contenus renvoyés.
 * @return array{source:string,contenus:array,oeuvres:array,autres_vues:int,total_vues:int}|null
 */
function trafic_contenus( int $jours, int $max = 20 ): ?array {
	$source = source_trafic();
	if ( 'aucune' === $source ) {
		return null;
	}
	$max     = max( 1, $max );
	$requete = trafic_requete( 'top-posts', trafic_args_liste( $jours, $max ) );
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$contenus = trafic_normaliser_contenus( trafic_liste_resume( $requete['resultat'], 'postviews' ) );
	$oeuvres  = trafic_regrouper_oeuvres( $contenus );
	$somme    = (int) array_sum( array_column( $contenus, 'vues' ) );
	$total    = max( $somme, (int) trafic_total_resume( $requete['resultat'], 'total_views' ) );
	return array(
		'source'      => $source,
		'contenus'    => array_slice( $contenus, 0, $max ),
		'oeuvres'     => $oeuvres,
		'autres_vues' => max( 0, $total - (int) array_sum( array_column( $oeuvres, 'vues' ) ) ),
		'total_vues'  => $total,
	);
}

/**
 * Référents de la période : un par groupe de l'API (Google, Discord…), vues = total du groupe
 * (ou somme de ses résultats). Null si le trafic est indisponible.
 *
 * @param int $jours Période en jours.
 * @param int $max   Référents renvoyés.
 * @return array{source:string,referents:array,total:int}|null
 */
function trafic_referents( int $jours, int $max = 10 ): ?array {
	$source = source_trafic();
	if ( 'aucune' === $source ) {
		return null;
	}
	$max     = max( 1, $max );
	$requete = trafic_requete( 'referrers', trafic_args_liste( $jours, $max ) );
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$referents = array();
	foreach ( trafic_liste_resume( $requete['resultat'], 'groups' ) as $groupe ) {
		if ( ! is_array( $groupe ) ) {
			continue;
		}
		$resultats = $groupe['results'] ?? array();
		$nom       = trafic_texte( $groupe['name'] ?? $groupe['group'] ?? '' );
		$url       = trafic_url( $groupe['url'] ?? '' );
		if ( '' === $url && is_array( $resultats ) && 1 === count( $resultats ) ) {
			$unique = reset( $resultats );
			$url    = is_array( $unique ) ? trafic_url( $unique['url'] ?? '' ) : '';
		}
		$vues = isset( $groupe['total'] ) && is_numeric( $groupe['total'] ) ? trafic_entier( $groupe['total'] ) : trafic_somme_resultats( $resultats );
		if ( '' === $nom ) {
			$nom = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : __( 'Inconnu', 'yume-core' );
		}
		if ( isset( $referents[ $nom ] ) ) {
			$referents[ $nom ]['vues'] += $vues;
			continue;
		}
		$referents[ $nom ] = array(
			'nom'  => $nom,
			'url'  => $url,
			'vues' => $vues,
		);
	}
	$referents = array_values( $referents );
	usort(
		$referents,
		static fn( array $a, array $b ): int => array( $b['vues'], $a['nom'] ) <=> array( $a['vues'], $b['nom'] )
	);
	$somme = (int) array_sum( array_column( $referents, 'vues' ) );
	return array(
		'source'    => $source,
		'referents' => array_slice( $referents, 0, $max ),
		'total'     => max( $somme, (int) trafic_total_resume( $requete['resultat'], 'total_views' ) ),
	);
}

/**
 * Termes de recherche de la période. chiffres : vues dont le terme est masqué par le moteur de
 * recherche (encrypted_search_terms) ; total : termes + chiffrés. Null si indisponible.
 *
 * @param int $jours Période en jours.
 * @param int $max   Termes renvoyés.
 * @return array{source:string,termes:array,chiffres:int,total:int}|null
 */
function trafic_recherches( int $jours, int $max = 10 ): ?array {
	$source = source_trafic();
	if ( 'aucune' === $source ) {
		return null;
	}
	$max     = max( 1, $max );
	$requete = trafic_requete( 'search-terms', trafic_args_liste( $jours, $max ) );
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$termes = array();
	foreach ( trafic_liste_resume( $requete['resultat'], 'search_terms' ) as $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$terme = sanitize_text_field( trafic_texte( $ligne['term'] ?? '' ) );
		if ( '' === $terme ) {
			continue;
		}
		$termes[ $terme ] = array(
			'terme' => $terme,
			'vues'  => ( $termes[ $terme ]['vues'] ?? 0 ) + trafic_entier( $ligne['views'] ?? 0 ),
		);
	}
	$termes = array_values( $termes );
	usort(
		$termes,
		static fn( array $a, array $b ): int => array( $b['vues'], $a['terme'] ) <=> array( $a['vues'], $b['terme'] )
	);
	$chiffres = (int) trafic_total_resume( $requete['resultat'], 'encrypted_search_terms' );
	return array(
		'source'   => $source,
		'termes'   => array_slice( $termes, 0, $max ),
		'chiffres' => $chiffres,
		'total'    => (int) array_sum( array_column( $termes, 'vues' ) ) + $chiffres,
	);
}

/**
 * Vues par pays de la période (code ISO, nom, vues ; jamais de ville). autres : vues des pays
 * hors du top et des codes inconnus (A1, A2, ZZ). Null si indisponible.
 *
 * @param int $jours Période en jours.
 * @param int $max   Pays renvoyés.
 * @return array{source:string,pays:array,autres:int,total:int}|null
 */
function trafic_pays( int $jours, int $max = 10 ): ?array {
	$source = source_trafic();
	if ( 'aucune' === $source ) {
		return null;
	}
	$max     = max( 1, $max );
	$requete = trafic_requete( 'country-views', trafic_args_liste( $jours, $max ) );
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$infos    = $requete['resultat']['country-info'] ?? array();
	$infos    = is_array( $infos ) ? $infos : array();
	$pays     = array();
	$inconnus = 0;
	foreach ( trafic_liste_resume( $requete['resultat'], 'views' ) as $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$code = strtoupper( trafic_texte( $ligne['country_code'] ?? '' ) );
		$vues = trafic_entier( $ligne['views'] ?? 0 );
		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) || in_array( $code, TRAFIC_PAYS_INCONNUS, true ) ) {
			$inconnus += $vues;
			continue;
		}
		if ( ! isset( $pays[ $code ] ) ) {
			$info          = isset( $infos[ $code ] ) && is_array( $infos[ $code ] ) ? $infos[ $code ] : array();
			$pays[ $code ] = array(
				'code' => $code,
				'nom'  => trafic_nom_pays( $code, trafic_texte( $info['country_full'] ?? '' ) ),
				'vues' => 0,
			);
		}
		$pays[ $code ]['vues'] += $vues;
	}
	$pays = array_values( $pays );
	usort(
		$pays,
		static fn( array $a, array $b ): int => array( $b['vues'], $a['code'] ) <=> array( $a['vues'], $b['code'] )
	);
	$total = (int) array_sum( array_column( $pays, 'vues' ) ) + $inconnus;
	$top   = array_slice( $pays, 0, $max );
	return array(
		'source' => $source,
		'pays'   => $top,
		'autres' => $total - (int) array_sum( array_column( $top, 'vues' ) ),
		'total'  => $total,
	);
}

/**
 * Vues d'un contenu sur la période, avec la série par jour (jours sans vue à 0). Null si le
 * trafic est indisponible ou le contenu invalide.
 *
 * @param int $post_id Contenu.
 * @param int $jours   Période en jours.
 * @return array{source:string,vues:int,serie:array}|null
 */
function trafic_post( int $post_id, int $jours ): ?array {
	$source = source_trafic();
	if ( 'aucune' === $source || $post_id <= 0 ) {
		return null;
	}
	$requete = trafic_requete( 'post/' . $post_id, array() );
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$par_jour = array();
	$donnees  = $requete['resultat']['data'] ?? array();
	foreach ( is_array( $donnees ) ? $donnees : array() as $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$ligne = array_values( $ligne );
		$date  = trafic_date( $ligne[0] ?? null );
		if ( '' !== $date ) {
			$par_jour[ $date ] = ( $par_jour[ $date ] ?? 0 ) + trafic_entier( $ligne[1] ?? 0 );
		}
	}
	$bornes = bornes_periode( max( 1, $jours ) );
	$serie  = array();
	$vues   = 0;
	foreach ( trafic_jours( $bornes['debut'], $bornes['fin'] ) as $date ) {
		$serie[] = array(
			'date' => $date,
			'vues' => $par_jour[ $date ] ?? 0,
		);
		$vues   += $par_jour[ $date ] ?? 0;
	}
	return array(
		'source' => $source,
		'vues'   => $vues,
		'serie'  => $serie,
	);
}

/**
 * Vues d'un jour par œuvre (top-posts du jour, 100 contenus, regroupés par œuvre), pour
 * l'historique quotidien. Null si le trafic est indisponible ou le jour invalide.
 *
 * @param string $jour Jour « Y-m-d » (heure du site).
 * @return array<int,int>|null oeuvre_id => vues.
 */
function trafic_vues_par_oeuvre_jour( string $jour ): ?array {
	if ( ! trafic_jour_valide( $jour ) || 'aucune' === source_trafic() ) {
		return null;
	}
	$requete = trafic_requete(
		'top-posts',
		array(
			'period'    => 'day',
			'num'       => 1,
			'date'      => $jour,
			'summarize' => 1,
			'max'       => TRAFIC_LIGNES_API,
		)
	);
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$vues = array();
	foreach ( trafic_regrouper_oeuvres( trafic_normaliser_contenus( trafic_liste_resume( $requete['resultat'], 'postviews' ) ) ) as $oeuvre ) {
		$vues[ (int) $oeuvre['oeuvre_id'] ] = (int) $oeuvre['vues'];
	}
	return $vues;
}

/**
 * Vues et visiteurs du site pour un jour (ligne « site » de l'historique). Null si le trafic est
 * indisponible, le jour invalide ou la réponse illisible ; zéros si l'API ne renvoie pas ce jour.
 *
 * @param string $jour Jour « Y-m-d » (heure du site).
 * @return array{vues:int,visiteurs:int}|null
 */
function trafic_visites_jour( string $jour ): ?array {
	if ( ! trafic_jour_valide( $jour ) || 'aucune' === source_trafic() ) {
		return null;
	}
	$requete = trafic_requete(
		'visits',
		array(
			'unit'        => 'day',
			'quantity'    => 1,
			'date'        => $jour,
			'stat_fields' => 'views,visitors',
		)
	);
	if ( is_wp_error( $requete['resultat'] ) ) {
		return null;
	}
	$lignes = trafic_lignes_visites( $requete['resultat'], 'jour' );
	if ( null === $lignes ) {
		return null;
	}
	return $lignes[ $jour ] ?? array(
		'vues'      => 0,
		'visiteurs' => 0,
	);
}
