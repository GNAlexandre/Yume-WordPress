<?php
/**
 * Mini-framework de test sans dépendance (PHPUnit n'est pas requis en local).
 *
 * Utilisation dans tests/test-<module>.php :
 *
 *   yume_test( 'crée un tome', function () {
 *       $id = yume_factory_post( array( 'post_type' => 'yume_tome' ) );
 *       yume_assert_same( 'yume_tome', get_post_type( $id ) );
 *   } );
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- socle de test en un seul fichier (exception et assertions).

/** Exception levée par une assertion échouée. */
class Yume_Test_Failure extends Exception {}

$GLOBALS['yume_tests'] = array();

/**
 * Déclare un test.
 *
 * @param string   $name  Nom lisible.
 * @param callable $corps Corps du test.
 */
function yume_test( string $name, callable $corps ): void {
	$GLOBALS['yume_tests'][] = array(
		'module' => $GLOBALS['yume_tests_current_file'] ?? 'misc',
		'name'   => $name,
		'fn'     => $corps,
	);
}

/**
 * Exporte une valeur pour un message d'erreur.
 *
 * @param mixed $v Valeur.
 */
function yume_test_export( $v ): string {
	$s = var_export( $v, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	return strlen( $s ) > 600 ? substr( $s, 0, 600 ) . '…' : $s;
}

/**
 * @param mixed  $expected Attendu.
 * @param mixed  $actual   Obtenu.
 * @param string $msg      Message.
 * @throws Yume_Test_Failure Assertion échouée.
 */
function yume_assert_same( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new Yume_Test_Failure( trim( $msg . "\n  attendu : " . yume_test_export( $expected ) . "\n  obtenu  : " . yume_test_export( $actual ) ) );
	}
}

/**
 * @param mixed  $expected Attendu.
 * @param mixed  $actual   Obtenu.
 * @param string $msg      Message.
 * @throws Yume_Test_Failure Assertion échouée.
 */
function yume_assert_equals( $expected, $actual, string $msg = '' ): void {
	if ( $expected != $actual ) { // phpcs:ignore Universal.Operators.StrictComparisons
		throw new Yume_Test_Failure( trim( $msg . "\n  attendu : " . yume_test_export( $expected ) . "\n  obtenu  : " . yume_test_export( $actual ) ) );
	}
}

/**
 * @param mixed  $value Valeur.
 * @param string $msg   Message.
 * @throws Yume_Test_Failure Assertion échouée.
 */
function yume_assert_true( $value, string $msg = '' ): void {
	if ( true !== $value ) {
		throw new Yume_Test_Failure( trim( $msg . "\n  attendu : true\n  obtenu  : " . yume_test_export( $value ) ) );
	}
}

/**
 * @param mixed  $value Valeur.
 * @param string $msg   Message.
 * @throws Yume_Test_Failure Assertion échouée.
 */
function yume_assert_false( $value, string $msg = '' ): void {
	if ( false !== $value ) {
		throw new Yume_Test_Failure( trim( $msg . "\n  attendu : false\n  obtenu  : " . yume_test_export( $value ) ) );
	}
}

/**
 * @param string $needle   Sous-chaîne attendue.
 * @param string $haystack Texte.
 * @param string $msg      Message.
 * @throws Yume_Test_Failure Assertion échouée.
 */
function yume_assert_contains( string $needle, string $haystack, string $msg = '' ): void {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new Yume_Test_Failure( trim( $msg . "\n  « " . $needle . ' » absent de : ' . yume_test_export( $haystack ) ) );
	}
}

/**
 * @param string $needle   Sous-chaîne interdite.
 * @param string $haystack Texte.
 * @param string $msg      Message.
 * @throws Yume_Test_Failure Assertion échouée.
 */
function yume_assert_not_contains( string $needle, string $haystack, string $msg = '' ): void {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new Yume_Test_Failure( trim( $msg . "\n  « " . $needle . ' » présent dans : ' . yume_test_export( $haystack ) ) );
	}
}

/**
 * Crée un article/entrée et renvoie son ID.
 *
 * @param array $args Arguments wp_insert_post (post_type, post_title, meta_input, …).
 * @throws Yume_Test_Failure Création impossible.
 */
function yume_factory_post( array $args = array() ): int {
	static $n = 0;
	++$n;
	$id = wp_insert_post(
		array_merge(
			array(
				'post_title'  => 'Test ' . $n,
				'post_status' => 'publish',
				'post_type'   => 'post',
			),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new Yume_Test_Failure( 'yume_factory_post : ' . $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * Crée un utilisateur avec un rôle et renvoie son ID.
 *
 * @param string $role Rôle.
 * @throws Yume_Test_Failure Création impossible.
 */
function yume_factory_user( string $role = 'subscriber' ): int {
	static $n = 0;
	++$n;
	$login = 'yt_' . $role . '_' . $n . '_' . wp_rand( 1000, 9999 );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Yume_Test_Failure( 'yume_factory_user : ' . $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * Exécute une requête REST interne en tant qu'utilisateur donné.
 *
 * @param string $method  GET, POST, PUT, PATCH, DELETE.
 * @param string $route   Ex. '/yume/v1/planning'.
 * @param array  $params  Paramètres (corps JSON ou query).
 * @param int    $user_id 0 = anonyme.
 * @param array  $files   Fichiers ($_FILES-like) pour les envois multipart.
 */
function yume_rest( string $method, string $route, array $params = array(), int $user_id = 0, array $files = array() ): WP_REST_Response {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	if ( $files ) {
		$request->set_file_params( $files );
	}
	$response = rest_do_request( $request );
	wp_set_current_user( 0 );
	return rest_ensure_response( $response );
}

/**
 * Rend un bloc dynamique et renvoie le HTML.
 *
 * @param string $name  Nom du bloc (yume/…).
 * @param array  $attrs Attributs.
 */
function yume_render_block( string $name, array $attrs = array() ): string {
	return render_block(
		array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
}

/**
 * Exécute les tests déclarés. Chaque test tourne dans une transaction annulée.
 *
 * @return bool Vrai si tous les tests passent.
 */
function yume_tests_run(): bool {
	global $wpdb;
	$pass   = 0;
	$fail   = 0;
	$errors = array();
	$wpdb->suppress_errors( false );
	foreach ( $GLOBALS['yume_tests'] as $t ) {
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
		$mail_filter = static function () {
			return true; // Aucun e-mail réel pendant les tests : pre_wp_mail court-circuite l'envoi.
		};
		add_filter( 'pre_wp_mail', $mail_filter );
		try {
			( $t['fn'] )();
			++$pass;
			echo "  ✓ [{$t['module']}] {$t['name']}\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		} catch ( \Throwable $e ) {
			++$fail;
			$where    = $e instanceof Yume_Test_Failure ? '' : ' (' . get_class( $e ) . ' ' . $e->getFile() . ':' . $e->getLine() . ')';
			$errors[] = "  ✗ [{$t['module']}] {$t['name']}{$where}\n    " . str_replace( "\n", "\n    ", $e->getMessage() );
			echo "  ✗ [{$t['module']}] {$t['name']}\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter );
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			wp_cache_flush();
			wp_set_current_user( 0 );
		}
	}
	echo "\n" . implode( "\n", $errors ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	printf( "%d réussi(s), %d échec(s)\n", $pass, $fail ); // phpcs:ignore WordPress.Security.EscapeOutput
	return 0 === $fail;
}
