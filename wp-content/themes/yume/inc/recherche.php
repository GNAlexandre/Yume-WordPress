<?php
/**
 * Formulaires de recherche : chaque repère « search » porte un nom distinct.
 *
 * Le bloc core/search ne nomme pas son formulaire (seul le champ reçoit le libellé) : sur
 * les pages de recherche et 404, le formulaire de l'en-tête et celui de la page sont alors
 * deux repères identiques pour un lecteur d'écran (axe landmark-unique). Le libellé du
 * bloc (« Rechercher sur le site », « Nouvelle recherche ») devient le nom du repère.
 *
 * Suggestions instantanées (AMEL-04) : le formulaire de l'en-tête (classe yn-nav__recherche)
 * devient une liste déroulante ARIA 1.2 (champ role="combobox", aria-expanded, aria-controls,
 * aria-activedescendant géré par le script, liste role="listbox", annonce aria-live du nombre
 * de suggestions) alimentée par GET /yume/v1/suggestions de l'extension Yume. Sans JavaScript
 * ou sans l'extension, le formulaire reste la recherche classique (/?s=).
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pose aria-label (libellé du bloc) sur le repère du formulaire de recherche : <search> ou
 * <form role="search"> selon la version de WordPress et le réglage du bloc.
 *
 * @param string $contenu Rendu du bloc.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_nom_recherche( $contenu, $bloc ) {
	$libelle = trim( wp_strip_all_tags( (string) ( $bloc['attrs']['label'] ?? '' ) ) );
	if ( ! is_string( $contenu ) || '' === $contenu || '' === $libelle ) {
		return $contenu;
	}
	$balises = new WP_HTML_Tag_Processor( $contenu );
	while ( $balises->next_tag() ) {
		$balise = $balises->get_tag();
		if ( 'SEARCH' === $balise || ( 'FORM' === $balise && 'search' === $balises->get_attribute( 'role' ) ) ) {
			if ( null === $balises->get_attribute( 'aria-label' ) ) {
				$balises->set_attribute( 'aria-label', $libelle );
			}
			$contenu = $balises->get_updated_html();
			break;
		}
	}
	$classes = ' ' . (string) ( $bloc['attrs']['className'] ?? '' ) . ' ';
	if ( str_contains( $classes, ' yn-nav__recherche ' ) ) {
		$contenu = yume_theme_suggestions_recherche( $contenu );
	}
	return $contenu;
}

/**
 * Les suggestions instantanées sont-elles disponibles (route de l'extension Yume) ?
 */
function yume_theme_suggestions_disponibles(): bool {
	/**
	 * Active les suggestions instantanées du champ de recherche de l'en-tête.
	 *
	 * @param bool $actif Extension présente.
	 */
	return (bool) apply_filters( 'yume_theme_suggestions_recherche', function_exists( '\\Yume\\Core\\Library\\suggestions' ) );
}

/**
 * Transforme le champ d'un formulaire de recherche en liste déroulante de suggestions
 * (combobox ARIA 1.2) et charge le script et la feuille des suggestions.
 *
 * @param string $contenu Rendu du bloc core/search.
 * @return string
 */
function yume_theme_suggestions_recherche( string $contenu ): string {
	if ( ! yume_theme_suggestions_disponibles() || ! str_contains( $contenu, '</form>' ) ) {
		return $contenu;
	}
	$id      = wp_unique_id( 'yn-suggest-' );
	$balises = new WP_HTML_Tag_Processor( $contenu );
	$trouve  = false;
	while ( $balises->next_tag( 'INPUT' ) ) {
		if ( 'search' === $balises->get_attribute( 'type' ) || $balises->has_class( 'wp-block-search__input' ) ) {
			$attributs = array(
				'role'                => 'combobox',
				'aria-autocomplete'   => 'list',
				'aria-expanded'       => 'false',
				'aria-controls'       => $id . '-liste',
				'aria-describedby'    => $id . '-aide',
				'autocomplete'        => 'off',
				'data-yn-suggestions' => rest_url( 'yume/v1/suggestions' ),
				'data-yn-annonce'     => $id . '-annonce',
				'data-yn-msg-aucune'  => __( 'Aucune suggestion.', 'yume' ),
				'data-yn-msg-une'     => __( '1 suggestion. Flèches haut et bas pour la choisir, Entrée pour l’ouvrir.', 'yume' ),
				/* translators: %d : nombre de suggestions. */
				'data-yn-msg-plus'    => __( '%d suggestions. Flèches haut et bas pour les parcourir, Entrée pour ouvrir.', 'yume' ),
				/* translators: %s : terme cherché. */
				'data-yn-msg-tout'    => __( 'Voir tous les résultats pour « %s »', 'yume' ),
			);
			foreach ( $attributs as $nom => $valeur ) {
				$balises->set_attribute( $nom, $valeur );
			}
			$trouve = true;
			break;
		}
	}
	if ( ! $trouve ) {
		return $contenu;
	}
	$contenu = $balises->get_updated_html();
	$panneau = '<div class="yn-suggest" id="' . esc_attr( $id ) . '" hidden><ul class="yn-suggest__liste" id="' . esc_attr( $id . '-liste' ) . '" role="listbox" aria-label="' . esc_attr__( 'Suggestions de recherche', 'yume' ) . '"></ul></div>'
		. '<span class="yn-visually-hidden" id="' . esc_attr( $id . '-aide' ) . '">' . esc_html__( 'Des suggestions apparaissent à partir de 2 caractères.', 'yume' ) . '</span>'
		. '<span class="yn-visually-hidden" id="' . esc_attr( $id . '-annonce' ) . '" aria-live="polite" aria-atomic="true"></span>';
	$fin     = strrpos( $contenu, '</form>' );
	$contenu = substr( $contenu, 0, $fin ) . $panneau . substr( $contenu, $fin );

	wp_enqueue_style( 'yume-suggestions', get_theme_file_uri( 'assets/css/suggestions.css' ), array( 'yume' ), yume_theme_version_fichier( 'assets/css/suggestions.css' ) );
	wp_enqueue_script(
		'yume-suggestions',
		get_theme_file_uri( 'assets/js/suggestions.js' ),
		array(),
		yume_theme_version_fichier( 'assets/js/suggestions.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	return $contenu;
}
add_filter( 'render_block_core/search', 'yume_theme_nom_recherche', 10, 2 );
