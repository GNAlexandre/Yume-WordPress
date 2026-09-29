<?php
/**
 * Tests des onglets de la fiche d'œuvre et des actualités par œuvre (PAGE-01) : filtre
 * yume_onglets_oeuvre, bloc yume/oeuvre-onglets, sous-page /oeuvres/{o}/actualites/ (liste,
 * pagination, 404, brouillons exclus), encart yume/oeuvre-news limité, référencement (titre,
 * canonique, og:url, rel=prev/next).
 *
 * Lancement : tools/localenv/test.sh actualites
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Library\actualites_introuvables;
use function Yume\Core\Library\actualites_oeuvre;
use function Yume\Core\Library\balises_open_graph;
use function Yume\Core\Library\balises_pages_onglet;
use function Yume\Core\Library\onglets_oeuvre;
use function Yume\Core\Library\renouveler_version;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_ta_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé : contenus Yume et articles vidés dans la transaction, requête
 * principale et paramètres GET restaurés, aucune notification.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_ta_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb, $wp_query, $wp_the_query, $post;
			$get      = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requete  = $wp_query;
			$reelle   = $wp_the_query;
			$post_sav = $post;
			$types    = "'yume_oeuvre', 'yume_tome', 'yume_chapitre', 'post'";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" );
			// phpcs:enable
			wp_cache_flush();
			renouveler_version();
			wp_set_current_user( 0 );
			$_GET = array();
			add_filter( 'yume_core_notifier', '__return_false' );
			try {
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
 * Crée une œuvre.
 *
 * @param string $titre  Titre.
 * @param string $statut Statut.
 */
function yume_ta_oeuvre( string $titre, string $statut = 'publish' ): int {
	$id = yume_factory_post(
		array(
			'post_type'    => 'yume_oeuvre',
			'post_title'   => $titre,
			'post_status'  => $statut,
			'post_content' => '<!-- wp:paragraph --><p>Résumé de ' . $titre . '.</p><!-- /wp:paragraph -->',
		)
	);
	wp_set_object_terms( $id, 'light-novel', 'yume_type' );
	return $id;
}

/**
 * Crée un article lié à une œuvre.
 *
 * @param int    $oeuvre_id Œuvre (0 : aucun lien).
 * @param string $titre     Titre.
 * @param array  $args      Arguments supplémentaires (post_status, post_date…).
 */
function yume_ta_article( int $oeuvre_id, string $titre, array $args = array() ): int {
	$id = yume_factory_post( array_merge( array( 'post_title' => $titre ), $args ) );
	if ( $oeuvre_id ) {
		$terme = (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true );
		if ( ! $terme ) {
			$objet = get_term_by( 'slug', get_post_field( 'post_name', $oeuvre_id ), 'yume_oeuvre_liee' );
			$terme = $objet instanceof WP_Term ? (int) $objet->term_id : 0;
		}
		wp_set_object_terms( $id, array( $terme ), 'yume_oeuvre_liee' );
	}
	return $id;
}

/**
 * Fait de la fiche (ou d'une sous-page) de l'œuvre la requête principale.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Sous-page ('' : la fiche).
 * @param int    $page      Paramètre pg (0 : absent).
 */
function yume_ta_aller( int $oeuvre_id, string $onglet = '', int $page = 0 ): void {
	global $wp_query, $wp_the_query, $post;
	$vars = array(
		'p'         => $oeuvre_id,
		'post_type' => 'yume_oeuvre',
	);
	if ( '' !== $onglet ) {
		$vars['yume_onglet'] = $onglet;
	}
	$_GET = $page ? array( 'pg' => (string) $page ) : array();
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées par yume_ta_test().
	$wp_query     = new WP_Query( $vars );
	$wp_the_query = $wp_query;
	$post         = get_post( $oeuvre_id );
	// phpcs:enable
}

/**
 * Rendu d'un bloc dans le contexte d'une œuvre.
 *
 * @param string $nom       Bloc (sans « yume/ »).
 * @param int    $oeuvre_id Œuvre.
 * @param array  $attrs     Attributs.
 */
function yume_ta_rendu( string $nom, int $oeuvre_id, array $attrs = array() ): string {
	$bloc = new WP_Block(
		array(
			'blockName'    => 'yume/' . $nom,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array(
			'postId'   => $oeuvre_id,
			'postType' => 'yume_oeuvre',
		)
	);
	return (string) $bloc->render();
}

/**
 * Onglet portant aria-current="page" dans un rendu de yume/oeuvre-onglets ('' si aucun).
 *
 * @param string $html Rendu.
 */
function yume_ta_onglet_actif( string $html ): string {
	return preg_match( '/<a class="yn-onglets-oeuvre__lien"[^>]*aria-current="page"[^>]*>([^<]+)</', $html, $m ) ? $m[1] : '';
}

/*
 * -----------------------------------------------------------------------------
 * Onglets
 * -----------------------------------------------------------------------------
 */

yume_ta_test(
	'Onglets : fiche seule → Présentation uniquement, aucun rendu ; article publié lié → Présentation + Actualités',
	static function () {
		$o = yume_ta_oeuvre( 'Brume Haute' );
		yume_ta_article( 0, 'Article sans œuvre' );
		yume_ta_article( $o, 'Brouillon lié', array( 'post_status' => 'draft' ) );
		yume_ta_aller( $o );

		$onglets = onglets_oeuvre( $o );
		yume_assert_same( array( 'fiche' ), array_keys( $onglets ) );
		yume_assert_same( 'Présentation', $onglets['fiche']['libelle'] );
		yume_assert_same( get_permalink( $o ), $onglets['fiche']['url'] );
		yume_assert_same( '', yume_ta_rendu( 'oeuvre-onglets', $o ), 'un seul onglet : rien' );

		yume_ta_article( $o, 'Annonce publiée' );
		$onglets = onglets_oeuvre( $o );
		yume_assert_same( array( 'fiche', 'actualites' ), array_keys( $onglets ) );
		yume_assert_same( 'Actualités', $onglets['actualites']['libelle'] );
		yume_assert_same( trailingslashit( (string) get_permalink( $o ) ) . 'actualites/', $onglets['actualites']['url'] );

		$html = yume_ta_rendu( 'oeuvre-onglets', $o );
		yume_assert_contains( '<nav class="yn-onglets-oeuvre wp-block-yume-oeuvre-onglets" aria-label="Sections de l’œuvre">', $html );
		yume_assert_same( 2, substr_count( $html, '<li class="yn-onglets-oeuvre__item">' ) );
		yume_assert_same( 1, substr_count( $html, 'aria-current="page"' ) );
		yume_assert_same( 'Présentation', yume_ta_onglet_actif( $html ), 'fiche affichée' );
		yume_assert_true( strpos( $html, 'Présentation' ) < strpos( $html, 'Actualités' ), 'Présentation en premier' );

		yume_ta_aller( $o, 'actualites' );
		yume_assert_same( 'Actualités', yume_ta_onglet_actif( yume_ta_rendu( 'oeuvre-onglets', $o ) ), 'sous-page affichée' );

		// Bloc rendu pour une autre œuvre que celle affichée : aucun onglet courant.
		$autre = yume_ta_oeuvre( 'Autre œuvre' );
		yume_ta_article( $autre, 'Article de l’autre œuvre' );
		yume_assert_same( '', yume_ta_onglet_actif( yume_ta_rendu( 'oeuvre-onglets', $autre ) ) );
	}
);

yume_ta_test(
	'Onglets : un onglet ajouté par le filtre yume_onglets_oeuvre apparaît ; « fiche » reste en premier, entrées invalides ignorées',
	static function () {
		$o      = yume_ta_oeuvre( 'Brume Haute' );
		$filtre = static function ( array $onglets, int $oeuvre_id ) use ( $o ): array {
			if ( $oeuvre_id !== $o ) {
				return $onglets;
			}
			unset( $onglets['fiche'] ); // Tentative de retrait : ignorée.
			$onglets['glossaire'] = array(
				'libelle' => 'Glossaire',
				'url'     => 'https://exemple.test/oeuvres/brume/glossaire/',
			);
			$onglets['vide']      = array( 'libelle' => '' );
			$onglets['bizarre']   = 'pas un tableau';
			return $onglets;
		};
		add_filter( 'yume_onglets_oeuvre', $filtre, 10, 2 );
		try {
			yume_ta_aller( $o );
			yume_assert_same( array( 'fiche', 'glossaire' ), array_keys( onglets_oeuvre( $o ) ) );
			$html = yume_ta_rendu( 'oeuvre-onglets', $o );
			yume_assert_contains( 'href="https://exemple.test/oeuvres/brume/glossaire/">Glossaire</a>', $html );
			yume_assert_same( 'Présentation', yume_ta_onglet_actif( $html ) );
		} finally {
			remove_filter( 'yume_onglets_oeuvre', $filtre, 10 );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Sous-page Actualités
 * -----------------------------------------------------------------------------
 */

yume_ta_test(
	'Actualités : sous-page déclarée, /oeuvres/{o}/actualites/ résolue vers la fiche avec l’onglet',
	static function () {
		yume_assert_true( in_array( 'actualites', \Yume\Core\Core\onglets_oeuvre(), true ), 'yume_sous_pages_oeuvre' );
		$o                      = yume_ta_oeuvre( 'Brume Haute' );
		$slug                   = get_post_field( 'post_name', $o );
		$sauve                  = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = '/oeuvres/' . $slug . '/actualites/';
		try {
			$wp                    = new WP();
			$wp->public_query_vars = $GLOBALS['wp']->public_query_vars;
			$wp->parse_request();
			$q = new WP_Query( $wp->query_vars );
		} finally {
			if ( null === $sauve ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $sauve;
			}
		}
		yume_assert_true( $q->is_singular( 'yume_oeuvre' ) );
		yume_assert_same( $o, (int) $q->get_queried_object_id() );
		yume_assert_same( 'actualites', $q->get( 'yume_onglet' ) );
	}
);

yume_ta_test(
	'Actualités : 404 sans article publié lié (brouillon, protégé, autre œuvre) et au-delà de la dernière page',
	static function () {
		$o     = yume_ta_oeuvre( 'Brume Haute' );
		$autre = yume_ta_oeuvre( 'Autre œuvre' );
		yume_ta_article( $o, 'Brouillon', array( 'post_status' => 'draft' ) );
		yume_ta_article(
			$o,
			'Programmé',
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);
		yume_ta_article( $o, 'Protégé', array( 'post_password' => 'secret' ) );
		yume_ta_article( $autre, 'Article de l’autre œuvre' );

		yume_ta_aller( $o, 'actualites' );
		yume_assert_true( actualites_introuvables(), 'aucun article publié lié' );
		yume_ta_aller( $o );
		yume_assert_false( actualites_introuvables(), 'la fiche n’est jamais concernée' );

		yume_ta_article( $o, 'Publié' );
		yume_ta_aller( $o, 'actualites' );
		yume_assert_false( actualites_introuvables() );
		yume_ta_aller( $o, 'actualites', 2 );
		yume_assert_true( actualites_introuvables(), 'page 2 inexistante' );
	}
);

yume_ta_test(
	'Actualités : liste paginée (10 par page, ?pg=N), plus récent d’abord, brouillons exclus, annonce de sortie comprise',
	static function () {
		$o   = yume_ta_oeuvre( 'Brume Haute' );
		$ids = array();
		for ( $n = 1; $n <= 12; $n++ ) {
			$ids[ $n ] = yume_ta_article( $o, 'Nouvelle ' . $n, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - ( 20 - $n ) * DAY_IN_SECONDS ) ) );
		}
		$brouillon = yume_ta_article( $o, 'Brouillon caché', array( 'post_status' => 'draft' ) );
		$sorties   = \Yume\Core\Publication\Annonce::categorie();
		wp_set_post_categories( $ids[12], array( $sorties ) );

		$premiere = actualites_oeuvre( $o );
		yume_assert_same( 12, $premiere['total'] );
		yume_assert_same( 10, count( $premiere['ids'] ) );
		yume_assert_same( $ids[12], $premiere['ids'][0], 'plus récent d’abord' );
		yume_assert_false( in_array( $brouillon, $premiere['ids'], true ) );

		yume_ta_aller( $o, 'actualites' );
		$html = yume_ta_rendu( 'oeuvre-news', $o );
		yume_assert_same( 10, substr_count( $html, 'class="yn-card yn-carte-article"' ) );
		yume_assert_contains( 'Nouvelle 12', $html );
		yume_assert_not_contains( 'Nouvelle 2<', $html, 'page 2 : ailleurs' );
		yume_assert_not_contains( 'Brouillon caché', $html );
		yume_assert_contains( '<p class="yn-label yn-oeuvre-news__categories">Sorties</p>', $html, 'annonce de sortie' );
		yume_assert_contains( 'page 1 sur 2', $html );
		yume_assert_contains( 'aria-label="Pages des actualités"', $html );
		yume_assert_contains( 'href="' . esc_url( trailingslashit( (string) get_permalink( $o ) ) . 'actualites/?pg=2' ) . '"', $html );

		yume_ta_aller( $o, 'actualites', 2 );
		$html = yume_ta_rendu( 'oeuvre-news', $o );
		yume_assert_same( 2, substr_count( $html, 'class="yn-card yn-carte-article"' ) );
		yume_assert_contains( 'Nouvelle 1<', $html );
		yume_assert_contains( 'Nouvelle 2<', $html );
		yume_assert_contains( 'page 2 sur 2', $html );
		// Lien vers la première page : sans ?pg=1.
		yume_assert_contains( 'href="' . esc_url( trailingslashit( (string) get_permalink( $o ) ) . 'actualites/' ) . '"', $html );
		yume_assert_not_contains( 'pg=1', $html );
	}
);

yume_ta_test(
	'Encart « Dernières actualités » (limite) : 3 articles et lien vers la sous-page ; rien sans article',
	static function () {
		$o = yume_ta_oeuvre( 'Brume Haute' );
		yume_ta_aller( $o );
		yume_assert_same( '', yume_ta_rendu( 'oeuvre-news', $o, array( 'limite' => 3 ) ) );
		yume_ta_article( $o, 'Brouillon', array( 'post_status' => 'draft' ) );
		yume_assert_same( '', yume_ta_rendu( 'oeuvre-news', $o, array( 'limite' => 3 ) ), 'brouillon seul : rien' );

		for ( $n = 1; $n <= 5; $n++ ) {
			yume_ta_article( $o, 'Nouvelle ' . $n, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - ( 10 - $n ) * DAY_IN_SECONDS ) ) );
		}
		$html = yume_ta_rendu( 'oeuvre-news', $o, array( 'limite' => 3 ) );
		yume_assert_contains( 'yn-oeuvre-news--encart', $html );
		yume_assert_contains( 'Dernières actualités', $html );
		yume_assert_same( 3, substr_count( $html, 'class="yn-card yn-carte-article"' ) );
		yume_assert_contains( 'Nouvelle 5', $html );
		yume_assert_not_contains( 'Nouvelle 2', $html );
		yume_assert_contains( 'href="' . esc_url( trailingslashit( (string) get_permalink( $o ) ) . 'actualites/' ) . '">Toutes les actualités', $html );
		yume_assert_not_contains( 'yn-pagination', $html );

		// Modèle du thème : onglets sous l'en-tête, encart après le sommaire des tomes.
		if ( 'yume' === get_stylesheet() ) {
			$fiche = get_block_template( 'yume//single-yume_oeuvre' );
			yume_assert_true( $fiche instanceof WP_Block_Template );
			yume_assert_contains( '<!-- wp:yume/oeuvre-onglets /-->', $fiche->content );
			yume_assert_true( strpos( $fiche->content, 'wp:yume/oeuvre-news {"limite":3}' ) > strpos( $fiche->content, 'wp:yume/tome-list' ) );
			$sous = get_block_template( 'yume//single-yume_oeuvre-actualites' );
			yume_assert_true( $sous instanceof WP_Block_Template, 'modèle single-yume_oeuvre-actualites' );
			yume_assert_contains( '<!-- wp:yume/oeuvre-header /-->', $sous->content );
			yume_assert_contains( '<!-- wp:yume/oeuvre-onglets /-->', $sous->content );
			yume_assert_contains( '<!-- wp:yume/oeuvre-news /-->', $sous->content );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Référencement
 * -----------------------------------------------------------------------------
 */

yume_ta_test(
	'SEO : titre « Actualités — Œuvre », canonique et og:url de la sous-page, rel=prev/next ; la fiche garde les siennes',
	static function () {
		$o = yume_ta_oeuvre( 'Brume Haute' );
		for ( $n = 1; $n <= 11; $n++ ) {
			yume_ta_article( $o, 'Nouvelle ' . $n );
		}
		$fiche = (string) get_permalink( $o );
		$sous  = trailingslashit( $fiche ) . 'actualites/';

		yume_ta_aller( $o );
		yume_assert_same( $fiche, wp_get_canonical_url( $o ) );
		yume_assert_same( $fiche, balises_open_graph()['og:url'] );
		yume_assert_same( 'Brume Haute', balises_open_graph()['og:title'] );
		yume_assert_contains( 'Brume Haute', wp_get_document_title() );
		yume_assert_not_contains( 'Actualités', wp_get_document_title() );
		yume_assert_same( '', balises_pages_onglet() );

		yume_ta_aller( $o, 'actualites' );
		yume_assert_contains( 'Actualités — Brume Haute', html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' ) );
		yume_assert_same( $sous, wp_get_canonical_url( $o ) );
		$og = balises_open_graph();
		yume_assert_same( $sous, $og['og:url'] );
		yume_assert_same( 'Actualités — Brume Haute', $og['og:title'] );
		yume_assert_same( 'website', $og['og:type'] );
		yume_assert_same( '<link rel="next" href="' . esc_url( $sous . '?pg=2' ) . "\" />\n", balises_pages_onglet() );

		yume_ta_aller( $o, 'actualites', 2 );
		yume_assert_same( $sous . '?pg=2', wp_get_canonical_url( $o ) );
		yume_assert_same( $sous . '?pg=2', balises_open_graph()['og:url'] );
		yume_assert_contains( 'Page 2', wp_get_document_title() );
		yume_assert_same( '<link rel="prev" href="' . esc_url( $sous ) . "\" />\n", balises_pages_onglet() );
		yume_assert_true( false !== has_action( 'wp_head', 'Yume\Core\Library\afficher_pages_onglet' ) );
	}
);
