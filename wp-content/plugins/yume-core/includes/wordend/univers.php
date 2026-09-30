<?php
/**
 * Univers du jeu WordEnd : registre des univers (intégrés dans assets/univers/ ou ajoutés par le
 * filtre yume_wordend_univers), adresse versionnée de leur manifeste, lien fiche d'œuvre ↔ univers
 * et liste des fichiers qu'ils référencent.
 *
 * Batcache : tout ce qui est calculé ici ne dépend que du code, des fichiers et de la requête
 * (URL), jamais de l'utilisateur connecté.
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
 * Dossier d'univers valide : un seul nom de dossier (lettres, chiffres, tiret, soulignement),
 * jamais « .. » ni « / » : il est résolu sous assets/univers/.
 *
 * @param string $dossier Dossier candidat.
 */
function dossier_valide( string $dossier ): bool {
	return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]*$/i', $dossier );
}

/**
 * Assainit une entrée du registre (voir le filtre yume_wordend_univers).
 *
 * @param mixed $entree Entrée brute : array{titre?, oeuvre?, dossier?, manifeste?, version?}.
 * @return array{titre:string, oeuvre:string, manifeste:string, version:string}|null Null si l'entrée est rejetée.
 */
function assainir_univers( $entree ): ?array {
	if ( ! is_array( $entree ) ) {
		return null;
	}
	$titre  = isset( $entree['titre'] ) && is_scalar( $entree['titre'] ) ? sanitize_text_field( (string) $entree['titre'] ) : '';
	$oeuvre = isset( $entree['oeuvre'] ) && is_scalar( $entree['oeuvre'] ) ? sanitize_title( (string) $entree['oeuvre'] ) : '';
	if ( '' === $titre ) {
		$titre = 'WordEnd';
	}

	if ( isset( $entree['dossier'] ) ) {
		// Univers livré dans l'extension : dossier sous assets/univers/, manifeste lisible.
		$dossier = is_string( $entree['dossier'] ) ? $entree['dossier'] : '';
		if ( ! dossier_valide( $dossier ) || ! is_readable( chemin_manifeste( $dossier ) ) ) {
			return null;
		}
		$version = version_univers( $dossier );
		$adresse = plugins_url( 'assets/univers/' . $dossier . '/manifeste.json', __FILE__ );
	} else {
		// Univers d'une autre extension : adresse absolue http(s) de son manifeste.
		$adresse = isset( $entree['manifeste'] ) && is_string( $entree['manifeste'] ) ? esc_url_raw( $entree['manifeste'], array( 'http', 'https' ) ) : '';
		if ( '' === $adresse || ! preg_match( '#^https?://[^/]#i', $adresse ) ) {
			return null;
		}
		$version = isset( $entree['version'] ) && is_scalar( $entree['version'] ) ? sanitize_text_field( (string) $entree['version'] ) : '';
		if ( '' === $version ) {
			$version = defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2';
		}
	}

	return array(
		'titre'     => $titre,
		'oeuvre'    => $oeuvre,
		'manifeste' => add_query_arg( 'ver', rawurlencode( $version ), $adresse ),
		'version'   => $version,
	);
}

/**
 * Univers disponibles (identiques pour tous les visiteurs : Batcache). Les entrées invalides
 * (slug vide, dossier hors de assets/univers/ ou sans manifeste lisible, adresse non http(s))
 * sont retirées.
 *
 * @return array<string, array{titre:string, oeuvre:string, manifeste:string, version:string}>
 */
function univers(): array {
	/**
	 * Registre des univers de WordEnd (ajouter, retirer ou renommer un univers).
	 *
	 * Chaque entrée, indexée par le slug de l'univers (assaini par sanitize_title ; vide ⇒ rejetée ;
	 * le premier de deux slugs identiques après assainissement l'emporte), est un tableau :
	 * - titre     (string) : nom affiché (libellé du papillon) ; « WordEnd » s'il est vide ;
	 * - oeuvre    (string) : slug de l'œuvre du catalogue (yume_oeuvre) dont la fiche ouvre cet
	 *                        univers (papillon, universPage) ; facultatif ;
	 * - soit dossier (string) : univers livré dans l'extension, dossier sous
	 *                        includes/wordend/assets/univers/ (un seul nom, sans « .. » ni « / ») ;
	 *                        l'entrée est retirée si son manifeste.json est illisible ;
	 * - soit manifeste (string) : adresse absolue http(s) du manifeste d'un univers fourni par une
	 *                        autre extension, avec version (string) facultative (défaut : version
	 *                        de yume-core), ajoutée en ?ver= au manifeste et à ses fichiers.
	 * « dossier » l'emporte sur « manifeste » si les deux sont donnés.
	 *
	 * Le résultat est public (window.ynWordEnd) et doit rester identique pour tous les visiteurs.
	 *
	 * @param array<string, array> $univers Par défaut : UNIVERS_INTEGRES (sukasuka).
	 */
	$brut  = apply_filters( 'yume_wordend_univers', UNIVERS_INTEGRES );
	$liste = array();
	foreach ( (array) $brut as $slug => $entree ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || isset( $liste[ $slug ] ) ) {
			continue;
		}
		$propre = assainir_univers( $entree );
		if ( null !== $propre ) {
			$liste[ $slug ] = $propre;
		}
	}
	return $liste;
}

/**
 * Univers ouvert par défaut : UNIVERS_PAR_DEFAUT s'il est disponible, sinon le premier du
 * registre ('' si aucun univers).
 *
 * @param array|null $liste Résultat de univers() s'il est déjà calculé.
 */
function univers_par_defaut( ?array $liste = null ): string {
	$liste = $liste ?? univers();
	if ( isset( $liste[ UNIVERS_PAR_DEFAUT ] ) ) {
		return UNIVERS_PAR_DEFAUT;
	}
	$slugs = array_keys( $liste );
	return $slugs ? (string) $slugs[0] : '';
}

/**
 * Slug de l'univers associé à une œuvre du catalogue (le premier du registre), '' si aucun.
 *
 * @param string $slug_oeuvre Slug (post_name) de l'œuvre.
 */
function univers_par_oeuvre( string $slug_oeuvre ): string {
	$slug_oeuvre = sanitize_title( $slug_oeuvre );
	if ( '' === $slug_oeuvre ) {
		return '';
	}
	foreach ( univers() as $slug => $univers ) {
		if ( $univers['oeuvre'] === $slug_oeuvre ) {
			return (string) $slug;
		}
	}
	return '';
}

/**
 * Univers de la page courante : celui de l'œuvre si la requête principale est la fiche (ou une
 * sous-page) d'une œuvre, '' sinon. Calculé depuis la requête seulement (même valeur pour tous
 * les visiteurs d'une URL : compatible Batcache).
 */
function univers_de_la_page(): string {
	if ( ! is_singular( 'yume_oeuvre' ) ) {
		return '';
	}
	$oeuvre = get_queried_object();
	if ( ! $oeuvre instanceof \WP_Post || 'yume_oeuvre' !== $oeuvre->post_type ) {
		return '';
	}
	return univers_par_oeuvre( (string) $oeuvre->post_name );
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
 * et pour ceux qui sont lisibles, planche <nom>.png et <nom>.planche.json, y compris la planche
 * propre d'un type d'ennemi), niveaux, décors,
 * musiques. Les fichiers référencés sont tous listés, même absents (contrôle des tests).
 *
 * @param string $dossier Dossier sous assets/univers/.
 * @return string[]
 */
function fichiers_univers( string $dossier ): array {
	if ( ! dossier_valide( $dossier ) ) {
		return array();
	}
	$manifeste = chemin_manifeste( $dossier );
	$base      = dirname( $manifeste ) . '/';
	$donnees   = lire_json( $manifeste );
	$fichiers  = array( $manifeste );
	foreach ( array( 'personnages', 'ennemis' ) as $cle ) {
		foreach ( (array) ( $donnees[ $cle ] ?? array() ) as $relatif ) {
			$chemin     = $base . (string) $relatif;
			$fichiers[] = $chemin;
			$entite     = lire_json( $chemin );
			$planches   = array( $entite['planche'] ?? '' );
			foreach ( (array) ( $entite['types'] ?? array() ) as $type ) {
				$planches[] = is_array( $type ) ? ( $type['planche'] ?? '' ) : ''; // Planche propre à un type d'ennemi.
			}
			foreach ( $planches as $planche ) {
				if ( is_string( $planche ) && '' !== $planche ) {
					$prefixe    = dirname( $chemin ) . '/' . $planche;
					$fichiers[] = $prefixe . '.png';
					$fichiers[] = $prefixe . '.planche.json';
				}
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
