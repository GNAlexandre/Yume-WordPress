<?php
/**
 * Tests du module core : types, taxonomies, termes, rôles et capacités, permaliens et
 * résolution des URL, unicité des slugs par œuvre et par tome, métadonnées et schémas REST,
 * API §7, événements §8, réglages, synchronisation des termes, administration.
 *
 * Commande : tools/localenv/test.sh core
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Core\assainir_reglages;
use function Yume\Core\Core\compter_mots;
use function Yume\Core\Core\definitions_roles;
use function Yume\Core\Core\installer_roles;
use function Yume\Core\Core\installer_termes;
use function Yume\Core\Core\regles_reecriture;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tc_)
 * -----------------------------------------------------------------------------
 */

/**
 * Crée une œuvre publiée.
 *
 * @param string $titre Titre.
 * @param array  $args  Arguments wp_insert_post supplémentaires.
 */
function yume_tc_oeuvre( string $titre, array $args = array() ): int {
	return yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => $titre,
				'post_status' => 'publish',
			),
			$args
		)
	);
}

/**
 * Crée un tome publié d'une œuvre (slug tome-{numero}).
 *
 * @param int   $oeuvre_id Œuvre.
 * @param mixed $numero    Numéro.
 * @param array $args      Arguments supplémentaires.
 */
function yume_tc_tome( int $oeuvre_id, $numero, array $args = array() ): int {
	return yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
				'post_name'   => 'tome-' . str_replace( '.', '-', (string) $numero ),
				'post_status' => 'publish',
				'meta_input'  => array(
					'yume_oeuvre_id' => $oeuvre_id,
					'yume_numero'    => $numero,
				),
			),
			$args
		)
	);
}

/**
 * Crée un chapitre publié d'un tome.
 *
 * @param int   $tome_id Tome.
 * @param mixed $numero  Numéro (null pour un spécial).
 * @param array $args    Arguments supplémentaires.
 */
function yume_tc_chapitre( int $tome_id, $numero, array $args = array() ): int {
	$meta = array( 'yume_tome_id' => $tome_id );
	if ( null !== $numero ) {
		$meta['yume_numero'] = $numero;
	}
	return yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => null === $numero ? 'Spécial' : 'Chapitre ' . $numero,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Texte du chapitre.</p><!-- /wp:paragraph -->',
				'meta_input'   => $meta,
			),
			$args
		)
	);
}

/**
 * Analyse une URL comme la requête principale (WP::parse_request) et renvoie les variables.
 *
 * @param string $url URL ou chemin.
 * @return array<string,mixed>
 */
function yume_tc_parse( string $url ): array {
	// Valeurs brutes sauvegardées puis restaurées telles quelles (aucune sortie ni usage).
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput
	$sauve = array(
		'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
		'PHP_SELF'    => $_SERVER['PHP_SELF'] ?? null,
		'PATH_INFO'   => $_SERVER['PATH_INFO'] ?? null,
	);
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	$chemin                 = (string) wp_parse_url( $url, PHP_URL_PATH );
	$requete                = (string) wp_parse_url( $url, PHP_URL_QUERY );
	$_SERVER['REQUEST_URI'] = $chemin . ( '' !== $requete ? '?' . $requete : '' );
	$_SERVER['PHP_SELF']    = '/index.php';
	unset( $_SERVER['PATH_INFO'] );
	try {
		$wp                    = new WP();
		$wp->public_query_vars = $GLOBALS['wp']->public_query_vars;
		$wp->parse_request();
		return $wp->query_vars;
	} finally {
		foreach ( $sauve as $cle => $valeur ) {
			if ( null === $valeur ) {
				unset( $_SERVER[ $cle ] );
			} else {
				$_SERVER[ $cle ] = $valeur;
			}
		}
	}
}

/**
 * Exécute une URL comme la requête principale et renvoie la requête WP_Query obtenue.
 *
 * @param string $url URL.
 */
function yume_tc_requete( string $url ): WP_Query {
	return new WP_Query( yume_tc_parse( $url ) );
}

/**
 * Enregistre les appels d'une action pendant l'exécution de $code.
 *
 * @param string   $action Action.
 * @param callable $code   Code à exécuter.
 * @return int[] Arguments reçus.
 */
function yume_tc_ecouter( string $action, callable $code ): array {
	$recus    = array();
	$ecouteur = static function ( $id ) use ( &$recus ) {
		$recus[] = (int) $id;
	};
	add_action( $action, $ecouteur, 50 );
	try {
		$code();
	} finally {
		remove_action( $action, $ecouteur, 50 );
	}
	return $recus;
}

/**
 * Exécute $code sans événements métier (§8) : filtre yume_core_notifier à faux, comme la
 * migration. Sert aux jeux de données qui publient des tomes datés de maintenant quand le
 * test ne porte pas sur les événements : sinon le planning applique légitimement le §8
 * (étape « publie », avancements à 100) et fausse les valeurs vérifiées.
 *
 * @param callable $code Code à exécuter.
 * @return mixed Valeur renvoyée par $code.
 */
function yume_tc_sans_evenements( callable $code ) {
	add_filter( 'yume_core_notifier', '__return_false', 99 );
	try {
		return $code();
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false', 99 );
	}
}

/**
 * Oublie les contenus publiés « pendant cette requête » (simule une nouvelle requête).
 */
function yume_tc_nouvelle_requete(): void {
	wp_cache_delete( 'publies_yume_tome', 'yume_core_requete' );
	wp_cache_delete( 'publies_yume_chapitre', 'yume_core_requete' );
}

/**
 * Pièce jointe factice (sans fichier) pour les couvertures.
 *
 * @param int $parent_id Contenu parent.
 */
function yume_tc_image( int $parent_id = 0 ): int {
	return (int) wp_insert_attachment(
		array(
			'post_title'     => 'Image ' . wp_rand( 1, 99999 ),
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		false,
		$parent_id
	);
}

/*
 * -----------------------------------------------------------------------------
 * Types de contenu et taxonomies
 * -----------------------------------------------------------------------------
 */

yume_test(
	'types de contenu enregistrés selon le contrat',
	function () {
		$attendus = array(
			'yume_oeuvre'   => 'oeuvres',
			'yume_tome'     => 'tomes',
			'yume_chapitre' => 'chapitres',
		);
		foreach ( $attendus as $type => $rest_base ) {
			$objet = get_post_type_object( $type );
			yume_assert_true( $objet instanceof WP_Post_Type, "$type enregistré" );
			yume_assert_same( $rest_base, $objet->rest_base );
			yume_assert_true( $objet->show_in_rest );
			yume_assert_same( 'yume', $objet->show_in_menu );
			yume_assert_true( $objet->map_meta_cap );
			yume_assert_same( 'edit_oeuvres' === 'edit_' . $rest_base ? 'edit_yume_oeuvres' : $objet->cap->edit_posts, $objet->cap->edit_posts );
			yume_assert_true( post_type_supports( $type, 'custom-fields' ), "$type supporte custom-fields" );
			yume_assert_true( post_type_supports( $type, 'comments' ), "$type supporte comments" );
		}
		yume_assert_same( 'edit_yume_tomes', get_post_type_object( 'yume_tome' )->cap->edit_posts );
		yume_assert_same( 'publish_yume_chapitres', get_post_type_object( 'yume_chapitre' )->cap->publish_posts );
		yume_assert_true( post_type_supports( 'yume_tome', 'page-attributes' ) );
		yume_assert_true( post_type_supports( 'yume_oeuvre', 'revisions' ) );
		yume_assert_same( 'Ajouter une œuvre', get_post_type_object( 'yume_oeuvre' )->labels->add_new_item );
		yume_assert_same( 'Aucun tome trouvé.', get_post_type_object( 'yume_tome' )->labels->not_found );
		yume_assert_same( 'oeuvres', get_post_type_object( 'yume_oeuvre' )->has_archive );
	}
);

yume_test(
	'taxonomies : hiérarchie, objets rattachés, REST',
	function () {
		yume_assert_true( is_taxonomy_hierarchical( 'yume_type' ) );
		yume_assert_true( is_taxonomy_hierarchical( 'yume_statut' ) );
		yume_assert_false( is_taxonomy_hierarchical( 'yume_genre' ) );
		yume_assert_false( is_taxonomy_hierarchical( 'yume_oeuvre_liee' ) );
		yume_assert_same( array( 'yume_oeuvre' ), get_taxonomy( 'yume_type' )->object_type );
		yume_assert_same( array( 'post' ), get_taxonomy( 'yume_oeuvre_liee' )->object_type );
		foreach ( array( 'yume_type', 'yume_statut', 'yume_genre', 'yume_oeuvre_liee' ) as $tax ) {
			yume_assert_true( get_taxonomy( $tax )->show_in_rest, "$tax en REST" );
		}
		// Personne ne crée de terme « œuvre liée » à la main.
		yume_assert_same( 'do_not_allow', get_taxonomy( 'yume_oeuvre_liee' )->cap->edit_terms );
	}
);

yume_test(
	'termes par défaut créés (et installation idempotente)',
	function () {
		installer_termes();
		installer_termes();
		yume_assert_same(
			array(
				'light-novel' => 'Light novel',
				'web-novel'   => 'Web novel',
				'manga'       => 'Manga',
			),
			yume_types()
		);
		yume_assert_same( array( 'en-cours', 'terminee', 'en-pause', 'licenciee', 'abandonnee' ), array_keys( yume_statuts() ) );
		yume_assert_same( 'Licenciée', yume_statuts()['licenciee'] );
		yume_assert_same(
			3,
			(int) wp_count_terms(
				array(
					'taxonomy'   => 'yume_type',
					'hide_empty' => false,
				)
			)
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Rôles et capacités
 * -----------------------------------------------------------------------------
 */

yume_test(
	'rôles créés avec leurs noms et capacités',
	function () {
		installer_roles();
		$noms = wp_roles()->get_names();
		yume_assert_same( 'Traducteur', $noms['yume_traducteur'] );
		yume_assert_same( 'Relecteur', $noms['yume_relecteur'] );
		yume_assert_same( 'Graphiste', $noms['yume_graphiste'] );
		yume_assert_same( 'Éditeur Yume', $noms['yume_editeur'] );
		yume_assert_same( 'Gérant', $noms['yume_gerant'] );
		yume_assert_same( 'Lecteur', $noms['subscriber'] );

		$trad = get_role( 'yume_traducteur' );
		foreach ( array( 'read', 'upload_files', 'yume_voir_equipe', 'yume_maj_planning', 'edit_yume_tomes' ) as $cap ) {
			yume_assert_true( $trad->has_cap( $cap ), "traducteur : $cap" );
		}
		yume_assert_false( $trad->has_cap( 'yume_publier' ) );
		yume_assert_false( $trad->has_cap( 'publish_yume_tomes' ) );

		$editeur = get_role( 'yume_editeur' );
		foreach ( array( 'yume_publier', 'yume_maj_planning_tous', 'edit_others_yume_chapitres', 'publish_yume_oeuvres', 'delete_yume_tomes', 'edit_posts', 'publish_posts', 'edit_published_posts', 'moderate_comments', 'manage_categories' ) as $cap ) {
			yume_assert_true( $editeur->has_cap( $cap ), "éditeur : $cap" );
		}
		yume_assert_false( $editeur->has_cap( 'yume_reglages' ) );

		$gerant = get_role( 'yume_gerant' );
		foreach ( array( 'yume_gerer_equipe', 'yume_reglages', 'edit_others_posts', 'delete_others_posts', 'list_users', 'yume_publier' ) as $cap ) {
			yume_assert_true( $gerant->has_cap( $cap ), "gérant : $cap" );
		}
		$admin = get_role( 'administrator' );
		foreach ( array( 'yume_reglages', 'yume_gerer_equipe', 'yume_maj_planning_tous', 'edit_others_yume_oeuvres', 'delete_published_yume_chapitres' ) as $cap ) {
			yume_assert_true( $admin->has_cap( $cap ), "administrateur : $cap" );
		}
		$lecteur = array_keys( array_filter( get_role( 'subscriber' )->capabilities ) );
		yume_assert_true( in_array( 'read', $lecteur, true ) );
		yume_assert_same( array(), preg_grep( '/yume/', $lecteur ), 'le lecteur n’a aucune capacité Yume' );
	}
);

yume_test(
	'installation des rôles idempotente et sans enregistrer le renommage « Lecteur »',
	function () {
		installer_roles();
		$avant = get_option( wp_roles()->role_key );
		installer_roles();
		yume_assert_same( $avant, get_option( wp_roles()->role_key ) );
		yume_assert_true( 'Lecteur' !== $avant['subscriber']['name'], 'le nom stocké du rôle subscriber est inchangé' );
		// Un rôle existant auquel il manque une capacité est complété.
		get_role( 'yume_relecteur' )->remove_cap( 'yume_voir_equipe' );
		installer_roles();
		yume_assert_true( get_role( 'yume_relecteur' )->has_cap( 'yume_voir_equipe' ) );
		yume_assert_same( array_keys( definitions_roles() ), array( 'yume_traducteur', 'yume_relecteur', 'yume_graphiste', 'yume_editeur', 'yume_gerant' ) );
	}
);

yume_test(
	'capacités par contenu : traducteur, éditeur, lecteur',
	function () {
		$trad    = yume_factory_user( 'yume_traducteur' );
		$editeur = yume_factory_user( 'yume_editeur' );
		$lecteur = yume_factory_user( 'subscriber' );
		$oeuvre  = yume_tc_oeuvre( 'Capacités' );
		$tome    = yume_tc_tome( $oeuvre, 1 );
		yume_assert_false( user_can( $trad, 'edit_post', $tome ), 'un traducteur ne modifie pas le tome d’un autre' );
		yume_assert_false( user_can( $trad, 'publish_yume_tomes' ) );
		yume_assert_true( user_can( $editeur, 'edit_post', $tome ) );
		yume_assert_true( user_can( $editeur, 'delete_post', $tome ) );
		yume_assert_false( user_can( $lecteur, 'edit_post', $oeuvre ) );
		yume_assert_true( user_can( $editeur, 'assign_term', get_term_by( 'slug', 'manga', 'yume_type' )->term_id ) );
	}
);

yume_test(
	'yume_user_can_edit_planning : responsables, éditeurs, lecteurs',
	function () {
		$trad    = yume_factory_user( 'yume_traducteur' );
		$autre   = yume_factory_user( 'yume_relecteur' );
		$editeur = yume_factory_user( 'yume_editeur' );
		$lecteur = yume_factory_user( 'subscriber' );
		$oeuvre  = yume_tc_oeuvre( 'Planning' );
		$tome    = yume_tc_tome(
			$oeuvre,
			2,
			array(
				'post_status' => 'draft',
				'meta_input'  => array(
					'yume_responsables' => array(
						'traduction' => $trad,
						'relecture'  => 0,
						'edition'    => 0,
					),
				),
			)
		);
		yume_assert_true( yume_user_can_edit_planning( $tome, $trad ) );
		yume_assert_false( yume_user_can_edit_planning( $tome, $autre ) );
		yume_assert_true( yume_user_can_edit_planning( $tome, $editeur ) );
		yume_assert_false( yume_user_can_edit_planning( $tome, $lecteur ) );
		yume_assert_false( yume_user_can_edit_planning( $oeuvre, $editeur ), 'seulement pour un tome' );
		wp_set_current_user( $trad );
		yume_assert_true( yume_user_can_edit_planning( $tome ), 'utilisateur courant par défaut' );
		wp_set_current_user( 0 );
		yume_assert_false( yume_user_can_edit_planning( $tome ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Permaliens, slugs et résolution des URL
 * -----------------------------------------------------------------------------
 */

yume_test(
	'permaliens des œuvres, tomes et chapitres',
	function () {
		$oeuvre = yume_tc_oeuvre( 'Grimgar of Fantasy and Ash' );
		$tome   = yume_tc_tome( $oeuvre, 9 );
		$chap   = yume_tc_chapitre( $tome, 3 );
		$demi   = yume_tc_chapitre( $tome, 12.5 );
		$post   = yume_tc_chapitre(
			$tome,
			null,
			array(
				'post_title' => 'Postface',
				'meta_input' => array( 'yume_nature' => 'postface' ),
			)
		);
		$slug   = get_post_field( 'post_name', $oeuvre );
		yume_assert_same( home_url( '/oeuvres/' . $slug . '/' ), get_permalink( $oeuvre ) );
		yume_assert_same( home_url( '/oeuvres/' . $slug . '/tome-9/' ), get_permalink( $tome ) );
		yume_assert_same( home_url( '/lire/' . $slug . '/tome-9/3/' ), get_permalink( $chap ) );
		yume_assert_same( home_url( '/lire/' . $slug . '/tome-9/12.5/' ), get_permalink( $demi ) );
		yume_assert_same( home_url( '/lire/' . $slug . '/tome-9/postface/' ), get_permalink( $post ) );
		yume_assert_same( home_url( '/oeuvres/' ), get_post_type_archive_link( 'yume_oeuvre' ) );
	}
);

yume_test(
	'deux œuvres ont chacune un « tome-1 » : slugs identiques, URL résolues vers le bon tome',
	function () {
		flush_rewrite_rules( false );
		$a  = yume_tc_oeuvre( 'Œuvre Alpha' );
		$b  = yume_tc_oeuvre( 'Œuvre Beta' );
		$ta = yume_tc_tome( $a, 1 );
		$tb = yume_tc_tome( $b, 1 );
		yume_assert_same( 'tome-1', get_post_field( 'post_name', $ta ) );
		yume_assert_same( 'tome-1', get_post_field( 'post_name', $tb ), 'le slug n’est unique que dans l’œuvre' );
		yume_assert_true( get_permalink( $ta ) !== get_permalink( $tb ) );
		yume_assert_same( $ta, url_to_postid( get_permalink( $ta ) ) );
		yume_assert_same( $tb, url_to_postid( get_permalink( $tb ) ) );

		$q = yume_tc_requete( get_permalink( $tb ) );
		yume_assert_true( $q->is_singular( 'yume_tome' ) );
		yume_assert_same( $tb, (int) $q->get_queried_object_id() );
		yume_assert_same( 1, (int) $q->post_count );
		yume_assert_false( (bool) $q->get( 'yume_non_canonique' ) );
	}
);

yume_test(
	'chapitres : même numéro et même « postface » dans deux tomes, résolution par tome',
	function () {
		flush_rewrite_rules( false );
		$o   = yume_tc_oeuvre( 'Silent Witch' );
		$t1  = yume_tc_tome( $o, 1 );
		$t2  = yume_tc_tome( $o, 2 );
		$c1  = yume_tc_chapitre( $t1, 1 );
		$c2  = yume_tc_chapitre( $t2, 1 );
		$pf1 = yume_tc_chapitre(
			$t1,
			null,
			array(
				'post_name'  => 'postface',
				'meta_input' => array( 'yume_nature' => 'postface' ),
			)
		);
		$pf2 = yume_tc_chapitre(
			$t2,
			null,
			array(
				'post_name'  => 'postface',
				'meta_input' => array( 'yume_nature' => 'postface' ),
			)
		);
		yume_assert_same( 'postface', get_post_field( 'post_name', $pf2 ), 'slug unique seulement dans le tome' );
		foreach ( array( $c1, $c2, $pf1, $pf2 ) as $id ) {
			yume_assert_same( $id, url_to_postid( get_permalink( $id ) ), 'url_to_postid ' . get_permalink( $id ) );
			$q = yume_tc_requete( get_permalink( $id ) );
			yume_assert_same( $id, (int) $q->get_queried_object_id() );
			yume_assert_true( $q->is_singular( 'yume_chapitre' ) );
		}
		// Troisième postface dans le même tome : suffixée.
		$pf3 = yume_tc_chapitre(
			$t1,
			null,
			array(
				'post_name'  => 'postface',
				'meta_input' => array( 'yume_nature' => 'postface' ),
			)
		);
		yume_assert_same( 'postface-2', get_post_field( 'post_name', $pf3 ) );
	}
);

yume_test(
	'unicité des slugs de tome dans une œuvre, slugs numériques et réservés',
	function () {
		$o  = yume_tc_oeuvre( 'Unicité' );
		$t1 = yume_tc_tome( $o, 1 );
		$t2 = yume_tc_tome( $o, 1, array( 'post_name' => 'tome-1' ) );
		yume_assert_same( 'tome-1', get_post_field( 'post_name', $t1 ) );
		yume_assert_same( 'tome-1-2', get_post_field( 'post_name', $t2 ) );
		$t3 = yume_tc_tome( $o, 9, array( 'post_name' => '9' ) );
		yume_assert_same( 'tome-9', get_post_field( 'post_name', $t3 ), 'un slug purement numérique reçoit un préfixe' );
		$t4 = yume_tc_tome( $o, 10, array( 'post_name' => 'feed' ) );
		yume_assert_same( 'feed-2', get_post_field( 'post_name', $t4 ), 'segment réservé' );
		$t5 = yume_tc_tome( $o, 11, array( 'post_name' => 'comment-page-3' ) );
		yume_assert_same( 'comment-page-3-2', get_post_field( 'post_name', $t5 ) );
		// Mise à jour sans changement : le slug reste stable.
		wp_update_post(
			array(
				'ID'         => $t1,
				'post_title' => 'Unicité — Tome 1 (révisé)',
			)
		);
		yume_assert_same( 'tome-1', get_post_field( 'post_name', $t1 ) );
		// Œuvre au slug réservé.
		$page = yume_tc_oeuvre( 'Page', array( 'post_name' => 'page' ) );
		yume_assert_same( 'page-2', get_post_field( 'post_name', $page ) );
	}
);

yume_test(
	'création REST : l’œuvre enregistrée après le contenu fixe le slug dans la bonne portée',
	function () {
		$admin = yume_factory_user( 'administrator' );
		$o     = yume_tc_oeuvre( 'Portée REST' );
		yume_tc_tome( $o, 1 );
		$autre = yume_tc_oeuvre( 'Autre portée' );
		yume_tc_tome( $autre, 1 );
		$rep = yume_rest(
			'POST',
			'/wp/v2/tomes',
			array(
				'title'  => 'Portée REST — Tome 1 bis',
				'slug'   => 'tome-1',
				'status' => 'publish',
				'meta'   => array(
					'yume_oeuvre_id' => $o,
					'yume_numero'    => 1,
				),
			),
			$admin
		);
		yume_assert_same( 201, $rep->get_status(), yume_test_export( $rep->get_data() ) );
		$id = (int) $rep->get_data()['id'];
		yume_assert_same( 'tome-1-2', get_post_field( 'post_name', $id ), 'conflit dans la même œuvre' );
		$rep2 = yume_rest(
			'POST',
			'/wp/v2/tomes',
			array(
				'title'  => 'Nouvelle — Tome 1',
				'slug'   => 'tome-1',
				'status' => 'publish',
				'meta'   => array( 'yume_oeuvre_id' => yume_tc_oeuvre( 'Nouvelle œuvre' ) ),
			),
			$admin
		);
		yume_assert_same( 'tome-1', get_post_field( 'post_name', (int) $rep2->get_data()['id'] ), 'pas de conflit dans une autre œuvre' );
	}
);

yume_test(
	'segment d’œuvre faux, ancien slug, raccourcis /lire/ : résolus puis marqués non canoniques',
	function () {
		// Numéro improbable : le test ne dépend pas des tomes déjà présents dans la base (un
		// vrai « tome-7 » publié ailleurs rendrait le slug ambigu).
		flush_rewrite_rules( false );
		$o    = yume_tc_oeuvre( 'Segment faux (test)' );
		$t    = yume_tc_tome( $o, 7913 );
		$c    = yume_tc_chapitre( $t, 4 );
		$slug = get_post_field( 'post_name', $o );

		$qv = yume_tc_parse( home_url( '/oeuvres/mauvaise-oeuvre/tome-7913/' ) );
		yume_assert_same( $t, (int) ( $qv['p'] ?? 0 ), 'slug de tome unique : retrouvé malgré le mauvais segment' );
		yume_assert_same( 1, (int) $qv['yume_non_canonique'] );

		$qv = yume_tc_parse( home_url( '/lire/' . $slug . '/tome-7913/' ) );
		yume_assert_same( $t, (int) ( $qv['p'] ?? 0 ) );
		yume_assert_same( 'yume_tome', $qv['post_type'] );
		yume_assert_same( 1, (int) $qv['yume_non_canonique'] );

		$qv = yume_tc_parse( home_url( '/lire/' . $slug . '/' ) );
		yume_assert_same( $o, (int) ( $qv['p'] ?? 0 ) );

		$qv = yume_tc_parse( home_url( '/lire/' . $slug . '/tome-7913/04/' ) );
		yume_assert_same( $c, (int) ( $qv['p'] ?? 0 ), 'numéro non normalisé' );
		yume_assert_same( 1, (int) $qv['yume_non_canonique'] );

		$qv = yume_tc_parse( home_url( '/lire/' . $slug . '/tome-7913/' . get_post_field( 'post_name', $c ) . '/' ) );
		yume_assert_same( $c, (int) ( $qv['p'] ?? 0 ), 'chapitre numéroté désigné par son slug' );
		yume_assert_same( 1, (int) $qv['yume_non_canonique'] );

		// Renommage de l'œuvre : l'ancien slug mène au tome.
		wp_update_post(
			array(
				'ID'        => $o,
				'post_name' => 'segment-faux-renomme',
			)
		);
		yume_assert_same( home_url( '/oeuvres/segment-faux-renomme/tome-7913/' ), get_permalink( $t ) );
		$qv = yume_tc_parse( home_url( '/oeuvres/' . $slug . '/tome-7913/' ) );
		yume_assert_same( $t, (int) ( $qv['p'] ?? 0 ) );
		yume_assert_same( 1, (int) $qv['yume_non_canonique'] );

		// Inconnu : 404.
		$qv = yume_tc_parse( home_url( '/oeuvres/segment-faux-renomme/tome-7999/' ) );
		yume_assert_same( '404', $qv['error'] ?? '' );
		$q = yume_tc_requete( home_url( '/lire/segment-faux-renomme/tome-7913/99/' ) );
		yume_assert_true( $q->is_404() );
	}
);

yume_test(
	'segments réservés : flux, embed, trackback, pages de commentaires, pagination',
	function () {
		flush_rewrite_rules( false );
		$o    = yume_tc_oeuvre( 'Réservés' );
		$t    = yume_tc_tome( $o, 1 );
		$c    = yume_tc_chapitre( $t, 2 );
		$slug = get_post_field( 'post_name', $o );

		$qv = yume_tc_parse( home_url( "/oeuvres/$slug/feed/" ) );
		yume_assert_same( $slug, $qv['yume_oeuvre'] ?? '', 'flux de commentaires de l’œuvre' );
		yume_assert_same( 'feed', $qv['feed'] );

		$qv = yume_tc_parse( home_url( '/oeuvres/feed/' ) );
		yume_assert_same( 'yume_oeuvre', $qv['post_type'] ?? '', 'flux de l’archive' );

		$qv = yume_tc_parse( home_url( '/oeuvres/page/2/' ) );
		yume_assert_same( '2', (string) ( $qv['paged'] ?? '' ), 'pagination de l’archive' );

		$qv = yume_tc_parse( home_url( "/oeuvres/$slug/2/" ) );
		yume_assert_same( $slug, $qv['yume_oeuvre'] ?? '' );

		$qv = yume_tc_parse( home_url( "/oeuvres/$slug/tome-1/feed/" ) );
		yume_assert_same( $t, (int) ( $qv['p'] ?? 0 ) );
		yume_assert_same( 'feed', $qv['feed'] );

		$qv = yume_tc_parse( home_url( "/oeuvres/$slug/tome-1/comment-page-2/" ) );
		yume_assert_same( $t, (int) ( $qv['p'] ?? 0 ) );
		yume_assert_same( '2', (string) $qv['cpage'] );

		$qv = yume_tc_parse( home_url( "/oeuvres/$slug/tome-1/embed/" ) );
		yume_assert_same( 'true', (string) $qv['embed'] );

		$qv = yume_tc_parse( home_url( "/lire/$slug/tome-1/2/comment-page-3/" ) );
		yume_assert_same( $c, (int) ( $qv['p'] ?? 0 ) );
		yume_assert_same( '3', (string) $qv['cpage'] );

		$qv = yume_tc_parse( home_url( "/lire/$slug/tome-1/2/trackback/" ) );
		yume_assert_same( '1', (string) $qv['tb'] );

		$qv = yume_tc_parse( home_url( "/lire/$slug/tome-1/feed/" ) );
		yume_assert_same( '404', $qv['error'] ?? '', '« feed » n’est jamais un chapitre' );

		// Liens générés par WordPress.
		yume_assert_same( get_permalink( $t ) . 'feed/', get_post_comments_feed_link( $t ) );
		yume_assert_same( get_permalink( $c ) . 'embed/', get_post_embed_url( $c ) );
		$q = yume_tc_requete( get_post_embed_url( $c ) );
		yume_assert_true( $q->is_embed() );
		yume_assert_same( $c, (int) $q->get_queried_object_id() );
	}
);

yume_test(
	'règles : aucun motif avec « # » ni « ! », règles en tête de la table',
	function () {
		flush_rewrite_rules( false );
		$regles = get_option( 'rewrite_rules' );
		foreach ( array_keys( regles_reecriture() ) as $motif ) {
			yume_assert_false( str_contains( $motif, '#' ) || str_contains( $motif, '!' ), $motif );
			yume_assert_true( isset( $regles[ $motif ] ), "règle enregistrée : $motif" );
		}
		$cles = array_keys( $regles );
		yume_assert_true(
			array_search( 'oeuvres/([^/]+)/([^/]+)(?:/([0-9]+))?/?$', $cles, true ) < array_search( 'oeuvres/[^/]+/([^/]+)/?$', $cles, true ),
			'la règle des tomes précède la règle native des pièces jointes'
		);
	}
);

yume_test(
	'brouillons : lien simple, aperçu ?p=ID&preview=true, invisible anonymement',
	function () {
		flush_rewrite_rules( false );
		$editeur = yume_factory_user( 'yume_editeur' );
		$o       = yume_tc_oeuvre( 'Brouillons' );
		$t       = yume_tc_tome( $o, 3, array( 'post_status' => 'draft' ) );
		$lien    = get_permalink( $t );
		yume_assert_contains( 'p=' . $t, $lien );
		yume_assert_contains( 'post_type=yume_tome', $lien );
		$apercu = get_preview_post_link( $t );
		yume_assert_contains( 'preview=true', $apercu );

		wp_set_current_user( $editeur );
		$q = new WP_Query(
			array(
				'p'         => $t,
				'post_type' => 'yume_tome',
				'preview'   => 'true',
			)
		);
		yume_assert_same( 1, (int) $q->post_count, 'aperçu visible par l’équipe' );
		// Même un slug défini : l'adresse jolie d'un brouillon n'est visible que par l'équipe.
		wp_update_post(
			array(
				'ID'        => $t,
				'post_name' => 'tome-3',
			)
		);
		$qv = yume_tc_parse( home_url( '/oeuvres/' . get_post_field( 'post_name', $o ) . '/tome-3/' ) );
		yume_assert_same( $t, (int) ( $qv['p'] ?? 0 ) );
		wp_set_current_user( 0 );
		$qv = yume_tc_parse( home_url( '/oeuvres/' . get_post_field( 'post_name', $o ) . '/tome-3/' ) );
		yume_assert_same( '404', $qv['error'] ?? '', 'anonyme' );

		// Permalien d'exemple de l'éditeur : slug modifiable.
		require_once ABSPATH . 'wp-admin/includes/post.php';
		wp_set_current_user( $editeur );
		$exemple = get_sample_permalink( $t );
		yume_assert_contains( '/oeuvres/' . get_post_field( 'post_name', $o ) . '/%pagename%/', $exemple[0] );
		yume_assert_same( 'tome-3', $exemple[1] );
	}
);

yume_test(
	'contenus sans rattachement : lien simple résolu nativement',
	function () {
		$t = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'post_title' => 'Orphelin',
			)
		);
		yume_assert_contains( 'p=' . $t, get_permalink( $t ) );
		yume_assert_same( $t, url_to_postid( get_permalink( $t ) ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Métadonnées et REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'métadonnées déclarées avec schémas REST complets',
	function () {
		$tome = get_registered_meta_keys( 'post', 'yume_tome' );
		foreach ( array( 'yume_oeuvre_id', 'yume_numero', 'yume_nature', 'yume_lien_pdf', 'yume_lien_epub', 'yume_equivalence', 'yume_illustrations', 'yume_credits', 'yume_etape', 'yume_avancement', 'yume_responsables', 'yume_date_cible', 'yume_bloque', 'yume_bloque_raison', 'yume_derniere_maj', 'yume_maj_par', 'yume_note_equipe', 'yume_nb_chapitres' ) as $cle ) {
			yume_assert_true( isset( $tome[ $cle ] ), "tome : $cle" );
			yume_assert_true( $tome[ $cle ]['single'] );
			yume_assert_true( (bool) $tome[ $cle ]['show_in_rest'] );
		}
		yume_assert_same( 'object', $tome['yume_avancement']['type'] );
		yume_assert_same( 100, $tome['yume_avancement']['show_in_rest']['schema']['properties']['relecture']['maximum'] );
		yume_assert_same( array( 'tome', 'arc', 'ex', 'bonus', 'chapitres' ), $tome['yume_nature']['show_in_rest']['schema']['enum'] );
		$oeuvre = get_registered_meta_keys( 'post', 'yume_oeuvre' );
		yume_assert_same( 'array', $oeuvre['yume_liens']['type'] );
		yume_assert_same( array( 'label', 'url' ), array_keys( $oeuvre['yume_liens']['show_in_rest']['schema']['items']['properties'] ) );
		yume_assert_same( array( 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche' ), $oeuvre['yume_jours_sortie']['show_in_rest']['schema']['items']['enum'] );
		$chap = get_registered_meta_keys( 'post', 'yume_chapitre' );
		foreach ( array( 'yume_tome_id', 'yume_oeuvre_id', 'yume_numero', 'yume_sous_titre', 'yume_nature', 'yume_credits', 'yume_nb_mots', 'yume_temps_lecture', 'yume_source' ) as $cle ) {
			yume_assert_true( isset( $chap[ $cle ] ), "chapitre : $cle" );
		}
		yume_assert_true( is_protected_meta( 'yume_numero', 'post' ), 'méta masquée de la boîte « Champs personnalisés »' );
		yume_assert_false( is_protected_meta( 'autre_cle', 'post' ) );
	}
);

yume_test(
	'REST /wp/v2/tomes : métadonnées exposées, note d’équipe réservée à l’équipe',
	function () {
		$admin  = yume_factory_user( 'administrator' );
		$trad   = yume_factory_user( 'yume_traducteur' );
		$o      = yume_tc_oeuvre( 'REST' );
		$t      = yume_tc_sans_evenements(
			static fn(): int => yume_tc_tome(
				$o,
				26.5,
				array(
					'meta_input' => array(
						'yume_note_equipe' => 'Secret de l’équipe',
						'yume_avancement'  => array(
							'traduction' => 100,
							'relecture'  => 62,
							'edition'    => 0,
						),
						'yume_lien_pdf'    => 'https://clictune.example/pdf',
					),
				)
			)
		);
		$public = yume_rest( 'GET', '/wp/v2/tomes/' . $t );
		yume_assert_same( 200, $public->get_status() );
		$meta = $public->get_data()['meta'];
		yume_assert_same( 26.5, $meta['yume_numero'] );
		yume_assert_same( $o, $meta['yume_oeuvre_id'] );
		yume_assert_same( 62, $meta['yume_avancement']['relecture'] );
		yume_assert_same( 'https://clictune.example/pdf', $meta['yume_lien_pdf'] );
		yume_assert_false( array_key_exists( 'yume_note_equipe', $meta ), 'note absente de la réponse publique' );
		yume_assert_not_contains( 'Secret', (string) wp_json_encode( $public->get_data() ) );

		$equipe = yume_rest( 'GET', '/wp/v2/tomes/' . $t, array( 'context' => 'view' ), $trad );
		yume_assert_same( 'Secret de l’équipe', $equipe->get_data()['meta']['yume_note_equipe'] );
		$adm = yume_rest( 'GET', '/wp/v2/tomes/' . $t, array( 'context' => 'edit' ), $admin );
		yume_assert_same( 'Secret de l’équipe', $adm->get_data()['meta']['yume_note_equipe'] );
	}
);

yume_test(
	'REST : écriture validée par le schéma, caches en lecture seule, droits',
	function () {
		$admin   = yume_factory_user( 'administrator' );
		$trad    = yume_factory_user( 'yume_traducteur' );
		$lecteur = yume_factory_user( 'subscriber' );
		$o       = yume_tc_oeuvre( 'Écritures' );
		$t       = yume_tc_tome( $o, 1 );

		$rep = yume_rest(
			'POST',
			'/wp/v2/tomes/' . $t,
			array(
				'meta' => array(
					'yume_etape'       => 'relecture',
					'yume_avancement'  => array(
						'traduction' => 100,
						'relecture'  => 40,
						'edition'    => 0,
					),
					'yume_date_cible'  => '2026-10-04',
					'yume_bloque'      => true,
					'yume_note_equipe' => 'Relecture en pause',
				),
			),
			$admin
		);
		yume_assert_same( 200, $rep->get_status(), yume_test_export( $rep->get_data() ) );
		yume_assert_same( 'relecture', get_post_meta( $t, 'yume_etape', true ) );
		yume_assert_same( 40, get_post_meta( $t, 'yume_avancement', true )['relecture'] );
		yume_assert_true( (bool) get_post_meta( $t, 'yume_bloque', true ) );

		$rep = yume_rest( 'POST', '/wp/v2/tomes/' . $t, array( 'meta' => array( 'yume_etape' => 'inconnue' ) ), $admin );
		yume_assert_same( 400, $rep->get_status(), 'énumération respectée' );
		$rep = yume_rest( 'POST', '/wp/v2/tomes/' . $t, array( 'meta' => array( 'yume_avancement' => array( 'traduction' => 150 ) ) ), $admin );
		yume_assert_same( 400, $rep->get_status(), 'pourcentage borné' );
		$rep = yume_rest( 'POST', '/wp/v2/tomes/' . $t, array( 'meta' => array( 'yume_nb_chapitres' => 99 ) ), $admin );
		yume_assert_true( $rep->get_status() >= 400, 'cache en lecture seule' );
		yume_assert_true( 99 !== (int) get_post_meta( $t, 'yume_nb_chapitres', true ) );
		$rep = yume_rest( 'POST', '/wp/v2/oeuvres/' . $o, array( 'meta' => array( 'yume_nb_favoris' => 5000 ) ), $admin );
		yume_assert_true( $rep->get_status() >= 400, 'favoris en lecture seule' );

		$rep = yume_rest( 'POST', '/wp/v2/tomes/' . $t, array( 'meta' => array( 'yume_etape' => 'edition' ) ), $trad );
		yume_assert_same( 403, $rep->get_status(), 'un traducteur ne modifie pas le tome par /wp/v2' );
		$rep = yume_rest( 'POST', '/wp/v2/tomes/' . $t, array( 'meta' => array( 'yume_etape' => 'edition' ) ), $lecteur );
		yume_assert_same( 403, $rep->get_status() );
		yume_assert_false( user_can( $lecteur, 'edit_post_meta', $t, 'yume_note_equipe' ) );
		yume_assert_true( user_can( $admin, 'edit_post_meta', $t, 'yume_note_equipe' ) );

		$rep = yume_rest(
			'POST',
			'/wp/v2/oeuvres/' . $o,
			array(
				'meta' => array(
					'yume_liens'        => array(
						array(
							'label' => 'Novel-Index',
							'url'   => 'https://novel-index.example/grimgar',
						),
					),
					'yume_jours_sortie' => array( 'samedi', 'mercredi' ),
					'yume_equipe'       => array(
						'traduction' => 'Calumi',
						'relecture'  => 'Angeloids',
						'edition'    => 'JojoGg',
					),
				),
			),
			$admin
		);
		yume_assert_same( 200, $rep->get_status(), yume_test_export( $rep->get_data() ) );
		yume_assert_same( array( 'mercredi', 'samedi' ), get_post_meta( $o, 'yume_jours_sortie', true ), 'jours dans l’ordre de la semaine' );
		yume_assert_same( 'Calumi', $rep->get_data()['meta']['yume_equipe']['traduction'] );
	}
);

yume_test(
	'assainissement des métadonnées',
	function () {
		$o = yume_tc_oeuvre( 'Assainissement' );
		update_post_meta(
			$o,
			'yume_liens',
			array(
				array(
					'label' => '<b>Site</b>',
					'url'   => 'javascript:alert(1)',
				),
				array(
					'label' => '',
					'url'   => 'https://mangadex.example/title/1',
				),
				'n’importe quoi',
			)
		);
		yume_assert_same(
			array(
				array(
					'label' => 'mangadex.example',
					'url'   => 'https://mangadex.example/title/1',
				),
			),
			get_post_meta( $o, 'yume_liens', true )
		);
		update_post_meta( $o, 'yume_titres_alt', "灰と幻想のグリムガル\n\nHai to Gensou no Grimgar\n灰と幻想のグリムガル" );
		yume_assert_same( array( '灰と幻想のグリムガル', 'Hai to Gensou no Grimgar' ), get_post_meta( $o, 'yume_titres_alt', true ) );
		update_post_meta( $o, 'yume_statut_vo', 'fini' );
		yume_assert_same( '', get_post_meta( $o, 'yume_statut_vo', true ) );

		$t = yume_tc_sans_evenements( static fn(): int => yume_tc_tome( $o, 1 ) );
		update_post_meta( $t, 'yume_date_cible', '2026-02-30' );
		yume_assert_same( '', get_post_meta( $t, 'yume_date_cible', true ) );
		update_post_meta( $t, 'yume_date_cible', '2026-09-27' );
		yume_assert_same( '2026-09-27', get_post_meta( $t, 'yume_date_cible', true ) );
		update_post_meta(
			$t,
			'yume_avancement',
			array(
				'traduction' => '150',
				'relecture'  => -5,
				'autre'      => 3,
			)
		);
		yume_assert_same(
			array(
				'traduction' => 100,
				'relecture'  => 0,
				'edition'    => 0,
			),
			get_post_meta( $t, 'yume_avancement', true )
		);
		update_post_meta( $t, 'yume_nature', 'volume' );
		yume_assert_same( 'tome', get_post_meta( $t, 'yume_nature', true ) );
		update_post_meta( $t, 'yume_numero', '26,5' );
		yume_assert_same( 26.5, (float) get_post_meta( $t, 'yume_numero', true ) );
		update_post_meta( $t, 'yume_illustrations', '12, 15;15 x 0' );
		yume_assert_same( array( 12, 15 ), get_post_meta( $t, 'yume_illustrations', true ) );
		update_post_meta( $t, 'yume_derniere_maj', '2026-09-25T10:15' );
		yume_assert_same( '2026-09-25 10:15:00', get_post_meta( $t, 'yume_derniere_maj', true ) );
		update_post_meta( $t, 'yume_lien_epub', 'ftp://exemple/fichier.epub' );
		yume_assert_same( '', get_post_meta( $t, 'yume_lien_epub', true ) );
		yume_assert_same( 'a_faire', get_post_meta( $t, 'yume_etape', true ), 'valeur par défaut' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Caches et dénormalisation
 * -----------------------------------------------------------------------------
 */

yume_test(
	'nombre de mots et temps de lecture (230 mots/min) calculés si absents',
	function () {
		yume_assert_same( 4, compter_mots( '<!-- wp:paragraph --><p>— L’homme regarda <em>vite</em> Grimgar.</p><!-- /wp:paragraph -->' ) );
		$o     = yume_tc_oeuvre( 'Lecture' );
		$t     = yume_tc_tome( $o, 1 );
		$texte = '<!-- wp:paragraph --><p>' . trim( str_repeat( 'mot ', 461 ) ) . '</p><!-- /wp:paragraph -->';
		$c     = yume_tc_chapitre( $t, 1, array( 'post_content' => $texte ) );
		yume_assert_same( 461, (int) get_post_meta( $c, 'yume_nb_mots', true ) );
		yume_assert_same( 3, (int) get_post_meta( $c, 'yume_temps_lecture', true ), 'arrondi supérieur' );

		// Valeur fournie par l'import : conservée, le temps est déduit.
		$c2 = yume_tc_chapitre( $t, 2, array( 'meta_input' => array( 'yume_nb_mots' => 2300 ) ) );
		yume_assert_same( 2300, (int) get_post_meta( $c2, 'yume_nb_mots', true ) );
		yume_assert_same( 10, (int) get_post_meta( $c2, 'yume_temps_lecture', true ) );

		// Texte modifié : recalcul.
		wp_update_post(
			array(
				'ID'           => $c2,
				'post_content' => '<p>Un deux trois.</p>',
			)
		);
		yume_assert_same( 3, (int) get_post_meta( $c2, 'yume_nb_mots', true ) );
		yume_assert_same( 1, (int) get_post_meta( $c2, 'yume_temps_lecture', true ) );
	}
);

yume_test(
	'œuvre du chapitre recopiée depuis le tome, suivie quand le tome change d’œuvre',
	function () {
		$a = yume_tc_oeuvre( 'Dénormalisation A' );
		$b = yume_tc_oeuvre( 'Dénormalisation B' );
		$t = yume_tc_tome( $a, 1 );
		$c = yume_tc_chapitre( $t, 1 );
		yume_assert_same( $a, (int) get_post_meta( $c, 'yume_oeuvre_id', true ) );
		update_post_meta( $t, 'yume_oeuvre_id', $b );
		yume_assert_same( $b, (int) get_post_meta( $c, 'yume_oeuvre_id', true ) );
		yume_assert_same( $b, yume_get_oeuvre_id( $c ) );
		// Valeur incohérente écrite à la main : corrigée au prochain enregistrement.
		update_post_meta( $c, 'yume_oeuvre_id', $a );
		wp_update_post(
			array(
				'ID'         => $c,
				'post_title' => 'Chapitre 1 bis',
			)
		);
		yume_assert_same( $b, (int) get_post_meta( $c, 'yume_oeuvre_id', true ) );
		// Chapitre déplacé dans un autre tome.
		$t2 = yume_tc_tome( $a, 2 );
		update_post_meta( $c, 'yume_tome_id', $t2 );
		yume_assert_same( $a, (int) get_post_meta( $c, 'yume_oeuvre_id', true ) );
		// Client REST qui envoie une œuvre incohérente : acceptée puis corrigée.
		$rep = yume_rest(
			'POST',
			'/wp/v2/chapitres',
			array(
				'title'   => 'Chapitre 2',
				'status'  => 'publish',
				'content' => 'Texte',
				'meta'    => array(
					'yume_tome_id'   => $t2,
					'yume_oeuvre_id' => $b,
					'yume_numero'    => 2,
				),
			),
			yume_factory_user( 'administrator' )
		);
		yume_assert_same( 201, $rep->get_status(), yume_test_export( $rep->get_data() ) );
		yume_assert_same( $a, (int) get_post_meta( (int) $rep->get_data()['id'], 'yume_oeuvre_id', true ) );
		yume_assert_same( $a, (int) $rep->get_data()['meta']['yume_oeuvre_id'], 'réponse REST à jour' );
	}
);

yume_test(
	'caches : chapitres publiés du tome et dernière sortie de l’œuvre',
	function () {
		$o  = yume_tc_oeuvre( 'Caches' );
		$t  = yume_tc_tome( $o, 1, array( 'post_date' => '2026-09-01 10:00:00' ) );
		$c1 = yume_tc_chapitre( $t, 1, array( 'post_date' => '2026-09-02 10:00:00' ) );
		$c2 = yume_tc_chapitre( $t, 2, array( 'post_date' => '2026-09-03 10:00:00' ) );
		yume_tc_chapitre( $t, 3, array( 'post_status' => 'draft' ) );
		yume_assert_same( 2, (int) get_post_meta( $t, 'yume_nb_chapitres', true ) );
		yume_assert_same( get_gmt_from_date( '2026-09-03 10:00:00' ), get_post_meta( $o, 'yume_derniere_sortie', true ) );
		wp_update_post(
			array(
				'ID'          => $c2,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( 1, (int) get_post_meta( $t, 'yume_nb_chapitres', true ) );
		yume_assert_same( get_gmt_from_date( '2026-09-02 10:00:00' ), get_post_meta( $o, 'yume_derniere_sortie', true ) );
		wp_delete_post( $c1, true );
		yume_assert_same( 0, (int) get_post_meta( $t, 'yume_nb_chapitres', true ) );
		yume_assert_same( get_gmt_from_date( '2026-09-01 10:00:00' ), get_post_meta( $o, 'yume_derniere_sortie', true ) );
		wp_trash_post( $t );
		yume_assert_same( '', (string) get_post_meta( $o, 'yume_derniere_sortie', true ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * API §7
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_get_tomes : tri par numéro, statut, nature',
	function () {
		$o   = yume_tc_oeuvre( 'API tomes' );
		$t10 = yume_tc_tome( $o, 10 );
		$t2  = yume_tc_tome( $o, 2 );
		$t25 = yume_tc_tome( $o, 2.5, array( 'meta_input' => array( 'yume_nature' => 'bonus' ) ) );
		$t11 = yume_tc_tome( $o, 11, array( 'post_status' => 'draft' ) );
		$sn  = yume_tc_tome( $o, 0, array( 'post_name' => 'hors-serie' ) );
		delete_post_meta( $sn, 'yume_numero' );
		yume_assert_same( array( $t2, $t25, $t10, $sn ), wp_list_pluck( yume_get_tomes( $o ), 'ID' ) );
		yume_assert_same( array( $t10, $t25, $t2, $sn ), wp_list_pluck( yume_get_tomes( $o, array( 'order' => 'DESC' ) ), 'ID' ), 'sans numéro en dernier' );
		yume_assert_same( array( $t2, $t25, $t10, $t11, $sn ), wp_list_pluck( yume_get_tomes( $o, array( 'status' => 'any' ) ), 'ID' ) );
		yume_assert_same( array( $t25 ), wp_list_pluck( yume_get_tomes( $o, array( 'nature' => 'bonus' ) ), 'ID' ) );
		yume_assert_same( array(), yume_get_tomes( 0 ) );
		yume_assert_true( yume_get_tomes( $o )[0] instanceof WP_Post );
	}
);

yume_test(
	'yume_get_chapitres : menu_order puis numéro',
	function () {
		$o  = yume_tc_oeuvre( 'API chapitres' );
		$t  = yume_tc_tome( $o, 1 );
		$c3 = yume_tc_chapitre( $t, 3 );
		$c1 = yume_tc_chapitre( $t, 1 );
		$pr = yume_tc_chapitre( $t, 0, array( 'meta_input' => array( 'yume_nature' => 'prologue' ) ) );
		$pf = yume_tc_chapitre( $t, null, array( 'meta_input' => array( 'yume_nature' => 'postface' ) ) );
		$dr = yume_tc_chapitre( $t, 2, array( 'post_status' => 'draft' ) );
		yume_assert_same( array( $pr, $c1, $c3, $pf ), wp_list_pluck( yume_get_chapitres( $t ), 'ID' ) );
		yume_assert_same( array( $pr, $c1, $dr, $c3, $pf ), wp_list_pluck( yume_get_chapitres( $t, array( 'status' => 'any' ) ), 'ID' ) );
		wp_update_post(
			array(
				'ID'         => $c3,
				'menu_order' => -1,
			)
		);
		yume_assert_same( $c3, yume_get_chapitres( $t )[0]->ID, 'menu_order prioritaire' );
	}
);

yume_test(
	'yume_get_oeuvre_id, yume_get_tome_id',
	function () {
		$o = yume_tc_oeuvre( 'Rattachements' );
		$t = yume_tc_tome( $o, 1 );
		$c = yume_tc_chapitre( $t, 1 );
		yume_assert_same( $o, yume_get_oeuvre_id( $o ) );
		yume_assert_same( $o, yume_get_oeuvre_id( $t ) );
		yume_assert_same( $o, yume_get_oeuvre_id( $c ) );
		yume_assert_same( 0, yume_get_oeuvre_id( 0 ) );
		yume_assert_same( $t, yume_get_tome_id( $c ) );
		yume_assert_same( $t, yume_get_tome_id( $t ) );
		yume_assert_same( 0, yume_get_tome_id( $o ) );
		$article = yume_factory_post( array( 'post_title' => 'Annonce' ) );
		wp_set_object_terms( $article, array( (int) get_post_meta( $o, '_yume_terme_lie', true ) ), 'yume_oeuvre_liee' );
		yume_assert_same( $o, yume_get_oeuvre_id( $article ), 'article relié par la taxonomie' );
	}
);

yume_test(
	'yume_chapitre_voisin : dans le tome et à travers les tomes publiés',
	function () {
		$o  = yume_tc_oeuvre( 'Voisins' );
		$t1 = yume_tc_tome( $o, 1 );
		$t2 = yume_tc_tome( $o, 2, array( 'post_status' => 'draft' ) );
		$t3 = yume_tc_tome( $o, 3 );
		$a1 = yume_tc_chapitre( $t1, 1 );
		$a2 = yume_tc_chapitre( $t1, 2 );
		$ad = yume_tc_chapitre( $t1, 3, array( 'post_status' => 'draft' ) );
		yume_tc_chapitre( $t2, 1 );
		$c1 = yume_tc_chapitre( $t3, 1 );
		$c2 = yume_tc_chapitre( $t3, 2 );
		yume_assert_same( $a2, yume_chapitre_voisin( $a1, 'next' )->ID );
		yume_assert_same( null, yume_chapitre_voisin( $a1, 'prev' ) );
		yume_assert_same( $c1, yume_chapitre_voisin( $a2, 'next' )->ID, 'saute le chapitre brouillon et le tome non publié' );
		yume_assert_same( $a2, yume_chapitre_voisin( $c1, 'prev' )->ID );
		yume_assert_same( null, yume_chapitre_voisin( $c2, 'next' ) );
		yume_assert_same( $c1, yume_chapitre_voisin( $ad, 'next' )->ID, 'depuis un aperçu de brouillon' );
		yume_assert_same( null, yume_chapitre_voisin( $a1, 'haut' ) );
		yume_assert_same( null, yume_chapitre_voisin( $t1, 'next' ) );
	}
);

yume_test(
	'yume_get_cover_id : tome, sinon œuvre',
	function () {
		$o    = yume_tc_oeuvre( 'Couvertures' );
		$t    = yume_tc_tome( $o, 1 );
		$c    = yume_tc_chapitre( $t, 1 );
		$img1 = yume_tc_image( $o );
		$img2 = yume_tc_image( $t );
		yume_assert_same( 0, yume_get_cover_id( $c ) );
		update_post_meta( $o, '_thumbnail_id', $img1 );
		yume_assert_same( $img1, yume_get_cover_id( $o ) );
		yume_assert_same( $img1, yume_get_cover_id( $t ) );
		yume_assert_same( $img1, yume_get_cover_id( $c ) );
		update_post_meta( $t, '_thumbnail_id', $img2 );
		yume_assert_same( $img2, yume_get_cover_id( $t ) );
		yume_assert_same( $img2, yume_get_cover_id( $c ) );
		yume_assert_same( 0, yume_get_cover_id( 0 ) );
	}
);

yume_test(
	'libellés des tomes et chapitres',
	function () {
		$o = yume_tc_oeuvre( 'Libellés' );
		yume_assert_same( 'Tome 9', yume_libelle_tome( yume_tc_tome( $o, 9 ) ) );
		yume_assert_same( 'T.9', yume_libelle_tome( yume_tc_tome( $o, 9 ), true ) );
		$arc = yume_tc_tome( $o, 7, array( 'meta_input' => array( 'yume_nature' => 'arc' ) ) );
		yume_assert_same( 'Arc 7', yume_libelle_tome( $arc ) );
		yume_assert_same( 'A.7', yume_libelle_tome( $arc, true ) );
		yume_assert_same( 'Tome 26,5', yume_libelle_tome( yume_tc_tome( $o, 26.5 ) ) );
		yume_assert_same( 'Tome EX 2', yume_libelle_tome( yume_tc_tome( $o, 2, array( 'meta_input' => array( 'yume_nature' => 'ex' ) ) ) ) );
		$t = yume_tc_tome( $o, 1 );
		yume_assert_same( 'Chapitre 3', yume_libelle_chapitre( yume_tc_chapitre( $t, 3 ) ) );
		yume_assert_same( 'Chapitre 12,5', yume_libelle_chapitre( yume_tc_chapitre( $t, 12.5 ) ) );
		yume_assert_same( 'Prologue', yume_libelle_chapitre( yume_tc_chapitre( $t, 0, array( 'meta_input' => array( 'yume_nature' => 'prologue' ) ) ) ) );
		yume_assert_same( 'Postface', yume_libelle_chapitre( yume_tc_chapitre( $t, null, array( 'meta_input' => array( 'yume_nature' => 'postface' ) ) ) ) );
		yume_assert_same( 'Interlude 2', yume_libelle_chapitre( yume_tc_chapitre( $t, 2, array( 'meta_input' => array( 'yume_nature' => 'interlude' ) ) ) ) );
		yume_assert_same( 'Épilogue', yume_libelle_chapitre( yume_tc_chapitre( $t, 9, array( 'meta_input' => array( 'yume_nature' => 'epilogue' ) ) ) ) );
		yume_assert_same( 'Tome 1', yume_libelle_tome( yume_tc_chapitre( $t, 4 ) ), 'libellé du tome d’un chapitre' );
		yume_assert_same( '', yume_libelle_chapitre( $t ) );
	}
);

yume_test(
	'listes de référence : natures, étapes, jours',
	function () {
		yume_assert_same( array( 'tome', 'arc', 'ex', 'bonus', 'chapitres' ), array_keys( yume_natures_tome() ) );
		yume_assert_same( array( 'a_faire', 'traduction', 'relecture', 'edition', 'publie' ), array_keys( yume_etapes() ) );
		yume_assert_same( 'Édition', yume_etapes()['edition'] );
		yume_assert_same( array( 'chapitre', 'prologue', 'interlude', 'epilogue', 'postface', 'bonus', 'illustrations' ), array_keys( yume_natures_chapitre() ) );
		yume_assert_same( 7, count( yume_jours_semaine() ) );
	}
);

yume_test(
	'yume_liens_telechargement',
	function () {
		$o       = yume_tc_oeuvre( 'Téléchargements' );
		$t       = yume_tc_tome(
			$o,
			1,
			array(
				'meta_input' => array(
					'yume_lien_pdf'  => 'https://clictune.example/pdf-1',
					'yume_lien_epub' => '',
				),
			)
		);
		$c       = yume_tc_chapitre( $t, 1 );
		$attendu = array(
			'pdf'  => 'https://clictune.example/pdf-1',
			'epub' => '',
		);
		yume_assert_same( $attendu, yume_liens_telechargement( $t ) );
		yume_assert_same( $attendu, yume_liens_telechargement( $c ) );
		yume_assert_same(
			array(
				'pdf'  => '',
				'epub' => '',
			),
			yume_liens_telechargement( $o )
		);
	}
);

yume_test(
	'yume_url_page : option yume_pages, sinon repli sur le slug',
	function () {
		delete_option( 'yume_pages' );
		yume_assert_same( home_url( '/bibliotheque/' ), yume_url_page( 'bibliotheque' ) );
		yume_assert_same( home_url( '/equipe/publier/' ), yume_url_page( 'publier' ) );
		yume_assert_same( home_url( '/connexion/' ), yume_url_page( 'connexion' ) );
		$page = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Planning',
				'post_name'  => 'planning-des-sorties',
			)
		);
		update_option( 'yume_pages', array( 'planning' => $page ) );
		yume_assert_same( get_permalink( $page ), yume_url_page( 'planning' ) );
		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( home_url( '/planning/' ), yume_url_page( 'planning' ), 'page non publiée : repli' );
	}
);

yume_test(
	'yume_setting : valeur enregistrée, défaut explicite, défaut du contrat',
	function () {
		delete_option( 'yume_reglages' );
		yume_assert_same( 14, yume_setting( 'rappel_jours_sans_maj' ) );
		yume_assert_same( array( 'mercredi', 'samedi', 'dimanche' ), yume_setting( 'jours_sortie' ) );
		yume_assert_same( 'GNAlexandre/Yume-WordPress', yume_setting( 'github_repo' ) );
		yume_assert_same( 'https://ko-fi.com/ynovel', yume_setting( 'kofi_url' ) );
		yume_assert_true( yume_setting( 'emails_lecteurs' ) );
		yume_assert_same( 7, yume_setting( 'rappel_jours_sans_maj', 7 ) );
		yume_assert_same( null, yume_setting( 'cle_inconnue' ) );
		yume_assert_same( 'x', yume_setting( 'cle_inconnue', 'x' ) );
		update_option( 'yume_reglages', array( 'rappel_jours_sans_maj' => 21 ) );
		yume_assert_same( 21, yume_setting( 'rappel_jours_sans_maj', 7 ) );
		yume_assert_same( 9, yume_setting( 'rappel_heure' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Événements §8
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_tome_publie : une seule fois par tome, au passage à publish',
	function () {
		$o     = yume_tc_oeuvre( 'Événements' );
		$t     = yume_tc_tome( $o, 4, array( 'post_status' => 'draft' ) );
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( $t ) {
				wp_update_post(
					array(
						'ID'          => $t,
						'post_status' => 'publish',
					)
				);
				wp_update_post(
					array(
						'ID'         => $t,
						'post_title' => 'Événements — Tome 4',
					)
				);
				wp_update_post(
					array(
						'ID'          => $t,
						'post_status' => 'draft',
					)
				);
				wp_update_post(
					array(
						'ID'          => $t,
						'post_status' => 'publish',
					)
				);
			}
		);
		yume_assert_same( array( $t ), $recus );
		yume_assert_true( '' !== (string) get_post_meta( $t, '_yume_publie_notifie', true ) );

		// Création directement publiée avec l'œuvre en meta_input.
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( $o, &$t2 ) {
				$t2 = yume_tc_tome( $o, 5 );
			}
		);
		yume_assert_same( array( $t2 ), $recus );
		yume_assert_same( get_post_meta( $t2, 'yume_derniere_sortie', true ), get_post_meta( $t2, 'yume_derniere_sortie', true ) );
		yume_assert_true( '' !== (string) get_post_meta( $o, 'yume_derniere_sortie', true ), 'dernière sortie de l’œuvre' );
	}
);

yume_test(
	'yume_tome_publie : attend que l’œuvre soit enregistrée (REST, méta-boîtes)',
	function () {
		$admin = yume_factory_user( 'administrator' );
		$o     = yume_tc_oeuvre( 'Attente' );
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( $admin, $o, &$id ) {
				$rep = yume_rest(
					'POST',
					'/wp/v2/tomes',
					array(
						'title'  => 'Attente — Tome 1',
						'status' => 'publish',
						'meta'   => array( 'yume_oeuvre_id' => $o ),
					),
					$admin
				);
				$id  = (int) $rep->get_data()['id'];
			}
		);
		yume_assert_same( array( $id ), $recus );
		yume_assert_same( $o, yume_get_oeuvre_id( $id ), 'l’œuvre est connue quand l’action part' );

		// Publié sans œuvre puis rattaché plus tard (sauvegarde des méta-boîtes).
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( &$sans ) {
				$sans = yume_factory_post(
					array(
						'post_type'  => 'yume_tome',
						'post_title' => 'Sans œuvre',
					)
				);
			}
		);
		yume_assert_same( array(), $recus );
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( $sans, $o ) {
				update_post_meta( $sans, 'yume_oeuvre_id', $o );
			}
		);
		yume_assert_same( array( $sans ), $recus );
	}
);

yume_test(
	'yume_chapitre_publie : seulement si le tome était déjà publié',
	function () {
		$o = yume_tc_oeuvre( 'Chapitres isolés' );
		$t = yume_tc_tome( $o, 7, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) );

		// Même requête que la sortie du tome : sortie groupée, pas d'événement.
		$recus = yume_tc_ecouter(
			'yume_chapitre_publie',
			function () use ( $t ) {
				yume_tc_chapitre( $t, 1 );
			}
		);
		yume_assert_same( array(), $recus );

		// Requête suivante : chapitre publié isolément.
		yume_tc_nouvelle_requete();
		$recus = yume_tc_ecouter(
			'yume_chapitre_publie',
			function () use ( $t, &$c2 ) {
				$c2 = yume_tc_chapitre( $t, 2 );
			}
		);
		yume_assert_same( array( $c2 ), $recus );

		// Tome encore en brouillon : jamais d'événement.
		$brouillon = yume_tc_tome( $o, 8, array( 'post_status' => 'draft' ) );
		yume_tc_nouvelle_requete();
		$recus = yume_tc_ecouter(
			'yume_chapitre_publie',
			function () use ( $brouillon ) {
				yume_tc_chapitre( $brouillon, 1 );
			}
		);
		yume_assert_same( array(), $recus );

		// Tome et chapitre programmés à la même heure (cron, requêtes séparées) : sortie groupée.
		$date = gmdate( 'Y-m-d H:i:s', time() - 60 + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$t9   = yume_tc_tome( $o, 9, array( 'post_date' => $date ) );
		yume_tc_nouvelle_requete();
		$recus = yume_tc_ecouter(
			'yume_chapitre_publie',
			function () use ( $t9, $date ) {
				yume_tc_chapitre( $t9, 1, array( 'post_date' => $date ) );
			}
		);
		yume_assert_same( array(), $recus );
	}
);

yume_test(
	'événements supprimés : contenu ancien (migration), filtre, publication orchestrée',
	function () {
		$o     = yume_tc_oeuvre( 'Suppression' );
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( $o, &$ancien ) {
				$ancien = yume_tc_tome( $o, 1, array( 'post_date' => '2021-03-01 12:00:00' ) );
			}
		);
		yume_assert_same( array(), $recus, 'tome migré daté de 2021' );
		yume_assert_same( 'ignore', get_post_meta( $ancien, '_yume_publie_notifie', true ) );
		yume_assert_true( '' !== (string) get_post_meta( $o, 'yume_derniere_sortie', true ), 'le cache de dernière sortie est tout de même calculé' );

		add_filter( 'yume_core_notifier', '__return_false' );
		try {
			$recus = yume_tc_ecouter(
				'yume_tome_publie',
				function () use ( $o ) {
					yume_tc_tome( $o, 2 );
				}
			);
		} finally {
			remove_filter( 'yume_core_notifier', '__return_false' );
		}
		yume_assert_same( array(), $recus, 'filtre yume_core_notifier' );

		// Publication orchestrée : core n'émet rien, le module publication émet lui-même.
		$t3 = yume_tc_tome( $o, 3, array( 'post_status' => 'draft' ) );
		try {
			$recus = yume_tc_ecouter(
				'yume_tome_publie',
				function () use ( $t3 ) {
					do_action( 'yume_publication_en_cours', $t3 );
					wp_update_post(
						array(
							'ID'          => $t3,
							'post_status' => 'publish',
						)
					);
					yume_assert_same( '', (string) get_post_meta( $t3, '_yume_publie_notifie', true ), 'rien de marqué par core' );
					do_action( 'yume_tome_publie', $t3 );
				}
			);
		} finally {
			unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
		}
		yume_assert_same( array( $t3 ), $recus, 'une seule émission (celle de publication)' );
		yume_assert_true( '' !== (string) get_post_meta( $t3, '_yume_publie_notifie', true ), 'marqué par l’écouteur de core' );
		wp_update_post(
			array(
				'ID'          => $t3,
				'post_status' => 'draft',
			)
		);
		$recus = yume_tc_ecouter(
			'yume_tome_publie',
			function () use ( $t3 ) {
				wp_update_post(
					array(
						'ID'          => $t3,
						'post_status' => 'publish',
					)
				);
			}
		);
		yume_assert_same( array(), $recus, 'jamais deux fois' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Réglages
 * -----------------------------------------------------------------------------
 */

yume_test(
	'réglages : formulaire, cases décochées, webhooks, mises à jour partielles',
	function () {
		delete_option( 'yume_reglages' );
		$sortie = assainir_reglages(
			array(
				'_formulaire'             => '1',
				'kofi_url'                => 'https://ko-fi.com/autre',
				'rappel_jours_sans_maj'   => '500',
				'rappel_heure'            => '25',
				'digest_jour'             => '0',
				'jours_sortie'            => array( 'samedi', 'jour-inconnu' ),
				'discord_webhook_sorties' => 'https://discord.com/api/webhooks/123456/abcDEF-_x',
				'discord_webhook_equipe'  => 'https://example.com/pas-un-webhook',
				'modele_annonce'          => "<script>x</script>Le {nature} {numero}\nde {oeuvre} !",
				'github_repo'             => 'pas un dépôt',
			)
		);
		yume_assert_false( $sortie['emails_lecteurs'], 'case décochée' );
		yume_assert_false( $sortie['maj_auto'] );
		yume_assert_same( 'https://ko-fi.com/autre', $sortie['kofi_url'] );
		yume_assert_same( 90, $sortie['rappel_jours_sans_maj'], 'borné au maximum' );
		yume_assert_same( 9, $sortie['rappel_heure'], 'option inconnue : valeur précédente' );
		yume_assert_same( 0, $sortie['digest_jour'] );
		yume_assert_same( array( 'samedi' ), $sortie['jours_sortie'] );
		yume_assert_same( 'https://discord.com/api/webhooks/123456/abcDEF-_x', $sortie['discord_webhook_sorties'] );
		yume_assert_same( '', $sortie['discord_webhook_equipe'], 'webhook invalide refusé' );
		yume_assert_not_contains( '<script>', $sortie['modele_annonce'] );
		yume_assert_contains( "\n", $sortie['modele_annonce'] );
		yume_assert_same( 'GNAlexandre/Yume-WordPress', $sortie['github_repo'] );
		yume_assert_true( count( get_settings_errors( 'yume_reglages' ) ) >= 2 );

		// Mise à jour programmée partielle : le reste est conservé.
		update_option( 'yume_reglages', $sortie );
		$partiel = assainir_reglages( array( 'banniere_id' => 0 ) );
		yume_assert_false( $partiel['emails_lecteurs'] );
		yume_assert_same( 'https://ko-fi.com/autre', $partiel['kofi_url'] );
	}
);

yume_test(
	'réglages : champs ajoutés par un module (yume_reglages_champs) et capacité de la page',
	function () {
		$ajout = static function ( array $champs ): array {
			$champs[] = array(
				'key'         => 'test_limite',
				'label'       => 'Limite',
				'type'        => 'number',
				'section'     => 'section_test',
				'default'     => 42,
				'description' => '',
				'min'         => 1,
			);
			return $champs;
		};
		add_filter( 'yume_reglages_champs', $ajout );
		try {
			delete_option( 'yume_reglages' );
			yume_assert_same( 42, yume_setting( 'test_limite' ) );
			$sortie = assainir_reglages(
				array(
					'_formulaire' => '1',
					'test_limite' => '-3',
				)
			);
			yume_assert_same( 1, $sortie['test_limite'] );
		} finally {
			remove_filter( 'yume_reglages_champs', $ajout );
		}
		yume_assert_same( 'yume_reglages', apply_filters( 'option_page_capability_yume_reglages', 'manage_options' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Taxonomie « œuvre liée » et liens de termes
 * -----------------------------------------------------------------------------
 */

yume_test(
	'terme « œuvre liée » créé, renommé et supprimé avec l’œuvre',
	function () {
		$o     = yume_tc_oeuvre( 'Witches Can’t Be Collared' );
		$slug  = get_post_field( 'post_name', $o );
		$terme = get_term_by( 'slug', $slug, 'yume_oeuvre_liee' );
		yume_assert_true( $terme instanceof WP_Term, 'terme créé' );
		yume_assert_same( 'Witches Can’t Be Collared', $terme->name );
		yume_assert_same( $o, (int) get_term_meta( $terme->term_id, 'yume_oeuvre_id', true ) );
		yume_assert_same( get_permalink( $o ), get_term_link( $terme ) );

		wp_update_post(
			array(
				'ID'         => $o,
				'post_title' => 'Witches Can’t Be Collared (nouveau titre)',
				'post_name'  => 'witches',
			)
		);
		$renomme = get_term( $terme->term_id, 'yume_oeuvre_liee' );
		yume_assert_same( 'witches', $renomme->slug );
		yume_assert_same( 'Witches Can’t Be Collared (nouveau titre)', $renomme->name );

		wp_delete_post( $o, true );
		yume_assert_same( null, get_term( $terme->term_id, 'yume_oeuvre_liee' ) );
	}
);

yume_test(
	'terme « œuvre liée » : brouillon, adoption d’un terme libre, pas de doublon',
	function () {
		$libre = wp_insert_term( 'Survival', 'yume_oeuvre_liee', array( 'slug' => 'survival' ) );
		$o     = yume_tc_oeuvre( 'Survival', array( 'post_status' => 'draft' ) );
		yume_assert_same( (int) $libre['term_id'], (int) get_post_meta( $o, '_yume_terme_lie', true ), 'terme libre adopté' );
		wp_update_post(
			array(
				'ID'          => $o,
				'post_status' => 'publish',
			)
		);
		yume_assert_same(
			1,
			count(
				get_terms(
					array(
						'taxonomy'   => 'yume_oeuvre_liee',
						'hide_empty' => false,
						'slug'       => 'survival',
					)
				)
			)
		);
		wp_trash_post( $o );
		yume_assert_true( get_term( (int) $libre['term_id'], 'yume_oeuvre_liee' ) instanceof WP_Term, 'conservé à la corbeille' );
	}
);

yume_test(
	'liens de termes : bibliothèque filtrée',
	function () {
		$manga = get_term_by( 'slug', 'manga', 'yume_type' );
		yume_assert_same( add_query_arg( 'type', 'manga', yume_url_page( 'bibliotheque' ) ), get_term_link( $manga ) );
		$statut = get_term_by( 'slug', 'en-cours', 'yume_statut' );
		yume_assert_same( add_query_arg( 'statut', 'en-cours', yume_url_page( 'bibliotheque' ) ), get_term_link( $statut ) );
		$genre = wp_insert_term( 'Fantasy', 'yume_genre' );
		yume_assert_same( add_query_arg( 'genre', 'fantasy', yume_url_page( 'bibliotheque' ) ), get_term_link( (int) $genre['term_id'], 'yume_genre' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Administration
 * -----------------------------------------------------------------------------
 */

yume_test(
	'méta-boîte du tome : enregistrement, planning daté, droits',
	function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$trad    = yume_factory_user( 'yume_traducteur' );
		$o       = yume_tc_oeuvre( 'Méta-boîtes' );
		$t       = yume_tc_tome( $o, 1, array( 'post_status' => 'draft' ) );
		wp_set_current_user( $editeur );
		$_POST = array(
			'yume_nonce_tome' => wp_create_nonce( 'yume_enregistrer_tome_' . $t ),
			'yume'            => wp_slash(
				array(
					'oeuvre_id'     => (string) $o,
					'nature'        => 'arc',
					'numero'        => '7',
					'lien_pdf'      => 'https://clictune.example/arc7.pdf',
					'lien_epub'     => 'javascript:alert(1)',
					'equivalence'   => 'Équivaut au tome 3',
					'credits'       => array(
						'traduction' => 'Pizzflc',
						'relecture'  => '',
						'edition'    => '',
					),
					'illustrations' => '',
					'etape'         => 'relecture',
					'avancement'    => array(
						'traduction' => '100',
						'relecture'  => '35',
						'edition'    => '0',
					),
					'responsables'  => array(
						'traduction' => (string) $trad,
						'relecture'  => '0',
						'edition'    => '0',
					),
					'date_cible'    => '2026-09-26',
					'bloque'        => '1',
					'bloque_raison' => 'Relecteur absent',
					'note_equipe'   => 'Voir sur Discord',
				)
			),
		);
		try {
			\Yume\Core\Core\enregistrer_tome( $t );
		} finally {
			$_POST = array();
		}
		yume_assert_same( 'arc', get_post_meta( $t, 'yume_nature', true ) );
		yume_assert_same( 7.0, (float) get_post_meta( $t, 'yume_numero', true ) );
		yume_assert_same( 'https://clictune.example/arc7.pdf', get_post_meta( $t, 'yume_lien_pdf', true ) );
		yume_assert_false( metadata_exists( 'post', $t, 'yume_lien_epub' ), 'URL dangereuse refusée' );
		yume_assert_same( 'relecture', get_post_meta( $t, 'yume_etape', true ) );
		yume_assert_same( 35, get_post_meta( $t, 'yume_avancement', true )['relecture'] );
		yume_assert_same( $trad, get_post_meta( $t, 'yume_responsables', true )['traduction'] );
		yume_assert_true( (bool) get_post_meta( $t, 'yume_bloque', true ) );
		yume_assert_same( 'Voir sur Discord', get_post_meta( $t, 'yume_note_equipe', true ) );
		yume_assert_same( $editeur, (int) get_post_meta( $t, 'yume_maj_par', true ) );
		yume_assert_true( '' !== (string) get_post_meta( $t, 'yume_derniere_maj', true ) );
		yume_assert_true( yume_user_can_edit_planning( $t, $trad ), 'le traducteur désigné devient responsable' );

		// Nonce absent ou d'un autre contenu : rien n'est écrit.
		$_POST = array(
			'yume_nonce_tome' => wp_create_nonce( 'yume_enregistrer_tome_' . ( $t + 1 ) ),
			'yume'            => array( 'nature' => 'bonus' ),
		);
		try {
			\Yume\Core\Core\enregistrer_tome( $t );
		} finally {
			$_POST = array();
		}
		yume_assert_same( 'arc', get_post_meta( $t, 'yume_nature', true ) );
	}
);

yume_test(
	'méta-boîtes de l’œuvre et du chapitre',
	function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$o       = yume_tc_oeuvre( 'Fiche' );
		$t       = yume_tc_tome( $o, 1 );
		$c       = yume_tc_chapitre( $t, 1 );
		wp_set_current_user( $editeur );
		$_POST = array(
			'yume_nonce_oeuvre' => wp_create_nonce( 'yume_enregistrer_oeuvre_' . $o ),
			'yume'              => wp_slash(
				array(
					'titres_alt'        => "灰と幻想のグリムガル\nHai to Gensou no Grimgar",
					'auteur'            => 'Jyumonji Ao',
					'illustrateur'      => 'Shirai Eiri',
					'editeur_vo'        => 'OVERLAP',
					'nb_tomes_vo'       => '22',
					'statut_vo'         => 'en_cours',
					'source_traduction' => 'Édition anglaise officielle (J-Novel Club)',
					'jours_sortie'      => array( 'dimanche', 'mercredi' ),
					'equipe'            => array(
						'traduction' => 'Calumi',
						'relecture'  => 'Angeloids',
						'edition'    => 'JojoGg',
					),
					'liens'             => array(
						array(
							'label' => 'Novel-Index',
							'url'   => 'https://novel-index.example/grimgar',
						),
						array(
							'label' => '',
							'url'   => '',
						),
					),
					'banniere_id'       => '999999',
				)
			),
		);
		try {
			\Yume\Core\Core\enregistrer_oeuvre( $o );
		} finally {
			$_POST = array();
		}
		yume_assert_same( array( '灰と幻想のグリムガル', 'Hai to Gensou no Grimgar' ), get_post_meta( $o, 'yume_titres_alt', true ) );
		yume_assert_same( 22, (int) get_post_meta( $o, 'yume_nb_tomes_vo', true ) );
		yume_assert_same( array( 'mercredi', 'dimanche' ), get_post_meta( $o, 'yume_jours_sortie', true ) );
		yume_assert_same( 1, count( get_post_meta( $o, 'yume_liens', true ) ) );
		yume_assert_false( metadata_exists( 'post', $o, 'yume_banniere_id' ), 'pièce jointe inexistante refusée' );

		$_POST = array(
			'yume_nonce_chapitre' => wp_create_nonce( 'yume_enregistrer_chapitre_' . $c ),
			'yume'                => array(
				'tome_id'    => (string) $t,
				'nature'     => 'chapitre',
				'numero'     => '1',
				'sous_titre' => 'La Crête Brumeuse',
				'credits'    => array(
					'traduction' => 'Calumi',
					'relecture'  => '',
					'edition'    => '',
				),
			),
		);
		try {
			\Yume\Core\Core\enregistrer_chapitre( $c );
		} finally {
			$_POST = array();
		}
		yume_assert_same( 'La Crête Brumeuse', get_post_meta( $c, 'yume_sous_titre', true ) );
		yume_assert_same( 'Calumi', get_post_meta( $c, 'yume_credits', true )['traduction'] );
	}
);

yume_test(
	'titres automatiques des tomes et chapitres enregistrés sans titre',
	function () {
		$o = yume_tc_oeuvre( 'Grimgar' );
		$t = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'post_title' => '',
				'meta_input' => array(
					'yume_oeuvre_id' => $o,
					'yume_numero'    => 9,
				),
			)
		);
		yume_assert_same( 'Grimgar — Tome 9', get_post_field( 'post_title', $t ) );
		$c = yume_factory_post(
			array(
				'post_type'  => 'yume_chapitre',
				'post_title' => '',
				'meta_input' => array(
					'yume_tome_id' => $t,
					'yume_nature'  => 'postface',
				),
			)
		);
		yume_assert_same( 'Postface', get_post_field( 'post_title', $c ) );
		yume_assert_same( 'postface', get_post_field( 'post_name', $c ) );
	}
);

yume_test(
	'tri des listes d’administration (SQL portable)',
	function () {
		$a = yume_tc_oeuvre( 'Zeta tri' );
		$b = yume_tc_oeuvre( 'Alpha tri' );

		list( $t1, $t2, $t3 ) = yume_tc_sans_evenements(
			static fn(): array => array(
				yume_tc_tome(
					$a,
					10,
					array(
						'meta_input' => array(
							'yume_etape'      => 'edition',
							'yume_date_cible' => '2026-10-01',
						),
					)
				),
				yume_tc_tome( $a, 2, array( 'meta_input' => array( 'yume_etape' => 'traduction' ) ) ),
				yume_tc_tome(
					$b,
					1,
					array(
						'meta_input' => array(
							'yume_etape'      => 'a_faire',
							'yume_date_cible' => '2026-09-28',
						),
					)
				),
			)
		);

		$ids = static function ( string $tri, string $ordre = 'ASC' ) use ( $a, $b ): array {
			$q                                   = new WP_Query(
				array(
					'post_type'      => 'yume_tome',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'yume_tri'       => $tri,
					'order'          => $ordre,
					'meta_query'     => array(
						array(
							'key'     => 'yume_oeuvre_id',
							'value'   => array( (string) $a, (string) $b ),
							'compare' => 'IN',
						),
					),
				)
			);
			$GLOBALS['yume_tc_derniere_requete'] = $q->request;
			return array_map( 'intval', $q->posts );
		};
		yume_assert_same( array( $t3, $t2, $t1 ), $ids( 'yume_numero' ), (string) ( $GLOBALS['yume_tc_derniere_requete'] ?? '' ) );
		yume_assert_same( array( $t1, $t2, $t3 ), $ids( 'yume_numero', 'DESC' ), 'numéro décroissant' );
		yume_assert_same( array( $t3, $t2, $t1 ), $ids( 'yume_oeuvre' ), 'par titre d’œuvre puis numéro' );
		yume_assert_same( array( $t3, $t2, $t1 ), $ids( 'yume_etape' ), 'ordre des étapes ' . $GLOBALS['yume_tc_derniere_requete'] );
		yume_assert_same( array( $t3, $t1, $t2 ), $ids( 'yume_date_cible' ), 'sans date en dernier' );

		list( $c1, $c2, $c3 ) = yume_tc_sans_evenements(
			static fn(): array => array(
				yume_tc_chapitre( $t1, 2 ),
				yume_tc_chapitre( $t3, 5 ),
				yume_tc_chapitre( $t1, 1 ),
			)
		);

		$q = new WP_Query(
			array(
				'post_type'      => 'yume_chapitre',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'yume_tri'       => 'yume_tome',
				'order'          => 'ASC',
				'post__in'       => array( $c1, $c2, $c3 ),
			)
		);
		yume_assert_same( array( $c2, $c3, $c1 ), array_map( 'intval', $q->posts ), 'chapitres par tome ' . $q->request );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Correctifs de revue (non-régression)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute des variables de requête comme la requête principale (pre_get_posts la voit comme telle).
 *
 * @param array<string,mixed> $vars Variables de requête.
 */
function yume_tc_requete_principale( array $vars ): WP_Query {
	$sauve                   = $GLOBALS['wp_the_query'] ?? null;
	$q                       = new WP_Query();
	$GLOBALS['wp_the_query'] = $q;
	try {
		$q->query( $vars );
	} finally {
		$GLOBALS['wp_the_query'] = $sauve;
	}
	return $q;
}

yume_test(
	'SEC-E-1 : github_repo et maj_auto réservés aux comptes qui peuvent installer des mises à jour',
	function () {
		\Yume\Core\Core\enregistrer_reglage();
		$gerant = yume_factory_user( 'yume_gerant' );
		$admin  = yume_factory_user( 'administrator' );
		delete_option( 'yume_reglages' );

		wp_set_current_user( $gerant );
		yume_assert_true( current_user_can( 'yume_reglages' ) );
		yume_assert_false( current_user_can( 'update_plugins' ) );
		// Formulaire complet envoyé à options.php par un gérant.
		$sortie = assainir_reglages(
			array(
				'_formulaire' => '1',
				'kofi_url'    => 'https://ko-fi.com/gerant',
				'github_repo' => 'attaquant/depot-piege',
			)
		);
		yume_assert_same( 'GNAlexandre/Yume-WordPress', $sortie['github_repo'], 'dépôt inchangé' );
		yume_assert_true( $sortie['maj_auto'], 'case absente : valeur conservée, pas décochée' );
		yume_assert_same( 'https://ko-fi.com/gerant', $sortie['kofi_url'], 'les autres réglages restent modifiables' );
		// Écriture directe de l'option (même assainissement que options.php).
		update_option(
			'yume_reglages',
			array(
				'github_repo' => 'attaquant/depot-piege',
				'maj_auto'    => false,
			)
		);
		yume_assert_same( 'GNAlexandre/Yume-WordPress', yume_setting( 'github_repo' ) );
		yume_assert_true( (bool) yume_setting( 'maj_auto' ) );
		yume_assert_same( 'https://github.com/GNAlexandre/Yume-WordPress/', \Yume\Core\Updater\url_depot( \Yume\Core\Updater\depot() ) );
		// Les deux champs ne sont pas affichés au gérant.
		$cles = array();
		foreach ( \Yume\Core\Core\champs_reglages() as $champ ) {
			if ( \Yume\Core\Core\champ_visible( $champ ) ) {
				$cles[] = $champ['key'];
			}
		}
		yume_assert_false( in_array( 'github_repo', $cles, true ) );
		yume_assert_false( in_array( 'maj_auto', $cles, true ) );
		yume_assert_true( in_array( 'kofi_url', $cles, true ) );

		// L'administrateur les modifie.
		wp_set_current_user( $admin );
		update_option(
			'yume_reglages',
			array(
				'github_repo' => 'Yume-Novel/Yume-WordPress',
				'maj_auto'    => false,
			)
		);
		yume_assert_same( 'Yume-Novel/Yume-WordPress', yume_setting( 'github_repo' ) );
		yume_assert_false( (bool) yume_setting( 'maj_auto' ) );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'SEC-S-2 / MET-6 : dépublier un tome ou une œuvre retire ses tomes et chapitres (URL, REST, plan du site)',
	function () {
		flush_rewrite_rules( false );
		$editeur                 = yume_factory_user( 'yume_editeur' );
		list( $o, $t, $c1, $c2 ) = yume_tc_sans_evenements(
			static function (): array {
				$o = yume_tc_oeuvre( 'Visibilité héritée' );
				$t = yume_tc_tome( $o, 7801 );
				return array( $o, $t, yume_tc_chapitre( $t, 1 ), yume_tc_chapitre( $t, 2 ) );
			}
		);
		$lien_c1                 = get_permalink( $c1 );
		$lien_t                  = get_permalink( $t );
		yume_assert_same( $c1, (int) ( yume_tc_parse( $lien_c1 )['p'] ?? 0 ), 'chapitre visible avant' );
		yume_assert_same( 200, yume_rest( 'GET', '/wp/v2/chapitres/' . $c1 )->get_status() );

		// Tome dépublié.
		wp_update_post(
			array(
				'ID'          => $t,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( '404', yume_tc_parse( $lien_c1 )['error'] ?? '', 'chapitre d’un tome brouillon : 404' );
		yume_assert_same( 404, yume_rest( 'GET', '/wp/v2/chapitres/' . $c1 )->get_status(), 'REST élément' );
		$liste = wp_list_pluck(
			yume_rest(
				'GET',
				'/wp/v2/chapitres',
				array(
					'per_page' => 100,
					'include'  => array( $c1, $c2 ),
				)
			)->get_data(),
			'id'
		);
		yume_assert_same( array(), array_map( 'intval', $liste ), 'REST liste' );
		$args = apply_filters(
			'wp_sitemaps_posts_query_args',
			array(
				'post_type'      => 'yume_chapitre',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			),
			'yume_chapitre'
		);
		$plan = array_map( 'intval', ( new WP_Query( $args ) )->posts );
		yume_assert_false( in_array( $c1, $plan, true ) || in_array( $c2, $plan, true ), 'plan du site' );
		$q = yume_tc_requete_principale(
			array(
				'post_type' => 'yume_chapitre',
				'p'         => $c1,
			)
		);
		yume_assert_same( array(), $q->posts, 'lien simple ?post_type=yume_chapitre&p=' );
		// Aperçu : l'éditeur, qui peut modifier le tome, lit toujours le chapitre.
		wp_set_current_user( $editeur );
		yume_assert_same( $c1, (int) ( yume_tc_parse( $lien_c1 )['p'] ?? 0 ), 'aperçu éditeur' );
		wp_set_current_user( 0 );
		yume_assert_same( 200, yume_rest( 'GET', '/wp/v2/chapitres/' . $c1, array( 'context' => 'edit' ), $editeur )->get_status() );

		// Tome republié : tout revient.
		wp_update_post(
			array(
				'ID'          => $t,
				'post_status' => 'publish',
			)
		);
		yume_assert_same( $c1, (int) ( yume_tc_parse( $lien_c1 )['p'] ?? 0 ) );
		yume_assert_same( 200, yume_rest( 'GET', '/wp/v2/chapitres/' . $c1 )->get_status() );

		// Œuvre dépubliée : tome et chapitres disparaissent, recherche comprise.
		wp_update_post(
			array(
				'ID'          => $o,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( '404', yume_tc_parse( $lien_t )['error'] ?? '', 'tome d’une œuvre brouillon : 404' );
		yume_assert_same( '404', yume_tc_parse( $lien_c1 )['error'] ?? '', 'chapitre d’une œuvre brouillon : 404' );
		yume_assert_same( 404, yume_rest( 'GET', '/wp/v2/tomes/' . $t )->get_status() );
		yume_assert_same( 404, yume_rest( 'GET', '/wp/v2/chapitres/' . $c2 )->get_status() );
		$trouves = wp_list_pluck( yume_rest( 'GET', '/wp/v2/search', array( 'search' => 'Visibilité héritée' ) )->get_data(), 'id' );
		yume_assert_false( in_array( $t, array_map( 'intval', $trouves ), true ), 'recherche REST' );
		$q = yume_tc_requete_principale(
			array(
				'post_type' => 'yume_tome',
				'p'         => $t,
			)
		);
		yume_assert_same( array(), $q->posts, 'lien simple du tome' );
		$args = apply_filters(
			'wp_sitemaps_posts_query_args',
			array(
				'post_type'      => 'yume_tome',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			),
			'yume_tome'
		);
		yume_assert_false( in_array( $t, array_map( 'intval', ( new WP_Query( $args ) )->posts ), true ), 'plan du site des tomes' );
	}
);

yume_test(
	'SEC-S-7 : archives d’auteur fermées, pas de plan du site des utilisateurs',
	function () {
		flush_rewrite_rules( false );
		$membre         = yume_factory_user( 'yume_traducteur' );
		$_GET['author'] = (string) $membre;
		try {
			yume_assert_same( '404', yume_tc_parse( home_url( '/?author=' . $membre ) )['error'] ?? '', '?author=N' );
		} finally {
			unset( $_GET['author'] );
		}
		$nicename = get_userdata( $membre )->user_nicename;
		yume_assert_same( '404', yume_tc_parse( home_url( '/author/' . $nicename . '/' ) )['error'] ?? '' );
		yume_assert_same( '404', yume_tc_parse( home_url( '/author/' . $nicename . '/feed/' ) )['error'] ?? '' );
		yume_assert_false( apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'users' ) );
		yume_assert_true( is_object( apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'posts' ) ) );
	}
);

yume_test(
	'BUG-12 : le lien d’auteur ne mène jamais aux archives fermées (fiche du compte, « Mon compte » ou accueil)',
	function () {
		$admin  = yume_factory_user( 'administrator' );
		$membre = yume_factory_user( 'yume_traducteur' );
		$autre  = yume_factory_user( 'yume_relecteur' );

		wp_set_current_user( $admin );
		yume_assert_same( add_query_arg( 'user_id', $membre, admin_url( 'user-edit.php' ) ), get_author_posts_url( $membre ), 'administrateur : fiche du compte' );
		yume_assert_same( admin_url( 'profile.php' ), get_author_posts_url( $admin ), 'son propre compte : profil' );

		wp_set_current_user( $membre );
		yume_assert_same( yume_url_page( 'compte' ), get_author_posts_url( $membre ), 'sans edit_user : page « Mon compte »' );
		yume_assert_same( home_url( '/' ), get_author_posts_url( $autre ), 'compte d’un autre : accueil' );

		wp_set_current_user( 0 );
		yume_assert_same( home_url( '/' ), get_author_posts_url( $membre ), 'visiteur : accueil' );
		yume_assert_not_contains( get_userdata( $membre )->user_nicename, get_author_posts_url( $membre ), 'identifiant non révélé' );
		yume_assert_same( home_url( '/' ), get_author_posts_url( 0 ) );
	}
);

yume_test(
	'MET-1 : la méta-boîte n’écrase ni la publication ni une mise à jour concurrente du planning',
	function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$o       = yume_tc_oeuvre( 'Planning concurrent' );
		$t       = yume_tc_sans_evenements( static fn(): int => yume_tc_tome( $o, 3, array( 'post_status' => 'draft' ) ) );
		update_post_meta( $t, 'yume_etape', 'relecture' );
		update_post_meta(
			$t,
			'yume_avancement',
			array(
				'traduction' => 100,
				'relecture'  => 20,
				'edition'    => 0,
			)
		);
		wp_set_current_user( $editeur );
		// Formulaire tel qu'affiché à l'ouverture de l'éditeur.
		ob_start();
		\Yume\Core\Core\boite_planning( get_post( $t ) );
		$html = (string) ob_get_clean();
		yume_assert_true( (bool) preg_match( '/name="yume\[planning_origine\]" value="([^"]*)"/', $html, $m ), 'valeurs d’origine dans le formulaire' );
		$origine = html_entity_decode( $m[1], ENT_QUOTES );
		$poster  = static function ( array $champs ) use ( $t, $origine ): void {
			$_POST = array(
				'yume_nonce_tome' => wp_create_nonce( 'yume_enregistrer_tome_' . $t ),
				'yume'            => wp_slash(
					array_merge(
						array(
							'oeuvre_id'        => (string) get_post_meta( $t, 'yume_oeuvre_id', true ),
							'nature'           => 'tome',
							'numero'           => '3',
							'etape'            => 'relecture',
							'avancement'       => array(
								'traduction' => '100',
								'relecture'  => '20',
								'edition'    => '0',
							),
							'responsables'     => array(
								'traduction' => '0',
								'relecture'  => '0',
								'edition'    => '0',
							),
							'date_cible'       => '',
							'bloque_raison'    => '',
							'note_equipe'      => '',
							'planning_origine' => $origine,
						),
						$champs
					)
				),
			);
			try {
				\Yume\Core\Core\enregistrer_tome( $t );
			} finally {
				$_POST = array();
			}
		};

		// (a) La publication a fait passer le tome à « publié, 100 % » entre-temps.
		update_post_meta( $t, 'yume_etape', 'publie' );
		update_post_meta(
			$t,
			'yume_avancement',
			array(
				'traduction' => 100,
				'relecture'  => 100,
				'edition'    => 100,
			)
		);
		$poster( array() );
		yume_assert_same( 'publie', get_post_meta( $t, 'yume_etape', true ), 'étape non ramenée en arrière' );
		yume_assert_same( 100, get_post_meta( $t, 'yume_avancement', true )['relecture'] );

		// (b) Mise à jour concurrente d'un seul champ, puis modification d'un autre champ dans l'éditeur.
		update_post_meta( $t, 'yume_etape', 'relecture' );
		update_post_meta(
			$t,
			'yume_avancement',
			array(
				'traduction' => 77,
				'relecture'  => 20,
				'edition'    => 0,
			)
		);
		$poster( array( 'date_cible' => '2026-12-24' ) );
		yume_assert_same( 77, get_post_meta( $t, 'yume_avancement', true )['traduction'], 'progression concurrente conservée' );
		yume_assert_same( '2026-12-24', get_post_meta( $t, 'yume_date_cible', true ), 'champ modifié écrit' );

		// (c) Champ réellement modifié par l'utilisateur : écrit (étapes précédentes terminées).
		$poster(
			array(
				'etape'      => 'edition',
				'avancement' => array(
					'traduction' => '100',
					'relecture'  => '100',
					'edition'    => '0',
				),
			)
		);
		yume_assert_same( 'edition', get_post_meta( $t, 'yume_etape', true ) );
		yume_assert_same( array(), \Yume\Core\Core\lire_refus_planning( $editeur, $t ), 'aucun refus' );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'SCAN-02 : méta-boîte, étape refusée signalée (avis après redirection), forcée par un gérant',
	function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$gerant  = yume_factory_user( 'yume_gerant' );
		$o       = yume_tc_oeuvre( 'Refus signalé' );
		$t       = yume_tc_sans_evenements( static fn(): int => yume_tc_tome( $o, 4, array( 'post_status' => 'draft' ) ) );
		update_post_meta( $t, 'yume_etape', 'traduction' );
		update_post_meta(
			$t,
			'yume_avancement',
			array(
				'traduction' => 70,
				'relecture'  => 0,
				'edition'    => 0,
			)
		);
		$poster = static function ( array $champs ) use ( $t ): void {
			$_POST = array(
				'yume_nonce_tome' => wp_create_nonce( 'yume_enregistrer_tome_' . $t ),
				'yume'            => wp_slash(
					array_merge(
						array(
							'oeuvre_id'     => (string) get_post_meta( $t, 'yume_oeuvre_id', true ),
							'nature'        => 'tome',
							'numero'        => '4',
							'etape'         => 'relecture',
							'avancement'    => array(
								'traduction' => '70',
								'relecture'  => '0',
								'edition'    => '0',
							),
							'responsables'  => array(
								'traduction' => '0',
								'relecture'  => '0',
								'edition'    => '0',
							),
							'date_cible'    => '',
							'bloque_raison' => '',
							'note_equipe'   => '',
						),
						$champs
					)
				),
			);
			try {
				\Yume\Core\Core\enregistrer_tome( $t );
			} finally {
				$_POST = array();
			}
		};

		// Éditeur : passage à la relecture avec une traduction à 70 % refusé, mais plus en silence.
		wp_set_current_user( $editeur );
		$poster( array() );
		yume_assert_same( 'traduction', get_post_meta( $t, 'yume_etape', true ), 'étape refusée' );
		$refus = get_transient( \Yume\Core\Core\cle_refus_planning( $editeur ) );
		yume_assert_true( is_array( $refus ) && $t === $refus['tome'], 'refus mémorisé pour ce tome' );
		yume_assert_contains( 'Terminez d’abord l’étape « Traduction »', implode( ' ', $refus['messages'] ) );

		// Avis d'administration au rechargement (éditeur classique), une seule fois.
		$ecran = \WP_Screen::get( 'yume_tome' );
		$ecran->is_block_editor( false );
		$ecran->set_current_screen();
		$GLOBALS['post'] = get_post( $t ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		ob_start();
		\Yume\Core\Core\avis_refus_planning();
		$avis = (string) ob_get_clean();
		yume_assert_contains( 'notice notice-error', $avis );
		yume_assert_contains( 'Étape non modifiée', $avis );
		ob_start();
		\Yume\Core\Core\avis_refus_planning();
		yume_assert_same( '', (string) ob_get_clean(), 'avis affiché une seule fois' );

		// Éditeur de blocs : refus lus par admin-ajax.
		$poster( array() );
		yume_assert_same( array(), \Yume\Core\Core\lire_refus_planning( $editeur, $t + 1000 ), 'autre tome : rien' );
		yume_assert_same( 1, count( \Yume\Core\Core\lire_refus_planning( $editeur, $t ) ) );

		// Responsable choisi hors de l'équipe : écarté, et signalé.
		$lecteur = yume_factory_user( 'subscriber' );
		$poster(
			array(
				'etape'        => 'traduction',
				'responsables' => array(
					'traduction' => (string) $lecteur,
					'relecture'  => '0',
					'edition'    => '0',
				),
			)
		);
		yume_assert_contains( 'ne fait pas partie de l’équipe', implode( ' ', \Yume\Core\Core\lire_refus_planning( $editeur, $t ) ) );
		yume_assert_same( 0, (int) ( get_post_meta( $t, 'yume_responsables', true )['traduction'] ?? 0 ) );

		// Gérant : l'étape peut être forcée (journalisée « étape forcée » si le planning le sait).
		wp_set_current_user( $gerant );
		$poster( array() );
		$attendu = function_exists( '\\Yume\\Core\\Planning\\peut_forcer_etape' ) ? 'relecture' : 'traduction';
		yume_assert_same( $attendu, get_post_meta( $t, 'yume_etape', true ), 'étape forcée par un gérant' );
		if ( 'relecture' === $attendu ) {
			yume_assert_same( array(), \Yume\Core\Core\lire_refus_planning( $gerant, $t ), 'aucun refus pour le gérant' );
			if ( function_exists( '\Yume\Core\Planning\table_journal' ) ) {
				global $wpdb;
				$table = \Yume\Core\Planning\table_journal();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tome_id = %d AND champ = 'etape_forcee'", $t ), ARRAY_A );
				yume_assert_true( is_array( $ligne ), 'étape forcée journalisée' );
				yume_assert_same( 0, (int) $ligne['public'], 'entrée réservée à l’équipe' );
				yume_assert_same( $gerant, (int) $ligne['user_id'] );
			}
		}
		// « Publié » reste interdit à un tome non publié, même pour un gérant.
		$poster( array( 'etape' => 'publie' ) );
		yume_assert_same( $attendu, get_post_meta( $t, 'yume_etape', true ), 'jamais « publié » sur un tome non publié' );
		yume_assert_true( array() !== \Yume\Core\Core\lire_refus_planning( $gerant, $t ) );
		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'SCAN-14 : traducteur, relecteur, graphiste renvoyés de wp-admin vers l’espace équipe, menu Yume masqué',
	function () {
		$membres = array(
			'yume_traducteur' => true,
			'yume_relecteur'  => true,
			'yume_graphiste'  => true,
			'yume_editeur'    => false,
			'yume_gerant'     => false,
			'administrator'   => false,
			'subscriber'      => false,
		);
		foreach ( $membres as $role => $renvoye ) {
			$uid = yume_factory_user( $role );
			yume_assert_same( $renvoye, \Yume\Core\Core\equipe_sans_redaction( $uid ), $role );
		}
		$traducteur = yume_factory_user( 'yume_traducteur' );
		wp_set_current_user( $traducteur );
		$page_avant = $GLOBALS['pagenow'] ?? null;
		foreach ( array(
			'profile.php'      => true,
			'admin-post.php'   => true,
			'async-upload.php' => true,
			'index.php'        => false,
			'edit.php'         => false,
			'post.php'         => false,
		) as $page => $autorisee ) {
			$GLOBALS['pagenow'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			yume_assert_same( $autorisee, \Yume\Core\Core\administration_equipe_autorisee(), $page );
		}
		$GLOBALS['pagenow'] = $page_avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Redirection vers la page équipe.
		$GLOBALS['pagenow'] = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$cible              = '';
		$capter             = static function ( $url ) use ( &$cible ) {
			$cible = (string) $url;
			throw new \RuntimeException( 'redirection' );
		};
		add_filter( 'wp_redirect', $capter, 1 );
		try {
			\Yume\Core\Core\rediriger_equipe_sans_redaction();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $capter, 1 );
			$GLOBALS['pagenow'] = $page_avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		yume_assert_same( yume_url_page( 'equipe' ), $cible );

		// Menu Yume masqué (et visible pour un éditeur).
		global $menu;
		$menu_avant = $menu;
		$menu       = array( array( 'Yume', 'edit_yume_tomes', 'yume' ), array( 'Profil', 'read', 'profile.php' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\Yume\Core\Core\masquer_menus_equipe();
		yume_assert_same( array( 'profile.php' ), array_values( array_column( $menu, 2 ) ) );
		wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
		$menu = array( array( 'Yume', 'edit_yume_tomes', 'yume' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\Yume\Core\Core\masquer_menus_equipe();
		yume_assert_same( array( 'yume' ), array_values( array_column( $menu, 2 ) ) );
		$menu = $menu_avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Barre d'administration : « Tableau de bord » → espace équipe.
		wp_set_current_user( $traducteur );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$barre = new \WP_Admin_Bar();
		$barre->add_node(
			array(
				'id'   => 'site-name',
				'href' => admin_url(),
			)
		);
		$barre->add_node(
			array(
				'id'     => 'dashboard',
				'parent' => 'site-name',
				'href'   => admin_url(),
				'title'  => 'Tableau de bord',
			)
		);
		$barre->add_node(
			array(
				'id'   => 'new-content',
				'href' => admin_url( 'post-new.php' ),
			)
		);
		\Yume\Core\Core\barre_equipe( $barre );
		yume_assert_same( yume_url_page( 'equipe' ), $barre->get_node( 'dashboard' )->href );
		yume_assert_same( 'site-name', $barre->get_node( 'dashboard' )->parent );
		yume_assert_same( null, $barre->get_node( 'new-content' ) );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'MET-3 : yume_tome_publie part une fois toutes les métadonnées écrites (REST)',
	function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$o       = yume_tc_oeuvre( 'Annonce complète' );
		$vus     = array();
		$ecoute  = static function ( $id ) use ( &$vus ): void {
			$vus[] = array(
				'numero' => (string) get_post_meta( (int) $id, 'yume_numero', true ),
				'nature' => (string) get_post_meta( (int) $id, 'yume_nature', true ),
				'pdf'    => (string) get_post_meta( (int) $id, 'yume_lien_pdf', true ),
			);
		};
		add_action( 'yume_tome_publie', $ecoute, 1 );
		try {
			$rep = yume_rest(
				'POST',
				'/wp/v2/tomes',
				array(
					'title'  => 'Annonce complète — Tome 8',
					'status' => 'publish',
					'meta'   => array(
						'yume_oeuvre_id' => $o,
						'yume_numero'    => 8,
						'yume_nature'    => 'tome',
						'yume_lien_pdf'  => 'https://example.com/tome8.pdf',
					),
				),
				$editeur
			);
		} finally {
			remove_action( 'yume_tome_publie', $ecoute, 1 );
		}
		yume_assert_same( 201, $rep->get_status() );
		yume_assert_same( 1, count( $vus ), 'un seul événement' );
		yume_assert_same( '8', $vus[0]['numero'] );
		yume_assert_same( 'tome', $vus[0]['nature'] );
		yume_assert_same( 'https://example.com/tome8.pdf', $vus[0]['pdf'] );
	}
);

yume_test(
	'MET-9 : le gérant gère les membres de l’équipe, jamais les administrateurs ni les gérants',
	function () {
		\Yume\Core\Core\installer_roles();
		$gerant = yume_factory_user( 'yume_gerant' );
		$autre  = yume_factory_user( 'yume_gerant' );
		$admin  = yume_factory_user( 'administrator' );
		$trad   = yume_factory_user( 'yume_traducteur' );
		$lec    = yume_factory_user( 'subscriber' );
		wp_set_current_user( $gerant );
		foreach ( array( 'list_users', 'promote_users' ) as $cap ) {
			yume_assert_true( current_user_can( $cap ), "gérant : $cap" );
		}
		// SEC-03 : ni création de compte ni modification du profil d'un autre compte.
		foreach ( array( 'create_users', 'edit_users' ) as $cap ) {
			yume_assert_false( current_user_can( $cap ), "gérant sans $cap" );
		}
		yume_assert_false( current_user_can( 'edit_user', $trad ), 'profil d’un membre : administrateur seulement' );
		yume_assert_false( current_user_can( 'edit_user', $lec ), 'profil d’un lecteur : administrateur seulement' );
		yume_assert_true( current_user_can( 'promote_user', $trad ) );
		yume_assert_true( current_user_can( 'promote_user', $lec ) );
		yume_assert_false( current_user_can( 'edit_user', $admin ), 'administrateur intouchable' );
		yume_assert_false( current_user_can( 'promote_user', $admin ) );
		yume_assert_false( current_user_can( 'edit_user', $autre ), 'autre gérant intouchable' );
		yume_assert_false( current_user_can( 'promote_user', $gerant ), 'pas de changement de son propre rôle' );
		yume_assert_true( current_user_can( 'edit_user', $gerant ), 'son propre profil' );
		yume_assert_false( current_user_can( 'delete_users' ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$roles = array_keys( get_editable_roles() );
		sort( $roles );
		yume_assert_same( array( 'subscriber', 'yume_editeur', 'yume_graphiste', 'yume_relecteur', 'yume_traducteur' ), $roles );
		wp_set_current_user( 0 );

		// REST : changer le rôle d'un lecteur, refuser administrateur et gérant.
		$rep = yume_rest( 'POST', '/wp/v2/users/' . $lec, array( 'roles' => array( 'yume_traducteur' ) ), $gerant );
		yume_assert_same( 200, $rep->get_status(), wp_json_encode( $rep->get_data() ) );
		yume_assert_same( array( 'yume_traducteur' ), array_values( get_userdata( $lec )->roles ) );
		yume_assert_true( yume_rest( 'POST', '/wp/v2/users/' . $lec, array( 'roles' => array( 'administrator' ) ), $gerant )->get_status() >= 400 );
		yume_assert_true( yume_rest( 'POST', '/wp/v2/users/' . $admin, array( 'roles' => array( 'subscriber' ) ), $gerant )->get_status() >= 400 );
		yume_assert_true( in_array( 'administrator', get_userdata( $admin )->roles, true ) );

		// L'administrateur garde tous les rôles.
		wp_set_current_user( $admin );
		yume_assert_true( isset( get_editable_roles()['administrator'] ) );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'MET-13 / RC-5 : deux chapitres de même numéro ont deux adresses ; routage sans N+1',
	function () {
		global $wpdb;
		flush_rewrite_rules( false );
		list( $t, $c2, $c3a, $c3b, $c4 ) = yume_tc_sans_evenements(
			static function (): array {
				$o = yume_tc_oeuvre( 'Doublons de numéro' );
				$t = yume_tc_tome( $o, 7802 );
				return array(
					$t,
					yume_tc_chapitre( $t, 2 ),
					yume_tc_chapitre( $t, 3, array( 'post_date' => '2026-01-01 10:00:00' ) ),
					yume_tc_chapitre( $t, 3, array( 'post_date' => '2026-01-02 10:00:00' ) ),
					yume_tc_chapitre( $t, 4 ),
				);
			}
		);
		$lien_a                          = get_permalink( $c3a );
		$lien_b                          = get_permalink( $c3b );
		yume_assert_true( str_ends_with( $lien_a, '/3/' ), $lien_a );
		yume_assert_true( $lien_a !== $lien_b, 'adresses distinctes : ' . $lien_b );
		yume_assert_true( str_ends_with( $lien_b, '/' . get_post_field( 'post_name', $c3b ) . '/' ), $lien_b );
		$qv = yume_tc_parse( $lien_a );
		yume_assert_same( $c3a, (int) ( $qv['p'] ?? 0 ) );
		yume_assert_true( empty( $qv['yume_non_canonique'] ) );
		$qv = yume_tc_parse( $lien_b );
		yume_assert_same( $c3b, (int) ( $qv['p'] ?? 0 ), 'le second chapitre est lisible' );
		yume_assert_true( empty( $qv['yume_non_canonique'] ), 'et son adresse est canonique' );
		// Navigation : le chapitre suivant du premier « 3 » est le second, sans boucle.
		$suivant = yume_chapitre_voisin( $c3a, 'next' );
		yume_assert_same( $c3b, $suivant ? (int) $suivant->ID : 0 );
		yume_assert_true( get_permalink( $suivant ) !== $lien_a );

		// Routage : nombre de requêtes indépendant du nombre de chapitres du tome.
		yume_tc_sans_evenements(
			static function () use ( $t ): void {
				for ( $i = 5; $i <= 30; $i++ ) {
					yume_tc_chapitre( $t, $i );
				}
			}
		);
		wp_cache_flush();
		$avant = $wpdb->num_queries;
		yume_tc_parse( get_permalink( $c4 ) );
		$nombre = $wpdb->num_queries - $avant;
		yume_assert_true( $nombre < 25, "requêtes pour router un chapitre d'un tome de 30 chapitres : $nombre" );
	}
);

yume_test(
	'RC-3 / RC-7 : désactivation sans règles orphelines, version autochargée',
	function () {
		global $wpdb;
		flush_rewrite_rules( false );
		$avant = (array) get_option( 'rewrite_rules' );
		yume_assert_true( (bool) preg_grep( '/yume_/', $avant ), 'règles Yume présentes' );
		// Désactivation sans les nettoyages des modules (tâches planifiées…).
		$sauve = $GLOBALS['wp_filter']['yume_core_deactivate'] ?? null;
		unset( $GLOBALS['wp_filter']['yume_core_deactivate'] );
		try {
			yume_core_deactivate();
		} finally {
			if ( $sauve ) {
				$GLOBALS['wp_filter']['yume_core_deactivate'] = $sauve;
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'rewrite_rules', 'options' );
		yume_assert_false( (bool) preg_grep( '/yume_/', (array) get_option( 'rewrite_rules' ) ), 'aucune règle Yume laissée en base' );
		flush_rewrite_rules( false );

		yume_core_install();
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", YUME_CORE_DB_VERSION_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		yume_assert_true( in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ), 'autoload : ' . $autoload );
	}
);

yume_test(
	'Règles de réécriture : signature enregistrée seulement après le vidage réel (requête arrêtée avant wp_loaded)',
	function () {
		global $wp_actions;
		$options = array( 'yume_core_regles', \Yume\Core\Social\OPTION_REGLES_LISTES, \Yume\Core\Social\OPTION_REGLES_CONTRIBUTEURS );
		foreach ( $options as $option ) {
			delete_option( $option );
		}
		delete_option( 'rewrite_rules' );
		$charge = $wp_actions['wp_loaded'] ?? 0;
		unset( $wp_actions['wp_loaded'] ); // Comme pendant init d'une requête.
		try {
			\Yume\Core\Core\verifier_regles();
			\Yume\Core\Social\verifier_regles_listes();
			\Yume\Core\Social\verifier_regles_contributeurs();
			foreach ( $options as $option ) {
				yume_assert_false( get_option( $option ), "$option : pas de signature avant le vidage" );
			}
			yume_assert_same( 99, has_action( 'wp_loaded', 'Yume\\Core\\Core\\enregistrer_signatures_regles' ) );
		} finally {
			$wp_actions['wp_loaded'] = $charge;
			remove_action( 'wp_loaded', 'Yume\\Core\\Core\\enregistrer_signatures_regles', 99 );
		}
		// La requête suivante (arrivée jusqu'à wp_loaded) vide les règles puis signe.
		\Yume\Core\Core\verifier_regles();
		\Yume\Core\Social\verifier_regles_listes();
		\Yume\Core\Social\verifier_regles_contributeurs();
		foreach ( $options as $option ) {
			yume_assert_true( is_string( get_option( $option ) ), "$option signée" );
		}
		$regles = (array) get_option( 'rewrite_rules' );
		yume_assert_true( isset( $regles['^listes/([a-z][a-z0-9]{11,15})(?:-([^/]*))?/?$'] ), 'règle des listes' );
		yume_assert_true( (bool) preg_grep( '/yume_contributeur/', $regles ), 'règles des contributeurs' );
		yume_assert_true( (bool) preg_grep( '/yume_/', $regles ), 'règles des œuvres' );

		// Règles effacées par un vidage fait sans l'extension (premier chargement après un
		// changement de thème) : signatures inchangées, mais les règles sont réécrites.
		$signatures = array_map( 'get_option', $options );
		update_option( 'rewrite_rules', array_filter( $regles, static fn( $requete ) => ! str_contains( $requete, 'yume_' ) ) );
		\Yume\Core\Core\verifier_regles();
		\Yume\Core\Social\verifier_regles_listes();
		\Yume\Core\Social\verifier_regles_contributeurs();
		$regles = (array) get_option( 'rewrite_rules' );
		yume_assert_true( isset( $regles['^listes/([a-z][a-z0-9]{11,15})(?:-([^/]*))?/?$'] ), 'règle des listes rétablie' );
		yume_assert_true( (bool) preg_grep( '/yume_contributeur/', $regles ), 'règles des contributeurs rétablies' );
		yume_assert_true( (bool) preg_grep( '/yume_route_/', $regles ), 'règles des œuvres rétablies' );
		yume_assert_same( $signatures, array_map( 'get_option', $options ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page « Illustrations » d'un tome (/lire/{oeuvre}/{tome}/illustrations/)
 * -----------------------------------------------------------------------------
 */

/**
 * Illustration de galerie (pièce jointe image avec fichier déclaré, sans fichier réel).
 *
 * @param string $nom Nom du fichier.
 */
function yume_tc_illustration( string $nom = 'planche.jpg' ): int {
	$id = yume_tc_image();
	update_post_meta( $id, '_wp_attached_file', '2026/09/' . $nom );
	return $id;
}

/**
 * Œuvre, tome (numéro improbable) et deux chapitres publiés, sans événements.
 *
 * @param int $numero Numéro du tome.
 * @return array{o:int,t:int,c1:int,c2:int,slug:string}
 */
function yume_tc_tome_illustre( int $numero ): array {
	return yume_tc_sans_evenements(
		static function () use ( $numero ): array {
			$o = yume_tc_oeuvre( 'Brume-Haute ' . $numero );
			$t = yume_tc_tome( $o, $numero );
			return array(
				'o'    => $o,
				't'    => $t,
				'c1'   => yume_tc_chapitre( $t, 1 ),
				'c2'   => yume_tc_chapitre( $t, 2 ),
				'slug' => (string) get_post_field( 'post_name', $o ),
			);
		}
	);
}

yume_test(
	'page Illustrations : route 200 avec une galerie, 404 sans galerie, en flux, paginée ou tome masqué',
	function () {
		flush_rewrite_rules( false );
		$s   = yume_tc_tome_illustre( 7931 );
		$url = home_url( '/lire/' . $s['slug'] . '/tome-7931/illustrations/' );
		$qv  = yume_tc_parse( $url );
		yume_assert_same( '404', $qv['error'] ?? '', 'sans galerie : 404' );
		yume_assert_same( '', yume_url_illustrations( $s['t'] ) );

		// Pièces jointes absentes ou non images : toujours pas de page.
		update_post_meta( $s['t'], 'yume_illustrations', array( 999999, yume_tc_image() ) );
		yume_assert_same( '404', yume_tc_parse( $url )['error'] ?? '', 'galerie sans image valable : 404' );

		$i1 = yume_tc_illustration( 'planche-1.jpg' );
		$i2 = yume_tc_illustration( 'planche-2.jpg' );
		update_post_meta( $s['t'], 'yume_illustrations', array( $i2, $i1, $i2, 999999 ) );
		yume_assert_same( array( $i2, $i1 ), yume_illustrations_tome( $s['t'] ), 'ordre de lecture, sans doublon ni pièce absente' );
		yume_assert_same( $url, yume_url_illustrations( $s['t'] ) );

		$qv = yume_tc_parse( $url );
		yume_assert_same( $s['t'], (int) ( $qv['p'] ?? 0 ), 'résolue vers le tome' );
		yume_assert_same( 'yume_tome', $qv['post_type'] ?? '' );
		yume_assert_same( 1, (int) ( $qv['yume_page_illustrations'] ?? 0 ) );
		yume_assert_true( empty( $qv['yume_non_canonique'] ), 'adresse canonique' );
		$q = new WP_Query( $qv );
		yume_assert_true( $q->is_singular( 'yume_tome' ) );
		yume_assert_false( $q->is_404() );
		yume_assert_same( 1, (int) $q->post_count );
		yume_assert_same( $s['t'], (int) $q->get_queried_object_id() );
		yume_assert_same( $s['t'], url_to_postid( $url ) );

		// Le tome et ses chapitres gardent leurs adresses.
		yume_assert_same( $s['c1'], (int) ( yume_tc_parse( get_permalink( $s['c1'] ) )['p'] ?? 0 ) );
		$qv = yume_tc_parse( get_permalink( $s['t'] ) );
		yume_assert_same( $s['t'], (int) ( $qv['p'] ?? 0 ) );
		yume_assert_true( empty( $qv['yume_page_illustrations'] ), 'page du tome : pas la page Illustrations' );

		// Variante de casse : résolue puis redirigée (non canonique).
		$qv = yume_tc_parse( home_url( '/lire/' . $s['slug'] . '/tome-7931/Illustrations/' ) );
		yume_assert_same( $s['t'], (int) ( $qv['p'] ?? 0 ) );
		yume_assert_same( 1, (int) ( $qv['yume_non_canonique'] ?? 0 ) );

		// Flux, intégration, pagination : jamais la page.
		foreach ( array( 'feed/', 'embed/', '2/', 'comment-page-2/' ) as $suffixe ) {
			yume_assert_same( '404', yume_tc_parse( $url . $suffixe )['error'] ?? '', 'illustrations/' . $suffixe );
		}

		// Tome dépublié, puis œuvre dépubliée (visibilité héritée) : 404.
		wp_update_post(
			array(
				'ID'          => $s['t'],
				'post_status' => 'draft',
			)
		);
		yume_assert_same( '404', yume_tc_parse( $url )['error'] ?? '', 'tome brouillon : 404' );
		wp_update_post(
			array(
				'ID'          => $s['t'],
				'post_status' => 'publish',
			)
		);
		yume_assert_same( $s['t'], (int) ( yume_tc_parse( $url )['p'] ?? 0 ) );
		wp_update_post(
			array(
				'ID'          => $s['o'],
				'post_status' => 'draft',
			)
		);
		yume_assert_same( '404', yume_tc_parse( $url )['error'] ?? '', 'œuvre brouillon : 404' );
	}
);

yume_test(
	'page Illustrations : un vrai chapitre « illustrations » du tome garde l’adresse',
	function () {
		flush_rewrite_rules( false );
		$s = yume_tc_tome_illustre( 7932 );
		update_post_meta( $s['t'], 'yume_illustrations', array( yume_tc_illustration() ) );
		$url = home_url( '/lire/' . $s['slug'] . '/tome-7932/illustrations/' );
		yume_assert_same( $url, yume_url_illustrations( $s['t'] ) );

		$reel = yume_tc_sans_evenements(
			static function () use ( $s ): int {
				return yume_tc_chapitre(
					$s['t'],
					null,
					array(
						'post_title'  => 'Illustrations',
						'post_name'   => 'illustrations',
						'post_status' => 'draft',
						'menu_order'  => -1,
						'meta_input'  => array( 'yume_nature' => 'illustrations' ),
					)
				);
			}
		);
		// Brouillon : l'adresse lui est réservée, aucune page virtuelle (404 pour un visiteur).
		yume_assert_same( '', yume_url_illustrations( $s['t'] ) );
		yume_assert_same( '404', yume_tc_parse( $url )['error'] ?? '' );

		yume_tc_sans_evenements(
			static function () use ( $reel ): void {
				wp_update_post(
					array(
						'ID'          => $reel,
						'post_status' => 'publish',
					)
				);
			}
		);
		yume_assert_same( $url, get_permalink( $reel ) );
		$qv = yume_tc_parse( $url );
		yume_assert_same( $reel, (int) ( $qv['p'] ?? 0 ), 'le chapitre réel l’emporte' );
		yume_assert_same( 'yume_chapitre', $qv['post_type'] ?? '' );
		yume_assert_true( empty( $qv['yume_page_illustrations'] ) );
		yume_assert_same( '', yume_url_illustrations_avant( $s['c1'] ), 'pas de page virtuelle avant le chapitre 1' );
	}
);

yume_test(
	'page Illustrations : titre, adresse canonique, noindex, absente du plan du site',
	function () {
		global $wp_query, $wp_the_query;
		flush_rewrite_rules( false );
		$s = yume_tc_tome_illustre( 7933 );
		update_post_meta( $s['t'], 'yume_illustrations', array( yume_tc_illustration() ) );
		$url = yume_url_illustrations( $s['t'] );

		$avant_q   = $wp_query;
		$avant_the = $wp_the_query;
		try {
			// phpcs:disable WordPress.WP.GlobalVariablesOverride -- requête principale simulée puis restaurée.
			$wp_query     = new WP_Query( yume_tc_parse( $url ) );
			$wp_the_query = $wp_query;
			// phpcs:enable
			yume_assert_true( yume_est_page_illustrations() );
			yume_assert_same( $url, wp_get_canonical_url( $s['t'] ) );
			$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
			yume_assert_true( ! empty( $robots['noindex'] ) && ! empty( $robots['follow'] ), 'noindex, follow' );
			$titre = apply_filters( 'document_title_parts', array( 'title' => 'x' ) );
			yume_assert_same( 'Illustrations · ' . get_the_title( $s['t'] ), $titre['title'] );
			yume_assert_same( '', wp_get_shortlink( $s['t'] ) );
			yume_assert_same( '', yume_url_illustrations_avant( $s['c2'] ), 'seul le premier chapitre est précédé des illustrations' );
			yume_assert_same( $url, yume_url_illustrations_avant( $s['c1'] ) );

			// Page du tome : rien ne change.
			// phpcs:disable WordPress.WP.GlobalVariablesOverride
			$wp_query     = new WP_Query( yume_tc_parse( get_permalink( $s['t'] ) ) );
			$wp_the_query = $wp_query;
			// phpcs:enable
			yume_assert_false( yume_est_page_illustrations() );
			yume_assert_same( get_permalink( $s['t'] ), wp_get_canonical_url( $s['t'] ) );
			yume_assert_true( empty( apply_filters( 'wp_robots', array() )['noindex'] ) );
		} finally {
			// phpcs:disable WordPress.WP.GlobalVariablesOverride
			$wp_query     = $avant_q;
			$wp_the_query = $avant_the;
			// phpcs:enable
		}

		// Plan du site : les URL des tomes seulement (aucune page virtuelle).
		$fournisseur = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
		$adresses    = $fournisseur ? wp_list_pluck( $fournisseur->get_url_list( 1, 'yume_tome' ), 'loc' ) : array();
		yume_assert_true( in_array( get_permalink( $s['t'] ), $adresses, true ), 'tome dans le plan du site' );
		yume_assert_false( in_array( $url, $adresses, true ), 'page Illustrations absente du plan du site' );
	}
);

yume_test(
	'Sous-pages d’œuvre (yume_sous_pages_oeuvre) : /oeuvres/{o}/{onglet}/ avant les tomes, slug de tome réservé, gabarit dédié',
	function () {
		global $wp_rewrite;
		$ajout = static fn( array $slugs ): array => array_merge( $slugs, array( 'onglet-essai', '123', 'feed' ) );
		add_filter( 'yume_sous_pages_oeuvre', $ajout );
		try {
			// Les modules déclarent aussi les leurs (actualites, glossaire…) : seul l'ajout du test compte.
			$onglets = \Yume\Core\Core\onglets_oeuvre();
			yume_assert_true( in_array( 'onglet-essai', $onglets, true ) );
			yume_assert_same( array(), array_values( array_intersect( array( '123', 'feed' ), $onglets ) ), 'slugs numériques et réservés écartés' );
			// En production le filtre est posé avant init ; ici les règles Yume sont remises en tête.
			$wp_rewrite->extra_rules_top = array_merge( \Yume\Core\Core\regles_reecriture(), array_diff_key( $wp_rewrite->extra_rules_top, \Yume\Core\Core\regles_reecriture() ) );
			$wp_rewrite->flush_rules( false );

			$oeuvre = yume_tc_oeuvre( 'Onglets' );
			$slug   = get_post_field( 'post_name', $oeuvre );
			yume_tc_tome( $oeuvre, 1 );

			$requete = yume_tc_requete( home_url( '/oeuvres/' . $slug . '/onglet-essai/' ) );
			yume_assert_true( $requete->is_singular( 'yume_oeuvre' ), 'sous-page = fiche de l’œuvre' );
			yume_assert_same( $oeuvre, (int) $requete->get_queried_object_id() );
			yume_assert_same( 'onglet-essai', $requete->get( 'yume_onglet' ) );

			// Les tomes gardent leur adresse.
			$tome = yume_tc_requete( home_url( '/oeuvres/' . $slug . '/tome-1/' ) );
			yume_assert_true( $tome->is_singular( 'yume_tome' ), 'tome' );

			yume_assert_same( 'onglet-essai-2', \Yume\Core\Core\slug_autorise( 'onglet-essai', 'yume_tome' ) );
			yume_assert_same( 'onglet-essai', \Yume\Core\Core\slug_autorise( 'onglet-essai', 'yume_chapitre' ) );
			yume_assert_same( trailingslashit( (string) get_permalink( $oeuvre ) ) . 'onglet-essai/', \Yume\Core\Core\url_onglet_oeuvre( $oeuvre, 'onglet-essai' ) );
			yume_assert_same( '', \Yume\Core\Core\url_onglet_oeuvre( $oeuvre, 'inconnu' ) );

			$GLOBALS['wp_query'] = $requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			yume_assert_same( 'onglet-essai', \Yume\Core\Core\onglet_oeuvre() );
			yume_assert_same( array( 'single-yume_oeuvre-onglet-essai.php', 'single.php' ), \Yume\Core\Core\gabarit_onglet_oeuvre( array( 'single.php' ) ) );
		} finally {
			remove_filter( 'yume_sous_pages_oeuvre', $ajout );
			foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $motif ) {
				if ( str_contains( $motif, 'onglet' ) ) {
					unset( $wp_rewrite->extra_rules_top[ $motif ] );
				}
			}
			$wp_rewrite->extra_rules_top = array_merge( \Yume\Core\Core\regles_reecriture(), array_diff_key( $wp_rewrite->extra_rules_top, \Yume\Core\Core\regles_reecriture() ) );
			$wp_rewrite->flush_rules( false );
			$GLOBALS['wp_query'] = $GLOBALS['wp_the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
);
