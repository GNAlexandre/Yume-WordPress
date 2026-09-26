<?php
/**
 * Constantes et fonctions internes du module core (non destinées aux autres modules :
 * ceux-ci passent par l'API publique de api.php).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Type de contenu « œuvre ». */
const CPT_OEUVRE = 'yume_oeuvre';

/** Type de contenu « tome ». */
const CPT_TOME = 'yume_tome';

/** Type de contenu « chapitre ». */
const CPT_CHAPITRE = 'yume_chapitre';

/** Taxonomie du type d'œuvre (light novel, web novel, manga). */
const TAX_TYPE = 'yume_type';

/** Taxonomie du statut de traduction. */
const TAX_STATUT = 'yume_statut';

/** Taxonomie des genres. */
const TAX_GENRE = 'yume_genre';

/** Taxonomie reliant les articles d'actualité aux œuvres. */
const TAX_OEUVRE_LIEE = 'yume_oeuvre_liee';

/** Option des réglages. */
const OPTION_REGLAGES = 'yume_reglages';

/** Groupe de cache non persistant : état propre à la requête en cours. */
const CACHE_REQUETE = 'yume_core_requete';

/** Vitesse de lecture retenue pour le temps de lecture (mots par minute). */
const MOTS_PAR_MINUTE = 230;

/**
 * Statuts « vivants » d'un contenu (hors corbeille, brouillon automatique et révisions).
 *
 * @return string[]
 */
function statuts_actifs(): array {
	return array( 'publish', 'future', 'draft', 'pending', 'private' );
}

/**
 * Types de contenu gérés par le module.
 *
 * @return string[]
 */
function types_yume(): array {
	return array( CPT_OEUVRE, CPT_TOME, CPT_CHAPITRE );
}

/**
 * Lit une valeur de l'état propre à la requête (cache non persistant, vidé entre deux
 * requêtes et entre deux tests).
 *
 * @param string $cle     Clé.
 * @param mixed  $defaut  Valeur si absente.
 * @return mixed
 */
function etat_get( string $cle, $defaut = null ) {
	$trouve = false;
	$valeur = wp_cache_get( $cle, CACHE_REQUETE, false, $trouve );
	return $trouve ? $valeur : $defaut;
}

/**
 * Écrit une valeur de l'état propre à la requête.
 *
 * @param string $cle    Clé.
 * @param mixed  $valeur Valeur.
 */
function etat_set( string $cle, $valeur ): void {
	wp_cache_set( $cle, $valeur, CACHE_REQUETE );
}

/**
 * Ajoute un entier à une liste de l'état de requête.
 *
 * @param string $cle Clé de la liste.
 * @param int    $id  Valeur.
 */
function etat_ajouter( string $cle, int $id ): void {
	$liste = (array) etat_get( $cle, array() );
	if ( ! in_array( $id, $liste, true ) ) {
		$liste[] = $id;
	}
	etat_set( $cle, $liste );
}

/**
 * Indique si un entier figure dans une liste de l'état de requête.
 *
 * @param string $cle Clé de la liste.
 * @param int    $id  Valeur.
 */
function etat_contient( string $cle, int $id ): bool {
	return in_array( $id, (array) etat_get( $cle, array() ), true );
}

/**
 * Normalise un numéro (tome ou chapitre) : nombre flottant ou null s'il est absent.
 *
 * @param mixed $valeur Valeur brute (chaîne « 26,5 », nombre…).
 */
function numero_ou_null( $valeur ): ?float {
	if ( is_string( $valeur ) ) {
		$valeur = str_replace( ',', '.', trim( $valeur ) );
	}
	if ( '' === $valeur || null === $valeur || ! is_numeric( $valeur ) ) {
		return null;
	}
	return round( (float) $valeur, 3 );
}

/**
 * Formate un numéro pour une URL (« 3 », « 12.5 »).
 *
 * @param float $numero Numéro.
 */
function numero_url( float $numero ): string {
	if ( floor( $numero ) === $numero ) {
		return (string) (int) $numero;
	}
	return rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' );
}

/**
 * Formate un numéro pour l'affichage en français (« 3 », « 26,5 »).
 *
 * @param float $numero Numéro.
 */
function numero_fr( float $numero ): string {
	return str_replace( '.', ',', numero_url( $numero ) );
}

/**
 * Date GMT de publication d'un contenu, avec repli sur la date locale quand la date GMT
 * n'a pas été renseignée (brouillon publié par wp_publish_post()).
 *
 * @param \WP_Post $post Contenu.
 * @return int Horodatage Unix (0 si inconnu).
 */
function horodatage_gmt( \WP_Post $post ): int {
	$gmt = (string) $post->post_date_gmt;
	if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		if ( '' === (string) $post->post_date || str_starts_with( (string) $post->post_date, '0000-00-00' ) ) {
			return 0;
		}
		$gmt = get_gmt_from_date( $post->post_date );
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? 0 : $ts;
}

/**
 * Le contenu est-il consultable par l'utilisateur courant ?
 *
 * Un contenu publié est visible par tous ; un contenu privé exige le droit de lecture ;
 * un brouillon, un contenu programmé ou en attente exige le droit de modification (aperçu).
 *
 * @param \WP_Post $post Contenu.
 */
function est_visible( \WP_Post $post ): bool {
	if ( 'publish' === $post->post_status ) {
		return true;
	}
	if ( 'private' === $post->post_status ) {
		return current_user_can( 'read_post', $post->ID );
	}
	if ( in_array( $post->post_status, array( 'draft', 'future', 'pending' ), true ) ) {
		return current_user_can( 'edit_post', $post->ID );
	}
	return false;
}

/**
 * Identifiants des contenus d'un type dont une métadonnée vaut une valeur entière donnée.
 *
 * Requête SQL directe préparée, compatible MySQL et SQLite, sans passer par WP_Query
 * (utilisée par le routage et les caches, appelés très tôt).
 *
 * @param string   $post_type Type de contenu.
 * @param string   $meta_key  Clé de métadonnée.
 * @param int      $valeur    Valeur entière attendue.
 * @param string[] $statuts   Statuts acceptés.
 * @return int[]
 */
function ids_par_meta( string $post_type, string $meta_key, int $valeur, array $statuts ): array {
	global $wpdb;
	if ( ! $statuts ) {
		return array();
	}
	$marques = implode( ', ', array_fill( 0, count( $statuts ), '%s' ) );
	$sql     = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s"
		. " WHERE p.post_type = %s AND m.meta_value = %s AND p.post_status IN ($marques) ORDER BY p.menu_order ASC, p.ID ASC";
	$params  = array_merge( array( $meta_key, $post_type, (string) $valeur ), $statuts );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
	return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
}

/**
 * Identifiants des contenus d'un type portant un slug donné.
 *
 * @param string   $post_type Type de contenu.
 * @param string   $slug      Slug (post_name).
 * @param string[] $statuts   Statuts acceptés.
 * @return int[]
 */
function ids_par_slug( string $post_type, string $slug, array $statuts ): array {
	global $wpdb;
	if ( '' === $slug || ! $statuts ) {
		return array();
	}
	$marques = implode( ', ', array_fill( 0, count( $statuts ), '%s' ) );
	$sql     = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status IN ($marques) ORDER BY ID ASC LIMIT 100";
	$params  = array_merge( array( $post_type, $slug ), $statuts );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
}

/**
 * Identifiants des contenus d'un type dont un ancien slug (_wp_old_slug) vaut $slug.
 *
 * @param string $post_type Type de contenu.
 * @param string $slug      Ancien slug.
 * @return int[]
 */
function ids_par_ancien_slug( string $post_type, string $slug ): array {
	global $wpdb;
	if ( '' === $slug ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_old_slug'"
			. " WHERE p.post_type = %s AND m.meta_value = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY p.ID DESC LIMIT 20",
			$post_type,
			$slug
		)
	);
	return array_map( 'intval', (array) $ids );
}

/**
 * Nom (slug) d'une œuvre pour les URL ; repli sur le titre pour un brouillon sans slug.
 *
 * @param int  $oeuvre_id ID de l'œuvre.
 * @param bool $repli     Calculer un slug provisoire depuis le titre si post_name est vide.
 */
function slug_oeuvre( int $oeuvre_id, bool $repli = false ): string {
	$oeuvre = $oeuvre_id ? get_post( $oeuvre_id ) : null;
	if ( ! $oeuvre || CPT_OEUVRE !== $oeuvre->post_type ) {
		return '';
	}
	if ( '' !== (string) $oeuvre->post_name ) {
		return (string) $oeuvre->post_name;
	}
	return $repli ? sanitize_title( $oeuvre->post_title, (string) $oeuvre->ID ) : '';
}

/**
 * Liste des œuvres (ID => titre), tous statuts vivants, triées par titre.
 *
 * @return array<int,string>
 */
function liste_oeuvres(): array {
	$posts = get_posts(
		array(
			'post_type'        => CPT_OEUVRE,
			'post_status'      => statuts_actifs(),
			'posts_per_page'   => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);
	$liste = array();
	foreach ( $posts as $p ) {
		$liste[ (int) $p->ID ] = '' !== $p->post_title ? $p->post_title : sprintf( /* translators: %d : ID */ __( 'Œuvre sans titre (#%d)', 'yume-core' ), $p->ID );
	}
	return $liste;
}

/**
 * Membres de l'équipe (utilisateurs ayant la capacité yume_voir_equipe).
 *
 * @return array<int,string> ID => nom affiché.
 */
function membres_equipe(): array {
	$users = get_users(
		array(
			'capability' => 'yume_voir_equipe',
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'fields'     => array( 'ID', 'display_name' ),
		)
	);
	$liste = array();
	foreach ( $users as $u ) {
		$liste[ (int) $u->ID ] = (string) $u->display_name;
	}
	return $liste;
}

/**
 * Statuts WordPress correspondant à l'argument « status » de l'API.
 *
 * @param mixed $status 'publish', 'any', un statut ou une liste de statuts.
 * @return string[]
 */
function statuts_demandes( $status ): array {
	if ( 'any' === $status ) {
		return statuts_actifs();
	}
	$demandes = array_filter( array_map( 'strval', (array) $status ) );
	$valides  = array_values( array_intersect( $demandes, statuts_actifs() ) );
	return $valides ? $valides : array( 'publish' );
}

/**
 * Termes d'une taxonomie (slug => nom) dans l'ordre du contrat, termes ajoutés ensuite.
 * Avant l'installation (taxonomie vide ou inconnue), renvoie les termes du contrat.
 *
 * @param string $taxonomie Taxonomie.
 * @return array<string,string>
 */
function termes_taxonomie( string $taxonomie ): array {
	$defauts = termes_par_defaut()[ $taxonomie ] ?? array();
	if ( ! taxonomy_exists( $taxonomie ) ) {
		return $defauts;
	}
	$termes = get_terms(
		array(
			'taxonomy'   => $taxonomie,
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);
	if ( is_wp_error( $termes ) || ! $termes ) {
		return $defauts;
	}
	$par_slug = array();
	foreach ( $termes as $terme ) {
		$par_slug[ $terme->slug ] = $terme->name;
	}
	$liste = array();
	foreach ( array_keys( $defauts ) as $slug ) {
		if ( isset( $par_slug[ $slug ] ) ) {
			$liste[ $slug ] = $par_slug[ $slug ];
			unset( $par_slug[ $slug ] );
		}
	}
	return $liste + $par_slug;
}

/**
 * Termes créés à l'installation (§3), par taxonomie : slug => nom.
 *
 * @return array<string,array<string,string>>
 */
function termes_par_defaut(): array {
	return array(
		TAX_TYPE   => array(
			'light-novel' => __( 'Light novel', 'yume-core' ),
			'web-novel'   => __( 'Web novel', 'yume-core' ),
			'manga'       => __( 'Manga', 'yume-core' ),
		),
		TAX_STATUT => array(
			'en-cours'   => __( 'En cours', 'yume-core' ),
			'terminee'   => __( 'Terminée', 'yume-core' ),
			'en-pause'   => __( 'En pause', 'yume-core' ),
			'licenciee'  => __( 'Licenciée', 'yume-core' ),
			'abandonnee' => __( 'Abandonnée', 'yume-core' ),
		),
	);
}

// L'état de requête ne doit jamais être partagé entre deux requêtes (cache persistant).
wp_cache_add_non_persistent_groups( array( CACHE_REQUETE ) );
