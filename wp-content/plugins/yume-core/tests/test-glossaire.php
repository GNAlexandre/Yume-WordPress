<?php
/**
 * Tests du module glossaire (PAGE-05) :
 *
 * - analyse et normalisation du YAML de Yume-Trad (fixture synthétique
 *   tools/fixtures/glossaire-exemple.yaml), erreurs situées (ligne), limites ;
 * - import : remplacement des entrées, « inchangé » (même empreinte), simulation sans écriture,
 *   journal de l'équipe, action yume_glossaire_importe, rétention de 5 versions, restauration ;
 * - API d'envoi ponctuel (authentification, YAML brut, JSON, multipart, simulation, GET, débit) ;
 * - vue « Glossaires » de l'espace équipe (droits, vérification, publication, restauration,
 *   téléchargement) ;
 * - page publique (champs internes absents pour un visiteur, notes pour l'équipe, entrée sans
 *   traduction masquée, anglicismes, 404, onglet, balises).
 *
 * Lancement : tools/localenv/test.sh glossaire
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Glossaire\analyser_glossaire;
use function Yume\Core\Glossaire\etat_glossaire;
use function Yume\Core\Glossaire\importer;
use function Yume\Core\Glossaire\lire_entrees;
use function Yume\Core\Glossaire\rendu_glossaire;
use function Yume\Core\Glossaire\table_entrees;
use function Yume\Core\Glossaire\table_versions;
use function Yume\Core\Glossaire\versions;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tg_)
 * -----------------------------------------------------------------------------
 */

/**
 * Contenu de la fixture synthétique.
 */
function yume_tg_fixture(): string {
	return (string) file_get_contents( dirname( __DIR__, 4 ) . '/tools/fixtures/glossaire-exemple.yaml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * Crée une œuvre publiée.
 *
 * @param string $titre Titre.
 */
function yume_tg_oeuvre( string $titre = 'Les Lanternes' ): int {
	return yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => 'publish',
		)
	);
}

/**
 * Petit glossaire valide (N entrées publiques dans « termes »).
 *
 * @param int    $n       Entrées.
 * @param string $suffixe Suffixe des noms (pour varier l'empreinte).
 */
function yume_tg_yaml( int $n, string $suffixe = '' ): string {
	$yaml = "termes:\n";
	for ( $i = 1; $i <= $n; $i++ ) {
		$yaml .= "- {termes_source: [用語{$i}], cibles: {fr: {nom: Terme {$i}{$suffixe}}}}\n";
	}
	return $yaml;
}

/**
 * Nombre de lignes des tables du glossaire pour une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{entrees:int,versions:int}
 */
function yume_tg_compter( int $oeuvre_id ): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return array(
		'entrees'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . table_entrees() . ' WHERE oeuvre_id = %d', $oeuvre_id ) ),
		'versions' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . table_versions() . ' WHERE oeuvre_id = %d', $oeuvre_id ) ),
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

/**
 * Rendu du bloc pour une œuvre, en tant qu'un compte.
 *
 * @param int $oeuvre_id Œuvre.
 * @param int $user_id   Compte (0 : visiteur).
 */
function yume_tg_rendu( int $oeuvre_id, int $user_id = 0 ): string {
	wp_set_current_user( $user_id );
	try {
		$bloc = new WP_Block(
			array(
				'blockName'   => 'yume/glossaire',
				'attrs'       => array(),
				'innerBlocks' => array(),
			),
			array(
				'postId'   => $oeuvre_id,
				'postType' => 'yume_oeuvre',
			)
		);
		return rendu_glossaire( array(), $bloc );
	} finally {
		wp_set_current_user( 0 );
	}
}

/**
 * Requête REST avec un corps brut.
 *
 * @param string $route   Route.
 * @param string $corps   Corps.
 * @param string $type    Content-Type.
 * @param int    $user_id Compte.
 * @param array  $query   Paramètres d'URL.
 */
function yume_tg_rest_brut( string $route, string $corps, string $type, int $user_id, array $query = array() ): WP_REST_Response {
	wp_set_current_user( $user_id );
	try {
		$requete = new WP_REST_Request( 'POST', $route );
		$requete->set_header( 'Content-Type', $type );
		$requete->set_body( $corps );
		$requete->set_query_params( $query );
		return rest_ensure_response( rest_do_request( $requete ) );
	} finally {
		wp_set_current_user( 0 );
	}
}

/**
 * Fichier temporaire comme une entrée de $_FILES (accepté par le filtre yume_glossaire_fichier_local).
 *
 * @param string $contenu Contenu.
 * @param string $nom     Nom d'origine.
 */
function yume_tg_fichier( string $contenu, string $nom = 'glossaire.yaml' ): array {
	$chemin = wp_tempnam( 'glossaire' );
	file_put_contents( $chemin, $contenu ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return array(
		'name'     => $nom,
		'type'     => 'application/yaml',
		'tmp_name' => $chemin,
		'error'    => UPLOAD_ERR_OK,
		'size'     => strlen( $contenu ),
	);
}

/**
 * Exécute $code en acceptant les fichiers locaux comme téléversés.
 *
 * @param callable $code Code.
 * @return mixed
 */
function yume_tg_fichiers_locaux( callable $code ) {
	add_filter( 'yume_glossaire_fichier_local', '__return_true' );
	try {
		return $code();
	} finally {
		remove_filter( 'yume_glossaire_fichier_local', '__return_true' );
	}
}

/**
 * Variables de la requête principale pour une URL (WP::parse_request).
 *
 * @param string $url URL.
 */
function yume_tg_requete( string $url ): WP_Query {
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput
	$sauve = array(
		'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
		'PHP_SELF'    => $_SERVER['PHP_SELF'] ?? null,
	);
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	$_SERVER['REQUEST_URI'] = (string) wp_parse_url( $url, PHP_URL_PATH );
	$_SERVER['PHP_SELF']    = '/index.php';
	try {
		$wp                    = new WP();
		$wp->public_query_vars = $GLOBALS['wp']->public_query_vars;
		$wp->parse_request();
		return new WP_Query( $wp->query_vars );
	} finally {
		foreach ( $sauve as $cle => $valeur ) {
			if ( null === $valeur ) {
				unset( $_SERVER[ $cle ] );
			} else {
				$_SERVER[ $cle ] = $valeur;
			}
		}
	}
}

/*
 * -----------------------------------------------------------------------------
 * Analyse et normalisation
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Glossaire : la fixture est analysée (catégories, entrées publiques, anglicismes, avertissement)',
	function () {
		$a = analyser_glossaire( yume_tg_fixture() );
		yume_assert_false( is_wp_error( $a ), is_wp_error( $a ) ? $a->get_error_message() : '' );
		yume_assert_same(
			array(
				'personnages'   => 4,
				'lieux'         => 2,
				'organisations' => 1,
				'creatures'     => 2,
				'objets'        => 2,
				'termes'        => 1,
				'evenements'    => 1,
				'chansons'      => 1,
				'anglicismes'   => 2,
			),
			$a['compteurs'],
			'catégories connues, puis inconnue, anglicismes en dernier'
		);
		yume_assert_same( 14, $a['entrees'] );
		yume_assert_same( 12, $a['publiques'], 'voyageur sans nom et 霧喰い sans traduction : non publics' );
		yume_assert_same( 2, $a['anglicismes'] );
		yume_assert_same( 1, count( $a['avertissements'] ) );
		yume_assert_contains( 'chansons, entrée 2 ignorée', $a['avertissements'][0] );
		yume_assert_same( hash( 'sha256', yume_tg_fixture() ), $a['sha256'] );

		$par_nom = array();
		foreach ( $a['lignes'] as $l ) {
			$par_nom[ $l['nom'] ] = $l;
		}
		$akari = $par_nom['Akari']['donnees'];
		yume_assert_same( array( 'アカリ', '灯里' ), $akari['termes_source'] );
		yume_assert_same( 'féminin', $akari['genre'] );
		yume_assert_same( array( 'Akari-chan' ), $akari['variantes'] );
		yume_assert_same( 'humain', $akari['provenance'] );
		yume_assert_same( 'sure', $akari['confiance'] );
		yume_assert_same( 1, $akari['tome'] );
		yume_assert_contains( 'refuse de quitter la vallée', $akari['description'], 'scalaire replié sur deux lignes' );

		$guetteur = $par_nom['Le Vieux Guetteur']['donnees'];
		yume_assert_same( "Gardien du col de Brume-Haute.\nIl tient le registre des départs depuis soixante ans.", $guetteur['description'], 'bloc | : retours à la ligne gardés' );
		yume_assert_same( 'masculin', $guetteur['genre'], 'm → masculin' );
		yume_assert_true( $guetteur['force'] );
		yume_assert_same( array( 'Vieil Homme du Brouillard' ), $guetteur['interdits'] );

		yume_assert_true( $par_nom['Sora']['donnees']['spoiler'] );
		yume_assert_same( 2, $par_nom['Sora']['donnees']['tome'] );

		$voyageur = $par_nom['名無しの旅人']['donnees'];
		yume_assert_false( $voyageur['public'], 'cibles.fr null : entrée à définir' );
		yume_assert_same( '', $voyageur['nom_fr'] );
		yume_assert_false( $par_nom['霧喰い']['donnees']['public'], 'cibles.fr vide et traduire null' );
		yume_assert_same( null, $par_nom['霧喰い']['donnees']['traduire'] );

		$hidamari = $par_nom['Hidamari']['donnees'];
		yume_assert_same( false, $hidamari['traduire'] );
		yume_assert_true( $hidamari['public'] );
		yume_assert_same( true, $par_nom['Brume-Haute']['donnees']['traduire'] );
		yume_assert_same( 'féminin', $par_nom['Brume-Haute']['donnees']['genre'], 'f → féminin' );
		yume_assert_true( isset( $par_nom['7'] ), 'nom numérique converti en texte' );
		yume_assert_false( isset( $par_nom['Lanterne de départ']['donnees']['champ_inconnu'] ), 'champ inconnu ignoré' );
		yume_assert_same( 'humain', $par_nom['Chanson des lumignons']['donnees']['provenance'] );
		yume_assert_same( 'Chansons', \Yume\Core\Glossaire\libelle_categorie( 'chansons' ) );

		// Colonne de recherche : minuscules sans accents, variantes et termes source compris.
		$recherche = $par_nom['Brume-Haute']['recherche'];
		yume_assert_contains( 'brume-haute', $recherche );
		yume_assert_contains( 'kiritaka', $recherche );
		yume_assert_contains( '霧高村', $recherche );
		yume_assert_contains( 'village de montagne', $recherche );
		yume_assert_not_contains( 'é', $par_nom['Akari']['recherche'] );
	}
);

yume_test(
	'Glossaire : texte brut borné (balises retirées, longueur), clés de catégorie normalisées, anglicismes en mapping',
	function () {
		$long = str_repeat( 'a', 2500 );
		$yaml = "Créatures:\n- termes_source: [x]\n  description: \"<script>alert(1)</script><b>Gras</b> et {$long}\"\n  cibles:\n    fr:\n      nom: '<img src=x onerror=alert(1)>Nom'\n      genre: autre\n  provenance: pirate\n  confiance: 12\nanglicismes:\n  Hello: Bonjour\n";
		$a    = analyser_glossaire( $yaml );
		yume_assert_false( is_wp_error( $a ), is_wp_error( $a ) ? $a->get_error_message() : '' );
		yume_assert_same( array( 'creatures', 'anglicismes' ), array_keys( $a['compteurs'] ) );
		$d = $a['lignes'][0]['donnees'];
		yume_assert_same( 'Nom', $d['nom'] );
		yume_assert_not_contains( '<', $d['description'] );
		yume_assert_not_contains( 'alert', $d['description'] );
		yume_assert_true( str_starts_with( $d['description'], 'Gras et aaa' ) );
		yume_assert_same( 2000, mb_strlen( $d['description'] ) );
		yume_assert_same( '', $d['genre'] );
		yume_assert_same( 'inconnue', $d['provenance'] );
		yume_assert_same( '', $d['confiance'] );
		yume_assert_same( 'Bonjour', $a['lignes'][1]['donnees']['fr'] );
		yume_assert_same( 'Hello', $a['lignes'][1]['donnees']['vo'] );
	}
);

yume_test(
	'Glossaire : erreurs claires (YAML invalide avec la ligne, racine, vide, 4 Mo, 5000 entrées)',
	function () {
		$e = analyser_glossaire( "personnages:\n- termes_source: [a\n  description: x\n" );
		yume_assert_true( is_wp_error( $e ) );
		yume_assert_same( 'yume_glossaire_yaml_invalide', $e->get_error_code() );
		yume_assert_true( (int) $e->get_error_data()['ligne'] > 0, 'numéro de ligne transmis' );
		yume_assert_contains( 'Ligne ', $e->get_error_message() );

		$e = analyser_glossaire( "personnages:\n  - a\n\tb: c\n" );
		yume_assert_true( is_wp_error( $e ) );

		yume_assert_same( 'yume_glossaire_structure', analyser_glossaire( "- a\n- b\n" )->get_error_code(), 'racine liste' );
		yume_assert_same( 'yume_glossaire_structure', analyser_glossaire( "juste du texte\n" )->get_error_code(), 'racine scalaire' );
		yume_assert_same( 'yume_glossaire_vide', analyser_glossaire( "# rien\n" )->get_error_code() );
		yume_assert_same( 'yume_glossaire_vide', analyser_glossaire( "personnages: []\nlieux:\n" )->get_error_code() );
		yume_assert_same( 'yume_glossaire_vide', analyser_glossaire( "personnages:\n- description: sans nom\n" )->get_error_code(), '0 entrée exploitable' );
		yume_assert_same( 'yume_glossaire_trop_gros', analyser_glossaire( 'a: ' . str_repeat( 'x', 4194304 ) )->get_error_code() );
		$e = analyser_glossaire( yume_tg_yaml( 5001 ) );
		yume_assert_same( 'yume_glossaire_trop_entrees', $e->get_error_code() );
		yume_assert_false( is_wp_error( analyser_glossaire( yume_tg_yaml( 5000 ) ) ), '5000 entrées acceptées' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Import
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Glossaire : import, remplacement complet, « inchangé » (même empreinte), simulation sans écriture, journal et action',
	function () {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \Yume\Core\Planning\table_journal() ); // phpcs:ignore WordPress.DB
		$oeuvre  = yume_tg_oeuvre();
		$editeur = yume_factory_user( 'yume_editeur' );

		// Simulation : rien n'est écrit.
		$b = importer( $oeuvre, yume_tg_fixture(), array( 'simulation' => true ) );
		yume_assert_same( 'simulation', $b['statut'] );
		yume_assert_same( 14, $b['total'] );
		yume_assert_same(
			array(
				'entrees'  => 0,
				'versions' => 0,
			),
			yume_tg_compter( $oeuvre )
		);
		yume_assert_same( 0, etat_glossaire( $oeuvre )['version'] );

		$recus  = array();
		$ecoute = static function ( $o, $v ) use ( &$recus ) {
			$recus[] = array( (int) $o, (int) $v );
		};
		add_action( 'yume_glossaire_importe', $ecoute, 10, 2 );
		try {
			$b = importer(
				$oeuvre,
				yume_tg_fixture(),
				array(
					'user_id' => $editeur,
					'source'  => 'televersement',
					'note'    => 'Première version',
				)
			);
		} finally {
			remove_action( 'yume_glossaire_importe', $ecoute, 10 );
		}
		yume_assert_same( 'importe', $b['statut'] );
		yume_assert_same( $oeuvre, $b['oeuvre']['id'] );
		yume_assert_same( 16, yume_tg_compter( $oeuvre )['entrees'], '14 entrées + 2 anglicismes' );
		yume_assert_same( 1, yume_tg_compter( $oeuvre )['versions'] );
		yume_assert_same( array( array( $oeuvre, (int) $b['version']['id'] ) ), $recus );
		$etat = etat_glossaire( $oeuvre );
		yume_assert_same( 14, $etat['entrees'] );
		yume_assert_same( 12, $etat['publiques'] );
		yume_assert_same( 2, $etat['anglicismes'] );
		yume_assert_same( (int) $b['version']['id'], $etat['version'] );
		yume_assert_same( 'Première version', $b['version']['note'] );
		yume_assert_true( str_ends_with( $b['oeuvre']['url_glossaire'], '/glossaire/' ), $b['oeuvre']['url_glossaire'] );

		// Journal de l'équipe (privé).
		$lignes = \Yume\Core\Planning\lire_journal( array( 'champs' => array( 'glossaire' ) ) );
		yume_assert_same( 1, count( $lignes ) );
		yume_assert_same( '0', (string) $lignes[0]->public );
		$entrees = \Yume\Core\Planning\grouper_journal( $lignes, true );
		yume_assert_same( 'a mis à jour le glossaire de « Les Lanternes » (14 entrées)', $entrees[0]['parties'][0] );
		yume_assert_same(
			array(),
			\Yume\Core\Planning\lire_journal(
				array(
					'champs' => array( 'glossaire' ),
					'public' => true,
				)
			),
			'jamais public'
		);

		// Même contenu : rien n'est réécrit.
		$b2 = importer( $oeuvre, yume_tg_fixture(), array( 'user_id' => $editeur ) );
		yume_assert_same( 'inchange', $b2['statut'] );
		yume_assert_same( 1, yume_tg_compter( $oeuvre )['versions'] );
		$s = importer( $oeuvre, yume_tg_fixture(), array( 'simulation' => true ) );
		yume_assert_true( $s['identique'] );

		// Nouveau contenu : remplacement complet des entrées.
		$b3 = importer( $oeuvre, yume_tg_yaml( 3 ), array( 'user_id' => $editeur ) );
		yume_assert_same( 'importe', $b3['statut'] );
		yume_assert_same( 3, yume_tg_compter( $oeuvre )['entrees'] );
		yume_assert_same( array( 'Terme 1', 'Terme 2', 'Terme 3' ), wp_list_pluck( lire_entrees( $oeuvre ), 'nom' ) );
		yume_assert_same( 2, yume_tg_compter( $oeuvre )['versions'] );

		// Erreur : rien ne change.
		$e = importer( $oeuvre, "- liste\n" );
		yume_assert_true( is_wp_error( $e ) );
		yume_assert_same( 3, yume_tg_compter( $oeuvre )['entrees'] );
		yume_assert_same( 'yume_oeuvre_introuvable', importer( 999999, yume_tg_yaml( 1 ) )->get_error_code() );

		// Une autre œuvre n'est pas touchée ; supprimer l'œuvre efface son glossaire.
		$autre = yume_tg_oeuvre( 'Autre' );
		importer( $autre, yume_tg_yaml( 2 ) );
		wp_delete_post( $oeuvre, true );
		yume_assert_same(
			array(
				'entrees'  => 0,
				'versions' => 0,
			),
			yume_tg_compter( $oeuvre )
		);
		yume_assert_same( 2, yume_tg_compter( $autre )['entrees'] );
	}
);

yume_test(
	'Glossaire : 5 versions conservées par œuvre, restauration d’une ancienne version',
	function () {
		$oeuvre = yume_tg_oeuvre();
		for ( $i = 1; $i <= 7; $i++ ) {
			importer( $oeuvre, yume_tg_yaml( $i ) );
		}
		$versions = versions( $oeuvre );
		yume_assert_same( 5, count( $versions ) );
		yume_assert_same( array( 7, 6, 5, 4, 3 ), array_map( 'intval', wp_list_pluck( $versions, 'nb_entrees' ) ) );

		$ancienne = (int) $versions[4]['id'];
		$b        = \Yume\Core\Glossaire\restaurer_version( $ancienne, 1 );
		yume_assert_same( 'importe', $b['statut'] );
		yume_assert_same( 'restauration', $b['version']['source'] );
		yume_assert_contains( 'Restauration de la version du', $b['version']['note'] );
		yume_assert_same( 3, count( lire_entrees( $oeuvre ) ) );
		yume_assert_same( 5, count( versions( $oeuvre ) ), 'toujours 5' );
		yume_assert_same( 'yume_glossaire_version_introuvable', \Yume\Core\Glossaire\restaurer_version( 999999, 1 )->get_error_code() );
	}
);

/*
 * -----------------------------------------------------------------------------
 * API (connecteur Yume-Trad)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Glossaire REST : anonyme 401, lecteur et traducteur 403, éditeur, gérant et administrateur autorisés',
	function () {
		$oeuvre = yume_tg_oeuvre();
		$route  = '/yume/v1/oeuvres/' . $oeuvre . '/glossaire';
		yume_assert_same( 401, yume_tg_rest_brut( $route, yume_tg_yaml( 1 ), 'application/yaml', 0 )->get_status() );
		yume_assert_same( 401, yume_rest( 'GET', $route )->get_status() );
		foreach ( array( 'subscriber', 'yume_traducteur', 'yume_relecteur' ) as $role ) {
			$u = yume_factory_user( $role );
			yume_assert_same( 403, yume_tg_rest_brut( $route, yume_tg_yaml( 1 ), 'application/yaml', $u )->get_status(), $role );
			yume_assert_same( 403, yume_rest( 'GET', $route, array(), $u )->get_status(), $role );
		}
		yume_assert_same( 0, yume_tg_compter( $oeuvre )['entrees'] );
		foreach ( array( 'yume_editeur', 'yume_gerant', 'administrator' ) as $n => $role ) {
			$u   = yume_factory_user( $role );
			$rep = yume_tg_rest_brut( $route, yume_tg_yaml( $n + 1 ), 'application/yaml', $u );
			yume_assert_same( 200, $rep->get_status(), $role . ' ' . wp_json_encode( $rep->get_data() ) );
			yume_assert_same( 'importe', $rep->get_data()['statut'] );
		}
		yume_assert_true( user_can( yume_factory_user( 'yume_gerant' ), \Yume\Core\Glossaire\CAPACITE ) );
		yume_assert_false( user_can( yume_factory_user( 'yume_graphiste' ), \Yume\Core\Glossaire\CAPACITE ) );
	}
);

yume_test(
	'Glossaire REST : YAML brut, JSON, multipart, simulation, slug, erreurs, GET des métadonnées, débit',
	function () {
		$oeuvre  = yume_tg_oeuvre( 'Lanternes API' );
		$slug    = (string) get_post_field( 'post_name', $oeuvre );
		$editeur = yume_factory_user( 'yume_editeur' );
		$route   = '/yume/v1/oeuvres/' . $slug . '/glossaire';

		// GET sans glossaire.
		$get = yume_rest( 'GET', $route, array(), $editeur );
		yume_assert_same( 200, $get->get_status() );
		yume_assert_same( null, $get->get_data()['version'] );

		// Simulation (YAML brut) : bilan, rien d'écrit.
		$rep = yume_tg_rest_brut( $route, yume_tg_fixture(), 'text/yaml', $editeur, array( 'simulation' => '1' ) );
		yume_assert_same( 200, $rep->get_status() );
		$d = $rep->get_data();
		yume_assert_same( 'simulation', $d['statut'] );
		yume_assert_same( 4, $d['nb_entrees']['personnages'] );
		yume_assert_same( 1, count( $d['avertissements'] ) );
		yume_assert_same( 'Lanternes API', $d['oeuvre']['titre'] );
		yume_assert_same( 0, yume_tg_compter( $oeuvre )['entrees'] );

		// YAML brut.
		$rep = yume_tg_rest_brut( $route, yume_tg_fixture(), 'application/yaml; charset=utf-8', $editeur );
		yume_assert_same( 'importe', $rep->get_data()['statut'] );
		yume_assert_same( 'api', $rep->get_data()['version']['source'] );
		yume_assert_same( 16, yume_tg_compter( $oeuvre )['entrees'] );

		// Même fichier : inchangé.
		yume_assert_same( 'inchange', yume_tg_rest_brut( $route, yume_tg_fixture(), 'text/plain', $editeur )->get_data()['statut'] );

		// JSON { yaml, note }.
		$rep = yume_tg_rest_brut(
			'/yume/v1/oeuvres/' . $oeuvre . '/glossaire',
			(string) wp_json_encode(
				array(
					'yaml' => yume_tg_yaml( 2 ),
					'note' => 'Depuis Yume-Trad 2.40',
				)
			),
			'application/json',
			$editeur
		);
		yume_assert_same( 'importe', $rep->get_data()['statut'], wp_json_encode( $rep->get_data() ) );
		yume_assert_same( 'Depuis Yume-Trad 2.40', $rep->get_data()['version']['note'] );
		yume_assert_same( 2, yume_tg_compter( $oeuvre )['entrees'] );

		// Multipart (fichier « glossaire »).
		$rep = yume_tg_fichiers_locaux( static fn() => yume_rest( 'POST', $route, array(), $editeur, array( 'glossaire' => yume_tg_fichier( yume_tg_yaml( 4 ) ) ) ) );
		yume_assert_same( 'importe', $rep->get_data()['statut'], wp_json_encode( $rep->get_data() ) );
		yume_assert_same( 4, yume_tg_compter( $oeuvre )['entrees'] );
		// Fichier non téléversé par PHP (sans le filtre) ou mauvaise extension : refusés.
		yume_assert_same( 400, yume_rest( 'POST', $route, array(), $editeur, array( 'glossaire' => yume_tg_fichier( yume_tg_yaml( 5 ) ) ) )->get_status() );
		$rep = yume_tg_fichiers_locaux( static fn() => yume_rest( 'POST', $route, array(), $editeur, array( 'glossaire' => yume_tg_fichier( yume_tg_yaml( 5 ), 'glossaire.php' ) ) ) );
		yume_assert_same( 415, $rep->get_status() );

		// GET : métadonnées de la version courante, sans le contenu.
		$get = yume_rest( 'GET', $route, array(), $editeur )->get_data();
		yume_assert_same( hash( 'sha256', yume_tg_yaml( 4 ) ), $get['version']['sha256'] );
		yume_assert_same( 4, $get['version']['nb_entrees'] );
		yume_assert_true( (bool) strtotime( $get['version']['cree_le'] ) );
		yume_assert_false( isset( $get['version']['yaml'] ) );
		yume_assert_not_contains( '用語', (string) wp_json_encode( $get ) );

		// Erreurs.
		$rep = yume_tg_rest_brut( $route, "a: [b\n", 'application/yaml', $editeur );
		yume_assert_same( 400, $rep->get_status() );
		yume_assert_same( 'yume_glossaire_yaml_invalide', $rep->get_data()['code'] );
		yume_assert_true( (int) $rep->get_data()['data']['ligne'] > 0 );
		yume_assert_same( 400, yume_tg_rest_brut( $route, '', 'application/json', $editeur )->get_status() );
		yume_assert_same( 404, yume_tg_rest_brut( '/yume/v1/oeuvres/inconnue-xyz/glossaire', yume_tg_yaml( 1 ), 'application/yaml', $editeur )->get_status() );
		yume_assert_same( 404, yume_rest( 'GET', '/yume/v1/oeuvres/999999/glossaire', array(), $editeur )->get_status() );

		// Débit : limite atteinte → 429.
		$limite = static fn(): int => 1;
		add_filter( 'yume_glossaire_envois_par_heure', $limite );
		try {
			$autre = yume_factory_user( 'yume_editeur' );
			yume_assert_same( 200, yume_tg_rest_brut( $route, yume_tg_yaml( 1 ), 'application/yaml', $autre, array( 'simulation' => 'true' ) )->get_status() );
			$rep = yume_tg_rest_brut( $route, yume_tg_yaml( 1 ), 'application/yaml', $autre );
			yume_assert_same( 429, $rep->get_status() );
			yume_assert_same( 'yume_glossaire_limite', $rep->get_data()['code'] );
		} finally {
			remove_filter( 'yume_glossaire_envois_par_heure', $limite );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Vue « Glossaires » de l'espace équipe
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Glossaire, vue équipe : réservée à yume_glossaire, vérification sans écriture, publication, restauration, téléchargement',
	function () {
		\Yume\Core\Core\installer_roles();
		$oeuvre  = yume_tg_oeuvre();
		$editeur = yume_factory_user( 'yume_editeur' );
		$trad    = yume_factory_user( 'yume_traducteur' );

		wp_set_current_user( $trad );
		yume_assert_false( isset( \Yume\Core\Planning\vues_equipe_ajoutees()['glossaire'] ), 'traducteur : pas de vue' );
		wp_set_current_user( $editeur );
		yume_assert_true( isset( \Yume\Core\Planning\vues_equipe_ajoutees()['glossaire'] ) );
		$html = \Yume\Core\Glossaire\rendu_vue_glossaire();
		yume_assert_contains( 'enctype="multipart/form-data"', $html );
		yume_assert_contains( 'name="glossaire"', $html );
		yume_assert_contains( 'value="verifier"', $html );
		yume_assert_contains( 'Publier le glossaire', $html );
		yume_assert_contains( '/yume/v1/oeuvres/', $html );
		wp_set_current_user( 0 );

		$nonce = static function ( int $user_id ): string {
			wp_set_current_user( $user_id );
			$n = wp_create_nonce( 'yume_glossaire' );
			wp_set_current_user( 0 );
			return $n;
		};
		$envoi = static function ( array $post, array $files, int $user_id ) use ( $nonce ): array {
			$post['_yume_nonce'] = $post['_yume_nonce'] ?? $nonce( $user_id );
			wp_set_current_user( $user_id );
			try {
				return yume_tg_fichiers_locaux( static fn() => \Yume\Core\Glossaire\traiter_formulaire_glossaire( $post, $files, $user_id ) );
			} finally {
				wp_set_current_user( 0 );
			}
		};

		// Droits et nonce.
		$r = $envoi(
			array(
				'op'     => 'publier',
				'oeuvre' => $oeuvre,
			),
			array( 'glossaire' => yume_tg_fichier( yume_tg_fixture() ) ),
			$trad
		);
		yume_assert_same( 'erreur', $r['type'] );
		$r = $envoi(
			array(
				'op'          => 'publier',
				'oeuvre'      => $oeuvre,
				'_yume_nonce' => 'x',
			),
			array( 'glossaire' => yume_tg_fichier( yume_tg_fixture() ) ),
			$editeur
		);
		yume_assert_contains( 'session a expiré', $r['message'] );
		yume_assert_same( 0, yume_tg_compter( $oeuvre )['entrees'] );

		// Vérifier : bilan en brouillon, rien d'écrit.
		$r = $envoi(
			array(
				'op'     => 'verifier',
				'oeuvre' => $oeuvre,
				'note'   => 'Essai',
			),
			array( 'glossaire' => yume_tg_fichier( yume_tg_fixture(), 'lanternes.yaml' ) ),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( '14 entrées', $r['message'] );
		yume_assert_same( 0, yume_tg_compter( $oeuvre )['versions'] );
		$brouillon = \Yume\Core\Glossaire\brouillon( $editeur );
		yume_assert_same( $oeuvre, $brouillon['oeuvre'] );
		wp_set_current_user( $editeur );
		$html = \Yume\Core\Glossaire\rendu_vue_glossaire();
		wp_set_current_user( 0 );
		yume_assert_contains( 'lanternes.yaml', $html );
		yume_assert_contains( 'chansons, entrée 2 ignorée', $html );
		yume_assert_contains( 'value="publier_brouillon"', $html );

		// Publier le brouillon.
		$r = $envoi(
			array(
				'op'     => 'publier_brouillon',
				'oeuvre' => $oeuvre,
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_contains( 'publié', $r['message'] );
		yume_assert_same( 16, yume_tg_compter( $oeuvre )['entrees'] );
		yume_assert_same( null, \Yume\Core\Glossaire\brouillon( $editeur ) );
		$v = versions( $oeuvre );
		yume_assert_same( 'televersement', $v[0]['source'] );
		yume_assert_same( 'Essai', $v[0]['note'] );
		yume_assert_same( 'erreur', $envoi( array( 'op' => 'publier_brouillon' ), array(), $editeur )['type'], 'brouillon consommé' );

		// Erreurs de fichier.
		$r = $envoi(
			array(
				'op'     => 'publier',
				'oeuvre' => $oeuvre,
			),
			array( 'glossaire' => yume_tg_fichier( "a: [b\n" ) ),
			$editeur
		);
		yume_assert_contains( 'Ligne 1', $r['message'] );
		$r = $envoi(
			array(
				'op'     => 'publier',
				'oeuvre' => $oeuvre,
			),
			array( 'glossaire' => yume_tg_fichier( yume_tg_yaml( 1 ), 'image.png' ) ),
			$editeur
		);
		yume_assert_contains( 'Type de fichier refusé', $r['message'] );
		$r = $envoi(
			array(
				'op'     => 'publier',
				'oeuvre' => $oeuvre,
			),
			array( 'glossaire' => yume_tg_fichier( "\x89PNG\0\0binaire" ) ),
			$editeur
		);
		yume_assert_contains( 'Type de fichier refusé', $r['message'] );
		$r = $envoi( array( 'op' => 'publier' ), array( 'glossaire' => yume_tg_fichier( yume_tg_yaml( 1 ) ) ), $editeur );
		yume_assert_contains( 'Choisissez une œuvre', $r['message'] );

		// Publier directement, puis restaurer la première version.
		$r = $envoi(
			array(
				'op'     => 'publier',
				'oeuvre' => $oeuvre,
			),
			array( 'glossaire' => yume_tg_fichier( yume_tg_yaml( 2 ) ) ),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 2, yume_tg_compter( $oeuvre )['entrees'] );
		$premiere = (int) versions( $oeuvre )[1]['id'];
		wp_set_current_user( $editeur );
		$_GET['oeuvre'] = (string) $oeuvre;
		$html           = \Yume\Core\Glossaire\rendu_vue_glossaire();
		unset( $_GET['oeuvre'] );
		wp_set_current_user( 0 );
		yume_assert_contains( 'Restaurer cette version', $html );
		yume_assert_contains( 'Télécharger ce YAML', $html );
		yume_assert_contains( 'En ligne', $html );
		$r = $envoi(
			array(
				'op'      => 'restaurer',
				'oeuvre'  => $oeuvre,
				'version' => $premiere,
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 16, yume_tg_compter( $oeuvre )['entrees'] );
		yume_assert_same( 'restauration', versions( $oeuvre )[0]['source'] );

		// Téléchargement : nonce par version et capacité.
		wp_set_current_user( $editeur );
		$nonce_v = wp_create_nonce( 'yume_glossaire_yaml_' . $premiere );
		$f       = \Yume\Core\Glossaire\fichier_version(
			array(
				'version'  => $premiere,
				'_wpnonce' => $nonce_v,
			),
			$editeur
		);
		yume_assert_false( is_wp_error( $f ), is_wp_error( $f ) ? $f->get_error_message() : '' );
		yume_assert_same( yume_tg_fixture(), $f['contenu'] );
		yume_assert_true( str_starts_with( $f['nom'], 'glossaire-les-lanternes' ) && str_ends_with( $f['nom'], '.yaml' ), $f['nom'] );
		yume_assert_true(
			is_wp_error(
				\Yume\Core\Glossaire\fichier_version(
					array(
						'version'  => $premiere,
						'_wpnonce' => 'x',
					),
					$editeur
				)
			)
		);
		wp_set_current_user( $trad );
		$nonce_t = wp_create_nonce( 'yume_glossaire_yaml_' . $premiere );
		yume_assert_same(
			'yume_interdit',
			\Yume\Core\Glossaire\fichier_version(
				array(
					'version'  => $premiere,
					'_wpnonce' => $nonce_t,
				),
				$trad
			)->get_error_code()
		);
		wp_set_current_user( 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page publique
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Glossaire public : aucun champ interne pour un visiteur, entrées sans traduction masquées, spoiler, anglicismes, recherche',
	function () {
		$oeuvre = yume_tg_oeuvre();
		importer( $oeuvre, yume_tg_fixture() );
		$html = yume_tg_rendu( $oeuvre );

		foreach ( array( 'Akari', 'Le Vieux Guetteur', 'Brume-Haute', 'Hidamari', 'Chanson des lumignons', 'Gardiens de la Flamme' ) as $nom ) {
			yume_assert_contains( '>' . $nom . '</h3>', $html, $nom );
		}
		// Champs internes : provenance, preuve, confiance, interdits, force, variantes.
		foreach ( array( 'Planche couleur', 'Akari-chan', 'Vieil Homme du Brouillard', 'lutin', 'imposé', 'Provenance', 'hypothèse', 'Kiritaka', 'yn-glossaire__notes', 'data-yn-glossaire-note', 'data-yn-glossaire-bascule' ) as $interne ) {
			yume_assert_not_contains( $interne, $html, 'visiteur : ' . $interne );
		}
		// Entrées sans traduction française (hors « traduire: false ») : absentes.
		yume_assert_not_contains( '名無しの旅人', $html );
		yume_assert_not_contains( '霧喰い', $html );
		yume_assert_not_contains( 'À définir', $html );

		yume_assert_contains( 'Nom conservé', $html );
		yume_assert_contains( '<span lang="ja">アカリ</span>', $html );
		yume_assert_contains( 'masculin', $html );
		yume_assert_contains( 'pluriel : Lanternes de départ', $html );
		yume_assert_contains( "Gardien du col de Brume-Haute.<br>\nIl tient", $html, 'retours à la ligne' );
		yume_assert_contains( '<summary>Révéler (spoiler, tome 2)</summary>', $html );
		yume_assert_contains( '<summary>Révéler (spoiler, tome 3)</summary>', $html );
		// Sommaire, sections, compteurs, recherche.
		yume_assert_contains( 'aria-label="Catégories du glossaire"', $html );
		yume_assert_contains( '<a href="#yn-glossaire-personnages">Personnages <span class="yn-glossaire__nb">3</span></a>', $html );
		yume_assert_contains( '<h2 id="yn-glossaire-chansons-titre">Chansons</h2>', $html );
		yume_assert_contains( 'data-yn-glossaire-outils hidden', $html );
		yume_assert_contains( 'aria-live="polite"', $html );
		yume_assert_contains( 'data-yn-recherche="brume-haute', $html, 'recherche normalisée' );
		yume_assert_not_contains( 'kiritaka', $html, 'variantes hors de la recherche publique' );
		// Anglicismes.
		yume_assert_contains( '<caption', $html );
		yume_assert_contains( '<td lang="en">See you!</td><td>À la prochaine !</td>', $html );
		yume_assert_contains( 'yn-glossaire-anglicismes', $html );
		yume_assert_true( strpos( $html, 'yn-glossaire-chansons' ) < strpos( $html, 'yn-glossaire-anglicismes' ), 'anglicismes en dernier' );
	}
);

yume_test(
	'Glossaire public : l’équipe voit les notes de traduction (masquées au chargement, ?notes=1) et les entrées à définir',
	function () {
		$oeuvre = yume_tg_oeuvre();
		importer( $oeuvre, yume_tg_fixture() );
		$trad = yume_factory_user( 'yume_traducteur' );
		$html = yume_tg_rendu( $oeuvre, $trad );
		yume_assert_contains( 'Afficher les notes de traduction', $html );
		yume_assert_contains( 'aria-pressed="false"', $html );
		yume_assert_contains( 'Akari-chan', $html );
		yume_assert_contains( '<del>Vieil Homme du Brouillard</del>', $html );
		yume_assert_contains( 'imposé', $html );
		yume_assert_contains( 'Planche couleur du tome 1', $html );
		yume_assert_contains( 'hypothèse', $html );
		yume_assert_contains( 'terminologue', $html );
		yume_assert_contains( '名無しの旅人', $html );
		yume_assert_contains( 'À définir', $html );
		yume_assert_contains( 'class="yn-glossaire__notes" data-yn-glossaire-note hidden', $html );
		yume_assert_not_contains( 'Gérer le glossaire', $html, 'traducteur : pas de gestion' );

		$_GET['notes'] = '1';
		try {
			$html = yume_tg_rendu( $oeuvre, yume_factory_user( 'yume_editeur' ) );
			yume_assert_contains( 'aria-pressed="true"', $html );
			yume_assert_contains( 'Masquer les notes de traduction', $html );
			yume_assert_not_contains( 'data-yn-glossaire-note hidden', $html );
			yume_assert_contains( 'Gérer le glossaire', $html );
			// Un visiteur ne peut pas demander les notes.
			$html = yume_tg_rendu( $oeuvre );
			yume_assert_not_contains( 'Akari-chan', $html );
		} finally {
			unset( $_GET['notes'] );
		}
	}
);

yume_test(
	'Glossaire public : onglet et page seulement avec un glossaire (404 sinon), titre, canonique, description, noindex sous 5 entrées',
	function () {
		global $wp_rewrite, $wp_query;
		$wp_rewrite->flush_rules( false );
		$oeuvre = yume_tg_oeuvre( 'Lanternes Page' );
		$fiche  = array(
			'fiche' => array(
				'libelle' => 'Présentation',
				'url'     => (string) get_permalink( $oeuvre ),
			),
		);
		yume_assert_same( array( 'fiche' ), array_keys( apply_filters( 'yume_onglets_oeuvre', $fiche, $oeuvre ) ), 'sans glossaire : pas d’onglet' );
		yume_assert_contains( 'glossaire', implode( ',', \Yume\Core\Core\onglets_oeuvre() ) );

		$url      = \Yume\Core\Glossaire\url_glossaire( $oeuvre );
		$sauve    = $wp_query;
		$afficher = static function () use ( $url ) {
			$GLOBALS['wp_query'] = yume_tg_requete( $url ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			\Yume\Core\Glossaire\page_introuvable();
			return $GLOBALS['wp_query'];
		};
		try {
			yume_assert_true( $afficher()->is_404(), 'sans glossaire : 404' );

			// Glossaire de 3 entrées : onglet, page, noindex.
			importer( $oeuvre, yume_tg_yaml( 3 ) );
			$onglets = apply_filters( 'yume_onglets_oeuvre', $fiche, $oeuvre );
			yume_assert_same( array( 'fiche', 'glossaire' ), array_keys( $onglets ) );
			yume_assert_same( 'Glossaire', $onglets['glossaire']['libelle'] );
			yume_assert_same( $url, $onglets['glossaire']['url'] );
			$q = $afficher();
			yume_assert_false( $q->is_404() );
			yume_assert_same( $oeuvre, \Yume\Core\Glossaire\oeuvre_page_glossaire() );
			yume_assert_same( 'Glossaire — Lanternes Page', \Yume\Core\Glossaire\titre_document( array( 'title' => 'x' ) )['title'] );
			yume_assert_same( $url, \Yume\Core\Glossaire\canonique( 'x', get_post( $oeuvre ) ) );
			yume_assert_true( ! empty( \Yume\Core\Glossaire\robots( array() )['noindex'] ), 'moins de 5 entrées publiques : noindex' );
			$balises = \Yume\Core\Glossaire\balises_partage( array( 'description' => 'x' ), $oeuvre );
			yume_assert_contains( 'Glossaire de « Lanternes Page »', $balises['description'] );
			yume_assert_same( $url, $balises['og:url'] );

			importer( $oeuvre, yume_tg_yaml( 6 ) );
			yume_assert_true( empty( \Yume\Core\Glossaire\robots( array() )['noindex'] ), '6 entrées : indexable' );

			// Entrées toutes sans traduction : 404 pour un visiteur, page pour l'équipe.
			importer( $oeuvre, "termes:\n- termes_source: [未訳]\n  cibles:\n    fr: null\n" );
			yume_assert_true( $afficher()->is_404(), 'aucune entrée publique : 404' );
			yume_assert_same( array( 'fiche' ), array_keys( apply_filters( 'yume_onglets_oeuvre', $fiche, $oeuvre ) ) );
			wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
			yume_assert_false( $afficher()->is_404(), 'équipe : page visible' );
			yume_assert_contains( 'glossaire', implode( ',', array_keys( apply_filters( 'yume_onglets_oeuvre', $fiche, $oeuvre ) ) ) );
		} finally {
			$GLOBALS['wp_query'] = $sauve; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_set_current_user( 0 );
		}
		// Hors de la page du glossaire : aucun filtre n'agit.
		yume_assert_same( array( 'title' => 'x' ), \Yume\Core\Glossaire\titre_document( array( 'title' => 'x' ) ) );
		yume_assert_same( 'x', \Yume\Core\Glossaire\canonique( 'x', get_post( $oeuvre ) ) );
	}
);

yume_test(
	'Glossaire : gabarit du thème (en-tête d’œuvre, onglets, bloc) et bloc enregistré',
	function () {
		$gabarit = (string) file_get_contents( get_theme_root() . '/yume/templates/single-yume_oeuvre-glossaire.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		yume_assert_contains( '<!-- wp:yume/oeuvre-header /-->', $gabarit );
		yume_assert_contains( '<!-- wp:yume/oeuvre-onglets /-->', $gabarit );
		yume_assert_contains( '<!-- wp:yume/glossaire /-->', $gabarit );
		yume_assert_true( WP_Block_Type_Registry::get_instance()->is_registered( 'yume/glossaire' ) );
		yume_assert_same( '', yume_render_block( 'yume/glossaire' ), 'hors contexte : rien en façade' );
	}
);

yume_test(
	'Glossaire : remplacement atomique (MariaDB : point de sauvegarde dans une transaction, annulation complète)',
	function () {
		$oeuvre = yume_tg_oeuvre();
		importer( $oeuvre, yume_tg_yaml( 3 ) );
		$mode = \Yume\Core\Glossaire\ouvrir_transaction();
		if ( \Yume\Core\Glossaire\est_sqlite() ) {
			yume_assert_same( 'aucun', $mode );
			return;
		}
		yume_assert_same( 'savepoint', $mode, 'déjà dans la transaction du test : point de sauvegarde' );
		$analyse = analyser_glossaire( yume_tg_yaml( 8 ) );
		yume_assert_true( \Yume\Core\Glossaire\remplacer_entrees( $oeuvre, $analyse['lignes'] ) );
		yume_assert_same( 8, yume_tg_compter( $oeuvre )['entrees'] );
		\Yume\Core\Glossaire\fermer_transaction( $mode, false );
		yume_assert_same( 3, yume_tg_compter( $oeuvre )['entrees'], 'annulé : anciennes entrées intactes' );
	}
);

yume_test(
	'Structure Yume-Trad récente : graphies_refusees (équipe seulement), genre « ? », termes_source vide, termes anglais en lang="en", terme VO identique au nom masqué',
	function () {
		$yaml    = <<<'YAML'
personnages:
- termes_source:
  - PROFESSEUR ESSAI
  - PROFESSEUR ESSAI NOM
  role: ''
  description: Professeur fictif de la fixture.
  provenance: terminologue
  preuve: 'graphie refusée : « ESSAI FLALROS »'
  confiance: hypothese
  graphies_refusees:
    en:
    - ESSAI FLALROS
  cibles:
    fr:
      nom: Essai Nom
      pluriel: ''
      genre: '?'
      variantes:
      - Professeur Essai
      interdits: []
      force: false
organisations:
- termes_source: []
  description: Organisation sans terme source.
  cibles:
    fr:
      nom: Organisation d'essai
creatures:
- termes_source:
  - SABREUR
  traduire: null
  cibles:
    fr:
      nom: Sabreur
YAML;
		$analyse = \Yume\Core\Glossaire\analyser_glossaire( $yaml );
		yume_assert_false( is_wp_error( $analyse ), is_wp_error( $analyse ) ? $analyse->get_error_message() : '' );
		yume_assert_same( 3, $analyse['entrees'] );

		$prof = \Yume\Core\Glossaire\normaliser_entree( \Yume\Core\Glossaire\Lecteur_Yaml::analyser( $yaml )['personnages'][0] );
		yume_assert_same( array( 'en' => array( 'ESSAI FLALROS' ) ), $prof['refusees'] );
		yume_assert_same( 'en', $prof['langue_source'] );
		yume_assert_same( '', $prof['genre'], 'genre « ? » = inconnu' );

		$entree   = array(
			'nom'     => $prof['nom'],
			'donnees' => $prof,
		);
		$visiteur = \Yume\Core\Glossaire\carte_entree( $entree, false, false );
		yume_assert_contains( '<span lang="en">PROFESSEUR ESSAI</span>', $visiteur );
		yume_assert_contains( 'hidden', $visiteur, 'notes masquées au chargement' );
		$equipe = \Yume\Core\Glossaire\carte_entree( $entree, true, true );
		yume_assert_contains( 'Graphies refusées (EN)', $equipe );
		yume_assert_contains( '<span lang="en">ESSAI FLALROS</span>', $equipe );
		yume_assert_not_contains( '?', wp_strip_all_tags( preg_replace( '/<dl.*<\/dl>/s', '', $visiteur ) ), 'aucun « ? » affiché comme genre' );

		$org = \Yume\Core\Glossaire\normaliser_entree( \Yume\Core\Glossaire\Lecteur_Yaml::analyser( $yaml )['organisations'][0] );
		yume_assert_same( 'Organisation d\'essai', $org['nom'] );
		yume_assert_same( array(), $org['termes_source'] );

		$sabreur = \Yume\Core\Glossaire\normaliser_entree( \Yume\Core\Glossaire\Lecteur_Yaml::analyser( $yaml )['creatures'][0] );
		$carte   = \Yume\Core\Glossaire\carte_entree(
			array(
				'nom'     => $sabreur['nom'],
				'donnees' => $sabreur,
			),
			false,
			false
		);
		yume_assert_not_contains( 'SABREUR', $carte, 'terme VO identique au nom (casse près) non répété' );
	}
);

yume_test(
	'Lecteur YAML en temps linéaire : chaînes entre guillemets et listes en ligne sur des milliers de lignes (pas de déni de service par le CPU)',
	function () {
		$cas = array(
			'guillemets'     => 't: "' . str_repeat( "abcdefghi\n", 40000 ) . "\"\n",
			'apostrophes'    => "t: '" . str_repeat( "it''s\n", 40000 ) . "'\n",
			'liste en ligne' => 't: [' . str_repeat( "  a,\n", 40000 ) . "  b]\n",
			'non fermé'      => 't: "' . str_repeat( "abcdefghi\n", 40000 ),
		);
		foreach ( $cas as $nom => $yaml ) {
			$debut = microtime( true );
			try {
				\Yume\Core\Glossaire\Lecteur_Yaml::analyser( $yaml );
			} catch ( \Yume\Core\Glossaire\Erreur_Yaml $e ) {
				yume_assert_same( 'non fermé', $nom, $e->getMessage() );
			}
			// Environ 400 Ko : quelques centièmes de seconde en temps linéaire, plusieurs
			// dizaines de secondes avec l'ancienne relecture depuis le début.
			yume_assert_true( microtime( true ) - $debut < 2.0, $nom . ' : ' . round( microtime( true ) - $debut, 2 ) . ' s' );
		}
		$r = \Yume\Core\Glossaire\Lecteur_Yaml::analyser( "t: [\"a\n  b\", 'c''d', {k: \"v # x\"}] # commentaire\n" );
		yume_assert_same( array( 't' => array( 'a b', "c'd", array( 'k' => 'v # x' ) ) ), $r );
	}
);
