<?php
/**
 * Tests du module lecteurs : tables favoris et notes, API §7, caches des œuvres, routes REST
 * /moi/* (droits, validation, RGPD, suppression du compte), outils de confidentialité,
 * alertes e-mail (sorties, récapitulatif, réponses), comptes (barre d'administration,
 * wp-admin, redirection après connexion), formulaires en façade et blocs selon la connexion.
 *
 * Lancement : tools/localenv/test.sh social
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Social\abonnes;
use function Yume\Core\Social\action_favori;
use function Yume\Core\Social\ajouter_favori;
use function Yume\Core\Social\alerter_sortie;
use function Yume\Core\Social\barre_administration;
use function Yume\Core\Social\confirmer_email;
use function Yume\Core\Social\definir_frequence;
use function Yume\Core\Social\enregistrer_preferences;
use function Yume\Core\Social\envoyer_recap;
use function Yume\Core\Social\est_lecteur;
use function Yume\Core\Social\jeton_formulaire;
use function Yume\Core\Social\noter;
use function Yume\Core\Social\peut_supprimer_compte;
use function Yume\Core\Social\preferences_alertes;
use function Yume\Core\Social\redirection_connexion;
use function Yume\Core\Social\rediriger_administration;
use function Yume\Core\Social\traiter_inscription;
use function Yume\Core\Social\traiter_profil;
use function Yume\Core\Social\traiter_suppression;
use function Yume\Core\Social\url_compte;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_ts_)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute $rappel et renvoie l'URL de redirection demandée (chaîne vide si aucune).
 *
 * @param callable $rappel Fonction.
 * @throws RuntimeException Toute exception autre que la redirection interceptée.
 */
function yume_ts_redirection( callable $rappel ): string {
	// Redirection interceptée par une exception (évite l'exit des gestionnaires de formulaires).
	$filtre = static function ( $url ) {
		throw new RuntimeException( (string) $url, 302 );
	};
	// Priorité 1 : avant le gestionnaire de WP-CLI, qui afficherait un avertissement.
	add_filter( 'wp_redirect', $filtre, 1 );
	try {
		$rappel();
	} catch ( RuntimeException $e ) {
		if ( 302 !== $e->getCode() ) {
			throw $e;
		}
		return $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filtre, 1 );
	}
	return '';
}

/**
 * Exécute $rappel et renvoie les e-mails envoyés (wp_mail) ou mis en file (yume_queue_email).
 *
 * @param callable $rappel Fonction.
 * @return array<int,array{to:string,subject:string,message:string}>
 */
function yume_ts_emails( callable $rappel ): array {
	global $wpdb;
	$envois = array();
	$filtre = static function ( $retour, $atts ) use ( &$envois ) {
		foreach ( (array) $atts['to'] as $destinataire ) {
			$envois[] = array(
				'to'      => (string) $destinataire,
				'subject' => (string) $atts['subject'],
				'message' => (string) $atts['message'],
			);
		}
		return true;
	};
	add_filter( 'pre_wp_mail', $filtre, 5, 2 );
	$file  = function_exists( 'yume_queue_email' );
	$table = $wpdb->prefix . 'yume_notifications';
	$avant = $file ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" ) : 0; // phpcs:ignore WordPress.DB
	try {
		$rappel();
	} finally {
		remove_filter( 'pre_wp_mail', $filtre, 5 );
	}
	if ( $file ) {
		$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT destinataire, sujet, html FROM {$table} WHERE id > %d ORDER BY id", $avant ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $lignes as $ligne ) {
			$envois[] = array(
				'to'      => (string) $ligne['destinataire'],
				'subject' => (string) $ligne['sujet'],
				'message' => (string) $ligne['html'],
			);
		}
	}
	return $envois;
}

/**
 * Destinataires d'une liste d'envois.
 *
 * @param array $envois Envois.
 * @return string[]
 */
function yume_ts_destinataires( array $envois ): array {
	$liste = array_values( array_unique( wp_list_pluck( $envois, 'to' ) ) );
	sort( $liste );
	return $liste;
}

/**
 * Œuvre publiée avec un tome et des chapitres publiés (sans événement de sortie).
 *
 * @param int $nb Nombre de chapitres.
 * @return array{oeuvre:int,tome:int,chapitres:int[]}
 */
function yume_ts_oeuvre( int $nb = 2 ): array {
	add_filter( 'yume_core_notifier', '__return_false' );
	$oeuvre = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Œuvre ' . wp_rand( 1, 999999 ),
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'  => 'yume_tome',
			'post_title' => 'Tome 3',
			'post_name'  => 'tome-3',
			'meta_input' => array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => 3,
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
				'menu_order'   => $i,
				'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'yume_tome_id' => $tome,
					'yume_numero'  => $i,
					'yume_nature'  => 'chapitre',
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
 * Membre avec un mot de passe connu.
 *
 * @param string $role Rôle.
 * @param string $mdp  Mot de passe.
 */
function yume_ts_membre( string $role = 'subscriber', string $mdp = 'secret-123' ): int {
	$id = yume_factory_user( $role );
	wp_set_password( $mdp, $id );
	clean_user_cache( $id );
	return $id;
}

/**
 * Place la requête principale sur un contenu le temps de $rappel.
 *
 * @param int      $post_id Contenu.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_ts_sur( int $post_id, callable $rappel ) {
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
 * Remplace $_POST le temps de $rappel.
 *
 * @param array    $donnees Champs.
 * @param callable $rappel  Fonction.
 * @return mixed
 */
function yume_ts_post( array $donnees, callable $rappel ) {
	$avant = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$_POST = wp_slash( $donnees );
	try {
		return $rappel();
	} finally {
		$_POST = $avant;
	}
}

/**
 * Enregistre des pages compte et connexion (option yume_pages), comme après la migration.
 *
 * @return array{compte:int,connexion:int}
 */
function yume_ts_pages(): array {
	$pages = array();
	foreach ( array( 'compte', 'connexion' ) as $cle ) {
		$pages[ $cle ] = yume_factory_post(
			array(
				'post_type'    => 'page',
				'post_title'   => ucfirst( $cle ),
				'post_name'    => $cle . '-' . wp_rand( 1, 99999 ),
				'post_content' => '<!-- wp:yume/account /-->',
			)
		);
	}
	update_option( 'yume_pages', $pages );
	return $pages;
}

/**
 * Jeton d'inscription daté de $age secondes.
 *
 * @param int $age Âge.
 */
function yume_ts_jeton( int $age ): string {
	$t = (string) ( time() - $age );
	return $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 );
}

/*
 * -----------------------------------------------------------------------------
 * Tables, API et caches
 * -----------------------------------------------------------------------------
 */

yume_test(
	'tables favoris et notes installées (schéma du contrat §13)',
	function () {
		global $wpdb;
		yume_assert_same( '1', get_option( 'yume_social_schema' ) );
		$s = yume_ts_oeuvre( 1 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		noter( $u, $s['oeuvre'], 4 );
		$favori = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}yume_favoris WHERE user_id = %d", $u ), ARRAY_A ); // phpcs:ignore WordPress.DB
		yume_assert_same( array( 'user_id', 'oeuvre_id', 'frequence', 'created_at' ), array_keys( (array) $favori ) );
		yume_assert_same( 'immediat', $favori['frequence'], 'fréquence par défaut' );
		$note = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}yume_notes WHERE user_id = %d", $u ), ARRAY_A ); // phpcs:ignore WordPress.DB
		yume_assert_same( array( 'user_id', 'oeuvre_id', 'note', 'updated_at' ), array_keys( (array) $note ) );
	}
);

yume_test(
	'API §7 : yume_is_favori, yume_get_note, yume_get_abonnes par fréquence',
	function () {
		$s  = yume_ts_oeuvre( 1 );
		$u1 = yume_factory_user();
		$u2 = yume_factory_user();
		$u3 = yume_factory_user();
		yume_assert_false( yume_is_favori( $u1, $s['oeuvre'] ) );
		yume_assert_same( 0, yume_get_note( $u1, $s['oeuvre'] ) );
		ajouter_favori( $u1, $s['oeuvre'] );
		ajouter_favori( $u2, $s['oeuvre'] );
		ajouter_favori( $u3, $s['oeuvre'] );
		definir_frequence( $u2, $s['oeuvre'], 'hebdo' );
		definir_frequence( $u3, $s['oeuvre'], 'jamais' );
		noter( $u1, $s['oeuvre'], 5 );
		yume_assert_true( yume_is_favori( $u1, $s['oeuvre'] ) );
		yume_assert_same( 5, yume_get_note( $u1, $s['oeuvre'] ) );
		yume_assert_same( array( $u1 ), yume_get_abonnes( $s['oeuvre'] ) );
		yume_assert_same( array( $u2 ), yume_get_abonnes( $s['oeuvre'], 'hebdo' ) );
		yume_assert_same( array( $u3 ), yume_get_abonnes( $s['oeuvre'], 'jamais' ) );
		yume_assert_same( array(), yume_get_abonnes( $s['oeuvre'], 'autre' ) );
	}
);

yume_test(
	'caches de l’œuvre : yume_nb_favoris, yume_note_moyenne, yume_nb_notes',
	function () {
		$s  = yume_ts_oeuvre( 1 );
		$o  = $s['oeuvre'];
		$u1 = yume_factory_user();
		$u2 = yume_factory_user();
		ajouter_favori( $u1, $o );
		ajouter_favori( $u1, $o );
		ajouter_favori( $u2, $o );
		yume_assert_same( '2', (string) get_post_meta( $o, 'yume_nb_favoris', true ), 'pas de doublon' );
		noter( $u1, $o, 5 );
		noter( $u2, $o, 4 );
		yume_assert_same( 4.5, (float) get_post_meta( $o, 'yume_note_moyenne', true ) );
		yume_assert_same( '2', (string) get_post_meta( $o, 'yume_nb_notes', true ) );
		noter( $u2, $o, 0 );
		yume_assert_same( 5.0, (float) get_post_meta( $o, 'yume_note_moyenne', true ) );
		yume_assert_same( '1', (string) get_post_meta( $o, 'yume_nb_notes', true ) );
		\Yume\Core\Social\retirer_favori( $u1, $o );
		yume_assert_same( '1', (string) get_post_meta( $o, 'yume_nb_favoris', true ) );
		// Les caches restent en lecture seule par /wp/v2 (contrat §4).
		$r = yume_rest(
			'POST',
			'/wp/v2/oeuvres/' . $o,
			array( 'meta' => array( 'yume_nb_favoris' => 999 ) ),
			yume_factory_user( 'administrator' )
		);
		yume_assert_same( '1', (string) get_post_meta( $o, 'yume_nb_favoris', true ), 'statut ' . $r->get_status() );
	}
);

yume_test(
	'favoris et notes refusés pour une œuvre non publiée',
	function () {
		$u         = yume_factory_user();
		$brouillon = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_status' => 'draft',
			)
		);
		yume_assert_true( is_wp_error( ajouter_favori( $u, $brouillon ) ) );
		yume_assert_true( is_wp_error( noter( $u, $brouillon, 3 ) ) );
		yume_assert_true( is_wp_error( noter( $u, $brouillon, 9 ) ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * REST /moi/*
 * -----------------------------------------------------------------------------
 */

yume_test(
	'REST /moi/* : connexion obligatoire (401)',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$o = $s['oeuvre'];
		foreach (
			array(
				array( 'GET', '/yume/v1/moi', array() ),
				array( 'POST', '/yume/v1/moi/favoris/' . $o, array() ),
				array( 'DELETE', '/yume/v1/moi/favoris/' . $o, array() ),
				array( 'PUT', '/yume/v1/moi/notes/' . $o, array( 'note' => 3 ) ),
				array( 'PUT', '/yume/v1/moi/alertes/' . $o, array( 'frequence' => 'hebdo' ) ),
				array( 'GET', '/yume/v1/moi/export', array() ),
				array(
					'DELETE',
					'/yume/v1/moi',
					array(
						'confirmation' => 'SUPPRIMER',
						'mot_de_passe' => 'x',
					),
				),
			) as $appel
		) {
			yume_assert_same( 401, yume_rest( $appel[0], $appel[1], $appel[2] )->get_status(), $appel[0] . ' ' . $appel[1] );
		}
	}
);

yume_test(
	'REST favoris : ajout (201 puis 200), retrait, compteur, œuvre introuvable',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$o = $s['oeuvre'];
		$u = yume_factory_user();
		$r = yume_rest( 'POST', '/yume/v1/moi/favoris/' . $o, array(), $u );
		yume_assert_same( 201, $r->get_status() );
		yume_assert_true( $r->get_data()['favori'] );
		yume_assert_same( 'immediat', $r->get_data()['frequence'] );
		yume_assert_same( 1, $r->get_data()['nb_favoris'] );
		yume_assert_same( 200, yume_rest( 'POST', '/yume/v1/moi/favoris/' . $o, array(), $u )->get_status(), 'idempotent' );
		$r = yume_rest( 'DELETE', '/yume/v1/moi/favoris/' . $o, array(), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_false( $r->get_data()['favori'] );
		yume_assert_same( 0, $r->get_data()['nb_favoris'] );
		yume_assert_same( 404, yume_rest( 'POST', '/yume/v1/moi/favoris/999999', array(), $u )->get_status() );
	}
);

yume_test(
	'REST notes : 1 à 5, 0 retire, hors bornes refusé',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$o = $s['oeuvre'];
		$u = yume_factory_user();
		$r = yume_rest( 'PUT', '/yume/v1/moi/notes/' . $o, array( 'note' => 4 ), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 4, $r->get_data()['note'] );
		yume_assert_same( 4.0, (float) $r->get_data()['moyenne'] );
		yume_assert_same( 1, $r->get_data()['nb_notes'] );
		yume_assert_same( 400, yume_rest( 'PUT', '/yume/v1/moi/notes/' . $o, array( 'note' => 6 ), $u )->get_status() );
		yume_assert_same( 400, yume_rest( 'PUT', '/yume/v1/moi/notes/' . $o, array( 'note' => -1 ), $u )->get_status() );
		yume_assert_same( 400, yume_rest( 'PUT', '/yume/v1/moi/notes/' . $o, array(), $u )->get_status() );
		$r = yume_rest( 'PUT', '/yume/v1/moi/notes/' . $o, array( 'note' => 0 ), $u );
		yume_assert_same( 0, $r->get_data()['note'] );
		yume_assert_same( 0, $r->get_data()['nb_notes'] );
	}
);

yume_test(
	'REST alertes : favori requis (409), fréquences valides seulement',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$o = $s['oeuvre'];
		$u = yume_factory_user();
		$r = yume_rest( 'PUT', '/yume/v1/moi/alertes/' . $o, array( 'frequence' => 'hebdo' ), $u );
		yume_assert_same( 409, $r->get_status() );
		ajouter_favori( $u, $o );
		$r = yume_rest( 'PUT', '/yume/v1/moi/alertes/' . $o, array( 'frequence' => 'hebdo' ), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 'hebdo', $r->get_data()['frequence'] );
		yume_assert_same( 400, yume_rest( 'PUT', '/yume/v1/moi/alertes/' . $o, array( 'frequence' => 'quotidien' ), $u )->get_status() );
		yume_assert_same( array( $u ), abonnes( $o, 'hebdo' ) );
	}
);

yume_test(
	'REST GET /moi : résumé du compte',
	function () {
		$s = yume_ts_oeuvre( 2 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		noter( $u, $s['oeuvre'], 3 );
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][1], 2, 40 );
		$d = yume_rest( 'GET', '/yume/v1/moi', array(), $u )->get_data();
		yume_assert_same( $u, $d['id'] );
		yume_assert_same( 1, count( $d['favoris'] ) );
		yume_assert_same( $s['oeuvre'], $d['favoris'][0]['oeuvre'] );
		yume_assert_same( 3, $d['notes'][0]['note'] );
		yume_assert_same( $s['chapitres'][1], $d['lecture'][0]['chapitre_id'] );
		yume_assert_same( 18, $d['reglages']['size'] );
		yume_assert_same( preferences_alertes( $u ), $d['alertes'] );
		yume_assert_false( $d['equipe'] );
		yume_assert_true( $d['suppression'] );
		yume_assert_contains( '/moi/export', $d['liens']['export'] );
	}
);

yume_test(
	'REST GET /moi/export : export JSON complet (RGPD) en pièce jointe',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		noter( $u, $s['oeuvre'], 5 );
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][0], 1, 10 );
		update_user_meta( $u, 'yume_reglages', array( 'size' => 20 ) );
		wp_insert_comment(
			array(
				'comment_post_ID'  => $s['chapitres'][0],
				'user_id'          => $u,
				'comment_content'  => 'Merci pour la traduction',
				'comment_approved' => 1,
			)
		);
		$r = yume_rest( 'GET', '/yume/v1/moi/export', array(), $u );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_contains( 'attachment; filename="yume-mes-donnees-', $r->get_headers()['Content-Disposition'] );
		$d = $r->get_data();
		yume_assert_same( get_userdata( $u )->user_email, $d['profil']['email'] );
		yume_assert_same( 1, count( $d['favoris'] ) );
		yume_assert_same( 5, $d['notes'][0]['note'] );
		yume_assert_same( 10, $d['progression'][0]['pourcentage'] );
		yume_assert_same( 20, $d['reglages_lecture']['size'] );
		yume_assert_same( 'Merci pour la traduction', $d['commentaires'][0]['contenu'] );
		yume_assert_true( isset( $d['preferences_alertes']['hebdo'] ) );
	}
);

yume_test(
	'REST DELETE /moi : confirmation et mot de passe obligatoires, jamais pour l’équipe',
	function () {
		add_filter( 'send_auth_cookies', '__return_false' );
		$s       = yume_ts_oeuvre( 1 );
		$lecteur = yume_ts_membre();
		$equipe  = yume_ts_membre( 'yume_traducteur' );
		$admin   = yume_ts_membre( 'administrator' );
		$editeur = yume_ts_membre( 'yume_editeur' );
		ajouter_favori( $lecteur, $s['oeuvre'] );
		noter( $lecteur, $s['oeuvre'], 2 );
		\Yume\Core\Reader\enregistrer_progression( $lecteur, $s['chapitres'][0] );
		$commentaire = wp_insert_comment(
			array(
				'comment_post_ID'      => $s['chapitres'][0],
				'user_id'              => $lecteur,
				'comment_author'       => 'Kaede',
				'comment_author_email' => get_userdata( $lecteur )->user_email,
				'comment_content'      => 'Bravo',
				'comment_approved'     => 1,
			)
		);

		$bon = array(
			'confirmation' => 'SUPPRIMER',
			'mot_de_passe' => 'secret-123',
		);
		foreach ( array( $equipe, $admin, $editeur ) as $interdit ) {
			yume_assert_same( 403, yume_rest( 'DELETE', '/yume/v1/moi', $bon, $interdit )->get_status() );
			yume_assert_true( (bool) get_userdata( $interdit ) );
		}
		yume_assert_same( 400, yume_rest( 'DELETE', '/yume/v1/moi', array( 'mot_de_passe' => 'secret-123' ), $lecteur )->get_status(), 'confirmation absente' );
		yume_assert_same( 400, yume_rest( 'DELETE', '/yume/v1/moi', array_merge( $bon, array( 'confirmation' => 'oui' ) ), $lecteur )->get_status() );
		yume_assert_same( 403, yume_rest( 'DELETE', '/yume/v1/moi', array_merge( $bon, array( 'mot_de_passe' => 'faux' ) ), $lecteur )->get_status() );
		yume_assert_true( (bool) get_userdata( $lecteur ) );

		$r = yume_rest( 'DELETE', '/yume/v1/moi', $bon, $lecteur );
		remove_filter( 'send_auth_cookies', '__return_false' );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_true( $r->get_data()['supprime'] );
		clean_user_cache( $lecteur );
		yume_assert_false( get_userdata( $lecteur ) );
		yume_assert_false( yume_is_favori( $lecteur, $s['oeuvre'] ) );
		yume_assert_same( array(), yume_get_progression( $lecteur ) );
		yume_assert_same( '0', (string) get_post_meta( $s['oeuvre'], 'yume_nb_favoris', true ), 'caches recalculés' );
		yume_assert_same( '0', (string) get_post_meta( $s['oeuvre'], 'yume_nb_notes', true ) );
		$c = get_comment( $commentaire );
		yume_assert_same( 'Bravo', $c->comment_content, 'commentaire conservé' );
		yume_assert_same( '', (string) $c->comment_author_email, 'commentaire anonymisé' );
		yume_assert_same( '0', (string) $c->user_id );
	}
);

yume_test(
	'outils de confidentialité : exportateur et effaceur déclarés et fonctionnels',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		noter( $u, $s['oeuvre'], 4 );
		enregistrer_preferences( $u, array( 'hebdo' => true ) );
		$email = get_userdata( $u )->user_email;
		$exp   = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$eff   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		yume_assert_true( isset( $exp['yume-core-lecteur'], $eff['yume-core-lecteur'] ) );
		$donnees = call_user_func( $exp['yume-core-lecteur']['callback'], $email, 1 );
		yume_assert_true( $donnees['done'] );
		$groupes = wp_list_pluck( $donnees['data'], 'group_id' );
		yume_assert_true( in_array( 'yume-favoris', $groupes, true ) );
		yume_assert_true( in_array( 'yume-notes', $groupes, true ) );
		$resultat = call_user_func( $eff['yume-core-lecteur']['callback'], $email, 1 );
		yume_assert_true( $resultat['items_removed'] );
		yume_assert_false( yume_is_favori( $u, $s['oeuvre'] ) );
		yume_assert_same( 0, yume_get_note( $u, $s['oeuvre'] ) );
		yume_assert_false( metadata_exists( 'user', $u, 'yume_alertes' ) );
		yume_assert_true( (bool) get_userdata( $u ), 'le compte reste' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Alertes
 * -----------------------------------------------------------------------------
 */

yume_test(
	'alertes : sortie d’un tome → abonnés « immédiat » seulement, une seule fois',
	function () {
		$s        = yume_ts_oeuvre( 2 );
		$immediat = yume_factory_user();
		$hebdo    = yume_factory_user();
		$jamais   = yume_factory_user();
		$muet     = yume_factory_user();
		foreach ( array( $immediat, $hebdo, $jamais, $muet ) as $u ) {
			ajouter_favori( $u, $s['oeuvre'] );
		}
		definir_frequence( $hebdo, $s['oeuvre'], 'hebdo' );
		definir_frequence( $jamais, $s['oeuvre'], 'jamais' );
		enregistrer_preferences( $muet, array( 'sorties' => false ) );

		$envois = yume_ts_emails(
			static function () use ( $s ) {
				do_action( 'yume_tome_publie', $s['tome'] );
			}
		);
		yume_assert_same( array( get_userdata( $immediat )->user_email ), yume_ts_destinataires( $envois ) );
		yume_assert_contains( 'Tome 3', $envois[0]['subject'] );
		yume_assert_contains( get_the_title( $s['oeuvre'] ), $envois[0]['subject'] );
		yume_assert_contains( esc_url( get_permalink( $s['chapitres'][0] ) ), $envois[0]['message'], 'lien de lecture' );
		yume_assert_contains( esc_url( url_compte() . '#yn-favoris' ), $envois[0]['message'], 'lien de gestion' );

		$encore = yume_ts_emails(
			static function () use ( $s ) {
				do_action( 'yume_tome_publie', $s['tome'] );
				alerter_sortie( $s['tome'] );
			}
		);
		yume_assert_same( array(), $encore, 'aucun doublon' );
	}
);

yume_test(
	'alertes : chapitre isolé, réglage emails_lecteurs désactivé',
	function () {
		$s = yume_ts_oeuvre( 2 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		$envois = yume_ts_emails(
			static function () use ( $s ) {
				do_action( 'yume_chapitre_publie', $s['chapitres'][1] );
			}
		);
		yume_assert_same( 1, count( $envois ) );
		yume_assert_contains( 'Nouveau chapitre', $envois[0]['subject'] );
		yume_assert_contains( 'Chapitre 2', $envois[0]['subject'] );

		$reglages                    = get_option( 'yume_reglages', array() );
		$reglages['emails_lecteurs'] = false;
		update_option( 'yume_reglages', $reglages );
		$envois = yume_ts_emails(
			static function () use ( $s ) {
				do_action( 'yume_chapitre_publie', $s['chapitres'][0] );
			}
		);
		yume_assert_same( array(), $envois );
	}
);

yume_test(
	'alertes : événement émis par core à la publication réelle d’un tome',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		$envois = yume_ts_emails(
			static function () use ( $s ) {
				yume_factory_post(
					array(
						'post_type'   => 'yume_tome',
						'post_title'  => 'Tome 4',
						'post_name'   => 'tome-4',
						'post_status' => 'publish',
						'meta_input'  => array(
							'yume_oeuvre_id' => $s['oeuvre'],
							'yume_numero'    => 4,
						),
					)
				);
			}
		);
		yume_assert_same( array( get_userdata( $u )->user_email ), yume_ts_destinataires( $envois ) );
	}
);

yume_test(
	'récapitulatif hebdomadaire : œuvres « hebdo », et toutes les suivies pour qui l’a activé',
	function () {
		$a        = yume_ts_oeuvre( 1 );
		$b        = yume_ts_oeuvre( 1 );
		$hebdo    = yume_factory_user();
		$immediat = yume_factory_user();
		$recap    = yume_factory_user();
		$jamais   = yume_factory_user();
		ajouter_favori( $hebdo, $a['oeuvre'], 'hebdo' );
		ajouter_favori( $immediat, $a['oeuvre'] );
		ajouter_favori( $recap, $a['oeuvre'] );
		ajouter_favori( $recap, $b['oeuvre'] );
		ajouter_favori( $jamais, $b['oeuvre'], 'jamais' );
		enregistrer_preferences( $recap, array( 'hebdo' => true ) );
		update_option( 'yume_social_recap_dernier', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) );
		// Deux sorties cette semaine (notées), une ancienne (hors période).
		add_post_meta( $a['tome'], '_yume_alerte_envoyee', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		add_post_meta( $b['chapitres'][0], '_yume_alerte_envoyee', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) );
		add_post_meta( $b['tome'], '_yume_alerte_envoyee', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$envois = yume_ts_emails( 'Yume\Core\Social\envoyer_recap' );
		yume_assert_same( yume_ts_destinataires( array( array( 'to' => get_userdata( $hebdo )->user_email ), array( 'to' => get_userdata( $recap )->user_email ) ) ), yume_ts_destinataires( $envois ) );
		foreach ( $envois as $envoi ) {
			if ( get_userdata( $recap )->user_email === $envoi['to'] ) {
				yume_assert_contains( esc_html( get_the_title( $a['oeuvre'] ) ), $envoi['message'] );
				yume_assert_contains( esc_html( get_the_title( $b['oeuvre'] ) ), $envoi['message'] );
			} else {
				yume_assert_not_contains( esc_html( get_the_title( $b['oeuvre'] ) ), $envoi['message'] );
			}
		}
		yume_assert_same( array(), yume_ts_emails( 'Yume\Core\Social\envoyer_recap' ), 'rien de neuf depuis le dernier envoi' );
		yume_assert_true( (bool) wp_next_scheduled( 'yume_social_recap_hebdo' ), 'tâche planifiée' );
		yume_assert_same( '0', wp_date( 'w', wp_next_scheduled( 'yume_social_recap_hebdo' ) ), 'le dimanche' );
	}
);

yume_test(
	'réponse à un commentaire : e-mail à l’auteur du parent selon sa préférence',
	function () {
		$s      = yume_ts_oeuvre( 1 );
		$auteur = yume_factory_user();
		$autre  = yume_factory_user();
		$parent = wp_insert_comment(
			array(
				'comment_post_ID'  => $s['chapitres'][0],
				'user_id'          => $auteur,
				'comment_content'  => 'Question ?',
				'comment_approved' => 1,
			)
		);
		$envois = yume_ts_emails(
			static function () use ( $s, $autre, $parent ) {
				$id = wp_insert_comment(
					array(
						'comment_post_ID'  => $s['chapitres'][0],
						'comment_parent'   => $parent,
						'user_id'          => $autre,
						'comment_author'   => 'Angeloids',
						'comment_content'  => 'Réponse détaillée',
						'comment_approved' => 0,
					)
				);
				wp_set_comment_status( $id, 'approve' );
			}
		);
		yume_assert_same( array( get_userdata( $auteur )->user_email ), yume_ts_destinataires( $envois ) );
		yume_assert_contains( 'Angeloids a répondu', $envois[0]['subject'] );
		yume_assert_contains( 'Réponse détaillée', $envois[0]['message'] );

		enregistrer_preferences( $auteur, array( 'commentaires' => false ) );
		$envois = yume_ts_emails(
			static function () use ( $s, $autre, $parent ) {
				$id = wp_insert_comment(
					array(
						'comment_post_ID'  => $s['chapitres'][0],
						'comment_parent'   => $parent,
						'user_id'          => $autre,
						'comment_content'  => 'Encore',
						'comment_approved' => 0,
					)
				);
				wp_set_comment_status( $id, 'approve' );
			}
		);
		yume_assert_same( array(), $envois );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Comptes lecteurs
 * -----------------------------------------------------------------------------
 */

yume_test(
	'lecteurs : barre d’administration masquée, profils équipe inchangés',
	function () {
		$lecteur = yume_factory_user();
		$equipe  = yume_factory_user( 'yume_traducteur' );
		wp_set_current_user( $lecteur );
		yume_assert_true( est_lecteur() );
		yume_assert_false( barre_administration( true ) );
		wp_set_current_user( $equipe );
		yume_assert_false( est_lecteur() );
		yume_assert_true( barre_administration( true ) );
		wp_set_current_user( 0 );
		yume_assert_true( peut_supprimer_compte( $lecteur ) );
		yume_assert_false( peut_supprimer_compte( $equipe ) );
	}
);

yume_test(
	'lecteurs : wp-admin redirigé vers le compte, sauf admin-post et admin-ajax',
	function () {
		global $pagenow;
		update_option( 'yume_pages', array() );
		wp_set_current_user( yume_factory_user() );
		$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_same( home_url( '/' ), yume_ts_redirection( 'Yume\Core\Social\rediriger_administration' ), 'pas de page compte : accueil' );
		yume_ts_pages();
		$avant   = $pagenow;
		$lecteur = yume_factory_user();
		wp_set_current_user( $lecteur );
		$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_same( url_compte(), yume_ts_redirection( 'Yume\Core\Social\rediriger_administration' ) );
		$pagenow = 'admin-post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_same( '', yume_ts_redirection( 'Yume\Core\Social\rediriger_administration' ) );
		wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
		$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		yume_assert_same( '', yume_ts_redirection( 'Yume\Core\Social\rediriger_administration' ) );
		$pagenow = $avant; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_set_current_user( 0 );
	}
);

yume_test(
	'connexion : retour à la page d’origine, sinon au compte (jamais wp-admin pour un lecteur)',
	function () {
		yume_ts_pages();
		$lecteur = get_userdata( yume_factory_user() );
		$editeur = get_userdata( yume_factory_user( 'yume_editeur' ) );
		$page    = home_url( '/lire/une-oeuvre/tome-1/2/' );
		yume_assert_same( url_compte(), redirection_connexion( admin_url(), '', $lecteur ) );
		yume_assert_same( url_compte(), redirection_connexion( admin_url(), admin_url( 'profile.php' ), $lecteur ) );
		yume_assert_same( $page, redirection_connexion( $page, $page, $lecteur ) );
		yume_assert_same( url_compte(), redirection_connexion( 'https://ailleurs.example/', 'https://ailleurs.example/', $lecteur ), 'hôte externe refusé' );
		yume_assert_same( admin_url(), redirection_connexion( admin_url(), '', $editeur ), 'équipe inchangée' );

		// Traducteur (sans accès à la rédaction) : espace équipe plutôt que le profil de wp-admin.
		$traducteur = get_userdata( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_same( admin_url( 'profile.php' ), redirection_connexion( admin_url( 'profile.php' ), '', $traducteur ), 'sans page équipe : défaut de WordPress' );
		$pages           = get_option( 'yume_pages' );
		$pages['equipe'] = yume_factory_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Équipe',
				'post_name'    => 'equipe-' . wp_rand( 1, 99999 ),
				'post_content' => '<!-- wp:yume/team-dashboard /-->',
			)
		);
		update_option( 'yume_pages', $pages );
		yume_assert_same( yume_url_page( 'equipe' ), redirection_connexion( admin_url( 'profile.php' ), '', $traducteur ) );
		yume_assert_same( yume_url_page( 'equipe' ), redirection_connexion( admin_url( 'profile.php' ), admin_url(), $traducteur ) );
		yume_assert_same( $page, redirection_connexion( $page, $page, $traducteur ), 'page demandée respectée' );
		yume_assert_same( admin_url(), redirection_connexion( admin_url(), '', $editeur ), 'éditeur : tableau de bord' );
	}
);

yume_test(
	'inscription en façade : compte subscriber, pot de miel, délai, limite par IP',
	function () {
		update_option( 'users_can_register', 1 );
		$ip_avant               = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde puis restauration telle quelle.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		$base                   = array(
			'action'      => 'yume_inscription',
			'_yn_nonce'   => wp_create_nonce( 'yume_inscription' ),
			'yn_jeton'    => yume_ts_jeton( 30 ),
			'yn_retour'   => home_url( '/connexion/' ),
			'yn_pseudo'   => 'lecteur' . wp_rand( 1000, 9999 ),
			'yn_email'    => 'nouveau' . wp_rand( 1000, 99999 ) . '@example.test',
			'yn_site_web' => '',
		);
		$url                    = yume_ts_post( $base, static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' ) );
		yume_assert_contains( 'yn-msg=inscription-ok', $url );
		$user = get_user_by( 'login', $base['yn_pseudo'] );
		yume_assert_true( $user instanceof WP_User );
		yume_assert_same( array( 'subscriber' ), array_values( $user->roles ) );

		$pot = yume_ts_post(
			array_merge(
				$base,
				array(
					'yn_pseudo'   => 'robot1',
					'yn_email'    => 'robot1@example.test',
					'yn_site_web' => 'http://spam.example',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' )
		);
		yume_assert_contains( 'inscription-refusee', $pot );
		yume_assert_false( get_user_by( 'login', 'robot1' ) );

		$vite = yume_ts_post(
			array_merge(
				$base,
				array(
					'yn_pseudo' => 'robot2',
					'yn_email'  => 'robot2@example.test',
					'yn_jeton'  => jeton_formulaire(),
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' )
		);
		yume_assert_contains( 'inscription-refusee', $vite, 'envoyé trop vite' );

		$pris = yume_ts_post( array_merge( $base, array( 'yn_email' => 'autre' . wp_rand( 1, 9999 ) . '@example.test' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' ) );
		yume_assert_contains( 'pseudo-pris', $pris );

		$email = yume_ts_post(
			array_merge(
				$base,
				array(
					'yn_pseudo' => 'robot5',
					'yn_email'  => 'pas-une-adresse',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' )
		);
		yume_assert_contains( 'email-invalide', $email );

		$limite = yume_ts_post(
			array_merge(
				$base,
				array(
					'yn_pseudo' => 'robot3',
					'yn_email'  => 'robot3@example.test',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' )
		);
		yume_assert_contains( 'trop-de-tentatives', $limite, '6e tentative de la même IP' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
		$nonce                  = yume_ts_post( array_merge( $base, array( '_yn_nonce' => 'faux' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' ) );
		yume_assert_contains( 'yn-msg=session', $nonce );
		update_option( 'users_can_register', 0 );
		$ferme = yume_ts_post( array_merge( $base, array( 'yn_pseudo' => 'robot4' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_inscription' ) );
		yume_assert_contains( 'inscriptions-fermees', $ferme );
		if ( null === $ip_avant ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $ip_avant;
		}
	}
);

yume_test(
	'profil et sécurité : pseudo, e-mail confirmé par lien, mot de passe actuel exigé',
	function () {
		add_filter( 'send_auth_cookies', '__return_false' );
		$u = yume_ts_membre();
		wp_set_current_user( $u );
		$base   = array(
			'_yn_nonce' => wp_create_nonce( 'yume_compte_profil' ),
			'yn_retour' => url_compte(),
			'yn_email'  => get_userdata( $u )->user_email,
		);
		$pseudo = 'Lectrice ' . wp_rand( 1000, 99999 );
		$url    = yume_ts_post( array_merge( $base, array( 'yn_pseudo' => $pseudo ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' ) );
		yume_assert_contains( 'pseudo-ok', $url );
		yume_assert_contains( '#yn-profil', $url );
		yume_assert_same( $pseudo, get_userdata( $u )->display_name );
		$autre = get_userdata( yume_factory_user() );
		$url   = yume_ts_post( array_merge( $base, array( 'yn_pseudo' => $autre->user_login ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' ) );
		yume_assert_contains( 'pseudo-pris', $url, 'identifiant d’un autre compte' );

		// Nouvelle adresse : refusée sans le mot de passe, puis en attente de confirmation.
		$url = yume_ts_post( array_merge( $base, array( 'yn_email' => 'neuve@example.test' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' ) );
		yume_assert_contains( 'mdp-actuel', $url );
		$envois = yume_ts_emails(
			static function () use ( $base ) {
				yume_ts_post(
					array_merge(
						$base,
						array(
							'yn_email'      => 'neuve@example.test',
							'yn_mdp_actuel' => 'secret-123',
						)
					),
					static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' )
				);
			}
		);
		yume_assert_same( array( 'neuve@example.test' ), yume_ts_destinataires( $envois ) );
		yume_assert_true( str_contains( get_userdata( $u )->user_email, 'example.test' ) && 'neuve@example.test' !== get_userdata( $u )->user_email, 'pas encore changée' );
		preg_match( '/yn-email=([A-Za-z0-9]+)/', $envois[0]['message'], $m );
		$_GET['yn-email'] = $m[1];
		$url              = yume_ts_redirection( 'Yume\Core\Social\confirmer_email' );
		unset( $_GET['yn-email'] );
		yume_assert_contains( 'email-ok', $url );
		clean_user_cache( $u );
		yume_assert_same( 'neuve@example.test', get_userdata( $u )->user_email );

		// Mot de passe : trop court, confirmation différente, puis accepté.
		$mdp = array(
			'yn_email'      => 'neuve@example.test',
			'yn_mdp_actuel' => 'secret-123',
		);
		$url = yume_ts_post(
			array_merge(
				$base,
				$mdp,
				array(
					'yn_mdp_nouveau'      => 'court',
					'yn_mdp_confirmation' => 'court',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' )
		);
		yume_assert_contains( 'mdp-court', $url );
		$url = yume_ts_post(
			array_merge(
				$base,
				$mdp,
				array(
					'yn_mdp_nouveau'      => 'nouveau-mdp-1',
					'yn_mdp_confirmation' => 'autre-mdp-2',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' )
		);
		yume_assert_contains( 'mdp-different', $url );
		$url = yume_ts_post(
			array_merge(
				$base,
				$mdp,
				array(
					'yn_mdp_nouveau'      => 'nouveau-mdp-1',
					'yn_mdp_confirmation' => 'nouveau-mdp-1',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_profil' )
		);
		yume_assert_contains( 'mdp-ok', $url );
		clean_user_cache( $u );
		yume_assert_true( wp_check_password( 'nouveau-mdp-1', get_userdata( $u )->user_pass, $u ) );
		wp_set_current_user( 0 );
		remove_filter( 'send_auth_cookies', '__return_false' );
	}
);

yume_test(
	'suppression du compte par formulaire : confirmation, mot de passe, équipe refusée',
	function () {
		add_filter( 'send_auth_cookies', '__return_false' );
		$u = yume_ts_membre();
		wp_set_current_user( $u );
		$base = array(
			'_yn_nonce'       => wp_create_nonce( 'yume_compte_supprimer' ),
			'yn_retour'       => url_compte(),
			'yn_confirmation' => 'supprimer',
			'yn_mdp'          => 'secret-123',
		);
		yume_assert_contains( 'suppression-confirmation', yume_ts_post( array_merge( $base, array( 'yn_confirmation' => 'non' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_suppression' ) ) );
		yume_assert_contains( 'mdp-actuel', yume_ts_post( array_merge( $base, array( 'yn_mdp' => 'faux' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_suppression' ) ) );
		$url = yume_ts_post( $base, static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_suppression' ) );
		yume_assert_contains( 'compte-supprime', $url );
		clean_user_cache( $u );
		yume_assert_false( get_userdata( $u ) );

		$equipe = yume_ts_membre( 'yume_relecteur' );
		wp_set_current_user( $equipe );
		$url = yume_ts_post( array_merge( $base, array( '_yn_nonce' => wp_create_nonce( 'yume_compte_supprimer' ) ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\traiter_suppression' ) );
		yume_assert_contains( 'suppression-interdite', $url );
		yume_assert_true( (bool) get_userdata( $equipe ) );
		wp_set_current_user( 0 );
		remove_filter( 'send_auth_cookies', '__return_false' );
	}
);

yume_test(
	'fiche œuvre sans JavaScript : favori par formulaire (nonce, retour à la fiche)',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$u = yume_factory_user();
		wp_set_current_user( $u );
		$base = array(
			'yn_oeuvre' => $s['oeuvre'],
			'yn_retour' => get_permalink( $s['oeuvre'] ),
			'_yn_nonce' => wp_create_nonce( 'yume_social_' . $s['oeuvre'] ),
		);
		$url  = yume_ts_post( array_merge( $base, array( 'yn_faire' => 'ajouter' ) ), static fn() => yume_ts_redirection( 'Yume\Core\Social\action_favori' ) );
		yume_assert_contains( 'favori-ajoute', $url );
		yume_assert_contains( '#yn-oeuvre-actions', $url );
		yume_assert_true( yume_is_favori( $u, $s['oeuvre'] ) );
		$url = yume_ts_post(
			array_merge(
				$base,
				array(
					'_yn_nonce' => 'x',
					'yn_faire'  => 'retirer',
				)
			),
			static fn() => yume_ts_redirection( 'Yume\Core\Social\action_favori' )
		);
		yume_assert_contains( 'yn-msg=session', $url );
		yume_assert_true( yume_is_favori( $u, $s['oeuvre'] ), 'nonce invalide : rien ne change' );
		wp_set_current_user( 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Blocs
 * -----------------------------------------------------------------------------
 */

yume_test(
	'bloc oeuvre-actions (visiteur) : moyenne, compteur, invitation à se connecter',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		noter( $u, $s['oeuvre'], 4 );
		$html = yume_ts_sur( $s['oeuvre'], static fn() => yume_render_block( 'yume/oeuvre-actions' ) );
		yume_assert_contains( 'class="yn-oeuvre-actions', $html );
		yume_assert_contains( 'Connectez-vous', $html );
		yume_assert_contains( '4,0', $html );
		yume_assert_contains( '(1 note)', $html );
		yume_assert_contains( 'data-yn-commencer', $html, 'premier chapitre' );
		yume_assert_contains( esc_url( get_permalink( $s['chapitres'][0] ) ), $html );
		yume_assert_contains( 'data-yn-reprendre hidden', $html, 'reprise visiteur par le script' );
		yume_assert_not_contains( '<form', $html );
		$page = yume_factory_post( array( 'post_type' => 'page' ) );
		yume_assert_same( '', trim( yume_ts_sur( $page, static fn() => yume_render_block( 'yume/oeuvre-actions' ) ) ), 'hors contexte' );
	}
);

yume_test(
	'bloc oeuvre-actions (membre) : formulaires avec nonce, étoiles en radios, alerte, reprise',
	function () {
		$s = yume_ts_oeuvre( 3 );
		$u = yume_factory_user();
		ajouter_favori( $u, $s['oeuvre'] );
		definir_frequence( $u, $s['oeuvre'], 'hebdo' );
		noter( $u, $s['oeuvre'], 3 );
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][2], 4, 60 );
		wp_set_current_user( $u );
		$html = yume_ts_sur( $s['oeuvre'], static fn() => yume_render_block( 'yume/oeuvre-actions' ) );
		wp_set_current_user( 0 );
		yume_assert_contains( 'Reprendre · Tome 3, chapitre 3', $html );
		yume_assert_contains( '#yn-p-5', $html );
		yume_assert_contains( 'aria-pressed="true"', $html );
		yume_assert_contains( 'name="_yn_nonce"', $html );
		yume_assert_same( 5, substr_count( $html, 'name="yn_note"' ) );
		yume_assert_contains( '<legend>Votre note</legend>', $html );
		yume_assert_contains( 'value="3" checked', $html );
		yume_assert_contains( 'Alerte : hebdomadaire', $html );
		yume_assert_contains( 'value="hebdo" checked', $html );
		yume_assert_not_contains( 'Connectez-vous', $html );
		yume_assert_contains( '"connecte":true', html_entity_decode( $html ) );
	}
);

yume_test(
	'bloc resume-reading : membre (serveur), visiteur (gabarit masqué), bandeau sans carte',
	function () {
		$s = yume_ts_oeuvre( 2 );
		$u = yume_factory_user();
		\Yume\Core\Reader\enregistrer_progression( $u, $s['chapitres'][1], 9, 41 );
		wp_set_current_user( $u );
		$bandeau = yume_render_block( 'yume/resume-reading', array( 'layout' => 'bandeau' ) );
		$carte   = yume_render_block( 'yume/resume-reading', array( 'layout' => 'carte' ) );
		wp_set_current_user( 0 );
		yume_assert_contains( 'yn-resume yn-resume--bandeau', $bandeau );
		yume_assert_not_contains( 'yn-card', $bandeau, 'le thème fournit le bandeau' );
		yume_assert_contains( '<p class="yn-label">Reprendre la lecture</p>', $bandeau );
		yume_assert_contains( 'Tome 3 · chapitre 2 · 41 %', $bandeau );
		yume_assert_contains( 'yn-btn yn-btn--primary', $bandeau );
		yume_assert_contains( 'Continuer', $bandeau );
		yume_assert_contains( '#yn-p-10', $bandeau );
		yume_assert_not_contains( ' hidden', $bandeau );
		yume_assert_contains( 'yn-card', $carte );
		yume_assert_contains( '--v:41%', $carte );

		$visiteur = yume_render_block( 'yume/resume-reading', array( 'layout' => 'bandeau' ) );
		yume_assert_contains( 'data-yn-resume="bandeau" hidden', $visiteur );
	}
);

yume_test(
	'bloc account (visiteur) : connexion, mot de passe oublié, inscription protégée',
	function () {
		// wp_login_form() lit l'hôte de la requête (absent en ligne de commande).
		$serveur                = $_SERVER;
		$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
		$_SERVER['REQUEST_URI'] = '/connexion/';
		update_option( 'users_can_register', 1 );
		$html = yume_render_block( 'yume/account' );
		yume_assert_contains( 'class="yn-account yn-account--acces', $html );
		yume_assert_contains( '<h2 class="yn-account__titre" id="yn-connexion-titre">Se connecter</h2>', $html );
		yume_assert_contains( 'id="yn-connexion"', $html, 'wp_login_form' );
		yume_assert_contains( 'name="yn_origine"', $html, 'retour en façade après un échec' );
		yume_assert_contains( 'id="yn-oubli"', $html );
		yume_assert_contains( 'value="yume_inscription"', $html );
		yume_assert_contains( 'name="yn_site_web"', $html, 'pot de miel' );
		yume_assert_contains( 'name="yn_jeton"', $html );
		yume_assert_not_contains( '<h1', $html );
		update_option( 'users_can_register', 0 );
		$ferme = yume_render_block( 'yume/account' );
		yume_assert_not_contains( 'value="yume_inscription"', $ferme );
		yume_assert_contains( 'Les inscriptions sont fermées', $ferme );
		$_SERVER = $serveur; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
);

yume_test(
	'bloc account (membre) : sept rubriques en h2, favoris, alertes, suppression selon le profil',
	function () {
		$s = yume_ts_oeuvre( 1 );
		$u = yume_ts_membre();
		ajouter_favori( $u, $s['oeuvre'] );
		wp_set_current_user( $u );
		$html = yume_render_block( 'yume/account' );
		wp_set_current_user( 0 );
		foreach ( array( 'yn-lecture', 'yn-favoris', 'yn-notes', 'yn-reglages', 'yn-alertes', 'yn-profil', 'yn-donnees' ) as $ancre ) {
			yume_assert_contains( 'id="' . $ancre . '"', $html );
			yume_assert_contains( 'href="#' . $ancre . '"', $html );
		}
		yume_assert_same( 7, substr_count( $html, '<h2 ' ) );
		yume_assert_not_contains( '<h1', $html );
		yume_assert_contains( 'Favoris et alertes (1)', $html );
		yume_assert_contains( 'data-yn-frequence', $html );
		yume_assert_contains( 'role="switch"', $html );
		yume_assert_contains( 'value="yume_compte_supprimer"', $html );
		yume_assert_contains( '/moi/export', $html );

		$equipe = yume_ts_membre( 'yume_traducteur' );
		wp_set_current_user( $equipe );
		$html = yume_render_block( 'yume/account' );
		wp_set_current_user( 0 );
		yume_assert_not_contains( 'value="yume_compte_supprimer"', $html );
		yume_assert_contains( 'ne peuvent pas être supprimés', $html );
	}
);

yume_test(
	'bloc auth-links : Connexion, Mon compte, Espace équipe selon la capacité',
	function () {
		$visiteur = yume_render_block( 'yume/auth-links' );
		yume_assert_contains( 'class="yn-auth', $visiteur );
		yume_assert_contains( '>Connexion<', $visiteur );
		yume_assert_not_contains( 'Mon compte', $visiteur );
		wp_set_current_user( yume_factory_user() );
		$lecteur = yume_render_block( 'yume/auth-links' );
		wp_set_current_user( yume_factory_user( 'yume_graphiste' ) );
		$equipe = yume_render_block( 'yume/auth-links' );
		wp_set_current_user( 0 );
		yume_assert_contains( 'Mon compte', $lecteur );
		yume_assert_contains( esc_url( url_compte() ), $lecteur );
		yume_assert_not_contains( 'Espace équipe', $lecteur );
		yume_assert_contains( 'Espace équipe', $equipe );
		yume_assert_contains( 'Mon compte', $equipe );
	}
);

yume_test(
	'fiche œuvre : progression du membre sur les lignes de tome (En cours · %, Reprendre #yn-p-N, lu ✓, Relire)',
	function () {
		if ( ! function_exists( '\Yume\Core\Reader\enregistrer_progression' ) || ! WP_Block_Type_Registry::get_instance()->is_registered( 'yume/tome-list' ) ) {
			return; // Modules lecture ou bibliothèque absents (YUME_ONLY_MODULES).
		}
		add_filter( 'yume_core_notifier', '__return_false' );
		try {
			$oeuvre = yume_factory_post(
				array(
					'post_type'  => 'yume_oeuvre',
					'post_title' => 'Progression ' . wp_rand( 1, 999999 ),
				)
			);
			$tomes  = array();
			$chaps  = array();
			foreach ( array( 1, 2, 3 ) as $n ) {
				$tomes[ $n ] = yume_factory_post(
					array(
						'post_type'  => 'yume_tome',
						'post_title' => 'Tome ' . $n,
						'post_name'  => 'tome-' . $n,
						'meta_input' => array(
							'yume_oeuvre_id' => $oeuvre,
							'yume_numero'    => $n,
							'yume_nature'    => 'tome',
						),
					)
				);
				foreach ( array( 1, 2, 3, 4 ) as $i ) {
					$chaps[ $n ][ $i ] = yume_factory_post(
						array(
							'post_type'    => 'yume_chapitre',
							'post_title'   => 'Chapitre ' . $i,
							'post_name'    => 'chapitre-' . $i,
							'menu_order'   => $i,
							'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
							'meta_input'   => array(
								'yume_tome_id' => $tomes[ $n ],
								'yume_numero'  => $i,
								'yume_nature'  => 'chapitre',
								'yume_nb_mots' => 1000,
							),
						)
					);
				}
			}
		} finally {
			remove_filter( 'yume_core_notifier', '__return_false' );
		}
		$rendu = static function () use ( $oeuvre ): string {
			wp_cache_flush();
			return (string) yume_ts_sur( $oeuvre, static fn() => yume_render_block( 'yume/tome-list' ) );
		};

		// Visiteur, puis membre sans position : lignes inchangées.
		$visiteur = $rendu();
		yume_assert_contains( 'Lire en ligne', $visiteur );
		yume_assert_not_contains( 'Reprendre', $visiteur );
		$membre = yume_ts_membre();
		wp_set_current_user( $membre );
		yume_assert_not_contains( 'yn-tome-list__ligne--', $rendu() );

		// Tome 2, chapitre 3, à mi-chapitre, paragraphe 11 (index 10) : 2,5 chapitres sur 4.
		\Yume\Core\Reader\enregistrer_progression( $membre, $chaps[2][3], 10, 50 );
		$html = $rendu();
		wp_set_current_user( 0 );
		yume_assert_same( 1, substr_count( $html, 'yn-tome-list__ligne--lecture' ), 'une seule ligne mise en avant' );
		yume_assert_contains( 'En cours · 63 %', $html, 'avancement du tome pondéré par les mots' );
		yume_assert_contains( 'vous en êtes au chapitre 3', $html );
		yume_assert_contains( esc_url( get_permalink( $chaps[2][3] ) . '#yn-p-11' ), $html, 'Reprendre vers le paragraphe retenu' );
		yume_assert_same( 1, substr_count( $html, '>Reprendre<' ) );
		yume_assert_same( 1, substr_count( $html, 'yn-tome-list__ligne--lu' ), 'tome 1 lu' );
		yume_assert_contains( '4 chapitres · lu ✓', $html );
		yume_assert_contains( esc_url( get_permalink( $chaps[1][1] ) ), $html, 'Relire : premier chapitre du tome 1' );
		yume_assert_same( 1, substr_count( $html, '>Relire<' ) );
		yume_assert_same( 1, substr_count( $html, '>Lire en ligne<' ), 'tome 3 inchangé' );
		yume_assert_true( strpos( $html, 'yn-tome-list__ligne--lecture' ) < strpos( $html, 'yn-tome-list__ligne--lu' ), 'du plus récent au plus ancien' );

		// Dernier chapitre du tome terminé : le tome passe en « lu ».
		\Yume\Core\Reader\enregistrer_progression( $membre, $chaps[2][4], 30, 100 );
		wp_set_current_user( $membre );
		$html = $rendu();
		wp_set_current_user( 0 );
		yume_assert_same( 2, substr_count( $html, 'yn-tome-list__ligne--lu' ) );
		yume_assert_not_contains( 'yn-tome-list__ligne--lecture', $html );

		// Un autre membre ne voit pas cette progression.
		wp_set_current_user( yume_ts_membre() );
		$autre = $rendu();
		wp_set_current_user( 0 );
		yume_assert_not_contains( 'lu ✓', $autre );
	}
);
