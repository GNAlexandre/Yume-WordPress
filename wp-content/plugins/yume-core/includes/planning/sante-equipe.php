<?php
/**
 * Espace équipe, vue « Santé du site » (?vue=sante, audit AMEL-12) : tâches planifiées (dernière
 * et prochaine exécution, état), file d'e-mails (attente, abandons, derniers échecs), webhooks
 * Discord avec le bouton « Envoyer un test », version installée et dernière release connue,
 * prérequis de mise en production (administrateurs seulement).
 *
 * Rendu seulement : les données viennent de includes/core/admin/sante.php (etat_taches_cron(),
 * etat_emails(), etat_webhooks(), etat_version()) et de notices.php (prerequis_manquants()) ; le
 * bouton de test poste vers l'action admin-post yume_sante_webhook, qui revient ici. Le sous-menu
 * Yume → Santé de l'administration redirige vers cette vue.
 *
 * Capacité yume_reglages (gérants et administrateurs), comme les Indicateurs et les Réglages.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

use const Yume\Core\Core\ACTION_TEST_WEBHOOK;
use const Yume\Core\Core\CAPACITE_SANTE;
use const Yume\Core\Core\RETOUR_SANTE;
use const Yume\Core\Core\URL_DOC_MISE_EN_PRODUCTION;

defined( 'ABSPATH' ) || exit;

/**
 * Déclare la vue dans l'espace équipe (filtre yume_vues_equipe, priorité tardive : dernière vue
 * ajoutée, juste avant « Réglages »).
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue_sante( $vues ): array {
	$vues = is_array( $vues ) ? $vues : array();
	if ( ! function_exists( '\\Yume\\Core\\Core\\etat_taches_cron' ) ) {
		return $vues;
	}
	$vues['sante'] = array(
		'libelle'  => __( 'Santé du site', 'yume-core' ),
		'capacite' => CAPACITE_SANTE,
		'groupe'   => 'site',
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_sante',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue_sante', 100 );

/**
 * Feuilles de style de la vue (celle des Indicateurs pour les cartes et tableaux, plus la sienne).
 */
function enregistrer_style_sante(): void {
	wp_register_style( 'yume-sante', YUME_CORE_URL . 'includes/planning/assets/sante.css', array( 'yume-kpi' ), YUME_CORE_VERSION );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style_sante' );

/**
 * Date et heure en français, heure de Paris (« 29 septembre 2026 à 9 h 00 »), quelle que soit la
 * langue du site ; $jamais si l'horodatage est vide.
 *
 * @param int    $ts     Horodatage.
 * @param string $jamais Texte si vide.
 */
function date_sante( int $ts, string $jamais ): string {
	return $ts > 0 ? format_fr( $ts, 'j F Y à G \h i' ) : $jamais;
}

/**
 * Pastille d'état : icône et texte (jamais la couleur seule).
 *
 * @param string $etat  ok, alerte, erreur ou neutre.
 * @param string $texte Libellé.
 */
function pastille_sante( string $etat, string $texte ): string {
	$styles = array(
		'ok'     => array( 'ok', '✓' ),
		'alerte' => array( 'warn', '▲' ),
		'erreur' => array( 'err', '■' ),
		'neutre' => array( 'info', '–' ),
	);
	$style  = $styles[ $etat ] ?? $styles['neutre'];
	return '<span class="yn-chip yn-chip--' . $style[0] . '"><span aria-hidden="true">' . $style[1] . '</span> ' . esc_html( $texte ) . '</span>';
}

/**
 * Tableau de la vue : en-têtes de colonnes, première cellule en-tête de ligne ; chaque cellule
 * porte le libellé de sa colonne (data-libelle), affiché devant la valeur quand le tableau
 * s'empile en liste sur petit écran.
 *
 * @param string   $id      Identifiant de la légende.
 * @param string   $legende Légende (lecteurs d'écran).
 * @param string[] $entetes En-têtes.
 * @param array    $lignes  Lignes : cellules déjà échappées.
 */
function tableau_sante( string $id, string $legende, array $entetes, array $lignes ): string {
	$html = '<table class="yn-kpi__table yn-sante__table"><caption id="' . esc_attr( $id ) . '" class="yn-visually-hidden">' . esc_html( $legende ) . '</caption><thead><tr>';
	foreach ( $entetes as $entete ) {
		$html .= '<th scope="col">' . esc_html( $entete ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $lignes as $cellules ) {
		$html .= '<tr>';
		foreach ( array_values( $cellules ) as $i => $cellule ) {
			$attr  = ' data-libelle="' . esc_attr( (string) ( $entetes[ $i ] ?? '' ) ) . '"';
			$html .= $i ? '<td' . $attr . '>' . $cellule . '</td>' : '<th scope="row"' . $attr . '>' . $cellule . '</th>';
		}
		$html .= '</tr>';
	}
	return $html . '</tbody></table>';
}

/**
 * Zone d'annonce du résultat du bouton « Envoyer un test ».
 *
 * @param array|null $retour Retour mémorisé (retour_formulaire()).
 */
function zone_retour_sante( ?array $retour ): string {
	$classe = 'yn-team__retour yn-reglages__retour';
	$corps  = '';
	if ( $retour ) {
		$classe .= 'ok' === ( $retour['type'] ?? '' ) ? ' yn-team__retour--ok' : ' yn-team__retour--erreur';
		$corps   = '<p>' . esc_html( (string) ( $retour['message'] ?? '' ) ) . '</p>';
	}
	return '<div class="' . esc_attr( $classe ) . '" id="' . esc_attr( RETOUR_SANTE ) . '" role="status" aria-live="polite" tabindex="-1">' . $corps . '</div>';
}

/**
 * Carte « Tâches planifiées ».
 *
 * @param array $taches État des tâches (etat_taches_cron()).
 */
function carte_taches_sante( array $taches ): string {
	$lignes = array();
	foreach ( $taches as $hook => $tache ) {
		if ( $tache['retard'] ) {
			$etat = pastille_sante( 'alerte', __( 'En retard', 'yume-core' ) );
		} elseif ( $tache['prochaine'] ) {
			$etat = pastille_sante( 'ok', __( 'Programmée', 'yume-core' ) );
		} else {
			$etat = pastille_sante( 'neutre', __( 'Non programmée', 'yume-core' ) );
		}
		$lignes[] = array(
			esc_html( $tache['libelle'] ) . '<code class="yn-sante__hook">' . esc_html( (string) $hook ) . '</code>',
			esc_html( date_sante( (int) $tache['derniere'], __( 'pas encore mesurée', 'yume-core' ) ) ),
			esc_html( date_sante( (int) $tache['prochaine'], '—' ) ),
			$etat,
		);
	}
	$html  = '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-sante-taches"><h2 id="yn-sante-taches">' . esc_html__( 'Tâches planifiées', 'yume-core' ) . '</h2>';
	$html .= '<p class="yn-muted">' . esc_html__( 'Le cron de WordPress se déclenche au passage des visiteurs : un léger retard est normal. La dernière exécution n’est connue que depuis l’installation de cette page.', 'yume-core' ) . '</p>';
	$html .= $lignes
		? tableau_sante( 'yn-sante-t-taches', __( 'Tâches planifiées : dernière et prochaine exécution, état', 'yume-core' ), array( __( 'Tâche', 'yume-core' ), __( 'Dernière exécution', 'yume-core' ), __( 'Prochaine exécution', 'yume-core' ), __( 'État', 'yume-core' ) ), $lignes )
		: '<p class="yn-muted">' . esc_html__( 'Aucune tâche planifiée.', 'yume-core' ) . '</p>';
	return $html . '</section>';
}

/**
 * Carte « E-mails » : file d'attente, abandons et derniers échecs.
 *
 * @param array|null $emails État de la file (etat_emails()).
 */
function carte_emails_sante( ?array $emails ): string {
	$html = '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-sante-emails"><h2 id="yn-sante-emails">' . esc_html__( 'E-mails', 'yume-core' ) . '</h2>';
	if ( null === $emails ) {
		return $html . '<p class="yn-muted">' . esc_html__( 'La file d’e-mails n’est pas chargée.', 'yume-core' ) . '</p></section>';
	}
	/* translators: %d : nombre */
	$attente = sprintf( _n( '%d e-mail en attente d’envoi', '%d e-mails en attente d’envoi', $emails['attente'], 'yume-core' ), $emails['attente'] );
	if ( $emails['plus_ancienne'] ) {
		/* translators: %s : date */
		$attente .= ' · ' . sprintf( __( 'le plus ancien depuis le %s', 'yume-core' ), date_sante( (int) $emails['plus_ancienne'], '' ) );
	}
	$html .= '<ul class="yn-kpi__liste"><li>' . esc_html( $attente ) . '</li>';
	/* translators: %d : nombre */
	$html .= '<li>' . esc_html( sprintf( _n( '%d e-mail abandonné après 3 tentatives ces 7 derniers jours', '%d e-mails abandonnés après 3 tentatives ces 7 derniers jours', $emails['abandons'], 'yume-core' ), $emails['abandons'] ) ) . '</li></ul>';
	$html .= '<h3 class="yn-sante__sous-titre">' . esc_html__( 'Derniers échecs d’envoi (7 jours)', 'yume-core' ) . '</h3>';
	if ( ! $emails['echecs'] ) {
		return $html . '<p>' . pastille_sante( 'ok', __( 'Aucun échec d’envoi', 'yume-core' ) ) . '</p></section>';
	}
	$html .= '<ul class="yn-sante__echecs">';
	foreach ( array_slice( $emails['echecs'], 0, 10 ) as $echec ) {
		$ts    = strtotime( (string) ( $echec['date'] ?? '' ) . ' UTC' );
		$type  = 'discord' === ( $echec['type'] ?? '' ) ? 'Discord' : __( 'E-mail', 'yume-core' );
		$html .= '<li>' . pastille_sante( 'erreur', $type ) . ' <span class="yn-muted">' . esc_html( date_sante( $ts ? (int) $ts : 0, '' ) ) . '</span> ' . esc_html( (string) ( $echec['message'] ?? '' ) ) . '</li>';
	}
	return $html . '</ul></section>';
}

/**
 * Carte « Webhooks Discord » : état de chaque canal (hôte seulement, jamais l'adresse secrète)
 * et bouton « Envoyer un test ».
 *
 * @param array $webhooks État des webhooks (etat_webhooks()).
 */
function carte_webhooks_sante( array $webhooks ): string {
	$lignes = array();
	foreach ( $webhooks as $canal => $webhook ) {
		if ( $webhook['configure'] ) {
			/* translators: %s : hôte du webhook */
			$etat   = pastille_sante( 'ok', sprintf( __( 'Configuré (%s)', 'yume-core' ), $webhook['hote'] ) );
			$action = '<form class="yn-sante__test" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( ACTION_TEST_WEBHOOK ) . '"><input type="hidden" name="canal" value="' . esc_attr( (string) $canal ) . '">'
				. wp_nonce_field( ACTION_TEST_WEBHOOK, '_wpnonce', false, false )
				/* translators: %s : canal */
				. '<button type="submit" class="yn-btn yn-btn--sm" aria-label="' . esc_attr( sprintf( __( 'Envoyer un test : %s', 'yume-core' ), $webhook['libelle'] ) ) . '">' . esc_html__( 'Envoyer un test', 'yume-core' ) . '</button></form>';
		} else {
			$etat   = pastille_sante( 'alerte', __( 'Non configuré', 'yume-core' ) );
			$action = '<a href="' . esc_url( url_vue_equipe( 'reglages' ) . '#yn-reglages-notifications' ) . '">' . esc_html__( 'Régler dans les Réglages', 'yume-core' ) . '</a>';
		}
		$lignes[] = array( esc_html( $webhook['libelle'] ), $etat, $action );
	}
	$html  = '<section class="yn-card yn-kpi__carte" id="yn-sante-webhooks" aria-labelledby="yn-sante-webhooks-titre"><h2 id="yn-sante-webhooks-titre">' . esc_html__( 'Webhooks Discord', 'yume-core' ) . '</h2>';
	$html .= '<p class="yn-muted">' . esc_html__( 'Le test publie un court message sur le canal : vérifiez qu’il arrive sur Discord.', 'yume-core' ) . '</p>';
	$html .= tableau_sante( 'yn-sante-t-webhooks', __( 'Webhooks Discord : état et test', 'yume-core' ), array( __( 'Canal', 'yume-core' ), __( 'État', 'yume-core' ), __( 'Test', 'yume-core' ) ), $lignes );
	return $html . '</section>';
}

/**
 * Carte « Version ».
 *
 * @param array $v État de la version (etat_version()).
 */
function carte_version_sante( array $v ): string {
	$html = '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-sante-version"><h2 id="yn-sante-version">' . esc_html__( 'Version', 'yume-core' ) . '</h2><ul class="yn-kpi__liste">';
	/* translators: %s : version */
	$html .= '<li>' . esc_html( sprintf( __( 'Version installée : %s', 'yume-core' ), $v['installee'] ) ) . '</li>';
	$html .= '<li>' . esc_html(
		'' !== $v['derniere']
			/* translators: %s : version */
			? sprintf( __( 'Dernière release connue : %s', 'yume-core' ), $v['derniere'] )
			: __( 'Dernière release : pas encore vérifiée (la recherche automatique tourne toutes les 12 heures).', 'yume-core' )
	) . '</li>';
	if ( $v['verifie'] ) {
		/* translators: %s : date */
		$html .= '<li>' . esc_html( sprintf( __( 'Dernière recherche de mise à jour : %s', 'yume-core' ), date_sante( (int) $v['verifie'], '' ) ) ) . '</li>';
	}
	$html .= '</ul><p>';
	if ( $v['maj'] ) {
		$html .= pastille_sante( 'alerte', __( 'Une mise à jour est disponible', 'yume-core' ) );
		if ( current_user_can( 'update_plugins' ) ) {
			$html .= ' <a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Voir les mises à jour', 'yume-core' ) . '</a>';
		}
	} else {
		$html .= pastille_sante( 'ok', __( 'À jour', 'yume-core' ) );
	}
	return $html . '</p></section>';
}

/**
 * Carte « Prérequis de mise en production » (administrateurs : réglages du site).
 *
 * @param array<string,string> $manquants Prérequis non remplis (prerequis_manquants()).
 */
function carte_prerequis_sante( array $manquants ): string {
	$html = '<section class="yn-card yn-kpi__carte" id="yn-sante-prerequis" aria-labelledby="yn-sante-prerequis-titre"><h2 id="yn-sante-prerequis-titre">' . esc_html__( 'Prérequis de mise en production', 'yume-core' ) . '</h2>';
	if ( ! $manquants ) {
		$html .= '<p>' . pastille_sante( 'ok', __( 'Tous les prérequis vérifiables sont remplis', 'yume-core' ) ) . '</p>';
	} else {
		$html .= '<p class="yn-muted">' . esc_html__( 'Réglages à corriger avant d’ouvrir le site au public :', 'yume-core' ) . '</p><ul class="yn-kpi__liste">';
		foreach ( $manquants as $cle => $message ) {
			$html .= '<li data-prerequis="' . esc_attr( (string) $cle ) . '">' . esc_html( $message ) . '</li>';
		}
		$html .= '</ul>';
	}
	$html .= '<p><a href="' . esc_url( URL_DOC_MISE_EN_PRODUCTION ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Liste complète de mise en production', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a></p>';
	return $html . '</section>';
}

/**
 * Vue « Santé du site » (?vue=sante).
 */
function rendu_vue_sante(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--kpi yn-team--sante' );
	$html .= navigation_equipe( 'sante' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( CAPACITE_SANTE ) ) {
		$html .= tete_vue( __( 'Santé du site', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les gérants et les administrateurs peuvent consulter la santé du site.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}
	wp_enqueue_style( 'yume-sante' );

	$taches   = \Yume\Core\Core\etat_taches_cron();
	$emails   = \Yume\Core\Core\etat_emails();
	$webhooks = \Yume\Core\Core\etat_webhooks();
	$version  = \Yume\Core\Core\etat_version();
	$retour   = est_apercu_editeur() ? null : retour_formulaire( get_current_user_id() );
	$retour   = $retour && RETOUR_SANTE === ( $retour['cible'] ?? '' ) ? $retour : null;

	$boutons = current_user_can( 'view_site_health_checks' )
		? lien_nouvel_onglet( admin_url( 'site-health.php' ), __( 'Outils → Santé du site', 'yume-core' ), 'yn-btn yn-btn--sm' )
		: '';
	$html   .= tete_vue( __( 'Santé du site', 'yume-core' ), $boutons );
	$html   .= '<p class="yn-muted">' . esc_html__( 'Tâches automatiques, e-mails, Discord et version installée. Les mêmes contrôles figurent dans Outils → Santé du site de l’administration.', 'yume-core' ) . '</p>';
	$html   .= zone_retour_sante( $retour );

	// Chiffres clés.
	$programmees = count( array_filter( array_column( $taches, 'prochaine' ) ) );
	$en_retard   = count( array_filter( array_column( $taches, 'retard' ) ) );
	$configures  = count( array_filter( array_column( $webhooks, 'configure' ) ) );
	$html       .= '<div class="yn-team__chiffres">';
	$html       .= chiffre_equipe(
		__( 'Tâches planifiées', 'yume-core' ),
		number_format_i18n( $programmees ),
		/* translators: %d : tâches en retard */
		$en_retard ? sprintf( _n( 'programmées, dont %d en retard', 'programmées, dont %d en retard', $en_retard, 'yume-core' ), $en_retard ) : __( 'programmées, aucune en retard', 'yume-core' ),
		$en_retard > 0
	);
	if ( null !== $emails ) {
		$html .= chiffre_equipe( __( 'E-mails en attente', 'yume-core' ), number_format_i18n( (int) $emails['attente'] ), __( 'dans la file d’envoi', 'yume-core' ) );
		$html .= chiffre_equipe( __( 'E-mails abandonnés', 'yume-core' ), number_format_i18n( (int) $emails['abandons'] ), __( 'ces 7 derniers jours', 'yume-core' ), $emails['abandons'] > 0 );
	}
	/* translators: 1: webhooks configurés, 2: total */
	$html .= chiffre_equipe( __( 'Webhooks Discord', 'yume-core' ), sprintf( __( '%1$d sur %2$d', 'yume-core' ), $configures, count( $webhooks ) ), __( 'canaux configurés', 'yume-core' ), $configures < count( $webhooks ) );
	$html .= '</div>';

	$html .= carte_taches_sante( $taches );
	$html .= '<div class="yn-kpi__grille">' . carte_emails_sante( $emails ) . carte_webhooks_sante( $webhooks ) . '</div>';
	$html .= '<div class="yn-kpi__grille">' . carte_version_sante( $version );
	// Prérequis de mise en production (administrateurs : ils portent sur des réglages du site).
	if ( current_user_can( 'manage_options' ) ) {
		$html .= carte_prerequis_sante( \Yume\Core\Core\prerequis_manquants( \Yume\Core\Core\etat_prerequis() ) );
	}
	$html .= '</div>';

	return $html . '</div></div>';
}
