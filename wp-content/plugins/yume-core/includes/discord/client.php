<?php
/**
 * Appels HTTP à Discord (OAuth2) : adresse d'autorisation, échange du code contre un jeton
 * d'accès, profil (users/@me), révocation du jeton. wp_safe_remote_* seulement (aucune
 * dépendance), User-Agent réel du site. Le jeton n'est jamais stocké : il ne vit que le temps du
 * retour (traiter_retour()).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

/** Page d'autorisation de Discord. */
const URL_AUTORISATION = 'https://discord.com/oauth2/authorize';

/** API de Discord (version 10). */
const API = 'https://discord.com/api/v10';

/** Scopes demandés : identifiant, nom, adresse e-mail et son état de vérification. */
const SCOPES = 'identify email';

/**
 * User-Agent réel du site.
 */
function agent(): string {
	$version = defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '0';
	return 'YumeNovel/' . $version . ' (+' . home_url( '/' ) . ')';
}

/**
 * PKCE (code_challenge S256) activé ? Filtre yume_discord_pkce pour le couper si Discord le
 * refusait pour un client confidentiel.
 */
function pkce_actif(): bool {
	/**
	 * Filtre l'utilisation de PKCE (code_challenge S256) dans la connexion Discord.
	 *
	 * @param bool $actif Défaut : vrai.
	 */
	return (bool) apply_filters( 'yume_discord_pkce', true );
}

/**
 * Encodage base64url sans remplissage (RFC 7636).
 *
 * @param string $octets Octets.
 */
function base64url( string $octets ): string {
	return rtrim( strtr( base64_encode( $octets ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodage base64url de PKCE (RFC 7636).
}

/**
 * Valeur aléatoire de 32 octets en base64url (43 caractères) : state ou code_verifier.
 */
function aleatoire(): string {
	return base64url( random_bytes( 32 ) );
}

/**
 * Valeur code_challenge (S256) d'un code_verifier.
 *
 * @param string $verifier code_verifier.
 */
function challenge( string $verifier ): string {
	return base64url( hash( 'sha256', $verifier, true ) );
}

/**
 * Valeur du paramètre prompt de la page d'autorisation : « none » (défaut : Discord ne redemande
 * pas l'autorisation quand l'application est déjà autorisée avec les mêmes scopes) ou « consent »
 * (écran d'autorisation à chaque connexion). Filtre yume_discord_prompt ; toute autre valeur
 * revient à « none ».
 */
function prompt(): string {
	/**
	 * Filtre le paramètre prompt de l'autorisation Discord.
	 *
	 * @param string $prompt 'none' (défaut) ou 'consent'.
	 */
	$prompt = apply_filters( 'yume_discord_prompt', 'none' );
	return 'consent' === $prompt ? 'consent' : 'none';
}

/**
 * Adresse de la page d'autorisation de Discord.
 *
 * @param string $state     Valeur state (liée au navigateur par le cookie yume_discord_etat).
 * @param string $challenge code_challenge S256 ('' : PKCE coupé).
 */
function url_autorisation( string $state, string $challenge ): string {
	$parametres = array(
		'client_id'     => client_id(),
		'response_type' => 'code',
		'redirect_uri'  => url_retour(),
		'scope'         => SCOPES,
		'state'         => $state,
	);
	if ( '' !== $challenge ) {
		$parametres['code_challenge']        = $challenge;
		$parametres['code_challenge_method'] = 'S256';
	}
	$parametres['prompt'] = prompt();
	return URL_AUTORISATION . '?' . http_build_query( $parametres, '', '&', PHP_QUERY_RFC3986 );
}

/**
 * Réponse JSON décodée d'un appel à Discord, ou erreur.
 *
 * @param array|\WP_Error $reponse Réponse de wp_safe_remote_*().
 * @return array<string,mixed>|\WP_Error yume_discord_reseau (502) ou yume_discord_api (502).
 */
function decoder( $reponse ) {
	if ( is_wp_error( $reponse ) ) {
		return new \WP_Error( 'yume_discord_reseau', __( 'Discord est injoignable.', 'yume-core' ), array( 'status' => 502 ) );
	}
	$code    = (int) wp_remote_retrieve_response_code( $reponse );
	$donnees = json_decode( (string) wp_remote_retrieve_body( $reponse ), true );
	if ( 200 !== $code || ! is_array( $donnees ) ) {
		return new \WP_Error(
			'yume_discord_api',
			__( 'Réponse inattendue de Discord.', 'yume-core' ),
			array(
				'status' => 502,
				'http'   => $code,
			)
		);
	}
	return $donnees;
}

/**
 * Échange le code d'autorisation contre un jeton d'accès (POST /oauth2/token).
 *
 * @param string $code     Code reçu sur l'adresse de retour.
 * @param string $verifier code_verifier du départ ('' : PKCE coupé).
 * @return array<string,mixed>|\WP_Error {access_token, token_type, expires_in, scope, …}.
 */
function echanger_code( string $code, string $verifier ) {
	$corps = array(
		'client_id'     => client_id(),
		'client_secret' => client_secret(),
		'grant_type'    => 'authorization_code',
		'code'          => $code,
		'redirect_uri'  => url_retour(),
	);
	if ( '' !== $verifier ) {
		$corps['code_verifier'] = $verifier;
	}
	$donnees = decoder(
		wp_safe_remote_post(
			API . '/oauth2/token',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => agent(),
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'        => $corps,
			)
		)
	);
	if ( is_wp_error( $donnees ) ) {
		return $donnees;
	}
	$jeton = $donnees['access_token'] ?? '';
	$type  = $donnees['token_type'] ?? '';
	if ( ! is_string( $jeton ) || '' === $jeton || ! is_string( $type ) || 'bearer' !== strtolower( $type ) ) {
		return new \WP_Error( 'yume_discord_api', __( 'Réponse inattendue de Discord.', 'yume-core' ), array( 'status' => 502 ) );
	}
	return $donnees;
}

/**
 * Profil du compte Discord (GET /users/@me), normalisé.
 *
 * @param string $jeton Jeton d'accès.
 * @return array{id:string,username:string,global_name:string,email:string,verified:bool}|\WP_Error
 */
function profil_discord( string $jeton ) {
	$donnees = decoder(
		wp_safe_remote_get(
			API . '/users/@me',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => agent(),
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $jeton,
				),
			)
		)
	);
	if ( is_wp_error( $donnees ) ) {
		return $donnees;
	}
	$id = isset( $donnees['id'] ) && is_scalar( $donnees['id'] ) ? (string) $donnees['id'] : '';
	if ( ! id_valide( $id ) ) {
		return new \WP_Error( 'yume_discord_api', __( 'Réponse inattendue de Discord.', 'yume-core' ), array( 'status' => 502 ) );
	}
	$texte = static function ( $valeur ): string {
		return is_string( $valeur ) ? trim( sanitize_text_field( $valeur ) ) : '';
	};
	return array(
		'id'          => $id,
		'username'    => $texte( $donnees['username'] ?? '' ),
		'global_name' => $texte( $donnees['global_name'] ?? '' ),
		'email'       => is_string( $donnees['email'] ?? null ) ? sanitize_email( $donnees['email'] ) : '',
		// Seul le booléen true compte : une adresse non vérifiée n'est jamais utilisée.
		'verified'    => true === ( $donnees['verified'] ?? null ),
	);
}

/**
 * Révoque le jeton d'accès (POST /oauth2/token/revoke) ; un échec est ignoré.
 *
 * @param string $jeton Jeton d'accès.
 */
function revoquer( string $jeton ): void {
	if ( '' === $jeton ) {
		return;
	}
	wp_safe_remote_post(
		API . '/oauth2/token/revoke',
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'user-agent'  => agent(),
			'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'        => array(
				'token'           => $jeton,
				'token_type_hint' => 'access_token',
				'client_id'       => client_id(),
				'client_secret'   => client_secret(),
			),
		)
	);
}
