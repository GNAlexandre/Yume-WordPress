<?php
/**
 * Vue d'équipe « Mettre en page un chapitre » (?vue=chapitre&id=ID), déclarée par le filtre
 * yume_vues_equipe, hors menu (on y arrive par un chapitre : fiche du tome, vue Corrections).
 *
 * Avec JavaScript (assets/editeur.js) : colonne de lecture identique au site (.yn-reader,
 * reader.css du thème), palette Récit / Dialogue / Pensée / Centré / Séparateur / Intertitre,
 * sélection (clic, Maj, Ctrl/Cmd, glisser, flèches), texte en place (double clic ou Entrée),
 * actions groupées, annuler / rétablir, Enregistrer (PUT …/mise-en-page). Sans JavaScript : le
 * chapitre en lecture seule et un renvoi vers l'édition avancée.
 *
 * @package Yume\Core
 */

namespace Yume\Core\MiseEnPage;

use function Yume\Core\Planning\acces_refuse_vue;
use function Yume\Core\Planning\get_entier;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\ouvrir_racine;
use function Yume\Core\Planning\tete_vue;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

/** Clé de la vue. */
const VUE = 'chapitre';

/** Poignée du script et du style. */
const POIGNEE = 'yume-mise-en-page';

/**
 * Adresse de la vue pour un chapitre.
 *
 * @param int $chapitre_id Chapitre.
 */
function url_vue( int $chapitre_id ): string {
	if ( function_exists( 'Yume\\Core\\Planning\\url_vue_equipe' ) ) {
		return url_vue_equipe( VUE, array( 'id' => $chapitre_id ) );
	}
	$base = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
	return add_query_arg(
		array(
			'vue' => VUE,
			'id'  => $chapitre_id,
		),
		$base
	);
}

/**
 * Déclare la vue (filtre yume_vues_equipe), hors menu.
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue( $vues ): array {
	$vues        = is_array( $vues ) ? $vues : array();
	$vues[ VUE ] = array(
		'libelle'  => __( 'Mettre en page', 'yume-core' ),
		'capacite' => 'yume_publier',
		'groupe'   => 'catalogue',
		'menu'     => false,
		'rendu'    => __NAMESPACE__ . '\\rendu_vue',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue' );

/**
 * Enregistre le script et le style de la vue.
 */
function enregistrer_ressources(): void {
	wp_register_style( POIGNEE, YUME_CORE_URL . 'includes/mise-en-page/assets/editeur.css', array(), YUME_CORE_VERSION );
	wp_register_script( POIGNEE, YUME_CORE_URL . 'includes/mise-en-page/assets/editeur.js', array(), YUME_CORE_VERSION, array( 'in_footer' => true ) );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_ressources' );

/**
 * Textes de l'interface passés au script.
 *
 * @return array<string,string>
 */
function textes_script(): array {
	return array(
		'chargement'    => __( 'Chargement du chapitre…', 'yume-core' ),
		'erreur'        => __( 'Le chapitre n’a pas pu être chargé. Rechargez la page.', 'yume-core' ),
		/* translators: %s : heure (« 18 h 02 ») */
		'enregistre'    => __( 'Enregistré à %s', 'yume-core' ),
		'envoi'         => __( 'Enregistrement…', 'yume-core' ),
		'aucun'         => __( 'Aucune modification', 'yume-core' ),
		/* translators: %d : nombre */
		'modifie1'      => __( '%d paragraphe modifié', 'yume-core' ),
		/* translators: %d : nombre */
		'modifies'      => __( '%d paragraphes modifiés', 'yume-core' ),
		/* translators: %s : message d’erreur */
		'echec'         => __( 'L’enregistrement a échoué : %s', 'yume-core' ),
		'session'       => __( 'Votre session a expiré : rechargez la page (vos modifications non enregistrées seront perdues).', 'yume-core' ),
		'reseau'        => __( 'Connexion impossible : vérifiez votre réseau puis réessayez.', 'yume-core' ),
		'quitter'       => __( 'Des modifications ne sont pas enregistrées.', 'yume-core' ),
		'recharger'     => __( 'Recharger', 'yume-core' ),
		/* translators: 1: nombre, 2: style */
		'passes1'       => __( '%1$d paragraphe passé en %2$s', 'yume-core' ),
		/* translators: 1: nombre, 2: style */
		'passes'        => __( '%1$d paragraphes passés en %2$s', 'yume-core' ),
		'selection0'    => __( 'Aucun paragraphe sélectionné', 'yume-core' ),
		'selection1'    => __( '1 paragraphe sélectionné', 'yume-core' ),
		/* translators: %d : nombre */
		'selections'    => __( '%d paragraphes sélectionnés', 'yume-core' ),
		'separateur'    => __( 'Séparateur de scène inséré', 'yume-core' ),
		'intertitre1'   => __( '1 paragraphe passé en intertitre', 'yume-core' ),
		'paragraphe1'   => __( '1 intertitre repassé en paragraphe', 'yume-core' ),
		'supprimes1'    => __( '1 paragraphe vide supprimé', 'yume-core' ),
		/* translators: %d : nombre */
		'intertitre'    => __( '%d paragraphes passés en intertitre', 'yume-core' ),
		/* translators: %d : nombre */
		'paragraphe'    => __( '%d intertitres repassés en paragraphe', 'yume-core' ),
		'scinde'        => __( 'Paragraphe scindé', 'yume-core' ),
		'fusionne'      => __( 'Paragraphe fusionné avec le précédent', 'yume-core' ),
		/* translators: %d : nombre */
		'supprimes'     => __( '%d paragraphes vides supprimés', 'yume-core' ),
		'vide_retire'   => __( 'Paragraphe vide retiré', 'yume-core' ),
		'ajoute'        => __( 'Nouveau paragraphe : tapez votre texte, Échap ou un clic ailleurs pour terminer', 'yume-core' ),
		'supprime1'     => __( '1 élément supprimé (Ctrl+Z pour annuler)', 'yume-core' ),
		/* translators: %d : nombre */
		'supprimes_sel' => __( '%d éléments supprimés (Ctrl+Z pour annuler)', 'yume-core' ),
		/* translators: %d : nombre */
		'colles'        => __( '%d paragraphes collés', 'yume-core' ),
		'rien'          => __( 'Rien à changer', 'yume-core' ),
		'annule'        => __( 'Modification annulée', 'yume-core' ),
		'retabli'       => __( 'Modification rétablie', 'yume-core' ),
		/* translators: %d : nombre */
		'saisie'        => __( 'Saisie du paragraphe %d : Entrée pour scinder, Échap pour annuler', 'yume-core' ),
		'saisie_ok'     => __( 'Texte modifié', 'yume-core' ),
		'saisie_annul'  => __( 'Saisie annulée', 'yume-core' ),
		'intouchable'   => __( 'Image ou notes : non modifiable ici', 'yume-core' ),
		'recit'         => __( 'Récit', 'yume-core' ),
		'dialogue'      => __( 'Dialogue', 'yume-core' ),
		'pensee'        => __( 'Pensée', 'yume-core' ),
		'centre'        => __( 'Centré', 'yume-core' ),
		'sep'           => __( 'Séparateur de scène', 'yume-core' ),
		'titre'         => __( 'Intertitre', 'yume-core' ),
		'autre'         => __( 'Bloc non modifiable', 'yume-core' ),
		/* translators: 1: style, 2: numéro du paragraphe */
		'unite'         => __( '%1$s, paragraphe %2$d', 'yume-core' ),
		'lecture'       => __( 'Lecture seule : votre rôle ne permet pas d’enregistrer.', 'yume-core' ),
	);
}

/**
 * Bouton de la barre d'outils ou de la palette.
 *
 * @param string $libelle  Libellé visible.
 * @param array  $attrs    Attributs (data-*, aria-*…).
 * @param string $classe   Classes supplémentaires.
 * @param string $raccourci Touche affichée (« D »), facultative.
 */
function bouton( string $libelle, array $attrs, string $classe = '', string $raccourci = '' ): string {
	$html = '<button type="button" class="yn-btn yn-btn--sm ' . esc_attr( $classe ) . '"';
	foreach ( $attrs as $nom => $valeur ) {
		$html .= ' ' . esc_attr( (string) $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	$html .= '>';
	if ( '' !== $raccourci ) {
		$html .= '<kbd class="yn-mep__touche" aria-hidden="true">' . esc_html( $raccourci ) . '</kbd> ';
	}
	return $html . esc_html( $libelle ) . '</button>';
}

/**
 * Palette des styles (à droite sur bureau, en bas sur mobile).
 */
function palette(): string {
	$styles = array(
		'recit'    => array( __( 'Récit', 'yume-core' ), 'R' ),
		'dialogue' => array( __( 'Dialogue', 'yume-core' ), 'D' ),
		'pensee'   => array( __( 'Pensée', 'yume-core' ), 'P' ),
		'centre'   => array( __( 'Centré', 'yume-core' ), 'C' ),
	);
	$html   = '<aside class="yn-mep__palette" data-yn-mep-palette hidden aria-labelledby="yn-mep-palette-titre"><h3 class="yn-label" id="yn-mep-palette-titre">' . esc_html__( 'Style de la sélection', 'yume-core' ) . '</h3>';
	$html  .= '<div class="yn-mep__styles" role="group" aria-labelledby="yn-mep-palette-titre">';
	foreach ( $styles as $style => $infos ) {
		$html .= bouton(
			$infos[0],
			array(
				'data-yn-mep-style' => $style,
				'aria-pressed'      => 'false',
				'aria-keyshortcuts' => $infos[1],
			),
			'yn-mep__style yn-mep__style--' . $style,
			$infos[1]
		);
	}
	$html .= '</div><div class="yn-mep__styles" role="group" aria-label="' . esc_attr__( 'Structure', 'yume-core' ) . '">';
	$html .= bouton(
		__( 'Séparateur de scène', 'yume-core' ),
		array(
			'data-yn-mep-op'    => 'separateur',
			'aria-keyshortcuts' => 'S',
			'title'             => __( 'Insère un séparateur de scène avant la sélection', 'yume-core' ),
		),
		'yn-mep__style',
		'S'
	);
	$html .= bouton(
		__( 'Intertitre', 'yume-core' ),
		array(
			'data-yn-mep-op'    => 'intertitre',
			'aria-pressed'      => 'false',
			'aria-keyshortcuts' => 'T',
			'title'             => __( 'Transforme le paragraphe en intertitre, et inversement', 'yume-core' ),
		),
		'yn-mep__style',
		'T'
	);
	$html .= '</div><div class="yn-mep__styles" role="group" aria-label="' . esc_attr__( 'Paragraphes', 'yume-core' ) . '">';
	$html .= bouton(
		__( 'Ajouter un paragraphe après', 'yume-core' ),
		array(
			'data-yn-mep-op'    => 'ajouter',
			'aria-keyshortcuts' => 'N',
		),
		'yn-mep__style',
		'N'
	);
	$html .= bouton(
		__( 'Supprimer le paragraphe', 'yume-core' ),
		array(
			'data-yn-mep-op'    => 'supprimer',
			'aria-keyshortcuts' => 'Delete',
			'title'             => __( 'Supprime la sélection (Annuler pour revenir en arrière)', 'yume-core' ),
		),
		'yn-mep__style',
		'Suppr'
	);
	$html .= '</div><p class="yn-muted yn-mep__selection" data-yn-mep-selection aria-live="polite"></p></aside>';
	return $html;
}

/**
 * Barre d'outils : Enregistrer, compteur, état, annuler / rétablir, actions, aperçu, aide.
 */
function barre(): string {
	$html    = '<div class="yn-mep__barre" data-yn-mep-barre hidden role="toolbar" aria-label="' . esc_attr__( 'Mise en page', 'yume-core' ) . '">';
	$html   .= '<div class="yn-mep__groupe">';
	$html   .= bouton(
		__( 'Enregistrer', 'yume-core' ),
		array(
			'data-yn-mep-enregistrer' => '',
			'aria-keyshortcuts'       => 'Control+S',
		),
		'yn-btn--primary'
	);
	$html   .= '<span class="yn-mep__compteur" data-yn-mep-compteur>' . esc_html__( 'Aucune modification', 'yume-core' ) . '</span>';
	$html   .= '<span class="yn-muted yn-mep__etat" data-yn-mep-etat role="status"></span>';
	$html   .= '</div><div class="yn-mep__groupe">';
	$html   .= bouton(
		__( 'Annuler', 'yume-core' ),
		array(
			'data-yn-mep-annuler' => '',
			'aria-keyshortcuts'   => 'Control+Z',
			'disabled'            => 'disabled',
		)
	);
	$html   .= bouton(
		__( 'Rétablir', 'yume-core' ),
		array(
			'data-yn-mep-retablir' => '',
			'aria-keyshortcuts'    => 'Control+Shift+Z',
			'disabled'             => 'disabled',
		)
	);
	$html   .= '<div class="yn-mep__menu"><button type="button" class="yn-btn yn-btn--sm" aria-expanded="false" aria-controls="yn-mep-actions" data-yn-mep-menu>' . esc_html__( 'Actions', 'yume-core' ) . ' <span aria-hidden="true">▾</span></button>';
	$html   .= '<ul class="yn-mep__actions yn-card" id="yn-mep-actions" hidden>';
	$actions = array(
		'tirets'   => __( 'Lignes commençant par un tiret → Dialogue', 'yume-core' ),
		'recit'    => __( 'Toute la sélection → Récit', 'yume-core' ),
		'italique' => __( 'Paragraphes entièrement en italique → Pensée', 'yume-core' ),
		'vides'    => __( 'Supprimer les paragraphes vides', 'yume-core' ),
	);
	foreach ( $actions as $cle => $libelle ) {
		$html .= '<li><button type="button" class="yn-mep__action" data-yn-mep-action="' . esc_attr( $cle ) . '">' . esc_html( $libelle ) . '</button></li>';
	}
	$html .= '</ul></div>';
	$html .= '</div><div class="yn-mep__groupe" role="group" aria-label="' . esc_attr__( 'Aperçu', 'yume-core' ) . '">';
	$html .= bouton(
		__( 'Bureau', 'yume-core' ),
		array(
			'data-yn-mep-largeur' => 'bureau',
			'aria-pressed'        => 'true',
		)
	);
	$html .= bouton(
		__( 'Mobile', 'yume-core' ),
		array(
			'data-yn-mep-largeur' => 'mobile',
			'aria-pressed'        => 'false',
		)
	);
	foreach ( array(
		'nuit'   => __( 'Nuit', 'yume-core' ),
		'papier' => __( 'Papier', 'yume-core' ),
		'sepia'  => __( 'Sépia', 'yume-core' ),
	) as $theme => $libelle ) {
		$html .= bouton(
			$libelle,
			array(
				'data-yn-mep-theme' => $theme,
				'aria-pressed'      => 'false',
			)
		);
	}
	$html .= bouton(
		'?',
		array(
			'data-yn-mep-aide'  => '',
			'aria-expanded'     => 'false',
			'aria-controls'     => 'yn-mep-raccourcis',
			'aria-label'        => __( 'Raccourcis clavier', 'yume-core' ),
			'aria-keyshortcuts' => 'Shift+?',
		)
	);
	return $html . '</div></div>';
}

/**
 * Panneau « ? » : raccourcis.
 */
function aide(): string {
	$lignes = array(
		array( __( 'Clic', 'yume-core' ), __( 'sélectionner un paragraphe', 'yume-core' ) ),
		array( __( 'Maj + clic, glisser', 'yume-core' ), __( 'sélectionner une plage', 'yume-core' ) ),
		array( __( 'Ctrl / Cmd + clic', 'yume-core' ), __( 'ajouter ou retirer un paragraphe', 'yume-core' ) ),
		array( '↑ ↓', __( 'déplacer la sélection (Maj : l’étendre)', 'yume-core' ) ),
		array( __( 'Échap', 'yume-core' ), __( 'vider la sélection, annuler une saisie', 'yume-core' ) ),
		array( 'R D P C', __( 'Récit, Dialogue, Pensée, Centré', 'yume-core' ) ),
		array( 'S', __( 'séparateur de scène avant la sélection', 'yume-core' ) ),
		array( 'N', __( 'ajouter un paragraphe après la sélection et taper dedans', 'yume-core' ) ),
		array( __( 'Suppr', 'yume-core' ), __( 'supprimer la sélection (Ctrl + Z pour la retrouver)', 'yume-core' ) ),
		array( 'T', __( 'intertitre, et inversement', 'yume-core' ) ),
		array( __( 'Double clic, Entrée', 'yume-core' ), __( 'corriger le texte en place', 'yume-core' ) ),
		array( __( 'Ctrl + I, Ctrl + B', 'yume-core' ), __( 'italique, gras (pendant la saisie)', 'yume-core' ) ),
		array( __( 'Entrée (saisie)', 'yume-core' ), __( 'en fin de paragraphe : nouveau paragraphe du même style ; au milieu : scinder', 'yume-core' ) ),
		array( __( 'Maj + Entrée (saisie)', 'yume-core' ), __( 'saut de ligne dans le paragraphe', 'yume-core' ) ),
		array( __( 'Coller (saisie)', 'yume-core' ), __( 'texte sans mise en forme sauf italique et gras ; une ligne collée = un paragraphe', 'yume-core' ) ),
		array( __( 'Retour arrière en début (saisie)', 'yume-core' ), __( 'fusionner avec le précédent', 'yume-core' ) ),
		array( __( 'Clic ailleurs', 'yume-core' ), __( 'valider la saisie', 'yume-core' ) ),
		array( __( 'Ctrl + Z, Ctrl + Maj + Z', 'yume-core' ), __( 'annuler, rétablir', 'yume-core' ) ),
		array( __( 'Ctrl + S', 'yume-core' ), __( 'enregistrer', 'yume-core' ) ),
	);
	$html   = '<section class="yn-card yn-mep__aide" id="yn-mep-raccourcis" hidden aria-labelledby="yn-mep-aide-titre"><h3 id="yn-mep-aide-titre">' . esc_html__( 'Raccourcis', 'yume-core' ) . '</h3><dl>';
	foreach ( $lignes as $l ) {
		$html .= '<div><dt><kbd>' . esc_html( $l[0] ) . '</kbd></dt><dd>' . esc_html( $l[1] ) . '</dd></div>';
	}
	$html .= '</dl><p class="yn-muted">' . esc_html__( 'Les actions groupées s’appliquent à la sélection, ou à tout le chapitre quand rien n’est sélectionné. Le tiret des dialogues est posé par le style : ne le tapez pas. Un paragraphe laissé vide disparaît quand vous le quittez. Rien n’est annoncé aux lecteurs ; chaque enregistrement crée une révision.', 'yume-core' ) . '</p></section>';
	return $html;
}

/**
 * Fil d'Ariane œuvre › tome › chapitre.
 *
 * @param \WP_Post $post Chapitre.
 */
function fil( \WP_Post $post ): string {
	$tome_id   = (int) get_post_meta( $post->ID, 'yume_tome_id', true );
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? (int) yume_get_oeuvre_id( (int) $post->ID ) : 0;
	$items     = array();
	if ( $oeuvre_id && $oeuvre_id !== (int) $post->ID ) {
		$url     = current_user_can( 'edit_post', $oeuvre_id ) ? url_vue_equipe( 'oeuvres', array( 'modifier' => $oeuvre_id ) ) : '';
		$items[] = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $oeuvre_id ) ) . '</a>' : esc_html( get_the_title( $oeuvre_id ) );
	}
	if ( $tome_id ) {
		$libelle = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $tome_id ) : get_the_title( $tome_id );
		$url     = function_exists( 'Yume\\Core\\Planning\\url_modifier_tome' ) ? \Yume\Core\Planning\url_modifier_tome( $tome_id ) . '#yn-chapitre-' . (int) $post->ID : '';
		$items[] = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $libelle ) . '</a>' : esc_html( $libelle );
	}
	$items[] = '<span aria-current="page">' . esc_html( titre_chapitre( (int) $post->ID ) ) . '</span>';
	$html    = '<nav class="yn-mep__fil" aria-label="' . esc_attr__( 'Fil d’Ariane', 'yume-core' ) . '"><ol>';
	foreach ( $items as $item ) {
		$html .= '<li>' . $item . '</li>';
	}
	return $html . '</ol></nav>';
}

/**
 * Liens « Voir le chapitre », « Historique des révisions », « Édition avancée ».
 *
 * @param \WP_Post $post Chapitre.
 */
function liens( \WP_Post $post ): string {
	$html      = '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_lecture( $post ) ) . '" data-yn-mep-voir>' . esc_html__( 'Voir le chapitre', 'yume-core' ) . '</a>';
	$revisions = url_revisions( (int) $post->ID );
	if ( '' !== $revisions ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( $revisions ) . '" data-yn-mep-revisions>' . esc_html__( 'Historique des révisions', 'yume-core' ) . '</a>';
	}
	$avancee = (string) get_edit_post_link( $post->ID, 'raw' );
	if ( '' !== $avancee ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( $avancee ) . '">' . esc_html__( 'Édition avancée (administration WordPress)', 'yume-core' ) . '</a>';
	}
	return $html;
}

/**
 * Navigation « Chapitre précédent / suivant » du même tome.
 *
 * @param \WP_Post $post Chapitre.
 */
function navigation_chapitres( \WP_Post $post ): string {
	$precedent = voisin( $post, -1 );
	$suivant   = voisin( $post, 1 );
	if ( ! $precedent && ! $suivant ) {
		return '';
	}
	$html = '<nav class="yn-mep__voisins" aria-label="' . esc_attr__( 'Chapitres du tome', 'yume-core' ) . '">';
	if ( $precedent ) {
		$html .= '<a class="yn-btn yn-btn--sm" rel="prev" href="' . esc_url( $precedent['url'] ) . '"><span aria-hidden="true">←</span> ' . esc_html__( 'Chapitre précédent', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $precedent['libelle'] ) . '</span></a>';
	}
	if ( $suivant ) {
		$html .= '<a class="yn-btn yn-btn--sm" rel="next" href="' . esc_url( $suivant['url'] ) . '">' . esc_html__( 'Chapitre suivant', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $suivant['libelle'] ) . '</span> <span aria-hidden="true">→</span></a>';
	}
	return $html . '</nav>';
}

/**
 * Vue « Mettre en page ».
 */
function rendu_vue(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--mep' );
	$html .= navigation_equipe( VUE );
	$html .= '<div class="yn-team__principal">';
	$titre = __( 'Mettre en page', 'yume-core' );
	$id    = get_entier( 'id' );
	$post  = chapitre( $id );
	if ( ! $post ) {
		return acces_refuse_vue( $html, $titre, __( 'Ce chapitre n’existe pas ou a été supprimé. Ouvrez la mise en page depuis la fiche d’un tome (section Chapitres).', 'yume-core' ), url_vue_equipe( 'tomes' ), __( 'Tous les tomes', 'yume-core' ) );
	}
	if ( ! peut( $id ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Votre rôle ne permet pas de mettre en page ce chapitre : réservé aux éditeurs et aux gérants.', 'yume-core' ) );
	}
	$mangadex = est_mangadex( $id );
	if ( ! $mangadex ) {
		wp_enqueue_style( POIGNEE );
		wp_enqueue_script( POIGNEE );
		wp_add_inline_script( POIGNEE, 'window.ynMiseEnPage = ' . wp_json_encode( array( 'textes' => textes_script() ) ) . ';', 'before' );
	} else {
		wp_enqueue_style( POIGNEE );
	}

	$html .= fil( $post );
	/* translators: %s : chapitre (« Chapitre 2, partie 1 — 10 septembre, soir ») */
	$html .= tete_vue( sprintf( __( 'Mettre en page · %s', 'yume-core' ), titre_chapitre( $id ) ), liens( $post ) );
	if ( $mangadex ) {
		return $html . '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Ce chapitre est hébergé sur MangaDex : il n’a pas de texte à mettre en page.', 'yume-core' ) . '</p></div></div></div>';
	}

	$html   .= '<div class="yn-mep" data-yn-mep data-chapitre="' . esc_attr( (string) $id ) . '">';
	$html   .= '<div class="yn-card yn-mep__sansjs" data-yn-mep-sansjs><p>' . esc_html__( 'La mise en page se fait avec JavaScript. Le chapitre est affiché ci-dessous en lecture seule ; pour le corriger sans JavaScript, utilisez l’édition avancée.', 'yume-core' ) . '</p>';
	$avancee = (string) get_edit_post_link( $id, 'raw' );
	if ( '' !== $avancee ) {
		$html .= '<p><a class="yn-btn yn-btn--sm" href="' . esc_url( $avancee ) . '">' . esc_html__( 'Édition avancée (administration WordPress)', 'yume-core' ) . '</a></p>';
	}
	$html .= '</div>';
	$html .= '<p class="yn-card yn-mep__presence" data-yn-mep-presence role="status" hidden></p>';
	$html .= '<div class="yn-mep__alerte" data-yn-mep-alerte role="alert" hidden></div>';
	$html .= barre();
	$html .= aide();
	$html .= '<div class="yn-mep__corps">';
	$html .= '<div class="yn-mep__colonne" data-yn-mep-colonne><div class="yn-reader yn-mep__lecture"><div class="wp-block-post-content" data-yn-mep-unites aria-label="' . esc_attr__( 'Paragraphes du chapitre', 'yume-core' ) . '">';
	$html .= wp_kses_post( do_blocks( (string) $post->post_content ) );
	$html .= '</div></div></div>';
	$html .= palette();
	$html .= '</div>';
	$html .= '<p class="yn-visually-hidden" data-yn-mep-annonce aria-live="polite" aria-atomic="true"></p>';
	$html .= navigation_chapitres( $post );
	$html .= '</div>';
	return $html . '</div></div>';
}
