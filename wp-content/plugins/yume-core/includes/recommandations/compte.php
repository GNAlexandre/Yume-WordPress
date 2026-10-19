<?php
/**
 * Rubrique « Pour vous » de Mon compte (ancre yn-pour-vous, maquette Compte.dc.html) : par les
 * points d'extension yume_compte_rubriques et yume_compte_sections_html (docs/06 §7), après
 * « À rattraper » (yn-rattrapage, lot L5) si elle est présente, sinon après « Lecture en cours »
 * (yn-lecture), sinon à la fin. Rendu serveur pour le membre connecté (page jamais mise en cache).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Recommandations;

defined( 'ABSPATH' ) || exit;

/** Ancre de la rubrique. */
const ANCRE_COMPTE = 'yn-pour-vous';

/** Nombre de suggestions de la rubrique. */
const LIMITE_COMPTE = 6;

/**
 * Insère une entrée après la première ancre trouvée parmi $apres (sinon à la fin), en
 * conservant l'ordre des autres entrées.
 *
 * @param array    $tableau Tableau ancre => valeur.
 * @param string   $cle     Ancre insérée.
 * @param mixed    $valeur  Valeur.
 * @param string[] $apres   Ancres de référence, par ordre de préférence.
 * @return array
 */
function inserer_apres( array $tableau, string $cle, $valeur, array $apres ): array {
	unset( $tableau[ $cle ] );
	foreach ( $apres as $ancre ) {
		if ( ! array_key_exists( $ancre, $tableau ) ) {
			continue;
		}
		$resultat = array();
		foreach ( $tableau as $k => $v ) {
			$resultat[ $k ] = $v;
			if ( $k === $ancre ) {
				$resultat[ $cle ] = $valeur;
			}
		}
		return $resultat;
	}
	$tableau[ $cle ] = $valeur;
	return $tableau;
}

/**
 * Ancres après lesquelles la rubrique se place.
 *
 * @return string[]
 */
function ancres_precedentes(): array {
	return array( 'yn-rattrapage', 'yn-lecture' );
}

/**
 * Rubrique « Pour vous » dans la navigation du compte.
 *
 * @param array $rubriques Rubriques (ancre => libellé).
 * @param int   $user_id   Membre connecté.
 * @return array
 */
function rubrique_compte( $rubriques, $user_id = 0 ) {
	unset( $user_id );
	if ( ! is_array( $rubriques ) || ! actives() ) {
		return $rubriques;
	}
	return inserer_apres( $rubriques, ANCRE_COMPTE, __( 'Pour vous', 'yume-core' ), ancres_precedentes() );
}
add_filter( 'yume_compte_rubriques', __NAMESPACE__ . '\\rubrique_compte', 10, 2 );

/**
 * Section « Pour vous » de la page compte.
 *
 * @param array    $sections Sections (ancre => HTML).
 * @param \WP_User $user     Membre connecté.
 * @return array
 */
function section_compte( $sections, $user = null ) {
	if ( ! is_array( $sections ) || ! $user instanceof \WP_User || ! actives() ) {
		return $sections;
	}
	return inserer_apres( $sections, ANCRE_COMPTE, html_section( (int) $user->ID ), ancres_precedentes() );
}
add_filter( 'yume_compte_sections_html', __NAMESPACE__ . '\\section_compte', 10, 2 );

/**
 * HTML de la section : cartes avec « parce que vous suivez {X} », ou invitation à ajouter des
 * favoris (sans favori ni lecture), ou message d'attente (aucune suggestion pour l'instant).
 *
 * @param int $user_id Membre.
 */
function html_section( int $user_id ): string {
	$titre = __( 'Pour vous', 'yume-core' );
	$aide  = __( 'Suggestions d’après vos favoris et vos lectures', 'yume-core' );
	$html  = function_exists( '\Yume\Core\Social\debut_section' )
		? \Yume\Core\Social\debut_section( ANCRE_COMPTE, $titre, $aide )
		: '<section class="yn-account__panneau" id="' . esc_attr( ANCRE_COMPTE ) . '" aria-labelledby="' . esc_attr( ANCRE_COMPTE . '-titre' ) . '" data-yn-panneau="' . esc_attr( ANCRE_COMPTE ) . '" tabindex="-1"><div class="yn-account__entete"><h2 class="yn-account__titre" id="' . esc_attr( ANCRE_COMPTE . '-titre' ) . '">' . esc_html( $titre ) . '</h2></div>';

	$bibliotheque = function_exists( 'yume_url_page' ) ? yume_url_page( 'bibliotheque' ) : home_url( '/' );
	$lien         = ' <a href="' . esc_url( $bibliotheque ) . '">' . esc_html__( 'Parcourir la bibliothèque', 'yume-core' ) . '</a>';
	if ( ! sources_membre( $user_id ) ) {
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Ajoutez des favoris pour recevoir des suggestions.', 'yume-core' ) . $lien . '</p></section>';
	}
	$cartes = rendu_cartes(
		recommandations_membre( $user_id, LIMITE_COMPTE ),
		array(
			'niveau'  => 'h3',
			'raisons' => true,
			'classe'  => 'yn-recommandations__grille--compte',
		)
	);
	if ( '' === $cartes ) {
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Pas encore de suggestion pour vos séries : revenez après de nouvelles sorties.', 'yume-core' ) . $lien . '</p></section>';
	}
	charger_styles();
	return $html . $cartes . '</section>';
}
