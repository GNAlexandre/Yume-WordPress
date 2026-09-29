<?php
/**
 * Points d'entrée du cœur de WordPress (audit P0-B) :
 *
 * - SEC-04 : /wp/v2/users et /wp/v2/users/{id} réservés aux comptes connectés qui peuvent
 *   lister les membres (list_users) ; identifiant de connexion (slug) et lien d'auteur retirés
 *   des réponses pour les autres ;
 * - SEC-05 : en-têtes de sécurité (façade, administration, wp-login.php) et balise generator
 *   retirée (pages et flux) ;
 * - SEC-06 : XML-RPC sans méthodes d'authentification ni pingbacks, sauf pour les requêtes
 *   signées de Jetpack (connexion WordPress.com), et sans en-tête X-Pingback.
 *
 * Les formulaires du cœur (inscription et mot de passe oublié par wp-login.php, SEC-02) et la
 * confirmation d'un changement d'adresse (SEC-11) sont traités dans social/comptes.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * SEC-04 : /wp/v2/users
 * -----------------------------------------------------------------------------
 */

/**
 * Routes du cœur qui listent ou décrivent les comptes (lecture).
 *
 * @return string[]
 */
function routes_utilisateurs(): array {
	return array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' );
}

/**
 * Qui peut lire /wp/v2/users et /wp/v2/users/{id} ?
 *
 * - visiteur : non (401) ;
 * - compte avec list_users (administrateur, gérant) : oui ;
 * - son propre compte (/wp/v2/users/{son id}) : oui (comme /wp/v2/users/me) ;
 * - compte avec edit_others_posts (éditeur de WordPress) : oui, pour la liste « Auteur » de
 *   l'éditeur de blocs (l'action « changer d'auteur » exige cette capacité) ;
 * - autres (lecteurs, traducteurs…) : non (403).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return true|\WP_Error
 */
function acces_utilisateurs_rest( $requete ) {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error(
			'rest_user_cannot_view',
			__( 'Connectez-vous pour consulter la liste des membres.', 'yume-core' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
	$id       = $requete instanceof \WP_REST_Request ? (int) $requete->get_param( 'id' ) : 0;
	$autorise = current_user_can( 'list_users' )
		|| ( $id > 0 && get_current_user_id() === $id )
		|| current_user_can( 'edit_others_posts' );
	/**
	 * Le compte connecté peut-il lire /wp/v2/users (ou /wp/v2/users/{id}) ?
	 *
	 * @param bool             $autorise Valeur calculée.
	 * @param \WP_REST_Request $requete  Requête.
	 */
	if ( (bool) apply_filters( 'yume_acces_utilisateurs_rest', $autorise, $requete ) ) {
		return true;
	}
	return new \WP_Error(
		'rest_user_cannot_view',
		__( 'Vous ne pouvez pas consulter la liste des membres.', 'yume-core' ),
		array( 'status' => 403 )
	);
}

/**
 * Ajoute acces_utilisateurs_rest() devant la vérification du cœur (lecture seulement : les
 * écritures gardent les droits de WordPress et les règles de social/comptes.php).
 *
 * @param array $routes Routes REST.
 * @return array
 */
function proteger_routes_utilisateurs( $routes ) {
	if ( ! is_array( $routes ) ) {
		return $routes;
	}
	foreach ( routes_utilisateurs() as $route ) {
		if ( empty( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			continue;
		}
		foreach ( $routes[ $route ] as $i => $gestionnaire ) {
			if ( ! is_int( $i ) || ! is_array( $gestionnaire ) ) {
				continue;
			}
			$methodes = $gestionnaire['methods'] ?? '';
			$methodes = is_array( $methodes ) ? array_keys( array_filter( $methodes ) ) : explode( ',', (string) $methodes );
			if ( ! in_array( 'GET', array_map( 'strtoupper', array_map( 'trim', $methodes ) ), true ) ) {
				continue;
			}
			$origine = $gestionnaire['permission_callback'] ?? null;

			$routes[ $route ][ $i ]['permission_callback'] = static function ( $requete ) use ( $origine ) {
				$acces = acces_utilisateurs_rest( $requete );
				if ( true !== $acces ) {
					return $acces;
				}
				return is_callable( $origine ) ? call_user_func( $origine, $requete ) : true;
			};
		}
	}
	return $routes;
}
add_filter( 'rest_endpoints', __NAMESPACE__ . '\\proteger_routes_utilisateurs', 20 );

/**
 * Réponse REST d'un compte : sans list_users, ni identifiant de connexion (slug) ni lien
 * d'archive d'auteur (qui le contient), sauf pour son propre compte.
 *
 * @param \WP_REST_Response $reponse Réponse.
 * @param \WP_User          $user    Compte décrit.
 * @return \WP_REST_Response
 */
function masquer_identifiant_rest( $reponse, $user ) {
	if ( ! $reponse instanceof \WP_REST_Response || current_user_can( 'list_users' ) ) {
		return $reponse;
	}
	if ( $user instanceof \WP_User && $user->ID > 0 && get_current_user_id() === (int) $user->ID ) {
		return $reponse;
	}
	$data = $reponse->get_data();
	if ( is_array( $data ) ) {
		unset( $data['slug'], $data['link'] );
		$reponse->set_data( $data );
	}
	return $reponse;
}
add_filter( 'rest_prepare_user', __NAMESPACE__ . '\\masquer_identifiant_rest', 20, 2 );

/*
 * -----------------------------------------------------------------------------
 * SEC-05 : en-têtes de sécurité et version de WordPress
 * -----------------------------------------------------------------------------
 */

/**
 * En-têtes de sécurité envoyés sur la façade, l'administration et wp-login.php.
 *
 * La CSP est en « Report-Only » et minimale : elle n'interdit ni les scripts en ligne du thème
 * ni ceux de Gutenberg/Jetpack (une CSP complète exigerait des nonces partout).
 * Strict-Transport-Security est laissé à l'hébergeur (WordPress.com l'envoie déjà).
 *
 * @param bool $integration Page d'intégration (/embed/) : faite pour être affichée dans un
 *                          cadre sur un autre site, donc sans X-Frame-Options.
 * @return array<string,string> Nom => valeur (valeur vide : en-tête non envoyé).
 */
function entetes_securite( bool $integration = false ): array {
	$entetes = array(
		'X-Frame-Options'                     => 'SAMEORIGIN',
		'X-Content-Type-Options'              => 'nosniff',
		'Referrer-Policy'                     => 'strict-origin-when-cross-origin',
		'Permissions-Policy'                  => 'camera=(), microphone=(), geolocation=()',
		// Appliquée : seul le site et l'aperçu du tableau de bord WordPress.com peuvent l'encadrer
		// (les navigateurs récents suivent frame-ancestors et ignorent alors X-Frame-Options).
		'Content-Security-Policy'             => "frame-ancestors 'self' https://wordpress.com https://*.wordpress.com",
		'Content-Security-Policy-Report-Only' => "object-src 'none'; base-uri 'self'",
	);
	if ( $integration ) {
		unset( $entetes['X-Frame-Options'], $entetes['Content-Security-Policy'] );
	}
	/**
	 * Filtre les en-têtes de sécurité (valeur vide : en-tête retiré).
	 *
	 * @param array<string,string> $entetes     Nom => valeur.
	 * @param bool                 $integration Page d'intégration (/embed/).
	 */
	$entetes = (array) apply_filters( 'yume_entetes_securite', $entetes, $integration );
	$propres = array();
	foreach ( $entetes as $nom => $valeur ) {
		$nom    = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $nom );
		$valeur = trim( str_replace( array( "\r", "\n" ), '', (string) $valeur ) );
		if ( '' !== $nom && '' !== $valeur ) {
			$propres[ $nom ] = $valeur;
		}
	}
	return $propres;
}

/**
 * Envoie les en-têtes de sécurité (send_headers, admin_init, login_init).
 *
 * @param \WP|mixed $wp Requête principale (send_headers), sinon inutilisé.
 */
function envoyer_entetes_securite( $wp = null ): void {
	if ( headers_sent() ) {
		return;
	}
	$integration = $wp instanceof \WP && isset( $wp->query_vars['embed'] );
	foreach ( entetes_securite( $integration ) as $nom => $valeur ) {
		header( $nom . ': ' . $valeur );
	}
}
add_action( 'send_headers', __NAMESPACE__ . '\\envoyer_entetes_securite' );
add_action( 'admin_init', __NAMESPACE__ . '\\envoyer_entetes_securite', 0, 0 );
add_action( 'login_init', __NAMESPACE__ . '\\envoyer_entetes_securite', 0, 0 );

/**
 * Balise generator retirée des pages (wp_head) et des flux (the_generator).
 */
function retirer_generateur(): void {
	remove_action( 'wp_head', 'wp_generator' );
	add_filter( 'the_generator', '__return_empty_string', 99 );
}
add_action( 'init', __NAMESPACE__ . '\\retirer_generateur' );

/*
 * -----------------------------------------------------------------------------
 * SEC-06 : XML-RPC
 * -----------------------------------------------------------------------------
 *
 * Jetpack (connexion WordPress.com) passe par xmlrpc.php avec des requêtes signées
 * (?for=jetpack&token=…&timestamp=…&nonce=…&signature=…) : Jetpack vérifie lui-même la
 * signature et, pour ces requêtes, remplace l'authentification par identifiant et mot de
 * passe par la sienne. On laisse donc ces requêtes intactes (méthodes et authentification),
 * et pour toutes les autres :
 * - xmlrpc_enabled = false : wp_xmlrpc_server::login() refuse toute méthode authentifiée
 *   (erreur 405) avant même de vérifier le mot de passe (plus de force brute) ;
 * - filtre authenticate : même refus si une extension appelle wp_authenticate() pendant
 *   une requête XML-RPC ;
 * - xmlrpc_methods : méthodes d'énumération retirées (wp.getUsersBlogs et ses alias
 *   blogger.* / metaWeblog.*, wp.getProfile, blogger.getUserInfo) ainsi que les pingbacks ;
 * - system.multicall (amplification) : ajouté par IXR_Server après xmlrpc_methods, il ne peut
 *   pas être retiré par ce filtre ; refuser_multicall() répond donc une erreur XML-RPC
 *   « méthode inexistante » avant la création du serveur (filtre wp_xmlrpc_server_class).
 */

/**
 * Requête XML-RPC signée par Jetpack ? (La signature elle-même est vérifiée par Jetpack.)
 */
function requete_jetpack(): bool {
	$jetpack = class_exists( '\Automattic\Jetpack\Connection\Manager' ) || defined( 'JETPACK__VERSION' );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- requête signée, vérifiée par Jetpack.
	$pour   = isset( $_GET['for'] ) && is_string( $_GET['for'] ) ? sanitize_key( wp_unslash( $_GET['for'] ) ) : '';
	$signee = isset( $_GET['token'], $_GET['timestamp'], $_GET['nonce'], $_GET['signature'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	/**
	 * La requête XML-RPC courante vient-elle de Jetpack (connexion WordPress.com) ?
	 *
	 * @param bool $jetpack Valeur calculée.
	 */
	return (bool) apply_filters( 'yume_requete_jetpack', $jetpack && 'jetpack' === $pour && $signee );
}

/**
 * Méthodes XML-RPC retirées hors Jetpack.
 *
 * @return string[]
 */
function methodes_xmlrpc_retirees(): array {
	return array( 'wp.getUsersBlogs', 'blogger.getUsersBlogs', 'metaWeblog.getUsersBlogs', 'wp.getProfile', 'blogger.getUserInfo', 'system.multicall', 'pingback.ping', 'pingback.extensions.getPingbacks' );
}

/**
 * Nom de la méthode appelée par un corps XML-RPC (analysé comme le fera le serveur, entités
 * comprises), chaîne vide si illisible.
 *
 * @param string $corps Corps de la requête.
 */
function methode_xmlrpc_appelee( string $corps ): string {
	if ( '' === trim( $corps ) || ! class_exists( 'IXR_Message' ) ) {
		return '';
	}
	$message = new \IXR_Message( $corps );
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés d'IXR_Message.
	if ( ! $message->parse() || 'methodCall' !== $message->messageType ) {
		return '';
	}
	return (string) $message->methodName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété d'IXR_Message.
}

/**
 * Appel à system.multicall hors Jetpack : erreur XML-RPC -32601 (comme une méthode retirée),
 * avant la création du serveur.
 *
 * @param string $classe Classe du serveur XML-RPC (inchangée).
 * @return string
 */
function refuser_multicall( $classe ) {
	$corps = isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) && is_string( $GLOBALS['HTTP_RAW_POST_DATA'] ) ? $GLOBALS['HTTP_RAW_POST_DATA'] : '';
	if ( requete_jetpack() || 'system.multicall' !== methode_xmlrpc_appelee( $corps ) ) {
		return $classe;
	}
	$erreur = new \IXR_Error( -32601, 'server error. requested method system.multicall does not exist.' );
	$xml    = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $erreur->getXml();
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'Content-Length: ' . strlen( $xml ) );
	}
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML produit par IXR_Error (échappé).
	exit;
}
add_filter( 'wp_xmlrpc_server_class', __NAMESPACE__ . '\\refuser_multicall', 1 );

/**
 * Filtre xmlrpc_methods.
 *
 * @param array $methodes Méthode => rappel.
 * @return array
 */
function filtrer_methodes_xmlrpc( $methodes ) {
	if ( ! is_array( $methodes ) || requete_jetpack() ) {
		return $methodes;
	}
	foreach ( methodes_xmlrpc_retirees() as $methode ) {
		unset( $methodes[ $methode ] );
	}
	return $methodes;
}
add_filter( 'xmlrpc_methods', __NAMESPACE__ . '\\filtrer_methodes_xmlrpc', 20 );

/**
 * Méthodes XML-RPC authentifiées : réservées à Jetpack.
 *
 * @param bool $actif Valeur calculée.
 */
function xmlrpc_authentifie( $actif ): bool {
	return (bool) $actif && requete_jetpack();
}
add_filter( 'xmlrpc_enabled', __NAMESPACE__ . '\\xmlrpc_authentifie', 20 );

/**
 * Aucune authentification par mot de passe pendant une requête XML-RPC hors Jetpack.
 *
 * @param \WP_User|\WP_Error|null $user Résultat des filtres précédents.
 * @return \WP_User|\WP_Error|null
 */
function bloquer_authentification_xmlrpc( $user ) {
	if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST || requete_jetpack() ) {
		return $user;
	}
	return new \WP_Error( 'yume_xmlrpc_ferme', __( 'Connexion par XML-RPC désactivée sur ce site.', 'yume-core' ) );
}
add_filter( 'authenticate', __NAMESPACE__ . '\\bloquer_authentification_xmlrpc', 100 );

/**
 * Pas d'en-tête X-Pingback (les pingbacks sont retirés).
 *
 * @param array $entetes En-têtes HTTP.
 * @return array
 */
function retirer_x_pingback( $entetes ) {
	if ( is_array( $entetes ) ) {
		unset( $entetes['X-Pingback'] );
	}
	return $entetes;
}
add_filter( 'wp_headers', __NAMESPACE__ . '\\retirer_x_pingback', 20 );
