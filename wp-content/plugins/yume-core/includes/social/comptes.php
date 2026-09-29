<?php
/**
 * Comptes lecteurs côté WordPress : barre d'administration masquée pour les lecteurs,
 * wp-admin redirigé vers la page compte (sauf admin-ajax.php et admin-post.php), redirection
 * après connexion vers la page d'origine ou le compte, liens d'inscription et de mot de passe
 * oublié vers la façade, formulaires d'inscription et de mot de passe oublié du cœur
 * (wp-login.php) protégés comme ceux de la façade (SEC-02) et confirmation d'un changement
 * d'adresse e-mail (avis à l'ancienne adresse et autres sessions fermées, SEC-11). La connexion
 * en façade (sans wp-login.php) est dans connexion.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Barre d'administration masquée pour les lecteurs.
 *
 * @param bool $afficher Valeur calculée.
 */
function barre_administration( $afficher ): bool {
	if ( est_lecteur() ) {
		return false;
	}
	return (bool) $afficher;
}
add_filter( 'show_admin_bar', __NAMESPACE__ . '\\barre_administration', 20 );

/**
 * La requête d'administration courante doit-elle rester accessible à un lecteur ?
 */
function administration_autorisee(): bool {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return true;
	}
	$page = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	return in_array( $page, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true );
}

/**
 * Administration (wp-admin) : un lecteur est renvoyé vers sa page compte.
 */
function rediriger_administration(): void {
	if ( ! is_user_logged_in() || ! est_lecteur() || administration_autorisee() ) {
		return;
	}
	wp_safe_redirect( url_compte_sure() );
	exit;
}
add_action( 'admin_init', __NAMESPACE__ . '\\rediriger_administration', 1 );

/**
 * Après la connexion : un lecteur revient à la page d'origine, ou à son compte si aucune
 * n'est demandée (ou si c'était l'administration). Un membre de l'équipe sans accès à la
 * rédaction (traducteur, relecteur, graphiste) arrive sur l'espace équipe au lieu du profil
 * de wp-admin quand aucune page n'est demandée.
 *
 * @param string             $redirection URL calculée par WordPress.
 * @param string             $demandee    URL demandée (redirect_to).
 * @param \WP_User|\WP_Error $user        Utilisateur connecté.
 */
function redirection_connexion( $redirection, $demandee, $user ): string {
	if ( $user instanceof \WP_User && ! est_lecteur( $user ) && ! user_can( $user, 'edit_posts' ) && user_can( $user, 'yume_voir_equipe' ) && page_enregistree( 'equipe' ) ) {
		// Traducteur, relecteur, graphiste : sans destination précise, l'espace équipe plutôt
		// que la page de profil de wp-admin (destination par défaut de WordPress).
		$demandee = (string) $demandee;
		if ( '' === $demandee || in_array( untrailingslashit( $demandee ), array( untrailingslashit( admin_url() ), admin_url( 'profile.php' ) ), true ) ) {
			return yume_url_page( 'equipe' );
		}
		return (string) $redirection;
	}
	if ( ! $user instanceof \WP_User || ! est_lecteur( $user ) ) {
		return (string) $redirection;
	}
	$demandee = (string) $demandee;
	if ( '' === $demandee || str_starts_with( $demandee, admin_url() ) || str_contains( $demandee, '/wp-admin' ) ) {
		return url_compte_sure();
	}
	return wp_validate_redirect( $demandee, url_compte_sure() );
}
add_filter( 'login_redirect', __NAMESPACE__ . '\\redirection_connexion', 20, 3 );

/**
 * Lien « Inscription » de WordPress → formulaire en façade (si la page existe).
 *
 * @param string $url URL par défaut.
 */
function url_inscription( $url ): string {
	if ( page_enregistree( 'connexion' ) || page_enregistree( 'compte' ) ) {
		return url_connexion() . '#yn-inscription';
	}
	return (string) $url;
}
add_filter( 'register_url', __NAMESPACE__ . '\\url_inscription' );

/**
 * Formulaire en façade en cours de traitement (traiter_inscription(), traiter_oubli()) ? Ses
 * protections sont alors déjà appliquées : erreurs_inscription() et limiter_oubli() ne
 * comptent pas la tentative une seconde fois.
 *
 * @param bool|null $actif Nouvel état (null : lecture seule).
 */
function traitement_facade( ?bool $actif = null ): bool {
	static $en_cours = false;
	if ( null !== $actif ) {
		$en_cours = $actif;
	}
	return $en_cours;
}

/**
 * Inscription par wp-login.php?action=register (SEC-02) : renvoi vers le formulaire
 * d'inscription en façade (pot de miel, jeton horodaté, limite par adresse IP), en GET comme
 * en POST.
 */
function inscription_wp_login(): void {
	if ( ! page_enregistree( 'connexion' ) && ! page_enregistree( 'compte' ) ) {
		return;
	}
	wp_safe_redirect( url_connexion() . '#yn-inscription' );
	exit;
}
add_action( 'login_form_register', __NAMESPACE__ . '\\inscription_wp_login' );

/**
 * Toute autre inscription par register_new_user() (wp-login.php sans page en façade, autre
 * extension) : mêmes protections que le formulaire en façade (5 inscriptions par heure et par
 * adresse IP, pot de miel et jeton horodaté quand le formulaire les envoie).
 *
 * @param \WP_Error $erreurs Erreurs déjà relevées.
 * @return \WP_Error
 */
function erreurs_inscription( $erreurs ) {
	if ( ! $erreurs instanceof \WP_Error || traitement_facade() ) {
		return $erreurs;
	}
	if ( limite_atteinte( 'inscription', 5, HOUR_IN_SECONDS ) ) {
		$erreurs->add( 'yume_trop_de_tentatives', __( '<strong>Erreur :</strong> trop de tentatives d’inscription. Réessayez dans une heure.', 'yume-core' ) );
		return $erreurs;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- formulaire d'inscription du cœur (sans nonce).
	$pot   = isset( $_POST['yn_site_web'] ) && '' !== champ_post( 'yn_site_web' );
	$jeton = isset( $_POST['yn_jeton'] ) && 'ok' !== verifier_jeton( champ_post( 'yn_jeton' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( $pot || $jeton ) {
		$erreurs->add( 'yume_inscription_refusee', __( '<strong>Erreur :</strong> inscription refusée. Réessayez depuis le formulaire du site.', 'yume-core' ) );
	}
	return $erreurs;
}
add_filter( 'registration_errors', __NAMESPACE__ . '\\erreurs_inscription', 20 );

/**
 * Demande de réinitialisation (retrieve_password(), SEC-02) : 5 demandes par heure et par
 * adresse IP (sauf formulaire en façade, qui l'a déjà comptée) et 5 par heure et par compte
 * (ou par identifiant saisi s'il n'existe pas, pour ne pas révéler les comptes). Au-delà,
 * aucun e-mail n'est envoyé.
 *
 * @param \WP_Error      $erreurs Erreurs.
 * @param \WP_User|false $user    Compte trouvé.
 */
function limiter_oubli( $erreurs, $user = false ): void {
	if ( ! $erreurs instanceof \WP_Error ) {
		return;
	}
	$refus = ! traitement_facade() && limite_atteinte( 'oubli', 5, HOUR_IN_SECONDS );
	if ( ! $refus ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- formulaire du cœur (sans nonce).
		$saisie = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) ) : '';
		$cle    = $user instanceof \WP_User ? 'u' . $user->ID : ( '' !== $saisie ? 'l' . $saisie : '' );
		$refus  = '' !== $cle && limite_atteinte( 'oubli', 5, HOUR_IN_SECONDS, $cle );
	}
	if ( $refus ) {
		$erreurs->add( 'yume_trop_de_tentatives', __( '<strong>Erreur :</strong> trop de demandes. Réessayez dans une heure.', 'yume-core' ) );
	}
}
add_action( 'lostpassword_post', __NAMESPACE__ . '\\limiter_oubli', 10, 2 );

/**
 * Formulaire wp-login.php?action=lostpassword envoyé (POST) : même réponse que le compte existe ou non,
 * ou que la limite soit atteinte (« vérifiez vos e-mails »). Le cœur afficherait sinon « aucun
 * compte avec cet identifiant ». Un champ vide reste traité par le cœur.
 */
function oubli_wp_login(): void {
	$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	// phpcs:disable WordPress.Security.NonceVerification -- formulaire du cœur (sans nonce).
	$saisie = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) : '';
	if ( 'POST' !== $methode || '' === $saisie ) {
		return;
	}
	retrieve_password( $saisie );
	$retour = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification
	wp_safe_redirect( '' !== $retour ? $retour : add_query_arg( 'checkemail', 'confirm', wp_login_url() ) );
	exit;
}
add_action( 'login_form_lostpassword', __NAMESPACE__ . '\\oubli_wp_login' );
add_action( 'login_form_retrievepassword', __NAMESPACE__ . '\\oubli_wp_login' );

/**
 * Lien « Mot de passe oublié » → formulaire en façade (si la page existe).
 *
 * @param string $url    URL par défaut.
 * @param string $retour Redirection demandée.
 */
function url_oubli( $url, $retour = '' ): string {
	if ( is_admin() || ( ! page_enregistree( 'connexion' ) && ! page_enregistree( 'compte' ) ) ) {
		return (string) $url;
	}
	return url_connexion( (string) $retour ) . '#yn-oubli';
}
add_filter( 'lostpassword_url', __NAMESPACE__ . '\\url_oubli', 10, 2 );

/**
 * Confirmation d'un changement d'adresse e-mail (lien ?yn-email=clé envoyé à la nouvelle
 * adresse). Le membre doit être connecté ; le lien expire après 24 h.
 */
function confirmer_email(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jeton secret propre au lien.
	if ( ! isset( $_GET['yn-email'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cle = sanitize_text_field( wp_unslash( $_GET['yn-email'] ) );
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( url_connexion( add_query_arg( 'yn-email', rawurlencode( $cle ), url_compte() ) ) );
		exit;
	}
	$user_id = get_current_user_id();
	$attente = get_user_meta( $user_id, META_EMAIL_ATTENTE, true );
	$valide  = is_array( $attente ) && ! empty( $attente['cle'] ) && ! empty( $attente['email'] )
		&& hash_equals( (string) $attente['cle'], hash( 'sha256', $cle ) )
		&& (int) ( $attente['expire'] ?? 0 ) >= time();
	if ( ! $valide ) {
		rediriger( url_compte(), 'email-lien-invalide', 'yn-profil' );
	}
	$email = sanitize_email( (string) $attente['email'] );
	$autre = email_exists( $email );
	if ( ! is_email( $email ) || ( $autre && (int) $autre !== $user_id ) ) {
		delete_user_meta( $user_id, META_EMAIL_ATTENTE );
		rediriger( url_compte(), 'email-pris', 'yn-profil' );
	}
	$ancienne = (string) get_userdata( $user_id )->user_email;
	// L'avis du cœur (en anglais, sans conseil) est remplacé par avis_changement_email().
	$sans_avis_coeur = static function () {
		return false;
	};
	add_filter( 'send_email_change_email', $sans_avis_coeur, 99 );
	$resultat = wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => $email,
		)
	);
	remove_filter( 'send_email_change_email', $sans_avis_coeur, 99 );
	delete_user_meta( $user_id, META_EMAIL_ATTENTE );
	if ( ! is_wp_error( $resultat ) ) {
		securiser_changement_email( $user_id, $ancienne, $email );
	}
	rediriger( url_compte(), is_wp_error( $resultat ) ? 'erreur' : 'email-ok', 'yn-profil' );
}
add_action( 'template_redirect', __NAMESPACE__ . '\\confirmer_email', 5 );

/**
 * Après un changement d'adresse confirmé (SEC-11) : avis à l'ancienne adresse et fermeture
 * des autres sessions du compte (un voleur de session ne garde pas l'accès). La session
 * courante est conservée ; sans session identifiable, toutes sont fermées.
 *
 * @param int    $user_id  Compte.
 * @param string $ancienne Ancienne adresse.
 * @param string $nouvelle Nouvelle adresse.
 */
function securiser_changement_email( int $user_id, string $ancienne, string $nouvelle ): void {
	$jeton = wp_get_session_token();
	if ( get_current_user_id() === $user_id && '' !== $jeton ) {
		wp_destroy_other_sessions();
	} else {
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}
	if ( is_email( $ancienne ) && strtolower( $ancienne ) !== strtolower( $nouvelle ) ) {
		avis_changement_email( $user_id, $ancienne, $nouvelle );
	}
}

/**
 * Prévient l'ancienne adresse d'un compte que l'adresse vient d'être changée.
 *
 * @param int    $user_id  Compte.
 * @param string $ancienne Ancienne adresse (destinataire).
 * @param string $nouvelle Nouvelle adresse.
 */
function avis_changement_email( int $user_id, string $ancienne, string $nouvelle ): bool {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}
	$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$sujet = sprintf( /* translators: %s : nom du site. */ __( '[%s] L’adresse e-mail de votre compte a été changée', 'yume-core' ), $site );
	$texte = sprintf(
		/* translators: 1 : pseudo, 2 : nom du site, 3 : nouvelle adresse, 4 : adresse de contact du site. */
		__(
			'Bonjour %1$s,

L’adresse e-mail de votre compte %2$s vient d’être remplacée par : %3$s
Les autres sessions ouvertes sur ce compte ont été fermées.

Si c’est bien vous, vous n’avez rien à faire.

Si vous n’êtes pas à l’origine de ce changement, quelqu’un connaît sans doute votre mot de passe : écrivez-nous au plus vite à %4$s depuis cette adresse pour récupérer votre compte.',
			'yume-core'
		),
		$user->display_name,
		$site,
		$nouvelle,
		(string) get_option( 'admin_email' )
	);
	return (bool) wp_mail( $ancienne, $sujet, $texte );
}

/*
 * -----------------------------------------------------------------------------
 * Profil d'un lecteur : uniquement par la page compte
 * -----------------------------------------------------------------------------
 */

/**
 * La page compte impose le mot de passe actuel (e-mail, mot de passe), un lien de
 * confirmation pour la nouvelle adresse et un pseudo unique (pseudo_pris). La route du cœur
 * POST/PUT/PATCH /wp/v2/users/{id|me} (et son équivalent par /batch/v1) n'applique aucune de
 * ces règles : elle est donc fermée aux lecteurs, qui modifient leur profil depuis la façade.
 * L'équipe garde l'accès standard (droits de WordPress).
 *
 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $reponse Réponse déjà calculée.
 * @param array                                               $handler Gestionnaire de la route.
 * @param \WP_REST_Request                                    $requete Requête.
 * @return mixed
 */
function bloquer_profil_rest( $reponse, $handler, $requete ) {
	if ( is_wp_error( $reponse ) || ! $requete instanceof \WP_REST_Request || ! is_user_logged_in() || ! est_lecteur() ) {
		return $reponse;
	}
	$rappel = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
	if ( ! is_array( $rappel ) || ! isset( $rappel[0], $rappel[1] ) ) {
		return $reponse;
	}
	$controleur = $rappel[0];
	$methode    = (string) $rappel[1];
	$ecritures  = array( 'create_item', 'update_item', 'update_current_item', 'delete_item', 'delete_current_item' );
	$vise       = ( $controleur instanceof \WP_REST_Users_Controller && in_array( $methode, $ecritures, true ) )
		|| ( $controleur instanceof \WP_REST_Application_Passwords_Controller && in_array( $methode, array( 'create_item', 'update_item' ), true ) );
	if ( ! $vise ) {
		return $reponse;
	}
	return new \WP_Error(
		'yume_profil_facade',
		sprintf(
			/* translators: %s : adresse de la page compte. */
			__( 'Modifiez votre profil (pseudo, adresse e-mail, mot de passe) depuis votre page compte : %s', 'yume-core' ),
			url_compte_sure()
		),
		array( 'status' => 403 )
	);
}
add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\\bloquer_profil_rest', 5, 3 );

/**
 * Pas de mots de passe d'application pour les lecteurs (ils contourneraient la page compte
 * et survivraient à un changement de mot de passe).
 *
 * @param bool     $disponible Valeur calculée.
 * @param \WP_User $user       Utilisateur.
 */
function mots_de_passe_application( $disponible, $user = null ): bool {
	if ( $user instanceof \WP_User && est_lecteur( $user ) ) {
		return false;
	}
	return (bool) $disponible;
}
add_filter( 'wp_is_application_passwords_available_for_user', __NAMESPACE__ . '\\mots_de_passe_application', 10, 2 );
