<?php
/**
 * Tests du socle du module statistiques (lot 0) : table d'historique, capacités des rôles,
 * tâche planifiée quotidienne (03:10, heure du site), périodes et variations, cache, œuvres
 * publiées, nettoyage d'une œuvre supprimée, routes REST (droits, 503 tant qu'une fonction de
 * données manque, en-tête Cache-Control).
 *
 * Les tests REST s'adaptent aux fichiers de données présents : 503 yume_statistiques_indisponible
 * avec les bouchons du socle, 200 une fois la fonction livrée.
 *
 * Lancement : tools/localenv/test.sh statistiques
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Statistiques\CAPACITE_LECTEURS;
use const Yume\Core\Statistiques\CAPACITE_STATS;
use const Yume\Core\Statistiques\HOOK_JOUR;
use const Yume\Core\Statistiques\PERIODE_DEFAUT;
use const Yume\Core\Statistiques\PERIODES;
use function Yume\Core\Statistiques\bornes_periode;
use function Yume\Core\Statistiques\cache_ecrire;
use function Yume\Core\Statistiques\cache_lire;
use function Yume\Core\Statistiques\cache_vider;
use function Yume\Core\Statistiques\oeuvre_de;
use function Yume\Core\Statistiques\oeuvres_publiees;
use function Yume\Core\Statistiques\periode_valide;
use function Yume\Core\Statistiques\table_prete;
use function Yume\Core\Statistiques\table_stats_jour;
use function Yume\Core\Statistiques\variation;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tsocle_)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute $corps avec le fuseau du site forcé à Europe/Paris (filtre, retiré ensuite).
 *
 * @param callable $corps Corps.
 */
function yume_tsocle_paris( callable $corps ): void {
	$fuseau = static fn() => 'Europe/Paris';
	add_filter( 'pre_option_timezone_string', $fuseau );
	try {
		$corps();
	} finally {
		remove_filter( 'pre_option_timezone_string', $fuseau );
	}
}

/**
 * Insère une ligne d'historique.
 *
 * @param string $jour      Jour « Y-m-d ».
 * @param int    $oeuvre_id Œuvre (0 : site).
 * @param int    $favoris   Favoris.
 */
function yume_tsocle_ligne( string $jour, int $oeuvre_id, int $favoris = 0 ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->replace(
		table_stats_jour(),
		array(
			'jour'      => $jour,
			'oeuvre_id' => $oeuvre_id,
			'favoris'   => $favoris,
		),
		array( '%s', '%d', '%d' )
	);
}

/**
 * Nombre de lignes d'historique d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function yume_tsocle_nb_lignes( int $oeuvre_id ): int {
	global $wpdb;
	$table = table_stats_jour();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE oeuvre_id = %d", $oeuvre_id ) );
}

/**
 * La fonction de données du module existe-t-elle (livrée par un autre lot) ?
 *
 * @param string $fonction Nom court.
 */
function yume_tsocle_livree( string $fonction ): bool {
	return function_exists( 'Yume\\Core\\Statistiques\\' . $fonction );
}

/*
 * -----------------------------------------------------------------------------
 * Table d'historique
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques : table yume_stats_jour installée (colonnes, NULL pour le trafic, REPLACE idempotent)',
	function () {
		global $wpdb;
		yume_assert_true( table_prete(), 'schéma installé' );
		yume_assert_same( $wpdb->prefix . 'yume_stats_jour', table_stats_jour() );
		yume_tsocle_ligne( '2026-09-14', 0, 12 );
		yume_tsocle_ligne( '2026-09-14', 0, 13 ); // Même clé (jour, oeuvre_id) : remplacée.
		yume_tsocle_ligne( '2026-09-14', 42, 3 );
		$table = table_stats_jour();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lignes = $wpdb->get_results( "SELECT * FROM {$table} WHERE jour = '2026-09-14' ORDER BY oeuvre_id", ARRAY_A );
		yume_assert_same( 2, count( $lignes ) );
		yume_assert_same(
			array( 'jour', 'oeuvre_id', 'vues', 'visiteurs', 'favoris', 'suiveurs', 'listes', 'lecteurs', 'lecteurs_30j', 'chapitres_lus', 'comptes', 'inscriptions' ),
			array_keys( $lignes[0] )
		);
		yume_assert_same( '13', (string) $lignes[0]['favoris'] );
		yume_assert_same( null, $lignes[0]['vues'], 'vues NULL par défaut (trafic indisponible)' );
		yume_assert_same( null, $lignes[0]['visiteurs'] );
		yume_assert_same( '0', (string) $lignes[0]['suiveurs'], 'compteurs à 0 par défaut' );
		yume_assert_same( '2026-09-14', (string) $lignes[1]['jour'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Capacités
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques : yume_voir_statistiques pour toute l’équipe, yume_voir_lecteurs pour gérants et administrateurs',
	function () {
		\Yume\Core\Core\installer_roles();
		yume_assert_same( 'yume_voir_statistiques', CAPACITE_STATS );
		yume_assert_same( 'yume_voir_lecteurs', CAPACITE_LECTEURS );
		$attendus = array(
			'subscriber'      => array( false, false ),
			'yume_traducteur' => array( true, false ),
			'yume_relecteur'  => array( true, false ),
			'yume_graphiste'  => array( true, false ),
			'yume_editeur'    => array( true, false ),
			'yume_gerant'     => array( true, true ),
			'administrator'   => array( true, true ),
		);
		foreach ( $attendus as $role => $caps ) {
			$user_id = yume_factory_user( $role );
			yume_assert_same( $caps[0], user_can( $user_id, CAPACITE_STATS ), $role . ' : ' . CAPACITE_STATS );
			yume_assert_same( $caps[1], user_can( $user_id, CAPACITE_LECTEURS ), $role . ' : ' . CAPACITE_LECTEURS );
		}
		yume_assert_true( in_array( CAPACITE_STATS, \Yume\Core\Core\capacites_propres(), true ) );
		yume_assert_true( in_array( CAPACITE_LECTEURS, \Yume\Core\Core\capacites_propres(), true ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Tâche planifiée
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques : tâche quotidienne planifiée à 03:10 (heure du site), recalée, libellée et retirée',
	function () {
		yume_tsocle_paris(
			function () {
				wp_clear_scheduled_hook( HOOK_JOUR );
				\Yume\Core\Statistiques\installer();
				$evenement = wp_get_scheduled_event( HOOK_JOUR );
				yume_assert_true( false !== $evenement, 'planifiée par installer()' );
				yume_assert_same( 'daily', $evenement->schedule );
				yume_assert_same( '03:10:00', wp_date( 'H:i:s', $evenement->timestamp ) );
				yume_assert_true( $evenement->timestamp > time() && $evenement->timestamp <= time() + DAY_IN_SECONDS, 'prochain 03:10' );

				// Déjà conforme : inchangée.
				\Yume\Core\Statistiques\planifier();
				yume_assert_same( $evenement->timestamp, wp_next_scheduled( HOOK_JOUR ) );

				// Dérivée (programmée en UTC, ou décalée d'une heure par un changement d'heure) : recalée.
				foreach ( array( '2026-11-02 03:10:00 UTC', '2026-10-26 04:10:00 Europe/Paris' ) as $date ) {
					wp_clear_scheduled_hook( HOOK_JOUR );
					wp_schedule_event( (int) strtotime( $date ), 'daily', HOOK_JOUR );
					\Yume\Core\Statistiques\planifier();
					$ts = (int) wp_next_scheduled( HOOK_JOUR );
					yume_assert_same( '03:10', wp_date( 'H:i', $ts ), 'recalée : ' . $date );
					yume_assert_true( $ts > time() && $ts <= time() + DAY_IN_SECONDS, 'prochain 03:10 : ' . $date );
				}

				// Libellé dans « Santé du site ».
				$taches = \Yume\Core\Core\taches_cron_yume();
				yume_assert_true( isset( $taches[ HOOK_JOUR ] ) && HOOK_JOUR !== $taches[ HOOK_JOUR ], 'libellé de la tâche' );

				// Désactivation du plugin : tâche retirée.
				\Yume\Core\Statistiques\desactiver();
				yume_assert_false( wp_next_scheduled( HOOK_JOUR ) );
				\Yume\Core\Statistiques\planifier();
				yume_assert_true( false !== wp_next_scheduled( HOOK_JOUR ), 'replanifiée' );
			}
		);
	}
);

yume_test(
	'statistiques : seule lancer_tache_jour() est accrochée à la tâche quotidienne',
	function () {
		yume_assert_true( false !== has_action( HOOK_JOUR, 'Yume\\Core\\Statistiques\\lancer_tache_jour' ) );
		// Sans historique.php complet, la tâche ne fait rien (et ne plante pas).
		if ( ! yume_tsocle_livree( 'tache_quotidienne' ) ) {
			\Yume\Core\Statistiques\lancer_tache_jour();
			yume_assert_same( 0, yume_tsocle_nb_lignes( 0 ) );
		}
		yume_assert_false( has_action( HOOK_JOUR, 'Yume\\Core\\Statistiques\\tache_quotidienne' ), 'tache_quotidienne() passe par lancer_tache_jour()' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Périodes et variations
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques : periode_valide() (7, 30, 90, 365, sinon 30)',
	function () {
		yume_assert_same( array( 7, 30, 90, 365 ), PERIODES );
		yume_assert_same( 30, PERIODE_DEFAUT );
		foreach ( array( 7, 30, 90, 365, '7', '90', ' 365 ' ) as $p ) {
			yume_assert_same( (int) $p, periode_valide( $p ), 'valide : ' . $p );
		}
		foreach ( array( 12, 0, -7, '-7', 'abc', '30.5', '7j', null, array( 7 ), 7.0, true, '' ) as $p ) {
			yume_assert_same( 30, periode_valide( $p ), 'invalide : ' . wp_json_encode( $p ) );
		}
	}
);

yume_test(
	'statistiques : bornes_periode() en heure du site, période précédente contiguë et de même durée',
	function () {
		yume_tsocle_paris(
			function () {
				// 15 septembre 2026, 22 h 30 UTC = 16 septembre, 0 h 30 à Paris (UTC+2).
				$b = bornes_periode( 7, (int) strtotime( '2026-09-15 22:30:00 UTC' ) );
				yume_assert_same(
					array(
						'debut'          => '2026-09-10',
						'fin'            => '2026-09-16',
						'debut_prec'     => '2026-09-03',
						'fin_prec'       => '2026-09-09',
						'debut_gmt'      => '2026-09-09 22:00:00',
						'fin_gmt'        => '2026-09-16 21:59:59',
						'debut_prec_gmt' => '2026-09-02 22:00:00',
						'fin_prec_gmt'   => '2026-09-09 21:59:59',
						'jours'          => 7,
					),
					$b
				);

				// 30 jours à cheval sur le passage à l'heure d'hiver (25 octobre 2026).
				$b = bornes_periode( 30, (int) strtotime( '2026-11-10 12:00:00 UTC' ) );
				yume_assert_same( '2026-10-12', $b['debut'] );
				yume_assert_same( '2026-11-10', $b['fin'] );
				yume_assert_same( '2026-10-11 22:00:00', $b['debut_gmt'], 'heure d’été au début' );
				yume_assert_same( '2026-11-10 22:59:59', $b['fin_gmt'], 'heure d’hiver à la fin' );
				yume_assert_same( '2026-09-12', $b['debut_prec'] );
				yume_assert_same( '2026-10-11', $b['fin_prec'] );
				foreach ( array( 7, 30, 90, 365 ) as $jours ) {
					$b = bornes_periode( $jours, (int) strtotime( '2026-03-29 01:30:00 UTC' ) );
					yume_assert_same( $jours, $b['jours'] );
					yume_assert_same( $jours - 1, (int) ( new DateTimeImmutable( $b['debut'] ) )->diff( new DateTimeImmutable( $b['fin'] ) )->days, $jours . ' jours' );
					yume_assert_same( $jours - 1, (int) ( new DateTimeImmutable( $b['debut_prec'] ) )->diff( new DateTimeImmutable( $b['fin_prec'] ) )->days, $jours . ' jours (précédente)' );
					yume_assert_same( $b['debut_gmt'], gmdate( 'Y-m-d H:i:s', strtotime( $b['fin_prec_gmt'] . ' UTC' ) + 1 ), 'contiguës : ' . $jours );
					yume_assert_same( $b['debut'], gmdate( 'Y-m-d', strtotime( $b['fin_prec'] . ' +1 day' ) ) );
				}
			}
		);

		// Sans horodatage : maintenant() (filtre yume_statistiques_maintenant).
		$fige = static fn() => (int) strtotime( '2026-09-15 12:00:00 UTC' );
		add_filter( 'yume_statistiques_maintenant', $fige );
		try {
			yume_assert_same( (int) strtotime( '2026-09-15 12:00:00 UTC' ), \Yume\Core\Statistiques\maintenant() );
			yume_assert_same( bornes_periode( 30, $fige() ), bornes_periode( 30 ) );
		} finally {
			remove_filter( 'yume_statistiques_maintenant', $fige );
		}
	}
);

yume_test(
	'statistiques : variation() en %, une décimale, null sans valeur précédente',
	function () {
		yume_assert_same( 10.0, variation( 110, 100 ) );
		yume_assert_same( -3.0, variation( 97, 100 ) );
		yume_assert_same( -66.7, variation( 1, 3 ) );
		yume_assert_same( 12.5, variation( 45, 40 ) );
		yume_assert_same( -100.0, variation( 0, 10 ) );
		yume_assert_same( 0.0, variation( 5, 5 ) );
		yume_assert_same( null, variation( 5, 0 ) );
		yume_assert_same( null, variation( 0, 0 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Cache et œuvres
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques : cache yume_stats_* (lecture, écriture, préfixe facultatif, vidage)',
	function () {
		cache_vider();
		yume_assert_false( cache_lire( 'essai' ) );
		cache_ecrire( 'essai', array( 'n' => 1 ), 300 );
		cache_ecrire( 'yume_stats_autre', array( 0 ), 300 );
		yume_assert_same( array( 'n' => 1 ), cache_lire( 'essai' ) );
		yume_assert_same( array( 'n' => 1 ), cache_lire( 'yume_stats_essai' ), 'préfixe facultatif' );
		yume_assert_same( array( 'n' => 1 ), get_transient( 'yume_stats_essai' ), 'transient yume_stats_essai' );
		yume_assert_same( array( 0 ), cache_lire( 'autre' ), 'clé déjà préfixée' );
		set_transient( 'yume_kpi_test_socle', 1, 300 ); // Hors module : conservé.
		cache_vider();
		yume_assert_false( cache_lire( 'essai' ) );
		yume_assert_false( cache_lire( 'autre' ) );
		yume_assert_true( false !== get_transient( 'yume_kpi_test_socle' ), 'transient hors module conservé' );
		delete_transient( 'yume_kpi_test_socle' );
	}
);

yume_test(
	'statistiques : oeuvres_publiees() (ID => titre brut, publiées seulement, à jour après un ajout) et oeuvre_de()',
	function () {
		$avant  = oeuvres_publiees();
		$a      = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Lanternes &amp; <em>brume</em>',
			)
		);
		$brouil = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_status' => 'draft',
			)
		);
		$liste  = oeuvres_publiees();
		yume_assert_same( 'Lanternes & brume', $liste[ $a ] ?? null );
		yume_assert_false( isset( $liste[ $brouil ] ), 'brouillon exclu' );
		yume_assert_same( count( $avant ) + 1, count( $liste ) );
		$tome = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'meta_input' => array( 'yume_oeuvre_id' => $a ),
			)
		);
		yume_assert_same( $a, oeuvre_de( $tome ) );
		yume_assert_same( $a, oeuvre_de( $a ) );
		yume_assert_same( 0, oeuvre_de( 0 ) );
		yume_assert_same( 0, oeuvre_de( yume_factory_post() ) );
	}
);

yume_test(
	'statistiques : œuvre supprimée définitivement → ses lignes d’historique aussi',
	function () {
		$a = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		$b = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		yume_tsocle_ligne( '2026-09-13', $a, 2 );
		yume_tsocle_ligne( '2026-09-14', $a, 3 );
		yume_tsocle_ligne( '2026-09-14', $b, 1 );
		yume_tsocle_ligne( '2026-09-14', 0, 4 );
		wp_trash_post( $a );
		yume_assert_same( 2, yume_tsocle_nb_lignes( $a ), 'corbeille : conservées' );
		wp_delete_post( $a, true );
		yume_assert_same( 0, yume_tsocle_nb_lignes( $a ) );
		yume_assert_same( 1, yume_tsocle_nb_lignes( $b ) );
		yume_assert_same( 1, yume_tsocle_nb_lignes( 0 ), 'ligne site conservée' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Routes REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques REST : 401 anonyme, 403 lecteur, en-tête Cache-Control private, no-store',
	function () {
		$lecteur = yume_factory_user( 'subscriber' );
		foreach ( array( '/yume/v1/statistiques/resume', '/yume/v1/statistiques/trafic', '/yume/v1/statistiques/oeuvres' ) as $route ) {
			$r = yume_rest( 'GET', $route );
			yume_assert_same( 401, $r->get_status(), 'anonyme : ' . $route );
			yume_assert_contains( 'no-store', (string) ( $r->get_headers()['Cache-Control'] ?? '' ), 'en-tête : ' . $route );
			yume_assert_same( 403, yume_rest( 'GET', $route, array(), $lecteur )->get_status(), 'lecteur : ' . $route );
		}
	}
);

yume_test(
	'statistiques REST : traducteur sur /resume, /trafic et /oeuvres → 503 tant que les données manquent',
	function () {
		$traducteur = yume_factory_user( 'yume_traducteur' );
		$cas        = array(
			'/yume/v1/statistiques/resume'  => yume_tsocle_livree( 'resume_lecteurs' ) && yume_tsocle_livree( 'classement' ),
			'/yume/v1/statistiques/trafic'  => yume_tsocle_livree( 'trafic_contenus' ),
			'/yume/v1/statistiques/oeuvres' => yume_tsocle_livree( 'classement' ),
		);
		foreach ( $cas as $route => $livree ) {
			$r = yume_rest( 'GET', $route, array( 'periode' => '7' ), $traducteur );
			yume_assert_contains( 'private, no-store', (string) ( $r->get_headers()['Cache-Control'] ?? '' ), 'en-tête : ' . $route );
			if ( $livree ) {
				yume_assert_same( 200, $r->get_status(), $route );
				continue;
			}
			yume_assert_same( 503, $r->get_status(), $route );
			yume_assert_same( 'yume_statistiques_indisponible', $r->get_data()['code'] ?? null, $route );
		}
		// Paramètre hors liste : refusé par le schéma de la route.
		yume_assert_same( 400, yume_rest( 'GET', '/yume/v1/statistiques/trafic', array( 'section' => 'villes' ), $traducteur )->get_status() );
	}
);

yume_test(
	'statistiques REST : fiche d’œuvre (404 inconnue) et lecteurs nominatifs (403 traducteur et éditeur)',
	function () {
		$oeuvre     = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		$editeur    = yume_factory_user( 'yume_editeur' );
		$gerant     = yume_factory_user( 'yume_gerant' );
		$article    = yume_factory_post();

		// Fiche : 404 sur un contenu qui n'est pas une œuvre.
		$r = yume_rest( 'GET', '/yume/v1/statistiques/oeuvres/' . $article, array(), $traducteur );
		yume_assert_same( 404, $r->get_status() );
		yume_assert_same( 'yume_oeuvre_introuvable', $r->get_data()['code'] ?? null );
		$r = yume_rest( 'GET', '/yume/v1/statistiques/oeuvres/' . $oeuvre, array(), $traducteur );
		yume_assert_same( yume_tsocle_livree( 'fiche_oeuvre' ) ? 200 : 503, $r->get_status(), 'fiche' );

		// Liste nominative : gérants et administrateurs seulement.
		$route = '/yume/v1/statistiques/oeuvres/' . $oeuvre . '/lecteurs';
		yume_assert_same( 401, yume_rest( 'GET', $route )->get_status() );
		yume_assert_same( 403, yume_rest( 'GET', $route, array(), $traducteur )->get_status(), 'traducteur' );
		yume_assert_same( 403, yume_rest( 'GET', $route, array(), $editeur )->get_status(), 'éditeur' );
		yume_assert_same( 404, yume_rest( 'GET', '/yume/v1/statistiques/oeuvres/' . $article . '/lecteurs', array(), $gerant )->get_status() );
		$r = yume_rest( 'GET', $route, array(), $gerant );
		yume_assert_same( yume_tsocle_livree( 'lecteurs_oeuvre' ) ? 200 : 503, $r->get_status(), 'gérant' );
		yume_assert_contains( 'no-store', (string) ( $r->get_headers()['Cache-Control'] ?? '' ) );
	}
);
