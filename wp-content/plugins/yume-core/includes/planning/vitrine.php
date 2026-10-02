<?php
/**
 * Vitrine du planning, partagée par l'accueil (bloc yume/planning-accueil) et la page Planning
 * (bandeau « À la une », vue « Chapitres » : ?vue=chapitres) :
 *
 * - prochain_tome() : le prochain tome à sortir (bandeau « À la une ») ;
 * - file_chapitres() : chapitres programmés, chapitres publiés récemment, tomes en cours de
 *   publication chapitre par chapitre ;
 * - tomes_en_preparation() : tomes à paraître (hors tome à la une et tomes en cours) ;
 * - rendus HTML échappés à la construction : rendu_a_la_une(), rendu_file_chapitres(),
 *   rendu_tomes_preparation(), rendu_planning_accueil().
 *
 * Mêmes règles de visibilité que le planning public : œuvres publiées seulement, jamais un
 * chapitre retiré (Publication\Service::META_RETIRE) ni une version en attente de remplacement.
 * Couleurs : uniquement les variables et classes du thème (§15) ; feuille assets/vitrine.css et
 * script assets/vitrine.js (poignée yume-vitrine : onglets Chapitres / Tomes sous 900 px, en
 * amélioration progressive, les deux listes restent visibles sans JavaScript).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Poignée de la feuille de style et du script de la vitrine. */
const POIGNEE_VITRINE = 'yume-vitrine';

/** Durée (secondes) pendant laquelle un chapitre publié porte le badge « Nouveau ». */
const DUREE_NOUVEAU = 2 * DAY_IN_SECONDS;

/**
 * Un chapitre publié moins d'une heure après son tome est sorti avec lui (tome publié d'un
 * bloc) : il ne figure pas dans « Publiés récemment », sauf si le tome est en cours de
 * publication chapitre par chapitre.
 */
const ECART_SORTIE_GROUPEE = HOUR_IN_SECONDS;

/**
 * Enregistre la feuille de style et le script de la vitrine (avant les blocs qui les citent).
 */
function enregistrer_assets_vitrine(): void {
	wp_register_style( POIGNEE_VITRINE, YUME_CORE_URL . 'includes/planning/assets/vitrine.css', array(), YUME_CORE_VERSION );
	wp_register_script(
		POIGNEE_VITRINE,
		YUME_CORE_URL . 'includes/planning/assets/vitrine.js',
		array(),
		YUME_CORE_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_assets_vitrine', 5 );

/**
 * Charge la feuille (et au besoin le script) de la vitrine sur la page en cours de rendu.
 *
 * @param bool $script Charger aussi le script des onglets.
 */
function charger_assets_vitrine( bool $script = false ): void {
	if ( ! wp_style_is( POIGNEE_VITRINE, 'registered' ) ) {
		enregistrer_assets_vitrine();
	}
	wp_enqueue_style( POIGNEE_VITRINE );
	if ( $script ) {
		wp_enqueue_script( POIGNEE_VITRINE );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Données
 * -----------------------------------------------------------------------------
 */

/**
 * Prochain tome à sortir, côté public : parmi les lignes publiques du planning pas encore
 * parues ni bloquées, celle dont la date de sortie (programmée ou cible) est la plus proche à
 * partir d'aujourd'hui. Un tome sans date n'est jamais retenu ; un tome déjà en ligne (publié
 * chapitre par chapitre, dans la file des chapitres) non plus.
 *
 * La ligne de planning (yume_get_planning()) est complétée de 'jours' (jours jusqu'à la
 * sortie, 0 = aujourd'hui), 'couverture' (URL de la couverture du tome, sinon de l'œuvre, sinon
 * ''), 'couverture_id', 'url_tome' (tome en ligne, sinon '') et 'url_oeuvre'.
 *
 * @return array<string,mixed>|null
 */
function prochain_tome(): ?array {
	limiter_cache_page();
	$aujourdhui = date_locale();
	foreach ( lignes_planning( array( 'a_venir' => true ) ) as $ligne ) {
		if ( in_array( $ligne['etat'], array( 'publie', 'bloque' ), true ) || 'publish' === $ligne['statut'] ) {
			continue;
		}
		$date = (string) $ligne['date_cible'];
		if ( ! valider_date( $date ) || $date < $aujourdhui ) {
			continue;
		}
		$couverture             = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( (int) $ligne['tome_id'] ) : 0;
		$ligne['jours']         = max( 0, ecart_jours( $aujourdhui, $date ) );
		$ligne['couverture_id'] = $couverture;
		$ligne['couverture']    = $couverture ? (string) wp_get_attachment_image_url( $couverture, 'yume-couverture' ) : '';
		$ligne['url_tome']      = (string) $ligne['url'];
		$ligne['url_oeuvre']    = (string) $ligne['url_oeuvre'];
		return $ligne;
	}
	return null;
}

/**
 * File des chapitres des œuvres publiques.
 *
 * - 'prochains' : chapitres programmés (statut future) des tomes en ligne, ordre chronologique ;
 * - 'publies' : chapitres publiés depuis 'jours_publies' jours, du plus récent au plus ancien
 *   (un tome publié d'un bloc n'y figure pas : il est dans « Dernières sorties ») ;
 * - 'tomes_en_cours' : tomes en cours de publication chapitre par chapitre
 *   (yume_parution_tome() === 'en_cours').
 *
 * Chaque chapitre : 'id', 'oeuvre', 'oeuvre_id', 'url_oeuvre', 'tome_id', 'tome' (« Tome 2 »),
 * 'libelle' (« Chapitre 3 », « Prologue »), 'ts' (horodatage), 'url' (chapitre publié, sinon
 * ''), 'nouveau' (publié depuis moins de 48 h). Chaque tome en cours : 'tome_id', 'oeuvre_id',
 * 'oeuvre', 'url_oeuvre', 'tome', 'en_ligne' (chapitres publiés), 'prevus'
 * (yume_chapitres_prevus, 0 : inconnu), 'rythme' (« chaque samedi à 18 h » ou ''), 'url',
 * 'prochain_ts' (prochain chapitre programmé, 0 : aucun).
 *
 * @param array $args 'prochains' (int, défaut 3), 'publies' (int, défaut 4), 'jours_publies'
 *                    (int, défaut 14), 'oeuvre_id' (int, 0 : toutes).
 * @return array{prochains:array,publies:array,tomes_en_cours:array}
 */
function file_chapitres( array $args = array() ): array {
	limiter_cache_page();
	$args       = wp_parse_args(
		$args,
		array(
			'prochains'     => 3,
			'publies'       => 4,
			'jours_publies' => 14,
			'oeuvre_id'     => 0,
		)
	);
	$nb_futurs  = max( 0, (int) $args['prochains'] );
	$nb_publies = max( 0, (int) $args['publies'] );
	$jours      = max( 1, (int) $args['jours_publies'] );
	$oeuvre_id  = max( 0, (int) $args['oeuvre_id'] );
	$maintenant = maintenant();

	$futurs  = lignes_chapitres( 'future', $oeuvre_id, 0 );
	$recents = $nb_publies ? lignes_chapitres( 'publish', $oeuvre_id, $maintenant - $jours * DAY_IN_SECONDS ) : array();

	$ids = array();
	foreach ( array_merge( $futurs, $recents ) as $r ) {
		$ids[] = (int) $r->id;
		$ids[] = (int) $r->tome;
		$ids[] = (int) $r->oeuvre;
	}
	if ( $ids ) {
		_prime_post_caches( array_values( array_unique( $ids ) ), false, true );
	}

	// Prochains : jamais ceux d'une œuvre arrêtée (comme le planning public).
	$prochains = array();
	$arretees  = array();
	foreach ( $futurs as $r ) {
		$o = (int) $r->oeuvre;
		if ( ! isset( $arretees[ $o ] ) ) {
			$arretees[ $o ] = oeuvre_arretee( $o );
		}
		if ( ! $arretees[ $o ] ) {
			$prochains[] = $r;
		}
	}

	// Publiés : chapitres sortis un par un (tome en cours, ou chapitre ajouté après le tome).
	$publies  = array();
	$parution = array();
	foreach ( $recents as $r ) {
		$tome = (int) $r->tome;
		if ( ! isset( $parution[ $tome ] ) ) {
			$parution[ $tome ] = yume_parution_tome( $tome );
		}
		$ts = ts_gmt( (string) $r->date_gmt );
		if ( 'en_cours' === $parution[ $tome ] || $ts - ts_gmt( (string) $r->tome_gmt ) > ECART_SORTIE_GROUPEE ) {
			$publies[] = $r;
		}
		if ( count( $publies ) >= $nb_publies ) {
			break;
		}
	}

	return array(
		'prochains'      => array_map( __NAMESPACE__ . '\\element_chapitre', array_slice( $prochains, 0, $nb_futurs ) ),
		'publies'        => array_map( __NAMESPACE__ . '\\element_chapitre', $publies ),
		'tomes_en_cours' => tomes_en_cours_publication( $oeuvre_id, $prochains ),
	);
}

/**
 * Chapitres d'un statut des tomes en ligne d'œuvres publiées (requête unique), hors chapitres
 * retirés et versions en attente.
 *
 * @param string $statut    'future' (ordre chronologique) ou 'publish' (plus récents d'abord).
 * @param int    $oeuvre_id Œuvre (0 : toutes).
 * @param int    $depuis    Horodatage : publiés depuis (0 : sans limite).
 * @return object[] Lignes {id, date_gmt, tome, tome_gmt, oeuvre}.
 */
function lignes_chapitres( string $statut, int $oeuvre_id, int $depuis ): array {
	global $wpdb;
	$retire  = class_exists( '\Yume\Core\Publication\Service' ) ? \Yume\Core\Publication\Service::META_RETIRE : '_yume_retire';
	$attente = class_exists( '\Yume\Core\Publication\Remplacement' ) ? \Yume\Core\Publication\Remplacement::META_DE : '_yume_remplacement_de';
	$sql     = 'SELECT c.ID AS id, c.post_date_gmt AS date_gmt, t.ID AS tome, t.post_date_gmt AS tome_gmt, o.ID AS oeuvre'
		. " FROM {$wpdb->posts} c"
		. " INNER JOIN {$wpdb->postmeta} ct ON ( ct.post_id = c.ID AND ct.meta_key = 'yume_tome_id' )"
		. " INNER JOIN {$wpdb->posts} t ON ( t.ID = CAST( ct.meta_value AS UNSIGNED ) AND t.post_type = 'yume_tome' AND t.post_status = 'publish' )"
		. " INNER JOIN {$wpdb->postmeta} tor ON ( tor.post_id = t.ID AND tor.meta_key = 'yume_oeuvre_id' )"
		. " INNER JOIN {$wpdb->posts} o ON ( o.ID = CAST( tor.meta_value AS UNSIGNED ) AND o.post_type = 'yume_oeuvre' AND o.post_status = 'publish' )"
		. " LEFT JOIN {$wpdb->postmeta} r ON ( r.post_id = c.ID AND r.meta_key = %s )"
		. " LEFT JOIN {$wpdb->postmeta} a ON ( a.post_id = c.ID AND a.meta_key = %s )"
		. " WHERE c.post_type = 'yume_chapitre' AND c.post_status = %s"
		. " AND ( r.meta_value IS NULL OR r.meta_value = '' OR r.meta_value = '0' ) AND a.post_id IS NULL";
	$valeurs = array( $retire, $attente, $statut );
	if ( $depuis > 0 ) {
		$sql      .= ' AND c.post_date_gmt >= %s';
		$valeurs[] = gmt( $depuis );
	}
	if ( $oeuvre_id > 0 ) {
		$sql      .= ' AND o.ID = %d';
		$valeurs[] = $oeuvre_id;
	}
	$sql .= 'future' === $statut ? ' ORDER BY c.post_date_gmt ASC, c.ID ASC' : ' ORDER BY c.post_date_gmt DESC, c.ID DESC';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results( $wpdb->prepare( $sql, $valeurs ) );
	return is_array( $lignes ) ? $lignes : array();
}

/**
 * Élément de la file d'un chapitre (voir file_chapitres()).
 *
 * @param object $r Ligne de lignes_chapitres().
 * @return array<string,mixed>
 */
function element_chapitre( object $r ): array {
	$id     = (int) $r->id;
	$tome   = (int) $r->tome;
	$oeuvre = (int) $r->oeuvre;
	$ts     = ts_gmt( (string) $r->date_gmt );
	$post   = get_post( $id );
	if ( ! $ts && $post ) {
		$ts = ts_contenu( $post, 'post_date' );
	}
	$publie = $post && 'publish' === $post->post_status;
	return array(
		'id'         => $id,
		'oeuvre'     => titre_brut( $oeuvre ),
		'oeuvre_id'  => $oeuvre,
		'url_oeuvre' => (string) get_permalink( $oeuvre ),
		'tome_id'    => $tome,
		'tome'       => yume_libelle_tome( $tome ),
		'libelle'    => yume_libelle_chapitre( $id ),
		'ts'         => $ts,
		'url'        => $publie ? (string) get_permalink( $id ) : '',
		'nouveau'    => $publie && $ts <= maintenant() && maintenant() - $ts < DUREE_NOUVEAU,
	);
}

/**
 * Tomes en ligne en cours de publication chapitre par chapitre, d'œuvres publiées non arrêtées.
 * Candidats en une requête (méta yume_parution « en_cours », tomes sans cette méta dont l'étape
 * n'est pas « publié », tomes ayant un chapitre programmé), confirmés par yume_parution_tome().
 *
 * @param int   $oeuvre_id Œuvre (0 : toutes).
 * @param array $futurs    Chapitres programmés (lignes_chapitres()) : prochain chapitre de chaque tome.
 * @return array<int,array<string,mixed>> Triés : prochain chapitre programmé d'abord, puis par œuvre.
 */
function tomes_en_cours_publication( int $oeuvre_id, array $futurs ): array {
	global $wpdb;
	$sql = "SELECT t.ID FROM {$wpdb->posts} t"
		. " LEFT JOIN {$wpdb->postmeta} pm ON ( pm.post_id = t.ID AND pm.meta_key = 'yume_parution' )"
		. " LEFT JOIN {$wpdb->postmeta} em ON ( em.post_id = t.ID AND em.meta_key = 'yume_etape' )"
		. " WHERE t.post_type = 'yume_tome' AND t.post_status = 'publish'"
		. " AND ( pm.meta_value = 'en_cours' OR ( ( pm.meta_value IS NULL OR pm.meta_value = '' ) AND ( em.meta_value IS NULL OR em.meta_value <> 'publie' ) ) )";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids       = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	$prochains = array();
	foreach ( $futurs as $r ) {
		$tome  = (int) $r->tome;
		$ids[] = $tome;
		if ( ! isset( $prochains[ $tome ] ) ) {
			$prochains[ $tome ] = ts_gmt( (string) $r->date_gmt );
		}
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	_prime_post_caches( $ids, false, true );
	$tomes = array();
	amorcer_comptes_chapitres( $ids );
	try {
		foreach ( $ids as $id ) {
			$o = yume_get_oeuvre_id( $id );
			if ( ! $o || ( $oeuvre_id && $o !== $oeuvre_id ) || 'publish' !== get_post_status( $o ) || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			if ( oeuvre_arretee( $o ) || 'en_cours' !== yume_parution_tome( $id ) ) {
				continue;
			}
			$tomes[] = array(
				'tome_id'     => $id,
				'oeuvre_id'   => $o,
				'oeuvre'      => titre_brut( $o ),
				'url_oeuvre'  => (string) get_permalink( $o ),
				'tome'        => yume_libelle_tome( $id ),
				'en_ligne'    => (int) compte_chapitres( $id )['publies'],
				'prevus'      => max( 0, (int) get_post_meta( $id, 'yume_chapitres_prevus', true ) ),
				'rythme'      => class_exists( '\Yume\Core\Publication\Service' ) ? \Yume\Core\Publication\Service::rythme_texte( $id ) : '',
				'url'         => (string) get_permalink( $id ),
				'prochain_ts' => $prochains[ $id ] ?? 0,
			);
		}
	} finally {
		oublier_comptes_chapitres();
	}
	usort(
		$tomes,
		static function ( array $a, array $b ): int {
			if ( ( $a['prochain_ts'] > 0 ) !== ( $b['prochain_ts'] > 0 ) ) {
				return $a['prochain_ts'] > 0 ? -1 : 1;
			}
			$cmp = $a['prochain_ts'] <=> $b['prochain_ts'];
			if ( 0 === $cmp ) {
				$cmp = strcasecmp( $a['oeuvre'], $b['oeuvre'] );
			}
			return 0 !== $cmp ? $cmp : ( $a['tome_id'] <=> $b['tome_id'] );
		}
	);
	return $tomes;
}

/**
 * Tomes en préparation : lignes publiques à paraître (yume_get_planning( a_venir )), hors tome
 * exclu (celui à la une) et hors tomes en cours de publication chapitre par chapitre, triées
 * comme les prochaines sorties (datés d'abord par date, puis les plus avancés).
 *
 * @param int $exclure_tome Tome à exclure (0 : aucun).
 * @param int $limite       Nombre maximal (0 : tous).
 * @return array<int,array<string,mixed>>
 */
function tomes_en_preparation( int $exclure_tome = 0, int $limite = 4 ): array {
	limiter_cache_page();
	$lignes = array();
	foreach ( lignes_planning( array( 'a_venir' => true ) ) as $l ) {
		if ( (int) $l['tome_id'] === $exclure_tome && $exclure_tome > 0 ) {
			continue;
		}
		if ( 'publish' === $l['statut'] && 'en_cours' === yume_parution_tome( (int) $l['tome_id'] ) ) {
			continue;
		}
		$lignes[] = $l;
	}
	usort( $lignes, __NAMESPACE__ . '\\comparer_a_venir' );
	return $limite > 0 ? array_slice( $lignes, 0, $limite ) : $lignes;
}

/**
 * Ordre des prochaines sorties (comme yume/upcoming) : tomes datés d'abord, par date, puis les
 * plus avancés.
 *
 * @param array $a Ligne.
 * @param array $b Ligne.
 */
function comparer_a_venir( array $a, array $b ): int {
	if ( ( '' === $a['date_cible'] ) !== ( '' === $b['date_cible'] ) ) {
		return '' === $a['date_cible'] ? 1 : -1;
	}
	if ( '' !== $a['date_cible'] && $a['date_cible'] !== $b['date_cible'] ) {
		return strcmp( $a['date_cible'], $b['date_cible'] );
	}
	$cmp = array_sum( $b['avancement'] ) <=> array_sum( $a['avancement'] );
	return 0 !== $cmp ? $cmp : ( $a['tome_id'] <=> $b['tome_id'] );
}

/*
 * -----------------------------------------------------------------------------
 * Petits composants
 * -----------------------------------------------------------------------------
 */

/**
 * Icône SVG décorative (trait, couleur du texte).
 *
 * @param string $nom cloche | livre | calendrier | coche.
 */
function icone_vitrine( string $nom ): string {
	$traces = array(
		'cloche'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"></path>',
		'livre'      => '<path d="M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2z"></path><path d="M22 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z"></path>',
		'calendrier' => '<rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path>',
		'coche'      => '<path d="M20 6 9 17l-5-5"></path>',
	);
	return '<svg class="yn-vitrine__icone" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $traces[ $nom ] ?? '' ) . '</svg>';
}

/**
 * Jour court en français (« jeu. 1er oct. », année ajoutée hors de l'année courante).
 *
 * @param int $ts Horodatage.
 */
function vitrine_jour( int $ts ): string {
	$jour = (int) format_fr( $ts, 'j' );
	/* translators: premier jour du mois (« 1er ») */
	$num   = 1 === $jour ? __( '1er', 'yume-core' ) : (string) $jour;
	$texte = format_fr( $ts, 'D' ) . ' ' . $num . ' ' . format_fr( $ts, 'M' );
	return format_fr( $ts, 'Y' ) !== substr( date_locale(), 0, 4 ) ? $texte . ' ' . format_fr( $ts, 'Y' ) : $texte;
}

/**
 * Date longue en français (« samedi 3 octobre 2026 », « jeudi 1er octobre 2026 »).
 *
 * @param int $ts Horodatage.
 */
function vitrine_date_longue( int $ts ): string {
	$jour = (int) format_fr( $ts, 'j' );
	$num  = 1 === $jour ? __( '1er', 'yume-core' ) : (string) $jour;
	return format_fr( $ts, 'l' ) . ' ' . $num . ' ' . format_fr( $ts, 'F Y' );
}

/**
 * Heure en français (« 18 h », « 18 h 30 »).
 *
 * @param int $ts Horodatage.
 */
function vitrine_heure( int $ts ): string {
	$minutes = (int) format_fr( $ts, 'i' );
	$heure   = format_fr( $ts, 'G' ) . "\u{00A0}h";
	return $minutes ? $heure . "\u{00A0}" . format_fr( $ts, 'i' ) : $heure;
}

/**
 * Échéance relative d'un jour proche (« aujourd’hui », « demain », « dans 3 jours »), vide
 * au-delà d'une semaine.
 *
 * @param int $ts Horodatage.
 */
function vitrine_echeance( int $ts ): string {
	$jours = ecart_jours( date_locale(), date_locale( $ts ) );
	if ( 0 === $jours ) {
		return __( 'aujourd’hui', 'yume-core' );
	}
	if ( 1 === $jours ) {
		return __( 'demain', 'yume-core' );
	}
	if ( $jours > 1 && $jours < 7 ) {
		/* translators: %d : nombre de jours */
		return sprintf( __( 'dans %d jours', 'yume-core' ), $jours );
	}
	return '';
}

/**
 * Bouton « Suivre l'œuvre » (ou « Me prévenir ») : membre connecté, formulaire du favori du
 * module lecteurs (admin-post yume_social_favori, sans JavaScript ; une œuvre suivie s'affiche
 * « Œuvre suivie » et le bouton la retire) ; visiteur, lien vers les actions de la fiche de
 * l'œuvre (favori, alerte de sortie).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $texte     Texte du bouton.
 * @param string $precision Complément lu par les lecteurs d'écran (« SukaMoka, Tome 2 · Chapitre 3 »).
 * @param string $classe    Classes du bouton.
 * @param string $ancre     Ancre de retour après l'envoi.
 */
function bouton_suivre( int $oeuvre_id, string $texte, string $precision, string $classe, string $ancre ): string {
	if ( ! $oeuvre_id || 'publish' !== get_post_status( $oeuvre_id ) ) {
		return '';
	}
	$cache   = '<span class="yn-visually-hidden"> : ' . esc_html( $precision ) . '</span>';
	$libelle = '<span class="yn-vitrine__btn-texte">' . esc_html( $texte ) . '</span>';
	$social  = is_user_logged_in() && function_exists( '\Yume\Core\Social\champs_action' ) && function_exists( '\Yume\Core\Social\est_favori' );
	if ( ! $social ) {
		$url = (string) get_permalink( $oeuvre_id ) . '#yn-oeuvre-actions';
		return '<a class="' . esc_attr( $classe ) . '" href="' . esc_url( $url ) . '">' . icone_vitrine( 'cloche' ) . $libelle . $cache . '</a>';
	}
	$suivie = \Yume\Core\Social\est_favori( get_current_user_id(), $oeuvre_id );
	$html   = '<form class="yn-vitrine__suivre" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. \Yume\Core\Social\champs_action( 'yume_social_favori', $oeuvre_id, $ancre )
		. '<input type="hidden" name="yn_faire" value="' . ( $suivie ? 'retirer' : 'ajouter' ) . '">';
	if ( $suivie ) {
		$libelle = '<span class="yn-vitrine__btn-texte">' . esc_html__( 'Œuvre suivie', 'yume-core' ) . '</span>';
	}
	return $html . '<button type="submit" class="' . esc_attr( $classe ) . '" aria-pressed="' . ( $suivie ? 'true' : 'false' ) . '">'
		. icone_vitrine( $suivie ? 'coche' : 'cloche' ) . $libelle . $cache . '</button></form>';
}

/**
 * Lien « Tous les chapitres publiés → » (bibliothèque triée par date de sortie).
 */
function lien_tous_chapitres(): string {
	$url = add_query_arg( 'tri', 'recent', yume_url_page( 'bibliotheque' ) );
	return '<p class="yn-vitrine__lien"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Tous les chapitres publiés', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>';
}

/*
 * -----------------------------------------------------------------------------
 * Rendus
 * -----------------------------------------------------------------------------
 */

/**
 * Bandeau « À la une » du prochain tome (voir prochain_tome()) : couverture, œuvre, tome, date
 * longue, compte à rebours, avancement des trois étapes, « Suivre l'œuvre » et « Voir la
 * fiche ». Rien pour un tableau vide.
 *
 * @param array  $tome     Ligne de prochain_tome().
 * @param string $contexte 'accueil' (titre h3) ou 'planning' (titre h2).
 */
function rendu_a_la_une( array $tome, string $contexte = 'accueil' ): string {
	if ( empty( $tome['tome_id'] ) || ! valider_date( (string) ( $tome['date_cible'] ?? '' ) ) ) {
		return '';
	}
	charger_assets_vitrine();
	$planning = 'planning' === $contexte;
	$niveau   = $planning ? 'h2' : 'h3';
	$ts       = ts_date( (string) $tome['date_cible'] );
	$jours    = (int) ( $tome['jours'] ?? max( 0, ecart_jours( date_locale(), (string) $tome['date_cible'] ) ) );
	$oeuvre   = (string) $tome['oeuvre'];
	$fiche    = '' !== (string) ( $tome['url_tome'] ?? '' ) ? (string) $tome['url_tome'] : (string) $tome['url_oeuvre'];
	$titre_id = 'yn-une-' . ( $planning ? 'planning' : 'accueil' );

	$html = '<section class="yn-card yn-vitrine-une yn-vitrine-une--' . esc_attr( $planning ? 'planning' : 'accueil' ) . '" aria-labelledby="' . esc_attr( $titre_id ) . '">';

	// Couverture (décorative : le titre et « Voir la fiche » la nomment déjà).
	$html .= '<div class="yn-cover yn-vitrine-une__couverture" aria-hidden="true">';
	if ( ! empty( $tome['couverture_id'] ) && function_exists( 'yume_image_couverture' ) ) {
		$html .= yume_image_couverture(
			(int) $tome['couverture_id'],
			'yume-couverture',
			array(
				'alt'     => '',
				'loading' => 'lazy',
			)
		);
	} else {
		$html .= '<span>' . esc_html( $oeuvre . ' · ' . yume_libelle_tome( (int) $tome['tome_id'], true ) ) . '</span>';
	}
	$html .= '</div>';

	// Titre et date, puis actions (placées sous l'avancement sur mobile).
	$html .= '<div class="yn-vitrine-une__corps">';
	$html .= '<p class="yn-label yn-vitrine-une__surtitre">' . esc_html( $planning ? __( 'À la une · prochain tome', 'yume-core' ) : __( 'Prochain tome', 'yume-core' ) ) . '</p>';
	$html .= '<' . $niveau . ' class="yn-vitrine-une__oeuvre" id="' . esc_attr( $titre_id ) . '">' . esc_html( $oeuvre ) . '<span class="yn-visually-hidden"> · ' . esc_html( (string) $tome['tome'] ) . '</span></' . $niveau . '>';
	$html .= '<p class="yn-vitrine-une__tome"><span class="yn-vitrine-une__numero" aria-hidden="true">' . esc_html( (string) $tome['tome'] ) . '</span>';
	$html .= '<span class="yn-label yn-vitrine-une__date">' . icone_vitrine( 'calendrier' ) . '<time datetime="' . esc_attr( (string) $tome['date_cible'] ) . '">' . esc_html( vitrine_date_longue( $ts ) ) . '</time></span></p>';
	if ( '' !== (string) ( $tome['titre'] ?? '' ) ) {
		$html .= '<p class="yn-muted yn-vitrine-une__sous-titre">' . esc_html( (string) $tome['titre'] ) . '</p>';
	}
	$html .= '</div><div class="yn-vitrine-une__actions">';
	$html .= bouton_suivre( (int) $tome['oeuvre_id'], __( 'Suivre l’œuvre', 'yume-core' ), $oeuvre, 'yn-btn yn-btn--primary', 'yn-vitrine-une' );
	if ( '' !== $fiche ) {
		$html .= '<a class="yn-btn" href="' . esc_url( $fiche ) . '">' . esc_html__( 'Voir la fiche', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $oeuvre ) . '</span></a>';
	}
	$html .= '</div>';

	// Compte à rebours et avancement.
	$html .= '<div class="yn-vitrine-une__suivi">';
	if ( 0 === $jours ) {
		$rebours = __( 'Aujourd’hui', 'yume-core' );
		$detail  = __( 'Sortie aujourd’hui', 'yume-core' );
	} else {
		/* translators: %d : jours avant la sortie (« J-2 ») */
		$rebours = sprintf( __( 'J-%d', 'yume-core' ), $jours );
		/* translators: %d : jours avant la sortie */
		$detail = 1 === $jours ? __( 'Sortie demain', 'yume-core' ) : sprintf( _n( 'Sortie dans %d jour', 'Sortie dans %d jours', $jours, 'yume-core' ), $jours );
	}
	$html  .= '<p class="yn-vitrine-une__rebours"><span class="yn-vitrine-une__j' . ( 0 === $jours ? ' yn-vitrine-une__j--jour' : '' ) . '" aria-hidden="true">' . esc_html( $rebours ) . '</span>';
	$html  .= '<span class="yn-label yn-vitrine-une__dans">' . esc_html( $detail ) . '</span></p>';
	$html  .= '<ul class="yn-vitrine-une__etapes">';
	$restes = array();
	foreach ( ETAPES_TRAVAIL as $etape ) {
		$pct = (int) ( $tome['avancement'][ $etape ] ?? 0 );
		if ( $pct < 100 ) {
			$restes[] = libelle_etape_min( $etape );
		}
		$html .= '<li><span class="yn-label yn-vitrine-une__etape"><span>' . esc_html( yume_etapes()[ $etape ] ) . '</span><span>' . esc_html( pct( $pct ) ) . '</span></span>';
		$html .= barre( $pct, variante_barre( $tome, $etape ) ) . '</li>';
	}
	$html .= '</ul>';
	if ( est_programme( $tome ) ) {
		$reste = __( 'Sortie programmée : le tome paraîtra tout seul à cette date.', 'yume-core' );
	} elseif ( ! $restes ) {
		$reste = __( 'Traduction, relecture et édition terminées : place à la sortie.', 'yume-core' );
	} else {
		$derniere = article_etape( (string) array_pop( $restes ) );
		$reste    = ! $restes
			/* translators: %s : étape (« l’édition », « la relecture ») */
			? sprintf( __( 'Encore %s à finir.', 'yume-core' ), $derniere )
			/* translators: 1: étapes, 2: dernière étape */
			: sprintf( __( 'Encore %1$s et %2$s à finir.', 'yume-core' ), implode( ', ', array_map( __NAMESPACE__ . '\\article_etape', $restes ) ), $derniere );
	}
	$html .= '<p class="yn-vitrine-une__reste">' . esc_html( $reste ) . '</p>';
	if ( 'en_retard' === $tome['etat'] ) {
		$html .= '<p class="yn-vitrine-une__etat">' . pastille_ligne( $tome ) . '</p>';
	}
	$html .= '</div>';

	return $html . '</section>';
}

/**
 * Étape avec son article (« la traduction », « la relecture », « l’édition »).
 *
 * @param string $etape Libellé d'étape en minuscules.
 */
function article_etape( string $etape ): string {
	return preg_match( '/^[aeiouyéèêàâîôûh]/u', $etape ) ? 'l’' . $etape : 'la ' . $etape;
}

/**
 * Encarts de progression des tomes en cours de publication (« 3 / 12 »).
 *
 * @param array $tomes Tomes de file_chapitres()['tomes_en_cours'].
 */
function encarts_tomes_en_cours( array $tomes ): string {
	if ( ! $tomes ) {
		return '';
	}
	$html = '<ul class="yn-vitrine-encours">';
	foreach ( $tomes as $t ) {
		$prevus = (int) $t['prevus'];
		$nom    = '<b>' . esc_html( (string) $t['oeuvre'] ) . '</b> <span class="yn-muted">· ' . esc_html( (string) $t['tome'] ) . '</span>';
		if ( '' !== (string) $t['url'] ) {
			$nom = '<a href="' . esc_url( (string) $t['url'] ) . '">' . $nom . '</a>';
		}
		$html .= '<li class="yn-vitrine-encours__item">';
		$html .= '<p class="yn-vitrine-encours__tete"><span class="yn-vitrine-encours__nom">' . $nom . '</span>';
		if ( $prevus > 0 ) {
			$html .= '<span class="yn-label yn-vitrine-encours__compte"><span aria-hidden="true">' . esc_html( min( (int) $t['en_ligne'], $prevus ) . ' / ' . $prevus ) . '</span><span class="yn-visually-hidden">'
				/* translators: 1: chapitres en ligne, 2: chapitres prévus */
				. esc_html( sprintf( __( '%1$d chapitres en ligne sur %2$d', 'yume-core' ), (int) $t['en_ligne'], $prevus ) ) . '</span></span></p>';
			$html .= barre( (int) round( min( 100, (int) $t['en_ligne'] * 100 / $prevus ) ) );
		} else {
			$html .= '<span class="yn-label yn-vitrine-encours__compte">'
				/* translators: %d : chapitres en ligne */
				. esc_html( sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', (int) $t['en_ligne'], 'yume-core' ), (int) $t['en_ligne'] ) ) . '</span></p>';
		}
		$rythme = '' !== (string) $t['rythme'] ? majuscule( (string) $t['rythme'] ) : __( 'Rythme libre', 'yume-core' );
		$html  .= '<p class="yn-muted yn-vitrine-encours__rythme">' . esc_html( $rythme ) . '</p></li>';
	}
	return $html . '</ul>';
}

/**
 * Élément de la frise d'un chapitre.
 *
 * @param array  $c        Chapitre (file_chapitres()).
 * @param bool   $futur    Chapitre programmé (sinon publié).
 * @param string $quand    Date affichée (« sam. 10 oct. · 18 h », « 18 h »).
 * @param string $ancre    Ancre de retour du bouton « Me prévenir ».
 */
function element_frise( array $c, bool $futur, string $quand, string $ancre ): string {
	$detail = trim( $c['tome'] . ' · ' . $c['libelle'], ' ·' );
	$titre  = $c['oeuvre'] . ', ' . $detail;
	$classe = 'yn-vitrine-frise__item yn-vitrine-frise__item--' . ( $futur ? 'prochain' : 'publie' ) . ( ! empty( $c['nouveau'] ) ? ' est-nouveau' : '' );
	$html   = '<li class="' . esc_attr( $classe ) . '"><span class="yn-vitrine-frise__point" aria-hidden="true"></span>';
	$html  .= '<time class="yn-label yn-vitrine-frise__date" datetime="' . esc_attr( gmdate( 'c', (int) $c['ts'] ) ) . '">' . esc_html( $quand ) . '</time>';
	$html  .= '<span class="yn-vitrine-frise__texte"><span class="yn-vitrine-frise__oeuvre"><b>' . esc_html( (string) $c['oeuvre'] ) . '</b>';
	if ( ! empty( $c['nouveau'] ) ) {
		$html .= ' <span class="yn-chip yn-chip--new">' . esc_html__( 'Nouveau', 'yume-core' ) . '</span>';
	}
	$echeance = $futur ? vitrine_echeance( (int) $c['ts'] ) : '';
	$html    .= '</span><span class="yn-muted yn-vitrine-frise__detail">' . esc_html( $detail . ( '' !== $echeance ? ' · ' . $echeance : '' ) ) . '</span></span>';
	if ( $futur ) {
		$html .= bouton_suivre( (int) $c['oeuvre_id'], __( 'Me prévenir', 'yume-core' ), $titre, 'yn-btn yn-vitrine-frise__action', $ancre );
	} elseif ( '' !== (string) $c['url'] ) {
		$html .= '<a class="yn-btn yn-vitrine-frise__action' . ( ! empty( $c['nouveau'] ) ? ' yn-btn--primary' : '' ) . '" href="' . esc_url( (string) $c['url'] ) . '">' . icone_vitrine( 'livre' )
			. '<span class="yn-vitrine__btn-texte">' . esc_html__( 'Lire', 'yume-core' ) . '</span><span class="yn-visually-hidden"> : ' . esc_html( $titre ) . '</span></a>';
	}
	return $html . '</li>';
}

/**
 * Liste de la frise : à plat (accueil, date et heure sur chaque ligne) ou groupée par jour
 * (page Planning, titre de jour puis heure).
 *
 * @param array  $chapitres Chapitres, dans l'ordre d'affichage.
 * @param bool   $futur     Chapitres programmés.
 * @param bool   $par_jour  Groupés par jour.
 * @param string $niveau    Balise des titres de jour (h4, h5).
 * @param string $ancre     Ancre de retour.
 */
function liste_frise( array $chapitres, bool $futur, bool $par_jour, string $niveau, string $ancre ): string {
	$aujourdhui = date_locale();
	if ( ! $par_jour ) {
		$html = '<ol class="yn-vitrine-frise__liste">';
		foreach ( $chapitres as $c ) {
			$ts = (int) $c['ts'];
			if ( $futur ) {
				$quand = vitrine_jour( $ts ) . ' · ' . vitrine_heure( $ts );
			} elseif ( date_locale( $ts ) === $aujourdhui ) {
				$quand = __( 'Auj.', 'yume-core' ) . ' · ' . vitrine_heure( $ts );
			} else {
				$quand = vitrine_jour( $ts );
			}
			$html .= element_frise( $c, $futur, $quand, $ancre );
		}
		return $html . '</ol>';
	}
	$jours = array();
	foreach ( $chapitres as $c ) {
		$jours[ date_locale( (int) $c['ts'] ) ][] = $c;
	}
	$html = '<ol class="yn-vitrine-frise__liste yn-vitrine-frise__liste--jours">';
	foreach ( $jours as $ymd => $du_jour ) {
		$titre = $ymd === $aujourdhui ? __( 'Aujourd’hui', 'yume-core' ) : vitrine_jour( (int) $du_jour[0]['ts'] );
		$html .= '<li class="yn-vitrine-frise__jour"><' . $niveau . ' class="yn-label yn-vitrine-frise__titre-jour"><time datetime="' . esc_attr( $ymd ) . '">' . esc_html( $titre ) . '</time></' . $niveau . '><ol class="yn-vitrine-frise__liste">';
		foreach ( $du_jour as $c ) {
			$html .= element_frise( $c, $futur, vitrine_heure( (int) $c['ts'] ), $ancre );
		}
		$html .= '</ol></li>';
	}
	return $html . '</ol>';
}

/**
 * File des chapitres (voir file_chapitres()) : encarts des tomes en cours (« 3 / 12 »), frise
 * « Prochains · programmés » (le plus proche juste au-dessus du repère « Aujourd’hui »), puis
 * « Publiés récemment » (badge « Nouveau ») ; « Lire », « Me prévenir » (suivre l'œuvre) et
 * « Tous les chapitres publiés → ». Page Planning : listes groupées par jour, encarts et
 * calendrier ICS en colonne.
 *
 * @param array  $file     Résultat de file_chapitres().
 * @param string $contexte 'accueil' (carte, titre h3) ou 'planning' (section, titre h2).
 */
function rendu_file_chapitres( array $file, string $contexte = 'accueil' ): string {
	charger_assets_vitrine();
	$planning  = 'planning' === $contexte;
	$prochains = (array) ( $file['prochains'] ?? array() );
	$publies   = (array) ( $file['publies'] ?? array() );
	$en_cours  = (array) ( $file['tomes_en_cours'] ?? array() );
	$id        = 'yn-vitrine-chapitres' . ( $planning ? '-planning' : '' );
	$h_titre   = $planning ? 'h2' : 'h3';
	$h_liste   = $planning ? 'h3' : 'h4';
	$ancre     = $id;

	$html  = '<section class="yn-card yn-vitrine-panneau yn-vitrine-chapitres yn-vitrine-chapitres--' . ( $planning ? 'planning' : 'accueil' ) . '" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titre' ) . '">';
	$html .= '<div class="yn-vitrine__tete"><' . $h_titre . ' class="yn-vitrine__titre" id="' . esc_attr( $id . '-titre' ) . '">' . esc_html__( 'Chapitres en lecture', 'yume-core' ) . '</' . $h_titre . '>';
	$html .= '<p class="yn-label yn-muted yn-vitrine__soustitre">' . esc_html__( 'Publication chapitre par chapitre', 'yume-core' ) . '</p></div>';

	$encarts = encarts_tomes_en_cours( $en_cours );
	if ( $planning ) {
		$html .= '<div class="yn-vitrine-chapitres__grille"><div class="yn-vitrine-chapitres__principal">';
	} else {
		$html .= $encarts;
	}

	$html .= '<div class="yn-vitrine-frise">';
	$html .= '<' . $h_liste . ' class="yn-label yn-vitrine-frise__titre yn-vitrine-frise__titre--prochains">' . esc_html__( 'Prochains · programmés', 'yume-core' ) . '</' . $h_liste . '>';
	$html .= $prochains
		? liste_frise( array_reverse( $prochains ), true, $planning, 'h4', $ancre )
		: '<p class="yn-muted yn-vitrine-frise__vide">' . esc_html__( 'Aucun chapitre programmé pour le moment.', 'yume-core' ) . '</p>';
	$html .= '<p class="yn-vitrine-frise__aujourdhui"><span class="yn-vitrine-frise__repere" aria-hidden="true"></span><span class="yn-chip yn-chip--new"><time datetime="' . esc_attr( date_locale() ) . '">'
		. esc_html( __( 'Aujourd’hui', 'yume-core' ) . ' · ' . vitrine_jour( maintenant() ) ) . '</time></span><span class="yn-vitrine-frise__filet" aria-hidden="true"></span></p>';
	$html .= '<' . $h_liste . ' class="yn-label yn-vitrine-frise__titre">' . esc_html__( 'Publiés récemment', 'yume-core' ) . '</' . $h_liste . '>';
	$html .= $publies
		? liste_frise( $publies, false, $planning, 'h4', $ancre )
		: '<p class="yn-muted yn-vitrine-frise__vide">' . esc_html__( 'Aucun chapitre publié récemment.', 'yume-core' ) . '</p>';
	$html .= '</div>';

	if ( $planning ) {
		$html .= '</div><aside class="yn-vitrine-chapitres__cote" aria-label="' . esc_attr__( 'Tomes en cours de publication', 'yume-core' ) . '">' . $encarts;
		$html .= '<div class="yn-vitrine-ics"><p class="yn-vitrine-ics__titre">' . icone_vitrine( 'calendrier' ) . esc_html__( 'Les sorties de tomes sont aussi dans le calendrier ICS', 'yume-core' ) . '</p>';
		$html .= '<p class="yn-muted yn-vitrine-ics__texte">' . esc_html__( 'Abonnez-vous pour les retrouver dans votre agenda.', 'yume-core' ) . '</p>';
		$html .= '<p class="yn-vitrine-ics__bouton"><a class="yn-btn" href="' . esc_url( url_ics( 0, true ) ) . '">' . esc_html__( 'S’abonner au calendrier (ICS)', 'yume-core' ) . '</a></p></div>';
		$html .= lien_tous_chapitres() . '</aside></div>';
	} else {
		$html .= lien_tous_chapitres();
	}
	return $html . '</section>';
}

/**
 * Tomes en préparation (voir tomes_en_preparation()) : œuvre · tome, étape et pourcentage,
 * barre, date cible (ou « à venir »), pastille d'état (le retard écrit en toutes lettres),
 * légende des états en bas de la carte.
 *
 * @param array $lignes Lignes.
 */
function rendu_tomes_preparation( array $lignes ): string {
	charger_assets_vitrine();
	$html  = '<section class="yn-card yn-vitrine-panneau yn-vitrine-tomes" id="yn-vitrine-tomes" aria-labelledby="yn-vitrine-tomes-titre">';
	$html .= '<div class="yn-vitrine__tete"><h3 class="yn-vitrine__titre" id="yn-vitrine-tomes-titre">' . esc_html__( 'Tomes en préparation', 'yume-core' ) . '</h3>';
	$html .= '<p class="yn-vitrine__lien-tete"><a href="' . esc_url( yume_url_page( 'planning' ) ) . '">' . esc_html__( 'Planning complet', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p></div>';
	if ( ! $lignes ) {
		$html .= '<p class="yn-muted yn-vitrine-tomes__vide">' . esc_html__( 'Aucun tome en préparation pour le moment.', 'yume-core' ) . '</p>';
	} else {
		$html .= '<ul class="yn-vitrine-tomes__liste">';
		foreach ( $lignes as $l ) {
			$etape = etape_de_travail( (string) $l['etape'] );
			$etape = '' !== $etape ? $etape : 'edition';
			$pct   = (int) ( $l['avancement'][ $etape ] ?? 0 );
			$nom   = '<b>' . esc_html( (string) $l['oeuvre'] ) . '</b>';
			if ( '' !== (string) $l['url_oeuvre'] ) {
				$nom = '<a href="' . esc_url( (string) $l['url_oeuvre'] ) . '">' . $nom . '</a>';
			}
			if ( est_programme( $l ) && 'bloque' !== $l['etat'] ) {
				/* translators: %s : date de sortie programmée */
				$date = sprintf( __( 'Sortie : %s', 'yume-core' ), date_cible_lisible( (string) $l['date_cible'], true ) );
			} elseif ( '' === (string) $l['date_cible'] ) {
				$date = __( 'Date : à venir', 'yume-core' );
			} elseif ( $l['date_cible'] < date_locale() ) {
				/* translators: %s : date cible dépassée */
				$date = sprintf( __( 'Prévu %s', 'yume-core' ), date_cible_lisible( (string) $l['date_cible'], true ) );
			} else {
				/* translators: %s : date cible */
				$date = sprintf( __( 'Cible : %s', 'yume-core' ), date_cible_lisible( (string) $l['date_cible'] ) );
			}
			if ( 'en_retard' === $l['etat'] ) {
				// Le retard se lit en toutes lettres, pas seulement à la couleur et à l'icône.
				$etat = pastille( 'en_retard', etats()['en_retard'] . ' · ' . libelle_etape_min( $etape ) );
			} elseif ( 'bloque' === $l['etat'] ) {
				$etat = pastille( 'bloque', texte_etat( $l ) );
			} else {
				$etat = pastille_ligne( $l, false );
			}
			$html .= '<li class="yn-vitrine-tomes__item yn-vitrine-tomes__item--' . esc_attr( (string) $l['etat'] ) . '">';
			$html .= '<p class="yn-vitrine-tomes__nom">' . $nom . ' <span class="yn-muted">· ' . esc_html( (string) $l['tome'] ) . '</span></p>';
			$html .= '<p class="yn-vitrine-tomes__etape"><span class="yn-label">' . esc_html( yume_etapes()[ $etape ] . ' ' . pct( $pct ) ) . '</span>' . barre( $pct, variante_barre( $l, $etape ) ) . '</p>';
			$html .= '<p class="yn-vitrine-tomes__pied"><span class="yn-label yn-muted">' . esc_html( $date ) . '</span>' . $etat . '</p>';
			$html .= '</li>';
		}
		$html .= '</ul>';
	}
	$html .= '<div class="yn-vitrine-legende"><p class="yn-label yn-muted" id="yn-vitrine-legende">' . esc_html__( 'Légende', 'yume-core' ) . '</p><ul aria-labelledby="yn-vitrine-legende">';
	foreach ( array( 'a_lheure', 'en_retard', 'bloque', 'publie' ) as $etat ) {
		$html .= '<li>' . pastille( $etat ) . '</li>';
	}
	return $html . '</ul></div></section>';
}

/**
 * Rendu du bloc yume/planning-accueil : titre « Planning » et lien « Planning complet → »,
 * prochain tome à la une, puis la grille « Chapitres en lecture » | « Tomes en préparation »
 * (onglets sous 900 px avec JavaScript). Rien (hors aperçu de l'éditeur) quand il n'y a ni
 * tome ni chapitre à montrer.
 *
 * @param array $attributs Attributs (aucun).
 */
function rendu_planning_accueil( array $attributs = array() ): string {
	unset( $attributs );
	limiter_cache_page();
	$une   = prochain_tome();
	$file  = file_chapitres();
	$tomes = tomes_en_preparation( $une ? (int) $une['tome_id'] : 0, 4 );
	if ( ! $une && ! $file['prochains'] && ! $file['publies'] && ! $file['tomes_en_cours'] && ! $tomes ) {
		return message_editeur( 'yn-planning-accueil', __( 'Planning : aucun tome ni chapitre à venir pour le moment (le bloc ne s’affiche pas).', 'yume-core' ) );
	}
	charger_assets_vitrine( true );
	$nb_chapitres = count( $file['prochains'] ) + count( $file['publies'] );

	$html  = '<section ' . attributs_racine(
		'yn-planning-accueil yn-vitrine',
		array(
			'id'              => 'yn-planning-accueil',
			'aria-labelledby' => 'yn-planning-accueil-titre',
		)
	) . '>';
	$html .= '<div class="yn-vitrine__tete yn-planning-accueil__tete"><h2 class="yn-section__titre" id="yn-planning-accueil-titre">' . esc_html__( 'Planning', 'yume-core' ) . '</h2>';
	$html .= '<p class="yn-section__lien"><a href="' . esc_url( yume_url_page( 'planning' ) ) . '">' . esc_html__( 'Planning complet', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p></div>';
	if ( $une ) {
		$html .= rendu_a_la_une( $une, 'accueil' );
	}
	$html .= '<div class="yn-vitrine-files" data-yn-onglets>';
	$html .= '<div class="yn-vitrine-files__onglets" role="tablist" aria-label="' . esc_attr__( 'File du planning', 'yume-core' ) . '" hidden>';
	$html .= '<button type="button" role="tab" id="yn-onglet-chapitres" aria-controls="yn-vitrine-chapitres" aria-selected="true">' . esc_html__( 'Chapitres', 'yume-core' ) . ' <span class="yn-muted">· ' . (int) $nb_chapitres . '</span></button>';
	$html .= '<button type="button" role="tab" id="yn-onglet-tomes" aria-controls="yn-vitrine-tomes" aria-selected="false" tabindex="-1">' . esc_html__( 'Tomes', 'yume-core' ) . ' <span class="yn-muted">· ' . count( $tomes ) . '</span></button>';
	$html .= '</div>';
	$html .= rendu_file_chapitres( $file, 'accueil' );
	$html .= rendu_tomes_preparation( $tomes );
	return $html . '</div></section>';
}
