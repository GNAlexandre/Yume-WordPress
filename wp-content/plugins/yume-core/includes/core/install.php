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
 * Genres proposés d'office (yume_genre) : les genres courants des light novels, web novels et
 * mangas, pour que le formulaire « Nouvelle œuvre » ne parte pas d'une liste vide. D'autres se
 * créent depuis ce formulaire ou Œuvres → Genres.
 *
 * @return string[]
 */
function genres_proposes(): array {
	return array(
		__( 'Action', 'yume-core' ),
		__( 'Aventure', 'yume-core' ),
		__( 'Comédie', 'yume-core' ),
		__( 'Drame', 'yume-core' ),
		__( 'Ecchi', 'yume-core' ),
		__( 'Fantasy', 'yume-core' ),
		__( 'Harem', 'yume-core' ),
		__( 'Historique', 'yume-core' ),
		__( 'Horreur', 'yume-core' ),
		__( 'Isekai', 'yume-core' ),
		__( 'Josei', 'yume-core' ),
		__( 'Magie', 'yume-core' ),
		__( 'Mecha', 'yume-core' ),
		__( 'Militaire', 'yume-core' ),
		__( 'Mystère', 'yume-core' ),
		__( 'Psychologique', 'yume-core' ),
		__( 'Réincarnation', 'yume-core' ),
		__( 'Romance', 'yume-core' ),
		__( 'School life', 'yume-core' ),
		__( 'Science-fiction', 'yume-core' ),
		__( 'Seinen', 'yume-core' ),
		__( 'Shōjo', 'yume-core' ),
		__( 'Shōnen', 'yume-core' ),
		__( 'Slice of life', 'yume-core' ),
		__( 'Sport', 'yume-core' ),
		__( 'Surnaturel', 'yume-core' ),
		__( 'Thriller', 'yume-core' ),
		__( 'Tragédie', 'yume-core' ),
		__( 'Yuri', 'yume-core' ),
	);
}

/**
 * Crée une seule fois les genres proposés absents (nom ou slug, casse ignorée) : un genre
 * supprimé ensuite par l'équipe ne revient pas à la mise à jour suivante (option
 * yume_genres_proposes).
 */
function installer_genres(): void {
	if ( ! taxonomy_exists( TAX_GENRE ) || get_option( 'yume_genres_proposes' ) ) {
		return;
	}
	$existants = get_terms(
		array(
			'taxonomy'   => TAX_GENRE,
			'hide_empty' => false,
		)
	);
	$connus    = array();
	foreach ( is_array( $existants ) ? $existants : array() as $terme ) {
		$connus[ mb_strtolower( $terme->name ) ] = true;
		$connus[ $terme->slug ]                  = true;
	}
	foreach ( genres_proposes() as $nom ) {
		if ( isset( $connus[ mb_strtolower( $nom ) ] ) || isset( $connus[ sanitize_title( $nom ) ] ) ) {
			continue;
		}
		wp_insert_term( $nom, TAX_GENRE );
	}
	update_option( 'yume_genres_proposes', 1, false );
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
 * Sur yume_core_install : types enregistrés, termes, rôles, réglages, termes des œuvres.
 */
function installer(): void {
	enregistrer_contenu();
	installer_termes();
	installer_genres();
	installer_roles();
	installer_reglages();
	synchroniser_tous_les_termes();
}
add_action( 'yume_core_install', __NAMESPACE__ . '\\installer' );
