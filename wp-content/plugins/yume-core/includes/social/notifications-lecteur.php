<?php
/**
 * Centre de notifications du lecteur (AMEL-11).
 *
 * - Table {prefix}yume_notifications_lecteur (install.php) : une ligne par membre et par
 *   événement, lue ou non (lu_le).
 * - Alimentée par les mêmes événements que les e-mails d'alerte (alertes.php) :
 *   yume_tome_publie / yume_chapitre_publie → type « sortie » pour chaque membre qui suit
 *   l'œuvre (favori dont l'alerte n'est pas « jamais »), une seule fois par sortie (méta
 *   _yume_notif_lecteur) ; réponse approuvée à un commentaire d'un membre → type « reponse »
 *   (insertion directement approuvée ou approbation différée par la modération). Un contenu
 *   dépublié retire ses notifications de sortie.
 * - Purge des notifications de plus de 90 jours (filtre yume_notifications_duree) par la tâche
 *   quotidienne yume_notifications_lecteur_purge.
 * - Cloche dans l'en-tête (bloc yume/auth-links, membres connectés seulement) : pastille du
 *   nombre de non lues, panneau accessible (liste, « Tout marquer comme lu », lien vers la
 *   rubrique « Notifications » du compte), rafraîchie toutes les 5 minutes si l'onglet est
 *   visible (auth-links/view.js). Aucun script ni requête pour un visiteur.
 * - Page compte : rubrique « Notifications » (liste, tout marquer comme lu, notifications
 *   navigateur de push.php).
 *
 * Routes REST : rest.php (GET /moi/notifications, POST /moi/notifications/lues).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Types de notification du lecteur. */
const TYPES_NOTIFICATION = array( 'sortie', 'reponse' );

/** Méta de contenu : notifications de sortie déjà créées (date GMT, verrou). */
const META_NOTIF_SORTIE = '_yume_notif_lecteur';

/** Méta de commentaire : notification de réponse déjà créée (verrou). */
const META_NOTIF_REPONSE = '_yume_notif_reponse';

/** Événement cron quotidien de purge des notifications anciennes. */
const HOOK_PURGE_NOTIFICATIONS = 'yume_notifications_lecteur_purge';

/** Durée de conservation des notifications, en jours (par défaut). */
const NOTIFICATIONS_DUREE = 90;

/** Nombre de notifications affichées dans le panneau de la cloche. */
const CLOCHE_NB = 8;

/**
 * Nom complet de la table des notifications du lecteur.
 */
function table_notifications_lecteur(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_notifications_lecteur';
}

/**
 * Ligne de notification normalisée pour l'API.
 *
 * @param array $ligne Ligne brute.
 * @return array{id:int,type:string,objet_id:int,titre:string,url:string,cree_le:string,lu:bool,lu_le:string}
 */
function normaliser_notification( array $ligne ): array {
	$lu_le = (string) ( $ligne['lu_le'] ?? '' );
	return array(
		'id'       => (int) ( $ligne['id'] ?? 0 ),
		'type'     => (string) ( $ligne['type'] ?? '' ),
		'objet_id' => (int) ( $ligne['objet_id'] ?? 0 ),
		'titre'    => (string) ( $ligne['titre'] ?? '' ),
		'url'      => (string) ( $ligne['url'] ?? '' ),
		'cree_le'  => iso( (string) ( $ligne['cree_le'] ?? '' ) ),
		'lu'       => '' !== $lu_le,
		'lu_le'    => iso( $lu_le ),
	);
}

/**
 * Crée une notification pour chaque membre (une seule par membre, type et objet).
 *
 * @param int[]  $user_ids Membres.
 * @param string $type     sortie ou reponse.
 * @param int    $objet_id Contenu ou commentaire.
 * @param string $titre    Texte (brut).
 * @param string $url      Adresse.
 * @return int[] Membres effectivement notifiés.
 */
function creer_notifications( array $user_ids, string $type, int $objet_id, string $titre, string $url ): array {
	global $wpdb;
	$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
	if ( ! $user_ids || ! in_array( $type, TYPES_NOTIFICATION, true ) || ! preparer_tables_lecteur() ) {
		return array();
	}
	$table = table_notifications_lecteur();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$deja     = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE type = %s AND objet_id = %d", $type, $objet_id ) ) );
	$user_ids = array_values( array_diff( $user_ids, $deja ) );
	if ( ! $user_ids ) {
		return array();
	}
	$titre      = mb_substr( texte_brut( $titre ), 0, 255 );
	$url        = substr( esc_url_raw( $url ), 0, 500 );
	$maintenant = maintenant_gmt();
	foreach ( array_chunk( $user_ids, 200 ) as $lot ) {
		$valeurs = array();
		$marques = array();
		foreach ( $lot as $user_id ) {
			$marques[] = '(%d, %s, %d, %s, %s, %s)';
			array_push( $valeurs, $user_id, $type, $objet_id, $titre, $url, $maintenant );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- marques %d/%s construites ci-dessus.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (user_id, type, objet_id, titre, url, cree_le) VALUES " . implode( ', ', $marques ), $valeurs ) );
	}
	/**
	 * Des notifications du lecteur viennent d'être créées (push.php planifie l'envoi des
	 * notifications navigateur).
	 *
	 * @param int[]  $user_ids Membres notifiés.
	 * @param string $type     Type.
	 * @param int    $objet_id Objet.
	 */
	do_action( 'yume_notifications_creees', $user_ids, $type, $objet_id );
	return $user_ids;
}

/**
 * Notifications d'un membre, les plus récentes d'abord.
 *
 * @param int  $user_id  Membre.
 * @param bool $non_lues Seulement les non lues.
 * @param int  $page     Page (à partir de 1).
 * @param int  $limite   Nombre par page (1 à 50).
 * @return array{notifications:array<int,array>,total:int}
 */
function notifications_utilisateur( int $user_id, bool $non_lues = false, int $page = 1, int $limite = 20 ): array {
	global $wpdb;
	$vide = array(
		'notifications' => array(),
		'total'         => 0,
	);
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return $vide;
	}
	$table  = table_notifications_lecteur();
	$limite = max( 1, min( 50, $limite ) );
	$page   = max( 1, $page );
	$filtre = $non_lues ? ' AND lu_le IS NULL' : '';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d{$filtre}", $user_id ) );
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d{$filtre} ORDER BY cree_le DESC, id DESC LIMIT %d OFFSET %d", $user_id, $limite, ( $page - 1 ) * $limite ), ARRAY_A );
	// phpcs:enable
	return array(
		'notifications' => array_map( __NAMESPACE__ . '\\normaliser_notification', $lignes ),
		'total'         => $total,
	);
}

/**
 * Nombre de notifications non lues d'un membre.
 *
 * @param int $user_id Membre.
 */
function nb_non_lues( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return 0;
	}
	$table = table_notifications_lecteur();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND lu_le IS NULL", $user_id ) );
}

/**
 * Marque des notifications comme lues (toutes si $ids est vide). Seules celles du membre.
 *
 * @param int   $user_id Membre.
 * @param int[] $ids     Notifications.
 * @return int Nombre de notifications marquées.
 */
function marquer_lues( int $user_id, array $ids = array() ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return 0;
	}
	$table = table_notifications_lecteur();
	$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	if ( $ids ) {
		$ids     = array_slice( $ids, 0, 200 );
		$marques = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lu_le = %s WHERE user_id = %d AND lu_le IS NULL AND id IN ({$marques})", array_merge( array( maintenant_gmt(), $user_id ), $ids ) ) );
	}
	return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lu_le = %s WHERE user_id = %d AND lu_le IS NULL", maintenant_gmt(), $user_id ) );
	// phpcs:enable
}

/**
 * Supprime les notifications d'un membre (RGPD, suppression du compte).
 *
 * @param int $user_id Membre.
 * @return int Nombre de notifications supprimées.
 */
function effacer_notifications( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->delete( table_notifications_lecteur(), array( 'user_id' => $user_id ), array( '%d' ) );
}

/**
 * Notifications d'un membre pour l'export RGPD.
 *
 * @param int $user_id Membre.
 * @return array<int,array>
 */
function donnees_notifications( int $user_id ): array {
	return notifications_utilisateur( $user_id, false, 1, 50 )['notifications'];
}

/**
 * Utilisateur supprimé : ses notifications aussi.
 *
 * @param int $user_id Utilisateur.
 */
function notifications_utilisateur_supprime( $user_id ): void {
	effacer_notifications( (int) $user_id );
}
add_action( 'deleted_user', __NAMESPACE__ . '\\notifications_utilisateur_supprime' );

/*
 * -----------------------------------------------------------------------------
 * Événements
 * -----------------------------------------------------------------------------
 */

/**
 * Membres qui suivent une œuvre : favoris en alerte « immédiate » ou « hebdomadaire ».
 *
 * @param int $oeuvre_id Œuvre.
 * @return int[]
 */
function suiveurs( int $oeuvre_id ): array {
	return array_values( array_unique( array_merge( abonnes( $oeuvre_id, 'immediat' ), abonnes( $oeuvre_id, 'hebdo' ) ) ) );
}

/**
 * Sortie d'un tome ou d'un chapitre : une notification pour chaque membre qui suit l'œuvre,
 * une seule fois par sortie. Même libellé que l'objet de l'e-mail d'alerte.
 *
 * @param int   $post_id Tome ou chapitre publié.
 * @param int[] $groupe  Chapitres sortis ensemble, vide sinon.
 * @return int Nombre de membres notifiés.
 */
function notifier_sortie( int $post_id, array $groupe = array() ): int {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'yume_tome', 'yume_chapitre' ), true ) || 'publish' !== $post->post_status ) {
		return 0;
	}
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $post_id ) : 0;
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		return 0;
	}
	if ( ! add_post_meta( $post_id, META_NOTIF_SORTIE, maintenant_gmt(), true ) ) {
		return 0;
	}
	$membres = suiveurs( $oeuvre_id );
	if ( ! $membres ) {
		return 0;
	}
	$message = message_sortie( $post_id, $groupe );
	$url     = 'yume_tome' === $post->post_type ? url_lecture_tome( $post_id ) : (string) get_permalink( $post_id );
	return count( creer_notifications( $membres, 'sortie', $post_id, $message['sujet'], $url ) );
}

/**
 * Action yume_tome_publie.
 *
 * @param int $tome_id Tome.
 */
function notif_sur_tome_publie( $tome_id ): void {
	notifier_sortie( (int) $tome_id );
}
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\notif_sur_tome_publie', 21 );

/**
 * Action yume_chapitre_publie (sortie groupée : libellé du groupe, filtré pendant l'action).
 *
 * @param int   $chapitre_id Chapitre.
 * @param int[] $groupe      Chapitres sortis ensemble.
 */
function notif_sur_chapitre_publie( $chapitre_id, $groupe = array() ): void {
	notifier_sortie( (int) $chapitre_id, array_map( 'intval', (array) $groupe ) );
}
add_action( 'yume_chapitre_publie', __NAMESPACE__ . '\\notif_sur_chapitre_publie', 21, 2 );

/**
 * Contenu dépublié : ses notifications de sortie (et celles de ses tomes et chapitres)
 * disparaissent ; une republication notifiera de nouveau.
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function notif_sur_depublication( $nouveau, $ancien, $post ): void {
	global $wpdb;
	if ( 'publish' !== $ancien || 'publish' === $nouveau || ! $post instanceof \WP_Post || ! tables_lecteur_pretes() ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'yume_tome', 'yume_chapitre', 'yume_oeuvre' ), true ) ) {
		return;
	}
	$table = table_notifications_lecteur();
	foreach ( array_chunk( sorties_concernees( $post ), 200 ) as $ids ) {
		$marques = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type = 'sortie' AND objet_id IN ({$marques})", $ids ) );
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, META_NOTIF_SORTIE );
		}
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\notif_sur_depublication', 11, 3 );

/**
 * Réponse approuvée à un commentaire d'un membre : notification à l'auteur du parent (sauf
 * s'il se répond à lui-même), une seule fois.
 *
 * @param \WP_Comment $reponse Réponse.
 * @return bool Vrai si une notification a été créée.
 */
function notifier_reponse_lecteur( \WP_Comment $reponse ): bool {
	if ( '1' !== (string) $reponse->comment_approved || ! (int) $reponse->comment_parent ) {
		return false;
	}
	$parent = get_comment( (int) $reponse->comment_parent );
	if ( ! $parent instanceof \WP_Comment ) {
		return false;
	}
	$destinataire = (int) $parent->user_id;
	if ( $destinataire <= 0 || $destinataire === (int) $reponse->user_id || ! get_userdata( $destinataire ) ) {
		return false;
	}
	if ( ! add_comment_meta( (int) $reponse->comment_ID, META_NOTIF_REPONSE, maintenant_gmt(), true ) ) {
		return false;
	}
	$auteur = texte_brut( (string) $reponse->comment_author );
	$auteur = '' !== $auteur ? $auteur : __( 'Un lecteur', 'yume-core' );
	/* translators: 1 : auteur de la réponse, 2 : titre de la page. */
	$titre = sprintf( __( '%1$s a répondu à votre commentaire sur « %2$s »', 'yume-core' ), $auteur, titre_brut( (int) $reponse->comment_post_ID ) );
	return (bool) creer_notifications( array( $destinataire ), 'reponse', (int) $reponse->comment_ID, $titre, (string) get_comment_link( $reponse ) );
}

/**
 * Commentaire inséré (wp_insert_comment, dont les commentaires publiés en façade) : réponse
 * directement approuvée.
 *
 * @param int         $comment_id  ID.
 * @param \WP_Comment $commentaire Commentaire.
 */
function notif_sur_commentaire( $comment_id, $commentaire = null ): void {
	$commentaire = $commentaire instanceof \WP_Comment ? $commentaire : get_comment( (int) $comment_id );
	if ( $commentaire instanceof \WP_Comment && '1' === (string) $commentaire->comment_approved ) {
		notifier_reponse_lecteur( $commentaire );
	}
}
add_action( 'wp_insert_comment', __NAMESPACE__ . '\\notif_sur_commentaire', 20, 2 );

/**
 * Commentaire approuvé plus tard par la modération.
 *
 * @param string      $nouveau     Nouveau statut.
 * @param string      $ancien      Ancien statut.
 * @param \WP_Comment $commentaire Commentaire.
 */
function notif_sur_statut_commentaire( $nouveau, $ancien, $commentaire ): void {
	if ( 'approved' !== $nouveau || 'approved' === $ancien || ! $commentaire instanceof \WP_Comment ) {
		return;
	}
	$frais = get_comment( (int) $commentaire->comment_ID );
	if ( $frais instanceof \WP_Comment ) {
		notifier_reponse_lecteur( $frais );
	}
}
add_action( 'transition_comment_status', __NAMESPACE__ . '\\notif_sur_statut_commentaire', 21, 3 );

/*
 * -----------------------------------------------------------------------------
 * Purge quotidienne
 * -----------------------------------------------------------------------------
 */

/**
 * Supprime les notifications plus anciennes que la durée de conservation.
 *
 * @return int Nombre de notifications supprimées.
 */
function purger_notifications(): int {
	global $wpdb;
	if ( ! tables_lecteur_pretes() ) {
		return 0;
	}
	/**
	 * Durée de conservation des notifications du lecteur, en jours.
	 *
	 * @param int $jours 90 par défaut.
	 */
	$jours = max( 1, (int) apply_filters( 'yume_notifications_duree', NOTIFICATIONS_DUREE ) );
	$table = table_notifications_lecteur();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE cree_le < %s", gmdate( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS ) ) );
}
add_action( HOOK_PURGE_NOTIFICATIONS, __NAMESPACE__ . '\\purger_notifications' );

/**
 * Planifie la purge quotidienne (si elle ne l'est pas).
 */
function planifier_purge_notifications(): void {
	if ( wp_installing() || wp_next_scheduled( HOOK_PURGE_NOTIFICATIONS ) ) {
		return;
	}
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', HOOK_PURGE_NOTIFICATIONS );
}
add_action( 'init', __NAMESPACE__ . '\\planifier_purge_notifications', 99 );

/**
 * Désactivation : suppression de la tâche de purge.
 */
function desactiver_notifications(): void {
	wp_clear_scheduled_hook( HOOK_PURGE_NOTIFICATIONS );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver_notifications' );

/*
 * -----------------------------------------------------------------------------
 * Cloche de l'en-tête (bloc yume/auth-links)
 * -----------------------------------------------------------------------------
 */

/**
 * Icône de cloche (décorative).
 */
function icone_cloche(): string {
	return '<svg class="yn-cloche__icone" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
		. '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>';
}

/**
 * Libellé accessible de la cloche.
 *
 * @param int $nb Non lues.
 */
function libelle_cloche( int $nb ): string {
	return $nb > 0
		/* translators: %d : nombre de notifications non lues. */
		? sprintf( _n( 'Notifications : %d non lue', 'Notifications : %d non lues', $nb, 'yume-core' ), $nb )
		: __( 'Notifications : aucune non lue', 'yume-core' );
}

/**
 * Élément d'une notification dans une liste (cloche ou page compte).
 *
 * @param array $notification Notification normalisée.
 */
function html_notification( array $notification ): string {
	$ts   = (int) strtotime( $notification['cree_le'] );
	$date = $ts > 0 ? sprintf( /* translators: %s : durée écoulée. */ __( 'il y a %s', 'yume-core' ), human_time_diff( $ts ) ) : '';
	return '<li class="yn-notification' . ( $notification['lu'] ? '' : ' est-non-lue' ) . '" data-yn-notification="' . esc_attr( (string) $notification['id'] ) . '">'
		. '<a class="yn-notification__lien" href="' . esc_url( $notification['url'] ) . '">'
		. ( $notification['lu'] ? '' : '<span class="yn-visually-hidden">' . esc_html__( 'Non lue : ', 'yume-core' ) . '</span>' )
		. esc_html( $notification['titre'] ) . '</a>'
		. ( '' !== $date ? ' <span class="yn-muted yn-notification__date">' . esc_html( $date ) . '</span>' : '' )
		. '</li>';
}

/**
 * Cloche des notifications du membre connecté ('' pour un visiteur). Sans JavaScript, un lien
 * vers la rubrique « Notifications » du compte ; avec, un bouton qui ouvre le panneau.
 */
function html_cloche(): string {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || apercu_editeur() || ! tables_lecteur_pretes() ) {
		return '';
	}
	$nb       = nb_non_lues( $user_id );
	$liste    = notifications_utilisateur( $user_id, false, 1, CLOCHE_NB )['notifications'];
	$compte   = url_compte() . '#yn-notifications';
	$donnees  = array_merge(
		donnees_rest(),
		array(
			'nonLues'    => $nb,
			'nombre'     => CLOCHE_NB,
			'intervalle' => 5 * MINUTE_IN_SECONDS,
		)
	);
	$pastille = '<span class="yn-cloche__pastille" aria-hidden="true" data-yn-cloche-pastille' . ( $nb > 0 ? '' : ' hidden' ) . '>' . esc_html( $nb > 99 ? '99+' : (string) $nb ) . '</span>';

	wp_enqueue_script( 'yume-cloche' );

	$html = '<div class="yn-cloche" data-yn-cloche="' . esc_attr( (string) wp_json_encode( $donnees ) ) . '">'
		. '<a class="yn-btn yn-btn--sm yn-cloche__bouton" href="' . esc_url( $compte ) . '" data-yn-cloche-lien>' . icone_cloche()
		. '<span class="yn-visually-hidden" data-yn-cloche-libelle>' . esc_html( libelle_cloche( $nb ) ) . '</span>' . $pastille . '</a>'
		. '<button type="button" class="yn-btn yn-btn--sm yn-cloche__bouton" aria-expanded="false" aria-controls="yn-cloche-panneau" data-yn-cloche-bouton hidden>' . icone_cloche()
		. '<span class="yn-visually-hidden" data-yn-cloche-libelle>' . esc_html( libelle_cloche( $nb ) ) . '</span>' . $pastille . '</button>'
		. '<div class="yn-cloche__panneau yn-card" id="yn-cloche-panneau" role="region" aria-labelledby="yn-cloche-titre" hidden>'
		. '<div class="yn-cloche__entete"><p class="yn-cloche__titre" id="yn-cloche-titre">' . esc_html__( 'Notifications', 'yume-core' ) . '</p>'
		. '<button type="button" class="yn-cloche__tout-lu" data-yn-cloche-tout-lu' . ( $nb > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Tout marquer comme lu', 'yume-core' ) . '</button></div>'
		. '<ul class="yn-cloche__liste" data-yn-cloche-liste>';
	foreach ( $liste as $notification ) {
		$html .= html_notification( $notification );
	}
	$html .= '</ul>'
		. '<p class="yn-cloche__vide yn-muted" data-yn-cloche-vide' . ( $liste ? ' hidden' : '' ) . '>' . esc_html__( 'Aucune notification pour l’instant. Vous serez prévenu ici des sorties de vos favoris et des réponses à vos commentaires.', 'yume-core' ) . '</p>'
		. '<p class="yn-cloche__pied"><a href="' . esc_url( $compte ) . '">' . esc_html__( 'Toutes mes notifications', 'yume-core' ) . '</a></p>'
		. '<p class="yn-visually-hidden" role="status" aria-live="polite" data-yn-cloche-annonce></p>'
		. '</div></div>';
	return $html;
}

/**
 * Enregistre le script de la cloche et des notifications navigateur (chargé seulement pour un
 * membre connecté, par html_cloche()).
 */
function enregistrer_script_cloche(): void {
	$fichier = __DIR__ . '/blocks/auth-links/view.js';
	wp_register_script(
		'yume-cloche',
		plugins_url( 'blocks/auth-links/view.js', __FILE__ ),
		array(),
		(string) ( file_exists( $fichier ) ? filemtime( $fichier ) : YUME_CORE_VERSION ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_script_cloche' );

/**
 * Insère la cloche au début du bloc yume/auth-links (appelé par le render.php du bloc).
 *
 * @param string $html Rendu du bloc.
 */
function inserer_cloche( string $html ): string {
	$cloche = html_cloche();
	if ( '' === $cloche ) {
		return $html;
	}
	$pos = strpos( $html, '>' );
	return false === $pos ? $cloche . $html : substr_replace( $html, $cloche, $pos + 1, 0 );
}

/*
 * -----------------------------------------------------------------------------
 * Page compte : rubrique « Notifications »
 * -----------------------------------------------------------------------------
 */

/**
 * Rubrique « Notifications » de la page compte : notifications navigateur, liste des
 * notifications (30 dernières), « Tout marquer comme lu ».
 *
 * @param int $user_id Membre.
 */
function section_notifications( int $user_id ): string {
	$html  = debut_section( 'yn-notifications', __( 'Notifications', 'yume-core' ), __( 'Sorties de vos favoris et réponses à vos commentaires, gardées 90 jours', 'yume-core' ) );
	$html .= html_messages( messages_notifications_courants() );
	if ( function_exists( __NAMESPACE__ . '\\html_push_compte' ) ) {
		$html .= html_push_compte( $user_id );
	}
	$nb    = nb_non_lues( $user_id );
	$liste = notifications_utilisateur( $user_id, false, 1, 30 )['notifications'];
	$html .= '<div class="yn-card yn-account__bloc yn-notifications">'
		. '<div class="yn-notifications__entete"><h3 class="yn-account__sous-titre">'
		/* translators: %d : nombre de notifications non lues. */
		. esc_html( $nb > 0 ? sprintf( _n( 'Dernières notifications (%d non lue)', 'Dernières notifications (%d non lues)', $nb, 'yume-core' ), $nb ) : __( 'Dernières notifications', 'yume-core' ) ) . '</h3>';
	if ( $nb > 0 ) {
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="yume_notifications_lues">'
			. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
			. champ_nonce( 'yume_notifications_lues' )
			. '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Tout marquer comme lu', 'yume-core' ) . '</button></form>';
	}
	$html .= '</div>';
	if ( $liste ) {
		$html .= '<ul class="yn-notifications__liste">';
		foreach ( $liste as $notification ) {
			$html .= html_notification( $notification );
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-account__vide">' . esc_html__( 'Aucune notification pour l’instant. Ajoutez des œuvres à vos favoris pour être prévenu de leurs sorties.', 'yume-core' ) . '</p>';
	}
	$html .= '</div>';
	return $html . '</section>';
}

/**
 * Messages de la rubrique « Notifications » demandés par l'URL courante.
 *
 * @return array<int,array{0:string,1:string}>
 */
function messages_notifications_courants(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage d'un code connu.
	$code = isset( $_GET[ PARAM_MESSAGE_LISTES ] ) ? sanitize_key( wp_unslash( $_GET[ PARAM_MESSAGE_LISTES ] ) ) : '';
	return 'notifications-lues' === $code ? array( array( 'succes', __( 'Toutes vos notifications sont marquées comme lues.', 'yume-core' ) ) ) : array();
}

/**
 * « Tout marquer comme lu » sans JavaScript.
 */
function action_notifications_lues(): void {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( 'yume_notifications_lues', $retour, 'yn-notifications' );
	marquer_lues( get_current_user_id() );
	rediriger_listes( $retour, 'notifications-lues', 'yn-notifications' );
}
add_action( 'admin_post_yume_notifications_lues', __NAMESPACE__ . '\\action_notifications_lues' );

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

/**
 * GET /moi/notifications : notifications du membre (paginées ; non_lues=1 : non lues
 * seulement). Jamais mises en cache.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_notifications( \WP_REST_Request $requete ) {
	$user_id = get_current_user_id();
	$limite  = (int) $requete['limite'];
	$page    = (int) $requete['page'];
	$donnees = notifications_utilisateur( $user_id, (bool) $requete['non_lues'], $page, $limite );
	$pages   = (int) max( 1, ceil( $donnees['total'] / max( 1, $limite ) ) );
	$reponse = rest_ensure_response(
		array(
			'notifications' => $donnees['notifications'],
			'non_lues'      => nb_non_lues( $user_id ),
			'total'         => $donnees['total'],
			'page'          => $page,
			'pages'         => $pages,
		)
	);
	$reponse->header( 'X-WP-Total', (string) $donnees['total'] );
	$reponse->header( 'X-WP-TotalPages', (string) $pages );
	$reponse->header( 'Cache-Control', 'no-store, private' );
	return $reponse;
}

/**
 * POST /moi/notifications/lues : marque comme lues les notifications données (ids), ou toutes.
 *
 * @param \WP_REST_Request $requete Requête.
 * @return \WP_REST_Response
 */
function rest_notifications_lues( \WP_REST_Request $requete ) {
	$user_id = get_current_user_id();
	$n       = marquer_lues( $user_id, array_map( 'intval', (array) $requete['ids'] ) );
	return rest_ensure_response(
		array(
			'marquees' => $n,
			'non_lues' => nb_non_lues( $user_id ),
		)
	);
}
