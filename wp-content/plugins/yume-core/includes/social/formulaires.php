<?php
/**
 * Formulaires en façade traités par admin-post.php (nonce obligatoire), utilisés sans
 * JavaScript et par la page compte :
 *
 * - visiteurs : inscription (pot de miel, délai minimal, limite par adresse IP, e-mail
 *   standard de WordPress pour choisir son mot de passe, rôle subscriber) et mot de passe oublié ;
 * - membres : profil et sécurité (pseudo, e-mail confirmé par lien, mot de passe), réglages
 *   de lecture, préférences d'alerte, suppression du compte, et repli sans JavaScript des
 *   actions de la fiche œuvre (favori, note, alerte).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Délai minimal (secondes) entre l'affichage et l'envoi du formulaire d'inscription. */
const DELAI_MIN_INSCRIPTION = 3;

/**
 * Jeton horodaté et signé du formulaire d'inscription (anti-robots).
 */
function jeton_formulaire(): string {
	$t = (string) time();
	return $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * Vérifie le jeton horodaté : 'ok', 'trop-tot', 'expire' ou 'invalide'.
 *
 * @param string $jeton Jeton reçu.
 */
function verifier_jeton( string $jeton ): string {
	$parties = explode( '.', $jeton );
	if ( 2 !== count( $parties ) || ! ctype_digit( $parties[0] ) ) {
		return 'invalide';
	}
	if ( ! hash_equals( substr( hash_hmac( 'sha256', $parties[0], wp_salt( 'nonce' ) ), 0, 20 ), $parties[1] ) ) {
		return 'invalide';
	}
	$age = time() - (int) $parties[0];
	if ( $age < DELAI_MIN_INSCRIPTION ) {
		return 'trop-tot';
	}
	return $age > DAY_IN_SECONDS ? 'expire' : 'ok';
}

/**
 * Valeur texte d'un champ POST (désinfectée).
 *
 * @param string $nom Nom du champ.
 */
function champ_post( string $nom ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par l'appelant.
	return isset( $_POST[ $nom ] ) && is_scalar( $_POST[ $nom ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $nom ] ) ) : '';
}

/**
 * Mot de passe reçu (non désinfecté : il n'est jamais affiché ni stocké en clair).
 *
 * @param string $nom Nom du champ.
 */
function mot_de_passe_post( string $nom ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- mot de passe brut, nonce vérifié par l'appelant.
	return isset( $_POST[ $nom ] ) && is_string( $_POST[ $nom ] ) ? (string) wp_unslash( $_POST[ $nom ] ) : '';
}

/**
 * Page où revenir après un formulaire : champ yn_retour (local), sinon référent, sinon $defaut.
 *
 * @param string $defaut URL par défaut.
 */
function page_retour( string $defaut ): string {
	$retour = champ_post( 'yn_retour' );
	if ( '' === $retour ) {
		$retour = (string) wp_get_referer();
	}
	$retour = '' !== $retour ? wp_validate_redirect( esc_url_raw( $retour ), '' ) : '';
	return '' !== $retour ? $retour : $defaut;
}

/**
 * Nonce valide pour cette action ? Sinon retour avec le message « session ».
 *
 * @param string $action Action du nonce.
 * @param string $retour Page de retour.
 * @param string $ancre  Ancre.
 */
function exiger_nonce( string $action, string $retour, string $ancre = '' ): void {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- vérifié par wp_verify_nonce.
	$nonce = isset( $_POST['_yn_nonce'] ) ? wp_unslash( $_POST['_yn_nonce'] ) : '';
	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, $action ) ) {
		rediriger( $retour, 'session', $ancre );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Visiteurs : inscription et mot de passe oublié
 * -----------------------------------------------------------------------------
 */

/**
 * Inscription en façade.
 */
function traiter_inscription(): void {
	$retour = page_retour( url_connexion() );
	if ( is_user_logged_in() ) {
		rediriger( url_compte() );
	}
	exiger_nonce( 'yume_inscription', $retour, 'yn-inscription' );
	if ( ! inscriptions_ouvertes() ) {
		rediriger( $retour, 'inscriptions-fermees', 'yn-inscription' );
	}
	if ( limite_atteinte( 'inscription', 5, HOUR_IN_SECONDS ) ) {
		rediriger( $retour, 'trop-de-tentatives', 'yn-inscription' );
	}
	// Pot de miel : un humain ne voit pas ce champ.
	if ( '' !== champ_post( 'yn_site_web' ) ) {
		rediriger( $retour, 'inscription-refusee', 'yn-inscription' );
	}
	$jeton = verifier_jeton( champ_post( 'yn_jeton' ) );
	if ( 'expire' === $jeton ) {
		rediriger( $retour, 'formulaire-expire', 'yn-inscription' );
	}
	if ( 'ok' !== $jeton ) {
		rediriger( $retour, 'inscription-refusee', 'yn-inscription' );
	}

	$pseudo = sanitize_user( champ_post( 'yn_pseudo' ), true );
	$email  = sanitize_email( champ_post( 'yn_email' ) );
	$taille = mb_strlen( $pseudo );
	if ( $taille < 3 || $taille > 40 || champ_post( 'yn_pseudo' ) !== $pseudo ) {
		rediriger( $retour, 'pseudo-invalide', 'yn-inscription' );
	}
	if ( ! is_email( $email ) ) {
		rediriger( $retour, 'email-invalide', 'yn-inscription' );
	}

	// Pas d'e-mail à l'administrateur pour chaque lecteur inscrit (filtre pour le rétablir).
	$notifier_admin = static function () {
		/**
		 * Faut-il prévenir l'administrateur de chaque inscription en façade ?
		 *
		 * @param bool $notifier Défaut : non.
		 */
		return (bool) apply_filters( 'yume_notifier_admin_inscription', false );
	};
	add_filter( 'wp_send_new_user_notification_to_admin', $notifier_admin );
	traitement_facade( true );
	$user_id = register_new_user( $pseudo, $email );
	traitement_facade( false );
	remove_filter( 'wp_send_new_user_notification_to_admin', $notifier_admin );

	if ( is_wp_error( $user_id ) ) {
		$codes = $user_id->get_error_codes();
		if ( in_array( 'username_exists', $codes, true ) ) {
			rediriger( $retour, 'pseudo-pris', 'yn-inscription' );
		}
		if ( array_intersect( array( 'invalid_email', 'empty_email' ), $codes ) ) {
			rediriger( $retour, 'email-invalide', 'yn-inscription' );
		}
		if ( array_intersect( array( 'invalid_username', 'empty_username', 'username_too_long', 'illegal_user_login' ), $codes ) ) {
			rediriger( $retour, 'pseudo-invalide', 'yn-inscription' );
		}
		if ( array( 'email_exists' ) === array_values( array_unique( $codes ) ) ) {
			// Même réponse qu'une inscription réussie : la page ne révèle pas quelles adresses
			// sont inscrites. Le titulaire de l'adresse est prévenu (lien « mot de passe oublié »).
			$titulaire = email_exists( $email );
			if ( $titulaire ) {
				prevenir_adresse_prise( (int) $titulaire, 'inscription' );
			}
			rediriger( $retour, 'inscription-ok', 'yn-connexion' );
		}
		rediriger( $retour, 'inscription-refusee', 'yn-inscription' );
	}
	$user = new \WP_User( (int) $user_id );
	$user->set_role( 'subscriber' );
	/**
	 * Un lecteur vient de s'inscrire en façade.
	 *
	 * @param int $user_id Utilisateur.
	 */
	do_action( 'yume_lecteur_inscrit', (int) $user_id );
	rediriger( $retour, 'inscription-ok', 'yn-connexion' );
}
add_action( 'admin_post_nopriv_yume_inscription', __NAMESPACE__ . '\\traiter_inscription' );
add_action( 'admin_post_yume_inscription', __NAMESPACE__ . '\\traiter_inscription' );

/**
 * Mot de passe oublié : e-mail de réinitialisation standard de WordPress. La réponse ne
 * révèle jamais si le compte existe.
 */
function traiter_oubli(): void {
	$retour = page_retour( url_connexion() );
	exiger_nonce( 'yume_oubli', $retour, 'yn-oubli' );
	if ( limite_atteinte( 'oubli', 5, HOUR_IN_SECONDS ) ) {
		rediriger( $retour, 'trop-de-tentatives', 'yn-oubli' );
	}
	$identifiant = champ_post( 'yn_identifiant' );
	if ( '' === $identifiant ) {
		rediriger( $retour, 'oubli-vide', 'yn-oubli' );
	}
	traitement_facade( true );
	retrieve_password( $identifiant );
	traitement_facade( false );
	rediriger( $retour, 'oubli-envoye', 'yn-connexion' );
}
add_action( 'admin_post_nopriv_yume_oubli', __NAMESPACE__ . '\\traiter_oubli' );
add_action( 'admin_post_yume_oubli', __NAMESPACE__ . '\\traiter_oubli' );

/*
 * -----------------------------------------------------------------------------
 * Membres : page compte
 * -----------------------------------------------------------------------------
 */

/**
 * Formulaire réservé aux membres : un visiteur est envoyé vers la connexion.
 */
function exiger_connexion(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( url_connexion( page_retour( home_url( '/' ) ) ) );
		exit;
	}
}

/**
 * Pseudo déjà porté par un autre compte (nom affiché ou identifiant) ?
 *
 * @param string $pseudo  Pseudo.
 * @param int    $user_id Membre qui le demande.
 */
function pseudo_pris( string $pseudo, int $user_id ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$autre = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->users} WHERE ID <> %d AND ( LOWER(display_name) = LOWER(%s) OR LOWER(user_login) = LOWER(%s) ) LIMIT 1",
			$user_id,
			$pseudo,
			$pseudo
		)
	);
	return null !== $autre;
}

/**
 * Envoie le lien de confirmation d'une nouvelle adresse e-mail.
 *
 * @param \WP_User $user  Membre.
 * @param string   $email Nouvelle adresse.
 */
function demander_changement_email( \WP_User $user, string $email ): bool {
	$cle = wp_generate_password( 32, false, false );
	update_user_meta(
		$user->ID,
		META_EMAIL_ATTENTE,
		array(
			'email'  => $email,
			'cle'    => hash( 'sha256', $cle ),
			'expire' => time() + DAY_IN_SECONDS,
		)
	);
	$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$lien  = add_query_arg( 'yn-email', rawurlencode( $cle ), url_compte() );
	$sujet = sprintf( /* translators: %s : nom du site. */ __( '[%s] Confirmez votre nouvelle adresse e-mail', 'yume-core' ), $site );
	$texte = sprintf(
		/* translators: 1 : pseudo, 2 : nom du site, 3 : lien de confirmation. */
		__( "Bonjour %1\$s,\n\nVous avez demandé à changer l’adresse e-mail de votre compte %2\$s.\nPour confirmer cette nouvelle adresse, ouvrez ce lien (valable 24 heures) en étant connecté :\n\n%3\$s\n\nSi vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre adresse actuelle reste inchangée.", 'yume-core' ),
		$user->display_name,
		$site,
		$lien
	);
	return (bool) wp_mail( $email, $sujet, $texte );
}

/**
 * Prévient le titulaire d'une adresse déjà inscrite qu'une personne a tenté de l'utiliser
 * (inscription ou changement d'adresse d'un autre compte), au lieu de le révéler à cette
 * personne. Trois messages par jour et par compte au plus : au-delà, rien n'est envoyé.
 *
 * @param int    $user_id  Titulaire de l'adresse.
 * @param string $contexte 'inscription' ou 'profil'.
 * @return bool Faux si l'envoi a échoué.
 */
function prevenir_adresse_prise( int $user_id, string $contexte ): bool {
	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return true;
	}
	if ( limite_atteinte( 'adresse-prise', 3, DAY_IN_SECONDS, 'u' . $user_id ) ) {
		return true;
	}
	$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$sujet = sprintf( /* translators: %s : nom du site. */ __( '[%s] Votre adresse e-mail a été saisie sur le site', 'yume-core' ), $site );
	if ( 'inscription' === $contexte ) {
		$texte = sprintf(
			/* translators: 1 : nom du site, 2 : lien de connexion. */
			__(
				'Bonjour,

Quelqu’un vient de demander la création d’un compte %1$s avec cette adresse e-mail. Aucun nouveau compte n’a été créé : cette adresse est déjà associée à votre compte.

Si c’était vous et que vous avez oublié votre mot de passe, utilisez « Mot de passe oublié » :

%2$s

Sinon, ignorez simplement ce message.',
				'yume-core'
			),
			$site,
			url_connexion() . '#yn-oubli'
		);
	} else {
		$texte = sprintf(
			/* translators: %s : nom du site. */
			__(
				'Bonjour,

Quelqu’un a demandé à utiliser cette adresse e-mail pour un autre compte %s. La demande a été refusée : cette adresse reste associée à votre compte et rien n’a été modifié.

Vous n’avez rien à faire.',
				'yume-core'
			),
			$site
		);
	}
	return (bool) wp_mail( $user->user_email, $sujet, $texte );
}

/**
 * Profil et sécurité : pseudo, adresse e-mail (confirmée par lien) et mot de passe. Changer
 * l'adresse ou le mot de passe exige le mot de passe actuel.
 */
function traiter_profil(): void {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( 'yume_compte_profil', $retour, 'yn-profil' );
	$user     = wp_get_current_user();
	$codes    = array();
	$erreurs  = array();
	$pseudo   = trim( champ_post( 'yn_pseudo' ) );
	$email    = sanitize_email( champ_post( 'yn_email' ) );
	$actuel   = mot_de_passe_post( 'yn_mdp_actuel' );
	$nouveau  = mot_de_passe_post( 'yn_mdp_nouveau' );
	$confirme = mot_de_passe_post( 'yn_mdp_confirmation' );

	// Pseudo (nom affiché).
	if ( '' !== $pseudo && $pseudo !== $user->display_name ) {
		$taille = mb_strlen( $pseudo );
		if ( $taille < 2 || $taille > 40 || wp_strip_all_tags( $pseudo ) !== $pseudo ) {
			$erreurs[] = 'pseudo-invalide';
		} elseif ( pseudo_pris( $pseudo, (int) $user->ID ) ) {
			$erreurs[] = 'pseudo-pris';
		} else {
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => $pseudo,
					'nickname'     => $pseudo,
				)
			);
			$codes[] = 'pseudo-ok';
		}
	}

	$change_email = '' !== champ_post( 'yn_email' ) && strtolower( $email ) !== strtolower( (string) $user->user_email );
	$change_mdp   = '' !== $nouveau;
	if ( $change_email || $change_mdp ) {
		if ( '' === $actuel || ! wp_check_password( $actuel, $user->user_pass, $user->ID ) ) {
			$erreurs[] = 'mdp-actuel';
		} else {
			if ( $change_email ) {
				$autre = email_exists( $email );
				if ( ! is_email( $email ) ) {
					$erreurs[] = 'email-invalide';
				} elseif ( limite_atteinte( 'profil-email', 5, HOUR_IN_SECONDS, 'u' . $user->ID ) || limite_atteinte( 'profil-email', 10, HOUR_IN_SECONDS ) ) {
					// Chaque demande envoie un e-mail : limite par membre et par adresse IP.
					$erreurs[] = 'trop-de-tentatives';
				} elseif ( $autre && (int) $autre !== (int) $user->ID ) {
					// Adresse d'un autre compte : même réponse qu'une adresse libre (pas
					// d'énumération) ; son titulaire est prévenu au lieu du lien de confirmation.
					if ( prevenir_adresse_prise( (int) $autre, 'profil' ) ) {
						$codes[] = 'email-attente';
					} else {
						$erreurs[] = 'erreur';
					}
				} elseif ( demander_changement_email( $user, $email ) ) {
					$codes[] = 'email-attente';
				} else {
					$erreurs[] = 'erreur';
				}
			}
			if ( $change_mdp ) {
				if ( mb_strlen( $nouveau ) < 8 ) {
					$erreurs[] = 'mdp-court';
				} elseif ( $nouveau !== $confirme ) {
					$erreurs[] = 'mdp-different';
				} else {
					$resultat = wp_update_user(
						array(
							'ID'        => $user->ID,
							'user_pass' => $nouveau,
						)
					);
					$codes[]  = is_wp_error( $resultat ) ? 'erreur' : 'mdp-ok';
				}
			}
		}
	}
	$tous = array_merge( $erreurs, $codes );
	rediriger( $retour, $tous ? $tous : array( 'rien-change' ), 'yn-profil' );
}
add_action( 'admin_post_yume_compte_profil', __NAMESPACE__ . '\\traiter_profil' );

/**
 * Réglages de lecture depuis la page compte (sans JavaScript).
 */
function traiter_reglages(): void {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( 'yume_compte_reglages', $retour, 'yn-reglages' );
	if ( ! function_exists( '\Yume\Core\Reader\enregistrer_reglages' ) ) {
		rediriger( $retour, 'erreur', 'yn-reglages' );
	}
	$user_id = get_current_user_id();
	if ( '' !== champ_post( 'yn_reinitialiser' ) ) {
		\Yume\Core\Reader\effacer_reglages( $user_id );
		rediriger( $retour, 'reglages-defaut', 'yn-reglages' );
	}
	$opacite = champ_post( 'yn_bgalpha' );
	\Yume\Core\Reader\enregistrer_reglages(
		$user_id,
		array(
			'size'    => champ_post( 'yn_size' ),
			'lh'      => champ_post( 'yn_lh' ),
			'font'    => champ_post( 'yn_font' ),
			'width'   => champ_post( 'yn_width' ),
			'bgAlpha' => is_numeric( $opacite ) ? (float) $opacite / 100 : null,
			'theme'   => champ_post( 'yn_theme' ),
		)
	);
	rediriger( $retour, 'reglages-ok', 'yn-reglages' );
}
add_action( 'admin_post_yume_compte_reglages', __NAMESPACE__ . '\\traiter_reglages' );

/**
 * Préférences d'alerte globales.
 */
function traiter_alertes(): void {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( 'yume_compte_alertes', $retour, 'yn-alertes' );
	enregistrer_preferences(
		get_current_user_id(),
		array(
			'sorties'      => '' !== champ_post( 'yn_sorties' ),
			'hebdo'        => '' !== champ_post( 'yn_hebdo' ),
			'commentaires' => '' !== champ_post( 'yn_commentaires' ),
		)
	);
	rediriger( $retour, 'alertes-ok', 'yn-alertes' );
}
add_action( 'admin_post_yume_compte_alertes', __NAMESPACE__ . '\\traiter_alertes' );

/**
 * Suppression du compte : « SUPPRIMER » + mot de passe actuel ; jamais pour l'équipe.
 */
function traiter_suppression(): void {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( 'yume_compte_supprimer', $retour, 'yn-donnees' );
	$user = wp_get_current_user();
	if ( ! peut_supprimer_compte( (int) $user->ID ) ) {
		rediriger( $retour, 'suppression-interdite', 'yn-donnees' );
	}
	$confirmation = strtoupper( trim( champ_post( 'yn_confirmation' ) ) );
	$mot_de_passe = mot_de_passe_post( 'yn_mdp' );
	if ( CONFIRMATION_SUPPRESSION !== $confirmation || '' === $mot_de_passe ) {
		rediriger( $retour, 'suppression-confirmation', 'yn-donnees' );
	}
	if ( ! wp_check_password( $mot_de_passe, $user->user_pass, $user->ID ) ) {
		rediriger( $retour, 'mdp-actuel', 'yn-donnees' );
	}
	wp_destroy_current_session();
	$resultat = supprimer_compte( (int) $user->ID );
	if ( is_wp_error( $resultat ) ) {
		rediriger( $retour, 'erreur', 'yn-donnees' );
	}
	wp_clear_auth_cookie();
	wp_set_current_user( 0 );
	rediriger( url_connexion(), 'compte-supprime' );
}
add_action( 'admin_post_yume_compte_supprimer', __NAMESPACE__ . '\\traiter_suppression' );

/*
 * -----------------------------------------------------------------------------
 * Fiche œuvre sans JavaScript : favori, note, alerte
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre visée par un formulaire d'action, page de retour et ancre de retour (champ yn_ancre,
 * par défaut le bloc des actions de la fiche).
 *
 * @return array{0:int,1:string,2:string}
 */
function contexte_action(): array {
	$oeuvre_id = absint( champ_post( 'yn_oeuvre' ) );
	$defaut    = oeuvre_publiee( $oeuvre_id ) ? (string) get_permalink( $oeuvre_id ) : home_url( '/' );
	$ancre     = preg_replace( '/[^a-z0-9-]/', '', strtolower( champ_post( 'yn_ancre' ) ) );
	return array( $oeuvre_id, page_retour( $defaut ), '' !== $ancre ? $ancre : 'yn-oeuvre-actions' );
}

/**
 * Visiteur : connexion, puis retour à la fiche.
 */
function action_visiteur(): void {
	list( , $retour ) = contexte_action();
	wp_safe_redirect( url_connexion( $retour ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_social_favori', __NAMESPACE__ . '\\action_visiteur' );
add_action( 'admin_post_nopriv_yume_social_note', __NAMESPACE__ . '\\action_visiteur' );
add_action( 'admin_post_nopriv_yume_social_alerte', __NAMESPACE__ . '\\action_visiteur' );

/**
 * Favori (ajouter / retirer).
 */
function action_favori(): void {
	list( $oeuvre_id, $retour, $ancre ) = contexte_action();
	exiger_nonce( 'yume_social_' . $oeuvre_id, $retour, $ancre );
	if ( 'retirer' === champ_post( 'yn_faire' ) ) {
		retirer_favori( get_current_user_id(), $oeuvre_id );
		rediriger( $retour, 'favori-retire', $ancre );
	}
	$resultat = ajouter_favori( get_current_user_id(), $oeuvre_id );
	rediriger( $retour, is_wp_error( $resultat ) ? 'erreur' : 'favori-ajoute', $ancre );
}
add_action( 'admin_post_yume_social_favori', __NAMESPACE__ . '\\action_favori' );

/**
 * Note (0 retire la note).
 */
function action_note(): void {
	list( $oeuvre_id, $retour, $ancre ) = contexte_action();
	exiger_nonce( 'yume_social_' . $oeuvre_id, $retour, $ancre );
	$valeur   = '' !== champ_post( 'yn_retirer' ) ? 0 : (int) champ_post( 'yn_note' );
	$resultat = noter( get_current_user_id(), $oeuvre_id, max( 0, min( 5, $valeur ) ) );
	if ( is_wp_error( $resultat ) ) {
		rediriger( $retour, 'erreur', $ancre );
	}
	rediriger( $retour, 0 === $valeur ? 'note-retiree' : 'note-ok', $ancre );
}
add_action( 'admin_post_yume_social_note', __NAMESPACE__ . '\\action_note' );

/**
 * Fréquence d'alerte d'un favori.
 */
function action_alerte(): void {
	list( $oeuvre_id, $retour, $ancre ) = contexte_action();
	exiger_nonce( 'yume_social_' . $oeuvre_id, $retour, $ancre );
	$resultat = definir_frequence( get_current_user_id(), $oeuvre_id, champ_post( 'yn_frequence' ) );
	if ( is_wp_error( $resultat ) ) {
		rediriger( $retour, 'yume_pas_favori' === $resultat->get_error_code() ? 'pas-favori' : 'erreur', $ancre );
	}
	rediriger( $retour, 'alerte-ok', $ancre );
}
add_action( 'admin_post_yume_social_alerte', __NAMESPACE__ . '\\action_alerte' );
