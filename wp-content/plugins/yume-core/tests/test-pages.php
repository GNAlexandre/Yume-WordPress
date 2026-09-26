<?php
/**
 * Tests des pages Yume (contrat §11) : repli de yume_url_page() sur le chemin quand la page
 * enregistrée n'est pas en ligne, avis d'administration des pages manquantes et recréation
 * (includes/core/admin/notices.php).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Migration_Planner;

use function Yume\Core\Core\avis_pages_yume;
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
