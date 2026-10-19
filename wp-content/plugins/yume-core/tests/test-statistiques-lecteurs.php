<?php
/**
 * Tests du lot C des statistiques : listes nominatives des favoris et des suiveurs d'une œuvre
 * (droits, jointure sur les comptes existants, recherche échappée, pagination, tri, champs
 * strictement limités), route REST /lecteurs, exports CSV (BOM, séparateur, cellules
 * neutralisées), journal des exports nominatifs et traitement d'une demande d'export (compte,
 * capacité, nonce).
 *
 * Lancement : tools/localenv/test.sh statistiques-lecteurs
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Statistiques\ACTION_EXPORT;
use const Yume\Core\Statistiques\LECTEURS_PAGE_MAX;
use const Yume\Core\Statistiques\NONCE_EXPORT;
use const Yume\Core\Statistiques\OPTION_EXPORTS;
use function Yume\Core\Statistiques\csv_classement;
use function Yume\Core\Statistiques\csv_lecteurs;
use function Yume\Core\Statistiques\exports_recents;
use function Yume\Core\Statistiques\journaliser_export;
use function Yume\Core\Statistiques\lecteurs_oeuvre;
use function Yume\Core\Statistiques\peut_voir_lecteurs;
use function Yume\Core\Statistiques\traiter_export;
use function Yume\Core\Statistiques\url_export;

/** Horodatage figé des tests (2026-10-19 10:00:00 UTC). */
const YUME_TSLECT_MAINTENANT = 1792404000;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tslect_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test : rôles installés, journal des exports vidé, « maintenant » figé.
 *
 * @param string   $nom   Libellé.
 * @param callable $corps Corps.
 */
function yume_tslect_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			\Yume\Core\Core\installer_roles();
			if ( ! \Yume\Core\Social\tables_pretes() ) {
				\Yume\Core\Social\installer_tables();
			}
			delete_option( OPTION_EXPORTS );
			$fige = static fn() => YUME_TSLECT_MAINTENANT;
			add_filter( 'yume_statistiques_maintenant', $fige );
			try {
				$corps();
			} finally {
				remove_filter( 'yume_statistiques_maintenant', $fige );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Crée un compte avec un pseudo (display_name) distinct de son identifiant de connexion.
 *
 * @param string $pseudo Pseudo.
 * @param string $role   Rôle.
 */
function yume_tslect_compte( string $pseudo, string $role = 'subscriber' ): int {
	$user_id = yume_factory_user( $role );
	wp_update_user(
		array(
			'ID'           => $user_id,
			'display_name' => $pseudo,
		)
	);
	return $user_id;
}

/**
 * Insère un favori directement en table.
 *
 * @param int    $user_id   Utilisateur (éventuellement inexistant).
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence Fréquence.
 * @param string $depuis    Date d'ajout GMT « Y-m-d H:i:s ».
 */
function yume_tslect_favori( int $user_id, int $oeuvre_id, string $frequence, string $depuis ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Social\table_favoris(),
		array(
			'user_id'    => $user_id,
			'oeuvre_id'  => $oeuvre_id,
			'frequence'  => $frequence,
			'created_at' => $depuis,
		),
		array( '%d', '%d', '%s', '%s' )
	);
}

/**
 * Œuvre de test et ses favoris :
 *   Akira (immédiat, 01/10), Bento (hebdo, 02/10), Chiyo (jamais, 03/10), Neko_ink (immédiat,
 *   04/10, traductrice), Daichi (hebdo, 05/10), plus un favori d'un compte supprimé (06/10).
 *
 * @return array{oeuvre:int,comptes:array<string,int>}
 */
function yume_tslect_donnees(): array {
	$oeuvre  = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Lanternes de Brume Haute',
			'post_name'  => 'lanternes-de-brume-haute',
		)
	);
	$comptes = array(
		'Akira'    => yume_tslect_compte( 'Akira' ),
		'Bento'    => yume_tslect_compte( 'Bento' ),
		'Chiyo'    => yume_tslect_compte( 'Chiyo' ),
		'Neko_ink' => yume_tslect_compte( 'Neko_ink', 'yume_traducteur' ),
		'Daichi'   => yume_tslect_compte( 'Daichi' ),
	);
	yume_tslect_favori( $comptes['Akira'], $oeuvre, 'immediat', '2026-10-01 08:00:00' );
	yume_tslect_favori( $comptes['Bento'], $oeuvre, 'hebdo', '2026-10-02 08:00:00' );
	yume_tslect_favori( $comptes['Chiyo'], $oeuvre, 'jamais', '2026-10-03 08:00:00' );
	yume_tslect_favori( $comptes['Neko_ink'], $oeuvre, 'immediat', '2026-10-04 08:00:00' );
	yume_tslect_favori( $comptes['Daichi'], $oeuvre, 'hebdo', '2026-10-05 08:00:00' );
	yume_tslect_favori( 987654321, $oeuvre, 'immediat', '2026-10-06 08:00:00' ); // Compte supprimé.
	return array(
		'oeuvre'  => $oeuvre,
		'comptes' => $comptes,
	);
}

/**
 * Pseudos d'une liste, dans l'ordre.
 *
 * @param array $resultat Résultat de lecteurs_oeuvre().
 * @return string[]
 */
function yume_tslect_pseudos( array $resultat ): array {
	return array_column( $resultat['lecteurs'], 'pseudo' );
}

/**
 * Vérifie qu'un texte ne contient ni l'e-mail ni l'identifiant de connexion des comptes.
 *
 * @param string $texte   Texte (JSON, CSV).
 * @param int[]  $comptes IDs.
 * @param string $msg     Contexte.
 */
function yume_tslect_sans_donnees_privees( string $texte, array $comptes, string $msg ): void {
	foreach ( $comptes as $user_id ) {
		$user = get_userdata( $user_id );
		yume_assert_not_contains( $user->user_email, $texte, $msg . ' : e-mail' );
		yume_assert_not_contains( $user->user_login, $texte, $msg . ' : identifiant de connexion' );
	}
	yume_assert_not_contains( 'user_email', $texte, $msg );
	yume_assert_not_contains( 'user_login', $texte, $msg );
}

/**
 * Lignes d'un CSV (BOM retiré), chaque ligne découpée sur « ; ».
 *
 * @param string $csv CSV.
 * @return array<int,string[]>
 */
function yume_tslect_lignes_csv( string $csv ): array {
	$lignes = preg_split( '/\r?\n/', trim( substr( $csv, 3 ) ) );
	return array_map( static fn( $l ) => str_getcsv( $l, ';', '"', '' ), $lignes );
}

/*
 * -----------------------------------------------------------------------------
 * Droits
 * -----------------------------------------------------------------------------
 */

yume_tslect_test(
	'statistiques lecteurs : peut_voir_lecteurs() pour gérants et administrateurs seulement',
	static function () {
		yume_assert_true( peut_voir_lecteurs( yume_factory_user( 'yume_gerant' ) ), 'gérant' );
		yume_assert_true( peut_voir_lecteurs( yume_factory_user( 'administrator' ) ), 'administrateur' );
		yume_assert_false( peut_voir_lecteurs( yume_factory_user( 'yume_editeur' ) ), 'éditeur' );
		yume_assert_false( peut_voir_lecteurs( yume_factory_user( 'yume_traducteur' ) ), 'traducteur' );
		yume_assert_false( peut_voir_lecteurs( yume_factory_user( 'subscriber' ) ), 'lecteur' );
		yume_assert_false( peut_voir_lecteurs(), 'anonyme' );
		wp_set_current_user( yume_factory_user( 'yume_gerant' ) );
		yume_assert_true( peut_voir_lecteurs(), 'utilisateur courant' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Listes nominatives
 * -----------------------------------------------------------------------------
 */

yume_tslect_test(
	'statistiques lecteurs : favoris (compte supprimé exclu), champs exactement pseudo, depuis, frequence, equipe',
	static function () {
		$d = yume_tslect_donnees();
		$r = lecteurs_oeuvre( $d['oeuvre'], array() );
		yume_assert_same( array( 'oeuvre', 'type', 'total', 'page', 'pages', 'limite', 'q', 'lecteurs' ), array_keys( $r ) );
		yume_assert_same( array( 'id', 'titre', 'url' ), array_keys( $r['oeuvre'] ) );
		yume_assert_same( $d['oeuvre'], $r['oeuvre']['id'] );
		yume_assert_same( 'Lanternes de Brume Haute', $r['oeuvre']['titre'] );
		yume_assert_same( get_permalink( $d['oeuvre'] ), $r['oeuvre']['url'] );
		yume_assert_same( 'favoris', $r['type'] );
		yume_assert_same( 5, $r['total'], 'le favori du compte supprimé est écarté (jointure)' );
		yume_assert_same( 1, $r['page'] );
		yume_assert_same( 1, $r['pages'] );
		yume_assert_same( 50, $r['limite'] );
		yume_assert_same( '', $r['q'] );
		yume_assert_same( array( 'Daichi', 'Neko_ink', 'Chiyo', 'Bento', 'Akira' ), yume_tslect_pseudos( $r ), 'plus récents d’abord' );
		foreach ( $r['lecteurs'] as $l ) {
			yume_assert_same( array( 'pseudo', 'depuis', 'frequence', 'equipe' ), array_keys( $l ) );
		}
		yume_assert_same( '2026-10-05T08:00:00+00:00', $r['lecteurs'][0]['depuis'], 'ISO 8601 UTC' );
		yume_assert_same( 'hebdo', $r['lecteurs'][0]['frequence'] );
		yume_assert_false( $r['lecteurs'][0]['equipe'], 'lecteur : pas de badge' );
		yume_assert_true( $r['lecteurs'][1]['equipe'], 'traductrice : badge équipe' );
		yume_assert_same( 'jamais', $r['lecteurs'][2]['frequence'] );
		yume_tslect_sans_donnees_privees( (string) wp_json_encode( $r ), $d['comptes'], 'liste' );

		// Contenu qui n'est pas une œuvre : liste vide.
		$vide = lecteurs_oeuvre( yume_factory_post(), array() );
		yume_assert_same( 0, $vide['total'] );
		yume_assert_same( array(), $vide['lecteurs'] );
	}
);

yume_tslect_test(
	'statistiques lecteurs : suiveurs (fréquence « jamais » exclue), tri par pseudo, ordre',
	static function () {
		$d = yume_tslect_donnees();
		$r = lecteurs_oeuvre( $d['oeuvre'], array( 'type' => 'suiveurs' ) );
		yume_assert_same( 'suiveurs', $r['type'] );
		yume_assert_same( 4, $r['total'] );
		yume_assert_false( in_array( 'Chiyo', yume_tslect_pseudos( $r ), true ), 'Chiyo n’est pas alertée' );

		$r = lecteurs_oeuvre( $d['oeuvre'], array( 'tri' => 'pseudo' ) );
		yume_assert_same( array( 'Akira', 'Bento', 'Chiyo', 'Daichi', 'Neko_ink' ), yume_tslect_pseudos( $r ), 'pseudo : ordre alphabétique par défaut' );
		$r = lecteurs_oeuvre(
			$d['oeuvre'],
			array(
				'tri'   => 'pseudo',
				'ordre' => 'desc',
			)
		);
		yume_assert_same( array( 'Neko_ink', 'Daichi', 'Chiyo', 'Bento', 'Akira' ), yume_tslect_pseudos( $r ) );
		$r = lecteurs_oeuvre(
			$d['oeuvre'],
			array(
				'tri'   => 'date',
				'ordre' => 'asc',
			)
		);
		yume_assert_same( array( 'Akira', 'Bento', 'Chiyo', 'Neko_ink', 'Daichi' ), yume_tslect_pseudos( $r ) );
		// Valeurs hors liste : valeurs par défaut.
		$r = lecteurs_oeuvre(
			$d['oeuvre'],
			array(
				'type'  => 'tous',
				'tri'   => 'user_email',
				'ordre' => 'n’importe',
			)
		);
		yume_assert_same( 'favoris', $r['type'] );
		yume_assert_same( 'Daichi', $r['lecteurs'][0]['pseudo'] );
	}
);

yume_tslect_test(
	'statistiques lecteurs : recherche sur le pseudo (2 caractères au moins, LIKE échappé)',
	static function () {
		$d = yume_tslect_donnees();
		$r = lecteurs_oeuvre( $d['oeuvre'], array( 'q' => 'Ben' ) );
		yume_assert_same( array( 'Bento' ), yume_tslect_pseudos( $r ) );
		yume_assert_same( 'Ben', $r['q'] );
		yume_assert_same( 1, $r['total'] );

		// Un seul caractère : recherche ignorée.
		$r = lecteurs_oeuvre( $d['oeuvre'], array( 'q' => 'B' ) );
		yume_assert_same( '', $r['q'] );
		yume_assert_same( 5, $r['total'] );

		// « _ » et « % » sont des caractères ordinaires (non échappés, « _i » trouverait aussi
		// Akira, Chiyo et Daichi, « %% » tout le monde).
		$r = lecteurs_oeuvre( $d['oeuvre'], array( 'q' => '_i' ) );
		yume_assert_same( array( 'Neko_ink' ), yume_tslect_pseudos( $r ) );
		$r = lecteurs_oeuvre( $d['oeuvre'], array( 'q' => '%%' ) );
		yume_assert_same( 0, $r['total'] );
		yume_assert_same( 1, $r['pages'] );

		// Pas de recherche sur l'e-mail ni sur l'identifiant de connexion.
		$login = get_userdata( $d['comptes']['Akira'] )->user_login;
		yume_assert_same( 0, lecteurs_oeuvre( $d['oeuvre'], array( 'q' => $login ) )['total'], 'identifiant' );
		yume_assert_same( 0, lecteurs_oeuvre( $d['oeuvre'], array( 'q' => '@example.test' ) )['total'], 'e-mail' );
	}
);

yume_tslect_test(
	'statistiques lecteurs : pagination (pages, limite bornée à 1..100)',
	static function () {
		$d = yume_tslect_donnees();
		$r = lecteurs_oeuvre(
			$d['oeuvre'],
			array(
				'limite' => 2,
				'page'   => 3,
			)
		);
		yume_assert_same( 5, $r['total'] );
		yume_assert_same( 3, $r['pages'] );
		yume_assert_same( 3, $r['page'] );
		yume_assert_same( 2, $r['limite'] );
		yume_assert_same( array( 'Akira' ), yume_tslect_pseudos( $r ) );
		$r = lecteurs_oeuvre(
			$d['oeuvre'],
			array(
				'limite' => 2,
				'page'   => 2,
			)
		);
		yume_assert_same( array( 'Chiyo', 'Bento' ), yume_tslect_pseudos( $r ) );
		// Au-delà de la dernière page : liste vide, total conservé.
		$r = lecteurs_oeuvre(
			$d['oeuvre'],
			array(
				'limite' => 2,
				'page'   => 9,
			)
		);
		yume_assert_same( array(), $r['lecteurs'] );
		yume_assert_same( 5, $r['total'] );
		yume_assert_same( LECTEURS_PAGE_MAX, lecteurs_oeuvre( $d['oeuvre'], array( 'limite' => 5000 ) )['limite'] );
		yume_assert_same( 1, lecteurs_oeuvre( $d['oeuvre'], array( 'limite' => 0 ) )['limite'] );
		yume_assert_same( 1, lecteurs_oeuvre( $d['oeuvre'], array( 'page' => -4 ) )['page'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

yume_tslect_test(
	'statistiques lecteurs REST : 403 traducteur et éditeur, 200 gérant (no-store, sans e-mail ni identifiant)',
	static function () {
		$d     = yume_tslect_donnees();
		$route = '/yume/v1/statistiques/oeuvres/' . $d['oeuvre'] . '/lecteurs';
		yume_assert_same( 401, yume_rest( 'GET', $route )->get_status(), 'anonyme' );
		yume_assert_same( 403, yume_rest( 'GET', $route, array(), yume_factory_user( 'yume_traducteur' ) )->get_status(), 'traducteur' );
		yume_assert_same( 403, yume_rest( 'GET', $route, array(), yume_factory_user( 'yume_editeur' ) )->get_status(), 'éditeur' );
		yume_assert_same( 403, yume_rest( 'GET', $route, array(), $d['comptes']['Akira'] )->get_status(), 'lecteur' );

		$r = yume_rest(
			'GET',
			$route,
			array(
				'type'   => 'suiveurs',
				'limite' => 2,
				'tri'    => 'pseudo',
				'ordre'  => 'asc',
			),
			yume_factory_user( 'yume_gerant' )
		);
		yume_assert_same( 200, $r->get_status() );
		yume_assert_contains( 'no-store', (string) ( $r->get_headers()['Cache-Control'] ?? '' ) );
		$data = $r->get_data();
		yume_assert_same( 4, $data['total'] );
		yume_assert_same( 2, $data['pages'] );
		yume_assert_same( array( 'Akira', 'Bento' ), yume_tslect_pseudos( $data ) );
		yume_assert_same( array( 'pseudo', 'depuis', 'frequence', 'equipe' ), array_keys( $data['lecteurs'][0] ) );
		yume_tslect_sans_donnees_privees( (string) wp_json_encode( $data ), $d['comptes'], 'REST' );

		$r = yume_rest( 'GET', $route, array( 'q' => 'Neko' ), yume_factory_user( 'administrator' ) );
		yume_assert_same( 200, $r->get_status(), 'administrateur' );
		yume_assert_same( array( 'Neko_ink' ), yume_tslect_pseudos( $r->get_data() ) );
		yume_assert_true( $r->get_data()['lecteurs'][0]['equipe'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * CSV
 * -----------------------------------------------------------------------------
 */

yume_tslect_test(
	'statistiques export : csv_lecteurs() (BOM, « ; », 4 colonnes, formule neutralisée, aucune donnée privée)',
	static function () {
		$d      = yume_tslect_donnees();
		$pirate = yume_tslect_compte( '=HYPERLINK("http://example.test";"x")' );
		yume_tslect_favori( $pirate, $d['oeuvre'], 'immediat', '2026-10-07 08:00:00' );
		$csv = csv_lecteurs( lecteurs_oeuvre( $d['oeuvre'], array() ) );
		yume_assert_same( "\xEF\xBB\xBF", substr( $csv, 0, 3 ), 'BOM UTF-8' );
		$lignes = yume_tslect_lignes_csv( $csv );
		yume_assert_same( 7, count( $lignes ), 'en-têtes + 6 lecteurs' );
		foreach ( $lignes as $ligne ) {
			yume_assert_same( 4, count( $ligne ), 'quatre colonnes' );
		}
		yume_assert_same( '\'=HYPERLINK("http://example.test";"x")', $lignes[1][0], 'formule neutralisée' );
		yume_assert_contains( ';', strtok( substr( $csv, 3 ), "\n" ) );
		$neko = $lignes[3];
		yume_assert_same( 'Neko_ink', $neko[0] );
		yume_assert_same( 1, preg_match( '/^2026-10-04 \d\d:00$/', $neko[1] ), 'date « Y-m-d H:i »' );
		yume_assert_true( '' !== $neko[2], 'libellé de la fréquence' );
		yume_assert_true( '' !== $neko[3], 'équipe' );
		yume_assert_same( '', $lignes[2][3], 'lecteur : colonne Équipe vide' );
		yume_tslect_sans_donnees_privees( $csv, array_merge( $d['comptes'], array( $pirate ) ), 'CSV' );
	}
);

yume_tslect_test(
	'statistiques export : csv_classement() sur un classement construit à la main',
	static function () {
		$classement = array(
			'tri'     => 'favoris',
			'total'   => 2,
			'page'    => 1,
			'pages'   => 1,
			'oeuvres' => array(
				array(
					'oeuvre_id'          => 12,
					'titre'              => 'Lanternes ; « Brume »',
					'url'                => 'https://example.test/oeuvre/lanternes/',
					'favoris'            => 412,
					'suiveurs'           => 380,
					'lecteurs'           => 1020,
					'lecteurs_30j'       => 184,
					'vues'               => 5230,
					'nouveaux_favoris'   => 38,
					'croissance_favoris' => -3,
					'croissance_vues'    => null,
				),
				array(
					'oeuvre_id'          => 13,
					'titre'              => '+cmd|calc',
					'url'                => 'https://example.test/oeuvre/autre/',
					'favoris'            => 2,
					'suiveurs'           => 1,
					'lecteurs'           => 0,
					'lecteurs_30j'       => 0,
					'vues'               => null,
					'nouveaux_favoris'   => 0,
					'croissance_favoris' => null,
					'croissance_vues'    => null,
				),
			),
		);
		$csv        = csv_classement( $classement );
		yume_assert_same( "\xEF\xBB\xBF", substr( $csv, 0, 3 ) );
		$lignes = yume_tslect_lignes_csv( $csv );
		yume_assert_same( 3, count( $lignes ) );
		yume_assert_same( 11, count( $lignes[0] ) );
		yume_assert_same(
			array( '1', 'Lanternes ; « Brume »', '412', '380', '1020', '184', '5230', '38', '-3', '', 'https://example.test/oeuvre/lanternes/' ),
			$lignes[1],
			'nombres négatifs intacts, inconnu vide, « ; » protégé par les guillemets'
		);
		yume_assert_same( "'+cmd|calc", $lignes[2][1], 'formule neutralisée' );
		yume_assert_same( '', $lignes[2][6], 'vues inconnues' );
		// Classement vide : en-têtes seulement.
		yume_assert_same( 1, count( yume_tslect_lignes_csv( csv_classement( array( 'oeuvres' => array() ) ) ) ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Journal des exports
 * -----------------------------------------------------------------------------
 */

yume_tslect_test(
	'statistiques export : journal des exports nominatifs plafonné à 100, plus récents d’abord',
	static function () {
		yume_assert_same( array(), exports_recents() );
		for ( $i = 1; $i <= 105; $i++ ) {
			journaliser_export( 7, 40 + $i, 'favoris', $i );
		}
		$journal = get_option( OPTION_EXPORTS );
		yume_assert_same( 100, count( $journal ) );
		$recents = exports_recents();
		yume_assert_same( 20, count( $recents ), '20 par défaut' );
		yume_assert_same(
			array(
				'quand'     => YUME_TSLECT_MAINTENANT,
				'user_id'   => 7,
				'oeuvre_id' => 145,
				'type'      => 'favoris',
				'nb'        => 105,
			),
			$recents[0]
		);
		yume_assert_same( 6, exports_recents( 500 )[99]['nb'], 'les 5 plus anciens sont oubliés' );
		yume_assert_same( 100, count( exports_recents( 500 ) ) );
		yume_assert_same( 1, count( exports_recents( 0 ) ) );
		$autoload = wp_load_alloptions();
		yume_assert_false( isset( $autoload[ OPTION_EXPORTS ] ), 'option non chargée automatiquement' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Adresse et traitement d'un export
 * -----------------------------------------------------------------------------
 */

yume_tslect_test(
	'statistiques export : url_export() (admin-post, action, type, paramètres du type, nonce)',
	static function () {
		wp_set_current_user( yume_factory_user( 'yume_gerant' ) );
		$url = html_entity_decode(
			url_export(
				'lecteurs',
				array(
					'oeuvre' => 12,
					'liste'  => 'suiveurs',
					'q'      => 'Ne ko',
					'tri'    => 'favoris',
				)
			)
		);
		yume_assert_contains( 'admin-post.php', $url );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		yume_assert_same( ACTION_EXPORT, $params['action'] );
		yume_assert_same( 'lecteurs', $params['type'] );
		yume_assert_same( '12', $params['oeuvre'] );
		yume_assert_same( 'suiveurs', $params['liste'] );
		yume_assert_same( 'Ne ko', $params['q'] );
		yume_assert_false( isset( $params['tri'] ), 'paramètre d’un autre type écarté' );
		yume_assert_true( false !== wp_verify_nonce( $params['_wpnonce'], NONCE_EXPORT ), 'nonce valide' );

		wp_parse_str(
			(string) wp_parse_url(
				html_entity_decode(
					url_export(
						'oeuvres',
						array(
							'periode' => 90,
							'tri'     => 'vues',
							'oeuvre'  => 3,
						)
					)
				),
				PHP_URL_QUERY
			),
			$params
		);
		yume_assert_same( 'oeuvres', $params['type'] );
		yume_assert_same( '90', $params['periode'] );
		yume_assert_same( 'vues', $params['tri'] );
		yume_assert_false( isset( $params['oeuvre'] ) );
		yume_assert_true( false !== has_action( 'admin_post_' . ACTION_EXPORT ), 'action admin-post accrochée' );
		yume_assert_true( false !== has_action( 'admin_post_nopriv_' . ACTION_EXPORT ), 'action anonyme accrochée' );
	}
);

yume_tslect_test(
	'statistiques export : traiter_export() lecteurs (compte, capacité, nonce, œuvre, journalisation)',
	static function () {
		$d          = yume_tslect_donnees();
		$gerant     = yume_factory_user( 'yume_gerant' );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		$get        = array(
			'type'   => 'lecteurs',
			'oeuvre' => (string) $d['oeuvre'],
			'liste'  => 'suiveurs',
		);
		$code       = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() . ':' . ( $r->get_error_data()['status'] ?? 0 ) : 'ok';

		// Anonyme.
		yume_assert_same( 'yume_export_connexion:401', $code( traiter_export( $get, 0 ) ) );
		// Traducteur : capacité refusée, même avec un nonce valide.
		wp_set_current_user( $traducteur );
		yume_assert_same( 'yume_export_interdit:403', $code( traiter_export( $get + array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) ), $traducteur ) ) );
		// Gérant : nonce absent ou faux.
		wp_set_current_user( $gerant );
		yume_assert_same( 'yume_export_expire:403', $code( traiter_export( $get, $gerant ) ) );
		yume_assert_same( 'yume_export_expire:403', $code( traiter_export( $get + array( '_wpnonce' => 'abc' ), $gerant ) ) );
		// Compte différent de l'utilisateur courant (le nonce lui est lié).
		yume_assert_same( 'yume_export_connexion:401', $code( traiter_export( $get, $traducteur ) ) );

		$get['_wpnonce'] = wp_create_nonce( NONCE_EXPORT );
		yume_assert_same( 'yume_export_type:400', $code( traiter_export( array( 'type' => 'emails' ) + $get, $gerant ) ) );
		yume_assert_same( 'yume_oeuvre_introuvable:404', $code( traiter_export( array( 'oeuvre' => (string) yume_factory_post() ) + $get, $gerant ) ) );
		yume_assert_same( array(), exports_recents(), 'aucun export journalisé après un refus' );

		$export = traiter_export( $get, $gerant );
		yume_assert_same( 'ok', $code( $export ) );
		yume_assert_same( array( 'type', 'nom', 'csv', 'lignes' ), array_keys( $export ) );
		yume_assert_same( 'lecteurs', $export['type'] );
		yume_assert_same( 4, $export['lignes'], 'suiveurs : Chiyo (jamais) exclue' );
		yume_assert_same( 'lecteurs-suiveurs-lanternes-de-brume-haute-2026-10-19.csv', $export['nom'] );
		yume_assert_same( 5, count( yume_tslect_lignes_csv( $export['csv'] ) ) );
		yume_tslect_sans_donnees_privees( $export['csv'], $d['comptes'], 'export' );
		yume_assert_same(
			array(
				array(
					'quand'     => YUME_TSLECT_MAINTENANT,
					'user_id'   => $gerant,
					'oeuvre_id' => $d['oeuvre'],
					'type'      => 'suiveurs',
					'nb'        => 4,
				),
			),
			exports_recents()
		);
	}
);

yume_tslect_test(
	'statistiques export : export complet au-delà d’une page de 100 lecteurs',
	static function () {
		$oeuvre = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		$admin  = yume_factory_user( 'administrator' );
		for ( $i = 0; $i < 103; $i++ ) {
			yume_tslect_favori( yume_factory_user( 'subscriber' ), $oeuvre, 'immediat', gmdate( 'Y-m-d H:i:s', YUME_TSLECT_MAINTENANT - $i * 60 ) );
		}
		wp_set_current_user( $admin );
		$export = traiter_export(
			array(
				'type'     => 'lecteurs',
				'oeuvre'   => (string) $oeuvre,
				'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
			),
			$admin
		);
		yume_assert_false( is_wp_error( $export ) );
		yume_assert_same( 103, $export['lignes'] );
		yume_assert_same( 104, count( yume_tslect_lignes_csv( $export['csv'] ) ) );
		yume_assert_same( 'favoris', exports_recents()[0]['type'] );
	}
);

yume_tslect_test(
	'statistiques export : traiter_export() oeuvres (équipe, lecteur refusé, 503 sans classement)',
	static function () {
		$lecteur    = yume_factory_user( 'subscriber' );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		wp_set_current_user( $lecteur );
		$refus = traiter_export(
			array(
				'type'     => 'oeuvres',
				'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
			),
			$lecteur
		);
		yume_assert_same( 'yume_export_interdit', is_wp_error( $refus ) ? $refus->get_error_code() : 'ok' );

		wp_set_current_user( $traducteur );
		$export = traiter_export(
			array(
				'type'     => 'oeuvres',
				'periode'  => '7',
				'tri'      => 'vues',
				'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
			),
			$traducteur
		);
		if ( ! function_exists( 'Yume\\Core\\Statistiques\\classement' ) ) {
			yume_assert_true( is_wp_error( $export ) );
			yume_assert_same( 'yume_statistiques_indisponible', $export->get_error_code() );
			yume_assert_same( 503, $export->get_error_data()['status'] );
		} else {
			yume_assert_false( is_wp_error( $export ), 'classement disponible' );
			yume_assert_same( 'statistiques-oeuvres-vues-7j-2026-10-19.csv', $export['nom'] );
			yume_assert_same( "\xEF\xBB\xBF", substr( $export['csv'], 0, 3 ) );
		}
		yume_assert_same( array(), exports_recents(), 'le classement n’est pas journalisé' );
	}
);
