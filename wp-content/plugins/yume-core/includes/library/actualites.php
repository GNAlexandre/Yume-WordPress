<?php
/**
 * Actualités d'une œuvre (PAGE-01) : articles publiés liés à l'œuvre par la taxonomie
 * yume_oeuvre_liee, dont les annonces de sortie des tomes (includes/publication/class-annonce.php,
 * catégorie « Sorties »).
 *
 * - sous-page /oeuvres/{oeuvre}/actualites/ (filtre yume_sous_pages_oeuvre), 404 si l'œuvre n'a
 *   aucun article publié lié ou si la page demandée (?pg=N) n'existe pas ;
 * - onglet « Actualités » (filtre yume_onglets_oeuvre, onglets.php) seulement dans ce cas ;
 * - bloc yume/oeuvre-news : liste paginée (10 par page) ; attribut « limite » pour l'encart
 *   « Dernières actualités » de la fiche (N articles + lien « Toutes les actualités », rien
 *   sans article).
 *
 * Les requêtes passent par WP_Query, dont les résultats sont mis en cache par WordPress et
 * invalidés à chaque modification d'article ou de terme.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/** Slug de la sous-page. */
const ONGLET_ACTUALITES = 'actualites';

/** Articles par page de la sous-page. */
const ACTUALITES_PAR_PAGE = 10;

/**
 * Déclare la sous-page (au chargement du module : les règles de réécriture en dépendent).
 *
 * @param string[] $slugs Sous-pages.
 * @return string[]
 */
function declarer_sous_page_actualites( $slugs ) {
	$slugs   = is_array( $slugs ) ? $slugs : array();
	$slugs[] = ONGLET_ACTUALITES;
	return $slugs;
}
add_filter( 'yume_sous_pages_oeuvre', __NAMESPACE__ . '\\declarer_sous_page_actualites' );

/**
 * Terme yume_oeuvre_liee d'une œuvre (méta _yume_terme_lie, sinon terme du même slug), ou 0.
 *
 * @param int $oeuvre_id Œuvre.
 */
function terme_actualites( int $oeuvre_id ): int {
	if ( $oeuvre_id <= 0 || ! taxonomy_exists( 'yume_oeuvre_liee' ) ) {
		return 0;
	}
	$terme_id = (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true );
	if ( $terme_id && term_exists( $terme_id, 'yume_oeuvre_liee' ) ) {
		return $terme_id;
	}
	$terme = get_term_by( 'slug', (string) get_post_field( 'post_name', $oeuvre_id ), 'yume_oeuvre_liee' );
	return $terme instanceof \WP_Term ? (int) $terme->term_id : 0;
}

/**
 * Articles publiés (sans mot de passe) liés à une œuvre, du plus récent au plus ancien.
 *
 * @param int $oeuvre_id Œuvre.
 * @param int $nombre    Articles par page.
 * @param int $page      Page (1 = la première).
 * @return array{ids:int[],total:int}
 */
function actualites_oeuvre( int $oeuvre_id, int $nombre = ACTUALITES_PAR_PAGE, int $page = 1 ): array {
	$terme_id = terme_actualites( $oeuvre_id );
	if ( ! $terme_id ) {
		return array(
			'ids'   => array(),
			'total' => 0,
		);
	}
	$requete = new \WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => max( 1, $nombre ),
			'paged'               => max( 1, $page ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'fields'              => 'ids',
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy'         => 'yume_oeuvre_liee',
					'field'            => 'term_id',
					'terms'            => array( $terme_id ),
					'include_children' => false,
				),
			),
		)
	);
	return array(
		'ids'   => array_map( 'intval', $requete->posts ),
		'total' => (int) $requete->found_posts,
	);
}

/**
 * L'œuvre a-t-elle au moins un article publié lié ?
 *
 * @param int $oeuvre_id Œuvre.
 */
function a_des_actualites( int $oeuvre_id ): bool {
	return actualites_oeuvre( $oeuvre_id, 1 )['total'] > 0;
}

/**
 * Nombre de pages de la sous-page Actualités d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function pages_actualites( int $oeuvre_id ): int {
	return max( 1, (int) ceil( actualites_oeuvre( $oeuvre_id, 1 )['total'] / ACTUALITES_PAR_PAGE ) );
}

/**
 * Onglet « Actualités » (quand l'œuvre a au moins un article publié lié).
 *
 * @param array<string,array{libelle:string,url:string}> $onglets   Onglets.
 * @param int                                            $oeuvre_id Œuvre.
 * @return array<string,array{libelle:string,url:string}>
 */
function onglet_actualites( $onglets, $oeuvre_id = 0 ) {
	$onglets = is_array( $onglets ) ? $onglets : array();
	$url     = url_onglet( (int) $oeuvre_id, ONGLET_ACTUALITES );
	if ( '' !== $url && a_des_actualites( (int) $oeuvre_id ) ) {
		$onglets[ ONGLET_ACTUALITES ] = array(
			'libelle' => __( 'Actualités', 'yume-core' ),
			'url'     => $url,
		);
	}
	return $onglets;
}
add_filter( 'yume_onglets_oeuvre', __NAMESPACE__ . '\\onglet_actualites', 10, 2 );

/**
 * Nombre de pages de la sous-page (référencement commun des onglets, onglets.php).
 *
 * @param int    $pages     Pages.
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Sous-page.
 */
function pages_onglet_actualites( $pages, $oeuvre_id = 0, $onglet = '' ) {
	return ONGLET_ACTUALITES === $onglet ? pages_actualites( (int) $oeuvre_id ) : $pages;
}
add_filter( 'yume_pages_onglet_oeuvre', __NAMESPACE__ . '\\pages_onglet_actualites', 10, 3 );

/**
 * La requête principale demande-t-elle la sous-page Actualités d'une œuvre sans article publié
 * lié, ou une page (?pg=N) au-delà de la dernière ?
 */
function actualites_introuvables(): bool {
	if ( ONGLET_ACTUALITES !== onglet_courant() ) {
		return false;
	}
	$oeuvre_id = (int) get_queried_object_id();
	return ! a_des_actualites( $oeuvre_id ) || page_onglet() > pages_actualites( $oeuvre_id );
}

/**
 * 404 pour une sous-page Actualités introuvable. Priorité 8 : avant les redirections
 * canoniques (9 et 10).
 */
function verifier_sous_page_actualites(): void {
	if ( ! actualites_introuvables() ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	// Sinon redirect_canonical() « devine » la fiche de l'œuvre et redirige la 404 vers elle.
	add_filter( 'redirect_canonical', '__return_false' );
}
add_action( 'template_redirect', __NAMESPACE__ . '\\verifier_sous_page_actualites', 8 );

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/oeuvre-news
 * -----------------------------------------------------------------------------
 */

/**
 * Carte d'un article (mêmes classes que la page Actualités du thème) : image mise en avant
 * (dégradé de substitution à défaut), catégories, titre, extrait, date.
 *
 * @param int $article_id Article.
 * @param int $niveau     Niveau du titre (2 ou 3).
 */
function carte_actualite( int $article_id, int $niveau = 3 ): string {
	$lien  = (string) get_permalink( $article_id );
	$titre = titre( $article_id );
	$image = has_post_thumbnail( $article_id ) ? get_the_post_thumbnail(
		$article_id,
		'yume-vignette',
		array(
			'alt'     => '',
			'loading' => 'lazy',
		)
	) : '';
	$html  = '<li class="yn-oeuvre-news__item"><article class="yn-card yn-carte-article">';
	if ( '' !== $image ) {
		$html .= '<figure class="wp-block-post-featured-image yn-carte-article__image yn-vignette">' . $image . '</figure>';
	} else {
		$html .= '<figure class="wp-block-post-featured-image yn-carte-article__image yn-vignette yn-substitution" aria-hidden="true"><span class="yn-substitution__texte">'
			. esc_html( wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) . '</span></figure>';
	}
	$html      .= '<div class="yn-carte-article__corps">';
	$categories = get_the_category( $article_id );
	$noms       = array();
	foreach ( $categories as $categorie ) {
		if ( 'uncategorized' !== $categorie->slug && 'non-classe' !== $categorie->slug ) {
			$noms[] = esc_html( $categorie->name );
		}
	}
	if ( $noms ) {
		$html .= '<p class="yn-label yn-oeuvre-news__categories">' . implode( ' · ', $noms ) . '</p>';
	}
	$niveau  = 2 === $niveau ? 2 : 3;
	$html   .= '<h' . $niveau . ' class="yn-carte-article__titre"><a href="' . esc_url( $lien ) . '">' . esc_html( $titre ) . '</a></h' . $niveau . '>';
	$extrait = trim( html_entity_decode( wp_strip_all_tags( (string) get_the_excerpt( $article_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' !== $extrait ) {
		$html .= '<p class="yn-carte-article__extrait">' . esc_html( wp_trim_words( $extrait, 24, '…' ) ) . '</p>';
	}
	$html .= '<p class="yn-carte-article__date">' . balise_date( (int) get_post_time( 'U', true, $article_id ), true ) . '</p>';
	return $html . '</div></article></li>';
}

/**
 * Rendu de yume/oeuvre-news : actualités de l'œuvre du contexte.
 *
 * Sans limite : liste paginée (?pg=N, 10 par page) de la sous-page Actualités. Avec une limite
 * (encart de la fiche) : les N plus récentes et un lien « Toutes les actualités » ; rien si
 * l'œuvre n'a aucun article.
 *
 * @param array          $attributs Attributs (limite).
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_oeuvre_news( array $attributs = array(), $bloc = null ): string {
	$oeuvre_id = oeuvre_contexte( $bloc );
	if ( ! $oeuvre_id || TYPE_OEUVRE !== get_post_type( $oeuvre_id ) ) {
		return rendu_sans_contexte( 'yn-oeuvre-news', __( 'Actualités de l’œuvre : visibles sur la fiche d’une œuvre.', 'yume-core' ) );
	}
	$limite = isset( $attributs['limite'] ) && is_numeric( $attributs['limite'] ) ? max( 0, min( 12, (int) $attributs['limite'] ) ) : 0;
	$id     = wp_unique_id( 'yn-actus-oeuvre-' );

	if ( $limite > 0 ) {
		$resultat = actualites_oeuvre( $oeuvre_id, $limite );
		if ( ! $resultat['ids'] ) {
			return '';
		}
		_prime_post_caches( $resultat['ids'], true, true );
		$items = '';
		foreach ( $resultat['ids'] as $article_id ) {
			$items .= carte_actualite( $article_id );
		}
		$tete = '<div class="yn-oeuvre-news__tete"><h2 class="yn-oeuvre-news__titre" id="' . esc_attr( $id ) . '">' . esc_html__( 'Dernières actualités', 'yume-core' ) . '</h2>';
		$tous = url_onglet( $oeuvre_id, ONGLET_ACTUALITES );
		if ( '' !== $tous ) {
			$tete .= '<a class="yn-oeuvre-news__tout" href="' . esc_url( $tous ) . '">' . esc_html__( 'Toutes les actualités', 'yume-core' ) . ' <span aria-hidden="true">→</span></a>';
		}
		$tete .= '</div>';
		return '<section ' . attributs_racine( 'yn-oeuvre-news yn-oeuvre-news--encart', array( 'aria-labelledby' => $id ) ) . '>'
			. $tete . '<ul class="yn-oeuvre-news__grille">' . $items . '</ul></section>';
	}

	$total_pages = pages_actualites( $oeuvre_id );
	$page        = min( page_onglet(), $total_pages );
	$resultat    = actualites_oeuvre( $oeuvre_id, ACTUALITES_PAR_PAGE, $page );
	$html        = '<section ' . attributs_racine( 'yn-oeuvre-news', array( 'aria-labelledby' => $id ) ) . '>';
	/* translators: %s : titre de l'œuvre. */
	$titre = sprintf( __( 'Actualités de %s', 'yume-core' ), titre( $oeuvre_id ) );
	if ( $total_pages > 1 ) {
		/* translators: 1 : page courante, 2 : nombre de pages. */
		$titre .= ' · ' . sprintf( __( 'page %1$d sur %2$d', 'yume-core' ), $page, $total_pages );
	}
	// L'onglet « Actualités » sert d'intitulé visible : le titre reste pour les lecteurs d'écran.
	$html .= '<h2 class="yn-visually-hidden" id="' . esc_attr( $id ) . '">' . esc_html( $titre ) . '</h2>';
	if ( ! $resultat['ids'] ) {
		return $html . '<p class="yn-muted yn-oeuvre-news__vide">' . esc_html__( 'Aucune actualité pour cette œuvre pour le moment.', 'yume-core' ) . '</p></section>';
	}
	_prime_post_caches( $resultat['ids'], true, true );
	$items = '';
	foreach ( $resultat['ids'] as $article_id ) {
		$items .= carte_actualite( $article_id );
	}
	$html .= '<ul class="yn-oeuvre-news__grille">' . $items . '</ul>';

	if ( $total_pages > 1 ) {
		$base  = url_onglet( $oeuvre_id, ONGLET_ACTUALITES );
		$liens = paginate_links(
			array(
				'base'      => $base . '%_%',
				'format'    => ( str_contains( $base, '?' ) ? '&' : '?' ) . PARAM_PAGE_ONGLET . '=%#%',
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => __( '‹ Précédente', 'yume-core' ),
				'next_text' => __( 'Suivante ›', 'yume-core' ),
				'mid_size'  => 1,
				'type'      => 'plain',
			)
		);
		if ( is_string( $liens ) && '' !== $liens ) {
			$html .= '<nav class="yn-pagination yn-oeuvre-news__pagination" aria-label="' . esc_attr__( 'Pages des actualités', 'yume-core' ) . '">' . $liens . '</nav>';
		}
	}
	return $html . '</section>';
}
