<?php
/**
 * Visibilité héritée des tomes et chapitres, et fermeture des archives d'auteur.
 *
 * Un tome n'est consultable que si son œuvre l'est ; un chapitre, que si son tome et son œuvre
 * le sont. Dépublier une œuvre ou un tome (brouillon, privé, corbeille…) retire donc aussi ses
 * tomes et chapitres du site, sans toucher à leur propre statut (republier le parent les rend de
 * nouveau visibles) :
 *
 * - URL /oeuvres/… et /lire/… : routing.php exige la visibilité de toute la hiérarchie ;
 * - requête principale en façade (lien simple ?post_type=…&p=…, recherche, flux), blocs
 *   « Boucle de requête », REST /wp/v2/tomes, /wp/v2/chapitres (liste et élément),
 *   /wp/v2/search et plans du site : les contenus dont un parent n'est pas visible sont exclus.
 *
 * Un contenu sans parent (rattachement absent ou vers un contenu d'un autre type) garde son
 * comportement propre. Les aperçus restent possibles : un parent en brouillon est visible pour
 * qui peut le modifier (est_visible()), sauf dans les plans du site, toujours anonymes.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Visibilité d'un contenu
 * -----------------------------------------------------------------------------
 */

/**
 * Un parent (œuvre ou tome) est-il visible ?
 *
 * @param int    $id     ID du parent.
 * @param string $type   Type attendu.
 * @param bool   $anonyme Vue anonyme (statut publish exigé) plutôt que celle de l'utilisateur courant.
 */
function parent_visible( int $id, string $type, bool $anonyme ): bool {
	$parent = $id > 0 ? get_post( $id ) : null;
	if ( ! $parent instanceof \WP_Post || $type !== $parent->post_type ) {
		return true; // Pas de parent : le contenu garde son comportement propre.
	}
	return $anonyme ? 'publish' === $parent->post_status : est_visible( $parent );
}

/**
 * Les parents d'un tome (œuvre) ou d'un chapitre (tome et œuvre) sont-ils visibles ?
 *
 * @param \WP_Post $post   Contenu.
 * @param bool     $anonyme Vue anonyme plutôt que celle de l'utilisateur courant.
 */
function hierarchie_visible( \WP_Post $post, bool $anonyme = false ): bool {
	if ( CPT_TOME === $post->post_type ) {
		return parent_visible( (int) get_post_meta( $post->ID, 'yume_oeuvre_id', true ), CPT_OEUVRE, $anonyme );
	}
	if ( CPT_CHAPITRE !== $post->post_type ) {
		return true;
	}
	$tome_id = (int) get_post_meta( $post->ID, 'yume_tome_id', true );
	if ( ! parent_visible( $tome_id, CPT_TOME, $anonyme ) ) {
		return false;
	}
	$oeuvre_id = CPT_TOME === get_post_type( $tome_id ) ? (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ) : 0;
	if ( ! $oeuvre_id ) {
		$oeuvre_id = (int) get_post_meta( $post->ID, 'yume_oeuvre_id', true );
	}
	return parent_visible( $oeuvre_id, CPT_OEUVRE, $anonyme );
}

/**
 * Le contenu et toute sa hiérarchie sont-ils consultables par l'utilisateur courant ?
 *
 * @param \WP_Post $post Contenu.
 */
function est_consultable( \WP_Post $post ): bool {
	return est_visible( $post ) && hierarchie_visible( $post );
}

/*
 * -----------------------------------------------------------------------------
 * Contenus masqués par leur hiérarchie
 * -----------------------------------------------------------------------------
 */

/**
 * Liste de marqueurs %s pour une clause IN préparée.
 *
 * @param array<int,mixed> $valeurs Valeurs.
 */
function marqueurs( array $valeurs ): string {
	return implode( ', ', array_fill( 0, count( $valeurs ), '%s' ) );
}

/**
 * IDs des contenus d'un ou plusieurs types rattachés (clé de méta) à l'un des parents donnés.
 *
 * @param string[] $types   Types des enfants.
 * @param string   $cle     yume_oeuvre_id ou yume_tome_id.
 * @param int[]    $parents IDs des parents.
 * @return array<int,string> ID => type.
 */
function enfants_de( array $types, string $cle, array $parents ): array {
	global $wpdb;
	if ( ! $parents ) {
		return array();
	}
	$statuts = statuts_actifs();
	$valeurs = array_map( 'strval', $parents );
	$sql     = "SELECT p.ID, p.post_type FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s"
		. ' WHERE p.post_type IN (' . marqueurs( $types ) . ') AND p.post_status IN (' . marqueurs( $statuts ) . ')'
		. ' AND m.meta_value IN (' . marqueurs( $valeurs ) . ')';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $cle ), $types, $statuts, $valeurs ) ) );
	$ids    = array();
	foreach ( (array) $lignes as $ligne ) {
		$ids[ (int) $ligne->ID ] = (string) $ligne->post_type;
	}
	return $ids;
}

/**
 * IDs des tomes et chapitres dont un parent (tome ou œuvre) n'est pas visible.
 *
 * Mis en cache pour la requête en cours (clé : utilisateur et dernier changement des contenus,
 * qui suit aussi les métadonnées).
 *
 * @param bool $anonyme Vue anonyme (plans du site) plutôt que celle de l'utilisateur courant.
 * @return int[]
 */
function ids_masques( bool $anonyme = false ): array {
	global $wpdb;
	static $cache = array();
	$user         = $anonyme ? 0 : get_current_user_id();
	$cle          = $user . ':' . wp_cache_get_last_changed( 'posts' );
	if ( isset( $cache[ $cle ] ) ) {
		return $cache[ $cle ];
	}
	if ( count( $cache ) > 20 ) {
		$cache = array();
	}

	// Œuvres et tomes non publiés.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_type FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_status NOT IN ('publish', 'auto-draft', 'inherit')",
			CPT_OEUVRE,
			CPT_TOME
		)
	);
	if ( $lignes && $user ) {
		// Utilisateur connecté : ses aperçus (brouillons qu'il peut modifier…) restent visibles.
		_prime_post_caches( array_map( 'intval', wp_list_pluck( $lignes, 'ID' ) ), false, false );
	}
	$oeuvres = array();
	$tomes   = array();
	foreach ( $lignes as $ligne ) {
		$post = $user ? get_post( (int) $ligne->ID ) : null;
		if ( $post && est_visible( $post ) ) {
			continue;
		}
		if ( CPT_OEUVRE === $ligne->post_type ) {
			$oeuvres[] = (int) $ligne->ID;
		} else {
			$tomes[] = (int) $ligne->ID;
		}
	}

	$masques = enfants_de( array( CPT_TOME, CPT_CHAPITRE ), 'yume_oeuvre_id', $oeuvres );
	foreach ( $masques as $id => $type ) {
		if ( CPT_TOME === $type ) {
			$tomes[] = $id;
		}
	}
	$masques += enfants_de( array( CPT_CHAPITRE ), 'yume_tome_id', array_values( array_unique( $tomes ) ) );

	$ids = array_map( 'intval', array_keys( $masques ) );
	sort( $ids );
	$cache[ $cle ] = $ids;
	return $ids;
}

/**
 * Variable de requête privée : IDs exclus par masquer_where() (clause ajoutée à la requête SQL,
 * quels que soient p, post__in ou post__not_in, que WP_Query traite comme exclusifs).
 */
const QV_MASQUES = 'yume_masques';

/**
 * Marque une requête pour qu'elle exclue les contenus masqués.
 *
 * @param array<string,mixed> $args   Arguments de WP_Query.
 * @param bool                $anonyme Vue anonyme.
 * @return array<string,mixed>
 */
function exclure_masques( array $args, bool $anonyme = false ): array {
	$ids = ids_masques( $anonyme );
	if ( $ids ) {
		$deja               = isset( $args[ QV_MASQUES ] ) ? array_map( 'intval', (array) $args[ QV_MASQUES ] ) : array();
		$args[ QV_MASQUES ] = array_values( array_unique( array_merge( $deja, $ids ) ) );
	}
	return $args;
}

/**
 * Filtre posts_where : exclut les IDs marqués par exclure_masques().
 *
 * @param string    $where Clause WHERE.
 * @param \WP_Query $query Requête.
 * @return string
 */
function masquer_where( $where, $query ) {
	if ( ! $query instanceof \WP_Query ) {
		return $where;
	}
	$ids = array_filter( array_map( 'absint', (array) $query->get( QV_MASQUES ) ) );
	if ( ! $ids ) {
		return $where;
	}
	global $wpdb;
	return $where . " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', $ids ) . ')';
}
add_filter( 'posts_where', __NAMESPACE__ . '\\masquer_where', 10, 2 );

/**
 * La requête peut-elle renvoyer des tomes ou des chapitres ?
 *
 * @param mixed $post_type Variable post_type de la requête.
 * @param bool  $recherche Requête de recherche (post_type vide = tous les types).
 */
function requete_concernee( $post_type, bool $recherche = false ): bool {
	if ( 'any' === $post_type || ( $recherche && empty( $post_type ) ) ) {
		return true;
	}
	return (bool) array_intersect( array_map( 'strval', (array) $post_type ), array( CPT_TOME, CPT_CHAPITRE ) );
}

/**
 * Requête principale en façade (contenu seul, recherche, flux, archives) : contenus masqués exclus.
 *
 * @param \WP_Query $query Requête.
 */
function masquer_requete_principale( $query ): void {
	if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
		return;
	}
	if ( ! requete_concernee( $query->get( 'post_type' ), $query->is_search() ) ) {
		return;
	}
	$args = exclure_masques( array( QV_MASQUES => $query->get( QV_MASQUES ) ) );
	if ( ! empty( $args[ QV_MASQUES ] ) ) {
		$query->set( QV_MASQUES, $args[ QV_MASQUES ] );
	}
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\masquer_requete_principale', 20 );

/**
 * Blocs « Boucle de requête » : contenus masqués exclus.
 *
 * @param array<string,mixed> $query Arguments de WP_Query.
 * @return array<string,mixed>
 */
function masquer_boucle_requete( $query ) {
	if ( ! is_array( $query ) || ! requete_concernee( $query['post_type'] ?? 'post', ! empty( $query['s'] ) ) ) {
		return $query;
	}
	return exclure_masques( $query );
}
add_filter( 'query_loop_block_query_vars', __NAMESPACE__ . '\\masquer_boucle_requete' );

/**
 * REST /wp/v2/tomes et /wp/v2/chapitres (listes) : contenus masqués exclus.
 *
 * @param array<string,mixed> $args Arguments de WP_Query.
 * @return array<string,mixed>
 */
function masquer_rest_liste( $args ) {
	return is_array( $args ) ? exclure_masques( $args ) : $args;
}
add_filter( 'rest_' . CPT_TOME . '_query', __NAMESPACE__ . '\\masquer_rest_liste' );
add_filter( 'rest_' . CPT_CHAPITRE . '_query', __NAMESPACE__ . '\\masquer_rest_liste' );

/**
 * REST /wp/v2/search : contenus masqués exclus.
 *
 * @param array<string,mixed> $args Arguments de WP_Query.
 * @return array<string,mixed>
 */
function masquer_rest_recherche( $args ) {
	if ( ! is_array( $args ) || ! requete_concernee( $args['post_type'] ?? 'any', true ) ) {
		return $args;
	}
	return exclure_masques( $args );
}
add_filter( 'rest_post_search_query', __NAMESPACE__ . '\\masquer_rest_recherche' );

/**
 * Plans du site : contenus masqués exclus (vue anonyme).
 *
 * @param array<string,mixed> $args      Arguments de WP_Query.
 * @param string              $post_type Type.
 * @return array<string,mixed>
 */
function masquer_plan_du_site( $args, $post_type = '' ) {
	if ( ! is_array( $args ) || ! in_array( $post_type, array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return $args;
	}
	return exclure_masques( $args, true );
}
add_filter( 'wp_sitemaps_posts_query_args', __NAMESPACE__ . '\\masquer_plan_du_site', 10, 2 );

/**
 * REST : GET /wp/v2/tomes/{id} et /wp/v2/chapitres/{id} d'un contenu masqué répond 404,
 * comme un contenu inexistant.
 *
 * @param mixed            $reponse Réponse (null, ou erreur déjà constatée).
 * @param array            $handler Gestionnaire de la route.
 * @param \WP_REST_Request $request Requête.
 * @return mixed
 */
function masquer_rest_element( $reponse, $handler, $request ) {
	if ( is_wp_error( $reponse ) || ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
		return $reponse;
	}
	foreach ( array( CPT_TOME, CPT_CHAPITRE ) as $type ) {
		$objet = get_post_type_object( $type );
		$base  = $objet && is_string( $objet->rest_base ) && '' !== $objet->rest_base ? $objet->rest_base : $type;
		$ns    = $objet && ! empty( $objet->rest_namespace ) ? (string) $objet->rest_namespace : 'wp/v2';
		if ( ! preg_match( '#^/' . preg_quote( $ns, '#' ) . '/' . preg_quote( $base, '#' ) . '/(\d+)/?$#', $request->get_route(), $m ) ) {
			continue;
		}
		$post = get_post( (int) $m[1] );
		if ( $post instanceof \WP_Post && $type === $post->post_type && ! hierarchie_visible( $post ) ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Identifiant de contenu invalide.', 'yume-core' ), array( 'status' => 404 ) );
		}
		break;
	}
	return $reponse;
}
add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\\masquer_rest_element', 10, 3 );

/**
 * Pas de redirection canonique (?post_type=…&p=N → permalien) vers un contenu masqué : elle
 * révélerait l'adresse, donc les slugs du tome et de l'œuvre non publiés. La page reste en 404.
 *
 * @param string|false $url URL de redirection.
 * @return string|false
 */
function bloquer_redirection_masquee( $url ) {
	$id = (int) get_query_var( 'p' );
	if ( $url && $id && is_404() ) {
		$post = get_post( $id );
		if ( $post instanceof \WP_Post && in_array( $post->post_type, array( CPT_TOME, CPT_CHAPITRE ), true ) && ! est_consultable( $post ) ) {
			return false;
		}
	}
	return $url;
}
add_filter( 'redirect_canonical', __NAMESPACE__ . '\\bloquer_redirection_masquee' );

/*
 * -----------------------------------------------------------------------------
 * Archives d'auteur
 * -----------------------------------------------------------------------------
 */

/**
 * Le site n'a pas d'archives d'auteur : /?author=N, /author/{identifiant}/ et leurs flux
 * répondent 404 (sans redirection qui révélerait l'identifiant de connexion).
 *
 * @param array<string,mixed> $qv Variables de requête.
 * @return array<string,mixed>
 */
function fermer_archives_auteur( $qv ) {
	if ( is_admin() || ! is_array( $qv ) ) {
		return $qv;
	}
	if ( ( isset( $qv['author'] ) && '' !== $qv['author'] ) || ( isset( $qv['author_name'] ) && '' !== $qv['author_name'] ) ) {
		return array( 'error' => '404' );
	}
	return $qv;
}
add_filter( 'request', __NAMESPACE__ . '\\fermer_archives_auteur', 1 );

/**
 * Lien « auteur » (liste des utilisateurs, colonne Auteur, blocs, REST) : les archives d'auteur
 * étant fermées, /author/{identifiant}/ mènerait à une 404 et révélerait l'identifiant de
 * connexion. Qui peut modifier le compte d'un autre est envoyé sur sa fiche (user-edit.php) ;
 * son propre compte mène au profil de l'administration (gestionnaires des comptes) ou à la page
 * « Mon compte » ; tout autre visiteur va sur l'accueil.
 *
 * @param string $lien      Lien d'origine.
 * @param int    $auteur_id Auteur.
 * @return string
 */
function lien_auteur( $lien, $auteur_id = 0 ) {
	$auteur_id = (int) $auteur_id;
	if ( $auteur_id <= 0 ) {
		return home_url( '/' );
	}
	if ( get_current_user_id() === $auteur_id ) {
		// Chacun peut modifier son propre compte : profil de l'administration pour qui gère les
		// comptes, page « Mon compte » pour les autres.
		return current_user_can( 'list_users' ) ? admin_url( 'profile.php' ) : yume_url_page( 'compte' );
	}
	if ( current_user_can( 'edit_user', $auteur_id ) ) {
		return add_query_arg( 'user_id', $auteur_id, admin_url( 'user-edit.php' ) );
	}
	return home_url( '/' );
}
add_filter( 'author_link', __NAMESPACE__ . '\\lien_auteur', 10, 2 );

/**
 * Plan du site : pas de fournisseur « users » (il listerait les identifiants de connexion).
 *
 * @param mixed  $fournisseur Fournisseur.
 * @param string $nom         Nom.
 * @return mixed
 */
function retirer_plan_auteurs( $fournisseur, $nom = '' ) {
	return 'users' === $nom ? false : $fournisseur;
}
add_filter( 'wp_sitemaps_add_provider', __NAMESPACE__ . '\\retirer_plan_auteurs', 10, 2 );
