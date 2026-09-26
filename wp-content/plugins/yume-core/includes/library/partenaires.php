<?php
/**
 * Bloc yume/partenaires : section « Nos partenaires » de l'accueil (logo, nom, description,
 * lien dans un nouvel onglet), alimentée par le réglage « partenaires » (Yume → Réglages).
 *
 * Tant que le réglage n'a jamais été enregistré, les quatre partenaires de l'ancien site sont
 * affichés (valeur par défaut du module core). Leur logo est la pièce jointe de l'ancien site
 * si elle existe et porte le fichier attendu (mêmes ID après la migration) ; sinon il est
 * cherché par nom de fichier dans la médiathèque ; à défaut, les initiales du nom
 * (monogramme) le remplacent : démonstration, Playground, autre site.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/** Transient : pièces jointes trouvées par nom de fichier (logos des partenaires par défaut). */
const TRANSIENT_LOGOS_PARTENAIRES = 'yume_partenaires_logos';

/**
 * Partenaires à afficher, dans l'ordre du réglage : nom, url, description, logo (ID de pièce
 * jointe ou adresse d'image, '' sans logo) et fichier (nom du fichier attendu, lignes par
 * défaut). Les lignes sans nom ou sans lien http(s) sont écartées.
 *
 * @return array<int,array{nom:string,url:string,description:string,logo:int|string,fichier:string}>
 */
function partenaires(): array {
	$brut = function_exists( 'yume_setting' ) ? yume_setting( 'partenaires' ) : null;
	if ( ! is_array( $brut ) ) {
		$brut = function_exists( 'Yume\Core\Core\partenaires_par_defaut' ) ? \Yume\Core\Core\partenaires_par_defaut() : array();
	}
	$liste = array();
	foreach ( $brut as $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$nom = is_scalar( $ligne['nom'] ?? null ) ? trim( wp_strip_all_tags( (string) $ligne['nom'] ) ) : '';
		$url = is_scalar( $ligne['url'] ?? null ) ? esc_url_raw( trim( (string) $ligne['url'] ), array( 'http', 'https' ) ) : '';
		if ( '' === $nom || '' === $url ) {
			continue;
		}
		$logo    = $ligne['logo'] ?? '';
		$liste[] = array(
			'nom'         => $nom,
			'url'         => $url,
			'description' => is_scalar( $ligne['description'] ?? null ) ? trim( wp_strip_all_tags( (string) $ligne['description'] ) ) : '',
			'logo'        => is_int( $logo ) || ( is_string( $logo ) && ctype_digit( $logo ) ) ? (int) $logo : ( is_string( $logo ) ? trim( $logo ) : '' ),
			'fichier'     => is_scalar( $ligne['fichier'] ?? null ) ? (string) $ligne['fichier'] : '',
		);
	}
	/**
	 * Filtre les partenaires affichés par le bloc yume/partenaires.
	 *
	 * @param array<int,array<string,mixed>> $liste Partenaires.
	 */
	return (array) apply_filters( 'yume_partenaires', $liste );
}

/**
 * Noms de fichier (en minuscules) d'une pièce jointe : fichier enregistré et, pour une image
 * réduite par WordPress (« -scaled »), fichier d'origine.
 *
 * @param int $id Pièce jointe.
 * @return string[]
 */
function fichiers_piece_jointe( int $id ): array {
	$noms    = array();
	$fichier = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( '' !== $fichier ) {
		$noms[] = strtolower( wp_basename( $fichier ) );
	}
	$meta = wp_get_attachment_metadata( $id );
	if ( is_array( $meta ) && ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
		$noms[] = strtolower( wp_basename( $meta['original_image'] ) );
	}
	return array_values( array_unique( $noms ) );
}

/**
 * La pièce jointe existe-t-elle, est-ce une image, et porte-t-elle le fichier attendu ?
 *
 * @param int    $id      Pièce jointe.
 * @param string $fichier Nom du fichier attendu ('' : pas de vérification du nom).
 */
function logo_valide( int $id, string $fichier = '' ): bool {
	if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
		return false;
	}
	return '' === $fichier || in_array( strtolower( wp_basename( $fichier ) ), fichiers_piece_jointe( $id ), true );
}

/**
 * Cherche une image de la médiathèque par nom de fichier (résultat mis en cache).
 *
 * @param string $fichier Nom du fichier (sans dossier).
 * @return int ID de la pièce jointe, 0 si aucune.
 */
function chercher_logo( string $fichier ): int {
	$fichier = wp_basename( $fichier );
	if ( '' === $fichier ) {
		return 0;
	}
	$cache = get_transient( TRANSIENT_LOGOS_PARTENAIRES );
	$cache = is_array( $cache ) ? $cache : array();
	if ( isset( $cache[ $fichier ] ) ) {
		$id = (int) $cache[ $fichier ];
		if ( 0 === $id || logo_valide( $id, $fichier ) ) {
			return $id;
		}
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- résultat mis en cache (transient) ci-dessous.
	$ids    = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value LIKE %s ) ORDER BY post_id ASC LIMIT 20",
			$fichier,
			'%/' . $wpdb->esc_like( $fichier )
		)
	);
	$trouve = 0;
	foreach ( (array) $ids as $candidat ) {
		if ( logo_valide( (int) $candidat, $fichier ) ) {
			$trouve = (int) $candidat;
			break;
		}
	}
	$cache[ $fichier ] = $trouve;
	set_transient( TRANSIENT_LOGOS_PARTENAIRES, $cache, DAY_IN_SECONDS );
	return $trouve;
}

/**
 * Oublie les logos trouvés par nom de fichier (ajout, modification ou suppression d'un média).
 */
function oublier_logos_partenaires(): void {
	delete_transient( TRANSIENT_LOGOS_PARTENAIRES );
}
add_action( 'add_attachment', __NAMESPACE__ . '\\oublier_logos_partenaires' );
add_action( 'edit_attachment', __NAMESPACE__ . '\\oublier_logos_partenaires' );
add_action( 'delete_attachment', __NAMESPACE__ . '\\oublier_logos_partenaires' );

/**
 * Logo d'un partenaire : pièce jointe (id) ou adresse d'image (url) ; les deux vides : monogramme.
 *
 * Avec un nom de fichier attendu (partenaires par défaut), l'ID n'est retenu que si la pièce
 * jointe porte ce fichier (un autre site peut avoir une pièce jointe sans rapport au même ID) ;
 * sinon l'image est cherchée par nom de fichier.
 *
 * @param int|string $logo    ID de pièce jointe ou adresse d'image.
 * @param string     $fichier Nom du fichier attendu ('' : aucun).
 * @return array{id:int,url:string}
 */
function resoudre_logo_partenaire( $logo, string $fichier = '' ): array {
	$aucun = array(
		'id'  => 0,
		'url' => '',
	);
	if ( is_int( $logo ) || ( is_string( $logo ) && ctype_digit( $logo ) ) ) {
		$id = (int) $logo;
		if ( logo_valide( $id, $fichier ) ) {
			return array(
				'id'  => $id,
				'url' => '',
			);
		}
		$trouve = '' !== $fichier ? chercher_logo( $fichier ) : 0;
		return $trouve ? array(
			'id'  => $trouve,
			'url' => '',
		) : $aucun;
	}
	$url = is_string( $logo ) ? esc_url_raw( trim( $logo ), array( 'http', 'https' ) ) : '';
	if ( '' !== $url ) {
		return array(
			'id'  => 0,
			'url' => $url,
		);
	}
	$trouve = '' !== $fichier ? chercher_logo( $fichier ) : 0;
	return $trouve ? array(
		'id'  => $trouve,
		'url' => '',
	) : $aucun;
}

/**
 * Formulaire des réglages : le logo des lignes par défaut devient la pièce jointe réellement
 * trouvée sur ce site (ou vide), pour que l'enregistrement du formulaire la conserve.
 *
 * @param array<int,array<string,mixed>> $lignes Partenaires.
 * @return array<int,array<string,mixed>>
 */
function logos_formulaire_partenaires( $lignes ): array {
	$lignes = is_array( $lignes ) ? $lignes : array();
	foreach ( $lignes as $i => $ligne ) {
		if ( ! is_array( $ligne ) || empty( $ligne['fichier'] ) || ! is_scalar( $ligne['fichier'] ) ) {
			continue;
		}
		$logo                 = resoudre_logo_partenaire( $ligne['logo'] ?? '', (string) $ligne['fichier'] );
		$lignes[ $i ]['logo'] = $logo['id'] ? $logo['id'] : $logo['url'];
		unset( $lignes[ $i ]['fichier'] );
	}
	return $lignes;
}
add_filter( 'yume_reglages_partenaires_formulaire', __NAMESPACE__ . '\\logos_formulaire_partenaires' );

/**
 * Initiales d'un nom (monogramme) : premières lettres des deux premiers mots significatifs
 * (« Novel de l'Aube » → NA, « J-Garden » → JG) ou majuscules d'un mot unique (« MassNovel » → MN).
 *
 * @param string $nom Nom.
 */
function initiales( string $nom ): string {
	$mots   = preg_split( '/[\s\-–—_.\/]+/u', trim( $nom ), -1, PREG_SPLIT_NO_EMPTY );
	$mots   = is_array( $mots ) ? $mots : array();
	$vides  = array( 'de', 'du', 'des', 'la', 'le', 'les', 'et', 'of', 'the', '&' );
	$gardes = array();
	foreach ( $mots as $mot ) {
		$mot = (string) preg_replace( '/^[dlDL][\'’]/u', '', $mot );
		$mot = (string) preg_replace( '/^[^\p{L}\p{N}]+/u', '', $mot );
		if ( '' !== $mot && ! in_array( mb_strtolower( $mot ), $vides, true ) ) {
			$gardes[] = $mot;
		}
	}
	if ( ! $gardes ) {
		return '?';
	}
	if ( count( $gardes ) >= 2 ) {
		return mb_strtoupper( mb_substr( $gardes[0], 0, 1 ) . mb_substr( $gardes[1], 0, 1 ) );
	}
	if ( preg_match_all( '/\p{Lu}/u', $gardes[0], $majuscules ) && count( $majuscules[0] ) >= 2 ) {
		return $majuscules[0][0] . $majuscules[0][1];
	}
	return mb_strtoupper( mb_substr( $gardes[0], 0, 1 ) );
}

/**
 * Logo (image ou monogramme) d'une carte de partenaire.
 *
 * @param array<string,mixed> $partenaire Partenaire (voir partenaires()).
 */
function logo_partenaire( array $partenaire ): string {
	$logo = resoudre_logo_partenaire( $partenaire['logo'] ?? '', (string) ( $partenaire['fichier'] ?? '' ) );
	// Le nom du partenaire est écrit sur la carte : le logo est décoratif (alt vide).
	$attributs = array(
		'class'    => 'yn-partenaire__image',
		'alt'      => '',
		'loading'  => 'lazy',
		'decoding' => 'async',
		'sizes'    => '(max-width: 600px) 120px, 240px',
	);
	$image     = '';
	if ( $logo['id'] ) {
		$image = wp_get_attachment_image( $logo['id'], 'medium', false, $attributs );
	} elseif ( '' !== $logo['url'] ) {
		$image = sprintf( '<img class="yn-partenaire__image" src="%s" alt="" loading="lazy" decoding="async" />', esc_url( $logo['url'] ) );
	}
	if ( '' !== $image ) {
		return '<span class="yn-partenaire__logo">' . $image . '</span>';
	}
	return '<span class="yn-partenaire__logo yn-partenaire__logo--monogramme" aria-hidden="true"><span class="yn-partenaire__monogramme">' . esc_html( initiales( (string) $partenaire['nom'] ) ) . '</span></span>';
}

/**
 * Rendu de yume/partenaires : titre et cartes (lien dans un nouvel onglet, rel="noopener").
 *
 * @param array<string,mixed> $attributes Attributs du bloc (title).
 */
function rendu_partenaires( array $attributes ): string {
	$liste = partenaires();
	if ( ! $liste ) {
		return rendu_sans_contexte( 'yn-partenaires', __( 'Aucun partenaire : ajoutez-les dans Yume → Réglages → Partenaires.', 'yume-core' ) );
	}
	$titre = isset( $attributes['title'] ) && is_string( $attributes['title'] ) ? trim( $attributes['title'] ) : '';
	$titre = '' !== $titre ? $titre : __( 'Nos partenaires', 'yume-core' );
	$id    = wp_unique_id( 'yn-partenaires-titre-' );

	$cartes = '';
	foreach ( $liste as $partenaire ) {
		$description = '' !== $partenaire['description']
			? '<span class="yn-partenaire__description">' . esc_html( $partenaire['description'] ) . '</span>'
			: '';
		$cartes     .= sprintf(
			'<li class="yn-partenaires__item"><a class="yn-partenaire" href="%1$s" target="_blank" rel="noopener">%2$s<span class="yn-partenaire__texte"><span class="yn-partenaire__nom">%3$s</span>%4$s</span><span class="yn-visually-hidden"> %5$s</span></a></li>',
			esc_url( $partenaire['url'] ),
			logo_partenaire( $partenaire ),
			esc_html( $partenaire['nom'] ),
			$description,
			esc_html__( '(s’ouvre dans un nouvel onglet)', 'yume-core' )
		);
	}
	return sprintf(
		'<section %1$s><h2 class="yn-partenaires__titre" id="%2$s">%3$s</h2><ul class="yn-partenaires__liste" role="list">%4$s</ul></section>',
		attributs_racine( 'yn-partenaires', array( 'aria-labelledby' => $id ) ),
		esc_attr( $id ),
		esc_html( $titre ),
		$cartes
	);
}
