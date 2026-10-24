<?php
/**
 * Tests des données de la page « Comptes inscrits » (lot A) : liste paginée (total, pages),
 * chaque filtre et chaque tri (inconnues en dernier), recherche (pseudo, nom Discord, e-mail et
 * identifiant exacts, jokers % et _ échappés), comptes supprimés absents, lecture estimée
 * identique à Reader\statistiques_lecture(), nombre de requêtes borné, indicateurs en cache,
 * fiche complète sans donnée interdite (e-mail, identifiant, IP, push, réglages, listes privées).
 *
 * Lancement : tools/localenv/test.sh comptes-donnees
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Comptes\INDICATEURS_TRANSIENT;
use const Yume\Core\Comptes\META_ACTIVITE;
use const Yume\Core\Comptes\META_SOURCE;
use const Yume\Core\Comptes\OPTION_DEPUIS;
use function Yume\Core\Comptes\compter_comptes;
use function Yume\Core\Comptes\fiche_compte;
use function Yume\Core\Comptes\indicateurs;
use function Yume\Core\Comptes\lectures_par_compte;
use function Yume\Core\Comptes\lister_comptes;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tcd_)
 * -----------------------------------------------------------------------------
 */

/**
 * « Maintenant » des tests : 24 octobre 2026, 12 h UTC (14 h à Paris).
 */
function yume_tcd_maintenant(): int {
	return 1792843200;
}

/**
 * Déclare un test : « maintenant » figé, fuseau Europe/Paris, cache des indicateurs vidé.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps (reçoit une étiquette unique à mettre dans les pseudos).
 */
function yume_tcd_test( string $nom, callable $corps ): void {
	yume_test(
		'comptes données : ' . $nom,
		static function () use ( $corps ) {
			$fige   = static fn() => yume_tcd_maintenant();
			$fuseau = static fn() => 'Europe/Paris';
			add_filter( 'yume_comptes_maintenant', $fige );
			add_filter( 'pre_option_timezone_string', $fuseau );
			delete_transient( INDICATEURS_TRANSIENT );
			try {
				$corps( 'Tcd' . wp_rand( 100000, 999999 ) );
			} finally {
				remove_filter( 'yume_comptes_maintenant', $fige );
				remove_filter( 'pre_option_timezone_string', $fuseau );
				delete_transient( INDICATEURS_TRANSIENT );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Date GMT « Y-m-d H:i:s » à $jours jours de « maintenant ».
 *
 * @param float $jours Décalage (négatif : passé).
 */
function yume_tcd_gmt( float $jours ): string {
	return gmdate( 'Y-m-d H:i:s', (int) ( yume_tcd_maintenant() + $jours * DAY_IN_SECONDS ) );
}

/**
 * Jour 'Y-m-d' (Paris) à $jours jours de « maintenant ».
 *
 * @param int $jours Décalage (négatif : passé).
 */
function yume_tcd_jour( int $jours ): string {
	return wp_date( 'Y-m-d', yume_tcd_maintenant() + $jours * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Paris' ) );
}

/**
 * Compte inséré directement (rapide).
 *
 * @param string $pseudo  Pseudo (display_name, tel qu'enregistré).
 * @param string $role    Rôle.
 * @param string $inscrit Date GMT d'inscription.
 * @param string $login   Identifiant ('' : généré « tcd_… »).
 * @return int
 */
function yume_tcd_compte( string $pseudo, string $role = 'subscriber', string $inscrit = '2026-01-01 00:00:00', string $login = '' ): int {
	global $wpdb;
	static $n = 0;
	++$n;
	$login = '' !== $login ? $login : 'tcd_' . $n . '_' . wp_rand( 1000, 999999 );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->users,
		array(
			'user_login'      => $login,
			'user_pass'       => '',
			'user_nicename'   => sanitize_title( $login ),
			'user_email'      => $login . '@exemple-tcd.test',
			'user_url'        => '',
			'user_registered' => $inscrit,
			'display_name'    => $pseudo,
		)
	);
	$id = (int) $wpdb->insert_id;
	$wpdb->insert(
		$wpdb->usermeta,
		array(
			'user_id'    => $id,
			'meta_key'   => $wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => serialize( array( $role => true ) ), // phpcs:ignore WordPress.DB.SlowDBQuery, WordPress.PHP.DiscouragedPHPFunctions
		)
	);
	// phpcs:enable
	return $id;
}

/**
 * Identifiants des comptes listés.
 *
 * @param array $args Paramètres de lister_comptes().
 * @return int[]
 */
function yume_tcd_ids( array $args ): array {
	return array_map( static fn( array $c ): int => $c['id'], lister_comptes( $args )['comptes'] );
}

/**
 * Mêmes éléments, sans tenir compte de l'ordre.
 *
 * @param int[]  $attendu Attendu.
 * @param int[]  $obtenu  Obtenu.
 * @param string $msg     Message.
 */
function yume_tcd_memes( array $attendu, array $obtenu, string $msg = '' ): void {
	sort( $attendu );
	sort( $obtenu );
	yume_assert_same( $attendu, $obtenu, $msg );
}

/**
 * Contenu publié inséré directement (sans annonce de publication).
 *
 * @param string $type   Type.
 * @param string $titre  Titre.
 * @param array  $metas  Métas.
 * @param int    $ordre  menu_order.
 * @param string $statut Statut.
 */
function yume_tcd_contenu( string $type, string $titre, array $metas = array(), int $ordre = 0, string $statut = 'publish' ): int {
	global $wpdb;
	$date = '2026-01-01 10:00:00';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->posts,
		array(
			'post_type'             => $type,
			'post_status'           => $statut,
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
 * Œuvre avec $nb_tomes tomes publiés de $nb_chapitres chapitres publiés (10 minutes chacun).
 *
 * @param string $titre        Titre.
 * @param int    $nb_tomes     Tomes.
 * @param int    $nb_chapitres Chapitres par tome.
 * @param string $statut       Statut de l'œuvre.
 * @return array{id:int,tomes:int[],chapitres:int[][]}
 */
function yume_tcd_oeuvre( string $titre, int $nb_tomes, int $nb_chapitres, string $statut = 'publish' ): array {
	$oeuvre    = yume_tcd_contenu( 'yume_oeuvre', $titre, array(), 0, $statut );
	$tomes     = array();
	$chapitres = array();
	for ( $t = 1; $t <= $nb_tomes; $t++ ) {
		$tome    = yume_tcd_contenu(
			'yume_tome',
			$titre . ' — Tome ' . $t,
			array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => $t,
			)
		);
		$tomes[] = $tome;
		for ( $c = 1; $c <= $nb_chapitres; $c++ ) {
			$chapitres[ $t - 1 ][] = yume_tcd_contenu(
				'yume_chapitre',
				$titre . ' ' . $t . '.' . $c,
				array(
					'yume_oeuvre_id'     => $oeuvre,
					'yume_tome_id'       => $tome,
					'yume_numero'        => $c,
					'yume_temps_lecture' => 10,
				),
				$c
			);
		}
	}
	wp_cache_flush();
	return array(
		'id'        => $oeuvre,
		'tomes'     => $tomes,
		'chapitres' => $chapitres,
	);
}

/**
 * Position de lecture insérée directement.
 *
 * @param int    $user_id     Compte.
 * @param int    $oeuvre_id   Œuvre.
 * @param int    $chapitre_id Chapitre.
 * @param int    $pourcentage Pourcentage lu.
 * @param string $maj         Date GMT.
 */
function yume_tcd_position( int $user_id, int $oeuvre_id, int $chapitre_id, int $pourcentage, string $maj = '2026-10-01 10:00:00' ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Reader\table_progression(),
		array(
			'user_id'     => $user_id,
			'oeuvre_id'   => $oeuvre_id,
			'tome_id'     => 0,
			'chapitre_id' => $chapitre_id,
			'paragraphe'  => 0,
			'pourcentage' => $pourcentage,
			'updated_at'  => $maj,
		)
	);
}

/**
 * Favori inséré directement.
 *
 * @param int    $user_id   Compte.
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence Fréquence.
 */
function yume_tcd_favori( int $user_id, int $oeuvre_id, string $frequence = 'immediat' ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Social\table_favoris(),
		array(
			'user_id'    => $user_id,
			'oeuvre_id'  => $oeuvre_id,
			'frequence'  => $frequence,
			'created_at' => '2026-09-01 08:00:00',
		)
	);
}

/**
 * Commentaire inséré directement (sans les actions de publication).
 *
 * @param int    $user_id Auteur.
 * @param int    $post_id Contenu.
 * @param string $statut  comment_approved (1, 0, spam, trash).
 * @param string $date    Date GMT.
 */
function yume_tcd_commentaire( int $user_id, int $post_id, string $statut = '1', string $date = '2026-10-01 10:00:00' ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->comments,
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Auteur',
			'comment_author_email' => 'ne-pas-afficher@exemple-tcd.test',
			'comment_author_IP'    => '203.0.113.9',
			'comment_date'         => $date,
			'comment_date_gmt'     => $date,
			'comment_content'      => 'Commentaire de test ' . $statut . ' sur la série.',
			'comment_approved'     => $statut,
			'comment_type'         => 'comment',
			'user_id'              => $user_id,
		)
	);
	return (int) $wpdb->insert_id;
}

/**
 * Cache d'objets vidé (contenus et comptes à relire), options du site rechargées hors mesure.
 */
function yume_tcd_cache_froid(): void {
	wp_cache_flush();
	wp_load_alloptions();
}

/**
 * Clés interdites (récursif) : identifiant de connexion, e-mail, IP, push, réglages.
 *
 * @param mixed  $valeur Valeur.
 * @param string $chemin Chemin (messages).
 */
function yume_tcd_sans_cles_interdites( $valeur, string $chemin = '' ): void {
	if ( ! is_array( $valeur ) ) {
		return;
	}
	foreach ( $valeur as $cle => $sous ) {
		yume_assert_false(
			in_array( (string) $cle, array( 'user_login', 'user_email', 'email', 'login', 'identifiant', 'user_pass', 'ip', 'ip_hash', 'endpoint', 'p256dh', 'auth', 'reglages', 'jeton', 'session' ), true ),
			'clé interdite : ' . $chemin . '.' . $cle
		);
		yume_tcd_sans_cles_interdites( $sous, $chemin . '.' . $cle );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Liste
 * -----------------------------------------------------------------------------
 */

yume_tcd_test(
	'pagination, total et tri par défaut (inscription décroissante)',
	function ( string $tag ) {
		$ids = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$ids[] = yume_tcd_compte( $tag . ' n' . $i, 'subscriber', yume_tcd_gmt( -10 * $i ) );
		}
		$page1 = lister_comptes(
			array(
				'q'      => $tag,
				'limite' => 3,
			)
		);
		yume_assert_same( 7, $page1['total'] );
		yume_assert_same( 3, $page1['pages'] );
		yume_assert_same( 1, $page1['page'] );
		yume_assert_same( 3, $page1['limite'] );
		yume_assert_same( $tag, $page1['args']['q'] );
		yume_assert_same( array_slice( $ids, 0, 3 ), array_column( $page1['comptes'], 'id' ), 'plus récent d’abord' );
		$page3 = lister_comptes(
			array(
				'q'      => $tag,
				'limite' => 3,
				'pg'     => 3,
			)
		);
		yume_assert_same( array( $ids[6] ), array_column( $page3['comptes'], 'id' ) );
		$loin = lister_comptes(
			array(
				'q'      => $tag,
				'limite' => 3,
				'page'   => 40,
			)
		);
		yume_assert_same( 3, $loin['page'], 'page au-delà : dernière page' );
		yume_assert_same( 7, compter_comptes( array( 'q' => $tag ) ) );
		$asc = yume_tcd_ids(
			array(
				'q'     => $tag,
				'ordre' => 'asc',
			)
		);
		yume_assert_same( array_reverse( $ids ), $asc );

		$vide = lister_comptes( array( 'q' => $tag . 'introuvable' ) );
		yume_assert_same( 0, $vide['total'] );
		yume_assert_same( 1, $vide['pages'] );
		yume_assert_same( array(), $vide['comptes'] );

		$ligne = $page1['comptes'][0];
		yume_assert_same(
			array( 'id', 'pseudo', 'roles', 'role', 'equipe', 'inscrit_le', 'source', 'source_deduite', 'discord', 'sans_mdp', 'activite', 'derniere_lecture', 'chapitres_lus', 'series', 'favoris', 'suiveurs', 'commentaires' ),
			array_keys( $ligne ),
			'forme exacte d’une ligne'
		);
		yume_assert_same( gmdate( 'c', yume_tcd_maintenant() - 10 * DAY_IN_SECONDS ), $ligne['inscrit_le'], 'ISO 8601 UTC' );
		yume_assert_same( array( 'subscriber' ), $ligne['roles'] );
	}
);

yume_tcd_test(
	'filtres rôle, source (déduite comprise), Discord, activité, inscription, sans lecture, sans mot de passe',
	function ( string $tag ) {
		$serie = yume_tcd_oeuvre( $tag . ' Série', 1, 2 );
		$a     = yume_tcd_compte( $tag . ' A', 'subscriber', yume_tcd_gmt( -2 ) );
		$b     = yume_tcd_compte( $tag . ' B', 'subscriber', yume_tcd_gmt( -40 ) );
		$c     = yume_tcd_compte( $tag . ' C', 'yume_traducteur', yume_tcd_gmt( -400 ) );
		$d     = yume_tcd_compte( $tag . ' D', 'subscriber', yume_tcd_gmt( -100 ), 'yn-' . substr( md5( $tag ), 0, 8 ) );
		$e     = yume_tcd_compte( $tag . ' E', 'administrator', yume_tcd_gmt( -200 ) );
		update_user_meta( $a, META_SOURCE, 'inscription' );
		update_user_meta( $a, META_ACTIVITE, yume_tcd_jour( 0 ) );
		update_user_meta( $b, 'yume_discord_id', '123456789012345678' );
		update_user_meta( $b, 'yume_discord_pseudo', 'kaede_dc' );
		update_user_meta( $b, '_yume_compte_sans_mdp', '1' );
		update_user_meta( $b, META_ACTIVITE, yume_tcd_jour( -20 ) );
		update_user_meta( $c, META_SOURCE, 'equipe' );
		update_user_meta( $d, META_ACTIVITE, yume_tcd_jour( -100 ) );
		update_user_meta( $e, 'yume_discord_id', '223456789012345678' );
		update_user_meta( $e, '_yume_compte_sans_mdp', '0' );
		yume_tcd_position( $e, $serie['id'], $serie['chapitres'][0][0], 50 );

		$f = static fn( array $args ): array => yume_tcd_ids( array_merge( array( 'q' => $tag ), $args ) );
		yume_tcd_memes( array( $a, $b, $c, $d, $e ), $f( array() ) );
		yume_tcd_memes( array( $a, $b, $d ), $f( array( 'role' => 'lecteurs' ) ), 'lecteurs' );
		yume_tcd_memes( array( $c, $e ), $f( array( 'role' => 'equipe' ) ), 'équipe' );
		yume_tcd_memes( array( $c ), $f( array( 'role' => 'yume_traducteur' ) ), 'rôle précis' );
		yume_tcd_memes( array( $a, $d ), $f( array( 'source' => 'inscription' ) ), 'source inscription (D déduite)' );
		yume_tcd_memes( array( $b ), $f( array( 'source' => 'discord' ) ), 'source discord (déduite)' );
		yume_tcd_memes( array( $c ), $f( array( 'source' => 'equipe' ) ), 'source équipe' );
		yume_tcd_memes( array( $e ), $f( array( 'source' => 'inconnue' ) ), 'source inconnue' );
		yume_tcd_memes( array( $b, $e ), $f( array( 'discord' => 'lie' ) ), 'Discord lié' );
		yume_tcd_memes( array( $a, $c, $d ), $f( array( 'discord' => 'non' ) ), 'Discord non lié' );
		yume_tcd_memes( array( $a ), $f( array( 'activite' => '7' ) ), 'actifs 7 j' );
		yume_tcd_memes( array( $a, $b ), $f( array( 'activite' => '30' ) ), 'actifs 30 j' );
		yume_tcd_memes( array( $a, $b ), $f( array( 'activite' => '90' ) ), 'actifs 90 j' );
		yume_tcd_memes( array( $d ), $f( array( 'activite' => 'inactifs' ) ), 'inactifs' );
		yume_tcd_memes( array( $c, $e ), $f( array( 'activite' => 'inconnue' ) ), 'activité inconnue' );
		yume_tcd_memes( array( $a ), $f( array( 'inscrits' => '30' ) ), 'inscrits 30 j' );
		yume_tcd_memes( array( $a, $b ), $f( array( 'inscrits' => '90' ) ), 'inscrits 90 j' );
		yume_tcd_memes( array( $a, $b, $d, $e ), $f( array( 'inscrits' => '365' ) ), 'inscrits 365 j' );
		yume_tcd_memes( array( $a, $b, $c, $d ), $f( array( 'sans_lecture' => '1' ) ), 'sans lecture' );
		yume_tcd_memes( array( $b ), $f( array( 'sans_mdp' => 'on' ) ), 'sans mot de passe' );
		yume_tcd_memes(
			array( $b ),
			$f(
				array(
					'role'     => 'lecteurs',
					'discord'  => 'lie',
					'activite' => '30',
				)
			),
			'filtres cumulés'
		);
		yume_assert_same(
			1,
			compter_comptes(
				array(
					'q'        => $tag,
					'sans_mdp' => '1',
				)
			)
		);

		$lignes = array();
		foreach ( lister_comptes( array( 'q' => $tag ) )['comptes'] as $ligne ) {
			$lignes[ $ligne['id'] ] = $ligne;
		}
		yume_assert_same( 'inscription', $lignes[ $a ]['source'] );
		yume_assert_false( $lignes[ $a ]['source_deduite'] );
		yume_assert_same( yume_tcd_jour( 0 ), $lignes[ $a ]['activite'] );
		yume_assert_false( $lignes[ $a ]['equipe'] );
		yume_assert_same( 'discord', $lignes[ $b ]['source'] );
		yume_assert_true( $lignes[ $b ]['source_deduite'] );
		yume_assert_same( 'kaede_dc', $lignes[ $b ]['discord'] );
		yume_assert_true( $lignes[ $b ]['sans_mdp'] );
		yume_assert_same( 'equipe', $lignes[ $c ]['source'] );
		yume_assert_true( $lignes[ $c ]['equipe'] );
		yume_assert_same( 'Traducteur', $lignes[ $c ]['role'] );
		yume_assert_same( '', $lignes[ $c ]['activite'] );
		yume_assert_same( 'inscription', $lignes[ $d ]['source'] );
		yume_assert_true( $lignes[ $d ]['source_deduite'] );
		yume_assert_same( 'inconnue', $lignes[ $e ]['source'] );
		yume_assert_false( $lignes[ $e ]['source_deduite'] );
		yume_assert_false( $lignes[ $e ]['sans_mdp'], 'méta à 0 : mot de passe connu' );
		yume_assert_same( '(sans pseudo)', $lignes[ $e ]['discord'], 'lié sans pseudo connu' );
		yume_assert_same( 'Administrateur', $lignes[ $e ]['role'] );
		yume_assert_true( $lignes[ $e ]['equipe'] );
		yume_assert_same( '', $lignes[ $a ]['discord'] );
		yume_assert_same( '2026-10-01T10:00:00+00:00', $lignes[ $e ]['derniere_lecture'] );
		yume_assert_same( '', $lignes[ $a ]['derniere_lecture'] );
		// Chaque source est cohérente avec source_compte().
		foreach ( $lignes as $id => $ligne ) {
			$attendu = \Yume\Core\Comptes\source_compte( $id );
			yume_assert_same( $attendu['source'], $ligne['source'], 'source de #' . $id );
			yume_assert_same( $attendu['deduite'], $ligne['source_deduite'], 'déduction de #' . $id );
		}
	}
);

yume_tcd_test(
	'tris : pseudo, activité et lecture (inconnues en dernier), favoris, commentaires',
	function ( string $tag ) {
		$serie = yume_tcd_oeuvre( $tag . ' Série', 1, 2 );
		$autre = yume_tcd_oeuvre( $tag . ' Autre', 1, 1 );
		$p1    = yume_tcd_compte( $tag . ' bravo', 'subscriber', '2026-01-01 00:00:00' );
		$p2    = yume_tcd_compte( $tag . ' Alpha', 'subscriber', '2026-01-02 00:00:00' );
		$p3    = yume_tcd_compte( $tag . ' charlie', 'subscriber', '2026-01-03 00:00:00' );
		$p4    = yume_tcd_compte( $tag . ' delta', 'subscriber', '2026-01-04 00:00:00' );
		update_user_meta( $p1, META_ACTIVITE, '2026-10-01' );
		update_user_meta( $p3, META_ACTIVITE, '2026-10-20' );
		yume_tcd_position( $p2, $serie['id'], $serie['chapitres'][0][0], 10, '2026-10-05 10:00:00' );
		yume_tcd_position( $p4, $serie['id'], $serie['chapitres'][0][1], 10, '2026-10-10 10:00:00' );
		yume_tcd_favori( $p3, $serie['id'] );
		yume_tcd_favori( $p3, $autre['id'], 'jamais' );
		yume_tcd_favori( $p1, $serie['id'], 'hebdo' );
		yume_tcd_commentaire( $p4, $serie['id'] );
		yume_tcd_commentaire( $p4, $serie['id'], '0' );
		yume_tcd_commentaire( $p4, $serie['id'], 'spam' );
		yume_tcd_commentaire( $p4, $serie['id'], 'trash' );
		yume_tcd_commentaire( $p2, $serie['id'] );

		$tri = static fn( string $t, string $o = '' ): array => yume_tcd_ids(
			array_filter(
				array(
					'q'     => $tag,
					'tri'   => $t,
					'ordre' => $o,
				)
			)
		);
		yume_assert_same( array( $p2, $p1, $p3, $p4 ), $tri( 'pseudo' ), 'pseudo croissant par défaut, casse ignorée' );
		yume_assert_same( array( $p4, $p3, $p1, $p2 ), $tri( 'pseudo', 'desc' ) );
		yume_assert_same( array( $p3, $p1, $p4, $p2 ), $tri( 'activite' ), 'activité récente d’abord, inconnues en dernier' );
		yume_assert_same( array( $p1, $p3 ), array_slice( $tri( 'activite', 'asc' ), 0, 2 ), 'croissant : inconnues toujours en dernier' );
		yume_tcd_memes( array( $p4, $p2 ), array_slice( $tri( 'activite', 'asc' ), 2 ) );
		yume_assert_same( array( $p4, $p2 ), array_slice( $tri( 'lecture' ), 0, 2 ), 'lecture récente d’abord' );
		yume_assert_same( array( $p2, $p4 ), array_slice( $tri( 'lecture', 'asc' ), 0, 2 ), 'sans lecture en dernier' );
		yume_assert_same( array( $p3, $p1 ), array_slice( $tri( 'favoris' ), 0, 2 ) );
		yume_assert_same( array( $p4, $p2 ), array_slice( $tri( 'commentaires' ), 0, 2 ) );
		yume_assert_same( array( $p4, $p3, $p2, $p1 ), $tri( 'inscription' ) );

		$lignes = array();
		foreach ( lister_comptes( array( 'q' => $tag ) )['comptes'] as $ligne ) {
			$lignes[ $ligne['id'] ] = $ligne;
		}
		yume_assert_same( 2, $lignes[ $p3 ]['favoris'] );
		yume_assert_same( 1, $lignes[ $p3 ]['suiveurs'], 'alertes ≠ jamais' );
		yume_assert_same( 1, $lignes[ $p1 ]['suiveurs'] );
		yume_assert_same( 2, $lignes[ $p4 ]['commentaires'], 'approuvés et en attente (ni spam ni corbeille)' );
		yume_assert_same( 1, $lignes[ $p4 ]['series'] );
		yume_assert_same( 0, $lignes[ $p1 ]['series'] );
	}
);

yume_tcd_test(
	'recherche : pseudo, nom Discord, e-mail et identifiant exacts seulement, jokers échappés',
	function ( string $tag ) {
		$jerry   = yume_tcd_compte( 'Tom &amp; Jerry ' . $tag );
		$pourcen = yume_tcd_compte( $tag . ' 100%sur' );
		$cent    = yume_tcd_compte( $tag . ' 100xsur' );
		$souli   = yume_tcd_compte( $tag . ' a_b' );
		$axb     = yume_tcd_compte( $tag . ' axb' );
		$dc      = yume_tcd_compte( 'Sans rapport ' . wp_rand( 1, 9999 ) );
		update_user_meta( $dc, 'yume_discord_id', '323456789012345678' );
		update_user_meta( $dc, 'yume_discord_pseudo', $tag . 'Discord' );
		$mail = yume_tcd_compte( 'Pseudo neutre ' . wp_rand( 1, 9999 ), 'subscriber', '2026-01-01 00:00:00', 'tcdmail' . strtolower( $tag ) );
		$adr  = 'tcdmail' . strtolower( $tag ) . '@exemple-tcd.test';

		$q     = static fn( string $q ): array => yume_tcd_ids( array( 'q' => $q ) );
		yume_tcd_memes( array( $jerry ), $q( 'Tom & Jerry ' . $tag ), '« & » enregistré en entité' );
		$ligne = lister_comptes( array( 'q' => 'Tom & Jerry ' . $tag ) )['comptes'][0];
		yume_assert_same( 'Tom & Jerry ' . $tag, $ligne['pseudo'], 'pseudo décodé' );
		yume_assert_same( array( $pourcen ), $q( $tag . ' 100%' ), '% littéral' );
		yume_tcd_memes( array( $pourcen, $cent ), $q( $tag . ' 100' ) );
		yume_assert_same( array( $souli ), $q( $tag . ' a_b' ), '_ littéral' );
		yume_assert_same( array( $axb ), $q( $tag . ' axb' ) );
		yume_assert_same( array( $dc ), $q( $tag . 'Discord' ), 'nom Discord' );
		yume_assert_same( array( $dc ), $q( strtolower( $tag ) . 'disc' ), 'nom Discord, partiel' );
		yume_assert_same( array( $mail ), $q( $adr ), 'e-mail exact' );
		yume_assert_same( array( $mail ), $q( strtoupper( $adr ) ), 'e-mail exact, casse ignorée' );
		yume_assert_same( array(), $q( substr( $adr, 1 ) ), 'e-mail partiel : jamais de LIKE' );
		yume_assert_same( array(), $q( '@exemple-tcd.test' ), 'domaine : rien' );
		yume_assert_same( array(), $q( substr( $adr, 0, -3 ) ), 'début d’adresse : rien' );
		yume_assert_same( array( $mail ), $q( 'tcdmail' . strtolower( $tag ) ), 'identifiant exact' );
		yume_assert_same( array(), $q( 'tcdmail' . substr( strtolower( $tag ), 0, -1 ) ), 'identifiant partiel : rien' );
	}
);

yume_tcd_test(
	'compte supprimé : absent de la liste, du total et des indicateurs',
	function ( string $tag ) {
		global $wpdb;
		$garde = yume_tcd_compte( $tag . ' garde' );
		$parti = yume_tcd_compte( $tag . ' parti' );
		$avant = indicateurs()['comptes'];
		$supp  = yume_tcd_compte( $tag . ' supprimé' );
		update_user_meta( $supp, META_ACTIVITE, yume_tcd_jour( 0 ) );
		// Ligne orpheline : le compte disparaît de wp_users, ses métas restent.
		$wpdb->delete( $wpdb->users, array( 'ID' => $supp ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $parti );
		clean_user_cache( $supp );
		yume_assert_same( array( $garde ), yume_tcd_ids( array( 'q' => $tag ) ) );
		yume_assert_same( 1, compter_comptes( array( 'q' => $tag ) ) );
		yume_assert_same( 0, compter_comptes( array( 'q' => $tag . ' supprimé' ) ) );
		delete_transient( INDICATEURS_TRANSIENT );
		yume_assert_same( $avant - 1, indicateurs()['comptes'], 'parti retiré, supprimé jamais compté' );
		yume_assert_same( null, fiche_compte( $supp ) );
		yume_assert_same( null, fiche_compte( $parti ) );
		yume_assert_same( null, fiche_compte( 0 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Lecture estimée et requêtes
 * -----------------------------------------------------------------------------
 */

yume_tcd_test(
	'chapitres_lus identique à Reader\statistiques_lecture() pour trois comptes',
	function ( string $tag ) {
		$s1       = yume_tcd_oeuvre( $tag . ' Une', 2, 3 );
		$s2       = yume_tcd_oeuvre( $tag . ' Deux', 1, 4 );
		$brouil   = yume_tcd_oeuvre( $tag . ' Brouillon', 1, 2, 'draft' );
		$u1       = yume_tcd_compte( $tag . ' u1' );
		$u2       = yume_tcd_compte( $tag . ' u2' );
		$u3       = yume_tcd_compte( $tag . ' u3' );
		$sans     = yume_tcd_compte( $tag . ' sans' );
		$depublie = yume_tcd_contenu( 'yume_chapitre', $tag . ' dépublié', array( 'yume_oeuvre_id' => $s2['id'] ), 9, 'draft' );
		yume_tcd_position( $u1, $s1['id'], $s1['chapitres'][1][1], 50, '2026-10-02 10:00:00' );
		yume_tcd_position( $u1, $s2['id'], $s2['chapitres'][0][3], 95, '2026-10-03 10:00:00' );
		yume_tcd_position( $u2, $s1['id'], $s1['chapitres'][0][0], 95, '2026-09-02 10:00:00' );
		yume_tcd_position( $u2, $brouil['id'], $brouil['chapitres'][0][1], 100, '2026-09-05 10:00:00' );
		yume_tcd_position( $u3, $s2['id'], $depublie, 100, '2026-08-02 10:00:00' );
		yume_tcd_position( $u3, $s1['id'], $s1['chapitres'][1][2], 89, '2026-08-01 10:00:00' );
		wp_cache_flush();

		$lots = lectures_par_compte( array( $u1, $u2, $u3, $sans, $u1, 0 ) );
		yume_tcd_memes( array( $u1, $u2, $u3, $sans ), array_keys( $lots ) );
		foreach ( array( $u1, $u2, $u3 ) as $uid ) {
			$stats = \Yume\Core\Reader\statistiques_lecture( $uid );
			yume_assert_same( $stats['chapitres_lus'], $lots[ $uid ]['chapitres_lus'], 'chapitres lus de #' . $uid );
			yume_assert_same( $stats['minutes'], $lots[ $uid ]['minutes'], 'minutes de #' . $uid );
			yume_assert_same( count( $stats['series'] ), $lots[ $uid ]['series'], 'séries de #' . $uid );
		}
		yume_assert_same( 3 + 1 + 4, $lots[ $u1 ]['chapitres_lus'], 'u1 : 3 + 1 (tome 2), 4 (fini à 95 %)' );
		yume_assert_same( 1, $lots[ $u2 ]['chapitres_lus'], 'u2 : œuvre en brouillon ignorée' );
		yume_assert_same( 5, $lots[ $u3 ]['chapitres_lus'], 'u3 : chapitre dépublié ignoré, 89 % non lu' );
		yume_assert_same( '2026-10-03 10:00:00', $lots[ $u1 ]['derniere'] );
		yume_assert_same(
			array(
				'chapitres_lus' => 0,
				'minutes'       => 0,
				'series'        => 0,
				'derniere'      => '',
			),
			$lots[ $sans ]
		);
		yume_assert_same( array(), lectures_par_compte( array() ) );

		$lignes = array();
		foreach ( lister_comptes( array( 'q' => $tag ) )['comptes'] as $ligne ) {
			$lignes[ $ligne['id'] ] = $ligne;
		}
		yume_assert_same( 8, $lignes[ $u1 ]['chapitres_lus'] );
		yume_assert_same( 2, $lignes[ $u1 ]['series'] );
		yume_assert_same( 5, $lignes[ $u3 ]['chapitres_lus'] );
		yume_assert_same( '2026-10-03T10:00:00+00:00', $lignes[ $u1 ]['derniere_lecture'] );
		$fiche = fiche_compte( $u1 );
		yume_assert_same( 8, $fiche['lecture']['chapitres_lus'], 'fiche : statistiques_lecture()' );
	}
);

yume_tcd_test(
	'une page de 50 comptes en au plus 8 requêtes, sans boucle par compte',
	function ( string $tag ) {
		global $wpdb;
		$s1 = yume_tcd_oeuvre( $tag . ' Une', 2, 3 );
		$s2 = yume_tcd_oeuvre( $tag . ' Deux', 1, 2 );
		for ( $i = 1; $i <= 55; $i++ ) {
			$uid = yume_tcd_compte( $tag . ' n' . $i, 0 === $i % 9 ? 'yume_relecteur' : 'subscriber', yume_tcd_gmt( -$i ) );
			update_user_meta( $uid, META_ACTIVITE, yume_tcd_jour( -$i ) );
			yume_tcd_position( $uid, $s1['id'], $s1['chapitres'][ $i % 2 ][ $i % 3 ], 50 + $i % 50 );
			if ( 0 === $i % 2 ) {
				yume_tcd_position( $uid, $s2['id'], $s2['chapitres'][0][ $i % 2 ], 95 );
				yume_tcd_favori( $uid, $s1['id'] );
				yume_tcd_commentaire( $uid, $s1['id'] );
			}
		}
		yume_tcd_cache_froid();
		foreach ( array( 'inscription', 'pseudo', 'activite', 'lecture', 'favoris', 'commentaires' ) as $tri ) {
			$avant    = $wpdb->num_queries;
			$resultat = lister_comptes(
				array(
					'q'   => $tag,
					'tri' => $tri,
				)
			);
			$requetes = $wpdb->num_queries - $avant;
			yume_assert_same( 55, $resultat['total'] );
			yume_assert_same( 50, count( $resultat['comptes'] ) );
			yume_assert_true( $requetes <= 8, 'tri ' . $tri . ' : ' . $requetes . ' requêtes' );
			yume_tcd_cache_froid();
		}
		// Le coût ne dépend pas du nombre de comptes de la page.
		$avant = $wpdb->num_queries;
		lister_comptes(
			array(
				'q'      => $tag,
				'limite' => 5,
			)
		);
		$petit = $wpdb->num_queries - $avant;
		yume_tcd_cache_froid();
		$avant = $wpdb->num_queries;
		lister_comptes(
			array(
				'q'      => $tag,
				'limite' => 100,
			)
		);
		yume_assert_same( $petit, $wpdb->num_queries - $avant, 'même nombre de requêtes pour 5 et 55 comptes' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Indicateurs
 * -----------------------------------------------------------------------------
 */

yume_tcd_test(
	'indicateurs : six chiffres, en cache 5 minutes',
	function ( string $tag ) {
		global $wpdb;
		$avant = indicateurs();
		yume_assert_same(
			array( 'comptes', 'lecteurs', 'equipe', 'nouveaux_30j', 'actifs_30j', 'discord', 'sans_mdp', 'activite_depuis' ),
			array_keys( $avant )
		);
		$l1 = yume_tcd_compte( $tag . ' l1', 'subscriber', yume_tcd_gmt( -3 ) );
		$l2 = yume_tcd_compte( $tag . ' l2', 'subscriber', yume_tcd_gmt( -45 ) );
		$t1 = yume_tcd_compte( $tag . ' t1', 'yume_graphiste', yume_tcd_gmt( -10 ) );
		yume_tcd_compte( $tag . ' g1', 'yume_gerant', yume_tcd_gmt( -400 ) );
		update_user_meta( $l1, META_ACTIVITE, yume_tcd_jour( -29 ) );
		update_user_meta( $l2, META_ACTIVITE, yume_tcd_jour( -31 ) );
		update_user_meta( $t1, META_ACTIVITE, yume_tcd_jour( 0 ) );
		update_user_meta( $l2, 'yume_discord_id', '423456789012345678' );
		update_user_meta( $l2, '_yume_compte_sans_mdp', '1' );
		update_option( OPTION_DEPUIS, '2026-09-01' );

		yume_assert_same( $avant, indicateurs(), 'en cache : inchangé' );
		$transient = get_transient( INDICATEURS_TRANSIENT );
		yume_assert_same( $avant, $transient );
		delete_transient( INDICATEURS_TRANSIENT );
		$apres = indicateurs();
		yume_assert_same( $avant['comptes'] + 4, $apres['comptes'] );
		yume_assert_same( $avant['lecteurs'] + 2, $apres['lecteurs'] );
		yume_assert_same( $avant['equipe'] + 2, $apres['equipe'] );
		yume_assert_same( $avant['nouveaux_30j'] + 2, $apres['nouveaux_30j'] );
		yume_assert_same( $avant['actifs_30j'] + 2, $apres['actifs_30j'], 'activité il y a 29 jours comptée, 31 non' );
		yume_assert_same( $avant['discord'] + 1, $apres['discord'] );
		yume_assert_same( $avant['sans_mdp'] + 1, $apres['sans_mdp'] );
		yume_assert_same( '2026-09-01', $apres['activite_depuis'] );
		$n = $wpdb->num_queries;
		indicateurs();
		yume_assert_true( $wpdb->num_queries - $n <= 1, 'lu dans le transient' );
		// Un compte créé vide le cache.
		yume_factory_user( 'subscriber' );
		yume_assert_false( get_transient( INDICATEURS_TRANSIENT ), 'cache vidé à l’inscription' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Fiche
 * -----------------------------------------------------------------------------
 */

yume_tcd_test(
	'fiche : toutes les sections, sans e-mail, identifiant, IP, push, réglages ni liste privée',
	function ( string $tag ) {
		global $wpdb;
		$serie  = yume_tcd_oeuvre( $tag . ' Série', 1, 3 );
		$autre  = yume_tcd_oeuvre( $tag . ' Autre', 1, 1 );
		$login  = 'yn-' . substr( md5( $tag . 'f' ), 0, 8 );
		$uid    = yume_tcd_compte( $tag . ' Fiche', 'subscriber', '2026-03-04 05:06:07', $login );
		$adr    = $login . '@exemple-tcd.test';
		$secret = 'Liste secrète ' . $tag;
		update_user_meta( $uid, META_ACTIVITE, '2026-10-20' );
		update_user_meta( $uid, 'yume_discord_id', '523456789012345678' );
		update_user_meta( $uid, 'yume_discord_pseudo', 'fiche_dc' );
		update_user_meta( $uid, 'yume_discord_lie_le', '2026-05-06 07:08:09' );
		update_user_meta( $uid, 'yume_reglages', array( 'theme' => 'reglage-tres-prive-' . $tag ) );
		update_user_meta( $uid, 'yume_alertes', array( 'hebdo' => true ) );
		yume_tcd_position( $uid, $serie['id'], $serie['chapitres'][0][1], 40, '2026-10-10 10:00:00' );
		yume_tcd_position( $uid, $autre['id'], $autre['chapitres'][0][0], 100, '2026-09-10 10:00:00' );
		yume_tcd_favori( $uid, $serie['id'], 'hebdo' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\Yume\Core\Social\table_notes(),
			array(
				'user_id'    => $uid,
				'oeuvre_id'  => $serie['id'],
				'note'       => 4,
				'updated_at' => '2026-10-01 00:00:00',
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\Yume\Core\Social\table_notes(),
			array(
				'user_id'    => $uid,
				'oeuvre_id'  => $autre['id'],
				'note'       => 5,
				'updated_at' => '2026-09-01 00:00:00',
			)
		);
		$c1 = yume_tcd_commentaire( $uid, $serie['id'], '1', '2026-10-05 10:00:00' );
		yume_tcd_commentaire( $uid, $serie['id'], '0', '2026-10-04 10:00:00' );
		yume_tcd_commentaire( $uid, $serie['id'], 'spam', '2026-10-03 10:00:00' );
		yume_tcd_commentaire( $uid, $serie['id'], 'trash', '2026-10-06 10:00:00' );
		// Listes : une publique, une privée, la liste système « À lire ».
		$listes  = \Yume\Core\Social\table_listes();
		$contenu = \Yume\Core\Social\table_listes_oeuvres();
		$ajout   = static function ( string $nom, string $systeme, int $publique, array $oeuvres ) use ( $wpdb, $listes, $contenu, $uid ): void {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$listes,
				array(
					'user_id'  => $uid,
					'nom'      => $nom,
					'slug'     => sanitize_title( $nom ),
					'jeton'    => substr( md5( $nom ), 0, 16 ),
					'publique' => $publique,
					'systeme'  => $systeme,
					'cree_le'  => '2026-01-01 00:00:00',
					'maj_le'   => '2026-01-01 00:00:00',
				)
			);
			$liste = (int) $wpdb->insert_id;
			foreach ( $oeuvres as $oeuvre ) {
				$wpdb->insert(
					$contenu,
					array(
						'liste_id'  => $liste,
						'oeuvre_id' => $oeuvre,
						'ajoute_le' => '2026-01-01 00:00:00',
					)
				);
			}
			// phpcs:enable
		};
		$ajout( 'À lire', 'a_lire', 0, array( $serie['id'], $autre['id'] ) );
		$ajout( 'Mes pépites ' . $tag, '', 1, array( $serie['id'] ) );
		$ajout( $secret, '', 0, array( $serie['id'], $autre['id'] ) );
		// Notifications et push.
		$notifs = \Yume\Core\Social\table_notifications_lecteur();
		foreach ( array( null, null, '2026-10-02 00:00:00' ) as $lu ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$notifs,
				array(
					'user_id'  => $uid,
					'type'     => 'sortie',
					'objet_id' => $serie['id'],
					'titre'    => 'Sortie',
					'url'      => 'https://exemple.test/',
					'cree_le'  => '2026-10-01 00:00:00',
					'lu_le'    => $lu,
				)
			);
		}
		$endpoint = 'https://push.exemple.test/abonnement-' . $tag;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\Yume\Core\Social\table_push(),
			array(
				'user_id'   => $uid,
				'endpoint'  => $endpoint,
				'empreinte' => hash( 'sha256', $endpoint ),
				'p256dh'    => 'cle-p256dh-' . $tag,
				'auth'      => 'cle-auth-' . $tag,
				'cree_le'   => '2026-10-01 00:00:00',
			)
		);
		// Coquilles.
		$coquilles = \Yume\Core\Corrections\table_prete();
		if ( $coquilles ) {
			foreach ( array( 'nouveau', 'nouveau', 'corrige' ) as $statut ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					\Yume\Core\Corrections\table_coquilles(),
					array(
						'chapitre_id' => $serie['chapitres'][0][0],
						'oeuvre_id'   => $serie['id'],
						'user_id'     => $uid,
						'extrait'     => 'une coquile',
						'contexte'    => 'contexte privé',
						'statut'      => $statut,
						'ip_hash'     => str_repeat( 'f', 64 ),
						'cree_le'     => '2026-10-01 00:00:00',
					)
				);
			}
		}
		wp_cache_flush();

		$fiche = fiche_compte( $uid );
		yume_assert_true( is_array( $fiche ) );
		yume_assert_same( array( 'identite', 'lecture', 'favoris', 'alertes', 'listes', 'notes', 'commentaires', 'coquilles', 'notifications' ), array_keys( $fiche ) );

		$id = $fiche['identite'];
		yume_assert_same(
			array( 'id', 'pseudo', 'roles', 'role', 'equipe', 'inscrit_le', 'source', 'source_deduite', 'discord', 'sans_mdp', 'pseudo_est_identifiant', 'activite', 'email_disponible' ),
			array_keys( $id )
		);
		yume_assert_same( $uid, $id['id'] );
		yume_assert_same( $tag . ' Fiche', $id['pseudo'] );
		yume_assert_same( array( 'subscriber' ), $id['roles'] );
		yume_assert_false( $id['equipe'] );
		yume_assert_same( '2026-03-04T05:06:07+00:00', $id['inscrit_le'] );
		yume_assert_same( 'inscription', $id['source'] );
		yume_assert_true( $id['source_deduite'] );
		yume_assert_same(
			array(
				'pseudo' => 'fiche_dc',
				'lie_le' => '2026-05-06T07:08:09+00:00',
			),
			$id['discord']
		);
		yume_assert_false( $id['sans_mdp'] );
		yume_assert_false( $id['pseudo_est_identifiant'] );
		yume_assert_same( '2026-10-20', $id['activite'] );
		yume_assert_true( $id['email_disponible'] );

		$lecture = $fiche['lecture'];
		yume_assert_same( 1 + 1, $lecture['chapitres_lus'] );
		yume_assert_same( 2, count( $lecture['series_detail'] ) );
		yume_assert_same( $serie['id'], $lecture['derniere']['oeuvre_id'], 'position la plus récente' );
		yume_assert_same( $serie['chapitres'][0][1], $lecture['derniere']['chapitre_id'] );
		yume_assert_same( $tag . ' Série', $lecture['derniere']['titre'] );
		yume_assert_same( '2026-10-10T10:00:00+00:00', $lecture['derniere']['le'] );
		yume_assert_same( 1, $lecture['series_detail'][0]['chapitres_lus'] );
		yume_assert_same( 3, $lecture['series_detail'][0]['chapitres'] );

		yume_assert_same( 1, count( $fiche['favoris'] ) );
		yume_assert_same( 'hebdo', $fiche['favoris'][0]['frequence'] );
		yume_assert_same( $tag . ' Série', $fiche['favoris'][0]['titre'] );
		yume_assert_same( '2026-09-01T08:00:00+00:00', $fiche['favoris'][0]['depuis'] );
		yume_assert_true( $fiche['alertes']['hebdo'] );

		$l = $fiche['listes'];
		yume_assert_same( 2, $l['systeme']['a_lire'] );
		yume_assert_same( 0, $l['systeme']['en_cours'] );
		yume_assert_same( 2, $l['personnelles'] );
		yume_assert_same( 1, $l['privees'] );
		yume_assert_same( 2, $l['oeuvres_privees'] );
		yume_assert_same( 1, count( $l['publiques'] ) );
		yume_assert_same( 'Mes pépites ' . $tag, $l['publiques'][0]['nom'] );
		yume_assert_same( 1, $l['publiques'][0]['nb'] );
		yume_assert_true( '' !== $l['publiques'][0]['url'] );

		yume_assert_same( 2, $fiche['notes']['nb'] );
		yume_assert_same( 4.5, $fiche['notes']['moyenne'] );
		yume_assert_same( 4, $fiche['notes']['lignes'][0]['note'] );

		$c = $fiche['commentaires'];
		yume_assert_same( 3, $c['total'], 'hors corbeille' );
		yume_assert_same( 1, $c['approuves'] );
		yume_assert_same( 1, $c['attente'] );
		yume_assert_same( 1, $c['spam'] );
		yume_assert_same( 3, count( $c['derniers'] ) );
		yume_assert_same( $c1, $c['derniers'][0]['id'] );
		yume_assert_same( 'approuve', $c['derniers'][0]['statut'] );
		yume_assert_contains( '#comment-' . $c1, $c['derniers'][0]['url'] );
		yume_assert_same( '', $c['derniers'][1]['url'], 'en attente : pas de lien public' );
		yume_assert_same( $tag . ' Série', $c['derniers'][0]['sur'] );

		if ( $coquilles ) {
			yume_assert_same( 3, $fiche['coquilles']['total'] );
			yume_assert_same(
				array(
					'nouveau' => 2,
					'corrige' => 1,
					'rejete'  => 0,
				),
				$fiche['coquilles']['par_statut']
			);
			yume_assert_same( 3, count( $fiche['coquilles']['dernieres'] ) );
		}
		yume_assert_same(
			array(
				'non_lues' => 2,
				'total'    => 3,
				'push'     => 1,
			),
			$fiche['notifications']
		);

		// Données interdites.
		yume_tcd_sans_cles_interdites( $fiche );
		$json = (string) wp_json_encode( $fiche, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		foreach ( array( $adr, $login, $secret, $endpoint, 'cle-p256dh-' . $tag, 'cle-auth-' . $tag, 'reglage-tres-prive-' . $tag, '203.0.113.9', 'ne-pas-afficher', str_repeat( 'f', 64 ), 'contexte privé' ) as $interdit ) {
			yume_assert_not_contains( $interdit, $json, 'fiche : ' . $interdit );
		}
		$liste = lister_comptes( array( 'q' => $tag . ' Fiche' ) );
		yume_assert_same( 1, $liste['total'] );
		yume_tcd_sans_cles_interdites( $liste );
		$json = (string) wp_json_encode( $liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		foreach ( array( $adr, $login ) as $interdit ) {
			yume_assert_not_contains( $interdit, $json, 'liste : ' . $interdit );
		}
		$par_email = lister_comptes( array( 'q' => $adr ) );
		yume_assert_same( array( $uid ), array_column( $par_email['comptes'], 'id' ) );
		yume_assert_not_contains( $adr, (string) wp_json_encode( $par_email['comptes'] ), 'la recherche n’expose pas l’adresse' );
	}
);

yume_tcd_test(
	'fiche : listes_utilisateur( $id, false ) ne crée aucune liste ; compte d’équipe ; ancien pseudo = identifiant',
	function ( string $tag ) {
		global $wpdb;
		$uid = yume_tcd_compte( 'ancien' . strtolower( $tag ), 'yume_gerant', '2024-01-01 00:00:00', 'ancien' . strtolower( $tag ) );
		wp_cache_flush();
		$fiche = fiche_compte( $uid );
		$table = \Yume\Core\Social\table_listes();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		yume_assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $uid ) ), 'aucune liste créée' );
		yume_assert_same(
			array(
				'a_lire'   => 0,
				'en_cours' => 0,
				'termine'  => 0,
			),
			$fiche['listes']['systeme']
		);
		yume_assert_same( array(), $fiche['listes']['publiques'] );
		yume_assert_true( $fiche['identite']['equipe'] );
		yume_assert_same( 'Gérant', $fiche['identite']['role'] );
		yume_assert_true( $fiche['identite']['pseudo_est_identifiant'] );
		yume_assert_same( 'inconnue', $fiche['identite']['source'] );
		yume_assert_same( null, $fiche['identite']['discord'] );
		yume_assert_same( '', $fiche['identite']['activite'] );
		yume_assert_same( null, $fiche['lecture']['derniere'] );
		yume_assert_same( array(), $fiche['lecture']['series_detail'] );
		yume_assert_same( 0, $fiche['commentaires']['total'] );
		yume_assert_same( 0.0, $fiche['notes']['moyenne'] );
		yume_assert_same(
			array(
				'non_lues' => 0,
				'total'    => 0,
				'push'     => 0,
			),
			$fiche['notifications']
		);
	}
);
