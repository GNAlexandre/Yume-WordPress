<?php
/**
 * Rendu des blocs d'en-tête, d'accueil et de bibliothèque : yume/library-menu, yume/banner,
 * yume/latest-releases et yume/library-grid.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * yume/library-menu
 * -----------------------------------------------------------------------------
 */

/**
 * Ligne du menu Bibliothèque : libellé et nombre d'œuvres.
 *
 * @param string $url     Adresse.
 * @param string $libelle Libellé.
 * @param int    $nombre  Nombre d'œuvres publiées.
 */
function ligne_menu( string $url, string $libelle, int $nombre ): string {
	return sprintf(
		'<li class="yn-library-menu__item"><a class="yn-library-menu__lien" href="%1$s"><span class="yn-library-menu__nom">%2$s</span> <span class="yn-library-menu__nombre">%3$s<span class="yn-visually-hidden"> %4$s</span></span></a></li>',
		esc_url( $url ),
		esc_html( $libelle ),
		esc_html( nombre_fr( $nombre ) ),
		esc_html( $nombre > 1 ? __( 'œuvres', 'yume-core' ) : __( 'œuvre', 'yume-core' ) )
	);
}

/**
 * Rendu de yume/library-menu (bloc sans attribut) : <details> dont le <summary> est un
 * élément de navigation.
 */
function rendu_library_menu(): string {
	$oeuvres = index_oeuvres()['oeuvres'];
	$aucun   = normaliser_filtres( array() );

	$lignes_types = '';
	$par_type     = compter_par( $oeuvres, $aucun, 'type' );
	foreach ( types() as $slug => $nom ) {
		$nombre = (int) ( $par_type[ $slug ] ?? 0 );
		if ( $nombre > 0 ) {
			$lignes_types .= ligne_menu( url_bibliotheque( array( 'type' => $slug ) ), libelle_type_pluriel( (string) $slug, (string) $nom ), $nombre );
		}
	}

	$lignes_statuts = '';
	foreach ( groupes_statuts() as $valeur => $groupe ) {
		$nombre = compter_groupe_statuts( $oeuvres, $aucun, $groupe['statuts'] );
		if ( $nombre > 0 ) {
			$lignes_statuts .= ligne_menu( url_bibliotheque( array( 'statut' => (string) $valeur ) ), $groupe['libelle'], $nombre );
		}
	}

	$id        = wp_unique_id( 'yn-bibliotheque-' );
	$colonnes  = '';
	$colonnes .= '' !== $lignes_types ? '<ul class="yn-library-menu__liste" aria-label="' . esc_attr__( 'Par type', 'yume-core' ) . '">' . $lignes_types . '</ul>' : '';
	$colonnes .= '' !== $lignes_statuts ? '<ul class="yn-library-menu__liste" aria-label="' . esc_attr__( 'Par statut', 'yume-core' ) . '">' . $lignes_statuts . '</ul>' : '';

	$compte    = function_exists( 'yume_url_page' ) ? yume_url_page( 'compte' ) : home_url( '/compte/' );
	$reprendre = sprintf(
		'<a class="yn-library-menu__reprendre" href="%1$s"%2$s>%3$s</a>',
		esc_url( $compte ),
		is_user_logged_in() ? '' : ' data-yn-reprendre',
		esc_html__( 'Reprendre ma lecture', 'yume-core' )
	);
	$tout      = sprintf(
		'<a class="yn-library-menu__tout" href="%1$s">%2$s <span aria-hidden="true">→</span><span class="yn-visually-hidden">%3$s</span> %4$s</a>',
		esc_url( url_bibliotheque( array( 'tri' => 'az' ) ) ),
		esc_html__( 'Toutes les œuvres A', 'yume-core' ),
		esc_html__( 'à', 'yume-core' ),
		esc_html__( 'Z', 'yume-core' )
	);

	$html  = '<details ' . attributs_racine( 'yn-library-menu', array( 'data-yn-library-menu' => '' ) ) . '>';
	$html .= '<summary class="wp-block-navigation-item__content yn-library-menu__bouton"><span class="wp-block-navigation-item__label">' . esc_html__( 'Bibliothèque', 'yume-core' ) . '</span>' . icone( 'chevron', 14 ) . '</summary>';
	$html .= '<div class="yn-library-menu__panneau" id="' . esc_attr( $id ) . '">';
	$html .= '<p class="yn-label yn-library-menu__titre">' . esc_html__( 'Bibliothèque', 'yume-core' ) . '</p>';
	if ( '' !== $colonnes ) {
		$html .= '<div class="yn-library-menu__colonnes">' . $colonnes . '</div>';
	} else {
		$html .= '<p class="yn-muted yn-library-menu__vide">' . esc_html__( 'Aucune œuvre publiée pour le moment.', 'yume-core' ) . '</p>';
	}
	$html .= '<div class="yn-library-menu__pied">' . $tout . $reprendre . '</div>';
	$html .= '</div></details>';
	return $html;
}

/*
 * -----------------------------------------------------------------------------
 * yume/banner
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu de yume/banner : bannière actuelle du site (réglage banniere_id), pleine largeur.
 *
 * @param array $attributs Attributs du bloc (height).
 */
function rendu_banner( array $attributs = array() ): string {
	$image_id = function_exists( 'yume_setting' ) ? absint( yume_setting( 'banniere_id', 0 ) ) : 0;
	if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
		return rendu_sans_contexte( 'yn-banner', __( 'Bannière du site : choisissez une image dans Yume → Réglages.', 'yume-core' ) );
	}
	$hauteur = isset( $attributs['height'] ) && is_numeric( $attributs['height'] ) ? (int) $attributs['height'] : 240;
	$hauteur = max( 80, min( 800, $hauteur ) );
	$alt     = trim( wp_strip_all_tags( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) );
	$image   = (string) wp_get_attachment_image(
		$image_id,
		'full',
		false,
		array(
			'class'         => 'yn-banner__image',
			'alt'           => $alt,
			'loading'       => 'eager',
			'fetchpriority' => 'high',
			'decoding'      => 'async',
			'sizes'         => '100vw',
		)
	);
	if ( '' === $image ) {
		return '';
	}
	return '<div ' . attributs_racine( 'yn-banner', array( 'style' => '--yn-banner-hauteur:' . $hauteur . 'px' ) ) . '>' . $image . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/latest-releases
 * -----------------------------------------------------------------------------
 */

/**
 * Forme courte d'un chapitre pour une ligne de métadonnées : « ch. 8 », « épilogue ».
 *
 * @param int $chapitre_id ID du chapitre.
 */
function chapitre_court( int $chapitre_id ): string {
	$nature = (string) get_post_meta( $chapitre_id, 'yume_nature', true );
	$numero = get_post_meta( $chapitre_id, 'yume_numero', true );
	if ( ( '' === $nature || 'chapitre' === $nature ) && is_numeric( $numero ) ) {
		$texte = rtrim( rtrim( number_format( (float) $numero, 2, ',', '' ), '0' ), ',' );
		/* translators: %s : numéro du chapitre. */
		return sprintf( __( 'ch. %s', 'yume-core' ), $texte );
	}
	return mb_strtolower( libelle_chapitre( $chapitre_id ) );
}

/**
 * Libellé de la pastille « en cours » selon la nature du tome.
 *
 * @param int $tome_id ID du tome.
 */
function libelle_en_cours( int $tome_id ): string {
	$nature = (string) get_post_meta( $tome_id, 'yume_nature', true );
	if ( 'arc' === $nature ) {
		return __( 'Arc en cours', 'yume-core' );
	}
	if ( 'tome' === $nature || '' === $nature ) {
		return __( 'Tome en cours', 'yume-core' );
	}
	return __( 'En cours de sortie', 'yume-core' );
}

/**
 * Carte d'une sortie (tome ou arc) dans la grille des dernières sorties.
 *
 * @param array $sortie Sortie : tome, ts (date), stats (statistiques du tome, facultatives).
 * @param int   $rang   Rang dans la grille (les premières couvertures ne sont pas différées).
 */
function carte_sortie( array $sortie, int $rang ): string {
	$tome_id   = (int) $sortie['tome'];
	$ts        = (int) $sortie['ts'];
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
	$oeuvre    = titre( $oeuvre_id );
	$libelle   = libelle_tome( $tome_id );
	$stats     = isset( $sortie['stats'] ) && is_array( $sortie['stats'] ) ? $sortie['stats'] : stats_tome( $tome_id );
	$en_cours  = ! empty( $stats['en_cours'] );
	$contexte  = trim( $oeuvre . ', ' . $libelle, ', ' );
	$image_id  = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $tome_id ) : 0;
	$badge     = est_nouveau( $ts ) ? pastille( __( 'Nouveau', 'yume-core' ), 'new', '', 'yn-releases__nouveau' ) : '';
	$cover     = couverture(
		$image_id,
		array(
			'alt'        => alt_couverture( $image_id, $contexte ),
			'texte'      => $oeuvre . ' · ' . libelle_tome( $tome_id, true ),
			'badge'      => $badge,
			'chargement' => $rang < 6 ? 'eager' : 'lazy',
			'sizes'      => '(min-width: 1200px) 220px, (min-width: 600px) 33vw, 50vw',
		)
	);

	$meta = array( esc_html( $libelle ) );
	if ( $en_cours && ! empty( $stats['dernier'] ) ) {
		$meta[] = esc_html( chapitre_court( (int) $stats['dernier'] ) );
	}
	$date = balise_date( $ts );
	if ( '' !== $date ) {
		$meta[] = $date;
	}

	// Lire : premier chapitre publié, ou dernier chapitre d'un arc en cours (la nouveauté).
	$lire = '';
	if ( $en_cours && ! empty( $stats['dernier'] ) ) {
		/* translators: 1 : chapitre (« ch. 8 »), 2 : œuvre et tome. */
		$lire = bouton_lire( (int) $stats['dernier'], __( 'Lire', 'yume-core' ), sprintf( __( '%1$s de %2$s', 'yume-core' ), libelle_chapitre( (int) $stats['dernier'] ), $contexte ) );
	} elseif ( ! empty( $stats['premier'] ) ) {
		$lire = bouton_lire( (int) $stats['premier'], __( 'Lire', 'yume-core' ), $contexte );
	}
	$actions = $lire . boutons_telechargement( $tome_id, $contexte );
	if ( $en_cours ) {
		$actions .= pastille( libelle_en_cours( $tome_id ), 'info', '', 'yn-releases__en-cours' );
	}

	$lien  = lien_public( $tome_id );
	$html  = '<li class="yn-releases__item">';
	$html .= '' !== $lien ? '<a class="yn-releases__lien" href="' . esc_url( $lien ) . '">' : '<div class="yn-releases__lien">';
	$html .= $cover . '<h3 class="yn-releases__titre">' . esc_html( $oeuvre ) . '</h3>';
	$html .= '' !== $lien ? '</a>' : '</div>';
	$html .= '<p class="yn-releases__meta yn-muted">' . implode( ' · ', $meta ) . '</p>';
	if ( '' !== $actions ) {
		$html .= '<div class="yn-releases__actions">' . $actions . '</div>';
	}
	return $html . '</li>';
}

/**
 * Rendu de yume/latest-releases : grille de couvertures des dernières sorties (sans carte
 * propre, dans la section « Dernières sorties » du thème).
 *
 * @param array $attributs Attributs du bloc (count).
 */
function rendu_latest_releases( array $attributs = array() ): string {
	$nombre  = isset( $attributs['count'] ) && is_numeric( $attributs['count'] ) ? (int) $attributs['count'] : 6;
	$nombre  = max( 1, min( 24, $nombre ) );
	$sorties = dernieres_sorties( $nombre );
	if ( ! $sorties ) {
		return '<div ' . attributs_racine( 'yn-releases' ) . '><p class="yn-muted yn-releases__vide">' . esc_html__( 'Aucune sortie pour le moment.', 'yume-core' ) . '</p></div>';
	}
	$ids = array();
	foreach ( $sorties as $sortie ) {
		$ids[] = (int) $sortie['tome'];
		$ids[] = (int) ( $sortie['oeuvre'] ?? 0 );
		$ids[] = (int) ( $sortie['stats']['premier'] ?? 0 );
		$ids[] = (int) ( $sortie['stats']['dernier'] ?? 0 );
	}
	amorcer_caches( $ids );
	$items = '';
	foreach ( $sorties as $rang => $sortie ) {
		$items .= carte_sortie( $sortie, (int) $rang );
	}
	return '<div ' . attributs_racine( 'yn-releases' ) . '><ul class="yn-grid-covers yn-releases__liste">' . $items . '</ul></div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/library-grid
 * -----------------------------------------------------------------------------
 */

/**
 * Paramètres de requête (GET) d'un état de filtres, sans les valeurs par défaut.
 *
 * @param array $filtres Filtres normalisés.
 * @return array<string,string>
 */
function parametres_filtres( array $filtres ): array {
	$params = array();
	if ( '' !== $filtres['type'] ) {
		$params['type'] = $filtres['type'];
	}
	if ( $filtres['statuts'] ) {
		$params['statut'] = implode( ',', $filtres['statuts'] );
	}
	if ( '' !== $filtres['genre'] ) {
		$params['genre'] = $filtres['genre'];
	}
	if ( 'az' === $filtres['tri'] ) {
		$params['tri'] = 'az';
	}
	return $params;
}

/**
 * Adresse d'un état de filtres (première page).
 *
 * @param string $base    Adresse de la page.
 * @param array  $filtres Filtres normalisés.
 */
function url_filtres( string $base, array $filtres ): string {
	$base   = remove_query_arg( array( 'type', 'statut', 'genre', 'tri', 'pg' ), $base );
	$params = parametres_filtres( $filtres );
	return $params ? add_query_arg( array_map( 'rawurlencode', $params ), $base ) : $base;
}

/**
 * Pastille de filtre (lien) avec compteur et état actif (aria-current).
 *
 * @param string   $url     Adresse.
 * @param string   $libelle Libellé.
 * @param int|null $nombre  Nombre d'œuvres (null : sans compteur).
 * @param bool     $actif   Filtre actif.
 */
function pastille_filtre( string $url, string $libelle, ?int $nombre, bool $actif ): string {
	$compteur = '';
	if ( null !== $nombre ) {
		$compteur = ' <span class="yn-library__nombre">' . esc_html( nombre_fr( $nombre ) ) . '<span class="yn-visually-hidden"> ' . esc_html( $nombre > 1 ? __( 'œuvres', 'yume-core' ) : __( 'œuvre', 'yume-core' ) ) . '</span></span>';
	}
	return sprintf(
		'<li><a class="%1$s" href="%2$s"%3$s>%4$s%5$s</a></li>',
		esc_attr( 'yn-chip yn-library__pastille' . ( $actif ? ' yn-chip--new is-active' : '' ) ),
		esc_url( $url ),
		$actif ? ' aria-current="page"' : '',
		esc_html( $libelle ),
		$compteur
	);
}

/**
 * Groupe de pastilles de filtre.
 *
 * @param string $id       Identifiant du groupe.
 * @param string $legende  Légende (« Type »).
 * @param string $pastilles Pastilles (<li>).
 */
function groupe_filtres( string $id, string $legende, string $pastilles ): string {
	return '<div class="yn-library__groupe" role="group" aria-labelledby="' . esc_attr( $id ) . '"><span class="yn-label yn-library__legende" id="' . esc_attr( $id ) . '">' . esc_html( $legende ) . '</span><ul class="yn-library__pastilles">' . $pastilles . '</ul></div>';
}

/**
 * Filtres de la bibliothèque (pastilles).
 *
 * @param array  $index   Index des œuvres.
 * @param array  $filtres Filtres normalisés actifs.
 * @param string $base    Adresse de la page.
 */
function filtres_bibliotheque( array $index, array $filtres, string $base ): string {
	$oeuvres = $index['oeuvres'];
	$uid     = wp_unique_id( 'yn-filtres-' );
	$html    = '';

	// Types.
	$comptes   = compter_par( $oeuvres, $filtres, 'type' );
	$sans_type = array_merge( $filtres, array( 'type' => '' ) );
	$pastilles = pastille_filtre( url_filtres( $base, $sans_type ), __( 'Tous', 'yume-core' ), count( filtrer_oeuvres( $oeuvres, $filtres, 'type' ) ), '' === $filtres['type'] );
	$noms      = types() + $index['termes'][ TAX_TYPE ];
	foreach ( $noms as $slug => $nom ) {
		$nombre = (int) ( $comptes[ $slug ] ?? 0 );
		$actif  = $filtres['type'] === $slug;
		if ( $nombre > 0 || $actif ) {
			$pastilles .= pastille_filtre( url_filtres( $base, array_merge( $filtres, array( 'type' => (string) $slug ) ) ), libelle_type_pluriel( (string) $slug, (string) $nom ), $nombre, $actif );
		}
	}
	$html .= groupe_filtres( $uid . '-type', __( 'Type', 'yume-core' ), $pastilles );

	// Statuts (groupes de la maquette ; un statut isolé actif reste affiché).
	$sans_statut = array_merge( $filtres, array( 'statuts' => array() ) );
	$pastilles   = pastille_filtre( url_filtres( $base, $sans_statut ), __( 'Tous', 'yume-core' ), count( filtrer_oeuvres( $oeuvres, $filtres, 'statut' ) ), ! $filtres['statuts'] );
	$reconnu     = ! $filtres['statuts'];
	foreach ( groupes_statuts() as $groupe ) {
		$slugs = $groupe['statuts'];
		sort( $slugs );
		$actif   = $slugs === $filtres['statuts'];
		$reconnu = $reconnu || $actif;
		$nombre  = compter_groupe_statuts( $oeuvres, $filtres, $groupe['statuts'] );
		if ( $nombre > 0 || $actif ) {
			$pastilles .= pastille_filtre( url_filtres( $base, array_merge( $filtres, array( 'statuts' => $slugs ) ) ), $groupe['libelle'], $nombre, $actif );
		}
	}
	if ( ! $reconnu ) {
		$noms_statuts = statuts() + $index['termes'][ TAX_STATUT ];
		$libelles     = array_map( static fn( $s ) => (string) ( $noms_statuts[ $s ] ?? $s ), $filtres['statuts'] );
		$pastilles   .= pastille_filtre( url_filtres( $base, $filtres ), implode( ' / ', $libelles ), compter_groupe_statuts( $oeuvres, $filtres, $filtres['statuts'] ), true );
	}
	$html .= groupe_filtres( $uid . '-statut', __( 'Statut', 'yume-core' ), $pastilles );

	// Genres (seulement s'il en existe).
	if ( $index['termes'][ TAX_GENRE ] || '' !== $filtres['genre'] ) {
		$comptes   = compter_par( $oeuvres, $filtres, 'genre' );
		$sans      = array_merge( $filtres, array( 'genre' => '' ) );
		$pastilles = pastille_filtre( url_filtres( $base, $sans ), __( 'Tous', 'yume-core' ), count( filtrer_oeuvres( $oeuvres, $filtres, 'genre' ) ), '' === $filtres['genre'] );
		$noms      = $index['termes'][ TAX_GENRE ];
		if ( '' !== $filtres['genre'] && ! isset( $noms[ $filtres['genre'] ] ) ) {
			$terme                     = get_term_by( 'slug', $filtres['genre'], TAX_GENRE );
			$noms[ $filtres['genre'] ] = $terme instanceof \WP_Term ? html_entity_decode( $terme->name, ENT_QUOTES, 'UTF-8' ) : $filtres['genre'];
		}
		foreach ( $noms as $slug => $nom ) {
			$nombre = (int) ( $comptes[ $slug ] ?? 0 );
			$actif  = $filtres['genre'] === (string) $slug;
			if ( $nombre > 0 || $actif ) {
				$pastilles .= pastille_filtre( url_filtres( $base, array_merge( $filtres, array( 'genre' => (string) $slug ) ) ), (string) $nom, $nombre, $actif );
			}
		}
		$html .= groupe_filtres( $uid . '-genre', __( 'Genre', 'yume-core' ), $pastilles );
	}

	// Tri.
	$pastilles  = pastille_filtre( url_filtres( $base, array_merge( $filtres, array( 'tri' => 'recent' ) ) ), __( 'Dernières sorties', 'yume-core' ), null, 'recent' === $filtres['tri'] );
	$pastilles .= pastille_filtre( url_filtres( $base, array_merge( $filtres, array( 'tri' => 'az' ) ) ), __( 'A → Z', 'yume-core' ), null, 'az' === $filtres['tri'] );
	$html      .= groupe_filtres( $uid . '-tri', __( 'Trier par', 'yume-core' ), $pastilles );

	return '<nav class="yn-library__filtres" aria-label="' . esc_attr__( 'Filtres de la bibliothèque', 'yume-core' ) . '">' . $html . '</nav>';
}

/**
 * Carte d'une œuvre dans la grille de la bibliothèque.
 *
 * @param array $oeuvre Entrée de l'index.
 * @param array $index  Index (noms des termes).
 * @param int   $rang   Rang dans la page.
 */
function carte_oeuvre( array $oeuvre, array $index, int $rang ): string {
	$id       = (int) $oeuvre['id'];
	$titre    = (string) $oeuvre['titre'];
	$image_id = (int) get_post_thumbnail_id( $id );
	$badge    = '';
	if ( $oeuvre['statuts'] ) {
		$statut = (string) $oeuvre['statuts'][0];
		$noms   = statuts() + $index['termes'][ TAX_STATUT ];
		$badge  = pastille_statut( $statut, (string) ( $noms[ $statut ] ?? $statut ), 'yn-library__statut' );
	}
	$cover = couverture(
		$image_id,
		array(
			'alt'        => alt_couverture( $image_id, $titre ),
			'texte'      => $titre,
			'badge'      => $badge,
			'chargement' => $rang < 6 ? 'eager' : 'lazy',
			'sizes'      => '(min-width: 1200px) 220px, (min-width: 600px) 33vw, 50vw',
		)
	);
	$meta  = array();
	if ( $oeuvre['types'] ) {
		$noms   = types() + $index['termes'][ TAX_TYPE ];
		$meta[] = (string) ( $noms[ $oeuvre['types'][0] ] ?? $oeuvre['types'][0] );
	}
	$tomes = resume_tomes( (array) $oeuvre['tomes'] );
	if ( '' !== $tomes ) {
		$meta[] = $tomes;
	}
	$html  = '<li class="yn-library__item">';
	$html .= '<a class="yn-library__lien" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . $cover . '<h3 class="yn-library__titre">' . esc_html( $titre ) . '</h3></a>';
	if ( $meta ) {
		$html .= '<p class="yn-library__meta yn-muted">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
	}
	return $html . '</li>';
}

/**
 * Rendu de yume/library-grid : filtres GET (type, statut, genre, tri), grille de couvertures
 * avec badge de statut, compteurs, pagination (paramètre pg) et état vide.
 *
 * @param array $attributs Attributs du bloc (perPage, showFilters).
 */
function rendu_library_grid( array $attributs = array() ): string {
	$par_page     = isset( $attributs['perPage'] ) && is_numeric( $attributs['perPage'] ) ? (int) $attributs['perPage'] : 24;
	$par_page     = max( 1, min( 96, $par_page ) );
	$avec_filtres = ! array_key_exists( 'showFilters', $attributs ) || (bool) $attributs['showFilters'];

	// Lecture publique de paramètres de filtre : aucune action, pas de nonce nécessaire.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$brut = array();
	foreach ( array( 'type', 'statut', 'genre', 'tri' ) as $param ) {
		if ( isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ) {
			$brut[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
		}
	}
	$page = isset( $_GET['pg'] ) && is_string( $_GET['pg'] ) ? absint( wp_unslash( $_GET['pg'] ) ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$filtres = normaliser_filtres( $avec_filtres ? $brut : array_intersect_key( $brut, array( 'tri' => true ) ) );
	$index   = index_oeuvres();
	$oeuvres = $index['oeuvres'];

	// Une valeur inconnue (ni terme existant ni terme utilisé) est ignorée.
	$types_connus = array_keys( types() + $index['termes'][ TAX_TYPE ] );
	if ( '' !== $filtres['type'] && ! in_array( $filtres['type'], $types_connus, true ) ) {
		$filtres['type'] = '';
	}
	$statuts_connus     = array_keys( statuts() + $index['termes'][ TAX_STATUT ] );
	$filtres['statuts'] = array_values( array_intersect( $filtres['statuts'], $statuts_connus ) );
	if ( '' !== $filtres['genre'] && ! isset( $index['termes'][ TAX_GENRE ][ $filtres['genre'] ] ) && ! term_exists( $filtres['genre'], TAX_GENRE ) ) {
		$filtres['genre'] = '';
	}

	$base      = url_courante();
	$resultats = trier_oeuvres( filtrer_oeuvres( $oeuvres, $filtres ), $filtres['tri'] );
	$total     = count( $resultats );
	$pages     = max( 1, (int) ceil( $total / $par_page ) );
	$page      = max( 1, min( $page, $pages ) );
	$tranche   = array_slice( $resultats, ( $page - 1 ) * $par_page, $par_page );

	$html = '';
	if ( $avec_filtres && $oeuvres ) {
		$html .= filtres_bibliotheque( $index, $filtres, $base );
	}

	/* translators: %s : nombre d'œuvres. */
	$resume = sprintf( $total > 1 ? __( '%s œuvres', 'yume-core' ) : __( '%s œuvre', 'yume-core' ), nombre_fr( $total ) );
	if ( $pages > 1 ) {
		/* translators: 1 : page courante, 2 : nombre de pages. */
		$resume .= ' · ' . sprintf( __( 'page %1$d sur %2$d', 'yume-core' ), $page, $pages );
	}
	$html .= '<h2 class="yn-library__resume yn-muted">' . esc_html( $resume ) . '</h2>';

	if ( ! $tranche ) {
		$html .= '<div class="yn-library__vide">';
		if ( $oeuvres ) {
			$html .= '<p>' . esc_html__( 'Aucune œuvre ne correspond à ces filtres.', 'yume-core' ) . '</p>';
			$html .= '<p><a class="yn-btn yn-btn--sm" href="' . esc_url( url_filtres( $base, normaliser_filtres( array() ) ) ) . '">' . esc_html__( 'Réinitialiser les filtres', 'yume-core' ) . '</a></p>';
		} else {
			$html .= '<p>' . esc_html__( 'Aucune œuvre publiée pour le moment.', 'yume-core' ) . '</p>';
		}
		$html .= '</div>';
		return '<div ' . attributs_racine( 'yn-library' ) . '>' . $html . '</div>';
	}

	$ids = array_map( static fn( array $o ): int => (int) $o['id'], $tranche );
	_prime_post_caches( $ids, false, true );
	$thumbs = array_filter( array_map( 'get_post_thumbnail_id', $ids ) );
	if ( $thumbs ) {
		_prime_post_caches( array_map( 'intval', $thumbs ), false, true );
	}

	$items = '';
	foreach ( array_values( $tranche ) as $rang => $oeuvre ) {
		$items .= carte_oeuvre( $oeuvre, $index, $rang );
	}
	$html .= '<ul class="yn-grid-covers yn-library__grille">' . $items . '</ul>';

	if ( $pages > 1 ) {
		$liens = paginate_links(
			array(
				'base'      => add_query_arg( 'pg', '%#%', url_filtres( $base, $filtres ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => __( '‹ Précédente', 'yume-core' ),
				'next_text' => __( 'Suivante ›', 'yume-core' ),
				'mid_size'  => 1,
				'type'      => 'plain',
			)
		);
		if ( is_string( $liens ) && '' !== $liens ) {
			$html .= '<nav class="yn-pagination yn-library__pagination" aria-label="' . esc_attr__( 'Pages de la bibliothèque', 'yume-core' ) . '">' . $liens . '</nav>';
		}
	}
	return '<div ' . attributs_racine( 'yn-library' ) . '>' . $html . '</div>';
}
