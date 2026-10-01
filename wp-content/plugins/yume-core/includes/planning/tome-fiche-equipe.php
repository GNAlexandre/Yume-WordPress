<?php
/**
 * Espace équipe, fiches de tome rangées sous « Tous les tomes » (menu Catalogue) :
 *
 * - « Nouveau tome » (?vue=tomes&nouveau=1, capacité yume_maj_planning_tous) remplace le
 *   formulaire « Ajouter un tome au planning » du tableau de bord : mêmes champs, plus les
 *   chapitres prévus et le rythme de sortie, même traitement (admin-post yume_planning_ajout →
 *   ajouter_tome(), comme POST /yume/v1/planning/tomes). Œuvre présélectionnée par ?oeuvre=ID
 *   (ancien paramètre ?oeuvre_ajout=ID accepté), page d'origine par ?depuis= (tomes, planning,
 *   oeuvres, tableau). Si l'œuvre a déjà un tome de même nature et de même numéro, sa fiche
 *   s'ouvre au lieu d'en créer un second. « Créer le tome et ajouter un chapitre » mène au
 *   formulaire de publication (?tome=ID).
 * - « Modifier le tome » (?vue=tomes&modifier=ID, yume_publier + edit_post, comme « Modifier
 *   l'œuvre ») : œuvre, nature, numéro, titre, responsables, date cible, chapitres prévus, rythme,
 *   crédits, couverture et son cadrage ; encadré « Tome complet » (parution « complet » et liens
 *   PDF / EPUB, sans annonce) ; chapitres du tome (tous statuts sauf corbeille et versions en
 *   attente) avec « Voir », « Aperçu », « Publier maintenant », « Changer la date » et
 *   « Retirer » (écran de confirmation, sans JavaScript) ; résumé du planning ; lien « Édition
 *   avancée » vers l'administration.
 *
 * Formulaires sans JavaScript (admin-post.php : yume_planning_ajout, yume_tome_modifier,
 * yume_tome_chapitre), résultat affiché au retour (retour_formulaire()).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

use Yume\Core\Publication\Fichiers;
use Yume\Core\Publication\Medias;

defined( 'ABSPATH' ) || exit;

/** Méta d'un chapitre retiré (repris par le formulaire de publication : jamais republié d'office). */
const META_CHAPITRE_RETIRE = '_yume_retire';

/*
 * -----------------------------------------------------------------------------
 * Adresses
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse de « Nouveau tome ».
 *
 * @param int    $oeuvre_id Œuvre présélectionnée (0 : aucune).
 * @param string $depuis    Page d'origine (voir origines_nouveau_tome()).
 */
function url_nouveau_tome( int $oeuvre_id = 0, string $depuis = '' ): string {
	return url_vue_equipe(
		'tomes',
		array(
			'nouveau' => 1,
			'oeuvre'  => $oeuvre_id,
			'depuis'  => $depuis,
		)
	);
}

/**
 * Adresse de « Modifier le tome ».
 *
 * @param int   $tome_id Tome.
 * @param array $args    Paramètres supplémentaires.
 */
function url_modifier_tome( int $tome_id, array $args = array() ): string {
	return url_vue_equipe( 'tomes', array_merge( array( 'modifier' => $tome_id ), $args ) );
}

/**
 * Formulaire de publication prérempli avec un tome (« Ajouter des chapitres »).
 *
 * @param int $tome_id Tome.
 */
function url_publier_tome( int $tome_id ): string {
	return add_query_arg( 'tome', $tome_id, yume_url_page( 'publier' ) );
}

/**
 * Pages d'où l'on ouvre « Nouveau tome » : clé => array{0: nom, 1: libellé du retour}.
 *
 * @return array<string,array{0:string,1:string}>
 */
function origines_nouveau_tome(): array {
	return array(
		'tomes'    => array( __( 'Tous les tomes', 'yume-core' ), __( 'Revenir à « Tous les tomes »', 'yume-core' ) ),
		'planning' => array( __( 'Planning complet', 'yume-core' ), __( 'Revenir au planning', 'yume-core' ) ),
		'oeuvres'  => array( __( 'Œuvres', 'yume-core' ), __( 'Revenir aux œuvres', 'yume-core' ) ),
		'tableau'  => array( __( 'Tableau de bord', 'yume-core' ), __( 'Revenir au tableau de bord', 'yume-core' ) ),
		'publier'  => array( __( 'Ajouter des chapitres', 'yume-core' ), __( 'Revenir à « Ajouter des chapitres »', 'yume-core' ) ),
	);
}

/**
 * Adresse de retour vers la page d'origine de « Nouveau tome ».
 *
 * @param string $depuis    Origine.
 * @param int    $oeuvre_id Œuvre présélectionnée.
 */
function url_origine_nouveau_tome( string $depuis, int $oeuvre_id = 0 ): string {
	switch ( $depuis ) {
		case 'planning':
			return url_vue_equipe( 'planning' );
		case 'oeuvres':
			return url_vue_equipe( 'oeuvres' ) . ( $oeuvre_id ? '#yn-oeuvre-' . $oeuvre_id : '' );
		case 'tableau':
			return url_vue_equipe();
		case 'publier':
			return function_exists( 'yume_url_page' ) ? (string) yume_url_page( 'publier' ) : url_vue_equipe( 'tomes' );
		default:
			return url_vue_equipe( 'tomes' );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Champs communs
 * -----------------------------------------------------------------------------
 */

/**
 * Chapitres prévus et rythme de sortie (jour, heure).
 *
 * @param string $prefixe Préfixe des identifiants.
 * @param string $prevus  Chapitres prévus ('' : inconnu).
 * @param string $jour    Jour du rythme ('' : libre).
 * @param string $heure   Heure du rythme (HH:MM).
 */
function champs_rythme( string $prefixe, string $prevus, string $jour, string $heure ): string {
	$jours = array( '' => __( 'Libre', 'yume-core' ) );
	foreach ( yume_jours_semaine() as $cle => $libelle ) {
		/* translators: %s : jour de la semaine (lundi…) */
		$jours[ $cle ] = sprintf( __( 'Chaque %s', 'yume-core' ), mb_strtolower( $libelle ) );
	}
	$html  = champ_saisie(
		$prefixe . '-prevus',
		'chapitres_prevus',
		__( 'Chapitres prévus (facultatif)', 'yume-core' ),
		$prevus,
		'number',
		array(
			'min'  => 0,
			'max'  => 999,
			'step' => 1,
		)
	);
	$html .= champ_select( $prefixe . '-rythme', 'rythme_jour', __( 'Rythme (facultatif)', 'yume-core' ), $jours, $jour );
	$html .= champ_saisie( $prefixe . '-heure', 'rythme_heure', __( 'Heure de sortie', 'yume-core' ), '' !== $heure ? $heure : '18:00', 'time', array( 'step' => 60 ) );
	return $html;
}

/**
 * Responsables des trois étapes (listes des membres de l'équipe).
 *
 * @param string $prefixe Préfixe des identifiants.
 * @param array  $membres Membres (ID => pseudo).
 * @param array  $valeurs Responsables actuels (étape => ID).
 */
function champs_responsables( string $prefixe, array $membres, array $valeurs ): string {
	$html = '';
	foreach ( ETAPES_TRAVAIL as $e ) {
		$choix = array( '0' => __( '— Personne —', 'yume-core' ) ) + $membres;
		$uid   = (int) ( $valeurs[ $e ] ?? 0 );
		if ( $uid && ! isset( $choix[ $uid ] ) ) {
			$choix[ $uid ] = nom_utilisateur( $uid );
		}
		/* translators: %s : étape */
		$html .= champ_select( $prefixe . '-resp-' . $e, 'responsables[' . $e . ']', sprintf( __( 'Responsable %s', 'yume-core' ), libelle_etape_min( $e ) ), $choix, (string) $uid );
	}
	return $html;
}

/**
 * Encadré des trois états de parution (rien à choisir : l'état suit les chapitres).
 */
function encadre_parutions(): string {
	$etats = array(
		'a_paraitre' => array( __( 'Dès la création.', 'yume-core' ), __( 'Aucun chapitre en ligne : le tome figure au planning, sans bouton « Lire ».', 'yume-core' ) ),
		'en_cours'   => array( __( 'Au premier chapitre publié.', 'yume-core' ), __( 'Le tome se lit en ligne, ses chapitres sortent au fil de l’eau.', 'yume-core' ) ),
		'complet'    => array( __( 'Quand l’équipe coche « Tome complet ».', 'yume-core' ), __( 'Tous les chapitres sont en ligne ; les liens PDF et EPUB s’ajoutent.', 'yume-core' ) ),
	);
	$html  = '<section class="yn-card yn-team__ajout" aria-labelledby="yn-parutions-titre"><h3 class="yn-label" id="yn-parutions-titre">' . esc_html__( 'Parution : l’état suit les chapitres, rien à choisir', 'yume-core' ) . '</h3><ul class="yn-parutions">';
	foreach ( $etats as $etat => $textes ) {
		$html .= '<li class="yn-parutions__etat">' . pastille_parution( $etat ) . '<span><strong>' . esc_html( $textes[0] ) . '</strong> ' . esc_html( $textes[1] ) . '</span></li>';
	}
	return $html . '</ul></section>';
}

/*
 * -----------------------------------------------------------------------------
 * Nouveau tome
 * -----------------------------------------------------------------------------
 */

/**
 * Formulaire « Nouveau tome » (admin-post.php, action yume_planning_ajout).
 *
 * @param array      $membres   Membres (ID => pseudo).
 * @param array|null $retour    Retour du dernier envoi (message, saisie à reprendre).
 * @param int        $oeuvre_id Œuvre présélectionnée.
 * @param string     $depuis    Page d'origine (origines_nouveau_tome()).
 */
function formulaire_nouveau_tome( array $membres, ?array $retour, int $oeuvre_id = 0, string $depuis = '' ): string {
	$pour_moi = $retour && 'yn-nouveau-tome-form' === ( $retour['cible'] ?? '' );
	$s        = $pour_moi && is_array( $retour['saisie'] ?? null ) ? $retour['saisie'] : array();
	$val      = static function ( string $cle, string $defaut = '' ) use ( $s ): string {
		return is_scalar( $s[ $cle ] ?? null ) ? (string) $s[ $cle ] : $defaut;
	};
	$oeuvres  = choix_oeuvres( __( '— Choisir une œuvre —', 'yume-core' ) );
	$choisie  = $val( 'oeuvre_id', $oeuvre_id ? (string) $oeuvre_id : '' );
	$choisie  = isset( $oeuvres[ $choisie ] ) ? $choisie : '';
	$depart   = yume_etapes();
	unset( $depart['publie'] );
	$rythme = is_array( $s['rythme'] ?? null ) ? $s['rythme'] : array();

	$html  = '<form class="yn-fiche" id="yn-nouveau-tome-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" aria-label="' . esc_attr__( 'Nouveau tome', 'yume-core' ) . '">';
	$html .= '<input type="hidden" name="action" value="yume_planning_ajout">' . wp_nonce_field( 'yume_planning_ajout', '_yume_nonce', true, false );
	$html .= '<div class="yn-fiche__principal">' . zone_retour( $pour_moi ? $retour : null );

	// Le tome.
	$html .= '<section class="yn-card yn-team__ajout" aria-labelledby="yn-nt-tome"><div class="yn-team__section-tete"><h3 class="yn-label" id="yn-nt-tome">' . esc_html__( 'Le tome', 'yume-core' ) . '</h3>';
	if ( current_user_can( CAPACITE_OEUVRES ) ) {
		$html .= '<p class="yn-muted">' . esc_html__( 'L’œuvre n’existe pas encore ?', 'yume-core' ) . ' <a href="' . esc_url( url_vue_equipe( 'oeuvres' ) . '#yn-nouvelle-oeuvre-section' ) . '">' . esc_html__( 'Créer une nouvelle œuvre', 'yume-core' ) . '</a></p>';
	}
	$html .= '</div><div class="yn-team__grille">';
	$html .= champ_select( 'yn-nt-oeuvre', 'oeuvre_id', __( 'Œuvre', 'yume-core' ), $oeuvres, $choisie, array( 'required' => true ) );
	$html .= champ_select( 'yn-nt-nature', 'nature', __( 'Nature', 'yume-core' ), yume_natures_tome(), $val( 'nature', 'tome' ) );
	$html .= champ_saisie(
		'yn-nt-numero',
		'numero',
		__( 'Numéro', 'yume-core' ),
		$val( 'numero' ),
		'number',
		array(
			'min'  => 0,
			'step' => 'any',
		)
	);
	$html .= champ_saisie( 'yn-nt-titre', 'titre', __( 'Titre (facultatif)', 'yume-core' ), $val( 'titre' ), 'text', array( 'maxlength' => 150 ) );
	$html .= champ_select( 'yn-nt-etape', 'etape', __( 'Étape de départ', 'yume-core' ), $depart, $val( 'etape', 'a_faire' ) );
	$html .= '</div></section>';

	// Équipe et calendrier.
	$html .= '<section class="yn-card yn-team__ajout" aria-labelledby="yn-nt-equipe"><h3 class="yn-label" id="yn-nt-equipe">' . esc_html__( 'Équipe et calendrier', 'yume-core' ) . '</h3><div class="yn-team__grille">';
	$html .= champs_responsables( 'yn-nt', $membres, is_array( $s['responsables'] ?? null ) ? $s['responsables'] : array() );
	$html .= champ_saisie( 'yn-nt-date', 'date_cible', __( 'Date cible du tome', 'yume-core' ), $val( 'date_cible' ), 'date' );
	$html .= champs_rythme( 'yn-nt', $val( 'chapitres_prevus' ), (string) ( $rythme['jour'] ?? '' ), (string) ( $rythme['heure'] ?? '' ) );
	$html .= '</div><p class="yn-muted">' . esc_html__( 'Facultatifs : le nombre de chapitres prévus (pour « 3 chapitres sur 12 en ligne ») et le rythme (pour proposer la date du chapitre suivant). « Libre » : aucune date proposée.', 'yume-core' ) . '</p></section>';

	$html .= encadre_parutions() . '</div>';

	// Colonne : origine, ce qui sera créé, tomes de l'œuvre, boutons.
	$html     .= '<div class="yn-fiche__cote">';
	$origines  = origines_nouveau_tome();
	$depuis    = isset( $origines[ $depuis ] ) ? $depuis : 'tomes';
	$oeuvre_id = (int) $choisie;
	$html     .= '<section class="yn-card yn-team__carte" aria-labelledby="yn-nt-origine"><h3 class="yn-label" id="yn-nt-origine">' . esc_html__( 'Ouvert depuis', 'yume-core' ) . '</h3>';
	$html     .= '<p>' . esc_html( $origines[ $depuis ][0] ) . '</p><p><a href="' . esc_url( url_origine_nouveau_tome( $depuis, $oeuvre_id ) ) . '">' . esc_html( $origines[ $depuis ][1] ) . '</a></p></section>';

	$html .= '<section class="yn-card yn-team__carte" aria-labelledby="yn-nt-cree"><h3 class="yn-label" id="yn-nt-cree">' . esc_html__( 'Ce qui sera créé', 'yume-core' ) . '</h3><ul class="yn-fiche__liste">';
	$html .= '<li><span class="yn-fiche__coche" aria-hidden="true">✓</span><span>' . esc_html__( 'Le tome, en brouillon, sans chapitre ni fichier.', 'yume-core' ) . '</span></li>';
	$html .= '<li><span class="yn-fiche__coche" aria-hidden="true">✓</span><span>' . esc_html__( 'Sa ligne au planning : étape de départ, responsables, date cible.', 'yume-core' ) . '</span></li>';
	$html .= '<li><span class="yn-fiche__coche yn-fiche__coche--non" aria-hidden="true">–</span><span class="yn-muted">' . esc_html__( 'Aucune annonce, aucun e-mail : rien n’est encore lisible.', 'yume-core' ) . '</span></li>';
	$html .= '</ul></section>';

	if ( $oeuvre_id ) {
		$tomes = yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) );
		// « Tomes de l'œuvre » puis le titre : « Tomes de Les Lanternes… » serait incorrect.
		$html .= '<section class="yn-card yn-team__carte" aria-labelledby="yn-nt-tomes"><h3 class="yn-label" id="yn-nt-tomes">' . esc_html__( 'Tomes de l’œuvre', 'yume-core' ) . '</h3><p class="yn-muted">' . esc_html( titre_brut( $oeuvre_id ) ) . '</p>';
		if ( $tomes ) {
			$html .= '<ul class="yn-fiche__tomes">';
			foreach ( $tomes as $tome ) {
				$libelle = yume_libelle_tome( (int) $tome->ID );
				$html   .= '<li>' . ( current_user_can( 'edit_post', $tome->ID ) ? '<a href="' . esc_url( url_modifier_tome( (int) $tome->ID ) ) . '">' . esc_html( $libelle ) . '</a>' : esc_html( $libelle ) ) . pastille_parution( yume_parution_tome( (int) $tome->ID ) ) . '</li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p class="yn-muted">' . esc_html__( 'Aucun tome pour le moment.', 'yume-core' ) . '</p>';
		}
		$html .= '<p class="yn-muted">' . esc_html__( 'Un tome de même nature et de même numéro existe déjà ? Sa fiche s’ouvre au lieu d’en créer un second.', 'yume-core' ) . '</p></section>';
	}

	$html .= '<p class="yn-fiche__boutons">';
	if ( current_user_can( 'yume_publier' ) ) {
		$html .= '<button type="submit" class="yn-btn yn-btn--primary" name="suite" value="chapitre">' . esc_html__( 'Créer le tome et ajouter un chapitre', 'yume-core' ) . '</button>';
		$html .= '<button type="submit" class="yn-btn" name="suite" value="fiche">' . esc_html__( 'Créer le tome', 'yume-core' ) . '</button>';
	} else {
		$html .= '<button type="submit" class="yn-btn yn-btn--primary" name="suite" value="fiche">' . esc_html__( 'Créer le tome', 'yume-core' ) . '</button>';
	}
	return $html . '</p></div></form>';
}

/**
 * Sous-vue « Nouveau tome » (?vue=tomes&nouveau=1).
 */
function rendu_nouveau_tome(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--tomes yn-team--nouveau-tome' );
	$html .= navigation_equipe( 'tomes' );
	$html .= '<div class="yn-team__principal">';
	if ( ! current_user_can( 'yume_maj_planning_tous' ) ) {
		return acces_refuse_vue( $html, __( 'Nouveau tome', 'yume-core' ), __( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent ajouter un tome au planning.', 'yume-core' ) );
	}
	$oeuvre = get_entier( 'oeuvre' );
	$oeuvre = $oeuvre ? $oeuvre : get_entier( 'oeuvre_ajout' );
	$oeuvre = $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) && 'trash' !== get_post_status( $oeuvre ) ? $oeuvre : 0;
	$retour = retour_formulaire( get_current_user_id() );

	$html .= tete_vue( __( 'Nouveau tome', 'yume-core' ), '' );
	$html .= '<p class="yn-muted">' . esc_html__( 'Le tome est ajouté au planning, sans fichier. Ses chapitres arrivent ensuite avec « Ajouter des chapitres » : un chapitre, plusieurs ou le tome entier.', 'yume-core' ) . '</p>';
	$html .= '<section class="yn-team__section" id="yn-nouveau-tome" aria-label="' . esc_attr__( 'Nouveau tome', 'yume-core' ) . '">';
	$html .= formulaire_nouveau_tome( membres_equipe(), $retour, $oeuvre, get_cle( 'depuis' ) ) . '</section>';
	return $html . '</div></div>';
}

/**
 * Adresse où mène le formulaire « Nouveau tome » après son envoi : formulaire de publication
 * (« Créer le tome et ajouter un chapitre »), fiche du tome créé ou du tome existant, ou retour
 * au formulaire en cas d'erreur.
 *
 * @param array $retour Retour (traiter_formulaire_ajout()).
 */
function adresse_apres_ajout( array $retour ): string {
	$tome_id = (int) ( $retour['tome_id'] ?? 0 );
	if ( 'ok' === $retour['type'] && $tome_id ) {
		return 'chapitre' === ( $retour['suite'] ?? '' ) && current_user_can( 'yume_publier' ) && '' !== yume_url_page( 'publier' )
			? url_publier_tome( $tome_id )
			: url_modifier_tome( $tome_id ) . '#yn-tome-fiche';
	}
	if ( $tome_id ) {
		// Tome déjà existant : sa fiche.
		return url_modifier_tome( $tome_id ) . '#yn-tome-fiche';
	}
	$oeuvre = (int) ( $retour['saisie']['oeuvre_id'] ?? 0 );
	return url_nouveau_tome( $oeuvre ) . '#yn-nouveau-tome-form';
}

/*
 * -----------------------------------------------------------------------------
 * Modifier le tome : saisie et enregistrement
 * -----------------------------------------------------------------------------
 */

/**
 * Numéro d'un tome à afficher dans un champ nombre (« 9 », « 26.5 »), ou ''.
 *
 * @param mixed $numero Méta yume_numero.
 */
function numero_champ( $numero ): string {
	return is_numeric( $numero ) ? rtrim( rtrim( number_format( (float) $numero, 3, '.', '' ), '0' ), '.' ) : '';
}

/**
 * Valeurs d'un tome existant, au format de saisie_tome() (formulaire « Modifier le tome »).
 *
 * @param int $id Tome.
 * @return array<string,mixed>
 */
function valeurs_tome( int $id ): array {
	$meta    = static function ( string $cle ) use ( $id ) {
		return get_post_meta( $id, $cle, true );
	};
	$rythme  = $meta( 'yume_rythme' );
	$credits = $meta( 'yume_credits' );
	$prevus  = (int) $meta( 'yume_chapitres_prevus' );
	return array(
		'oeuvre_id'        => (string) (int) $meta( 'yume_oeuvre_id' ),
		'nature'           => '' !== (string) $meta( 'yume_nature' ) ? (string) $meta( 'yume_nature' ) : 'tome',
		'numero'           => numero_champ( $meta( 'yume_numero' ) ),
		'titre'            => sous_titre_tome( $id ),
		'responsables'     => norm_responsables( $meta( 'yume_responsables' ) ),
		'date_cible'       => (string) $meta( 'yume_date_cible' ),
		'chapitres_prevus' => $prevus > 0 ? (string) $prevus : '',
		'rythme'           => is_array( $rythme ) ? $rythme : array(),
		'credits'          => \Yume\Core\Core\san_trio_textes( is_array( $credits ) ? $credits : array() ),
		'complet'          => 'complet' === (string) $meta( 'yume_parution' ),
		'lien_pdf'         => (string) $meta( 'yume_lien_pdf' ),
		'lien_epub'        => (string) $meta( 'yume_lien_epub' ),
		'cadrage'          => yume_cadrage_couverture( (int) get_post_thumbnail_id( $id ) ),
	);
}

/**
 * Saisie du formulaire « Modifier le tome », nettoyée (sans le fichier de couverture).
 *
 * @param array $post Données POST (brutes, avec slashes).
 * @return array<string,mixed>
 */
function saisie_tome( array $post ): array {
	$texte   = static function ( string $cle, int $max = 200 ) use ( $post ): string {
		$v = champ_post( $post, $cle );
		return is_scalar( $v ) ? mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max ) : '';
	};
	$resp    = champ_post( $post, 'responsables' );
	$propres = array();
	if ( is_array( $resp ) ) {
		foreach ( ETAPES_TRAVAIL as $e ) {
			if ( isset( $resp[ $e ] ) && is_scalar( $resp[ $e ] ) ) {
				$propres[ $e ] = absint( $resp[ $e ] );
			}
		}
	}
	return array(
		'oeuvre_id'        => (string) absint( $texte( 'oeuvre_id', 20 ) ),
		'nature'           => sanitize_key( $texte( 'nature', 20 ) ),
		'numero'           => $texte( 'numero', 20 ),
		'titre'            => $texte( 'titre', 150 ),
		'responsables'     => is_array( $resp ) ? $propres : null,
		'date_cible'       => $texte( 'date_cible', 10 ),
		'chapitres_prevus' => $texte( 'chapitres_prevus', 10 ),
		'rythme'           => array(
			'jour'  => sanitize_key( $texte( 'rythme_jour', 20 ) ),
			'heure' => $texte( 'rythme_heure', 5 ),
		),
		'credits'          => \Yume\Core\Core\san_trio_textes( champ_post( $post, 'credits' ) ),
		'complet'          => '1' === $texte( 'complet', 1 ),
		'lien_pdf'         => $texte( 'lien_pdf', 2000 ),
		'lien_epub'        => $texte( 'lien_epub', 2000 ),
		'cadrage'          => cadrage_saisi( $post ),
	);
}

/**
 * Lien de téléchargement saisi : adresse http(s) valide ou vide.
 *
 * @param string $saisi   Texte saisi.
 * @param string $libelle PDF ou EPUB.
 * @return string|\WP_Error
 */
function lien_tome_saisi( string $saisi, string $libelle ) {
	if ( '' === $saisi ) {
		return '';
	}
	$url = \Yume\Core\Core\san_url( $saisi );
	if ( '' === $url || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
		/* translators: %s : PDF ou EPUB */
		return erreur( 'yume_lien_invalide', sprintf( __( 'Lien %s invalide : indiquez une adresse complète (https://…).', 'yume-core' ), $libelle ), 400 );
	}
	return $url;
}

/**
 * Modifie un tome depuis l'espace équipe (statut de publication et adresse inchangés).
 *
 * Champs du tome (œuvre, nature, numéro, titre : titre du contenu reconstruit s'ils changent),
 * planning (responsables, date cible : mettre_a_jour(), journalisé), chapitres prévus, rythme,
 * crédits, « Tome complet » (méta yume_parution ; décochée : « en_cours » pour un tome en ligne,
 * vide sinon), liens PDF et EPUB, couverture et cadrage. N'annonce rien.
 *
 * @param int        $id         Tome.
 * @param array      $saisie     Saisie nettoyée (saisie_tome()).
 * @param array|null $couverture Entrée de $_FILES d'une nouvelle couverture (facultative).
 * @param int        $user_id    Utilisateur.
 * @return array{id:int,avertissements:string[],changements:string[]}|\WP_Error
 */
function modifier_tome( int $id, array $saisie, ?array $couverture, int $user_id ) {
	$saisie = array_merge( saisie_tome( array() ), $saisie );
	$post   = get_post( $id );
	if ( ! $post || 'yume_tome' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return erreur( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), 404 );
	}
	if ( ! user_can( $user_id, 'yume_publier' ) || ! user_can( $user_id, 'edit_post', $id ) ) {
		return erreur( 'yume_tome_interdit', __( 'Votre rôle ne permet pas de modifier ce tome.', 'yume-core' ), 403 );
	}
	$identite = valider_identite_tome( $saisie );
	if ( is_wp_error( $identite ) ) {
		return $identite;
	}
	$prevus = valider_chapitres_prevus( $saisie['chapitres_prevus'] );
	if ( is_wp_error( $prevus ) ) {
		return $prevus;
	}
	$rythme = valider_rythme( $saisie['rythme'] );
	if ( is_wp_error( $rythme ) ) {
		return $rythme;
	}
	$liens = array();
	foreach ( array(
		'yume_lien_pdf'  => array( 'lien_pdf', 'PDF' ),
		'yume_lien_epub' => array( 'lien_epub', 'EPUB' ),
	) as $cle => $champ ) {
		$liens[ $cle ] = lien_tome_saisi( (string) $saisie[ $champ[0] ], $champ[1] );
		if ( is_wp_error( $liens[ $cle ] ) ) {
			return $liens[ $cle ];
		}
	}
	$double = tome_en_double( $identite['oeuvre_id'], $identite['nature'], $identite['numero'], $identite['titre'], $id );
	if ( $double ) {
		return erreur(
			'yume_tome_existe',
			/* translators: %s : tome */
			sprintf( __( '%s existe déjà : choisissez une autre nature ou un autre numéro.', 'yume-core' ), cible_journal( $double ) ),
			409,
			array( 'tome_id' => $double )
		);
	}
	$fichier = couverture_saisie( $couverture );
	if ( is_wp_error( $fichier ) ) {
		return $fichier;
	}

	// Planning d'abord : il valide tout avant d'écrire (droits, date, responsables).
	$planning = array( 'date_cible' => $saisie['date_cible'] );
	if ( is_array( $saisie['responsables'] ) && user_can( $user_id, 'yume_maj_planning_tous' ) ) {
		// Seulement les responsables changés : un ancien membre resté responsable ne bloque rien.
		$actuels = norm_responsables( get_post_meta( $id, 'yume_responsables', true ) );
		$changes = array_diff_assoc( array_map( 'intval', $saisie['responsables'] ), $actuels );
		if ( $changes ) {
			$planning['responsables'] = $changes;
		}
	}
	$maj = mettre_a_jour( $id, $planning, $user_id );
	if ( is_wp_error( $maj ) ) {
		return $maj;
	}
	$changements = array_keys( $maj['changements'] );

	// Identité : titre du contenu reconstruit seulement si elle change (titre migré conservé sinon).
	$avant = array(
		'oeuvre_id' => (int) get_post_meta( $id, 'yume_oeuvre_id', true ),
		'nature'    => '' !== (string) get_post_meta( $id, 'yume_nature', true ) ? (string) get_post_meta( $id, 'yume_nature', true ) : 'tome',
		'numero'    => is_numeric( get_post_meta( $id, 'yume_numero', true ) ) ? round( (float) get_post_meta( $id, 'yume_numero', true ), 3 ) : null,
		'titre'     => sous_titre_tome( $id ),
	);
	if ( $avant !== $identite ) {
		update_post_meta( $id, 'yume_oeuvre_id', $identite['oeuvre_id'] );
		update_post_meta( $id, 'yume_nature', $identite['nature'] );
		if ( null === $identite['numero'] ) {
			delete_post_meta( $id, 'yume_numero' );
		} else {
			update_post_meta( $id, 'yume_numero', $identite['numero'] );
		}
		$maj_post = wp_update_post(
			wp_slash(
				array(
					'ID'         => $id,
					'post_title' => titre_tome_planning( $identite['oeuvre_id'], $identite['nature'], $identite['numero'], $identite['titre'] ),
				)
			),
			true
		);
		if ( is_wp_error( $maj_post ) ) {
			return $maj_post;
		}
		$changements[] = 'identite';
	}

	// Parution : « Tome complet » cochée → complet ; décochée → en cours (tome en ligne) ou vide.
	$parution = (string) get_post_meta( $id, 'yume_parution', true );
	if ( $saisie['complet'] ) {
		$nouvelle = 'complet';
	} elseif ( 'complet' === $parution ) {
		$nouvelle = 'publish' === $post->post_status ? 'en_cours' : '';
	} else {
		$nouvelle = $parution;
	}
	// Passage à « complet » d'un tome en ligne : par le service de publication (planning « publié »
	// à 100 %, action yume_tome_complet), sans annonce depuis cette vue.
	$par_service = 'complet' === $nouvelle && 'complet' !== $parution && 'publish' === $post->post_status && method_exists( '\Yume\Core\Publication\Service', 'marquer_complet' );
	$credits     = \Yume\Core\Core\san_trio_textes( $saisie['credits'] );
	foreach (
		array(
			'yume_chapitres_prevus' => $prevus > 0 ? $prevus : '',
			'yume_rythme'           => is_array( $rythme ) ? $rythme : '',
			'yume_credits'          => array_filter( $credits ) ? $credits : '',
			'yume_lien_pdf'         => $liens['yume_lien_pdf'],
			'yume_lien_epub'        => $liens['yume_lien_epub'],
			'yume_parution'         => $par_service ? $parution : $nouvelle,
		) as $cle => $valeur
	) {
		$actuelle = get_post_meta( $id, $cle, true );
		if ( '' === $valeur ) {
			if ( metadata_exists( 'post', $id, $cle ) && '' !== $actuelle && array() !== $actuelle ) {
				$changements[] = $cle;
			}
			delete_post_meta( $id, $cle );
			continue;
		}
		if ( $actuelle != $valeur ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- méta lue en texte (« 12 » contre 12).
			update_post_meta( $id, $cle, wp_slash( $valeur ) );
			$changements[] = $cle;
		}
	}

	if ( $par_service ) {
		$complet = \Yume\Core\Publication\Service::marquer_complet(
			$id,
			array(
				'lien_pdf'  => $liens['yume_lien_pdf'],
				'lien_epub' => $liens['yume_lien_epub'],
			),
			false
		);
		if ( is_wp_error( $complet ) ) {
			return $complet;
		}
		$changements[] = 'yume_parution';
	}

	$avert = array();
	if ( $fichier ) {
		/* translators: %s : tome */
		$cid = Medias::couverture( $fichier, $id, sprintf( __( 'Couverture — %s', 'yume-core' ), titre_brut( $id ) ) );
		if ( is_wp_error( $cid ) ) {
			$avert[] = $cid->get_error_message();
		} else {
			set_post_thumbnail( $id, (int) $cid );
			$changements[] = 'couverture';
		}
	}
	// Cadrage : seulement la couverture propre au tome (jamais celle de l'œuvre, reprise à défaut).
	$propre = (int) get_post_thumbnail_id( $id );
	if ( $propre && null !== $saisie['cadrage'] ) {
		yume_enregistrer_cadrage( $propre, $saisie['cadrage'] );
	}

	/**
	 * Un tome vient d'être modifié depuis l'espace équipe (« Modifier le tome »).
	 *
	 * @param int   $id      Tome.
	 * @param array $saisie  Saisie nettoyée.
	 * @param int   $user_id Utilisateur.
	 */
	do_action( 'yume_tome_modifie_equipe', $id, $saisie, $user_id );

	return array(
		'id'             => $id,
		'avertissements' => $avert,
		'changements'    => array_values( array_unique( $changements ) ),
	);
}

/**
 * Traite le formulaire « Modifier le tome ».
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param array $files   Fichiers ($_FILES).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int,saisie?:array}
 */
function traiter_formulaire_tome( array $post, array $files, int $user_id ): array {
	$id     = isset( $post['tome_id'] ) && is_scalar( $post['tome_id'] ) ? absint( $post['tome_id'] ) : 0;
	$nonce  = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$erreur = array(
		'cible'   => 'yn-tome-form',
		'type'    => 'erreur',
		'tome_id' => $id,
	);
	if ( ! $id || ! wp_verify_nonce( $nonce, 'yume_tome_modifier_' . $id ) ) {
		return $erreur + array( 'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	$saisie     = saisie_tome( $post );
	$couverture = isset( $files['couverture'] ) && is_array( $files['couverture'] ) ? $files['couverture'] : null;
	$resultat   = modifier_tome( $id, $saisie, $couverture, $user_id );
	if ( is_wp_error( $resultat ) ) {
		return $erreur + array(
			'message' => $resultat->get_error_message(),
			'saisie'  => $saisie,
		);
	}
	$message = $resultat['changements']
		/* translators: %s : tome */
		? sprintf( __( '%s est enregistré.', 'yume-core' ), cible_journal( $id ) )
		: __( 'Aucun changement à enregistrer.', 'yume-core' );
	if ( $resultat['avertissements'] ) {
		$message .= ' ' . implode( ' ', $resultat['avertissements'] );
	}
	return array(
		'cible'   => 'yn-tome-form',
		'type'    => 'ok',
		'message' => $message,
		'tome_id' => $id,
	);
}

/**
 * Formulaire « Modifier le tome » (admin-post.php, action yume_tome_modifier).
 */
function admin_post_modifier_tome(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_tome().
	$retour = traiter_formulaire_tome( $_POST, $_FILES, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_vue_tome( $retour );
}
add_action( 'admin_post_yume_tome_modifier', __NAMESPACE__ . '\\admin_post_modifier_tome' );
add_action( 'admin_post_nopriv_yume_tome_modifier', __NAMESPACE__ . '\\admin_post_anonyme' );

/**
 * Redirige vers la fiche du tome après un formulaire (vers « Tous les tomes » sans tome).
 *
 * @param array $retour Retour.
 */
function rediriger_vue_tome( array $retour ): void {
	$tome_id = (int) ( $retour['tome_id'] ?? 0 );
	$adresse = $tome_id && 'yume_tome' === get_post_type( $tome_id ) ? url_modifier_tome( $tome_id ) . '#' . $retour['cible'] : url_vue_equipe( 'tomes' );
	wp_safe_redirect( $adresse );
	exit;
}

/*
 * -----------------------------------------------------------------------------
 * Chapitres du tome : publier maintenant, changer la date, retirer
 * -----------------------------------------------------------------------------
 */

/**
 * Date de sortie saisie (champ date-heure, heure du site) : « AAAA-MM-JJTHH:MM ».
 *
 * @param mixed $saisi Valeur saisie.
 */
function date_chapitre_saisie( $saisi ): ?\DateTimeImmutable {
	$saisi = is_scalar( $saisi ) ? trim( (string) $saisi ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $saisi ) ) {
		return null;
	}
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', str_replace( 'T', ' ', $saisi ), wp_timezone() );
	return $date && $date->format( 'Y-m-d H:i' ) === str_replace( 'T', ' ', $saisi ) ? $date : null;
}

/**
 * Agit sur un chapitre depuis la fiche de son tome.
 *
 * - « publier » : chapitre programmé ou en brouillon mis en ligne tout de suite (tome déjà en
 *   ligne ; la sortie est annoncée comme celle d'un chapitre programmé, événement
 *   yume_chapitre_publie émis par core) ;
 * - « date » : chapitre programmé ou en brouillon programmé à la date saisie (à venir) ;
 * - « retirer » : chapitre en ligne ou programmé remis en brouillon (adresse et commentaires
 *   conservés), confirmation obligatoire ; marqué retiré pour que le formulaire de publication
 *   ne le republie pas d'office.
 *
 * Droits : edit_post sur le chapitre et publish_yume_chapitres.
 *
 * @param int    $chapitre_id Chapitre.
 * @param string $op          publier | date | retirer.
 * @param array  $donnees     date (date-heure saisie), confirmer ('1').
 * @param int    $user_id     Utilisateur.
 * @return array{message:string,tome_id:int}|\WP_Error
 */
function agir_sur_chapitre( int $chapitre_id, string $op, array $donnees, int $user_id ) {
	$chap = get_post( $chapitre_id );
	if ( ! $chap || 'yume_chapitre' !== $chap->post_type || ! in_array( $chap->post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
		return erreur( 'yume_chapitre_introuvable', __( 'Chapitre introuvable.', 'yume-core' ), 404 );
	}
	if ( ! user_can( $user_id, 'edit_post', $chapitre_id ) || ! user_can( $user_id, 'publish_yume_chapitres' ) ) {
		return erreur( 'yume_chapitre_interdit', __( 'Votre rôle ne permet pas de publier ni de retirer ce chapitre.', 'yume-core' ), 403 );
	}
	$tome_id = (int) get_post_meta( $chapitre_id, 'yume_tome_id', true );
	$libelle = yume_libelle_chapitre( $chapitre_id );
	$publie  = in_array( $chap->post_status, array( 'publish', 'private' ), true );
	$hors    = __( 'Le tome n’est pas encore en ligne : sa première sortie se fait par « Ajouter des chapitres » (annonce comprise).', 'yume-core' );

	if ( 'publier' === $op || 'date' === $op ) {
		if ( $publie ) {
			/* translators: %s : chapitre */
			return erreur( 'yume_chapitre_en_ligne', sprintf( __( '« %s » est déjà en ligne.', 'yume-core' ), $libelle ), 409 );
		}
		if ( ! $tome_id || 'publish' !== get_post_status( $tome_id ) ) {
			return erreur( 'yume_tome_hors_ligne', $hors, 409 );
		}
		if ( 'publier' === $op ) {
			$local = current_time( 'mysql' );
			$gmt   = current_time( 'mysql', true );
			$etat  = 'publish';
		} else {
			$date = date_chapitre_saisie( $donnees['date'] ?? '' );
			if ( ! $date ) {
				return erreur( 'yume_date_invalide', __( 'Indiquez la date et l’heure de sortie du chapitre.', 'yume-core' ), 400 );
			}
			if ( $date->getTimestamp() <= time() + MINUTE_IN_SECONDS ) {
				return erreur( 'yume_date_passee', __( 'Choisissez une date à venir, ou « Publier maintenant ».', 'yume-core' ), 400 );
			}
			$local = $date->format( 'Y-m-d H:i:s' );
			$gmt   = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$etat  = 'future';
		}
		delete_post_meta( $chapitre_id, META_CHAPITRE_RETIRE );
		$ok = wp_update_post(
			array(
				'ID'            => $chapitre_id,
				'post_status'   => $etat,
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array(
			'message' => 'publish' === $etat
				/* translators: %s : chapitre */
				? sprintf( __( '« %s » est en ligne.', 'yume-core' ), $libelle )
				/* translators: 1: chapitre, 2: date */
				: sprintf( __( '« %1$s » est programmé le %2$s.', 'yume-core' ), $libelle, format_fr( ts_gmt( $gmt ), 'j M Y à H:i' ) ),
			'tome_id' => $tome_id,
		);
	}

	if ( 'retirer' === $op ) {
		if ( ! $publie && 'future' !== $chap->post_status ) {
			/* translators: %s : chapitre */
			return erreur( 'yume_chapitre_hors_ligne', sprintf( __( '« %s » n’est ni en ligne ni programmé.', 'yume-core' ), $libelle ), 409 );
		}
		if ( '1' !== ( is_scalar( $donnees['confirmer'] ?? null ) ? (string) $donnees['confirmer'] : '' ) ) {
			return erreur( 'yume_confirmation', __( 'Confirmez le retrait du chapitre.', 'yume-core' ), 400 );
		}
		$ok = wp_update_post(
			array(
				'ID'          => $chapitre_id,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		update_post_meta( $chapitre_id, META_CHAPITRE_RETIRE, 1 );
		return array(
			/* translators: %s : chapitre */
			'message' => sprintf( __( '« %s » est retiré : il repasse en brouillon, son adresse et ses commentaires sont conservés.', 'yume-core' ), $libelle ),
			'tome_id' => $tome_id,
		);
	}

	return erreur( 'yume_action_inconnue', __( 'Action inconnue.', 'yume-core' ), 400 );
}

/**
 * Traite un formulaire d'action sur un chapitre (fiche du tome).
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int}
 */
function traiter_action_chapitre( array $post, int $user_id ): array {
	$id    = isset( $post['chapitre_id'] ) && is_scalar( $post['chapitre_id'] ) ? absint( $post['chapitre_id'] ) : 0;
	$op    = is_scalar( $post['op'] ?? null ) ? sanitize_key( (string) $post['op'] ) : '';
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$tome  = $id && 'yume_chapitre' === get_post_type( $id ) ? (int) get_post_meta( $id, 'yume_tome_id', true ) : 0;
	$base  = array(
		'cible'   => $tome ? 'yn-chapitre-' . $id : 'yn-tome-chapitres',
		'tome_id' => $tome,
	);
	if ( ! $id || ! wp_verify_nonce( $nonce, 'yume_tome_chapitre_' . $id ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		);
	}
	$resultat = agir_sur_chapitre(
		$id,
		$op,
		array(
			'date'      => champ_post( $post, 'date' ),
			'confirmer' => champ_post( $post, 'confirmer' ),
		),
		$user_id
	);
	if ( is_wp_error( $resultat ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => $resultat->get_error_message(),
		);
	}
	return $base + array(
		'type'    => 'ok',
		'message' => $resultat['message'],
	);
}

/**
 * Action sur un chapitre (admin-post.php, action yume_tome_chapitre).
 */
function admin_post_chapitre_tome(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_action_chapitre().
	$retour = traiter_action_chapitre( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_vue_tome( $retour );
}
add_action( 'admin_post_yume_tome_chapitre', __NAMESPACE__ . '\\admin_post_chapitre_tome' );
add_action( 'admin_post_nopriv_yume_tome_chapitre', __NAMESPACE__ . '\\admin_post_anonyme' );

/*
 * -----------------------------------------------------------------------------
 * Modifier le tome : rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Valeur d'un champ date-heure (« AAAA-MM-JJTHH:MM », heure du site).
 *
 * @param int $ts Horodatage.
 */
function valeur_date_heure( int $ts ): string {
	return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
}

/**
 * Champs cachés d'une action sur un chapitre.
 *
 * @param int    $chapitre_id Chapitre.
 * @param string $op          Action.
 */
function champs_action_chapitre( int $chapitre_id, string $op ): string {
	$html  = '<input type="hidden" name="action" value="yume_tome_chapitre"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	$html .= '<input type="hidden" name="chapitre_id" value="' . $chapitre_id . '">';
	return $html . wp_nonce_field( 'yume_tome_chapitre_' . $chapitre_id, '_yume_nonce', true, false );
}

/**
 * Ligne d'un chapitre de la fiche du tome : repère, titre, temps de lecture, état, date et
 * actions permises.
 *
 * @param \WP_Post   $chap        Chapitre.
 * @param bool       $tome_publie Le tome est en ligne.
 * @param string     $proposee    Date proposée pour un brouillon (rythme), champ date-heure.
 * @param bool       $confirmer   Écran de confirmation du retrait.
 * @param array|null $retour      Retour du dernier envoi pour ce chapitre.
 */
function ligne_chapitre_tome( \WP_Post $chap, bool $tome_publie, string $proposee, bool $confirmer, ?array $retour ): string {
	$id      = (int) $chap->ID;
	$tome_id = (int) get_post_meta( $id, 'yume_tome_id', true );
	$libelle = yume_libelle_chapitre( $id );
	$sous    = trim( (string) get_post_meta( $id, 'yume_sous_titre', true ) );
	$minutes = (int) get_post_meta( $id, 'yume_temps_lecture', true );
	$nature  = (string) get_post_meta( $id, 'yume_nature', true );
	$numero  = get_post_meta( $id, 'yume_numero', true );
	$repere  = ( '' === $nature || 'chapitre' === $nature ) && is_numeric( $numero ) ? str_replace( '.', ',', numero_champ( $numero ) ) : mb_strtoupper( mb_substr( $libelle, 0, 1 ) );
	$publie  = in_array( $chap->post_status, array( 'publish', 'private' ), true );
	$nom     = '<span class="yn-visually-hidden"> : ' . esc_html( $libelle ) . '</span>';
	$peut    = current_user_can( 'edit_post', $id ) && current_user_can( 'publish_yume_chapitres' );

	if ( $publie ) {
		$etat = '<span class="yn-chip yn-chip--ok">' . esc_html__( 'En ligne', 'yume-core' ) . '</span>';
	} elseif ( 'future' === $chap->post_status ) {
		$etat = '<span class="yn-chip yn-chip--info">' . esc_html__( 'Programmé', 'yume-core' ) . '</span>';
	} else {
		$etat = '<span class="yn-chip">' . esc_html__( 'Brouillon', 'yume-core' ) . '</span>';
	}
	$ts    = ts_contenu( $chap, 'post_date' );
	$quand = ( $publie || 'future' === $chap->post_status ) && $ts ? format_fr( $ts, 'j M Y, H:i' ) : '';

	$html  = '<li class="yn-chapitres__ligne" id="yn-chapitre-' . $id . '">';
	$html .= '<span class="yn-chapitres__repere" aria-hidden="true">' . esc_html( $repere ) . '</span>';
	$html .= '<div class="yn-chapitres__infos"><p class="yn-chapitres__titre"><strong>' . esc_html( $libelle . ( '' !== $sous && 0 !== strcasecmp( $sous, $libelle ) ? ' : ' . $sous : '' ) ) . '</strong>';
	/* translators: %d : minutes de lecture */
	$html .= $minutes > 0 ? ' <span class="yn-muted">· ' . esc_html( sprintf( _n( '%d min', '%d min', $minutes, 'yume-core' ), $minutes ) ) . '</span>' : '';
	$html .= '</p><p class="yn-chapitres__etat">' . $etat . ( '' !== $quand ? ' <span class="yn-muted">' . esc_html( $quand ) . '</span>' : '' ) . '</p></div>';

	if ( $confirmer && $peut && ( $publie || 'future' === $chap->post_status ) ) {
		$html .= '<form class="yn-chapitres__confirmation" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_action_chapitre( $id, 'retirer' );
		$html .= '<input type="hidden" name="confirmer" value="1">';
		$html .= '<p><strong>' . esc_html(
			sprintf(
				/* translators: %s : chapitre */
				__( 'Retirer « %s » ?', 'yume-core' ),
				$libelle
			)
		) . '</strong> ' . esc_html__( 'Il repasse en brouillon : il n’est plus lisible ni programmé, son adresse et ses commentaires sont conservés. Rien n’est annoncé.', 'yume-core' ) . '</p>';
		$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--sm yn-team__retirer">' . esc_html__( 'Oui, retirer', 'yume-core' ) . $nom . '</button>';
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_modifier_tome( $tome_id ) . '#yn-chapitre-' . $id ) . '">' . esc_html__( 'Annuler', 'yume-core' ) . '</a></p></form>';
		return $html . '</li>';
	}

	$html .= '<div class="yn-chapitres__actions">';
	if ( $publie ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir', 'yume-core' ) . $nom . '</a>';
	} else {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_preview_post_link( $chap ) ) . '">' . esc_html__( 'Aperçu', 'yume-core' ) . $nom . '</a>';
	}
	if ( $peut && ! $publie && $tome_publie ) {
		$html  .= '<form class="yn-chapitres__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_action_chapitre( $id, 'publier' );
		$html  .= '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Publier maintenant', 'yume-core' ) . $nom . '</button></form>';
		$valeur = 'future' === $chap->post_status && $ts ? valeur_date_heure( $ts ) : $proposee;
		$champ  = 'yn-chapitre-' . $id . '-date';
		$html  .= '<form class="yn-chapitres__form yn-chapitres__date" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_action_chapitre( $id, 'date' );
		/* translators: %s : chapitre */
		$html .= '<label class="yn-visually-hidden" for="' . esc_attr( $champ ) . '">' . esc_html( sprintf( __( 'Date de sortie de « %s »', 'yume-core' ), $libelle ) ) . '</label>';
		$html .= '<input type="datetime-local" id="' . esc_attr( $champ ) . '" name="date" value="' . esc_attr( $valeur ) . '" min="' . esc_attr( valeur_date_heure( time() ) ) . '" required>';
		$html .= '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Changer la date', 'yume-core' ) . $nom . '</button></form>';
	}
	if ( $peut && ( $publie || 'future' === $chap->post_status ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_modifier_tome( $tome_id, array( 'retirer' => $id ) ) . '#yn-chapitre-' . $id ) . '">' . esc_html__( 'Retirer', 'yume-core' ) . $nom . '</a>';
	}
	$html .= '</div>';
	$html .= $retour ? '<div class="yn-chapitres__retour">' . zone_retour( $retour ) . '</div>' : '';
	return $html . '</li>';
}

/**
 * Section « Chapitres » de la fiche du tome : chapitres (tous statuts sauf corbeille et
 * versions en attente de remplacement), puis la ligne « À venir » quand le nombre de chapitres
 * prévus dépasse celui des chapitres existants (dates proposées selon le rythme).
 *
 * @param int      $tome_id Tome.
 * @param callable $pour    Retour destiné à une cible (string) : ?array.
 */
function section_chapitres_tome( int $tome_id, callable $pour ): string {
	$chapitres   = yume_get_chapitres( $tome_id, array( 'status' => 'any' ) );
	$tome_publie = 'publish' === get_post_status( $tome_id );
	$retirer     = get_entier( 'retirer' );

	// Rythme : prochaine date après le dernier chapitre en ligne ou programmé (et maintenant).
	$dernier    = time();
	$brouillons = 0;
	$numeros    = array( 0.0 );
	foreach ( $chapitres as $c ) {
		if ( in_array( $c->post_status, array( 'publish', 'future' ), true ) ) {
			$dernier = max( $dernier, ts_contenu( $c, 'post_date' ) );
		} else {
			++$brouillons;
		}
		$nature = (string) get_post_meta( $c->ID, 'yume_nature', true );
		$numero = get_post_meta( $c->ID, 'yume_numero', true );
		if ( ( '' === $nature || 'chapitre' === $nature ) && is_numeric( $numero ) ) {
			$numeros[] = (float) $numero;
		}
	}
	$prochaine = yume_prochaine_sortie_rythme( $tome_id, ( new \DateTimeImmutable( '@' . $dernier ) )->setTimezone( wp_timezone() ) );
	$proposee  = $prochaine ? $prochaine->format( 'Y-m-d\TH:i' ) : '';

	$html  = '<section class="yn-card yn-chapitres" id="yn-tome-chapitres" aria-labelledby="yn-tome-chapitres-titre"><div class="yn-team__section-tete yn-chapitres__tete">';
	$html .= '<h3 id="yn-tome-chapitres-titre">' . esc_html(
		/* translators: %d : nombre de chapitres */
		sprintf( _n( 'Chapitres (%d)', 'Chapitres (%d)', count( $chapitres ), 'yume-core' ), count( $chapitres ) )
	) . '</h3>';
	$html .= '<a href="' . esc_url( url_publier_tome( $tome_id ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a></div>';
	$html .= '<div class="yn-chapitres__note">' . zone_retour( $pour( 'yn-tome-chapitres' ) );
	if ( ! $chapitres ) {
		$html .= '<p class="yn-muted">' . esc_html__( 'Aucun chapitre pour le moment : « Ajouter des chapitres » reçoit un DOCX ou un EPUB avec un chapitre, plusieurs ou le tome entier.', 'yume-core' ) . '</p>';
	} elseif ( ! $tome_publie && count( $chapitres ) > 0 ) {
		$html .= '<p class="yn-muted">' . esc_html__( 'Le tome n’est pas encore en ligne : sa première sortie se fait par « Ajouter des chapitres » (annonce comprise). Ensuite, chaque chapitre se publie, se programme ou se retire ici.', 'yume-core' ) . '</p>';
	}
	$html .= '</div>';

	$prevus = (int) get_post_meta( $tome_id, 'yume_chapitres_prevus', true );
	$reste  = $prevus - count( $chapitres );
	if ( $chapitres || $reste > 0 ) {
		$html .= '<ul class="yn-chapitres__liste">';
		foreach ( $chapitres as $c ) {
			$html .= ligne_chapitre_tome( $c, $tome_publie, $proposee, $retirer === (int) $c->ID, $pour( 'yn-chapitre-' . (int) $c->ID ) );
		}
		if ( $reste > 0 ) {
			$debut = (int) floor( max( $numeros ) ) + 1;
			$fin   = $debut + $reste - 1;
			$dates = '';
			if ( $prochaine ) {
				$premiere = $prochaine->modify( '+' . ( 7 * $brouillons ) . ' days' );
				$derniere = $premiere->modify( '+' . ( 7 * ( $reste - 1 ) ) . ' days' );
				$dates    = sprintf(
					/* translators: %s : dates (« 18 oct. → 6 déc. ») */
					__( 'au rythme : %s', 'yume-core' ),
					format_fr( $premiere->getTimestamp(), 'j M' ) . ( $reste > 1 ? ' → ' . format_fr( $derniere->getTimestamp(), 'j M' ) : '' )
				);
			}
			$html .= '<li class="yn-chapitres__ligne yn-chapitres__ligne--a-venir"><span class="yn-chapitres__repere" aria-hidden="true">' . esc_html( $debut . ( $fin > $debut ? '–' . $fin : '' ) ) . '</span>';
			$html .= '<div class="yn-chapitres__infos"><p class="yn-chapitres__titre yn-muted">' . esc_html(
				sprintf(
					/* translators: 1: chapitres à venir, 2: chapitres prévus */
					_n( '%1$d chapitre pas encore déposé (sur %2$d prévus)', '%1$d chapitres pas encore déposés (sur %2$d prévus)', $reste, 'yume-core' ),
					$reste,
					$prevus
				)
			) . '</p><p class="yn-chapitres__etat"><span class="yn-chip">' . esc_html__( 'À venir', 'yume-core' ) . '</span>' . ( '' !== $dates ? ' <span class="yn-muted">' . esc_html( $dates ) . '</span>' : '' ) . '</p></div></li>';
		}
		$html .= '</ul>';
	}
	return $html . '</section>';
}

/**
 * Carte « Planning » de la fiche : étape, avancement, état, date cible, lien vers le planning.
 *
 * @param int $tome_id Tome.
 */
function carte_planning_tome( int $tome_id ): string {
	$l      = ligne_tome( $tome_id );
	$etape  = (string) $l['etape'];
	$etapes = yume_etapes();
	$html   = '<section class="yn-card yn-team__carte yn-fiche__planning" aria-labelledby="yn-tome-planning"><h3 class="yn-label" id="yn-tome-planning">' . esc_html__( 'Planning', 'yume-core' ) . '</h3>';
	$texte  = $etapes[ $etape ] ?? $etapes['a_faire'];
	if ( in_array( $etape, ETAPES_TRAVAIL, true ) ) {
		$texte .= ' · ' . pct( (int) ( $l['avancement'][ $etape ] ?? 0 ) );
	}
	$html .= '<p>' . esc_html__( 'Étape', 'yume-core' ) . ' <strong>' . esc_html( $texte ) . '</strong></p>';
	$html .= '<p>' . pastille_ligne( $l ) . '</p>';
	if ( '' !== (string) $l['date_cible'] ) {
		/* translators: %s : date cible */
		$html .= '<p class="yn-muted">' . esc_html( sprintf( __( 'Date cible : %s', 'yume-core' ), date_cible_lisible( (string) $l['date_cible'], true ) ) ) . '</p>';
	}
	return $html . '<p><a href="' . esc_url( url_vue_equipe( 'planning', array( 'tome' => $tome_id ) ) . '#yn-tome-' . $tome_id ) . '">' . esc_html__( 'Ouvrir dans le planning', 'yume-core' ) . '</a></p></section>';
}

/**
 * Formulaire « Modifier le tome » (admin-post.php, action yume_tome_modifier, multipart).
 *
 * @param int        $id     Tome.
 * @param array|null $retour Retour du dernier envoi.
 */
function formulaire_tome( int $id, ?array $retour ): string {
	$pour_moi = $retour && 'yn-tome-form' === ( $retour['cible'] ?? '' ) && (int) ( $retour['tome_id'] ?? 0 ) === $id;
	$s        = array_merge( valeurs_tome( $id ), $pour_moi && is_array( $retour['saisie'] ?? null ) ? $retour['saisie'] : array() );
	$val      = static function ( string $cle ) use ( $s ): string {
		return is_scalar( $s[ $cle ] ?? null ) ? (string) $s[ $cle ] : '';
	};
	$rythme   = is_array( $s['rythme'] ) ? $s['rythme'] : array();
	$credits  = is_array( $s['credits'] ) ? $s['credits'] : array();

	$html  = '<form class="yn-fiche" id="yn-tome-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" aria-label="' . esc_attr__( 'Modifier le tome', 'yume-core' ) . '">';
	$html .= '<input type="hidden" name="action" value="yume_tome_modifier"><input type="hidden" name="tome_id" value="' . $id . '">';
	$html .= wp_nonce_field( 'yume_tome_modifier_' . $id, '_yume_nonce', true, false );
	$html .= '<div class="yn-fiche__principal">' . zone_retour( $pour_moi ? $retour : null );

	// Le tome.
	$html .= '<section class="yn-card yn-team__ajout" aria-labelledby="yn-tome-identite"><h3 class="yn-label" id="yn-tome-identite">' . esc_html__( 'Le tome', 'yume-core' ) . '</h3><div class="yn-team__grille">';
	$html .= champ_select( 'yn-tome-oeuvre', 'oeuvre_id', __( 'Œuvre', 'yume-core' ), choix_oeuvres( __( '— Choisir une œuvre —', 'yume-core' ) ), $val( 'oeuvre_id' ), array( 'required' => true ) );
	$html .= champ_select( 'yn-tome-nature', 'nature', __( 'Nature', 'yume-core' ), yume_natures_tome(), $val( 'nature' ) );
	$html .= champ_saisie(
		'yn-tome-numero',
		'numero',
		__( 'Numéro', 'yume-core' ),
		str_replace( ',', '.', $val( 'numero' ) ),
		'number',
		array(
			'min'  => 0,
			'step' => 'any',
		)
	);
	$html .= champ_saisie( 'yn-tome-titre', 'titre', __( 'Titre (facultatif)', 'yume-core' ), $val( 'titre' ), 'text', array( 'maxlength' => 150 ) );
	$html .= '</div></section>';

	// Équipe et calendrier.
	$html .= '<section class="yn-card yn-team__ajout" aria-labelledby="yn-tome-equipe"><h3 class="yn-label" id="yn-tome-equipe">' . esc_html__( 'Équipe et calendrier', 'yume-core' ) . '</h3><div class="yn-team__grille">';
	if ( current_user_can( 'yume_maj_planning_tous' ) ) {
		$html .= champs_responsables( 'yn-tome', membres_equipe(), is_array( $s['responsables'] ) ? $s['responsables'] : array() );
	}
	$html .= champ_saisie( 'yn-tome-date', 'date_cible', __( 'Date cible du tome', 'yume-core' ), $val( 'date_cible' ), 'date' );
	$html .= champs_rythme( 'yn-tome', $val( 'chapitres_prevus' ), (string) ( $rythme['jour'] ?? '' ), (string) ( $rythme['heure'] ?? '' ) );
	$html .= '</div></section>';

	// Crédits.
	$html .= '<section class="yn-card yn-team__ajout" aria-labelledby="yn-tome-credits"><h3 class="yn-label" id="yn-tome-credits">' . esc_html__( 'Crédits affichés sur la page du tome', 'yume-core' ) . '</h3><div class="yn-team__grille">';
	foreach (
		array(
			'traduction' => __( 'Crédit traduction', 'yume-core' ),
			'relecture'  => __( 'Crédit relecture', 'yume-core' ),
			'edition'    => __( 'Crédit édition / couverture', 'yume-core' ),
		) as $etape => $libelle
	) {
		$html .= champ_saisie( 'yn-tome-credit-' . $etape, 'credits[' . $etape . ']', $libelle, (string) ( $credits[ $etape ] ?? '' ), 'text', array( 'maxlength' => 200 ) );
	}
	$html .= '</div></section></div>';

	// Colonne : couverture, tome complet, planning, enregistrer.
	$html    .= '<div class="yn-fiche__cote">';
	$propre   = (int) get_post_thumbnail_id( $id );
	$affichee = yume_get_cover_id( $id );
	$max      = Fichiers::taille_max_couverture();
	$html    .= '<section class="yn-card yn-team__carte yn-fiche__couverture" aria-labelledby="yn-tome-couverture"><h3 class="yn-label" id="yn-tome-couverture">' . esc_html__( 'Couverture', 'yume-core' ) . '</h3>';
	if ( $affichee && ! $propre ) {
		$html .= '<p class="yn-muted">' . esc_html__( 'Le tome n’a pas sa propre couverture : celle de l’œuvre est affichée.', 'yume-core' ) . '</p>';
	}
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-tome-fichier">' . esc_html( $propre ? __( 'Changer la couverture (facultatif)', 'yume-core' ) : __( 'Ajouter une couverture (facultatif)', 'yume-core' ) ) . '</label>';
	$html .= '<input type="file" id="yn-tome-fichier" name="couverture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="yn-tome-fichier-aide" data-yn-cadrage-fichier>';
	/* translators: %s : taille maximale */
	$html .= '<span class="yn-muted" id="yn-tome-fichier-aide">' . esc_html( sprintf( __( 'JPG, PNG ou WebP, %s maximum. Portrait (2:3) de préférence.', 'yume-core' ), Fichiers::taille_lisible( $max ) ) ) . '</span></p>';
	$html .= champ_cadrage( $propre, is_array( $s['cadrage'] ) ? $s['cadrage'] : null ) . '</section>';

	$html .= '<section class="yn-card yn-team__carte" aria-labelledby="yn-tome-complet"><h3 class="yn-label" id="yn-tome-complet">' . esc_html__( 'Tome complet', 'yume-core' ) . '</h3>';
	$html .= '<p class="yn-team__champ--case"><input type="hidden" name="complet" value="0"><label class="yn-team__case yn-fiche__case" for="yn-tome-case-complet"><input type="checkbox" id="yn-tome-case-complet" name="complet" value="1"' . checked( ! empty( $s['complet'] ), true, false ) . ' aria-describedby="yn-tome-complet-aide"> ' . esc_html__( 'Tous les chapitres sont en ligne : passer le tome à « Complet » (sans annonce)', 'yume-core' ) . '</label></p>';
	$html .= champ_saisie( 'yn-tome-pdf', 'lien_pdf', __( 'Lien PDF', 'yume-core' ), $val( 'lien_pdf' ), 'url', array( 'placeholder' => 'https://…' ) );
	$html .= champ_saisie( 'yn-tome-epub', 'lien_epub', __( 'Lien EPUB', 'yume-core' ), $val( 'lien_epub' ), 'url', array( 'placeholder' => 'https://…' ) );
	$html .= '<p class="yn-muted" id="yn-tome-complet-aide">' . esc_html__( 'Ici, la case passe le tome à « Complet » (liens affichés, planning « Publié » à 100 %) sans rien annoncer : ni article, ni Discord, ni e-mail. Pour annoncer le tome complet, cochez « Tome complet » en ajoutant ses derniers chapitres.', 'yume-core' ) . '</p></section>';

	$html .= carte_planning_tome( $id );

	$html   .= '<p class="yn-fiche__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button>';
	$html   .= '<a class="yn-btn" href="' . esc_url( url_vue_equipe( 'tomes' ) . '#yn-tomes-' . $id ) . '">' . esc_html__( 'Annuler', 'yume-core' ) . '</a></p>';
	$edition = (string) get_edit_post_link( $id, 'raw' );
	if ( '' !== $edition ) {
		$html .= '<p class="yn-fiche__avancee"><a class="yn-muted" href="' . esc_url( $edition ) . '">' . esc_html__( 'Édition avancée (administration WordPress)', 'yume-core' ) . '</a></p>';
	}
	return $html . '</div></form>';
}

/**
 * Sous-vue « Modifier le tome » (?vue=tomes&modifier=ID).
 *
 * @param int $id Tome.
 */
function rendu_modifier_tome( int $id ): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--tomes yn-team--fiche-tome' );
	$html .= navigation_equipe( 'tomes' );
	$html .= '<div class="yn-team__principal">';
	$titre = __( 'Modifier le tome', 'yume-core' );
	if ( ! current_user_can( 'yume_publier' ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent modifier les tomes.', 'yume-core' ) );
	}
	$post = get_post( $id );
	if ( ! $post || 'yume_tome' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Ce tome n’existe pas ou a été supprimé.', 'yume-core' ), url_vue_equipe( 'tomes' ), __( 'Tous les tomes', 'yume-core' ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Votre rôle ne permet pas de modifier ce tome.', 'yume-core' ), url_vue_equipe( 'tomes' ), __( 'Tous les tomes', 'yume-core' ) );
	}

	$retour = retour_formulaire( get_current_user_id() );
	$pour   = static function ( string $cible ) use ( $retour, $id ): ?array {
		return $retour && ( $retour['cible'] ?? '' ) === $cible && (int) ( $retour['tome_id'] ?? 0 ) === $id ? $retour : null;
	};
	$nb     = chapitres_par_tome()[ $id ] ?? array(
		'publies'    => 0,
		'attente'    => 0,
		'programmes' => 0,
	);
	$tome   = array(
		'statut'     => (string) $post->post_status,
		'parution'   => yume_parution_tome( $id ),
		'date'       => ts_contenu( $post, in_array( $post->post_status, array( 'publish', 'private', 'future' ), true ) ? 'post_date' : 'post_modified' ),
		'etape'      => (string) get_post_meta( $id, 'yume_etape', true ),
		'date_cible' => (string) get_post_meta( $id, 'yume_date_cible', true ),
		'publies'    => (int) $nb['publies'],
		'attente'    => (int) $nb['attente'],
		'programmes' => (int) $nb['programmes'],
		'prevus'     => (int) get_post_meta( $id, 'yume_chapitres_prevus', true ),
		'prochain'   => prochains_chapitres_programmes()[ $id ] ?? null,
		'pdf'        => '' !== trim( (string) get_post_meta( $id, 'yume_lien_pdf', true ) ),
		'epub'       => '' !== trim( (string) get_post_meta( $id, 'yume_lien_epub', true ) ),
	);

	$boutons = '';
	if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
		$boutons .= '<a class="yn-btn" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir le tome', 'yume-core' ) . '</a>';
	}
	$boutons .= '<a class="yn-btn yn-btn--primary" href="' . esc_url( url_publier_tome( $id ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a>';

	$html .= '<p class="yn-fiche__retour"><a href="' . esc_url( url_vue_equipe( 'tomes' ) . '#yn-tomes-' . $id ) . '"><span aria-hidden="true">←</span> ' . esc_html__( 'Tous les tomes', 'yume-core' ) . '</a></p>';
	/* translators: %s : tome (« Œuvre — Tome 2 ») */
	$html .= tete_vue( sprintf( __( 'Modifier le tome : %s', 'yume-core' ), titre_brut( $id ) ), $boutons );
	$date  = texte_date_tome( $tome );
	$html .= '<p class="yn-lecture__puces yn-fiche__puces">' . pastille_parution( $tome['parution'] ) . pastilles_chapitres_tome( $tome ) . ( '' !== $date ? '<span class="yn-muted">' . esc_html( $date ) . '</span>' : '' ) . '</p>';
	$html .= '<div id="yn-tome-fiche">' . zone_retour( $pour( 'yn-tome-fiche' ) ) . '</div>';
	$html .= formulaire_tome( $id, $pour( 'yn-tome-form' ) );
	$html .= section_chapitres_tome( $id, $pour );
	return $html . '</div></div>';
}
