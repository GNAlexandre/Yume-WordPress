<?php
/**
 * Tests du lot D du module comptes : export CSV de la liste « Comptes inscrits » (droits, nonce,
 * paramètres, colonnes exactes, cellules neutralisées, toutes les pages avec garde-fou, e-mail
 * réservé aux administrateurs, journalisation export / export_email) et données personnelles
 * (clés activite et source_inscription de l'export, exportateur WordPress, effacement des métas
 * sur yume_effacer_donnees).
 *
 * Les pages de lister_comptes() (lot A) sont fournies par le filtre yume_comptes_vue_donnees.
 *
 * Lancement : tools/localenv/test.sh comptes-export
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Comptes\ACTION_EXPORT;
use const Yume\Core\Comptes\COMPTES_PAGE_MAX;
use const Yume\Core\Comptes\EXPORT_PAGES_MAX;
use const Yume\Core\Comptes\META_ACTIVITE;
use const Yume\Core\Comptes\META_SOURCE;
use const Yume\Core\Comptes\NONCE_EXPORT;
use const Yume\Core\Comptes\OPTION_JOURNAL;
use function Yume\Core\Comptes\csv_comptes;
use function Yume\Core\Comptes\journal_recent;
use function Yume\Core\Comptes\traiter_export;
use function Yume\Core\Comptes\url_export;

/** Horodatage figé des tests (2026-10-19 10:00:00 UTC). */
const YUME_TCE_MAINTENANT = 1792404000;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tce_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test : rôles installés, journal vidé, « maintenant » figé, fuseau Europe/Paris,
 * compte courant remis à 0 à la fin.
 *
 * @param string   $nom   Libellé.
 * @param callable $corps Corps.
 */
function yume_tce_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			\Yume\Core\Core\installer_roles();
			if ( ! \Yume\Core\Social\tables_pretes() ) {
				\Yume\Core\Social\installer_tables();
			}
			delete_option( OPTION_JOURNAL );
			update_option( 'timezone_string', 'Europe/Paris' );
			$fige = static fn() => YUME_TCE_MAINTENANT;
			add_filter( 'yume_comptes_maintenant', $fige );
			try {
				$corps();
			} finally {
				remove_filter( 'yume_comptes_maintenant', $fige );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Ligne de la liste (forme de lister_comptes()) pour un compte réel.
 *
 * @param int   $user_id Compte.
 * @param array $champs  Champs remplacés.
 */
function yume_tce_ligne( int $user_id, array $champs = array() ): array {
	return array_merge(
		array(
			'id'               => $user_id,
			'pseudo'           => 'Lecteur ' . $user_id,
			'roles'            => array( 'subscriber' ),
			'role'             => 'Abonné',
			'equipe'           => false,
			'inscrit_le'       => '2026-10-18T23:30:00+00:00',
			'source'           => 'inscription',
			'source_deduite'   => false,
			'discord'          => '',
			'sans_mdp'         => false,
			'activite'         => '2026-10-19',
			'derniere_lecture' => '',
			'chapitres_lus'    => 0,
			'series'           => 0,
			'favoris'          => 0,
			'suiveurs'         => 0,
			'commentaires'     => 0,
		),
		$champs
	);
}

/**
 * Exécute $rappel avec lister_comptes() bouchonnée : $comptes découpés en pages de
 * $args['limite'] ; chaque appel est noté dans $appels. $pages force le nombre de pages annoncé.
 *
 * @param array    $comptes Lignes.
 * @param callable $rappel  Fonction.
 * @param array    $appels  Arguments reçus (sortie).
 * @param int      $pages   Pages annoncées (0 : calculées).
 * @return mixed
 */
function yume_tce_avec_liste( array $comptes, callable $rappel, ?array &$appels = null, int $pages = 0 ) {
	$appels  = array();
	$bouchon = static function ( $pre, $fonction, $args ) use ( $comptes, &$appels, $pages ) {
		if ( 'lister_comptes' !== $fonction ) {
			return $pre;
		}
		$a        = (array) ( $args[0] ?? array() );
		$appels[] = $a;
		$limite   = max( 1, (int) ( $a['limite'] ?? 50 ) );
		$page     = max( 1, (int) ( $a['page'] ?? 1 ) );
		return array(
			'total'   => count( $comptes ),
			'page'    => $page,
			'pages'   => $pages > 0 ? $pages : max( 1, (int) ceil( count( $comptes ) / $limite ) ),
			'limite'  => $limite,
			'args'    => $a,
			'comptes' => $pages > 0 ? array_slice( $comptes, 0, 1 ) : array_slice( $comptes, ( $page - 1 ) * $limite, $limite ),
		);
	};
	add_filter( 'yume_comptes_vue_donnees', $bouchon, 10, 3 );
	try {
		return $rappel();
	} finally {
		remove_filter( 'yume_comptes_vue_donnees', $bouchon, 10 );
	}
}

/**
 * Lignes d'un CSV (BOM retiré, séparateur « ; »).
 *
 * @param string $csv Fichier.
 * @return array<int,array<int,string>>
 */
function yume_tce_lignes( string $csv ): array {
	$lignes = preg_split( '/\r?\n/', trim( substr( $csv, 3 ) ) );
	return array_map( static fn( $l ) => str_getcsv( $l, ';', '"', '' ), $lignes );
}

/**
 * Code d'un refus (« code:statut ») ou « ok ».
 *
 * @param mixed $r Résultat de traiter_export().
 */
function yume_tce_code( $r ): string {
	return is_wp_error( $r ) ? $r->get_error_code() . ':' . ( $r->get_error_data()['status'] ?? 0 ) : 'ok';
}

/*
 * -----------------------------------------------------------------------------
 * Export CSV
 * -----------------------------------------------------------------------------
 */

yume_tce_test(
	'comptes export : refus 401, 403 (capacité, nonce, e-mail), 400, 503, rien de journalisé',
	static function () {
		$gerant     = yume_factory_user( 'yume_gerant' );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		$lecteur    = yume_factory_user( 'subscriber' );
		$comptes    = array( yume_tce_ligne( $lecteur ) );

		yume_tce_avec_liste(
			$comptes,
			static function () use ( $gerant, $traducteur, $lecteur ) {
				// Anonyme, ou compte différent du compte courant (le nonce lui est lié).
				yume_assert_same( 'yume_comptes_export_connexion:401', yume_tce_code( traiter_export( array(), 0 ) ) );
				wp_set_current_user( $gerant );
				yume_assert_same( 'yume_comptes_export_connexion:401', yume_tce_code( traiter_export( array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) ), $traducteur ) ) );
				// Sans la capacité, même avec un nonce valide.
				foreach ( array( $traducteur, $lecteur ) as $sans_droit ) {
					wp_set_current_user( $sans_droit );
					yume_assert_same( 'yume_comptes_export_interdit:403', yume_tce_code( traiter_export( array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) ), $sans_droit ) ) );
				}
				// Gérant : nonce absent, faux ou d'une autre action.
				wp_set_current_user( $gerant );
				yume_assert_same( 'yume_comptes_export_expire:403', yume_tce_code( traiter_export( array(), $gerant ) ) );
				yume_assert_same( 'yume_comptes_export_expire:403', yume_tce_code( traiter_export( array( '_wpnonce' => 'abc' ), $gerant ) ) );
				yume_assert_same( 'yume_comptes_export_expire:403', yume_tce_code( traiter_export( array( '_wpnonce' => wp_create_nonce( 'yume_statistiques_export' ) ), $gerant ) ) );
				$nonce = array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) );
				// Paramètres non scalaires, e-mail invalide.
				yume_assert_same( 'yume_comptes_export_parametres:400', yume_tce_code( traiter_export( $nonce + array( 'role' => array( 'administrator' ) ), $gerant ) ) );
				yume_assert_same( 'yume_comptes_export_parametres:400', yume_tce_code( traiter_export( $nonce + array( 'email' => 'oui' ), $gerant ) ) );
				// E-mail demandé par un gérant (list_users mais pas manage_options).
				yume_assert_true( user_can( $gerant, 'list_users' ) && ! user_can( $gerant, 'manage_options' ) );
				yume_assert_same( 'yume_comptes_export_email:403', yume_tce_code( traiter_export( $nonce + array( 'email' => '1' ), $gerant ) ) );
			}
		);
		// Données indisponibles (lister_comptes() absente) : 503.
		wp_set_current_user( $gerant );
		$absente = static fn( $pre, $fonction ) => 'lister_comptes' === $fonction ? false : $pre;
		add_filter( 'yume_comptes_vue_donnees', $absente, 10, 2 );
		try {
			yume_assert_same( 'yume_comptes_indisponible:503', yume_tce_code( traiter_export( array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) ), $gerant ) ) );
		} finally {
			remove_filter( 'yume_comptes_vue_donnees', $absente, 10 );
		}
		yume_assert_same( array(), journal_recent(), 'aucun export journalisé après un refus' );
	}
);

yume_tce_test(
	'comptes export : colonnes exactes, valeurs, sans e-mail par défaut, journal export',
	static function () {
		$gerant  = yume_factory_user( 'yume_gerant' );
		$lecteur = yume_factory_user( 'subscriber' );
		$membre  = yume_factory_user( 'yume_traducteur' );
		$comptes = array(
			yume_tce_ligne(
				$lecteur,
				array(
					'pseudo'           => 'Akari &amp; Co',
					'source'           => 'discord',
					'source_deduite'   => true,
					'discord'          => 'akari',
					'sans_mdp'         => true,
					'derniere_lecture' => '2026-10-18T22:15:00+00:00',
					'chapitres_lus'    => 12,
					'favoris'          => 3,
					'suiveurs'         => 2,
					'commentaires'     => 5,
				)
			),
			yume_tce_ligne(
				$membre,
				array(
					'pseudo'     => 'Traductrice',
					'roles'      => array( 'yume_traducteur' ),
					'role'       => 'Traducteur',
					'equipe'     => true,
					'inscrit_le' => '2025-01-02T08:00:00+00:00',
					'source'     => 'inconnue',
					'activite'   => '',
				)
			),
		);
		wp_set_current_user( $gerant );
		$get    = array(
			'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
			'role'     => 'tous',
			'source'   => 'toutes',
			'tri'      => 'pseudo',
			'pg'       => '3',
			'limite'   => '7',
		);
		$export = yume_tce_avec_liste(
			$comptes,
			static fn() => traiter_export( $get, $gerant ),
			$appels
		);
		yume_assert_same( 'ok', yume_tce_code( $export ) );
		yume_assert_same( array( 'nom', 'csv', 'lignes' ), array_keys( $export ) );
		yume_assert_same( 'comptes-inscrits-2026-10-19.csv', $export['nom'] );
		yume_assert_same( 2, $export['lignes'] );
		yume_assert_same( "\xEF\xBB\xBF", substr( $export['csv'], 0, 3 ), 'BOM UTF-8' );
		// Toutes les pages, par COMPTES_PAGE_MAX, en commençant à la première (pg et limite ignorés).
		yume_assert_same( 1, count( $appels ) );
		yume_assert_same( 1, $appels[0]['page'] );
		yume_assert_same( COMPTES_PAGE_MAX, $appels[0]['limite'] );
		yume_assert_same( 'pseudo', $appels[0]['tri'] );
		yume_assert_same( 'asc', $appels[0]['ordre'] );

		$lignes = yume_tce_lignes( $export['csv'] );
		yume_assert_same(
			array( 'Pseudo', 'Rôle', 'Équipe', 'Inscrit le', 'Source', 'Discord', 'Sans mot de passe', 'Dernière activité', 'Dernière lecture', 'Chapitres lus (est.)', 'Favoris', 'Dont alertés', 'Commentaires' ),
			$lignes[0]
		);
		yume_assert_same(
			array( 'Akari & Co', 'Abonné', '', '2026-10-19', 'Discord (déduite)', 'Oui', 'Oui', '2026-10-19', '2026-10-19', '12', '3', '2', '5' ),
			$lignes[1],
			'dates au jour, heure du site (Europe/Paris)'
		);
		yume_assert_same(
			array( 'Traductrice', 'Traducteur', 'Oui', '2025-01-02', 'Inconnue', '', '', '', '', '0', '0', '0', '0' ),
			$lignes[2]
		);
		yume_assert_same( 3, count( $lignes ) );
		foreach ( array( $lecteur, $membre ) as $id ) {
			$compte = get_userdata( $id );
			yume_assert_not_contains( $compte->user_email, $export['csv'], 'e-mail absent par défaut' );
			yume_assert_not_contains( $compte->user_login, $export['csv'], 'identifiant absent' );
		}
		yume_assert_not_contains( '@', $export['csv'] );

		$journal = journal_recent();
		yume_assert_same( 1, count( $journal ) );
		yume_assert_same( 'export', $journal[0]['action'] );
		yume_assert_same( $gerant, $journal[0]['acteur'] );
		yume_assert_same( 0, $journal[0]['compte'] );
		yume_assert_same( 2, $journal[0]['nb'] );
		yume_assert_same( YUME_TCE_MAINTENANT, $journal[0]['quand'] );
		yume_assert_same( 'pseudo', $journal[0]['contexte']['tri'] );
		yume_assert_same( 'tous', $journal[0]['contexte']['role'] );
		yume_assert_false( isset( $journal[0]['contexte']['page'] ) );
	}
);

yume_tce_test(
	'comptes export : cellules neutralisées (=, +, -, @), nombres tels quels',
	static function () {
		$ids     = array( yume_factory_user(), yume_factory_user(), yume_factory_user(), yume_factory_user() );
		$pseudos = array( '=1+1', '+33 6', '-2', '@SOMME(A1)' );
		$comptes = array();
		foreach ( $ids as $i => $id ) {
			$comptes[] = yume_tce_ligne(
				$id,
				array(
					'pseudo' => $pseudos[ $i ],
					'role'   => '=HYPERLINK("x")',
				)
			);
		}
		$lignes = yume_tce_lignes( csv_comptes( array( 'comptes' => $comptes ) ) );
		yume_assert_same( "'=1+1", $lignes[1][0] );
		yume_assert_same( "'+33 6", $lignes[2][0] );
		yume_assert_same( "'-2", $lignes[3][0] );
		yume_assert_same( "'@SOMME(A1)", $lignes[4][0] );
		yume_assert_same( "'=HYPERLINK(\"x\")", $lignes[1][1] );
		yume_assert_same( '0', $lignes[1][9], 'nombre non neutralisé' );
		yume_assert_same( 1, count( yume_tce_lignes( csv_comptes( array() ) ) ), 'en-têtes seuls' );
	}
);

yume_tce_test(
	'comptes export : email=1 refusé au gérant, accepté pour l’administrateur et journalisé export_email',
	static function () {
		$admin   = yume_factory_user( 'administrator' );
		$gerant  = yume_factory_user( 'yume_gerant' );
		$lecteur = yume_factory_user( 'subscriber' );
		$email   = get_userdata( $lecteur )->user_email;
		$comptes = array( yume_tce_ligne( $lecteur, array( 'pseudo' => 'Mio' ) ) );

		wp_set_current_user( $gerant );
		$refus = yume_tce_avec_liste(
			$comptes,
			static fn() => traiter_export(
				array(
					'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
					'email'    => '1',
				),
				$gerant
			)
		);
		yume_assert_same( 'yume_comptes_export_email:403', yume_tce_code( $refus ) );
		yume_assert_same( array(), journal_recent() );

		wp_set_current_user( $admin );
		$export = yume_tce_avec_liste(
			$comptes,
			static fn() => traiter_export(
				array(
					'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
					'email'    => '1',
				),
				$admin
			)
		);
		yume_assert_same( 'ok', yume_tce_code( $export ) );
		yume_assert_same( 'comptes-inscrits-avec-email-2026-10-19.csv', $export['nom'] );
		$lignes = yume_tce_lignes( $export['csv'] );
		yume_assert_same( array( 'Pseudo', 'E-mail', 'Rôle' ), array_slice( $lignes[0], 0, 3 ) );
		yume_assert_same( 14, count( $lignes[0] ) );
		yume_assert_same( array( 'Mio', $email ), array_slice( $lignes[1], 0, 2 ) );
		$journal = journal_recent();
		yume_assert_same( 'export_email', $journal[0]['action'] );
		yume_assert_same( $admin, $journal[0]['acteur'] );
		yume_assert_same( 1, $journal[0]['nb'] );

		// L'administrateur sans email=1 (ou email=0) : pas de colonne e-mail, action export.
		$sans = yume_tce_avec_liste(
			$comptes,
			static fn() => traiter_export(
				array(
					'_wpnonce' => wp_create_nonce( NONCE_EXPORT ),
					'email'    => '0',
				),
				$admin
			)
		);
		yume_assert_not_contains( $email, $sans['csv'] );
		yume_assert_same( 'export', journal_recent()[0]['action'] );
	}
);

yume_tce_test(
	'comptes export : toutes les pages réunies, garde-fou EXPORT_PAGES_MAX',
	static function () {
		$admin   = yume_factory_user( 'administrator' );
		$lecteur = yume_factory_user();
		$comptes = array();
		for ( $i = 1; $i <= 2 * COMPTES_PAGE_MAX + 5; $i++ ) {
			$comptes[] = yume_tce_ligne( $lecteur, array( 'pseudo' => 'Compte ' . $i ) );
		}
		wp_set_current_user( $admin );
		$export = yume_tce_avec_liste( $comptes, static fn() => traiter_export( array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) ), $admin ), $appels );
		yume_assert_same( 2 * COMPTES_PAGE_MAX + 5, $export['lignes'] );
		yume_assert_same( array( 1, 2, 3 ), array_column( $appels, 'page' ) );
		$lignes = yume_tce_lignes( $export['csv'] );
		yume_assert_same( 'Compte ' . ( 2 * COMPTES_PAGE_MAX + 5 ), end( $lignes )[0] );

		// Une liste qui annonce un million de pages s'arrête au garde-fou.
		$borne = yume_tce_avec_liste( array_slice( $comptes, 0, 1 ), static fn() => traiter_export( array( '_wpnonce' => wp_create_nonce( NONCE_EXPORT ) ), $admin ), $appels, 1000000 );
		yume_assert_same( EXPORT_PAGES_MAX, count( $appels ) );
		yume_assert_same( EXPORT_PAGES_MAX, $borne['lignes'] );
	}
);

yume_tce_test(
	'comptes export : url_export() (admin-post, nonce, filtres hors défauts, ni page ni limite, email) et hooks admin-post',
	static function () {
		$gerant = yume_factory_user( 'yume_gerant' );
		wp_set_current_user( $gerant );
		$url = html_entity_decode( url_export( array() ) );
		yume_assert_contains( 'admin-post.php', $url );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		yume_assert_same( array( 'action', '_wpnonce' ), array_keys( $q ) );
		yume_assert_same( ACTION_EXPORT, $q['action'] );
		yume_assert_same( 1, wp_verify_nonce( $q['_wpnonce'], NONCE_EXPORT ) );

		$url = html_entity_decode(
			url_export(
				array(
					'q'            => 'aka ri',
					'role'         => 'lecteurs',
					'source'       => 'toutes',
					'activite'     => 'inconnue',
					'sans_lecture' => true,
					'tri'          => 'pseudo',
					'ordre'        => 'asc',
					'page'         => 4,
					'limite'       => 10,
					'compte'       => 12,
					'email'        => true,
				)
			)
		);
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		unset( $q['_wpnonce'] );
		yume_assert_same(
			array(
				'action'       => ACTION_EXPORT,
				'q'            => 'aka ri',
				'role'         => 'lecteurs',
				'activite'     => 'inconnue',
				'sans_lecture' => '1',
				'tri'          => 'pseudo',
				'email'        => '1',
			),
			$q
		);
		yume_assert_true( false !== has_action( 'admin_post_' . ACTION_EXPORT, 'Yume\Core\Comptes\admin_post_export' ) );
		yume_assert_true( false !== has_action( 'admin_post_nopriv_' . ACTION_EXPORT, 'Yume\Core\Comptes\admin_post_export_anonyme' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Données personnelles
 * -----------------------------------------------------------------------------
 */

yume_tce_test(
	'comptes RGPD : export JSON (activite, source_inscription), exportateur WordPress',
	static function () {
		$lecteur = yume_factory_user();
		$vierge  = yume_factory_user();
		update_user_meta( $lecteur, META_ACTIVITE, '2026-10-18' );
		update_user_meta( $lecteur, META_SOURCE, 'discord' );

		$donnees = apply_filters( 'yume_donnees_personnelles', array( 'format' => 'yume-export-1' ), $lecteur );
		yume_assert_same( '2026-10-18', $donnees['activite'] );
		yume_assert_same( 'discord', $donnees['source_inscription'] );
		yume_assert_same( 'yume-export-1', $donnees['format'] );
		$vide = apply_filters( 'yume_donnees_personnelles', array(), $vierge );
		yume_assert_true( array_key_exists( 'activite', $vide ) && null === $vide['activite'] );
		yume_assert_true( array_key_exists( 'source_inscription', $vide ) && null === $vide['source_inscription'] );
		// Clés existantes jamais modifiées.
		$garde = apply_filters( 'yume_donnees_personnelles', array( 'activite' => 'x' ), $lecteur );
		yume_assert_same( 'x', $garde['activite'] );
		// Export complet du module social.
		$complet = \Yume\Core\Social\donnees_personnelles( $lecteur );
		yume_assert_same( '2026-10-18', $complet['activite'] );
		yume_assert_same( 'discord', $complet['source_inscription'] );

		$exportateurs = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		yume_assert_true( isset( $exportateurs['yume-core-comptes'] ) );
		$resultat = call_user_func( $exportateurs['yume-core-comptes']['callback'], get_userdata( $lecteur )->user_email, 1 );
		yume_assert_true( $resultat['done'] );
		yume_assert_same( 1, count( $resultat['data'] ) );
		yume_assert_same( array( '2026-10-18', 'Discord' ), array_column( $resultat['data'][0]['data'], 'value' ) );
		$rien = call_user_func( $exportateurs['yume-core-comptes']['callback'], get_userdata( $vierge )->user_email, 1 );
		yume_assert_same( array(), $rien['data'] );
		$inconnu = call_user_func( $exportateurs['yume-core-comptes']['callback'], 'personne@example.test', 1 );
		yume_assert_same( array(), $inconnu['data'] );
	}
);

yume_tce_test(
	'comptes RGPD : yume_effacer_donnees efface les métas d’activité et de source',
	static function () {
		$lecteur = yume_factory_user();
		$autre   = yume_factory_user();
		foreach ( array( $lecteur, $autre ) as $id ) {
			update_user_meta( $id, META_ACTIVITE, '2026-10-18' );
			update_user_meta( $id, META_SOURCE, 'inscription' );
		}
		do_action( 'yume_effacer_donnees', $lecteur );
		yume_assert_false( metadata_exists( 'user', $lecteur, META_ACTIVITE ) );
		yume_assert_false( metadata_exists( 'user', $lecteur, META_SOURCE ) );
		yume_assert_same( '2026-10-18', get_user_meta( $autre, META_ACTIVITE, true ), 'autre compte intact' );
		yume_assert_same( 'inscription', get_user_meta( $autre, META_SOURCE, true ) );

		// Par l'effacement du module social (outil d'effacement de WordPress, suppression du compte).
		\Yume\Core\Social\effacer_donnees( $autre );
		yume_assert_false( metadata_exists( 'user', $autre, META_ACTIVITE ) );
		yume_assert_false( metadata_exists( 'user', $autre, META_SOURCE ) );
	}
);
