<?php
/**
 * Santé du site (audit AMEL-12) : données de la vue « Santé du site » de l'espace équipe
 * (?vue=sante, capacité yume_reglages, rendu dans includes/planning/sante-equipe.php), tests
 * personnalisés de l'écran « Outils → Santé du site » de WordPress (filtre site_status_tests) et
 * sous-menu Yume → Santé, qui mène à la vue de l'espace équipe.
 *
 * - Tâches planifiées Yume : dernière exécution (option yume_cron_derniers, horodatage noté au
 *   début de chaque tâche par un écouteur générique) et prochaine exécution ;
 * - file d'e-mails : en attente, abandonnés, derniers échecs ;
 * - webhooks Discord configurés, bouton « Envoyer un test » (admin-post yume_sante_webhook, nonce,
 *   retour sur la vue de l'espace équipe) ;
 * - version installée et dernière release connue, lue dans l'état mis en cache par la
 *   vérification des mises à jour (aucune requête réseau à l'affichage) ;
 * - prérequis de mise en production (etat_prerequis(), notices.php).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Slug du sous-menu Yume → Santé (redirigé vers la vue de l'espace équipe). */
const PAGE_SANTE = 'yume-sante';

/** Capacité de la vue et du bouton de test (gérants et administrateurs). */
const CAPACITE_SANTE = 'yume_reglages';

/** Option : dernière exécution de chaque tâche planifiée Yume (hook => horodatage). */
const OPTION_CRON_DERNIERS = 'yume_cron_derniers';

/** Action admin-post du test de webhook. */
const ACTION_TEST_WEBHOOK = 'yume_sante_webhook';

/** Zone d'annonce du retour du bouton de test dans la vue « Santé du site ». */
const RETOUR_SANTE = 'yn-sante-retour';

/** Retard toléré d'une tâche planifiée avant alerte (le cron WordPress suit le trafic). */
const RETARD_CRON_TOLERE = HOUR_IN_SECONDS;

/*
 * -----------------------------------------------------------------------------
 * Tâches planifiées : dernière exécution
 * -----------------------------------------------------------------------------
 */

/**
 * Tâches planifiées connues de Yume : hook => libellé. Les autres événements planifiés dont le
 * hook commence par « yume_ » sont ajoutés avec leur hook pour libellé.
 *
 * @return array<string,string>
 */
function taches_cron_yume(): array {
	$taches = array(
		'yume_planning_rappels'             => __( 'Rappels quotidiens du planning', 'yume-core' ),
		'yume_planning_digest'              => __( 'Récapitulatif hebdomadaire des gérants', 'yume-core' ),
		'yume_notifications_envoyer'        => __( 'Envoi de la file d’e-mails (toutes les 5 minutes)', 'yume-core' ),
		'yume_social_recap_hebdo'           => __( 'Récapitulatif hebdomadaire des lecteurs', 'yume-core' ),
		'yume_publication_sortie_groupee'   => __( 'Annonce groupée des sorties programmées', 'yume-core' ),
		'yume_notifications_lecteur_purge'  => __( 'Purge des notifications des lecteurs (90 jours)', 'yume-core' ),
		'yume_push_envoyer'                 => __( 'Envoi des notifications navigateur', 'yume-core' ),
		'puc_cron_check_updates-yume-core'  => __( 'Recherche de mises à jour (extension)', 'yume-core' ),
		'puc_cron_check_updates_theme-yume' => __( 'Recherche de mises à jour (thème)', 'yume-core' ),
	);
	$cron   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
	foreach ( (array) $cron as $evenements ) {
		foreach ( array_keys( (array) $evenements ) as $hook ) {
			$hook = (string) $hook;
			if ( str_starts_with( $hook, 'yume_' ) && ! isset( $taches[ $hook ] ) ) {
				$taches[ $hook ] = $hook;
			}
		}
	}
	/**
	 * Filtre les tâches planifiées suivies par la page « Santé du site ».
	 *
	 * @param array<string,string> $taches Hook => libellé.
	 */
	return (array) apply_filters( 'yume_taches_cron', $taches );
}

/**
 * Écouteur générique : note le début de chaque tâche Yume (priorité minimale, avant le travail).
 */
function ecouter_taches_cron(): void {
	foreach ( array_keys( taches_cron_yume() ) as $hook ) {
		add_action( (string) $hook, __NAMESPACE__ . '\\noter_execution_cron', PHP_INT_MIN, 0 );
	}
}
add_action( 'init', __NAMESPACE__ . '\\ecouter_taches_cron', 1 );

/**
 * Enregistre l'horodatage de la tâche en cours (option non chargée d'office).
 *
 * @param string $hook Tâche (par défaut : action courante).
 */
function noter_execution_cron( string $hook = '' ): void {
	$hook = '' !== $hook ? $hook : (string) current_action();
	if ( '' === $hook ) {
		return;
	}
	$derniers          = get_option( OPTION_CRON_DERNIERS, array() );
	$derniers          = is_array( $derniers ) ? $derniers : array();
	$derniers[ $hook ] = time();
	update_option( OPTION_CRON_DERNIERS, $derniers, false );
}

/**
 * État des tâches planifiées Yume.
 *
 * @param int $maintenant Horodatage de référence (0 : maintenant).
 * @return array<string,array{libelle:string,derniere:int,prochaine:int,recurrence:string,retard:bool}>
 */
function etat_taches_cron( int $maintenant = 0 ): array {
	$maintenant = $maintenant > 0 ? $maintenant : time();
	$derniers   = get_option( OPTION_CRON_DERNIERS, array() );
	$derniers   = is_array( $derniers ) ? $derniers : array();
	$etat       = array();
	foreach ( taches_cron_yume() as $hook => $libelle ) {
		$evenement = wp_get_scheduled_event( (string) $hook );
		$prochaine = $evenement ? (int) $evenement->timestamp : 0;
		$derniere  = (int) ( $derniers[ $hook ] ?? 0 );
		// Tâche ponctuelle ou désactivée, jamais vue : rien à montrer.
		if ( ! $prochaine && ! $derniere && str_starts_with( (string) $hook, 'puc_' ) ) {
			continue;
		}
		$etat[ (string) $hook ] = array(
			'libelle'    => (string) $libelle,
			'derniere'   => $derniere,
			'prochaine'  => $prochaine,
			'recurrence' => $evenement && $evenement->schedule ? (string) $evenement->schedule : '',
			'retard'     => $prochaine > 0 && $prochaine < $maintenant - RETARD_CRON_TOLERE,
		);
	}
	return $etat;
}

/*
 * -----------------------------------------------------------------------------
 * E-mails, webhooks, version
 * -----------------------------------------------------------------------------
 */

/**
 * État de la file d'e-mails (module planning) : null si la file n'existe pas.
 *
 * @return array{attente:int,plus_ancienne:int,abandons:int,echecs:array}|null
 *         plus_ancienne : horodatage du plus ancien e-mail en attente (0 : aucun) ; abandons :
 *         e-mails abandonnés depuis 7 jours ; echecs : derniers échecs d'envoi (7 jours).
 */
function etat_emails(): ?array {
	global $wpdb;
	if ( ! function_exists( '\\Yume\\Core\\Planning\\table_notifications' ) ) {
		return null;
	}
	$table = \Yume\Core\Planning\table_notifications();
	$seuil = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN statut IN ('attente', 'envoi') THEN 1 ELSE 0 END) AS attente, MIN(CASE WHEN statut IN ('attente', 'envoi') THEN created_at END) AS ancienne, SUM(CASE WHEN statut = 'echec' AND created_at >= %s THEN 1 ELSE 0 END) AS abandons FROM {$table}", $seuil ) );
	$vieux = $ligne && $ligne->ancienne ? strtotime( $ligne->ancienne . ' UTC' ) : 0;
	return array(
		'attente'       => (int) ( $ligne->attente ?? 0 ),
		'plus_ancienne' => $vieux ? (int) $vieux : 0,
		'abandons'      => (int) ( $ligne->abandons ?? 0 ),
		'echecs'        => function_exists( '\\Yume\\Core\\Planning\\echecs_recents' ) ? \Yume\Core\Planning\echecs_recents( 7 ) : array(),
	);
}

/**
 * Webhooks Discord : canal => libellé, configuré, hôte (l'adresse complète, secrète, n'est
 * jamais affichée).
 *
 * @return array<string,array{libelle:string,configure:bool,hote:string}>
 */
function etat_webhooks(): array {
	$canaux = array(
		'sorties' => __( 'Annonces des sorties', 'yume-core' ),
		'equipe'  => __( 'Canal de l’équipe (rappels)', 'yume-core' ),
	);
	$etat   = array();
	foreach ( $canaux as $canal => $libelle ) {
		$url            = function_exists( '\\Yume\\Core\\Planning\\webhook' ) ? \Yume\Core\Planning\webhook( $canal ) : '';
		$etat[ $canal ] = array(
			'libelle'   => $libelle,
			'configure' => '' !== $url,
			'hote'      => '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '',
		);
	}
	return $etat;
}

/**
 * Version installée et dernière release connue, d'après l'état enregistré par la vérification
 * des mises à jour (option de Plugin Update Checker, sinon transient update_plugins) : aucune
 * requête réseau.
 *
 * @return array{installee:string,derniere:string,verifie:int,maj:bool}
 *         verifie : horodatage de la dernière vérification (0 : inconnu).
 */
function etat_version(): array {
	$installee = defined( 'YUME_CORE_VERSION' ) ? (string) YUME_CORE_VERSION : '';
	$derniere  = '';
	$verifie   = 0;
	$etat      = get_site_option( 'external_updates-yume-core', null );
	if ( is_object( $etat ) ) {
		$verifie = (int) ( $etat->lastCheck ?? 0 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( isset( $etat->update ) && is_object( $etat->update ) && ! empty( $etat->update->version ) ) {
			$derniere = (string) $etat->update->version;
		}
	}
	if ( '' === $derniere ) {
		$base      = function_exists( '\\Yume\\Core\\Updater\\base_plugin' ) ? \Yume\Core\Updater\base_plugin() : 'yume-core/yume-core.php';
		$transient = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) && isset( $transient->response[ $base ]->new_version ) ) {
			$derniere = (string) $transient->response[ $base ]->new_version;
			$verifie  = $verifie ? $verifie : (int) ( $transient->last_checked ?? 0 );
		}
	}
	return array(
		'installee' => $installee,
		// Sans offre de mise à jour enregistrée, la version installée est la plus récente connue.
		'derniere'  => '' !== $derniere ? $derniere : ( $verifie ? $installee : '' ),
		'verifie'   => $verifie,
		'maj'       => '' !== $derniere && '' !== $installee && version_compare( $derniere, $installee, '>' ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Tests « Santé du site » de WordPress
 * -----------------------------------------------------------------------------
 */

/**
 * Résultat d'un test de santé au format attendu par WordPress.
 *
 * @param string $test        Identifiant.
 * @param string $statut      good, recommended ou critical.
 * @param string $libelle     Titre.
 * @param string $description Description (HTML déjà échappé).
 */
function resultat_sante( string $test, string $statut, string $libelle, string $description ): array {
	return array(
		'label'       => $libelle,
		'status'      => $statut,
		'badge'       => array(
			'label' => __( 'Yume', 'yume-core' ),
			'color' => 'good' === $statut ? 'blue' : ( 'critical' === $statut ? 'red' : 'orange' ),
		),
		'description' => $description,
		'actions'     => current_user_can( CAPACITE_SANTE ) ? '<p><a href="' . esc_url( url_sante() ) . '">' . esc_html__( 'Ouvrir la santé du site dans l’espace équipe', 'yume-core' ) . '</a></p>' : '',
		'test'        => $test,
	);
}

/**
 * Test : tâches planifiées Yume (programmées et pas en retard).
 */
function test_sante_cron(): array {
	$etat         = etat_taches_cron();
	$manquantes   = array();
	$en_retard    = array();
	$essentielles = array( 'yume_planning_rappels', 'yume_planning_digest', 'yume_notifications_envoyer' );
	foreach ( $essentielles as $hook ) {
		if ( isset( $etat[ $hook ] ) && ! $etat[ $hook ]['prochaine'] ) {
			$manquantes[] = $etat[ $hook ]['libelle'];
		}
	}
	foreach ( $etat as $tache ) {
		if ( $tache['retard'] ) {
			$en_retard[] = $tache['libelle'];
		}
	}
	if ( $manquantes ) {
		return resultat_sante(
			'yume_taches_cron',
			'critical',
			__( 'Des tâches planifiées de Yume ne sont pas programmées', 'yume-core' ),
			'<p>' . esc_html( implode( ', ', $manquantes ) ) . '</p><p>' . esc_html__( 'Elles sont reprogrammées au prochain chargement d’une page ; si l’alerte persiste, désactivez puis réactivez l’extension Yume Core.', 'yume-core' ) . '</p>'
		);
	}
	if ( $en_retard ) {
		return resultat_sante(
			'yume_taches_cron',
			'recommended',
			__( 'Des tâches planifiées de Yume sont en retard', 'yume-core' ),
			'<p>' . esc_html( implode( ', ', $en_retard ) ) . '</p><p>' . esc_html__( 'Le cron de WordPress ne tourne qu’au passage des visiteurs : un site peu visité, ou dont le cron est désactivé (DISABLE_WP_CRON), exécute ces tâches en retard.', 'yume-core' ) . '</p>'
		);
	}
	return resultat_sante( 'yume_taches_cron', 'good', __( 'Les tâches planifiées de Yume tournent à l’heure', 'yume-core' ), '<p>' . esc_html__( 'Rappels, récapitulatifs et envoi des e-mails sont programmés et aucun n’est en retard.', 'yume-core' ) . '</p>' );
}

/**
 * Test : file d'e-mails (pas d'abandon récent, rien de bloqué depuis plus d'une heure).
 */
function test_sante_emails(): array {
	$etat = etat_emails();
	if ( null === $etat ) {
		return resultat_sante( 'yume_emails', 'good', __( 'File d’e-mails Yume absente', 'yume-core' ), '<p>' . esc_html__( 'Le module planning n’est pas chargé.', 'yume-core' ) . '</p>' );
	}
	$bloque = $etat['plus_ancienne'] && $etat['plus_ancienne'] < time() - HOUR_IN_SECONDS;
	if ( $etat['abandons'] > 0 || $bloque ) {
		$details = array();
		if ( $etat['abandons'] ) {
			/* translators: %d : nombre d'e-mails */
			$details[] = sprintf( _n( '%d e-mail abandonné après 3 tentatives ces 7 derniers jours.', '%d e-mails abandonnés après 3 tentatives ces 7 derniers jours.', $etat['abandons'], 'yume-core' ), $etat['abandons'] );
		}
		if ( $bloque ) {
			/* translators: %d : nombre d'e-mails */
			$details[] = sprintf( _n( '%d e-mail attend depuis plus d’une heure : l’envoi ne passe pas ou le cron est arrêté.', '%d e-mails attendent, dont un depuis plus d’une heure : l’envoi ne passe pas ou le cron est arrêté.', $etat['attente'], 'yume-core' ), $etat['attente'] );
		}
		return resultat_sante( 'yume_emails', 'recommended', __( 'Des e-mails de Yume ne partent pas', 'yume-core' ), '<p>' . esc_html( implode( ' ', $details ) ) . '</p>' );
	}
	return resultat_sante( 'yume_emails', 'good', __( 'Les e-mails de Yume partent normalement', 'yume-core' ), '<p>' . esc_html__( 'Aucun e-mail abandonné ces 7 derniers jours, aucun en attente depuis plus d’une heure.', 'yume-core' ) . '</p>' );
}

/**
 * Test : webhooks Discord configurés.
 */
function test_sante_webhooks(): array {
	$absents = array();
	foreach ( etat_webhooks() as $webhook ) {
		if ( ! $webhook['configure'] ) {
			$absents[] = $webhook['libelle'];
		}
	}
	if ( $absents ) {
		return resultat_sante(
			'yume_webhooks',
			'recommended',
			__( 'Webhooks Discord de Yume non configurés', 'yume-core' ),
			/* translators: %s : canaux */
			'<p>' . esc_html( sprintf( __( 'Sans webhook, rien n’est publié sur Discord pour : %s. Renseignez-les dans les Réglages de l’espace équipe (Annonces et notifications).', 'yume-core' ), implode( ', ', $absents ) ) ) . '</p>'
		);
	}
	return resultat_sante( 'yume_webhooks', 'good', __( 'Webhooks Discord de Yume configurés', 'yume-core' ), '<p>' . esc_html__( 'Les deux canaux Discord sont réglés ; le bouton « Envoyer un test » de la page « Santé du site » de l’espace équipe vérifie qu’ils répondent.', 'yume-core' ) . '</p>' );
}

/**
 * Test : version installée à jour par rapport à la dernière release connue.
 */
function test_sante_version(): array {
	$v = etat_version();
	if ( $v['maj'] ) {
		return resultat_sante(
			'yume_version',
			'recommended',
			__( 'Une nouvelle version de Yume Core est disponible', 'yume-core' ),
			/* translators: 1: version installée, 2: dernière version */
			'<p>' . esc_html( sprintf( __( 'Version installée : %1$s ; dernière release : %2$s.', 'yume-core' ), $v['installee'], $v['derniere'] ) ) . '</p>'
		);
	}
	return resultat_sante(
		'yume_version',
		'good',
		__( 'Yume Core est à jour', 'yume-core' ),
		/* translators: %s : version */
		'<p>' . esc_html( sprintf( __( 'Version installée : %s.', 'yume-core' ), $v['installee'] ) ) . ( $v['verifie'] ? '' : ' ' . esc_html__( 'Aucune recherche de mise à jour enregistrée pour le moment.', 'yume-core' ) ) . '</p>'
	);
}

/**
 * Ajoute les tests Yume à l'écran « Santé du site » (tests directs, sans requête réseau).
 *
 * @param array $tests Tests.
 * @return array
 */
function tests_sante_site( $tests ): array {
	$tests = is_array( $tests ) ? $tests : array();
	foreach ( array(
		'yume_taches_cron' => array( __( 'Tâches planifiées Yume', 'yume-core' ), 'test_sante_cron' ),
		'yume_emails'      => array( __( 'E-mails Yume', 'yume-core' ), 'test_sante_emails' ),
		'yume_webhooks'    => array( __( 'Webhooks Discord Yume', 'yume-core' ), 'test_sante_webhooks' ),
		'yume_version'     => array( __( 'Version de Yume Core', 'yume-core' ), 'test_sante_version' ),
	) as $cle => $test ) {
		$tests['direct'][ $cle ] = array(
			'label' => $test[0],
			'test'  => __NAMESPACE__ . '\\' . $test[1],
		);
	}
	return $tests;
}
add_filter( 'site_status_tests', __NAMESPACE__ . '\\tests_sante_site' );

/*
 * -----------------------------------------------------------------------------
 * Bouton « Envoyer un test »
 * -----------------------------------------------------------------------------
 */

/**
 * Traite le formulaire « Envoyer un test » (droits, nonce, canal), sans redirection.
 *
 * @param array $post    Données POST (canal, _wpnonce).
 * @param int   $user_id Utilisateur.
 * @return string Résultat : ok, echec, absent, canal, nonce ou droits.
 */
function traiter_test_webhook( array $post, int $user_id ): string {
	if ( ! $user_id || ! ( user_can( $user_id, CAPACITE_SANTE ) || user_can( $user_id, 'manage_options' ) ) ) {
		return 'droits';
	}
	$nonce = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $post['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, ACTION_TEST_WEBHOOK ) ) {
		return 'nonce';
	}
	$canal = isset( $post['canal'] ) && is_string( $post['canal'] ) ? sanitize_key( $post['canal'] ) : '';
	if ( ! isset( etat_webhooks()[ $canal ] ) || ! function_exists( '\\Yume\\Core\\Planning\\envoyer_test_discord' ) ) {
		return 'canal';
	}
	if ( ! etat_webhooks()[ $canal ]['configure'] ) {
		return 'absent';
	}
	return \Yume\Core\Planning\envoyer_test_discord( $canal, $user_id ) ? 'ok' : 'echec';
}

/**
 * Message affiché après « Envoyer un test ».
 *
 * @param string $resultat Résultat de traiter_test_webhook().
 * @param string $canal    Canal testé.
 * @return array{type:string,message:string} type : ok ou erreur.
 */
function message_test_webhook( string $resultat, string $canal ): array {
	$messages = array(
		'ok'     => array( 'ok', __( 'Message de test envoyé : vérifiez qu’il est arrivé sur Discord.', 'yume-core' ) ),
		'echec'  => array( 'erreur', __( 'Discord a refusé le message de test : vérifiez l’adresse du webhook (détail dans « Derniers échecs d’envoi »).', 'yume-core' ) ),
		'absent' => array( 'erreur', __( 'Ce webhook n’est pas configuré.', 'yume-core' ) ),
		'canal'  => array( 'erreur', __( 'Canal inconnu.', 'yume-core' ) ),
		'nonce'  => array( 'erreur', __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) ),
		'droits' => array( 'erreur', __( 'Vous n’avez pas le droit d’envoyer ce test.', 'yume-core' ) ),
	);
	$message  = $messages[ $resultat ] ?? $messages['canal'];
	$webhooks = etat_webhooks();
	return array(
		'type'    => $message[0],
		'message' => $message[1] . ( isset( $webhooks[ $canal ] ) && 'canal' !== $resultat ? ' (' . $webhooks[ $canal ]['libelle'] . ')' : '' ),
	);
}

/**
 * Action admin-post « Envoyer un test » : traite, mémorise le message puis revient sur la vue
 * « Santé du site » de l'espace équipe (Outils → Santé du site sans le module planning).
 */
function action_test_webhook(): void {
	$user_id  = get_current_user_id();
	$resultat = traiter_test_webhook( $_POST, $user_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par traiter_test_webhook().
	if ( 'droits' === $resultat ) {
		wp_die( esc_html__( 'Vous n’avez pas le droit d’envoyer ce test.', 'yume-core' ), 403 );
	}
	$canal = isset( $_POST['canal'] ) && is_string( $_POST['canal'] ) ? sanitize_key( $_POST['canal'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	wp_safe_redirect( retour_test_webhook( $resultat, $canal, $user_id ) );
	exit;
}
add_action( 'admin_post_' . ACTION_TEST_WEBHOOK, __NAMESPACE__ . '\\action_test_webhook' );

/**
 * Mémorise le message du test (retour de formulaire de l'espace équipe, lu une fois par la vue)
 * et renvoie l'adresse de retour : la zone d'annonce de la vue « Santé du site ».
 *
 * @param string $resultat Résultat de traiter_test_webhook().
 * @param string $canal    Canal testé.
 * @param int    $user_id  Utilisateur.
 */
function retour_test_webhook( string $resultat, string $canal, int $user_id ): string {
	if ( ! function_exists( '\\Yume\\Core\\Planning\\retour_formulaire' ) ) {
		return admin_url( 'site-health.php' );
	}
	$message = message_test_webhook( $resultat, $canal );
	\Yume\Core\Planning\retour_formulaire(
		$user_id,
		array(
			'type'    => $message['type'],
			'message' => $message['message'],
			'cible'   => RETOUR_SANTE,
			'details' => array(),
		)
	);
	return url_sante() . '#' . RETOUR_SANTE;
}

/*
 * -----------------------------------------------------------------------------
 * Sous-menu Yume → Santé : lien vers la vue de l'espace équipe
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse de la vue « Santé du site » de l'espace équipe (?vue=sante), ou de l'écran
 * Outils → Santé du site si le module planning (espace équipe) n'est pas chargé.
 */
function url_sante(): string {
	return function_exists( '\\Yume\\Core\\Planning\\url_vue_equipe' ) ? \Yume\Core\Planning\url_vue_equipe( 'sante' ) : admin_url( 'site-health.php' );
}

/**
 * Sous-menu « Santé » (priorité 20, avant Réglages replacé en fin de menu) : comme « Publier un
 * tome », il mène à l'espace équipe (redirection au chargement de la page).
 */
function ajouter_page_sante(): void {
	$hook = add_submenu_page(
		'yume',
		__( 'Santé du site Yume', 'yume-core' ),
		__( 'Santé', 'yume-core' ),
		CAPACITE_SANTE,
		PAGE_SANTE,
		__NAMESPACE__ . '\\afficher_page_sante'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\\rediriger_page_sante' );
	}
}
add_action( 'admin_menu', __NAMESPACE__ . '\\ajouter_page_sante', 20 );

/**
 * Yume → Santé mène à la vue « Santé du site » de l'espace équipe.
 */
function rediriger_page_sante(): void {
	if ( current_user_can( CAPACITE_SANTE ) && function_exists( '\\Yume\\Core\\Planning\\url_vue_equipe' ) ) {
		wp_safe_redirect( url_sante() );
		exit;
	}
}

/**
 * Page de repli, sans module planning (pas d'espace équipe) : renvoi vers Outils → Santé du site,
 * qui affiche les mêmes contrôles.
 */
function afficher_page_sante(): void {
	if ( ! current_user_can( CAPACITE_SANTE ) ) {
		wp_die( esc_html__( 'Vous n’avez pas accès à cette page.', 'yume-core' ), 403 );
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Santé du site Yume', 'yume-core' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Le détail de la santé du site s’affiche dans l’espace équipe, qui n’est pas disponible (module planning absent). Les mêmes contrôles figurent dans Outils → Santé du site.', 'yume-core' ) . '</p>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Ouvrir Outils → Santé du site', 'yume-core' ) . '</a></p></div></div>';
}
