<?php
/**
 * Connexion en façade sans wp-login.php (page « connexion » ou « compte », bloc yume/account) :
 *
 * - formulaire propre posté vers admin-post.php (action yume_connexion, nonce) : identifiant ou
 *   e-mail, mot de passe, « Se souvenir de moi », redirect_to validé (même site) ;
 * - authentification par wp_authenticate() (chaîne authenticate complète : Jetpack Protect et les
 *   autres protections s'appliquent), session ouverte seulement ensuite (wp_set_auth_cookie(),
 *   action wp_login), comme wp_signon() ;
 * - administrateurs (manage_options) refusés même avec le bon mot de passe : ils se connectent par
 *   la page de connexion WordPress (WordPress.com / Jetpack, validation en deux étapes) ;
 * - limitation propre au formulaire (Jetpack Protect ne voit plus wp-login.php) : échecs comptés
 *   par adresse IP (hachée) et par compte, blocage temporaire, remise à zéro au succès, action
 *   wp_login_failed à chaque échec ;
 * - échec : retour sur la page de connexion avec un message générique et l'identifiant
 *   pré-rempli, jamais vers wp-login.php ;
 * - liens de connexion du site (wp_login_url() hors administration et hors wp-login.php) vers la
 *   page de connexion en façade ; seul le lien « Connexion administrateur » mène à wp-login.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Paramètres d'adresse propres au retour du formulaire de connexion. */
const PARAMS_RETOUR_CONNEXION = array( 'yn-identifiant', 'yn-minutes' );

/*
 * -----------------------------------------------------------------------------
 * Liens de connexion
 * -----------------------------------------------------------------------------
 */

/**
 * Lien « Connexion administrateur » : la page de connexion WordPress (wp_login_url(), donc
 * Jetpack SSO / WordPress.com sur Atomic), sans le détour par la façade.
 *
 * @param string $retour Page où revenir après la connexion.
 */
function url_connexion_administrateur( string $retour = '' ): string {
	lien_wordpress( true );
	try {
		return wp_login_url( $retour );
	} finally {
		lien_wordpress( false );
	}
}

/**
 * Construction du lien vers la page de connexion WordPress en cours ? (filtre_url_connexion()
 * laisse alors wp_login_url() intact).
 *
 * @param bool|null $actif Nouvel état (null : lecture seule).
 */
function lien_wordpress( ?bool $actif = null ): bool {
	static $en_cours = false;
	if ( null !== $actif ) {
		$en_cours = $actif;
	}
	return $en_cours;
}

/**
 * Liens wp_login_url() → page de connexion en façade, pour les liens « Se connecter » de la façade et
 * les renvois des formulaires admin-post.php ou admin-ajax.php. Inchangé dans l'administration
 * (auth_redirect() : administrateurs), sur wp-login.php (sauf après la réinitialisation d'un mot
 * de passe) et sans page « connexion » ni « compte ».
 *
 * @param string $url    URL calculée par WordPress.
 * @param string $retour Page où revenir (redirect_to).
 */
function filtre_url_connexion( $url, $retour = '' ): string {
	if ( lien_wordpress() || ( ! page_enregistree( 'connexion' ) && ! page_enregistree( 'compte' ) ) ) {
		return (string) $url;
	}
	$page = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	if ( 'wp-login.php' === $page ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de l'étape affichée.
		$etape = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $etape, array( 'rp', 'resetpass' ), true ) ) {
			return (string) $url;
		}
	} elseif ( is_admin() && ! in_array( $page, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
		return (string) $url;
	}
	return url_connexion( (string) $retour );
}
add_filter( 'login_url', __NAMESPACE__ . '\\filtre_url_connexion', 99, 2 );

/*
 * -----------------------------------------------------------------------------
 * Comptes réservés à la page de connexion WordPress
 * -----------------------------------------------------------------------------
 */

/**
 * Ce compte doit-il se connecter par la page de connexion WordPress (connexion WordPress.com /
 * Jetpack et sa validation en deux étapes, docs/mise-en-production.md §1) ? Les administrateurs
 * (manage_options, super-administrateur en multisite) : décision du propriétaire, le reste de
 * l'équipe (gérants compris) et les lecteurs passent par la façade.
 *
 * @param \WP_User $user Compte authentifié.
 */
function connexion_reservee_wordpress( \WP_User $user ): bool {
	$reservee = user_can( $user, 'manage_options' ) || ( is_multisite() && is_super_admin( $user->ID ) );
	/**
	 * Filtre les comptes refusés par le formulaire de connexion en façade (renvoyés vers la
	 * page de connexion WordPress).
	 *
	 * @param bool     $reservee Défaut : administrateurs (manage_options).
	 * @param \WP_User $user     Compte authentifié (mot de passe correct).
	 */
	return (bool) apply_filters( 'yume_connexion_reservee_wordpress', $reservee, $user );
}

/*
 * -----------------------------------------------------------------------------
 * Limitation des échecs
 * -----------------------------------------------------------------------------
 */

/**
 * Seuils : échecs tolérés, période de comptage et durée du blocage (secondes).
 *
 * @return array{tentatives:int,periode:int,blocage:int}
 */
function limites_connexion(): array {
	/**
	 * Filtre les seuils du formulaire de connexion en façade (par adresse IP et par compte).
	 *
	 * @param array $limites tentatives (5), periode (900 s), blocage (900 s).
	 */
	$limites = (array) apply_filters(
		'yume_connexion_limites',
		array(
			'tentatives' => 5,
			'periode'    => 15 * MINUTE_IN_SECONDS,
			'blocage'    => 15 * MINUTE_IN_SECONDS,
		)
	);
	return array(
		'tentatives' => max( 1, (int) ( $limites['tentatives'] ?? 5 ) ),
		'periode'    => max( 60, (int) ( $limites['periode'] ?? 900 ) ),
		'blocage'    => max( 60, (int) ( $limites['blocage'] ?? 900 ) ),
	);
}

/**
 * Clés des compteurs (transients) : adresse IP et compte visé (compte existant par son ID,
 * sinon la saisie en minuscules, pour ne pas révéler quels comptes existent), hachés.
 *
 * @param string $identifiant Identifiant ou e-mail saisi.
 * @return string[]
 */
function cles_limite_connexion( string $identifiant ): array {
	$saisie = strtolower( trim( $identifiant ) );
	$user   = '' !== $saisie ? get_user_by( is_email( $saisie ) ? 'email' : 'login', $saisie ) : false;
	$compte = $user instanceof \WP_User ? 'u' . $user->ID : 'l' . $saisie;
	$cles   = array( 'ip|' . adresse_ip() );
	if ( '' !== $saisie ) {
		$cles[] = 'id|' . $compte;
	}
	return array_map(
		static fn( string $cle ): string => 'yume_cnx_' . substr( hash_hmac( 'sha256', $cle, wp_salt( 'nonce' ) ), 0, 32 ),
		$cles
	);
}

/**
 * Secondes de blocage restantes pour cette adresse IP ou ce compte (0 : non bloqué).
 *
 * @param string $identifiant Identifiant saisi.
 */
function attente_connexion( string $identifiant ): int {
	$attente = 0;
	foreach ( cles_limite_connexion( $identifiant ) as $cle ) {
		$etat = get_transient( $cle );
		if ( is_array( $etat ) && (int) ( $etat['bloque'] ?? 0 ) > time() ) {
			$attente = max( $attente, (int) $etat['bloque'] - time() );
		}
	}
	return $attente;
}

/**
 * Compte un échec (adresse IP et compte) ; renvoie les secondes de blocage s'il commence.
 *
 * @param string $identifiant Identifiant saisi.
 */
function noter_echec_connexion( string $identifiant ): int {
	$limites = limites_connexion();
	$attente = 0;
	foreach ( cles_limite_connexion( $identifiant ) as $cle ) {
		$etat = get_transient( $cle );
		if ( ! is_array( $etat ) || time() - (int) ( $etat['debut'] ?? 0 ) > $limites['periode'] ) {
			$etat = array(
				'n'      => 0,
				'debut'  => time(),
				'bloque' => 0,
			);
		}
		$etat['n'] = (int) $etat['n'] + 1;
		if ( $etat['n'] >= $limites['tentatives'] ) {
			$etat['bloque'] = time() + $limites['blocage'];
			$attente        = max( $attente, $limites['blocage'] );
		}
		$duree = $etat['bloque'] > 0 ? $limites['blocage'] : (int) $etat['debut'] + $limites['periode'] - time();
		set_transient( $cle, $etat, max( 1, $duree ) );
	}
	return $attente;
}

/**
 * Connexion réussie : compteurs de l'adresse IP et du compte remis à zéro.
 *
 * @param string $identifiant Identifiant saisi.
 */
function effacer_limite_connexion( string $identifiant ): void {
	foreach ( cles_limite_connexion( $identifiant ) as $cle ) {
		delete_transient( $cle );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Traitement (admin-post.php, action yume_connexion)
 * -----------------------------------------------------------------------------
 */

/**
 * Page où revenir après un échec : page du formulaire (champ yn_origine, locale, jamais
 * wp-login.php ni l'administration), sinon la page de connexion en façade.
 */
function page_echec_connexion(): string {
	$defaut  = page_enregistree( 'connexion' ) || page_enregistree( 'compte' ) ? url_connexion() : home_url( '/' );
	$origine = champ_post( 'yn_origine' );
	$origine = '' !== $origine ? wp_validate_redirect( esc_url_raw( $origine ), '' ) : '';
	if ( '' === $origine || str_contains( $origine, 'wp-login.php' ) || str_starts_with( $origine, admin_url() ) ) {
		return $defaut;
	}
	return remove_query_arg( PARAMS_RETOUR_CONNEXION, $origine );
}

/**
 * Retour sur la page de connexion avec un message (et l'identifiant saisi, jamais le mot de
 * passe), puis arrêt.
 *
 * @param string $code        Code du message (connexion-echec, connexion-bloquee, connexion-reservee).
 * @param string $identifiant Identifiant saisi.
 * @param int    $attente     Secondes de blocage (connexion-bloquee).
 */
function retour_echec_connexion( string $code, string $identifiant, int $attente = 0 ): void {
	$args = array();
	if ( '' !== $identifiant ) {
		$args['yn-identifiant'] = rawurlencode( mb_substr( $identifiant, 0, 100 ) );
	}
	if ( $attente > 0 ) {
		$args['yn-minutes'] = (int) ceil( $attente / MINUTE_IN_SECONDS );
	}
	rediriger( add_query_arg( $args, page_echec_connexion() ), $code, 'yn-connexion-message' );
}

/**
 * Formulaire de connexion en façade (admin-post.php, action yume_connexion).
 */
function traiter_connexion(): void {
	$demandee = champ_post( 'redirect_to' );
	$demandee = '' !== $demandee ? wp_validate_redirect( esc_url_raw( $demandee ), '' ) : '';
	if ( is_user_logged_in() ) {
		wp_safe_redirect( '' !== $demandee ? $demandee : url_compte_sure() );
		exit;
	}
	exiger_nonce( 'yume_connexion', page_echec_connexion(), 'yn-bloc-connexion' );

	$identifiant  = champ_post( 'yn_identifiant' );
	$mot_de_passe = mot_de_passe_post( 'yn_mot_de_passe' );
	$attente      = attente_connexion( $identifiant );
	if ( $attente > 0 ) {
		// Action du cœur : les protections qui l'écoutent (Jetpack Protect…) comptent aussi ces tentatives.
		do_action( 'wp_login_failed', $identifiant, new \WP_Error( 'yume_trop_de_tentatives', __( 'Trop de tentatives.', 'yume-core' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- action du cœur.
		retour_echec_connexion( 'connexion-bloquee', $identifiant, $attente );
	}
	if ( '' === $identifiant || '' === $mot_de_passe ) {
		retour_echec_connexion( 'connexion-echec', $identifiant );
	}

	// Chaîne authenticate complète (Jetpack Protect, etc.) ; wp_authenticate() déclenche
	// wp_login_failed en cas d'échec. La session n'est ouverte qu'après les vérifications.
	$user = wp_authenticate( $identifiant, $mot_de_passe );
	if ( ! $user instanceof \WP_User ) {
		$attente = noter_echec_connexion( $identifiant );
		retour_echec_connexion( $attente > 0 ? 'connexion-bloquee' : 'connexion-echec', $identifiant, $attente );
	}
	if ( connexion_reservee_wordpress( $user ) ) {
		retour_echec_connexion( 'connexion-reservee', $identifiant );
	}

	effacer_limite_connexion( $identifiant );
	$souvenir = '' !== champ_post( 'yn_se_souvenir' );
	$identite = array(
		'user_login'    => $identifiant,
		'user_password' => '',
		'remember'      => $souvenir,
	);
	// Comme wp_signon().
	$securise = (bool) apply_filters( 'secure_signon_cookie', is_ssl(), $identite ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
	wp_set_auth_cookie( $user->ID, $souvenir, $securise );
	wp_set_current_user( $user->ID );
	do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- action du cœur.

	// Filtre de wp-login.php : redirection_connexion() (comptes.php) s'y accroche.
	$defaut      = '' !== $demandee ? $demandee : url_compte_sure();
	$destination = (string) apply_filters( 'login_redirect', $defaut, $demandee, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
	wp_safe_redirect( wp_validate_redirect( $destination, url_compte_sure() ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_connexion', __NAMESPACE__ . '\\traiter_connexion' );
add_action( 'admin_post_yume_connexion', __NAMESPACE__ . '\\traiter_connexion' );

/*
 * -----------------------------------------------------------------------------
 * Rendu (bloc yume/account, visiteur)
 * -----------------------------------------------------------------------------
 */

/**
 * Message du formulaire de connexion demandé par l'adresse (yn-msg) : HTML (role="alert",
 * focalisé par le script du bloc) ou chaîne vide.
 *
 * @param string $retour Page demandée après la connexion (lien administrateur).
 */
function message_connexion( string $retour ): string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- simple affichage d'un code connu.
	$codes   = isset( $_GET['yn-msg'] ) && is_string( $_GET['yn-msg'] ) ? array_map( 'sanitize_key', explode( ',', sanitize_text_field( wp_unslash( $_GET['yn-msg'] ) ) ) ) : array();
	$minutes = isset( $_GET['yn-minutes'] ) && is_string( $_GET['yn-minutes'] ) ? absint( $_GET['yn-minutes'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( in_array( 'connexion-reservee', $codes, true ) ) {
		$texte = esc_html__( 'Les administrateurs se connectent par la page de connexion WordPress.', 'yume-core' )
			. ' <a href="' . esc_url( url_connexion_administrateur( $retour ) ) . '">' . esc_html__( 'Se connecter par la page WordPress', 'yume-core' ) . '</a>';
	} elseif ( in_array( 'connexion-bloquee', $codes, true ) ) {
		$minutes = max( 1, min( 60, $minutes ) );
		/* translators: %d : nombre de minutes. */
		$texte = esc_html( sprintf( _n( 'Trop de tentatives, réessayez dans %d minute.', 'Trop de tentatives, réessayez dans %d minutes.', $minutes, 'yume-core' ), $minutes ) );
	} elseif ( in_array( 'connexion-echec', $codes, true ) ) {
		$texte = esc_html__( 'Identifiant ou mot de passe incorrect.', 'yume-core' );
	} else {
		return '';
	}
	return '<p class="yn-avis yn-avis--erreur yn-account__connexion-message" id="yn-connexion-message" role="alert" tabindex="-1" data-yn-focus>' . $texte . '</p>';
}

/**
 * Formulaire de connexion en façade.
 *
 * @param string $demandee Page demandée après la connexion (validée, '' : défaut).
 */
function formulaire_connexion( string $demandee ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pré-remplissage après un échec.
	$saisie  = isset( $_GET['yn-identifiant'] ) && is_string( $_GET['yn-identifiant'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['yn-identifiant'] ) ), 0, 100 ) : '';
	$message = message_connexion( $demandee );
	$html    = $message
		. '<form id="yn-connexion" class="yn-account__formulaire yn-account__connexion" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="yume_connexion">'
		. '<input type="hidden" name="yn_origine" value="' . esc_url( remove_query_arg( PARAMS_RETOUR_CONNEXION, url_courante() ) ) . '">'
		. ( '' !== $demandee ? '<input type="hidden" name="redirect_to" value="' . esc_url( $demandee ) . '">' : '' )
		. champ_nonce( 'yume_connexion' )
		. '<p class="yn-account__champ"><label for="yn-identifiant">' . esc_html__( 'Pseudo ou adresse e-mail', 'yume-core' ) . '</label>'
		. '<input type="text" id="yn-identifiant" name="yn_identifiant" value="' . esc_attr( $saisie ) . '" required autocomplete="username" autocapitalize="none" spellcheck="false"'
		. ( '' !== $message ? ' aria-describedby="yn-connexion-message"' : '' ) . '></p>'
		. '<p class="yn-account__champ"><label for="yn-mot-de-passe">' . esc_html__( 'Mot de passe', 'yume-core' ) . '</label>'
		. '<input type="password" id="yn-mot-de-passe" name="yn_mot_de_passe" required autocomplete="current-password"></p>'
		. '<p class="yn-account__case"><input type="checkbox" id="yn-se-souvenir" name="yn_se_souvenir" value="1">'
		. '<label for="yn-se-souvenir">' . esc_html__( 'Se souvenir de moi', 'yume-core' ) . '</label></p>'
		. '<div class="yn-account__boutons"><button type="submit" id="yn-se-connecter" class="yn-btn yn-btn--primary">' . esc_html__( 'Se connecter', 'yume-core' ) . '</button></div>'
		. '</form>';
	return $html;
}

/**
 * Petit lien « Connexion administrateur » sous le formulaire.
 *
 * @param string $demandee Page demandée après la connexion.
 */
function lien_connexion_administrateur( string $demandee ): string {
	return '<p class="yn-muted yn-account__note yn-account__admin"><a href="' . esc_url( url_connexion_administrateur( $demandee ) ) . '">' . esc_html__( 'Connexion administrateur', 'yume-core' ) . '</a></p>';
}
