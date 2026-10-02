<?php
/**
 * Tests de la vue « Tous les tomes » de l'espace équipe (?vue=tomes, includes/planning/
 * tomes-equipe.php) : droits (yume_publier), listing (publiés, programmés, brouillons, par
 * œuvre, parution), filtres œuvre / statut (parution) / recherche, pagination, liens d'action
 * (« Modifier » dans l'espace équipe), entrées de navigation (tableau de bord, formulaire de
 * publication), section « Tomes en préparation » du tableau de bord ; « Nouveau tome »
 * (?vue=tomes&nouveau=1 : création d'un tome vide avec chapitres prévus et rythme, tome existant)
 * et « Modifier le tome » (?vue=tomes&modifier=ID : droits, champs, état du tome choisi par
 * l'équipe — Planifié, En cours de publication, Publié — avec ses confirmations, actions sur les
 * chapitres), includes/planning/tome-fiche-equipe.php ; remplacement de la lecture en ligne d'un tome déjà paru par le formulaire de
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

use function Yume\Core\Planning\adresse_apres_ajout;
use function Yume\Core\Planning\modifier_tome;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\saisie_tome;
use function Yume\Core\Planning\traiter_action_chapitre;
use function Yume\Core\Planning\traiter_etat_tome;
use function Yume\Core\Planning\traiter_formulaire_ajout;
use function Yume\Core\Planning\traiter_formulaire_tome;
use function Yume\Core\Planning\url_modifier_tome;
use function Yume\Core\Planning\url_nouveau_tome;
use function Yume\Core\Planning\url_publier_tome;
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
 * Chapitre programmé d'un tome (date de sortie à venir, sans notification).
 *
 * @param int $tome   Tome.
 * @param int $numero Numéro.
 * @param int $ts     Horodatage de sortie (à venir).
 */
function yume_tte_chapitre_programme( int $tome, int $numero, int $ts ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'     => 'yume_chapitre',
				'post_title'    => 'Chapitre ' . $numero,
				'post_status'   => 'future',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
				'post_content'  => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'    => array(
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
 * Exécute une action admin-post et renvoie l'adresse de redirection (exit évité : la
 * redirection lève une exception).
 *
 * @param callable $action Fonction admin_post_*.
 * @param array    $post   Champs POST.
 */
function yume_tte_admin_post( callable $action, array $post ): string {
	$redirige = static function ( $url ) {
		throw new RuntimeException( 'redirection:' . $url );
	};
	add_filter( 'wp_redirect', $redirige, 1 );
	$_POST   = $post;
	$adresse = '';
	try {
		$action();
	} catch ( RuntimeException $e ) {
		$adresse = substr( $e->getMessage(), strlen( 'redirection:' ) );
	} finally {
		remove_filter( 'wp_redirect', $redirige, 1 );
		$_POST = array();
	}
	return $adresse;
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
	'tomes-equipe : tous les tomes (publiés, programmés, brouillons) par œuvre, parution, date, chapitres en ligne, liens d’action (« Modifier » dans l’espace équipe)',
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

		// Tome paru (règle historique : complet) avec lecture en ligne : ajouter, voir, modifier.
		$l = $ligne( $g1 );
		yume_assert_contains( '<span class="yn-chip yn-chip--info"><span aria-hidden="true">✓</span> Publié</span>', $l );
		yume_assert_contains( 'Paru le ', $l );
		yume_assert_contains( '2 chapitres en ligne', $l );
		yume_assert_contains( '1 chapitre préparé, pas encore en ligne', $l );
		yume_assert_contains( '>PDF</span>', $l, 'lien PDF présent' );
		yume_assert_contains( $publier( $g1 ) . '">Ajouter des chapitres<span class="yn-visually-hidden"> — Grimgar, Tome 1</span>', $l );
		yume_assert_contains( esc_url( get_permalink( $g1 ) ) . '">Voir<', $l );
		yume_assert_contains( esc_url( url_modifier_tome( $g1 ) ) . '">Modifier<', $l, '« Modifier » ouvre la fiche dans l’espace équipe' );
		wp_set_current_user( $editeur );
		yume_assert_not_contains( esc_url( (string) get_edit_post_link( $g1 ) ), $l, 'plus l’écran d’édition de l’administration' );
		yume_assert_not_contains( 'wp-admin', $l );

		// Publié sans lecture en ligne : « Ajouter des chapitres » aussi.
		$l = $ligne( $g2 );
		yume_assert_contains( 'Pas de lecture en ligne', $l );
		yume_assert_contains( $publier( $g2 ) . '">Ajouter des chapitres', $l );
		yume_assert_not_contains( 'Remplacer', $l );

		// Programmé et brouillon : à paraître, pas de « Voir ».
		$l = $ligne( $g3 );
		yume_assert_contains( '<span class="yn-chip yn-chip--warn"><span aria-hidden="true">▲</span> Planifié</span>', $l );
		yume_assert_contains( 'Sortie le ', $l );
		yume_assert_contains( 'Pas encore de chapitre', $l );
		yume_assert_not_contains( '">Voir<', $l );
		$l = $ligne( $g4 );
		yume_assert_contains( '<span class="yn-chip yn-chip--warn"><span aria-hidden="true">▲</span> Planifié</span>', $l );
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
	'tomes-equipe : filtres œuvre, statut (parution, programmés, brouillons) et recherche (GET, sans JavaScript), pagination',
	function () {
		$grimgar = yume_tte_oeuvre( 'Grimgar' );
		$raven   = yume_tte_oeuvre( 'Raven' );
		$g1      = yume_tte_tome( $grimgar, 1 );
		$g2      = yume_tte_tome( $grimgar, 2, 'future' );
		$g3      = yume_tte_tome( $grimgar, 3, 'draft' );
		$r1      = yume_tte_tome( $raven, 1 );
		update_post_meta( $r1, 'yume_parution', 'en_cours' );
		yume_tte_chapitre( $r1, 1 );
		yume_tte_chapitre_programme( $r1, 2, time() + 3 * DAY_IN_SECONDS );
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
		$options = static function () use ( $html ): array {
			preg_match( '#<select id="yn-t-statut".*?</select>#s', $html, $m );
			preg_match_all( '#<option value="([^"]*)"[^>]*>([^<]*)#', $m[0] ?? '', $o );
			return array_combine( $o[1], $o[2] );
		};
		yume_assert_same(
			array(
				''           => 'Tous',
				'a_paraitre' => 'Planifiés',
				'en_cours'   => 'En cours de publication',
				'complet'    => 'Publiés',
				'programme'  => 'Programmés',
				'brouillon'  => 'Brouillons',
			),
			$options(),
			'filtre « Statut » fondé sur la parution'
		);
		yume_assert_same( array( $g2, $g3 ), $liste( array( 'statut' => 'a_paraitre' ) ), 'à paraître' );
		yume_assert_same( array( $r1 ), $liste( array( 'statut' => 'en_cours' ) ), 'en cours' );
		yume_assert_same( array( $g1 ), $liste( array( 'statut' => 'complet' ) ), 'complets' );
		yume_assert_same( array( $g2, $r1 ), $liste( array( 'statut' => 'programme' ) ), 'programmés : tome ou chapitre' );
		yume_assert_same( array( $g3 ), $liste( array( 'statut' => 'brouillon' ) ), 'brouillons' );
		yume_assert_same( array( $g1, $g2, $g3, $r1 ), $liste( array( 'statut' => 'publie' ) ), 'ancien statut ignoré' );
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

/*
 * -----------------------------------------------------------------------------
 * Nouveau tome et Modifier le tome (tome-fiche-equipe.php)
 * -----------------------------------------------------------------------------
 */

yume_tte_test(
	'nouveau tome : une seule vue (?vue=tomes&nouveau=1) — champs, œuvre présélectionnée, origine, rubrique « Tous les tomes » ; boutons du tableau de bord, du planning, des œuvres et de « Tous les tomes » ; réservée aux éditeurs et gérants',
	function () {
		$oeuvre  = yume_tte_oeuvre( 'Grimgar' );
		$editeur = yume_tte_membre( 'yume_editeur' );
		yume_tte_tome( $oeuvre, 1 );

		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'     => 'tomes',
				'nouveau' => '1',
				'oeuvre'  => (string) $oeuvre,
				'depuis'  => 'planning',
			)
		);
		yume_assert_same( ' aria-current="page"', yume_tte_nav( $html )['Tous les tomes'][1], 'rubrique courante : Tous les tomes' );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Nouveau tome</h2>', $html );
		yume_assert_contains( 'name="action" value="yume_planning_ajout"', $html );
		yume_assert_contains( 'name="_yume_nonce"', $html );
		yume_assert_not_contains( 'data-yn-planning-ajout', $html, 'formulaire sans JavaScript' );
		yume_assert_contains( '<option value="' . $oeuvre . '" selected=\'selected\'>Grimgar</option>', $html, 'œuvre présélectionnée' );
		foreach ( array( 'name="nature"', 'name="numero"', 'name="titre"', 'name="date_cible"', 'name="etape"', 'name="responsables[traduction]"', 'name="responsables[relecture]"', 'name="responsables[edition]"', 'name="chapitres_prevus"', 'name="rythme_jour"' ) as $champ ) {
			yume_assert_contains( $champ, $html, $champ );
		}
		yume_assert_contains( 'type="time" id="yn-nt-heure" name="rythme_heure" value="18:00"', $html, 'heure par défaut' );
		yume_assert_contains( '<option value="" selected=\'selected\'>Libre</option>', $html, 'rythme libre par défaut' );
		yume_assert_contains( '<option value="samedi">Chaque samedi</option>', $html );
		yume_assert_contains( 'name="suite" value="chapitre">Créer le tome et ajouter un chapitre</button>', $html );
		yume_assert_contains( 'name="suite" value="fiche">Créer le tome</button>', $html );
		yume_assert_contains( 'État du tome : proposé par le site, modifiable dans « Modifier le tome »', $html );
		yume_assert_contains( 'Quand l’équipe coche « Tome complet » ou choisit « Publié ».', $html );
		yume_assert_contains( esc_url( url_vue_equipe( 'planning' ) ) . '">Revenir au planning', $html );
		yume_assert_contains( 'Tomes de l’œuvre</h3><p class="yn-muted">Grimgar</p>', $html );
		yume_assert_contains( 'Créer une nouvelle œuvre', $html );
		$ancien = yume_tte_rendu(
			$editeur,
			array(
				'vue'          => 'tomes',
				'nouveau'      => '1',
				'oeuvre_ajout' => (string) $oeuvre,
			)
		);
		yume_assert_contains( '<option value="' . $oeuvre . '" selected=\'selected\'>', $ancien, 'ancien paramètre ?oeuvre_ajout=' );

		// Les boutons « Ajouter un tome au planning » et « Nouveau tome » y mènent.
		$gerant  = yume_tte_membre( 'yume_gerant' );
		$tableau = yume_tte_rendu( $gerant );
		yume_assert_contains( 'id="yn-ajouter-tome-section"', $tableau, 'ancre conservée' );
		yume_assert_contains( esc_url( url_nouveau_tome( 0, 'tableau' ) ) . '">Nouveau tome</a>', $tableau );
		yume_assert_not_contains( 'name="action" value="yume_planning_ajout"', $tableau, 'plus de formulaire intégré au tableau de bord' );
		yume_assert_contains( esc_url( url_nouveau_tome( $oeuvre, 'tableau' ) ), yume_tte_rendu( $gerant, array( 'oeuvre_ajout' => (string) $oeuvre ) ), 'anciens liens ?oeuvre_ajout=' );
		yume_assert_contains( esc_url( url_nouveau_tome( 0, 'planning' ) ) . '">Ajouter un tome au planning', yume_tte_rendu( $gerant, array( 'vue' => 'planning' ) ) );
		$filtre = yume_tte_rendu(
			$gerant,
			array(
				'vue'    => 'planning',
				'oeuvre' => (string) $oeuvre,
			)
		);
		yume_assert_contains( esc_url( url_nouveau_tome( $oeuvre, 'planning' ) ), $filtre, 'planning filtré : œuvre présélectionnée' );
		yume_assert_contains( esc_url( url_nouveau_tome( $oeuvre, 'oeuvres' ) ) . '">Ajouter un tome au planning', yume_tte_rendu( $gerant, array( 'vue' => 'oeuvres' ) ) );
		$tomes = yume_tte_rendu( $gerant, array( 'vue' => 'tomes' ) );
		yume_assert_contains( esc_url( url_nouveau_tome( 0, 'tomes' ) ) . '">Nouveau tome</a>', $tomes );
		yume_assert_contains( esc_url( yume_url_page( 'publier' ) ) . '">Ajouter des chapitres</a>', $tomes );
		yume_assert_contains( 'Tous les tomes du catalogue, publiés compris. « Ajouter des chapitres » ouvre le formulaire de publication avec le tome déjà choisi', $tomes );

		// Réservée aux éditeurs et gérants (yume_maj_planning_tous).
		$trad = yume_tte_rendu(
			yume_tte_membre( 'yume_traducteur' ),
			array(
				'vue'     => 'tomes',
				'nouveau' => '1',
			)
		);
		yume_assert_contains( 'peuvent ajouter un tome au planning', $trad );
		yume_assert_not_contains( 'yume_planning_ajout', $trad );
	}
);

yume_tte_test(
	'nouveau tome : tome vide avec chapitres prévus et rythme (formulaire et REST) ; « Créer le tome » → sa fiche, « … et ajouter un chapitre » → publication ; tome existant → sa fiche, rien de créé ; erreurs reprises dans le formulaire',
	function () {
		$oeuvre  = yume_tte_oeuvre( 'Grimgar' );
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$champs = array(
			'action'           => 'yume_planning_ajout',
			'_yume_nonce'      => wp_create_nonce( 'yume_planning_ajout' ),
			'oeuvre_id'        => (string) $oeuvre,
			'nature'           => 'tome',
			'numero'           => '2',
			'titre'            => '',
			'date_cible'       => '2026-12-06',
			'etape'            => 'traduction',
			'chapitres_prevus' => '12',
			'rythme_jour'      => 'samedi',
			'rythme_heure'     => '',
			'responsables'     => array(
				'traduction' => (string) $editeur,
				'relecture'  => '0',
				'edition'    => '0',
			),
			'suite'            => 'fiche',
		);

		$retour = traiter_formulaire_ajout( $champs, $editeur );
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		$tome = (int) $retour['tome_id'];
		yume_assert_same( 'draft', get_post_status( $tome ) );
		yume_assert_same( 'Grimgar — Tome 2', get_post_field( 'post_title', $tome ) );
		yume_assert_same( 12, (int) get_post_meta( $tome, 'yume_chapitres_prevus', true ) );
		yume_assert_same(
			array(
				'jour'  => 'samedi',
				'heure' => '18:00',
			),
			get_post_meta( $tome, 'yume_rythme', true ),
			'heure par défaut'
		);
		yume_assert_same( '2026-12-06', get_post_meta( $tome, 'yume_date_cible', true ) );
		yume_assert_same( 'traduction', get_post_meta( $tome, 'yume_etape', true ) );
		yume_assert_same( $editeur, (int) get_post_meta( $tome, 'yume_responsables', true )['traduction'] );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $tome ), 'à paraître dès la création' );
		yume_assert_same( array(), yume_get_chapitres( $tome, array( 'status' => 'any' ) ), 'sans chapitre' );
		yume_assert_same( url_modifier_tome( $tome ) . '#yn-tome-fiche', adresse_apres_ajout( $retour ), '« Créer le tome » : sa fiche' );
		yume_assert_same( url_publier_tome( $tome ), adresse_apres_ajout( array_merge( $retour, array( 'suite' => 'chapitre' ) ) ) );

		// Même œuvre, nature et numéro : la fiche du tome existant, aucun second tome.
		$avant   = count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) );
		$adresse = yume_tte_admin_post(
			'Yume\\Core\\Planning\\admin_post_ajout',
			array_merge(
				$champs,
				array(
					'numero' => '2,0',
					'suite'  => 'chapitre',
				)
			)
		);
		yume_assert_same( url_modifier_tome( $tome ) . '#yn-tome-fiche', $adresse, 'fiche du tome existant' );
		yume_assert_same( $avant, count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) ), 'aucun second tome' );
		$fiche = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
			)
		);
		yume_assert_contains( 'Grimgar T.2 existe déjà : voici sa fiche, aucun tome n’a été créé.', $fiche, 'message en tête de la fiche' );

		// « Créer le tome et ajouter un chapitre » : formulaire de publication, tome choisi.
		$adresse = yume_tte_admin_post(
			'Yume\\Core\\Planning\\admin_post_ajout',
			array_merge(
				$champs,
				array(
					'numero' => '3',
					'suite'  => 'chapitre',
				)
			)
		);
		parse_str( (string) wp_parse_url( $adresse, PHP_URL_QUERY ), $args );
		$t3 = (int) ( $args['tome'] ?? 0 );
		yume_assert_same( url_publier_tome( $t3 ), $adresse );
		yume_assert_same( 3.0, (float) get_post_meta( $t3, 'yume_numero', true ) );
		yume_assert_false( (bool) get_transient( 'yume_planning_retour_' . $editeur ), 'aucun message laissé en attente' );

		// Erreurs : message et saisie repris dans le formulaire.
		$retour = traiter_formulaire_ajout(
			array_merge(
				$champs,
				array(
					'numero'      => '4',
					'rythme_jour' => 'jamais',
				)
			),
			$editeur
		);
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_contains( 'Rythme', $retour['message'] );
		$retour = traiter_formulaire_ajout(
			array_merge(
				$champs,
				array(
					'numero'           => '4',
					'chapitres_prevus' => 'douze',
				)
			),
			$editeur
		);
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_same( url_nouveau_tome( $oeuvre ) . '#yn-nouveau-tome-form', adresse_apres_ajout( $retour ) );
		set_transient( 'yume_planning_retour_' . $editeur, $retour, 60 );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'     => 'tomes',
				'nouveau' => '1',
				'oeuvre'  => (string) $oeuvre,
			)
		);
		yume_assert_contains( 'Chapitres prévus : indiquez un nombre entier de 0 à 999.', $html );
		yume_assert_contains( 'name="numero" value="4"', $html );
		yume_assert_contains( '<option value="samedi" selected=\'selected\'>Chaque samedi</option>', $html );
		yume_assert_same( 2, count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) ), 'rien de créé (tomes 2 et 3 seulement)' );
		$faux = traiter_formulaire_ajout(
			array_merge(
				$champs,
				array(
					'numero'      => '5',
					'_yume_nonce' => 'faux',
				)
			),
			$editeur
		);
		yume_assert_same( 'erreur', $faux['type'], 'nonce' );
		$trad = yume_tte_membre( 'yume_traducteur' );
		wp_set_current_user( $trad );
		$refus = traiter_formulaire_ajout(
			array_merge(
				$champs,
				array(
					'numero'      => '5',
					'_yume_nonce' => wp_create_nonce( 'yume_planning_ajout' ),
				)
			),
			$trad
		);
		yume_assert_same( 'erreur', $refus['type'], 'traducteur refusé' );

		// REST : même service, mêmes champs.
		$reponse = yume_rest(
			'POST',
			'/yume/v1/planning/tomes',
			array(
				'oeuvre_id'        => $oeuvre,
				'nature'           => 'arc',
				'numero'           => 7,
				'chapitres_prevus' => 20,
				'rythme'           => array(
					'jour'  => 'mercredi',
					'heure' => '12:30',
				),
			),
			$editeur
		);
		yume_assert_same( 201, $reponse->get_status() );
		$arc = (int) $reponse->get_data()['tome_id'];
		yume_assert_same( 20, (int) get_post_meta( $arc, 'yume_chapitres_prevus', true ) );
		yume_assert_same(
			array(
				'jour'  => 'mercredi',
				'heure' => '12:30',
			),
			get_post_meta( $arc, 'yume_rythme', true )
		);
		$invalide = yume_rest(
			'POST',
			'/yume/v1/planning/tomes',
			array(
				'oeuvre_id' => $oeuvre,
				'numero'    => 8,
				'rythme'    => array( 'jour' => 'jamais' ),
			),
			$editeur
		);
		yume_assert_same( 400, $invalide->get_status() );
	}
);

yume_tte_test(
	'modifier le tome : fiche dans l’espace équipe (?vue=tomes&modifier=ID) avec édition avancée ; un traducteur ne peut ni l’ouvrir ni l’enregistrer ; enregistrement (titre, œuvre, planning, prévus, rythme, crédits), doublon refusé',
	function () {
		$grimgar = yume_tte_oeuvre( 'Grimgar' );
		$raven   = yume_tte_oeuvre( 'Raven' );
		$tome    = yume_tte_tome( $grimgar, 2, 'draft' );
		$autre   = yume_tte_tome( $grimgar, 4, 'draft' );
		update_post_meta( $tome, 'yume_chapitres_prevus', 12 );
		update_post_meta(
			$tome,
			'yume_rythme',
			array(
				'jour'  => 'samedi',
				'heure' => '18:00',
			)
		);
		$editeur = yume_tte_membre( 'yume_editeur' );
		$trad    = yume_tte_membre( 'yume_traducteur' );

		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
			)
		);
		yume_assert_same( ' aria-current="page"', yume_tte_nav( $html )['Tous les tomes'][1], 'rubrique courante : Tous les tomes' );
		yume_assert_contains( 'Modifier le tome : Grimgar — Tome 2', $html );
		yume_assert_contains( 'name="action" value="yume_tome_modifier"', $html );
		yume_assert_contains( 'enctype="multipart/form-data"', $html );
		yume_assert_contains( 'name="tome_id" value="' . $tome . '"', $html );
		yume_assert_contains( 'name="chapitres_prevus" value="12"', $html );
		yume_assert_true( strpos( $html, 'name="chapitres_prevus"' ) > (int) strpos( $html, 'id="yn-tome-identite"' ), 'chapitres prévus dans la section « Le tome », hors de l’encadré grisé de l’état' );
		yume_assert_same( 1, substr_count( $html, 'name="chapitres_prevus"' ), 'un seul champ' );
		yume_assert_contains( '<option value="samedi" selected=\'selected\'>Chaque samedi</option>', $html );
		yume_assert_contains( 'name="credits[traduction]"', $html );
		yume_assert_contains( 'name="lien_pdf" value="https://www.clictune.com/pdf2"', $html );
		yume_assert_contains( 'id="yn-tome-etat"', $html, 'section « État du tome »' );
		yume_assert_true( (bool) preg_match( '#name="etat" value="a_paraitre" checked#', $html ), 'état actuel coché : Planifié' );
		yume_assert_contains( 'Si « Planifié »', $html );
		yume_assert_contains( 'Si « En cours de publication »', $html );
		yume_assert_contains( 'Si « Publié »', $html );
		yume_assert_contains( 'name="etape"', $html, 'étape du planning dans « Planifié »' );
		yume_assert_contains( 'name="avancement[traduction]"', $html );
		yume_assert_contains( 'Annoncer la sortie du tome (article, Discord, e-mails)', $html, 'tome pas en ligne : case « Annoncer » de la sortie' );
		yume_assert_not_contains( 'passer le tome à « Complet »', $html, 'plus de case « Tome complet »' );
		yume_assert_contains( 'name="cadrage_x"', $html, 'cadrage de la couverture' );
		yume_assert_contains( 'Ouvrir dans le planning', $html );
		yume_assert_contains( '0 chapitres sur 12 en ligne', $html );
		yume_assert_contains( '<span class="yn-chip yn-chip--warn"><span aria-hidden="true">▲</span> Planifié</span>', $html );
		yume_assert_contains( esc_url( get_edit_post_link( $tome, 'raw' ) ) . '">Édition avancée (administration WordPress)', $html );
		yume_assert_contains( esc_url( url_publier_tome( $tome ) ) . '">Ajouter des chapitres', $html );
		yume_assert_contains( '12 chapitres pas encore déposés (sur 12 prévus)', $html, 'ligne « À venir »' );

		// Traducteur : ni la fiche ni l'enregistrement.
		$refus = yume_tte_rendu(
			$trad,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
			)
		);
		yume_assert_contains( 'peuvent modifier les tomes', $refus );
		yume_assert_not_contains( 'yume_tome_modifier', $refus );
		wp_set_current_user( $trad );
		$r = modifier_tome(
			$tome,
			saisie_tome(
				array(
					'oeuvre_id' => (string) $grimgar,
					'numero'    => '3',
				)
			),
			null,
			$trad
		);
		yume_assert_true( is_wp_error( $r ), 'traducteur refusé' );
		yume_assert_same( 403, $r->get_error_data()['status'] );
		$r = traiter_formulaire_tome(
			array(
				'tome_id'     => (string) $tome,
				'_yume_nonce' => wp_create_nonce( 'yume_tome_modifier_' . $tome ),
				'oeuvre_id'   => (string) $grimgar,
				'numero'      => '3',
			),
			array(),
			$trad
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( 2.0, (float) get_post_meta( $tome, 'yume_numero', true ), 'rien de modifié' );

		// Éditeur : enregistrement.
		wp_set_current_user( $editeur );
		$post = array(
			'tome_id'          => (string) $tome,
			'_yume_nonce'      => wp_create_nonce( 'yume_tome_modifier_' . $tome ),
			'oeuvre_id'        => (string) $raven,
			'nature'           => 'tome',
			'numero'           => '3',
			'titre'            => wp_slash( 'L’éveil' ),
			'responsables'     => array(
				'traduction' => (string) $editeur,
				'relecture'  => '0',
				'edition'    => '0',
			),
			'date_cible'       => '2027-01-15',
			'chapitres_prevus' => '10',
			'rythme_jour'      => 'vendredi',
			'rythme_heure'     => '20:00',
			'credits'          => array(
				'traduction' => 'Cerale',
				'relecture'  => 'Shadowadow',
				'edition'    => '',
			),
			'etat'             => 'a_paraitre',
			'lien_pdf'         => '',
			'lien_epub'        => '',
		);
		yume_assert_same( 'erreur', traiter_formulaire_tome( array_merge( $post, array( '_yume_nonce' => 'faux' ) ), array(), $editeur )['type'], 'nonce' );
		$r = traiter_formulaire_tome( $post, array(), $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		clean_post_cache( $tome );
		yume_assert_same( 'Raven — Tome 3 : L’éveil', get_post_field( 'post_title', $tome ) );
		yume_assert_same( $raven, (int) get_post_meta( $tome, 'yume_oeuvre_id', true ) );
		yume_assert_same( 3.0, (float) get_post_meta( $tome, 'yume_numero', true ) );
		yume_assert_same( 'draft', get_post_status( $tome ), 'statut inchangé' );
		yume_assert_same( 10, (int) get_post_meta( $tome, 'yume_chapitres_prevus', true ) );
		yume_assert_same(
			array(
				'jour'  => 'vendredi',
				'heure' => '20:00',
			),
			get_post_meta( $tome, 'yume_rythme', true )
		);
		yume_assert_same(
			array(
				'traduction' => 'Cerale',
				'relecture'  => 'Shadowadow',
				'edition'    => '',
			),
			get_post_meta( $tome, 'yume_credits', true )
		);
		yume_assert_same( '2027-01-15', get_post_meta( $tome, 'yume_date_cible', true ) );
		yume_assert_same( $editeur, (int) get_post_meta( $tome, 'yume_responsables', true )['traduction'] );
		yume_assert_same( '', (string) get_post_meta( $tome, 'yume_lien_pdf', true ), 'lien PDF vidé' );

		// Rythme libre, chapitres prévus vidés : métas supprimées.
		$r = traiter_formulaire_tome(
			array_merge(
				$post,
				array(
					'rythme_jour'      => '',
					'chapitres_prevus' => '',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_false( metadata_exists( 'post', $tome, 'yume_rythme' ) );
		yume_assert_false( metadata_exists( 'post', $tome, 'yume_chapitres_prevus' ) );
		yume_assert_same(
			'Aucun changement à enregistrer.',
			traiter_formulaire_tome(
				array_merge(
					$post,
					array(
						'rythme_jour'      => '',
						'chapitres_prevus' => '',
					)
				),
				array(),
				$editeur
			)['message']
		);

		// Doublon : l'œuvre a déjà un tome de ce numéro.
		$r = traiter_formulaire_tome(
			array_merge(
				$post,
				array(
					'oeuvre_id' => (string) $grimgar,
					'numero'    => '4',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_contains( 'existe déjà', $r['message'] );
		yume_assert_same( $raven, (int) get_post_meta( $tome, 'yume_oeuvre_id', true ), 'rien de modifié' );
		yume_assert_same( 4.0, (float) get_post_meta( $autre, 'yume_numero', true ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * État du tome choisi par l'équipe (Planifié, En cours de publication, Publié)
 * -----------------------------------------------------------------------------
 */

/**
 * Champs du formulaire « Modifier le tome » tels que la fiche les envoie (valeurs actuelles du
 * tome, nonce compris), complétés ou remplacés par $plus.
 *
 * @param int                  $tome Tome.
 * @param array<string,string> $plus Champs à ajouter ou remplacer.
 * @return array<string,mixed>
 */
function yume_tte_post_tome( int $tome, array $plus = array() ): array {
	$v      = \Yume\Core\Planning\valeurs_tome( $tome );
	$rythme = is_array( $v['rythme'] ) ? $v['rythme'] : array();
	return array_merge(
		array(
			'tome_id'          => (string) $tome,
			'_yume_nonce'      => wp_create_nonce( 'yume_tome_modifier_' . $tome ),
			'oeuvre_id'        => $v['oeuvre_id'],
			'nature'           => $v['nature'],
			'numero'           => $v['numero'],
			'titre'            => $v['titre'],
			'date_cible'       => $v['date_cible'],
			'chapitres_prevus' => $v['chapitres_prevus'],
			'rythme_jour'      => (string) ( $rythme['jour'] ?? '' ),
			'rythme_heure'     => (string) ( $rythme['heure'] ?? '' ),
			'lien_pdf'         => $v['lien_pdf'],
			'lien_epub'        => $v['lien_epub'],
			'etat'             => $v['etat'],
			'annoncer'         => '0',
		),
		$plus
	);
}

/**
 * Envoie l'écran de confirmation d'un changement d'état (admin-post yume_tome_etat).
 *
 * @param int                  $tome Tome.
 * @param string               $etat État demandé.
 * @param int                  $user Utilisateur.
 * @param array<string,string> $plus Champs à ajouter ou remplacer.
 * @return array<string,mixed> Retour.
 */
function yume_tte_confirmer_etat( int $tome, string $etat, int $user, array $plus = array() ): array {
	wp_set_current_user( $user );
	return traiter_etat_tome(
		array_merge(
			array(
				'tome_id'     => (string) $tome,
				'etat'        => $etat,
				'confirmer'   => '1',
				'_yume_nonce' => wp_create_nonce( 'yume_tome_etat_' . $tome ),
			),
			$plus
		),
		$user
	);
}

/**
 * Lignes « parution » du journal d'un tome, de la plus récente à la plus ancienne.
 *
 * @param int $tome Tome.
 * @return object[]
 */
function yume_tte_journal_parution( int $tome ): array {
	global $wpdb;
	$table = \Yume\Core\Planning\table_journal();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ancien, nouveau, public, user_id FROM {$table} WHERE tome_id = %d AND champ = 'parution' ORDER BY id DESC", $tome ) );
}

/**
 * Retire publish_yume_tomes à un utilisateur le temps de $corps.
 *
 * @param int      $user  Utilisateur.
 * @param callable $corps Corps.
 * @return mixed Résultat de $corps.
 */
function yume_tte_sans_publier( int $user, callable $corps ) {
	$filtre = static function ( $caps, $demandees, $args ) use ( $user ) {
		if ( (int) ( $args[1] ?? 0 ) === $user ) {
			$caps['publish_yume_tomes'] = false;
		}
		return $caps;
	};
	add_filter( 'user_has_cap', $filtre, 10, 3 );
	try {
		return $corps();
	} finally {
		remove_filter( 'user_has_cap', $filtre, 10 );
	}
}

yume_tte_test(
	'état du tome : segments Planifié / En cours de publication / Publié et encadrés (planning, chapitres en ligne, liens, « Annoncer » décochée) ; « Publié » → « En cours » rouvre le tome après confirmation (liens gardés mais masqués, planning « Édition », journal) ; choix manuel non écrasé',
	function () {
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$tome   = yume_tte_tome( $oeuvre, 2 );
		update_post_meta( $tome, 'yume_lien_epub', 'https://www.clictune.com/epub2' );
		update_post_meta( $tome, 'yume_parution', 'complet' );
		update_post_meta( $tome, 'yume_etape', 'publie' );
		update_post_meta( $tome, 'yume_chapitres_prevus', 6 );
		yume_tte_chapitre( $tome, 1 );
		$prochain = yume_tte_chapitre_programme( $tome, 2, time() + 3 * DAY_IN_SECONDS );
		$editeur  = yume_tte_membre( 'yume_editeur' );
		$vue      = array(
			'vue'      => 'tomes',
			'modifier' => (string) $tome,
		);

		$html = yume_tte_rendu( $editeur, $vue );
		yume_assert_contains( '<span class="yn-chip yn-chip--info"><span aria-hidden="true">✓</span> Publié</span>', $html, 'en-tête : libellé de l’équipe' );
		foreach (
			array(
				'a_paraitre' => 'Planifié',
				'en_cours'   => 'En cours de publication',
				'complet'    => 'Publié',
			) as $cle => $libelle
		) {
			yume_assert_contains( 'name="etat" value="' . $cle . '"', $html, 'segment ' . $cle );
			yume_assert_contains( $libelle . '</strong>', $html );
		}
		yume_assert_true( (bool) preg_match( '#name="etat" value="complet" checked#', $html ), 'état actuel coché' );
		yume_assert_contains( 'yn-etat__encadre yn-etat__encadre--complet yn-etat__encadre--choisi', $html, 'encadré de l’état choisi mis en avant' );
		yume_assert_contains( '<span class="yn-etat__actuel">État actuel</span>', $html );
		yume_assert_contains( 'Ouvrir dans le planning', $html );
		yume_assert_contains( '<strong>1 sur 6</strong>', $html, 'chapitres en ligne N sur M' );
		yume_assert_contains( 'Prochain : <strong>Chapitre 2, ', $html, 'prochain chapitre programmé' );
		yume_assert_contains( 'name="chapitres_prevus" value="6"', $html );
		yume_assert_contains( 'name="lien_epub" value="https://www.clictune.com/epub2"', $html );
		yume_assert_contains( '<input type="checkbox" id="yn-tome-annoncer" name="annoncer" value="1" aria-describedby="yn-tome-annoncer-aide">', $html, 'case « Annoncer » décochée par défaut' );
		yume_assert_contains( 'Annoncer « Le tome 2 est complet »', $html );
		yume_assert_contains( 'Proposé par le site', $html, 'aucun choix manuel' );
		yume_assert_same( 'https://www.clictune.com/pdf2', yume_liens_telechargement( $tome )['pdf'], 'tome « Publié » : liens montrés' );

		// « En cours » choisi : écran de confirmation, rien ne change encore.
		wp_set_current_user( $editeur );
		$r = traiter_formulaire_tome( yume_tte_post_tome( $tome, array( 'etat' => 'en_cours' ) ), array(), $editeur );
		yume_assert_same( 'yn-tome-etat-confirmation', $r['cible'], $r['message'] );
		yume_assert_same( array( 'etat' => 'en_cours' ), $r['args'] ?? array() );
		yume_assert_same( 'complet', yume_parution_tome( $tome ), 'rien ne change sans confirmation' );
		$adresse = yume_tte_admin_post( 'Yume\\Core\\Planning\\admin_post_modifier_tome', yume_tte_post_tome( $tome, array( 'etat' => 'en_cours' ) ) );
		yume_assert_same( url_modifier_tome( $tome, array( 'etat' => 'en_cours' ) ) . '#yn-tome-etat-confirmation', $adresse, 'redirection vers l’écran de confirmation' );
		$html = yume_tte_rendu( $editeur, array_merge( $vue, array( 'etat' => 'en_cours' ) ) );
		yume_assert_contains( 'id="yn-tome-etat-confirmation"', $html );
		yume_assert_contains( 'name="action" value="yume_tome_etat"', $html );
		yume_assert_contains( 'name="confirmer" value="1"', $html );
		yume_assert_contains( '(« En cours de publication ») ?', $html );
		yume_assert_contains( 'ne sont plus montrés aux lecteurs', $html );
		yume_assert_contains( 'Oui, rouvrir le tome', $html );
		yume_assert_not_contains( 'id="yn-tome-etat-confirmation"', yume_tte_rendu( $editeur, array_merge( $vue, array( 'etat' => 'complet' ) ) ), 'état déjà en place : pas d’écran' );

		// Nonce invalide, traducteur : rien ne change.
		yume_assert_same( 'erreur', yume_tte_confirmer_etat( $tome, 'en_cours', $editeur, array( '_yume_nonce' => 'faux' ) )['type'], 'nonce' );
		$r = yume_tte_confirmer_etat( $tome, 'en_cours', yume_tte_membre( 'yume_traducteur' ) );
		yume_assert_same( 'erreur', $r['type'], 'traducteur' );
		yume_assert_contains( 'Votre rôle ne permet pas', $r['message'] );
		yume_assert_same( 'complet', yume_parution_tome( $tome ) );

		$r = yume_tte_confirmer_etat( $tome, 'en_cours', $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'est rouvert', $r['message'] );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ) );
		yume_assert_same( 'https://www.clictune.com/pdf2', get_post_meta( $tome, 'yume_lien_pdf', true ), 'lien gardé en base' );
		yume_assert_same(
			array(
				'pdf'  => '',
				'epub' => '',
			),
			yume_liens_telechargement( $tome ),
			'liens masqués aux lecteurs'
		);
		yume_assert_same( 'publish', get_post_status( $tome ), 'tome toujours en ligne' );
		yume_assert_same( 'edition', get_post_meta( $tome, 'yume_etape', true ), 'planning remis à « Édition »' );
		$choix = yume_parution_manuelle( $tome );
		yume_assert_same( 'en_cours', $choix['etat'] ?? '', 'choix manuel noté' );
		yume_assert_same( $editeur, $choix['par'] ?? 0 );
		$journal = yume_tte_journal_parution( $tome );
		yume_assert_same( 'complet', $journal[0]->ancien ?? '', 'journal : ancien état' );
		yume_assert_same( '0', (string) ( $journal[0]->public ?? '' ), 'journal de l’équipe seulement' );
		yume_assert_contains( '"rouvert":true', (string) ( $journal[0]->nouveau ?? '' ) );
		yume_assert_contains(
			'état : Publié → En cours de publication (liens PDF et EPUB masqués)',
			\Yume\Core\Planning\texte_changement(
				(object) array(
					'champ'   => 'parution',
					'ancien'  => $journal[0]->ancien,
					'nouveau' => $journal[0]->nouveau,
				),
				true
			)
		);

		// Choix manuel non écrasé : chapitre programmé publié, enregistrement sans changement d'état.
		\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
		$r = traiter_action_chapitre(
			array(
				'chapitre_id' => (string) $prochain,
				'op'          => 'publier',
				'_yume_nonce' => wp_create_nonce( 'yume_tome_chapitre_' . $prochain ),
			),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		$r = traiter_formulaire_tome( yume_tte_post_tome( $tome ), array(), $editeur );
		yume_assert_same( 'yn-tome-form', $r['cible'], 'aucun changement d’état demandé' );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ), 'choix gardé' );
		yume_assert_same( 'en_cours', yume_parution_manuelle( $tome )['etat'] ?? '', 'marque gardée' );
		yume_assert_contains( 'Choisi par ', yume_tte_rendu( $editeur, $vue ) );
	}
);

yume_tte_test(
	'état du tome : « En cours » → « Publié » (Publication\Service::marquer_complet) — confirmation s’il manque des chapitres prévus, annonce seulement avec la case ; refus sans publish_yume_tomes et sans nonce',
	function () {
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$tome   = yume_tte_tome( $oeuvre, 2 );
		update_post_meta( $tome, 'yume_parution', 'en_cours' );
		update_post_meta( $tome, 'yume_chapitres_prevus', 3 );
		yume_tte_chapitre( $tome, 1 );
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );

		// Moins de chapitres en ligne que prévu : confirmation ; les autres champs sont enregistrés.
		$r = traiter_formulaire_tome(
			yume_tte_post_tome(
				$tome,
				array(
					'etat'      => 'complet',
					'lien_epub' => 'https://exemple.test/t2.epub',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'yn-tome-etat-confirmation', $r['cible'], $r['message'] );
		yume_assert_contains( 'est enregistré', $r['message'], 'champs enregistrés' );
		yume_assert_same( 'https://exemple.test/t2.epub', get_post_meta( $tome, 'yume_lien_epub', true ) );
		yume_assert_same( '', yume_liens_telechargement( $tome )['epub'], 'tome en cours : liens pas encore montrés' );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ) );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
				'etat'     => 'complet',
			)
		);
		yume_assert_contains( '2 chapitres prévus ne sont pas encore en ligne (1 sur 3). Le tome sera affiché « Publié » quand même.', $html );
		yume_assert_contains( 'id="yn-tome-etat-annoncer" name="annoncer" value="1">', $html, 'case « Annoncer » décochée sur l’écran de confirmation' );
		yume_assert_contains( 'Oui, passer à « Publié »', $html );

		// Droits et nonce : rien ne change.
		$r = yume_tte_sans_publier(
			$editeur,
			static function () use ( $tome, $editeur ) {
				return yume_tte_confirmer_etat( $tome, 'complet', $editeur );
			}
		);
		yume_assert_same( 'erreur', $r['type'], 'sans publish_yume_tomes' );
		yume_assert_contains( 'publier ni de retirer', $r['message'] );
		yume_assert_same( 'erreur', yume_tte_confirmer_etat( $tome, 'complet', $editeur, array( '_yume_nonce' => 'faux' ) )['type'], 'nonce' );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ), 'rien ne change' );

		// Confirmé sans la case : « Publié » sans annonce.
		$complets = array();
		$suivre   = static function ( $id, $annoncer ) use ( &$complets ) {
			$complets[] = array( (int) $id, (bool) $annoncer );
		};
		add_action( 'yume_tome_complet', $suivre, 10, 2 );
		$n = yume_tte_compter(
			function () use ( $tome, $editeur ) {
				$r = yume_tte_confirmer_etat( $tome, 'complet', $editeur );
				yume_assert_same( 'ok', $r['type'], $r['message'] );
				yume_assert_contains( 'sans annonce', $r['message'] );
			}
		);
		remove_action( 'yume_tome_complet', $suivre, 10 );
		yume_assert_same( array( array( $tome, false ) ), $complets, 'passage complet par le service de publication, sans annonce' );
		yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->discord, $n->articles ), 'aucune annonce' );
		yume_assert_same( 'complet', yume_parution_tome( $tome ) );
		yume_assert_same( 'publie', get_post_meta( $tome, 'yume_etape', true ), 'planning « publié »' );
		yume_assert_same( 'https://exemple.test/t2.epub', yume_liens_telechargement( $tome )['epub'], 'liens montrés' );
		yume_assert_same( 'complet', yume_parution_manuelle( $tome )['etat'] ?? '' );

		// Tous les chapitres prévus en ligne : sans confirmation ; case cochée : tome complet annoncé.
		$t3 = yume_tte_tome( $oeuvre, 3 );
		update_post_meta( $t3, 'yume_parution', 'en_cours' );
		update_post_meta( $t3, 'yume_chapitres_prevus', 1 );
		yume_tte_chapitre( $t3, 1 );
		$n = yume_tte_compter(
			function () use ( $t3, $editeur ) {
				wp_set_current_user( $editeur );
				$r = traiter_formulaire_tome(
					yume_tte_post_tome(
						$t3,
						array(
							'etat'     => 'complet',
							'annoncer' => '1',
						)
					),
					array(),
					$editeur
				);
				yume_assert_same( 'yn-tome-etat', $r['cible'], $r['message'] );
				yume_assert_contains( 'tome complet annoncé', $r['message'] );
			}
		);
		yume_assert_same( 'complet', yume_parution_tome( $t3 ) );
		yume_assert_same( 1, count( $n->articles ), 'article « Le tome 3 de Grimgar est complet »' );
		yume_assert_same( 1, count( $n->discord ), 'Discord « Tome complet »' );
	}
);

yume_tte_test(
	'état du tome : « En cours » → « Planifié » retire la lecture après confirmation — chapitres en ligne et programmés en brouillon marqués retirés, tome et annonce en brouillon, e-mails en attente et sortie groupée programmée annulés ; la sortie suivante est annoncée et repasse « En cours »',
	function () {
		global $wpdb;
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$tome   = yume_tte_tome( $oeuvre, 2 );
		update_post_meta( $tome, 'yume_parution', 'en_cours' );
		update_post_meta( $tome, '_yume_publie_notifie', gmdate( 'Y-m-d H:i:s' ) );
		$c1 = yume_tte_chapitre( $tome, 1 );
		$c2 = yume_tte_chapitre( $tome, 2 );
		$c3 = yume_tte_chapitre_programme( $tome, 3, time() + 2 * DAY_IN_SECONDS );
		$c4 = yume_tte_chapitre( $tome, 4, 'draft' );
		$c5 = yume_tte_chapitre( $tome, 5, 'draft' );

		// Sortie groupée programmée des chapitres 4 et 5.
		$sortie = Service::publier(
			$tome,
			wp_date( 'Y-m-d\TH:i', time() + 5 * DAY_IN_SECONDS ),
			array(
				'mode'   => Service::MODE_CHAPITRES,
				'sortie' => 'date',
			)
		);
		unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
		yume_assert_false( is_wp_error( $sortie ), is_wp_error( $sortie ) ? $sortie->get_error_message() : '' );
		$groupe = get_post_meta( $tome, Service::META_GROUPE, true );
		yume_assert_true( is_array( $groupe ), 'sortie groupée programmée' );
		yume_assert_true( (bool) wp_next_scheduled( Service::HOOK_GROUPE, array( $tome, (int) $groupe['ts'] ) ), 'tâche de la sortie groupée' );

		// Annonce parue et e-mail d'un abonné en attente.
		$article = yume_factory_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Grimgar, Tome 2 : Chapitres 1 et 2 disponibles !',
				'meta_input'  => array( Annonce::META_TOME => $tome ),
			)
		);
		$lecteur = yume_factory_user();
		\Yume\Core\Social\ajouter_favori( $lecteur, $oeuvre );
		yume_assert_same( 1, \Yume\Core\Social\alerter_sortie( $tome ), 'e-mail mis en file' );
		$table   = \Yume\Core\Planning\table_notifications();
		$attente = static function () use ( $wpdb, $table, $tome ): int {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE statut = 'attente' AND contexte = %s", 'alerte_sortie_' . $tome ) );
		};
		yume_assert_same( 1, $attente() );

		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$r = traiter_formulaire_tome( yume_tte_post_tome( $tome, array( 'etat' => 'a_paraitre' ) ), array(), $editeur );
		yume_assert_same( 'yn-tome-etat-confirmation', $r['cible'], $r['message'] );
		yume_assert_same( 'publish', get_post_status( $c1 ), 'rien ne change sans confirmation' );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
				'etat'     => 'a_paraitre',
			)
		);
		yume_assert_contains( 'à « Planifié » ?', $html );
		yume_assert_contains( 'Ce qui va être retiré : 2 chapitres retirés de la lecture, 3 chapitres programmés annulés, page du tome retirée, annonce dépubliée ; les notifications déjà envoyées ne peuvent pas être rappelées.', $html );
		yume_assert_contains( 'Oui, retirer de la lecture', $html );
		yume_assert_not_contains( 'name="comprendre"', $html, 'tome en cours : pas de case « Je comprends »' );

		// Sans publish_yume_tomes : refusé.
		$r = yume_tte_sans_publier(
			$editeur,
			static function () use ( $tome, $editeur ) {
				return yume_tte_confirmer_etat( $tome, 'a_paraitre', $editeur );
			}
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( 'publish', get_post_status( $tome ) );

		$r = yume_tte_confirmer_etat( $tome, 'a_paraitre', $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'repasse « Planifié ». 2 chapitres retirés de la lecture', $r['message'] );
		foreach ( array( $c1, $c2, $c3, $c4, $c5 ) as $c ) {
			clean_post_cache( $c );
			yume_assert_same( 'draft', get_post_status( $c ), 'chapitre ' . $c . ' en brouillon' );
			yume_assert_same( '1', (string) get_post_meta( $c, '_yume_retire', true ), 'chapitre ' . $c . ' marqué retiré' );
		}
		clean_post_cache( $tome );
		clean_post_cache( $article );
		yume_assert_same( 'draft', get_post_status( $tome ), 'tome en brouillon : plus rien de lisible' );
		yume_assert_same( 'draft', get_post_status( $article ), 'annonce en brouillon' );
		yume_assert_same( 0, $attente(), 'e-mail en attente annulé' );
		yume_assert_same( '', get_post_meta( $tome, Service::META_GROUPE, true ), 'sortie groupée annulée' );
		yume_assert_false( wp_next_scheduled( Service::HOOK_GROUPE, array( $tome, (int) $groupe['ts'] ) ), 'tâche de la sortie groupée supprimée' );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $tome ) );
		yume_assert_same( 'planifie', get_post_meta( $tome, 'yume_parution', true ) );
		yume_assert_same( 'planifie', yume_parution_manuelle( $tome )['etat'] ?? '' );
		yume_assert_false( metadata_exists( 'post', $tome, '_yume_publie_notifie' ), 'sortie oubliée : la prochaine sera annoncée' );
		yume_assert_false( metadata_exists( 'post', $tome, '_yume_planning_depublie' ), 'retour au planning, pas une dépublication passagère' );
		$journal = yume_tte_journal_parution( $tome );
		yume_assert_contains( '"retires":2', (string) ( $journal[0]->nouveau ?? '' ) );
		yume_assert_contains( '"annules":3', (string) ( $journal[0]->nouveau ?? '' ) );
		yume_assert_contains(
			'état : En cours de publication → Planifié (2 chapitres retirés de la lecture, 3 chapitres programmés annulés, tome retiré de la lecture)',
			\Yume\Core\Planning\texte_changement(
				(object) array(
					'champ'   => 'parution',
					'ancien'  => $journal[0]->ancien,
					'nouveau' => $journal[0]->nouveau,
				),
				true
			)
		);

		// La sortie suivante (« Ajouter des chapitres ») est annoncée normalement et repasse « En cours ».
		yume_tte_chapitre( $tome, 6, 'draft' );
		\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
		$n = yume_tte_compter(
			function () use ( $tome ) {
				$s = Service::publier(
					$tome,
					'maintenant',
					array(
						'mode'   => Service::MODE_CHAPITRES,
						'sortie' => 'maintenant',
					)
				);
				yume_assert_false( is_wp_error( $s ), is_wp_error( $s ) ? $s->get_error_message() : '' );
			}
		);
		yume_assert_same( array( $tome ), $n->tome, 'sortie du tome annoncée de nouveau (yume_tome_publie)' );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ), 'publier un chapitre d’un tome « Planifié » : « En cours »' );
		yume_assert_same( null, yume_parution_manuelle( $tome ), 'choix manuel remplacé par la publication' );
		$journal = yume_tte_journal_parution( $tome );
		yume_assert_contains( '"auto":true', (string) ( $journal[0]->nouveau ?? '' ), 'changement automatique journalisé' );
		yume_assert_same( 'draft', get_post_status( $c1 ), 'chapitres retirés : jamais republiés d’office' );
	}
);

yume_tte_test(
	'état du tome : « Publié » → « Planifié » retire le tome entier — confirmation appuyée (case « Je comprends » obligatoire) ; liens gardés en base',
	function () {
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$tome   = yume_tte_tome( $oeuvre, 4 );
		update_post_meta( $tome, 'yume_parution', 'complet' );
		$c1      = yume_tte_chapitre( $tome, 1 );
		$editeur = yume_tte_membre( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$r = traiter_formulaire_tome( yume_tte_post_tome( $tome, array( 'etat' => 'a_paraitre' ) ), array(), $editeur );
		yume_assert_same( 'yn-tome-etat-confirmation', $r['cible'], $r['message'] );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
				'etat'     => 'a_paraitre',
			)
		);
		yume_assert_contains( 'entier de la lecture ?', $html );
		yume_assert_contains( 'il disparaît entièrement de la lecture', $html );
		yume_assert_contains( '1 chapitre retiré de la lecture, page du tome retirée, liens PDF et EPUB masqués ;', $html );
		yume_assert_contains( 'name="comprendre" value="1" required', $html, 'case « Je comprends »' );

		$r = yume_tte_confirmer_etat( $tome, 'a_paraitre', $editeur );
		yume_assert_same( 'erreur', $r['type'], 'sans « Je comprends »' );
		yume_assert_same( 'yn-tome-etat-confirmation', $r['cible'], 'l’écran de confirmation reste ouvert' );
		yume_assert_contains( 'Je comprends', $r['message'] );
		yume_assert_same( 'publish', get_post_status( $tome ), 'rien ne change' );

		$r = yume_tte_confirmer_etat( $tome, 'a_paraitre', $editeur, array( 'comprendre' => '1' ) );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		clean_post_cache( $tome );
		clean_post_cache( $c1 );
		yume_assert_same( 'draft', get_post_status( $tome ) );
		yume_assert_same( 'draft', get_post_status( $c1 ) );
		yume_assert_same( 'https://www.clictune.com/pdf4', get_post_meta( $tome, 'yume_lien_pdf', true ), 'lien gardé en base' );
		yume_assert_same( '', yume_liens_telechargement( $tome )['pdf'], 'mais masqué' );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $tome ) );
	}
);

yume_tte_test(
	'état du tome : « Planifié » → « En cours » sans chapitre en ligne (rien ne change, lien « Ajouter des chapitres ») ; « Planifié » → « Publié » publie le tome et ses chapitres en attente (catalogue sans annonce, ou annoncé), tome vide confirmé ; refus sans publish_yume_tomes',
	function () {
		$oeuvre  = yume_tte_oeuvre( 'Grimgar' );
		$editeur = yume_tte_membre( 'yume_editeur' );
		$vide    = yume_tte_tome( $oeuvre, 5, 'draft' );
		delete_post_meta( $vide, 'yume_lien_pdf' );

		// « En cours » sans chapitre en ligne : message et lien, rien ne change.
		wp_set_current_user( $editeur );
		$adresse = yume_tte_admin_post( 'Yume\\Core\\Planning\\admin_post_modifier_tome', yume_tte_post_tome( $vide, array( 'etat' => 'en_cours' ) ) );
		yume_assert_same( url_modifier_tome( $vide ) . '#yn-tome-etat', $adresse );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $vide,
			)
		);
		yume_assert_contains( 'rien ne change', $html );
		yume_assert_contains( '<p class="yn-etat__suite"><a class="yn-btn yn-btn--sm yn-btn--primary" href="' . esc_url( url_publier_tome( $vide ) ) . '">Ajouter des chapitres</a></p>', $html );
		yume_assert_false( metadata_exists( 'post', $vide, 'yume_parution' ), 'parution inchangée' );
		yume_assert_same( 'draft', get_post_status( $vide ) );

		// « Publié » d'un tome vide : confirmation qui le dit, puis publication.
		$r = traiter_formulaire_tome( yume_tte_post_tome( $vide, array( 'etat' => 'complet' ) ), array(), $editeur );
		yume_assert_same( 'yn-tome-etat-confirmation', $r['cible'], $r['message'] );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $vide,
				'etat'     => 'complet',
			)
		);
		yume_assert_contains( 'aucun chapitre ni lien PDF ou EPUB', $html );
		yume_assert_contains( 'Oui, publier le tome', $html );
		$r = yume_tte_sans_publier(
			$editeur,
			static function () use ( $vide, $editeur ) {
				return yume_tte_confirmer_etat( $vide, 'complet', $editeur );
			}
		);
		yume_assert_same( 'erreur', $r['type'], 'sans publish_yume_tomes' );
		yume_assert_same( 'draft', get_post_status( $vide ) );
		$r = yume_tte_confirmer_etat( $vide, 'complet', $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		clean_post_cache( $vide );
		yume_assert_same( 'publish', get_post_status( $vide ), 'tome vide publié après confirmation' );
		yume_assert_same( 'complet', yume_parution_tome( $vide ) );

		// Tome et chapitres en attente, sans la case : ajout au catalogue sans annonce.
		$tome = yume_tte_tome( $oeuvre, 6, 'draft' );
		$c1   = yume_tte_chapitre( $tome, 1, 'draft' );
		$c2   = yume_tte_chapitre( $tome, 2, 'draft' );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
				'etat'     => 'complet',
			)
		);
		yume_assert_contains( 'Le tome et ses 2 chapitres en attente sont mis en ligne tout de suite.', $html );
		unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
		$n = yume_tte_compter(
			function () use ( $tome, $editeur ) {
				$r = yume_tte_confirmer_etat( $tome, 'complet', $editeur );
				yume_assert_same( 'ok', $r['type'], $r['message'] );
				yume_assert_contains( 'sans annonce', $r['message'] );
			}
		);
		yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->discord, $n->articles ), 'aucune annonce' );
		clean_post_cache( $tome );
		yume_assert_same( 'publish', get_post_status( $tome ) );
		yume_assert_same( 'publish', get_post_status( $c1 ) );
		yume_assert_same( 'publish', get_post_status( $c2 ) );
		yume_assert_same( 'complet', yume_parution_tome( $tome ) );
		yume_assert_same( 'https://www.clictune.com/pdf6', yume_liens_telechargement( $tome )['pdf'], 'liens montrés' );
		$journal = yume_tte_journal_parution( $tome );
		yume_assert_contains( '"publie":true', (string) ( $journal[0]->nouveau ?? '' ) );

		// Case cochée : sortie annoncée.
		$annonce = yume_tte_tome( $oeuvre, 7, 'draft' );
		yume_tte_chapitre( $annonce, 1, 'draft' );
		unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
		$n = yume_tte_compter(
			function () use ( $annonce, $editeur ) {
				$r = yume_tte_confirmer_etat( $annonce, 'complet', $editeur, array( 'annoncer' => '1' ) );
				yume_assert_same( 'ok', $r['type'], $r['message'] );
				yume_assert_contains( 'sortie annoncée', $r['message'] );
			}
		);
		yume_assert_same( array( $annonce ), $n->tome, 'yume_tome_publie' );
		yume_assert_same( 'complet', yume_parution_tome( $annonce ) );
		yume_assert_same( 'publie', get_post_meta( $annonce, 'yume_etape', true ), 'planning « publié »' );
	}
);

yume_tte_test(
	'modifier le tome : chapitres (états, actions selon le statut, ligne « À venir ») ; publier maintenant, changer la date, retirer avec confirmation — nonces et droits',
	function () {
		global $wpdb;
		$oeuvre = yume_tte_oeuvre( 'Grimgar' );
		$tome   = yume_tte_tome( $oeuvre, 2 );
		update_post_meta( $tome, 'yume_parution', 'en_cours' );
		update_post_meta( $tome, 'yume_chapitres_prevus', 6 );
		update_post_meta(
			$tome,
			'yume_rythme',
			array(
				'jour'  => 'samedi',
				'heure' => '18:00',
			)
		);
		$c1 = yume_tte_chapitre( $tome, 1 );
		update_post_meta( $c1, 'yume_temps_lecture', 19 );
		$c2        = yume_tte_chapitre_programme( $tome, 2, time() + 3 * DAY_IN_SECONDS );
		$c3        = yume_tte_chapitre( $tome, 3, 'draft' );
		$corbeille = yume_tte_chapitre( $tome, 9, 'draft' );
		wp_trash_post( $corbeille );
		$attente = yume_tte_chapitre( $tome, 10, 'draft' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => Remplacement::STATUT ), array( 'ID' => $attente ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $attente );
		$editeur = yume_tte_membre( 'yume_editeur' );
		$vue     = array(
			'vue'      => 'tomes',
			'modifier' => (string) $tome,
		);
		$html    = yume_tte_rendu( $editeur, $vue );
		$ligne   = static function ( int $id ) use ( &$html ): string {
			preg_match( '#<li class="yn-chapitres__ligne" id="yn-chapitre-' . $id . '">.*?</li>#s', $html, $m );
			return $m[0] ?? '';
		};

		yume_assert_contains( 'Chapitres (3)', $html );
		yume_assert_not_contains( 'id="yn-chapitre-' . $corbeille . '"', $html, 'corbeille exclue' );
		yume_assert_not_contains( 'id="yn-chapitre-' . $attente . '"', $html, 'version en attente exclue' );
		$l = $ligne( $c1 );
		yume_assert_contains( '>En ligne</span>', $l );
		yume_assert_contains( '· 19 min', $l );
		yume_assert_contains( esc_url( get_permalink( $c1 ) ) . '">Voir', $l );
		yume_assert_contains( esc_url( url_modifier_tome( $tome, array( 'retirer' => $c1 ) ) . '#yn-chapitre-' . $c1 ) . '">Retirer', $l );
		yume_assert_not_contains( 'Publier maintenant', $l );
		$l = $ligne( $c2 );
		yume_assert_contains( '>Programmé</span>', $l );
		yume_assert_contains( esc_url( get_preview_post_link( $c2 ) ) . '">Aperçu', $l );
		yume_assert_contains( 'name="op" value="publier"', $l );
		yume_assert_contains( 'Publier maintenant', $l );
		yume_assert_contains( 'type="datetime-local"', $l );
		yume_assert_contains( 'value="' . wp_date( 'Y-m-d\TH:i', (int) get_post_time( 'U', true, $c2 ) ) . '"', $l, 'date actuelle dans le champ' );
		yume_assert_contains( 'Changer la date', $l );
		yume_assert_contains( '">Retirer', $l, 'programmé : peut être retiré' );
		$l        = $ligne( $c3 );
		$proposee = yume_prochaine_sortie_rythme( $tome, ( new DateTimeImmutable( '@' . get_post_time( 'U', true, $c2 ) ) )->setTimezone( wp_timezone() ) );
		yume_assert_contains( '>Brouillon</span>', $l );
		yume_assert_contains( 'Publier maintenant', $l );
		yume_assert_contains( 'value="' . $proposee->format( 'Y-m-d\TH:i' ) . '"', $l, 'brouillon : date proposée selon le rythme' );
		yume_assert_not_contains( '">Retirer', $l );
		yume_assert_contains( 'yn-chapitres__ligne--a-venir', $html );
		yume_assert_contains( '>4–6</span>', $html );
		yume_assert_contains( '3 chapitres pas encore déposés (sur 6 prévus)', $html );
		yume_assert_contains( 'au rythme : ', $html );
		yume_assert_contains( '1 chapitre sur 6 en ligne', $html );
		yume_assert_contains( 'Chapitre 2 programmé le ', $html );

		// Nonce invalide, droits : rien ne change.
		wp_set_current_user( $editeur );
		$r = traiter_action_chapitre(
			array(
				'chapitre_id' => (string) $c2,
				'op'          => 'publier',
				'_yume_nonce' => 'faux',
			),
			$editeur
		);
		yume_assert_same( 'erreur', $r['type'], 'nonce' );
		yume_assert_same( 'future', get_post_status( $c2 ) );
		$trad = yume_tte_membre( 'yume_traducteur' );
		wp_set_current_user( $trad );
		$r = traiter_action_chapitre(
			array(
				'chapitre_id' => (string) $c2,
				'op'          => 'publier',
				'_yume_nonce' => wp_create_nonce( 'yume_tome_chapitre_' . $c2 ),
			),
			$trad
		);
		yume_assert_same( 'erreur', $r['type'], 'traducteur' );
		yume_assert_contains( 'Votre rôle ne permet pas', $r['message'] );
		yume_assert_same( 'future', get_post_status( $c2 ) );
		yume_assert_not_contains( 'yume_tome_chapitre', yume_tte_rendu( $trad, $vue ) );

		// Publier maintenant : en ligne tout de suite, sortie annoncée comme un chapitre programmé.
		$action = static function ( int $id, string $op, array $plus = array() ) use ( $editeur ): array {
			wp_set_current_user( $editeur );
			return traiter_action_chapitre(
				array_merge(
					array(
						'chapitre_id' => (string) $id,
						'op'          => $op,
						'_yume_nonce' => wp_create_nonce( 'yume_tome_chapitre_' . $id ),
					),
					$plus
				),
				$editeur
			);
		};
		// Nouvelle requête : le tome n'est pas « publié dans cette requête » (sortie groupée).
		\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
		$n = yume_tte_compter(
			function () use ( $action, $c2 ) {
				$r = $action( $c2, 'publier' );
				yume_assert_same( 'ok', $r['type'], $r['message'] );
				yume_assert_same( 'yn-chapitre-' . $c2, $r['cible'] );
			}
		);
		clean_post_cache( $c2 );
		yume_assert_same( 'publish', get_post_status( $c2 ) );
		yume_assert_true( abs( time() - (int) get_post_time( 'U', true, $c2 ) ) < 120, 'daté de maintenant' );
		yume_assert_same( array( $c2 ), $n->chap, 'sortie du chapitre (yume_chapitre_publie)' );
		yume_assert_same( 'erreur', $action( $c2, 'publier' )['type'], 'déjà en ligne' );

		// Changer la date : date passée refusée, date à venir → programmé.
		$r = $action( $c3, 'date', array( 'date' => wp_date( 'Y-m-d\TH:i', time() - HOUR_IN_SECONDS ) ) );
		yume_assert_same( 'erreur', $r['type'], 'date passée' );
		yume_assert_same( 'draft', get_post_status( $c3 ) );
		$quand = time() + 5 * DAY_IN_SECONDS;
		$r     = $action( $c3, 'date', array( 'date' => wp_date( 'Y-m-d\TH:i', $quand ) ) );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'programmé le', $r['message'] );
		clean_post_cache( $c3 );
		yume_assert_same( 'future', get_post_status( $c3 ) );
		yume_assert_same( wp_date( 'Y-m-d H:i', $quand ), substr( (string) get_post_field( 'post_date', $c3 ), 0, 16 ) );

		// Retirer : écran de confirmation (sans JavaScript), puis brouillon.
		$html = yume_tte_rendu( $editeur, array_merge( $vue, array( 'retirer' => (string) $c1 ) ) );
		$l    = $ligne( $c1 );
		yume_assert_contains( 'class="yn-chapitres__confirmation"', $l );
		yume_assert_contains( 'name="op" value="retirer"', $l );
		yume_assert_contains( 'name="confirmer" value="1"', $l );
		yume_assert_contains( 'Oui, retirer', $l );
		yume_assert_not_contains( 'class="yn-chapitres__confirmation"', $ligne( $c3 ), 'seul le chapitre demandé' );
		$r = $action( $c1, 'retirer' );
		yume_assert_same( 'erreur', $r['type'], 'sans confirmation' );
		yume_assert_same( 'publish', get_post_status( $c1 ) );
		$r = $action( $c1, 'retirer', array( 'confirmer' => '1' ) );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		clean_post_cache( $c1 );
		yume_assert_same( 'draft', get_post_status( $c1 ) );
		yume_assert_same( '1', (string) get_post_meta( $c1, '_yume_retire', true ), 'marqué retiré : jamais republié d’office' );

		// Admin-post : retour vers la fiche, message dans la ligne du chapitre.
		wp_set_current_user( $editeur );
		$adresse = yume_tte_admin_post(
			'Yume\\Core\\Planning\\admin_post_chapitre_tome',
			array(
				'chapitre_id' => (string) $c1,
				'op'          => 'publier',
				'_yume_nonce' => wp_create_nonce( 'yume_tome_chapitre_' . $c1 ),
			)
		);
		yume_assert_same( url_modifier_tome( $tome ) . '#yn-chapitre-' . $c1, $adresse );
		clean_post_cache( $c1 );
		yume_assert_same( 'publish', get_post_status( $c1 ) );
		yume_assert_false( metadata_exists( 'post', $c1, '_yume_retire' ), 'plus marqué retiré' );
		$html = yume_tte_rendu( $editeur, $vue );
		yume_assert_contains( '« Chapitre 1 » est en ligne.', $ligne( $c1 ) );

		// Tome pas encore en ligne : sa première sortie passe par « Ajouter des chapitres ».
		$brouillon = yume_tte_tome( $oeuvre, 5, 'draft' );
		$cb        = yume_tte_chapitre( $brouillon, 1, 'draft' );
		$r         = $action( $cb, 'publier' );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_contains( 'Ajouter des chapitres', $r['message'] );
		yume_assert_same( 'draft', get_post_status( $cb ) );
		$html = yume_tte_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $brouillon,
			)
		);
		yume_assert_not_contains( 'Publier maintenant', $html );
		yume_assert_contains( 'Le tome n’est pas encore en ligne', $html );
	}
);
