<?php
/**
 * Statistiques, graphiques : SVG inline accessibles générés en PHP, sans bibliothèque ni
 * JavaScript, et variations lisibles.
 *
 * - svg_courbe() : une ou deux séries datées (vues et visiteurs, favoris et suiveurs…), aire sous
 *   la première, quadrillage ; chaque date est une bande transparente avec son <title> (infobulle
 *   native au survol) ; axes en HTML sous le dessin (le texte n'est pas déformé par le viewBox) ;
 *   tableau des valeurs dans un <details> (tableau_kpi() de la vue Indicateurs).
 * - svg_barres_h() : barres horizontales (pays, répartitions), libellés et valeurs écrits.
 * - svg_sparkline() : tendance d'une carte de chiffre.
 * - variation_html() : ▲ +12 % / ▼ −3 % / = 0 % / « — » : symbole et texte, jamais la couleur seule.
 *
 * Chaque SVG porte role="img" et un aria-label qui résume les données. Les couleurs viennent des
 * classes CSS (assets/statistiques.css → variables --wp--preset--color--* du thème), donc
 * suivent les thèmes Nuit, Papier et Sépia ; la seconde série est en pointillés (distinction sans
 * la couleur). Fonctions pures (aucune requête) : testables directement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

use function Yume\Core\Planning\format_fr;
use function Yume\Core\Planning\fuseau;
use function Yume\Core\Planning\tableau_kpi;

defined( 'ABSPATH' ) || exit;

/** Largeur du repère des courbes (unités du viewBox). */
const COURBE_LARGEUR = 1000;

/** Marge haute du repère des courbes : le maximum n'est pas collé au bord. */
const COURBE_MARGE = 8;

/** Nombre maximal de dates écrites sous une courbe. */
const COURBE_REPERES = 5;

/** Hauteur d'une ligne de svg_barres_h() (pixels). */
const BARRE_HAUTEUR = 34;

/*
 * -----------------------------------------------------------------------------
 * Aides
 * -----------------------------------------------------------------------------
 */

/**
 * Horodatage (midi, fuseau du planning) d'une date « Y-m-d » ou d'un mois « Y-m », 0 si
 * invalide.
 *
 * @param string $date Date.
 */
function graphique_ts( string $date ): int {
	$date = 7 === strlen( $date ) ? $date . '-01' : $date;
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return 0;
	}
	$jour = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' 12:00:00', fuseau() );
	return $jour ? $jour->getTimestamp() : 0;
}

/**
 * Date lisible d'un point (« 20 sept. », « 20 sept. 2026 », « sept. 2026 » pour un mois).
 *
 * @param string $date  Date « Y-m-d » ou « Y-m ».
 * @param string $unite 'jour' ou 'mois'.
 * @param bool   $annee Avec l'année (jours seulement).
 */
function graphique_date( string $date, string $unite = 'jour', bool $annee = false ): string {
	$ts = graphique_ts( $date );
	if ( ! $ts ) {
		return $date;
	}
	if ( 'mois' === $unite ) {
		return format_fr( $ts, 'M Y' );
	}
	return format_fr( $ts, $annee ? 'j M Y' : 'j M' );
}

/**
 * Nombre lisible (séparateur de milliers de la langue du site), « — » si inconnu.
 *
 * @param mixed $valeur Valeur (null : inconnue).
 */
function graphique_nombre( $valeur ): string {
	if ( null === $valeur || ! is_numeric( $valeur ) ) {
		return '—';
	}
	$valeur = (float) $valeur;
	return number_format_i18n( $valeur, floor( $valeur ) === $valeur ? 0 : 1 );
}

/**
 * Coordonnée arrondie pour un attribut SVG (un chiffre après la virgule, point décimal).
 *
 * @param float $valeur Valeur.
 */
function graphique_coord( float $valeur ): string {
	return rtrim( rtrim( number_format( $valeur, 1, '.', '' ), '0' ), '.' );
}

/**
 * Pas « rond » supérieur ou égal à $valeur (1, 1,5, 2, 2,5, 3, 4, 5, 6 ou 8 × 10^n), au moins 1 (comptes entiers).
 *
 * @param float $valeur Valeur.
 */
function graphique_pas( float $valeur ): float {
	if ( $valeur <= 1 ) {
		return 1.0;
	}
	$puissance = 10 ** floor( log10( $valeur ) );
	$facteur   = $valeur / $puissance;
	foreach ( array( 1, 1.5, 2, 2.5, 3, 4, 5, 6, 8 ) as $rond ) {
		if ( $facteur <= $rond + 1e-9 ) {
			return $rond * $puissance;
		}
	}
	return 10 * $puissance;
}

/**
 * Échelle d'une courbe : bas et haut ronds, graduation du milieu ronde aussi. Séries additives
 * (vues, inscriptions) : à partir de 0 ; stocks (favoris, suiveurs) : à partir d'un multiple du
 * pas sous le minimum, pour que les variations restent visibles (les graduations sont écrites).
 *
 * @param float[] $valeurs Valeurs connues.
 * @param bool    $zero    Partir de 0.
 * @return array{bas:float,haut:float}
 */
function graphique_echelle( array $valeurs, bool $zero ): array {
	$min = $valeurs ? (float) min( $valeurs ) : 0.0;
	$max = $valeurs ? (float) max( $valeurs ) : 0.0;
	$bas = 0.0;
	$pas = graphique_pas( $max / 2 );
	if ( ! $zero && $min > 0 ) {
		$pas = graphique_pas( max( 1.0, $max - $min ) / 2 );
	}
	for ( $i = 0; $i < 40; $i++ ) {
		$bas = $zero ? 0.0 : floor( $min / $pas ) * $pas;
		if ( $bas + 2 * $pas >= $max ) {
			break;
		}
		$pas = graphique_pas( $pas * 1.01 );
	}
	return array(
		'bas'  => $bas,
		'haut' => $bas + 2 * $pas,
	);
}

/**
 * Message « aucune donnée » d'un graphique.
 *
 * @param string $texte Texte (vide : message par défaut).
 */
function graphique_vide( string $texte = '' ): string {
	return '<p class="yn-muted yn-stats__vide">' . esc_html( '' !== $texte ? $texte : __( 'Aucune donnée pour le moment.', 'yume-core' ) ) . '</p>';
}

/*
 * -----------------------------------------------------------------------------
 * Variation
 * -----------------------------------------------------------------------------
 */

/**
 * Variation lisible : « ▲ +12,5 % » (hausse), « ▼ −3 % » (baisse), « = 0 % » (stable) ou « — »
 * (non définie : pas de valeur précédente). Le symbole et le signe portent le sens ; la classe
 * (yn-stats__variation--hausse, --baisse, --stable, --inconnue) n'ajoute que la couleur.
 *
 * @param float|null $variation Variation (pourcentage, ou écart absolu avec $unite = '').
 * @param string     $unite     Suffixe : « % » (défaut) ou '' pour un écart absolu (« +38 »).
 */
function variation_html( ?float $variation, string $unite = '%' ): string {
	if ( null === $variation || is_nan( $variation ) ) {
		return '<span class="yn-stats__variation yn-stats__variation--inconnue"><span aria-hidden="true">—</span><span class="yn-visually-hidden">' . esc_html__( 'variation non disponible', 'yume-core' ) . '</span></span>';
	}
	$valeur  = round( $variation, 1 );
	$absolue = abs( $valeur );
	$nombre  = number_format_i18n( $absolue, floor( $absolue ) === $absolue ? 0 : 1 ) . ( '' !== $unite ? "\u{00A0}" . $unite : '' );
	if ( $valeur > 0 ) {
		$classe  = 'hausse';
		$symbole = '▲';
		$texte   = '+' . $nombre;
	} elseif ( $valeur < 0 ) {
		$classe  = 'baisse';
		$symbole = '▼';
		$texte   = "\u{2212}" . $nombre;
	} else {
		$classe  = 'stable';
		$symbole = '=';
		$texte   = $nombre;
	}
	return '<span class="yn-stats__variation yn-stats__variation--' . $classe . '"><span aria-hidden="true">' . $symbole . '</span> ' . esc_html( $texte ) . '</span>';
}

/*
 * -----------------------------------------------------------------------------
 * Courbes
 * -----------------------------------------------------------------------------
 */

/**
 * Courbe d'une ou deux séries datées, accessible : SVG role="img" (aria-label résumant chaque
 * série), une bande par date avec son <title> (infobulle native), légende et axes en HTML,
 * tableau des valeurs dans un <details>.
 *
 * @param array $series  Séries (deux au plus) : array{libelle:string, points:array<int,array{date:string,valeur:int|float|null}>}.
 *                       Une valeur null (donnée absente ce jour) est sautée par le tracé et vaut « — » dans le tableau.
 * @param array $options titre (résumé et légende du tableau), id (préfixe d'identifiant unique),
 *                       hauteur (240), unite ('jour' ou 'mois'), cumul (true : séries additives,
 *                       total dans le résumé ; false : stocks, « de … à … »), aire (true),
 *                       tableau (true), vide (message sans donnée).
 */
function svg_courbe( array $series, array $options = array() ): string {
	$options = array_merge(
		array(
			'titre'   => __( 'Évolution', 'yume-core' ),
			'id'      => 'yn-stats-courbe',
			'hauteur' => 240,
			'unite'   => 'jour',
			'cumul'   => true,
			'aire'    => true,
			'tableau' => true,
			'vide'    => '',
		),
		$options
	);
	$unite   = 'mois' === $options['unite'] ? 'mois' : 'jour';
	$hauteur = max( 60, (int) $options['hauteur'] );
	$largeur = COURBE_LARGEUR;

	// Séries normalisées (deux au plus) et dates communes, triées.
	$propres = array();
	$dates   = array();
	foreach ( array_slice( array_values( $series ), 0, 2 ) as $serie ) {
		if ( ! is_array( $serie ) ) {
			continue;
		}
		$valeurs = array();
		foreach ( (array) ( $serie['points'] ?? array() ) as $point ) {
			$date = is_array( $point ) ? (string) ( $point['date'] ?? '' ) : '';
			if ( '' === $date ) {
				continue;
			}
			$valeur           = $point['valeur'] ?? null;
			$valeurs[ $date ] = is_numeric( $valeur ) ? max( 0.0, (float) $valeur ) : null;
			$dates[ $date ]   = true;
		}
		ksort( $valeurs, SORT_STRING );
		$propres[] = array(
			'libelle' => (string) ( $serie['libelle'] ?? '' ),
			'valeurs' => $valeurs,
		);
	}
	$dates = array_keys( $dates );
	sort( $dates, SORT_STRING );
	$nb = count( $dates );
	if ( ! $propres || ! $nb ) {
		return graphique_vide( (string) $options['vide'] );
	}

	$connues = array();
	foreach ( $propres as $serie ) {
		$connues = array_merge( $connues, array_values( array_filter( $serie['valeurs'], 'is_float' ) ) );
	}
	$echelle = graphique_echelle( $connues, (bool) $options['cumul'] );
	$bas     = $echelle['bas'];
	$max     = $echelle['haut'];
	$x       = static fn( int $i ): float => 1 === $nb ? $largeur / 2 : $i * $largeur / ( $nb - 1 );
	$y       = static fn( float $v ): float => $hauteur - ( ( $v - $bas ) / ( $max - $bas ) ) * ( $hauteur - COURBE_MARGE );
	$id      = sanitize_html_class( (string) $options['id'], 'yn-stats-courbe' );

	// Résumé (aria-label) : période puis, par série, total ou évolution et maximum.
	$debut  = graphique_date( $dates[0], $unite, true );
	$fin    = graphique_date( $dates[ $nb - 1 ], $unite, true );
	$resume = 1 === $nb
		/* translators: 1: titre du graphique, 2: date */
		? sprintf( __( '%1$s, le %2$s', 'yume-core' ), $options['titre'], $debut )
		/* translators: 1: titre du graphique, 2: première date, 3: dernière date */
		: sprintf( __( '%1$s, du %2$s au %3$s', 'yume-core' ), $options['titre'], $debut, $fin );
	$parts = array();
	foreach ( $propres as $serie ) {
		$valeurs = array_filter( $serie['valeurs'], 'is_float' );
		if ( ! $valeurs ) {
			/* translators: %s : série */
			$parts[] = sprintf( __( '%s : aucune donnée', 'yume-core' ), $serie['libelle'] );
			continue;
		}
		$pic     = (float) max( $valeurs );
		$jour    = graphique_date( (string) array_search( $pic, $valeurs, true ), $unite, true );
		$parts[] = $options['cumul']
			/* translators: 1: série, 2: total, 3: maximum, 4: date du maximum */
			? sprintf( __( '%1$s : %2$s au total, au plus %3$s (%4$s)', 'yume-core' ), $serie['libelle'], graphique_nombre( array_sum( $valeurs ) ), graphique_nombre( $pic ), $jour )
			/* translators: 1: série, 2: première valeur, 3: dernière valeur, 4: maximum */
			: sprintf( __( '%1$s : de %2$s à %3$s, au plus %4$s', 'yume-core' ), $serie['libelle'], graphique_nombre( reset( $valeurs ) ), graphique_nombre( end( $valeurs ) ), graphique_nombre( $pic ) );
	}
	$resume .= ' : ' . implode( ' ; ', $parts ) . '.';

	// Légende (trait plein / pointillés : la couleur n'est pas seule).
	$html  = '<figure class="yn-stats__graphique">';
	$html .= '<ul class="yn-stats__legende">';
	foreach ( $propres as $i => $serie ) {
		$html .= '<li><span class="yn-stats__trait yn-stats__trait--' . ( $i + 1 ) . '" aria-hidden="true"></span>' . esc_html( $serie['libelle'] ) . '</li>';
	}
	$html .= '</ul>';

	// Repère : axe des valeurs (HTML), dessin.
	$html .= '<div class="yn-stats__repere" style="--yn-stats-hauteur:' . (int) $hauteur . 'px">';
	$html .= '<div class="yn-stats__axe-y" aria-hidden="true"><span>' . esc_html( graphique_nombre( $max ) ) . '</span><span>' . esc_html( graphique_nombre( ( $bas + $max ) / 2 ) ) . '</span><span>' . esc_html( graphique_nombre( $bas ) ) . '</span></div>';
	$html .= '<svg class="yn-stats__svg" viewBox="0 0 ' . $largeur . ' ' . $hauteur . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $resume ) . '" focusable="false">';
	foreach ( array( 0.0, 0.5, 1.0 ) as $part ) {
		$ligne = graphique_coord( $y( $bas + ( $max - $bas ) * $part ) );
		$html .= '<line class="yn-stats__grille" x1="0" y1="' . $ligne . '" x2="' . $largeur . '" y2="' . $ligne . '" vector-effect="non-scaling-stroke"></line>';
	}
	foreach ( $propres as $i => $serie ) {
		$coords = array();
		foreach ( $dates as $j => $date ) {
			$valeur = $serie['valeurs'][ $date ] ?? null;
			if ( null !== $valeur ) {
				$coords[] = graphique_coord( $x( $j ) ) . ',' . graphique_coord( $y( $valeur ) );
			}
		}
		if ( ! $coords ) {
			continue;
		}
		if ( 1 === count( $coords ) ) {
			// Un seul point : petit trait horizontal pour qu'il reste visible.
			$point  = array_map( 'floatval', explode( ',', $coords[0] ) );
			$coords = array(
				graphique_coord( max( 0.0, $point[0] - 6 ) ) . ',' . graphique_coord( $point[1] ),
				graphique_coord( min( (float) $largeur, $point[0] + 6 ) ) . ',' . graphique_coord( $point[1] ),
			);
		}
		if ( 0 === $i && $options['aire'] && count( $coords ) > 1 ) {
			$premier = explode( ',', $coords[0] )[0];
			$dernier = explode( ',', end( $coords ) )[0];
			$html   .= '<polygon class="yn-stats__aire" points="' . esc_attr( $premier . ',' . $hauteur . ' ' . implode( ' ', $coords ) . ' ' . $dernier . ',' . $hauteur ) . '"></polygon>';
		}
		$html .= '<polyline class="yn-stats__ligne yn-stats__ligne--' . ( $i + 1 ) . '" points="' . esc_attr( implode( ' ', $coords ) ) . '" vector-effect="non-scaling-stroke"></polyline>';
	}
	// Bandes de survol : une par date, avec son <title> (toutes les séries de la date).
	foreach ( $dates as $j => $date ) {
		$gauche = 0 === $j ? 0.0 : ( $x( $j - 1 ) + $x( $j ) ) / 2;
		$droite = $nb - 1 === $j ? (float) $largeur : ( $x( $j ) + $x( $j + 1 ) ) / 2;
		$infos  = array();
		foreach ( $propres as $serie ) {
			$infos[] = $serie['libelle'] . ' : ' . graphique_nombre( $serie['valeurs'][ $date ] ?? null );
		}
		$html .= '<rect class="yn-stats__zone" x="' . graphique_coord( $gauche ) . '" y="0" width="' . graphique_coord( max( 0.1, $droite - $gauche ) ) . '" height="' . $hauteur . '"><title>' . esc_html( graphique_date( $date, $unite, true ) . ' — ' . implode( ' · ', $infos ) ) . '</title></rect>';
	}
	$html .= '</svg>';

	// Axe des dates (HTML) : quelques repères régulièrement espacés.
	$reperes = array_values( array_unique( array_map( static fn( int $k ): int => (int) round( $k * ( $nb - 1 ) / ( COURBE_REPERES - 1 ) ), range( 0, COURBE_REPERES - 1 ) ) ) );
	$html   .= '<div class="yn-stats__axe-x" aria-hidden="true">';
	foreach ( $reperes as $j ) {
		$html .= '<span>' . esc_html( graphique_date( $dates[ $j ], $unite ) ) . '</span>';
	}
	$html .= '</div></div>';

	// Tableau des valeurs (du plus récent au plus ancien).
	if ( $options['tableau'] ) {
		$entetes = array( 'mois' === $unite ? __( 'Mois', 'yume-core' ) : __( 'Date', 'yume-core' ) );
		foreach ( $propres as $serie ) {
			$entetes[] = $serie['libelle'];
		}
		$lignes = array();
		foreach ( array_reverse( $dates ) as $date ) {
			$ligne = array( esc_html( graphique_date( $date, $unite, true ) ) );
			foreach ( $propres as $serie ) {
				$ligne[] = esc_html( graphique_nombre( $serie['valeurs'][ $date ] ?? null ) );
			}
			$lignes[] = $ligne;
		}
		$html .= '<details class="yn-kpi__details yn-stats__donnees"><summary>' . esc_html__( 'Voir le tableau des valeurs', 'yume-core' ) . '</summary>';
		/* translators: %s : titre du graphique */
		$html .= tableau_kpi( $id . '-tableau', sprintf( __( '%s (du plus récent au plus ancien)', 'yume-core' ), $options['titre'] ), $entetes, $lignes ) . '</details>';
	}
	return $html . '</figure>';
}

/**
 * Barres horizontales accessibles (pays, répartitions) : libellé et valeur écrits au-dessus de
 * chaque barre, <title> par barre, résumé en aria-label. Sans viewBox : le texte garde sa taille
 * et les barres s'étirent en pourcentage de la largeur.
 *
 * @param array $barres  Barres : array{libelle:string, valeur:int|float, texte?:string} (texte : valeur affichée, nombre par défaut).
 * @param array $options titre (résumé), max (valeur de la barre pleine ; défaut : la plus grande), vide.
 */
function svg_barres_h( array $barres, array $options = array() ): string {
	$options = array_merge(
		array(
			'titre' => __( 'Répartition', 'yume-core' ),
			'max'   => 0,
			'vide'  => '',
		),
		$options
	);
	$propres = array();
	foreach ( $barres as $barre ) {
		if ( ! is_array( $barre ) || '' === (string) ( $barre['libelle'] ?? '' ) ) {
			continue;
		}
		$valeur    = max( 0.0, (float) ( $barre['valeur'] ?? 0 ) );
		$propres[] = array(
			'libelle' => (string) $barre['libelle'],
			'valeur'  => $valeur,
			'texte'   => (string) ( $barre['texte'] ?? graphique_nombre( $valeur ) ),
		);
	}
	if ( ! $propres ) {
		return graphique_vide( (string) $options['vide'] );
	}
	$max = (float) $options['max'] > 0 ? (float) $options['max'] : max( array_column( $propres, 'valeur' ) );
	$max = $max > 0 ? $max : 1.0;

	$resume = $options['titre'] . ' : ' . implode( ', ', array_map( static fn( array $b ): string => $b['libelle'] . ' ' . $b['texte'], $propres ) ) . '.';
	$html   = '<svg class="yn-stats__barres-h" width="100%" height="' . ( count( $propres ) * BARRE_HAUTEUR ) . '" role="img" aria-label="' . esc_attr( $resume ) . '" focusable="false">';
	foreach ( $propres as $i => $barre ) {
		$haut  = $i * BARRE_HAUTEUR;
		$pct   = min( 100.0, 100 * $barre['valeur'] / $max );
		$html .= '<g><title>' . esc_html( $barre['libelle'] . ' : ' . $barre['texte'] ) . '</title>';
		$html .= '<text class="yn-stats__barre-libelle" x="0" y="' . ( $haut + 14 ) . '">' . esc_html( mb_strimwidth( $barre['libelle'], 0, 40, '…' ) ) . '</text>';
		$html .= '<text class="yn-stats__barre-valeur" x="100%" y="' . ( $haut + 14 ) . '" text-anchor="end">' . esc_html( $barre['texte'] ) . '</text>';
		$html .= '<rect class="yn-stats__barre-piste" x="0" y="' . ( $haut + 20 ) . '" width="100%" height="8" rx="4"></rect>';
		$html .= '<rect class="yn-stats__barre-remplie" x="0" y="' . ( $haut + 20 ) . '" width="' . graphique_coord( $pct ) . '%" height="8" rx="4"></rect></g>';
	}
	return $html . '</svg>';
}

/**
 * Petite courbe de tendance (carte de chiffre) : SVG role="img", libellé fourni par l'appelant.
 * Chaîne vide s'il y a moins de deux valeurs connues.
 *
 * @param array  $valeurs Valeurs dans l'ordre chronologique (null : inconnue, sautée).
 * @param string $libelle Résumé lu par les lecteurs d'écran (« Vues : de 310 à 585 »).
 */
function svg_sparkline( array $valeurs, string $libelle ): string {
	$connues = array();
	$valeurs = array_values( $valeurs );
	foreach ( $valeurs as $i => $valeur ) {
		if ( is_numeric( $valeur ) ) {
			$connues[ $i ] = max( 0.0, (float) $valeur );
		}
	}
	if ( count( $connues ) < 2 ) {
		return '';
	}
	$nb     = count( $valeurs );
	$max    = max( $connues ) > 0 ? max( $connues ) : 1.0;
	$coords = array();
	foreach ( $connues as $i => $valeur ) {
		$coords[] = graphique_coord( $i * 100 / max( 1, $nb - 1 ) ) . ',' . graphique_coord( 28 - $valeur / $max * 26 );
	}
	$html  = '<svg class="yn-stats__sparkline" viewBox="0 0 100 28" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $libelle ) . '" focusable="false">';
	$html .= '<polyline class="yn-stats__ligne yn-stats__ligne--1" points="' . esc_attr( implode( ' ', $coords ) ) . '" vector-effect="non-scaling-stroke"></polyline>';
	return $html . '</svg>';
}
