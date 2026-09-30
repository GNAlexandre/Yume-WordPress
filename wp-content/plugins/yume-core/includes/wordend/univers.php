<?php
/**
 * Univers du jeu WordEnd : registre des univers intégrés (dossiers de assets/univers/), adresse
 * versionnée de leur manifeste et liste des fichiers qu'ils référencent.
 *
 * Version minimale (lot 0) : pas encore de filtre ni de lien fiche d'œuvre ↔ univers
 * (lot E : yume_wordend_univers, univers_par_oeuvre(), univers_de_la_page()).
 * Schémas : docs/wordend-formats.md.
 *
 * @package Yume\Core
 */

namespace Yume\Core\WordEnd;

defined( 'ABSPATH' ) || exit;

/** Univers livrés avec l'extension (slug => titre, œuvre du catalogue, dossier de assets/univers/). */
const UNIVERS_INTEGRES = array(
	'sukasuka' => array(
		'titre'   => 'WordEnd',
		'oeuvre'  => 'sukasuka',
		'dossier' => 'sukasuka',
	),
);

/** Univers ouvert par défaut. */
const UNIVERS_PAR_DEFAUT = 'sukasuka';

/**
 * Chemin absolu du manifeste d'un univers intégré.
 *
 * @param string $dossier Dossier sous assets/univers/.
 */
function chemin_manifeste( string $dossier ): string {
	return __DIR__ . '/assets/univers/' . $dossier . '/manifeste.json';
}

/**
 * Version d'un univers : version de l'extension et date de modification du manifeste.
 *
 * @param string $dossier Dossier sous assets/univers/.
 */
function version_univers( string $dossier ): string {
	$chemin = chemin_manifeste( $dossier );
	return ( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . ( is_readable( $chemin ) ? (string) filemtime( $chemin ) : '0' );
}

/**
 * Univers disponibles (identiques pour tous les visiteurs : Batcache). Les univers dont le
 * manifeste est illisible sont retirés.
 *
 * @return array<string, array{titre:string, oeuvre:string, manifeste:string, version:string}>
 */
function univers(): array {
	$liste = array();
	foreach ( UNIVERS_INTEGRES as $slug => $univers ) {
		$dossier = $univers['dossier'];
		if ( ! is_readable( chemin_manifeste( $dossier ) ) ) {
			continue;
		}
		$version        = version_univers( $dossier );
		$liste[ $slug ] = array(
			'titre'     => $univers['titre'],
			'oeuvre'    => $univers['oeuvre'],
			'manifeste' => add_query_arg( 'ver', rawurlencode( $version ), plugins_url( 'assets/univers/' . $dossier . '/manifeste.json', __FILE__ ) ),
			'version'   => $version,
		);
	}
	return $liste;
}

/**
 * Lit un fichier JSON d'un univers (tableau vide s'il est absent ou invalide).
 *
 * @param string $chemin Chemin absolu.
 */
function lire_json( string $chemin ): array {
	if ( ! is_readable( $chemin ) ) {
		return array();
	}
	$donnees = json_decode( (string) file_get_contents( $chemin ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local de l'extension.
	return is_array( $donnees ) ? $donnees : array();
}

/**
 * Chemins absolus des fichiers d'un univers intégré : manifeste, personnages et ennemis (JSON,
 * et pour ceux qui sont lisibles, planche <nom>.png et <nom>.planche.json), niveaux, décors,
 * musiques. Les fichiers référencés sont tous listés, même absents (contrôle des tests).
 *
 * @param string $dossier Dossier sous assets/univers/.
 * @return string[]
 */
function fichiers_univers( string $dossier ): array {
	$manifeste = chemin_manifeste( $dossier );
	$base      = dirname( $manifeste ) . '/';
	$donnees   = lire_json( $manifeste );
	$fichiers  = array( $manifeste );
	foreach ( array( 'personnages', 'ennemis' ) as $cle ) {
		foreach ( (array) ( $donnees[ $cle ] ?? array() ) as $relatif ) {
			$chemin     = $base . (string) $relatif;
			$fichiers[] = $chemin;
			$entite     = lire_json( $chemin );
			if ( ! empty( $entite['planche'] ) && is_string( $entite['planche'] ) ) {
				$prefixe    = dirname( $chemin ) . '/' . $entite['planche'];
				$fichiers[] = $prefixe . '.png';
				$fichiers[] = $prefixe . '.planche.json';
			}
		}
	}
	foreach ( (array) ( $donnees['niveaux'] ?? array() ) as $relatif ) {
		$fichiers[] = $base . (string) $relatif;
	}
	foreach ( (array) ( $donnees['decors'] ?? array() ) as $decor ) {
		if ( is_array( $decor ) && ! empty( $decor['image'] ) ) {
			$fichiers[] = $base . (string) $decor['image'];
		}
	}
	foreach ( (array) ( $donnees['musiques'] ?? array() ) as $musique ) {
		if ( is_array( $musique ) && ! empty( $musique['fichier'] ) ) {
			$fichiers[] = $base . (string) $musique['fichier'];
		}
	}
	return array_values( array_unique( $fichiers ) );
}
