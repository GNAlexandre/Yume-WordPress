<?php
/**
 * Connexion Discord : configuration (constantes de wp-config.php prioritaires sur les réglages),
 * métas de liaison, comptes autorisés, messages de retour (paramètre yn-discord).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

/** Méta utilisateur : identifiant Discord (snowflake) du compte lié. */
const META_ID = 'yume_discord_id';

/** Méta utilisateur : nom d'utilisateur Discord au moment de la liaison (affichage seulement). */
const META_PSEUDO = 'yume_discord_pseudo';

/** Méta utilisateur : date de la liaison (GMT, Y-m-d H:i:s). */
const META_LIE_LE = 'yume_discord_lie_le';

/** Paramètre d'adresse des messages du module. */
const PARAM_MESSAGE = 'yn-discord';

/** Ancre de la rubrique de Mon compte. */
const ANCRE_COMPTE = 'yn-discord';

/** Modes du départ : connexion (visiteur, crée le compte au besoin) ou liaison (membre connecté). */
const MODES = array( 'connexion', 'liaison' );

/**
 * Le module social (création de compte, page compte, limites) est-il chargé ?
 */
function social_disponible(): bool {
	return function_exists( '\Yume\Core\Social\creer_compte_lecteur' )
		&& function_exists( '\Yume\Core\Social\connexion_reservee_wordpress' )
		&& function_exists( '\Yume\Core\Social\limite_atteinte' );
}

/**
 * Valeur non vide d'une constante de wp-config.php ('' si absente ou vide).
 *
 * @param string $nom Nom de la constante.
 */
function constante( string $nom ): string {
	if ( ! defined( $nom ) ) {
		return '';
	}
	$valeur = constant( $nom );
	return is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
}

/**
 * Réglage texte du module ('' si absent).
 *
 * @param string $cle Clé du réglage.
 */
function reglage_texte( string $cle ): string {
	$valeur = function_exists( 'yume_setting' ) ? yume_setting( $cle, '' ) : '';
	return is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
}

/**
 * Réglage booléen du module.
 *
 * @param string $cle    Clé du réglage.
 * @param bool   $defaut Défaut.
 */
function reglage_booleen( string $cle, bool $defaut ): bool {
	return (bool) ( function_exists( 'yume_setting' ) ? yume_setting( $cle, $defaut ) : $defaut );
}

/**
 * Client ID de l'application Discord : constante YUME_DISCORD_CLIENT_ID, sinon réglage
 * discord_client_id. Jamais affiché hors des réglages.
 */
function client_id(): string {
	$constante = constante( 'YUME_DISCORD_CLIENT_ID' );
	return '' !== $constante ? $constante : reglage_texte( 'discord_client_id' );
}

/**
 * Client Secret de l'application Discord : constante YUME_DISCORD_CLIENT_SECRET (recommandé,
 * wp-config.php), sinon réglage discord_client_secret (type secret). Jamais affiché.
 */
function client_secret(): string {
	$constante = constante( 'YUME_DISCORD_CLIENT_SECRET' );
	return '' !== $constante ? $constante : reglage_texte( 'discord_client_secret' );
}

/**
 * Connexion Discord utilisable : module social chargé, réglage discord_connexion coché, Client ID et
 * Client Secret connus. Sinon aucun bouton et la route de retour répond 404.
 */
function discord_configure(): bool {
	return social_disponible()
		&& reglage_booleen( 'discord_connexion', false )
		&& '' !== client_id()
		&& '' !== client_secret();
}

/**
 * Création d'un compte au premier passage autorisée par le réglage discord_inscription (sous
 * réserve de Social\inscriptions_ouvertes()).
 */
function inscription_discord(): bool {
	return reglage_booleen( 'discord_inscription', true );
}

/**
 * Adresse de retour (Redirect URI) à déclarer exactement sur le portail Discord.
 */
function url_retour(): string {
	return rest_url( 'yume/v1/discord/retour' );
}

/**
 * Seuils de la limite par adresse IP (retours, et départs comptés à part).
 *
 * @return array{max:int,duree:int}
 */
function limites(): array {
	/**
	 * Filtre la limite des connexions Discord par adresse IP.
	 *
	 * @param array $limites max (10 retours) et duree (900 s).
	 */
	$limites = (array) apply_filters(
		'yume_discord_limites',
		array(
			'max'   => 10,
			'duree' => 15 * MINUTE_IN_SECONDS,
		)
	);
	return array(
		'max'   => max( 1, (int) ( $limites['max'] ?? 10 ) ),
		'duree' => max( 60, (int) ( $limites['duree'] ?? 900 ) ),
	);
}

/**
 * Identifiant Discord valide (snowflake : chiffres seulement) ?
 *
 * @param mixed $id Valeur.
 */
function id_valide( $id ): bool {
	return is_string( $id ) && 1 === preg_match( '/^\d{1,25}$/', $id );
}

/*
 * -----------------------------------------------------------------------------
 * Liaison
 * -----------------------------------------------------------------------------
 */

/**
 * Compte lié à cet identifiant Discord (0 sinon).
 *
 * @param string $discord_id Identifiant Discord.
 */
function utilisateur_par_discord( string $discord_id ): int {
	if ( ! id_valide( $discord_id ) ) {
		return 0;
	}
	$ids = get_users(
		array(
			'meta_key'    => META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- recherche ponctuelle (retour de Discord).
			'meta_value'  => $discord_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- idem.
			'number'      => 1,
			'fields'      => 'ID',
			'count_total' => false,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Identifiant Discord lié à ce compte ('' sinon).
 *
 * @param int $user_id Compte.
 */
function discord_lie( int $user_id ): string {
	$id = $user_id > 0 ? (string) get_user_meta( $user_id, META_ID, true ) : '';
	return id_valide( $id ) ? $id : '';
}

/**
 * Métas de liaison d'un profil Discord (création de compte ou liaison).
 *
 * @param array $profil Profil normalisé (profil_discord()).
 * @return array<string,string>
 */
function metas_liaison( array $profil ): array {
	return array(
		META_ID     => (string) $profil['id'],
		META_PSEUDO => mb_substr( sanitize_text_field( (string) ( $profil['username'] ?? '' ) ), 0, 64 ),
		META_LIE_LE => gmdate( 'Y-m-d H:i:s' ),
	);
}

/**
 * Lie un compte Discord à un compte du site (métas yume_discord_*), puis émet yume_discord_lie.
 * Refusé si ce compte Discord est déjà lié à un autre compte.
 *
 * @param int   $user_id Compte du site.
 * @param array $profil  Profil Discord (id obligatoire, username).
 * @return true|\WP_Error yume_discord_profil (400), yume_utilisateur_invalide (404), yume_discord_deja_lie (409).
 */
function lier( int $user_id, array $profil ) {
	$discord_id = isset( $profil['id'] ) && is_scalar( $profil['id'] ) ? (string) $profil['id'] : '';
	if ( ! id_valide( $discord_id ) ) {
		return new \WP_Error( 'yume_discord_profil', __( 'Profil Discord invalide.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new \WP_Error( 'yume_utilisateur_invalide', __( 'Utilisateur inconnu.', 'yume-core' ), array( 'status' => 404 ) );
	}
	$titulaire = utilisateur_par_discord( $discord_id );
	if ( $titulaire > 0 && $titulaire !== $user_id ) {
		return new \WP_Error( 'yume_discord_deja_lie', __( 'Ce compte Discord est déjà lié à un autre compte.', 'yume-core' ), array( 'status' => 409 ) );
	}
	$profil['id'] = $discord_id;
	foreach ( metas_liaison( $profil ) as $cle => $valeur ) {
		update_user_meta( $user_id, $cle, $valeur );
	}
	/**
	 * Un compte Discord vient d'être lié à un compte du site.
	 *
	 * @param int    $user_id    Compte du site.
	 * @param string $discord_id Identifiant Discord.
	 */
	do_action( 'yume_discord_lie', $user_id, $discord_id );
	return true;
}

/**
 * Retire la liaison Discord d'un compte (métas), puis émet yume_discord_delie.
 *
 * @param int $user_id Compte.
 * @return bool Faux si le compte n'était pas lié.
 */
function delier( int $user_id ): bool {
	if ( '' === discord_lie( $user_id ) ) {
		return false;
	}
	effacer_metas( $user_id );
	/**
	 * La liaison Discord d'un compte vient d'être retirée.
	 *
	 * @param int $user_id Compte.
	 */
	do_action( 'yume_discord_delie', $user_id );
	return true;
}

/**
 * Supprime les métas Discord d'un compte (sans action).
 *
 * @param int $user_id Compte.
 * @return int Métas supprimées.
 */
function effacer_metas( int $user_id ): int {
	$n = 0;
	if ( $user_id <= 0 ) {
		return 0;
	}
	foreach ( array( META_ID, META_PSEUDO, META_LIE_LE ) as $cle ) {
		if ( metadata_exists( 'user', $user_id, $cle ) ) {
			delete_user_meta( $user_id, $cle );
			++$n;
		}
	}
	return $n;
}

/**
 * Ce compte peut-il se connecter avec Discord ? Défaut : pas les comptes réservés à la page de
 * connexion WordPress (administrateurs, Social\connexion_reservee_wordpress()).
 *
 * @param \WP_User $user Compte.
 */
function connexion_autorisee( \WP_User $user ): bool {
	$autorisee = function_exists( '\Yume\Core\Social\connexion_reservee_wordpress' )
		? ! \Yume\Core\Social\connexion_reservee_wordpress( $user )
		: ! user_can( $user, 'manage_options' );
	/**
	 * Filtre les comptes autorisés à se connecter (et à lier leur compte) avec Discord.
	 *
	 * @param bool     $autorisee Défaut : tous sauf les administrateurs.
	 * @param \WP_User $user      Compte.
	 */
	return (bool) apply_filters( 'yume_discord_connexion_autorisee', $autorisee, $user );
}

/*
 * -----------------------------------------------------------------------------
 * Messages (paramètre yn-discord)
 * -----------------------------------------------------------------------------
 */

/**
 * Messages du module : code => [type, texte]. Le code reserve ajoute un lien vers la page de
 * connexion WordPress (message_html()).
 *
 * @return array<string,array{0:string,1:string}>
 */
function messages(): array {
	return array(
		'expire'               => array( 'erreur', __( 'La connexion avec Discord a expiré. Recommencez.', 'yume-core' ) ),
		'refuse'               => array( 'erreur', __( 'Connexion avec Discord annulée : l’autorisation n’a pas été donnée sur Discord.', 'yume-core' ) ),
		'non-verifie'          => array( 'erreur', __( 'Votre adresse e-mail Discord n’est pas vérifiée : vérifiez-la sur Discord, ou créez un compte avec votre adresse e-mail.', 'yume-core' ) ),
		'email-existant'       => array( 'erreur', __( 'Un compte existe déjà avec cette adresse : connectez-vous avec votre mot de passe, puis liez Discord depuis Mon compte.', 'yume-core' ) ),
		'inscriptions-fermees' => array( 'erreur', __( 'Aucun compte n’est lié à ce compte Discord et les inscriptions sont fermées pour le moment.', 'yume-core' ) ),
		'lie'                  => array( 'succes', __( 'Compte Discord lié : vous pouvez maintenant vous connecter avec Discord.', 'yume-core' ) ),
		'delie'                => array( 'succes', __( 'Compte Discord délié.', 'yume-core' ) ),
		'deja-lie'             => array( 'erreur', __( 'Ce compte Discord est déjà lié à un autre compte.', 'yume-core' ) ),
		'mdp'                  => array( 'erreur', __( 'Définissez d’abord un mot de passe (Mot de passe oublié ?) pour ne pas perdre l’accès à votre compte.', 'yume-core' ) ),
		'reserve'              => array( 'erreur', __( 'Les administrateurs se connectent par la page de connexion WordPress.', 'yume-core' ) ),
		'limite'               => array( 'erreur', __( 'Trop de tentatives de connexion avec Discord. Réessayez dans quelques minutes.', 'yume-core' ) ),
		'erreur'               => array( 'erreur', __( 'La connexion avec Discord a échoué. Réessayez dans quelques instants.', 'yume-core' ) ),
	);
}

/**
 * Code de message demandé par l'adresse courante ('' sinon).
 */
function code_courant(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage d'un code connu.
	$code = isset( $_GET[ PARAM_MESSAGE ] ) && is_string( $_GET[ PARAM_MESSAGE ] ) ? sanitize_key( wp_unslash( $_GET[ PARAM_MESSAGE ] ) ) : '';
	return isset( messages()[ $code ] ) ? $code : '';
}

/**
 * HTML d'un message (role alert pour une erreur, status sinon), '' pour un code inconnu.
 *
 * @param string $code   Code.
 * @param string $retour Page demandée après la connexion (lien administrateur du code reserve).
 */
function message_html( string $code, string $retour = '' ): string {
	$messages = messages();
	if ( ! isset( $messages[ $code ] ) ) {
		return '';
	}
	list( $type, $texte ) = $messages[ $code ];
	$html                 = esc_html( $texte );
	if ( 'reserve' === $code && function_exists( '\Yume\Core\Social\url_connexion_administrateur' ) ) {
		$html .= ' <a href="' . esc_url( \Yume\Core\Social\url_connexion_administrateur( $retour ) ) . '">' . esc_html__( 'Se connecter par la page WordPress', 'yume-core' ) . '</a>';
	}
	return '<p class="yn-avis yn-avis--' . esc_attr( $type ) . ' yn-discord__message" role="' . ( 'erreur' === $type ? 'alert' : 'status' ) . '">' . $html . '</p>';
}

/**
 * Adresse avec un code de message du module (paramètres yn-discord et yn-msg remplacés) et une ancre.
 *
 * @param string $url   Adresse locale.
 * @param string $code  Code ('' : aucun).
 * @param string $ancre Ancre (sans #).
 */
function url_message( string $url, string $code, string $ancre = '' ): string {
	$url = remove_query_arg( array( PARAM_MESSAGE, 'yn-msg' ), (string) preg_replace( '/#.*$/', '', $url ) );
	if ( '' !== $code ) {
		$url = add_query_arg( PARAM_MESSAGE, $code, $url );
	}
	return '' !== $ancre ? $url . '#' . rawurlencode( $ancre ) : $url;
}

/**
 * Page compte (sûre), ou l'accueil.
 */
function url_compte(): string {
	return function_exists( '\Yume\Core\Social\url_compte_sure' ) ? \Yume\Core\Social\url_compte_sure() : home_url( '/' );
}

/**
 * Page de connexion en façade (ou l'accueil sans module social).
 */
function url_connexion(): string {
	return function_exists( '\Yume\Core\Social\url_connexion' ) ? \Yume\Core\Social\url_connexion() : home_url( '/' );
}

/**
 * Adresse locale validée (jamais wp-login.php ni l'administration), sinon $defaut.
 *
 * @param string $url    Adresse proposée.
 * @param string $defaut Défaut.
 */
function adresse_locale( string $url, string $defaut ): string {
	$url = '' !== $url ? wp_validate_redirect( esc_url_raw( $url ), '' ) : '';
	if ( '' === $url || str_contains( $url, 'wp-login.php' ) || str_starts_with( $url, admin_url() ) ) {
		return $defaut;
	}
	return $url;
}
