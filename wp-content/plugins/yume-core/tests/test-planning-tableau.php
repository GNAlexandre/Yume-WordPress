<?php
/**
 * Tests du tableau du planning public (/planning/) : barres d'avancement lisibles par les
 * lecteurs d'écran, tomes publiés chapitre par chapitre (« En cours · N chapitres sur M »,
 * mini-barre, prochain chapitre programmé, pastille « En cours de publication »), tome
 * classique inchangé, légende et nombre de requêtes indépendant du nombre de lignes.
 *
 * Lancement : tools/localenv/test.sh planning-tableau
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\tableau_planning;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tpt_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé des œuvres, tomes et chapitres déjà présents dans la base (vidés dans
 * la transaction du test, annulée ensuite par le lanceur).
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tpt_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			wp_cache_flush();
			$corps();
		}
	);
}

/**
 * Crée une œuvre publiée.
 *
 * @param string $titre Titre.
 */
function yume_tpt_oeuvre( string $titre ): int {
	$id = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => 'publish',
		)
	);
	wp_set_object_terms( $id, 'light-novel', 'yume_type' );
	return $id;
}

/**
 * Crée un tome (sans notification).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $numero    Numéro.
 * @param string $statut    Statut.
 * @param array  $meta      Métadonnées.
 */
function yume_tpt_tome( int $oeuvre_id, int $numero, string $statut, array $meta = array() ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
				'post_status' => $statut,
				'meta_input'  => array_merge(
					array(
						'yume_oeuvre_id'    => $oeuvre_id,
						'yume_numero'       => $numero,
						'yume_nature'       => 'tome',
						'yume_etape'        => 'traduction',
						'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
						'yume_date_cible'   => wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ),
					),
					$meta
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Crée un chapitre d'un tome : en ligne, ou programmé à une date locale (« Y-m-d H:i:s »).
 *
 * @param int    $tome_id Tome.
 * @param int    $numero  Numéro.
 * @param string $date    Date locale de sortie programmée (vide : en ligne).
 */
function yume_tpt_chapitre( int $tome_id, int $numero, string $date = '' ): int {
	add_filter( 'yume_core_notifier', '__return_false' );
	try {
		return yume_factory_post(
			array(
				'post_type'   => 'yume_chapitre',
				'post_title'  => 'Chapitre ' . $numero,
				'post_status' => '' === $date ? 'publish' : 'future',
				'post_date'   => '' === $date ? current_time( 'mysql' ) : $date,
				'menu_order'  => $numero,
				'meta_input'  => array(
					'yume_tome_id' => $tome_id,
					'yume_numero'  => $numero,
					'yume_nature'  => 'chapitre',
				),
			)
		);
	} finally {
		remove_filter( 'yume_core_notifier', '__return_false' );
	}
}

/**
 * Date locale (fuseau du site) dans N jours, à une heure donnée.
 *
 * @param int $jours Jours.
 * @param int $heure Heure.
 */
function yume_tpt_date( int $jours, int $heure ): string {
	return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+' . $jours . ' days' )->setTime( $heure, 0 )->format( 'Y-m-d H:i:s' );
}

/**
 * Tome publié chapitre par chapitre : 3 chapitres en ligne et 2 programmés (le prochain dans
 * 2 jours à 18 h), 12 chapitres prévus ou aucun.
 *
 * @param int $oeuvre_id Œuvre.
 * @param int $numero    Numéro.
 * @param int $prevus    Chapitres prévus (0 : inconnu).
 * @return array{tome:int,prochain:int}
 */
function yume_tpt_tome_en_cours( int $oeuvre_id, int $numero, int $prevus = 12 ): array {
	$meta = array(
		'yume_parution'   => 'en_cours',
		'yume_avancement' => array(
			'traduction' => 42,
			'relecture'  => 33,
			'edition'    => 33,
		),
	);
	if ( $prevus > 0 ) {
		$meta['yume_chapitres_prevus'] = $prevus;
	}
	$tome = yume_tpt_tome( $oeuvre_id, $numero, 'publish', $meta );
	for ( $i = 1; $i <= 3; $i++ ) {
		yume_tpt_chapitre( $tome, $i );
	}
	$prochain = yume_tpt_chapitre( $tome, 4, yume_tpt_date( 2, 18 ) );
	yume_tpt_chapitre( $tome, 5, yume_tpt_date( 9, 18 ) );
	return array(
		'tome'     => $tome,
		'prochain' => $prochain,
	);
}

/**
 * Tableau du planning public.
 */
function yume_tpt_tableau(): string {
	return tableau_planning(
		yume_get_planning(),
		array(
			'type'   => '',
			'etat'   => '',
			'oeuvre' => 0,
		),
		false
	);
}

/**
 * Ligne (<tr>) du tableau qui contient un texte.
 *
 * @param string $html  Tableau.
 * @param string $texte Texte cherché.
 */
function yume_tpt_ligne( string $html, string $texte ): string {
	foreach ( explode( '<tr ', $html ) as $morceau ) {
		if ( str_contains( $morceau, $texte ) ) {
			return substr( $morceau, 0, (int) strpos( $morceau, '</tr>' ) );
		}
	}
	return '';
}

/**
 * Texte lisible d'un fragment (sans balises, espaces insécables normalisées).
 *
 * @param string $html Fragment.
 */
function yume_tpt_texte( string $html ): string {
	return str_replace( array( "\u{00A0}", "\u{202F}" ), ' ', wp_strip_all_tags( $html ) );
}

/*
 * -----------------------------------------------------------------------------
 * Tests
 * -----------------------------------------------------------------------------
 */

yume_tpt_test(
	'tableau du planning : chaque étape a sa barre (role progressbar) et son pourcentage',
	static function () {
		$oeuvre = yume_tpt_oeuvre( 'Grimgar of Fantasy and Ash' );
		yume_tpt_tome(
			$oeuvre,
			10,
			'draft',
			array(
				'yume_etape'      => 'relecture',
				'yume_avancement' => array(
					'traduction' => 100,
					'relecture'  => 62,
					'edition'    => 0,
				),
			)
		);
		$html = yume_tpt_tableau();
		yume_assert_same( 3, substr_count( $html, 'role="progressbar"' ), 'une barre par étape' );
		yume_assert_contains( '<span class="yn-planning__progression" role="progressbar" aria-label="Relecture" aria-valuemin="0" aria-valuemax="100" aria-valuenow="62" aria-valuetext="62' . "\u{00A0}" . '%">', $html );
		yume_assert_contains( 'aria-label="Traduction" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100"', $html );
		yume_assert_contains( 'aria-label="Édition" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"', $html );
		yume_assert_contains( 'class="yn-bar" style="--v:62%"', $html, 'barre de la relecture' );
		yume_assert_contains( '<span class="yn-planning__pct">62' . "\u{00A0}" . '%</span>', $html, 'pourcentage écrit' );
		yume_assert_contains( '<span class="yn-planning__pct yn-muted">0' . "\u{00A0}" . '%</span>', $html, 'étape à faire : 0 %' );
		yume_assert_contains( '<td role="cell" data-label="Relecture">', $html, 'libellé de colonne pour les cartes' );
	}
);

yume_tpt_test(
	'tableau du planning : tome en cours de publication (chapitres en ligne, prochain chapitre, pastille)',
	static function () {
		$oeuvre = yume_tpt_oeuvre( 'SukaMoka' );
		$t      = yume_tpt_tome_en_cours( $oeuvre, 2 );
		yume_assert_same( 'en_cours', yume_parution_tome( $t['tome'] ) );
		$html  = yume_tpt_tableau();
		$ligne = yume_tpt_ligne( $html, 'SukaMoka' );
		yume_assert_contains( 'yn-planning__ligne--en-cours', $ligne );
		yume_assert_contains( 'En cours · 3 chapitres sur 12', yume_tpt_texte( $ligne ) );
		yume_assert_not_contains( 'chapitre 4 / 5', $ligne, 'pas de doublon dans le complément du titre' );
		// Mini-barre : 12 segments, 3 en ligne, 2 programmés.
		yume_assert_contains( '<span class="yn-planning__segments" aria-hidden="true">', $ligne );
		yume_assert_same( 3, substr_count( $ligne, '<span class="est-en-ligne"></span>' ) );
		yume_assert_same( 2, substr_count( $ligne, '<span class="est-programme"></span>' ) );
		yume_assert_same( 7, substr_count( $ligne, '<span></span>' ) );
		// Prochain chapitre : le plus tôt des deux, date et heure du site.
		$ts = get_post_datetime( $t['prochain'], 'date', 'gmt' )->getTimestamp();
		yume_assert_contains( 'Prochain : Chapitre 4 · ' . \Yume\Core\Publication\Formulaire::date_fr( $ts, 'court' ), yume_tpt_texte( $ligne ) );
		yume_assert_contains( '18:00', yume_tpt_texte( $ligne ), 'heure locale du site' );
		yume_assert_contains( '<time datetime="' . gmdate( 'c', $ts ) . '">', $ligne );
		yume_assert_contains( 'yn-chip yn-chip--chapitre-programme', $ligne );
		// Colonne État : « En cours de publication » puis l'état du planning.
		yume_assert_contains( '<div class="yn-planning__etats"><span class="yn-chip yn-chip--en-cours"><span aria-hidden="true">◐</span> En cours de publication</span><span class="yn-chip yn-chip--ok"', $ligne );
		yume_assert_contains( 'aria-valuenow="42"', $ligne );
	}
);

yume_tpt_test(
	'tableau du planning : tome en cours sans nombre de chapitres prévus ni chapitre programmé',
	static function () {
		$oeuvre  = yume_tpt_oeuvre( 'Les Lanternes de Brume-Haute' );
		$tome    = yume_tpt_tome(
			$oeuvre,
			3,
			'publish',
			array( 'yume_parution' => 'en_cours' )
		);
		$premier = yume_tpt_chapitre( $tome, 1 );
		yume_tpt_chapitre( $tome, 2 );
		$ligne = yume_tpt_ligne( yume_tpt_tableau(), 'Lanternes' );
		yume_assert_contains( 'En cours · 2 chapitres en ligne', yume_tpt_texte( $ligne ) );
		yume_assert_not_contains( 'chapitres sur', $ligne );
		yume_assert_not_contains( 'yn-planning__segments', $ligne, 'pas de barre sans fin connue' );
		yume_assert_not_contains( 'Prochain :', $ligne );
		yume_assert_contains( 'En cours de publication', $ligne );

		// Un seul chapitre : singulier.
		wp_trash_post( $premier );
		yume_assert_contains( 'En cours · 1 chapitre en ligne', yume_tpt_texte( yume_tpt_ligne( yume_tpt_tableau(), 'Lanternes' ) ) );
	}
);

yume_tpt_test(
	'tableau du planning : un tome classique reste inchangé (ni ligne chapitres ni pastille de parution)',
	static function () {
		$oeuvre = yume_tpt_oeuvre( 'Grimgar of Fantasy and Ash' );
		$tome   = yume_tpt_tome( $oeuvre, 10, 'draft', array( 'yume_etape' => 'relecture' ) );
		yume_tpt_tome_en_cours( yume_tpt_oeuvre( 'SukaMoka' ), 2 );
		$ligne = yume_tpt_ligne( yume_tpt_tableau(), 'Grimgar' );
		yume_assert_true( '' !== $ligne, 'ligne trouvée' );
		yume_assert_contains( '<span class="yn-muted yn-planning__tome">Tome 10</span></th>', $ligne );
		yume_assert_not_contains( 'yn-planning__ligne--en-cours', $ligne );
		yume_assert_not_contains( 'yn-planning__chapitres', $ligne );
		yume_assert_not_contains( 'En cours de publication', $ligne );
		yume_assert_not_contains( 'yn-planning__etats', $ligne );
		yume_assert_contains( '<td role="cell" data-label="État"><span class="yn-chip yn-chip--ok"><span aria-hidden="true">●</span> À l’heure</span></td>', $ligne );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $tome ) );
	}
);

yume_tpt_test(
	'tableau du planning : le nombre de requêtes ne dépend pas du nombre de tomes en cours de publication',
	static function () {
		$compter = static function (): int {
			global $wpdb;
			$lignes = yume_get_planning();
			$avant  = $wpdb->num_queries;
			tableau_planning(
				$lignes,
				array(
					'type'   => '',
					'etat'   => '',
					'oeuvre' => 0,
				),
				false
			);
			return $wpdb->num_queries - $avant;
		};
		yume_tpt_tome_en_cours( yume_tpt_oeuvre( 'SukaMoka' ), 2 );
		$un = $compter();
		yume_tpt_tome_en_cours( yume_tpt_oeuvre( 'Les Lanternes de Brume-Haute' ), 3 );
		yume_tpt_tome_en_cours( yume_tpt_oeuvre( 'Secrets of the Silent Witch' ), 7, 0 );
		$trois = $compter();
		yume_assert_same( $un, $trois, 'requêtes constantes' );
		yume_assert_true( $trois <= 3, 'au plus trois requêtes (chapitres programmés et leur cache) : ' . $trois );
	}
);

yume_tpt_test(
	'légende du planning : « En cours de publication » et « Chapitre programmé » avec leurs pastilles',
	static function () {
		$html = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'Comment lire ce planning', $html );
		yume_assert_contains( '<li><span class="yn-chip yn-chip--en-cours"><span aria-hidden="true">◐</span> En cours de publication</span> le tome sort chapitre par chapitre, lisible en ligne au fil des sorties.</li>', $html );
		yume_assert_contains( '<li><span class="yn-chip yn-chip--chapitre-programme"><span aria-hidden="true">◷</span> Chapitre programmé</span> la date et l’heure de sortie du prochain chapitre.</li>', $html );
		yume_assert_contains( 'le tome est sorti : bonne lecture !', $html, 'entrées existantes conservées' );
		yume_assert_contains( 'la sortie est programmée : le tome paraîtra tout seul à cette date.', $html );
	}
);
