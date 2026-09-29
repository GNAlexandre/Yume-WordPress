<?php
/**
 * Tests des pages Yume (contrat §11) : repli de yume_url_page() sur le chemin quand la page
 * enregistrée n'est pas en ligne, avis d'administration des pages manquantes et recréation
 * (includes/core/admin/notices.php), et avis « Prérequis de mise en production » (AMEL-01).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Migration_Planner;

use function Yume\Core\Core\avis_pages_yume;
use function Yume\Core\Core\avis_prerequis_production;
use function Yume\Core\Core\changer_prerequis_masques;
use function Yume\Core\Core\etat_deux_facteurs;
use function Yume\Core\Core\etat_prerequis;
use function Yume\Core\Core\prerequis_manquants;
use function Yume\Core\Core\prerequis_masques;
use function Yume\Core\Core\url_action_prerequis;
use function Yume\Core\Core\pages_yume_manquantes;
use function Yume\Core\Core\peut_recreer_pages_yume;
use function Yume\Core\Core\recreer_pages_yume;

/**
 * Part d'un site sans pages Yume : supprime (dans la transaction du test) les pages aux adresses
 * du contrat et vide yume_pages et les réglages de lecture.
 */
function yume_test_pages_vider(): void {
	$ids = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_name__in'  => array_merge(
				array_column( Migration_Planner::PAGES_A_CREER, 0 ),
				array_map( static fn( $s ) => $s . '__trashed', array_column( Migration_Planner::PAGES_A_CREER, 0 ) )
			),
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	update_option( 'yume_pages', array() );
	update_option( 'show_on_front', 'posts' );
	update_option( 'page_on_front', 0 );
	update_option( 'page_for_posts', 0 );
}

/**
 * Nombre de pages (hors corbeille) au slug donné.
 *
 * @param string $slug Slug.
 */
function yume_test_pages_nombre( string $slug ): int {
	return count(
		get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'name'           => $slug,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		)
	);
}

yume_test(
	'yume_url_page : une page à la corbeille, en brouillon, privée ou sous un parent dépublié renvoie au chemin du contrat',
	function () {
		yume_test_pages_vider();
		$equipe  = yume_factory_post(
			array(
				'post_type' => 'page',
				'post_name' => 'equipe',
			)
		);
		$publier = yume_factory_post(
			array(
				'post_type'   => 'page',
				'post_name'   => 'publier',
				'post_parent' => $equipe,
			)
		);
		$compte  = yume_factory_post(
			array(
				'post_type' => 'page',
				'post_name' => 'compte-a-part',
			)
		);
		update_option(
			'yume_pages',
			array(
				'equipe'  => $equipe,
				'publier' => $publier,
				'compte'  => $compte,
			)
		);
		yume_assert_same( home_url( '/equipe/publier/' ), yume_url_page( 'publier' ) );
		yume_assert_same( home_url( '/compte-a-part/' ), yume_url_page( 'compte' ), 'page publiée : son permalien' );

		wp_update_post(
			array(
				'ID'          => $compte,
				'post_status' => 'private',
			)
		);
		yume_assert_same( home_url( '/compte/' ), yume_url_page( 'compte' ), 'page privée' );
		wp_update_post(
			array(
				'ID'          => $compte,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( home_url( '/compte/' ), yume_url_page( 'compte' ), 'brouillon' );

		wp_trash_post( $equipe );
		yume_assert_same( home_url( '/equipe/' ), yume_url_page( 'equipe' ), 'à la corbeille' );
		yume_assert_same( home_url( '/equipe/publier/' ), yume_url_page( 'publier' ), 'jamais /equipe__trashed/publier/' );
		yume_assert_same( home_url( '/equipe/membres/' ), yume_url_page( 'membres' ), 'clé absente de yume_pages' );
		yume_assert_same( home_url( '/mentions-legales/' ), yume_url_page( 'mentions-legales' ) );
		yume_assert_same( home_url( '/' ), yume_url_page( 'accueil' ) );
	}
);

yume_test(
	'Pages manquantes : aucune avant la migration ; absentes, à la corbeille, non publiées ou détachées de leur parent listées',
	function () {
		yume_test_pages_vider();
		yume_assert_same( array(), pages_yume_manquantes(), 'yume_pages vide : pas encore migré' );

		recreer_pages_yume();
		yume_assert_same( array(), pages_yume_manquantes() );
		$pages = get_option( 'yume_pages' );

		wp_trash_post( $pages['equipe'] );
		wp_update_post(
			array(
				'ID'          => $pages['compte'],
				'post_status' => 'draft',
			)
		);
		wp_delete_post( $pages['mentions-legales'], true );
		unset( $pages['accueil'] );
		update_option( 'yume_pages', $pages );

		$manquantes = pages_yume_manquantes();
		yume_assert_same( array( 'equipe', 'publier', 'membres', 'compte', 'mentions-legales', 'accueil' ), array_keys( $manquantes ) );
		yume_assert_same( 'à la corbeille', $manquantes['equipe'] );
		yume_assert_same( 'page parente absente ou non publiée', $manquantes['publier'] );
		yume_assert_contains( 'non publiée', $manquantes['compte'] );
		yume_assert_same( 'absente', $manquantes['mentions-legales'] );
		yume_assert_same( 'absente', $manquantes['accueil'] );
	}
);

yume_test(
	'Recréer les pages : les dix pages du contrat, mêmes slugs, parents et contenus que la migration, sans doublon',
	function () {
		yume_test_pages_vider();
		$traites = recreer_pages_yume();
		yume_assert_same( array_keys( Migration_Planner::PAGES_A_CREER ), array_keys( $traites ) );
		$pages = get_option( 'yume_pages' );
		foreach ( Migration_Planner::PAGES_A_CREER as $cle => $def ) {
			$post = get_post( $pages[ $cle ] );
			yume_assert_same( 'publish', $post->post_status, $cle );
			yume_assert_same( $def[0], $post->post_name, $cle );
			yume_assert_same( $def[2], $post->post_title, $cle );
			yume_assert_same( '' !== $def[3] ? '<!-- wp:' . $def[3] . ' /-->' : Migration_Planner::contenu_page( $cle ), $post->post_content, $cle );
		}
		yume_assert_same( home_url( '/equipe/membres/' ), get_permalink( $pages['membres'] ) );
		yume_assert_same( home_url( '/equipe/publier/' ), yume_url_page( 'publier' ) );
		yume_assert_contains( 'Automattic', get_post( $pages['mentions-legales'] )->post_content );
		yume_assert_same( (int) $pages['accueil'], (int) get_option( 'page_on_front' ) );
		yume_assert_same( (int) $pages['actualites'], (int) get_option( 'page_for_posts' ) );

		// Relancer ne crée rien.
		yume_assert_same( array(), recreer_pages_yume() );
		yume_assert_same( $pages, get_option( 'yume_pages' ) );
		foreach ( array( 'equipe', 'publier', 'actualites', 'accueil' ) as $slug ) {
			yume_assert_same( 1, yume_test_pages_nombre( $slug ), $slug );
		}
	}
);

yume_test(
	'Recréer les pages : page à la corbeille remplacée, brouillon republié, enfants rattachés au nouveau parent, page existante reprise',
	function () {
		yume_test_pages_vider();
		recreer_pages_yume();
		$avant = get_option( 'yume_pages' );
		wp_trash_post( $avant['equipe'] );
		wp_update_post(
			array(
				'ID'          => $avant['compte'],
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $avant['actualites'] );
		// L'équipe a recréé à la main une page « Mentions légales » à la bonne adresse.
		wp_delete_post( $avant['mentions-legales'], true );
		$main = yume_factory_post(
			array(
				'post_type'    => 'page',
				'post_name'    => 'mentions-legales',
				'post_content' => 'Texte de l’équipe.',
			)
		);

		$traites = recreer_pages_yume();
		yume_assert_same( array( 'equipe', 'publier', 'membres', 'compte', 'actualites', 'mentions-legales' ), array_keys( $traites ) );
		$pages = get_option( 'yume_pages' );
		yume_assert_true( $pages['equipe'] !== $avant['equipe'], 'nouvelle page équipe' );
		yume_assert_same( 'equipe', get_post( $pages['equipe'] )->post_name );
		yume_assert_same( $avant['publier'], $pages['publier'], 'page enfant gardée' );
		yume_assert_same( (int) $pages['equipe'], (int) get_post( $pages['publier'] )->post_parent );
		yume_assert_same( home_url( '/equipe/publier/' ), yume_url_page( 'publier' ) );
		yume_assert_same( home_url( '/equipe/membres/' ), get_permalink( $pages['membres'] ) );
		yume_assert_same( $avant['compte'], $pages['compte'], 'brouillon republié' );
		yume_assert_same( 'publish', get_post_status( $pages['compte'] ) );
		yume_assert_same( $main, $pages['mentions-legales'], 'page existante reprise' );
		yume_assert_same( 'Texte de l’équipe.', get_post( $main )->post_content );
		yume_assert_same( (int) $pages['actualites'], (int) get_option( 'page_for_posts' ), 'page des articles reportée' );
		yume_assert_same( 'trash', get_post_status( $avant['equipe'] ), 'ancienne page laissée à la corbeille' );
		yume_assert_same( array(), pages_yume_manquantes() );
		yume_assert_same( 1, yume_test_pages_nombre( 'mentions-legales' ) );
	}
);

yume_test(
	'Avis d’administration : réservé aux réglages (administrateur, gérant), bouton « Recréer les pages manquantes » protégé par nonce',
	function () {
		yume_test_pages_vider();
		recreer_pages_yume();
		$pages = get_option( 'yume_pages' );
		wp_trash_post( $pages['planning'] );

		$courant = get_current_user_id();
		wp_set_current_user( yume_factory_user( 'subscriber' ) );
		yume_assert_false( peut_recreer_pages_yume() );
		ob_start();
		avis_pages_yume();
		yume_assert_same( '', ob_get_clean(), 'lecteur : rien' );

		wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
		yume_assert_false( peut_recreer_pages_yume(), 'éditeur : pas les réglages' );

		wp_set_current_user( yume_factory_user( 'yume_gerant' ) );
		yume_assert_true( peut_recreer_pages_yume() );

		wp_set_current_user( yume_factory_user( 'administrator' ) );
		ob_start();
		avis_pages_yume();
		$html = (string) ob_get_clean();
		yume_assert_contains( 'Pages Yume manquantes', $html );
		yume_assert_contains( 'Planning (<code>/planning/</code>) : à la corbeille', $html );
		yume_assert_contains( 'Recréer les pages manquantes', $html );
		yume_assert_contains( 'name="action" value="yume_recreer_pages"', $html );
		yume_assert_contains( 'name="_wpnonce"', $html );
		yume_assert_not_contains( '/bibliotheque/', $html );
		yume_assert_true( false !== has_action( 'admin_post_yume_recreer_pages' ) );

		recreer_pages_yume();
		ob_start();
		avis_pages_yume();
		yume_assert_same( '', ob_get_clean(), 'plus rien à signaler' );
		wp_set_current_user( $courant );
	}
);

/**
 * État « prêt pour la production » (aucun prérequis manquant), modifiable par clé.
 *
 * @param array $modifs Clés à changer.
 */
function yume_test_prerequis_etat( array $modifs = array() ): array {
	return array_merge(
		array(
			'langue'           => 'fr_FR',
			'wp_debug'         => false,
			'wp_debug_display' => false,
			'script_debug'     => false,
			'debug_log'        => false,
			'deux_facteurs'    => 'active',
			'inscriptions'     => true,
			'role_defaut'      => 'subscriber',
			'blog_public'      => true,
			'xmlrpc'           => true,
			'jetpack'          => true,
		),
		$modifs
	);
}

/**
 * Affiche l'avis des prérequis pour un état simulé, sur un écran d'administration donné, et
 * renvoie le HTML.
 *
 * @param array  $etat  État simulé (filtre yume_prerequis_etat).
 * @param string $ecran Identifiant de l'écran courant.
 */
function yume_test_prerequis_avis( array $etat, string $ecran = 'dashboard' ): string {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	$avant                     = $GLOBALS['current_screen'] ?? null;
	$GLOBALS['current_screen'] = WP_Screen::get( $ecran ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- écran simulé, rétabli plus bas.
	$filtre                    = static fn() => $etat;
	add_filter( 'yume_prerequis_etat', $filtre );
	ob_start();
	avis_prerequis_production();
	$html = (string) ob_get_clean();
	remove_filter( 'yume_prerequis_etat', $filtre );
	$GLOBALS['current_screen'] = $avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	return $html;
}

yume_test(
	'Prérequis de mise en production : langue, débogage, 2FA, rôle par défaut, indexation, XML-RPC sans Jetpack',
	function () {
		yume_assert_same( array(), prerequis_manquants( yume_test_prerequis_etat() ), 'site prêt : rien à signaler' );

		$cles = static fn( array $modifs ) => array_keys( prerequis_manquants( yume_test_prerequis_etat( $modifs ) ) );
		yume_assert_same( array( 'langue' ), $cles( array( 'langue' => 'en_US' ) ) );
		yume_assert_contains( '« en_US »', prerequis_manquants( yume_test_prerequis_etat( array( 'langue' => 'en_US' ) ) )['langue'] );
		yume_assert_same( array( 'wp_debug' ), $cles( array( 'wp_debug' => true ) ) );
		yume_assert_contains(
			'WP_DEBUG_DISPLAY',
			prerequis_manquants(
				yume_test_prerequis_etat(
					array(
						'wp_debug'         => true,
						'wp_debug_display' => true,
					)
				)
			)['wp_debug']
		);
		yume_assert_same( array( 'script_debug' ), $cles( array( 'script_debug' => true ) ) );
		yume_assert_same( array( 'debug_log' ), $cles( array( 'debug_log' => true ) ) );
		yume_assert_same( array( 'deux_facteurs' ), $cles( array( 'deux_facteurs' => 'absente' ) ) );
		yume_assert_same( array( 'deux_facteurs_rappel' ), $cles( array( 'deux_facteurs' => 'inconnue' ) ) );
		yume_assert_same( array( 'role_defaut' ), $cles( array( 'role_defaut' => 'administrator' ) ) );
		yume_assert_contains( '« yume_editeur »', prerequis_manquants( yume_test_prerequis_etat( array( 'role_defaut' => 'yume_editeur' ) ) )['role_defaut'] );
		yume_assert_same(
			array(),
			$cles(
				array(
					'inscriptions' => false,
					'role_defaut'  => 'administrator',
				)
			),
			'inscriptions fermées : rôle sans effet'
		);
		yume_assert_same( array( 'blog_public' ), $cles( array( 'blog_public' => false ) ) );
		yume_assert_same( array( 'xmlrpc' ), $cles( array( 'jetpack' => false ) ) );
		yume_assert_same(
			array(),
			$cles(
				array(
					'jetpack' => false,
					'xmlrpc'  => false,
				)
			)
		);

		// État réel observé : réglages du cœur lus en direct.
		update_option( 'WPLANG', 'fr_FR' );
		update_option( 'users_can_register', 1 );
		update_option( 'default_role', 'editor' );
		update_option( 'blog_public', '0' );
		$etat = etat_prerequis();
		yume_assert_true( $etat['inscriptions'] );
		yume_assert_same( 'editor', $etat['role_defaut'] );
		yume_assert_false( $etat['blog_public'] );
		yume_assert_same( defined( 'WP_DEBUG' ) && WP_DEBUG, $etat['wp_debug'] );
		yume_assert_same( get_locale(), $etat['langue'] );
		$manquants = prerequis_manquants( $etat );
		yume_assert_true( isset( $manquants['role_defaut'], $manquants['blog_public'] ) );
		update_option( 'default_role', 'subscriber' );
		update_option( 'blog_public', '1' );
		$manquants = prerequis_manquants( etat_prerequis() );
		yume_assert_false( isset( $manquants['role_defaut'] ) || isset( $manquants['blog_public'] ) );

		// Sans Two-Factor ni Jetpack SSO : 2FA non détectable, simple rappel.
		if ( ! class_exists( 'Two_Factor_Core' ) && ! class_exists( 'Jetpack' ) ) {
			yume_assert_same( 'inconnue', etat_deux_facteurs( get_current_user_id() ) );
		}
	}
);

yume_test(
	'Avis des prérequis : administrateurs seulement (manage_options), lien vers la liste de mise en production',
	function () {
		$courant = get_current_user_id();
		$etat    = yume_test_prerequis_etat(
			array(
				'langue'   => 'en_US',
				'wp_debug' => true,
			)
		);
		foreach ( array( 'subscriber', 'yume_editeur', 'yume_gerant' ) as $role ) {
			wp_set_current_user( yume_factory_user( $role ) );
			yume_assert_same( '', yume_test_prerequis_avis( $etat ), $role . ' : rien' );
		}
		wp_set_current_user( yume_factory_user( 'administrator' ) );
		$html = yume_test_prerequis_avis( $etat );
		yume_assert_contains( 'Prérequis de mise en production', $html );
		yume_assert_contains( 'data-prerequis="langue"', $html );
		yume_assert_contains( 'data-prerequis="wp_debug"', $html );
		yume_assert_not_contains( 'data-prerequis="blog_public"', $html );
		yume_assert_contains( 'https://github.com/GNAlexandre/Yume-WordPress/blob/main/docs/mise-en-production.md', $html );
		yume_assert_contains( esc_url( url_action_prerequis( 'masquer' ) ), $html );
		yume_assert_contains( '_wpnonce=', url_action_prerequis( 'masquer' ) );
		yume_assert_same( '', yume_test_prerequis_avis( yume_test_prerequis_etat() ), 'site prêt : pas d’avis' );
		yume_assert_true( false !== has_action( 'admin_post_yume_prerequis' ) );
		yume_assert_true( false !== has_action( 'admin_notices', 'Yume\\Core\\Core\\avis_prerequis_production' ) );
		wp_set_current_user( $courant );
	}
);

yume_test(
	'Avis des prérequis : masqué par administrateur (méta), un nouveau prérequis réapparaît, « Revoir » sur le tableau de bord Yume',
	function () {
		$courant = get_current_user_id();
		$admin   = yume_factory_user( 'administrator' );
		$autre   = yume_factory_user( 'administrator' );
		wp_set_current_user( $admin );
		$etat   = yume_test_prerequis_etat( array( 'langue' => 'en_US' ) );
		$filtre = static fn() => $etat;
		add_filter( 'yume_prerequis_etat', $filtre );
		changer_prerequis_masques( 'masquer' );
		remove_filter( 'yume_prerequis_etat', $filtre );
		yume_assert_same( array( 'langue' ), prerequis_masques( $admin ) );
		yume_assert_same( '', yume_test_prerequis_avis( $etat ), 'rappel masqué' );

		// Un autre prérequis manquant apparaît : seul lui est affiché.
		$html = yume_test_prerequis_avis(
			yume_test_prerequis_etat(
				array(
					'langue'      => 'en_US',
					'blog_public' => false,
				)
			)
		);
		yume_assert_contains( 'data-prerequis="blog_public"', $html );
		yume_assert_not_contains( 'data-prerequis="langue"', $html );

		// Masqué pour cet administrateur seulement.
		wp_set_current_user( $autre );
		yume_assert_contains( 'data-prerequis="langue"', yume_test_prerequis_avis( $etat ) );

		// Tableau de bord Yume : lien « Revoir » quand des rappels sont masqués.
		wp_set_current_user( $admin );
		$html = yume_test_prerequis_avis( $etat, 'toplevel_page_yume' );
		yume_assert_contains( '1 prérequis de mise en production masqué.', $html );
		yume_assert_contains( esc_url( url_action_prerequis( 'revoir' ) ), $html );
		yume_assert_same( '', yume_test_prerequis_avis( $etat ), 'ailleurs : pas de rappel « Revoir »' );
		yume_assert_same( '', yume_test_prerequis_avis( yume_test_prerequis_etat( array( 'wp_debug' => true ) ), 'edit-post' ), 'pas sur les écrans d’édition' );
		yume_assert_contains( 'data-prerequis="wp_debug"', yume_test_prerequis_avis( yume_test_prerequis_etat( array( 'wp_debug' => true ) ), 'yume_page_yume-reglages' ) );

		changer_prerequis_masques( 'revoir' );
		yume_assert_same( array(), prerequis_masques( $admin ) );
		yume_assert_contains( 'data-prerequis="langue"', yume_test_prerequis_avis( $etat ) );
		wp_set_current_user( $courant );
	}
);

yume_test(
	'BUG-11 : lien « Contactez-nous » du pied de page vers la page enregistrée (yume_pages) ou au slug',
	function () {
		if ( ! function_exists( 'yume_theme_lien' ) ) {
			return; // Thème Yume inactif.
		}
		// Page enregistrée sous la clé contactez-nous, à un autre slug : elle l'emporte sur le slug.
		$page                    = yume_factory_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Nous écrire',
				'post_name'   => 'nous-ecrire-test',
			)
		);
		$pages                   = get_option( 'yume_pages', array() );
		$pages                   = is_array( $pages ) ? $pages : array();
		$pages['contactez-nous'] = $page;
		update_option( 'yume_pages', $pages );
		yume_assert_same( get_permalink( $page ), yume_theme_lien( 'contact' ) );

		// Rendu du lien de navigation : adresse réelle, classe conservée ; l'en-tête garde le Discord.
		$html = do_blocks( '<!-- wp:navigation-link {"label":"Contactez-nous","url":"/contactez-nous/","kind":"custom","isTopLevelLink":true,"className":"yn-lien-contact"} /-->' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $page ) ) . '"', $html );
		yume_assert_same( 'discord', yume_theme_cle_lien_depuis_classes( 'yn-lien-discord yn-lien-contact' ), 'en-tête : « Contact » → Discord' );
	}
);
