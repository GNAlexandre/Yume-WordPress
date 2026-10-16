<?php
/**
 * Administration du module mangadex : boîte « MangaDex » des œuvres (série liée) et des
 * chapitres hébergés sur MangaDex (informations en lecture seule).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/** Action du jeton de la boîte des œuvres. */
const NONCE_OEUVRE = 'yume_mdx_oeuvre';

/**
 * Boîte « MangaDex » de l'écran d'une œuvre.
 */
function boite_oeuvre_ajouter(): void {
	add_meta_box( 'yume-mangadex', __( 'MangaDex', 'yume-core' ), __NAMESPACE__ . '\\boite_oeuvre', 'yume_oeuvre', 'side', 'default' );
}
add_action( 'add_meta_boxes_yume_oeuvre', __NAMESPACE__ . '\\boite_oeuvre_ajouter' );

/**
 * Rendu de la boîte des œuvres : série MangaDex (identifiant ou adresse).
 *
 * @param \WP_Post $post Œuvre.
 */
function boite_oeuvre( \WP_Post $post ): void {
	$serie = yume_mangadex_serie( (int) $post->ID );
	wp_nonce_field( NONCE_OEUVRE, '_yume_mdx_nonce' );
	echo '<p><label for="yume-mdx-serie">' . esc_html__( 'Série MangaDex', 'yume-core' ) . '</label>';
	echo '<input type="text" class="widefat code" id="yume-mdx-serie" name="yume_mdx[serie]" value="' . esc_attr( $serie ) . '" placeholder="https://mangadex.org/title/…"></p>';
	echo '<p class="description">' . esc_html__( 'Adresse ou identifiant de la série sur MangaDex. Les chapitres en français du groupe de l’équipe (Yume → Réglages → Mangas) seront lus sur le site, sans qu’aucune image n’y soit gardée. Laisser vide pour une œuvre sans lecture MangaDex.', 'yume-core' ) . '</p>';
	if ( '' === $serie ) {
		return;
	}
	echo '<p><a href="' . esc_url( 'https://mangadex.org/title/' . $serie ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir la série sur MangaDex', 'yume-core' ) . '<span class="screen-reader-text"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a></p>';
	$autre = yume_mangadex_oeuvre_par_serie( $serie );
	if ( $autre && (int) $post->ID !== $autre ) {
		/* translators: %s : titre de l'autre œuvre */
		echo '<p class="notice notice-warning inline">' . esc_html( sprintf( __( 'Cette série est déjà liée à « %s » : ses chapitres n’iront que dans la première œuvre liée.', 'yume-core' ), get_the_title( $autre ) ) ) . '</p>';
	}
}

/**
 * Enregistre la série MangaDex d'une œuvre (jeton, droits, pas les sauvegardes automatiques).
 *
 * @param int $post_id Œuvre.
 */
function enregistrer_oeuvre( $post_id ): void {
	$post_id = (int) $post_id;
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- vérifié ci-dessous.
	$jeton = isset( $_POST['_yume_mdx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_yume_mdx_nonce'] ) ) : '';
	if ( '' === $jeton || ! wp_verify_nonce( $jeton, NONCE_OEUVRE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$saisie = isset( $_POST['yume_mdx'] ) && is_array( $_POST['yume_mdx'] ) ? wp_unslash( $_POST['yume_mdx'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- extraire_uuid() ne garde qu'un identifiant.
	$serie  = extraire_uuid( $saisie['serie'] ?? '' );
	if ( '' === $serie ) {
		delete_post_meta( $post_id, 'yume_mdx_serie' );
	} else {
		update_post_meta( $post_id, 'yume_mdx_serie', $serie );
	}
}
add_action( 'save_post_yume_oeuvre', __NAMESPACE__ . '\\enregistrer_oeuvre' );

/**
 * Boîte « MangaDex » d'un chapitre hébergé sur MangaDex (lecture seule : la synchronisation
 * tient ces informations à jour).
 *
 * @param \WP_Post $post Chapitre.
 */
function boite_chapitre_ajouter( $post ): void {
	if ( $post instanceof \WP_Post && null !== yume_mangadex_chapitre( (int) $post->ID ) ) {
		add_meta_box( 'yume-mangadex', __( 'MangaDex', 'yume-core' ), __NAMESPACE__ . '\\boite_chapitre', 'yume_chapitre', 'side', 'default' );
	}
}
add_action( 'add_meta_boxes_yume_chapitre', __NAMESPACE__ . '\\boite_chapitre_ajouter' );

/**
 * Rendu de la boîte d'un chapitre hébergé sur MangaDex.
 *
 * @param \WP_Post $post Chapitre.
 */
function boite_chapitre( \WP_Post $post ): void {
	$mdx = yume_mangadex_chapitre( (int) $post->ID );
	if ( null === $mdx ) {
		return;
	}
	echo '<p>' . esc_html__( 'Chapitre hébergé sur MangaDex : ses pages sont relayées à la lecture, aucune image n’est gardée par le site.', 'yume-core' ) . '</p><ul>';
	/* translators: %d : nombre de pages */
	echo '<li>' . esc_html( sprintf( _n( '%d page', '%d pages', $mdx['pages'], 'yume-core' ), $mdx['pages'] ) ) . '</li>';
	if ( $mdx['groupes'] ) {
		/* translators: %s : groupes de traduction */
		echo '<li>' . esc_html( sprintf( __( 'Traduction : %s', 'yume-core' ), implode( ', ', $mdx['groupes'] ) ) ) . '</li>';
	}
	echo '<li><code>' . esc_html( $mdx['uuid'] ) . '</code></li></ul>';
	echo '<p><a href="' . esc_url( $mdx['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir le chapitre sur MangaDex', 'yume-core' ) . '<span class="screen-reader-text"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a></p>';
}
