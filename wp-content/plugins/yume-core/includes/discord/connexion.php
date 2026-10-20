<?php
/**
 * Connexion avec Discord (OAuth2 Authorization Code + PKCE S256) :
 *
 * 1. départ : formulaire POST admin-post yume_discord_depart (bouton_html()) → demarrer() : state et
 *    code_verifier aléatoires (32 octets), mémorisés dans un transient de 10 minutes et liés au
 *    navigateur par le cookie yume_discord_etat (HttpOnly, SameSite=Lax, Secure en https), puis
 *    redirection vers la page d'autorisation de Discord ;
 * 2. retour : GET /wp-json/yume/v1/discord/retour (route publique, réponse 302 servie par
 *    rest_pre_serve_request, en-têtes nocache) → traiter_retour() : state du cookie = state reçu =
 *    transient (usage unique), limite par adresse IP, échange du code, profil, révocation du jeton
 *    (jamais stocké) ;
 * 3. résolution : compte lié → session (comme Social\traiter_connexion()) sauf administrateurs ;
 *    liaison depuis Mon compte ; sinon adresse vérifiée exigée, jamais de liaison automatique avec un
 *    compte existant, création d'un compte lecteur si les inscriptions sont ouvertes.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

/** Cookie qui lie le state au navigateur. */
const COOKIE_ETAT = 'yume_discord_etat';

/** Préfixe des transients d'état (suivi du sha256 du state). */
const PREFIXE_ETAT = 'yume_discord_etat_';

/** Durée de validité d'un départ (secondes). */
const DUREE_ETAT = 10 * MINUTE_IN_SECONDS;

/** Route REST de retour (espace de noms yume/v1). */
const ROUTE_RETOUR = '/discord/retour';

/*
 * -----------------------------------------------------------------------------
 * Départ
 * -----------------------------------------------------------------------------
 */

/**
 * Nom du transient d'un state.
 *
 * @param string $state State.
 */
function cle_etat( string $state ): string {
	return PREFIXE_ETAT . hash( 'sha256', $state );
}

/**
 * Pose (ou efface, avec une expiration passée) le cookie d'état. Le filtre
 * yume_discord_envoyer_cookie permet de couper l'envoi réel (tests), comme send_auth_cookies.
 *
 * @param string $valeur Valeur (state, '' pour effacer).
 * @param int    $expire Expiration (horodatage).
 */
function poser_cookie( string $valeur, int $expire ): void {
	/**
	 * Faut-il envoyer le cookie d'état de la connexion Discord ?
	 *
	 * @param bool   $envoyer Défaut : vrai.
	 * @param string $valeur  Valeur du cookie ('' : effacement).
	 * @param int    $expire  Expiration.
	 */
	if ( ! apply_filters( 'yume_discord_envoyer_cookie', true, $valeur, $expire ) || headers_sent() ) {
		return;
	}
	setcookie(
		COOKIE_ETAT,
		$valeur,
		array(
			'expires'  => $expire,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/**
 * Prépare une connexion Discord : state et code_verifier (transient de 10 minutes + cookie
 * yume_discord_etat), et renvoie l'adresse de la page d'autorisation de Discord.
 *
 * @param string $mode        connexion ou liaison.
 * @param int    $user_id     Compte connecté (liaison), 0 sinon.
 * @param string $redirect_to Page demandée après la connexion (validée au retour).
 * @param string $origine     Page du bouton (retour avec un message).
 */
function demarrer( string $mode, int $user_id, string $redirect_to, string $origine ): string {
	$state    = aleatoire();
	$verifier = pkce_actif() ? aleatoire() : '';
	set_transient(
		cle_etat( $state ),
		array(
			'verifier'    => $verifier,
			'mode'        => in_array( $mode, MODES, true ) ? $mode : 'connexion',
			'user_id'     => max( 0, $user_id ),
			'redirect_to' => $redirect_to,
			'origine'     => $origine,
			'cree'        => time(),
		),
		DUREE_ETAT
	);
	poser_cookie( $state, time() + DUREE_ETAT );
	return url_autorisation( $state, '' !== $verifier ? challenge( $verifier ) : '' );
}

/**
 * Valeur texte d'un champ POST.
 *
 * @param string $nom Nom du champ.
 */
function champ( string $nom ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant (membre connecté).
	return isset( $_POST[ $nom ] ) && is_string( $_POST[ $nom ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nom ] ) ) : '';
}

/**
 * Nonce du formulaire (champ _yn_nonce) valide pour cette action ?
 *
 * @param string $action Action.
 */
function nonce_valide( string $action ): bool {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- vérifié par wp_verify_nonce.
	$nonce = isset( $_POST['_yn_nonce'] ) && is_string( $_POST['_yn_nonce'] ) ? wp_unslash( $_POST['_yn_nonce'] ) : '';
	return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
}

/**
 * Redirige (adresse locale validée) puis s'arrête.
 *
 * @param string $url Adresse.
 */
function aller( string $url ): void {
	wp_safe_redirect( wp_validate_redirect( $url, url_compte() ) );
	exit;
}

/**
 * Formulaire « Se connecter avec Discord » / « Lier mon compte Discord » (admin-post
 * yume_discord_depart, visiteurs et membres).
 */
function traiter_depart(): void {
	nocache_headers();
	$mode     = 'liaison' === champ( 'mode' ) ? 'liaison' : 'connexion';
	$demandee = champ( 'redirect_to' );
	$demandee = '' !== $demandee ? wp_validate_redirect( esc_url_raw( $demandee ), '' ) : '';
	$ancre    = 'liaison' === $mode ? ANCRE_COMPTE : 'yn-bloc-connexion';
	$origine  = adresse_locale( champ( 'yn_origine' ), 'liaison' === $mode ? url_compte() : url_connexion() );
	$origine  = remove_query_arg( array( PARAM_MESSAGE, 'yn-msg', 'yn-identifiant', 'yn-minutes' ), $origine );
	if ( ! discord_configure() ) {
		aller( $origine );
	}
	$user_id = get_current_user_id();
	if ( 'liaison' === $mode ) {
		if ( ! $user_id ) {
			aller( url_connexion() );
		}
		if ( ! nonce_valide( 'yume_discord_depart' ) ) {
			aller( add_query_arg( 'yn-msg', 'session', url_message( $origine, '', '' ) ) . '#' . ANCRE_COMPTE );
		}
		if ( ! connexion_autorisee( wp_get_current_user() ) ) {
			aller( url_message( $origine, 'reserve', $ancre ) );
		}
		if ( '' !== discord_lie( $user_id ) ) {
			aller( url_message( $origine, '', $ancre ) );
		}
	} elseif ( $user_id ) {
		// Déjà connecté : comme Social\traiter_connexion().
		aller( '' !== $demandee ? $demandee : url_compte() );
	}
	$limites = limites();
	if ( \Yume\Core\Social\limite_atteinte( 'discord-depart', $limites['max'], $limites['duree'] ) ) {
		aller( url_message( $origine, 'limite', $ancre ) );
	}
	// Adresse externe fixe (page d'autorisation de Discord) : wp_safe_redirect() la refuserait.
	wp_redirect( demarrer( $mode, 'liaison' === $mode ? $user_id : 0, $demandee, $origine ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- adresse construite par url_autorisation() (discord.com).
	exit;
}
add_action( 'admin_post_nopriv_yume_discord_depart', __NAMESPACE__ . '\\traiter_depart' );
add_action( 'admin_post_yume_discord_depart', __NAMESPACE__ . '\\traiter_depart' );

/*
 * -----------------------------------------------------------------------------
 * Retour
 * -----------------------------------------------------------------------------
 */

/**
 * État d'un départ, s'il existe et si le state reçu est celui du cookie du navigateur ; le
 * transient est supprimé (usage unique).
 *
 * @param string $state        State reçu.
 * @param string $state_cookie State du cookie yume_discord_etat.
 * @return array<string,mixed>|null
 */
function consommer_etat( string $state, string $state_cookie ): ?array {
	if ( '' === $state || '' === $state_cookie || strlen( $state ) > 128 || ! hash_equals( $state_cookie, $state ) ) {
		return null;
	}
	$cle  = cle_etat( $state );
	$etat = get_transient( $cle );
	delete_transient( $cle );
	if ( ! is_array( $etat ) || (int) ( $etat['cree'] ?? 0 ) < time() - DUREE_ETAT ) {
		return null;
	}
	return $etat;
}

/**
 * Résultat de traiter_retour().
 *
 * @param string $url Destination.
 * @return array{url:string}
 */
function resultat( string $url ): array {
	return array( 'url' => wp_validate_redirect( $url, url_compte() ) );
}

/**
 * Traite le retour de Discord et renvoie la destination (message yn-discord au besoin). Ne lit
 * aucune superglobale : paramètres de l'adresse et cookies reçus en arguments.
 *
 * @param array $get    Paramètres de l'adresse (code, state, error).
 * @param array $cookie Cookies (yume_discord_etat, cookie de session WordPress pour la liaison).
 * @return array{url:string}
 */
function traiter_retour( array $get, array $cookie ): array {
	$texte = static function ( array $source, string $cle ): string {
		return isset( $source[ $cle ] ) && is_string( $source[ $cle ] ) ? trim( $source[ $cle ] ) : '';
	};
	if ( ! discord_configure() ) {
		return resultat( home_url( '/' ) );
	}
	$limites = limites();
	$bloque  = \Yume\Core\Social\limite_atteinte( 'discord-retour', $limites['max'], $limites['duree'] );
	$etat    = consommer_etat( $texte( $get, 'state' ), $texte( $cookie, COOKIE_ETAT ) );
	$mode    = is_array( $etat ) && 'liaison' === ( $etat['mode'] ?? '' ) ? 'liaison' : 'connexion';
	$ancre   = 'liaison' === $mode ? ANCRE_COMPTE : 'yn-bloc-connexion';
	$origine = adresse_locale( is_array( $etat ) ? (string) ( $etat['origine'] ?? '' ) : '', 'liaison' === $mode ? url_compte() : url_connexion() );
	$echec   = static function ( string $code ) use ( $origine, $ancre ): array {
		return resultat( url_message( $origine, $code, $ancre ) );
	};

	if ( $bloque ) {
		return $echec( 'limite' );
	}
	if ( null === $etat ) {
		return $echec( 'expire' );
	}
	if ( '' !== $texte( $get, 'error' ) ) {
		// access_denied : le visiteur a refusé l'autorisation sur Discord.
		return $echec( 'refuse' );
	}
	$code = $texte( $get, 'code' );
	if ( '' === $code || strlen( $code ) > 512 ) {
		return $echec( 'erreur' );
	}
	$jeton = echanger_code( $code, (string) ( $etat['verifier'] ?? '' ) );
	if ( is_wp_error( $jeton ) ) {
		return $echec( 'erreur' );
	}
	$acces = (string) $jeton['access_token'];
	unset( $jeton );
	try {
		$profil = profil_discord( $acces );
	} finally {
		// Le jeton n'est jamais conservé : révoqué dès le profil lu (échec ignoré).
		revoquer( $acces );
		$acces = '';
	}
	if ( is_wp_error( $profil ) ) {
		return $echec( 'erreur' );
	}

	if ( 'liaison' === $mode ) {
		return resoudre_liaison( $etat, $profil, $cookie, $echec );
	}
	return resoudre_connexion( $etat, $profil, $echec );
}

/**
 * Compte connecté dans ce navigateur : utilisateur courant, sinon cookie de session WordPress
 * (la REST ignore ce cookie sans nonce : la route de retour est une simple navigation).
 *
 * @param array $cookie Cookies reçus.
 */
function compte_connecte( array $cookie ): int {
	$user_id = get_current_user_id();
	if ( ! $user_id && defined( 'LOGGED_IN_COOKIE' ) && isset( $cookie[ LOGGED_IN_COOKIE ] ) && is_string( $cookie[ LOGGED_IN_COOKIE ] ) ) {
		$user_id = (int) wp_validate_auth_cookie( $cookie[ LOGGED_IN_COOKIE ], 'logged_in' );
	}
	return $user_id;
}

/**
 * Liaison depuis Mon compte : le compte du départ doit être celui connecté dans ce navigateur.
 *
 * @param array    $etat   État du départ.
 * @param array    $profil Profil Discord.
 * @param array    $cookie Cookies reçus.
 * @param callable $echec  Destination avec un code de message.
 * @return array{url:string}
 */
function resoudre_liaison( array $etat, array $profil, array $cookie, callable $echec ): array {
	$user_id = (int) ( $etat['user_id'] ?? 0 );
	if ( $user_id <= 0 || compte_connecte( $cookie ) !== $user_id ) {
		return $echec( 'expire' );
	}
	$user = get_userdata( $user_id );
	if ( ! $user instanceof \WP_User ) {
		return $echec( 'expire' );
	}
	if ( ! connexion_autorisee( $user ) ) {
		return $echec( 'reserve' );
	}
	$lie = lier( $user_id, $profil );
	if ( is_wp_error( $lie ) ) {
		return $echec( 'yume_discord_deja_lie' === $lie->get_error_code() ? 'deja-lie' : 'erreur' );
	}
	return $echec( 'lie' );
}

/**
 * Connexion (visiteur) : compte lié, sinon création d'un compte lecteur, jamais de liaison
 * automatique avec un compte existant (sauf filtre yume_discord_lier_par_email_verifie).
 *
 * @param array    $etat   État du départ.
 * @param array    $profil Profil Discord.
 * @param callable $echec  Destination avec un code de message.
 * @return array{url:string}
 */
function resoudre_connexion( array $etat, array $profil, callable $echec ): array {
	$demandee = (string) ( $etat['redirect_to'] ?? '' );
	$lie      = utilisateur_par_discord( (string) $profil['id'] );
	$user     = $lie > 0 ? get_userdata( $lie ) : false;
	if ( $user instanceof \WP_User ) {
		return connecter( $user, $demandee, $echec );
	}

	$email = (string) $profil['email'];
	if ( true !== $profil['verified'] || '' === $email || ! is_email( $email ) ) {
		return $echec( 'non-verifie' );
	}
	$existant = (int) email_exists( $email );
	if ( $existant > 0 ) {
		/**
		 * Faut-il lier automatiquement un compte Discord (adresse vérifiée) au compte du site qui a
		 * la même adresse ? Défaut : non (un compte Discord compromis ne doit pas ouvrir un compte du
		 * site) ; le titulaire se connecte avec son mot de passe puis lie Discord depuis Mon compte.
		 *
		 * @param bool  $lier     Défaut : faux.
		 * @param array $profil   Profil Discord (adresse vérifiée).
		 * @param int   $user_id  Compte du site qui a cette adresse.
		 */
		$user = apply_filters( 'yume_discord_lier_par_email_verifie', false, $profil, $existant ) ? get_userdata( $existant ) : false;
		if ( ! $user instanceof \WP_User ) {
			return $echec( 'email-existant' );
		}
		if ( ! connexion_autorisee( $user ) ) {
			return $echec( 'reserve' );
		}
		$resultat = lier( $existant, $profil );
		if ( is_wp_error( $resultat ) ) {
			return $echec( 'yume_discord_deja_lie' === $resultat->get_error_code() ? 'deja-lie' : 'erreur' );
		}
		return connecter( $user, $demandee, $echec );
	}

	if ( ! inscription_discord() || ! \Yume\Core\Social\inscriptions_ouvertes() ) {
		return $echec( 'inscriptions-fermees' );
	}
	$user_id = creer_compte( $profil );
	if ( is_wp_error( $user_id ) ) {
		$codes = array(
			'yume_inscriptions_fermees' => 'inscriptions-fermees',
			'yume_email_pris'           => 'email-existant',
		);
		return $echec( $codes[ $user_id->get_error_code() ] ?? 'erreur' );
	}
	$user = get_userdata( $user_id );
	if ( ! $user instanceof \WP_User ) {
		return $echec( 'erreur' );
	}
	return connecter( $user, $demandee, $echec );
}

/**
 * Ouvre la session d'un compte autorisé, sinon renvoie le message « reserve ».
 *
 * @param \WP_User $user     Compte.
 * @param string   $demandee Page demandée.
 * @param callable $echec    Destination avec un code de message.
 * @return array{url:string}
 */
function connecter( \WP_User $user, string $demandee, callable $echec ): array {
	if ( ! connexion_autorisee( $user ) ) {
		return $echec( 'reserve' );
	}
	return resultat( ouvrir_session( $user, $demandee ) );
}

/**
 * Pseudo public d'un nouveau compte : nom d'affichage Discord, sinon nom d'utilisateur, sinon
 * « Lecteur Discord » ; suffixe « -2 », « -3 »… si le pseudo est pris.
 *
 * @param array $profil Profil Discord.
 */
function pseudo_nouveau_compte( array $profil ): string {
	$bases = array();
	foreach ( array( $profil['global_name'] ?? '', $profil['username'] ?? '', __( 'Lecteur Discord', 'yume-core' ) ) as $brut ) {
		$base = (string) preg_replace( '/[<>@\x00-\x1F\x7F]+/u', '', (string) $brut );
		$base = trim( (string) preg_replace( '/\s+/u', ' ', $base ) );
		$base = trim( mb_substr( $base, 0, 36 ) );
		if ( mb_strlen( $base ) >= 2 && ! in_array( $base, $bases, true ) ) {
			$bases[] = $base;
		}
	}
	foreach ( $bases as $base ) {
		for ( $n = 1; $n <= 50; $n++ ) {
			$pseudo = 1 === $n ? $base : $base . '-' . $n;
			$valide = \Yume\Core\Social\valider_pseudo( $pseudo );
			if ( true === $valide ) {
				return $pseudo;
			}
			if ( 'yume_pseudo_pris' !== $valide->get_error_code() ) {
				continue 2; // Pseudo refusé (réservé…) : base suivante.
			}
		}
	}
	return __( 'Lecteur Discord', 'yume-core' ) . '-' . wp_rand( 1000, 999999 );
}

/**
 * Crée le compte lecteur d'un profil Discord vérifié (Social\creer_compte_lecteur(), source
 * discord, sans e-mail, métas de liaison et _yume_compte_sans_mdp), puis émet yume_discord_lie et
 * yume_discord_compte_cree.
 *
 * @param array $profil Profil Discord.
 * @return int|\WP_Error
 */
function creer_compte( array $profil ) {
	$metas = metas_liaison( $profil );
	$metas[ \Yume\Core\Social\META_COMPTE_SANS_MDP ] = 1;
	$user_id = \Yume\Core\Social\creer_compte_lecteur(
		array(
			'pseudo'   => pseudo_nouveau_compte( $profil ),
			'email'    => (string) $profil['email'],
			'source'   => 'discord',
			'notifier' => false,
			'meta'     => $metas,
		)
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	/** Cette action est documentée dans includes/discord/fonctions.php (lier()). */
	do_action( 'yume_discord_lie', (int) $user_id, (string) $profil['id'] );
	/**
	 * Un compte lecteur vient d'être créé par la connexion Discord (métas de liaison en place).
	 *
	 * @param int $user_id Compte.
	 */
	do_action( 'yume_discord_compte_cree', (int) $user_id );
	return (int) $user_id;
}

/**
 * Ouvre la session d'un compte, comme Social\traiter_connexion() (cookie « se souvenir de moi »,
 * wp_set_current_user, action wp_login, filtre login_redirect) et renvoie la destination.
 *
 * @param \WP_User $user        Compte.
 * @param string   $redirect_to Page demandée ('' : défaut).
 */
function ouvrir_session( \WP_User $user, string $redirect_to ): string {
	$demandee = '' !== $redirect_to ? wp_validate_redirect( esc_url_raw( $redirect_to ), '' ) : '';
	$identite = array(
		'user_login'    => $user->user_login,
		'user_password' => '',
		'remember'      => true,
	);
	// Comme wp_signon().
	$securise = (bool) apply_filters( 'secure_signon_cookie', is_ssl(), $identite ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
	wp_set_auth_cookie( $user->ID, true, $securise );
	wp_set_current_user( $user->ID );
	do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- action du cœur.
	$defaut      = '' !== $demandee ? $demandee : url_compte();
	$destination = (string) apply_filters( 'login_redirect', $defaut, $demandee, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
	return wp_validate_redirect( $destination, url_compte() );
}

/*
 * -----------------------------------------------------------------------------
 * Route REST de retour
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare GET /yume/v1/discord/retour (toujours déclarée : 404 tant que la connexion n'est pas
 * configurée).
 */
function declarer_route(): void {
	$texte = array(
		'type'              => 'string',
		'default'           => '',
		'sanitize_callback' => static function ( $valeur ): string {
			return is_scalar( $valeur ) ? sanitize_text_field( (string) $valeur ) : '';
		},
	);
	register_rest_route(
		'yume/v1',
		ROUTE_RETOUR,
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\rest_retour',
			// Simple navigation depuis Discord : la sécurité repose sur state + cookie + PKCE.
			'permission_callback' => '__return_true',
			'args'                => array(
				'code'  => $texte,
				'state' => $texte,
				'error' => $texte,
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\declarer_route' );

/**
 * GET /discord/retour : redirection 302 (en-têtes nocache), servie sans corps par servir_retour().
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_retour( \WP_REST_Request $requete ) {
	if ( ! discord_configure() ) {
		return new \WP_Error( 'yume_discord_inactif', __( 'La connexion avec Discord n’est pas activée.', 'yume-core' ), array( 'status' => 404 ) );
	}
	$get = array();
	foreach ( array( 'code', 'state', 'error' ) as $cle ) {
		$get[ $cle ] = is_scalar( $requete->get_param( $cle ) ) ? (string) $requete->get_param( $cle ) : '';
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé par hash_equals() (state) ou validé par wp_validate_auth_cookie().
	$cookie   = isset( $_COOKIE ) && is_array( $_COOKIE ) ? wp_unslash( $_COOKIE ) : array();
	$resultat = traiter_retour( $get, $cookie );
	poser_cookie( '', time() - YEAR_IN_SECONDS );
	$reponse = new \WP_REST_Response( null, 302 );
	$reponse->header( 'Location', $resultat['url'] );
	foreach ( wp_get_nocache_headers() as $nom => $valeur ) {
		if ( ! empty( $valeur ) ) {
			$reponse->header( $nom, (string) $valeur );
		}
	}
	return $reponse;
}

/**
 * Sert la redirection du retour sans corps JSON (en-têtes et statut déjà envoyés par le serveur).
 *
 * @param bool              $servi   Déjà servi.
 * @param \WP_HTTP_Response $reponse Réponse.
 * @param \WP_REST_Request  $requete Requête.
 */
function servir_retour( $servi, $reponse, $requete ): bool {
	if ( $servi || ! $requete instanceof \WP_REST_Request || '/yume/v1' . ROUTE_RETOUR !== $requete->get_route() ) {
		return (bool) $servi;
	}
	return $reponse instanceof \WP_HTTP_Response && 302 === $reponse->get_status();
}
add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\servir_retour', 10, 3 );

/*
 * -----------------------------------------------------------------------------
 * Boutons (page de connexion, Mon compte)
 * -----------------------------------------------------------------------------
 */

/**
 * Feuille de style du bouton Discord.
 */
function charger_style(): void {
	wp_enqueue_style( 'yume-discord', plugins_url( 'assets/discord.css', __FILE__ ), array(), defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : false );
}

/**
 * Icône générique (bulle de discussion), décorative.
 */
function icone(): string {
	return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
}

/**
 * Bouton « Se connecter avec Discord » (mode connexion) ou « Lier mon compte Discord » (mode
 * liaison) : formulaire POST vers admin-post yume_discord_depart ; nonce pour un membre connecté
 * seulement (page visiteur publique, mise en cache). '' si la connexion n'est pas configurée.
 *
 * @param string $mode        connexion ou liaison.
 * @param string $redirect_to Page demandée après la connexion ('' : défaut).
 * @param string $origine     Page du bouton (retour avec un message).
 * @param string $libelle     Libellé ('' : selon le mode).
 */
function bouton_html( string $mode, string $redirect_to, string $origine, string $libelle = '' ): string {
	if ( ! discord_configure() ) {
		return '';
	}
	$mode = in_array( $mode, MODES, true ) ? $mode : 'connexion';
	if ( '' === $libelle ) {
		$libelle = 'liaison' === $mode ? __( 'Lier mon compte Discord', 'yume-core' ) : __( 'Se connecter avec Discord', 'yume-core' );
	}
	charger_style();
	$origine = remove_query_arg( array( PARAM_MESSAGE, 'yn-msg' ), $origine );
	return '<form class="yn-discord__formulaire" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="yume_discord_depart">'
		. '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">'
		. '<input type="hidden" name="yn_origine" value="' . esc_url( $origine ) . '">'
		. ( '' !== $redirect_to ? '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect_to ) . '">' : '' )
		. ( is_user_logged_in() ? '<input type="hidden" name="_yn_nonce" value="' . esc_attr( wp_create_nonce( 'yume_discord_depart' ) ) . '">' : '' )
		. '<button type="submit" class="yn-btn yn-btn--discord">' . icone() . '<span>' . esc_html( $libelle ) . '</span></button>'
		. '</form>';
}

/**
 * Séparateur « ou » entre un formulaire et le bouton Discord.
 */
function separateur(): string {
	return '<p class="yn-discord__ou"><span>' . esc_html__( 'ou', 'yume-core' ) . '</span></p>';
}

/**
 * Page courante sans paramètres de message (retour des boutons).
 */
function page_courante(): string {
	$ici = function_exists( '\Yume\Core\Social\url_courante' ) ? \Yume\Core\Social\url_courante() : home_url( '/' );
	return remove_query_arg( array( PARAM_MESSAGE, 'yn-msg' ), $ici );
}

/**
 * Sous le formulaire de connexion (visiteur) : message de retour de Discord et bouton
 * « Se connecter avec Discord ».
 *
 * @param string $html     HTML des autres modules.
 * @param string $demandee Page demandée après la connexion.
 */
function html_connexion( $html, $demandee = '' ): string {
	$html = (string) $html;
	if ( ! discord_configure() ) {
		return $html;
	}
	$code    = code_courant();
	$message = in_array( $code, array( 'expire', 'refuse', 'non-verifie', 'email-existant', 'inscriptions-fermees', 'reserve', 'limite', 'erreur' ), true )
		? message_html( $code, (string) $demandee )
		: '';
	return $html . '<div class="yn-discord yn-discord--connexion">' . $message . separateur()
		. bouton_html( 'connexion', (string) $demandee, page_courante() ) . '</div>';
}
add_filter( 'yume_compte_html_connexion_extra', __NAMESPACE__ . '\\html_connexion', 10, 2 );

/**
 * Sous le formulaire d'inscription (inscriptions ouvertes et réglage discord_inscription) :
 * bouton « Créer un compte avec Discord ».
 *
 * @param string $html HTML des autres modules.
 * @param string $ici  Page courante.
 */
function html_inscription( $html, $ici = '' ): string {
	$html = (string) $html;
	if ( ! discord_configure() || ! inscription_discord() || ! \Yume\Core\Social\inscriptions_ouvertes() ) {
		return $html;
	}
	$origine = '' !== (string) $ici ? (string) $ici : page_courante();
	return $html . '<div class="yn-discord yn-discord--inscription">' . separateur()
		. bouton_html( 'connexion', '', $origine, __( 'Créer un compte avec Discord', 'yume-core' ) )
		. '<p class="yn-muted yn-account__note">' . esc_html__( 'Votre nom Discord devient votre pseudo public (modifiable dans Mon compte). Seule une adresse e-mail vérifiée par Discord est acceptée.', 'yume-core' ) . '</p>'
		. '</div>';
}
add_filter( 'yume_compte_html_inscription_extra', __NAMESPACE__ . '\\html_inscription', 10, 2 );
