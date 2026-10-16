<?php
/**
 * Plugin Name:       Yume – prototype MangaDex
 * Description:       Prototype jetable (spike) : lire sur le site des chapitres hébergés sur MangaDex, images relayées par WordPress (aucune image stockée). Mesures dans Outils → Prototype MangaDex. En production : seulement l’essai bref et encadré du README (étape 2).
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Yume Novel
 * License:           GPL-2.0-or-later
 * Text Domain:       yume-core
 *
 * Voir tools/mangadex-prototype/README.md : objectif, contraintes de MangaDex, essai sur un PC
 * puis essai bref en production, protocole de mesure et critères de décision.
 *
 * - Client de l'API MangaDex (User-Agent réel, pas d'en-tête Via, réponses mises en cache dans des
 *   transients) : chapitre, flux d'une série en français, serveur MD@Home d'un chapitre.
 * - Proxy d'images (obligatoire : MangaDex sert une autre image aux sites qui lient ses images) :
 *   GET /wp-json/yume-proto/v1/image/{chapitre}/{empreinte}/{qualite}/{n} (voie « rest ») ou
 *   /?yume_mdx_image={chapitre}/{empreinte}/{qualite}/{n} (voie « front »), pour mesurer laquelle
 *   le cache de WordPress.com garde. Aucune URL libre : le chapitre doit appartenir à une série de
 *   la liste blanche, la page est désignée par son numéro. Chaque image venue d'un nœud MD@Home
 *   est signalée à api.mangadex.network/report, comme MangaDex l'exige.
 * - Lecteur : [yume_mangadex chapitre="uuid"] et [yume_mangadex_serie manga="uuid"].
 *
 * @package Yume\MangadexProto
 */

namespace Yume\MangadexProto;

defined( 'ABSPATH' ) || exit;

/** API publique de MangaDex. */
const API = 'https://api.mangadex.org';

/** Signalement des téléchargements depuis un nœud MD@Home (exigé par MangaDex). */
const RAPPORT = 'https://api.mangadex.network/report';

/** Espace de noms REST du prototype. */
const ESPACE = 'yume-proto/v1';

/** Qualités d'image de MD@Home : originale, compressée. */
const QUALITES = array( 'data', 'data-saver' );

/** Motif d'un identifiant MangaDex. */
const MOTIF_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

/** Durée de cache de l'adresse MD@Home d'un chapitre (MangaDex garantit au moins 15 min). */
const DUREE_AT_HOME = 600;

/** Durée de cache des informations de chapitre et des flux de série. */
const DUREE_INFOS = 3600;

/** Taille maximale d'une image relayée (octets). */
const TAILLE_MAX = 15728640;

/** Nombre de mesures gardées (les plus récentes). */
const MAX_MESURES = 500;

/** Option : identifiants des séries autorisées (liste blanche). */
const OPTION_SERIES = 'yume_mdx_series';

/** Option : dernières mesures du proxy. */
const OPTION_MESURES = 'yume_mdx_mesures';

/** Option : résultats des 50 derniers signalements à MangaDex. */
const OPTION_RAPPORTS = 'yume_mdx_rapports';

/** Types d'image relayés. */
const TYPES_IMAGE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

/*
 * -----------------------------------------------------------------------------
 * Client de l'API MangaDex
 * -----------------------------------------------------------------------------
 */

/**
 * User-Agent réel du site (MangaDex refuse les User-Agent usurpés).
 */
function agent(): string {
	$version = defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : 'proto';
	return 'YumeNovel/' . $version . ' (+' . home_url( '/' ) . '; prototype-mangadex)';
}

/**
 * Identifiant MangaDex valide ?
 *
 * @param mixed $valeur Valeur.
 */
function est_uuid( $valeur ): bool {
	return is_string( $valeur ) && 1 === preg_match( '/^' . MOTIF_UUID . '$/', $valeur );
}

/**
 * GET sur l'API MangaDex, réponse JSON décodée. Paramètres tableau : clé[]=valeur (format de
 * MangaDex) ; les clés déjà entre crochets (order[chapter]) passent telles quelles. Une réponse
 * 429 ou 403 (limite atteinte, adresse bloquée) n'est jamais réessayée.
 *
 * @param string              $chemin     Chemin (/chapter/…).
 * @param array<string,mixed> $parametres Paramètres de requête.
 * @return array<string,mixed>|\WP_Error
 */
function api_get( string $chemin, array $parametres = array() ) {
	$requete = array();
	foreach ( $parametres as $cle => $valeur ) {
		foreach ( (array) $valeur as $element ) {
			$requete[] = rawurlencode( is_array( $valeur ) ? $cle . '[]' : (string) $cle ) . '=' . rawurlencode( (string) $element );
		}
	}
	$url     = API . $chemin . ( $requete ? '?' . implode( '&', $requete ) : '' );
	$reponse = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => agent(),
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $reponse ) ) {
		return new \WP_Error( 'yume_mdx_reseau', 'MangaDex injoignable : ' . $reponse->get_error_message(), array( 'status' => 502 ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $reponse );
	if ( 429 === $code || 403 === $code ) {
		return new \WP_Error( 'yume_mdx_limite', 'MangaDex limite les requêtes de ce serveur (HTTP ' . $code . ') : réessayez dans une minute.', array( 'status' => 503 ) );
	}
	$donnees = json_decode( (string) wp_remote_retrieve_body( $reponse ), true );
	if ( 200 !== $code || ! is_array( $donnees ) || 'ok' !== ( $donnees['result'] ?? '' ) ) {
		$detail = is_array( $donnees ) ? (string) ( $donnees['errors'][0]['detail'] ?? '' ) : '';
		return new \WP_Error( 'yume_mdx_api', 'Réponse inattendue de MangaDex (HTTP ' . $code . ')' . ( '' !== $detail ? ' : ' . $detail : '' ), array( 'status' => 404 === $code ? 404 : 502 ) );
	}
	return $donnees;
}

/**
 * Séries autorisées (liste blanche) : le proxy ne relaie que leurs chapitres, pour que le site
 * ne serve pas de relais gratuit à tout MangaDex (et ne se fasse pas limiter à cause d'autrui).
 *
 * @return string[]
 */
function series_autorisees(): array {
	$series = get_option( OPTION_SERIES, array() );
	return array_values( array_filter( is_array( $series ) ? $series : array(), __NAMESPACE__ . '\\est_uuid' ) );
}

/**
 * Premier titre lisible d'un objet de titres MangaDex (fr, puis en, puis le premier).
 *
 * @param mixed $titres Objet langue => titre.
 */
function titre_localise( $titres ): string {
	if ( ! is_array( $titres ) || array() === $titres ) {
		return '';
	}
	foreach ( array( 'fr', 'en' ) as $langue ) {
		if ( ! empty( $titres[ $langue ] ) ) {
			return (string) $titres[ $langue ];
		}
	}
	return (string) reset( $titres );
}

/**
 * Groupes de traduction d'une ressource MangaDex (relations scanlation_group).
 *
 * @param array<string,mixed> $ressource Ressource (avec includes[]=scanlation_group).
 * @return array<int,array{id:string,nom:string}>
 */
function groupes( array $ressource ): array {
	$groupes = array();
	foreach ( (array) ( $ressource['relationships'] ?? array() ) as $relation ) {
		if ( 'scanlation_group' === ( $relation['type'] ?? '' ) ) {
			$groupes[] = array(
				'id'  => (string) ( $relation['id'] ?? '' ),
				'nom' => (string) ( $relation['attributes']['name'] ?? '' ),
			);
		}
	}
	return $groupes;
}

/**
 * Informations d'un chapitre, prises dans le flux (en cache) des séries de la liste blanche :
 * un identifiant inconnu est refusé **sans aucun appel à MangaDex**, pour que personne ne puisse
 * faire interroger l'API par le site (une seule IP pour tous les lecteurs) avec des identifiants
 * au hasard. Erreur aussi pour un chapitre hébergé ailleurs (externalUrl : MangaPlus…).
 *
 * @param string $id Identifiant du chapitre.
 * @return array<string,mixed>|\WP_Error
 */
function chapitre( string $id ) {
	if ( ! est_uuid( $id ) ) {
		return new \WP_Error( 'yume_mdx_id', 'Identifiant de chapitre invalide.', array( 'status' => 400 ) );
	}
	foreach ( series_autorisees() as $manga ) {
		$serie = serie( $manga );
		if ( is_wp_error( $serie ) ) {
			continue;
		}
		foreach ( $serie['chapitres'] as $c ) {
			if ( $c['id'] !== $id ) {
				continue;
			}
			if ( '' !== $c['externe'] ) {
				return new \WP_Error( 'yume_mdx_externe', 'Ce chapitre est hébergé hors de MangaDex : il ne peut pas être lu ici.', array( 'status' => 409 ) );
			}
			return array_merge(
				$c,
				array(
					'manga'  => array(
						'id'    => $manga,
						'titre' => $serie['titre'],
					),
					'langue' => 'fr',
				)
			);
		}
	}
	return new \WP_Error( 'yume_mdx_inconnu', 'Chapitre inconnu : il n’appartient à aucune série de la liste blanche du prototype (Outils → Prototype MangaDex), ou il est trop récent pour le flux en cache (1 h).', array( 'status' => 403 ) );
}

/**
 * Serveur MD@Home d'un chapitre (cache 10 min) : baseUrl, hash, data, dataSaver.
 *
 * @param string $id     Identifiant du chapitre (déjà contrôlé par chapitre()).
 * @param bool   $forcer Redemander un nœud (après un échec, comme le demande MangaDex).
 * @return array{baseUrl:string,hash:string,data:string[],dataSaver:string[]}|\WP_Error
 */
function at_home( string $id, bool $forcer = false ) {
	$cle = 'yume_mdx_ah_' . $id;
	$ah  = $forcer ? false : get_transient( $cle );
	if ( is_array( $ah ) ) {
		return $ah;
	}
	$donnees = api_get( '/at-home/server/' . $id );
	if ( is_wp_error( $donnees ) ) {
		return $donnees;
	}
	$base = (string) ( $donnees['baseUrl'] ?? '' );
	if ( 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) || empty( $donnees['chapter']['hash'] ) ) {
		return new \WP_Error( 'yume_mdx_at_home', 'Serveur d’images MangaDex invalide.', array( 'status' => 502 ) );
	}
	$ah = array(
		'baseUrl'   => untrailingslashit( $base ),
		'hash'      => (string) $donnees['chapter']['hash'],
		'data'      => array_values( array_map( 'strval', (array) ( $donnees['chapter']['data'] ?? array() ) ) ),
		'dataSaver' => array_values( array_map( 'strval', (array) ( $donnees['chapter']['dataSaver'] ?? array() ) ) ),
	);
	set_transient( $cle, $ah, DUREE_AT_HOME );
	return $ah;
}

/**
 * Chapitres en français d'une série autorisée (cache 1 h), dans l'ordre de lecture.
 *
 * @param string $manga Identifiant de la série.
 * @return array{titre:string,chapitres:array<int,array<string,mixed>>}|\WP_Error
 */
function serie( string $manga ) {
	if ( ! est_uuid( $manga ) || ! in_array( $manga, series_autorisees(), true ) ) {
		return new \WP_Error( 'yume_mdx_serie', 'Série absente de la liste blanche du prototype.', array( 'status' => 403 ) );
	}
	$cle   = 'yume_mdx_serie_' . $manga;
	$serie = get_transient( $cle );
	if ( is_array( $serie ) ) {
		return $serie;
	}
	$infos = api_get( '/manga/' . $manga );
	if ( is_wp_error( $infos ) ) {
		return $infos;
	}
	$flux = api_get(
		'/manga/' . $manga . '/feed',
		array(
			'translatedLanguage' => array( 'fr' ),
			'order[volume]'      => 'asc',
			'order[chapter]'     => 'asc',
			'limit'              => '100',
			'includes'           => array( 'scanlation_group' ),
		)
	);
	if ( is_wp_error( $flux ) ) {
		return $flux;
	}
	$chapitres = array();
	foreach ( (array) ( $flux['data'] ?? array() ) as $ressource ) {
		$attributs   = (array) ( $ressource['attributes'] ?? array() );
		$chapitres[] = array(
			'id'       => (string) ( $ressource['id'] ?? '' ),
			'volume'   => (string) ( $attributs['volume'] ?? '' ),
			'chapitre' => (string) ( $attributs['chapter'] ?? '' ),
			'titre'    => (string) ( $attributs['title'] ?? '' ),
			'pages'    => (int) ( $attributs['pages'] ?? 0 ),
			'externe'  => (string) ( $attributs['externalUrl'] ?? '' ),
			'publie'   => (string) ( $attributs['readableAt'] ?? '' ),
			'groupes'  => groupes( (array) $ressource ),
		);
	}
	$serie = array(
		'titre'     => titre_localise( $infos['data']['attributes']['title'] ?? array() ),
		'chapitres' => $chapitres,
	);
	set_transient( $cle, $serie, DUREE_INFOS );
	return $serie;
}

/*
 * -----------------------------------------------------------------------------
 * Proxy d'images
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse relayée d'une page : stable tant que le chapitre ne change pas (l'empreinte vient du
 * hash MD@Home), donc cachable longtemps par le navigateur et par un cache de bord.
 *
 * @param string $voie      rest ou front.
 * @param string $id        Chapitre.
 * @param string $empreinte Huit premiers caractères du hash MD@Home.
 * @param string $qualite   data ou data-saver.
 * @param int    $n         Numéro de page (1…).
 */
function url_image( string $voie, string $id, string $empreinte, string $qualite, int $n ): string {
	$chemin = $id . '/' . $empreinte . '/' . $qualite . '/' . $n;
	return 'front' === $voie ? add_query_arg( 'yume_mdx_image', $chemin, home_url( '/' ) ) : rest_url( ESPACE . '/image/' . $chemin );
}

/**
 * Enregistre une mesure du proxy (option, MAX_MESURES dernières).
 *
 * @param array<string,mixed> $mesure Mesure.
 */
function mesurer( array $mesure ): void {
	$mesures   = get_option( OPTION_MESURES, array() );
	$mesures   = is_array( $mesures ) ? $mesures : array();
	$mesures[] = $mesure;
	update_option( OPTION_MESURES, array_slice( $mesures, -MAX_MESURES ), false );
}

/**
 * Met un signalement en attente ; ils partent en fin de requête, une fois la réponse envoyée au
 * lecteur (fastcgi_finish_request() dans envoyer()) : même « non bloquante », une requête
 * sortante peut retenir la réponse jusqu'à son délai (3 s mesurées).
 *
 * @param array<string,mixed>|null $rapport Signalement, ou null pour tout envoyer.
 */
function signaler( ?array $rapport = null ): void {
	static $file     = array();
	static $accroche = false;
	if ( null !== $rapport ) {
		$file[] = $rapport;
		if ( ! $accroche ) {
			$accroche = true;
			register_shutdown_function( __NAMESPACE__ . '\\signaler' );
		}
		return;
	}
	$resultats = array();
	while ( $file ) {
		$debut       = microtime( true );
		$reponse     = wp_remote_post(
			RAPPORT,
			array(
				'timeout'    => 5,
				'user-agent' => agent(),
				'headers'    => array( 'Content-Type' => 'application/json' ),
				'body'       => wp_json_encode( array_shift( $file ) ),
			)
		);
		$resultats[] = array(
			't'    => time(),
			'ms'   => (int) round( ( microtime( true ) - $debut ) * 1000 ),
			'code' => is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse ),
			'err'  => is_wp_error( $reponse ) ? $reponse->get_error_message() : '',
		);
	}
	if ( $resultats ) {
		$anciens = get_option( OPTION_RAPPORTS, array() );
		update_option( OPTION_RAPPORTS, array_slice( array_merge( is_array( $anciens ) ? $anciens : array(), $resultats ), -50 ), false );
	}
}

/**
 * Télécharge une page depuis MD@Home, la signale à MangaDex si le nœud n'est pas sur
 * mangadex.org, et la mesure.
 *
 * @param array{baseUrl:string,hash:string,data:string[],dataSaver:string[]} $ah      Serveur.
 * @param string                                                             $qualite Qualité.
 * @param string                                                             $fichier Fichier.
 * @param string                                                             $voie    Voie de la requête (mesure).
 * @return array{type:string,corps:string,ms:int,cache:bool}|\WP_Error
 */
function telecharger( array $ah, string $qualite, string $fichier, string $voie ) {
	$url     = $ah['baseUrl'] . '/' . $qualite . '/' . $ah['hash'] . '/' . rawurlencode( $fichier );
	$debut   = microtime( true );
	$reponse = wp_remote_get(
		$url,
		array(
			'timeout'             => 20,
			'user-agent'          => agent(),
			'limit_response_size' => TAILLE_MAX,
		)
	);
	$ms      = (int) round( ( microtime( true ) - $debut ) * 1000 );
	$code    = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );
	$corps   = is_wp_error( $reponse ) ? '' : (string) wp_remote_retrieve_body( $reponse );
	$type    = is_wp_error( $reponse ) ? '' : strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $reponse, 'content-type' ) )[0] ) );
	$cache   = ! is_wp_error( $reponse ) && 0 === stripos( (string) wp_remote_retrieve_header( $reponse, 'x-cache' ), 'HIT' );
	$ok      = 200 === $code && '' !== $corps && in_array( $type, TYPES_IMAGE, true );
	$hote    = (string) wp_parse_url( $ah['baseUrl'], PHP_URL_HOST );

	// MangaDex : signaler chaque téléchargement depuis un nœud hors mangadex.org (succès ou
	// échec), après l'envoi de l'image (signaler()).
	if ( ! preg_match( '/(^|\.)mangadex\.org$/', $hote ) ) {
		signaler(
			array(
				'url'      => $url,
				'success'  => $ok,
				'cached'   => $cache,
				'bytes'    => strlen( $corps ),
				'duration' => $ms,
			)
		);
	}
	mesurer(
		array(
			't'       => time(),
			'voie'    => $voie,
			'qualite' => $qualite,
			'ms'      => $ms,
			'octets'  => strlen( $corps ),
			'cache'   => $cache,
			'ok'      => $ok,
			'code'    => $code,
			'noeud'   => $hote,
		)
	);
	if ( ! $ok ) {
		return new \WP_Error( 'yume_mdx_image', 'Image indisponible sur MangaDex (HTTP ' . $code . ').', array( 'status' => 502 ) );
	}
	return array(
		'type'  => $type,
		'corps' => $corps,
		'ms'    => $ms,
		'cache' => $cache,
	);
}

/**
 * Image d'une page relayée, ou erreur. Un échec redemande un nœud MD@Home et réessaie une fois
 * (procédure de MangaDex), jamais plus.
 *
 * @param string $chemin chapitre/empreinte/qualite/n.
 * @param string $voie   rest ou front.
 * @return array{type:string,corps:string,ms:int,cache:bool}|\WP_Error
 */
function image( string $chemin, string $voie ) {
	if ( ! preg_match( '#^(' . MOTIF_UUID . ')/([0-9a-f]{8})/(data|data-saver)/([1-9][0-9]{0,3})$#', $chemin, $m ) ) {
		return new \WP_Error( 'yume_mdx_chemin', 'Adresse d’image invalide.', array( 'status' => 400 ) );
	}
	list( , $id, $empreinte, $qualite, $n ) = $m;
	$infos                                  = chapitre( $id );
	if ( is_wp_error( $infos ) ) {
		return $infos;
	}
	foreach ( array( false, true ) as $forcer ) {
		$ah = at_home( $id, $forcer );
		if ( is_wp_error( $ah ) ) {
			return $ah;
		}
		if ( substr( $ah['hash'], 0, 8 ) !== $empreinte ) {
			return new \WP_Error( 'yume_mdx_version', 'Le chapitre a changé sur MangaDex : rechargez la page.', array( 'status' => 404 ) );
		}
		$fichiers = 'data' === $qualite ? $ah['data'] : $ah['dataSaver'];
		if ( ! isset( $fichiers[ (int) $n - 1 ] ) ) {
			return new \WP_Error( 'yume_mdx_page', 'Page inexistante.', array( 'status' => 404 ) );
		}
		$image = telecharger( $ah, $qualite, $fichiers[ (int) $n - 1 ], $voie );
		if ( ! is_wp_error( $image ) ) {
			return $image;
		}
	}
	return $image;
}

/**
 * Envoie l'image et termine la requête. Cache long (une page d'un chapitre ne change jamais à
 * adresse égale) ; X-Yume-Proto-Genere date la génération : identique sur deux réponses, elle
 * prouve qu'un cache a servi la seconde (mesurer.mjs).
 *
 * @param array{type:string,corps:string,ms:int,cache:bool} $image Image.
 */
function envoyer( array $image ): void {
	header_remove( 'Expires' );
	header_remove( 'Pragma' );
	header_remove( 'Set-Cookie' );
	status_header( 200 );
	header( 'Content-Type: ' . $image['type'] );
	header( 'Content-Length: ' . strlen( $image['corps'] ) );
	header( 'Cache-Control: public, max-age=31536000, immutable' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Yume-Proto-Genere: ' . gmdate( 'Y-m-d\TH:i:s\Z' ) . ' amont=' . $image['ms'] . 'ms' . ( $image['cache'] ? ' hit' : '' ) );
	echo $image['corps']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image binaire.
	// Réponse terminée pour le lecteur ; les signalements partent ensuite (signaler()).
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} else {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();
	}
	exit;
}

/**
 * Voie « front » : /?yume_mdx_image=chapitre/empreinte/qualite/n, traitée tôt (init), avant
 * le thème ; c'est la voie que Batcache peut mettre en cache comme une page.
 */
function servir_image_front(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture publique, sans effet.
	$chemin = isset( $_GET['yume_mdx_image'] ) ? sanitize_text_field( wp_unslash( $_GET['yume_mdx_image'] ) ) : '';
	if ( '' === $chemin ) {
		return;
	}
	$image = image( $chemin, 'front' );
	if ( is_wp_error( $image ) ) {
		$donnees = $image->get_error_data();
		status_header( is_array( $donnees ) && ! empty( $donnees['status'] ) ? (int) $donnees['status'] : 502 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo esc_html( $image->get_error_message() );
		exit;
	}
	envoyer( $image );
}
add_action( 'init', __NAMESPACE__ . '\\servir_image_front', 1 );

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

/**
 * Routes REST du prototype (lecture publique).
 */
function routes(): void {
	$uuid = '(?P<id>' . MOTIF_UUID . ')';
	register_rest_route(
		ESPACE,
		'/chapitre/' . $uuid,
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_chapitre',
			'permission_callback' => '__return_true',
			'args'                => array(
				'qualite' => array(
					'type'    => 'string',
					'enum'    => QUALITES,
					'default' => 'data-saver',
				),
				'voie'    => array(
					'type'    => 'string',
					'enum'    => array( 'rest', 'front' ),
					'default' => 'rest',
				),
			),
		)
	);
	register_rest_route(
		ESPACE,
		'/serie/' . $uuid,
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_serie',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		ESPACE,
		'/image/(?P<chemin>' . MOTIF_UUID . '/[0-9a-f]{8}/(?:data|data-saver)/[0-9]{1,4})',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_image',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );

/**
 * GET /chapitre/{id} : informations, crédits et adresses relayées des pages.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_chapitre( \WP_REST_Request $requete ) {
	$id    = (string) $requete['id'];
	$infos = chapitre( $id );
	if ( is_wp_error( $infos ) ) {
		return $infos;
	}
	$ah = at_home( $id );
	if ( is_wp_error( $ah ) ) {
		return $ah;
	}
	$qualite   = (string) $requete['qualite'];
	$voie      = (string) $requete['voie'];
	$empreinte = substr( $ah['hash'], 0, 8 );
	$nombre    = count( 'data' === $qualite ? $ah['data'] : $ah['dataSaver'] );
	$pages     = array();
	for ( $n = 1; $n <= $nombre; $n++ ) {
		$pages[] = url_image( $voie, $id, $empreinte, $qualite, $n );
	}
	$reponse = rest_ensure_response(
		array_merge(
			$infos,
			array(
				'qualite'  => $qualite,
				'voie'     => $voie,
				'pages'    => $pages,
				'mangadex' => 'https://mangadex.org/chapter/' . $id,
			)
		)
	);
	// L'adresse des pages suit le hash du chapitre : quelques minutes de cache suffisent.
	$reponse->header( 'Cache-Control', 'public, max-age=300' );
	return $reponse;
}

/**
 * GET /serie/{id} : chapitres en français d'une série autorisée.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_serie( \WP_REST_Request $requete ) {
	$serie = serie( (string) $requete['id'] );
	return is_wp_error( $serie ) ? $serie : rest_ensure_response( $serie );
}

/**
 * GET /image/{chapitre}/{empreinte}/{qualite}/{n} : image relayée (réponse binaire).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_Error Seulement en cas d'erreur (sinon la requête se termine dans envoyer()).
 */
function rest_image( \WP_REST_Request $requete ) {
	$image = image( (string) $requete['chemin'], 'rest' );
	if ( is_wp_error( $image ) ) {
		return $image;
	}
	envoyer( $image );
	return new \WP_Error( 'yume_mdx_inatteignable', '', array( 'status' => 500 ) );
}

/*
 * -----------------------------------------------------------------------------
 * Lecteur (codes courts)
 * -----------------------------------------------------------------------------
 */

/**
 * Feuille et script du lecteur.
 */
function enregistrer_lecteur(): void {
	$version = (string) filemtime( __FILE__ );
	wp_register_style( 'yume-mdx-lecteur', plugins_url( 'lecteur.css', __FILE__ ), array(), $version );
	wp_register_script(
		'yume-mdx-lecteur',
		plugins_url( 'lecteur.js', __FILE__ ),
		array(),
		$version,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_lecteur' );

/**
 * Conteneur du lecteur d'un chapitre (rempli par lecteur.js).
 *
 * @param string $chapitre Chapitre.
 * @param string $qualite  Qualité par défaut.
 * @param string $voie     Voie du proxy.
 */
function html_lecteur( string $chapitre, string $qualite, string $voie ): string {
	wp_enqueue_style( 'yume-mdx-lecteur' );
	wp_enqueue_script( 'yume-mdx-lecteur' );
	return '<div class="ymdx-lecteur" data-ymdx-api="' . esc_url( rest_url( ESPACE . '/chapitre/' ) ) . '" data-ymdx-chapitre="' . esc_attr( $chapitre ) . '" data-ymdx-qualite="' . esc_attr( $qualite ) . '" data-ymdx-voie="' . esc_attr( $voie ) . '">'
		. '<p class="ymdx-lecteur__etat" role="status">Chargement du chapitre…</p>'
		. '<noscript><p><a href="' . esc_url( 'https://mangadex.org/chapter/' . $chapitre ) . '">Lire ce chapitre sur MangaDex</a></p></noscript>'
		. '</div>';
}

/**
 * [yume_mangadex chapitre="uuid" qualite="data-saver|data" voie="rest|front"].
 *
 * @param array<string,string>|string $attributs Attributs.
 */
function code_chapitre( $attributs ): string {
	$a = shortcode_atts(
		array(
			'chapitre' => '',
			'qualite'  => 'data-saver',
			'voie'     => 'rest',
		),
		(array) $attributs
	);
	if ( ! est_uuid( $a['chapitre'] ) ) {
		return '<p>Prototype MangaDex : identifiant de chapitre invalide.</p>';
	}
	return html_lecteur( $a['chapitre'], in_array( $a['qualite'], QUALITES, true ) ? $a['qualite'] : 'data-saver', 'front' === $a['voie'] ? 'front' : 'rest' );
}
add_shortcode( 'yume_mangadex', __NAMESPACE__ . '\\code_chapitre' );

/**
 * [yume_mangadex_serie manga="uuid"] : chapitres en français de la série ; ?chapitre=uuid sur la
 * même page ouvre le lecteur.
 *
 * @param array<string,string>|string $attributs Attributs.
 */
function code_serie( $attributs ): string {
	$a = shortcode_atts(
		array(
			'manga'   => '',
			'qualite' => 'data-saver',
			'voie'    => 'rest',
		),
		(array) $attributs
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation publique.
	$choisi = isset( $_GET['chapitre'] ) ? sanitize_key( wp_unslash( $_GET['chapitre'] ) ) : '';
	if ( est_uuid( $choisi ) ) {
		return '<p><a href="' . esc_url( remove_query_arg( 'chapitre' ) ) . '">← Tous les chapitres</a></p>'
			. html_lecteur( $choisi, in_array( $a['qualite'], QUALITES, true ) ? $a['qualite'] : 'data-saver', 'front' === $a['voie'] ? 'front' : 'rest' );
	}
	$serie = serie( $a['manga'] );
	if ( is_wp_error( $serie ) ) {
		return '<p>Prototype MangaDex : ' . esc_html( $serie->get_error_message() ) . '</p>';
	}
	wp_enqueue_style( 'yume-mdx-lecteur' );
	$html = '<div class="ymdx-serie"><h2>' . esc_html( $serie['titre'] ) . '</h2>';
	if ( array() === $serie['chapitres'] ) {
		return $html . '<p>Aucun chapitre en français sur MangaDex.</p></div>';
	}
	$html .= '<ol class="ymdx-serie__liste">';
	foreach ( $serie['chapitres'] as $c ) {
		$libelle = trim( ( '' !== $c['volume'] ? 'Vol. ' . $c['volume'] . ' · ' : '' ) . ( '' !== $c['chapitre'] ? 'Ch. ' . $c['chapitre'] : 'Oneshot' ) . ( '' !== $c['titre'] ? ' — ' . $c['titre'] : '' ) );
		$groupes = implode( ', ', array_filter( array_column( $c['groupes'], 'nom' ) ) );
		$html   .= '<li>' . ( '' !== $c['externe']
			? esc_html( $libelle ) . ' <em>(hébergé hors de MangaDex)</em>'
			: '<a href="' . esc_url( add_query_arg( 'chapitre', $c['id'] ) ) . '">' . esc_html( $libelle ) . '</a>' )
			. ( '' !== $groupes ? ' <span class="ymdx-serie__groupe">' . esc_html( $groupes ) . '</span>' : '' ) . '</li>';
	}
	return $html . '</ol><p class="ymdx-credit">Chapitres hébergés sur <a href="' . esc_url( 'https://mangadex.org/title/' . $a['manga'] ) . '">MangaDex</a>.</p></div>';
}
add_shortcode( 'yume_mangadex_serie', __NAMESPACE__ . '\\code_serie' );

/*
 * -----------------------------------------------------------------------------
 * Administration : liste blanche et mesures
 * -----------------------------------------------------------------------------
 */

/**
 * Page Outils → Prototype MangaDex.
 */
function menu(): void {
	add_management_page( 'Prototype MangaDex', 'Prototype MangaDex', 'manage_options', 'yume-mdx-proto', __NAMESPACE__ . '\\page_admin' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );

/**
 * Enregistre la liste blanche ou efface les mesures (admin-post).
 */
function traiter_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Accès refusé.', 403 );
	}
	check_admin_referer( 'yume_mdx_proto' );
	if ( isset( $_POST['effacer'] ) ) {
		delete_option( OPTION_MESURES );
		delete_option( OPTION_RAPPORTS );
	} else {
		$saisie = isset( $_POST['series'] ) ? sanitize_textarea_field( wp_unslash( $_POST['series'] ) ) : '';
		preg_match_all( '/' . MOTIF_UUID . '/', strtolower( $saisie ), $trouves );
		update_option( OPTION_SERIES, array_values( array_unique( $trouves[0] ) ), false );
	}
	wp_safe_redirect( admin_url( 'tools.php?page=yume-mdx-proto' ) );
	exit;
}
add_action( 'admin_post_yume_mdx_proto', __NAMESPACE__ . '\\traiter_admin' );

/**
 * Centile d'une liste de nombres.
 *
 * @param int[] $valeurs Valeurs.
 * @param float $p       Centile (0…1).
 */
function centile( array $valeurs, float $p ): int {
	if ( array() === $valeurs ) {
		return 0;
	}
	sort( $valeurs );
	return (int) $valeurs[ (int) min( count( $valeurs ) - 1, floor( $p * count( $valeurs ) ) ) ];
}

/**
 * Résumé des mesures par voie et qualité.
 *
 * @return array<string,array<string,int|float>>
 */
function resume_mesures(): array {
	$mesures = get_option( OPTION_MESURES, array() );
	$groupes = array();
	foreach ( is_array( $mesures ) ? $mesures : array() as $m ) {
		$groupes[ $m['voie'] . ' · ' . $m['qualite'] ][] = $m;
	}
	$resume = array();
	foreach ( $groupes as $cle => $liste ) {
		$ms             = array_column( $liste, 'ms' );
		$resume[ $cle ] = array(
			'images'  => count( $liste ),
			'erreurs' => count( array_filter( $liste, static fn( $m ) => empty( $m['ok'] ) ) ),
			'p50'     => centile( $ms, 0.5 ),
			'p95'     => centile( $ms, 0.95 ),
			'ko'      => (int) round( array_sum( array_column( $liste, 'octets' ) ) / 1024 ),
			'moyen'   => (int) round( array_sum( array_column( $liste, 'octets' ) ) / max( 1, count( $liste ) ) / 1024 ),
			'hit'     => (int) round( 100 * count( array_filter( $liste, static fn( $m ) => ! empty( $m['cache'] ) ) ) / max( 1, count( $liste ) ) ),
		);
	}
	return $resume;
}

/**
 * Rendu de la page d'administration.
 */
function page_admin(): void {
	echo '<div class="wrap"><h1>Prototype MangaDex</h1>';
	echo '<p>Prototype jetable : voir <code>tools/mangadex-prototype/README.md</code>. Chaque image relayée par le site est comptée ci-dessous (téléchargement depuis MangaDex seulement : une image servie par un cache n’arrive pas jusqu’ici).</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'yume_mdx_proto' );
	echo '<input type="hidden" name="action" value="yume_mdx_proto">';
	echo '<h2>Séries autorisées</h2><p>Identifiants MangaDex (ou adresses https://mangadex.org/title/…), un par ligne. Le proxy ne relaie que leurs chapitres.</p>';
	echo '<textarea name="series" rows="4" cols="60" class="large-text code">' . esc_textarea( implode( "\n", series_autorisees() ) ) . '</textarea>';
	submit_button( 'Enregistrer la liste' );
	echo '</form>';

	echo '<h2>Mesures du proxy (' . (int) MAX_MESURES . ' dernières images)</h2>';
	$resume = resume_mesures();
	if ( array() === $resume ) {
		echo '<p>Aucune image relayée pour le moment.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Voie · qualité</th><th>Images</th><th>Erreurs</th><th>Téléchargement MangaDex p50 / p95</th><th>Volume relayé</th><th>Taille moyenne</th><th>Déjà en cache chez MangaDex</th></tr></thead><tbody>';
		foreach ( $resume as $cle => $r ) {
			echo '<tr><td>' . esc_html( $cle ) . '</td><td>' . (int) $r['images'] . '</td><td>' . (int) $r['erreurs'] . '</td><td>' . (int) $r['p50'] . ' ms / ' . (int) $r['p95'] . ' ms</td><td>' . esc_html( number_format_i18n( $r['ko'] / 1024, 1 ) ) . ' Mo</td><td>' . (int) $r['moyen'] . ' Ko</td><td>' . (int) $r['hit'] . ' %</td></tr>';
		}
		echo '</tbody></table>';
	}
	$rapports = get_option( OPTION_RAPPORTS, array() );
	$rapports = is_array( $rapports ) ? $rapports : array();
	echo '<h2>Signalements à MangaDex (api.mangadex.network/report)</h2>';
	if ( array() === $rapports ) {
		echo '<p>Aucun signalement envoyé (images servies par un nœud mangadex.org, ou aucune image relayée).</p>';
	} else {
		$reussis = count( array_filter( $rapports, static fn( $r ) => $r['code'] >= 200 && $r['code'] < 300 ) );
		$dernier = end( $rapports );
		echo '<p>' . (int) $reussis . ' réussi(s) sur les ' . count( $rapports ) . ' derniers ; délai médian ' . (int) centile( array_column( $rapports, 'ms' ), 0.5 ) . ' ms. Dernier : HTTP ' . (int) $dernier['code'] . ( '' !== $dernier['err'] ? ' (' . esc_html( $dernier['err'] ) . ')' : '' ) . '.</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
	wp_nonce_field( 'yume_mdx_proto' );
	echo '<input type="hidden" name="action" value="yume_mdx_proto"><input type="hidden" name="effacer" value="1">';
	submit_button( 'Effacer les mesures', 'secondary', 'submit', false );
	echo '</form>';

	echo '<h2>Essayer</h2><p>Dans une page de test (brouillon) : <code>[yume_mangadex_serie manga="identifiant"]</code> (liste des chapitres en français, lecteur au clic) ou <code>[yume_mangadex chapitre="identifiant"]</code>. Attributs : <code>qualite="data"</code> (images originales), <code>voie="front"</code> (proxy par l’adresse publique au lieu de l’API REST).</p>';
	echo '</div>';
}
