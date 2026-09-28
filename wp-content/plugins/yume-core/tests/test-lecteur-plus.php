<?php
/**
 * Tests du lot « lecteur-plus » (phase 2) : lecture hors ligne (manifeste web, service worker,
 * page « Hors ligne », marqueur des pages mémorisables), options d'accessibilité du panneau
 * Paramètres (police OpenDyslexic, contraste renforcé, animations réduites, raccourcis) et
 * statistiques de lecture de la page compte.
 *
 * Lancement : tools/localenv/test.sh lecteur-plus
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Reader\configuration_sw;
use function Yume\Core\Reader\contenu_sw;
use function Yume\Core\Reader\duree_lisible;
use function Yume\Core\Reader\enregistrer_progression;
use function Yume\Core\Reader\entetes_sw;
use function Yume\Core\Reader\manifeste;
use function Yume\Core\Reader\page_hors_ligne;
use function Yume\Core\Reader\polices;
use function Yume\Core\Reader\pwa_actif;
use function Yume\Core\Reader\statistiques_lecture;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_lp_)
 * -----------------------------------------------------------------------------
 */

/**
 * Crée une œuvre publiée avec des tomes et des chapitres publiés.
 *
 * @param array<int,int> $tomes Numéro du tome => nombre de chapitres (ordre de création quelconque).
 * @param int            $mots  Mots par chapitre (yume_nb_mots) ; yume_temps_lecture = 10 min.
 * @return array{oeuvre:int,tomes:array<int,int>,chapitres:array<int,int[]>}
 */
function yume_lp_serie( array $tomes, int $mots = 2300 ): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	$oeuvre    = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Série ' . wp_rand( 1, 999999 ),
		)
	);
	$ids_tomes = array();
	$chapitres = array();
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
		// Chapitres créés dans le désordre : l'ordre de lecture vient de menu_order.
		for ( $i = $nb; $i >= 1; $i-- ) {
			$chapitres[ $numero ][ $i ] = yume_factory_post(
				array(
					'post_type'    => 'yume_chapitre',
					'post_title'   => 'Chapitre ' . $i,
					'post_name'    => 'chapitre-' . $i,
					'menu_order'   => $i,
					'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
					'meta_input'   => array(
						'yume_tome_id'       => $tome,
						'yume_oeuvre_id'     => $oeuvre,
						'yume_numero'        => $i,
						'yume_nature'        => 'chapitre',
						'yume_nb_mots'       => $mots,
						'yume_temps_lecture' => 10,
					),
				)
			);
		}
		ksort( $chapitres[ $numero ] );
		$chapitres[ $numero ] = array_values( $chapitres[ $numero ] );
	}
	remove_filter( 'yume_core_notifier', '__return_false' );
	return array(
		'oeuvre'    => $oeuvre,
		'tomes'     => $ids_tomes,
		'chapitres' => $chapitres,
	);
}

/**
 * Place la requête principale sur un contenu, exécute $rappel, puis restaure la requête.
 *
 * @param int      $post_id Contenu.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_lp_sur( int $post_id, callable $rappel ) {
	global $wp_query, $wp_the_query, $post;
	$avant_q    = $wp_query;
	$avant_the  = $wp_the_query;
	$avant_post = $post;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- contexte de rendu simulé puis restauré.
	$wp_query     = new WP_Query(
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => 'any',
		)
	);
	$wp_the_query = $wp_query;
	$post         = get_post( $post_id );
	try {
		return $rappel();
	} finally {
		$wp_query     = $avant_q;
		$wp_the_query = $avant_the;
		$post         = $avant_post;
	}
	// phpcs:enable
}

/**
 * Sortie d'une fonction d'affichage.
 *
 * @param callable $rappel Fonction.
 */
function yume_lp_sortie( callable $rappel ): string {
	ob_start();
	$rappel();
	return (string) ob_get_clean();
}

/*
 * -----------------------------------------------------------------------------
 * Manifeste et service worker (AMEL-07)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'manifeste : JSON valide, nom, affichage autonome, portée, couleurs du thème, icônes',
	function () {
		$json = wp_json_encode( manifeste() );
		$m    = json_decode( (string) $json, true );
		yume_assert_true( is_array( $m ), 'JSON valide' );
		yume_assert_same( 'Yume Novel', $m['name'] );
		yume_assert_same( 'standalone', $m['display'] );
		yume_assert_same( 'fr', $m['lang'] );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		yume_assert_same( trailingslashit( '' !== $base ? $base : '/' ), $m['start_url'] );
		yume_assert_same( $m['start_url'], $m['scope'] );
		yume_assert_true( (bool) preg_match( '/^#[0-9a-f]{6}$/i', $m['theme_color'] ), 'couleur du thème' );
		yume_assert_true( (bool) preg_match( '/^#[0-9a-f]{6}$/i', $m['background_color'] ), 'couleur de fond' );
		yume_assert_true( count( $m['icons'] ) >= 1 );
		foreach ( $m['icons'] as $icone ) {
			yume_assert_true( ! empty( $icone['src'] ) && ! empty( $icone['sizes'] ) && ! empty( $icone['type'] ), 'icône complète' );
		}
		// Sans icône du site : SVG du plugin, fichier présent.
		if ( ! (int) get_option( 'site_icon' ) ) {
			yume_assert_contains( 'includes/reader/assets/icone.svg', $m['icons'][0]['src'] );
			yume_assert_true( is_readable( YUME_CORE_DIR . 'includes/reader/assets/icone.svg' ) );
		}
	}
);

yume_test(
	'service worker : en-têtes (type JS, Service-Worker-Allowed, pas de cache) et configuration',
	function () {
		$entetes = entetes_sw();
		yume_assert_contains( 'javascript', $entetes['Content-Type'] );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		yume_assert_same( trailingslashit( '' !== $base ? $base : '/' ), $entetes['Service-Worker-Allowed'] );
		yume_assert_contains( 'no-cache', $entetes['Cache-Control'] );
		$config = configuration_sw();
		yume_assert_same( YUME_CORE_VERSION, $config['version'] );
		yume_assert_same( 30, $config['maxChapitres'] );
		yume_assert_true( str_ends_with( $config['lecture'], '/lire/' ) );
		yume_assert_contains( 'yume_hors_ligne=1', $config['horsLigne'] );
		$exclus = implode( ' ', array_merge( $config['systeme'], $config['exclus'] ) );
		yume_assert_contains( '/wp-admin/', $exclus );
		yume_assert_contains( 'wp-login.php', $exclus );
		yume_assert_contains( '/wp-json/', $exclus );
		if ( function_exists( 'yume_url_page' ) ) {
			yume_assert_contains( (string) wp_parse_url( yume_url_page( 'compte' ), PHP_URL_PATH ), implode( ' ', $config['exclus'] ), 'compte exclu' );
		}
		add_filter( 'yume_pwa_max_chapitres', static fn() => 12 );
		yume_assert_same( 12, configuration_sw()['maxChapitres'] );
		remove_all_filters( 'yume_pwa_max_chapitres' );
	}
);

yume_test(
	'service worker : code versionné, stratégies et exclusions présentes',
	function () {
		$js = contenu_sw();
		yume_assert_true( str_starts_with( $js, 'self.YUME_SW_CONFIG = {' ), 'configuration en tête' );
		yume_assert_contains( '"version":"' . YUME_CORE_VERSION . '"', $js );
		foreach ( array( "'wp-admin/'", "'wp-login.php'", "'wp-json/'", "'equipe/'", "'compte/'", "'connexion/'", 'rest_route', 'admin-ajax.php' ) as $exclu ) {
			yume_assert_contains( $exclu, $js, 'exclusion ' . $exclu );
		}
		yume_assert_contains( "requete.method !== 'GET'", $js, 'requêtes autres que GET ignorées' );
		yume_assert_contains( "credentials: 'omit'", $js, 'copie anonyme (sans cookie)' );
		yume_assert_contains( 'yume-hors-ligne', $js, 'marqueur de page publique vérifié' );
		yume_assert_contains( 'MAX_CHAPITRES', $js, 'limite LRU' );
		yume_assert_contains( 'skipWaiting', $js );
		yume_assert_contains( 'clients.claim', $js );
	}
);

yume_test(
	'service worker désactivé (filtre yume_pwa_actif) : vide les caches et se désinscrit',
	function () {
		yume_assert_true( pwa_actif(), 'actif par défaut' );
		add_filter( 'yume_pwa_actif', '__return_false' );
		try {
			yume_assert_false( pwa_actif() );
			$js = contenu_sw();
			yume_assert_contains( 'unregister', $js );
			yume_assert_contains( 'caches.delete', $js );
			yume_assert_not_contains( 'YUME_SW_CONFIG', $js );
			$pied = yume_lp_sortie( 'Yume\Core\Reader\script_pwa' );
			yume_assert_contains( 'getRegistrations', $pied, 'pages : désinscription' );
			yume_assert_not_contains( 'serviceWorker.register', $pied );
			yume_assert_same( '', yume_lp_sortie( 'Yume\Core\Reader\entete_pwa' ), 'pas de manifeste' );
		} finally {
			remove_filter( 'yume_pwa_actif', '__return_false' );
		}
		// Réglage Yume → Réglages « Lecture hors ligne ».
		$avant = get_option( 'yume_reglages' );
		update_option( 'yume_reglages', array_merge( is_array( $avant ) ? $avant : array(), array( 'pwa_hors_ligne' => false ) ) );
		yume_assert_false( pwa_actif(), 'réglage décoché' );
		update_option( 'yume_reglages', $avant );
		$champs = wp_list_pluck( \Yume\Core\Core\champs_reglages(), 'key' );
		yume_assert_true( in_array( 'pwa_hors_ligne', $champs, true ), 'champ déclaré' );
	}
);

yume_test(
	'page « Hors ligne » : document autonome, sans donnée de membre',
	function () {
		wp_set_current_user( yume_factory_user() );
		$html = page_hors_ligne();
		wp_set_current_user( 0 );
		yume_assert_true( str_starts_with( $html, '<!doctype html>' ) );
		yume_assert_contains( 'lang="fr"', $html );
		yume_assert_contains( 'Vous êtes hors ligne', $html );
		yume_assert_contains( 'noindex', $html );
		yume_assert_not_contains( 'wp_rest', $html );
		yume_assert_not_contains( 'nonce', $html );
	}
);

yume_test(
	'pages : manifeste partout, marqueur « mémorisable » seulement en lecture et pour un visiteur',
	function () {
		$s        = yume_lp_serie( array( 1 => 2 ) );
		$chapitre = $s['chapitres'][1][0];
		$visiteur = yume_lp_sur( $chapitre, static fn() => yume_lp_sortie( 'Yume\Core\Reader\entete_pwa' ) );
		yume_assert_contains( '<link rel="manifest" href="', $visiteur );
		yume_assert_contains( 'yume_manifest=1', $visiteur );
		yume_assert_contains( '<meta name="yume-hors-ligne" content="lecture">', $visiteur );
		$oeuvre = yume_lp_sur( $s['oeuvre'], static fn() => yume_lp_sortie( 'Yume\Core\Reader\entete_pwa' ) );
		yume_assert_contains( 'rel="manifest"', $oeuvre );
		yume_assert_not_contains( 'yume-hors-ligne', $oeuvre, 'fiche œuvre : jamais mise en cache' );
		wp_set_current_user( yume_factory_user() );
		$membre = yume_lp_sur( $chapitre, static fn() => yume_lp_sortie( 'Yume\Core\Reader\entete_pwa' ) );
		wp_set_current_user( 0 );
		yume_assert_not_contains( 'yume-hors-ligne', $membre, 'membre connecté : HTML personnel jamais mis en cache' );
		// Enregistrement : portée racine, chapitre suivant demandé sur une page de lecture.
		$pied = yume_lp_sur( $chapitre, static fn() => yume_lp_sortie( 'Yume\Core\Reader\script_pwa' ) );
		yume_assert_contains( 'serviceWorker.register', $pied );
		yume_assert_contains( 'yume_sw=1', $pied );
		yume_assert_contains( '"lecture":true', $pied );
		yume_assert_contains( 'link[rel=next]', $pied );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Accessibilité du panneau (AMEL-08)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'accessibilité : police OpenDyslexic proposée, fichiers et licence OFL présents',
	function () {
		$polices = polices();
		yume_assert_true( isset( $polices['opendyslexic'] ) );
		yume_assert_contains( 'OpenDyslexic', $polices['opendyslexic']['pile'] );
		yume_assert_contains( 'sans-serif', $polices['opendyslexic']['pile'], 'repli système' );
		$dossier = YUME_CORE_DIR . 'includes/reader/assets/polices/opendyslexic/';
		foreach ( array( '400-normal', '400-italic', '700-normal', '700-italic' ) as $variante ) {
			yume_assert_true( is_readable( $dossier . 'opendyslexic-latin-' . $variante . '.woff2' ), $variante );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		yume_assert_contains( 'SIL Open Font License', (string) file_get_contents( $dossier . 'OFL.txt' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = (string) file_get_contents( YUME_CORE_DIR . 'includes/reader/blocks/reader-tools/style.css' );
		yume_assert_contains( 'font-family: OpenDyslexic', $css );
		yume_assert_contains( 'html[data-yn-contraste="renforce"]', $css );
		yume_assert_contains( 'html[data-yn-animations="reduites"]', $css );
	}
);

yume_test(
	'accessibilité : options et raccourcis rendus dans le panneau Paramètres',
	function () {
		$s    = yume_lp_serie( array( 1 => 2 ) );
		$html = yume_lp_sur( $s['chapitres'][1][0], static fn() => yume_render_block( 'yume/reader-tools' ) );
		yume_assert_contains( '<legend>Accessibilité</legend>', $html );
		yume_assert_contains( 'data-yn-a11y="dyslexie"', $html );
		yume_assert_contains( 'data-yn-a11y="contraste"', $html );
		yume_assert_contains( 'data-yn-a11y="animations"', $html );
		yume_assert_contains( 'Police adaptée à la dyslexie', $html );
		yume_assert_contains( 'Contraste renforcé', $html );
		yume_assert_contains( 'Réduire les animations', $html );
		yume_assert_contains( 'value="opendyslexic"', $html, 'police dans la liste' );
		// Chaque case a son libellé.
		foreach ( array( 'yn-a11y-dyslexie', 'yn-a11y-contraste', 'yn-a11y-animations' ) as $id ) {
			yume_assert_contains( 'for="' . $id . '"', $html );
			yume_assert_contains( 'id="' . $id . '"', $html );
		}
		yume_assert_contains( 'Raccourcis clavier', $html );
		yume_assert_contains( '<kbd>←</kbd>', $html );
		yume_assert_contains( '<kbd>→</kbd>', $html );
		yume_assert_contains( 'Chapitre précédent', $html );
		yume_assert_contains( 'Chapitre suivant', $html );
		yume_assert_contains( 'Inactifs pendant la saisie', $html );
	}
);

yume_test(
	'accessibilité : options appliquées avant le premier rendu (yn.a11y) ; script sans saisie',
	function () {
		$s      = yume_lp_serie( array( 1 => 1 ) );
		$script = yume_lp_sur( $s['chapitres'][1][0], static fn() => yume_lp_sortie( 'Yume\Core\Reader\script_initialisation' ) );
		yume_assert_contains( 'yn.a11y', $script );
		yume_assert_contains( 'data-yn-contraste', $script );
		yume_assert_contains( 'data-yn-animations', $script );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js = (string) file_get_contents( YUME_CORE_DIR . 'includes/reader/blocks/reader-tools/view.js' );
		yume_assert_contains( "CLE_A11Y = 'yn.a11y'", $js );
		yume_assert_contains( 'estChampSaisie( e.target )', $js, 'raccourcis inactifs dans un champ' );
		yume_assert_contains( 'prefers-reduced-motion', $js );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Statistiques de lecture (PAGE-06)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'statistiques : chapitres lus, tomes terminés, temps estimé, séries en cours et à jour',
	function () {
		$u = yume_factory_user();
		// Série A : tomes 1 (3 chap.) et 2 (2 chap.) ; position : tome 2, chapitre 1 à 40 %.
		$a = yume_lp_serie(
			array(
				2 => 2,
				1 => 3,
			)
		);
		enregistrer_progression( $u, $a['chapitres'][2][0], 3, 40 );
		// Série B : un tome de 2 chapitres, dernier lu à 95 % → à jour.
		$b = yume_lp_serie( array( 1 => 2 ) );
		enregistrer_progression( $u, $b['chapitres'][1][1], 12, 95 );
		// Série C : chapitre 1 lu à 100 % sur 3 → en cours.
		$c = yume_lp_serie( array( 1 => 3 ), 460 );
		delete_post_meta( $c['chapitres'][1][0], 'yume_temps_lecture' );
		enregistrer_progression( $u, $c['chapitres'][1][0], 20, 100 );

		$stats = statistiques_lecture( $u );
		yume_assert_same( 3 + 2 + 1, $stats['chapitres_lus'] );
		yume_assert_same( 1 + 1 + 0, $stats['tomes_termines'] );
		// A : 3 × 10 min ; B : 2 × 10 min ; C : 460 mots / 230 = 2 min (repli sur yume_nb_mots).
		yume_assert_same( 30 + 20 + 2, $stats['minutes'] );
		yume_assert_same( 2, $stats['series_en_cours'] );
		yume_assert_same( 1, $stats['series_a_jour'] );
		$par_oeuvre = array_column( $stats['series'], null, 'oeuvre_id' );
		yume_assert_false( $par_oeuvre[ $a['oeuvre'] ]['a_jour'] );
		yume_assert_same( 2, $par_oeuvre[ $a['oeuvre'] ]['reste'] );
		yume_assert_same( 2, $par_oeuvre[ $a['oeuvre'] ]['tomes'] );
		yume_assert_true( $par_oeuvre[ $b['oeuvre'] ]['a_jour'] );
		yume_assert_same( 0, $par_oeuvre[ $b['oeuvre'] ]['reste'] );

		// Nouveau chapitre publié dans B : plus « à jour ».
		yume_factory_post(
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
		$b2 = array_column( statistiques_lecture( $u )['series'], null, 'oeuvre_id' )[ $b['oeuvre'] ];
		yume_assert_false( $b2['a_jour'] );
		yume_assert_same( 1, $b2['reste'] );

		// Autre membre, ou visiteur : rien.
		yume_assert_same( array(), statistiques_lecture( yume_factory_user() )['series'] );
		yume_assert_same( 0, statistiques_lecture( 0 )['chapitres_lus'] );
		yume_assert_same( '45 min', duree_lisible( 45 ) );
		yume_assert_same( '3 h 05', duree_lisible( 185 ) );
		yume_assert_same( '2 h', duree_lisible( 120 ) );
	}
);

yume_test(
	'statistiques : œuvre dépubliée ou chapitre retiré ignorés ; deux requêtes quel que soit le nombre de séries',
	function () {
		global $wpdb;
		$u = yume_factory_user();
		$a = yume_lp_serie( array( 1 => 2 ) );
		$b = yume_lp_serie( array( 1 => 2 ) );
		$c = yume_lp_serie( array( 1 => 2 ) );
		enregistrer_progression( $u, $a['chapitres'][1][1], 1, 100 );
		enregistrer_progression( $u, $b['chapitres'][1][0], 1, 10 );
		enregistrer_progression( $u, $c['chapitres'][1][0], 1, 10 );
		wp_update_post(
			array(
				'ID'          => $b['oeuvre'],
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'          => $c['chapitres'][1][0],
				'post_status' => 'draft',
			)
		);
		\Yume\Core\Reader\plan_de_lecture( array( $a['oeuvre'] ) );
		$avant = $wpdb->num_queries;
		\Yume\Core\Reader\plan_de_lecture( array( $a['oeuvre'], $b['oeuvre'], $c['oeuvre'] ) );
		yume_assert_same( 2, $wpdb->num_queries - $avant, 'requêtes agrégées' );
		$stats = statistiques_lecture( $u );
		yume_assert_same( array( $a['oeuvre'] ), array_column( $stats['series'], 'oeuvre_id' ) );
		yume_assert_same( 2, $stats['chapitres_lus'] );
	}
);

yume_test(
	'page compte : rubrique « Mes statistiques » du membre connecté',
	function () {
		$u = yume_factory_user();
		$a = yume_lp_serie( array( 1 => 2 ) );
		enregistrer_progression( $u, $a['chapitres'][1][1], 5, 100 );
		wp_set_current_user( $u );
		$html = \Yume\Core\Social\compte_connecte();
		wp_set_current_user( 0 );
		yume_assert_contains( 'href="#yn-stats"', $html, 'onglet' );
		yume_assert_contains( 'id="yn-stats"', $html );
		yume_assert_contains( 'Mes statistiques', $html );
		yume_assert_contains( 'data-yn-stat="tomes"', $html );
		yume_assert_contains( 'data-yn-stat="temps"', $html );
		yume_assert_contains( 'Tomes terminés', $html );
		yume_assert_contains( 'Temps de lecture estimé', $html );
		yume_assert_contains( 'data-yn-serie="' . $a['oeuvre'] . '"', $html );
		yume_assert_contains( 'À jour', $html );
		yume_assert_contains( '20 min', $html );
		// Membre sans lecture : message d'attente.
		wp_set_current_user( yume_factory_user() );
		$vide = \Yume\Core\Social\compte_connecte();
		wp_set_current_user( 0 );
		yume_assert_contains( 'Vos statistiques apparaîtront', $vide );
		yume_assert_not_contains( 'data-yn-serie=', $vide );
	}
);
