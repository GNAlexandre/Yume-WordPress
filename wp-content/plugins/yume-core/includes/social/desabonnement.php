<?php
/**
 * Désabonnement en un clic depuis les e-mails d'alerte (SCAN-20).
 *
 * Chaque e-mail d'alerte porte en pied un lien signé propre au destinataire : il coupe les
 * alertes d'une œuvre (fréquence « jamais »), les réponses aux commentaires ou toutes les
 * alertes, sans connexion. La signature est un HMAC (sel « auth » du site) du membre, de la
 * portée, de l'œuvre et d'un jeton secret propre au membre (méta _yume_jeton_desabonnement,
 * effacée avec ses données) : changer ce jeton invalide tous ses anciens liens.
 *
 * Ouvrir le lien (GET) ne change rien : un antivirus de messagerie qui précharge les liens ne
 * doit pas désabonner le lecteur. Une confirmation s'affiche, avec un bouton qui renvoie en POST
 * les mêmes paramètres signés. La requête POST « List-Unsubscribe=One-Click » des messageries
 * (RFC 8058), annoncée par les en-têtes List-Unsubscribe et List-Unsubscribe-Post ajoutés aux
 * e-mails qui portent ce lien, s'applique directement. Idempotent : confirmer deux fois donne le
 * même résultat.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Portées d'un lien de désabonnement. */
const PORTEES_DESABO = array( 'oeuvre', 'commentaires', 'tout' );

/**
 * Jeton secret du membre pour ses liens de désabonnement (créé au premier besoin).
 *
 * @param int $user_id Membre.
 */
function jeton_desabonnement( int $user_id ): string {
	$jeton = (string) get_user_meta( $user_id, META_JETON_DESABO, true );
	if ( '' === $jeton ) {
		$jeton = wp_generate_password( 24, false, false );
		update_user_meta( $user_id, META_JETON_DESABO, $jeton );
	}
	return $jeton;
}

/**
 * Signature d'un lien de désabonnement.
 *
 * @param int    $user_id   Membre.
 * @param string $portee    oeuvre, commentaires ou tout.
 * @param int    $oeuvre_id Œuvre (0 hors portée « oeuvre »).
 */
function signature_desabonnement( int $user_id, string $portee, int $oeuvre_id ): string {
	$message = 'yume-desabo|' . $user_id . '|' . $portee . '|' . $oeuvre_id . '|' . jeton_desabonnement( $user_id );
	return substr( hash_hmac( 'sha256', $message, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Adresse du lien de désabonnement en un clic.
 *
 * @param int    $user_id   Membre.
 * @param string $portee    oeuvre, commentaires ou tout.
 * @param int    $oeuvre_id Œuvre (portée « oeuvre »).
 */
function url_desabonnement( int $user_id, string $portee, int $oeuvre_id = 0 ): string {
	$portee    = in_array( $portee, PORTEES_DESABO, true ) ? $portee : 'tout';
	$oeuvre_id = 'oeuvre' === $portee ? max( 0, $oeuvre_id ) : 0;
	return add_query_arg(
		url_params_desabonnement(
			array(
				'user_id'   => $user_id,
				'portee'    => $portee,
				'oeuvre_id' => $oeuvre_id,
			)
		),
		home_url( '/' )
	);
}

/**
 * Paramètres signés d'un lien de désabonnement.
 *
 * @param array{user_id:int,portee:string,oeuvre_id:int} $lien Membre, portée, œuvre.
 * @return array<string,string|int>
 */
function url_params_desabonnement( array $lien ): array {
	return array(
		'yn-desabo' => (int) $lien['user_id'],
		'yn-portee' => (string) $lien['portee'],
		'yn-oeuvre' => (int) $lien['oeuvre_id'],
		'yn-sig'    => signature_desabonnement( (int) $lien['user_id'], (string) $lien['portee'], (int) $lien['oeuvre_id'] ),
	);
}

/**
 * Lien de désabonnement à placer dans un corps d'e-mail partagé par plusieurs destinataires :
 * l'adresse est un marqueur (#yn-desabo-portée-œuvre) que envoyer_email() remplace par le lien
 * signé du destinataire.
 *
 * @param string $portee    oeuvre, commentaires ou tout.
 * @param int    $oeuvre_id Œuvre (portée « oeuvre »).
 * @param string $texte     Libellé du lien.
 */
function lien_desabonnement( string $portee, int $oeuvre_id, string $texte ): string {
	return '<a href="#yn-desabo-' . esc_attr( $portee ) . '-' . (int) $oeuvre_id . '">' . esc_html( $texte ) . '</a>';
}

/**
 * Remplace les marqueurs de désabonnement d'un corps d'e-mail par les liens signés du
 * destinataire.
 *
 * @param string $corps   Corps HTML.
 * @param int    $user_id Destinataire.
 */
function personnaliser_desabonnement( string $corps, int $user_id ): string {
	return (string) preg_replace_callback(
		'/href="#yn-desabo-(oeuvre|commentaires|tout)-(\d+)"/',
		static function ( array $m ) use ( $user_id ): string {
			return 'href="' . esc_url( url_desabonnement( $user_id, $m[1], (int) $m[2] ) ) . '"';
		},
		$corps
	);
}

/**
 * Applique un désabonnement (sans effet s'il est déjà appliqué).
 *
 * - oeuvre : fréquence « jamais » pour ce favori (rien si l'œuvre n'est plus en favori) ;
 * - commentaires : préférence « Réponses à mes commentaires » coupée ;
 * - tout : tous les favoris en « jamais » et les trois préférences d'alerte coupées.
 *
 * @param int    $user_id   Membre.
 * @param string $portee    oeuvre, commentaires ou tout.
 * @param int    $oeuvre_id Œuvre (portée « oeuvre »).
 * @return bool Faux si la portée est inconnue ou le membre absent.
 */
function desabonner( int $user_id, string $portee, int $oeuvre_id = 0 ): bool {
	global $wpdb;
	if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! in_array( $portee, PORTEES_DESABO, true ) ) {
		return false;
	}
	if ( 'commentaires' === $portee ) {
		enregistrer_preferences( $user_id, array( 'commentaires' => false ) );
		return true;
	}
	if ( 'tout' === $portee ) {
		enregistrer_preferences(
			$user_id,
			array(
				'sorties'      => false,
				'hebdo'        => false,
				'commentaires' => false,
			)
		);
	}
	if ( ! tables_pretes() ) {
		return true;
	}
	$conditions = array( 'user_id' => $user_id );
	$formats    = array( '%d' );
	if ( 'oeuvre' === $portee ) {
		if ( $oeuvre_id <= 0 ) {
			return false;
		}
		$conditions['oeuvre_id'] = $oeuvre_id;
		$formats[]               = '%d';
	}
	// Mise à jour directe : l'œuvre a pu être dépubliée depuis l'envoi de l'e-mail.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update( table_favoris(), array( 'frequence' => 'jamais' ), $conditions, array( '%s' ), $formats );
	return true;
}

/**
 * Lien de désabonnement de la requête courante, s'il est présent et correctement signé.
 *
 * @return array{user_id:int,portee:string,oeuvre_id:int}|null|false Null sans lien, faux si invalide.
 */
function lien_desabonnement_courant() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lien signé (HMAC) propre au membre.
	if ( ! isset( $_GET['yn-desabo'] ) ) {
		return null;
	}
	$user_id   = absint( wp_unslash( $_GET['yn-desabo'] ) );
	$portee    = isset( $_GET['yn-portee'] ) ? sanitize_key( wp_unslash( $_GET['yn-portee'] ) ) : '';
	$oeuvre_id = isset( $_GET['yn-oeuvre'] ) ? absint( wp_unslash( $_GET['yn-oeuvre'] ) ) : 0;
	$signature = isset( $_GET['yn-sig'] ) ? sanitize_text_field( wp_unslash( $_GET['yn-sig'] ) ) : '';
	// phpcs:enable
	if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! in_array( $portee, PORTEES_DESABO, true ) || '' === $signature ) {
		return false;
	}
	if ( ( 'oeuvre' === $portee ) !== ( $oeuvre_id > 0 ) ) {
		return false;
	}
	// Pas de jeton : aucun lien n'a jamais été émis pour ce membre (ne pas en créer un ici).
	if ( '' === (string) get_user_meta( $user_id, META_JETON_DESABO, true ) ) {
		return false;
	}
	if ( ! hash_equals( signature_desabonnement( $user_id, $portee, $oeuvre_id ), $signature ) ) {
		return false;
	}
	return array(
		'user_id'   => $user_id,
		'portee'    => $portee,
		'oeuvre_id' => $oeuvre_id,
	);
}

/**
 * Description du désabonnement proposé par un lien (texte de la confirmation).
 *
 * @param array{user_id:int,portee:string,oeuvre_id:int} $lien Lien vérifié.
 */
function description_desabonnement( array $lien ): string {
	if ( 'oeuvre' === $lien['portee'] ) {
		$titre = texte_brut( (string) get_the_title( $lien['oeuvre_id'] ) );
		/* translators: %s : titre de l'œuvre. */
		return '' !== $titre ? sprintf( __( 'Ne plus être alerté pour « %s »', 'yume-core' ), $titre ) : __( 'Ne plus être alerté pour cette œuvre', 'yume-core' );
	}
	if ( 'commentaires' === $lien['portee'] ) {
		return __( 'Ne plus recevoir d’e-mail quand on répond à mes commentaires', 'yume-core' );
	}
	return __( 'Ne plus recevoir aucune alerte e-mail de Yume Novel', 'yume-core' );
}

/**
 * Confirmation d'un lien de désabonnement ouvert (GET) : rien n'est encore appliqué ; un seul
 * bouton renvoie en POST les mêmes paramètres signés. Vide sans lien valide dans l'adresse.
 * Affichée par la page compte (zone des messages), sinon par une page minimale.
 */
function confirmation_desabonnement(): string {
	$lien = lien_desabonnement_courant();
	if ( ! is_array( $lien ) ) {
		return '';
	}
	return '<div class="yn-avis yn-avis--info yn-desabo" role="status">'
		. '<p class="yn-desabo__texte">' . esc_html__( 'Confirmez-vous ce désabonnement ?', 'yume-core' ) . ' <strong>' . esc_html( description_desabonnement( $lien ) ) . '</strong></p>'
		. '<form class="yn-desabo__formulaire" method="post" action="' . esc_url( url_desabonnement( $lien['user_id'], $lien['portee'], $lien['oeuvre_id'] ) ) . '">'
		. '<input type="hidden" name="yn-confirmer" value="1">'
		. '<button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Confirmer le désabonnement', 'yume-core' ) . '</button>'
		. '</form></div>';
}

/**
 * La requête courante affiche-t-elle la page compte ?
 */
function sur_page_compte(): bool {
	$pages = get_option( 'yume_pages', array() );
	return is_array( $pages ) && ! empty( $pages['compte'] ) && (int) get_queried_object_id() === (int) $pages['compte'];
}

/**
 * Traite un lien de désabonnement.
 *
 * - GET (lien ouvert, éventuellement par un antivirus de messagerie qui précharge les liens) :
 *   aucun changement, confirmation avec un bouton (page compte, sinon page minimale) ;
 * - POST (ce bouton, ou le désabonnement « One-Click » RFC 8058 d'une messagerie) : appliqué ;
 *   le bouton ramène à la page compte avec un message, la messagerie reçoit une réponse brève.
 *
 * Sans connexion, pas de nonce possible : la signature HMAC du lien et la méthode POST suffisent.
 */
function traiter_desabonnement(): void {
	$lien = lien_desabonnement_courant();
	if ( null === $lien ) {
		return;
	}
	$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	nocache_headers();
	if ( 'POST' !== $methode ) {
		if ( ! is_array( $lien ) ) {
			if ( page_enregistree( 'compte' ) ) {
				rediriger( url_compte(), 'desabo-invalide' );
			}
			wp_die( esc_html( messages()['desabo-invalide'][1] ), esc_html__( 'Alertes e-mail', 'yume-core' ), array( 'response' => 400 ) );
		}
		if ( page_enregistree( 'compte' ) ) {
			if ( ! sur_page_compte() ) {
				// La page compte affiche la confirmation (zone des messages du bloc yume/account).
				wp_safe_redirect( add_query_arg( url_params_desabonnement( $lien ), url_compte() ) );
				exit;
			}
			return;
		}
		// Site sans page compte : confirmation minimale plutôt qu'une page introuvable.
		wp_die( confirmation_desabonnement(), esc_html__( 'Alertes e-mail', 'yume-core' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé par confirmation_desabonnement().
	}

	$code = 'desabo-invalide';
	if ( is_array( $lien ) && desabonner( $lien['user_id'], $lien['portee'], $lien['oeuvre_id'] ) ) {
		$code = 'desabo-' . $lien['portee'];
		/**
		 * Un membre s'est désabonné par le lien d'un e-mail.
		 *
		 * @param int    $user_id   Membre.
		 * @param string $portee    oeuvre, commentaires ou tout.
		 * @param int    $oeuvre_id Œuvre (portée « oeuvre »), 0 sinon.
		 */
		do_action( 'yume_desabonnement', $lien['user_id'], $lien['portee'], $lien['oeuvre_id'] );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- lien signé (HMAC), membre non connecté.
	$bouton = isset( $_POST['yn-confirmer'] );
	if ( ! $bouton ) {
		// Désabonnement « One-Click » d'une messagerie : pas de redirection, une réponse brève.
		status_header( is_array( $lien ) ? 200 : 400 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( messages()[ $code ][1] );
		exit;
	}
	$connecte = is_user_logged_in() && is_array( $lien ) && get_current_user_id() === $lien['user_id'];
	if ( page_enregistree( 'compte' ) ) {
		rediriger( url_compte(), $code, $connecte ? 'yn-alertes' : '' );
	}
	wp_die(
		esc_html( messages()[ $code ][1] ),
		esc_html__( 'Alertes e-mail', 'yume-core' ),
		array(
			'response'  => is_array( $lien ) ? 200 : 400,
			'link_url'  => esc_url( home_url( '/' ) ),
			'link_text' => esc_html__( 'Retour à l’accueil', 'yume-core' ),
		)
	);
}
add_action( 'template_redirect', __NAMESPACE__ . '\\traiter_desabonnement', 4 );

/**
 * En-têtes List-Unsubscribe (RFC 2369 et 8058) des e-mails qui portent un lien signé de
 * désabonnement : le premier lien du pied (le plus précis) sert de lien « One-Click ».
 * Filtre wp_mail : couvre l'envoi direct comme la file d'e-mails du module planning.
 *
 * @param array $args Arguments de wp_mail().
 * @return array
 */
function entetes_desabonnement( $args ) {
	if ( ! is_array( $args ) || empty( $args['message'] ) || ! is_string( $args['message'] ) ) {
		return $args;
	}
	if ( ! preg_match( '/href="([^"]*[?&](?:amp;|#038;)?yn-desabo=[^"]+)"/', $args['message'], $m ) ) {
		return $args;
	}
	$url     = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$url     = str_replace( '&#038;', '&', $url );
	$entetes = $args['headers'] ?? array();
	if ( ! is_array( $entetes ) ) {
		$entetes = '' === trim( (string) $entetes ) ? array() : preg_split( "/\r\n|\n/", (string) $entetes );
	}
	foreach ( $entetes as $entete ) {
		if ( 0 === stripos( ltrim( (string) $entete ), 'List-Unsubscribe:' ) ) {
			return $args;
		}
	}
	$entetes[]       = 'List-Unsubscribe: <' . esc_url_raw( $url ) . '>';
	$entetes[]       = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
	$args['headers'] = $entetes;
	return $args;
}
add_filter( 'wp_mail', __NAMESPACE__ . '\\entetes_desabonnement' );
