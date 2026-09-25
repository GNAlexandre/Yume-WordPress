<?php
/**
 * Routeur du serveur intégré de PHP pour le WordPress local (utilisé par serve.sh) :
 * les fichiers existants (CSS, JS, images, scripts de wp-admin) sont servis tels quels,
 * tout le reste passe par index.php (permaliens).
 *
 * Commande équivalente : php -S 127.0.0.1:8080 -t "$YUME_WP_PATH" tools/localenv/router.php
 *
 * @package Yume\Core
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- routeur local, avant WordPress.
$yume_racine = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/' );
$yume_chemin = rawurldecode( (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

// Aucun accès hors de la racine.
if ( false !== strpos( $yume_chemin, '..' ) ) {
	http_response_code( 400 );
	return true;
}

$yume_fichier = $yume_racine . $yume_chemin;
if ( '/' !== $yume_chemin && is_file( $yume_fichier ) ) {
	return false;
}
if ( is_dir( $yume_fichier ) && is_file( rtrim( $yume_fichier, '/' ) . '/index.php' ) ) {
	if ( '/' !== substr( $yume_chemin, -1 ) ) {
		header( 'Location: ' . $yume_chemin . '/', true, 301 );
		return true;
	}
	return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $yume_racine . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $yume_racine );
require $yume_racine . '/index.php';
