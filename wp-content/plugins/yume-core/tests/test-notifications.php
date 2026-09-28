<?php
/**
 * Tests du centre de notifications du lecteur (AMEL-11) et des notifications navigateur
 * (Web Push, AMEL-06), lot P3-D : création sur les événements de sortie et de réponse,
 * lecture et marquage REST, purge, cloche de l'en-tête, jeton VAPID ES256, hôtes autorisés,
 * envoi par lots en tâche cron, authentification du service worker, service worker.
 *
 * Lancement : tools/localenv/test.sh notifications
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Social\abonnements_push;
use function Yume\Core\Social\ajouter_favori;
use function Yume\Core\Social\authentifier_push;
use function Yume\Core\Social\base64url_decoder;
use function Yume\Core\Social\cle_publique_vapid;
use function Yume\Core\Social\creer_notifications;
use function Yume\Core\Social\definir_frequence;
use function Yume\Core\Social\donnees_personnelles;
use function Yume\Core\Social\effacer_donnees;
use function Yume\Core\Social\endpoint_autorise;
use function Yume\Core\Social\enregistrer_abonnement;
use function Yume\Core\Social\envoyer_lot_push;
use function Yume\Core\Social\jeton_vapid;
use function Yume\Core\Social\nb_non_lues;
use function Yume\Core\Social\notifications_utilisateur;
use function Yume\Core\Social\purger_notifications;
use function Yume\Core\Social\section_notifications;
use function Yume\Core\Social\session_push_valide;
use function Yume\Core\Social\signature_brut_vers_der;
use function Yume\Core\Social\table_notifications_lecteur;
use function Yume\Core\Social\table_push;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tnot_)
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre publiée avec un tome et des chapitres publiés (sans événement de sortie).
 *
 * @param int $nb Nombre de chapitres.
 * @return array{oeuvre:int,tome:int,chapitres:int[]}
 */
function yume_tnot_oeuvre( int $nb = 2 ): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	$oeuvre = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Œuvre ' . wp_rand( 1, 999999 ),
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'  => 'yume_tome',
			'post_title' => 'Tome 4',
			'post_name'  => 'tome-4',
			'meta_input' => array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => 4,
				'yume_nature'    => 'tome',
			),
		)
	);
	$ids    = array();
	for ( $i = 1; $i <= $nb; $i++ ) {
		$ids[] = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => 'Chapitre ' . $i,
				'post_name'    => 'chapitre-' . $i,
				'menu_order'   => $i,
				'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id' => $tome,
					'yume_numero'  => $i,
					'yume_nature'  => 'chapitre',
				),
			)
		);
	}
	remove_filter( 'yume_core_notifier', '__return_false' );
	return array(
		'oeuvre'    => $oeuvre,
		'tome'      => $tome,
		'chapitres' => $ids,
	);
}

/**
 * Ouvre une session WordPress (méta session_tokens) pour le membre.
 *
 * @param int $user_id Membre.
 * @return string Jeton de session (en clair).
 */
function yume_tnot_session( int $user_id ): string {
	return WP_Session_Tokens::get_instance( $user_id )->create( time() + HOUR_IN_SECONDS );
}

/**
 * Abonnement push valide (clés factices de la bonne longueur), lié à une session.
 *
 * @param int         $user_id Membre.
 * @param string      $hote    Hôte du service push.
 * @param string|null $jeton   Jeton de session (null : nouvelle session).
 * @return array{endpoint:string,p256dh:string,auth:string,id:int,jeton:string}
 * @throws Yume_Test_Failure Abonnement refusé.
 */
function yume_tnot_abonnement( int $user_id, string $hote = 'fcm.googleapis.com', ?string $jeton = null ): array {
	$jeton    = $jeton ?? yume_tnot_session( $user_id );
	$endpoint = 'https://' . $hote . '/fcm/send/' . wp_generate_password( 24, false );
	$p256dh   = rtrim( strtr( base64_encode( "\x04" . random_bytes( 64 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	$auth     = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	$id       = enregistrer_abonnement( $user_id, $endpoint, $p256dh, $auth, hash( 'sha256', $jeton ) );
	if ( is_wp_error( $id ) ) {
		throw new Yume_Test_Failure( 'abonnement : ' . $id->get_error_message() );
	}
	return array(
		'endpoint' => $endpoint,
		'p256dh'   => $p256dh,
		'auth'     => $auth,
		'id'       => (int) $id,
		'jeton'    => $jeton,
	);
}

/**
 * Le service worker de l'abonnement s'authentifie-t-il (GET /moi/notifications) ?
 *
 * @param array $abo Abonnement (yume_tnot_abonnement()).
 * @return int Membre authentifié (0 : refusé).
 */
function yume_tnot_authentifie( array $abo ): int {
	$avant_route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
	$sauve       = $_SERVER;
	try {
		$_SERVER['REQUEST_METHOD']               = 'GET';
		$_SERVER['HTTP_X_YUME_PUSH_ENDPOINT']    = $abo['endpoint'];
		$_SERVER['HTTP_X_YUME_PUSH_AUTH']        = $abo['auth'];
		$GLOBALS['wp']->query_vars['rest_route'] = '/yume/v1/moi/notifications';
		wp_set_current_user( 0 );
		return true === authentifier_push( null ) ? get_current_user_id() : 0;
	} finally {
		$_SERVER                                 = $sauve; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$GLOBALS['wp']->query_vars['rest_route'] = $avant_route;
		wp_set_current_user( 0 );
	}
}

/**
 * Exécute $rappel avec le cookie de connexion d'une session (wp_get_session_token()).
 *
 * @param int      $user_id Membre.
 * @param string   $jeton   Jeton de session.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_tnot_avec_cookie( int $user_id, string $jeton, callable $rappel ) {
	$avant                       = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegardé puis restauré tel quel.
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in', $jeton );
	try {
		return $rappel();
	} finally {
		if ( null === $avant ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $avant;
		}
	}
}

/**
 * Exécute $rappel en interceptant les requêtes HTTP sortantes (réponse $code) et renvoie les
 * requêtes capturées.
 *
 * @param callable     $rappel Fonction.
 * @param int|callable $code   Code HTTP renvoyé (ou fonction url => code).
 * @return array<int,array{url:string,args:array}>
 */
function yume_tnot_http( callable $rappel, $code = 201 ): array {
	$captures = array();
	$filtre   = static function ( $pre, $args, $url ) use ( &$captures, $code ) {
		$captures[] = array(
			'url'  => (string) $url,
			'args' => (array) $args,
		);
		$statut     = is_callable( $code ) ? (int) $code( $url ) : (int) $code;
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $statut,
				'message' => 'Test',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $filtre, 1, 3 );
	try {
		$rappel();
	} finally {
		remove_filter( 'pre_http_request', $filtre, 1 );
	}
	return $captures;
}

/**
 * Vieillit toutes les notifications de la table de $secondes.
 *
 * @param int $secondes Secondes.
 */
function yume_tnot_vieillir( int $secondes ): void {
	global $wpdb;
	$table = table_notifications_lecteur();
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET cree_le = %s", gmdate( 'Y-m-d H:i:s', time() - $secondes ) ) ); // phpcs:ignore WordPress.DB
}

/*
 * -----------------------------------------------------------------------------
 * Centre de notifications
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-11 : sortie d’un chapitre → notification aux membres qui suivent l’œuvre (hors « jamais »), une seule fois',
	function () {
		$s        = yume_tnot_oeuvre( 2 );
		$immediat = yume_factory_user();
		$hebdo    = yume_factory_user();
		$jamais   = yume_factory_user();
		$autre    = yume_factory_user();
		ajouter_favori( $immediat, $s['oeuvre'] );
		ajouter_favori( $hebdo, $s['oeuvre'], 'hebdo' );
		ajouter_favori( $jamais, $s['oeuvre'] );
		definir_frequence( $jamais, $s['oeuvre'], 'jamais' );
		do_action( 'yume_chapitre_publie', $s['chapitres'][1] );
		do_action( 'yume_chapitre_publie', $s['chapitres'][1] );
		yume_assert_same( 1, nb_non_lues( $immediat ) );
		yume_assert_same( 1, nb_non_lues( $hebdo ) );
		yume_assert_same( 0, nb_non_lues( $jamais ) );
		yume_assert_same( 0, nb_non_lues( $autre ) );
		$n = notifications_utilisateur( $immediat )['notifications'][0];
		yume_assert_same( 'sortie', $n['type'] );
		yume_assert_same( $s['chapitres'][1], $n['objet_id'] );
		yume_assert_contains( 'Nouveau chapitre de', $n['titre'] );
		yume_assert_same( get_permalink( $s['chapitres'][1] ), $n['url'] );
		yume_assert_false( $n['lu'] );
	}
);

yume_test(
	'AMEL-11 : sortie d’un tome (lien vers le premier chapitre) ; dépublication → notification retirée',
	function () {
		$s = yume_tnot_oeuvre( 2 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		do_action( 'yume_tome_publie', $s['tome'] );
		$n = notifications_utilisateur( $u )['notifications'];
		yume_assert_same( 1, count( $n ) );
		yume_assert_contains( 'est disponible', $n[0]['titre'] );
		yume_assert_same( get_permalink( $s['chapitres'][0] ), $n[0]['url'] );
		wp_update_post(
			array(
				'ID'          => $s['tome'],
				'post_status' => 'draft',
			)
		);
		yume_assert_same( 0, notifications_utilisateur( $u )['total'] );
	}
);

yume_test(
	'AMEL-11 : réponse approuvée à un commentaire → notification à l’auteur du parent (directe ou après modération)',
	function () {
		$s      = yume_tnot_oeuvre( 1 );
		$auteur = yume_factory_user();
		$autre  = yume_factory_user();
		$parent = wp_insert_comment(
			array(
				'comment_post_ID'  => $s['chapitres'][0],
				'user_id'          => $auteur,
				'comment_content'  => 'Question ?',
				'comment_approved' => 1,
			)
		);
		// Réponse directement approuvée.
		$direct = wp_insert_comment(
			array(
				'comment_post_ID'  => $s['chapitres'][0],
				'comment_parent'   => $parent,
				'user_id'          => $autre,
				'comment_author'   => 'Kazuma',
				'comment_content'  => 'Réponse',
				'comment_approved' => 1,
			)
		);
		$n      = notifications_utilisateur( $auteur )['notifications'];
		yume_assert_same( 1, count( $n ) );
		yume_assert_same( 'reponse', $n[0]['type'] );
		yume_assert_same( $direct, $n[0]['objet_id'] );
		yume_assert_contains( 'Kazuma a répondu à votre commentaire', $n[0]['titre'] );
		// En attente puis approuvée : une seule notification, à l'approbation.
		$attente = wp_insert_comment(
			array(
				'comment_post_ID'  => $s['chapitres'][0],
				'comment_parent'   => $parent,
				'user_id'          => $autre,
				'comment_content'  => 'Autre réponse',
				'comment_approved' => 0,
			)
		);
		yume_assert_same( 1, nb_non_lues( $auteur ) );
		wp_set_comment_status( $attente, 'approve' );
		wp_set_comment_status( $attente, 'hold' );
		wp_set_comment_status( $attente, 'approve' );
		yume_assert_same( 2, nb_non_lues( $auteur ) );
		// Se répondre à soi-même : rien.
		wp_insert_comment(
			array(
				'comment_post_ID'  => $s['chapitres'][0],
				'comment_parent'   => $parent,
				'user_id'          => $auteur,
				'comment_content'  => 'Précision',
				'comment_approved' => 1,
			)
		);
		yume_assert_same( 2, nb_non_lues( $auteur ) );
		yume_assert_same( 0, nb_non_lues( $autre ) );
	}
);

yume_test(
	'AMEL-11 : REST — liste paginée, non_lues, marquer lues (ids du membre seulement, ou toutes)',
	function () {
		$a = yume_factory_user();
		$b = yume_factory_user();
		for ( $i = 1; $i <= 5; $i++ ) {
			creer_notifications( array( $a ), 'sortie', 1000 + $i, 'Sortie ' . $i, home_url( '/lire/' . $i . '/' ) );
		}
		creer_notifications( array( $b ), 'sortie', 2000, 'Pour B', home_url( '/' ) );
		$r = yume_rest(
			'GET',
			'/yume/v1/moi/notifications',
			array(
				'limite' => 2,
				'page'   => 2,
			),
			$a
		);
		yume_assert_same( 200, $r->get_status() );
		$d = $r->get_data();
		yume_assert_same( 5, $d['total'] );
		yume_assert_same( 3, $d['pages'] );
		yume_assert_same( 5, $d['non_lues'] );
		yume_assert_same( 2, count( $d['notifications'] ) );
		yume_assert_same( '5', $r->get_headers()['X-WP-Total'] );
		yume_assert_contains( 'no-store', $r->get_headers()['Cache-Control'] );

		$ids   = wp_list_pluck( notifications_utilisateur( $a )['notifications'], 'id' );
		$autre = notifications_utilisateur( $b )['notifications'][0]['id'];
		$r     = yume_rest( 'POST', '/yume/v1/moi/notifications/lues', array( 'ids' => array( $ids[0], $autre ) ), $a );
		yume_assert_same( 1, $r->get_data()['marquees'] );
		yume_assert_same( 4, $r->get_data()['non_lues'] );
		yume_assert_same( 1, nb_non_lues( $b ), 'la notification de B reste non lue' );

		$r = yume_rest(
			'GET',
			'/yume/v1/moi/notifications',
			array(
				'non_lues' => true,
				'limite'   => 1,
			),
			$a
		);
		yume_assert_same( 4, $r->get_data()['total'] );
		yume_assert_same( 1, count( $r->get_data()['notifications'] ) );

		yume_rest( 'POST', '/yume/v1/moi/notifications/lues', array(), $a );
		yume_assert_same( 0, nb_non_lues( $a ) );
		yume_assert_same( 401, yume_rest( 'GET', '/yume/v1/moi/notifications' )->get_status() );
	}
);

yume_test(
	'AMEL-11 : purge des notifications de plus de 90 jours (tâche quotidienne)',
	function () {
		$u = yume_factory_user();
		creer_notifications( array( $u ), 'sortie', 1, 'Ancienne', home_url( '/' ) );
		yume_tnot_vieillir( 91 * DAY_IN_SECONDS );
		creer_notifications( array( $u ), 'sortie', 2, 'Récente', home_url( '/' ) );
		yume_assert_same( 1, purger_notifications() );
		yume_assert_same( array( 'Récente' ), wp_list_pluck( notifications_utilisateur( $u )['notifications'], 'titre' ) );
		yume_assert_true( false !== wp_next_scheduled( 'yume_notifications_lecteur_purge' ), 'tâche quotidienne planifiée' );
	}
);

yume_test(
	'AMEL-11 : cloche dans yume/auth-links pour un membre (pastille, panneau), rien pour un visiteur',
	function () {
		wp_dequeue_script( 'yume-cloche' );
		$visiteur = yume_render_block( 'yume/auth-links' );
		yume_assert_not_contains( 'yn-cloche', $visiteur );
		yume_assert_false( wp_script_is( 'yume-cloche', 'enqueued' ), 'aucun script pour un visiteur' );

		$u = yume_factory_user();
		creer_notifications( array( $u ), 'sortie', 1, 'Tome 9 de Grimgar est disponible', home_url( '/' ) );
		creer_notifications( array( $u ), 'sortie', 2, 'Nouveau chapitre', home_url( '/' ) );
		wp_set_current_user( $u );
		$html = yume_render_block( 'yume/auth-links' );
		wp_set_current_user( 0 );
		yume_assert_contains( 'data-yn-cloche=', $html );
		yume_assert_contains( 'Notifications : 2 non lues', $html );
		yume_assert_contains( 'aria-controls="yn-cloche-panneau"', $html );
		yume_assert_contains( 'aria-expanded="false"', $html );
		yume_assert_contains( 'Tout marquer comme lu', $html );
		yume_assert_contains( 'Tome 9 de Grimgar est disponible', $html );
		yume_assert_contains( '#yn-notifications', $html );
		yume_assert_contains( 'Mon compte', $html );
		yume_assert_true( strpos( $html, 'yn-cloche' ) < strpos( $html, 'Mon compte' ), 'cloche avant « Mon compte »' );
		yume_assert_true( wp_script_is( 'yume-cloche', 'enqueued' ) );
		wp_dequeue_script( 'yume-cloche' );
	}
);

yume_test(
	'AMEL-11 : rubrique « Notifications » du compte ; notifications dans l’export RGPD et effacées',
	function () {
		$u = yume_factory_user();
		creer_notifications( array( $u ), 'reponse', 7, 'Kazuma a répondu à votre commentaire', home_url( '/' ) );
		yume_tnot_abonnement( $u );
		wp_set_current_user( $u );
		$html = section_notifications( $u );
		wp_set_current_user( 0 );
		yume_assert_contains( 'id="yn-notifications"', $html );
		yume_assert_contains( 'Kazuma a répondu', $html );
		yume_assert_contains( 'value="yume_notifications_lues"', $html );
		yume_assert_contains( 'data-yn-push=', $html );
		yume_assert_contains( 'Activer sur cet appareil', $html );

		$export = donnees_personnelles( $u );
		yume_assert_same( 'Kazuma a répondu à votre commentaire', $export['notifications'][0]['titre'] );
		yume_assert_same( 'fcm.googleapis.com', $export['notifications_push'][0]['service'] );
		yume_assert_not_contains( 'auth', wp_json_encode( $export['notifications_push'] ) );
		effacer_donnees( $u );
		yume_assert_same( 0, notifications_utilisateur( $u )['total'] );
		yume_assert_same( array(), abonnements_push( $u ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Web Push
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-06 : jeton VAPID ES256 vérifiable par openssl_verify, clé privée jamais exposée',
	function () {
		global $wpdb;
		$cle = cle_publique_vapid();
		yume_assert_same( 65, strlen( base64url_decoder( $cle ) ) );
		$jeton = jeton_vapid( 'https://fcm.googleapis.com', 1900000000 );
		$parts = explode( '.', $jeton );
		yume_assert_same( 3, count( $parts ) );
		$entete = json_decode( base64url_decoder( $parts[0] ), true );
		$charge = json_decode( base64url_decoder( $parts[1] ), true );
		yume_assert_same( 'ES256', $entete['alg'] );
		yume_assert_same( 'https://fcm.googleapis.com', $charge['aud'] );
		yume_assert_same( 1900000000, $charge['exp'] );
		yume_assert_true( str_starts_with( $charge['sub'], 'mailto:' ) );
		$signature = base64url_decoder( $parts[2] );
		yume_assert_same( 64, strlen( $signature ) );
		// Clé publique (point non compressé) → PEM SubjectPublicKeyInfo P-256.
		$spki = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . base64url_decoder( $cle );
		$pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		yume_assert_same( 1, openssl_verify( $parts[0] . '.' . $parts[1], signature_brut_vers_der( $signature ), $pem, OPENSSL_ALGO_SHA256 ) );
		yume_assert_same( 0, openssl_verify( $parts[0] . '.X', signature_brut_vers_der( $signature ), $pem, OPENSSL_ALGO_SHA256 ) );

		// GET /push/cle : publique, clé publique seulement.
		$r = yume_rest( 'GET', '/yume/v1/push/cle' );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( $cle, $r->get_data()['cle'] );
		yume_assert_not_contains( 'PRIVATE', wp_json_encode( $r->get_data() ) );
		// Option non chargée automatiquement.
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'yume_vapid' ) ); // phpcs:ignore WordPress.DB
		yume_assert_true( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'autoload : ' . $autoload );
		// Désactivé par le filtre : pas de clé.
		add_filter( 'yume_push_actif', '__return_false' );
		$r = yume_rest( 'GET', '/yume/v1/push/cle' );
		remove_filter( 'yume_push_actif', '__return_false' );
		yume_assert_false( $r->get_data()['actif'] );
		yume_assert_same( null, $r->get_data()['cle'] );
	}
);

yume_test(
	'AMEL-06 : seuls les points d’accès https des services push connus sont acceptés (SSRF)',
	function () {
		foreach ( array(
			'https://fcm.googleapis.com/fcm/send/abc',
			'https://updates.push.services.mozilla.com/wpush/v2/abc',
			'https://wns2-par02p.notify.windows.com/w/?token=abc',
			'https://web.push.apple.com/QGx',
		) as $ok ) {
			yume_assert_true( endpoint_autorise( $ok ), $ok );
		}
		foreach ( array(
			'http://fcm.googleapis.com/fcm/send/abc',
			'https://fcm.googleapis.com.evil.test/x',
			'https://evilnotify.windows.com/x',
			'https://notify.windows.com/x',
			'https://127.0.0.1/x',
			'https://localhost/x',
			'https://user:pass@fcm.googleapis.com/x',
			'https://fcm.googleapis.com:8443/x',
			'https://exemple.fr/push',
			'ftp://web.push.apple.com/x',
		) as $refuse ) {
			yume_assert_false( endpoint_autorise( $refuse ), $refuse );
		}
		$u = yume_factory_user();
		$r = yume_rest(
			'POST',
			'/yume/v1/moi/push',
			array(
				'endpoint' => 'https://169.254.169.254/latest/meta-data',
				'p256dh'   => str_repeat( 'A', 87 ),
				'auth'     => str_repeat( 'A', 22 ),
			),
			$u
		);
		yume_assert_same( 400, $r->get_status() );
		yume_assert_same( 'yume_push_hote', $r->get_data()['code'] );
		yume_assert_same( array(), abonnements_push( $u ) );
		// Clés invalides.
		$r = yume_rest(
			'POST',
			'/yume/v1/moi/push',
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/x',
				'keys'     => array(
					'p256dh' => 'court',
					'auth'   => 'court',
				),
			),
			$u
		);
		yume_assert_same( 400, $r->get_status() );
		// Valide (format PushSubscription.toJSON()), puis désabonnement.
		$valide = array(
			'endpoint' => 'https://fcm.googleapis.com/fcm/send/valide',
			'keys'     => array(
				'p256dh' => rtrim( strtr( base64_encode( "\x04" . str_repeat( 'k', 64 ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'auth'   => rtrim( strtr( base64_encode( str_repeat( 'a', 16 ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			),
		);
		// Sans session WordPress (mot de passe d'application…) : refusé.
		$r = yume_rest( 'POST', '/yume/v1/moi/push', $valide, $u );
		yume_assert_same( 403, $r->get_status() );
		yume_assert_same( 'yume_push_session', $r->get_data()['code'] );
		$jeton = yume_tnot_session( $u );
		$r     = yume_tnot_avec_cookie(
			$u,
			$jeton,
			static function () use ( $valide, $u ) {
				return yume_rest( 'POST', '/yume/v1/moi/push', $valide, $u );
			}
		);
		yume_assert_same( 201, $r->get_status() );
		yume_assert_same( hash( 'sha256', $jeton ), abonnements_push( $u )[0]['session'], 'abonnement lié à la session' );
		yume_assert_same( 1, count( abonnements_push( $u ) ) );
		yume_assert_same( 200, yume_rest( 'DELETE', '/yume/v1/moi/push', array( 'endpoint' => 'https://fcm.googleapis.com/fcm/send/valide' ), yume_factory_user() )->get_status() );
		yume_assert_same( 1, count( abonnements_push( $u ) ), 'un autre membre ne supprime rien' );
		yume_rest( 'DELETE', '/yume/v1/moi/push', array( 'endpoint' => 'https://fcm.googleapis.com/fcm/send/valide' ), $u );
		yume_assert_same( array(), abonnements_push( $u ) );
	}
);

yume_test(
	'AMEL-06 : envoi en tâche cron (pas pendant la publication), en-têtes VAPID, TTL, Urgency, sans corps',
	function () {
		$s   = yume_tnot_oeuvre( 1 );
		$u   = yume_factory_user();
		$abo = yume_tnot_abonnement( $u );
		ajouter_favori( $u, $s['oeuvre'] );
		wp_clear_scheduled_hook( 'yume_push_envoyer' );
		yume_tnot_vieillir( 0 );
		// L'abonnement vient d'être créé : la notification doit lui être postérieure.
		global $wpdb;
		$wpdb->update( table_push(), array( 'dernier_envoi' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $abo['id'] ) ); // phpcs:ignore WordPress.DB
		$pendant = yume_tnot_http(
			static function () use ( $s ) {
				do_action( 'yume_chapitre_publie', $s['chapitres'][0] );
			}
		);
		yume_assert_same( array(), $pendant, 'aucune requête pendant la publication' );
		yume_assert_true( false !== wp_next_scheduled( 'yume_push_envoyer' ), 'envoi planifié' );

		$envois = yume_tnot_http(
			static function () {
				yume_assert_same( 1, envoyer_lot_push() );
			}
		);
		yume_assert_same( 1, count( $envois ) );
		yume_assert_same( $abo['endpoint'], $envois[0]['url'] );
		$entetes = $envois[0]['args']['headers'];
		yume_assert_true( str_starts_with( $entetes['Authorization'], 'vapid t=' ), $entetes['Authorization'] );
		yume_assert_contains( ', k=' . cle_publique_vapid(), $entetes['Authorization'] );
		yume_assert_same( '86400', $entetes['TTL'] );
		yume_assert_same( 'normal', $entetes['Urgency'] );
		yume_assert_same( '', $envois[0]['args']['body'] );
		yume_assert_same( 'POST', $envois[0]['args']['method'] );
		// Rien de nouveau : pas de second envoi.
		yume_assert_same( array(), yume_tnot_http( 'Yume\Core\Social\envoyer_lot_push' ) );
	}
);

yume_test(
	'AMEL-06 : abonnement supprimé sur 404/410, échecs comptés sinon ; lots de 50 enchaînés',
	function () {
		global $wpdb;
		$u     = yume_factory_user();
		$gone  = yume_tnot_abonnement( $u );
		$panne = yume_tnot_abonnement( $u, 'updates.push.services.mozilla.com' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table_push() . ' SET dernier_envoi = %s', gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB
		creer_notifications( array( $u ), 'sortie', 1, 'Sortie', home_url( '/' ) );
		yume_tnot_http(
			'Yume\Core\Social\envoyer_lot_push',
			static function ( $url ) use ( $gone ) {
				return $url === $gone['endpoint'] ? 410 : 500;
			}
		);
		$restants = abonnements_push( $u );
		yume_assert_same( array( $panne['endpoint'] ), wp_list_pluck( $restants, 'endpoint' ) );
		yume_assert_same( 1, (int) $restants[0]['echecs'] );

		// 55 abonnements à prévenir : 50 d'abord, puis un lot suivant planifié pour les 5 autres.
		$wpdb->query( 'DELETE FROM ' . table_push() ); // phpcs:ignore WordPress.DB
		$membres = array();
		for ( $i = 0; $i < 55; $i++ ) {
			$membres[] = yume_factory_user();
			yume_tnot_abonnement( end( $membres ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table_push() . ' SET dernier_envoi = %s', gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB
		creer_notifications( $membres, 'sortie', 2, 'Sortie groupée', home_url( '/' ) );
		wp_clear_scheduled_hook( 'yume_push_envoyer' );
		yume_assert_same( 50, count( yume_tnot_http( 'Yume\Core\Social\envoyer_lot_push' ) ) );
		yume_assert_true( false !== wp_next_scheduled( 'yume_push_envoyer' ), 'lot suivant planifié' );
		wp_clear_scheduled_hook( 'yume_push_envoyer' );
		yume_assert_same( 5, count( yume_tnot_http( 'Yume\Core\Social\envoyer_lot_push' ) ) );
		yume_assert_false( wp_next_scheduled( 'yume_push_envoyer' ), 'plus rien à envoyer' );
		// Désactivé : aucun envoi.
		creer_notifications( $membres, 'sortie', 3, 'Encore', home_url( '/' ) );
		add_filter( 'yume_push_actif', '__return_false' );
		yume_assert_same( array(), yume_tnot_http( 'Yume\Core\Social\envoyer_lot_push' ) );
		remove_filter( 'yume_push_actif', '__return_false' );
	}
);

yume_test(
	'AMEL-06 : le service worker s’authentifie par l’abonnement (GET /moi/notifications seulement)',
	function () {
		$u           = yume_factory_user();
		$abo         = yume_tnot_abonnement( $u );
		$avant_route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		$sauve       = $_SERVER;
		try {
			$_SERVER['REQUEST_METHOD']               = 'GET';
			$_SERVER['HTTP_X_YUME_PUSH_ENDPOINT']    = $abo['endpoint'];
			$_SERVER['HTTP_X_YUME_PUSH_AUTH']        = 'mauvais';
			$GLOBALS['wp']->query_vars['rest_route'] = '/yume/v1/moi/notifications';
			wp_set_current_user( 0 );
			yume_assert_same( null, authentifier_push( null ) );
			yume_assert_same( 0, get_current_user_id() );
			$_SERVER['HTTP_X_YUME_PUSH_AUTH']        = $abo['auth'];
			$GLOBALS['wp']->query_vars['rest_route'] = '/yume/v1/moi';
			yume_assert_same( null, authentifier_push( null ), 'autre route' );
			$GLOBALS['wp']->query_vars['rest_route'] = '/yume/v1/moi/notifications';
			$_SERVER['REQUEST_METHOD']               = 'POST';
			yume_assert_same( null, authentifier_push( null ), 'autre méthode' );
			$_SERVER['REQUEST_METHOD'] = 'GET';
			yume_assert_true( authentifier_push( true ) );
			yume_assert_same( $u, get_current_user_id() );
		} finally {
			$_SERVER                                 = $sauve; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$GLOBALS['wp']->query_vars['rest_route'] = $avant_route;
			wp_set_current_user( 0 );
		}
	}
);

yume_test(
	'Sécurité : abonnement push révoqué avec sa session (destruction, déconnexion, session invalide à l’envoi)',
	function () {
		$u = yume_factory_user();
		$a = yume_tnot_abonnement( $u );
		$b = yume_tnot_abonnement( $u, 'updates.push.services.mozilla.com' );
		yume_assert_same( $u, yume_tnot_authentifie( $a ) );
		yume_assert_same( $u, yume_tnot_authentifie( $b ) );

		// Session A détruite (« Se déconnecter » ailleurs, expiration) : seul A disparaît.
		WP_Session_Tokens::get_instance( $u )->destroy( $a['jeton'] );
		yume_assert_same( array( $b['id'] ), array_map( 'intval', wp_list_pluck( abonnements_push( $u ), 'id' ) ) );
		yume_assert_same( 0, yume_tnot_authentifie( $a ) );
		yume_assert_same( $u, yume_tnot_authentifie( $b ) );

		// Déconnexion (wp_logout reçoit l'identifiant ; le cookie porte encore le jeton).
		$c = yume_tnot_abonnement( $u );
		yume_tnot_avec_cookie(
			$u,
			$c['jeton'],
			static function () use ( $u ) {
				do_action( 'wp_logout', $u );
			}
		);
		yume_assert_same( array( $b['id'] ), array_map( 'intval', wp_list_pluck( abonnements_push( $u ), 'id' ) ) );

		// Stockage des sessions sans action de méta (filtre de l'hébergeur) : session invalide →
		// ni authentification, ni envoi ; l'abonnement est supprimé.
		$refus = static function () {
			return false;
		};
		add_filter( 'yume_push_session_valide', $refus );
		try {
			creer_notifications( array( $u ), 'sortie', 1, 'Sortie', home_url( '/' ) );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . table_push() . ' SET dernier_envoi = %s', gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB
			yume_assert_same( array(), yume_tnot_http( 'Yume\Core\Social\envoyer_lot_push' ), 'aucun envoi' );
			yume_assert_same( array(), abonnements_push( $u ) );
		} finally {
			remove_filter( 'yume_push_session_valide', $refus );
		}
		// Le filtre peut aussi valider une session inconnue du stockage par défaut.
		$accepte = static function ( $valide, $session ) {
			return str_repeat( 'a', 64 ) === $session ? true : $valide;
		};
		add_filter( 'yume_push_session_valide', $accepte, 10, 2 );
		yume_assert_true( session_push_valide( $u, str_repeat( 'a', 64 ) ) );
		remove_filter( 'yume_push_session_valide', $accepte, 10 );
		yume_assert_false( session_push_valide( $u, str_repeat( 'a', 64 ) ) );
		yume_assert_false( session_push_valide( $u, '' ) );
		// Session expirée : invalide.
		$jeton    = yume_tnot_session( $u );
		$sessions = get_user_meta( $u, 'session_tokens', true );
		$sessions[ hash( 'sha256', $jeton ) ]['expiration'] = time() - 10;
		update_user_meta( $u, 'session_tokens', $sessions );
		yume_assert_false( session_push_valide( $u, hash( 'sha256', $jeton ) ) );
	}
);

yume_test(
	'Sécurité : mot de passe changé ou réinitialisé, toutes les sessions détruites → tous les abonnements push supprimés',
	function () {
		$u = yume_factory_user();
		yume_tnot_abonnement( $u );
		yume_tnot_abonnement( $u );
		$autre = yume_factory_user();
		yume_tnot_abonnement( $autre );
		yume_assert_same( 2, count( abonnements_push( $u ) ) );
		// Profil modifié sans changer le mot de passe : rien.
		wp_update_user(
			array(
				'ID'           => $u,
				'display_name' => 'Nouveau pseudo',
			)
		);
		yume_assert_same( 2, count( abonnements_push( $u ) ) );
		// Changement de mot de passe (page profil, wp_update_user).
		add_filter( 'send_password_change_email', '__return_false' );
		wp_update_user(
			array(
				'ID'        => $u,
				'user_pass' => 'Nouveau-mot-de-passe-42',
			)
		);
		remove_filter( 'send_password_change_email', '__return_false' );
		yume_assert_same( array(), abonnements_push( $u ) );
		yume_assert_same( 1, count( abonnements_push( $autre ) ), 'les autres membres ne sont pas touchés' );
		// Réinitialisation (« Mot de passe oublié »).
		yume_tnot_abonnement( $u );
		do_action( 'after_password_reset', get_userdata( $u ), 'x' );
		yume_assert_same( array(), abonnements_push( $u ) );
		// wp_set_password() (WP-CLI, extensions).
		yume_tnot_abonnement( $u );
		wp_set_password( 'Encore-un-autre-7', $u );
		yume_assert_same( array(), abonnements_push( $u ) );
		// « Se déconnecter partout » : toutes les sessions détruites.
		$garde = yume_tnot_session( $u );
		yume_tnot_abonnement( $u, 'fcm.googleapis.com', $garde );
		yume_tnot_abonnement( $u );
		WP_Session_Tokens::get_instance( $u )->destroy_all();
		yume_assert_same( array(), abonnements_push( $u ) );
		yume_assert_same( 1, count( abonnements_push( $autre ) ) );
	}
);

yume_test(
	'Sécurité : « Se déconnecter » désabonne aussi le navigateur (script de la cloche)',
	function () {
		$js = (string) file_get_contents( YUME_CORE_DIR . 'includes/social/blocks/auth-links/view.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_assert_contains( 'action=logout', $js );
		yume_assert_contains( 'getSubscription()', $js );
		yume_assert_contains( '.unsubscribe()', $js );
	}
);

yume_test(
	'AMEL-06 : service worker — push et notificationclick ajoutés, exclusions et cache hors ligne intacts',
	function () {
		$sw = (string) file_get_contents( YUME_CORE_DIR . 'includes/reader/assets/sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_assert_contains( "addEventListener( 'push'", $sw );
		yume_assert_contains( "addEventListener( 'notificationclick'", $sw );
		yume_assert_contains( "cache: 'no-store'", $sw );
		yume_assert_contains( "'wp-json/'", $sw, 'REST jamais interceptée' );
		yume_assert_contains( "url.searchParams.has( 'rest_route' )", $sw );
		yume_assert_contains( "[ 'equipe/', 'compte/', 'connexion/' ]", $sw );
		yume_assert_contains( "credentials: 'omit'", $sw );
		yume_assert_contains( 'MARQUEUR.test( texte )', $sw );

		$code = \Yume\Core\Reader\contenu_sw();
		yume_assert_contains( '"notifications":', $code );
		yume_assert_contains( 'moi\/notifications', wp_json_encode( $code ) );
		yume_assert_contains( '"horsLigneActif":true', $code );
		// Lecture hors ligne coupée, push actif : même service worker, sans cache.
		add_filter( 'yume_pwa_actif', '__return_false' );
		$sans_cache = \Yume\Core\Reader\contenu_sw();
		add_filter( 'yume_push_actif', '__return_false' );
		$rien = \Yume\Core\Reader\contenu_sw();
		remove_filter( 'yume_push_actif', '__return_false' );
		remove_filter( 'yume_pwa_actif', '__return_false' );
		yume_assert_contains( '"horsLigneActif":false', $sans_cache );
		yume_assert_contains( "addEventListener( 'push'", $sans_cache );
		yume_assert_contains( 'unregister', $rien );
		yume_assert_not_contains( "'push'", $rien );
	}
);

yume_test(
	'AMEL-06 : réglage « Notifications navigateur » déclaré dans Yume → Réglages',
	function () {
		$cles = wp_list_pluck( (array) apply_filters( 'yume_reglages_champs', array() ), 'key' );
		yume_assert_true( in_array( 'notifications_navigateur', $cles, true ) );
		yume_assert_true( \Yume\Core\Social\push_actif() );
	}
);
