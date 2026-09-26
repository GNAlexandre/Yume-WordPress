<?php
/**
 * Tests du module lecture : table progression, réglages de lecture (valeurs par défaut,
 * bornes, liste blanche des polices), routes REST /moi/reglages et /moi/progression (droits,
 * validation, déduction de l'œuvre et du tome), nettoyage, bloc yume/reader-tools et script
 * d'initialisation des réglages.
 *
 * Lancement : tools/localenv/test.sh reader
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Reader\assainir_reglages;
use function Yume\Core\Reader\defauts_reglages;
use function Yume\Core\Reader\enregistrer_progression;
use function Yume\Core\Reader\lignes_progression;
use function Yume\Core\Reader\polices;
use function Yume\Core\Reader\reglages_enregistres;
use function Yume\Core\Reader\script_initialisation;
use function Yume\Core\Reader\table_progression;
use function Yume\Core\Reader\variables_css;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tr_)
 * -----------------------------------------------------------------------------
 */

/**
 * Crée une œuvre, un tome et des chapitres publiés (sans émettre d'événement de sortie).
 *
 * @param int    $nb      Nombre de chapitres.
 * @param string $statut  Statut des chapitres.
 * @return array{oeuvre:int,tome:int,chapitres:int[]}
 */
function yume_tr_serie( int $nb = 3, string $statut = 'publish' ): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	$oeuvre = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Grimgar ' . wp_rand( 1, 99999 ),
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'  => 'yume_tome',
			'post_title' => 'Grimgar — Tome 7',
			'post_name'  => 'tome-7',
			'meta_input' => array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => 7,
				'yume_nature'    => 'tome',
			),
		)
	);
	$ids    = array();
	for ( $i = 1; $i <= $nb; $i++ ) {
		$ids[] = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_title'   => 'Chapitre ' . $i,
				'post_name'    => 'chapitre-' . $i,
				'post_status'  => $statut,
				'menu_order'   => $i,
				'post_content' => '<!-- wp:paragraph --><p>Un.</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"yn-dialogue"} --><p class="yn-dialogue">— Deux.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id'    => $tome,
					'yume_numero'     => $i,
					'yume_nature'     => 'chapitre',
					'yume_sous_titre' => 'Sous-titre ' . $i,
				),
			)
		);
	}
	remove_filter( 'yume_core_notifier', '__return_false' );
	return array(
		'oeuvre'    => $oeuvre,
		'tome'      => $tome,
		'chapitres' => $ids,
	);
}

/**
 * Place la requête principale sur un contenu, exécute $rappel, puis restaure la requête.
 *
 * @param int      $post_id Contenu.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_tr_sur( int $post_id, callable $rappel ) {
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

/*
 * -----------------------------------------------------------------------------
 * Installation
 * -----------------------------------------------------------------------------
 */

yume_test(
	'table progression installée (schéma du contrat §13)',
	function () {
		global $wpdb;
		yume_assert_same( '1', get_option( 'yume_reader_schema' ) );
		$table = table_progression();
		yume_assert_same( $wpdb->prefix . 'yume_progression', $table );
		$s = yume_tr_serie( 1 );
		$u = yume_factory_user();
		enregistrer_progression( $u, $s['chapitres'][0], 4, 20 );
		$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $u ), ARRAY_A ); // phpcs:ignore WordPress.DB
		yume_assert_same( array( 'user_id', 'oeuvre_id', 'tome_id', 'chapitre_id', 'paragraphe', 'pourcentage', 'updated_at' ), array_keys( (array) $ligne ) );
		// Clé primaire (user_id, oeuvre_id) : REPLACE remplace la ligne existante.
		enregistrer_progression( $u, $s['chapitres'][0], 9, 50 );
		yume_assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $u ) ) ); // phpcs:ignore WordPress.DB
	}
);

/*
 * -----------------------------------------------------------------------------
 * Réglages de lecture
 * -----------------------------------------------------------------------------
 */

yume_test(
	'réglages : valeurs par défaut du doc 04 et 12 polices (Literata par défaut)',
	function () {
		yume_assert_same(
			array(
				'size'    => 18,
				'lh'      => 1.6,
				'font'    => 'literata',
				'width'   => 68,
				'bgAlpha' => 0.93,
				'theme'   => 'nuit',
			),
			defauts_reglages()
		);
		$polices = polices();
		yume_assert_same( 12, count( $polices ) );
		yume_assert_same( array( 'avenir', 'merriweather', 'arial', 'roboto', 'calibri', 'times', 'verdana', 'georgia', 'garamond', 'trebuchet', 'courier', 'literata' ), array_keys( $polices ) );
		yume_assert_same( 'Avenir Roman', $polices['avenir']['label'] );
		yume_assert_contains( 'Nunito Sans', $polices['avenir']['pile'], 'repli libre d’Avenir' );
		yume_assert_contains( 'serif', $polices['literata']['pile'] );
	}
);

yume_test(
	'réglages : bornes, liste blanche, conservation des valeurs valides',
	function () {
		$r = assainir_reglages(
			array(
				'size'    => 40,
				'lh'      => '1,35',
				'font'    => 'Comic Sans',
				'width'   => 10,
				'bgAlpha' => 0.2,
				'theme'   => 'rose',
				'autre'   => 'x',
			)
		);
		yume_assert_same( 26, $r['size'] );
		yume_assert_same( 1.35, $r['lh'] );
		yume_assert_same( 'literata', $r['font'] );
		yume_assert_same( 56, $r['width'] );
		yume_assert_same( 0.6, $r['bgAlpha'] );
		yume_assert_same( 'nuit', $r['theme'] );
		yume_assert_false( isset( $r['autre'] ) );
		$base = assainir_reglages( array( 'font' => 'georgia' ) );
		yume_assert_same( 'georgia', assainir_reglages( array( 'size' => 'abc' ), $base )['font'], 'mise à jour partielle' );
		yume_assert_same( 18, assainir_reglages( array( 'size' => 'abc' ), $base )['size'] );
		$css = variables_css(
			array(
				'size'  => 20,
				'width' => 70,
				'font'  => 'georgia',
			)
		);
		yume_assert_same( '20px', $css['--yn-size'] );
		yume_assert_same( '70ch', $css['--yn-width'] );
		yume_assert_contains( 'Georgia', $css['--yn-font'] );
	}
);

yume_test(
	'polices : une pile douteuse ajoutée par filtre est ignorée',
	function () {
		$filtre = static function ( $polices ) {
			$polices['piege'] = array(
				'label' => 'Piège',
				'pile'  => 'x;}body{display:none',
			);
			$polices['sobre'] = array(
				'label' => 'Sobre',
				'pile'  => '"Sobre Sans", sans-serif',
			);
			return $polices;
		};
		add_filter( 'yume_lecture_polices', $filtre );
		$polices = polices();
		remove_filter( 'yume_lecture_polices', $filtre );
		yume_assert_false( isset( $polices['piege'] ) );
		yume_assert_true( isset( $polices['sobre'] ) );
	}
);

yume_test(
	'REST /moi/reglages : connexion obligatoire',
	function () {
		yume_assert_same( 401, yume_rest( 'GET', '/yume/v1/moi/reglages' )->get_status() );
		yume_assert_same( 401, yume_rest( 'PUT', '/yume/v1/moi/reglages', array( 'size' => 20 ) )->get_status() );
	}
);

yume_test(
	'REST /moi/reglages : lecture, écriture partielle, validation, réinitialisation',
	function () {
		$u = yume_factory_user();
		$r = yume_rest( 'GET', '/yume/v1/moi/reglages', array(), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( defauts_reglages(), $r->get_data() );

		$r = yume_rest(
			'PUT',
			'/yume/v1/moi/reglages',
			array(
				'size'    => 22,
				'lh'      => 1.8,
				'font'    => 'merriweather',
				'width'   => 72,
				'bgAlpha' => 0.75,
				'theme'   => 'sepia',
			),
			$u
		);
		yume_assert_same( 200, $r->get_status() );
		$meta = get_user_meta( $u, 'yume_reglages', true );
		yume_assert_same( 22, $meta['size'] );
		yume_assert_same( 'merriweather', $meta['font'] );
		yume_assert_same( 'sepia', $meta['theme'] );
		yume_assert_same( 0.75, $meta['bgAlpha'] );

		// Mise à jour partielle : le reste est conservé.
		$r = yume_rest( 'PUT', '/yume/v1/moi/reglages', array( 'theme' => 'papier' ), $u );
		yume_assert_same( 'papier', $r->get_data()['theme'] );
		yume_assert_same( 22, $r->get_data()['size'] );

		// Hors bornes ou hors liste blanche : 400, rien n'est modifié.
		foreach ( array( array( 'size' => 13 ), array( 'size' => 27 ), array( 'lh' => 2.5 ), array( 'width' => 90 ), array( 'bgAlpha' => 0.5 ), array( 'font' => 'comic' ), array( 'theme' => 'rose' ) ) as $mauvais ) {
			$r = yume_rest( 'PUT', '/yume/v1/moi/reglages', $mauvais, $u );
			yume_assert_same( 400, $r->get_status(), wp_json_encode( $mauvais ) );
		}
		yume_assert_same( 22, reglages_enregistres( $u )['size'] );

		$r = yume_rest( 'PUT', '/yume/v1/moi/reglages', array( 'reinitialiser' => true ), $u );
		yume_assert_same( defauts_reglages(), $r->get_data() );
		yume_assert_same( null, reglages_enregistres( $u ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Progression
 * -----------------------------------------------------------------------------
 */

yume_test(
	'REST /moi/progression : connexion obligatoire',
	function () {
		yume_assert_same( 401, yume_rest( 'GET', '/yume/v1/moi/progression' )->get_status() );
		$s = yume_tr_serie( 1 );
		yume_assert_same( 401, yume_rest( 'PUT', '/yume/v1/moi/progression', array( 'chapitre_id' => $s['chapitres'][0] ) )->get_status() );
	}
);

yume_test(
	'REST PUT /moi/progression : œuvre et tome déduits, une ligne par œuvre, validation',
	function () {
		$s = yume_tr_serie( 3 );
		$u = yume_factory_user();

		$r = yume_rest(
			'PUT',
			'/yume/v1/moi/progression',
			array(
				'chapitre_id' => $s['chapitres'][1],
				'paragraphe'  => 12,
				'pourcentage' => 41,
			),
			$u
		);
		yume_assert_same( 200, $r->get_status() );
		$d = $r->get_data();
		yume_assert_same( $s['oeuvre'], $d['oeuvre_id'] );
		yume_assert_same( $s['tome'], $d['tome_id'] );
		yume_assert_same( 12, $d['paragraphe'] );
		yume_assert_contains( '#yn-p-13', $d['url_reprise'], 'ancre 1-based' );

		// Nouvelle position dans la même œuvre : remplace la précédente.
		yume_rest( 'PUT', '/yume/v1/moi/progression', array( 'chapitre_id' => $s['chapitres'][2] ), $u );
		$lignes = lignes_progression( $u );
		yume_assert_same( 1, count( $lignes ) );
		yume_assert_same( $s['chapitres'][2], $lignes[0]['chapitre_id'] );
		yume_assert_same( 0, $lignes[0]['paragraphe'] );

		// Validation.
		yume_assert_same( 400, yume_rest( 'PUT', '/yume/v1/moi/progression', array(), $u )->get_status(), 'chapitre obligatoire' );
		yume_assert_same(
			400,
			yume_rest(
				'PUT',
				'/yume/v1/moi/progression',
				array(
					'chapitre_id' => $s['chapitres'][0],
					'paragraphe'  => -1,
				),
				$u
			)->get_status()
		);
		yume_assert_same(
			400,
			yume_rest(
				'PUT',
				'/yume/v1/moi/progression',
				array(
					'chapitre_id' => $s['chapitres'][0],
					'pourcentage' => 101,
				),
				$u
			)->get_status()
		);
		yume_assert_same( 400, yume_rest( 'PUT', '/yume/v1/moi/progression', array( 'chapitre_id' => $s['oeuvre'] ), $u )->get_status(), 'pas un chapitre' );
		$brouillon = yume_tr_serie( 1, 'draft' );
		$r         = yume_rest( 'PUT', '/yume/v1/moi/progression', array( 'chapitre_id' => $brouillon['chapitres'][0] ), $u );
		yume_assert_same( 400, $r->get_status(), 'chapitre non publié' );
		yume_assert_same( 'yume_chapitre_invalide', $r->get_data()['code'] );
	}
);

yume_test(
	'REST GET /moi/progression et yume_get_progression : ordre, filtre par œuvre, forme des lignes',
	function () {
		$a = yume_tr_serie( 2 );
		$b = yume_tr_serie( 2 );
		$u = yume_factory_user();
		enregistrer_progression( $u, $a['chapitres'][0], 3, 10 );
		global $wpdb;
		$wpdb->update( table_progression(), array( 'updated_at' => '2026-01-01 10:00:00' ), array( 'user_id' => $u ) ); // phpcs:ignore WordPress.DB
		enregistrer_progression( $u, $b['chapitres'][1], 7, 55 );

		$toutes = yume_get_progression( $u );
		yume_assert_same( 2, count( $toutes ) );
		yume_assert_same( $b['oeuvre'], $toutes[0]['oeuvre_id'], 'plus récente d’abord' );
		yume_assert_same( array( 'oeuvre_id', 'chapitre_id', 'tome_id', 'paragraphe', 'pourcentage', 'updated_at' ), array_keys( $toutes[0] ) );
		yume_assert_same( 55, $toutes[0]['pourcentage'] );
		$une = yume_get_progression( $u, $a['oeuvre'] );
		yume_assert_same( 1, count( $une ) );
		yume_assert_same( $a['chapitres'][0], $une[0]['chapitre_id'] );

		$r = yume_rest( 'GET', '/yume/v1/moi/progression', array( 'oeuvre' => $b['oeuvre'] ), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 1, count( $r->get_data() ) );
		yume_assert_same( get_permalink( $b['chapitres'][1] ), $r->get_data()[0]['url'] );
		yume_assert_contains( 'Chapitre 2', $r->get_data()[0]['titre'] );

		// Chapitre dépublié : omis de la REST, conservé dans la table.
		wp_update_post(
			array(
				'ID'          => $b['chapitres'][1],
				'post_status' => 'draft',
			)
		);
		yume_assert_same( 1, count( yume_rest( 'GET', '/yume/v1/moi/progression', array(), $u )->get_data() ) );
		yume_assert_same( 2, count( yume_get_progression( $u ) ) );
		yume_assert_same( array(), yume_get_progression( 0 ) );
	}
);

yume_test(
	'progression : nettoyage à la suppression d’un utilisateur, d’un chapitre ou d’une œuvre',
	function () {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$s  = yume_tr_serie( 2 );
		$u1 = yume_factory_user();
		$u2 = yume_factory_user();
		$u3 = yume_factory_user();
		enregistrer_progression( $u1, $s['chapitres'][0] );
		enregistrer_progression( $u2, $s['chapitres'][1] );
		enregistrer_progression( $u3, $s['chapitres'][0] );
		wp_delete_user( $u1 );
		yume_assert_same( array(), lignes_progression( $u1 ) );
		wp_delete_post( $s['chapitres'][1], true );
		yume_assert_same( array(), lignes_progression( $u2 ) );
		yume_assert_same( 1, count( lignes_progression( $u3 ) ) );
		wp_delete_post( $s['oeuvre'], true );
		yume_assert_same( array(), lignes_progression( $u3 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/reader-tools et initialisation
 * -----------------------------------------------------------------------------
 */

yume_test(
	'bloc reader-tools : rien hors d’un chapitre',
	function () {
		$s = yume_tr_serie( 1 );
		yume_assert_same( '', trim( yume_tr_sur( $s['oeuvre'], static fn() => yume_render_block( 'yume/reader-tools' ) ) ) );
		$page = yume_factory_post( array( 'post_type' => 'page' ) );
		yume_assert_same( '', trim( yume_tr_sur( $page, static fn() => yume_render_block( 'yume/reader-tools' ) ) ) );
	}
);

yume_test(
	'bloc reader-tools (visiteur) : barre, progression, panneau accessible, configuration',
	function () {
		$s    = yume_tr_serie( 3 );
		$html = yume_tr_sur( $s['chapitres'][1], static fn() => yume_render_block( 'yume/reader-tools' ) );
		yume_assert_contains( 'class="yn-reader-tools wp-block-yume-reader-tools"', $html );
		yume_assert_contains( 'data-yn-progression', $html );
		yume_assert_contains( 'role="progressbar"', $html );
		yume_assert_contains( 'data-yn-action="marque-page"', $html );
		yume_assert_contains( 'data-yn-action="theme"', $html );
		yume_assert_contains( 'aria-haspopup="dialog"', $html );
		yume_assert_contains( 'aria-keyshortcuts="S"', $html );
		yume_assert_contains( '<dialog class="yn-reader-panel" id="yn-parametres-lecture" aria-labelledby="yn-parametres-titre"', $html );
		yume_assert_contains( 'aria-modal="true"', $html );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $s['tome'] ) ) . '"', $html, 'lien Sommaire' );
		yume_assert_contains( '2 sur 3', $html );
		yume_assert_same( 12, substr_count( $html, 'name="font"' ) );
		yume_assert_same( 3, substr_count( $html, 'name="theme"' ) );
		foreach ( array( 'Taille', 'Interligne', 'Opacité du fond', 'Largeur de colonne', 'Valider', 'Réinitialiser par défaut', 'Compact', 'Large', 'Transparent', 'Opaque' ) as $texte ) {
			yume_assert_contains( $texte, $html );
		}
		preg_match( '/data-yn-lecteur="([^"]+)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		yume_assert_same( $s['chapitres'][1], $config['chapitre'] );
		yume_assert_same( $s['oeuvre'], $config['oeuvre'] );
		yume_assert_same( get_permalink( $s['chapitres'][0] ), $config['prev'] );
		yume_assert_same( get_permalink( $s['chapitres'][2] ), $config['next'] );
		yume_assert_false( $config['connecte'] );
		yume_assert_same( '', $config['nonce'], 'pas de nonce pour un visiteur' );
		yume_assert_same( null, $config['reglages'] );
	}
);

yume_test(
	'bloc reader-tools (membre) : nonce REST, réglages et position du compte',
	function () {
		$s = yume_tr_serie( 2 );
		$u = yume_factory_user();
		enregistrer_progression( $u, $s['chapitres'][0], 5, 30 );
		update_user_meta(
			$u,
			'yume_reglages',
			array(
				'size' => 21,
				'font' => 'georgia',
			)
		);
		wp_set_current_user( $u );
		$html = yume_tr_sur( $s['chapitres'][0], static fn() => yume_render_block( 'yume/reader-tools' ) );
		wp_set_current_user( 0 );
		preg_match( '/data-yn-lecteur="([^"]+)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		yume_assert_true( $config['connecte'] );
		yume_assert_true( strlen( $config['nonce'] ) > 5 );
		yume_assert_contains( '/yume/v1/', $config['rest'] );
		yume_assert_same( 21, $config['reglages']['size'] );
		yume_assert_same( 5, $config['progression']['paragraphe'] );
		yume_assert_contains( 'value="21"', $html, 'curseur prérempli' );
		yume_assert_contains( 'sur votre compte', $html );
	}
);

yume_test(
	'script d’initialisation : chapitres seulement, réglages du compte transmis',
	function () {
		$s = yume_tr_serie( 1 );
		$u = yume_factory_user();
		update_user_meta(
			$u,
			'yume_reglages',
			array(
				'size'  => 24,
				'theme' => 'sepia',
			)
		);
		$hors = yume_tr_sur(
			$s['oeuvre'],
			static function () {
				ob_start();
				script_initialisation();
				return ob_get_clean();
			}
		);
		yume_assert_same( '', $hors );
		wp_set_current_user( $u );
		$sortie = yume_tr_sur(
			$s['chapitres'][0],
			static function () {
				ob_start();
				script_initialisation();
				return ob_get_clean();
			}
		);
		wp_set_current_user( 0 );
		yume_assert_contains( 'id="yume-lecture-init"', $sortie );
		yume_assert_contains( 'yn.reglages', $sortie );
		yume_assert_contains( '"size":24', $sortie );
		yume_assert_contains( '"theme":"sepia"', $sortie );
		yume_assert_contains( '.yn-reader{', $sortie );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Non-régression (revue)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'UX-8 : barre unique — lien de compte dans la barre, en-tête du gabarit masqué',
	function () {
		$s        = yume_tr_serie( 2 );
		$visiteur = yume_tr_sur( $s['chapitres'][0], static fn() => yume_render_block( 'yume/reader-tools' ) );
		yume_assert_contains( 'yn-reader-tools__compte', $visiteur );
		yume_assert_contains( '>Connexion</a>', $visiteur );
		$u = yume_factory_user();
		wp_update_user(
			array(
				'ID'           => $u,
				'display_name' => 'Kaede',
			)
		);
		wp_set_current_user( $u );
		$membre = yume_tr_sur( $s['chapitres'][0], static fn() => yume_render_block( 'yume/reader-tools' ) );
		wp_set_current_user( 0 );
		yume_assert_contains( 'yn-reader-tools__compte--membre', $membre );
		yume_assert_contains( 'aria-label="Mon compte (Kaede)"', $membre );
		yume_assert_not_contains( '>Connexion</a>', $membre );
		$gabarit = (string) file_get_contents( get_theme_root() . '/yume/templates/single-yume_chapitre.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_assert_not_contains( 'wp:template-part {"slug":"header-lecture"', $gabarit, 'La barre de lecture est le seul en-tête du chapitre.' );
		yume_assert_contains( '<!-- wp:yume/reader-tools /-->', $gabarit );
	}
);

yume_test(
	'MET-11 : hors chapitre, la bascule de thème d’un membre est enregistrée sur le compte',
	function () {
		$s      = yume_tr_serie( 1 );
		$sortie = static function ( int $post_id ): string {
			return yume_tr_sur(
				$post_id,
				static function () {
					ob_start();
					\Yume\Core\Reader\script_theme_membre();
					return (string) ob_get_clean();
				}
			);
		};
		yume_assert_same( '', $sortie( $s['oeuvre'] ), 'visiteur : rien' );
		$u = yume_factory_user();
		wp_set_current_user( $u );
		$html = $sortie( $s['oeuvre'] );
		yume_assert_contains( 'id="yume-theme-membre"', $html );
		yume_assert_contains( 'yn:theme', $html );
		yume_assert_contains( 'moi\/reglages', $html );
		yume_assert_contains( '"r":false', $html, 'compte sans réglages : jeu complet de l’appareil envoyé' );
		yume_assert_contains( 'yn.reglages', $html );
		yume_assert_same( '', $sortie( $s['chapitres'][0] ), 'chapitre : la barre de lecture s’en charge' );
		update_user_meta( $u, 'yume_reglages', array( 'theme' => 'sepia' ) );
		yume_assert_contains( '"r":true', $sortie( $s['oeuvre'] ) );
		wp_set_current_user( 0 );
	}
);

yume_test(
	'MET-7 / MET-8 : garde-fous du script de la barre (paragraphe à peine visible ignoré, thème avec jeu complet)',
	function () {
		$js = (string) file_get_contents( YUME_CORE_DIR . 'includes/reader/blocks/reader-tools/view.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_assert_contains( 'getBoundingClientRect().bottom - limite >= SEUIL_VISIBLE', $js, 'MET-7' );
		yume_assert_contains( 'config.reglages ? { theme: theme } : Object.assign( copie( enVigueur ), { theme: theme } )', $js, 'MET-8' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page « Illustrations » d'un tome
 * -----------------------------------------------------------------------------
 */

/**
 * Donne au tome d'une série une galerie de deux planches.
 *
 * @param int $tome_id Tome.
 * @return int[] Pièces jointes.
 */
function yume_tr_galerie( int $tome_id ): array {
	$ids = array();
	foreach ( array( 'planche-a.jpg', 'planche-b.jpg' ) as $nom ) {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => $nom,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			'2026/09/' . $nom
		);
		update_post_meta( $id, '_wp_attached_file', '2026/09/' . $nom );
		$ids[] = $id;
	}
	update_post_meta( $tome_id, 'yume_illustrations', $ids );
	flush_rewrite_rules( false );
	return $ids;
}

/**
 * Place la requête principale sur la page Illustrations d'un tome, exécute $rappel, restaure.
 *
 * @param int      $tome_id Tome.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_tr_sur_illustrations( int $tome_id, callable $rappel ) {
	global $wp_query, $wp_the_query, $post;
	$avant = array( $wp_query, $wp_the_query, $post );
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- contexte simulé puis restauré.
	$wp_query     = new WP_Query(
		array(
			'p'                       => $tome_id,
			'post_type'               => 'yume_tome',
			'yume_page_illustrations' => 1,
		)
	);
	$wp_the_query = $wp_query;
	$post         = get_post( $tome_id );
	try {
		return $rappel();
	} finally {
		list( $wp_query, $wp_the_query, $post ) = $avant;
	}
	// phpcs:enable
}

yume_test(
	'page Illustrations : barre de lecture sans chapitre ni marque-page, → chapitre 1, réglages appliqués',
	function () {
		$s = yume_tr_serie( 3 );
		yume_tr_galerie( $s['tome'] );
		$url  = yume_url_illustrations( $s['tome'] );
		$html = yume_tr_sur_illustrations( $s['tome'], static fn() => yume_render_block( 'yume/reader-tools' ) );
		yume_assert_contains( 'class="yn-reader-tools wp-block-yume-reader-tools"', $html );
		yume_assert_not_contains( 'data-yn-action="marque-page"', $html, 'pas de marque-page' );
		yume_assert_contains( 'data-yn-action="theme"', $html );
		yume_assert_contains( 'id="yn-parametres-lecture"', $html, 'panneau Paramètres' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $s['tome'] ) ) . '"', $html, 'retour et sommaire du tome' );
		yume_assert_contains( '<span>Illustrations</span>', $html );
		yume_assert_contains( '2 planches', $html );
		yume_assert_contains( 'data-yn-portee="des illustrations"', $html );
		yume_assert_contains( 'Connexion', $html );
		preg_match( '/data-yn-lecteur="([^"]+)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		yume_assert_same( 0, $config['chapitre'], 'aucun chapitre : aucun suivi de lecture' );
		yume_assert_same( $s['tome'], $config['tome'] );
		yume_assert_same( $s['oeuvre'], $config['oeuvre'] );
		yume_assert_same( $url, $config['url'] );
		yume_assert_same( '', $config['prev'] );
		yume_assert_same( get_permalink( $s['chapitres'][0] ), $config['next'] );

		// Réglages de lecture appliqués avant le premier rendu (largeur de colonne comprise).
		$init = yume_tr_sur_illustrations(
			$s['tome'],
			static function () {
				ob_start();
				script_initialisation();
				return (string) ob_get_clean();
			}
		);
		yume_assert_contains( 'id="yume-lecture-init"', $init );
		yume_assert_contains( '--yn-width', $init );

		// Chapitre 1 : ← mène aux illustrations ; chapitre 2 inchangé.
		$c1 = yume_tr_sur( $s['chapitres'][0], static fn() => yume_render_block( 'yume/reader-tools' ) );
		preg_match( '/data-yn-lecteur="([^"]+)"/', $c1, $m );
		yume_assert_same( $url, json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true )['prev'] );
		$c2 = yume_tr_sur( $s['chapitres'][1], static fn() => yume_render_block( 'yume/reader-tools' ) );
		preg_match( '/data-yn-lecteur="([^"]+)"/', $c2, $m );
		yume_assert_same( get_permalink( $s['chapitres'][0] ), json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true )['prev'] );

		// Le suivi du script exige un chapitre (config.chapitre) : rien n'est enregistré ici.
		$js = (string) file_get_contents( YUME_CORE_DIR . 'includes/reader/blocks/reader-tools/view.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_assert_contains( 'const suiviPossible = !! ( article && config.oeuvre && config.chapitre );', $js );
	}
);

yume_test(
	'page Illustrations : modèle « yume-illustrations » du thème, classes de lecture',
	function () {
		if ( 'yume' !== get_template() ) {
			return;
		}
		$s = yume_tr_serie( 1 );
		yume_tr_galerie( $s['tome'] );
		$modeles = yume_tr_sur_illustrations( $s['tome'], static fn() => apply_filters( 'single_template_hierarchy', array( 'single-yume_tome.php', 'single.php' ) ) );
		yume_assert_same( 'yume-illustrations.php', $modeles[0] );
		$classes = yume_tr_sur_illustrations( $s['tome'], static fn() => get_body_class() );
		yume_assert_true( in_array( 'yume-lecture', $classes, true ) && in_array( 'yume-illustrations', $classes, true ) );
		yume_assert_same( array( 'single-yume_tome.php' ), yume_tr_sur( $s['tome'], static fn() => apply_filters( 'single_template_hierarchy', array( 'single-yume_tome.php' ) ) ), 'page du tome inchangée' );

		yume_tr_sur_illustrations( $s['tome'], static fn() => get_single_template() );
		yume_assert_true( str_ends_with( (string) ( $GLOBALS['_wp_current_template_id'] ?? '' ), '//yume-illustrations' ), 'modèle de blocs résolu : ' . ( $GLOBALS['_wp_current_template_id'] ?? '' ) );
		$gabarit = (string) file_get_contents( get_theme_root() . '/yume/templates/yume-illustrations.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( array( 'wp:yume/reader-tools', 'wp:yume/chapter-header', 'wp:yume/tome-illustrations', 'wp:yume/chapter-nav', '"className":"yn-reader"' ) as $bloc ) {
			yume_assert_contains( $bloc, $gabarit );
		}
	}
);

yume_test(
	'page Illustrations : jamais une position de lecture, pourcentages du tome inchangés',
	function () {
		$s = yume_tr_serie( 3 );
		$u = yume_factory_user();
		yume_rest(
			'PUT',
			'/yume/v1/moi/progression',
			array(
				'chapitre_id' => $s['chapitres'][1],
				'paragraphe'  => 5,
				'pourcentage' => 50,
			),
			$u
		);
		$avancement = function_exists( 'Yume\Core\Social\avancement_tome' ) ? \Yume\Core\Social\avancement_tome( $s['tome'], $s['chapitres'][1], 50 ) : null;

		yume_tr_galerie( $s['tome'] );
		// Le tome (objet de la page Illustrations) n'est pas un chapitre : refusé par la REST.
		$r = yume_rest( 'PUT', '/yume/v1/moi/progression', array( 'chapitre_id' => $s['tome'] ), $u );
		yume_assert_same( 400, $r->get_status() );
		$lignes = lignes_progression( $u );
		yume_assert_same( 1, count( $lignes ) );
		yume_assert_same( $s['chapitres'][1], $lignes[0]['chapitre_id'], 'le marque-page reste sur le chapitre 2' );
		yume_assert_same( 5, $lignes[0]['paragraphe'] );
		yume_assert_same( 50, $lignes[0]['pourcentage'] );

		// La barre de la page Illustrations d'un membre ne porte aucun chapitre à enregistrer.
		wp_set_current_user( $u );
		$html = yume_tr_sur_illustrations( $s['tome'], static fn() => yume_render_block( 'yume/reader-tools' ) );
		wp_set_current_user( 0 );
		preg_match( '/data-yn-lecteur="([^"]+)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		yume_assert_same( 0, $config['chapitre'] );
		yume_assert_same( $s['chapitres'][1], (int) $config['progression']['chapitre_id'], 'position du compte transmise telle quelle' );

		if ( null !== $avancement ) {
			yume_assert_same( $avancement, \Yume\Core\Social\avancement_tome( $s['tome'], $s['chapitres'][1], 50 ), 'avancement dans le tome inchangé' );
			yume_assert_same( 100, \Yume\Core\Social\avancement_tome( $s['tome'], $s['chapitres'][2], 100 ) );
		}
		// Le rang « N sur M » des chapitres ne compte pas la page Illustrations.
		yume_assert_contains( '1 sur 3', yume_tr_sur( $s['chapitres'][0], static fn() => yume_render_block( 'yume/reader-tools' ) ) );
	}
);
