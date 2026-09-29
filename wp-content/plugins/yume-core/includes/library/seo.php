<?php
/**
 * Référencement : données structurées schema.org (JSON-LD) des fiches d'œuvre (BookSeries),
 * des tomes (Book) et des chapitres (Chapter, isPartOf), avec leur fil d'Ariane
 * (BreadcrumbList) ; balises <link rel="prev|next"> des chapitres ; meta description,
 * Open Graph et Twitter Card de toutes les pages publiques (celles de Jetpack sont retirées).
 *
 * Rien n'est émis pour un contenu non publié (aperçu de l'équipe), ni (Open Graph) sur les
 * pages privées (compte, espace équipe), les pages noindex (recherche, page Illustrations)
 * et les 404.
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

/*
 * -----------------------------------------------------------------------------
 * Open Graph, Twitter Card et meta description
 * -----------------------------------------------------------------------------
 */

/** Compte X (Twitter) du site pour twitter:site. */
const COMPTE_TWITTER = '@YumeNovel';

/** Pages Yume privées (option yume_pages) : ni aperçu de partage ni description. */
const PAGES_PRIVEES_SEO = array( 'equipe', 'publier', 'membres', 'compte', 'connexion' );

/** Blocs des pages privées (repli si les pages ne sont pas enregistrées dans yume_pages). */
const BLOCS_PRIVES_SEO = array( 'yume/account', 'yume/team-dashboard', 'yume/team-members', 'yume/publish-form' );

/**
 * La page est-elle une page privée (compte, connexion, espace équipe ou l'une de ses sous-pages) ?
 *
 * @param int $page_id Page.
 */
function page_privee_seo( int $page_id ): bool {
	if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) ) {
		return false;
	}
	$pages = get_option( 'yume_pages', array() );
	if ( is_array( $pages ) ) {
		$privees = array_filter( array_map( 'intval', array_intersect_key( $pages, array_flip( PAGES_PRIVEES_SEO ) ) ) );
		if ( array_intersect( array_merge( array( $page_id ), array_map( 'intval', get_post_ancestors( $page_id ) ) ), $privees ) ) {
			return true;
		}
	}
	foreach ( BLOCS_PRIVES_SEO as $bloc ) {
		if ( has_block( $bloc, $page_id ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Image par défaut des aperçus de partage : bannière du site (Yume → Réglages), sinon logo du
 * site, sinon icône du site. 0 si aucune.
 */
function image_defaut_seo(): int {
	$candidats = array(
		function_exists( 'yume_setting' ) ? absint( yume_setting( 'banniere_id', 0 ) ) : 0,
		absint( get_option( 'site_logo', 0 ) ),
		absint( get_theme_mod( 'custom_logo', 0 ) ),
		absint( get_option( 'site_icon', 0 ) ),
	);
	foreach ( $candidats as $image_id ) {
		if ( $image_id && wp_attachment_is_image( $image_id ) ) {
			return $image_id;
		}
	}
	return 0;
}

/**
 * Image d'aperçu d'un contenu : couverture (œuvre, tome, chapitre), image mise en avant (article,
 * page), sinon couverture de l'œuvre liée à un article, sinon image par défaut du site.
 *
 * @param int $post_id Contenu (0 : accueil et archives).
 */
function image_partage_id( int $post_id ): int {
	$image_id = 0;
	if ( $post_id > 0 ) {
		$type = get_post_type( $post_id );
		if ( in_array( $type, array( TYPE_OEUVRE, TYPE_TOME, TYPE_CHAPITRE ), true ) && function_exists( 'yume_get_cover_id' ) ) {
			$image_id = yume_get_cover_id( $post_id );
		} else {
			$image_id = (int) get_post_thumbnail_id( $post_id );
			if ( ! $image_id && 'post' === $type && function_exists( 'yume_get_oeuvre_id' ) && function_exists( 'yume_get_cover_id' ) ) {
				$oeuvre_id = yume_get_oeuvre_id( $post_id );
				$image_id  = $oeuvre_id && 'publish' === get_post_status( $oeuvre_id ) ? yume_get_cover_id( $oeuvre_id ) : 0;
			}
		}
	}
	return $image_id && wp_attachment_is_image( $image_id ) ? $image_id : image_defaut_seo();
}

/**
 * Balises og:image d'une image (adresse, dimensions, texte alternatif).
 *
 * @param int $image_id Pièce jointe.
 * @return array<string,string>
 */
function balises_image_og( int $image_id ): array {
	$source = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;
	if ( ! is_array( $source ) || empty( $source[0] ) ) {
		return array();
	}
	$balises = array( 'og:image' => (string) $source[0] );
	if ( ! empty( $source[1] ) && ! empty( $source[2] ) ) {
		$balises['og:image:width']  = (string) (int) $source[1];
		$balises['og:image:height'] = (string) (int) $source[2];
	}
	$alt = trim( wp_strip_all_tags( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) );
	if ( '' !== $alt ) {
		$balises['og:image:alt'] = $alt;
	}
	return $balises;
}

/**
 * Titre d'aperçu d'un contenu : « Chapitre 3 — Sous-titre · Œuvre — Tome 1 » pour un chapitre,
 * le titre sinon.
 *
 * @param int $post_id Contenu.
 */
function titre_partage( int $post_id ): string {
	if ( TYPE_CHAPITRE !== get_post_type( $post_id ) ) {
		return titre( $post_id );
	}
	$tome_id = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $post_id ) : 0;
	$tome    = $tome_id ? titre( $tome_id ) : '';
	return nom_chapitre_seo( $post_id ) . ( '' !== $tome ? ' · ' . $tome : '' );
}

/**
 * Contenu singulier public affiché par la requête principale, 0 si la page n'en est pas un, -1
 * si c'est un contenu à ne pas présenter (non publié, protégé par mot de passe, page privée,
 * page Illustrations noindex).
 */
function contenu_partage(): int {
	if ( ! is_singular() ) {
		return 0;
	}
	$post_id = (int) get_queried_object_id();
	$post    = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) || page_privee_seo( $post_id ) ) {
		return -1;
	}
	if ( function_exists( 'yume_est_page_illustrations' ) && yume_est_page_illustrations() ) {
		return -1;
	}
	return $post_id;
}

/**
 * Adresse canonique de la page affichée (sans paramètres de requête).
 *
 * @param int $post_id Contenu singulier (0 sinon).
 */
function url_partage( int $post_id ): string {
	if ( $post_id > 0 ) {
		$url = wp_get_canonical_url( $post_id );
		return is_string( $url ) ? $url : (string) get_permalink( $post_id );
	}
	if ( is_front_page() ) {
		return home_url( '/' );
	}
	if ( is_home() && get_option( 'page_for_posts' ) ) {
		return (string) get_permalink( (int) get_option( 'page_for_posts' ) );
	}
	$objet = get_queried_object();
	if ( $objet instanceof \WP_Term ) {
		$url = get_term_link( $objet );
		return is_string( $url ) ? $url : '';
	}
	if ( is_post_type_archive() ) {
		$type = get_query_var( 'post_type' );
		$url  = get_post_type_archive_link( is_array( $type ) ? (string) reset( $type ) : (string) $type );
		return is_string( $url ) ? $url : '';
	}
	global $wp;
	$chemin = isset( $wp->request ) ? (string) $wp->request : '';
	return home_url( user_trailingslashit( '/' . ltrim( $chemin, '/' ) ) );
}

/**
 * Balises meta de la page affichée : description, Open Graph (og:*, article:*) et Twitter Card.
 *
 * @return array<string,string> Propriété ou nom => contenu ; vide si rien ne doit être émis.
 */
function balises_open_graph(): array {
	if ( is_404() || is_search() || is_feed() || is_admin() ) {
		return array();
	}
	$post_id = contenu_partage();
	if ( $post_id < 0 ) {
		return array();
	}
	$site = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$type = 'website';
	if ( is_front_page() ) {
		$titre       = $site;
		$description = html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === trim( $description ) && $post_id > 0 ) {
			$description = description_seo( $post_id );
		}
	} elseif ( $post_id > 0 ) {
		$titre       = titre_partage( $post_id );
		$description = description_seo( $post_id );
		$type_post   = get_post_type( $post_id );
		if ( in_array( $type_post, array( TYPE_OEUVRE, TYPE_TOME ), true ) ) {
			$type = 'book';
		} elseif ( in_array( $type_post, array( TYPE_CHAPITRE, 'post' ), true ) ) {
			$type = 'article';
		}
		// Chapitre sans texte (ou tome sans résumé) : résumé de l'œuvre.
		if ( '' === $description && in_array( $type_post, array( TYPE_TOME, TYPE_CHAPITRE ), true ) && function_exists( 'yume_get_oeuvre_id' ) ) {
			$oeuvre_id   = yume_get_oeuvre_id( $post_id );
			$description = $oeuvre_id && 'publish' === get_post_status( $oeuvre_id ) ? description_seo( $oeuvre_id ) : '';
		}
	} else {
		$titre       = html_entity_decode( wp_strip_all_tags( wp_get_document_title() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = is_home() ? html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		if ( is_category() || is_tag() || is_tax() ) {
			$description = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( term_description() ) ) );
		}
	}
	$description = trim( $description );

	$balises = array( 'description' => $description );
	$balises = array_merge(
		$balises,
		array(
			'og:site_name'   => $site,
			'og:locale'      => 'fr_FR',
			'og:type'        => $type,
			'og:title'       => '' !== trim( $titre ) ? $titre : $site,
			'og:description' => $description,
			'og:url'         => url_partage( $post_id ),
		),
		balises_image_og( image_partage_id( $post_id ) )
	);
	if ( 'article' === $type && $post_id > 0 ) {
		$balises['article:published_time'] = (string) get_post_time( 'c', true, $post_id );
		$balises['article:modified_time']  = (string) get_post_modified_time( 'c', true, $post_id );
	}
	$balises['twitter:card'] = isset( $balises['og:image'] ) ? 'summary_large_image' : 'summary';
	$balises['twitter:site'] = COMPTE_TWITTER;
	if ( isset( $balises['og:image:alt'] ) ) {
		$balises['twitter:image:alt'] = $balises['og:image:alt'];
	}

	/**
	 * Filtre les balises meta de partage (description, Open Graph, Twitter Card) de la page
	 * affichée. Un tableau vide n'émet rien.
	 *
	 * @param array<string,string> $balises Propriété (og:*, article:*, twitter:*) ou « description » => contenu.
	 * @param int                  $post_id Contenu singulier affiché (0 : accueil, archive).
	 */
	$balises = (array) apply_filters( 'yume_open_graph', $balises, $post_id );
	return array_filter(
		array_map( static fn( $valeur ): string => is_scalar( $valeur ) ? trim( (string) $valeur ) : '', $balises ),
		'strlen'
	);
}

/**
 * Balises <meta> HTML d'un jeu de balises de partage.
 *
 * @param array<string,string> $balises Balises (balises_open_graph()).
 */
function html_open_graph( array $balises ): string {
	$html = '';
	foreach ( $balises as $cle => $valeur ) {
		$cle = (string) $cle;
		// Open Graph (og:, article:, book:) : attribut property ; description et twitter: : name.
		$attribut = preg_match( '/^(og|article|book|profile):/', $cle ) ? 'property' : 'name';
		$valeur   = in_array( $cle, array( 'og:url', 'og:image' ), true ) ? esc_url( $valeur ) : esc_attr( $valeur );
		$html    .= '<meta ' . $attribut . '="' . esc_attr( $cle ) . '" content="' . $valeur . "\" />\n";
	}
	return $html;
}

/**
 * Affiche dans <head> la meta description, l'Open Graph et la Twitter Card de la page.
 */
function afficher_open_graph(): void {
	echo html_open_graph( balises_open_graph() ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé à la construction.
}
add_action( 'wp_head', __NAMESPACE__ . '\\afficher_open_graph', 5 );

// Jetpack (WordPress.com) : ses balises Open Graph et Twitter Card feraient doublon.
add_filter( 'jetpack_enable_open_graph', '__return_false', 99 );
