<?php
/**
 * Espace équipe, vue « Mes tâches » (?vue=taches, capacité yume_voir_equipe) : toutes les tâches
 * du membre connecté (tomes dont il est responsable d'une étape non encore passée, voir
 * taches()), en retard d'abord, avec les mêmes cartes que le tableau de bord (carte_tache() :
 * curseur d'avancement, étape, date cible, blocage, note, formulaire admin-post
 * yume_planning_maj avec nonce, ou REST avec JavaScript). Sans JavaScript, l'envoi revient sur
 * cette vue (champ _wp_http_referer du nonce, voir rediriger_retour()) et le message s'affiche
 * dans la carte concernée, ou en tête de liste si la tâche n'est plus la sienne.
 *
 * L'entrée « Mes tâches » de la navigation mène ici (auparavant une ancre du tableau de bord,
 * sans effet visible puisque la section y est déjà affichée) ; les rappels de retard aussi.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Vue « Mes tâches » (?vue=taches).
 */
function rendu_vue_taches(): string {
	$uid     = get_current_user_id();
	$taches  = taches(
		yume_get_planning(
			array(
				'a_venir' => true,
				'public'  => false,
			)
		),
		$uid
	);
	$retour  = est_apercu_editeur() ? null : retour_formulaire( $uid );
	$retards = count(
		array_filter(
			$taches,
			static function ( array $t ): bool {
				return 'en_retard' === $t['ligne']['etat'] && ! $t['attente'];
			}
		)
	);

	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--taches' );
	$html .= navigation_equipe( 'taches', $retards );
	$html .= '<div class="yn-team__principal">';
	$html .= tete_vue( __( 'Mes tâches', 'yume-core' ), '<a class="yn-btn" href="' . esc_url( url_vue_equipe( 'planning' ) ) . '">' . esc_html__( 'Planning complet', 'yume-core' ) . '</a>' );

	$cibles = array();
	foreach ( $taches as $tache ) {
		$cibles[] = 'yn-tache-' . (int) $tache['ligne']['tome_id'];
	}
	// Retour d'une tâche qui n'est plus la vôtre (étape passée, responsable changé) : en tête.
	$orphelin = $retour && ! in_array( (string) ( $retour['cible'] ?? '' ), $cibles, true ) ? $retour : null;
	$html    .= '<div id="yn-taches-retour">' . zone_retour( $orphelin ) . '</div>';

	$html .= '<section class="yn-team__section" id="yn-mes-taches" aria-labelledby="yn-mes-taches-titre"><div class="yn-team__section-tete">';
	$html .= '<h2 id="yn-mes-taches-titre">' . esc_html(
		$taches
			/* translators: %d : nombre de tâches */
			? sprintf( _n( '%d tâche en cours', '%d tâches en cours', count( $taches ), 'yume-core' ), count( $taches ) )
			: __( 'Aucune tâche en cours', 'yume-core' )
	) . '</h2>';
	if ( $retards ) {
		/* translators: %d : nombre de retards */
		$html .= '<span class="yn-chip yn-chip--warn">' . esc_html( sprintf( _n( '%d en retard', '%d en retard', $retards, 'yume-core' ), $retards ) ) . '</span>';
	}
	$html .= '</div>';
	if ( ! $taches ) {
		$html .= '<div class="yn-card yn-team__vide"><p>' . esc_html__( 'Aucune tâche ne vous est attribuée pour le moment : vous n’êtes responsable d’aucune étape à venir (traduction, relecture ou édition).', 'yume-core' ) . '</p>';
		$html .= '<p class="yn-muted">' . esc_html__( 'Demandez à un éditeur ou à un gérant de vous confier un tome ; il apparaîtra ici avec son avancement à mettre à jour.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe( 'planning' ) ) . '">' . esc_html__( 'Voir le planning complet', 'yume-core' ) . '</a></p></div>';
	}
	foreach ( $taches as $tache ) {
		$cible = 'yn-tache-' . (int) $tache['ligne']['tome_id'];
		$html .= carte_tache( $tache, $retour && ( $retour['cible'] ?? '' ) === $cible ? $retour : null );
	}
	$html .= '</section>';

	return $html . '</div></div>';
}
