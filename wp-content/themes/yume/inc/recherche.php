<?php
/**
 * Formulaires de recherche : chaque repère « search » porte un nom distinct.
 *
 * Le bloc core/search ne nomme pas son formulaire (seul le champ reçoit le libellé) : sur
 * les pages de recherche et 404, le formulaire de l'en-tête et celui de la page sont alors
 * deux repères identiques pour un lecteur d'écran (axe landmark-unique). Le libellé du
 * bloc (« Rechercher sur le site », « Nouvelle recherche ») devient le nom du repère.
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
			return $balises->get_updated_html();
		}
	}
	return $contenu;
}
add_filter( 'render_block_core/search', 'yume_theme_nom_recherche', 10, 2 );
