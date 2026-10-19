<?php
/**
 * Côté lecteur : script et feuille « yume-coquilles », fenêtre <dialog> de signalement imprimée
 * sur wp_footer, lien clavier « Signaler une coquille dans ce chapitre » après la navigation de fin
 * de chapitre (filtre render_block_yume/chapter-nav, aucun changement du gabarit).
 *
 * Tout est masqué sans JavaScript (attributs hidden, <dialog> fermé) : le script révèle le lien
 * de fin de chapitre et affiche le bouton flottant à la sélection d'un passage.
 *
 * Cache de page : le HTML d'un visiteur est identique pour tous (aucune donnée personnelle ; nonce
 * REST seulement pour un membre connecté, jamais mis en cache).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

/** Poignée du script et de la feuille du lecteur. */
const POIGNEE_LECTEUR = 'yume-coquilles';

/** Paragraphe fictif de l'adresse de connexion, remplacé par le script (#yn-p-N). */
const PARAGRAPHE_FICTIF = 999999;

/**
 * Chapitre affiché qui accepte les signalements, ou 0.
 */
function chapitre_de_la_page(): int {
	if ( is_admin() || ! is_singular( 'yume_chapitre' ) || ! coquilles_actives() || ! table_prete() ) {
		return 0;
	}
	if ( function_exists( 'Yume\\Core\\Reader\\page_de_lecture' ) && ! \Yume\Core\Reader\page_de_lecture() ) {
		return 0;
	}
	$id = (int) get_queried_object_id();
	return chapitre_signalable( $id ) ? $id : 0;
}

/**
 * Configuration transmise au script (attribut data-yn-coquilles).
 *
 * @param int $chapitre_id Chapitre.
 * @return array<string,mixed>
 */
function configuration_lecteur( int $chapitre_id ): array {
	$connecte  = is_user_logged_in();
	$anonyme   = ! $connecte && anonymes_autorises();
	$connexion = '';
	if ( ! $connecte && ! $anonyme ) {
		$retour    = (string) get_permalink( $chapitre_id ) . '#yn-p-' . PARAGRAPHE_FICTIF;
		$connexion = function_exists( 'Yume\\Core\\Social\\url_connexion' ) ? \Yume\Core\Social\url_connexion( $retour ) : wp_login_url( $retour );
	}
	return array(
		'rest'      => esc_url_raw( rest_url( REST_NS . '/chapitres/' . $chapitre_id . '/coquilles' ) ),
		'nonce'     => $connecte ? wp_create_nonce( 'wp_rest' ) : '',
		'chapitre'  => $chapitre_id,
		'connexion' => $connexion,
		'fictif'    => PARAGRAPHE_FICTIF,
		'anonyme'   => $anonyme,
		'jeton'     => $anonyme ? \Yume\Core\Social\jeton_formulaire() : '',
		'titre'     => libelle_chapitre( $chapitre_id ),
		'min'       => EXTRAIT_MIN,
		'max'       => EXTRAIT_MAX,
		'messages'  => array(
			/* translators: %d : numéro du paragraphe (remplacé par le script) */
			'paragraphe' => __( 'paragraphe %d', 'yume-core' ),
			'court'      => sprintf(
				/* translators: 1: minimum, 2: maximum */
				__( 'Recopiez le passage concerné (%1$d à %2$d caractères).', 'yume-core' ),
				EXTRAIT_MIN,
				EXTRAIT_MAX
			),
			'envoi'      => __( 'Envoi…', 'yume-core' ),
			'erreur'     => __( 'Le signalement n’a pas pu être envoyé. Réessayez.', 'yume-core' ),
			'reseau'     => __( 'Connexion impossible : vérifiez votre réseau puis réessayez.', 'yume-core' ),
			'session'    => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		),
	);
}

/**
 * Enregistre et charge le script et la feuille sur un chapitre signalable.
 */
function charger_assets(): void {
	if ( ! chapitre_de_la_page() ) {
		return;
	}
	wp_enqueue_style( POIGNEE_LECTEUR, YUME_CORE_URL . 'includes/corrections/assets/coquilles.css', array(), YUME_CORE_VERSION );
	wp_enqueue_script( POIGNEE_LECTEUR, YUME_CORE_URL . 'includes/corrections/assets/coquilles.js', array(), YUME_CORE_VERSION, array( 'in_footer' => true ) );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\charger_assets' );

/**
 * Icône crayon (décorative).
 */
function icone_crayon(): string {
	return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path></svg>';
}

/**
 * HTML de la fenêtre de signalement, du bouton flottant et de la zone d'annonce.
 *
 * @param int $chapitre_id Chapitre.
 */
function html_fenetre( int $chapitre_id ): string {
	$config   = configuration_lecteur( $chapitre_id );
	$membre   = '' === $config['connexion'];
	$html     = '<div class="yn-coquilles-racine" data-yn-coquilles="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
	$flottant = icone_crayon() . '<span>' . ( $membre ? esc_html__( 'Signaler une coquille', 'yume-core' ) : esc_html__( 'Connectez-vous pour signaler une coquille', 'yume-core' ) ) . '</span>';
	$html    .= $membre
		? '<button type="button" class="yn-btn yn-btn--primary yn-btn--sm yn-coquilles-flottant" hidden data-yn-coquilles-flottant>' . $flottant . '</button>'
		: '<a class="yn-btn yn-btn--primary yn-btn--sm yn-coquilles-flottant" hidden data-yn-coquilles-flottant href="' . esc_url( $config['connexion'] ) . '">' . $flottant . '</a>';
	$html    .= '<p class="yn-coquilles-annonce" role="status" aria-live="polite" data-yn-coquilles-annonce></p>';
	if ( ! $membre ) {
		return $html . '</div>';
	}
	$html .= '<dialog class="yn-coquilles" id="yn-coquilles" aria-labelledby="yn-coquilles-titre" aria-describedby="yn-coquilles-position">';
	$html .= '<form class="yn-coquilles__formulaire" method="dialog" novalidate>';
	$html .= '<div class="yn-coquilles__entete"><h2 class="yn-coquilles__titre" id="yn-coquilles-titre">' . esc_html__( 'Signaler une coquille', 'yume-core' ) . '</h2>';
	$html .= '<button type="button" class="yn-btn yn-btn--sm yn-coquilles__fermer" data-yn-fermer aria-label="' . esc_attr__( 'Fermer', 'yume-core' ) . '"><span aria-hidden="true">✕</span></button></div>';
	$html .= '<p class="yn-muted yn-coquilles__position" id="yn-coquilles-position">' . esc_html( $config['titre'] ) . ' · <span data-yn-coquilles-numero></span></p>';
	// Passage sélectionné (lecture seule).
	$html .= '<div class="yn-coquilles__bloc" data-yn-mode-selection><p class="yn-label yn-coquilles__label">' . esc_html__( 'Passage sélectionné', 'yume-core' ) . '</p>';
	$html .= '<blockquote class="yn-coquilles__passage" data-yn-coquilles-passage></blockquote></div>';
	// Ouverture au clavier : paragraphe et passage à recopier.
	$html .= '<div class="yn-coquilles__bloc" data-yn-mode-saisie hidden>';
	$html .= '<p class="yn-coquilles__champ yn-coquilles__champ--court"><label class="yn-label yn-coquilles__label" for="yn-coquilles-paragraphe">' . esc_html__( 'Paragraphe', 'yume-core' ) . '</label>';
	$html .= '<input type="number" id="yn-coquilles-paragraphe" min="1" step="1" inputmode="numeric"></p>';
	$html .= '<p class="yn-coquilles__champ"><label class="yn-label yn-coquilles__label" for="yn-coquilles-extrait">' . esc_html__( 'Passage', 'yume-core' ) . '</label>';
	$html .= '<textarea id="yn-coquilles-extrait" rows="2" maxlength="' . EXTRAIT_MAX . '" aria-describedby="yn-coquilles-extrait-aide"></textarea>';
	$html .= '<span class="yn-muted yn-coquilles__aide" id="yn-coquilles-extrait-aide">' . esc_html( $config['messages']['court'] ) . '</span></p></div>';
	$html .= '<p class="yn-coquilles__champ"><label class="yn-label yn-coquilles__label" for="yn-coquilles-commentaire">' . esc_html__( 'Commentaire (facultatif)', 'yume-core' ) . '</label>';
	$html .= '<textarea id="yn-coquilles-commentaire" rows="3" maxlength="' . COMMENTAIRE_MAX . '" placeholder="' . esc_attr__( 'Ex. : il manque l’accent sur « à »', 'yume-core' ) . '"></textarea></p>';
	if ( $config['anonyme'] ) {
		// Pot de miel : invisible pour un humain.
		$html .= '<div class="yn-coquilles__piege" aria-hidden="true"><label for="yn-coquilles-site">' . esc_html__( 'Laissez ce champ vide', 'yume-core' ) . '</label><input type="text" id="yn-coquilles-site" name="yn_site_web" tabindex="-1" autocomplete="off"></div>';
		$html .= '<p class="yn-muted yn-coquilles__note">' . esc_html__( 'Votre signalement est anonyme.', 'yume-core' ) . '</p>';
	} else {
		$html .= '<p class="yn-muted yn-coquilles__note">' . esc_html__( 'Votre pseudo sera visible de l’équipe seulement.', 'yume-core' ) . '</p>';
	}
	$html .= '<p class="yn-coquilles__erreur" role="alert" data-yn-coquilles-erreur></p>';
	$html .= '<div class="yn-coquilles__actions"><button type="button" class="yn-btn" data-yn-fermer>' . esc_html__( 'Annuler', 'yume-core' ) . '</button>';
	$html .= '<button type="submit" class="yn-btn yn-btn--primary" data-yn-coquilles-envoyer>' . esc_html__( 'Envoyer', 'yume-core' ) . '</button></div>';
	return $html . '</form></dialog></div>';
}

/**
 * Imprime la fenêtre de signalement en bas de page (chapitre signalable seulement).
 */
function imprimer_fenetre(): void {
	$chapitre_id = chapitre_de_la_page();
	if ( ! $chapitre_id ) {
		return;
	}
	echo html_fenetre( $chapitre_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé à la construction.
}
add_action( 'wp_footer', __NAMESPACE__ . '\\imprimer_fenetre' );

/**
 * Lien clavier après la navigation de fin de chapitre (masqué sans JavaScript).
 *
 * @param string $contenu HTML du bloc yume/chapter-nav.
 * @param array  $bloc    Bloc analysé.
 */
function lien_fin_de_chapitre( $contenu, $bloc = array() ): string {
	unset( $bloc );
	$contenu = (string) $contenu;
	if ( '' === trim( $contenu ) || ! chapitre_de_la_page() ) {
		return $contenu;
	}
	$html  = '<p class="yn-coquilles-fin yn-muted" hidden data-yn-coquilles-fin>';
	$html .= esc_html__( 'Une coquille ? Sélectionnez le passage puis « Signaler une coquille ».', 'yume-core' ) . ' ';
	$html .= '<button type="button" class="yn-coquilles-fin__bouton" data-yn-coquilles-ouvrir>' . esc_html__( 'Signaler une coquille dans ce chapitre', 'yume-core' ) . '</button></p>';
	return $contenu . $html;
}
add_filter( 'render_block_yume/chapter-nav', __NAMESPACE__ . '\\lien_fin_de_chapitre', 10, 2 );
