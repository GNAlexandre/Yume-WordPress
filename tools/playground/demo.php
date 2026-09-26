<?php
/**
 * Contenu de démonstration minimal de Yume Novel, pour WordPress Playground et la préproduction.
 *
 * Crée (sans doublon si on le relance) : une œuvre fictive avec ses taxonomies, un tome publié
 * avec liens PDF/EPUB et deux chapitres, un tome planifié en cours de traduction, un article
 * d'actualité, toutes les pages du contrat (§11 : bibliothèque, planning, équipe, publier,
 * membres, compte, connexion, actualités, mentions légales, accueil) avec les réglages de lecture
 * (accueil statique, page des articles), les pages institutionnelles du menu et deux comptes de
 * test (equipe / equipe, lecteur / lecteur). Les commentaires sont réservés aux comptes connectés.
 *
 * Ce fichier est la source de l'étape runPHP des blueprints (tools/playground/construire.php
 * l'y recopie). Il s'exécute aussi sur un WordPress local :
 *
 *   tools/localenv/wp.sh eval-file tools/playground/demo.php
 *
 * @package Yume\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Étape runPHP de WordPress Playground : WordPress n'est pas encore chargé.
	require_once '/wordpress/wp-load.php';
}

if ( ! post_type_exists( 'yume_oeuvre' ) || ! post_type_exists( 'yume_tome' ) || ! post_type_exists( 'yume_chapitre' ) ) {
	echo "Démo : l'extension Yume Core n'est pas active, aucun contenu créé.\n";
	return;
}

/**
 * Renvoie l'ID d'un contenu existant, ou le crée. Le contenu existant est cherché par type,
 * par slug (sauf si $metas contient 'sans_slug') et par les métadonnées listées dans $metas.
 *
 * @param array    $donnees Arguments de wp_insert_post (post_type, post_name, meta_input…).
 * @param string[] $metas   Métadonnées de meta_input qui identifient le contenu (rattachement, numéro).
 * @return int
 */
function yume_demo_contenu( array $donnees, array $metas = array() ): int {
	$requete = array(
		'post_type'      => $donnees['post_type'],
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	);
	if ( ! in_array( 'sans_slug', $metas, true ) ) {
		$requete['name'] = $donnees['post_name'];
	}
	foreach ( array_diff( $metas, array( 'sans_slug' ) ) as $cle ) {
		$requete['meta_query'][] = array(
			'key'   => $cle,
			'value' => (string) $donnees['meta_input'][ $cle ],
		);
	}
	$existants = get_posts( $requete );
	if ( $existants ) {
		return (int) $existants[0];
	}
	$id = wp_insert_post( wp_slash( $donnees ), true );
	if ( is_wp_error( $id ) ) {
		echo 'Démo : ' . esc_html( $id->get_error_message() ) . "\n";
		return 0;
	}
	return (int) $id;
}

/**
 * Paragraphe Gutenberg (classe facultative : yn-dialogue, yn-thought, yn-center).
 *
 * @param string $texte  Texte brut.
 * @param string $classe Classe CSS.
 * @return string
 */
function yume_demo_paragraphe( string $texte, string $classe = '' ): string {
	if ( '' === $classe ) {
		return "<!-- wp:paragraph -->\n<p>" . esc_html( $texte ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return '<!-- wp:paragraph {"className":"' . esc_attr( $classe ) . '"} -->' . "\n<p class=\"" . esc_attr( $classe ) . '">' . esc_html( $texte ) . "</p>\n<!-- /wp:paragraph -->\n\n";
}

/**
 * Séparateur de scène (core/separator, classe yn-scene-break).
 *
 * @return string
 */
function yume_demo_separateur(): string {
	return "<!-- wp:separator {\"className\":\"yn-scene-break\"} -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity yn-scene-break\"/>\n<!-- /wp:separator -->\n\n";
}

// Réglages du site.
update_option( 'blogname', 'Yume Novel (démo)' );
update_option( 'blogdescription', 'Fan-traductions françaises de light novels' );
update_option( 'timezone_string', 'Europe/Paris' );
update_option( 'date_format', 'j F Y' );
update_option( 'time_format', 'H:i' );
update_option( 'start_of_week', 1 );
update_option( 'users_can_register', 1 );
update_option( 'default_role', 'subscriber' );
if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
	update_option( 'permalink_structure', '/%postname%/' );
}

// Comptes de test.
$yume_demo_comptes = array(
	'equipe'  => get_role( 'yume_editeur' ) ? 'yume_editeur' : 'editor',
	'lecteur' => 'subscriber',
);
$yume_demo_ids     = array();
foreach ( $yume_demo_comptes as $yume_demo_login => $yume_demo_role ) {
	$yume_demo_user = get_user_by( 'login', $yume_demo_login );
	if ( $yume_demo_user ) {
		$yume_demo_ids[ $yume_demo_login ] = (int) $yume_demo_user->ID;
		continue;
	}
	$yume_demo_id = wp_insert_user(
		array(
			'user_login'   => $yume_demo_login,
			'user_pass'    => $yume_demo_login,
			'user_email'   => $yume_demo_login . '@example.org',
			'display_name' => 'equipe' === $yume_demo_login ? 'Équipe démo' : 'Lecteur démo',
			'role'         => $yume_demo_role,
		)
	);

	$yume_demo_ids[ $yume_demo_login ] = is_wp_error( $yume_demo_id ) ? 0 : (int) $yume_demo_id;
}
$yume_demo_equipe = $yume_demo_ids['equipe'] ? $yume_demo_ids['equipe'] : 1;

// Œuvre fictive.
$yume_demo_credits = array(
	'traduction' => 'Équipe démo',
	'relecture'  => 'Équipe démo',
	'edition'    => 'Équipe démo',
);
$yume_demo_oeuvre  = yume_demo_contenu(
	array(
		'post_type'    => 'yume_oeuvre',
		'post_name'    => 'lanternes-de-brume-haute',
		'post_title'   => 'Les Lanternes de Brume-Haute',
		'post_status'  => 'publish',
		'post_excerpt' => 'Dans un village de montagne, les lanternes s’allument seules la nuit où quelqu’un doit partir.',
		'post_content' => yume_demo_paragraphe( 'Dans le village de Brume-Haute, chaque maison garde une lanterne de papier. Quand l’une d’elles s’allume seule, quelqu’un doit quitter la vallée avant l’aube.' )
			. yume_demo_paragraphe( 'Œuvre fictive créée pour la démonstration du site : aucun texte réel n’est reproduit.' ),
		'meta_input'   => array(
			'yume_titres_alt'        => array( 'Brume-Haute no Chōchin', 'The Lanterns of High Mist' ),
			'yume_auteur'            => 'Auteur fictif',
			'yume_illustrateur'      => 'Illustratrice fictive',
			'yume_editeur_vo'        => 'Éditions de démonstration',
			'yume_nb_tomes_vo'       => 3,
			'yume_statut_vo'         => 'en_cours',
			'yume_jours_sortie'      => array( 'samedi' ),
			'yume_liens'             => array(),
			'yume_source_traduction' => 'Texte original de démonstration',
			'yume_equipe'            => $yume_demo_credits,
		),
	)
);

if ( $yume_demo_oeuvre ) {
	$yume_demo_termes = array(
		'yume_type'   => 'light-novel',
		'yume_statut' => 'en-cours',
		'yume_genre'  => 'fantasy',
	);
	foreach ( $yume_demo_termes as $yume_demo_taxonomie => $yume_demo_terme ) {
		if ( taxonomy_exists( $yume_demo_taxonomie ) ) {
			wp_set_object_terms( $yume_demo_oeuvre, $yume_demo_terme, $yume_demo_taxonomie );
		}
	}

	// Tome 1 : publié, avec liens de téléchargement externes.
	$yume_demo_tome1 = yume_demo_contenu(
		array(
			'post_type'    => 'yume_tome',
			'post_name'    => 'tome-1',
			'post_title'   => 'Les Lanternes de Brume-Haute — Tome 1',
			'post_status'  => 'publish',
			'post_excerpt' => 'La première lanterne s’allume chez la famille Aoki.',
			'menu_order'   => 1,
			'meta_input'   => array(
				'yume_oeuvre_id'    => $yume_demo_oeuvre,
				'yume_numero'       => 1,
				'yume_nature'       => 'tome',
				'yume_lien_pdf'     => 'https://example.org/yume-demo/lanternes-tome-1.pdf',
				'yume_lien_epub'    => 'https://example.org/yume-demo/lanternes-tome-1.epub',
				'yume_credits'      => $yume_demo_credits,
				'yume_etape'        => 'publie',
				'yume_avancement'   => array(
					'traduction' => 100,
					'relecture'  => 100,
					'edition'    => 100,
				),
				'yume_responsables' => array(
					'traduction' => $yume_demo_equipe,
					'relecture'  => $yume_demo_equipe,
					'edition'    => $yume_demo_equipe,
				),
			),
		),
		array( 'yume_oeuvre_id' )
	);

	// Deux chapitres du tome 1.
	$yume_demo_chapitres = array(
		1 => array(
			'titre'      => 'Chapitre 1',
			'sous_titre' => 'La lanterne éteinte',
			'contenu'    => yume_demo_paragraphe( 'Le vent descendait des crêtes en sifflant entre les toits. Sora referma la porte de l’atelier et souffla sur ses doigts gelés.' )
				. yume_demo_paragraphe( "—\u{00A0}Tu as vu la lanterne des Aoki ? demanda sa sœur depuis l’escalier.", 'yn-dialogue' )
				. yume_demo_paragraphe( "—\u{00A0}Elle est éteinte depuis des années.", 'yn-dialogue' )
				. yume_demo_paragraphe( 'Pourtant, ce soir, elle brillait.', 'yn-thought' )
				. yume_demo_separateur()
				. yume_demo_paragraphe( 'Au matin, la place du village était couverte de givre, et personne n’osait parler de la lumière.' ),
		),
		2 => array(
			'titre'      => 'Chapitre 2',
			'sous_titre' => 'Ceux qui partent avant l’aube',
			'contenu'    => yume_demo_paragraphe( 'La tradition voulait que l’on prépare un baluchon, une gourde et une lettre pour ceux qui restaient.' )
				. yume_demo_paragraphe( "—\u{00A0}Je ne partirai pas, dit Sora. Pas sans savoir pourquoi.", 'yn-dialogue' )
				. yume_demo_paragraphe( 'Fin de l’extrait de démonstration', 'yn-center' ),
		),
	);
	if ( $yume_demo_tome1 ) {
		foreach ( $yume_demo_chapitres as $yume_demo_numero => $yume_demo_chapitre ) {
			yume_demo_contenu(
				array(
					'post_type'    => 'yume_chapitre',
					'post_name'    => 'chapitre-' . $yume_demo_numero,
					'post_title'   => $yume_demo_chapitre['titre'] . ' — ' . $yume_demo_chapitre['sous_titre'],
					'post_status'  => 'publish',
					'post_content' => $yume_demo_chapitre['contenu'],
					'menu_order'   => $yume_demo_numero,
					'meta_input'   => array(
						'yume_tome_id'    => $yume_demo_tome1,
						'yume_oeuvre_id'  => $yume_demo_oeuvre,
						'yume_numero'     => $yume_demo_numero,
						'yume_sous_titre' => $yume_demo_chapitre['sous_titre'],
						'yume_nature'     => 'chapitre',
						'yume_credits'    => $yume_demo_credits,
					),
				),
				array( 'sans_slug', 'yume_tome_id', 'yume_numero' )
			);
		}
	}

	// Tome 2 : planifié (brouillon), en cours de traduction.
	yume_demo_contenu(
		array(
			'post_type'   => 'yume_tome',
			'post_name'   => 'tome-2',
			'post_title'  => 'Les Lanternes de Brume-Haute — Tome 2',
			'post_status' => 'draft',
			'menu_order'  => 2,
			'meta_input'  => array(
				'yume_oeuvre_id'    => $yume_demo_oeuvre,
				'yume_numero'       => 2,
				'yume_nature'       => 'tome',
				'yume_etape'        => 'traduction',
				'yume_avancement'   => array(
					'traduction' => 40,
					'relecture'  => 0,
					'edition'    => 0,
				),
				'yume_responsables' => array(
					'traduction' => $yume_demo_equipe,
					'relecture'  => 0,
					'edition'    => 0,
				),
				'yume_date_cible'   => wp_date( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ),
				'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
				'yume_maj_par'      => $yume_demo_equipe,
			),
		),
		array( 'yume_oeuvre_id' )
	);
}

// Article d'actualité lié à l'œuvre.
$yume_demo_article = yume_demo_contenu(
	array(
		'post_type'    => 'post',
		'post_name'    => 'bienvenue-sur-la-demo',
		'post_title'   => 'Bienvenue sur la démo de Yume Novel',
		'post_status'  => 'publish',
		'post_content' => yume_demo_paragraphe( 'Ce site de démonstration présente la bibliothèque, le lecteur en ligne, le planning et l’espace équipe de Yume Novel.' )
			. yume_demo_paragraphe( 'Comptes de test : equipe / equipe (équipe) et lecteur / lecteur (lecteur).' ),
	)
);
if ( $yume_demo_article && $yume_demo_oeuvre && taxonomy_exists( 'yume_oeuvre_liee' ) ) {
	wp_set_object_terms( $yume_demo_article, 'lanternes-de-brume-haute', 'yume_oeuvre_liee' );
}

// Pages du contrat (§11) : les mêmes que la migration (Migration_Planner::PAGES_A_CREER, mêmes
// slugs, parents et contenus), créées par Yume Core si elles ne sont pas en ligne. Une page déjà
// présente à son adresse est reprise : aucun doublon si le script est relancé.
if ( function_exists( 'Yume\\Core\\Core\\recreer_pages_yume' ) ) {
	\Yume\Core\Core\recreer_pages_yume();
} else {
	echo "Démo : Yume Core ne sait pas créer les pages du contrat (module migration absent ?).\n";
}
$yume_demo_pages = get_option( 'yume_pages', array() );
$yume_demo_pages = is_array( $yume_demo_pages ) ? $yume_demo_pages : array();

// Réglages de lecture : accueil statique (front-page.html), page des articles « Actualités ».
if ( ! empty( $yume_demo_pages['accueil'] ) && ! empty( $yume_demo_pages['actualites'] ) ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $yume_demo_pages['accueil'] );
	update_option( 'page_for_posts', (int) $yume_demo_pages['actualites'] );
}
// Commentaires réservés aux comptes connectés, comme après la migration.
update_option( 'comment_registration', 1 );

// Pages institutionnelles du menu « Yume Novel » et « Contact » (parts/header.html) : contenu réel
// du site actuel (pages-institutionnelles.json, extrait de l'export par extraire-pages.php ; dans
// Playground, recopié dans le blueprint par construire.php), et version révisée livrée avec
// l'extension quand elle existe (FAQ), comme après la migration. Une page de démonstration
// laissée par une version précédente de ce script est remplacée ; une page modifiée est gardée.
$yume_demo_json = $GLOBALS['yume_demo_pages_json'] ?? '';
if ( '' === $yume_demo_json && is_readable( __DIR__ . '/pages-institutionnelles.json' ) ) {
	$yume_demo_json = (string) file_get_contents( __DIR__ . '/pages-institutionnelles.json' );
}
$yume_demo_reelles = json_decode( $yume_demo_json, true );
$yume_demo_reelles = is_array( $yume_demo_reelles ) ? $yume_demo_reelles : array();
if ( ! $yume_demo_reelles ) {
	echo "Démo : pages-institutionnelles.json absent, pages du menu non créées.\n";
}
foreach ( $yume_demo_reelles as $yume_demo_page ) {
	if ( ! is_array( $yume_demo_page ) || empty( $yume_demo_page['slug'] ) ) {
		continue;
	}
	$yume_demo_slug    = (string) $yume_demo_page['slug'];
	$yume_demo_contenu = (string) ( $yume_demo_page['contenu'] ?? '' );
	if ( is_callable( array( '\\Yume\\Core\\Migration\\Migration_Executor', 'contenu_revise' ) ) ) {
		$yume_demo_revise  = \Yume\Core\Migration\Migration_Executor::contenu_revise( $yume_demo_slug );
		$yume_demo_contenu = '' !== $yume_demo_revise ? $yume_demo_revise : $yume_demo_contenu;
	}
	$yume_demo_existe = get_page_by_path( $yume_demo_slug );
	if ( $yume_demo_existe && ! str_contains( (string) $yume_demo_existe->post_content, 'Page de démonstration.' ) ) {
		continue;
	}
	$yume_demo_donnees = array(
		'post_type'    => 'page',
		'post_name'    => $yume_demo_slug,
		'post_title'   => (string) ( $yume_demo_page['titre'] ?? $yume_demo_slug ),
		'post_content' => $yume_demo_contenu,
		'post_status'  => 'publish',
	);
	if ( $yume_demo_existe ) {
		$yume_demo_donnees['ID'] = (int) $yume_demo_existe->ID;
	}
	wp_insert_post( wp_slash( $yume_demo_donnees ) );
}

flush_rewrite_rules( false );

printf(
	"Démo Yume prête : œuvre %d, %d page(s), comptes equipe / equipe et lecteur / lecteur.\n",
	(int) $yume_demo_oeuvre,
	count( $yume_demo_pages )
);
