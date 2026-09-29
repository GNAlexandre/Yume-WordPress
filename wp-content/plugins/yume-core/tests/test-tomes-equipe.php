<?php
/**
 * Tests de la vue « Tous les tomes » de l'espace équipe (?vue=tomes, includes/planning/
 * tomes-equipe.php) : droits (yume_publier), listing (publiés, programmés, brouillons, par
 * œuvre), filtres œuvre / statut / recherche, pagination, liens d'action, entrées de navigation
 * (tableau de bord, formulaire de publication), section « Tomes en préparation » du tableau de
 * bord ; remplacement de la lecture en ligne d'un tome déjà paru par le formulaire de
 * publication (?tome=ID) : chapitres mis à jour sans doublon, chapitres disparus signalés, sans
 * nouvelle annonce ni changement de la date de sortie ; remplacement en deux temps (version en
 * attente : brouillon, « Vérifier » et aperçu ne changent rien en ligne, aperçu réservé à l'équipe ;
 * « Remplacer » appliqué en place ; annulation sans image orpheline ; un seul remplacement en
 * attente par tome ; nettoyage après 7 jours ; tomes non publiés mis à jour directement).
 *
 * Lancement : tools/localenv/test.sh tomes-equipe
 *
 * @package Yume\Core
 */

use Yume\Core\Publication\Annonce;
use Yume\Core\Publication\Formulaire;
use Yume\Core\Publication\Remplacement;
use Yume\Core\Publication\Service;

use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_timp_fixture' ) ) {
	// Fonctions d'aide (fixtures) sans les tests du module import.
	$GLOBALS['yume_tests_import_aides_seules'] = true;
	require __DIR__ . '/test-import.php';
	unset( $GLOBALS['yume_tests_import_aides_seules'] );
}

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tte_)
 * -----------------------------------------------------------------------------
 */

/**
 * Test isolé des contenus existants (œuvres, tomes, chapitres vidés dans la transaction du test,
 * annulée ensuite par le lanceur) ; fichiers locaux acceptés comme téléversés, pièces jointes
 * créées supprimées du disque à la fin.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps (reçoit le contexte : medias, fichiers).
 */
function yume_tte_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$ctx           = new stdClass();
			$ctx->medias   = array();
			$ctx->fichiers = array();
			$suivre        = static function ( $id ) use ( $ctx ) {
				$ctx->medias[] = (int) $id;
			};
			add_filter( 'yume_publication_fichier_local', '__return_true' );
			add_action( 'add_attachment', $suivre );
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps( $ctx );
			} finally {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				remove_filter( 'yume_publication_fichier_local', '__return_true' );
				remove_action( 'add_attachment', $suivre );
				foreach ( $ctx->medias as $id ) {
					wp_delete_attachment( $id, true );
				}
				foreach ( $ctx->fichiers as $f ) {
					if ( is_file( $f ) ) {
						wp_delete_file( $f );
					}
				}
				unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
				delete_transient( Formulaire::RETOUR . get_current_user_id() );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Crée un membre de l'équipe.
 *
 * @param string $role Rôle.
 */
function yume_tte_membre( string $role ): int {
	return yume_factory_user( $role );
}

/**
 * Crée une œuvre publiée.
 *
 * @param string $titre Titre.
 */
function yume_tte_oeuvre( string $titre ): int {
	return yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => $titre,
		)
	);
}

/**
 * Crée un tome (sans notification) ; publié : daté d'il y a un mois (tome paru).
 *
 * @param int    $oeuvre Œuvre.
 * @param int    $numero Numéro.
 * @param string $statut Statut.
 */
function yume_tte_tome( int $oeuvre, int $numero, string $statut = 'publish' ): int {
	$args = array(
		'post_type'   => 'yume_tome',
		'post_title'  => get_the_title( $oeuvre ) . ' — Tome ' . $numero,
		'post_name'   => 'tome-' . $numero,
		'post_status' => $statut,
		'meta_input'  => array(
			'yume_oeuvre_id' => $oeuvre,
			'yume_numero'    => $numero,
			'yume_nature'    => 'tome',
			'yume_lien_pdf'  => 'https://www.clictune.com/pdf' . $numero,
		),
	);
	if ( 'publish' === $statut ) {
		$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$args['post_date']     = get_date_from_gmt( $args['post_date_gmt'] );
	} elseif ( 'future' === $statut ) {
		$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$args['post_date']     = get_date_from_gmt( $args['post_date_gmt'] );
	}
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post( $args );
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Chapitre d'un tome (sans notification).
 *
 * @param int    $tome   Tome.
 * @param int    $numero Numéro.
 * @param string $statut Statut.
 */
function yume_tte_chapitre( int $tome, int $numero, string $statut = 'publish' ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => 'Chapitre ' . $numero,
				'post_status'  => $statut,
				'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id' => $tome,
					'yume_numero'  => $numero,
					'yume_nature'  => 'chapitre',
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Rend l'espace équipe avec des paramètres GET, en tant qu'utilisateur.
 *
 * @param int   $user_id Utilisateur.
 * @param array $get     Paramètres GET.
 */
function yume_tte_rendu( int $user_id, array $get = array() ): string {
	$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return yume_render_block( 'yume/team-dashboard' );
}

/**
 * Libellés et cibles des entrées de la navigation de l'espace équipe.
 *
 * @param string $html HTML.
 * @return array<string,array{0:string,1:string}> libellé => [href, attributs].
 */
function yume_tte_nav( string $html ): array {
	preg_match( '#<nav class="yn-team__nav".*?</nav>#s', $html, $m );
	preg_match_all( '#<li><a href="([^"]*)"([^>]*)>([^<]*)#', $m[0] ?? '', $liens, PREG_SET_ORDER );
	$nav = array();
	foreach ( $liens as $l ) {
		$nav[ html_entity_decode( trim( $l[3] ), ENT_QUOTES, 'UTF-8' ) ] = array( html_entity_decode( $l[1], ENT_QUOTES, 'UTF-8' ), $l[2] );
	}
	return $nav;
}

/**
 * Copie une fixture dans un fichier temporaire et renvoie l'entrée $_FILES correspondante.
 *
 * @param stdClass $ctx     Contexte.
 * @param string   $fixture Fixture.
 * @return array<string,mixed>
 */
function yume_tte_fichier( stdClass $ctx, string $fixture ): array {
	$tmp = wp_tempnam( 'yume-test' );
	copy( yume_timp_fixture( $fixture ), $tmp );
	$ctx->fichiers[] = $tmp;
	return array(
		'name'     => $fixture,
		'type'     => 'application/octet-stream',
		'tmp_name' => $tmp,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $tmp ),
	);
}

/**
 * Compte, pendant $corps, les événements de sortie, alertes, messages Discord, e-mails et
 * articles créés.
 *
 * @param callable $corps Corps.
 * @return stdClass tome, chap, alertes, discord, mails, articles.
 */
function yume_tte_compter( callable $corps ): stdClass {
	$n           = new stdClass();
	$n->tome     = array();
	$n->chap     = array();
	$n->alertes  = array();
	$n->discord  = array();
	$n->mails    = 0;
	$n->articles = array();
	$tome        = static function ( $id ) use ( $n ) {
		$n->tome[] = (int) $id;
	};
	$chap        = static function ( $id ) use ( $n ) {
		$n->chap[] = (int) $id;
	};
	$alertes     = static function ( $id ) use ( $n ) {
		$n->alertes[] = (int) $id;
	};
	$mail        = static function ( $pre ) use ( $n ) {
		++$n->mails;
		return $pre;
	};
	$article     = static function ( $id, $post ) use ( $n ) {
		if ( 'post' === $post->post_type ) {
			$n->articles[] = (int) $id;
		}
	};
	$http        = static function ( $pre, $args, $url ) use ( $n ) {
		if ( str_contains( (string) $url, 'discord.com' ) ) {
			$n->discord[] = (string) $url;
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 204,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return $pre;
	};
	$reglages    = get_option( 'yume_reglages', array() );
	update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), array( 'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/sorties' ) ) );
	add_action( 'yume_tome_publie', $tome, 1 );
	add_action( 'yume_chapitre_publie', $chap, 1 );
	add_action( 'yume_alertes_envoyees', $alertes, 1 );
	add_filter( 'pre_wp_mail', $mail, 1 );
	add_action( 'wp_insert_post', $article, 10, 2 );
	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		$corps();
	} finally {
		remove_action( 'yume_tome_publie', $tome, 1 );
		remove_action( 'yume_chapitre_publie', $chap, 1 );
		remove_action( 'yume_alertes_envoyees', $alertes, 1 );
		remove_filter( 'pre_wp_mail', $mail, 1 );
		remove_action( 'wp_insert_post', $article, 10 );
		remove_filter( 'pre_http_request', $http, 10 );
		update_option( 'yume_reglages', $reglages );
	}
	return $n;
}

/**
 * Envoie le formulaire de publication sans JavaScript (admin-post) et renvoie le message de
 * retour mémorisé.
 *
 * @param stdClass            $ctx     Contexte.
 * @param array<string,mixed> $post    Champs du formulaire.
 * @param string              $fixture Fichier source ('' : aucun fichier).
 * @return array<string,mixed>
 */
function yume_tte_formulaire( stdClass $ctx, array $post, string $fixture ): array {
	$redirige = static function ( $url ) {
		throw new RuntimeException( 'redirection:' . $url );
	};
	add_filter( 'wp_redirect', $redirige, 1 );
	$_POST  = array_merge(
		array(
			'action'      => 'yume_publication',
			'_yume_nonce' => wp_create_nonce( 'yume_publication' ),
			'etape'       => 'publier',
		),
		$post
	);
	$_FILES = '' !== $fixture ? array( 'source' => yume_tte_fichier( $ctx, $fixture ) ) : array();
	try {
		Formulaire::traiter();
	} catch ( RuntimeException $e ) {
		yume_assert_contains( 'redirection:', $e->getMessage() );
		$GLOBALS['yume_tte_redirection'] = substr( $e->getMessage(), strlen( 'redirection:' ) );
	} finally {
		remove_filter( 'wp_redirect', $redirige, 1 );
		$_POST  = array();
		$_FILES = array();
	}
	$retour = get_transient( Formulaire::RETOUR . get_current_user_id() );
	delete_transient( Formulaire::RETOUR . get_current_user_id() );
	return is_array( $retour ) ? $retour : array();
}

/*
 * -----------------------------------------------------------------------------
 * Droits et navigation
 * -----------------------------------------------------------------------------
 */

yume_tte_test(
	'tomes-equipe : réservée à yume_publier — traducteur sans entrée ni vue, lecteur et visiteur refusés',
	function () {
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$t1     = yume_tte_tome( $oeuvre, 1 );
		$trad   = yume_tte_membre( 'yume_traducteur' );
		yume_assert_false( user_can( $trad, 'yume_publier' ), 'traducteur sans yume_publier' );

		$html = yume_tte_rendu( $trad, array( 'vue' => 'tomes' ) );
		yume_assert_true( ! isset( yume_tte_nav( $html )['Tous les tomes'] ), 'pas d’entrée « Tous les tomes »' );
		yume_assert_contains( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent gérer la lecture en ligne des tomes.', $html );
		yume_assert_not_contains( 'id="yn-tomes-' . $t1 . '"', $html, 'aucun tome listé' );
		yume_assert_not_contains( 'id="yn-tous-les-tomes"', yume_tte_rendu( $trad ), 'tableau de bord : pas de section' );

		$lecteur = yume_tte_rendu( yume_tte_membre( 'subscriber' ), array( 'vue' => 'tomes' ) );
		yume_assert_contains( 'Espace réservé à l’équipe', $lecteur );
		yume_assert_not_contains( 'yn-tomes-', $lecteur );
		$visiteur = yume_tte_rendu( 0, array( 'vue' => 'tomes' ) );
		yume_assert_contains( 'Se connecter', $visiteur );
		yume_assert_not_contains( 'yn-tomes-', $visiteur );
	}
);

yume_tte_test(
	'tomes-equipe : « Tous les tomes » mène à ?vue=tomes (tableau de bord, vues, formulaire de publication) ; section « Tomes en préparation »',
	function () {
		foreach ( array( 'yume_editeur', 'yume_gerant', 'administrator' ) as $role ) {
			$id = yume_tte_membre( $role );
			wp_set_current_user( $id );
			$nav = yume_tte_nav( navigation_equipe( 'tableau' ) );
			yume_assert_same( url_vue_equipe( 'tomes' ), $nav['Tous les tomes'][0] ?? '', $role . ' : entrée vers la vue' );
			yume_assert_same( '', $nav['Tous les tomes'][1], 'pas courante sur le tableau de bord' );
			$libelles = array_keys( $nav );
			yume_assert_same( array_search( 'Œuvres', $libelles, true ) + 1, array_search( 'Tous les tomes', $libelles, true ), 'menu « Catalogue », juste après « Œuvres »' );
		}
		$html = yume_tte_rendu( $id, array( 'vue' => 'tomes' ) );
		yume_assert_same( ' aria-current="page"', yume_tte_nav( $html )['Tous les tomes'][1], 'entrée courante sur la vue' );
		yume_assert_same( 1, substr_count( $html, 'aria-current' ) );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Tous les tomes</h2>', $html );

		// Tableau de bord : la section ne liste que le planning à venir, d'où son nom.
		$tableau = yume_tte_rendu( $id );
		yume_assert_contains( '<h2 id="yn-tous-titre">Tomes en préparation</h2>', $tableau );
		yume_assert_not_contains( '#yn-tous-les-tomes', $tableau, 'plus d’ancre « Tous les tomes »' );

		// Formulaire de publication : même navigation.
		$_GET = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form = yume_render_block( 'yume/publish-form' );
		yume_assert_same( url_vue_equipe( 'tomes' ), yume_tte_nav( $form )['Tous les tomes'][0] ?? '' );
		yume_assert_not_contains( 'yn-tous-les-tomes', $form );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Listing, filtres, actions
 * -----------------------------------------------------------------------------
 */

yume_tte_test(
	'tomes-equipe : tous les tomes (publiés, programmés, brouillons) par œuvre, statut, date, chapitres en ligne, liens d’action',
	function () {
		$raven   = yume_tte_oeuvre( 'Raven' );
		$grimgar = yume_tte_oeuvre( 'Grimgar' );
		$g2      = yume_tte_tome( $grimgar, 2 );
		$g1      = yume_tte_tome( $grimgar, 1 );
		$g3      = yume_tte_tome( $grimgar, 3, 'future' );
		$g4      = yume_tte_tome( $grimgar, 4, 'draft' );
		$r1      = yume_tte_tome( $raven, 1 );
		yume_tte_chapitre( $g1, 1 );
		yume_tte_chapitre( $g1, 2 );
		yume_tte_chapitre( $g1, 3, 'draft' );
		$editeur = yume_tte_membre( 'yume_editeur' );

		$html = yume_tte_rendu( $editeur, array( 'vue' => 'tomes' ) );
		foreach ( array( $g1, $g2, $g3, $g4, $r1 ) as $id ) {
			yume_assert_contains( 'id="yn-tomes-' . $id . '"', $html, 'tome ' . $id . ' listé' );
		}
		yume_assert_contains( '>5 tomes</h2>', $html );
		yume_assert_true( strpos( $html, '>Grimgar</h3>' ) < strpos( $html, '>Raven</h3>' ), 'œuvres par titre' );
		yume_assert_true( strpos( $html, 'id="yn-tomes-' . $g1 . '"' ) < strpos( $html, 'id="yn-tomes-' . $g2 . '"' ), 'tomes par numéro' );
		yume_assert_true( strpos( $html, 'id="yn-tomes-' . $g3 . '"' ) < strpos( $html, 'id="yn-tomes-' . $g4 . '"' ) );

		$ligne   = static function ( int $id ) use ( $html ): string {
			preg_match( '#<li class="yn-lecture__tome" id="yn-tomes-' . $id . '">.*?</li>#s', $html, $m );
			return $m[0] ?? '';
		};
		$publier = static fn( int $id ): string => esc_url( add_query_arg( 'tome', $id, yume_url_page( 'publier' ) ) );

		// Publié avec lecture en ligne : remplacer, voir, modifier.
		$l = $ligne( $g1 );
		yume_assert_contains( '>Publié</span>', $l );
		yume_assert_contains( 'Paru le ', $l );
		yume_assert_contains( '2 chapitres en ligne', $l );
		yume_assert_contains( '1 chapitre préparé, pas encore en ligne', $l );
		yume_assert_contains( $publier( $g1 ) . '">Remplacer la lecture en ligne<span class="yn-visually-hidden"> — Grimgar, Tome 1</span>', $l );
		yume_assert_contains( esc_url( get_permalink( $g1 ) ) . '">Voir<', $l );
		wp_set_current_user( $editeur );
		yume_assert_contains( esc_url( get_edit_post_link( $g1 ) ) . '">Modifier<', $l );

		// Publié sans lecture en ligne : ajouter.
		$l = $ligne( $g2 );
		yume_assert_contains( 'Pas de lecture en ligne', $l );
		yume_assert_contains( $publier( $g2 ) . '">Lecture en ligne : ajouter le DOCX/EPUB', $l );
		yume_assert_not_contains( 'Remplacer', $l );

		// Programmé et brouillon : pas de « Voir ».
		$l = $ligne( $g3 );
		yume_assert_contains( '>Programmé</span>', $l );
		yume_assert_contains( 'Sortie le ', $l );
		yume_assert_not_contains( '">Voir<', $l );
		$l = $ligne( $g4 );
		yume_assert_contains( '>Brouillon</span>', $l );
		yume_assert_contains( 'Modifié le ', $l );
		yume_assert_not_contains( '">Voir<', $l );

		// Sans droit de modifier le tome : pas de « Modifier ».
		$bloque = static function ( $caps, $cap, $user_id, $args ) use ( $g2 ) {
			return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $g2 ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $bloque, 10, 4 );
		try {
			$html = yume_tte_rendu( $editeur, array( 'vue' => 'tomes' ) );
		} finally {
			remove_filter( 'map_meta_cap', $bloque, 10 );
		}
		preg_match( '#id="yn-tomes-' . $g2 . '">.*?</li>#s', $html, $m );
		yume_assert_not_contains( '">Modifier<', $m[0] ?? 'x">Modifier<' );
	}
);

yume_tte_test(
	'tomes-equipe : filtres œuvre, statut et recherche (GET, sans JavaScript), pagination',
	function () {
		$grimgar = yume_tte_oeuvre( 'Grimgar' );
		$raven   = yume_tte_oeuvre( 'Raven' );
		$g1      = yume_tte_tome( $grimgar, 1 );
		$g2      = yume_tte_tome( $grimgar, 2, 'future' );
		$g3      = yume_tte_tome( $grimgar, 3, 'draft' );
		$r1      = yume_tte_tome( $raven, 1 );
		$editeur = yume_tte_membre( 'yume_editeur' );
		$liste   = static function ( array $get ) use ( $editeur ): array {
			$html = yume_tte_rendu( $editeur, array_merge( array( 'vue' => 'tomes' ), $get ) );
			preg_match_all( '#id="yn-tomes-(\d+)"#', $html, $m );
			return array_map( 'intval', $m[1] );
		};

		$html = yume_tte_rendu( $editeur, array( 'vue' => 'tomes' ) );
		yume_assert_contains( 'method="get"', $html );
		yume_assert_contains( '<input type="hidden" name="vue" value="tomes">', $html );
		yume_assert_contains( 'name="oeuvre"', $html );
		yume_assert_contains( 'name="statut"', $html );
		yume_assert_contains( 'type="search" id="yn-t-recherche" name="recherche"', $html );
		yume_assert_not_contains( 'Effacer les filtres', $html );

		yume_assert_same( array( $g1, $g2, $g3 ), $liste( array( 'oeuvre' => (string) $grimgar ) ), 'œuvre' );
		yume_assert_same( array( $g1, $r1 ), $liste( array( 'statut' => 'publie' ) ), 'publiés' );
		yume_assert_same( array( $g2 ), $liste( array( 'statut' => 'programme' ) ), 'programmés' );
		yume_assert_same( array( $g3 ), $liste( array( 'statut' => 'brouillon' ) ), 'brouillons' );
		yume_assert_same( array( $g1, $g2, $g3, $r1 ), $liste( array( 'statut' => 'nimporte' ) ), 'statut inconnu ignoré' );
		yume_assert_same( array( $r1 ), $liste( array( 'recherche' => 'raven' ) ), 'recherche dans le titre' );
		yume_assert_same( array( $g1 ), $liste( array( 'recherche' => 'Grimgar — Tome 1' ) ) );
		yume_assert_same(
			array(),
			$liste(
				array(
					'oeuvre'    => (string) $raven,
					'statut'    => 'brouillon',
					'recherche' => 'x',
				)
			)
		);
		$vide = yume_tte_rendu(
			$editeur,
			array(
				'vue'       => 'tomes',
				'recherche' => '<b>zzz</b>',
			)
		);
		yume_assert_contains( 'Aucun tome ne correspond à ces filtres.', $vide );
		yume_assert_contains( 'Effacer les filtres', $vide );
		yume_assert_not_contains( '<b>zzz', $vide, 'recherche assainie' );

		// Pagination (60 tomes par page), filtres conservés.
		for ( $i = 10; $i < 70; $i++ ) {
			yume_tte_tome( $raven, $i, 'draft' );
		}
		$page1 = yume_tte_rendu(
			$editeur,
			array(
				'vue'    => 'tomes',
				'oeuvre' => (string) $raven,
			)
		);
		yume_assert_contains( '>61 tomes</h2>', $page1 );
		yume_assert_same( 60, substr_count( $page1, 'class="yn-lecture__tome"' ) );
		yume_assert_contains( 'Page 1 sur 2', $page1 );
		yume_assert_contains(
			esc_url(
				url_vue_equipe(
					'tomes',
					array(
						'oeuvre' => $raven,
						'pg'     => 2,
					)
				)
			),
			$page1
		);
		$page2 = $liste(
			array(
				'oeuvre' => (string) $raven,
				'pg'     => '2',
			)
		);
		yume_assert_same( 1, count( $page2 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Remplacement de la lecture en ligne d'un tome déjà paru
 * -----------------------------------------------------------------------------
 */

yume_tte_test(
	'tomes-equipe : formulaire ?tome=ID d’un tome paru avec lecture en ligne — « Remplacer la lecture en ligne (N chapitres actuels) », sans annonce par défaut',
	function () {
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$avec   = yume_tte_tome( $oeuvre, 1 );
		$sans   = yume_tte_tome( $oeuvre, 2 );
		$brouil = yume_tte_tome( $oeuvre, 3, 'draft' );
		yume_tte_chapitre( $avec, 1 );
		yume_tte_chapitre( $avec, 2 );
		yume_tte_chapitre( $avec, 3, 'draft' );
		yume_tte_chapitre( $brouil, 1, 'draft' );
		wp_set_current_user( yume_tte_membre( 'yume_editeur' ) );
		$rendu = static function ( int $tome ): string {
			$_GET = array( 'tome' => (string) $tome ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return yume_render_block( 'yume/publish-form' );
		};

		$html = $rendu( $avec );
		yume_assert_contains( 'Remplacer la lecture en ligne (2 chapitres actuels)', $html );
		yume_assert_contains( 'chaque chapitre est remplacé en place, par numéro', $html );
		yume_assert_contains( 'un chapitre absent du nouveau fichier est signalé', $html );
		yume_assert_contains( 'la date de sortie du tome ne change pas', $html );
		yume_assert_true( (bool) preg_match( '#<input id="yn-publish-sans-annonce" type="checkbox"[^>]*checked#', $html ), 'sans annonce cochée' );
		yume_assert_contains( 'name="retirer_absents"', $html );
		yume_assert_not_contains( 'Remplacer la lecture en ligne', $rendu( $sans ), 'pas de lecture en ligne : ajout' );
		yume_assert_not_contains( 'Remplacer la lecture en ligne', $rendu( $brouil ), 'brouillon : préparation normale' );
	}
);

yume_tte_test(
	'tomes-equipe : remplacer la lecture en ligne d’un tome paru (formulaire sans JavaScript) — mêmes chapitres, sans doublon, sans annonce, date conservée ; disparus signalés',
	function ( $ctx ) {
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$oeuvre   = yume_tte_oeuvre( 'Grimgar de test' );
		$tome     = yume_tte_tome( $oeuvre, 10 );
		$sortie   = get_post_field( 'post_date_gmt', $tome );
		$champs   = array(
			'oeuvre_id'     => (string) $oeuvre,
			'tome_planning' => (string) $tome,
			'tome_id'       => (string) $tome,
			'numero'        => '10',
		);
		$sorties  = static function (): array {
			return get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
		};
		$avant    = $sorties();
		$chapitre = static function ( int $tome, float $numero ): array {
			return array_values(
				array_filter(
					yume_get_chapitres( $tome, array( 'status' => 'any' ) ),
					static fn( $c ) => 'chapitre' === get_post_meta( $c->ID, 'yume_nature', true ) && abs( (float) get_post_meta( $c->ID, 'yume_numero', true ) - $numero ) < 0.001
				)
			);
		};

		// 1. Première lecture en ligne (ajout au catalogue), puis même fichier à nouveau.
		$n   = yume_tte_compter(
			function () use ( $ctx, $champs ) {
				$r = yume_tte_formulaire( $ctx, $champs, 'regles.docx' );
				yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
				yume_assert_contains( 'lecture en ligne ajoutée (13 chapitres)', $r['message'] );
			}
		);
		$ids = wp_list_pluck( yume_get_chapitres( $tome ), 'ID' );
		yume_assert_same( 13, count( $ids ) );
		$chap1       = $chapitre( $tome, 1 )[0];
		$date_chap1  = $chap1->post_date_gmt;
		$slug_chap1  = $chap1->post_name;
		$commentaire = wp_insert_comment(
			array(
				'comment_post_ID'  => $chap1->ID,
				'comment_content'  => 'Merci !',
				'comment_approved' => 1,
			)
		);
		wp_update_post(
			array(
				'ID'           => $chap1->ID,
				'post_content' => '<!-- wp:paragraph --><p>Ancienne version.</p><!-- /wp:paragraph -->',
			)
		);
		clean_post_cache( $chap1->ID );

		$_GET = array( 'tome' => (string) $tome ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		yume_assert_contains( 'Remplacer la lecture en ligne (13 chapitres actuels)', yume_render_block( 'yume/publish-form' ) );
		$_GET = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$n = yume_tte_compter(
			function () use ( $ctx, $champs ) {
				$r = yume_tte_formulaire( $ctx, $champs, 'regles.docx' );
				yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
				yume_assert_contains( 'lecture en ligne remplacée (13 chapitres en ligne), sans annonce : ni article, ni Discord, ni e-mail. La date de sortie du tome ne change pas.', $r['message'] );
			}
		);
		yume_assert_same( array(), $n->tome, 'aucun yume_tome_publie' );
		yume_assert_same( array(), $n->chap, 'aucun yume_chapitre_publie' );
		yume_assert_same( array(), $n->alertes, 'aucune alerte lecteur' );
		yume_assert_same( array(), $n->discord, 'aucun message Discord' );
		yume_assert_same( 0, $n->mails, 'aucun e-mail' );
		yume_assert_same( array(), $n->articles, 'aucun article créé' );
		yume_assert_same( $ids, wp_list_pluck( yume_get_chapitres( $tome ), 'ID' ), 'mêmes chapitres, sans doublon' );
		yume_assert_same( 13, count( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) ) );
		clean_post_cache( $chap1->ID );
		$chap1 = get_post( $chap1->ID );
		yume_assert_not_contains( 'Ancienne version', $chap1->post_content, 'texte remplacé' );
		yume_assert_same( $date_chap1, $chap1->post_date_gmt, 'date du chapitre conservée' );
		yume_assert_same( $slug_chap1, $chap1->post_name, 'adresse conservée' );
		yume_assert_same( 1, (int) get_comments_number( $chap1->ID ), 'commentaire conservé' );
		yume_assert_same( $chap1->ID, (int) get_comment( $commentaire )->comment_post_ID );

		// 2. Nouveau fichier plus court (chapitres 1 à 4) : 1 à 3 mis à jour, 4 ajouté, le reste
		// signalé (et mis en brouillon, case cochée).
		$n = yume_tte_compter(
			function () use ( $ctx, $champs ) {
				$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'retirer_absents' => '1' ) ), 'styles-variantes.docx' );
				yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
				yume_assert_contains( 'lecture en ligne remplacée (4 chapitres en ligne ; 1 nouveau)', $r['message'] );
				yume_assert_contains( '10 chapitres du tome sont absents du nouveau fichier. Il(s) a (ont) été mis en brouillon.', implode( ' ', $r['rapport']['avertissements'] ) );
			}
		);
		yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->alertes, $n->discord, $n->articles ), 'toujours aucune annonce' );
		yume_assert_same( 0, $n->mails );
		$en_ligne = yume_get_chapitres( $tome );
		yume_assert_same( 4, count( $en_ligne ) );
		yume_assert_same( $chap1->ID, $chapitre( $tome, 1 )[0]->ID, 'chapitre 1 mis à jour en place' );
		yume_assert_same( 1, count( $chapitre( $tome, 1 ) ), 'aucun doublon' );
		$chap4 = $chapitre( $tome, 4 );
		yume_assert_same( 1, count( $chap4 ) );
		yume_assert_same( 'publish', $chap4[0]->post_status );
		yume_assert_same( $sortie, $chap4[0]->post_date_gmt, 'nouveau chapitre daté de la sortie du tome' );
		yume_assert_same( Service::NOTIFIE_CATALOGUE, get_post_meta( $chap4[0]->ID, '_yume_publie_notifie', true ) );
		yume_assert_same( 10, count( yume_get_chapitres( $tome, array( 'status' => 'draft' ) ) ), 'disparus mis en brouillon' );

		// Service : les disparus figurent dans le rapport (sans case : restent en ligne).
		$rapport = Service::preparer(
			array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => '10',
			),
			array( 'source' => yume_tte_fichier( $ctx, 'sans-titre.docx' ) )
		);
		yume_assert_false( is_wp_error( $rapport ) );
		yume_assert_true( $rapport['sans_annonce'], 'tome paru : sans annonce par défaut' );
		yume_assert_same( 13, count( $rapport['disparus'] ), 'chapitres 2 à 4 (en ligne) et les 10 retirés signalés' );
		yume_assert_same( 3, count( array_filter( $rapport['disparus'], static fn( $d ) => 'publish' === get_post_status( $d['id'] ) ) ), 'sans la case : restent en ligne' );
		foreach ( $rapport['disparus'] as $d ) {
			yume_assert_false( $d['retire'] );
		}

		// Tome inchangé : statut, date de sortie, aucun article ni annonce.
		clean_post_cache( $tome );
		yume_assert_same( 'publish', get_post_status( $tome ) );
		yume_assert_same( $sortie, get_post_field( 'post_date_gmt', $tome ), 'date de sortie du tome conservée' );
		yume_assert_same( 0, Annonce::existant( $tome ), 'aucun article d’annonce' );
		yume_assert_same( $avant, $sorties(), 'aucun nouvel article' );
		yume_assert_same( 'https://www.clictune.com/pdf10', get_post_meta( $tome, 'yume_lien_pdf', true ), 'lien PDF conservé' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Remplacement en deux temps : version en attente (rien ne change en ligne), puis remplacement
 * -----------------------------------------------------------------------------
 */

/**
 * Tome paru (daté d'il y a un mois) dont la lecture en ligne vient de styles-variantes.docx
 * (chapitres 1 à 4, sans image), mis en ligne sans annonce.
 *
 * @param stdClass $ctx    Contexte.
 * @param int      $oeuvre Œuvre.
 * @return array{0:int,1:array<string,string>} Tome, champs du formulaire.
 */
function yume_tte_tome_en_ligne( stdClass $ctx, int $oeuvre ): array {
	$tome   = yume_tte_tome( $oeuvre, 10 );
	$champs = array(
		'oeuvre_id'     => (string) $oeuvre,
		'tome_planning' => (string) $tome,
		'tome_id'       => (string) $tome,
		'numero'        => '10',
	);
	$r      = yume_tte_formulaire( $ctx, $champs, 'styles-variantes.docx' );
	yume_assert_contains( 'lecture en ligne ajoutée (4 chapitres)', (string) ( $r['message'] ?? '' ) );
	yume_assert_true( Remplacement::mode( $tome ), 'tome en mode remplacement' );
	return array( $tome, $champs );
}

/**
 * Photographie des chapitres du tome (tous statuts actifs) : contenu, statut, dates, adresse.
 *
 * @param int $tome Tome.
 * @return array<int,array<string,mixed>>
 */
function yume_tte_photo( int $tome ): array {
	$photo = array();
	foreach ( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) as $c ) {
		clean_post_cache( $c->ID );
		$c                     = get_post( $c->ID );
		$photo[ (int) $c->ID ] = array( $c->post_title, $c->post_content, $c->post_status, $c->post_modified_gmt, $c->post_date_gmt, $c->post_name, (int) $c->menu_order, get_post_meta( $c->ID, 'yume_nb_mots', true ) );
	}
	return $photo;
}

yume_tte_test(
	'remplacement : brouillon, vérifier et aperçu ne modifient jamais les chapitres en ligne (contenu, statut, date de modification) ; aperçu réservé à l’équipe, noindex',
	function ( $ctx ) {
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$oeuvre                = yume_tte_oeuvre( 'Grimgar de test' );
		list( $tome, $champs ) = yume_tte_tome_en_ligne( $ctx, $oeuvre );
		$avant                 = yume_tte_photo( $tome );
		yume_assert_same( 4, count( $avant ) );
		$fichier_avant = get_post_meta( $tome, Service::META, true )['fichier']['nom'] ?? '';
		$galerie_avant = get_post_meta( $tome, 'yume_illustrations', true );

		// 1. Service (API, « Enregistrer en brouillon ») : version en attente.
		$n = yume_tte_compter(
			function () use ( $ctx, $oeuvre, &$rapport ) {
				$rapport = Service::preparer(
					array(
						'oeuvre_id' => $oeuvre,
						'nature'    => 'tome',
						'numero'    => '10',
					),
					array( 'source' => yume_tte_fichier( $ctx, 'regles.docx' ) )
				);
			}
		);
		yume_assert_false( is_wp_error( $rapport ), is_wp_error( $rapport ) ? $rapport->get_error_message() : '' );
		yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->alertes, $n->discord, $n->articles ), 'aucune annonce' );
		yume_assert_same( $avant, yume_tte_photo( $tome ), 'chapitres en ligne inchangés (brouillon)' );
		yume_assert_true( is_array( $rapport['remplacement'] ), 'remplacement en attente' );
		yume_assert_same( 13, count( $rapport['remplacement']['chapitres'] ) );
		yume_assert_same( 3, $rapport['remplacement']['remplaces'] + $rapport['remplacement']['inchanges'], 'chapitres 1 à 3 : versions des chapitres en ligne' );
		yume_assert_same( 10, $rapport['remplacement']['nouveaux'] );
		yume_assert_same( 1, $rapport['remplacement']['absents'] );
		yume_assert_same( 13, count( Remplacement::versions( $tome ) ) );
		yume_assert_same( Remplacement::STATUT, $rapport['chapitres'][0]['statut'] );
		yume_assert_same( '', $rapport['chapitres'][0]['edition'], 'version en attente : pas de lien vers l’éditeur' );
		yume_assert_same( array_keys( $avant ), wp_list_pluck( yume_get_chapitres( $tome, array( 'status' => 'any' ) ), 'ID' ), 'versions hors des listes du tome' );
		yume_assert_same( 4, count( yume_get_chapitres( $tome ) ), 'chapitres en ligne : toujours 4' );
		yume_assert_same( $fichier_avant, get_post_meta( $tome, Service::META, true )['fichier']['nom'] ?? '', 'fichier en place inchangé' );
		yume_assert_same( $galerie_avant, get_post_meta( $tome, 'yume_illustrations', true ), 'galerie du tome inchangée' );
		foreach ( Remplacement::versions( $tome ) as $v ) {
			foreach ( (array) get_post_meta( $v->ID, Remplacement::META_MEDIAS, true ) as $media ) {
				yume_assert_same( 0, (int) wp_get_post_parent_id( (int) $media ), 'image en attente sans rattachement' );
			}
		}

		// 2. Formulaire sans JavaScript : « Vérifier » (même fichier), puis « Prévisualiser ».
		$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
		yume_assert_contains( 'Vérification terminée, rien n’a changé en ligne : 13 chapitres prêts (3 modifiés, 10 nouveaux) ; 1 chapitre en ligne absent du fichier, laissé en ligne.', $r['message'] );
		yume_assert_contains( 'Remplacer la lecture en ligne maintenant', $r['message'] );
		yume_assert_same( $avant, yume_tte_photo( $tome ), 'chapitres en ligne inchangés (vérifier)' );
		yume_assert_same( 13, count( Remplacement::versions( $tome ) ), 'une seule préparation (la précédente est remplacée)' );

		$GLOBALS['yume_tte_redirection'] = '';
		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'apercu' ) ), 'regles.docx' );
		$versions = Remplacement::versions( $tome );
		yume_assert_same( get_preview_post_link( $versions[0] ), $GLOBALS['yume_tte_redirection'], 'aperçu de la version en attente' );
		yume_assert_same( $avant, yume_tte_photo( $tome ), 'chapitres en ligne inchangés (aperçu)' );

		// 3. Accès à l'aperçu : équipe seulement (404 pour un visiteur ou un lecteur).
		$requete = static function ( int $user ) use ( $versions ): WP_Query {
			wp_set_current_user( $user );
			return new WP_Query(
				array(
					'post_type' => 'yume_chapitre',
					'p'         => (int) $versions[0]->ID,
				)
			);
		};
		yume_assert_same( 0, $requete( 0 )->post_count, 'visiteur : 404' );
		yume_assert_same( 0, $requete( yume_tte_membre( 'subscriber' ) )->post_count, 'lecteur : 404' );
		$q = $requete( $editeur );
		yume_assert_same( 1, $q->post_count, 'équipe : aperçu' );
		yume_assert_true( $q->is_preview(), 'aperçu' );
		yume_assert_same(
			array(),
			get_posts(
				array(
					'post_type'        => 'yume_chapitre',
					'post_status'      => 'any',
					'p'                => (int) $versions[0]->ID,
					'suppress_filters' => true,
				)
			),
			'hors des requêtes « any »'
		);
		$sauve                   = array( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
		$GLOBALS['wp_query']     = $q;
		$GLOBALS['wp_the_query'] = $q;
		try {
			$robots = Remplacement::robots( array( 'max-image-preview' => 'large' ) );
		} finally {
			list( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ) = $sauve;
		}
		yume_assert_true( ! empty( $robots['noindex'] ) && ! empty( $robots['nofollow'] ), 'noindex, nofollow' );
		$rest = yume_rest( 'GET', '/wp/v2/' . ( get_post_type_object( 'yume_chapitre' )->rest_base ? get_post_type_object( 'yume_chapitre' )->rest_base : 'yume_chapitre' ) . '/' . $versions[0]->ID );
		yume_assert_true( $rest->get_status() >= 401, 'REST : illisible pour un visiteur (' . $rest->get_status() . ')' );

		// 4. Formulaire : encadré avec la version en attente et ses aperçus.
		wp_set_current_user( $editeur );
		$_GET = array( 'tome' => (string) $tome ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = yume_render_block( 'yume/publish-form' );
		yume_assert_contains( 'Remplacer la lecture en ligne (4 chapitres actuels)', $html );
		yume_assert_contains( 'Vérifier (sans rien changer en ligne)', $html );
		yume_assert_contains( 'Version en attente : rien n’a changé en ligne', $html );
		yume_assert_contains( 'à partir de «' . "\u{a0}" . 'regles.docx' . "\u{a0}" . '» : 13 chapitres prêts', $html );
		yume_assert_contains( esc_url( get_preview_post_link( $versions[0] ) ), $html );
		yume_assert_contains( 'value="remplacer"', $html );
		yume_assert_contains( 'value="annuler_remplacement"', $html );
		yume_assert_not_contains( '<div class="yn-publish__attente" data-yn-attente hidden', $html );
	}
);

yume_tte_test(
	'remplacement : « Remplacer la lecture en ligne maintenant » applique en place (mêmes ID, adresses, dates, commentaires), sans annonce ; images rattachées ; préparation nettoyée',
	function ( $ctx ) {
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$oeuvre                = yume_tte_oeuvre( 'Grimgar de test' );
		list( $tome, $champs ) = yume_tte_tome_en_ligne( $ctx, $oeuvre );
		$avant                 = yume_tte_photo( $tome );
		$chap1                 = (int) array_keys( $avant )[0];
		$commentaire           = wp_insert_comment(
			array(
				'comment_post_ID'  => $chap1,
				'comment_content'  => 'Merci !',
				'comment_approved' => 1,
			)
		);
		$r                     = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
		yume_assert_true( (bool) wp_next_scheduled( Remplacement::HOOK_NETTOYAGE, array( $tome ) ), 'nettoyage programmé' );
		$medias = Remplacement::lire( $tome )['medias'];
		yume_assert_same( 5, count( $medias ), 'images nouvelles suivies' );

		$n = yume_tte_compter(
			function () use ( $ctx, $champs ) {
				$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'remplacer' ) ), '' );
				yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
				yume_assert_same( 'Grimgar de test — Tome 10 : lecture en ligne remplacée (14 chapitres en ligne ; 10 nouveaux), sans annonce : ni article, ni Discord, ni e-mail. La date de sortie du tome ne change pas.', $r['message'] );
			}
		);
		yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->alertes, $n->discord, $n->articles ), 'aucune annonce' );
		yume_assert_same( 0, $n->mails );
		$apres = yume_tte_photo( $tome );
		yume_assert_same( 14, count( yume_get_chapitres( $tome ) ), '4 chapitres remplacés ou gardés (le 4e, absent du fichier, reste en ligne) + 10 nouveaux' );
		foreach ( $avant as $id => $ligne ) {
			yume_assert_true( isset( $apres[ $id ] ), 'même chapitre ' . $id );
			yume_assert_same( 'publish', $apres[ $id ][2] );
			yume_assert_same( $ligne[4], $apres[ $id ][4], 'date conservée' );
			yume_assert_same( $ligne[5], $apres[ $id ][5], 'adresse conservée' );
		}
		yume_assert_true( $avant[ $chap1 ][1] !== $apres[ $chap1 ][1], 'contenu remplacé' );
		yume_assert_same( $chap1, (int) get_comment( $commentaire )->comment_post_ID, 'commentaire conservé' );
		yume_assert_same( 1, (int) get_comments_number( $chap1 ) );
		yume_assert_same( array(), Remplacement::versions( $tome ), 'versions supprimées' );
		yume_assert_same( null, Remplacement::lire( $tome ) );
		yume_assert_false( wp_next_scheduled( Remplacement::HOOK_NETTOYAGE, array( $tome ) ), 'nettoyage déprogrammé' );
		yume_assert_same( 'regles.docx', get_post_meta( $tome, Service::META, true )['fichier']['nom'] ?? '' );
		foreach ( $medias as $media ) {
			yume_assert_true( (int) wp_get_post_parent_id( $media ) > 0, 'image rattachée ' . $media );
		}
		$galerie = get_post_meta( $tome, 'yume_illustrations', true );
		yume_assert_same( 2, count( $galerie ), 'galerie posée au remplacement' );
		foreach ( yume_get_chapitres( $tome ) as $c ) {
			if ( ! isset( $avant[ (int) $c->ID ] ) ) {
				yume_assert_same( Service::NOTIFIE_CATALOGUE, get_post_meta( $c->ID, '_yume_publie_notifie', true ), 'nouveau chapitre ajouté sans annonce' );
				yume_assert_same( get_post_field( 'post_date_gmt', $tome ), $c->post_date_gmt, 'daté de la sortie du tome' );
				yume_assert_false( metadata_exists( 'post', $c->ID, Remplacement::META_DE ) );
			}
		}
		// Rien en attente : « Remplacer » sans fichier refuse clairement.
		$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'remplacer' ) ), '' );
		yume_assert_same( 'erreur', $r['type'] ?? '' );
		yume_assert_contains( 'Aucun remplacement n’est en attente', $r['message'] );
	}
);

yume_tte_test(
	'remplacement : « Annuler le remplacement » (formulaire, REST) — rien ne change en ligne, versions et images nouvelles supprimées (aucune orpheline)',
	function ( $ctx ) {
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$oeuvre                = yume_tte_oeuvre( 'Grimgar de test' );
		list( $tome, $champs ) = yume_tte_tome_en_ligne( $ctx, $oeuvre );
		$avant                 = yume_tte_photo( $tome );
		$pieces                = static function (): int {
			return count(
				get_posts(
					array(
						'post_type'        => 'attachment',
						'post_status'      => 'inherit',
						'posts_per_page'   => -1,
						'fields'           => 'ids',
						'suppress_filters' => true,
					)
				)
			);
		};
		$nb_pieces             = $pieces();
		$galerie_avant         = get_post_meta( $tome, 'yume_illustrations', true );

		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		$medias = Remplacement::lire( $tome )['medias'];
		yume_assert_same( $nb_pieces + 5, $pieces() );
		$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'annuler_remplacement' ) ), '' );
		yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
		yume_assert_same( 'Remplacement annulé : la version en attente et ses images sont supprimées, la lecture en ligne n’a pas changé.', $r['message'] );
		yume_assert_same( $avant, yume_tte_photo( $tome ), 'rien n’a changé en ligne' );
		yume_assert_same( array(), Remplacement::versions( $tome ) );
		yume_assert_same( null, Remplacement::lire( $tome ) );
		yume_assert_same( $nb_pieces, $pieces(), 'aucune image orpheline' );
		foreach ( $medias as $media ) {
			yume_assert_same( null, get_post( $media ) );
		}
		yume_assert_false( wp_next_scheduled( Remplacement::HOOK_NETTOYAGE, array( $tome ) ) );
		yume_assert_same( $galerie_avant, get_post_meta( $tome, 'yume_illustrations', true ), 'galerie inchangée' );

		// REST : DELETE /publications/{id}/remplacement (équipe seulement).
		Service::preparer(
			array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => '10',
			),
			array( 'source' => yume_tte_fichier( $ctx, 'sans-titre.docx' ) )
		);
		yume_assert_true( count( Remplacement::versions( $tome ) ) > 0 );
		yume_assert_same( 401, yume_rest( 'DELETE', '/yume/v1/publications/' . $tome . '/remplacement' )->get_status(), 'visiteur refusé' );
		yume_assert_same( 403, yume_rest( 'DELETE', '/yume/v1/publications/' . $tome . '/remplacement', array(), yume_tte_membre( 'yume_traducteur' ) )->get_status(), 'traducteur refusé' );
		$reponse = yume_rest( 'DELETE', '/yume/v1/publications/' . $tome . '/remplacement', array(), $editeur );
		yume_assert_same( 200, $reponse->get_status() );
		yume_assert_true( $reponse->get_data()['annule'] );
		yume_assert_same( array(), Remplacement::versions( $tome ) );
		yume_assert_same( $avant, yume_tte_photo( $tome ) );
		yume_assert_same( $nb_pieces, $pieces(), 'aucune image orpheline (REST)' );
		yume_assert_same( 404, yume_rest( 'DELETE', '/yume/v1/publications/' . $tome . '/remplacement', array(), $editeur )->get_status(), 'plus rien à annuler' );
	}
);

yume_tte_test(
	'remplacement : un seul en attente par tome — un second membre est averti (409) ; le même membre remplace sa préparation sans doublon ni image perdue',
	function ( $ctx ) {
		$alice = yume_tte_membre( 'yume_editeur' );
		$bob   = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $alice );
		$oeuvre                = yume_tte_oeuvre( 'Grimgar de test' );
		list( $tome, $champs ) = yume_tte_tome_en_ligne( $ctx, $oeuvre );
		$avant                 = yume_tte_photo( $tome );
		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		$premiere = Remplacement::lire( $tome );

		wp_set_current_user( $bob );
		$refus = Service::preparer(
			array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => '10',
			),
			array( 'source' => yume_tte_fichier( $ctx, 'sans-titre.docx' ) )
		);
		yume_assert_true( is_wp_error( $refus ) );
		yume_assert_same( 'yume_remplacement_en_attente', $refus->get_error_code() );
		yume_assert_same( 409, $refus->get_error_data()['status'] );
		yume_assert_contains( 'Un seul remplacement peut attendre par tome', $refus->get_error_message() );
		yume_assert_contains( get_userdata( $alice )->display_name, $refus->get_error_message() );
		$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'sans-titre.docx' );
		yume_assert_same( 'erreur', $r['type'] ?? '' );
		yume_assert_contains( 'déjà en attente', $r['message'] );
		yume_assert_same( $premiere, Remplacement::lire( $tome ), 'préparation d’Alice intacte' );
		yume_assert_same( 13, count( Remplacement::versions( $tome ) ) );

		// Alice vérifie à nouveau (même fichier) : sa préparation est remplacée, images réutilisées.
		wp_set_current_user( $alice );
		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		yume_assert_same( 13, count( Remplacement::versions( $tome ) ), 'aucune version en double' );
		yume_assert_same( $premiere['medias'], Remplacement::lire( $tome )['medias'], 'images réutilisées' );
		// Puis un autre fichier : les images que plus rien n'utilise sont supprimées.
		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'sans-titre.docx' );
		foreach ( $premiere['medias'] as $media ) {
			yume_assert_same( null, get_post( $media ), 'image de la préparation précédente supprimée' );
		}
		yume_assert_same( $avant, yume_tte_photo( $tome ), 'rien n’a changé en ligne' );
	}
);

yume_tte_test(
	'remplacement : une préparation abandonnée est supprimée après 7 jours (tâche cron, temps simulé) et au prochain envoi',
	function ( $ctx ) {
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$oeuvre                = yume_tte_oeuvre( 'Grimgar de test' );
		list( $tome, $champs ) = yume_tte_tome_en_ligne( $ctx, $oeuvre );
		$avant                 = yume_tte_photo( $tome );
		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		$prevu = wp_next_scheduled( Remplacement::HOOK_NETTOYAGE, array( $tome ) );
		yume_assert_true( $prevu >= time() + 7 * DAY_IN_SECONDS, 'tâche programmée à 7 jours' );
		$medias = Remplacement::lire( $tome )['medias'];

		// Avant l'échéance : rien n'est supprimé.
		do_action( Remplacement::HOOK_NETTOYAGE, $tome );
		yume_assert_same( 13, count( Remplacement::versions( $tome ) ) );

		// 8 jours plus tard (temps simulé : date de préparation reculée).
		$etat         = Remplacement::lire( $tome );
		$etat['cree'] = time() - 8 * DAY_IN_SECONDS;
		update_post_meta( $tome, Remplacement::META_TOME, $etat );
		do_action( Remplacement::HOOK_NETTOYAGE, $tome );
		yume_assert_same( array(), Remplacement::versions( $tome ) );
		yume_assert_same( null, Remplacement::lire( $tome ) );
		foreach ( $medias as $media ) {
			yume_assert_same( null, get_post( $media ), 'image supprimée' );
		}
		yume_assert_same( $avant, yume_tte_photo( $tome ) );

		// Préparation expirée d'un autre membre : elle ne bloque plus, supprimée au prochain envoi.
		yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'regles.docx' );
		$etat         = Remplacement::lire( $tome );
		$etat['cree'] = time() - 8 * DAY_IN_SECONDS;
		update_post_meta( $tome, Remplacement::META_TOME, $etat );
		wp_set_current_user( yume_tte_membre( 'yume_editeur' ) );
		$r = yume_tte_formulaire( $ctx, array_merge( $champs, array( 'etape' => 'verifier' ) ), 'sans-titre.docx' );
		yume_assert_same( 'succes', $r['type'] ?? '', $r['message'] ?? '' );
		yume_assert_same( get_current_user_id(), Remplacement::lire( $tome )['par'] );
		yume_assert_same( 1, count( Remplacement::versions( $tome ) ) );
		// Publier sans appliquer : une préparation expirée n'est jamais appliquée.
		$etat         = Remplacement::lire( $tome );
		$etat['cree'] = time() - 8 * DAY_IN_SECONDS;
		update_post_meta( $tome, Remplacement::META_TOME, $etat );
		$sortie = Service::publier( $tome, 'maintenant', array( 'sans_annonce' => true ) );
		yume_assert_same( null, $sortie['remplacement_applique'] );
		yume_assert_same( $avant, yume_tte_photo( $tome ) );
	}
);

yume_tte_test(
	'remplacement : tomes non publiés (brouillon, programmé) et tome paru sans lecture en ligne gardent la mise à jour directe',
	function ( $ctx ) {
		wp_set_current_user( yume_tte_membre( 'yume_editeur' ) );
		$oeuvre = yume_tte_oeuvre( 'Grimgar de test' );
		foreach ( array( 'draft', 'future' ) as $i => $statut ) {
			$tome = yume_tte_tome( $oeuvre, 20 + $i, $statut );
			$base = array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => (string) ( 20 + $i ),
			);
			$r1   = Service::preparer( $base, array( 'source' => yume_tte_fichier( $ctx, 'styles-variantes.docx' ) ) );
			yume_assert_same( null, $r1['remplacement'], $statut );
			$ids = wp_list_pluck( yume_get_chapitres( $tome, array( 'status' => 'any' ) ), 'ID' );
			yume_assert_same( 4, count( $ids ) );
			wp_update_post(
				array(
					'ID'           => $ids[0],
					'post_content' => '<!-- wp:paragraph --><p>Ancienne version.</p><!-- /wp:paragraph -->',
				)
			);
			$r2 = Service::preparer( $base, array( 'source' => yume_tte_fichier( $ctx, 'styles-variantes.docx' ) ) );
			yume_assert_same( null, $r2['remplacement'], $statut . ' : pas de préparation séparée' );
			yume_assert_same( array(), Remplacement::versions( $tome ) );
			yume_assert_same( $ids, array_slice( array_column( $r2['chapitres'], 'id' ), 0, 4 ), $statut . ' : mis à jour en place' );
			yume_assert_same( 'maj', $r2['chapitres'][0]['action'] );
			clean_post_cache( $ids[0] );
			yume_assert_not_contains( 'Ancienne version', get_post( $ids[0] )->post_content, $statut . ' : mis à jour tout de suite' );
		}
		// Tome paru sans chapitre (ajout de la lecture en ligne) : brouillons créés directement.
		$tome = yume_tte_tome( $oeuvre, 30 );
		$r    = Service::preparer(
			array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => '30',
			),
			array( 'source' => yume_tte_fichier( $ctx, 'styles-variantes.docx' ) )
		);
		yume_assert_same( null, $r['remplacement'] );
		yume_assert_same( 4, count( yume_get_chapitres( $tome, array( 'status' => 'draft' ) ) ) );
		yume_assert_same( array(), Remplacement::versions( $tome ) );
	}
);
