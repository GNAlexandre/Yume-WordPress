<?php
/**
 * Tests de l'espace équipe (façade) : navigation partagée (vues, « Publier », déconnexion),
 * vue « Planning complet » (?vue=planning : tous les tomes vivants, filtres, formulaire par
 * ligne, raccourcis, « Retirer du planning », erreur affichée sur la ligne), raccourcis de
 * « Mes tâches » et de « Tomes en préparation », vue « Journal » (?vue=journal : pagination, filtre
 * par tome), vue « Réglages » (?vue=reglages : champs selon les capacités, enregistrement par
 * admin-post.php avec l'assainissement de la page d'administration), avertissements de la page
 * « Membres et rôles » et passerelle du planning public.
 *
 * Lancement : tools/localenv/test.sh equipe
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\grouper_journal;
use function Yume\Core\Planning\journaliser;
use function Yume\Core\Planning\lire_journal;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\retour_formulaire;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\table_notifications;
use function Yume\Core\Planning\traiter_formulaire_maj;
use function Yume\Core\Planning\traiter_formulaire_reglages;
use function Yume\Core\Planning\traiter_formulaire_retrait;
use function Yume\Core\Planning\url_vue_equipe;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_te_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé des contenus existants (œuvres, tomes, chapitres, journal, file
 * d'e-mails vidés dans la transaction du test, annulée ensuite par le lanceur).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_te_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_notifications() ); // phpcs:ignore
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps();
			} finally {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Crée un membre avec un pseudo.
 *
 * @param string $role Rôle.
 * @param string $nom  Pseudo.
 */
function yume_te_membre( string $role, string $nom ): int {
	$id = yume_factory_user( $role );
	wp_update_user(
		array(
			'ID'           => $id,
			'display_name' => $nom,
		)
	);
	return $id;
}

/**
 * Crée une œuvre publiée.
 *
 * @param string $titre Titre.
 */
function yume_te_oeuvre( string $titre ): int {
	$id = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => $titre,
		)
	);
	wp_set_object_terms( $id, 'light-novel', 'yume_type' );
	return $id;
}

/**
 * Crée un tome (sans notification).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $numero    Numéro.
 * @param array  $meta      Méta de planning.
 * @param string $statut    Statut.
 */
function yume_te_tome( int $oeuvre_id, int $numero, array $meta = array(), string $statut = 'draft' ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		$args = array(
			'post_type'   => 'yume_tome',
			'post_title'  => get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
			'post_status' => $statut,
			'meta_input'  => array_merge(
				array(
					'yume_oeuvre_id'    => $oeuvre_id,
					'yume_numero'       => $numero,
					'yume_nature'       => 'tome',
					'yume_etape'        => 'traduction',
					'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
				),
				$meta
			),
		);
		if ( 'future' === $statut ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}
		return yume_factory_post( $args );
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Jeu de données : Calumi (traducteur), Pizz (éditeur), Hikari (gérant), un administrateur ;
 * Grimgar T.10 (brouillon, relecture), T.11 (programmé), T.9 (publié il y a longtemps) ;
 * Raven T.3 (brouillon, bloqué).
 *
 * @return array<string,int>
 */
function yume_te_jeu(): array {
	$d            = array();
	$d['calumi']  = yume_te_membre( 'yume_traducteur', 'Calumi' );
	$d['editeur'] = yume_te_membre( 'yume_editeur', 'Pizz' );
	$d['gerant']  = yume_te_membre( 'yume_gerant', 'Hikari' );
	$d['admin']   = yume_te_membre( 'administrator', 'Proprio' );
	$d['lecteur'] = yume_te_membre( 'subscriber', 'Kaede' );
	$d['grimgar'] = yume_te_oeuvre( 'Grimgar' );
	$d['raven']   = yume_te_oeuvre( 'Raven' );
	$d['t10']     = yume_te_tome(
		$d['grimgar'],
		10,
		array(
			'yume_etape'        => 'traduction',
			'yume_avancement'   => array(
				'traduction' => 70,
				'relecture'  => 0,
				'edition'    => 0,
			),
			'yume_responsables' => array(
				'traduction' => $d['calumi'],
				'relecture'  => 0,
				'edition'    => 0,
			),
		)
	);
	$d['t11']     = yume_te_tome( $d['grimgar'], 11, array(), 'future' );
	$d['t9']      = yume_te_tome(
		$d['grimgar'],
		9,
		array(
			'yume_etape'        => 'publie',
			'yume_avancement'   => array(
				'traduction' => 100,
				'relecture'  => 100,
				'edition'    => 100,
			),
			'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ),
		),
		'publish'
	);
	$d['raven3']  = yume_te_tome(
		$d['raven'],
		3,
		array(
			'yume_bloque'        => true,
			'yume_bloque_raison' => 'relecteur manquant',
		)
	);
	return $d;
}

/**
 * Rend l'espace équipe avec des paramètres GET, en tant qu'utilisateur.
 *
 * @param int   $user_id Utilisateur.
 * @param array $get     Paramètres GET.
 */
function yume_te_rendu( int $user_id, array $get = array() ): string {
	$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return yume_render_block( 'yume/team-dashboard' );
}

/**
 * Libellés et cibles des entrées de la navigation de l'espace équipe.
 *
 * @param string $html HTML.
 * @return array<int,array{0:string,1:string,2:string}> libellé, href, attributs.
 */
function yume_te_nav( string $html ): array {
	preg_match( '#<nav class="yn-team__nav".*?</nav>#s', $html, $m );
	preg_match_all( '#<li><a href="([^"]*)"([^>]*)>([^<]*)#', $m[0] ?? '', $liens, PREG_SET_ORDER );
	return array_map(
		static fn( $l ) => array( html_entity_decode( trim( $l[3] ), ENT_QUOTES, 'UTF-8' ), html_entity_decode( $l[1], ENT_QUOTES, 'UTF-8' ), $l[2] ),
		$liens
	);
}

/*
 * -----------------------------------------------------------------------------
 * Navigation
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'navigation : « Planning complet » et « Journal » mènent aux vues de l’espace équipe, entrée active, déconnexion',
	function () {
		$admin = yume_te_membre( 'administrator', 'Proprio' );
		wp_set_current_user( $admin );
		// Les vues ajoutées par le filtre yume_vues_equipe (« Indicateurs »…) sont placées juste
		// avant « Réglages » ; elles sont écartées ici pour vérifier les entrées de base.
		$ajoutees = array_column( \Yume\Core\Planning\vues_equipe_ajoutees(), 'libelle' );
		$de_base  = static function ( array $entrees ) use ( $ajoutees ): array {
			return array_values(
				array_filter(
					$entrees,
					static function ( $e ) use ( $ajoutees ) {
						return ! in_array( $e[0], $ajoutees, true );
					}
				)
			);
		};
		$nav      = navigation_equipe( 'tableau', 2 );
		$toutes   = array_column( yume_te_nav( $nav ), 0 );
		$du_site  = array_column(
			array_filter(
				\Yume\Core\Planning\vues_equipe_ajoutees(),
				static fn( $v ) => 'site' === $v['groupe']
			),
			'libelle'
		);
		yume_assert_same( array_merge( $du_site, array( 'Réglages' ) ), array_slice( $toutes, -1 - count( $du_site ) ), 'vues du menu « Site » avant « Réglages »' );
		// Menus repliables : Catalogue, Planning, Équipe, Site ; aucun ouvert sur le tableau de bord.
		yume_assert_same( 4, substr_count( $nav, '<li class="yn-team__groupe"><details>' ) );
		foreach ( array( 'Catalogue', 'Planning', 'Équipe', 'Site' ) as $menu ) {
			yume_assert_contains( '<summary>' . $menu . '</summary>', $nav );
		}
		yume_assert_contains( '<details open><summary>Catalogue</summary>', navigation_equipe( 'tomes' ), 'menu de la page affichée ouvert' );
		$entrees  = $de_base( yume_te_nav( $nav ) );
		$libelles = array_column( $entrees, 0 );
		yume_assert_same( array( 'Tableau de bord', 'Mes tâches', 'Œuvres', 'Tous les tomes', 'Ajouter des chapitres', 'Lecture à compléter', 'Planning complet', 'Journal', 'Membres et rôles', 'Réglages' ), $libelles );
		yume_assert_same( url_vue_equipe( 'reglages' ), $entrees[9][1], 'réglages dans l’espace équipe' );
		yume_assert_same( url_vue_equipe( 'lecture' ), $entrees[5][1], 'lecture à compléter dans l’espace équipe' );
		yume_assert_not_contains( 'page=yume-reglages', $nav, 'plus la page de l’administration' );
		yume_assert_same( url_vue_equipe( 'oeuvres' ), $entrees[2][1], 'œuvres dans l’espace équipe' );
		yume_assert_same( url_vue_equipe( 'planning' ), $entrees[6][1] );
		yume_assert_same( url_vue_equipe( 'journal' ), $entrees[7][1] );
		yume_assert_contains( 'vue=planning', $entrees[6][1] );
		yume_assert_true( yume_url_page( 'planning' ) !== $entrees[6][1], 'plus le planning public' );
		yume_assert_same( '#yn-team', $entrees[0][1], 'ancres sur le tableau de bord' );
		yume_assert_same( ' aria-current="true"', $entrees[0][2] );
		yume_assert_contains( '2 en retard', $nav, 'signature historique conservée' );
		yume_assert_contains( 'Se déconnecter', $nav );
		yume_assert_contains( esc_url( wp_logout_url( home_url( '/' ) ) ), $nav );

		foreach ( array(
			'planning' => 6,
			'journal'  => 7,
			'publier'  => 4,
			'lecture'  => 5,
			'oeuvres'  => 2,
			'tomes'    => 3,
			'membres'  => 8,
			'reglages' => 9,
			'taches'   => 1,
		) as $cle => $index ) {
			$html    = navigation_equipe( $cle );
			$entrees = $de_base( yume_te_nav( $html ) );
			yume_assert_same( ' aria-current="page"', $entrees[ $index ][2], $cle );
			yume_assert_same( 1, substr_count( $html, 'aria-current' ), $cle . ' : une seule entrée active' );
			yume_assert_same( url_vue_equipe(), $entrees[0][1], $cle . ' : adresse complète du tableau de bord' );
			yume_assert_same( url_vue_equipe( 'taches' ), $entrees[1][1], '« Mes tâches » : vue ?vue=taches' );
		}

		// Traducteur : ni publier, ni tous les tomes, ni membres ; les vues restent proposées.
		wp_set_current_user( yume_te_membre( 'yume_traducteur', 'Calumi' ) );
		yume_assert_same( array( 'Tableau de bord', 'Mes tâches', 'Planning complet', 'Journal' ), array_column( yume_te_nav( navigation_equipe( 'tableau' ) ), 0 ) );
	}
);

yume_te_test(
	'url_vue_equipe : paramètres vides ignorés, page équipe sans vue',
	function () {
		$base = yume_url_page( 'equipe' );
		yume_assert_same( $base, url_vue_equipe() );
		yume_assert_same( add_query_arg( 'vue', 'journal', $base ), url_vue_equipe( 'journal', array( 'tome' => 0 ) ) );
		yume_assert_same(
			add_query_arg(
				array(
					'vue'  => 'planning',
					'tome' => 12,
				),
				$base
			),
			url_vue_equipe( 'planning', array( 'tome' => 12 ) )
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Planning complet
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'planning complet (administrateur) : tous les tomes vivants, raccourcis, retrait des seuls brouillons',
	function () {
		$d    = yume_te_jeu();
		$html = yume_te_rendu( $d['admin'], array( 'vue' => 'planning' ) );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Planning complet</h2>', $html );
		yume_assert_contains( 'Voir le planning public', $html );
		foreach ( array( 't10', 't11', 't9', 'raven3' ) as $cle ) {
			yume_assert_contains( 'id="yn-tome-' . $d[ $cle ] . '"', $html, $cle . ' listé' );
		}
		yume_assert_contains( '4 tomes', $html );
		yume_assert_contains( 'Programmé le', $html, 'statut programmé affiché' );
		yume_assert_not_contains( 'id="yn-mes-taches"', $html, 'pas le tableau de bord' );
		yume_assert_contains( 'data-yn-rest=', $html, 'formulaires enregistrés en JavaScript' );

		// Formulaire complet par ligne.
		yume_assert_contains( 'name="responsables[relecture]"', $html );
		yume_assert_contains( 'id="g' . $d['t10'] . '-etape"', $html );
		yume_assert_contains( 'name="bloque_present"', $html );

		// Raccourcis.
		yume_assert_contains( esc_url( add_query_arg( 'tome', $d['t10'], yume_url_page( 'publier' ) ) ), $html, 'Publier ce tome' );
		yume_assert_not_contains( esc_url( add_query_arg( 'tome', $d['t9'], yume_url_page( 'publier' ) ) ), $html, 'tome déjà publié' );
		yume_assert_contains( esc_url( get_edit_post_link( $d['t10'], 'raw' ) ), $html, 'Modifier dans l’administration' );
		yume_assert_contains( esc_url( get_permalink( $d['t9'] ) ) . '">Voir la fiche', $html );
		yume_assert_same( 1, substr_count( $html, '>Voir la fiche' ), 'fiche : tomes publiés seulement' );
		yume_assert_contains( esc_url( url_vue_equipe( 'journal', array( 'tome' => $d['t10'] ) ) ), $html, 'historique' );

		// Retirer du planning : brouillons seulement, nonce, confirmation.
		yume_assert_contains( 'name="action" value="yume_planning_retrait"', $html );
		yume_assert_same( 2, substr_count( $html, 'value="yume_planning_retrait"' ), 'T.10 et Raven T.3' );
		yume_assert_contains( 'name="tome_id" value="' . $d['raven3'] . '"', $html );
		yume_assert_contains( 'data-yn-confirmer="Retirer', $html );
	}
);

yume_te_test(
	'planning complet : filtres œuvre, statut (publiés, programmés), état, responsable ; lien direct vers un tome',
	function () {
		$d     = yume_te_jeu();
		$ids   = static function ( string $html ): array {
			preg_match_all( '#<details class="yn-team__details" id="yn-tome-(\d+)"#', $html, $m );
			$ids = array_map( 'intval', $m[1] );
			sort( $ids );
			return $ids;
		};
		$trier = static function ( array $ids ): array {
			sort( $ids );
			return $ids;
		};
		yume_assert_same(
			$trier( array( $d['t10'], $d['t11'], $d['t9'] ) ),
			$ids(
				yume_te_rendu(
					$d['gerant'],
					array(
						'vue'    => 'planning',
						'oeuvre' => (string) $d['grimgar'],
					)
				)
			)
		);
		yume_assert_same(
			array( $d['t9'] ),
			$ids(
				yume_te_rendu(
					$d['gerant'],
					array(
						'vue'    => 'planning',
						'statut' => 'publish',
					)
				)
			)
		);
		yume_assert_same(
			array( $d['t11'] ),
			$ids(
				yume_te_rendu(
					$d['gerant'],
					array(
						'vue'    => 'planning',
						'statut' => 'future',
					)
				)
			)
		);
		yume_assert_same(
			array( $d['raven3'] ),
			$ids(
				yume_te_rendu(
					$d['gerant'],
					array(
						'vue'  => 'planning',
						'etat' => 'bloque',
					)
				)
			)
		);
		yume_assert_same(
			array( $d['t10'] ),
			$ids(
				yume_te_rendu(
					$d['gerant'],
					array(
						'vue'         => 'planning',
						'responsable' => (string) $d['calumi'],
					)
				)
			)
		);
		$html = yume_te_rendu(
			$d['gerant'],
			array(
				'vue'    => 'planning',
				'statut' => 'nimporte',
			)
		);
		yume_assert_same( 4, count( $ids( $html ) ), 'statut inconnu ignoré' );

		$html = yume_te_rendu(
			$d['gerant'],
			array(
				'vue'  => 'planning',
				'tome' => (string) $d['raven3'],
			)
		);
		yume_assert_same( array( $d['raven3'] ), $ids( $html ) );
		yume_assert_contains( 'id="yn-tome-' . $d['raven3'] . '" open', $html, 'ligne dépliée' );
		yume_assert_contains( 'Afficher tout le planning', $html );

		// Formulaire de filtres : GET vers la page équipe, vue conservée.
		yume_assert_contains( 'method="get"', $html );
		yume_assert_contains( '<input type="hidden" name="vue" value="planning">', $html );
		yume_assert_contains( 'name="statut"', $html );
		yume_assert_contains( '<option value="publish">Publié</option>', $html );
	}
);

yume_te_test(
	'planning complet (traducteur) : formulaire de ses étapes seulement, lecture seule ailleurs, jamais de retrait',
	function () {
		$d    = yume_te_jeu();
		$html = yume_te_rendu( $d['calumi'], array( 'vue' => 'planning' ) );
		yume_assert_contains( 'id="yn-tome-' . $d['t10'] . '"', $html );
		yume_assert_contains( 'data-yn-planning="' . $d['t10'] . '"', $html, 'son tome : formulaire' );
		yume_assert_not_contains( 'data-yn-planning="' . $d['raven3'] . '"', $html, 'tome d’un autre : lecture seule' );
		yume_assert_contains( 'Vous n’êtes pas responsable de ce tome', $html );
		yume_assert_not_contains( 'name="responsables[', $html );
		yume_assert_not_contains( 'yume_planning_retrait', $html );
		yume_assert_not_contains( 'Publier ce tome', $html );
		yume_assert_contains( 'Historique', $html );
	}
);

yume_te_test(
	'planning complet : l’erreur du service (étape prématurée) s’affiche sur la ligne, dépliée',
	function () {
		$d = yume_te_jeu();
		wp_set_current_user( $d['editeur'] );
		$retour = traiter_formulaire_maj(
			array(
				'tome_id'     => (string) $d['t10'],
				'ancre'       => 'yn-tome-' . $d['t10'],
				'_yume_nonce' => wp_create_nonce( 'yume_planning_maj_' . $d['t10'] ),
				'etape'       => 'relecture',
			),
			$d['editeur']
		);
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_same( 'yn-tome-' . $d['t10'], $retour['cible'] );
		retour_formulaire( $d['editeur'], $retour );
		$html = yume_te_rendu( $d['editeur'], array( 'vue' => 'planning' ) );
		yume_assert_contains( 'id="yn-tome-' . $d['t10'] . '" open', $html );
		yume_assert_true( 1 === preg_match( '#id="yn-tome-' . $d['t10'] . '" open>.*?<p class="yn-team__retour yn-team__retour--erreur"[^>]*>' . preg_quote( esc_html( $retour['message'] ), '#' ) . '</p>#s', $html ), 'message dans la ligne' );
		yume_assert_contains( 'Terminez d’abord', $retour['message'] );
		yume_assert_same( 'traduction', get_post_meta( $d['t10'], 'yume_etape', true ) );
	}
);

yume_te_test(
	'planning complet : les étapes proposées sont celles permises à l’utilisateur',
	function () {
		$d = yume_te_jeu();
		preg_match( '#<select id="g' . $d['t10'] . '-etape"[^>]*>(.*?)</select>#s', yume_te_rendu( $d['admin'], array( 'vue' => 'planning' ) ), $m );
		yume_assert_true( ! empty( $m[1] ) );
		foreach ( array( 'a_faire', 'traduction', 'relecture', 'edition' ) as $etape ) {
			yume_assert_contains( 'value="' . $etape . '"', $m[1], $etape );
		}
		yume_assert_not_contains( 'value="publie"', $m[1], 'jamais « publié » pour un tome non publié' );
	}
);

yume_te_test(
	'Retirer du planning : brouillon à la corbeille, refus expliqué (publié, traducteur, nonce)',
	function () {
		$d    = yume_te_jeu();
		$post = static function ( int $tome ): array {
			return array(
				'action'      => 'yume_planning_retrait',
				'tome_id'     => (string) $tome,
				'_yume_nonce' => wp_create_nonce( 'yume_planning_retrait_' . $tome ),
			);
		};

		wp_set_current_user( $d['calumi'] );
		$r = traiter_formulaire_retrait( $post( $d['t10'] ), $d['calumi'] );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( 'draft', get_post_status( $d['t10'] ) );

		wp_set_current_user( $d['editeur'] );
		$r = traiter_formulaire_retrait( array_merge( $post( $d['t10'] ), array( '_yume_nonce' => 'x' ) ), $d['editeur'] );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_contains( 'session', $r['message'] );

		$r = traiter_formulaire_retrait( $post( $d['t9'] ), $d['editeur'] );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( 'yn-tome-' . $d['t9'], $r['cible'], 'erreur affichée sur la ligne' );
		yume_assert_same( 'publish', get_post_status( $d['t9'] ) );

		$r = traiter_formulaire_retrait( $post( $d['raven3'] ), $d['editeur'] );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 'yn-gestion-retour', $r['cible'] );
		yume_assert_contains( 'Raven', $r['message'] );
		yume_assert_same( 'trash', get_post_status( $d['raven3'] ) );

		// Le message s'affiche en tête de la vue ; le tome n'est plus listé ; le journal le dit.
		retour_formulaire( $d['editeur'], $r );
		$html = yume_te_rendu( $d['editeur'], array( 'vue' => 'planning' ) );
		yume_assert_true( 1 === preg_match( '#<div id="yn-gestion-retour"><p class="yn-team__retour yn-team__retour--ok"[^>]*>[^<]*Raven#', $html ) );
		yume_assert_not_contains( 'id="yn-tome-' . $d['raven3'] . '"', $html );
		$journal = grouper_journal( lire_journal( array( 'tome_id' => $d['raven3'] ) ), true );
		yume_assert_contains( 'retiré du planning', implode( ' ', $journal[0]['parties'] ?? array() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Tableau de bord : raccourcis, journal
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'tableau de bord : raccourcis sur « Mes tâches » et « Tomes en préparation », liens vers les vues',
	function () {
		$d = yume_te_jeu();
		update_post_meta(
			$d['t10'],
			'yume_responsables',
			array(
				'traduction' => $d['editeur'],
				'relecture'  => 0,
				'edition'    => 0,
			)
		);
		$html = yume_te_rendu( $d['editeur'] );
		preg_match( '#<form class="yn-card yn-team__tache[^"]*" id="yn-tache-' . $d['t10'] . '".*?</form>#s', $html, $carte );
		yume_assert_true( ! empty( $carte[0] ), 'carte de tâche' );
		yume_assert_contains( 'Publier ce tome', $carte[0] );
		yume_assert_contains( 'Modifier dans l’administration', $carte[0] );
		yume_assert_contains( 'Gérer dans le planning complet', $carte[0] );
		yume_assert_not_contains( '<form', substr( $carte[0], 5 ), 'pas de formulaire imbriqué' );
		yume_assert_contains( 'id="yn-tous-les-tomes"', $html );
		yume_assert_contains( '<h2 id="yn-tous-titre">Tomes en préparation</h2>', $html, 'section du planning à venir (la vue « Tous les tomes » liste tout le catalogue)' );
		yume_assert_contains( 'value="yume_planning_retrait"', $html, 'retrait depuis « Tomes en préparation »' );
		yume_assert_contains( esc_url( url_vue_equipe( 'planning' ) ), $html );
		yume_assert_contains( esc_url( url_vue_equipe( 'journal' ) ) . '">Tout le journal', $html );

		// Traducteur : raccourcis sans publication ni retrait.
		update_post_meta(
			$d['t10'],
			'yume_responsables',
			array(
				'traduction' => $d['calumi'],
				'relecture'  => 0,
				'edition'    => 0,
			)
		);
		$html = yume_te_rendu( $d['calumi'] );
		yume_assert_contains( 'id="yn-tache-' . $d['t10'] . '"', $html );
		yume_assert_contains( 'Historique', $html );
		yume_assert_not_contains( 'Publier ce tome', $html );
		yume_assert_not_contains( 'yume_planning_retrait', $html );
	}
);

yume_te_test(
	'vue Journal : tout le journal paginé, filtre par tome, rappels en option, réservé à l’équipe',
	function () {
		$d = yume_te_jeu();
		for ( $i = 0; $i < 70; $i++ ) {
			journaliser( $d['t10'], $d['calumi'], 'date_cible', '', gmdate( 'Y-m-d', time() + ( $i + 1 ) * DAY_IN_SECONDS ) );
		}
		journaliser( $d['raven3'], $d['gerant'], 'bloque', '0', '1' );
		journaliser(
			$d['raven3'],
			0,
			'rappel',
			'',
			array(
				'destinataires' => array( $d['calumi'] ),
				'motif'         => 'date',
				'jours'         => 3,
			)
		);
		global $wpdb;
		// Horodatages distincts : les lignes ne sont pas regroupées en une seule mise à jour.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table_journal() . ' SET created_at = DATE_SUB( %s, INTERVAL id MINUTE )', gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		if ( $wpdb->last_error ) {
			// SQLite : pas de DATE_SUB.
			$wpdb->query( 'UPDATE ' . table_journal() . " SET created_at = datetime( 'now', '-' || id || ' minutes' )" ); // phpcs:ignore
		}

		$html = yume_te_rendu( $d['calumi'], array( 'vue' => 'journal' ) );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Journal de l’équipe</h2>', $html );
		yume_assert_contains( 'rel="next"', $html );
		yume_assert_not_contains( 'rel="prev"', $html );
		yume_assert_true( substr_count( $html, '<li><time' ) >= 50, 'une page pleine' );
		yume_assert_not_contains( 'rappel envoyé', $html, 'rappels exclus par défaut' );
		yume_assert_contains( esc_url( url_vue_equipe( 'journal', array( 'tome' => $d['t10'] ) ) ), $html, 'tome cliquable' );

		$html = yume_te_rendu(
			$d['calumi'],
			array(
				'vue' => 'journal',
				'pg'  => '2',
			)
		);
		yume_assert_contains( 'rel="prev"', $html );
		yume_assert_not_contains( 'rel="next"', $html );

		$html = yume_te_rendu(
			$d['calumi'],
			array(
				'vue'     => 'journal',
				'tome'    => (string) $d['raven3'],
				'rappels' => '1',
			)
		);
		yume_assert_contains( 'bloqué', $html );
		yume_assert_contains( 'rappel envoyé à Calumi', $html );
		yume_assert_not_contains( 'date cible :', $html, 'autres tomes exclus' );
		yume_assert_contains( '<option value="' . $d['raven3'] . '" selected=\'selected\'>', $html );

		// Hors équipe : message d'accès, pas de journal.
		$html = yume_te_rendu( $d['lecteur'], array( 'vue' => 'journal' ) );
		yume_assert_contains( 'Espace réservé à l’équipe', $html );
		yume_assert_not_contains( 'yn-team__journal', $html );
	}
);

yume_te_test(
	'journal : textes « retiré du planning » et « étape forcée » (équipe seulement)',
	function () {
		$d = yume_te_jeu();
		journaliser( $d['t10'], $d['admin'], 'retire', '', 'Grimgar T.10' );
		journaliser(
			$d['t11'],
			$d['admin'],
			'etape_forcee',
			'traduction',
			array(
				'etape'      => 'relecture',
				'avancement' => array(
					'traduction' => 70,
					'relecture'  => 0,
					'edition'    => 0,
				),
			),
			false
		);
		$champs = array( 'champs' => array( 'etape_forcee' ) );
		$equipe = grouper_journal( lire_journal( array( 'tome_id' => $d['t11'] ) + $champs ), true );
		yume_assert_same( array( 'étape forcée : relecture (traduction ' . \Yume\Core\Planning\pct( 70 ) . ')' ), $equipe[0]['parties'] );
		yume_assert_same( array(), grouper_journal( lire_journal( array( 'tome_id' => $d['t11'] ) + $champs ), false ), 'jamais public' );
		yume_assert_same(
			array(),
			lire_journal(
				array(
					'tome_id' => $d['t11'],
					'public'  => true,
				) + $champs
			)
		);
		$retire = grouper_journal( lire_journal( array( 'tome_id' => $d['t10'] ) ), false );
		yume_assert_same( array( 'retiré du planning' ), $retire[0]['parties'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Membres et rôles
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'Membres et rôles : membre responsable de tomes en cours signalé (nombre, tomes, lien filtré), lien administration',
	function () {
		$d       = yume_te_jeu();
		$equipe  = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_name'  => 'equipe',
				'post_title' => 'Espace équipe',
			)
		);
		$membres = yume_factory_post(
			array(
				'post_type'    => 'page',
				'post_name'    => 'membres',
				'post_title'   => 'Membres et rôles',
				'post_parent'  => $equipe,
				'post_content' => '<!-- wp:yume/team-members /-->',
			)
		);
		update_option(
			'yume_pages',
			array(
				'equipe'  => $equipe,
				'membres' => $membres,
			)
		);
		// Calumi : traduction de T.10 et édition de Raven T.3 (en cours) ; T.9 publié ignoré.
		update_post_meta(
			$d['raven3'],
			'yume_responsables',
			array(
				'traduction' => 0,
				'relecture'  => 0,
				'edition'    => $d['calumi'],
			)
		);
		update_post_meta(
			$d['t9'],
			'yume_responsables',
			array(
				'traduction' => $d['calumi'],
				'relecture'  => 0,
				'edition'    => 0,
			)
		);

		wp_set_current_user( $d['gerant'] );
		$html = yume_render_block( 'yume/team-members' );
		preg_match( '#<li class="yn-team__ligne yn-team__membre" id="yn-membre-' . $d['calumi'] . '">.*?</li>#s', $html, $ligne );
		yume_assert_true( ! empty( $ligne[0] ) );
		yume_assert_contains( 'Calumi est responsable de 2 tomes en cours', $ligne[0] );
		yume_assert_contains( 'Grimgar Tome 10', $ligne[0] );
		yume_assert_contains( esc_url( url_vue_equipe( 'planning', array( 'responsable' => $d['calumi'] ) ) ), $ligne[0] );
		yume_assert_contains( 'data-yn-confirmer="Retirer Calumi de l’équipe ? Il reste responsable de 2 tomes en cours."', $ligne[0] );
		yume_assert_contains( 'value="yume_equipe_membres"', $ligne[0], 'le retrait reste possible' );
		preg_match( '#<li class="yn-team__ligne yn-team__membre" id="yn-membre-' . $d['editeur'] . '">.*?</li>#s', $html, $autre );
		yume_assert_not_contains( 'yn-team__membre-alerte', $autre[0] ?? '', 'aucun tome : aucun avertissement' );
		yume_assert_not_contains( 'user-edit.php', $html, 'gérant : pas de lien administration' );

		// Administrateur : lien vers user-edit.php sur les comptes non modifiables ici.
		wp_set_current_user( $d['admin'] );
		$html = yume_render_block( 'yume/team-members' );
		preg_match( '#<li class="yn-team__ligne yn-team__membre" id="yn-membre-' . $d['gerant'] . '">.*?</li>#s', $html, $g );
		yume_assert_contains( esc_url( admin_url( 'user-edit.php?user_id=' . $d['gerant'] ) ), $g[0] ?? '' );
		preg_match( '#<li class="yn-team__ligne yn-team__membre" id="yn-membre-' . $d['calumi'] . '">.*?</li>#s', $html, $c );
		yume_assert_not_contains( 'user-edit.php', $c[0] ?? '', 'compte modifiable : formulaire, pas de lien' );

		// Après retrait : le message rappelle les tomes à réattribuer.
		$r = \Yume\Core\Planning\traiter_formulaire_membres(
			array(
				'op'          => 'retrait',
				'user_id'     => (string) $d['calumi'],
				'_yume_nonce' => wp_create_nonce( 'yume_membres_' . $d['calumi'] ),
			),
			$d['admin']
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'Il reste responsable de 2 tomes en cours', $r['message'] );
		update_option( 'yume_pages', array() );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Planning public
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'planning public : passerelle « Modifier dans l’espace équipe » pour l’équipe seulement',
	function () {
		$d = yume_te_jeu();
		wp_set_current_user( $d['calumi'] );
		$html = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'yn-planning__equipe-tete', $html );
		yume_assert_contains( esc_url( url_vue_equipe( 'planning' ) ) . '">Modifier dans l’espace équipe', $html );
		yume_assert_contains( esc_url( url_vue_equipe( 'planning', array( 'tome' => $d['t10'] ) ) . '#yn-tome-' . $d['t10'] ), $html );
		foreach ( array( $d['lecteur'], 0 ) as $uid ) {
			wp_set_current_user( $uid );
			$html = yume_render_block( 'yume/planning' );
			yume_assert_not_contains( 'Modifier dans l’espace équipe', $html, (string) $uid );
			yume_assert_not_contains( 'vue=planning', $html, (string) $uid );
		}
	}
);

yume_te_test(
	'sortie programmée : pastille « Programmé le … » (style distinct) au lieu de « À l’heure »',
	function () {
		$d      = yume_te_jeu();
		$ligne  = null;
		$lignes = yume_get_planning(
			array(
				'gestion' => true,
				'public'  => false,
			)
		);
		foreach ( $lignes as $l ) {
			if ( (int) $l['tome_id'] === $d['t11'] ) {
				$ligne = $l;
			}
		}
		yume_assert_true( is_array( $ligne ) && ! empty( $ligne['programme'] ), 'ligne programmée' );
		$libelle = \Yume\Core\Planning\libelle_etat_ligne( $ligne );
		yume_assert_contains( 'Programmé le', $libelle );
		$puce = \Yume\Core\Planning\pastille_ligne( $ligne );
		yume_assert_contains( 'yn-chip--programme', $puce );
		yume_assert_contains( esc_html( $libelle ), $puce );
		yume_assert_not_contains( 'À l’heure', $puce );

		$html = yume_te_rendu(
			$d['gerant'],
			array(
				'vue'    => 'planning',
				'statut' => 'future',
			)
		);
		yume_assert_contains( '<span class="yn-chip yn-chip--new yn-chip--programme" data-yn-puce="">', $html, 'vue de gestion' );
		yume_assert_contains( esc_html( $libelle ), $html );

		wp_set_current_user( $d['calumi'] );
		$html = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'yn-chip--programme', $html, 'planning public' );
		yume_assert_contains( esc_html( $libelle ), $html );
	}
);

yume_te_test(
	'journal : sortie partielle, retour en ligne, tome complet, dépublication',
	function () {
		$d     = yume_te_jeu();
		$texte = static function ( string $champ, $ancien, $nouveau ) use ( $d ): array {
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			journaliser( $d['t9'], 0, $champ, $ancien, $nouveau );
			$e = grouper_journal( lire_journal( array( 'tome_id' => $d['t9'] ) ), true );
			return array( implode( ', ', $e[0]['parties'] ?? array() ), (bool) ( $e[0]['publie'] ?? false ) );
		};
		yume_assert_same(
			array( 'sortie partielle : 5 chapitres publiés sur 12', false ),
			$texte(
				'publie',
				'',
				array(
					'chapitres' => 5,
					'total'     => 12,
					'partiel'   => true,
				)
			)
		);
		yume_assert_same(
			array( 'remis en ligne, 12 chapitres', true ),
			$texte(
				'publie',
				'',
				array(
					'chapitres' => 12,
					'retour'    => true,
				)
			)
		);
		yume_assert_same(
			array( 'dernier chapitre publié : tome complet', true ),
			$texte(
				'publie',
				'',
				array(
					'chapitres' => 12,
					'complet'   => true,
				)
			)
		);
		yume_assert_same( array( 'dépublié (repassé en brouillon)', false ), $texte( 'depublie', 'publish', 'draft' ) );
		yume_assert_same( array( 'dépublié (passé en privé)', false ), $texte( 'depublie', 'publish', 'private' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Réglages (?vue=reglages)
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'réglages : vue refusée à l’éditeur et au traducteur (ni formulaire ni entrée de navigation)',
	function () {
		$d = yume_te_jeu();
		foreach ( array( 'editeur', 'calumi' ) as $qui ) {
			$html = yume_te_rendu( $d[ $qui ], array( 'vue' => 'reglages' ) );
			yume_assert_contains( 'Seuls les gérants et les administrateurs peuvent modifier les réglages du site.', $html, $qui );
			yume_assert_not_contains( 'name="yume_reglages[', $html, $qui . ' : aucun champ' );
			yume_assert_not_contains( 'yume_reglages_equipe', $html, $qui . ' : aucun formulaire' );
			yume_assert_not_contains( 'Réglages', implode( '|', array_column( yume_te_nav( $html ), 0 ) ), $qui . ' : pas d’entrée' );
			yume_assert_not_contains( 'id="yn-mes-taches"', $html, $qui . ' : pas le tableau de bord' );
		}
	}
);

yume_te_test(
	'réglages : le gérant voit toutes les sections sauf « Mises à jour » (update_plugins), l’administrateur tout',
	function () {
		$d = yume_te_jeu();
		delete_option( 'yume_reglages' );
		$html = yume_te_rendu( $d['gerant'], array( 'vue' => 'reglages' ) );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Réglages</h2>', $html );
		yume_assert_contains( 'data-yn-rest=', $html, 'racine de l’espace équipe' );
		$nav = yume_te_nav( $html );
		yume_assert_same( ' aria-current="page"', $nav[ count( $nav ) - 1 ][2], 'entrée « Réglages » active' );
		foreach ( array( 'Site et réseaux', 'Planning et rappels', 'Annonces et notifications', 'Partenaires' ) as $titre ) {
			yume_assert_contains( '>' . $titre . '</h2>', $html, $titre );
		}
		yume_assert_not_contains( '>Mises à jour</h2>', $html, 'section vide masquée' );
		foreach ( \Yume\Core\Core\champs_reglages() as $champ ) {
			$present = false !== strpos( $html, 'name="yume_reglages[' . $champ['key'] . ']' );
			yume_assert_same( ! in_array( $champ['key'], array( 'github_repo', 'maj_auto' ), true ), $present, $champ['key'] );
		}
		// Types de champs dans l'habillage de l'espace équipe.
		yume_assert_contains( 'type="url" id="yn-reglage-kofi_url" name="yume_reglages[kofi_url]" value="https://ko-fi.com/ynovel"', $html );
		yume_assert_contains( '<label class="yn-label" for="yn-reglage-kofi_url">Page Ko-fi</label>', $html );
		yume_assert_contains( 'aria-describedby="yn-reglage-kofi_url-aide"', $html );
		yume_assert_contains( 'type="number" id="yn-reglage-rappel_jours_sans_maj"', $html );
		yume_assert_contains( 'min="1" max="90"', $html );
		yume_assert_contains( '<select id="yn-reglage-rappel_heure"', $html );
		yume_assert_contains( 'name="yume_reglages[jours_sortie][]" value="samedi" checked', $html );
		yume_assert_contains( '<textarea id="yn-reglage-modele_annonce"', $html );
		yume_assert_contains( 'name="yume_reglages[emails_lecteurs]" value="1" checked', $html );
		yume_assert_contains( 'name="yume_reglages[partenaires][0][nom]" value="MassNovel"', $html );
		yume_assert_contains( 'name="yume_reglages[partenaires][7][url]"', $html, 'emplacements libres' );
		yume_assert_contains( 'Choisir dans la médiathèque', $html );
		yume_assert_contains( esc_url( admin_url( 'admin.php?page=yume-reglages#yume-reglage-banniere_id' ) ), $html );
		yume_assert_not_contains( 'wp.media', $html );
		// Un seul formulaire, un seul bouton, nonce et action admin-post.php.
		yume_assert_same( 1, substr_count( $html, 'Enregistrer les réglages' ) );
		yume_assert_contains( 'name="action" value="yume_reglages_equipe"', $html );
		yume_assert_contains( 'name="_yume_nonce"', $html );
		yume_assert_contains( 'name="yume_reglages[_formulaire]" value="1"', $html );
		yume_assert_contains( esc_url( admin_url( 'admin-post.php' ) ), $html );
		yume_assert_contains( 'Ouvrir dans l’administration', $html );
		yume_assert_contains( 'role="status" aria-live="polite"', $html );

		$html = yume_te_rendu( $d['admin'], array( 'vue' => 'reglages' ) );
		yume_assert_contains( '>Mises à jour</h2>', $html );
		yume_assert_contains( 'name="yume_reglages[github_repo]"', $html );
		yume_assert_contains( 'name="yume_reglages[maj_auto]"', $html );
	}
);

yume_te_test(
	'réglages : enregistrement (admin-post.php) avec l’assainissement de la page d’administration',
	function () {
		$d = yume_te_jeu();
		delete_option( 'yume_reglages' );
		wp_set_current_user( $d['gerant'] );
		$dossier = wp_upload_dir();
		$image   = wp_insert_attachment(
			array(
				'post_title'     => 'Bannière',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			$dossier['basedir'] . '/2026/09/banniere-equipe-test.png'
		);
		$post    = array(
			'action'        => 'yume_reglages_equipe',
			'_yume_nonce'   => wp_create_nonce( 'yume_reglages_equipe' ),
			'yume_reglages' => array(
				'_formulaire'           => '1',
				'kofi_url'              => 'https://ko-fi.com/equipe',
				'modele_annonce'        => 'Le {nature} de l\\\'équipe',
				'rappel_jours_sans_maj' => '500',
				'jours_sortie'          => array( 'samedi', 'jour-inconnu' ),
				'banniere_id'           => $dossier['baseurl'] . '/2026/09/banniere-equipe-test.png',
				'github_repo'           => 'attaquant/depot-piege',
				'partenaires'           => array(
					array(
						'nom' => 'Bon partenaire',
						'url' => 'https://exemple.fr/',
					),
					array(
						'nom' => 'Mauvais lien',
						'url' => 'javascript:alert(1)',
					),
				),
			),
		);
		$retour  = traiter_formulaire_reglages( $post, $d['gerant'] );
		yume_assert_same( 'erreur', $retour['type'], 'avertissement de l’assainissement' );
		yume_assert_same( 'yn-reglages-retour', $retour['cible'] );
		yume_assert_contains( 'Partenaire « Mauvais lien » ignoré', implode( ' | ', $retour['details'] ) );
		yume_assert_same( 1, count( $retour['details'] ) );

		$option = get_option( 'yume_reglages' );
		yume_assert_same( 'https://ko-fi.com/equipe', $option['kofi_url'] );
		yume_assert_same( 'Le {nature} de l\'équipe', $option['modele_annonce'], 'déslashé' );
		yume_assert_same( 90, $option['rappel_jours_sans_maj'], 'borné comme dans l’administration' );
		yume_assert_same( array( 'samedi' ), $option['jours_sortie'] );
		yume_assert_false( $option['emails_lecteurs'], 'case absente du formulaire : décochée' );
		yume_assert_same( $image, $option['banniere_id'], 'adresse de la médiathèque convertie en ID' );
		yume_assert_same( array( 'Bon partenaire' ), array_column( $option['partenaires'], 'nom' ) );
		yume_assert_same( 'GNAlexandre/Yume-WordPress', $option['github_repo'], 'dépôt réservé à update_plugins' );
		yume_assert_true( $option['maj_auto'], 'maj_auto conservé (champ masqué au gérant)' );

		// Même résultat que le callback de la page d'administration.
		$attendu = \Yume\Core\Core\assainir_reglages( wp_unslash( array_merge( $post['yume_reglages'], array( 'banniere_id' => (string) $image ) ) ) );
		yume_assert_same( $attendu, $option );

		// Formulaire sans avertissement ; image inconnue : valeur conservée et signalée.
		$post['_yume_nonce']                  = wp_create_nonce( 'yume_reglages_equipe' );
		$post['yume_reglages']['partenaires'] = array();
		$retour                               = traiter_formulaire_reglages( $post, $d['gerant'] );
		yume_assert_same( 'ok', $retour['type'] );
		yume_assert_same( 'Réglages enregistrés.', $retour['message'] );
		$post['yume_reglages']['banniere_id'] = 'https://ailleurs.example/image.png';
		$retour                               = traiter_formulaire_reglages( $post, $d['gerant'] );
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_contains( 'Bannière du site', $retour['details'][0] ?? '' );
		yume_assert_same( $image, (int) yume_setting( 'banniere_id' ) );

		// Retour affiché dans la zone aria-live de la vue.
		retour_formulaire( $d['gerant'], $retour );
		$html = yume_te_rendu( $d['gerant'], array( 'vue' => 'reglages' ) );
		yume_assert_contains( 'yn-team__retour--erreur" id="yn-reglages-retour" role="status" aria-live="polite"', $html );
		yume_assert_contains( 'Réglages enregistrés, sauf :', $html );
		yume_assert_contains( 'aucune image de la médiathèque', $html );
	}
);

yume_te_test(
	'réglages : nonce invalide ou compte sans yume_reglages refusés, rien n’est enregistré',
	function () {
		$d = yume_te_jeu();
		delete_option( 'yume_reglages' );
		$saisie = array(
			'_formulaire' => '1',
			'kofi_url'    => 'https://ko-fi.com/pirate',
		);
		wp_set_current_user( $d['gerant'] );
		$retour = traiter_formulaire_reglages(
			array(
				'_yume_nonce'   => 'faux',
				'yume_reglages' => $saisie,
			),
			$d['gerant']
		);
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_contains( 'session a expiré', $retour['message'] );
		yume_assert_same( 'https://ko-fi.com/ynovel', yume_setting( 'kofi_url' ) );

		wp_set_current_user( $d['editeur'] );
		$retour = traiter_formulaire_reglages(
			array(
				'_yume_nonce'   => wp_create_nonce( 'yume_reglages_equipe' ),
				'yume_reglages' => $saisie,
			),
			$d['editeur']
		);
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_contains( 'autorisation', $retour['message'] );
		yume_assert_same( 'https://ko-fi.com/ynovel', yume_setting( 'kofi_url' ) );
		yume_assert_true( has_action( 'admin_post_yume_reglages_equipe' ) > 0 );
		yume_assert_true( has_action( 'admin_post_nopriv_yume_reglages_equipe' ) > 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Lecture à compléter
 * -----------------------------------------------------------------------------
 */

/**
 * Chapitre publié (ou brouillon) d'un tome, sans notification.
 *
 * @param int    $tome_id Tome.
 * @param int    $numero  Numéro.
 * @param string $statut  Statut.
 */
function yume_te_chapitre( int $tome_id, int $numero, string $statut = 'publish' ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => 'Chapitre ' . $numero,
				'post_status'  => $statut,
				'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id' => $tome_id,
					'yume_numero'  => $numero,
					'yume_nature'  => 'chapitre',
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

yume_te_test(
	'lecture à compléter : tomes parus sans chapitre en ligne, par œuvre, progression, filtre, boutons ; le tome quitte la liste une fois ses chapitres en ligne',
	function () {
		$d       = yume_te_jeu();
		$d['t8'] = yume_te_tome( $d['grimgar'], 8, array( 'yume_etape' => 'publie' ), 'publish' );
		$d['r1'] = yume_te_tome(
			$d['raven'],
			1,
			array(
				'yume_etape'     => 'publie',
				'yume_lien_pdf'  => 'https://www.clictune.com/r1',
				'yume_lien_epub' => 'https://www.clictune.com/r1e',
			),
			'publish'
		);
		yume_te_chapitre( $d['t8'], 1 );
		yume_te_chapitre( $d['r1'], 1, 'draft' );

		$html = yume_te_rendu( $d['editeur'], array( 'vue' => 'lecture' ) );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Lecture en ligne à compléter</h2>', $html );
		yume_assert_contains( '1 tome sur 3 a la lecture en ligne', $html, 'progression' );
		yume_assert_contains( '2 à compléter', $html );
		yume_assert_contains( '--v:33%', $html, 'barre de progression' );
		yume_assert_contains( 'id="yn-lecture-' . $d['t9'] . '"', $html, 'T.9 publié sans chapitre' );
		yume_assert_contains( 'id="yn-lecture-' . $d['r1'] . '"', $html, 'Raven T.1 : chapitre en brouillon seulement' );
		foreach ( array( 't8', 't10', 't11', 'raven3' ) as $cle ) {
			yume_assert_not_contains( 'id="yn-lecture-' . $d[ $cle ] . '"', $html, $cle . ' absent' );
		}
		yume_assert_true( strpos( $html, '>Grimgar</h3>' ) < strpos( $html, '>Raven</h3>' ), 'œuvres par titre' );
		yume_assert_contains( '1 à compléter sur 2 tomes parus', $html );
		yume_assert_contains( esc_url( add_query_arg( 'tome', $d['t9'], yume_url_page( 'publier' ) ) ) . '">Ajouter le DOCX', $html );
		yume_assert_contains( esc_url( get_permalink( $d['r1'] ) ) . '">Voir la fiche', $html );
		yume_assert_contains( 'PDF présent', $html );
		yume_assert_contains( 'EPUB présent', $html );
		yume_assert_contains( 'Pas de PDF', $html, 'T.9 sans lien' );
		yume_assert_contains( '1 chapitre préparé, pas encore en ligne', $html );
		yume_assert_contains( '<span class="yn-visually-hidden"> — Grimgar, Tome 9</span>', $html, 'bouton explicite pour les lecteurs d’écran' );
		yume_assert_contains( 'name="vue" value="lecture"', $html, 'filtre GET' );
		yume_assert_contains( '>Grimgar (1)</option>', $html );
		$nav = yume_te_nav( $html );
		yume_assert_same( 'Lecture à compléter', $nav[5][0] );
		yume_assert_same( ' aria-current="page"', $nav[5][2], 'entrée active' );
		yume_assert_same( 1, substr_count( $html, 'aria-current' ) );

		// Filtre par œuvre.
		$raven = yume_te_rendu(
			$d['editeur'],
			array(
				'vue'    => 'lecture',
				'oeuvre' => (string) $d['raven'],
			)
		);
		yume_assert_contains( 'id="yn-lecture-' . $d['r1'] . '"', $raven );
		yume_assert_not_contains( 'id="yn-lecture-' . $d['t9'] . '"', $raven );
		yume_assert_contains( '0 tome sur 1 a la lecture en ligne', $raven );
		yume_assert_contains( '>Toutes les œuvres</a>', $raven );

		// Chapitres mis en ligne : le tome quitte la liste.
		yume_te_chapitre( $d['t9'], 1 );
		$apres = yume_te_rendu( $d['gerant'], array( 'vue' => 'lecture' ) );
		yume_assert_not_contains( 'id="yn-lecture-' . $d['t9'] . '"', $apres, 'T.9 complété' );
		yume_assert_contains( '2 tomes sur 3 ont la lecture en ligne', $apres );
		yume_assert_not_contains( '>Grimgar</h3>', $apres, 'œuvre complète masquée' );

		// Tout complété.
		yume_te_chapitre( $d['r1'], 2 );
		$fini = yume_te_rendu( $d['admin'], array( 'vue' => 'lecture' ) );
		yume_assert_contains( 'Tous les tomes parus ont leur lecture en ligne.', $fini );
		yume_assert_contains( '3 tomes sur 3 ont la lecture en ligne', $fini );
	}
);

yume_te_test(
	'lecture à compléter : réservée à yume_publier (traducteur refusé, sans entrée de navigation), visiteur renvoyé à la connexion',
	function () {
		$d    = yume_te_jeu();
		$html = yume_te_rendu( $d['calumi'], array( 'vue' => 'lecture' ) );
		yume_assert_contains( 'Lecture en ligne à compléter', $html );
		yume_assert_contains( 'Seuls les rôles « Éditeur Yume » et « Gérant »', $html );
		yume_assert_not_contains( 'id="yn-lecture-' . $d['t9'] . '"', $html );
		yume_assert_not_contains( 'Lecture à compléter', implode( '|', array_column( yume_te_nav( $html ), 0 ) ) );
		$lecteur = yume_te_rendu( $d['lecteur'], array( 'vue' => 'lecture' ) );
		yume_assert_not_contains( 'yn-lecture-', $lecteur );
		yume_assert_contains( 'Espace réservé à l’équipe', $lecteur );
		$visiteur = yume_te_rendu( 0, array( 'vue' => 'lecture' ) );
		yume_assert_contains( 'Se connecter', $visiteur );
		yume_assert_not_contains( 'yn-lecture-', $visiteur );
	}
);

yume_te_test(
	'journal : « lecture en ligne ajoutée (sans annonce) » réservé à l’équipe',
	function () {
		$d  = yume_te_jeu();
		$id = journaliser(
			$d['t9'],
			$d['editeur'],
			'lecture_ajoutee',
			'',
			array(
				'chapitres' => 12,
				'programme' => false,
			)
		);
		yume_assert_true( $id > 0 );
		$lignes = lire_journal( array( 'tome_id' => $d['t9'] ) );
		yume_assert_same( 0, (int) $lignes[0]->public );
		$entrees = grouper_journal( $lignes, true );
		yume_assert_contains( 'lecture en ligne ajoutée (sans annonce) : 12 chapitres', implode( ' ', $entrees[0]['parties'] ) );
		yume_assert_same(
			array(),
			lire_journal(
				array(
					'tome_id' => $d['t9'],
					'public'  => true,
				)
			)
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * SEC-03 : le gérant change les rôles, jamais le profil d'un autre compte
 * -----------------------------------------------------------------------------
 */

yume_te_test(
	'SEC-03 : un gérant ne change ni le mot de passe ni l’e-mail d’un autre compte (REST, user-edit.php)',
	function () {
		$gerant = yume_te_membre( 'yume_gerant', 'Hikari' );
		$admin  = yume_te_membre( 'administrator', 'Admin' );
		$trad   = yume_te_membre( 'yume_traducteur', 'Calumi' );
		$lec    = yume_te_membre( 'subscriber', 'Kaede' );

		foreach ( array( $lec, $trad ) as $cible ) {
			$avant = get_userdata( $cible );
			$rep   = yume_rest( 'POST', '/wp/v2/users/' . $cible, array( 'password' => 'Nouveau-mot-de-passe-42' ), $gerant );
			yume_assert_same( 403, $rep->get_status(), "mot de passe du compte $cible" );
			$rep = yume_rest( 'POST', '/wp/v2/users/' . $cible, array( 'email' => 'pirate' . $cible . '@example.com' ), $gerant );
			yume_assert_same( 403, $rep->get_status(), "e-mail du compte $cible" );
			// Un rôle accompagné d'un autre champ exige edit_user : refusé aussi.
			$rep = yume_rest(
				'POST',
				'/wp/v2/users/' . $cible,
				array(
					'roles'    => array( 'yume_relecteur' ),
					'password' => 'Nouveau-mot-de-passe-42',
				),
				$gerant
			);
			yume_assert_same( 403, $rep->get_status(), "rôle + mot de passe du compte $cible" );
			clean_user_cache( $cible );
			$apres = get_userdata( $cible );
			yume_assert_same( $avant->user_pass, $apres->user_pass, 'mot de passe inchangé' );
			yume_assert_same( $avant->user_email, $apres->user_email, 'e-mail inchangé' );
			yume_assert_same( $avant->roles, $apres->roles, 'rôle inchangé' );
		}

		// user-edit.php (current_user_can( 'edit_user', $id )) et user-new.php (create_users).
		wp_set_current_user( $gerant );
		yume_assert_false( current_user_can( 'edit_user', $lec ), 'user-edit.php d’un lecteur' );
		yume_assert_false( current_user_can( 'edit_user', $trad ), 'user-edit.php d’un membre' );
		yume_assert_false( current_user_can( 'create_users' ), 'user-new.php' );
		yume_assert_true( current_user_can( 'edit_user', $gerant ), 'son propre profil reste modifiable' );
		yume_assert_true( current_user_can( 'list_users' ), 'liste des comptes (users.php)' );
		wp_set_current_user( 0 );

		// Son propre profil (REST) et la liste des comptes en contexte edit : inchangés.
		$rep = yume_rest( 'POST', '/wp/v2/users/' . $gerant, array( 'name' => 'Hikari G.' ), $gerant );
		yume_assert_same( 200, $rep->get_status(), wp_json_encode( $rep->get_data() ) );
		$rep = yume_rest( 'GET', '/wp/v2/users', array( 'context' => 'edit' ), $gerant );
		yume_assert_same( 200, $rep->get_status() );

		// L'administrateur, lui, peut toujours.
		$rep = yume_rest( 'POST', '/wp/v2/users/' . $lec, array( 'email' => 'kaede-nouvelle@example.com' ), $admin );
		yume_assert_same( 200, $rep->get_status(), wp_json_encode( $rep->get_data() ) );
		yume_assert_same( 'kaede-nouvelle@example.com', get_userdata( $lec )->user_email );
		$rep = yume_rest( 'POST', '/wp/v2/users/' . $trad, array( 'password' => 'Autre-mot-de-passe-42' ), $admin );
		yume_assert_same( 200, $rep->get_status() );
		yume_assert_true( wp_check_password( 'Autre-mot-de-passe-42', get_userdata( $trad )->user_pass, $trad ) );
		wp_set_current_user( $admin );
		yume_assert_true( current_user_can( 'edit_user', $lec ) );
		yume_assert_true( current_user_can( 'create_users' ) );
	}
);

yume_te_test(
	'SEC-03 : sans edit_users, le gérant change toujours les rôles (Membres et rôles, REST)',
	function () {
		$gerant = yume_te_membre( 'yume_gerant', 'Hikari' );
		$trad   = yume_te_membre( 'yume_traducteur', 'Calumi' );
		$lec    = yume_te_membre( 'subscriber', 'Kaede' );
		$lec2   = yume_te_membre( 'subscriber', 'Mio' );
		wp_set_current_user( $gerant );
		$post = static function ( string $op, array $champs ): array {
			$nonce = 'ajout' === $op ? 'yume_membres_ajout' : 'yume_membres_' . (int) ( $champs['user_id'] ?? 0 );
			return array_merge(
				array(
					'op'          => $op,
					'_yume_nonce' => wp_create_nonce( $nonce ),
				),
				array_map( 'strval', $champs )
			);
		};

		// Changer le rôle d'un membre.
		$r = \Yume\Core\Planning\traiter_formulaire_membres(
			$post(
				'role',
				array(
					'user_id' => $trad,
					'role'    => 'yume_editeur',
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( array( 'yume_editeur' ), array_values( get_userdata( $trad )->roles ) );

		// Ajouter un compte existant (lecteur) à l'équipe.
		$r = \Yume\Core\Planning\traiter_formulaire_membres(
			$post(
				'ajout',
				array(
					'compte' => get_userdata( $lec )->user_login,
					'role'   => 'yume_graphiste',
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( array( 'yume_graphiste' ), array_values( get_userdata( $lec )->roles ) );

		// Retirer de l'équipe : retour au rôle Lecteur.
		$r = \Yume\Core\Planning\traiter_formulaire_membres( $post( 'retrait', array( 'user_id' => $trad ) ), $gerant );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( array( 'subscriber' ), array_values( get_userdata( $trad )->roles ) );
		wp_set_current_user( 0 );

		// REST : un changement de rôle seul n'exige que promote_user.
		$rep = yume_rest( 'POST', '/wp/v2/users/' . $lec2, array( 'roles' => array( 'yume_relecteur' ) ), $gerant );
		yume_assert_same( 200, $rep->get_status(), wp_json_encode( $rep->get_data() ) );
		yume_assert_same( array( 'yume_relecteur' ), array_values( get_userdata( $lec2 )->roles ) );
	}
);

yume_te_test(
	'SEC-03 : un site existant perd create_users et edit_users du gérant à la mise à jour (yume_core_roles)',
	function () {
		$roles = wp_roles();
		// État d'avant la mise à jour : capacités stockées en base, ancienne signature.
		$stockes = get_option( $roles->role_key );
		$stockes['yume_gerant']['capabilities']['create_users'] = true;
		$stockes['yume_gerant']['capabilities']['edit_users']   = true;
		update_option( $roles->role_key, $stockes, true );
		$roles->for_site();
		update_option( 'yume_core_roles', 'signature-precedente', true );
		$gerant = yume_te_membre( 'yume_gerant', 'Hikari' );
		$lec    = yume_te_membre( 'subscriber', 'Kaede' );
		yume_assert_true( user_can( $gerant, 'edit_user', $lec ), 'état initial : le gérant modifiait les profils' );

		\Yume\Core\Core\verifier_roles();

		$role = get_role( 'yume_gerant' );
		yume_assert_false( $role->has_cap( 'create_users' ) );
		yume_assert_false( $role->has_cap( 'edit_users' ) );
		yume_assert_true( $role->has_cap( 'promote_users' ) );
		yume_assert_true( $role->has_cap( 'list_users' ) );
		$en_base = get_option( $roles->role_key );
		yume_assert_false( isset( $en_base['yume_gerant']['capabilities']['edit_users'] ), 'retiré de l’option des rôles' );
		yume_assert_false( isset( $en_base['yume_gerant']['capabilities']['create_users'] ) );
		yume_assert_true( ! empty( $en_base['administrator']['capabilities']['edit_users'] ), 'administrateur inchangé' );
		yume_assert_false( user_can( $gerant, 'edit_user', $lec ) );
		yume_assert_true( user_can( $gerant, 'promote_user', $lec ) );
		yume_assert_true( 'signature-precedente' !== get_option( 'yume_core_roles' ), 'signature mise à jour' );

		// Idempotent : une seconde vérification n'écrit plus rien.
		$avant = get_option( $roles->role_key );
		\Yume\Core\Core\verifier_roles();
		\Yume\Core\Core\installer_roles();
		yume_assert_same( $avant, get_option( $roles->role_key ) );
	}
);
