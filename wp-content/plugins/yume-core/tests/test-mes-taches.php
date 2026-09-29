<?php
/**
 * Tests de la vue « Mes tâches » de l'espace équipe (?vue=taches, includes/planning/
 * mes-taches.php) : droits, contenu (tâches du membre seulement, en retard d'abord, pastille de
 * retards), état vide, formulaire d'avancement sans JavaScript qui revient sur la vue avec son
 * message, navigation (entrée courante, lien depuis le tableau de bord et le formulaire de
 * publication) et lien des rappels de retard.
 *
 * Lancement : tools/localenv/test.sh mes-taches
 *
 * @package Yume\Core
 */

use function Yume\Core\Planning\admin_post_maj;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

/**
 * Test isolé des contenus existants (œuvres, tomes, chapitres vidés dans la transaction du test).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tmt_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$serveur = $_SERVER;
			try {
				$corps();
			} finally {
				$_GET     = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$_SERVER  = $serveur;
				$_POST    = array();
				$_REQUEST = array();
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Tome du planning (brouillon, sans notification) avec ses responsables.
 *
 * @param int    $oeuvre       Œuvre.
 * @param int    $numero       Numéro.
 * @param array  $responsables Étape => membre.
 * @param string $date_cible   Date cible (Y-m-d) ou ''.
 * @param string $etape        Étape en cours.
 */
function yume_tmt_tome( int $oeuvre, int $numero, array $responsables, string $date_cible = '', string $etape = 'traduction' ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre ) . ' — Tome ' . $numero,
				'post_status' => 'draft',
				'meta_input'  => array(
					'yume_oeuvre_id'    => $oeuvre,
					'yume_numero'       => $numero,
					'yume_nature'       => 'tome',
					'yume_etape'        => $etape,
					'yume_date_cible'   => $date_cible,
					'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
					'yume_avancement'   => array(
						'traduction' => 40,
						'relecture'  => 0,
						'edition'    => 0,
					),
					'yume_responsables' => array_merge(
						array(
							'traduction' => 0,
							'relecture'  => 0,
							'edition'    => 0,
						),
						$responsables
					),
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Jeu : Calumi (traducteur) responsable de Grimgar T.1 (à l'heure) et T.2 (en retard),
 * Sora (traducteur) de Raven T.1.
 *
 * @return array<string,int>
 */
function yume_tmt_jeu(): array {
	$d            = array();
	$d['calumi']  = yume_factory_user( 'yume_traducteur' );
	$d['sora']    = yume_factory_user( 'yume_traducteur' );
	$d['grimgar'] = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Grimgar',
		)
	);
	$d['raven']   = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Raven',
		)
	);
	$d['g1']      = yume_tmt_tome( $d['grimgar'], 1, array( 'traduction' => $d['calumi'] ), gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) );
	$d['g2']      = yume_tmt_tome( $d['grimgar'], 2, array( 'traduction' => $d['calumi'] ), gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ) );
	$d['r1']      = yume_tmt_tome( $d['raven'], 1, array( 'traduction' => $d['sora'] ) );
	return $d;
}

/**
 * Rend l'espace équipe avec des paramètres GET, en tant qu'utilisateur.
 *
 * @param int   $user_id Utilisateur.
 * @param array $get     Paramètres GET.
 */
function yume_tmt_rendu( int $user_id, array $get = array() ): string {
	$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return yume_render_block( 'yume/team-dashboard' );
}

/**
 * Entrée « Mes tâches » de la navigation : [href, attributs, texte].
 *
 * @param string $html HTML.
 * @return array{0:string,1:string,2:string}
 */
function yume_tmt_entree( string $html ): array {
	preg_match( '#<nav class="yn-team__nav".*?</nav>#s', $html, $nav );
	preg_match( '#<li><a href="([^"]*)"([^>]*)>(Mes tâches.*?)</a></li>#s', $nav[0] ?? '', $m );
	return array( html_entity_decode( $m[1] ?? '', ENT_QUOTES, 'UTF-8' ), $m[2] ?? '', $m[3] ?? '' );
}

yume_tmt_test(
	'mes-taches : vue ?vue=taches — mes tâches seulement, en retard d’abord, pastille de retards, entrée courante',
	function () {
		$d    = yume_tmt_jeu();
		$html = yume_tmt_rendu( $d['calumi'], array( 'vue' => 'taches' ) );
		yume_assert_contains( 'class="yn-team yn-team--vue yn-team--taches', $html );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Mes tâches</h2>', $html );
		yume_assert_contains( '>2 tâches en cours</h2>', $html );
		yume_assert_contains( 'id="yn-tache-' . $d['g1'] . '"', $html );
		yume_assert_contains( 'id="yn-tache-' . $d['g2'] . '"', $html );
		yume_assert_not_contains( 'id="yn-tache-' . $d['r1'] . '"', $html, 'pas la tâche d’un autre' );
		yume_assert_true( strpos( $html, 'id="yn-tache-' . $d['g2'] ) < strpos( $html, 'id="yn-tache-' . $d['g1'] ), 'en retard d’abord' );
		yume_assert_contains( 'yn-team__tache--retard', $html );
		yume_assert_contains( '<span class="yn-chip yn-chip--warn">1 en retard</span>', $html );
		// Mêmes formulaires que le tableau de bord : admin-post + nonce + curseur.
		yume_assert_contains( 'name="action" value="yume_planning_maj"', $html );
		yume_assert_contains( 'name="_yume_nonce"', $html );
		yume_assert_contains( 'name="avancement[traduction]"', $html );

		$entree = yume_tmt_entree( $html );
		yume_assert_same( url_vue_equipe( 'taches' ), $entree[0] );
		yume_assert_same( ' aria-current="page"', $entree[1], 'entrée courante' );
		yume_assert_contains( '1 en retard', $entree[2], 'pastille des retards conservée' );
		yume_assert_same( 1, substr_count( $html, 'aria-current' ) );
		yume_assert_not_contains( 'id="yn-team-chiffres"', $html, 'pas le tableau de bord' );
	}
);

yume_tmt_test(
	'mes-taches : état vide explicite ; réservé à l’équipe (lecteur refusé, visiteur renvoyé à la connexion)',
	function () {
		$d    = yume_tmt_jeu();
		$vide = yume_tmt_rendu( yume_factory_user( 'yume_editeur' ), array( 'vue' => 'taches' ) );
		yume_assert_contains( '>Aucune tâche en cours</h2>', $vide );
		yume_assert_contains( 'Aucune tâche ne vous est attribuée pour le moment', $vide );
		yume_assert_contains( esc_url( url_vue_equipe( 'planning' ) ) . '">Voir le planning complet', $vide );
		yume_assert_not_contains( 'id="yn-tache-', $vide );

		$lecteur = yume_tmt_rendu( yume_factory_user( 'subscriber' ), array( 'vue' => 'taches' ) );
		yume_assert_contains( 'Espace réservé à l’équipe', $lecteur );
		yume_assert_not_contains( 'yn-tache-', $lecteur );
		$visiteur = yume_tmt_rendu( 0, array( 'vue' => 'taches' ) );
		yume_assert_contains( 'Se connecter', $visiteur );
		yume_assert_not_contains( 'yn-tache-', $visiteur );
		unset( $d );
	}
);

yume_tmt_test(
	'mes-taches : formulaire d’avancement sans JavaScript — enregistré, retour sur ?vue=taches, message dans la carte',
	function () {
		$d    = yume_tmt_jeu();
		$vue  = url_vue_equipe( 'taches' );
		$chem = (string) wp_parse_url( $vue, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $vue, PHP_URL_QUERY );
		// Page affichée : le champ _wp_http_referer du nonce pointe sur la vue.
		$_SERVER['REQUEST_URI'] = $chem;
		$html                   = yume_tmt_rendu( $d['calumi'], array( 'vue' => 'taches' ) );
		yume_assert_contains( 'name="_wp_http_referer" value="' . esc_attr( $chem ) . '"', $html );

		wp_set_current_user( $d['calumi'] );
		$_POST                  = array(
			'action'           => 'yume_planning_maj',
			'tome_id'          => (string) $d['g1'],
			'ancre'            => 'yn-tache-' . $d['g1'],
			'_yume_nonce'      => wp_create_nonce( 'yume_planning_maj_' . $d['g1'] ),
			'_wp_http_referer' => $chem,
			'avancement'       => array( 'traduction' => '75' ),
		);
		$_REQUEST               = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- simulation de l’envoi ; nonce vérifié par admin_post_maj().
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';
		$cible                  = '';
		$redirige               = static function ( $url ) use ( &$cible ) {
			$cible = (string) $url;
			throw new RuntimeException( 'redirection' );
		};
		add_filter( 'wp_redirect', $redirige, 1 );
		try {
			admin_post_maj();
		} catch ( RuntimeException $e ) {
			yume_assert_same( 'redirection', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirige, 1 );
		}
		yume_assert_same( 75, (int) get_post_meta( $d['g1'], 'yume_avancement', true )['traduction'], 'avancement enregistré' );
		yume_assert_contains( 'vue=taches', $cible, 'retour sur la vue' );
		yume_assert_contains( 'yume_planning=ok', $cible );
		yume_assert_true( str_ends_with( $cible, '#yn-tache-' . $d['g1'] ), 'ancre de la carte' );

		$apres = yume_tmt_rendu(
			$d['calumi'],
			array(
				'vue'           => 'taches',
				'yume_planning' => 'ok',
			)
		);
		yume_assert_true( 1 === preg_match( '#id="yn-tache-' . $d['g1'] . '".*?<p class="yn-team__retour yn-team__retour--ok"[^>]*>[^<]+</p>#s', $apres ), 'message dans la carte' );
		yume_assert_contains( 'value="75"', $apres );
	}
);

yume_tmt_test(
	'mes-taches : « Mes tâches » mène à ?vue=taches depuis le tableau de bord, les autres vues et le formulaire de publication ',
	function () {
		$d = yume_tmt_jeu();
		wp_set_current_user( $d['calumi'] );
		foreach ( array( 'tableau', 'planning', 'journal' ) as $actif ) {
			$entree = yume_tmt_entree( navigation_equipe( $actif ) );
			yume_assert_same( url_vue_equipe( 'taches' ), $entree[0], $actif );
			yume_assert_same( '', $entree[1], $actif . ' : pas courante' );
		}
		$tableau = yume_tmt_rendu( $d['calumi'] );
		yume_assert_not_contains( '#yn-mes-taches"', $tableau, 'plus d’ancre sans effet' );
		yume_assert_contains( 'id="yn-mes-taches"', $tableau, 'la section reste sur le tableau de bord' );

		wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
		$_GET = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		yume_assert_same( url_vue_equipe( 'taches' ), yume_tmt_entree( yume_render_block( 'yume/publish-form' ) )[0] );

		// Rappel de retard au responsable : voir test-planning (« vue=taches#yn-tache- »).
	}
);
