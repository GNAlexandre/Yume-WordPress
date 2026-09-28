<?php
/**
 * Tests des listes de lecture (PAGE-07, lot P3-D) : listes système et personnelles, routes
 * REST /moi/listes (CRUD, limites, droits), exclusivité des listes système, remplissage
 * automatique par la progression, fiche d'œuvre (menu et formulaire sans JavaScript), page
 * compte, page publique /listes/{id}-{slug}/ (visibilité, noindex, nom affiché), RGPD.
 *
 * Lancement : tools/localenv/test.sh listes
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Social\action_listes_oeuvre;
use function Yume\Core\Social\ajouter_a_liste;
use function Yume\Core\Social\creer_liste;
use function Yume\Core\Social\donnees_personnelles;
use function Yume\Core\Social\effacer_donnees;
use function Yume\Core\Social\exporter_donnees;
use function Yume\Core\Social\liste;
use function Yume\Core\Social\liste_affichee;
use function Yume\Core\Social\liste_systeme;
use function Yume\Core\Social\liste_template_redirect;
use function Yume\Core\Social\listes_contenant;
use function Yume\Core\Social\listes_utilisateur;
use function Yume\Core\Social\oeuvres_liste;
use function Yume\Core\Social\rendu_liste_publique;
use function Yume\Core\Social\robots_liste;
use function Yume\Core\Social\section_listes;
use function Yume\Core\Social\table_listes_oeuvres;
use function Yume\Core\Social\url_liste;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tlis_)
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre publiée avec un tome et des chapitres publiés (sans événement de sortie).
 *
 * @param int    $nb    Nombre de chapitres.
 * @param string $titre Titre de l'œuvre.
 * @return array{oeuvre:int,tome:int,chapitres:int[]}
 */
function yume_tlis_oeuvre( int $nb = 2, string $titre = '' ): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	$oeuvre = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => '' !== $titre ? $titre : 'Œuvre ' . wp_rand( 1, 999999 ),
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'  => 'yume_tome',
			'post_title' => 'Tome 1',
			'post_name'  => 'tome-1',
			'meta_input' => array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => 1,
				'yume_nature'    => 'tome',
			),
		)
	);
	$ids    = array();
	for ( $i = 1; $i <= $nb; $i++ ) {
		$ids[] = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => 'Chapitre ' . $i,
				'post_name'    => 'chapitre-' . $i,
				'menu_order'   => $i,
				'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id' => $tome,
					'yume_numero'  => $i,
					'yume_nature'  => 'chapitre',
				),
			)
		);
	}
	remove_filter( 'yume_core_notifier', '__return_false' );
	return array(
		'oeuvre'    => $oeuvre,
		'tome'      => $tome,
		'chapitres' => $ids,
	);
}

/**
 * Exécute $rappel et renvoie l'URL de redirection demandée (chaîne vide si aucune).
 *
 * @param callable $rappel Fonction.
 * @throws RuntimeException Toute exception autre que la redirection interceptée.
 */
function yume_tlis_redirection( callable $rappel ): string {
	$filtre = static function ( $url ) {
		throw new RuntimeException( (string) $url, 302 );
	};
	add_filter( 'wp_redirect', $filtre, 1 );
	try {
		$rappel();
	} catch ( RuntimeException $e ) {
		if ( 302 !== $e->getCode() ) {
			throw $e;
		}
		return $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filtre, 1 );
	}
	return '';
}

/**
 * Remplace $_POST le temps de $rappel.
 *
 * @param array    $donnees Champs.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_tlis_post( array $donnees, callable $rappel ) {
	$avant = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$_POST = wp_slash( $donnees );
	try {
		return $rappel();
	} finally {
		$_POST = $avant;
	}
}

/**
 * Place la requête principale sur la page d'une liste le temps de $rappel.
 *
 * @param int      $liste_id Liste.
 * @param callable $rappel   Fonction.
 * @return mixed
 */
function yume_tlis_sur_liste( int $liste_id, callable $rappel ) {
	global $wp_query, $wp_the_query;
	$avant_q   = $wp_query;
	$avant_the = $wp_the_query;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- contexte simulé puis restauré.
	$wp_query = new WP_Query();
	$wp_query->set( 'yume_liste', $liste_id );
	$wp_the_query = $wp_query;
	try {
		return $rappel();
	} finally {
		$wp_query     = $avant_q;
		$wp_the_query = $avant_the;
	}
	// phpcs:enable
}

/**
 * Identifiants des listes du membre, par clé système.
 *
 * @param int $user_id Membre.
 * @return array<string,int>
 */
function yume_tlis_systeme( int $user_id ): array {
	$ids = array();
	foreach ( listes_utilisateur( $user_id ) as $l ) {
		if ( '' !== $l['systeme'] ) {
			$ids[ $l['systeme'] ] = $l['id'];
		}
	}
	return $ids;
}

/*
 * -----------------------------------------------------------------------------
 * Listes, REST, limites et droits
 * -----------------------------------------------------------------------------
 */

yume_test(
	'PAGE-07 : trois listes système créées à la première consultation, dans l’ordre',
	function () {
		$u      = yume_factory_user();
		$listes = listes_utilisateur( $u );
		yume_assert_same( array( 'a_lire', 'en_cours', 'termine' ), wp_list_pluck( $listes, 'systeme' ) );
		yume_assert_same( array( 'À lire', 'En cours', 'Terminé' ), wp_list_pluck( $listes, 'nom' ) );
		// Pas de doublon à la seconde consultation.
		yume_assert_same( 3, count( listes_utilisateur( $u ) ) );
		$r = yume_rest( 'GET', '/yume/v1/moi/listes', array(), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 3, count( $r->get_data()['listes'] ) );
		yume_assert_true( $r->get_data()['auto'] );
		yume_assert_same( 401, yume_rest( 'GET', '/yume/v1/moi/listes' )->get_status() );
	}
);

yume_test(
	'PAGE-07 : REST créer, modifier, ajouter, retirer, supprimer une liste personnelle',
	function () {
		$s = yume_tlis_oeuvre( 1 );
		$u = yume_factory_user();
		$r = yume_rest(
			'POST',
			'/yume/v1/moi/listes',
			array(
				'nom'         => '  Mes <b>isekai</b>  préférés ',
				'description' => 'Pour les soirées.',
				'publique'    => true,
			),
			$u
		);
		yume_assert_same( 201, $r->get_status() );
		$liste = $r->get_data();
		yume_assert_same( 'Mes isekai préférés', $liste['nom'] );
		yume_assert_same( 'mes-isekai-preferes', $liste['slug'] );
		yume_assert_true( $liste['publique'] );
		yume_assert_same( null, $liste['systeme'] );
		yume_assert_contains( '/listes/' . $liste['id'] . '-mes-isekai-preferes', $liste['url'] );

		$r = yume_rest( 'PUT', '/yume/v1/moi/listes/' . $liste['id'] . '/oeuvres/' . $s['oeuvre'], array(), $u );
		yume_assert_same( 201, $r->get_status() );
		yume_assert_same( array( $liste['id'] ), $r->get_data()['listes'] );
		yume_assert_same( 200, yume_rest( 'PUT', '/yume/v1/moi/listes/' . $liste['id'] . '/oeuvres/' . $s['oeuvre'], array(), $u )->get_status(), 'déjà présente : 200' );
		$r     = yume_rest( 'GET', '/yume/v1/moi/listes', array( 'oeuvre' => $s['oeuvre'] ), $u );
		$perso = wp_list_filter( $r->get_data()['listes'], array( 'id' => $liste['id'] ) );
		yume_assert_true( reset( $perso )['contient'] );
		yume_assert_same( array( $s['oeuvre'] ), reset( $perso )['oeuvres'] );

		$r = yume_rest(
			'PATCH',
			'/yume/v1/moi/listes/' . $liste['id'],
			array(
				'nom'      => 'Isekai',
				'publique' => false,
			),
			$u
		);
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 'Isekai', $r->get_data()['nom'] );
		yume_assert_same( 'isekai', $r->get_data()['slug'] );
		yume_assert_false( $r->get_data()['publique'] );
		yume_assert_same( 'Pour les soirées.', $r->get_data()['description'], 'description inchangée' );

		$r = yume_rest( 'DELETE', '/yume/v1/moi/listes/' . $liste['id'] . '/oeuvres/' . $s['oeuvre'], array(), $u );
		yume_assert_same( array(), $r->get_data()['listes'] );
		yume_assert_same( 200, yume_rest( 'DELETE', '/yume/v1/moi/listes/' . $liste['id'], array(), $u )->get_status() );
		yume_assert_same( null, liste( $liste['id'] ) );

		// Œuvre non publiée : 404.
		$brouillon = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_status' => 'draft',
			)
		);
		$systeme   = yume_tlis_systeme( $u );
		yume_assert_same( 404, yume_rest( 'PUT', '/yume/v1/moi/listes/' . $systeme['a_lire'] . '/oeuvres/' . $brouillon, array(), $u )->get_status() );
		// Nom vide : 400.
		yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/moi/listes', array( 'nom' => '<i></i> ' ), $u )->get_status() );
	}
);

yume_test(
	'PAGE-07 : listes système ni renommées ni supprimées, et mutuellement exclusives',
	function () {
		$s  = yume_tlis_oeuvre( 1 );
		$u  = yume_factory_user();
		$id = yume_tlis_systeme( $u );
		yume_assert_same( 400, yume_rest( 'PATCH', '/yume/v1/moi/listes/' . $id['a_lire'], array( 'nom' => 'Autre' ), $u )->get_status() );
		yume_assert_same( 400, yume_rest( 'DELETE', '/yume/v1/moi/listes/' . $id['termine'], array(), $u )->get_status() );
		// Rendre publique une liste système : permis.
		$r = yume_rest( 'PATCH', '/yume/v1/moi/listes/' . $id['termine'], array( 'publique' => true ), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_true( $r->get_data()['publique'] );

		yume_rest( 'PUT', '/yume/v1/moi/listes/' . $id['a_lire'] . '/oeuvres/' . $s['oeuvre'], array(), $u );
		$r = yume_rest( 'PUT', '/yume/v1/moi/listes/' . $id['en_cours'] . '/oeuvres/' . $s['oeuvre'], array(), $u );
		yume_assert_same( array( $id['en_cours'] ), $r->get_data()['listes'], 'l’œuvre quitte « À lire »' );
		// Une liste personnelle s'ajoute sans rien retirer.
		$perso = creer_liste( $u, 'Favoris d’été' );
		$r     = yume_rest( 'PUT', '/yume/v1/moi/listes/' . $perso['id'] . '/oeuvres/' . $s['oeuvre'], array(), $u );
		$ids   = $r->get_data()['listes'];
		sort( $ids );
		yume_assert_same( array( $id['en_cours'], $perso['id'] ), $ids );
	}
);

yume_test(
	'PAGE-07 : limites de 20 listes personnelles et de 500 œuvres par liste',
	function () {
		global $wpdb;
		$s = yume_tlis_oeuvre( 1 );
		$u = yume_factory_user();
		for ( $i = 1; $i <= 20; $i++ ) {
			yume_assert_true( is_array( creer_liste( $u, 'Liste ' . $i ) ), 'liste ' . $i );
		}
		$r = yume_rest( 'POST', '/yume/v1/moi/listes', array( 'nom' => 'Vingt et unième' ), $u );
		yume_assert_same( 409, $r->get_status() );
		yume_assert_same( 'yume_listes_limite', $r->get_data()['code'] );
		yume_assert_same( 23, count( listes_utilisateur( $u ) ), '20 personnelles + 3 système' );

		// 500 œuvres déjà dans la liste (lignes insérées directement) : la 501e est refusée.
		$pleine  = creer_liste( yume_factory_user(), 'Pleine' );
		$table   = table_listes_oeuvres();
		$valeurs = array();
		for ( $i = 1; $i <= 500; $i++ ) {
			$valeurs[] = $wpdb->prepare( '(%d, %d, %s, %d)', $pleine['id'], 9000000 + $i, current_time( 'mysql', true ), $i );
		}
		$wpdb->query( "INSERT INTO {$table} (liste_id, oeuvre_id, ajoute_le, ordre) VALUES " . implode( ', ', $valeurs ) ); // phpcs:ignore WordPress.DB
		$r = yume_rest( 'PUT', '/yume/v1/moi/listes/' . $pleine['id'] . '/oeuvres/' . $s['oeuvre'], array(), $pleine['user_id'] );
		yume_assert_same( 409, $r->get_status() );
		yume_assert_same( 'yume_liste_pleine', $r->get_data()['code'] );
	}
);

yume_test(
	'PAGE-07 : impossible de lire ou modifier la liste d’un autre membre (404)',
	function () {
		$s      = yume_tlis_oeuvre( 1 );
		$a      = yume_factory_user();
		$b      = yume_factory_user();
		$liste  = creer_liste( $a, 'Secrète' );
		$chemin = '/yume/v1/moi/listes/' . $liste['id'];
		yume_assert_same( 404, yume_rest( 'PATCH', $chemin, array( 'nom' => 'Piratée' ), $b )->get_status() );
		yume_assert_same( 404, yume_rest( 'DELETE', $chemin, array(), $b )->get_status() );
		yume_assert_same( 404, yume_rest( 'PUT', $chemin . '/oeuvres/' . $s['oeuvre'], array(), $b )->get_status() );
		ajouter_a_liste( $liste, $s['oeuvre'] );
		yume_assert_same( 404, yume_rest( 'DELETE', $chemin . '/oeuvres/' . $s['oeuvre'], array(), $b )->get_status() );
		yume_assert_same( 'Secrète', liste( $liste['id'] )['nom'] );
		yume_assert_same( array( $s['oeuvre'] ), oeuvres_liste( $liste['id'] ) );
		// Les listes de B ne montrent pas celle de A.
		$ids = wp_list_pluck( yume_rest( 'GET', '/yume/v1/moi/listes', array(), $b )->get_data()['listes'], 'id' );
		yume_assert_false( in_array( $liste['id'], $ids, true ) );
		yume_assert_same( 401, yume_rest( 'PATCH', $chemin, array( 'nom' => 'x' ) )->get_status() );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Remplissage automatique
 * -----------------------------------------------------------------------------
 */

yume_test(
	'PAGE-07 : « En cours » à la première lecture, « Terminé » au dernier chapitre, désactivable',
	function () {
		global $wpdb;
		$s  = yume_tlis_oeuvre( 2 );
		$u  = yume_factory_user();
		$id = yume_tlis_systeme( $u );
		ajouter_a_liste( liste( $id['a_lire'] ), $s['oeuvre'] );

		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][0], 3, 40 );
		yume_assert_same( array( $id['en_cours'] ), array_keys( listes_contenant( $u, $s['oeuvre'] ) ), 'quitte « À lire » pour « En cours »' );

		// Dernier chapitre lu à moins de 90 % : toujours en cours.
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][1], 2, 50 );
		yume_assert_same( array( $id['en_cours'] ), array_keys( listes_contenant( $u, $s['oeuvre'] ) ) );
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][1], 9, 95 );
		yume_assert_same( array( $id['termine'] ), array_keys( listes_contenant( $u, $s['oeuvre'] ) ), 'terminée' );

		// Relecture d'un ancien chapitre : reste « Terminé ».
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][0], 1, 10 );
		yume_assert_same( array( $id['termine'] ), array_keys( listes_contenant( $u, $s['oeuvre'] ) ), 'relecture' );

		// Nouveau chapitre sorti après le classement : retour dans « En cours ».
		$wpdb->update( table_listes_oeuvres(), array( 'ajoute_le' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'liste_id' => $id['termine'] ) ); // phpcs:ignore WordPress.DB
		add_filter( 'yume_core_notifier', '__return_false' );
		$nouveau = yume_factory_post(
			array(
				'post_type'  => 'yume_chapitre',
				'post_title' => 'Chapitre 3',
				'menu_order' => 3,
				'meta_input' => array(
					'yume_tome_id' => $s['tome'],
					'yume_numero'  => 3,
					'yume_nature'  => 'chapitre',
				),
			)
		);
		remove_filter( 'yume_core_notifier', '__return_false' );
		\Yume\Core\Reader\enregistrer_progression( $u, $nouveau, 1, 20 );
		yume_assert_same( array( $id['en_cours'] ), array_keys( listes_contenant( $u, $s['oeuvre'] ) ), 'nouvelle sortie' );

		// Désactivé par le membre : rien ne bouge.
		$v = yume_factory_user();
		update_user_meta( $v, 'yume_listes_auto', '0' );
		\Yume\Core\Reader\enregistrer_progression( $v, $s['chapitres'][0], 1, 20 );
		yume_assert_same( array(), listes_contenant( $v, $s['oeuvre'] ) );
		yume_assert_false( yume_rest( 'GET', '/yume/v1/moi/listes', array(), $v )->get_data()['auto'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Fiche d'œuvre et page compte
 * -----------------------------------------------------------------------------
 */

yume_test(
	'PAGE-07 : fiche d’œuvre — menu « Ajouter à une liste » à côté de Favori pour un membre seulement',
	function () {
		$s     = yume_tlis_oeuvre( 1 );
		$u     = yume_factory_user();
		$rendu = static function () use ( $s ) {
			return yume_render_block( 'yume/oeuvre-actions', array() );
		};
		$bloc  = static function () use ( $s, $rendu ) {
			global $wp_query, $wp_the_query, $post;
			$avant = array( $wp_query, $wp_the_query, $post );
			// phpcs:disable WordPress.WP.GlobalVariablesOverride
			$wp_query     = new WP_Query(
				array(
					'p'         => $s['oeuvre'],
					'post_type' => 'yume_oeuvre',
				)
			);
			$wp_the_query = $wp_query;
			$post         = get_post( $s['oeuvre'] );
			try {
				return $rendu();
			} finally {
				list( $wp_query, $wp_the_query, $post ) = $avant;
			}
			// phpcs:enable
		};
		yume_assert_not_contains( 'data-yn-menu="listes"', $bloc(), 'visiteur' );
		wp_set_current_user( $u );
		$html = $bloc();
		wp_set_current_user( 0 );
		yume_assert_contains( 'data-yn-menu="listes"', $html );
		yume_assert_contains( 'Ajouter à une liste', $html );
		yume_assert_contains( 'value="yume_listes_oeuvre"', $html );
		yume_assert_contains( '>À lire<', $html );
		yume_assert_contains( 'name="yn_nouvelle"', $html );
		// Dans la ligne des boutons, avant la zone d'annonce.
		$ligne = strpos( $html, 'yn-oeuvre-actions__ligne' );
		$menu  = strpos( $html, 'data-yn-menu="listes"' );
		$zone  = strpos( $html, 'data-yn-annonce' );
		yume_assert_true( $ligne < $menu && $menu < $zone, 'menu dans la ligne des actions' );
		yume_assert_true( strpos( $html, 'data-yn-favori' ) < $menu, 'après Favori' );
	}
);

yume_test(
	'PAGE-07 : formulaire de la fiche sans JavaScript (cases et création rapide)',
	function () {
		$s  = yume_tlis_oeuvre( 1 );
		$u  = yume_factory_user();
		$id = yume_tlis_systeme( $u );
		wp_set_current_user( $u );
		$url = yume_tlis_redirection(
			static function () use ( $s, $id ) {
				yume_tlis_post(
					array(
						'yn_oeuvre'   => $s['oeuvre'],
						'yn_retour'   => get_permalink( $s['oeuvre'] ),
						'_yn_nonce'   => wp_create_nonce( 'yume_social_' . $s['oeuvre'] ),
						'yn_listes'   => array( $id['a_lire'] ),
						'yn_nouvelle' => 'Coups de cœur',
					),
					static function () {
						action_listes_oeuvre();
					}
				);
			}
		);
		wp_set_current_user( 0 );
		yume_assert_contains( 'yn-lmsg=liste-creee', $url );
		yume_assert_contains( '#yn-oeuvre-actions', $url );
		$noms = array();
		foreach ( listes_utilisateur( $u ) as $l ) {
			if ( in_array( $s['oeuvre'], oeuvres_liste( $l['id'] ), true ) ) {
				$noms[] = $l['nom'];
			}
		}
		yume_assert_same( array( 'À lire', 'Coups de cœur' ), $noms );
	}
);

yume_test(
	'PAGE-07 : page compte — rubrique « Mes listes » (gérer, renommer, rendre publique, supprimer)',
	function () {
		$s     = yume_tlis_oeuvre( 1, 'Grimgar' );
		$u     = yume_factory_user();
		$liste = creer_liste( $u, 'À partager', 'Mes conseils', true );
		ajouter_a_liste( $liste, $s['oeuvre'] );
		wp_set_current_user( $u );
		$html   = section_listes( $u );
		$compte = yume_render_block( 'yume/account' );
		wp_set_current_user( 0 );
		yume_assert_contains( 'id="yn-listes"', $html );
		yume_assert_contains( 'À partager', $html );
		yume_assert_contains( 'Grimgar', $html );
		yume_assert_contains( esc_html( url_liste( $liste ) ), $html );
		foreach ( array( 'yume_liste_modifier', 'yume_liste_supprimer', 'yume_liste_retirer', 'yume_liste_creer', 'yume_listes_auto' ) as $action ) {
			yume_assert_contains( 'value="' . $action . '"', $html, $action );
		}
		yume_assert_contains( 'href="#yn-listes"', $compte );
		yume_assert_contains( 'href="#yn-notifications"', $compte );
		yume_assert_not_contains( 'v2.1', $compte );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page publique
 * -----------------------------------------------------------------------------
 */

yume_test(
	'PAGE-07 : liste publique visible sans connexion (titre, nom affiché, couvertures), noindex',
	function () {
		$s = yume_tlis_oeuvre( 1, 'Danmachi' );
		$u = yume_factory_user();
		wp_update_user(
			array(
				'ID'           => $u,
				'display_name' => 'Hestia Fan',
			)
		);
		$liste = creer_liste( $u, 'Mes pépites', 'À lire absolument.', true );
		ajouter_a_liste( $liste, $s['oeuvre'] );
		yume_assert_contains( '/listes/' . $liste['id'] . '-mes-pepites/', url_liste( $liste ) );
		$regles = (array) get_option( 'rewrite_rules' );
		yume_assert_true( isset( $regles['^listes/([0-9]+)(?:-([^/]*))?/?$'] ), 'règle de réécriture enregistrée' );

		$html = yume_tlis_sur_liste(
			$liste['id'],
			static function () {
				liste_template_redirect();
				yume_assert_false( is_404() );
				$robots = robots_liste( array( 'max-image-preview' => 'large' ) );
				yume_assert_true( ! empty( $robots['noindex'] ) );
				return rendu_liste_publique();
			}
		);
		yume_assert_contains( '<h1 class="yn-liste-publique__titre">Mes pépites</h1>', $html );
		yume_assert_contains( 'Hestia Fan', $html );
		yume_assert_not_contains( get_userdata( $u )->user_login, $html, 'jamais l’identifiant de connexion' );
		yume_assert_contains( 'Danmachi', $html );
		yume_assert_contains( 'yn-cover', $html );
		yume_assert_contains( 'À lire absolument.', $html );
		yume_assert_not_contains( 'Gérer mes listes', $html, 'visiteur : pas de lien de gestion' );
	}
);

yume_test(
	'PAGE-07 : liste privée → 404 pour un autre (et un visiteur), visible par son propriétaire',
	function () {
		$u     = yume_factory_user();
		$autre = yume_factory_user();
		$liste = creer_liste( $u, 'Privée' );
		foreach ( array( 0, $autre ) as $qui ) {
			wp_set_current_user( $qui );
			yume_tlis_sur_liste(
				$liste['id'],
				static function () {
					yume_assert_same( null, liste_affichee() );
					liste_template_redirect();
					yume_assert_true( is_404() );
					yume_assert_same( '', rendu_liste_publique() );
				}
			);
		}
		wp_set_current_user( $u );
		$html = yume_tlis_sur_liste(
			$liste['id'],
			static function () {
				return rendu_liste_publique();
			}
		);
		wp_set_current_user( 0 );
		yume_assert_contains( 'Privée : vous seul voyez cette page', $html );
		// Liste inconnue : 404.
		yume_tlis_sur_liste(
			999999,
			static function () {
				liste_template_redirect();
				yume_assert_true( is_404() );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * RGPD et nettoyage
 * -----------------------------------------------------------------------------
 */

yume_test(
	'PAGE-07 : listes dans l’export RGPD, effacées avec les données et le compte',
	function () {
		$s     = yume_tlis_oeuvre( 1, 'Overlord' );
		$u     = yume_factory_user();
		$liste = creer_liste( $u, 'Sombre', 'Des héros pas très héroïques', true );
		ajouter_a_liste( $liste, $s['oeuvre'] );
		$export = donnees_personnelles( $u );
		$noms   = wp_list_pluck( $export['listes'], 'nom' );
		yume_assert_true( in_array( 'Sombre', $noms, true ) );
		$sombre = wp_list_filter( $export['listes'], array( 'nom' => 'Sombre' ) );
		yume_assert_same( 'Overlord', reset( $sombre )['oeuvres'][0]['oeuvre'] );
		$json = wp_json_encode( yume_rest( 'GET', '/yume/v1/moi/export', array(), $u )->get_data() );
		yume_assert_contains( 'Sombre', $json );
		$items = exporter_donnees( get_userdata( $u )->user_email )['data'];
		yume_assert_true( in_array( 'yume-listes', wp_list_pluck( $items, 'group_id' ), true ) );

		yume_assert_true( effacer_donnees( $u ) > 0 );
		yume_assert_same( null, liste( $liste['id'] ) );
		yume_assert_same( array(), listes_utilisateur( $u, false ) );

		// Suppression du compte (administration) : listes supprimées.
		$v     = yume_factory_user();
		$autre = creer_liste( $v, 'Temporaire' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $v );
		yume_assert_same( null, liste( $autre['id'] ) );
	}
);

yume_test(
	'PAGE-07 : une œuvre supprimée quitte les listes ; une œuvre dépubliée n’y est plus affichée',
	function () {
		$s     = yume_tlis_oeuvre( 1 );
		$t     = yume_tlis_oeuvre( 1 );
		$u     = yume_factory_user();
		$liste = liste_systeme( $u, 'a_lire' );
		ajouter_a_liste( $liste, $s['oeuvre'] );
		ajouter_a_liste( $liste, $t['oeuvre'] );
		wp_update_post(
			array(
				'ID'          => $t['oeuvre'],
				'post_status' => 'draft',
			)
		);
		yume_assert_same( array( $s['oeuvre'] ), oeuvres_liste( $liste['id'] ) );
		yume_assert_same( 2, count( oeuvres_liste( $liste['id'], false ) ) );
		wp_delete_post( $s['oeuvre'], true );
		yume_assert_same( array( $t['oeuvre'] ), oeuvres_liste( $liste['id'], false ) );
	}
);
