<?php
/**
 * Accès à l'administration des membres de l'équipe sans droit de rédaction (traducteur,
 * relecteur, graphiste : yume_voir_equipe sans edit_others_yume_tomes ni edit_posts).
 *
 * Leur travail se fait dans l'espace équipe (/equipe/) : wp-admin les y renvoie, sauf leur
 * profil et les points d'entrée techniques (admin-post.php, admin-ajax.php,
 * async-upload.php) ; le menu Yume leur est masqué et le lien « Tableau de bord » de la
 * barre d'administration mène à l'espace équipe. Éditeurs, gérants et administrateurs ne
 * sont pas concernés.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * L'utilisateur est-il un membre de l'équipe sans accès à la rédaction ?
 *
 * @param int $user_id Utilisateur (0 : utilisateur courant).
 */
function equipe_sans_redaction( int $user_id = 0 ): bool {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id || ! user_can( $user_id, 'yume_voir_equipe' ) ) {
		return false;
	}
	foreach ( array( 'edit_others_yume_tomes', 'edit_posts', 'manage_options' ) as $cap ) {
		if ( user_can( $user_id, $cap ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Adresse de l'espace équipe (page « equipe »), ou l'accueil à défaut.
 */
function url_espace_equipe(): string {
	return function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
}

/**
 * Pages d'administration qui restent accessibles.
 */
function administration_equipe_autorisee(): bool {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return true;
	}
	$page = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	return in_array( $page, array( 'profile.php', 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true );
}

/**
 * Administration (wp-admin) : renvoi vers l’espace équipe.
 */
function rediriger_equipe_sans_redaction(): void {
	if ( ! is_user_logged_in() || ! equipe_sans_redaction() || administration_equipe_autorisee() ) {
		return;
	}
	wp_safe_redirect( url_espace_equipe() );
	exit;
}
add_action( 'admin_init', __NAMESPACE__ . '\\rediriger_equipe_sans_redaction', 1 );

/**
 * Menu d'administration (profil) : ni menu Yume, ni tableau de bord, ni médiathèque, qui
 * renverraient tous vers l'espace équipe.
 */
function masquer_menus_equipe(): void {
	if ( ! equipe_sans_redaction() ) {
		return;
	}
	remove_menu_page( 'yume' );
	remove_menu_page( 'index.php' );
	remove_menu_page( 'upload.php' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\masquer_menus_equipe', 1000 );

/**
 * Barre d'administration : « Tableau de bord » (et le nom du site en façade) mènent à
 * l'espace équipe ; « Créer » est retiré.
 *
 * @param \WP_Admin_Bar $barre Barre d'administration.
 */
function barre_equipe( $barre ): void {
	if ( ! $barre instanceof \WP_Admin_Bar || ! equipe_sans_redaction() ) {
		return;
	}
	$equipe = url_espace_equipe();
	$noeud  = $barre->get_node( 'dashboard' );
	if ( $noeud ) {
		$barre->add_node(
			array(
				'id'     => 'dashboard',
				'parent' => $noeud->parent,
				'title'  => __( 'Espace équipe', 'yume-core' ),
				'href'   => $equipe,
			)
		);
	}
	$site = $barre->get_node( 'site-name' );
	if ( $site && ! is_admin() ) {
		$barre->add_node(
			array(
				'id'   => 'site-name',
				'href' => $equipe,
			)
		);
	}
	$barre->remove_node( 'new-content' );
}
add_action( 'admin_bar_menu', __NAMESPACE__ . '\\barre_equipe', 100 );
