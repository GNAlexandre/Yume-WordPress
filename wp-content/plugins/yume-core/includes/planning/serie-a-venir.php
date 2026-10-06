<?php
/**
 * Série à venir (lot S) : annoncer au planning public le premier tome d'une œuvre encore en
 * brouillon sans dévoiler son titre.
 *
 * - Métas de l'œuvre yume_serie_a_venir ('1' ou absente) et yume_libelle_a_venir (nom public,
 *   « Nouvelle série à venir » si vide), déclarées dans core/meta.php ; lecture par
 *   yume_oeuvre_a_venir() et yume_titre_public_oeuvre() (core/api.php) ; saisie dans les
 *   formulaires « Nouvelle œuvre » et « Modifier l'œuvre » (section « Annonce au planning »,
 *   oeuvres-equipe.php) par enregistrer_serie_a_venir().
 * - Côté lecteurs, les tomes d'une série à venir paraissent au planning public (planning.php :
 *   lignes_depuis_ids(), ligne_titre_public()) sous le nom public : tome sans sous-titre, sans
 *   lien vers l'œuvre ni le tome, sans couverture (vitrine.php), sans « Suivre l'œuvre »
 *   (bouton_suivre() exige une œuvre publiée) ; journal public et flux sous le même nom
 *   (journal.php : cible_journal(), rest.php). Les autres brouillons restent invisibles.
 * - Espace équipe : vrai titre partout, avec la pastille « Titre caché au public »
 *   (pastille_titre_cache()).
 * - Révélation : décocher la case, ou automatiquement à la première publication de l'œuvre ou
 *   d'un de ses tomes ou chapitres (transition vers publish : reveler_a_la_publication()) ;
 *   métas supprimées et ligne « serie_a_venir » au journal de l'équipe (jamais publique).
 * - Toute modification des deux métas vide les caches comme un changement d'état de l'œuvre
 *   (vider_caches_etat_oeuvre() : bibliothèque, calendrier ICS, pages Batcache).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Méta « série à venir » de l'œuvre ('1' ou absente). */
const META_SERIE_A_VENIR = 'yume_serie_a_venir';

/** Méta du nom affiché au public d'une série à venir. */
const META_LIBELLE_A_VENIR = 'yume_libelle_a_venir';

/**
 * Nom public d'une série à venir tel qu'enregistré (« Nouvelle série à venir » si vide), même
 * une fois l'œuvre publiée (journal de la révélation).
 *
 * @param int $oeuvre_id Œuvre.
 */
function libelle_a_venir( int $oeuvre_id ): string {
	$libelle = trim( (string) get_post_meta( $oeuvre_id, META_LIBELLE_A_VENIR, true ) );
	return '' !== $libelle ? $libelle : __( 'Nouvelle série à venir', 'yume-core' );
}

/**
 * Pastille de l'espace équipe « Titre caché au public » d'une série à venir (vide sinon).
 *
 * @param int $oeuvre_id Œuvre.
 */
function pastille_titre_cache( int $oeuvre_id ): string {
	if ( ! yume_oeuvre_a_venir( $oeuvre_id ) ) {
		return '';
	}
	/* translators: %s : nom affiché au public (« Nouvelle série à venir ») */
	$aide = sprintf( __( 'Au planning public : « %s », sans titre, lien ni couverture.', 'yume-core' ), yume_titre_public_oeuvre( $oeuvre_id ) );
	return ' <span class="yn-chip yn-chip--info" title="' . esc_attr( $aide ) . '"><span aria-hidden="true">◌</span> ' . esc_html__( 'Titre caché au public', 'yume-core' ) . '</span>';
}

/**
 * Enregistre le choix « série à venir » d'une œuvre (formulaires de l'œuvre). Une œuvre publiée
 * ou privée n'est jamais « à venir » : ses métas sont retirées. Un changement est noté au
 * journal de l'équipe.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param bool   $a_venir   Case « Série à venir » cochée.
 * @param string $libelle   Nom affiché au public (vide : « Nouvelle série à venir »).
 * @param int    $user_id   Auteur.
 */
function enregistrer_serie_a_venir( int $oeuvre_id, bool $a_venir, string $libelle, int $user_id ): void {
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return;
	}
	if ( in_array( get_post_status( $oeuvre_id ), array( 'publish', 'private' ), true ) ) {
		$a_venir = false;
	}
	$libelle = \Yume\Core\Core\san_libelle_a_venir( $libelle );
	if ( ! $a_venir ) {
		reveler_serie( $oeuvre_id, $user_id, 'equipe' );
		return;
	}
	$avant  = '1' === (string) get_post_meta( $oeuvre_id, META_SERIE_A_VENIR, true );
	$ancien = trim( (string) get_post_meta( $oeuvre_id, META_LIBELLE_A_VENIR, true ) );
	update_post_meta( $oeuvre_id, META_SERIE_A_VENIR, '1' );
	if ( '' === $libelle ) {
		delete_post_meta( $oeuvre_id, META_LIBELLE_A_VENIR );
	} else {
		update_post_meta( $oeuvre_id, META_LIBELLE_A_VENIR, $libelle );
	}
	if ( ! $avant || $ancien !== $libelle ) {
		journaliser(
			0,
			$user_id,
			'serie_a_venir',
			$avant ? '1' : '',
			array(
				'oeuvre'  => $oeuvre_id,
				'titre'   => titre_brut( $oeuvre_id ),
				'a_venir' => true,
				'libelle' => libelle_a_venir( $oeuvre_id ),
				'avant'   => $avant,
			),
			false
		);
	}
}

/**
 * Révèle le titre d'une série à venir : métas retirées, ligne au journal de l'équipe.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $user_id   Auteur (0 : système).
 * @param string $motif     'equipe' (case décochée) ou 'publication' (première publication).
 * @return bool Vrai si l'œuvre était une série à venir.
 */
function reveler_serie( int $oeuvre_id, int $user_id, string $motif ): bool {
	$etait   = '1' === (string) get_post_meta( $oeuvre_id, META_SERIE_A_VENIR, true );
	$libelle = libelle_a_venir( $oeuvre_id );
	delete_post_meta( $oeuvre_id, META_SERIE_A_VENIR );
	delete_post_meta( $oeuvre_id, META_LIBELLE_A_VENIR );
	if ( ! $etait ) {
		return false;
	}
	journaliser(
		0,
		$user_id,
		'serie_a_venir',
		'1',
		array(
			'oeuvre'  => $oeuvre_id,
			'titre'   => titre_brut( $oeuvre_id ),
			'a_venir' => false,
			'libelle' => $libelle,
			'motif'   => 'publication' === $motif ? 'publication' : 'equipe',
		),
		false
	);
	/**
	 * Le titre d'une série à venir vient d'être révélé au public.
	 *
	 * @param int    $oeuvre_id Œuvre.
	 * @param string $motif     'equipe' ou 'publication'.
	 * @param int    $user_id   Auteur.
	 */
	do_action( 'yume_serie_revelee', $oeuvre_id, $motif, $user_id );
	return true;
}

/**
 * Première publication de l'œuvre, d'un de ses tomes ou d'un de ses chapitres (transition vers
 * publish) : le titre d'une série à venir est révélé avant toute annonce.
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function reveler_a_la_publication( $nouveau, $ancien, $post ): void {
	if ( 'publish' !== $nouveau || 'publish' === $ancien || ! $post instanceof \WP_Post ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'yume_oeuvre', 'yume_tome', 'yume_chapitre' ), true ) ) {
		return;
	}
	$oeuvre_id = yume_get_oeuvre_id( (int) $post->ID );
	if ( $oeuvre_id && '1' === (string) get_post_meta( $oeuvre_id, META_SERIE_A_VENIR, true ) ) {
		reveler_serie( $oeuvre_id, auteur_publication( (int) $post->ID ), 'publication' );
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\reveler_a_la_publication', 1, 3 );

/**
 * Métas « série à venir » ajoutées, modifiées ou supprimées : caches vidés comme pour un
 * changement d'état de l'œuvre (bibliothèque, calendrier ICS, pages du planning dans Batcache).
 *
 * @param mixed  $meta_id   ID(s) de la méta.
 * @param int    $object_id Œuvre.
 * @param string $meta_key  Clé.
 */
function vider_caches_serie_a_venir( $meta_id, $object_id, $meta_key ): void {
	unset( $meta_id );
	if ( in_array( $meta_key, array( META_SERIE_A_VENIR, META_LIBELLE_A_VENIR ), true ) && 'yume_oeuvre' === get_post_type( (int) $object_id ) ) {
		vider_caches_etat_oeuvre( (int) $object_id );
	}
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\vider_caches_serie_a_venir', 10, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\vider_caches_serie_a_venir', 10, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\vider_caches_serie_a_venir', 10, 3 );

/**
 * Texte d'une ligne « serie_a_venir » du journal de l'équipe.
 *
 * @param array $infos oeuvre, titre, a_venir, libelle, avant, motif.
 */
function texte_serie_a_venir_journal( array $infos ): string {
	$titre   = (string) ( $infos['titre'] ?? '' );
	$libelle = (string) ( $infos['libelle'] ?? '' );
	if ( ! empty( $infos['a_venir'] ) ) {
		return ! empty( $infos['avant'] )
			/* translators: 1: titre de l'œuvre, 2: nom affiché au public */
			? sprintf( __( 'nom public de « %1$s » : « %2$s »', 'yume-core' ), $titre, $libelle )
			/* translators: 1: titre de l'œuvre, 2: nom affiché au public */
			: sprintf( __( 'titre de « %1$s » caché au public : série annoncée sous le nom « %2$s »', 'yume-core' ), $titre, $libelle );
	}
	return 'publication' === ( $infos['motif'] ?? '' )
		/* translators: %s : titre de l'œuvre */
		? sprintf( __( 'titre de « %s » révélé au public (première publication)', 'yume-core' ), $titre )
		/* translators: %s : titre de l'œuvre */
		: sprintf( __( 'titre de « %s » révélé au public', 'yume-core' ), $titre );
}
