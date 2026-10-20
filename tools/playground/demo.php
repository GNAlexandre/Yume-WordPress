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
 * Pour la vue Statistiques de l'espace équipe : six comptes lecteurs fictifs (sans mot de passe
 * connu), favoris, positions de lecture, listes et 60 jours d'historique quotidien.
 * Fonctionnalités lecteurs (2.5) : quatre autres œuvres fictives (genres et types croisés, favoris
 * croisés d'au moins trois comptes : « Si vous avez aimé… » et « Pour vous »), chapitres publiés
 * après la dernière lecture du compte lecteur (rattrapage) et trois signalements de coquilles
 * (deux nouveaux, un corrigé).
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

// Genres de démonstration (taxonomie libre) : créés avec leur nom affiché avant d'être attribués.
$yume_demo_genres = array(
	'fantasy'       => 'Fantasy',
	'drame'         => 'Drame',
	'mystere'       => 'Mystère',
	'romance'       => 'Romance',
	'aventure'      => 'Aventure',
	'slice-of-life' => 'Slice of life',
);
if ( taxonomy_exists( 'yume_genre' ) ) {
	foreach ( $yume_demo_genres as $yume_demo_slug => $yume_demo_nom ) {
		if ( ! term_exists( $yume_demo_slug, 'yume_genre' ) ) {
			wp_insert_term( $yume_demo_nom, 'yume_genre', array( 'slug' => $yume_demo_slug ) );
		}
	}
}

if ( $yume_demo_oeuvre ) {
	$yume_demo_termes = array(
		'yume_type'   => 'light-novel',
		'yume_statut' => 'en-cours',
		'yume_genre'  => array( 'fantasy', 'mystere' ),
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

	// IDs des chapitres (positions de lecture des statistiques de démonstration).
	$yume_demo_chapitre_ids = array();
	if ( $yume_demo_tome1 ) {
		foreach ( $yume_demo_chapitres as $yume_demo_numero => $yume_demo_chapitre ) {
			$yume_demo_chapitre_ids[ $yume_demo_numero ] = yume_demo_contenu(
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

// Quatre autres œuvres fictives (genres et types croisés) : recommandations « Si vous avez
// aimé… » de la fiche et rubrique « Pour vous » ; chapitres datés (rattrapage). Chaque chapitre :
// [ sous-titre, publié il y a N jours, paragraphe ].
$yume_demo_autres     = array(
	'les-veilleurs-du-col'              => array(
		'titre'     => 'Les Veilleurs du Col',
		'resume'    => 'Au sommet d’un col enneigé, une poignée de gardiens veille sur un passage que nul ne doit franchir.',
		'type'      => 'light-novel',
		'statut'    => 'en-cours',
		'genres'    => array( 'fantasy', 'drame' ),
		'chapitres' => array(
			1 => array( 'Le relais de pierre', 30, 'Le relais tenait debout par habitude plus que par miracle. Ilan y monta la garde pour la première fois la nuit du solstice.' ),
			2 => array( 'La relève', 25, 'Personne ne vint le relever à l’aube. Il attendit, les yeux fixés sur le sentier où la neige effaçait chaque trace.' ),
			3 => array( 'Les traces dans la neige', 2, 'Des pas remontaient le col, trop légers pour un voyageur, trop réguliers pour une bête.' ),
			4 => array( 'Le signal', 1, 'Au sommet, le feu d’alarme brûlait déjà. Quelqu’un l’avait allumé avant lui.' ),
		),
	),
	'le-cartographe-des-marees'         => array(
		'titre'     => 'Le Cartographe des marées',
		'resume'    => 'Une apprentie cartographe découvre que ses cartes changent avec les marées, et que certaines îles n’existent qu’à marée basse.',
		'type'      => 'light-novel',
		'statut'    => 'en-cours',
		'genres'    => array( 'aventure', 'fantasy', 'mystere' ),
		'chapitres' => array(
			1 => array( 'L’encre salée', 40, 'Nami trempa sa plume dans l’encrier et la carte se mit à onduler sous ses doigts, comme une vague qui se retire.' ),
			2 => array( 'L’île de six heures', 20, 'À marée basse, une île surgit au large du port. À marée haute, elle disparut de la carte et de la mer.' ),
		),
	),
	'sous-le-cerisier-d-hiver'          => array(
		'titre'     => 'Sous le cerisier d’hiver',
		'resume'    => 'Chaque hiver, un cerisier fleurit hors saison dans la cour d’un lycée de province. Deux élèves veulent comprendre pourquoi.',
		'type'      => 'web-novel',
		'statut'    => 'en-cours',
		'genres'    => array( 'drame', 'romance', 'slice-of-life' ),
		'chapitres' => array(
			1 => array( 'Des fleurs sur la neige', 15, 'Le matin de la rentrée de janvier, les branches du vieux cerisier étaient couvertes de fleurs roses.' ),
			2 => array( 'Le club de botanique', 8, 'Le club de botanique ne comptait que deux membres, et l’un d’eux ne venait jamais.' ),
			3 => array( 'La lettre sous l’écorce', 3, 'Entre deux racines, Haru trouva une enveloppe jaunie, adressée à quelqu’un qui portait son nom.' ),
		),
	),
	'la-bibliotheque-aux-sept-horloges' => array(
		'titre'     => 'La Bibliothèque aux sept horloges',
		'resume'    => 'Dans une bibliothèque où chaque horloge indique une heure différente, une archiviste range des livres qui n’ont pas encore été écrits.',
		'type'      => 'light-novel',
		'statut'    => 'terminee',
		'genres'    => array( 'mystere', 'slice-of-life' ),
		'chapitres' => array(
			1 => array( 'La septième horloge', 50, 'La septième horloge avançait de trois minutes chaque jour. L’archiviste notait l’écart dans un carnet relié de bleu.' ),
			2 => array( 'Le registre des livres à venir', 45, 'Le registre listait des titres que personne n’avait jamais lus, avec, en face, une date de retour.' ),
		),
	),
);
$yume_demo_autres_ids = array();
$yume_demo_autres_ch  = array();
foreach ( $yume_demo_autres as $yume_demo_slug => $yume_demo_def ) {
	$yume_demo_id = yume_demo_contenu(
		array(
			'post_type'    => 'yume_oeuvre',
			'post_name'    => $yume_demo_slug,
			'post_title'   => $yume_demo_def['titre'],
			'post_status'  => 'publish',
			'post_excerpt' => $yume_demo_def['resume'],
			'post_content' => yume_demo_paragraphe( $yume_demo_def['resume'] )
				. yume_demo_paragraphe( 'Œuvre fictive créée pour la démonstration du site : aucun texte réel n’est reproduit.' ),
			'meta_input'   => array(
				'yume_auteur'            => 'Auteur fictif',
				'yume_statut_vo'         => 'terminee' === $yume_demo_def['statut'] ? 'termine' : 'en_cours',
				'yume_source_traduction' => 'Texte original de démonstration',
				'yume_equipe'            => $yume_demo_credits,
			),
		)
	);
	if ( ! $yume_demo_id ) {
		continue;
	}
	$yume_demo_autres_ids[ $yume_demo_slug ] = $yume_demo_id;
	foreach ( array(
		'yume_type'   => $yume_demo_def['type'],
		'yume_statut' => $yume_demo_def['statut'],
		'yume_genre'  => $yume_demo_def['genres'],
	) as $yume_demo_taxonomie => $yume_demo_terme ) {
		if ( taxonomy_exists( $yume_demo_taxonomie ) ) {
			wp_set_object_terms( $yume_demo_id, $yume_demo_terme, $yume_demo_taxonomie );
		}
	}
	$yume_demo_tome = yume_demo_contenu(
		array(
			'post_type'   => 'yume_tome',
			'post_name'   => 'tome-1',
			'post_title'  => $yume_demo_def['titre'] . ' — Tome 1',
			'post_status' => 'publish',
			'post_date'   => wp_date( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ),
			'menu_order'  => 1,
			'meta_input'  => array(
				'yume_oeuvre_id' => $yume_demo_id,
				'yume_numero'    => 1,
				'yume_nature'    => 'tome',
				'yume_credits'   => $yume_demo_credits,
				'yume_etape'     => 'publie',
			),
		),
		array( 'yume_oeuvre_id' )
	);
	if ( ! $yume_demo_tome ) {
		continue;
	}
	foreach ( $yume_demo_def['chapitres'] as $yume_demo_numero => $yume_demo_chapitre ) {
		$yume_demo_publie = time() - $yume_demo_chapitre[1] * DAY_IN_SECONDS;

		$yume_demo_autres_ch[ $yume_demo_slug ][ $yume_demo_numero ] = yume_demo_contenu(
			array(
				'post_type'     => 'yume_chapitre',
				'post_name'     => 'chapitre-' . $yume_demo_numero,
				'post_title'    => 'Chapitre ' . $yume_demo_numero . ' — ' . $yume_demo_chapitre[0],
				'post_status'   => 'publish',
				'post_date'     => wp_date( 'Y-m-d H:i:s', $yume_demo_publie ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $yume_demo_publie ),
				'post_content'  => yume_demo_paragraphe( $yume_demo_chapitre[2] )
					. yume_demo_paragraphe( 'Fin de l’extrait de démonstration', 'yn-center' ),
				'menu_order'    => $yume_demo_numero,
				'meta_input'    => array(
					'yume_tome_id'    => $yume_demo_tome,
					'yume_oeuvre_id'  => $yume_demo_id,
					'yume_numero'     => $yume_demo_numero,
					'yume_sous_titre' => $yume_demo_chapitre[0],
					'yume_nature'     => 'chapitre',
					'yume_credits'    => $yume_demo_credits,
				),
			),
			array( 'sans_slug', 'yume_tome_id', 'yume_numero' )
		);
	}
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

// Second article lié (plus ancien) : onglet « Actualités » de la fiche et page /actualites/.
$yume_demo_article_2 = yume_demo_contenu(
	array(
		'post_type'    => 'post',
		'post_name'    => 'lanternes-de-brume-haute-le-tome-2-en-relecture',
		'post_title'   => 'Lanternes de Brume Haute : le tome 2 en relecture',
		'post_status'  => 'publish',
		'post_date'    => wp_date( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
		'post_excerpt' => 'La traduction du tome 2 est terminée : place à la relecture avant la sortie prévue dans deux semaines.',
		'post_content' => yume_demo_paragraphe( 'La traduction du tome 2 est terminée : place à la relecture avant la sortie prévue dans deux semaines.' ),
	)
);
if ( $yume_demo_article_2 && $yume_demo_oeuvre && taxonomy_exists( 'yume_oeuvre_liee' ) ) {
	wp_set_object_terms( $yume_demo_article_2, 'lanternes-de-brume-haute', 'yume_oeuvre_liee' );
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

// Profil public du compte equipe (PAGE-04, consentement explicite) : /contributeurs/equipe-demo/,
// liste /contributeurs/ et page « L'équipe ».
if ( $yume_demo_ids['equipe'] && function_exists( 'Yume\\Core\\Social\\enregistrer_profil_public' ) ) {
	\Yume\Core\Social\enregistrer_profil_public(
		(int) $yume_demo_ids['equipe'],
		array(
			'public'  => true,
			'bio'     => 'Compte de démonstration de l’équipe : traduction et relecture des Lanternes de Brume Haute.',
			'x'       => '@YumeNovel',
			'arrivee' => '2024-09',
		)
	);
}

// Glossaire de l'œuvre (PAGE-05) : fixture synthétique tools/fixtures/glossaire-exemple.yaml
// (dans Playground, recopiée dans le blueprint par construire.php). Relancé : « inchangé ».
$yume_demo_glossaire = $GLOBALS['yume_demo_glossaire_yaml'] ?? '';
if ( '' === $yume_demo_glossaire && is_readable( dirname( __DIR__ ) . '/fixtures/glossaire-exemple.yaml' ) ) {
	$yume_demo_glossaire = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/glossaire-exemple.yaml' );
}
if ( $yume_demo_oeuvre && '' !== $yume_demo_glossaire && function_exists( 'Yume\\Core\\Glossaire\\importer' ) ) {
	\Yume\Core\Glossaire\importer(
		(int) $yume_demo_oeuvre,
		$yume_demo_glossaire,
		array(
			'user_id' => 0,
			'source'  => 'televersement',
			'note'    => 'Démonstration',
		)
	);
}

// Statistiques de l'espace équipe (?vue=statistiques) : comptes lecteurs fictifs inscrits ces
// dernières semaines, favoris datés (alertes immédiates, hebdomadaires ou aucune), positions de
// lecture, listes de lecture, puis 60 jours d'historique quotidien (calculer_jour()). Le trafic
// (vues, visiteurs) est simulé hors production : les blueprints déclarent WP_ENVIRONMENT_TYPE
// « staging ». Relancé : rien n'est dupliqué, l'historique est recalculé à l'identique.
if ( $yume_demo_oeuvre && function_exists( 'Yume\\Core\\Statistiques\\calculer_jour' ) && function_exists( 'Yume\\Core\\Social\\table_favoris' ) && function_exists( 'Yume\\Core\\Reader\\table_progression' ) ) {
	global $wpdb;
	$yume_demo_jour    = static fn( int $jours ): string => gmdate( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS );
	$yume_demo_ch1     = (int) ( $yume_demo_chapitre_ids[1] ?? 0 );
	$yume_demo_ch2     = (int) ( $yume_demo_chapitre_ids[2] ?? 0 );
	$yume_demo_tome_id = (int) ( $yume_demo_tome1 ?? 0 );
	// Identifiant => [ inscrit il y a N jours, favori il y a N jours (-1 : aucun),
	// fréquence d'alerte, chapitre lu (1, 2 ou 0), pourcentage, lu il y a N jours, liste système ].
	$yume_demo_lecteurs = array(
		'lecteur'        => array( 58, 55, 'immediat', 2, 50, 1, 'en_cours' ),
		'demo-lecteur-1' => array( 57, 41, 'immediat', 2, 100, 3, 'termine' ),
		'demo-lecteur-2' => array( 44, 30, 'hebdo', 1, 95, 12, 'en_cours' ),
		'demo-lecteur-3' => array( 36, 22, 'jamais', 1, 40, 40, 'a_lire' ),
		'demo-lecteur-4' => array( 20, 12, 'immediat', 2, 92, 2, 'en_cours' ),
		'demo-lecteur-5' => array( 9, 5, 'jamais', 0, 0, 0, 'a_lire' ),
		'demo-lecteur-6' => array( 4, -1, 'immediat', 1, 20, 1, '' ),
		'equipe'         => array( 60, 2, 'hebdo', 0, 0, 0, '' ),
	);
	$yume_demo_pseudos  = array(
		'demo-lecteur-1' => 'Sakura_Lit',
		'demo-lecteur-2' => 'Kenji',
		'demo-lecteur-3' => 'Mio des Brumes',
		'demo-lecteur-4' => 'Hoshi',
		'demo-lecteur-5' => 'Aoi',
		'demo-lecteur-6' => 'Lanterne_rouge',
	);
	foreach ( $yume_demo_lecteurs as $yume_demo_login => $yume_demo_l ) {
		$yume_demo_user = get_user_by( 'login', $yume_demo_login );
		if ( ! $yume_demo_user && isset( $yume_demo_pseudos[ $yume_demo_login ] ) ) {
			$yume_demo_uid  = wp_insert_user(
				array(
					'user_login'      => $yume_demo_login,
					'user_pass'       => wp_generate_password( 24 ),
					'user_email'      => $yume_demo_login . '@example.org',
					'display_name'    => $yume_demo_pseudos[ $yume_demo_login ],
					'role'            => 'subscriber',
					'user_registered' => $yume_demo_jour( $yume_demo_l[0] ),
				)
			);
			$yume_demo_user = is_wp_error( $yume_demo_uid ) ? false : get_userdata( (int) $yume_demo_uid );
		}
		if ( ! $yume_demo_user ) {
			continue;
		}
		$yume_demo_uid = (int) $yume_demo_user->ID;
		if ( 'equipe' !== $yume_demo_login ) {
			// Date d'inscription étalée : courbe des inscriptions de l'onglet Lecteurs.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->users, array( 'user_registered' => $yume_demo_jour( $yume_demo_l[0] ) ), array( 'ID' => $yume_demo_uid ) );
			clean_user_cache( $yume_demo_uid );
		}
		if ( $yume_demo_l[1] >= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace(
				\Yume\Core\Social\table_favoris(),
				array(
					'user_id'    => $yume_demo_uid,
					'oeuvre_id'  => $yume_demo_oeuvre,
					'frequence'  => $yume_demo_l[2],
					'created_at' => $yume_demo_jour( $yume_demo_l[1] ),
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}
		$yume_demo_chapitre = 2 === $yume_demo_l[3] ? $yume_demo_ch2 : ( 1 === $yume_demo_l[3] ? $yume_demo_ch1 : 0 );
		if ( $yume_demo_chapitre && $yume_demo_tome_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace(
				\Yume\Core\Reader\table_progression(),
				array(
					'user_id'     => $yume_demo_uid,
					'oeuvre_id'   => $yume_demo_oeuvre,
					'tome_id'     => $yume_demo_tome_id,
					'chapitre_id' => $yume_demo_chapitre,
					'paragraphe'  => 0,
					'pourcentage' => $yume_demo_l[4],
					'updated_at'  => $yume_demo_jour( $yume_demo_l[5] ),
				),
				array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
			);
		}
		if ( '' !== $yume_demo_l[6] && function_exists( 'Yume\\Core\\Social\\liste_systeme' ) ) {
			$yume_demo_liste = \Yume\Core\Social\liste_systeme( $yume_demo_uid, $yume_demo_l[6] );
			if ( $yume_demo_liste ) {
				\Yume\Core\Social\ajouter_a_liste( $yume_demo_liste, $yume_demo_oeuvre );
			}
		}
	}
	if ( function_exists( 'Yume\\Core\\Social\\recalculer_caches' ) ) {
		\Yume\Core\Social\recalculer_caches( $yume_demo_oeuvre );
	}
}

// Fonctionnalités lecteurs (2.5) : favoris croisés de comptes fictifs sur les autres œuvres
// (co-favoris d'au moins trois lecteurs : « N lecteurs la suivent aussi »), lecture du compte
// lecteur arrêtée avant les derniers chapitres des Veilleurs du Col (rattrapage : accueil et Mon
// compte), signalements de coquilles (un nouveau par lecteur, un corrigé). Relancé : favoris et
// positions remplacés, signalements jamais dupliqués.
if ( $yume_demo_oeuvre && $yume_demo_autres_ids && function_exists( 'Yume\\Core\\Social\\table_favoris' ) && function_exists( 'Yume\\Core\\Reader\\table_progression' ) ) {
	global $wpdb;
	$yume_demo_jour = static fn( int $jours ): string => gmdate( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS );
	$yume_demo_uid  = static function ( string $login ): int {
		$user = get_user_by( 'login', $login );
		return $user ? (int) $user->ID : 0;
	};
	// Œuvre => comptes qui la suivent (la fiche des Lanternes est suivie par lecteur,
	// demo-lecteur-1 à 5 et equipe : voir plus haut).
	$yume_demo_suivis = array(
		'les-veilleurs-du-col'              => array( 'demo-lecteur-1', 'demo-lecteur-2', 'demo-lecteur-3', 'demo-lecteur-4', 'demo-lecteur-6' ),
		'le-cartographe-des-marees'         => array( 'demo-lecteur-2', 'demo-lecteur-3', 'demo-lecteur-5', 'equipe' ),
		'sous-le-cerisier-d-hiver'          => array( 'lecteur', 'demo-lecteur-4', 'demo-lecteur-5', 'demo-lecteur-6' ),
		'la-bibliotheque-aux-sept-horloges' => array( 'demo-lecteur-1', 'demo-lecteur-6' ),
	);
	foreach ( $yume_demo_suivis as $yume_demo_slug => $yume_demo_logins ) {
		$yume_demo_id = (int) ( $yume_demo_autres_ids[ $yume_demo_slug ] ?? 0 );
		if ( ! $yume_demo_id ) {
			continue;
		}
		foreach ( $yume_demo_logins as $yume_demo_rang => $yume_demo_login ) {
			$yume_demo_user_id = $yume_demo_uid( $yume_demo_login );
			if ( ! $yume_demo_user_id ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace(
				\Yume\Core\Social\table_favoris(),
				array(
					'user_id'    => $yume_demo_user_id,
					'oeuvre_id'  => $yume_demo_id,
					'frequence'  => 'hebdo',
					'created_at' => $yume_demo_jour( 3 + 4 * $yume_demo_rang ),
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}
		if ( function_exists( 'Yume\\Core\\Social\\recalculer_caches' ) ) {
			\Yume\Core\Social\recalculer_caches( $yume_demo_id );
		}
	}

	// Compte lecteur : chapitre 2 des Veilleurs lu en entier il y a 10 jours ; les chapitres 3 et
	// 4, publiés depuis, apparaissent dans « Reprendre la lecture » (accueil) et « À rattraper ».
	$yume_demo_lecteur = $yume_demo_uid( 'lecteur' );
	$yume_demo_ch_veil = $yume_demo_autres_ch['les-veilleurs-du-col'] ?? array();
	if ( $yume_demo_lecteur && ! empty( $yume_demo_ch_veil[2] ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			\Yume\Core\Reader\table_progression(),
			array(
				'user_id'     => $yume_demo_lecteur,
				'oeuvre_id'   => (int) $yume_demo_autres_ids['les-veilleurs-du-col'],
				'tome_id'     => (int) yume_get_tome_id( (int) $yume_demo_ch_veil[2] ),
				'chapitre_id' => (int) $yume_demo_ch_veil[2],
				'paragraphe'  => 0,
				'pourcentage' => 100,
				'updated_at'  => $yume_demo_jour( 10 ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	// Signalements de coquilles (?vue=corrections) sur les chapitres des Lanternes : deux nouveaux,
	// un corrigé (paragraphe retouché depuis : pastille « Paragraphe modifié depuis »).
	if ( function_exists( 'Yume\\Core\\Corrections\\table_prete' ) && \Yume\Core\Corrections\table_prete() ) {
		$yume_demo_coquilles = array(
			array( 'demo-lecteur-2', 1, 0, 'Le vent descendait des crêtes en sifflant', 'Il manque une virgule avant « en sifflant », non ?', 'nouveau', 2, '' ),
			array( 'lecteur', 2, 1, 'Pas sans savoir pourquoi.', 'Espace insécable avant le point final ?', 'nouveau', 1, '' ),
			array( 'demo-lecteur-3', 1, 5, 'personne n’osait parler de la lumière', '« osais » au lieu de « osait ».', 'corrige', 6, 'Au matin, la place du village était couverte de givre, et personne n’osais parler de la lumière.' ),
		);
		$yume_demo_table_coq = \Yume\Core\Corrections\table_coquilles();
		foreach ( $yume_demo_coquilles as $yume_demo_c ) {
			$yume_demo_user_id  = $yume_demo_uid( $yume_demo_c[0] );
			$yume_demo_chapitre = (int) ( $yume_demo_chapitre_ids[ $yume_demo_c[1] ] ?? 0 );
			if ( ! $yume_demo_user_id || ! $yume_demo_chapitre ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$yume_demo_existe = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$yume_demo_table_coq} WHERE user_id = %d AND chapitre_id = %d AND paragraphe = %d", $yume_demo_user_id, $yume_demo_chapitre, $yume_demo_c[2] ) );
			if ( $yume_demo_existe ) {
				continue;
			}
			$yume_demo_texte = \Yume\Core\Corrections\texte_paragraphe( $yume_demo_chapitre, $yume_demo_c[2] );
			$yume_demo_avant = '' !== $yume_demo_c[7] ? $yume_demo_c[7] : $yume_demo_texte;
			$yume_demo_fait  = 'corrige' === $yume_demo_c[5];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$yume_demo_table_coq,
				array(
					'chapitre_id' => $yume_demo_chapitre,
					'tome_id'     => (int) yume_get_tome_id( $yume_demo_chapitre ),
					'oeuvre_id'   => (int) $yume_demo_oeuvre,
					'user_id'     => $yume_demo_user_id,
					'paragraphe'  => $yume_demo_c[2],
					'extrait'     => $yume_demo_c[3],
					'contexte'    => $yume_demo_avant,
					'empreinte'   => \Yume\Core\Corrections\empreinte_paragraphe( $yume_demo_avant ),
					'commentaire' => $yume_demo_c[4],
					'statut'      => $yume_demo_c[5],
					'cree_le'     => $yume_demo_jour( $yume_demo_c[6] ),
					'traite_le'   => $yume_demo_fait ? $yume_demo_jour( $yume_demo_c[6] - 2 ) : null,
					'traite_par'  => $yume_demo_fait ? (int) $yume_demo_equipe : 0,
				)
			);
		}
	}

	// Caches des recommandations et du rattrapage renouvelés (sans attendre la minute de tampon).
	if ( function_exists( 'Yume\\Core\\Recommandations\\renouveler_version' ) ) {
		\Yume\Core\Recommandations\renouveler_version();
	}
	if ( function_exists( 'Yume\\Core\\Rattrapage\\invalider_tous' ) ) {
		\Yume\Core\Rattrapage\invalider_tous();
	}
}

if ( $yume_demo_oeuvre && function_exists( 'Yume\\Core\\Statistiques\\calculer_jour' ) ) {
	global $wpdb;
	// Historique quotidien des 60 derniers jours (la veille comprise), comme la tâche de 03:10.
	// L'œuvre de démonstration vient d'être publiée : la simulation ne lui donne aucune vue les
	// jours précédents. Ses vues quotidiennes sont donc inventées (déterministes, en hausse), sauf
	// si le trafic est indisponible (colonne NULL).
	$yume_demo_table = \Yume\Core\Statistiques\table_stats_jour();
	for ( $yume_demo_n = 60; $yume_demo_n >= 1; --$yume_demo_n ) {
		$yume_demo_date = wp_date( 'Y-m-d', time() - $yume_demo_n * DAY_IN_SECONDS );
		\Yume\Core\Statistiques\calculer_jour( $yume_demo_date );
		$yume_demo_vues = 35 + (int) ( ( 60 - $yume_demo_n ) * 0.8 ) + crc32( 'yume-demo|' . $yume_demo_date ) % 40;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$yume_demo_table} SET vues = %d WHERE jour = %s AND oeuvre_id = %d AND vues IS NOT NULL", $yume_demo_vues, $yume_demo_date, $yume_demo_oeuvre ) );
	}
	\Yume\Core\Statistiques\cache_vider();
	update_option( \Yume\Core\Statistiques\OPTION_DERNIER_JOUR, wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), false );
}

flush_rewrite_rules( false );

printf(
	"Démo Yume prête : œuvre %d et %d autre(s), %d page(s), comptes equipe / equipe et lecteur / lecteur.\n",
	(int) $yume_demo_oeuvre,
	count( $yume_demo_autres_ids ),
	count( $yume_demo_pages )
);
