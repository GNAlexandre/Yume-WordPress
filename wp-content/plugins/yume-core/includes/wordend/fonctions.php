<?php
/**
 * Fonctions du module wordend : activation, œuvres qui affichent le papillon, adresses des
 * fichiers chargés à la demande et configuration passée au script déclencheur.
 *
 * @package Yume\Core
 */

namespace Yume\Core\WordEnd;

defined( 'ABSPATH' ) || exit;

/** Poignée du script déclencheur (façade). */
const POIGNEE = 'yume-wordend-declencheur';

/** Poignée de la feuille du papillon de la fiche d'œuvre. */
const POIGNEE_SECRET = 'yume-wordend-secret';

/** Fichiers du jeu chargés à la demande (clé de configuration => fichier de assets/). */
const FICHIERS_A_LA_DEMANDE = array(
	'jeu'        => 'jeu.js',
	'style'      => 'jeu.css',
	'planche'    => 'chtholly.png',
	'meta'       => 'chtholly.json',
	'timere'     => 'timere.png',
	'timereMeta' => 'timere.json',
);

/**
 * L'easter egg est-il actif ?
 */
function actif(): bool {
	/**
	 * Active ou coupe l'easter egg WordEnd (script déclencheur et papillon de la fiche).
	 *
	 * @param bool $actif Vrai par défaut.
	 */
	return (bool) apply_filters( 'yume_wordend_actif', true );
}

/**
 * Slugs des œuvres dont la fiche affiche le papillon qui ouvre le jeu.
 *
 * @return string[]
 */
function oeuvres_declencheuses(): array {
	/**
	 * Slugs des œuvres (yume_oeuvre) dont la fiche affiche le papillon de WordEnd.
	 *
	 * @param string[] $slugs Par défaut : sukasuka.
	 */
	$slugs = apply_filters( 'yume_wordend_oeuvres', array( 'sukasuka' ) );
	$slugs = array_filter( array_map( 'sanitize_title', array_map( 'strval', (array) $slugs ) ) );
	return array_values( array_unique( $slugs ) );
}

/**
 * Chemin absolu d'un fichier de assets/.
 *
 * @param string $fichier Nom du fichier.
 */
function chemin_asset( string $fichier ): string {
	return __DIR__ . '/assets/' . $fichier;
}

/**
 * Version d'un fichier de assets/ : version de l'extension et date de modification.
 *
 * @param string $fichier Nom du fichier.
 */
function version_fichier( string $fichier ): string {
	$chemin = chemin_asset( $fichier );
	return ( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . ( is_readable( $chemin ) ? (string) filemtime( $chemin ) : '0' );
}

/**
 * Adresse versionnée d'un fichier de assets/ (chargé à la demande : la version évite un
 * ancien fichier en cache après une mise à jour).
 *
 * @param string $fichier Nom du fichier.
 */
function url_asset( string $fichier ): string {
	return add_query_arg( 'ver', rawurlencode( version_fichier( $fichier ) ), plugins_url( 'assets/' . $fichier, __FILE__ ) );
}

/**
 * Configuration du jeu (window.ynWordEnd) : identique pour tous les visiteurs (Batcache).
 *
 * @return array{jeu:string,style:string,planche:string,meta:string,timere:string,timereMeta:string,version:string}
 */
function configuration(): array {
	$config = array();
	foreach ( FICHIERS_A_LA_DEMANDE as $cle => $fichier ) {
		$config[ $cle ] = url_asset( $fichier );
	}
	$config['version'] = defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '';
	return $config;
}

/**
 * Chemins absolus des fichiers livrés par le module (contrôle des tests et de l'archive).
 *
 * @return string[]
 */
function fichiers_requis(): array {
	$fichiers = array_merge( array( 'declencheur.js', 'secret.css' ), array_values( FICHIERS_A_LA_DEMANDE ) );
	return array_map( __NAMESPACE__ . '\\chemin_asset', $fichiers );
}
