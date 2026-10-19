<?php
/**
 * Statistiques, exports CSV par admin-post.php?action=yume_statistiques_export et nonce.
 *
 * Deux types : « oeuvres », le classement des œuvres (capacité yume_voir_statistiques ;
 * paramètres periode, tri) ; « lecteurs », la liste nominative des favoris ou des suiveurs d'une
 * œuvre (capacité yume_voir_lecteurs ; paramètres oeuvre, liste, q), journalisée
 * (journaliser_export()).
 *
 * Fichiers UTF-8 avec BOM (Excel), séparateur « ; », cellules de texte neutralisées contre
 * l'injection de formules (Planning\cellule_csv()). L'export nominatif contient exactement les
 * champs de la liste à l'écran (pseudo, date d'ajout, alertes, équipe) : ni e-mail, ni
 * identifiant de connexion.
 *
 * Refus : 401 en anonyme (admin_post_nopriv_*), 403 sans la capacité ou avec un nonce expiré,
 * 404 œuvre introuvable, 400 type inconnu, 503 classement indisponible. traiter_export() fait
 * tout le travail sans rien envoyer (testable) ; admin_post_export() n'ajoute que les en-têtes.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Action admin-post.php des exports CSV. */
const ACTION_EXPORT = 'yume_statistiques_export';

/** Action du nonce des exports CSV. */
const NONCE_EXPORT = 'yume_statistiques_export';

/** Paramètres repris dans l'adresse d'export, par type. */
const PARAMETRES_EXPORT = array(
	'oeuvres'  => array( 'periode', 'tri' ),
	'lecteurs' => array( 'oeuvre', 'liste', 'q' ),
);

/** Garde-fou : nombre maximal de pages lues pour un export (boucle de pagination). */
const EXPORT_PAGES_MAX = 1000;

/*
 * -----------------------------------------------------------------------------
 * Adresse
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse d'un export CSV (admin-post.php, action ACTION_EXPORT, nonce NONCE_EXPORT de
 * l'utilisateur courant). Seuls les paramètres du type sont repris (oeuvres : periode, tri ;
 * lecteurs : oeuvre, liste, q). L'adresse est celle de wp_nonce_url() : « & » y est encodé en
 * « &amp; », prête pour esc_url() dans un attribut href.
 *
 * @param string $type oeuvres ou lecteurs.
 * @param array  $args Paramètres.
 */
function url_export( string $type, array $args = array() ): string {
	$type   = isset( PARAMETRES_EXPORT[ $type ] ) ? $type : 'oeuvres';
	$params = array(
		'action' => ACTION_EXPORT,
		'type'   => $type,
	);
	foreach ( PARAMETRES_EXPORT[ $type ] as $cle ) {
		if ( isset( $args[ $cle ] ) && is_scalar( $args[ $cle ] ) && '' !== (string) $args[ $cle ] ) {
			$params[ $cle ] = (string) $args[ $cle ];
		}
	}
	return wp_nonce_url( add_query_arg( array_map( 'rawurlencode', $params ), admin_url( 'admin-post.php' ) ), NONCE_EXPORT );
}

/*
 * -----------------------------------------------------------------------------
 * Fichiers CSV
 * -----------------------------------------------------------------------------
 */

/**
 * Cellule CSV : les nombres tels quels, le texte neutralisé contre l'injection de formules
 * (Planning\cellule_csv() : apostrophe devant = + - @, tabulation, retour chariot), null vide.
 *
 * @param mixed $valeur Valeur.
 */
function cellule_export( $valeur ): string {
	if ( is_int( $valeur ) || is_float( $valeur ) ) {
		return (string) $valeur;
	}
	if ( function_exists( '\\Yume\\Core\\Planning\\cellule_csv' ) ) {
		return \Yume\Core\Planning\cellule_csv( $valeur );
	}
	$texte = is_scalar( $valeur ) ? (string) $valeur : '';
	return '' !== $texte && false !== strpos( "=+-@\t\r", $texte[0] ) ? "'" . $texte : $texte;
}

/**
 * Fichier CSV (UTF-8 avec BOM, séparateur « ; ») : une ligne d'en-têtes puis les lignes.
 *
 * @param string[] $entetes En-têtes.
 * @param array[]  $lignes  Lignes (valeurs scalaires ou null).
 */
function fichier_csv( array $entetes, array $lignes ): string {
	$flux = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $flux ) {
		return '';
	}
	fputcsv( $flux, array_map( __NAMESPACE__ . '\\cellule_export', $entetes ), ';', '"', '' );
	foreach ( $lignes as $ligne ) {
		fputcsv( $flux, array_map( __NAMESPACE__ . '\\cellule_export', array_values( (array) $ligne ) ), ';', '"', '' );
	}
	rewind( $flux );
	$csv = (string) stream_get_contents( $flux );
	fclose( $flux ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return "\xEF\xBB\xBF" . $csv;
}

/**
 * Entier ou null (valeur absente ou non numérique).
 *
 * @param mixed $valeur Valeur.
 */
function entier_ou_null( $valeur ): ?int {
	return is_numeric( $valeur ) ? (int) $valeur : null;
}

/**
 * CSV du classement des œuvres (forme de classement() : { tri, total, page, pages, oeuvres }) :
 * rang, œuvre, favoris, suiveurs alertés, lecteurs, lecteurs actifs (30 j), vues, nouveaux favoris,
 * croissance des favoris et des vues, adresse. Une valeur inconnue (vues sans Jetpack, croissance
 * sans historique) donne une cellule vide.
 *
 * @param array $classement Classement.
 */
function csv_classement( array $classement ): string {
	$entetes = array(
		__( 'Rang', 'yume-core' ),
		__( 'Œuvre', 'yume-core' ),
		__( 'Favoris', 'yume-core' ),
		__( 'Suiveurs alertés', 'yume-core' ),
		__( 'Lecteurs', 'yume-core' ),
		__( 'Lecteurs actifs (30 j)', 'yume-core' ),
		__( 'Vues', 'yume-core' ),
		__( 'Nouveaux favoris', 'yume-core' ),
		__( 'Croissance des favoris', 'yume-core' ),
		__( 'Croissance des vues', 'yume-core' ),
		__( 'Adresse', 'yume-core' ),
	);
	$oeuvres = is_array( $classement['oeuvres'] ?? null ) ? array_values( $classement['oeuvres'] ) : array();
	$lignes  = array();
	foreach ( $oeuvres as $i => $o ) {
		$o        = (array) $o;
		$lignes[] = array(
			$i + 1,
			is_scalar( $o['titre'] ?? null ) ? (string) $o['titre'] : '',
			(int) ( $o['favoris'] ?? 0 ),
			(int) ( $o['suiveurs'] ?? 0 ),
			(int) ( $o['lecteurs'] ?? 0 ),
			(int) ( $o['lecteurs_30j'] ?? 0 ),
			entier_ou_null( $o['vues'] ?? null ),
			(int) ( $o['nouveaux_favoris'] ?? 0 ),
			entier_ou_null( $o['croissance_favoris'] ?? null ),
			entier_ou_null( $o['croissance_vues'] ?? null ),
			is_scalar( $o['url'] ?? null ) ? (string) $o['url'] : '',
		);
	}
	return fichier_csv( $entetes, $lignes );
}

/**
 * CSV d'une liste nominative (forme de lecteurs_oeuvre()) : pseudo, date d'ajout (heure du
 * site, « Y-m-d H:i »), alertes (libellé de la fréquence), équipe (« Oui » ou vide). Aucune autre
 * colonne.
 *
 * @param array $resultat Résultat de lecteurs_oeuvre().
 */
function csv_lecteurs( array $resultat ): string {
	$entetes  = array(
		__( 'Pseudo', 'yume-core' ),
		__( 'Depuis', 'yume-core' ),
		__( 'Alertes', 'yume-core' ),
		__( 'Équipe', 'yume-core' ),
	);
	$libelles = function_exists( '\\Yume\\Core\\Social\\libelles_frequences' ) ? \Yume\Core\Social\libelles_frequences() : array();
	$lecteurs = is_array( $resultat['lecteurs'] ?? null ) ? $resultat['lecteurs'] : array();
	$lignes   = array();
	$fuseau   = wp_timezone();
	foreach ( $lecteurs as $l ) {
		$l         = (array) $l;
		$frequence = is_scalar( $l['frequence'] ?? null ) ? (string) $l['frequence'] : '';
		$ts        = is_scalar( $l['depuis'] ?? null ) && '' !== (string) $l['depuis'] ? strtotime( (string) $l['depuis'] ) : false;
		$lignes[]  = array(
			is_scalar( $l['pseudo'] ?? null ) ? (string) $l['pseudo'] : '',
			false !== $ts ? ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $fuseau )->format( 'Y-m-d H:i' ) : '',
			$libelles[ $frequence ] ?? $frequence,
			! empty( $l['equipe'] ) ? __( 'Oui', 'yume-core' ) : '',
		);
	}
	return fichier_csv( $entetes, $lignes );
}

/*
 * -----------------------------------------------------------------------------
 * Traitement d'une demande d'export
 * -----------------------------------------------------------------------------
 */

/**
 * Paramètre GET texte (déséchappé, assaini), chaîne vide si absent ou non scalaire.
 *
 * @param array  $get Paramètres GET bruts ($_GET).
 * @param string $cle Clé.
 */
function parametre_export( array $get, string $cle ): string {
	return isset( $get[ $cle ] ) && is_scalar( $get[ $cle ] ) ? sanitize_text_field( wp_unslash( (string) $get[ $cle ] ) ) : '';
}

/**
 * Erreur d'export.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $statut  Statut HTTP.
 */
function erreur_export( string $code, string $message, int $statut ): \WP_Error {
	return new \WP_Error( $code, $message, array( 'status' => $statut ) );
}

/**
 * Classement complet des œuvres (toutes les pages de classement()).
 *
 * @param int    $periode Période en jours.
 * @param string $tri     Tri.
 * @return array|\WP_Error
 */
function classement_complet( int $periode, string $tri ) {
	if ( ! function_exists( __NAMESPACE__ . '\\classement' ) ) {
		return erreur_export( 'yume_statistiques_indisponible', __( 'Le classement des œuvres n’est pas encore disponible.', 'yume-core' ), 503 );
	}
	$oeuvres = array();
	$page    = 1;
	do {
		$resultat = (array) classement( $periode, $tri, OEUVRES_PAGE_MAX, $page );
		$lot      = is_array( $resultat['oeuvres'] ?? null ) ? array_values( $resultat['oeuvres'] ) : array();
		$oeuvres  = array_merge( $oeuvres, $lot );
		$pages    = (int) ( $resultat['pages'] ?? 1 );
		++$page;
	} while ( $lot && $page <= $pages && $page <= EXPORT_PAGES_MAX );
	return array(
		'tri'     => $tri,
		'total'   => count( $oeuvres ),
		'page'    => 1,
		'pages'   => 1,
		'oeuvres' => $oeuvres,
	);
}

/**
 * Liste nominative complète d'une œuvre (toutes les pages de lecteurs_oeuvre(), les plus
 * récents d'abord).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $type      favoris ou suiveurs.
 * @param string $q         Recherche sur le pseudo.
 */
function lecteurs_complets( int $oeuvre_id, string $type, string $q ): array {
	$args     = array(
		'type'   => $type,
		'q'      => $q,
		'page'   => 1,
		'limite' => LECTEURS_PAGE_MAX,
		'tri'    => 'date',
		'ordre'  => 'desc',
	);
	$resultat = lecteurs_oeuvre( $oeuvre_id, $args );
	$pages    = min( EXPORT_PAGES_MAX, (int) $resultat['pages'] );
	for ( $page = 2; $page <= $pages; $page++ ) {
		$args['page'] = $page;
		$suite        = lecteurs_oeuvre( $oeuvre_id, $args );
		if ( ! $suite['lecteurs'] ) {
			break;
		}
		$resultat['lecteurs'] = array_merge( $resultat['lecteurs'], $suite['lecteurs'] );
	}
	$resultat['page']   = 1;
	$resultat['pages']  = 1;
	$resultat['limite'] = max( 1, count( $resultat['lecteurs'] ) );
	return $resultat;
}

/**
 * Traite une demande d'export sans rien envoyer : contrôle du compte, de la capacité du type et
 * du nonce, puis construction du fichier (et journalisation de l'export nominatif).
 *
 * @param array $get     Paramètres GET bruts ($_GET : type, _wpnonce, periode, tri, oeuvre, liste, q).
 * @param int   $user_id Utilisateur qui exporte (l'utilisateur courant : le nonce lui est lié).
 * @return array{type:string,nom:string,csv:string,lignes:int}|\WP_Error
 */
function traiter_export( array $get, int $user_id ) {
	if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
		return erreur_export( 'yume_export_connexion', __( 'Connectez-vous pour exporter les statistiques.', 'yume-core' ), 401 );
	}
	$type = parametre_export( $get, 'type' );
	if ( ! isset( PARAMETRES_EXPORT[ $type ] ) ) {
		return erreur_export( 'yume_export_type', __( 'Type d’export inconnu.', 'yume-core' ), 400 );
	}
	$capacite = 'lecteurs' === $type ? CAPACITE_LECTEURS : CAPACITE_STATS;
	if ( ! user_can( $user_id, $capacite ) ) {
		return erreur_export(
			'yume_export_interdit',
			'lecteurs' === $type
				? __( 'L’export de la liste des lecteurs est réservé aux gérants et aux administrateurs.', 'yume-core' )
				: __( 'L’export des statistiques est réservé à l’équipe.', 'yume-core' ),
			403
		);
	}
	if ( ! wp_verify_nonce( parametre_export( $get, '_wpnonce' ), NONCE_EXPORT ) ) {
		return erreur_export( 'yume_export_expire', __( 'Le lien d’export a expiré : rechargez la page des statistiques puis réessayez.', 'yume-core' ), 403 );
	}
	$date = wp_date( 'Y-m-d', maintenant() );

	if ( 'oeuvres' === $type ) {
		$periode = periode_valide( parametre_export( $get, 'periode' ) );
		$tri     = parametre_export( $get, 'tri' );
		$tri     = in_array( $tri, TRIS_OEUVRES, true ) ? $tri : 'favoris';
		$complet = classement_complet( $periode, $tri );
		if ( is_wp_error( $complet ) ) {
			return $complet;
		}
		return array(
			'type'   => 'oeuvres',
			'nom'    => sanitize_file_name( 'statistiques-oeuvres-' . $tri . '-' . $periode . 'j-' . $date . '.csv' ),
			'csv'    => csv_classement( $complet ),
			'lignes' => count( $complet['oeuvres'] ),
		);
	}

	$oeuvre_id = absint( parametre_export( $get, 'oeuvre' ) );
	if ( ! $oeuvre_id || 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return erreur_export( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), 404 );
	}
	$liste    = 'suiveurs' === parametre_export( $get, 'liste' ) ? 'suiveurs' : 'favoris';
	$resultat = lecteurs_complets( $oeuvre_id, $liste, parametre_export( $get, 'q' ) );
	$nb       = count( $resultat['lecteurs'] );
	journaliser_export( $user_id, $oeuvre_id, $liste, $nb );
	$slug = sanitize_title( (string) get_post_field( 'post_name', $oeuvre_id ) );
	return array(
		'type'   => 'lecteurs',
		'nom'    => sanitize_file_name( 'lecteurs-' . $liste . '-' . ( '' !== $slug ? $slug : 'oeuvre-' . $oeuvre_id ) . '-' . $date . '.csv' ),
		'csv'    => csv_lecteurs( $resultat ),
		'lignes' => $nb,
	);
}

/*
 * -----------------------------------------------------------------------------
 * admin-post.php
 * -----------------------------------------------------------------------------
 */

/**
 * Export CSV (admin-post.php?action=yume_statistiques_export) : fichier téléchargé, jamais mis
 * en cache ; page d'erreur avec le statut HTTP du refus sinon.
 */
function admin_post_export(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paramètres bruts, nonce vérifié et valeurs assainies par traiter_export().
	$export = traiter_export( (array) $_GET, get_current_user_id() );
	if ( is_wp_error( $export ) ) {
		$donnees = $export->get_error_data();
		wp_die(
			esc_html( $export->get_error_message() ),
			esc_html__( 'Export impossible', 'yume-core' ),
			array( 'response' => (int) ( is_array( $donnees ) ? ( $donnees['status'] ?? 403 ) : 403 ) )
		);
	}
	nocache_headers();
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $export['nom'] . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $export['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichier CSV, cellules neutralisées par cellule_export().
	exit;
}
add_action( 'admin_post_' . ACTION_EXPORT, __NAMESPACE__ . '\\admin_post_export' );

/**
 * Export demandé sans être connecté : 401.
 */
function admin_post_export_anonyme(): void {
	wp_die(
		esc_html__( 'Connectez-vous pour exporter les statistiques.', 'yume-core' ),
		esc_html__( 'Export impossible', 'yume-core' ),
		array( 'response' => 401 )
	);
}
add_action( 'admin_post_nopriv_' . ACTION_EXPORT, __NAMESPACE__ . '\\admin_post_export_anonyme' );
