<?php
/**
 * Export CSV de la liste « Comptes inscrits » : admin-post.php?action=yume_comptes_export, mêmes
 * filtres que la liste (q, role, source, discord, activite, inscrits, sans_lecture, sans_mdp,
 * tri, ordre), nonce yume_comptes_export, capacité yume_voir_lecteurs (gérants et
 * administrateurs). Toutes les pages de la liste sont exportées (garde-fou EXPORT_PAGES_MAX).
 *
 * Colonnes : celles de la liste (pseudo, rôle, équipe, inscription, source, Discord, sans mot de
 * passe, dernière activité, dernière lecture, chapitres lus estimés, favoris, dont alertés,
 * commentaires), jamais l'e-mail ni l'identifiant de connexion. Option email=1 réservée aux
 * administrateurs (manage_options) : colonne « E-mail » ajoutée après le pseudo, export
 * journalisé « export_email » au lieu de « export ».
 *
 * Fichier UTF-8 avec BOM (Excel), séparateur « ; », cellules de texte neutralisées contre
 * l'injection de formules (Planning\cellule_csv()). Chaque export accepté est journalisé
 * (journaliser(), compte 0, nombre de lignes, filtres en contexte).
 *
 * Refus : 401 en anonyme (admin_post_nopriv_*) ou compte différent du compte courant, 403 sans
 * la capacité, nonce expiré ou e-mail demandé sans manage_options, 400 paramètres non
 * scalaires ou email invalide, 503 données de la liste indisponibles (lister_comptes() absente).
 * traiter_export() fait tout le travail sans rien envoyer (testable) ; admin_post_export()
 * n'ajoute que les en-têtes (nocache_headers()).
 *
 * Contrat : docs/06-contrat-technique.md §8 septies.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

defined( 'ABSPATH' ) || exit;

/** Action admin-post.php de l'export CSV. */
const ACTION_EXPORT = 'yume_comptes_export';

/** Action du nonce de l'export CSV. */
const NONCE_EXPORT = 'yume_comptes_export';

/** Garde-fou : pages de COMPTES_PAGE_MAX comptes lues au plus pour un export. */
const EXPORT_PAGES_MAX = 500;

/** Filtres de la liste repris par l'export (ni page ni limite : toutes les pages). */
const EXPORT_FILTRES = array( 'q', 'role', 'source', 'discord', 'activite', 'inscrits', 'sans_lecture', 'sans_mdp', 'tri', 'ordre' );

/*
 * -----------------------------------------------------------------------------
 * Adresse
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse de l'export CSV (admin-post.php, action ACTION_EXPORT, nonce NONCE_EXPORT du compte
 * courant). Les filtres de la liste (EXPORT_FILTRES) sont normalisés par args_comptes() et omis
 * quand ils valent leur défaut ; page et limite sont ignorées (toutes les pages). « email » vrai
 * ajoute email=1 (accepté pour les administrateurs seulement). L'adresse est celle de
 * wp_nonce_url() : « & » y est encodé en « &amp; », prête pour esc_url() dans un attribut href.
 *
 * @param array $args Filtres de la liste (+ email).
 */
function url_export( array $args ): string {
	$defauts = args_comptes( array() );
	$normal  = args_comptes( $args );
	$params  = array( 'action' => ACTION_EXPORT );
	foreach ( EXPORT_FILTRES as $cle ) {
		$valeur = $normal[ $cle ];
		$defaut = 'ordre' === $cle ? ordre_par_defaut( $normal['tri'] ) : $defauts[ $cle ];
		if ( $valeur === $defaut ) {
			continue;
		}
		$params[ $cle ] = is_bool( $valeur ) ? '1' : (string) $valeur;
	}
	if ( in_array( strtolower( parametre( $args, 'email' ) ), array( '1', 'on', 'true', 'oui', 'yes' ), true ) ) {
		$params['email'] = '1';
	}
	return wp_nonce_url( add_query_arg( array_map( 'rawurlencode', $params ), admin_url( 'admin-post.php' ) ), NONCE_EXPORT );
}

/*
 * -----------------------------------------------------------------------------
 * Fichier CSV
 * -----------------------------------------------------------------------------
 */

/**
 * Cellule CSV : les nombres tels quels, le texte neutralisé contre l'injection de formules
 * (Planning\cellule_csv() : apostrophe devant = + - @, tabulation, retour chariot), null vide.
 *
 * @param mixed $valeur Valeur.
 */
function export_cellule( $valeur ): string {
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
 * Jour 'Y-m-d' (heure du site) d'une date ISO 8601 ou SQL GMT ; '' si vide ou illisible.
 *
 * @param mixed $date Date.
 */
function export_jour( $date ): string {
	if ( ! is_scalar( $date ) || '' === (string) $date ) {
		return '';
	}
	$ts = strtotime( (string) $date . ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $date ) ? ' UTC' : '' ) );
	return false !== $ts && $ts > 0 ? (string) wp_date( 'Y-m-d', $ts ) : '';
}

/**
 * CSV de la liste des comptes (forme de lister_comptes() : {comptes: [...]}), UTF-8 avec BOM,
 * séparateur « ; ». Colonnes : Pseudo, [E-mail], Rôle, Équipe, Inscrit le, Source, Discord,
 * Sans mot de passe, Dernière activité, Dernière lecture, Chapitres lus (est.), Favoris, Dont
 * alertés, Commentaires. Dates au jour ('Y-m-d', heure du site). L'e-mail (lu sur le compte,
 * jamais dans le résultat) n'apparaît qu'avec $avec_email ; le contrôle du droit
 * (manage_options) revient à l'appelant (traiter_export()).
 *
 * @param array $resultat   Résultat de lister_comptes() (toutes les pages fusionnées).
 * @param bool  $avec_email Ajouter la colonne E-mail.
 */
function csv_comptes( array $resultat, bool $avec_email = false ): string {
	$entetes = array( __( 'Pseudo', 'yume-core' ) );
	if ( $avec_email ) {
		$entetes[] = __( 'E-mail', 'yume-core' );
	}
	$entetes = array_merge(
		$entetes,
		array(
			__( 'Rôle', 'yume-core' ),
			__( 'Équipe', 'yume-core' ),
			__( 'Inscrit le', 'yume-core' ),
			__( 'Source', 'yume-core' ),
			__( 'Discord', 'yume-core' ),
			__( 'Sans mot de passe', 'yume-core' ),
			__( 'Dernière activité', 'yume-core' ),
			__( 'Dernière lecture', 'yume-core' ),
			__( 'Chapitres lus (est.)', 'yume-core' ),
			__( 'Favoris', 'yume-core' ),
			__( 'Dont alertés', 'yume-core' ),
			__( 'Commentaires', 'yume-core' ),
		)
	);
	$sources = libelles_sources();
	$oui     = __( 'Oui', 'yume-core' );
	$comptes = is_array( $resultat['comptes'] ?? null ) ? $resultat['comptes'] : array();

	$flux = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $flux ) {
		return '';
	}
	fputcsv( $flux, array_map( __NAMESPACE__ . '\\export_cellule', $entetes ), ';', '"', '' );
	foreach ( $comptes as $c ) {
		$c      = (array) $c;
		$texte  = static function ( string $cle ) use ( $c ): string {
			return is_scalar( $c[ $cle ] ?? null ) ? (string) $c[ $cle ] : '';
		};
		$pseudo = wp_specialchars_decode( $texte( 'pseudo' ), ENT_QUOTES );
		$source = $texte( 'source' );
		$source = $sources[ $source ] ?? ( '' !== $source ? $source : $sources['inconnue'] );
		if ( ! empty( $c['source_deduite'] ) ) {
			/* translators: %s: source d'inscription (Formulaire du site, Discord…). */
			$source = sprintf( __( '%s (déduite)', 'yume-core' ), $source );
		}
		$ligne = array( $pseudo );
		if ( $avec_email ) {
			$compte  = get_userdata( (int) ( $c['id'] ?? 0 ) );
			$ligne[] = $compte instanceof \WP_User ? (string) $compte->user_email : '';
		}
		$activite = $texte( 'activite' );
		$ligne    = array_merge(
			$ligne,
			array(
				wp_specialchars_decode( $texte( 'role' ), ENT_QUOTES ),
				! empty( $c['equipe'] ) ? $oui : '',
				export_jour( $c['inscrit_le'] ?? '' ),
				$source,
				'' !== $texte( 'discord' ) ? $oui : '',
				! empty( $c['sans_mdp'] ) ? $oui : '',
				jour_valide( $activite ) ? $activite : '',
				export_jour( $c['derniere_lecture'] ?? '' ),
				(int) ( $c['chapitres_lus'] ?? 0 ),
				(int) ( $c['favoris'] ?? 0 ),
				(int) ( $c['suiveurs'] ?? 0 ),
				(int) ( $c['commentaires'] ?? 0 ),
			)
		);
		fputcsv( $flux, array_map( __NAMESPACE__ . '\\export_cellule', $ligne ), ';', '"', '' );
	}
	rewind( $flux );
	$csv = (string) stream_get_contents( $flux );
	fclose( $flux ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return "\xEF\xBB\xBF" . $csv;
}

/*
 * -----------------------------------------------------------------------------
 * Traitement d'une demande d'export
 * -----------------------------------------------------------------------------
 */

/**
 * Erreur d'export.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $statut  Statut HTTP.
 */
function export_erreur( string $code, string $message, int $statut ): \WP_Error {
	return new \WP_Error( $code, $message, array( 'status' => $statut ) );
}

/**
 * Tous les comptes correspondant aux filtres (toutes les pages de lister_comptes(), par
 * COMPTES_PAGE_MAX, EXPORT_PAGES_MAX pages au plus), ou null si les données sont indisponibles.
 * Passe par vue_donnees() : le filtre yume_comptes_vue_donnees peut fournir les pages (tests).
 *
 * @param array $args Paramètres normalisés (args_comptes()).
 * @return array{total:int,page:int,pages:int,limite:int,args:array,comptes:array}|null
 */
function export_comptes_complets( array $args ): ?array {
	$args['limite'] = COMPTES_PAGE_MAX;
	$comptes        = array();
	$total          = 0;
	$page           = 1;
	do {
		$args['page'] = $page;
		$resultat     = vue_donnees( 'lister_comptes', array( $args ) );
		if ( ! is_array( $resultat ) ) {
			if ( 1 === $page ) {
				return null;
			}
			break;
		}
		$lot     = is_array( $resultat['comptes'] ?? null ) ? array_values( $resultat['comptes'] ) : array();
		$comptes = array_merge( $comptes, $lot );
		$total   = max( $total, (int) ( $resultat['total'] ?? 0 ) );
		$pages   = (int) ( $resultat['pages'] ?? 1 );
		++$page;
	} while ( $lot && $page <= $pages && $page <= EXPORT_PAGES_MAX );
	unset( $args['page'], $args['limite'] );
	return array(
		'total'   => $total,
		'page'    => 1,
		'pages'   => 1,
		'limite'  => max( 1, count( $comptes ) ),
		'args'    => $args,
		'comptes' => $comptes,
	);
}

/**
 * Traite une demande d'export sans rien envoyer : contrôle du compte, de la capacité, du nonce et
 * du droit à l'e-mail, lecture de toutes les pages de la liste, construction du fichier et
 * journalisation (export ou export_email).
 *
 * @param array $get     Paramètres GET bruts ($_GET : _wpnonce, filtres de la liste, email).
 * @param int   $user_id Compte qui exporte (le compte courant : le nonce lui est lié).
 * @return array{nom:string,csv:string,lignes:int}|\WP_Error
 */
function traiter_export( array $get, int $user_id ) {
	if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
		return export_erreur( 'yume_comptes_export_connexion', __( 'Connectez-vous pour exporter la liste des comptes.', 'yume-core' ), 401 );
	}
	if ( ! peut_voir_comptes( $user_id ) ) {
		return export_erreur( 'yume_comptes_export_interdit', __( 'L’export de la liste des comptes est réservé aux gérants et aux administrateurs.', 'yume-core' ), 403 );
	}
	$nonce = isset( $get['_wpnonce'] ) && is_scalar( $get['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $get['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, NONCE_EXPORT ) ) {
		return export_erreur( 'yume_comptes_export_expire', __( 'Le lien d’export a expiré : rechargez la page des comptes inscrits puis réessayez.', 'yume-core' ), 403 );
	}

	$propres = array();
	foreach ( array_merge( EXPORT_FILTRES, array( 'email' ) ) as $cle ) {
		if ( ! array_key_exists( $cle, $get ) ) {
			continue;
		}
		if ( ! is_scalar( $get[ $cle ] ) ) {
			return export_erreur( 'yume_comptes_export_parametres', __( 'Paramètres d’export invalides.', 'yume-core' ), 400 );
		}
		$propres[ $cle ] = wp_unslash( (string) $get[ $cle ] );
	}
	$email = $propres['email'] ?? '';
	if ( ! in_array( $email, array( '', '0', '1' ), true ) ) {
		return export_erreur( 'yume_comptes_export_parametres', __( 'Paramètres d’export invalides.', 'yume-core' ), 400 );
	}
	$avec_email = '1' === $email;
	if ( $avec_email && ! user_can( $user_id, 'manage_options' ) ) {
		return export_erreur( 'yume_comptes_export_email', __( 'L’export avec les adresses e-mail est réservé aux administrateurs.', 'yume-core' ), 403 );
	}

	$args    = args_comptes( $propres );
	$complet = export_comptes_complets( $args );
	if ( null === $complet ) {
		return export_erreur( 'yume_comptes_indisponible', __( 'La liste des comptes n’est pas encore disponible.', 'yume-core' ), 503 );
	}
	$nb       = count( $complet['comptes'] );
	$contexte = array();
	foreach ( EXPORT_FILTRES as $cle ) {
		$contexte[ $cle ] = $args[ $cle ];
	}
	journaliser( $avec_email ? 'export_email' : 'export', $user_id, 0, $nb, $contexte );

	return array(
		'nom'    => sanitize_file_name( 'comptes-inscrits-' . ( $avec_email ? 'avec-email-' : '' ) . wp_date( 'Y-m-d', maintenant() ) . '.csv' ),
		'csv'    => csv_comptes( $complet, $avec_email ),
		'lignes' => $nb,
	);
}

/*
 * -----------------------------------------------------------------------------
 * admin-post.php
 * -----------------------------------------------------------------------------
 */

/**
 * Export CSV (admin-post.php?action=yume_comptes_export) : fichier téléchargé, jamais mis en
 * cache ; page d'erreur avec le statut HTTP du refus sinon.
 */
function admin_post_export(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paramètres bruts, nonce vérifié et valeurs assainies par traiter_export().
	$export = traiter_export( (array) $_GET, get_current_user_id() );
	nocache_headers();
	if ( is_wp_error( $export ) ) {
		$donnees = $export->get_error_data();
		wp_die(
			esc_html( $export->get_error_message() ),
			esc_html__( 'Export impossible', 'yume-core' ),
			array( 'response' => (int) ( is_array( $donnees ) ? ( $donnees['status'] ?? 403 ) : 403 ) )
		);
	}
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $export['nom'] . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $export['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichier CSV, cellules neutralisées par export_cellule().
	exit;
}
add_action( 'admin_post_' . ACTION_EXPORT, __NAMESPACE__ . '\\admin_post_export' );

/**
 * Export demandé sans être connecté : 401.
 */
function admin_post_export_anonyme(): void {
	nocache_headers();
	wp_die(
		esc_html__( 'Connectez-vous pour exporter la liste des comptes.', 'yume-core' ),
		esc_html__( 'Export impossible', 'yume-core' ),
		array( 'response' => 401 )
	);
}
add_action( 'admin_post_nopriv_' . ACTION_EXPORT, __NAMESPACE__ . '\\admin_post_export_anonyme' );
