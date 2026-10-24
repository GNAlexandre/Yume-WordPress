<?php
/**
 * Tests du socle du module comptes (lot 0) : méta de dernière activité (une écriture par jour,
 * jour du site sans heure, déclencheurs), amorçage depuis les positions de lecture, source
 * d'inscription (posée à la création, déduite pour les anciens comptes), journal des
 * consultations borné, normalisation des paramètres de la liste, adresse et accès aux données.
 *
 * Lancement : tools/localenv/test.sh comptes-activite
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Comptes\COMPTES_PAGE_MAX;
use const Yume\Core\Comptes\COMPTES_PAR_PAGE;
use const Yume\Core\Comptes\JOURNAL_MAX;
use const Yume\Core\Comptes\META_ACTIVITE;
use const Yume\Core\Comptes\META_SOURCE;
use const Yume\Core\Comptes\OPTION_AMORCAGE;
use const Yume\Core\Comptes\OPTION_DEPUIS;
use const Yume\Core\Comptes\OPTION_JOURNAL;
use const Yume\Core\Comptes\RECHERCHE_MAX;
use function Yume\Core\Comptes\amorcer_activite;
use function Yume\Core\Comptes\args_comptes;
use function Yume\Core\Comptes\derniere_activite;
use function Yume\Core\Comptes\jour_site;
use function Yume\Core\Comptes\journal_recent;
use function Yume\Core\Comptes\journaliser;
use function Yume\Core\Comptes\noter_activite;
use function Yume\Core\Comptes\source_compte;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tca_)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute $rappel avec « maintenant » figé à $ts (filtre yume_comptes_maintenant), puis retire
 * le filtre.
 *
 * @param int      $ts     Horodatage.
 * @param callable $rappel Fonction.
 * @return mixed
 */
function yume_tca_a( int $ts, callable $rappel ) {
	$fige = static function () use ( $ts ): int {
		return $ts;
	};
	add_filter( 'yume_comptes_maintenant', $fige );
	try {
		return $rappel();
	} finally {
		remove_filter( 'yume_comptes_maintenant', $fige );
	}
}

/**
 * Crée une œuvre, un tome et un chapitre publiés (sans émettre d'événement de sortie).
 *
 * @return array{oeuvre:int,chapitre:int}
 */
function yume_tca_serie(): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		$oeuvre   = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Comptes ' . wp_rand( 1, 99999 ),
			)
		);
		$tome     = yume_factory_post(
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
		$chapitre = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => 'Chapitre 1',
				'post_name'    => 'chapitre-1',
				'menu_order'   => 1,
				'post_content' => '<!-- wp:paragraph --><p>Un.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id' => $tome,
					'yume_numero'  => 1,
					'yume_nature'  => 'chapitre',
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
	return array(
		'oeuvre'   => $oeuvre,
		'chapitre' => $chapitre,
	);
}

/*
 * -----------------------------------------------------------------------------
 * Module
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Module comptes : chargé après rattrapage, droits gérant et administrateur seulement',
	function () {
		$modules = YUME_CORE_MODULES;
		yume_assert_same( array_search( 'rattrapage', $modules, true ) + 1, array_search( 'comptes', $modules, true ) );
		yume_assert_true( function_exists( 'Yume\Core\Comptes\noter_activite' ) );
		$gerant = yume_factory_user( 'yume_gerant' );
		$admin  = yume_factory_user( 'administrator' );
		$edit   = yume_factory_user( 'yume_editeur' );
		$lec    = yume_factory_user( 'subscriber' );
		yume_assert_true( \Yume\Core\Comptes\peut_voir_comptes( $gerant ) );
		yume_assert_true( \Yume\Core\Comptes\peut_voir_comptes( $admin ) );
		yume_assert_false( \Yume\Core\Comptes\peut_voir_comptes( $edit ) );
		yume_assert_false( \Yume\Core\Comptes\peut_voir_comptes( $lec ) );
		yume_assert_false( \Yume\Core\Comptes\peut_voir_comptes( 0 ), 'visiteur' );
		yume_assert_true( \Yume\Core\Comptes\peut_voir_email( $gerant ) );
		yume_assert_true( \Yume\Core\Comptes\peut_voir_email( $admin ) );
		yume_assert_false( \Yume\Core\Comptes\peut_voir_email( $edit ) );
		wp_set_current_user( $gerant );
		try {
			yume_assert_true( \Yume\Core\Comptes\peut_voir_comptes(), 'compte courant par défaut' );
		} finally {
			wp_set_current_user( 0 );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Dernière activité
 * -----------------------------------------------------------------------------
 */

yume_test(
	'noter_activite : jour du site sans heure, une écriture par jour, jamais de retour en arrière',
	function () {
		update_option( 'timezone_string', 'Europe/Paris' );
		$uid = yume_factory_user( 'subscriber' );
		// 23 h 30 UTC le 10 mars = 0 h 30 le 11 mars à Paris.
		$ts     = (int) strtotime( '2026-03-10 23:30:00 UTC' );
		$ecrits = 0;
		$compte = static function ( $meta_id, $user_id, $cle ) use ( &$ecrits ): void {
			if ( META_ACTIVITE === $cle ) {
				++$ecrits;
			}
		};
		add_action( 'added_user_meta', $compte, 10, 3 );
		add_action( 'updated_user_meta', $compte, 10, 3 );
		try {
			yume_assert_same( '2026-03-11', jour_site( $ts ) );
			yume_assert_same( '', derniere_activite( $uid ), 'inconnue au départ' );
			yume_assert_true( noter_activite( $uid, $ts ) );
			yume_assert_same( '2026-03-11', get_user_meta( $uid, META_ACTIVITE, true ), 'jour seul, sans heure' );
			yume_assert_false( noter_activite( $uid, $ts + 3600 ), 'même jour : rien' );
			yume_assert_false( noter_activite( $uid, $ts - 2 * DAY_IN_SECONDS ), 'jour plus ancien : rien' );
			yume_assert_same( 1, $ecrits, 'une seule écriture' );
			// « maintenant » figé : le jour suivant écrit de nouveau.
			yume_assert_true( yume_tca_a( $ts + DAY_IN_SECONDS, static fn() => noter_activite( $uid ) ) );
			yume_assert_same( '2026-03-12', derniere_activite( $uid ) );
			yume_assert_same( 2, $ecrits );
			// Filtre : activité ignorée.
			add_filter( 'yume_comptes_noter_activite', '__return_false' );
			try {
				yume_assert_false( noter_activite( $uid, $ts + 5 * DAY_IN_SECONDS ) );
			} finally {
				remove_filter( 'yume_comptes_noter_activite', '__return_false' );
			}
			yume_assert_same( '2026-03-12', derniere_activite( $uid ) );
			yume_assert_false( noter_activite( 0 ), 'visiteur' );
			yume_assert_false( noter_activite( 987654321 ), 'compte inexistant' );
			yume_assert_same( '', get_user_meta( 987654321, META_ACTIVITE, true ) );
			update_user_meta( $uid, META_ACTIVITE, 'pas une date' );
			yume_assert_same( '', derniere_activite( $uid ), 'valeur invalide ignorée' );
		} finally {
			remove_action( 'added_user_meta', $compte, 10 );
			remove_action( 'updated_user_meta', $compte, 10 );
		}
	}
);

yume_test(
	'Activité notée par la connexion, la lecture, un favori, un commentaire et une coquille',
	function () {
		$jour  = jour_site();
		$serie = yume_tca_serie();

		$lec  = yume_factory_user( 'subscriber' );
		$user = get_userdata( $lec );
		do_action( 'wp_login', $user->user_login, $user );
		yume_assert_same( $jour, derniere_activite( $lec ), 'wp_login' );

		$lec2 = yume_factory_user( 'subscriber' );
		$pos  = \Yume\Core\Reader\enregistrer_progression( $lec2, $serie['chapitre'], 0, 50 );
		yume_assert_false( is_wp_error( $pos ), is_wp_error( $pos ) ? $pos->get_error_message() : '' );
		yume_assert_same( $jour, derniere_activite( $lec2 ), 'yume_progression_enregistree' );

		$lec3 = yume_factory_user( 'subscriber' );
		$fav  = \Yume\Core\Social\ajouter_favori( $lec3, $serie['oeuvre'] );
		yume_assert_false( is_wp_error( $fav ), is_wp_error( $fav ) ? $fav->get_error_message() : '' );
		yume_assert_same( $jour, derniere_activite( $lec3 ), 'yume_favori_ajoute' );

		// Commentaire : seulement si son auteur est le compte connecté.
		yume_assert_true( has_action( 'comment_post', 'Yume\Core\Comptes\activite_commentaire' ) > 0 );
		$lec4 = yume_factory_user( 'subscriber' );
		\Yume\Core\Comptes\activite_commentaire( 1, 1, array( 'user_id' => $lec4 ) );
		yume_assert_same( '', derniere_activite( $lec4 ), 'auteur non connecté : rien' );
		wp_set_current_user( $lec4 );
		try {
			\Yume\Core\Comptes\activite_commentaire( 1, 1, array( 'user_id' => $lec4 ) );
		} finally {
			wp_set_current_user( 0 );
		}
		yume_assert_same( $jour, derniere_activite( $lec4 ), 'comment_post' );

		yume_assert_true( has_action( 'yume_coquille_signalee', 'Yume\Core\Comptes\activite_coquille' ) > 0 );
		$lec5 = yume_factory_user( 'subscriber' );
		\Yume\Core\Comptes\activite_coquille( 1, $serie['chapitre'], 0 );
		\Yume\Core\Comptes\activite_coquille( 1, $serie['chapitre'], $lec5 );
		yume_assert_same( $jour, derniere_activite( $lec5 ), 'yume_coquille_signalee' );

		// Filet des pages vues : jamais sous WP-CLI (ni administration, REST, Ajax, cron).
		$lec6 = yume_factory_user( 'subscriber' );
		wp_set_current_user( $lec6 );
		try {
			yume_assert_false( \Yume\Core\Comptes\requete_de_page() );
			\Yume\Core\Comptes\filet_activite();
		} finally {
			wp_set_current_user( 0 );
		}
		yume_assert_same( '', derniere_activite( $lec6 ) );
		yume_assert_same( 999, has_action( 'init', 'Yume\Core\Comptes\filet_activite' ) );
	}
);

yume_test(
	'amorcer_activite : jour de la dernière position, une seule fois, OPTION_DEPUIS posée',
	function () {
		global $wpdb;
		update_option( 'timezone_string', 'Europe/Paris' );
		$table = \Yume\Core\Reader\table_progression();
		$a     = yume_factory_user( 'subscriber' );
		$b     = yume_factory_user( 'subscriber' );
		$c     = yume_factory_user( 'subscriber' );
		$sans  = yume_factory_user( 'subscriber' );
		$ligne = static function ( int $uid, int $oeuvre, string $quand ) use ( $wpdb, $table ): void {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'user_id'     => $uid,
					'oeuvre_id'   => $oeuvre,
					'tome_id'     => 0,
					'chapitre_id' => 1,
					'paragraphe'  => 0,
					'pourcentage' => 10,
					'updated_at'  => $quand,
				)
			);
		};
		$ligne( $a, 901, '2026-02-01 10:00:00' );
		$ligne( $a, 902, '2026-02-03 23:30:00' ); // 4 février à Paris.
		$ligne( $b, 901, '2026-01-15 08:00:00' );
		$ligne( $c, 901, '2026-01-10 08:00:00' );
		update_user_meta( $b, META_ACTIVITE, '2026-03-01' ); // Plus récent : gardé.
		update_user_meta( $c, META_ACTIVITE, '2025-12-31' ); // Plus ancien : remplacé.
		delete_option( OPTION_AMORCAGE );
		delete_option( OPTION_DEPUIS );

		$jour   = '2026-10-24';
		$ecrits = yume_tca_a( (int) strtotime( $jour . ' 12:00:00 UTC' ), static fn() => amorcer_activite() );
		yume_assert_true( $ecrits >= 2, 'au moins deux comptes amorcés : ' . $ecrits );
		yume_assert_same( '2026-02-04', derniere_activite( $a ), 'position la plus récente, heure du site' );
		yume_assert_same( '2026-03-01', derniere_activite( $b ), 'méta plus récente conservée' );
		yume_assert_same( '2026-01-10', derniere_activite( $c ) );
		yume_assert_same( '', derniere_activite( $sans ), 'sans position : rien' );
		yume_assert_same( $jour, get_option( OPTION_DEPUIS ), 'début du relevé' );
		yume_assert_true( false !== get_option( OPTION_AMORCAGE ) );

		// Idempotent : un second appel (nouvelle installation, filet) ne refait rien.
		$ligne( $sans, 901, '2026-05-05 08:00:00' );
		yume_assert_same( 0, amorcer_activite() );
		\Yume\Core\Comptes\filet_amorcage();
		yume_assert_same( '', derniere_activite( $sans ) );
		yume_assert_same( $jour, get_option( OPTION_DEPUIS ), 'début du relevé inchangé' );
		yume_assert_true( has_action( 'yume_core_install', 'Yume\Core\Comptes\amorcer_activite' ) > 0 );
		yume_assert_same( 100, has_action( 'init', 'Yume\Core\Comptes\filet_amorcage' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Source d'inscription
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Source d’inscription : posée par creer_compte_lecteur(), déduite pour les anciens comptes',
	function () {
		$suffixe = wp_rand( 10000, 99999 );
		$equipe  = \Yume\Core\Social\creer_compte_lecteur(
			array(
				'pseudo'   => 'Créé par l’équipe ' . $suffixe,
				'email'    => 'equipe' . $suffixe . '@example.test',
				'source'   => 'equipe',
				'notifier' => false,
			)
		);
		add_filter( 'yume_inscriptions_ouvertes', '__return_true' );
		try {
			$discord = \Yume\Core\Social\creer_compte_lecteur(
				array(
					'pseudo'   => 'Venu de Discord ' . $suffixe,
					'email'    => 'discord' . $suffixe . '@example.test',
					'source'   => 'discord',
					'notifier' => false,
				)
			);
		} finally {
			remove_filter( 'yume_inscriptions_ouvertes', '__return_true' );
		}
		yume_assert_true( is_int( $equipe ) && is_int( $discord ), 'comptes créés' );
		yume_assert_same( 'equipe', get_user_meta( $equipe, META_SOURCE, true ) );
		yume_assert_same( 'discord', get_user_meta( $discord, META_SOURCE, true ) );
		yume_assert_same(
			array(
				'source'  => 'equipe',
				'deduite' => false,
			),
			source_compte( $equipe )
		);
		yume_assert_same( 5, has_action( 'yume_lecteur_inscrit', 'Yume\Core\Comptes\source_inscription' ) );
		\Yume\Core\Comptes\noter_source( $equipe, 'autre' );
		yume_assert_same( 'equipe', get_user_meta( $equipe, META_SOURCE, true ), 'source inconnue ignorée' );

		// Anciens comptes : déduction, rien n'est écrit.
		$ancien_discord = yume_factory_user( 'subscriber' );
		update_user_meta( $ancien_discord, 'yume_discord_id', '123456789012345678' );
		update_user_meta( $ancien_discord, '_yume_compte_sans_mdp', '1' );
		yume_assert_same(
			array(
				'source'  => 'discord',
				'deduite' => true,
			),
			source_compte( $ancien_discord )
		);
		$ancien_form = (int) wp_insert_user(
			array(
				'user_login' => 'yn-' . substr( md5( (string) $suffixe ), 0, 8 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'form' . $suffixe . '@example.test',
				'role'       => 'subscriber',
			)
		);
		yume_assert_same(
			array(
				'source'  => 'inscription',
				'deduite' => true,
			),
			source_compte( $ancien_form )
		);
		// Discord lié mais mot de passe connu : pas de déduction « discord ».
		$lie = yume_factory_user( 'subscriber' );
		update_user_meta( $lie, 'yume_discord_id', '123456789012345679' );
		yume_assert_same(
			array(
				'source'  => 'inconnue',
				'deduite' => false,
			),
			source_compte( $lie )
		);
		yume_assert_same( 'inconnue', source_compte( 987654321 )['source'], 'compte inexistant' );
		yume_assert_same( '', get_user_meta( $ancien_discord, META_SOURCE, true ), 'rien de persisté' );
		yume_assert_same( '', get_user_meta( $ancien_form, META_SOURCE, true ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Journal, paramètres, adresse, données
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Journal des consultations : borné à 200 entrées, plus récente en tête, non autoloadé',
	function () {
		global $wpdb;
		delete_option( OPTION_JOURNAL );
		yume_assert_same( array(), journal_recent() );
		for ( $i = 1; $i <= JOURNAL_MAX + 5; $i++ ) {
			journaliser( 'fiche', 7, $i );
		}
		journaliser(
			'export',
			8,
			0,
			42,
			array(
				'role'   => 'lecteurs',
				'balise' => '<b>gras</b>',
				'liste'  => array( 'ignorée' ),
			)
		);
		journaliser( '', 8, 1 );
		$tout = journal_recent( 500 );
		yume_assert_same( JOURNAL_MAX, count( $tout ) );
		yume_assert_same( 'export', $tout[0]['action'] );
		yume_assert_same( 42, $tout[0]['nb'] );
		yume_assert_same(
			array(
				'role'   => 'lecteurs',
				'balise' => 'gras',
			),
			$tout[0]['contexte']
		);
		yume_assert_same( JOURNAL_MAX + 5, $tout[1]['compte'], 'puis la fiche la plus récente' );
		yume_assert_same( 7, $tout[1]['acteur'] );
		yume_assert_same( 7, $tout[ JOURNAL_MAX - 1 ]['compte'], 'les six plus anciennes sont retirées' );
		yume_assert_true( $tout[0]['quand'] > 0 );
		yume_assert_same( 20, count( journal_recent() ) );
		yume_assert_same( 3, count( journal_recent( 3 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", OPTION_JOURNAL ) );
		yume_assert_true( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'autoload : ' . $autoload );
	}
);

yume_test(
	'args_comptes normalise les paramètres ; url_comptes omet les défauts',
	function () {
		$defauts = args_comptes( array() );
		yume_assert_same(
			array(
				'q'            => '',
				'role'         => 'tous',
				'source'       => 'toutes',
				'discord'      => 'tous',
				'activite'     => 'toutes',
				'inscrits'     => 'tout',
				'sans_lecture' => false,
				'sans_mdp'     => false,
				'tri'          => 'inscription',
				'ordre'        => 'desc',
				'page'         => 1,
				'limite'       => COMPTES_PAR_PAGE,
			),
			$defauts
		);
		$a = args_comptes(
			array(
				'q'            => '  Kaede  ',
				'role'         => 'yume_gerant',
				'source'       => 'discord',
				'discord'      => 'lie',
				'activite'     => '30',
				'inscrits'     => 365,
				'sans_lecture' => '1',
				'sans_mdp'     => 'on',
				'tri'          => 'pseudo',
				'pg'           => '3',
				'limite'       => '500',
			)
		);
		yume_assert_same( 'Kaede', $a['q'] );
		yume_assert_same( 'yume_gerant', $a['role'] );
		yume_assert_same( 'discord', $a['source'] );
		yume_assert_same( 'lie', $a['discord'] );
		yume_assert_same( '30', $a['activite'] );
		yume_assert_same( '365', $a['inscrits'] );
		yume_assert_true( $a['sans_lecture'] && $a['sans_mdp'] );
		yume_assert_same( 'asc', $a['ordre'], 'pseudo : croissant par défaut' );
		yume_assert_same( 3, $a['page'] );
		yume_assert_same( COMPTES_PAGE_MAX, $a['limite'] );
		$b = args_comptes(
			array(
				'q'        => 'k',
				'role'     => 'inexistant',
				'source'   => 'facebook',
				'discord'  => array( 'lie' ),
				'activite' => '12',
				'inscrits' => '-1',
				'tri'      => 'email',
				'ordre'    => 'haut',
				'page'     => '-4',
				'limite'   => '0',
			)
		);
		yume_assert_same( $defauts, $b, 'valeurs inconnues → défauts' );
		yume_assert_same( RECHERCHE_MAX, mb_strlen( args_comptes( array( 'q' => str_repeat( 'é', 80 ) ) )['q'] ), 'recherche coupée' );
		yume_assert_same( '', args_comptes( array( 'q' => '<b></b>' ) )['q'], 'balises retirées' );
		yume_assert_same( 'equipe', args_comptes( array( 'role' => 'equipe' ) )['role'] );
		yume_assert_same( 'inconnue', args_comptes( array( 'source' => 'inconnue' ) )['source'] );

		$base = \Yume\Core\Planning\url_vue_equipe( 'comptes' );
		yume_assert_same( $base, \Yume\Core\Comptes\url_comptes( $defauts ), 'défauts omis' );
		$url     = \Yume\Core\Comptes\url_comptes(
			array(
				'q'        => 'Tom & Jerry',
				'tri'      => 'pseudo',
				'ordre'    => 'asc',
				'page'     => 2,
				'sans_mdp' => true,
				'compte'   => 12,
			)
		);
		$requete = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $requete );
		yume_assert_same( 'comptes', $requete['vue'] );
		yume_assert_same( 'Tom & Jerry', $requete['q'] );
		yume_assert_same( 'pseudo', $requete['tri'] );
		yume_assert_false( isset( $requete['ordre'] ), 'ordre par défaut du tri omis' );
		yume_assert_same( '2', $requete['pg'] );
		yume_assert_same( '1', $requete['sans_mdp'] );
		yume_assert_same( '12', $requete['compte'] );
		yume_assert_false( isset( $requete['page'] ) || isset( $requete['role'] ) || isset( $requete['limite'] ) );
	}
);

yume_test(
	'vue_donnees : filtre yume_comptes_vue_donnees, fonction absente, appel direct ; libellés',
	function () {
		$uid = yume_factory_user( 'subscriber' );
		update_user_meta( $uid, META_ACTIVITE, '2026-01-02' );
		yume_assert_same( '2026-01-02', \Yume\Core\Comptes\vue_donnees( 'derniere_activite', array( $uid ) ) );
		yume_assert_same( null, \Yume\Core\Comptes\vue_donnees( 'fonction_qui_n_existe_pas' ) );
		yume_assert_same( null, \Yume\Core\Comptes\vue_donnees( '..\\autre' ) );
		$bouchon = static function ( $pre, $fonction ) {
			if ( 'lister_comptes' === $fonction ) {
				return array( 'total' => 3 );
			}
			return 'derniere_activite' === $fonction ? false : $pre;
		};
		add_filter( 'yume_comptes_vue_donnees', $bouchon, 10, 2 );
		try {
			yume_assert_same( array( 'total' => 3 ), \Yume\Core\Comptes\vue_donnees( 'lister_comptes', array( array() ) ) );
			yume_assert_same( null, \Yume\Core\Comptes\vue_donnees( 'derniere_activite', array( $uid ) ), 'false : absente' );
		} finally {
			remove_filter( 'yume_comptes_vue_donnees', $bouchon, 10 );
		}
		yume_assert_same( array( 'inscription', 'discord', 'equipe', 'inconnue' ), array_keys( \Yume\Core\Comptes\libelles_sources() ) );
		$roles = \Yume\Core\Comptes\libelles_roles();
		yume_assert_same( array( 'tous', 'lecteurs', 'equipe' ), array_slice( array_keys( $roles ), 0, 3 ) );
		yume_assert_true( isset( $roles['subscriber'], $roles['yume_gerant'], $roles['administrator'] ) );
	}
);
