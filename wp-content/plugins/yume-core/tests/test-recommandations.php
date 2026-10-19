<?php
/**
 * Tests du module recommandations (lot L3, docs/06 §8 sexies) : similarité des genres,
 * co-favoris (seuil de 3 lecteurs), score et ordre, exclusions, seuil minimal, cache versionné et
 * invalidation, bloc yume/recommandations (HTML identique pour les visiteurs, membre sans ses
 * favoris ni ses lectures), rubrique « Pour vous » de Mon compte, routes REST.
 *
 * Lancement : tools/localenv/test.sh recommandations
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Recommandations\OPTION_ATTENTE;
use const Yume\Core\Recommandations\OPTION_VERSION;
use const Yume\Core\Recommandations\SEUIL_COFAVORIS;
use const Yume\Core\Recommandations\TAMPON;
use function Yume\Core\Recommandations\accrocher_au_modele;
use function Yume\Core\Recommandations\cle_cache;
use function Yume\Core\Recommandations\cofavoris;
use function Yume\Core\Recommandations\invalider;
use function Yume\Core\Recommandations\memoire;
use function Yume\Core\Recommandations\recommandations_membre;
use function Yume\Core\Recommandations\recommandations_oeuvre;
use function Yume\Core\Recommandations\rendu_cartes;
use function Yume\Core\Recommandations\similarite_genres;
use function Yume\Core\Recommandations\version;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_trc_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test : aucun contenu Yume ni favori au départ, cache des recommandations remis à
 * zéro, notifications coupées, requête principale et utilisateur courant restaurés à la fin.
 *
 * @param string   $nom   Nom du test.
 * @param callable $corps Corps du test.
 */
function yume_trc_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wp_query, $wp_the_query, $post;
			$requete  = $wp_query;
			$reelle   = $wp_the_query;
			$post_sav = $post;
			add_filter( 'yume_core_notifier', '__return_false' );
			try {
				yume_trc_vider();
				$corps();
			} finally {
				remove_filter( 'yume_core_notifier', '__return_false' );
				$wp_query     = $requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$wp_the_query = $reelle; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$post         = $post_sav; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				wp_set_current_user( 0 );
				yume_trc_oublier();
			}
		}
	);
}

/**
 * Vide la mémoire de requête du module et lève le délai d'une minute entre deux versions.
 */
function yume_trc_oublier(): void {
	$memoire = &memoire();
	$memoire = array();
	delete_transient( TAMPON );
}

/**
 * Supprime (dans la transaction du test) les contenus Yume, les favoris et les positions.
 */
function yume_trc_vider(): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('yume_oeuvre', 'yume_tome', 'yume_chapitre')" );
	if ( $ids ) {
		$liste = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($liste)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($liste)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($liste)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->query( 'DELETE FROM ' . \Yume\Core\Social\table_favoris() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( function_exists( '\Yume\Core\Reader\table_progression' ) && \Yume\Core\Reader\table_prete() ) {
		$wpdb->query( 'DELETE FROM ' . \Yume\Core\Reader\table_progression() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
	wp_set_current_user( 0 );
	yume_trc_oublier();
}

/**
 * Crée une œuvre avec ses termes.
 *
 * @param string $titre  Titre.
 * @param array  $termes taxonomie => slugs (yume_type, yume_genre, yume_statut).
 * @param array  $args   Arguments wp_insert_post supplémentaires.
 */
function yume_trc_oeuvre( string $titre, array $termes = array(), array $args = array() ): int {
	$id = yume_factory_post(
		array_merge(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => $titre,
				'post_status' => 'publish',
			),
			$args
		)
	);
	foreach ( $termes as $taxonomie => $slugs ) {
		foreach ( (array) $slugs as $slug ) {
			if ( ! term_exists( $slug, $taxonomie ) ) {
				wp_insert_term( ucfirst( str_replace( '-', ' ', $slug ) ), $taxonomie, array( 'slug' => $slug ) );
			}
		}
		wp_set_object_terms( $id, (array) $slugs, $taxonomie );
	}
	return $id;
}

/**
 * Ajoute des favoris : chaque lecteur créé suit toutes les œuvres données.
 *
 * @param int[] $oeuvres  Œuvres.
 * @param int   $lecteurs Nombre de lecteurs créés.
 * @return int[] Lecteurs.
 */
function yume_trc_favoris( array $oeuvres, int $lecteurs ): array {
	$ids = array();
	for ( $i = 0; $i < $lecteurs; $i++ ) {
		$user = yume_factory_user( 'subscriber' );
		foreach ( $oeuvres as $oeuvre ) {
			$ok = \Yume\Core\Social\ajouter_favori( $user, $oeuvre );
			yume_assert_true( true === $ok, 'favori ajouté' );
		}
		$ids[] = $user;
	}
	return $ids;
}

/**
 * Enregistre une position de lecture (écriture directe dans la table du module lecture).
 *
 * @param int $user_id   Lecteur.
 * @param int $oeuvre_id Œuvre.
 */
function yume_trc_lecture( int $user_id, int $oeuvre_id ): void {
	global $wpdb;
	if ( ! \Yume\Core\Reader\table_prete() ) {
		\Yume\Core\Reader\installer_tables();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		\Yume\Core\Reader\table_progression(),
		array(
			'user_id'     => $user_id,
			'oeuvre_id'   => $oeuvre_id,
			'tome_id'     => 0,
			'chapitre_id' => 0,
			'paragraphe'  => 0,
			'pourcentage' => 10,
			'updated_at'  => current_time( 'mysql', true ),
		)
	);
}

/**
 * Identifiants d'une liste de recommandations.
 *
 * @param array $recos Recommandations.
 * @return int[]
 */
function yume_trc_ids( array $recos ): array {
	return array_map( static fn( array $r ): int => (int) $r['id'], $recos );
}

/**
 * Rend le bloc dans le contexte d'un contenu.
 *
 * @param int   $post_id Contenu (0 : aucun).
 * @param array $attrs   Attributs.
 */
function yume_trc_bloc( int $post_id, array $attrs = array() ): string {
	$bloc = new WP_Block(
		array(
			'blockName'    => 'yume/recommandations',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		$post_id ? array(
			'postId'   => $post_id,
			'postType' => get_post_type( $post_id ),
		) : array()
	);
	return (string) $bloc->render();
}

/**
 * Catalogue de référence : Lanternes (fantasy, drame ; light novel) et cinq autres œuvres.
 *
 * @return array<string,int>
 */
function yume_trc_catalogue(): array {
	return array(
		'lanternes' => yume_trc_oeuvre(
			'Les Lanternes de Brume-Haute',
			array(
				'yume_type'  => 'light-novel',
				'yume_genre' => array( 'fantasy', 'drame' ),
			)
		),
		'veilleurs' => yume_trc_oeuvre(
			'Les Veilleurs',
			array(
				'yume_type'  => 'light-novel',
				'yume_genre' => array( 'fantasy', 'drame' ),
			)
		),
		'arcs'      => yume_trc_oeuvre(
			'Arcs d’ombre',
			array(
				'yume_type'  => 'web-novel',
				'yume_genre' => array( 'fantasy' ),
			)
		),
		'cerisier'  => yume_trc_oeuvre(
			'Sous le Cerisier',
			array(
				'yume_type'  => 'light-novel',
				'yume_genre' => array( 'romance' ),
			)
		),
		'hoshizora' => yume_trc_oeuvre(
			'Hoshizora',
			array(
				'yume_type'  => 'manga',
				'yume_genre' => array( 'drame', 'romance' ),
			)
		),
		'isole'     => yume_trc_oeuvre(
			'Isolé',
			array(
				'yume_type'  => 'manga',
				'yume_genre' => array( 'horreur' ),
			)
		),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Calcul
 * -----------------------------------------------------------------------------
 */

yume_trc_test(
	'similarite_genres() : indice de Jaccard des genres',
	static function () {
		$c = yume_trc_catalogue();
		yume_assert_same( 1.0, similarite_genres( $c['lanternes'], $c['veilleurs'] ) );
		yume_assert_same( 0.5, similarite_genres( $c['lanternes'], $c['arcs'] ) );
		yume_assert_same( 1 / 3, similarite_genres( $c['lanternes'], $c['hoshizora'] ) );
		yume_assert_same( 0.0, similarite_genres( $c['lanternes'], $c['isole'] ) );
		yume_assert_same( 0.0, similarite_genres( $c['lanternes'], 0 ) );
		$sans = yume_trc_oeuvre( 'Sans genre' );
		yume_assert_same( 0.0, similarite_genres( $sans, yume_trc_oeuvre( 'Sans genre 2' ) ), 'deux œuvres sans genre' );
	}
);

yume_trc_test(
	'cofavoris() : agrégat à partir de 3 lecteurs communs seulement (k-anonymat)',
	static function () {
		yume_assert_same( 3, SEUIL_COFAVORIS );
		$c = yume_trc_catalogue();
		yume_trc_favoris( array( $c['lanternes'], $c['cerisier'] ), 3 );
		yume_trc_favoris( array( $c['lanternes'], $c['isole'] ), 2 );
		yume_trc_favoris( array( $c['lanternes'] ), 4 );
		yume_trc_favoris( array( $c['isole'] ), 5 );
		yume_assert_same( array( $c['cerisier'] => 3 ), cofavoris( $c['lanternes'] ), 'deux lecteurs communs : absent' );
		yume_assert_same( array(), cofavoris( $c['isole'] ) );
		yume_assert_same( array(), cofavoris( 0 ) );
		yume_trc_favoris( array( $c['lanternes'], $c['isole'] ), 1 );
		yume_assert_same(
			array(
				$c['cerisier'] => 3,
				$c['isole']    => 3,
			),
			cofavoris( $c['lanternes'] ),
			'troisième lecteur commun : présent'
		);
	}
);

yume_trc_test(
	'score : genres, type, co-favoris ; ordre et départage par favoris puis dernière sortie',
	static function () {
		$c = yume_trc_catalogue();
		// Score sans favori : 3 × Jaccard + 1 × même type.
		// veilleurs 3 + 1 = 4 ; arcs 1,5 ; hoshizora 1 ; cerisier 1 ; isolé 0 (absent).
		$recos = recommandations_oeuvre( $c['lanternes'], 12 );
		yume_assert_same( array( $c['veilleurs'], $c['arcs'] ), array_slice( yume_trc_ids( $recos ), 0, 2 ) );
		yume_assert_same( 4.0, $recos[0]['score'] );
		yume_assert_same( 1.5, $recos[1]['score'] );
		yume_assert_false( in_array( $c['isole'], yume_trc_ids( $recos ), true ), 'score nul exclu' );
		yume_assert_false( in_array( $c['lanternes'], yume_trc_ids( $recos ), true ), 'jamais l’œuvre elle-même' );

		// Égalité hoshizora / cerisier (1) : départage par yume_nb_favoris, puis dernière sortie.
		yume_assert_same( array( $c['cerisier'], $c['hoshizora'] ), array_slice( yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) ), 2, 2 ), 'à défaut, identifiant croissant' );
		update_post_meta( $c['hoshizora'], 'yume_derniere_sortie', '2026-10-10 10:00:00' );
		update_post_meta( $c['cerisier'], 'yume_derniere_sortie', '2026-10-01 10:00:00' );
		yume_trc_oublier();
		invalider();
		yume_assert_same( array( $c['hoshizora'], $c['cerisier'] ), array_slice( yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) ), 2, 2 ), 'sortie la plus récente d’abord' );
		update_post_meta( $c['cerisier'], 'yume_nb_favoris', 5 );
		yume_trc_oublier();
		invalider();
		yume_assert_same( array( $c['cerisier'], $c['hoshizora'] ), array_slice( yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) ), 2, 2 ), 'plus de favoris d’abord' );

		// Co-favoris : 3 lecteurs suivent Lanternes et Isolé, 1 autre suit Lanternes seule.
		// cofav = 3 / √(4 × 3) ≈ 0,866 → score 4 × 0,866 = 3,4641.
		yume_trc_favoris( array( $c['lanternes'], $c['isole'] ), 3 );
		yume_trc_favoris( array( $c['lanternes'] ), 1 );
		yume_trc_oublier();
		$recos = recommandations_oeuvre( $c['lanternes'], 12 );
		$isole = array_values( array_filter( $recos, static fn( $r ) => $r['id'] === $c['isole'] ) );
		yume_assert_same( 1, count( $isole ), 'co-favoris : œuvre sans genre commun recommandée' );
		yume_assert_same( 3, $isole[0]['cofavoris'] );
		yume_assert_same( round( 4 * 3 / sqrt( 12 ), 4 ), $isole[0]['score'] );
		yume_assert_same( null, $recos[0]['cofavoris'], 'pas de co-favoris : null' );

		// Poids filtrés.
		$poids = static fn() => array(
			'genres'    => 0,
			'type'      => 1,
			'cofavoris' => 0,
		);
		add_filter( 'yume_recommandations_poids', $poids );
		try {
			yume_trc_oublier();
			invalider();
			$ids = yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) );
			sort( $ids );
			$attendu = array( $c['veilleurs'], $c['cerisier'] );
			sort( $attendu );
			yume_assert_same( $attendu, $ids, 'type seul : les light novels' );
		} finally {
			remove_filter( 'yume_recommandations_poids', $poids );
		}
	}
);

yume_trc_test(
	'format : titre, adresse, image, type, genres communs, co-favoris, score',
	static function () {
		$c     = yume_trc_catalogue();
		$recos = recommandations_oeuvre( $c['lanternes'], 1 );
		yume_assert_same( 1, count( $recos ), 'limite' );
		yume_assert_same( array( 'id', 'titre', 'url', 'image', 'type', 'genres_communs', 'cofavoris', 'score' ), array_keys( $recos[0] ) );
		yume_assert_same( 'Les Veilleurs', $recos[0]['titre'] );
		yume_assert_same( get_permalink( $c['veilleurs'] ), $recos[0]['url'] );
		yume_assert_same( '', $recos[0]['image'], 'pas de couverture' );
		yume_assert_true( '' !== $recos[0]['type'], 'type renseigné' );
		$genres = $recos[0]['genres_communs'];
		sort( $genres );
		yume_assert_same( array( 'Drame', 'Fantasy' ), $genres );
	}
);

yume_trc_test(
	'exclusions : brouillon, licenciée, série à venir, filtre des candidates, statuts exclus filtrés',
	static function () {
		$c         = yume_trc_catalogue();
		$brouillon = yume_trc_oeuvre(
			'Brouillon',
			array( 'yume_genre' => array( 'fantasy', 'drame' ) ),
			array( 'post_status' => 'draft' )
		);
		$a_venir   = yume_trc_oeuvre(
			'Série à venir',
			array( 'yume_genre' => array( 'fantasy', 'drame' ) ),
			array(
				'post_status' => 'draft',
				'meta_input'  => array( 'yume_serie_a_venir' => '1' ),
			)
		);
		$licence   = yume_trc_oeuvre(
			'Licenciée',
			array(
				'yume_type'   => 'light-novel',
				'yume_genre'  => array( 'fantasy', 'drame' ),
				'yume_statut' => 'licenciee',
			)
		);
		$abandon   = yume_trc_oeuvre(
			'Abandonnée',
			array(
				'yume_type'   => 'light-novel',
				'yume_genre'  => array( 'fantasy', 'drame' ),
				'yume_statut' => 'abandonnee',
			)
		);
		$ids       = yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) );
		foreach ( array( $brouillon, $a_venir, $licence ) as $exclue ) {
			yume_assert_false( in_array( $exclue, $ids, true ), 'exclue : ' . get_the_title( $exclue ) );
		}
		yume_assert_true( in_array( $abandon, $ids, true ), 'abandonnée gardée' );

		$statuts = static fn() => array( 'abandonnee' );
		add_filter( 'yume_recommandations_statuts_exclus', $statuts );
		try {
			yume_trc_oublier();
			invalider();
			$ids = yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) );
			yume_assert_true( in_array( $licence, $ids, true ), 'licenciée réintégrée par le filtre' );
			yume_assert_false( in_array( $abandon, $ids, true ), 'abandonnée exclue par le filtre' );
		} finally {
			remove_filter( 'yume_recommandations_statuts_exclus', $statuts );
		}

		$candidates = static function ( array $ids, int $oeuvre ) use ( $c, $brouillon ) {
			yume_assert_same( $c['lanternes'], $oeuvre );
			$ids   = array_values( array_diff( $ids, array( $c['veilleurs'] ) ) );
			$ids[] = $brouillon; // Ajout ignoré : pas une candidate de base.
			return $ids;
		};
		add_filter( 'yume_recommandations_candidates', $candidates, 10, 2 );
		try {
			yume_trc_oublier();
			invalider();
			$ids = yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) );
			yume_assert_false( in_array( $c['veilleurs'], $ids, true ), 'retirée par le filtre' );
			yume_assert_false( in_array( $brouillon, $ids, true ), 'brouillon ajouté par le filtre ignoré' );
		} finally {
			remove_filter( 'yume_recommandations_candidates', $candidates, 10 );
		}

		yume_assert_same( array(), recommandations_oeuvre( $brouillon ), 'œuvre de départ non publiée' );
		yume_assert_same( array(), recommandations_oeuvre( yume_factory_post( array( 'post_title' => 'Article' ) ) ), 'pas une œuvre' );
	}
);

yume_trc_test(
	'minimum : rien si moins de 2 œuvres ont un score positif (filtre), rien si inactif',
	static function () {
		$a = yume_trc_oeuvre( 'A', array( 'yume_genre' => 'fantasy' ) );
		$b = yume_trc_oeuvre( 'B', array( 'yume_genre' => 'fantasy' ) );
		yume_trc_oeuvre( 'C', array( 'yume_genre' => 'horreur' ) );
		yume_assert_same( array(), recommandations_oeuvre( $a ), 'une seule œuvre proche' );
		$un = static fn() => 1;
		add_filter( 'yume_recommandations_minimum', $un );
		try {
			yume_assert_same( array( $b ), yume_trc_ids( recommandations_oeuvre( $a ) ), 'minimum à 1' );
		} finally {
			remove_filter( 'yume_recommandations_minimum', $un );
		}
		yume_trc_oeuvre( 'D', array( 'yume_genre' => 'fantasy' ) );
		yume_trc_oublier();
		yume_assert_same( 2, count( recommandations_oeuvre( $a ) ), 'deux œuvres proches' );
		add_filter( 'yume_recommandations_actives', '__return_false' );
		try {
			yume_assert_same( array(), recommandations_oeuvre( $a ), 'module inactif' );
		} finally {
			remove_filter( 'yume_recommandations_actives', '__return_false' );
		}
	}
);

yume_trc_test(
	'membre : favoris et lectures retirés, repli sur les suivantes ; visiteur inchangé',
	static function () {
		$c       = yume_trc_catalogue();
		$visiteu = yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 2 ) );
		yume_assert_same( array( $c['veilleurs'], $c['arcs'] ), $visiteu );
		$membre = yume_factory_user( 'subscriber' );
		\Yume\Core\Social\ajouter_favori( $membre, $c['veilleurs'] );
		yume_trc_lecture( $membre, $c['arcs'] );
		$ids = yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 2, $membre ) );
		yume_assert_same( 2, count( $ids ), 'repli sur les suivantes' );
		yume_assert_false( in_array( $c['veilleurs'], $ids, true ), 'favori retiré' );
		yume_assert_false( in_array( $c['arcs'], $ids, true ), 'lecture retirée' );
		yume_assert_same( $visiteu, yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 2 ) ), 'visiteur : même liste' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Cache
 * -----------------------------------------------------------------------------
 */

yume_trc_test(
	'cache : transient versionné ; nouvelle version sur favori, état d’œuvre, termes, tome publié, suppression',
	static function () {
		$c = yume_trc_catalogue();
		yume_trc_oublier();
		invalider();
		yume_trc_oublier();
		recommandations_oeuvre( $c['lanternes'] );
		$cle = cle_cache( $c['lanternes'] );
		yume_assert_same( 'yume_reco_' . $c['lanternes'] . '_' . version(), $cle );
		yume_assert_true( is_array( get_transient( $cle ) ), 'transient créé' );

		$lecteur    = yume_factory_user( 'subscriber' );
		$tome       = yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => 'Tome 1',
				'post_status' => 'draft',
				'meta_input'  => array( 'yume_oeuvre_id' => $c['arcs'] ),
			)
		);
		$evenements = array(
			'favori ajouté'  => static fn() => \Yume\Core\Social\ajouter_favori( $lecteur, $c['veilleurs'] ),
			'favori retiré'  => static fn() => \Yume\Core\Social\retirer_favori( $lecteur, $c['veilleurs'] ),
			'état changé'    => static fn() => do_action( 'yume_oeuvre_etat_change', $c['arcs'], 'en-cours', 'licenciee', 1 ),
			'termes'         => static fn() => wp_set_object_terms( $c['isole'], array( 'fantasy' ), 'yume_genre' ),
			'tome publié'    => static fn() => do_action( 'yume_tome_publie', $tome ),
			'œuvre modifiée' => static fn() => wp_update_post(
				array(
					'ID'         => $c['cerisier'],
					'post_title' => 'Sous le Cerisier (nouveau titre)',
				)
			),
			'œuvre effacée'  => static fn() => wp_delete_post( $c['hoshizora'], true ),
		);
		foreach ( $evenements as $nom => $evenement ) {
			$avant = version();
			delete_transient( TAMPON );
			$evenement();
			yume_assert_true( version() !== $avant, 'nouvelle version : ' . $nom );
		}

		// Un article ou un chapitre ne change rien.
		$avant = version();
		delete_transient( TAMPON );
		yume_factory_post( array( 'post_title' => 'Article' ) );
		yume_assert_same( $avant, version(), 'article : même version' );

		// Les termes changés comptent à la lecture suivante.
		yume_trc_oublier();
		yume_assert_true( in_array( $c['isole'], yume_trc_ids( recommandations_oeuvre( $c['lanternes'], 12 ) ), true ), 'genre ajouté pris en compte' );
	}
);

yume_trc_test(
	'cache : une version par minute au plus, invalidation en attente appliquée après le délai',
	static function () {
		$c = yume_trc_catalogue();
		yume_trc_oublier();
		invalider();
		$v1 = version();
		yume_assert_true( false !== get_transient( TAMPON ), 'tampon posé' );
		recommandations_oeuvre( $c['lanternes'] );
		invalider();
		yume_assert_same( $v1, version(), 'dans la minute : même version' );
		yume_assert_same( '1', get_option( OPTION_ATTENTE ), 'invalidation notée' );
		delete_transient( TAMPON );
		$v2 = version();
		yume_assert_true( $v2 !== $v1, 'après le délai : nouvelle version à la lecture' );
		yume_assert_false( get_option( OPTION_ATTENTE ), 'attente levée' );
		yume_assert_same( $v2, (string) get_option( OPTION_VERSION ) );

		$six_heures = static fn() => 0;
		add_filter( 'yume_recommandations_cache', $six_heures );
		try {
			yume_trc_oublier();
			recommandations_oeuvre( $c['lanternes'] );
			yume_assert_false( get_transient( cle_cache( $c['lanternes'] ) ), 'durée 0 : aucun transient' );
		} finally {
			remove_filter( 'yume_recommandations_cache', $six_heures );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Bloc
 * -----------------------------------------------------------------------------
 */

yume_trc_test(
	'bloc : enregistré comme les autres blocs du plugin, placé après yume/oeuvre-news dans le modèle',
	static function () {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'yume/recommandations' );
		yume_assert_true( $type instanceof WP_Block_Type, 'bloc enregistré' );
		yume_assert_same( 'yume', $type->category );
		yume_assert_same( 3, (int) $type->api_version );
		yume_assert_true( is_callable( $type->render_callback ), 'rendu serveur' );
		yume_assert_true( in_array( 'yume/recommandations', $GLOBALS['yume_dynamic_blocks'], true ), 'via yume_register_dynamic_block' );
		yume_assert_false( (bool) ( $type->supports['html'] ?? true ) );
		yume_assert_same( 4, $type->attributes['count']['default'] );
		yume_assert_true( in_array( 'yume-bibliotheque', $type->style_handles, true ), 'feuille commune' );
		yume_assert_true( count( $type->style_handles ) > 1, 'feuille propre' );

		if ( 'yume' === get_stylesheet() ) {
			$fiche = get_block_template( 'yume//single-yume_oeuvre' );
			yume_assert_true( $fiche instanceof WP_Block_Template );
			yume_assert_same( 1, substr_count( $fiche->content, 'wp:yume/recommandations' ), 'un seul bloc' );
			yume_assert_true( strpos( $fiche->content, 'wp:yume/recommandations' ) > strpos( $fiche->content, 'wp:yume/oeuvre-news' ), 'après les actualités' );
			yume_assert_true( strpos( $fiche->content, 'wp:yume/recommandations' ) < strpos( $fiche->content, 'yn-fiche__commentaires' ), 'avant les commentaires' );
		}

		// Modèle « Œuvre » personnalisé avant le bloc : insertion après yume/oeuvre-news.
		$modele          = new WP_Block_Template();
		$modele->slug    = 'single-yume_oeuvre';
		$modele->content = '<!-- wp:yume/oeuvre-news {"limite":3} /-->';
		yume_assert_same( array( 'yume/recommandations' ), accrocher_au_modele( array(), 'after', 'yume/oeuvre-news', $modele ) );
		yume_assert_contains( '<!-- wp:yume/recommandations /-->', apply_block_hooks_to_content( $modele->content, $modele, 'insert_hooked_blocks' ) );
		yume_assert_same( array(), accrocher_au_modele( array(), 'before', 'yume/oeuvre-news', $modele ) );
		$modele->content = '<!-- wp:yume/oeuvre-news /--><!-- wp:yume/recommandations /-->';
		yume_assert_same( array(), accrocher_au_modele( array(), 'after', 'yume/oeuvre-news', $modele ), 'déjà présent' );
		$modele->slug    = 'single-yume_oeuvre-actualites';
		$modele->content = '<!-- wp:yume/oeuvre-news /-->';
		yume_assert_same( array(), accrocher_au_modele( array(), 'after', 'yume/oeuvre-news', $modele ), 'pas sur une sous-page' );
	}
);

yume_trc_test(
	'bloc : HTML identique pour les visiteurs, rien hors fiche ni sans données, membre sans ses favoris',
	static function () {
		$c    = yume_trc_catalogue();
		$html = yume_trc_bloc( $c['lanternes'] );
		yume_assert_contains( 'class="yn-recommandations', $html );
		yume_assert_contains( 'Si vous avez aimé Les Lanternes de Brume-Haute', $html );
		yume_assert_same( 4, substr_count( $html, 'class="yn-recommandations__item"' ), '4 cartes par défaut' );
		yume_assert_contains( 'Les Veilleurs', $html );
		yume_assert_contains( 'class="yn-cover', $html );
		yume_assert_contains( '<span class="yn-chip">Fantasy</span>', $html );
		yume_assert_not_contains( 'lecteurs la suivent', $html, 'aucun co-favori' );
		yume_trc_oublier();
		yume_assert_same( $html, yume_trc_bloc( $c['lanternes'] ), 'deux rendus anonymes identiques' );
		yume_assert_same( 2, substr_count( yume_trc_bloc( $c['lanternes'], array( 'count' => 2 ) ), 'class="yn-recommandations__item"' ), 'attribut count' );

		// Requête principale sur la fiche, sans contexte de bloc : même rendu.
		global $wp_query, $wp_the_query;
		$wp_query     = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			array(
				'p'         => $c['lanternes'],
				'post_type' => 'yume_oeuvre',
			)
		);
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_same( $html, yume_trc_bloc( 0 ), 'objet de la requête' );

		// Co-favoris affichés à partir de 3 lecteurs communs.
		yume_trc_favoris( array( $c['lanternes'], $c['veilleurs'] ), 3 );
		yume_trc_oublier();
		yume_assert_contains( '3 lecteurs la suivent aussi', yume_trc_bloc( $c['lanternes'] ) );

		// Membre connecté : ses favoris disparaissent, la suivante prend la place.
		$membre = yume_factory_user( 'subscriber' );
		\Yume\Core\Social\ajouter_favori( $membre, $c['veilleurs'] );
		wp_set_current_user( $membre );
		$perso = yume_trc_bloc( $c['lanternes'] );
		wp_set_current_user( 0 );
		yume_assert_not_contains( 'Les Veilleurs', $perso );
		yume_assert_same( 3, substr_count( $perso, 'class="yn-recommandations__item"' ), 'les trois autres' );

		// Rien hors fiche, rien sans données.
		$article      = yume_factory_post( array( 'post_title' => 'Article' ) );
		$wp_query     = new WP_Query( array( 'p' => $article ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_same( '', yume_trc_bloc( $article ), 'article' );
		yume_assert_same( '', yume_trc_bloc( 0 ), 'sans contexte' );
		yume_assert_same( '', yume_trc_bloc( $c['isole'] ), 'œuvre sans œuvre proche' );
		$brouillon = yume_trc_oeuvre( 'Brouillon', array( 'yume_genre' => 'fantasy' ), array( 'post_status' => 'draft' ) );
		yume_assert_same( '', yume_trc_bloc( $brouillon ), 'œuvre non publiée' );
	}
);

yume_trc_test(
	'rendu_cartes() : titres, types et genres échappés, aucune carte sans recommandation',
	static function () {
		yume_assert_same( '', rendu_cartes( array(), array() ) );
		$html = rendu_cartes(
			array(
				array(
					'id'             => 999999,
					'titre'          => '<script>alert(1)</script> & co',
					'url'            => 'javascript:alert(1)',
					'image'          => '',
					'type'           => '<b>Type</b>',
					'genres_communs' => array( '<i>genre</i>' ),
					'cofavoris'      => 2,
					'score'          => 1.0,
					'parce_que'      => array( 0 ),
				),
			),
			array( 'raisons' => true )
		);
		yume_assert_not_contains( '<script>', $html );
		yume_assert_not_contains( '<b>', $html );
		yume_assert_not_contains( '<i>', $html );
		yume_assert_not_contains( 'javascript:', $html );
		yume_assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; co', $html );
		yume_assert_not_contains( 'lecteurs la suivent', $html, 'moins de 3 : rien' );
		yume_assert_not_contains( 'parce que', $html, 'source inconnue : rien' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Mon compte
 * -----------------------------------------------------------------------------
 */

yume_trc_test(
	'compte : rubrique « Pour vous » après Lecture en cours, invitation sans favori, cartes « parce que vous suivez »',
	static function () {
		$c      = yume_trc_catalogue();
		$membre = yume_factory_user( 'subscriber' );
		$user   = get_userdata( $membre );

		$rubriques = apply_filters( 'yume_compte_rubriques', \Yume\Core\Social\rubriques_compte(), $membre );
		$ancres    = array_keys( $rubriques );
		yume_assert_same( 'yn-pour-vous', $ancres[ array_search( 'yn-lecture', $ancres, true ) + 1 ], 'après yn-lecture' );
		yume_assert_same( 'Pour vous', $rubriques['yn-pour-vous'] );

		wp_set_current_user( $membre );
		$sections = \Yume\Core\Social\sections_compte( $user, array() );
		yume_assert_same( array_values( array_intersect( array_keys( $sections ), $ancres ) ), array_values( array_intersect( $ancres, array_keys( $sections ) ) ), 'sections dans l’ordre des rubriques' );
		yume_assert_contains( 'id="yn-pour-vous"', $sections['yn-pour-vous'] );
		yume_assert_contains( 'data-yn-panneau="yn-pour-vous"', $sections['yn-pour-vous'] );
		yume_assert_contains( 'Ajoutez des favoris pour recevoir des suggestions', $sections['yn-pour-vous'] );
		yume_assert_not_contains( 'yn-recommandations__item', $sections['yn-pour-vous'] );

		// Après « À rattraper » (lot L5) quand cette rubrique existe.
		$apres = \Yume\Core\Recommandations\rubrique_compte(
			array(
				'yn-lecture'    => 'Lecture',
				'yn-rattrapage' => 'À rattraper',
				'yn-stats'      => 'Stats',
			)
		);
		yume_assert_same( array( 'yn-lecture', 'yn-rattrapage', 'yn-pour-vous', 'yn-stats' ), array_keys( $apres ) );

		\Yume\Core\Social\ajouter_favori( $membre, $c['lanternes'] );
		yume_trc_lecture( $membre, $c['cerisier'] );
		$recos = recommandations_membre( $membre );
		$ids   = yume_trc_ids( $recos );
		yume_assert_true( count( $recos ) > 0 && count( $recos ) <= 6 );
		yume_assert_false( in_array( $c['lanternes'], $ids, true ), 'favori exclu' );
		yume_assert_false( in_array( $c['cerisier'], $ids, true ), 'lecture exclue' );
		// Veilleurs : 4 (depuis Lanternes) + 1 (depuis Cerisier), Lanternes d'abord.
		yume_assert_same( $c['veilleurs'], $ids[0] );
		yume_assert_same( 5.0, $recos[0]['score'] );
		yume_assert_same( array( $c['lanternes'], $c['cerisier'] ), $recos[0]['parce_que'] );
		$hoshi = array_values( array_filter( $recos, static fn( $r ) => $r['id'] === $c['hoshizora'] ) );
		yume_assert_same( 2, count( $hoshi[0]['parce_que'] ), 'deux sources agrégées' );

		$html = \Yume\Core\Social\sections_compte( $user, array() )['yn-pour-vous'];
		yume_assert_contains( 'parce que vous suivez Les Lanternes de Brume-Haute', $html );
		yume_assert_contains( 'yn-recommandations__grille', $html );
		yume_assert_not_contains( 'Ajoutez des favoris', $html );

		add_filter( 'yume_recommandations_actives', '__return_false' );
		try {
			yume_assert_false( isset( apply_filters( 'yume_compte_rubriques', \Yume\Core\Social\rubriques_compte(), $membre )['yn-pour-vous'] ), 'inactif : pas de rubrique' );
		} finally {
			remove_filter( 'yume_recommandations_actives', '__return_false' );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

yume_trc_test(
	'REST : /recommandations/{oeuvre} publique et non personnalisée, /moi/recommandations réservée',
	static function () {
		$c       = yume_trc_catalogue();
		$reponse = yume_rest( 'GET', '/yume/v1/recommandations/' . $c['lanternes'] );
		yume_assert_same( 200, $reponse->get_status() );
		yume_assert_same( 'public, max-age=3600', $reponse->get_headers()['Cache-Control'] ?? '' );
		$donnees = $reponse->get_data();
		yume_assert_same( $c['lanternes'], $donnees['oeuvre'] );
		yume_assert_same( 4, count( $donnees['recommandations'] ) );
		yume_assert_same( $c['veilleurs'], $donnees['recommandations'][0]['id'] );

		$membre = yume_factory_user( 'subscriber' );
		\Yume\Core\Social\ajouter_favori( $membre, $c['veilleurs'] );
		yume_assert_same( $donnees, yume_rest( 'GET', '/yume/v1/recommandations/' . $c['lanternes'], array(), $membre )->get_data(), 'membre : même réponse publique' );

		$brouillon = yume_trc_oeuvre( 'Brouillon', array(), array( 'post_status' => 'draft' ) );
		$erreur    = yume_rest( 'GET', '/yume/v1/recommandations/' . $brouillon );
		yume_assert_same( 404, $erreur->get_status() );
		yume_assert_same( 'yume_oeuvre_introuvable', $erreur->get_data()['code'] );
		yume_assert_same( 404, yume_rest( 'GET', '/yume/v1/recommandations/999999' )->get_status() );

		yume_assert_same( 401, yume_rest( 'GET', '/yume/v1/moi/recommandations' )->get_status() );
		$moi = yume_rest( 'GET', '/yume/v1/moi/recommandations', array(), $membre );
		yume_assert_same( 200, $moi->get_status() );
		yume_assert_same( 'private, no-store', $moi->get_headers()['Cache-Control'] ?? '' );
		$ids = yume_trc_ids( $moi->get_data() );
		yume_assert_true( count( $ids ) > 0 );
		yume_assert_false( in_array( $c['veilleurs'], $ids, true ) );
		yume_assert_same( array( $c['veilleurs'] ), $moi->get_data()[0]['parce_que'] );
	}
);
