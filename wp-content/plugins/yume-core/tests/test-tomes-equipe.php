<?php
/**
 * Tests de la vue « Tous les tomes » de l'espace équipe (?vue=tomes, includes/planning/
 * tomes-equipe.php) : droits (yume_publier), listing (publiés, programmés, brouillons, par
 * œuvre), filtres œuvre / statut / recherche, pagination, liens d'action, entrées de navigation
 * (tableau de bord, formulaire de publication), section « Tomes en préparation » du tableau de
 * bord ; remplacement de la lecture en ligne d'un tome déjà paru par le formulaire de
 * publication (?tome=ID) : chapitres mis à jour sans doublon, chapitres disparus signalés, sans
 * nouvelle annonce ni changement de la date de sortie.
 *
 * Lancement : tools/localenv/test.sh tomes-equipe
 *
 * @package Yume\Core
 */

use Yume\Core\Publication\Annonce;
use Yume\Core\Publication\Formulaire;
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
 * @param string              $fixture Fichier source.
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
	$_FILES = array( 'source' => yume_tte_fichier( $ctx, $fixture ) );
	try {
		Formulaire::traiter();
	} catch ( RuntimeException $e ) {
		yume_assert_contains( 'redirection:', $e->getMessage() );
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
			yume_assert_same( array_search( 'Lecture à compléter', $libelles, true ) + 1, array_search( 'Tous les tomes', $libelles, true ), 'juste après « Lecture à compléter »' );
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
