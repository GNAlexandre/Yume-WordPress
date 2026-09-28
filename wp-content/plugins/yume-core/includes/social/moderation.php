<?php
/**
 * Modération des commentaires en façade (AMEL-10) :
 *
 * - signalement d'un commentaire par un lecteur connecté (route REST
 *   POST /yume/v1/commentaires/{id}/signalement, voir rest.php) : un signalement par compte et
 *   par commentaire, motif court facultatif, limite de débit ; au-delà du seuil
 *   (yume_signalements_seuil, 3 par défaut), le commentaire repasse en attente de modération.
 *   Seuls comptent pour ce seuil les comptes inscrits depuis au moins 7 jours
 *   (yume_signalement_anciennete) : des comptes créés à la chaîne ne suffisent pas à masquer
 *   un commentaire. Un commentaire d'un membre de l'équipe (yume_voir_equipe) ne passe jamais
 *   automatiquement en attente : il reste publié et signalé dans la vue de modération.
 *   Les signalements sont gardés en méta de commentaire (_yume_signalements : ID du compte =>
 *   motif, date, ignoré) ;
 * - vue « Commentaires » de l'espace équipe (?vue=commentaires, capacité moderate_comments,
 *   filtre yume_vues_equipe ; entrée « Commentaires (N) » seulement s'il y a à modérer) :
 *   commentaires en attente et commentaires signalés, actions
 *   Approuver / Indésirable / Corbeille / Ignorer les signalements (admin-post.php, action
 *   yume_moderation, nonce, edit_comment).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\ouvrir_racine;
use function Yume\Core\Planning\rediriger_retour;
use function Yume\Core\Planning\retour_formulaire;
use function Yume\Core\Planning\tete_vue;
use function Yume\Core\Planning\url_vue_equipe;
use function Yume\Core\Planning\zone_retour;

defined( 'ABSPATH' ) || exit;

/** Méta de commentaire : signalements (ID du compte => motif, date GMT, ignoré). */
const META_SIGNALEMENTS = '_yume_signalements';

/** Longueur maximale du motif d'un signalement. */
const MOTIF_SIGNALEMENT_MAX = 200;

/** Commentaires affichés par liste dans la vue de modération. */
const COMMENTAIRES_PAR_LISTE = 50;

/*
 * -----------------------------------------------------------------------------
 * Signalements
 * -----------------------------------------------------------------------------
 */

/**
 * Nombre de signalements à partir duquel un commentaire repasse en attente de modération.
 */
function seuil_signalements(): int {
	/**
	 * Nombre de signalements (comptes différents) qui renvoie un commentaire en attente de
	 * modération.
	 *
	 * @param int $seuil Défaut 3.
	 */
	return max( 1, (int) apply_filters( 'yume_signalements_seuil', 3 ) );
}

/**
 * Ancienneté minimale (en jours) d'un compte pour que son signalement compte dans le seuil de
 * mise en attente automatique.
 */
function anciennete_signalement(): int {
	/**
	 * Ancienneté minimale, en jours, d'un compte dont le signalement compte pour la mise en
	 * attente automatique (les autres signalements restent visibles dans la vue de modération).
	 *
	 * @param int $jours Défaut 7.
	 */
	return max( 0, (int) apply_filters( 'yume_signalement_anciennete', 7 ) );
}

/**
 * Le signalement de ce compte compte-t-il pour le seuil (compte assez ancien) ?
 *
 * @param int $user_id Compte.
 */
function signalement_comptant( int $user_id ): bool {
	$user = get_userdata( $user_id );
	if ( ! $user instanceof \WP_User ) {
		return false;
	}
	$jours = anciennete_signalement();
	if ( 0 === $jours ) {
		return true;
	}
	$inscrit = strtotime( (string) $user->user_registered . ' UTC' );
	return false !== $inscrit && $inscrit > 0 && $inscrit <= time() - $jours * DAY_IN_SECONDS;
}

/**
 * Signalements d'un commentaire, par compte.
 *
 * @param int $comment_id Commentaire.
 * @return array<int,array{motif:string,date:string,ignore:bool}>
 */
function signalements( int $comment_id ): array {
	$brut   = get_comment_meta( $comment_id, META_SIGNALEMENTS, true );
	$propre = array();
	foreach ( is_array( $brut ) ? $brut : array() as $uid => $s ) {
		if ( (int) $uid > 0 && is_array( $s ) ) {
			$propre[ (int) $uid ] = array(
				'motif'  => (string) ( $s['motif'] ?? '' ),
				'date'   => (string) ( $s['date'] ?? '' ),
				'ignore' => ! empty( $s['ignore'] ),
			);
		}
	}
	return $propre;
}

/**
 * Signalements encore à traiter (non ignorés) d'un commentaire.
 *
 * @param int $comment_id Commentaire.
 * @return array<int,array{motif:string,date:string,ignore:bool}>
 */
function signalements_actifs( int $comment_id ): array {
	return array_filter(
		signalements( $comment_id ),
		static function ( array $s ): bool {
			return ! $s['ignore'];
		}
	);
}

/**
 * Erreur de signalement.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $statut  Statut HTTP.
 */
function erreur_signalement( string $code, string $message, int $statut ): \WP_Error {
	return new \WP_Error( $code, $message, array( 'status' => $statut ) );
}

/**
 * Signale un commentaire publié : un signalement par compte et par commentaire, jamais le sien,
 * débit limité (yume_signalements_debit par heure et par compte) ; au seuil, le commentaire
 * repasse en attente de modération.
 *
 * @param int    $comment_id Commentaire.
 * @param int    $user_id    Compte qui signale.
 * @param string $motif      Motif (facultatif, tronqué).
 * @return array{attente:bool,message:string}|\WP_Error
 */
function signaler_commentaire( int $comment_id, int $user_id, string $motif = '' ) {
	$commentaire = $comment_id ? get_comment( $comment_id ) : null;
	if ( $user_id <= 0 ) {
		return erreur_signalement( 'rest_forbidden', __( 'Connectez-vous pour signaler un commentaire.', 'yume-core' ), 401 );
	}
	// Seuls les commentaires publiés d'un contenu public se signalent (rien à révéler sinon).
	if ( ! $commentaire || '1' !== (string) $commentaire->comment_approved || ! is_post_publicly_viewable( (int) $commentaire->comment_post_ID ) ) {
		return erreur_signalement( 'yume_commentaire_introuvable', __( 'Commentaire introuvable.', 'yume-core' ), 404 );
	}
	if ( (int) $commentaire->user_id === $user_id ) {
		return erreur_signalement( 'yume_signalement_propre', __( 'Vous ne pouvez pas signaler votre propre commentaire.', 'yume-core' ), 400 );
	}
	/**
	 * Nombre de signalements autorisés par compte et par heure.
	 *
	 * @param int $max Défaut 10.
	 */
	$debit = max( 1, (int) apply_filters( 'yume_signalements_debit', 10 ) );
	if ( limite_atteinte( 'signalement', $debit, HOUR_IN_SECONDS, 'u' . $user_id ) ) {
		return erreur_signalement( 'yume_trop_de_signalements', __( 'Vous avez envoyé beaucoup de signalements : réessayez dans une heure.', 'yume-core' ), 429 );
	}
	$tous = signalements( $comment_id );
	if ( isset( $tous[ $user_id ] ) ) {
		return erreur_signalement( 'yume_deja_signale', __( 'Vous avez déjà signalé ce commentaire : l’équipe va l’examiner.', 'yume-core' ), 409 );
	}
	$tous[ $user_id ] = array(
		'motif'  => mb_substr( trim( sanitize_text_field( $motif ) ), 0, MOTIF_SIGNALEMENT_MAX ),
		'date'   => gmdate( 'Y-m-d H:i:s' ),
		'ignore' => false,
	);
	update_comment_meta( $comment_id, META_SIGNALEMENTS, $tous );
	$actifs    = count( signalements_actifs( $comment_id ) );
	$comptants = count( array_filter( array_keys( signalements_actifs( $comment_id ) ), __NAMESPACE__ . '\\signalement_comptant' ) );
	// Commentaire d'un membre de l'équipe : jamais masqué automatiquement (reste signalé).
	$equipe  = (int) $commentaire->user_id > 0 && user_can( (int) $commentaire->user_id, 'yume_voir_equipe' );
	$attente = ! $equipe && $comptants >= seuil_signalements();
	if ( $attente ) {
		wp_set_comment_status( $comment_id, 'hold' );
	}
	/**
	 * Un commentaire vient d'être signalé.
	 *
	 * @param int  $comment_id Commentaire.
	 * @param int  $user_id    Compte qui signale.
	 * @param int  $actifs     Signalements à traiter.
	 * @param bool $attente    Le commentaire vient de repasser en attente de modération.
	 */
	do_action( 'yume_commentaire_signale', $comment_id, $user_id, $actifs, $attente );
	return array(
		'attente' => $attente,
		'message' => __( 'Merci : le commentaire a été signalé à l’équipe.', 'yume-core' ),
	);
}

/**
 * Marque les signalements d'un commentaire comme traités (ignorés) : ils ne comptent plus et
 * le commentaire quitte la liste des signalés ; les comptes ne peuvent pas le signaler deux fois.
 *
 * @param int $comment_id Commentaire.
 */
function ignorer_signalements( int $comment_id ): void {
	$tous = signalements( $comment_id );
	if ( ! $tous ) {
		return;
	}
	foreach ( $tous as $uid => $s ) {
		$tous[ $uid ]['ignore'] = true;
	}
	update_comment_meta( $comment_id, META_SIGNALEMENTS, $tous );
}

/**
 * Un commentaire approuvé (façade ou administration) : ses signalements sont traités.
 *
 * @param string      $nouveau     Nouveau statut.
 * @param string      $ancien      Ancien statut.
 * @param \WP_Comment $commentaire Commentaire.
 */
function apres_approbation( $nouveau, $ancien, $commentaire ): void {
	if ( 'approved' === $nouveau && 'approved' !== $ancien && $commentaire instanceof \WP_Comment ) {
		ignorer_signalements( (int) $commentaire->comment_ID );
	}
}
add_action( 'transition_comment_status', __NAMESPACE__ . '\\apres_approbation', 10, 3 );

/*
 * -----------------------------------------------------------------------------
 * Vue « Commentaires » de l'espace équipe
 * -----------------------------------------------------------------------------
 */

/**
 * Nombre de commentaires à modérer (en attente, ou publiés et signalés).
 */
function nombre_a_moderer(): int {
	$a_moderer = commentaires_a_moderer();
	return count( $a_moderer['attente'] ) + count( $a_moderer['signales'] );
}

/**
 * Déclare la vue ?vue=commentaires (filtre yume_vues_equipe), pour qui a moderate_comments :
 * l'entrée de navigation « Commentaires (N) » n'apparaît que s'il y a des commentaires à
 * modérer, ou sur la vue elle-même (la navigation reste sobre quand tout est traité).
 *
 * @param array $vues Vues.
 * @return array
 */
function vue_moderation( $vues ) {
	$vues = is_array( $vues ) ? $vues : array();
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return $vues;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix d'affichage en lecture seule.
	$courante = isset( $_GET['vue'] ) && is_string( $_GET['vue'] ) && 'commentaires' === sanitize_key( wp_unslash( $_GET['vue'] ) );
	$nombre   = nombre_a_moderer();
	if ( ! $nombre && ! $courante ) {
		return $vues;
	}
	$vues['commentaires'] = array(
		/* translators: %d : commentaires à modérer */
		'libelle'  => $nombre ? sprintf( __( 'Commentaires (%d)', 'yume-core' ), $nombre ) : __( 'Commentaires', 'yume-core' ),
		'capacite' => 'moderate_comments',
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_commentaires',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\vue_moderation' );

/**
 * Commentaires à modérer : en attente, et publiés avec des signalements à traiter (les plus
 * signalés d'abord).
 *
 * @return array{attente:\WP_Comment[],signales:\WP_Comment[]}
 */
function commentaires_a_moderer(): array {
	$attente  = get_comments(
		array(
			'status'      => 'hold',
			'type'        => 'comment',
			'post_status' => 'publish',
			'number'      => COMMENTAIRES_PAR_LISTE,
			'orderby'     => 'comment_date_gmt',
			'order'       => 'DESC',
		)
	);
	$signales = array_values(
		array_filter(
			get_comments(
				array(
					'status'      => 'approve',
					'type'        => 'comment',
					'post_status' => 'publish',
					'meta_key'    => META_SIGNALEMENTS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'number'      => 200,
					'orderby'     => 'comment_date_gmt',
					'order'       => 'DESC',
				)
			),
			static function ( $c ): bool {
				return $c instanceof \WP_Comment && (bool) signalements_actifs( (int) $c->comment_ID );
			}
		)
	);
	usort(
		$signales,
		static function ( \WP_Comment $a, \WP_Comment $b ): int {
			return count( signalements_actifs( (int) $b->comment_ID ) ) <=> count( signalements_actifs( (int) $a->comment_ID ) );
		}
	);
	return array(
		'attente'  => array_values( array_filter( (array) $attente, static fn( $c ): bool => $c instanceof \WP_Comment ) ),
		'signales' => array_slice( $signales, 0, COMMENTAIRES_PAR_LISTE ),
	);
}

/**
 * Carte d'un commentaire à modérer : auteur, contenu, date, signalements, actions.
 *
 * @param \WP_Comment $c Commentaire.
 */
function carte_moderation( \WP_Comment $c ): string {
	$id      = (int) $c->comment_ID;
	$titre   = 'yn-com-' . $id . '-titre';
	$post_id = (int) $c->comment_post_ID;
	$actifs  = signalements_actifs( $id );
	$html    = '<li class="yn-card yn-moderation__commentaire" id="yn-com-' . $id . '" aria-labelledby="' . esc_attr( $titre ) . '">';
	$html   .= '<p class="yn-moderation__tete"><b id="' . esc_attr( $titre ) . '">' . esc_html( (string) $c->comment_author ) . '</b>';
	$html   .= '<span class="yn-muted">' . esc_html(
		sprintf(
			/* translators: 1: contenu commenté, 2: date */
			__( 'sur « %1$s », le %2$s', 'yume-core' ),
			wp_strip_all_tags( get_the_title( $post_id ) ),
			(string) mysql2date( 'j M Y à H:i', (string) $c->comment_date )
		)
	) . '</span>';
	if ( '1' !== (string) $c->comment_approved ) {
		$html .= '<span class="yn-chip yn-chip--info">' . esc_html__( 'En attente', 'yume-core' ) . '</span>';
	}
	if ( $actifs ) {
		/* translators: %d : nombre de signalements */
		$html .= '<span class="yn-chip yn-chip--warn"><span aria-hidden="true">▲</span> ' . esc_html( sprintf( _n( '%d signalement', '%d signalements', count( $actifs ), 'yume-core' ), count( $actifs ) ) ) . '</span>';
	}
	$html .= '</p>';
	$html .= '<p class="yn-moderation__texte">' . esc_html( wp_html_excerpt( wp_strip_all_tags( (string) $c->comment_content ), 800, '…' ) ) . '</p>';
	if ( $actifs ) {
		$html .= '<ul class="yn-moderation__motifs">';
		foreach ( $actifs as $uid => $s ) {
			$user  = get_userdata( (int) $uid );
			$html .= '<li>' . esc_html( ( $user ? $user->display_name : __( 'Compte supprimé', 'yume-core' ) ) . ' : ' . ( '' !== $s['motif'] ? '« ' . $s['motif'] . ' »' : __( 'sans motif', 'yume-core' ) ) ) . '</li>';
		}
		$html .= '</ul>';
	}
	if ( current_user_can( 'edit_comment', $id ) ) {
		$nom = '<span class="yn-visually-hidden"> : ' . esc_html(
			sprintf(
				/* translators: %s : auteur du commentaire */
				__( 'commentaire de %s', 'yume-core' ),
				(string) $c->comment_author
			)
		) . '</span>';
		$html .= '<form class="yn-moderation__actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="yume_moderation"><input type="hidden" name="commentaire" value="' . $id . '">';
		$html .= wp_nonce_field( 'yume_moderation_' . $id, '_yume_nonce', true, false );
		if ( '1' !== (string) $c->comment_approved ) {
			$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-btn--primary" name="op" value="approuver">' . esc_html__( 'Approuver', 'yume-core' ) . $nom . '</button>';
		}
		if ( $actifs ) {
			$html .= '<button type="submit" class="yn-btn yn-btn--sm" name="op" value="ignorer">' . esc_html__( 'Ignorer les signalements', 'yume-core' ) . $nom . '</button>';
		}
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-moderation__danger" name="op" value="indesirable" data-yn-confirmer="' . esc_attr__( 'Marquer ce commentaire comme indésirable ?', 'yume-core' ) . '">' . esc_html__( 'Indésirable', 'yume-core' ) . $nom . '</button>';
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-moderation__danger" name="op" value="corbeille" data-yn-confirmer="' . esc_attr__( 'Mettre ce commentaire à la corbeille ?', 'yume-core' ) . '">' . esc_html__( 'Corbeille', 'yume-core' ) . $nom . '</button>';
		$html .= '</form>';
	} else {
		$html .= '<p class="yn-muted">' . esc_html__( 'Seul un compte qui peut modifier ce contenu (auteur, gérant) peut modérer ce commentaire : passez par un gérant.', 'yume-core' ) . '</p>';
	}
	$lien = get_comment_link( $c );
	if ( '1' === (string) $c->comment_approved && $lien ) {
		$html .= '<p class="yn-muted"><a href="' . esc_url( $lien ) . '">' . esc_html__( 'Voir en contexte', 'yume-core' ) . '</a></p>';
	}
	return $html . '</li>';
}

/**
 * Section d'une liste de commentaires.
 *
 * @param string        $id          Identifiant.
 * @param string        $titre       Titre.
 * @param \WP_Comment[] $commentaires Commentaires.
 * @param string        $vide        Texte si la liste est vide.
 */
function section_moderation( string $id, string $titre, array $commentaires, string $vide ): string {
	$html  = '<section class="yn-team__section" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-titre">';
	$html .= '<h2 id="' . esc_attr( $id ) . '-titre">' . esc_html( $titre ) . ' <span class="yn-muted">(' . count( $commentaires ) . ')</span></h2>';
	if ( ! $commentaires ) {
		return $html . '<p class="yn-card yn-team__vide yn-muted">' . esc_html( $vide ) . '</p></section>';
	}
	$html .= '<ul class="yn-moderation__liste">';
	foreach ( $commentaires as $c ) {
		$html .= carte_moderation( $c );
	}
	return $html . '</ul></section>';
}

/**
 * Vue « Commentaires » (?vue=commentaires) : commentaires signalés, puis en attente.
 */
function rendu_vue_commentaires(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--moderation' );
	$html .= navigation_equipe( 'commentaires' );
	$html .= '<div class="yn-team__principal">';
	$admin = current_user_can( 'moderate_comments' ) ? '<a class="yn-btn" href="' . esc_url( admin_url( 'edit-comments.php' ) ) . '">' . esc_html__( 'Tous les commentaires (administration)', 'yume-core' ) . '</a>' : '';
	$html .= tete_vue( __( 'Commentaires à modérer', 'yume-core' ), $admin );
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return $html . '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'La modération des commentaires est réservée aux éditeurs et aux gérants.', 'yume-core' ) . '</p></div></div></div>';
	}
	$retour = retour_formulaire( get_current_user_id() );
	$html  .= '<p class="yn-muted">' . esc_html(
		sprintf(
			/* translators: %d : seuil */
			_n(
				'Un commentaire signalé par %d lecteur repasse automatiquement en attente. Approuver le publie de nouveau et classe ses signalements.',
				'Un commentaire signalé par %d lecteurs repasse automatiquement en attente. Approuver le publie de nouveau et classe ses signalements.',
				seuil_signalements(),
				'yume-core'
			),
			seuil_signalements()
		)
	) . '</p>';
	$html     .= '<div id="yn-moderation-retour">' . zone_retour( $retour ) . '</div>';
	$a_moderer = commentaires_a_moderer();
	$html     .= section_moderation( 'yn-moderation-signales', __( 'Signalés', 'yume-core' ), $a_moderer['signales'], __( 'Aucun commentaire publié n’est signalé.', 'yume-core' ) );
	$html     .= section_moderation( 'yn-moderation-attente', __( 'En attente de modération', 'yume-core' ), $a_moderer['attente'], __( 'Aucun commentaire en attente.', 'yume-core' ) );
	return $html . '</div></div>';
}

/**
 * Traite une action de modération (Approuver, Indésirable, Corbeille, Ignorer les
 * signalements) : nonce et edit_comment sur ce commentaire.
 *
 * @param array $post    Données POST (brutes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string}
 */
function traiter_moderation( array $post, int $user_id ): array {
	$id    = isset( $post['commentaire'] ) && is_scalar( $post['commentaire'] ) ? absint( $post['commentaire'] ) : 0;
	$op    = isset( $post['op'] ) && is_scalar( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$err   = static function ( string $message ): array {
		return array(
			'type'    => 'erreur',
			'message' => $message,
			'cible'   => 'yn-moderation-retour',
		);
	};
	if ( ! $id || ! wp_verify_nonce( $nonce, 'yume_moderation_' . $id ) ) {
		return $err( __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	$c = get_comment( $id );
	if ( ! $c ) {
		return $err( __( 'Commentaire introuvable.', 'yume-core' ) );
	}
	if ( ! user_can( $user_id, 'moderate_comments' ) || ! user_can( $user_id, 'edit_comment', $id ) ) {
		return $err( __( 'Vous ne pouvez pas modérer ce commentaire.', 'yume-core' ) );
	}
	$auteur = (string) $c->comment_author;
	switch ( $op ) {
		case 'approuver':
			$ok = wp_set_comment_status( $id, 'approve' );
			ignorer_signalements( $id );
			/* translators: %s : auteur */
			$message = __( 'Commentaire de %s approuvé.', 'yume-core' );
			break;
		case 'indesirable':
			$ok = wp_spam_comment( $id );
			/* translators: %s : auteur */
			$message = __( 'Commentaire de %s marqué comme indésirable.', 'yume-core' );
			break;
		case 'corbeille':
			$ok = wp_trash_comment( $id );
			/* translators: %s : auteur */
			$message = __( 'Commentaire de %s mis à la corbeille.', 'yume-core' );
			break;
		case 'ignorer':
			ignorer_signalements( $id );
			$ok = true;
			/* translators: %s : auteur */
			$message = __( 'Signalements du commentaire de %s ignorés.', 'yume-core' );
			break;
		default:
			return $err( __( 'Action inconnue.', 'yume-core' ) );
	}
	if ( ! $ok ) {
		return $err( __( 'L’action n’a pas pu être appliquée. Réessayez.', 'yume-core' ) );
	}
	return array(
		'type'    => 'ok',
		'message' => sprintf( $message, $auteur ),
		'cible'   => 'yn-moderation-retour',
	);
}

/**
 * Action de modération envoyée par la vue (admin-post.php, action yume_moderation).
 */
function admin_post_moderation(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_moderation().
	$retour = traiter_moderation( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_retour( $retour + array( 'tome_id' => 0 ) );
}
add_action( 'admin_post_yume_moderation', __NAMESPACE__ . '\\admin_post_moderation' );

/**
 * Visiteur non connecté : vers la connexion, retour sur la vue.
 */
function admin_post_moderation_anonyme(): void {
	wp_safe_redirect( wp_login_url( url_vue_equipe( 'commentaires' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_moderation', __NAMESPACE__ . '\\admin_post_moderation_anonyme' );
