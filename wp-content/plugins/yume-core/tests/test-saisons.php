<?php
/**
 * Tests du module saisons (thèmes saisonniers, docs/07-themes-saisonniers.md) : définitions,
 * résolution par date (chaque saison, chevauchement du Nouvel An, priorité des fêtes, ligne
 * désactivée, calendrier vide, fuseau du site), modes manuel et aucun, aperçu ?yn-saison
 * (refusé à un anonyme ou à un lecteur, accepté avec yume_reglages, page exclue du cache),
 * mémoire par requête et filtre final, assainissement du calendrier, événement
 * yume_saison_changee et purge, phrase d'état, rendu du type de champ saisons (administration et
 * espace équipe), tâche planifiée quotidienne, réglage saison_sprite et arbre décoratif du thème
 * (docs/08-sprites-saisonniers.md).
 *
 * Lancement : tools/localenv/test.sh saisons
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Saisons\HOOK_QUOTIDIEN;
use const Yume\Core\Saisons\OPTION_COURANTE;
use const Yume\Core\Saisons\ORDRE;
use const Yume\Core\Saisons\PARAM_APERCU;
use function Yume\Core\Saisons\assainir_calendrier;
use function Yume\Core\Saisons\calendrier_par_defaut;
use function Yume\Core\Saisons\heure_conforme;
use function Yume\Core\Saisons\phrase_etat;
use function Yume\Core\Saisons\planifier;
use function Yume\Core\Saisons\reinitialiser;
use function Yume\Core\Saisons\verifier_changement;

// Batcache absent des tests : adresses purgées relevées (même simulation que test-oeuvres-etat).
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

if ( ! function_exists( 'batcache_cancel' ) ) {
	/**
	 * Relève l'exclusion de la page du cache de Batcache.
	 */
	function batcache_cancel(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- fonction de Batcache simulée.
		$GLOBALS['yume_tests_batcache_cancel'] = (int) ( $GLOBALS['yume_tests_batcache_cancel'] ?? 0 ) + 1;
	}
}

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tsa_)
 * -----------------------------------------------------------------------------
 */

/**
 * Date dans le fuseau du site.
 *
 * @param string $date « Y-m-d H:i ».
 */
function yume_tsa_date( string $date ): DateTimeImmutable {
	return new DateTimeImmutable( $date, wp_timezone() );
}

/**
 * Exécute $corps avec « maintenant » figé à une date (fuseau du site) et la saison oubliée
 * avant et après.
 *
 * @param string   $date  « Y-m-d H:i ».
 * @param callable $corps Corps.
 * @return mixed
 */
function yume_tsa_a_la_date( string $date, callable $corps ) {
	$ts    = yume_tsa_date( $date )->getTimestamp();
	$figer = static function () use ( $ts ): int {
		return $ts;
	};
	add_filter( 'yume_saison_maintenant', $figer );
	reinitialiser();
	try {
		return $corps();
	} finally {
		remove_filter( 'yume_saison_maintenant', $figer );
		reinitialiser();
	}
}

/**
 * Enregistre des réglages Yume (fusionnés avec les actuels).
 *
 * @param array<string,mixed> $valeurs Réglages.
 */
function yume_tsa_reglages( array $valeurs ): void {
	$actuels = get_option( 'yume_reglages', array() );
	update_option( 'yume_reglages', array_merge( is_array( $actuels ) ? $actuels : array(), $valeurs ) );
	reinitialiser();
}

/**
 * Champ saison_calendrier tel que déclaré dans les réglages.
 *
 * @return array<string,mixed>
 * @throws Yume_Test_Failure Champ absent.
 */
function yume_tsa_champ_calendrier(): array {
	foreach ( \Yume\Core\Core\champs_reglages() as $champ ) {
		if ( 'saison_calendrier' === $champ['key'] ) {
			return $champ;
		}
	}
	throw new Yume_Test_Failure( 'champ saison_calendrier absent' );
}

/*
 * -----------------------------------------------------------------------------
 * Définitions et module
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_saisons() : six slugs dans l’ordre de priorité, avec nom, type et logo ; module déclaré après planning',
	function () {
		$saisons = yume_saisons();
		yume_assert_same( array( 'noel', 'halloween', 'printemps', 'ete', 'automne', 'hiver' ), array_keys( $saisons ) );
		foreach ( $saisons as $slug => $definition ) {
			yume_assert_true( is_string( $definition['nom'] ) && '' !== $definition['nom'], "nom de $slug" );
			yume_assert_same( $slug . '.webp', $definition['logo'], "logo de $slug" );
			yume_assert_same( in_array( $slug, array( 'noel', 'halloween' ), true ) ? 'fete' : 'saison', $definition['type'], "type de $slug" );
			yume_assert_same( $definition['nom'], yume_saison_nom( $slug ) );
		}
		yume_assert_true( '' !== yume_saison_nom( '' ), 'nom du thème de base' );
		yume_assert_same( '', yume_saison_nom( 'inconnu' ) );
		$modules = YUME_CORE_MODULES;
		yume_assert_same( array_search( 'planning', $modules, true ) + 1, array_search( 'saisons', $modules, true ) );
	}
);

yume_test(
	'Calendrier par défaut : six lignes du cahier des charges, réglage absent → défauts',
	function () {
		$reglages = get_option( 'yume_reglages', array() );
		$reglages = is_array( $reglages ) ? $reglages : array();
		unset( $reglages['saison_calendrier'] );
		update_option( 'yume_reglages', $reglages );
		$calendrier = yume_saison_calendrier();
		yume_assert_same( calendrier_par_defaut(), $calendrier );
		yume_assert_same( ORDRE, array_column( $calendrier, 'theme' ) );
		yume_assert_same(
			array(
				'theme' => 'hiver',
				'debut' => '21/12',
				'fin'   => '19/03',
				'actif' => true,
			),
			$calendrier[5]
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Résolution par date
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_saison_pour_date : chaque saison, bornes incluses, Nouvel An pour l’hiver, fêtes prioritaires',
	function () {
		$cas        = array(
			'2026-03-19 12:00' => 'hiver',
			'2026-03-20 00:00' => 'printemps',
			'2026-04-15 12:00' => 'printemps',
			'2026-06-20 23:59' => 'printemps',
			'2026-06-21 00:00' => 'ete',
			'2026-07-15 12:00' => 'ete',
			'2026-09-21 12:00' => 'ete',
			'2026-09-22 12:00' => 'automne',
			'2026-09-30 23:59' => 'automne',
			'2026-10-01 00:00' => 'halloween',
			'2026-10-31 23:59' => 'halloween',
			'2026-11-01 00:00' => 'automne',
			'2026-11-30 12:00' => 'automne',
			'2026-12-01 00:00' => 'noel',
			'2026-12-21 12:00' => 'noel',
			'2026-12-31 23:59' => 'noel',
			'2027-01-01 00:00' => 'hiver',
			'2027-02-15 12:00' => 'hiver',
			'2028-02-29 12:00' => 'hiver',
		);
		$calendrier = calendrier_par_defaut();
		foreach ( $cas as $date => $attendu ) {
			yume_assert_same( $attendu, yume_saison_pour_date( yume_tsa_date( $date ), $calendrier ), $date );
		}
	}
);

yume_test(
	'yume_saison_pour_date : ligne désactivée sautée, calendrier vide ou invalide → thème de base',
	function () {
		$calendrier             = calendrier_par_defaut();
		$calendrier[1]['actif'] = false; // Halloween.
		yume_assert_same( 'automne', yume_saison_pour_date( yume_tsa_date( '2026-10-15 12:00' ), $calendrier ) );
		$calendrier[0]['actif'] = false; // Noël.
		yume_assert_same( 'hiver', yume_saison_pour_date( yume_tsa_date( '2026-12-25 12:00' ), $calendrier ) );
		yume_assert_same( 'automne', yume_saison_pour_date( yume_tsa_date( '2026-12-05 12:00' ), $calendrier ) );

		yume_assert_same( '', yume_saison_pour_date( yume_tsa_date( '2026-10-15 12:00' ), array() ) );
		$invalide = array(
			array(
				'theme' => 'automne',
				'debut' => '31/02',
				'fin'   => '20/12',
				'actif' => true,
			),
			array(
				'theme' => 'inconnu',
				'debut' => '01/01',
				'fin'   => '31/12',
				'actif' => true,
			),
		);
		yume_assert_same( '', yume_saison_pour_date( yume_tsa_date( '2026-10-15 12:00' ), $invalide ) );
		$seule = array(
			array(
				'theme' => 'ete',
				'debut' => '1/7',
				'fin'   => '31/8',
				'actif' => true,
			),
		);
		yume_assert_same( 'ete', yume_saison_pour_date( yume_tsa_date( '2026-08-31 12:00' ), $seule ) );
		yume_assert_same( '', yume_saison_pour_date( yume_tsa_date( '2026-09-01 12:00' ), $seule ) );
	}
);

yume_test(
	'yume_saison_pour_date : date convertie dans le fuseau du site (Europe/Paris)',
	function () {
		update_option( 'timezone_string', 'Europe/Paris' );
		update_option( 'gmt_offset', '' );
		$utc = new DateTimeImmutable( '2026-09-30 23:30', new DateTimeZone( 'UTC' ) ); // 01/10 01:30 à Paris.
		yume_assert_same( 'halloween', yume_saison_pour_date( $utc, calendrier_par_defaut() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Saison active : modes, aperçu, mémoire, filtre
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_saison_active : mode auto (calendrier du site), manuel et aucun',
	function () {
		yume_tsa_reglages(
			array(
				'saison_mode'       => 'auto',
				'saison_manuelle'   => '',
				'saison_calendrier' => calendrier_par_defaut(),
			)
		);
		yume_assert_same( 'noel', yume_tsa_a_la_date( '2026-12-25 12:00', 'yume_saison_active' ) );
		yume_assert_same( 'printemps', yume_tsa_a_la_date( '2026-04-01 12:00', 'yume_saison_active' ) );

		yume_tsa_reglages(
			array(
				'saison_mode'     => 'manuel',
				'saison_manuelle' => 'ete',
			)
		);
		yume_assert_same( 'ete', yume_tsa_a_la_date( '2026-12-25 12:00', 'yume_saison_active' ) );
		yume_tsa_reglages( array( 'saison_manuelle' => '' ) );
		yume_assert_same( '', yume_tsa_a_la_date( '2026-12-25 12:00', 'yume_saison_active' ) );

		yume_tsa_reglages(
			array(
				'saison_mode'     => 'aucun',
				'saison_manuelle' => 'ete',
			)
		);
		yume_assert_same( '', yume_tsa_a_la_date( '2026-12-25 12:00', 'yume_saison_active' ) );
	}
);

yume_test(
	'Aperçu ?yn-saison : ignoré pour un anonyme et un lecteur, honoré avec yume_reglages (page exclue du cache)',
	function () {
		$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		try {
			yume_tsa_reglages(
				array(
					'saison_mode'     => 'manuel',
					'saison_manuelle' => 'ete',
				)
			);
			$_GET[ PARAM_APERCU ]                  = 'noel';
			$GLOBALS['yume_tests_batcache_cancel'] = 0;

			wp_set_current_user( 0 );
			yume_assert_same( 'ete', yume_tsa_a_la_date( '2026-04-01 12:00', 'yume_saison_active' ), 'anonyme' );
			wp_set_current_user( yume_factory_user( 'subscriber' ) );
			yume_assert_same( 'ete', yume_tsa_a_la_date( '2026-04-01 12:00', 'yume_saison_active' ), 'lecteur' );
			yume_assert_same( 0, $GLOBALS['yume_tests_batcache_cancel'], 'cache de page conservé sans aperçu' );

			wp_set_current_user( yume_factory_user( 'administrator' ) );
			yume_assert_same( 'noel', yume_tsa_a_la_date( '2026-04-01 12:00', 'yume_saison_active' ), 'administrateur' );
			yume_assert_true( $GLOBALS['yume_tests_batcache_cancel'] > 0, 'batcache_cancel() appelé' );

			wp_set_current_user( yume_factory_user( 'yume_gerant' ) );
			$_GET[ PARAM_APERCU ] = 'aucun';
			yume_assert_same( '', yume_tsa_a_la_date( '2026-04-01 12:00', 'yume_saison_active' ), 'aperçu du thème de base' );
			$_GET[ PARAM_APERCU ] = 'inconnu';
			yume_assert_same( 'ete', yume_tsa_a_la_date( '2026-04-01 12:00', 'yume_saison_active' ), 'slug inconnu ignoré' );
		} finally {
			$_GET = $get;
			wp_set_current_user( 0 );
			reinitialiser();
		}
	}
);

yume_test(
	'yume_saison_active : résultat mémorisé pour la requête, filtre final yume_saison_active (slug inconnu → base)',
	function () {
		yume_tsa_reglages(
			array(
				'saison_mode'       => 'auto',
				'saison_calendrier' => calendrier_par_defaut(),
			)
		);
		$forcer = static function () {
			return 'hiver';
		};
		$faux   = static function () {
			return 'carnaval';
		};
		try {
			yume_tsa_a_la_date(
				'2026-07-01 12:00',
				function () use ( $forcer ) {
					yume_assert_same( 'ete', yume_saison_active() );
					add_filter( 'yume_saison_active', $forcer );
					yume_assert_same( 'ete', yume_saison_active(), 'valeur mémorisée' );
					reinitialiser();
					yume_assert_same( 'hiver', yume_saison_active(), 'filtre final' );
				}
			);
			remove_filter( 'yume_saison_active', $forcer );
			add_filter( 'yume_saison_active', $faux );
			yume_assert_same( '', yume_tsa_a_la_date( '2026-07-01 12:00', 'yume_saison_active' ) );
		} finally {
			remove_filter( 'yume_saison_active', $forcer );
			remove_filter( 'yume_saison_active', $faux );
			reinitialiser();
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Assainissement
 * -----------------------------------------------------------------------------
 */

yume_test(
	'assainir_calendrier : date invalide → précédente avec avertissement, thème inconnu ignoré, ordre figé',
	function () {
		if ( ! function_exists( 'add_settings_error' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$erreurs = $GLOBALS['wp_settings_errors'] ?? array();
		try {
			$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- avertissements de la page de réglages, remis en place.
			$sortie                        = assainir_calendrier(
				array(
					'hiver'   => array(
						'debut' => '32/13',
						'fin'   => '1/3',
						'actif' => '1',
					),
					'inconnu' => array(
						'debut' => '01/01',
						'fin'   => '02/01',
						'actif' => '1',
					),
					'noel'    => array(
						'debut' => '2.12',
						'fin'   => '24/12',
						'actif' => '0',
					),
				),
				calendrier_par_defaut()
			);
			yume_assert_same( ORDRE, array_column( $sortie, 'theme' ), 'six lignes, ordre de priorité' );
			yume_assert_same(
				array(
					'theme' => 'noel',
					'debut' => '02/12',
					'fin'   => '24/12',
					'actif' => false,
				),
				$sortie[0]
			);
			yume_assert_same(
				array(
					'theme' => 'hiver',
					'debut' => '21/12',
					'fin'   => '01/03',
					'actif' => true,
				),
				$sortie[5],
				'début invalide : valeur précédente'
			);
			yume_assert_same( calendrier_par_defaut()[2], $sortie[2], 'ligne non transmise : inchangée' );
			$codes = array_column( get_settings_errors( 'yume_reglages' ), 'code' );
			yume_assert_true( in_array( 'yume_saison_hiver_debut', $codes, true ), 'avertissement' );
			yume_assert_same( 1, count( $codes ) );

			// Forme liste (par programme), dans le désordre : ordre rétabli ; saisie non tableau : précédent.
			$liste = array_reverse( calendrier_par_defaut() );
			yume_assert_same( calendrier_par_defaut(), assainir_calendrier( $liste, calendrier_par_defaut() ) );
			yume_assert_same( calendrier_par_defaut(), assainir_calendrier( 'n’importe quoi', calendrier_par_defaut() ) );
			yume_assert_same( '29/02', assainir_calendrier( array( 'hiver' => array( 'fin' => '29/2' ) ), calendrier_par_defaut() )[5]['fin'] );
		} finally {
			$GLOBALS['wp_settings_errors'] = $erreurs; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remise en place.
		}
	}
);

yume_test(
	'Type de champ saisons : déclaré par le module, assainir_champ() délègue au callback (précédent = calendrier enregistré)',
	function () {
		$champ = yume_tsa_champ_calendrier();
		yume_assert_same( 'saisons', $champ['type'] );
		yume_assert_same( 'saisons', $champ['section'] );
		yume_assert_true( isset( \Yume\Core\Core\sections_reglages()['saisons'] ), 'section' );
		$cles = array_column( \Yume\Core\Core\champs_reglages(), 'key' );
		foreach ( array( 'saison_mode', 'saison_manuelle', 'saison_calendrier', 'saison_decor' ) as $cle ) {
			yume_assert_true( in_array( $cle, $cles, true ), $cle );
		}
		$defauts = \Yume\Core\Core\defauts_reglages();
		yume_assert_same( 'auto', $defauts['saison_mode'] );
		yume_assert_same( '', $defauts['saison_manuelle'] );
		yume_assert_same( true, $defauts['saison_decor'] );

		$enregistre             = calendrier_par_defaut();
		$enregistre[3]['debut'] = '15/06';
		yume_tsa_reglages( array( 'saison_calendrier' => $enregistre ) );
		$sortie = \Yume\Core\Core\assainir_champ( $champ, array( 'ete' => array( 'debut' => 'juin' ) ), array() );
		yume_assert_same( '15/06', $sortie[3]['debut'], 'valeur précédente : celle enregistrée' );

		// Sans callback, le core conserve la valeur précédente.
		unset( $champ['sanitize'] );
		yume_assert_same( array( 'x' ), \Yume\Core\Core\assainir_champ( $champ, array( 'ete' => array() ), array( 'x' ) ) );
	}
);

yume_test(
	'Réglage saison_sprite : case « Arbre décoratif » après « Décors animés », défaut vrai, assainie en booléen',
	function () {
		$champs = \Yume\Core\Core\champs_reglages();
		$cles   = array_column( $champs, 'key' );
		$index  = array_search( 'saison_sprite', $cles, true );
		yume_assert_true( false !== $index, 'champ déclaré' );
		yume_assert_same( array_search( 'saison_decor', $cles, true ) + 1, $index, 'juste après saison_decor' );
		$champ = $champs[ $index ];
		yume_assert_same( 'checkbox', $champ['type'] );
		yume_assert_same( 'saisons', $champ['section'] );
		yume_assert_same( true, $champ['default'] );
		yume_assert_true( '' !== (string) $champ['label'] && '' !== (string) $champ['description'], 'libellé et description' );
		yume_assert_same( true, \Yume\Core\Core\defauts_reglages()['saison_sprite'] );

		$reglages = get_option( 'yume_reglages', array() );
		$reglages = is_array( $reglages ) ? $reglages : array();
		unset( $reglages['saison_sprite'] );
		update_option( 'yume_reglages', $reglages );
		yume_assert_same( true, yume_setting( 'saison_sprite' ), 'réglage absent → défaut vrai' );

		yume_assert_same( false, \Yume\Core\Core\assainir_champ( $champ, '0', true ) );
		yume_assert_same( true, \Yume\Core\Core\assainir_champ( $champ, '1', false ) );
		$sortie = \Yume\Core\Core\assainir_reglages( array( '_formulaire' => 1 ) );
		yume_assert_same( false, $sortie['saison_sprite'], 'case décochée dans le formulaire → faux' );
		$sortie = \Yume\Core\Core\assainir_reglages( array( 'saison_sprite' => 'oui' ) );
		yume_assert_same( true, $sortie['saison_sprite'] );
	}
);

/**
 * Sortie de wp_body_open (arbre décoratif du thème) pour une requête principale donnée, avec la
 * saison forcée par le filtre yume_saison_active ; requête, saison et réglages remis en place.
 *
 * @param string   $saison  Saison forcée.
 * @param callable $requete Construit la requête principale (WP_Query).
 * @return array{html:string,classes:string[]} Sortie de wp_body_open et classes du body.
 */
function yume_tsa_rendu_sprite( string $saison, callable $requete ): array {
	global $wp_query, $wp_the_query;
	$sauve  = array( $wp_query, $wp_the_query );
	$forcer = static function () use ( $saison ) {
		return $saison;
	};
	add_filter( 'yume_saison_active', $forcer );
	reinitialiser();
	try {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- remises en place dans le finally.
		$wp_query     = $requete();
		$wp_the_query = $wp_query;
		// phpcs:enable
		ob_start();
		yume_theme_afficher_sprite();
		return array(
			'html'    => (string) ob_get_clean(),
			'classes' => yume_theme_classe_sprite( array() ),
		);
	} finally {
		list( $wp_query, $wp_the_query ) = $sauve; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- remise en place.
		remove_filter( 'yume_saison_active', $forcer );
		reinitialiser();
	}
}

yume_test(
	'Thème : arbre décoratif sur l’accueil (saison forcée, fichier présent), absent sur un chapitre, l’espace équipe, réglage coupé ou fichier manquant',
	function () {
		if ( ! function_exists( 'yume_theme_afficher_sprite' ) ) {
			return; // Thème Yume inactif.
		}
		yume_assert_true( has_action( 'wp_body_open', 'yume_theme_afficher_sprite' ) > 0, 'accroché à wp_body_open' );
		yume_tsa_reglages( array( 'saison_sprite' => true ) );
		$accueil = static function () {
			$requete          = new WP_Query();
			$requete->is_home = true;
			return $requete;
		};

		$rendu = yume_tsa_rendu_sprite( 'hiver', $accueil );
		yume_assert_contains( '<div class="yn-sprite" aria-hidden="true" style="', $rendu['html'] );
		yume_assert_contains( 'assets/img/sprites/arbre-hiver.webp?v=', $rendu['html'] );
		yume_assert_contains( 'arbre-hiver-2x.webp?v=', $rendu['html'] );
		yume_assert_contains( '--yn-sprite: image-set(', $rendu['html'] );
		yume_assert_contains( 'type(&quot;image/webp&quot;) 2x', $rendu['html'] );
		if ( file_exists( get_theme_file_path( 'assets/img/sprites/arbre-hiver.avif' ) ) ) {
			yume_assert_true( strpos( $rendu['html'], 'arbre-hiver.avif' ) < strpos( $rendu['html'], 'image/webp' ), 'AVIF avant WebP' );
		}
		yume_assert_same( array( 'yn-sprite-actif' ), $rendu['classes'] );

		// Chapitre (et lecteur MangaDex, qui s'affiche dans le chapitre) : aucun arbre.
		$chapitre = yume_factory_post(
			array(
				'post_type'   => 'yume_chapitre',
				'post_status' => 'publish',
				'post_title'  => 'Chapitre sans arbre',
			)
		);
		$rendu    = yume_tsa_rendu_sprite(
			'hiver',
			static function () use ( $chapitre ) {
				return new WP_Query(
					array(
						'p'           => $chapitre,
						'post_type'   => 'yume_chapitre',
						'post_status' => 'any',
					)
				);
			}
		);
		yume_assert_same( '', $rendu['html'], 'chapitre' );
		yume_assert_same( array(), $rendu['classes'], 'chapitre : pas de classe' );

		// Espace équipe : pages enregistrées sous les clés publier et equipe (autres slugs).
		$publier          = yume_factory_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Publier',
				'post_name'   => 'publier-test-sprite',
			)
		);
		$equipe           = yume_factory_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Équipe',
				'post_name'   => 'equipe-test-sprite',
			)
		);
		$publique         = yume_factory_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'À propos',
				'post_name'   => 'a-propos-test-sprite',
			)
		);
		$pages            = get_option( 'yume_pages', array() );
		$pages            = is_array( $pages ) ? $pages : array();
		$pages['publier'] = $publier;
		$pages['equipe']  = $equipe;
		update_option( 'yume_pages', $pages );
		$page = static function ( int $id ) {
			return static function () use ( $id ) {
				return new WP_Query( array( 'page_id' => $id ) );
			};
		};
		yume_assert_same( '', yume_tsa_rendu_sprite( 'hiver', $page( $publier ) )['html'], 'publier' );
		yume_assert_same( '', yume_tsa_rendu_sprite( 'hiver', $page( $equipe ) )['html'], 'équipe' );
		yume_assert_contains( 'class="yn-sprite"', yume_tsa_rendu_sprite( 'hiver', $page( $publique ) )['html'], 'page publique' );

		// Réglage coupé.
		yume_tsa_reglages( array( 'saison_sprite' => false ) );
		yume_assert_same( '', yume_tsa_rendu_sprite( 'hiver', $accueil )['html'], 'réglage faux' );
		yume_tsa_reglages( array( 'saison_sprite' => true ) );

		// Sans saison : rien.
		yume_assert_same( '', yume_tsa_rendu_sprite( '', $accueil )['html'], 'thème de base' );

		// Fichier manquant (l'arbre d'automne n'est pas encore livré) : aucun élément, donc aucun 404.
		$manquantes = array_filter(
			array_keys( yume_saisons() ),
			static function ( string $slug ): bool {
				return ! file_exists( get_theme_file_path( 'assets/img/sprites/arbre-' . $slug . '.webp' ) );
			}
		);
		foreach ( $manquantes as $slug ) {
			yume_assert_same( '', yume_tsa_rendu_sprite( $slug, $accueil )['html'], "fichier manquant : $slug" );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Événement et purge
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_saison_changee : émis avec (nouvelle, ancienne) par verifier_changement() et l’enregistrement des réglages, caches purgés',
	function () {
		$recus  = array();
		$ecoute = static function ( $nouvelle, $ancienne ) use ( &$recus ) {
			$recus[] = array( $nouvelle, $ancienne );
		};
		add_action( 'yume_saison_changee', $ecoute, 10, 2 );
		try {
			yume_tsa_reglages(
				array(
					'saison_mode'       => 'auto',
					'saison_calendrier' => calendrier_par_defaut(),
				)
			);
			update_option( OPTION_COURANTE, 'automne' );
			$recus                          = array();
			$GLOBALS['yume_tests_batcache'] = array();
			yume_tsa_a_la_date(
				'2026-12-05 00:05',
				function () use ( &$recus ) {
					yume_assert_true( verifier_changement(), 'changement détecté' );
					yume_assert_same( array( array( 'noel', 'automne' ) ), $recus );
					yume_assert_same( 'noel', get_option( OPTION_COURANTE ) );
					yume_assert_false( verifier_changement(), 'pas de second événement' );
					yume_assert_same( 1, count( $recus ) );
					yume_assert_true( in_array( home_url( '/' ), $GLOBALS['yume_tests_batcache'], true ), 'accueil purgé' );
					yume_assert_true( in_array( yume_url_page( 'planning' ), $GLOBALS['yume_tests_batcache'], true ), 'planning purgé' );

					// Réglage sans rapport : aucune vérification.
					yume_tsa_reglages( array( 'kofi_url' => 'https://ko-fi.com/autre' ) );
					yume_assert_same( 1, count( $recus ) );

					// Passage au thème de base par les réglages.
					yume_tsa_reglages( array( 'saison_mode' => 'aucun' ) );
					yume_assert_same( array( '', 'noel' ), $recus[1] ?? null );
					yume_assert_same( '', get_option( OPTION_COURANTE ) );
				}
			);
		} finally {
			remove_action( 'yume_saison_changee', $ecoute, 10 );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * État, rendu, tâche planifiée
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Phrase d’état : thème du jour, prochain changement et sa date selon le mode',
	function () {
		yume_tsa_reglages(
			array(
				'saison_mode'       => 'auto',
				'saison_calendrier' => calendrier_par_defaut(),
			)
		);
		$phrase = yume_tsa_a_la_date( '2026-10-30 12:00', 'Yume\Core\Saisons\phrase_etat' );
		yume_assert_contains( yume_saison_nom( 'halloween' ), $phrase );
		yume_assert_contains( yume_saison_nom( 'automne' ) . ' le 01/11', $phrase );
		$phrase = yume_tsa_a_la_date( '2026-12-25 12:00', 'Yume\Core\Saisons\phrase_etat' );
		yume_assert_contains( yume_saison_nom( 'hiver' ) . ' le 01/01', $phrase );

		yume_tsa_reglages(
			array(
				'saison_mode'     => 'manuel',
				'saison_manuelle' => 'ete',
			)
		);
		$phrase = phrase_etat();
		yume_assert_contains( yume_saison_nom( 'ete' ), $phrase );
		yume_assert_not_contains( ' le ', $phrase );
	}
);

yume_test(
	'Rendu du champ saisons : table accessible (administration et espace équipe), noms de champs, aperçu, état',
	function () {
		yume_tsa_reglages(
			array(
				'saison_mode'       => 'auto',
				'saison_calendrier' => calendrier_par_defaut(),
			)
		);
		$champ = yume_tsa_champ_calendrier();
		ob_start();
		\Yume\Core\Core\afficher_champ( array( 'champ' => $champ ) );
		$admin  = (string) ob_get_clean();
		$equipe = \Yume\Core\Planning\champ_reglage_equipe( $champ );
		foreach ( array(
			'administration' => $admin,
			'espace équipe'  => $equipe,
		) as $ou => $html ) {
			yume_assert_contains( 'name="yume_reglages[saison_calendrier][noel][debut]" value="01/12"', $html, $ou );
			yume_assert_contains( 'name="yume_reglages[saison_calendrier][hiver][fin]" value="19/03"', $html, $ou );
			yume_assert_contains( 'type="hidden" name="yume_reglages[saison_calendrier][ete][actif]" value="0"', $html, $ou );
			yume_assert_contains( esc_url( add_query_arg( PARAM_APERCU, 'automne', home_url( '/' ) ) ), $html, $ou );
			yume_assert_contains( 'target="_blank" rel="noopener"', $html, $ou );
			yume_assert_contains( '<th scope="row">' . esc_html( yume_saison_nom( 'halloween' ) ) . '</th>', $html, $ou );
			yume_assert_same( 5, substr_count( $html, '<th scope="col">' ), $ou . ' : colonnes' );
			yume_assert_same( 6, substr_count( $html, '<th scope="row">' ), $ou . ' : lignes' );
			yume_assert_same( 12, substr_count( $html, '(JJ/MM)</label>' ), $ou . ' : libellés des dates' );
			$positions = array_map(
				static function ( $slug ) use ( $html ) {
					return strpos( $html, '[saison_calendrier][' . $slug . '][debut]' );
				},
				ORDRE
			);
			$triees    = $positions;
			sort( $triees );
			yume_assert_same( $triees, $positions, $ou . ' : ordre de priorité' );
			yume_assert_contains( esc_html( phrase_etat() ), $html, $ou . ' : état' );
		}
		yume_assert_contains( 'yn-reglages__champ--large', $equipe );
		yume_assert_contains( 'yn-visually-hidden', $equipe );
		yume_assert_contains( 'screen-reader-text', $admin );
	}
);

yume_test(
	'Tâche quotidienne yume_saisons_quotidien : planifiée chaque jour à 00:05 heure du site, idempotente',
	function () {
		wp_clear_scheduled_hook( HOOK_QUOTIDIEN );
		planifier();
		$evenement = wp_get_scheduled_event( HOOK_QUOTIDIEN );
		yume_assert_true( false !== $evenement, 'planifiée' );
		yume_assert_same( 'daily', $evenement->schedule );
		yume_assert_true( heure_conforme( (int) $evenement->timestamp ), '00:05' );
		yume_assert_true( $evenement->timestamp > time() && $evenement->timestamp <= time() + DAY_IN_SECONDS + HOUR_IN_SECONDS );
		planifier();
		yume_assert_same( $evenement->timestamp, wp_next_scheduled( HOOK_QUOTIDIEN ), 'idempotente' );
		yume_assert_true( false !== has_action( HOOK_QUOTIDIEN, 'Yume\Core\Saisons\verifier_changement' ) );
	}
);
