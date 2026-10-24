<?php
/**
 * Dernière activité et source d'inscription des comptes.
 *
 * Dernière activité : méta META_ACTIVITE = jour 'Y-m-d' à l'heure du site, écrite au plus une
 * fois par jour et par compte (jamais d'heure, d'adresse IP ni d'agent). Déclencheurs :
 * connexion (wp_login), position de lecture (yume_progression_enregistree), favori ajouté
 * (yume_favori_ajoute), commentaire d'un compte connecté (comment_post), coquille signalée
 * (yume_coquille_signalee) et, en filet, toute page du site vue par un compte connecté (init
 * tardif, hors administration, REST, Ajax, cron et WP-CLI : méta lue dans le cache du compte
 * courant, aucune requête en plus hors de l'écriture quotidienne). Amorçage unique
 * (yume_core_install, puis filet sur init si l'option manque) : jour de la position de lecture
 * la plus récente de chaque compte ; OPTION_DEPUIS = début du relevé.
 *
 * Source d'inscription : méta META_SOURCE posée à la création du compte (yume_lecteur_inscrit,
 * priorité 5). Pour les anciens comptes, source_compte() la déduit sans rien écrire.
 *
 * Contrat : docs/06-contrat-technique.md §8 septies.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Dernière activité
 * -----------------------------------------------------------------------------
 */

/**
 * Jour 'Y-m-d' d'un horodatage, à l'heure du site.
 *
 * @param int $ts Horodatage Unix (0 : maintenant()).
 */
function jour_site( int $ts = 0 ): string {
	return (string) wp_date( 'Y-m-d', $ts > 0 ? $ts : maintenant() );
}

/**
 * Jour valide ('Y-m-d') ?
 *
 * @param mixed $jour Valeur.
 */
function jour_valide( $jour ): bool {
	return is_string( $jour ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $jour );
}

/**
 * Note l'activité d'un compte : la méta prend le jour de $ts si elle est absente ou plus
 * ancienne (au plus une écriture par jour ; jamais de retour en arrière).
 *
 * @param int $user_id Compte.
 * @param int $ts      Horodatage de l'activité (0 : maintenant()).
 * @return bool Vrai si la méta a été écrite.
 */
function noter_activite( int $user_id, int $ts = 0 ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}
	/**
	 * Faut-il noter l'activité de ce compte ?
	 *
	 * @param bool $noter   Défaut : vrai.
	 * @param int  $user_id Compte.
	 */
	if ( ! apply_filters( 'yume_comptes_noter_activite', true, $user_id ) ) {
		return false;
	}
	if ( ! get_userdata( $user_id ) instanceof \WP_User ) {
		return false;
	}
	$jour = jour_site( $ts );
	if ( strcmp( derniere_activite( $user_id ), $jour ) >= 0 ) {
		return false;
	}
	return (bool) update_user_meta( $user_id, META_ACTIVITE, $jour );
}

/**
 * Jour de dernière activité d'un compte ('Y-m-d', heure du site), '' si inconnu.
 *
 * @param int $user_id Compte.
 */
function derniere_activite( int $user_id ): string {
	$jour = $user_id > 0 ? get_user_meta( $user_id, META_ACTIVITE, true ) : '';
	return jour_valide( $jour ) ? $jour : '';
}

/**
 * Amorçage unique de la méta d'activité : pour chaque compte qui a une position de lecture, jour
 * (heure du site) de sa position la plus récente, si la méta est absente ou plus ancienne ; pose
 * OPTION_DEPUIS (début du relevé, jour courant) si elle manque, puis OPTION_AMORCAGE. Sans effet
 * une fois l'amorçage fait. Si la table des positions n'est pas prête (module reader absent ou
 * non installé), rien n'est amorcé et l'amorçage sera retenté.
 *
 * @return int Nombre de comptes dont la méta a été écrite.
 */
function amorcer_activite(): int {
	global $wpdb;
	if ( false !== get_option( OPTION_AMORCAGE ) ) {
		return 0;
	}
	if ( false === get_option( OPTION_DEPUIS ) ) {
		add_option( OPTION_DEPUIS, jour_site(), '', true );
	}
	if ( ! function_exists( '\Yume\Core\Reader\table_progression' ) || ! function_exists( '\Yume\Core\Reader\table_prete' ) || ! \Yume\Core\Reader\table_prete() ) {
		return 0;
	}
	$table = \Yume\Core\Reader\table_progression();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms de tables internes ; requête unique à l'installation.
	$lignes  = $wpdb->get_results( "SELECT p.user_id AS user_id, MAX(p.updated_at) AS derniere FROM {$table} p INNER JOIN {$wpdb->users} u ON u.ID = p.user_id GROUP BY p.user_id" );
	$actuels = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", META_ACTIVITE ) );
	// phpcs:enable
	$existant = array();
	foreach ( (array) $actuels as $a ) {
		$existant[ (int) $a->user_id ] = jour_valide( $a->meta_value ) ? (string) $a->meta_value : '';
	}
	$aujourdhui = jour_site();
	$ecrits     = 0;
	foreach ( (array) $lignes as $ligne ) {
		$uid  = (int) $ligne->user_id;
		$jour = $uid > 0 && is_string( $ligne->derniere ) && '' !== $ligne->derniere ? get_date_from_gmt( $ligne->derniere, 'Y-m-d' ) : '';
		if ( ! jour_valide( $jour ) || 0 === strpos( $jour, '0000' ) ) {
			continue;
		}
		$jour = strcmp( $jour, $aujourdhui ) > 0 ? $aujourdhui : $jour;
		if ( strcmp( $existant[ $uid ] ?? '', $jour ) >= 0 ) {
			continue;
		}
		if ( update_user_meta( $uid, META_ACTIVITE, $jour ) ) {
			++$ecrits;
		}
	}
	update_option( OPTION_AMORCAGE, maintenant(), true );
	return $ecrits;
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\amorcer_activite' );

/**
 * Filet : amorçage si l'option manque (installation antérieure au module, install interrompue).
 */
function filet_amorcage(): void {
	if ( false === get_option( OPTION_AMORCAGE ) ) {
		amorcer_activite();
	}
}
add_action( 'init', __NAMESPACE__ . '\\filet_amorcage', 100 );

/**
 * Connexion (wp_login).
 *
 * @param string        $login Identifiant.
 * @param \WP_User|null $user  Compte.
 */
function activite_connexion( $login, $user = null ): void {
	unset( $login );
	if ( $user instanceof \WP_User ) {
		noter_activite( (int) $user->ID );
	}
}
add_action( 'wp_login', __NAMESPACE__ . '\\activite_connexion', 10, 2 );

/**
 * Position de lecture enregistrée (yume_progression_enregistree) ou favori ajouté
 * (yume_favori_ajoute) : le premier argument est le compte.
 *
 * @param int|mixed $user_id Compte.
 */
function activite_membre( $user_id ): void {
	if ( is_numeric( $user_id ) ) {
		noter_activite( (int) $user_id );
	}
}
add_action( 'yume_progression_enregistree', __NAMESPACE__ . '\\activite_membre', 10, 1 );
add_action( 'yume_favori_ajoute', __NAMESPACE__ . '\\activite_membre', 10, 1 );

/**
 * Commentaire publié par un compte connecté (comment_post) : son auteur, s'il est le compte
 * courant (un import ou une création par un tiers ne compte pas).
 *
 * @param int        $comment_id Commentaire.
 * @param int|string $approuve   Statut.
 * @param array      $donnees    Données du commentaire.
 */
function activite_commentaire( $comment_id, $approuve = 0, $donnees = array() ): void {
	unset( $comment_id, $approuve );
	$auteur = is_array( $donnees ) ? (int) ( $donnees['user_id'] ?? 0 ) : 0;
	if ( $auteur > 0 && get_current_user_id() === $auteur ) {
		noter_activite( $auteur );
	}
}
add_action( 'comment_post', __NAMESPACE__ . '\\activite_commentaire', 10, 3 );

/**
 * Coquille signalée (yume_coquille_signalee, module corrections) par un compte.
 *
 * @param int $id          Signalement.
 * @param int $chapitre_id Chapitre.
 * @param int $user_id     Compte (0 : anonyme).
 */
function activite_coquille( $id, $chapitre_id = 0, $user_id = 0 ): void {
	unset( $id, $chapitre_id );
	if ( is_numeric( $user_id ) && (int) $user_id > 0 ) {
		noter_activite( (int) $user_id );
	}
}
add_action( 'yume_coquille_signalee', __NAMESPACE__ . '\\activite_coquille', 10, 3 );

/**
 * La requête est-elle une page du site (ni administration, ni REST, ni Ajax, ni cron, ni
 * WP-CLI, ni XML-RPC) ? Évaluée sur init : la REST se reconnaît à son adresse.
 */
function requete_de_page(): bool {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple détection de la REST.
	if ( isset( $_GET['rest_route'] ) ) {
		return false;
	}
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$chemin = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$base   = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	$rest   = $base . '/' . trim( rest_get_url_prefix(), '/' ) . '/';
	return 0 !== strpos( trailingslashit( $chemin ), $rest );
}

/**
 * Filet : une page du site vue par un compte connecté note son activité (une écriture par jour
 * au plus ; la méta est déjà dans le cache du compte courant).
 */
function filet_activite(): void {
	if ( is_user_logged_in() && requete_de_page() ) {
		noter_activite( get_current_user_id() );
	}
}
add_action( 'init', __NAMESPACE__ . '\\filet_activite', 999 );

/*
 * -----------------------------------------------------------------------------
 * Source d'inscription
 * -----------------------------------------------------------------------------
 */

/**
 * Enregistre la source d'inscription d'un compte (SOURCES ; autre valeur ignorée).
 *
 * @param int    $user_id Compte.
 * @param string $source  inscription, discord ou equipe.
 */
function noter_source( int $user_id, string $source ): void {
	if ( $user_id > 0 && in_array( $source, SOURCES, true ) && get_userdata( $user_id ) instanceof \WP_User ) {
		update_user_meta( $user_id, META_SOURCE, $source );
	}
}

/**
 * Compte créé par Social\creer_compte_lecteur() (yume_lecteur_inscrit, priorité 5 : avant les
 * autres écouteurs).
 *
 * @param int|mixed    $user_id Compte.
 * @param string|mixed $source  Source.
 */
function source_inscription( $user_id, $source = '' ): void {
	if ( is_numeric( $user_id ) && is_string( $source ) ) {
		noter_source( (int) $user_id, $source );
	}
}
add_action( 'yume_lecteur_inscrit', __NAMESPACE__ . '\\source_inscription', 5, 2 );

/**
 * Source d'inscription d'un compte : la méta si elle est posée (deduite = faux) ; sinon,
 * déduite sans rien écrire (deduite = vrai) : Discord lié et compte sans mot de passe → discord ;
 * identifiant généré (yn-xxxxxxxx, depuis 2.5.0) → inscription. Sinon (ou compte inexistant) :
 * inconnue, deduite = faux.
 *
 * @param int $user_id Compte.
 * @return array{source:string,deduite:bool}
 */
function source_compte( int $user_id ): array {
	$inconnue = array(
		'source'  => 'inconnue',
		'deduite' => false,
	);
	$compte   = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( ! $compte instanceof \WP_User ) {
		return $inconnue;
	}
	$meta = get_user_meta( $user_id, META_SOURCE, true );
	if ( is_string( $meta ) && in_array( $meta, SOURCES, true ) ) {
		return array(
			'source'  => $meta,
			'deduite' => false,
		);
	}
	$discord  = function_exists( '\Yume\Core\Discord\discord_lie' )
		? '' !== \Yume\Core\Discord\discord_lie( $user_id )
		: '' !== (string) get_user_meta( $user_id, 'yume_discord_id', true );
	$sans_mdp = function_exists( '\Yume\Core\Social\compte_sans_mot_de_passe' )
		? \Yume\Core\Social\compte_sans_mot_de_passe( $user_id )
		: (bool) get_user_meta( $user_id, '_yume_compte_sans_mdp', true );
	if ( $discord && $sans_mdp ) {
		return array(
			'source'  => 'discord',
			'deduite' => true,
		);
	}
	$genere = function_exists( '\Yume\Core\Social\identifiant_genere' )
		? \Yume\Core\Social\identifiant_genere( (string) $compte->user_login )
		: 1 === preg_match( '/^yn-[a-z0-9]{8}$/', (string) $compte->user_login );
	if ( $genere ) {
		return array(
			'source'  => 'inscription',
			'deduite' => true,
		);
	}
	return $inconnue;
}
