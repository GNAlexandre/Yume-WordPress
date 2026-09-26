<?php
/**
 * Commentaires : formulaire et liens de réponse entièrement en français.
 *
 * Les chaînes sont posées ici plutôt que laissées à la traduction de WordPress pour que
 * l'interface reste française même si la langue du site ou du profil diffère.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Champs du formulaire de commentaire pour les visiteurs non connectés.
 *
 * Le champ « Site web » est retiré : il n'apporte rien aux lecteurs et attire le spam.
 *
 * @param array $champs Champs par défaut.
 * @return array
 */
function yume_theme_champs_commentaire( $champs ) {
	$commentateur = wp_get_current_commenter();
	$requis       = (bool) get_option( 'require_name_email' );
	$attr_requis  = $requis ? ' required' : '';
	$marque       = $requis ? ' <span class="required" aria-hidden="true">*</span>' : '';

	$nouveaux = array(
		'author' => sprintf(
			'<p class="comment-form-author"><label for="author">%1$s%2$s</label><input id="author" name="author" type="text" value="%3$s" size="30" maxlength="245" autocomplete="nickname"%4$s></p>',
			esc_html__( 'Pseudo', 'yume' ),
			$marque,
			esc_attr( $commentateur['comment_author'] ),
			$attr_requis
		),
		'email'  => sprintf(
			'<p class="comment-form-email"><label for="email">%1$s%2$s</label><input id="email" name="email" type="email" value="%3$s" size="30" maxlength="100" autocomplete="email" aria-describedby="email-notes"%4$s></p>',
			esc_html__( 'Adresse e-mail', 'yume' ),
			$marque,
			esc_attr( $commentateur['comment_author_email'] ),
			$attr_requis
		),
	);

	if ( isset( $champs['cookies'] ) ) {
		$coche               = empty( $commentateur['comment_author_email'] ) ? '' : ' checked';
		$nouveaux['cookies'] = sprintf(
			'<p class="comment-form-cookies-consent"><input id="wp-comment-cookies-consent" name="wp-comment-cookies-consent" type="checkbox" value="yes"%1$s> <label for="wp-comment-cookies-consent">%2$s</label></p>',
			$coche,
			esc_html__( 'Retenir mon pseudo et mon e-mail dans ce navigateur pour mon prochain commentaire.', 'yume' )
		);
	}

	return $nouveaux;
}
add_filter( 'comment_form_default_fields', 'yume_theme_champs_commentaire' );

/**
 * Libellés du formulaire de commentaire.
 *
 * @param array $reglages Réglages par défaut de comment_form().
 * @return array
 */
function yume_theme_formulaire_commentaire( $reglages ) {
	$requis = (bool) get_option( 'require_name_email' );

	$reglages['title_reply'] = esc_html__( 'Laisser un commentaire', 'yume' );
	/* translators: %s : auteur du commentaire auquel on répond. */
	$reglages['title_reply_to']       = esc_html__( 'Répondre à %s', 'yume' );
	$reglages['cancel_reply_link']    = esc_html__( 'Annuler la réponse', 'yume' );
	$reglages['label_submit']         = esc_html__( 'Publier', 'yume' );
	$reglages['title_reply_before']   = '<h3 id="reply-title" class="comment-reply-title">';
	$reglages['title_reply_after']    = '</h3>';
	$reglages['class_submit']         = 'submit wp-element-button';
	$reglages['comment_notes_before'] = sprintf(
		'<p class="comment-notes"><span id="email-notes">%1$s</span>%2$s</p>',
		esc_html__( 'Votre adresse e-mail ne sera pas publiée.', 'yume' ),
		$requis ? ' <span class="required-field-message">' . esc_html__( 'Les champs obligatoires sont marqués d’un astérisque.', 'yume' ) . '</span>' : ''
	);
	$reglages['comment_field']        = sprintf(
		'<p class="comment-form-comment"><label for="comment">%1$s <span class="required" aria-hidden="true">*</span></label><textarea id="comment" name="comment" cols="45" rows="6" maxlength="65525" required></textarea></p>',
		esc_html__( 'Votre commentaire', 'yume' )
	);
	$reglages['must_log_in']          = sprintf(
		'<p class="must-log-in">%s</p>',
		sprintf(
			/* translators: %s : adresse de la page de connexion. */
			wp_kses( __( 'Vous devez <a href="%s">vous connecter</a> pour publier un commentaire.', 'yume' ), array( 'a' => array( 'href' => array() ) ) ),
			esc_url( wp_login_url( yume_theme_url_courante() ) )
		)
	);

	$utilisateur = wp_get_current_user();
	if ( $utilisateur->exists() ) {
		$reglages['logged_in_as'] = sprintf(
			'<p class="logged-in-as">%1$s <a href="%2$s">%3$s</a></p>',
			sprintf(
				/* translators: %s : nom affiché de l'utilisateur connecté. */
				esc_html__( 'Connecté en tant que %s.', 'yume' ),
				'<strong>' . esc_html( $utilisateur->display_name ) . '</strong>'
			),
			esc_url( wp_logout_url( yume_theme_url_courante() ) ),
			esc_html__( 'Se déconnecter', 'yume' )
		);
	}

	return $reglages;
}
add_filter( 'comment_form_defaults', 'yume_theme_formulaire_commentaire' );

/**
 * Textes du lien « Répondre » d'un commentaire.
 *
 * @param array $arguments Arguments de get_comment_reply_link().
 * @return array
 */
function yume_theme_lien_reponse( $arguments ) {
	$arguments['reply_text'] = esc_html__( 'Répondre', 'yume' );
	/* translators: %s : auteur du commentaire. */
	$arguments['reply_to_text'] = esc_html__( 'Répondre à %s', 'yume' );
	$arguments['login_text']    = esc_html__( 'Connectez-vous pour répondre', 'yume' );
	return $arguments;
}
add_filter( 'comment_reply_link_args', 'yume_theme_lien_reponse' );

/**
 * Texte du lien d'annulation de réponse.
 *
 * @param string $lien Lien HTML complet.
 * @return string
 */
function yume_theme_lien_annuler_reponse( $lien ) {
	return (string) preg_replace( '#(<a\b[^>]*>).*?(</a>)#s', '$1' . esc_html__( 'Annuler la réponse', 'yume' ) . '$2', (string) $lien, 1 );
}
add_filter( 'cancel_comment_reply_link', 'yume_theme_lien_annuler_reponse' );
