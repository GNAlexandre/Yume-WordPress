<?php
/**
 * Tests du module bibliothèque : enregistrement des blocs, rendu de chaque bloc avec et sans
 * données, brouillons exclus, filtres et pagination de la grille, liens (rel, externes),
 * cache invalidé, JSON-LD et balises rel=prev/next, partenaires de l'accueil (réglage, logos).
 *
 * Commande : tools/localenv/test.sh library
 *
 * Chaque test part d'une bibliothèque vide (contenus Yume supprimés dans la transaction
 * annulée à la fin du test) : les résultats ne dépendent pas des données de la base locale.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Library\accrocher_oeuvre_infos;
use function Yume\Core\Library\balise_jsonld;
use function Yume\Core\Library\balises_voisins;
use function Yume\Core\Library\date_courte;
use function Yume\Core\Library\donnees_structurees;
use function Yume\Core\Library\duree_lecture;
use function Yume\Core\Library\est_nouveau;
use function Yume\Core\Library\index_oeuvres;
use function Yume\Core\Library\initiales;
use function Yume\Core\Library\logos_formulaire_partenaires;
use function Yume\Core\Library\normaliser_filtres;
use function Yume\Core\Library\partenaires;
use function Yume\Core\Library\renouveler_version;
use function Yume\Core\Library\resoudre_logo_partenaire;
use function Yume\Core\Library\sous_titre_tome;
use function Yume\Core\Library\version_cache;

// Module non chargé (YUME_ONLY_MODULES sans « library ») : rien à tester.
if ( ! function_exists( 'Yume\Core\Library\rendu_banner' ) ) {
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tl_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test de la bibliothèque : bibliothèque vide au départ, aucune notification
 * (événements métier), paramètres GET et requête principale restaurés à la fin.
 *
 * @param string   $nom   Nom du test.
 * @param callable $corps Corps du test.
 */
function yume_tl_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wp_query, $wp_the_query, $post;
			$get      = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requete  = $wp_query;
			$reelle   = $wp_the_query;
			$post_sav = $post;
			add_filter( 'yume_core_notifier', '__return_false' );
			try {
				yume_tl_vider();
				$corps();
			} finally {
				remove_filter( 'yume_core_notifier', '__return_false' );
				$_GET         = $get;
				$wp_query     = $requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$wp_the_query = $reelle; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$post         = $post_sav; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				renouveler_version();
			}
		}
	);
}

/**
 * Supprime (dans la transaction du test) tous les contenus Yume et la bannière du site.
 */
function yume_tl_vider(): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('yume_oeuvre', 'yume_tome', 'yume_chapitre')" );
	if ( $ids ) {
		$liste = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($liste)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($liste)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($liste)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	$reglages = get_option( 'yume_reglages', array() );
	$reglages = is_array( $reglages ) ? $reglages : array();
	// 0 et non unset : l'assainissement des réglages conserve les clés absentes.
	$reglages['banniere_id'] = 0;
	update_option( 'yume_reglages', $reglages );
	wp_cache_flush();
	renouveler_version();
	wp_set_current_user( 0 );
}

/**
 * Crée une œuvre publiée avec ses termes.
 *
 * @param string $titre  Titre.
 * @param array  $termes taxonomie => slugs (yume_type, yume_statut, yume_genre).
 * @param array  $args   Arguments wp_insert_post supplémentaires.
 */
function yume_tl_oeuvre( string $titre, array $termes = array(), array $args = array() ): int {
	$id = yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'    => 'yume_oeuvre',
				'post_title'   => $titre,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Synopsis de ' . esc_html( $titre ) . '.</p><!-- /wp:paragraph -->',
			),
			$args
		)
	);
	foreach ( $termes as $taxonomie => $slugs ) {
		foreach ( (array) $slugs as $slug ) {
			if ( ! term_exists( $slug, $taxonomie ) ) {
				wp_insert_term( ucfirst( str_replace( '-', ' ', $slug ) ), $taxonomie, array( 'slug' => $slug ) );
			}
		}
		wp_set_object_terms( $id, (array) $slugs, $taxonomie );
	}
	return $id;
}

/**
 * Date locale « Y-m-d H:i:s » il y a $jours jours.
 *
 * @param float $jours Nombre de jours (négatif : futur).
 */
function yume_tl_date( float $jours ): string {
	return wp_date( 'Y-m-d H:i:s', (int) ( time() - $jours * DAY_IN_SECONDS ) );
}

/**
 * Crée un tome.
 *
 * @param int   $oeuvre_id Œuvre.
 * @param mixed $numero    Numéro.
 * @param array $args      Arguments (meta_input, post_status, post_date…).
 */
function yume_tl_tome( int $oeuvre_id, $numero, array $args = array() ): int {
	$nature = $args['meta_input']['yume_nature'] ?? 'tome';
	return yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre_id ) . ' — ' . ( 'arc' === $nature ? 'Arc ' : 'Tome ' ) . $numero,
				'post_name'   => ( 'arc' === $nature ? 'arc-' : 'tome-' ) . $numero,
				'post_status' => 'publish',
				'post_date'   => yume_tl_date( 30 ),
				'menu_order'  => (int) $numero,
				'meta_input'  => array(
					'yume_oeuvre_id' => $oeuvre_id,
					'yume_numero'    => $numero,
					'yume_nature'    => $nature,
				),
			),
			$args
		)
	);
}

/**
 * Crée un chapitre.
 *
 * @param int   $tome_id Tome.
 * @param mixed $numero  Numéro (null : spécial, nature dans $args).
 * @param array $args    Arguments.
 */
function yume_tl_chapitre( int $tome_id, $numero, array $args = array() ): int {
	$meta = array( 'yume_tome_id' => $tome_id );
	if ( null !== $numero ) {
		$meta['yume_numero'] = $numero;
		$meta['yume_nature'] = 'chapitre';
	}
	return yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => null === $numero ? 'Postface' : 'Chapitre ' . $numero,
				'post_name'    => null === $numero ? 'postface' : 'chapitre-' . $numero,
				'post_status'  => 'publish',
				'post_date'    => get_post_field( 'post_date', $tome_id ),
				'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Les gremlins chantaient tout autour d’eux. ', 60 ) . '</p><!-- /wp:paragraph -->',
				'menu_order'   => null === $numero ? 999 : (int) $numero,
				'meta_input'   => $meta,
			),
			$args
		)
	);
}

/**
 * Crée une pièce jointe image (sans fichier : métadonnées seules).
 *
 * @param string $nom     Nom du fichier.
 * @param string $alt     Texte alternatif.
 * @param string $legende Légende.
 */
function yume_tl_image( string $nom = 'image.jpg', string $alt = '', string $legende = '' ): int {
	$id = wp_insert_attachment(
		array(
			'post_title'     => $nom,
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
			'post_excerpt'   => $legende,
		),
		'2026/09/' . $nom
	);
	update_post_meta( $id, '_wp_attached_file', '2026/09/' . $nom );
	update_post_meta(
		$id,
		'_wp_attachment_metadata',
		array(
			'width'  => 480,
			'height' => 720,
			'file'   => '2026/09/' . $nom,
			'sizes'  => array(),
		)
	);
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return (int) $id;
}

/**
 * Rend un bloc de la bibliothèque, avec un contexte postId éventuel.
 *
 * @param string $nom     Nom court (« tome-list »).
 * @param array  $attrs   Attributs.
 * @param int    $post_id Contenu du contexte (0 : aucun).
 */
function yume_tl_rendu( string $nom, array $attrs = array(), int $post_id = 0 ): string {
	$contexte = $post_id ? array(
		'postId'   => $post_id,
		'postType' => get_post_type( $post_id ),
	) : array();
	$bloc     = new WP_Block(
		array(
			'blockName'    => 'yume/' . $nom,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		$contexte
	);
	return (string) $bloc->render();
}

/**
 * Fait de $post_id l'objet de la requête principale (page singulière).
 *
 * @param int $post_id Contenu.
 */
function yume_tl_aller( int $post_id ): void {
	global $wp_query, $wp_the_query, $post;
	$wp_query     = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => array( 'publish', 'draft', 'future' ),
		)
	);
	$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$post         = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}

/**
 * Nombre d'occurrences d'une sous-chaîne.
 *
 * @param string $aiguille Sous-chaîne.
 * @param string $texte    Texte.
 */
function yume_tl_compte( string $aiguille, string $texte ): int {
	return substr_count( $texte, $aiguille );
}

/**
 * Extrait le JSON-LD d'une balise <script> et le décode.
 *
 * @param string $html Balise.
 * @return array
 */
function yume_tl_jsonld( string $html ): array {
	yume_assert_true( 1 === preg_match( '#<script type="application/ld\+json"[^>]*>(.*)</script>#s', $html, $m ), 'balise JSON-LD présente' );
	$donnees = json_decode( $m[1], true );
	yume_assert_true( is_array( $donnees ), 'JSON valide : ' . json_last_error_msg() );
	return $donnees;
}

/**
 * Nœud du @graph d'un type donné.
 *
 * @param array  $document Document JSON-LD.
 * @param string $type     @type.
 * @return array
 * @throws Yume_Test_Failure Si le nœud est absent.
 */
function yume_tl_noeud( array $document, string $type ): array {
	foreach ( $document['@graph'] ?? array() as $noeud ) {
		if ( ( $noeud['@type'] ?? '' ) === $type ) {
			return $noeud;
		}
	}
	throw new Yume_Test_Failure( 'nœud ' . $type . ' absent du JSON-LD' );
}

/*
 * -----------------------------------------------------------------------------
 * Enregistrement
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'enregistre les 13 blocs du module (apiVersion 3, catégorie yume, rendu serveur, style)',
	static function () {
		$registre = WP_Block_Type_Registry::get_instance();
		foreach ( array( 'library-menu', 'banner', 'latest-releases', 'library-grid', 'oeuvre-header', 'oeuvre-infos', 'tome-list', 'tome-header', 'tome-toc', 'chapter-header', 'chapter-nav', 'tome-illustrations', 'partenaires' ) as $nom ) {
			$type = $registre->get_registered( 'yume/' . $nom );
			yume_assert_true( $type instanceof WP_Block_Type, 'yume/' . $nom . ' enregistré' );
			yume_assert_same( 'yume', $type->category, 'catégorie de ' . $nom );
			yume_assert_same( 3, (int) $type->api_version, 'apiVersion de ' . $nom );
			yume_assert_true( is_callable( $type->render_callback ), 'rendu serveur de ' . $nom );
			yume_assert_true( in_array( 'yume/' . $nom, $GLOBALS['yume_dynamic_blocks'], true ), 'enregistré via yume_register_dynamic_block : ' . $nom );
			yume_assert_false( (bool) ( $type->supports['html'] ?? true ), 'supports.html = false : ' . $nom );
			yume_assert_true( count( $type->style_handles ) > 0, 'feuille de style : ' . $nom );
		}
		yume_assert_same( 240, $registre->get_registered( 'yume/banner' )->attributes['height']['default'] );
		yume_assert_same( 6, $registre->get_registered( 'yume/latest-releases' )->attributes['count']['default'] );
		yume_assert_same( 24, $registre->get_registered( 'yume/library-grid' )->attributes['perPage']['default'] );
		yume_assert_true( $registre->get_registered( 'yume/library-grid' )->attributes['showFilters']['default'] );
		yume_assert_true( wp_style_is( 'yume-bibliotheque', 'registered' ), 'feuille commune enregistrée' );
		yume_assert_true( in_array( 'yume-bibliotheque', $registre->get_registered( 'yume/tome-list' )->style_handles, true ), 'feuille commune déclarée' );
		yume_assert_true( count( $registre->get_registered( 'yume/library-menu' )->view_script_handles ) > 0, 'script du menu' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * yume/banner
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'banner : rien sans bannière ; image pleine largeur, hauteur réglable et bornée sinon',
	static function () {
		yume_assert_same( '', yume_tl_rendu( 'banner' ), 'aucune bannière' );

		$reglages                = (array) get_option( 'yume_reglages', array() );
		$reglages['banniere_id'] = yume_tl_image( 'banniere.jpg', 'Un nouvel élan pour Yume' );
		update_option( 'yume_reglages', $reglages );

		$html = yume_tl_rendu( 'banner', array( 'height' => 300 ) );
		yume_assert_contains( 'class="yn-banner wp-block-yume-banner"', $html );
		yume_assert_contains( '--yn-banner-hauteur:300px', $html );
		yume_assert_contains( 'alt="Un nouvel élan pour Yume"', $html );
		yume_assert_contains( 'fetchpriority="high"', $html );
		yume_assert_not_contains( 'loading="lazy"', $html );
		yume_assert_contains( '--yn-banner-hauteur:240px', yume_tl_rendu( 'banner' ), 'hauteur par défaut' );
		yume_assert_contains( '--yn-banner-hauteur:800px', yume_tl_rendu( 'banner', array( 'height' => 5000 ) ), 'hauteur bornée' );

		$reglages['banniere_id'] = yume_factory_post( array( 'post_type' => 'page' ) );
		update_option( 'yume_reglages', $reglages );
		yume_assert_same( '', yume_tl_rendu( 'banner' ), 'un contenu qui n’est pas une image est ignoré' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * yume/latest-releases
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'latest-releases : tri par date, badge « Nouveau » (< 7 j), boutons Lire / PDF / EPUB, brouillons exclus',
	static function () {
		yume_assert_contains( 'Aucune sortie pour le moment.', yume_tl_rendu( 'latest-releases' ), 'état vide' );

		$couv   = yume_tl_image( 'couv.jpg' );
		$oeuvre = yume_tl_oeuvre( 'Grimgar of Fantasy and Ash', array( 'yume_type' => 'light-novel' ) );
		$t1     = yume_tl_tome(
			$oeuvre,
			1,
			array(
				'post_date'  => yume_tl_date( 20 ),
				'meta_input' => array(
					'yume_lien_pdf'  => 'https://www.clictune.com/pdf1',
					'yume_lien_epub' => '',
				),
			)
		);
		$t2     = yume_tl_tome(
			$oeuvre,
			2,
			array(
				'post_date'  => yume_tl_date( 2 ),
				'meta_input' => array(
					'yume_lien_pdf'  => 'https://www.clictune.com/pdf2',
					'yume_lien_epub' => 'https://www.clictune.com/epub2',
				),
			)
		);
		set_post_thumbnail( $t2, $couv );
		$c1 = yume_tl_chapitre( $t2, 1 );
		yume_tl_chapitre( $t2, 2 );
		yume_tl_tome( $oeuvre, 3, array( 'post_status' => 'draft' ) );
		$cachee = yume_tl_oeuvre( 'Œuvre en préparation', array(), array( 'post_status' => 'draft' ) );
		yume_tl_tome( $cachee, 1, array( 'post_date' => yume_tl_date( 1 ) ) );

		$html = yume_tl_rendu( 'latest-releases' );
		yume_assert_contains( 'class="yn-releases wp-block-yume-latest-releases"', $html );
		yume_assert_contains( '<ul class="yn-grid-covers yn-releases__liste">', $html );
		yume_assert_same( 2, yume_tl_compte( '<li class="yn-releases__item">', $html ), 'deux sorties publiées' );
		yume_assert_true( strpos( $html, get_permalink( $t2 ) ) < strpos( $html, get_permalink( $t1 ) ), 'la plus récente d’abord' );
		yume_assert_not_contains( 'tome-3', $html, 'tome brouillon exclu' );
		yume_assert_not_contains( 'Œuvre en préparation', $html, 'œuvre brouillon exclue' );
		yume_assert_same( 1, yume_tl_compte( 'yn-chip--new', $html ), 'un seul badge « Nouveau »' );
		yume_assert_contains( 'alt="Couverture : Grimgar of Fantasy and Ash, Tome 2"', $html, 'texte alternatif de la couverture' );
		yume_assert_contains( '<span class="yn-cover__texte" aria-hidden="true">Grimgar of Fantasy and Ash · T.1</span>', $html, 'couverture de substitution' );
		yume_assert_contains( '<h3 class="yn-releases__titre">Grimgar of Fantasy and Ash</h3>', $html );
		yume_assert_contains( 'Tome 2 · <time datetime="', $html );
		yume_assert_contains( esc_html( date_courte( (int) get_post_time( 'U', true, $t2 ) ) ) . '</time>', $html, 'date « j M »' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $c1 ) ) . '">Lire', $html, 'Lire : premier chapitre publié' );
		yume_assert_contains( 'href="https://www.clictune.com/epub2" target="_blank" rel="noopener">EPUB', $html, 'EPUB externe' );
		yume_assert_contains( '(lien externe, nouvel onglet)', $html, 'indication de lien externe' );
		yume_assert_same( 2, yume_tl_compte( 'yn-telechargement--pdf', $html ), 'deux PDF' );
		yume_assert_same( 1, yume_tl_compte( 'yn-telechargement--epub', $html ), 'EPUB absent si lien vide' );
		yume_assert_same( 1, yume_tl_compte( 'yn-lire', $html ), 'pas de bouton Lire sans chapitre' );

		yume_assert_same( 1, yume_tl_compte( '<li class="yn-releases__item">', yume_tl_rendu( 'latest-releases', array( 'count' => 1 ) ) ), 'nombre limité' );
	}
);

yume_tl_test(
	'latest-releases : un arc de web novel est regroupé, daté de son dernier chapitre et marqué « Arc en cours »',
	static function () {
		$wn  = yume_tl_oeuvre( 'Secrets of the Silent Witch', array( 'yume_type' => 'web-novel' ) );
		$arc = yume_tl_tome(
			$wn,
			7,
			array(
				'post_date'  => yume_tl_date( 20 ),
				'meta_input' => array( 'yume_nature' => 'arc' ),
			)
		);
		yume_tl_chapitre( $arc, 1, array( 'post_date' => yume_tl_date( 20 ) ) );
		$c2 = yume_tl_chapitre( $arc, 2, array( 'post_date' => yume_tl_date( 1 ) ) );
		yume_tl_chapitre( $arc, 3, array( 'post_status' => 'draft' ) );
		$ln = yume_tl_oeuvre( 'Raven of the Inner Palace', array( 'yume_type' => 'light-novel' ) );
		yume_tl_tome( $ln, 6, array( 'post_date' => yume_tl_date( 3 ) ) );

		$html = yume_tl_rendu( 'latest-releases' );
		yume_assert_same( 2, yume_tl_compte( '<li class="yn-releases__item">', $html ), 'une carte par tome ou arc' );
		yume_assert_true( strpos( $html, 'Secrets of the Silent Witch' ) < strpos( $html, 'Raven of the Inner Palace' ), 'l’arc est daté de son dernier chapitre' );
		yume_assert_contains( 'Arc 7 · ch. 2 · <time', $html );
		yume_assert_contains( 'Arc en cours', $html );
		yume_assert_same( 2, yume_tl_compte( 'yn-chip--new', $html ), 'les deux sorties ont moins de 7 jours' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $c2 ) ) . '">Lire<span class="yn-visually-hidden"> — Chapitre 2 de Secrets of the Silent Witch, Arc 7</span>', $html, 'Lire : dernier chapitre d’un arc en cours' );
	}
);

yume_tl_test(
	'latest-releases : un tome déjà publié (PDF seul) mis en lecture en ligne est daté de cette sortie (UX-2)',
	static function () {
		$oeuvre = yume_tl_oeuvre( 'Grimgar of Fantasy and Ash', array( 'yume_type' => 'light-novel' ) );
		$t7     = yume_tl_tome( $oeuvre, 7, array( 'post_date' => yume_tl_date( 19 ) ) );
		$t9     = yume_tl_tome( $oeuvre, 9, array( 'post_date' => yume_tl_date( 5 ) ) );
		$raven  = yume_tl_oeuvre( 'Raven of the Inner Palace', array( 'yume_type' => 'light-novel' ) );
		$r6     = yume_tl_tome( $raven, 6, array( 'post_date' => yume_tl_date( 13 ) ) );
		// Programmation future d'un autre tome publié : ne le date pas (sortie non passée).
		update_post_meta(
			$r6,
			'_yume_publication',
			array(
				'sortie'    => gmdate( DATE_ATOM, time() + 3 * DAY_IN_SECONDS ),
				'sortie_le' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$html = yume_tl_rendu( 'latest-releases' );
		yume_assert_true( strpos( $html, get_permalink( $t9 ) ) < strpos( $html, get_permalink( $t7 ) ), 'avant la sortie en ligne : T9 d’abord' );
		yume_assert_true( strpos( $html, get_permalink( $r6 ) ) < strpos( $html, get_permalink( $t7 ) ), 'avant la sortie en ligne : Raven T6 avant T7' );
		yume_assert_same( 1, yume_tl_compte( 'yn-chip--new', $html ), 'seul T9 est nouveau' );

		// « Publier maintenant » (module publication) : chapitres publiés maintenant, métadonnée de sortie.
		$maintenant = time() - 60;
		yume_tl_chapitre( $t7, 1, array( 'post_date' => wp_date( 'Y-m-d H:i:s', $maintenant ) ) );
		update_post_meta(
			$t7,
			'_yume_publication',
			array(
				'sortie'     => 'maintenant',
				'sortie_par' => 1,
				'sortie_le'  => gmdate( 'Y-m-d H:i:s', $maintenant ),
			)
		);
		yume_assert_same( $maintenant, \Yume\Core\Library\date_sortie_tome( $t7 ), 'date de sortie effective (sortie_le, UTC)' );
		yume_assert_same( (int) get_post_time( 'U', true, $r6 ), \Yume\Core\Library\date_sortie_tome( $r6 ), 'sortie programmée à venir ignorée' );

		$html = yume_tl_rendu( 'latest-releases' );
		yume_assert_true( strpos( $html, get_permalink( $t7 ) ) < strpos( $html, get_permalink( $t9 ) ), 'la sortie du jour en tête (cache invalidé)' );
		yume_assert_same( 2, yume_tl_compte( 'yn-chip--new', $html ), 'badge « Nouveau » sur T7' );
		yume_assert_contains( 'Tome 7 · <time datetime="' . esc_attr( gmdate( 'c', $maintenant ) ) . '">' . esc_html( date_courte( $maintenant ) ) . '</time>', $html, 'datée du jour de la sortie en ligne' );

		// Sortie programmée passée d'un tome déjà publié : datée de la date prévue.
		$prevue = time() - 2 * DAY_IN_SECONDS;
		update_post_meta(
			$r6,
			'_yume_publication',
			array(
				'sortie'    => gmdate( DATE_ATOM, $prevue ),
				'sortie_le' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ),
			)
		);
		yume_assert_same( $prevue, \Yume\Core\Library\date_sortie_tome( $r6 ), 'sortie programmée passée' );
		$html = yume_tl_rendu( 'latest-releases' );
		yume_assert_true( strpos( $html, get_permalink( $r6 ) ) < strpos( $html, get_permalink( $t9 ) ), 'Raven T6 remonte après sa sortie programmée' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * yume/library-grid
 * -----------------------------------------------------------------------------
 */

/**
 * Jeu de données commun aux tests de la grille.
 *
 * @return array<string,int>
 */
function yume_tl_bibliotheque(): array {
	$ids = array(
		'grimgar' => yume_tl_oeuvre(
			'Grimgar of Fantasy and Ash',
			array(
				'yume_type'   => 'light-novel',
				'yume_statut' => 'en-cours',
				'yume_genre'  => array( 'fantasy', 'drame' ),
			),
			array( 'post_date' => yume_tl_date( 60 ) )
		),
		'raven'   => yume_tl_oeuvre(
			'Raven of the Inner Palace',
			array(
				'yume_type'   => 'light-novel',
				'yume_statut' => 'terminee',
				'yume_genre'  => 'fantasy',
			),
			array( 'post_date' => yume_tl_date( 50 ) )
		),
		'manga'   => yume_tl_oeuvre(
			'Alya Manga',
			array(
				'yume_type'   => 'manga',
				'yume_statut' => 'licenciee',
			),
			array( 'post_date' => yume_tl_date( 40 ) )
		),
		'witch'   => yume_tl_oeuvre(
			'Silent Witch',
			array(
				'yume_type'   => 'web-novel',
				'yume_statut' => 'en-pause',
			),
			array( 'post_date' => yume_tl_date( 30 ) )
		),
	);
	yume_tl_tome( $ids['grimgar'], 1, array( 'post_date' => yume_tl_date( 5 ) ) );
	yume_tl_tome( $ids['grimgar'], 2, array( 'post_date' => yume_tl_date( 4 ) ) );
	yume_tl_oeuvre(
		'Brouillon caché',
		array( 'yume_type' => 'light-novel' ),
		array( 'post_status' => 'draft' )
	);
	return $ids;
}

yume_tl_test(
	'library-grid : grille de couvertures, badge de statut, compteurs, brouillons exclus',
	static function () {
		yume_assert_contains( 'Aucune œuvre publiée pour le moment.', yume_tl_rendu( 'library-grid' ), 'état vide' );
		$ids  = yume_tl_bibliotheque();
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_contains( 'class="yn-library wp-block-yume-library-grid"', $html );
		yume_assert_contains( '<ul class="yn-grid-covers yn-library__grille">', $html );
		yume_assert_same( 4, yume_tl_compte( '<li class="yn-library__item">', $html ) );
		yume_assert_not_contains( 'Brouillon caché', $html );
		yume_assert_contains( '>4 œuvres</h2>', $html );
		yume_assert_contains( 'yn-chip yn-chip--ok yn-statut yn-statut--en-cours yn-library__statut"><span aria-hidden="true">●</span> En cours', $html, 'badge de statut (icône + libellé)' );
		yume_assert_contains( 'Light novel · 2 tomes', $html, 'type et tomes publiés' );
		yume_assert_contains( 'Light novels <span class="yn-library__nombre">2<span class="yn-visually-hidden"> œuvres</span>', $html, 'compteur du type' );
		yume_assert_contains( 'Séries en cours <span class="yn-library__nombre">2', $html, 'en cours + en pause' );
		yume_assert_contains( 'Licenciées / abandonnées <span class="yn-library__nombre">1', $html );
		yume_assert_contains( 'Fantasy <span class="yn-library__nombre">2', $html, 'compteur du genre' );
		yume_assert_true( strpos( $html, 'Grimgar of Fantasy' ) < strpos( $html, 'Silent Witch' ) && strpos( $html, 'Silent Witch' ) < strpos( $html, 'Alya Manga' ) && strpos( $html, 'Alya Manga' ) < strpos( $html, 'Raven' ), 'tri par défaut : dernière sortie' );
		yume_assert_contains( 'aria-label="Filtres de la bibliothèque"', $html );
		yume_assert_same( 4, yume_tl_compte( 'aria-current="page"', $html ), 'un filtre actif par groupe (tous + dernières sorties)' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $ids['raven'] ) ) . '"', $html );

		$sans = yume_tl_rendu( 'library-grid', array( 'showFilters' => false ) );
		yume_assert_not_contains( 'yn-library__filtres', $sans, 'filtres masqués' );
	}
);

yume_tl_test(
	'library-grid : filtres GET type, statut (groupe), genre et tri A → Z ; valeurs inconnues ignorées',
	static function () {
		yume_tl_bibliotheque();

		$_GET = array( 'type' => 'manga' );
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_same( 1, yume_tl_compte( '<li class="yn-library__item">', $html ) );
		yume_assert_contains( 'Alya Manga', $html );
		yume_assert_contains( 'class="yn-chip yn-library__pastille yn-chip--new is-active" href="http://', $html );
		yume_assert_true( 1 === preg_match( '#<a class="yn-chip yn-library__pastille yn-chip--new is-active" href="[^"]*type=manga[^"]*" aria-current="page">Manga#', $html ), 'pastille Manga active' );
		yume_assert_contains( 'Licenciées / abandonnées <span class="yn-library__nombre">1<', $html, 'compteurs à facettes' );
		yume_assert_not_contains( 'Séries en cours <span', $html, 'groupe sans œuvre du type masqué' );

		$_GET = array( 'statut' => 'licenciee,abandonnee' );
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_same( 1, yume_tl_compte( '<li class="yn-library__item">', $html ) );
		yume_assert_true( 1 === preg_match( '#aria-current="page">Licenciées / abandonnées#', $html ), 'groupe actif' );

		$_GET = array( 'statut' => 'en-pause' );
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_same( 1, yume_tl_compte( '<li class="yn-library__item">', $html ) );
		yume_assert_contains( 'Silent Witch', $html );
		yume_assert_true( 1 === preg_match( '#aria-current="page">En pause#', $html ), 'statut isolé affiché comme filtre actif' );

		$_GET = array( 'genre' => 'fantasy' );
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_same( 2, yume_tl_compte( '<li class="yn-library__item">', $html ) );

		$_GET = array( 'tri' => 'az' );
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_true( strpos( $html, 'Alya Manga' ) < strpos( $html, 'Grimgar' ) && strpos( $html, 'Raven' ) < strpos( $html, 'Silent Witch' ), 'tri alphabétique' );
		yume_assert_true( 1 === preg_match( '#aria-current="page">A → Z#', $html ) );

		$_GET = array(
			'type'   => '"><script>alert(1)</script>',
			'statut' => 'inconnu',
			'genre'  => 'nimporte',
		);
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_same( 4, yume_tl_compte( '<li class="yn-library__item">', $html ), 'valeurs inconnues ignorées' );
		yume_assert_not_contains( '<script>', $html );

		$filtres = normaliser_filtres(
			array(
				'statut' => 'licenciee, abandonnee,licenciee',
				'tri'    => 'AZ',
			)
		);
		yume_assert_same( array( 'abandonnee', 'licenciee' ), $filtres['statuts'] );
		yume_assert_same( 'az', $filtres['tri'] );
	}
);

yume_tl_test(
	'library-grid : pagination (pg) et état vide avec réinitialisation des filtres',
	static function () {
		yume_tl_bibliotheque();
		$html = yume_tl_rendu( 'library-grid', array( 'perPage' => 3 ) );
		yume_assert_same( 3, yume_tl_compte( '<li class="yn-library__item">', $html ) );
		yume_assert_contains( 'class="yn-pagination yn-library__pagination" aria-label="Pages de la bibliothèque"', $html );
		yume_assert_contains( 'pg=2', $html );
		yume_assert_contains( '4 œuvres · page 1 sur 2', $html );

		$_GET = array(
			'pg'  => '2',
			'tri' => 'az',
		);
		$html = yume_tl_rendu( 'library-grid', array( 'perPage' => 3 ) );
		yume_assert_same( 1, yume_tl_compte( '<li class="yn-library__item">', $html ) );
		yume_assert_contains( 'Silent Witch', $html );
		yume_assert_contains( 'page-numbers current">2</span>', $html, 'page courante' );
		yume_assert_contains( 'tri=az', $html, 'les liens de pagination gardent les filtres' );

		$_GET = array(
			'type'   => 'manga',
			'statut' => 'en-cours',
		);
		$html = yume_tl_rendu( 'library-grid' );
		yume_assert_contains( 'Aucune œuvre ne correspond à ces filtres.', $html );
		yume_assert_contains( 'Réinitialiser les filtres', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * yume/library-menu
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'library-menu : <details> de navigation, compteurs des types et statuts, liens, reprise',
	static function () {
		$vide = yume_tl_rendu( 'library-menu' );
		yume_assert_contains( 'Aucune œuvre publiée pour le moment.', $vide );
		yume_tl_bibliotheque();
		$html = yume_tl_rendu( 'library-menu' );
		yume_assert_true( 0 === strpos( $html, '<details ' ), 'racine <details>' );
		yume_assert_contains( 'class="yn-library-menu wp-block-yume-library-menu"', $html );
		yume_assert_contains( '<summary class="wp-block-navigation-item__content yn-library-menu__bouton"><span class="wp-block-navigation-item__label">Bibliothèque</span>', $html );
		$biblio = yume_url_page( 'bibliotheque' );
		yume_assert_contains( 'href="' . esc_url( add_query_arg( 'type', 'light-novel', $biblio ) ) . '"><span class="yn-library-menu__nom">Light novels</span> <span class="yn-library-menu__nombre">2<', $html, 'brouillon non compté' );
		yume_assert_contains( 'Manga</span> <span class="yn-library-menu__nombre">1<', $html );
		yume_assert_contains( 'Web novels</span> <span class="yn-library-menu__nombre">1<', $html );
		yume_assert_contains( 'statut=en-cours%2Cen-pause', $html );
		yume_assert_contains( 'Séries en cours</span> <span class="yn-library-menu__nombre">2<', $html );
		yume_assert_contains( 'Séries terminées</span> <span class="yn-library-menu__nombre">1<', $html );
		yume_assert_contains( 'href="' . esc_url( add_query_arg( 'tri', 'az', $biblio ) ) . '"', $html, 'Toutes les œuvres A → Z' );
		yume_assert_contains( 'href="' . esc_url( yume_url_page( 'compte' ) ) . '" data-yn-reprendre>Reprendre ma lecture', $html, 'visiteur : reprise par le script' );

		wp_set_current_user( yume_factory_user( 'subscriber' ) );
		$html = yume_tl_rendu( 'library-menu' );
		yume_assert_contains( 'href="' . esc_url( yume_url_page( 'compte' ) ) . '">Reprendre ma lecture', $html, 'membre : page compte' );
		yume_assert_not_contains( 'data-yn-reprendre', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Fiche d'une œuvre
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre complète pour les fiches.
 */
function yume_tl_grimgar(): int {
	$id = yume_tl_oeuvre(
		'Grimgar of Fantasy and Ash',
		array(
			'yume_type'   => 'light-novel',
			'yume_statut' => 'en-cours',
			'yume_genre'  => array( 'fantasy', 'isekai' ),
		),
		array(
			'post_content' => '<!-- wp:paragraph --><p>Quand Haruhiro se réveille, il est dans l’obscurité.</p><!-- /wp:paragraph -->',
			'meta_input'   => array(
				'yume_titres_alt'        => array( 'Hai to Gensou no Grimgar', '灰と幻想のグリムガル' ),
				'yume_auteur'            => 'Jyumonji Ao',
				'yume_illustrateur'      => 'Shirai Eiri',
				'yume_editeur_vo'        => 'OVERLAP',
				'yume_nb_tomes_vo'       => 22,
				'yume_statut_vo'         => 'en_cours',
				'yume_jours_sortie'      => array( 'dimanche' ),
				'yume_equipe'            => array(
					'traduction' => 'Calumi',
					'relecture'  => 'Angeloids',
					'edition'    => 'Calumi',
				),
				'yume_source_traduction' => 'Édition anglaise officielle (J-Novel Club)',
				'yume_liens'             => array(
					array(
						'label' => 'Fiche Novel-Index',
						'url'   => 'https://www.novel-index.com/grimgar',
					),
				),
			),
		)
	);
	set_post_thumbnail( $id, yume_tl_image( 'grimgar.jpg' ) );
	update_post_meta( $id, 'yume_banniere_id', yume_tl_image( 'banniere-grimgar.jpg' ) );
	return $id;
}

yume_tl_test(
	'oeuvre-header : fil d’Ariane, couverture, pastilles, titres alternatifs, fiche technique, synopsis, bannière',
	static function () {
		yume_assert_same( '', yume_tl_rendu( 'oeuvre-header' ), 'sans contexte : rien' );
		$id = yume_tl_grimgar();
		yume_tl_tome( $id, 1 );
		yume_tl_tome( $id, 2 );
		$html = yume_tl_rendu( 'oeuvre-header', array(), $id );
		yume_assert_contains( 'class="yn-oeuvre-header wp-block-yume-oeuvre-header"', $html );
		yume_assert_contains( '<h1 class="yn-oeuvre-header__titre">Grimgar of Fantasy and Ash</h1>', $html );
		yume_assert_contains( 'aria-label="Fil d’Ariane"', $html );
		yume_assert_contains( '>Light novels</a>', $html, 'type dans le fil d’Ariane' );
		yume_assert_contains( '<li class="yn-ariane__item" aria-current="page"><span>Grimgar of Fantasy and Ash</span></li>', $html );
		yume_assert_contains( '<span class="yn-chip yn-chip--info">Light novel</span>', $html );
		yume_assert_contains( '<span aria-hidden="true">●</span> Traduction en cours', $html );
		yume_assert_contains( 'genre=fantasy', $html, 'genres liés à la bibliothèque' );
		yume_assert_contains( '<span lang="ja">灰と幻想のグリムガル</span>', $html, 'langue du titre japonais' );
		yume_assert_contains( '<dt class="yn-label">Scénario</dt><dd>Jyumonji Ao</dd>', $html );
		yume_assert_contains( 'OVERLAP · 22 tomes (en cours)', $html );
		yume_assert_contains( '2 tomes · sorties : dimanche', $html );
		yume_assert_contains( 'Quand Haruhiro se réveille', $html, 'synopsis' );
		yume_assert_contains( 'class="yn-fiche-banniere" aria-hidden="true"', $html, 'bannière de l’œuvre en fond' );
		yume_assert_contains( 'alt="Couverture : Grimgar of Fantasy and Ash"', $html );

		// Le contexte d'un tome ou d'un chapitre mène à son œuvre.
		$tome = yume_tl_tome( $id, 3 );
		yume_assert_contains( '<h1 class="yn-oeuvre-header__titre">Grimgar of Fantasy and Ash</h1>', yume_tl_rendu( 'oeuvre-header', array(), $tome ) );

		$brouillon = yume_tl_oeuvre( 'Œuvre <b>secrète</b> & cachée', array(), array( 'post_status' => 'draft' ) );
		yume_assert_same( '', yume_tl_rendu( 'oeuvre-header', array(), $brouillon ), 'brouillon invisible des visiteurs' );
		wp_set_current_user( yume_factory_user( 'administrator' ) );
		$apercu = yume_tl_rendu( 'oeuvre-header', array(), $brouillon );
		yume_assert_contains( 'Œuvre secrète &amp; cachée</h1>', $apercu, 'aperçu de l’équipe, titre nettoyé et échappé' );
	}
);

yume_tl_test(
	'oeuvre-infos : équipe (rôles regroupés), source, liens externes ; rien sans données',
	static function () {
		$vide = yume_tl_oeuvre( 'Sans infos' );
		yume_assert_same( '', yume_tl_rendu( 'oeuvre-infos', array(), $vide ) );
		$id   = yume_tl_grimgar();
		$html = yume_tl_rendu( 'oeuvre-infos', array(), $id );
		yume_assert_contains( 'class="yn-oeuvre-infos wp-block-yume-oeuvre-infos"', $html );
		yume_assert_contains( '>Équipe de traduction</h2>', $html );
		yume_assert_contains( '<b class="yn-oeuvre-infos__nom">Calumi</b> · traduction, édition', $html, 'rôles d’une même personne regroupés' );
		yume_assert_contains( '<b class="yn-oeuvre-infos__nom">Angeloids</b> · relecture', $html );
		yume_assert_contains( 'Traduction depuis : Édition anglaise officielle (J-Novel Club). Fan-traduction à but non lucratif.', $html );
		yume_assert_contains( '<a href="https://www.novel-index.com/grimgar" target="_blank" rel="noopener">Fiche Novel-Index', $html );
		yume_assert_contains( '(lien externe, nouvel onglet)', $html );

		update_post_meta(
			$id,
			'yume_liens',
			array(
				array(
					'label' => 'Piège',
					'url'   => 'javascript:alert(1)',
				),
			)
		);
		yume_assert_not_contains( 'javascript:', yume_tl_rendu( 'oeuvre-infos', array(), $id ), 'adresse non http ignorée' );
	}
);

yume_tl_test(
	'oeuvre-infos est inséré après tome-list dans un modèle qui ne le contient pas encore',
	static function () {
		$modele          = new WP_Block_Template();
		$modele->content = '<!-- wp:yume/tome-list /-->';
		yume_assert_same( array( 'yume/oeuvre-infos' ), accrocher_oeuvre_infos( array(), 'after', 'yume/tome-list', $modele ) );
		yume_assert_same( array(), accrocher_oeuvre_infos( array(), 'before', 'yume/tome-list', $modele ) );
		yume_assert_same( array(), accrocher_oeuvre_infos( array(), 'after', 'core/paragraph', $modele ) );
		$modele->content = '<!-- wp:yume/tome-list /--><!-- wp:yume/oeuvre-infos /-->';
		yume_assert_same( array(), accrocher_oeuvre_infos( array(), 'after', 'yume/tome-list', $modele ), 'déjà présent' );
		yume_assert_same( array(), accrocher_oeuvre_infos( array(), 'after', 'yume/tome-list', array( 'name' => 'composition' ) ), 'compositions ignorées' );

		$modele->content = '<!-- wp:yume/tome-list /-->';
		$modele->slug    = 'single-yume_oeuvre';
		yume_assert_contains( '<!-- wp:yume/oeuvre-infos /-->', apply_block_hooks_to_content( $modele->content, $modele, 'insert_hooked_blocks' ), 'insertion par l’API des blocs accrochés' );

		// Modèle « Œuvre » du thème : le bloc y est placé explicitement (colonne de droite, après
		// les commentaires) ; l'insertion automatique s'efface, sans doublon.
		if ( 'yume' === get_stylesheet() ) {
			$theme = get_block_template( 'yume//single-yume_oeuvre' );
			yume_assert_true( $theme instanceof WP_Block_Template, 'modèle single-yume_oeuvre du thème' );
			yume_assert_same( 1, substr_count( $theme->content, 'wp:yume/oeuvre-infos' ), 'un seul yume/oeuvre-infos dans le modèle du thème' );
			yume_assert_true( strpos( $theme->content, 'wp:yume/oeuvre-infos' ) > strpos( $theme->content, 'yn-fiche__commentaires' ), 'après les commentaires' );
		}
	}
);

yume_tl_test(
	'tome-list : tomes publiés du plus récent, statistiques, boutons, repli des anciens au-delà de 6',
	static function () {
		$id = yume_tl_grimgar();
		yume_assert_contains( 'Aucun tome publié pour le moment.', yume_tl_rendu( 'tome-list', array(), $id ) );
		$tomes = array();
		for ( $n = 1; $n <= 8; $n++ ) {
			$tomes[ $n ] = yume_tl_tome(
				$id,
				$n,
				array(
					'post_date'  => yume_tl_date( 40 - $n ),
					'meta_input' => array( 'yume_lien_pdf' => 'https://www.clictune.com/t' . $n ),
				)
			);
		}
		yume_tl_tome( $id, 9, array( 'post_status' => 'draft' ) );
		$c1 = yume_tl_chapitre( $tomes[8], 1 );
		yume_tl_chapitre( $tomes[8], 2 );
		yume_tl_chapitre( $tomes[8], null, array( 'meta_input' => array( 'yume_nature' => 'postface' ) ) );
		yume_tl_chapitre( $tomes[8], 3, array( 'post_status' => 'draft' ) );

		$html = yume_tl_rendu( 'tome-list', array(), $id );
		yume_assert_contains( 'class="yn-tome-list wp-block-yume-tome-list"', $html );
		yume_assert_contains( '>Tomes <span class="yn-tome-list__nombre">(8)</span></h2>', $html, 'brouillon exclu du compte' );
		yume_assert_not_contains( 'tome-9', $html );
		yume_assert_true( strpos( $html, '>Tome 8<' ) < strpos( $html, '>Tome 7<' ), 'du plus récent au plus ancien' );
		yume_assert_contains( '<details class="yn-tome-list__anciens">', $html );
		yume_assert_contains( '>Tomes 1 à 3<', $html, 'libellé du groupe replié' );
		yume_assert_contains( 'Afficher les 3 tomes précédents', $html );
		yume_assert_true( strpos( $html, '<details' ) < strpos( $html, '>Tome 3<' ) && strpos( $html, '>Tome 4<' ) < strpos( $html, '<details' ), 'tomes 1 à 3 dans le <details>' );
		yume_assert_contains( '2 chapitres + postface · ', $html, 'chapitres publiés seulement' );
		yume_assert_contains( ' mots · ~', $html );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $c1 ) ) . '">Lire en ligne', $html );
		yume_assert_same( 8, yume_tl_compte( 'yn-telechargement--pdf', $html ) );
		yume_assert_same( 0, yume_tl_compte( 'yn-telechargement--epub', $html ), 'EPUB vide : pas de bouton' );
		yume_assert_contains( '<span class="yn-visually-hidden">Publié le </span><time datetime=', $html );

		// Extension par un autre module (progression du lecteur).
		$filtre = static function ( array $ligne, int $tome_id ) use ( $tomes ): array {
			if ( $tomes[7] === $tome_id ) {
				$ligne['classes'][] = 'is-en-cours';
				$ligne['details'][] = 'vous en êtes au chapitre 3';
				$ligne['lire']      = '<a class="yn-btn yn-btn--primary yn-btn--sm" href="#reprendre">Reprendre</a><script>x</script>';
			}
			return $ligne;
		};
		add_filter( 'yume_bibliotheque_ligne_tome', $filtre, 10, 2 );
		$html = yume_tl_rendu( 'tome-list', array(), $id );
		remove_filter( 'yume_bibliotheque_ligne_tome', $filtre, 10 );
		yume_assert_contains( '<li class="yn-tome-list__ligne is-en-cours">', $html );
		yume_assert_contains( 'vous en êtes au chapitre 3', $html );
		yume_assert_contains( 'href="#reprendre">Reprendre</a>', $html );
		yume_assert_not_contains( '<script>', $html, 'HTML du filtre nettoyé' );

		// Six tomes ou moins : pas de repli.
		$autre = yume_tl_oeuvre( 'Autre' );
		yume_tl_tome( $autre, 1 );
		yume_assert_not_contains( '<details', yume_tl_rendu( 'tome-list', array(), $autre ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page d'un tome
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'tome-header : titre, crédits, équivalence, lecture, PDF / EPUB, galerie avec légendes et lien vers l’image',
	static function () {
		yume_assert_same( '', yume_tl_rendu( 'tome-header' ) );
		$id   = yume_tl_grimgar();
		$i1   = yume_tl_image( 'illus-1.jpg', '', 'Haruhiro au crépuscule' );
		$i2   = yume_tl_image( 'illus-2.jpg', 'Merry et Shihoru' );
		$tome = yume_tl_tome(
			$id,
			9,
			array(
				'meta_input' => array(
					'yume_lien_pdf'      => 'https://www.clictune.com/pdf9',
					'yume_lien_epub'     => 'https://www.clictune.com/epub9',
					'yume_equivalence'   => 'Équivaut au tome 9 de l’édition anglaise.',
					'yume_credits'       => array(
						'traduction' => 'Calumi',
						'relecture'  => 'Angeloids',
					),
					'yume_illustrations' => array( $i1, $i2, 999999 ),
				),
			)
		);
		$c1   = yume_tl_chapitre( $tome, 1 );
		$html = yume_tl_rendu( 'tome-header', array(), $tome );
		yume_assert_contains( 'class="yn-tome-header wp-block-yume-tome-header"', $html );
		yume_assert_contains( '<span class="yn-tome-header__oeuvre">Grimgar of Fantasy and Ash</span><span class="yn-visually-hidden"> — </span><span class="yn-tome-header__libelle">Tome 9</span>', $html );
		yume_assert_contains( '<dt class="yn-label">Traduction</dt><dd>Calumi</dd>', $html );
		yume_assert_not_contains( '<dt class="yn-label">Édition</dt>', $html, 'rôle vide omis' );
		yume_assert_contains( 'Équivaut au tome 9 de l’édition anglaise.', $html );
		yume_assert_contains( 'href="' . esc_url( yume_url_illustrations( $tome ) ) . '" data-yn-debut-chapitre="' . esc_attr( get_permalink( $c1 ) ) . '"', $html, 'galerie : la lecture commence par la page Illustrations' );
		yume_assert_contains( '>Commencer la lecture', $html );
		yume_assert_contains( 'href="https://www.clictune.com/pdf9" target="_blank" rel="noopener">PDF', $html );
		yume_assert_contains( 'href="https://www.clictune.com/epub9" target="_blank" rel="noopener">EPUB', $html );
		yume_assert_contains( 'Fiche de l’œuvre', $html );
		yume_assert_contains( 'Illustrations <span class="yn-muted">(2)</span>', $html, 'pièce jointe inexistante ignorée' );
		yume_assert_contains( '<figcaption class="yn-galerie__legende">Haruhiro au crépuscule</figcaption>', $html );
		yume_assert_contains( '<figcaption class="yn-galerie__legende">Illustration 2</figcaption>', $html, 'légende de repli' );
		yume_assert_contains( 'alt="Merry et Shihoru"', $html );
		yume_assert_contains( 'alt="Illustration 1 — Grimgar of Fantasy and Ash, Tome 9"', $html );
		yume_assert_contains( 'href="' . esc_url( wp_get_attachment_url( $i1 ) ) . '"', $html, 'lien vers l’image en grand' );
		yume_assert_contains( 'aria-current="page"><span>Tome 9</span>', $html );
	}
);

yume_tl_test(
	'tome-toc : chapitres publiés (sous-titre, durée) ; planifiés « à venir » seulement pour un arc en cours',
	static function () {
		$ln   = yume_tl_oeuvre( 'Light', array( 'yume_type' => 'light-novel' ) );
		$tome = yume_tl_tome( $ln, 1 );
		yume_assert_contains( 'Aucun chapitre en ligne pour ce tome.', yume_tl_rendu( 'tome-toc', array(), $tome ) );
		$c1 = yume_tl_chapitre( $tome, 1, array( 'meta_input' => array( 'yume_sous_titre' => 'La Crête Brumeuse' ) ) );
		yume_tl_chapitre( $tome, 2, array( 'post_status' => 'draft' ) );
		$html = yume_tl_rendu( 'tome-toc', array(), $tome );
		yume_assert_contains( 'class="yn-toc wp-block-yume-tome-toc"', $html );
		yume_assert_contains( '>Sommaire</h2>', $html );
		yume_assert_contains( '<a class="yn-toc__lien" href="' . esc_url( get_permalink( $c1 ) ) . '"><span class="yn-toc__numero">Chapitre 1</span><span class="yn-toc__sous-titre">La Crête Brumeuse</span>', $html );
		yume_assert_contains( 'Temps de lecture :', $html );
		yume_assert_not_contains( 'Chapitre 2', $html, 'brouillon d’un tome complet masqué' );

		// Le contexte d'un chapitre marque le chapitre courant.
		yume_assert_contains( 'aria-current="page"', yume_tl_rendu( 'tome-toc', array(), $c1 ) );

		$wn  = yume_tl_oeuvre( 'Web', array( 'yume_type' => 'web-novel' ) );
		$arc = yume_tl_tome( $wn, 7, array( 'meta_input' => array( 'yume_nature' => 'arc' ) ) );
		yume_tl_chapitre( $arc, 1 );
		yume_tl_chapitre(
			$arc,
			2,
			array(
				'post_status' => 'future',
				'post_date'   => yume_tl_date( -3 ),
			)
		);
		yume_tl_chapitre( $arc, 3, array( 'post_status' => 'draft' ) );
		$html = yume_tl_rendu( 'tome-toc', array(), $arc );
		yume_assert_contains( '<li class="yn-toc__item yn-toc__item--a-venir"><span class="yn-toc__lien"><span class="yn-toc__numero">Chapitre 3</span>', $html, 'planifié non cliquable' );
		yume_assert_contains( 'À venir', $html );
		yume_assert_contains( 'Prévu le ' . date_courte( time() + 3 * DAY_IN_SECONDS ), $html, 'chapitre programmé' );
		yume_assert_contains( '1 chapitre · ~', $html );
		yume_assert_contains( '2 à venir', $html );
		yume_assert_same( 1, yume_tl_compte( '<a class="yn-toc__lien"', $html ), 'seul le chapitre publié est un lien' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Lecteur
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'chapter-header : fil d’Ariane œuvre › tome › chapitre, « Chapitre N » en h1, sous-titre, crédits, durée',
	static function () {
		yume_assert_same( '', yume_tl_rendu( 'chapter-header' ) );
		$id   = yume_tl_grimgar();
		$tome = yume_tl_tome( $id, 7, array( 'meta_input' => array( 'yume_credits' => array( 'traduction' => 'Tome-Trad' ) ) ) );
		$c1   = yume_tl_chapitre(
			$tome,
			1,
			array(
				'meta_input' => array(
					'yume_sous_titre' => 'La Crête Brumeuse',
					'yume_credits'    => array(
						'traduction' => 'Angeloids',
						'relecture'  => 'Calumi',
					),
				),
			)
		);
		$html = yume_tl_rendu( 'chapter-header', array(), $c1 );
		yume_assert_true( 0 === strpos( $html, '<header class="yn-chapter-header wp-block-yume-chapter-header">' ) );
		yume_assert_contains( '<a href="' . esc_url( get_permalink( $id ) ) . '">Grimgar of Fantasy and Ash</a>', $html );
		yume_assert_contains( '<a href="' . esc_url( get_permalink( $tome ) ) . '">Tome 7</a>', $html );
		yume_assert_contains( '<li class="yn-ariane__item" aria-current="page"><span>Chapitre 1</span></li>', $html );
		yume_assert_contains( '<h1 class="yn-chapter-header__titre">Chapitre 1</h1><p class="yn-subtitle">La Crête Brumeuse</p>', $html );
		yume_assert_contains( '<span class="yn-credits__role">Traduction</span> · <span class="yn-credits__nom">Angeloids</span>', $html );
		yume_assert_contains( '<span class="yn-credits__role">Relecture</span> · <span class="yn-credits__nom">Calumi</span>', $html );
		yume_assert_contains( 'Lecture ~', $html, 'temps de lecture' );

		// Sans crédits propres : ceux du tome.
		$c2 = yume_tl_chapitre( $tome, 2 );
		yume_assert_contains( 'Tome-Trad', yume_tl_rendu( 'chapter-header', array(), $c2 ) );

		// Brouillon : invisible pour un visiteur.
		$c3 = yume_tl_chapitre( $tome, 3, array( 'post_status' => 'draft' ) );
		yume_assert_same( '', yume_tl_rendu( 'chapter-header', array(), $c3 ) );
	}
);

yume_tl_test(
	'chapter-nav : précédent · sommaire · suivant (rel=prev/next), passage d’un tome à l’autre, libellés explicites',
	static function () {
		yume_assert_same( '', yume_tl_rendu( 'chapter-nav' ) );
		$id = yume_tl_oeuvre( 'Grimgar' );
		$t1 = yume_tl_tome( $id, 1, array( 'post_date' => yume_tl_date( 10 ) ) );
		$t2 = yume_tl_tome( $id, 2, array( 'post_date' => yume_tl_date( 5 ) ) );
		$a1 = yume_tl_chapitre( $t1, 1 );
		$a2 = yume_tl_chapitre( $t1, 2 );
		$b1 = yume_tl_chapitre( $t2, 1, array( 'meta_input' => array( 'yume_sous_titre' => 'S’il vous plaît' ) ) );
		yume_tl_chapitre( $t2, 2, array( 'post_status' => 'draft' ) );

		$html = yume_tl_rendu( 'chapter-nav', array(), $a2 );
		yume_assert_contains( 'class="yn-chapter-nav wp-block-yume-chapter-nav" aria-label="Chapitres précédent et suivant"', $html );
		yume_assert_contains( 'rel="prev" href="' . esc_url( get_permalink( $a1 ) ) . '"', $html );
		yume_assert_contains( '<span class="yn-visually-hidden">Chapitre précédent : </span>Chapitre 1', $html );
		yume_assert_contains( 'rel="next" href="' . esc_url( get_permalink( $b1 ) ) . '"', $html );
		yume_assert_contains( '<span class="yn-visually-hidden">Chapitre suivant : </span>Tome 2 · Chapitre 1 · S’il vous plaît', $html, 'changement de tome indiqué' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $t1 ) ) . '"', $html, 'sommaire du tome' );
		yume_assert_contains( 'Sommaire<span class="yn-chapter-nav__complement"> du tome</span><span class="yn-visually-hidden"> — Tome 1</span>', $html );

		$premier = yume_tl_rendu( 'chapter-nav', array(), $a1 );
		yume_assert_not_contains( 'rel="prev"', $premier );
		yume_assert_contains( 'yn-chapter-nav__vide', $premier );

		$dernier = yume_tl_rendu( 'chapter-nav', array(), $b1 );
		yume_assert_not_contains( 'rel="next"', $dernier, 'chapitre brouillon ignoré' );
		yume_assert_contains( 'Dernier chapitre disponible', $dernier );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Référencement
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'JSON-LD : BookSeries (œuvre), Book isPartOf (tome), Chapter isPartOf (chapitre), BreadcrumbList, JSON valide',
	static function () {
		$id   = yume_tl_grimgar();
		$tome = yume_tl_tome( $id, 9, array( 'meta_input' => array( 'yume_credits' => array( 'traduction' => 'Calumi & Angeloids' ) ) ) );
		$c1   = yume_tl_chapitre( $tome, 1, array( 'meta_input' => array( 'yume_sous_titre' => 'La Crête <Brumeuse> & co' ) ) );
		update_post_meta( $c1, 'yume_temps_lecture', 18 );

		$serie = yume_tl_noeud( yume_tl_jsonld( balise_jsonld( $id ) ), 'BookSeries' );
		yume_assert_same( 'https://schema.org', donnees_structurees( $id )['@context'] );
		yume_assert_same( 'Grimgar of Fantasy and Ash', $serie['name'] );
		yume_assert_same( get_permalink( $id ) . '#oeuvre', $serie['@id'] );
		yume_assert_same( array( 'Hai to Gensou no Grimgar', '灰と幻想のグリムガル' ), $serie['alternateName'] );
		yume_assert_same( 'Jyumonji Ao', $serie['author']['name'] );
		yume_assert_same( 'fr', $serie['inLanguage'] );
		yume_assert_same( 'Book', $serie['hasPart'][0]['@type'] );
		yume_assert_same( 9, $serie['hasPart'][0]['position'] );
		yume_assert_contains( 'Quand Haruhiro', $serie['description'] );

		$doc   = yume_tl_jsonld( balise_jsonld( $tome ) );
		$livre = yume_tl_noeud( $doc, 'Book' );
		yume_assert_same( 'BookSeries', $livre['isPartOf']['@type'] );
		yume_assert_same( get_permalink( $id ) . '#oeuvre', $livre['isPartOf']['@id'] );
		yume_assert_same( 'https://schema.org/EBook', $livre['bookFormat'] );
		$traducteurs = wp_list_pluck( $livre['translator'], 'name' );
		yume_assert_same( array( 'Calumi', 'Angeloids' ), array_slice( $traducteurs, 0, 2 ), 'traducteurs des crédits' );
		yume_assert_same( 'Organization', end( $livre['translator'] )['@type'], 'puis l’équipe du site' );
		yume_assert_same( 'Chapter', $livre['hasPart'][0]['@type'] );
		$ariane = yume_tl_noeud( $doc, 'BreadcrumbList' );
		yume_assert_same( 3, count( $ariane['itemListElement'] ) );
		yume_assert_same( 'Tome 9', $ariane['itemListElement'][2]['name'] );

		$balise = balise_jsonld( $c1 );
		yume_assert_true( 1 === preg_match( '#<script type="application/ld\+json"[^>]*>([^<]*)</script>#', $balise ), 'aucun « < » dans le JSON (JSON_HEX_TAG)' );
		$chapitre = yume_tl_noeud( yume_tl_jsonld( $balise ), 'Chapter' );
		yume_assert_same( 'Book', $chapitre['isPartOf']['@type'] );
		yume_assert_same( get_permalink( $tome ) . '#tome', $chapitre['isPartOf']['@id'] );
		yume_assert_same( 'BookSeries', $chapitre['isPartOf']['isPartOf']['@type'] );
		yume_assert_same( 'PT18M', $chapitre['timeRequired'] );
		yume_assert_same( 1, $chapitre['position'] );
		yume_assert_contains( 'Chapitre 1 — La Crête', $chapitre['name'] );

		$brouillon = yume_tl_chapitre( $tome, 2, array( 'post_status' => 'draft' ) );
		yume_assert_same( array(), donnees_structurees( $brouillon ), 'rien pour un brouillon' );
		yume_assert_same( '', balise_jsonld( yume_factory_post() ), 'rien pour un article' );
	}
);

yume_tl_test(
	'wp_head : <link rel="prev|next"> et JSON-LD sur un chapitre publié ; rien sur un brouillon ni ailleurs',
	static function () {
		$id   = yume_tl_oeuvre( 'Grimgar' );
		$tome = yume_tl_tome( $id, 1 );
		$c1   = yume_tl_chapitre( $tome, 1 );
		$c2   = yume_tl_chapitre( $tome, 2 );
		$c3   = yume_tl_chapitre( $tome, 3 );
		yume_assert_same( 9, has_action( 'wp_head', 'Yume\\Core\\Library\\afficher_voisins' ) );
		yume_assert_same( 20, has_action( 'wp_head', 'Yume\\Core\\Library\\afficher_jsonld' ) );

		yume_tl_aller( $c2 );
		ob_start();
		Yume\Core\Library\afficher_voisins();
		Yume\Core\Library\afficher_jsonld();
		$tete = (string) ob_get_clean();
		yume_assert_contains( '<link rel="prev" href="' . esc_url( get_permalink( $c1 ) ) . '" />', $tete );
		yume_assert_contains( '<link rel="next" href="' . esc_url( get_permalink( $c3 ) ) . '" />', $tete );
		yume_assert_contains( '"@type":"Chapter"', $tete );

		yume_assert_same( '<link rel="next" href="' . esc_url( get_permalink( $c2 ) ) . "\" />\n", balises_voisins( $c1 ), 'premier chapitre : suivant seulement' );

		yume_tl_aller( $tome );
		ob_start();
		Yume\Core\Library\afficher_voisins();
		Yume\Core\Library\afficher_jsonld();
		$tete = (string) ob_get_clean();
		yume_assert_not_contains( 'rel="prev"', $tete );
		yume_assert_contains( '"@type":"Book"', $tete );

		wp_update_post(
			array(
				'ID'          => $c2,
				'post_status' => 'draft',
			)
		);
		yume_tl_aller( $c2 );
		ob_start();
		Yume\Core\Library\afficher_voisins();
		Yume\Core\Library\afficher_jsonld();
		yume_assert_same( '', (string) ob_get_clean(), 'aperçu d’un brouillon : rien' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Cache
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'cache : versionné, invalidé à la création, au changement de statut, de termes et à la suppression',
	static function () {
		$oeuvre = yume_tl_oeuvre( 'Grimgar', array( 'yume_type' => 'light-novel' ) );
		$t1     = yume_tl_tome( $oeuvre, 1, array( 'post_date' => yume_tl_date( 10 ) ) );
		yume_assert_same( 1, yume_tl_compte( '<li class="yn-releases__item">', yume_tl_rendu( 'latest-releases' ) ) );
		$version = version_cache();
		yume_assert_true( false !== get_transient( 'yume_bib_sorties_' . substr( md5( wp_json_encode( array( 6 ) ) . '|' . $version . '|' . \Yume\Core\Library\FORMAT_CACHE ), 0, 16 ) ), 'liste mise en cache (transient)' );

		$t2 = yume_tl_tome( $oeuvre, 2, array( 'post_date' => yume_tl_date( 1 ) ) );
		yume_assert_true( version_cache() !== $version, 'nouvelle version après enregistrement' );
		yume_assert_same( 2, yume_tl_compte( '<li class="yn-releases__item">', yume_tl_rendu( 'latest-releases' ) ), 'nouveau tome visible' );

		wp_update_post(
			array(
				'ID'          => $t2,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( 1, yume_tl_compte( '<li class="yn-releases__item">', yume_tl_rendu( 'latest-releases' ) ), 'dépublication prise en compte' );

		yume_assert_contains( 'Light novels</span> <span class="yn-library-menu__nombre">1<', yume_tl_rendu( 'library-menu' ) );
		wp_set_object_terms( $oeuvre, 'manga', 'yume_type' );
		yume_assert_contains( 'Manga</span> <span class="yn-library-menu__nombre">1<', yume_tl_rendu( 'library-menu' ), 'changement de type pris en compte' );

		wp_delete_post( $t1, true );
		yume_assert_contains( 'Aucune sortie pour le moment.', yume_tl_rendu( 'latest-releases' ), 'suppression prise en compte' );

		wp_trash_post( $oeuvre );
		yume_assert_same( array(), index_oeuvres()['oeuvres'], 'œuvre à la corbeille retirée de l’index' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Fonctions utilitaires
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'formats : sous-titre d’un tome, durées, dates françaises, nouveauté',
	static function () {
		$id  = yume_tl_oeuvre( 'Secrets of the Silent Witch' );
		$arc = yume_tl_tome(
			$id,
			7,
			array(
				'post_title' => 'Secrets of the Silent Witch — Arc 7 : Tournoi d’échec',
				'meta_input' => array( 'yume_nature' => 'arc' ),
			)
		);
		yume_assert_same( 'Tournoi d’échec', sous_titre_tome( $arc ) );
		$simple = yume_tl_tome( $id, 1 );
		yume_assert_same( '', sous_titre_tome( $simple ), 'titre = libellé' );
		$libre = yume_tl_tome( $id, 12, array( 'post_title' => 'Secrets of the Silent Witch — Le Pays des ombres' ) );
		yume_assert_same( 'Le Pays des ombres', sous_titre_tome( $libre ), 'titre libre' );

		yume_assert_same( "~18\u{00A0}min", duree_lecture( 18 ) );
		yume_assert_same( "~5\u{00A0}h\u{00A0}40", duree_lecture( 340 ) );
		yume_assert_same( "~2\u{00A0}h", duree_lecture( 120 ) );
		yume_assert_same( '', duree_lecture( 0 ) );

		$ts = (int) ( new DateTimeImmutable( '2026-09-20 12:00:00', wp_timezone() ) )->getTimestamp();
		yume_assert_same( '20 sept.', date_courte( $ts ) );
		yume_assert_same( '20 sept. 2026', date_courte( $ts, true ) );
		yume_assert_same( '1 août', date_courte( (int) ( new DateTimeImmutable( '2026-08-01 12:00:00', wp_timezone() ) )->getTimestamp() ) );
		yume_assert_true( est_nouveau( time() - 6 * DAY_IN_SECONDS ) );
		yume_assert_false( est_nouveau( time() - 8 * DAY_IN_SECONDS ) );
		yume_assert_false( est_nouveau( 0 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Recherche
 * -----------------------------------------------------------------------------
 */

/**
 * Lance une recherche comme requête principale et renvoie les ID trouvés, dans l'ordre.
 *
 * @param array $vars Variables de requête.
 * @return int[]
 */
function yume_tl_rechercher( array $vars ): array {
	$requete                 = new WP_Query();
	$GLOBALS['wp_the_query'] = $requete;
	$GLOBALS['wp_query']     = $requete;
	return array_map(
		'intval',
		$requete->query(
			array_merge(
				array(
					'fields'         => 'ids',
					'posts_per_page' => 50,
				),
				$vars
			)
		)
	);
}

yume_tl_test(
	'recherche : la fiche de l’œuvre (titre ou titre alternatif) passe avant ses tomes et ses annonces (UX-7)',
	static function () {
		$oeuvre = yume_tl_oeuvre( 'Grimgar of Fantasy and Ash', array(), array( 'post_date' => yume_tl_date( 400 ) ) );
		$alt    = yume_tl_oeuvre(
			'Hai to Gensou',
			array(),
			array(
				'post_date'    => yume_tl_date( 300 ),
				'post_content' => '<!-- wp:paragraph --><p>Le monde de grimgar, vu autrement.</p><!-- /wp:paragraph -->',
				'meta_input'   => array( 'yume_titres_alt' => array( 'Grimgar, le monde de cendres' ) ),
			)
		);
		$autre  = yume_tl_oeuvre(
			'Raven of the Inner Palace',
			array(),
			array(
				'post_date'    => yume_tl_date( 200 ),
				'post_content' => '<!-- wp:paragraph --><p>Pour les lecteurs de Grimgar.</p><!-- /wp:paragraph -->',
			)
		);
		$tomes  = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$tomes[] = yume_tl_tome( $oeuvre, $i, array( 'post_date' => yume_tl_date( 100 - $i ) ) );
		}
		$annonce = yume_factory_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Le tome 9 de Grimgar est disponible !',
				'post_date'   => yume_tl_date( 1 ),
			)
		);

		$ids = yume_tl_rechercher( array( 's' => 'grimgar' ) );
		yume_assert_same( $oeuvre, $ids[0] ?? 0, 'la fiche de l’œuvre en premier' );
		yume_assert_same( $alt, $ids[1] ?? 0, 'puis l’œuvre dont un titre alternatif correspond' );
		yume_assert_true( array_search( $tomes[8], $ids, true ) < array_search( $annonce, $ids, true ), 'à pertinence égale, les tomes avant les annonces' );
		yume_assert_true( array_search( $annonce, $ids, true ) < array_search( $autre, $ids, true ), 'une œuvre qui ne cite le nom que dans son synopsis ne passe pas devant les titres' );
		yume_assert_same( $tomes[8], $ids[2] ?? 0, 'tomes du plus récent au plus ancien' );

		// Tri explicite demandé : ordre de WordPress conservé.
		$ids = yume_tl_rechercher(
			array(
				's'       => 'grimgar',
				'orderby' => 'date',
			)
		);
		yume_assert_same( $annonce, $ids[0] ?? 0, 'orderby=date : la plus récente d’abord' );

		// Requête secondaire (non principale) : ordre inchangé.
		$secondaire = new WP_Query(
			array(
				's'              => 'grimgar',
				'fields'         => 'ids',
				'posts_per_page' => 50,
			)
		);
		yume_assert_same( $annonce, (int) ( $secondaire->posts[0] ?? 0 ), 'requête secondaire : ordre de WordPress' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Thème : blocs à fond personnalisé (contenus migrés)
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'thème : un groupe à fond clair sans couleur de texte prend l’encre sombre (UX-1, /lequipe/ en Nuit)',
	static function () {
		if ( ! function_exists( 'yume_theme_encre_fond_personnalise' ) ) {
			return; // Thème Yume inactif.
		}
		$groupe = static function ( array $attrs ): string {
			$fond = $attrs['style']['color']['background'] ?? '';
			return do_blocks( '<!-- wp:group ' . wp_json_encode( $attrs ) . ' --><div class="wp-block-group has-background" style="background-color:' . esc_attr( $fond ) . '"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">La direction</h3><!-- /wp:heading --></div><!-- /wp:group -->' );
		};
		yume_assert_contains( 'yn-fond-clair', $groupe( array( 'style' => array( 'color' => array( 'background' => '#efe7fb' ) ) ) ), '#efe7fb : fond clair' );
		yume_assert_contains( 'yn-fond-clair', $groupe( array( 'style' => array( 'color' => array( 'background' => '#F5F0FA' ) ) ) ), 'hexadécimal en capitales' );
		yume_assert_contains( 'yn-fond-clair', $groupe( array( 'style' => array( 'color' => array( 'background' => '#fff' ) ) ) ), 'forme courte' );
		yume_assert_contains( 'yn-fond-sombre', $groupe( array( 'style' => array( 'color' => array( 'background' => '#1b1231' ) ) ) ), 'fond sombre' );
		yume_assert_contains( 'yn-fond-sombre', $groupe( array( 'style' => array( 'color' => array( 'background' => 'rgb(20, 10, 40)' ) ) ) ), 'rgb()' );
		yume_assert_not_contains(
			'yn-fond-',
			$groupe(
				array(
					'style' => array(
						'color' => array(
							'background' => '#efe7fb',
							'text'       => '#333333',
						),
					),
				)
			),
			'couleur de texte propre : inchangé'
		);
		yume_assert_not_contains(
			'yn-fond-',
			$groupe(
				array(
					'textColor' => 'texte-fort',
					'style'     => array( 'color' => array( 'background' => '#efe7fb' ) ),
				)
			),
			'couleur de texte de palette : inchangé'
		);
		yume_assert_not_contains( 'yn-fond-', $groupe( array( 'backgroundColor' => 'carte' ) ), 'fond de palette (suit le thème) : inchangé' );
		yume_assert_not_contains( 'yn-fond-', $groupe( array( 'style' => array( 'color' => array( 'background' => 'var(--wp--preset--color--carte)' ) ) ) ), 'variable : inchangé' );
		yume_assert_true( abs( yume_theme_luminance( '#ffffff' ) - 1.0 ) < 0.0001 && yume_theme_luminance( '#000' ) < 0.0001, 'luminance WCAG' );
		yume_assert_same( null, yume_theme_luminance( 'linear-gradient(#fff,#000)' ), 'dégradé ignoré' );

		$css = (string) file_get_contents( get_theme_file_path( 'assets/css/yume.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		yume_assert_contains( 'html[data-yn-theme="nuit"] .yn-fond-clair {', $css, 'palette Papier sous le thème Nuit' );
		yume_assert_contains( 'html[data-yn-theme="papier"] .yn-fond-sombre,', $css, 'palette Nuit sous Papier et Sépia' );
	}
);

yume_tl_test(
	'styles des blocs : titres plus spécifiques que :root :where(hN) de WordPress 6.6 (RC-1)',
	static function () {
		// Sous WordPress 6.6 (minimum déclaré), theme.json produit « :root :where(h2){…} » (0,1,0),
		// chargé après les feuilles des blocs : une classe seule sur un titre perd.
		$titres  = array(
			'tome-list/style.css'     => array( 'yn-tome-list__titre', 'yn-tome-list__nom' ),
			'oeuvre-header/style.css' => array( 'yn-oeuvre-header__titre' ),
			'tome-header/style.css'   => array( 'yn-tome-header__titre', 'yn-galerie__titre' ),
			'tome-toc/style.css'      => array( 'yn-toc__titre' ),
		);
		$dossier = dirname( __DIR__ ) . '/includes/library/blocks/';
		foreach ( $titres as $fichier => $classes ) {
			$css = (string) file_get_contents( $dossier . $fichier ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
			foreach ( $classes as $classe ) {
				yume_assert_same( 0, preg_match( '/(?:^|[},])\s*\.' . preg_quote( $classe, '/' ) . '\s*[{,]/m', $css ), $fichier . ' : .' . $classe . ' jamais seul' );
				yume_assert_true( 1 === preg_match( '/\.[a-z-]+\s+\.' . preg_quote( $classe, '/' ) . '\s*\{/', $css ), $fichier . ' : .' . $classe . ' préfixé par son bloc' );
			}
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * yume/partenaires (section « Nos partenaires » de l'accueil)
 * -----------------------------------------------------------------------------
 */

/**
 * Règle la liste des partenaires (sans passer par l'assainissement) ; null : jamais enregistrée.
 *
 * @param array|null $liste Partenaires.
 */
function yume_tl_partenaires( $liste ): void {
	$reglages = get_option( 'yume_reglages', array() );
	$reglages = is_array( $reglages ) ? $reglages : array();
	if ( null === $liste ) {
		unset( $reglages['partenaires'] );
	} else {
		$reglages['partenaires'] = $liste;
	}
	// Écriture directe : l'assainissement (testé à part) est suspendu le temps de l'écriture.
	$filtres = $GLOBALS['wp_filter']['sanitize_option_yume_reglages'] ?? null;
	remove_all_filters( 'sanitize_option_yume_reglages' );
	update_option( 'yume_reglages', $reglages );
	if ( null !== $filtres ) {
		$GLOBALS['wp_filter']['sanitize_option_yume_reglages'] = $filtres; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
	delete_transient( 'yume_partenaires_logos' );
}

yume_tl_test(
	'partenaires : les quatre de l’ancien site tant que le réglage n’a jamais été enregistré',
	static function () {
		yume_tl_partenaires( null );
		$liste = partenaires();
		yume_assert_same( array( 'MassNovel', 'Novel Index', 'Novel de l’Aube', 'J-Garden' ), array_column( $liste, 'nom' ) );
		yume_assert_same( array( 'https://massnovel.fr/', 'https://www.novel-index.com/', 'https://noveldelaube.com/', 'https://j-garden.fr/' ), array_column( $liste, 'url' ) );
		yume_assert_same( array( 1317, 1312, 2486, 1313 ), array_column( $liste, 'logo' ), 'ID des logos de l’ancien site' );
		yume_assert_same( 'logo_ln-france4-1.webp', $liste[2]['fichier'], 'fichier attendu du logo' );
		yume_assert_contains( 'Regroupe toutes les sorties de manhwa', $liste[0]['description'] );

		// Réglage enregistré vide : aucun partenaire, le bloc ne rend rien en façade.
		yume_tl_partenaires( array() );
		yume_assert_same( array(), partenaires() );
		yume_assert_same( '', yume_tl_rendu( 'partenaires' ) );
	}
);

yume_tl_test(
	'partenaires : cartes en liens externes (nouvel onglet, rel noopener, nom accessible), monogramme sans logo',
	static function () {
		yume_tl_partenaires(
			array(
				array(
					'nom'         => 'MassNovel',
					'url'         => 'https://massnovel.fr/',
					'description' => 'Sorties <b>manhwa</b>',
					'logo'        => '',
				),
				array(
					'nom'         => 'Novel de l’Aube',
					'url'         => 'https://noveldelaube.com/',
					'description' => '',
					'logo'        => 999999,
				),
				array(
					'nom' => 'Sans lien',
					'url' => 'javascript:alert(1)',
				),
			)
		);
		$html = yume_tl_rendu( 'partenaires', array( 'title' => 'Amis & alliés' ) );
		yume_assert_contains( '<section class="', $html );
		yume_assert_contains( 'yn-partenaires', $html );
		yume_assert_same( 1, preg_match( '/aria-labelledby="([^"]+)".*<h2 class="yn-partenaires__titre" id="\1">Amis &amp; alliés<\/h2>/s', $html ), 'section nommée par son titre' );
		yume_assert_same( 2, yume_tl_compte( '<li class="yn-partenaires__item">', $html ), 'ligne sans lien http(s) écartée' );
		yume_assert_not_contains( 'javascript:', $html );
		yume_assert_same( 2, yume_tl_compte( 'target="_blank" rel="noopener"', $html ), 'nouvel onglet et rel="noopener"' );
		yume_assert_contains( 'href="https://noveldelaube.com/"', $html );
		yume_assert_contains( '<span class="yn-partenaire__nom">MassNovel</span>', $html, 'nom visible dans le lien (nom accessible)' );
		yume_assert_same( 2, yume_tl_compte( 's’ouvre dans un nouvel onglet', $html ), 'nouvel onglet annoncé' );
		yume_assert_contains( 'Sorties manhwa', $html, 'balises retirées de la description' );
		yume_assert_not_contains( '<b>', $html );
		yume_assert_not_contains( 'yn-partenaire__description"></span>', $html, 'pas de description vide' );
		yume_assert_contains( 'aria-hidden="true"><span class="yn-partenaire__monogramme">MN</span>', $html, 'monogramme sans logo' );
		yume_assert_contains( '<span class="yn-partenaire__monogramme">NA</span>', $html, 'monogramme : pièce jointe inexistante' );
		yume_assert_not_contains( '<img', $html );

		// Titre par défaut.
		yume_assert_contains( '>Nos partenaires</h2>', yume_tl_rendu( 'partenaires' ) );

		yume_assert_same( 'MN', initiales( 'MassNovel' ) );
		yume_assert_same( 'NI', initiales( 'Novel Index' ) );
		yume_assert_same( 'NA', initiales( 'Novel de l’Aube' ) );
		yume_assert_same( 'JG', initiales( 'J-Garden' ) );
		yume_assert_same( 'É', initiales( 'éclat' ) );
	}
);

yume_tl_test(
	'partenaires : logo (pièce jointe ou adresse) décoratif, alt vide, taille contrainte',
	static function () {
		$image = yume_tl_image( 'logo-partenaire.png' );
		yume_tl_partenaires(
			array(
				array(
					'nom'         => 'Avec média',
					'url'         => 'https://exemple.fr/',
					'description' => 'Une ligne',
					'logo'        => $image,
				),
				array(
					'nom'         => 'Avec adresse',
					'url'         => 'https://exemple.org/',
					'description' => 'Une autre',
					'logo'        => 'https://cdn.exemple.org/logo.svg" onerror="alert(1)',
				),
			)
		);
		$html = yume_tl_rendu( 'partenaires' );
		yume_assert_same( 2, yume_tl_compte( '<img', $html ) );
		yume_assert_same( 2, yume_tl_compte( 'alt=""', $html ), 'logo décoratif : le nom est écrit sur la carte' );
		yume_assert_contains( 'logo-partenaire.png', $html, 'image de la médiathèque' );
		yume_assert_contains( 'class="yn-partenaire__image', $html );
		yume_assert_not_contains( 'onerror="', $html, 'adresse du logo échappée' );
		yume_assert_not_contains( 'yn-partenaire__monogramme', $html );
	}
);

yume_tl_test(
	'partenaires : logo par défaut retenu seulement si la pièce jointe porte le fichier attendu',
	static function () {
		delete_transient( 'yume_partenaires_logos' );
		$autre = yume_tl_image( 'sans-rapport.jpg' );
		yume_assert_same( 0, resoudre_logo_partenaire( $autre, 'logomassnovel-2.png' )['id'], 'autre pièce jointe au même ID : écartée' );
		yume_assert_same( $autre, resoudre_logo_partenaire( $autre )['id'], 'ID saisi dans les réglages (sans fichier attendu) : retenu' );
		yume_assert_same( 0, resoudre_logo_partenaire( 99999999, '' )['id'], 'pièce jointe inexistante' );

		// Le média existe sous un autre ID (autre site, import) : trouvé par nom de fichier.
		$logo = yume_tl_image( 'logomassnovel-2.png' );
		yume_tl_image( 'xlogomassnovel-2.png' );
		yume_assert_same( $logo, resoudre_logo_partenaire( $autre, 'logomassnovel-2.png' )['id'], 'recherche par nom de fichier (cache oublié à l’ajout d’un média)' );
		yume_assert_same( $logo, resoudre_logo_partenaire( $logo, 'logomassnovel-2.png' )['id'], 'ID et fichier concordants' );
		yume_assert_same( $logo, resoudre_logo_partenaire( '', 'LOGOMASSNOVEL-2.PNG' )['id'], 'casse ignorée' );
		yume_assert_same( 0, resoudre_logo_partenaire( '', 'logo-1.png' )['id'], 'fichier absent de la médiathèque : monogramme' );
		yume_assert_same( 'https://exemple.fr/l.png', resoudre_logo_partenaire( 'https://exemple.fr/l.png' )['url'] );
		yume_assert_same( '', resoudre_logo_partenaire( 'javascript:alert(1)' )['url'] );

		// Partenaires par défaut : le logo de MassNovel est retrouvé, les autres sont des monogrammes
		// (sauf si la base locale contient les médias de l'ancien site).
		yume_tl_partenaires( null );
		$html = yume_tl_rendu( 'partenaires' );
		yume_assert_contains( 'logomassnovel-2.png', $html );
		yume_assert_same( 4, yume_tl_compte( 'class="yn-partenaire__logo', $html ) );

		// Formulaire des réglages : le logo par défaut devient l'ID trouvé sur ce site, sans fichier.
		$formulaire = logos_formulaire_partenaires( \Yume\Core\Core\partenaires_par_defaut() );
		yume_assert_same( $logo, $formulaire[0]['logo'] );
		yume_assert_false( isset( $formulaire[0]['fichier'] ) );
	}
);

yume_tl_test(
	'partenaires : assainissement du réglage (lien http(s) obligatoire, logo, 8 lignes au plus)',
	static function () {
		if ( ! function_exists( 'Yume\Core\Core\assainir_partenaires' ) ) {
			return;
		}
		$image  = yume_tl_image( 'logo-ok.png' );
		$lignes = array(
			array(
				'nom'         => ' <script>x</script>Team <em>A</em> ',
				'url'         => 'https://a.fr/',
				'description' => str_repeat( 'é', 300 ),
				'logo'        => (string) $image,
			),
			array(
				'nom'  => 'Piège',
				'url'  => 'javascript:alert(1)',
				'logo' => '',
			),
			array(
				'nom'  => 'Sans schéma',
				'url'  => 'ftp://b.fr/',
				'logo' => '',
			),
			array(
				'nom'  => '',
				'url'  => '',
				'logo' => '',
			),
			array(
				'nom'     => 'Logo URL',
				'url'     => 'http://c.fr/',
				'logo'    => 'javascript:alert(1)',
				'fichier' => '../x.png',
			),
			array(
				'nom'     => 'Logo inexistant',
				'url'     => 'https://d.fr/',
				'logo'    => '99999999',
				'fichier' => 'logo.png',
			),
			'pas une ligne',
		);
		$sortie = \Yume\Core\Core\assainir_partenaires( $lignes );
		yume_assert_same( array( 'Team A', 'Logo URL', 'Logo inexistant' ), array_column( $sortie, 'nom' ), 'lignes invalides ou vides retirées' );
		yume_assert_same( 'https://a.fr/', $sortie[0]['url'] );
		yume_assert_same( 160, mb_strlen( $sortie[0]['description'] ), 'description limitée' );
		yume_assert_same( $image, $sortie[0]['logo'], 'ID de pièce jointe existante' );
		yume_assert_same( '', $sortie[1]['logo'], 'adresse de logo javascript: refusée' );
		yume_assert_false( isset( $sortie[1]['fichier'] ), 'fichier attendu seulement avec un ID' );
		yume_assert_same( '', $sortie[2]['logo'], 'pièce jointe inexistante' );
		yume_assert_same(
			'https://cdn.fr/l.png',
			\Yume\Core\Core\assainir_partenaires(
				array(
					array(
						'nom'  => 'X',
						'url'  => 'https://x.fr',
						'logo' => 'https://cdn.fr/l.png',
					),
				)
			)[0]['logo']
		);

		$dix = array_fill(
			0,
			10,
			array(
				'nom' => 'P',
				'url' => 'https://p.fr/',
			)
		);
		yume_assert_same( 8, count( \Yume\Core\Core\assainir_partenaires( $dix ) ), '8 partenaires au plus' );
		yume_assert_same( array(), \Yume\Core\Core\assainir_partenaires( 'texte' ) );

		// Par le formulaire de Yume → Réglages (callback de l'option).
		$reglages = \Yume\Core\Core\assainir_reglages(
			array(
				'_formulaire' => '1',
				'partenaires' => array(
					array(
						'nom' => 'Ok',
						'url' => 'https://ok.fr/',
					),
					array(
						'nom' => 'Ko',
						'url' => 'javascript:alert(1)',
					),
				),
			)
		);
		yume_assert_same( array( 'Ok' ), array_column( $reglages['partenaires'], 'nom' ) );
		$defauts = \Yume\Core\Core\defauts_reglages();
		yume_assert_same( 4, count( $defauts['partenaires'] ), 'valeur par défaut du réglage' );
	}
);

yume_tl_test(
	'partenaires : feuille du bloc (grille responsive, logo contenu), accueil et pied de page',
	static function () {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/includes/library/blocks/partenaires/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		yume_assert_contains( 'object-fit: contain', $css );
		yume_assert_contains( 'repeat(2, minmax(0, 1fr))', $css, '2 × 2' );
		yume_assert_contains( 'grid-template-columns: minmax(0, 1fr)', $css, 'une colonne sur mobile' );
		yume_assert_contains( 'html[data-yn-theme="papier"] .yn-partenaires', $css );
		yume_assert_same( 0, preg_match( '/(?:^|[},])\s*\.yn-partenaires__titre\s*[{,]/m', $css ), 'titre préfixé par son bloc' );

		$theme = get_theme_root() . '/yume';
		if ( is_dir( $theme ) ) {
			$accueil = (string) file_get_contents( $theme . '/templates/front-page.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
			yume_assert_contains( '<!-- wp:yume/partenaires', $accueil, 'bloc dans le modèle de l’accueil' );
			$pied = (string) file_get_contents( $theme . '/parts/footer.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
			yume_assert_contains( 'href="https://noveldelaube.com/"', $pied );
			yume_assert_not_contains( 'noveldelaube.fr', $pied );
			// BUG-11 : « Contactez-nous » dans le pied de page ; « Contact » de l'en-tête reste le Discord.
			yume_assert_contains( '"label":"Contactez-nous","url":"/contactez-nous/"', $pied );
			yume_assert_contains( '"className":"yn-lien-contact"', $pied );
			// BUG-02 : un seul h1 sur l'accueil, visuellement masqué, dans le repère <main>.
			yume_assert_same( 1, preg_match_all( '/<h1\b/', $accueil ) );
			yume_assert_same( 1, preg_match( '/<main[^>]*>\s*<!-- wp:heading \{"level":1,"className":"yn-visually-hidden"\} -->\s*<h1 class="wp-block-heading yn-visually-hidden">Yume Novel — fan-traductions de light novels<\/h1>/u', $accueil ) );
			$feuille = (string) file_get_contents( $theme . '/assets/css/yume.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
			yume_assert_contains( '.yn-accueil > .yn-visually-hidden + * {', $feuille, 'la première section garde sa place' );
		}
	}
);

yume_tl_test(
	'partenaires : variante en ligne (noms liés séparés par « · »), rien sans partenaire (BUG-03)',
	static function () {
		yume_tl_partenaires(
			array(
				array(
					'nom' => 'Ok & Cie',
					'url' => 'https://ok.example/',
				),
				array(
					'nom' => 'Ko',
					'url' => 'javascript:alert(1)',
				),
				array(
					'nom' => 'Deux',
					'url' => 'https://deux.example/',
				),
			)
		);
		yume_assert_same( '<a href="https://ok.example/">Ok &amp; Cie</a> · <a href="https://deux.example/">Deux</a>', \Yume\Core\Library\partenaires_en_ligne() );
		$html = yume_tl_rendu( 'partenaires', array( 'variante' => 'en-ligne' ) );
		yume_assert_same( 1, preg_match( '/^<p class="[^"]*yn-partenaires-en-ligne[^"]*">Partenaires&nbsp;: <a href="https:\/\/ok\.example\/">Ok &amp; Cie<\/a> · <a href="https:\/\/deux\.example\/">Deux<\/a><\/p>$/u', $html ), $html );
		yume_assert_not_contains( 'javascript:', $html );
		yume_assert_not_contains( '<section', $html );

		yume_tl_partenaires( array() );
		yume_assert_same( '', \Yume\Core\Library\partenaires_en_ligne() );
		yume_assert_same( '', yume_tl_rendu( 'partenaires', array( 'variante' => 'en-ligne' ) ) );
	}
);

yume_tl_test(
	'thème : le pied de page reflète le réglage « partenaires » (BUG-03)',
	static function () {
		if ( ! function_exists( 'yume_theme_partenaires_pied' ) ) {
			return; // Thème Yume inactif.
		}
		$pied    = (string) file_get_contents( get_template_directory() . '/parts/footer.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		$mention = preg_match( '/<!-- wp:paragraph \{[^}]*yn-copyright[^}]*\} -->.*?<!-- \/wp:paragraph -->/s', $pied, $trouve ) ? $trouve[0] : '';
		$rendu   = static fn(): string => do_blocks( $mention );
		$annee   = wp_date( 'Y' );
		yume_assert_true( '' !== $mention, 'paragraphe yn-copyright du pied de page' );

		// Jamais enregistré : les quatre partenaires par défaut, comme le modèle.
		yume_tl_partenaires( null );
		$html = $rendu();
		yume_assert_contains( '<a href="https://massnovel.fr/">MassNovel</a> · <a href="https://www.novel-index.com/">Novel Index</a> · <a href="https://noveldelaube.com/">Novel de l’Aube</a> · <a href="https://j-garden.fr/">J-Garden</a></p>', $html );
		yume_assert_contains( 'Yume Novel © ' . $annee . ' · Fan-traductions à but non lucratif · Partenaires&nbsp;: <a', $html );

		// Liste réduite : seul le partenaire réglé apparaît.
		yume_tl_partenaires(
			array(
				array(
					'nom' => 'Ok Partner',
					'url' => 'https://ok.example/',
				),
			)
		);
		$html = $rendu();
		yume_assert_contains( 'Partenaires&nbsp;: <a href="https://ok.example/">Ok Partner</a></p>', $html );
		yume_assert_not_contains( 'massnovel', $html );
		yume_assert_same( 1, substr_count( $html, '<a ' ), 'un seul lien' );

		// Liste vide : plus de mention « Partenaires », le reste de la ligne demeure.
		yume_tl_partenaires( array() );
		$html = $rendu();
		yume_assert_not_contains( 'Partenaires', $html );
		yume_assert_contains( 'Fan-traductions à but non lucratif</p>', $html );
	}
);

yume_tl_test(
	'thème : titres 404 et recherche, rôles et dates en français quelle que soit la langue (BUG-07)',
	static function () {
		if ( ! function_exists( 'yume_theme_titre_document' ) ) {
			return; // Thème Yume inactif.
		}
		global $wp_query;
		$anglais = static fn(): string => 'en_US';
		add_filter( 'pre_determine_locale', $anglais );
		try {
			$wp_query = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$wp_query->set_404();
			yume_assert_same( 'Page introuvable', apply_filters( 'document_title_parts', array( 'title' => 'Page not found' ) )['title'] );

			$wp_query            = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$wp_query->is_search = true;
			$wp_query->set( 's', 'lanterne' );
			yume_assert_same( 'Résultats pour « lanterne »', apply_filters( 'document_title_parts', array( 'title' => 'Search Results for “lanterne”' ) )['title'] );
			$wp_query->set( 's', '' );
			yume_assert_same( 'Recherche', apply_filters( 'document_title_parts', array( 'title' => 'x' ) )['title'] );

			yume_assert_same( 'Administrateur', translate_user_role( 'Administrator' ) );
			yume_assert_same( 'Éditeur', translate_user_role( 'Editor' ) );
			yume_assert_same( 'Lecteur', translate_user_role( 'Subscriber' ), 'nom de l’extension conservé' );
			yume_assert_same( 'Traducteur', translate_user_role( 'Traducteur' ), 'rôle Yume inchangé' );

			$moment = ( new DateTimeImmutable( '2026-09-28 12:00:00', wp_timezone() ) )->getTimestamp();
			yume_assert_same( '28 septembre 2026', wp_date( 'j F Y', $moment ) );
			yume_assert_same( '28 sept.', wp_date( 'j M', $moment ) );
			yume_assert_same( 'lundi 28', wp_date( 'l j', $moment ) );
			yume_assert_same( 'lun. 28 à 12h00', wp_date( 'D j \\à G\\hi', $moment ), 'caractères échappés conservés' );
			yume_assert_same( '2026-09-28', wp_date( 'Y-m-d', $moment ), 'format sans nom inchangé' );
		} finally {
			remove_filter( 'pre_determine_locale', $anglais );
		}
	}
);

yume_test(
	'partenaires : sans les médias de l’ancien site, les logos embarqués remplacent les initiales',
	static function () {
		yume_tl_partenaires( null );
		$html = yume_tl_rendu( 'partenaires' );
		foreach ( array( 'massnovel', 'novel-index', 'novel-de-laube', 'j-garden' ) as $logo ) {
			yume_assert_contains( 'blocks/partenaires/logos/' . $logo . '.png', $html );
			yume_assert_true( is_readable( YUME_CORE_DIR . 'includes/library/blocks/partenaires/logos/' . $logo . '.png' ), $logo );
		}
		yume_assert_not_contains( 'yn-partenaire__monogramme', $html );

		// Partenaire ajouté sans logo : toujours les initiales.
		yume_tl_partenaires(
			array(
				array(
					'nom'         => 'Équipe amie',
					'url'         => 'https://exemple.org/',
					'description' => '',
					'logo'        => '',
				),
			)
		);
		yume_assert_contains( 'yn-partenaire__monogramme', yume_tl_rendu( 'partenaires' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page « Illustrations » d'un tome
 * -----------------------------------------------------------------------------
 */

/**
 * Tome 1 illustré (galerie de trois planches) et ses trois chapitres, puis un tome 2 sans galerie.
 *
 * @return array{o:int,t1:int,t2:int,c:int[],d:int[],i:int[]}
 */
function yume_tl_illustre(): array {
	flush_rewrite_rules( false );
	$o  = yume_tl_oeuvre( 'Les Lanternes' );
	$i  = array(
		yume_tl_image( 'planche-1.jpg', '', 'Frontispice' ),
		yume_tl_image( 'planche-2.jpg', 'Mira sur le toit' ),
		yume_tl_image( 'planche-3.jpg' ),
	);
	$t1 = yume_tl_tome( $o, 1, array( 'meta_input' => array( 'yume_illustrations' => $i ) ) );
	$t2 = yume_tl_tome( $o, 2 );
	return array(
		'o'  => $o,
		't1' => $t1,
		't2' => $t2,
		'c'  => array( yume_tl_chapitre( $t1, 1 ), yume_tl_chapitre( $t1, 2 ), yume_tl_chapitre( $t1, 3 ) ),
		'd'  => array( yume_tl_chapitre( $t2, 1 ), yume_tl_chapitre( $t2, 2 ) ),
		'i'  => $i,
	);
}

/**
 * Place la requête principale sur la page Illustrations d'un tome.
 *
 * @param int $tome_id Tome.
 */
function yume_tl_aller_illustrations( int $tome_id ): void {
	global $wp_query, $wp_the_query, $post;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées par yume_tl_test().
	$wp_query     = new WP_Query(
		array(
			'p'                       => $tome_id,
			'post_type'               => 'yume_tome',
			'yume_page_illustrations' => 1,
		)
	);
	$wp_the_query = $wp_query;
	$post         = get_post( $tome_id );
	// phpcs:enable
}

yume_tl_test(
	'page Illustrations : en-tête, planches (grande taille, chargement différé, légendes, alt, image en grand), Commencer la lecture',
	static function () {
		$s = yume_tl_illustre();
		yume_assert_same( '', yume_tl_rendu( 'tome-illustrations' ), 'sans contexte : rien' );
		yume_assert_same( '', yume_tl_rendu( 'tome-illustrations', array(), $s['t2'] ), 'tome sans galerie : rien' );

		yume_tl_aller_illustrations( $s['t1'] );
		$url = yume_url_illustrations( $s['t1'] );
		yume_assert_true( '' !== $url );

		$entete = yume_tl_rendu( 'chapter-header', array(), $s['t1'] );
		yume_assert_contains( 'class="yn-chapter-header yn-chapter-header--illustrations wp-block-yume-chapter-header"', $entete );
		yume_assert_contains( '<h1 class="yn-chapter-header__titre">Illustrations</h1><p class="yn-subtitle">Tome 1</p>', $entete );
		yume_assert_contains( '>Les Lanternes</a>', $entete, 'fil d’Ariane : œuvre' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $s['t1'] ) ) . '">Tome 1</a>', $entete, 'fil d’Ariane : tome' );
		yume_assert_contains( 'aria-current="page"><span>Illustrations</span>', $entete );
		yume_assert_contains( '3 illustrations', $entete );

		$planches = yume_tl_rendu( 'tome-illustrations', array(), $s['t1'] );
		yume_assert_true( 0 === strpos( $planches, '<div class="yn-tome-illustrations wp-block-yume-tome-illustrations">' ), $planches );
		yume_assert_same( 3, yume_tl_compte( '<figure class="yn-illustration yn-tome-illustrations__planche"', $planches ) );
		yume_assert_true( strpos( $planches, 'planche-1.jpg' ) < strpos( $planches, 'planche-2.jpg' ) && strpos( $planches, 'planche-2.jpg' ) < strpos( $planches, 'planche-3.jpg' ), 'ordre de lecture' );
		yume_assert_same( 1, yume_tl_compte( 'loading="eager"', $planches ), 'première planche chargée tout de suite' );
		yume_assert_same( 2, yume_tl_compte( 'loading="lazy"', $planches ), 'les suivantes en différé' );
		yume_assert_contains( 'alt="Illustration 1 — Les Lanternes, Tome 1"', $planches, 'alt de repli, comme la galerie' );
		yume_assert_contains( 'alt="Mira sur le toit"', $planches );
		yume_assert_contains( '<figcaption>Frontispice</figcaption>', $planches );
		yume_assert_same( 1, yume_tl_compte( '<figcaption>', $planches ), 'légende seulement si elle existe' );
		yume_assert_contains( '<a class="yn-tome-illustrations__lien" href="' . esc_url( wp_get_attachment_url( $s['i'][1] ) ) . '">', $planches, 'lien vers l’image en grand' );

		$nav = yume_tl_rendu( 'chapter-nav', array(), $s['t1'] );
		yume_assert_contains( 'yn-chapter-nav--illustrations', $nav );
		yume_assert_contains( 'rel="next" href="' . esc_url( get_permalink( $s['c'][0] ) ) . '"', $nav );
		yume_assert_contains( 'Commencer la lecture<span aria-hidden="true"> · </span><span class="yn-visually-hidden"> : </span>Chapitre 1', $nav );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $s['t1'] ) ) . '">', $nav, 'Sommaire du tome' );
		yume_assert_not_contains( 'rel="prev"', $nav );

		// La galerie de la page du tome reste en place.
		yume_tl_aller( $s['t1'] );
		$fiche = yume_tl_rendu( 'tome-header', array(), $s['t1'] );
		yume_assert_contains( 'Illustrations <span class="yn-muted">(3)</span>', $fiche );
		yume_assert_same( '', yume_tl_rendu( 'chapter-header', array(), $s['t1'] ), 'page du tome : pas d’en-tête de lecture' );
	}
);

yume_tl_test(
	'page Illustrations : « précédent » du chapitre 1 (et rel=prev), les autres chapitres inchangés',
	static function () {
		$s   = yume_tl_illustre();
		$url = yume_url_illustrations( $s['t1'] );

		$nav = yume_tl_rendu( 'chapter-nav', array(), $s['c'][0] );
		yume_assert_contains( 'rel="prev" href="' . esc_url( $url ) . '"', $nav, 'chapitre 1 → Illustrations' );
		yume_assert_contains( '<span class="yn-visually-hidden">Page précédente : </span>Illustrations', $nav );
		yume_assert_contains( '<link rel="prev" href="' . esc_url( $url ) . '" />', balises_voisins( $s['c'][0] ) );

		$nav = yume_tl_rendu( 'chapter-nav', array(), $s['c'][1] );
		yume_assert_contains( 'rel="prev" href="' . esc_url( get_permalink( $s['c'][0] ) ) . '"', $nav, 'chapitre 2 → chapitre 1' );

		// Tome 2 sans galerie : son chapitre 1 revient au dernier chapitre du tome 1.
		$nav = yume_tl_rendu( 'chapter-nav', array(), $s['d'][0] );
		yume_assert_contains( 'rel="prev" href="' . esc_url( get_permalink( $s['c'][2] ) ) . '"', $nav );
		yume_assert_not_contains( '/illustrations/', $nav );

		// Galerie ajoutée au tome 2 : le chapitre 1 du tome 2 est précédé de ses illustrations.
		update_post_meta( $s['t2'], 'yume_illustrations', array( $s['i'][2] ) );
		$nav = yume_tl_rendu( 'chapter-nav', array(), $s['d'][0] );
		yume_assert_contains( 'rel="prev" href="' . esc_url( yume_url_illustrations( $s['t2'] ) ) . '"', $nav );
	}
);

yume_tl_test(
	'page Illustrations : première entrée du sommaire du tome',
	static function () {
		$s    = yume_tl_illustre();
		$url  = yume_url_illustrations( $s['t1'] );
		$html = yume_tl_rendu( 'tome-toc', array(), $s['t1'] );
		yume_assert_contains( '<li class="yn-toc__item yn-toc__item--illustrations"><a class="yn-toc__lien" href="' . esc_url( $url ) . '"><span class="yn-toc__numero">Illustrations</span><span class="yn-toc__sous-titre">3 planches</span>', $html );
		yume_assert_true( strpos( $html, 'yn-toc__item--illustrations' ) < strpos( $html, esc_url( get_permalink( $s['c'][0] ) ) ), 'avant le chapitre 1' );
		yume_assert_same( 4, yume_tl_compte( '<li class="yn-toc__item', $html ) );
		yume_assert_not_contains( 'yn-toc__item--illustrations', yume_tl_rendu( 'tome-toc', array(), $s['t2'] ), 'tome sans galerie' );

		// Sur la page Illustrations, l'entrée est la page courante.
		yume_tl_aller_illustrations( $s['t1'] );
		yume_assert_contains( 'yn-toc__item--illustrations is-current"><a class="yn-toc__lien" href="' . esc_url( $url ) . '" aria-current="page">', yume_tl_rendu( 'tome-toc', array(), $s['t1'] ) );
	}
);

yume_tl_test(
	'page Illustrations : « Commencer la lecture » / « Lire en ligne » y mènent sans position dans le tome',
	static function () {
		if ( ! function_exists( 'Yume\Core\Reader\enregistrer_progression' ) ) {
			return;
		}
		$s   = yume_tl_illustre();
		$url = yume_url_illustrations( $s['t1'] );
		$c1  = esc_url( get_permalink( $s['c'][0] ) );

		$fiche = yume_tl_rendu( 'tome-header', array(), $s['t1'] );
		yume_assert_contains( 'href="' . esc_url( $url ) . '" data-yn-debut-chapitre="' . $c1 . '" data-yn-debut-tome="' . $s['t1'] . '" data-yn-debut-oeuvre="' . $s['o'] . '">Commencer la lecture', $fiche, 'visiteur' );
		yume_assert_true( wp_script_is( 'yume-debut-lecture', 'enqueued' ), 'script des positions locales' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $s['d'][0] ) ) . '">Commencer la lecture', yume_tl_rendu( 'tome-header', array(), $s['t2'] ), 'tome sans galerie : chapitre 1' );
		$liste = yume_tl_rendu( 'tome-list', array(), $s['o'] );
		yume_assert_contains( 'href="' . esc_url( $url ) . '" data-yn-debut-chapitre="' . $c1 . '"', $liste, 'ligne du tome 1 : Lire en ligne → Illustrations' );

		// Membre sans position : Illustrations ; position dans le tome 2 : tome 1 toujours par les illustrations.
		$u = yume_factory_user();
		wp_set_current_user( $u );
		yume_assert_contains( 'href="' . esc_url( $url ) . '"', yume_tl_rendu( 'tome-header', array(), $s['t1'] ) );
		\Yume\Core\Reader\enregistrer_progression( $u, $s['d'][0], 4, 30 );
		wp_cache_flush();
		yume_assert_contains( 'href="' . esc_url( $url ) . '"', yume_tl_rendu( 'tome-header', array(), $s['t1'] ), 'position dans un autre tome' );

		// Position dans le tome 1 : le bouton ouvre le premier chapitre (les reprises gardent leur ancre).
		\Yume\Core\Reader\enregistrer_progression( $u, $s['c'][1], 7, 40 );
		wp_cache_flush();
		$fiche = yume_tl_rendu( 'tome-header', array(), $s['t1'] );
		yume_assert_contains( 'href="' . $c1 . '">Commencer la lecture', $fiche );
		yume_assert_not_contains( 'data-yn-debut', $fiche );
		if ( has_filter( 'yume_bibliotheque_ligne_tome' ) ) {
			$liste = yume_tl_rendu( 'tome-list', array(), $s['o'] );
			yume_assert_contains( esc_url( get_permalink( $s['c'][1] ) . '#yn-p-8' ) . '">Reprendre', $liste, 'Reprendre : chapitre et ancre' );
			yume_assert_not_contains( '/illustrations/', $liste );
		}
		wp_set_current_user( 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Open Graph, Twitter Card, meta description (BUG-08)
 * -----------------------------------------------------------------------------
 */

/**
 * Balises de partage de la page courante (requête principale).
 *
 * @return array<string,string>
 */
function yume_tl_og(): array {
	return \Yume\Core\Library\balises_open_graph();
}

/**
 * Fait de la requête principale l'accueil (derniers articles en page d'accueil).
 */
function yume_tl_aller_accueil(): void {
	global $wp_query, $wp_the_query;
	update_option( 'show_on_front', 'posts' );
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées par yume_tl_test().
	$wp_query          = new WP_Query();
	$wp_query->is_home = true;
	$wp_the_query      = $wp_query;
	// phpcs:enable
}

yume_tl_test(
	'Open Graph : accueil (website), œuvre et tome (book), chapitre et article (article), Twitter Card, Jetpack retiré',
	static function () {
		$couv = yume_tl_image( 'couv-grimgar.jpg', 'Couverture du tome 9' );
		$id   = yume_tl_grimgar();
		$tome = yume_tl_tome( $id, 9, array( 'meta_input' => array( '_thumbnail_id' => $couv ) ) );
		$c1   = yume_tl_chapitre( $tome, 1, array( 'meta_input' => array( 'yume_sous_titre' => 'La Crête' ) ) );

		yume_assert_false( apply_filters( 'jetpack_enable_open_graph', true ), 'Open Graph de Jetpack désactivé' );
		yume_assert_true( false !== has_action( 'wp_head', 'Yume\Core\Library\afficher_open_graph' ), 'accroché à wp_head' );

		// Œuvre.
		yume_tl_aller( $id );
		$og = yume_tl_og();
		yume_assert_same( 'book', $og['og:type'] );
		yume_assert_same( 'Grimgar of Fantasy and Ash', $og['og:title'] );
		yume_assert_same( 'fr_FR', $og['og:locale'] );
		yume_assert_same( wp_get_canonical_url( $id ), $og['og:url'] );
		yume_assert_contains( 'Quand Haruhiro', $og['og:description'] );
		yume_assert_same( $og['og:description'], $og['description'], 'meta description = og:description' );
		yume_assert_same( '@YumeNovel', $og['twitter:site'] );
		yume_assert_same( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), $og['og:site_name'] );

		// Tome : sa couverture, dimensions et texte alternatif.
		yume_tl_aller( $tome );
		$og = yume_tl_og();
		yume_assert_same( 'book', $og['og:type'] );
		yume_assert_same( wp_get_attachment_image_url( $couv, 'large' ), $og['og:image'] );
		yume_assert_same( '480', $og['og:image:width'] );
		yume_assert_same( '720', $og['og:image:height'] );
		yume_assert_same( 'Couverture du tome 9', $og['og:image:alt'] );
		yume_assert_same( 'summary_large_image', $og['twitter:card'] );
		$html = \Yume\Core\Library\html_open_graph( $og );
		yume_assert_contains( '<meta property="og:type" content="book" />', $html );
		yume_assert_contains( '<meta name="twitter:card" content="summary_large_image" />', $html );
		yume_assert_contains( '<meta name="description" content="', $html );
		yume_assert_contains( '<meta property="og:image" content="' . esc_url( wp_get_attachment_image_url( $couv, 'large' ) ) . '" />', $html );

		// Chapitre : article, couverture du tome, titre complet.
		yume_tl_aller( $c1 );
		$og = yume_tl_og();
		yume_assert_same( 'article', $og['og:type'] );
		yume_assert_same( 'Chapitre 1 — La Crête · ' . get_the_title( $tome ), $og['og:title'] );
		yume_assert_same( wp_get_attachment_image_url( $couv, 'large' ), $og['og:image'] );
		yume_assert_true( isset( $og['article:published_time'] ) );
		yume_assert_contains( 'Les gremlins chantaient', $og['og:description'] );

		// Article sans image : bannière du site par défaut ; échappement.
		$banniere                = yume_tl_image( 'banniere.jpg' );
		$reglages                = (array) get_option( 'yume_reglages', array() );
		$reglages['banniere_id'] = $banniere;
		update_option( 'yume_reglages', $reglages );
		$article = yume_factory_post(
			array(
				'post_title'   => 'Sortie du tome 9 & « bonus »',
				'post_excerpt' => 'Le tome 9 est <b>disponible</b> "maintenant".',
			)
		);
		yume_tl_aller( $article );
		$og = yume_tl_og();
		yume_assert_same( 'article', $og['og:type'] );
		yume_assert_same( wp_get_attachment_image_url( $banniere, 'large' ), $og['og:image'], 'image par défaut : bannière' );
		yume_assert_same( 'Le tome 9 est disponible "maintenant".', $og['description'] );
		$html = \Yume\Core\Library\html_open_graph( $og );
		yume_assert_contains( 'content="Le tome 9 est disponible &quot;maintenant&quot;."', $html, 'attribut échappé' );
		yume_assert_not_contains( '<b>', $html );

		// Image mise en avant de l'article prioritaire.
		set_post_thumbnail( $article, $couv );
		yume_assert_same( wp_get_attachment_image_url( $couv, 'large' ), yume_tl_og()['og:image'] );

		// Accueil : website, bannière.
		yume_tl_aller_accueil();
		$og = yume_tl_og();
		yume_assert_same( 'website', $og['og:type'] );
		yume_assert_same( home_url( '/' ), $og['og:url'] );
		yume_assert_same( wp_get_attachment_image_url( $banniere, 'large' ), $og['og:image'] );

		// Filtre yume_open_graph.
		$filtre = static function ( array $balises ): array {
			$balises['twitter:site'] = '@Autre';
			unset( $balises['og:locale'] );
			return $balises;
		};
		add_filter( 'yume_open_graph', $filtre );
		$og = yume_tl_og();
		remove_filter( 'yume_open_graph', $filtre );
		yume_assert_same( '@Autre', $og['twitter:site'] );
		yume_assert_false( isset( $og['og:locale'] ) );
	}
);

yume_tl_test(
	'Open Graph : rien sur les 404, la recherche, les pages privées, les aperçus ; description absente si vide',
	static function () {
		global $wp_query, $wp_the_query;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées par yume_tl_test().
		$wp_query = new WP_Query();
		$wp_query->set_404();
		$wp_the_query = $wp_query;
		yume_assert_same( array(), yume_tl_og(), '404' );
		yume_assert_same( '', \Yume\Core\Library\html_open_graph( yume_tl_og() ) );

		$wp_query            = new WP_Query();
		$wp_query->is_search = true;
		$wp_the_query        = $wp_query;
		yume_assert_same( array(), yume_tl_og(), 'recherche (noindex)' );
		// phpcs:enable

		// Page Mon compte (yume_pages) et sous-page de l'espace équipe.
		$compte  = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Mon compte',
			)
		);
		$equipe  = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Espace équipe',
			)
		);
		$publier = yume_factory_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Publier',
				'post_parent' => $equipe,
			)
		);
		$autre   = yume_factory_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Connexion bis',
				'post_content' => '<!-- wp:yume/account /-->',
			)
		);
		update_option(
			'yume_pages',
			array(
				'compte' => $compte,
				'equipe' => $equipe,
			)
		);
		foreach ( array( $compte, $equipe, $publier, $autre ) as $page ) {
			yume_tl_aller( $page );
			yume_assert_same( array(), yume_tl_og(), 'page privée ' . get_the_title( $page ) );
		}
		$publique = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Mentions légales',
			)
		);
		yume_tl_aller( $publique );
		yume_assert_same( 'website', yume_tl_og()['og:type'] ?? '', 'page publique' );

		// Œuvre sans synopsis : ni description ni og:description ; chapitre en aperçu : rien.
		$oeuvre = yume_tl_oeuvre( 'Sans résumé', array(), array( 'post_content' => '' ) );
		yume_tl_aller( $oeuvre );
		$og = yume_tl_og();
		yume_assert_false( isset( $og['description'] ) || isset( $og['og:description'] ), 'description vide omise' );
		yume_assert_not_contains( 'name="description"', \Yume\Core\Library\html_open_graph( $og ) );
		yume_assert_same( 'summary', $og['twitter:card'], 'sans image' );

		$tome      = yume_tl_tome( $oeuvre, 1 );
		$brouillon = yume_tl_chapitre( $tome, 1, array( 'post_status' => 'draft' ) );
		yume_tl_aller( $brouillon );
		yume_assert_same( array(), yume_tl_og(), 'aperçu d’un brouillon' );

		// Chapitre sans texte : résumé de l'œuvre.
		$grimgar = yume_tl_grimgar();
		$t9      = yume_tl_tome( $grimgar, 9 );
		$vide    = yume_tl_chapitre( $t9, 1, array( 'post_content' => '' ) );
		yume_tl_aller( $vide );
		yume_assert_contains( 'Quand Haruhiro', yume_tl_og()['og:description'] ?? '' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Nombre de chapitres (yume_nb_chapitres) et requêtes de la fiche (BUG-09)
 * -----------------------------------------------------------------------------
 */

yume_tl_test(
	'yume_nb_chapitres : maintenu à l’ajout, au retrait, à la suppression ; identique au calcul ; repli si absent',
	static function () {
		$o      = yume_tl_oeuvre( 'Compteur' );
		$t      = yume_tl_tome( $o, 1 );
		$n      = static fn(): int => \Yume\Core\Library\nb_chapitres_tome( $t );
		$calcul = static fn(): int => count( yume_get_chapitres( $t ) );

		yume_assert_same( 0, $n() );
		$c1 = yume_tl_chapitre( $t, 1 );
		$c2 = yume_tl_chapitre( $t, 2 );
		yume_tl_chapitre( $t, null, array( 'meta_input' => array( 'yume_nature' => 'postface' ) ) );
		yume_tl_chapitre( $t, 3, array( 'post_status' => 'draft' ) );
		yume_assert_same( '3', (string) get_post_meta( $t, 'yume_nb_chapitres', true ), 'méta tenue par core' );
		yume_assert_same( $calcul(), $n() );
		yume_assert_same( 3, \Yume\Core\Library\calculer_stats_tome( $t )['publies'], 'identique aux statistiques' );

		wp_trash_post( $c2 );
		yume_assert_same( 2, $n(), 'chapitre à la corbeille' );
		wp_delete_post( $c1, true );
		yume_assert_same( 1, $n(), 'chapitre supprimé' );
		yume_assert_same( $calcul(), $n() );

		// Tome migré sans la méta : calcul.
		delete_post_meta( $t, 'yume_nb_chapitres' );
		yume_assert_same( 1, $n(), 'repli sur le calcul' );
	}
);

yume_tl_test(
	'fiche d’œuvre : statistiques des tomes en une requête, identiques au calcul tome par tome, et nombre de requêtes indépendant du nombre de tomes',
	static function () {
		global $wpdb;
		$o     = yume_tl_oeuvre( 'Série longue' );
		$tomes = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$tomes[ $i ] = yume_tl_tome( $o, $i, array( 'post_date' => yume_tl_date( 40 - $i ) ) );
			yume_tl_chapitre( $tomes[ $i ], 2 );
			yume_tl_chapitre( $tomes[ $i ], 1, array( 'menu_order' => 1 ) );
			yume_tl_chapitre(
				$tomes[ $i ],
				null,
				array(
					'meta_input' => array( 'yume_nature' => 'prologue' ),
					'menu_order' => 0,
				)
			);
		}
		yume_tl_chapitre( $tomes[2], 3, array( 'post_status' => 'draft' ) );
		// Tome sans chapitre (cache à 0) et arc en cours (chapitres à venir).
		$vide = yume_tl_tome( $o, 9 );
		$arc  = yume_tl_tome( $o, 10, array( 'meta_input' => array( 'yume_nature' => 'arc' ) ) );
		yume_tl_chapitre( $arc, 1 );
		yume_tl_chapitre(
			$arc,
			2,
			array(
				'post_status' => 'future',
				'post_date'   => yume_tl_date( -3 ),
			)
		);

		renouveler_version();
		$stats = \Yume\Core\Library\stats_oeuvre( $o );
		foreach ( array_merge( $tomes, array( $vide, $arc ) ) as $tome_id ) {
			yume_assert_same( \Yume\Core\Library\calculer_stats_tome( $tome_id ), $stats[ $tome_id ], 'tome ' . $tome_id );
		}
		yume_assert_same( 0, $stats[ $vide ]['publies'] );
		yume_assert_true( $stats[ $arc ]['en_cours'] && 1 === $stats[ $arc ]['a_venir'], 'arc en cours' );

		// Liste des tomes (statistiques en cache) : autant de requêtes pour 3 que pour 10 tomes.
		$mesurer = static function ( int $oeuvre ) use ( $wpdb ): int {
			yume_tl_rendu( 'tome-list', array(), $oeuvre ); // Statistiques en cache.
			wp_cache_flush();
			$avant = $wpdb->num_queries;
			$html  = yume_tl_rendu( 'tome-list', array(), $oeuvre );
			yume_assert_contains( 'Lire en ligne', $html );
			return $wpdb->num_queries - $avant;
		};
		$petite  = yume_tl_oeuvre( 'Série courte' );
		for ( $i = 1; $i <= 3; $i++ ) {
			$t = yume_tl_tome( $petite, $i );
			yume_tl_chapitre( $t, 1 );
			yume_tl_chapitre( $t, 2 );
		}
		$q_petite = $mesurer( $petite );
		$q_longue = $mesurer( $o );
		yume_assert_true( $q_longue <= $q_petite + 2, "requêtes : $q_longue pour 10 tomes, $q_petite pour 3" );

		// Permaliens des premiers chapitres : segments d'URL amorcés en une requête.
		wp_cache_flush();
		$premiers = array_map( static fn( int $t ): int => (int) $stats[ $t ]['premier'], $tomes );
		\Yume\Core\Library\amorcer_caches( array_merge( array( $o ), array_values( $tomes ), $premiers ) );
		wp_load_alloptions(); // Options vidées par wp_cache_flush() : hors mesure.
		$avant = $wpdb->num_queries;
		foreach ( $premiers as $chapitre ) {
			get_permalink( $chapitre );
		}
		yume_assert_same( 0, $wpdb->num_queries - $avant, 'aucune requête par permalien' );
		yume_assert_same( \Yume\Core\Core\segments_tome( $tomes[1] ), \Yume\Core\Core\calculer_segments( \Yume\Core\Core\ids_par_meta( 'yume_chapitre', 'yume_tome_id', $tomes[1], \Yume\Core\Core\statuts_actifs() ) ), 'segments identiques' );
	}
);

yume_tl_test(
	'pages Yume (yume_pages) chargées en une fois avant le rendu : yume_url_page() sans requête',
	static function () {
		global $wpdb;
		$pages = array();
		foreach ( array( 'bibliotheque', 'planning', 'compte', 'equipe' ) as $cle ) {
			$pages[ $cle ] = yume_factory_post(
				array(
					'post_type'  => 'page',
					'post_title' => ucfirst( $cle ),
					'post_name'  => $cle,
				)
			);
		}
		update_option( 'yume_pages', $pages );
		yume_assert_true( false !== has_action( 'template_redirect', 'Yume\Core\Library\amorcer_pages_yume' ) );
		wp_cache_flush();
		wp_load_alloptions();
		\Yume\Core\Library\amorcer_pages_yume();
		$avant = $wpdb->num_queries;
		foreach ( array_keys( $pages ) as $cle ) {
			yume_url_page( $cle );
		}
		yume_assert_same( 0, $wpdb->num_queries - $avant );
	}
);
