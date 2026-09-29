<?php
/**
 * Tests des indicateurs de l'espace équipe (?vue=kpi : sorties par mois, délais par étape tirés
 * du journal, charge par membre, audience agrégée, e-mails, cache, accès et navigation) et de la
 * santé du site (vue ?vue=sante de l'espace équipe, sous-menu Yume → Santé qui y mène : dernière
 * exécution des tâches planifiées, tests « Santé du site », bouton « Envoyer un test » d'un
 * webhook Discord, dates en français).
 *
 * Lancement : tools/localenv/test.sh kpi
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\audience_kpi;
use function Yume\Core\Planning\charge_membres;
use function Yume\Core\Planning\delais_par_etape;
use function Yume\Core\Planning\donnees_kpi;
use function Yume\Core\Planning\emails_kpi;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\rendu_vue_kpi;
use function Yume\Core\Planning\rendu_vue_sante;
use function Yume\Core\Planning\retards_kpi;
use function Yume\Core\Planning\sorties_par_mois;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\table_notifications;
use function Yume\Core\Planning\vue_equipe;
use function Yume\Core\Planning\vues_equipe_ajoutees;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tk_)
 * -----------------------------------------------------------------------------
 */

/** Horodatage de référence des tests : 15 septembre 2026, 12 h UTC. */
const YUME_TK_MAINTENANT = 1789473600;

/**
 * Déclare un test isolé (contenus Yume, journal, file d'e-mails, favoris et progression vidés
 * dans la transaction du test ; « maintenant » figé au 15 septembre 2026).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tk_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_notifications() ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . \Yume\Core\Social\table_favoris() ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . \Yume\Core\Reader\table_progression() ); // phpcs:ignore
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			\Yume\Core\Planning\oublier_kpi();
			$fige = static function () {
				return YUME_TK_MAINTENANT;
			};
			add_filter( 'yume_planning_maintenant', $fige );
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps();
			} finally {
				remove_filter( 'yume_planning_maintenant', $fige );
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				\Yume\Core\Planning\oublier_kpi();
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Insère un contenu publié à une date donnée (sans déclencher les annonces).
 *
 * @param string $type Type de contenu.
 * @param string $date Date locale « Y-m-d H:i:s ».
 */
function yume_tk_contenu( string $type, string $date ): int {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->posts,
		array(
			'post_type'             => $type,
			'post_status'           => 'publish',
			'post_title'            => 'KPI ' . $type,
			'post_content'          => '',
			'post_excerpt'          => '',
			'to_ping'               => '',
			'pinged'                => '',
			'post_content_filtered' => '',
			'post_date'             => $date,
			'post_date_gmt'         => get_gmt_from_date( $date ),
			'post_modified'         => $date,
			'post_modified_gmt'     => get_gmt_from_date( $date ),
		)
	);
	return (int) $wpdb->insert_id;
}

/**
 * Écrit une ligne du journal à une date donnée.
 *
 * @param int    $tome   Tome.
 * @param string $champ  Champ.
 * @param string $ancien Ancienne valeur.
 * @param string $nouv   Nouvelle valeur.
 * @param int    $ts     Horodatage.
 */
function yume_tk_journal( int $tome, string $champ, string $ancien, string $nouv, int $ts ): void {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		table_journal(),
		array(
			'tome_id'    => $tome,
			'user_id'    => 0,
			'champ'      => $champ,
			'ancien'     => $ancien,
			'nouveau'    => $nouv,
			'public'     => 1,
			'created_at' => gmdate( 'Y-m-d H:i:s', $ts ),
		)
	);
}

/**
 * Ligne de planning minimale pour charge_membres() et retards_kpi().
 *
 * @param int    $id    Tome.
 * @param string $etape Étape.
 * @param string $etat  État.
 * @param array  $resp  Étape => ID du responsable.
 * @param int    $jours Jours de retard.
 */
function yume_tk_ligne( int $id, string $etape, string $etat, array $resp, int $jours = 0 ): array {
	$responsables = array();
	foreach ( array( 'traduction', 'relecture', 'edition' ) as $e ) {
		$responsables[ $e ] = array(
			'id'  => (int) ( $resp[ $e ] ?? 0 ),
			'nom' => '',
		);
	}
	return array(
		'tome_id'      => $id,
		'oeuvre'       => 'Œuvre',
		'tome'         => 'Tome ' . $id,
		'etape'        => $etape,
		'etat'         => $etat,
		'responsables' => $responsables,
		'motif_retard' => 'en_retard' === $etat ? 'date' : '',
		'jours_retard' => $jours,
	);
}

/**
 * Réglages Yume fusionnés.
 *
 * @param array $valeurs Valeurs.
 */
function yume_tk_reglages( array $valeurs ): void {
	$actuels = get_option( 'yume_reglages', array() );
	update_option( 'yume_reglages', array_merge( is_array( $actuels ) ? $actuels : array(), $valeurs ) );
}

/*
 * -----------------------------------------------------------------------------
 * Indicateurs
 * -----------------------------------------------------------------------------
 */

yume_tk_test(
	'KPI : sorties par mois sur 12 mois (tomes et chapitres publiés, mois vides à zéro)',
	static function () {
		yume_tk_contenu( 'yume_tome', '2026-09-10 10:00:00' );
		yume_tk_contenu( 'yume_tome', '2026-07-02 10:00:00' );
		yume_tk_contenu( 'yume_tome', '2026-07-20 10:00:00' );
		yume_tk_contenu( 'yume_chapitre', '2026-07-20 10:00:00' );
		yume_tk_contenu( 'yume_tome', '2025-10-05 10:00:00' );
		yume_tk_contenu( 'yume_tome', '2025-09-20 10:00:00' ); // Hors des 12 mois.
		$sorties = sorties_par_mois();
		yume_assert_same( 12, count( $sorties ) );
		yume_assert_same( '2025-10', $sorties[0]['mois'], 'premier mois' );
		yume_assert_same( '2026-09', $sorties[11]['mois'], 'mois courant en dernier' );
		$par_mois = array_column( $sorties, 'tomes', 'mois' );
		yume_assert_same( 1, $par_mois['2026-09'] );
		yume_assert_same( 2, $par_mois['2026-07'] );
		yume_assert_same( 1, $par_mois['2025-10'] );
		yume_assert_same( 0, $par_mois['2026-08'] );
		yume_assert_same( 4, array_sum( $par_mois ) );
		yume_assert_same( 1, array_column( $sorties, 'chapitres', 'mois' )['2026-07'] );
		yume_assert_contains( 'sept.', $sorties[11]['libelle'] );
	}
);

yume_tk_test(
	'KPI : délai moyen par étape d’après le journal (étapes terminées dans la période)',
	static function () {
		$j  = DAY_IN_SECONDS;
		$t0 = YUME_TK_MAINTENANT - 40 * $j;
		// Tome 1 : à faire 2 j, traduction 10 j, relecture 4 j, édition en cours.
		yume_tk_journal( 1, 'creation', '', 'Tome 1', $t0 );
		yume_tk_journal( 1, 'etape', 'a_faire', 'traduction', $t0 + 2 * $j );
		yume_tk_journal( 1, 'avancement', '{}', '{}', $t0 + 5 * $j );
		yume_tk_journal( 1, 'etape', 'traduction', 'relecture', $t0 + 12 * $j );
		yume_tk_journal( 1, 'etape', 'relecture', 'edition', $t0 + 16 * $j );
		// Tome 2 : à faire 4 j.
		yume_tk_journal( 2, 'creation', '', 'Tome 2', $t0 + 10 * $j );
		yume_tk_journal( 2, 'etape', 'a_faire', 'traduction', $t0 + 14 * $j );
		// Tome 3 : changement d'étape sans début connu (tome migré) : ignoré.
		yume_tk_journal( 3, 'etape', 'traduction', 'relecture', $t0 + 3 * $j );

		$delais = delais_par_etape( gmdate( 'Y-m-d H:i:s', $t0 ) );
		yume_assert_same( 3.0, $delais['a_faire']['jours'] );
		yume_assert_same( 2, $delais['a_faire']['nb'] );
		yume_assert_same( 10.0, $delais['traduction']['jours'] );
		yume_assert_same( 1, $delais['traduction']['nb'] );
		yume_assert_same( 4.0, $delais['relecture']['jours'] );
		yume_assert_same( 0, $delais['edition']['nb'] );

		// Période commençant au 13e jour : seules les étapes terminées après comptent.
		$delais = delais_par_etape( gmdate( 'Y-m-d H:i:s', $t0 + 13 * $j ) );
		yume_assert_same( 0, $delais['traduction']['nb'], 'traduction terminée avant la période' );
		yume_assert_same( 1, $delais['relecture']['nb'] );
		yume_assert_same( 1, $delais['a_faire']['nb'] );
		yume_assert_same( 4.0, $delais['a_faire']['jours'] );
	}
);

yume_tk_test(
	'KPI : charge par membre (tâches ouvertes et en retard) et retards en cours',
	static function () {
		$ana     = yume_factory_user( 'yume_traducteur' );
		$bob     = yume_factory_user( 'yume_relecteur' );
		$lecteur = yume_factory_user( 'subscriber' );
		$lignes  = array(
			yume_tk_ligne(
				11,
				'traduction',
				'en_retard',
				array(
					'traduction' => $ana,
					'relecture'  => $bob,
				),
				9
			),
			yume_tk_ligne(
				12,
				'relecture',
				'a_lheure',
				array(
					'traduction' => $ana,
					'relecture'  => $bob,
				)
			),
			yume_tk_ligne( 13, 'a_faire', 'en_retard', array( 'traduction' => $ana ), 3 ),
			yume_tk_ligne( 14, 'publie', 'publie', array( 'traduction' => $ana ) ),
			yume_tk_ligne( 15, 'edition', 'a_lheure', array( 'edition' => $lecteur ) ),
		);
		$charge  = charge_membres( $lignes );
		// Ana : tomes 11 et 13 (le 12 a passé sa traduction), deux retards.
		yume_assert_same( 2, $charge[ $ana ]['ouvertes'] );
		yume_assert_same( 2, $charge[ $ana ]['retards'] );
		// Bob : tomes 11 (à venir) et 12, aucun retard sur son étape.
		yume_assert_same( 2, $charge[ $bob ]['ouvertes'] );
		yume_assert_same( 0, $charge[ $bob ]['retards'] );
		yume_assert_false( isset( $charge[ $lecteur ] ), 'hors équipe : absent' );
		yume_assert_same( $ana, array_key_first( $charge ), 'le plus en retard d’abord' );

		$retards = retards_kpi( $lignes );
		yume_assert_same( array( 11, 13 ), array_column( $retards, 'tome_id' ) );
		yume_assert_same( 'traduction', $retards[1]['etape'], '« à faire » compte comme traduction' );
	}
);

yume_tk_test(
	'KPI : lecteurs actifs et favoris par œuvre (agrégats seulement), e-mails de la période',
	static function () {
		global $wpdb;
		$o1     = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Alpha',
			)
		);
		$o2     = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Beta',
			)
		);
		$recent = gmdate( 'Y-m-d H:i:s', YUME_TK_MAINTENANT - 5 * DAY_IN_SECONDS );
		$vieux  = gmdate( 'Y-m-d H:i:s', YUME_TK_MAINTENANT - 60 * DAY_IN_SECONDS );
		$prog   = \Yume\Core\Reader\table_progression();
		foreach ( array( array( 101, $o1, $recent ), array( 101, $o2, $recent ), array( 102, $o1, $recent ), array( 103, $o1, $vieux ) ) as $p ) {
			$wpdb->insert(
				$prog,
				array(
					'user_id'    => $p[0],
					'oeuvre_id'  => $p[1],
					'updated_at' => $p[2],
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$fav = \Yume\Core\Social\table_favoris();
		foreach ( array( array( 101, $o1, $recent ), array( 102, $o1, $vieux ), array( 103, $o1, $vieux ), array( 104, $o2, $recent ) ) as $f ) {
			$wpdb->insert(
				$fav,
				array(
					'user_id'    => $f[0],
					'oeuvre_id'  => $f[1],
					'created_at' => $f[2],
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$depuis   = gmdate( 'Y-m-d H:i:s', YUME_TK_MAINTENANT - 30 * DAY_IN_SECONDS );
		$audience = audience_kpi( $depuis );
		yume_assert_same( 2, $audience['lecteurs'], 'comptes distincts ayant lu dans la période' );
		yume_assert_same( array( $o1, $o2 ), array_keys( $audience['oeuvres'] ), 'œuvres par favoris' );
		yume_assert_same(
			array(
				'titre'    => 'Alpha',
				'favoris'  => 3,
				'nouveaux' => 1,
				'lecteurs' => 2,
			),
			$audience['oeuvres'][ $o1 ]
		);
		yume_assert_same( 1, $audience['oeuvres'][ $o2 ]['lecteurs'] );
		yume_assert_not_contains( '101', wp_json_encode( $audience ), 'aucun identifiant de lecteur' );

		$notifs = table_notifications();
		foreach ( array( array( 'envoye', $recent, $recent ), array( 'envoye', $vieux, $vieux ), array( 'echec', $recent, null ), array( 'attente', $recent, null ) ) as $n ) {
			$wpdb->insert(
				$notifs,
				array(
					'destinataire' => 'a@example.org',
					'sujet'        => 'S',
					'html'         => 'x',
					'statut'       => $n[0],
					'created_at'   => $n[1],
					'envoye_le'    => $n[2],
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		yume_assert_same(
			array(
				'envoyes' => 1,
				'echecs'  => 1,
				'attente' => 1,
			),
			emails_kpi( $depuis )
		);
	}
);

yume_tk_test(
	'KPI : indicateurs mis en cache 5 minutes, cache effacé par une mise à jour du planning',
	static function () {
		$premier = donnees_kpi( 90 );
		yume_assert_same( 90, $premier['jours'] );
		yume_assert_same( 0, $premier['sorties_periode'] );
		yume_tk_contenu( 'yume_tome', '2026-09-10 10:00:00' );
		yume_assert_same( 0, donnees_kpi( 90 )['sorties_periode'], 'lu dans le cache' );
		do_action( 'yume_planning_mis_a_jour', 1, array(), 0 );
		yume_assert_same( 1, donnees_kpi( 90 )['sorties_periode'], 'recalculé' );
		yume_assert_same( 30, donnees_kpi( 7 )['jours'], 'période inconnue : 30 jours' );
	}
);

yume_tk_test(
	'KPI : vue réservée aux gérants et administrateurs, entrée de navigation selon le rôle',
	static function () {
		$gerant     = yume_factory_user( 'yume_gerant' );
		$editeur    = yume_factory_user( 'yume_editeur' );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		$admin      = yume_factory_user( 'administrator' );

		wp_set_current_user( $gerant );
		yume_assert_true( isset( vues_equipe_ajoutees()['kpi'] ) );
		yume_assert_contains( 'vue=kpi', navigation_equipe( 'tableau' ) );
		$_GET['vue'] = 'kpi';
		yume_assert_same( 'kpi', vue_equipe() );
		$html = rendu_vue_kpi();
		yume_assert_contains( 'Sorties par mois', $html );
		yume_assert_contains( 'role="img"', $html, 'graphique accessible' );
		yume_assert_contains( '<table class="yn-kpi__table">', $html, 'tableau de données' );
		yume_assert_contains( 'Délai moyen par étape', $html );
		yume_assert_contains( 'Charge par membre', $html );
		yume_assert_contains( 'aria-current="page"', $html );
		yume_assert_true( wp_style_is( 'yume-kpi', 'enqueued' ), 'feuille de style chargée' );

		// Période choisie.
		$_GET['periode'] = '365';
		yume_assert_contains( '<option value="365" selected', rendu_vue_kpi() );

		wp_set_current_user( $admin );
		yume_assert_true( isset( vues_equipe_ajoutees()['kpi'] ), 'administrateur' );

		foreach ( array( $traducteur, $editeur ) as $uid ) {
			wp_set_current_user( $uid );
			yume_assert_false( isset( vues_equipe_ajoutees()['kpi'] ), 'pas de vue sans yume_reglages' );
			yume_assert_not_contains( 'vue=kpi', navigation_equipe( 'tableau' ) );
			yume_assert_same( '', vue_equipe(), '?vue=kpi ignoré' );
			$html = rendu_vue_kpi();
			yume_assert_contains( 'Seuls les gérants', $html, 'accès refusé' );
			yume_assert_not_contains( 'Sorties par mois', $html );
		}
		wp_set_current_user( $traducteur );
		yume_assert_not_contains( 'Sorties par mois', yume_render_block( 'yume/team-dashboard' ), 'tableau de bord à la place' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Santé du site
 * -----------------------------------------------------------------------------
 */

yume_tk_test(
	'Santé : l’écouteur générique note le début de chaque tâche planifiée Yume',
	static function () {
		delete_option( \Yume\Core\Core\OPTION_CRON_DERNIERS );
		$ajouter = static function ( $taches ) {
			$taches['yume_test_tache_kpi'] = 'Tâche de test';
			return $taches;
		};
		add_filter( 'yume_taches_cron', $ajouter );
		try {
			\Yume\Core\Core\ecouter_taches_cron();
			$vu    = 0;
			$tache = static function () use ( &$vu ) {
				$vu = (int) ( get_option( \Yume\Core\Core\OPTION_CRON_DERNIERS )['yume_test_tache_kpi'] ?? 0 );
			};
			add_action( 'yume_test_tache_kpi', $tache );
			$avant = time();
			do_action( 'yume_test_tache_kpi' );
			yume_assert_true( $vu >= $avant, 'horodatage écrit avant le travail de la tâche' );
			$etat = \Yume\Core\Core\etat_taches_cron();
			yume_assert_same( $vu, $etat['yume_test_tache_kpi']['derniere'] );
			yume_assert_same( 'Tâche de test', $etat['yume_test_tache_kpi']['libelle'] );
			yume_assert_true( isset( $etat['yume_planning_rappels'], $etat['yume_notifications_envoyer'] ) );
			yume_assert_true( has_action( 'yume_planning_digest', 'Yume\Core\Core\noter_execution_cron' ) !== false, 'hooks existants écoutés' );
			remove_action( 'yume_test_tache_kpi', $tache );
		} finally {
			remove_filter( 'yume_taches_cron', $ajouter );
		}
	}
);

yume_tk_test(
	'Santé : tests « Santé du site » (tâches, e-mails, webhooks, version) et leurs statuts',
	static function () {
		global $wpdb;
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		foreach ( array( 'yume_taches_cron', 'yume_emails', 'yume_webhooks', 'yume_version' ) as $cle ) {
			yume_assert_true( isset( $tests['direct'][ $cle ] ) && is_callable( $tests['direct'][ $cle ]['test'] ), $cle );
		}

		// Tâches (limitées aux trois tâches du planning, reprogrammées à l'heure : l'environnement
		// de test ne fait jamais tourner le cron, d'autres tâches y sont en retard).
		$essentielles = static function ( $taches ) {
			return array_intersect_key( (array) $taches, array_flip( array( 'yume_planning_rappels', 'yume_planning_digest', 'yume_notifications_envoyer' ) ) );
		};
		add_filter( 'yume_taches_cron', $essentielles );
		remove_all_filters( 'yume_planning_maintenant' ); // Planification à l'heure réelle.
		foreach ( array( 'yume_planning_rappels', 'yume_planning_digest', 'yume_notifications_envoyer' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		\Yume\Core\Planning\planifier();
		yume_assert_same( 'good', \Yume\Core\Core\test_sante_cron()['status'], 'tâches à l’heure' );
		wp_clear_scheduled_hook( 'yume_planning_digest' );
		wp_schedule_event( time() - 2 * HOUR_IN_SECONDS, 'weekly', 'yume_planning_digest' );
		$r = \Yume\Core\Core\test_sante_cron();
		yume_assert_same( 'recommended', $r['status'], 'tâche en retard' );
		yume_assert_same( 'yume_taches_cron', $r['test'] );
		wp_clear_scheduled_hook( 'yume_notifications_envoyer' );
		yume_assert_same( 'critical', \Yume\Core\Core\test_sante_cron()['status'], 'tâche non programmée' );
		remove_filter( 'yume_taches_cron', $essentielles );

		// E-mails.
		yume_assert_same( 'good', \Yume\Core\Core\test_sante_emails()['status'] );
		$wpdb->insert(
			table_notifications(),
			array(
				'destinataire' => 'a@example.org',
				'sujet'        => 'S',
				'html'         => 'x',
				'statut'       => 'echec',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		yume_assert_same( 'recommended', \Yume\Core\Core\test_sante_emails()['status'], 'e-mail abandonné' );
		$wpdb->query( 'DELETE FROM ' . table_notifications() ); // phpcs:ignore
		$wpdb->insert(
			table_notifications(),
			array(
				'destinataire' => 'a@example.org',
				'sujet'        => 'S',
				'html'         => 'x',
				'statut'       => 'attente',
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		yume_assert_same( 'recommended', \Yume\Core\Core\test_sante_emails()['status'], 'e-mail bloqué' );

		// Webhooks.
		yume_tk_reglages(
			array(
				'discord_webhook_sorties' => '',
				'discord_webhook_equipe'  => '',
			)
		);
		yume_assert_same( 'recommended', \Yume\Core\Core\test_sante_webhooks()['status'] );
		yume_tk_reglages(
			array(
				'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/sorties',
				'discord_webhook_equipe'  => 'https://discord.com/api/webhooks/2/equipe',
			)
		);
		yume_assert_same( 'good', \Yume\Core\Core\test_sante_webhooks()['status'] );

		// Version : lue dans l'état enregistré, sans requête réseau.
		$requetes = 0;
		$compter  = static function ( $pre ) use ( &$requetes ) {
			++$requetes;
			return $pre;
		};
		add_filter( 'pre_http_request', $compter );
		delete_site_transient( 'update_plugins' );
		update_site_option(
			'external_updates-yume-core',
			(object) array(
				'lastCheck'      => time() - 3600,
				'checkedVersion' => YUME_CORE_VERSION,
				'update'         => (object) array( 'version' => '99.0.0' ),
			)
		);
		$v = \Yume\Core\Core\etat_version();
		yume_assert_same( '99.0.0', $v['derniere'] );
		yume_assert_true( $v['maj'] );
		yume_assert_same( 'recommended', \Yume\Core\Core\test_sante_version()['status'] );
		update_site_option(
			'external_updates-yume-core',
			(object) array(
				'lastCheck'      => time() - 3600,
				'checkedVersion' => YUME_CORE_VERSION,
			)
		);
		yume_assert_same( 'good', \Yume\Core\Core\test_sante_version()['status'] );
		yume_assert_same( YUME_CORE_VERSION, \Yume\Core\Core\etat_version()['derniere'], 'à jour : dernière = installée' );
		remove_filter( 'pre_http_request', $compter );
		yume_assert_same( 0, $requetes, 'aucune requête réseau' );
	}
);

yume_tk_test(
	'Santé : « Envoyer un test » (capacité, nonce, envoi Discord intercepté, retour sur la vue) et vue ?vue=sante',
	static function () {
		$gerant     = yume_factory_user( 'yume_gerant' );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		yume_tk_reglages(
			array(
				'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/secret-sorties',
				'discord_webhook_equipe'  => '',
			)
		);
		yume_assert_true( has_action( 'admin_post_yume_sante_webhook' ) > 0 );

		$requetes = array();
		$code     = 204;
		$filtre   = static function ( $pre, $args, $url ) use ( &$requetes, &$code ) {
			$requetes[] = array(
				'url'  => $url,
				'body' => (string) $args['body'],
			);
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filtre, 10, 3 );
		try {
			wp_set_current_user( $traducteur );
			$nonce = wp_create_nonce( 'yume_sante_webhook' );
			yume_assert_same(
				'droits',
				\Yume\Core\Core\traiter_test_webhook(
					array(
						'canal'    => 'sorties',
						'_wpnonce' => $nonce,
					),
					$traducteur
				)
			);
			yume_assert_same( 'droits', \Yume\Core\Core\traiter_test_webhook( array( 'canal' => 'sorties' ), 0 ), 'anonyme' );

			wp_set_current_user( $gerant );
			yume_assert_same(
				'nonce',
				\Yume\Core\Core\traiter_test_webhook(
					array(
						'canal'    => 'sorties',
						'_wpnonce' => 'faux',
					),
					$gerant
				)
			);
			yume_assert_same( 'nonce', \Yume\Core\Core\traiter_test_webhook( array( 'canal' => 'sorties' ), $gerant ), 'sans nonce' );
			yume_assert_same( array(), $requetes, 'rien envoyé sans droit ni nonce' );

			$nonce = wp_create_nonce( 'yume_sante_webhook' );
			yume_assert_same(
				'ok',
				\Yume\Core\Core\traiter_test_webhook(
					array(
						'canal'    => 'sorties',
						'_wpnonce' => $nonce,
					),
					$gerant
				)
			);
			yume_assert_same( 1, count( $requetes ) );
			yume_assert_same( 'https://discord.com/api/webhooks/1/secret-sorties', $requetes[0]['url'] );
			yume_assert_contains( 'Message de test', $requetes[0]['body'] );

			yume_assert_same(
				'absent',
				\Yume\Core\Core\traiter_test_webhook(
					array(
						'canal'    => 'equipe',
						'_wpnonce' => $nonce,
					),
					$gerant
				)
			);
			yume_assert_same(
				'canal',
				\Yume\Core\Core\traiter_test_webhook(
					array(
						'canal'    => 'inconnu',
						'_wpnonce' => $nonce,
					),
					$gerant
				)
			);

			$code = 404;
			yume_assert_same(
				'echec',
				\Yume\Core\Core\traiter_test_webhook(
					array(
						'canal'    => 'sorties',
						'_wpnonce' => $nonce,
					),
					$gerant
				)
			);
			yume_assert_contains( 'HTTP 404', wp_json_encode( \Yume\Core\Planning\echecs_recents( 1 ) ), 'échec noté' );

			// Retour : message mémorisé, affiché une fois dans la zone d'annonce de la vue.
			$url = \Yume\Core\Core\retour_test_webhook( 'ok', 'sorties', $gerant );
			yume_assert_contains( 'vue=sante', $url );
			yume_assert_contains( '#yn-sante-retour', $url );
			yume_assert_same( 'erreur', \Yume\Core\Core\message_test_webhook( 'nonce', 'sorties' )['type'] );

			// Vue : bouton de test, adresse secrète du webhook jamais affichée.
			$_GET['vue'] = 'sante';
			yume_assert_same( 'sante', vue_equipe() );
			$html = rendu_vue_sante();
			yume_assert_contains( 'Message de test envoyé', $html, 'retour affiché' );
			yume_assert_contains( 'Annonces des sorties', $html );
			yume_assert_contains( 'Envoyer un test', $html );
			yume_assert_contains( 'name="_wpnonce"', $html );
			yume_assert_contains( 'value="yume_sante_webhook"', $html );
			yume_assert_contains( 'admin-post.php', $html );
			yume_assert_contains( 'Configuré (discord.com)', $html );
			yume_assert_contains( 'Non configuré', $html );
			yume_assert_contains( 'vue=reglages#yn-reglages-notifications', $html, 'canal non configuré : lien vers les réglages' );
			yume_assert_not_contains( 'secret-sorties', $html );
			yume_assert_not_contains( 'webhooks/1', $html );
			yume_assert_contains( 'Tâches planifiées', $html );
			yume_assert_contains( 'Derniers échecs d’envoi', $html );
			yume_assert_contains( 'HTTP 404', $html, 'échec listé' );
			yume_assert_contains( 'Version installée : ' . YUME_CORE_VERSION, $html );
			yume_assert_contains( 'aria-current="page"', $html );
			yume_assert_true( wp_style_is( 'yume-sante', 'enqueued' ), 'feuille de style chargée' );
			yume_assert_not_contains( 'Prérequis de mise en production', $html, 'prérequis : administrateurs seulement' );
			yume_assert_not_contains( 'Message de test envoyé', rendu_vue_sante(), 'message lu une seule fois' );

			wp_set_current_user( $traducteur );
			$html = rendu_vue_sante();
			yume_assert_contains( 'Seuls les gérants', $html, 'accès refusé' );
			yume_assert_not_contains( 'Envoyer un test', $html );
			yume_assert_same( '', vue_equipe(), '?vue=sante ignoré' );
		} finally {
			remove_filter( 'pre_http_request', $filtre, 10 );
		}
	}
);

yume_tk_test(
	'Santé : navigation (« Santé du site » juste avant « Réglages »), prérequis une seule fois, sous-menu et avis',
	static function () {
		$gerant     = yume_factory_user( 'yume_gerant' );
		$admin      = yume_factory_user( 'administrator' );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		$editeur    = yume_factory_user( 'yume_editeur' );

		foreach ( array( $gerant, $admin ) as $uid ) {
			wp_set_current_user( $uid );
			yume_assert_true( isset( vues_equipe_ajoutees()['sante'] ) );
			$nav = navigation_equipe( 'tableau' );
			yume_assert_true( preg_match_all( '#<li><a [^>]*>([^<]+)</a></li>#', $nav, $m ) > 0 );
			$libelles = array_map( 'html_entity_decode', $m[1] );
			$sante    = array_search( 'Santé du site', $libelles, true );
			yume_assert_true( false !== $sante, 'entrée présente' );
			yume_assert_same( 'Réglages', $libelles[ $sante + 1 ] ?? '', 'juste avant Réglages' );
			yume_assert_contains( 'vue=sante', $nav );
		}
		foreach ( array( $traducteur, $editeur ) as $uid ) {
			wp_set_current_user( $uid );
			yume_assert_false( isset( vues_equipe_ajoutees()['sante'] ) );
			yume_assert_not_contains( 'vue=sante', navigation_equipe( 'tableau' ) );
			yume_assert_not_contains( 'Santé du site', navigation_equipe( 'tableau' ) );
		}

		// Administrateur : prérequis affichés une seule fois (pas d'avis en plus en façade).
		$etat = static function ( $e ) {
			$e['langue']   = 'en_US';
			$e['wp_debug'] = true;
			return $e;
		};
		add_filter( 'yume_prerequis_etat', $etat );
		wp_set_current_user( $admin );
		$html = rendu_vue_sante();
		remove_filter( 'yume_prerequis_etat', $etat );
		yume_assert_same( 1, substr_count( $html, 'Prérequis de mise en production' ), 'une seule fois' );
		yume_assert_same( 1, substr_count( $html, 'data-prerequis="langue"' ) );
		yume_assert_contains( 'id="yn-sante-prerequis"', $html );

		// Sous-menu Yume → Santé : mène à la vue ; l'avis global n'est pas affiché sur cet écran
		// et renvoie vers la vue pour le détail.
		yume_assert_contains( 'vue=sante', \Yume\Core\Core\url_sante() );
		yume_assert_true( false !== has_action( 'admin_menu', 'Yume\Core\Core\ajouter_page_sante' ) );
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		$avant = $GLOBALS['current_screen'] ?? null;
		add_filter( 'yume_prerequis_etat', $etat );
		try {
			$GLOBALS['current_screen'] = WP_Screen::get( 'yume_page_yume-sante' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- écran simulé, rétabli plus bas.
			yume_assert_false( \Yume\Core\Core\ecran_prerequis(), 'pas d’avis sur Yume → Santé' );
			$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			delete_user_meta( $admin, \Yume\Core\Core\META_PREREQUIS_MASQUES );
			ob_start();
			\Yume\Core\Core\avis_prerequis_production();
			$avis = (string) ob_get_clean();
			yume_assert_contains( 'vue=sante#yn-sante-prerequis', $avis, 'avis : lien vers le détail' );
		} finally {
			remove_filter( 'yume_prerequis_etat', $etat );
			$GLOBALS['current_screen'] = $avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		// Tests de WordPress : le lien d'action mène aussi à la vue.
		yume_assert_contains( 'vue=sante', \Yume\Core\Core\test_sante_version()['actions'] );
	}
);

yume_tk_test(
	'Santé : dates en français (« 29 septembre 2026 à 9 h 00 ») même si la langue du site est en_US',
	static function () {
		$gerant = yume_factory_user( 'yume_gerant' );
		wp_set_current_user( $gerant );
		$locale = static fn() => 'en_US';
		add_filter( 'locale', $locale );
		try {
			// 29 septembre 2026, 9 h 00 à Paris (UTC+2) ; 5 octobre 2026, 14 h 05.
			$prochaine = ( new DateTimeImmutable( '2026-09-29 09:00:00', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp();
			$derniere  = ( new DateTimeImmutable( '2026-10-05 14:05:00', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp();
			wp_clear_scheduled_hook( 'yume_planning_rappels' );
			wp_schedule_event( $prochaine, 'daily', 'yume_planning_rappels' );
			update_option( \Yume\Core\Core\OPTION_CRON_DERNIERS, array( 'yume_planning_rappels' => $derniere ), false );
			yume_assert_same( '29 septembre 2026 à 9 h 00', \Yume\Core\Planning\date_sante( $prochaine, '' ) );
			$html = rendu_vue_sante();
			yume_assert_contains( '29 septembre 2026 à 9 h 00', $html );
			yume_assert_contains( '5 octobre 2026 à 14 h 05', $html );
			yume_assert_not_contains( 'September', $html );
			yume_assert_not_contains( 'October', $html );
			yume_assert_contains( 'data-libelle="Prochaine exécution"', $html, 'libellés des colonnes pour la liste mobile' );
		} finally {
			remove_filter( 'locale', $locale );
			wp_clear_scheduled_hook( 'yume_planning_rappels' );
			delete_option( \Yume\Core\Core\OPTION_CRON_DERNIERS );
		}
	}
);
