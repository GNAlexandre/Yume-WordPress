<?php
/**
 * Rendu des blocs de la fiche d'une œuvre et de la page d'un tome : yume/oeuvre-header,
 * yume/oeuvre-infos, yume/tome-list, yume/tome-header et yume/tome-toc.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Éléments communs aux fiches
 * -----------------------------------------------------------------------------
 */

/**
 * Contenu d'une fiche (synopsis de l'œuvre, présentation du tome) rendu comme the_content,
 * sans codes courts ni contenus embarqués, avec une garde contre la récursion (un bloc de
 * fiche placé dans le contenu qu'il affiche).
 *
 * @param int $post_id ID.
 */
function contenu_fiche( int $post_id ): string {
	static $en_cours = array();
	$post            = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || isset( $en_cours[ $post_id ] ) || post_password_required( $post ) ) {
		return '';
	}
	$brut = trim( (string) $post->post_content );
	if ( '' === $brut ) {
		$extrait = trim( (string) $post->post_excerpt );
		return '' === $extrait ? '' : wpautop( esc_html( $extrait ) );
	}
	$en_cours[ $post_id ] = true;
	$html                 = has_blocks( $brut ) ? do_blocks( $brut ) : wpautop( $brut );
	$html                 = wp_filter_content_tags( convert_chars( wptexturize( $html ) ), 'the_content' );
	unset( $en_cours[ $post_id ] );
	return trim( $html );
}

/**
 * Fond d'en-tête (décoratif) : ambiance de couleurs tirée de la couverture, très floutée
 * (bibliotheque.css), ou chaîne vide. La bannière des œuvres n'est plus affichée : c'était souvent
 * un logo avec du texte, illisible et chargé derrière la fiche.
 *
 * @param int $oeuvre_id     ID de l'œuvre.
 * @param int $couverture_id Couverture à utiliser (celle d'un tome) ; 0 : celle de l'œuvre.
 */
function banniere_oeuvre( int $oeuvre_id, int $couverture_id = 0 ): string {
	$image_id = $couverture_id ? $couverture_id : yume_get_cover_id( $oeuvre_id );
	if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
		return '';
	}
	// Petite taille : l'image est floutée, sa définition ne se voit pas.
	$image = (string) wp_get_attachment_image(
		$image_id,
		'medium',
		false,
		array(
			'class'    => 'yn-fiche-banniere__image',
			'alt'      => '',
			'loading'  => 'eager',
			'decoding' => 'async',
		)
	);
	return '' === $image ? '' : '<div class="yn-fiche-banniere yn-fiche-banniere--ambiance" aria-hidden="true">' . $image . '</div>';
}

/**
 * Texte en japonais, chinois ou coréen ? (attribut lang pour les lecteurs d'écran et la police).
 *
 * @param string $texte Texte.
 */
function langue_texte( string $texte ): string {
	if ( preg_match( '/[\p{Hiragana}\p{Katakana}]/u', $texte ) ) {
		return 'ja';
	}
	if ( preg_match( '/\p{Hangul}/u', $texte ) ) {
		return 'ko';
	}
	if ( preg_match( '/\p{Han}/u', $texte ) ) {
		return 'ja';
	}
	return '';
}

/**
 * Libellé d'un statut sur la fiche : « Traduction en cours »…
 *
 * @param string $slug Slug.
 * @param string $nom  Nom du terme.
 */
function libelle_statut_fiche( string $slug, string $nom ): string {
	$libelles = array(
		'en-cours' => __( 'Traduction en cours', 'yume-core' ),
		'terminee' => __( 'Traduction terminée', 'yume-core' ),
		'en-pause' => __( 'Traduction en pause', 'yume-core' ),
	);
	return $libelles[ $slug ] ?? $nom;
}

/**
 * Termes d'une taxonomie pour une œuvre (slug => nom).
 *
 * @param int    $oeuvre_id ID.
 * @param string $taxonomie Taxonomie.
 * @return array<string,string>
 */
function termes_oeuvre( int $oeuvre_id, string $taxonomie ): array {
	$termes = get_the_terms( $oeuvre_id, $taxonomie );
	$liste  = array();
	if ( is_array( $termes ) ) {
		foreach ( $termes as $terme ) {
			$liste[ (string) $terme->slug ] = html_entity_decode( (string) $terme->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	return $liste;
}

/**
 * Élément de fiche technique (<div><dt/><dd/></div>).
 *
 * @param string $terme  Intitulé.
 * @param string $valeur Valeur (HTML sûr).
 */
function element_fiche( string $terme, string $valeur ): string {
	return '<div class="yn-fiche-technique__item"><dt class="yn-label">' . esc_html( $terme ) . '</dt><dd>' . $valeur . '</dd></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Parution d'un tome (publication chapitre par chapitre)
 * -----------------------------------------------------------------------------
 */

/**
 * Nombre de chapitres prévus pour le tome (méta yume_chapitres_prevus), 0 si inconnu.
 *
 * @param int $tome_id Tome.
 */
function chapitres_prevus( int $tome_id ): int {
	return max( 0, (int) get_post_meta( $tome_id, 'yume_chapitres_prevus', true ) );
}

/**
 * Avancement d'un tome en cours : « 3 chapitres sur 12 » (tous les chapitres publiés, prologue
 * et épilogue compris, comme les chapitres prévus) ; vide si le nombre prévu est inconnu.
 *
 * @param array $stats  Statistiques du tome (stats_tome()).
 * @param int   $prevus Chapitres prévus.
 */
function texte_avancement( array $stats, int $prevus ): string {
	if ( $prevus <= 0 ) {
		return '';
	}
	$publies = (int) ( $stats['publies'] ?? 0 );
	/* translators: 1 : chapitres en ligne, 2 : chapitres prévus. */
	return sprintf( $publies > 1 ? __( '%1$s chapitres sur %2$s', 'yume-core' ) : __( '%1$s chapitre sur %2$s', 'yume-core' ), nombre_fr( $publies ), nombre_fr( max( $prevus, $publies ) ) );
}

/**
 * Rythme de sortie d'un tome (méta yume_rythme) : « Un nouveau chapitre chaque samedi à 18 h » ;
 * vide sans rythme.
 *
 * @param int $tome_id Tome.
 */
function texte_rythme( int $tome_id ): string {
	$rythme = get_post_meta( $tome_id, 'yume_rythme', true );
	$jour   = is_array( $rythme ) ? nom_jour( (string) ( $rythme['jour'] ?? '' ) ) : '';
	if ( '' === $jour ) {
		return '';
	}
	$heure = preg_match( '/^(\d{2}):(\d{2})$/', (string) ( $rythme['heure'] ?? '' ), $m ) ? heure_fr( (int) $m[1], (int) $m[2] ) : heure_fr( 18 );
	/* translators: 1 : jour de la semaine (« samedi »), 2 : heure (« 18 h »). */
	return sprintf( __( 'Un nouveau chapitre chaque %1$s à %2$s', 'yume-core' ), $jour, $heure );
}

/**
 * Le tome a-t-il au moins un lien de téléchargement (PDF ou EPUB) ?
 *
 * @param int $tome_id Tome.
 */
function a_telechargement( int $tome_id ): bool {
	if ( ! function_exists( 'yume_liens_telechargement' ) ) {
		return false;
	}
	foreach ( yume_liens_telechargement( $tome_id ) as $url ) {
		if ( est_url_http( (string) $url ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Mention qui remplace les boutons PDF / EPUB d'un tome en cours qui n'en a pas encore : courte
 * (« PDF et EPUB quand le tome sera complet ») ou longue (« PDF et EPUB seront proposés quand les
 * 12 chapitres seront en ligne. »). Vide pour un tome complet ou qui a déjà un lien.
 *
 * @param int    $tome_id Tome.
 * @param array  $stats   Statistiques du tome.
 * @param string $classe  Classe de l'élément.
 * @param bool   $longue  Forme longue (page du tome).
 */
function mention_telechargement( int $tome_id, array $stats, string $classe, bool $longue = false ): string {
	if ( empty( $stats['en_cours'] ) || a_telechargement( $tome_id ) ) {
		return '';
	}
	$prevus = chapitres_prevus( $tome_id );
	if ( ! $longue ) {
		$texte = __( 'PDF et EPUB quand le tome sera complet', 'yume-core' );
	} elseif ( $prevus > 1 ) {
		/* translators: %s : nombre de chapitres prévus. */
		$texte = sprintf( __( 'PDF et EPUB seront proposés quand les %s chapitres seront en ligne.', 'yume-core' ), nombre_fr( $prevus ) );
	} else {
		$texte = __( 'PDF et EPUB seront proposés quand le tome sera complet.', 'yume-core' );
	}
	$balise = $longue ? 'p' : 'span';
	return '<' . $balise . ' class="' . esc_attr( $classe . ' yn-muted' ) . '">' . esc_html( $texte ) . '</' . $balise . '>';
}

/**
 * Prochain chapitre programmé d'un tome en cours : balise <time> de sa date (« samedi 11 oct. »),
 * vide s'il n'y en a pas ou si sa date est passée (publication imminente).
 *
 * @param array $stats Statistiques du tome.
 * @param bool  $heure Afficher l'heure.
 */
function date_prochain_chapitre( array $stats, bool $heure = false ): string {
	$ts = (int) ( $stats['prochain'] ?? 0 );
	return ! empty( $stats['en_cours'] ) && $ts > time() ? balise_date_jour( $ts, $heure ) : '';
}

/*
 * -----------------------------------------------------------------------------
 * yume/oeuvre-header
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu de yume/oeuvre-header : bannière, fil d'Ariane, couverture, pastilles, titre,
 * titres alternatifs, fiche technique et synopsis.
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_oeuvre_header( array $attributs = array(), $bloc = null ): string {
	$oeuvre_id = oeuvre_contexte( $bloc );
	if ( ! $oeuvre_id ) {
		return rendu_sans_contexte( 'yn-oeuvre-header', __( 'En-tête de l’œuvre : visible sur la fiche d’une œuvre.', 'yume-core' ) );
	}
	$titre   = titre( $oeuvre_id );
	$types   = termes_oeuvre( $oeuvre_id, TAX_TYPE );
	$statuts = termes_oeuvre( $oeuvre_id, TAX_STATUT );
	$genres  = termes_oeuvre( $oeuvre_id, TAX_GENRE );

	// Fil d'Ariane : Bibliothèque › Type › Œuvre.
	$ariane = array(
		array(
			'texte' => __( 'Bibliothèque', 'yume-core' ),
			'url'   => url_bibliotheque(),
		),
	);
	if ( $types ) {
		$slug     = (string) array_key_first( $types );
		$ariane[] = array(
			'texte' => libelle_type_pluriel( $slug, $types[ $slug ] ),
			'url'   => url_bibliotheque( array( 'type' => $slug ) ),
		);
	}
	$ariane[] = array( 'texte' => $titre );

	// Pastilles : type, statut, genres (liens vers la bibliothèque filtrée).
	$pastilles = '';
	foreach ( $types as $nom ) {
		$pastilles .= pastille( $nom, 'info' );
	}
	foreach ( $statuts as $slug => $nom ) {
		$pastilles .= pastille_statut( (string) $slug, libelle_statut_fiche( (string) $slug, $nom ) );
	}
	if ( $genres ) {
		$liens = array();
		foreach ( $genres as $slug => $nom ) {
			$liens[] = '<a href="' . esc_url( url_bibliotheque( array( 'genre' => (string) $slug ) ) ) . '">' . esc_html( $nom ) . '</a>';
		}
		$pastilles .= '<span class="yn-chip yn-chip--info yn-oeuvre-header__genres"><span class="yn-visually-hidden">' . esc_html__( 'Genres :', 'yume-core' ) . ' </span>' . implode( '<span aria-hidden="true"> · </span><span class="yn-visually-hidden">, </span>', $liens ) . '</span>';
	}

	// Titres alternatifs.
	$alternatifs = array();
	foreach ( (array) get_post_meta( $oeuvre_id, 'yume_titres_alt', true ) as $alt ) {
		$alt = is_scalar( $alt ) ? trim( wp_strip_all_tags( (string) $alt ) ) : '';
		if ( '' !== $alt && $alt !== $titre ) {
			$langue        = langue_texte( $alt );
			$alternatifs[] = '<span' . ( '' !== $langue ? ' lang="' . esc_attr( $langue ) . '"' : '' ) . '>' . esc_html( $alt ) . '</span>';
		}
	}

	// Fiche technique.
	$fiche  = '';
	$auteur = meta_texte( $oeuvre_id, 'yume_auteur' );
	if ( '' !== $auteur ) {
		$fiche .= element_fiche( __( 'Scénario', 'yume-core' ), esc_html( $auteur ) );
	}
	$illustrateur = meta_texte( $oeuvre_id, 'yume_illustrateur' );
	if ( '' !== $illustrateur ) {
		$fiche .= element_fiche( __( 'Illustrations', 'yume-core' ), esc_html( $illustrateur ) );
	}
	$editeur = meta_texte( $oeuvre_id, 'yume_editeur_vo' );
	$vo      = array();
	if ( '' !== $editeur ) {
		$vo[] = $editeur;
	}
	$nb_vo     = (int) get_post_meta( $oeuvre_id, 'yume_nb_tomes_vo', true );
	$statut_vo = (string) get_post_meta( $oeuvre_id, 'yume_statut_vo', true );
	if ( $nb_vo > 0 ) {
		/* translators: %s : nombre de tomes parus en VO. */
		$texte = sprintf( $nb_vo > 1 ? __( '%s tomes', 'yume-core' ) : __( '%s tome', 'yume-core' ), nombre_fr( $nb_vo ) );
		if ( 'en_cours' === $statut_vo ) {
			$texte .= ' ' . __( '(en cours)', 'yume-core' );
		} elseif ( 'termine' === $statut_vo ) {
			$texte .= ' ' . __( '(terminé)', 'yume-core' );
		}
		$vo[] = $texte;
	}
	if ( $vo ) {
		$fiche .= element_fiche( __( 'Éditeur VO', 'yume-core' ), esc_html( implode( ' · ', $vo ) ) );
	}
	$index    = index_oeuvres()['oeuvres'];
	$traduits = isset( $index[ $oeuvre_id ] ) ? resume_tomes( (array) $index[ $oeuvre_id ]['tomes'] ) : '';
	$jours    = array_values( array_intersect_key( function_exists( 'yume_jours_semaine' ) ? yume_jours_semaine() : array(), array_flip( array_map( 'strval', (array) get_post_meta( $oeuvre_id, 'yume_jours_sortie', true ) ) ) ) );
	$traduit  = array();
	if ( '' !== $traduits ) {
		$traduit[] = $traduits;
	}
	if ( $jours ) {
		/* translators: %s : jours de sortie (« mercredi, samedi »). */
		$traduit[] = sprintf( __( 'sorties : %s', 'yume-core' ), mb_strtolower( implode( ', ', $jours ) ) );
	}
	if ( $traduit ) {
		$fiche .= element_fiche( __( 'Traduit', 'yume-core' ), esc_html( implode( ' · ', $traduit ) ) );
	}

	$image_id = (int) get_post_thumbnail_id( $oeuvre_id );
	$cover    = couverture(
		$image_id,
		array(
			'alt'        => alt_couverture( $image_id, $titre ),
			'texte'      => $titre,
			'chargement' => 'eager',
			'priorite'   => true,
			'sizes'      => '(min-width: 700px) 220px, 132px',
		)
	);

	$synopsis = contenu_fiche( $oeuvre_id );

	$html  = '<div ' . attributs_racine( 'yn-oeuvre-header' ) . '>';
	$html .= banniere_oeuvre( $oeuvre_id );
	$html .= fil_ariane( $ariane, 'yn-oeuvre-header__ariane' );
	$html .= '<div class="yn-oeuvre-header__couverture">' . $cover . '</div>';
	$html .= '<div class="yn-oeuvre-header__tete">';
	if ( '' !== $pastilles ) {
		$html .= '<div class="yn-oeuvre-header__pastilles">' . $pastilles . '</div>';
	}
	$html .= '<h1 class="yn-oeuvre-header__titre">' . esc_html( $titre ) . '</h1>';
	if ( $alternatifs ) {
		$html .= '<p class="yn-oeuvre-header__alternatifs yn-muted"><span class="yn-visually-hidden">' . esc_html__( 'Autres titres :', 'yume-core' ) . ' </span>' . implode( '<span aria-hidden="true"> · </span><span class="yn-visually-hidden">, </span>', $alternatifs ) . '</p>';
	}
	$html .= '</div>';
	if ( '' !== $fiche || '' !== $synopsis ) {
		$html .= '<div class="yn-oeuvre-header__details">';
		if ( '' !== $fiche ) {
			$html .= '<dl class="yn-fiche-technique yn-oeuvre-header__fiche">' . $fiche . '</dl>';
		}
		if ( '' !== $synopsis ) {
			$html .= '<div class="yn-oeuvre-header__synopsis">' . $synopsis . '</div>';
		}
		$html .= '</div>';
	}
	return $html . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/oeuvre-infos
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu de yume/oeuvre-infos : cartes « Équipe de traduction » et « Liens ».
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_oeuvre_infos( array $attributs = array(), $bloc = null ): string {
	$oeuvre_id = oeuvre_contexte( $bloc );
	if ( ! $oeuvre_id ) {
		return rendu_sans_contexte( 'yn-oeuvre-infos', __( 'Équipe de traduction et liens : visibles sur la fiche d’une œuvre.', 'yume-core' ) );
	}
	$roles   = roles_credits();
	$equipe  = credits( $oeuvre_id, 'yume_equipe' );
	$membres = array();
	foreach ( $equipe as $role => $noms ) {
		$membres[ $noms ][] = mb_strtolower( $roles[ $role ] ?? $role );
	}
	$source = meta_texte( $oeuvre_id, 'yume_source_traduction' );

	$liens = array();
	foreach ( (array) get_post_meta( $oeuvre_id, 'yume_liens', true ) as $lien ) {
		$url     = is_array( $lien ) && isset( $lien['url'] ) && is_string( $lien['url'] ) ? trim( $lien['url'] ) : '';
		$libelle = is_array( $lien ) && isset( $lien['label'] ) && is_scalar( $lien['label'] ) ? trim( wp_strip_all_tags( (string) $lien['label'] ) ) : '';
		if ( ! est_url_http( $url ) ) {
			continue;
		}
		$liens[] = '<li><a href="' . esc_url( $url ) . '"' . attributs_lien( $url ) . '>' . esc_html( '' !== $libelle ? $libelle : (string) wp_parse_url( $url, PHP_URL_HOST ) ) . indication_externe( $url ) . '</a></li>';
	}

	if ( ! $membres && '' === $source && ! $liens ) {
		return rendu_sans_contexte( 'yn-oeuvre-infos', __( 'Aucune information d’équipe ni lien pour cette œuvre.', 'yume-core' ) );
	}

	$html = '';
	if ( $membres || '' !== $source ) {
		$id    = wp_unique_id( 'yn-equipe-' );
		$html .= '<section class="yn-card yn-oeuvre-infos__carte yn-oeuvre-infos__equipe" aria-labelledby="' . esc_attr( $id ) . '">';
		$html .= '<h2 class="yn-label yn-oeuvre-infos__titre" id="' . esc_attr( $id ) . '">' . esc_html__( 'Équipe de traduction', 'yume-core' ) . '</h2>';
		if ( $membres ) {
			$html .= '<ul class="yn-oeuvre-infos__membres">';
			foreach ( $membres as $noms => $fonctions ) {
				$html .= '<li><b class="yn-oeuvre-infos__nom">' . esc_html( (string) $noms ) . '</b> · ' . esc_html( implode( ', ', $fonctions ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		$note = '' !== $source
			/* translators: %s : source de la traduction (« Édition anglaise officielle (J-Novel Club) »). */
			? sprintf( __( 'Traduction depuis : %s.', 'yume-core' ), rtrim( $source, '.' ) ) . ' '
			: '';
		$html .= '<p class="yn-muted yn-oeuvre-infos__note">' . esc_html( $note . __( 'Fan-traduction à but non lucratif.', 'yume-core' ) ) . '</p>';
		$html .= '</section>';
	}
	if ( $liens ) {
		$id    = wp_unique_id( 'yn-liens-' );
		$html .= '<section class="yn-card yn-oeuvre-infos__carte yn-oeuvre-infos__liens" aria-labelledby="' . esc_attr( $id ) . '">';
		$html .= '<h2 class="yn-label yn-oeuvre-infos__titre" id="' . esc_attr( $id ) . '">' . esc_html__( 'Liens', 'yume-core' ) . '</h2>';
		$html .= '<ul class="yn-oeuvre-infos__liste">' . implode( '', $liens ) . '</ul>';
		$html .= '</section>';
	}
	return '<div ' . attributs_racine( 'yn-oeuvre-infos' ) . '>' . $html . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/tome-list
 * -----------------------------------------------------------------------------
 */

/**
 * Nature dominante d'une liste de tomes : nature commune, sinon 'volume'.
 *
 * @param \WP_Post[] $tomes Tomes.
 */
function nature_commune( array $tomes ): string {
	$natures = array();
	foreach ( $tomes as $tome ) {
		$nature                                       = (string) get_post_meta( $tome->ID, 'yume_nature', true );
		$natures[ '' === $nature ? 'tome' : $nature ] = true;
	}
	return 1 === count( $natures ) ? (string) array_key_first( $natures ) : 'volume';
}

/**
 * Noms d'une nature : [singulier, pluriel], avec majuscule.
 *
 * @param string $nature Nature ('tome', 'arc', 'ex', 'volume'…).
 * @return array{0:string,1:string}
 */
function noms_nature( string $nature ): array {
	$noms = array(
		'tome'      => array( __( 'Tome', 'yume-core' ), __( 'Tomes', 'yume-core' ) ),
		'arc'       => array( __( 'Arc', 'yume-core' ), __( 'Arcs', 'yume-core' ) ),
		'ex'        => array( __( 'Tome EX', 'yume-core' ), __( 'Tomes EX', 'yume-core' ) ),
		'chapitres' => array( __( 'Recueil', 'yume-core' ), __( 'Recueils', 'yume-core' ) ),
	);
	return $noms[ $nature ] ?? array( __( 'Volume', 'yume-core' ), __( 'Volumes', 'yume-core' ) );
}

/**
 * Ligne d'un tome dans la liste de la fiche.
 *
 * @param \WP_Post $tome  Tome.
 * @param string   $oeuvre Titre de l'œuvre.
 */
function ligne_tome( \WP_Post $tome, string $oeuvre ): string {
	$id         = (int) $tome->ID;
	$libelle    = libelle_tome( $id );
	$sous_titre = sous_titre_tome( $id );
	$stats      = stats_tome( $id );
	$contexte   = trim( $oeuvre . ', ' . $libelle, ', ' );
	$lien       = lien_public( $id );
	$image_id   = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $id ) : 0;
	$cover      = couverture(
		$image_id,
		array(
			'alt'   => '',
			'texte' => libelle_tome( $id, true ),
			'sizes' => '72px',
		)
	);

	// Tome en cours : « 3 chapitres sur 12 » quand le nombre prévu est connu.
	$en_cours    = ! empty( $stats['en_cours'] );
	$avancement  = $en_cours ? texte_avancement( $stats, chapitres_prevus( $id ) ) : '';
	$details     = array_filter(
		array(
			'' !== $avancement ? $avancement : resume_chapitres( $stats ),
			texte_mots( (int) $stats['mots'] ),
			duree_lecture( (int) $stats['minutes'] ),
		),
		'strlen'
	);
	$equivalence = meta_texte( $id, 'yume_equivalence' );
	$prochain    = date_prochain_chapitre( $stats );

	$nom = '' !== $lien ? '<a href="' . esc_url( $lien ) . '">' . esc_html( $libelle ) . '</a>' : esc_html( $libelle );
	if ( '' !== $sous_titre ) {
		$nom .= ' <span class="yn-tome-list__sous-titre">' . esc_html( $sous_titre ) . '</span>';
	}
	if ( $en_cours ) {
		$nom .= ' ' . pastille( yume_parutions()['en_cours'], 'ok', '●', 'yn-tome-list__en-cours' );
	}

	$ligne = array(
		'classes' => array( 'yn-tome-list__ligne' ),
		'nom'     => $nom,
		'details' => array_values( $details ),
		'lire'    => bouton_commencer( $id, (int) $stats['premier'], __( 'Lire en ligne', 'yume-core' ), $contexte ),
	);
	/**
	 * Filtre une ligne de la liste des tomes (ex. progression du lecteur : « Reprendre »,
	 * « Relire », « En cours · 41 % » de la maquette Oeuvre, fournis par un autre module).
	 *
	 * @param array $ligne   classes (string[]), nom (HTML sûr), details (string[] en texte brut),
	 *                       lire (HTML sûr du bouton de lecture, vide si aucun chapitre).
	 * @param int   $tome_id Tome.
	 * @param array $stats   Statistiques du tome (premier, dernier, chapitres, mots, minutes…).
	 */
	$ligne   = (array) apply_filters( 'yume_bibliotheque_ligne_tome', $ligne, $id, $stats );
	$classes = implode( ' ', array_map( 'sanitize_html_class', (array) ( $ligne['classes'] ?? array( 'yn-tome-list__ligne' ) ) ) );
	$nom     = wp_kses_post( (string) ( $ligne['nom'] ?? $nom ) );
	$details = array_filter( array_map( 'strval', (array) ( $ligne['details'] ?? array() ) ), 'strlen' );
	$actions = wp_kses_post( (string) ( $ligne['lire'] ?? '' ) ) . boutons_telechargement( $id, $contexte ) . mention_telechargement( $id, $stats, 'yn-tome-list__telechargement' );

	$html  = '<li class="' . esc_attr( $classes ) . '">';
	$html .= '' !== $lien ? '<a class="yn-tome-list__couverture" href="' . esc_url( $lien ) . '" tabindex="-1" aria-hidden="true">' . $cover . '</a>' : '<span class="yn-tome-list__couverture">' . $cover . '</span>';
	$html .= '<div class="yn-tome-list__infos"><h3 class="yn-tome-list__nom">' . $nom . '</h3>';
	if ( $details || '' !== $prochain ) {
		$texte = esc_html( implode( ' · ', $details ) );
		if ( '' !== $prochain ) {
			/* translators: %s : date du prochain chapitre (« samedi 11 oct. »). */
			$texte .= ( '' !== $texte ? ' · ' : '' ) . '<span class="yn-tome-list__prochain">' . sprintf( esc_html__( 'prochain chapitre %s', 'yume-core' ), $prochain ) . '</span>';
		}
		$html .= '<p class="yn-tome-list__details yn-muted">' . $texte . '</p>';
	}
	if ( '' !== $equivalence ) {
		$html .= '<p class="yn-tome-list__equivalence yn-muted">' . esc_html( $equivalence ) . '</p>';
	}
	$html .= '</div>';
	$html .= '<p class="yn-tome-list__date">' . ( $en_cours
		? '<span class="yn-visually-hidden">' . esc_html__( 'En ligne', 'yume-core' ) . ' </span>' . esc_html__( 'depuis le', 'yume-core' ) . ' '
		: '<span class="yn-visually-hidden">' . esc_html__( 'Publié le', 'yume-core' ) . ' </span>' ) . balise_date( horodatage( $tome ), true ) . '</p>';
	$html .= '<div class="yn-tome-list__actions">' . $actions . '</div>';
	return $html . '</li>';
}

/**
 * Rendu de yume/tome-list : tomes publiés de l'œuvre, du plus récent au plus ancien ; au-delà
 * de 6, les plus anciens sont repliés dans un <details>. Les tomes à paraître (brouillons,
 * programmés) n'y figurent pas : le bloc yume/oeuvre-planning du module planning les annonce.
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_tome_list( array $attributs = array(), $bloc = null ): string {
	$oeuvre_id = oeuvre_contexte( $bloc );
	if ( ! $oeuvre_id || ! function_exists( 'yume_get_tomes' ) ) {
		return rendu_sans_contexte( 'yn-tome-list', __( 'Liste des tomes : visible sur la fiche d’une œuvre.', 'yume-core' ) );
	}
	$tomes  = yume_get_tomes( $oeuvre_id, array( 'order' => 'DESC' ) );
	$oeuvre = titre( $oeuvre_id );
	$nature = nature_commune( $tomes );
	$noms   = noms_nature( $tomes ? $nature : 'tome' );
	$id     = wp_unique_id( 'yn-tomes-' );

	$html  = '<section ' . attributs_racine( 'yn-tome-list', array( 'aria-labelledby' => $id ) ) . '>';
	$html .= '<div class="yn-tome-list__onglets"><h2 class="yn-tome-list__titre" id="' . esc_attr( $id ) . '">' . esc_html( $noms[1] ) . ' <span class="yn-tome-list__nombre">(' . esc_html( nombre_fr( count( $tomes ) ) ) . ')</span></h2></div>';

	if ( ! $tomes ) {
		$html .= '<p class="yn-muted yn-tome-list__vide">' . esc_html__( 'Aucun tome publié pour le moment.', 'yume-core' ) . '</p>';
		return $html . '</section>';
	}

	// Couvertures et premiers chapitres (boutons « Lire en ligne ») chargés en une fois.
	$stats_oeuvre = stats_oeuvre( $oeuvre_id );
	$a_charger    = array( $oeuvre_id );
	foreach ( $tomes as $tome ) {
		$a_charger[] = (int) ( $stats_oeuvre[ (int) $tome->ID ]['premier'] ?? 0 );
	}
	amorcer_caches( array_merge( $a_charger, wp_list_pluck( $tomes, 'ID' ) ) );

	$visibles = count( $tomes ) > 6 ? array_slice( $tomes, 0, 5 ) : $tomes;
	$anciens  = count( $tomes ) > 6 ? array_slice( $tomes, 5 ) : array();

	$html .= '<div class="yn-card yn-tome-list__carte">';
	$html .= '<div class="yn-tome-list__entete yn-label" aria-hidden="true"><span></span><span>' . esc_html( $noms[0] ) . '</span><span>' . esc_html__( 'Publié le', 'yume-core' ) . '</span><span>' . esc_html__( 'Lire · télécharger', 'yume-core' ) . '</span></div>';
	$html .= '<ol class="yn-tome-list__liste">';
	foreach ( $visibles as $tome ) {
		$html .= ligne_tome( $tome, $oeuvre );
	}
	$html .= '</ol>';

	if ( $anciens ) {
		$nature_anciens = nature_commune( $anciens );
		$noms_anciens   = noms_nature( $nature_anciens );
		$numeros        = array_filter( array_map( static fn( \WP_Post $t ) => get_post_meta( $t->ID, 'yume_numero', true ), $anciens ), 'is_numeric' );
		$plus_ancien    = end( $anciens );
		if ( 'volume' !== $nature_anciens && count( $numeros ) === count( $anciens ) ) {
			$min = (float) min( $numeros );
			$max = (float) max( $numeros );
			/* translators: 1 : « Tomes », 2 : premier numéro, 3 : dernier numéro. */
			$titre_groupe = sprintf( __( '%1$s %2$s à %3$s', 'yume-core' ), $noms_anciens[1], str_replace( '.', ',', (string) $min ), str_replace( '.', ',', (string) $max ) );
			$couv_groupe  = libelle_tome( (int) $plus_ancien->ID, true ) . '–' . str_replace( '.', ',', (string) $max );
		} else {
			/* translators: %s : « Volumes ». */
			$titre_groupe = sprintf( __( '%s précédents', 'yume-core' ), $noms_anciens[1] );
			$couv_groupe  = '…';
		}
		$nombre  = count( $anciens );
		$pluriel = mb_strtolower( $noms_anciens[1] );
		// Une fois ouverte, la ligne « Tomes 1 à 4 » disparaît (style.css) : les tomes précédents
		// prennent sa place ; le script tomes-anciens.js y déplace le focus.
		if ( wp_script_is( 'yume-tomes-anciens', 'registered' ) ) {
			wp_enqueue_script( 'yume-tomes-anciens' );
		}
		$html .= '<details class="yn-tome-list__anciens">';
		$html .= '<summary class="yn-tome-list__ligne yn-tome-list__resume">';
		$html .= '<span class="yn-tome-list__couverture">' . couverture( 0, array( 'texte' => $couv_groupe ) ) . '</span>';
		$html .= '<span class="yn-tome-list__infos"><span class="yn-tome-list__nom">' . esc_html( $titre_groupe ) . '</span>';
		/* translators: 1 : nombre, 2 : « tomes ». */
		$html .= '<span class="yn-tome-list__details yn-muted yn-tome-list__afficher">' . esc_html( sprintf( __( 'Afficher les %1$s %2$s précédents', 'yume-core' ), nombre_fr( $nombre ), $pluriel ) ) . '</span>';
		$html .= '</span><span class="yn-tome-list__date"></span>';
		$html .= '<span class="yn-tome-list__actions"><span class="yn-btn yn-btn--sm" aria-hidden="true">' . esc_html__( 'Afficher', 'yume-core' ) . '</span></span>';
		$html .= '</summary>';
		$html .= '<ol class="yn-tome-list__liste">';
		foreach ( $anciens as $tome ) {
			$html .= ligne_tome( $tome, $oeuvre );
		}
		$html .= '</ol></details>';
	}
	$html .= '</div>';
	return $html . '</section>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/tome-header
 * -----------------------------------------------------------------------------
 */

/**
 * Légende d'une illustration (texte brut, vide si aucune).
 *
 * @param int $image_id Pièce jointe.
 */
function legende_illustration( int $image_id ): string {
	return trim( wp_strip_all_tags( (string) wp_get_attachment_caption( $image_id ) ) );
}

/**
 * Texte alternatif d'une illustration : celui de la médiathèque, sinon « Illustration N — Œuvre, Tome 9 ».
 *
 * @param int    $image_id Pièce jointe.
 * @param int    $numero   Rang de l'illustration (à partir de 1).
 * @param string $contexte Œuvre et tome.
 */
function alt_illustration( int $image_id, int $numero, string $contexte ): string {
	$alt = trim( wp_strip_all_tags( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) );
	if ( '' === $alt ) {
		/* translators: 1 : numéro de l'illustration, 2 : œuvre et tome. */
		$alt = sprintf( __( 'Illustration %1$d — %2$s', 'yume-core' ), $numero, $contexte );
	}
	return $alt;
}

/**
 * Le membre connecté a-t-il une position de lecture enregistrée dans ce tome ? (Un visiteur
 * n'a de position que dans son navigateur : voir bouton_commencer().)
 *
 * @param int $tome_id Tome.
 */
function lecture_dans_tome( int $tome_id ): bool {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || ! function_exists( 'yume_get_progression' ) || ! function_exists( 'yume_get_oeuvre_id' ) ) {
		return false;
	}
	foreach ( yume_get_progression( $user_id, yume_get_oeuvre_id( $tome_id ) ) as $ligne ) {
		if ( (int) ( $ligne['tome_id'] ?? 0 ) === $tome_id ) {
			return true;
		}
	}
	return false;
}

/**
 * Bouton « Commencer la lecture » / « Lire en ligne » d'un tome : il ouvre la page
 * Illustrations quand le tome en a une et que le lecteur n'a pas encore de position dans ce
 * tome, sinon le premier chapitre. Pour un visiteur (position dans localStorage), le script
 * debut-lecture.js ramène le lien au premier chapitre si yn.progression désigne ce tome.
 *
 * @param int    $tome_id   Tome.
 * @param int    $premier   Premier chapitre publié du tome.
 * @param string $texte     Texte visible.
 * @param string $precision Précision pour les lecteurs d'écran.
 * @param bool   $petit     Bouton compact.
 */
function bouton_commencer( int $tome_id, int $premier, string $texte, string $precision = '', bool $petit = true ): string {
	$chapitre      = $premier ? lien_public( $premier ) : '';
	$illustrations = '' !== $chapitre && function_exists( 'yume_url_illustrations' ) ? yume_url_illustrations( $tome_id ) : '';
	if ( '' === $illustrations || lecture_dans_tome( $tome_id ) ) {
		return '' !== $chapitre ? bouton_lire_url( $chapitre, $texte, $precision, $petit ) : '';
	}
	if ( wp_script_is( 'yume-debut-lecture', 'registered' ) ) {
		wp_enqueue_script( 'yume-debut-lecture' );
	}
	return bouton_lire_url(
		$illustrations,
		$texte,
		$precision,
		$petit,
		array(
			'data-yn-debut-chapitre' => $chapitre,
			'data-yn-debut-tome'     => (string) $tome_id,
			'data-yn-debut-oeuvre'   => (string) ( function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0 ),
		)
	);
}

/**
 * Galerie des illustrations d'un tome (légendes, lien vers l'image en grand).
 *
 * @param int    $tome_id  ID du tome.
 * @param string $contexte Œuvre et tome (textes alternatifs).
 */
function galerie_tome( int $tome_id, string $contexte ): string {
	$ids = yume_illustrations_tome( $tome_id );
	if ( ! $ids ) {
		return '';
	}
	$items = '';
	foreach ( $ids as $rang => $image_id ) {
		$numero  = $rang + 1;
		$legende = legende_illustration( $image_id );
		$alt     = alt_illustration( $image_id, $numero, $contexte );
		$grande  = wp_get_attachment_image_url( $image_id, 'full' );
		$image   = (string) wp_get_attachment_image(
			$image_id,
			'medium_large',
			false,
			array(
				'class'   => 'yn-galerie__image',
				'alt'     => $alt,
				'loading' => 'lazy',
				'sizes'   => '(min-width: 1000px) 240px, 45vw',
			)
		);
		$items  .= '<li class="yn-galerie__item"><figure class="yn-galerie__figure">';
		$items  .= is_string( $grande ) && '' !== $grande
			? '<a class="yn-galerie__lien" href="' . esc_url( $grande ) . '">' . $image . '<span class="yn-visually-hidden"> ' . esc_html__( '(voir l’image en grand)', 'yume-core' ) . '</span></a>'
			: $image;
		/* translators: %d : numéro de l'illustration. */
		$items .= '<figcaption class="yn-galerie__legende">' . esc_html( '' !== $legende ? $legende : sprintf( __( 'Illustration %d', 'yume-core' ), $numero ) ) . '</figcaption>';
		$items .= '</figure></li>';
	}
	$id = wp_unique_id( 'yn-galerie-' );
	return '<section class="yn-galerie yn-tome-header__galerie" aria-labelledby="' . esc_attr( $id ) . '"><h2 class="yn-galerie__titre" id="' . esc_attr( $id ) . '">' . esc_html__( 'Illustrations', 'yume-core' ) . ' <span class="yn-muted">(' . esc_html( nombre_fr( count( $ids ) ) ) . ')</span></h2><ul class="yn-galerie__liste">' . $items . '</ul></section>';
}

/**
 * Rendu de yume/tome-header : couverture, titre, parution (« Tome en cours · 3 sur 12 »,
 * rythme de sortie), crédits, équivalence, lecture, PDF / EPUB (ou mention d'attente pour un
 * tome en cours), présentation et galerie d'illustrations.
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_tome_header( array $attributs = array(), $bloc = null ): string {
	$tome_id = tome_contexte( $bloc );
	if ( ! $tome_id ) {
		return rendu_sans_contexte( 'yn-tome-header', __( 'En-tête du tome : visible sur la page d’un tome.', 'yume-core' ) );
	}
	$oeuvre_id  = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
	$oeuvre     = titre( $oeuvre_id );
	$libelle    = libelle_tome( $tome_id );
	$sous_titre = sous_titre_tome( $tome_id );
	$stats      = stats_tome( $tome_id );
	$contexte   = trim( $oeuvre . ', ' . $libelle, ', ' );
	$lien_oeu   = $oeuvre_id ? lien_public( $oeuvre_id ) : '';

	$ariane = array(
		array(
			'texte' => __( 'Bibliothèque', 'yume-core' ),
			'url'   => url_bibliotheque(),
		),
	);
	if ( $oeuvre_id ) {
		$ariane[] = array(
			'texte' => $oeuvre,
			'url'   => $lien_oeu,
		);
	}
	$ariane[] = array( 'texte' => $libelle );

	$pastilles = '';
	$types     = $oeuvre_id ? termes_oeuvre( $oeuvre_id, TAX_TYPE ) : array();
	foreach ( $types as $nom ) {
		$pastilles .= pastille( $nom, 'info' );
	}
	$en_cours = ! empty( $stats['en_cours'] );
	$prevus   = $en_cours ? chapitres_prevus( $tome_id ) : 0;
	if ( $en_cours ) {
		$etat = libelle_en_cours( $tome_id );
		if ( $prevus > 0 ) {
			/* translators: 1 : « Tome en cours », 2 : chapitres en ligne, 3 : chapitres prévus. */
			$etat = sprintf( __( '%1$s · %2$s sur %3$s', 'yume-core' ), $etat, nombre_fr( (int) $stats['publies'] ), nombre_fr( max( $prevus, (int) $stats['publies'] ) ) );
		}
		$pastilles .= pastille( $etat, 'ok', '●', 'yn-tome-header__parution' );
	} elseif ( 'publish' !== get_post_status( $tome_id ) || 'a_paraitre' === ( $stats['parution'] ?? '' ) ) {
		$pastilles .= pastille( yume_parutions()['a_paraitre'], 'warn', '▲', 'yn-tome-header__parution' );
	}

	$details = array_filter(
		array(
			resume_chapitres( $stats ),
			texte_mots( (int) $stats['mots'] ),
			duree_lecture( (int) $stats['minutes'] ),
		),
		'strlen'
	);
	$date    = 'publish' === get_post_status( $tome_id ) ? balise_date( horodatage( $tome_id ), true ) : '';
	$rythme  = $en_cours ? texte_rythme( $tome_id ) : '';

	$credits = '';
	$roles   = roles_credits();
	foreach ( credits_herites( $tome_id ) as $role => $noms ) {
		$credits .= element_fiche( $roles[ $role ] ?? $role, esc_html( $noms ) );
	}

	$image_id = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $tome_id ) : 0;
	$cover    = couverture(
		$image_id,
		array(
			'alt'        => alt_couverture( $image_id, $contexte ),
			'texte'      => $oeuvre . ' · ' . libelle_tome( $tome_id, true ),
			'chargement' => 'eager',
			'priorite'   => true,
			'sizes'      => '(min-width: 700px) 220px, 132px',
		)
	);

	$lire = '';
	if ( ! empty( $stats['premier'] ) ) {
		$lire = bouton_commencer( $tome_id, (int) $stats['premier'], __( 'Commencer la lecture', 'yume-core' ), $contexte, false );
	}
	$actions = $lire . boutons_telechargement( $tome_id, $contexte, false );
	if ( '' !== $lien_oeu ) {
		$actions .= '<a class="yn-btn" href="' . esc_url( $lien_oeu ) . '">' . esc_html__( 'Fiche de l’œuvre', 'yume-core' ) . '</a>';
	}

	$equivalence  = meta_texte( $tome_id, 'yume_equivalence' );
	$presentation = contenu_fiche( $tome_id );

	$html  = '<div ' . attributs_racine( 'yn-tome-header' ) . '>';
	$html .= $oeuvre_id ? banniere_oeuvre( $oeuvre_id, yume_get_cover_id( $tome_id ) ) : '';
	$html .= fil_ariane( $ariane, 'yn-tome-header__ariane' );
	$html .= '<div class="yn-tome-header__couverture">' . $cover . '</div>';
	$html .= '<div class="yn-tome-header__tete">';
	if ( '' !== $pastilles ) {
		$html .= '<div class="yn-tome-header__pastilles">' . $pastilles . '</div>';
	}
	$html .= '<h1 class="yn-tome-header__titre">';
	if ( '' !== $oeuvre ) {
		$html .= '<span class="yn-tome-header__oeuvre">' . esc_html( $oeuvre ) . '</span><span class="yn-visually-hidden"> — </span>';
	}
	$html .= '<span class="yn-tome-header__libelle">' . esc_html( $libelle ) . '</span>';
	if ( '' !== $sous_titre ) {
		$html .= '<span class="yn-visually-hidden"> : </span><span class="yn-tome-header__sous-titre">' . esc_html( $sous_titre ) . '</span>';
	}
	$html .= '</h1>';
	if ( $details || '' !== $date ) {
		$ligne = esc_html( implode( ' · ', $details ) );
		if ( '' !== $date ) {
			$ligne .= ( '' !== $ligne ? ' · ' : '' ) . ( $en_cours
				/* translators: %s : date de mise en ligne du premier chapitre. */
				? sprintf( esc_html__( 'en ligne depuis le %s', 'yume-core' ), $date )
				/* translators: %s : date de publication. */
				: sprintf( esc_html__( 'publié le %s', 'yume-core' ), $date ) );
		}
		$html .= '<p class="yn-tome-header__details yn-muted">' . $ligne . '</p>';
	}
	if ( '' !== $rythme ) {
		$html .= '<p class="yn-tome-header__rythme">' . esc_html( $rythme ) . '</p>';
	}
	$html .= '</div>';
	$html .= '<div class="yn-tome-header__corps">';
	if ( '' !== $credits ) {
		$html .= '<dl class="yn-fiche-technique yn-tome-header__credits">' . $credits . '</dl>';
	}
	if ( '' !== $equivalence ) {
		$html .= '<p class="yn-tome-header__equivalence">' . esc_html( $equivalence ) . '</p>';
	}
	if ( '' !== $presentation ) {
		$html .= '<div class="yn-tome-header__presentation">' . $presentation . '</div>';
	}
	if ( '' !== $actions ) {
		$html .= '<div class="yn-tome-header__actions">' . $actions . '</div>';
	}
	$html .= mention_telechargement( $tome_id, $stats, 'yn-tome-header__telechargement', true );
	$html .= '</div>';
	$html .= galerie_tome( $tome_id, $contexte );
	return $html . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/tome-toc
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu de yume/tome-toc : sommaire du tome (chapitres publiés, sous-titres, temps de
 * lecture ; si le tome est en cours : chapitres planifiés « à venir », datés s'ils sont
 * programmés, et ligne « Chapitres N à M » pour les chapitres prévus pas encore créés).
 *
 * @param array          $attributs Attributs du bloc.
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_tome_toc( array $attributs = array(), $bloc = null ): string {
	$tome_id = tome_contexte( $bloc );
	if ( ! $tome_id || ! function_exists( 'yume_get_chapitres' ) ) {
		return rendu_sans_contexte( 'yn-toc', __( 'Sommaire du tome : visible sur la page d’un tome.', 'yume-core' ) );
	}
	$courant  = chapitre_contexte( $bloc );
	$stats    = stats_tome( $tome_id );
	$en_cours = ! empty( $stats['en_cours'] );
	$statuts  = $en_cours ? array( 'publish', 'future', 'draft', 'pending' ) : array( 'publish' );
	$liste    = yume_get_chapitres( $tome_id, array( 'status' => $statuts ) );
	$id       = wp_unique_id( 'yn-sommaire-' );

	$resume = array_filter(
		array(
			resume_chapitres( $stats ),
			duree_lecture( (int) $stats['minutes'] ),
		),
		'strlen'
	);
	// Chapitres prévus qui n'existent pas encore (ni programmés ni en brouillon).
	$prevus    = $en_cours ? chapitres_prevus( $tome_id ) : 0;
	$manquants = max( 0, $prevus - (int) $stats['publies'] - (int) $stats['a_venir'] );
	$a_venir   = (int) $stats['a_venir'] + $manquants;
	if ( $en_cours && $a_venir > 0 ) {
		/* translators: %s : nombre de chapitres à venir. */
		$resume[] = sprintf( __( '%s à venir', 'yume-core' ), nombre_fr( $a_venir ) );
	}

	$html  = '<nav ' . attributs_racine( 'yn-toc', array( 'aria-labelledby' => $id ) ) . '>';
	$html .= '<div class="yn-toc__tete"><h2 class="yn-toc__titre" id="' . esc_attr( $id ) . '">' . esc_html__( 'Sommaire', 'yume-core' ) . '</h2>';
	if ( $resume ) {
		$html .= '<p class="yn-toc__resume yn-muted">' . esc_html( implode( ' · ', $resume ) ) . '</p>';
	}
	$html .= '</div>';

	// Page Illustrations (planches avant le chapitre 1) : première entrée du sommaire.
	$illustrations = yume_url_illustrations( $tome_id );
	$entree_illus  = '';
	if ( '' !== $illustrations ) {
		$nombre       = count( yume_illustrations_tome( $tome_id ) );
		$actuel       = tome_illustrations_contexte( $bloc ) === $tome_id;
		$entree_illus = '<li class="yn-toc__item yn-toc__item--illustrations' . ( $actuel ? ' is-current' : '' ) . '"><a class="yn-toc__lien" href="' . esc_url( $illustrations ) . '"' . ( $actuel ? ' aria-current="page"' : '' ) . '>'
			. '<span class="yn-toc__numero">' . esc_html__( 'Illustrations', 'yume-core' ) . '</span>'
			/* translators: %s : nombre d'illustrations. */
			. '<span class="yn-toc__sous-titre">' . esc_html( sprintf( _n( '%s planche', '%s planches', $nombre, 'yume-core' ), nombre_fr( $nombre ) ) ) . '</span>'
			. '<span class="yn-toc__duree yn-muted"></span></a></li>';
	}

	if ( ! $liste ) {
		if ( '' !== $entree_illus ) {
			$html .= '<ol class="yn-card yn-toc__liste">' . $entree_illus . '</ol>';
		}
		$html .= '<p class="yn-card yn-toc__vide yn-muted">' . esc_html__( 'Aucun chapitre en ligne pour ce tome.', 'yume-core' ) . '</p>';
		return $html . '</nav>';
	}

	// Ligne « Chapitres 5 à 10 à venir », placée après le dernier chapitre ordinaire (avant un
	// épilogue ou une postface déjà prévus), numérotée à la suite du plus grand numéro.
	$ligne_prevus = '';
	$rang_prevus  = count( $liste ) - 1;
	if ( $manquants > 0 ) {
		$dernier_numero = 0;
		foreach ( $liste as $rang => $chapitre ) {
			$nature = (string) get_post_meta( $chapitre->ID, 'yume_nature', true );
			$numero = get_post_meta( $chapitre->ID, 'yume_numero', true );
			if ( ( '' === $nature || 'chapitre' === $nature ) && is_numeric( $numero ) ) {
				$dernier_numero = max( $dernier_numero, (int) floor( (float) $numero ) );
				$rang_prevus    = $rang;
			}
		}
		$debut = $dernier_numero + 1;
		$fin   = $dernier_numero + $manquants;
		/* translators: 1 : premier numéro, 2 : dernier numéro. */
		$numeros      = 1 === $manquants ? sprintf( __( 'Chapitre %s', 'yume-core' ), nombre_fr( $debut ) ) : sprintf( __( 'Chapitres %1$s à %2$s', 'yume-core' ), nombre_fr( $debut ), nombre_fr( $fin ) );
		$ligne_prevus = '<li class="yn-toc__item yn-toc__item--a-venir yn-toc__item--prevus"><span class="yn-toc__lien"><span class="yn-toc__numero">' . esc_html( $numeros ) . '</span>'
			. '<span class="yn-toc__sous-titre">' . esc_html__( 'à venir', 'yume-core' ) . '</span><span class="yn-toc__duree"></span></span></li>';
	}

	$html .= '<ol class="yn-card yn-toc__liste">' . $entree_illus;
	foreach ( $liste as $rang => $chapitre ) {
		$cid        = (int) $chapitre->ID;
		$libelle    = libelle_chapitre( $cid );
		$sous_titre = meta_texte( $cid, 'yume_sous_titre' );
		$contenu    = '<span class="yn-toc__numero">' . esc_html( $libelle ) . '</span>';
		$contenu   .= '<span class="yn-toc__sous-titre">' . ( '' !== $sous_titre ? esc_html( $sous_titre ) : '' ) . '</span>';
		if ( 'publish' === $chapitre->post_status ) {
			$minutes  = (int) get_post_meta( $cid, 'yume_temps_lecture', true );
			$contenu .= '<span class="yn-toc__duree yn-muted">' . ( $minutes > 0 ? '<span class="yn-visually-hidden">' . esc_html__( 'Temps de lecture :', 'yume-core' ) . ' </span>' . esc_html( duree_lecture( $minutes ) ) : '' ) . '</span>';
			$actuel   = $cid === $courant;
			$html    .= '<li class="yn-toc__item' . ( $actuel ? ' is-current' : '' ) . '"><a class="yn-toc__lien" href="' . esc_url( (string) get_permalink( $cid ) ) . '"' . ( $actuel ? ' aria-current="page"' : '' ) . '>' . $contenu . '</a></li>';
		} elseif ( 'future' === $chapitre->post_status ) {
			// Chapitre programmé : sa date et son heure de sortie (sans lien).
			$contenu .= '<span class="yn-toc__duree"><span class="yn-chip yn-chip--warn yn-toc__a-venir yn-toc__programme">'
				. '<span class="yn-visually-hidden">' . esc_html__( 'Prévu le', 'yume-core' ) . ' </span>' . balise_date_jour( horodatage( $chapitre ), true ) . '</span></span>';
			$html    .= '<li class="yn-toc__item yn-toc__item--a-venir"><span class="yn-toc__lien">' . $contenu . '</span></li>';
		} else {
			$contenu .= '<span class="yn-toc__duree">' . pastille( __( 'À venir', 'yume-core' ), 'info', '', 'yn-toc__a-venir' ) . '</span>';
			$html    .= '<li class="yn-toc__item yn-toc__item--a-venir"><span class="yn-toc__lien">' . $contenu . '</span></li>';
		}
		if ( $rang === $rang_prevus ) {
			$html .= $ligne_prevus;
		}
	}
	$html .= '</ol>';
	return $html . '</nav>';
}
