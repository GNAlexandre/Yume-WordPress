<?php
/**
 * Tests du lot « journal / planning et modération » (BUG-10, AMEL-09, AMEL-10) :
 *
 * - pause d'un tome (« Mettre en pause » / « Reprendre ») : droits, journal de l'équipe, sortie
 *   des retards et des rappels, badge « En pause » de l'espace équipe, rien en public ;
 * - planning complet : tri « en retard d'abord », export CSV (en-têtes, BOM, injection de
 *   formules neutralisée, droits) ;
 * - rappels plafonnés (premier rappel, relance, puis hebdomadaire ; plus rien au-delà du
 *   plafond : récapitulatif seulement), à heure simulée ;
 * - signalement d'un commentaire (REST : authentification, doublon, seuil → attente, débit) ;
 * - vue « Commentaires » de l'espace équipe (droits, actions) ; bouton « Signaler » et badge
 *   « Équipe » du thème.
 *
 * Lancement : tools/localenv/test.sh moderation
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\basculer_pause;
use function Yume\Core\Planning\cellule_csv;
use function Yume\Core\Planning\controler_export;
use function Yume\Core\Planning\csv_planning;
use function Yume\Core\Planning\envoyer_digest;
use function Yume\Core\Planning\est_en_pause;
use function Yume\Core\Planning\executer_rappels;
use function Yume\Core\Planning\grouper_journal;
use function Yume\Core\Planning\lignes_vue_planning;
use function Yume\Core\Planning\lire_journal;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\table_notifications;
use function Yume\Core\Planning\traiter_formulaire_pause;
use function Yume\Core\Planning\url_export_planning;
use function Yume\Core\Planning\vues_equipe_ajoutees;
use function Yume\Core\Social\signalements_actifs;
use function Yume\Core\Social\traiter_moderation;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tm_)
 * -----------------------------------------------------------------------------
 */

/** Maintenant simulé : jeudi 24 septembre 2026, 12 h à Paris. */
const YUME_TM_MAINTENANT = 1790244000;

/**
 * Déclare un test isolé des contenus existants (œuvres, tomes, chapitres, journal, file
 * d'e-mails, commentaires vidés dans la transaction du test, annulée ensuite par le lanceur).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tm_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->comments}" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta}" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_notifications() ); // phpcs:ignore
			delete_option( 'yume_planning_dernier_digest' );
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
 * Exécute un corps à heure simulée (module planning).
 *
 * @param callable $corps Corps.
 * @param int      $ts    Horodatage.
 * @return mixed
 */
function yume_tm_a( callable $corps, int $ts = YUME_TM_MAINTENANT ) {
	$filtre = static fn(): int => $ts;
	add_filter( 'yume_planning_maintenant', $filtre );
	try {
		return $corps();
	} finally {
		remove_filter( 'yume_planning_maintenant', $filtre );
	}
}

/**
 * Crée un compte avec un pseudo.
 *
 * @param string $role Rôle.
 * @param string $nom  Pseudo.
 */
function yume_tm_membre( string $role, string $nom ): int {
	$id = yume_factory_user( $role );
	wp_update_user(
		array(
			'ID'              => $id,
			'display_name'    => $nom,
			// Compte inscrit depuis un mois : ses signalements comptent pour le seuil.
			'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
		)
	);
	return $id;
}

/**
 * Crée une œuvre publiée.
 *
 * @param string $titre Titre.
 */
function yume_tm_oeuvre( string $titre ): int {
	return yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => $titre,
		)
	);
}

/**
 * Crée un tome brouillon (sans notification) avec ses méta de planning.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $numero    Numéro.
 * @param array  $meta      Méta.
 * @param string $titre    Titre (sinon « Œuvre — Tome N »).
 */
function yume_tm_tome( int $oeuvre_id, int $numero, array $meta = array(), string $titre = '' ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => '' !== $titre ? $titre : get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
				'post_status' => 'draft',
				'meta_input'  => array_merge(
					array(
						'yume_oeuvre_id'    => $oeuvre_id,
						'yume_numero'       => $numero,
						'yume_nature'       => 'tome',
						'yume_etape'        => 'traduction',
						'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s', YUME_TM_MAINTENANT - DAY_IN_SECONDS ),
					),
					$meta
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Jeu de données du planning : Calumi (traducteur), Pizz (éditeur) ; Grimgar T.1 en retard de
 * 3 jours (date cible), T.2 en retard de 20 jours, T.3 à l'heure, Raven T.1 en retard de
 * 60 jours (au-delà du plafond de 8 semaines).
 *
 * @return array<string,int>
 */
function yume_tm_jeu(): array {
	$d            = array();
	$d['calumi']  = yume_tm_membre( 'yume_traducteur', 'Calumi' );
	$d['editeur'] = yume_tm_membre( 'yume_editeur', 'Pizz' );
	$d['lecteur'] = yume_tm_membre( 'subscriber', 'Kaede' );
	$d['grimgar'] = yume_tm_oeuvre( 'Grimgar' );
	$d['raven']   = yume_tm_oeuvre( 'Raven' );
	$resp         = array(
		'traduction' => $d['calumi'],
		'relecture'  => 0,
		'edition'    => 0,
	);
	$jour         = static fn( int $n ): string => gmdate( 'Y-m-d', YUME_TM_MAINTENANT + $n * DAY_IN_SECONDS );
	$d['g1']      = yume_tm_tome(
		$d['grimgar'],
		1,
		array(
			'yume_date_cible'   => $jour( -3 ),
			'yume_responsables' => $resp,
		)
	);
	$d['g2']      = yume_tm_tome(
		$d['grimgar'],
		2,
		array(
			'yume_date_cible'   => $jour( -20 ),
			'yume_responsables' => $resp,
		)
	);
	$d['g3']      = yume_tm_tome(
		$d['grimgar'],
		3,
		array(
			'yume_date_cible'   => $jour( 30 ),
			'yume_responsables' => $resp,
		)
	);
	$d['r1']      = yume_tm_tome(
		$d['raven'],
		1,
		array(
			'yume_date_cible'   => $jour( -60 ),
			'yume_responsables' => $resp,
		)
	);
	return $d;
}

/**
 * Tomes rappelés par executer_rappels() à un instant donné.
 *
 * @param int $ts Horodatage.
 * @return int[]
 */
function yume_tm_rappeles( int $ts ): array {
	$ids = yume_tm_a( static fn() => array_column( executer_rappels()['rappels'], 'tome_id' ), $ts );
	sort( $ids );
	return $ids;
}

/**
 * Rend l'espace équipe avec des paramètres GET, en tant qu'utilisateur (heure simulée).
 *
 * @param int   $user_id Utilisateur.
 * @param array $get     Paramètres GET.
 */
function yume_tm_rendu( int $user_id, array $get = array() ): string {
	$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return yume_tm_a( static fn() => yume_render_block( 'yume/team-dashboard' ) );
}

/**
 * Article publié avec un commentaire publié.
 *
 * @param int    $auteur  Compte auteur du commentaire (0 : visiteur).
 * @param string $statut  Statut du commentaire ('1' ou '0').
 * @param string $texte   Texte.
 * @return array{post:int,commentaire:int}
 */
function yume_tm_commentaire( int $auteur = 0, string $statut = '1', string $texte = 'Super chapitre !' ): array {
	static $post = 0;
	if ( ! $post || ! get_post( $post ) ) {
		$post = yume_factory_post(
			array(
				'post_title'     => 'Annonce de sortie',
				'comment_status' => 'open',
			)
		);
	}
	$user = $auteur ? get_userdata( $auteur ) : null;
	$id   = wp_insert_comment(
		array(
			'comment_post_ID'      => $post,
			'comment_content'      => $texte,
			'comment_author'       => $user ? $user->display_name : 'Visiteur',
			'comment_author_email' => $user ? $user->user_email : 'visiteur@example.test',
			'user_id'              => $auteur,
			'comment_approved'     => $statut,
		)
	);
	return array(
		'post'        => $post,
		'commentaire' => (int) $id,
	);
}

/**
 * POST /yume/v1/commentaires/{id}/signalement.
 *
 * @param int    $id      Commentaire.
 * @param int    $user_id Compte.
 * @param string $motif   Motif.
 */
function yume_tm_signaler( int $id, int $user_id, string $motif = '' ): WP_REST_Response {
	return yume_rest( 'POST', '/yume/v1/commentaires/' . $id . '/signalement', array( 'motif' => $motif ), $user_id );
}

/*
 * -----------------------------------------------------------------------------
 * Pause d'un tome
 * -----------------------------------------------------------------------------
 */

yume_tm_test(
	'pause : droits, journal de l’équipe (« a mis en pause » / « a repris »), sortie des retards, reprise datée',
	function () {
		yume_tm_a(
			function () {
				$d = yume_tm_jeu();
				yume_assert_same( 'en_retard', yume_planning_etat( $d['g1'] ) );

				$refus = basculer_pause( $d['g1'], true, $d['calumi'] );
				yume_assert_true( is_wp_error( $refus ), 'traducteur : refusé' );
				yume_assert_same( 403, $refus->get_error_data()['status'] );
				yume_assert_false( est_en_pause( $d['g1'] ) );

				$r = basculer_pause( $d['g1'], true, $d['editeur'] );
				yume_assert_same( true, $r['changement'] );
				yume_assert_true( est_en_pause( $d['g1'] ) );
				$meta = get_post_meta( $d['g1'], 'yume_pause', true );
				yume_assert_same( gmdate( 'Y-m-d H:i:s', YUME_TM_MAINTENANT ), $meta['depuis'] );
				yume_assert_same( $d['editeur'], (int) $meta['par'] );
				yume_assert_same( 'a_lheure', yume_planning_etat( $d['g1'] ), 'plus en retard' );
				yume_assert_same( false, basculer_pause( $d['g1'], true, $d['editeur'] )['changement'], 'déjà en pause' );

				$lignes = lire_journal( array( 'tome_id' => $d['g1'] ) );
				yume_assert_same( 'pause', $lignes[0]->champ );
				yume_assert_same( '0', (string) $lignes[0]->public, 'jamais public' );
				$entree = grouper_journal( $lignes, true )[0];
				yume_assert_same( array( 'a mis en pause' ), $entree['parties'] );
				yume_assert_same(
					array(),
					grouper_journal(
						lire_journal(
							array(
								'tome_id' => $d['g1'],
								'public'  => true,
							)
						),
						false
					),
					'rien dans le journal public'
				);

				// Reprise : datée comme une mise à jour, le tome n'est plus en retard « sans nouvelles ».
				yume_tm_a(
					function () use ( $d ) {
						basculer_pause( $d['g1'], false, $d['editeur'] );
					},
					YUME_TM_MAINTENANT + 3600
				);
				yume_assert_false( est_en_pause( $d['g1'] ) );
				yume_assert_same( '', get_post_meta( $d['g1'], 'yume_pause', true ) );
				yume_assert_same( gmdate( 'Y-m-d H:i:s', YUME_TM_MAINTENANT + 3600 ), get_post_meta( $d['g1'], 'yume_derniere_maj', true ) );
				yume_assert_same( 'en_retard', yume_planning_etat( $d['g1'] ), 'la date cible reste dépassée' );
				$entree = grouper_journal( lire_journal( array( 'tome_id' => $d['g1'] ) ), true )[0];
				yume_assert_same( array( 'a repris' ), $entree['parties'] );

				// Un tome publié ne se met pas en pause.
				update_post_meta( $d['g3'], 'yume_etape', 'publie' );
				yume_assert_same( 409, basculer_pause( $d['g3'], true, $d['editeur'] )->get_error_data()['status'] );
			}
		);
	}
);

yume_tm_test(
	'pause : caches du calendrier ICS et des indicateurs vidés à la pause et à la reprise',
	function () {
		yume_tm_a(
			function () {
				$d = yume_tm_jeu();
				foreach ( array( true, false ) as $pause ) {
					set_transient( 'yume_planning_ics', array( 0 => 'ancien' ), HOUR_IN_SECONDS );
					set_transient( 'yume_kpi_30', array( 'ancien' ), HOUR_IN_SECONDS );
					basculer_pause( $d['g1'], $pause, $d['editeur'] );
					yume_assert_false( get_transient( 'yume_planning_ics' ), 'ICS vidé' );
					yume_assert_false( get_transient( 'yume_kpi_30' ), 'indicateurs vidés' );
				}
				// Sans changement (déjà repris) : rien n'est émis, le cache reste.
				set_transient( 'yume_kpi_30', array( 'ancien' ), HOUR_IN_SECONDS );
				basculer_pause( $d['g1'], false, $d['editeur'] );
				yume_assert_same( array( 'ancien' ), get_transient( 'yume_kpi_30' ) );
			}
		);
	}
);

yume_tm_test(
	'pause : formulaire admin-post (nonce), badge « En pause » et « Reprendre » dans le planning complet, rien en public',
	function () {
		$d    = yume_tm_jeu();
		$post = static fn( int $tome, string $pause, string $nonce = '' ): array => array(
			'action'      => 'yume_planning_pause',
			'tome_id'     => (string) $tome,
			'pause'       => $pause,
			'_yume_nonce' => '' !== $nonce ? $nonce : wp_create_nonce( 'yume_planning_pause_' . $tome ),
		);
		wp_set_current_user( $d['editeur'] );
		$r    = traiter_formulaire_pause( $post( $d['g2'], '1', 'x' ), $d['editeur'] );
		yume_assert_same( 'erreur', $r['type'], 'nonce invalide' );
		yume_assert_false( est_en_pause( $d['g2'] ) );
		$r = yume_tm_a( static fn() => traiter_formulaire_pause( $post( $d['g2'], '1' ), $d['editeur'] ) );
		yume_assert_same( 'ok', $r['type'] );
		yume_assert_same( 'yn-tome-' . $d['g2'], $r['cible'] );
		yume_assert_contains( 'est en pause', $r['message'] );
		yume_assert_true( est_en_pause( $d['g2'] ) );

		wp_set_current_user( $d['calumi'] );
		$r = traiter_formulaire_pause( $post( $d['g2'], '0' ), $d['calumi'] );
		yume_assert_same( 'erreur', $r['type'], 'traducteur : refusé' );
		yume_assert_true( est_en_pause( $d['g2'] ) );

		$html = yume_tm_rendu( $d['editeur'], array( 'vue' => 'planning' ) );
		yume_assert_same( 1, substr_count( $html, 'yn-team__pause"' ), 'un badge' );
		yume_assert_contains( '</span> En pause</span>', $html );
		yume_assert_contains( 'name="action" value="yume_planning_pause"', $html );
		yume_assert_contains( '>Reprendre<span class="yn-visually-hidden"> : Grimgar · Tome 2', $html );
		yume_assert_contains( '>Mettre en pause<span class="yn-visually-hidden"> : Grimgar · Tome 1', $html );
		yume_assert_contains( 'name="_yume_nonce" value="' . wp_create_nonce( 'yume_planning_pause_' . $d['g2'] ) . '"', $html );

		// Traducteur : badge visible, pas de bouton ; tableau de bord : badge sur sa tâche.
		$html = yume_tm_rendu( $d['calumi'], array( 'vue' => 'planning' ) );
		yume_assert_contains( '</span> En pause</span>', $html );
		yume_assert_not_contains( 'yume_planning_pause', $html );
		$html = yume_tm_rendu( $d['calumi'] );
		yume_assert_contains( '</span> En pause</span>', $html, 'Mes tâches' );

		// Public : ni badge, ni méta.
		wp_set_current_user( 0 );
		$public = yume_tm_a( static fn() => wp_json_encode( yume_get_planning( array( 'public' => true ) ) ) );
		yume_assert_not_contains( 'pause', $public );
		$rest = yume_rest( 'GET', '/wp/v2/tomes/' . $d['g2'], array( 'context' => 'view' ), 0 );
		yume_assert_not_contains( 'yume_pause', (string) wp_json_encode( $rest->get_data() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Tri et export CSV
 * -----------------------------------------------------------------------------
 */

yume_tm_test(
	'planning complet : tri « en retard d’abord » (du plus ancien au plus récent), option du filtre',
	function () {
		$d      = yume_tm_jeu();
		$ordre  = yume_tm_a( static fn() => array_map( 'intval', array_column( lignes_vue_planning( array( 'tri' => 'retard' ) ), 'tome_id' ) ) );
		$retard = array_slice( $ordre, 0, 3 );
		yume_assert_same( array( $d['r1'], $d['g2'], $d['g1'] ), $retard );
		yume_assert_same( $d['g3'], $ordre[3] );
		yume_tm_a( static fn() => basculer_pause( $d['r1'], true, $d['editeur'] ) );
		$ordre = yume_tm_a( static fn() => array_map( 'intval', array_column( lignes_vue_planning( array( 'tri' => 'retard' ) ), 'tome_id' ) ) );
		yume_assert_same( array( $d['g2'], $d['g1'] ), array_slice( $ordre, 0, 2 ), 'tome en pause : plus en tête' );

		$html = yume_tm_rendu(
			$d['editeur'],
			array(
				'vue' => 'planning',
				'tri' => 'retard',
			)
		);
		yume_assert_contains( '<option value="retard" selected=\'selected\'>En retard d’abord</option>', $html );
		yume_assert_true( strpos( $html, 'id="yn-tome-' . $d['g2'] . '"' ) < strpos( $html, 'id="yn-tome-' . $d['g3'] . '"' ) );
		yume_assert_contains( 'Exporter en CSV', $html );
		yume_assert_contains( 'tri=retard', html_entity_decode( $html ) );
	}
);

yume_tm_test(
	'export CSV : BOM, séparateur « ; », en-têtes, colonnes, formules neutralisées, droits et nonce',
	function () {
		$d     = yume_tm_jeu();
		$piege = yume_tm_oeuvre( '=HYPERLINK(1)' );
		yume_tm_tome( $piege, 1, array( 'yume_date_cible' => gmdate( 'Y-m-d', YUME_TM_MAINTENANT + 9 * DAY_IN_SECONDS ) ) );
		update_post_meta(
			$d['g3'],
			'yume_responsables',
			array(
				'traduction' => $d['calumi'],
				'relecture'  => $d['editeur'],
				'edition'    => 0,
			)
		);
		yume_tm_a( static fn() => basculer_pause( $d['g2'], true, $d['editeur'] ) );
		$csv = yume_tm_a( static fn() => csv_planning( lignes_vue_planning( array( 'oeuvre' => $d['grimgar'] ) ) ) );
		yume_assert_same( "\xEF\xBB\xBF", substr( $csv, 0, 3 ), 'BOM UTF-8' );
		$lignes = explode( "\n", trim( substr( $csv, 3 ) ) );
		yume_assert_same( 'Œuvre;Tome;Étape;Statut;Responsables;"Date cible";"Date programmée";"Dernière mise à jour";Retard', $lignes[0] );
		yume_assert_same( 4, count( $lignes ), 'en-tête et trois tomes de Grimgar' );
		yume_assert_contains( 'Grimgar;"Tome 1";Traduction;"En retard";"Traduction : Calumi";' . gmdate( 'Y-m-d', YUME_TM_MAINTENANT - 3 * DAY_IN_SECONDS ) . ';;', $csv );
		yume_assert_contains( ';"3 j (date cible dépassée)"', $csv );
		yume_assert_contains( '"En pause"', $csv );
		yume_assert_contains( '"Traduction : Calumi · Relecture : Pizz"', $csv );
		$piege_csv = yume_tm_a( static fn() => csv_planning( lignes_vue_planning( array( 'oeuvre' => $piege ) ) ) );
		yume_assert_contains( "\n'=HYPERLINK(1);\"Tome 1\"", $piege_csv, 'formule neutralisée' );
		yume_assert_not_contains( "\n=HYPERLINK", $piege_csv );

		foreach ( array( '=1+1', '+33', '-2', '@SUM(A1)', "\tx", "\rx" ) as $cellule ) {
			yume_assert_same( "'" . $cellule, cellule_csv( $cellule ), $cellule );
		}
		yume_assert_same( 'Grimgar', cellule_csv( 'Grimgar' ) );
		yume_assert_same( '2026-09-24', cellule_csv( '2026-09-24' ) );

		wp_set_current_user( 0 );
		yume_assert_true( is_wp_error( controler_export( wp_create_nonce( 'yume_planning_export' ) ) ), 'anonyme' );
		wp_set_current_user( $d['lecteur'] );
		yume_assert_same( 'yume_export_interdit', controler_export( wp_create_nonce( 'yume_planning_export' ) )->get_error_code(), 'lecteur' );
		wp_set_current_user( $d['calumi'] );
		yume_assert_same( 'yume_export_expire', controler_export( 'faux' )->get_error_code(), 'nonce invalide' );
		yume_assert_true( true === controler_export( wp_create_nonce( 'yume_planning_export' ) ), 'membre de l’équipe' );
		$url = url_export_planning(
			array(
				'oeuvre' => $d['grimgar'],
				'tri'    => 'retard',
				'etat'   => '',
			)
		);
		yume_assert_contains( 'admin-post.php?action=yume_planning_export', $url );
		yume_assert_contains( 'oeuvre=' . $d['grimgar'], $url );
		yume_assert_contains( '_wpnonce=' . wp_create_nonce( 'yume_planning_export' ), $url );
		yume_assert_not_contains( 'etat=', $url );
		yume_assert_true( has_action( 'admin_post_yume_planning_export' ) > 0 && has_action( 'admin_post_nopriv_yume_planning_export' ) > 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Rappels plafonnés
 * -----------------------------------------------------------------------------
 */

yume_tm_test(
	'rappels plafonnés : premier rappel, relance à 3 jours, puis hebdomadaire ; plafond de 8 semaines ; pause exclue',
	function () {
		$d = yume_tm_jeu();
		$j = static fn( float $n ): int => (int) ( YUME_TM_MAINTENANT + $n * DAY_IN_SECONDS );
		// Le tome à l'heure finirait « sans nouvelles » au fil des semaines simulées : hors du test.
		wp_delete_post( $d['g3'], true );
		yume_tm_a( static fn() => basculer_pause( $d['g2'], true, $d['editeur'] ) );

		yume_assert_same( array( $d['g1'] ), yume_tm_rappeles( $j( 0 ) ), 'premier rappel ; Raven T.1 (60 j) au-delà du plafond ; Grimgar T.2 en pause' );
		yume_assert_same( array(), yume_tm_rappeles( $j( 1 ) ), 'lendemain' );
		yume_assert_same( array( $d['g1'] ), yume_tm_rappeles( $j( 3 ) - 30 ), 'relance après 3 jours' );
		yume_assert_same( array(), yume_tm_rappeles( $j( 6 ) ), 'ensuite plus de rappel tous les 3 jours' );
		yume_assert_same( array(), yume_tm_rappeles( $j( 9 ) ), 'six jours après la relance' );
		yume_assert_same( array( $d['g1'] ), yume_tm_rappeles( $j( 10 ) - 30 ), 'une semaine après la relance' );
		yume_assert_same( array(), yume_tm_rappeles( $j( 14 ) ) );
		yume_assert_same( array( $d['g1'] ), yume_tm_rappeles( $j( 17 ) - 30 ), 'hebdomadaire' );

		// Une mise à jour du tome remet le compteur à zéro (relance de nouveau à 3 jours).
		update_post_meta( $d['g1'], 'yume_derniere_maj', gmdate( 'Y-m-d H:i:s', $j( 17 ) ) );
		yume_assert_same( array( $d['g1'] ), yume_tm_rappeles( $j( 24 ) - 30 ) );
		yume_assert_same( array( $d['g1'] ), yume_tm_rappeles( $j( 27 ) - 30 ), 'relance à 3 jours après une mise à jour' );

		// Au-delà de 8 semaines de retard (3 + 53 = 56 jours) : plus aucun rappel.
		yume_assert_same( array(), yume_tm_rappeles( $j( 53 ) ), 'plafond atteint' );

		// Plafond filtrable (0 : aucun plafond) ; le tome en pause reste exclu.
		add_filter( 'yume_rappels_plafond', '__return_zero' );
		try {
			yume_assert_same( array( $d['g1'], $d['r1'] ), yume_tm_rappeles( $j( 60 ) ) );
		} finally {
			remove_filter( 'yume_rappels_plafond', '__return_zero' );
		}
		yume_assert_same( 0, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . table_journal() . " WHERE champ = 'rappel' AND tome_id = %d", $d['g2'] ) ), 'aucun rappel pour le tome en pause' ); // phpcs:ignore
	}
);

yume_tm_test(
	'récapitulatif : le tome au-delà du plafond y reste (« plus de rappel automatique ») ; tome en pause hors des retards',
	function () {
		$d = yume_tm_jeu();
		yume_tm_membre( 'yume_gerant', 'Hikari' );
		yume_tm_a( static fn() => basculer_pause( $d['g2'], true, $d['editeur'] ) );
		$r = yume_tm_a( static fn() => envoyer_digest( true ) );
		yume_assert_true( $r['envoye'] );
		yume_assert_same( 2, $r['retards'], 'Grimgar T.1 et Raven T.1, pas le tome en pause' );
		global $wpdb;
		$html = (string) $wpdb->get_var( 'SELECT html FROM ' . table_notifications() . " WHERE contexte = 'digest' LIMIT 1" ); // phpcs:ignore
		yume_assert_contains( 'Raven — Tome 1</strong> · La date cible de Raven — Tome 1', $html );
		yume_assert_contains( 'Plus de rappel automatique', $html );
		yume_assert_same( 1, substr_count( $html, 'Plus de rappel automatique' ), 'seulement le tome plafonné' );
		yume_assert_not_contains( 'Grimgar — Tome 2', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Signalement d'un commentaire
 * -----------------------------------------------------------------------------
 */

yume_tm_test(
	'signalement : connexion requise, commentaire publié d’un autre, un par compte, seuil → attente de modération',
	function () {
		$auteur = yume_tm_membre( 'subscriber', 'Auteur' );
		$c      = yume_tm_commentaire( $auteur )['commentaire'];
		$l1     = yume_tm_membre( 'subscriber', 'L1' );
		$l2     = yume_tm_membre( 'subscriber', 'L2' );
		$l3     = yume_tm_membre( 'subscriber', 'L3' );

		yume_assert_same( 401, yume_tm_signaler( $c, 0 )->get_status(), 'anonyme' );
		yume_assert_same( 400, yume_tm_signaler( $c, $auteur )->get_status(), 'son propre commentaire' );
		yume_assert_same( 404, yume_tm_signaler( 999999, $l1 )->get_status(), 'inconnu' );
		$attente = yume_tm_commentaire( $auteur, '0' )['commentaire'];
		yume_assert_same( 404, yume_tm_signaler( $attente, $l1 )->get_status(), 'commentaire non publié' );
		yume_assert_same( 400, yume_tm_signaler( $c, $l1, str_repeat( 'x', 201 ) )->get_status(), 'motif trop long' );

		$r = yume_tm_signaler( $c, $l1, '  Divulgâcheur <b>tome 12</b> ' );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( false, $r->get_data()['attente'] );
		yume_assert_same( 'Divulgâcheur tome 12', signalements_actifs( $c )[ $l1 ]['motif'] );
		yume_assert_same( 'approved', wp_get_comment_status( $c ) );

		$doublon = yume_tm_signaler( $c, $l1 );
		yume_assert_same( 409, $doublon->get_status() );
		yume_assert_same( 'yume_deja_signale', $doublon->get_data()['code'] );
		yume_assert_same( 1, count( signalements_actifs( $c ) ) );

		yume_assert_same( 200, yume_tm_signaler( $c, $l2 )->get_status() );
		yume_assert_same( 'approved', wp_get_comment_status( $c ) );
		$r = yume_tm_signaler( $c, $l3 );
		yume_assert_same( true, $r->get_data()['attente'], 'troisième signalement' );
		yume_assert_same( 'unapproved', wp_get_comment_status( $c ), 'en attente de modération' );

		// Seuil filtrable.
		$c2 = yume_tm_commentaire( $auteur )['commentaire'];
		add_filter( 'yume_signalements_seuil', '__return_true' );
		try {
			yume_assert_same( true, yume_tm_signaler( $c2, $l1 )->get_data()['attente'], 'seuil à 1' );
		} finally {
			remove_filter( 'yume_signalements_seuil', '__return_true' );
		}
	}
);

yume_tm_test(
	'Sécurité : signalements multi-comptes — comptes de moins de 7 jours hors seuil, commentaire de l’équipe jamais masqué',
	function () {
		$auteur  = yume_tm_membre( 'subscriber', 'Auteur' );
		$c       = yume_tm_commentaire( $auteur )['commentaire'];
		$recents = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$recents[] = yume_factory_user();
		}
		foreach ( $recents as $recent ) {
			$r = yume_tm_signaler( $c, $recent );
			yume_assert_same( 200, $r->get_status() );
			yume_assert_same( false, $r->get_data()['attente'] );
		}
		yume_assert_same( 'approved', wp_get_comment_status( $c ), 'comptes récents : toujours publié' );
		yume_assert_same( 4, count( signalements_actifs( $c ) ), 'signalements gardés pour la modération' );
		// Il faut 3 comptes anciens (seuil par défaut) pour la mise en attente.
		yume_tm_signaler( $c, yume_tm_membre( 'subscriber', 'Ancien 1' ) );
		yume_tm_signaler( $c, yume_tm_membre( 'subscriber', 'Ancien 2' ) );
		yume_assert_same( 'approved', wp_get_comment_status( $c ) );
		yume_assert_same( true, yume_tm_signaler( $c, yume_tm_membre( 'subscriber', 'Ancien 3' ) )->get_data()['attente'] );
		yume_assert_same( 'unapproved', wp_get_comment_status( $c ) );

		// Ancienneté filtrable (0 : tous les comptes comptent).
		$c2 = yume_tm_commentaire( $auteur )['commentaire'];
		add_filter( 'yume_signalement_anciennete', '__return_zero' );
		try {
			yume_tm_signaler( $c2, $recents[0] );
			yume_tm_signaler( $c2, $recents[1] );
			yume_assert_same( true, yume_tm_signaler( $c2, $recents[2] )->get_data()['attente'] );
		} finally {
			remove_filter( 'yume_signalement_anciennete', '__return_zero' );
		}

		// Commentaire d'un membre de l'équipe : jamais en attente automatiquement, reste signalé.
		$membre = yume_tm_membre( 'yume_traducteur', 'Traductrice' );
		yume_assert_true( user_can( $membre, 'yume_voir_equipe' ) );
		$e = yume_tm_commentaire( $membre )['commentaire'];
		foreach ( array( 'A1', 'A2', 'A3', 'A4' ) as $nom ) {
			yume_assert_same( false, yume_tm_signaler( $e, yume_tm_membre( 'subscriber', $nom ) )->get_data()['attente'] );
		}
		yume_assert_same( 'approved', wp_get_comment_status( $e ) );
		yume_assert_same( 4, count( signalements_actifs( $e ) ) );
		$html = yume_tm_rendu( yume_tm_membre( 'yume_editeur', 'Éditrice' ), array( 'vue' => 'commentaires' ) );
		yume_assert_contains( 'id="yn-com-' . $e . '"', $html, 'visible dans la vue de modération' );
	}
);

yume_tm_test(
	'signalement : limite de débit par compte (429), nonce REST exigé pour la session du navigateur',
	function () {
		$auteur = yume_tm_membre( 'subscriber', 'Auteur' );
		$l1     = yume_tm_membre( 'subscriber', 'Pressé' );
		$ids    = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = yume_tm_commentaire( $auteur )['commentaire'];
		}
		$debit = static fn(): int => 2;
		add_filter( 'yume_signalements_debit', $debit );
		try {
			yume_assert_same( 200, yume_tm_signaler( $ids[0], $l1 )->get_status() );
			yume_assert_same( 200, yume_tm_signaler( $ids[1], $l1 )->get_status() );
			$r = yume_tm_signaler( $ids[2], $l1 );
			yume_assert_same( 429, $r->get_status() );
			yume_assert_same( 'yume_trop_de_signalements', $r->get_data()['code'] );
			yume_assert_same( array(), signalements_actifs( $ids[2] ) );
			$autre = yume_tm_membre( 'subscriber', 'Calme' );
			yume_assert_same( 200, yume_tm_signaler( $ids[2], $autre )->get_status(), 'limite propre à chaque compte' );
		} finally {
			remove_filter( 'yume_signalements_debit', $debit );
		}
		$routes = rest_get_server()->get_routes();
		yume_assert_true( isset( $routes['/yume/v1/commentaires/(?P<id>\d+)/signalement'] ) );
		yume_assert_same( 'Yume\Core\Social\permission_connecte', $routes['/yume/v1/commentaires/(?P<id>\d+)/signalement'][0]['permission_callback'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Vue « Commentaires »
 * -----------------------------------------------------------------------------
 */

yume_tm_test(
	'vue « Commentaires » : entrée « Commentaires (N) » pour moderate_comments seulement, listes signalés et en attente',
	function () {
		$editeur = yume_tm_membre( 'yume_editeur', 'Pizz' );
		$trad    = yume_tm_membre( 'yume_traducteur', 'Calumi' );
		$auteur  = yume_tm_membre( 'subscriber', 'Auteur' );

		wp_set_current_user( $editeur );
		yume_assert_false( isset( vues_equipe_ajoutees()['commentaires'] ), 'rien à modérer : pas d’entrée' );

		$attente = yume_tm_commentaire( 0, '0', 'Achetez des <b>montres</b>' )['commentaire'];
		$signale = yume_tm_commentaire( $auteur, '1', 'Le héros meurt au tome 12' )['commentaire'];
		yume_tm_signaler( $signale, yume_tm_membre( 'subscriber', 'L1' ), 'divulgâcheur' );

		wp_set_current_user( $editeur );
		$vues = vues_equipe_ajoutees();
		yume_assert_same( 'Commentaires (2)', $vues['commentaires']['libelle'] );
		wp_set_current_user( $trad );
		yume_assert_false( isset( vues_equipe_ajoutees()['commentaires'] ), 'traducteur : pas de modération' );
		$html = yume_tm_rendu( $trad, array( 'vue' => 'commentaires' ) );
		yume_assert_not_contains( 'Commentaires à modérer', $html );

		$html = yume_tm_rendu( $editeur, array( 'vue' => 'commentaires' ) );
		yume_assert_contains( 'Commentaires à modérer', $html );
		yume_assert_contains( 'vue=commentaires" aria-current="page">Commentaires (2)</a>', $html );
		yume_assert_contains( 'id="yn-com-' . $signale . '"', $html );
		yume_assert_contains( 'id="yn-com-' . $attente . '"', $html );
		yume_assert_true( strpos( $html, 'yn-moderation-signales' ) < strpos( $html, 'yn-moderation-attente' ) );
		yume_assert_contains( '1 signalement', $html );
		yume_assert_contains( 'L1 : « divulgâcheur »', $html );
		yume_assert_contains( 'Achetez des montres', $html, 'texte sans balise' );
		yume_assert_not_contains( '<b>montres', $html );
		yume_assert_contains( 'name="op" value="approuver"', $html );
		yume_assert_contains( 'name="op" value="ignorer"', $html );
		yume_assert_contains( 'name="op" value="indesirable"', $html );
		yume_assert_contains( 'name="op" value="corbeille"', $html );
		yume_assert_contains( 'name="_yume_nonce" value="' . wp_create_nonce( 'yume_moderation_' . $signale ) . '"', $html );
	}
);

yume_tm_test(
	'vue « Commentaires » : actions Approuver, Ignorer, Indésirable, Corbeille (nonce, droits)',
	function () {
		$editeur = yume_tm_membre( 'yume_gerant', 'Hikari' );
		$pizz    = yume_tm_membre( 'yume_editeur', 'Pizz' );
		$trad    = yume_tm_membre( 'yume_traducteur', 'Calumi' );
		$auteur  = yume_tm_membre( 'subscriber', 'Auteur' );
		$post    = static fn( int $id, string $op, string $nonce = '' ): array => array(
			'action'      => 'yume_moderation',
			'commentaire' => (string) $id,
			'op'          => $op,
			'_yume_nonce' => '' !== $nonce ? $nonce : wp_create_nonce( 'yume_moderation_' . $id ),
		);
		$a       = yume_tm_commentaire( $auteur, '0' )['commentaire'];
		$b       = yume_tm_commentaire( $auteur )['commentaire'];
		$c       = yume_tm_commentaire( $auteur )['commentaire'];
		$e       = yume_tm_commentaire( $auteur )['commentaire'];
		$l1      = yume_tm_membre( 'subscriber', 'L1' );
		yume_tm_signaler( $b, $l1 );

		wp_set_current_user( $trad );
		yume_assert_same( 'erreur', traiter_moderation( $post( $a, 'approuver' ), $trad )['type'], 'traducteur' );
		yume_assert_same( 'unapproved', wp_get_comment_status( $a ) );

		// Éditeur : moderate_comments, mais pas edit_comment sur l'article d'un autre compte.
		wp_set_current_user( $pizz );
		yume_assert_same( 'Vous ne pouvez pas modérer ce commentaire.', traiter_moderation( $post( $a, 'approuver' ), $pizz )['message'] );
		yume_assert_contains( 'Seul un compte qui peut modifier', yume_tm_rendu( $pizz, array( 'vue' => 'commentaires' ) ) );

		wp_set_current_user( $editeur );
		yume_assert_same( 'erreur', traiter_moderation( $post( $a, 'approuver', 'x' ), $editeur )['type'], 'nonce' );
		yume_assert_same( 'erreur', traiter_moderation( $post( $a, 'supprimer' ), $editeur )['type'], 'action inconnue' );
		$r = traiter_moderation( $post( $a, 'approuver' ), $editeur );
		yume_assert_same( 'ok', $r['type'] );
		yume_assert_same( 'Commentaire de Auteur approuvé.', $r['message'] );
		yume_assert_same( 'approved', wp_get_comment_status( $a ) );

		yume_assert_same( 'ok', traiter_moderation( $post( $b, 'ignorer' ), $editeur )['type'] );
		yume_assert_same( array(), signalements_actifs( $b ) );
		yume_assert_same( 'approved', wp_get_comment_status( $b ) );
		yume_assert_same( 409, yume_tm_signaler( $b, $l1 )->get_status(), 'signalement ignoré : pas de second signalement du même compte' );

		yume_assert_same( 'ok', traiter_moderation( $post( $c, 'indesirable' ), $editeur )['type'] );
		yume_assert_same( 'spam', wp_get_comment_status( $c ) );
		yume_assert_same( 'ok', traiter_moderation( $post( $e, 'corbeille' ), $editeur )['type'] );
		yume_assert_same( 'trash', wp_get_comment_status( $e ) );

		// Un commentaire repassé en attente par les signalements puis approuvé dans
		// l'administration : ses signalements sont traités.
		$f = yume_tm_commentaire( $auteur )['commentaire'];
		add_filter( 'yume_signalements_seuil', '__return_true' );
		try {
			yume_tm_signaler( $f, yume_tm_membre( 'subscriber', 'L2' ) );
		} finally {
			remove_filter( 'yume_signalements_seuil', '__return_true' );
		}
		yume_assert_same( 'unapproved', wp_get_comment_status( $f ) );
		wp_set_comment_status( $f, 'approve' );
		yume_assert_same( array(), signalements_actifs( $f ) );
		yume_assert_true( has_action( 'admin_post_yume_moderation' ) > 0 && has_action( 'admin_post_nopriv_yume_moderation' ) > 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Thème : bouton « Signaler » et badge « Équipe »
 * -----------------------------------------------------------------------------
 */

yume_tm_test(
	'thème : badge « Équipe » sur les commentaires de l’équipe, bouton « Signaler » pour les lecteurs connectés',
	function () {
		if ( ! function_exists( 'yume_theme_badge_equipe' ) ) {
			throw new Yume_Test_Failure( 'thème Yume inactif' );
		}
		$membre  = yume_tm_membre( 'yume_traducteur', 'Calumi' );
		$lecteur = yume_tm_membre( 'subscriber', 'Kaede' );
		$equipe  = yume_tm_commentaire( $membre )['commentaire'];
		$public  = yume_tm_commentaire( $lecteur )['commentaire'];
		$rendu   = static function ( string $bloc, int $id ): string {
			$b = new WP_Block(
				array(
					'blockName'    => $bloc,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
				array( 'commentId' => $id )
			);
			return $b->render();
		};
		yume_assert_contains( 'Calumi<span class="yn-commentaire__equipe">Équipe</span></div>', $rendu( 'core/comment-author-name', $equipe ) );
		yume_assert_not_contains( 'yn-commentaire__equipe', $rendu( 'core/comment-author-name', $public ) );

		wp_set_current_user( 0 );
		yume_assert_not_contains( 'data-yn-signaler', yume_theme_bouton_signaler( '', array(), new WP_Block( array( 'blockName' => 'core/comment-reply-link' ), array( 'commentId' => $equipe ) ) ) );
		wp_set_current_user( $lecteur );
		$html = yume_theme_bouton_signaler( '<div>Répondre</div>', array(), new WP_Block( array( 'blockName' => 'core/comment-reply-link' ), array( 'commentId' => $equipe ) ) );
		yume_assert_contains( 'data-yn-signaler="' . esc_url( rest_url( 'yume/v1/commentaires/' . $equipe . '/signalement' ) ) . '"', $html );
		yume_assert_contains( 'data-yn-nonce="' . wp_create_nonce( 'wp_rest' ) . '"', $html );
		yume_assert_contains( '<div class="yn-signaler" hidden', $html, 'masqué sans JavaScript' );
		yume_assert_contains( 'maxlength="200"', $html );
		yume_assert_true( wp_script_is( 'yume-commentaires', 'enqueued' ) );
		yume_assert_not_contains( 'data-yn-signaler', yume_theme_bouton_signaler( '', array(), new WP_Block( array( 'blockName' => 'core/comment-reply-link' ), array( 'commentId' => $public ) ) ), 'pas son propre commentaire' );
		wp_dequeue_script( 'yume-commentaires' );
		wp_dequeue_style( 'yume-commentaires' );
	}
);
