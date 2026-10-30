<?php
/**
 * Réglages du module saisons : section « Thème saisonnier » de Yume → Réglages et de la vue
 * « Réglages » de l'espace équipe (filtres yume_reglages_sections et yume_reglages_champs du
 * module core), assainissement du calendrier (type de champ saisons, rendu par le core).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Saisons;

defined( 'ABSPATH' ) || exit;

/** Identifiant de la section de réglages. */
const SECTION_REGLAGES = 'saisons';

/**
 * Section « Thème saisonnier ».
 *
 * @param mixed $sections Sections (id => titre).
 * @return array
 */
function sections_reglages( $sections ) {
	$sections                     = is_array( $sections ) ? $sections : array();
	$sections[ SECTION_REGLAGES ] = __( 'Thème saisonnier', 'yume-core' );
	return $sections;
}
add_filter( 'yume_reglages_sections', __NAMESPACE__ . '\\sections_reglages' );

/**
 * Champs de la section : mode, thème forcé, calendrier (type saisons), décors, arbre décoratif.
 *
 * @param mixed $champs Champs.
 * @return array
 */
function champs_reglages( $champs ) {
	$champs = is_array( $champs ) ? $champs : array();
	$themes = array( '' => yume_saison_nom( '' ) );
	foreach ( definitions() as $slug => $definition ) {
		$themes[ $slug ] = $definition['nom'];
	}
	$champs[] = array(
		'key'         => 'saison_mode',
		'label'       => __( 'Pilotage du thème', 'yume-core' ),
		'type'        => 'select',
		'section'     => SECTION_REGLAGES,
		'default'     => 'auto',
		'options'     => array(
			'auto'   => __( 'Selon le calendrier', 'yume-core' ),
			'manuel' => __( 'Thème choisi ci-dessous', 'yume-core' ),
			'aucun'  => __( 'Thème de base toute l’année', 'yume-core' ),
		),
		'description' => __( 'Le thème saisonnier change le logo et les couleurs du site (pas le thème Sépia choisi par un lecteur).', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'saison_manuelle',
		'label'       => __( 'Thème choisi', 'yume-core' ),
		'type'        => 'select',
		'section'     => SECTION_REGLAGES,
		'default'     => '',
		'options'     => $themes,
		'description' => __( 'Utilisé seulement avec « Thème choisi ci-dessous ».', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'saison_calendrier',
		'label'       => __( 'Calendrier', 'yume-core' ),
		'type'        => 'saisons',
		'section'     => SECTION_REGLAGES,
		'default'     => calendrier_par_defaut(),
		'sanitize'    => __NAMESPACE__ . '\\assainir_champ_calendrier',
		'lignes'      => __NAMESPACE__ . '\\lignes_formulaire',
		'etat'        => __NAMESPACE__ . '\\phrase_etat',
		'description' => __( 'Dates au format JJ/MM, bornes incluses, heure de Paris ; une période peut passer le Nouvel An (Hiver : 21/12 → 19/03). La première ligne active qui contient la date l’emporte : les fêtes passent avant les saisons. « Aperçu » montre le site avec ce thème, pour vous seulement.', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'saison_decor',
		'label'       => __( 'Décors animés', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => SECTION_REGLAGES,
		'default'     => true,
		'description' => __( 'Quelques décors légers dans l’en-tête (pétales, feuilles, flocons…), jamais pour les lecteurs qui réduisent les animations.', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'saison_sprite',
		'label'       => __( 'Arbre décoratif', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => SECTION_REGLAGES,
		'default'     => true,
		'description' => __( 'Arbre de la saison en fond de page, à droite, en filigrane ; jamais sur les chapitres ni sous 1 024 px.', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages' );

/**
 * Callback sanitize du champ saison_calendrier (assainir_champ() du core lui passe la saisie et
 * le champ) : la valeur précédente est le calendrier enregistré.
 *
 * @param mixed $valeur Saisie.
 * @return array<int,array{theme:string,debut:string,fin:string,actif:bool}>
 */
function assainir_champ_calendrier( $valeur ): array {
	return assainir_calendrier( $valeur );
}

/**
 * Assainit le calendrier : toujours les six lignes, dans l'ordre de priorité figé ; ligne de
 * thème inconnu ignorée ; ligne absente ou borne absente → valeur précédente ; date invalide →
 * valeur précédente de la ligne, avec un avertissement (add_settings_error) ; case « Actif »
 * absente → valeur précédente (le formulaire envoie toujours 0 ou 1).
 *
 * @param mixed      $valeur    Saisie (slug => {debut, fin, actif} ou liste de {theme, …}).
 * @param array|null $precedent Calendrier précédent (null : celui du site).
 * @return array<int,array{theme:string,debut:string,fin:string,actif:bool}>
 */
function assainir_calendrier( $valeur, ?array $precedent = null ): array {
	$avant  = indexer_lignes( calendrier_normalise( $precedent ?? yume_saison_calendrier() ) );
	$saisie = indexer_lignes( $valeur );
	$sortie = array();
	foreach ( ORDRE as $slug ) {
		$ligne = $avant[ $slug ];
		if ( isset( $saisie[ $slug ] ) ) {
			foreach ( array( 'debut', 'fin' ) as $borne ) {
				if ( ! array_key_exists( $borne, $saisie[ $slug ] ) ) {
					continue;
				}
				$date = date_valide( $saisie[ $slug ][ $borne ] );
				if ( '' !== $date ) {
					$ligne[ $borne ] = $date;
					continue;
				}
				signaler_date_invalide( $slug, $borne, $saisie[ $slug ][ $borne ], $ligne[ $borne ] );
			}
			if ( array_key_exists( 'actif', $saisie[ $slug ] ) ) {
				$ligne['actif'] = booleen( $saisie[ $slug ]['actif'], (bool) $ligne['actif'] );
			}
		}
		$sortie[] = array(
			'theme' => $slug,
			'debut' => (string) $ligne['debut'],
			'fin'   => (string) $ligne['fin'],
			'actif' => (bool) $ligne['actif'],
		);
	}
	return $sortie;
}

/**
 * Avertissement de la page de réglages pour une date refusée.
 *
 * @param string $slug      Thème.
 * @param string $borne     debut ou fin.
 * @param mixed  $saisie    Saisie refusée.
 * @param string $conservee Valeur conservée.
 */
function signaler_date_invalide( string $slug, string $borne, $saisie, string $conservee ): void {
	if ( ! function_exists( 'add_settings_error' ) ) {
		return;
	}
	$texte = is_scalar( $saisie ) ? mb_substr( sanitize_text_field( (string) $saisie ), 0, 20 ) : '';
	add_settings_error(
		'yume_reglages',
		'yume_saison_' . $slug . '_' . $borne,
		sprintf(
			'debut' === $borne
				/* translators: 1: thème, 2: saisie refusée, 3: date conservée (JJ/MM) */
				? __( 'Thème saisonnier « %1$s » : début « %2$s » invalide (format JJ/MM), la date précédente (%3$s) est conservée.', 'yume-core' )
				/* translators: 1: thème, 2: saisie refusée, 3: date conservée (JJ/MM) */
				: __( 'Thème saisonnier « %1$s » : fin « %2$s » invalide (format JJ/MM), la date précédente (%3$s) est conservée.', 'yume-core' ),
			yume_saison_nom( $slug ),
			$texte,
			$conservee
		)
	);
}
