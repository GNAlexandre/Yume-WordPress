<?php
/**
 * Comptes lecteurs côté WordPress : barre d'administration masquée pour les lecteurs,
 * wp-admin redirigé vers la page compte (sauf admin-ajax.php et admin-post.php), redirection
 * après connexion vers la page d'origine ou le compte, liens d'inscription et de mot de passe
 * oublié vers la façade, retour en façade après un échec de connexion et confirmation d'un
 * changement d'adresse e-mail.
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
 * Formulaire de connexion en façade : champ caché signalant l'origine (pour revenir en façade
 * après un échec) et lien vers « Mot de passe oublié ».
 *
 * @param string $contenu Contenu ajouté au milieu du formulaire.
 * @param array  $args    Arguments de wp_login_form().
 */
function champ_origine_connexion( $contenu, $args ): string {
	if ( ! is_array( $args ) || 'yn-connexion' !== ( $args['form_id'] ?? '' ) ) {
		return (string) $contenu;
	}
	$page = url_courante();
	return (string) $contenu
		. '<input type="hidden" name="yn_origine" value="' . esc_url( $page ) . '">';
}
add_filter( 'login_form_middle', __NAMESPACE__ . '\\champ_origine_connexion', 10, 2 );

/**
 * Échec de connexion depuis la façade : retour au formulaire avec un message, sans révéler
 * si l'identifiant existe.
 */
function echec_connexion(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- formulaire de connexion de WordPress (sans nonce).
	$origine = isset( $_POST['yn_origine'] ) ? esc_url_raw( wp_unslash( $_POST['yn_origine'] ) ) : '';
	if ( '' === $origine ) {
		return;
	}
	$origine = wp_validate_redirect( $origine, '' );
	if ( '' === $origine ) {
		return;
	}
	rediriger( $origine, 'connexion-echec', 'yn-connexion' );
}
add_action( 'wp_login_failed', __NAMESPACE__ . '\\echec_connexion', 20 );

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
	$resultat = wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => $email,
		)
	);
	delete_user_meta( $user_id, META_EMAIL_ATTENTE );
	rediriger( url_compte(), is_wp_error( $resultat ) ? 'erreur' : 'email-ok', 'yn-profil' );
}
add_action( 'template_redirect', __NAMESPACE__ . '\\confirmer_email', 5 );

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
