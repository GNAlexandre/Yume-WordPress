<?php
/**
 * Notifications du module corrections :
 *
 * - Discord, canal « equipe » (yume_discord(), module planning), à chaque signalement :
 *   « Coquille signalée — **{Œuvre}** · {Tome} · {Chapitre}, ¶ {N} : « {extrait} » — par {pseudo}
 *   → {lien de la vue} ». Au plus un message par chapitre toutes les 10 minutes (transient
 *   yume_coquilles_discord_{chapitre}) : les signalements suivants sont comptés et le message
 *   suivant dit « +N autres signalements ». Réglage coquilles_discord, filtre
 *   yume_coquilles_discord. Pas d'e-mail.
 * - Lecteur, à « corrigé » : notification de la cloche (type « coquille ») « Votre signalement sur
 *   {Chapitre} a été corrigé, merci ! » par Social\creer_notifications() si elle existe.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

/** Fenêtre d'étouffement des messages Discord d'un chapitre, en secondes. */
const ETOUFFEMENT_DISCORD = 10 * MINUTE_IN_SECONDS;

/** Préfixe du transient d'étouffement (suivi de l'ID du chapitre). */
const TRANSIENT_DISCORD = 'yume_coquilles_discord_';

/**
 * Les signalements sont-ils annoncés sur Discord ? (réglage coquilles_discord, filtre
 * yume_coquilles_discord).
 */
function discord_actif(): bool {
	/**
	 * Filtre l'annonce des coquilles sur le Discord de l'équipe.
	 *
	 * @param bool $actif Réglage coquilles_discord (vrai par défaut).
	 */
	return (bool) apply_filters( 'yume_coquilles_discord', reglage( 'coquilles_discord', true ) );
}

/**
 * Échappe la mise en forme Markdown de Discord.
 *
 * @param string $texte Texte.
 */
function echapper_markdown( string $texte ): string {
	return (string) preg_replace( '/([\\\\*_~`|>\[\]()])/u', '\\\\$1', $texte );
}

/**
 * Texte du message Discord d'un signalement.
 *
 * @param array $ligne  Signalement normalisé.
 * @param int   $autres Signalements du chapitre passés sous silence depuis le dernier message.
 */
function message_discord( array $ligne, int $autres = 0 ): string {
	$fil = fil( $ligne );
	if ( count( $fil ) > 1 ) {
		$fil[0] = '**' . echapper_markdown( $fil[0] ) . '**';
		for ( $i = 1, $n = count( $fil ); $i < $n; $i++ ) {
			$fil[ $i ] = echapper_markdown( $fil[ $i ] );
		}
	} else {
		$fil = array_map( __NAMESPACE__ . '\\echapper_markdown', $fil );
	}
	$vue   = function_exists( 'Yume\\Core\\Planning\\url_vue_equipe' ) ? \Yume\Core\Planning\url_vue_equipe( VUE_CLE ) : home_url( '/' );
	$texte = sprintf(
		/* translators: 1: œuvre · tome · chapitre, 2: numéro du paragraphe, 3: extrait, 4: pseudo, 5: adresse de la vue */
		__( 'Coquille signalée — %1$s, ¶ %2$d : « %3$s » — par %4$s → <%5$s>', 'yume-core' ),
		implode( ' · ', $fil ),
		$ligne['paragraphe'] + 1,
		echapper_markdown( $ligne['extrait'] ),
		echapper_markdown( auteur( $ligne ) ),
		$vue
	);
	if ( $autres > 0 ) {
		/* translators: %d : nombre de signalements */
		$texte .= ' ' . sprintf( _n( '(+%d autre signalement sur ce chapitre)', '(+%d autres signalements sur ce chapitre)', $autres, 'yume-core' ), $autres );
	}
	return $texte;
}

/**
 * Annonce un signalement sur Discord (action yume_coquille_signalee), étouffée par chapitre.
 *
 * @param int $id Signalement.
 * @return bool Vrai si un message est parti.
 */
function annoncer_discord( $id ): bool {
	if ( ! discord_actif() || ! function_exists( 'yume_discord' ) ) {
		return false;
	}
	$ligne = coquille( (int) $id );
	if ( ! $ligne ) {
		return false;
	}
	$cle   = TRANSIENT_DISCORD . $ligne['chapitre_id'];
	$etat  = get_transient( $cle );
	$etat  = is_array( $etat ) ? $etat : array();
	$jusqu = (int) ( $etat['jusqua'] ?? 0 );
	$taire = (int) ( $etat['attente'] ?? 0 );
	if ( $jusqu > time() ) {
		$etat['attente'] = $taire + 1;
		set_transient( $cle, $etat, DAY_IN_SECONDS );
		return false;
	}
	set_transient(
		$cle,
		array(
			'jusqua'  => time() + ETOUFFEMENT_DISCORD,
			'attente' => 0,
		),
		DAY_IN_SECONDS
	);
	return (bool) yume_discord( 'equipe', message_discord( $ligne, $taire ) );
}
add_action( 'yume_coquille_signalee', __NAMESPACE__ . '\\annoncer_discord' );

/**
 * Notification de la cloche du lecteur quand son signalement est corrigé (action
 * yume_coquille_traitee).
 *
 * @param int    $id     Signalement.
 * @param string $statut Nouveau statut.
 * @return int[] Comptes notifiés.
 */
function notifier_lecteur( $id, $statut = '' ): array {
	if ( 'corrige' !== $statut || ! function_exists( 'Yume\\Core\\Social\\creer_notifications' ) ) {
		return array();
	}
	$ligne = coquille( (int) $id );
	if ( ! $ligne || $ligne['user_id'] <= 0 ) {
		return array();
	}
	$url = url_paragraphe( $ligne );
	return \Yume\Core\Social\creer_notifications(
		array( $ligne['user_id'] ),
		'coquille',
		$ligne['id'],
		sprintf(
			/* translators: %s : chapitre */
			__( 'Votre signalement sur %s a été corrigé, merci !', 'yume-core' ),
			get_post( $ligne['chapitre_id'] ) ? libelle_chapitre( $ligne['chapitre_id'] ) : __( 'un chapitre', 'yume-core' )
		),
		'' !== $url ? $url : home_url( '/' )
	);
}
add_action( 'yume_coquille_traitee', __NAMESPACE__ . '\\notifier_lecteur', 10, 2 );
