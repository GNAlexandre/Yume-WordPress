<?php
/**
 * Enregistrement des blocs de la bibliothèque (yume_register_dynamic_block, sur init) et
 * insertion automatique de yume/oeuvre-infos après yume/tome-list dans les modèles qui ne
 * le contiennent pas encore (API des blocs accrochés de WordPress).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Blocs du module (dossiers de blocks/).
 *
 * @return string[]
 */
function noms_blocs(): array {
	return array(
		'library-menu',
		'banner',
		'latest-releases',
		'library-grid',
		'oeuvre-header',
		'oeuvre-infos',
		'tome-list',
		'tome-header',
		'tome-toc',
		'chapter-header',
		'chapter-nav',
		'tome-illustrations',
		'partenaires',
		'recherche',
		'oeuvre-onglets',
		'oeuvre-news',
	);
}

/**
 * Feuille commune aux blocs de fiche et de grille (fil d'Ariane, fiche technique, bannière
 * de fiche, couverture de substitution) : enregistrée avant les blocs, qui la déclarent
 * comme style dans leur block.json (poignée « yume-bibliotheque »).
 */
function enregistrer_style_commun(): void {
	$fichier = __DIR__ . '/assets/bibliotheque.css';
	wp_register_style(
		'yume-bibliotheque',
		plugins_url( 'assets/bibliotheque.css', __FILE__ ),
		array(),
		( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . ( is_readable( $fichier ) ? (string) filemtime( $fichier ) : '0' )
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style_commun', 5 );

/**
 * Script des boutons « Commencer la lecture » d'un tome qui a une page Illustrations
 * (bouton_commencer()) : mis en file par le rendu du bouton, chargé en pied de page.
 */
function enregistrer_script_debut_lecture(): void {
	$fichier = __DIR__ . '/assets/debut-lecture.js';
	wp_register_script(
		'yume-debut-lecture',
		plugins_url( 'assets/debut-lecture.js', __FILE__ ),
		array(),
		( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . ( is_readable( $fichier ) ? (string) filemtime( $fichier ) : '0' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_script_debut_lecture', 5 );

/**
 * Script de la ligne « Tomes 1 à 4 » (tomes précédents repliés, yume/tome-list) : à l'ouverture,
 * la ligne disparaît et le focus passe au premier tome affiché. Sans JavaScript, la ligne
 * disparaît aussi (CSS).
 */
function enregistrer_script_tomes_anciens(): void {
	$fichier = __DIR__ . '/assets/tomes-anciens.js';
	wp_register_script(
		'yume-tomes-anciens',
		plugins_url( 'assets/tomes-anciens.js', __FILE__ ),
		array(),
		( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . ( is_readable( $fichier ) ? (string) filemtime( $fichier ) : '0' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_script_tomes_anciens', 5 );

/**
 * Enregistre les blocs du module.
 */
function enregistrer_blocs(): void {
	if ( ! function_exists( 'yume_register_dynamic_block' ) ) {
		return;
	}
	foreach ( noms_blocs() as $nom ) {
		$type = yume_register_dynamic_block( __DIR__ . '/blocks/' . $nom );
		if ( $type instanceof \WP_Block_Type ) {
			versionner_ressources( $type, __DIR__ . '/blocks/' . $nom );
		}
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_blocs' );

/**
 * Version des feuilles et scripts d'un bloc : version de l'extension et date du fichier,
 * pour que les navigateurs rechargent un fichier modifié par une mise à jour.
 *
 * @param \WP_Block_Type $type    Bloc enregistré.
 * @param string         $dossier Dossier du bloc.
 */
function versionner_ressources( \WP_Block_Type $type, string $dossier ): void {
	$ressources = array(
		array( wp_styles(), (array) $type->style_handles, $dossier . '/style.css' ),
		array( wp_scripts(), (array) $type->view_script_handles, $dossier . '/view.js' ),
	);
	foreach ( $ressources as list( $registre, $poignees, $fichier ) ) {
		if ( ! is_readable( $fichier ) ) {
			continue;
		}
		$version = ( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . (string) filemtime( $fichier );
		foreach ( $poignees as $poignee ) {
			if ( isset( $registre->registered[ $poignee ] ) ) {
				$registre->registered[ $poignee ]->ver = $version;
			}
		}
	}
}

/**
 * Insère yume/oeuvre-infos après yume/tome-list dans un modèle ou une partie de modèle qui
 * ne contient pas déjà ce bloc (maquette Oeuvre : cartes « Équipe de traduction » et
 * « Liens » à côté des commentaires). L'équipe peut le retirer dans l'éditeur de site.
 *
 * @param string[]                               $accroches Blocs à insérer.
 * @param string                                 $position  before, after, first_child, last_child.
 * @param string                                 $ancre     Bloc d'ancrage.
 * @param \WP_Block_Template|\WP_Post|array|null $contexte  Modèle, partie ou composition.
 * @return string[]
 */
function accrocher_oeuvre_infos( $accroches, $position, $ancre, $contexte ) {
	$accroches = is_array( $accroches ) ? $accroches : array();
	if ( 'yume/tome-list' !== $ancre || 'after' !== $position || ! $contexte instanceof \WP_Block_Template ) {
		return $accroches;
	}
	if ( str_contains( (string) $contexte->content, 'wp:yume/oeuvre-infos' ) || in_array( 'yume/oeuvre-infos', $accroches, true ) ) {
		return $accroches;
	}
	/**
	 * Active l'insertion automatique de yume/oeuvre-infos après yume/tome-list.
	 *
	 * @param bool                $actif    Insertion active.
	 * @param \WP_Block_Template  $contexte Modèle.
	 */
	if ( ! apply_filters( 'yume_bibliotheque_inserer_oeuvre_infos', true, $contexte ) ) {
		return $accroches;
	}
	$accroches[] = 'yume/oeuvre-infos';
	return $accroches;
}
add_filter( 'hooked_block_types', __NAMESPACE__ . '\\accrocher_oeuvre_infos', 10, 4 );
