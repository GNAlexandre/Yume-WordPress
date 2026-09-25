<?php
/**
 * Assemble l'export du site yumenovel.fr à partir des réponses brutes du connecteur
 * WordPress.com (opérations en lecture seule list / get), rangées dans export/raw/.
 *
 *   php tools/migrate/assemble-export.php [dossier-export]
 *
 * Chaque fichier raw/<operation>__<clé>.json contient :
 *   { "operation": "pages.get", "tool": "wpcom-mcp-content-authoring", "params": {...}, "response": {...} }
 * où « response » est la réponse JSON du connecteur ({ "data": … , "pagination": … }).
 *
 * Écrit dans le dossier d'export : pages.json, posts.json, categories.json, media.json,
 * navigations.json, template-parts.json et site.json (métadonnées de l'export). Script PHP
 * autonome : aucune fonction WordPress, aucune dépendance.
 *
 * @package Yume\Core
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$yume_dir = rtrim( $argv[1] ?? __DIR__ . '/export', '/' );
$yume_raw = $yume_dir . '/raw';
if ( ! is_dir( $yume_raw ) ) {
	fwrite( STDERR, "Dossier introuvable : $yume_raw\n" );
	exit( 1 );
}

/**
 * Décode un titre ou un texte rendu par l'API (entités HTML, espaces insécables).
 *
 * @param mixed $valeur Chaîne ou objet {raw, rendered}.
 */
function yume_export_texte( $valeur ): string {
	if ( is_array( $valeur ) ) {
		$valeur = $valeur['raw'] ?? $valeur['rendered'] ?? '';
	}
	$texte = html_entity_decode( (string) $valeur, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( str_replace( "\u{00A0}", ' ', $texte ) );
}

/**
 * Contenu brut (balisage de blocs) d'une réponse get, ou null s'il est absent.
 *
 * @param mixed $valeur Chaîne ou objet {raw, rendered}.
 */
function yume_export_contenu( $valeur ): ?string {
	if ( is_array( $valeur ) ) {
		$valeur = $valeur['raw'] ?? $valeur['rendered'] ?? null;
	}
	return null === $valeur ? null : (string) $valeur;
}

$yume_listes  = array();
$yume_details = array();
$yume_dates   = array();
foreach ( glob( $yume_raw . '/*.json' ) as $yume_fichier ) {
	$yume_json = json_decode( (string) file_get_contents( $yume_fichier ), true );
	if ( ! is_array( $yume_json ) || empty( $yume_json['operation'] ) || ! isset( $yume_json['response'] ) ) {
		fwrite( STDERR, 'Ignoré (format inattendu) : ' . basename( $yume_fichier ) . "\n" );
		continue;
	}
	$yume_dates[] = filemtime( $yume_fichier );
	$yume_op      = (string) $yume_json['operation'];
	$yume_data    = $yume_json['response']['data'] ?? $yume_json['response'];
	if ( str_ends_with( $yume_op, '.list' ) ) {
		foreach ( (array) $yume_data as $yume_item ) {
			if ( is_array( $yume_item ) && isset( $yume_item['id'] ) ) {
				$yume_listes[ $yume_op ][ (string) $yume_item['id'] ] = $yume_item;
			}
		}
	} elseif ( str_ends_with( $yume_op, '.get' ) && is_array( $yume_data ) && isset( $yume_data['id'] ) ) {
		$yume_details[ $yume_op ][ (string) $yume_data['id'] ] = $yume_data;
	}
}

/**
 * Fusionne la liste (métadonnées complètes) et les réponses get (contenu brut).
 *
 * @param array  $liste   Éléments de la liste, par ID.
 * @param array  $details Réponses get, par ID.
 * @param array  $champs  Champs à conserver.
 * @return array<int,array<string,mixed>>
 */
function yume_export_fusion( array $liste, array $details, array $champs ): array {
	$ids = array_unique( array_merge( array_keys( $liste ), array_keys( $details ) ) );
	sort( $ids, SORT_NUMERIC );
	$sortie = array();
	foreach ( $ids as $id ) {
		$l    = $liste[ $id ] ?? array();
		$d    = $details[ $id ] ?? array();
		$item = array( 'id' => (int) $id );
		foreach ( $champs as $champ ) {
			if ( in_array( $champ, array( 'title', 'excerpt' ), true ) ) {
				$item[ $champ ] = yume_export_texte( $d[ $champ ] ?? $l[ $champ ] ?? '' );
			} elseif ( 'content' === $champ ) {
				$item['content'] = array_key_exists( 'content', $d ) ? yume_export_contenu( $d['content'] ) : null;
			} else {
				$item[ $champ ] = $d[ $champ ] ?? $l[ $champ ] ?? null;
			}
		}
		$sortie[] = $item;
	}
	return $sortie;
}

$yume_pages = yume_export_fusion(
	$yume_listes['pages.list'] ?? array(),
	$yume_details['pages.get'] ?? array(),
	array( 'slug', 'status', 'title', 'link', 'date', 'modified', 'author', 'parent', 'menu_order', 'template', 'featured_media', 'excerpt', 'content' )
);
$yume_posts = yume_export_fusion(
	$yume_listes['posts.list'] ?? array(),
	$yume_details['posts.get'] ?? array(),
	array( 'slug', 'status', 'title', 'link', 'date', 'modified', 'author', 'categories', 'tags', 'featured_media', 'excerpt', 'content' )
);

$yume_media = array();
foreach ( $yume_listes['media.list'] ?? array() as $yume_m ) {
	$yume_media[] = array(
		'id'         => (int) $yume_m['id'],
		'date'       => $yume_m['date'] ?? null,
		'mime_type'  => $yume_m['mime_type'] ?? '',
		'source_url' => $yume_m['source_url'] ?? '',
		'title'      => yume_export_texte( $yume_m['title'] ?? '' ),
		'alt_text'   => (string) ( $yume_m['alt_text'] ?? '' ),
		'post'       => isset( $yume_m['post'] ) ? (int) $yume_m['post'] : 0,
		'width'      => (int) ( $yume_m['media_details']['width'] ?? 0 ),
		'height'     => (int) ( $yume_m['media_details']['height'] ?? 0 ),
		'file'       => (string) ( $yume_m['media_details']['file'] ?? '' ),
	);
}
usort( $yume_media, static fn( $a, $b ) => $a['id'] <=> $b['id'] );

$yume_categories = array_values( $yume_listes['categories.list'] ?? array() );
usort( $yume_categories, static fn( $a, $b ) => $a['id'] <=> $b['id'] );

$yume_navigations = array();
foreach ( $yume_listes['navigation.list'] ?? array() as $yume_n ) {
	$yume_navigations[] = array(
		'id'      => (int) $yume_n['id'],
		'title'   => yume_export_texte( $yume_n['title'] ?? '' ),
		'status'  => $yume_n['status'] ?? '',
		'content' => yume_export_contenu( $yume_n['content'] ?? '' ),
	);
}
$yume_parts = array();
foreach ( $yume_listes['template-parts.list'] ?? array() as $yume_p ) {
	$yume_parts[] = array(
		'id'      => (string) $yume_p['id'],
		'slug'    => $yume_p['slug'] ?? '',
		'title'   => yume_export_texte( $yume_p['title'] ?? '' ),
		'area'    => $yume_p['area'] ?? '',
		'content' => yume_export_contenu( $yume_p['content'] ?? '' ),
	);
}

$yume_sans_contenu = array(
	'pages' => array_values( array_map( static fn( $p ) => $p['id'], array_filter( $yume_pages, static fn( $p ) => null === $p['content'] ) ) ),
	'posts' => array_values( array_map( static fn( $p ) => $p['id'], array_filter( $yume_posts, static fn( $p ) => null === $p['content'] ) ) ),
);

$yume_site = array(
	'site_id'           => 238001312,
	'domaine'           => 'yumenovel.fr',
	'domaines_alias'    => array( 'yumenovel.wordpress.com' ),
	'exporte_le'        => $yume_dates ? gmdate( 'Y-m-d H:i:s', max( $yume_dates ) ) : null,
	'source'            => 'Connecteur WordPress.com (lecture seule : pages.list/get, posts.list/get, categories.list, media.list, navigation.list, template-parts.list)',
	'comptes'           => array(
		'pages'          => count( $yume_pages ),
		'posts'          => count( $yume_posts ),
		'categories'     => count( $yume_categories ),
		'media'          => count( $yume_media ),
		'navigations'    => count( $yume_navigations ),
		'template_parts' => count( $yume_parts ),
	),
	'contenu_manquant'  => $yume_sans_contenu,
);

$yume_sorties = array(
	'pages.json'          => $yume_pages,
	'posts.json'          => $yume_posts,
	'categories.json'     => $yume_categories,
	'media.json'          => $yume_media,
	'navigations.json'    => $yume_navigations,
	'template-parts.json' => $yume_parts,
	'site.json'           => $yume_site,
);
foreach ( $yume_sorties as $yume_nom => $yume_valeur ) {
	file_put_contents( $yume_dir . '/' . $yume_nom, json_encode( $yume_valeur, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
}

printf(
	"Export assemblé dans %s : %d pages (%d sans contenu), %d articles (%d sans contenu), %d catégories, %d médias, %d navigations, %d parties de modèle.\n",
	$yume_dir,
	count( $yume_pages ),
	count( $yume_sans_contenu['pages'] ),
	count( $yume_posts ),
	count( $yume_sans_contenu['posts'] ),
	count( $yume_categories ),
	count( $yume_media ),
	count( $yume_navigations ),
	count( $yume_parts )
);
