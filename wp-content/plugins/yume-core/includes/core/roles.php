<?php
/**
 * Rôles et capacités (§5 du contrat).
 *
 * L'installation est idempotente : les rôles absents sont créés, les rôles existants
 * reçoivent les capacités manquantes et leur nom d'affichage est remis à jour. Le rôle
 * subscriber est affiché « Lecteur » (renommage d'affichage, sans toucher au rôle stocké).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Capacités propres à Yume (hors capacités des types de contenu).
 *
 * @return string[]
 */
function capacites_propres(): array {
	return array( 'yume_maj_planning', 'yume_maj_planning_tous', 'yume_publier', 'yume_gerer_equipe', 'yume_reglages', 'yume_voir_equipe' );
}

/**
 * Capacités primitives des trois types de contenu (edit_yume_oeuvres, publish_yume_tomes…).
 *
 * @return string[]
 */
function capacites_types(): array {
	$caps = array();
	foreach ( array( 'yume_oeuvres', 'yume_tomes', 'yume_chapitres' ) as $pluriel ) {
		foreach ( array( 'edit', 'edit_others', 'publish', 'read_private', 'delete', 'delete_private', 'delete_published', 'delete_others', 'edit_private', 'edit_published' ) as $action ) {
			$caps[] = $action . '_' . $pluriel;
		}
	}
	return $caps;
}

/**
 * Définition des rôles Yume : slug => [nom, capacités].
 *
 * @return array<string,array{nom:string,caps:string[]}>
 */
function definitions_roles(): array {
	$equipe  = array( 'read', 'upload_files', 'yume_voir_equipe', 'yume_maj_planning', 'edit_yume_tomes' );
	$editeur = array_merge(
		$equipe,
		array( 'yume_publier', 'yume_maj_planning_tous' ),
		capacites_types(),
		array( 'edit_posts', 'publish_posts', 'edit_published_posts', 'moderate_comments', 'manage_categories' )
	);
	$gerant  = array_merge(
		$editeur,
		array( 'yume_gerer_equipe', 'yume_reglages', 'edit_others_posts', 'delete_others_posts', 'list_users' ),
		// Sans ces deux capacités, delete_others_posts ne permet pas de supprimer un article publié.
		array( 'delete_posts', 'delete_published_posts' )
	);
	return array(
		'yume_traducteur' => array(
			'nom'  => __( 'Traducteur', 'yume-core' ),
			'caps' => $equipe,
		),
		'yume_relecteur'  => array(
			'nom'  => __( 'Relecteur', 'yume-core' ),
			'caps' => $equipe,
		),
		'yume_graphiste'  => array(
			'nom'  => __( 'Graphiste', 'yume-core' ),
			'caps' => $equipe,
		),
		'yume_editeur'    => array(
			'nom'  => __( 'Éditeur Yume', 'yume-core' ),
			'caps' => array_values( array_unique( $editeur ) ),
		),
		'yume_gerant'     => array(
			'nom'  => __( 'Gérant', 'yume-core' ),
			'caps' => array_values( array_unique( $gerant ) ),
		),
	);
}

/**
 * Crée ou met à jour les rôles (une seule écriture de l'option des rôles).
 *
 * Travaille sur les données stockées (et non sur les noms affichés en mémoire) pour ne jamais
 * enregistrer le renommage d'affichage du rôle subscriber.
 */
function installer_roles(): void {
	$roles = wp_roles();
	$defs  = definitions_roles();
	// L'administrateur reçoit toutes les capacités Yume.
	$defs['administrator'] = array(
		'nom'  => '',
		'caps' => array_merge( capacites_propres(), capacites_types() ),
	);

	$stockes = $roles->use_db ? get_option( $roles->role_key, array() ) : $roles->roles;
	if ( ! is_array( $stockes ) ) {
		$stockes = array();
	}
	$modifie = false;
	foreach ( $defs as $slug => $def ) {
		if ( ! isset( $stockes[ $slug ] ) ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$stockes[ $slug ] = array(
				'name'         => $def['nom'],
				'capabilities' => array(),
			);
			$modifie          = true;
		}
		if ( '' !== $def['nom'] && ( $stockes[ $slug ]['name'] ?? '' ) !== $def['nom'] ) {
			$stockes[ $slug ]['name'] = $def['nom'];
			$modifie                  = true;
		}
		foreach ( $def['caps'] as $cap ) {
			if ( empty( $stockes[ $slug ]['capabilities'][ $cap ] ) ) {
				$stockes[ $slug ]['capabilities'][ $cap ] = true;
				$modifie                                  = true;
			}
		}
	}
	if ( ! $modifie ) {
		return;
	}
	if ( $roles->use_db ) {
		update_option( $roles->role_key, $stockes, true );
		// Recharge les rôles depuis l'option (déclenche wp_roles_init, donc le renommage d'affichage).
		$roles->for_site();
	} else {
		$roles->roles = $stockes;
		$roles->init_roles();
	}
	// L'utilisateur courant voit tout de suite ses nouvelles capacités.
	$courant = wp_get_current_user();
	if ( $courant instanceof \WP_User && $courant->exists() ) {
		$courant->get_role_caps();
	}
}

/**
 * Affiche le rôle subscriber sous le nom « Lecteur ».
 *
 * @param \WP_Roles $roles Rôles.
 */
function renommer_abonne( $roles ): void {
	if ( $roles instanceof \WP_Roles && isset( $roles->roles['subscriber'] ) ) {
		// Avant init, les traductions ne peuvent pas encore être chargées (chaîne source française).
		$nom                                = did_action( 'init' ) ? __( 'Lecteur', 'yume-core' ) : 'Lecteur';
		$roles->roles['subscriber']['name'] = $nom;
		$roles->role_names['subscriber']    = $nom;
	}
}
add_action( 'wp_roles_init', __NAMESPACE__ . '\\renommer_abonne' );

/**
 * Renommage appliqué aussi si les rôles ont été chargés avant le plugin.
 */
function renommer_abonne_init(): void {
	renommer_abonne( wp_roles() );
}
add_action( 'init', __NAMESPACE__ . '\\renommer_abonne_init', 1 );

/**
 * La fonction translate_user_role() ne connaît pas « Lecteur » : le nom est rendu tel quel.
 *
 * @param string $traduction Traduction.
 * @param string $texte      Texte original.
 * @param string $contexte   Contexte.
 * @param string $domaine    Domaine.
 */
function filtre_nom_role( $traduction, $texte, $contexte, $domaine ) {
	if ( 'User role' === $contexte && 'Subscriber' === $texte && 'default' === $domaine ) {
		return did_action( 'init' ) ? __( 'Lecteur', 'yume-core' ) : 'Lecteur';
	}
	return $traduction;
}
add_filter( 'gettext_with_context', __NAMESPACE__ . '\\filtre_nom_role', 10, 4 );
