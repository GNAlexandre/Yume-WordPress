<?php
/**
 * Mon compte, rubrique « Connexion Discord » (filtres yume_compte_rubriques et
 * yume_compte_sections_html, après « Profil et sécurité ») : état de la liaison, « Lier mon compte
 * Discord », « Délier » (admin-post yume_discord_delier, nonce, mot de passe actuel exigé, refusé
 * tant que le compte n'a pas de mot de passe défini). Rubrique absente pour un lecteur tant que la
 * connexion n'est pas configurée ; l'équipe y lit « non configurée ».
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

/** Ancre après laquelle la rubrique est insérée. */
const ANCRE_PRECEDENTE = 'yn-profil';

/**
 * La rubrique est-elle affichée à ce membre ?
 *
 * @param int $user_id Membre connecté.
 */
function rubrique_visible( int $user_id ): bool {
	if ( $user_id <= 0 || ! social_disponible() ) {
		return false;
	}
	return discord_configure() || ( function_exists( '\Yume\Core\Social\est_equipe' ) && \Yume\Core\Social\est_equipe( $user_id ) );
}

/**
 * Insère une entrée après une clé connue (à la fin si elle est absente).
 *
 * @param array  $tableau Tableau ordonné.
 * @param string $apres   Clé précédente.
 * @param string $cle     Nouvelle clé.
 * @param mixed  $valeur  Valeur.
 * @return array
 */
function inserer_apres( array $tableau, string $apres, string $cle, $valeur ): array {
	unset( $tableau[ $cle ] );
	if ( ! array_key_exists( $apres, $tableau ) ) {
		$tableau[ $cle ] = $valeur;
		return $tableau;
	}
	$sortie = array();
	foreach ( $tableau as $k => $v ) {
		$sortie[ $k ] = $v;
		if ( $k === $apres ) {
			$sortie[ $cle ] = $valeur;
		}
	}
	return $sortie;
}

/**
 * Rubrique « Connexion Discord » de la navigation du compte.
 *
 * @param array $rubriques Rubriques (ancre => libellé).
 * @param int   $user_id   Membre connecté.
 * @return array
 */
function rubriques( $rubriques, $user_id = 0 ) {
	if ( ! is_array( $rubriques ) || ! rubrique_visible( (int) $user_id ) ) {
		return $rubriques;
	}
	return inserer_apres( $rubriques, ANCRE_PRECEDENTE, ANCRE_COMPTE, __( 'Connexion Discord', 'yume-core' ) );
}
add_filter( 'yume_compte_rubriques', __NAMESPACE__ . '\\rubriques', 10, 2 );

/**
 * Section « Connexion Discord » de la page compte.
 *
 * @param array    $sections Sections (ancre => HTML).
 * @param \WP_User $user     Membre connecté.
 * @return array
 */
function sections( $sections, $user = null ) {
	if ( ! is_array( $sections ) || ! $user instanceof \WP_User || ! rubrique_visible( (int) $user->ID ) ) {
		return $sections;
	}
	return inserer_apres( $sections, ANCRE_PRECEDENTE, ANCRE_COMPTE, section_html( $user ) );
}
add_filter( 'yume_compte_sections_html', __NAMESPACE__ . '\\sections', 10, 2 );

/**
 * HTML de la section.
 *
 * @param \WP_User $user Membre connecté.
 */
function section_html( \WP_User $user ): string {
	$html  = \Yume\Core\Social\debut_section( ANCRE_COMPTE, __( 'Connexion Discord', 'yume-core' ), __( 'Connexion en un clic avec votre compte Discord', 'yume-core' ) );
	$html .= '<div class="yn-card yn-account__bloc yn-discord yn-discord--compte">';
	if ( ! discord_configure() ) {
		$html .= '<p>' . esc_html__( 'La connexion avec Discord n’est pas configurée sur le site.', 'yume-core' ) . '</p>';
		if ( user_can( $user, 'yume_reglages' ) || user_can( $user, 'manage_options' ) ) {
			$html .= '<p class="yn-muted yn-account__note">' . esc_html__( 'Elle s’active dans Yume → Réglages, section « Connexion Discord » (Client ID et Client Secret de l’application Discord).', 'yume-core' ) . '</p>';
		}
		return $html . '</div></section>';
	}

	$code = code_courant();
	if ( in_array( $code, array( 'lie', 'delie', 'deja-lie', 'mdp', 'reserve', 'expire', 'refuse', 'limite', 'erreur' ), true ) ) {
		$html .= message_html( $code );
	}
	$discord_id = discord_lie( (int) $user->ID );
	$autorisee  = connexion_autorisee( $user );
	$ici        = page_courante();

	if ( '' !== $discord_id ) {
		$pseudo = (string) get_user_meta( $user->ID, META_PSEUDO, true );
		$le     = (string) get_user_meta( $user->ID, META_LIE_LE, true );
		$date   = '' !== $le ? wp_date( 'j F Y', (int) strtotime( $le . ' UTC' ) ) : '';
		$nom    = '' !== $pseudo ? '@' . $pseudo : __( 'un compte Discord', 'yume-core' );
		$html  .= '<p>' . ( '' !== $date
			/* translators: 1 : nom Discord (@pseudo), 2 : date de la liaison. */
			? sprintf( esc_html__( 'Lié à %1$s depuis le %2$s.', 'yume-core' ), '<strong>' . esc_html( $nom ) . '</strong>', esc_html( (string) $date ) )
			/* translators: %s : nom Discord (@pseudo). */
			: sprintf( esc_html__( 'Lié à %s.', 'yume-core' ), '<strong>' . esc_html( $nom ) . '</strong>' ) )
			. ' ' . ( $autorisee
				? esc_html__( 'Vous pouvez vous connecter avec Discord ou avec votre mot de passe.', 'yume-core' )
				: esc_html__( 'Les administrateurs se connectent par la page de connexion WordPress : Discord ne permet pas d’ouvrir ce compte.', 'yume-core' ) )
			. '</p>';
		if ( \Yume\Core\Social\compte_sans_mot_de_passe( (int) $user->ID ) ) {
			$html .= '<p class="yn-avis yn-avis--info">' . esc_html__( 'Votre compte a été créé avec Discord et n’a pas encore de mot de passe. Pour pouvoir délier Discord, définissez-en un : déconnectez-vous, puis utilisez « Mot de passe oublié ? » avec votre adresse e-mail.', 'yume-core' ) . '</p>'
				. '<p><a class="yn-btn" href="' . esc_url( wp_logout_url( wp_lostpassword_url() ) ) . '">' . esc_html__( 'Se déconnecter et définir un mot de passe', 'yume-core' ) . '</a></p>';
		} else {
			$html .= '<form class="yn-account__formulaire" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="yume_discord_delier">'
				. '<input type="hidden" name="yn_retour" value="' . esc_url( $ici ) . '">'
				. '<input type="hidden" name="_yn_nonce" value="' . esc_attr( wp_create_nonce( 'yume_discord_delier' ) ) . '">'
				. '<p class="yn-account__champ"><label for="yn-discord-mdp">' . esc_html__( 'Mot de passe actuel', 'yume-core' ) . '</label>'
				. '<input type="password" id="yn-discord-mdp" name="yn_mdp_actuel" required autocomplete="current-password"></p>'
				. '<div class="yn-account__boutons"><button type="submit" class="yn-btn">' . esc_html__( 'Délier', 'yume-core' ) . '</button></div>'
				. '</form>';
		}
		return $html . '</div></section>';
	}

	if ( ! $autorisee ) {
		$html .= '<p>' . esc_html__( 'Les administrateurs se connectent par la page de connexion WordPress : la connexion avec Discord n’est pas proposée pour ce compte.', 'yume-core' ) . '</p>';
		return $html . '</div></section>';
	}
	$html .= '<p>' . esc_html__( 'Non lié. Liez votre compte Discord pour vous connecter en un clic.', 'yume-core' ) . '</p>'
		. bouton_html( 'liaison', '', $ici );
	return $html . '</div></section>';
}

/**
 * Retour sur la page compte avec un code de message (yn-discord) ou un code du module social
 * (yn-msg), puis arrêt.
 *
 * @param string $retour Page.
 * @param string $code   Code yn-discord ('' : aucun).
 * @param string $msg    Code yn-msg ('' : aucun).
 */
function retour_compte( string $retour, string $code, string $msg = '' ): void {
	$url = url_message( $retour, $code );
	if ( '' !== $msg ) {
		$url = add_query_arg( 'yn-msg', $msg, $url );
	}
	aller( $url . '#' . ANCRE_COMPTE );
}

/**
 * « Délier » (admin-post yume_discord_delier) : membre connecté, nonce, compte avec un mot de passe
 * défini, mot de passe actuel correct (5 essais par heure et par compte).
 */
function traiter_delier(): void {
	nocache_headers();
	$retour = champ( 'yn_retour' );
	$retour = adresse_locale( '' !== $retour ? $retour : (string) wp_get_referer(), url_compte() );
	if ( ! is_user_logged_in() ) {
		aller( url_connexion() );
	}
	if ( ! nonce_valide( 'yume_discord_delier' ) ) {
		retour_compte( $retour, '', 'session' );
	}
	$user = wp_get_current_user();
	if ( '' === discord_lie( (int) $user->ID ) ) {
		retour_compte( $retour, '' );
	}
	if ( \Yume\Core\Social\compte_sans_mot_de_passe( (int) $user->ID ) ) {
		retour_compte( $retour, 'mdp' );
	}
	if ( \Yume\Core\Social\limite_atteinte( 'discord-delier', 5, HOUR_IN_SECONDS, 'u' . $user->ID ) ) {
		retour_compte( $retour, 'limite' );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- mot de passe brut, jamais affiché ni stocké ; nonce vérifié par nonce_valide().
	$mot_de_passe = isset( $_POST['yn_mdp_actuel'] ) && is_string( $_POST['yn_mdp_actuel'] ) ? (string) wp_unslash( $_POST['yn_mdp_actuel'] ) : '';
	if ( '' === $mot_de_passe || ! wp_check_password( $mot_de_passe, $user->user_pass, $user->ID ) ) {
		retour_compte( $retour, '', 'mdp-actuel' );
	}
	retour_compte( $retour, delier( (int) $user->ID ) ? 'delie' : 'erreur' );
}
add_action( 'admin_post_yume_discord_delier', __NAMESPACE__ . '\\traiter_delier' );
