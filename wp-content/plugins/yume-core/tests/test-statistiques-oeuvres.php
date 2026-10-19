<?php
/**
 * Tests des statistiques par œuvre (lot B) : compteurs (favoris, suiveurs alertés, listes,
 * lecteurs, lecteurs actifs, chapitres lus estimés), classements (tris, pagination, nouveaux
 * favoris, croissance), fiche d'une œuvre, résumé des lecteurs, historique quotidien (calcul
 * idempotent, rattrapage, purge, séries, reconstitution des favoris), invalidation du cache,
 * routes REST et performance de la tâche quotidienne.
 *
 * Données insérées directement en table ; « maintenant » figé au 15 septembre 2026, 12 h UTC
 * (14 h à Paris, fuseau du site forcé).
 *
 * Lancement : tools/localenv/test.sh statistiques-oeuvres
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Statistiques\OPTION_DERNIER_JOUR;
use function Yume\Core\Statistiques\cache_ecrire;
use function Yume\Core\Statistiques\cache_lire;
use function Yume\Core\Statistiques\cache_vider;
use function Yume\Core\Statistiques\calculer_jour;
use function Yume\Core\Statistiques\classement;
use function Yume\Core\Statistiques\compteurs_calcules;
use function Yume\Core\Statistiques\compteurs_oeuvre;
use function Yume\Core\Statistiques\compteurs_oeuvres;
use function Yume\Core\Statistiques\fiche_oeuvre;
use function Yume\Core\Statistiques\points;
use function Yume\Core\Statistiques\reconstituer_favoris;
use function Yume\Core\Statistiques\resume_lecteurs;
use function Yume\Core\Statistiques\serie;
use function Yume\Core\Statistiques\table_stats_jour;
use function Yume\Core\Statistiques\tache_quotidienne;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tsoe_)
 * -----------------------------------------------------------------------------
 */

/** Horodatage de référence : 15 septembre 2026, 12 h UTC (14 h à Paris). */
const YUME_TSOE_MAINTENANT = 1789473600;

/**
 * Déclare un test isolé : contenus Yume, favoris, listes, positions et historique vidés dans la
 * transaction du test ; « maintenant » figé ; fuseau du site Europe/Paris ; cache vidé.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tsoe_test( string $nom, callable $corps ): void {
	yume_test(
		'statistiques œuvres : ' . $nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" );
			foreach ( array( \Yume\Core\Social\table_favoris(), \Yume\Core\Social\table_listes(), \Yume\Core\Social\table_listes_oeuvres(), \Yume\Core\Reader\table_progression(), table_stats_jour() ) as $table ) {
				$wpdb->query( "DELETE FROM {$table}" );
			}
			// phpcs:enable
			delete_option( OPTION_DERNIER_JOUR );
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$fige   = static fn() => YUME_TSOE_MAINTENANT;
			$fuseau = static fn() => 'Europe/Paris';
			add_filter( 'yume_statistiques_maintenant', $fige );
			add_filter( 'pre_option_timezone_string', $fuseau );
			cache_vider();
			try {
				$corps();
			} finally {
				remove_filter( 'yume_statistiques_maintenant', $fige );
				remove_filter( 'pre_option_timezone_string', $fuseau );
				cache_vider();
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Date GMT « Y-m-d H:i:s » à $jours jours (et $heures heures) de « maintenant ».
 *
 * @param float $jours Décalage en jours (négatif : passé).
 */
function yume_tsoe_gmt( float $jours ): string {
	return gmdate( 'Y-m-d H:i:s', (int) ( YUME_TSOE_MAINTENANT + $jours * DAY_IN_SECONDS ) );
}

/**
 * Insère un contenu publié (sans déclencher les annonces de publication) et ses métas.
 *
 * @param string $type  Type de contenu.
 * @param string $titre Titre.
 * @param array  $metas Métas.
 * @param int    $ordre menu_order.
 */
function yume_tsoe_contenu( string $type, string $titre, array $metas = array(), int $ordre = 0 ): int {
	global $wpdb;
	$date = '2026-01-01 10:00:00';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->posts,
		array(
			'post_type'             => $type,
			'post_status'           => 'publish',
			'post_title'            => $titre,
			'post_name'             => sanitize_title( $titre ) . '-' . wp_rand( 1000, 999999 ),
			'post_content'          => '',
			'post_excerpt'          => '',
			'to_ping'               => '',
			'pinged'                => '',
			'post_content_filtered' => '',
			'menu_order'            => $ordre,
			'post_date'             => $date,
			'post_date_gmt'         => $date,
			'post_modified'         => $date,
			'post_modified_gmt'     => $date,
		)
	);
	$id = (int) $wpdb->insert_id;
	foreach ( $metas as $cle => $valeur ) {
		add_post_meta( $id, $cle, $valeur );
	}
	return $id;
}

/**
 * Œuvre publiée avec $nb_tomes tomes de $nb_chapitres chapitres publiés.
 *
 * @param string $titre        Titre.
 * @param int    $nb_tomes     Tomes.
 * @param int    $nb_chapitres Chapitres par tome.
 * @return array{id:int,tomes:int[],chapitres:int[][]}
 */
function yume_tsoe_oeuvre( string $titre, int $nb_tomes = 0, int $nb_chapitres = 0 ): array {
	$oeuvre    = yume_tsoe_contenu( 'yume_oeuvre', $titre );
	$tomes     = array();
	$chapitres = array();
	for ( $t = 1; $t <= $nb_tomes; $t++ ) {
		$tome    = yume_tsoe_contenu(
			'yume_tome',
			$titre . ' — Tome ' . $t,
			array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => $t,
			)
		);
		$tomes[] = $tome;
		for ( $c = 1; $c <= $nb_chapitres; $c++ ) {
			$chapitres[ $t - 1 ][] = yume_tsoe_contenu(
				'yume_chapitre',
				$titre . ' ' . $t . '.' . $c,
				array(
					'yume_oeuvre_id' => $oeuvre,
					'yume_tome_id'   => $tome,
					'yume_numero'    => $c,
				),
				$c
			);
		}
	}
	wp_cache_flush(); // oeuvres_publiees() : nouvelle version des contenus.
	return array(
		'id'        => $oeuvre,
		'tomes'     => $tomes,
		'chapitres' => $chapitres,
	);
}

/**
 * Compte utilisateur inséré directement (rapide) avec un rôle et une date d'inscription.
 *
 * @param string $inscrit Date GMT d'inscription.
 * @param string $role    Rôle.
 */
function yume_tsoe_compte( string $inscrit, string $role = 'subscriber' ): int {
	global $wpdb;
	static $n = 0;
	++$n;
	$login = 'tsoe_' . $n . '_' . wp_rand( 1000, 999999 );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->users,
		array(
			'user_login'      => $login,
			'user_pass'       => '',
			'user_nicename'   => $login,
			'user_email'      => $login . '@example.test',
			'user_url'        => '',
			'user_registered' => $inscrit,
			'display_name'    => $login,
		)
	);
	$id = (int) $wpdb->insert_id;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->usermeta,
		array(
			'user_id'    => $id,
			'meta_key'   => $wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => serialize( array( $role => true ) ), // phpcs:ignore WordPress.DB.SlowDBQuery, WordPress.PHP.DiscouragedPHPFunctions
		)
	);
	return $id;
}

/**
 * Favori inséré directement.
 *
 * @param int    $user_id   Compte.
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence Fréquence.
 * @param string $cree      Date GMT d'ajout.
 */
function yume_tsoe_favori( int $user_id, int $oeuvre_id, string $frequence = 'immediat', string $cree = '2026-01-01 00:00:00' ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Social\table_favoris(),
		array(
			'user_id'    => $user_id,
			'oeuvre_id'  => $oeuvre_id,
			'frequence'  => $frequence,
			'created_at' => $cree,
		)
	);
}

/**
 * Position de lecture insérée directement.
 *
 * @param int    $user_id     Compte.
 * @param int    $oeuvre_id   Œuvre.
 * @param int    $tome_id     Tome.
 * @param int    $chapitre_id Chapitre.
 * @param int    $pourcentage Pourcentage lu.
 * @param string $maj         Date GMT de mise à jour.
 */
function yume_tsoe_position( int $user_id, int $oeuvre_id, int $tome_id, int $chapitre_id, int $pourcentage, string $maj ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Reader\table_progression(),
		array(
			'user_id'     => $user_id,
			'oeuvre_id'   => $oeuvre_id,
			'tome_id'     => $tome_id,
			'chapitre_id' => $chapitre_id,
			'paragraphe'  => 0,
			'pourcentage' => $pourcentage,
			'updated_at'  => $maj,
		)
	);
}

/**
 * Liste de lecture insérée directement, avec ses œuvres.
 *
 * @param int    $user_id Propriétaire.
 * @param string $systeme a_lire, en_cours, termine ou '' (personnelle).
 * @param int[]  $oeuvres Œuvres.
 * @param string $ajout   Date GMT d'ajout des œuvres.
 */
function yume_tsoe_liste( int $user_id, string $systeme, array $oeuvres, string $ajout = '2026-01-01 00:00:00' ): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Social\table_listes(),
		array(
			'user_id' => $user_id,
			'nom'     => '' === $systeme ? 'Perso' : $systeme,
			'slug'    => '' === $systeme ? 'perso' : $systeme,
			'jeton'   => substr( md5( (string) wp_rand() ), 0, 16 ),
			'systeme' => $systeme,
			'cree_le' => $ajout,
			'maj_le'  => $ajout,
		)
	);
	$liste = (int) $wpdb->insert_id;
	foreach ( $oeuvres as $oeuvre_id ) {
		$wpdb->insert(
			\Yume\Core\Social\table_listes_oeuvres(),
			array(
				'liste_id'  => $liste,
				'oeuvre_id' => $oeuvre_id,
				'ajoute_le' => $ajout,
			)
		);
	}
	// phpcs:enable
}

/**
 * Ligne d'historique insérée directement.
 *
 * @param string $jour      Jour.
 * @param int    $oeuvre_id Œuvre (0 : site).
 * @param array  $valeurs   Colonnes.
 */
function yume_tsoe_historique( string $jour, int $oeuvre_id, array $valeurs = array() ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->replace(
		table_stats_jour(),
		array_merge(
			array(
				'jour'      => $jour,
				'oeuvre_id' => $oeuvre_id,
			),
			$valeurs
		)
	);
}

/**
 * Lignes d'historique (jour => oeuvre_id => ligne normalisée).
 */
function yume_tsoe_lignes(): array {
	global $wpdb;
	$table = table_stats_jour();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes   = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY jour, oeuvre_id", ARRAY_A );
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		$resultat[ substr( $ligne['jour'], 0, 10 ) ][ (int) $ligne['oeuvre_id'] ] = \Yume\Core\Statistiques\normaliser_ligne_historique( $ligne );
	}
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Compteurs
 * -----------------------------------------------------------------------------
 */

yume_tsoe_test(
	'compteurs (suiveurs sans « jamais », listes par système, lecteurs actifs sur 30 jours), cinq requêtes au plus',
	function () {
		global $wpdb;
		$a    = yume_tsoe_oeuvre( 'Alpha' )['id'];
		$b    = yume_tsoe_oeuvre( 'Bêta' )['id'];
		$u    = array();
		$u[]  = yume_tsoe_compte( '2026-01-01 00:00:00' );
		$u[]  = yume_tsoe_compte( '2026-01-01 00:00:00' );
		$u[]  = yume_tsoe_compte( '2026-01-01 00:00:00' );
		$u[]  = yume_tsoe_compte( '2026-01-01 00:00:00' );
		$brou = yume_tsoe_contenu( 'yume_oeuvre', 'Brouillon' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $brou ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();

		yume_tsoe_favori( $u[0], $a, 'immediat' );
		yume_tsoe_favori( $u[1], $a, 'hebdo' );
		yume_tsoe_favori( $u[2], $a, 'jamais' );
		yume_tsoe_favori( 999999, $a, 'immediat' ); // Compte inexistant : ignoré.
		yume_tsoe_favori( $u[0], $b, 'jamais' );
		yume_tsoe_favori( $u[0], $brou, 'immediat' );
		yume_tsoe_liste( $u[0], 'a_lire', array( $a, $b ) );
		yume_tsoe_liste( $u[1], 'a_lire', array( $a ) );
		yume_tsoe_liste( $u[2], 'en_cours', array( $a ) );
		yume_tsoe_liste( $u[3], 'termine', array( $a ) );
		yume_tsoe_liste( $u[3], '', array( $a, $b ) );
		yume_tsoe_position( $u[0], $a, 0, 0, 10, yume_tsoe_gmt( -1 ) );
		yume_tsoe_position( $u[1], $a, 0, 0, 10, yume_tsoe_gmt( -29 ) );
		yume_tsoe_position( $u[2], $a, 0, 0, 10, yume_tsoe_gmt( -40 ) );
		yume_tsoe_position( $u[3], $b, 0, 0, 10, yume_tsoe_gmt( -100 ) );

		$tous = compteurs_oeuvres();
		yume_assert_same( array( $a, $b ), array_keys( $tous ), 'œuvres publiées seulement, par titre' );
		yume_assert_same(
			array(
				'favoris'       => 3,
				'suiveurs'      => 2,
				'listes'        => array(
					'a_lire'   => 2,
					'en_cours' => 1,
					'termine'  => 1,
					'perso'    => 1,
				),
				'lecteurs'      => 3,
				'lecteurs_30j'  => 2,
				'chapitres_lus' => 0,
			),
			$tous[ $a ]
		);
		yume_assert_same( 1, $tous[ $b ]['favoris'] );
		yume_assert_same( 0, $tous[ $b ]['suiveurs'], 'favori « jamais » : pas suiveur' );
		yume_assert_same( 1, $tous[ $b ]['lecteurs'] );
		yume_assert_same( 0, $tous[ $b ]['lecteurs_30j'] );
		yume_assert_same( $tous[ $a ], compteurs_oeuvre( $a ), 'compteurs_oeuvre() : même forme' );
		yume_assert_same( 0, compteurs_oeuvre( 0 )['favoris'] );

		// Requêtes agrégées : au plus cinq, quel que soit le nombre d'œuvres.
		$avant = $wpdb->num_queries;
		compteurs_calcules( array( $a, $b ), yume_tsoe_gmt( -30 ) );
		yume_assert_true( $wpdb->num_queries - $avant <= 5, 'requêtes : ' . ( $wpdb->num_queries - $avant ) );

		// En cache : une nouvelle ligne n'apparaît qu'après le vidage.
		yume_tsoe_favori( $u[3], $a, 'immediat' );
		yume_assert_same( 3, compteurs_oeuvres()[ $a ]['favoris'], 'en cache' );
		cache_vider();
		yume_assert_same( 4, compteurs_oeuvres()[ $a ]['favoris'] );
	}
);

yume_tsoe_test(
	'chapitres lus estimés : chapitres qui précèdent la position, plus le courant à 90 % ou plus',
	function () {
		$o = yume_tsoe_oeuvre( 'Gamma', 2, 3 );
		$c = $o['chapitres'];
		$t = $o['tomes'];
		$u = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$u[] = yume_tsoe_compte( '2026-01-01 00:00:00' );
		}
		$maj = yume_tsoe_gmt( -1 );
		yume_tsoe_position( $u[0], $o['id'], $t[0], $c[0][1], 95, $maj );   // 2e chapitre à 95 % : 2.
		yume_tsoe_position( $u[1], $o['id'], $t[0], $c[0][1], 50, $maj );   // 2e chapitre à 50 % : 1.
		yume_tsoe_position( $u[2], $o['id'], $t[0], $c[0][0], 10, $maj );   // 1er chapitre à 10 % : 0.
		yume_tsoe_position( $u[3], $o['id'], $t[1], $c[1][0], 0, $maj );    // Tome 2, 1er chapitre : 3.
		yume_tsoe_position( $u[4], $o['id'], $t[1], $c[1][2], 100, $maj );  // Dernier chapitre lu : 6.
		yume_tsoe_position( $u[5], $o['id'], $t[0], 123456, 100, $maj );    // Chapitre inconnu : 0.
		$compteurs = compteurs_oeuvre( $o['id'] );
		yume_assert_same( 12, $compteurs['chapitres_lus'] );
		yume_assert_same( 6, $compteurs['lecteurs'] );

		// Le cas de référence : 3 chapitres, position au 2e à 95 % → 2 lus.
		$seul = yume_tsoe_oeuvre( 'Delta', 1, 3 );
		yume_tsoe_position( $u[0], $seul['id'], $seul['tomes'][0], $seul['chapitres'][0][1], 95, $maj );
		yume_assert_same( 2, compteurs_oeuvre( $seul['id'] )['chapitres_lus'] );
		yume_assert_same( 2, compteurs_oeuvres()[ $seul['id'] ]['chapitres_lus'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Classement
 * -----------------------------------------------------------------------------
 */

yume_tsoe_test(
	'classement : tris, pagination, nouveaux favoris de la période, vues et croissance tirées de l’historique',
	function () {
		$a = yume_tsoe_oeuvre( 'Aube' )['id'];
		$b = yume_tsoe_oeuvre( 'Brume' )['id'];
		$c = yume_tsoe_oeuvre( 'Cendre' )['id'];
		$u = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$u[] = yume_tsoe_compte( '2026-01-01 00:00:00' );
		}
		// Aube : 1 favori ancien. Brume : 3 favoris dont 2 de la semaine. Cendre : 2 favoris anciens.
		yume_tsoe_favori( $u[0], $a, 'immediat', '2026-05-01 00:00:00' );
		yume_tsoe_favori( $u[0], $b, 'immediat', '2026-05-01 00:00:00' );
		yume_tsoe_favori( $u[1], $b, 'jamais', yume_tsoe_gmt( -2 ) );
		yume_tsoe_favori( $u[2], $b, 'hebdo', yume_tsoe_gmt( -6 ) );
		yume_tsoe_favori( $u[3], $b, 'hebdo', yume_tsoe_gmt( -8 ) );      // Hors période de 7 jours.
		yume_tsoe_favori( $u[0], $c, 'immediat', '2026-05-01 00:00:00' );
		yume_tsoe_favori( $u[1], $c, 'immediat', '2026-05-01 00:00:00' );
		// Lecteurs : Aube 3, Brume 1, Cendre 0.
		yume_tsoe_position( $u[0], $a, 0, 0, 0, yume_tsoe_gmt( -1 ) );
		yume_tsoe_position( $u[1], $a, 0, 0, 0, yume_tsoe_gmt( -1 ) );
		yume_tsoe_position( $u[2], $a, 0, 0, 0, yume_tsoe_gmt( -1 ) );
		yume_tsoe_position( $u[0], $b, 0, 0, 0, yume_tsoe_gmt( -1 ) );
		// Historique (période de 7 jours : 9 au 15 septembre ; précédente : 2 au 8).
		yume_tsoe_historique(
			'2026-09-08',
			$b,
			array(
				'favoris' => 1,
				'vues'    => 40,
			)
		);
		yume_tsoe_historique(
			'2026-09-10',
			$b,
			array(
				'favoris' => 2,
				'vues'    => 30,
			)
		);
		yume_tsoe_historique(
			'2026-09-14',
			$b,
			array(
				'favoris' => 3,
				'vues'    => 50,
			)
		);
		yume_tsoe_historique( '2026-09-03', $b, array( 'vues' => 5 ) );
		yume_tsoe_historique( '2026-09-12', $c, array( 'favoris' => 2 ) ); // Vues inconnues (NULL).
		yume_tsoe_historique( '2026-09-14', 0, array( 'vues' => 999 ) );   // Ligne du site : ignorée.

		$r = classement( 7 );
		yume_assert_same( array( 'tri', 'total', 'page', 'pages', 'oeuvres' ), array_keys( $r ) );
		yume_assert_same( 'favoris', $r['tri'] );
		yume_assert_same( 3, $r['total'] );
		yume_assert_same( 1, $r['pages'] );
		yume_assert_same( array( $b, $c, $a ), wp_list_pluck( $r['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same(
			array( 'oeuvre_id', 'titre', 'url', 'favoris', 'suiveurs', 'lecteurs', 'lecteurs_30j', 'vues', 'nouveaux_favoris', 'croissance_favoris', 'croissance_vues' ),
			array_keys( $r['oeuvres'][0] )
		);
		$brume = $r['oeuvres'][0];
		yume_assert_same( 'Brume', $brume['titre'] );
		yume_assert_true( '' !== $brume['url'], 'adresse publique' );
		yume_assert_same( 4, $brume['favoris'] );
		yume_assert_same( 3, $brume['suiveurs'] );
		yume_assert_same( 2, $brume['nouveaux_favoris'], 'favoris ajoutés dans les 7 jours' );
		yume_assert_same( 80, $brume['vues'], 'vues des 9–15 septembre' );
		yume_assert_same( 35, $brume['croissance_vues'], '80 − 45 (période précédente)' );
		yume_assert_same( 3, $brume['croissance_favoris'], '4 aujourd’hui − 1 la veille de la période' );
		$cendre = $r['oeuvres'][1];
		yume_assert_same( null, $cendre['vues'], 'vues inconnues' );
		yume_assert_same( null, $cendre['croissance_vues'] );
		yume_assert_same( 0, $cendre['croissance_favoris'], 'première ligne de la fenêtre (12 septembre)' );
		yume_assert_same( null, $r['oeuvres'][2]['croissance_favoris'], 'sans historique' );

		// Autres tris (valeurs inconnues en dernier, égalité : favoris puis titre).
		yume_assert_same( array( $a, $b, $c ), wp_list_pluck( classement( 7, 'lecteurs' )['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( array( $b, $c, $a ), wp_list_pluck( classement( 7, 'vues' )['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( array( $b, $c, $a ), wp_list_pluck( classement( 7, 'nouveaux' )['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( array( $b, $c, $a ), wp_list_pluck( classement( 7, 'croissance' )['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( array( $b, $c, $a ), wp_list_pluck( classement( 7, 'suiveurs' )['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( 'favoris', classement( 7, 'inconnu' )['tri'] );
		// Période de 30 jours : le favori du 7 septembre est nouveau.
		yume_assert_same( 3, classement( 30 )['oeuvres'][0]['nouveaux_favoris'] );

		// Pagination.
		$p = classement( 7, 'favoris', 2, 2 );
		yume_assert_same( 3, $p['total'] );
		yume_assert_same( 2, $p['page'] );
		yume_assert_same( 2, $p['pages'] );
		yume_assert_same( array( $a ), wp_list_pluck( $p['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( array(), classement( 7, 'favoris', 2, 5 )['oeuvres'], 'page au-delà de la dernière' );
		yume_assert_same( 1, count( classement( 7, 'favoris', 0 )['oeuvres'] ), 'limite ramenée à 1' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Fiche d'une œuvre
 * -----------------------------------------------------------------------------
 */

yume_tsoe_test(
	'fiche d’une œuvre : compteurs, période, fréquences, série, tomes, début de l’historique',
	function () {
		$o = yume_tsoe_oeuvre( 'Écume <b>&amp;</b> sel', 2, 2 );
		$u = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$u[] = yume_tsoe_compte( '2026-01-01 00:00:00' );
		}
		yume_tsoe_favori( $u[0], $o['id'], 'immediat', yume_tsoe_gmt( -3 ) );
		yume_tsoe_favori( $u[1], $o['id'], 'hebdo', '2026-05-01 00:00:00' );
		yume_tsoe_favori( $u[2], $o['id'], 'jamais', '2026-05-01 00:00:00' );
		yume_tsoe_position( $u[0], $o['id'], $o['tomes'][0], $o['chapitres'][0][1], 100, yume_tsoe_gmt( -2 ) );
		yume_tsoe_position( $u[1], $o['id'], $o['tomes'][1], $o['chapitres'][1][0], 20, yume_tsoe_gmt( -20 ) );
		yume_tsoe_historique( '2026-09-01', $o['id'], array( 'favoris' => 1 ) );
		yume_tsoe_historique(
			'2026-09-12',
			$o['id'],
			array(
				'favoris'      => 2,
				'suiveurs'     => 1,
				'lecteurs_30j' => 2,
				'vues'         => 7,
			)
		);
		yume_tsoe_historique( '2026-09-10', $o['id'], array( 'favoris' => 2 ) );

		$f = fiche_oeuvre( $o['id'], 7 );
		yume_assert_same( array( 'oeuvre', 'compteurs', 'periode', 'frequences', 'serie', 'tomes', 'depuis_historique' ), array_keys( $f ) );
		yume_assert_same( array( 'id', 'titre', 'url' ), array_keys( $f['oeuvre'] ) );
		yume_assert_same( 'Écume & sel', $f['oeuvre']['titre'] );
		yume_assert_same( compteurs_oeuvre( $o['id'] ), $f['compteurs'] );
		yume_assert_same( 4, $f['compteurs']['chapitres_lus'], '2 (tome 1, chapitre 2 lu) + 2 (tome 2, chapitre 1 entamé)' );
		yume_assert_same(
			array(
				'nouveaux_favoris' => 1,
				'lecteurs_actifs'  => 1,
				'vues'             => 7,
				'vues_prec'        => null,
			),
			$f['periode']
		);
		yume_assert_same(
			array(
				'immediat' => 1,
				'hebdo'    => 1,
				'jamais'   => 1,
			),
			$f['frequences']
		);
		yume_assert_same(
			array(
				array(
					'date'         => '2026-09-10',
					'favoris'      => 2,
					'suiveurs'     => 0,
					'lecteurs_30j' => 0,
					'vues'         => null,
				),
				array(
					'date'         => '2026-09-12',
					'favoris'      => 2,
					'suiveurs'     => 1,
					'lecteurs_30j' => 2,
					'vues'         => 7,
				),
			),
			$f['serie']
		);
		yume_assert_same(
			array(
				array(
					'tome_id'              => $o['tomes'][0],
					'titre'                => 'Écume & sel — Tome 1',
					'chapitres'            => 2,
					'lecteurs_positionnes' => 1,
				),
				array(
					'tome_id'              => $o['tomes'][1],
					'titre'                => 'Écume & sel — Tome 2',
					'chapitres'            => 2,
					'lecteurs_positionnes' => 1,
				),
			),
			$f['tomes']
		);
		yume_assert_same( '2026-09-01', $f['depuis_historique'] );

		// Sans historique ; contenu qui n'est pas une œuvre ; œuvre à la corbeille.
		$vide = yume_tsoe_oeuvre( 'Vide' )['id'];
		$fv   = fiche_oeuvre( $vide, 30 );
		yume_assert_same( null, $fv['depuis_historique'] );
		yume_assert_same( array(), $fv['serie'] );
		yume_assert_same( array(), $fv['tomes'] );
		yume_assert_same( null, fiche_oeuvre( $o['tomes'][0], 7 ) );
		yume_assert_same( null, fiche_oeuvre( 0, 7 ) );
		wp_trash_post( $vide );
		yume_assert_same( null, fiche_oeuvre( $vide, 7 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Lecteurs du site
 * -----------------------------------------------------------------------------
 */

yume_tsoe_test(
	'résumé des lecteurs : comptes lecteurs seulement, inscriptions par jour du site, actifs, favoris',
	function () {
		$avant = resume_lecteurs( 7 );
		cache_vider();
		$o = yume_tsoe_oeuvre( 'Zéphyr' )['id'];
		// Période : 9–15 septembre (Paris) ; précédente : 2–8 septembre.
		$l1 = yume_tsoe_compte( '2026-09-14 23:30:00' );           // 15 septembre à Paris.
		$l2 = yume_tsoe_compte( '2026-09-10 08:00:00' );
		$l3 = yume_tsoe_compte( '2026-09-05 08:00:00' );           // Période précédente.
		$l4 = yume_tsoe_compte( '2026-09-08 22:30:00' );           // 9 septembre à Paris.
		yume_tsoe_compte( '2026-03-01 08:00:00' );                 // Ancien.
		yume_tsoe_compte( '2026-09-12 08:00:00', 'yume_traducteur' ); // Équipe : pas un compte lecteur.
		yume_tsoe_favori( $l1, $o, 'immediat' );
		yume_tsoe_favori( $l2, $o, 'hebdo' );
		yume_tsoe_favori( $l3, $o, 'jamais' );
		yume_tsoe_position( $l1, $o, 0, 0, 0, yume_tsoe_gmt( -1 ) );
		yume_tsoe_position( $l2, $o, 0, 0, 0, yume_tsoe_gmt( -10 ) );   // Période précédente.
		yume_tsoe_position( $l3, $o, 0, 0, 0, yume_tsoe_gmt( -11 ) );   // Période précédente.

		$r = resume_lecteurs( 7 );
		yume_assert_same(
			array( 'comptes', 'inscriptions', 'inscriptions_prec', 'variation_inscriptions', 'actifs', 'actifs_prec', 'variation_actifs', 'favoris_total', 'suiveurs_total', 'lecteurs_avec_favori', 'serie_inscriptions', 'frequences' ),
			array_keys( $r )
		);
		yume_assert_same( $avant['comptes'] + 5, $r['comptes'], 'cinq comptes lecteurs (subscriber)' );
		yume_assert_same( $avant['inscriptions'] + 3, $r['inscriptions'] );
		yume_assert_same( $avant['inscriptions_prec'] + 1, $r['inscriptions_prec'] );
		if ( 0 === $avant['inscriptions_prec'] && 0 === $avant['inscriptions'] ) {
			yume_assert_same( 200.0, $r['variation_inscriptions'] );
		}
		yume_assert_same( 1, $r['actifs'] );
		yume_assert_same( 2, $r['actifs_prec'] );
		yume_assert_same( -50.0, $r['variation_actifs'] );
		yume_assert_same( 3, $r['favoris_total'] );
		yume_assert_same( 2, $r['suiveurs_total'] );
		yume_assert_same( 3, $r['lecteurs_avec_favori'] );
		yume_assert_same(
			array(
				'immediat' => 1,
				'hebdo'    => 1,
				'jamais'   => 1,
			),
			$r['frequences']
		);
		yume_assert_same( 7, count( $r['serie_inscriptions'] ) );
		yume_assert_same( '2026-09-09', $r['serie_inscriptions'][0]['date'] );
		yume_assert_same( '2026-09-15', $r['serie_inscriptions'][6]['date'] );
		$par_jour = array_column( $r['serie_inscriptions'], 'n', 'date' );
		$avant_j  = array_column( $avant['serie_inscriptions'], 'n', 'date' );
		yume_assert_same( 1, $par_jour['2026-09-15'] - $avant_j['2026-09-15'], 'inscrit à 23 h 30 UTC : 15 septembre à Paris' );
		yume_assert_same( 1, $par_jour['2026-09-09'] - $avant_j['2026-09-09'], 'inscrit à 22 h 30 UTC : 9 septembre à Paris' );
		yume_assert_same( 1, $par_jour['2026-09-10'] - $avant_j['2026-09-10'] );
		yume_assert_same( $r['inscriptions'], array_sum( $par_jour ) );
		yume_assert_same( 365, count( resume_lecteurs( 365 )['serie_inscriptions'] ) );
		unset( $l4 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Historique
 * -----------------------------------------------------------------------------
 */

yume_tsoe_test(
	'calculer_jour() : ligne du site et ligne par œuvre publiée, bornées à la fin du jour, idempotent (REPLACE)',
	function () {
		$o = yume_tsoe_oeuvre( 'Iris', 1, 2 );
		$p = yume_tsoe_oeuvre( 'Jade' )['id'];
		$u = array(
			yume_tsoe_compte( '2026-09-14 10:00:00' ),  // Inscrit le 14.
			yume_tsoe_compte( '2026-09-15 08:00:00' ),  // Inscrit le 15 : après le jour calculé.
			yume_tsoe_compte( '2026-01-01 00:00:00' ),
		);
		yume_tsoe_favori( $u[0], $o['id'], 'immediat', '2026-09-14 10:00:00' );
		yume_tsoe_favori( $u[2], $o['id'], 'jamais', '2026-05-01 00:00:00' );
		yume_tsoe_favori( $u[1], $o['id'], 'immediat', '2026-09-15 08:00:00' ); // Après le 14 : ignoré.
		yume_tsoe_liste( $u[0], 'a_lire', array( $o['id'] ), '2026-09-14 10:00:00' );
		yume_tsoe_liste( $u[1], 'en_cours', array( $o['id'] ), '2026-09-15 09:00:00' ); // Ignoré.
		yume_tsoe_position( $u[0], $o['id'], $o['tomes'][0], $o['chapitres'][0][1], 95, '2026-09-14 12:00:00' );
		yume_tsoe_position( $u[2], $o['id'], $o['tomes'][0], $o['chapitres'][0][0], 10, '2026-07-01 12:00:00' );
		yume_tsoe_position( $u[2], $p, 0, 0, 10, '2026-09-01 12:00:00' );

		yume_assert_same( 3, calculer_jour( '2026-09-14' ), 'site + 2 œuvres' );
		yume_assert_same( 3, calculer_jour( '2026-09-14' ), 'recalcul' );
		yume_assert_same( 0, calculer_jour( '2026-02-30' ), 'jour invalide' );
		yume_assert_same( 0, calculer_jour( 'hier' ) );
		$lignes = yume_tsoe_lignes();
		yume_assert_same( array( '2026-09-14' ), array_keys( $lignes ) );
		yume_assert_same( array( 0, $o['id'], $p ), array_keys( $lignes['2026-09-14'] ), 'une ligne par clé' );
		$oeuvre = $lignes['2026-09-14'][ $o['id'] ];
		yume_assert_same( 2, $oeuvre['favoris'] );
		yume_assert_same( 1, $oeuvre['suiveurs'] );
		yume_assert_same( 1, $oeuvre['listes'] );
		yume_assert_same( 2, $oeuvre['lecteurs'] );
		yume_assert_same( 1, $oeuvre['lecteurs_30j'], 'fenêtre de 30 jours finissant le 14' );
		yume_assert_same( 2, $oeuvre['chapitres_lus'] );
		yume_assert_same( 0, $oeuvre['comptes'] );
		$site = $lignes['2026-09-14'][0];
		yume_assert_same( 2, $site['favoris'] );
		yume_assert_same( 1, $site['suiveurs'] );
		yume_assert_same( 1, $site['listes'] );
		yume_assert_same( 2, $site['lecteurs'], 'comptes distincts ayant une position' );
		yume_assert_same( 2, $site['lecteurs_30j'] );
		yume_assert_same( 2, $site['chapitres_lus'] );
		yume_assert_same( 1, $site['inscriptions'] );
		yume_assert_true( $site['comptes'] >= 2, 'comptes lecteurs inscrits au plus tard le 14' );
		if ( ! function_exists( 'Yume\\Core\\Statistiques\\trafic_visites_jour' ) ) {
			yume_assert_same( null, $site['vues'], 'trafic indisponible : NULL' );
			yume_assert_same( null, $site['visiteurs'] );
			yume_assert_same( null, $oeuvre['vues'] );
		}
		yume_assert_same( null, $oeuvre['visiteurs'], 'pas de visiteurs par œuvre' );
	}
);

yume_tsoe_test(
	'tache_quotidienne() : veille, rattrapage des jours manquants (7 au plus), purge au-delà de la rétention',
	function () {
		$o = yume_tsoe_oeuvre( 'Kaki' )['id'];
		// Lignes anciennes : 401 jours (purgée) et 399 jours (conservée) avant le 15 septembre 2026.
		yume_tsoe_historique( '2025-08-10', $o, array( 'favoris' => 1 ) );
		yume_tsoe_historique( '2025-08-12', $o, array( 'favoris' => 1 ) );

		// Sans dernier jour : la veille seulement.
		tache_quotidienne();
		yume_assert_same( array( '2025-08-12', '2026-09-14' ), array_keys( yume_tsoe_lignes() ) );
		yume_assert_same( '2026-09-14', get_option( OPTION_DERNIER_JOUR ) );

		// Déjà à jour : rien n'est recalculé.
		yume_tsoe_historique( '2026-09-14', $o, array( 'favoris' => 42 ) );
		tache_quotidienne();
		yume_assert_same( 42, yume_tsoe_lignes()['2026-09-14'][ $o ]['favoris'] );

		// Trois jours manquants.
		update_option( OPTION_DERNIER_JOUR, '2026-09-11' );
		tache_quotidienne();
		yume_assert_same( array( '2025-08-12', '2026-09-12', '2026-09-13', '2026-09-14' ), array_keys( yume_tsoe_lignes() ) );
		yume_assert_same( 0, yume_tsoe_lignes()['2026-09-14'][ $o ]['favoris'], 'recalculé' );

		// Longue interruption : 7 jours au plus.
		update_option( OPTION_DERNIER_JOUR, '2026-08-01' );
		tache_quotidienne();
		yume_assert_same( array( '2025-08-12', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14' ), array_keys( yume_tsoe_lignes() ) );

		// Rétention réduite par le filtre.
		$retention = static fn() => 5;
		add_filter( 'yume_statistiques_retention', $retention );
		try {
			tache_quotidienne();
		} finally {
			remove_filter( 'yume_statistiques_retention', $retention );
		}
		yume_assert_same( array( '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14' ), array_keys( yume_tsoe_lignes() ) );

		// lancer_tache_jour() (callback de la tâche planifiée) passe par tache_quotidienne().
		delete_option( OPTION_DERNIER_JOUR );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . table_stats_jour() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		do_action( \Yume\Core\Statistiques\HOOK_JOUR );
		yume_assert_same( array( '2026-09-14' ), array_keys( yume_tsoe_lignes() ) );
	}
);

yume_tsoe_test(
	'serie() et points() : lignes triées par jour, trous non comblés, NULL conservé, colonne contrôlée',
	function () {
		$o = yume_tsoe_oeuvre( 'Lune' )['id'];
		yume_tsoe_historique(
			'2026-09-13',
			$o,
			array(
				'favoris' => 5,
				'vues'    => 12,
			)
		);
		yume_tsoe_historique( '2026-09-10', $o, array( 'favoris' => 3 ) );
		yume_tsoe_historique(
			'2026-09-14',
			$o,
			array(
				'favoris' => 6,
				'vues'    => 0,
			)
		);
		yume_tsoe_historique( '2026-09-01', $o, array( 'favoris' => 1 ) );
		yume_tsoe_historique( '2026-09-13', 0, array( 'favoris' => 50 ) );
		$s = serie( $o, '2026-09-09', '2026-09-14' );
		yume_assert_same( array( '2026-09-10', '2026-09-13', '2026-09-14' ), array_column( $s, 'jour' ) );
		yume_assert_same(
			array( 'jour', 'oeuvre_id', 'vues', 'visiteurs', 'favoris', 'suiveurs', 'listes', 'lecteurs', 'lecteurs_30j', 'chapitres_lus', 'comptes', 'inscriptions' ),
			array_keys( $s[0] )
		);
		yume_assert_same( $o, $s[0]['oeuvre_id'] );
		yume_assert_same( null, $s[0]['vues'] );
		yume_assert_same( 3, $s[0]['favoris'] );
		yume_assert_same(
			array(
				array(
					'date'   => '2026-09-10',
					'valeur' => null,
				),
				array(
					'date'   => '2026-09-13',
					'valeur' => 12,
				),
				array(
					'date'   => '2026-09-14',
					'valeur' => 0,
				),
			),
			points( $o, '2026-09-09', '2026-09-14', 'vues' )
		);
		yume_assert_same( array( 50 ), array_column( points( 0, '2026-09-01', '2026-09-30', 'favoris' ), 'valeur' ), 'ligne du site' );
		yume_assert_same( array(), points( $o, '2026-09-09', '2026-09-14', 'user_id' ), 'colonne inconnue' );
		yume_assert_same( array(), serie( $o, '14/09/2026', '2026-09-14' ), 'date invalide' );
	}
);

yume_tsoe_test(
	'reconstituer_favoris() : estimation depuis created_at, seulement sur une table vide',
	function () {
		$o = yume_tsoe_oeuvre( 'Mûre' )['id'];
		$p = yume_tsoe_oeuvre( 'Nacre' )['id'];
		$u = array(
			yume_tsoe_compte( '2026-01-01 00:00:00' ),
			yume_tsoe_compte( '2026-01-01 00:00:00' ),
		);
		yume_tsoe_favori( $u[0], $o, 'immediat', '2026-05-01 00:00:00' );     // Avant la fenêtre.
		yume_tsoe_favori( $u[1], $o, 'immediat', '2026-09-12 21:30:00' );     // 12 sept. 23 h 30 à Paris.
		yume_tsoe_favori( $u[0], $p, 'immediat', '2026-09-12 22:30:00' );     // 13 sept. 0 h 30 à Paris.
		yume_tsoe_favori( $u[1], $p, 'immediat', yume_tsoe_gmt( -0.01 ) );   // Aujourd'hui : exclu.

		yume_assert_same( 15, reconstituer_favoris( 5 ), '5 jours × (site + 2 œuvres)' );
		$lignes = yume_tsoe_lignes();
		yume_assert_same( array( '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14' ), array_keys( $lignes ) );
		yume_assert_same( array( 1, 1, 2, 2, 2 ), array_map( static fn( $jour ) => $jour[ $o ]['favoris'], array_values( $lignes ) ) );
		yume_assert_same( array( 0, 0, 0, 1, 1 ), array_map( static fn( $jour ) => $jour[ $p ]['favoris'], array_values( $lignes ) ) );
		yume_assert_same( array( 1, 1, 2, 3, 3 ), array_map( static fn( $jour ) => $jour[0]['favoris'], array_values( $lignes ) ), 'site' );
		yume_assert_same( 0, $lignes['2026-09-14'][ $o ]['suiveurs'], 'colonne favoris seule' );
		yume_assert_same( null, $lignes['2026-09-14'][ $o ]['vues'] );

		// Table déjà remplie : rien.
		yume_assert_same( 0, reconstituer_favoris( 90 ) );
		yume_assert_same( 5, count( yume_tsoe_lignes() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Cache, REST, performance
 * -----------------------------------------------------------------------------
 */

yume_tsoe_test(
	'cache vidé quand un favori est ajouté ou retiré, un tome ou un chapitre publié',
	function () {
		foreach ( array( 'yume_favori_ajoute', 'yume_favori_retire', 'yume_tome_publie', 'yume_chapitre_publie' ) as $action ) {
			yume_assert_true( false !== has_action( $action, 'Yume\\Core\\Statistiques\\cache_vider' ), $action );
		}
		// Rappel appelé sans argument (accepted_args 0) : les autres rappels de l'action ne sont pas joués ici.
		cache_ecrire( 'compteurs', array( 'x' ), 300 );
		do_action( 'yume_favori_retire', 1, 2 );
		yume_assert_false( cache_lire( 'compteurs' ), 'vidé' );
		yume_assert_false( has_action( 'yume_progression_enregistree', 'Yume\\Core\\Statistiques\\cache_vider' ), 'pas à chaque position' );

		// Parcours réel : ajout d'un favori par le module social.
		$o = yume_tsoe_oeuvre( 'Opale' )['id'];
		$u = yume_factory_user( 'subscriber' );
		yume_assert_same( 0, compteurs_oeuvres()[ $o ]['favoris'] );
		yume_assert_true( true === \Yume\Core\Social\ajouter_favori( $u, $o ) );
		yume_assert_same( 1, compteurs_oeuvres()[ $o ]['favoris'] );
		\Yume\Core\Social\retirer_favori( $u, $o );
		yume_assert_same( 0, compteurs_oeuvres()[ $o ]['favoris'] );
	}
);

yume_tsoe_test(
	'REST : /resume, /oeuvres et /oeuvres/{id} répondent 200 à l’équipe',
	function () {
		$o = yume_tsoe_oeuvre( 'Perle', 1, 1 )['id'];
		$u = yume_tsoe_compte( '2026-01-01 00:00:00' );
		yume_tsoe_favori( $u, $o );
		$traducteur = yume_factory_user( 'yume_traducteur' );

		$r = yume_rest( 'GET', '/yume/v1/statistiques/resume', array( 'periode' => '7' ), $traducteur );
		yume_assert_same( 200, $r->get_status() );
		$d = $r->get_data();
		yume_assert_same( 7, $d['periode'] );
		yume_assert_same( 1, $d['lecteurs']['favoris_total'] );
		yume_assert_same( array( $o ), wp_list_pluck( $d['top_suivies'], 'oeuvre_id' ) );

		$r = yume_rest( 'GET', '/yume/v1/statistiques/oeuvres', array( 'tri' => 'lecteurs' ), $traducteur );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 'lecteurs', $r->get_data()['tri'] );
		yume_assert_same( 1, $r->get_data()['total'] );

		$r = yume_rest( 'GET', '/yume/v1/statistiques/oeuvres/' . $o, array( 'periode' => '90' ), $traducteur );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( $o, $r->get_data()['oeuvre']['id'] );
		yume_assert_same( 1, count( $r->get_data()['tomes'] ) );
		yume_assert_contains( 'no-store', (string) ( $r->get_headers()['Cache-Control'] ?? '' ) );

		// Aucune donnée personnelle dans les réponses agrégées.
		$json = (string) wp_json_encode( $r->get_data() ) . wp_json_encode( yume_rest( 'GET', '/yume/v1/statistiques/resume', array(), $traducteur )->get_data() );
		yume_assert_not_contains( '@example.test', $json );
		yume_assert_not_contains( 'tsoe_', $json );
	}
);

yume_tsoe_test(
	'performance : tâche quotidienne en moins de 2 s pour 50 œuvres et 5 000 positions',
	function () {
		global $wpdb;
		$oeuvres = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$oeuvres[] = yume_tsoe_oeuvre( sprintf( 'Perf %02d', $i ), 1, 4 );
		}
		$comptes = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$comptes[] = yume_tsoe_compte( '2026-01-01 00:00:00' );
		}
		// 5 000 positions (100 comptes × 50 œuvres) et 2 500 favoris, insérés par paquets.
		$positions = array();
		$favoris   = array();
		foreach ( $comptes as $n => $user_id ) {
			foreach ( $oeuvres as $k => $o ) {
				$positions[] = $wpdb->prepare( '(%d, %d, %d, %d, 0, %d, %s)', $user_id, $o['id'], $o['tomes'][0], $o['chapitres'][0][ ( $n + $k ) % 4 ], ( $n * 7 ) % 101, yume_tsoe_gmt( -( $n % 60 ) ) );
				if ( 0 === ( $n + $k ) % 2 ) {
					$favoris[] = $wpdb->prepare( '(%d, %d, %s, %s)', $user_id, $o['id'], 0 === $n % 3 ? 'jamais' : 'immediat', '2026-06-01 00:00:00' );
				}
			}
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( array_chunk( $positions, 500 ) as $paquet ) {
			$wpdb->query( 'INSERT INTO ' . \Yume\Core\Reader\table_progression() . ' (user_id, oeuvre_id, tome_id, chapitre_id, paragraphe, pourcentage, updated_at) VALUES ' . implode( ', ', $paquet ) );
		}
		foreach ( array_chunk( $favoris, 500 ) as $paquet ) {
			$wpdb->query( 'INSERT INTO ' . \Yume\Core\Social\table_favoris() . ' (user_id, oeuvre_id, frequence, created_at) VALUES ' . implode( ', ', $paquet ) );
		}
		yume_assert_same( 5000, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Yume\Core\Reader\table_progression() ) );
		// phpcs:enable

		$debut = microtime( true );
		tache_quotidienne();
		$duree = microtime( true ) - $debut;
		yume_assert_true( $duree < 2.0, sprintf( 'durée : %.3f s', $duree ) );
		$site = yume_tsoe_lignes()['2026-09-14'][0];
		yume_assert_same( 51, count( yume_tsoe_lignes()['2026-09-14'] ) );
		yume_assert_same( 100, $site['lecteurs'] );
		yume_assert_same( 2500, $site['favoris'] );
		yume_assert_true( $site['chapitres_lus'] > 0 );

		// Compteurs en direct (rendu) : même ordre de grandeur.
		cache_vider();
		$debut = microtime( true );
		$c     = compteurs_oeuvres();
		$duree = microtime( true ) - $debut;
		yume_assert_same( 50, count( $c ) );
		yume_assert_true( $duree < 1.0, sprintf( 'compteurs : %.3f s', $duree ) );
	}
);
