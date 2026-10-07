<?php
/**
 * Tests de la recette « planning et Discord » : la page Planning ne garde, parmi les tomes
 * publiés, que ceux parus aujourd'hui (heure de Paris) et ceux encore en cours de publication ;
 * annonces Discord des chapitres sur un troisième canal (salon des sorties, salon dédié ou
 * aucun), état et test dans « Santé du site », validation des réglages.
 *
 * Lancement : tools/localenv/test.sh planning-discord
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Core\assainir_reglages;
use function Yume\Core\Core\etat_webhooks;
use function Yume\Core\Core\test_sante_webhooks;
use function Yume\Core\Planning\webhook;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tpd_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé des œuvres, tomes et chapitres déjà présents (vidés dans la
 * transaction du test), « maintenant » fixé au mercredi 7 octobre 2026 à 12 h (Paris) pour le
 * module planning, réglages remis en place ensuite.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tpd_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			wp_cache_flush();
			$reglages   = get_option( 'yume_reglages', array() );
			$maintenant = static function () {
				return yume_tpd_ts( '2026-10-07 12:00' );
			};
			add_filter( 'yume_planning_maintenant', $maintenant );
			try {
				$corps();
			} finally {
				remove_filter( 'yume_planning_maintenant', $maintenant );
				update_option( 'yume_reglages', $reglages );
			}
		}
	);
}

/**
 * Horodatage d'une date-heure de Paris « Y-m-d H:i ».
 *
 * @param string $paris Date-heure, heure de Paris.
 */
function yume_tpd_ts( string $paris ): int {
	return ( new DateTimeImmutable( $paris, new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp();
}

/**
 * Œuvre publiée.
 *
 * @param string $titre Titre.
 */
function yume_tpd_oeuvre( string $titre ): int {
	return yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => 'publish',
		)
	);
}

/**
 * Tome du planning (sans notification). $sortie : date-heure de Paris de sa sortie (tome
 * publié, étape « publié ») ; vide : tome à paraître (brouillon en traduction).
 *
 * @param int    $oeuvre Œuvre.
 * @param int    $numero Numéro.
 * @param string $sortie Sortie (Paris) ou ''.
 * @param array  $meta   Métas supplémentaires.
 */
function yume_tpd_tome( int $oeuvre, int $numero, string $sortie = '', array $meta = array() ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		$gmt = '' !== $sortie ? gmdate( 'Y-m-d H:i:s', yume_tpd_ts( $sortie ) ) : '';
		return yume_factory_post(
			array(
				'post_type'     => 'yume_tome',
				'post_title'    => get_the_title( $oeuvre ) . ' — Tome ' . $numero,
				'post_status'   => '' !== $sortie ? 'publish' : 'draft',
				'post_date_gmt' => '' !== $gmt ? $gmt : gmdate( 'Y-m-d H:i:s' ),
				'post_date'     => '' !== $gmt ? get_date_from_gmt( $gmt ) : current_time( 'mysql' ),
				'meta_input'    => array_merge(
					array(
						'yume_oeuvre_id'    => $oeuvre,
						'yume_numero'       => $numero,
						'yume_nature'       => 'tome',
						'yume_etape'        => '' !== $sortie ? 'publie' : 'traduction',
						'yume_derniere_maj' => '' !== $gmt ? $gmt : gmdate( 'Y-m-d H:i:s' ),
						'yume_date_cible'   => '' !== $sortie ? substr( $sortie, 0, 10 ) : '2026-10-20',
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
 * Libellés « œuvre · tome » des lignes.
 *
 * @param array $lignes Lignes du planning.
 * @return string[]
 */
function yume_tpd_libelles( array $lignes ): array {
	$sortie = array_map(
		static function ( array $l ): string {
			return $l['oeuvre'] . ' · ' . $l['tome'];
		},
		$lignes
	);
	sort( $sortie );
	return $sortie;
}

/**
 * Exécute $corps en capturant les requêtes HTTP sortantes (réponse 204 simulée).
 *
 * @param callable $corps Corps.
 * @return string[] URL appelées.
 */
function yume_tpd_http( callable $corps ): array {
	$urls   = array();
	$filtre = static function ( $pre, $args, $url ) use ( &$urls ) {
		$urls[] = (string) $url;
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 204,
				'message' => 'No Content',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $filtre, 1, 3 );
	try {
		$corps();
	} finally {
		remove_filter( 'pre_http_request', $filtre, 1 );
	}
	return $urls;
}

/**
 * Règle les webhooks Discord.
 *
 * @param array $valeurs Réglages.
 */
function yume_tpd_reglages( array $valeurs ): void {
	$actuels = get_option( 'yume_reglages', array() );
	update_option( 'yume_reglages', array_merge( is_array( $actuels ) ? $actuels : array(), $valeurs ) );
}

/*
 * -----------------------------------------------------------------------------
 * Page Planning : parus aujourd'hui, à venir, en cours de publication
 * -----------------------------------------------------------------------------
 */

yume_tpd_test(
	'page Planning : un tome paru la veille (ou avant) disparaît ; à venir, paru aujourd’hui (dès minuit, heure de Paris) et en cours de publication restent',
	function () {
		$grimgar = yume_tpd_oeuvre( 'Grimgar' );
		$witch   = yume_tpd_oeuvre( 'Silent Witch' );
		yume_tpd_tome( $grimgar, 9, '2026-09-29 12:00' );
		yume_tpd_tome( $grimgar, 10, '2026-10-06 23:30' );
		yume_tpd_tome( $grimgar, 11, '2026-10-07 00:30' );
		yume_tpd_tome( $grimgar, 12 );
		yume_tpd_tome( $witch, 7, '2026-10-01 18:00', array( 'yume_parution' => 'en_cours' ) );

		$page = yume_get_planning( array( 'publies_du_jour' => true ) );
		yume_assert_same(
			array( 'Grimgar · Tome 11', 'Grimgar · Tome 12', 'Silent Witch · Tome 7' ),
			yume_tpd_libelles( $page ),
			'T.9 (29 sept.) et T.10 (hier 23 h 30) masqués ; T.11 (aujourd’hui 0 h 30, encore la veille en UTC) gardé'
		);

		// Le rendu public suit la même règle.
		$html = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'Tome 11', $html );
		yume_assert_contains( 'Tome 12', $html );
		yume_assert_contains( 'Tome 7', $html );
		yume_assert_not_contains( 'Tome 10', $html );
		yume_assert_not_contains( 'Tome 9', $html );

		// Ailleurs (REST, calendrier) : comportement inchangé, publiés des 14 derniers jours.
		yume_assert_same(
			array( 'Grimgar · Tome 10', 'Grimgar · Tome 11', 'Grimgar · Tome 12', 'Grimgar · Tome 9', 'Silent Witch · Tome 7' ),
			yume_tpd_libelles( yume_get_planning() )
		);
		// « À venir » (accueil) : jamais de tome publié, même en cours de publication.
		yume_assert_same(
			array( 'Grimgar · Tome 12' ),
			yume_tpd_libelles(
				yume_get_planning(
					array(
						'a_venir'         => true,
						'publies_du_jour' => true,
					)
				)
			)
		);
	}
);

yume_tpd_test(
	'page Planning : un tome en cours de publication depuis longtemps (étape « publié », dernière mise à jour ancienne) reste affiché',
	function () {
		$oeuvre = yume_tpd_oeuvre( 'SukaMoka' );
		yume_tpd_tome( $oeuvre, 2, '2026-08-01 18:00', array( 'yume_parution' => 'en_cours' ) );
		yume_tpd_tome( $oeuvre, 1, '2026-08-01 18:00', array( 'yume_parution' => 'complet' ) );
		yume_assert_same( array( 'SukaMoka · Tome 2' ), yume_tpd_libelles( yume_get_planning( array( 'publies_du_jour' => true ) ) ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Discord : canal des chapitres
 * -----------------------------------------------------------------------------
 */

yume_tpd_test(
	'Discord : annonce d’un chapitre dans le salon des sorties (défaut), un salon dédié, le salon des sorties si le dédié est vide, ou nulle part ; les tomes restent dans le salon des sorties',
	function () {
		$sorties   = 'https://discord.com/api/webhooks/1/sorties';
		$chapitres = 'https://discord.com/api/webhooks/3/chapitres';
		$oeuvre    = yume_tpd_oeuvre( 'SukaMoka' );
		$tome      = yume_tpd_tome( $oeuvre, 2, '2026-09-20 18:00', array( 'yume_parution' => 'en_cours' ) );
		$chapitre  = yume_factory_post(
			array(
				'post_type'   => 'yume_chapitre',
				'post_title'  => 'Chapitre 3',
				'post_status' => 'publish',
				'meta_input'  => array(
					'yume_tome_id' => $tome,
					'yume_numero'  => 3,
				),
			)
		);
		$annoncer  = static function () use ( $chapitre ) {
			do_action( 'yume_chapitre_publie', $chapitre );
		};

		yume_tpd_reglages(
			array(
				'discord_webhook_sorties'   => $sorties,
				'discord_webhook_chapitres' => $chapitres,
			)
		);
		$opts = get_option( 'yume_reglages' );
		unset( $opts['discord_chapitres'] );
		update_option( 'yume_reglages', $opts );
		yume_assert_same( array( $sorties ), yume_tpd_http( $annoncer ), 'défaut : salon des sorties' );

		yume_tpd_reglages( array( 'discord_chapitres' => 'dedie' ) );
		yume_assert_same( array( $chapitres ), yume_tpd_http( $annoncer ), 'salon dédié' );
		yume_assert_same( $sorties, webhook( 'sorties' ), 'les tomes restent dans le salon des sorties' );

		yume_tpd_reglages( array( 'discord_webhook_chapitres' => '' ) );
		yume_assert_same( array( $sorties ), yume_tpd_http( $annoncer ), 'salon dédié sans webhook : salon des sorties' );

		yume_tpd_reglages(
			array(
				'discord_chapitres'         => 'aucun',
				'discord_webhook_chapitres' => $chapitres,
			)
		);
		yume_assert_same( array(), yume_tpd_http( $annoncer ), 'aucune annonce de chapitre' );
		yume_assert_same( $sorties, webhook( 'sorties' ) );
	}
);

yume_tpd_test(
	'Discord : réglages (choix et webhook des chapitres validés), « Santé du site » (état du canal des chapitres, désactivé n’est pas un oubli, test possible)',
	function () {
		$sorties = 'https://discord.com/api/webhooks/1/sorties';
		$equipe  = 'https://discord.com/api/webhooks/2/equipe';
		update_option(
			'yume_reglages',
			assainir_reglages(
				array(
					'discord_webhook_sorties'   => $sorties,
					'discord_webhook_equipe'    => $equipe,
					'discord_chapitres'         => 'nimporte',
					'discord_webhook_chapitres' => 'https://exemple.org/pas-discord',
				)
			)
		);
		yume_assert_same( 'sorties', yume_setting( 'discord_chapitres' ), 'choix inconnu : défaut' );
		yume_assert_same( '', (string) yume_setting( 'discord_webhook_chapitres' ), 'adresse hors Discord refusée' );

		$etat = etat_webhooks();
		yume_assert_same( array( 'sorties', 'chapitres', 'equipe' ), array_keys( $etat ) );
		yume_assert_true( $etat['chapitres']['configure'] && $etat['chapitres']['partage'], 'chapitres : salon des sorties' );
		yume_assert_same( 'good', test_sante_webhooks()['status'] );

		yume_tpd_reglages( array( 'discord_chapitres' => 'aucun' ) );
		$etat = etat_webhooks();
		yume_assert_true( $etat['chapitres']['desactive'] && ! $etat['chapitres']['configure'] );
		yume_assert_same( 'good', test_sante_webhooks()['status'], 'désactivé au choix : pas un oubli' );

		yume_tpd_reglages(
			array(
				'discord_chapitres'         => 'dedie',
				'discord_webhook_chapitres' => 'https://discord.com/api/webhooks/3/chapitres',
			)
		);
		$etat = etat_webhooks();
		yume_assert_true( $etat['chapitres']['configure'] && ! $etat['chapitres']['partage'] );
		yume_assert_same( 'discord.com', $etat['chapitres']['hote'] );
		$admin = yume_factory_user( 'administrator' );
		$urls  = yume_tpd_http(
			static function () use ( $admin ) {
				wp_set_current_user( $admin );
				$post = array(
					'canal'    => 'chapitres',
					'_wpnonce' => wp_create_nonce( \Yume\Core\Core\ACTION_TEST_WEBHOOK ),
				);
				yume_assert_same( 'ok', \Yume\Core\Core\traiter_test_webhook( $post, $admin ) );
			}
		);
		yume_assert_same( array( 'https://discord.com/api/webhooks/3/chapitres' ), $urls, 'test du canal des chapitres' );

		// Champs proposés dans les réglages (espace équipe et administration).
		$cles = wp_list_pluck( \Yume\Core\Core\champs_reglages(), 'key' );
		yume_assert_true( in_array( 'discord_chapitres', $cles, true ) && in_array( 'discord_webhook_chapitres', $cles, true ) );
	}
);
