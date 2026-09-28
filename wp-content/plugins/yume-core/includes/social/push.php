<?php
/**
 * Notifications navigateur (Web Push, AMEL-06), sans dépendance externe.
 *
 * - Opt-in explicite, appareil par appareil, dans la rubrique « Notifications » de la page
 *   compte (auth-links/view.js : permission du navigateur, abonnement PushManager, envoi de
 *   l'abonnement à POST /moi/push).
 * - Clés VAPID P-256 générées par OpenSSL à la première activation (option yume_vapid, non
 *   chargée automatiquement ; la clé privée ne quitte jamais le serveur) ; jeton JWT ES256
 *   signé par openssl_sign() (signature DER convertie en R||S).
 * - Push SANS charge utile (aucun chiffrement aes128gcm) : le service worker
 *   (includes/reader/assets/sw.js) reçoit l'événement « push », demande
 *   GET /yume/v1/moi/notifications?non_lues=1&limite=1 (cookies inclus, en-têtes
 *   X-Yume-Push-Endpoint et X-Yume-Push-Auth qui l'authentifient sans nonce ; rien n'est mis
 *   en cache) et affiche la notification ; un clic ouvre son adresse.
 * - Table {prefix}yume_push (id, user_id, endpoint, empreinte, p256dh, auth, cree_le,
 *   dernier_envoi, echecs). Envoi en tâche cron par lots (yume_push_envoyer), jamais pendant la
 *   requête qui publie : chaque abonnement dont le membre a une notification non lue plus
 *   récente que le dernier envoi reçoit un push (TTL 24 h, Urgency normal). Abonnements 404/410
 *   supprimés, et après ECHECS_PUSH_MAX échecs consécutifs.
 * - Seuls les points d'accès https des services push connus sont acceptés (anti-SSRF) :
 *   fcm.googleapis.com, updates.push.services.mozilla.com, *.notify.windows.com,
 *   web.push.apple.com.
 * - Désactivable : réglage « Notifications navigateur » (notifications_navigateur) et filtre
 *   yume_push_actif.
 *
 * Routes REST : rest.php (POST/DELETE /moi/push, GET /push/cle).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Option (non chargée automatiquement) : clés VAPID. */
const OPTION_VAPID = 'yume_vapid';

/** Événement cron d'envoi des push par lots. */
const HOOK_PUSH = 'yume_push_envoyer';

/** Abonnements traités par lot. */
const LOT_PUSH = 50;

/** Durée de vie d'un push chez le service push (secondes). */
const TTL_PUSH = DAY_IN_SECONDS;

/** Échecs consécutifs (hors 404/410) avant suppression d'un abonnement. */
const ECHECS_PUSH_MAX = 5;

/** Abonnements (appareils) par membre au plus : le plus ancien est remplacé. */
const ABONNEMENTS_PUSH_MAX = 10;

/**
 * Nom complet de la table des abonnements Web Push.
 */
function table_push(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_push';
}

/**
 * Les notifications navigateur sont-elles actives ? Réglage notifications_navigateur (défaut :
 * oui), OpenSSL disponible, puis filtre yume_push_actif.
 */
function push_actif(): bool {
	$actif = function_exists( 'yume_setting' ) ? rest_sanitize_boolean( yume_setting( 'notifications_navigateur', true ) ) : true;
	$actif = $actif && function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_sign' ) && defined( 'OPENSSL_KEYTYPE_EC' );
	/**
	 * Active ou désactive les notifications navigateur (Web Push).
	 *
	 * @param bool $actif Valeur du réglage « Notifications navigateur ».
	 */
	return (bool) apply_filters( 'yume_push_actif', $actif );
}

/**
 * Champ « Notifications navigateur » de Yume → Réglages (filtre yume_reglages_champs).
 *
 * @param array $champs Champs.
 * @return array
 */
function champ_reglage_push( $champs ): array {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'notifications_navigateur',
		'label'       => __( 'Notifications navigateur', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'notifications',
		'default'     => true,
		'description' => __( 'Les lecteurs peuvent activer, depuis leur compte, une notification du navigateur à chaque sortie d’une œuvre suivie et à chaque réponse à leurs commentaires (site en HTTPS et tâches planifiées actives).', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champ_reglage_push' );

/*
 * -----------------------------------------------------------------------------
 * Points d'accès autorisés
 * -----------------------------------------------------------------------------
 */

/**
 * Hôtes des services push acceptés : nom exact, ou « *.domaine » pour ses sous-domaines.
 *
 * @return string[]
 */
function hotes_push(): array {
	return array( 'fcm.googleapis.com', 'updates.push.services.mozilla.com', '*.notify.windows.com', 'web.push.apple.com' );
}

/**
 * Le point d'accès est-il une adresse https d'un service push connu (anti-SSRF) ?
 *
 * @param string $endpoint Adresse.
 */
function endpoint_autorise( string $endpoint ): bool {
	if ( '' === $endpoint || strlen( $endpoint ) > 1000 || preg_match( '/[\s\x00-\x1f]/', $endpoint ) ) {
		return false;
	}
	$parties = wp_parse_url( $endpoint );
	if ( ! is_array( $parties ) || 'https' !== strtolower( (string) ( $parties['scheme'] ?? '' ) ) || empty( $parties['host'] ) ) {
		return false;
	}
	if ( isset( $parties['user'] ) || isset( $parties['pass'] ) || ( isset( $parties['port'] ) && 443 !== (int) $parties['port'] ) ) {
		return false;
	}
	$hote = strtolower( (string) $parties['host'] );
	foreach ( hotes_push() as $autorise ) {
		if ( str_starts_with( $autorise, '*.' ) ) {
			$suffixe = substr( $autorise, 1 );
			if ( str_ends_with( $hote, $suffixe ) && strlen( $hote ) > strlen( $suffixe ) && (bool) preg_match( '/^[a-z0-9.-]+$/', $hote ) ) {
				return true;
			}
		} elseif ( $hote === $autorise ) {
			return true;
		}
	}
	return false;
}

/*
 * -----------------------------------------------------------------------------
 * VAPID (RFC 8292) : clés et jeton ES256
 * -----------------------------------------------------------------------------
 */

/**
 * Encodage base64url sans remplissage.
 *
 * @param string $donnees Octets.
 */
function base64url( string $donnees ): string {
	return rtrim( strtr( base64_encode( $donnees ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodage base64url du protocole Web Push.
}

/**
 * Décodage base64url ('' si invalide).
 *
 * @param string $texte Texte.
 */
function base64url_decoder( string $texte ): string {
	if ( ! preg_match( '/^[A-Za-z0-9_-]*={0,2}$/', $texte ) ) {
		return '';
	}
	$brut = base64_decode( strtr( rtrim( $texte, '=' ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- décodage base64url du protocole Web Push.
	return false === $brut ? '' : $brut;
}

/**
 * Clés VAPID du site, générées à la première demande : public (point P-256 non compressé,
 * base64url) et prive (PEM, jamais exposé). Tableau vide si OpenSSL ne peut pas les générer.
 *
 * @return array{public?:string,prive?:string}
 */
function cles_vapid(): array {
	$cles = get_option( OPTION_VAPID );
	if ( is_array( $cles ) && ! empty( $cles['public'] ) && ! empty( $cles['prive'] ) ) {
		return $cles;
	}
	if ( ! function_exists( 'openssl_pkey_new' ) || ! defined( 'OPENSSL_KEYTYPE_EC' ) ) {
		return array();
	}
	$cle = openssl_pkey_new(
		array(
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		)
	);
	if ( false === $cle ) {
		return array();
	}
	$pem     = '';
	$details = openssl_pkey_get_details( $cle );
	if ( ! openssl_pkey_export( $cle, $pem ) || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
		return array();
	}
	$point = "\x04" . str_pad( (string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( (string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
	$cles  = array(
		'public'  => base64url( $point ),
		'prive'   => (string) $pem,
		'cree_le' => maintenant_gmt(),
	);
	// add_option : si une autre requête vient de les créer, ce sont les siennes qui comptent.
	if ( ! add_option( OPTION_VAPID, $cles, '', false ) ) {
		$existantes = get_option( OPTION_VAPID );
		return is_array( $existantes ) && ! empty( $existantes['public'] ) ? $existantes : array();
	}
	return $cles;
}

/**
 * Clé publique VAPID (applicationServerKey), '' si indisponible.
 */
function cle_publique_vapid(): string {
	return (string) ( cles_vapid()['public'] ?? '' );
}

/**
 * Signature ECDSA DER (SEQUENCE de deux INTEGER) → R||S (64 octets), '' si invalide.
 *
 * @param string $der Signature DER.
 */
function signature_der_vers_brut( string $der ): string {
	$pos = 0;
	$lon = strlen( $der );
	if ( $lon < 8 || "\x30" !== $der[0] ) {
		return '';
	}
	$pos = 2;
	if ( ord( $der[1] ) & 0x80 ) {
		$pos += ord( $der[1] ) & 0x7f;
	}
	$entiers = array();
	for ( $i = 0; $i < 2; $i++ ) {
		if ( $pos + 2 > $lon || "\x02" !== $der[ $pos ] ) {
			return '';
		}
		$taille = ord( $der[ $pos + 1 ] );
		$valeur = substr( $der, $pos + 2, $taille );
		if ( strlen( $valeur ) !== $taille ) {
			return '';
		}
		$valeur = ltrim( $valeur, "\0" );
		if ( strlen( $valeur ) > 32 ) {
			return '';
		}
		$entiers[] = str_pad( $valeur, 32, "\0", STR_PAD_LEFT );
		$pos      += 2 + $taille;
	}
	return $entiers[0] . $entiers[1];
}

/**
 * Signature R||S (64 octets) → DER (pour openssl_verify), '' si invalide.
 *
 * @param string $brut Signature R||S.
 */
function signature_brut_vers_der( string $brut ): string {
	if ( 64 !== strlen( $brut ) ) {
		return '';
	}
	$corps = '';
	foreach ( array( substr( $brut, 0, 32 ), substr( $brut, 32 ) ) as $entier ) {
		$entier = ltrim( $entier, "\0" );
		if ( '' === $entier || ord( $entier[0] ) & 0x80 ) {
			$entier = "\0" . $entier;
		}
		$corps .= "\x02" . chr( strlen( $entier ) ) . $entier;
	}
	return "\x30" . chr( strlen( $corps ) ) . $corps;
}

/**
 * Contact de l'application serveur (claim « sub ») : adresse de l'administration.
 */
function sujet_vapid(): string {
	$email = sanitize_email( (string) get_option( 'admin_email' ) );
	/**
	 * Contact VAPID transmis aux services push (mailto: ou https:).
	 *
	 * @param string $sujet mailto:adresse de l'administration.
	 */
	return (string) apply_filters( 'yume_push_sujet', '' !== $email ? 'mailto:' . $email : home_url( '/' ) );
}

/**
 * Jeton JWT ES256 VAPID pour une audience (origine du service push), '' si impossible.
 *
 * @param string   $audience   Origine « https://hote ».
 * @param int|null $expiration Horodatage d'expiration (par défaut : dans 12 heures).
 */
function jeton_vapid( string $audience, ?int $expiration = null ): string {
	$cles = cles_vapid();
	if ( empty( $cles['prive'] ) ) {
		return '';
	}
	$entete = base64url(
		(string) wp_json_encode(
			array(
				'typ' => 'JWT',
				'alg' => 'ES256',
			)
		)
	);
	$charge = base64url(
		(string) wp_json_encode(
			array(
				'aud' => $audience,
				'exp' => $expiration ?? time() + 12 * HOUR_IN_SECONDS,
				'sub' => sujet_vapid(),
			),
			JSON_UNESCAPED_SLASHES
		)
	);
	$entree = $entete . '.' . $charge;
	$prive  = openssl_pkey_get_private( (string) $cles['prive'] );
	$der    = '';
	if ( false === $prive || ! openssl_sign( $entree, $der, $prive, OPENSSL_ALGO_SHA256 ) ) {
		return '';
	}
	$brut = signature_der_vers_brut( (string) $der );
	return '' === $brut ? '' : $entree . '.' . base64url( $brut );
}

/**
 * Audience VAPID d'un point d'accès : « https://hote ».
 *
 * @param string $endpoint Point d'accès.
 */
function audience_push( string $endpoint ): string {
	return 'https://' . strtolower( (string) wp_parse_url( $endpoint, PHP_URL_HOST ) );
}

/*
 * -----------------------------------------------------------------------------
 * Abonnements
 * -----------------------------------------------------------------------------
 */

/**
 * Empreinte d'un point d'accès (clé unique de la table).
 *
 * @param string $endpoint Point d'accès.
 */
function empreinte_push( string $endpoint ): string {
	return hash( 'sha256', $endpoint );
}

/**
 * Enregistre (ou rattache au membre) l'abonnement push d'un appareil.
 *
 * @param int    $user_id  Membre.
 * @param string $endpoint Point d'accès du service push.
 * @param string $p256dh   Clé publique du navigateur (base64url, 65 octets).
 * @param string $auth     Secret d'authentification (base64url, 16 octets).
 * @return int|\WP_Error Identifiant de l'abonnement.
 */
function enregistrer_abonnement( int $user_id, string $endpoint, string $p256dh, string $auth ) {
	global $wpdb;
	if ( ! push_actif() ) {
		return new \WP_Error( 'yume_push_inactif', __( 'Les notifications navigateur sont désactivées sur ce site.', 'yume-core' ), array( 'status' => 403 ) );
	}
	if ( ! endpoint_autorise( $endpoint ) ) {
		return new \WP_Error( 'yume_push_hote', __( 'Service de notifications non reconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( 65 !== strlen( base64url_decoder( $p256dh ) ) || 16 !== strlen( base64url_decoder( $auth ) ) ) {
		return new \WP_Error( 'yume_push_cles', __( 'Clés d’abonnement invalides.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( $user_id <= 0 || ! preparer_tables_lecteur() ) {
		return new \WP_Error( 'yume_push_echec', __( 'L’abonnement n’a pas pu être enregistré.', 'yume-core' ), array( 'status' => 500 ) );
	}
	$table      = table_push();
	$empreinte  = empreinte_push( $endpoint );
	$maintenant = maintenant_gmt();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE empreinte = %s", $empreinte ) );
	if ( $id ) {
		// Même appareil (reconnexion avec un autre compte, clés renouvelées) : mise à jour.
		$wpdb->update(
			$table,
			array(
				'user_id'       => $user_id,
				'endpoint'      => $endpoint,
				'p256dh'        => $p256dh,
				'auth'          => $auth,
				'cree_le'       => $maintenant,
				'dernier_envoi' => $maintenant,
				'echecs'        => 0,
			),
			array( 'id' => $id )
		);
		return $id;
	}
	// Trop d'appareils : les plus anciens sont remplacés.
	$anciens = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY cree_le DESC, id DESC LIMIT 100 OFFSET %d", $user_id, ABONNEMENTS_PUSH_MAX - 1 ) ) );
	foreach ( $anciens as $ancien ) {
		$wpdb->delete( $table, array( 'id' => $ancien ), array( '%d' ) );
	}
	$ok = $wpdb->insert(
		$table,
		array(
			'user_id'       => $user_id,
			'endpoint'      => $endpoint,
			'empreinte'     => $empreinte,
			'p256dh'        => $p256dh,
			'auth'          => $auth,
			'cree_le'       => $maintenant,
			// Seules les notifications créées après l'abonnement déclenchent un push.
			'dernier_envoi' => $maintenant,
			'echecs'        => 0,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
	);
	// phpcs:enable
	if ( ! $ok ) {
		return new \WP_Error( 'yume_push_echec', __( 'L’abonnement n’a pas pu être enregistré.', 'yume-core' ), array( 'status' => 500 ) );
	}
	return (int) $wpdb->insert_id;
}

/**
 * Supprime l'abonnement d'un appareil du membre.
 *
 * @param int    $user_id  Membre.
 * @param string $endpoint Point d'accès.
 * @return bool Vrai si un abonnement a été supprimé.
 */
function supprimer_abonnement( int $user_id, string $endpoint ): bool {
	global $wpdb;
	if ( $user_id <= 0 || '' === $endpoint || ! tables_lecteur_pretes() ) {
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->delete(
		table_push(),
		array(
			'user_id'   => $user_id,
			'empreinte' => empreinte_push( $endpoint ),
		),
		array( '%d', '%s' )
	);
}

/**
 * Abonnements d'un membre.
 *
 * @param int $user_id Membre.
 * @return array<int,array>
 */
function abonnements_push( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return array();
	}
	$table = table_push();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC", $user_id ), ARRAY_A );
}

/**
 * Supprime les abonnements d'un membre (RGPD, suppression du compte).
 *
 * @param int $user_id Membre.
 * @return int Nombre d'abonnements supprimés.
 */
function effacer_push( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->delete( table_push(), array( 'user_id' => $user_id ), array( '%d' ) );
}

/**
 * Abonnements d'un membre pour l'export RGPD (sans le secret d'authentification).
 *
 * @param int $user_id Membre.
 * @return array<int,array<string,string>>
 */
function donnees_push( int $user_id ): array {
	$export = array();
	foreach ( abonnements_push( $user_id ) as $abo ) {
		$export[] = array(
			'service'       => (string) wp_parse_url( (string) $abo['endpoint'], PHP_URL_HOST ),
			'cree_le'       => iso( (string) $abo['cree_le'] ),
			'dernier_envoi' => iso( (string) $abo['dernier_envoi'] ),
		);
	}
	return $export;
}

/**
 * Utilisateur supprimé : ses abonnements aussi.
 *
 * @param int $user_id Utilisateur.
 */
function push_utilisateur_supprime( $user_id ): void {
	effacer_push( (int) $user_id );
}
add_action( 'deleted_user', __NAMESPACE__ . '\\push_utilisateur_supprime' );

/*
 * -----------------------------------------------------------------------------
 * Envoi (tâche cron par lots)
 * -----------------------------------------------------------------------------
 */

/**
 * Des notifications viennent d'être créées : planifie un lot d'envoi (jamais d'envoi pendant
 * la requête qui publie).
 *
 * @param int[] $user_ids Membres notifiés.
 */
function planifier_push( $user_ids = array() ): void {
	global $wpdb;
	$user_ids = array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) );
	if ( ! $user_ids || ! push_actif() || ! tables_lecteur_pretes() || wp_next_scheduled( HOOK_PUSH ) ) {
		return;
	}
	$table   = table_push();
	$marques = implode( ', ', array_fill( 0, min( 500, count( $user_ids ) ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$abonnes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id IN ({$marques})", array_slice( $user_ids, 0, 500 ) ) );
	if ( $abonnes > 0 || count( $user_ids ) > 500 ) {
		wp_schedule_single_event( time() + 10, HOOK_PUSH );
	}
}
add_action( 'yume_notifications_creees', __NAMESPACE__ . '\\planifier_push' );

/**
 * Abonnements à prévenir : ceux dont le membre a une notification non lue créée après le
 * dernier envoi.
 *
 * @param int $limite Nombre maximal.
 * @return array<int,array>
 */
function abonnements_a_prevenir( int $limite = LOT_PUSH ): array {
	global $wpdb;
	if ( ! tables_lecteur_pretes() ) {
		return array();
	}
	$push   = table_push();
	$notifs = table_notifications_lecteur();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.*, (SELECT MAX(n.cree_le) FROM {$notifs} n WHERE n.user_id = p.user_id AND n.lu_le IS NULL) AS derniere
			FROM {$push} p
			WHERE EXISTS (SELECT 1 FROM {$notifs} n WHERE n.user_id = p.user_id AND n.lu_le IS NULL AND n.cree_le > COALESCE(p.dernier_envoi, p.cree_le))
			ORDER BY p.id ASC LIMIT %d",
			max( 1, $limite )
		),
		ARRAY_A
	);
	// phpcs:enable
	return $lignes;
}

/**
 * Envoie un push sans charge utile à un abonnement (wp_safe_remote_post) et tient la table à
 * jour : 404/410 → abonnement supprimé ; autre échec → compteur, suppression au-delà de
 * ECHECS_PUSH_MAX.
 *
 * @param array $abo Ligne de la table (avec « derniere » : date de la notification annoncée).
 * @return int Code HTTP (0 : non envoyé).
 */
function envoyer_push( array $abo ): int {
	global $wpdb;
	$table    = table_push();
	$id       = (int) $abo['id'];
	$endpoint = (string) $abo['endpoint'];
	if ( ! endpoint_autorise( $endpoint ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return 0;
	}
	$jeton = jeton_vapid( audience_push( $endpoint ) );
	if ( '' === $jeton ) {
		return 0;
	}
	$reponse = wp_safe_remote_post(
		$endpoint,
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization'  => 'vapid t=' . $jeton . ', k=' . cle_publique_vapid(),
				'TTL'            => (string) TTL_PUSH,
				'Urgency'        => 'normal',
				'Content-Length' => '0',
			),
			'body'        => '',
		)
	);
	$code    = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );
	$envoi   = ! empty( $abo['derniere'] ) ? (string) $abo['derniere'] : maintenant_gmt();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	if ( in_array( $code, array( 404, 410 ), true ) ) {
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	} elseif ( $code >= 200 && $code < 300 ) {
		$wpdb->update(
			$table,
			array(
				'dernier_envoi' => $envoi,
				'echecs'        => 0,
			),
			array( 'id' => $id )
		);
	} elseif ( (int) $abo['echecs'] + 1 >= ECHECS_PUSH_MAX ) {
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	} else {
		$wpdb->update(
			$table,
			array(
				'dernier_envoi' => $envoi,
				'echecs'        => (int) $abo['echecs'] + 1,
			),
			array( 'id' => $id )
		);
	}
	// phpcs:enable
	return $code;
}

/**
 * Tâche cron : envoie un lot de push, et planifie le lot suivant s'il en reste.
 *
 * @return int Nombre de push acceptés par les services (2xx).
 */
function envoyer_lot_push(): int {
	if ( ! push_actif() ) {
		return 0;
	}
	$acceptes = 0;
	$lot      = abonnements_a_prevenir( LOT_PUSH );
	foreach ( $lot as $abo ) {
		$code = envoyer_push( $abo );
		if ( $code >= 200 && $code < 300 ) {
			++$acceptes;
		}
	}
	if ( count( $lot ) >= LOT_PUSH && ! wp_next_scheduled( HOOK_PUSH ) ) {
		wp_schedule_single_event( time() + 30, HOOK_PUSH );
	}
	return $acceptes;
}
add_action( HOOK_PUSH, __NAMESPACE__ . '\\envoyer_lot_push' );

/**
 * Désactivation : suppression des envois planifiés.
 */
function desactiver_push(): void {
	wp_clear_scheduled_hook( HOOK_PUSH );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver_push' );

/*
 * -----------------------------------------------------------------------------
 * Authentification du service worker
 * -----------------------------------------------------------------------------
 */

/**
 * GET /yume/v1/moi/notifications demandé par le service worker : il n'a pas de nonce REST ;
 * il s'authentifie par l'abonnement de l'appareil (en-têtes X-Yume-Push-Endpoint et
 * X-Yume-Push-Auth : point d'accès et secret « auth », connus du seul navigateur et du site).
 * Seule cette route en lecture est concernée.
 *
 * @param \WP_Error|null|true $resultat Résultat des authentifications précédentes.
 * @return \WP_Error|null|true
 */
function authentifier_push( $resultat ) {
	global $wpdb;
	if ( is_wp_error( $resultat ) || get_current_user_id() > 0 || ! tables_lecteur_pretes() ) {
		return $resultat;
	}
	$endpoint = isset( $_SERVER['HTTP_X_YUME_PUSH_ENDPOINT'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_YUME_PUSH_ENDPOINT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé par empreinte seulement.
	$auth     = isset( $_SERVER['HTTP_X_YUME_PUSH_AUTH'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_YUME_PUSH_AUTH'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé par hash_equals seulement.
	$methode  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	$route    = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? untrailingslashit( (string) $GLOBALS['wp']->query_vars['rest_route'] ) : '';
	if ( '' === $endpoint || '' === $auth || 'GET' !== $methode || '/' . REST_NS . '/moi/notifications' !== $route ) {
		return $resultat;
	}
	$table = table_push();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$abo = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, auth FROM {$table} WHERE empreinte = %s", empreinte_push( $endpoint ) ), ARRAY_A );
	if ( is_array( $abo ) && hash_equals( (string) $abo['auth'], $auth ) && get_userdata( (int) $abo['user_id'] ) ) {
		wp_set_current_user( (int) $abo['user_id'] );
		return true;
	}
	return $resultat;
}
add_filter( 'rest_authentication_errors', __NAMESPACE__ . '\\authentifier_push', 150 );

/*
 * -----------------------------------------------------------------------------
 * Page compte et REST
 * -----------------------------------------------------------------------------
 */

/**
 * Bloc « Notifications navigateur » de la rubrique « Notifications » : le bouton d'activation
 * n'apparaît qu'avec JavaScript, dans un navigateur compatible et un contexte sécurisé.
 *
 * @param int $user_id Membre.
 */
function html_push_compte( int $user_id ): string {
	$html = '<div class="yn-card yn-account__bloc yn-push"><h3 class="yn-account__sous-titre">' . esc_html__( 'Notifications navigateur', 'yume-core' ) . '</h3>';
	if ( ! push_actif() ) {
		return $html . '<p class="yn-muted">' . esc_html__( 'Les notifications navigateur sont désactivées sur ce site pour le moment.', 'yume-core' ) . '</p></div>';
	}
	$cle = cle_publique_vapid();
	if ( '' === $cle ) {
		return $html . '<p class="yn-muted">' . esc_html__( 'Les notifications navigateur ne sont pas disponibles sur ce serveur.', 'yume-core' ) . '</p></div>';
	}
	$donnees = array_merge(
		donnees_rest(),
		array(
			'cle'        => $cle,
			'sw'         => function_exists( '\Yume\Core\Reader\url_pwa' ) ? \Yume\Core\Reader\url_pwa( 'yume_sw' ) : add_query_arg( 'yume_sw', '1', home_url( '/' ) ),
			'portee'     => function_exists( '\Yume\Core\Reader\chemin_base' ) ? \Yume\Core\Reader\chemin_base() : '/',
			// Empreintes (SHA-256) des points d'accès de ce compte : le script sait si l'abonnement
			// de l'appareil est bien rattaché à ce compte, sans requête.
			'empreintes' => array_values( wp_list_pluck( abonnements_push( $user_id ), 'empreinte' ) ),
		)
	);
	wp_enqueue_script( 'yume-cloche' );
	return $html . '<div data-yn-push="' . esc_attr( (string) wp_json_encode( $donnees ) ) . '">'
		. '<p>' . esc_html__( 'Recevez une notification sur cet appareil à chaque sortie d’une œuvre suivie et à chaque réponse à vos commentaires, même site fermé. À activer sur chaque appareil.', 'yume-core' ) . '</p>'
		. '<p class="yn-muted" data-yn-push-etat>' . esc_html__( 'Nécessite JavaScript et un navigateur compatible (site en HTTPS).', 'yume-core' ) . '</p>'
		. '<div class="yn-account__boutons"><button type="button" class="yn-btn yn-btn--primary" data-yn-push-bouton hidden>' . esc_html__( 'Activer sur cet appareil', 'yume-core' ) . '</button></div>'
		. '<p class="yn-visually-hidden" role="status" aria-live="polite" data-yn-push-annonce></p>'
		. '</div></div>';
}

/**
 * POST /moi/push : enregistre l'abonnement de l'appareil.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_push_abonner( \WP_REST_Request $requete ) {
	$cles   = is_array( $requete['keys'] ) ? $requete['keys'] : array();
	$p256dh = (string) ( $requete['p256dh'] ?? ( $cles['p256dh'] ?? '' ) );
	$auth   = (string) ( $requete['auth'] ?? ( $cles['auth'] ?? '' ) );
	$id     = enregistrer_abonnement( get_current_user_id(), (string) $requete['endpoint'], $p256dh, $auth );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$reponse = rest_ensure_response(
		array(
			'abonne' => true,
			'id'     => $id,
		)
	);
	$reponse->set_status( 201 );
	return $reponse;
}

/**
 * DELETE /moi/push : supprime l'abonnement de l'appareil (endpoint).
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_push_desabonner( \WP_REST_Request $requete ) {
	return rest_ensure_response(
		array(
			'abonne'   => false,
			'supprime' => supprimer_abonnement( get_current_user_id(), (string) $requete['endpoint'] ),
		)
	);
}

/**
 * GET /push/cle : clé publique VAPID (publique ; null si les notifications navigateur sont
 * désactivées).
 *
 * @return \WP_REST_Response
 */
function rest_push_cle() {
	$actif = push_actif();
	$cle   = $actif ? cle_publique_vapid() : '';
	return rest_ensure_response(
		array(
			'actif' => $actif && '' !== $cle,
			'cle'   => '' !== $cle ? $cle : null,
		)
	);
}
