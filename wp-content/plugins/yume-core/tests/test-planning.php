<?php
/**
 * Tests du module planning : tables, états, yume_get_planning et filtres, REST (droits,
 * assainissement, journal, masquage des notes), écouteurs d'événements, file d'e-mails,
 * Discord, rappels et anti-répétition (heure simulée), récapitulatif, planification cron,
 * blocs (rendu selon les droits), formulaires sans JavaScript et page « Membres et rôles ».
 *
 * Lancement : tools/localenv/test.sh planning
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\analyser_etat;
use function Yume\Core\Planning\desactiver;
use function Yume\Core\Planning\envoyer_digest;
use function Yume\Core\Planning\envoyer_lot;
use function Yume\Core\Planning\est_conforme;
use function Yume\Core\Planning\executer_rappels;
use function Yume\Core\Planning\format_fr;
use function Yume\Core\Planning\grouper_journal;
use function Yume\Core\Planning\lire_journal;
use function Yume\Core\Planning\mettre_a_jour;
use function Yume\Core\Planning\planifier;
use function Yume\Core\Planning\prochaine_occurrence;
use function Yume\Core\Planning\purger;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\table_notifications;
use function Yume\Core\Planning\traiter_formulaire_ajout;
use function Yume\Core\Planning\traiter_formulaire_maj;
use function Yume\Core\Planning\traiter_formulaire_membres;
use function Yume\Core\Planning\url_membres;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tp_)
 * -----------------------------------------------------------------------------
 */

/**
 * Isole un test des contenus déjà présents dans la base (site de démonstration, données d'autres
 * modules) : œuvres, tomes, chapitres, journal et file d'e-mails sont vidés dans la transaction
 * du test, annulée ensuite par le lanceur.
 */
function yume_tp_isoler(): void {
	global $wpdb;
	$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
	$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
	$wpdb->query( 'DELETE FROM ' . table_notifications() ); // phpcs:ignore
	delete_option( 'yume_planning_echecs' );
	delete_option( 'yume_planning_dernier_digest' );
	wp_cache_flush();
}

/**
 * Déclare un test du module, isolé des contenus existants.
 *
 * @param string   $nom Nom.
 * @param callable $corps  Corps.
 */
function yume_tp_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			yume_tp_isoler();
			$corps();
		}
	);
}

/**
 * Gérants et administrateurs (destinataires attendus), triés.
 *
 * @return int[]
 */
function yume_tp_gerants(): array {
	$ids = array_map(
		'intval',
		get_users(
			array(
				'role__in' => array( 'yume_gerant', 'administrator' ),
				'fields'   => 'ID',
			)
		)
	);
	sort( $ids );
	return $ids;
}

/** Maintenant simulé des tests : jeudi 24 septembre 2026, 12 h à Paris. */
const YUME_TP_MAINTENANT = 1790244000; // 2026-09-24 10:00:00 UTC.

/**
 * Simule l'heure du module planning ; renvoie la fonction qui annule la simulation.
 *
 * @param int $ts Horodatage.
 */
function yume_tp_heure( int $ts = YUME_TP_MAINTENANT ): callable {
	$filtre = static function () use ( $ts ): int {
		return $ts;
	};
	add_filter( 'yume_planning_maintenant', $filtre );
	return static function () use ( $filtre ): void {
		remove_filter( 'yume_planning_maintenant', $filtre );
	};
}

/**
 * Exécute un test à heure simulée.
 *
 * @param callable $corps Corps.
 * @param int      $ts Horodatage.
 */
function yume_tp_a( callable $corps, int $ts = YUME_TP_MAINTENANT ): void {
	$annuler = yume_tp_heure( $ts );
	try {
		$corps();
	} finally {
		$annuler();
	}
}

/**
 * Date « Y-m-d » à N jours du maintenant simulé.
 *
 * @param int $jours Décalage.
 */
function yume_tp_jour( int $jours ): string {
	return gmdate( 'Y-m-d', YUME_TP_MAINTENANT + $jours * DAY_IN_SECONDS );
}

/**
 * Date GMT à N jours du maintenant simulé.
 *
 * @param float $jours Décalage.
 */
function yume_tp_gmt( float $jours ): string {
	return gmdate( 'Y-m-d H:i:s', (int) ( YUME_TP_MAINTENANT + $jours * DAY_IN_SECONDS ) );
}

/**
 * Crée une œuvre.
 *
 * @param string $titre  Titre.
 * @param string $type   Slug yume_type.
 * @param string $statut Statut.
 */
function yume_tp_oeuvre( string $titre, string $type = 'light-novel', string $statut = 'publish' ): int {
	$id = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => $statut,
		)
	);
	wp_set_object_terms( $id, $type, 'yume_type' );
	return $id;
}

/**
 * Crée un tome (brouillon par défaut, sans événement de publication).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param mixed  $numero    Numéro.
 * @param array  $meta      Métadonnées (planning…).
 * @param string $statut    Statut.
 * @param string $nature    Nature.
 */
function yume_tp_tome( int $oeuvre_id, $numero, array $meta = array(), string $statut = 'draft', string $nature = 'tome' ): int {
	$natures = yume_natures_tome();
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		$id = yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre_id ) . ' — ' . $natures[ $nature ] . ' ' . $numero,
				'post_status' => $statut,
				'meta_input'  => array_merge(
					array(
						'yume_oeuvre_id'    => $oeuvre_id,
						'yume_numero'       => $numero,
						'yume_nature'       => $nature,
						'yume_etape'        => 'traduction',
						'yume_derniere_maj' => yume_tp_gmt( 0 ),
					),
					$meta
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
	return $id;
}

/**
 * Crée un membre avec un pseudo.
 *
 * @param string $role Rôle.
 * @param string $nom  Pseudo.
 */
function yume_tp_membre( string $role, string $nom ): int {
	$id = yume_factory_user( $role );
	wp_update_user(
		array(
			'ID'           => $id,
			'display_name' => $nom,
		)
	);
	return $id;
}

/**
 * Modifie des réglages Yume.
 *
 * @param array $valeurs Clés et valeurs.
 */
function yume_tp_reglages( array $valeurs ): void {
	$actuels = get_option( 'yume_reglages', array() );
	update_option( 'yume_reglages', array_merge( is_array( $actuels ) ? $actuels : array(), $valeurs ) );
}

/**
 * Intercepte les requêtes HTTP sortantes ; renvoie la fonction qui arrête l'interception.
 *
 * @param array $requetes Requêtes capturées (url, args).
 * @param mixed $reponse  Code HTTP ou WP_Error.
 */
function yume_tp_http( array &$requetes, $reponse = 204 ): callable {
	$filtre = static function ( $pre, $args, $url ) use ( &$requetes, $reponse ) {
		$requetes[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( $reponse instanceof WP_Error ) {
			return $reponse;
		}
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => (int) $reponse,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $filtre, 10, 3 );
	return static function () use ( $filtre ): void {
		remove_filter( 'pre_http_request', $filtre, 10 );
	};
}

/**
 * Lignes de la file d'e-mails.
 *
 * @param string $contexte Contexte (vide : toutes).
 * @return object[]
 */
function yume_tp_file( string $contexte = '' ): array {
	global $wpdb;
	$table = table_notifications();
	if ( '' === $contexte ) {
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore
	}
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE contexte = %s ORDER BY id ASC", $contexte ) ); // phpcs:ignore
}

/**
 * Journal brut d'un tome (du plus ancien au plus récent).
 *
 * @param int $tome_id Tome.
 * @return object[]
 */
function yume_tp_journal( int $tome_id ): array {
	return array_reverse(
		lire_journal(
			array(
				'tome_id' => $tome_id,
				'limit'   => 100,
			)
		)
	);
}

/**
 * Rend un bloc avec un contexte.
 *
 * @param string $nom     Bloc.
 * @param array  $attrs   Attributs.
 * @param array  $contexte Contexte.
 */
function yume_tp_bloc( string $nom, array $attrs = array(), array $contexte = array() ): string {
	$bloc = new WP_Block(
		array(
			'blockName'    => $nom,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		$contexte
	);
	return (string) $bloc->render();
}

/**
 * Jeu de données : Grimgar T.10 (relecture, à l'heure), Silent Witch Arc 7 (en retard par
 * date), Raven T.7 (inactif), Witches T.2 (bloqué sans relecteur), Grimgar T.9 (publié il y a
 * 3 jours), SukaMoka (manga) T.2.
 *
 * @return array<string,int>
 */
function yume_tp_jeu(): array {
	$d              = array();
	$d['calumi']    = yume_tp_membre( 'yume_traducteur', 'Calumi' );
	$d['angeloids'] = yume_tp_membre( 'yume_relecteur', 'Angeloids' );
	$d['jojo']      = yume_tp_membre( 'yume_graphiste', 'JojoGg' );
	$d['editeur']   = yume_tp_membre( 'yume_editeur', 'Pizzflc' );
	$d['gerant']    = yume_tp_membre( 'yume_gerant', 'Mael7523m' );
	$d['lecteur']   = yume_tp_membre( 'subscriber', 'Kaede' );
	$d['grimgar']   = yume_tp_oeuvre( 'Grimgar of Fantasy and Ash' );
	$d['sw']        = yume_tp_oeuvre( 'Secrets of the Silent Witch', 'web-novel' );
	$d['raven']     = yume_tp_oeuvre( 'Raven of the Inner Palace' );
	$d['witches']   = yume_tp_oeuvre( 'Witches Can’t Be Collared' );
	$d['sukamoka']  = yume_tp_oeuvre( 'SukaMoka', 'manga' );
	$d['t10']       = yume_tp_tome(
		$d['grimgar'],
		10,
		array(
			'yume_etape'        => 'relecture',
			'yume_avancement'   => array(
				'traduction' => 100,
				'relecture'  => 62,
				'edition'    => 0,
			),
			'yume_responsables' => array(
				'traduction' => $d['calumi'],
				'relecture'  => $d['angeloids'],
				'edition'    => $d['jojo'],
			),
			'yume_date_cible'   => yume_tp_jour( 3 ),
			'yume_note_equipe'  => 'Postface à relire',
		)
	);
	$d['arc7']      = yume_tp_tome(
		$d['sw'],
		7,
		array(
			'yume_etape'        => 'relecture',
			'yume_avancement'   => array(
				'traduction' => 100,
				'relecture'  => 35,
				'edition'    => 0,
			),
			'yume_responsables' => array(
				'traduction' => $d['calumi'],
				'relecture'  => $d['angeloids'],
				'edition'    => 0,
			),
			'yume_date_cible'   => yume_tp_jour( -3 ),
		),
		'draft',
		'arc'
	);
	$d['raven7']    = yume_tp_tome(
		$d['raven'],
		7,
		array(
			'yume_etape'        => 'traduction',
			'yume_avancement'   => array(
				'traduction' => 48,
				'relecture'  => 0,
				'edition'    => 0,
			),
			'yume_responsables' => array(
				'traduction' => $d['calumi'],
				'relecture'  => 0,
				'edition'    => $d['angeloids'],
			),
			'yume_date_cible'   => yume_tp_jour( 25 ),
			'yume_derniere_maj' => yume_tp_gmt( -20 ),
		)
	);
	$d['witches2']  = yume_tp_tome(
		$d['witches'],
		2,
		array(
			'yume_avancement'    => array(
				'traduction' => 8,
				'relecture'  => 0,
				'edition'    => 0,
			),
			'yume_responsables'  => array(
				'traduction' => $d['jojo'],
				'relecture'  => 0,
				'edition'    => 0,
			),
			'yume_bloque'        => true,
			'yume_bloque_raison' => 'relecteur manquant',
		)
	);
	$d['t9']        = yume_tp_tome(
		$d['grimgar'],
		9,
		array(
			'yume_etape'        => 'publie',
			'yume_avancement'   => array(
				'traduction' => 100,
				'relecture'  => 100,
				'edition'    => 100,
			),
			'yume_derniere_maj' => yume_tp_gmt( -3 ),
		),
		'publish'
	);
	$d['sukamoka2'] = yume_tp_tome(
		$d['sukamoka'],
		2,
		array(
			'yume_responsables' => array(
				'traduction' => $d['angeloids'],
				'relecture'  => 0,
				'edition'    => 0,
			),
			'yume_date_cible'   => yume_tp_jour( 10 ),
		)
	);
	return $d;
}

/*
 * -----------------------------------------------------------------------------
 * Installation
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'tables planning_journal et notifications installées avec les colonnes du contrat',
	function () {
		global $wpdb;
		$journal = $wpdb->get_col( 'DESCRIBE ' . table_journal(), 0 ); // phpcs:ignore
		foreach ( array( 'id', 'tome_id', 'user_id', 'champ', 'ancien', 'nouveau', 'public', 'created_at' ) as $col ) {
			yume_assert_true( in_array( $col, $journal, true ), "colonne journal $col" );
		}
		$notifs = $wpdb->get_col( 'DESCRIBE ' . table_notifications(), 0 ); // phpcs:ignore
		foreach ( array( 'id', 'destinataire', 'user_id', 'sujet', 'html', 'contexte', 'statut', 'tentatives', 'created_at', 'envoye_le' ) as $col ) {
			yume_assert_true( in_array( $col, $notifs, true ), "colonne notifications $col" );
		}
		yume_assert_same( '1', get_option( 'yume_planning_db_version' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * États
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'yume_planning_etat : à l’heure, en retard par date, par inactivité, bloqué, publié',
	function () {
		yume_tp_a(
			function () {
				$o      = yume_tp_oeuvre( 'Grimgar of Fantasy and Ash' );
				$heure  = yume_tp_tome( $o, 1, array( 'yume_date_cible' => yume_tp_jour( 5 ) ) );
				$jour   = yume_tp_tome( $o, 2, array( 'yume_date_cible' => yume_tp_jour( 0 ) ) );
				$date   = yume_tp_tome( $o, 3, array( 'yume_date_cible' => yume_tp_jour( -2 ) ) );
				$inact  = yume_tp_tome( $o, 4, array( 'yume_derniere_maj' => yume_tp_gmt( -20 ) ) );
				$bloque = yume_tp_tome(
					$o,
					5,
					array(
						'yume_bloque'     => true,
						'yume_date_cible' => yume_tp_jour( -10 ),
					)
				);
				$publie = yume_tp_tome(
					$o,
					6,
					array(
						'yume_etape'  => 'publie',
						'yume_bloque' => true,
					)
				);
				yume_assert_same( 'a_lheure', yume_planning_etat( $heure ) );
				yume_assert_same( 'a_lheure', yume_planning_etat( $jour ), 'la date du jour n’est pas dépassée' );
				yume_assert_same( 'en_retard', yume_planning_etat( $date ) );
				yume_assert_same(
					array(
						'etat'  => 'en_retard',
						'motif' => 'date',
						'jours' => 2,
					),
					analyser_etat( $date )
				);
				yume_assert_same( 'en_retard', yume_planning_etat( $inact ) );
				yume_assert_same( 'inactivite', analyser_etat( $inact )['motif'] );
				yume_assert_same( 20, analyser_etat( $inact )['jours'] );
				yume_assert_same( 'bloque', yume_planning_etat( $bloque ), 'bloqué l’emporte sur le retard' );
				yume_assert_same( 'publie', yume_planning_etat( $publie ), 'publié l’emporte sur tout' );

				// Le seuil d'inactivité suit le réglage.
				yume_tp_reglages( array( 'rappel_jours_sans_maj' => 30 ) );
				yume_assert_same( 'a_lheure', yume_planning_etat( $inact ) );

				// Contenu qui n'est pas un tome, filtre.
				yume_assert_same( 'a_lheure', yume_planning_etat( $o ) );
				$filtre = static function () {
					return 'bloque';
				};
				add_filter( 'yume_planning_etat', $filtre );
				yume_assert_same( 'bloque', yume_planning_etat( $heure ) );
				remove_filter( 'yume_planning_etat', $filtre );
			}
		);
	}
);

yume_tp_test(
	'yume_planning_etat : sans yume_derniere_maj, la dernière modification du tome sert de référence',
	function () {
		$o  = yume_tp_oeuvre( 'Raven of the Inner Palace' );
		$id = yume_tp_tome( $o, 1 );
		delete_post_meta( $id, 'yume_derniere_maj' );
		yume_assert_same( 'a_lheure', yume_planning_etat( $id ), 'tome modifié à l’instant' );
		yume_tp_a(
			function () use ( $id ) {
				yume_assert_same( 'en_retard', yume_planning_etat( $id ), 'un mois plus tard' );
			},
			time() + 30 * DAY_IN_SECONDS
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * yume_get_planning
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'yume_get_planning : clés du contrat, responsables, tri par date cible, publiés en dernier',
	function () {
		yume_tp_a(
			function () {
				$d      = yume_tp_jeu();
				$lignes = yume_get_planning();
				$ids    = array_column( $lignes, 'tome_id' );
				yume_assert_same( array( $d['arc7'], $d['t10'], $d['sukamoka2'], $d['raven7'], $d['witches2'], $d['t9'] ), $ids );
				$l = $lignes[1];
				foreach ( array( 'tome_id', 'oeuvre_id', 'oeuvre', 'tome', 'etape', 'avancement', 'responsables', 'date_cible', 'etat', 'derniere_maj', 'url_oeuvre' ) as $cle ) {
					yume_assert_true( array_key_exists( $cle, $l ), "clé $cle" );
				}
				yume_assert_same( 'Grimgar of Fantasy and Ash', $l['oeuvre'] );
				yume_assert_same( 'Tome 10', $l['tome'] );
				yume_assert_same( 62, $l['avancement']['relecture'] );
				yume_assert_same(
					array(
						'id'  => $d['angeloids'],
						'nom' => 'Angeloids',
					),
					$l['responsables']['relecture']
				);
				yume_assert_same( 'a_lheure', $l['etat'] );
				yume_assert_same( get_permalink( $d['grimgar'] ), $l['url_oeuvre'] );
				yume_assert_false( array_key_exists( 'note_equipe', $l ), 'jamais de note dans les lignes' );
				yume_assert_same( 'Arc 7', $lignes[0]['tome'] );
				yume_assert_same( 'en_retard', $lignes[0]['etat'] );
				yume_assert_same( 'date', $lignes[0]['motif_retard'] );
				yume_assert_same( 'publie', $lignes[5]['etat'] );
			}
		);
	}
);

yume_tp_test(
	'yume_get_planning : filtres oeuvre_id, type, etat, a_venir, limit, responsable',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				yume_assert_same( array( $d['t10'], $d['t9'] ), array_column( yume_get_planning( array( 'oeuvre_id' => $d['grimgar'] ) ), 'tome_id' ) );
				yume_assert_same( array( $d['sukamoka2'] ), array_column( yume_get_planning( array( 'type' => 'manga' ) ), 'tome_id' ) );
				yume_assert_same( array( $d['arc7'] ), array_column( yume_get_planning( array( 'type' => 'web-novel' ) ), 'tome_id' ) );
				yume_assert_same( array( $d['arc7'], $d['raven7'] ), array_column( yume_get_planning( array( 'etat' => 'en_retard' ) ), 'tome_id' ) );
				yume_assert_same( array( $d['witches2'] ), array_column( yume_get_planning( array( 'etat' => 'bloque' ) ), 'tome_id' ) );
				yume_assert_same( 5, count( yume_get_planning( array( 'a_venir' => true ) ) ) );
				yume_assert_same( array( $d['arc7'], $d['t10'] ), array_column( yume_get_planning( array( 'limit' => 2 ) ), 'tome_id' ) );
				yume_assert_same( array( $d['arc7'], $d['t10'], $d['sukamoka2'], $d['raven7'] ), array_column( yume_get_planning( array( 'responsable' => $d['angeloids'] ) ), 'tome_id' ) );
			}
		);
	}
);

yume_tp_test(
	'yume_get_planning : publiés récents (inclure_publies_depuis), œuvre non publiée et tome privé exclus du public',
	function () {
		yume_tp_a(
			function () {
				$o      = yume_tp_oeuvre( 'Grimgar of Fantasy and Ash' );
				$recent = yume_tp_tome(
					$o,
					8,
					array(
						'yume_etape'        => 'publie',
						'yume_derniere_maj' => yume_tp_gmt( -3 ),
					),
					'publish'
				);
				$ancien = yume_tp_tome(
					$o,
					7,
					array(
						'yume_etape'        => 'publie',
						'yume_derniere_maj' => yume_tp_gmt( -20 ),
					),
					'publish'
				);
				$secret = yume_tp_oeuvre( 'Projet secret', 'light-novel', 'draft' );
				$cache  = yume_tp_tome( $secret, 1 );
				$prive  = yume_tp_tome( $o, 11, array(), 'private' );
				$ids    = array_column( yume_get_planning(), 'tome_id' );
				yume_assert_true( in_array( $recent, $ids, true ), 'publié il y a 3 jours' );
				yume_assert_false( in_array( $ancien, $ids, true ), 'publié il y a 20 jours' );
				yume_assert_false( in_array( $cache, $ids, true ), 'œuvre non publiée' );
				yume_assert_false( in_array( $prive, $ids, true ), 'tome privé' );
				$ids = array_column( yume_get_planning( array( 'inclure_publies_depuis' => 30 ) ), 'tome_id' );
				yume_assert_true( in_array( $ancien, $ids, true ) );
				$ids = array_column( yume_get_planning( array( 'inclure_publies_depuis' => 0 ) ), 'tome_id' );
				yume_assert_false( in_array( $recent, $ids, true ) );
				$ids = array_column( yume_get_planning( array( 'public' => false ) ), 'tome_id' );
				yume_assert_true( in_array( $cache, $ids, true ) && in_array( $prive, $ids, true ), 'visibles pour l’équipe' );

				// Série abandonnée ou licenciée : ses tomes à paraître sortent du planning public.
				$arretee = yume_tp_oeuvre( 'Série arrêtée' );
				wp_set_object_terms( $arretee, 'abandonnee', 'yume_statut' );
				$attente = yume_tp_tome( $arretee, 3 );
				$sorti   = yume_tp_tome(
					$arretee,
					2,
					array(
						'yume_etape'        => 'publie',
						'yume_derniere_maj' => yume_tp_gmt( -2 ),
					),
					'publish'
				);
				$ids     = array_column( yume_get_planning(), 'tome_id' );
				yume_assert_false( in_array( $attente, $ids, true ), 'tome à paraître d’une série abandonnée masqué' );
				yume_assert_true( in_array( $sorti, $ids, true ), 'sortie récente conservée' );
				yume_assert_true( in_array( $attente, array_column( yume_get_planning( array( 'public' => false ) ), 'tome_id' ), true ), 'visible pour l’équipe' );
				wp_set_object_terms( $arretee, 'en-cours', 'yume_statut' );
				yume_assert_true( in_array( $attente, array_column( yume_get_planning(), 'tome_id' ), true ), 'série reprise' );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'GET /planning : public, sans note d’équipe ni ID d’utilisateur, filtres',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				$r = yume_rest( 'GET', '/yume/v1/planning' );
				yume_assert_same( 200, $r->get_status() );
				$json = wp_json_encode( $r->get_data() );
				yume_assert_not_contains( 'Postface à relire', $json );
				yume_assert_not_contains( 'note_equipe', $json );
				yume_assert_not_contains( 'ts_activite', $json );
				$ligne = $r->get_data()[1];
				yume_assert_same( $d['t10'], $ligne['tome_id'] );
				yume_assert_same( array( 'nom' => 'Angeloids' ), $ligne['responsables']['relecture'] );
				yume_assert_same( 'À l’heure', $ligne['etat_libelle'] );
				yume_assert_same( '6', $r->get_headers()['X-WP-Total'] );
				$r = yume_rest( 'GET', '/yume/v1/planning', array( 'type' => 'manga' ) );
				yume_assert_same( array( $d['sukamoka2'] ), array_column( $r->get_data(), 'tome_id' ) );
				$r = yume_rest(
					'GET',
					'/yume/v1/planning',
					array(
						'etat'   => 'en_retard',
						'oeuvre' => $d['sw'],
					)
				);
				yume_assert_same( array( $d['arc7'] ), array_column( $r->get_data(), 'tome_id' ) );
				yume_assert_same( 400, yume_rest( 'GET', '/yume/v1/planning', array( 'type' => 'roman' ) )->get_status() );
				yume_assert_same( 400, yume_rest( 'GET', '/yume/v1/planning', array( 'etat' => 'perdu' ) )->get_status() );
			}
		);
	}
);

yume_tp_test(
	'PATCH /tomes/{id}/planning : droits (anonyme, lecteur, membre non responsable, responsable)',
	function () {
		yume_tp_a(
			function () {
				$d      = yume_tp_jeu();
				$route  = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				$saisie = array( 'avancement' => array( 'relecture' => 70 ) );
				yume_assert_same( 401, yume_rest( 'PATCH', $route, $saisie )->get_status() );
				yume_assert_same( 403, yume_rest( 'PATCH', $route, $saisie, $d['lecteur'] )->get_status() );
				yume_assert_same( 403, yume_rest( 'PATCH', '/yume/v1/tomes/' . $d['sukamoka2'] . '/planning', $saisie, $d['calumi'] )->get_status(), 'pas responsable' );
				yume_assert_same( 404, yume_rest( 'PATCH', '/yume/v1/tomes/' . $d['grimgar'] . '/planning', $saisie, $d['editeur'] )->get_status(), 'une œuvre n’est pas un tome' );
				$r = yume_rest( 'PATCH', $route, $saisie, $d['angeloids'] );
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( 70, get_post_meta( $d['t10'], 'yume_avancement', true )['relecture'] );
				yume_assert_same( 100, get_post_meta( $d['t10'], 'yume_avancement', true )['traduction'], 'avancement partiel fusionné' );
				yume_assert_same( 200, yume_rest( 'PATCH', '/yume/v1/tomes/' . $d['sukamoka2'] . '/planning', $saisie, $d['editeur'] )->get_status(), 'éditeur : tous les tomes' );
			}
		);
	}
);

yume_tp_test(
	'PATCH : assainissement (bornes 0–100, date, étape) et erreurs 400',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$route = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				$r     = yume_rest(
					'PATCH',
					$route,
					array(
						'avancement' => array(
							'relecture' => 150,
							'edition'   => -5,
						),
					),
					$d['angeloids']
				);
				yume_assert_same( 200, $r->get_status() );
				$av = get_post_meta( $d['t10'], 'yume_avancement', true );
				yume_assert_same( 100, $av['relecture'] );
				yume_assert_same( 0, $av['edition'] );
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'date_cible' => '2026-13-40' ), $d['angeloids'] )->get_status() );
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'date_cible' => '27/09/2026' ), $d['angeloids'] )->get_status() );
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'etape' => 'fini' ), $d['angeloids'] )->get_status() );
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'avancement' => array( 'relecture' => 'beaucoup' ) ), $d['angeloids'] )->get_status() );
				$r = yume_rest(
					'PATCH',
					$route,
					array(
						'bloque_raison' => '<b>attente</b> des illustrations',
						'bloque'        => 'true',
						'date_cible'    => '',
					),
					$d['angeloids']
				);
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( 'attente des illustrations', get_post_meta( $d['t10'], 'yume_bloque_raison', true ) );
				yume_assert_true( (bool) get_post_meta( $d['t10'], 'yume_bloque', true ) );
				yume_assert_same( '', get_post_meta( $d['t10'], 'yume_date_cible', true ), 'date retirée' );
				yume_assert_same( 'bloque', yume_planning_etat( $d['t10'] ) );
				// Débloquer efface la raison.
				yume_rest( 'PATCH', $route, array( 'bloque' => false ), $d['angeloids'] );
				yume_assert_same( '', get_post_meta( $d['t10'], 'yume_bloque_raison', true ) );
			}
		);
	}
);

yume_tp_test(
	'PATCH : journal par champ modifié, date et auteur de mise à jour, action yume_planning_mis_a_jour',
	function () {
		yume_tp_a(
			function () {
				$d      = yume_tp_jeu();
				$recus  = array();
				$ecoute = static function ( $tome_id, $changements, $user_id ) use ( &$recus ) {
					$recus[] = array( $tome_id, array_keys( $changements ), $user_id );
				};
				add_action( 'yume_planning_mis_a_jour', $ecoute, 10, 3 );
				$r = yume_rest(
					'PATCH',
					'/yume/v1/tomes/' . $d['t10'] . '/planning',
					array(
						'etape'       => 'edition',
						'avancement'  => array( 'relecture' => 100 ),
						'date_cible'  => yume_tp_jour( 4 ),
						'note_equipe' => 'Relecture finie, reste la postface',
					),
					$d['angeloids']
				);
				remove_action( 'yume_planning_mis_a_jour', $ecoute, 10 );
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( array( 'etape', 'avancement', 'date_cible', 'note_equipe' ), $r->get_data()['changements'] );
				yume_assert_contains( 'relecture 62', $r->get_data()['message'] );
				yume_assert_same( array( array( $d['t10'], array( 'etape', 'avancement', 'date_cible', 'note_equipe' ), $d['angeloids'] ) ), $recus );
				yume_assert_same( yume_tp_gmt( 0 ), get_post_meta( $d['t10'], 'yume_derniere_maj', true ) );
				yume_assert_same( $d['angeloids'], (int) get_post_meta( $d['t10'], 'yume_maj_par', true ) );

				$journal = yume_tp_journal( $d['t10'] );
				yume_assert_same( array( 'etape', 'avancement', 'date_cible', 'note_equipe' ), array_column( $journal, 'champ' ) );
				yume_assert_same( 'relecture', $journal[0]->ancien );
				yume_assert_same( 'edition', $journal[0]->nouveau );
				yume_assert_same( '{"traduction":100,"relecture":100,"edition":0}', $journal[1]->nouveau );
				yume_assert_same( array( '1', '1', '1', '0' ), array_column( $journal, 'public' ), 'note jamais publique' );
				foreach ( $journal as $ligne ) {
					yume_assert_same( $d['angeloids'], (int) $ligne->user_id );
				}

				// Aucun changement : ni journal ni date.
				$r = yume_rest( 'PATCH', '/yume/v1/tomes/' . $d['t10'] . '/planning', array( 'etape' => 'edition' ), $d['angeloids'] );
				yume_assert_same( array(), $r->get_data()['changements'] );
				yume_assert_same( 'Aucun changement à enregistrer.', $r->get_data()['message'] );
				yume_assert_same( 4, count( yume_tp_journal( $d['t10'] ) ) );
			}
		);
	}
);

yume_tp_test(
	'PATCH : responsables réservés à yume_maj_planning_tous et limités aux membres de l’équipe',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$route = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				$r     = yume_rest( 'PATCH', $route, array( 'responsables' => array( 'edition' => $d['calumi'] ) ), $d['angeloids'] );
				yume_assert_same( 403, $r->get_status() );
				yume_assert_same( 'yume_responsables_interdit', $r->get_data()['code'] );
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'responsables' => array( 'edition' => $d['lecteur'] ) ), $d['editeur'] )->get_status() );
				$r = yume_rest( 'PATCH', $route, array( 'responsables' => array( 'edition' => $d['calumi'] ) ), $d['gerant'] );
				yume_assert_same( 200, $r->get_status() );
				$resp = get_post_meta( $d['t10'], 'yume_responsables', true );
				yume_assert_same( $d['calumi'], $resp['edition'] );
				yume_assert_same( $d['angeloids'], $resp['relecture'], 'responsables partiels fusionnés' );
				$journal = yume_tp_journal( $d['t10'] );
				yume_assert_same( 'responsables', end( $journal )->champ );
				$entree = grouper_journal( lire_journal( array( 'tome_id' => $d['t10'] ) ), false );
				yume_assert_contains( 'édition confiée à Calumi', implode( ' ', $entree[0]['parties'] ) );
			}
		);
	}
);

yume_tp_test(
	'GET /planning/journal : public, sans notes, texte lisible, filtre par tome, flux RSS',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				yume_rest(
					'PATCH',
					'/yume/v1/tomes/' . $d['t10'] . '/planning',
					array(
						'avancement'  => array( 'relecture' => 75 ),
						'note_equipe' => 'Note très secrète',
					),
					$d['angeloids']
				);
				yume_rest( 'PATCH', '/yume/v1/tomes/' . $d['raven7'] . '/planning', array( 'avancement' => array( 'traduction' => 60 ) ), $d['calumi'] );
				$r = yume_rest( 'GET', '/yume/v1/planning/journal' );
				yume_assert_same( 200, $r->get_status() );
				$json = wp_json_encode( $r->get_data() );
				yume_assert_not_contains( 'secr', $json );
				yume_assert_not_contains( 'note_equipe', $json );
				yume_assert_same( 2, count( $r->get_data() ) );
				$entree = $r->get_data()[1];
				yume_assert_same( 'avancement', $entree['champ'] );
				yume_assert_same( 'Angeloids', $entree['auteur'] );
				yume_assert_same( 'relecture 62 % → 75 %', str_replace( "\u{00A0}", ' ', $entree['texte'] ) );
				yume_assert_same( 'Grimgar of Fantasy and Ash', $entree['oeuvre'] );
				// Projet non annoncé (œuvre en brouillon) : historique absent du journal public.
				$secret = yume_tp_oeuvre( 'Projet secret', 'light-novel', 'draft' );
				wp_set_current_user( $d['editeur'] );
				$cache = \Yume\Core\Planning\ajouter_tome(
					array(
						'oeuvre_id' => $secret,
						'numero'    => 1,
					),
					$d['editeur']
				);
				wp_set_current_user( 0 );
				yume_assert_true( is_int( $cache ) );
				yume_assert_not_contains( 'Projet secret', wp_json_encode( yume_rest( 'GET', '/yume/v1/planning/journal' )->get_data() ) );
				yume_assert_same( array(), yume_rest( 'GET', '/yume/v1/planning/journal', array( 'tome' => $cache ) )->get_data() );
				yume_assert_same( 1, count( lire_journal( array( 'tome_id' => $cache ) ) ), 'visible pour l’équipe' );
				$r = yume_rest( 'GET', '/yume/v1/planning/journal', array( 'tome' => $d['raven7'] ) );
				yume_assert_same( array( $d['raven7'] ), array_column( $r->get_data(), 'tome_id' ) );
				$r = yume_rest( 'GET', '/yume/v1/planning/journal', array( 'format' => 'rss' ) );
				yume_assert_contains( '<rss version="2.0"', (string) $r->get_data() );
				yume_assert_contains( '<item><title>Angeloids · Grimgar of Fantasy and Ash T.10', (string) $r->get_data() );
				yume_assert_not_contains( 'secr', (string) $r->get_data() );
			}
		);
	}
);

yume_tp_test(
	'POST /planning/tomes : ajout d’un tome brouillon au planning, droits, doublons, validation',
	function () {
		yume_tp_a(
			function () {
				$d      = yume_tp_jeu();
				$saisie = array(
					'oeuvre_id'    => $d['grimgar'],
					'nature'       => 'tome',
					'numero'       => '11',
					'titre'        => 'Le retour',
					'responsables' => array(
						'traduction' => $d['calumi'],
						'relecture'  => $d['angeloids'],
					),
					'date_cible'   => yume_tp_jour( 40 ),
				);
				yume_assert_same( 401, yume_rest( 'POST', '/yume/v1/planning/tomes', $saisie )->get_status() );
				yume_assert_same( 403, yume_rest( 'POST', '/yume/v1/planning/tomes', $saisie, $d['calumi'] )->get_status() );
				$r = yume_rest( 'POST', '/yume/v1/planning/tomes', $saisie, $d['editeur'] );
				yume_assert_same( 201, $r->get_status() );
				$id = (int) $r->get_data()['tome_id'];
				yume_assert_same( 'yume_tome', get_post_type( $id ) );
				yume_assert_same( 'draft', get_post_status( $id ) );
				yume_assert_same( 'Grimgar of Fantasy and Ash — Tome 11 : Le retour', get_post( $id )->post_title );
				yume_assert_same( $d['grimgar'], (int) get_post_meta( $id, 'yume_oeuvre_id', true ) );
				yume_assert_same( 'a_faire', get_post_meta( $id, 'yume_etape', true ) );
				yume_assert_same( $d['angeloids'], get_post_meta( $id, 'yume_responsables', true )['relecture'] );
				yume_assert_same( 0, get_post_meta( $id, 'yume_responsables', true )['edition'] );
				yume_assert_same( yume_tp_jour( 40 ), get_post_meta( $id, 'yume_date_cible', true ) );
				yume_assert_same(
					'Le retour',
					yume_get_planning(
						array(
							'oeuvre_id' => $d['grimgar'],
							'a_venir'   => true,
						)
					)[1]['titre']
				);
				yume_assert_same( 'creation', yume_tp_journal( $id )[0]->champ );
				yume_assert_contains( 'ajouté au planning', $r->get_data()['message'] );

				$r = yume_rest( 'POST', '/yume/v1/planning/tomes', $saisie, $d['gerant'] );
				yume_assert_same( 409, $r->get_status(), 'doublon' );
				yume_assert_same( $id, $r->get_data()['data']['tome_id'] );
				yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/planning/tomes', array_merge( $saisie, array( 'oeuvre_id' => $d['t10'] ) ), $d['editeur'] )->get_status(), 'œuvre invalide' );
				yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/planning/tomes', array_merge( $saisie, array( 'numero' => '' ) ), $d['editeur'] )->get_status(), 'numéro obligatoire' );
				yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/planning/tomes', array_merge( $saisie, array( 'etape' => 'publie' ) ), $d['editeur'] )->get_status() );
				yume_assert_same(
					400,
					yume_rest(
						'POST',
						'/yume/v1/planning/tomes',
						array_merge(
							$saisie,
							array(
								'numero'       => '12',
								'responsables' => array( 'edition' => $d['lecteur'] ),
							)
						),
						$d['editeur']
					)->get_status(),
					'responsable hors équipe'
				);
				$r = yume_rest(
					'POST',
					'/yume/v1/planning/tomes',
					array_merge(
						$saisie,
						array(
							'numero' => '26,5',
							'nature' => 'arc',
							'titre'  => '',
						)
					),
					$d['editeur']
				);
				yume_assert_same( 201, $r->get_status() );
				yume_assert_same( 'Arc 26,5', yume_libelle_tome( (int) $r->get_data()['tome_id'] ) );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Événements
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'yume_tome_publie : étape publiée, 100 %, débloqué, journal public, annonce Discord avec couverture et liens',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				yume_tp_reglages( array( 'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/sorties' ) );
				update_post_meta( $d['t10'], 'yume_lien_pdf', 'https://clictune.com/pdf-t10' );
				update_post_meta( $d['t10'], 'yume_lien_epub', 'https://clictune.com/epub-t10' );
				update_post_meta( $d['t10'], 'yume_bloque', true );
				$image = wp_insert_attachment(
					array(
						'post_mime_type' => 'image/jpeg',
						'post_title'     => 'Couverture',
						'post_status'    => 'inherit',
					),
					false,
					$d['t10']
				);
				update_post_meta( $image, '_wp_attached_file', 'tests/couverture-t10.jpg' );
				set_post_thumbnail( $d['t10'], $image );
				$recus  = 0;
				$ecoute = static function () use ( &$recus ) {
					++$recus;
				};
				add_action( 'yume_planning_mis_a_jour', $ecoute );
				$requetes = array();
				$arreter  = yume_tp_http( $requetes );
				wp_set_current_user( $d['editeur'] );
				try {
					do_action( 'yume_tome_publie', $d['t10'] );
				} finally {
					$arreter();
					remove_action( 'yume_planning_mis_a_jour', $ecoute );
					wp_set_current_user( 0 );
				}
				yume_assert_same( 'publie', get_post_meta( $d['t10'], 'yume_etape', true ) );
				yume_assert_same(
					array(
						'traduction' => 100,
						'relecture'  => 100,
						'edition'    => 100,
					),
					get_post_meta( $d['t10'], 'yume_avancement', true )
				);
				yume_assert_false( (bool) get_post_meta( $d['t10'], 'yume_bloque', true ) );
				yume_assert_same( yume_tp_gmt( 0 ), get_post_meta( $d['t10'], 'yume_derniere_maj', true ) );
				yume_assert_same( 'publie', yume_planning_etat( $d['t10'] ) );
				yume_assert_same( 1, $recus );

				$champs = array_column( yume_tp_journal( $d['t10'] ), 'champ' );
				yume_assert_true( in_array( 'publie', $champs, true ) && in_array( 'etape', $champs, true ) );
				$entrees = grouper_journal(
					lire_journal(
						array(
							'public'  => true,
							'tome_id' => $d['t10'],
						)
					),
					false
				);
				yume_assert_same( 1, count( $entrees ), 'une seule entrée publique regroupée' );
				yume_assert_true( $entrees[0]['publie'] );
				yume_assert_same( 'Pizzflc', $entrees[0]['auteur'] );

				yume_assert_same( 1, count( $requetes ) );
				yume_assert_same( 'https://discord.com/api/webhooks/1/sorties', $requetes[0]['url'] );
				yume_assert_same( 'POST', $requetes[0]['args']['method'] );
				$corps = json_decode( $requetes[0]['args']['body'], true );
				yume_assert_contains( '**Grimgar of Fantasy and Ash** — Tome 10 est disponible', $corps['content'] );
				yume_assert_same( array( 'parse' => array() ), $corps['allowed_mentions'] );
				$embed = $corps['embeds'][0];
				yume_assert_same( 'Grimgar of Fantasy and Ash — Tome 10', $embed['title'] );
				yume_assert_contains( 'couverture-t10.jpg', $embed['image']['url'] );
				yume_assert_contains( '[PDF](https://clictune.com/pdf-t10)', $embed['description'] );
				yume_assert_contains( '[EPUB](https://clictune.com/epub-t10)', $embed['description'] );
				yume_assert_contains( 'Lire en ligne', $embed['description'] );
				yume_assert_same( 0xF3A6C8, $embed['color'] );
			}
		);
	}
);

yume_tp_test(
	'yume_tome_publie émis par le cœur à la publication d’un tome planifié ; sans webhook, aucune requête',
	function () {
		$o        = yume_tp_oeuvre( 'Raven of the Inner Palace' );
		$tome     = yume_tp_tome(
			$o,
			7,
			array(
				'yume_etape'        => 'edition',
				'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$requetes = array();
		$arreter  = yume_tp_http( $requetes );
		try {
			wp_update_post(
				array(
					'ID'          => $tome,
					'post_status' => 'publish',
				)
			);
		} finally {
			$arreter();
		}
		yume_assert_same( 'publie', get_post_meta( $tome, 'yume_etape', true ) );
		yume_assert_same( array(), $requetes, 'webhook vide : aucune requête' );
		yume_assert_same( 'publie', yume_get_planning( array( 'oeuvre_id' => $o ) )[0]['etat'], 'visible parmi les publiés récents' );
	}
);

yume_tp_test(
	'yume_chapitre_publie : annonce Discord courte, journal public, activité du tome',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				yume_tp_reglages( array( 'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/sorties' ) );
				update_post_meta( $d['arc7'], 'yume_derniere_maj', yume_tp_gmt( -10 ) );
				$chapitre = yume_factory_post(
					array(
						'post_type'   => 'yume_chapitre',
						'post_title'  => 'Chapitre 9',
						'post_status' => 'draft',
						'meta_input'  => array(
							'yume_tome_id'    => $d['arc7'],
							'yume_numero'     => 9,
							'yume_nature'     => 'chapitre',
							'yume_sous_titre' => 'Le tournoi',
						),
					)
				);
				$requetes = array();
				$arreter  = yume_tp_http( $requetes );
				try {
					do_action( 'yume_chapitre_publie', $chapitre );
				} finally {
					$arreter();
				}
				yume_assert_same( 1, count( $requetes ) );
				$corps = json_decode( $requetes[0]['args']['body'], true );
				yume_assert_contains( 'Nouveau chapitre : **Secrets of the Silent Witch** — Arc 7, Chapitre 9 « Le tournoi »', $corps['content'] );
				yume_assert_false( isset( $corps['embeds'] ), 'annonce courte sans embed' );
				yume_assert_same( yume_tp_gmt( 0 ), get_post_meta( $d['arc7'], 'yume_derniere_maj', true ) );
				$journal = yume_tp_journal( $d['arc7'] );
				yume_assert_same( 'chapitre_publie', $journal[0]->champ );
				yume_assert_same( '1', $journal[0]->public );
				$entree = grouper_journal( lire_journal( array( 'tome_id' => $d['arc7'] ) ), false )[0];
				yume_assert_same( 'Secrets of the Silent Witch A.7 · Chapitre 9', $entree['cible'] );
				yume_assert_true( $entree['publie'] );
			}
		);
	}
);

yume_tp_test(
	'yume_journal_planning hors du module (méta-boîte du cœur) : action émise une fois après l’enregistrement',
	function () {
		yume_tp_a(
			function () {
				$d      = yume_tp_jeu();
				$recus  = array();
				$ecoute = static function ( $tome_id, $changements ) use ( &$recus ) {
					$recus[] = array( $tome_id, array_keys( $changements ) );
				};
				add_action( 'yume_planning_mis_a_jour', $ecoute, 10, 2 );
				yume_journal_planning( $d['t10'], $d['angeloids'], 'etape', 'relecture', 'edition' );
				yume_journal_planning( $d['t10'], $d['angeloids'], 'avancement', array( 'relecture' => 62 ), array( 'relecture' => 80 ) );
				yume_journal_planning( $d['t10'], $d['angeloids'], 'date_cible', '2026-09-27', '2026-09-27' );
				yume_assert_same( array(), $recus, 'pas encore émise' );
				do_action( 'wp_after_insert_post', $d['t10'], get_post( $d['t10'] ), true, null );
				remove_action( 'yume_planning_mis_a_jour', $ecoute, 10 );
				yume_assert_same( array( array( $d['t10'], array( 'etape', 'avancement' ) ) ), $recus );
				yume_assert_same( 2, count( yume_tp_journal( $d['t10'] ) ), 'valeur identique non journalisée' );
				yume_journal_planning( $d['t10'], $d['angeloids'], 'note_equipe', '', 'Interne' );
				$journal = yume_tp_journal( $d['t10'] );
				yume_assert_same( '0', end( $journal )->public );
				\Yume\Core\Planning\vider_tampon();
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * File d'e-mails
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'yume_queue_email : utilisateur ou adresse, adresse invalide ignorée, pas de doublon en attente',
	function () {
		$u = yume_tp_membre( 'yume_traducteur', 'Calumi' );
		yume_queue_email( $u, 'Rappel planning', '<p>Bonjour</p>', 'rappel' );
		yume_queue_email( 'lecteur@example.test', 'Nouveau tome', '<p>Tome 10</p><script>alert(1)</script>', 'alerte' );
		yume_queue_email( 'pas-une-adresse', 'Sujet', '<p>Texte</p>' );
		yume_queue_email( 999999, 'Sujet', '<p>Texte</p>' );
		yume_queue_email( $u, 'Rappel planning', '<p>Bonjour</p>', 'rappel' );
		$file = yume_tp_file();
		yume_assert_same( 2, count( $file ) );
		yume_assert_same( get_userdata( $u )->user_email, $file[0]->destinataire );
		yume_assert_same( $u, (int) $file[0]->user_id );
		yume_assert_same( 'attente', $file[0]->statut );
		yume_assert_same( 'rappel', $file[0]->contexte );
		yume_assert_same( 'lecteur@example.test', $file[1]->destinataire );
		yume_assert_not_contains( '<script', $file[1]->html );
		yume_assert_true( (bool) wp_next_scheduled( 'yume_notifications_envoyer' ) );
	}
);

yume_tp_test(
	'envoi par lot : gabarit HTML Yume, lien « Gérer mes alertes », statut envoyé',
	function () {
		$u = yume_tp_membre( 'subscriber', 'Kaede' );
		yume_queue_email( $u, 'Tome 10 disponible', '<p>Bonne lecture !</p><p><a href="https://exemple.test/lire">Lire</a></p>', 'alerte' );
		$envoyes = array();
		$capture = static function ( $pre, $atts ) use ( &$envoyes ) {
			$envoyes[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 20, 2 );
		try {
			$bilan = envoyer_lot();
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 20 );
		}
		yume_assert_same( 1, $bilan['envoyes'] );
		yume_assert_same( 1, count( $envoyes ) );
		yume_assert_same( get_userdata( $u )->user_email, $envoyes[0]['to'] );
		yume_assert_same( 'Tome 10 disponible', $envoyes[0]['subject'] );
		yume_assert_contains( 'Content-Type: text/html; charset=UTF-8', implode( "\n", (array) $envoyes[0]['headers'] ) );
		$html = $envoyes[0]['message'];
		yume_assert_contains( '<!DOCTYPE html><html lang="fr">', $html );
		yume_assert_contains( 'Gérer mes alertes', $html );
		yume_assert_contains( esc_url( yume_url_page( 'compte' ) ), $html );
		yume_assert_contains( '#241740', $html, 'couleurs du thème en ligne' );
		yume_assert_contains( 'Bonne lecture !', $html );
		yume_assert_contains( '<a style="color:#b23a71;" href="https://exemple.test/lire">', $html );
		$ligne = yume_tp_file()[0];
		yume_assert_same( 'envoye', $ligne->statut );
		yume_assert_same( '1', (string) $ligne->tentatives );
		yume_assert_true( '' !== (string) $ligne->envoye_le );
		yume_assert_same( 0, envoyer_lot()['envoyes'], 'rien à renvoyer' );
	}
);

yume_tp_test(
	'envoi par lot : trois tentatives au plus, puis échec journalisé ; lots limités',
	function () {
		yume_queue_email( 'a@example.test', 'Sujet A', '<p>A</p>' );
		for ( $i = 0; $i < 3; $i++ ) {
			yume_queue_email( 'b' . $i . '@example.test', 'Sujet B', '<p>B</p>' );
		}
		$refus = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $refus, 20 );
		try {
			$bilan = envoyer_lot( 1 );
			yume_assert_same( 1, $bilan['echecs'] );
			yume_assert_same( 'attente', yume_tp_file()[0]->statut );
			yume_assert_same( '1', (string) yume_tp_file()[0]->tentatives );
			yume_assert_same( 'attente', yume_tp_file()[1]->statut, 'lot d’un seul e-mail' );
			envoyer_lot( 1 );
			$bilan = envoyer_lot( 1 );
			yume_assert_same( 1, $bilan['abandons'] );
		} finally {
			remove_filter( 'pre_wp_mail', $refus, 20 );
		}
		yume_assert_same( 'echec', yume_tp_file()[0]->statut );
		yume_assert_same( '3', (string) yume_tp_file()[0]->tentatives );
		$echecs = get_option( 'yume_planning_echecs' );
		yume_assert_same( 'email', $echecs[0]['type'] );
		yume_assert_contains( 'Sujet A', $echecs[0]['message'] );
		yume_assert_same( 3, envoyer_lot()['envoyes'], 'les autres partent quand l’envoi refonctionne' );
	}
);

yume_tp_test(
	'purge : envois de plus de 30 jours supprimés, récents conservés ; utilisateur supprimé abandonné',
	function () {
		global $wpdb;
		yume_queue_email( 'vieux@example.test', 'Vieux', '<p>V</p>' );
		yume_queue_email( 'recent@example.test', 'Récent', '<p>R</p>' );
		yume_queue_email( 'attente@example.test', 'Attente', '<p>A</p>' );
		$file = yume_tp_file();
		$wpdb->update( table_notifications(), array( 'statut' => 'envoye', 'envoye_le' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $file[0]->id ) ); // phpcs:ignore
		$wpdb->update( table_notifications(), array( 'statut' => 'envoye', 'envoye_le' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $file[1]->id ) ); // phpcs:ignore
		yume_assert_same( 1, purger() );
		yume_assert_same( array( 'Récent', 'Attente' ), array_column( yume_tp_file(), 'sujet' ) );

		$u = yume_tp_membre( 'subscriber', 'Parti' );
		yume_queue_email( $u, 'Au revoir', '<p>…</p>' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u );
		envoyer_lot();
		yume_assert_same( 'echec', yume_tp_file()[2]->statut );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Discord
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'yume_discord : JSON, 2 000 caractères au plus, mentions désactivées, canaux, webhook vide ou non https',
	function () {
		$requetes = array();
		$arreter  = yume_tp_http( $requetes );
		try {
			yume_assert_false( yume_discord( 'sorties', 'Bonjour' ), 'webhook vide' );
			yume_assert_same( 0, count( $requetes ) );
			yume_tp_reglages(
				array(
					'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/sorties',
					'discord_webhook_equipe'  => 'https://discord.com/api/webhooks/2/equipe',
				)
			);
			yume_assert_false( yume_discord( 'general', 'Bonjour' ), 'canal inconnu' );
			yume_assert_same( 0, count( $requetes ) );
			yume_assert_true(
				yume_discord(
					'equipe',
					str_repeat( 'é', 2500 ) . ' @everyone',
					array(
						array(
							'title' => str_repeat( 'x', 300 ),
							'color' => 123,
						),
					)
				)
			);
			yume_assert_same( 'https://discord.com/api/webhooks/2/equipe', $requetes[0]['url'] );
			yume_assert_contains( 'application/json', $requetes[0]['args']['headers']['Content-Type'] );
			$corps = json_decode( $requetes[0]['args']['body'], true );
			yume_assert_same( 2000, mb_strlen( $corps['content'] ) );
			yume_assert_same( '…', mb_substr( $corps['content'], -1 ) );
			yume_assert_same( array(), $corps['allowed_mentions']['parse'] );
			yume_assert_same( 256, mb_strlen( $corps['embeds'][0]['title'] ) );
			yume_assert_false( yume_discord( 'sorties', '' ), 'message vide' );
			$http = static function ( $valeur ) {
				$valeur['discord_webhook_sorties'] = 'http://discord.com/api/webhooks/1/sorties';
				return $valeur;
			};
			add_filter( 'option_yume_reglages', $http );
			yume_assert_false( yume_discord( 'sorties', 'Bonjour' ), 'webhook non https' );
			remove_filter( 'option_yume_reglages', $http );
			yume_assert_same( 1, count( $requetes ) );
		} finally {
			$arreter();
		}
	}
);

yume_tp_test(
	'yume_discord : échec HTTP ou réseau journalisé sans erreur fatale',
	function () {
		yume_tp_reglages( array( 'discord_webhook_equipe' => 'https://discord.com/api/webhooks/2/equipe' ) );
		$requetes = array();
		$arreter  = yume_tp_http( $requetes, 500 );
		try {
			yume_assert_false( yume_discord( 'equipe', 'Rappel' ) );
		} finally {
			$arreter();
		}
		$arreter = yume_tp_http( $requetes, new WP_Error( 'http_request_failed', 'Délai dépassé' ) );
		try {
			yume_assert_false( yume_discord( 'equipe', 'Rappel' ) );
		} finally {
			$arreter();
		}
		$echecs = get_option( 'yume_planning_echecs' );
		yume_assert_same( 2, count( $echecs ) );
		yume_assert_contains( 'Délai dépassé', $echecs[0]['message'] );
		yume_assert_contains( 'HTTP 500', $echecs[1]['message'] );
		yume_assert_same( 'discord', $echecs[1]['type'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Rappels et récapitulatif
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'rappels : retard par date et par inactivité → e-mail aux responsables, Discord équipe, journal, méta',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				yume_tp_reglages( array( 'discord_webhook_equipe' => 'https://discord.com/api/webhooks/2/equipe' ) );
				$requetes = array();
				$arreter  = yume_tp_http( $requetes );
				try {
					$rapport = executer_rappels();
				} finally {
					$arreter();
				}
				$rappels = array_column( $rapport['rappels'], 'destinataires', 'tome_id' );
				yume_assert_same( array( $d['angeloids'] ), $rappels[ $d['arc7'] ], 'responsable de l’étape en cours' );
				yume_assert_same( array( $d['calumi'] ), $rappels[ $d['raven7'] ] );
				yume_assert_same( 2, count( $rappels ), 'aucun rappel pour les tomes à l’heure' );
				$mails = yume_tp_file( 'rappel' );
				yume_assert_same( 2, count( $mails ) );
				yume_assert_same( 'Rappel planning : Secrets of the Silent Witch — Arc 7', $mails[0]->sujet );
				yume_assert_contains( 'est dépassée de 3 jours', $mails[0]->html );
				yume_assert_contains( 'Bonjour Angeloids', $mails[0]->html );
				yume_assert_contains( 'vue=taches#yn-tache-' . $d['arc7'], $mails[0]->html, 'carte de la tâche dans la vue « Mes tâches »' );
				yume_assert_contains( 'n’a pas été mis à jour depuis 20 jours', $mails[1]->html );
				yume_assert_same( 1, count( $requetes ), 'un seul message Discord groupé' );
				$texte = json_decode( $requetes[0]['args']['body'], true )['content'];
				yume_assert_contains( 'Rappels du planning', $texte );
				yume_assert_contains( 'Secrets of the Silent Witch — Arc 7', $texte );
				yume_assert_contains( 'responsable : Angeloids', $texte );
				$journal = yume_tp_journal( $d['arc7'] );
				yume_assert_same( 'rappel', $journal[0]->champ );
				yume_assert_same( '1', $journal[0]->public );
				yume_assert_same( 0, (int) $journal[0]->user_id );
				$infos = json_decode( $journal[0]->nouveau, true );
				yume_assert_same( 'date', $infos['motif'] );
				yume_assert_same( array( 'email', 'discord' ), $infos['canaux'] );
				yume_assert_same( yume_tp_gmt( 0 ), get_post_meta( $d['arc7'], '_yume_dernier_rappel', true ) );
				$entree = grouper_journal(
					lire_journal(
						array(
							'tome_id' => $d['arc7'],
							'public'  => true,
						)
					),
					false
				)[0];
				yume_assert_same( 'Système', $entree['auteur'] );
				yume_assert_contains( 'rappel envoyé à Angeloids : date cible dépassée de 3 jours', $entree['parties'][0] );
			}
		);
	}
);

yume_tp_test(
	'rappels : anti-répétition (un rappel par tome tous les 3 jours, heure simulée)',
	function () {
		$d = array();
		yume_tp_a(
			function () use ( &$d ) {
				$d = yume_tp_jeu();
				yume_assert_same( 2, count( executer_rappels()['rappels'] ) );
				yume_assert_same( 2 + count( yume_tp_gerants() ), envoyer_lot()['envoyes'], 'deux rappels et le signalement aux gérants' );
			}
		);
		yume_tp_a(
			function () {
				yume_assert_same( 0, count( executer_rappels()['rappels'] ), 'lendemain' );
			},
			YUME_TP_MAINTENANT + DAY_IN_SECONDS
		);
		yume_tp_a(
			function () {
				yume_assert_same( 0, count( executer_rappels()['rappels'] ), 'deux jours après' );
			},
			YUME_TP_MAINTENANT + 2 * DAY_IN_SECONDS
		);
		yume_tp_a(
			function () use ( &$d ) {
				$rapport = executer_rappels();
				yume_assert_same( 2, count( $rapport['rappels'] ), 'trois jours après (même heure, quelques secondes plus tôt)' );
				yume_assert_same( 'date', $rapport['rappels'][0]['motif'] );
			},
			YUME_TP_MAINTENANT + 3 * DAY_IN_SECONDS - 30
		);
		yume_assert_same( 4, count( yume_tp_file( 'rappel' ) ) );
	}
);

yume_tp_test(
	'rappels : tome bloqué sans responsable signalé aux gérants ; tome en retard sans responsable → gérants',
	function () {
		yume_tp_a(
			function () {
				$d    = yume_tp_jeu();
				$seul = yume_tp_tome( $d['raven'], 8, array( 'yume_date_cible' => yume_tp_jour( -1 ) ) );
				// Un tome bloqué dont toutes les étapes restantes ont un responsable n'est pas signalé.
				$complet = yume_tp_tome(
					$d['raven'],
					9,
					array(
						'yume_bloque'       => true,
						'yume_responsables' => array(
							'traduction' => $d['calumi'],
							'relecture'  => $d['angeloids'],
							'edition'    => $d['jojo'],
						),
					)
				);
				$rapport = executer_rappels();
				yume_assert_same( array( $d['witches2'] ), array_column( $rapport['signalements'], 'tome_id' ) );
				yume_assert_same( array( 'relecture', 'edition' ), $rapport['signalements'][0]['manquants'] );
				$signalements = yume_tp_file( 'signalement' );
				$dest         = array_map( 'intval', array_column( $signalements, 'user_id' ) );
				sort( $dest );
				$attendus = yume_tp_gerants();
				yume_assert_true( in_array( $d['gerant'], $attendus, true ) );
				yume_assert_same( $attendus, $dest, 'gérants et administrateurs' );
				yume_assert_contains( 'aucun relecteur assigné', $signalements[0]->html );
				yume_assert_contains( 'relecteur manquant', $signalements[0]->html );
				yume_assert_same( '0', yume_tp_journal( $d['witches2'] )[0]->public, 'signalement non public' );
				$vers_gerants = array_column( $rapport['rappels'], 'destinataires', 'tome_id' )[ $seul ];
				sort( $vers_gerants );
				yume_assert_same( $attendus, $vers_gerants, 'retard sans responsable : gérants prévenus' );
				yume_assert_false( in_array( $complet, array_column( $rapport['signalements'], 'tome_id' ), true ) );
			}
		);
	}
);

yume_tp_test(
	'récapitulatif hebdomadaire : gérants et administrateurs, sorties, retards, bloqués ; une fois par jour',
	function () {
		yume_tp_a(
			function () {
				$d        = yume_tp_jeu();
				$resultat = envoyer_digest();
				yume_assert_true( $resultat['envoye'] );
				yume_assert_same( 1, $resultat['sorties'] );
				yume_assert_same( 2, $resultat['retards'] );
				yume_assert_same( 1, $resultat['bloques'] );
				$mails = yume_tp_file( 'digest' );
				yume_assert_same( count( yume_tp_gerants() ), count( $mails ), 'gérants et administrateurs' );
				yume_assert_true( in_array( $d['gerant'], array_map( 'intval', array_column( $mails, 'user_id' ) ), true ) );
				yume_assert_false( in_array( $d['editeur'], array_map( 'intval', array_column( $mails, 'user_id' ) ), true ), 'pas les éditeurs' );
				$html = $mails[0]->html;
				foreach ( array( 'Sorties de la semaine', 'Grimgar of Fantasy and Ash — Tome 9', 'Retards', 'Secrets of the Silent Witch — Arc 7', 'Tomes bloqués', 'relecteur manquant', 'Sorties prévues dans les 7 jours', 'Grimgar of Fantasy and Ash — Tome 10' ) as $attendu ) {
					yume_assert_contains( $attendu, $html );
				}
				yume_assert_same( 'Récapitulatif du planning · jeudi 24 septembre', $mails[0]->sujet );
				yume_assert_false( envoyer_digest()['envoye'], 'déjà envoyé aujourd’hui' );
				yume_assert_same( 'digest', lire_journal( array( 'champs' => array( 'digest' ) ) )[0]->champ );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Planification
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'planification : heure de Paris, jour du récapitulatif, reprogrammation au changement de réglage, nettoyage',
	function () {
		yume_tp_a(
			function () {
				desactiver();
				yume_assert_false( wp_next_scheduled( 'yume_planning_rappels' ) );
				planifier();
				$rappels = wp_next_scheduled( 'yume_planning_rappels' );
				$digest  = wp_next_scheduled( 'yume_planning_digest' );
				yume_assert_same( '2026-09-25 09:00', format_fr( $rappels, 'Y-m-d H:i' ), 'lendemain 9 h (Paris)' );
				yume_assert_same( '2026-09-28 09:00', format_fr( $digest, 'Y-m-d H:i' ), 'lundi suivant' );
				yume_assert_same( 'daily', wp_get_scheduled_event( 'yume_planning_rappels' )->schedule );
				yume_assert_same( 'weekly', wp_get_scheduled_event( 'yume_planning_digest' )->schedule );
				yume_assert_same( 300, wp_get_schedules()[ wp_get_scheduled_event( 'yume_notifications_envoyer' )->schedule ]['interval'] );
				planifier();
				yume_assert_same( $rappels, wp_next_scheduled( 'yume_planning_rappels' ), 'idempotent' );

				yume_tp_reglages(
					array(
						'rappel_heure' => 15,
						'digest_jour'  => 3,
					)
				);
				yume_assert_same( '2026-09-24 15:00', format_fr( wp_next_scheduled( 'yume_planning_rappels' ), 'Y-m-d H:i' ) );
				yume_assert_same( '2026-09-30 15:00', format_fr( wp_next_scheduled( 'yume_planning_digest' ), 'Y-m-d H:i' ), 'mercredi' );
				yume_assert_same( 1, count( array_filter( _get_cron_array(), static fn( $e ) => isset( $e['yume_planning_rappels'] ) ) ), 'un seul événement' );

				do_action( 'yume_core_deactivate' );
				foreach ( array( 'yume_planning_rappels', 'yume_planning_digest', 'yume_notifications_envoyer' ) as $hook ) {
					yume_assert_false( wp_next_scheduled( $hook ), $hook );
				}
			}
		);
	}
);

yume_tp_test(
	'planification : changement d’heure été/hiver corrigé',
	function () {
		// Dimanche 25 octobre 2026 : passage à l'heure d'hiver. 9 h à Paris = 8 h UTC.
		$apres = gmmktime( 12, 0, 0, 10, 24, 2026 );
		$ts    = prochaine_occurrence( 9, -1, $apres );
		yume_assert_same( '2026-10-25 08:00', gmdate( 'Y-m-d H:i', $ts ) );
		yume_assert_true( est_conforme( $ts, 9, -1 ) );
		yume_assert_false( est_conforme( $ts + HOUR_IN_SECONDS, 9, -1 ), 'un événement quotidien décalé à 10 h est reprogrammé' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Blocs
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'bloc yume/upcoming : compact, sans carte, par date, sans publiés ni bloqués',
	function () {
		yume_tp_a(
			function () {
				$d    = yume_tp_jeu();
				$html = yume_render_block( 'yume/upcoming', array( 'count' => 3 ) );
				yume_assert_contains( 'class="yn-upcoming wp-block-yume-upcoming"', $html );
				yume_assert_not_contains( 'yn-card', $html );
				yume_assert_same( 3, substr_count( $html, 'yn-upcoming__item' ) );
				yume_assert_true( strpos( $html, 'Secrets of the Silent Witch' ) < strpos( $html, 'Grimgar of Fantasy and Ash' ), 'ordre des dates' );
				yume_assert_contains( 'lun. 21 sept.', $html );
				// UX-4 : le retard se lit en clair (pas seulement à la couleur et à l'icône) et une
				// date dépassée n'est pas présentée comme une date de sortie à venir.
				yume_assert_contains( '<span aria-hidden="true">▲</span> En retard · relecture</span>', $html );
				yume_assert_not_contains( 'En retard : ', $html, 'pas de doublon pour les lecteurs d’écran' );
				yume_assert_contains( '<span class="yn-label yn-upcoming__date">prévu lun. 21 sept.</span>', $html );
				yume_assert_contains( '<span class="yn-label yn-upcoming__date">' . \Yume\Core\Planning\date_cible_lisible( yume_tp_jour( 3 ) ) . '</span>', $html, 'date future inchangée' );
				yume_assert_contains( 'À l’heure : </span>62', $html );
				yume_assert_not_contains( 'Witches', $html );
				yume_assert_not_contains( 'Tome 9', $html );
				yume_assert_same( 4, substr_count( yume_render_block( 'yume/upcoming', array( 'count' => 10 ) ), 'yn-upcoming__item' ), 'quatre tomes à venir non bloqués' );
			}
		);
	}
);

yume_tp_test(
	'bloc yume/upcoming : message discret quand rien n’est planifié',
	function () {
		yume_tp_oeuvre( 'Grimgar of Fantasy and Ash' );
		yume_assert_contains( 'Aucune sortie planifiée', yume_render_block( 'yume/upcoming' ) );
	}
);

yume_tp_test(
	'bloc yume/planning : chiffres, filtres GET accessibles, tableau, légende, journal public sans notes',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				mettre_a_jour(
					$d['t10'],
					array(
						'avancement'  => array( 'relecture' => 70 ),
						'note_equipe' => 'Ne pas publier ceci',
					),
					$d['angeloids']
				);
				$html = yume_render_block( 'yume/planning' );
				yume_assert_contains( 'class="yn-planning wp-block-yume-planning" id="yn-planning"', $html );
				yume_assert_not_contains( '<h1', $html );
				yume_assert_contains( '<h2 id="yn-planning-journal-titre" class="yn-label">Journal des mises à jour</h2>', $html );
				yume_assert_contains( 'Comment lire ce planning', $html );
				yume_assert_contains( '<nav class="yn-planning__filtres" aria-label="Filtrer le planning">', $html );
				yume_assert_contains( 'aria-current="true">Tout</a>', $html );
				yume_assert_contains( 'Les jours de sortie habituels sont le mercredi, le samedi et le dimanche.', $html );
				yume_assert_contains( '<table class="yn-planning__table" role="table">', $html );
				yume_assert_same( 6, substr_count( $html, 'yn-planning__ligne ' ) );
				yume_assert_contains( 'Bloqué · relecteur manquant', $html );
				yume_assert_contains( 'Prochaine sortie', $html );
				yume_assert_contains( '<p class="yn-planning__valeur">dim. 27 sept.</p>', $html, 'prochaine sortie datée' );
				yume_assert_contains( '<p class="yn-planning__valeur">5 tomes</p>', $html );
				yume_assert_contains( 'sur 5 œuvres · 3 membres actifs', $html );
				yume_assert_contains( 'relecture 62 % → 70 %', str_replace( "\u{00A0}", ' ', $html ) );
				yume_assert_not_contains( 'Ne pas publier', $html );
				yume_assert_not_contains( 'Postface à relire', $html );
				yume_assert_contains( 'class="yn-bar yn-bar--warn" style="--v:35%"', $html, 'étape en retard' );
				yume_assert_contains( '100 % · Calumi', str_replace( "\u{00A0}", ' ', $html ) );

				$_GET['type'] = 'manga';
				try {
					$html = yume_render_block( 'yume/planning' );
				} finally {
					unset( $_GET['type'] );
				}
				yume_assert_same( 1, substr_count( $html, 'yn-planning__ligne ' ) );
				yume_assert_contains( 'SukaMoka', $html );
				yume_assert_contains( 'aria-current="true">Manga</a>', $html );

				$_GET['etat'] = 'bloque';
				try {
					$html = yume_render_block( 'yume/planning', array( 'showFilters' => false ) );
				} finally {
					unset( $_GET['etat'] );
				}
				yume_assert_not_contains( 'yn-planning__filtres', $html );
				yume_assert_same( 6, substr_count( $html, 'yn-planning__ligne ' ), 'filtres désactivés : GET ignoré' );
			}
		);
	}
);

yume_tp_test(
	'bloc yume/oeuvre-planning : tome en préparation de l’œuvre ; vide sans tome en cours',
	function () {
		yume_tp_a(
			function () {
				$d    = yume_tp_jeu();
				$html = yume_tp_bloc( 'yume/oeuvre-planning', array(), array( 'postId' => $d['grimgar'] ) );
				yume_assert_contains( 'yn-oeuvre-planning yn-card', $html );
				yume_assert_contains( 'Planning de l’œuvre', $html );
				yume_assert_contains( 'Tome 10 · sortie prévue dim. 27 sept.', $html );
				yume_assert_contains( 'Relecture · Angeloids', $html );
				yume_assert_contains( 'Édition · JojoGg', $html );
				yume_assert_contains( '<span aria-hidden="true">●</span> À l’heure', $html );
				yume_assert_contains( 'oeuvre=' . $d['grimgar'], $html );
				yume_assert_contains( '#yn-planning-journal', $html );
				yume_assert_not_contains( 'Postface à relire', $html );
				// Sur la page d'un tome, l'œuvre du tome.
				yume_assert_contains( 'Tome 10', yume_tp_bloc( 'yume/oeuvre-planning', array(), array( 'postId' => $d['t9'] ) ) );
				$vide = yume_tp_oeuvre( 'Terminée' );
				yume_assert_same( '', yume_tp_bloc( 'yume/oeuvre-planning', array(), array( 'postId' => $vide ) ) );
			}
		);
	}
);

yume_tp_test(
	'bloc yume/team-dashboard : connexion requise, réservé à l’équipe',
	function () {
		$html = yume_render_block( 'yume/team-dashboard' );
		yume_assert_contains( 'yn-team--acces', $html );
		yume_assert_contains( 'Se connecter', $html );
		yume_assert_contains( 'wp-login.php', $html );
		wp_set_current_user( yume_tp_membre( 'subscriber', 'Kaede' ) );
		$html = yume_render_block( 'yume/team-dashboard' );
		yume_assert_contains( 'Espace réservé à l’équipe', $html );
		yume_assert_not_contains( 'data-yn-nonce', $html );
		wp_set_current_user( 0 );
	}
);

yume_tp_test(
	'bloc yume/team-dashboard (traducteur) : mes tâches avec formulaire, curseur, nonce ; ni gestion ni ajout',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				wp_set_current_user( $d['angeloids'] );
				$html = yume_render_block( 'yume/team-dashboard' );
				wp_set_current_user( 0 );
				yume_assert_contains( 'class="yn-team wp-block-yume-team-dashboard" id="yn-team"', $html );
				yume_assert_contains( 'data-yn-nonce="', $html );
				yume_assert_contains( 'Bonjour Angeloids', $html );
				yume_assert_contains( 'Jeudi 24 septembre 2026', $html );
				yume_assert_contains( 'id="yn-tache-' . $d['arc7'] . '"', $html );
				yume_assert_contains( 'id="yn-tache-' . $d['t10'] . '"', $html );
				yume_assert_contains( 'id="yn-tache-' . $d['sukamoka2'] . '"', $html );
				yume_assert_contains( 'id="yn-tache-' . $d['raven7'] . '"', $html, 'édition en attente de la traduction' );
				yume_assert_not_contains( 'id="yn-tache-' . $d['witches2'] . '"', $html );
				yume_assert_contains( 'En attente', $html );
				yume_assert_true( strpos( $html, 'id="yn-tache-' . $d['arc7'] . '"' ) < strpos( $html, 'id="yn-tache-' . $d['t10'] . '"' ), 'retards d’abord' );
				yume_assert_contains( 'yn-team__tache--retard', $html );
				yume_assert_contains( 'name="action" value="yume_planning_maj"', $html );
				yume_assert_contains( 'name="_yume_nonce"', $html );
				yume_assert_contains( 'type="range" id="t' . $d['t10'] . '-av-relecture" name="avancement[relecture]" min="0" max="100" step="1" value="62"', $html );
				yume_assert_contains( '<label for="t' . $d['t10'] . '-av-relecture">Avancement<span class="yn-visually-hidden"> relecture</span></label>', $html, 'étape lue par les lecteurs d’écran' );
				yume_assert_contains( 'role="status" aria-live="polite" data-yn-retour', $html );
				yume_assert_contains( 'value="Postface à relire"', $html, 'la note est visible par l’équipe' );
				yume_assert_contains( 'Nouvelle date', $html );
				yume_assert_contains( 'traduction terminée par Calumi', $html );
				yume_assert_contains( 'Mes tâches en cours', $html );
				yume_assert_contains( '2 relectures', $html );
				yume_assert_not_contains( 'id="yn-tous-les-tomes"', $html );
				yume_assert_not_contains( 'yume_planning_ajout', $html );
				yume_assert_not_contains( 'Publier un tome', $html );
				yume_assert_not_contains( 'name="responsables[', $html );
				yume_assert_contains( 'Journal de l’équipe', $html );
				yume_assert_contains( 'Rappels automatiques', $html );
			}
		);
	}
);

yume_tp_test(
	'bloc yume/team-dashboard (gérant) : tous les tomes, ajout au planning, publier, rappels récents',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				executer_rappels();
				wp_set_current_user( $d['gerant'] );
				$html = yume_render_block( 'yume/team-dashboard' );
				wp_set_current_user( 0 );
				yume_assert_contains( 'id="yn-tous-les-tomes"', $html );
				yume_assert_contains( 'id="yn-tome-' . $d['witches2'] . '"', $html );
				yume_assert_contains( 'name="responsables[relecture]"', $html );
				yume_assert_contains( 'name="action" value="yume_planning_ajout"', $html );
				yume_assert_contains( 'Ajouter un tome au planning', $html );
				yume_assert_contains( '<option value="' . $d['grimgar'] . '">Grimgar of Fantasy and Ash</option>', $html );
				yume_assert_contains( 'Publier un tome', $html );
				yume_assert_contains( '>Réglages</a>', $html );
				yume_assert_contains( esc_url( \Yume\Core\Planning\url_vue_equipe( 'reglages' ) ), $html );
				yume_assert_contains( 'Membres et rôles', $html );
				yume_assert_contains( 'Rappels ce mois', $html );
				yume_assert_contains( 'aucun relecteur assigné', $html );
				yume_assert_contains( '<b>Angeloids</b> · Secrets of the Silent Witch A.7', $html );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Formulaires sans JavaScript
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'formulaire sans JavaScript : nonce, droits, case « bloqué » décochée, ajout',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				wp_set_current_user( $d['angeloids'] );
				$nonce  = wp_create_nonce( 'yume_planning_maj_' . $d['t10'] );
				$retour = traiter_formulaire_maj(
					array(
						'tome_id'        => (string) $d['t10'],
						'ancre'          => 'yn-tache-' . $d['t10'],
						'_yume_nonce'    => $nonce,
						'avancement'     => array( 'relecture' => '80' ),
						'etape'          => 'relecture',
						'date_cible'     => yume_tp_jour( 3 ),
						'note_equipe'    => wp_slash( 'Presque fini \\o/ « ok »' ),
						'bloque_present' => '1',
						'bloque_raison'  => '',
					),
					$d['angeloids']
				);
				yume_assert_same( 'ok', $retour['type'] );
				yume_assert_same( 'yn-tache-' . $d['t10'], $retour['cible'] );
				yume_assert_same( 80, get_post_meta( $d['t10'], 'yume_avancement', true )['relecture'] );
				yume_assert_same( 'Presque fini \\o/ « ok »', get_post_meta( $d['t10'], 'yume_note_equipe', true ), 'barre oblique inverse conservée' );
				yume_assert_false( (bool) get_post_meta( $d['t10'], 'yume_bloque', true ) );

				$retour = traiter_formulaire_maj(
					array(
						'tome_id'     => (string) $d['t10'],
						'_yume_nonce' => 'faux',
						'etape'       => 'edition',
					),
					$d['angeloids']
				);
				yume_assert_same( 'erreur', $retour['type'] );
				yume_assert_same( 'relecture', get_post_meta( $d['t10'], 'yume_etape', true ) );

				$retour = traiter_formulaire_maj(
					array(
						'tome_id'     => (string) $d['witches2'],
						'_yume_nonce' => wp_create_nonce( 'yume_planning_maj_' . $d['witches2'] ),
						'etape'       => 'edition',
					),
					$d['angeloids']
				);
				yume_assert_same( 'erreur', $retour['type'], 'pas responsable' );

				wp_set_current_user( $d['editeur'] );
				$retour = traiter_formulaire_ajout(
					array(
						'_yume_nonce'  => wp_create_nonce( 'yume_planning_ajout' ),
						'oeuvre_id'    => (string) $d['raven'],
						'nature'       => 'tome',
						'numero'       => '8',
						'titre'        => '',
						'date_cible'   => '',
						'etape'        => 'traduction',
						'responsables' => array(
							'traduction' => (string) $d['calumi'],
							'relecture'  => '0',
							'edition'    => '0',
						),
					),
					$d['editeur']
				);
				wp_set_current_user( 0 );
				yume_assert_same( 'ok', $retour['type'] );
				yume_assert_same( 'traduction', get_post_meta( $retour['tome_id'], 'yume_etape', true ) );
				yume_assert_contains( 'Raven of the Inner Palace T.8 ajouté au planning', $retour['message'] );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Non-régression (revue)
 * -----------------------------------------------------------------------------
 */

yume_tp_test(
	'SEC-E-3 : /wp/v2/tomes ne contourne pas yume_maj_planning_tous (création, méta de planning, responsables)',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				yume_assert_false( user_can( $d['calumi'], get_post_type_object( 'yume_tome' )->cap->create_posts ), 'un traducteur ne crée pas de tome' );
				yume_assert_true( user_can( $d['editeur'], get_post_type_object( 'yume_tome' )->cap->create_posts ), 'un éditeur crée un tome' );

				$avant = count(
					get_posts(
						array(
							'post_type'   => 'yume_tome',
							'post_status' => 'any',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					)
				);
				$r     = yume_rest(
					'POST',
					'/wp/v2/tomes',
					array(
						'title'  => 'Grimgar of Fantasy and Ash — Tome 42',
						'status' => 'draft',
						'meta'   => array(
							'yume_oeuvre_id'    => $d['grimgar'],
							'yume_numero'       => 42,
							'yume_etape'        => 'edition',
							'yume_date_cible'   => yume_tp_jour( 5 ),
							'yume_responsables' => array(
								'traduction' => $d['calumi'],
								'relecture'  => $d['lecteur'],
								'edition'    => 0,
							),
						),
					),
					$d['calumi']
				);
				yume_assert_same( 403, $r->get_status(), 'création refusée au traducteur' );
				yume_assert_same(
					$avant,
					count(
						get_posts(
							array(
								'post_type'   => 'yume_tome',
								'post_status' => 'any',
								'numberposts' => -1,
								'fields'      => 'ids',
							)
						)
					)
				);
				yume_assert_not_contains( 'Tome 42', (string) wp_json_encode( yume_get_planning() ) );

				// Tome dont le traducteur est l'auteur (données anciennes) : méta de planning refusées.
				wp_update_post(
					array(
						'ID'          => $d['raven7'],
						'post_author' => $d['calumi'],
					)
				);
				foreach (
					array(
						'yume_responsables' => array(
							'traduction' => $d['calumi'],
							'relecture'  => $d['lecteur'],
							'edition'    => 0,
						),
						'yume_etape'        => 'publie',
						'yume_avancement'   => array(
							'traduction' => 100,
							'relecture'  => 100,
							'edition'    => 100,
						),
						'yume_date_cible'   => yume_tp_jour( 60 ),
						'yume_bloque'       => true,
					) as $cle => $valeur
				) {
					$r = yume_rest( 'POST', '/wp/v2/tomes/' . $d['raven7'], array( 'meta' => array( $cle => $valeur ) ), $d['calumi'] );
					yume_assert_same( 403, $r->get_status(), $cle . ' : ' . yume_test_export( $r->get_data() ) );
				}
				yume_assert_same( 'traduction', get_post_meta( $d['raven7'], 'yume_etape', true ) );
				yume_assert_same( 0, (int) get_post_meta( $d['raven7'], 'yume_responsables', true )['relecture'] );
				yume_assert_same( yume_tp_jour( 25 ), get_post_meta( $d['raven7'], 'yume_date_cible', true ) );
				yume_assert_false( user_can( $d['calumi'], 'edit_post_meta', $d['raven7'], 'yume_responsables' ) );
				// Les autres champs du tome restent modifiables par son auteur.
				yume_assert_same( 200, yume_rest( 'POST', '/wp/v2/tomes/' . $d['raven7'], array( 'excerpt' => 'Résumé' ), $d['calumi'] )->get_status() );

				// Éditeur : méta de planning permises, responsables limités à l'équipe.
				$r = yume_rest(
					'POST',
					'/wp/v2/tomes/' . $d['raven7'],
					array( 'meta' => array( 'yume_responsables' => array( 'relecture' => $d['lecteur'] ) ) ),
					$d['editeur']
				);
				yume_assert_same( 400, $r->get_status(), 'un lecteur n’est pas responsable' );
				yume_assert_same( 0, (int) get_post_meta( $d['raven7'], 'yume_responsables', true )['relecture'] );
				$r = yume_rest(
					'POST',
					'/wp/v2/tomes/' . $d['raven7'],
					array(
						'meta' => array(
							'yume_responsables' => array(
								'traduction' => $d['calumi'],
								'relecture'  => $d['angeloids'],
								'edition'    => $d['jojo'],
							),
						),
					),
					$d['editeur']
				);
				yume_assert_same( 200, $r->get_status(), yume_test_export( $r->get_data() ) );
				yume_assert_same( $d['angeloids'], (int) get_post_meta( $d['raven7'], 'yume_responsables', true )['relecture'] );
				wp_set_current_user( 0 );
			}
		);
	}
);

yume_tp_test(
	'MET-4 : l’étape « publié » suit la publication (tome non publié refusé, retour arrière réservé)',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				// Tome brouillon : ni le responsable ni l'éditeur ne le marquent publié à la main.
				$route = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				$r     = yume_rest( 'PATCH', $route, array( 'etape' => 'publie' ), $d['calumi'] );
				yume_assert_same( 400, $r->get_status() );
				yume_assert_same( 'yume_etape_publie_interdite', $r->get_data()['code'] );
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'etape' => 'publie' ), $d['editeur'] )->get_status() );
				yume_assert_same( 'relecture', get_post_meta( $d['t10'], 'yume_etape', true ) );
				yume_assert_same( array(), yume_tp_journal( $d['t10'] ) );
				yume_assert_false( array_key_exists( 'publie', \Yume\Core\Planning\etapes_proposees( $d['t10'], 'relecture', $d['calumi'] ) ), 'étape non proposée' );
				yume_assert_true( array_key_exists( 'edition', \Yume\Core\Planning\etapes_proposees( $d['t10'], 'relecture', $d['calumi'] ) ) );

				// Tome publié : un simple responsable ne le fait pas revenir dans les sorties à venir.
				update_post_meta(
					$d['t9'],
					'yume_responsables',
					array(
						'traduction' => $d['calumi'],
						'relecture'  => 0,
						'edition'    => 0,
					)
				);
				$route = '/yume/v1/tomes/' . $d['t9'] . '/planning';
				$r     = yume_rest( 'PATCH', $route, array( 'etape' => 'traduction' ), $d['calumi'] );
				yume_assert_same( 403, $r->get_status() );
				yume_assert_same( 'publie', get_post_meta( $d['t9'], 'yume_etape', true ) );
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'etape' => 'publie' ), $d['calumi'] )->get_status(), 'étape inchangée acceptée' );
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'avancement' => array( 'edition' => 100 ) ), $d['calumi'] )->get_status() );
				yume_assert_same( array( 'publie' => 'Publié' ), \Yume\Core\Planning\etapes_proposees( $d['t9'], 'publie', $d['calumi'] ) );
				yume_assert_not_contains( 'Tome 9', (string) wp_json_encode( yume_get_planning( array( 'a_venir' => true ) ) ) );

				// L'éditeur peut corriger, puis remettre « publié » sur le tome publié.
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'etape' => 'edition' ), $d['editeur'] )->get_status() );
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'etape' => 'publie' ), $d['editeur'] )->get_status() );
				yume_assert_same( 'publie', get_post_meta( $d['t9'], 'yume_etape', true ) );

				// Écriture système (publication) : toujours acceptée.
				$res = mettre_a_jour( $d['arc7'], array( 'etape' => 'publie' ), 0, array( 'forcer' => true ) );
				yume_assert_false( is_wp_error( $res ) );
				wp_set_current_user( 0 );
			}
		);
	}
);

yume_tp_test(
	'RC-6 : lignes du planning sans requête par tome (termes, chapitres, membres)',
	function () {
		yume_tp_a(
			function () {
				$d      = yume_tp_jeu();
				$mesure = static function (): int {
					wp_cache_flush();
					$avant = $GLOBALS['wpdb']->num_queries;
					yume_get_planning( array( 'public' => false ) );
					return $GLOBALS['wpdb']->num_queries - $avant;
				};
				$base   = $mesure();
				add_filter( 'yume_core_notifier', '__return_false' );
				try {
					for ( $i = 0; $i < 6; $i++ ) {
						$o = yume_tp_oeuvre( 'Série ' . $i, 0 === $i % 2 ? 'manga' : 'light-novel' );
						$u = yume_tp_membre( 'yume_traducteur', 'Membre ' . $i );
						$t = yume_tp_tome(
							$o,
							1,
							array(
								'yume_responsables' => array(
									'traduction' => $u,
									'relecture'  => $d['angeloids'],
									'edition'    => 0,
								),
								'yume_maj_par'      => $u,
								'yume_date_cible'   => yume_tp_jour( 5 + $i ),
							)
						);
						foreach ( array( 'publish', 'draft' ) as $statut ) {
							yume_factory_post(
								array(
									'post_type'   => 'yume_chapitre',
									'post_status' => $statut,
									'meta_input'  => array( 'yume_tome_id' => $t ),
								)
							);
						}
					}
				} finally {
					remove_filter( 'yume_core_notifier', '__return_false' );
				}
				$apres = $mesure();
				yume_assert_true( $apres - $base <= 3, sprintf( 'requêtes : %d pour 7 tomes, %d pour 13 tomes', $base, $apres ) );

				// Les comptes groupés valent ceux calculés tome par tome.
				foreach ( yume_get_planning( array( 'public' => false ) ) as $ligne ) {
					$chapitres = yume_get_chapitres( (int) $ligne['tome_id'], array( 'status' => 'any' ) );
					yume_assert_same( count( $chapitres ), $ligne['chapitres']['total'], 'total ' . $ligne['tome'] );
					yume_assert_same( count( array_filter( $chapitres, static fn( $c ) => 'publish' === $c->post_status ) ), $ligne['chapitres']['publies'], 'publiés ' . $ligne['tome'] );
				}
				yume_assert_same( array(), \Yume\Core\Planning\comptes_chapitres_amorces(), 'lot vidé après calcul' );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page « Membres et rôles » (yume/team-members)
 * -----------------------------------------------------------------------------
 */

/**
 * Crée la page « Membres et rôles » sous l'espace équipe et l'enregistre (option yume_pages),
 * comme après la migration.
 *
 * @return int ID de la page.
 */
function yume_tp_page_membres(): int {
	$equipe  = yume_factory_post(
		array(
			'post_type'   => 'page',
			'post_name'   => 'equipe',
			'post_title'  => 'Espace équipe',
			'post_status' => 'publish',
		)
	);
	$membres = yume_factory_post(
		array(
			'post_type'    => 'page',
			'post_name'    => 'membres',
			'post_title'   => 'Membres et rôles',
			'post_status'  => 'publish',
			'post_parent'  => $equipe,
			'post_content' => '<!-- wp:yume/team-members /-->',
		)
	);
	update_option(
		'yume_pages',
		array(
			'equipe'  => $equipe,
			'membres' => $membres,
		)
	);
	return $membres;
}

/**
 * Formulaire de la page « Membres et rôles », nonce compris.
 *
 * @param string $op     role, retrait ou ajout.
 * @param array  $champs Champs (user_id, role, compte).
 * @return array
 */
function yume_tp_post_membres( string $op, array $champs ): array {
	$nonce = 'ajout' === $op ? 'yume_membres_ajout' : 'yume_membres_' . (int) ( $champs['user_id'] ?? 0 );
	return array_merge(
		array(
			'action'      => 'yume_equipe_membres',
			'op'          => $op,
			'_yume_nonce' => wp_create_nonce( $nonce ),
		),
		array_map( 'strval', $champs )
	);
}

yume_tp_test(
	'bloc yume/team-members : connexion requise, réservé aux gérants (yume_gerer_equipe)',
	function () {
		$html = yume_render_block( 'yume/team-members' );
		yume_assert_contains( 'yn-team--acces', $html );
		yume_assert_contains( 'wp-login.php', $html );
		foreach ( array( 'subscriber', 'yume_traducteur', 'yume_editeur' ) as $role ) {
			wp_set_current_user( yume_tp_membre( $role, 'Kaede' ) );
			$html = yume_render_block( 'yume/team-members' );
			yume_assert_contains( 'Page réservée aux gérants', $html, $role );
			yume_assert_not_contains( 'yume_equipe_membres', $html, $role );
		}
		wp_set_current_user( 0 );
	}
);

yume_tp_test(
	'bloc yume/team-members (gérant) : membres et rôles, navigation partagée, administrateurs et gérants intouchables',
	function () {
		\Yume\Core\Core\installer_roles();
		$page   = yume_tp_page_membres();
		$gerant = yume_tp_membre( 'yume_gerant', 'Hikari' );
		$autre  = yume_tp_membre( 'yume_gerant', 'Sora' );
		$admin  = yume_tp_membre( 'administrator', 'Admin' );
		$trad   = yume_tp_membre( 'yume_traducteur', 'Calumi' );
		$lec    = yume_tp_membre( 'subscriber', 'Kaede' );
		wp_set_current_user( $gerant );
		$html = yume_render_block( 'yume/team-members' );
		// Vues ajoutées par le filtre yume_vues_equipe (« Indicateurs »…), placées avant « Réglages ».
		$ajoutees = array_column( \Yume\Core\Planning\vues_equipe_ajoutees(), 'libelle' );
		wp_set_current_user( 0 );
		yume_assert_contains( 'class="yn-team yn-team--membres wp-block-yume-team-members" id="yn-team"', $html );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Membres et rôles</h2>', $html );

		// Navigation : celle du tableau de bord, « Membres et rôles » en page courante.
		preg_match( '#<nav class="yn-team__nav".*?</nav>#s', $html, $m );
		yume_assert_true( ! empty( $m[0] ), 'navigation présente' );
		preg_match_all( '#<li><a href="([^"]*)"([^>]*)>([^<]*)#', $m[0], $liens, PREG_SET_ORDER );
		$libelles = array_map( static fn( $l ) => html_entity_decode( trim( $l[3] ), ENT_QUOTES, 'UTF-8' ), $liens );
		yume_assert_same( array_merge( array( 'Tableau de bord', 'Mes tâches', 'Publier un tome', 'Lecture à compléter', 'Tous les tomes', 'Œuvres', 'Planning complet', 'Journal', 'Membres et rôles' ), $ajoutees, array( 'Réglages' ) ), $libelles );
		$equipe = esc_url( yume_url_page( 'equipe' ) );
		yume_assert_same( $equipe, $liens[0][1] );
		yume_assert_same( esc_url( \Yume\Core\Planning\url_vue_equipe( 'taches' ) ), $liens[1][1] );
		yume_assert_same( esc_url( get_permalink( $page ) ), $liens[8][1] );
		yume_assert_same( ' aria-current="page"', $liens[8][2] );
		yume_assert_same( 1, substr_count( $m[0], 'aria-current' ) );

		// Membres : chacun avec son rôle ; lecteur absent de la liste.
		yume_assert_contains( 'id="yn-membre-' . $trad . '"', $html );
		yume_assert_contains( 'id="yn-membre-' . $admin . '"', $html, 'l’administrateur fait partie de l’équipe' );
		yume_assert_not_contains( 'id="yn-membre-' . $lec . '"', $html );
		yume_assert_contains( '>Traducteur</span>', $html );
		yume_assert_contains( '>Gérant</span>', $html );

		// Formulaires : seulement pour les comptes modifiables.
		yume_assert_contains( 'name="user_id" value="' . $trad . '"', $html );
		foreach ( array( $admin, $autre, $gerant ) as $id ) {
			yume_assert_not_contains( 'name="user_id" value="' . $id . '"', $html, "compte $id non modifiable" );
		}
		yume_assert_contains( 'Votre compte : votre rôle ne se change pas ici.', $html );
		yume_assert_contains( 'Administrateur ou gérant : non modifiable depuis l’espace équipe.', $html );
		yume_assert_contains( 'name="action" value="yume_equipe_membres"', $html );
		yume_assert_contains( 'name="_yume_nonce"', $html );
		yume_assert_contains( '<option value="yume_traducteur" selected=\'selected\'>Traducteur</option>', $html );
		yume_assert_contains( '<option value="yume_editeur">Éditeur Yume</option>', $html );
		yume_assert_not_contains( '<option value="yume_gerant"', $html );
		yume_assert_not_contains( '<option value="administrator"', $html );
		yume_assert_contains( 'name="op" value="retrait"', $html );
		yume_assert_contains( 'id="yn-ajouter-membre-form"', $html );
		yume_assert_contains( 'name="compte"', $html );

		// Tableau de bord : le lien « Membres et rôles » mène à la page.
		wp_set_current_user( $gerant );
		$tableau = yume_render_block( 'yume/team-dashboard' );
		wp_set_current_user( 0 );
		yume_assert_contains( '<a href="' . esc_url( get_permalink( $page ) ) . '">Membres et rôles</a>', $tableau );
		yume_assert_not_contains( 'users.php', $tableau );
	}
);

yume_tp_test(
	'lien « Membres et rôles » : page de l’espace équipe, sinon users.php (page absente), rien pour un traducteur',
	function () {
		delete_option( 'yume_pages' );
		$gerant = yume_tp_membre( 'yume_gerant', 'Hikari' );
		wp_set_current_user( $gerant );
		yume_assert_same( admin_url( 'users.php' ), url_membres() );
		yume_assert_contains( '<a href="' . esc_url( admin_url( 'users.php' ) ) . '">Membres et rôles</a>', yume_render_block( 'yume/team-dashboard' ) );
		$page = yume_tp_page_membres();
		yume_assert_same( get_permalink( $page ), url_membres() );
		yume_assert_same( home_url( '/equipe/membres/' ), yume_url_page( 'membres' ) );
		wp_set_current_user( yume_tp_membre( 'yume_traducteur', 'Calumi' ) );
		yume_assert_same( '', url_membres() );
		yume_assert_not_contains( 'Membres et rôles', yume_render_block( 'yume/team-dashboard' ) );
		wp_set_current_user( 0 );
	}
);

yume_tp_test(
	'formulaire « Membres et rôles » : changer le rôle, ajouter un lecteur (identifiant ou e-mail), retirer de l’équipe',
	function () {
		\Yume\Core\Core\installer_roles();
		$gerant = yume_tp_membre( 'yume_gerant', 'Hikari' );
		$trad   = yume_tp_membre( 'yume_traducteur', 'Calumi' );
		$lec    = yume_tp_membre( 'subscriber', 'Kaede' );
		$lec2   = yume_tp_membre( 'subscriber', 'Mio' );
		wp_set_current_user( $gerant );

		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'role',
				array(
					'user_id' => $trad,
					'role'    => 'yume_relecteur',
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 'yn-membre-' . $trad, $r['cible'] );
		yume_assert_same( 'Rôle de Calumi : Relecteur.', $r['message'] );
		yume_assert_same( array( 'yume_relecteur' ), array_values( get_userdata( $trad )->roles ) );

		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'ajout',
				array(
					'compte' => get_userdata( $lec )->user_login,
					'role'   => 'yume_graphiste',
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 'Kaede rejoint l’équipe : Graphiste.', $r['message'] );
		yume_assert_same( array( 'yume_graphiste' ), array_values( get_userdata( $lec )->roles ) );
		yume_assert_true( user_can( $lec, 'yume_voir_equipe' ) );

		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'ajout',
				array(
					'compte' => wp_slash( ' ' . get_userdata( $lec2 )->user_email . ' ' ),
					'role'   => 'yume_editeur',
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( array( 'yume_editeur' ), array_values( get_userdata( $lec2 )->roles ) );

		// Déjà membre, compte inconnu.
		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'ajout',
				array(
					'compte' => get_userdata( $lec )->user_login,
					'role'   => 'yume_traducteur',
				)
			),
			$gerant
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_contains( 'fait déjà partie de l’équipe', $r['message'] );
		yume_assert_same( 'yn-ajouter-membre-form', $r['cible'] );
		$r = traiter_formulaire_membres( yume_tp_post_membres( 'ajout', array( 'compte' => 'personne-inconnue' ) ), $gerant );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_contains( 'Aucun compte', $r['message'] );

		$r = traiter_formulaire_membres( yume_tp_post_membres( 'retrait', array( 'user_id' => $trad ) ), $gerant );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 'yn-membres', $r['cible'] );
		yume_assert_same( array( 'subscriber' ), array_values( get_userdata( $trad )->roles ) );
		yume_assert_false( user_can( $trad, 'yume_voir_equipe' ) );
		wp_set_current_user( 0 );
	}
);

yume_tp_test(
	'formulaire « Membres et rôles » : refus (nonce, non-gérant, administrateur, autre gérant, soi-même, rôle interdit)',
	function () {
		\Yume\Core\Core\installer_roles();
		$gerant  = yume_tp_membre( 'yume_gerant', 'Hikari' );
		$autre   = yume_tp_membre( 'yume_gerant', 'Sora' );
		$admin   = yume_tp_membre( 'administrator', 'Admin' );
		$editeur = yume_tp_membre( 'yume_editeur', 'Rin' );
		$trad    = yume_tp_membre( 'yume_traducteur', 'Calumi' );
		$lec     = yume_tp_membre( 'subscriber', 'Kaede' );

		// Nonce absent ou d'un autre compte.
		wp_set_current_user( $gerant );
		$post                = yume_tp_post_membres(
			'role',
			array(
				'user_id' => $trad,
				'role'    => 'yume_relecteur',
			)
		);
		$post['_yume_nonce'] = wp_create_nonce( 'yume_membres_' . $lec );
		$r                   = traiter_formulaire_membres( $post, $gerant );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_contains( 'session a expiré', $r['message'] );
		$r = traiter_formulaire_membres( array( 'op' => 'retrait' ), $gerant );
		yume_assert_same( 'erreur', $r['type'] );

		// Rôles hors équipe : gérant, administrateur, rôle WordPress.
		foreach ( array( 'yume_gerant', 'administrator', 'editor', 'inconnu' ) as $role ) {
			$r = traiter_formulaire_membres(
				yume_tp_post_membres(
					'role',
					array(
						'user_id' => $trad,
						'role'    => $role,
					)
				),
				$gerant
			);
			yume_assert_same( 'erreur', $r['type'], $role );
		}
		yume_assert_same( array( 'yume_traducteur' ), array_values( get_userdata( $trad )->roles ) );

		// Comptes intouchables : administrateur, autre gérant, soi-même.
		foreach ( array( $admin, $autre, $gerant ) as $cible ) {
			foreach ( array( 'role', 'retrait' ) as $op ) {
				$r = traiter_formulaire_membres(
					yume_tp_post_membres(
						$op,
						array(
							'user_id' => $cible,
							'role'    => 'yume_traducteur',
						)
					),
					$gerant
				);
				yume_assert_same( 'erreur', $r['type'], "$op $cible" );
			}
		}
		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'ajout',
				array(
					'compte' => get_userdata( $admin )->user_login,
					'role'   => 'yume_traducteur',
				)
			),
			$gerant
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_true( in_array( 'administrator', get_userdata( $admin )->roles, true ) );
		yume_assert_same( array( 'yume_gerant' ), array_values( get_userdata( $autre )->roles ) );
		yume_assert_same( array( 'yume_gerant' ), array_values( get_userdata( $gerant )->roles ) );

		// Un administrateur ne promeut pas un gérant depuis cette page (administration seulement).
		wp_set_current_user( $admin );
		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'role',
				array(
					'user_id' => $trad,
					'role'    => 'yume_gerant',
				)
			),
			$admin
		);
		yume_assert_same( 'erreur', $r['type'] );
		$r = traiter_formulaire_membres( yume_tp_post_membres( 'retrait', array( 'user_id' => $autre ) ), $admin );
		yume_assert_same( 'erreur', $r['type'] );

		// Un éditeur (sans yume_gerer_equipe) ne gère pas les membres.
		wp_set_current_user( $editeur );
		$r = traiter_formulaire_membres(
			yume_tp_post_membres(
				'ajout',
				array(
					'compte' => get_userdata( $lec )->user_login,
					'role'   => 'yume_traducteur',
				)
			),
			$editeur
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( array( 'subscriber' ), array_values( get_userdata( $lec )->roles ) );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'étape : la relecture et l’édition exigent les étapes précédentes à 100 %, pour tout le monde',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$route = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				update_post_meta( $d['t10'], 'yume_etape', 'traduction' );
				update_post_meta(
					$d['t10'],
					'yume_avancement',
					array(
						'traduction' => 70,
						'relecture'  => 0,
						'edition'    => 0,
					)
				);

				// Traduction à 70 % : ni le responsable ni l'éditeur ne passent à la relecture.
				foreach ( array( $d['calumi'], $d['editeur'] ) as $uid ) {
					$r = yume_rest( 'PATCH', $route, array( 'etape' => 'relecture' ), $uid );
					yume_assert_same( 400, $r->get_status() );
					yume_assert_same( 'yume_etape_prematuree', $r->get_data()['code'] );
				}
				yume_assert_same( 'traduction', get_post_meta( $d['t10'], 'yume_etape', true ) );

				// Même saisie : traduction terminée et relecture demandée ensemble → accepté.
				$r = yume_rest(
					'PATCH',
					$route,
					array(
						'avancement' => array( 'traduction' => 100 ),
						'etape'      => 'relecture',
					),
					$d['editeur']
				);
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( 'relecture', get_post_meta( $d['t10'], 'yume_etape', true ) );

				// Édition : relecture à 0 % → refusée ; retour en arrière toujours possible.
				yume_assert_same( 400, yume_rest( 'PATCH', $route, array( 'etape' => 'edition' ), $d['editeur'] )->get_status() );
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'etape' => 'traduction' ), $d['editeur'] )->get_status() );

				// Le formulaire propose l'étape suivante (l'avancement est contrôlé à l'envoi).
				yume_assert_true( array_key_exists( 'relecture', \Yume\Core\Planning\etapes_proposees( $d['t10'], 'traduction', $d['calumi'] ) ) );
			}
		);
	}
);

yume_tp_test(
	'SCAN-02 : administrateur et gérant forcent une étape sans 100 % (journal « étape forcée ») ; jamais « publié » sur un tome non publié',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$admin = yume_tp_membre( 'administrator', 'Admin' );
				$route = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				yume_assert_true( \Yume\Core\Planning\peut_forcer_etape( $admin ) );
				yume_assert_true( \Yume\Core\Planning\peut_forcer_etape( $d['gerant'] ) );
				yume_assert_false( \Yume\Core\Planning\peut_forcer_etape( $d['editeur'] ) );
				yume_assert_false( \Yume\Core\Planning\peut_forcer_etape( $d['calumi'] ) );
				yume_assert_false( \Yume\Core\Planning\peut_forcer_etape( 0 ) );

				// Relecture à 62 % : l'éditeur ne passe pas à l'édition, le gérant si.
				$r = yume_rest( 'PATCH', $route, array( 'etape' => 'edition' ), $d['editeur'] );
				yume_assert_same( 'yume_etape_prematuree', $r->get_data()['code'] );
				$r = yume_rest( 'PATCH', $route, array( 'etape' => 'edition' ), $d['gerant'] );
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( 'edition', get_post_meta( $d['t10'], 'yume_etape', true ) );
				yume_assert_same( 62, get_post_meta( $d['t10'], 'yume_avancement', true )['relecture'], 'avancement conservé' );
				$lignes = array_values(
					array_filter(
						yume_tp_journal( $d['t10'] ),
						static function ( $l ) {
							return 'etape_forcee' === $l->champ;
						}
					)
				);
				yume_assert_same( 1, count( $lignes ) );
				yume_assert_same( '0', (string) $lignes[0]->public, 'étape forcée : journal de l’équipe seulement' );
				yume_assert_same( 'relecture', $lignes[0]->ancien );
				yume_assert_same( (int) $d['gerant'], (int) $lignes[0]->user_id );

				// Administrateur : depuis la traduction à 48 %, passage direct à l'édition.
				$r = yume_rest( 'PATCH', '/yume/v1/tomes/' . $d['raven7'] . '/planning', array( 'etape' => 'edition' ), $admin );
				yume_assert_same( 200, $r->get_status() );
				yume_assert_true( \Yume\Core\Planning\est_etape_forcee( $d['raven7'], 'traduction', 'relecture', $admin ) );
				yume_assert_false( \Yume\Core\Planning\est_etape_forcee( $d['raven7'], 'traduction', 'relecture', $d['editeur'] ) );

				// « Publié » reste réservé aux tomes publiés, même pour un administrateur.
				$r = yume_rest( 'PATCH', $route, array( 'etape' => 'publie' ), $admin );
				yume_assert_same( 400, $r->get_status() );
				yume_assert_same( 'yume_etape_publie_interdite', $r->get_data()['code'] );
				yume_assert_same( 'edition', get_post_meta( $d['t10'], 'yume_etape', true ) );
			}
		);
	}
);

yume_tp_test(
	'SCAN-09 : un responsable ne modifie que l’avancement de son étape (403 explicite) ; éditeur et gérant libres',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$route = '/yume/v1/tomes/' . $d['t10'] . '/planning';
				// Calumi (traduction) ne touche pas à la relecture.
				$r = yume_rest( 'PATCH', $route, array( 'avancement' => array( 'relecture' => 90 ) ), $d['calumi'] );
				yume_assert_same( 403, $r->get_status() );
				yume_assert_same( 'yume_avancement_interdit', $r->get_data()['code'] );
				yume_assert_contains( 'relecture', $r->get_data()['message'] );
				yume_assert_same( 62, get_post_meta( $d['t10'], 'yume_avancement', true )['relecture'] );
				// Angeloids (relecture) : les trois curseurs renvoyés, seule sa valeur change.
				$r = yume_rest(
					'PATCH',
					$route,
					array(
						'avancement' => array(
							'traduction' => 100,
							'relecture'  => 80,
							'edition'    => 0,
						),
					),
					$d['angeloids']
				);
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( 80, get_post_meta( $d['t10'], 'yume_avancement', true )['relecture'] );
				// Angeloids ne touche pas à l'édition (JojoGg).
				$r = yume_rest( 'PATCH', $route, array( 'avancement' => array( 'edition' => 10 ) ), $d['angeloids'] );
				yume_assert_same( 403, $r->get_status() );
				// Éditeur et gérant : toutes les étapes.
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'avancement' => array( 'edition' => 10 ) ), $d['editeur'] )->get_status() );
				yume_assert_same( 200, yume_rest( 'PATCH', $route, array( 'avancement' => array( 'traduction' => 95 ) ), $d['gerant'] )->get_status() );
				yume_assert_same(
					array(
						'traduction' => 95,
						'relecture'  => 80,
						'edition'    => 10,
					),
					get_post_meta( $d['t10'], 'yume_avancement', true )
				);
			}
		);
	}
);

yume_tp_test(
	'SCAN-04 : dépublier un tome le ramène à l’édition (avancements gardés, journal, dernière sortie) ; le republier rétablit « publié »',
	function () {
		$o    = yume_tp_oeuvre( 'Raven of the Inner Palace' );
		$tome = yume_tp_tome(
			$o,
			7,
			array(
				'yume_etape'        => 'edition',
				'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		wp_update_post(
			array(
				'ID'          => $tome,
				'post_status' => 'publish',
			)
		);
		yume_assert_same( 'publie', get_post_meta( $tome, 'yume_etape', true ) );
		yume_assert_true( '' !== (string) get_post_meta( $o, 'yume_derniere_sortie', true ), 'dernière sortie posée' );

		wp_update_post(
			array(
				'ID'          => $tome,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( 'edition', get_post_meta( $tome, 'yume_etape', true ) );
		yume_assert_same( 100, get_post_meta( $tome, 'yume_avancement', true )['edition'], 'avancements conservés' );
		yume_assert_same( '', (string) get_post_meta( $o, 'yume_derniere_sortie', true ), 'dernière sortie recalculée' );
		$champs = array_column( yume_tp_journal( $tome ), 'champ' );
		yume_assert_true( in_array( 'depublie', $champs, true ) );
		$ids = wp_list_pluck(
			yume_get_planning(
				array(
					'a_venir' => true,
					'public'  => false,
				)
			),
			'tome_id'
		);
		yume_assert_true( in_array( $tome, $ids, true ), 'de retour parmi les tomes en cours' );

		// Retour en ligne : yume_tome_publie n'est émis qu'une fois, la sortie est rétablie quand même.
		wp_update_post(
			array(
				'ID'          => $tome,
				'post_status' => 'publish',
			)
		);
		yume_assert_same( 'publie', get_post_meta( $tome, 'yume_etape', true ) );
		yume_assert_same( '', (string) get_post_meta( $tome, '_yume_planning_depublie', true ) );
		$journal = yume_tp_journal( $tome );
		$dernier = end( $journal );
		yume_assert_same( 'publie', $dernier->champ );
		yume_assert_contains( '"retour":true', (string) $dernier->nouveau );
		yume_assert_true( '' !== (string) get_post_meta( $o, 'yume_derniere_sortie', true ) );
	}
);

yume_tp_test(
	'SCAN-05 : un tome programmé suit sa date programmée, « Programmé le … », jamais en retard',
	function () {
		$o    = yume_tp_oeuvre( 'Grimgar of Fantasy and Ash' );
		$tome = yume_tp_tome(
			$o,
			11,
			array(
				'yume_etape'        => 'edition',
				'yume_date_cible'   => gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ),
				'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ),
			)
		);
		yume_assert_same( 'en_retard', yume_planning_etat( $tome ) );
		$sortie = time() + 6 * DAY_IN_SECONDS;
		wp_update_post(
			array(
				'ID'            => $tome,
				'post_status'   => 'future',
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $sortie ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $sortie ),
				'edit_date'     => true,
			)
		);
		yume_assert_same( 'future', get_post_status( $tome ) );
		$jour = \Yume\Core\Planning\date_locale( $sortie );
		yume_assert_same( $jour, \Yume\Core\Planning\date_programmee( $tome ) );
		yume_assert_same( $jour, get_post_meta( $tome, 'yume_date_cible', true ), 'date cible alignée' );
		yume_assert_true( in_array( 'date_cible', array_column( yume_tp_journal( $tome ), 'champ' ), true ), 'alignement journalisé' );
		yume_assert_same( 'a_lheure', yume_planning_etat( $tome ) );
		yume_assert_same( 'programme', analyser_etat( $tome )['motif'] );

		// Même avec une date cible périmée (écrite à la main) : programmé, jamais en retard.
		update_post_meta( $tome, 'yume_date_cible', gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) );
		yume_assert_same( 'a_lheure', yume_planning_etat( $tome ) );
		$ligne = yume_get_planning( array( 'oeuvre_id' => $o ) )[0];
		yume_assert_true( $ligne['programme'] );
		yume_assert_same( $jour, $ligne['date_cible'] );
		$public = \Yume\Core\Planning\ligne_publique( $ligne );
		yume_assert_contains( 'Programmé le ', $public['etat_libelle'] );
		yume_assert_same( 'a_lheure', $public['etat'] );
		$r = yume_rest( 'GET', '/yume/v1/planning', array( 'oeuvre' => $o ) );
		yume_assert_true( $r->get_data()[0]['programme'] );
		yume_assert_same( $jour, $r->get_data()[0]['date_programmee'] );
		yume_assert_contains( 'Programmé le ', $r->get_data()[0]['etat_libelle'] );
		yume_assert_same( 0, count( executer_rappels()['rappels'] ), 'aucun rappel pour une sortie programmée' );
	}
);

yume_tp_test(
	'SCAN-08 : un responsable retiré de l’équipe ne reçoit plus de rappel et compte comme manquant',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$ligne = yume_get_planning(
					array(
						'oeuvre_id' => $d['sw'],
						'public'    => false,
					)
				)[0];
				yume_assert_same( array( $d['angeloids'] ), \Yume\Core\Planning\destinataires_rappel( $ligne ) );
				yume_assert_same( array( 'edition' ), \Yume\Core\Planning\roles_manquants( $ligne ) );
				( new WP_User( $d['angeloids'] ) )->set_role( 'subscriber' );
				yume_assert_false( in_array( $d['angeloids'], \Yume\Core\Planning\destinataires_rappel( $ligne ), true ) );
				yume_assert_same( array( 'relecture', 'edition' ), \Yume\Core\Planning\roles_manquants( $ligne ) );
				$rapport = executer_rappels();
				foreach ( $rapport['rappels'] as $rappel ) {
					yume_assert_false( in_array( $d['angeloids'], $rappel['destinataires'], true ), 'ancien membre sans rappel' );
				}
			}
		);
	}
);

yume_tp_test(
	'SCAN-10 : un arc publié chapitre par chapitre garde son étape jusqu’au dernier chapitre',
	function () {
		$o    = yume_tp_oeuvre( 'Secrets of the Silent Witch', 'web-novel' );
		$arc  = yume_tp_tome(
			$o,
			8,
			array(
				'yume_etape'        => 'relecture',
				'yume_avancement'   => array(
					'traduction' => 100,
					'relecture'  => 40,
					'edition'    => 0,
				),
				'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
			),
			'publish',
			'arc'
		);
		$chap = array();
		foreach ( array( 'publish', 'future', 'draft' ) as $i => $statut ) {
			$date       = 'future' === $statut ? gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) : gmdate( 'Y-m-d H:i:s' );
			$chap[ $i ] = yume_factory_post(
				array(
					'post_type'     => 'yume_chapitre',
					'post_title'    => 'Chapitre ' . ( $i + 1 ),
					'post_status'   => $statut,
					'post_date'     => get_date_from_gmt( $date ),
					'post_date_gmt' => $date,
					'meta_input'    => array(
						'yume_tome_id' => $arc,
						'yume_numero'  => $i + 1,
						'yume_nature'  => 'chapitre',
					),
				)
			);
		}
		do_action( 'yume_tome_publie', $arc );
		yume_assert_same( 'relecture', get_post_meta( $arc, 'yume_etape', true ), 'étape gardée' );
		yume_assert_same( 40, get_post_meta( $arc, 'yume_avancement', true )['relecture'] );
		$journal = yume_tp_journal( $arc );
		$dernier = end( $journal );
		yume_assert_same( 'publie', $dernier->champ );
		yume_assert_contains( '"partiel":true', (string) $dernier->nouveau );
		$ids = wp_list_pluck(
			yume_get_planning(
				array(
					'a_venir' => true,
					'public'  => false,
				)
			),
			'tome_id'
		);
		yume_assert_true( in_array( $arc, $ids, true ), 'reste dans les listes de l’équipe' );

		wp_update_post(
			array(
				'ID'          => $chap[2],
				'post_status' => 'publish',
			)
		);
		yume_assert_same( 'relecture', get_post_meta( $arc, 'yume_etape', true ), 'un chapitre programmé reste' );
		wp_publish_post( $chap[1] );
		yume_assert_same( 'publie', get_post_meta( $arc, 'yume_etape', true ), 'dernier chapitre sorti' );
		yume_assert_same( 100, get_post_meta( $arc, 'yume_avancement', true )['edition'] );
		yume_assert_same( '', (string) get_post_meta( $arc, '_yume_planning_sortie_partielle', true ) );

		// Tome classique sans chapitre en attente : « publié » tout de suite.
		$tome = yume_tp_tome( $o, 9, array(), 'publish' );
		do_action( 'yume_tome_publie', $tome );
		yume_assert_same( 'publie', get_post_meta( $tome, 'yume_etape', true ) );
	}
);

yume_tp_test(
	'yume_get_planning( gestion ) : tous les tomes vivants, filtres œuvre, statut, état',
	function () {
		yume_tp_a(
			function () {
				$d     = yume_tp_jeu();
				$vieux = yume_tp_tome(
					$d['grimgar'],
					3,
					array(
						'yume_etape'        => 'publie',
						'yume_derniere_maj' => yume_tp_gmt( -400 ),
					),
					'publish'
				);
				wp_update_post(
					array(
						'ID'            => $vieux,
						'post_date'     => '2024-01-01 10:00:00',
						'post_date_gmt' => '2024-01-01 09:00:00',
					)
				);
				$prive = yume_tp_tome( $d['raven'], 1, array(), 'private' );
				$cache = yume_tp_oeuvre( 'Œuvre en brouillon', 'light-novel', 'draft' );
				$autre = yume_tp_tome( $cache, 1 );
				$ids   = wp_list_pluck( yume_get_planning( array( 'gestion' => true ) ), 'tome_id' );
				foreach ( array( $d['t10'], $d['arc7'], $d['raven7'], $d['witches2'], $d['t9'], $d['sukamoka2'], $vieux, $prive, $autre ) as $id ) {
					yume_assert_true( in_array( $id, $ids, true ), 'tome ' . $id );
				}
				yume_assert_false( in_array( $vieux, wp_list_pluck( yume_get_planning( array( 'public' => false ) ), 'tome_id' ), true ), 'vue normale inchangée' );
				$ligne = yume_get_planning(
					array(
						'gestion'   => true,
						'oeuvre_id' => $d['grimgar'],
						'statut'    => 'publish',
					)
				);
				yume_assert_same( array( $d['t9'], $vieux ), wp_list_pluck( $ligne, 'tome_id' ) );
				yume_assert_same( 'publish', $ligne[0]['statut'] );
				yume_assert_same(
					array( $d['witches2'] ),
					wp_list_pluck(
						yume_get_planning(
							array(
								'gestion' => true,
								'etat'    => 'bloque',
							)
						),
						'tome_id'
					)
				);
				yume_assert_same(
					array(),
					yume_get_planning(
						array(
							'gestion' => true,
							'statut'  => 'trash',
						)
					)
				);
			}
		);
	}
);

yume_tp_test(
	'retirer_tome et DELETE /tomes/{id}/planning : brouillon sans chapitre publié seulement, droits, journal',
	function () {
		yume_tp_a(
			function () {
				$d = yume_tp_jeu();
				// Droits.
				$e = \Yume\Core\Planning\retirer_tome( $d['t10'], $d['calumi'] );
				yume_assert_same( 403, $e->get_error_data()['status'] );
				yume_assert_same( 401, yume_rest( 'DELETE', '/yume/v1/tomes/' . $d['t10'] . '/planning' )->get_status() );
				yume_assert_same( 403, yume_rest( 'DELETE', '/yume/v1/tomes/' . $d['t10'] . '/planning', array(), $d['calumi'] )->get_status() );
				// Publié, programmé, chapitre publié : refus expliqué.
				$e = \Yume\Core\Planning\retirer_tome( $d['t9'], $d['editeur'] );
				yume_assert_same( 409, $e->get_error_data()['status'] );
				yume_assert_contains( 'administration', $e->get_error_message() );
				yume_factory_post(
					array(
						'post_type'   => 'yume_chapitre',
						'post_title'  => 'Chapitre 1',
						'post_status' => 'publish',
						'meta_input'  => array( 'yume_tome_id' => $d['arc7'] ),
					)
				);
				$r = yume_rest( 'DELETE', '/yume/v1/tomes/' . $d['arc7'] . '/planning', array(), $d['gerant'] );
				yume_assert_same( 409, $r->get_status() );
				yume_assert_contains( 'chapitre publié', $r->get_data()['message'] );
				yume_assert_same( 'draft', get_post_status( $d['arc7'] ) );
				// Brouillon sans chapitre publié : corbeille + journal.
				$r = yume_rest( 'DELETE', '/yume/v1/tomes/' . $d['t10'] . '/planning', array(), $d['editeur'] );
				yume_assert_same( 200, $r->get_status() );
				yume_assert_contains( 'retiré du planning', $r->get_data()['message'] );
				yume_assert_same( 'trash', get_post_status( $d['t10'] ) );
				yume_assert_true( in_array( 'retire', array_column( yume_tp_journal( $d['t10'] ), 'champ' ), true ) );
				yume_assert_false( in_array( $d['t10'], wp_list_pluck( yume_get_planning( array( 'gestion' => true ) ), 'tome_id' ), true ) );
				yume_assert_same( 404, yume_rest( 'DELETE', '/yume/v1/tomes/' . $d['t10'] . '/planning', array(), $d['editeur'] )->get_status() );
			}
		);
	}
);

yume_test(
	'BUG-01 : le lien du tome dans une entrée du journal est souligné (pas distingué par la couleur seule)',
	function () {
		$entree = array(
			'ts'      => time(),
			'auteur'  => 'Calumi',
			'cible'   => 'Tome de test T.1',
			'tome_id' => 42,
			'parties' => array(),
			'publie'  => false,
		);
		$html   = \Yume\Core\Planning\liste_journal( array( $entree ), 'yn-team__journal yn-journal', false, static fn( int $id ) => 'https://exemple.test/?tome=' . $id );
		yume_assert_contains( '<span class="yn-journal__texte"><b>Calumi</b> · <a href="https://exemple.test/?tome=42">Tome de test T.1</a>', $html );

		// Les deux feuilles qui affichent le journal soulignent ce lien (WCAG 1.4.1).
		$feuilles = array(
			'team-dashboard' => '.yn-team',
			'planning'       => '.yn-planning',
		);
		foreach ( $feuilles as $bloc => $parent ) {
			$css = (string) file_get_contents( YUME_CORE_DIR . 'includes/planning/blocks/' . $bloc . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			yume_assert_true( (bool) preg_match( '/' . preg_quote( $parent, '/' ) . ' \.yn-journal__texte a \{\s*text-decoration: underline;/', $css ), $bloc );
		}
	}
);

yume_test(
	'Vues ajoutées à l’espace équipe (yume_vues_equipe) : entrée de navigation, ?vue= et capacité respectées',
	function () {
		$ajout = static function ( array $vues ): array {
			$vues['essai']    = array(
				'libelle'  => 'Vue d’essai',
				'capacite' => 'yume_voir_equipe',
				'rendu'    => static fn(): string => '<p>rendu-essai</p>',
			);
			$vues['reservee'] = array(
				'libelle'  => 'Réservée',
				'capacite' => 'manage_options',
				'rendu'    => static fn(): string => '<p>rendu-reserve</p>',
			);
			$vues['journal']  = array(
				'libelle' => 'Écrase le journal',
				'rendu'   => static fn(): string => 'non',
			);
			return $vues;
		};
		add_filter( 'yume_vues_equipe', $ajout );
		$avant = get_current_user_id();
		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		try {
			$vues = \Yume\Core\Planning\vues_equipe_ajoutees();
			yume_assert_same( array( 'essai' ), array_keys( $vues ) );
			$nav = \Yume\Core\Planning\navigation_equipe( 'essai' );
			yume_assert_contains( 'Vue d’essai', $nav );
			yume_assert_contains( 'vue=essai" aria-current="page"', $nav );
			yume_assert_not_contains( 'Réservée', $nav );
			$_GET['vue'] = 'essai';
			yume_assert_same( 'essai', \Yume\Core\Planning\vue_equipe() );
			$_GET['vue'] = 'reservee';
			yume_assert_same( '', \Yume\Core\Planning\vue_equipe() );
		} finally {
			unset( $_GET['vue'] );
			remove_filter( 'yume_vues_equipe', $ajout );
			wp_set_current_user( $avant );
		}
	}
);
