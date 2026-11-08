<?php
/**
 * Tests de la programmation des sorties (lot C, docs/09) : répartition en N publications
 * (Calendrier::repartir(), fixture Miss Medic partagée avec tests/js/programmer.test.js),
 * calendrier sur mesure (sortie « calendrier » : dates explicites, une annonce par publication),
 * repli sans JavaScript (repartition = N), route POST /publications/{id}/calendrier
 * (reprogrammation des chapitres déjà programmés), vue de l'espace équipe « Programmer les
 * sorties » (droits, rendu, envoi sans JavaScript).
 *
 * @package Yume\Core
 */

use Yume\Core\Publication\Calendrier;
use Yume\Core\Publication\Formulaire;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_tpub' ) ) {
	$GLOBALS['yume_tests_publication_aides_seules'] = true;
	require __DIR__ . '/test-publication.php';
	unset( $GLOBALS['yume_tests_publication_aides_seules'] );
}
if ( ! function_exists( 'yume_tpc_docx' ) ) {
	// Fonctions d'aide de l'ajout de chapitres (DOCX synthétiques, tome, compteurs).
	$GLOBALS['yume_tests_publication_aides_seules'] = true;
	require __DIR__ . '/test-publication-chapitres.php';
	unset( $GLOBALS['yume_tests_publication_aides_seules'] );
}

if ( ! function_exists( 'yume_tcal_fixture' ) ) {
	/**
	 * Fixture des 45 unités du tome 2 de Miss Medic (titres neutres, nombres de mots).
	 *
	 * @return array<string,mixed>
	 */
	function yume_tcal_fixture(): array {
		$json = file_get_contents( __DIR__ . '/fixtures/repartition-miss-medic.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixture locale.
		return json_decode( (string) $json, true );
	}

	/**
	 * Date locale du site « AAAA-MM-JJTHH:MM » dans $jours jours à $heure heures.
	 *
	 * @param int $jours Jours.
	 * @param int $heure Heure.
	 */
	function yume_tcal_date( int $jours, int $heure = 18 ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+' . $jours . ' days' )->setTime( $heure, 0 )->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Date GMT enregistrée d'une date locale « AAAA-MM-JJTHH:MM ».
	 *
	 * @param string $locale Date locale.
	 */
	function yume_tcal_gmt( string $locale ): string {
		return ( new DateTimeImmutable( $locale, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Calendrier JSON : publications [date, [clés]].
	 *
	 * @param array<int,array{0:string,1:string[]}> $publications Publications.
	 */
	function yume_tcal_json( array $publications ): string {
		return (string) wp_json_encode(
			array(
				'publications' => array_map(
					static fn( array $p ): array => array(
						'date'      => $p[0],
						'chapitres' => $p[1],
					),
					$publications
				),
			)
		);
	}

	/**
	 * Sortie « calendrier » d'un tome (mode chapitres).
	 *
	 * @param int                 $tome    Tome.
	 * @param array<string,mixed> $options Options supplémentaires.
	 * @return array<string,mixed>|WP_Error
	 */
	function yume_tcal_publier( int $tome, array $options ) {
		return Service::publier(
			$tome,
			'maintenant',
			array_merge(
				array(
					'mode'         => Service::MODE_CHAPITRES,
					'sortie'       => 'calendrier',
					'sans_annonce' => false,
				),
				$options
			)
		);
	}
}

if ( ! empty( $GLOBALS['yume_tests_publication_aides_seules'] ) ) {
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Répartition
 * -----------------------------------------------------------------------------
 */

yume_test(
	'calendrier : repartir() sur les 45 unités de Miss Medic donne les groupes attendus (les mêmes que programmer.js) pour N = 10, 5, 1, 45',
	function () {
		$fixture = yume_tcal_fixture();
		$mots    = array_column( $fixture['unites'], 'mots' );
		yume_assert_same( 45, count( $mots ) );
		foreach ( $fixture['attendu'] as $n => $attendu ) {
			yume_assert_same( $attendu, Service::repartir( $mots, (int) $n ), 'N = ' . $n );
		}
		// N = 10 : la plus grosse publication est l'unité insécable de 8 453 mots.
		$sommes = array();
		$i      = 0;
		foreach ( Service::repartir( $mots, 10 ) as $taille ) {
			$sommes[] = array_sum( array_slice( $mots, $i, $taille ) );
			$i       += $taille;
		}
		yume_assert_same( 8453, max( $sommes ) );
		yume_assert_same( array_sum( $mots ), array_sum( $sommes ) );
	}
);

yume_test(
	'calendrier : repartir() ne coupe jamais une unité, ne laisse jamais une unité à 0 mot seule, ramène N au nombre d’unités',
	function () {
		yume_assert_same( array( 2 ), Service::repartir( array( 100, 0 ), 2 ), '0 mot en fin : collé à la précédente' );
		yume_assert_same( array( 2, 1 ), Service::repartir( array( 0, 100, 50 ), 3 ), '0 mot en tête : collé à la suivante' );
		yume_assert_same( array( 2 ), Service::repartir( array( 0, 0 ), 5 ) );
		yume_assert_same( array(), Service::repartir( array(), 3 ) );
		yume_assert_same( array( 1, 1, 1 ), Service::repartir( array( 10, 20, 30 ), 9 ) );
		yume_assert_same( array( 3 ), Service::repartir( array( 10, 20, 30 ), 0 ) );
		yume_assert_same( array( 2, 1 ), Service::repartir( array( 10, 10, 30 ), 2 ) );
		$mots = array_column( yume_tcal_fixture()['unites'], 'mots' );
		yume_assert_same( 37, count( Service::repartir( $mots, 400 ) ), '37 blocs (8 titres sans texte collés)' );
	}
);

yume_test(
	'calendrier : analyser() lit le JSON du formulaire et refuse un calendrier mal formé',
	function () {
		yume_assert_same( array(), Calendrier::analyser( '' ) );
		yume_assert_same( array(), Calendrier::analyser( null ) );
		$ok = Calendrier::analyser( yume_tcal_json( array( array( '2030-01-05T18:00', array( 'chapitre:1#1', 'id:12' ) ) ) ) );
		yume_assert_same(
			array(
				array(
					'date'      => '2030-01-05T18:00',
					'chapitres' => array( 'chapitre:1#1', 'id:12' ),
				),
			),
			$ok
		);
		foreach ( array( '{', '{"publications":"x"}', '{"publications":[{"date":"x"}]}', '{"publications":[{"chapitres":["<b>"]}]}', '[1,2]' ) as $mauvais ) {
			$e = Calendrier::analyser( $mauvais );
			yume_assert_same( 'yume_calendrier_invalide', is_wp_error( $e ) ? $e->get_error_code() : '', $mauvais );
		}
		$e = Calendrier::date( '15/11/2030' );
		yume_assert_same( 'yume_date_invalide', is_wp_error( $e ) ? $e->get_error_code() : '' );
		yume_assert_same( array( null, true ), Calendrier::date( '2001-01-01T10:00' ), 'date passée : maintenant' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Sortie « calendrier »
 * -----------------------------------------------------------------------------
 */

yume_test(
	'calendrier : dates explicites, chapitres d’une même publication à la même date, UNE annonce par publication (plusieurs publications en attente)',
	yume_tpub(
		function () {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Calendrier' ), array( 1, 2, 3, 4, 5 ) );
			$d1                 = yume_tcal_date( 2 );
			$d2                 = yume_tcal_date( 9 );
			$d3                 = yume_tcal_date( 16 );
			$vus                = array();
			$ecouteur           = static function ( $id, $groupe = array() ) use ( &$vus ) {
				$vus[] = array( (int) $id, array_map( 'intval', (array) $groupe ) );
			};
			add_action( 'yume_chapitre_publie', $ecouteur, 1, 2 );
			try {
				$sortie = yume_tcal_publier(
					$tome,
					array(
						'calendrier' => yume_tcal_json(
							array(
								array( $d1, array( 'id:' . $ids[0], 'id:' . $ids[1] ) ),
								array( $d2, array( 'id:' . $ids[2], 'id:' . $ids[3] ) ),
								array( $d3, array( 'id:' . $ids[4] ) ),
							)
						),
					)
				);
				yume_assert_false( is_wp_error( $sortie ), is_wp_error( $sortie ) ? $sortie->get_error_message() : '' );
				yume_assert_same( 'calendrier', $sortie['sortie'] );
				yume_assert_same( 3, $sortie['publications'] );
				yume_assert_same( 5, $sortie['chapitres'] );
				$attendu = array( $d1, $d1, $d2, $d2, $d3 );
				foreach ( $ids as $i => $id ) {
					yume_assert_same( 'future', get_post_status( $id ) );
					yume_assert_same( yume_tcal_gmt( $attendu[ $i ] ), get_post_field( 'post_date_gmt', $id ), 'chapitre ' . ( $i + 1 ) );
				}
				// Deux publications de plusieurs chapitres attendent : une sortie groupée chacune.
				$groupes = Calendrier::groupes( $tome );
				$t1      = ( new DateTimeImmutable( $d1, wp_timezone() ) )->getTimestamp();
				$t2      = ( new DateTimeImmutable( $d2, wp_timezone() ) )->getTimestamp();
				yume_assert_same( array( (string) $t1, (string) $t2 ), array_map( 'strval', array_keys( $groupes ) ) );
				yume_assert_true( false !== wp_next_scheduled( Calendrier::HOOK_GROUPE, array( $tome, $t1 ) ) );
				yume_assert_true( false !== wp_next_scheduled( Calendrier::HOOK_GROUPE, array( $tome, $t2 ) ) );
				yume_assert_same( 'ignore', get_post_meta( $ids[0], '_yume_publie_notifie', true ) );
				yume_assert_false( metadata_exists( 'post', $ids[4], '_yume_publie_notifie' ), 'publication d’un seul chapitre : annoncée par core' );
				yume_assert_contains( '3 publications', Formulaire::message_chapitres( $sortie ) );
				yume_assert_contains( 'une annonce par publication', Formulaire::message_chapitres( $sortie ) );
				yume_assert_same( array(), $vus );

				// Le cron publie les deux premiers chapitres, puis la tâche annonce la publication 1.
				wp_publish_post( $ids[0] );
				wp_publish_post( $ids[1] );
				yume_assert_same( array(), $vus, 'aucune annonce par chapitre' );
				do_action( Calendrier::HOOK_GROUPE, $tome, $t1 );
				yume_assert_same( array( array( $ids[0], array( $ids[0], $ids[1] ) ) ), $vus );
				do_action( Calendrier::HOOK_GROUPE, $tome, $t1 );
				yume_assert_same( 1, count( $vus ), 'jamais deux fois' );
				yume_assert_same( array( (string) $t2 ), array_map( 'strval', array_keys( Calendrier::groupes( $tome ) ) ), 'la publication 2 attend toujours' );
				// Publication 2.
				wp_publish_post( $ids[2] );
				wp_publish_post( $ids[3] );
				do_action( Calendrier::HOOK_GROUPE, $tome, $t2 );
				yume_assert_same( array( $ids[2], array( $ids[2], $ids[3] ) ), $vus[1] );
				yume_assert_false( metadata_exists( 'post', $tome, Calendrier::META_GROUPES ) );
			} finally {
				remove_action( 'yume_chapitre_publie', $ecouteur, 1 );
			}
		}
	)
);

yume_test(
	'calendrier : contrôles (chapitre oublié, doublon, ordre, dates décroissantes, inconnu) et date passée = maintenant avec avertissement',
	yume_tpub(
		function () {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Contrôles' ), array( 1, 2, 3 ) );
			$d1                 = yume_tcal_date( 2 );
			$d2                 = yume_tcal_date( 5 );
			$cas                = array(
				'yume_calendrier_incomplet' => array( array( $d1, array( 'id:' . $ids[0], 'id:' . $ids[1] ) ) ),
				'yume_calendrier_doublon'   => array( array( $d1, array( 'id:' . $ids[0], 'id:' . $ids[1] ) ), array( $d2, array( 'id:' . $ids[1], 'id:' . $ids[2] ) ) ),
				'yume_calendrier_ordre'     => array( array( $d1, array( 'id:' . $ids[0], 'id:' . $ids[2] ) ), array( $d2, array( 'id:' . $ids[1] ) ) ),
				'yume_calendrier_dates'     => array( array( $d2, array( 'id:' . $ids[0] ) ), array( $d1, array( 'id:' . $ids[1], 'id:' . $ids[2] ) ) ),
				'yume_calendrier_chapitre'  => array( array( $d1, array( 'id:' . $ids[0], 'id:' . $ids[1], 'id:' . $ids[2], 'id:999999' ) ) ),
			);
			foreach ( $cas as $code => $publications ) {
				$e = yume_tcal_publier( $tome, array( 'calendrier' => yume_tcal_json( $publications ) ) );
				yume_assert_same( $code, is_wp_error( $e ) ? $e->get_error_code() : 'pas d’erreur', $code );
				yume_assert_same( 'draft', get_post_status( $ids[0] ), 'rien n’est sorti : ' . $code );
			}
			$e = yume_tcal_publier( $tome, array() );
			yume_assert_same( 'yume_calendrier_manquant', is_wp_error( $e ) ? $e->get_error_code() : '' );

			// Date passée : maintenant, avec avertissement ; la suite à sa date.
			$sortie = yume_tcal_publier(
				$tome,
				array(
					'calendrier' => yume_tcal_json(
						array(
							array( '2001-01-01T10:00', array( 'id:' . $ids[0] ) ),
							array( $d2, array( 'id:' . $ids[1], 'id:' . $ids[2] ) ),
						)
					),
				)
			);
			yume_assert_false( is_wp_error( $sortie ) );
			yume_assert_same( 'publish', get_post_status( $ids[0] ) );
			yume_assert_same( 'future', get_post_status( $ids[2] ) );
			yume_assert_same( array( 'Publication 1 : la date choisie est passée, elle sort maintenant.' ), $sortie['avertissements'] );
			yume_assert_contains( 'elle sort maintenant', Formulaire::message_chapitres( $sortie ) );
		}
	)
);

yume_test(
	'calendrier : clés de comparaison d’un fichier analysé (DOCX synthétique), première sortie du tome avec ses premiers chapitres, publications suivantes groupées',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre  = yume_tpub_oeuvre( 'SukaMoka calendrier' );
			$tome    = yume_tpc_tome( $oeuvre );
			$rapport = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue', 'ch1', 'ch2', 'ch3' ) );
			$cles    = array_column( $rapport['comparaison']['lignes'], 'cle' );
			yume_assert_same( 4, count( $cles ) );
			$d1 = yume_tcal_date( 3 );
			$d2 = yume_tcal_date( 10 );
			$n  = yume_tpc_compter(
				function () use ( $tome, $cles, $d1, $d2 ) {
					$sortie = yume_tcal_publier(
						$tome,
						array(
							'calendrier' => yume_tcal_json(
								array(
									array( $d1, array( $cles[0], $cles[1] ) ),
									array( $d2, array( $cles[2], $cles[3] ) ),
								)
							),
						)
					);
					yume_assert_false( is_wp_error( $sortie ), is_wp_error( $sortie ) ? $sortie->get_error_message() : '' );
				}
			);
			yume_assert_same( 'future', get_post_status( $tome ), 'le tome sort avec sa première publication' );
			yume_assert_same( yume_tcal_gmt( $d1 ), get_post_field( 'post_date_gmt', $tome ) );
			yume_assert_same( yume_tcal_gmt( $d1 ), get_post_field( 'post_date_gmt', yume_tpc_id( $tome, 'Chapitre 1' ) ) );
			yume_assert_same( yume_tcal_gmt( $d2 ), get_post_field( 'post_date_gmt', yume_tpc_id( $tome, 'Chapitre 2' ) ) );
			yume_assert_same( yume_tcal_gmt( $d2 ), get_post_field( 'post_date_gmt', yume_tpc_id( $tome, 'Chapitre 3' ) ) );
			yume_assert_same( array(), array_merge( $n->tome, $n->chap ), 'rien d’annoncé avant les dates' );
			$t2 = ( new DateTimeImmutable( $d2, wp_timezone() ) )->getTimestamp();
			yume_assert_same( array( (string) $t2 ), array_map( 'strval', array_keys( Calendrier::groupes( $tome ) ) ), 'publication 2 : une annonce groupée' );
		}
	)
);

yume_test(
	'calendrier : repli sans JavaScript (repartition = N) : répartition par nombre de mots, dates au rythme du tome ; REST /publier sortie=calendrier',
	yume_tpub(
		function () {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Repli' ), array( 1, 2, 3, 4 ) );
			foreach ( array( 1000, 0, 500, 500 ) as $i => $mots ) {
				update_post_meta( $ids[ $i ], 'yume_nb_mots', $mots );
			}
			update_post_meta(
				$tome,
				'yume_rythme',
				array(
					'jour'  => 'samedi',
					'heure' => '18:00',
				)
			);
			$s1     = yume_prochaine_sortie_rythme( $tome );
			$s2     = $s1->modify( '+7 days' );
			$sortie = yume_tcal_publier( $tome, array( 'repartition' => 2 ) );
			yume_assert_false( is_wp_error( $sortie ), is_wp_error( $sortie ) ? $sortie->get_error_message() : '' );
			yume_assert_same( 2, $sortie['publications'] );
			$gmt = static fn( DateTimeImmutable $d ): string => $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			yume_assert_same( $gmt( $s1 ), get_post_field( 'post_date_gmt', $ids[0] ) );
			yume_assert_same( $gmt( $s2 ), get_post_field( 'post_date_gmt', $ids[1] ), 'chapitre à 0 mot collé au suivant' );
			yume_assert_same( $gmt( $s2 ), get_post_field( 'post_date_gmt', $ids[3] ) );

			// REST : sortie=calendrier avec le JSON du panneau.
			list( $tome2, $ids2 ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'REST' ), array( 7, 8 ) );
			$d                    = yume_tcal_date( 4 );
			$reponse              = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome2 . '/publier',
				array(
					'mode'         => 'chapitres',
					'sortie'       => 'calendrier',
					'sans_annonce' => false,
					'calendrier'   => yume_tcal_json( array( array( $d, array( 'id:' . $ids2[0], 'id:' . $ids2[1] ) ) ) ),
				),
				$editeur
			);
			yume_assert_same( 200, $reponse->get_status(), wp_json_encode( $reponse->get_data() ) );
			yume_assert_same( 1, $reponse->get_data()['publications'] );
			yume_assert_same( yume_tcal_gmt( $d ), get_post_field( 'post_date_gmt', $ids2[1] ) );
			$reponse = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome2 . '/publier',
				array(
					'mode'       => 'chapitres',
					'sortie'     => 'calendrier',
					'calendrier' => '{"publications":"x"}',
				),
				$editeur
			);
			yume_assert_same( 400, $reponse->get_status() );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Reprogrammation : route /calendrier et vue « Programmer les sorties »
 * -----------------------------------------------------------------------------
 */

yume_test(
	'calendrier : POST /publications/{id}/calendrier programme les brouillons et reprogramme les chapitres programmés (annonces déplacées, passage complet suivi, journal)',
	yume_tpub(
		function () {
			global $wpdb;
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Reprogrammer' ), array( 1, 2, 3, 4 ) );
			// Tome en cours de parution : ses nouveaux chapitres sont annoncés par défaut.
			update_post_meta( $tome, 'yume_parution', 'en_cours' );
			// Chapitres 1 et 2 déjà programmés ensemble (sortie groupée historique), 3 et 4 en brouillon.
			$avant  = yume_tcal_date( 3 );
			$sortie = Service::publier( $tome, $avant );
			yume_assert_false( is_wp_error( $sortie ) );
			wp_update_post(
				array(
					'ID'          => $ids[2],
					'post_status' => 'draft',
				)
			);
			wp_update_post(
				array(
					'ID'          => $ids[3],
					'post_status' => 'draft',
				)
			);
			delete_post_meta( $ids[2], '_yume_publie_notifie' );
			delete_post_meta( $ids[3], '_yume_publie_notifie' );
			$t_avant = ( new DateTimeImmutable( $avant, wp_timezone() ) )->getTimestamp();
			update_post_meta(
				$tome,
				Service::META_COMPLET,
				array(
					'ts'       => $t_avant,
					'annoncer' => true,
					'liens'    => array(),
				)
			);
			yume_assert_same( array( 'future', 'future', 'draft', 'draft' ), array_map( 'get_post_status', $ids ) );

			$d1 = yume_tcal_date( 6 );
			$d2 = yume_tcal_date( 13 );
			// Lecteur : refusé.
			$refus = yume_rest( 'POST', '/yume/v1/publications/' . $tome . '/calendrier', array( 'calendrier' => '' ), yume_factory_user( 'subscriber' ) );
			yume_assert_same( 403, $refus->get_status() );
			// Calendrier incomplet : refusé, rien ne change.
			$incomplet = yume_rest( 'POST', '/yume/v1/publications/' . $tome . '/calendrier', array( 'calendrier' => yume_tcal_json( array( array( $d1, array( 'id:' . $ids[0] ) ) ) ) ), $editeur );
			yume_assert_same( 400, $incomplet->get_status() );
			yume_assert_same( yume_tcal_gmt( $avant ), get_post_field( 'post_date_gmt', $ids[0] ) );
			yume_assert_true( is_array( get_post_meta( $tome, Service::META_GROUPE, true ) ), 'sortie groupée intacte' );

			$reponse = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome . '/calendrier',
				array(
					'calendrier' => yume_tcal_json(
						array(
							array( $d1, array( 'id:' . $ids[0] ) ),
							array( $d2, array( 'id:' . $ids[1], 'id:' . $ids[2], 'id:' . $ids[3] ) ),
						)
					),
				),
				$editeur
			);
			yume_assert_same( 200, $reponse->get_status(), wp_json_encode( $reponse->get_data() ) );
			$donnees = $reponse->get_data();
			yume_assert_same( 2, $donnees['publications'] );
			yume_assert_same( 2, $donnees['reprogrammes'] );
			yume_assert_contains( '2 publications', $donnees['message'] );
			yume_assert_same( 4, count( $donnees['programmer']['unites'] ), 'réglages du panneau renvoyés' );
			foreach ( array( $d1, $d2, $d2, $d2 ) as $i => $d ) {
				yume_assert_same( 'future', get_post_status( $ids[ $i ] ) );
				yume_assert_same( yume_tcal_gmt( $d ), get_post_field( 'post_date_gmt', $ids[ $i ] ), 'chapitre ' . ( $i + 1 ) );
			}
			// Ancienne sortie groupée supprimée, nouvelle publication groupée.
			yume_assert_false( metadata_exists( 'post', $tome, Service::META_GROUPE ) );
			yume_assert_false( wp_next_scheduled( Service::HOOK_GROUPE, array( $tome, $t_avant ) ) );
			$t2 = ( new DateTimeImmutable( $d2, wp_timezone() ) )->getTimestamp();
			yume_assert_same( array( (string) $t2 ), array_map( 'strval', array_keys( Calendrier::groupes( $tome ) ) ) );
			yume_assert_false( metadata_exists( 'post', $ids[0], '_yume_publie_notifie' ), 'chapitre seul : marque retirée, core l’annoncera' );
			// Passage « complet » programmé : suit le nouveau dernier chapitre.
			yume_assert_same( $t2, (int) get_post_meta( $tome, Service::META_COMPLET, true )['ts'] );
			// Journal du planning (équipe).
			$table = \Yume\Core\Planning\table_journal();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tome_id = %d AND champ = 'calendrier' ORDER BY id DESC LIMIT 1", $tome ) );
			yume_assert_true( (bool) $ligne, 'ligne de journal' );
			$texte = \Yume\Core\Planning\texte_changement( $ligne, true );
			yume_assert_contains( 'sorties programmées : 2 publications', $texte );
			yume_assert_contains( '2 chapitres reprogrammés', $texte );
			yume_assert_same( '', \Yume\Core\Planning\texte_changement( $ligne, false ), 'jamais public' );

			// Répartition (sans calendrier) : tout en une publication.
			$reponse = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome . '/calendrier',
				array(
					'repartition' => 1,
					'depart'      => yume_tcal_date( 20 ),
				),
				$editeur
			);
			yume_assert_same( 200, $reponse->get_status(), wp_json_encode( $reponse->get_data() ) );
			yume_assert_same( 1, $reponse->get_data()['publications'] );
			yume_assert_same( get_post_field( 'post_date_gmt', $ids[0] ), get_post_field( 'post_date_gmt', $ids[3] ) );

			// Plus rien à programmer.
			list( $vide ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Vide' ), array() );
			$rien         = yume_rest( 'POST', '/yume/v1/publications/' . $vide . '/calendrier', array( 'repartition' => 1 ), $editeur );
			yume_assert_same( 409, $rien->get_status() );
		}
	)
);

yume_test(
	'calendrier : infos_tome() donne les brouillons à sortir et la règle du rythme ; le formulaire propose « Calendrier sur mesure » et le panneau',
	yume_tpub(
		function () {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Infos' ), array( 1, 2 ) );
			update_post_meta( $ids[0], 'yume_nb_mots', 1234 );
			update_post_meta(
				$tome,
				'yume_rythme',
				array(
					'jour'  => 'mercredi',
					'heure' => '20:30',
				)
			);
			$infos = Service::infos_tome( $tome );
			yume_assert_same( array( 'id:' . $ids[0], 'id:' . $ids[1] ), array_column( $infos['a_sortir'], 'cle' ) );
			yume_assert_same( 1234, $infos['a_sortir'][0]['mots'] );
			yume_assert_same( 'Chapitre', $infos['a_sortir'][0]['nature_libelle'] );
			yume_assert_same(
				array(
					'jour'  => 3,
					'heure' => '20:30',
				),
				$infos['rythme_regle']
			);
			yume_assert_same( 2, count( $infos['dates_rythme'] ) );
			yume_assert_contains( 'vue=programmer', $infos['programmer'] );

			$_GET['tome'] = (string) $tome;
			try {
				$html = Formulaire::rendu();
			} finally {
				unset( $_GET['tome'] );
			}
			yume_assert_contains( 'value="calendrier"', $html );
			yume_assert_contains( 'Calendrier sur mesure', $html );
			yume_assert_contains( 'data-yn-programmer ', $html );
			yume_assert_contains( 'name="repartition"', $html );
			yume_assert_contains( 'name="calendrier"', $html );
			yume_assert_contains( 'Programmer plus tard dans la vue dédiée', $html );
			yume_assert_contains( esc_attr( '"cle":"id:' . $ids[0] . '"' ), $html );
		}
	)
);

yume_test(
	'calendrier : vue « Programmer les sorties » (?vue=programmer) : droits, choix du tome, panneau avec brouillons et programmés, lien depuis la fiche du tome ; envoi sans JavaScript',
	yume_tpub(
		function () {
			$editeur            = yume_factory_user( 'yume_editeur' );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Vue' ), array( 1, 2, 3 ) );
			wp_set_current_user( $editeur );
			Service::publier(
				$tome,
				'maintenant',
				array(
					'mode'         => Service::MODE_CHAPITRES,
					'sortie'       => 'calendrier',
					'sans_annonce' => false,
					'calendrier'   => yume_tcal_json( array( array( yume_tcal_date( 5 ), array( 'id:' . $ids[0], 'id:' . $ids[1], 'id:' . $ids[2] ) ) ) ),
				)
			);
			// Un brouillon de plus.
			$autre = yume_factory_post(
				array(
					'post_type'   => 'yume_chapitre',
					'post_title'  => 'Chapitre 4',
					'post_status' => 'draft',
					'menu_order'  => 9,
					'meta_input'  => array(
						'yume_tome_id' => $tome,
						'yume_numero'  => 4,
						'yume_nature'  => 'chapitre',
					),
				)
			);
			yume_assert_true( isset( \Yume\Core\Planning\vues_equipe_ajoutees()['programmer'] ), 'vue déclarée pour un éditeur' );
			$_GET['vue']  = 'programmer';
			$_GET['tome'] = (string) $tome;
			try {
				$html = \Yume\Core\Planning\rendu_vue_programmer();
				yume_assert_contains( 'Programmer les sorties : ', $html );
				yume_assert_contains( 'data-yn-programmer-vue', $html );
				yume_assert_contains( 'name="action" value="yume_programmer_sorties"', $html );
				yume_assert_contains( 'name="_yume_nonce"', $html );
				yume_assert_contains( esc_attr( '"cle":"id:' . $autre . '"' ), $html, 'brouillon' );
				yume_assert_contains( esc_attr( '"cle":"id:' . $ids[0] . '"' ), $html, 'programmé' );
				yume_assert_contains( 'aria-current="page">Programmer les sorties', $html, 'entrée de navigation' );
				yume_assert_true( wp_script_is( Calendrier::POIGNEE, 'enqueued' ) );

				unset( $_GET['tome'] );
				yume_assert_contains( 'name="tome"', \Yume\Core\Planning\rendu_vue_programmer(), 'choix du tome' );

				wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
				$_GET['tome'] = (string) $tome;
				yume_assert_false( isset( \Yume\Core\Planning\vues_equipe_ajoutees()['programmer'] ), 'pas pour un traducteur' );
				$refus = \Yume\Core\Planning\rendu_vue_programmer();
				yume_assert_not_contains( 'data-yn-programmer-vue', $refus );
			} finally {
				unset( $_GET['vue'], $_GET['tome'] );
				wp_dequeue_script( Calendrier::POIGNEE );
				wp_dequeue_style( Calendrier::POIGNEE );
			}

			// Lien depuis la fiche du tome.
			wp_set_current_user( $editeur );
			$fiche = \Yume\Core\Planning\section_chapitres_tome( $tome, static fn() => null );
			yume_assert_contains( esc_url( \Yume\Core\Planning\url_programmer( $tome ) ) . '">Programmer les sorties</a>', $fiche );

			// Envoi sans JavaScript : nonce exigé, puis répartition en 2 publications.
			$post   = array(
				'tome_id'     => (string) $tome,
				'repartition' => '2',
				'annoncer'    => '1',
			);
			$retour = \Yume\Core\Planning\traiter_programmer( $post, $editeur );
			yume_assert_same( 'erreur', $retour['type'] );
			$post['_yume_nonce'] = wp_create_nonce( Calendrier::ACTION . '_' . $tome );
			$retour              = \Yume\Core\Planning\traiter_programmer( $post, $editeur );
			yume_assert_same( 'ok', $retour['type'], $retour['message'] );
			yume_assert_contains( '2 publications', $retour['message'] );
			yume_assert_same( 'future', get_post_status( $autre ) );
			yume_assert_same( 2, Calendrier::nb_publications( array_map( static fn( $id ) => new DateTimeImmutable( get_post_field( 'post_date_gmt', $id ), new DateTimeZone( 'UTC' ) ), array_merge( $ids, array( $autre ) ) ) ) );
		}
	)
);
