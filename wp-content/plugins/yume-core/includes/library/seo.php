<?php
/**
 * Référencement : données structurées schema.org (JSON-LD) des fiches d'œuvre (BookSeries),
 * des tomes (Book) et des chapitres (Chapter, isPartOf), avec leur fil d'Ariane
 * (BreadcrumbList) ; balises <link rel="prev|next"> des chapitres.
 *
 * Rien n'est émis pour un contenu non publié (aperçu de l'équipe).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Contenu publié affiché par la requête principale (œuvre, tome ou chapitre), ou 0.
 */
function contenu_seo(): int {
	if ( ! is_singular( array( TYPE_OEUVRE, TYPE_TOME, TYPE_CHAPITRE ) ) ) {
		return 0;
	}
	if ( function_exists( 'yume_est_page_illustrations' ) && yume_est_page_illustrations() ) {
		return 0; // Page Illustrations d'un tome (noindex) : pas de second Book pour le tome.
	}
	$id = (int) get_queried_object_id();
	return $id && 'publish' === get_post_status( $id ) ? $id : 0;
}

/**
 * Texte brut d'un résumé pour schema.org (extrait, sinon début du contenu).
 *
 * @param int $post_id ID.
 */
function description_seo( int $post_id ): string {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}
	$texte = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content;
	$texte = html_entity_decode( wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $texte ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$texte = trim( (string) preg_replace( '/\s+/u', ' ', $texte ) );
	return mb_strlen( $texte ) > 300 ? rtrim( mb_substr( $texte, 0, 297 ) ) . '…' : $texte;
}

/**
 * Personne (ou organisation) nommée pour schema.org.
 *
 * @param string $nom  Nom.
 * @param string $type Person ou Organization.
 * @return array<string,string>|null
 */
function entite_seo( string $nom, string $type = 'Person' ): ?array {
	$nom = trim( $nom );
	return '' === $nom ? null : array(
		'@type' => $type,
		'name'  => $nom,
	);
}

/**
 * Traducteurs (crédits) au format schema.org.
 *
 * @param array<string,string> $credits Crédits.
 * @return array<int,array<string,string>>
 */
function traducteurs_seo( array $credits ): array {
	$traducteurs = array();
	if ( ! empty( $credits['traduction'] ) ) {
		foreach ( preg_split( '/\s*(?:,|&|\bet\b)\s*/u', (string) $credits['traduction'] ) as $nom ) {
			$entite = entite_seo( (string) $nom );
			if ( $entite ) {
				$traducteurs[] = $entite;
			}
		}
	}
	$site = entite_seo( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), 'Organization' );
	if ( $site ) {
		$site['url']   = home_url( '/' );
		$traducteurs[] = $site;
	}
	return $traducteurs;
}

/**
 * Adresse de la couverture d'un contenu (image moyenne-grande), ou chaîne vide.
 *
 * @param int $post_id ID.
 */
function image_seo( int $post_id ): string {
	$image_id = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $post_id ) : (int) get_post_thumbnail_id( $post_id );
	$url      = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
	return is_string( $url ) ? $url : '';
}

/**
 * Nombre (position) d'un tome ou d'un chapitre, ou null.
 *
 * @param int $post_id ID.
 * @return int|float|null
 */
function position_seo( int $post_id ) {
	$numero = get_post_meta( $post_id, 'yume_numero', true );
	if ( ! is_numeric( $numero ) ) {
		return null;
	}
	$numero = (float) $numero;
	return floor( $numero ) === $numero ? (int) $numero : $numero;
}

/**
 * Identifiants schema.org (@id) stables.
 *
 * @param int    $post_id ID.
 * @param string $ancre   Fragment (oeuvre, tome, chapitre, ariane).
 */
function id_seo( int $post_id, string $ancre ): string {
	return (string) get_permalink( $post_id ) . '#' . $ancre;
}

/**
 * Élément BreadcrumbList.
 *
 * @param array<int,array{texte:string,url?:string}> $elements Éléments (du plus général au courant).
 * @param int                                        $post_id  Contenu courant.
 * @return array<string,mixed>
 */
function ariane_seo( array $elements, int $post_id ): array {
	$items = array();
	$rang  = 0;
	foreach ( $elements as $element ) {
		$url = (string) ( $element['url'] ?? '' );
		if ( '' === $url || '' === trim( (string) $element['texte'] ) ) {
			continue;
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => ++$rang,
			'name'     => (string) $element['texte'],
			'item'     => $url,
		);
	}
	return array(
		'@type'           => 'BreadcrumbList',
		'@id'             => id_seo( $post_id, 'ariane' ),
		'itemListElement' => $items,
	);
}

/**
 * Référence courte à une œuvre (BookSeries).
 *
 * @param int $oeuvre_id ID.
 * @return array<string,string>
 */
function reference_oeuvre( int $oeuvre_id ): array {
	return array(
		'@type' => 'BookSeries',
		'@id'   => id_seo( $oeuvre_id, 'oeuvre' ),
		'name'  => titre( $oeuvre_id ),
		'url'   => (string) get_permalink( $oeuvre_id ),
	);
}

/**
 * Supprime les valeurs vides (null, '', tableau vide) d'un nœud schema.org.
 *
 * @param array $noeud Nœud.
 * @return array
 */
function nettoyer_seo( array $noeud ): array {
	return array_filter(
		$noeud,
		static fn( $valeur ): bool => null !== $valeur && '' !== $valeur && array() !== $valeur
	);
}

/**
 * Données structurées d'une œuvre, d'un tome ou d'un chapitre publié.
 *
 * @param int $post_id ID.
 * @return array<string,mixed> Document JSON-LD (@context, @graph), vide si non applicable.
 */
function donnees_structurees( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
		return array();
	}
	$graphe = array();
	switch ( $post->post_type ) {
		case TYPE_OEUVRE:
			$graphe = graphe_oeuvre( $post_id );
			break;
		case TYPE_TOME:
			$graphe = graphe_tome( $post_id );
			break;
		case TYPE_CHAPITRE:
			$graphe = graphe_chapitre( $post_id );
			break;
	}
	if ( ! $graphe ) {
		return array();
	}
	/**
	 * Filtre les données structurées (JSON-LD) d'une œuvre, d'un tome ou d'un chapitre.
	 *
	 * @param array $graphe  Nœuds du @graph.
	 * @param int   $post_id Contenu.
	 */
	$graphe = (array) apply_filters( 'yume_bibliotheque_jsonld', $graphe, $post_id );
	return array(
		'@context' => 'https://schema.org',
		'@graph'   => array_values( $graphe ),
	);
}

/**
 * Nœuds d'une œuvre : BookSeries + BreadcrumbList.
 *
 * @param int $oeuvre_id ID.
 * @return array<int,array>
 */
function graphe_oeuvre( int $oeuvre_id ): array {
	$alternatifs = array_values( array_filter( array_map( static fn( $t ) => is_scalar( $t ) ? trim( wp_strip_all_tags( (string) $t ) ) : '', (array) get_post_meta( $oeuvre_id, 'yume_titres_alt', true ) ), 'strlen' ) );
	$parties     = array();
	if ( function_exists( 'yume_get_tomes' ) ) {
		foreach ( yume_get_tomes( $oeuvre_id ) as $tome ) {
			$parties[] = nettoyer_seo(
				array(
					'@type'    => 'Book',
					'@id'      => id_seo( (int) $tome->ID, 'tome' ),
					'name'     => titre( (int) $tome->ID ),
					'url'      => (string) get_permalink( $tome ),
					'position' => position_seo( (int) $tome->ID ),
				)
			);
		}
	}
	$illustrateur = entite_seo( meta_texte( $oeuvre_id, 'yume_illustrateur' ) );
	$serie        = nettoyer_seo(
		array(
			'@type'         => 'BookSeries',
			'@id'           => id_seo( $oeuvre_id, 'oeuvre' ),
			'name'          => titre( $oeuvre_id ),
			'alternateName' => $alternatifs,
			'url'           => (string) get_permalink( $oeuvre_id ),
			'description'   => description_seo( $oeuvre_id ),
			'image'         => image_seo( $oeuvre_id ),
			'author'        => entite_seo( meta_texte( $oeuvre_id, 'yume_auteur' ) ),
			'contributor'   => $illustrateur,
			'publisher'     => entite_seo( meta_texte( $oeuvre_id, 'yume_editeur_vo' ), 'Organization' ),
			'genre'         => array_values( termes_oeuvre( $oeuvre_id, TAX_GENRE ) ),
			'inLanguage'    => 'fr',
			'translator'    => traducteurs_seo( credits( $oeuvre_id, 'yume_equipe' ) ),
			'dateModified'  => get_post_modified_time( 'c', true, $oeuvre_id ),
			'hasPart'       => $parties,
		)
	);
	$ariane       = array(
		array(
			'texte' => __( 'Bibliothèque', 'yume-core' ),
			'url'   => url_bibliotheque(),
		),
		array(
			'texte' => titre( $oeuvre_id ),
			'url'   => (string) get_permalink( $oeuvre_id ),
		),
	);
	return array( $serie, ariane_seo( $ariane, $oeuvre_id ) );
}

/**
 * Nœuds d'un tome : Book (isPartOf BookSeries) + BreadcrumbList.
 *
 * @param int $tome_id ID.
 * @return array<int,array>
 */
function graphe_tome( int $tome_id ): array {
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
	$chapitres = array();
	if ( function_exists( 'yume_get_chapitres' ) ) {
		foreach ( yume_get_chapitres( $tome_id ) as $chapitre ) {
			$chapitres[] = nettoyer_seo(
				array(
					'@type'    => 'Chapter',
					'@id'      => id_seo( (int) $chapitre->ID, 'chapitre' ),
					'name'     => nom_chapitre_seo( (int) $chapitre->ID ),
					'url'      => (string) get_permalink( $chapitre ),
					'position' => position_seo( (int) $chapitre->ID ),
				)
			);
		}
	}
	$livre  = nettoyer_seo(
		array(
			'@type'         => 'Book',
			'@id'           => id_seo( $tome_id, 'tome' ),
			'name'          => titre( $tome_id ),
			'url'           => (string) get_permalink( $tome_id ),
			'bookFormat'    => 'https://schema.org/EBook',
			'inLanguage'    => 'fr',
			'position'      => position_seo( $tome_id ),
			'image'         => image_seo( $tome_id ),
			'description'   => description_seo( $tome_id ),
			'author'        => $oeuvre_id ? entite_seo( meta_texte( $oeuvre_id, 'yume_auteur' ) ) : null,
			'illustrator'   => $oeuvre_id ? entite_seo( meta_texte( $oeuvre_id, 'yume_illustrateur' ) ) : null,
			'translator'    => traducteurs_seo( credits_herites( $tome_id ) ),
			'datePublished' => get_post_time( 'c', true, $tome_id ),
			'dateModified'  => get_post_modified_time( 'c', true, $tome_id ),
			'isPartOf'      => $oeuvre_id && 'publish' === get_post_status( $oeuvre_id ) ? reference_oeuvre( $oeuvre_id ) : null,
			'hasPart'       => $chapitres,
		)
	);
	$ariane = array(
		array(
			'texte' => __( 'Bibliothèque', 'yume-core' ),
			'url'   => url_bibliotheque(),
		),
	);
	if ( $oeuvre_id ) {
		$ariane[] = array(
			'texte' => titre( $oeuvre_id ),
			'url'   => lien_public( $oeuvre_id ),
		);
	}
	$ariane[] = array(
		'texte' => libelle_tome( $tome_id ),
		'url'   => (string) get_permalink( $tome_id ),
	);
	return array( $livre, ariane_seo( $ariane, $tome_id ) );
}

/**
 * Nom d'un chapitre : « Chapitre 1 — La Crête Brumeuse ».
 *
 * @param int $chapitre_id ID.
 */
function nom_chapitre_seo( int $chapitre_id ): string {
	$sous_titre = meta_texte( $chapitre_id, 'yume_sous_titre' );
	return libelle_chapitre( $chapitre_id ) . ( '' !== $sous_titre ? ' — ' . $sous_titre : '' );
}

/**
 * Nœuds d'un chapitre : Chapter (isPartOf Book, lui-même isPartOf BookSeries) + BreadcrumbList.
 *
 * @param int $chapitre_id ID.
 * @return array<int,array>
 */
function graphe_chapitre( int $chapitre_id ): array {
	$tome_id   = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $chapitre_id ) : 0;
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $chapitre_id ) : 0;
	$livre     = null;
	if ( $tome_id && 'publish' === get_post_status( $tome_id ) ) {
		$livre = nettoyer_seo(
			array(
				'@type'    => 'Book',
				'@id'      => id_seo( $tome_id, 'tome' ),
				'name'     => titre( $tome_id ),
				'url'      => (string) get_permalink( $tome_id ),
				'isPartOf' => $oeuvre_id && 'publish' === get_post_status( $oeuvre_id ) ? reference_oeuvre( $oeuvre_id ) : null,
			)
		);
	}
	$minutes  = (int) get_post_meta( $chapitre_id, 'yume_temps_lecture', true );
	$chapitre = nettoyer_seo(
		array(
			'@type'               => 'Chapter',
			'@id'                 => id_seo( $chapitre_id, 'chapitre' ),
			'name'                => nom_chapitre_seo( $chapitre_id ),
			'headline'            => libelle_chapitre( $chapitre_id ),
			'alternativeHeadline' => meta_texte( $chapitre_id, 'yume_sous_titre' ),
			'url'                 => (string) get_permalink( $chapitre_id ),
			'position'            => position_seo( $chapitre_id ),
			'inLanguage'          => 'fr',
			'image'               => image_seo( $chapitre_id ),
			'author'              => $oeuvre_id ? entite_seo( meta_texte( $oeuvre_id, 'yume_auteur' ) ) : null,
			'translator'          => traducteurs_seo( credits_herites( $chapitre_id ) ),
			'timeRequired'        => duree_iso( $minutes ),
			'datePublished'       => get_post_time( 'c', true, $chapitre_id ),
			'dateModified'        => get_post_modified_time( 'c', true, $chapitre_id ),
			'isPartOf'            => $livre,
		)
	);
	return array( $chapitre, ariane_seo( ariane_chapitre( $chapitre_id ), $chapitre_id ) );
}

/**
 * Balise <script type="application/ld+json"> d'un contenu (chaîne vide si non applicable).
 *
 * @param int $post_id ID.
 */
function balise_jsonld( int $post_id ): string {
	$donnees = donnees_structurees( $post_id );
	if ( ! $donnees ) {
		return '';
	}
	$json = wp_json_encode( $donnees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	return is_string( $json ) ? '<script type="application/ld+json" class="yn-jsonld">' . $json . "</script>\n" : '';
}

/**
 * Balises <link rel="prev|next"> d'un chapitre publié.
 *
 * @param int $chapitre_id ID.
 */
function balises_voisins( int $chapitre_id ): string {
	if ( TYPE_CHAPITRE !== get_post_type( $chapitre_id ) || ! function_exists( 'yume_chapitre_voisin' ) ) {
		return '';
	}
	$html          = '';
	$illustrations = function_exists( 'yume_url_illustrations_avant' ) ? yume_url_illustrations_avant( $chapitre_id ) : '';
	foreach ( array( 'prev', 'next' ) as $sens ) {
		if ( 'prev' === $sens && '' !== $illustrations ) {
			// Premier chapitre du tome : la page Illustrations le précède.
			$html .= '<link rel="prev" href="' . esc_url( $illustrations ) . "\" />\n";
			continue;
		}
		$voisin = yume_chapitre_voisin( $chapitre_id, $sens );
		if ( $voisin instanceof \WP_Post ) {
			$html .= '<link rel="' . esc_attr( $sens ) . '" href="' . esc_url( (string) get_permalink( $voisin ) ) . "\" />\n";
		}
	}
	return $html;
}

/**
 * Affiche dans <head> les liens rel=prev/next d'un chapitre (près de la balise canonique).
 */
function afficher_voisins(): void {
	$id = contenu_seo();
	if ( $id ) {
		echo balises_voisins( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé à la construction.
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\afficher_voisins', 9 );

/**
 * Affiche dans <head> le JSON-LD de l'œuvre, du tome ou du chapitre affiché.
 */
function afficher_jsonld(): void {
	$id = contenu_seo();
	if ( $id ) {
		echo balise_jsonld( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encodé avec JSON_HEX_TAG.
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\afficher_jsonld', 20 );
