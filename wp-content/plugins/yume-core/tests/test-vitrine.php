<?php
/**
 * Tests de la vitrine du planning (includes/planning/vitrine.php) : prochain tome à la une, file
 * des chapitres, tomes en préparation, bloc yume/planning-accueil, gabarit de l'accueil et page
 * Planning (bandeau à la une, vue « Chapitres »).
 *
 * Lancement : tools/localenv/test.sh vitrine
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\fuseau;
use function Yume\Core\Planning\file_chapitres;
use function Yume\Core\Planning\prochain_tome;
use function Yume\Core\Planning\rendu_a_la_une;
use function Yume\Core\Planning\rendu_file_chapitres;
use function Yume\Core\Planning\rendu_planning_accueil;
use function Yume\Core\Planning\rendu_tomes_preparation;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\tomes_en_preparation;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tv_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé des contenus existants (œuvres, tomes, chapitres et journal vidés dans
 * la transaction du test, annulée ensuite par le lanceur).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tv_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			wp_cache_flush();
			add_filter( 'yume_core_notifier', '__return_false' );
			try {
				$corps();
			} finally {
				remove_filter( 'yume_core_notifier', '__return_false' );
				unset( $_GET['vue'], $_GET['oeuvre'] );
			}
		}
	);
}

/**
 * Date « Y-m-d » (heure de Paris) à N jours d'aujourd'hui.
 *
 * @param int $jours Décalage.
 */
function yume_tv_jour( int $jours ): string {
	return ( new DateTimeImmutable( 'now', fuseau() ) )->modify( sprintf( '%+d days', $jours ) )->format( 'Y-m-d' );
}

/**
 * Horodatage d'un jour à N jours d'aujourd'hui, à midi (heure de Paris).
 *
 * @param int $jours Décalage.
 */
function yume_tv_midi( int $jours ): int {
	return ( new DateTimeImmutable( yume_tv_jour( $jours ) . ' 12:00:00', fuseau() ) )->getTimestamp();
}

/**
 * Champs de date d'un contenu daté (date locale et GMT).
 *
 * @param int $ts Horodatage.
 * @return array<string,string>
 */
function yume_tv_dates( int $ts ): array {
	$gmt = gmdate( 'Y-m-d H:i:s', $ts );
	return array(
		'post_date'     => get_date_from_gmt( $gmt ),
		'post_date_gmt' => $gmt,
	);
}

/**
 * Crée une œuvre.
 *
 * @param string $titre  Titre.
 * @param string $statut Statut.
 */
function yume_tv_oeuvre( string $titre, string $statut = 'publish' ): int {
	$id = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => $statut,
		)
	);
	wp_set_object_terms( $id, 'light-novel', 'yume_type' );
	return $id;
}

/**
 * Crée un tome.
 *
 * @param int    $oeuvre Œuvre.
 * @param int    $numero Numéro.
 * @param array  $meta   Métadonnées.
 * @param string $statut Statut.
 * @param int    $ts     Date du contenu (0 : maintenant).
 */
function yume_tv_tome( int $oeuvre, int $numero, array $meta = array(), string $statut = 'draft', int $ts = 0 ): int {
	return yume_factory_post(
		array_merge(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre ) . ' — Tome ' . $numero,
				'post_status' => $statut,
				'meta_input'  => array_merge(
					array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => $numero,
						'yume_nature'    => 'tome',
						'yume_etape'     => 'traduction',
					),
					$meta
				),
			),
			yume_tv_dates( $ts ? $ts : time() )
		)
	);
}

/**
 * Crée un chapitre.
 *
 * @param int    $tome   Tome.
 * @param int    $numero Numéro.
 * @param string $statut publish | future | draft.
 * @param int    $ts     Date de sortie.
 * @param array  $meta   Métadonnées supplémentaires.
 */
function yume_tv_chapitre( int $tome, int $numero, string $statut, int $ts, array $meta = array() ): int {
	return yume_factory_post(
		array_merge(
			array(
				'post_type'   => 'yume_chapitre',
				'post_title'  => 'Chapitre ' . $numero,
				'post_status' => $statut,
				'meta_input'  => array_merge(
					array(
						'yume_tome_id'   => $tome,
						'yume_oeuvre_id' => (int) get_post_meta( $tome, 'yume_oeuvre_id', true ),
						'yume_numero'    => $numero,
						'yume_nature'    => 'chapitre',
					),
					$meta
				),
			),
			yume_tv_dates( $ts )
		)
	);
}

/**
 * Jeu de données : SukaMoka T.2 et Lanternes T.3 publiés chapitre par chapitre, Silent Witch
 * T.7 (à la une dans 3 jours) et T.8 (bloqué), Lanternes T.4 (dans 5 jours), SukaMoka T.3
 * (sans date), Grimgar T.9 publié d'un bloc il y a 2 jours (plus un bonus ajouté ensuite),
 * une œuvre privée avec un tome proche et des chapitres.
 *
 * @return array<string,int>
 */
function yume_tv_jeu(): array {
	$h               = HOUR_IN_SECONDS;
	$j               = DAY_IN_SECONDS;
	$t               = time();
	$d               = array();
	$d['suka']       = yume_tv_oeuvre( 'SukaMoka' );
	$d['lant']       = yume_tv_oeuvre( 'Les Lanternes de Brume-Haute' );
	$d['sw']         = yume_tv_oeuvre( 'Secrets of the Silent Witch' );
	$d['grim']       = yume_tv_oeuvre( 'Grimgar of Fantasy and Ash' );
	$d['priv']       = yume_tv_oeuvre( 'Œuvre secrète', 'draft' );
	$d['suka2']      = yume_tv_tome(
		$d['suka'],
		2,
		array(
			'yume_parution'         => 'en_cours',
			'yume_chapitres_prevus' => 12,
			'yume_rythme'           => array(
				'jour'  => 'samedi',
				'heure' => '18:00',
			),
		),
		'publish',
		$t - 10 * $j
	);
	$d['lant3']      = yume_tv_tome(
		$d['lant'],
		3,
		array(
			'yume_parution'         => 'en_cours',
			'yume_chapitres_prevus' => 10,
		),
		'publish',
		$t - 30 * $j
	);
	$d['sw7']        = yume_tv_tome(
		$d['sw'],
		7,
		array(
			'yume_etape'      => 'edition',
			'yume_avancement' => array(
				'traduction' => 100,
				'relecture'  => 100,
				'edition'    => 80,
			),
			'yume_date_cible' => yume_tv_jour( 3 ),
		)
	);
	$d['sw8']        = yume_tv_tome(
		$d['sw'],
		8,
		array(
			'yume_etape'         => 'relecture',
			'yume_bloque'        => 1,
			'yume_bloque_raison' => 'il manque un relecteur',
			'yume_date_cible'    => yume_tv_jour( 1 ),
		)
	);
	$d['lant4']      = yume_tv_tome(
		$d['lant'],
		4,
		array(
			'yume_etape'      => 'relecture',
			'yume_avancement' => array(
				'traduction' => 100,
				'relecture'  => 60,
				'edition'    => 0,
			),
			'yume_date_cible' => yume_tv_jour( 5 ),
		)
	);
	$d['suka3']      = yume_tv_tome( $d['suka'], 3 );
	$d['grim9']      = yume_tv_tome(
		$d['grim'],
		9,
		array(
			'yume_parution' => 'complet',
			'yume_etape'    => 'publie',
		),
		'publish',
		$t - 2 * $j
	);
	$d['priv1']      = yume_tv_tome( $d['priv'], 1, array( 'yume_date_cible' => yume_tv_jour( 1 ) ) );
	$d['priv2']      = yume_tv_tome( $d['priv'], 2, array( 'yume_parution' => 'en_cours' ), 'publish', $t - 5 * $j );
	$d['suka_c1']    = yume_tv_chapitre( $d['suka2'], 1, 'publish', $t - 3 * $j, array( 'yume_nature' => 'prologue' ) );
	$d['suka_c2']    = yume_tv_chapitre( $d['suka2'], 2, 'publish', $t - 2 * $h );
	$d['suka_c3']    = yume_tv_chapitre( $d['suka2'], 3, 'future', yume_tv_midi( 1 ) );
	$d['suka_c4']    = yume_tv_chapitre( $d['suka2'], 4, 'future', $t + 8 * $j );
	$d['suka_vieux'] = yume_tv_chapitre( $d['suka2'], 9, 'publish', $t - 20 * $j );
	$d['lant_c4']    = yume_tv_chapitre( $d['lant3'], 4, 'publish', $t - 5 * $j );
	$d['lant_c5']    = yume_tv_chapitre( $d['lant3'], 5, 'future', $t + 4 * $j );
	$d['lant_ret']   = yume_tv_chapitre( $d['lant3'], 6, 'publish', $t - $j, array( '_yume_retire' => 1 ) );
	$d['grim_c1']    = yume_tv_chapitre( $d['grim9'], 1, 'publish', $t - 2 * $j );
	$d['grim_c2']    = yume_tv_chapitre( $d['grim9'], 2, 'publish', $t - 2 * $j );
	$d['grim_bonus'] = yume_tv_chapitre( $d['grim9'], 3, 'publish', $t - $j, array( 'yume_nature' => 'bonus' ) );
	$d['priv_c1']    = yume_tv_chapitre( $d['priv2'], 1, 'publish', $t - $h );
	$d['priv_c2']    = yume_tv_chapitre( $d['priv2'], 2, 'future', $t + 2 * $j );
	wp_cache_flush();
	return $d;
}

/**
 * Identifiants d'une liste d'éléments.
 *
 * @param array  $elements Éléments.
 * @param string $cle      Clé de l'identifiant.
 * @return int[]
 */
function yume_tv_ids( array $elements, string $cle = 'id' ): array {
	return array_map( 'intval', array_column( $elements, $cle ) );
}

/*
 * -----------------------------------------------------------------------------
 * Prochain tome
 * -----------------------------------------------------------------------------
 */

yume_tv_test(
	'prochain tome : le plus proche à partir d’aujourd’hui, hors bloqué, œuvre privée et tome en cours ; enrichi (jours, couverture, adresses)',
	static function () {
		$d    = yume_tv_jeu();
		$tome = prochain_tome();
		yume_assert_true( is_array( $tome ) );
		yume_assert_same( $d['sw7'], (int) $tome['tome_id'], 'Silent Witch T.7 (T.8 bloqué et le tome de l’œuvre privée sont plus proches)' );
		yume_assert_same( 3, $tome['jours'] );
		yume_assert_same( '', $tome['couverture'], 'sans couverture' );
		yume_assert_same( '', $tome['url_tome'], 'tome pas encore en ligne' );
		yume_assert_same( get_permalink( $d['sw'] ), $tome['url_oeuvre'] );

		// Couverture de l'œuvre à défaut de celle du tome.
		$image = yume_factory_post(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'guid'           => 'http://example.test/couverture.jpg',
			)
		);
		update_post_meta( $image, '_wp_attached_file', 'couverture.jpg' );
		set_post_thumbnail( $d['sw'], $image );
		$tome = prochain_tome();
		yume_assert_same( $image, $tome['couverture_id'] );
		yume_assert_contains( 'couverture.jpg', $tome['couverture'] );
	}
);

yume_tv_test(
	'prochain tome : aucun sans date ni pour une date passée ; un tome programmé est retenu à sa date de sortie',
	static function () {
		$oeuvre = yume_tv_oeuvre( 'Grimgar of Fantasy and Ash' );
		yume_tv_tome( $oeuvre, 10 );
		yume_tv_tome( $oeuvre, 11, array( 'yume_date_cible' => yume_tv_jour( -2 ) ) );
		yume_assert_same( null, prochain_tome(), 'sans date, ou date dépassée : aucun' );

		yume_tv_tome( $oeuvre, 12, array( 'yume_date_cible' => yume_tv_jour( 6 ) ) );
		$programme = yume_tv_tome( $oeuvre, 13, array(), 'future', strtotime( yume_tv_jour( 2 ) . ' 16:00:00 UTC' ) );
		$tome      = prochain_tome();
		yume_assert_same( $programme, (int) $tome['tome_id'], 'tome programmé dans 2 jours' );
		yume_assert_true( $tome['programme'] );
		yume_assert_same( 2, $tome['jours'] );
		$html = rendu_a_la_une( $tome );
		yume_assert_contains( 'Sortie programmée', $html );
		yume_assert_contains( 'J-2', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * File des chapitres
 * -----------------------------------------------------------------------------
 */

yume_tv_test(
	'file des chapitres : programmés dans l’ordre, publiés récents d’abord, « nouveau » sous 48 h ; ni retiré, ni œuvre privée, ni tome publié d’un bloc',
	static function () {
		$d    = yume_tv_jeu();
		$file = file_chapitres();
		yume_assert_same( array( 'prochains', 'publies', 'tomes_en_cours' ), array_keys( $file ) );
		yume_assert_same( array( $d['suka_c3'], $d['lant_c5'], $d['suka_c4'] ), yume_tv_ids( $file['prochains'] ), 'ordre chronologique' );
		yume_assert_same( array( $d['suka_c2'], $d['grim_bonus'], $d['suka_c1'], $d['lant_c4'] ), yume_tv_ids( $file['publies'] ), 'plus récents d’abord, 4 au plus' );

		$c2 = $file['publies'][0];
		yume_assert_same( 'SukaMoka', $c2['oeuvre'] );
		yume_assert_same( $d['suka'], $c2['oeuvre_id'] );
		yume_assert_same( get_permalink( $d['suka'] ), $c2['url_oeuvre'] );
		yume_assert_same( $d['suka2'], $c2['tome_id'] );
		yume_assert_same( 'Tome 2', $c2['tome'] );
		yume_assert_same( 'Chapitre 2', $c2['libelle'] );
		yume_assert_same( get_permalink( $d['suka_c2'] ), $c2['url'] );
		yume_assert_true( $c2['nouveau'], 'publié il y a 2 h' );
		yume_assert_false( $file['publies'][2]['nouveau'], 'publié il y a 3 jours' );
		yume_assert_same( 'Prologue', $file['publies'][2]['libelle'] );
		yume_assert_same( '', $file['prochains'][0]['url'], 'pas d’adresse avant la sortie' );
		yume_assert_false( $file['prochains'][0]['nouveau'] );
		yume_assert_true( $file['prochains'][0]['ts'] > time() );

		$ids = array_merge( yume_tv_ids( $file['prochains'] ), yume_tv_ids( file_chapitres( array( 'publies' => 20 ) )['publies'] ) );
		foreach ( array( 'lant_ret', 'priv_c1', 'priv_c2', 'grim_c1', 'grim_c2', 'suka_vieux' ) as $absent ) {
			yume_assert_false( in_array( $d[ $absent ], $ids, true ), $absent );
		}

		// Limites et fenêtre.
		$file = file_chapitres(
			array(
				'prochains'     => 2,
				'publies'       => 1,
				'jours_publies' => 30,
			)
		);
		yume_assert_same( array( $d['suka_c3'], $d['lant_c5'] ), yume_tv_ids( $file['prochains'] ) );
		yume_assert_same( array( $d['suka_c2'] ), yume_tv_ids( $file['publies'] ) );
		yume_assert_true(
			in_array(
				$d['suka_vieux'],
				yume_tv_ids(
					file_chapitres(
						array(
							'publies'       => 20,
							'jours_publies' => 30,
						)
					)['publies']
				),
				true
			),
			'publié il y a 20 jours dans une fenêtre de 30'
		);

		// Version en attente de remplacement : jamais dans la file.
		update_post_meta( $d['suka_c2'], '_yume_remplacement_de', $d['suka_c1'] );
		yume_assert_false( in_array( $d['suka_c2'], yume_tv_ids( file_chapitres()['publies'] ), true ) );
	}
);

yume_tv_test(
	'file des chapitres : tomes en cours (« 3 / 12 », rythme, adresse) et filtre par œuvre',
	static function () {
		$d     = yume_tv_jeu();
		$tomes = file_chapitres()['tomes_en_cours'];
		yume_assert_same( array( $d['suka2'], $d['lant3'] ), yume_tv_ids( $tomes, 'tome_id' ), 'prochain chapitre programmé d’abord ; ni Grimgar (complet) ni l’œuvre privée' );
		yume_assert_same( 'SukaMoka', $tomes[0]['oeuvre'] );
		yume_assert_same( 'Tome 2', $tomes[0]['tome'] );
		yume_assert_same( 3, $tomes[0]['en_ligne'] );
		yume_assert_same( 12, $tomes[0]['prevus'] );
		yume_assert_same( 'chaque samedi à 18 h', $tomes[0]['rythme'] );
		yume_assert_same( get_permalink( $d['suka2'] ), $tomes[0]['url'] );
		yume_assert_same( '', $tomes[1]['rythme'] );

		$file = file_chapitres( array( 'oeuvre_id' => $d['lant'] ) );
		yume_assert_same( array( $d['lant_c5'] ), yume_tv_ids( $file['prochains'] ) );
		yume_assert_same( array( $d['lant_c4'] ), yume_tv_ids( $file['publies'] ) );
		yume_assert_same( array( $d['lant3'] ), yume_tv_ids( $file['tomes_en_cours'], 'tome_id' ) );

		$file = file_chapitres( array( 'oeuvre_id' => $d['priv'] ) );
		yume_assert_same( array(), $file['prochains'] );
		yume_assert_same( array(), $file['publies'] );
		yume_assert_same( array(), $file['tomes_en_cours'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Tomes en préparation
 * -----------------------------------------------------------------------------
 */

yume_tv_test(
	'tomes en préparation : sans le tome à la une ni les tomes en cours, datés d’abord, limite',
	static function () {
		$d = yume_tv_jeu();
		yume_assert_same( array( $d['sw8'], $d['lant4'], $d['suka3'] ), yume_tv_ids( tomes_en_preparation( $d['sw7'] ), 'tome_id' ) );
		yume_assert_same( array( $d['sw8'], $d['sw7'], $d['lant4'], $d['suka3'] ), yume_tv_ids( tomes_en_preparation(), 'tome_id' ) );
		yume_assert_same( array( $d['sw8'], $d['sw7'] ), yume_tv_ids( tomes_en_preparation( 0, 2 ), 'tome_id' ) );

		$html = rendu_tomes_preparation( tomes_en_preparation( $d['sw7'] ) );
		yume_assert_contains( 'Tomes en préparation', $html );
		yume_assert_contains( 'Bloqué · il manque un relecteur', $html, 'raison du blocage en toutes lettres' );
		yume_assert_contains( 'Relecture 60', $html );
		yume_assert_contains( 'Date : à venir', $html );
		yume_assert_contains( 'Cible : ', $html );
		yume_assert_contains( 'Légende', $html );
		yume_assert_contains( 'Aucun tome en préparation pour le moment.', rendu_tomes_preparation( array() ) );
	}
);

yume_tv_test(
	'tomes en préparation : le retard s’écrit en toutes lettres',
	static function () {
		$oeuvre = yume_tv_oeuvre( 'Raven of the Inner Palace' );
		yume_tv_tome(
			$oeuvre,
			7,
			array(
				'yume_etape'      => 'relecture',
				'yume_date_cible' => yume_tv_jour( -4 ),
			)
		);
		$html = rendu_tomes_preparation( tomes_en_preparation() );
		yume_assert_contains( '<span aria-hidden="true">▲</span> En retard · relecture</span>', $html );
		yume_assert_contains( 'Prévu ', $html, 'date dépassée : pas présentée comme une date à venir' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Rendus et bloc d'accueil
 * -----------------------------------------------------------------------------
 */

yume_tv_test(
	'bloc yume/planning-accueil : titre et lien, à la une, deux files, frise ordonnée autour d’« Aujourd’hui », boutons',
	static function () {
		$d    = yume_tv_jeu();
		$html = yume_render_block( 'yume/planning-accueil' );
		yume_assert_contains( 'class="yn-planning-accueil yn-vitrine wp-block-yume-planning-accueil"', $html );
		yume_assert_contains( '>Planning</h2>', $html );
		yume_assert_contains( 'href="' . esc_url( yume_url_page( 'planning' ) ) . '">Planning complet', $html );
		yume_assert_same( 1, substr_count( $html, 'yn-vitrine-une ' ), 'un bandeau à la une' );
		yume_assert_contains( 'Prochain tome', $html );
		yume_assert_contains( 'Secrets of the Silent Witch', $html );
		yume_assert_contains( '>J-3<', $html );
		yume_assert_contains( 'Sortie dans 3 jours', $html );
		yume_assert_contains( 'Encore l’édition à finir.', $html );
		yume_assert_contains( 'Voir la fiche', $html );
		yume_assert_contains( 'Chapitres en lecture', $html );
		yume_assert_contains( 'Tomes en préparation', $html );
		yume_assert_true( strpos( $html, 'Chapitres en lecture' ) < strpos( $html, 'Tomes en préparation' ), 'chapitres à gauche' );
		yume_assert_contains( '3 / 12', $html );
		yume_assert_contains( 'Chaque samedi à 18 h', $html );
		yume_assert_contains( 'Rythme libre', $html );
		yume_assert_contains( 'Nouveau', $html );
		yume_assert_contains( 'Me prévenir', $html );
		yume_assert_contains( 'Lire', $html );
		yume_assert_contains( 'Tous les chapitres publiés', $html );
		yume_assert_contains( esc_url( add_query_arg( 'tri', 'recent', yume_url_page( 'bibliotheque' ) ) ), $html );
		yume_assert_contains( 'role="tablist"', $html );
		yume_assert_contains( 'hidden>', $html, 'onglets masqués sans JavaScript' );
		yume_assert_not_contains( 'Silent Witch · Tome 8 ·', $html );

		// Frise : le chapitre programmé le plus proche juste au-dessus du repère du jour.
		$c4  = strpos( $html, 'Tome 2 · Chapitre 4' );
		$c5  = strpos( $html, 'Tome 3 · Chapitre 5' );
		$c3  = strpos( $html, 'Tome 2 · Chapitre 3' );
		$auj = strpos( $html, 'yn-vitrine-frise__aujourdhui' );
		$pub = strpos( $html, 'Tome 2 · Chapitre 2' );
		yume_assert_true( $c4 < $c5 && $c5 < $c3 && $c3 < $auj && $auj < $pub, 'du plus lointain au plus proche, puis aujourd’hui, puis les publiés' );
		yume_assert_contains( 'Tome 2 · Chapitre 3 · demain', $html );

		// Visiteur : « Me prévenir » mène aux actions de la fiche de l'œuvre.
		yume_assert_contains( 'href="' . esc_url( get_permalink( $d['suka'] ) . '#yn-oeuvre-actions' ) . '"', $html );
		yume_assert_not_contains( 'yume_social_favori', $html );

		// Ressources de la vitrine chargées.
		yume_assert_true( wp_style_is( 'yume-vitrine', 'enqueued' ) );
		yume_assert_true( wp_script_is( 'yume-vitrine', 'enqueued' ) );
	}
);

yume_tv_test(
	'bloc yume/planning-accueil : membre connecté, « Suivre l’œuvre » et « Me prévenir » suivent l’œuvre (formulaire du favori)',
	static function () {
		$d = yume_tv_jeu();
		wp_set_current_user( yume_factory_user( 'subscriber' ) );
		$html = rendu_planning_accueil();
		yume_assert_contains( 'name="action" value="yume_social_favori"', $html );
		yume_assert_contains( 'name="yn_oeuvre" value="' . $d['sw'] . '"', $html );
		yume_assert_contains( 'name="yn_ancre" value="yn-vitrine-une"', $html );
		yume_assert_contains( 'Suivre l’œuvre', $html );
		yume_assert_contains( 'aria-pressed="false"', $html );
		if ( function_exists( '\Yume\Core\Social\ajouter_favori' ) ) {
			\Yume\Core\Social\ajouter_favori( get_current_user_id(), $d['sw'] );
			yume_assert_contains( 'Œuvre suivie', rendu_planning_accueil() );
		}
	}
);

yume_tv_test(
	'bloc yume/planning-accueil : textes vides propres, et aucune sortie quand il n’y a rien',
	static function () {
		yume_assert_same( '', yume_render_block( 'yume/planning-accueil' ), 'rien du tout' );

		yume_tv_tome( yume_tv_oeuvre( 'SukaMoka' ), 3 );
		$html = yume_render_block( 'yume/planning-accueil' );
		yume_assert_not_contains( 'yn-vitrine-une ', $html, 'pas de tome daté : pas de bandeau' );
		yume_assert_contains( 'Aucun chapitre programmé pour le moment.', $html );
		yume_assert_contains( 'Aucun chapitre publié récemment.', $html );
		yume_assert_contains( 'SukaMoka', $html );
		yume_assert_same( '', rendu_a_la_une( array() ) );
	}
);

yume_tv_test(
	'vitrine : cache de pages limité à 60 s, gabarit de l’accueil (dernières sorties puis planning, sans yume/upcoming)',
	static function () {
		yume_tv_tome( yume_tv_oeuvre( 'SukaMoka' ), 3, array( 'yume_date_cible' => yume_tv_jour( 2 ) ) );
		$avant = $GLOBALS['batcache'] ?? null;
		try {
			$GLOBALS['batcache'] = (object) array( 'max_age' => 300 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Batcache simulé.
			yume_render_block( 'yume/planning-accueil' );
			yume_assert_same( 60, $GLOBALS['batcache']->max_age );
		} finally {
			$GLOBALS['batcache'] = $avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Batcache simulé.
		}

		yume_assert_true( WP_Block_Type_Registry::get_instance()->is_registered( 'yume/planning-accueil' ) );
		yume_assert_true( wp_style_is( 'yume-vitrine', 'registered' ) );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/includes/planning/assets/vitrine.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		yume_assert_contains( 'grid-template-columns: minmax(0, 3fr) minmax(0, 2fr)', $css, 'grille 3fr / 2fr' );
		yume_assert_contains( 'align-items: stretch', $css, 'colonnes de même hauteur' );
		yume_assert_contains( 'margin-top: auto', $css, 'légende en bas' );
		yume_assert_same( 0, preg_match( '/#[0-9a-f]{3,6}\b/i', $css ), 'aucune couleur codée en dur' );

		$theme = get_theme_root() . '/yume';
		if ( is_dir( $theme ) ) {
			$accueil = (string) file_get_contents( $theme . '/templates/front-page.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
			$sorties = strpos( $accueil, '<!-- wp:yume/latest-releases' );
			$plan    = strpos( $accueil, '<!-- wp:yume/planning-accueil' );
			yume_assert_true( false !== $sorties && false !== $plan && $sorties < $plan, 'dernières sorties en haut, puis le planning' );
			yume_assert_not_contains( 'wp:yume/upcoming', $accueil );
			yume_assert_true( $plan < strpos( $accueil, 'Actualités' ), 'actualités après le planning' );
			yume_assert_contains( '<!-- wp:yume/partenaires', $accueil );
			yume_assert_contains( 'yn-bandeau-reprise', $accueil );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page Planning
 * -----------------------------------------------------------------------------
 */

yume_tv_test(
	'page Planning : bandeau à la une avant « En bref », onglet Chapitres (?vue=chapitres) avec la file groupée par jour',
	static function () {
		$d    = yume_tv_jeu();
		$html = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'À la une · prochain tome', $html );
		yume_assert_true( strpos( $html, 'yn-vitrine-une' ) < strpos( $html, 'yn-planning-chiffres' ), 'bandeau avant les chiffres' );
		yume_assert_contains( '<h2 class="yn-vitrine-une__oeuvre"', $html );
		yume_assert_contains( 'vue=chapitres', $html );
		yume_assert_true( strpos( $html, '>Tableau</a>' ) < strpos( $html, '>Chapitres</a>' ) && strpos( $html, '>Chapitres</a>' ) < strpos( $html, '>Calendrier</a>' ), 'Tableau | Chapitres | Calendrier' );
		yume_assert_contains( 'yn-planning__table', $html, 'vue par défaut : tableau' );
		yume_assert_not_contains( 'yn-vitrine-chapitres-planning', $html );

		$_GET['vue'] = 'chapitres';
		$html        = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'id="yn-vitrine-chapitres-planning"', $html );
		yume_assert_contains( '<h2 class="yn-vitrine__titre"', $html );
		yume_assert_contains( 'aria-current="true">Chapitres</a>', $html );
		yume_assert_not_contains( 'yn-planning__table', $html );
		yume_assert_contains( 'yn-vitrine-frise__titre-jour', $html, 'groupée par jour' );
		yume_assert_contains( 'planning.ics', $html );
		yume_assert_contains( 'calendrier ICS', $html );
		yume_assert_contains( 'Tome 2 · Chapitre 4', $html, 'jusqu’à 10 programmés' );
		yume_assert_contains( 'Tous les chapitres publiés', $html );

		$_GET['oeuvre'] = (string) $d['lant'];
		$html           = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'Tome 3 · Chapitre 5', $html );
		yume_assert_not_contains( 'Tome 2 · Chapitre 4', $html, 'filtre par œuvre' );
		yume_assert_contains( 'vue=chapitres', $html, 'les filtres gardent la vue' );
	}
);

yume_tv_test(
	'page Planning : vue Chapitres vide, textes propres',
	static function () {
		yume_tv_oeuvre( 'SukaMoka' );
		$html = rendu_file_chapitres( file_chapitres(), 'planning' );
		yume_assert_contains( 'Aucun chapitre programmé pour le moment.', $html );
		yume_assert_contains( 'Aucun chapitre publié récemment.', $html );
	}
);
