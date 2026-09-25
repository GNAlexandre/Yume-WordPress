<?php
/**
 * Lanceur de tests Yume, exécuté dans WordPress via WP-CLI :
 *
 *   wp eval-file wp-content/plugins/yume-core/tests/runner.php [module ...]
 *
 * Charge tests/test-*.php (ou seulement ceux des modules passés en argument), exécute chaque
 * test dans une transaction annulée à la fin, et sort avec un code non nul en cas d'échec.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/helpers.php';

$yume_filter = array_slice( $args ?? array(), 0 ); // phpcs:ignore
$yume_files  = glob( __DIR__ . '/test-*.php' );
sort( $yume_files );

foreach ( $yume_files as $yume_file ) {
	$yume_module = preg_replace( '/^test-|\.php$/', '', basename( $yume_file ) );
	if ( $yume_filter && ! in_array( $yume_module, $yume_filter, true ) ) {
		continue;
	}
	$GLOBALS['yume_tests_current_file'] = $yume_module;
	require $yume_file;
}

exit( yume_tests_run() ? 0 : 1 );
