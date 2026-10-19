<?php
/**
 * Tests de la connexion avec Discord (includes/discord, lot L2) : configuration, départ (state,
 * cookie, PKCE S256), retour (state expiré, refus, adresse non vérifiée, compte lié, administrateur,
 * adresse existante, création, inscriptions fermées), liaison et déliaison, limite par adresse IP,
 * aucun jeton en base, RGPD, route REST, boutons et rubrique du compte, réglages et Santé.
 *
 * Aucune requête réseau réelle : Discord est simulé par pre_http_request (toute autre adresse fait
 * échouer le test).
 *
 * Lancement : tools/localenv/test.sh discord
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Discord;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_td_)
 * -----------------------------------------------------------------------------
 */

/** Jeton d'accès renvoyé par le faux Discord (ne doit jamais être stocké). */
const YUME_TD_JETON = 'jeton-acces-yume-test-6f1c2a';

/** Jeton de rafraîchissement renvoyé par le faux Discord (ne doit jamais être stocké). */
const YUME_TD_RAFRAICHI = 'jeton-rafraichi-yume-test-9b7e';

/** Mot de passe des comptes de ces tests. */
const YUME_TD_MDP = 'Discord-Cheval-Pile-42';

/**
 * Active la connexion Discord (réglages), avec des valeurs à remplacer.
 *
 * @param array $reglages Réglages fusionnés.
 */
function yume_td_configurer( array $reglages = array() ): void {
	global $wpdb;
	$actuels = get_option( 'yume_reglages', array() );
	$valeur  = array_merge(
		is_array( $actuels ) ? $actuels : array(),
		array(
			'discord_connexion'     => true,
			'discord_inscription'   => true,
			'discord_client_id'     => '112233445566778899',
			'discord_client_secret' => 'secret-de-test',
		),
		$reglages
	);
	// Écriture directe : l'assainissement du réglage (déclaré après rest_api_init) garderait un
	// secret vidé. La transaction du test annule l'écriture.
	delete_option( 'yume_reglages' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->options,
		array(
			'option_name'  => 'yume_reglages',
			'option_value' => maybe_serialize( $valeur ),
			'autoload'     => 'on',
		)
	);
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'yume_reglages', 'options' );
}

/**
 * Profil Discord simulé (users/@me).
 *
 * @param array $valeurs Valeurs remplacées.
 */
function yume_td_profil( array $valeurs = array() ): array {
	return array_merge(
		array(
			'id'          => '80351110224678912',
			'username'    => 'mio_brumes',
			'global_name' => 'Mio des Brumes',
			'email'       => 'mio-' . wp_rand( 1000, 999999 ) . '@exemple.test',
			'verified'    => true,
			'avatar'      => 'a_d5efa99b3eeaa7dd43acca82f5692432',
		),
		$valeurs
	);
}

/**
 * Exécute $rappel avec un faux Discord : jeton, profil, révocation ; toute autre adresse (ou un
 * appel non prévu) fait échouer le test. Renvoie le résultat du rappel et les appels reçus.
 *
 * @param array|null $profil  Profil renvoyé par users/@me (null : erreur 401).
 * @param callable   $rappel  Fonction.
 * @param int        $code    Statut HTTP de l'échange du code.
 * @return array{resultat:mixed,appels:array<int,array{url:string,args:array}>}
 * @throws Yume_Test_Failure Requête vers une adresse non simulée.
 */
function yume_td_discord( ?array $profil, callable $rappel, int $code = 200 ): array {
	$appels  = array();
	$reponse = static function ( int $statut, $corps ): array {
		return array(
			'headers'  => array(),
			'body'     => is_string( $corps ) ? $corps : (string) wp_json_encode( $corps ),
			'response' => array(
				'code'    => $statut,
				'message' => 200 === $statut ? 'OK' : 'Erreur',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$filtre  = static function ( $pre, $args, $url ) use ( &$appels, $profil, $code, $reponse ) {
		$appels[] = array(
			'url'  => (string) $url,
			'args' => (array) $args,
		);
		switch ( $url ) {
			case 'https://discord.com/api/v10/oauth2/token':
				return 200 === $code
					? $reponse(
						200,
						array(
							'access_token'  => YUME_TD_JETON,
							'token_type'    => 'Bearer',
							'expires_in'    => 604800,
							'refresh_token' => YUME_TD_RAFRAICHI,
							'scope'         => 'identify email',
						)
					)
					: $reponse( $code, array( 'error' => 'invalid_grant' ) );
			case 'https://discord.com/api/v10/users/@me':
				return null === $profil ? $reponse( 401, array( 'message' => '401: Unauthorized' ) ) : $reponse( 200, $profil );
			case 'https://discord.com/api/v10/oauth2/token/revoke':
				return $reponse( 200, '' );
		}
		throw new Yume_Test_Failure( 'Requête réseau réelle interdite : ' . $url );
	};
	add_filter( 'pre_http_request', $filtre, 10, 3 );
	try {
		$resultat = $rappel();
	} finally {
		remove_filter( 'pre_http_request', $filtre, 10 );
	}
	return array(
		'resultat' => $resultat,
		'appels'   => $appels,
	);
}

/**
 * Départ (demarrer()) sans envoi réel du cookie : renvoie l'adresse de Discord, le state et le
 * cookie posé.
 *
 * @param string $mode        connexion ou liaison.
 * @param int    $user_id     Compte (liaison).
 * @param string $redirect_to Page demandée.
 * @param string $origine     Page du bouton.
 * @return array{url:string,state:string,cookie:string,expire:int}
 */
function yume_td_demarrer( string $mode = 'connexion', int $user_id = 0, string $redirect_to = '', string $origine = '' ): array {
	$cookie = array(
		'valeur' => '',
		'expire' => 0,
	);
	$note   = static function ( $envoyer, $valeur, $expire ) use ( &$cookie ) {
		$cookie = array(
			'valeur' => (string) $valeur,
			'expire' => (int) $expire,
		);
		return false;
	};
	add_filter( 'yume_discord_envoyer_cookie', $note, 10, 3 );
	try {
		$url = Discord\demarrer( $mode, $user_id, $redirect_to, '' !== $origine ? $origine : home_url( '/connexion/' ) );
	} finally {
		remove_filter( 'yume_discord_envoyer_cookie', $note, 10 );
	}
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
	return array(
		'url'    => $url,
		'state'  => (string) ( $params['state'] ?? '' ),
		'cookie' => $cookie['valeur'],
		'expire' => $cookie['expire'],
	);
}

/**
 * Retour de Discord (traiter_retour()) avec un faux Discord, cookies de session interceptés
 * (send_auth_cookies coupé) : destination, appels HTTP, sessions ouvertes, wp_login.
 *
 * @param array      $get     Paramètres de l'adresse.
 * @param array      $cookie  Cookies.
 * @param array|null $profil  Profil Discord.
 * @return array{url:string,appels:array,sessions:int[],logins:string[],connecte:int}
 */
function yume_td_retour( array $get, array $cookie, ?array $profil ): array {
	$sessions = array();
	$logins   = array();
	$note     = static function ( $valeur, $expire, $expiration, $user_id ) use ( &$sessions ) {
		$sessions[] = (int) $user_id;
	};
	$login    = static function ( $identifiant ) use ( &$logins ) {
		$logins[] = (string) $identifiant;
	};
	add_filter( 'send_auth_cookies', '__return_false', 99 );
	add_filter( 'yume_discord_envoyer_cookie', '__return_false', 99 );
	add_action( 'set_logged_in_cookie', $note, 10, 4 );
	add_action( 'wp_login', $login );
	try {
		$sortie = yume_td_discord(
			$profil,
			static function () use ( $get, $cookie ) {
				return Discord\traiter_retour( $get, $cookie );
			}
		);
	} finally {
		remove_filter( 'send_auth_cookies', '__return_false', 99 );
		remove_filter( 'yume_discord_envoyer_cookie', '__return_false', 99 );
		remove_action( 'set_logged_in_cookie', $note, 10 );
		remove_action( 'wp_login', $login );
	}
	$connecte = get_current_user_id();
	wp_set_current_user( 0 );
	return array(
		'url'      => (string) $sortie['resultat']['url'],
		'appels'   => $sortie['appels'],
		'sessions' => $sessions,
		'logins'   => $logins,
		'connecte' => $connecte,
	);
}

/**
 * Parcours complet : départ puis retour avec ce profil.
 *
 * @param array|null $profil      Profil Discord.
 * @param string     $mode        Mode.
 * @param int        $user_id     Compte (liaison).
 * @param string     $redirect_to Page demandée.
 * @param array      $cookies     Cookies supplémentaires.
 */
function yume_td_parcours( ?array $profil, string $mode = 'connexion', int $user_id = 0, string $redirect_to = '', array $cookies = array() ): array {
	$depart = yume_td_demarrer( $mode, $user_id, $redirect_to, 'liaison' === $mode ? home_url( '/compte/' ) : home_url( '/connexion/' ) );
	return yume_td_retour(
		array(
			'code'  => 'code-autorisation-test',
			'state' => $depart['state'],
		),
		array_merge( array( 'yume_discord_etat' => $depart['cookie'] ), $cookies ),
		$profil
	);
}

/**
 * Code yn-discord d'une adresse ('' sinon).
 *
 * @param string $url Adresse.
 */
function yume_td_code( string $url ): string {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
	return (string) ( $params['yn-discord'] ?? '' );
}

/**
 * Compte de ce rôle avec un mot de passe connu.
 *
 * @param string $role Rôle.
 */
function yume_td_compte( string $role = 'subscriber' ): WP_User {
	$id = yume_factory_user( $role );
	wp_set_password( YUME_TD_MDP, $id );
	return get_userdata( $id );
}

/**
 * Appelle un traitement admin-post (départ, déliaison) : redirection interceptée.
 *
 * @param callable $traitement Fonction.
 * @param array    $post       Champs POST.
 * @param int      $user_id    Compte connecté (0 : visiteur).
 * @throws RuntimeException Toute exception autre que la redirection interceptée.
 */
function yume_td_post( callable $traitement, array $post, int $user_id = 0 ): string {
	$avant       = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$_POST       = wp_slash( $post );
	$redirection = static function ( $url ) {
		throw new RuntimeException( (string) $url, 302 );
	};
	add_filter( 'wp_redirect', $redirection, 1 );
	add_filter( 'yume_discord_envoyer_cookie', '__return_false', 99 );
	wp_set_current_user( $user_id );
	$url = '';
	try {
		$traitement();
	} catch ( RuntimeException $e ) {
		if ( 302 !== $e->getCode() ) {
			throw $e;
		}
		$url = $e->getMessage();
	} finally {
		$_POST = $avant;
		remove_filter( 'wp_redirect', $redirection, 1 );
		remove_filter( 'yume_discord_envoyer_cookie', '__return_false', 99 );
		wp_set_current_user( 0 );
	}
	return $url;
}

/**
 * Rendu du bloc yume/account (visiteur ou membre) à l'adresse donnée.
 *
 * @param int    $user_id Compte (0 : visiteur).
 * @param string $requete Chemin et requête.
 * @param array  $get     Paramètres GET.
 */
function yume_td_rendu( int $user_id, string $requete = '/connexion/', array $get = array() ): string {
	$serveur                = $_SERVER;
	$get_avant              = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
	$_SERVER['REQUEST_URI'] = $requete;
	$_GET                   = $get;
	wp_set_current_user( $user_id );
	try {
		return yume_render_block( 'yume/account' );
	} finally {
		$_SERVER = $serveur; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_GET    = $get_avant;
		wp_set_current_user( 0 );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Configuration
 * -----------------------------------------------------------------------------
 */

yume_test(
	'discord : configuration selon les réglages (case, Client ID, Client Secret), constante prioritaire',
	function () {
		yume_assert_false( Discord\discord_configure(), 'non configuré par défaut' );
		yume_td_configurer();
		yume_assert_true( Discord\discord_configure() );
		yume_assert_same( '112233445566778899', Discord\client_id() );
		yume_assert_same( 'secret-de-test', Discord\client_secret() );
		yume_td_configurer( array( 'discord_client_secret' => '' ) );
		yume_assert_false( Discord\discord_configure(), 'sans secret' );
		yume_td_configurer( array( 'discord_client_id' => '' ) );
		yume_assert_false( Discord\discord_configure(), 'sans Client ID' );
		yume_td_configurer( array( 'discord_connexion' => false ) );
		yume_assert_false( Discord\discord_configure(), 'case décochée' );

		// Constante de wp-config.php : valeur lue, champ verrouillé (nom propre aux tests).
		if ( ! defined( 'YUME_TD_CONSTANTE_DISCORD' ) ) {
			define( 'YUME_TD_CONSTANTE_DISCORD', ' valeur-constante ' );
		}
		yume_assert_same( 'valeur-constante', Discord\constante( 'YUME_TD_CONSTANTE_DISCORD' ) );
		yume_assert_contains( 'YUME_TD_CONSTANTE_DISCORD', Discord\verrou( 'YUME_TD_CONSTANTE_DISCORD' ) );
		yume_assert_same( '', Discord\verrou( 'YUME_TD_CONSTANTE_ABSENTE' ) );
		yume_assert_same( rest_url( 'yume/v1/discord/retour' ), Discord\url_retour() );
	}
);

yume_test(
	'discord : réglages (section, case, Client ID verrouillable, secret jamais réaffiché) et test Santé',
	function () {
		$champs = array();
		foreach ( Yume\Core\Core\champs_reglages() as $champ ) {
			$champs[ $champ['key'] ] = $champ;
		}
		yume_assert_true( isset( Yume\Core\Core\sections_reglages()['discord_connexion'] ) );
		yume_assert_same( 'checkbox', $champs['discord_connexion']['type'] );
		yume_assert_false( $champs['discord_connexion']['default'] );
		yume_assert_true( $champs['discord_inscription']['default'] );
		yume_assert_same( 'text', $champs['discord_client_id']['type'] );
		yume_assert_same( 'secret', $champs['discord_client_secret']['type'] );
		yume_assert_contains( Discord\url_retour(), $champs['discord_client_id']['description'], 'adresse de retour affichée' );
		yume_assert_same( '112233445566778899', call_user_func( $champs['discord_client_id']['sanitize'], ' 1122 3344-5566778899 ' ) );

		// Santé : rien sans configuration ; activé sans secret, secret en base → recommandé.
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		yume_assert_false( isset( $tests['direct']['yume_discord'] ), 'aucun test Santé sans configuration' );
		yume_td_configurer( array( 'discord_client_secret' => '' ) );
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		yume_assert_true( isset( $tests['direct']['yume_discord'] ) );
		$resultat = Discord\test_sante();
		yume_assert_same( 'recommended', $resultat['status'] );
		yume_assert_same( 'yume_discord', $resultat['test'] );
		yume_td_configurer();
		yume_assert_same( 'recommended', Discord\test_sante()['status'], 'secret en base plutôt qu’en constante' );
		yume_assert_not_contains( 'secret-de-test', wp_json_encode( Discord\test_sante() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Départ
 * -----------------------------------------------------------------------------
 */

yume_test(
	'discord : départ — transient, cookie lié au navigateur, adresse d’autorisation et PKCE S256',
	function () {
		yume_td_configurer();
		$depart = yume_td_demarrer( 'connexion', 0, home_url( '/oeuvres/exemple/' ) );
		yume_assert_true( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $depart['state'] ), 'state de 32 octets en base64url' );
		yume_assert_same( $depart['state'], $depart['cookie'], 'cookie = state' );
		yume_assert_true( $depart['expire'] > time() + 500 && $depart['expire'] <= time() + 600, 'cookie de 10 minutes' );
		yume_assert_true( str_starts_with( $depart['url'], 'https://discord.com/oauth2/authorize?' ) );
		yume_assert_contains( 'scope=identify%20email', $depart['url'] );
		yume_assert_contains( 'prompt=consent', $depart['url'] );
		parse_str( (string) wp_parse_url( $depart['url'], PHP_URL_QUERY ), $params );
		yume_assert_same( '112233445566778899', $params['client_id'] );
		yume_assert_same( 'code', $params['response_type'] );
		yume_assert_same( Discord\url_retour(), $params['redirect_uri'] );
		yume_assert_same( 'S256', $params['code_challenge_method'] );

		$etat = get_transient( 'yume_discord_etat_' . hash( 'sha256', $depart['state'] ) );
		yume_assert_true( is_array( $etat ) );
		yume_assert_same( 'connexion', $etat['mode'] );
		yume_assert_same( home_url( '/oeuvres/exemple/' ), $etat['redirect_to'] );
		yume_assert_true( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $etat['verifier'] ), 'code_verifier conforme (RFC 7636)' );
		$attendu = rtrim( strtr( base64_encode( hash( 'sha256', $etat['verifier'], true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		yume_assert_same( $attendu, $params['code_challenge'], 'code_challenge = BASE64URL(SHA256(verifier))' );

		// PKCE coupé par le filtre : ni challenge ni verifier.
		add_filter( 'yume_discord_pkce', '__return_false' );
		try {
			$sans = yume_td_demarrer();
		} finally {
			remove_filter( 'yume_discord_pkce', '__return_false' );
		}
		yume_assert_not_contains( 'code_challenge', $sans['url'] );
		yume_assert_same( '', get_transient( 'yume_discord_etat_' . hash( 'sha256', $sans['state'] ) )['verifier'] );
	}
);

yume_test(
	'discord : formulaire de départ (non configuré, visiteur, membre connecté, liaison sans nonce)',
	function () {
		$traiter = 'Yume\Core\Discord\traiter_depart';
		$origine = home_url( '/connexion/' );
		$url     = yume_td_post(
			$traiter,
			array(
				'mode'       => 'connexion',
				'yn_origine' => $origine,
			)
		);
		yume_assert_same( $origine, $url, 'non configuré : retour sur la page, sans Discord' );

		yume_td_configurer();
		$url = yume_td_post(
			$traiter,
			array(
				'mode'        => 'connexion',
				'yn_origine'  => $origine,
				'redirect_to' => 'https://ailleurs.example/piege',
			)
		);
		yume_assert_true( str_starts_with( $url, 'https://discord.com/oauth2/authorize?' ), 'visiteur → Discord' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$etat = get_transient( 'yume_discord_etat_' . hash( 'sha256', $params['state'] ) );
		yume_assert_same( '', $etat['redirect_to'], 'redirect_to externe ignoré' );
		yume_assert_same( 0, $etat['user_id'] );

		$lecteur = yume_td_compte();
		$url     = yume_td_post( $traiter, array( 'mode' => 'connexion' ), $lecteur->ID );
		yume_assert_not_contains( 'discord.com', $url, 'déjà connecté : pas de nouvelle connexion' );

		$url = yume_td_post(
			$traiter,
			array(
				'mode'       => 'liaison',
				'yn_origine' => home_url( '/compte/' ),
				'_yn_nonce'  => 'invalide',
			),
			$lecteur->ID
		);
		yume_assert_contains( 'yn-msg=session', $url, 'liaison : nonce exigé' );

		wp_set_current_user( $lecteur->ID );
		$nonce = wp_create_nonce( 'yume_discord_depart' );
		wp_set_current_user( 0 );
		$url = yume_td_post(
			$traiter,
			array(
				'mode'       => 'liaison',
				'yn_origine' => home_url( '/compte/' ),
				'_yn_nonce'  => $nonce,
			),
			$lecteur->ID
		);
		yume_assert_true( str_starts_with( $url, 'https://discord.com/oauth2/authorize?' ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$etat = get_transient( 'yume_discord_etat_' . hash( 'sha256', $params['state'] ) );
		yume_assert_same( 'liaison', $etat['mode'] );
		yume_assert_same( $lecteur->ID, $etat['user_id'] );

		// Liaison demandée par un visiteur : page de connexion, pas Discord.
		$url = yume_td_post( $traiter, array( 'mode' => 'liaison' ) );
		yume_assert_not_contains( 'discord.com', $url );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Retour
 * -----------------------------------------------------------------------------
 */

yume_test(
	'discord : retour refusé sans state valide (absent, cookie différent, expiré, réutilisé) et sur refus',
	function () {
		yume_td_configurer();
		$profil = yume_td_profil();

		$sans = yume_td_retour( array( 'code' => 'x' ), array(), $profil );
		yume_assert_same( 'expire', yume_td_code( $sans['url'] ) );
		yume_assert_same( array(), $sans['appels'], 'aucun appel à Discord' );

		$depart = yume_td_demarrer();
		$autre  = yume_td_retour(
			array(
				'code'  => 'x',
				'state' => $depart['state'],
			),
			array( 'yume_discord_etat' => 'autre-navigateur' ),
			$profil
		);
		yume_assert_same( 'expire', yume_td_code( $autre['url'] ), 'cookie d’un autre navigateur' );
		yume_assert_same( array(), $autre['appels'] );

		$depart = yume_td_demarrer();
		delete_transient( 'yume_discord_etat_' . hash( 'sha256', $depart['state'] ) );
		$expire = yume_td_retour(
			array(
				'code'  => 'x',
				'state' => $depart['state'],
			),
			array( 'yume_discord_etat' => $depart['cookie'] ),
			$profil
		);
		yume_assert_same( 'expire', yume_td_code( $expire['url'] ), 'transient expiré' );

		$depart = yume_td_demarrer();
		$get    = array(
			'error' => 'access_denied',
			'state' => $depart['state'],
		);
		$refus  = yume_td_retour( $get, array( 'yume_discord_etat' => $depart['cookie'] ), $profil );
		yume_assert_same( 'refuse', yume_td_code( $refus['url'] ) );
		yume_assert_same( array(), $refus['appels'] );
		yume_assert_contains( '#yn-bloc-connexion', $refus['url'] );
		yume_assert_true( str_starts_with( $refus['url'], home_url( '/connexion/' ) ), 'retour sur la page du bouton' );
		$encore = yume_td_retour( $get, array( 'yume_discord_etat' => $depart['cookie'] ), $profil );
		yume_assert_same( 'expire', yume_td_code( $encore['url'] ), 'state à usage unique' );

		// Erreur de Discord à l'échange du code.
		$depart = yume_td_demarrer();
		$sortie = yume_td_discord(
			$profil,
			static function () use ( $depart ) {
				return Discord\traiter_retour(
					array(
						'code'  => 'perime',
						'state' => $depart['state'],
					),
					array( 'yume_discord_etat' => $depart['cookie'] )
				);
			},
			400
		);
		yume_assert_same( 'erreur', yume_td_code( $sortie['resultat']['url'] ) );
	}
);

yume_test(
	'discord : échange du code (PKCE, redirect_uri exacte), profil avec Bearer, jeton révoqué et jamais stocké',
	function () {
		global $wpdb;
		yume_td_configurer();
		$profil = yume_td_profil();
		$sortie = yume_td_parcours( $profil );
		yume_assert_same( 3, count( $sortie['appels'] ), 'jeton, profil, révocation' );

		$echange = $sortie['appels'][0];
		yume_assert_same( 'https://discord.com/api/v10/oauth2/token', $echange['url'] );
		yume_assert_same( 'POST', $echange['args']['method'] );
		yume_assert_same( 'authorization_code', $echange['args']['body']['grant_type'] );
		yume_assert_same( 'code-autorisation-test', $echange['args']['body']['code'] );
		yume_assert_same( Discord\url_retour(), $echange['args']['body']['redirect_uri'] );
		yume_assert_same( '112233445566778899', $echange['args']['body']['client_id'] );
		yume_assert_true( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $echange['args']['body']['code_verifier'] ) );
		yume_assert_same( 10, (int) $echange['args']['timeout'] );
		yume_assert_true( $echange['args']['reject_unsafe_urls'], 'wp_safe_remote_post' );
		yume_assert_contains( 'YumeNovel/', $echange['args']['user-agent'] );

		yume_assert_same( 'https://discord.com/api/v10/users/@me', $sortie['appels'][1]['url'] );
		yume_assert_same( 'Bearer ' . YUME_TD_JETON, $sortie['appels'][1]['args']['headers']['Authorization'] );
		yume_assert_same( 'https://discord.com/api/v10/oauth2/token/revoke', $sortie['appels'][2]['url'] );
		yume_assert_same( YUME_TD_JETON, $sortie['appels'][2]['args']['body']['token'] );

		// Aucun jeton dans les options (transients compris) ni dans les métas.
		foreach ( array( YUME_TD_JETON, YUME_TD_RAFRAICHI ) as $jeton ) {
			$like = '%' . $wpdb->esc_like( $jeton ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			yume_assert_same( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", $like ) ), 'jeton absent des options' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			yume_assert_same( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_value LIKE %s", $like ) ), 'jeton absent des métas' );
		}
		// Le transient d'état est consommé.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		yume_assert_same( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_yume_discord_etat_%' ) ) );
	}
);

yume_test(
	'discord : adresse Discord non vérifiée jamais utilisée',
	function () {
		yume_td_configurer();
		update_option( 'users_can_register', 1 );
		$profil = yume_td_profil( array( 'verified' => false ) );
		$sortie = yume_td_parcours( $profil );
		yume_assert_same( 'non-verifie', yume_td_code( $sortie['url'] ) );
		yume_assert_false( (bool) email_exists( $profil['email'] ), 'aucun compte créé' );
		yume_assert_same( array(), $sortie['sessions'] );

		// Même un compte existant avec cette adresse n'est ni lié ni ouvert.
		$existant = yume_td_compte();
		$profil   = yume_td_profil(
			array(
				'verified' => false,
				'email'    => $existant->user_email,
			)
		);
		$sortie   = yume_td_parcours( $profil );
		yume_assert_same( 'non-verifie', yume_td_code( $sortie['url'] ) );
		yume_assert_same( '', (string) get_user_meta( $existant->ID, Discord\META_ID, true ) );

		$sans = yume_td_parcours( yume_td_profil( array( 'email' => null ) ) );
		yume_assert_same( 'non-verifie', yume_td_code( $sans['url'] ), 'sans adresse' );
	}
);

yume_test(
	'discord : compte lié → session ouverte (cookie, wp_login, login_redirect), page demandée',
	function () {
		yume_td_configurer();
		$lecteur = yume_td_compte();
		$profil  = yume_td_profil();
		update_user_meta( $lecteur->ID, Discord\META_ID, $profil['id'] );
		$page   = home_url( '/oeuvres/exemple/' );
		$sortie = yume_td_parcours( $profil, 'connexion', 0, $page );
		yume_assert_same( $page, $sortie['url'] );
		yume_assert_same( array( $lecteur->ID ), $sortie['sessions'], 'cookie de session posé' );
		yume_assert_same( array( $lecteur->user_login ), $sortie['logins'], 'wp_login déclenchée' );
		yume_assert_same( $lecteur->ID, $sortie['connecte'] );

		$sans_page = yume_td_parcours( $profil );
		yume_assert_same( Yume\Core\Social\url_compte_sure(), $sans_page['url'], 'défaut : compte' );

		// Traducteur (équipe non administratrice) : accepté.
		$traducteur = yume_td_compte( 'yume_traducteur' );
		$autre      = yume_td_profil( array( 'id' => '80351110224678913' ) );
		update_user_meta( $traducteur->ID, Discord\META_ID, $autre['id'] );
		yume_assert_same( array( $traducteur->ID ), yume_td_parcours( $autre )['sessions'] );
	}
);

yume_test(
	'discord : administrateur lié refusé (reserve, aucune session), filtre yume_discord_connexion_autorisee',
	function () {
		yume_td_configurer();
		$admin  = yume_td_compte( 'administrator' );
		$profil = yume_td_profil();
		update_user_meta( $admin->ID, Discord\META_ID, $profil['id'] );
		$sortie = yume_td_parcours( $profil );
		yume_assert_same( 'reserve', yume_td_code( $sortie['url'] ) );
		yume_assert_same( array(), $sortie['sessions'] );
		yume_assert_same( array(), $sortie['logins'] );
		yume_assert_same( 0, $sortie['connecte'] );

		$lecteur = yume_td_compte();
		yume_assert_true( Discord\connexion_autorisee( $lecteur ) );
		add_filter( 'yume_discord_connexion_autorisee', '__return_false' );
		try {
			yume_assert_false( Discord\connexion_autorisee( $lecteur ) );
		} finally {
			remove_filter( 'yume_discord_connexion_autorisee', '__return_false' );
		}
	}
);

yume_test(
	'discord : adresse déjà inscrite → pas de liaison automatique (sauf filtre explicite)',
	function () {
		yume_td_configurer();
		update_option( 'users_can_register', 1 );
		$existant = yume_td_compte();
		$profil   = yume_td_profil( array( 'email' => $existant->user_email ) );
		$sortie   = yume_td_parcours( $profil );
		yume_assert_same( 'email-existant', yume_td_code( $sortie['url'] ) );
		yume_assert_same( '', (string) get_user_meta( $existant->ID, Discord\META_ID, true ), 'aucune liaison' );
		yume_assert_same( array(), $sortie['sessions'] );

		add_filter( 'yume_discord_lier_par_email_verifie', '__return_true' );
		try {
			$lie = yume_td_parcours( $profil );
		} finally {
			remove_filter( 'yume_discord_lier_par_email_verifie', '__return_true' );
		}
		yume_assert_same( $profil['id'], (string) get_user_meta( $existant->ID, Discord\META_ID, true ) );
		yume_assert_same( array( $existant->ID ), $lie['sessions'] );
	}
);

yume_test(
	'discord : nouveau compte créé par creer_compte_lecteur() (source discord, sans mot de passe, pseudo suffixé)',
	function () {
		yume_td_configurer();
		update_option( 'users_can_register', 1 );
		$sources = array();
		$crees   = array();
		$mails   = 0;
		$inscrit = static function ( $user_id, $source = '' ) use ( &$sources ) {
			$sources[ (int) $user_id ] = (string) $source;
		};
		$cree    = static function ( $user_id ) use ( &$crees ) {
			$crees[] = (int) $user_id;
		};
		$mail    = static function ( $args ) use ( &$mails ) {
			++$mails;
			return $args;
		};
		// Pseudo déjà porté : « Mio des Brumes-2 ».
		$autre = yume_factory_user();
		wp_update_user(
			array(
				'ID'           => $autre,
				'display_name' => 'Mio des Brumes',
			)
		);
		add_action( 'yume_lecteur_inscrit', $inscrit, 10, 2 );
		add_action( 'yume_discord_compte_cree', $cree );
		add_filter( 'wp_mail', $mail );
		$page = home_url( '/oeuvres/exemple/' );
		try {
			$profil = yume_td_profil();
			$sortie = yume_td_parcours( $profil, 'connexion', 0, $page );
		} finally {
			remove_action( 'yume_lecteur_inscrit', $inscrit, 10 );
			remove_action( 'yume_discord_compte_cree', $cree );
			remove_filter( 'wp_mail', $mail );
		}
		$user_id = (int) email_exists( $profil['email'] );
		yume_assert_true( $user_id > 0, 'compte créé' );
		$user = get_userdata( $user_id );
		yume_assert_same( array( 'subscriber' ), array_values( $user->roles ) );
		yume_assert_same( 'Mio des Brumes-2', $user->display_name );
		yume_assert_true( 1 === preg_match( '/^yn-[a-z0-9]{8}$/', $user->user_login ), 'identifiant généré' );
		yume_assert_same( 'discord', $sources[ $user_id ] ?? '' );
		yume_assert_same( array( $user_id ), $crees );
		yume_assert_same( 0, $mails, 'aucun e-mail « choisissez votre mot de passe »' );
		yume_assert_true( Yume\Core\Social\compte_sans_mot_de_passe( $user_id ) );
		yume_assert_same( $profil['id'], (string) get_user_meta( $user_id, Discord\META_ID, true ) );
		yume_assert_same( 'mio_brumes', (string) get_user_meta( $user_id, Discord\META_PSEUDO, true ) );
		yume_assert_true( '' !== (string) get_user_meta( $user_id, Discord\META_LIE_LE, true ) );
		yume_assert_same( '', (string) get_user_meta( $user_id, 'avatar', true ), 'avatar jamais conservé' );
		yume_assert_same( array( $user_id ), $sortie['sessions'], 'session ouverte' );
		yume_assert_same( $page, $sortie['url'] );

		// Second passage : même compte, aucune nouvelle création.
		$encore = yume_td_parcours( $profil );
		yume_assert_same( array( $user_id ), $encore['sessions'] );

		// Pseudo invalide (réservé, trop court) : nom d'utilisateur, puis « Lecteur Discord ».
		$reserve = yume_td_profil(
			array(
				'id'          => '80351110224678999',
				'global_name' => 'Admin',
				'username'    => 'x',
			)
		);
		yume_td_parcours( $reserve );
		yume_assert_same( 'Lecteur Discord', get_userdata( (int) email_exists( $reserve['email'] ) )->display_name );
	}
);

yume_test(
	'discord : inscriptions fermées ou création désactivée → aucun compte créé (message dédié)',
	function () {
		yume_td_configurer();
		update_option( 'users_can_register', 0 );
		$profil = yume_td_profil();
		$sortie = yume_td_parcours( $profil );
		yume_assert_same( 'inscriptions-fermees', yume_td_code( $sortie['url'] ) );
		yume_assert_false( (bool) email_exists( $profil['email'] ) );
		yume_assert_same( array(), $sortie['sessions'] );

		// Un compte déjà lié se connecte quand même.
		$lecteur = yume_td_compte();
		update_user_meta( $lecteur->ID, Discord\META_ID, $profil['id'] );
		yume_assert_same( array( $lecteur->ID ), yume_td_parcours( $profil )['sessions'] );

		update_option( 'users_can_register', 1 );
		yume_td_configurer( array( 'discord_inscription' => false ) );
		$nouveau = yume_td_profil( array( 'id' => '80351110224678000' ) );
		yume_assert_same( 'inscriptions-fermees', yume_td_code( yume_td_parcours( $nouveau )['url'] ) );
		yume_assert_false( (bool) email_exists( $nouveau['email'] ) );
	}
);

yume_test(
	'discord : liaison depuis Mon compte (compte du navigateur, déjà lié ailleurs, cookie de session)',
	function () {
		yume_td_configurer();
		$lecteur = yume_td_compte();
		$profil  = yume_td_profil();
		$lies    = array();
		$suivi   = static function ( $user_id, $discord_id ) use ( &$lies ) {
			$lies[] = array( (int) $user_id, (string) $discord_id );
		};
		add_action( 'yume_discord_lie', $suivi, 10, 2 );
		try {
			wp_set_current_user( $lecteur->ID );
			$sortie = yume_td_parcours( $profil, 'liaison', $lecteur->ID );
		} finally {
			remove_action( 'yume_discord_lie', $suivi, 10 );
		}
		yume_assert_same( 'lie', yume_td_code( $sortie['url'] ) );
		yume_assert_contains( '#yn-discord', $sortie['url'] );
		yume_assert_same( $profil['id'], Discord\discord_lie( $lecteur->ID ) );
		yume_assert_same( array( array( $lecteur->ID, $profil['id'] ) ), $lies );
		yume_assert_same( array(), $sortie['sessions'], 'pas de nouvelle session' );

		// Même compte Discord, autre compte du site : refusé.
		$autre = yume_td_compte();
		wp_set_current_user( $autre->ID );
		$refus = yume_td_parcours( $profil, 'liaison', $autre->ID );
		yume_assert_same( 'deja-lie', yume_td_code( $refus['url'] ) );
		yume_assert_same( '', Discord\discord_lie( $autre->ID ) );
		yume_assert_true( is_wp_error( Discord\lier( $autre->ID, $profil ) ) );

		// Le compte connecté n'est pas celui du départ : refusé.
		wp_set_current_user( $autre->ID );
		$profil2 = yume_td_profil( array( 'id' => '80351110224670001' ) );
		$vol     = yume_td_parcours( $profil2, 'liaison', $lecteur->ID );
		yume_assert_same( 'expire', yume_td_code( $vol['url'] ) );
		yume_assert_same( 0, Discord\utilisateur_par_discord( $profil2['id'] ) );

		// La REST ignore le cookie de session sans nonce : le cookie logged_in est lu directement.
		$cookie = wp_generate_auth_cookie( $autre->ID, time() + HOUR_IN_SECONDS, 'logged_in' );
		$ok     = yume_td_parcours( $profil2, 'liaison', $autre->ID, '', array( LOGGED_IN_COOKIE => $cookie ) );
		yume_assert_same( 'lie', yume_td_code( $ok['url'] ) );
		yume_assert_same( $profil2['id'], Discord\discord_lie( $autre->ID ) );

		// Administrateur : liaison refusée.
		$admin = yume_td_compte( 'administrator' );
		wp_set_current_user( $admin->ID );
		$profil3 = yume_td_profil( array( 'id' => '80351110224670002' ) );
		yume_assert_same( 'reserve', yume_td_code( yume_td_parcours( $profil3, 'liaison', $admin->ID )['url'] ) );
	}
);

yume_test(
	'discord : délier exige le nonce, un mot de passe défini et le mot de passe actuel',
	function () {
		yume_td_configurer();
		$lecteur = yume_td_compte();
		Discord\lier( $lecteur->ID, yume_td_profil() );
		$delies = array();
		$suivi  = static function ( $user_id ) use ( &$delies ) {
			$delies[] = (int) $user_id;
		};
		wp_set_current_user( $lecteur->ID );
		$nonce = wp_create_nonce( 'yume_discord_delier' );
		wp_set_current_user( 0 );
		$traiter = 'Yume\Core\Discord\traiter_delier';
		$base    = array(
			'yn_retour' => home_url( '/compte/' ),
			'_yn_nonce' => $nonce,
		);

		$url = yume_td_post( $traiter, array_merge( $base, array( '_yn_nonce' => 'invalide' ) ), $lecteur->ID );
		yume_assert_contains( 'yn-msg=session', $url );

		$url = yume_td_post( $traiter, array_merge( $base, array( 'yn_mdp_actuel' => 'mauvais' ) ), $lecteur->ID );
		yume_assert_contains( 'yn-msg=mdp-actuel', $url );
		yume_assert_true( '' !== Discord\discord_lie( $lecteur->ID ), 'toujours lié' );

		update_user_meta( $lecteur->ID, Yume\Core\Social\META_COMPTE_SANS_MDP, 1 );
		$url = yume_td_post( $traiter, array_merge( $base, array( 'yn_mdp_actuel' => YUME_TD_MDP ) ), $lecteur->ID );
		yume_assert_same( 'mdp', yume_td_code( $url ), 'compte sans mot de passe défini' );
		yume_assert_true( '' !== Discord\discord_lie( $lecteur->ID ) );
		delete_user_meta( $lecteur->ID, Yume\Core\Social\META_COMPTE_SANS_MDP );

		add_action( 'yume_discord_delie', $suivi );
		try {
			$url = yume_td_post( $traiter, array_merge( $base, array( 'yn_mdp_actuel' => YUME_TD_MDP ) ), $lecteur->ID );
		} finally {
			remove_action( 'yume_discord_delie', $suivi );
		}
		yume_assert_same( 'delie', yume_td_code( $url ) );
		yume_assert_contains( '#yn-discord', $url );
		yume_assert_same( '', Discord\discord_lie( $lecteur->ID ) );
		yume_assert_false( metadata_exists( 'user', $lecteur->ID, Discord\META_PSEUDO ) );
		yume_assert_same( array( $lecteur->ID ), $delies );
		yume_assert_false( Discord\delier( $lecteur->ID ), 'déjà délié' );
	}
);

yume_test(
	'discord : limite de retours par adresse IP (filtre yume_discord_limites)',
	function () {
		yume_td_configurer();
		$limite                 = static function (): array {
			return array(
				'max'   => 2,
				'duree' => 900,
			);
		};
		$avant                  = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		add_filter( 'yume_discord_limites', $limite );
		try {
			yume_td_retour( array(), array(), null );
			yume_td_retour( array(), array(), null );
			$depart = yume_td_demarrer();
			$sortie = yume_td_retour(
				array(
					'code'  => 'x',
					'state' => $depart['state'],
				),
				array( 'yume_discord_etat' => $depart['cookie'] ),
				yume_td_profil()
			);
			yume_assert_same( 'limite', yume_td_code( $sortie['url'] ) );
			yume_assert_same( array(), $sortie['appels'], 'aucun appel à Discord une fois bloqué' );
			$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
			yume_assert_same( 'expire', yume_td_code( yume_td_retour( array(), array(), null )['url'] ), 'autre adresse non bloquée' );
		} finally {
			remove_filter( 'yume_discord_limites', $limite );
			$_SERVER['REMOTE_ADDR'] = $avant;
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Route REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'discord : route GET /discord/retour — 404 sans configuration, 302 nocache sinon, servie sans corps',
	function () {
		$reponse = yume_rest( 'GET', '/yume/v1/discord/retour', array( 'state' => 'x' ) );
		yume_assert_same( 404, $reponse->get_status() );
		yume_assert_same( 'yume_discord_inactif', $reponse->get_data()['code'] );

		yume_td_configurer();
		$avant   = $_COOKIE;
		$_COOKIE = array();
		add_filter( 'yume_discord_envoyer_cookie', '__return_false' );
		try {
			$reponse = yume_rest( 'GET', '/yume/v1/discord/retour', array( 'state' => 'inconnu' ) );
		} finally {
			remove_filter( 'yume_discord_envoyer_cookie', '__return_false' );
			$_COOKIE = $avant;
		}
		yume_assert_same( 302, $reponse->get_status() );
		$entetes = $reponse->get_headers();
		yume_assert_same( 'expire', yume_td_code( $entetes['Location'] ) );
		yume_assert_contains( 'no-cache', $entetes['Cache-Control'] ?? '' );
		yume_assert_true( Discord\servir_retour( false, $reponse, new WP_REST_Request( 'GET', '/yume/v1/discord/retour' ) ), 'pas de corps JSON' );
		yume_assert_false( Discord\servir_retour( false, $reponse, new WP_REST_Request( 'GET', '/yume/v1/planning' ) ) );
		yume_assert_false( Discord\servir_retour( false, new WP_REST_Response( array(), 404 ), new WP_REST_Request( 'GET', '/yume/v1/discord/retour' ) ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Boutons et rubrique du compte
 * -----------------------------------------------------------------------------
 */

yume_test(
	'discord : sans configuration, aucun bouton ni rubrique lecteur ; l’équipe lit « non configurée »',
	function () {
		update_option( 'users_can_register', 1 );
		$visiteur = yume_td_rendu( 0, '/connexion/', array( 'yn-discord' => 'email-existant' ) );
		yume_assert_not_contains( 'yume_discord_depart', $visiteur );
		yume_assert_not_contains( 'yn-btn--discord', $visiteur );
		yume_assert_not_contains( 'Un compte existe déjà', $visiteur, 'aucun message Discord' );
		yume_assert_same( 'x', apply_filters( 'yume_compte_html_connexion_extra', 'x', '' ) );
		yume_assert_same( 'x', apply_filters( 'yume_compte_html_inscription_extra', 'x', home_url( '/' ) ) );

		$lecteur = yume_td_compte();
		yume_assert_not_contains( 'yn-discord', yume_td_rendu( $lecteur->ID, '/compte/' ) );

		$traducteur = yume_td_compte( 'yume_traducteur' );
		$equipe     = yume_td_rendu( $traducteur->ID, '/compte/' );
		yume_assert_contains( 'data-yn-onglet="yn-discord"', $equipe );
		yume_assert_contains( 'n’est pas configurée', $equipe );
		yume_assert_not_contains( 'yume_discord_depart', $equipe );
	}
);

yume_test(
	'discord : boutons de la page de connexion (connexion, création si inscriptions ouvertes, message de retour)',
	function () {
		yume_td_configurer();
		update_option( 'users_can_register', 1 );
		$demande = home_url( '/oeuvres/exemple/' );
		$html    = yume_td_rendu(
			0,
			'/connexion/?yn-discord=email-existant',
			array(
				'yn-discord'  => 'email-existant',
				'redirect_to' => $demande,
			)
		);
		yume_assert_same( 2, substr_count( $html, 'name="action" value="yume_discord_depart"' ), 'connexion + création' );
		yume_assert_contains( 'Se connecter avec Discord', $html );
		yume_assert_contains( 'Créer un compte avec Discord', $html );
		yume_assert_contains( 'class="yn-btn yn-btn--discord"', $html );
		yume_assert_contains( 'name="redirect_to" value="' . esc_url( $demande ) . '"', $html );
		yume_assert_contains( 'Un compte existe déjà avec cette adresse', $html );
		yume_assert_contains( 'role="alert"', $html );
		yume_assert_not_contains( 'name="_yn_nonce" value="' . wp_create_nonce( 'yume_discord_depart' ), $html, 'pas de nonce pour un visiteur' );
		preg_match_all( '/<form class="yn-discord__formulaire".*?<\/form>/s', $html, $formulaires );
		yume_assert_same( 2, count( $formulaires[0] ) );
		yume_assert_not_contains( 'yn-discord=', implode( '', $formulaires[0] ), 'origine des boutons sans le paramètre de message' );
		yume_assert_true( wp_style_is( 'yume-discord', 'enqueued' ) );

		// Message réservé : lien vers la page WordPress.
		$reserve = yume_td_rendu( 0, '/connexion/', array( 'yn-discord' => 'reserve' ) );
		yume_assert_contains( 'Les administrateurs se connectent', $reserve );
		yume_assert_contains( 'wp-login.php', $reserve );

		// Inscriptions fermées : seulement « Se connecter avec Discord ».
		update_option( 'users_can_register', 0 );
		$ferme = yume_td_rendu( 0, '/connexion/' );
		yume_assert_same( 1, substr_count( $ferme, 'name="action" value="yume_discord_depart"' ) );
		yume_assert_not_contains( 'Créer un compte avec Discord', $ferme );
	}
);

yume_test(
	'discord : rubrique « Connexion Discord » de Mon compte (non lié, lié, sans mot de passe, administrateur)',
	function () {
		yume_td_configurer();
		$lecteur = yume_td_compte();
		$html    = yume_td_rendu( $lecteur->ID, '/compte/' );
		yume_assert_contains( 'data-yn-onglet="yn-discord"', $html );
		yume_assert_true( strpos( $html, 'id="yn-profil"' ) < strpos( $html, 'id="yn-discord"' ), 'après Profil et sécurité' );
		yume_assert_contains( 'Non lié', $html );
		yume_assert_contains( 'Lier mon compte Discord', $html );
		yume_assert_contains( 'name="mode" value="liaison"', $html );
		yume_assert_contains( 'name="_yn_nonce"', $html );

		Discord\lier(
			$lecteur->ID,
			yume_td_profil()
		);
		$lie = yume_td_rendu( $lecteur->ID, '/compte/', array( 'yn-discord' => 'lie' ) );
		yume_assert_contains( '@mio_brumes', $lie );
		yume_assert_contains( 'name="action" value="yume_discord_delier"', $lie );
		yume_assert_contains( 'autocomplete="current-password"', $lie );
		yume_assert_contains( 'Compte Discord lié', $lie );
		yume_assert_not_contains( 'Lier mon compte Discord', $lie );

		update_user_meta( $lecteur->ID, Yume\Core\Social\META_COMPTE_SANS_MDP, 1 );
		$sans = yume_td_rendu( $lecteur->ID, '/compte/' );
		yume_assert_not_contains( 'yume_discord_delier', $sans );
		yume_assert_contains( 'Mot de passe oublié', $sans );

		$admin = yume_td_compte( 'administrator' );
		$html  = yume_td_rendu( $admin->ID, '/compte/' );
		yume_assert_contains( 'data-yn-onglet="yn-discord"', $html );
		yume_assert_not_contains( 'Lier mon compte Discord', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * RGPD
 * -----------------------------------------------------------------------------
 */

yume_test(
	'discord : export (clé discord, exportateur yume-core-discord) et effacement des métas',
	function () {
		$lecteur = yume_td_compte();
		yume_td_sans_discord( $lecteur->ID );
		Discord\lier( $lecteur->ID, yume_td_profil() );
		$export = Yume\Core\Social\donnees_personnelles( $lecteur->ID );
		yume_assert_same( '80351110224678912', $export['discord']['id'] );
		yume_assert_same( 'mio_brumes', $export['discord']['pseudo'] );
		yume_assert_true( '' !== $export['discord']['lie_le'] );
		yume_assert_not_contains( YUME_TD_JETON, (string) wp_json_encode( $export ) );

		$exportateurs = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		yume_assert_true( isset( $exportateurs['yume-core-discord'] ) );
		$donnees = call_user_func( $exportateurs['yume-core-discord']['callback'], $lecteur->user_email, 1 );
		yume_assert_same( 'yume-discord', $donnees['data'][0]['group_id'] );

		$effaceurs = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$efface    = call_user_func( $effaceurs['yume-core-discord']['callback'], $lecteur->user_email, 1 );
		yume_assert_true( $efface['items_removed'] );
		yume_assert_same( '', Discord\discord_lie( $lecteur->ID ) );

		// Effacement des données du module lecteurs (yume_effacer_donnees).
		Discord\lier( $lecteur->ID, yume_td_profil() );
		Yume\Core\Social\effacer_donnees( $lecteur->ID );
		yume_assert_same( '', Discord\discord_lie( $lecteur->ID ) );
		yume_assert_false( metadata_exists( 'user', $lecteur->ID, Discord\META_LIE_LE ) );
	}
);

/**
 * Le compte n'a aucune donnée Discord exportée.
 *
 * @param int $user_id Compte.
 * @throws Yume_Test_Failure Donnée présente.
 */
function yume_td_sans_discord( int $user_id ): void {
	$export = Yume\Core\Social\donnees_personnelles( $user_id );
	if ( ! array_key_exists( 'discord', $export ) || null !== $export['discord'] ) {
		throw new Yume_Test_Failure( 'Export : clé discord attendue à null.' );
	}
}
