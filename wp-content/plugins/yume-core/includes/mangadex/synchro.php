<?php
/**
 * Synchronisation des chapitres MangaDex : tâche planifiée et page Yume → Mangas (MangaDex)
 * (dernier rapport, simulation, synchronisation immédiate). Logique : class-synchro.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/** Slug de la page d'administration. */
const PAGE = 'yume-mangadex';

/** Action admin-post de la page. */
const ACTION = 'yume_mangadex_synchro';

/**
 * Planifie la tâche horaire quand la synchronisation est active, la retire sinon.
 */
function planifier(): void {
	$prevue = wp_next_scheduled( Synchro::CRON );
	if ( Synchro::active() && ! $prevue ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', Synchro::CRON );
	} elseif ( ! Synchro::active() && $prevue ) {
		wp_clear_scheduled_hook( Synchro::CRON );
	}
}
add_action( 'init', __NAMESPACE__ . '\\planifier', 20 );

/**
 * Tâche horaire : synchronise si c'est toujours actif.
 */
function tache(): void {
	if ( Synchro::active() ) {
		Synchro::executer();
	}
}
add_action( Synchro::CRON, __NAMESPACE__ . '\\tache' );

/**
 * Désactivation de l'extension : tâche retirée.
 */
function desactiver(): void {
	wp_clear_scheduled_hook( Synchro::CRON );
}
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\desactiver' );

/**
 * Sous-menu Yume → Mangas (MangaDex).
 */
function menu(): void {
	add_submenu_page( 'yume', __( 'Mangas (MangaDex)', 'yume-core' ), __( 'Mangas (MangaDex)', 'yume-core' ), 'yume_reglages', PAGE, __NAMESPACE__ . '\\page' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\menu', 20 );

/**
 * Lance une simulation ou une synchronisation depuis la page (jeton, droit yume_reglages).
 */
function traiter_page(): void {
	if ( ! current_user_can( 'yume_reglages' ) ) {
		wp_die( esc_html__( 'Accès refusé.', 'yume-core' ), 403 );
	}
	check_admin_referer( ACTION );
	$simulation = ! ( isset( $_POST['mode'] ) && 'synchroniser' === sanitize_key( wp_unslash( $_POST['mode'] ) ) );
	if ( ! $simulation && ! Synchro::active() ) {
		$simulation = true;
	}
	$rapport = Synchro::executer( $simulation );
	if ( $simulation ) {
		set_transient( 'yume_mdx_simulation_' . get_current_user_id(), $rapport, HOUR_IN_SECONDS );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => PAGE ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\traiter_page' );

/**
 * Rendu d'un rapport de synchronisation (HTML échappé).
 *
 * @param array<string,mixed> $rapport Rapport.
 */
function html_rapport( array $rapport ): string {
	if ( ! $rapport ) {
		return '<p>' . esc_html__( 'Aucune synchronisation pour le moment.', 'yume-core' ) . '</p>';
	}
	$date = (string) ( $rapport['date'] ?? '' );
	$html = '<p><strong>' . esc_html( ! empty( $rapport['simulation'] ) ? __( 'Simulation (rien n’a été modifié)', 'yume-core' ) : __( 'Synchronisation', 'yume-core' ) ) . '</strong> · '
		. esc_html( '' !== $date ? wp_date( 'j F Y, H:i', (int) strtotime( $date . ' UTC' ) ) : '' ) . '</p>';
	if ( empty( $rapport['ok'] ) ) {
		return $html . '<div class="notice notice-error inline"><p>' . esc_html( (string) ( $rapport['erreur'] ?? '' ) ) . ' ' . esc_html__( 'Rien n’a été modifié.', 'yume-core' ) . '</p></div>';
	}
	$lignes = array(
		__( 'Chapitres du groupe sur MangaDex', 'yume-core' ) => (int) $rapport['chapitres_mangadex'],
		__( 'Chapitres créés', 'yume-core' )              => (int) $rapport['crees'],
		__( 'Chapitres mis à jour', 'yume-core' )         => (int) $rapport['maj'],
		__( 'Chapitres remis en ligne', 'yume-core' )     => (int) $rapport['remis_en_ligne'],
		__( 'Chapitres retirés (absents de MangaDex)', 'yume-core' ) => (int) $rapport['retires'],
		__( 'Tomes créés', 'yume-core' )                  => (int) $rapport['tomes_crees'],
		__( 'Tomes vides mis en brouillon', 'yume-core' ) => (int) $rapport['tomes_vides'],
		__( 'Chapitres hébergés hors de MangaDex (ignorés)', 'yume-core' ) => (int) $rapport['externes'],
	);
	$html  .= '<table class="widefat striped" style="max-width:640px"><tbody>';
	foreach ( $lignes as $libelle => $nombre ) {
		$html .= '<tr><th scope="row">' . esc_html( $libelle ) . '</th><td>' . (int) $nombre . '</td></tr>';
	}
	$html .= '</tbody></table>';
	foreach ( (array) $rapport['alertes'] as $alerte ) {
		$html .= '<div class="notice notice-warning inline"><p>' . esc_html( (string) $alerte ) . '</p></div>';
	}
	if ( $rapport['oeuvres'] ) {
		$html .= '<h3>' . esc_html__( 'Œuvres', 'yume-core' ) . '</h3><ul>';
		foreach ( (array) $rapport['oeuvres'] as $o ) {
			/* translators: 1 : chapitres lisibles, 2 : créés, 3 : mis à jour, 4 : retirés */
			$detail = sprintf( __( '%1$d chapitres sur MangaDex : %2$d créés, %3$d mis à jour, %4$d retirés', 'yume-core' ), (int) $o['chapitres'], (int) $o['crees'], (int) $o['maj'], (int) $o['retires'] );
			$html  .= '<li><a href="' . esc_url( (string) get_edit_post_link( (int) $o['oeuvre'] ) ) . '">' . esc_html( (string) $o['titre'] ) . '</a> — ' . esc_html( $detail )
				. ( ! empty( $o['import'] ) ? ' <em>' . esc_html__( '(import initial, sans annonce)', 'yume-core' ) . '</em>' : '' ) . '</li>';
		}
		$html .= '</ul>';
	}
	if ( $rapport['series_non_liees'] ) {
		$html .= '<h3>' . esc_html__( 'Séries du groupe liées à aucune œuvre', 'yume-core' ) . '</h3><p>' . esc_html__( 'Leurs chapitres ne sont pas repris. Pour les lire sur le site : ouvrir l’œuvre (ou la créer) et coller l’adresse de la série dans sa boîte « MangaDex ».', 'yume-core' ) . '</p><ul>';
		foreach ( (array) $rapport['series_non_liees'] as $s ) {
			/* translators: %d : nombre de chapitres */
			$html .= '<li><a href="' . esc_url( 'https://mangadex.org/title/' . $s['serie'] ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $s['titre'] ? $s['titre'] : $s['serie'] ) . '</a> — ' . esc_html( sprintf( _n( '%d chapitre', '%d chapitres', (int) $s['chapitres'], 'yume-core' ), (int) $s['chapitres'] ) ) . '</li>';
		}
		$html .= '</ul>';
	}
	if ( $rapport['oeuvres_non_publiees'] ) {
		$html .= '<h3>' . esc_html__( 'Œuvres liées mais non publiées (ignorées)', 'yume-core' ) . '</h3><ul>';
		foreach ( (array) $rapport['oeuvres_non_publiees'] as $o ) {
			$html .= '<li><a href="' . esc_url( (string) get_edit_post_link( (int) $o['oeuvre'] ) ) . '">' . esc_html( (string) $o['titre'] ) . '</a></li>';
		}
		$html .= '</ul>';
	}
	return $html;
}

/**
 * Page Yume → Mangas (MangaDex) : état, dernier rapport, simulation, synchronisation.
 */
function page(): void {
	if ( ! current_user_can( 'yume_reglages' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Mangas (MangaDex)', 'yume-core' ) . '</h1>';
	if ( ! lecteur_pret() ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Le lecteur de mangas n’est pas encore livré : la synchronisation reste coupée. La simulation montre déjà ce qu’elle ferait.', 'yume-core' ) . '</p></div>';
	} elseif ( ! Synchro::active() ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'La synchronisation est coupée : cochez « Synchroniser les chapitres » dans Yume → Réglages → Mangas (MangaDex).', 'yume-core' ) . '</p></div>';
	} else {
		$prochaine = wp_next_scheduled( Synchro::CRON );
		/* translators: %s : date */
		echo '<p>' . esc_html( $prochaine ? sprintf( __( 'Prochaine synchronisation : %s.', 'yume-core' ), wp_date( 'j F Y, H:i', $prochaine ) ) : __( 'Synchronisation active.', 'yume-core' ) ) . '</p>';
	}
	/* translators: %s : identifiant du groupe */
	echo '<p>' . esc_html( sprintf( __( 'Groupe MangaDex : %s', 'yume-core' ), groupe() ) ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( ACTION );
	echo '<input type="hidden" name="action" value="' . esc_attr( ACTION ) . '">';
	echo '<p><button type="submit" name="mode" value="simuler" class="button">' . esc_html__( 'Simuler (sans rien changer)', 'yume-core' ) . '</button> ';
	if ( Synchro::active() ) {
		echo '<button type="submit" name="mode" value="synchroniser" class="button button-primary">' . esc_html__( 'Synchroniser maintenant', 'yume-core' ) . '</button>';
	}
	echo '</p></form>';
	$simulation = get_transient( 'yume_mdx_simulation_' . get_current_user_id() );
	if ( is_array( $simulation ) ) {
		echo '<h2>' . esc_html__( 'Dernière simulation', 'yume-core' ) . '</h2>' . html_rapport( $simulation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans html_rapport().
	}
	echo '<h2>' . esc_html__( 'Dernière synchronisation', 'yume-core' ) . '</h2>' . html_rapport( Synchro::dernier_rapport() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans html_rapport().
	echo '</div>';
}
