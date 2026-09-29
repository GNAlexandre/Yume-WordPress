<?php
/**
 * Blocs du module lecteurs (§10) : enregistrement et rendu de yume/oeuvre-actions,
 * yume/resume-reading et yume/auth-links (yume/account : compte.php).
 *
 * Tout fonctionne sans JavaScript (formulaires admin-post avec nonce) ; les scripts des blocs
 * passent ensuite par la REST (X-WP-Nonce). Couleurs : variables et classes du thème (§15).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre les blocs du module.
 */
function enregistrer_blocs(): void {
	if ( ! function_exists( 'yume_register_dynamic_block' ) ) {
		return;
	}
	foreach ( array( 'oeuvre-actions', 'resume-reading', 'account', 'auth-links' ) as $bloc ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/' . $bloc );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_blocs' );

/*
 * -----------------------------------------------------------------------------
 * Composants communs
 * -----------------------------------------------------------------------------
 */

/**
 * Attributs de la racine d'un bloc (classes de l'éditeur comprises).
 *
 * @param string $classe Classe racine.
 * @param array  $extra  Attributs supplémentaires.
 */
function attributs_racine( string $classe, array $extra = array() ): string {
	$extra['class'] = trim( $classe . ' ' . ( $extra['class'] ?? '' ) );
	if ( class_exists( '\WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render ) ) {
		return get_block_wrapper_attributes( $extra );
	}
	$html = '';
	foreach ( $extra as $nom => $valeur ) {
		$html .= ' ' . esc_attr( (string) $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return ltrim( $html );
}

/**
 * Rendu en cours dans l'éditeur de blocs (aperçu serveur) ?
 */
function apercu_editeur(): bool {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return false;
	}
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
	return '' === $route || str_contains( $route, '/block-renderer/' );
}

/**
 * Message discret d'aperçu dans l'éditeur.
 *
 * @param string $classe Classe racine.
 * @param string $texte  Texte.
 */
function rendu_apercu( string $classe, string $texte ): string {
	return '<div ' . attributs_racine( $classe . ' yn-apercu-editeur' ) . '><p class="yn-muted">' . esc_html( $texte ) . '</p></div>';
}

/**
 * Œuvre du contexte (bloc postId, ou objet de la requête), ou 0.
 *
 * @param \WP_Block|null $bloc Instance du bloc.
 */
function oeuvre_contexte( $bloc = null ): int {
	$id = 0;
	if ( $bloc instanceof \WP_Block && ! empty( $bloc->context['postId'] ) ) {
		$id = (int) $bloc->context['postId'];
	}
	if ( ! $id ) {
		$id = (int) get_queried_object_id();
	}
	if ( $id && 'yume_oeuvre' !== get_post_type( $id ) && function_exists( 'yume_get_oeuvre_id' ) ) {
		$id = yume_get_oeuvre_id( $id );
	}
	return $id && 'yume_oeuvre' === get_post_type( $id ) ? $id : 0;
}

/**
 * Données REST pour les scripts (membres seulement).
 */
function donnees_rest(): array {
	if ( ! is_user_logged_in() ) {
		return array(
			'connecte' => false,
		);
	}
	return array(
		'connecte' => true,
		'rest'     => esc_url_raw( rest_url( REST_NS . '/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
	);
}

/**
 * Libellé court d'une position : « Tome 7, chapitre 3 ».
 *
 * @param int $chapitre_id Chapitre.
 */
function libelle_position( int $chapitre_id ): string {
	$tome     = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $chapitre_id ) : '';
	$chapitre = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : '';
	$chapitre = '' !== $chapitre ? mb_strtolower( mb_substr( $chapitre, 0, 1 ) ) . mb_substr( $chapitre, 1 ) : '';
	return implode( ', ', array_filter( array( $tome, $chapitre ), 'strlen' ) );
}

/**
 * Premier chapitre publié d'une œuvre (premier tome publié qui en a).
 *
 * @param int $oeuvre_id Œuvre.
 */
function premier_chapitre( int $oeuvre_id ): int {
	if ( ! function_exists( 'yume_get_tomes' ) ) {
		return 0;
	}
	foreach ( yume_get_tomes( $oeuvre_id ) as $tome ) {
		$chapitres = yume_get_chapitres( (int) $tome->ID );
		if ( $chapitres ) {
			return (int) $chapitres[0]->ID;
		}
	}
	return 0;
}

/**
 * Position enrichie du membre courant pour une œuvre (ou la plus récente), ou null.
 *
 * @param int $oeuvre_id Œuvre (0 : la plus récente, toutes œuvres).
 */
function position_membre( int $oeuvre_id = 0 ): ?array {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || ! function_exists( '\Yume\Core\Reader\enrichir_ligne' ) ) {
		return null;
	}
	foreach ( yume_get_progression( $user_id, $oeuvre_id ) as $ligne ) {
		$enrichie = \Yume\Core\Reader\enrichir_ligne( $ligne );
		if ( $enrichie ) {
			return $enrichie;
		}
	}
	return null;
}

/**
 * Icône SVG décorative.
 *
 * @param string $chemin Tracés SVG (constante).
 * @param string $classe Classe.
 */
function icone( string $chemin, string $classe = 'yn-icone' ): string {
	return '<svg class="' . esc_attr( $classe ) . '" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $chemin . '</svg>';
}

/**
 * Champs communs d'un formulaire admin-post de la fiche œuvre.
 *
 * @param string $action    Action admin-post.
 * @param int    $oeuvre_id Œuvre.
 * @param string $ancre     Ancre de retour.
 */
function champs_action( string $action, int $oeuvre_id, string $ancre = 'yn-oeuvre-actions' ): string {
	return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
		. '<input type="hidden" name="yn_oeuvre" value="' . esc_attr( (string) $oeuvre_id ) . '">'
		. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
		. '<input type="hidden" name="yn_ancre" value="' . esc_attr( $ancre ) . '">'
		. champ_nonce( 'yume_social_' . $oeuvre_id );
}

/*
 * -----------------------------------------------------------------------------
 * yume/oeuvre-actions
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu du bloc yume/oeuvre-actions : Reprendre (ou Commencer), Favori (compteur), Note
 * (moyenne et étoiles), Alerte. Visiteur : invitation à se connecter.
 *
 * @param array          $attributes Attributs.
 * @param \WP_Block|null $bloc       Instance.
 */
function rendu_oeuvre_actions( array $attributes = array(), $bloc = null ): string {
	$oeuvre_id = oeuvre_contexte( $bloc );
	if ( ! $oeuvre_id || ! oeuvre_publiee( $oeuvre_id ) ) {
		return apercu_editeur() ? rendu_apercu( 'yn-oeuvre-actions', __( 'Actions de l’œuvre : Reprendre, Favori, Note, Alerte (visibles sur une fiche œuvre).', 'yume-core' ) ) : '';
	}
	$user_id   = get_current_user_id();
	$connecte  = $user_id > 0;
	$caches    = caches( $oeuvre_id );
	$frequence = $connecte ? frequence_favori( $user_id, $oeuvre_id ) : '';
	$favori    = '' !== $frequence;
	$ma_note   = $connecte ? note( $user_id, $oeuvre_id ) : 0;
	$titre     = wp_strip_all_tags( get_the_title( $oeuvre_id ) );
	$connexion = url_connexion( (string) get_permalink( $oeuvre_id ) );
	$premier   = premier_chapitre( $oeuvre_id );
	$position  = $connecte ? position_membre( $oeuvre_id ) : null;
	$libelles  = libelles_frequences();

	$donnees = array_merge(
		donnees_rest(),
		array(
			'oeuvre'    => $oeuvre_id,
			'frequence' => $favori ? $frequence : null,
			'note'      => $ma_note,
			'libelles'  => $libelles,
		)
	);

	$html  = '<div ' . attributs_racine(
		'yn-oeuvre-actions',
		array(
			'id'              => 'yn-oeuvre-actions',
			'data-yn-actions' => wp_json_encode( $donnees ),
		)
	) . '>';
	$html .= html_messages( messages_courants() );
	$html .= '<div class="yn-oeuvre-actions__ligne">';

	// Reprendre / Commencer.
	$lecture = icone( '<path d="M7 4v16l13-8z"></path>' );
	if ( $position ) {
		$html .= '<a class="yn-btn yn-btn--primary yn-oeuvre-actions__lire" href="' . esc_url( $position['url_reprise'] ) . '">' . $lecture
			/* translators: %s : position (« Tome 7, chapitre 3 »). */
			. '<span>' . esc_html( sprintf( __( 'Reprendre · %s', 'yume-core' ), libelle_position( $position['chapitre_id'] ) ) ) . '</span></a>';
	} else {
		if ( $premier ) {
			$html .= '<a class="yn-btn yn-btn--primary yn-oeuvre-actions__lire" href="' . esc_url( (string) get_permalink( $premier ) ) . '" data-yn-commencer>' . $lecture
				. '<span>' . esc_html__( 'Commencer la lecture', 'yume-core' ) . '</span></a>';
		}
		// Visiteur (ou membre sans position enregistrée) : reprise lue dans localStorage par le script.
		$html .= '<a class="yn-btn yn-btn--primary yn-oeuvre-actions__lire" href="#" data-yn-reprendre hidden>' . $lecture . '<span data-yn-reprendre-texte></span></a>';
	}

	// Favori.
	$coeur = icone( '<path d="M12 21s-7.5-4.6-9.5-9.2C1 8.4 3.1 5 6.6 5c2 0 3.4 1.1 4.4 2.5C12 6.1 13.4 5 15.4 5 18.9 5 21 8.4 19.5 11.8 17.5 16.4 12 21 12 21z"></path>', 'yn-icone yn-oeuvre-actions__coeur' );
	/* translators: %d : nombre de lecteurs ayant l'œuvre en favori. */
	$compte_favoris = sprintf( _n( '%d lecteur l’a en favori', '%d lecteurs l’ont en favori', $caches['favoris'], 'yume-core' ), $caches['favoris'] );
	if ( $connecte ) {
		$html .= '<form class="yn-oeuvre-actions__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-form="favori">'
			. champs_action( 'yume_social_favori', $oeuvre_id )
			. '<input type="hidden" name="yn_faire" value="' . ( $favori ? 'retirer' : 'ajouter' ) . '" data-yn-faire>'
			. '<button type="submit" class="yn-btn yn-oeuvre-actions__favori" aria-pressed="' . ( $favori ? 'true' : 'false' ) . '" data-yn-favori>'
			. $coeur . '<span>' . esc_html__( 'Favori', 'yume-core' ) . '</span><span aria-hidden="true">·</span>'
			. '<span data-yn-compteur>' . esc_html( number_format_i18n( $caches['favoris'] ) ) . '</span>'
			. '<span class="yn-visually-hidden" data-yn-compteur-texte>' . esc_html( ' — ' . $compte_favoris ) . '</span>'
			. '</button></form>';
	} else {
		$html .= '<a class="yn-btn yn-oeuvre-actions__favori" href="' . esc_url( $connexion ) . '">' . $coeur
			. '<span>' . esc_html__( 'Favori', 'yume-core' ) . '</span><span aria-hidden="true">·</span><span>' . esc_html( number_format_i18n( $caches['favoris'] ) ) . '</span>'
			. '<span class="yn-visually-hidden">' . esc_html( ' — ' . $compte_favoris . ' ; connectez-vous pour l’ajouter à vos favoris' ) . '</span></a>';
	}

	// Note : moyenne + étoiles (groupe de boutons radio).
	$etoile  = '<span class="yn-oeuvre-actions__etoile" aria-hidden="true">★</span>';
	$moyenne = $caches['notes'] > 0
		? '<span data-yn-moyenne>' . esc_html( moyenne_fr( $caches['moyenne'] ) ) . '</span> <span class="yn-muted" data-yn-nb-notes>'
			/* translators: %d : nombre de notes. */
			. esc_html( sprintf( _n( '(%d note)', '(%d notes)', $caches['notes'], 'yume-core' ), $caches['notes'] ) ) . '</span>'
		: '<span data-yn-moyenne>' . esc_html__( 'Noter', 'yume-core' ) . '</span> <span class="yn-muted" data-yn-nb-notes></span>';
	if ( $connecte ) {
		$html .= '<details class="yn-oeuvre-actions__menu" data-yn-menu="note"><summary class="yn-btn">' . $etoile . $moyenne
			. '<span class="yn-visually-hidden">' . esc_html__( ' — donner ma note', 'yume-core' ) . '</span></summary>'
			. '<div class="yn-oeuvre-actions__panneau yn-card">'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-form="note">'
			. champs_action( 'yume_social_note', $oeuvre_id )
			. '<fieldset class="yn-etoiles"><legend>' . esc_html__( 'Votre note', 'yume-core' ) . '</legend><div class="yn-etoiles__choix">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$html .= '<label class="yn-etoiles__etoile' . ( $i <= $ma_note ? ' est-pleine' : '' ) . '"><input type="radio" name="yn_note" value="' . $i . '"' . checked( $ma_note, $i, false ) . '>'
				. '<span aria-hidden="true">★</span><span class="yn-visually-hidden">'
				/* translators: %d : nombre d'étoiles. */
				. esc_html( sprintf( _n( '%d étoile sur 5', '%d étoiles sur 5', $i, 'yume-core' ), $i ) ) . '</span></label>';
		}
		$html .= '</div></fieldset>'
			. '<div class="yn-oeuvre-actions__boutons">'
			. '<button type="submit" class="yn-btn yn-btn--primary yn-btn--sm yn-sans-js">' . esc_html__( 'Enregistrer ma note', 'yume-core' ) . '</button>'
			. '<button type="submit" name="yn_retirer" value="1" class="yn-btn yn-btn--sm" data-yn-retirer-note' . ( $ma_note ? '' : ' hidden' ) . '>' . esc_html__( 'Retirer ma note', 'yume-core' ) . '</button>'
			. '</div></form></div></details>';
	} else {
		$html .= '<p class="yn-btn yn-oeuvre-actions__moyenne">' . $etoile . ( $caches['notes'] > 0 ? '<span class="yn-visually-hidden">' . esc_html__( 'Note moyenne :', 'yume-core' ) . ' </span>' . $moyenne : '<span class="yn-muted">' . esc_html__( 'Pas encore noté', 'yume-core' ) . '</span>' ) . '</p>';
	}

	// Alerte (membres).
	if ( $connecte ) {
		$cloche = icone( '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"></path>' );
		$html  .= '<details class="yn-oeuvre-actions__menu" data-yn-menu="alerte"><summary class="yn-btn">' . $cloche
			. '<span data-yn-alerte-resume>' . esc_html( $favori ? sprintf( /* translators: %s : fréquence. */ __( 'Alerte : %s', 'yume-core' ), mb_strtolower( $libelles[ $frequence ] ) ) : __( 'Alerte', 'yume-core' ) ) . '</span></summary>'
			. '<div class="yn-oeuvre-actions__panneau yn-card">'
			. '<p class="yn-muted yn-oeuvre-actions__aide" data-yn-alerte-hors-favori' . ( $favori ? ' hidden' : '' ) . '>' . esc_html__( 'Ajoutez l’œuvre à vos favoris pour recevoir un e-mail à chaque sortie.', 'yume-core' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-form="alerte"' . ( $favori ? '' : ' hidden' ) . '>'
			. champs_action( 'yume_social_alerte', $oeuvre_id )
			. '<fieldset class="yn-oeuvre-actions__frequences"><legend>' . esc_html__( 'Recevoir un e-mail', 'yume-core' ) . '</legend>';
		$aides  = array(
			'immediat' => __( 'à chaque sortie', 'yume-core' ),
			'hebdo'    => __( 'dans le récapitulatif du dimanche', 'yume-core' ),
			'jamais'   => __( 'aucun e-mail pour cette œuvre', 'yume-core' ),
		);
		foreach ( $libelles as $cle => $libelle ) {
			$html .= '<label class="yn-oeuvre-actions__frequence"><input type="radio" name="yn_frequence" value="' . esc_attr( $cle ) . '"' . checked( $frequence, $cle, false ) . '> <span><strong>' . esc_html( $libelle ) . '</strong> <span class="yn-muted">' . esc_html( $aides[ $cle ] ) . '</span></span></label>';
		}
		$html .= '</fieldset><button type="submit" class="yn-btn yn-btn--primary yn-btn--sm yn-sans-js">' . esc_html__( 'Enregistrer l’alerte', 'yume-core' ) . '</button></form>'
			. '</div></details>';
	}
	$html .= '</div>';

	if ( ! $connecte ) {
		$html .= '<p class="yn-oeuvre-actions__invitation yn-muted"><a href="' . esc_url( $connexion ) . '">' . esc_html__( 'Connectez-vous', 'yume-core' ) . '</a> '
			/* translators: %s : titre de l'œuvre. */
			. esc_html( sprintf( __( 'pour ajouter %s à vos favoris, la noter et recevoir une alerte à chaque sortie.', 'yume-core' ), $titre ) ) . '</p>';
	}
	$html .= '<p class="yn-visually-hidden" role="status" aria-live="polite" data-yn-annonce></p>';
	$html .= '</div>';
	return $html;
}

/*
 * -----------------------------------------------------------------------------
 * yume/resume-reading
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu du bloc yume/resume-reading. Membre avec une position : rendu serveur. Sinon, un
 * gabarit masqué que le script remplit depuis localStorage['yn.progression'] (visiteur).
 *
 * @param array $attributes Attributs (layout : bandeau | carte).
 */
function rendu_resume( array $attributes = array() ): string {
	$layout   = in_array( $attributes['layout'] ?? '', array( 'bandeau', 'carte' ), true ) ? $attributes['layout'] : 'carte';
	$position = position_membre( 0 );
	$classe   = 'yn-resume yn-resume--' . $layout . ( 'carte' === $layout ? ' yn-card' : '' );

	if ( $position ) {
		$texte = implode( ' · ', array_filter( array( $position['oeuvre'], $position['tome'], mb_strtolower( $position['chapitre'] ), libelle_part_chapitre( (int) $position['pourcentage'] ) ), 'strlen' ) );
		return '<div ' . attributs_racine( $classe ) . '>' . contenu_resume(
			$layout,
			array(
				'titre'       => $texte,
				'oeuvre'      => $position['oeuvre'],
				'detail'      => implode( ' · ', array_filter( array( $position['tome'], mb_strtolower( $position['chapitre'] ), libelle_part_chapitre( (int) $position['pourcentage'] ) ), 'strlen' ) ),
				'url'         => $position['url_reprise'],
				'pourcentage' => $position['pourcentage'],
				'couverture'  => function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $position['chapitre_id'] ) : 0,
				'position'    => $position['titre'],
			)
		) . '</div>';
	}

	if ( apercu_editeur() ) {
		return rendu_apercu( $classe, __( 'Reprendre la lecture : affiché quand le lecteur a une lecture en cours.', 'yume-core' ) );
	}
	return '<div ' . attributs_racine( $classe, array( 'data-yn-resume' => $layout ) ) . ' hidden>' . contenu_resume(
		$layout,
		array(
			'titre'       => '',
			'oeuvre'      => '',
			'detail'      => '',
			'url'         => '#',
			'pourcentage' => 0,
			'couverture'  => 0,
			'position'    => '',
		)
	) . '</div>';
}

/**
 * Contenu de la reprise (bandeau : surtitre, titre, bouton ; carte : couverture, titre,
 * détail, barre, bouton). Les éléments data-yn-* sont remplis par le script côté visiteur.
 *
 * @param string $layout  bandeau | carte.
 * @param array  $donnees titre, oeuvre, detail, url, pourcentage, couverture, position.
 */
function contenu_resume( string $layout, array $donnees ): string {
	$bouton = '<a class="yn-btn yn-btn--primary yn-resume__bouton" href="' . esc_url( $donnees['url'] ) . '" data-yn-resume-lien>'
		. esc_html__( 'Continuer', 'yume-core' )
		. '<span class="yn-visually-hidden" data-yn-resume-position>' . esc_html( '' !== $donnees['position'] ? ' : ' . $donnees['position'] : '' ) . '</span></a>';
	if ( 'bandeau' === $layout ) {
		return '<div class="yn-resume__texte"><p class="yn-label">' . esc_html__( 'Reprendre la lecture', 'yume-core' ) . '</p>'
			. '<p class="yn-resume__titre" data-yn-resume-titre>' . esc_html( $donnees['titre'] ) . '</p></div>' . $bouton;
	}
	$couverture = $donnees['couverture'] ? yume_image_couverture(
		(int) $donnees['couverture'],
		'yume-couverture',
		array(
			'alt'     => '',
			'loading' => 'lazy',
		)
	) : '';
	return '<div class="yn-cover yn-resume__couverture" aria-hidden="true">' . ( $couverture ? $couverture : '<span data-yn-resume-initiales>' . esc_html( mb_substr( $donnees['oeuvre'], 0, 2 ) ) . '</span>' ) . '</div>'
		. '<div class="yn-resume__corps"><p class="yn-label">' . esc_html__( 'Reprendre la lecture', 'yume-core' ) . '</p>'
		. '<p class="yn-resume__oeuvre" data-yn-resume-oeuvre>' . esc_html( $donnees['oeuvre'] ) . '</p>'
		. '<p class="yn-resume__detail yn-muted" data-yn-resume-detail>' . esc_html( $donnees['detail'] ) . '</p>'
		. '<span class="yn-bar" aria-hidden="true"><span style="--v:' . esc_attr( (string) (int) $donnees['pourcentage'] ) . '%" data-yn-resume-barre></span></span>'
		. $bouton . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/auth-links
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu du bloc yume/auth-links : « Connexion » (visiteur) ou « Mon compte » suivi de
 * « Se déconnecter », précédés de « Espace équipe » pour qui a la capacité yume_voir_equipe.
 */
function rendu_auth_links(): string {
	$html = '<div ' . attributs_racine( 'yn-auth' ) . '>';
	if ( ! is_user_logged_in() ) {
		$courante = apercu_editeur() ? home_url( '/' ) : url_courante();
		$html    .= '<a class="yn-btn yn-btn--primary yn-btn--sm" href="' . esc_url( url_connexion( $courante ) ) . '">' . esc_html__( 'Connexion', 'yume-core' ) . '</a>';
		return $html . '</div>';
	}
	$page_courante = (int) get_queried_object_id();
	$pages         = get_option( 'yume_pages', array() );
	$pages         = is_array( $pages ) ? $pages : array();
	if ( current_user_can( 'yume_voir_equipe' ) ) {
		$equipe = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/equipe/' );
		$actif  = ! empty( $pages['equipe'] ) && (int) $pages['equipe'] === $page_courante;
		$html  .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( $equipe ) . '"' . ( $actif ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Espace équipe', 'yume-core' ) . '</a>';
	}
	$actif = ( ! empty( $pages['compte'] ) && (int) $pages['compte'] === $page_courante ) || ( ! empty( $pages['connexion'] ) && (int) $pages['connexion'] === $page_courante );
	$html .= '<a class="yn-btn yn-btn--primary yn-btn--sm" href="' . esc_url( url_compte() ) . '"' . ( $actif ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Mon compte', 'yume-core' ) . '</a>';
	// Déconnexion (SCAN-18) : retour à l'accueil, la page courante pouvant être réservée.
	// Sur un bureau étroit, seule l'icône reste visible (le libellé reste lu).
	$icone = '<svg class="yn-auth__icone" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
		. '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/></svg>';
	$html .= '<a class="yn-auth__deconnexion" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '" title="' . esc_attr__( 'Se déconnecter', 'yume-core' ) . '">'
		. $icone . '<span class="yn-auth__libelle">' . esc_html__( 'Se déconnecter', 'yume-core' ) . '</span></a>';
	return $html . '</div>';
}
