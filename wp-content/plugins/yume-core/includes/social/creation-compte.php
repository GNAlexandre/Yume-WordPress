<?php
/**
 * Création des comptes lecteurs : point d'entrée unique pour tout compte créé hors de
 * wp-admin (inscription en façade, connexion Discord, création par l'équipe), validation du
 * pseudo public (longueur, balises, adresse e-mail, mots réservés, unicité) et identifiant de
 * connexion généré. Contrat : docs/06-contrat-technique.md §7 (« Comptes lecteurs »).
 *
 * Les protections anti-abus (pot de miel, jeton horodaté, limites par adresse IP) restent à la
 * charge de l'appelant : creer_compte_lecteur() ne lit ni $_POST ni l'adresse IP et ne passe pas
 * par register_new_user() (ni registration_errors, ni register_new_user).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Méta utilisateur : compte créé sans mot de passe connu de son titulaire (compte Discord). Elle
 * est posée par l'appelant (clé meta de creer_compte_lecteur()) et retirée quand le titulaire
 * définit un mot de passe.
 */
const META_COMPTE_SANS_MDP = '_yume_compte_sans_mdp';

/** Sources de création d'un compte lecteur (argument « source » de yume_lecteur_inscrit). */
const SOURCES_COMPTE = array( 'inscription', 'discord', 'equipe' );

/**
 * Pseudo déjà porté par un autre compte (nom affiché ou identifiant, sans tenir compte de la
 * casse) ?
 *
 * @param string $pseudo  Pseudo.
 * @param int    $user_id Compte qui le demande (exclu de la recherche ; 0 : aucun).
 */
function pseudo_pris( string $pseudo, int $user_id = 0 ): bool {
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
 * Pseudos réservés (comparés sans casse, sans accents, espaces, points, tirets et tirets bas
 * confondus).
 *
 * @return string[]
 */
function pseudos_reserves(): array {
	$reserves = array( 'admin', 'administrateur', 'yume', 'yume novel', 'équipe' );
	/**
	 * Filtre les pseudos réservés, refusés à l'inscription et au changement de pseudo.
	 *
	 * @param string[] $reserves Pseudos.
	 */
	return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'yume_pseudos_reserves', $reserves ) ), 'strlen' ) );
}

/**
 * Forme comparable d'un pseudo (minuscules, sans accents, séparateurs réduits à une espace).
 *
 * @param string $pseudo Pseudo.
 */
function forme_pseudo( string $pseudo ): string {
	$forme = remove_accents( mb_strtolower( trim( $pseudo ) ) );
	return trim( (string) preg_replace( '/[\s._\-]+/u', ' ', $forme ) );
}

/**
 * Valide un pseudo public : 2 à 40 caractères, pas uniquement des espaces, sans balise ni
 * caractère de contrôle, sans « @ » (jamais une adresse e-mail), hors pseudos réservés, et libre
 * (pseudo_pris() : nom affiché ou identifiant d'un autre compte).
 *
 * Erreurs : yume_pseudo_invalide (400 ; donnée « raison » : vide, longueur, balise, email,
 * reserve) ou yume_pseudo_pris (409).
 *
 * @param string $pseudo  Pseudo (les espaces de début et de fin sont ignorées).
 * @param int    $user_id Compte qui le demande (0 : nouveau compte).
 * @return true|\WP_Error
 */
function valider_pseudo( string $pseudo, int $user_id = 0 ) {
	$pseudo = trim( $pseudo );
	$taille = mb_strlen( $pseudo );
	$raison = '';
	if ( '' === $pseudo ) {
		$raison = 'vide';
	} elseif ( $taille < 2 || $taille > 40 ) {
		$raison = 'longueur';
	} elseif ( wp_strip_all_tags( $pseudo ) !== $pseudo || preg_match( '/[<>\x00-\x1F\x7F]/u', $pseudo ) ) {
		$raison = 'balise';
	} elseif ( str_contains( $pseudo, '@' ) || is_email( $pseudo ) ) {
		$raison = 'email';
	} elseif ( in_array( forme_pseudo( $pseudo ), array_map( __NAMESPACE__ . '\\forme_pseudo', pseudos_reserves() ), true ) ) {
		$raison = 'reserve';
	}
	if ( '' !== $raison ) {
		$messages = array(
			'vide'     => __( 'Choisissez un pseudo.', 'yume-core' ),
			'longueur' => __( 'Le pseudo doit contenir de 2 à 40 caractères.', 'yume-core' ),
			'balise'   => __( 'Le pseudo ne peut pas contenir de balise ni de caractère spécial invisible.', 'yume-core' ),
			'email'    => __( 'Le pseudo ne peut pas être une adresse e-mail ni contenir « @ ».', 'yume-core' ),
			'reserve'  => __( 'Ce pseudo est réservé.', 'yume-core' ),
		);
		return new \WP_Error(
			'yume_pseudo_invalide',
			$messages[ $raison ],
			array(
				'status' => 400,
				'raison' => $raison,
			)
		);
	}
	if ( pseudo_pris( $pseudo, $user_id ) ) {
		return new \WP_Error( 'yume_pseudo_pris', __( 'Ce pseudo est déjà utilisé.', 'yume-core' ), array( 'status' => 409 ) );
	}
	return true;
}

/**
 * Identifiant de connexion généré : « yn- » suivi de 8 caractères [a-z0-9] tirés au hasard,
 * porté par aucun compte (ni comme identifiant, ni comme nom affiché).
 */
function generer_identifiant(): string {
	$alphabet    = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$dernier     = strlen( $alphabet ) - 1;
	$identifiant = '';
	for ( $essai = 0; $essai < 50; $essai++ ) {
		$identifiant = 'yn-';
		for ( $i = 0; $i < 8; $i++ ) {
			$identifiant .= $alphabet[ wp_rand( 0, $dernier ) ];
		}
		if ( ! username_exists( $identifiant ) && ! pseudo_pris( $identifiant ) ) {
			break;
		}
	}
	return $identifiant;
}

/**
 * Le compte a-t-il été créé sans mot de passe connu de son titulaire (méta _yume_compte_sans_mdp) ?
 *
 * @param int $user_id Compte.
 */
function compte_sans_mot_de_passe( int $user_id ): bool {
	return $user_id > 0 && (bool) get_user_meta( $user_id, META_COMPTE_SANS_MDP, true );
}

/**
 * Erreur de création d'un compte.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $statut  Statut HTTP.
 */
function erreur_creation_compte( string $code, string $message, int $statut ): \WP_Error {
	return new \WP_Error( $code, $message, array( 'status' => $statut ) );
}

/**
 * Crée un compte lecteur (rôle subscriber ; display_name = nickname = pseudo) et émet
 * yume_lecteur_inscrit( $user_id, $source ).
 *
 * @param array $donnees {
 *     Données du compte.
 *
 *     @type string $pseudo      Pseudo public (obligatoire, valider_pseudo()).
 *     @type string $email        Adresse e-mail (obligatoire, unique).
 *     @type string $identifiant  Identifiant de connexion ; '' (défaut) : generer_identifiant().
 *     @type string $mot_de_passe Mot de passe ; '' (défaut) : aléatoire, inconnu du titulaire.
 *     @type string $source       'inscription' (défaut), 'discord' ou 'equipe' ; les deux premières
 *                                exigent inscriptions_ouvertes().
 *     @type bool   $notifier     E-mail WordPress « choisissez votre mot de passe » au titulaire
 *                                (défaut : vrai). L'e-mail à l'administrateur suit le filtre
 *                                yume_notifier_admin_inscription (défaut : aucun), dans tous les cas.
 *     @type array  $meta         Métas utilisateur à poser à la création (clé => valeur).
 * }
 * @return int|\WP_Error ID du compte, ou yume_inscriptions_fermees (403), yume_pseudo_invalide (400),
 *                       yume_pseudo_pris (409), yume_email_invalide (400), yume_email_pris (409),
 *                       yume_identifiant_invalide (400), yume_identifiant_pris (409),
 *                       yume_creation_echec (500, erreur de wp_insert_user() en donnée « erreur »).
 */
function creer_compte_lecteur( array $donnees ) {
	$texte  = static function ( string $cle ) use ( $donnees ): string {
		return isset( $donnees[ $cle ] ) && is_scalar( $donnees[ $cle ] ) ? trim( (string) $donnees[ $cle ] ) : '';
	};
	$source = $texte( 'source' );
	$source = in_array( $source, SOURCES_COMPTE, true ) ? $source : 'inscription';
	if ( 'equipe' !== $source && ! inscriptions_ouvertes() ) {
		return erreur_creation_compte( 'yume_inscriptions_fermees', __( 'Les inscriptions sont fermées pour le moment.', 'yume-core' ), 403 );
	}

	$pseudo = $texte( 'pseudo' );
	$valide = valider_pseudo( $pseudo );
	if ( is_wp_error( $valide ) ) {
		return $valide;
	}

	$email = sanitize_email( $texte( 'email' ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return erreur_creation_compte( 'yume_email_invalide', __( 'Adresse e-mail invalide.', 'yume-core' ), 400 );
	}
	if ( email_exists( $email ) ) {
		return erreur_creation_compte( 'yume_email_pris', __( 'Cette adresse e-mail est déjà associée à un compte.', 'yume-core' ), 409 );
	}

	$identifiant = $texte( 'identifiant' );
	if ( '' === $identifiant ) {
		$identifiant = generer_identifiant();
	} elseif ( ! validate_username( $identifiant ) || mb_strlen( $identifiant ) > 60 ) {
		return erreur_creation_compte( 'yume_identifiant_invalide', __( 'Identifiant de connexion invalide.', 'yume-core' ), 400 );
	} elseif ( username_exists( $identifiant ) ) {
		return erreur_creation_compte( 'yume_identifiant_pris', __( 'Cet identifiant de connexion est déjà utilisé.', 'yume-core' ), 409 );
	}

	$mot_de_passe = isset( $donnees['mot_de_passe'] ) && is_string( $donnees['mot_de_passe'] ) ? $donnees['mot_de_passe'] : '';
	$genere       = '' === $mot_de_passe;
	if ( $genere ) {
		$mot_de_passe = wp_generate_password( 24 );
	}
	$metas = array();
	foreach ( isset( $donnees['meta'] ) && is_array( $donnees['meta'] ) ? $donnees['meta'] : array() as $cle => $valeur ) {
		if ( is_string( $cle ) && '' !== $cle ) {
			$metas[ $cle ] = $valeur;
		}
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => $identifiant,
			'user_pass'    => $mot_de_passe,
			'user_email'   => $email,
			'display_name' => $pseudo,
			'nickname'     => $pseudo,
			'role'         => 'subscriber',
			'meta_input'   => $metas,
		)
	);
	if ( is_wp_error( $user_id ) ) {
		$code = $user_id->get_error_code();
		if ( 'existing_user_login' === $code ) {
			return erreur_creation_compte( 'yume_identifiant_pris', __( 'Cet identifiant de connexion est déjà utilisé.', 'yume-core' ), 409 );
		}
		if ( in_array( $code, array( 'invalid_username', 'user_login_too_long', 'empty_user_login' ), true ) ) {
			return erreur_creation_compte( 'yume_identifiant_invalide', __( 'Identifiant de connexion invalide.', 'yume-core' ), 400 );
		}
		if ( 'existing_user_email' === $code ) {
			return erreur_creation_compte( 'yume_email_pris', __( 'Cette adresse e-mail est déjà associée à un compte.', 'yume-core' ), 409 );
		}
		return new \WP_Error(
			'yume_creation_echec',
			__( 'Le compte n’a pas pu être créé.', 'yume-core' ),
			array(
				'status' => 500,
				'erreur' => $user_id,
			)
		);
	}
	$user_id = (int) $user_id;
	if ( $genere ) {
		// Comme register_new_user() : rappel de choisir un mot de passe dans wp-admin.
		update_user_meta( $user_id, 'default_password_nag', true );
	}

	$notifier_admin = static function () use ( $user_id, $source ): bool {
		/**
		 * Faut-il prévenir l'administrateur de chaque compte lecteur créé ?
		 *
		 * @param bool   $notifier Défaut : non.
		 * @param int    $user_id  Compte créé.
		 * @param string $source   inscription, discord ou equipe.
		 */
		return (bool) apply_filters( 'yume_notifier_admin_inscription', false, $user_id, $source );
	};
	add_filter( 'wp_send_new_user_notification_to_admin', $notifier_admin );
	$notifier = ! array_key_exists( 'notifier', $donnees ) || (bool) $donnees['notifier'];
	wp_send_new_user_notifications( $user_id, $notifier ? 'both' : 'admin' );
	remove_filter( 'wp_send_new_user_notification_to_admin', $notifier_admin );

	/**
	 * Un compte lecteur vient d'être créé (rôle et métas en place, e-mails envoyés).
	 *
	 * @param int    $user_id Compte.
	 * @param string $source  inscription, discord ou equipe.
	 */
	do_action( 'yume_lecteur_inscrit', $user_id, $source );
	return $user_id;
}
