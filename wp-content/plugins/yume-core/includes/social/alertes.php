<?php
/**
 * Alertes e-mail des lecteurs :
 *
 * - yume_tome_publie / yume_chapitre_publie → e-mail aux abonnés « immédiat » de l'œuvre dont
 *   la préférence « E-mail à chaque sortie » est active, une seule fois par sortie ;
 * - récapitulatif hebdomadaire (cron, dimanche) : œuvres en alerte « hebdo », plus toutes les
 *   œuvres suivies (hors « jamais ») pour qui a activé le récapitulatif ;
 * - réponse à un commentaire → e-mail à l'auteur du commentaire parent (préférence
 *   « Réponses à mes commentaires »).
 *
 * Chaque e-mail se termine par des liens de désabonnement en un clic (desabonnement.php).
 *
 * Tout dépend du réglage emails_lecteurs. Envoi par la file du module planning
 * (yume_queue_email) si elle existe, sinon wp_mail avec un gabarit HTML sobre.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Enveloppe HTML minimale (repli quand la file d'e-mails du module planning est absente).
 * Les couleurs sont écrites en clair : un client de messagerie ignore les variables CSS.
 *
 * @param string $sujet Sujet.
 * @param string $corps Corps HTML.
 */
function gabarit_simple( string $sujet, string $corps ): string {
	$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $sujet ) . '</title></head>'
		. '<body style="margin:0;padding:24px 12px;background:#f6eef3;font-family:\'Nunito Sans\',\'Segoe UI\',Helvetica,Arial,sans-serif;color:#2a1240;">'
		. '<div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e8d9e2;border-radius:10px;padding:28px 24px;font-size:15px;line-height:1.6;">'
		. '<p style="margin:0 0 16px;font-weight:800;font-size:18px;">' . esc_html( '' !== $site ? $site : 'Yume Novel' ) . '</p>'
		. '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;">' . esc_html( $sujet ) . '</h1>'
		. $corps
		. '</div></body></html>';
}

/**
 * Bouton d'appel à l'action (styles en ligne pour les messageries).
 *
 * @param string $url   Adresse.
 * @param string $texte Libellé.
 */
function bouton_email( string $url, string $texte ): string {
	return '<p style="margin:24px 0 8px;"><a style="display:inline-block;padding:12px 20px;border-radius:6px;background:#b23a71;color:#ffffff;font-weight:700;text-decoration:none;" href="' . esc_url( $url ) . '">' . esc_html( $texte ) . '</a></p>';
}

/**
 * Mention de fin : pourquoi cet e-mail, lien de gestion vers la page compte et liens de
 * désabonnement en un clic (sans connexion, signés pour chaque destinataire par
 * envoyer_email()) : le plus précis d'abord (cette œuvre, ou les réponses aux commentaires),
 * puis « toutes les alertes ».
 *
 * @param string $raison    Raison (texte).
 * @param string $ancre     Rubrique du compte.
 * @param string $portee    Désabonnement précis proposé : oeuvre, commentaires ou tout.
 * @param int    $oeuvre_id Œuvre (portée « oeuvre »).
 */
function pied_email( string $raison, string $ancre = 'yn-favoris', string $portee = 'tout', int $oeuvre_id = 0 ): string {
	$liens = array( '<a href="' . esc_url( url_compte() . '#' . $ancre ) . '">' . esc_html__( 'Modifier mes alertes', 'yume-core' ) . '</a>' );
	if ( 'oeuvre' === $portee && $oeuvre_id > 0 ) {
		$liens[] = lien_desabonnement( 'oeuvre', $oeuvre_id, __( 'Ne plus être alerté pour cette œuvre', 'yume-core' ) );
	} elseif ( 'commentaires' === $portee ) {
		$liens[] = lien_desabonnement( 'commentaires', 0, __( 'Ne plus recevoir les réponses à mes commentaires', 'yume-core' ) );
	}
	$liens[] = lien_desabonnement( 'tout', 0, __( 'Me désabonner de toutes les alertes', 'yume-core' ) );
	return '<p style="margin:24px 0 0;font-size:13px;color:#5e4a73;">' . esc_html( $raison ) . ' ' . implode( ' · ', $liens ) . '</p>';
}

/**
 * Envoie (ou met en file) un e-mail à un membre.
 *
 * @param int    $user_id  Destinataire.
 * @param string $sujet    Sujet.
 * @param string $corps    Corps HTML (sans enveloppe).
 * @param string $contexte Origine (alerte_sortie, recap_hebdo, reponse_commentaire).
 */
function envoyer_email( int $user_id, string $sujet, string $corps, string $contexte ): bool {
	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return false;
	}
	// Liens de désabonnement du pied : signés pour ce destinataire.
	$corps = personnaliser_desabonnement( $corps, $user_id );
	if ( function_exists( 'yume_queue_email' ) ) {
		yume_queue_email( $user_id, $sujet, $corps, $contexte );
		return true;
	}
	return (bool) wp_mail( $user->user_email, $sujet, gabarit_simple( $sujet, $corps ), array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
 * Titre d'un contenu en texte brut, pour un objet d'e-mail : get_the_title() renvoie du HTML
 * (entités de wptexturize : &#8217;, &#038;…) qu'une ligne d'objet afficherait telles quelles.
 *
 * @param int $post_id Contenu.
 */
function titre_brut( int $post_id ): string {
	return texte_brut( (string) get_the_title( $post_id ) );
}

/**
 * Texte HTML (titre, pseudo stocké avec &amp;…) ramené en texte brut.
 *
 * @param string $html Texte.
 */
function texte_brut( string $html ): string {
	return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Première page de lecture d'un tome (son premier chapitre publié), sinon le tome.
 *
 * @param int $tome_id Tome.
 */
function url_lecture_tome( int $tome_id ): string {
	$chapitres = function_exists( 'yume_get_chapitres' ) ? yume_get_chapitres( $tome_id ) : array();
	return $chapitres ? (string) get_permalink( $chapitres[0] ) : (string) get_permalink( $tome_id );
}

/**
 * Libellé d'une sortie : « Tome 9 » ou « Tome 7 · Chapitre 3 ».
 *
 * @param int $post_id Tome ou chapitre.
 */
function libelle_sortie( int $post_id ): string {
	if ( 'yume_tome' === get_post_type( $post_id ) ) {
		$libelle = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $post_id ) : '';
		return '' !== $libelle ? texte_brut( $libelle ) : titre_brut( $post_id );
	}
	$morceaux = array();
	$tome     = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $post_id ) : '';
	if ( '' !== $tome ) {
		$morceaux[] = texte_brut( $tome );
	}
	$chapitre   = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $post_id ) : '';
	$morceaux[] = '' !== $chapitre ? texte_brut( $chapitre ) : titre_brut( $post_id );
	return implode( ' · ', $morceaux );
}

/**
 * Sujet et corps de l'alerte d'une sortie.
 *
 * @param int   $post_id Tome ou chapitre publié.
 * @param int[] $groupe  Chapitres sortis ensemble (sortie groupée), vide sinon.
 * @return array{sujet:string,corps:string}
 */
function message_sortie( int $post_id, array $groupe = array() ): array {
	$oeuvre_id = yume_get_oeuvre_id( $post_id );
	$oeuvre    = titre_brut( $oeuvre_id );
	$libelle   = libelle_sortie( $post_id );
	$est_tome  = 'yume_tome' === get_post_type( $post_id );
	if ( $est_tome ) {
		/* translators: 1 : libellé du tome, 2 : œuvre. */
		$sujet = sprintf( __( '%1$s de %2$s est disponible', 'yume-core' ), $libelle, $oeuvre );
		$texte = sprintf(
			/* translators: 1 : libellé du tome, 2 : œuvre. */
			esc_html__( 'Bonne nouvelle : %1$s de %2$s vient de sortir sur Yume Novel.', 'yume-core' ),
			'<strong>' . esc_html( $libelle ) . '</strong>',
			'<strong>' . esc_html( $oeuvre ) . '</strong>'
		);
		$url    = url_lecture_tome( $post_id );
		$action = __( 'Lire en ligne', 'yume-core' );
	} elseif ( count( $groupe ) > 1 && is_callable( array( '\\Yume\\Core\\Publication\\Service', 'libelle_groupe' ) ) ) {
		$libelle = \Yume\Core\Publication\Service::libelle_groupe( $groupe );
		/* translators: 1 : œuvre, 2 : libellé des chapitres (« Chapitres 21 à 23 »). */
		$sujet = sprintf( __( 'Nouveaux chapitres de %1$s : %2$s', 'yume-core' ), $oeuvre, $libelle );
		$texte = sprintf(
			/* translators: 1 : libellé des chapitres, 2 : œuvre. */
			esc_html__( 'De nouveaux chapitres viennent de sortir : %1$s de %2$s.', 'yume-core' ),
			'<strong>' . esc_html( $libelle ) . '</strong>',
			'<strong>' . esc_html( $oeuvre ) . '</strong>'
		);
		$url    = (string) get_permalink( $post_id );
		$action = __( 'Lire les chapitres', 'yume-core' );
	} else {
		/* translators: 1 : œuvre, 2 : libellé du chapitre. */
		$sujet = sprintf( __( 'Nouveau chapitre de %1$s : %2$s', 'yume-core' ), $oeuvre, $libelle );
		$texte = sprintf(
			/* translators: 1 : libellé du chapitre, 2 : œuvre. */
			esc_html__( 'Un nouveau chapitre vient de sortir : %1$s de %2$s.', 'yume-core' ),
			'<strong>' . esc_html( $libelle ) . '</strong>',
			'<strong>' . esc_html( $oeuvre ) . '</strong>'
		);
		$sous_titre = texte_brut( (string) get_post_meta( $post_id, 'yume_sous_titre', true ) );
		if ( '' !== $sous_titre ) {
			$texte .= ' <em>' . esc_html( $sous_titre ) . '</em>';
		}
		$url    = (string) get_permalink( $post_id );
		$action = __( 'Lire le chapitre', 'yume-core' );
	}
	$corps = '<p style="margin:0 0 12px;">' . $texte . '</p>' . bouton_email( $url, $action );
	if ( $est_tome && function_exists( 'yume_liens_telechargement' ) ) {
		$liens = array_filter( yume_liens_telechargement( $post_id ) );
		if ( $liens ) {
			$morceaux = array();
			foreach ( $liens as $format => $lien ) {
				$morceaux[] = '<a href="' . esc_url( $lien ) . '">' . esc_html( strtoupper( $format ) ) . '</a>';
			}
			$corps .= '<p style="margin:8px 0 0;font-size:14px;">' . esc_html__( 'Télécharger :', 'yume-core' ) . ' ' . implode( ' · ', $morceaux ) . '</p>';
		}
	}
	$corps .= pied_email( __( 'Vous recevez cet e-mail car cette œuvre est dans vos favoris avec l’alerte « Immédiate ».', 'yume-core' ), 'yn-favoris', 'oeuvre', (int) $oeuvre_id );
	return array(
		'sujet' => $sujet,
		'corps' => $corps,
	);
}

/**
 * Sortie d'un tome ou d'un chapitre : note la sortie (pour le récapitulatif) et prévient
 * une seule fois les abonnés « immédiat ».
 *
 * @param int   $post_id Tome ou chapitre.
 * @param int[] $groupe  Chapitres sortis ensemble (sortie groupée), vide sinon.
 * @return int Nombre d'e-mails envoyés ou mis en file.
 */
function alerter_sortie( int $post_id, array $groupe = array() ): int {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'yume_tome', 'yume_chapitre' ), true ) || 'publish' !== $post->post_status ) {
		return 0;
	}
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $post_id ) : 0;
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		return 0;
	}
	// Verrou : add_post_meta( unique ) échoue si la sortie a déjà été traitée.
	if ( ! add_post_meta( $post_id, META_ALERTE_ENVOYEE, maintenant_gmt(), true ) ) {
		return 0;
	}
	if ( ! emails_actifs() ) {
		return 0;
	}
	$message = message_sortie( $post_id, $groupe );
	$envoyes = 0;
	foreach ( abonnes( $oeuvre_id, 'immediat' ) as $user_id ) {
		if ( ! preferences_alertes( $user_id )['sorties'] ) {
			continue;
		}
		if ( envoyer_email( $user_id, $message['sujet'], $message['corps'], contexte_sortie( $post_id ) ) ) {
			++$envoyes;
		}
	}
	/**
	 * Les abonnés « immédiat » d'une sortie ont été prévenus.
	 *
	 * @param int $post_id Tome ou chapitre.
	 * @param int $envoyes Nombre d'e-mails.
	 */
	do_action( 'yume_alertes_envoyees', $post_id, $envoyes );
	return $envoyes;
}

/**
 * Contexte des e-mails d'alerte d'une sortie dans la file (identifie le contenu annoncé, pour
 * retirer les e-mails en attente s'il est dépublié).
 *
 * @param int $post_id Tome ou chapitre.
 */
function contexte_sortie( int $post_id ): string {
	return 'alerte_sortie_' . $post_id;
}

/**
 * Contenus dont les alertes deviennent caduques quand $post_id quitte l'état publié : le
 * contenu lui-même, les chapitres d'un tome, les tomes et chapitres d'une œuvre.
 *
 * @param \WP_Post $post Contenu dépublié.
 * @return int[]
 */
function sorties_concernees( \WP_Post $post ): array {
	$ids = array( (int) $post->ID );
	if ( 'yume_chapitre' === $post->post_type ) {
		return $ids;
	}
	$tomes = array();
	if ( 'yume_tome' === $post->post_type ) {
		$tomes = array( (int) $post->ID );
	} elseif ( 'yume_oeuvre' === $post->post_type ) {
		$tomes = get_posts(
			array(
				'post_type'        => 'yume_tome',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => 'yume_oeuvre_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => (int) $post->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$ids   = array_merge( $ids, array_map( 'intval', $tomes ) );
	}
	if ( $tomes ) {
		$chapitres = get_posts(
			array(
				'post_type'        => 'yume_chapitre',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => 'yume_tome_id',
						'value'   => array_map( 'intval', $tomes ),
						'compare' => 'IN',
					),
				),
			)
		);
		$ids       = array_merge( $ids, array_map( 'intval', $chapitres ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Retire de la file les alertes encore en attente pour des contenus dépubliés. Si aucune
 * alerte de la sortie n'était encore partie, la sortie est « oubliée » (_yume_alerte_envoyee
 * effacée) : une republication préviendra de nouveau les abonnés.
 *
 * @param int[] $ids Tomes ou chapitres.
 * @return int Nombre d'e-mails retirés.
 */
function retirer_alertes_en_attente( array $ids ): int {
	global $wpdb;
	if ( ! $ids || ! function_exists( '\Yume\Core\Planning\table_notifications' ) ) {
		return 0;
	}
	$table   = \Yume\Core\Planning\table_notifications();
	$retires = 0;
	foreach ( $ids as $id ) {
		$contexte = contexte_sortie( (int) $id );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$retires += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE statut = 'attente' AND contexte = %s", $contexte ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$partis = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE contexte = %s", $contexte ) );
		if ( 0 === $partis ) {
			delete_post_meta( (int) $id, META_ALERTE_ENVOYEE );
		}
	}
	return $retires;
}

/**
 * Un tome, un chapitre ou une œuvre quitte l'état publié : ses alertes en attente ne partent
 * pas (le formulaire de publication promet qu'un tome dépublié disparaît des notifications).
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function sur_depublication( $nouveau, $ancien, $post ): void {
	if ( 'publish' !== $ancien || 'publish' === $nouveau || ! $post instanceof \WP_Post ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'yume_tome', 'yume_chapitre', 'yume_oeuvre' ), true ) ) {
		return;
	}
	retirer_alertes_en_attente( sorties_concernees( $post ) );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\sur_depublication', 10, 3 );

/**
 * Action yume_tome_publie : alerte des abonnés.
 *
 * @param int $tome_id Tome.
 */
function sur_tome_publie( $tome_id ): void {
	alerter_sortie( (int) $tome_id );
}
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\sur_tome_publie', 20 );

/**
 * Action yume_chapitre_publie : alerte des abonnés.
 *
 * @param int   $chapitre_id Chapitre.
 * @param int[] $groupe      Chapitres sortis ensemble (sortie groupée), vide sinon.
 */
function sur_chapitre_publie( $chapitre_id, $groupe = array() ): void {
	alerter_sortie( (int) $chapitre_id, array_map( 'intval', (array) $groupe ) );
}
add_action( 'yume_chapitre_publie', __NAMESPACE__ . '\\sur_chapitre_publie', 20, 2 );

/*
 * -----------------------------------------------------------------------------
 * Récapitulatif hebdomadaire
 * -----------------------------------------------------------------------------
 */

/**
 * Sorties notées entre deux dates GMT, regroupées par œuvre.
 *
 * @param string $depuis Date GMT exclue.
 * @param string $jusqua Date GMT incluse.
 * @return array<int,int[]> oeuvre_id => IDs de tomes et chapitres (ordre de sortie).
 */
function sorties_entre( string $depuis, string $jusqua ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes     = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s"
			. " WHERE p.post_type IN ('yume_tome', 'yume_chapitre') AND p.post_status = 'publish' AND m.meta_value > %s AND m.meta_value <= %s"
			. ' ORDER BY m.meta_value ASC, p.ID ASC LIMIT 500',
			META_ALERTE_ENVOYEE,
			$depuis,
			$jusqua
		),
		ARRAY_A
	);
	$par_oeuvre = array();
	foreach ( (array) $lignes as $ligne ) {
		$id        = (int) $ligne['ID'];
		$oeuvre_id = yume_get_oeuvre_id( $id );
		if ( ! oeuvre_publiee( $oeuvre_id ) ) {
			continue;
		}
		$par_oeuvre[ $oeuvre_id ][] = $id;
	}
	return $par_oeuvre;
}

/**
 * Destinataires du récapitulatif : membre => œuvres concernées.
 *
 * @param array<int,int[]> $sorties Sorties par œuvre.
 * @return array<int,int[]>
 */
function destinataires_recap( array $sorties ): array {
	$destinataires = array();
	foreach ( array_keys( $sorties ) as $oeuvre_id ) {
		foreach ( abonnes( $oeuvre_id, 'hebdo' ) as $user_id ) {
			$destinataires[ $user_id ][] = $oeuvre_id;
		}
		foreach ( abonnes( $oeuvre_id, 'immediat' ) as $user_id ) {
			if ( preferences_alertes( $user_id )['hebdo'] ) {
				$destinataires[ $user_id ][] = $oeuvre_id;
			}
		}
	}
	return array_map( 'array_unique', $destinataires );
}

/**
 * Envoie le récapitulatif hebdomadaire des sorties (tâche cron du dimanche).
 *
 * @return int Nombre de récapitulatifs envoyés ou mis en file.
 */
function envoyer_recap(): int {
	$maintenant = maintenant_gmt();
	$precedent  = (string) get_option( OPTION_RECAP, '' );
	$plancher   = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
	$depuis     = ( '' === $precedent || $precedent < $plancher ) ? gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) : $precedent;
	update_option( OPTION_RECAP, $maintenant, false );
	if ( ! emails_actifs() ) {
		return 0;
	}
	$sorties = sorties_entre( $depuis, $maintenant );
	if ( ! $sorties ) {
		return 0;
	}
	$sujet   = __( 'Votre récapitulatif de la semaine sur Yume Novel', 'yume-core' );
	$envoyes = 0;
	foreach ( destinataires_recap( $sorties ) as $user_id => $oeuvres ) {
		$corps = '<p style="margin:0 0 12px;">' . esc_html__( 'Voici les sorties de la semaine pour les œuvres que vous suivez :', 'yume-core' ) . '</p>';
		foreach ( $oeuvres as $oeuvre_id ) {
			$corps .= '<p style="margin:16px 0 4px;font-weight:700;"><a href="' . esc_url( (string) get_permalink( $oeuvre_id ) ) . '">' . esc_html( titre_brut( $oeuvre_id ) ) . '</a></p><ul style="margin:0;padding-left:20px;">';
			foreach ( $sorties[ $oeuvre_id ] as $post_id ) {
				$url    = 'yume_tome' === get_post_type( $post_id ) ? url_lecture_tome( $post_id ) : (string) get_permalink( $post_id );
				$corps .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( libelle_sortie( $post_id ) ) . '</a></li>';
			}
			$corps .= '</ul>';
		}
		$corps .= pied_email( __( 'Vous recevez ce récapitulatif pour vos favoris en alerte « Hebdomadaire » ou parce que vous avez activé le récapitulatif du dimanche.', 'yume-core' ), 'yn-alertes' );
		if ( envoyer_email( (int) $user_id, $sujet, $corps, 'recap_hebdo' ) ) {
			++$envoyes;
		}
	}
	return $envoyes;
}
add_action( HOOK_RECAP, __NAMESPACE__ . '\\envoyer_recap' );

/*
 * -----------------------------------------------------------------------------
 * Réponses aux commentaires
 * -----------------------------------------------------------------------------
 */

/**
 * Prévient l'auteur (membre) d'un commentaire parent qu'une réponse approuvée a été publiée.
 *
 * @param \WP_Comment $reponse Réponse approuvée.
 * @return bool Vrai si un e-mail est parti.
 */
function notifier_reponse( \WP_Comment $reponse ): bool {
	if ( ! emails_actifs() || '1' !== (string) $reponse->comment_approved || ! (int) $reponse->comment_parent ) {
		return false;
	}
	$parent = get_comment( (int) $reponse->comment_parent );
	if ( ! $parent instanceof \WP_Comment ) {
		return false;
	}
	$destinataire = (int) $parent->user_id;
	if ( $destinataire <= 0 || $destinataire === (int) $reponse->user_id || ! preferences_alertes( $destinataire )['commentaires'] ) {
		return false;
	}
	if ( ! add_comment_meta( (int) $reponse->comment_ID, '_yume_reponse_notifiee', maintenant_gmt(), true ) ) {
		return false;
	}
	$titre  = titre_brut( (int) $reponse->comment_post_ID );
	$auteur = texte_brut( (string) $reponse->comment_author );
	$auteur = '' !== $auteur ? $auteur : __( 'Un lecteur', 'yume-core' );
	/* translators: 1 : auteur de la réponse, 2 : titre de la page. */
	$sujet   = sprintf( __( '%1$s a répondu à votre commentaire sur « %2$s »', 'yume-core' ), $auteur, $titre );
	$extrait = wp_trim_words( wp_strip_all_tags( (string) $reponse->comment_content ), 60, '…' );
	$corps   = '<blockquote style="margin:0 0 12px;padding:8px 16px;border-left:3px solid #b23a71;color:#2a1240;">' . esc_html( $extrait ) . '</blockquote>'
		. bouton_email( (string) get_comment_link( $reponse ), __( 'Voir la réponse', 'yume-core' ) )
		. pied_email( __( 'Vous recevez cet e-mail car une personne a répondu à l’un de vos commentaires.', 'yume-core' ), 'yn-alertes', 'commentaires' );
	return envoyer_email( $destinataire, $sujet, $corps, 'reponse_commentaire' );
}

/**
 * Nouveau commentaire directement approuvé.
 *
 * @param int        $comment_id ID.
 * @param int|string $approuve   1, 0 ou « spam ».
 */
function sur_commentaire( $comment_id, $approuve ): void {
	if ( 1 === (int) $approuve && '1' === (string) $approuve ) {
		$commentaire = get_comment( (int) $comment_id );
		if ( $commentaire instanceof \WP_Comment ) {
			notifier_reponse( $commentaire );
		}
	}
}
add_action( 'comment_post', __NAMESPACE__ . '\\sur_commentaire', 20, 2 );

/**
 * Commentaire approuvé par la modération.
 *
 * @param string      $nouveau     Nouveau statut.
 * @param string      $ancien      Ancien statut.
 * @param \WP_Comment $commentaire Commentaire.
 */
function sur_statut_commentaire( $nouveau, $ancien, $commentaire ): void {
	if ( 'approved' !== $nouveau || 'approved' === $ancien || ! $commentaire instanceof \WP_Comment ) {
		return;
	}
	$frais = get_comment( (int) $commentaire->comment_ID );
	if ( $frais instanceof \WP_Comment ) {
		notifier_reponse( $frais );
	}
}
add_action( 'transition_comment_status', __NAMESPACE__ . '\\sur_statut_commentaire', 20, 3 );
