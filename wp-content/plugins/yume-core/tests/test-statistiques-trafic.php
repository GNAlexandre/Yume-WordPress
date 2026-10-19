<?php
/**
 * Tests du trafic des statistiques (lot A) : source du trafic (Jetpack, simulation hors
 * production, aucune), normalisation des charges utiles brutes de WordPress.com Stats injectées
 * par le filtre yume_statistiques_requete_stats (visites, contenus regroupés par œuvre, référents,
 * termes de recherche, pays sans ville, vues d'un contenu, historique), erreurs et cache, appel
 * de la classe Jetpack (client simulé), simulation déterministe, route REST /trafic.
 *
 * Aucune requête réseau : un filtre pre_http_request enregistre toute tentative et fait échouer
 * le test. Jetpack n'est pas installé dans l'environnement de test.
 *
 * Lancement : tools/localenv/test.sh statistiques-trafic
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Statistiques\cache_vider;
use function Yume\Core\Statistiques\derniere_erreur_trafic;
use function Yume\Core\Statistiques\jetpack_disponible;
use function Yume\Core\Statistiques\requete_stats;
use function Yume\Core\Statistiques\source_trafic;
use function Yume\Core\Statistiques\trafic_appeler_jetpack;
use function Yume\Core\Statistiques\trafic_cle_cache;
use function Yume\Core\Statistiques\trafic_contenus;
use function Yume\Core\Statistiques\trafic_etat;
use function Yume\Core\Statistiques\trafic_pays;
use function Yume\Core\Statistiques\trafic_post;
use function Yume\Core\Statistiques\trafic_recherches;
use function Yume\Core\Statistiques\trafic_referents;
use function Yume\Core\Statistiques\trafic_resume;
use function Yume\Core\Statistiques\trafic_source_choisie;
use function Yume\Core\Statistiques\trafic_source_finale;
use function Yume\Core\Statistiques\trafic_visites_jour;
use function Yume\Core\Statistiques\trafic_vues_par_oeuvre_jour;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tstrafic_)
 * -----------------------------------------------------------------------------
 */

/**
 * « Maintenant » des tests : 15 septembre 2026, 12 h à Paris (dernier jour des périodes).
 */
function yume_tstrafic_maintenant(): int {
	return (int) strtotime( '2026-09-15 10:00:00 UTC' );
}

/**
 * Exécute $corps avec : fuseau Europe/Paris, maintenant() figé, source du trafic forcée (sauf
 * $source vide), charges utiles brutes injectées par ressource (valeur, WP_Error ou Closure
 * recevant les arguments), cache vidé, et garde réseau (toute requête HTTP fait échouer le test).
 *
 * @param array    $charges Ressource => charge utile.
 * @param callable $corps   Corps ; reçoit l'ArrayObject des appels [ ressource, args ].
 * @param string   $source  Source forcée (« jetpack », « simule », « aucune ») ou '' (naturelle).
 */
function yume_tstrafic( array $charges, callable $corps, string $source = 'jetpack' ): void {
	$appels   = new ArrayObject();
	$reseau   = new ArrayObject();
	$fige     = static fn() => yume_tstrafic_maintenant();
	$fuseau   = static fn() => 'Europe/Paris';
	$forcer   = static fn() => $source;
	$injecter = static function ( $pre, $ressource, $args ) use ( $charges, $appels ) {
		if ( ! array_key_exists( $ressource, $charges ) ) {
			return $pre;
		}
		$appels[] = array( $ressource, $args );
		$charge   = $charges[ $ressource ];
		return $charge instanceof Closure ? $charge( $args ) : $charge;
	};
	$garde    = static function ( $pre, $args, $url ) use ( $reseau ) {
		$reseau[] = $url;
		return new WP_Error( 'yume_test_reseau', 'Requête réseau interdite pendant les tests.' );
	};
	add_filter( 'yume_statistiques_maintenant', $fige );
	add_filter( 'pre_option_timezone_string', $fuseau );
	if ( '' !== $source ) {
		add_filter( 'yume_statistiques_source_trafic', $forcer );
	}
	add_filter( 'yume_statistiques_requete_stats', $injecter, 20, 3 );
	add_filter( 'pre_http_request', $garde, 1, 3 );
	cache_vider();
	trafic_etat( array( 'erreur' => '' ) );
	try {
		$corps( $appels );
	} finally {
		remove_filter( 'yume_statistiques_maintenant', $fige );
		remove_filter( 'pre_option_timezone_string', $fuseau );
		remove_filter( 'yume_statistiques_source_trafic', $forcer );
		remove_filter( 'yume_statistiques_requete_stats', $injecter, 20 );
		remove_filter( 'pre_http_request', $garde, 1 );
		cache_vider();
		trafic_etat( array( 'erreur' => '' ) );
	}
	yume_assert_same( array(), $reseau->getArrayCopy(), 'aucune requête réseau' );
}

/**
 * Œuvre « Lanternes de brume » avec un tome, un chapitre, une page et un article, publiés en 2026.
 *
 * @return array{oeuvre:int,tome:int,chapitre:int,page:int,article:int}
 */
function yume_tstrafic_contenus(): array {
	$date   = '2026-01-10 10:00:00';
	$oeuvre = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Lanternes de brume',
			'post_date'  => $date,
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'  => 'yume_tome',
			'post_title' => 'Tome 1',
			'post_date'  => $date,
			'meta_input' => array( 'yume_oeuvre_id' => $oeuvre ),
		)
	);
	return array(
		'oeuvre'   => $oeuvre,
		'tome'     => $tome,
		'chapitre' => yume_factory_post(
			array(
				'post_type'  => 'yume_chapitre',
				'post_title' => 'Chapitre 1',
				'post_date'  => $date,
				'meta_input' => array(
					'yume_oeuvre_id' => $oeuvre,
					'yume_tome_id'   => $tome,
				),
			)
		),
		'page'     => yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'À propos',
				'post_date'  => $date,
			)
		),
		'article'  => yume_factory_post(
			array(
				'post_title' => 'Annonce',
				'post_date'  => $date,
			)
		),
	);
}

/**
 * Charge utile brute de visits (unité jour) : 14 jours du 2 au 15 septembre 2026, vues 10 × rang,
 * visiteurs = rang ; le 12 septembre manque ; colonnes dans le désordre ; lignes parasites.
 */
function yume_tstrafic_visites_jours(): array {
	$data = array();
	for ( $i = 0; $i < 14; $i++ ) {
		$jour = gmdate( 'Y-m-d', strtotime( '2026-09-02 12:00:00 UTC' ) + $i * DAY_IN_SECONDS );
		if ( '2026-09-12' !== $jour ) {
			$data[] = array( $jour, $i + 1, 10 * ( $i + 1 ), 0 );
		}
	}
	$data[] = array( '2026-08-01', 999, 999, 0 ); // Hors périodes : ignorée.
	$data[] = array( 'abc', 5, 5, 0 );            // Période illisible : ignorée.
	$data[] = 'ligne invalide';
	return array(
		'date'      => '2026-09-15',
		'unit'      => 'day',
		'fields'    => array( 'period', 'visitors', 'views', 'likes' ),
		'data'      => $data,
		'cached_at' => 1789000000,
	);
}

/*
 * -----------------------------------------------------------------------------
 * Source du trafic
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques trafic : source jetpack / simule (hors production) / aucune (production), filtre',
	function () {
		yume_assert_same( 'jetpack', trafic_source_choisie( true, 'production' ) );
		yume_assert_same( 'jetpack', trafic_source_choisie( true, 'local' ) );
		yume_assert_same( 'aucune', trafic_source_choisie( false, 'production' ) );
		yume_assert_same( 'simule', trafic_source_choisie( false, 'local' ) );
		yume_assert_same( 'simule', trafic_source_choisie( false, 'staging' ) );
		yume_assert_same( 'simule', trafic_source_choisie( false, 'development' ) );

		// Filtre : valeurs inconnues refusées, jamais de simulation en production.
		yume_assert_same( 'aucune', trafic_source_finale( 'simule', 'production' ) );
		yume_assert_same( 'simule', trafic_source_finale( 'simule', 'local' ) );
		yume_assert_same( 'jetpack', trafic_source_finale( 'jetpack', 'production' ) );
		yume_assert_same( 'aucune', trafic_source_finale( 'ville', 'local' ) );
		yume_assert_same( 'aucune', trafic_source_finale( array( 'jetpack' ), 'local' ) );
		yume_assert_same( 'aucune', trafic_source_finale( null, 'local' ) );

		// Jetpack n'est pas installé dans l'environnement de test : source naturelle simulée.
		if ( ! class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			yume_assert_false( jetpack_disponible(), 'Jetpack absent' );
		}
		yume_assert_same( trafic_source_choisie( jetpack_disponible(), wp_get_environment_type() ), source_trafic() );
		if ( 'production' !== wp_get_environment_type() && ! jetpack_disponible() ) {
			yume_assert_same( 'simule', source_trafic(), 'hors production sans Jetpack' );
		}

		foreach ( array( 'jetpack', 'simule', 'aucune' ) as $source ) {
			$forcer = static fn() => $source;
			add_filter( 'yume_statistiques_source_trafic', $forcer );
			try {
				yume_assert_same( 'production' === wp_get_environment_type() && 'simule' === $source ? 'aucune' : $source, source_trafic() );
			} finally {
				remove_filter( 'yume_statistiques_source_trafic', $forcer );
			}
		}
		$inconnue = static fn() => 'google-analytics';
		add_filter( 'yume_statistiques_source_trafic', $inconnue );
		try {
			yume_assert_same( 'aucune', source_trafic() );
		} finally {
			remove_filter( 'yume_statistiques_source_trafic', $inconnue );
		}
	}
);

yume_test(
	'statistiques trafic : source « aucune » → toutes les fonctions renvoient null, sans appel',
	function () {
		yume_tstrafic(
			array(
				'visits'    => yume_tstrafic_visites_jours(),
				'top-posts' => array( 'summary' => array( 'postviews' => array() ) ),
			),
			function ( ArrayObject $appels ) {
				yume_assert_same( null, trafic_resume( 30 ) );
				yume_assert_same( null, trafic_contenus( 30 ) );
				yume_assert_same( null, trafic_referents( 30 ) );
				yume_assert_same( null, trafic_recherches( 30 ) );
				yume_assert_same( null, trafic_pays( 30 ) );
				yume_assert_same( null, trafic_post( 12, 30 ) );
				yume_assert_same( null, trafic_vues_par_oeuvre_jour( '2026-09-14' ) );
				yume_assert_same( null, trafic_visites_jour( '2026-09-14' ) );
				yume_assert_same( 0, count( $appels ), 'aucune requête' );
			},
			'aucune'
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Normalisation des charges utiles brutes
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques trafic : trafic_resume() par jour (somme, période précédente, variation, jours manquants)',
	function () {
		yume_tstrafic(
			array( 'visits' => yume_tstrafic_visites_jours() ),
			function ( ArrayObject $appels ) {
				$r = trafic_resume( 7 );
				yume_assert_same(
					array( 'source', 'unite', 'vues', 'visiteurs', 'vues_prec', 'visiteurs_prec', 'variation_vues', 'variation_visiteurs', 'serie', 'calcule' ),
					array_keys( $r )
				);
				yume_assert_same( 'jetpack', $r['source'] );
				yume_assert_same( 'jour', $r['unite'] );
				// Du 9 au 15 septembre : rangs 8 à 14 sans le 12 (rang 11).
				yume_assert_same( 660, $r['vues'] );
				yume_assert_same( 66, $r['visiteurs'] );
				// Du 2 au 8 septembre : rangs 1 à 7.
				yume_assert_same( 280, $r['vues_prec'] );
				yume_assert_same( 28, $r['visiteurs_prec'] );
				yume_assert_same( 135.7, $r['variation_vues'] );
				yume_assert_same( 135.7, $r['variation_visiteurs'] );
				yume_assert_same( 7, count( $r['serie'] ) );
				yume_assert_same(
					array(
						'date'      => '2026-09-09',
						'vues'      => 80,
						'visiteurs' => 8,
					),
					$r['serie'][0]
				);
				yume_assert_same(
					array(
						'date'      => '2026-09-12',
						'vues'      => 0,
						'visiteurs' => 0,
					),
					$r['serie'][3],
					'jour absent : 0'
				);
				yume_assert_same( '2026-09-15', $r['serie'][6]['date'] );
				yume_assert_same( yume_tstrafic_maintenant(), $r['calcule'] );
				yume_assert_same(
					array(
						'visits',
						array(
							'unit'        => 'day',
							'quantity'    => 14,
							'date'        => '2026-09-15',
							'stat_fields' => 'views,visitors',
						),
					),
					$appels[0]
				);
			}
		);
	}
);

yume_test(
	'statistiques trafic : trafic_resume( 365 ) par mois (24 mois coupés en deux, périodes « Y-m » ou « Y-m-d »)',
	function () {
		$data = array();
		for ( $i = 0; $i < 24; $i++ ) {
			$mois   = gmdate( 'Y-m', strtotime( '2024-10-01 12:00:00 UTC +' . $i . ' months' ) );
			$data[] = array( 0 === $i % 2 ? $mois . '-01' : $mois, $i < 12 ? 100 : 150, $i < 12 ? 10 : 15 );
		}
		yume_tstrafic(
			array(
				'visits' => array(
					'unit'   => 'month',
					'fields' => array( 'period', 'views', 'visitors' ),
					'data'   => $data,
				),
			),
			function ( ArrayObject $appels ) {
				$r = trafic_resume( 365 );
				yume_assert_same( 'mois', $r['unite'] );
				yume_assert_same( 1800, $r['vues'] );
				yume_assert_same( 1200, $r['vues_prec'] );
				yume_assert_same( 180, $r['visiteurs'] );
				yume_assert_same( 50.0, $r['variation_vues'] );
				yume_assert_same( 12, count( $r['serie'] ) );
				yume_assert_same( '2025-10-01', $r['serie'][0]['date'] );
				yume_assert_same( '2026-09-01', $r['serie'][11]['date'] );
				yume_assert_same( 'month', $appels[0][1]['unit'] );
				yume_assert_same( 24, $appels[0][1]['quantity'] );
			}
		);
	}
);

yume_test(
	'statistiques trafic : trafic_contenus() (titres locaux, types, regroupement par œuvre, totaux)',
	function () {
		$c = yume_tstrafic_contenus();
		yume_tstrafic(
			array(
				'top-posts' => array(
					'date'    => '2026-09-15',
					'period'  => 'day',
					'summary' => array(
						'postviews'   => array(
							array(
								'id'    => $c['oeuvre'],
								'title' => 'Ancien titre',
								'views' => 100,
								'href'  => 'https://exemple.test/ancien/',
								'type'  => 'post',
							),
							array(
								'id'    => $c['tome'],
								'views' => 50,
							),
							array(
								'id'    => $c['chapitre'],
								'views' => 30,
							),
							array(
								'id'    => $c['page'],
								'views' => 20,
							),
							array(
								'id'    => 0,
								'title' => 'Home page / Archives',
								'views' => 40,
								'href'  => 'https://yumenovel.fr/',
								'type'  => 'homepage',
							),
							array(
								'id'    => 999999,
								'title' => 'Supprimé &amp; <b>vieux</b>',
								'views' => '10',
								'href'  => 'https://yumenovel.fr/vieux/',
							),
							array(
								'id'    => $c['chapitre'],
								'views' => 5,
							),
							'parasite',
						),
						'total_views' => 300,
					),
				),
			),
			function ( ArrayObject $appels ) use ( $c ) {
				$r = trafic_contenus( 30, 3 );
				yume_assert_same( array( 'source', 'contenus', 'oeuvres', 'autres_vues', 'total_vues' ), array_keys( $r ) );
				yume_assert_same( 'jetpack', $r['source'] );
				yume_assert_same( 3, count( $r['contenus'] ), 'top coupé à $max' );
				yume_assert_same(
					array(
						'post_id'   => $c['oeuvre'],
						'titre'     => 'Lanternes de brume',
						'url'       => get_permalink( $c['oeuvre'] ),
						'type'      => 'oeuvre',
						'oeuvre_id' => $c['oeuvre'],
						'vues'      => 100,
					),
					$r['contenus'][0],
					'titre et adresse locaux'
				);
				yume_assert_same( array( 'tome', 50, $c['oeuvre'] ), array( $r['contenus'][1]['type'], $r['contenus'][1]['vues'], $r['contenus'][1]['oeuvre_id'] ) );
				yume_assert_same( array( 0, 'autre', 0, 40, 'https://yumenovel.fr/' ), array( $r['contenus'][2]['post_id'], $r['contenus'][2]['type'], $r['contenus'][2]['oeuvre_id'], $r['contenus'][2]['vues'], $r['contenus'][2]['url'] ) );

				// Regroupement : fiche 100 + tome 50 + chapitre 30 + 5 (lignes fusionnées).
				yume_assert_same(
					array(
						array(
							'oeuvre_id' => $c['oeuvre'],
							'titre'     => 'Lanternes de brume',
							'url'       => get_permalink( $c['oeuvre'] ),
							'vues'      => 185,
						),
					),
					$r['oeuvres']
				);
				yume_assert_same( 300, $r['total_vues'], 'total de l’API' );
				yume_assert_same( 115, $r['autres_vues'], 'hors œuvres' );
				yume_assert_same(
					array(
						'period'    => 'day',
						'num'       => 30,
						'date'      => '2026-09-15',
						'summarize' => 1,
						'max'       => 100,
					),
					$appels[0][1],
					'arguments de top-posts'
				);

				// Top complet : chapitre fusionné, page, contenu supprimé (titre de l'API nettoyé).
				$tout = trafic_contenus( 30, 50 );
				yume_assert_same( 6, count( $tout['contenus'] ) );
				$par_id = array_column( $tout['contenus'], null, 'post_id' );
				yume_assert_same( array( 'chapitre', 35 ), array( $par_id[ $c['chapitre'] ]['type'], $par_id[ $c['chapitre'] ]['vues'] ) );
				yume_assert_same( array( 'page', 0 ), array( $par_id[ $c['page'] ]['type'], $par_id[ $c['page'] ]['oeuvre_id'] ) );
				yume_assert_same(
					array(
						'post_id'   => 999999,
						'titre'     => 'Supprimé & vieux',
						'url'       => 'https://yumenovel.fr/vieux/',
						'type'      => 'autre',
						'oeuvre_id' => 0,
						'vues'      => 10,
					),
					$par_id[999999]
				);
			}
		);
	}
);

yume_test(
	'statistiques trafic : trafic_referents() (groupes, résultats aplatis, fusion, top et total)',
	function () {
		yume_tstrafic(
			array(
				'referrers' => array(
					'summary' => array(
						'groups'      => array(
							array(
								'name'    => 'Google',
								'url'     => 'https://www.google.com/',
								'total'   => 300,
								'results' => array(
									array(
										'name'  => 'google.com',
										'views' => 200,
									),
									array(
										'name'  => 'google.fr',
										'views' => 100,
									),
								),
							),
							array(
								'name'    => 'Discord',
								'results' => array(
									array(
										'name'     => 'discord.com',
										'url'      => 'https://discord.com/channels/1',
										'views'    => 50,
										'children' => array(
											array(
												'name'  => 'salon',
												'views' => 50,
											),
										),
									),
								),
							),
							array(
								'name'    => 'Ancien format',
								'results' => array(
									'a.example' => 5,
									'b.example' => 7,
								),
							),
							array(
								'name'    => 'Profond',
								'results' => array(
									array(
										'name'     => 'x',
										'children' => array(
											array(
												'name'  => 'y',
												'views' => 4,
											),
											array(
												'name'  => 'z',
												'views' => 6,
											),
										),
									),
								),
							),
							array(
								'name'  => 'Google',
								'total' => 20,
							),
						),
						'total_views' => 400,
					),
				),
			),
			function ( ArrayObject $appels ) {
				$r = trafic_referents( 30, 3 );
				yume_assert_same(
					array(
						'source'    => 'jetpack',
						'referents' => array(
							array(
								'nom'  => 'Google',
								'url'  => 'https://www.google.com/',
								'vues' => 320,
							),
							array(
								'nom'  => 'Discord',
								'url'  => 'https://discord.com/channels/1',
								'vues' => 50,
							),
							array(
								'nom'  => 'Ancien format',
								'url'  => '',
								'vues' => 12,
							),
						),
						'total'     => 400,
					),
					$r
				);
				yume_assert_same( 'referrers', $appels[0][0] );
				yume_assert_same( 100, $appels[0][1]['max'], 'au moins 100 lignes demandées' );
				yume_assert_same( 1, $appels[0][1]['summarize'] );
			}
		);
	}
);

yume_test(
	'statistiques trafic : trafic_recherches() (termes fusionnés et nettoyés, termes chiffrés)',
	function () {
		yume_tstrafic(
			array(
				'search-terms' => array(
					'summary' => array(
						'search_terms'           => array(
							array(
								'term'  => 'yume novel',
								'views' => 40,
							),
							array(
								'term'  => '<b>lanternes</b> vf',
								'views' => 10,
							),
							array(
								'term'  => '',
								'views' => 3,
							),
							array(
								'term'  => 'yume novel',
								'views' => 2,
							),
						),
						'encrypted_search_terms' => 25,
					),
				),
			),
			function () {
				yume_assert_same(
					array(
						'source'   => 'jetpack',
						'termes'   => array(
							array(
								'terme' => 'yume novel',
								'vues'  => 42,
							),
							array(
								'terme' => 'lanternes vf',
								'vues'  => 10,
							),
						),
						'chiffres' => 25,
						'total'    => 77,
					),
					trafic_recherches( 30 )
				);
			}
		);
	}
);

yume_test(
	'statistiques trafic : trafic_pays() (codes ISO, noms, inconnus dans « autres », jamais de ville)',
	function () {
		yume_tstrafic(
			array(
				'country-views' => array(
					'summary'      => array(
						'views' => array(
							array(
								'country_code' => 'FR',
								'views'        => 100,
							),
							array(
								'country_code' => 'BE',
								'views'        => 20,
							),
							array(
								'country_code' => 'ZZ',
								'views'        => 5,
							),
							array(
								'country_code' => 'A1',
								'views'        => 3,
							),
							array(
								'country_code' => 'DE',
								'views'        => 10,
							),
							array(
								'country_code' => 'fr',
								'views'        => 1,
							),
							array(
								'country_code' => 'XYZ',
								'views'        => 2,
							),
						),
					),
					'country-info' => array(
						'FR' => array( 'country_full' => 'France' ),
						'BE' => array( 'country_full' => 'Belgium' ),
						'DE' => array( 'country_full' => 'Germany' ),
					),
				),
			),
			function ( ArrayObject $appels ) {
				$r = trafic_pays( 30, 2 );
				yume_assert_same( array( 'source', 'pays', 'autres', 'total' ), array_keys( $r ) );
				yume_assert_same( 2, count( $r['pays'] ) );
				foreach ( $r['pays'] as $pays ) {
					yume_assert_same( array( 'code', 'nom', 'vues' ), array_keys( $pays ), 'code, nom, vues seulement' );
				}
				yume_assert_same( array( 'FR', 'France', 101 ), array_values( $r['pays'][0] ) );
				yume_assert_same( array( 'BE', 20 ), array( $r['pays'][1]['code'], $r['pays'][1]['vues'] ) );
				yume_assert_true( in_array( $r['pays'][1]['nom'], array( 'Belgique', 'Belgium' ), true ), 'nom localisé ou de l’API' );
				yume_assert_same( 141, $r['total'] );
				yume_assert_same( 20, $r['autres'], 'DE 10 + ZZ 5 + A1 3 + XYZ 2' );
				yume_assert_same( array( 'country-views' ), array_unique( array_column( $appels->getArrayCopy(), 0 ) ), 'jamais location-views' );
			}
		);
	}
);

yume_test(
	'statistiques trafic : trafic_post(), trafic_vues_par_oeuvre_jour() (format days) et trafic_visites_jour()',
	function () {
		$c      = yume_tstrafic_contenus();
		$autre  = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Autre œuvre',
			)
		);
		$tome   = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'meta_input' => array( 'yume_oeuvre_id' => $autre ),
			)
		);
		$autres = yume_factory_post(
			array(
				'post_type'  => 'yume_chapitre',
				'meta_input' => array(
					'yume_oeuvre_id' => $autre,
					'yume_tome_id'   => $tome,
				),
			)
		);
		yume_tstrafic(
			array(
				'post/' . $c['chapitre'] => array(
					'views'  => 60,
					'fields' => array( 'period', 'views' ),
					'data'   => array(
						array( '2026-09-01', 30 ),
						array( '2026-09-10', 7 ),
						array( '2026-09-15', 3 ),
						array( 'n/a', 9 ),
					),
				),
				'top-posts'              => array(
					'days' => array(
						'2026-09-14' => array(
							'postviews' => array(
								array(
									'id'    => $c['tome'],
									'views' => 3,
								),
								array(
									'id'    => $autres,
									'views' => 2,
								),
								array(
									'id'    => 0,
									'views' => 50,
								),
							),
						),
						'2026-09-13' => array(
							'postviews' => array(
								array(
									'id'    => $c['chapitre'],
									'views' => 4,
								),
							),
						),
					),
				),
				'visits'                 => array(
					'fields' => array( 'period', 'views', 'visitors' ),
					'data'   => array( array( '2026-09-14', 321, 123 ) ),
				),
			),
			function ( ArrayObject $appels ) use ( $c, $autre ) {
				$r = trafic_post( $c['chapitre'], 7 );
				yume_assert_same( 'jetpack', $r['source'] );
				yume_assert_same( 10, $r['vues'], '9 au 15 septembre' );
				yume_assert_same( 7, count( $r['serie'] ) );
				yume_assert_same(
					array(
						'date' => '2026-09-10',
						'vues' => 7,
					),
					$r['serie'][1]
				);
				yume_assert_same( null, trafic_post( 0, 7 ) );

				$vues = trafic_vues_par_oeuvre_jour( '2026-09-14' );
				yume_assert_same( 7, $vues[ $c['oeuvre'] ] ?? null );
				yume_assert_same( 2, $vues[ $autre ] ?? null );
				yume_assert_same( 2, count( $vues ), 'accueil exclu' );
				yume_assert_same(
					array(
						'period'    => 'day',
						'num'       => 1,
						'date'      => '2026-09-14',
						'summarize' => 1,
						'max'       => 100,
					),
					$appels[1][1]
				);
				yume_assert_same( null, trafic_vues_par_oeuvre_jour( '2026-02-30' ) );
				yume_assert_same( null, trafic_vues_par_oeuvre_jour( 'hier' ) );

				yume_assert_same(
					array(
						'vues'      => 321,
						'visiteurs' => 123,
					),
					trafic_visites_jour( '2026-09-14' )
				);
				yume_assert_same(
					array(
						'vues'      => 0,
						'visiteurs' => 0,
					),
					trafic_visites_jour( '2026-09-13' ),
					'jour absent de la réponse'
				);
				yume_assert_same( 1, $appels[2][1]['quantity'] );
				yume_assert_same( null, trafic_visites_jour( '15/09/2026' ) );
			}
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Erreurs et cache
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques trafic : WP_Error → null, derniere_erreur_trafic(), cache court (2 min) ; succès en cache 15 min',
	function () {
		yume_tstrafic(
			array(
				'visits'    => new WP_Error( 'site_not_connected', 'Le site n’est pas connecté à WordPress.com.' ),
				'top-posts' => array( 'summary' => array( 'postviews' => array() ) ),
				'referrers' => 'réponse texte',
				'summary'   => array(
					'error'   => 'unknown_blog',
					'message' => 'Blog inconnu',
				),
			),
			function ( ArrayObject $appels ) {
				yume_assert_same( '', derniere_erreur_trafic() );
				yume_assert_same( null, trafic_resume( 7 ) );
				yume_assert_same( 'Le site n’est pas connecté à WordPress.com.', derniere_erreur_trafic() );
				trafic_etat( array( 'erreur' => '' ) );
				yume_assert_same( null, trafic_resume( 7 ), 'erreur servie par le cache' );
				yume_assert_same( 1, count( $appels ), 'une seule requête' );
				yume_assert_same( 'Le site n’est pas connecté à WordPress.com.', derniere_erreur_trafic(), 'message relu dans le cache' );
				$args  = array(
					'unit'        => 'day',
					'quantity'    => 14,
					'date'        => '2026-09-15',
					'stat_fields' => 'views,visitors',
				);
				$duree = (int) get_option( '_transient_timeout_yume_stats_' . trafic_cle_cache( 'jetpack', 'visits', $args ) ) - time();
				yume_assert_true( $duree > 0 && $duree <= 120, 'erreur en cache 2 minutes : ' . $duree );
				$erreur = requete_stats( 'visits', $args );
				yume_assert_true( is_wp_error( $erreur ) );
				yume_assert_same( 'site_not_connected', $erreur->get_error_code() );

				// Succès : 15 minutes, réutilisé ; autre période → autre requête.
				trafic_etat( array( 'erreur' => '' ) );
				$a = trafic_contenus( 30 );
				$b = trafic_contenus( 30 );
				yume_assert_same( $a, $b );
				yume_assert_same( 0, $a['total_vues'] );
				yume_assert_same( 2, count( $appels ), 'contenus : une requête pour deux appels' );
				$args  = array(
					'period'    => 'day',
					'num'       => 30,
					'date'      => '2026-09-15',
					'summarize' => 1,
					'max'       => 100,
				);
				$duree = (int) get_option( '_transient_timeout_yume_stats_' . trafic_cle_cache( 'jetpack', 'top-posts', $args ) ) - time();
				yume_assert_true( $duree > 120 && $duree <= 900, 'succès en cache 15 minutes : ' . $duree );
				trafic_contenus( 7 );
				yume_assert_same( 3, count( $appels ) );
				yume_assert_same( '', derniere_erreur_trafic(), 'aucune erreur sur un succès' );

				// Réponses invalides.
				yume_assert_same( null, trafic_referents( 30 ) );
				yume_assert_true( '' !== derniere_erreur_trafic() );
				$invalide = requete_stats( 'referrers', array( 'max' => 1 ) );
				yume_assert_same( 'yume_trafic_reponse_invalide', $invalide->get_error_code() );
				$api = requete_stats( 'summary', array() );
				yume_assert_same( 'yume_trafic_unknown_blog', $api->get_error_code() );
				yume_assert_same( 'Blog inconnu', derniere_erreur_trafic() );
			}
		);
	}
);

yume_test(
	'statistiques trafic : source jetpack sans Jetpack installé → erreur propre, aucune requête réseau',
	function () {
		if ( class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			return; // Jetpack présent : cas non applicable.
		}
		yume_tstrafic(
			array(),
			function () {
				yume_assert_same( null, trafic_resume( 30 ) );
				yume_assert_same( null, trafic_pays( 30 ) );
				yume_assert_same( 'yume_trafic_jetpack_absent', requete_stats( 'visits', array() )->get_error_code() );
				yume_assert_true( '' !== derniere_erreur_trafic() );
			}
		);
	}
);

yume_test(
	'statistiques trafic : appel de WPCOM_Stats (méthodes, post/{id}, WP_Error, exception, ressource inconnue)',
	function () {
		$client = new class() {
			/**
			 * Appels reçus.
			 *
			 * @var array
			 */
			public $appels = array();

			/**
			 * Visites.
			 *
			 * @param array $args Arguments.
			 */
			public function get_visits( $args = array() ) {
				$this->appels[] = array( 'get_visits', $args ); // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundOutsideClass -- méthode d'une classe anonyme.
				return array(
					'fields' => array( 'period', 'views' ),
					'data'   => array(),
				);
			}

			/**
			 * Vues d'un contenu.
			 *
			 * @param int   $post_id       Contenu.
			 * @param array $args          Arguments.
			 * @param bool  $cache_in_meta Cache en méta.
			 */
			public function get_post_views( $post_id, $args = array(), $cache_in_meta = false ) {
				$this->appels[] = array( 'get_post_views', $post_id, $args, $cache_in_meta ); // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundOutsideClass -- méthode d'une classe anonyme.
				return new WP_Error( 'invalid_blog', 'Blog invalide' );
			}

			/**
			 * Contenus les plus vus : panne.
			 *
			 * @param array $args           Arguments.
			 * @param bool  $override_cache Sans cache.
			 * @throws RuntimeException Toujours.
			 */
			public function get_top_posts( $args = array(), $override_cache = false ) {
				unset( $args, $override_cache );
				throw new RuntimeException( 'panne simulée' );
			}
		};
		$r      = trafic_appeler_jetpack( 'visits', array( 'unit' => 'day' ), $client );
		yume_assert_same( array( 'period', 'views' ), $r['fields'] );
		yume_assert_same( array( 'get_visits', array( 'unit' => 'day' ) ), $client->appels[0] );
		$r = trafic_appeler_jetpack( 'post/42', array(), $client );
		yume_assert_same( 'invalid_blog', $r->get_error_code() );
		yume_assert_same( array( 'get_post_views', 42, array(), false ), $client->appels[1] );
		$r = trafic_appeler_jetpack( 'top-posts', array(), $client );
		yume_assert_same( 'yume_trafic_exception', $r->get_error_code() );
		yume_assert_contains( 'panne simulée', $r->get_error_message() );
		yume_assert_same( 'yume_trafic_methode_absente', trafic_appeler_jetpack( 'referrers', array(), $client )->get_error_code() );
		yume_assert_same( 'yume_trafic_ressource_inconnue', trafic_appeler_jetpack( 'location-views/city', array(), $client )->get_error_code() );
		if ( ! class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			yume_assert_same( 'yume_trafic_jetpack_absent', trafic_appeler_jetpack( 'visits', array() )->get_error_code() );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Simulation
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques trafic : simulation déterministe hors production, étiquetée « simule », contenus du site',
	function () {
		if ( 'production' === wp_get_environment_type() ) {
			return; // La simulation n'existe pas en production.
		}
		$c = yume_tstrafic_contenus();
		yume_tstrafic(
			array(),
			function () use ( $c ) {
				$r = trafic_resume( 30 );
				yume_assert_same( 'simule', $r['source'] );
				yume_assert_same( 30, count( $r['serie'] ) );
				yume_assert_true( $r['vues'] > 0 && $r['visiteurs'] > 0 && $r['visiteurs'] < $r['vues'] );
				yume_assert_true( $r['vues_prec'] > 0 );
				cache_vider();
				yume_assert_same( $r, trafic_resume( 30 ), 'déterministe' );
				$jour = array_column( $r['serie'], null, 'date' )['2026-09-14'];
				yume_assert_same(
					array(
						'vues'      => $jour['vues'],
						'visiteurs' => $jour['visiteurs'],
					),
					trafic_visites_jour( '2026-09-14' ),
					'même jour, même valeur'
				);

				$an = trafic_resume( 365 );
				yume_assert_same( array( 'simule', 'mois', 12 ), array( $an['source'], $an['unite'], count( $an['serie'] ) ) );

				$contenus = trafic_contenus( 30 );
				yume_assert_same( 'simule', $contenus['source'] );
				$par_id = array_column( $contenus['contenus'], null, 'post_id' );
				foreach ( array( 'oeuvre', 'tome', 'chapitre' ) as $type ) {
					yume_assert_same( $type, $par_id[ $c[ $type ] ]['type'] ?? null, $type . ' simulé' );
				}
				$oeuvres = array_column( $contenus['oeuvres'], 'vues', 'oeuvre_id' );
				yume_assert_true( ( $oeuvres[ $c['oeuvre'] ] ?? 0 ) > 0, 'œuvre regroupée' );
				yume_assert_true( $contenus['total_vues'] >= array_sum( array_column( $contenus['contenus'], 'vues' ) ) );

				$jour = trafic_vues_par_oeuvre_jour( '2026-09-14' );
				yume_assert_true( is_int( $jour[ $c['oeuvre'] ] ?? null ) && $jour[ $c['oeuvre'] ] > 0 );
				yume_assert_false( isset( trafic_vues_par_oeuvre_jour( '2025-12-01' )[ $c['oeuvre'] ] ), 'aucune vue avant la publication' );

				$pays = trafic_pays( 30, 5 );
				yume_assert_same( 'simule', $pays['source'] );
				yume_assert_same( 5, count( $pays['pays'] ) );
				yume_assert_same( 'FR', $pays['pays'][0]['code'] );
				foreach ( $pays['pays'] as $ligne ) {
					yume_assert_same( array( 'code', 'nom', 'vues' ), array_keys( $ligne ) );
				}
				yume_assert_true( $pays['autres'] > 0, 'pays hors du top dans « autres »' );
				yume_assert_same( $pays['total'], $pays['autres'] + array_sum( array_column( $pays['pays'], 'vues' ) ) );

				$referents = trafic_referents( 30 );
				yume_assert_same( array( 'simule', 'Google' ), array( $referents['source'], $referents['referents'][0]['nom'] ) );
				$recherches = trafic_recherches( 30 );
				yume_assert_true( in_array( 'yume novel', array_column( $recherches['termes'], 'terme' ), true ) );
				yume_assert_true( $recherches['chiffres'] > 0 );

				$post = trafic_post( $c['chapitre'], 7 );
				yume_assert_same( array( 'simule', 7 ), array( $post['source'], count( $post['serie'] ) ) );
				yume_assert_true( $post['vues'] > 0 );
				yume_assert_same( '', derniere_erreur_trafic() );
			},
			'simule'
		);

		// Filtre de simulation : réponse brute seulement si rien n'a déjà répondu et si la source est
		// « simule » (jamais pour Jetpack).
		$forcer = static fn() => 'simule';
		add_filter( 'yume_statistiques_source_trafic', $forcer );
		try {
			$brute = \Yume\Core\Statistiques\simule_requete_stats( null, 'visits', array( 'quantity' => 3 ) );
			yume_assert_same( array( 'period', 'views', 'visitors' ), $brute['fields'] ?? null );
			yume_assert_same( 3, count( $brute['data'] ) );
			yume_assert_same( array( 'deja' ), \Yume\Core\Statistiques\simule_requete_stats( array( 'deja' ), 'visits', array() ) );
		} finally {
			remove_filter( 'yume_statistiques_source_trafic', $forcer );
		}
		$forcer = static fn() => 'jetpack';
		add_filter( 'yume_statistiques_source_trafic', $forcer );
		try {
			yume_assert_same( null, \Yume\Core\Statistiques\simule_requete_stats( null, 'visits', array() ) );
		} finally {
			remove_filter( 'yume_statistiques_source_trafic', $forcer );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Route REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques trafic REST : /trafic renvoie chaque section (simulée), ou { source: aucune } sans trafic',
	function () {
		$traducteur = yume_factory_user( 'yume_traducteur' );
		if ( 'production' !== wp_get_environment_type() ) {
			yume_tstrafic(
				array(),
				function () use ( $traducteur ) {
					foreach ( array( 'contenus', 'referents', 'recherches', 'pays' ) as $section ) {
						$r = yume_rest( 'GET', '/yume/v1/statistiques/trafic', array( 'section' => $section ), $traducteur );
						yume_assert_same( 200, $r->get_status(), $section );
						yume_assert_same( 'simule', $r->get_data()['source'] ?? null, $section );
					}
				},
				'simule'
			);
		}
		yume_tstrafic(
			array(),
			function () use ( $traducteur ) {
				$r = yume_rest( 'GET', '/yume/v1/statistiques/trafic', array( 'section' => 'pays' ), $traducteur );
				yume_assert_same( 200, $r->get_status() );
				yume_assert_same( 'aucune', $r->get_data()['source'] ?? null );
			},
			'aucune'
		);
	}
);
