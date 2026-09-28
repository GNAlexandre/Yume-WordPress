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
		// Membres et rôles : changer le rôle d'un compte (limité aux rôles de l'équipe et au
		// Lecteur par roles_gerables()). Ni create_users ni edit_users (SEC-03) : voir
		// capacites_retirees().
		array( 'promote_users' ),
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
 * Capacités retirées des rôles Yume par une mise à jour : installer_roles() les enlève des rôles
 * stockés en base (sites existants), verifier_roles() relance l'installation quand cette liste
 * change.
 *
 * SEC-03 : le gérant ne modifie plus le profil d'un autre compte (mot de passe, e-mail) ni n'en
 * crée ; la page « Membres et rôles » n'a besoin que de promote_users. Le mot de passe d'un
 * membre se réinitialise par « Mot de passe oublié ? » ou par un administrateur.
 *
 * @return array<string,string[]> slug du rôle => capacités à retirer.
 */
function capacites_retirees(): array {
	return array(
		'yume_gerant' => array( 'create_users', 'edit_users' ),
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
	foreach ( capacites_retirees() as $slug => $caps ) {
		foreach ( $caps as $cap ) {
			if ( isset( $stockes[ $slug ]['capabilities'][ $cap ] ) ) {
				unset( $stockes[ $slug ]['capabilities'][ $cap ] );
				$modifie = true;
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

/**
 * Rôles qu'un gérant (tout compte sans manage_options) peut attribuer, et comptes qu'il peut
 * modifier : Lecteur et rôles de l'équipe, jamais administrateur, gérant ni rôles WordPress
 * éditoriaux.
 *
 * @return string[]
 */
function roles_gerables(): array {
	return array( 'subscriber', 'yume_traducteur', 'yume_relecteur', 'yume_graphiste', 'yume_editeur' );
}

/**
 * Le compte peut-il administrer tous les utilisateurs (administrateur) ?
 *
 * @param int $user_id Utilisateur.
 */
function gere_tous_les_membres( int $user_id ): bool {
	return $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) );
}

/**
 * Liste des rôles attribuables (user-new.php, user-edit.php, users.php, REST /wp/v2/users) :
 * limitée à roles_gerables() pour qui n'est pas administrateur.
 *
 * @param array<string,array> $roles Rôles.
 * @return array<string,array>
 */
function limiter_roles_attribuables( $roles ) {
	if ( ! is_array( $roles ) || gere_tous_les_membres( get_current_user_id() ) ) {
		return $roles;
	}
	return array_intersect_key( $roles, array_flip( roles_gerables() ) );
}
add_filter( 'editable_roles', __NAMESPACE__ . '\\limiter_roles_attribuables' );

/**
 * Un compte sans manage_options (gérant) ne promeut que des comptes dont tous les rôles sont
 * dans roles_gerables() ; il ne change jamais son propre rôle. Même règle pour edit_user,
 * remove_user et delete_user si un compte non administrateur en reçoit les capacités (le gérant
 * ne les a pas : SEC-03, capacites_retirees()).
 *
 * @param string[] $caps    Capacités primitives exigées.
 * @param string   $cap     Capacité demandée.
 * @param int      $user_id Utilisateur qui agit.
 * @param array    $args    Arguments (ID du compte visé).
 * @return string[]
 */
function limiter_gestion_membres( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_user', 'promote_user', 'remove_user', 'delete_user' ), true ) || empty( $args[0] ) ) {
		return $caps;
	}
	$user_id = (int) $user_id;
	$cible   = (int) $args[0];
	if ( gere_tous_les_membres( $user_id ) ) {
		return $caps;
	}
	if ( $cible === $user_id ) {
		// Son propre profil reste modifiable ; son propre rôle, non.
		return 'promote_user' === $cap ? array( 'do_not_allow' ) : $caps;
	}
	$compte = get_userdata( $cible );
	if ( $compte instanceof \WP_User && ( array_diff( (array) $compte->roles, roles_gerables() ) || is_super_admin( $cible ) ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}
add_filter( 'map_meta_cap', __NAMESPACE__ . '\\limiter_gestion_membres', 10, 4 );

/**
 * Réinstalle les capacités quand les définitions des rôles ont changé (capacité ajoutée ou
 * retirée dans une mise à jour du plugin), sans attendre une montée de version.
 */
function verifier_roles(): void {
	$signature = md5(
		(string) wp_json_encode(
			array(
				array_map( static fn( array $def ): array => $def['caps'], definitions_roles() ),
				capacites_retirees(),
			)
		)
	);
	if ( get_option( 'yume_core_roles' ) === $signature ) {
		return;
	}
	installer_roles();
	update_option( 'yume_core_roles', $signature, true );
}
add_action( 'init', __NAMESPACE__ . '\\verifier_roles', 98 );
