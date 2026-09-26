<?php
/**
 * Pages Yume manquantes (contrat §11, option yume_pages) : avis d'administration listant les
 * pages absentes, à la corbeille, non publiées ou détachées de leur page parente, et bouton
 * « Recréer les pages manquantes » qui les recrée comme la migration
 * (Migration_Planner::PAGES_A_CREER : mêmes slugs, parents et contenus).
 *
 * La fonction recreer_pages_yume() sert aussi au contenu de démonstration
 * (tools/playground/demo.php).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

use Yume\Core\Migration\Blocks;
use Yume\Core\Migration\Migration_Planner;

defined( 'ABSPATH' ) || exit;

/**
 * Définitions des pages Yume (clé => slug, clé du parent, titre, bloc, réglage de lecture),
 * reprises de la migration, ou tableau vide si le module migration n'est pas chargé.
 *
 * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
 */
function pages_yume_attendues(): array {
	return class_exists( Migration_Planner::class ) ? Migration_Planner::PAGES_A_CREER : array();
}

/**
 * Contenu d'une page Yume créée : bloc dynamique, ou contenu propre (mentions légales).
 *
 * @param string $cle Clé de la page.
 * @param array  $def Définition (PAGES_A_CREER).
 */
function contenu_page_yume( string $cle, array $def ): string {
	return '' !== $def[3] ? Blocks::dynamique( $def[3] ) : Migration_Planner::contenu_page( $cle );
}

/**
 * État d'une page Yume enregistrée : '' si elle est en ligne à son adresse, sinon la raison.
 *
 * @param string $cle   Clé de la page.
 * @param array  $pages Option yume_pages (clé => ID).
 */
function etat_page_yume( string $cle, array $pages ): string {
	$defs = pages_yume_attendues();
	$id   = (int) ( $pages[ $cle ] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
		return __( 'absente', 'yume-core' );
	}
	if ( 'trash' === $post->post_status ) {
		return __( 'à la corbeille', 'yume-core' );
	}
	if ( 'publish' !== $post->post_status ) {
		$statut = get_post_status_object( $post->post_status );
		/* translators: %s: statut (Brouillon, Privé…). */
		return sprintf( __( 'non publiée (%s)', 'yume-core' ), $statut ? mb_strtolower( (string) $statut->label ) : $post->post_status );
	}
	$parent = (string) ( $defs[ $cle ][1] ?? '' );
	if ( '' !== $parent && ( '' !== etat_page_yume( $parent, $pages ) || (int) ( $pages[ $parent ] ?? 0 ) !== (int) $post->post_parent ) ) {
		return __( 'page parente absente ou non publiée', 'yume-core' );
	}
	return '';
}

/**
 * Pages Yume manquantes : clés de yume_pages dont la page n'est pas en ligne, et (une fois la
 * migration faite, yume_pages non vide) pages du contrat jamais créées.
 *
 * @return array<string,string> Clé => raison.
 */
function pages_yume_manquantes(): array {
	$pages = get_option( 'yume_pages', array() );
	$pages = is_array( $pages ) ? $pages : array();
	if ( array() === $pages ) {
		return array();
	}
	$cles       = array_unique( array_merge( array_keys( pages_yume_attendues() ), array_keys( $pages ) ) );
	$manquantes = array();
	foreach ( $cles as $cle ) {
		$raison = etat_page_yume( (string) $cle, $pages );
		if ( '' !== $raison ) {
			$manquantes[ (string) $cle ] = $raison;
		}
	}
	return $manquantes;
}

/**
 * Recrée les pages Yume qui ne sont pas en ligne et met à jour yume_pages. Une page encore
 * présente (brouillon, privée) est republiée et rattachée à son parent ; une page absente ou
 * à la corbeille est remplacée par une page publiée à l'adresse du contrat (une page existante
 * à cette adresse est reprise). Les réglages de lecture (page_on_front, page_for_posts) qui ne
 * visent plus une page publiée sont reportés sur la page recréée.
 *
 * @return array<string,int> Clé => ID des pages créées, republiées ou rattachées.
 */
function recreer_pages_yume(): array {
	$defs = pages_yume_attendues();
	if ( array() === $defs ) {
		return array();
	}
	$pages   = get_option( 'yume_pages', array() );
	$pages   = is_array( $pages ) ? $pages : array();
	$traites = array();
	foreach ( $defs as $cle => $def ) {
		if ( '' === etat_page_yume( $cle, $pages ) ) {
			continue;
		}
		list( $slug, $parent_cle, $titre ) = $def;
		$parent                            = '' !== $parent_cle ? (int) ( $pages[ $parent_cle ] ?? 0 ) : 0;
		if ( '' !== $parent_cle && '' !== etat_page_yume( $parent_cle, $pages ) ) {
			continue; // Parent non recréé : la page enfant n'aurait pas la bonne adresse.
		}
		$post = get_post( (int) ( $pages[ $cle ] ?? 0 ) );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			$post = get_page_by_path( ( $parent ? get_page_uri( $parent ) . '/' : '' ) . $slug );
			$post = $post instanceof \WP_Post && 'trash' !== $post->post_status && (int) $post->post_parent === $parent ? $post : null;
		}
		if ( $post instanceof \WP_Post ) {
			$maj = array(
				'ID'          => (int) $post->ID,
				'post_status' => 'publish',
				'post_parent' => $parent,
			);
			if ( '' === $post->post_name ) {
				$maj['post_name'] = $slug;
			}
			if ( '' === trim( $post->post_content ) ) {
				$maj['post_content'] = contenu_page_yume( $cle, $def );
			}
			$id = wp_update_post( wp_slash( $maj ), true );
		} else {
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => 'page',
						'post_title'     => $titre,
						'post_name'      => $slug,
						'post_status'    => 'publish',
						'post_parent'    => $parent,
						'post_content'   => contenu_page_yume( $cle, $def ),
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					)
				),
				true
			);
		}
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		$pages[ $cle ]   = (int) $id;
		$traites[ $cle ] = (int) $id;
	}
	if ( $traites ) {
		update_option( 'yume_pages', $pages );
	}
	foreach ( $defs as $cle => $def ) {
		$reglage = (string) $def[4];
		if ( '' === $reglage || empty( $pages[ $cle ] ) || '' !== etat_page_yume( $cle, $pages ) ) {
			continue;
		}
		$actuelle = get_post( (int) get_option( $reglage ) );
		if ( ! $actuelle instanceof \WP_Post || 'page' !== $actuelle->post_type || 'publish' !== $actuelle->post_status ) {
			update_option( $reglage, (int) $pages[ $cle ] );
		}
	}
	return $traites;
}

/**
 * L'utilisateur courant peut-il voir l'avis et recréer les pages ?
 */
function peut_recreer_pages_yume(): bool {
	return current_user_can( 'manage_options' ) || current_user_can( 'yume_reglages' );
}

/**
 * Avis d'administration : pages Yume manquantes, et résultat d'une recréation.
 */
function avis_pages_yume(): void {
	if ( ! peut_recreer_pages_yume() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple compte rendu après redirection.
	$recreees = isset( $_GET['yume_pages_recreees'] ) ? absint( $_GET['yume_pages_recreees'] ) : null;
	if ( null !== $recreees ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: nombre de pages. */
					_n( '%d page Yume recréée ou republiée.', '%d pages Yume recréées ou republiées.', $recreees, 'yume-core' ),
					$recreees
				)
			)
		);
	}
	$manquantes = pages_yume_manquantes();
	if ( array() === $manquantes ) {
		return;
	}
	$defs = pages_yume_attendues();
	echo '<div class="notice notice-warning yume-pages-manquantes"><p><strong>' . esc_html__( 'Pages Yume manquantes', 'yume-core' ) . '</strong> — ';
	esc_html_e( 'les liens du site (en-tête, pied de page, espace équipe) qui y mènent aboutissent à une erreur 404 :', 'yume-core' );
	echo '</p><ul style="list-style:disc;margin-left:2em">';
	foreach ( $manquantes as $cle => $raison ) {
		$def    = $defs[ $cle ] ?? array( $cle, '', $cle );
		$chemin = '/' . ( '' !== $def[1] ? $defs[ $def[1] ][0] . '/' : '' ) . $def[0] . '/';
		printf( '<li>%1$s (<code>%2$s</code>) : %3$s</li>', esc_html( $def[2] ), esc_html( $chemin ), esc_html( $raison ) );
	}
	echo '</ul>';
	if ( array() !== $defs ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
		echo '<input type="hidden" name="action" value="yume_recreer_pages">';
		wp_nonce_field( 'yume_recreer_pages' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Recréer les pages manquantes', 'yume-core' ) . '</button> ';
		esc_html_e( 'Les pages en brouillon ou privées sont republiées ; les pages absentes ou à la corbeille sont recréées à leur adresse d’origine.', 'yume-core' );
		echo '</p></form>';
	}
	echo '</div>';
}
add_action( 'admin_notices', __NAMESPACE__ . '\\avis_pages_yume' );

/**
 * Action du bouton « Recréer les pages manquantes » (admin-post.php).
 */
function action_recreer_pages_yume(): void {
	if ( ! peut_recreer_pages_yume() ) {
		wp_die( esc_html__( 'Vous n’avez pas le droit de recréer les pages du site.', 'yume-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'yume_recreer_pages' );
	$traites = recreer_pages_yume();
	$retour  = wp_get_referer();
	wp_safe_redirect( add_query_arg( 'yume_pages_recreees', count( $traites ), remove_query_arg( 'yume_pages_recreees', $retour ? $retour : admin_url() ) ) );
	exit;
}
add_action( 'admin_post_yume_recreer_pages', __NAMESPACE__ . '\\action_recreer_pages_yume' );

/**
 * Retire le compte rendu de l'adresse affichée (comme les messages du cœur).
 *
 * @param string[] $args Paramètres retirés.
 * @return string[]
 */
function parametres_retires_pages_yume( array $args ): array {
	$args[] = 'yume_pages_recreees';
	return $args;
}
add_filter( 'removable_query_args', __NAMESPACE__ . '\\parametres_retires_pages_yume' );
