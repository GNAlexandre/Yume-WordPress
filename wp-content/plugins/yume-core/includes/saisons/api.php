<?php
/**
 * API PHP publique du module saisons (docs/07-themes-saisonniers.md §5, contrat §7).
 * Signatures figées : toute évolution passe d'abord par le cahier des charges.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Saisons\calendrier_normalise;
use function Yume\Core\Saisons\definitions;
use function Yume\Core\Saisons\lignes_valides;
use function Yume\Core\Saisons\saison_active;
use function Yume\Core\Saisons\saison_du_calendrier;
use function Yume\Core\Saisons\slug_connu;

/**
 * Les six thèmes saisonniers, dans l'ordre de priorité du calendrier.
 *
 * @return array<string,array{nom:string,type:string,logo:string}> slug => ['nom' => 'Automne',
 *         'type' => 'saison'|'fete', 'logo' => 'automne.webp' (fichier du thème, assets/img/saisons/)].
 */
function yume_saisons(): array {
	return definitions();
}

/**
 * Saison active pour la requête courante ('' : thème de base).
 *
 * Ordre : aperçu ?yn-saison=<slug|aucun> (compte ayant yume_reglages ; la page est alors exclue
 * des caches) > réglage saison_mode (aucun → '', manuel → saison_manuelle) > calendrier à la date
 * courante. Filtre final yume_saison_active ; résultat mémorisé pour la requête.
 */
function yume_saison_active(): string {
	return saison_active();
}

/**
 * Les six lignes du calendrier (réglage saison_calendrier fusionné avec les défauts), dans
 * l'ordre de priorité.
 *
 * @return array<int,array{theme:string,debut:string,fin:string,actif:bool}> Dates « JJ/MM ».
 */
function yume_saison_calendrier(): array {
	$valeur = function_exists( 'yume_setting' ) ? yume_setting( 'saison_calendrier', array() ) : array();
	return calendrier_normalise( $valeur );
}

/**
 * Thème du calendrier à une date (fuseau du site) : première ligne active dont la période
 * contient la date ; '' si aucune. Ne tient compte ni du mode ni de l'aperçu.
 *
 * @param DateTimeInterface $date       Date.
 * @param array|null        $calendrier Lignes {theme, debut, fin, actif} à utiliser telles
 *                                      quelles (null : calendrier du site). Les lignes
 *                                      invalides sont ignorées.
 */
function yume_saison_pour_date( DateTimeInterface $date, ?array $calendrier = null ): string {
	return saison_du_calendrier( $date, lignes_valides( $calendrier ?? yume_saison_calendrier() ) );
}

/**
 * Nom affiché d'un thème : « Automne », « Thème de base » pour '', '' pour un slug inconnu.
 *
 * @param string $slug Slug.
 */
function yume_saison_nom( string $slug ): string {
	if ( '' === $slug ) {
		return did_action( 'init' ) ? __( 'Thème de base', 'yume-core' ) : 'Thème de base';
	}
	return slug_connu( $slug ) ? definitions()[ $slug ]['nom'] : '';
}
