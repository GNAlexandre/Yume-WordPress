<?php
/**
 * Régénère les jeux d'essai de la migration (tools/migrate/fixtures/) à partir de l'export
 * complet (tools/migrate/export/, non versionné).
 *
 *   php tools/migrate/build-fixtures.php
 *
 * Les fixtures sont des EXTRAITS COURTS : pages métier complètes (hub, fiches, arcs, pages
 * institutionnelles) mais chapitres réduits à quelques paragraphes (en-tête, quelques
 * répliques, une pensée, le pied de navigation) — jamais le texte intégral d'un chapitre.
 *
 * Script PHP pur (aucun appel à WordPress, aucune écriture hors de tools/migrate/fixtures/).
 *
 * @package Yume\Core
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$yume_source = __DIR__ . '/export';
$yume_cible  = __DIR__ . '/fixtures';

/** Pages reprises : 'complet' ou règles d'extrait (premiers blocs, blocs de fin, pensées, citations). */
$yume_pages = array(
	12   => 'complet', // Hub « Yume LN ».
	66   => 'complet', // Page vide « Yume News » (famille catégorie).
	143  => 'complet', // Page institutionnelle « La Yume Novel ».
	151  => 'complet', // Brouillon « FAQ » (ignoré).
	550  => 'complet', // Fiche Silent Witch (WN) : arcs.
	2209 => 'complet', // Fiche Grimgar (LN) : 9 tomes, 18 liens ClicTune.
	2072 => 'complet', // ARC 4 (6 chapitres traduits).
	2173 => 'complet', // ARC 7 (15 annoncés, 8 traduits).
	1548 => array( 'debut' => 9, 'fin' => 3 ),                                  // T.4 ch. 1 : slug incohérent.
	2417 => array( 'debut' => 5, 'fin' => 3, 'citations' => 1 ),                // T.7 ch. 3 : sous-titre « ****** », citation.
	2558 => array( 'debut' => 7, 'fin' => 4, 'pensees' => 1 ),                  // T.7 ch. 8 : image finale non liée.
);

/** Articles repris (contenu complet : ce sont des annonces courtes). */
$yume_articles = array( 2555, 2564, 1547, 1712, 205 );

/**
 * Lit un fichier JSON de l'export.
 *
 * @param string $fichier Chemin.
 * @return array
 */
function yume_fixtures_lire( string $fichier ): array {
	if ( ! is_readable( $fichier ) ) {
		fwrite( STDERR, "Fichier introuvable : $fichier (lancer d'abord assemble-export.php).\n" );
		exit( 1 );
	}
	$donnees = json_decode( (string) file_get_contents( $fichier ), true );
	if ( ! is_array( $donnees ) ) {
		fwrite( STDERR, "JSON invalide : $fichier\n" );
		exit( 1 );
	}
	return $donnees;
}

/**
 * Écrit un fichier JSON lisible.
 *
 * @param string $fichier Chemin.
 * @param mixed  $donnees Données.
 */
function yume_fixtures_ecrire( string $fichier, $donnees ): void {
	file_put_contents( $fichier, json_encode( $donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
}

/**
 * Réduit un chapitre à un extrait : premiers blocs, quelques blocs choisis, blocs de fin.
 *
 * @param string $contenu Contenu brut (blocs de premier niveau séparés par une ligne vide).
 * @param array  $regles  debut, fin, pensees, citations.
 */
function yume_fixtures_extrait( string $contenu, array $regles ): string {
	$blocs   = preg_split( '/\n\n(?=<!-- wp:)/', trim( $contenu ) );
	$debut   = array_slice( $blocs, 0, $regles['debut'] );
	$fin     = array_slice( $blocs, -$regles['fin'] );
	$milieu  = array_slice( $blocs, $regles['debut'], count( $blocs ) - $regles['debut'] - $regles['fin'] );
	$choisis = array();
	$pensees = (int) ( $regles['pensees'] ?? 0 );
	$cit     = (int) ( $regles['citations'] ?? 0 );
	foreach ( $milieu as $bloc ) {
		$interieur = trim( (string) preg_replace( '/<!--.*?-->/s', '', $bloc ) );
		if ( $pensees > 0 && preg_match( '#^<p><em>[^<]+</em></p>$#u', $interieur ) ) {
			$choisis[] = $bloc;
			--$pensees;
		} elseif ( $cit > 0 && str_starts_with( $bloc, '<!-- wp:quote' ) ) {
			$choisis[] = $bloc;
			--$cit;
		}
	}
	return implode( "\n\n", array_merge( $debut, $choisis, array( "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\" />\n<!-- /wp:separator -->" ), $fin ) );
}

$yume_toutes_pages = yume_fixtures_lire( $yume_source . '/pages.json' );
$yume_tous_posts   = yume_fixtures_lire( $yume_source . '/posts.json' );
$yume_medias       = yume_fixtures_lire( $yume_source . '/media.json' );
$yume_site         = yume_fixtures_lire( $yume_source . '/site.json' );

if ( ! is_dir( $yume_cible ) ) {
	mkdir( $yume_cible, 0755, true );
}

$yume_sortie_pages = array();
foreach ( $yume_toutes_pages as $yume_page ) {
	$yume_regle = $yume_pages[ $yume_page['id'] ] ?? null;
	if ( null === $yume_regle ) {
		continue;
	}
	if ( is_array( $yume_regle ) ) {
		$yume_page['content'] = yume_fixtures_extrait( (string) $yume_page['content'], $yume_regle );
	}
	$yume_sortie_pages[] = $yume_page;
}
$yume_sortie_posts = array_values( array_filter( $yume_tous_posts, static fn( $p ) => in_array( $p['id'], $yume_articles, true ) ) );

// Médias référencés par les extraits (images à la une, blocs image, couvertures).
$yume_ids = array();
foreach ( array_merge( $yume_sortie_pages, $yume_sortie_posts ) as $yume_item ) {
	$yume_ids[] = (int) ( $yume_item['featured_media'] ?? 0 );
	if ( preg_match_all( '/"id":(\d+)|wp-image-(\d+)/', (string) $yume_item['content'], $yume_m ) ) {
		foreach ( array_merge( $yume_m[1], $yume_m[2] ) as $yume_id ) {
			$yume_ids[] = (int) $yume_id;
		}
	}
}
$yume_navigations = array_values( array_filter( yume_fixtures_lire( $yume_source . '/navigations.json' ), static fn( $n ) => 4 === (int) $n['id'] ) );
$yume_parties     = array_values( array_filter( yume_fixtures_lire( $yume_source . '/template-parts.json' ), static fn( $t ) => 'header' === ( $t['slug'] ?? '' ) ) );
// Image de l'en-tête (bannière du site) référencée par la partie de modèle.
if ( preg_match_all( '/\\?"id\\?":(\d+)|wp-image-(\d+)/', (string) json_encode( $yume_parties ), $yume_m ) ) {
	foreach ( array_merge( $yume_m[1], $yume_m[2] ) as $yume_id ) {
		$yume_ids[] = (int) $yume_id;
	}
}
$yume_ids           = array_flip( array_filter( $yume_ids ) );
$yume_sortie_medias = array_values( array_filter( $yume_medias, static fn( $m ) => isset( $yume_ids[ $m['id'] ] ) ) );

$yume_site['source']           = 'Extraits courts de l’export (tools/migrate/build-fixtures.php) — jeu d’essai des tests de migration.';
$yume_site['comptes']          = array(
	'pages'          => count( $yume_sortie_pages ),
	'posts'          => count( $yume_sortie_posts ),
	'categories'     => 3,
	'media'          => count( $yume_sortie_medias ),
	'navigations'    => count( $yume_navigations ),
	'template_parts' => count( $yume_parties ),
);
$yume_site['contenu_manquant'] = array(
	'pages' => array(),
	'posts' => array(),
);

yume_fixtures_ecrire( $yume_cible . '/site.json', $yume_site );
yume_fixtures_ecrire( $yume_cible . '/pages.json', $yume_sortie_pages );
yume_fixtures_ecrire( $yume_cible . '/posts.json', $yume_sortie_posts );
yume_fixtures_ecrire( $yume_cible . '/categories.json', yume_fixtures_lire( $yume_source . '/categories.json' ) );
yume_fixtures_ecrire( $yume_cible . '/media.json', $yume_sortie_medias );
yume_fixtures_ecrire( $yume_cible . '/navigations.json', $yume_navigations );
yume_fixtures_ecrire( $yume_cible . '/template-parts.json', $yume_parties );

printf(
	"Fixtures écrites dans %s : %d pages, %d articles, %d médias.\n",
	$yume_cible,
	count( $yume_sortie_pages ),
	count( $yume_sortie_posts ),
	count( $yume_sortie_medias )
);
