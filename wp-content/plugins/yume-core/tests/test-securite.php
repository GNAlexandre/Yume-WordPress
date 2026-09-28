<?php
/**
 * Tests des points d'entrée du cœur (audit P0-B) : inscription et mot de passe oublié par
 * wp-login.php (SEC-02), /wp/v2/users et métas des tomes (SEC-04), en-têtes de sécurité et
 * balise generator (SEC-05), XML-RPC (SEC-06), changement d'adresse e-mail confirmé (SEC-11).
 *
 * Lancement : tools/localenv/test.sh securite
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Core\entetes_securite;
use function Yume\Core\Core\methodes_xmlrpc_retirees;
use function Yume\Core\Social\traitement_facade;
use function Yume\Core\Social\url_connexion;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tsec_)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute $rappel et renvoie l'URL de redirection demandée (chaîne vide si aucune).
 *
 * @param callable $rappel Fonction.
 * @throws RuntimeException Toute exception autre que la redirection interceptée.
 */
function yume_tsec_redirection( callable $rappel ): string {
	$filtre = static function ( $url ) {
		throw new RuntimeException( (string) $url, 302 );
	};
	add_filter( 'wp_redirect', $filtre, 1 );
	try {
		$rappel();
	} catch ( RuntimeException $e ) {
		if ( 302 !== $e->getCode() ) {
			throw $e;
		}
		return $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filtre, 1 );
	}
	return '';
}

/**
 * Exécute $rappel avec une adresse IP donnée (restaurée ensuite).
 *
 * @param string   $ip     Adresse IP.
 * @param callable $rappel Fonction.
 * @return mixed
 */
function yume_tsec_ip( string $ip, callable $rappel ) {
	$avant                  = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde puis restauration telle quelle.
	$_SERVER['REMOTE_ADDR'] = $ip;
	try {
		return $rappel();
	} finally {
		if ( null === $avant ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $avant;
		}
	}
}

/**
 * Exécute $rappel avec des champs POST donnés (restaurés ensuite).
 *
 * @param array    $post   Champs.
 * @param callable $rappel Fonction.
 * @return mixed
 */
function yume_tsec_post( array $post, callable $rappel ) {
	$avant                     = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$methode                   = $_SERVER['REQUEST_METHOD'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$_POST                     = $post;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		return $rappel();
	} finally {
		$_POST = $avant;
		if ( null === $methode ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $methode;
		}
	}
}

/**
 * Exécute $rappel et renvoie les e-mails envoyés.
 *
 * @param callable $rappel Fonction.
 * @return array<int,array{to:string,subject:string,message:string}>
 */
function yume_tsec_emails( callable $rappel ): array {
	$envois = array();
	$filtre = static function ( $retour, $atts ) use ( &$envois ) {
		foreach ( (array) $atts['to'] as $destinataire ) {
			$envois[] = array(
				'to'      => (string) $destinataire,
				'subject' => (string) $atts['subject'],
				'message' => (string) $atts['message'],
			);
		}
		return true;
	};
	add_filter( 'pre_wp_mail', $filtre, 5, 2 );
	try {
		$rappel();
	} finally {
		remove_filter( 'pre_wp_mail', $filtre, 5 );
	}
	return $envois;
}

/**
 * Garantit une page « connexion » enregistrée (formulaires en façade).
 */
function yume_tsec_page_connexion(): void {
	if ( \Yume\Core\Social\page_enregistree( 'connexion' ) ) {
		return;
	}
	$pages              = (array) get_option( 'yume_pages', array() );
	$pages['connexion'] = yume_factory_post(
		array(
			'post_type'  => 'page',
			'post_title' => 'Connexion',
			'post_name'  => 'connexion-' . wp_rand( 1000, 9999 ),
		)
	);
	update_option( 'yume_pages', $pages );
}

/*
 * -----------------------------------------------------------------------------
 * SEC-02 : wp-login.php (inscription, mot de passe oublié)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'SEC-02 : wp-login.php?action=register renvoie vers l’inscription en façade',
	function () {
		yume_tsec_page_connexion();
		yume_assert_true( has_action( 'login_form_register', 'Yume\Core\Social\inscription_wp_login' ) > 0 );
		$url = yume_tsec_redirection( static fn() => do_action( 'login_form_register' ) );
		yume_assert_same( url_connexion() . '#yn-inscription', $url );
		$url = yume_tsec_post(
			array(
				'user_login' => 'spambot1',
				'user_email' => 'spambot1@example.test',
			),
			static fn() => yume_tsec_redirection( static fn() => do_action( 'login_form_register' ) )
		);
		yume_assert_contains( '#yn-inscription', $url, 'POST aussi renvoyé, sans créer de compte' );
		yume_assert_false( get_user_by( 'login', 'spambot1' ) );
	}
);

yume_test(
	'SEC-02 : registration_errors limite à 5 inscriptions par heure et par IP, pot de miel et jeton',
	function () {
		update_option( 'users_can_register', 1 );
		yume_tsec_ip(
			'203.0.113.' . wp_rand( 1, 250 ),
			static function () {
				for ( $i = 1; $i <= 5; $i++ ) {
					$id = register_new_user( 'robotwp' . $i . wp_rand( 100, 999 ), 'robotwp' . $i . wp_rand( 1000, 9999 ) . '@example.test' );
					yume_assert_false( is_wp_error( $id ), 'inscription ' . $i . ' : ' . ( is_wp_error( $id ) ? implode( ',', $id->get_error_codes() ) : '' ) );
				}
				$id = register_new_user( 'robotwp6', 'robotwp6@example.test' );
				yume_assert_true( is_wp_error( $id ) && in_array( 'yume_trop_de_tentatives', $id->get_error_codes(), true ), '6e inscription refusée' );
				yume_assert_false( get_user_by( 'login', 'robotwp6' ) );
				// Le formulaire en façade a déjà compté sa tentative : pas de double comptage.
				traitement_facade( true );
				$erreurs = apply_filters( 'registration_errors', new WP_Error(), 'x', 'x@example.test' );
				traitement_facade( false );
				yume_assert_false( $erreurs->has_errors() );
			}
		);
		yume_tsec_ip(
			'198.51.100.' . wp_rand( 1, 250 ),
			static function () {
				$pot = yume_tsec_post(
					array( 'yn_site_web' => 'http://spam.example' ),
					static fn() => apply_filters( 'registration_errors', new WP_Error(), 'robot7', 'robot7@example.test' )
				);
				yume_assert_same( array( 'yume_inscription_refusee' ), $pot->get_error_codes(), 'pot de miel' );
				$jeton = yume_tsec_post(
					array( 'yn_jeton' => 'faux.jeton' ),
					static fn() => apply_filters( 'registration_errors', new WP_Error(), 'robot8', 'robot8@example.test' )
				);
				yume_assert_same( array( 'yume_inscription_refusee' ), $jeton->get_error_codes(), 'jeton invalide' );
				$propre = apply_filters( 'registration_errors', new WP_Error(), 'robot9', 'robot9@example.test' );
				yume_assert_false( $propre->has_errors(), 'sans champs du formulaire : pas de refus' );
			}
		);
		update_option( 'users_can_register', 0 );
	}
);

yume_test(
	'SEC-02 : mot de passe oublié limité par IP et par compte, réponse identique sur wp-login.php',
	function () {
		$user = get_userdata( yume_factory_user() );
		// Par IP : 5 demandes, puis refus (même pour un identifiant inconnu).
		yume_tsec_ip(
			'192.0.2.' . wp_rand( 1, 250 ),
			static function () {
				for ( $i = 1; $i <= 5; $i++ ) {
					$r = retrieve_password( 'inconnu' . $i . wp_rand( 1000, 9999 ) );
					yume_assert_false( in_array( 'yume_trop_de_tentatives', $r->get_error_codes(), true ), 'demande ' . $i );
				}
				$r = retrieve_password( 'inconnu-final' );
				yume_assert_true( in_array( 'yume_trop_de_tentatives', $r->get_error_codes(), true ), '6e demande de la même IP' );
			}
		);
		// Par compte : 5 demandes depuis 5 IP, la 6e n'envoie rien.
		$envois = yume_tsec_emails(
			static function () use ( $user ) {
				for ( $i = 1; $i <= 5; $i++ ) {
					$r = yume_tsec_ip( '198.18.' . $i . '.' . wp_rand( 1, 250 ), static fn() => retrieve_password( $user->user_login ) );
					yume_assert_true( true === $r, 'demande ' . $i . ' : ' . yume_test_export( $r ) );
				}
				$r = yume_tsec_ip( '198.18.9.' . wp_rand( 1, 250 ), static fn() => retrieve_password( $user->user_email ) );
				yume_assert_true( is_wp_error( $r ) && in_array( 'yume_trop_de_tentatives', $r->get_error_codes(), true ), '6e demande pour ce compte' );
			}
		);
		yume_assert_same( 5, count( $envois ), 'aucun e-mail au-delà de la limite' );

		// wp-login.php : même redirection (« vérifiez vos e-mails ») que le compte existe ou non.
		$autre   = get_userdata( yume_factory_user() );
		$existe  = yume_tsec_ip(
			'192.0.2.' . wp_rand( 1, 250 ),
			static fn() => yume_tsec_post( array( 'user_login' => $autre->user_login ), static fn() => yume_tsec_redirection( static fn() => do_action( 'login_form_lostpassword' ) ) )
		);
		$inconnu = yume_tsec_ip(
			'192.0.2.' . wp_rand( 1, 250 ),
			static fn() => yume_tsec_post( array( 'user_login' => 'personne-' . wp_rand( 1000, 9999 ) ), static fn() => yume_tsec_redirection( static fn() => do_action( 'login_form_lostpassword' ) ) )
		);
		yume_assert_contains( 'checkemail=confirm', $existe );
		yume_assert_same( $existe, $inconnu, 'aucune énumération des comptes' );
		$vide = yume_tsec_post( array( 'user_login' => '' ), static fn() => yume_tsec_redirection( static fn() => do_action( 'login_form_lostpassword' ) ) );
		yume_assert_same( '', $vide, 'champ vide : laissé au cœur' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * SEC-04 : /wp/v2/users et métas des tomes
 * -----------------------------------------------------------------------------
 */

yume_test(
	'SEC-04 : /wp/v2/users réservé aux comptes avec list_users (401 anonyme, 403 lecteur)',
	function () {
		$admin   = yume_factory_user( 'administrator' );
		$gerant  = yume_factory_user( 'yume_gerant' );
		$lecteur = yume_factory_user( 'subscriber' );
		$trad    = yume_factory_user( 'yume_traducteur' );
		yume_factory_post( array( 'post_author' => $admin ) );

		yume_assert_same( 401, yume_rest( 'GET', '/wp/v2/users' )->get_status(), 'anonyme' );
		yume_assert_same( 401, yume_rest( 'GET', '/wp/v2/users/' . $admin )->get_status(), 'anonyme, un compte' );
		yume_assert_same( 401, yume_rest( 'GET', '/wp/v2/users', array( 'context' => 'edit' ) )->get_status() );
		yume_assert_same( 403, yume_rest( 'GET', '/wp/v2/users', array(), $lecteur )->get_status(), 'lecteur' );
		yume_assert_same( 403, yume_rest( 'GET', '/wp/v2/users/' . $admin, array(), $lecteur )->get_status() );
		yume_assert_same( 403, yume_rest( 'GET', '/wp/v2/users', array(), $trad )->get_status(), 'traducteur' );
		yume_assert_same( 200, yume_rest( 'GET', '/wp/v2/users/' . $lecteur, array(), $lecteur )->get_status(), 'son propre compte' );
		yume_assert_same( 200, yume_rest( 'GET', '/wp/v2/users/me', array(), $lecteur )->get_status() );

		$liste = yume_rest( 'GET', '/wp/v2/users', array( 'per_page' => 100 ), $gerant );
		yume_assert_same( 200, $liste->get_status(), 'gérant (list_users)' );
		$ids = wp_list_pluck( $liste->get_data(), 'id' );
		yume_assert_true( in_array( $admin, $ids, true ) );
		yume_assert_true( array_key_exists( 'slug', $liste->get_data()[0] ), 'list_users : identifiant conservé' );
		yume_assert_same( 200, yume_rest( 'GET', '/wp/v2/users', array( 'context' => 'edit' ), $admin )->get_status(), 'éditeur de blocs (context=edit)' );

		// Éditeur (edit_others_posts, sans list_users) : liste des auteurs, sans identifiant.
		$editeur = new WP_User( yume_factory_user( 'yume_editeur' ) );
		$editeur->add_cap( 'edit_others_posts' );
		$auteurs = yume_rest( 'GET', '/wp/v2/users', array( 'context' => 'view' ), $editeur->ID );
		yume_assert_same( 200, $auteurs->get_status(), yume_test_export( $auteurs->get_data() ) );
		yume_assert_true( count( $auteurs->get_data() ) > 0 );
		foreach ( $auteurs->get_data() as $ligne ) {
			if ( (int) $ligne['id'] !== $editeur->ID ) {
				yume_assert_false( array_key_exists( 'slug', $ligne ), 'slug retiré' );
				yume_assert_false( array_key_exists( 'link', $ligne ), 'lien retiré' );
			}
		}
		$moi = yume_rest( 'GET', '/wp/v2/users/me', array(), $lecteur )->get_data();
		yume_assert_true( array_key_exists( 'slug', $moi ), 'son propre compte : identifiant conservé' );
	}
);

yume_test(
	'SEC-04 : yume_responsables et yume_maj_par absents de /wp/v2/tomes hors de l’équipe',
	function () {
		$trad    = yume_factory_user( 'yume_traducteur' );
		$lecteur = yume_factory_user( 'subscriber' );
		add_filter( 'yume_core_notifier', '__return_false', 99 );
		$o = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Sécurité',
			)
		);
		$t = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'post_title' => 'Sécurité — Tome 1',
				'post_name'  => 'tome-1',
				'meta_input' => array(
					'yume_oeuvre_id'    => $o,
					'yume_numero'       => 1,
					'yume_responsables' => array(
						'traduction' => $trad,
						'relecture'  => 0,
						'edition'    => 0,
					),
					'yume_maj_par'      => $trad,
				),
			)
		);
		remove_filter( 'yume_core_notifier', '__return_false', 99 );
		foreach ( array( 0, $lecteur ) as $qui ) {
			$rep = yume_rest( 'GET', '/wp/v2/tomes/' . $t, array(), $qui );
			yume_assert_same( 200, $rep->get_status() );
			$meta = $rep->get_data()['meta'];
			yume_assert_same( 1, (int) $meta['yume_numero'], 'autres métas toujours publiques' );
			foreach ( array( 'yume_responsables', 'yume_maj_par', 'yume_note_equipe' ) as $cle ) {
				yume_assert_false( array_key_exists( $cle, $meta ), $cle . ' masquée (' . $qui . ')' );
			}
			$champs = yume_rest( 'GET', '/wp/v2/tomes/' . $t, array( '_fields' => 'meta.yume_responsables,meta.yume_maj_par' ), $qui );
			yume_assert_not_contains( 'yume_responsables', (string) wp_json_encode( $champs->get_data() ) );
		}
		$equipe = yume_rest( 'GET', '/wp/v2/tomes/' . $t, array(), $trad )->get_data()['meta'];
		yume_assert_same( $trad, $equipe['yume_responsables']['traduction'] );
		yume_assert_same( $trad, $equipe['yume_maj_par'], 'valeur typée par le schéma' );
		yume_assert_same( 0, $equipe['yume_responsables']['relecture'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * SEC-05 : en-têtes et generator
 * -----------------------------------------------------------------------------
 */

yume_test(
	'SEC-05 : en-têtes de sécurité (filtrables), generator retiré',
	function () {
		$entetes = entetes_securite();
		yume_assert_same( 'SAMEORIGIN', $entetes['X-Frame-Options'] ?? '' );
		yume_assert_same( 'nosniff', $entetes['X-Content-Type-Options'] ?? '' );
		yume_assert_same( 'strict-origin-when-cross-origin', $entetes['Referrer-Policy'] ?? '' );
		yume_assert_same( 'camera=(), microphone=(), geolocation=()', $entetes['Permissions-Policy'] ?? '' );
		$csp = $entetes['Content-Security-Policy-Report-Only'] ?? '';
		foreach ( array( "object-src 'none'", "base-uri 'self'" ) as $directive ) {
			yume_assert_contains( $directive, $csp );
		}
		// Seule frame-ancestors est appliquée : le site et l'aperçu du tableau de bord WordPress.com.
		yume_assert_same( "frame-ancestors 'self' https://wordpress.com https://*.wordpress.com", $entetes['Content-Security-Policy'] ?? '' );
		$integration = entetes_securite( true );
		yume_assert_false( isset( $integration['X-Frame-Options'] ), 'pages /embed/ intégrables ailleurs' );
		yume_assert_false( isset( $integration['Content-Security-Policy'] ), 'pages /embed/ : pas de frame-ancestors' );

		$filtre = static function ( $e ) {
			$e['X-Frame-Options'] = '';
			$e['X-Test']          = "a\r\nInjection: 1";
			return $e;
		};
		add_filter( 'yume_entetes_securite', $filtre );
		$filtres = entetes_securite();
		remove_filter( 'yume_entetes_securite', $filtre );
		yume_assert_false( isset( $filtres['X-Frame-Options'] ), 'valeur vide : en-tête retiré' );
		yume_assert_same( 'aInjection: 1', $filtres['X-Test'], 'pas de saut de ligne' );

		foreach ( array( 'send_headers', 'admin_init', 'login_init' ) as $crochet ) {
			yume_assert_true( false !== has_action( $crochet, 'Yume\Core\Core\envoyer_entetes_securite' ), $crochet );
		}
		yume_assert_false( has_action( 'wp_head', 'wp_generator' ), 'pas de generator dans wp_head' );
		yume_assert_same( '', (string) apply_filters( 'the_generator', get_the_generator( 'rss2' ), 'rss2' ), 'ni dans les flux' );
		$serveur                = $_SERVER['SERVER_NAME'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde puis restauration telle quelle.
		$_SERVER['SERVER_NAME'] = 'example.test';
		ob_start();
		wp_head();
		$tete = (string) ob_get_clean();
		if ( null === $serveur ) {
			unset( $_SERVER['SERVER_NAME'] );
		} else {
			$_SERVER['SERVER_NAME'] = $serveur;
		}
		yume_assert_not_contains( 'name="generator"', $tete );
	}
);

/*
 * -----------------------------------------------------------------------------
 * SEC-06 : XML-RPC
 * -----------------------------------------------------------------------------
 */

yume_test(
	'SEC-06 : XML-RPC sans méthodes d’authentification ni pingbacks, Jetpack conservé',
	function () {
		$toutes   = array_fill_keys( array( 'wp.getUsersBlogs', 'wp.getProfile', 'system.multicall', 'pingback.ping', 'pingback.extensions.getPingbacks', 'wp.getPosts', 'demo.sayHello' ), '__return_true' );
		$filtrees = apply_filters( 'xmlrpc_methods', $toutes );
		foreach ( methodes_xmlrpc_retirees() as $methode ) {
			yume_assert_false( isset( $filtrees[ $methode ] ), $methode );
		}
		yume_assert_true( isset( $filtrees['demo.sayHello'] ) );
		yume_assert_false( apply_filters( 'xmlrpc_enabled', true ), 'méthodes authentifiées fermées' );
		$entetes = apply_filters( 'wp_headers', array( 'X-Pingback' => 'http://example.test/xmlrpc.php' ), null );
		yume_assert_false( isset( $entetes['X-Pingback'] ) );

		// Requête signée de Jetpack : rien n'est retiré.
		add_filter( 'yume_requete_jetpack', '__return_true' );
		$jetpack = apply_filters( 'xmlrpc_methods', $toutes );
		$actif   = apply_filters( 'xmlrpc_enabled', true );
		remove_filter( 'yume_requete_jetpack', '__return_true' );
		foreach ( array( 'wp.getUsersBlogs', 'wp.getProfile', 'system.multicall', 'pingback.extensions.getPingbacks' ) as $methode ) {
			// (pingback.ping est retiré par le cœur hors production : wp_maybe_disable_xmlrpc_pingback_for_environment.)
			yume_assert_true( isset( $jetpack[ $methode ] ), 'Jetpack : ' . $methode );
		}
		yume_assert_true( $actif );

		// system.multicall (ajouté par IXR_Server) : repéré dans le corps, même avec des entités.
		require_once ABSPATH . WPINC . '/class-IXR.php';
		$appel = '<?xml version="1.0"?><methodCall><methodName>system&#46;multicall</methodName><params></params></methodCall>';
		yume_assert_same( 'system.multicall', \Yume\Core\Core\methode_xmlrpc_appelee( $appel ) );
		yume_assert_same( '', \Yume\Core\Core\methode_xmlrpc_appelee( 'pas du XML' ) );
		yume_assert_same( 'wp_xmlrpc_server', \Yume\Core\Core\refuser_multicall( 'wp_xmlrpc_server' ), 'autre appel : serveur inchangé' );

		// ?for=jetpack sans Jetpack installé ni signature : non reconnu.
		$_GET['for'] = 'jetpack';
		$faux        = \Yume\Core\Core\requete_jetpack();
		unset( $_GET['for'] );
		yume_assert_false( $faux, 'sans signature' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * SEC-11 : changement d'adresse e-mail confirmé
 * -----------------------------------------------------------------------------
 */

yume_test(
	'SEC-11 : adresse changée → avis à l’ancienne adresse, autres sessions fermées',
	function () {
		$u        = yume_factory_user();
		$ancienne = get_userdata( $u )->user_email;
		$nouvelle = 'change' . wp_rand( 1000, 99999 ) . '@example.test';
		$sessions = WP_Session_Tokens::get_instance( $u );
		$courant  = $sessions->create( time() + HOUR_IN_SECONDS );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$sessions->create( time() + HOUR_IN_SECONDS );
		yume_assert_same( 3, count( $sessions->get_all() ) );

		$cle = 'cle' . wp_rand( 100000, 999999 );
		update_user_meta(
			$u,
			\Yume\Core\Social\META_EMAIL_ATTENTE,
			array(
				'email'  => $nouvelle,
				'cle'    => hash( 'sha256', $cle ),
				'expire' => time() + HOUR_IN_SECONDS,
			)
		);
		wp_set_current_user( $u );
		$cookie_avant                = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $u, time() + HOUR_IN_SECONDS, 'logged_in', $courant );
		$_GET['yn-email']            = $cle;
		$url                         = '';
		$envois                      = yume_tsec_emails(
			static function () use ( &$url ) {
				$url = yume_tsec_redirection( 'Yume\Core\Social\confirmer_email' );
			}
		);
		unset( $_GET['yn-email'] );
		if ( null === $cookie_avant ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie_avant;
		}
		yume_assert_contains( 'email-ok', $url );
		clean_user_cache( $u );
		yume_assert_same( $nouvelle, get_userdata( $u )->user_email );
		yume_assert_same( array( $ancienne ), array_values( array_unique( wp_list_pluck( $envois, 'to' ) ) ), 'un seul avis, à l’ancienne adresse' );
		yume_assert_same( 1, count( $envois ), 'pas de second avis (celui du cœur)' );
		yume_assert_contains( $nouvelle, $envois[0]['message'] );
		yume_assert_contains( 'pas à l’origine', $envois[0]['message'] );
		$restantes = WP_Session_Tokens::get_instance( $u )->get_all();
		yume_assert_same( 1, count( $restantes ), 'seule la session courante reste' );
		yume_assert_true( is_array( WP_Session_Tokens::get_instance( $u )->get( $courant ) ) );

		// Sans session identifiable (confirmation hors navigateur) : toutes fermées.
		$sessions->create( time() + HOUR_IN_SECONDS );
		\Yume\Core\Social\securiser_changement_email( $u, $nouvelle, 'autre@example.test' );
		yume_assert_same( array(), WP_Session_Tokens::get_instance( $u )->get_all() );
		wp_set_current_user( 0 );
	}
);
