<?php
/**
 * Menu d'administration Yume (§6 bis du contrat) : menu de premier niveau « Yume »
 * (slug yume), tableau de bord, taxonomies, réglages, et ressources d'administration.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Menu de premier niveau (priorité 9 : avant l'ajout des types de contenu en sous-menus).
 */
function ajouter_menu(): void {
	add_menu_page(
		__( 'Yume Novel', 'yume-core' ),
		__( 'Yume', 'yume-core' ),
		'edit_yume_tomes',
		'yume',
		__NAMESPACE__ . '\\afficher_tableau_de_bord',
		'dashicons-book-alt',
		26
	);
	add_submenu_page(
		'yume',
		__( 'Tableau de bord Yume', 'yume-core' ),
		__( 'Tableau de bord', 'yume-core' ),
		'edit_yume_tomes',
		'yume',
		__NAMESPACE__ . '\\afficher_tableau_de_bord'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\ajouter_menu', 9 );

/**
 * Sous-menus des taxonomies des œuvres (les types rangés sous « Yume » n'en ont pas
 * automatiquement) et page Réglages en dernier.
 */
function ajouter_sous_menus(): void {
	foreach ( array( TAX_TYPE, TAX_STATUT, TAX_GENRE ) as $taxonomie ) {
		$objet = get_taxonomy( $taxonomie );
		if ( ! $objet ) {
			continue;
		}
		add_submenu_page(
			'yume',
			$objet->labels->name,
			$objet->labels->menu_name,
			$objet->cap->manage_terms,
			'edit-tags.php?taxonomy=' . $taxonomie . '&post_type=' . CPT_OEUVRE
		);
	}
	add_submenu_page(
		'yume',
		__( 'Réglages Yume', 'yume-core' ),
		__( 'Réglages', 'yume-core' ),
		'yume_reglages',
		PAGE_REGLAGES,
		__NAMESPACE__ . '\\afficher_page_reglages'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\ajouter_sous_menus', 11 );

/**
 * Place la page Réglages en fin de menu, après les sous-pages des autres modules
 * (ajoutées sur admin_menu priorité ≥ 20).
 */
function ordonner_sous_menus(): void {
	global $submenu;
	if ( empty( $submenu['yume'] ) || ! is_array( $submenu['yume'] ) ) {
		return;
	}
	$reglages = array();
	foreach ( $submenu['yume'] as $index => $item ) {
		if ( isset( $item[2] ) && PAGE_REGLAGES === $item[2] ) {
			$reglages[] = $item;
			unset( $submenu['yume'][ $index ] );
		}
	}
	$submenu['yume'] = array_merge( array_values( $submenu['yume'] ), $reglages ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}
add_action( 'admin_menu', __NAMESPACE__ . '\\ordonner_sous_menus', 999 );

/**
 * Garde le menu Yume ouvert sur les pages des taxonomies des œuvres.
 *
 * @param string $parent_file Menu parent.
 */
function filtre_parent_file( $parent_file ) {
	global $current_screen, $submenu_file;
	if ( $current_screen instanceof \WP_Screen && in_array( $current_screen->taxonomy, array( TAX_TYPE, TAX_STATUT, TAX_GENRE ), true ) ) {
		$submenu_file = 'edit-tags.php?taxonomy=' . $current_screen->taxonomy . '&post_type=' . CPT_OEUVRE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		return 'yume';
	}
	return $parent_file;
}
add_filter( 'parent_file', __NAMESPACE__ . '\\filtre_parent_file' );

/**
 * Compte les contenus d'un type par statut.
 *
 * @param string $type Type de contenu.
 * @return array<string,int>
 */
function compter( string $type ): array {
	$compte = wp_count_posts( $type );
	return array(
		'publish' => (int) ( $compte->publish ?? 0 ),
		'future'  => (int) ( $compte->future ?? 0 ),
		'draft'   => (int) ( $compte->draft ?? 0 ) + (int) ( $compte->pending ?? 0 ),
	);
}

/**
 * Tableau de bord Yume : chiffres, tomes en préparation, raccourcis.
 */
function afficher_tableau_de_bord(): void {
	if ( ! current_user_can( 'edit_yume_tomes' ) ) {
		wp_die( esc_html__( 'Vous n’avez pas accès à l’espace Yume.', 'yume-core' ), 403 );
	}
	$oeuvres   = compter( CPT_OEUVRE );
	$tomes     = compter( CPT_TOME );
	$chapitres = compter( CPT_CHAPITRE );

	echo '<div class="wrap yume-admin">';
	echo '<h1>' . esc_html__( 'Tableau de bord Yume', 'yume-core' ) . '</h1>';

	// Raccourcis.
	$liens = array();
	foreach ( array( CPT_OEUVRE, CPT_TOME, CPT_CHAPITRE ) as $type ) {
		$objet = get_post_type_object( $type );
		if ( $objet && current_user_can( $objet->cap->create_posts ) ) {
			$liens[] = '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . $type ) ) . '">' . esc_html( $objet->labels->add_new_item ) . '</a>';
		}
	}
	if ( current_user_can( 'yume_publier' ) ) {
		$liens[] = '<a class="button button-primary" href="' . esc_url( yume_url_page( 'publier' ) ) . '">' . esc_html__( 'Publier un tome', 'yume-core' ) . '</a>';
	}
	if ( current_user_can( 'yume_voir_equipe' ) ) {
		$liens[] = '<a class="button" href="' . esc_url( yume_url_page( 'equipe' ) ) . '">' . esc_html__( 'Espace équipe', 'yume-core' ) . '</a>';
	}
	$liens[] = '<a class="button" href="' . esc_url( yume_url_page( 'planning' ) ) . '">' . esc_html__( 'Planning public', 'yume-core' ) . '</a>';
	if ( current_user_can( 'yume_reglages' ) ) {
		$liens[] = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . PAGE_REGLAGES ) ) . '">' . esc_html__( 'Réglages', 'yume-core' ) . '</a>';
	}
	echo '<p class="yume-admin__raccourcis">' . implode( ' ', $liens ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

	// Chiffres.
	$cartes = array(
		array( __( 'Œuvres', 'yume-core' ), $oeuvres['publish'], $oeuvres['draft'] ? sprintf( /* translators: %d : nombre */ _n( '%d brouillon', '%d brouillons', $oeuvres['draft'], 'yume-core' ), $oeuvres['draft'] ) : '' ),
		array( __( 'Tomes sortis', 'yume-core' ), $tomes['publish'], trim( ( $tomes['future'] ? sprintf( /* translators: %d : nombre */ _n( '%d programmé', '%d programmés', $tomes['future'], 'yume-core' ), $tomes['future'] ) : '' ) . ( $tomes['draft'] ? ' · ' . sprintf( /* translators: %d : nombre */ _n( '%d planifié', '%d planifiés', $tomes['draft'], 'yume-core' ), $tomes['draft'] ) : '' ), ' ·' ) ),
		array( __( 'Chapitres en ligne', 'yume-core' ), $chapitres['publish'], $chapitres['future'] ? sprintf( /* translators: %d : nombre */ _n( '%d programmé', '%d programmés', $chapitres['future'], 'yume-core' ), $chapitres['future'] ) : '' ),
	);
	echo '<ul class="yume-admin__chiffres">';
	foreach ( $cartes as $carte ) {
		printf(
			'<li class="yume-admin__chiffre"><span class="yume-admin__valeur">%1$s</span> <span class="yume-admin__libelle">%2$s</span>%3$s</li>',
			esc_html( number_format_i18n( $carte[1] ) ),
			esc_html( $carte[0] ),
			'' !== $carte[2] ? '<span class="yume-admin__detail">' . esc_html( $carte[2] ) . '</span>' : ''
		);
	}
	echo '</ul>';

	// Tomes en préparation.
	$en_cours = get_posts(
		array(
			'post_type'        => CPT_TOME,
			'post_status'      => array( 'draft', 'pending', 'future' ),
			'posts_per_page'   => 15,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);
	echo '<h2>' . esc_html__( 'Tomes en préparation', 'yume-core' ) . '</h2>';
	if ( ! $en_cours ) {
		echo '<p>' . esc_html__( 'Aucun tome en préparation.', 'yume-core' ) . '</p>';
	} else {
		$etapes = yume_etapes();
		echo '<div style="overflow-x:auto"><table class="widefat striped yume-admin__table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Œuvre', 'yume-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Tome', 'yume-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Étape', 'yume-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Avancement', 'yume-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Date cible', 'yume-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $en_cours as $tome ) {
			$oeuvre_id  = yume_get_oeuvre_id( (int) $tome->ID );
			$etape      = (string) get_post_meta( $tome->ID, 'yume_etape', true );
			$avancement = san_avancement( get_post_meta( $tome->ID, 'yume_avancement', true ) );
			$date       = (string) get_post_meta( $tome->ID, 'yume_date_cible', true );
			$bloque     = (bool) get_post_meta( $tome->ID, 'yume_bloque', true );
			$lien       = current_user_can( 'edit_post', $tome->ID ) ? get_edit_post_link( $tome->ID ) : '';
			$libelle    = yume_libelle_tome( (int) $tome->ID );
			echo '<tr>';
			echo '<td>' . esc_html( $oeuvre_id ? get_the_title( $oeuvre_id ) : '—' ) . '</td>';
			echo '<td>' . ( $lien ? '<a href="' . esc_url( $lien ) . '">' . esc_html( $libelle ) . '</a>' : esc_html( $libelle ) ) . '</td>';
			echo '<td>' . esc_html( $etapes[ $etape ] ?? $etapes['a_faire'] ) . ( $bloque ? ' <span class="yume-admin__bloque">' . esc_html__( 'Bloqué', 'yume-core' ) . '</span>' : '' ) . '</td>';
			printf(
				'<td>%s</td>',
				esc_html(
					sprintf(
						/* translators: 1: traduction %, 2: relecture %, 3: édition % */
						__( 'Trad. %1$d %% · Relec. %2$d %% · Éd. %3$d %%', 'yume-core' ),
						$avancement['traduction'],
						$avancement['relecture'],
						$avancement['edition']
					)
				)
			);
			echo '<td>' . esc_html( $date ? date_i18n( 'j F Y', (int) strtotime( $date . ' 12:00:00' ) ) : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '</div>';
}

/**
 * Styles et scripts d'administration (tableau de bord, réglages, écrans des contenus Yume).
 *
 * @param string $hook Écran courant.
 */
function charger_ressources_admin( $hook ): void {
	$ecran        = get_current_screen();
	$sur_yume     = in_array( $hook, array( 'toplevel_page_yume', 'yume_page_' . PAGE_REGLAGES ), true );
	$sur_contenus = $ecran instanceof \WP_Screen && in_array( $ecran->post_type, types_yume(), true );
	if ( ! $sur_yume && ! $sur_contenus ) {
		return;
	}
	$url = YUME_CORE_URL . 'includes/core/assets/';
	wp_enqueue_style( 'yume-core-admin', $url . 'admin.css', array(), YUME_CORE_VERSION );
	if ( 'yume_page_' . PAGE_REGLAGES === $hook || ( $ecran instanceof \WP_Screen && 'post' === $ecran->base ) ) {
		wp_enqueue_media();
		wp_enqueue_script( 'yume-core-admin', $url . 'admin.js', array( 'wp-a11y' ), YUME_CORE_VERSION, true );
		wp_localize_script(
			'yume-core-admin',
			'yumeAdmin',
			array(
				'utiliser'           => __( 'Utiliser cette image', 'yume-core' ),
				'utiliser_plusieurs' => __( 'Utiliser ces images', 'yume-core' ),
				'image_choisie'      => __( 'Image choisie', 'yume-core' ),
				/* translators: %d : nombre d'images */
				'images_choisies'    => __( '%d images choisies', 'yume-core' ),
				'ligne_ajoutee'      => __( 'Nouvelle ligne de lien ajoutée.', 'yume-core' ),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\charger_ressources_admin' );
