<?php
/**
 * Lecteur des chapitres MangaDex (contrat §8 bis) : les pages relayées par le proxy
 * remplacent le contenu (vide) du chapitre, dans le modèle « Chapitre » du thème.
 *
 * - Pages rendues côté serveur, sous .wp-block-post-content : en défilement (mode par défaut),
 *   la lecture fonctionne sans JavaScript. Les deux premières pages sont chargées tout de
 *   suite, les suivantes au fil du défilement (loading="lazy").
 * - HTML identique pour tous les visiteurs (cache de pages) : qualité « Économie de données »
 *   dans src, « Originale » dans data-yn-originale ; le choix du lecteur (mode, qualité) est
 *   appliqué par manga.js et gardé dans localStorage yn.manga (contrat §14).
 * - Chaque page (.yn-manga__page) compte comme un paragraphe pour le suivi de lecture du bloc
 *   yume/reader-tools (marque-page, reprise, progression).
 * - Crédits : « Images hébergées par MangaDex », lien vers le chapitre sur MangaDex (repli si une
 *   page ne se charge pas).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/** Poignée de la feuille de style et du script du lecteur. */
const POIGNEE_LECTEUR = 'yume-manga';

/** Poids moyen d'une page en qualité originale (Mo, mesuré sur les chapitres du groupe). */
const MO_PAR_PAGE_ORIGINALE = 1.6;

/** Pages chargées dès l'affichage (les suivantes : au fil du défilement). */
const PAGES_IMMEDIATES = 2;

/**
 * Chapitre MangaDex affiché par la requête principale (lecteur livré), 0 sinon.
 */
function chapitre_affiche(): int {
	if ( ! lecteur_pret() || ! is_singular( 'yume_chapitre' ) ) {
		return 0;
	}
	$id = (int) get_queried_object_id();
	return null !== yume_mangadex_chapitre( $id ) ? $id : 0;
}

/**
 * Enregistre la feuille de style et le script du lecteur.
 */
function enregistrer_assets_lecteur(): void {
	wp_register_style( POIGNEE_LECTEUR, YUME_CORE_URL . 'includes/mangadex/assets/manga.css', array(), YUME_CORE_VERSION );
	wp_register_script(
		POIGNEE_LECTEUR,
		YUME_CORE_URL . 'includes/mangadex/assets/manga.js',
		array(),
		YUME_CORE_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_assets_lecteur', 5 );

/**
 * Charge le lecteur sur la page d'un chapitre MangaDex.
 */
function charger_assets_lecteur(): void {
	if ( chapitre_affiche() ) {
		wp_enqueue_style( POIGNEE_LECTEUR );
		wp_enqueue_script( POIGNEE_LECTEUR );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\charger_assets_lecteur' );

/**
 * Ajoute les pages au contenu du chapitre affiché (après wpautop et les codes courts).
 *
 * @param string $contenu Contenu filtré.
 */
function contenu_chapitre( $contenu ) {
	$chapitre_id = chapitre_affiche();
	if ( ! $chapitre_id || (int) get_the_ID() !== $chapitre_id ) {
		return $contenu;
	}
	return html_lecteur( $chapitre_id ) . $contenu;
}
add_filter( 'the_content', __NAMESPACE__ . '\\contenu_chapitre', 20 );

/**
 * Poids approximatif d'un chapitre en qualité originale (Mo, arrondi au Mo).
 *
 * @param int $pages Nombre de pages.
 */
function poids_originale( int $pages ): int {
	return max( 1, (int) round( $pages * MO_PAR_PAGE_ORIGINALE ) );
}

/**
 * HTML du lecteur d'un chapitre MangaDex ('' pour un autre contenu).
 *
 * @param int $chapitre_id Chapitre.
 */
function html_lecteur( int $chapitre_id ): string {
	$mdx = yume_mangadex_chapitre( $chapitre_id );
	if ( null === $mdx ) {
		return '';
	}
	$total  = $mdx['pages'];
	$source = '<p class="yn-label yn-manga__source">'
		. sprintf(
			/* translators: %s : lien « MangaDex ». */
			esc_html__( 'Images hébergées par %s', 'yume-core' ),
			'<a href="https://mangadex.org/" rel="noopener">MangaDex</a>'
		)
		. '<span aria-hidden="true"> · </span><a href="' . esc_url( $mdx['url'] ) . '" rel="noopener" data-yn-manga-mangadex>' . esc_html__( 'Lire ce chapitre sur MangaDex', 'yume-core' ) . '</a></p>';

	if ( $total < 1 ) {
		return '<div class="yn-manga yn-manga--vide"><p>' . esc_html__( 'Les pages de ce chapitre ne sont pas encore disponibles sur le site.', 'yume-core' ) . '</p>' . $source . '</div>';
	}

	$html  = '<div class="yn-manga" data-yn-manga data-yn-mode="defilement" data-yn-pages="' . esc_attr( (string) $total ) . '">';
	$html .= '<div class="yn-manga__outils" hidden>';
	/* translators: %d : nombre de pages. */
	$html .= '<span class="yn-label yn-manga__info">' . esc_html( sprintf( _n( '%d page', '%d pages', $total, 'yume-core' ), $total ) ) . '</span>';
	$html .= '<div class="yn-manga__choix" role="group" aria-label="' . esc_attr__( 'Mode de lecture', 'yume-core' ) . '">'
		. '<button type="button" class="yn-btn yn-btn--sm" data-yn-manga-mode="defilement" aria-pressed="true">' . esc_html__( 'Défilement', 'yume-core' ) . '</button>'
		. '<button type="button" class="yn-btn yn-btn--sm" data-yn-manga-mode="page" aria-pressed="false">' . esc_html__( 'Page par page', 'yume-core' ) . '</button>'
		. '</div>';
	$html .= '<div class="yn-manga__choix" role="group" aria-label="' . esc_attr__( 'Qualité des images', 'yume-core' ) . '">'
		. '<button type="button" class="yn-btn yn-btn--sm" data-yn-manga-qualite="data-saver" aria-pressed="true">' . esc_html__( 'Économie de données', 'yume-core' ) . '</button>'
		/* translators: %d : poids approximatif du chapitre en Mo. */
		. '<button type="button" class="yn-btn yn-btn--sm" data-yn-manga-qualite="data" aria-pressed="false">' . esc_html( sprintf( __( 'Originale (≈ %d Mo)', 'yume-core' ), poids_originale( $total ) ) ) . '</button>'
		. '</div>';
	$html .= '</div>';

	$html .= '<div class="yn-manga__pages">';
	for ( $n = 1; $n <= $total; $n++ ) {
		$attributs = array(
			'src'               => yume_mangadex_url_page( $chapitre_id, $n, 'data-saver' ),
			'data-yn-originale' => yume_mangadex_url_page( $chapitre_id, $n, 'data' ),
			/* translators: 1 : numéro de page, 2 : nombre de pages. */
			'alt'               => sprintf( __( 'Page %1$d sur %2$d', 'yume-core' ), $n, $total ),
			'decoding'          => 'async',
			'loading'           => $n <= PAGES_IMMEDIATES ? 'eager' : 'lazy',
			'fetchpriority'     => 1 === $n ? 'high' : '',
			'referrerpolicy'    => 'same-origin',
		);
		$img       = '<img';
		foreach ( $attributs as $nom => $valeur ) {
			if ( '' !== $valeur ) {
				$img .= ' ' . $nom . '="' . ( 'src' === $nom || 'data-yn-originale' === $nom ? esc_url( $valeur ) : esc_attr( $valeur ) ) . '"';
			}
		}
		$html .= '<figure class="yn-manga__page" data-yn-page="' . esc_attr( (string) $n ) . '">' . $img . '></figure>';
	}
	$html .= '</div>';

	$html .= '<div class="yn-manga__navigation" hidden>'
		. '<button type="button" class="yn-btn" data-yn-manga-aller="precedente">' . esc_html__( '← Page précédente', 'yume-core' ) . '</button>'
		/* translators: 1 : numéro de page, 2 : nombre de pages. */
		. '<span class="yn-label yn-manga__compteur" aria-live="polite">' . esc_html( sprintf( __( 'Page %1$d sur %2$d', 'yume-core' ), 1, $total ) ) . '</span>'
		. '<button type="button" class="yn-btn yn-btn--primary" data-yn-manga-aller="suivante">' . esc_html__( 'Page suivante →', 'yume-core' ) . '</button>'
		. '</div>';
	return $html . $source . '</div>';
}
