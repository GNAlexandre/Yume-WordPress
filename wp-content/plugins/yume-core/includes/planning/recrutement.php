<?php
/**
 * Recrutement (PAGE-03) : page « Rejoindre l'équipe » (clé `rejoindre` de yume_pages, slug
 * `rejoindre-l-equipe`, créée par la migration et par « Recréer les pages manquantes »), bloc
 * dynamique yume/recrutement et réglages de la section « Recrutement » (Yume → Réglages et vue
 * Réglages de l'espace équipe, filtre yume_reglages_champs) :
 *
 * - `recrutement_intro` (texte long) : introduction de la page ;
 * - `recrutement_postes` (texte long, une ligne par poste « Intitulé | Description courte |
 *   ouvert|fermé ») : seuls les postes ouverts sont affichés ;
 * - `recrutement_test_url` (URL http(s), facultatif) : test de traduction à télécharger ;
 * - `recrutement_consigne` (texte) : comment postuler sur le Discord (salon, ticket).
 *
 * Aucun formulaire : les candidatures passent par le serveur Discord (réglage discord_invite),
 * le site ne recueille aucune donnée personnelle de candidat.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Nombre maximal de postes enregistrés. */
const MAX_POSTES_RECRUTEMENT = 12;

/** Longueurs maximales d'un intitulé et d'une description de poste. */
const LONGUEUR_INTITULE_POSTE    = 80;
const LONGUEUR_DESCRIPTION_POSTE = 200;

/*
 * -----------------------------------------------------------------------------
 * Réglages
 * -----------------------------------------------------------------------------
 */

/**
 * Postes proposés tant que le réglage n'a jamais été enregistré (format du champ).
 */
function postes_recrutement_defaut(): string {
	return implode(
		"\n",
		array(
			'Traducteur EN→FR | Traduire des light novels depuis l’anglais, à votre rythme, avec l’aide d’un relecteur. | ouvert',
			'Relecteur | Relire et corriger les traductions : orthographe, style, cohérence des termes. | ouvert',
			'Graphiste (clean et typeset) | Nettoyer et lettrer les planches des mangas, retoucher les illustrations. | ouvert',
		)
	);
}

/**
 * Section « Recrutement » de Yume → Réglages (filtre yume_reglages_sections).
 *
 * @param array<string,string> $sections Sections.
 * @return array<string,string>
 */
function section_reglages_recrutement( $sections ): array {
	$sections                = is_array( $sections ) ? $sections : array();
	$sections['recrutement'] = __( 'Recrutement', 'yume-core' );
	return $sections;
}
add_filter( 'yume_reglages_sections', __NAMESPACE__ . '\\section_reglages_recrutement' );

/**
 * Champs de la section « Recrutement » (filtre yume_reglages_champs du module core).
 *
 * @param array $champs Champs.
 * @return array
 */
function champs_reglages_recrutement( $champs ): array {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'recrutement_intro',
		'label'       => __( 'Introduction de la page « Rejoindre l’équipe »', 'yume-core' ),
		'type'        => 'textarea',
		'section'     => 'recrutement',
		'default'     => __( 'Yume Novel est une équipe de bénévoles passionnés qui traduisent des light novels et des mangas inédits en français. Aucune expérience n’est exigée : la motivation et le goût du travail soigné comptent plus que tout. Chacun avance à son rythme, accompagné par le reste de l’équipe.', 'yume-core' ),
		'description' => __( 'Texte affiché en tête de la page « Rejoindre l’équipe ». Une ligne vide sépare deux paragraphes.', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'recrutement_postes',
		'label'       => __( 'Postes', 'yume-core' ),
		'type'        => 'textarea',
		'section'     => 'recrutement',
		'default'     => postes_recrutement_defaut(),
		'sanitize'    => __NAMESPACE__ . '\\assainir_postes_recrutement',
		'description' => sprintf(
			/* translators: %d : nombre maximal de postes */
			__( 'Un poste par ligne : « Intitulé | Description courte | ouvert » (ou « fermé » : le poste n’est plus affiché). %d postes au plus.', 'yume-core' ),
			MAX_POSTES_RECRUTEMENT
		),
	);
	$champs[] = array(
		'key'         => 'recrutement_test_url',
		'label'       => __( 'Test de traduction', 'yume-core' ),
		'type'        => 'url',
		'section'     => 'recrutement',
		'default'     => '',
		'placeholder' => 'https://…',
		'description' => __( 'Lien du test de traduction à télécharger (facultatif, http ou https).', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'recrutement_consigne',
		'label'       => __( 'Comment postuler', 'yume-core' ),
		'type'        => 'text',
		'section'     => 'recrutement',
		'default'     => __( 'Rejoignez notre serveur Discord et ouvrez un ticket dans le salon « tickets » en précisant le poste qui vous intéresse.', 'yume-core' ),
		'description' => __( 'Affiché au-dessus du bouton « Postuler sur le Discord » (lien : réglage « Invitation Discord »).', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages_recrutement' );

/**
 * Découpe le texte du réglage en postes.
 *
 * @param mixed $valeur Texte (une ligne par poste).
 * @return array<int,array{intitule:string,description:string,ouvert:bool}>
 */
function lire_postes_recrutement( $valeur ): array {
	$postes = array();
	$lignes = is_scalar( $valeur ) ? preg_split( '/\R/u', (string) $valeur ) : array();
	foreach ( (array) $lignes as $ligne ) {
		$parties  = array_map( 'trim', explode( '|', (string) $ligne ) );
		$intitule = mb_substr( sanitize_text_field( $parties[0] ?? '' ), 0, LONGUEUR_INTITULE_POSTE );
		if ( '' === $intitule ) {
			continue;
		}
		$etat     = remove_accents( mb_strtolower( sanitize_text_field( $parties[2] ?? '' ) ) );
		$postes[] = array(
			'intitule'    => $intitule,
			'description' => mb_substr( sanitize_text_field( $parties[1] ?? '' ), 0, LONGUEUR_DESCRIPTION_POSTE ),
			// Sans état, le poste est ouvert ; « fermé », « non », « pourvu » le masquent.
			'ouvert'      => ! in_array( $etat, array( 'ferme', 'non', 'pourvu', 'clos', '0' ), true ),
		);
		if ( count( $postes ) >= MAX_POSTES_RECRUTEMENT ) {
			break;
		}
	}
	return $postes;
}

/**
 * Assainit le réglage recrutement_postes : lignes vides retirées, champs nettoyés et tronqués,
 * état normalisé (« ouvert » / « fermé »), 12 postes au plus. Le réglage reste un texte (il
 * s'édite dans une zone de texte, dans l'administration comme dans l'espace équipe).
 *
 * @param mixed $valeur Texte saisi.
 */
function assainir_postes_recrutement( $valeur ): string {
	$lignes = array();
	foreach ( lire_postes_recrutement( $valeur ) as $poste ) {
		// La barre verticale sépare les colonnes : elle ne peut pas figurer dans un champ.
		$lignes[] = str_replace( '|', '/', $poste['intitule'] ) . ' | ' . str_replace( '|', '/', $poste['description'] ) . ' | ' . ( $poste['ouvert'] ? 'ouvert' : 'fermé' );
	}
	return implode( "\n", $lignes );
}

/**
 * Postes du réglage (ouverts et fermés).
 *
 * @return array<int,array{intitule:string,description:string,ouvert:bool}>
 */
function postes_recrutement(): array {
	return lire_postes_recrutement( yume_setting( 'recrutement_postes', postes_recrutement_defaut() ) );
}

/**
 * Postes ouverts, dans l'ordre du réglage.
 *
 * @return array<int,array{intitule:string,description:string,ouvert:bool}>
 */
function postes_ouverts(): array {
	return array_values(
		array_filter(
			postes_recrutement(),
			static function ( array $poste ): bool {
				return $poste['ouvert'];
			}
		)
	);
}

/**
 * Invitation Discord du site (réglage discord_invite), adresse par défaut du contrat sinon.
 */
function invitation_discord_recrutement(): string {
	$url = san_url_recrutement( yume_setting( 'discord_invite', '' ) );
	return '' !== $url ? $url : 'https://discord.gg/SMBZqhgUv8';
}

/**
 * URL http(s) ou chaîne vide.
 *
 * @param mixed $valeur Valeur.
 */
function san_url_recrutement( $valeur ): string {
	$valeur = is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
	return '' !== $valeur ? esc_url_raw( $valeur, array( 'http', 'https' ) ) : '';
}

/**
 * Adresse de yume_url_page( 'rejoindre' ) : sans page enregistrée, adresse du contrat
 * (/rejoindre-l-equipe/) plutôt que /rejoindre/.
 *
 * @param string $url URL calculée.
 * @param string $cle Clé de page.
 */
function url_page_rejoindre( $url, $cle = '' ): string {
	if ( 'rejoindre' !== $cle ) {
		return (string) $url;
	}
	$pages = get_option( 'yume_pages', array() );
	$id    = is_array( $pages ) ? absint( $pages['rejoindre'] ?? 0 ) : 0;
	if ( $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
		return (string) $url;
	}
	return home_url( '/rejoindre-l-equipe/' );
}
add_filter( 'yume_url_page', __NAMESPACE__ . '\\url_page_rejoindre', 10, 2 );

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/recrutement
 * -----------------------------------------------------------------------------
 */

/**
 * Enregistre le bloc yume/recrutement.
 */
function enregistrer_bloc_recrutement(): void {
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/recrutement' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc_recrutement' );

/**
 * Lien externe ouvert dans un nouvel onglet (annoncé aux lecteurs d'écran).
 *
 * @param string $url     Adresse.
 * @param string $libelle Libellé.
 * @param string $classe  Classes.
 */
function lien_externe_recrutement( string $url, string $libelle, string $classe ): string {
	return '<a class="' . esc_attr( $classe ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $libelle )
		. '<span class="yn-visually-hidden"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a>';
}

/**
 * Rendu du bloc yume/recrutement : introduction, postes ouverts (cartes) ou « Aucun poste
 * ouvert pour le moment », test de traduction, appel à postuler sur le Discord.
 */
function rendu_recrutement(): string {
	$intro   = trim( (string) yume_setting( 'recrutement_intro', '' ) );
	$postes  = postes_ouverts();
	$test    = san_url_recrutement( yume_setting( 'recrutement_test_url', '' ) );
	$conseil = trim( sanitize_text_field( (string) yume_setting( 'recrutement_consigne', '' ) ) );

	$html = '<div ' . attributs_racine( 'yn-recrutement' ) . '>';
	if ( '' !== $intro ) {
		$html .= '<div class="yn-recrutement__intro">' . wpautop( esc_html( $intro ) ) . '</div>';
	}

	$html .= '<section class="yn-recrutement__section" aria-labelledby="yn-recrutement-postes">'
		. '<h2 class="yn-recrutement__titre" id="yn-recrutement-postes">' . esc_html__( 'Postes ouverts', 'yume-core' ) . '</h2>';
	if ( $postes ) {
		$html .= '<ul class="yn-recrutement__postes">';
		foreach ( $postes as $poste ) {
			$html .= '<li class="yn-card yn-recrutement__poste">'
				. '<h3 class="yn-recrutement__intitule">' . esc_html( $poste['intitule'] ) . '</h3>'
				. ( '' !== $poste['description'] ? '<p class="yn-recrutement__description">' . esc_html( $poste['description'] ) . '</p>' : '' )
				. '<p class="yn-recrutement__etat"><span class="yn-chip yn-chip--ok"><span aria-hidden="true">●</span> ' . esc_html__( 'Recrutement ouvert', 'yume-core' ) . '</span></p>'
				. '</li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-card yn-recrutement__vide">' . esc_html__( 'Aucun poste ouvert pour le moment. Revenez bientôt, ou passez nous dire bonjour sur le Discord : les candidatures spontanées sont toujours lues.', 'yume-core' ) . '</p>';
	}
	$html .= '</section>';

	$html .= '<section class="yn-card yn-recrutement__postuler" aria-labelledby="yn-recrutement-postuler">'
		. '<h2 class="yn-recrutement__titre" id="yn-recrutement-postuler">' . esc_html__( 'Postuler', 'yume-core' ) . '</h2>';
	if ( '' !== $conseil ) {
		$html .= '<p>' . esc_html( $conseil ) . '</p>';
	}
	if ( '' !== $test ) {
		$html .= '<p>' . esc_html__( 'Pour la traduction, un court test vous sera demandé : vous pouvez le télécharger dès maintenant et le joindre à votre ticket.', 'yume-core' ) . '</p>';
	}
	$html .= '<p class="yn-recrutement__actions">' . lien_externe_recrutement( invitation_discord_recrutement(), __( 'Postuler sur le Discord', 'yume-core' ), 'yn-btn yn-btn--primary' );
	if ( '' !== $test ) {
		$html .= ' ' . lien_externe_recrutement( $test, __( 'Télécharger le test de traduction', 'yume-core' ), 'yn-btn' );
	}
	$html .= '</p><p class="yn-muted yn-recrutement__note">' . esc_html__( 'Le site ne recueille aucune candidature : tout se passe sur le Discord, où l’équipe vous répond directement.', 'yume-core' ) . '</p>';
	$html .= '</section>';

	return $html . '</div>';
}
