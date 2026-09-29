<?php
/**
 * Tests de la connexion en façade sans wp-login.php (includes/social/connexion.php) :
 * formulaire (admin-post yume_connexion), succès lecteur et équipe, échecs (message générique,
 * identifiant pré-rempli, jamais wp-login.php), comptes réservés à la page WordPress, nonce,
 * redirect_to externe, limitation par adresse IP et par compte, wp_login_failed, liens.
 *
 * Lancement : tools/localenv/test.sh connexion
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Social\attente_connexion;
use function Yume\Core\Social\connexion_reservee_wordpress;
use function Yume\Core\Social\url_compte;
use function Yume\Core\Social\url_connexion;
use function Yume\Core\Social\url_connexion_administrateur;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tc_)
 * -----------------------------------------------------------------------------
 */

/** Mot de passe des comptes de ces tests. */
const YUME_TC_MDP = 'Correct-Cheval-Pile-42';

/**
 * Pages compte et connexion enregistrées (option yume_pages).
 *
 * @return array{compte:int,connexion:int}
 */
function yume_tc_pages(): array {
	$pages = array();
	foreach ( array( 'compte', 'connexion' ) as $cle ) {
		$pages[ $cle ] = yume_factory_post(
			array(
				'post_type'    => 'page',
				'post_title'   => ucfirst( $cle ),
				'post_name'    => $cle . '-' . wp_rand( 1, 99999 ),
				'post_content' => '<!-- wp:yume/account /-->',
			)
		);
	}
	update_option( 'yume_pages', $pages );
	return $pages;
}

/**
 * Compte de ce rôle avec le mot de passe connu.
 *
 * @param string $role Rôle.
 */
function yume_tc_compte( string $role ): WP_User {
	$id = yume_factory_user( $role );
	wp_set_password( YUME_TC_MDP, $id );
	return get_userdata( $id );
}

/**
 * Envoie le formulaire (traiter_connexion()) et renvoie la redirection et les cookies de
 * session posés (actions set_auth_cookie / set_logged_in_cookie, envoi réel coupé).
 *
 * @param array $champs Champs (fusionnés avec action, nonce, origine).
 * @param bool  $nonce  Nonce valide.
 * @return array{url:string,cookies:array<int,array{user:int,souvenir:bool}>,connecte:int}
 * @throws RuntimeException Toute exception autre que la redirection interceptée.
 */
function yume_tc_envoyer( array $champs, bool $nonce = true ): array {
	$cookies     = array();
	$note        = static function ( $cookie, $expire, $expiration, $user_id ) use ( &$cookies ) {
		// Session : $expire = 0 ; « Se souvenir de moi » : expiration à 14 jours.
		$cookies[] = array(
			'user'     => (int) $user_id,
			'souvenir' => 0 !== (int) $expire,
		);
	};
	$redirection = static function ( $url ) {
		throw new RuntimeException( (string) $url, 302 );
	};
	$avant       = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$_POST       = wp_slash(
		array_merge(
			array(
				'action'     => 'yume_connexion',
				'_yn_nonce'  => $nonce ? wp_create_nonce( 'yume_connexion' ) : 'invalide',
				'yn_origine' => url_connexion(),
			),
			$champs
		)
	);
	add_filter( 'send_auth_cookies', '__return_false', 99 );
	add_action( 'set_logged_in_cookie', $note, 10, 4 );
	add_filter( 'wp_redirect', $redirection, 1 );
	$url = '';
	try {
		Yume\Core\Social\traiter_connexion();
	} catch ( RuntimeException $e ) {
		if ( 302 !== $e->getCode() ) {
			throw $e;
		}
		$url = $e->getMessage();
	} finally {
		$_POST = $avant;
		remove_filter( 'send_auth_cookies', '__return_false', 99 );
		remove_action( 'set_logged_in_cookie', $note, 10 );
		remove_filter( 'wp_redirect', $redirection, 1 );
	}
	$connecte = get_current_user_id();
	wp_set_current_user( 0 );
	return array(
		'url'      => $url,
		'cookies'  => $cookies,
		'connecte' => $connecte,
	);
}

/**
 * Adresse IP de test le temps de $rappel.
 *
 * @param string   $ip     Adresse.
 * @param callable $rappel Fonction.
 * @return mixed
 */
function yume_tc_ip( string $ip, callable $rappel ) {
	$avant                  = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde puis restauration telle quelle.
	$_SERVER['REMOTE_ADDR'] = $ip;
	try {
		return $rappel();
	} finally {
		$_SERVER['REMOTE_ADDR'] = $avant;
	}
}

/**
 * Rendu du bloc yume/account pour un visiteur sur la page de connexion (adresse donnée).
 *
 * @param string $requete Chemin et requête (REQUEST_URI).
 * @param array  $get     Paramètres GET.
 */
function yume_tc_rendu( string $requete, array $get = array() ): string {
	$serveur                = $_SERVER;
	$get_avant              = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
	$_SERVER['REQUEST_URI'] = $requete;
	$_GET                   = $get;
	wp_set_current_user( 0 );
	try {
		return yume_render_block( 'yume/account' );
	} finally {
		$_SERVER = $serveur; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_GET    = $get_avant;
	}
}

/*
 * -----------------------------------------------------------------------------
 * Tests
 * -----------------------------------------------------------------------------
 */

yume_test(
	'connexion : formulaire propre vers admin-post (nonce, champs étiquetés, autocomplete), lien administrateur',
	function () {
		yume_tc_pages();
		$html = yume_tc_rendu( '/connexion/' );
		yume_assert_contains( 'id="yn-connexion"', $html );
		yume_assert_contains( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		yume_assert_contains( 'name="action" value="yume_connexion"', $html );
		yume_assert_contains( 'name="_yn_nonce"', $html );
		yume_assert_contains( '<label for="yn-identifiant">Pseudo ou adresse e-mail</label>', $html );
		yume_assert_contains( '<label for="yn-mot-de-passe">Mot de passe</label>', $html );
		yume_assert_contains( '<label for="yn-se-souvenir">Se souvenir de moi</label>', $html );
		yume_assert_contains( 'autocomplete="username"', $html );
		yume_assert_contains( 'autocomplete="current-password"', $html );
		yume_assert_not_contains( 'loginform', $html, 'plus de wp_login_form()' );
		yume_assert_not_contains( 'role="alert"', $html, 'aucun message sans échec' );
		// Seul le petit lien « Connexion administrateur » mène à wp-login.php.
		yume_assert_same( 1, substr_count( $html, 'wp-login.php' ) );
		yume_assert_contains( 'Connexion administrateur', $html );
		yume_assert_contains( 'name="yn_origine"', $html );

		$demande = home_url( '/oeuvre/exemple/' );
		$avec    = yume_tc_rendu( '/connexion/?redirect_to=' . rawurlencode( $demande ), array( 'redirect_to' => $demande ) );
		yume_assert_contains( 'name="redirect_to" value="' . esc_url( $demande ) . '"', $avec );
		$externe = yume_tc_rendu( '/connexion/', array( 'redirect_to' => 'https://ailleurs.example/piege' ) );
		yume_assert_not_contains( 'ailleurs.example', $externe, 'redirect_to externe ignoré' );
	}
);

yume_test(
	'connexion : lecteur et membre de l’équipe connectés (cookie, wp_login), redirection',
	function () {
		yume_tc_pages();
		$lecteur = yume_tc_compte( 'subscriber' );
		$page    = home_url( '/oeuvre/exemple/' );
		$logins  = array();
		$suivi   = static function ( $login ) use ( &$logins ) {
			$logins[] = (string) $login;
		};
		add_action( 'wp_login', $suivi );
		$r = yume_tc_ip(
			'198.51.100.10',
			static fn() => yume_tc_envoyer(
				array(
					'yn_identifiant'  => $lecteur->user_login,
					'yn_mot_de_passe' => YUME_TC_MDP,
					'yn_se_souvenir'  => '1',
					'redirect_to'     => $page,
				)
			)
		);
		yume_assert_same( $page, $r['url'], 'page d’origine' );
		yume_assert_same( $lecteur->ID, $r['connecte'] );
		yume_assert_same(
			array(
				array(
					'user'     => $lecteur->ID,
					'souvenir' => true,
				),
			),
			$r['cookies'],
			'cookie posé, « se souvenir »'
		);

		// Par l'adresse e-mail, sans redirect_to : Mon compte ; cookie de session.
		$r = yume_tc_ip(
			'198.51.100.10',
			static fn() => yume_tc_envoyer(
				array(
					'yn_identifiant'  => $lecteur->user_email,
					'yn_mot_de_passe' => YUME_TC_MDP,
				)
			)
		);
		yume_assert_same( url_compte(), $r['url'] );
		yume_assert_same(
			array(
				array(
					'user'     => $lecteur->ID,
					'souvenir' => false,
				),
			),
			$r['cookies']
		);

		// Traducteur et éditeur : connectés par la façade (équipe).
		foreach ( array( 'yume_traducteur', 'yume_editeur' ) as $role ) {
			$membre = yume_tc_compte( $role );
			$r      = yume_tc_ip(
				'198.51.100.11',
				static fn() => yume_tc_envoyer(
					array(
						'yn_identifiant'  => $membre->user_login,
						'yn_mot_de_passe' => YUME_TC_MDP,
						'redirect_to'     => $page,
					)
				)
			);
			yume_assert_same( $page, $r['url'], $role );
			yume_assert_same( $membre->ID, $r['connecte'], $role );
			yume_assert_same( 1, count( $r['cookies'] ), $role );
			yume_assert_false( connexion_reservee_wordpress( $membre ), $role );
		}
		remove_action( 'wp_login', $suivi );
		yume_assert_same( 4, count( $logins ), 'action wp_login à chaque connexion' );
	}
);

yume_test(
	'connexion : échec → page de connexion, message générique, identifiant pré-rempli, jamais wp-login.php',
	function () {
		$pages   = yume_tc_pages();
		$lecteur = yume_tc_compte( 'subscriber' );
		$echecs  = array();
		$suivi   = static function ( $login ) use ( &$echecs ) {
			$echecs[] = (string) $login;
		};
		add_action( 'wp_login_failed', $suivi );
		foreach ( array( $lecteur->user_login, 'inconnu-' . wp_rand( 1000, 9999 ) ) as $identifiant ) {
			$r = yume_tc_ip(
				'198.51.100.20',
				static fn() => yume_tc_envoyer(
					array(
						'yn_identifiant'  => $identifiant,
						'yn_mot_de_passe' => 'faux',
						'yn_origine'      => url_connexion( home_url( '/oeuvre/x/' ) ),
					)
				)
			);
			yume_assert_contains( (string) get_permalink( $pages['connexion'] ), $r['url'] );
			yume_assert_contains( 'yn-msg=connexion-echec', $r['url'] );
			yume_assert_contains( 'yn-identifiant=' . rawurlencode( $identifiant ), $r['url'] );
			yume_assert_contains( 'redirect_to=', $r['url'], 'page demandée conservée' );
			yume_assert_contains( '#yn-connexion-message', $r['url'], 'ancre sur le message' );
			yume_assert_not_contains( 'wp-login.php', $r['url'] );
			yume_assert_not_contains( 'faux', $r['url'], 'jamais le mot de passe' );
			yume_assert_same( 0, $r['connecte'] );
			yume_assert_same( array(), $r['cookies'] );
		}
		remove_action( 'wp_login_failed', $suivi );
		yume_assert_same( 2, count( $echecs ), 'wp_login_failed à chaque échec' );

		// Origine piégée (wp-login.php, administration, hôte externe) : page de connexion.
		foreach ( array( site_url( 'wp-login.php' ), admin_url( 'profile.php' ), 'https://ailleurs.example/' ) as $origine ) {
			$r = yume_tc_ip(
				'198.51.100.21',
				static fn() => yume_tc_envoyer(
					array(
						'yn_identifiant'  => 'x',
						'yn_mot_de_passe' => 'faux',
						'yn_origine'      => $origine,
					)
				)
			);
			yume_assert_contains( (string) get_permalink( $pages['connexion'] ), $r['url'], $origine );
			yume_assert_not_contains( 'wp-login.php', $r['url'], $origine );
		}

		// Rendu du retour : message role="alert" focalisé, identifiant pré-rempli, pas de mot de passe.
		$html = yume_tc_rendu(
			'/connexion/?yn-msg=connexion-echec&yn-identifiant=kaede',
			array(
				'yn-msg'         => 'connexion-echec',
				'yn-identifiant' => 'kaede',
			)
		);
		yume_assert_contains( 'role="alert"', $html );
		yume_assert_contains( 'data-yn-focus', $html );
		yume_assert_contains( 'Identifiant ou mot de passe incorrect.', $html );
		yume_assert_same( 1, substr_count( $html, 'Identifiant ou mot de passe incorrect.' ), 'affiché une seule fois' );
		yume_assert_contains( 'name="yn_identifiant" value="kaede"', $html );
		yume_assert_contains( 'aria-describedby="yn-connexion-message"', $html );
		yume_assert_not_contains( 'yn-identifiant=kaede', $html, 'identifiant absent des adresses de retour' );
	}
);

yume_test(
	'connexion : administrateurs refusés même avec le bon mot de passe ; mauvais mot de passe → message générique',
	function () {
		$pages = yume_tc_pages();
		foreach ( array( 'administrator' ) as $role ) {
			$compte = yume_tc_compte( $role );
			yume_assert_true( connexion_reservee_wordpress( $compte ), $role );
			$r = yume_tc_ip(
				'198.51.100.30',
				static fn() => yume_tc_envoyer(
					array(
						'yn_identifiant'  => $compte->user_login,
						'yn_mot_de_passe' => YUME_TC_MDP,
					)
				)
			);
			yume_assert_contains( 'yn-msg=connexion-reservee', $r['url'], $role );
			yume_assert_contains( (string) get_permalink( $pages['connexion'] ), $r['url'], $role );
			yume_assert_same( 0, $r['connecte'], $role . ' : pas de session' );
			yume_assert_same( array(), $r['cookies'], $role . ' : pas de cookie' );

			$faux = yume_tc_ip(
				'198.51.100.31',
				static fn() => yume_tc_envoyer(
					array(
						'yn_identifiant'  => $compte->user_login,
						'yn_mot_de_passe' => 'faux',
					)
				)
			);
			yume_assert_contains( 'yn-msg=connexion-echec', $faux['url'], $role . ' : même message qu’un lecteur' );
			yume_assert_not_contains( 'reservee', $faux['url'], $role );
		}

		// Gérant : connecté par la façade (décision du propriétaire) ; filtre pour le réserver.
		$gerant = yume_tc_compte( 'yume_gerant' );
		yume_assert_false( connexion_reservee_wordpress( $gerant ) );
		$r = yume_tc_ip(
			'198.51.100.32',
			static fn() => yume_tc_envoyer(
				array(
					'yn_identifiant'  => $gerant->user_login,
					'yn_mot_de_passe' => YUME_TC_MDP,
				)
			)
		);
		yume_assert_same( $gerant->ID, $r['connecte'], 'gérant connecté' );
		add_filter( 'yume_connexion_reservee_wordpress', '__return_true' );
		yume_assert_true( connexion_reservee_wordpress( $gerant ) );
		remove_filter( 'yume_connexion_reservee_wordpress', '__return_true' );

		$html = yume_tc_rendu( '/connexion/?yn-msg=connexion-reservee', array( 'yn-msg' => 'connexion-reservee' ) );
		yume_assert_contains( 'Les administrateurs se connectent par la page de connexion WordPress.', $html );
		yume_assert_contains( 'role="alert"', $html );
		yume_assert_contains( esc_url( url_connexion_administrateur() ), $html );
		yume_assert_contains( 'wp-login.php', url_connexion_administrateur() );
	}
);

yume_test(
	'connexion : nonce invalide et redirect_to externe refusés',
	function () {
		yume_tc_pages();
		$lecteur = yume_tc_compte( 'subscriber' );
		$r       = yume_tc_ip(
			'198.51.100.40',
			static fn() => yume_tc_envoyer(
				array(
					'yn_identifiant'  => $lecteur->user_login,
					'yn_mot_de_passe' => YUME_TC_MDP,
				),
				false
			)
		);
		yume_assert_contains( 'yn-msg=session', $r['url'] );
		yume_assert_same( 0, $r['connecte'] );
		yume_assert_same( array(), $r['cookies'] );
		yume_assert_not_contains( 'wp-login.php', $r['url'] );

		$r = yume_tc_ip(
			'198.51.100.40',
			static fn() => yume_tc_envoyer(
				array(
					'yn_identifiant'  => $lecteur->user_login,
					'yn_mot_de_passe' => YUME_TC_MDP,
					'redirect_to'     => 'https://ailleurs.example/piege',
				)
			)
		);
		yume_assert_same( url_compte(), $r['url'], 'hôte externe : Mon compte' );
		yume_assert_same( $lecteur->ID, $r['connecte'] );
	}
);

yume_test(
	'connexion : blocage après 5 échecs (adresse IP, compte), remise à zéro au succès, seuils filtrables',
	function () {
		yume_tc_pages();
		$lecteur = yume_tc_compte( 'subscriber' );
		$autre   = yume_tc_compte( 'subscriber' );
		$faux    = static fn( string $login ) => yume_tc_envoyer(
			array(
				'yn_identifiant'  => $login,
				'yn_mot_de_passe' => 'faux',
			)
		);
		$bon     = static fn( WP_User $u ) => yume_tc_envoyer(
			array(
				'yn_identifiant'  => $u->user_login,
				'yn_mot_de_passe' => YUME_TC_MDP,
			)
		);

		// Par compte : 5 échecs depuis 5 adresses différentes bloquent ce compte.
		for ( $i = 1; $i <= 4; $i++ ) {
			$r = yume_tc_ip( '203.0.113.' . $i, static fn() => $faux( $lecteur->user_login ) );
			yume_assert_contains( 'connexion-echec', $r['url'], 'échec ' . $i );
		}
		$r = yume_tc_ip( '203.0.113.5', static fn() => $faux( $lecteur->user_email ) );
		yume_assert_contains( 'yn-msg=connexion-bloquee', $r['url'], 'cinquième échec (par l’e-mail : même compte)' );
		yume_assert_contains( 'yn-minutes=15', $r['url'] );
		$echecs = 0;
		$suivi  = static function () use ( &$echecs ) {
			++$echecs;
		};
		add_action( 'wp_login_failed', $suivi );
		$r = yume_tc_ip( '203.0.113.6', static fn() => $bon( $lecteur ) );
		remove_action( 'wp_login_failed', $suivi );
		yume_assert_contains( 'connexion-bloquee', $r['url'], 'bon mot de passe refusé pendant le blocage' );
		yume_assert_same( 0, $r['connecte'] );
		yume_assert_same( 1, $echecs, 'wp_login_failed aussi pendant le blocage' );
		yume_assert_true( yume_tc_ip( '203.0.113.99', static fn() => attente_connexion( $lecteur->user_login ) ) > 0 );
		// Un autre compte, d'une autre adresse, n'est pas touché.
		$r = yume_tc_ip( '203.0.113.7', static fn() => $bon( $autre ) );
		yume_assert_same( $autre->ID, $r['connecte'] );

		// Par adresse IP : 5 échecs sur des comptes différents bloquent l'adresse.
		for ( $i = 1; $i <= 5; $i++ ) {
			$r = yume_tc_ip( '192.0.2.50', static fn() => $faux( 'inconnu' . $i ) );
		}
		yume_assert_contains( 'connexion-bloquee', $r['url'] );
		$r = yume_tc_ip( '192.0.2.50', static fn() => $bon( $autre ) );
		yume_assert_contains( 'connexion-bloquee', $r['url'], 'adresse bloquée pour tous les comptes' );
		yume_assert_same( 0, $r['connecte'] );

		// Remise à zéro au succès : 4 échecs, succès, puis 4 nouveaux échecs sans blocage.
		$troisieme = yume_tc_compte( 'subscriber' );
		for ( $i = 1; $i <= 4; $i++ ) {
			yume_tc_ip( '192.0.2.60', static fn() => $faux( $troisieme->user_login ) );
		}
		$r = yume_tc_ip( '192.0.2.60', static fn() => $bon( $troisieme ) );
		yume_assert_same( $troisieme->ID, $r['connecte'] );
		for ( $i = 1; $i <= 4; $i++ ) {
			$r = yume_tc_ip( '192.0.2.60', static fn() => $faux( $troisieme->user_login ) );
			yume_assert_contains( 'connexion-echec', $r['url'], 'compteurs remis à zéro, échec ' . $i );
		}

		// Seuils filtrables.
		$seuils = static fn() => array(
			'tentatives' => 2,
			'periode'    => 600,
			'blocage'    => 120,
		);
		add_filter( 'yume_connexion_limites', $seuils );
		yume_tc_ip( '192.0.2.70', static fn() => $faux( 'filtre' ) );
		$r = yume_tc_ip( '192.0.2.70', static fn() => $faux( 'filtre' ) );
		remove_filter( 'yume_connexion_limites', $seuils );
		yume_assert_contains( 'yn-minutes=2', $r['url'] );

		$html = yume_tc_rendu(
			'/connexion/?yn-msg=connexion-bloquee&yn-minutes=12',
			array(
				'yn-msg'     => 'connexion-bloquee',
				'yn-minutes' => '12',
			)
		);
		yume_assert_contains( 'Trop de tentatives, réessayez dans 12 minutes.', $html );
	}
);

yume_test(
	'connexion : liens « Se connecter » du site vers /connexion/, wp-login.php dans l’administration',
	function () {
		$pages   = yume_tc_pages();
		$retour  = home_url( '/oeuvre/exemple/' );
		$facade  = wp_login_url( $retour );
		$attendu = (string) get_permalink( $pages['connexion'] );
		yume_assert_contains( $attendu, $facade );
		yume_assert_contains( 'redirect_to=', $facade );
		yume_assert_not_contains( 'wp-login.php', $facade );
		yume_assert_contains( 'wp-login.php', url_connexion_administrateur( $retour ) );
		yume_assert_contains( $attendu, wp_login_url(), 'lien suivant : de nouveau la façade' );

		// Administration (auth_redirect()) : page de connexion WordPress.
		set_current_screen( 'dashboard' );
		$pagenow            = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['pagenow'] = 'index.php';
		yume_assert_contains( 'wp-login.php', wp_login_url( admin_url() ) );
		// admin-post.php (formulaires envoyés sans session) : la façade.
		$GLOBALS['pagenow'] = 'admin-post.php';
		yume_assert_contains( $attendu, wp_login_url( $retour ) );
		$GLOBALS['pagenow']        = $pagenow;
		$GLOBALS['current_screen'] = null;

		// Sans page Yume : wp-login.php inchangé.
		update_option( 'yume_pages', array() );
		yume_assert_contains( 'wp-login.php', wp_login_url( $retour ) );
	}
);
