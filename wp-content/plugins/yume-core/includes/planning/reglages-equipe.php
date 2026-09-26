<?php
/**
 * Vue « Réglages » de l'espace équipe (?vue=reglages sur la page équipe, bloc
 * yume/team-dashboard) : les réglages Yume (option yume_reglages) dans l'habillage de l'espace
 * équipe, pour qui a la capacité yume_reglages.
 *
 * Sections et champs viennent de la source unique de la page Yume → Réglages
 * (\Yume\Core\Core\sections_reglages() et champs_reglages()) : mêmes règles d'affichage (un
 * champ qui exige une capacité propre, comme update_plugins pour les mises à jour, est masqué ;
 * un champ verrouillé est affiché en lecture seule). L'enregistrement passe par admin-post.php
 * (action yume_reglages_equipe, nonce) puis par update_option(), donc par le même assainissement
 * que la page d'administration (assainir_reglages(), callback du réglage). La page
 * d'administration reste disponible (lien « Ouvrir dans l'administration ») et garde le
 * sélecteur de la médiathèque : ici, les images se désignent par leur ID ou leur adresse.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

use const Yume\Core\Core\MAX_PARTENAIRES;
use const Yume\Core\Core\OPTION_REGLAGES;
use const Yume\Core\Core\PAGE_REGLAGES;

defined( 'ABSPATH' ) || exit;

/** Action admin-post.php et nonce du formulaire des réglages de l'espace équipe. */
const ACTION_REGLAGES_EQUIPE = 'yume_reglages_equipe';

/** Cible du retour du formulaire (zone aria-live en tête de la vue). */
const RETOUR_REGLAGES = 'yn-reglages-retour';

/*
 * -----------------------------------------------------------------------------
 * Données
 * -----------------------------------------------------------------------------
 */

/**
 * Sections de la vue et leurs champs visibles pour l'utilisateur courant, dans l'ordre de la
 * page d'administration ; une section sans champ accessible n'est pas affichée.
 *
 * @return array<string,array{titre:string,champs:array<int,array<string,mixed>>}>
 */
function sections_reglages_equipe(): array {
	$sections = array();
	foreach ( \Yume\Core\Core\sections_reglages() as $id => $titre ) {
		$sections[ (string) $id ] = array(
			'titre'  => (string) $titre,
			'champs' => array(),
		);
	}
	foreach ( \Yume\Core\Core\champs_reglages() as $champ ) {
		if ( ! \Yume\Core\Core\champ_visible( $champ ) ) {
			continue;
		}
		$section = (string) $champ['section'];
		if ( ! isset( $sections[ $section ] ) ) {
			$sections[ $section ] = array(
				'titre'  => ucfirst( str_replace( array( '_', '-' ), ' ', $section ) ),
				'champs' => array(),
			);
		}
		$sections[ $section ]['champs'][] = $champ;
	}
	return array_filter(
		$sections,
		static function ( array $section ): bool {
			return (bool) $section['champs'];
		}
	);
}

/**
 * Adresse de la page Yume → Réglages de l'administration (ancre facultative).
 *
 * @param string $ancre Ancre (sans #).
 */
function url_reglages_admin( string $ancre = '' ): string {
	return admin_url( 'admin.php?page=' . PAGE_REGLAGES ) . ( '' !== $ancre ? '#' . $ancre : '' );
}

/*
 * -----------------------------------------------------------------------------
 * Champs
 * -----------------------------------------------------------------------------
 */

/**
 * Lien vers l'administration, ouvert dans un nouvel onglet (annoncé aux lecteurs d'écran).
 *
 * @param string $url     Adresse.
 * @param string $libelle Libellé.
 * @param string $classe  Classe.
 */
function lien_nouvel_onglet( string $url, string $libelle, string $classe = 'yn-reglages__mediatheque' ): string {
	return '<a class="' . esc_attr( $classe ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $libelle )
		. '<span class="yn-visually-hidden"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a>';
}

/**
 * Aperçu d'une image désignée par l'ID d'une pièce jointe ou par son adresse ('' si aucune).
 *
 * @param mixed  $valeur  ID ou adresse.
 * @param string $libelle Nom de l'image (texte de remplacement « Aperçu : … »).
 */
function apercu_image_reglage( $valeur, string $libelle ): string {
	$valeur = is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
	/* translators: %s : nom du réglage (bannière, logo…) */
	$alt = sprintf( __( 'Aperçu : %s', 'yume-core' ), $libelle );
	if ( '' === $valeur || '0' === $valeur ) {
		return '';
	}
	if ( ctype_digit( $valeur ) ) {
		$img = wp_get_attachment_image(
			(int) $valeur,
			'thumbnail',
			false,
			array(
				'class'   => 'yn-reglages__vignette',
				'alt'     => $alt,
				'loading' => 'lazy',
			)
		);
		return (string) $img;
	}
	$url = esc_url( $valeur, array( 'http', 'https' ) );
	return '' !== $url ? '<img class="yn-reglages__vignette" src="' . $url . '" alt="' . esc_attr( $alt ) . '" loading="lazy">' : '';
}

/**
 * Description d'un champ (liée par aria-describedby).
 *
 * @param string $id    ID de la description.
 * @param string $texte Texte.
 */
function aide_reglage( string $id, string $texte ): string {
	return '' !== $texte ? '<span class="yn-muted yn-reglages__aide" id="' . esc_attr( $id ) . '">' . esc_html( $texte ) . '</span>' : '';
}

/**
 * Un champ de réglage dans l'habillage de l'espace équipe (mêmes noms de champs que la page
 * d'administration : yume_reglages[clé]).
 *
 * @param array<string,mixed> $champ Champ (voir \Yume\Core\Core\champs_reglages()).
 */
function champ_reglage_equipe( array $champ ): string {
	$cle      = (string) $champ['key'];
	$type     = (string) $champ['type'];
	$id       = 'yn-reglage-' . $cle;
	$nom      = OPTION_REGLAGES . '[' . $cle . ']';
	$libelle  = (string) $champ['label'];
	$valeur   = yume_setting( $cle );
	$desc     = (string) ( $champ['description'] ?? '' );
	$aide_id  = $id . '-aide';
	$large    = in_array( $type, array( 'textarea', 'checkboxes', 'media', 'partenaires' ), true );
	$classe   = 'yn-team__champ yn-reglages__champ' . ( $large ? ' yn-reglages__champ--large' : '' );
	$decrit   = static function ( string $texte ) use ( $aide_id ): string {
		return '' !== $texte ? ' aria-describedby="' . esc_attr( $aide_id ) . '"' : '';
	};
	$attr_num = '';
	foreach ( array( 'min', 'max', 'step' ) as $a ) {
		if ( isset( $champ[ $a ] ) && is_scalar( $champ[ $a ] ) ) {
			$attr_num .= ' ' . $a . '="' . esc_attr( (string) $champ[ $a ] ) . '"';
		}
	}

	if ( ! \Yume\Core\Core\champ_modifiable( $champ ) ) {
		// Champ verrouillé : valeur en lecture seule, jamais envoyée (comme dans l'administration).
		$desc  = trim( $desc . ' ' . (string) ( $champ['verrouille'] ?? '' ) );
		$html  = '<div class="' . esc_attr( $classe ) . '"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( $id ) . '" value="' . esc_attr( is_scalar( $valeur ) ? (string) $valeur : '' ) . '" disabled' . $decrit( $desc ) . '>';
		return $html . aide_reglage( $aide_id, $desc ) . '</div>';
	}

	switch ( $type ) {
		case 'checkbox':
			$html  = '<div class="' . esc_attr( $classe ) . ' yn-reglages__champ--case">';
			$html .= '<label class="yn-team__case yn-reglages__case" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '" value="1"' . checked( (bool) $valeur, true, false ) . $decrit( $desc ) . '> ' . esc_html( $libelle ) . '</label>';
			return $html . aide_reglage( $aide_id, $desc ) . '</div>';

		case 'checkboxes':
			$coches = array_map( 'strval', (array) $valeur );
			$html   = '<fieldset class="' . esc_attr( $classe ) . ' yn-reglages__groupe"' . $decrit( $desc ) . '><legend class="yn-label">' . esc_html( $libelle ) . '</legend><span class="yn-reglages__cases">';
			foreach ( (array) $champ['options'] as $option => $texte ) {
				$option_id = $id . '-' . sanitize_key( (string) $option );
				$html     .= '<label class="yn-team__case yn-reglages__case" for="' . esc_attr( $option_id ) . '"><input type="checkbox" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $nom ) . '[]" value="' . esc_attr( (string) $option ) . '"' . checked( in_array( (string) $option, $coches, true ), true, false ) . '> ' . esc_html( (string) $texte ) . '</label>';
			}
			return $html . '</span>' . aide_reglage( $aide_id, $desc ) . '</fieldset>';

		case 'select':
			$html  = '<div class="' . esc_attr( $classe ) . '"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
			$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '"' . $decrit( $desc ) . '>';
			foreach ( (array) $champ['options'] as $option => $texte ) {
				$html .= '<option value="' . esc_attr( (string) $option ) . '"' . selected( is_scalar( $valeur ) ? (string) $valeur : '', (string) $option, false ) . '>' . esc_html( (string) $texte ) . '</option>';
			}
			return $html . '</select>' . aide_reglage( $aide_id, $desc ) . '</div>';

		case 'textarea':
			$html  = '<div class="' . esc_attr( $classe ) . '"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
			$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '" rows="3"' . $decrit( $desc ) . '>' . esc_textarea( is_scalar( $valeur ) ? (string) $valeur : '' ) . '</textarea>';
			return $html . aide_reglage( $aide_id, $desc ) . '</div>';

		case 'media':
			$courant = (int) ( is_scalar( $valeur ) ? $valeur : 0 );
			$desc    = trim( $desc . ' ' . __( 'ID d’une image de la médiathèque ou adresse d’une image déjà envoyée sur le site ; vider le champ retire l’image.', 'yume-core' ) );
			$html    = '<div class="' . esc_attr( $classe ) . ' yn-reglages__media"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
			$html   .= '<span class="yn-reglages__media-ligne">' . apercu_image_reglage( $courant, $libelle );
			$html   .= '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '" value="' . esc_attr( $courant ? (string) $courant : '' ) . '" autocomplete="off" spellcheck="false"' . $decrit( $desc ) . '></span>';
			$html   .= aide_reglage( $aide_id, $desc );
			return $html . lien_nouvel_onglet( url_reglages_admin( 'yume-reglage-' . $cle ), __( 'Choisir dans la médiathèque', 'yume-core' ) ) . '</div>';

		case 'partenaires':
			return champ_partenaires_equipe( $id, $nom, $valeur, $libelle, $desc );

		case 'number':
			$html  = '<div class="' . esc_attr( $classe ) . '"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
			$html .= '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '" value="' . esc_attr( is_scalar( $valeur ) ? (string) $valeur : '' ) . '"' . $attr_num . ' inputmode="numeric"' . $decrit( $desc ) . '>';
			return $html . aide_reglage( $aide_id, $desc ) . '</div>';

		default:
			$html  = '<div class="' . esc_attr( $classe ) . '"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $libelle ) . '</label>';
			$html .= '<input type="' . ( 'url' === $type ? 'url' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '" value="' . esc_attr( is_scalar( $valeur ) ? (string) $valeur : '' ) . '" autocomplete="off" spellcheck="false"';
			$html .= ! empty( $champ['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $champ['placeholder'] ) . '"' : '';
			$html .= $decrit( $desc ) . '>';
			return $html . aide_reglage( $aide_id, $desc ) . '</div>';
	}
}

/**
 * Partenaires de l'accueil : une carte par partenaire (nom, lien, description, logo avec
 * aperçu), puis les emplacements libres regroupés sous « Ajouter un partenaire ». Mêmes noms de
 * champs que le tableau de la page d'administration.
 *
 * @param string $id      Préfixe des ID.
 * @param string $nom     Attribut name du réglage.
 * @param mixed  $valeur  Liste enregistrée (ou par défaut).
 * @param string $libelle Libellé du réglage.
 * @param string $desc    Description.
 */
function champ_partenaires_equipe( string $id, string $nom, $valeur, string $libelle, string $desc ): string {
	$lignes = is_array( $valeur ) ? array_values( array_filter( $valeur, 'is_array' ) ) : array();
	// Filtre documenté dans includes/core/settings.php (champ_partenaires()).
	$lignes   = array_slice( (array) apply_filters( 'yume_reglages_partenaires_formulaire', $lignes ), 0, MAX_PARTENAIRES );
	$colonnes = array(
		'nom'         => __( 'Nom', 'yume-core' ),
		'url'         => __( 'Lien', 'yume-core' ),
		'description' => __( 'Description (une ligne)', 'yume-core' ),
		'logo'        => __( 'Logo (ID ou adresse)', 'yume-core' ),
	);
	$aide_id  = $id . '-aide';
	$html     = '<div class="yn-reglages__champ yn-reglages__champ--large yn-reglages__partenaires" role="group" aria-labelledby="' . esc_attr( $id . '-titre' ) . '"' . ( '' !== $desc ? ' aria-describedby="' . esc_attr( $aide_id ) . '"' : '' ) . '>';
	$html    .= '<p class="yn-label" id="' . esc_attr( $id . '-titre' ) . '">' . esc_html( $libelle ) . '</p>' . aide_reglage( $aide_id, $desc );
	if ( current_user_can( 'upload_files' ) ) {
		$html .= '<p class="yn-reglages__aide">' . lien_nouvel_onglet( admin_url( 'upload.php' ), __( 'Choisir dans la médiathèque', 'yume-core' ) ) . ' <span class="yn-muted">' . esc_html__( '(copiez l’ID ou l’adresse du fichier de l’image, puis collez-le dans « Logo »)', 'yume-core' ) . '</span></p>';
	}
	$ligne_html = static function ( int $i, array $ligne ) use ( $id, $nom, $colonnes ): string {
		/* translators: %d : numéro du partenaire */
		$titre = sprintf( __( 'Partenaire %d', 'yume-core' ), $i + 1 );
		$html  = '<fieldset class="yn-reglages__partenaire"><legend class="yn-label">' . esc_html( $titre ) . '</legend>';
		foreach ( $colonnes as $cle => $texte ) {
			$champ_id = $id . '-' . $i . '-' . $cle;
			$brute    = $ligne[ $cle ] ?? '';
			$brute    = is_scalar( $brute ) ? (string) $brute : '';
			$html    .= '<p class="yn-team__champ' . ( 'logo' === $cle ? ' yn-reglages__logo' : '' ) . '"><label class="yn-label" for="' . esc_attr( $champ_id ) . '">' . esc_html( $texte ) . '<span class="yn-visually-hidden"> (' . esc_html( $titre ) . ')</span></label>';
			$html    .= '<input type="' . ( 'url' === $cle ? 'url' : 'text' ) . '" id="' . esc_attr( $champ_id ) . '" name="' . esc_attr( $nom . '[' . $i . '][' . $cle . ']' ) . '" value="' . esc_attr( $brute ) . '" autocomplete="off"' . ( 'url' === $cle ? ' placeholder="https://…"' : '' ) . ( 'description' === $cle ? ' maxlength="160"' : ( 'nom' === $cle ? ' maxlength="80"' : '' ) ) . '>';
			if ( 'logo' === $cle ) {
				$qui   = is_scalar( $ligne['nom'] ?? null ) && '' !== (string) $ligne['nom'] ? (string) $ligne['nom'] : $titre;
				$html .= apercu_image_reglage( $brute, $texte . ' (' . $qui . ')' );
			}
			$html .= '</p>';
		}
		return $html . '</fieldset>';
	};
	$html      .= '<div class="yn-reglages__partenaires-liste">';
	$remplis    = count( $lignes );
	for ( $i = 0; $i < $remplis; $i++ ) {
		$html .= $ligne_html( $i, $lignes[ $i ] );
	}
	$html .= '</div>';
	if ( $remplis < MAX_PARTENAIRES ) {
		$libres = MAX_PARTENAIRES - $remplis;
		/* translators: %d : nombre d'emplacements libres */
		$html .= '<details class="yn-team__details yn-reglages__ajout"><summary>' . esc_html( sprintf( _n( 'Ajouter un partenaire (%d emplacement libre)', 'Ajouter un partenaire (%d emplacements libres)', $libres, 'yume-core' ), $libres ) ) . '</summary>';
		$html .= '<div class="yn-reglages__partenaires-liste">';
		for ( $i = $remplis; $i < MAX_PARTENAIRES; $i++ ) {
			$html .= $ligne_html( $i, array() );
		}
		$html .= '</div></details>';
	}
	return $html . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * Vue
 * -----------------------------------------------------------------------------
 */

/**
 * Zone d'annonce du résultat de l'enregistrement (message et avertissements de l'assainissement).
 *
 * @param array|null $retour Retour du formulaire.
 */
function zone_retour_reglages( ?array $retour ): string {
	$classe = 'yn-team__retour yn-reglages__retour';
	$corps  = '';
	if ( $retour ) {
		$classe .= 'ok' === $retour['type'] ? ' yn-team__retour--ok' : ' yn-team__retour--erreur';
		$corps   = '<p>' . esc_html( (string) $retour['message'] ) . '</p>';
		$details = array_filter( array_map( 'strval', (array) ( $retour['details'] ?? array() ) ) );
		if ( $details ) {
			$corps .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $details ) ) . '</li></ul>';
		}
	}
	return '<div class="' . esc_attr( $classe ) . '" id="' . esc_attr( RETOUR_REGLAGES ) . '" role="status" aria-live="polite" tabindex="-1">' . $corps . '</div>';
}

/**
 * Vue « Réglages » (?vue=reglages) : toutes les sections et tous les champs visibles, un seul
 * bouton « Enregistrer les réglages ».
 */
function rendu_vue_reglages(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--reglages' );
	$html .= navigation_equipe( 'reglages' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( 'yume_reglages' ) ) {
		$html .= tete_vue( __( 'Réglages', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les gérants et les administrateurs peuvent modifier les réglages du site.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}

	$retour = est_apercu_editeur() ? null : retour_formulaire( get_current_user_id() );
	$retour = $retour && RETOUR_REGLAGES === ( $retour['cible'] ?? '' ) ? $retour : null;

	$html .= tete_vue( __( 'Réglages', 'yume-core' ), lien_nouvel_onglet( url_reglages_admin(), __( 'Ouvrir dans l’administration', 'yume-core' ), 'yn-btn yn-btn--sm' ) );
	$html .= '<p class="yn-muted">' . esc_html__( 'Réglages du site, des rappels du planning, des annonces Discord et des partenaires de l’accueil. Les modifications s’appliquent dès l’enregistrement.', 'yume-core' ) . '</p>';
	$html .= zone_retour_reglages( $retour );

	$html .= '<form class="yn-reglages" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$html .= '<input type="hidden" name="action" value="' . esc_attr( ACTION_REGLAGES_EQUIPE ) . '">';
	$html .= wp_nonce_field( ACTION_REGLAGES_EQUIPE, '_yume_nonce', false, false );
	// Même marqueur que la page d'administration : une case décochée vaut « non ».
	$html .= '<input type="hidden" name="' . esc_attr( OPTION_REGLAGES ) . '[_formulaire]" value="1">';

	foreach ( sections_reglages_equipe() as $section => $donnees ) {
		$ancre = 'yn-reglages-' . sanitize_html_class( str_replace( '_', '-', $section ) );
		$html .= '<section class="yn-card yn-reglages__section" id="' . esc_attr( $ancre ) . '" aria-labelledby="' . esc_attr( $ancre . '-titre' ) . '">';
		$html .= '<h2 id="' . esc_attr( $ancre . '-titre' ) . '">' . esc_html( $donnees['titre'] ) . '</h2><div class="yn-reglages__champs">';
		foreach ( $donnees['champs'] as $champ ) {
			$html .= champ_reglage_equipe( $champ );
		}
		$html .= '</div></section>';
	}

	$html .= '<p class="yn-team__action yn-reglages__envoi"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer les réglages', 'yume-core' ) . '</button></p>';
	$html .= '</form>';
	return $html . '</div></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Enregistrement (admin-post.php, action yume_reglages_equipe)
 * -----------------------------------------------------------------------------
 */

/**
 * Prépare l'assainissement de la page d'administration hors de wp-admin : fonctions
 * add_settings_error() / get_settings_errors() et callback du réglage (register_setting()).
 */
function preparer_reglages(): void {
	if ( ! function_exists( 'add_settings_error' ) ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}
	if ( ! has_filter( 'sanitize_option_' . OPTION_REGLAGES ) ) {
		\Yume\Core\Core\enregistrer_reglage();
	}
}

/**
 * Images saisies par adresse (champs de type media) : l'adresse d'un fichier de la médiathèque
 * est remplacée par l'ID de sa pièce jointe, ce qu'attend l'assainissement ; une adresse inconnue
 * garde la valeur actuelle, avec un avertissement.
 *
 * @param array<string,mixed> $entree   Saisie (déslashée).
 * @param string[]            $notices  Avertissements (complétés).
 * @return array<string,mixed>
 */
function convertir_images_reglages( array $entree, array &$notices ): array {
	foreach ( \Yume\Core\Core\champs_reglages() as $champ ) {
		$cle = (string) $champ['key'];
		if ( 'media' !== $champ['type'] || ! array_key_exists( $cle, $entree ) || ! is_scalar( $entree[ $cle ] ) ) {
			continue;
		}
		$saisie = trim( (string) $entree[ $cle ] );
		if ( '' === $saisie || ctype_digit( $saisie ) ) {
			$entree[ $cle ] = '' === $saisie ? '0' : $saisie;
			continue;
		}
		$url = esc_url_raw( $saisie, array( 'http', 'https' ) );
		$id  = '' !== $url ? (int) attachment_url_to_postid( $url ) : 0;
		if ( $id ) {
			$entree[ $cle ] = (string) $id;
			continue;
		}
		$notices[] = sprintf(
			/* translators: %s : nom du réglage */
			__( '« %s » : cette adresse ne correspond à aucune image de la médiathèque, la valeur précédente est conservée.', 'yume-core' ),
			(string) $champ['label']
		);
		$entree[ $cle ] = (string) (int) yume_setting( $cle );
	}
	return $entree;
}

/**
 * Traite le formulaire des réglages de l'espace équipe : nonce, capacité yume_reglages, puis
 * update_option(), donc l'assainissement de la page d'administration (assainir_reglages() :
 * champs réservés à une autre capacité ou verrouillés conservés, webhooks et partenaires
 * vérifiés). Les règles par champ s'appliquent à l'utilisateur courant.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,details:string[]}
 */
function traiter_formulaire_reglages( array $post, int $user_id ): array {
	$nonce  = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$erreur = static function ( string $message ): array {
		return array(
			'type'    => 'erreur',
			'message' => $message,
			'cible'   => RETOUR_REGLAGES,
			'details' => array(),
		);
	};
	if ( ! wp_verify_nonce( $nonce, ACTION_REGLAGES_EQUIPE ) ) {
		return $erreur( __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	if ( ! $user_id || ! user_can( $user_id, 'yume_reglages' ) ) {
		return $erreur( __( 'Vous n’avez pas l’autorisation de modifier les réglages Yume.', 'yume-core' ) );
	}

	$entree = isset( $post[ OPTION_REGLAGES ] ) && is_array( $post[ OPTION_REGLAGES ] ) ? wp_unslash( $post[ OPTION_REGLAGES ] ) : array();
	// Formulaire complet, comme options.php : les cases absentes valent « non ».
	$entree['_formulaire'] = '1';
	$notices               = array();
	$entree                = convertir_images_reglages( $entree, $notices );

	preparer_reglages();
	$avant = count( get_settings_errors( OPTION_REGLAGES ) );
	update_option( OPTION_REGLAGES, $entree );
	foreach ( array_slice( get_settings_errors( OPTION_REGLAGES ), $avant ) as $avis ) {
		$texte = wp_strip_all_tags( (string) ( $avis['message'] ?? '' ) );
		if ( '' !== $texte && ! in_array( $texte, $notices, true ) ) {
			$notices[] = $texte;
		}
	}

	if ( $notices ) {
		return array(
			'type'    => 'erreur',
			'message' => __( 'Réglages enregistrés, sauf :', 'yume-core' ),
			'cible'   => RETOUR_REGLAGES,
			'details' => $notices,
		);
	}
	return array(
		'type'    => 'ok',
		'message' => __( 'Réglages enregistrés.', 'yume-core' ),
		'cible'   => RETOUR_REGLAGES,
		'details' => array(),
	);
}

/**
 * Formulaire des réglages de l'espace équipe (admin-post.php) : retour vers ?vue=reglages.
 */
function admin_post_reglages(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_reglages().
	$retour = traiter_formulaire_reglages( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	wp_safe_redirect( url_vue_equipe( 'reglages' ) . '#' . RETOUR_REGLAGES );
	exit;
}
add_action( 'admin_post_' . ACTION_REGLAGES_EQUIPE, __NAMESPACE__ . '\\admin_post_reglages' );
add_action( 'admin_post_nopriv_' . ACTION_REGLAGES_EQUIPE, __NAMESPACE__ . '\\admin_post_anonyme' );
