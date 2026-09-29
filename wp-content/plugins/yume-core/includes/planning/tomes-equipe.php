<?php
/**
 * Espace équipe, vue « Tous les tomes » (?vue=tomes, capacité yume_publier, celle du formulaire
 * de publication) : tous les tomes du catalogue, publiés compris (publiés, programmés,
 * brouillons), groupés par œuvre, avec des filtres œuvre / statut / recherche dans le titre
 * (GET, sans JavaScript) et une pagination.
 *
 * Pour chaque tome : couverture, libellé, statut, date, nombre de chapitres en ligne et les
 * actions « Voir » (tome publié), « Lecture en ligne : ajouter le DOCX/EPUB » (aucun chapitre)
 * ou « Remplacer la lecture en ligne » (chapitres existants) — formulaire de publication
 * prérempli par ?tome=ID, mode « Ajout au catalogue » (sans annonce) d'office pour un tome
 * publié — et « Modifier » (administration, si l'utilisateur peut modifier le tome).
 *
 * La section « Tomes en préparation » du tableau de bord ne liste que le planning à venir :
 * cette vue est le seul endroit de l'espace équipe où retrouver un tome déjà paru.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Tomes par page de la vue « Tous les tomes ». */
const CATALOGUE_PAR_PAGE = 60;

/**
 * Filtre « Statut » de la vue : clé => libellé.
 *
 * @return array<string,string>
 */
function statuts_vue_tomes(): array {
	return array(
		''          => __( 'Tous', 'yume-core' ),
		'publie'    => __( 'Publiés', 'yume-core' ),
		'programme' => __( 'Programmés', 'yume-core' ),
		'brouillon' => __( 'Brouillons', 'yume-core' ),
	);
}

/**
 * Statuts WordPress d'une clé du filtre « Statut ».
 *
 * @param string $statut Clé (voir statuts_vue_tomes()).
 * @return string[]
 */
function statuts_wp_vue_tomes( string $statut ): array {
	switch ( $statut ) {
		case 'publie':
			return array( 'publish', 'private' );
		case 'programme':
			return array( 'future' );
		case 'brouillon':
			return array( 'draft', 'pending' );
		default:
			return array( 'publish', 'private', 'future', 'draft', 'pending' );
	}
}

/**
 * Tomes de la vue, filtrés, triés (œuvres par titre, tomes par numéro, extras à la fin),
 * paginés, puis groupés par œuvre.
 *
 * @param array{oeuvre?:int,statut?:string,recherche?:string} $filtres Filtres.
 * @param int                                                 $page    Page (1…).
 * @return array{total:int,page:int,pages:int,oeuvres:array<int,array{id:int,titre:string,tomes:array<int,array<string,mixed>>}>}
 */
function tomes_equipe( array $filtres, int $page = 1 ): array {
	$args = array(
		'post_type'        => 'yume_tome',
		'post_status'      => statuts_wp_vue_tomes( (string) ( $filtres['statut'] ?? '' ) ),
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
	);
	if ( ! empty( $filtres['oeuvre'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- quelques centaines de tomes.
		$args['meta_query'] = array(
			array(
				'key'   => 'yume_oeuvre_id',
				'value' => (string) (int) $filtres['oeuvre'],
			),
		);
	}
	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		// Titre du tome seulement (« Œuvre — Tome 3 : Sous-titre ») : le nom de l'œuvre y figure.
		$args['s']              = $recherche;
		$args['search_columns'] = array( 'post_title' );
	}
	$chapitres = chapitres_par_tome();
	$titres    = array();
	$liste     = array();
	foreach ( get_posts( $args ) as $tome ) {
		$oeuvre = (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true );
		if ( ! $oeuvre || 'yume_oeuvre' !== get_post_type( $oeuvre ) || 'trash' === get_post_status( $oeuvre ) ) {
			continue;
		}
		$titres[ $oeuvre ] = $titres[ $oeuvre ] ?? titre_brut( $oeuvre );
		$numero            = get_post_meta( $tome->ID, 'yume_numero', true );
		$nb                = $chapitres[ (int) $tome->ID ] ?? array(
			'publies' => 0,
			'attente' => 0,
		);
		$liste[]           = array(
			'id'      => (int) $tome->ID,
			'oeuvre'  => $oeuvre,
			'libelle' => yume_libelle_tome( (int) $tome->ID ),
			'statut'  => (string) $tome->post_status,
			'date'    => ts_contenu( $tome, in_array( $tome->post_status, array( 'publish', 'private', 'future' ), true ) ? 'post_date' : 'post_modified' ),
			'ex'      => 'ex' === get_post_meta( $tome->ID, 'yume_nature', true ),
			'numero'  => is_numeric( $numero ) ? (float) $numero : PHP_FLOAT_MAX,
			'ordre'   => (int) $tome->menu_order,
			'publies' => (int) $nb['publies'],
			'attente' => (int) $nb['attente'],
		);
	}
	usort(
		$liste,
		static function ( array $a, array $b ) use ( $titres ): int {
			$cmp = strnatcasecmp( remove_accents( $titres[ $a['oeuvre'] ] ), remove_accents( $titres[ $b['oeuvre'] ] ) );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return array( $a['oeuvre'], $a['ex'], $a['numero'], $a['ordre'], $a['id'] ) <=> array( $b['oeuvre'], $b['ex'], $b['numero'], $b['ordre'], $b['id'] );
		}
	);
	$total    = count( $liste );
	$pages    = max( 1, (int) ceil( $total / CATALOGUE_PAR_PAGE ) );
	$page     = min( max( 1, $page ), $pages );
	$resultat = array(
		'total'   => $total,
		'page'    => $page,
		'pages'   => $pages,
		'oeuvres' => array(),
	);
	foreach ( array_slice( $liste, ( $page - 1 ) * CATALOGUE_PAR_PAGE, CATALOGUE_PAR_PAGE ) as $tome ) {
		$oeuvre = $tome['oeuvre'];
		if ( ! isset( $resultat['oeuvres'][ $oeuvre ] ) ) {
			$resultat['oeuvres'][ $oeuvre ] = array(
				'id'    => $oeuvre,
				'titre' => $titres[ $oeuvre ],
				'tomes' => array(),
			);
		}
		$resultat['oeuvres'][ $oeuvre ]['tomes'][] = $tome;
	}
	return $resultat;
}

/**
 * Pastille de statut et date d'un tome (« Publié · 12 mars 2024 »).
 *
 * @param array<string,mixed> $tome Tome (voir tomes_equipe()).
 */
function statut_tome_equipe( array $tome ): string {
	$date = $tome['date'] ? format_fr( (int) $tome['date'], 'j M Y' ) : '';
	switch ( $tome['statut'] ) {
		case 'publish':
		case 'private':
			$puce = '<span class="yn-chip yn-chip--ok">' . esc_html__( 'Publié', 'yume-core' ) . '</span>';
			/* translators: %s : date de sortie */
			$texte = '' !== $date ? sprintf( __( 'Paru le %s', 'yume-core' ), $date ) : '';
			break;
		case 'future':
			$puce = '<span class="yn-chip yn-chip--info">' . esc_html__( 'Programmé', 'yume-core' ) . '</span>';
			/* translators: %s : date de sortie programmée */
			$texte = '' !== $date ? sprintf( __( 'Sortie le %s', 'yume-core' ), $date ) : '';
			break;
		default:
			$puce = '<span class="yn-chip">' . esc_html__( 'Brouillon', 'yume-core' ) . '</span>';
			/* translators: %s : date de dernière modification */
			$texte = '' !== $date ? sprintf( __( 'Modifié le %s', 'yume-core' ), $date ) : '';
	}
	return $puce . ( '' !== $texte ? '<span class="yn-muted yn-tomes__date">' . esc_html( $texte ) . '</span>' : '' );
}

/**
 * Ligne d'un tome : couverture, libellé, statut, date, chapitres en ligne, actions.
 *
 * @param array<string,mixed> $tome   Tome (voir tomes_equipe()).
 * @param string              $oeuvre Titre de l'œuvre.
 */
function ligne_tome_equipe( array $tome, string $oeuvre ): string {
	$id         = (int) $tome['id'];
	$couverture = yume_get_cover_id( $id );
	$contexte   = '<span class="yn-visually-hidden"> — ' . esc_html( $oeuvre . ', ' . $tome['libelle'] ) . '</span>';
	$html       = '<li class="yn-lecture__tome" id="yn-tomes-' . $id . '">';
	$html      .= '<span class="yn-lecture__couverture" aria-hidden="true">';
	$html      .= $couverture ? yume_image_couverture( $couverture, 'thumbnail', array( 'alt' => '' ) ) : '<span class="yn-lecture__sans-couverture">' . esc_html( mb_strtoupper( mb_substr( $oeuvre, 0, 1 ) ) ) . '</span>';
	$html      .= '</span><div class="yn-lecture__infos"><p class="yn-lecture__libelle">' . esc_html( (string) $tome['libelle'] ) . '</p><p class="yn-lecture__puces">';
	$html      .= statut_tome_equipe( $tome );
	$html      .= $tome['publies'] > 0
		/* translators: %d : nombre de chapitres */
		? '<span class="yn-chip yn-chip--ok">' . esc_html( sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', (int) $tome['publies'], 'yume-core' ), (int) $tome['publies'] ) ) . '</span>'
		: '<span class="yn-chip">' . esc_html__( 'Pas de lecture en ligne', 'yume-core' ) . '</span>';
	if ( $tome['attente'] > 0 ) {
		/* translators: %d : nombre de chapitres */
		$html .= '<span class="yn-chip yn-chip--warn">' . esc_html( sprintf( _n( '%d chapitre préparé, pas encore en ligne', '%d chapitres préparés, pas encore en ligne', (int) $tome['attente'], 'yume-core' ), (int) $tome['attente'] ) ) . '</span>';
	}
	$html   .= '</p></div><p class="yn-lecture__actions">';
	$publier = add_query_arg( 'tome', $id, yume_url_page( 'publier' ) );
	$html   .= '<a class="yn-btn yn-btn--primary yn-btn--sm" href="' . esc_url( $publier ) . '">' . esc_html(
		$tome['publies'] > 0 ? __( 'Remplacer la lecture en ligne', 'yume-core' ) : __( 'Lecture en ligne : ajouter le DOCX/EPUB', 'yume-core' )
	) . $contexte . '</a>';
	if ( in_array( $tome['statut'], array( 'publish', 'private' ), true ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir', 'yume-core' ) . $contexte . '</a>';
	}
	$modifier = current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id ) : '';
	if ( '' !== $modifier ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( $modifier ) . '">' . esc_html__( 'Modifier', 'yume-core' ) . $contexte . '</a>';
	}
	return $html . '</p></li>';
}

/**
 * Texte recherché (paramètre GET « recherche »).
 */
function get_recherche_tomes(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre d'affichage en lecture seule.
	$brut = isset( $_GET['recherche'] ) && is_string( $_GET['recherche'] ) ? sanitize_text_field( wp_unslash( $_GET['recherche'] ) ) : '';
	return mb_substr( trim( $brut ), 0, 100 );
}

/**
 * Vue « Tous les tomes » (?vue=tomes).
 */
function rendu_vue_tomes(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--tomes' );
	$html .= navigation_equipe( 'tomes' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( 'yume_publier' ) ) {
		$html .= tete_vue( __( 'Tous les tomes', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent gérer la lecture en ligne des tomes.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}

	$oeuvre    = get_entier( 'oeuvre' );
	$oeuvre    = $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) ? $oeuvre : 0;
	$statut    = get_cle( 'statut' );
	$statut    = isset( statuts_vue_tomes()[ $statut ] ) ? $statut : '';
	$recherche = get_recherche_tomes();
	$filtres   = array_filter(
		array(
			'oeuvre'    => $oeuvre,
			'statut'    => $statut,
			'recherche' => $recherche,
		)
	);
	$donnees   = tomes_equipe( $filtres, max( 1, get_entier( 'pg' ) ) );

	$html .= tete_vue( __( 'Tous les tomes', 'yume-core' ), '<a class="yn-btn" href="' . esc_url( yume_url_page( 'publier' ) ) . '">' . esc_html__( 'Publier un tome', 'yume-core' ) . '</a>' );
	$html .= '<p class="yn-muted">' . esc_html__( 'Tous les tomes du catalogue, publiés compris. « Remplacer la lecture en ligne » ouvre le formulaire de publication prérempli : déposez le nouveau DOCX ou EPUB et vérifiez-le (rien ne change en ligne), puis remplacez : les chapitres sont mis à jour en place (mêmes adresses, commentaires conservés), sans nouvelle annonce pour un tome déjà paru (ni article, ni Discord, ni e-mail) et sans changer sa date de sortie.', 'yume-core' ) . '</p>';

	// Filtres (GET, sans JavaScript).
	$choix = choix_oeuvres( __( 'Toutes', 'yume-core' ) );
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" role="search" aria-label="' . esc_attr__( 'Filtrer les tomes', 'yume-core' ) . '">' . champs_caches_vue( 'tomes' );
	$html .= champ_select( 'yn-t-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), $choix, $oeuvre ? (string) $oeuvre : '' );
	$html .= champ_select( 'yn-t-statut', 'statut', __( 'Statut', 'yume-core' ), statuts_vue_tomes(), $statut );
	$html .= champ_saisie( 'yn-t-recherche', 'recherche', __( 'Titre contient', 'yume-core' ), $recherche, 'search', array( 'maxlength' => 100 ) );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p>';
	$html .= '</form>';

	$html .= '<section class="yn-team__section" id="yn-tomes-liste" aria-labelledby="yn-tomes-liste-titre"><div class="yn-team__section-tete">';
	$html .= '<h2 id="yn-tomes-liste-titre">' . esc_html(
		$donnees['total'] > 0
			/* translators: %d : nombre de tomes */
			? sprintf( _n( '%d tome', '%d tomes', $donnees['total'], 'yume-core' ), $donnees['total'] )
			: __( 'Aucun tome', 'yume-core' )
	) . '</h2>';
	if ( $filtres ) {
		$html .= '<a href="' . esc_url( url_vue_equipe( 'tomes' ) ) . '">' . esc_html__( 'Effacer les filtres', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	if ( ! $donnees['total'] ) {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html(
			$filtres
				? __( 'Aucun tome ne correspond à ces filtres.', 'yume-core' )
				: __( 'Aucun tome pour le moment.', 'yume-core' )
		) . '</p>';
	}
	foreach ( $donnees['oeuvres'] as $id => $groupe ) {
		$titre_id = 'yn-tomes-oeuvre-' . (int) $id;
		$html    .= '<section class="yn-card yn-lecture__oeuvre" aria-labelledby="' . esc_attr( $titre_id ) . '"><div class="yn-lecture__oeuvre-tete">';
		$html    .= '<h3 id="' . esc_attr( $titre_id ) . '">' . esc_html( $groupe['titre'] ) . '</h3>';
		$html    .= '<span class="yn-muted">' . esc_html(
			/* translators: %d : nombre de tomes */
			sprintf( _n( '%d tome', '%d tomes', count( $groupe['tomes'] ), 'yume-core' ), count( $groupe['tomes'] ) )
		) . '</span></div><ul class="yn-lecture__tomes">';
		foreach ( $groupe['tomes'] as $tome ) {
			$html .= ligne_tome_equipe( $tome, $groupe['titre'] );
		}
		$html .= '</ul></section>';
	}
	$html .= pagination_vue( 'tomes', $filtres, $donnees['page'], $donnees['page'] < $donnees['pages'], $donnees['pages'] );
	$html .= '</section>';

	return $html . '</div></div>';
}
