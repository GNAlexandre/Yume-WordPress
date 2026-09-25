<?php
/**
 * Fonctions internes du module lecteurs : tables favoris et notes, caches des œuvres,
 * préférences d'alerte, profils (lecteur, équipe), limitation de débit par adresse IP,
 * URL des pages de compte et messages affichés après un formulaire.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Option : version du schéma des tables du module. */
const OPTION_SCHEMA = 'yume_social_schema';

/** Version du schéma (favoris, notes). */
const VERSION_SCHEMA = '1';

/** Méta utilisateur des préférences d'alerte globales. */
const META_ALERTES = 'yume_alertes';

/** Méta utilisateur : changement d'adresse e-mail en attente de confirmation. */
const META_EMAIL_ATTENTE = '_yume_email_en_attente';

/** Fréquences d'alerte d'un favori. */
const FREQUENCES = array( 'immediat', 'hebdo', 'jamais' );

/** Événement cron du récapitulatif hebdomadaire des lecteurs. */
const HOOK_RECAP = 'yume_social_recap_hebdo';

/** Option : date GMT du dernier récapitulatif hebdomadaire envoyé. */
const OPTION_RECAP = 'yume_social_recap_dernier';

/** Méta de contenu : alertes des abonnés « immédiat » déjà envoyées (date GMT). */
const META_ALERTE_ENVOYEE = '_yume_alerte_envoyee';

/** Texte de confirmation exigé pour supprimer un compte. */
const CONFIRMATION_SUPPRESSION = 'SUPPRIMER';

/**
 * Nom complet de la table des favoris.
 */
function table_favoris(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_favoris';
}

/**
 * Nom complet de la table des notes.
 */
function table_notes(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_notes';
}

/**
 * Les tables du module sont-elles installées ?
 */
function tables_pretes(): bool {
	return VERSION_SCHEMA === get_option( OPTION_SCHEMA );
}

/**
 * Date et heure GMT courantes au format MySQL.
 */
function maintenant_gmt(): string {
	return current_time( 'mysql', true );
}

/**
 * Convertit une date GMT « Y-m-d H:i:s » en ISO 8601. Chaîne vide si invalide.
 *
 * @param string $gmt Date GMT.
 */
function iso( string $gmt ): string {
	if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? '' : gmdate( 'c', $ts );
}

/**
 * L'œuvre existe-t-elle et est-elle publiée ?
 *
 * @param int $oeuvre_id ID.
 */
function oeuvre_publiee( int $oeuvre_id ): bool {
	$post = $oeuvre_id > 0 ? get_post( $oeuvre_id ) : null;
	return $post instanceof \WP_Post && 'yume_oeuvre' === $post->post_type && 'publish' === $post->post_status;
}

/**
 * Erreur « œuvre introuvable » (404).
 */
function erreur_oeuvre(): \WP_Error {
	return new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), array( 'status' => 404 ) );
}

/*
 * -----------------------------------------------------------------------------
 * Favoris et alertes par œuvre
 * -----------------------------------------------------------------------------
 */

/**
 * Fréquence d'alerte d'un favori, ou chaîne vide si l'œuvre n'est pas en favori.
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 */
function frequence_favori( int $user_id, int $oeuvre_id ): string {
	global $wpdb;
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! tables_pretes() ) {
		return '';
	}
	$table = table_favoris();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$frequence = $wpdb->get_var( $wpdb->prepare( "SELECT frequence FROM {$table} WHERE user_id = %d AND oeuvre_id = %d", $user_id, $oeuvre_id ) );
	if ( null === $frequence ) {
		return '';
	}
	return in_array( (string) $frequence, FREQUENCES, true ) ? (string) $frequence : 'immediat';
}

/**
 * L'œuvre est-elle dans les favoris du membre ?
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 */
function est_favori( int $user_id, int $oeuvre_id ): bool {
	return '' !== frequence_favori( $user_id, $oeuvre_id );
}

/**
 * Ajoute une œuvre publiée aux favoris (sans effet si elle y est déjà).
 *
 * @param int    $user_id   Utilisateur.
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence Fréquence d'alerte initiale (défaut : immédiate).
 * @return true|\WP_Error
 */
function ajouter_favori( int $user_id, int $oeuvre_id, string $frequence = 'immediat' ) {
	global $wpdb;
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		return erreur_oeuvre();
	}
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new \WP_Error( 'yume_utilisateur_invalide', __( 'Utilisateur inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! tables_pretes() ) {
		installer_tables();
	}
	if ( est_favori( $user_id, $oeuvre_id ) ) {
		return true;
	}
	$frequence = in_array( $frequence, FREQUENCES, true ) ? $frequence : 'immediat';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->insert(
		table_favoris(),
		array(
			'user_id'    => $user_id,
			'oeuvre_id'  => $oeuvre_id,
			'frequence'  => $frequence,
			'created_at' => maintenant_gmt(),
		),
		array( '%d', '%d', '%s', '%s' )
	);
	if ( false === $ok && ! est_favori( $user_id, $oeuvre_id ) ) {
		return new \WP_Error( 'yume_favori_echec', __( 'Le favori n’a pas pu être enregistré.', 'yume-core' ), array( 'status' => 500 ) );
	}
	recalculer_caches( $oeuvre_id );
	/**
	 * Une œuvre vient d'être ajoutée aux favoris d'un membre.
	 *
	 * @param int $user_id   Utilisateur.
	 * @param int $oeuvre_id Œuvre.
	 */
	do_action( 'yume_favori_ajoute', $user_id, $oeuvre_id );
	return true;
}

/**
 * Retire une œuvre des favoris (et donc ses alertes).
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 * @return bool Vrai si une ligne a été supprimée.
 */
function retirer_favori( int $user_id, int $oeuvre_id ): bool {
	global $wpdb;
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! tables_pretes() ) {
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$n = (int) $wpdb->delete(
		table_favoris(),
		array(
			'user_id'   => $user_id,
			'oeuvre_id' => $oeuvre_id,
		),
		array( '%d', '%d' )
	);
	if ( $n ) {
		recalculer_caches( $oeuvre_id );
		/**
		 * Une œuvre vient d'être retirée des favoris d'un membre.
		 *
		 * @param int $user_id   Utilisateur.
		 * @param int $oeuvre_id Œuvre.
		 */
		do_action( 'yume_favori_retire', $user_id, $oeuvre_id );
	}
	return $n > 0;
}

/**
 * Règle la fréquence d'alerte d'un favori.
 *
 * @param int    $user_id   Utilisateur.
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence immediat, hebdo ou jamais.
 * @return true|\WP_Error
 */
function definir_frequence( int $user_id, int $oeuvre_id, string $frequence ) {
	global $wpdb;
	if ( ! in_array( $frequence, FREQUENCES, true ) ) {
		return new \WP_Error( 'yume_frequence_invalide', __( 'Fréquence d’alerte invalide.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		return erreur_oeuvre();
	}
	if ( ! est_favori( $user_id, $oeuvre_id ) ) {
		return new \WP_Error( 'yume_pas_favori', __( 'Ajoutez d’abord l’œuvre à vos favoris pour régler ses alertes.', 'yume-core' ), array( 'status' => 409 ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update(
		table_favoris(),
		array( 'frequence' => $frequence ),
		array(
			'user_id'   => $user_id,
			'oeuvre_id' => $oeuvre_id,
		),
		array( '%s' ),
		array( '%d', '%d' )
	);
	return true;
}

/**
 * Favoris d'un membre, du plus récent au plus ancien.
 *
 * @param int $user_id Utilisateur.
 * @return array<int,array{oeuvre_id:int,frequence:string,created_at:string}>
 */
function favoris_utilisateur( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_pretes() ) {
		return array();
	}
	$table = table_favoris();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, frequence, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, oeuvre_id ASC", $user_id ), ARRAY_A );
	return array_map(
		static function ( $ligne ): array {
			return array(
				'oeuvre_id'  => (int) $ligne['oeuvre_id'],
				'frequence'  => in_array( (string) $ligne['frequence'], FREQUENCES, true ) ? (string) $ligne['frequence'] : 'immediat',
				'created_at' => (string) $ligne['created_at'],
			);
		},
		$lignes
	);
}

/**
 * Abonnés d'une œuvre pour une fréquence (utilisateurs existants seulement).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence immediat, hebdo ou jamais.
 * @return int[]
 */
function abonnes( int $oeuvre_id, string $frequence = 'immediat' ): array {
	global $wpdb;
	if ( $oeuvre_id <= 0 || ! in_array( $frequence, FREQUENCES, true ) || ! tables_pretes() ) {
		return array();
	}
	$table = table_favoris();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT f.user_id FROM {$table} f INNER JOIN {$wpdb->users} u ON u.ID = f.user_id WHERE f.oeuvre_id = %d AND f.frequence = %s ORDER BY f.user_id ASC", $oeuvre_id, $frequence ) );
	return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
}

/*
 * -----------------------------------------------------------------------------
 * Notes
 * -----------------------------------------------------------------------------
 */

/**
 * Note (1 à 5) d'un membre pour une œuvre, 0 si aucune.
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 */
function note( int $user_id, int $oeuvre_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! tables_pretes() ) {
		return 0;
	}
	$table = table_notes();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$note = (int) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$table} WHERE user_id = %d AND oeuvre_id = %d", $user_id, $oeuvre_id ) );
	return max( 0, min( 5, $note ) );
}

/**
 * Note une œuvre (1 à 5) ou retire la note (0).
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 * @param int $valeur    Note 0–5.
 * @return true|\WP_Error
 */
function noter( int $user_id, int $oeuvre_id, int $valeur ) {
	global $wpdb;
	if ( $valeur < 0 || $valeur > 5 ) {
		return new \WP_Error( 'yume_note_invalide', __( 'La note doit être comprise entre 1 et 5 (0 pour la retirer).', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		return erreur_oeuvre();
	}
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new \WP_Error( 'yume_utilisateur_invalide', __( 'Utilisateur inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! tables_pretes() ) {
		installer_tables();
	}
	if ( 0 === $valeur ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			table_notes(),
			array(
				'user_id'   => $user_id,
				'oeuvre_id' => $oeuvre_id,
			),
			array( '%d', '%d' )
		);
	} else {
		// REPLACE : compatible MySQL et SQLite, clé primaire (user_id, oeuvre_id).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->replace(
			table_notes(),
			array(
				'user_id'    => $user_id,
				'oeuvre_id'  => $oeuvre_id,
				'note'       => $valeur,
				'updated_at' => maintenant_gmt(),
			),
			array( '%d', '%d', '%d', '%s' )
		);
		if ( false === $ok ) {
			return new \WP_Error( 'yume_note_echec', __( 'La note n’a pas pu être enregistrée.', 'yume-core' ), array( 'status' => 500 ) );
		}
	}
	recalculer_caches( $oeuvre_id );
	/**
	 * Un membre vient de noter une œuvre (0 : note retirée).
	 *
	 * @param int $user_id   Utilisateur.
	 * @param int $oeuvre_id Œuvre.
	 * @param int $valeur    Note.
	 */
	do_action( 'yume_note_enregistree', $user_id, $oeuvre_id, $valeur );
	return true;
}

/**
 * Notes d'un membre, de la plus récente à la plus ancienne.
 *
 * @param int $user_id Utilisateur.
 * @return array<int,array{oeuvre_id:int,note:int,updated_at:string}>
 */
function notes_utilisateur( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_pretes() ) {
		return array();
	}
	$table = table_notes();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, note, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, oeuvre_id ASC", $user_id ), ARRAY_A );
	return array_map(
		static function ( $ligne ): array {
			return array(
				'oeuvre_id'  => (int) $ligne['oeuvre_id'],
				'note'       => max( 1, min( 5, (int) $ligne['note'] ) ),
				'updated_at' => (string) $ligne['updated_at'],
			);
		},
		$lignes
	);
}

/**
 * Moyenne et nombre des notes d'une œuvre (calcul SQL).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{moyenne:float,nombre:int}
 */
function stats_notes( int $oeuvre_id ): array {
	global $wpdb;
	if ( $oeuvre_id <= 0 || ! tables_pretes() ) {
		return array(
			'moyenne' => 0.0,
			'nombre'  => 0,
		);
	}
	$table = table_notes();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS nombre, SUM(note) AS total FROM {$table} WHERE oeuvre_id = %d AND note BETWEEN 1 AND 5", $oeuvre_id ), ARRAY_A );
	$nombre = (int) ( $ligne['nombre'] ?? 0 );
	$total  = (int) ( $ligne['total'] ?? 0 );
	return array(
		'moyenne' => $nombre > 0 ? round( $total / $nombre, 2 ) : 0.0,
		'nombre'  => $nombre,
	);
}

/**
 * Nombre de favoris d'une œuvre (calcul SQL).
 *
 * @param int $oeuvre_id Œuvre.
 */
function compter_favoris( int $oeuvre_id ): int {
	global $wpdb;
	if ( $oeuvre_id <= 0 || ! tables_pretes() ) {
		return 0;
	}
	$table = table_favoris();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE oeuvre_id = %d", $oeuvre_id ) );
}

/**
 * Recalcule les caches de l'œuvre (§4) : yume_nb_favoris, yume_note_moyenne, yume_nb_notes.
 *
 * @param int $oeuvre_id Œuvre.
 */
function recalculer_caches( int $oeuvre_id ): void {
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return;
	}
	$stats = stats_notes( $oeuvre_id );
	update_post_meta( $oeuvre_id, 'yume_nb_favoris', compter_favoris( $oeuvre_id ) );
	update_post_meta( $oeuvre_id, 'yume_note_moyenne', (float) $stats['moyenne'] );
	update_post_meta( $oeuvre_id, 'yume_nb_notes', (int) $stats['nombre'] );
}

/**
 * Caches lus sur l'œuvre (pour l'affichage).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{favoris:int,moyenne:float,notes:int}
 */
function caches( int $oeuvre_id ): array {
	return array(
		'favoris' => (int) get_post_meta( $oeuvre_id, 'yume_nb_favoris', true ),
		'moyenne' => round( (float) get_post_meta( $oeuvre_id, 'yume_note_moyenne', true ), 2 ),
		'notes'   => (int) get_post_meta( $oeuvre_id, 'yume_nb_notes', true ),
	);
}

/**
 * Note moyenne affichée à la française (« 4,6 »).
 *
 * @param float $moyenne Moyenne.
 */
function moyenne_fr( float $moyenne ): string {
	return str_replace( '.', ',', number_format( $moyenne, 1, '.', '' ) );
}

/*
 * -----------------------------------------------------------------------------
 * Préférences d'alerte globales
 * -----------------------------------------------------------------------------
 */

/**
 * Préférences par défaut : e-mail à chaque sortie d'un favori (oui), récapitulatif
 * hebdomadaire de toutes les sorties suivies (non), réponses à mes commentaires (oui).
 *
 * @return array{sorties:bool,hebdo:bool,commentaires:bool}
 */
function preferences_par_defaut(): array {
	return array(
		'sorties'      => true,
		'hebdo'        => false,
		'commentaires' => true,
	);
}

/**
 * Préférences d'alerte d'un membre.
 *
 * @param int $user_id Utilisateur.
 * @return array{sorties:bool,hebdo:bool,commentaires:bool}
 */
function preferences_alertes( int $user_id ): array {
	$prefs = preferences_par_defaut();
	$brut  = $user_id > 0 ? get_user_meta( $user_id, META_ALERTES, true ) : array();
	$brut  = is_array( $brut ) ? $brut : array();
	foreach ( array_keys( $prefs ) as $cle ) {
		if ( array_key_exists( $cle, $brut ) ) {
			$prefs[ $cle ] = (bool) $brut[ $cle ];
		}
	}
	return $prefs;
}

/**
 * Enregistre les préférences d'alerte (clés connues, booléens).
 *
 * @param int   $user_id Utilisateur.
 * @param array $valeurs Préférences reçues (les clés absentes gardent leur valeur).
 * @return array{sorties:bool,hebdo:bool,commentaires:bool}
 */
function enregistrer_preferences( int $user_id, array $valeurs ): array {
	$prefs = preferences_alertes( $user_id );
	foreach ( array_keys( $prefs ) as $cle ) {
		if ( array_key_exists( $cle, $valeurs ) ) {
			$prefs[ $cle ] = rest_sanitize_boolean( $valeurs[ $cle ] );
		}
	}
	update_user_meta( $user_id, META_ALERTES, $prefs );
	return $prefs;
}

/**
 * Libellés des fréquences d'alerte.
 *
 * @return array<string,string>
 */
function libelles_frequences(): array {
	return array(
		'immediat' => __( 'Immédiate', 'yume-core' ),
		'hebdo'    => __( 'Hebdomadaire', 'yume-core' ),
		'jamais'   => __( 'Jamais', 'yume-core' ),
	);
}

/**
 * Les e-mails aux lecteurs sont-ils activés (réglage emails_lecteurs) ?
 */
function emails_actifs(): bool {
	$valeur = function_exists( 'yume_setting' ) ? yume_setting( 'emails_lecteurs', true ) : true;
	return rest_sanitize_boolean( $valeur );
}

/*
 * -----------------------------------------------------------------------------
 * Profils
 * -----------------------------------------------------------------------------
 */

/**
 * L'utilisateur est-il un membre de l'équipe ou un administrateur ?
 *
 * @param int $user_id Utilisateur.
 */
function est_equipe( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( is_multisite() && is_super_admin( $user_id ) ) {
		return true;
	}
	foreach ( array( 'manage_options', 'yume_voir_equipe', 'edit_posts', 'edit_yume_tomes', 'moderate_comments', 'list_users' ) as $capacite ) {
		if ( user_can( $user_id, $capacite ) ) {
			return true;
		}
	}
	return false;
}

/**
 * L'utilisateur (courant par défaut) est-il un simple lecteur connecté ?
 *
 * @param \WP_User|int|null $user Utilisateur.
 */
function est_lecteur( $user = null ): bool {
	$id = $user instanceof \WP_User ? (int) $user->ID : ( null === $user ? get_current_user_id() : (int) $user );
	return $id > 0 && ! est_equipe( $id );
}

/**
 * Un compte peut-il être supprimé en façade ? Jamais pour l'équipe ni un administrateur.
 *
 * @param int $user_id Utilisateur.
 */
function peut_supprimer_compte( int $user_id ): bool {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( ! $user || est_equipe( $user_id ) ) {
		return false;
	}
	$roles = array_values( (array) $user->roles );
	return ! array_diff( $roles, array( 'subscriber' ) );
}

/*
 * -----------------------------------------------------------------------------
 * Limitation de débit (formulaires publics)
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse IP du visiteur (REMOTE_ADDR validée ; jamais d'en-tête falsifiable).
 */
function adresse_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Compte une tentative et indique si la limite est dépassée pour cette adresse IP.
 *
 * @param string $action Nom de l'action (inscription, oubli…).
 * @param int    $max    Tentatives autorisées sur la période.
 * @param int    $duree  Période en secondes.
 * @return bool Vrai si la limite est atteinte (la tentative doit être refusée).
 */
function limite_atteinte( string $action, int $max, int $duree ): bool {
	/**
	 * Filtre la limite de tentatives d'une action publique par adresse IP.
	 *
	 * @param int    $max    Tentatives autorisées.
	 * @param string $action Action.
	 */
	$max  = (int) apply_filters( 'yume_limite_tentatives', $max, $action );
	$cle  = 'yume_lim_' . sanitize_key( $action ) . '_' . substr( hash_hmac( 'sha256', adresse_ip(), wp_salt( 'nonce' ) ), 0, 32 );
	$etat = get_transient( $cle );
	$etat = is_array( $etat ) ? $etat : array(
		'n'   => 0,
		'fin' => time() + $duree,
	);
	if ( (int) $etat['n'] >= $max ) {
		return true;
	}
	$etat['n'] = (int) $etat['n'] + 1;
	set_transient( $cle, $etat, max( 1, (int) $etat['fin'] - time() ) );
	return false;
}

/*
 * -----------------------------------------------------------------------------
 * Pages et messages
 * -----------------------------------------------------------------------------
 */

/**
 * La page Yume de cette clé est-elle réellement enregistrée (option yume_pages) ?
 *
 * @param string $cle Clé (compte, connexion…).
 */
function page_enregistree( string $cle ): bool {
	$pages = get_option( 'yume_pages', array() );
	if ( ! is_array( $pages ) || empty( $pages[ $cle ] ) ) {
		return false;
	}
	$page = get_post( (int) $pages[ $cle ] );
	return $page && 'page' === $page->post_type && in_array( $page->post_status, array( 'publish', 'private' ), true );
}

/**
 * URL de la page compte.
 */
function url_compte(): string {
	return function_exists( 'yume_url_page' ) ? yume_url_page( 'compte' ) : home_url( '/compte/' );
}

/**
 * Destination sûre pour renvoyer un lecteur vers son compte : la page compte si elle existe
 * (option yume_pages), sinon l'accueil (site pas encore migré : jamais de page 404).
 */
function url_compte_sure(): string {
	return page_enregistree( 'compte' ) ? url_compte() : home_url( '/' );
}

/**
 * URL de la page de connexion en façade (page « connexion », sinon « compte », sinon
 * wp-login.php), avec la page d'origine en redirect_to.
 *
 * @param string $retour Page où revenir après la connexion.
 */
function url_connexion( string $retour = '' ): string {
	if ( page_enregistree( 'connexion' ) ) {
		$url = yume_url_page( 'connexion' );
	} elseif ( page_enregistree( 'compte' ) ) {
		$url = yume_url_page( 'compte' );
	} else {
		return wp_login_url( $retour );
	}
	$retour = '' !== $retour ? wp_validate_redirect( $retour, '' ) : '';
	return '' !== $retour ? add_query_arg( 'redirect_to', rawurlencode( $retour ), $url ) : $url;
}

/**
 * Adresse de la page courante (pour revenir après une connexion ou un formulaire).
 */
function url_courante(): string {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$ref = wp_get_referer();
		return $ref ? $ref : home_url( '/' );
	}
	// REQUEST_URI contient déjà le chemin d'installation : on repart de l'hôte de home_url().
	$hote = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST );
	$port = wp_parse_url( home_url(), PHP_URL_PORT );
	if ( $port ) {
		$hote .= ':' . $port;
	}
	$url = $hote . $uri;
	return remove_query_arg( array( 'yn-msg', 'yn-email', '_wpnonce' ), $url );
}

/**
 * Inscriptions en façade ouvertes ? (réglage WordPress « Tout le monde peut s'inscrire »).
 */
function inscriptions_ouvertes(): bool {
	/**
	 * Filtre l'ouverture des inscriptions en façade.
	 *
	 * @param bool $ouvertes Défaut : option users_can_register.
	 */
	return (bool) apply_filters( 'yume_inscriptions_ouvertes', (bool) get_option( 'users_can_register' ) );
}

/**
 * Messages affichés après un formulaire (paramètre yn-msg) : code => [type, texte].
 *
 * @return array<string,array{0:string,1:string}>
 */
function messages(): array {
	return array(
		'inscription-ok'           => array( 'succes', __( 'Compte créé ! Consultez votre boîte mail : un lien vous permet de choisir votre mot de passe.', 'yume-core' ) ),
		'inscriptions-fermees'     => array( 'erreur', __( 'Les inscriptions sont fermées pour le moment.', 'yume-core' ) ),
		'inscription-refusee'      => array( 'erreur', __( 'L’inscription n’a pas pu aboutir. Réessayez dans quelques instants.', 'yume-core' ) ),
		'pseudo-invalide'          => array( 'erreur', __( 'Choisissez un pseudo de 3 à 40 caractères : lettres, chiffres, espaces, points, tirets et tirets bas.', 'yume-core' ) ),
		'pseudo-pris'              => array( 'erreur', __( 'Ce pseudo est déjà utilisé.', 'yume-core' ) ),
		'email-invalide'           => array( 'erreur', __( 'Adresse e-mail invalide.', 'yume-core' ) ),
		'email-pris'               => array( 'erreur', __( 'Cette adresse e-mail est déjà associée à un compte.', 'yume-core' ) ),
		'trop-de-tentatives'       => array( 'erreur', __( 'Trop de tentatives depuis votre connexion. Réessayez dans une heure.', 'yume-core' ) ),
		'formulaire-expire'        => array( 'erreur', __( 'Le formulaire a expiré. Rechargez la page et recommencez.', 'yume-core' ) ),
		'connexion-echec'          => array( 'erreur', __( 'Identifiant ou mot de passe incorrect.', 'yume-core' ) ),
		'oubli-envoye'             => array( 'succes', __( 'Si un compte correspond, un e-mail de réinitialisation vient d’être envoyé.', 'yume-core' ) ),
		'oubli-vide'               => array( 'erreur', __( 'Indiquez votre pseudo ou votre adresse e-mail.', 'yume-core' ) ),
		'profil-ok'                => array( 'succes', __( 'Profil mis à jour.', 'yume-core' ) ),
		'pseudo-ok'                => array( 'succes', __( 'Pseudo mis à jour.', 'yume-core' ) ),
		'email-attente'            => array( 'succes', __( 'Un lien de confirmation a été envoyé à votre nouvelle adresse. Le changement prendra effet après confirmation.', 'yume-core' ) ),
		'email-ok'                 => array( 'succes', __( 'Votre nouvelle adresse e-mail est confirmée.', 'yume-core' ) ),
		'email-lien-invalide'      => array( 'erreur', __( 'Ce lien de confirmation est invalide ou a expiré.', 'yume-core' ) ),
		'mdp-ok'                   => array( 'succes', __( 'Mot de passe modifié.', 'yume-core' ) ),
		'mdp-actuel'               => array( 'erreur', __( 'Mot de passe actuel incorrect.', 'yume-core' ) ),
		'mdp-court'                => array( 'erreur', __( 'Le nouveau mot de passe doit contenir au moins 8 caractères.', 'yume-core' ) ),
		'mdp-different'            => array( 'erreur', __( 'Les deux mots de passe ne correspondent pas.', 'yume-core' ) ),
		'rien-change'              => array( 'info', __( 'Aucune modification.', 'yume-core' ) ),
		'reglages-ok'              => array( 'succes', __( 'Réglages de lecture enregistrés.', 'yume-core' ) ),
		'reglages-defaut'          => array( 'succes', __( 'Réglages de lecture par défaut rétablis.', 'yume-core' ) ),
		'alertes-ok'               => array( 'succes', __( 'Préférences d’alerte enregistrées.', 'yume-core' ) ),
		'favori-ajoute'            => array( 'succes', __( 'Œuvre ajoutée à vos favoris.', 'yume-core' ) ),
		'favori-retire'            => array( 'succes', __( 'Œuvre retirée de vos favoris.', 'yume-core' ) ),
		'note-ok'                  => array( 'succes', __( 'Merci, votre note est enregistrée.', 'yume-core' ) ),
		'note-retiree'             => array( 'succes', __( 'Votre note a été retirée.', 'yume-core' ) ),
		'alerte-ok'                => array( 'succes', __( 'Alerte mise à jour.', 'yume-core' ) ),
		'pas-favori'               => array( 'erreur', __( 'Ajoutez d’abord l’œuvre à vos favoris pour régler ses alertes.', 'yume-core' ) ),
		'suppression-confirmation' => array( 'erreur', __( 'Pour supprimer votre compte, saisissez SUPPRIMER et votre mot de passe actuel.', 'yume-core' ) ),
		'suppression-interdite'    => array( 'erreur', __( 'Les comptes de l’équipe et des administrateurs ne peuvent pas être supprimés depuis cette page.', 'yume-core' ) ),
		'compte-supprime'          => array( 'succes', __( 'Votre compte et vos données ont été supprimés. Merci d’avoir lu avec nous.', 'yume-core' ) ),
		'erreur'                   => array( 'erreur', __( 'Une erreur est survenue. Réessayez.', 'yume-core' ) ),
		'session'                  => array( 'erreur', __( 'Votre session a expiré. Rechargez la page et recommencez.', 'yume-core' ) ),
	);
}

/**
 * Messages demandés par l'adresse courante (paramètre yn-msg, codes séparés par des virgules).
 *
 * @return array<int,array{0:string,1:string}>
 */
function messages_courants(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage d'un code connu.
	$brut = isset( $_GET['yn-msg'] ) ? sanitize_text_field( wp_unslash( $_GET['yn-msg'] ) ) : '';
	if ( '' === $brut ) {
		return array();
	}
	$connus = messages();
	$liste  = array();
	foreach ( array_unique( array_slice( explode( ',', $brut ), 0, 5 ) ) as $code ) {
		$code = sanitize_key( $code );
		if ( isset( $connus[ $code ] ) ) {
			$liste[] = $connus[ $code ];
		}
	}
	return $liste;
}

/**
 * HTML des messages (région annoncée aux lecteurs d'écran).
 *
 * @param array $messages Messages [type, texte].
 */
function html_messages( array $messages ): string {
	if ( ! $messages ) {
		return '';
	}
	$html = '';
	foreach ( $messages as $message ) {
		$type  = in_array( $message[0], array( 'succes', 'erreur', 'info' ), true ) ? $message[0] : 'info';
		$role  = 'erreur' === $type ? 'alert' : 'status';
		$html .= '<p class="yn-avis yn-avis--' . esc_attr( $type ) . '" role="' . esc_attr( $role ) . '">' . esc_html( $message[1] ) . '</p>';
	}
	return $html;
}

/**
 * Redirige (adresse locale validée) avec des codes de message et une ancre, puis s'arrête.
 *
 * @param string          $url    Adresse.
 * @param string|string[] $codes  Codes de message.
 * @param string          $ancre  Ancre (sans #).
 */
function rediriger( string $url, $codes = array(), string $ancre = '' ): void {
	$url   = wp_validate_redirect( $url, url_compte() );
	$url   = remove_query_arg( array( 'yn-msg', 'yn-email' ), preg_replace( '/#.*$/', '', $url ) );
	$codes = array_filter( array_map( 'sanitize_key', (array) $codes ) );
	if ( $codes ) {
		$url = add_query_arg( 'yn-msg', implode( ',', $codes ), $url );
	}
	if ( '' !== $ancre ) {
		$url .= '#' . rawurlencode( $ancre );
	}
	wp_safe_redirect( $url );
	exit;
}
