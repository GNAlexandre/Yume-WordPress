<?php
/**
 * Proxy des pages des chapitres MangaDex (contrat §8 bis).
 *
 * MangaDex impose de relayer ses images (un site qui les lie reçoit une image « lisez sur
 * MangaDex ») : le navigateur du lecteur demande chaque page au site, qui la télécharge sur le
 * serveur MD@Home du chapitre et la renvoie telle quelle.
 *
 * - **Rien n'est gardé** : l'image passe en mémoire, jamais sur le disque ni dans la médiathèque ;
 *   aucune écriture en base pour une image (seuls l'adresse MD@Home d'un chapitre, 10 min, et les
 *   pauses après une limite ou un signalement en échec sont des transients).
 * - **Pas de relais ouvert** : seulement les chapitres de la base, publiés, d'un tome et d'une
 *   œuvre publiés, et seulement une fois le lecteur livré (lecteur_pret()). L'adresse porte la
 *   version du chapitre : un chapitre mis à jour sur MangaDex change d'adresse, d'où un cache
 *   d'un an (« immutable ») sur l'image.
 * - **Règles de MangaDex** : User-Agent réel, échec d'un nœud → un seul autre nœud demandé, aucun
 *   nouvel essai après 429/403 (pause de DUREE_PAUSE pour tout le site), et chaque image venue
 *   d'un nœud MD@Home signalée à api.mangadex.network/report, après l'envoi de l'image au
 *   lecteur, avec un délai court. Un signalement en échec ouvre un coupe-circuit
 *   (DUREE_COUPURE) : le point de signalement de MangaDex répondait HTTP 522 le 16/10/2026.
 *
 * Adresse : /?yume_mdx_image={chapitre}/{version}/{qualite}/{page}, qualité « data-saver »
 * (économie de données, par défaut) ou « data » (originale).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/**
 * Pages relayées.
 */
final class Proxy {

	/** Paramètre de l'adresse d'une page. */
	public const PARAMETRE = 'yume_mdx_image';

	/** Qualités (noms de MD@Home) : économie de données (défaut), originale. */
	public const QUALITES = array( 'data-saver', 'data' );

	/** Taille maximale d'une image relayée (octets). */
	public const TAILLE_MAX = 15728640;

	/** Délai de téléchargement d'une image (secondes). */
	public const DELAI_IMAGE = 20;

	/** Signalement des téléchargements depuis un nœud MD@Home (exigé par MangaDex). */
	public const SIGNALEMENT = 'https://api.mangadex.network/report';

	/** Délai d'un signalement (secondes ; la réponse au lecteur est déjà partie). */
	public const DELAI_SIGNALEMENT = 3;

	/** Transient : signalements suspendus après un échec. */
	public const COUPURE = 'yume_mdx_signalement_coupe';

	/** Durée de la suspension des signalements (secondes). */
	public const DUREE_COUPURE = 900;

	/** Transient : MangaDex a limité ou bloqué le site (429/403), plus aucun appel. */
	public const PAUSE = 'yume_mdx_pause';

	/** Durée de la pause après une limite (secondes). */
	public const DUREE_PAUSE = 60;

	/** Durée de cache d'une page relayée (un an : l'adresse change avec le chapitre). */
	public const DUREE_CACHE = 31536000;

	/**
	 * Signalements en attente (envoyés après la réponse).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $signalements = array();

	/**
	 * Adresse relayée d'une page d'un chapitre MangaDex, '' pour un autre contenu ou une page
	 * hors du chapitre.
	 *
	 * @param int    $chapitre_id Chapitre.
	 * @param int    $page        Page (1…).
	 * @param string $qualite     data-saver ou data (autre valeur : data-saver).
	 */
	public static function url( int $chapitre_id, int $page, string $qualite = 'data-saver' ): string {
		$mdx = yume_mangadex_chapitre( $chapitre_id );
		if ( null === $mdx || $page < 1 || $page > $mdx['pages'] || ! preg_match( '/^[0-9a-f]{8}$/', $mdx['version'] ) ) {
			return '';
		}
		$qualite = in_array( $qualite, self::QUALITES, true ) ? $qualite : 'data-saver';
		return add_query_arg( self::PARAMETRE, $mdx['uuid'] . '/' . $mdx['version'] . '/' . $qualite . '/' . $page, home_url( '/' ) );
	}

	/**
	 * Réponse à une demande de page : statut, en-têtes et corps (image ou message court).
	 *
	 * @param string $chemin chapitre/version/qualite/page.
	 * @return array{statut:int,entetes:array<string,string>,corps:string}
	 */
	public static function reponse( string $chemin ): array {
		if ( ! preg_match( '#^(' . MOTIF_UUID . ')/([0-9a-f]{8})/(data|data-saver)/([1-9][0-9]{0,3})$#', $chemin, $m ) ) {
			return self::erreur( 400, __( 'Adresse d’image invalide.', 'yume-core' ) );
		}
		list( , $uuid, $version, $qualite, $page ) = $m;
		$page                                      = (int) $page;

		$chapitre_id = lecteur_pret() ? yume_mangadex_chapitre_par_uuid( $uuid ) : 0;
		if ( ! $chapitre_id || ! self::lisible( $chapitre_id ) ) {
			return self::erreur( 404, __( 'Chapitre introuvable.', 'yume-core' ) );
		}
		$mdx = yume_mangadex_chapitre( $chapitre_id );
		if ( null === $mdx || $mdx['version'] !== $version ) {
			return self::erreur( 404, __( 'Le chapitre a changé : rechargez la page.', 'yume-core' ) );
		}
		if ( $mdx['pages'] > 0 && $page > $mdx['pages'] ) {
			return self::erreur( 404, __( 'Page inexistante.', 'yume-core' ) );
		}
		if ( get_transient( self::PAUSE ) ) {
			return self::erreur( 503, __( 'MangaDex limite les requêtes du site : réessayez dans une minute.', 'yume-core' ) );
		}

		$image = null;
		foreach ( array( false, true ) as $autre_noeud ) {
			$ah = Client::at_home( $uuid, $autre_noeud );
			if ( is_wp_error( $ah ) ) {
				if ( 'yume_mdx_limite' === $ah->get_error_code() ) {
					set_transient( self::PAUSE, time(), self::DUREE_PAUSE );
					return self::erreur( 503, __( 'MangaDex limite les requêtes du site : réessayez dans une minute.', 'yume-core' ) );
				}
				return self::erreur( 502, __( 'MangaDex est injoignable.', 'yume-core' ) );
			}
			$fichiers = 'data' === $qualite ? $ah['data'] : $ah['dataSaver'];
			if ( ! isset( $fichiers[ $page - 1 ] ) ) {
				return self::erreur( 404, __( 'Page inexistante.', 'yume-core' ) );
			}
			$image = self::telecharger( $ah, $qualite, $fichiers[ $page - 1 ] );
			if ( null !== $image ) {
				break;
			}
		}
		if ( null === $image ) {
			return self::erreur( 502, __( 'Image indisponible sur MangaDex.', 'yume-core' ) );
		}
		return array(
			'statut'  => 200,
			'entetes' => array(
				'Content-Type'                 => $image['type'],
				'Cache-Control'                => 'public, max-age=' . self::DUREE_CACHE . ', immutable',
				'X-Content-Type-Options'       => 'nosniff',
				'Cross-Origin-Resource-Policy' => 'same-site',
				'Content-Security-Policy'      => "default-src 'none'",
				'X-Robots-Tag'                 => 'noindex',
			),
			'corps'   => $image['corps'],
		);
	}

	/**
	 * Le chapitre est-il lisible par tous : publié, sans mot de passe, dans un tome et une œuvre
	 * publiés ? (Une œuvre « Licenciée » a ses chapitres en brouillon.)
	 *
	 * @param int $chapitre_id Chapitre.
	 */
	private static function lisible( int $chapitre_id ): bool {
		$chapitre = get_post( $chapitre_id );
		if ( ! $chapitre || 'publish' !== $chapitre->post_status || '' !== $chapitre->post_password ) {
			return false;
		}
		$tome   = yume_get_tome_id( $chapitre_id );
		$oeuvre = yume_get_oeuvre_id( $chapitre_id );
		return $tome && $oeuvre && 'publish' === get_post_status( $tome ) && 'publish' === get_post_status( $oeuvre );
	}

	/**
	 * Télécharge une page sur MD@Home et met son signalement en attente. Le type vient des
	 * premiers octets (jamais de l'en-tête du nœud) : seuls JPEG, PNG, GIF et WebP passent.
	 *
	 * @param array{baseUrl:string,hash:string,data:string[],dataSaver:string[]} $ah      Serveur.
	 * @param string                                                             $qualite Qualité.
	 * @param string                                                             $fichier Fichier.
	 * @return array{type:string,corps:string}|null
	 */
	private static function telecharger( array $ah, string $qualite, string $fichier ): ?array {
		$url     = $ah['baseUrl'] . '/' . $qualite . '/' . $ah['hash'] . '/' . rawurlencode( $fichier );
		$debut   = microtime( true );
		$reponse = wp_remote_get(
			$url,
			array(
				'timeout'             => self::DELAI_IMAGE,
				'user-agent'          => agent(),
				'limit_response_size' => self::TAILLE_MAX,
			)
		);
		$duree   = (int) round( ( microtime( true ) - $debut ) * 1000 );
		$code    = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );
		$corps   = is_wp_error( $reponse ) ? '' : (string) wp_remote_retrieve_body( $reponse );
		$type    = 200 === $code ? self::type_image( $corps ) : '';
		$cache   = ! is_wp_error( $reponse ) && 0 === stripos( (string) wp_remote_retrieve_header( $reponse, 'x-cache' ), 'HIT' );
		$hote    = (string) wp_parse_url( $ah['baseUrl'], PHP_URL_HOST );

		// Nœud MD@Home (hors mangadex.org) : signalement, succès ou échec.
		if ( ! preg_match( '/(^|\.)mangadex\.org$/', $hote ) ) {
			self::$signalements[] = array(
				'url'      => $url,
				'success'  => '' !== $type,
				'cached'   => $cache,
				'bytes'    => strlen( $corps ),
				'duration' => $duree,
			);
		}
		return '' === $type ? null : array(
			'type'  => $type,
			'corps' => $corps,
		);
	}

	/**
	 * Type MIME d'une image d'après ses premiers octets, '' si ce n'est pas une image admise.
	 *
	 * @param string $corps Contenu.
	 */
	public static function type_image( string $corps ): string {
		if ( 0 === strncmp( $corps, "\xFF\xD8\xFF", 3 ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strncmp( $corps, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'image/png';
		}
		if ( 0 === strncmp( $corps, 'GIF87a', 6 ) || 0 === strncmp( $corps, 'GIF89a', 6 ) ) {
			return 'image/gif';
		}
		if ( 0 === strncmp( $corps, 'RIFF', 4 ) && 'WEBP' === substr( $corps, 8, 4 ) ) {
			return 'image/webp';
		}
		return '';
	}

	/**
	 * Réponse d'erreur : texte court, jamais mise en cache.
	 *
	 * @param int    $statut  Statut HTTP.
	 * @param string $message Message.
	 * @return array{statut:int,entetes:array<string,string>,corps:string}
	 */
	private static function erreur( int $statut, string $message ): array {
		$entetes = array(
			'Content-Type'           => 'text/plain; charset=utf-8',
			'Cache-Control'          => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex',
		);
		if ( 503 === $statut ) {
			$entetes['Retry-After'] = (string) self::DUREE_PAUSE;
		}
		return array(
			'statut'  => $statut,
			'entetes' => $entetes,
			'corps'   => $message,
		);
	}

	/**
	 * Signalements en attente (lecture seule, pour les tests et le diagnostic).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function signalements_en_attente(): array {
		return self::$signalements;
	}

	/**
	 * Envoie les signalements en attente, puis vide la file. Coupe-circuit : pendant
	 * DUREE_COUPURE après un échec (réseau, délai, 5xx), rien n'est envoyé ; un succès n'écrit
	 * rien en base.
	 */
	public static function envoyer_signalements(): void {
		$file               = self::$signalements;
		self::$signalements = array();
		if ( ! $file || get_transient( self::COUPURE ) ) {
			return;
		}
		foreach ( $file as $signalement ) {
			$reponse = wp_remote_post(
				self::SIGNALEMENT,
				array(
					'timeout'    => self::DELAI_SIGNALEMENT,
					'user-agent' => agent(),
					'headers'    => array( 'Content-Type' => 'application/json' ),
					'body'       => (string) wp_json_encode( $signalement ),
				)
			);
			$code    = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );
			if ( 0 === $code || $code >= 500 ) {
				set_transient( self::COUPURE, time(), self::DUREE_COUPURE );
				return;
			}
		}
	}

	/**
	 * Sert une page demandée par /?yume_mdx_image=… (sur init, avant le thème) et termine la
	 * requête ; les signalements partent une fois la réponse envoyée au lecteur.
	 */
	public static function servir(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture publique, sans effet.
		if ( ! isset( $_GET[ self::PARAMETRE ] ) || is_admin() || wp_doing_ajax() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture publique, sans effet.
		$chemin  = sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAMETRE ] ) );
		$reponse = self::reponse( $chemin );

		foreach ( array( 'Set-Cookie', 'Expires', 'Pragma', 'Last-Modified' ) as $entete ) {
			header_remove( $entete );
		}
		status_header( $reponse['statut'] );
		foreach ( $reponse['entetes'] as $nom => $valeur ) {
			header( $nom . ': ' . $valeur );
		}
		header( 'Content-Length: ' . strlen( $reponse['corps'] ) );
		$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' !== $methode ) {
			echo $reponse['corps']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image binaire ou texte brut (text/plain, nosniff).
		}
		// Réponse terminée pour le lecteur ; les signalements partent ensuite.
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();
		}
		self::envoyer_signalements();
		exit;
	}
}
add_action( 'init', array( Proxy::class, 'servir' ), 20 );
