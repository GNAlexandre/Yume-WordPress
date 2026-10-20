<?php
/**
 * Rubrique « À rattraper » de Mon compte (#yn-rattrapage, juste après « Reprendre la lecture »),
 * ajoutée par les filtres yume_compte_rubriques et yume_compte_sections_html du module social
 * (contrat §7) : même liste que l'accueil, LIMITE_COMPTE séries au plus, ou « Vous êtes à jour
 * sur toutes vos séries. ». Seulement pour un membre qui a commencé au moins une série encore en
 * ligne (sinon « Reprendre la lecture » invite déjà à parcourir la bibliothèque).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Rattrapage;

defined( 'ABSPATH' ) || exit;

/**
 * La rubrique s'affiche-t-elle pour ce membre ?
 *
 * @param int $user_id Membre.
 */
function rubrique_visible( int $user_id ): bool {
	return $user_id > 0 && actif() && donnees_membre( $user_id )['series'] > 0;
}

/**
 * Insère une entrée après une ancre connue (à la fin si l'ancre est absente).
 *
 * @param array  $liste  Ancre => valeur, dans l'ordre.
 * @param string $apres  Ancre de référence.
 * @param string $ancre  Nouvelle ancre.
 * @param mixed  $valeur Valeur.
 */
function inserer_apres( array $liste, string $apres, string $ancre, $valeur ): array {
	if ( ! array_key_exists( $apres, $liste ) ) {
		$liste[ $ancre ] = $valeur;
		return $liste;
	}
	$sortie = array();
	foreach ( $liste as $cle => $element ) {
		if ( $cle === $ancre ) {
			continue;
		}
		$sortie[ $cle ] = $element;
		if ( $cle === $apres ) {
			$sortie[ $ancre ] = $valeur;
		}
	}
	return $sortie;
}

/**
 * Filtre yume_compte_rubriques : « À rattraper » après « Lecture en cours ».
 *
 * @param mixed $rubriques Rubriques (ancre => libellé).
 * @param int   $user_id   Membre connecté.
 * @return mixed
 */
function rubriques( $rubriques, $user_id = 0 ) {
	if ( ! is_array( $rubriques ) || ! rubrique_visible( (int) $user_id ) ) {
		return $rubriques;
	}
	return inserer_apres( $rubriques, 'yn-lecture', ANCRE, __( 'À rattraper', 'yume-core' ) );
}
add_filter( 'yume_compte_rubriques', __NAMESPACE__ . '\\rubriques', 10, 2 );

/**
 * Filtre yume_compte_sections_html : section « À rattraper » après « Reprendre la lecture ».
 *
 * @param mixed $sections Sections (ancre => HTML).
 * @param mixed $user     Membre connecté.
 * @return mixed
 */
function sections( $sections, $user = null ) {
	if ( ! is_array( $sections ) || ! $user instanceof \WP_User || ! rubrique_visible( (int) $user->ID ) ) {
		return $sections;
	}
	return inserer_apres( $sections, 'yn-lecture', ANCRE, section_compte( (int) $user->ID ) );
}
add_filter( 'yume_compte_sections_html', __NAMESPACE__ . '\\sections', 10, 2 );

/**
 * HTML de la section « À rattraper ».
 *
 * @param int $user_id Membre.
 */
function section_compte( int $user_id ): string {
	$titre = __( 'À rattraper', 'yume-core' );
	$aide  = __( 'Chapitres publiés après votre position dans chaque série commencée', 'yume-core' );
	if ( function_exists( '\Yume\Core\Social\debut_section' ) ) {
		$html = \Yume\Core\Social\debut_section( ANCRE, $titre, $aide );
	} else {
		$html = '<section class="yn-account__panneau" id="' . esc_attr( ANCRE ) . '" aria-labelledby="' . esc_attr( ANCRE . '-titre' ) . '" data-yn-panneau="' . esc_attr( ANCRE ) . '" tabindex="-1">'
			. '<div class="yn-account__entete"><h2 class="yn-account__titre" id="' . esc_attr( ANCRE . '-titre' ) . '">' . esc_html( $titre ) . '</h2>'
			. '<p class="yn-muted yn-account__aide">' . esc_html( $aide ) . '</p></div>';
	}
	$donnees = rattrapage( $user_id, LIMITE_COMPTE );
	$cartes  = $donnees['oeuvres'] ? rendu_cartes( $donnees['oeuvres'], 'carte' ) : '';
	if ( '' === $cartes ) {
		$html .= '<p class="yn-account__vide" data-yn-rattrapage-a-jour>' . esc_html__( 'Vous êtes à jour sur toutes vos séries.', 'yume-core' ) . '</p>';
	} else {
		$html .= $cartes;
		if ( $donnees['total'] > count( $donnees['oeuvres'] ) ) {
			$autres = $donnees['total'] - count( $donnees['oeuvres'] );
			/* translators: %d : nombre de séries à rattraper non affichées. */
			$html .= '<p class="yn-muted yn-account__aide">' . esc_html( sprintf( _n( 'Et %d autre série à rattraper.', 'Et %d autres séries à rattraper.', $autres, 'yume-core' ), $autres ) ) . '</p>';
		}
	}
	return $html . '</section>';
}
