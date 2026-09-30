<?php
/**
 * Fonctions du module wordend : activation, œuvres qui affichent le papillon, adresses des
 * fichiers chargés à la demande (feuille, scripts du moteur) et configuration passée au script
 * déclencheur. Les univers (manifestes, personnages, niveaux…) : univers.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\WordEnd;

defined( 'ABSPATH' ) || exit;

/** Poignée du script déclencheur (façade). */
const POIGNEE = 'yume-wordend-declencheur';

/** Poignée de la feuille du papillon de la fiche d'œuvre. */
const POIGNEE_SECRET = 'yume-wordend-secret';

/** Feuille du jeu (chargée à la demande). */
const FEUILLE_JEU = 'jeu.css';

/**
 * Scripts du moteur, dans l'ordre de chargement (chacun enrichit window.ynWordEndMoteur ; le
 * dernier expose window.ynWordEndJeu). Interfaces : docs/wordend-formats.md.
 */
const SCRIPTS_MOTEUR = array(
	'moteur/00-espace.js',
	'moteur/stockage.js',
	'moteur/ressources.js',
	'moteur/audio.js',
	'moteur/rendu.js',
	'moteur/physique.js',
	'moteur/competences.js',
	'moteur/joueur.js',
	'moteur/ennemis.js',
	'moteur/niveau.js',
	'moteur/entrees.js',
	'moteur/ecrans.js',
	'moteur/modale.js',
	'moteur/jeu.js',
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
 * Slugs des œuvres dont la fiche affiche le papillon qui ouvre le jeu : œuvres des univers
 * (yume_wordend_univers) et œuvres ajoutées par le filtre yume_wordend_oeuvres, assainis et
 * dédoublonnés.
 *
 * @return string[]
 */
function oeuvres_declencheuses(): array {
	$des_univers = array_values( array_filter( array_column( univers(), 'oeuvre' ) ) );
	/**
	 * Slugs des œuvres (yume_oeuvre) dont la fiche affiche le papillon de WordEnd. Une œuvre sans
	 * univers associé ouvre l'univers par défaut ; les œuvres des univers (yume_wordend_univers)
	 * gardent toujours leur papillon (les retirer : filtre yume_wordend_univers).
	 *
	 * @param string[] $slugs Par défaut : œuvres des univers (sukasuka).
	 */
	$slugs = apply_filters( 'yume_wordend_oeuvres', $des_univers );
	$slugs = array_merge( array_map( 'strval', (array) $slugs ), $des_univers );
	$slugs = array_filter( array_map( 'sanitize_title', $slugs ) );
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
 * Configuration du jeu (window.ynWordEnd). Batcache : elle ne dépend que de l'URL demandée
 * (universPage = univers de la fiche d'œuvre de la requête principale), jamais du visiteur.
 *
 * @return array{version:string, style:string, scripts:string[], univers:array, universParDefaut:string, universPage:string}
 */
function configuration(): array {
	$univers = univers();
	return array(
		'version'          => defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '',
		'style'            => url_asset( FEUILLE_JEU ),
		'scripts'          => array_map( __NAMESPACE__ . '\\url_asset', SCRIPTS_MOTEUR ),
		'univers'          => $univers,
		'universParDefaut' => univers_par_defaut( $univers ),
		'universPage'      => univers_de_la_page(),
	);
}

/**
 * Chemins absolus des fichiers livrés par le module (contrôle des tests et de l'archive) :
 * déclencheur, feuilles, scripts du moteur et fichiers des univers intégrés.
 *
 * @return string[]
 */
function fichiers_requis(): array {
	$fichiers = array_map( __NAMESPACE__ . '\\chemin_asset', array_merge( array( 'declencheur.js', 'secret.css', FEUILLE_JEU ), SCRIPTS_MOTEUR ) );
	foreach ( UNIVERS_INTEGRES as $univers ) {
		$fichiers = array_merge( $fichiers, fichiers_univers( $univers['dossier'] ) );
	}
	return $fichiers;
}
