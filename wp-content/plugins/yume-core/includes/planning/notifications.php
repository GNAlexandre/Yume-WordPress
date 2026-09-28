<?php
/**
 * Notifications : file d'e-mails (table notifications) envoyée par lots toutes les 5 minutes
 * dans le gabarit HTML Yume, trois tentatives au plus, purge des envois de plus de 30 jours ;
 * webhooks Discord ; journal des échecs pour les gérants.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Journal des échecs
 * -----------------------------------------------------------------------------
 */

/**
 * Note un échec d'envoi (20 derniers conservés, sans erreur ni notice).
 *
 * @param string $type    'discord' ou 'email'.
 * @param string $message Détail.
 */
function noter_echec( string $type, string $message ): void {
	$echecs = get_option( OPTION_ECHECS, array() );
	$echecs = is_array( $echecs ) ? $echecs : array();
	array_unshift(
		$echecs,
		array(
			'date'    => gmt(),
			'type'    => sanitize_key( $type ),
			'message' => mb_substr( sanitize_text_field( $message ), 0, 300 ),
		)
	);
	update_option( OPTION_ECHECS, array_slice( $echecs, 0, 20 ), false );
	/**
	 * Un envoi (Discord ou e-mail) a échoué.
	 *
	 * @param string $type    'discord' ou 'email'.
	 * @param string $message Détail.
	 */
	do_action( 'yume_planning_echec_envoi', $type, $message );
}

/**
 * Échecs récents (depuis N jours).
 *
 * @param int $jours Jours.
 * @return array<int,array{date:string,type:string,message:string}>
 */
function echecs_recents( int $jours = 7 ): array {
	$echecs = get_option( OPTION_ECHECS, array() );
	$seuil  = gmt( maintenant() - $jours * DAY_IN_SECONDS );
	return array_values(
		array_filter(
			is_array( $echecs ) ? $echecs : array(),
			static function ( $e ) use ( $seuil ): bool {
				return is_array( $e ) && isset( $e['date'] ) && (string) $e['date'] >= $seuil;
			}
		)
	);
}

/*
 * -----------------------------------------------------------------------------
 * Discord
 * -----------------------------------------------------------------------------
 */

/**
 * URL du webhook d'un canal ('' si non réglé ou invalide).
 *
 * @param string $canal 'sorties' ou 'equipe'.
 */
function webhook( string $canal ): string {
	$cles = array(
		'sorties' => 'discord_webhook_sorties',
		'equipe'  => 'discord_webhook_equipe',
	);
	if ( ! isset( $cles[ $canal ] ) || ! function_exists( 'yume_setting' ) ) {
		return '';
	}
	$url = yume_setting( $cles[ $canal ], '' );
	$url = is_string( $url ) ? trim( $url ) : '';
	if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return '';
	}
	return esc_url_raw( $url, array( 'https' ) );
}

/**
 * Tronque un texte (UTF-8) avec « … ».
 *
 * @param string $texte Texte.
 * @param int    $max   Longueur maximale.
 */
function tronquer( string $texte, int $max ): string {
	return mb_strlen( $texte ) > $max ? rtrim( mb_substr( $texte, 0, $max - 1 ) ) . '…' : $texte;
}

/**
 * Échappe la mise en forme Markdown de Discord dans un texte libre.
 *
 * @param string $texte Texte.
 */
function echapper_discord( string $texte ): string {
	return (string) preg_replace( '/([\\\\*_~`|>\[\]()])/u', '\\\\$1', $texte );
}

/**
 * Assainit un embed Discord (limites de l'API).
 *
 * @param mixed $embed Embed.
 * @return array<string,mixed>
 */
function assainir_embed( $embed ): array {
	if ( ! is_array( $embed ) ) {
		return array();
	}
	$propre = array();
	foreach ( array(
		'title'       => 256,
		'description' => 4096,
	) as $cle => $max ) {
		if ( isset( $embed[ $cle ] ) && is_scalar( $embed[ $cle ] ) && '' !== (string) $embed[ $cle ] ) {
			$propre[ $cle ] = tronquer( (string) $embed[ $cle ], $max );
		}
	}
	if ( ! empty( $embed['url'] ) && is_string( $embed['url'] ) ) {
		$propre['url'] = esc_url_raw( $embed['url'], array( 'http', 'https' ) );
	}
	if ( isset( $embed['color'] ) && is_numeric( $embed['color'] ) ) {
		$propre['color'] = max( 0, min( 0xFFFFFF, (int) $embed['color'] ) );
	}
	foreach ( array( 'image', 'thumbnail' ) as $cle ) {
		if ( ! empty( $embed[ $cle ]['url'] ) && is_string( $embed[ $cle ]['url'] ) ) {
			$propre[ $cle ] = array( 'url' => esc_url_raw( $embed[ $cle ]['url'], array( 'http', 'https' ) ) );
		}
	}
	if ( ! empty( $embed['footer']['text'] ) && is_scalar( $embed['footer']['text'] ) ) {
		$propre['footer'] = array( 'text' => tronquer( (string) $embed['footer']['text'], 2048 ) );
	}
	if ( ! empty( $embed['timestamp'] ) && is_string( $embed['timestamp'] ) ) {
		$propre['timestamp'] = $embed['timestamp'];
	}
	if ( ! empty( $embed['fields'] ) && is_array( $embed['fields'] ) ) {
		$propre['fields'] = array();
		foreach ( array_slice( $embed['fields'], 0, 25 ) as $champ ) {
			if ( is_array( $champ ) && isset( $champ['name'], $champ['value'] ) && '' !== (string) $champ['name'] && '' !== (string) $champ['value'] ) {
				$propre['fields'][] = array(
					'name'   => tronquer( (string) $champ['name'], 256 ),
					'value'  => tronquer( (string) $champ['value'], 1024 ),
					'inline' => ! empty( $champ['inline'] ),
				);
			}
		}
	}
	return $propre;
}

/**
 * Publie sur un webhook Discord (voir yume_discord()).
 *
 * @param string $canal  'sorties' ou 'equipe'.
 * @param string $texte  Message.
 * @param array  $embeds Embeds.
 */
function envoyer_discord( string $canal, string $texte, array $embeds = array() ): bool {
	$url = webhook( $canal );
	if ( '' === $url ) {
		return false;
	}
	$texte  = tronquer( trim( $texte ), 2000 );
	$embeds = array_values( array_filter( array_map( __NAMESPACE__ . '\\assainir_embed', array_slice( $embeds, 0, 10 ) ) ) );
	if ( '' === $texte && ! $embeds ) {
		return false;
	}
	$charge = array(
		'content'          => $texte,
		'allowed_mentions' => array( 'parse' => array() ),
	);
	if ( $embeds ) {
		$charge['embeds'] = $embeds;
	}
	/**
	 * Filtre le message Discord avant envoi (tableau vide : pas d'envoi).
	 *
	 * @param array  $charge Corps JSON (content, embeds, allowed_mentions).
	 * @param string $canal  'sorties' ou 'equipe'.
	 */
	$charge = (array) apply_filters( 'yume_planning_discord', $charge, $canal );
	if ( ! $charge ) {
		return false;
	}
	$reponse = wp_remote_post(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => (string) wp_json_encode( $charge ),
			'data_format' => 'body',
		)
	);
	if ( is_wp_error( $reponse ) ) {
		/* translators: 1: canal, 2: erreur */
		noter_echec( 'discord', sprintf( __( 'Discord (%1$s) : %2$s', 'yume-core' ), $canal, $reponse->get_error_message() ) );
		return false;
	}
	$code = (int) wp_remote_retrieve_response_code( $reponse );
	if ( $code < 200 || $code >= 300 ) {
		/* translators: 1: canal, 2: code HTTP */
		noter_echec( 'discord', sprintf( __( 'Discord (%1$s) : réponse HTTP %2$d', 'yume-core' ), $canal, $code ) );
		return false;
	}
	return true;
}

/**
 * Publie un long message en plusieurs messages de 2 000 caractères au plus (coupés entre
 * deux lignes).
 *
 * @param string   $canal  Canal.
 * @param string   $titre  Première ligne.
 * @param string[] $lignes Lignes.
 * @return bool Vrai si tous les messages sont partis.
 */
function discord_en_lignes( string $canal, string $titre, array $lignes ): bool {
	if ( '' === webhook( $canal ) || ! $lignes ) {
		return false;
	}
	$messages = array();
	$courant  = $titre;
	foreach ( $lignes as $ligne ) {
		$ligne = tronquer( $ligne, 1900 );
		if ( mb_strlen( $courant ) + 1 + mb_strlen( $ligne ) > 2000 ) {
			$messages[] = $courant;
			$courant    = $ligne;
		} else {
			$courant .= ( '' === $courant ? '' : "\n" ) . $ligne;
		}
	}
	$messages[] = $courant;
	$ok         = true;
	foreach ( $messages as $message ) {
		$ok = envoyer_discord( $canal, $message ) && $ok;
	}
	return $ok;
}

/**
 * Message de test sur un webhook Discord (bouton « Envoyer un test » de Yume → Santé) : passe
 * par envoyer_discord(), donc par le filtre yume_planning_discord et le journal des échecs.
 * La vérification des droits et du nonce revient à l'appelant.
 *
 * @param string $canal   'sorties' ou 'equipe'.
 * @param int    $user_id Auteur du test (nommé dans le message).
 * @return bool Vrai si Discord a accepté le message.
 */
function envoyer_test_discord( string $canal, int $user_id ): bool {
	if ( '' === webhook( $canal ) ) {
		return false;
	}
	$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	return envoyer_discord(
		$canal,
		sprintf(
			/* translators: 1: nom du site, 2: pseudo, 3: date et heure */
			__( 'Message de test de %1$s, envoyé par %2$s le %3$s depuis Yume → Santé : ce webhook fonctionne.', 'yume-core' ),
			echapper_discord( '' !== $site ? $site : 'Yume Novel' ),
			echapper_discord( nom_utilisateur( $user_id ) ),
			format_fr( maintenant(), 'j F Y à H\hi' )
		)
	);
}

/*
 * -----------------------------------------------------------------------------
 * File d'e-mails
 * -----------------------------------------------------------------------------
 */

/**
 * Ajoute un e-mail à la file (voir yume_queue_email()). Un même message (destinataire, sujet,
 * contexte) encore en attente n'est pas ajouté deux fois.
 *
 * @param int|string $destinataire ID utilisateur ou adresse.
 * @param string     $sujet        Sujet.
 * @param string     $html         Corps HTML.
 * @param string     $contexte     Origine.
 * @return int ID de la notification (0 si rien n'a été ajouté).
 */
function mettre_en_file( $destinataire, string $sujet, string $html, string $contexte = '' ): int {
	global $wpdb;
	$user_id = 0;
	$email   = '';
	if ( is_int( $destinataire ) || ( is_string( $destinataire ) && ctype_digit( $destinataire ) ) ) {
		$user    = get_userdata( (int) $destinataire );
		$user_id = $user ? (int) $user->ID : 0;
		$email   = $user ? (string) $user->user_email : '';
	} elseif ( is_string( $destinataire ) ) {
		$email = sanitize_email( $destinataire );
		$user  = $email ? get_user_by( 'email', $email ) : false;
		if ( $user ) {
			$user_id = (int) $user->ID;
		}
	}
	if ( '' === $email || ! is_email( $email ) || strlen( $email ) > 190 ) {
		return 0;
	}
	$sujet    = mb_substr( sanitize_text_field( $sujet ), 0, 255 );
	$contexte = mb_substr( sanitize_key( $contexte ), 0, 60 );
	$html     = wp_kses_post( $html );
	if ( '' === $sujet || '' === trim( wp_strip_all_tags( $html ) ) ) {
		return 0;
	}
	$table = table_notifications();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$doublon = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE statut = 'attente' AND destinataire = %s AND sujet = %s AND contexte = %s LIMIT 1", $email, $sujet, $contexte ) );
	if ( $doublon ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->insert(
		$table,
		array(
			'destinataire' => $email,
			'user_id'      => $user_id,
			'sujet'        => $sujet,
			'html'         => $html,
			'contexte'     => $contexte,
			'statut'       => 'attente',
			'tentatives'   => 0,
			'created_at'   => gmt(),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
	);
	if ( ! $ok ) {
		return 0;
	}
	if ( ! wp_installing() && ! wp_next_scheduled( HOOK_ENVOI ) ) {
		wp_schedule_event( maintenant() + MINUTE_IN_SECONDS, RECURRENCE_ENVOI, HOOK_ENVOI );
	}
	return (int) $wpdb->insert_id;
}

/**
 * Couleurs du gabarit (valeurs du thème Papier et de l'en-tête Nuit, contrat §15).
 *
 * @return array<string,string>
 */
function couleurs_email(): array {
	return array(
		'fond'         => '#f6eef3',
		'carte'        => '#ffffff',
		'filet'        => '#e8d9e2',
		'texte'        => '#2a1240',
		'faible'       => '#5e4a73',
		'accent'       => '#b23a71',
		'accent_texte' => '#ffffff',
		'nuit'         => '#241740',
		'nuit_texte'   => '#fff8fb',
		'sakura'       => '#f3a6c8',
	);
}

/**
 * Bouton d'appel à l'action pour les e-mails.
 *
 * @param string $url   Adresse.
 * @param string $texte Libellé.
 */
function bouton_email( string $url, string $texte ): string {
	$c = couleurs_email();
	// Le style précède href : le gabarit ne colore que les liens « <a href= » sans style.
	return '<p style="margin:24px 0 8px;"><a style="display:inline-block;padding:12px 20px;border-radius:6px;background:' . $c['accent'] . ';color:' . $c['accent_texte'] . ';font-weight:700;text-decoration:none;" href="' . esc_url( $url ) . '">' . esc_html( $texte ) . '</a></p>';
}

/**
 * Enveloppe un contenu dans le gabarit HTML Yume (en-tête nuit, carte claire, pied de page
 * avec « Gérer mes alertes »).
 *
 * @param string $sujet   Sujet (titre du document).
 * @param string $contenu Corps HTML.
 */
function gabarit_email( string $sujet, string $contenu ): string {
	if ( preg_match( '/^\s*(<!doctype|<html)/i', $contenu ) ) {
		return $contenu;
	}
	$c       = couleurs_email();
	$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$site    = '' !== $site ? $site : 'Yume Novel';
	$alertes = function_exists( 'yume_url_page' ) ? yume_url_page( 'compte' ) : home_url( '/compte/' );
	$police  = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
	$titres  = "'Outfit','Segoe UI',Helvetica,Arial,sans-serif";

	$html  = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	$html .= '<meta name="color-scheme" content="light"><title>' . esc_html( $sujet ) . '</title></head>';
	$html .= '<body style="margin:0;padding:0;background:' . $c['fond'] . ';">';
	$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $c['fond'] . ';"><tr><td align="center" style="padding:24px 12px;">';
	$html .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">';
	$html .= '<tr><td style="background:' . $c['nuit'] . ';border-radius:10px 10px 0 0;padding:18px 24px;font-family:' . $titres . ';font-size:20px;font-weight:800;color:' . $c['nuit_texte'] . ';">';
	$html .= '<span style="display:inline-block;width:14px;height:14px;border-radius:7px;background:' . $c['sakura'] . ';margin-right:10px;vertical-align:middle;"></span>';
	$html .= '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . $c['nuit_texte'] . ';text-decoration:none;vertical-align:middle;">' . esc_html( $site ) . '</a></td></tr>';
	$html .= '<tr><td style="background:' . $c['carte'] . ';border:1px solid ' . $c['filet'] . ';border-top:0;border-radius:0 0 10px 10px;padding:28px 24px;font-family:' . $police . ';font-size:15px;line-height:1.6;color:' . $c['texte'] . ';">';
	$html .= '<h1 style="margin:0 0 16px;font-family:' . $titres . ';font-size:22px;line-height:1.25;color:' . $c['texte'] . ';">' . esc_html( $sujet ) . '</h1>';
	$html .= str_replace( '<a href=', '<a style="color:' . $c['accent'] . ';" href=', $contenu );
	$html .= '</td></tr>';
	$html .= '<tr><td style="padding:18px 24px;font-family:' . $police . ';font-size:13px;line-height:1.5;color:' . $c['faible'] . ';text-align:center;">';
	$html .= esc_html__( 'Yume Novel · fan-traductions à but non lucratif.', 'yume-core' ) . '<br>';
	$html .= '<a href="' . esc_url( $alertes ) . '" style="color:' . $c['accent'] . ';font-weight:700;">' . esc_html__( 'Gérer mes alertes', 'yume-core' ) . '</a>';
	$html .= '</td></tr></table></td></tr></table></body></html>';
	return $html;
}

/**
 * Nom d'expéditeur des e-mails Yume.
 *
 * @param string $nom Nom par défaut.
 */
function nom_expediteur( $nom ): string {
	$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	return '' !== $site ? $site : (string) $nom;
}

/**
 * Envoie un lot de la file.
 *
 * @param int $taille Nombre maximal d'e-mails (0 : filtre yume_notifications_lot, défaut 20).
 * @return array{envoyes:int,echecs:int,abandons:int}
 */
function envoyer_lot( int $taille = 0 ): array {
	global $wpdb;
	$table = table_notifications();
	if ( $taille <= 0 ) {
		/**
		 * Nombre d'e-mails envoyés par passage de la tâche (toutes les 5 minutes).
		 *
		 * @param int $taille Défaut 20.
		 */
		$taille = max( 1, (int) apply_filters( 'yume_notifications_lot', 20 ) );
	}
	$bilan = array(
		'envoyes'  => 0,
		'echecs'   => 0,
		'abandons' => 0,
	);

	// Envois interrompus (plus de 15 minutes « en cours ») : remis en attente.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET statut = 'attente', tentatives = tentatives + 1 WHERE statut = 'envoi' AND envoye_le < %s", gmt( maintenant() - 15 * MINUTE_IN_SECONDS ) ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET statut = 'echec' WHERE statut = 'attente' AND tentatives >= %d", TENTATIVES_MAX ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE statut = 'attente' ORDER BY id ASC LIMIT %d", $taille ) );
	if ( ! $lignes ) {
		purger_si_besoin();
		return $bilan;
	}

	$erreur  = '';
	$ecouter = static function ( $wp_error ) use ( &$erreur ): void {
		$erreur = $wp_error instanceof \WP_Error ? $wp_error->get_error_message() : '';
	};
	add_action( 'wp_mail_failed', $ecouter );
	add_filter( 'wp_mail_from_name', __NAMESPACE__ . '\\nom_expediteur' );

	foreach ( $lignes as $ligne ) {
		// Réservation atomique : un autre passage simultané ne renverra pas ce message.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$pris = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET statut = 'envoi', envoye_le = %s WHERE id = %d AND statut = 'attente'", gmt(), (int) $ligne->id ) );
		if ( 1 !== (int) $pris ) {
			continue;
		}
		// Alerte de sortie dont le contenu n'est plus publié entre-temps : abandonnée.
		if ( preg_match( '/^alerte_sortie_(\d+)$/', (string) $ligne->contexte, $m ) && 'publish' !== get_post_status( (int) $m[1] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'statut' => 'echec' ), array( 'id' => (int) $ligne->id ), array( '%s' ), array( '%d' ) );
			++$bilan['abandons'];
			continue;
		}
		$email = (string) $ligne->destinataire;
		if ( (int) $ligne->user_id > 0 ) {
			$user = get_userdata( (int) $ligne->user_id );
			if ( ! $user ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, array( 'statut' => 'echec' ), array( 'id' => (int) $ligne->id ), array( '%s' ), array( '%d' ) );
				++$bilan['abandons'];
				continue;
			}
			$email = (string) $user->user_email;
		}
		$erreur = '';
		$html   = gabarit_email( (string) $ligne->sujet, (string) $ligne->html );
		$texte  = trim( html_entity_decode( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '</li>', '</h2>' ), "\n", (string) $ligne->html ) ), ENT_QUOTES, 'UTF-8' ) );
		$alt    = static function ( $phpmailer ) use ( $texte ): void {
			if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
				$phpmailer->AltBody = $texte; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}
		};
		add_action( 'phpmailer_init', $alt );
		$ok = wp_mail( $email, (string) $ligne->sujet, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );

		if ( $ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'statut'     => 'envoye',
					'envoye_le'  => gmt(),
					'tentatives' => (int) $ligne->tentatives + 1,
				),
				array( 'id' => (int) $ligne->id ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
			++$bilan['envoyes'];
			continue;
		}
		$tentatives = (int) $ligne->tentatives + 1;
		$statut     = $tentatives >= TENTATIVES_MAX ? 'echec' : 'attente';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'statut'     => $statut,
				'tentatives' => $tentatives,
				'envoye_le'  => null,
			),
			array( 'id' => (int) $ligne->id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( 'echec' === $statut ) {
			++$bilan['abandons'];
			/* translators: 1: sujet, 2: erreur */
			noter_echec( 'email', sprintf( __( 'E-mail « %1$s » abandonné après 3 tentatives : %2$s', 'yume-core' ), (string) $ligne->sujet, '' !== $erreur ? $erreur : __( 'envoi refusé', 'yume-core' ) ) );
		} else {
			++$bilan['echecs'];
		}
	}

	remove_filter( 'wp_mail_from_name', __NAMESPACE__ . '\\nom_expediteur' );
	remove_action( 'wp_mail_failed', $ecouter );
	purger_si_besoin();
	return $bilan;
}

/**
 * Tâche cron de la file d'e-mails.
 */
function tache_envoi(): void {
	envoyer_lot();
}
add_action( HOOK_ENVOI, __NAMESPACE__ . '\\tache_envoi' );

/**
 * Supprime les e-mails envoyés (ou abandonnés) depuis plus de 30 jours.
 *
 * @return int Lignes supprimées.
 */
function purger(): int {
	global $wpdb;
	$table = table_notifications();
	$seuil = gmt( maintenant() - 30 * DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ( statut = 'envoye' AND envoye_le < %s ) OR ( statut = 'echec' AND created_at < %s )", $seuil, $seuil ) );
	update_option( OPTION_DERNIERE_PURGE, maintenant(), false );
	return (int) $n;
}

/**
 * Purge au plus une fois par jour.
 */
function purger_si_besoin(): void {
	if ( (int) get_option( OPTION_DERNIERE_PURGE, 0 ) < maintenant() - DAY_IN_SECONDS ) {
		purger();
	}
}
