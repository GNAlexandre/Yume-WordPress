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

/*
 * Prérequis de mise en production (audit AMEL-01, SEC-14 ; docs/mise-en-production.md) : avis
 * réservé aux administrateurs (manage_options) qui liste les réglages du site ou du compte à
 * corriger avant l'ouverture au public. Chaque rappel masqué l'est pour l'administrateur qui l'a
 * masqué seulement (méta utilisateur), et le lien « Revoir » du tableau de bord Yume le fait
 * réapparaître.
 */

/**
 * Adresse de la liste de mise en production (branche principale du dépôt).
 */
const URL_DOC_MISE_EN_PRODUCTION = 'https://github.com/GNAlexandre/Yume-WordPress/blob/main/docs/mise-en-production.md';

/**
 * Méta utilisateur : clés des rappels masqués par l'administrateur.
 */
const META_PREREQUIS_MASQUES = 'yume_prerequis_masques';

/**
 * État du site et du compte courant observé pour les prérequis. Le filtre
 * « yume_prerequis_etat » permet de le compléter (ou aux tests de simuler des constantes).
 *
 * @return array{langue:string,wp_debug:bool,wp_debug_display:bool,script_debug:bool,debug_log:bool,deux_facteurs:string,inscriptions:bool,role_defaut:string,blog_public:bool,xmlrpc:bool,jetpack:bool}
 */
function etat_prerequis(): array {
	$wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$etat     = array(
		'langue'           => (string) get_locale(),
		'wp_debug'         => $wp_debug,
		// WP_DEBUG_DISPLAY (vrai par défaut) n'a d'effet que si WP_DEBUG est actif.
		'wp_debug_display' => $wp_debug && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
		'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
		'debug_log'        => is_file( WP_CONTENT_DIR . '/debug.log' ),
		'deux_facteurs'    => etat_deux_facteurs( get_current_user_id() ),
		'inscriptions'     => (bool) get_option( 'users_can_register' ),
		'role_defaut'      => (string) get_option( 'default_role', 'subscriber' ),
		'blog_public'      => '0' !== (string) get_option( 'blog_public', '1' ),
		'xmlrpc'           => (bool) apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
		'jetpack'          => class_exists( 'Jetpack' ) || defined( 'JETPACK__VERSION' ),
	);
	/**
	 * Filtre l'état observé pour l'avis « Prérequis de mise en production ».
	 *
	 * @param array $etat État (voir etat_prerequis()).
	 */
	return (array) apply_filters( 'yume_prerequis_etat', $etat ) + $etat;
}

/**
 * Double authentification du compte : 'active' (extension Two-Factor configurée pour ce compte,
 * ou Jetpack SSO qui exige la 2FA WordPress.com), 'absente' (Two-Factor installée mais pas
 * configurée pour ce compte), 'inconnue' (rien de détectable : simple rappel).
 *
 * @param int $user_id Utilisateur.
 */
function etat_deux_facteurs( int $user_id ): string {
	if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
		if ( \Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
			return 'active';
		}
		return 'absente';
	}
	$sso = class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'sso' );
	if ( $sso && get_option( 'jetpack_sso_require_two_step' ) ) {
		return 'active';
	}
	return 'inconnue';
}

/**
 * Prérequis non remplis pour un état donné.
 *
 * @param array $etat État (etat_prerequis()).
 * @return array<string,string> Clé => message (texte brut).
 */
function prerequis_manquants( array $etat ): array {
	$manquants = array();
	if ( 'fr_FR' !== ( $etat['langue'] ?? '' ) ) {
		/* translators: %s: code de langue (en_US…). */
		$manquants['langue'] = sprintf( __( 'La langue du site est « %s » : choisissez « Français » (fr_FR) dans Réglages → Général, sinon les dates, les écrans du cœur et les e-mails ne sont pas en français.', 'yume-core' ), (string) ( $etat['langue'] ?? '' ) );
	}
	if ( ! empty( $etat['wp_debug'] ) ) {
		$manquants['wp_debug'] = ! empty( $etat['wp_debug_display'] )
			? __( 'WP_DEBUG et WP_DEBUG_DISPLAY sont actifs : les erreurs PHP (chemins, requêtes) s’affichent aux visiteurs. Mettez-les à false dans wp-config.php.', 'yume-core' )
			: __( 'WP_DEBUG est actif : mettez-le à false dans wp-config.php en production.', 'yume-core' );
	}
	if ( ! empty( $etat['debug_log'] ) ) {
		$manquants['debug_log'] = __( 'Le fichier wp-content/debug.log existe : supprimez-le (SFTP) et désactivez WP_DEBUG_LOG, il peut être lisible depuis le Web.', 'yume-core' );
	}
	if ( ! empty( $etat['script_debug'] ) ) {
		$manquants['script_debug'] = __( 'SCRIPT_DEBUG est actif : les scripts non minifiés sont servis. Retirez-le de wp-config.php.', 'yume-core' );
	}
	$deux_facteurs = (string) ( $etat['deux_facteurs'] ?? 'inconnue' );
	if ( 'absente' === $deux_facteurs ) {
		$manquants['deux_facteurs'] = __( 'Votre compte administrateur n’a pas de double authentification : configurez-la dans Profil → Options de double authentification.', 'yume-core' );
	} elseif ( 'active' !== $deux_facteurs ) {
		$manquants['deux_facteurs_rappel'] = __( 'Double authentification non détectable ici : vérifiez qu’elle est activée sur votre compte WordPress.com et exigée pour les administrateurs (Jetpack → Réglages → Sécurité → « Exiger la validation en deux étapes ») ou installez l’extension Two-Factor.', 'yume-core' );
	}
	$role = (string) ( $etat['role_defaut'] ?? 'subscriber' );
	if ( ! empty( $etat['inscriptions'] ) && 'subscriber' !== $role ) {
		/* translators: %s: rôle par défaut. */
		$manquants['role_defaut'] = sprintf( __( 'Les inscriptions sont ouvertes avec le rôle par défaut « %s » : tout visiteur qui crée un compte l’obtient. Choisissez « Abonné » dans Réglages → Général.', 'yume-core' ), $role );
	}
	if ( array_key_exists( 'blog_public', $etat ) && ! $etat['blog_public'] ) {
		$manquants['blog_public'] = __( 'Le site demande aux moteurs de recherche de ne pas l’indexer : décochez l’option dans Réglages → Lecture (ou rendez le site public sur WordPress.com).', 'yume-core' );
	}
	if ( ! empty( $etat['xmlrpc'] ) && empty( $etat['jetpack'] ) ) {
		$manquants['xmlrpc'] = __( 'XML-RPC est actif alors que Jetpack n’est pas installé : personne n’en a besoin, désactivez-le (cible des attaques par force brute).', 'yume-core' );
	}
	return $manquants;
}

/**
 * Clés des rappels masqués par un utilisateur.
 *
 * @param int $user_id Utilisateur.
 * @return string[]
 */
function prerequis_masques( int $user_id ): array {
	$masques = get_user_meta( $user_id, META_PREREQUIS_MASQUES, true );
	return is_array( $masques ) ? array_values( array_filter( $masques, 'is_string' ) ) : array();
}

/**
 * L'avis doit-il être affiché sur cet écran ? Tableau de bord, écrans Yume, extensions et
 * réglages (pas sur chaque écran d'édition, ni sur Yume → Santé, qui détaille déjà les
 * prérequis).
 */
function ecran_prerequis(): bool {
	$ecran = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $ecran ) {
		return true;
	}
	$id = (string) $ecran->id;
	if ( 'yume_page_' . PAGE_SANTE === $id ) {
		return false;
	}
	return in_array( $id, array( 'dashboard', 'plugins', 'toplevel_page_yume', 'users', 'profile' ), true )
		|| str_starts_with( $id, 'yume_page_' ) || str_starts_with( $id, 'options-' );
}

/**
 * Adresse d'une action de l'avis (masquer ou revoir), protégée par nonce.
 *
 * @param string $faire 'masquer' ou 'revoir'.
 */
function url_action_prerequis( string $faire ): string {
	$args = array(
		'action' => 'yume_prerequis',
		'faire'  => $faire,
	);
	return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'yume_prerequis_' . $faire );
}

/**
 * Avis « Prérequis de mise en production ».
 */
function avis_prerequis_production(): void {
	if ( ! current_user_can( 'manage_options' ) || ! ecran_prerequis() ) {
		return;
	}
	$manquants = prerequis_manquants( etat_prerequis() );
	$masques   = prerequis_masques( get_current_user_id() );
	$visibles  = array_diff_key( $manquants, array_flip( $masques ) );
	$ecran     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( array() === $visibles ) {
		$caches = count( array_intersect_key( $manquants, array_flip( $masques ) ) );
		if ( $caches && $ecran && 'toplevel_page_yume' === $ecran->id ) {
			printf(
				'<div class="notice notice-info yume-prerequis-masques"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: nombre de rappels. */
						_n( '%d prérequis de mise en production masqué.', '%d prérequis de mise en production masqués.', $caches, 'yume-core' ),
						$caches
					)
				),
				esc_url( url_action_prerequis( 'revoir' ) ),
				esc_html__( 'Revoir', 'yume-core' )
			);
		}
		return;
	}
	echo '<div class="notice notice-warning yume-prerequis"><p><strong>' . esc_html__( 'Prérequis de mise en production', 'yume-core' ) . '</strong> — ';
	esc_html_e( 'réglages à corriger avant d’ouvrir le site au public :', 'yume-core' );
	echo '</p><ul style="list-style:disc;margin-left:2em">';
	foreach ( $visibles as $cle => $message ) {
		printf( '<li data-prerequis="%1$s">%2$s</li>', esc_attr( $cle ), esc_html( $message ) );
	}
	echo '</ul><p>';
	printf(
		'<a href="%1$s">%2$s</a> · <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a> · <a href="%5$s">%6$s</a>',
		esc_url( url_sante() . '#yn-sante-prerequis' ),
		esc_html__( 'Détail dans la santé du site (espace équipe)', 'yume-core' ),
		esc_url( URL_DOC_MISE_EN_PRODUCTION ),
		esc_html__( 'Liste de mise en production (docs/mise-en-production.md)', 'yume-core' ),
		esc_url( url_action_prerequis( 'masquer' ) ),
		esc_html__( 'Masquer ces rappels pour mon compte', 'yume-core' )
	);
	echo '</p></div>';
}
add_action( 'admin_notices', __NAMESPACE__ . '\\avis_prerequis_production' );

/**
 * Masque (pour l'utilisateur courant) les rappels affichés, ou les fait réapparaître.
 *
 * @param string $faire 'masquer' ou 'revoir'.
 */
function changer_prerequis_masques( string $faire ): void {
	$user_id = get_current_user_id();
	if ( 'revoir' === $faire ) {
		delete_user_meta( $user_id, META_PREREQUIS_MASQUES );
		return;
	}
	$cles = array_keys( prerequis_manquants( etat_prerequis() ) );
	update_user_meta( $user_id, META_PREREQUIS_MASQUES, array_values( array_unique( array_merge( prerequis_masques( $user_id ), $cles ) ) ) );
}

/**
 * Action des liens « Masquer ces rappels » et « Revoir » (admin-post.php).
 */
function action_prerequis(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous, propre à chaque action.
	$faire = isset( $_GET['faire'] ) && 'revoir' === $_GET['faire'] ? 'revoir' : 'masquer';
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Vous n’avez pas le droit de modifier ces rappels.', 'yume-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'yume_prerequis_' . $faire );
	changer_prerequis_masques( $faire );
	$retour = wp_get_referer();
	wp_safe_redirect( $retour ? $retour : admin_url( 'admin.php?page=yume' ) );
	exit;
}
add_action( 'admin_post_yume_prerequis', __NAMESPACE__ . '\\action_prerequis' );
