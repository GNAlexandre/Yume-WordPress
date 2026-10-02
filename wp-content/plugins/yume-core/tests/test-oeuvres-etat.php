<?php
/**
 * Tests de l'état des œuvres (lot E, includes/planning/oeuvres-etat.php) : changement d'état
 * (droits, nonce, état inconnu), confirmation des changements qui touchent les lecteurs,
 * « Licenciée » (chapitres en brouillon, liens mis de côté, fiche et tomes en ligne, alertes en
 * attente annulées), sortie de « Licenciée » avec et sans restauration (aucune annonce ni
 * notification), « En pause » (ni rappel, ni alerte aux gérants, tomes jamais « en retard »),
 * retour « En cours », suggestion « passer à Terminée », filtre « État », regroupement des tomes,
 * formulaire « Modifier l'œuvre », caches.
 *
 * Lancement : tools/localenv/test.sh oeuvres-etat
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\apercu_etat_oeuvre;
use function Yume\Core\Planning\changer_etat_oeuvre;
use function Yume\Core\Planning\donnees_kpi;
use function Yume\Core\Planning\envoyer_digest;
use function Yume\Core\Planning\etats_tomes;
use function Yume\Core\Planning\executer_rappels;
use function Yume\Core\Planning\groupes_tomes;
use function Yume\Core\Planning\grouper_journal;
use function Yume\Core\Planning\lire_journal;
use function Yume\Core\Planning\mettre_en_file;
use function Yume\Core\Planning\oeuvres_equipe;
use function Yume\Core\Planning\puces_tomes_oeuvre;
use function Yume\Core\Planning\rendu_vue_oeuvres;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\table_notifications;
use function Yume\Core\Planning\tomes_des_oeuvres;
use function Yume\Core\Planning\traiter_etat_oeuvre;
use function Yume\Core\Planning\traiter_formulaire_oeuvre;
use function Yume\Core\Planning\valeurs_oeuvre;

// Batcache absent des tests : adresses purgées relevées (voir vider_caches_etat_oeuvre()).
if ( ! function_exists( 'batcache_clear_url' ) ) {
	/**
	 * Relève une adresse purgée.
	 *
	 * @param string $url Adresse.
	 */
	function batcache_clear_url( $url ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- fonction de Batcache simulée.
		$GLOBALS['yume_tests_batcache'][] = (string) $url;
	}
}

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_toet_)
 * -----------------------------------------------------------------------------
 */

/** Maintenant simulé : jeudi 24 septembre 2026, 12 h à Paris. */
const YUME_TOET_MAINTENANT = 1790244000;

/**
 * Test isolé : œuvres, tomes, chapitres, journal et file d'e-mails vidés (transaction annulée
 * ensuite par le lanceur).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_toet_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_notifications() ); // phpcs:ignore
			delete_option( 'yume_planning_dernier_digest' );
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$GLOBALS['yume_tests_batcache'] = array();
			$get                            = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps();
			} finally {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				delete_transient( 'yume_planning_retour_' . get_current_user_id() );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Exécute un corps à heure simulée (module planning).
 *
 * @param callable $corps Corps.
 * @param int      $ts    Horodatage.
 * @return mixed
 */
function yume_toet_a( callable $corps, int $ts = YUME_TOET_MAINTENANT ) {
	$filtre = static fn(): int => $ts;
	add_filter( 'yume_planning_maintenant', $filtre );
	try {
		return $corps();
	} finally {
		remove_filter( 'yume_planning_maintenant', $filtre );
	}
}

/**
 * Contenu créé sans événement de publication (filtre yume_core_notifier coupé).
 *
 * @param array $args Arguments de wp_insert_post.
 */
function yume_toet_post( array $args ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post( $args );
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Œuvre publiée, avec un état.
 *
 * @param string $titre Titre.
 * @param string $etat  État (slug yume_statut, '' : aucun).
 * @param array  $meta  Méta.
 */
function yume_toet_oeuvre( string $titre, string $etat = 'en-cours', array $meta = array() ): int {
	$id = yume_toet_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => $titre,
			'meta_input' => $meta,
		)
	);
	if ( '' !== $etat ) {
		wp_set_object_terms( $id, $etat, 'yume_statut' );
	}
	return $id;
}

/**
 * Tome d'une œuvre.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $numero    Numéro.
 * @param string $statut    Statut WordPress.
 * @param array  $meta      Méta supplémentaires.
 */
function yume_toet_tome( int $oeuvre_id, int $numero, string $statut = 'publish', array $meta = array() ): int {
	return yume_toet_post(
		array(
			'post_type'   => 'yume_tome',
			'post_title'  => get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
			'post_status' => $statut,
			'post_date'   => gmdate( 'Y-m-d H:i:s', YUME_TOET_MAINTENANT - 30 * DAY_IN_SECONDS ),
			'meta_input'  => array_merge(
				array(
					'yume_oeuvre_id'    => $oeuvre_id,
					'yume_numero'       => $numero,
					'yume_nature'       => 'tome',
					'yume_etape'        => 'publish' === $statut ? 'publie' : 'traduction',
					'yume_parution'     => 'publish' === $statut ? 'complet' : '',
					'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s', YUME_TOET_MAINTENANT - DAY_IN_SECONDS ),
				),
				$meta
			),
		)
	);
}

/**
 * Chapitre d'un tome.
 *
 * @param int    $tome_id Tome.
 * @param int    $numero  Numéro.
 * @param string $statut  publish (daté de 10 jours) ou future (dans 5 jours).
 */
function yume_toet_chapitre( int $tome_id, int $numero, string $statut = 'publish' ): int {
	$ts = 'future' === $statut ? time() + 5 * DAY_IN_SECONDS : time() - 10 * DAY_IN_SECONDS;
	return yume_toet_post(
		array(
			'post_type'     => 'yume_chapitre',
			'post_title'    => 'Chapitre ' . $numero,
			'post_status'   => $statut,
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
			'menu_order'    => $numero,
			'meta_input'    => array(
				'yume_tome_id'   => $tome_id,
				'yume_oeuvre_id' => yume_get_oeuvre_id( $tome_id ),
				'yume_numero'    => $numero,
				'yume_nature'    => 'chapitre',
			),
		)
	);
}

/**
 * Jeu « licence » : SukaMoka publiée, T1 en ligne (liens PDF/EPUB, deux chapitres publiés, un
 * programmé), T2 planifié (brouillon, sans chapitre).
 *
 * @return array<string,int>
 */
function yume_toet_jeu_licence(): array {
	$d            = array();
	$d['editeur'] = yume_factory_user( 'yume_editeur' );
	$d['oeuvre']  = yume_toet_oeuvre( 'SukaMoka' );
	$d['t1']      = yume_toet_tome(
		$d['oeuvre'],
		1,
		'publish',
		array(
			'yume_lien_pdf'  => 'https://exemple.test/t1.pdf',
			'yume_lien_epub' => 'https://exemple.test/t1.epub',
		)
	);
	$d['t2']      = yume_toet_tome( $d['oeuvre'], 2, 'draft' );
	$d['c1']      = yume_toet_chapitre( $d['t1'], 1 );
	$d['c2']      = yume_toet_chapitre( $d['t1'], 2 );
	$d['c3']      = yume_toet_chapitre( $d['t1'], 3, 'future' );
	return $d;
}

/**
 * Nombre de lignes de la file d'e-mails.
 */
function yume_toet_nb_emails(): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . table_notifications() ); // phpcs:ignore
}

/**
 * Jeu « planning » : Calumi (traducteur) responsable de Grimgar T1 en retard de 3 jours ; T2
 * bloqué sans responsable ; Raven T1 en retard (autre œuvre, toujours en cours).
 *
 * @return array<string,int>
 */
function yume_toet_jeu_planning(): array {
	$d            = array();
	$d['calumi']  = yume_factory_user( 'yume_traducteur' );
	$d['editeur'] = yume_factory_user( 'yume_editeur' );
	$d['gerant']  = yume_factory_user( 'yume_gerant' );
	$d['grimgar'] = yume_toet_oeuvre( 'Grimgar' );
	$d['raven']   = yume_toet_oeuvre( 'Raven' );
	$resp         = array(
		'traduction' => $d['calumi'],
		'relecture'  => 0,
		'edition'    => 0,
	);
	$d['g1']      = yume_toet_tome(
		$d['grimgar'],
		1,
		'draft',
		array(
			'yume_date_cible'   => gmdate( 'Y-m-d', YUME_TOET_MAINTENANT - 3 * DAY_IN_SECONDS ),
			'yume_responsables' => $resp,
		)
	);
	$d['g2']      = yume_toet_tome(
		$d['grimgar'],
		2,
		'draft',
		array(
			'yume_bloque'        => true,
			'yume_bloque_raison' => 'relecteur manquant',
		)
	);
	$d['r1']      = yume_toet_tome(
		$d['raven'],
		1,
		'draft',
		array(
			'yume_date_cible'   => gmdate( 'Y-m-d', YUME_TOET_MAINTENANT - 5 * DAY_IN_SECONDS ),
			'yume_responsables' => $resp,
		)
	);
	return $d;
}

/*
 * -----------------------------------------------------------------------------
 * Changement d'état : droits, nonce, confirmation
 * -----------------------------------------------------------------------------
 */

yume_toet_test(
	'État d’une œuvre : droits (edit_post), état inconnu, nonce, inchangé ; journal de l’équipe et action yume_oeuvre_etat_change',
	static function () {
		$d          = yume_toet_jeu_licence();
		$traducteur = yume_factory_user( 'yume_traducteur' );

		$refus = changer_etat_oeuvre( $d['oeuvre'], 'en-pause', $traducteur );
		yume_assert_true( is_wp_error( $refus ), 'traducteur refusé' );
		yume_assert_same( 403, $refus->get_error_data()['status'] );
		yume_assert_same( 400, changer_etat_oeuvre( $d['oeuvre'], 'oubliee', $d['editeur'] )->get_error_data()['status'] );
		yume_assert_same( 404, changer_etat_oeuvre( $d['t1'], 'en-pause', $d['editeur'] )->get_error_data()['status'], 'pas une œuvre' );

		wp_set_current_user( $d['editeur'] );
		$post = array(
			'action'      => 'yume_oeuvre_etat',
			'oeuvre_id'   => (string) $d['oeuvre'],
			'etat'        => 'en-pause',
			'_yume_nonce' => 'faux',
		);
		$r    = traiter_etat_oeuvre( wp_slash( $post ), $d['editeur'] );
		yume_assert_same( 'erreur', $r['type'], 'nonce invalide' );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );

		$recu = array();
		$ecou = static function ( $oeuvre, $ancien, $nouveau, $user ) use ( &$recu ) {
			$recu[] = array( $oeuvre, $ancien, $nouveau, $user );
		};
		add_action( 'yume_oeuvre_etat_change', $ecou, 10, 4 );
		$post['_yume_nonce'] = wp_create_nonce( 'yume_oeuvre_etat_' . $d['oeuvre'] );
		$r                   = traiter_etat_oeuvre( wp_slash( $post ), $d['editeur'] );
		remove_action( 'yume_oeuvre_etat_change', $ecou, 10 );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 'yn-oeuvre-' . $d['oeuvre'], $r['cible'] );
		yume_assert_contains( '« SukaMoka » est maintenant « En pause »', $r['message'] );
		yume_assert_contains( 'Plus de rappel ni d’alerte de planning', $r['message'] );
		yume_assert_same( array( array( $d['oeuvre'], 'en-cours', 'en-pause', $d['editeur'] ) ), $recu );
		yume_assert_same( array( 'en-pause' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( 'publish', get_post_status( $d['c1'] ), 'en pause : la lecture reste en ligne' );

		$lignes = lire_journal( array( 'champs' => array( 'etat_oeuvre' ) ) );
		yume_assert_same( 1, count( $lignes ) );
		yume_assert_same( '0', (string) $lignes[0]->tome_id, 'ligne d’équipe' );
		yume_assert_same( '0', (string) $lignes[0]->public, 'jamais publique' );
		yume_assert_same( array( 'état de « SukaMoka » : en pause' ), grouper_journal( $lignes, true )[0]['parties'] );

		$encore = changer_etat_oeuvre( $d['oeuvre'], 'en-pause', $d['editeur'] );
		yume_assert_false( $encore['changement'], 'déjà en pause' );
		yume_assert_same( 1, count( lire_journal( array( 'champs' => array( 'etat_oeuvre' ) ) ) ), 'rien de journalisé' );
		yume_assert_true( has_action( 'admin_post_yume_oeuvre_etat' ) > 0 && has_action( 'admin_post_nopriv_yume_oeuvre_etat' ) > 0 );
	}
);

yume_toet_test(
	'État d’une œuvre : « Licenciée » (et sa sortie) exige une confirmation ; écran de confirmation sans JavaScript',
	static function () {
		$d = yume_toet_jeu_licence();
		wp_set_current_user( $d['editeur'] );

		$refus = changer_etat_oeuvre( $d['oeuvre'], 'licenciee', $d['editeur'] );
		yume_assert_same( 'yume_oeuvre_etat_confirmation', $refus->get_error_code() );
		yume_assert_same( 409, $refus->get_error_data()['status'] );
		$apercu = $refus->get_error_data()['apercu'];
		yume_assert_same( 3, $apercu['retrait']['chapitres'] );
		yume_assert_same( 1, $apercu['retrait']['liens'] );
		yume_assert_same( 1, $apercu['retrait']['tomes'] );
		yume_assert_same( 'publish', get_post_status( $d['c1'] ), 'rien n’a changé' );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );

		// Ligne de la liste : « Changer » vers « Licenciée » mène à la confirmation.
		$r = traiter_etat_oeuvre(
			wp_slash(
				array(
					'oeuvre_id'   => (string) $d['oeuvre'],
					'etat'        => 'licenciee',
					'_yume_nonce' => wp_create_nonce( 'yume_oeuvre_etat_' . $d['oeuvre'] ),
				)
			),
			$d['editeur']
		);
		yume_assert_same( $d['oeuvre'], $r['changer'] );
		yume_assert_same( 'licenciee', $r['vers'] );
		yume_assert_same( '', $r['message'] );
		yume_assert_same( 'publish', get_post_status( $d['c1'] ) );

		$_GET = array(
			'vue'     => 'oeuvres',
			'changer' => (string) $d['oeuvre'],
			'vers'    => 'licenciee',
		);
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Passer « SukaMoka » à « Licenciée » ?', $html );
		yume_assert_contains( '3 chapitres en ligne ou programmés (1 tome concerné) repassent en brouillon', $html );
		yume_assert_contains( 'Les liens PDF et EPUB de 1 tome sont mis de côté', $html );
		yume_assert_contains( 'La fiche de l’œuvre et ses tomes restent en ligne', $html );
		yume_assert_contains( 'name="confirmer" value="1"', $html );
		yume_assert_contains( 'name="etat" value="licenciee"', $html );
		yume_assert_contains( 'name="_yume_nonce" value="' . wp_create_nonce( 'yume_oeuvre_etat_' . $d['oeuvre'] ) . '"', $html );
		yume_assert_contains( 'Confirmer : passer à « Licenciée »', $html );
		yume_assert_not_contains( 'name="restaurer"', $html, 'pas de restauration en entrant dans « Licenciée »' );
		yume_assert_not_contains( 'id="yn-oeuvres-liste"', $html, 'écran seul' );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ), 'l’écran ne change rien' );

		// Droits : un traducteur ne voit pas l'écran.
		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_not_contains( 'Passer « SukaMoka »', rendu_vue_oeuvres() );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Licenciée : retrait et restauration
 * -----------------------------------------------------------------------------
 */

yume_toet_test(
	'Licenciée : chapitres en brouillon (marqués), liens mis de côté, fiche et tomes en ligne, alertes en attente annulées',
	static function () {
		$d = yume_toet_jeu_licence();
		mettre_en_file( 'lecteur@example.test', 'Nouveau chapitre', '<p>Chapitre 2 disponible</p>', 'alerte_sortie_' . $d['c2'] );
		mettre_en_file( 'autre@example.test', 'Autre sortie', '<p>Autre</p>', 'alerte_sortie_999999' );
		yume_assert_same( 2, yume_toet_nb_emails() );

		$r = changer_etat_oeuvre( $d['oeuvre'], 'licenciee', $d['editeur'], array( 'confirmer' => true ) );
		yume_assert_true( ! is_wp_error( $r ) && $r['changement'] );
		yume_assert_same( 3, $r['retrait']['chapitres'] );
		yume_assert_same( 2, $r['retrait']['publies'] );
		yume_assert_same( 1, $r['retrait']['programmes'] );
		yume_assert_same( 1, $r['retrait']['liens'] );
		foreach ( array( 'c1', 'c2', 'c3' ) as $c ) {
			yume_assert_same( 'draft', get_post_status( $d[ $c ] ), $c . ' en brouillon' );
			yume_assert_true( '' !== (string) get_post_meta( $d[ $c ], '_yume_retire_licence', true ), $c . ' marqué' );
			yume_assert_same( '1', (string) get_post_meta( $d[ $c ], '_yume_retire', true ), $c . ' : la publication ne le ressort pas' );
		}
		yume_assert_same( '', get_post_meta( $d['t1'], 'yume_lien_pdf', true ) );
		yume_assert_same( '', get_post_meta( $d['t1'], 'yume_lien_epub', true ) );
		$cote = get_post_meta( $d['t1'], '_yume_liens_licence', true );
		yume_assert_same( 'https://exemple.test/t1.pdf', $cote['pdf'] );
		yume_assert_same( 'https://exemple.test/t1.epub', $cote['epub'] );
		yume_assert_same( 'publish', get_post_status( $d['t1'] ), 'tome toujours en ligne' );
		yume_assert_same( 'draft', get_post_status( $d['t2'] ) );
		yume_assert_same( 'publish', get_post_status( $d['oeuvre'] ), 'fiche toujours en ligne' );
		yume_assert_same( array( 'licenciee' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( array(), yume_get_chapitres( $d['t1'] ), 'plus de lecture en ligne' );

		global $wpdb;
		$contextes = $wpdb->get_col( 'SELECT contexte FROM ' . table_notifications() . " WHERE statut = 'attente'" ); // phpcs:ignore
		yume_assert_same( array( 'alerte_sortie_999999' ), $contextes, 'alerte du chapitre retiré annulée, les autres gardées' );

		$entree = grouper_journal( lire_journal( array( 'champs' => array( 'etat_oeuvre' ) ) ), true )[0];
		yume_assert_same( array( 'état de « SukaMoka » : licenciée, lecture en ligne retirée (3 chapitre(s), liens de 1 tome(s))' ), $entree['parties'] );
	}
);

yume_toet_test(
	'Sortie de « Licenciée » avec restauration : chapitres republiés (ou reprogrammés) et liens remis, sans annonce ni notification',
	static function () {
		$d = yume_toet_jeu_licence();
		changer_etat_oeuvre( $d['oeuvre'], 'licenciee', $d['editeur'], array( 'confirmer' => true ) );
		wp_set_current_user( $d['editeur'] );

		// Écran de confirmation : case « Remettre en ligne » cochée par défaut.
		$_GET = array(
			'vue'     => 'oeuvres',
			'changer' => (string) $d['oeuvre'],
			'vers'    => 'en-cours',
		);
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Remettre en ligne la lecture et les liens retirés à la licence', $html );
		yume_assert_contains( 'name="restaurer" value="1" checked', $html );
		yume_assert_contains( 'name="restaurer_present" value="1"', $html );
		yume_assert_contains( '3 chapitre(s) republié(s)', $html );

		$refus = changer_etat_oeuvre( $d['oeuvre'], 'en-cours', $d['editeur'] );
		yume_assert_same( 'yume_oeuvre_etat_confirmation', $refus->get_error_code(), 'sortie : confirmation exigée' );

		$emails   = yume_toet_nb_emails();
		$chapitre = did_action( 'yume_chapitre_publie' );
		$tome     = did_action( 'yume_tome_publie' );
		$r        = traiter_etat_oeuvre(
			wp_slash(
				array(
					'oeuvre_id'         => (string) $d['oeuvre'],
					'etat'              => 'en-cours',
					'confirmer'         => '1',
					'restaurer_present' => '1',
					'restaurer'         => '1',
					'_yume_nonce'       => wp_create_nonce( 'yume_oeuvre_etat_' . $d['oeuvre'] ),
				)
			),
			$d['editeur']
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'Remis en ligne sans annonce ni notification : 3 chapitre(s), liens de 1 tome(s)', $r['message'] );
		yume_assert_same( 'publish', get_post_status( $d['c1'] ) );
		yume_assert_same( 'publish', get_post_status( $d['c2'] ) );
		yume_assert_same( 'future', get_post_status( $d['c3'] ), 'date encore à venir : reprogrammé' );
		foreach ( array( 'c1', 'c2', 'c3' ) as $c ) {
			yume_assert_same( '', get_post_meta( $d[ $c ], '_yume_retire_licence', true ) );
			yume_assert_same( '', get_post_meta( $d[ $c ], '_yume_retire', true ) );
		}
		yume_assert_same( 'https://exemple.test/t1.pdf', get_post_meta( $d['t1'], 'yume_lien_pdf', true ) );
		yume_assert_same( 'https://exemple.test/t1.epub', get_post_meta( $d['t1'], 'yume_lien_epub', true ) );
		yume_assert_same( '', get_post_meta( $d['t1'], '_yume_liens_licence', true ) );
		yume_assert_same( $chapitre, did_action( 'yume_chapitre_publie' ), 'aucune annonce de chapitre' );
		yume_assert_same( $tome, did_action( 'yume_tome_publie' ), 'aucune annonce de tome' );
		yume_assert_same( $emails, yume_toet_nb_emails(), 'aucun e-mail mis en file' );
		yume_assert_false( (bool) has_filter( 'yume_core_notifier' ), 'notifications rétablies après l’opération' );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );
	}
);

yume_toet_test(
	'Sortie de « Licenciée » sans restauration : lecture et liens restent de côté (restaurables plus tard)',
	static function () {
		$d = yume_toet_jeu_licence();
		changer_etat_oeuvre( $d['oeuvre'], 'licenciee', $d['editeur'], array( 'confirmer' => true ) );
		$r = traiter_etat_oeuvre(
			wp_slash(
				array(
					'oeuvre_id'         => (string) $d['oeuvre'],
					'etat'              => 'abandonnee',
					'confirmer'         => '1',
					'restaurer_present' => '1',
					'_yume_nonce'       => wp_create_nonce( 'yume_oeuvre_etat_' . $d['oeuvre'] ),
				)
			),
			$d['editeur']
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'restent de côté', $r['message'] );
		yume_assert_same( 'draft', get_post_status( $d['c1'] ) );
		yume_assert_true( '' !== (string) get_post_meta( $d['c1'], '_yume_retire_licence', true ), 'marque gardée' );
		yume_assert_same( '', get_post_meta( $d['t1'], 'yume_lien_pdf', true ) );
		yume_assert_same( 'https://exemple.test/t1.pdf', get_post_meta( $d['t1'], '_yume_liens_licence', true )['pdf'], 'liens gardés de côté' );
		yume_assert_same( array( 'abandonnee' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( null, apercu_etat_oeuvre( $d['oeuvre'], 'en-cours' )['restauration'], 'restauration proposée seulement en quittant « Licenciée »' );

		// Un lien remplacé entre-temps n'est pas écrasé à la restauration suivante.
		changer_etat_oeuvre( $d['oeuvre'], 'licenciee', $d['editeur'], array( 'confirmer' => true ) );
		update_post_meta( $d['t1'], 'yume_lien_pdf', 'https://exemple.test/nouveau.pdf' );
		changer_etat_oeuvre( $d['oeuvre'], 'en-cours', $d['editeur'], array( 'confirmer' => true ) );
		yume_assert_same( 'https://exemple.test/nouveau.pdf', get_post_meta( $d['t1'], 'yume_lien_pdf', true ) );
		yume_assert_same( 'https://exemple.test/t1.epub', get_post_meta( $d['t1'], 'yume_lien_epub', true ) );
		yume_assert_same( 'publish', get_post_status( $d['c1'] ), 'restauré au second passage' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * En pause : ni rappel, ni alerte, ni retard
 * -----------------------------------------------------------------------------
 */

yume_toet_test(
	'En pause : aucun rappel, aucun signalement aux gérants, hors du récapitulatif, tomes jamais « en retard » (tableau de bord, Mes tâches, indicateurs) ; retour En cours : rappels de nouveau',
	static function () {
		$d = yume_toet_jeu_planning();
		yume_toet_a(
			static function () use ( $d ) {
				yume_assert_same( 'en_retard', yume_planning_etat( $d['g1'] ) );
				$r = changer_etat_oeuvre( $d['grimgar'], 'en-pause', $d['editeur'] );
				yume_assert_true( $r['changement'] );
				yume_assert_same( 'a_lheure', yume_planning_etat( $d['g1'] ), 'œuvre en pause : plus en retard' );
				yume_assert_same( 'en_retard', yume_planning_etat( $d['r1'] ), 'autre œuvre inchangée' );
				yume_assert_same( 'bloque', yume_planning_etat( $d['g2'] ), 'bloqué reste bloqué' );

				$rapport = executer_rappels();
				yume_assert_same( array( $d['r1'] ), array_column( $rapport['rappels'], 'tome_id' ), 'seul Raven est rappelé' );
				yume_assert_same( array(), $rapport['signalements'], 'pas de « tome bloqué » aux gérants' );

				$digest = envoyer_digest( true );
				yume_assert_same( 1, $digest['retards'], 'récapitulatif : Raven seulement' );
				yume_assert_same( 0, $digest['bloques'], 'récapitulatif : pas le tome bloqué de Grimgar' );
				global $wpdb;
				$corps = (string) $wpdb->get_var( 'SELECT html FROM ' . table_notifications() . " WHERE contexte = 'digest' LIMIT 1" ); // phpcs:ignore
				yume_assert_not_contains( 'Grimgar', $corps );

				yume_assert_same( array( $d['r1'] ), array_column( donnees_kpi( 30, true )['retards'], 'tome_id' ), 'indicateurs : Raven seulement' );

				wp_set_current_user( $d['calumi'] );
				$_GET = array( 'vue' => 'taches' );
				$html = yume_render_block( 'yume/team-dashboard' );
				yume_assert_contains( 'Œuvre en pause</span>', $html, 'pastille sur la tâche' );
				yume_assert_contains( '1 en retard', $html, 'Mes tâches : Raven seulement' );
				$_GET = array();
				$html = yume_render_block( 'yume/team-dashboard' );
				yume_assert_contains( 'Œuvre en pause</span>', $html );

				// Retour « En cours de publication » : retards et rappels reviennent.
				changer_etat_oeuvre( $d['grimgar'], 'en-cours', $d['editeur'] );
				yume_assert_same( 'en_retard', yume_planning_etat( $d['g1'] ) );
				$rapport = executer_rappels();
				yume_assert_true( in_array( $d['g1'], array_column( $rapport['rappels'], 'tome_id' ), true ), 'Grimgar T1 rappelé' );
				yume_assert_same( array( $d['g2'] ), array_column( $rapport['signalements'], 'tome_id' ), 'tome bloqué signalé de nouveau' );
				$html = yume_render_block( 'yume/team-dashboard' );
				yume_assert_not_contains( 'Œuvre en pause', $html );
			}
		);
	}
);

yume_toet_test(
	'Terminée, abandonnée, licenciée : pas de retard non plus (yume_oeuvre_sans_rappels) ; caches vidés au changement d’état',
	static function () {
		$d = yume_toet_jeu_planning();
		yume_toet_a(
			static function () use ( $d ) {
				foreach ( array( 'terminee', 'abandonnee' ) as $etat ) {
					changer_etat_oeuvre( $d['raven'], $etat, $d['editeur'] );
					yume_assert_true( yume_oeuvre_sans_rappels( $d['raven'] ), $etat );
					yume_assert_same( 'a_lheure', yume_planning_etat( $d['r1'] ), $etat );
				}
				changer_etat_oeuvre( $d['raven'], 'en-cours', $d['editeur'] );
				yume_assert_same( 'en_retard', yume_planning_etat( $d['r1'] ) );

				set_transient( 'yume_planning_ics', array( 0 => 'ancien' ), HOUR_IN_SECONDS );
				set_transient( 'yume_kpi_30', array( 'ancien' ), HOUR_IN_SECONDS );
				// Une donnée de la bibliothèque a été lue : l'invalidation suivante renouvelle aussitôt la version.
				$cache                          = &\Yume\Core\Library\etat_cache();
				$cache['lu']                    = true;
				$version                        = (string) get_option( 'yume_bibliotheque_cache' );
				$GLOBALS['yume_tests_batcache'] = array();
				changer_etat_oeuvre( $d['raven'], 'en-pause', $d['editeur'] );
				yume_assert_false( get_transient( 'yume_planning_ics' ), 'ICS vidé' );
				yume_assert_false( get_transient( 'yume_kpi_30' ), 'indicateurs vidés' );
				yume_assert_true( (string) get_option( 'yume_bibliotheque_cache' ) !== $version, 'cache de la bibliothèque renouvelé' );
				yume_assert_true( in_array( yume_url_page( 'planning' ), $GLOBALS['yume_tests_batcache'], true ), 'page du planning purgée' );
				yume_assert_true( in_array( (string) get_permalink( $d['raven'] ), $GLOBALS['yume_tests_batcache'], true ), 'fiche de l’œuvre purgée' );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Liste « Œuvres » : tomes, suggestion, filtre, formulaire
 * -----------------------------------------------------------------------------
 */

yume_toet_test(
	'Liste « Œuvres » : tomes regroupés par état (« T1 à T6 ✓ Publiés »), une requête pour tous les tomes, état modifiable sur la ligne, légende',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$raven   = yume_toet_oeuvre( 'Raven of the Inner Palace' );
		for ( $n = 1; $n <= 6; $n++ ) {
			yume_toet_tome( $raven, $n );
		}
		yume_toet_tome( $raven, 7, 'publish', array( 'yume_parution' => 'en_cours' ) );
		yume_toet_tome( $raven, 8, 'draft' );
		yume_toet_tome( $raven, 9, 'draft' );
		$vide = yume_toet_oeuvre( 'Roshidere', 'licenciee' );

		$etats = etats_tomes( tomes_des_oeuvres( array( $raven ) )[ $raven ] );
		yume_assert_same( array( 'T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9' ), array_column( $etats, 'court' ) );
		$groupes = groupes_tomes( $etats );
		yume_assert_same(
			array(
				array( 'T1', 'T6', 6, 'complet' ),
				array( 'T7', 'T7', 1, 'en_cours' ),
				array( 'T8', 'T9', 2, 'a_paraitre' ),
			),
			array_map( static fn( array $g ): array => array( $g['debut'], $g['fin'], $g['nb'], $g['etat'] ), $groupes )
		);
		$puces = puces_tomes_oeuvre( $etats );
		yume_assert_contains( 'T1 à T6 <span aria-hidden="true">✓</span> Publiés', $puces );
		yume_assert_contains( 'T7 <span aria-hidden="true">●</span> En cours de publication', $puces );
		yume_assert_contains( 'T8 à T9 <span aria-hidden="true">○</span> Planifiés', $puces );

		// Nombre de requêtes indépendant du nombre de tomes.
		global $wpdb;
		wp_cache_flush();
		$avant = $wpdb->num_queries;
		oeuvres_equipe();
		$avec_neuf = $wpdb->num_queries - $avant;
		for ( $n = 10; $n <= 15; $n++ ) {
			yume_toet_tome( $raven, $n, 'draft' );
		}
		wp_cache_flush();
		$avant = $wpdb->num_queries;
		$liste = oeuvres_equipe();
		yume_assert_same( $avec_neuf, $wpdb->num_queries - $avant, 'pas de requête par tome' );
		yume_assert_same( 15, $liste[0]['tomes'] );

		wp_set_current_user( $editeur );
		$_GET = array( 'vue' => 'oeuvres' );
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'name="action" value="yume_oeuvre_etat"', $html );
		yume_assert_contains( 'name="_yume_nonce" value="' . wp_create_nonce( 'yume_oeuvre_etat_' . $raven ) . '"', $html );
		yume_assert_contains( '<option value="en-cours" selected=\'selected\'>En cours de publication</option>', $html );
		yume_assert_contains( '<option value="licenciee" selected=\'selected\'>Licenciée</option>', $html, 'état de Roshidere' );
		yume_assert_contains( '>Changer<span class="yn-visually-hidden"> : Raven of the Inner Palace</span>', $html );
		yume_assert_contains( 'Aucun tome', $html, 'Roshidere sans tome' );
		yume_assert_contains( 'id="yn-oeuvres-legende"', $html );
		yume_assert_contains( 'lecture en ligne et liens PDF/EPUB retirés (réversible)', $html );
		yume_assert_same( 1, substr_count( $html, 'id="yn-oeuvre-' . $vide . '"' ) );

		// Sans edit_post : pastille seulement.
		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		$bloc = \Yume\Core\Planning\bloc_etat_ligne( $liste[0] );
		yume_assert_not_contains( '<select', $bloc );
		yume_assert_contains( 'En cours de publication</span>', $bloc );
	}
);

yume_toet_test(
	'Suggestion « passer à Terminée » : VO terminée et autant de tomes publiés, jamais automatique ; filtre « État »',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		$raven   = yume_toet_oeuvre(
			'Raven',
			'en-cours',
			array(
				'yume_nb_tomes_vo' => 2,
				'yume_statut_vo'   => 'termine',
			)
		);
		$gimai   = yume_toet_oeuvre( 'Gimai', 'en-pause' );
		yume_toet_tome( $raven, 1 );
		$t2 = yume_toet_tome( $raven, 2, 'draft' );

		$suggestion = static function () use ( $raven ): bool {
			foreach ( oeuvres_equipe() as $o ) {
				if ( $o['id'] === $raven ) {
					return $o['suggestion'];
				}
			}
			return false;
		};
		yume_assert_false( $suggestion(), 'un seul tome publié sur deux' );
		wp_update_post(
			array(
				'ID'          => $t2,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $t2, 'yume_parution', 'complet' );
		yume_assert_true( $suggestion() );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $raven, 'yume_statut', array( 'fields' => 'slugs' ) ), 'jamais automatique' );

		wp_set_current_user( $editeur );
		$_GET = array( 'vue' => 'oeuvres' );
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Tous les tomes de la VO sont publiés :', $html );
		yume_assert_contains( 'changer=' . $raven . '&#038;vers=terminee#yn-oeuvre-etat', $html );

		$_GET = array(
			'vue'     => 'oeuvres',
			'changer' => (string) $raven,
			'vers'    => 'terminee',
		);
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Passer « Raven » à « Terminée » ?', $html );
		yume_assert_contains( 'Plus de retard, de rappel ni d’alerte de planning', $html );

		changer_etat_oeuvre( $raven, 'terminee', $editeur );
		yume_assert_false( $suggestion(), 'déjà terminée' );

		// Filtre « État ».
		yume_assert_same( array( 'Gimai' ), wp_list_pluck( oeuvres_equipe( array( 'etat' => 'en-pause' ) ), 'titre' ) );
		yume_assert_same( array( 'Raven' ), wp_list_pluck( oeuvres_equipe( array( 'etat' => 'terminee' ) ), 'titre' ) );
		$_GET = array(
			'vue'  => 'oeuvres',
			'etat' => 'en-pause',
		);
		$html = rendu_vue_oeuvres();
		yume_assert_contains( '<option value="en-pause" selected=\'selected\'>En pause</option>', $html );
		yume_assert_contains( '1 œuvre', $html );
		yume_assert_contains( 'Effacer les filtres', $html );
		yume_assert_not_contains( 'id="yn-oeuvre-' . $raven . '"', $html );
	}
);

yume_toet_test(
	'Formulaire « Modifier l’œuvre » : champ « État de l’œuvre », même fonction de changement (confirmation pour « Licenciée »)',
	static function () {
		$d = yume_toet_jeu_licence();
		wp_set_current_user( $d['editeur'] );
		$_GET = array(
			'vue'      => 'oeuvres',
			'modifier' => (string) $d['oeuvre'],
		);
		$html = rendu_vue_oeuvres();
		yume_assert_contains( '>État de l’œuvre</label>', $html );
		yume_assert_contains( '<option value="en-cours" selected=\'selected\'>En cours de publication</option>', $html );
		yume_assert_not_contains( 'Statut de la traduction', $html );

		$valeurs = valeurs_oeuvre( $d['oeuvre'] );
		$envoi   = array(
			'action'           => 'yume_oeuvre_modifier',
			'oeuvre_id'        => (string) $d['oeuvre'],
			'_yume_nonce'      => wp_create_nonce( 'yume_oeuvre_modifier_' . $d['oeuvre'] ),
			'titre'            => 'SukaMoka (LN)',
			'avancement'       => 'licenciee',
			'synopsis'         => $valeurs['synopsis'],
			'synopsis_origine' => $valeurs['synopsis_origine'],
		);
		$r       = traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $d['editeur'] );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( $d['oeuvre'], $r['changer'] );
		yume_assert_same( 'licenciee', $r['vers'] );
		yume_assert_contains( 'confirmez-le', $r['message'] );
		yume_assert_same( 'SukaMoka (LN)', get_post_field( 'post_title', $d['oeuvre'] ), 'fiche enregistrée' );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ), 'état en attente de confirmation' );
		yume_assert_same( 'publish', get_post_status( $d['c1'] ) );

		$envoi['avancement'] = 'en-pause';
		$r                   = traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $d['editeur'] );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'est maintenant « En pause »', $r['message'] );
		yume_assert_same( array( 'en-pause' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( 1, count( lire_journal( array( 'champs' => array( 'etat_oeuvre' ) ) ) ), 'journalisé par changer_etat_oeuvre()' );

		$envoi['avancement'] = '';
		traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $d['editeur'] );
		yume_assert_same( array( 'en-pause' ), wp_get_object_terms( $d['oeuvre'], 'yume_statut', array( 'fields' => 'slugs' ) ), 'état vide : inchangé' );
	}
);
