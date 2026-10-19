<?php
/**
 * Statistiques, vue de l'espace équipe (?vue=statistiques, menu « Site », avant « Indicateurs »).
 *
 * Rendu serveur complet, sans JavaScript : formulaires GET et liens. Paramètres :
 *
 *   periode = 7 | 30 (défaut) | 90 | 365          conservée d'un onglet à l'autre
 *   onglet  = apercu (défaut) | trafic | oeuvres | lecteurs
 *   tri     = classement de l'onglet Œuvres (favoris, suiveurs, lecteurs, vues, nouveaux, croissance)
 *   pg      = page (classement, liste nominative)
 *   oeuvre  = ID : fiche statistique d'une œuvre, avec la liste nominative des lecteurs qui la
 *             suivent (liste = favoris | suiveurs, q = recherche sur le pseudo, tri = date | pseudo)
 *             réservée aux comptes qui ont yume_voir_lecteurs (peut_voir_lecteurs()) ; les autres
 *             voient un encart « réservé aux gérants » avec le seul chiffre.
 *
 * Données : fonctions des autres fichiers du module (trafic_*(), source_trafic(), classement(),
 * fiche_oeuvre(), resume_lecteurs(), lecteurs_oeuvre(), url_export()…), appelées par
 * vue_donnees() seulement si elles existent : sinon « Aucune donnée pour le moment ». Le filtre
 * yume_statistiques_vue_donnees court-circuite ces appels (tests, démonstrations).
 *
 * Capacité yume_voir_statistiques (toute l'équipe) : agrégats seulement, aucun lecteur nommé hors
 * de la liste nominative de la fiche d'œuvre. Feuille assets/statistiques.css (yume-statistiques,
 * dépend de yume-kpi pour les tableaux) ; graphiques SVG de graphiques.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

use function Yume\Core\Planning\champ_select;
use function Yume\Core\Planning\champs_caches_vue;
use function Yume\Core\Planning\format_fr;
use function Yume\Core\Planning\get_cle;
use function Yume\Core\Planning\get_entier;
use function Yume\Core\Planning\il_y_a;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\ouvrir_racine;
use function Yume\Core\Planning\pagination_vue;
use function Yume\Core\Planning\tableau_kpi;
use function Yume\Core\Planning\tete_vue;
use function Yume\Core\Planning\titre_brut;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

/** Clé de la vue dans l'espace équipe (?vue=statistiques). */
const VUE_CLE = 'statistiques';

/** Poignée de la feuille de style de la vue. */
const VUE_STYLE = 'yume-statistiques';

/** Œuvres des tops de l'aperçu. */
const VUE_TOP = 5;

/** Contenus listés dans l'onglet Trafic. */
const VUE_CONTENUS = 20;

/*
 * -----------------------------------------------------------------------------
 * Déclaration, style, adresses
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare la vue dans l'espace équipe (filtre yume_vues_equipe, priorité 5 : avant
 * « Indicateurs » dans le menu « Site »).
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue( $vues ): array {
	$vues            = is_array( $vues ) ? $vues : array();
	$vues[ VUE_CLE ] = array(
		'libelle'  => __( 'Statistiques', 'yume-core' ),
		'capacite' => CAPACITE_STATS,
		'groupe'   => 'site',
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_statistiques',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue', 5 );

/**
 * Feuille de style de la vue (chargée seulement quand la vue est rendue ; s'appuie sur celle des
 * Indicateurs pour les tableaux et le <details> des données).
 */
function enregistrer_style_statistiques(): void {
	wp_register_style( VUE_STYLE, YUME_CORE_URL . 'includes/statistiques/assets/statistiques.css', array( 'yume-kpi' ), YUME_CORE_VERSION );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style_statistiques' );

/**
 * Adresse de la vue Statistiques (url_vue_equipe( 'statistiques', … )) ; la période par défaut
 * et l'onglet « Aperçu » sont omis, comme les valeurs vides.
 *
 * @param array $args Paramètres : periode, onglet, tri, pg, oeuvre, liste, q.
 */
function url_statistiques( array $args = array() ): string {
	if ( isset( $args['periode'] ) && PERIODE_DEFAUT === periode_valide( $args['periode'] ) ) {
		unset( $args['periode'] );
	}
	if ( 'apercu' === ( $args['onglet'] ?? '' ) ) {
		unset( $args['onglet'] );
	}
	return url_vue_equipe( VUE_CLE, $args );
}

/*
 * -----------------------------------------------------------------------------
 * Données
 * -----------------------------------------------------------------------------
 */

/**
 * Résultat d'une fonction de données du module (trafic_resume(), classement()…), ou null si elle
 * n'existe pas (fichier pas encore livré). Le filtre yume_statistiques_vue_donnees peut fournir
 * le résultat à la place (tests, démonstrations) ; false y signifie « fonction absente ».
 *
 * @param string $fonction Nom court de la fonction (espace de noms du module).
 * @param array  $args     Arguments positionnels.
 * @return mixed Résultat ; null si indisponible.
 */
function vue_donnees( string $fonction, array $args = array() ) {
	/**
	 * Court-circuite une fonction de données de la vue Statistiques.
	 *
	 * @param mixed  $pre      null : appeler la fonction ; false : fonction absente ; sinon le résultat.
	 * @param string $fonction Nom court (trafic_resume, classement, fiche_oeuvre, lecteurs_oeuvre…).
	 * @param array  $args     Arguments.
	 */
	$pre = apply_filters( 'yume_statistiques_vue_donnees', null, $fonction, $args );
	if ( false === $pre ) {
		return null;
	}
	if ( null !== $pre ) {
		return $pre;
	}
	return fonction_disponible( $fonction ) ? call_user_func_array( __NAMESPACE__ . '\\' . $fonction, $args ) : null;
}

/**
 * Le compte courant peut-il voir les listes nominatives (peut_voir_lecteurs(), sinon la capacité
 * yume_voir_lecteurs) ?
 */
function vue_peut_voir_lecteurs(): bool {
	if ( fonction_disponible( 'peut_voir_lecteurs' ) ) {
		return (bool) peut_voir_lecteurs( get_current_user_id() );
	}
	return current_user_can( CAPACITE_LECTEURS );
}

/**
 * Source du trafic : 'jetpack', 'simule', 'aucune', ou '' si l'adaptateur n'est pas disponible.
 */
function vue_source(): string {
	$source = vue_donnees( 'source_trafic' );
	return is_string( $source ) && in_array( $source, array( 'jetpack', 'simule', 'aucune' ), true ) ? $source : '';
}

/**
 * Points [{date, valeur}] d'une colonne d'une série ([{date, colonne…}]) ; valeur null si absente.
 *
 * @param mixed  $lignes  Série.
 * @param string $colonne Colonne.
 * @return array<int,array{date:string,valeur:int|null}>
 */
function vue_points( $lignes, string $colonne ): array {
	$points = array();
	foreach ( is_array( $lignes ) ? $lignes : array() as $ligne ) {
		if ( ! is_array( $ligne ) || empty( $ligne['date'] ) ) {
			continue;
		}
		$valeur   = $ligne[ $colonne ] ?? null;
		$points[] = array(
			'date'   => (string) $ligne['date'],
			'valeur' => is_numeric( $valeur ) ? (int) $valeur : null,
		);
	}
	return $points;
}

/*
 * -----------------------------------------------------------------------------
 * État de la vue (paramètres GET)
 * -----------------------------------------------------------------------------
 */

/**
 * Onglets : clé => libellé.
 *
 * @return array<string,string>
 */
function vue_onglets(): array {
	return array(
		'apercu'   => __( 'Aperçu', 'yume-core' ),
		'trafic'   => __( 'Trafic', 'yume-core' ),
		'oeuvres'  => __( 'Œuvres', 'yume-core' ),
		'lecteurs' => __( 'Lecteurs', 'yume-core' ),
	);
}

/**
 * Périodes proposées : jours => libellé court.
 *
 * @return array<int,string>
 */
function vue_periodes(): array {
	return array(
		7   => __( '7 jours', 'yume-core' ),
		30  => __( '30 jours', 'yume-core' ),
		90  => __( '90 jours', 'yume-core' ),
		365 => __( '12 mois', 'yume-core' ),
	);
}

/**
 * Période en toutes lettres (« les 30 derniers jours », « les 12 derniers mois »).
 *
 * @param int $jours Jours.
 */
function vue_periode_longue( int $jours ): string {
	/* translators: %d : nombre de jours */
	return 365 === $jours ? __( 'les 12 derniers mois', 'yume-core' ) : sprintf( __( 'les %d derniers jours', 'yume-core' ), $jours );
}

/**
 * Comparaison avec la période précédente (« vs 30 j précédents », « vs 12 mois précédents »).
 *
 * @param int $jours Jours.
 */
function vue_periode_precedente( int $jours ): string {
	/* translators: %d : nombre de jours */
	return 365 === $jours ? __( 'vs 12 mois précédents', 'yume-core' ) : sprintf( __( 'vs %d j précédents', 'yume-core' ), $jours );
}

/**
 * Texte d'un paramètre GET (recherche), nettoyé et tronqué.
 *
 * @param string $cle Clé.
 */
function vue_texte_get( string $cle ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- recherche d'affichage en lecture seule.
	$valeur = isset( $_GET[ $cle ] ) && is_string( $_GET[ $cle ] ) ? sanitize_text_field( wp_unslash( $_GET[ $cle ] ) ) : '';
	return mb_substr( $valeur, 0, 60 );
}

/**
 * Paramètres de la vue, validés.
 *
 * @return array{periode:int,onglet:string,oeuvre:int,tri:string,pg:int,liste:string,q:string}
 */
function vue_etat(): array {
	$oeuvre = get_entier( 'oeuvre' );
	$onglet = get_cle( 'onglet' );
	$tri    = get_cle( 'tri' );
	$liste  = get_cle( 'liste' );
	if ( $oeuvre ) {
		$tri = in_array( $tri, array( 'date', 'pseudo' ), true ) ? $tri : 'date';
	} else {
		$tri = in_array( $tri, TRIS_OEUVRES, true ) ? $tri : 'favoris';
	}
	return array(
		'periode' => periode_valide( get_entier( 'periode' ) ),
		'onglet'  => $oeuvre ? 'oeuvres' : ( isset( vue_onglets()[ $onglet ] ) ? $onglet : 'apercu' ),
		'oeuvre'  => $oeuvre,
		'tri'     => $tri,
		'pg'      => max( 1, get_entier( 'pg' ) ),
		'liste'   => 'suiveurs' === $liste ? 'suiveurs' : 'favoris',
		'q'       => vue_texte_get( 'q' ),
	);
}

/**
 * Paramètres d'adresse de l'état courant (période, onglet, tri du classement), plus $extra.
 *
 * @param array $etat  État (vue_etat()).
 * @param array $extra Paramètres ajoutés ou remplacés.
 * @return array<string,mixed>
 */
function vue_args( array $etat, array $extra = array() ): array {
	$args = array(
		'periode' => PERIODE_DEFAUT !== $etat['periode'] ? $etat['periode'] : '',
		'onglet'  => 'apercu' !== $etat['onglet'] && ! $etat['oeuvre'] ? $etat['onglet'] : '',
		'tri'     => ! $etat['oeuvre'] && 'oeuvres' === $etat['onglet'] && 'favoris' !== $etat['tri'] ? $etat['tri'] : '',
	);
	return array_merge( $args, $extra );
}

/*
 * -----------------------------------------------------------------------------
 * Composants
 * -----------------------------------------------------------------------------
 */

/**
 * Nombre lisible (« 12 480 », « — » si inconnu).
 *
 * @param mixed $n Nombre.
 */
function vue_nombre( $n ): string {
	return graphique_nombre( $n );
}

/**
 * Part en pourcentage (« 78 % »), « — » si le total est nul.
 *
 * @param mixed $n     Valeur.
 * @param mixed $total Total.
 */
function vue_part( $n, $total ): string {
	$total = (float) $total;
	return $total > 0 ? number_format_i18n( round( 100 * (float) $n / $total ) ) . "\u{00A0}%" : '—';
}

/**
 * Message « aucune donnée ».
 *
 * @param string $texte Texte (vide : « Aucune donnée pour le moment. »).
 */
function vue_vide( string $texte = '' ): string {
	return graphique_vide( $texte );
}

/**
 * Carte d'un chiffre : surtitre, valeur, ligne de variation (HTML déjà échappé) et détail.
 *
 * @param string $label     Surtitre.
 * @param string $valeur    Valeur.
 * @param string $variation HTML de la variation (variation_html()…), vide sinon.
 * @param string $detail    Détail.
 * @param string $extra     HTML ajouté en bas (sparkline).
 */
function vue_carte( string $label, string $valeur, string $variation, string $detail, string $extra = '' ): string {
	$html  = '<div class="yn-card yn-team__chiffre yn-stats__carte"><p class="yn-label">' . esc_html( $label ) . '</p>';
	$html .= '<p class="yn-team__valeur">' . esc_html( $valeur ) . '</p>';
	$html .= '' !== $variation ? '<p class="yn-stats__evolution">' . $variation . '</p>' : '';
	$html .= '' !== $detail ? '<p class="yn-muted yn-stats__detail">' . esc_html( $detail ) . '</p>' : '';
	return $html . $extra . '</div>';
}

/**
 * Section en carte avec titre (h2) et identifiant.
 *
 * @param string $id    Identifiant du titre.
 * @param string $titre Titre.
 * @param string $corps HTML du contenu.
 * @param string $tete  HTML ajouté à droite du titre (légende, lien).
 */
function vue_section( string $id, string $titre, string $corps, string $tete = '' ): string {
	$html  = '<section class="yn-card yn-kpi__carte yn-stats__section" aria-labelledby="' . esc_attr( $id ) . '">';
	$html .= '<div class="yn-team__section-tete"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $titre ) . '</h2>' . $tete . '</div>';
	return $html . $corps . '</section>';
}

/**
 * Message du trafic indisponible (Jetpack absent ou non connecté) ou sans donnée.
 */
function vue_message_trafic(): string {
	return 'aucune' === vue_source()
		? vue_vide( __( 'Trafic indisponible : Jetpack n’est pas connecté sur ce site.', 'yume-core' ) )
		: vue_vide();
}

/**
 * Top d'œuvres (aperçu) : rang, titre lié à la fiche statistique, barre, valeur.
 *
 * @param array  $lignes Œuvres (oeuvre_id, titre, et la colonne $cle).
 * @param string $cle    Colonne de la valeur.
 * @param string $unite  Unité lue après la valeur par les lecteurs d'écran (« favoris »).
 * @param array  $etat   État (période conservée dans les liens).
 */
function vue_top( array $lignes, string $cle, string $unite, array $etat ): string {
	$lignes = array_slice( array_values( array_filter( $lignes, 'is_array' ) ), 0, VUE_TOP );
	if ( ! $lignes ) {
		return vue_vide();
	}
	$max  = max( 1, (int) max( array_map( static fn( array $l ): int => (int) ( $l[ $cle ] ?? 0 ), $lignes ) ) );
	$html = '<ol class="yn-stats__top">';
	foreach ( $lignes as $i => $l ) {
		$valeur    = (int) ( $l[ $cle ] ?? 0 );
		$oeuvre_id = (int) ( $l['oeuvre_id'] ?? 0 );
		$titre     = (string) ( $l['titre'] ?? '' );
		$lien      = $oeuvre_id > 0 ? '<a href="' . esc_url(
			url_statistiques(
				vue_args(
					$etat,
					array(
						'onglet' => '',
						'tri'    => '',
						'oeuvre' => $oeuvre_id,
					)
				)
			)
		) . '">' . esc_html( $titre ) . '</a>' : '<span>' . esc_html( $titre ) . '</span>';
		$html     .= '<li><span class="yn-stats__rang">' . ( $i + 1 ) . '</span><span class="yn-stats__top-corps">' . $lien;
		$html     .= '<span class="yn-bar" aria-hidden="true"><span style="--v:' . (int) round( 100 * $valeur / $max ) . '%"></span></span></span>';
		$html     .= '<span class="yn-stats__top-valeur">' . esc_html( vue_nombre( $valeur ) ) . '<span class="yn-visually-hidden"> ' . esc_html( $unite ) . '</span></span></li>';
	}
	return $html . '</ol>';
}

/*
 * -----------------------------------------------------------------------------
 * En-tête, période, onglets
 * -----------------------------------------------------------------------------
 */

/**
 * Formulaire GET de la période (boutons radio présentés en segments, bouton « Afficher ») ; les
 * autres paramètres de la page sont conservés en champs cachés.
 *
 * @param array $etat État.
 */
function vue_formulaire_periode( array $etat ): string {
	$html  = '<form class="yn-stats__periode" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Choisir la période', 'yume-core' ) . '">';
	$html .= champs_caches_vue( VUE_CLE );
	$garde = $etat['oeuvre']
		? array(
			'oeuvre' => $etat['oeuvre'],
			'liste'  => 'favoris' !== $etat['liste'] ? $etat['liste'] : '',
			'q'      => $etat['q'],
			'tri'    => 'date' !== $etat['tri'] ? $etat['tri'] : '',
		)
		: array_diff_key( vue_args( $etat ), array( 'periode' => '' ) );
	foreach ( $garde as $nom => $valeur ) {
		if ( '' !== (string) $valeur ) {
			$html .= '<input type="hidden" name="' . esc_attr( $nom ) . '" value="' . esc_attr( (string) $valeur ) . '">';
		}
	}
	$html .= '<fieldset class="yn-stats__segments"><legend class="yn-label">' . esc_html__( 'Période', 'yume-core' ) . '</legend><span class="yn-stats__choix">';
	foreach ( vue_periodes() as $jours => $libelle ) {
		$id    = 'yn-stats-p-' . $jours;
		$html .= '<input class="yn-stats__radio" type="radio" id="' . esc_attr( $id ) . '" name="periode" value="' . (int) $jours . '"' . checked( $etat['periode'], $jours, false ) . '>';
		$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
	}
	$html .= '</span></fieldset><button type="submit" class="yn-btn">' . esc_html__( 'Afficher', 'yume-core' ) . '</button></form>';
	return $html;
}

/**
 * Onglets (liens ; celui de la page porte aria-current).
 *
 * @param array $etat État.
 */
function vue_navigation_onglets( array $etat ): string {
	$html = '<nav class="yn-stats__onglets" aria-label="' . esc_attr__( 'Rubriques des statistiques', 'yume-core' ) . '"><ul>';
	foreach ( vue_onglets() as $cle => $libelle ) {
		$url   = url_statistiques(
			array(
				'periode' => $etat['periode'],
				'onglet'  => $cle,
			)
		);
		$html .= '<li><a href="' . esc_url( $url ) . '"' . ( $cle === $etat['onglet'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $libelle ) . '</a></li>';
	}
	return $html . '</ul></nav>';
}

/**
 * En-tête : titre, présentation et source du trafic, bandeau de démonstration, période et export.
 *
 * @param array $etat État.
 */
function vue_entete( array $etat ): string {
	$html   = tete_vue( __( 'Statistiques', 'yume-core' ), '' );
	$source = vue_source();
	$texte  = __( 'Audience du site et des œuvres.', 'yume-core' );
	if ( 'jetpack' === $source ) {
		$texte  .= ' ' . __( 'Trafic : Jetpack (WordPress.com)', 'yume-core' );
		$trafic  = vue_donnees( 'trafic_resume', array( $etat['periode'] ) );
		$calcule = is_array( $trafic ) ? (int) ( $trafic['calcule'] ?? 0 ) : 0;
		/* translators: %s : durée (« il y a 4 min ») */
		$texte .= $calcule > 0 ? ' · ' . sprintf( __( 'mis à jour %s', 'yume-core' ), il_y_a( $calcule ) ) . '.' : '.';
	} elseif ( 'simule' === $source ) {
		$texte .= ' ' . __( 'Trafic : données de démonstration.', 'yume-core' );
	} elseif ( 'aucune' === $source ) {
		$texte .= ' ' . __( 'Trafic : indisponible (Jetpack n’est pas connecté).', 'yume-core' );
	}
	$html .= '<p class="yn-muted yn-stats__presentation">' . esc_html( $texte ) . '</p>';
	if ( 'simule' === $source ) {
		$html .= '<p class="yn-card yn-stats__bandeau" role="note"><span class="yn-chip yn-chip--warn">' . esc_html__( 'Démonstration', 'yume-core' ) . '</span> ';
		$html .= '<span><b>' . esc_html__( 'Données de démonstration :', 'yume-core' ) . '</b> ' . esc_html__( 'Jetpack n’est pas connecté sur ce site. Les vues, visiteurs, référents, recherches et pays affichés sont simulés.', 'yume-core' ) . '</span></p>';
	}

	$html  .= '<div class="yn-stats__barre">' . vue_formulaire_periode( $etat );
	$export = $etat['oeuvre'] ? null : vue_donnees(
		'url_export',
		array(
			'oeuvres',
			array(
				'periode' => $etat['periode'],
				'tri'     => 'oeuvres' === $etat['onglet'] ? $etat['tri'] : 'favoris',
			),
		)
	);
	if ( is_string( $export ) && '' !== $export ) {
		$html .= '<a class="yn-btn" href="' . esc_url( $export ) . '" download>' . esc_html__( 'Exporter le classement (CSV)', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	return $html . ( $etat['oeuvre'] ? '' : vue_navigation_onglets( $etat ) );
}

/*
 * -----------------------------------------------------------------------------
 * Onglet « Aperçu »
 * -----------------------------------------------------------------------------
 */

/**
 * Onglet « Aperçu » : cartes (vues, visiteurs, lecteurs actifs, favoris, suiveurs alertés),
 * courbe des vues et visiteurs, œuvres les plus suivies et les plus vues.
 *
 * @param array $etat État.
 */
function vue_onglet_apercu( array $etat ): string {
	$jours      = $etat['periode'];
	$precedente = vue_periode_precedente( $jours );
	$trafic     = vue_donnees( 'trafic_resume', array( $jours ) );
	$lecteurs   = vue_donnees( 'resume_lecteurs', array( $jours ) );
	$classement = vue_donnees( 'classement', array( $jours, 'favoris', OEUVRES_PAGE_MAX, 1 ) );
	$contenus   = vue_donnees( 'trafic_contenus', array( $jours ) );
	$sans       = __( 'Aucune donnée pour le moment', 'yume-core' );

	$html = '<div class="yn-stats__cartes">';
	if ( is_array( $trafic ) ) {
		$serie = is_array( $trafic['serie'] ?? null ) ? $trafic['serie'] : array();
		$html .= vue_carte(
			__( 'Vues', 'yume-core' ),
			vue_nombre( $trafic['vues'] ?? 0 ),
			variation_html( isset( $trafic['variation_vues'] ) ? (float) $trafic['variation_vues'] : null ),
			$precedente,
			/* translators: %s : période */
			svg_sparkline( array_column( vue_points( $serie, 'vues' ), 'valeur' ), sprintf( __( 'Tendance des vues sur %s', 'yume-core' ), vue_periode_longue( $jours ) ) )
		);
		$html .= vue_carte(
			__( 'Visiteurs', 'yume-core' ),
			vue_nombre( $trafic['visiteurs'] ?? 0 ),
			variation_html( isset( $trafic['variation_visiteurs'] ) ? (float) $trafic['variation_visiteurs'] : null ),
			$precedente,
			/* translators: %s : période */
			svg_sparkline( array_column( vue_points( $serie, 'visiteurs' ), 'valeur' ), sprintf( __( 'Tendance des visiteurs sur %s', 'yume-core' ), vue_periode_longue( $jours ) ) )
		);
	} else {
		$indispo = 'aucune' === vue_source() ? __( 'Trafic indisponible', 'yume-core' ) : $sans;
		$html   .= vue_carte( __( 'Vues', 'yume-core' ), '—', '', $indispo );
		$html   .= vue_carte( __( 'Visiteurs', 'yume-core' ), '—', '', $indispo );
	}
	if ( is_array( $lecteurs ) ) {
		$favoris  = (int) ( $lecteurs['favoris_total'] ?? 0 );
		$suiveurs = (int) ( $lecteurs['suiveurs_total'] ?? 0 );
		$nouveaux = null;
		if ( is_array( $classement ) && (int) ( $classement['pages'] ?? 1 ) <= 1 ) {
			$nouveaux = (int) array_sum( array_map( static fn( $o ): int => is_array( $o ) ? (int) ( $o['nouveaux_favoris'] ?? 0 ) : 0, (array) ( $classement['oeuvres'] ?? array() ) ) );
		}
		$html .= vue_carte(
			__( 'Lecteurs actifs', 'yume-core' ),
			vue_nombre( $lecteurs['actifs'] ?? 0 ),
			variation_html( isset( $lecteurs['variation_actifs'] ) ? (float) $lecteurs['variation_actifs'] : null ),
			__( 'position de lecture mise à jour sur la période', 'yume-core' )
		);
		$html .= vue_carte(
			__( 'Favoris', 'yume-core' ),
			vue_nombre( $favoris ),
			null !== $nouveaux
				/* translators: %s : période */
				? variation_html( (float) $nouveaux, '' ) . ' <span class="yn-muted">' . esc_html( sprintf( __( 'nouveaux sur %s', 'yume-core' ), vue_periode_longue( $jours ) ) ) . '</span>'
				: '',
			__( 'toutes œuvres', 'yume-core' )
		);
		$html .= vue_carte(
			__( 'Suiveurs alertés', 'yume-core' ),
			vue_nombre( $suiveurs ),
			/* translators: %s : pourcentage */
			$favoris > 0 ? '<span class="yn-muted">' . esc_html( sprintf( __( '%s des favoris', 'yume-core' ), vue_part( $suiveurs, $favoris ) ) ) . '</span>' : '',
			__( 'alerte immédiate ou hebdomadaire', 'yume-core' )
		);
	} else {
		$html .= vue_carte( __( 'Lecteurs actifs', 'yume-core' ), '—', '', $sans );
		$html .= vue_carte( __( 'Favoris', 'yume-core' ), '—', '', $sans );
		$html .= vue_carte( __( 'Suiveurs alertés', 'yume-core' ), '—', '', $sans );
	}
	$html .= '</div>';

	$html .= vue_courbe_trafic( $trafic, $etat, 'yn-stats-apercu-courbe', 240 );

	$suivies = is_array( $classement ) ? (array) ( $classement['oeuvres'] ?? array() ) : array();
	$vues    = is_array( $contenus ) ? (array) ( $contenus['oeuvres'] ?? array() ) : array();
	$html   .= '<div class="yn-stats__duo">';
	$html   .= vue_section(
		'yn-stats-top-suivies',
		__( 'Œuvres les plus suivies', 'yume-core' ),
		( is_array( $classement ) ? vue_top( $suivies, 'favoris', __( 'favoris', 'yume-core' ), $etat ) : vue_vide() )
		. '<p><a href="' . esc_url( url_statistiques( vue_args( $etat, array( 'onglet' => 'oeuvres' ) ) ) ) . '">' . esc_html__( 'Voir tout le classement →', 'yume-core' ) . '</a></p>'
	);
	$html   .= vue_section(
		'yn-stats-top-vues',
		__( 'Œuvres les plus vues', 'yume-core' ),
		( is_array( $contenus ) ? vue_top( $vues, 'vues', __( 'vues', 'yume-core' ), $etat ) : vue_message_trafic() )
		. '<p><a href="' . esc_url( url_statistiques( vue_args( $etat, array( 'onglet' => 'trafic' ) ) ) ) . '">' . esc_html__( 'Voir le détail du trafic →', 'yume-core' ) . '</a></p>'
	);
	return $html . '</div>';
}

/**
 * Section « Vues et visiteurs par jour » (ou par mois sur 12 mois).
 *
 * @param mixed  $trafic  Résumé du trafic (trafic_resume()) ou null.
 * @param array  $etat    État.
 * @param string $id      Identifiant de la section.
 * @param int    $hauteur Hauteur du dessin.
 */
function vue_courbe_trafic( $trafic, array $etat, string $id, int $hauteur ): string {
	$mois  = is_array( $trafic ) && 'mois' === ( $trafic['unite'] ?? '' );
	$titre = $mois ? __( 'Vues et visiteurs par mois', 'yume-core' ) : __( 'Vues et visiteurs par jour', 'yume-core' );
	if ( ! is_array( $trafic ) ) {
		return vue_section( $id, $titre, vue_message_trafic() );
	}
	$serie = is_array( $trafic['serie'] ?? null ) ? $trafic['serie'] : array();
	return vue_section(
		$id,
		$titre,
		svg_courbe(
			array(
				array(
					'libelle' => __( 'Vues', 'yume-core' ),
					'points'  => vue_points( $serie, 'vues' ),
				),
				array(
					'libelle' => __( 'Visiteurs', 'yume-core' ),
					'points'  => vue_points( $serie, 'visiteurs' ),
				),
			),
			array(
				/* translators: %s : période */
				'titre'   => $titre . ' ' . sprintf( __( 'sur %s', 'yume-core' ), vue_periode_longue( $etat['periode'] ) ),
				'id'      => $id,
				'hauteur' => $hauteur,
				'unite'   => $mois ? 'mois' : 'jour',
			)
		)
	);
}

/*
 * -----------------------------------------------------------------------------
 * Onglet « Trafic »
 * -----------------------------------------------------------------------------
 */

/**
 * Ligne « Source du trafic » (administrateurs) : Jetpack, démonstration ou indisponible, et
 * dernière erreur de l'API.
 */
function vue_source_admin(): string {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$libelles = array(
		'jetpack' => __( 'Jetpack (WordPress.com Stats)', 'yume-core' ),
		'simule'  => __( 'données de démonstration (Jetpack absent, site hors production)', 'yume-core' ),
		'aucune'  => __( 'indisponible (Jetpack absent ou non connecté)', 'yume-core' ),
		''        => __( 'adaptateur de trafic non disponible', 'yume-core' ),
	);
	$erreur   = vue_donnees( 'derniere_erreur_trafic' );
	$erreur   = is_string( $erreur ) && '' !== $erreur ? $erreur : __( 'aucune', 'yume-core' );
	/* translators: 1: source du trafic, 2: dernière erreur */
	return '<p class="yn-card yn-stats__source">' . esc_html( sprintf( __( 'Source du trafic : %1$s — dernière erreur : %2$s', 'yume-core' ), $libelles[ vue_source() ], $erreur ) ) . '</p>';
}

/**
 * Libellé d'un type de contenu du trafic.
 *
 * @param string $type Type (oeuvre, tome, chapitre, page, article, autre).
 */
function vue_type_contenu( string $type ): string {
	$types = array(
		'oeuvre'   => __( 'Fiche œuvre', 'yume-core' ),
		'tome'     => __( 'Tome', 'yume-core' ),
		'chapitre' => __( 'Chapitre', 'yume-core' ),
		'page'     => __( 'Page', 'yume-core' ),
		'article'  => __( 'Article', 'yume-core' ),
	);
	return $types[ $type ] ?? __( 'Autre', 'yume-core' );
}

/**
 * Onglet « Trafic » : cartes, courbe, contenus les plus vus, regroupement par œuvre, référents,
 * termes de recherche, pays.
 *
 * @param array $etat État.
 */
function vue_onglet_trafic( array $etat ): string {
	$jours  = $etat['periode'];
	$html   = vue_source_admin();
	$trafic = vue_donnees( 'trafic_resume', array( $jours ) );
	$mois   = is_array( $trafic ) && 'mois' === ( $trafic['unite'] ?? '' );

	// Cartes.
	if ( is_array( $trafic ) ) {
		$vues       = (int) ( $trafic['vues'] ?? 0 );
		$visiteurs  = (int) ( $trafic['visiteurs'] ?? 0 );
		$vues_p     = (int) ( $trafic['vues_prec'] ?? 0 );
		$visit_p    = (int) ( $trafic['visiteurs_prec'] ?? 0 );
		$ratio      = $visiteurs > 0 ? $vues / $visiteurs : null;
		$ratio_p    = $visit_p > 0 ? $vues_p / $visit_p : null;
		$precedente = vue_periode_precedente( $jours );
		$meilleur   = null;
		foreach ( vue_points( $trafic['serie'] ?? array(), 'vues' ) as $point ) {
			if ( null !== $point['valeur'] && ( null === $meilleur || $point['valeur'] > $meilleur['valeur'] ) ) {
				$meilleur = $point;
			}
		}
		$html .= '<div class="yn-stats__cartes">';
		$html .= vue_carte( __( 'Vues', 'yume-core' ), vue_nombre( $vues ), variation_html( isset( $trafic['variation_vues'] ) ? (float) $trafic['variation_vues'] : null ), $precedente );
		$html .= vue_carte( __( 'Visiteurs', 'yume-core' ), vue_nombre( $visiteurs ), variation_html( isset( $trafic['variation_visiteurs'] ) ? (float) $trafic['variation_visiteurs'] : null ), $precedente );
		$html .= vue_carte(
			__( 'Vues par visiteur', 'yume-core' ),
			null !== $ratio ? number_format_i18n( $ratio, 1 ) : '—',
			null !== $ratio && null !== $ratio_p && $ratio_p > 0 ? variation_html( round( ( $ratio - $ratio_p ) / $ratio_p * 100, 1 ) ) : '',
			$precedente
		);
		$html .= vue_carte(
			$mois ? __( 'Meilleur mois', 'yume-core' ) : __( 'Meilleur jour', 'yume-core' ),
			$meilleur ? ( $mois ? graphique_date( $meilleur['date'], 'mois' ) : format_fr( graphique_ts( $meilleur['date'] ), 'D j M' ) ) : '—',
			'',
			/* translators: %s : nombre de vues */
			$meilleur ? sprintf( __( '%s vues', 'yume-core' ), vue_nombre( $meilleur['valeur'] ) ) : ''
		);
		$html .= '</div>';
	}
	$html .= vue_courbe_trafic( $trafic, $etat, 'yn-stats-trafic-courbe', 300 );
	if ( ! is_array( $trafic ) ) {
		return $html;
	}

	// Contenus et regroupement par œuvre.
	$contenus = vue_donnees( 'trafic_contenus', array( $jours, VUE_CONTENUS ) );
	$titres   = oeuvres_publiees();
	$corps    = vue_message_trafic();
	$oeuvres  = vue_message_trafic();
	if ( is_array( $contenus ) ) {
		$lignes = array();
		foreach ( (array) ( $contenus['contenus'] ?? array() ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$titre     = (string) ( $c['titre'] ?? '' );
			$url       = (string) ( $c['url'] ?? '' );
			$oeuvre_id = (int) ( $c['oeuvre_id'] ?? 0 );
			$lignes[]  = array(
				'' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $titre ) . '</a>' : esc_html( $titre ),
				esc_html( vue_type_contenu( (string) ( $c['type'] ?? '' ) ) ),
				esc_html( $oeuvre_id && isset( $titres[ $oeuvre_id ] ) ? $titres[ $oeuvre_id ] : '—' ),
				esc_html( vue_nombre( $c['vues'] ?? 0 ) ),
			);
		}
		$corps  = $lignes
			? tableau_kpi( 'yn-stats-t-contenus', __( 'Contenus les plus vus sur la période', 'yume-core' ), array( __( 'Contenu', 'yume-core' ), __( 'Type', 'yume-core' ), __( 'Œuvre', 'yume-core' ), __( 'Vues', 'yume-core' ) ), $lignes, true )
			: vue_vide();
		$total  = (int) ( $contenus['total_vues'] ?? 0 );
		$lignes = array();
		foreach ( (array) ( $contenus['oeuvres'] ?? array() ) as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$oeuvre_id = (int) ( $o['oeuvre_id'] ?? 0 );
			$titre     = esc_html( (string) ( $o['titre'] ?? '' ) );
			$lignes[]  = array(
				$oeuvre_id ? '<a href="' . esc_url(
					url_statistiques(
						vue_args(
							$etat,
							array(
								'onglet' => '',
								'oeuvre' => $oeuvre_id,
							)
						)
					)
				) . '">' . $titre . '</a>' : $titre,
				esc_html( vue_nombre( $o['vues'] ?? 0 ) ),
				esc_html( vue_part( $o['vues'] ?? 0, $total ) ),
			);
		}
		if ( (int) ( $contenus['autres_vues'] ?? 0 ) > 0 ) {
			$lignes[] = array( esc_html__( 'Autres pages (accueil, planning…)', 'yume-core' ), esc_html( vue_nombre( $contenus['autres_vues'] ) ), esc_html( vue_part( $contenus['autres_vues'], $total ) ) );
		}
		$oeuvres = $lignes
			? tableau_kpi( 'yn-stats-t-oeuvres-vues', __( 'Vues regroupées par œuvre (fiche, tomes et chapitres)', 'yume-core' ), array( __( 'Œuvre', 'yume-core' ), __( 'Vues', 'yume-core' ), __( 'Part', 'yume-core' ) ), $lignes, true )
			: vue_vide();
	}
	$html .= '<div class="yn-stats__duo yn-stats__duo--large">';
	$html .= vue_section( 'yn-stats-contenus', __( 'Contenus les plus vus', 'yume-core' ), $corps );
	$html .= vue_section( 'yn-stats-par-oeuvre', __( 'Par œuvre', 'yume-core' ), $oeuvres );
	$html .= '</div>';

	// Référents, recherches, pays.
	$html .= '<div class="yn-stats__trio">';

	$referents = vue_donnees( 'trafic_referents', array( $jours ) );
	$corps     = vue_message_trafic();
	if ( is_array( $referents ) ) {
		$items = '';
		foreach ( (array) ( $referents['referents'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$items .= '<li><span>' . esc_html( (string) ( $r['nom'] ?? '' ) ) . '</span><b>' . esc_html( vue_nombre( $r['vues'] ?? 0 ) ) . '</b></li>';
			}
		}
		$corps = '' !== $items ? '<ul class="yn-stats__valeurs">' . $items . '</ul>' : vue_vide();
	}
	$html .= vue_section( 'yn-stats-referents', __( 'Référents', 'yume-core' ), $corps );

	$recherches = vue_donnees( 'trafic_recherches', array( $jours ) );
	$corps      = vue_message_trafic();
	if ( is_array( $recherches ) ) {
		$items = '';
		foreach ( (array) ( $recherches['termes'] ?? array() ) as $t ) {
			if ( is_array( $t ) ) {
				$items .= '<li><span>' . esc_html( (string) ( $t['terme'] ?? '' ) ) . '</span><b>' . esc_html( vue_nombre( $t['vues'] ?? 0 ) ) . '</b></li>';
			}
		}
		if ( (int) ( $recherches['chiffres'] ?? 0 ) > 0 ) {
			$items .= '<li><span class="yn-muted">' . esc_html__( 'Termes masqués par les moteurs', 'yume-core' ) . '</span><b>' . esc_html( vue_nombre( $recherches['chiffres'] ) ) . '</b></li>';
		}
		$corps  = '' !== $items ? '<ul class="yn-stats__valeurs">' . $items . '</ul>' : vue_vide();
		$corps .= '<p class="yn-muted yn-stats__note">' . esc_html__( 'Les moteurs masquent la plupart des termes : chiffre indicatif.', 'yume-core' ) . '</p>';
	}
	$html .= vue_section( 'yn-stats-recherches', __( 'Termes de recherche', 'yume-core' ), $corps );

	$pays  = vue_donnees( 'trafic_pays', array( $jours ) );
	$corps = vue_message_trafic();
	if ( is_array( $pays ) ) {
		$total  = (int) ( $pays['total'] ?? 0 );
		$barres = array();
		foreach ( (array) ( $pays['pays'] ?? array() ) as $p ) {
			if ( is_array( $p ) ) {
				$barres[] = array(
					'libelle' => (string) ( $p['nom'] ?? ( $p['code'] ?? '' ) ),
					'valeur'  => (int) ( $p['vues'] ?? 0 ),
					'texte'   => vue_part( $p['vues'] ?? 0, $total ),
				);
			}
		}
		if ( (int) ( $pays['autres'] ?? 0 ) > 0 ) {
			$barres[] = array(
				'libelle' => __( 'Autres', 'yume-core' ),
				'valeur'  => (int) $pays['autres'],
				'texte'   => vue_part( $pays['autres'], $total ),
			);
		}
		$corps  = svg_barres_h(
			$barres,
			array(
				'titre' => __( 'Part des vues par pays', 'yume-core' ),
				'max'   => $total,
			)
		);
		$corps .= '<p class="yn-muted yn-stats__note">' . esc_html__( 'Par pays uniquement, jamais par ville.', 'yume-core' ) . '</p>';
	}
	$html .= vue_section( 'yn-stats-pays', __( 'Pays', 'yume-core' ), $corps );

	return $html . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * Onglet « Œuvres »
 * -----------------------------------------------------------------------------
 */

/**
 * Tris du classement : clé => libellé.
 *
 * @return array<string,string>
 */
function vue_tris_oeuvres(): array {
	return array(
		'favoris'    => __( 'Favoris', 'yume-core' ),
		'suiveurs'   => __( 'Suiveurs alertés', 'yume-core' ),
		'lecteurs'   => __( 'Lecteurs', 'yume-core' ),
		'vues'       => __( 'Vues', 'yume-core' ),
		'nouveaux'   => __( 'Nouveaux favoris', 'yume-core' ),
		'croissance' => __( 'Croissance 30 j', 'yume-core' ),
	);
}

/**
 * Onglet « Œuvres » : tri (formulaire GET) et classement paginé, chaque titre menant à la fiche
 * statistique de l'œuvre.
 *
 * @param array $etat État.
 */
function vue_onglet_oeuvres( array $etat ): string {
	$html  = '<form class="yn-card yn-team__filtres yn-stats__tri" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Trier le classement', 'yume-core' ) . '">' . champs_caches_vue( VUE_CLE );
	$html .= '<input type="hidden" name="onglet" value="oeuvres">';
	$html .= PERIODE_DEFAUT !== $etat['periode'] ? '<input type="hidden" name="periode" value="' . (int) $etat['periode'] . '">' : '';
	$html .= champ_select( 'yn-stats-tri', 'tri', __( 'Trier par', 'yume-core' ), vue_tris_oeuvres(), $etat['tri'] );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Trier', 'yume-core' ) . '</button></p></form>';

	$classement = vue_donnees( 'classement', array( $etat['periode'], $etat['tri'], OEUVRES_PAR_PAGE, $etat['pg'] ) );
	if ( ! is_array( $classement ) ) {
		return $html . vue_section( 'yn-stats-classement', __( 'Classement des œuvres', 'yume-core' ), vue_vide() );
	}
	$page   = max( 1, (int) ( $classement['page'] ?? $etat['pg'] ) );
	$pages  = max( 1, (int) ( $classement['pages'] ?? 1 ) );
	$rang   = ( $page - 1 ) * OEUVRES_PAR_PAGE;
	$lignes = array();
	foreach ( (array) ( $classement['oeuvres'] ?? array() ) as $o ) {
		if ( ! is_array( $o ) ) {
			continue;
		}
		++$rang;
		$oeuvre_id  = (int) ( $o['oeuvre_id'] ?? 0 );
		$croissance = $o['croissance_favoris'] ?? null;
		$nouveaux   = (int) ( $o['nouveaux_favoris'] ?? 0 );
		$lignes[]   = array(
			'<span class="yn-stats__rang">' . $rang . '</span> <a href="' . esc_url(
				url_statistiques(
					vue_args(
						$etat,
						array(
							'onglet' => '',
							'tri'    => '',
							'oeuvre' => $oeuvre_id,
						)
					)
				)
			) . '">' . esc_html( (string) ( $o['titre'] ?? '' ) ) . '</a>',
			esc_html( vue_nombre( $o['favoris'] ?? 0 ) ),
			esc_html( vue_nombre( $o['suiveurs'] ?? 0 ) ),
			esc_html( vue_nombre( $o['lecteurs'] ?? 0 ) ),
			esc_html( vue_nombre( $o['lecteurs_30j'] ?? 0 ) ),
			esc_html( vue_nombre( $o['vues'] ?? null ) ),
			esc_html( ( $nouveaux > 0 ? '+' : '' ) . vue_nombre( $nouveaux ) ),
			variation_html( is_numeric( $croissance ) ? (float) $croissance : null, '' ),
		);
	}
	$corps = $lignes
		? tableau_kpi(
			'yn-stats-t-classement',
			/* translators: 1: critère de tri, 2: période */
			sprintf( __( 'Classement des œuvres par %1$s (nouveaux favoris sur %2$s, croissance des favoris sur 30 jours)', 'yume-core' ), mb_strtolower( vue_tris_oeuvres()[ $etat['tri'] ] ), vue_periode_longue( $etat['periode'] ) ),
			array( __( 'Œuvre', 'yume-core' ), __( 'Favoris', 'yume-core' ), __( 'Suiveurs', 'yume-core' ), __( 'Lecteurs', 'yume-core' ), __( 'Actifs 30 j', 'yume-core' ), __( 'Vues', 'yume-core' ), __( 'Nouveaux', 'yume-core' ), __( 'Croissance', 'yume-core' ) ),
			$lignes,
			true
		)
		: vue_vide();
	$corps .= pagination_vue( VUE_CLE, vue_args( $etat ), $page, $page < $pages, $pages );
	/* translators: %d : nombre d'œuvres */
	$tete  = '<span class="yn-muted">' . esc_html( sprintf( _n( '%d œuvre', '%d œuvres', (int) ( $classement['total'] ?? 0 ), 'yume-core' ), (int) ( $classement['total'] ?? 0 ) ) ) . '</span>';
	$html .= vue_section( 'yn-stats-classement', __( 'Classement des œuvres', 'yume-core' ), $corps, $tete );
	$html .= '<p class="yn-muted yn-stats__note">' . esc_html__( 'Cliquer sur un titre ouvre les statistiques de l’œuvre. Suiveurs alertés = favoris avec alerte immédiate ou hebdomadaire.', 'yume-core' ) . '</p>';
	return $html;
}

/*
 * -----------------------------------------------------------------------------
 * Onglet « Lecteurs »
 * -----------------------------------------------------------------------------
 */

/**
 * Libellés des fréquences d'alerte.
 *
 * @return array<string,string>
 */
function vue_frequences(): array {
	return array(
		'immediat' => __( 'Immédiate', 'yume-core' ),
		'hebdo'    => __( 'Hebdomadaire', 'yume-core' ),
		'jamais'   => __( 'Jamais', 'yume-core' ),
	);
}

/**
 * Onglet « Lecteurs » : comptes, inscriptions, lecteurs actifs, part avec un favori, courbe des
 * inscriptions, répartition des fréquences d'alerte. Aucun lecteur nommé.
 *
 * @param array $etat État.
 */
function vue_onglet_lecteurs( array $etat ): string {
	$jours    = $etat['periode'];
	$lecteurs = vue_donnees( 'resume_lecteurs', array( $jours ) );
	$note     = '<p class="yn-muted yn-stats__note">' . esc_html__( 'Aucun lecteur n’est nommé dans cet onglet. La liste nominative se trouve dans la fiche de chaque œuvre (gérants et administrateurs).', 'yume-core' ) . '</p>';
	if ( ! is_array( $lecteurs ) ) {
		return vue_section( 'yn-stats-lecteurs', __( 'Lecteurs', 'yume-core' ), vue_vide() ) . $note;
	}
	$comptes = (int) ( $lecteurs['comptes'] ?? 0 );
	$serie   = vue_points( $lecteurs['serie_inscriptions'] ?? array(), 'n' );

	$html  = '<div class="yn-stats__cartes">';
	$html .= vue_carte( __( 'Comptes lecteurs', 'yume-core' ), vue_nombre( $comptes ), '', __( 'inscrits au total', 'yume-core' ) );
	$html .= vue_carte(
		__( 'Inscriptions', 'yume-core' ),
		vue_nombre( $lecteurs['inscriptions'] ?? 0 ),
		variation_html( isset( $lecteurs['variation_inscriptions'] ) ? (float) $lecteurs['variation_inscriptions'] : null ),
		vue_periode_precedente( $jours )
	);
	$html .= vue_carte(
		__( 'Lecteurs actifs', 'yume-core' ),
		vue_nombre( $lecteurs['actifs'] ?? 0 ),
		variation_html( isset( $lecteurs['variation_actifs'] ) ? (float) $lecteurs['variation_actifs'] : null ),
		vue_periode_precedente( $jours )
	);
	$html .= vue_carte( __( 'Avec au moins 1 favori', 'yume-core' ), vue_part( $lecteurs['lecteurs_avec_favori'] ?? 0, $comptes ), '', __( 'des comptes lecteurs', 'yume-core' ) );
	$html .= '</div>';

	$titre = __( 'Inscriptions par jour', 'yume-core' );
	$html .= vue_section(
		'yn-stats-inscriptions',
		$titre,
		svg_courbe(
			array(
				array(
					'libelle' => __( 'Inscriptions', 'yume-core' ),
					'points'  => $serie,
				),
			),
			array(
				/* translators: %s : période */
				'titre'   => $titre . ' ' . sprintf( __( 'sur %s', 'yume-core' ), vue_periode_longue( $jours ) ),
				'id'      => 'yn-stats-inscriptions',
				'hauteur' => 180,
			)
		)
	);

	$frequences = is_array( $lecteurs['frequences'] ?? null ) ? $lecteurs['frequences'] : array();
	$total      = array_sum( array_map( 'intval', array_intersect_key( $frequences, vue_frequences() ) ) );
	if ( $total > 0 ) {
		$barre   = '<div class="yn-stats__repartition" aria-hidden="true">';
		$legende = '<ul class="yn-stats__legende-parts">';
		$rang    = 0;
		foreach ( vue_frequences() as $cle => $libelle ) {
			++$rang;
			$n        = (int) ( $frequences[ $cle ] ?? 0 );
			$barre   .= '<span class="yn-stats__part yn-stats__part--' . $rang . '" style="--part:' . graphique_coord( 100 * $n / $total ) . '%"></span>';
			$legende .= '<li><span class="yn-stats__puce yn-stats__part--' . $rang . '" aria-hidden="true"></span>' . esc_html( $libelle ) . ' <b>' . esc_html( vue_part( $n, $total ) ) . '</b> <span class="yn-muted">(' . esc_html( vue_nombre( $n ) ) . ')</span></li>';
		}
		$corps = $barre . '</div>' . $legende . '</ul>';
	} else {
		$corps = vue_vide();
	}
	$html .= vue_section( 'yn-stats-frequences', __( 'Fréquence des alertes choisie par les lecteurs', 'yume-core' ), $corps );
	return $html . $note;
}

/*
 * -----------------------------------------------------------------------------
 * Fiche d'une œuvre
 * -----------------------------------------------------------------------------
 */

/**
 * Encart « réservé aux gérants » (cadenas) à la place de la liste nominative.
 *
 * @param int|null $favoris Nombre de favoris (null : inconnu).
 */
function vue_cadenas( ?int $favoris ): string {
	$html  = '<div class="yn-stats__cadenas"><svg class="yn-stats__icone" width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg><div>';
	$html .= null !== $favoris
		/* translators: %s : nombre de lecteurs */
		? '<p><b>' . esc_html( sprintf( _n( '%s lecteur a mis cette œuvre en favori.', '%s lecteurs ont mis cette œuvre en favori.', $favoris, 'yume-core' ), vue_nombre( $favoris ) ) ) . '</b></p>'
		: '';
	$html .= '<p class="yn-muted">' . esc_html__( 'La liste des pseudos est réservée aux gérants et administrateurs (RGPD). Les chiffres restent visibles pour toute l’équipe.', 'yume-core' ) . '</p>';
	return $html . '</div></div>';
}

/**
 * Liste nominative des lecteurs qui suivent une œuvre (gérants et administrateurs) : choix
 * favoris / suiveurs alertés, recherche sur le pseudo, tri, tableau paginé, export journalisé.
 * Pseudo, date et fréquence seulement.
 *
 * @param int        $oeuvre_id Œuvre.
 * @param array      $etat      État.
 * @param array|null $compteurs Compteurs de la fiche (favoris, suiveurs) ou null.
 */
function vue_liste_nominative( int $oeuvre_id, array $etat, ?array $compteurs ): string {
	$base   = array(
		'periode' => PERIODE_DEFAUT !== $etat['periode'] ? $etat['periode'] : '',
		'oeuvre'  => $oeuvre_id,
	);
	$listes = array(
		'favoris'  => __( 'Favoris', 'yume-core' ),
		'suiveurs' => __( 'Suiveurs alertés', 'yume-core' ),
	);

	// Choix de la liste (liens en segments).
	$html = '<div class="yn-stats__outils"><ul class="yn-stats__listes" aria-label="' . esc_attr__( 'Liste affichée', 'yume-core' ) . '">';
	foreach ( $listes as $cle => $libelle ) {
		$nombre = null !== $compteurs && isset( $compteurs[ $cle ] ) ? ' (' . vue_nombre( $compteurs[ $cle ] ) . ')' : '';
		$url    = url_statistiques( array_merge( $base, array( 'liste' => 'favoris' !== $cle ? $cle : '' ) ) );
		$html  .= '<li><a href="' . esc_url( $url ) . '"' . ( $cle === $etat['liste'] ? ' aria-current="true"' : '' ) . '>' . esc_html( $libelle . $nombre ) . '</a></li>';
	}
	$html .= '</ul>';

	// Recherche et tri (formulaire GET).
	$html .= '<form class="yn-stats__recherche" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" role="search" aria-label="' . esc_attr__( 'Rechercher un lecteur', 'yume-core' ) . '">' . champs_caches_vue( VUE_CLE );
	$html .= '<input type="hidden" name="oeuvre" value="' . (int) $oeuvre_id . '">';
	$html .= PERIODE_DEFAUT !== $etat['periode'] ? '<input type="hidden" name="periode" value="' . (int) $etat['periode'] . '">' : '';
	$html .= 'favoris' !== $etat['liste'] ? '<input type="hidden" name="liste" value="' . esc_attr( $etat['liste'] ) . '">' : '';
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-stats-q">' . esc_html__( 'Rechercher un pseudo', 'yume-core' ) . '</label>';
	$html .= '<input type="search" id="yn-stats-q" name="q" value="' . esc_attr( $etat['q'] ) . '" maxlength="60" placeholder="' . esc_attr__( '2 caractères minimum', 'yume-core' ) . '"></p>';
	$html .= champ_select(
		'yn-stats-tri-lecteurs',
		'tri',
		__( 'Trier par', 'yume-core' ),
		array(
			'date'   => __( 'Date d’ajout', 'yume-core' ),
			'pseudo' => __( 'Pseudo', 'yume-core' ),
		),
		$etat['tri']
	);
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Rechercher', 'yume-core' ) . '</button></p></form></div>';

	$resultat = vue_donnees(
		'lecteurs_oeuvre',
		array(
			$oeuvre_id,
			array(
				'type'   => $etat['liste'],
				'q'      => $etat['q'],
				'page'   => $etat['pg'],
				'limite' => LECTEURS_PAR_PAGE,
				'tri'    => $etat['tri'],
				'ordre'  => 'pseudo' === $etat['tri'] ? 'asc' : 'desc',
			),
		)
	);
	if ( ! is_array( $resultat ) ) {
		return $html . vue_vide();
	}

	$lignes = array();
	foreach ( (array) ( $resultat['lecteurs'] ?? array() ) as $l ) {
		if ( ! is_array( $l ) ) {
			continue;
		}
		$depuis    = strtotime( (string) ( $l['depuis'] ?? '' ) );
		$frequence = (string) ( $l['frequence'] ?? '' );
		$lignes[]  = array(
			esc_html( (string) ( $l['pseudo'] ?? '' ) ) . ( ! empty( $l['equipe'] ) ? ' <span class="yn-chip yn-chip--info yn-stats__equipe">' . esc_html__( 'Équipe', 'yume-core' ) . '</span>' : '' ),
			esc_html( $depuis ? format_fr( $depuis, 'j M Y' ) : '—' ),
			'<span class="yn-chip">' . esc_html( vue_frequences()[ $frequence ] ?? $frequence ) . '</span>',
		);
	}
	if ( $lignes ) {
		$html .= tableau_kpi(
			'yn-stats-t-lecteurs',
			'suiveurs' === $etat['liste'] ? __( 'Lecteurs qui reçoivent les alertes de l’œuvre', 'yume-core' ) : __( 'Lecteurs qui ont mis l’œuvre en favori', 'yume-core' ),
			array( __( 'Pseudo', 'yume-core' ), __( 'Depuis', 'yume-core' ), __( 'Alertes', 'yume-core' ) ),
			$lignes,
			true
		);
	} else {
		$html .= vue_vide( '' !== $etat['q'] ? __( 'Aucun lecteur ne correspond à cette recherche.', 'yume-core' ) : __( 'Aucun lecteur pour le moment.', 'yume-core' ) );
	}
	$page  = max( 1, (int) ( $resultat['page'] ?? $etat['pg'] ) );
	$pages = max( 1, (int) ( $resultat['pages'] ?? 1 ) );
	$args  = array_merge(
		$base,
		array(
			'liste' => 'favoris' !== $etat['liste'] ? $etat['liste'] : '',
			'q'     => $etat['q'],
			'tri'   => 'date' !== $etat['tri'] ? $etat['tri'] : '',
		)
	);
	$html .= pagination_vue( VUE_CLE, $args, $page, $page < $pages, $pages );
	$html .= '<p class="yn-muted yn-stats__note">' . esc_html__( 'Pseudo affiché (le même que sous les commentaires), date et fréquence seulement : jamais l’adresse e-mail.', 'yume-core' ) . '</p>';

	$export = vue_donnees(
		'url_export',
		array(
			'lecteurs',
			array(
				'oeuvre' => $oeuvre_id,
				'liste'  => $etat['liste'],
			),
		)
	);
	if ( is_string( $export ) && '' !== $export && (int) ( $resultat['total'] ?? 0 ) > 0 ) {
		$html .= '<div class="yn-stats__export"><a class="yn-btn" href="' . esc_url( $export ) . '" download>' . esc_html__( 'Exporter la liste (CSV)', 'yume-core' ) . '</a>';
		$html .= '<p class="yn-muted">' . esc_html__( 'Chaque export est journalisé. Le fichier contient des données personnelles : gardez-le pour un usage interne et supprimez-le après usage.', 'yume-core' ) . '</p></div>';
	}
	return $html;
}

/**
 * Fiche statistique d'une œuvre (?vue=statistiques&oeuvre=ID) : chiffres, évolution, listes de
 * lecture, tomes, puis la liste nominative (ou l'encart réservé).
 *
 * @param array $etat État.
 */
function vue_fiche( array $etat ): string {
	$oeuvre_id = $etat['oeuvre'];
	$jours     = $etat['periode'];
	$retour    = '<p><a href="' . esc_url(
		url_statistiques(
			array(
				'periode' => $jours,
				'onglet'  => 'oeuvres',
			)
		)
	) . '">' . esc_html__( '← Retour au classement des œuvres', 'yume-core' ) . '</a></p>';
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return $retour . '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Œuvre introuvable.', 'yume-core' ) . '</p></div>';
	}

	$fiche     = vue_donnees( 'fiche_oeuvre', array( $oeuvre_id, $jours ) );
	$fiche     = is_array( $fiche ) ? $fiche : null;
	$compteurs = $fiche && is_array( $fiche['compteurs'] ?? null ) ? $fiche['compteurs'] : null;
	$titre     = $fiche ? (string) ( $fiche['oeuvre']['titre'] ?? '' ) : '';
	$titre     = '' !== $titre ? $titre : titre_brut( $oeuvre_id );
	$publique  = $fiche ? (string) ( $fiche['oeuvre']['url'] ?? '' ) : '';
	$publique  = '' !== $publique || 'publish' !== get_post_status( $oeuvre_id ) ? $publique : (string) get_permalink( $oeuvre_id );

	// Tête de la fiche.
	$html  = $retour . '<div class="yn-stats__fiche-tete">';
	$html .= has_post_thumbnail( $oeuvre_id ) ? '<span class="yn-stats__couverture" aria-hidden="true">' . get_the_post_thumbnail( $oeuvre_id, 'thumbnail', array( 'alt' => '' ) ) . '</span>' : '<span class="yn-stats__couverture" aria-hidden="true"></span>';
	/* translators: %s : période (« les 30 derniers jours ») */
	$html .= '<div><p class="yn-label">' . esc_html( sprintf( __( 'Statistiques de l’œuvre · %s', 'yume-core' ), vue_periode_longue( $jours ) ) ) . '</p>';
	$html .= '<h2 class="yn-stats__fiche-titre">' . esc_html( $titre ) . '</h2>';
	if ( $fiche ) {
		$depuis = (string) ( $fiche['depuis_historique'] ?? '' );
		$html  .= '<p class="yn-muted">' . esc_html(
			'' !== $depuis
				/* translators: %s : date */
				? sprintf( __( 'Historique quotidien depuis le %s.', 'yume-core' ), graphique_date( $depuis, 'jour', true ) )
				: __( 'L’historique quotidien commence avec le module : les courbes se remplissent jour après jour.', 'yume-core' )
		) . '</p>';
	}
	$html .= '</div>';
	if ( '' !== $publique ) {
		$html .= '<a class="yn-btn" href="' . esc_url( $publique ) . '">' . esc_html__( 'Voir la fiche publique', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $titre ) . '</span></a>';
	}
	$html .= '</div>';

	if ( null === $compteurs ) {
		$html .= vue_vide();
	} else {
		$periode    = is_array( $fiche['periode'] ?? null ) ? $fiche['periode'] : array();
		$frequences = is_array( $fiche['frequences'] ?? null ) ? $fiche['frequences'] : array();
		$nouveaux   = (int) ( $periode['nouveaux_favoris'] ?? 0 );
		$vues       = $periode['vues'] ?? null;
		$vues_prec  = $periode['vues_prec'] ?? null;
		$html      .= '<div class="yn-stats__cartes yn-stats__cartes--fiche">';
		$html      .= vue_carte(
			__( 'Favoris', 'yume-core' ),
			vue_nombre( $compteurs['favoris'] ?? 0 ),
			/* translators: %s : période */
			variation_html( (float) $nouveaux, '' ) . ' <span class="yn-muted">' . esc_html( sprintf( __( 'sur %s', 'yume-core' ), vue_periode_longue( $jours ) ) ) . '</span>',
			''
		);
		$html .= vue_carte(
			__( 'Suiveurs alertés', 'yume-core' ),
			vue_nombre( $compteurs['suiveurs'] ?? 0 ),
			'',
			/* translators: 1: alertes immédiates, 2: alertes hebdomadaires */
			sprintf( __( 'immédiat %1$s · hebdo %2$s', 'yume-core' ), vue_nombre( $frequences['immediat'] ?? 0 ), vue_nombre( $frequences['hebdo'] ?? 0 ) )
		);
		$html .= vue_carte( __( 'Lecteurs', 'yume-core' ), vue_nombre( $compteurs['lecteurs'] ?? 0 ), '', __( 'avec une position de lecture', 'yume-core' ) );
		$html .= vue_carte(
			__( 'Actifs 30 j', 'yume-core' ),
			vue_nombre( $compteurs['lecteurs_30j'] ?? 0 ),
			'',
			/* translators: 1: lecteurs actifs, 2: période */
			sprintf( __( '%1$s actifs sur %2$s', 'yume-core' ), vue_nombre( $periode['lecteurs_actifs'] ?? 0 ), vue_periode_longue( $jours ) )
		);
		$html .= vue_carte( __( 'Chapitres lus (est.)', 'yume-core' ), vue_nombre( $compteurs['chapitres_lus'] ?? 0 ), '', __( 'd’après les positions de lecture', 'yume-core' ) );
		$html .= vue_carte(
			__( 'Vues', 'yume-core' ),
			vue_nombre( $vues ),
			is_numeric( $vues ) && is_numeric( $vues_prec ) ? variation_html( variation( (int) $vues, (int) $vues_prec ) ) : '',
			is_numeric( $vues ) ? vue_periode_precedente( $jours ) : __( 'trafic indisponible', 'yume-core' )
		);
		$html .= '</div>';

		// Évolution (historique quotidien).
		$serie        = is_array( $fiche['serie'] ?? null ) ? $fiche['serie'] : array();
		$titre_courbe = __( 'Évolution des favoris et des suiveurs', 'yume-core' );
		$html        .= vue_section(
			'yn-stats-fiche-evolution',
			$titre_courbe,
			svg_courbe(
				array(
					array(
						'libelle' => __( 'Favoris', 'yume-core' ),
						'points'  => vue_points( $serie, 'favoris' ),
					),
					array(
						'libelle' => __( 'Suiveurs alertés', 'yume-core' ),
						'points'  => vue_points( $serie, 'suiveurs' ),
					),
				),
				array(
					'titre' => $titre_courbe,
					'id'    => 'yn-stats-fiche-evolution',
					'cumul' => false,
					'aire'  => false,
					'vide'  => __( 'Pas encore d’historique quotidien pour cette œuvre.', 'yume-core' ),
				)
			)
		);
		$actifs       = vue_points( $serie, 'lecteurs_30j' );
		$vues_jour    = vue_points( $serie, 'vues' );
		$html        .= '<div class="yn-stats__duo">';
		$titre_courbe = __( 'Lecteurs actifs sur 30 jours', 'yume-core' );
		$html        .= vue_section(
			'yn-stats-fiche-actifs',
			$titre_courbe,
			svg_courbe(
				array(
					array(
						'libelle' => __( 'Lecteurs actifs', 'yume-core' ),
						'points'  => $actifs,
					),
				),
				array(
					'titre'   => $titre_courbe,
					'id'      => 'yn-stats-fiche-actifs',
					'cumul'   => false,
					'hauteur' => 160,
					'vide'    => __( 'Pas encore d’historique quotidien pour cette œuvre.', 'yume-core' ),
				)
			)
		);
		$titre_courbe = __( 'Vues par jour', 'yume-core' );
		$html        .= vue_section(
			'yn-stats-fiche-vues',
			$titre_courbe,
			array_filter( array_column( $vues_jour, 'valeur' ), 'is_int' )
				? svg_courbe(
					array(
						array(
							'libelle' => __( 'Vues', 'yume-core' ),
							'points'  => $vues_jour,
						),
					),
					array(
						'titre'   => $titre_courbe,
						'id'      => 'yn-stats-fiche-vues',
						'hauteur' => 160,
					)
				)
				: vue_message_trafic()
		);
		$html        .= '</div>';

		// Listes de lecture et tomes.
		$listes = is_array( $compteurs['listes'] ?? null ) ? $compteurs['listes'] : array();
		$corps  = '<dl class="yn-stats__listes-lecture">';
		foreach (
			array(
				'a_lire'   => __( 'À lire', 'yume-core' ),
				'en_cours' => __( 'En cours', 'yume-core' ),
				'termine'  => __( 'Terminé', 'yume-core' ),
				'perso'    => __( 'Listes perso', 'yume-core' ),
			) as $cle => $libelle
		) {
			$corps .= '<div><dt class="yn-label">' . esc_html( $libelle ) . '</dt><dd>' . esc_html( vue_nombre( $listes[ $cle ] ?? 0 ) ) . '</dd></div>';
		}
		$corps .= '</dl><p class="yn-muted yn-stats__note">' . esc_html__( 'Chiffres seulement : les listes peuvent être privées.', 'yume-core' ) . '</p>';
		$html  .= '<div class="yn-stats__duo">' . vue_section( 'yn-stats-fiche-listes', __( 'Dans les listes des lecteurs', 'yume-core' ), $corps );
		$lignes = array();
		foreach ( (array) ( $fiche['tomes'] ?? array() ) as $t ) {
			if ( is_array( $t ) ) {
				$lignes[] = array( esc_html( (string) ( $t['titre'] ?? '' ) ), esc_html( vue_nombre( $t['chapitres'] ?? 0 ) ), esc_html( vue_nombre( $t['lecteurs_positionnes'] ?? 0 ) ) );
			}
		}
		$html .= vue_section(
			'yn-stats-fiche-tomes',
			__( 'Lecteurs par tome', 'yume-core' ),
			$lignes
				? tableau_kpi( 'yn-stats-t-tomes', __( 'Lecteurs dont la position de lecture est dans chaque tome', 'yume-core' ), array( __( 'Tome', 'yume-core' ), __( 'Chapitres', 'yume-core' ), __( 'Lecteurs positionnés', 'yume-core' ) ), $lignes, true )
				: vue_vide()
		) . '</div>';
	}

	// Lecteurs qui suivent l'œuvre : liste nominative (gérants) ou encart réservé.
	$tete  = '<p class="yn-muted">' . esc_html__( 'Réservé aux gérants et administrateurs · données personnelles, usage interne', 'yume-core' ) . '</p>';
	$corps = vue_peut_voir_lecteurs()
		? vue_liste_nominative( $oeuvre_id, $etat, $compteurs )
		: vue_cadenas( null !== $compteurs ? (int) ( $compteurs['favoris'] ?? 0 ) : null );
	return $html . vue_section( 'yn-stats-fiche-lecteurs', __( 'Lecteurs qui suivent l’œuvre', 'yume-core' ), $corps, $tete );
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Vue « Statistiques » (?vue=statistiques).
 */
function rendu_vue_statistiques(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--kpi yn-team--stats' );
	$html .= navigation_equipe( VUE_CLE );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( CAPACITE_STATS ) ) {
		$html .= tete_vue( __( 'Statistiques', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les membres de l’équipe peuvent consulter les statistiques.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}
	wp_enqueue_style( VUE_STYLE );

	$etat  = vue_etat();
	$html .= vue_entete( $etat );
	if ( $etat['oeuvre'] ) {
		$html .= vue_fiche( $etat );
	} else {
		$onglets = array(
			'apercu'   => __NAMESPACE__ . '\\vue_onglet_apercu',
			'trafic'   => __NAMESPACE__ . '\\vue_onglet_trafic',
			'oeuvres'  => __NAMESPACE__ . '\\vue_onglet_oeuvres',
			'lecteurs' => __NAMESPACE__ . '\\vue_onglet_lecteurs',
		);
		$html   .= '<div class="yn-stats__onglet" id="yn-stats-' . esc_attr( $etat['onglet'] ) . '">' . call_user_func( $onglets[ $etat['onglet'] ], $etat ) . '</div>';
	}
	return $html . '</div></div>';
}
