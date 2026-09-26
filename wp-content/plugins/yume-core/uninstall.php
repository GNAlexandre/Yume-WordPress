<?php
/**
 * Désinstallation de Yume Core.
 *
 * Par défaut, seules les tâches planifiées Yume sont supprimées : œuvres, tomes, chapitres,
 * comptes et réglages restent en base (une réinstallation retrouve tout). Pour tout effacer,
 * définir dans wp-config.php, avant de supprimer l'extension :
 *
 *   define( 'YUME_UNINSTALL_PURGE', true );
 *
 * La purge supprime alors les contenus Yume (œuvres, tomes, chapitres, avec leurs
 * métadonnées et commentaires), les termes des taxonomies Yume, les tables yume_*, les
 * rôles Yume et les capacités yume_*, les options, transients et métadonnées utilisateur
 * yume_*. Les fichiers de la médiathèque (couvertures, illustrations) sont conservés.
 *
 * @package Yume\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Supprime tous les événements WP-Cron dont le nom commence par « yume ».
 */
function yume_uninstall_taches(): void {
	$cron = _get_cron_array();
	if ( ! is_array( $cron ) ) {
		return;
	}
	$hooks = array();
	foreach ( $cron as $evenements ) {
		foreach ( array_keys( (array) $evenements ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'yume' ) ) {
				$hooks[ $hook ] = true;
			}
		}
	}
	foreach ( array_keys( $hooks ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
}

/**
 * Purge complète des données Yume du site courant.
 */
function yume_uninstall_purger(): void {
	global $wpdb;

	// Contenus Yume (métadonnées, commentaires et relations supprimés par wp_delete_post).
	$types = array( 'yume_oeuvre', 'yume_tome', 'yume_chapitre' );
	do {
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s, %s) LIMIT 200",
				$types[0],
				$types[1],
				$types[2]
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	} while ( $ids );

	// Termes des taxonomies Yume (taxonomies déclarées le temps de la purge).
	foreach ( array( 'yume_type', 'yume_statut', 'yume_genre', 'yume_oeuvre_liee' ) as $taxonomie ) {
		if ( ! taxonomy_exists( $taxonomie ) ) {
			register_taxonomy( $taxonomie, array(), array( 'public' => false ) );
		}
		$termes = get_terms(
			array(
				'taxonomy'   => $taxonomie,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_array( $termes ) ) {
			foreach ( $termes as $terme_id ) {
				wp_delete_term( (int) $terme_id, $taxonomie );
			}
		}
	}

	// Tables des modules (§13 du contrat).
	foreach ( array( 'favoris', 'notes', 'progression', 'planning_journal', 'notifications' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'yume_' . $table ) . '`' ); // phpcs:ignore WordPress.DB
	}

	// Rôles Yume : les utilisateurs concernés redeviennent lecteurs (subscriber).
	$roles = array( 'yume_traducteur', 'yume_relecteur', 'yume_graphiste', 'yume_editeur', 'yume_gerant' );
	foreach ( $roles as $role ) {
		$users = get_users(
			array(
				'role'   => $role,
				'fields' => 'ID',
			)
		);
		foreach ( $users as $user_id ) {
			$user = new WP_User( (int) $user_id );
			$user->remove_role( $role );
			if ( ! $user->roles ) {
				$user->add_role( 'subscriber' );
			}
		}
		remove_role( $role );
	}
	foreach ( wp_roles()->role_objects as $objet ) {
		foreach ( array_keys( (array) $objet->capabilities ) as $cap ) {
			if ( preg_match( '/(^yume_|_yume_(oeuvres|tomes|chapitres)$)/', (string) $cap ) ) {
				$objet->remove_cap( $cap );
			}
		}
	}

	// Options, transients et métadonnées utilisateur.
	$options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'yume_' ) . '%',
			$wpdb->esc_like( '_transient_yume_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_yume_' ) . '%'
		)
	);
	foreach ( $options as $option ) {
		delete_option( (string) $option );
	}
	// Méta utilisateur publiques (yume_reglages, yume_alertes) et internes (_yume_email_en_attente).
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( 'yume_' ) . '%', $wpdb->esc_like( '_yume_' ) . '%' )
	);
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_yume_' ) . '%' )
	);
	// Méta internes des commentaires (_yume_reponse_notifiee).
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_yume_' ) . '%' )
	);
	wp_cache_flush();
}

/**
 * Désinstallation d'un site.
 */
function yume_uninstall_site(): void {
	yume_uninstall_taches();
	if ( defined( 'YUME_UNINSTALL_PURGE' ) && YUME_UNINSTALL_PURGE ) {
		yume_uninstall_purger();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $yume_site_id ) {
		switch_to_blog( (int) $yume_site_id );
		yume_uninstall_site();
		restore_current_blog();
	}
} else {
	yume_uninstall_site();
}
