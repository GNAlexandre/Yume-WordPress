<?php
/**
 * Rendu des blocs du lecteur : yume/chapter-header et yume/chapter-nav (placés dans
 * l'article .yn-reader du modèle « Chapitre » du thème).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Fil d'Ariane d'un chapitre (œuvre › tome › chapitre), aussi utilisé par le JSON-LD.
 *
 * @param int $chapitre_id ID du chapitre.
 * @return array<int,array{texte:string,url:string}>
 */
function ariane_chapitre( int $chapitre_id ): array {
	$tome_id   = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $chapitre_id ) : 0;
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $chapitre_id ) : 0;
	$ariane    = array();
	if ( $oeuvre_id ) {
		$ariane[] = array(
			'texte' => titre( $oeuvre_id ),
			'url'   => lien_public( $oeuvre_id ),
		);
	}
	if ( $tome_id ) {
		$ariane[] = array(
			'texte' => libelle_tome( $tome_id ),
			'url'   => lien_public( $tome_id ),
		);
	}
	$ariane[] = array(
		'texte' => libelle_chapitre( $chapitre_id ),
		'url'   => (string) get_permalink( $chapitre_id ),
	);
	return $ariane;
}

/**
 * Rendu de yume/chapter-header : fil d'Ariane, « Chapitre N » (h1), sous-titre (.yn-subtitle),
 * crédits et temps de lecture.
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_chapter_header( array $attributs = array(), $bloc = null ): string {
	$chapitre_id = chapitre_contexte( $bloc );
	if ( ! $chapitre_id ) {
		return rendu_sans_contexte( 'yn-chapter-header', __( 'En-tête du chapitre : visible sur un chapitre.', 'yume-core' ) );
	}
	$sous_titre = meta_texte( $chapitre_id, 'yume_sous_titre' );
	$credits    = credits_herites( $chapitre_id );
	$minutes    = (int) get_post_meta( $chapitre_id, 'yume_temps_lecture', true );

	$html  = '<header ' . attributs_racine( 'yn-chapter-header' ) . '>';
	$html .= fil_ariane( ariane_chapitre( $chapitre_id ), 'yn-chapter-header__ariane' );
	$html .= '<hgroup class="yn-chapter-header__titres">';
	$html .= '<h1 class="yn-chapter-header__titre">' . esc_html( libelle_chapitre( $chapitre_id ) ) . '</h1>';
	if ( '' !== $sous_titre ) {
		$html .= '<p class="yn-subtitle">' . esc_html( $sous_titre ) . '</p>';
	}
	$html .= '</hgroup>';
	if ( $credits || $minutes > 0 ) {
		$html .= '<p class="yn-label yn-chapter-header__meta">';
		if ( $credits ) {
			$html .= '<span class="yn-credits">' . credits_en_ligne( $credits ) . '</span>';
		}
		if ( $minutes > 0 ) {
			$html .= ( $credits ? '<span class="yn-chapter-header__sep" aria-hidden="true">&nbsp;· </span><span class="yn-visually-hidden">. </span>' : '' );
			/* translators: %s : durée (« ~18 min »). */
			$html .= '<span class="yn-chapter-header__lecture">' . esc_html( sprintf( __( 'Lecture %s', 'yume-core' ), duree_lecture( $minutes ) ) ) . '</span>';
		}
		$html .= '</p>';
	}
	return $html . '</header>';
}

/**
 * Libellé d'un chapitre voisin : « Chapitre 2 », précédé du tome s'il change de tome.
 *
 * @param \WP_Post $voisin      Chapitre voisin.
 * @param int      $tome_actuel Tome du chapitre courant.
 */
function libelle_voisin( \WP_Post $voisin, int $tome_actuel ): string {
	$libelle = libelle_chapitre( (int) $voisin->ID );
	$tome_id = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( (int) $voisin->ID ) : 0;
	if ( $tome_id && $tome_id !== $tome_actuel ) {
		$libelle = libelle_tome( $tome_id ) . ' · ' . $libelle;
	}
	return $libelle;
}

/**
 * Rendu de yume/chapter-nav : Précédent · Sommaire · Suivant (liens rel=prev/next).
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_chapter_nav( array $attributs = array(), $bloc = null ): string {
	$chapitre_id = chapitre_contexte( $bloc );
	if ( ! $chapitre_id || ! function_exists( 'yume_chapitre_voisin' ) ) {
		return rendu_sans_contexte( 'yn-chapter-nav', __( 'Navigation entre chapitres : visible sur un chapitre.', 'yume-core' ) );
	}
	$tome_id   = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $chapitre_id ) : 0;
	$precedent = yume_chapitre_voisin( $chapitre_id, 'prev' );
	$suivant   = yume_chapitre_voisin( $chapitre_id, 'next' );

	$html = '<nav ' . attributs_racine( 'yn-chapter-nav', array( 'aria-label' => __( 'Chapitres précédent et suivant', 'yume-core' ) ) ) . '>';

	if ( $precedent instanceof \WP_Post ) {
		$html .= sprintf(
			'<a class="yn-btn yn-chapter-nav__lien yn-chapter-nav__precedent" rel="prev" href="%1$s">%2$s<span class="yn-chapter-nav__texte"><span class="yn-visually-hidden">%3$s </span>%4$s</span></a>',
			esc_url( (string) get_permalink( $precedent ) ),
			icone( 'precedent' ),
			esc_html__( 'Chapitre précédent :', 'yume-core' ),
			esc_html( libelle_voisin( $precedent, $tome_id ) )
		);
	} else {
		$html .= '<span class="yn-chapter-nav__vide" aria-hidden="true"></span>';
	}

	$lien_tome = $tome_id ? lien_public( $tome_id ) : '';
	if ( '' !== $lien_tome ) {
		$html .= sprintf(
			'<a class="yn-btn yn-chapter-nav__lien yn-chapter-nav__sommaire" href="%1$s">%2$s<span class="yn-chapter-nav__texte">%3$s<span class="yn-chapter-nav__complement"> %4$s</span><span class="yn-visually-hidden"> — %5$s</span></span></a>',
			esc_url( $lien_tome ),
			icone( 'sommaire' ),
			esc_html__( 'Sommaire', 'yume-core' ),
			esc_html__( 'du tome', 'yume-core' ),
			esc_html( libelle_tome( $tome_id ) )
		);
	}

	if ( $suivant instanceof \WP_Post ) {
		$sous_titre = meta_texte( (int) $suivant->ID, 'yume_sous_titre' );
		$texte      = libelle_voisin( $suivant, $tome_id ) . ( '' !== $sous_titre ? ' · ' . $sous_titre : '' );
		$html      .= sprintf(
			'<a class="yn-btn yn-btn--primary yn-chapter-nav__lien yn-chapter-nav__suivant" rel="next" href="%1$s"><span class="yn-chapter-nav__texte"><span class="yn-visually-hidden">%2$s </span>%3$s</span>%4$s</a>',
			esc_url( (string) get_permalink( $suivant ) ),
			esc_html__( 'Chapitre suivant :', 'yume-core' ),
			esc_html( $texte ),
			icone( 'suivant' )
		);
	} else {
		$html .= '<p class="yn-chapter-nav__fin yn-muted">' . esc_html__( 'Dernier chapitre disponible', 'yume-core' ) . '</p>';
	}
	return $html . '</nav>';
}
