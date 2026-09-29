<?php
/**
 * URL et routage des tomes et chapitres (§3 du contrat).
 *
 *   /oeuvres/{oeuvre}/                            œuvre (réécriture native du type, archive /oeuvres/)
 *   /oeuvres/{oeuvre}/{slug-tome}/                tome
 *   /oeuvres/{oeuvre}/{onglet}/                   sous-page de l'œuvre déclarée par le filtre
 *                                                 yume_sous_pages_oeuvre (actualités, glossaire…)
 *   /lire/{oeuvre}/{slug-tome}/{numero|slug}/     chapitre (numéro pour les chapitres, slug pour
 *                                                 les spéciaux : prologue, postface…)
 *   /lire/{oeuvre}/{slug-tome}/illustrations/     page Illustrations du tome (illustrations.php),
 *                                                 quand aucun chapitre n'occupe ce segment
 *
 * Les slugs de tomes ne sont uniques qu'au sein d'une œuvre et ceux des chapitres qu'au sein
 * d'un tome : la résolution d'une URL passe donc par l'œuvre (et le tome). Les variables de
 * requête publiques yume_route_* portées par les règles sont traduites en « p + post_type »
 * par le filtre request (requête principale) et par parse_query (toute autre WP_Query, dont
 * url_to_postid()). Une URL correcte mais non canonique (mauvais segment d'œuvre, ancien slug,
 * chapitre désigné par son slug…) est redirigée en 301 vers le permalien.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Variables de requête publiques portées par les règles de réécriture. */
const QV_OEUVRE   = 'yume_route_oeuvre';
const QV_TOME     = 'yume_route_tome';
const QV_CHAPITRE = 'yume_route_chapitre';
const QV_LIRE     = 'yume_route_lire';

/** Variable de requête publique : sous-page de l'œuvre (onglets_oeuvre()). */
const QV_ONGLET = 'yume_onglet';

/** Variable de requête privée : l'URL demandée n'est pas le permalien canonique. */
const QV_NON_CANONIQUE = 'yume_non_canonique';

/**
 * Segments réservés par WordPress après un permalien (flux, intégration, rétroliens,
 * pagination) : ils ne peuvent jamais désigner un tome ni un chapitre.
 *
 * @return string[]
 */
function segments_reserves(): array {
	return array( 'feed', 'rdf', 'rss', 'rss2', 'atom', 'embed', 'trackback', 'page', 'attachment', 'comments' );
}

/**
 * Le slug est-il réservé (segment WordPress ou « comment-page-N ») ?
 *
 * @param string $slug Slug.
 */
function slug_reserve( string $slug ): bool {
	return in_array( $slug, segments_reserves(), true ) || (bool) preg_match( '/^comment-page-[0-9]+$/', $slug );
}

/**
 * Sous-pages d'une œuvre (/oeuvres/{oeuvre}/{onglet}/), déclarées par les modules avec le filtre
 * yume_sous_pages_oeuvre (liste de slugs). Un tome ne peut pas prendre l'un de ces slugs.
 *
 * @return string[]
 */
function onglets_oeuvre(): array {
	/**
	 * Filtre les sous-pages d'une œuvre.
	 *
	 * @param string[] $slugs Slugs (minuscules, chiffres et tirets ; pas uniquement des chiffres).
	 */
	$slugs  = (array) apply_filters( 'yume_sous_pages_oeuvre', array() );
	$retenu = array();
	foreach ( $slugs as $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' !== $slug && ! preg_match( '/^[0-9]+$/', $slug ) && ! slug_reserve( $slug ) ) {
			$retenu[] = $slug;
		}
	}
	$retenu = array_values( array_unique( $retenu ) );
	sort( $retenu );
	return $retenu;
}

/**
 * Sous-page de l'œuvre affichée ('' sur la fiche elle-même ou ailleurs).
 */
function onglet_oeuvre(): string {
	$onglet = (string) get_query_var( QV_ONGLET );
	return '' !== $onglet && is_singular( CPT_OEUVRE ) && in_array( $onglet, onglets_oeuvre(), true ) ? $onglet : '';
}

/**
 * Adresse d'une sous-page d'œuvre ('' si l'œuvre n'a pas de permalien ou l'onglet est inconnu).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $onglet    Slug déclaré par yume_sous_pages_oeuvre.
 */
function url_onglet_oeuvre( int $oeuvre_id, string $onglet ): string {
	$lien = get_permalink( $oeuvre_id );
	if ( ! is_string( $lien ) || '' === $lien || ! in_array( $onglet, onglets_oeuvre(), true ) ) {
		return '';
	}
	return user_trailingslashit( trailingslashit( $lien ) . $onglet );
}

/**
 * Gabarit d'une sous-page : single-yume_oeuvre-{onglet} (thème), puis ceux de la fiche.
 *
 * @param string[] $gabarits Hiérarchie.
 * @return string[]
 */
function gabarit_onglet_oeuvre( $gabarits ) {
	$onglet = onglet_oeuvre();
	if ( '' !== $onglet && is_array( $gabarits ) ) {
		array_unshift( $gabarits, 'single-' . CPT_OEUVRE . '-' . $onglet . '.php' );
	}
	return $gabarits;
}
add_filter( 'single_template_hierarchy', __NAMESPACE__ . '\gabarit_onglet_oeuvre' );

/**
 * Règles de réécriture des tomes et chapitres (motif => requête), dans l'ordre d'évaluation.
 *
 * Les segments réservés d'une œuvre (flux, embed, trackback, pagination des commentaires,
 * pages) sont déclarés d'abord, avec le même sens que les règles natives du type, pour ne
 * jamais être pris pour un slug de tome. Les motifs n'utilisent ni « # » ni « ! », délimiteurs
 * employés par WordPress et WP-CLI pour évaluer les règles.
 *
 * @return array<string,string>
 */
function regles_reecriture(): array {
	global $wp_rewrite;
	$racine = $wp_rewrite instanceof \WP_Rewrite ? (string) $wp_rewrite->root : '';
	$seg    = '([^/]+)';
	$flux   = '(feed|rdf|rss|rss2|atom)';

	$oeuvre    = $racine . 'oeuvres/' . $seg;
	$oeuvre_qv = 'index.php?' . CPT_OEUVRE . '=$matches[1]';
	$tome      = $racine . 'oeuvres/' . $seg . '/' . $seg;
	$tome_qv   = 'index.php?' . QV_OEUVRE . '=$matches[1]&' . QV_TOME . '=$matches[2]';
	$chap      = $racine . 'lire/' . $seg . '/' . $seg . '/' . $seg;
	$chap_qv   = 'index.php?' . QV_OEUVRE . '=$matches[1]&' . QV_TOME . '=$matches[2]&' . QV_CHAPITRE . '=$matches[3]';

	$regles = array(
		// Œuvre : segments réservés.
		$oeuvre . '/feed/' . $flux . '/?$'            => $oeuvre_qv . '&feed=$matches[2]',
		$oeuvre . '/' . $flux . '/?$'                 => $oeuvre_qv . '&feed=$matches[2]',
		$oeuvre . '/embed/?$'                         => $oeuvre_qv . '&embed=true',
		$oeuvre . '/trackback/?$'                     => $oeuvre_qv . '&tb=1',
		$oeuvre . '/comment-page-([0-9]{1,})/?$'      => $oeuvre_qv . '&cpage=$matches[2]',
		$oeuvre . '/page/?([0-9]{1,})/?$'             => $oeuvre_qv . '&paged=$matches[2]',
		$oeuvre . '/([0-9]+)/?$'                      => $oeuvre_qv . '&page=$matches[2]',
		// Chapitres.
		$chap . '/feed/' . $flux . '/?$'              => $chap_qv . '&feed=$matches[4]',
		$chap . '/' . $flux . '/?$'                   => $chap_qv . '&feed=$matches[4]',
		$chap . '/embed/?$'                           => $chap_qv . '&embed=true',
		$chap . '/trackback/?$'                       => $chap_qv . '&tb=1',
		$chap . '/comment-page-([0-9]{1,})/?$'        => $chap_qv . '&cpage=$matches[4]',
		$chap . '(?:/([0-9]+))?/?$'                   => $chap_qv . '&page=$matches[4]',
		// Raccourcis /lire/{oeuvre}/{tome}/ et /lire/{oeuvre}/ : redirigés vers le tome et l'œuvre.
		$racine . 'lire/' . $seg . '/' . $seg . '/?$' => 'index.php?' . QV_OEUVRE . '=$matches[1]&' . QV_TOME . '=$matches[2]&' . QV_LIRE . '=1',
		$racine . 'lire/' . $seg . '/?$'              => 'index.php?' . QV_OEUVRE . '=$matches[1]&' . QV_LIRE . '=1',
		// Tomes.
		$tome . '/feed/' . $flux . '/?$'              => $tome_qv . '&feed=$matches[3]',
		$tome . '/' . $flux . '/?$'                   => $tome_qv . '&feed=$matches[3]',
		$tome . '/embed/?$'                           => $tome_qv . '&embed=true',
		$tome . '/trackback/?$'                       => $tome_qv . '&tb=1',
		$tome . '/comment-page-([0-9]{1,})/?$'        => $tome_qv . '&cpage=$matches[3]',
		$tome . '(?:/([0-9]+))?/?$'                   => $tome_qv . '&page=$matches[3]',
	);

	// Sous-pages de l'œuvre : avant les tomes (même forme d'adresse).
	$onglets = onglets_oeuvre();
	if ( $onglets ) {
		$motif  = $oeuvre . '/(' . implode( '|', array_map( 'preg_quote', $onglets ) ) . ')/?$';
		$regles = array_merge( array( $motif => $oeuvre_qv . '&' . QV_ONGLET . '=$matches[2]' ), $regles );
	}
	return $regles;
}

/**
 * Déclare les règles (en tête, avant les règles natives des œuvres) et les variables de requête.
 */
function ajouter_regles_reecriture(): void {
	global $wp;
	foreach ( regles_reecriture() as $motif => $requete ) {
		add_rewrite_rule( $motif, $requete, 'top' );
	}
	// Déclaration directe : url_to_postid() filtre sur $wp->public_query_vars sans appliquer query_vars.
	if ( $wp instanceof \WP ) {
		foreach ( array( QV_OEUVRE, QV_TOME, QV_CHAPITRE, QV_LIRE, QV_ONGLET ) as $qv ) {
			$wp->add_query_var( $qv );
		}
	}
}

/**
 * Ajoute les variables de requête publiques (requêtes principales).
 *
 * @param string[] $vars Variables publiques.
 * @return string[]
 */
function filtre_query_vars( array $vars ): array {
	return array_values( array_unique( array_merge( $vars, array( QV_OEUVRE, QV_TOME, QV_CHAPITRE, QV_LIRE, QV_ONGLET ) ) ) );
}
add_filter( 'query_vars', __NAMESPACE__ . '\\filtre_query_vars' );

/**
 * Vide les règles de réécriture quand les règles Yume ont changé (mise à jour du plugin,
 * développement), sans attendre une réactivation.
 */
function verifier_regles(): void {
	global $wp_rewrite;
	if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
		return;
	}
	vider_regles_si_changees( 'yume_core_regles', regles_reecriture(), 'oeuvres' );
}
add_action( 'init', __NAMESPACE__ . '\\verifier_regles', 100 );

/**
 * Vide les règles de réécriture quand celles d'un module Yume ont changé (signature gardée dans
 * $option : règles + version) ou manquent en base (première règle absente de rewrite_rules :
 * un vidage fait sans l'extension chargée, par exemple celui que WordPress lance au premier
 * chargement après un changement de thème, les a effacées sans changer la signature).
 *
 * La signature n'est enregistrée qu'une fois les règles réellement écrites : WordPress reporte
 * le vidage à wp_loaded, qu'une requête arrêtée plus tôt n'atteint pas. Sans ces deux gardes,
 * /contributeurs/, /listes/… ou /oeuvres/{o}/actualites/ restaient en 404 jusqu'à la version
 * suivante.
 *
 * @param string   $option Option qui garde la signature.
 * @param string[] $regles Règles du module (motif => requête), dans l'ordre de déclaration.
 * @param string   $nom    Nom du module (entre dans la signature).
 */
function vider_regles_si_changees( string $option, array $regles, string $nom ): void {
	$signature = md5( (string) wp_json_encode( $regles ) . '|' . $nom . '|' . YUME_CORE_VERSION );
	$en_base   = get_option( 'rewrite_rules' );
	// Option vide : WordPress la régénère à la demande, avec les règles déclarées.
	$presentes = ! is_array( $en_base ) || array() === $en_base || array() === $regles || isset( $en_base[ (string) array_key_first( $regles ) ] );
	if ( $presentes && get_option( $option ) === $signature ) {
		return;
	}
	flush_rewrite_rules( false );
	$GLOBALS['yume_signatures_regles'][ $option ] = $signature;
	if ( did_action( 'wp_loaded' ) ) {
		enregistrer_signatures_regles();
	} else {
		add_action( 'wp_loaded', __NAMESPACE__ . '\\enregistrer_signatures_regles', 99 );
	}
}

/**
 * Enregistre les signatures en attente, après le vidage des règles (wp_loaded, priorité 10).
 */
function enregistrer_signatures_regles(): void {
	foreach ( (array) ( $GLOBALS['yume_signatures_regles'] ?? array() ) as $option => $signature ) {
		update_option( $option, $signature, true );
	}
	$GLOBALS['yume_signatures_regles'] = array();
}

/*
 * -----------------------------------------------------------------------------
 * Permaliens
 * -----------------------------------------------------------------------------
 */

/**
 * Segment d'URL d'un chapitre dans son tome : son numéro pour un chapitre numéroté
 * (« 3 », « 12.5 »), son slug pour un chapitre spécial (prologue, postface…).
 *
 * @param \WP_Post $chapitre  Chapitre.
 * @param bool     $leavename Garder le jeton %yume_chapitre% à la place du slug.
 */
function segment_chapitre( \WP_Post $chapitre, bool $leavename = false ): string {
	$numero = numero_segment( $chapitre );
	if ( null !== $numero ) {
		$tome_id  = (int) get_post_meta( $chapitre->ID, 'yume_tome_id', true );
		$segments = $tome_id ? segments_tome( $tome_id ) : array();
		// Numéro déjà porté par un autre chapitre du tome : ce chapitre-ci est adressé par son slug.
		if ( ! isset( $segments[ $chapitre->ID ] ) || $segments[ $chapitre->ID ] === $numero ) {
			return $numero;
		}
	}
	if ( $leavename ) {
		return '%' . CPT_CHAPITRE . '%';
	}
	return (string) $chapitre->post_name;
}

/**
 * Numéro d'URL d'un chapitre numéroté (« 3 », « 12.5 »), ou null pour un chapitre spécial.
 *
 * @param \WP_Post $chapitre Chapitre.
 */
function numero_segment( \WP_Post $chapitre ): ?string {
	$nature = (string) get_post_meta( $chapitre->ID, 'yume_nature', true );
	$numero = numero_ou_null( get_post_meta( $chapitre->ID, 'yume_numero', true ) );
	if ( ( '' === $nature || 'chapitre' === $nature ) && null !== $numero && $numero >= 0 ) {
		return numero_url( $numero );
	}
	return null;
}

/**
 * Segments d'URL des chapitres d'un tome : ID => segment.
 *
 * Deux chapitres ne partagent jamais une adresse : quand plusieurs chapitres du tome portent le
 * même numéro, le premier (publié d'abord, puis le plus ancien) garde le numéro et les autres
 * sont adressés par leur slug (/lire/…/chapitre-3-2/). Calculé avec une requête sur les
 * chapitres et l'amorçage de leurs caches, puis mis en cache pour la requête en cours.
 *
 * @param int $tome_id Tome.
 * @return array<int,string>
 */
function segments_tome( int $tome_id ): array {
	$cache = &cache_segments();
	$cle   = $tome_id . ':' . wp_cache_get_last_changed( 'posts' );
	if ( ! isset( $cache[ $cle ] ) ) {
		$cache[ $cle ] = calculer_segments( $tome_id > 0 ? ids_par_meta( CPT_CHAPITRE, 'yume_tome_id', $tome_id, statuts_actifs() ) : array() );
	}
	return $cache[ $cle ];
}

/**
 * Cache (requête en cours) des segments d'URL par tome : « tome:last_changed » => segments.
 *
 * @return array<string,array<int,string>>
 */
function &cache_segments(): array {
	static $cache = array();
	if ( count( $cache ) > 200 ) {
		$cache = array();
	}
	return $cache;
}

/**
 * Segments d'URL d'une liste ordonnée (menu_order, ID) de chapitres d'un même tome.
 *
 * @param int[] $ids Chapitres.
 * @return array<int,string>
 */
function calculer_segments( array $ids ): array {
	if ( $ids ) {
		_prime_post_caches( $ids, false, true );
	}
	$posts = array_values( array_filter( array_map( 'get_post', $ids ) ) );
	// Ordre d'attribution des numéros : publiés d'abord, puis par date, puis par ID.
	usort(
		$posts,
		static function ( \WP_Post $a, \WP_Post $b ): int {
			$pa = 'publish' === $a->post_status ? 0 : 1;
			$pb = 'publish' === $b->post_status ? 0 : 1;
			if ( $pa !== $pb ) {
				return $pa <=> $pb;
			}
			$cmp = strcmp( (string) $a->post_date_gmt, (string) $b->post_date_gmt );
			return 0 !== $cmp ? $cmp : ( $a->ID <=> $b->ID );
		}
	);
	$segments = array();
	$pris     = array();
	foreach ( $posts as $post ) {
		$numero = numero_segment( $post );
		if ( null !== $numero && ( ! isset( $pris[ $numero ] ) || '' === (string) $post->post_name ) ) {
			$pris[ $numero ]       = true;
			$segments[ $post->ID ] = $numero;
		} else {
			$segments[ $post->ID ] = (string) $post->post_name;
		}
	}
	// Ordre des IDs de ids_par_meta() conservé (menu_order, ID).
	$ordonnes = array();
	foreach ( $ids as $id ) {
		if ( isset( $segments[ $id ] ) ) {
			$ordonnes[ $id ] = $segments[ $id ];
		}
	}
	return $ordonnes;
}

/**
 * Calcule en une requête (et un amorçage des caches) les segments d'URL de plusieurs tomes,
 * avant l'affichage d'une liste de liens vers leurs chapitres (fiche d'œuvre, accueil) : sans
 * cela, chaque premier permalien de chapitre d'un tome coûte une requête et un amorçage.
 *
 * @param int[] $tome_ids Tomes.
 */
function amorcer_segments( array $tome_ids ): void {
	global $wpdb;
	$cache    = &cache_segments();
	$version  = wp_cache_get_last_changed( 'posts' );
	$tome_ids = array_values(
		array_filter(
			array_unique( array_map( 'intval', $tome_ids ) ),
			static fn( int $id ): bool => $id > 0 && ! isset( $cache[ $id . ':' . $version ] )
		)
	);
	if ( ! $tome_ids ) {
		return;
	}
	$statuts = statuts_actifs();
	$sql     = "SELECT p.ID, m.meta_value AS tome FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_tome_id'"
		. ' WHERE p.post_type = %s AND m.meta_value IN (' . implode( ', ', array_fill( 0, count( $tome_ids ), '%s' ) ) . ')'
		. ' AND p.post_status IN (' . implode( ', ', array_fill( 0, count( $statuts ), '%s' ) ) . ') ORDER BY p.menu_order ASC, p.ID ASC';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes   = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( CPT_CHAPITRE ), array_map( 'strval', $tome_ids ), $statuts ) ), ARRAY_A );
	$par_tome = array_fill_keys( $tome_ids, array() );
	foreach ( $lignes as $ligne ) {
		$par_tome[ (int) $ligne['tome'] ][] = (int) $ligne['ID'];
	}
	_prime_post_caches( array_map( 'intval', wp_list_pluck( $lignes, 'ID' ) ), false, true );
	foreach ( $par_tome as $tome_id => $ids ) {
		$cache[ $tome_id . ':' . $version ] = calculer_segments( array_values( array_unique( $ids ) ) );
	}
}

/**
 * Construit une URL publique à partir d'un chemin relatif.
 *
 * @param string $chemin Chemin sans barre initiale.
 */
function url_depuis_chemin( string $chemin ): string {
	global $wp_rewrite;
	return home_url( user_trailingslashit( $wp_rewrite->root . $chemin ) );
}

/**
 * Permalien joli d'un tome, ou chaîne vide si on ne peut pas le construire (lien simple).
 *
 * @param \WP_Post $tome      Tome.
 * @param bool     $leavename Garder le jeton %yume_tome%.
 * @param bool     $sample    Permalien d'exemple (éditeur).
 */
function permalien_tome( \WP_Post $tome, bool $leavename = false, bool $sample = false ): string {
	global $wp_rewrite;
	if ( ! $wp_rewrite->using_permalinks() || ( wp_force_plain_post_permalink( $tome ) && ! $sample ) ) {
		return '';
	}
	$oeuvre = slug_oeuvre( (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true ), $sample );
	$slug   = $leavename ? '%' . CPT_TOME . '%' : (string) $tome->post_name;
	if ( '' === $oeuvre || '' === $slug ) {
		return '';
	}
	return url_depuis_chemin( 'oeuvres/' . $oeuvre . '/' . $slug );
}

/**
 * Permalien joli d'un chapitre, ou chaîne vide.
 *
 * @param \WP_Post $chapitre  Chapitre.
 * @param bool     $leavename Garder le jeton %yume_chapitre% (chapitres spéciaux).
 * @param bool     $sample    Permalien d'exemple (éditeur).
 */
function permalien_chapitre( \WP_Post $chapitre, bool $leavename = false, bool $sample = false ): string {
	global $wp_rewrite;
	if ( ! $wp_rewrite->using_permalinks() || ( wp_force_plain_post_permalink( $chapitre ) && ! $sample ) ) {
		return '';
	}
	$tome = get_post( (int) get_post_meta( $chapitre->ID, 'yume_tome_id', true ) );
	if ( ! $tome || CPT_TOME !== $tome->post_type ) {
		return '';
	}
	$oeuvre_id = (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true );
	if ( ! $oeuvre_id ) {
		$oeuvre_id = (int) get_post_meta( $chapitre->ID, 'yume_oeuvre_id', true );
	}
	$oeuvre = slug_oeuvre( $oeuvre_id, $sample );
	$slug_t = (string) $tome->post_name;
	if ( '' === $slug_t && $sample ) {
		$slug_t = sanitize_title( $tome->post_title, (string) $tome->ID );
	}
	$segment = segment_chapitre( $chapitre, $leavename );
	if ( '' === $oeuvre || '' === $slug_t || '' === $segment ) {
		return '';
	}
	return url_depuis_chemin( 'lire/' . $oeuvre . '/' . $slug_t . '/' . $segment );
}

/**
 * Filtre post_type_link : permaliens des tomes et chapitres.
 *
 * @param string   $lien      Lien calculé par WordPress.
 * @param \WP_Post $post      Contenu.
 * @param bool     $leavename Garder le jeton de slug.
 * @param bool     $sample    Permalien d'exemple.
 */
function filtre_post_type_link( $lien, $post, $leavename = false, $sample = false ) {
	if ( ! $post instanceof \WP_Post ) {
		return $lien;
	}
	if ( CPT_TOME === $post->post_type ) {
		$joli = permalien_tome( $post, (bool) $leavename, (bool) $sample );
		return '' !== $joli ? $joli : $lien;
	}
	if ( CPT_CHAPITRE === $post->post_type ) {
		$joli = permalien_chapitre( $post, (bool) $leavename, (bool) $sample );
		return '' !== $joli ? $joli : $lien;
	}
	return $lien;
}
add_filter( 'post_type_link', __NAMESPACE__ . '\\filtre_post_type_link', 10, 4 );

/**
 * Les pièces jointes d'une œuvre, d'un tome ou d'un chapitre ont un lien simple
 * (?attachment_id=N) : leurs URL « enfant » entreraient en conflit avec les tomes.
 *
 * @param string $lien    Lien.
 * @param int    $post_id ID de la pièce jointe.
 */
function filtre_attachment_link( $lien, $post_id ) {
	$parent = (int) wp_get_post_parent_id( (int) $post_id );
	if ( $parent && in_array( get_post_type( $parent ), types_yume(), true ) ) {
		return home_url( '/?attachment_id=' . (int) $post_id );
	}
	return $lien;
}
add_filter( 'attachment_link', __NAMESPACE__ . '\\filtre_attachment_link', 10, 2 );

/*
 * -----------------------------------------------------------------------------
 * Résolution des requêtes
 * -----------------------------------------------------------------------------
 */

/**
 * Normalise un segment d'URL comme WordPress le fait pour les slugs.
 *
 * @param mixed $segment Segment brut.
 */
function normaliser_segment( $segment ): string {
	if ( ! is_scalar( $segment ) ) {
		return '';
	}
	return sanitize_title_for_query( rawurldecode( (string) $segment ) );
}

/**
 * Retrouve l'œuvre désignée par un segment (slug actuel ou ancien slug).
 *
 * @param string $slug Segment normalisé.
 * @return array{id:int,canonique:bool}|null
 */
function trouver_oeuvre( string $slug ): ?array {
	$ids = ids_par_slug( CPT_OEUVRE, $slug, statuts_actifs() );
	if ( $ids ) {
		return array(
			'id'        => $ids[0],
			'canonique' => true,
		);
	}
	$anciens = ids_par_ancien_slug( CPT_OEUVRE, $slug );
	if ( $anciens ) {
		return array(
			'id'        => $anciens[0],
			'canonique' => false,
		);
	}
	return null;
}

/**
 * Premier contenu visible d'une liste d'IDs (ou le premier tout court si $visibilite est faux).
 *
 * La visibilité est héritée (visibility.php) : un tome dont l'œuvre n'est pas visible, un
 * chapitre dont le tome ou l'œuvre ne l'est pas, ne sont pas consultables.
 *
 * @param int[] $ids        IDs candidats.
 * @param bool  $visibilite Exiger que l'utilisateur courant puisse le consulter.
 */
function premier_visible( array $ids, bool $visibilite ): int {
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post && ( ! $visibilite || est_consultable( $post ) ) ) {
			return (int) $post->ID;
		}
	}
	return 0;
}

/**
 * Résout un tome depuis les segments d'œuvre et de tome.
 *
 * @param string $o          Segment d'œuvre normalisé.
 * @param string $t          Segment de tome normalisé.
 * @param bool   $visibilite Exiger que le tome soit consultable (page de tome) ; faux pour
 *                           résoudre le tome parent d'un chapitre.
 * @return array{id:int,canonique:bool}|null
 */
function resoudre_tome( string $o, string $t, bool $visibilite ): ?array {
	if ( '' === $o || '' === $t ) {
		return null;
	}
	$candidats = ids_par_slug( CPT_TOME, $t, statuts_actifs() );

	// 1. Correspondance exacte : un tome de ce slug dans l'œuvre de ce slug.
	$exacts = array();
	foreach ( $candidats as $id ) {
		if ( slug_oeuvre( (int) get_post_meta( $id, 'yume_oeuvre_id', true ) ) === $o ) {
			$exacts[] = $id;
		}
	}
	if ( $exacts ) {
		// Le tome existe à cette adresse : visible ou introuvable, jamais redirigé ailleurs.
		$id = premier_visible( $exacts, $visibilite );
		return $id ? array(
			'id'        => $id,
			'canonique' => true,
		) : null;
	}

	// 2. Œuvre retrouvée (éventuellement par un ancien slug) : tome actuel ou ancien slug du tome.
	$oeuvre = trouver_oeuvre( $o );
	if ( $oeuvre ) {
		$dans_oeuvre = array();
		foreach ( $candidats as $cid ) {
			if ( (int) get_post_meta( $cid, 'yume_oeuvre_id', true ) === $oeuvre['id'] ) {
				$dans_oeuvre[] = $cid;
			}
		}
		foreach ( ids_par_ancien_slug( CPT_TOME, $t ) as $cid ) {
			if ( (int) get_post_meta( $cid, 'yume_oeuvre_id', true ) === $oeuvre['id'] ) {
				$dans_oeuvre[] = $cid;
			}
		}
		if ( $dans_oeuvre ) {
			$id = premier_visible( $dans_oeuvre, $visibilite );
			return $id ? array(
				'id'        => $id,
				'canonique' => false,
			) : null;
		}
	}

	// 3. Segment d'œuvre faux mais slug de tome publié unique sur tout le site.
	$publies = array_values(
		array_filter(
			$candidats,
			static function ( int $cid ): bool {
				return 'publish' === get_post_status( $cid );
			}
		)
	);
	if ( 1 === count( $publies ) && ( ! $visibilite || premier_visible( $publies, true ) ) ) {
		return array(
			'id'        => $publies[0],
			'canonique' => false,
		);
	}
	return null;
}

/**
 * Résout un chapitre depuis les segments d'œuvre, de tome et de chapitre.
 *
 * @param string $o Segment d'œuvre normalisé.
 * @param string $t Segment de tome normalisé.
 * @param string $c Segment de chapitre brut (numéro ou slug).
 * @return array{id:int,canonique:bool}|null
 */
function resoudre_chapitre( string $o, string $t, string $c ): ?array {
	$tome = resoudre_tome( $o, $t, false );
	if ( ! $tome ) {
		return null;
	}
	// Segments de tous les chapitres du tome, calculés en une fois (caches amorcés).
	$segments = segments_tome( $tome['id'] );
	if ( ! $segments ) {
		return null;
	}
	$chapitres = array_keys( $segments );
	$brut      = rawurldecode( $c );
	$numerique = (bool) preg_match( '/^[0-9]+(?:[.,][0-9]+)?$/', $brut );
	$segment   = $numerique ? numero_url( (float) str_replace( ',', '.', $brut ) ) : normaliser_segment( $c );
	$canonique = $tome['canonique'] && ( ! $numerique || $segment === $brut );

	// Segment canonique (numéro d'un chapitre numéroté, slug d'un chapitre spécial ou d'un
	// chapitre dont le numéro est déjà pris dans le tome).
	$exacts = array_keys( $segments, $segment, true );
	if ( $exacts ) {
		$id = premier_visible( $exacts, true );
		return $id ? array(
			'id'        => $id,
			'canonique' => $canonique,
		) : null;
	}

	// Chapitre numéroté désigné par son slug : accepté puis redirigé.
	$par_slug = array();
	foreach ( $chapitres as $cid ) {
		if ( get_post_field( 'post_name', $cid ) === $segment ) {
			$par_slug[] = $cid;
		}
	}
	$id = premier_visible( $par_slug, true );
	if ( $id ) {
		return array(
			'id'        => $id,
			'canonique' => false,
		);
	}
	return null;
}

/**
 * Résout la page Illustrations d'un tome (/lire/{oeuvre}/{tome}/illustrations/) : tome
 * consultable (hiérarchie comprise) ayant une page Illustrations, sans flux, intégration,
 * rétrolien ni pagination.
 *
 * @param string              $o  Segment d'œuvre normalisé.
 * @param string              $t  Segment de tome normalisé.
 * @param string              $c  Segment brut (« illustrations », casse ou encodage libres).
 * @param array<string,mixed> $qv Variables de requête.
 * @return array{id:int,canonique:bool}|null
 */
function resoudre_illustrations( string $o, string $t, string $c, array $qv ): ?array {
	foreach ( array( 'feed', 'embed', 'tb', 'cpage', 'page' ) as $cle ) {
		if ( ! empty( $qv[ $cle ] ) ) {
			return null;
		}
	}
	$tome = resoudre_tome( $o, $t, true );
	if ( ! $tome || ! a_page_illustrations( $tome['id'] ) ) {
		return null;
	}
	return array(
		'id'        => $tome['id'],
		'canonique' => $tome['canonique'] && SEGMENT_ILLUSTRATIONS === $c,
	);
}

/**
 * Traduit les variables yume_route_* en requête WordPress (p + post_type) ou en 404.
 *
 * @param array<string,mixed> $qv Variables de requête.
 * @return array<string,mixed>
 */
function resoudre_requete( array $qv ): array {
	$o    = normaliser_segment( $qv[ QV_OEUVRE ] ?? '' );
	$t    = isset( $qv[ QV_TOME ] ) ? normaliser_segment( $qv[ QV_TOME ] ) : '';
	$c    = isset( $qv[ QV_CHAPITRE ] ) && is_scalar( $qv[ QV_CHAPITRE ] ) ? (string) $qv[ QV_CHAPITRE ] : '';
	$lire = ! empty( $qv[ QV_LIRE ] );

	unset( $qv[ QV_OEUVRE ], $qv[ QV_TOME ], $qv[ QV_CHAPITRE ], $qv[ QV_LIRE ], $qv['error'] );

	$cible = null;
	$type  = '';
	if ( slug_reserve( $t ) || ( '' !== $c && slug_reserve( normaliser_segment( $c ) ) ) ) {
		// Segment réservé à une place de tome ou de chapitre : jamais un contenu.
		return array( 'error' => '404' );
	}
	if ( '' !== $c && '' !== $t ) {
		$cible = resoudre_chapitre( $o, $t, $c );
		$type  = CPT_CHAPITRE;
		if ( ! $cible && SEGMENT_ILLUSTRATIONS === normaliser_segment( $c ) ) {
			// Page Illustrations du tome (illustrations.php) : aucun chapitre à cette adresse.
			$cible = resoudre_illustrations( $o, $t, $c, $qv );
			if ( $cible ) {
				$type                   = CPT_TOME;
				$qv[ QV_ILLUSTRATIONS ] = 1;
			}
		}
	} elseif ( '' !== $t ) {
		$cible = resoudre_tome( $o, $t, true );
		$type  = CPT_TOME;
	} elseif ( $lire && '' !== $o ) {
		$oeuvre = trouver_oeuvre( $o );
		$post   = $oeuvre ? get_post( $oeuvre['id'] ) : null;
		if ( $oeuvre && $post && est_visible( $post ) ) {
			$cible = array(
				'id'        => $oeuvre['id'],
				'canonique' => false,
			);
			$type  = CPT_OEUVRE;
		}
	}

	if ( ! $cible ) {
		return array( 'error' => '404' );
	}

	$qv['p']         = $cible['id'];
	$qv['post_type'] = $type;
	if ( $lire || ! $cible['canonique'] ) {
		$qv[ QV_NON_CANONIQUE ] = 1;
	}
	return $qv;
}

/**
 * Filtre request : résolution de la requête principale.
 *
 * @param array<string,mixed> $qv Variables de requête.
 * @return array<string,mixed>
 */
function filtre_request( $qv ) {
	if ( is_array( $qv ) && ! empty( $qv[ QV_OEUVRE ] ) ) {
		return resoudre_requete( $qv );
	}
	return $qv;
}
add_filter( 'request', __NAMESPACE__ . '\\filtre_request', 5 );

/**
 * Sur parse_query : résolution des autres requêtes (url_to_postid(), new WP_Query( … )).
 *
 * @param \WP_Query $query Requête.
 */
function action_parse_query( $query ): void {
	if ( ! $query instanceof \WP_Query || empty( $query->query_vars[ QV_OEUVRE ] ) ) {
		return;
	}
	$vars = is_array( $query->query ) ? $query->query : array();
	$vars = array_merge( $vars, array_intersect_key( $query->query_vars, array_flip( array( QV_OEUVRE, QV_TOME, QV_CHAPITRE, QV_LIRE ) ) ) );
	// Nouvelle analyse avec les variables résolues : les drapeaux (is_single…) sont recalculés.
	$query->parse_query( resoudre_requete( $vars ) );
}
add_action( 'parse_query', __NAMESPACE__ . '\\action_parse_query', 1 );

/**
 * Redirection 301 vers le permalien quand l'URL demandée n'est pas canonique.
 * Priorité 9 : avant redirect_canonical().
 */
function redirection_canonique(): void {
	if ( ! get_query_var( QV_NON_CANONIQUE ) || is_preview() || ! is_singular() ) {
		return;
	}
	$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $methode, array( 'GET', 'HEAD' ), true ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return;
	}
	// Page Illustrations : sa propre adresse (ni flux, ni intégration, ni pagination possibles).
	$illustrations = (bool) get_query_var( QV_ILLUSTRATIONS );
	$cible         = $illustrations ? url_illustrations( (int) $post->ID ) : get_permalink( $post );
	if ( ! $cible ) {
		return;
	}
	if ( ! $illustrations ) {
		if ( is_feed() ) {
			$cible = get_post_comments_feed_link( $post->ID, (string) get_query_var( 'feed' ) );
		} elseif ( is_embed() ) {
			$cible = (string) get_post_embed_url( $post );
		} elseif ( ! str_contains( $cible, '?' ) ) {
			$page = (int) get_query_var( 'page' );
			if ( $page > 1 ) {
				$cible = trailingslashit( $cible ) . user_trailingslashit( (string) $page, 'single_paged' );
			}
			$cpage = (int) get_query_var( 'cpage' );
			if ( $cpage > 0 ) {
				$cible = trailingslashit( $cible ) . user_trailingslashit( 'comment-page-' . $cpage, 'commentpaged' );
			}
		}
	}
	$requete = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '' !== $requete ) {
		$cible .= ( str_contains( $cible, '?' ) ? '&' : '?' ) . $requete;
	}
	wp_safe_redirect( $cible, 301, 'Yume Novel' );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\redirection_canonique', 9 );

/*
 * -----------------------------------------------------------------------------
 * Unicité des slugs par œuvre (tomes) et par tome (chapitres)
 * -----------------------------------------------------------------------------
 */

/**
 * Clé de métadonnée qui délimite la portée d'unicité du slug d'un type.
 *
 * @param string $post_type Type.
 */
function cle_portee( string $post_type ): string {
	return CPT_TOME === $post_type ? 'yume_oeuvre_id' : 'yume_tome_id';
}

/**
 * Rend un slug utilisable dans les URL : jamais réservé, jamais purement numérique.
 *
 * @param string $slug      Slug.
 * @param string $post_type Type (tome ou chapitre).
 */
function slug_autorise( string $slug, string $post_type ): string {
	if ( '' === $slug ) {
		return $slug;
	}
	if ( preg_match( '/^[0-9]+$/', $slug ) ) {
		return ( CPT_TOME === $post_type ? 'tome-' : 'chapitre-' ) . $slug;
	}
	if ( slug_reserve( $slug ) || ( CPT_TOME === $post_type && in_array( $slug, onglets_oeuvre(), true ) ) ) {
		return $slug . '-2';
	}
	return $slug;
}

/**
 * Le slug est-il déjà pris dans la portée (même œuvre pour un tome, même tome pour un chapitre) ?
 *
 * @param string $slug      Slug.
 * @param int    $post_id   Contenu à exclure.
 * @param string $post_type Type.
 * @param int    $portee    ID de l'œuvre ou du tome.
 */
function slug_pris( string $slug, int $post_id, string $post_type, int $portee ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$pris = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s"
			. ' WHERE p.post_type = %s AND p.post_name = %s AND p.ID != %d AND m.meta_value = %s'
			. " AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT 1",
			cle_portee( $post_type ),
			$post_type,
			$slug,
			$post_id,
			(string) $portee
		)
	);
	return null !== $pris;
}

/**
 * Premier slug libre dans la portée : slug, slug-2, slug-3…
 *
 * @param string $slug      Slug de base.
 * @param int    $post_id   Contenu concerné.
 * @param string $post_type Type.
 * @param int    $portee    ID de l'œuvre ou du tome.
 */
function slug_unique_dans_portee( string $slug, int $post_id, string $post_type, int $portee ): string {
	$candidat = $slug;
	$suffixe  = 2;
	while ( slug_pris( $candidat, $post_id, $post_type, $portee ) ) {
		$candidat = _truncate_post_slug( $slug, 200 - ( strlen( (string) $suffixe ) + 1 ) ) . '-' . $suffixe;
		++$suffixe;
	}
	return $candidat;
}

/**
 * Filtre wp_unique_post_slug : un slug de tome n'est unique que dans son œuvre, un slug de
 * chapitre que dans son tome. Si la portée n'est pas encore connue (création où l'œuvre est
 * enregistrée après le contenu, comme dans l'API REST), le slug demandé est gardé et vérifié
 * par garantir_slug() une fois les métadonnées écrites.
 *
 * @param string $slug          Slug proposé par WordPress (unique sur tout le type).
 * @param int    $post_id       ID (0 à la création).
 * @param string $post_status   Statut.
 * @param string $post_type     Type.
 * @param int    $post_parent   Parent.
 * @param string $original_slug Slug demandé.
 */
function filtre_unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
	if ( ! in_array( $post_type, array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return $slug;
	}
	$base   = slug_autorise( (string) $original_slug, $post_type );
	$portee = $post_id ? (int) get_post_meta( (int) $post_id, cle_portee( $post_type ), true ) : 0;
	if ( ! $portee ) {
		return $base;
	}
	return slug_unique_dans_portee( $base, (int) $post_id, $post_type, $portee );
}
add_filter( 'wp_unique_post_slug', __NAMESPACE__ . '\\filtre_unique_post_slug', 10, 6 );

/**
 * Slugs d'œuvres interdits en plus de ceux de WordPress (flux, embed).
 *
 * @param bool   $mauvais   Slug refusé.
 * @param string $slug      Slug.
 * @param string $post_type Type.
 */
function filtre_slug_plat_interdit( $mauvais, $slug, $post_type ) {
	if ( CPT_OEUVRE === $post_type && slug_reserve( (string) $slug ) ) {
		return true;
	}
	return $mauvais;
}
add_filter( 'wp_unique_post_slug_is_bad_flat_slug', __NAMESPACE__ . '\\filtre_slug_plat_interdit', 10, 3 );

/**
 * Après enregistrement (métadonnées comprises) : garantit un slug présent, autorisé et unique
 * dans sa portée pour tout tome ou chapitre publié, programmé ou privé.
 *
 * @param int $post_id ID.
 */
function garantir_slug( int $post_id ): void {
	global $wpdb;
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return;
	}
	if ( ! in_array( $post->post_status, array( 'publish', 'future', 'private' ), true ) ) {
		return;
	}
	$base = (string) $post->post_name;
	if ( '' === $base ) {
		$base = sanitize_title( $post->post_title, CPT_TOME === $post->post_type ? 'tome' : 'chapitre' );
	}
	$base   = slug_autorise( $base, $post->post_type );
	$portee = (int) get_post_meta( $post->ID, cle_portee( $post->post_type ), true );
	$voulu  = $portee ? slug_unique_dans_portee( $base, (int) $post->ID, $post->post_type, $portee ) : $base;
	if ( $voulu !== $post->post_name ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->posts, array( 'post_name' => $voulu ), array( 'ID' => $post->ID ) );
		clean_post_cache( $post->ID );
	}
}

/**
 * Un tome qui passe en ligne (publié ou programmé) depuis l'administration, avec un slug vide
 * ou tiré de son titre, reçoit le slug du contrat (« tome-10 », « arc-7 ») d'après sa nature
 * et son numéro, comme une publication par le formulaire. Un tome déjà en ligne garde son adresse.
 *
 * @param array $data    Données du contenu (non échappées).
 * @param array $postarr Arguments d'origine.
 * @return array
 */
function slug_tome_a_la_mise_en_ligne( $data, $postarr ) {
	$service = '\\Yume\\Core\\Publication\\Service';
	if ( CPT_TOME !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true )
		|| ! is_callable( array( $service, 'slug_a_poser' ) ) ) {
		return $data;
	}
	$id     = (int) ( $postarr['ID'] ?? 0 );
	$actuel = $id ? get_post( $id ) : null;
	$numero = $id ? get_post_meta( $id, 'yume_numero', true ) : '';
	if ( ! $actuel instanceof \WP_Post || '' === $numero || null === $numero ) {
		return $data;
	}
	$candidat             = clone $actuel;
	$candidat->post_name  = (string) $data['post_name'];
	$candidat->post_title = wp_unslash( (string) $data['post_title'] );
	if ( $service::slug_a_poser( $candidat ) ) {
		$data['post_name'] = $service::slug_tome_existant( $id );
	}
	return $data;
}
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\\slug_tome_a_la_mise_en_ligne', 10, 2 );

/**
 * Sur wp_after_insert_post : vérification du slug des tomes et chapitres.
 *
 * @param int $post_id ID.
 */
function apres_enregistrement_slug( $post_id ): void {
	garantir_slug( (int) $post_id );
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement_slug', 5 );

/**
 * Quand la portée d'un tome ou d'un chapitre change (métadonnée écrite après coup),
 * le slug est revérifié.
 *
 * @param int    $meta_id   ID de la métadonnée.
 * @param int    $object_id ID du contenu.
 * @param string $meta_key  Clé.
 */
function portee_modifiee( $meta_id, $object_id, $meta_key ): void {
	$type = get_post_type( (int) $object_id );
	if ( ( CPT_TOME === $type && 'yume_oeuvre_id' === $meta_key ) || ( CPT_CHAPITRE === $type && 'yume_tome_id' === $meta_key ) ) {
		garantir_slug( (int) $object_id );
	}
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\portee_modifiee', 10, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\portee_modifiee', 10, 3 );
