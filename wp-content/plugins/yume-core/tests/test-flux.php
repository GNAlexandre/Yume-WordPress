<?php
/**
 * Tests des flux et du calendrier (PAGE-02, AMEL-05) : calendrier ICS du planning (structure
 * RFC 5545, pliage, échappement, statuts, filtre œuvre, cache invalidé), flux RSS d'une œuvre
 * (tomes, chapitres, articles liés) et bloc yume/calendrier (mois, navigation, aujourd'hui).
 *
 * Lancement : tools/localenv/test.sh flux
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Library\elements_flux_oeuvre;
use function Yume\Core\Library\oeuvre_du_flux;
use function Yume\Core\Library\renouveler_version;
use function Yume\Core\Library\rss_oeuvre;
use function Yume\Core\Planning\calendrier_ics;
use function Yume\Core\Planning\evenements_calendrier;
use function Yume\Core\Planning\invalider_ics;
use function Yume\Core\Planning\plier_ics;
use function Yume\Core\Planning\texte_ics;
use const Yume\Core\Planning\TRANSIENT_ICS;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tf_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé : contenus Yume vidés dans la transaction, cache ICS vidé, requête
 * principale et paramètres GET restaurés, aucune notification.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tf_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb, $wp_query, $wp_the_query, $post;
			$get      = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requete  = $wp_query;
			$reelle   = $wp_the_query;
			$post_sav = $post;
			$types    = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" );
			// phpcs:enable
			wp_cache_flush();
			invalider_ics();
			renouveler_version();
			add_filter( 'yume_core_notifier', '__return_false' );
			try {
				$corps();
			} finally {
				remove_filter( 'yume_core_notifier', '__return_false' );
				$_GET         = $get;
				$wp_query     = $requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$wp_the_query = $reelle; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$post         = $post_sav; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				invalider_ics();
				renouveler_version();
			}
		}
	);
}

/**
 * Crée une œuvre.
 *
 * @param string $titre  Titre.
 * @param string $statut Statut.
 */
function yume_tf_oeuvre( string $titre, string $statut = 'publish' ): int {
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
 * @param int    $oeuvre_id Œuvre.
 * @param int    $numero    Numéro.
 * @param string $statut    Statut (draft : prévu ; future : programmé ; publish : paru).
 * @param array  $args      Arguments supplémentaires (post_date, meta_input…).
 */
function yume_tf_tome( int $oeuvre_id, int $numero, string $statut = 'draft', array $args = array() ): int {
	return yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
				'post_name'   => 'tome-' . $numero,
				'post_status' => $statut,
				'meta_input'  => array(
					'yume_oeuvre_id'    => $oeuvre_id,
					'yume_numero'       => $numero,
					'yume_nature'       => 'tome',
					'yume_etape'        => 'publish' === $statut ? 'publie' : 'relecture',
					'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
				),
			),
			$args
		)
	);
}

/**
 * Crée un chapitre.
 *
 * @param int    $tome_id Tome.
 * @param int    $numero  Numéro.
 * @param string $statut  Statut.
 */
function yume_tf_chapitre( int $tome_id, int $numero, string $statut = 'publish' ): int {
	return yume_factory_post(
		array(
			'post_type'   => 'yume_chapitre',
			'post_title'  => 'Chapitre ' . $numero . ' — Titre ' . $numero,
			'post_name'   => 'chapitre-' . $numero,
			'post_status' => $statut,
			'menu_order'  => $numero,
			'meta_input'  => array(
				'yume_tome_id' => $tome_id,
				'yume_numero'  => $numero,
				'yume_nature'  => 'chapitre',
			),
		)
	);
}

/**
 * Date locale « Y-m-d » dans $jours jours (Paris).
 *
 * @param int $jours Jours (négatif : passé).
 */
function yume_tf_jour( int $jours ): string {
	return ( new DateTimeImmutable( '@' . ( time() + $jours * DAY_IN_SECONDS ) ) )->setTimezone( new DateTimeZone( 'Europe/Paris' ) )->format( 'Y-m-d' );
}

/**
 * Jeu de données : une œuvre avec un tome paru (2 chapitres publiés, 1 brouillon), un tome
 * programmé dans 10 jours à 20 h (Paris), un tome prévu dans 20 jours ; une seconde œuvre
 * avec un tome prévu ; une œuvre en brouillon avec un tome prévu (jamais publique).
 *
 * @return array<string,int>
 */
function yume_tf_donnees(): array {
	$d          = array();
	$d['o']     = yume_tf_oeuvre( 'Brume; Haute, et Cie' );
	$d['paru']  = yume_tf_tome( $d['o'], 1, 'publish', array( 'post_date' => wp_date( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
	$d['c1']    = yume_tf_chapitre( $d['paru'], 1 );
	$d['c2']    = yume_tf_chapitre( $d['paru'], 2 );
	$d['c3']    = yume_tf_chapitre( $d['paru'], 3, 'draft' );
	$d['prog']  = yume_tf_tome( $d['o'], 2, 'future', array( 'post_date' => yume_tf_jour( 10 ) . ' 20:00:00' ) );
	$d['prevu'] = yume_tf_tome( $d['o'], 3, 'draft', array( 'meta_input' => array( 'yume_date_cible' => yume_tf_jour( 20 ) ) ) );
	$d['o2']    = yume_tf_oeuvre( 'Seconde œuvre' );
	$d['t2']    = yume_tf_tome( $d['o2'], 1, 'draft', array( 'meta_input' => array( 'yume_date_cible' => yume_tf_jour( 5 ) ) ) );
	$d['ob']    = yume_tf_oeuvre( 'Œuvre cachée', 'draft' );
	$d['tb']    = yume_tf_tome( $d['ob'], 1, 'draft', array( 'meta_input' => array( 'yume_date_cible' => yume_tf_jour( 5 ) ) ) );
	return $d;
}

/**
 * Déplie un calendrier ICS (RFC 5545 §3.1) et renvoie ses lignes logiques.
 *
 * @param string $ics Texte.
 * @return string[]
 */
function yume_tf_deplier( string $ics ): array {
	return explode( "\r\n", rtrim( str_replace( "\r\n ", '', $ics ), "\r\n" ) );
}

/**
 * Bloc VEVENT (lignes dépliées) d'un tome.
 *
 * @param string $ics     Calendrier.
 * @param int    $tome_id Tome.
 * @return string[] Lignes du VEVENT (vide s'il est absent).
 */
function yume_tf_vevent( string $ics, int $tome_id ): array {
	$lignes  = yume_tf_deplier( $ics );
	$uid     = 'UID:tome-' . $tome_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
	$courant = array();
	foreach ( $lignes as $l ) {
		if ( 'BEGIN:VEVENT' === $l ) {
			$courant = array();
		}
		$courant[] = $l;
		if ( 'END:VEVENT' === $l && in_array( $uid, $courant, true ) ) {
			return $courant;
		}
	}
	return array();
}

/*
 * -----------------------------------------------------------------------------
 * ICS : primitives
 * -----------------------------------------------------------------------------
 */

yume_test(
	'ICS : échappement TEXT des « \\ ; , » et des retours à la ligne',
	static function () {
		yume_assert_same( 'a\\\\b\\;c\\,d\\ne\\nf', texte_ics( "a\\b;c,d\r\ne\nf" ) );
	}
);

yume_test(
	'ICS : pliage à 75 octets sans couper un caractère UTF-8, dépliage fidèle',
	static function () {
		$ligne = 'SUMMARY:' . str_repeat( 'Œuvre éà ', 30 );
		$plie  = plier_ics( $ligne );
		yume_assert_true( str_ends_with( $plie, "\r\n" ), 'terminée par CRLF' );
		$morceaux = explode( "\r\n", rtrim( $plie, "\r\n" ) );
		yume_assert_true( count( $morceaux ) > 1, 'ligne pliée' );
		foreach ( $morceaux as $i => $m ) {
			yume_assert_true( strlen( $m ) <= 75, 'morceau ≤ 75 octets' );
			yume_assert_true( (bool) mb_check_encoding( $i > 0 ? substr( $m, 1 ) : $m, 'UTF-8' ), 'UTF-8 intact' );
			if ( $i > 0 ) {
				yume_assert_same( ' ', $m[0], 'suite commençant par une espace' );
			}
		}
		yume_assert_same( $ligne, str_replace( "\r\n ", '', rtrim( $plie, "\r\n" ) ) );
		yume_assert_same( "VERSION:2.0\r\n", plier_ics( 'VERSION:2.0' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * ICS : route REST
 * -----------------------------------------------------------------------------
 */

yume_tf_test(
	'ICS : GET /planning.ics public, text/calendar, structure RFC 5545 et CRLF',
	static function () {
		$d   = yume_tf_donnees();
		$rep = yume_rest( 'GET', '/yume/v1/planning.ics' );
		yume_assert_same( 200, $rep->get_status() );
		yume_assert_same( 'text/calendar; charset=utf-8', $rep->get_headers()['Content-Type'] ?? '' );
		$ics = (string) $rep->get_data();
		yume_assert_true( str_starts_with( $ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" ), 'en-tête' );
		yume_assert_true( str_ends_with( $ics, "END:VCALENDAR\r\n" ), 'fin' );
		yume_assert_same( 0, preg_match( "/(?<!\r)\n/", $ics ), 'uniquement des CRLF' );
		foreach ( explode( "\r\n", $ics ) as $l ) {
			yume_assert_true( strlen( $l ) <= 75, 'ligne ≤ 75 octets : ' . $l );
		}
		yume_assert_same( substr_count( $ics, 'BEGIN:VEVENT' ), substr_count( $ics, 'END:VEVENT' ) );
		yume_assert_same( 4, substr_count( $ics, 'BEGIN:VEVENT' ), 'paru, programmé, 2 prévus (œuvre brouillon exclue)' );
		yume_assert_same( array(), yume_tf_vevent( $ics, $d['tb'] ), 'œuvre non publiée absente' );
		foreach ( array( 'paru', 'prog', 'prevu', 't2' ) as $cle ) {
			$ev = implode( "\n", yume_tf_vevent( $ics, $d[ $cle ] ) );
			yume_assert_contains( 'UID:tome-' . $d[ $cle ] . '@', $ev );
			yume_assert_same( 1, preg_match( '/^DTSTAMP:\d{8}T\d{6}Z$/m', $ev ), 'DTSTAMP ' . $cle );
			yume_assert_contains( 'SUMMARY:', $ev );
			yume_assert_contains( 'DESCRIPTION:État : ', $ev );
			yume_assert_contains( 'URL:http', $ev );
		}
	}
);

yume_tf_test(
	'ICS : prévu en date (TENTATIVE, « prévision »), programmé et paru en UTC (CONFIRMED), échappement du titre',
	static function () {
		$d   = yume_tf_donnees();
		$ics = calendrier_ics();

		$prevu = implode( "\n", yume_tf_vevent( $ics, $d['prevu'] ) );
		yume_assert_contains( 'DTSTART;VALUE=DATE:' . str_replace( '-', '', yume_tf_jour( 20 ) ), $prevu );
		yume_assert_contains( 'STATUS:TENTATIVE', $prevu );
		yume_assert_contains( 'SUMMARY:Brume\\; Haute\\, et Cie T.3 (prévision)', $prevu );
		yume_assert_contains( 'Date indicative', $prevu );

		$prog = implode( "\n", yume_tf_vevent( $ics, $d['prog'] ) );
		$utc  = gmdate( 'Ymd\THis\Z', (int) get_post_time( 'U', true, $d['prog'] ) );
		yume_assert_contains( 'DTSTART:' . $utc, $prog );
		yume_assert_contains( 'STATUS:CONFIRMED', $prog );
		yume_assert_contains( 'SUMMARY:Brume\\; Haute\\, et Cie T.2' . "\n", $prog );
		yume_assert_contains( 'DESCRIPTION:État : Programmé le ', $prog );

		$paru = implode( "\n", yume_tf_vevent( $ics, $d['paru'] ) );
		yume_assert_same( 1, preg_match( '/^DTSTART:\d{8}T\d{6}Z$/m', $paru ) );
		yume_assert_contains( 'STATUS:CONFIRMED', $paru );
		yume_assert_contains( 'URL:' . get_permalink( $d['paru'] ), $paru );
	}
);

yume_tf_test(
	'ICS : filtre ?oeuvre= par ID ou par slug ; œuvre inconnue ou non publiée : 404',
	static function () {
		$d = yume_tf_donnees();
		foreach ( array( (string) $d['o'], get_post_field( 'post_name', $d['o'] ) ) as $valeur ) {
			$ics = (string) yume_rest( 'GET', '/yume/v1/planning.ics', array( 'oeuvre' => $valeur ) )->get_data();
			yume_assert_same( 3, substr_count( $ics, 'BEGIN:VEVENT' ), 'œuvre ' . $valeur );
			yume_assert_same( array(), yume_tf_vevent( $ics, $d['t2'] ), 'autre œuvre absente' );
		}
		yume_assert_same( 404, yume_rest( 'GET', '/yume/v1/planning.ics', array( 'oeuvre' => 'inconnue' ) )->get_status() );
		yume_assert_same( 404, yume_rest( 'GET', '/yume/v1/planning.ics', array( 'oeuvre' => (string) $d['ob'] ) )->get_status() );
		yume_assert_same( 404, yume_rest( 'GET', '/yume/v1/planning.ics', array( 'oeuvre' => (string) $d['paru'] ) )->get_status(), 'un tome n’est pas une œuvre' );
	}
);

yume_tf_test(
	'ICS : mis en cache, invalidé par yume_planning_mis_a_jour et par l’enregistrement d’un tome',
	static function () {
		$d = yume_tf_donnees();
		calendrier_ics();
		yume_assert_true( is_array( get_transient( TRANSIENT_ICS ) ), 'cache écrit' );
		// Le cache est servi tel quel, jusqu’à la mise à jour du planning.
		set_transient( TRANSIENT_ICS, array( 0 => 'EN CACHE' ), HOUR_IN_SECONDS );
		yume_assert_same( 'EN CACHE', calendrier_ics() );
		do_action( 'yume_planning_mis_a_jour', $d['prevu'], array( 'date_cible' => array() ), 0 );
		yume_assert_false( get_transient( TRANSIENT_ICS ), 'cache vidé par l’événement' );
		yume_assert_contains( 'BEGIN:VCALENDAR', calendrier_ics() );

		set_transient( TRANSIENT_ICS, array( 0 => 'EN CACHE' ), HOUR_IN_SECONDS );
		wp_update_post(
			array(
				'ID'         => $d['t2'],
				'post_title' => 'Seconde œuvre — Tome 1 bis',
			)
		);
		yume_assert_false( get_transient( TRANSIENT_ICS ), 'cache vidé par l’enregistrement' );
		yume_assert_not_contains( 'EN CACHE', calendrier_ics() );
	}
);

yume_tf_test(
	'Planning : événements datés (nature, jour de Paris), tomes non datés ignorés',
	static function () {
		$d    = yume_tf_donnees();
		$sans = yume_tf_tome( $d['o'], 4 );
		$ev   = array();
		foreach ( evenements_calendrier( $d['o'] ) as $e ) {
			$ev[ $e['tome_id'] ] = $e;
		}
		yume_assert_false( isset( $ev[ $sans ] ), 'sans date cible' );
		yume_assert_same( 'sorti', $ev[ $d['paru'] ]['nature'] );
		yume_assert_same( 'programme', $ev[ $d['prog'] ]['nature'] );
		yume_assert_same( yume_tf_jour( 10 ), $ev[ $d['prog'] ]['jour'] );
		yume_assert_same( 'prevu', $ev[ $d['prevu'] ]['nature'] );
		yume_assert_same( 0, $ev[ $d['prevu'] ]['ts'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * RSS d'une œuvre
 * -----------------------------------------------------------------------------
 */

yume_tf_test(
	'RSS œuvre : tomes et chapitres publiés (et articles liés), rien de non publié, XML valide',
	static function () {
		$d       = yume_tf_donnees();
		$terme   = get_term_by( 'slug', get_post_field( 'post_name', $d['o'] ), 'yume_oeuvre_liee' );
		$article = yume_factory_post( array( 'post_title' => 'Annonce du tome 1' ) );
		if ( $terme instanceof WP_Term ) {
			wp_set_object_terms( $article, array( (int) $terme->term_id ), 'yume_oeuvre_liee' );
		}
		$xml = rss_oeuvre( $d['o'] );
		$doc = simplexml_load_string( $xml );
		yume_assert_true( false !== $doc, 'XML bien formé' );
		$liens = array();
		foreach ( $doc->channel->item as $item ) {
			$liens[] = (string) $item->link;
		}
		yume_assert_true( in_array( get_permalink( $d['paru'] ), $liens, true ), 'tome paru' );
		yume_assert_true( in_array( get_permalink( $d['c1'] ), $liens, true ), 'chapitre 1' );
		yume_assert_true( in_array( get_permalink( $d['c2'] ), $liens, true ), 'chapitre 2' );
		yume_assert_false( in_array( get_permalink( $d['c3'] ), $liens, true ), 'chapitre brouillon absent' );
		yume_assert_not_contains( 'Tome 2', $xml, 'tome programmé absent' );
		yume_assert_not_contains( 'Tome 3', $xml, 'tome prévu absent' );
		if ( $terme instanceof WP_Term ) {
			yume_assert_true( in_array( get_permalink( $article ), $liens, true ), 'article lié' );
		}
		yume_assert_contains( '<category>Chapitre</category>', $xml );
		yume_assert_contains( '<category>Tome</category>', $xml );
		yume_assert_contains( 'Brume; Haute, et Cie · Tome 1 · Chapitre 1 — Titre 1', $xml );
		// Du plus récent au plus ancien.
		$ts = array_column( elements_flux_oeuvre( $d['o'] ), 'ts' );
		$tr = $ts;
		rsort( $tr );
		yume_assert_same( $tr, $ts );
	}
);

yume_tf_test(
	'RSS œuvre : servi pour /oeuvres/{o}/feed/ ; ?commentaires=1 garde le flux natif',
	static function () {
		global $wp_query, $wp_the_query;
		$d            = yume_tf_donnees();
		$wp_query     = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			array(
				'p'         => $d['o'],
				'post_type' => 'yume_oeuvre',
				'feed'      => 'feed',
			)
		);
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_true( is_feed() && is_singular( 'yume_oeuvre' ), 'requête de flux de l’œuvre' );
		yume_assert_same( $d['o'], oeuvre_du_flux() );
		$_GET['commentaires'] = '1';
		yume_assert_same( 0, oeuvre_du_flux() );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/calendrier
 * -----------------------------------------------------------------------------
 */

yume_tf_test(
	'Calendrier : mois demandé, grille accessible, navigation ?mois=, aujourd’hui marqué',
	static function () {
		$d      = yume_tf_donnees();
		$mois   = substr( yume_tf_jour( 10 ), 0, 7 );
		$prec   = ( new DateTimeImmutable( $mois . '-01' ) )->modify( '-1 month' )->format( 'Y-m' );
		$suiv   = ( new DateTimeImmutable( $mois . '-01' ) )->modify( '+1 month' )->format( 'Y-m' );
		$jour15 = strtotime( $mois . '-15 10:00:00 UTC' );
		$filtre = static function () use ( $jour15 ): int {
			return $jour15;
		};
		add_filter( 'yume_planning_maintenant', $filtre );
		try {
			$_GET['mois'] = $mois;
			$html         = yume_render_block( 'yume/calendrier' );
		} finally {
			remove_filter( 'yume_planning_maintenant', $filtre );
		}
		yume_assert_contains( 'class="yn-calendrier wp-block-yume-calendrier', $html );
		yume_assert_contains( '<caption class="yn-visually-hidden">Sorties de ', $html );
		yume_assert_same( 7, substr_count( $html, '<th scope="col">' ), 'en-têtes des jours' );
		yume_assert_contains( '<abbr title="lundi">lun.</abbr>', $html );
		yume_assert_contains( 'mois=' . $prec, $html );
		yume_assert_contains( 'mois=' . $suiv, $html );
		yume_assert_contains( 'rel="prev"', $html );
		yume_assert_same( 1, substr_count( $html, 'aria-current="date"' ), 'aujourd’hui (grille ; la liste ne montre que les jours de sortie)' );
		yume_assert_contains( 'aria-current="date"><span class="yn-calendrier__num"><time datetime="' . $mois . '-15">15</time>', $html );
		// Programmé : icône, texte masqué et classe distincte (pas la couleur seule).
		yume_assert_contains( 'yn-calendrier__evt--programme', $html );
		yume_assert_contains( '<span class="yn-visually-hidden">Programmé : </span><a href="', $html );
		yume_assert_contains( 'Brume; Haute, et Cie T.2', $html );
		yume_assert_contains( 'webcal://', $html );
		yume_assert_contains( 'yn-calendrier__liste', $html );
		// Mois invalide : mois courant.
		$_GET['mois'] = '2026-13';
		yume_assert_contains( 'datetime="' . substr( date_i18n( 'Y-m' ), 0, 7 ) . '-01"', yume_render_block( 'yume/calendrier' ) );
	}
);

yume_tf_test(
	'Planning : onglet « Calendrier » (?vue=calendrier) et lien d’abonnement ICS',
	static function () {
		yume_tf_donnees();
		$html = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'yn-planning__table', $html );
		yume_assert_contains( 'vue=calendrier', $html );
		yume_assert_contains( 'webcal://', $html );
		yume_assert_contains( 'S’abonner au calendrier (ICS)', $html );
		yume_assert_not_contains( 'yn-calendrier__grille', $html );
		$_GET['vue'] = 'calendrier';
		$html        = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'yn-calendrier__grille', $html );
		yume_assert_not_contains( 'yn-planning__table', $html );
		yume_assert_same( 1, preg_match( '/rel="next" href="[^"]*vue=calendrier[^"]*mois=\d{4}-\d{2}/', $html ), 'la navigation garde l’onglet' );
	}
);
