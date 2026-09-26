<?php
/**
 * Extrait de l'export de l'ancien site (tools/migrate/export/pages.json, non versionné) le
 * contenu réel des pages institutionnelles du menu (L'équipe, La Yume Novel, FAQ, Nos réseaux,
 * Contact) vers pages-institutionnelles.json, versionné et repris par la démo (demo.php) :
 * Playground et l'environnement local montrent ainsi ces pages telles qu'elles sont en ligne.
 * Les images restent celles de la médiathèque du site actuel.
 *
 * Usage (PHP 8.1+, sans WordPress) :
 *
 *   php tools/playground/extraire-pages.php                     depuis tools/migrate/export/pages.json
 *   php tools/playground/extraire-pages.php --export=chemin.json
 *
 * Puis régénérer les blueprints : php tools/playground/construire.php
 *
 * @package Yume\Core
 */

// Script en ligne de commande uniquement.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$yume_ep_options = getopt( '', array( 'export:' ) );
$yume_ep_export  = (string) ( $yume_ep_options['export'] ?? dirname( __DIR__ ) . '/migrate/export/pages.json' );
$yume_ep_slugs   = array( 'lequipe', 'la-yume-novel', 'yume-faq', 'a-propos', 'contactez-nous' );

$yume_ep_brut = is_readable( $yume_ep_export ) ? file_get_contents( $yume_ep_export ) : false;
if ( false === $yume_ep_brut ) {
	fwrite( STDERR, "Export introuvable : {$yume_ep_export}\n" );
	exit( 1 );
}
$yume_ep_pages = json_decode( $yume_ep_brut, true );
$yume_ep_pages = is_array( $yume_ep_pages ) && isset( $yume_ep_pages['pages'] ) ? $yume_ep_pages['pages'] : $yume_ep_pages;
if ( ! is_array( $yume_ep_pages ) ) {
	fwrite( STDERR, "Export illisible : {$yume_ep_export}\n" );
	exit( 1 );
}

/**
 * Texte d'un champ de l'API (chaîne, ou objet { raw, rendered }).
 *
 * @param mixed $champ Champ.
 */
function yume_ep_texte( $champ ): string {
	if ( is_array( $champ ) ) {
		$champ = $champ['raw'] ?? $champ['rendered'] ?? '';
	}
	return is_string( $champ ) ? $champ : '';
}

$yume_ep_sortie = array();
foreach ( $yume_ep_slugs as $yume_ep_slug ) {
	foreach ( $yume_ep_pages as $yume_ep_page ) {
		if ( ! is_array( $yume_ep_page ) || ( $yume_ep_page['slug'] ?? '' ) !== $yume_ep_slug || 'publish' !== ( $yume_ep_page['status'] ?? '' ) ) {
			continue;
		}
		$yume_ep_sortie[] = array(
			'slug'    => $yume_ep_slug,
			'titre'   => html_entity_decode( yume_ep_texte( $yume_ep_page['title'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'contenu' => yume_ep_texte( $yume_ep_page['content'] ?? '' ),
		);
		break;
	}
}

$yume_ep_fichier = __DIR__ . '/pages-institutionnelles.json';
$yume_ep_json    = json_encode( $yume_ep_sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- hors WordPress.
if ( false === file_put_contents( $yume_ep_fichier, $yume_ep_json ) ) {
	fwrite( STDERR, "Écriture impossible : {$yume_ep_fichier}\n" );
	exit( 1 );
}
printf( "%d page(s) écrite(s) dans %s\n", count( $yume_ep_sortie ), $yume_ep_fichier );
