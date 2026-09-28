<?php
/**
 * Rendu du bloc yume/recherche : page de résultats de /?s= (AMEL-04).
 *
 * - Résultats groupés : « Œuvres », « Tomes », « Actualités » et, si le réglage
 *   recherche_chapitres est actif, « Dans les chapitres » (extrait autour du mot, lien vers le
 *   paragraphe #yn-p-N) ; compteur par groupe ; pagination propre à chaque groupe (pg_{groupe}).
 * - Filtres combinables en GET, sans JavaScript (liens) : contenu (un groupe), statut de
 *   l'œuvre (groupes de la bibliothèque), genre, tri (pertinence, plus récents, A → Z).
 * - Termes surlignés par <mark> dans un texte toujours échappé.
 * - Aucun résultat : œuvres au titre proche, lien vers la Bibliothèque.
 *
 * Moteur (plier(), resultats_recherche()…) : recherche.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Terme de la recherche courante (brut, nettoyé ; à échapper en sortie).
 */
function terme_courant(): string {
	$terme = get_query_var( 's' );
	// Lecture publique du terme (aucune action) : pas de nonce ; nettoyé par nettoyer_terme().
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( ( ! is_string( $terme ) || '' === $terme ) && isset( $_GET['s'] ) && is_string( $_GET['s'] ) ) {
		$terme = wp_unslash( $_GET['s'] );
	}
	// phpcs:enable
	return nettoyer_terme( $terme );
}

/**
 * Filtres de la recherche courante (paramètres GET).
 *
 * @return array{contenu:string,statuts:string[],genre:string,tri:string,pages:array<string,int>}
 */
function filtres_courants(): array {
	$brut = array();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture publique de filtres.
	foreach ( $_GET as $cle => $valeur ) {
		if ( is_string( $cle ) && is_string( $valeur ) && preg_match( '/^(contenu|statut|genre|tri|pg_[a-z]+)$/', $cle ) ) {
			$brut[ $cle ] = sanitize_text_field( wp_unslash( $valeur ) );
		}
	}
	// phpcs:enable
	return normaliser_filtres_recherche( $brut );
}

/**
 * Adresse d'une recherche (première page de chaque groupe sauf pages données).
 *
 * @param string $terme   Terme.
 * @param array  $filtres Filtres normalisés.
 * @param array  $pages   Pages par groupe (groupe => page) à garder.
 */
function url_recherche( string $terme, array $filtres, array $pages = array() ): string {
	$params = array( 's' => $terme );
	if ( '' !== $filtres['contenu'] ) {
		$params['contenu'] = $filtres['contenu'];
	}
	if ( $filtres['statuts'] ) {
		$params['statut'] = implode( ',', $filtres['statuts'] );
	}
	if ( '' !== $filtres['genre'] ) {
		$params['genre'] = $filtres['genre'];
	}
	if ( 'pertinence' !== $filtres['tri'] ) {
		$params['tri'] = $filtres['tri'];
	}
	foreach ( $pages as $groupe => $page ) {
		if ( (int) $page > 1 ) {
			$params[ 'pg_' . $groupe ] = (string) (int) $page;
		}
	}
	return add_query_arg( array_map( 'rawurlencode', $params ), home_url( '/' ) );
}

/**
 * Pastille de filtre (lien, état actif aria-current, compteur facultatif).
 *
 * @param string   $url     Adresse.
 * @param string   $libelle Libellé.
 * @param int|null $nombre  Nombre de résultats (null : sans compteur).
 * @param bool     $actif   Filtre actif.
 * @param string   $suffixe Suffixe tronqué (« + ») du compteur.
 */
function pastille_recherche( string $url, string $libelle, ?int $nombre, bool $actif, string $suffixe = '' ): string {
	$compteur = '';
	if ( null !== $nombre ) {
		$compteur = ' <span class="yn-search__nombre">' . esc_html( nombre_fr( $nombre ) . $suffixe ) . '<span class="yn-visually-hidden"> ' . esc_html( $nombre > 1 ? __( 'résultats', 'yume-core' ) : __( 'résultat', 'yume-core' ) ) . '</span></span>';
	}
	return sprintf(
		'<li><a class="%1$s" href="%2$s"%3$s>%4$s%5$s</a></li>',
		esc_attr( 'yn-chip yn-search__pastille' . ( $actif ? ' yn-chip--new is-active' : '' ) ),
		esc_url( $url ),
		$actif ? ' aria-current="page"' : '',
		esc_html( $libelle ),
		$compteur
	);
}

/**
 * Groupe de pastilles.
 *
 * @param string $id        Identifiant.
 * @param string $legende   Légende.
 * @param string $pastilles Pastilles (<li>).
 */
function groupe_pastilles_recherche( string $id, string $legende, string $pastilles ): string {
	return '<div class="yn-search__groupe-filtres" role="group" aria-labelledby="' . esc_attr( $id ) . '"><span class="yn-label yn-search__legende" id="' . esc_attr( $id ) . '">' . esc_html( $legende ) . '</span><ul class="yn-search__pastilles">' . $pastilles . '</ul></div>';
}

/**
 * Filtres de la page de résultats.
 *
 * @param string $terme     Terme.
 * @param array  $filtres   Filtres normalisés.
 * @param array  $resultats Résultats groupés.
 */
function filtres_recherche( string $terme, array $filtres, array $resultats ): string {
	$uid  = wp_unique_id( 'yn-search-filtres-' );
	$html = '';

	// Contenu (groupes, avec compteurs).
	$pastilles = pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'contenu' => '' ) ) ), __( 'Tout', 'yume-core' ), (int) $resultats['total'], '' === $filtres['contenu'] );
	foreach ( $resultats['groupes'] as $cle => $groupe ) {
		$pastilles .= pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'contenu' => $cle ) ) ), $groupe['libelle'], (int) $groupe['total'], $cle === $filtres['contenu'], $groupe['tronque'] ? '+' : '' );
	}
	$html .= groupe_pastilles_recherche( $uid . '-contenu', __( 'Contenu', 'yume-core' ), $pastilles );

	// Statut de l'œuvre (groupes de la bibliothèque).
	$pastilles = pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'statuts' => array() ) ) ), __( 'Tous', 'yume-core' ), null, ! $filtres['statuts'] );
	$reconnu   = ! $filtres['statuts'];
	foreach ( groupes_statuts() as $groupe ) {
		$slugs = $groupe['statuts'];
		sort( $slugs );
		$actif      = $slugs === $filtres['statuts'];
		$reconnu    = $reconnu || $actif;
		$pastilles .= pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'statuts' => $slugs ) ) ), $groupe['libelle'], null, $actif );
	}
	if ( ! $reconnu ) {
		$noms       = statuts() + index_oeuvres()['termes'][ TAX_STATUT ];
		$pastilles .= pastille_recherche( url_recherche( $terme, $filtres ), implode( ' / ', array_map( static fn( $s ) => (string) ( $noms[ $s ] ?? $s ), $filtres['statuts'] ) ), null, true );
	}
	$html .= groupe_pastilles_recherche( $uid . '-statut', __( 'Statut de l’œuvre', 'yume-core' ), $pastilles );

	// Genre (s'il en existe).
	$genres = index_oeuvres()['termes'][ TAX_GENRE ];
	if ( '' !== $filtres['genre'] && ! isset( $genres[ $filtres['genre'] ] ) ) {
		$t                           = get_term_by( 'slug', $filtres['genre'], TAX_GENRE );
		$genres[ $filtres['genre'] ] = $t instanceof \WP_Term ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : $filtres['genre'];
	}
	if ( $genres ) {
		$pastilles = pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'genre' => '' ) ) ), __( 'Tous', 'yume-core' ), null, '' === $filtres['genre'] );
		foreach ( $genres as $slug => $nom ) {
			$pastilles .= pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'genre' => (string) $slug ) ) ), (string) $nom, null, (string) $slug === $filtres['genre'] );
		}
		$html .= groupe_pastilles_recherche( $uid . '-genre', __( 'Genre', 'yume-core' ), $pastilles );
	}

	// Tri.
	$pastilles = '';
	foreach (
		array(
			'pertinence' => __( 'Pertinence', 'yume-core' ),
			'recent'     => __( 'Plus récents', 'yume-core' ),
			'az'         => __( 'A → Z', 'yume-core' ),
		) as $tri => $libelle
	) {
		$pastilles .= pastille_recherche( url_recherche( $terme, array_merge( $filtres, array( 'tri' => $tri ) ) ), $libelle, null, $tri === $filtres['tri'] );
	}
	$html .= groupe_pastilles_recherche( $uid . '-tri', __( 'Trier par', 'yume-core' ), $pastilles );

	return '<nav class="yn-search__filtres" aria-label="' . esc_attr__( 'Filtres de la recherche', 'yume-core' ) . '">' . $html . '</nav>';
}

/**
 * Vignette de couverture d'un résultat.
 *
 * @param int    $image_id Pièce jointe (0 : substitution).
 * @param string $titre    Titre (texte de substitution).
 */
function vignette_resultat( int $image_id, string $titre ): string {
	return couverture(
		$image_id,
		array(
			'alt'    => '',
			'texte'  => $titre,
			'taille' => 'thumbnail',
			'sizes'  => '64px',
			'classe' => 'yn-search__vignette',
		)
	);
}

/**
 * Ligne de résultat : lien principal (titre en h3), détails, extrait.
 *
 * @param string $classe   Variante.
 * @param string $url      Adresse.
 * @param string $titre    Titre (HTML sûr, surligné).
 * @param string $vignette Vignette (HTML sûr, vide sinon).
 * @param array  $lignes   Lignes supplémentaires (HTML sûr), classe => contenu.
 */
function ligne_resultat( string $classe, string $url, string $titre, string $vignette, array $lignes ): string {
	$html = '<li class="' . esc_attr( 'yn-search__item yn-search__item--' . $classe . ( '' !== $vignette ? ' yn-search__item--image' : '' ) ) . '">';
	if ( '' !== $vignette ) {
		$html .= $vignette;
	}
	$html .= '<div class="yn-search__corps"><h3 class="yn-search__nom"><a class="yn-search__lien" href="' . esc_url( $url ) . '">' . $titre . '</a></h3>';
	foreach ( $lignes as $cl => $contenu ) {
		if ( '' !== $contenu ) {
			$html .= '<p class="' . esc_attr( 'yn-search__' . $cl ) . '">' . $contenu . '</p>';
		}
	}
	return $html . '</div></li>';
}

/**
 * Résultat « œuvre ».
 *
 * @param array    $r    Résultat.
 * @param string[] $mots Mots pliés.
 */
function item_oeuvre( array $r, array $mots ): string {
	$oeuvre = index_recherche()['oeuvres'][ $r['id'] ] ?? null;
	if ( ! $oeuvre ) {
		return '';
	}
	$termes  = index_oeuvres()['termes'];
	$meta    = array();
	$types   = types() + $termes[ TAX_TYPE ];
	$statuts = statuts() + $termes[ TAX_STATUT ];
	if ( $oeuvre['types'] ) {
		$meta[] = (string) ( $types[ $oeuvre['types'][0] ] ?? $oeuvre['types'][0] );
	}
	if ( $oeuvre['statuts'] ) {
		$meta[] = (string) ( $statuts[ $oeuvre['statuts'][0] ] ?? $oeuvre['statuts'][0] );
	}
	$detail   = '';
	$libelles = array(
		'alt'          => __( 'Titre alternatif', 'yume-core' ),
		'auteur'       => __( 'Auteur', 'yume-core' ),
		'illustrateur' => __( 'Illustrateur', 'yume-core' ),
		'editeur'      => __( 'Éditeur VO', 'yume-core' ),
	);
	if ( isset( $libelles[ $r['champ'] ] ) && '' !== $r['texte'] ) {
		/* translators: 1 : nature du champ (« Auteur »), 2 : valeur surlignée. */
		$detail = sprintf( esc_html__( '%1$s : %2$s', 'yume-core' ), esc_html( $libelles[ $r['champ'] ] ), surligner( $r['texte'], $mots ) );
	}
	return ligne_resultat(
		'oeuvre',
		(string) get_permalink( (int) $r['id'] ),
		surligner( $oeuvre['titre'], $mots ),
		vignette_resultat( (int) $oeuvre['image'], $oeuvre['titre'] ),
		array(
			'detail' => $detail,
			'meta'   => esc_html( implode( ' · ', $meta ) ),
		)
	);
}

/**
 * Résultat « tome ».
 *
 * @param array    $r    Résultat.
 * @param string[] $mots Mots pliés.
 */
function item_tome( array $r, array $mots ): string {
	$index = index_recherche();
	$tome  = $index['tomes'][ $r['id'] ] ?? null;
	if ( ! $tome ) {
		return '';
	}
	$oeuvre = $index['oeuvres'][ $tome['oeuvre'] ] ?? null;
	$image  = (int) get_post_thumbnail_id( (int) $r['id'] );
	$image  = $image ? $image : (int) ( $oeuvre['image'] ?? 0 );
	$meta   = $oeuvre ? esc_html( $oeuvre['titre'] ) : '';
	if ( $tome['date'] > 0 ) {
		/* translators: %s : date de sortie. */
		$meta .= ( '' !== $meta ? ' · ' : '' ) . sprintf( esc_html__( 'sorti le %s', 'yume-core' ), balise_date( (int) $tome['date'], true ) );
	}
	return ligne_resultat( 'tome', (string) get_permalink( (int) $r['id'] ), surligner( $tome['titre'], $mots ), vignette_resultat( $image, $tome['titre'] ), array( 'meta' => $meta ) );
}

/**
 * Résultat « actualité ».
 *
 * @param array    $r    Résultat.
 * @param string[] $mots Mots pliés.
 */
function item_actualite( array $r, array $mots ): string {
	return ligne_resultat(
		'actualite',
		(string) get_permalink( (int) $r['id'] ),
		surligner( $r['titre'], $mots ),
		'',
		array(
			'meta'    => balise_date( (int) $r['date'], true ),
			'extrait' => extrait_autour( (string) $r['texte'], $mots ),
		)
	);
}

/**
 * Numéro (1 pour le premier) du paragraphe d'un chapitre qui contient la première occurrence :
 * rang parmi les blocs de premier niveau (ce que le lecteur numérote, #yn-p-N), ou parmi les
 * paragraphes d'un contenu sans blocs. 0 si introuvable.
 *
 * @param string   $contenu Contenu enregistré du chapitre.
 * @param string[] $mots    Mots pliés.
 */
function paragraphe_occurrence( string $contenu, array $mots ): int {
	$morceaux = array();
	if ( has_blocks( $contenu ) ) {
		foreach ( parse_blocks( $contenu ) as $bloc ) {
			if ( null === $bloc['blockName'] && '' === trim( (string) $bloc['innerHTML'] ) ) {
				continue;
			}
			$morceaux[] = null === $bloc['blockName'] ? (string) $bloc['innerHTML'] : serialize_block( $bloc );
		}
	} else {
		$morceaux = preg_split( '#</p>|\n\s*\n#i', $contenu, -1, PREG_SPLIT_NO_EMPTY );
		$morceaux = array_values( array_filter( (array) $morceaux, static fn( $m ): bool => '' !== trim( wp_strip_all_tags( (string) $m ) ) ) );
	}
	foreach ( $morceaux as $i => $morceau ) {
		if ( contient_un( plier( texte_brut( (string) $morceau ) ), $mots ) ) {
			return $i + 1;
		}
	}
	return 0;
}

/**
 * Résultat « chapitre » : titre, œuvre et tome, extrait de 200 caractères autour de la
 * première occurrence, lien vers le paragraphe.
 *
 * @param array    $r    Résultat.
 * @param string[] $mots Mots pliés.
 */
function item_chapitre( array $r, array $mots ): string {
	$index  = index_recherche();
	$url    = (string) get_permalink( (int) $r['id'] );
	$numero = paragraphe_occurrence( (string) $r['contenu'], $mots );
	if ( $numero > 0 && '' !== $url ) {
		$url .= '#yn-p-' . $numero;
	}
	$meta = array();
	if ( isset( $index['oeuvres'][ $r['oeuvre'] ] ) ) {
		$meta[] = $index['oeuvres'][ $r['oeuvre'] ]['titre'];
	}
	$tome = libelle_tome( (int) $r['tome'] );
	if ( '' !== $tome ) {
		$meta[] = $tome;
	}
	return ligne_resultat(
		'chapitre',
		$url,
		surligner( libelle_chapitre( (int) $r['id'] ), $mots ),
		'',
		array(
			'meta'    => esc_html( implode( ' · ', $meta ) ),
			'extrait' => extrait_autour( texte_brut( (string) $r['contenu'] ), $mots ),
		)
	);
}

/**
 * Section d'un groupe : titre et compteur, liste paginée.
 *
 * @param string $cle       Groupe.
 * @param array  $groupe    Groupe de resultats_recherche().
 * @param string $terme     Terme.
 * @param array  $filtres   Filtres normalisés.
 * @param array  $mots      Mots pliés.
 * @param int    $par_page  Résultats par page.
 */
function section_groupe( string $cle, array $groupe, string $terme, array $filtres, array $mots, int $par_page ): string {
	$total = (int) $groupe['total'];
	$pages = max( 1, (int) ceil( $total / $par_page ) );
	$page  = max( 1, min( $pages, (int) ( $filtres['pages'][ $cle ] ?? 1 ) ) );
	$id    = 'yn-search-' . $cle;

	$items = '';
	foreach ( array_slice( $groupe['resultats'], ( $page - 1 ) * $par_page, $par_page ) as $r ) {
		$rendu  = __NAMESPACE__ . '\\item_' . array(
			'oeuvres'    => 'oeuvre',
			'tomes'      => 'tome',
			'actualites' => 'actualite',
			'chapitres'  => 'chapitre',
		)[ $cle ];
		$items .= $rendu( $r, $mots );
	}

	$nombre = nombre_fr( $total ) . ( $groupe['tronque'] ? '+' : '' );
	$html   = '<section class="' . esc_attr( 'yn-search__section yn-search__section--' . $cle ) . '" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titre' ) . '">';
	$html  .= '<h2 class="yn-search__titre" id="' . esc_attr( $id . '-titre' ) . '">' . esc_html( $groupe['libelle'] ) . ' <span class="yn-search__compteur">' . esc_html( $nombre ) . '<span class="yn-visually-hidden"> ' . esc_html( $total > 1 ? __( 'résultats', 'yume-core' ) : __( 'résultat', 'yume-core' ) ) . '</span></span></h2>';
	if ( '' === $items ) {
		$html .= '<p class="yn-search__rien yn-muted">' . esc_html__( 'Aucun résultat dans ce groupe.', 'yume-core' ) . '</p>';
	} else {
		$html .= '<ul class="yn-search__liste">' . $items . '</ul>';
	}
	if ( $groupe['tronque'] ) {
		/* translators: %s : nombre de chapitres lus. */
		$html .= '<p class="yn-search__note yn-muted">' . esc_html( sprintf( __( 'Recherche limitée aux %s chapitres les plus récents contenant ces mots : précisez votre recherche pour trouver les plus anciens.', 'yume-core' ), nombre_fr( $total ) ) ) . '</p>';
	}
	if ( $pages > 1 ) {
		$autres = $filtres['pages'];
		unset( $autres[ $cle ] );
		$liens = paginate_links(
			array(
				'base'         => add_query_arg( 'pg_' . $cle, '%#%', url_recherche( $terme, $filtres, $autres ) ),
				'format'       => '',
				'current'      => $page,
				'total'        => $pages,
				'prev_text'    => __( '‹ Précédente', 'yume-core' ),
				'next_text'    => __( 'Suivante ›', 'yume-core' ),
				'mid_size'     => 1,
				'type'         => 'plain',
				'add_fragment' => '#' . $id,
			)
		);
		if ( is_string( $liens ) && '' !== $liens ) {
			/* translators: %s : groupe de résultats (« Œuvres »). */
			$html .= '<nav class="yn-pagination yn-search__pagination" aria-label="' . esc_attr( sprintf( __( 'Pages des résultats : %s', 'yume-core' ), $groupe['libelle'] ) ) . '">' . $liens . '</nav>';
		}
	}
	return $html . '</section>';
}

/**
 * Bloc « Aucun résultat » : œuvres au titre proche, lien vers la Bibliothèque.
 *
 * @param string $terme   Terme.
 * @param array  $filtres Filtres normalisés.
 * @param array  $mots    Mots pliés.
 */
function aucun_resultat( string $terme, array $filtres, array $mots ): string {
	$html  = '<div class="yn-search__vide">';
	$html .= '<h2 class="yn-search__titre">' . esc_html__( 'Aucun résultat', 'yume-core' ) . '</h2>';
	/* translators: %s : terme cherché. */
	$html   .= '<p>' . esc_html( sprintf( __( 'Rien ne correspond à « %s ». Vérifiez l’orthographe ou essayez un titre plus court.', 'yume-core' ), $terme ) ) . '</p>';
	$proches = oeuvres_proches( $mots );
	if ( $proches ) {
		$index = index_recherche();
		$liens = '';
		foreach ( $proches as $id ) {
			$liens .= '<li><a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( $index['oeuvres'][ $id ]['titre'] ?? titre( $id ) ) . '</a></li>';
		}
		$html .= '<p class="yn-search__proches-titre">' . esc_html__( 'Vouliez-vous dire :', 'yume-core' ) . '</p><ul class="yn-search__proches">' . $liens . '</ul>';
	}
	$html .= '<p class="yn-search__actions">';
	if ( '' !== $filtres['contenu'] || filtres_oeuvre_actifs( $filtres ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_recherche( $terme, normaliser_filtres_recherche( array() ) ) ) . '">' . esc_html__( 'Chercher sans filtre', 'yume-core' ) . '</a> ';
	}
	$html .= '<a class="yn-btn yn-btn--sm yn-btn--primary" href="' . esc_url( url_bibliotheque() ) . '">' . esc_html__( 'Parcourir la bibliothèque', 'yume-core' ) . '</a>';
	return $html . '</p></div>';
}

/**
 * Rendu de yume/recherche.
 *
 * @param array $attributs perPage (résultats par page d'un groupe affiché seul), apercu
 *                         (résultats par groupe quand tous les groupes sont affichés), showFilters.
 */
function rendu_recherche( array $attributs = array() ): string {
	$par_page         = isset( $attributs['perPage'] ) && is_numeric( $attributs['perPage'] ) ? max( 1, min( 50, (int) $attributs['perPage'] ) ) : 20;
	$apercu           = isset( $attributs['apercu'] ) && is_numeric( $attributs['apercu'] ) ? max( 1, min( 20, (int) $attributs['apercu'] ) ) : 5;
	$filtres_visibles = ! array_key_exists( 'showFilters', $attributs ) || (bool) $attributs['showFilters'];

	$terme   = terme_courant();
	$filtres = filtres_courants();
	if ( '' === $terme ) {
		$html  = '<p>' . esc_html__( 'Saisissez un titre d’œuvre, un auteur ou un mot pour lancer la recherche.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn yn-btn--sm" href="' . esc_url( url_bibliotheque() ) . '">' . esc_html__( 'Parcourir la bibliothèque', 'yume-core' ) . '</a></p>';
		return '<div ' . attributs_racine( 'yn-search' ) . '>' . $html . '</div>';
	}

	$resultats = resultats_recherche( $terme, $filtres );
	$mots      = $resultats['mots'];
	$html      = '';
	if ( $filtres_visibles ) {
		$html .= filtres_recherche( $terme, $filtres, $resultats );
	}

	$affiches = '' !== $filtres['contenu'] ? array( $filtres['contenu'] => $resultats['groupes'][ $filtres['contenu'] ] ) : $resultats['groupes'];
	$total    = array_sum( array_map( static fn( array $g ): int => (int) $g['total'], $affiches ) );

	/* translators: 1 : nombre de résultats, 2 : terme cherché. */
	$resume = sprintf( _n( '%1$s résultat pour « %2$s »', '%1$s résultats pour « %2$s »', max( 1, $total ), 'yume-core' ), nombre_fr( $total ), $terme );
	$html  .= '<p class="yn-search__resume">' . esc_html( $resume ) . '</p>';

	if ( 0 === $total ) {
		$html .= aucun_resultat( $terme, $filtres, $mots );
		return '<div ' . attributs_racine( 'yn-search' ) . '>' . $html . '</div>';
	}

	$n = '' !== $filtres['contenu'] ? $par_page : $apercu;
	foreach ( $affiches as $cle => $groupe ) {
		if ( '' === $filtres['contenu'] && 0 === (int) $groupe['total'] ) {
			continue;
		}
		$html .= section_groupe( (string) $cle, $groupe, $terme, $filtres, $mots, $n );
	}
	return '<div ' . attributs_racine( 'yn-search' ) . '>' . $html . '</div>';
}
