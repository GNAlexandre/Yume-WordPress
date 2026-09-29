<?php
/**
 * Profils publics des contributeurs (PAGE-04), sur consentement explicite (opt-in) :
 *
 *   /contributeurs/              liste des profils publics (avatar à initiales, pseudo, rôle, tomes)
 *   /contributeurs/{slug}/       profil d'un membre de l'équipe qui a choisi de l'afficher
 *
 * Méta utilisateur : `yume_profil_public` (booléen, faux par défaut), `yume_profil_bio` (300
 * caractères), `yume_profil_liens` ({discord: pseudo, x: URL, site: URL} ; URL http(s) validées),
 * `yume_profil_arrivee` (« AAAA-MM », facultatif) et `yume_profil_slug` (adresse publique).
 *
 * L'adresse n'utilise jamais l'identifiant de connexion : WordPress dérive user_nicename de
 * user_login (sanitize_title), il est donc aussi exclu. Le slug public vient du pseudo
 * (display_name) ; un pseudo identique à l'identifiant de connexion empêche d'activer le profil.
 *
 * Le profil répond 404 (sans cache) dès que le consentement est retiré, que le compte quitte
 * l'équipe (capacité yume_voir_equipe) ou que son pseudo redevient son identifiant ; au retrait
 * du consentement (et au changement d'adresse), l'ancienne adresse et la liste /contributeurs/
 * sont purgées de Batcache si batcache_clear_url() existe (purger_cache_url(), listes.php). Les responsables du
 * planning (méta privée yume_responsables) ne sont lus que pour un compte consentant et seulement
 * pour les tomes publiés d'œuvres publiées.
 *
 * Règles de réécriture propres (option yume_regles_contributeurs), gabarit de thème
 * « yume-contributeur » (hiérarchie index), bloc yume/profil-contributeur (profil, ou liste des
 * profils : archive, page « L'équipe »), rubrique « Profil public » de la page compte (membres de
 * l'équipe seulement), plan du site (fournisseur « contributeurs »).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Méta utilisateur : consentement à l'affichage du profil public. */
const META_PROFIL_PUBLIC = 'yume_profil_public';

/** Méta utilisateur : courte présentation. */
const META_PROFIL_BIO = 'yume_profil_bio';

/** Méta utilisateur : liens (discord, x, site). */
const META_PROFIL_LIENS = 'yume_profil_liens';

/** Méta utilisateur : arrivée dans l'équipe (« AAAA-MM »). */
const META_PROFIL_ARRIVEE = 'yume_profil_arrivee';

/** Méta utilisateur : segment d'adresse public du profil. */
const META_PROFIL_SLUG = 'yume_profil_slug';

/** Longueur maximale de la présentation. */
const BIO_PROFIL_MAX = 300;

/** Premier segment des adresses publiques. */
const SEGMENT_CONTRIBUTEURS = 'contributeurs';

/** Variables de requête : archive / profil. */
const QV_CONTRIBUTEURS = 'yume_contributeurs';
const QV_CONTRIBUTEUR  = 'yume_contributeur';

/** Option : signature des règles de réécriture des contributeurs. */
const OPTION_REGLES_CONTRIBUTEURS = 'yume_regles_contributeurs';

/** Action admin-post.php (et nonce) du formulaire de la page compte. */
const ACTION_PROFIL_PUBLIC = 'yume_compte_profil_public';

/*
 * -----------------------------------------------------------------------------
 * Données du profil
 * -----------------------------------------------------------------------------
 */

/**
 * Le compte fait-il partie de l'équipe (capacité yume_voir_equipe) ?
 *
 * @param int $user_id Compte.
 */
function membre_equipe_profil( int $user_id ): bool {
	return $user_id > 0 && user_can( $user_id, 'yume_voir_equipe' );
}

/**
 * Le pseudo révèle-t-il l'identifiant de connexion (identique, ou même adresse une fois
 * normalisé) ?
 *
 * @param \WP_User $user Compte.
 */
function pseudo_revele_identifiant( \WP_User $user ): bool {
	$pseudo = trim( (string) $user->display_name );
	$login  = (string) $user->user_login;
	if ( '' === $pseudo || '' === $login ) {
		return true;
	}
	return mb_strtolower( $pseudo ) === mb_strtolower( $login )
		|| sanitize_title( $pseudo ) === sanitize_title( $login )
		|| sanitize_title( $pseudo ) === mb_strtolower( $login )
		|| sanitize_title( $pseudo ) === (string) $user->user_nicename;
}

/**
 * Slug public disponible pour un compte, tiré de son pseudo ('' si le pseudo révèle
 * l'identifiant de connexion ou ne donne aucun slug). Un slug déjà pris par un autre compte
 * reçoit un suffixe (-2, -3…).
 *
 * @param \WP_User $user Compte.
 */
function generer_slug_profil( \WP_User $user ): string {
	if ( pseudo_revele_identifiant( $user ) ) {
		return '';
	}
	$base = sanitize_title( (string) $user->display_name );
	$base = trim( (string) preg_replace( '/[^a-z0-9-]+/', '', $base ), '-' );
	if ( '' === $base || ctype_digit( $base ) ) {
		return '';
	}
	$base = mb_substr( $base, 0, 60 );
	$slug = $base;
	for ( $n = 2; $n < 100; $n++ ) {
		$pris = get_users(
			array(
				'meta_key'    => META_PROFIL_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'  => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'exclude'     => array( (int) $user->ID ),
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);
		if ( ! $pris ) {
			return $slug;
		}
		$slug = $base . '-' . $n;
	}
	return '';
}

/**
 * Le profil public du compte est-il affiché ? Consentement, appartenance à l'équipe, slug
 * enregistré et pseudo distinct de l'identifiant de connexion.
 *
 * @param int $user_id Compte.
 */
function profil_public_actif( int $user_id ): bool {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( ! $user instanceof \WP_User || ! get_user_meta( $user_id, META_PROFIL_PUBLIC, true ) ) {
		return false;
	}
	return membre_equipe_profil( $user_id )
		&& '' !== (string) get_user_meta( $user_id, META_PROFIL_SLUG, true )
		&& ! pseudo_revele_identifiant( $user );
}

/**
 * Liens du profil, normalisés.
 *
 * @param int $user_id Compte.
 * @return array{discord:string,x:string,site:string}
 */
function liens_profil( int $user_id ): array {
	$liens = get_user_meta( $user_id, META_PROFIL_LIENS, true );
	$liens = is_array( $liens ) ? $liens : array();
	return array(
		'discord' => pseudo_discord_valide( $liens['discord'] ?? '' ),
		'x'       => url_x_valide( $liens['x'] ?? '' ),
		'site'    => url_site_valide( $liens['site'] ?? '' ),
	);
}

/**
 * Pseudo Discord (2 à 37 caractères : lettres, chiffres, point, tiret bas, tiret, #), ou ''.
 *
 * @param mixed $valeur Saisie.
 */
function pseudo_discord_valide( $valeur ): string {
	$valeur = is_scalar( $valeur ) ? ltrim( trim( sanitize_text_field( (string) $valeur ) ), '@' ) : '';
	return preg_match( '/^[\p{L}\p{N}_.#-]{2,37}$/u', $valeur ) ? $valeur : '';
}

/**
 * Adresse d'un compte X (Twitter) : « @pseudo », « pseudo » ou adresse x.com / twitter.com
 * (http ou https), normalisée en https://x.com/pseudo ; '' sinon.
 *
 * @param mixed $valeur Saisie.
 */
function url_x_valide( $valeur ): string {
	$valeur = is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
	if ( '' === $valeur ) {
		return '';
	}
	$pseudo = '';
	if ( preg_match( '/^@?([A-Za-z0-9_]{1,15})$/', $valeur, $trouve ) ) {
		$pseudo = $trouve[1];
	} else {
		$morceaux = wp_parse_url( $valeur );
		$schema   = strtolower( (string) ( $morceaux['scheme'] ?? '' ) );
		$hote     = strtolower( (string) ( $morceaux['host'] ?? '' ) );
		$chemin   = trim( (string) ( $morceaux['path'] ?? '' ), '/' );
		if ( in_array( $schema, array( 'http', 'https' ), true )
			&& in_array( $hote, array( 'x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com', 'mobile.x.com' ), true )
			&& preg_match( '/^@?([A-Za-z0-9_]{1,15})$/', $chemin, $trouve ) ) {
			$pseudo = $trouve[1];
		}
	}
	return '' !== $pseudo ? 'https://x.com/' . $pseudo : '';
}

/**
 * Adresse d'un site personnel : http(s) avec un nom d'hôte, sans identifiants ; '' sinon.
 *
 * @param mixed $valeur Saisie.
 */
function url_site_valide( $valeur ): string {
	$valeur = is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
	if ( '' === $valeur || strlen( $valeur ) > 200 ) {
		return '';
	}
	$morceaux = wp_parse_url( $valeur );
	$schema   = strtolower( (string) ( $morceaux['scheme'] ?? '' ) );
	if ( ! in_array( $schema, array( 'http', 'https' ), true ) || empty( $morceaux['host'] ) || isset( $morceaux['user'] ) || isset( $morceaux['pass'] ) ) {
		return '';
	}
	return esc_url_raw( $valeur, array( 'http', 'https' ) );
}

/**
 * Arrivée dans l'équipe (« AAAA-MM ») valide, ou ''.
 *
 * @param mixed $valeur Saisie.
 */
function arrivee_valide( $valeur ): string {
	$valeur = is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
	if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $valeur, $trouve ) ) {
		return '';
	}
	$annee = (int) $trouve[1];
	return $annee >= 2000 && $annee <= (int) gmdate( 'Y' ) + 1 ? $valeur : '';
}

/**
 * Présentation assainie (texte brut, 300 caractères au plus).
 *
 * @param mixed $valeur Saisie.
 */
function bio_valide( $valeur ): string {
	$valeur = is_scalar( $valeur ) ? sanitize_textarea_field( (string) $valeur ) : '';
	return trim( mb_substr( trim( $valeur ), 0, BIO_PROFIL_MAX ) );
}

/**
 * Enregistre le profil public d'un membre de l'équipe (formulaire de la page compte).
 *
 * @param int                 $user_id Compte.
 * @param array<string,mixed> $donnees public (bool), bio, discord, x, site, arrivee.
 * @return string[] Codes de message (profil-public-*).
 */
function enregistrer_profil_public( int $user_id, array $donnees ): array {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof \WP_User || ! membre_equipe_profil( $user_id ) ) {
		return array( 'profil-public-refuse' );
	}
	$codes = array();
	update_user_meta( $user_id, META_PROFIL_BIO, bio_valide( $donnees['bio'] ?? '' ) );
	update_user_meta( $user_id, META_PROFIL_ARRIVEE, arrivee_valide( $donnees['arrivee'] ?? '' ) );

	$liens = array(
		'discord' => pseudo_discord_valide( $donnees['discord'] ?? '' ),
		'x'       => url_x_valide( $donnees['x'] ?? '' ),
		'site'    => url_site_valide( $donnees['site'] ?? '' ),
	);
	foreach ( $liens as $cle => $valeur ) {
		$saisie = $donnees[ $cle ] ?? '';
		if ( '' === $valeur && is_scalar( $saisie ) && '' !== trim( (string) $saisie ) ) {
			$codes[] = 'profil-public-lien';
		}
	}
	update_user_meta( $user_id, META_PROFIL_LIENS, $liens );

	$avant   = (bool) get_user_meta( $user_id, META_PROFIL_PUBLIC, true );
	$adresse = url_profil_public( $user_id );
	$demande = ! empty( $donnees['public'] );
	if ( $demande ) {
		$slug = generer_slug_profil( $user );
		if ( '' === $slug ) {
			$demande = false;
			$codes[] = 'profil-public-pseudo';
		} else {
			update_user_meta( $user_id, META_PROFIL_SLUG, $slug );
		}
	}
	if ( $demande ) {
		update_user_meta( $user_id, META_PROFIL_PUBLIC, true );
		$codes[] = $avant ? 'profil-public-ok' : 'profil-public-active';
	} else {
		// Retrait immédiat : le profil répond 404 dès maintenant, l'ancienne adresse est oubliée.
		update_user_meta( $user_id, META_PROFIL_PUBLIC, false );
		delete_user_meta( $user_id, META_PROFIL_SLUG );
		// Cache de pages (Batcache sur WordPress.com) : le profil et la liste qui le montrait.
		if ( '' !== $adresse ) {
			purger_cache_url( $adresse );
			purger_cache_url( url_contributeurs() );
		}
		if ( $avant ) {
			$codes[] = 'profil-public-retire';
		} elseif ( ! in_array( 'profil-public-pseudo', $codes, true ) ) {
			$codes[] = 'profil-public-ok';
		}
	}
	return array_values( array_unique( $codes ) );
}

/**
 * Pseudo modifié (page compte, administration) : l'adresse du profil public suit le nouveau
 * pseudo (l'ancienne répond 404) ; un pseudo qui reprend l'identifiant de connexion masque le
 * profil (profil_public_actif()).
 *
 * @param int $user_id Compte.
 */
function suivre_pseudo_profil( $user_id ): void {
	$user_id = (int) $user_id;
	$user    = get_userdata( $user_id );
	if ( ! $user instanceof \WP_User || ! get_user_meta( $user_id, META_PROFIL_PUBLIC, true ) ) {
		return;
	}
	$ancienne = url_profil_public( $user_id );
	$slug     = generer_slug_profil( $user );
	if ( '' !== $slug ) {
		update_user_meta( $user_id, META_PROFIL_SLUG, $slug );
	}
	// L'ancienne adresse (pseudo changé ou masqué) ne doit plus être servie depuis le cache.
	if ( '' !== $ancienne && url_profil_public( $user_id ) !== $ancienne ) {
		purger_cache_url( $ancienne );
	}
}
add_action( 'profile_update', __NAMESPACE__ . '\\suivre_pseudo_profil' );

/**
 * Compte affiché par un slug public, ou null (aucun, ou profil non public).
 *
 * @param string $slug Slug.
 */
function contributeur_par_slug( string $slug ): ?\WP_User {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}
	$ids = get_users(
		array(
			'meta_key'    => META_PROFIL_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'meta_value'  => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			'number'      => 1,
			'fields'      => 'ID',
			'count_total' => false,
		)
	);
	$id  = $ids ? (int) reset( $ids ) : 0;
	return $id && profil_public_actif( $id ) ? get_userdata( $id ) : null;
}

/**
 * Profils publics, triés par pseudo.
 *
 * @return \WP_User[]
 */
function contributeurs_publics(): array {
	$users = get_users(
		array(
			'meta_key'    => META_PROFIL_PUBLIC, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => false,
		)
	);
	return array_values(
		array_filter(
			$users,
			static function ( $user ): bool {
				return $user instanceof \WP_User && profil_public_actif( (int) $user->ID );
			}
		)
	);
}

/**
 * Libellé du rôle Yume d'un compte (« Traducteur », « Gérant »…), ou « Membre de l'équipe ».
 *
 * @param \WP_User $user Compte.
 */
function libelle_role_yume( \WP_User $user ): string {
	$roles = wp_roles()->roles;
	$ordre = array( 'yume_gerant', 'yume_editeur', 'yume_traducteur', 'yume_relecteur', 'yume_graphiste', 'administrator' );
	foreach ( $ordre as $role ) {
		if ( in_array( $role, (array) $user->roles, true ) && isset( $roles[ $role ]['name'] ) ) {
			return 'administrator' === $role ? __( 'Administrateur', 'yume-core' ) : translate_user_role( $roles[ $role ]['name'] );
		}
	}
	return __( 'Membre de l’équipe', 'yume-core' );
}

/**
 * Responsables des tomes publiés (œuvre publiée) : compte => tome => étapes. La méta privée
 * yume_responsables n'est lue qu'ici ; le résultat n'est affiché que pour un profil public.
 *
 * @return array<int,array<int,string[]>>
 */
function index_contributions(): array {
	static $memo = null, $version = '';
	$derniere    = wp_cache_get_last_changed( 'posts' );
	if ( null !== $memo && $version === $derniere ) {
		return $memo;
	}
	$version = $derniere;
	$cle     = 'contributions:' . $derniere;
	$cache   = wp_cache_get( $cle, 'yume_contributeurs' );
	if ( is_array( $cache ) ) {
		$memo = $cache;
		return $memo;
	}
	$tomes = get_posts(
		array(
			'post_type'        => 'yume_tome',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
		)
	);
	$tomes = array_map( 'intval', $tomes );
	$index = array();
	if ( $tomes ) {
		update_meta_cache( 'post', $tomes );
		$oeuvres = array_values( array_unique( array_filter( array_map( static fn( $id ) => (int) get_post_meta( $id, 'yume_oeuvre_id', true ), $tomes ) ) ) );
		if ( $oeuvres ) {
			_prime_post_caches( $oeuvres, false, false );
		}
		$etapes = array( 'traduction', 'relecture', 'edition' );
		foreach ( $tomes as $tome_id ) {
			$oeuvre_id = (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true );
			if ( ! $oeuvre_id || 'yume_oeuvre' !== get_post_type( $oeuvre_id ) || 'publish' !== get_post_status( $oeuvre_id ) ) {
				continue;
			}
			$responsables = get_post_meta( $tome_id, 'yume_responsables', true );
			$responsables = is_array( $responsables ) ? $responsables : ( is_object( $responsables ) ? (array) $responsables : array() );
			foreach ( $etapes as $etape ) {
				$uid = isset( $responsables[ $etape ] ) && is_numeric( $responsables[ $etape ] ) ? (int) $responsables[ $etape ] : 0;
				if ( $uid > 0 ) {
					$index[ $uid ][ $tome_id ][] = $etape;
				}
			}
		}
	}
	wp_cache_set( $cle, $index, 'yume_contributeurs', HOUR_IN_SECONDS );
	$memo = $index;
	return $memo;
}

/**
 * Contributions d'un profil public, groupées par œuvre (ordre alphabétique, tomes par numéro).
 *
 * @param int $user_id Compte (profil public).
 * @return array<int,array{titre:string,url:string,tomes:array<int,array{titre:string,url:string,etapes:string[]}>}>
 */
function contributions_profil( int $user_id ): array {
	if ( ! profil_public_actif( $user_id ) ) {
		return array();
	}
	$index   = index_contributions();
	$libelle = function_exists( 'yume_etapes' ) ? yume_etapes() : array();
	$oeuvres = array();
	foreach ( $index[ $user_id ] ?? array() as $tome_id => $etapes ) {
		$oeuvre_id = (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true );
		if ( ! isset( $oeuvres[ $oeuvre_id ] ) ) {
			$oeuvres[ $oeuvre_id ] = array(
				'titre' => wp_strip_all_tags( get_the_title( $oeuvre_id ) ),
				'url'   => (string) get_permalink( $oeuvre_id ),
				'tomes' => array(),
				'ordre' => array(),
			);
		}
		$oeuvres[ $oeuvre_id ]['tomes'][ $tome_id ] = array(
			'titre'  => wp_strip_all_tags( get_the_title( $tome_id ) ),
			'url'    => (string) get_permalink( $tome_id ),
			'etapes' => array_map( static fn( $e ) => (string) ( $libelle[ $e ] ?? $e ), $etapes ),
		);
		$oeuvres[ $oeuvre_id ]['ordre'][ $tome_id ] = (float) get_post_meta( $tome_id, 'yume_numero', true );
	}
	foreach ( $oeuvres as &$oeuvre ) {
		asort( $oeuvre['ordre'] );
		$oeuvre['tomes'] = array_replace( array_intersect_key( $oeuvre['ordre'], $oeuvre['tomes'] ), $oeuvre['tomes'] );
		unset( $oeuvre['ordre'] );
	}
	unset( $oeuvre );
	uasort( $oeuvres, static fn( $a, $b ) => strcasecmp( remove_accents( $a['titre'] ), remove_accents( $b['titre'] ) ) );
	return $oeuvres;
}

/**
 * Nombre de tomes publiés auxquels un profil public a contribué.
 *
 * @param int $user_id Compte.
 */
function nombre_tomes_profil( int $user_id ): int {
	return profil_public_actif( $user_id ) ? count( index_contributions()[ $user_id ] ?? array() ) : 0;
}

/**
 * Le profil a-t-il assez de contenu pour être indexé (présentation ou contributions) ?
 *
 * @param int $user_id Compte.
 */
function profil_indexable( int $user_id ): bool {
	return profil_public_actif( $user_id ) && ( '' !== (string) get_user_meta( $user_id, META_PROFIL_BIO, true ) || nombre_tomes_profil( $user_id ) > 0 );
}

/*
 * -----------------------------------------------------------------------------
 * Adresses et règles de réécriture
 * -----------------------------------------------------------------------------
 */

/**
 * Règles de réécriture des contributeurs (motif => requête).
 *
 * @return array<string,string>
 */
function regles_contributeurs(): array {
	return array(
		'^' . SEGMENT_CONTRIBUTEURS . '/?$'         => 'index.php?' . QV_CONTRIBUTEURS . '=1',
		'^' . SEGMENT_CONTRIBUTEURS . '/([^/]+)/?$' => 'index.php?' . QV_CONTRIBUTEURS . '=1&' . QV_CONTRIBUTEUR . '=$matches[1]',
	);
}

/**
 * Déclare les règles (init).
 */
function ajouter_regles_contributeurs(): void {
	foreach ( regles_contributeurs() as $motif => $requete ) {
		add_rewrite_rule( $motif, $requete, 'top' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\ajouter_regles_contributeurs' );

/**
 * Vide les règles de réécriture quand celles des contributeurs ont changé (mise à jour de
 * l'extension, développement), sans attendre une réactivation.
 */
function verifier_regles_contributeurs(): void {
	global $wp_rewrite;
	if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
		return;
	}
	$signature = md5( (string) wp_json_encode( regles_contributeurs() ) . '|contributeurs|' . YUME_CORE_VERSION );
	if ( get_option( OPTION_REGLES_CONTRIBUTEURS ) === $signature ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( OPTION_REGLES_CONTRIBUTEURS, $signature, true );
}
add_action( 'init', __NAMESPACE__ . '\\verifier_regles_contributeurs', 101 );

/**
 * Variables de requête publiques.
 *
 * @param string[] $vars Variables.
 * @return string[]
 */
function variables_contributeurs( $vars ): array {
	$vars   = is_array( $vars ) ? $vars : array();
	$vars[] = QV_CONTRIBUTEURS;
	$vars[] = QV_CONTRIBUTEUR;
	return $vars;
}
add_filter( 'query_vars', __NAMESPACE__ . '\\variables_contributeurs' );

/**
 * Adresse de la liste des contributeurs.
 */
function url_contributeurs(): string {
	global $wp_rewrite;
	if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ) {
		return home_url( user_trailingslashit( $wp_rewrite->root . SEGMENT_CONTRIBUTEURS ) );
	}
	return add_query_arg( QV_CONTRIBUTEURS, '1', home_url( '/' ) );
}

/**
 * Adresse du profil public d'un compte, ou '' s'il n'est pas public.
 *
 * @param int $user_id Compte.
 */
function url_profil_public( int $user_id ): string {
	if ( ! profil_public_actif( $user_id ) ) {
		return '';
	}
	$slug = (string) get_user_meta( $user_id, META_PROFIL_SLUG, true );
	global $wp_rewrite;
	if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ) {
		return home_url( user_trailingslashit( $wp_rewrite->root . SEGMENT_CONTRIBUTEURS . '/' . $slug ) );
	}
	return add_query_arg(
		array(
			QV_CONTRIBUTEURS => '1',
			QV_CONTRIBUTEUR  => $slug,
		),
		home_url( '/' )
	);
}

/*
 * -----------------------------------------------------------------------------
 * Requête principale : archive, profil, 404
 * -----------------------------------------------------------------------------
 */

/**
 * La requête principale vise-t-elle les contributeurs (liste ou profil) ?
 */
function est_route_contributeurs(): bool {
	global $wp_query;
	return $wp_query instanceof \WP_Query && (bool) $wp_query->get( QV_CONTRIBUTEURS );
}

/**
 * Profil demandé par la requête principale : compte affiché, null si la page est la liste,
 * false si le profil n'existe pas ou n'est pas public (404).
 *
 * @return \WP_User|null|false
 */
function contributeur_demande() {
	global $wp_query;
	if ( ! est_route_contributeurs() ) {
		return false;
	}
	$slug = (string) $wp_query->get( QV_CONTRIBUTEUR );
	if ( '' === $slug ) {
		return null;
	}
	$user = contributeur_par_slug( $slug );
	return $user instanceof \WP_User ? $user : false;
}

/**
 * Requête principale des contributeurs : ni accueil ni liste d'articles (modèle index, voir
 * hierarchie_contributeurs()).
 *
 * @param \WP_Query $requete Requête.
 */
function preparer_requete_contributeurs( $requete ): void {
	if ( ! $requete instanceof \WP_Query || ! $requete->is_main_query() || ! $requete->get( QV_CONTRIBUTEURS ) ) {
		return;
	}
	$requete->is_home     = false;
	$requete->is_archive  = false;
	$requete->is_singular = false;
	$requete->set( 'no_found_rows', true );
}
add_action( 'parse_query', __NAMESPACE__ . '\\preparer_requete_contributeurs' );

/**
 * Aucun article à charger pour ces pages.
 *
 * @param array|null $posts   Résultat court-circuité.
 * @param \WP_Query  $requete Requête.
 * @return array|null
 */
function court_circuiter_requete( $posts, $requete ) {
	if ( $requete instanceof \WP_Query && $requete->is_main_query() && $requete->get( QV_CONTRIBUTEURS ) ) {
		return array();
	}
	return $posts;
}
add_filter( 'posts_pre_query', __NAMESPACE__ . '\\court_circuiter_requete', 10, 2 );

/**
 * 404 pour un profil absent, non public ou hors équipe ; 200 pour la liste et un profil public.
 *
 * @param bool      $court   Court-circuit.
 * @param \WP_Query $requete Requête principale.
 * @return bool
 */
function statut_contributeurs( $court, $requete = null ) {
	if ( ! $requete instanceof \WP_Query || ! $requete->get( QV_CONTRIBUTEURS ) ) {
		return $court;
	}
	if ( false === contributeur_demande() ) {
		$requete->set_404();
		status_header( 404 );
		nocache_headers();
	} else {
		status_header( 200 );
	}
	return true;
}
add_filter( 'pre_handle_404', __NAMESPACE__ . '\\statut_contributeurs', 10, 2 );

/**
 * Pas de redirection canonique « devinée » depuis ces adresses.
 *
 * @param string|false $url Redirection.
 * @return string|false
 */
function redirection_contributeurs( $url ) {
	return est_route_contributeurs() ? false : $url;
}
add_filter( 'redirect_canonical', __NAMESPACE__ . '\\redirection_contributeurs' );

/**
 * Modèle « yume-contributeur » du thème en tête de la hiérarchie (la requête n'a ni contenu
 * ni archive : WordPress retombe sur le modèle index).
 *
 * @param string[] $modeles Modèles.
 * @return string[]
 */
function hierarchie_contributeurs( $modeles ) {
	if ( ! is_array( $modeles ) || ! est_route_contributeurs() || is_404() ) {
		return $modeles;
	}
	array_unshift( $modeles, 'yume-contributeur.php' );
	return $modeles;
}
add_filter( 'index_template_hierarchy', __NAMESPACE__ . '\\hierarchie_contributeurs' );

/**
 * Nom et description du modèle dans l'éditeur de site.
 *
 * @param array $types Types de modèles.
 * @return array
 */
function type_modele_contributeur( $types ) {
	if ( ! is_array( $types ) ) {
		return $types;
	}
	$types['yume-contributeur'] = array(
		'title'       => _x( 'Contributeurs', 'Template name', 'yume-core' ),
		'description' => __( 'Liste des profils publics de l’équipe (/contributeurs/) et profil d’un contributeur (/contributeurs/{pseudo}/).', 'yume-core' ),
	);
	return $types;
}
add_filter( 'default_template_types', __NAMESPACE__ . '\\type_modele_contributeur' );

/**
 * Titre du document.
 *
 * @param array<string,string> $parties Parties.
 * @return array<string,string>
 */
function titre_contributeurs( $parties ) {
	if ( ! is_array( $parties ) || ! est_route_contributeurs() || is_404() ) {
		return $parties;
	}
	$user             = contributeur_demande();
	$parties['title'] = $user instanceof \WP_User
		/* translators: %s : pseudo du contributeur. */
		? sprintf( __( '%s, contributeur', 'yume-core' ), $user->display_name )
		: __( 'Contributeurs', 'yume-core' );
	return $parties;
}
add_filter( 'document_title_parts', __NAMESPACE__ . '\\titre_contributeurs' );

/**
 * Classe du document.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function classes_contributeurs( $classes ) {
	$classes = is_array( $classes ) ? $classes : array();
	if ( est_route_contributeurs() && ! is_404() ) {
		$classes[] = 'yume-contributeurs';
	}
	return $classes;
}
add_filter( 'body_class', __NAMESPACE__ . '\\classes_contributeurs' );

/**
 * Adresse canonique de la page affichée.
 */
function url_canonique_contributeurs(): string {
	$user = contributeur_demande();
	if ( false === $user ) {
		return '';
	}
	return $user instanceof \WP_User ? url_profil_public( (int) $user->ID ) : url_contributeurs();
}

/**
 * Balise <link rel="canonical"> (WordPress ne l'émet que pour les contenus singuliers).
 */
function afficher_canonique_contributeurs(): void {
	$url = est_route_contributeurs() && ! is_404() ? url_canonique_contributeurs() : '';
	if ( '' !== $url ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\afficher_canonique_contributeurs', 2 );

/**
 * Robots : un profil sans présentation ni contribution publiée (page presque vide), et la liste
 * quand aucun profil n'est public, sont « noindex, follow ».
 *
 * @param array<string,bool|string> $robots Directives.
 * @return array<string,bool|string>
 */
function robots_contributeurs( $robots ) {
	if ( ! is_array( $robots ) || ! est_route_contributeurs() || is_404() ) {
		return $robots;
	}
	$user   = contributeur_demande();
	$maigre = $user instanceof \WP_User ? ! profil_indexable( (int) $user->ID ) : ! contributeurs_publics();
	if ( $maigre ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );
	}
	return $robots;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\robots_contributeurs' );

/**
 * Open Graph (module bibliothèque, filtre yume_open_graph) : titre, description et type
 * « profile » d'un profil ; titre et description de la liste.
 *
 * @param array<string,string> $balises Balises.
 * @return array<string,string>
 */
function open_graph_contributeurs( $balises ) {
	if ( ! is_array( $balises ) || ! $balises || ! est_route_contributeurs() || is_404() ) {
		return $balises;
	}
	$user = contributeur_demande();
	if ( $user instanceof \WP_User ) {
		$bio         = (string) get_user_meta( $user->ID, META_PROFIL_BIO, true );
		$nombre      = nombre_tomes_profil( (int) $user->ID );
		$description = '' !== $bio ? $bio : sprintf(
			/* translators: 1 : rôle, 2 : nombre de tomes. */
			_n( '%1$s chez Yume Novel, %2$d tome publié.', '%1$s chez Yume Novel, %2$d tomes publiés.', $nombre, 'yume-core' ),
			libelle_role_yume( $user ),
			$nombre
		);
		$balises['og:type']          = 'profile';
		$balises['og:title']         = (string) $user->display_name;
		$balises['profile:username'] = (string) $user->display_name;
	} else {
		$description         = __( 'Les membres de l’équipe Yume Novel qui présentent leur travail : traduction, relecture, édition.', 'yume-core' );
		$balises['og:title'] = __( 'Contributeurs', 'yume-core' );
	}
	$balises['description']    = $description;
	$balises['og:description'] = $description;
	$balises['og:url']         = url_canonique_contributeurs();
	return $balises;
}
add_filter( 'yume_open_graph', __NAMESPACE__ . '\\open_graph_contributeurs' );

/*
 * -----------------------------------------------------------------------------
 * Plan du site
 * -----------------------------------------------------------------------------
 */

/**
 * Adresses du plan du site : la liste (s'il y a des profils) et les profils indexables.
 *
 * @return array<int,array{loc:string}>
 */
function urls_plan_contributeurs(): array {
	$profils = contributeurs_publics();
	if ( ! $profils ) {
		return array();
	}
	$urls = array( array( 'loc' => url_contributeurs() ) );
	foreach ( $profils as $user ) {
		if ( profil_indexable( (int) $user->ID ) ) {
			$urls[] = array( 'loc' => url_profil_public( (int) $user->ID ) );
		}
	}
	return $urls;
}

/**
 * Fournisseur « contributeurs » du plan du site de WordPress (wp-sitemap-contributeurs-1.xml).
 */
function enregistrer_plan_contributeurs(): void {
	if ( ! class_exists( '\WP_Sitemaps_Provider' ) || ! function_exists( 'wp_register_sitemap_provider' ) ) {
		return;
	}
	wp_register_sitemap_provider(
		'contributeurs',
		new class() extends \WP_Sitemaps_Provider {
			/**
			 * Fournisseur sans sous-type.
			 */
			public function __construct() {
				$this->name        = 'contributeurs';
				$this->object_type = 'contributeurs';
			}

			/**
			 * Adresses d'une page du plan.
			 *
			 * @param int    $page_num       Page.
			 * @param string $object_subtype Sous-type (aucun).
			 * @return array<int,array{loc:string}>
			 */
			public function get_url_list( $page_num, $object_subtype = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature du parent.
				return 1 === (int) $page_num ? urls_plan_contributeurs() : array();
			}

			/**
			 * Nombre de pages du plan.
			 *
			 * @param string $object_subtype Sous-type (aucun).
			 * @return int
			 */
			public function get_max_num_pages( $object_subtype = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature du parent.
				return urls_plan_contributeurs() ? 1 : 0;
			}
		}
	);
}
add_action( 'wp_sitemaps_init', __NAMESPACE__ . '\\enregistrer_plan_contributeurs' );

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/profil-contributeur
 * -----------------------------------------------------------------------------
 */

/**
 * Enregistre le bloc.
 */
function enregistrer_bloc_profil(): void {
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/profil-contributeur' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc_profil' );

/**
 * Mois et année (« mars 2025 ») d'une valeur « AAAA-MM », ou ''.
 *
 * @param string $valeur Valeur.
 */
function mois_annee( string $valeur ): string {
	if ( '' === arrivee_valide( $valeur ) ) {
		return '';
	}
	return wp_date( 'F Y', (int) strtotime( $valeur . '-15 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
}

/**
 * Rendu du bloc yume/profil-contributeur.
 *
 * - mode « auto » (défaut) : profil sur /contributeurs/{slug}/, liste ailleurs (avec le titre
 *   de la page en <h1> sur /contributeurs/) ;
 * - mode « liste » : liste des profils publics (rien s'il n'y en a aucun hors de /contributeurs/).
 *
 * @param array $attributs Attributs (mode, titre).
 */
function rendu_profil_contributeur( array $attributs = array() ): string {
	$mode = 'liste' === ( $attributs['mode'] ?? 'auto' ) ? 'liste' : 'auto';
	if ( 'auto' === $mode && est_route_contributeurs() ) {
		$user = contributeur_demande();
		if ( $user instanceof \WP_User ) {
			return rendu_profil( $user );
		}
		if ( false === $user ) {
			return '';
		}
		return rendu_liste_contributeurs( true, '' );
	}
	if ( apercu_editeur() ) {
		return rendu_apercu( 'yn-contributeurs', __( 'Profils publics des contributeurs : liste sur les pages, profil sur /contributeurs/{pseudo}/.', 'yume-core' ) );
	}
	$titre = isset( $attributs['titre'] ) && is_string( $attributs['titre'] ) ? $attributs['titre'] : __( 'Profils des contributeurs', 'yume-core' );
	return rendu_liste_contributeurs( false, $titre );
}

/**
 * Liste des profils publics.
 *
 * @param bool   $page  Page /contributeurs/ (titre en <h1>, message si la liste est vide).
 * @param string $titre Titre de la section hors de /contributeurs/ (<h2>).
 */
function rendu_liste_contributeurs( bool $page, string $titre ): string {
	$profils = contributeurs_publics();
	if ( ! $profils && ! $page ) {
		return '';
	}
	$html = '<section ' . attributs_racine( 'yn-contributeurs', array( 'aria-labelledby' => 'yn-contributeurs-titre' ) ) . '>';
	if ( $page ) {
		$html .= '<header class="yn-contributeurs__entete"><p class="yn-label">' . esc_html__( 'L’équipe Yume Novel', 'yume-core' ) . '</p>'
			. '<h1 class="yn-contributeurs__titre" id="yn-contributeurs-titre">' . esc_html__( 'Contributeurs', 'yume-core' ) . '</h1>'
			. '<p class="yn-muted">' . esc_html__( 'Les membres de l’équipe qui ont choisi de présenter leur travail.', 'yume-core' ) . '</p></header>';
	} else {
		$html .= '<h2 class="yn-contributeurs__titre" id="yn-contributeurs-titre">' . esc_html( '' !== $titre ? $titre : __( 'Profils des contributeurs', 'yume-core' ) ) . '</h2>';
	}
	if ( ! $profils ) {
		$html .= '<p class="yn-card yn-contributeurs__vide">' . esc_html__( 'Aucun membre de l’équipe n’a encore rendu son profil public.', 'yume-core' ) . '</p>';
	} else {
		$html .= '<ul class="yn-contributeurs__liste">';
		foreach ( $profils as $user ) {
			$nombre = nombre_tomes_profil( (int) $user->ID );
			$html  .= '<li class="yn-card yn-contributeurs__carte">'
				. '<span class="yn-contributeur__avatar" aria-hidden="true">' . esc_html( initiales( (string) $user->display_name ) ) . '</span>'
				. '<span class="yn-contributeurs__texte"><a class="yn-contributeurs__nom" href="' . esc_url( url_profil_public( (int) $user->ID ) ) . '">' . esc_html( (string) $user->display_name ) . '</a>'
				. '<span class="yn-contributeurs__role">' . esc_html( libelle_role_yume( $user ) ) . '</span>'
				/* translators: %d : nombre de tomes. */
				. '<span class="yn-muted yn-contributeurs__tomes">' . esc_html( $nombre > 0 ? sprintf( _n( '%d tome publié', '%d tomes publiés', $nombre, 'yume-core' ), $nombre ) : __( 'Pas encore de tome publié', 'yume-core' ) ) . '</span>'
				. '</span></li>';
		}
		$html .= '</ul>';
	}
	return $html . '</section>';
}

/**
 * Profil d'un contributeur (compte au profil public).
 *
 * @param \WP_User $user Compte.
 */
function rendu_profil( \WP_User $user ): string {
	$user_id  = (int) $user->ID;
	$nom      = (string) $user->display_name;
	$bio      = (string) get_user_meta( $user_id, META_PROFIL_BIO, true );
	$liens    = liens_profil( $user_id );
	$arrivee  = mois_annee( (string) get_user_meta( $user_id, META_PROFIL_ARRIVEE, true ) );
	$oeuvres  = contributions_profil( $user_id );
	$nb_tomes = nombre_tomes_profil( $user_id );

	$html  = '<article ' . attributs_racine( 'yn-contributeur', array( 'aria-labelledby' => 'yn-contributeur-nom' ) ) . '>';
	$html .= '<nav class="yn-contributeur__ariane" aria-label="' . esc_attr__( 'Fil d’Ariane', 'yume-core' ) . '"><a href="' . esc_url( url_contributeurs() ) . '">' . esc_html__( 'Contributeurs', 'yume-core' ) . '</a> <span aria-hidden="true">›</span> <span aria-current="page">' . esc_html( $nom ) . '</span></nav>';
	$html .= '<header class="yn-contributeur__entete">'
		. '<span class="yn-contributeur__avatar yn-contributeur__avatar--grand" aria-hidden="true">' . esc_html( initiales( $nom ) ) . '</span>'
		. '<div class="yn-contributeur__identite"><p class="yn-label">' . esc_html__( 'Contributeur Yume Novel', 'yume-core' ) . '</p>'
		. '<h1 class="yn-contributeur__nom" id="yn-contributeur-nom">' . esc_html( $nom ) . '</h1>'
		. '<p class="yn-contributeur__meta"><span class="yn-chip">' . esc_html( libelle_role_yume( $user ) ) . '</span>'
		/* translators: %s : mois et année. */
		. ( '' !== $arrivee ? ' <span class="yn-muted">' . esc_html( sprintf( __( 'Dans l’équipe depuis %s', 'yume-core' ), $arrivee ) ) . '</span>' : '' )
		. '</p></div></header>';

	if ( '' !== $bio ) {
		$html .= '<div class="yn-contributeur__bio">' . wpautop( esc_html( $bio ) ) . '</div>';
	}

	$items = '';
	if ( '' !== $liens['discord'] ) {
		$items .= '<li><span class="yn-muted">' . esc_html__( 'Discord :', 'yume-core' ) . '</span> <span class="yn-contributeur__discord">' . esc_html( $liens['discord'] ) . '</span></li>';
	}
	foreach ( array(
		'x'    => __( 'X (Twitter)', 'yume-core' ),
		'site' => __( 'Site personnel', 'yume-core' ),
	) as $cle => $libelle ) {
		if ( '' !== $liens[ $cle ] ) {
			$items .= '<li><a href="' . esc_url( $liens[ $cle ] ) . '" rel="me nofollow noopener" target="_blank">' . esc_html( $libelle )
				. '<span class="yn-visually-hidden"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a></li>';
		}
	}
	if ( '' !== $items ) {
		$html .= '<ul class="yn-contributeur__liens" aria-label="' . esc_attr__( 'Liens', 'yume-core' ) . '">' . $items . '</ul>';
	}

	$html .= '<section class="yn-contributeur__travaux" aria-labelledby="yn-contributeur-travaux">'
		. '<h2 id="yn-contributeur-travaux">' . esc_html__( 'Contributions', 'yume-core' ) . '</h2>';
	if ( $oeuvres ) {
		$html .= '<p class="yn-muted">' . esc_html(
			sprintf(
				/* translators: 1 : nombre de tomes, 2 : nombre d'œuvres. */
				_n( '%1$d tome publié', '%1$d tomes publiés', $nb_tomes, 'yume-core' ) . ' · ' . _n( '%2$d œuvre', '%2$d œuvres', count( $oeuvres ), 'yume-core' ),
				$nb_tomes,
				count( $oeuvres )
			)
		) . '</p><ul class="yn-contributeur__oeuvres">';
		foreach ( $oeuvres as $oeuvre ) {
			$html .= '<li class="yn-card yn-contributeur__oeuvre"><h3><a href="' . esc_url( $oeuvre['url'] ) . '">' . esc_html( $oeuvre['titre'] ) . '</a></h3><ul class="yn-contributeur__tomes">';
			foreach ( $oeuvre['tomes'] as $tome ) {
				$html .= '<li><a href="' . esc_url( $tome['url'] ) . '">' . esc_html( $tome['titre'] ) . '</a> <span class="yn-muted">— ' . esc_html( implode( ', ', $tome['etapes'] ) ) . '</span></li>';
			}
			$html .= '</ul></li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-muted">' . esc_html__( 'Aucun tome publié pour le moment : ses premières contributions arrivent bientôt.', 'yume-core' ) . '</p>';
	}
	$html .= '</section>';
	$html .= '<p class="yn-contributeur__retour"><a href="' . esc_url( url_contributeurs() ) . '"><span aria-hidden="true">←</span> ' . esc_html__( 'Tous les contributeurs', 'yume-core' ) . '</a></p>';
	return $html . '</article>';
}

/*
 * -----------------------------------------------------------------------------
 * Page « L'équipe » : profils publics et lien vers le recrutement
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse de la page « Rejoindre l'équipe » (clé rejoindre de yume_pages) si elle est en ligne.
 */
function url_page_rejoindre(): string {
	$pages = get_option( 'yume_pages', array() );
	$id    = is_array( $pages ) ? absint( $pages['rejoindre'] ?? 0 ) : 0;
	return $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
}

/**
 * La page affichée est-elle « L'équipe » (page racine « lequipe » du menu Yume Novel) ?
 *
 * @param int $post_id Page rendue par le bloc post-content.
 */
function est_page_lequipe( int $post_id ): bool {
	$page = get_post( $post_id );
	return $page instanceof \WP_Post && 'page' === $page->post_type && 'lequipe' === $page->post_name && 0 === (int) $page->post_parent
		&& (int) get_queried_object_id() === $post_id;
}

/**
 * Contenu de la page « L'équipe » : les mentions « Poste à pourvoir » mènent à la page de
 * recrutement, et les profils publics (bloc yume/profil-contributeur, mode liste) suivent le
 * contenu, avec un lien « Rejoindre l'équipe ».
 *
 * @param string    $contenu  Rendu du bloc core/post-content.
 * @param array     $bloc     Bloc analysé.
 * @param \WP_Block $instance Instance.
 * @return string
 */
function completer_page_lequipe( $contenu, $bloc = array(), $instance = null ) {
	$post_id = $instance instanceof \WP_Block ? (int) ( $instance->context['postId'] ?? 0 ) : 0;
	if ( ! is_string( $contenu ) || '' === $contenu || ! $post_id || ! est_page_lequipe( $post_id ) ) {
		return $contenu;
	}
	$rejoindre = url_page_rejoindre();
	if ( '' !== $rejoindre ) {
		$contenu = (string) preg_replace_callback(
			'#(<(?:em|p|span|strong)[^>]*>)(Poste à pourvoir[^<]*)(</)#u',
			static function ( array $m ) use ( $rejoindre ): string {
				return $m[1] . '<a href="' . esc_url( $rejoindre ) . '">' . $m[2] . '</a>' . $m[3];
			},
			$contenu
		);
	}
	$ajout = rendu_liste_contributeurs( false, __( 'Profils des contributeurs', 'yume-core' ) );
	if ( '' !== $rejoindre ) {
		$ajout .= '<p class="yn-contributeurs__rejoindre"><a class="yn-btn yn-btn--primary" href="' . esc_url( $rejoindre ) . '">' . esc_html__( 'Rejoindre l’équipe', 'yume-core' ) . '</a></p>';
	}
	if ( '' === $ajout ) {
		return $contenu;
	}
	wp_enqueue_style( 'yume-profil-contributeur-style' );
	$fin = strrpos( $contenu, '</div>' );
	return false === $fin ? $contenu . $ajout : substr( $contenu, 0, $fin ) . $ajout . substr( $contenu, $fin );
}
add_filter( 'render_block_core/post-content', __NAMESPACE__ . '\\completer_page_lequipe', 10, 3 );

/*
 * -----------------------------------------------------------------------------
 * Page compte : rubrique « Profil public » (membres de l'équipe)
 * -----------------------------------------------------------------------------
 */

/**
 * Ajoute la rubrique « Profil public » après « Profil et sécurité » pour un membre de l'équipe.
 *
 * @param array<string,string> $rubriques Rubriques (ancre => libellé).
 * @param int                  $user_id   Compte.
 * @return array<string,string>
 */
function rubriques_profil_public( array $rubriques, int $user_id ): array {
	if ( ! membre_equipe_profil( $user_id ) ) {
		return $rubriques;
	}
	$sortie = array();
	foreach ( $rubriques as $ancre => $libelle ) {
		$sortie[ $ancre ] = $libelle;
		if ( 'yn-profil' === $ancre ) {
			$sortie['yn-profil-public'] = __( 'Profil public', 'yume-core' );
		}
	}
	if ( ! isset( $sortie['yn-profil-public'] ) ) {
		$sortie['yn-profil-public'] = __( 'Profil public', 'yume-core' );
	}
	return $sortie;
}

/**
 * Messages du formulaire « Profil public » (paramètre yn-msg).
 *
 * @return array<string,array{0:string,1:string}>
 */
function messages_profil_public(): array {
	return array(
		'profil-public-active' => array( 'succes', __( 'Votre profil public est en ligne.', 'yume-core' ) ),
		'profil-public-ok'     => array( 'succes', __( 'Profil public enregistré.', 'yume-core' ) ),
		'profil-public-retire' => array( 'succes', __( 'Votre profil public est retiré : son adresse ne mène plus nulle part.', 'yume-core' ) ),
		'profil-public-pseudo' => array( 'erreur', __( 'Profil non publié : votre pseudo est identique à votre identifiant de connexion. Choisissez un autre pseudo dans « Profil et sécurité », puis réessayez.', 'yume-core' ) ),
		'profil-public-lien'   => array( 'erreur', __( 'Un lien n’a pas été retenu : indiquez une adresse complète en http ou https (pour X, une adresse x.com ou votre @pseudo).', 'yume-core' ) ),
		'profil-public-refuse' => array( 'erreur', __( 'Le profil public est réservé aux membres de l’équipe.', 'yume-core' ) ),
	);
}

/**
 * Rubrique « Profil public » de la page compte ('' hors de l'équipe).
 *
 * @param \WP_User $user Compte connecté.
 */
function section_profil_public( \WP_User $user ): string {
	$user_id = (int) $user->ID;
	if ( ! membre_equipe_profil( $user_id ) ) {
		return '';
	}
	wp_enqueue_style( 'yume-profil-contributeur-style' );
	$actif   = profil_public_actif( $user_id );
	$bio     = (string) get_user_meta( $user_id, META_PROFIL_BIO, true );
	$liens   = liens_profil( $user_id );
	$arrivee = arrivee_valide( get_user_meta( $user_id, META_PROFIL_ARRIVEE, true ) );
	$revele  = pseudo_revele_identifiant( $user );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage d'un code connu.
	$codes    = isset( $_GET['yn-msg'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['yn-msg'] ) ) ) : array();
	$connus   = messages_profil_public();
	$messages = array();
	foreach ( array_slice( $codes, 0, 5 ) as $code ) {
		$code = sanitize_key( $code );
		if ( isset( $connus[ $code ] ) ) {
			$messages[] = $connus[ $code ];
		}
	}

	$html  = debut_section( 'yn-profil-public', __( 'Profil public', 'yume-core' ), __( 'Facultatif, désactivé par défaut', 'yume-core' ) );
	$html .= html_messages( $messages );
	$html .= '<div class="yn-card yn-account__bloc yn-profil-public__explication">'
		. '<p>' . esc_html__( 'Vous pouvez présenter votre travail sur une page publique, visible de tous et référencée par les moteurs de recherche. Elle affichera :', 'yume-core' ) . '</p><ul>'
		/* translators: 1 : pseudo, 2 : rôle dans l'équipe. */
		. '<li>' . esc_html( sprintf( __( 'votre pseudo (%1$s) et votre rôle dans l’équipe (%2$s) ;', 'yume-core' ), $user->display_name, libelle_role_yume( $user ) ) ) . '</li>'
		. '<li>' . esc_html__( 'votre présentation, vos liens et votre date d’arrivée, si vous les renseignez ;', 'yume-core' ) . '</li>'
		. '<li>' . esc_html__( 'les tomes publiés dont vous êtes responsable dans le planning (traduction, relecture, édition), avec leur œuvre.', 'yume-core' ) . '</li>'
		. '</ul><p class="yn-muted">' . esc_html__( 'Jamais votre identifiant de connexion, votre adresse e-mail ni les tomes en préparation. Décocher la case retire la page immédiatement.', 'yume-core' ) . '</p>';
	if ( $actif ) {
		$url   = url_profil_public( $user_id );
		$html .= '<p><span class="yn-chip yn-chip--ok"><span aria-hidden="true">●</span> ' . esc_html__( 'Profil en ligne', 'yume-core' ) . '</span> <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
	}
	if ( $revele ) {
		$html .= '<p class="yn-avis yn-avis--info">' . esc_html__( 'Votre pseudo est identique à votre identifiant de connexion : pour ne pas le rendre public, choisissez d’abord un autre pseudo dans « Profil et sécurité ».', 'yume-core' ) . '</p>';
	}
	$html .= '</div>';

	$html .= '<form class="yn-card yn-account__bloc yn-account__formulaire yn-profil-public" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="' . esc_attr( ACTION_PROFIL_PUBLIC ) . '">'
		. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
		. champ_nonce( ACTION_PROFIL_PUBLIC )
		. '<ul class="yn-account__interrupteurs"><li class="yn-account__interrupteur"><label for="yn-profil-public-case"><span class="yn-account__interrupteur-texte">' . esc_html__( 'Afficher mon profil public', 'yume-core' )
		. '<span class="yn-muted" id="yn-profil-public-case-aide">' . esc_html__( 'Page /contributeurs/ à votre pseudo, listée sur la page « L’équipe ».', 'yume-core' ) . '</span></span>'
		. '<input type="checkbox" role="switch" class="yn-interrupteur" id="yn-profil-public-case" name="yn_public" value="1" aria-describedby="yn-profil-public-case-aide"' . checked( $actif, true, false ) . ( $revele && ! $actif ? ' disabled' : '' ) . '></label></li></ul>'
		. '<p class="yn-account__champ yn-profil-public__bio"><label for="yn-profil-bio">' . esc_html__( 'Présentation', 'yume-core' ) . '</label>'
		. '<textarea id="yn-profil-bio" name="yn_bio" rows="4" maxlength="' . esc_attr( (string) BIO_PROFIL_MAX ) . '" aria-describedby="yn-profil-bio-aide">' . esc_textarea( $bio ) . '</textarea>'
		/* translators: %d : nombre de caractères. */
		. '<span class="yn-muted yn-account__note" id="yn-profil-bio-aide">' . esc_html( sprintf( __( '%d caractères au plus, texte simple.', 'yume-core' ), BIO_PROFIL_MAX ) ) . '</span></p>'
		. '<div class="yn-account__champs">'
		. '<p class="yn-account__champ"><label for="yn-profil-discord">' . esc_html__( 'Pseudo Discord', 'yume-core' ) . '</label>'
		. '<input type="text" id="yn-profil-discord" name="yn_discord" value="' . esc_attr( $liens['discord'] ) . '" maxlength="37" autocomplete="off" spellcheck="false"></p>'
		. '<p class="yn-account__champ"><label for="yn-profil-x">' . esc_html__( 'Compte X (Twitter)', 'yume-core' ) . '</label>'
		. '<input type="text" id="yn-profil-x" name="yn_x" value="' . esc_attr( $liens['x'] ) . '" placeholder="@pseudo" autocomplete="off" spellcheck="false" aria-describedby="yn-profil-x-aide">'
		. '<span class="yn-muted yn-account__note" id="yn-profil-x-aide">' . esc_html__( '@pseudo ou adresse x.com.', 'yume-core' ) . '</span></p>'
		. '<p class="yn-account__champ"><label for="yn-profil-site">' . esc_html__( 'Site personnel', 'yume-core' ) . '</label>'
		. '<input type="url" id="yn-profil-site" name="yn_site" value="' . esc_attr( $liens['site'] ) . '" placeholder="https://…" autocomplete="url"></p>'
		. '<p class="yn-account__champ"><label for="yn-profil-arrivee">' . esc_html__( 'Dans l’équipe depuis', 'yume-core' ) . '</label>'
		. '<input type="month" id="yn-profil-arrivee" name="yn_arrivee" value="' . esc_attr( $arrivee ) . '" min="2000-01" aria-describedby="yn-profil-arrivee-aide">'
		. '<span class="yn-muted yn-account__note" id="yn-profil-arrivee-aide">' . esc_html__( 'Mois et année (AAAA-MM), facultatif.', 'yume-core' ) . '</span></p>'
		. '</div>'
		. '<div class="yn-account__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer le profil public', 'yume-core' ) . '</button></div>'
		. '</form>';
	return $html . '</section>';
}

/**
 * Formulaire « Profil public » (admin-post.php, nonce, membre de l'équipe connecté).
 */
function traiter_profil_public(): void {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( ACTION_PROFIL_PUBLIC, $retour, 'yn-profil-public' );
	$codes = enregistrer_profil_public(
		get_current_user_id(),
		array(
			'public'  => '' !== champ_post( 'yn_public' ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par exiger_nonce() ; assaini par bio_valide().
			'bio'     => isset( $_POST['yn_bio'] ) && is_string( $_POST['yn_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['yn_bio'] ) ) : '',
			'discord' => champ_post( 'yn_discord' ),
			'x'       => champ_post( 'yn_x' ),
			'site'    => champ_post( 'yn_site' ),
			'arrivee' => champ_post( 'yn_arrivee' ),
		)
	);
	rediriger( $retour, $codes, 'yn-profil-public' );
}
add_action( 'admin_post_' . ACTION_PROFIL_PUBLIC, __NAMESPACE__ . '\\traiter_profil_public' );

/*
 * -----------------------------------------------------------------------------
 * Données personnelles (outil d'export de WordPress)
 * -----------------------------------------------------------------------------
 */

/**
 * Exportateur « Profil public Yume Novel ».
 *
 * @param array<string,array> $exportateurs Exportateurs.
 * @return array<string,array>
 */
function exportateur_profil_public( $exportateurs ): array {
	$exportateurs                       = is_array( $exportateurs ) ? $exportateurs : array();
	$exportateurs['yume-profil-public'] = array(
		'exporter_friendly_name' => __( 'Profil public Yume Novel', 'yume-core' ),
		'callback'               => __NAMESPACE__ . '\\exporter_profil_public',
	);
	return $exportateurs;
}
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\exportateur_profil_public' );

/**
 * Données du profil public d'une adresse e-mail.
 *
 * @param string $email Adresse.
 * @return array{data:array,done:bool}
 */
function exporter_profil_public( $email ): array {
	$user = get_user_by( 'email', (string) $email );
	if ( ! $user instanceof \WP_User || ! metadata_exists( 'user', $user->ID, META_PROFIL_PUBLIC ) ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
	$liens = liens_profil( (int) $user->ID );
	$items = array(
		array(
			'name'  => __( 'Profil public affiché', 'yume-core' ),
			'value' => profil_public_actif( (int) $user->ID ) ? __( 'oui', 'yume-core' ) : __( 'non', 'yume-core' ),
		),
		array(
			'name'  => __( 'Présentation', 'yume-core' ),
			'value' => (string) get_user_meta( $user->ID, META_PROFIL_BIO, true ),
		),
		array(
			'name'  => __( 'Pseudo Discord', 'yume-core' ),
			'value' => $liens['discord'],
		),
		array(
			'name'  => __( 'Compte X', 'yume-core' ),
			'value' => $liens['x'],
		),
		array(
			'name'  => __( 'Site personnel', 'yume-core' ),
			'value' => $liens['site'],
		),
		array(
			'name'  => __( 'Dans l’équipe depuis', 'yume-core' ),
			'value' => (string) get_user_meta( $user->ID, META_PROFIL_ARRIVEE, true ),
		),
	);
	return array(
		'data' => array(
			array(
				'group_id'    => 'yume-profil-public',
				'group_label' => __( 'Profil public Yume Novel', 'yume-core' ),
				'item_id'     => 'yume-profil-public-' . $user->ID,
				'data'        => $items,
			),
		),
		'done' => true,
	);
}
