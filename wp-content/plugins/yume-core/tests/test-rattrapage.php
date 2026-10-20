<?php
/**
 * Tests du module rattrapage (lot L5) : calcul des chapitres à rattraper (position, seuil de
 * 90 %, nouveaux depuis la dernière lecture, tri, tome qui commence, brouillons et œuvres
 * dépubliées ignorés), cache par membre et invalidation, bloc yume/rattrapage « Reprendre la
 * lecture » (visiteur : chaîne vide stricte ; membre : dernière lecture puis séries à rattraper,
 * 3 cartes au plus ; bandeau du bas de l'accueil en repli « Communauté »), dates au format du
 * site, modèle de l'accueil, rubrique « À rattraper » du compte et route GET /yume/v1/moi/rattrapage.
 *
 * Lancement : tools/localenv/test.sh rattrapage
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Rattrapage\date_courte;
use function Yume\Core\Rattrapage\donnees_accueil;
use function Yume\Core\Rattrapage\invalider_tous;
use function Yume\Core\Rattrapage\oublier_reprises;
use function Yume\Core\Rattrapage\rattrapage;
use function Yume\Core\Rattrapage\reprise_affichee;
use function Yume\Core\Rattrapage\rendu_cartes;
use function Yume\Core\Rattrapage\version;
use function Yume\Core\Reader\enregistrer_progression;
use function Yume\Core\Reader\table_progression;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_rat_)
 * -----------------------------------------------------------------------------
 */

/**
 * Crée une œuvre publiée, ses tomes et leurs chapitres publiés, sans événement de sortie.
 * Chaque chapitre est daté : le n-ième chapitre créé (dans l'ordre de lecture) l'est au jour
 * $debut + n (GMT, 12:00).
 *
 * @param array<int,int> $tomes Numéro du tome => nombre de chapitres.
 * @param string         $debut Date « Y-m-d » du jour 0.
 * @param string         $titre Titre de l'œuvre.
 * @return array{oeuvre:int,tomes:array<int,int>,chapitres:array<int,int[]>,dates:array<int,string>}
 */
function yume_rat_serie( array $tomes, string $debut = '2026-01-01', string $titre = '' ): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		$oeuvre    = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => '' !== $titre ? $titre : 'Série ' . wp_rand( 1, 999999 ),
			)
		);
		$ids_tomes = array();
		$chapitres = array();
		$dates     = array();
		$jour      = 0;
		ksort( $tomes );
		foreach ( $tomes as $numero => $nb ) {
			$tome                 = yume_factory_post(
				array(
					'post_type'  => 'yume_tome',
					'post_title' => 'Tome ' . $numero,
					'post_name'  => 'tome-' . $numero,
					'meta_input' => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => $numero,
						'yume_nature'    => 'tome',
					),
				)
			);
			$ids_tomes[ $numero ] = $tome;
			for ( $i = 1; $i <= $nb; $i++ ) {
				++$jour;
				$date                   = gmdate( 'Y-m-d 12:00:00', (int) strtotime( $debut . ' UTC' ) + $jour * DAY_IN_SECONDS );
				$id                     = yume_factory_post(
					array(
						'post_type'     => 'yume_chapitre',
						'post_title'    => 'Chapitre ' . $i,
						'post_name'     => 'chapitre-' . $i,
						'menu_order'    => $i,
						'post_date'     => get_date_from_gmt( $date ),
						'post_date_gmt' => $date,
						'post_content'  => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
						'meta_input'    => array(
							'yume_tome_id'   => $tome,
							'yume_oeuvre_id' => $oeuvre,
							'yume_numero'    => $i,
							'yume_nature'    => 'chapitre',
						),
					)
				);
				$chapitres[ $numero ][] = $id;
				$dates[ $id ]           = $date;
			}
		}
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
	return array(
		'oeuvre'    => $oeuvre,
		'tomes'     => $ids_tomes,
		'chapitres' => $chapitres,
		'dates'     => $dates,
	);
}

/**
 * Enregistre une position puis fixe sa date de dernière lecture (GMT).
 *
 * @param int    $user_id     Membre.
 * @param int    $chapitre_id Chapitre.
 * @param int    $paragraphe  Paragraphe (index).
 * @param int    $pourcentage Pourcentage lu.
 * @param string $lu_le       Date GMT « Y-m-d H:i:s ».
 */
function yume_rat_position( int $user_id, int $chapitre_id, int $paragraphe, int $pourcentage, string $lu_le ): void {
	global $wpdb;
	$ligne = enregistrer_progression( $user_id, $chapitre_id, $paragraphe, $pourcentage );
	yume_assert_true( is_array( $ligne ), 'position enregistrée' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update(
		table_progression(),
		array( 'updated_at' => $lu_le ),
		array(
			'user_id'     => $user_id,
			'chapitre_id' => $chapitre_id,
		)
	);
}

/**
 * Lignes du rattrapage indexées par œuvre.
 *
 * @param int $user_id Membre.
 * @return array<int,array>
 */
function yume_rat_par_oeuvre( int $user_id ): array {
	return array_column( rattrapage( $user_id, 50 )['oeuvres'], null, 'oeuvre_id' );
}

/**
 * Rend un bloc en tant qu'utilisateur donné (0 : visiteur), feuille de style retirée ensuite.
 *
 * @param int   $user_id Utilisateur.
 * @param array $attrs   Attributs.
 */
function yume_rat_bloc( int $user_id, array $attrs = array() ): string {
	wp_set_current_user( $user_id );
	try {
		return yume_render_block( 'yume/rattrapage', $attrs );
	} finally {
		wp_set_current_user( 0 );
	}
}

/**
 * Jeu commun : un membre, quatre séries.
 *  - A : tome 1 de 5 chapitres (jours 1 à 5), position chapitre 2 (paragraphe 6), lu le jour 3
 *        à 18:00 : restent les chapitres 3 (publié avant la lecture), 4 et 5 (après) ;
 *  - B : à jour (dernier chapitre lu à 100 %) ;
 *  - C : tome 1 terminé (2 chapitres, dernier lu à 95 %), tome 2 de 2 chapitres publiés plus
 *        tard : « Tome 2 commence » ;
 *  - D : jamais ouverte (favori seulement) : absente.
 *
 * @param int $pourcentage_a Pourcentage lu du chapitre 2 de A.
 * @return array<string,mixed>
 */
function yume_rat_jeu( int $pourcentage_a = 95 ): array {
	$u = yume_factory_user();
	$a = yume_rat_serie( array( 1 => 5 ), '2026-01-01', 'Les Lanternes de Brume-Haute' );
	$b = yume_rat_serie( array( 1 => 2 ), '2026-01-01' );
	$c = yume_rat_serie(
		array(
			1 => 2,
			2 => 2,
		),
		'2026-02-01'
	);
	$d = yume_rat_serie( array( 1 => 2 ), '2026-03-01' );
	yume_rat_position( $u, $a['chapitres'][1][1], 5, $pourcentage_a, '2026-01-04 18:00:00' );
	yume_rat_position( $u, $b['chapitres'][1][1], 0, 100, '2026-01-10 09:00:00' );
	yume_rat_position( $u, $c['chapitres'][1][1], 0, 95, '2026-02-03 18:00:00' );
	if ( function_exists( '\Yume\Core\Social\ajouter_favori' ) ) {
		\Yume\Core\Social\ajouter_favori( $u, $d['oeuvre'] );
	}
	return compact( 'u', 'a', 'b', 'c', 'd' );
}

/*
 * -----------------------------------------------------------------------------
 * Calcul
 * -----------------------------------------------------------------------------
 */

yume_test(
	'calcul : restants après la position, nouveaux depuis la dernière lecture, séries à jour et jamais ouvertes omises',
	function () {
		$j      = yume_rat_jeu();
		$a      = $j['a'];
		$resume = rattrapage( $j['u'], 6 );
		yume_assert_same( array( 'total', 'oeuvres' ), array_keys( $resume ) );
		yume_assert_same( 2, $resume['total'], 'A et C (B à jour, D jamais ouverte)' );
		$par = yume_rat_par_oeuvre( $j['u'] );
		yume_assert_same( array( $j['c']['oeuvre'], $a['oeuvre'] ), array_keys( $par ), 'C (dernière sortie en février) avant A' );

		$la = $par[ $a['oeuvre'] ];
		yume_assert_same(
			array( 'oeuvre_id', 'titre', 'url', 'image', 'restants', 'nouveaux_depuis', 'tome', 'prochain', 'suivant', 'reprise', 'dernier_publie_le' ),
			array_keys( $la )
		);
		yume_assert_same( 'Les Lanternes de Brume-Haute', $la['titre'] );
		yume_assert_same( get_permalink( $a['oeuvre'] ), $la['url'] );
		yume_assert_same( 3, $la['restants'], 'chapitres 3, 4, 5 (chapitre 2 lu à 95 %)' );
		yume_assert_same( 2, $la['nouveaux_depuis'], 'chapitres 4 et 5 publiés après la dernière lecture' );
		yume_assert_same( $a['chapitres'][1][2], $la['prochain']['id'] );
		yume_assert_same( 'Chapitre 3', $la['prochain']['titre'] );
		yume_assert_same( get_permalink( $a['chapitres'][1][2] ), $la['prochain']['url'] );
		yume_assert_same( $la['prochain'], $la['suivant'], 'chapitre courant lu : le suivant est le prochain' );
		yume_assert_same( $a['tomes'][1], $la['tome']['id'] );
		yume_assert_same( 'Tome 1', $la['tome']['libelle'] );
		yume_assert_false( $la['tome']['commence'] );
		yume_assert_same( get_permalink( $a['chapitres'][1][1] ) . '#yn-p-6', $la['reprise']['url'], 'url_reprise : ancre du paragraphe' );
		yume_assert_same( 'Chapitre 2', $la['reprise']['titre'] );
		yume_assert_same( 95, $la['reprise']['pourcentage'] );
		yume_assert_same( gmdate( 'c', (int) strtotime( $a['dates'][ $a['chapitres'][1][4] ] . ' UTC' ) ), $la['dernier_publie_le'] );
		yume_assert_same( '', $la['image'], 'sans couverture' );

		$lc = $par[ $j['c']['oeuvre'] ];
		yume_assert_same( 2, $lc['restants'] );
		yume_assert_same( 2, $lc['nouveaux_depuis'] );
		yume_assert_same( $j['c']['chapitres'][2][0], $lc['prochain']['id'] );
		yume_assert_same( $j['c']['tomes'][2], $lc['tome']['id'] );
		yume_assert_true( $lc['tome']['commence'], 'premier chapitre d’un tome pas encore commencé' );
		yume_assert_same( 'Tome 2', $lc['tome']['libelle'] );

		// Limite, membre sans lecture, visiteur.
		yume_assert_same( 1, count( rattrapage( $j['u'], 1 )['oeuvres'] ) );
		yume_assert_same( 2, rattrapage( $j['u'], 1 )['total'] );
		yume_assert_same(
			array(
				'total'   => 0,
				'oeuvres' => array(),
			),
			rattrapage( yume_factory_user() )
		);
		yume_assert_same( 0, rattrapage( 0 )['total'] );
	}
);

yume_test(
	'calcul : chapitre courant compté sous 90 %, pas à 90 % ; « Chapitre suivant » après le chapitre courant',
	function () {
		$j  = yume_rat_jeu( 41 );
		$a  = $j['a'];
		$la = yume_rat_par_oeuvre( $j['u'] )[ $a['oeuvre'] ];
		yume_assert_same( 4, $la['restants'], 'chapitre 2 (41 %) + 3, 4, 5' );
		yume_assert_same( 2, $la['nouveaux_depuis'], 'le chapitre courant n’est pas nouveau' );
		yume_assert_same( $a['chapitres'][1][1], $la['prochain']['id'], 'prochain = premier restant = chapitre courant' );
		yume_assert_same( $a['chapitres'][1][2], $la['suivant']['id'], 'suivant = chapitre 3' );
		yume_assert_same( 41, $la['reprise']['pourcentage'] );

		yume_rat_position( $j['u'], $a['chapitres'][1][1], 5, 89, '2026-01-04 18:00:00' );
		yume_assert_same( 4, yume_rat_par_oeuvre( $j['u'] )[ $a['oeuvre'] ]['restants'], '89 % : encore à lire' );
		yume_rat_position( $j['u'], $a['chapitres'][1][1], 5, 90, '2026-01-04 18:00:00' );
		yume_assert_same( 3, yume_rat_par_oeuvre( $j['u'] )[ $a['oeuvre'] ]['restants'], '90 % : lu (SEUIL_CHAPITRE_LU)' );

		// Dernier chapitre publié en cours de lecture : seul restant, pas de « suivant ».
		yume_rat_position( $j['u'], $a['chapitres'][1][4], 0, 20, '2026-01-20 09:00:00' );
		$la = yume_rat_par_oeuvre( $j['u'] )[ $a['oeuvre'] ];
		yume_assert_same( 1, $la['restants'] );
		yume_assert_same( 0, $la['nouveaux_depuis'] );
		yume_assert_same( null, $la['suivant'] );
		yume_assert_same( get_permalink( $a['chapitres'][1][4] ), $la['reprise']['url'], 'paragraphe 0 : sans ancre' );
	}
);

yume_test(
	'calcul : chapitre brouillon ignoré, œuvre dépubliée et position sur un chapitre retiré omises',
	function () {
		$j = yume_rat_jeu();
		$a = $j['a'];
		add_filter( 'yume_core_notifier', '__return_false' );
		try {
			yume_factory_post(
				array(
					'post_type'   => 'yume_chapitre',
					'post_status' => 'draft',
					'post_title'  => 'Chapitre 6',
					'menu_order'  => 6,
					'meta_input'  => array(
						'yume_tome_id'   => $a['tomes'][1],
						'yume_oeuvre_id' => $a['oeuvre'],
						'yume_numero'    => 6,
					),
				)
			);
			yume_assert_same( 3, yume_rat_par_oeuvre( $j['u'] )[ $a['oeuvre'] ]['restants'], 'brouillon non compté' );

			wp_update_post(
				array(
					'ID'          => $j['c']['oeuvre'],
					'post_status' => 'draft',
				)
			);
			wp_update_post(
				array(
					'ID'          => $a['chapitres'][1][1],
					'post_status' => 'draft',
				)
			);
		} finally {
			remove_filter( 'yume_core_notifier', '__return_false' );
		}
		yume_assert_same(
			array(
				'total'   => 0,
				'oeuvres' => array(),
			),
			rattrapage( $j['u'] ),
			'œuvre C dépubliée, position de A sur un chapitre retiré'
		);
	}
);

yume_test(
	'calcul : tri par chapitre publié le plus récent parmi les restants ; nombre de requêtes borné',
	function () {
		global $wpdb;
		$u      = yume_factory_user();
		$series = array();
		foreach ( array( '2026-01-01', '2026-03-01', '2026-02-01', '2026-04-01', '2026-01-15', '2026-02-15' ) as $n => $debut ) {
			$series[ $n ] = yume_rat_serie( array( 1 => 3 ), $debut );
			yume_rat_position( $u, $series[ $n ]['chapitres'][1][0], 0, 100, '2025-12-31 00:00:00' );
		}
		$ordre = array_column( rattrapage( $u, 6 )['oeuvres'], 'oeuvre_id' );
		yume_assert_same( array( $series[3]['oeuvre'], $series[1]['oeuvre'], $series[5]['oeuvre'], $series[2]['oeuvre'], $series[4]['oeuvre'], $series[0]['oeuvre'] ), $ordre );

		// Calcul sans cache : plan de lecture, dates et contenus en requêtes agrégées ; seuls les
		// permaliens des chapitres (segments du tome, module core) coûtent quelques requêtes par série gardée.
		add_filter( 'yume_rattrapage_cache', '__return_zero' );
		try {
			$deux = yume_factory_user();
			foreach ( array( 0, 1 ) as $n ) {
				yume_rat_position( $deux, $series[ $n ]['chapitres'][1][0], 0, 100, '2025-12-31 00:00:00' );
			}
			rattrapage( $deux );
			rattrapage( $u );
			wp_cache_flush();
			$avant = $wpdb->num_queries;
			rattrapage( $deux );
			$pour_deux = $wpdb->num_queries - $avant;
			wp_cache_flush();
			$avant = $wpdb->num_queries;
			rattrapage( $u );
			$pour_six = $wpdb->num_queries - $avant;
		} finally {
			remove_filter( 'yume_rattrapage_cache', '__return_zero' );
		}
		yume_assert_true( $pour_six - $pour_deux <= 4 * 4, "requêtes : {$pour_deux} pour 2 séries, {$pour_six} pour 6" );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Cache
 * -----------------------------------------------------------------------------
 */

yume_test(
	'cache : transient par membre, servi sans recalcul, effacé sur yume_progression_enregistree (ce membre seulement)',
	function () {
		global $wpdb;
		$j     = yume_rat_jeu();
		$autre = yume_factory_user();
		yume_rat_position( $autre, $j['a']['chapitres'][1][0], 0, 100, '2026-01-02 09:00:00' );
		rattrapage( $j['u'] );
		rattrapage( $autre );
		$cache = get_transient( 'yume_rattrapage_' . $j['u'] );
		yume_assert_true( is_array( $cache ) && version() === $cache['version'], 'transient versionné' );
		yume_assert_same( 2, $cache['donnees']['total'] );

		$avant = $wpdb->num_queries;
		rattrapage( $j['u'] );
		yume_assert_true( $wpdb->num_queries - $avant <= 2, 'servi depuis le cache (lignes de progression + transient)' );

		// Nouvelle position du membre : son cache seul est effacé, l'œuvre A disparaît (à jour).
		enregistrer_progression( $j['u'], $j['a']['chapitres'][1][4], 0, 100 );
		yume_assert_false( get_transient( 'yume_rattrapage_' . $j['u'] ), 'cache du membre effacé' );
		yume_assert_true( is_array( get_transient( 'yume_rattrapage_' . $autre ) ), 'cache de l’autre membre intact' );
		yume_assert_false( isset( yume_rat_par_oeuvre( $j['u'] )[ $j['a']['oeuvre'] ] ) );

		// Ligne de progression modifiée sans l'action (suppression) : l'empreinte périme le cache.
		\Yume\Core\Reader\supprimer_progression( $j['u'], $j['c']['oeuvre'] );
		yume_assert_same( 0, rattrapage( $j['u'] )['total'] );
	}
);

yume_test(
	'cache : version incrémentée sur yume_chapitre_publie, yume_tome_publie et la dépublication ; nouveau chapitre visible',
	function () {
		$j = yume_rat_jeu();
		$b = $j['b'];
		yume_assert_false( isset( yume_rat_par_oeuvre( $j['u'] )[ $b['oeuvre'] ] ), 'B à jour' );

		// Chapitre ajouté sans aucun événement (requête directe) : le cache sert encore l'ancien état.
		$v = version();
		add_filter( 'yume_core_notifier', '__return_false' );
		remove_action( 'transition_post_status', 'Yume\Core\Rattrapage\sur_transition', 10 );
		try {
			$nouveau = yume_factory_post(
				array(
					'post_type'  => 'yume_chapitre',
					'post_title' => 'Chapitre 3',
					'post_name'  => 'chapitre-3',
					'menu_order' => 3,
					'meta_input' => array(
						'yume_tome_id'   => $b['tomes'][1],
						'yume_oeuvre_id' => $b['oeuvre'],
						'yume_numero'    => 3,
					),
				)
			);
		} finally {
			add_action( 'transition_post_status', 'Yume\Core\Rattrapage\sur_transition', 10, 3 );
			remove_filter( 'yume_core_notifier', '__return_false' );
		}
		yume_assert_same( $v, version() );
		yume_assert_false( isset( yume_rat_par_oeuvre( $j['u'] )[ $b['oeuvre'] ] ), 'cache encore valide' );

		do_action( 'yume_chapitre_publie', $nouveau );
		yume_assert_same( $v + 1, version() );
		$lb = yume_rat_par_oeuvre( $j['u'] )[ $b['oeuvre'] ] ?? null;
		yume_assert_true( is_array( $lb ), 'B réapparaît après la publication' );
		yume_assert_same( $nouveau, $lb['prochain']['id'] );
		yume_assert_same( 1, $lb['nouveaux_depuis'] );

		do_action( 'yume_tome_publie', $b['tomes'][1] );
		yume_assert_same( $v + 2, version() );

		// Dépublication d'un chapitre (transition_post_status quittant publish).
		wp_update_post(
			array(
				'ID'          => $nouveau,
				'post_status' => 'draft',
			)
		);
		yume_assert_same( $v + 3, version() );
		yume_assert_false( isset( yume_rat_par_oeuvre( $j['u'] )[ $b['oeuvre'] ] ), 'B de nouveau à jour' );

		invalider_tous();
		yume_assert_same( $v + 4, version() );
	}
);

yume_test(
	'filtres : yume_rattrapage_actif coupe tout, yume_rattrapage_cache à 0 désactive le transient',
	function () {
		$j = yume_rat_jeu();
		add_filter( 'yume_rattrapage_cache', '__return_zero' );
		try {
			yume_assert_same( 2, rattrapage( $j['u'] )['total'] );
			yume_assert_false( get_transient( 'yume_rattrapage_' . $j['u'] ) );
		} finally {
			remove_filter( 'yume_rattrapage_cache', '__return_zero' );
		}
		add_filter( 'yume_rattrapage_actif', '__return_false' );
		try {
			yume_assert_same( 0, rattrapage( $j['u'] )['total'] );
			yume_assert_same( '', yume_rat_bloc( $j['u'] ) );
			wp_set_current_user( $j['u'] );
			$compte = \Yume\Core\Social\compte_connecte();
			wp_set_current_user( 0 );
			yume_assert_not_contains( 'yn-rattrapage', $compte );
		} finally {
			remove_filter( 'yume_rattrapage_actif', '__return_false' );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Bloc et accueil
 * -----------------------------------------------------------------------------
 */

/**
 * Première carte (li) d'un rendu du bloc.
 *
 * @param string $html Rendu.
 */
function yume_rat_premiere_carte( string $html ): string {
	$debut = strpos( $html, '<li ' );
	$fin   = strpos( $html, '</li>' );
	return false === $debut || false === $fin ? '' : substr( $html, $debut, $fin - $debut + 5 );
}

yume_test(
	'bloc : chaîne vide stricte pour un visiteur (cache de page), rien pour un membre sans lecture',
	function () {
		$j = yume_rat_jeu();
		oublier_reprises();
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_true( WP_Block_Type_Registry::get_instance()->is_registered( 'yume/rattrapage' ) );
		yume_assert_same( '', yume_rat_bloc( 0 ) );
		yume_assert_same( '', yume_rat_bloc( 0, array( 'layout' => 'carte' ) ) );
		yume_assert_same( '', do_blocks( '<!-- wp:yume/rattrapage {"count":3,"layout":"section","align":"wide"} /-->' ), 'visiteur, dans un modèle' );
		yume_assert_false( wp_style_is( 'yume-rattrapage', 'enqueued' ), 'aucune feuille de style pour un visiteur' );
		yume_assert_same( 0, reprise_affichee( 0 ), 'rien de noté pour un visiteur' );
		$sans = yume_factory_user();
		yume_assert_same( '', yume_rat_bloc( $sans ), 'membre sans lecture' );
		yume_assert_same( 0, reprise_affichee( $sans ) );
		yume_assert_same(
			array(
				'reprise' => null,
				'oeuvres' => array(),
				'autres'  => 0,
			),
			donnees_accueil( $sans )
		);
	}
);

yume_test(
	'bloc : membre à jour → une seule carte, sa dernière lecture (« Continuer »), sans « Chapitre suivant »',
	function () {
		$j      = yume_rat_jeu();
		$a_jour = yume_factory_user();
		yume_rat_position( $a_jour, $j['b']['chapitres'][1][1], 4, 100, '2026-01-10 09:00:00' );
		$html = yume_rat_bloc( $a_jour );
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_contains( 'Reprendre la lecture</h2>', $html );
		yume_assert_same( 1, substr_count( $html, '<li class="yn-rattrapage__item' ) );
		yume_assert_contains( 'yn-rattrapage__item--reprise" data-yn-oeuvre="' . $j['b']['oeuvre'] . '" data-yn-reprise', $html );
		yume_assert_contains( '<p class="yn-label yn-rattrapage__surtitre">Dernière lecture</p>', $html );
		yume_assert_contains( 'Tome 1 · chapitre 2 · 100 % du chapitre', $html );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $j['b']['chapitres'][1][1] ) . '#yn-p-5' ) . '">Continuer', $html );
		yume_assert_contains( '--v:100%', $html );
		yume_assert_not_contains( 'yn-rattrapage__suivant', $html );
		yume_assert_not_contains( 'yn-rattrapage__avancement', $html, 'série à jour : pas de compte de chapitres' );
		yume_assert_not_contains( 'yn-rattrapage__plus', $html );
		yume_assert_same( $j['b']['oeuvre'], reprise_affichee( $a_jour ), 'lecture notée pour le bandeau' );
	}
);

yume_test(
	'bloc : membre → « Reprendre la lecture », dernière lecture en tête (nouveaux chapitres, « Chapitre suivant »), puis séries à rattraper sans doublon',
	function () {
		oublier_reprises();
		$j    = yume_rat_jeu( 41 );
		$a    = $j['a'];
		$c    = $j['c'];
		$html = yume_rat_bloc( $j['u'], array( 'align' => 'wide' ) );
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_contains( '<section class="', $html );
		yume_assert_contains( 'yn-rattrapage yn-rattrapage--section yn-section', $html );
		yume_assert_contains( 'alignwide', $html );
		yume_assert_contains( 'Reprendre la lecture</h2>', $html );
		yume_assert_not_contains( 'Nouveaux chapitres depuis votre dernière lecture', $html );
		yume_assert_contains( 'Toutes mes lectures', $html );
		yume_assert_contains( '#yn-lecture"', $html );
		yume_assert_same( 2, substr_count( $html, '<li class="yn-rattrapage__item' ), 'C (dernière lecture) et A ; B à jour' );
		yume_assert_same( 1, substr_count( $html, 'data-yn-oeuvre="' . $c['oeuvre'] . '"' ), 'série de la dernière lecture non répétée' );
		yume_assert_same( 1, substr_count( $html, 'data-yn-reprise' ) );

		// Première carte : C, lue le plus récemment (chapitre 2 du tome 1 à 95 %), tome 2 publié depuis.
		$premiere = yume_rat_premiere_carte( $html );
		yume_assert_contains( 'data-yn-oeuvre="' . $c['oeuvre'] . '" data-yn-reprise', $premiere );
		yume_assert_contains( 'Tome 1 · chapitre 2 · 95 % du chapitre', $premiere );
		yume_assert_contains( 'Tome 2 commence · <strong class="yn-rattrapage__nombre">2 nouveaux chapitres</strong>', $premiere );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $c['chapitres'][1][1] ) ) . '">Continuer', $premiere );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $c['chapitres'][2][0] ) ) . '">Chapitre suivant', $premiere );
		yume_assert_contains( 'dernier publié le <time datetime="', $premiere );

		// Puis A, carte de rattrapage habituelle.
		yume_assert_true( strpos( $html, 'data-yn-oeuvre="' . $c['oeuvre'] . '"' ) < strpos( $html, 'data-yn-oeuvre="' . $a['oeuvre'] . '"' ), 'dernière lecture en tête' );
		yume_assert_contains( 'Tome 1 · <strong class="yn-rattrapage__nombre">2 nouveaux chapitres</strong>', $html );
		yume_assert_not_contains( 'depuis votre dernière lecture</p>', $html, 'forme courte sur l’accueil' );
		yume_assert_contains( 'Reprendre (chapitre 2, 41 %)', $html );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $a['chapitres'][1][1] ) . '#yn-p-6' ) . '"', $html );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $a['chapitres'][1][2] ) ) . '">Chapitre suivant', $html );
		yume_assert_same( 1, substr_count( $html, '<h2' ) );
		yume_assert_same( 2, substr_count( $html, '<h3' ) );
		yume_assert_same( 2, substr_count( $html, '<p class="yn-rattrapage__actions">' ), 'boutons hors du texte, sur toute la largeur de la carte' );
		yume_assert_not_contains( 'yn-rattrapage__plus', $html, 'rien de plus à rattraper' );
		yume_assert_same( $c['oeuvre'], reprise_affichee( $j['u'] ) );

		// count et disposition « carte ».
		$carte = yume_rat_bloc(
			$j['u'],
			array(
				'count'  => 2,
				'layout' => 'carte',
			)
		);
		yume_assert_contains( 'yn-rattrapage--carte yn-card', $carte );
		yume_assert_same( 2, substr_count( $carte, '<li class="yn-rattrapage__item' ) );
		yume_assert_contains( 'nouveaux chapitres</strong> depuis votre dernière lecture', $carte );
		yume_assert_same( 1, substr_count( yume_rat_bloc( $j['u'], array( 'count' => 1 ) ), '<li class="yn-rattrapage__item' ), 'count 1 : la dernière lecture seule' );
		yume_assert_true( wp_style_is( 'yume-rattrapage', 'enqueued' ), 'feuille de style chargée pour le membre' );
		wp_dequeue_style( 'yume-rattrapage' );
		oublier_reprises();
	}
);

yume_test(
	'bloc : 3 cartes au plus (dernière lecture + 2 séries à rattraper, les plus récentes), lien vers les autres',
	function () {
		$u      = yume_factory_user();
		$series = array();
		foreach ( array( '2026-01-01', '2026-03-01', '2026-02-01', '2026-04-01', '2026-01-15' ) as $n => $debut ) {
			$series[ $n ] = yume_rat_serie( array( 1 => 3 ), $debut );
			yume_rat_position( $u, $series[ $n ]['chapitres'][1][0], 0, 100, '2025-12-31 00:00:00' );
		}
		// Dernière lecture : dernier chapitre publié, en cours (81 %) : rien après la position.
		$recente = yume_rat_serie( array( 4 => 2 ), '2026-01-01', 'Mikadono' );
		yume_rat_position( $u, $recente['chapitres'][4][1], 3, 81, '2026-05-01 10:00:00' );

		$donnees = donnees_accueil( $u );
		yume_assert_same( $recente['oeuvre'], $donnees['reprise']['position']['oeuvre_id'] );
		yume_assert_same( $recente['oeuvre'], $donnees['reprise']['ligne']['oeuvre_id'], 'chapitre courant sous 90 % : encore dans le rattrapage' );
		yume_assert_same( array( $series[3]['oeuvre'], $series[1]['oeuvre'] ), array_column( $donnees['oeuvres'], 'oeuvre_id' ) );
		yume_assert_same( 3, $donnees['autres'] );

		$html = yume_rat_bloc( $u, array( 'count' => 3 ) );
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_same( 3, substr_count( $html, '<li class="yn-rattrapage__item' ) );
		yume_assert_same( 1, substr_count( $html, 'data-yn-oeuvre="' . $recente['oeuvre'] . '"' ) );
		$premiere = yume_rat_premiere_carte( $html );
		yume_assert_contains( 'data-yn-oeuvre="' . $recente['oeuvre'] . '" data-yn-reprise', $premiere );
		yume_assert_contains( 'Tome 4 · chapitre 2 · 81 % du chapitre', $premiere );
		yume_assert_contains( '>Continuer<span class="yn-visually-hidden"> : Mikadono · Tome 4 · Chapitre 2</span>', $premiere );
		yume_assert_not_contains( 'Chapitre suivant', $premiere, 'aucun chapitre après la position' );
		yume_assert_not_contains( 'yn-rattrapage__avancement', $premiere );
		yume_assert_true( strpos( $html, 'data-yn-oeuvre="' . $series[3]['oeuvre'] . '"' ) < strpos( $html, 'data-yn-oeuvre="' . $series[1]['oeuvre'] . '"' ), 'tri du rattrapage conservé' );
		yume_assert_not_contains( 'data-yn-oeuvre="' . $series[2]['oeuvre'] . '"', $html );
		yume_assert_contains( '<p class="yn-rattrapage__plus"><a href="' . esc_url( \Yume\Core\Rattrapage\url_lectures( 'yn-rattrapage' ) ) . '">Et 3 autres séries à rattraper', $html );

		// Bloc réglé plus haut : la grille passe à la ligne (le défaut reste 3).
		yume_assert_same( 6, substr_count( yume_rat_bloc( $u, array( 'count' => 12 ) ), '<li class="yn-rattrapage__item' ) );
		yume_assert_same( 3, substr_count( yume_rat_bloc( $u ), '<li class="yn-rattrapage__item' ), 'défaut : 3' );
		wp_dequeue_style( 'yume-rattrapage' );

		// Mon compte → À rattraper inchangé : toutes les séries (12 au plus), dernière lecture comprise.
		wp_set_current_user( $u );
		$compte = \Yume\Core\Social\compte_connecte();
		wp_set_current_user( 0 );
		wp_dequeue_style( 'yume-rattrapage' );
		$section = substr( $compte, (int) strpos( $compte, 'id="yn-rattrapage"' ) );
		$section = substr( $section, 0, (int) strpos( $section, '</section>' ) );
		yume_assert_same( 6, substr_count( $section, '<li class="yn-rattrapage__item"' ) );
		yume_assert_not_contains( 'data-yn-reprise', $section );
		oublier_reprises();
	}
);

yume_test(
	'accueil : le bandeau du bas ne répète pas la dernière lecture du membre (repli « Communauté » + Discord) ; visiteur inchangé',
	function () {
		$theme = get_theme_root() . '/yume';
		if ( ! is_dir( $theme ) ) {
			return;
		}
		$accueil = (string) file_get_contents( $theme . '/templates/front-page.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		$debut   = strpos( $accueil, '<!-- wp:group {"tagName":"section","align":"wide","className":"yn-card yn-bandeau-reprise"' );
		$fin     = strpos( $accueil, '<!-- /wp:group -->', (int) strpos( $accueil, '<!-- /wp:buttons --></section>', (int) $debut ) );
		yume_assert_true( false !== $debut && false !== $fin, 'bandeau présent dans le modèle' );
		$bandeau = substr( $accueil, (int) $debut, (int) $fin - (int) $debut + strlen( '<!-- /wp:group -->' ) );
		$bloc    = '<!-- wp:yume/rattrapage {"count":3,"layout":"section","align":"wide"} /-->';
		$rendre  = static function ( int $user_id, string $contenu ): string {
			wp_set_current_user( $user_id );
			try {
				return do_blocks( $contenu );
			} finally {
				wp_set_current_user( 0 );
			}
		};
		oublier_reprises();
		$j = yume_rat_jeu( 41 );

		// Membre : le bloc du haut affiche la dernière lecture, le bandeau montre son repli.
		$page = $rendre( $j['u'], $bloc . "\n\n" . $bandeau );
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_same( 1, substr_count( $page, '>Continuer<' ), 'une seule reprise sur la page' );
		yume_assert_contains( 'yn-rattrapage__item--reprise', $page );
		yume_assert_not_contains( 'yn-resume', $page, 'ni reprise ni gabarit visiteur dans le bandeau' );
		yume_assert_contains( 'yn-bandeau-reprise__repli', $page );
		yume_assert_contains( 'Discutez des sorties avec l’équipe et les autres lecteurs', $page );
		yume_assert_contains( 'Rejoindre le Discord', $page );

		// Même membre sans le bloc du haut (autre page, rattrapage coupé) : le bandeau reprend.
		oublier_reprises();
		$seul = $rendre( $j['u'], $bandeau );
		yume_assert_contains( 'yn-resume yn-resume--bandeau', $seul );
		yume_assert_contains( 'Tome 1 · chapitre 2 · 95 % du chapitre', $seul );
		add_filter( 'yume_rattrapage_actif', '__return_false' );
		try {
			$coupe = $rendre( $j['u'], $bloc . $bandeau );
		} finally {
			remove_filter( 'yume_rattrapage_actif', '__return_false' );
		}
		yume_assert_contains( 'yn-resume yn-resume--bandeau', $coupe, 'rattrapage coupé : bandeau d’avant' );

		// La carte « Reprendre la lecture » (layout carte) et un autre membre ne sont pas concernés.
		$rendre( $j['u'], $bloc );
		wp_dequeue_style( 'yume-rattrapage' );
		wp_set_current_user( $j['u'] );
		$carte = yume_render_block( 'yume/resume-reading', array( 'layout' => 'carte' ) );
		wp_set_current_user( 0 );
		yume_assert_contains( 'yn-resume yn-resume--carte', $carte );
		$autre = yume_factory_user();
		yume_rat_position( $autre, $j['a']['chapitres'][1][1], 0, 30, '2026-01-04 18:00:00' );
		yume_assert_contains( 'yn-resume yn-resume--bandeau', $rendre( $autre, $bandeau ) );

		// Visiteur : bloc du haut vide, bandeau rendu côté client (gabarit masqué), rien de personnel.
		oublier_reprises();
		$visiteur = $rendre( 0, $bloc . $bandeau );
		yume_assert_not_contains( 'yn-rattrapage', $visiteur );
		yume_assert_contains( 'data-yn-resume="bandeau" hidden', $visiteur );
		yume_assert_contains( 'Rejoindre le Discord', $visiteur );
		yume_assert_not_contains( get_the_title( $j['c']['oeuvre'] ), $visiteur );
		oublier_reprises();
	}
);

yume_test(
	'dates : format du site (« 18 oct. », « 2 sept. 2025 »), jamais d’abréviation anglaise capitalisée',
	function () {
		$annee = (int) wp_date( 'Y' );
		yume_assert_same( '<time datetime="' . $annee . '-10-18T12:00:00+00:00">18 oct.</time>', date_courte( $annee . '-10-18T12:00:00+00:00' ) );
		yume_assert_same( '<time datetime="' . $annee . '-08-01T12:00:00+00:00">1 août</time>', date_courte( $annee . '-08-01T12:00:00+00:00' ) );
		$avant = $annee - 1;
		yume_assert_same( '<time datetime="' . $avant . '-09-02T12:00:00+00:00">2 sept. ' . $avant . '</time>', date_courte( $avant . '-09-02T12:00:00+00:00' ), 'année ajoutée' );
		yume_assert_same( '', date_courte( '' ) );
		yume_assert_same( '', date_courte( 'pas une date' ) );
	}
);

yume_test(
	'rendu des cartes : titres échappés, « 12 chapitres à lire » sans nouveauté, singulier',
	function () {
		$ligne = array(
			'oeuvre_id'         => 7,
			'titre'             => '<script>alert(1)</script>',
			'url'               => 'https://exemple.test/o/',
			'image'             => '',
			'restants'          => 12,
			'nouveaux_depuis'   => 0,
			'tome'              => array(
				'id'       => 0,
				'libelle'  => 'Arc 7',
				'commence' => true,
			),
			'prochain'          => array(
				'id'    => 0,
				'titre' => 'Chapitre 1',
				'url'   => 'https://exemple.test/c/',
			),
			'suivant'           => array(
				'id'    => 0,
				'titre' => 'Chapitre 1',
				'url'   => 'https://exemple.test/c/',
			),
			'reprise'           => array(
				'url'         => 'https://exemple.test/r/',
				'titre'       => 'Épilogue',
				'pourcentage' => 100,
			),
			'dernier_publie_le' => '',
		);
		$html  = rendu_cartes( array( $ligne ), 'section' );
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_not_contains( '<script>', $html );
		yume_assert_contains( '&lt;script&gt;', $html );
		yume_assert_contains( 'Arc 7 commence · <strong class="yn-rattrapage__nombre">12 chapitres à lire</strong>', $html );
		yume_assert_contains( '>Reprendre<span class="yn-visually-hidden">', $html, 'chapitre terminé : « Reprendre » seul' );
		yume_assert_not_contains( 'dernier publié le', $html, 'sans date' );
		$ligne['restants']         = 1;
		$ligne['nouveaux_depuis']  = 1;
		$ligne['tome']['commence'] = false;
		yume_assert_contains( 'Arc 7 · <strong class="yn-rattrapage__nombre">1 nouveau chapitre</strong> depuis votre dernière lecture', rendu_cartes( array( $ligne ), 'carte' ) );
		yume_assert_same( '', rendu_cartes( array(), 'section' ) );
		wp_dequeue_style( 'yume-rattrapage' );
	}
);

yume_test(
	'accueil : bloc yume/rattrapage (3 cartes) entre « Dernières sorties » et le Planning, bandeau en bas',
	function () {
		$theme = get_theme_root() . '/yume';
		if ( ! is_dir( $theme ) ) {
			return;
		}
		$accueil    = (string) file_get_contents( $theme . '/templates/front-page.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local.
		$sorties    = strpos( $accueil, '<!-- wp:yume/latest-releases' );
		$rattrapage = strpos( $accueil, '<!-- wp:yume/rattrapage {"count":3,"layout":"section","align":"wide"} /-->' );
		$planning   = strpos( $accueil, '<!-- wp:yume/planning-accueil' );
		yume_assert_true( false !== $sorties && false !== $rattrapage && false !== $planning, 'trois blocs présents' );
		yume_assert_true( $sorties < $rattrapage && $rattrapage < $planning, 'ordre : sorties, rattrapage, planning' );
		yume_assert_same( 1, substr_count( $accueil, 'wp:yume/rattrapage' ) );
		$bandeau = strpos( $accueil, '<!-- wp:yume/resume-reading {"layout":"bandeau"} /-->' );
		yume_assert_true( false !== $bandeau && $rattrapage < $bandeau, 'bandeau rendu après le bloc du haut' );
		yume_assert_contains( 'yn-lien-discord', $accueil );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Compte
 * -----------------------------------------------------------------------------
 */

yume_test(
	'compte : rubrique « À rattraper » juste après « Lecture en cours », même liste',
	function () {
		$j = yume_rat_jeu();
		wp_set_current_user( $j['u'] );
		$html = \Yume\Core\Social\compte_connecte();
		wp_set_current_user( 0 );
		wp_dequeue_style( 'yume-rattrapage' );
		yume_assert_contains( 'href="#yn-rattrapage"', $html );
		yume_assert_contains( 'data-yn-onglet="yn-rattrapage">À rattraper</a>', $html );
		yume_assert_true( strpos( $html, 'href="#yn-lecture"' ) < strpos( $html, 'href="#yn-rattrapage"' ) && strpos( $html, 'href="#yn-rattrapage"' ) < strpos( $html, 'href="#yn-stats"' ), 'onglet entre Lecture en cours et Mes statistiques' );
		yume_assert_true( strpos( $html, 'id="yn-lecture"' ) < strpos( $html, 'id="yn-rattrapage"' ) && strpos( $html, 'id="yn-rattrapage"' ) < strpos( $html, 'id="yn-stats"' ), 'section entre Reprendre la lecture et Mes statistiques' );
		yume_assert_contains( 'data-yn-panneau="yn-rattrapage"', $html );
		yume_assert_contains( 'yn-rattrapage__liste--carte', $html );
		yume_assert_contains( 'data-yn-oeuvre="' . $j['a']['oeuvre'] . '"', $html );
		yume_assert_contains( 'nouveaux chapitres</strong> depuis votre dernière lecture', $html );
		yume_assert_not_contains( 'Vous êtes à jour sur toutes vos séries.', $html );
	}
);

yume_test(
	'compte : « Vous êtes à jour sur toutes vos séries. » ; pas de rubrique sans série commencée',
	function () {
		$j = yume_rat_jeu();
		$u = yume_factory_user();
		yume_rat_position( $u, $j['b']['chapitres'][1][1], 0, 100, '2026-01-10 09:00:00' );
		wp_set_current_user( $u );
		$html = \Yume\Core\Social\compte_connecte();
		wp_set_current_user( 0 );
		yume_assert_contains( 'id="yn-rattrapage"', $html );
		yume_assert_contains( 'Vous êtes à jour sur toutes vos séries.', $html );

		$sans = yume_factory_user();
		wp_set_current_user( $sans );
		$html = \Yume\Core\Social\compte_connecte();
		wp_set_current_user( 0 );
		yume_assert_not_contains( 'yn-rattrapage', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'REST GET /moi/rattrapage : 401 anonyme, 200 membre (même tableau), limite 1-50, Cache-Control privé',
	function () {
		$j       = yume_rat_jeu();
		$anonyme = yume_rest( 'GET', '/yume/v1/moi/rattrapage' );
		yume_assert_same( 401, $anonyme->get_status() );

		$rep = yume_rest( 'GET', '/yume/v1/moi/rattrapage', array(), $j['u'] );
		yume_assert_same( 200, $rep->get_status() );
		yume_assert_same( 'private, no-store', $rep->get_headers()['Cache-Control'] ?? '' );
		$donnees = $rep->get_data();
		yume_assert_same( 2, $donnees['total'] );
		yume_assert_same( rattrapage( $j['u'] ), $donnees );
		yume_assert_same( $j['a']['chapitres'][1][2], $donnees['oeuvres'][1]['prochain']['id'] );

		$un = yume_rest( 'GET', '/yume/v1/moi/rattrapage', array( 'limite' => 1 ), $j['u'] );
		yume_assert_same( 1, count( $un->get_data()['oeuvres'] ) );
		yume_assert_same( 2, $un->get_data()['total'] );
		yume_assert_same( 400, yume_rest( 'GET', '/yume/v1/moi/rattrapage', array( 'limite' => 0 ), $j['u'] )->get_status() );
		yume_assert_same( 400, yume_rest( 'GET', '/yume/v1/moi/rattrapage', array( 'limite' => 51 ), $j['u'] )->get_status() );

		// Un membre ne voit que ses propres lectures.
		$autre = yume_rest( 'GET', '/yume/v1/moi/rattrapage', array(), yume_factory_user() );
		yume_assert_same(
			array(
				'total'   => 0,
				'oeuvres' => array(),
			),
			$autre->get_data()
		);
	}
);
