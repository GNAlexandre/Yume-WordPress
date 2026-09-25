<?php
/**
 * Installation et désactivation du module core (hooks du socle yume_core_install et
 * yume_core_deactivate). Tout est idempotent : l'installation est rejouée à chaque montée
 * de version du plugin.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Crée les termes par défaut des taxonomies yume_type et yume_statut (sans renommer
 * les termes existants).
 */
function installer_termes(): void {
	foreach ( termes_par_defaut() as $taxonomie => $termes ) {
		if ( ! taxonomy_exists( $taxonomie ) ) {
			continue;
		}
		foreach ( $termes as $slug => $nom ) {
			if ( get_term_by( 'slug', $slug, $taxonomie ) ) {
				continue;
			}
			wp_insert_term( $nom, $taxonomie, array( 'slug' => $slug ) );
		}
	}
}

/**
 * Crée l'option des réglages avec les valeurs par défaut si elle n'existe pas, et complète
 * une option existante avec les clés apparues depuis (nouvelle version).
 */
function installer_reglages(): void {
	$defauts = defauts_reglages();
	$actuels = get_option( OPTION_REGLAGES, null );
	if ( ! is_array( $actuels ) ) {
		add_option( OPTION_REGLAGES, $defauts, '', true );
		return;
	}
	$manquants = array_diff_key( $defauts, $actuels );
	if ( $manquants ) {
		update_option( OPTION_REGLAGES, array_merge( $actuels, $manquants ) );
	}
}

/**
 * yume_core_install : types enregistrés, termes, rôles, réglages, termes des œuvres.
 */
function installer(): void {
	enregistrer_contenu();
	installer_termes();
	installer_roles();
	installer_reglages();
	synchroniser_tous_les_termes();
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer' );
