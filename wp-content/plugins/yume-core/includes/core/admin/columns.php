<?php
/**
 * Listes d'administration des œuvres, tomes et chapitres : colonnes utiles, tri (œuvre,
 * numéro, étape, date cible, dernière sortie) et filtres par œuvre et par tome.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Paramètre GET du filtre par œuvre. */
const FILTRE_OEUVRE = 'yume_oeuvre_filtre';

/** Paramètre GET du filtre par tome. */
const FILTRE_TOME = 'yume_tome_filtre';

/**
 * Insère des colonnes après la colonne titre.
 *
 * @param array<string,string> $colonnes Colonnes existantes.
 * @param array<string,string> $ajouts   Colonnes à ajouter.
 * @return array<string,string>
 */
function inserer_colonnes( array $colonnes, array $ajouts ): array {
	$resultat = array();
	foreach ( $colonnes as $cle => $libelle ) {
		$resultat[ $cle ] = $libelle;
		if ( 'title' === $cle ) {
			$resultat = array_merge( $resultat, $ajouts );
		}
	}
	return isset( $colonnes['title'] ) ? $resultat : array_merge( $colonnes, $ajouts );
}

/**
 * Colonnes des œuvres.
 *
 * @param array<string,string> $colonnes Colonnes.
 * @return array<string,string>
 */
function colonnes_oeuvres( $colonnes ) {
	return inserer_colonnes(
		(array) $colonnes,
		array(
			'yume_tomes'           => __( 'Tomes', 'yume-core' ),
			'yume_derniere_sortie' => __( 'Dernière sortie', 'yume-core' ),
		)
	);
}
add_filter( 'manage_' . CPT_OEUVRE . '_posts_columns', __NAMESPACE__ . '\\colonnes_oeuvres' );

/**
 * Colonnes des tomes.
 *
 * @param array<string,string> $colonnes Colonnes.
 * @return array<string,string>
 */
function colonnes_tomes( $colonnes ) {
	return inserer_colonnes(
		(array) $colonnes,
		array(
			'yume_oeuvre'       => __( 'Œuvre', 'yume-core' ),
			'yume_numero'       => __( 'N°', 'yume-core' ),
			'yume_etape'        => __( 'Étape', 'yume-core' ),
			'yume_date_cible'   => __( 'Date cible', 'yume-core' ),
			'yume_nb_chapitres' => __( 'Chapitres', 'yume-core' ),
		)
	);
}
add_filter( 'manage_' . CPT_TOME . '_posts_columns', __NAMESPACE__ . '\\colonnes_tomes' );

/**
 * Colonnes des chapitres.
 *
 * @param array<string,string> $colonnes Colonnes.
 * @return array<string,string>
 */
function colonnes_chapitres( $colonnes ) {
	return inserer_colonnes(
		(array) $colonnes,
		array(
			'yume_oeuvre'  => __( 'Œuvre', 'yume-core' ),
			'yume_tome'    => __( 'Tome', 'yume-core' ),
			'yume_numero'  => __( 'N°', 'yume-core' ),
			'yume_lecture' => __( 'Lecture', 'yume-core' ),
		)
	);
}
add_filter( 'manage_' . CPT_CHAPITRE . '_posts_columns', __NAMESPACE__ . '\\colonnes_chapitres' );

/**
 * Lien vers la liste filtrée par œuvre.
 *
 * @param int    $oeuvre_id ID de l'œuvre.
 * @param string $type      Type listé.
 */
function lien_filtre_oeuvre( int $oeuvre_id, string $type ): string {
	if ( ! $oeuvre_id ) {
		return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Aucune œuvre', 'yume-core' ) . '</span>';
	}
	return sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url(
			add_query_arg(
				array(
					'post_type'   => $type,
					FILTRE_OEUVRE => $oeuvre_id,
				),
				admin_url( 'edit.php' )
			)
		),
		esc_html( get_the_title( $oeuvre_id ) )
	);
}

/**
 * Contenu des colonnes.
 *
 * @param string $colonne Colonne.
 * @param int    $post_id ID.
 */
function afficher_colonne( $colonne, $post_id ): void {
	$post_id = (int) $post_id;
	$type    = get_post_type( $post_id );
	switch ( $colonne ) {
		case 'yume_tomes':
			$nb = count( yume_get_tomes( $post_id, array( 'status' => 'any' ) ) );
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					add_query_arg(
						array(
							'post_type'   => CPT_TOME,
							FILTRE_OEUVRE => $post_id,
						),
						admin_url( 'edit.php' )
					)
				),
				esc_html( number_format_i18n( $nb ) )
			);
			break;
		case 'yume_derniere_sortie':
			$date = (string) get_post_meta( $post_id, 'yume_derniere_sortie', true );
			echo '' !== $date ? esc_html( get_date_from_gmt( $date, 'j F Y' ) ) : '—';
			break;
		case 'yume_oeuvre':
			echo lien_filtre_oeuvre( yume_get_oeuvre_id( $post_id ), (string) $type ); // phpcs:ignore WordPress.Security.EscapeOutput
			break;
		case 'yume_tome':
			$tome_id = yume_get_tome_id( $post_id );
			if ( $tome_id ) {
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url(
						add_query_arg(
							array(
								'post_type'   => CPT_CHAPITRE,
								FILTRE_OEUVRE => yume_get_oeuvre_id( $tome_id ),
								FILTRE_TOME   => $tome_id,
							),
							admin_url( 'edit.php' )
						)
					),
					esc_html( yume_libelle_tome( $tome_id ) )
				);
			} else {
				echo '—';
			}
			break;
		case 'yume_numero':
			$numero = numero_ou_null( get_post_meta( $post_id, 'yume_numero', true ) );
			$nature = (string) get_post_meta( $post_id, 'yume_nature', true );
			echo null === $numero ? '—' : esc_html( numero_fr( $numero ) );
			if ( CPT_TOME === $type && 'tome' !== $nature ) {
				$natures = yume_natures_tome();
				echo ' <span class="yume-etat">' . esc_html( $natures[ $nature ] ?? $nature ) . '</span>';
			} elseif ( CPT_CHAPITRE === $type && 'chapitre' !== $nature ) {
				$natures = yume_natures_chapitre();
				echo ' <span class="yume-etat">' . esc_html( $natures[ $nature ] ?? $nature ) . '</span>';
			}
			break;
		case 'yume_etape':
			$etapes = yume_etapes();
			$etape  = (string) get_post_meta( $post_id, 'yume_etape', true );
			echo esc_html( $etapes[ $etape ] ?? $etapes['a_faire'] );
			if ( get_post_meta( $post_id, 'yume_bloque', true ) ) {
				echo ' <span class="yume-admin__bloque">' . esc_html__( 'Bloqué', 'yume-core' ) . '</span>';
			}
			break;
		case 'yume_date_cible':
			$date = (string) get_post_meta( $post_id, 'yume_date_cible', true );
			echo '' !== $date ? esc_html( date_i18n( 'j M Y', (int) strtotime( $date . ' 12:00:00' ) ) ) : '—';
			break;
		case 'yume_nb_chapitres':
			echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, 'yume_nb_chapitres', true ) ) );
			break;
		case 'yume_lecture':
			$minutes = (int) get_post_meta( $post_id, 'yume_temps_lecture', true );
			echo $minutes ? esc_html( sprintf( /* translators: %d : minutes */ __( '%d min', 'yume-core' ), $minutes ) ) : '—';
			break;
	}
}
add_action( 'manage_' . CPT_OEUVRE . '_posts_custom_column', __NAMESPACE__ . '\\afficher_colonne', 10, 2 );
add_action( 'manage_' . CPT_TOME . '_posts_custom_column', __NAMESPACE__ . '\\afficher_colonne', 10, 2 );
add_action( 'manage_' . CPT_CHAPITRE . '_posts_custom_column', __NAMESPACE__ . '\\afficher_colonne', 10, 2 );

/**
 * Colonnes triables.
 *
 * @param array<string,mixed> $colonnes Colonnes triables.
 * @return array<string,mixed>
 */
function colonnes_triables( $colonnes ) {
	$colonnes = (array) $colonnes;
	$ecran    = get_current_screen();
	$type     = $ecran instanceof \WP_Screen ? $ecran->post_type : '';
	if ( CPT_OEUVRE === $type ) {
		$colonnes['yume_derniere_sortie'] = array( 'yume_derniere_sortie', true );
	} elseif ( CPT_TOME === $type ) {
		$colonnes['yume_oeuvre']     = 'yume_oeuvre';
		$colonnes['yume_numero']     = 'yume_numero';
		$colonnes['yume_etape']      = 'yume_etape';
		$colonnes['yume_date_cible'] = 'yume_date_cible';
	} elseif ( CPT_CHAPITRE === $type ) {
		$colonnes['yume_oeuvre'] = 'yume_oeuvre';
		$colonnes['yume_tome']   = 'yume_tome';
		$colonnes['yume_numero'] = 'yume_numero';
	}
	return $colonnes;
}
add_filter( 'manage_edit-' . CPT_OEUVRE . '_sortable_columns', __NAMESPACE__ . '\\colonnes_triables' );
add_filter( 'manage_edit-' . CPT_TOME . '_sortable_columns', __NAMESPACE__ . '\\colonnes_triables' );
add_filter( 'manage_edit-' . CPT_CHAPITRE . '_sortable_columns', __NAMESPACE__ . '\\colonnes_triables' );

/**
 * Filtres au-dessus des listes des tomes et chapitres.
 *
 * @param string $post_type Type listé.
 */
function filtres_liste( $post_type ): void {
	if ( ! in_array( $post_type, array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre de liste en lecture seule.
	$oeuvre = isset( $_GET[ FILTRE_OEUVRE ] ) ? absint( $_GET[ FILTRE_OEUVRE ] ) : 0;
	echo '<label class="screen-reader-text" for="' . esc_attr( FILTRE_OEUVRE ) . '">' . esc_html__( 'Filtrer par œuvre', 'yume-core' ) . '</label>';
	echo '<select name="' . esc_attr( FILTRE_OEUVRE ) . '" id="' . esc_attr( FILTRE_OEUVRE ) . '">';
	echo '<option value="0">' . esc_html__( 'Toutes les œuvres', 'yume-core' ) . '</option>';
	foreach ( liste_oeuvres() as $id => $titre ) {
		printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $id, selected( $oeuvre, (int) $id, false ), esc_html( $titre ) );
	}
	echo '</select>';

	if ( CPT_CHAPITRE === $post_type && $oeuvre ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre de liste en lecture seule.
		$tome = isset( $_GET[ FILTRE_TOME ] ) ? absint( $_GET[ FILTRE_TOME ] ) : 0;
		echo '<label class="screen-reader-text" for="' . esc_attr( FILTRE_TOME ) . '">' . esc_html__( 'Filtrer par tome', 'yume-core' ) . '</label>';
		echo '<select name="' . esc_attr( FILTRE_TOME ) . '" id="' . esc_attr( FILTRE_TOME ) . '">';
		echo '<option value="0">' . esc_html__( 'Tous les tomes', 'yume-core' ) . '</option>';
		foreach ( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) as $t ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $t->ID, selected( $tome, (int) $t->ID, false ), esc_html( yume_libelle_tome( (int) $t->ID ) ) );
		}
		echo '</select>';
	}
}
add_action( 'restrict_manage_posts', __NAMESPACE__ . '\\filtres_liste' );

/**
 * Applique filtres et tris à la requête principale des listes d'administration.
 *
 * @param \WP_Query $query Requête.
 */
function requete_liste( $query ): void {
	if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
		return;
	}
	global $pagenow;
	$type = $query->get( 'post_type' );
	if ( 'edit.php' !== $pagenow || ! in_array( $type, types_yume(), true ) ) {
		return;
	}
	appliquer_filtres_liste( $query, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\requete_liste' );

/**
 * Filtres par œuvre / tome et tri selon les paramètres de liste.
 *
 * @param \WP_Query           $query      Requête.
 * @param array<string,mixed> $parametres Paramètres (GET).
 */
function appliquer_filtres_liste( \WP_Query $query, array $parametres ): void {
	$type       = (string) $query->get( 'post_type' );
	$meta_query = (array) $query->get( 'meta_query' );
	$oeuvre     = isset( $parametres[ FILTRE_OEUVRE ] ) ? absint( $parametres[ FILTRE_OEUVRE ] ) : 0;
	$tome       = isset( $parametres[ FILTRE_TOME ] ) ? absint( $parametres[ FILTRE_TOME ] ) : 0;
	if ( $oeuvre && in_array( $type, array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		$meta_query[] = array(
			'key'   => 'yume_oeuvre_id',
			'value' => (string) $oeuvre,
		);
		if ( ! $query->get( 'orderby' ) ) {
			// Liste d'une œuvre : ordre de lecture par défaut.
			$query->set( 'orderby', CPT_TOME === $type ? 'yume_numero' : 'yume_tome' );
			$query->set( 'order', 'ASC' );
		}
	}
	if ( $tome && CPT_CHAPITRE === $type ) {
		$meta_query[] = array(
			'key'   => 'yume_tome_id',
			'value' => (string) $tome,
		);
		if ( ! $query->get( 'orderby' ) ) {
			// Liste d'un tome : ordre de lecture par défaut.
			$query->set( 'orderby', 'yume_numero' );
			$query->set( 'order', 'ASC' );
		}
	}
	if ( $meta_query ) {
		$query->set( 'meta_query', $meta_query );
	}
	$tri = (string) $query->get( 'orderby' );
	if ( in_array( $tri, array( 'yume_oeuvre', 'yume_tome', 'yume_numero', 'yume_etape', 'yume_date_cible', 'yume_derniere_sortie' ), true ) ) {
		$query->set( 'yume_tri', $tri );
	}
}

/**
 * Tri SQL portable (MySQL et SQLite) par jointures gauches : les contenus sans la
 * métadonnée restent dans la liste, rangés en dernier.
 *
 * @param array<string,string> $clauses Clauses SQL.
 * @param \WP_Query            $query   Requête.
 * @return array<string,string>
 */
function clauses_tri( $clauses, $query ) {
	if ( ! $query instanceof \WP_Query ) {
		return $clauses;
	}
	$tri = (string) $query->get( 'yume_tri' );
	if ( '' === $tri ) {
		return $clauses;
	}
	global $wpdb;
	$sens    = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
	$jointer = static function ( string $alias, string $cle, string $colonne_id = '' ) use ( $wpdb ): string {
		$colonne_id = '' !== $colonne_id ? $colonne_id : "{$wpdb->posts}.ID";
		return $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ( {$alias}.post_id = {$colonne_id} AND {$alias}.meta_key = %s )", $cle ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	};
	$vide    = static function ( string $expr ): string {
		return "( {$expr} IS NULL OR {$expr} = '' ) ASC";
	};

	switch ( $tri ) {
		case 'yume_numero':
			$clauses['join']   .= $jointer( 'yume_tri_n', 'yume_numero' );
			$clauses['orderby'] = $vide( 'yume_tri_n.meta_value' ) . ", ( yume_tri_n.meta_value + 0 ) {$sens}, {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_date DESC";
			break;
		case 'yume_date_cible':
		case 'yume_derniere_sortie':
			$clauses['join']   .= $jointer( 'yume_tri_d', $tri );
			$clauses['orderby'] = $vide( 'yume_tri_d.meta_value' ) . ", yume_tri_d.meta_value {$sens}, {$wpdb->posts}.post_date DESC";
			break;
		case 'yume_etape':
			$clauses['join'] .= $jointer( 'yume_tri_e', 'yume_etape' );
			$cas              = array();
			$rang             = 0;
			foreach ( array_keys( yume_etapes() ) as $etape ) {
				$cas[] = $wpdb->prepare( 'WHEN %s THEN %d', $etape, $rang++ );
			}
			$clauses['orderby'] = '( CASE yume_tri_e.meta_value ' . implode( ' ', $cas ) . " ELSE 0 END ) {$sens}, {$wpdb->posts}.post_date DESC";
			break;
		case 'yume_oeuvre':
			$clauses['join']   .= $jointer( 'yume_tri_o', 'yume_oeuvre_id' );
			$clauses['join']   .= " LEFT JOIN {$wpdb->posts} AS yume_tri_op ON ( yume_tri_op.ID = CAST( yume_tri_o.meta_value AS UNSIGNED ) )";
			$clauses['join']   .= $jointer( 'yume_tri_n', 'yume_numero' );
			$clauses['orderby'] = "( yume_tri_op.post_title IS NULL ) ASC, yume_tri_op.post_title {$sens}, ( yume_tri_n.meta_value + 0 ) ASC, {$wpdb->posts}.menu_order ASC";
			break;
		case 'yume_tome':
			// Œuvre, puis numéro du tome, puis numéro du chapitre.
			$clauses['join']   .= $jointer( 'yume_tri_o', 'yume_oeuvre_id' );
			$clauses['join']   .= " LEFT JOIN {$wpdb->posts} AS yume_tri_op ON ( yume_tri_op.ID = CAST( yume_tri_o.meta_value AS UNSIGNED ) )";
			$clauses['join']   .= $jointer( 'yume_tri_t', 'yume_tome_id' );
			$clauses['join']   .= $jointer( 'yume_tri_tn', 'yume_numero', 'CAST( yume_tri_t.meta_value AS UNSIGNED )' );
			$clauses['join']   .= $jointer( 'yume_tri_n', 'yume_numero' );
			$clauses['orderby'] = "( yume_tri_op.post_title IS NULL ) ASC, yume_tri_op.post_title {$sens}, ( yume_tri_tn.meta_value + 0 ) {$sens}, {$wpdb->posts}.menu_order ASC, ( yume_tri_n.meta_value + 0 ) ASC";
			break;
	}
	return $clauses;
}
add_filter( 'posts_clauses', __NAMESPACE__ . '\\clauses_tri', 10, 2 );
