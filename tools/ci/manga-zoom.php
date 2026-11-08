<?php
/**
 * Données du test de bout en bout du lecteur manga (tools/ci/manga-zoom.js) : une œuvre de type
 * manga liée à MangaDex, son tome et un chapitre MangaDex de trois pages (métas yume_mdx_*),
 * publiés. Idempotent (contenus repris par leur slug). Aucune requête vers MangaDex : les images
 * du proxy sont interceptées par le navigateur du test.
 *
 * Lancement : tools/localenv/wp.sh eval-file tools/ci/manga-zoom.php (fait par manga-zoom.sh).
 * Écrit l'adresse du chapitre sur la sortie standard.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_mangadex_chapitre' ) ) {
	fwrite( STDERR, "[manga-zoom] module mangadex absent (extension yume-core active ?)\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- outil en ligne de commande.
	exit( 2 );
}

/**
 * Contenu repris par son type et son slug, sinon créé (publié).
 *
 * @param array<string,mixed> $donnees Arguments de wp_insert_post (post_type, post_name obligatoires).
 */
function yume_manga_zoom_contenu( array $donnees ): int {
	$existants = get_posts(
		array(
			'post_type'      => $donnees['post_type'],
			'name'           => $donnees['post_name'],
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	$donnees   = array_merge( array( 'post_status' => 'publish' ), $donnees );
	if ( $existants ) {
		$donnees['ID'] = (int) $existants[0];
	}
	$meta = $donnees['meta_input'] ?? array();
	unset( $donnees['meta_input'] );
	$id = (int) wp_insert_post( wp_slash( $donnees ), true );
	if ( ! $id ) {
		fwrite( STDERR, '[manga-zoom] création impossible : ' . $donnees['post_name'] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- outil en ligne de commande.
		exit( 2 );
	}
	foreach ( $meta as $cle => $valeur ) {
		update_post_meta( $id, $cle, $valeur );
	}
	return $id;
}

$yume_manga_zoom_oeuvre = yume_manga_zoom_contenu(
	array(
		'post_type'  => 'yume_oeuvre',
		'post_name'  => 'essai-manga-zoom',
		'post_title' => 'Essai manga (zoom)',
		'meta_input' => array( 'yume_mdx_serie' => '0e2b8c1a-1111-4a2b-9c3d-000000000001' ),
	)
);
if ( taxonomy_exists( 'yume_type' ) ) {
	wp_set_object_terms( $yume_manga_zoom_oeuvre, 'manga', 'yume_type' );
}

$yume_manga_zoom_tome = yume_manga_zoom_contenu(
	array(
		'post_type'  => 'yume_tome',
		'post_name'  => 'tome-1',
		'post_title' => 'Essai manga (zoom) — Tome 1',
		'meta_input' => array(
			'yume_oeuvre_id' => $yume_manga_zoom_oeuvre,
			'yume_numero'    => 1,
			'yume_nature'    => 'tome',
		),
	)
);

$yume_manga_zoom_chapitre = yume_manga_zoom_contenu(
	array(
		'post_type'    => 'yume_chapitre',
		'post_name'    => 'essai-manga-zoom-chapitre-1',
		'post_title'   => 'Chapitre 1',
		'post_content' => '',
		'meta_input'   => array(
			'yume_tome_id'      => $yume_manga_zoom_tome,
			'yume_oeuvre_id'    => $yume_manga_zoom_oeuvre,
			'yume_numero'       => 1,
			'yume_nature'       => 'chapitre',
			'yume_mdx_chapitre' => '0e2b8c1a-2222-4a2b-9c3d-000000000001',
			'yume_mdx_pages'    => 3,
			'yume_mdx_version'  => 'a1b2c3d4',
		),
	)
);

echo esc_url_raw( (string) get_permalink( $yume_manga_zoom_chapitre ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sortie console.
