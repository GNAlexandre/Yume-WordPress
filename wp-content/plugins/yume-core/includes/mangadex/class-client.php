<?php
/**
 * Client de l'API MangaDex (https://api.mangadex.org/docs/).
 *
 * Règles de MangaDex respectées : User-Agent réel, aucun en-tête Via, environ 5 requêtes/s par
 * IP au plus (le site est une seule IP pour tous ses lecteurs : le serveur MD@Home d'un
 * chapitre est gardé 10 min, sa limite est de 40 appels par minute), et jamais de nouvel essai
 * après une réponse 429 (limite) ou 403 (adresse bloquée).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/**
 * Appels à l'API MangaDex, réponses normalisées.
 */
final class Client {

	/** API publique de MangaDex. */
	public const API = 'https://api.mangadex.org';

	/** Résultats par page (maximum de l'API pour /chapter). */
	public const PAR_PAGE = 100;

	/** L'API refuse offset + limit au-delà de 10 000. */
	public const MAX_RESULTATS = 10000;

	/** Durée de cache du serveur MD@Home d'un chapitre (MangaDex garantit au moins 15 min). */
	public const DUREE_AT_HOME = 600;

	/** Classements de contenu demandés (tous sauf « pornographic »). */
	public const CLASSEMENTS = array( 'safe', 'suggestive', 'erotica' );

	/**
	 * GET sur l'API, réponse JSON décodée. Paramètre tableau : clé[]=valeur (format de
	 * MangaDex) ; une clé déjà entre crochets (order[readableAt]) passe telle quelle.
	 *
	 * @param string              $chemin     Chemin (/chapter…).
	 * @param array<string,mixed> $parametres Paramètres de requête.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get( string $chemin, array $parametres = array() ) {
		$requete = array();
		foreach ( $parametres as $cle => $valeur ) {
			foreach ( (array) $valeur as $element ) {
				$requete[] = rawurlencode( is_array( $valeur ) ? $cle . '[]' : (string) $cle ) . '=' . rawurlencode( (string) $element );
			}
		}
		$reponse = wp_remote_get(
			self::API . $chemin . ( $requete ? '?' . implode( '&', $requete ) : '' ),
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
			return new \WP_Error( 'yume_mdx_limite', 'MangaDex limite les requêtes du site (HTTP ' . $code . ') : nouvel essai plus tard.', array( 'status' => 503 ) );
		}
		$donnees = json_decode( (string) wp_remote_retrieve_body( $reponse ), true );
		if ( 200 !== $code || ! is_array( $donnees ) || 'ok' !== ( $donnees['result'] ?? '' ) ) {
			$detail = is_array( $donnees ) ? (string) ( $donnees['errors'][0]['detail'] ?? '' ) : '';
			return new \WP_Error( 'yume_mdx_api', 'Réponse inattendue de MangaDex (HTTP ' . $code . ')' . ( '' !== $detail ? ' : ' . $detail : '' ), array( 'status' => 404 === $code ? 404 : 502 ) );
		}
		return $donnees;
	}

	/**
	 * Chapitres d'un groupe dans une langue, toutes séries confondues, du plus ancien au plus
	 * récent (date de mise en lecture), normalisés (normaliser_chapitre()). Toutes les pages de
	 * résultats sont lues, dans la limite de l'API (MAX_RESULTATS).
	 *
	 * @param string $groupe Identifiant du groupe.
	 * @param string $langue Code de langue MangaDex.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function chapitres_du_groupe( string $groupe, string $langue = 'fr' ) {
		if ( ! est_uuid( $groupe ) ) {
			return new \WP_Error( 'yume_mdx_id', 'Identifiant de groupe MangaDex invalide.', array( 'status' => 400 ) );
		}
		$chapitres = array();
		$decalage  = 0;
		do {
			$page = self::get(
				'/chapter',
				array(
					'groups'               => array( $groupe ),
					'translatedLanguage'   => array( $langue ),
					'contentRating'        => self::CLASSEMENTS,
					'includes'             => array( 'manga', 'scanlation_group' ),
					'order[readableAt]'    => 'asc',
					'limit'                => (string) self::PAR_PAGE,
					'offset'               => (string) $decalage,
					'includeFutureUpdates' => '0',
				)
			);
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$donnees = is_array( $page['data'] ?? null ) ? $page['data'] : array();
			foreach ( $donnees as $ressource ) {
				if ( is_array( $ressource ) && est_uuid( $ressource['id'] ?? null ) ) {
					$chapitres[] = self::normaliser_chapitre( $ressource );
				}
			}
			$total     = max( 0, (int) ( $page['total'] ?? 0 ) );
			$decalage += self::PAR_PAGE;
		} while ( $donnees && $decalage < $total && $decalage + self::PAR_PAGE <= self::MAX_RESULTATS );
		return $chapitres;
	}

	/**
	 * Serveur MD@Home d'un chapitre (en cache DUREE_AT_HOME) : baseUrl, hash, fichiers des deux
	 * qualités (data : originale, dataSaver : économie de données).
	 *
	 * @param string $chapitre Identifiant du chapitre.
	 * @param bool   $forcer   Demander un autre nœud (après un échec, comme le veut MangaDex).
	 * @return array{baseUrl:string,hash:string,data:string[],dataSaver:string[]}|\WP_Error
	 */
	public static function at_home( string $chapitre, bool $forcer = false ) {
		if ( ! est_uuid( $chapitre ) ) {
			return new \WP_Error( 'yume_mdx_id', 'Identifiant de chapitre MangaDex invalide.', array( 'status' => 400 ) );
		}
		$cle = 'yume_mdx_ah_' . $chapitre;
		$ah  = $forcer ? false : get_transient( $cle );
		if ( is_array( $ah ) ) {
			return $ah;
		}
		$donnees = self::get( '/at-home/server/' . $chapitre );
		if ( is_wp_error( $donnees ) ) {
			return $donnees;
		}
		$base = (string) ( $donnees['baseUrl'] ?? '' );
		$hash = (string) ( $donnees['chapter']['hash'] ?? '' );
		if ( 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) || ! preg_match( '/^[0-9a-f]{32,64}$/', $hash ) ) {
			return new \WP_Error( 'yume_mdx_at_home', 'Serveur d’images MangaDex invalide.', array( 'status' => 502 ) );
		}
		$ah = array(
			'baseUrl'   => untrailingslashit( $base ),
			'hash'      => $hash,
			'data'      => self::fichiers( $donnees['chapter']['data'] ?? array() ),
			'dataSaver' => self::fichiers( $donnees['chapter']['dataSaver'] ?? array() ),
		);
		set_transient( $cle, $ah, self::DUREE_AT_HOME );
		return $ah;
	}

	/**
	 * Noms de fichiers d'images valides (jamais de chemin : ils sont ajoutés à une adresse).
	 *
	 * @param mixed $liste Liste brute.
	 * @return string[]
	 */
	private static function fichiers( $liste ): array {
		$fichiers = array();
		foreach ( is_array( $liste ) ? $liste : array() as $fichier ) {
			if ( is_string( $fichier ) && preg_match( '/^[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)$/', $fichier ) ) {
				$fichiers[] = $fichier;
			}
		}
		return $fichiers;
	}

	/**
	 * Chapitre MangaDex normalisé : identifiant, série (id, titre), volume et numéro (texte,
	 * '' si absent), titre, langue, nombre de pages, adresse externe (chapitre hébergé hors de
	 * MangaDex : non lisible sur le site), dates de mise en lecture et de mise à jour, version
	 * (8 caractères, change quand MangaDex met le chapitre à jour) et groupes.
	 *
	 * @param array<string,mixed> $ressource Ressource « chapter » de l'API.
	 * @return array<string,mixed>
	 */
	public static function normaliser_chapitre( array $ressource ): array {
		$attributs = is_array( $ressource['attributes'] ?? null ) ? $ressource['attributes'] : array();
		$serie     = array(
			'id'    => '',
			'titre' => '',
		);
		$groupes   = array();
		foreach ( is_array( $ressource['relationships'] ?? null ) ? $ressource['relationships'] : array() as $relation ) {
			$type = (string) ( $relation['type'] ?? '' );
			$id   = (string) ( $relation['id'] ?? '' );
			if ( 'manga' === $type && est_uuid( $id ) ) {
				$serie = array(
					'id'    => $id,
					'titre' => self::titre_localise( $relation['attributes']['title'] ?? array() ),
				);
			} elseif ( 'scanlation_group' === $type && est_uuid( $id ) ) {
				$groupes[] = array(
					'id'  => $id,
					'nom' => sanitize_text_field( (string) ( $relation['attributes']['name'] ?? '' ) ),
				);
			}
		}
		$id  = (string) ( $ressource['id'] ?? '' );
		$maj = (string) ( $attributs['updatedAt'] ?? '' );
		return array(
			'id'       => $id,
			'serie'    => $serie,
			'volume'   => self::texte( $attributs['volume'] ?? '' ),
			'chapitre' => self::texte( $attributs['chapter'] ?? '' ),
			'titre'    => self::texte( $attributs['title'] ?? '' ),
			'langue'   => self::texte( $attributs['translatedLanguage'] ?? '' ),
			'pages'    => max( 0, (int) ( $attributs['pages'] ?? 0 ) ),
			'externe'  => esc_url_raw( (string) ( $attributs['externalUrl'] ?? '' ) ),
			'publie'   => self::texte( $attributs['readableAt'] ?? '' ),
			'maj'      => $maj,
			'version'  => substr( sha1( $id . '|' . $maj ), 0, 8 ),
			'groupes'  => $groupes,
		);
	}

	/**
	 * Titre lisible d'un objet de titres MangaDex (fr, puis en, puis le premier).
	 *
	 * @param mixed $titres Objet langue => titre.
	 */
	public static function titre_localise( $titres ): string {
		if ( ! is_array( $titres ) || array() === $titres ) {
			return '';
		}
		foreach ( array( 'fr', 'en' ) as $langue ) {
			if ( ! empty( $titres[ $langue ] ) && is_string( $titres[ $langue ] ) ) {
				return sanitize_text_field( $titres[ $langue ] );
			}
		}
		$premier = reset( $titres );
		return is_string( $premier ) ? sanitize_text_field( $premier ) : '';
	}

	/**
	 * Texte d'un attribut (null ou non scalaire : '').
	 *
	 * @param mixed $valeur Valeur.
	 */
	private static function texte( $valeur ): string {
		return is_scalar( $valeur ) ? sanitize_text_field( (string) $valeur ) : '';
	}
}
