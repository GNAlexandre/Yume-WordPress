<?php
/**
 * Vue « Corrections » de l'espace équipe (?vue=corrections, menu « Catalogue ») : coquilles
 * signalées par les lecteurs, groupées par chapitre.
 *
 * - Entrée « Corrections (N) » (N = signalements nouveaux) pour toute l'équipe (yume_voir_equipe) :
 *   traducteurs et relecteurs lisent ; seules les personnes qui peuvent modifier le chapitre
 *   (edit_post : éditeurs, gérants, administrateurs) voient les actions. Entrée absente tant que la
 *   file n'a reçu aucun signalement (sauf sur la vue elle-même), comme « Commentaires ».
 * - Filtres GET oeuvre, tome, statut (nouveau par défaut, corrige, rejete, tous), pg (50 par page).
 * - Actions en admin-post.php : yume_correction (champs coquille, op = corrige|rejete|rouvrir,
 *   nonce yume_correction_{id}) et yume_correction_chapitre (champs chapitre, op = corrige|rejete,
 *   nonce yume_correction_chapitre_{chapitre}) ; retour sans JavaScript par
 *   Planning\retour_formulaire() / rediriger_retour() (cible yn-corrections-retour).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

use function Yume\Core\Planning\champ_select;
use function Yume\Core\Planning\champs_caches_vue;
use function Yume\Core\Planning\get_cle;
use function Yume\Core\Planning\get_entier;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\ouvrir_racine;
use function Yume\Core\Planning\pagination_vue;
use function Yume\Core\Planning\rediriger_retour;
use function Yume\Core\Planning\retour_formulaire;
use function Yume\Core\Planning\tete_vue;
use function Yume\Core\Planning\url_vue_equipe;
use function Yume\Core\Planning\zone_retour;

defined( 'ABSPATH' ) || exit;

/** Poignée de la feuille de style de la vue. */
const POIGNEE_VUE = 'yume-corrections';

/** Cible des messages de retour. */
const CIBLE_RETOUR = 'yn-corrections-retour';

/**
 * La file a-t-elle déjà reçu au moins un signalement (quel que soit son statut) ?
 */
function file_non_vide(): bool {
	global $wpdb;
	if ( ! table_prete() ) {
		return false;
	}
	$table = table_coquilles();
	return (bool) $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

/**
 * Déclare la vue ?vue=corrections (filtre yume_vues_equipe) pour toute l'équipe : entrée
 * « Corrections (N) » du menu Catalogue (N = nouveaux). Comme « Commentaires », l'entrée reste
 * absente tant que la file n'a reçu aucun signalement (navigation sobre), sauf sur la vue elle-même.
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue( $vues ) {
	$vues = is_array( $vues ) ? $vues : array();
	if ( ! table_prete() ) {
		return $vues;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix d'affichage en lecture seule.
	$courante = isset( $_GET['vue'] ) && is_string( $_GET['vue'] ) && VUE_CLE === sanitize_key( wp_unslash( $_GET['vue'] ) );
	if ( ! $courante && ! file_non_vide() ) {
		return $vues;
	}
	$nombre          = compter_nouvelles();
	$vues[ VUE_CLE ] = array(
		/* translators: %d : signalements nouveaux */
		'libelle'  => $nombre ? sprintf( __( 'Corrections (%d)', 'yume-core' ), $nombre ) : __( 'Corrections', 'yume-core' ),
		'capacite' => 'yume_voir_equipe',
		'groupe'   => 'catalogue',
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_corrections',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue' );

/**
 * Feuille de style de la vue (chargée quand la vue est rendue).
 */
function enregistrer_style_vue(): void {
	wp_register_style( POIGNEE_VUE, YUME_CORE_URL . 'includes/corrections/assets/corrections.css', array(), YUME_CORE_VERSION );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style_vue' );

/**
 * Filtres de la vue (GET), validés.
 *
 * @return array{oeuvre:int,tome:int,statut:string,pg:int}
 */
function filtres_vue(): array {
	$statut = get_cle( 'statut' );
	return array(
		'oeuvre' => get_entier( 'oeuvre' ),
		'tome'   => get_entier( 'tome' ),
		'statut' => in_array( $statut, array_merge( STATUTS, array( 'tous' ) ), true ) ? $statut : 'nouveau',
		'pg'     => max( 1, get_entier( 'pg' ) ),
	);
}

/**
 * Œuvres et tomes qui ont des signalements (listes des filtres).
 *
 * @param int $oeuvre_id Œuvre choisie (tomes de cette œuvre seulement), 0 : toutes.
 * @return array{oeuvres:array<string,string>,tomes:array<string,string>}
 */
function choix_filtres( int $oeuvre_id ): array {
	global $wpdb;
	$table   = table_coquilles();
	$oeuvres = array( '' => __( 'Toutes', 'yume-core' ) );
	$tomes   = array( '' => __( 'Tous', 'yume-core' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids_oeuvres = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT oeuvre_id FROM {$table} WHERE oeuvre_id > 0" ) );
	$ids_tomes   = $oeuvre_id > 0
		? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tome_id FROM {$table} WHERE tome_id > 0 AND oeuvre_id = %d", $oeuvre_id ) ) )
		: array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT tome_id FROM {$table} WHERE tome_id > 0" ) );
	// phpcs:enable
	$titres = array();
	foreach ( $ids_oeuvres as $id ) {
		if ( get_post( $id ) ) {
			$titres[ (string) $id ] = wp_strip_all_tags( get_the_title( $id ) );
		}
	}
	natcasesort( $titres );
	$oeuvres += $titres;
	$libelles = array();
	foreach ( $ids_tomes as $id ) {
		if ( ! get_post( $id ) ) {
			continue;
		}
		$tome                     = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $id ) : wp_strip_all_tags( get_the_title( $id ) );
		$oeuvre                   = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $id ) : 0;
		$libelles[ (string) $id ] = ( $oeuvre_id <= 0 && $oeuvre ? wp_strip_all_tags( get_the_title( $oeuvre ) ) . ' › ' : '' ) . $tome;
	}
	natcasesort( $libelles );
	$tomes += $libelles;
	return array(
		'oeuvres' => $oeuvres,
		'tomes'   => $tomes,
	);
}

/**
 * Signalements groupés par chapitre (ordre d'apparition : chapitre du signalement le plus récent
 * d'abord), triés par paragraphe puis date dans chaque chapitre.
 *
 * @param array<int,array> $lignes Signalements normalisés.
 * @return array<int,array<int,array>> chapitre_id => signalements.
 */
function groupes_par_chapitre( array $lignes ): array {
	$groupes = array();
	foreach ( $lignes as $ligne ) {
		$groupes[ $ligne['chapitre_id'] ][] = $ligne;
	}
	foreach ( $groupes as $id => $liste ) {
		usort(
			$liste,
			static function ( array $a, array $b ): int {
				return array( $a['paragraphe'], $a['cree_le'], $a['id'] ) <=> array( $b['paragraphe'], $b['cree_le'], $b['id'] );
			}
		);
		$groupes[ $id ] = $liste;
	}
	return $groupes;
}

/**
 * Date GMT de la table, à l'heure du site (« 19 oct. 2026 14:02 »).
 *
 * @param string $gmt Date GMT « Y-m-d H:i:s ».
 */
function date_affichee( string $gmt ): string {
	$ts = '' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
	return false === $ts ? '' : (string) wp_date( 'j M Y H:i', $ts );
}

/**
 * Citation de l'extrait dans son contexte, extrait surligné (<mark>) ; 80 caractères de contexte
 * de part et d'autre au plus.
 *
 * @param array $ligne Signalement normalisé.
 */
function citation( array $ligne ): string {
	$extrait  = $ligne['extrait'];
	$contexte = $ligne['contexte'];
	$position = '' !== $contexte ? mb_strpos( $contexte, $extrait ) : false;
	if ( false === $position ) {
		return '« <mark>' . esc_html( $extrait ) . '</mark> »';
	}
	$avant = mb_substr( $contexte, 0, (int) $position );
	$apres = mb_substr( $contexte, (int) $position + mb_strlen( $extrait ) );
	if ( mb_strlen( $avant ) > 80 ) {
		$avant = '…' . ltrim( mb_substr( $avant, -80 ) );
	}
	if ( mb_strlen( $apres ) > 80 ) {
		$apres = rtrim( mb_substr( $apres, 0, 80 ) ) . '…';
	}
	return '« ' . esc_html( $avant ) . '<mark>' . esc_html( $extrait ) . '</mark>' . esc_html( $apres ) . ' »';
}

/**
 * Lien qui s'ouvre dans un nouvel onglet.
 *
 * @param string $url     Adresse.
 * @param string $libelle Libellé.
 * @param string $precis  Complément pour les lecteurs d'écran.
 */
function lien_onglet( string $url, string $libelle, string $precis = '' ): string {
	return '<a class="yn-btn yn-btn--sm" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $libelle )
		. ( '' !== $precis ? '<span class="yn-visually-hidden"> ' . esc_html( $precis ) . '</span>' : '' )
		. ' <span aria-hidden="true">↗</span><span class="yn-visually-hidden"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a>';
}

/**
 * Formulaire d'actions d'un signalement (Corrigé / Rejeté, ou Rouvrir).
 *
 * @param array $ligne Signalement normalisé.
 */
function formulaire_signalement( array $ligne ): string {
	$id  = $ligne['id'];
	$nom = '<span class="yn-visually-hidden"> : ' . esc_html(
		sprintf(
			/* translators: 1: pseudo, 2: numéro du paragraphe */
			__( 'signalement de %1$s, paragraphe %2$d', 'yume-core' ),
			auteur( $ligne ),
			$ligne['paragraphe'] + 1
		)
	) . '</span>';
	$html  = '<form class="yn-corrections__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$html .= '<input type="hidden" name="action" value="yume_correction"><input type="hidden" name="coquille" value="' . $id . '">';
	$html .= wp_nonce_field( 'yume_correction_' . $id, '_yume_nonce', true, false );
	if ( 'nouveau' === $ligne['statut'] ) {
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-corrections__ok" name="op" value="corrige">' . esc_html__( 'Corrigé', 'yume-core' ) . $nom . '</button>';
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-corrections__non" name="op" value="rejete">' . esc_html__( 'Rejeté', 'yume-core' ) . $nom . '</button>';
	} else {
		$html .= '<button type="submit" class="yn-btn yn-btn--sm" name="op" value="rouvrir">' . esc_html__( 'Rouvrir', 'yume-core' ) . $nom . '</button>';
	}
	return $html . '</form>';
}

/**
 * Un signalement dans la carte de son chapitre.
 *
 * @param array $ligne Signalement normalisé.
 * @param bool  $peut  Le compte courant peut traiter (edit_post sur le chapitre).
 */
function item_signalement( array $ligne, bool $peut ): string {
	$html  = '<li class="yn-corrections__signalement" id="yn-coquille-' . $ligne['id'] . '">';
	$html .= '<p class="yn-corrections__ligne"><span class="yn-label yn-corrections__numero">' . esc_html( '¶ ' . ( $ligne['paragraphe'] + 1 ) ) . '</span>';
	$html .= '<span class="yn-corrections__cit">' . citation( $ligne ) . '</span>';
	if ( paragraphe_modifie( $ligne ) ) {
		$html .= '<span class="yn-chip yn-chip--warn"><span aria-hidden="true">▲</span> ' . esc_html__( 'Paragraphe modifié depuis', 'yume-core' ) . '</span>';
	}
	if ( 'corrige' === $ligne['statut'] ) {
		$html .= '<span class="yn-chip yn-chip--ok">' . esc_html__( 'Corrigé', 'yume-core' ) . '</span>';
	} elseif ( 'rejete' === $ligne['statut'] ) {
		$html .= '<span class="yn-chip yn-chip--info">' . esc_html__( 'Rejeté', 'yume-core' ) . '</span>';
	}
	$html       .= '</p>';
	$commentaire = '' !== $ligne['commentaire'] ? '« ' . $ligne['commentaire'] . ' »' : __( 'Sans commentaire', 'yume-core' );
	$html       .= '<p class="yn-corrections__meta">' . esc_html( $commentaire ) . ' — <b>' . esc_html( auteur( $ligne ) ) . '</b>, ' . esc_html( date_affichee( $ligne['cree_le'] ) );
	if ( 'nouveau' !== $ligne['statut'] && '' !== $ligne['traite_le'] ) {
		$par   = $ligne['traite_par'] > 0 ? get_userdata( $ligne['traite_par'] ) : false;
		$html .= '<span class="yn-muted"> · ' . esc_html(
			$par
				/* translators: 1: date, 2: pseudo */
				? sprintf( __( 'traité le %1$s par %2$s', 'yume-core' ), date_affichee( $ligne['traite_le'] ), $par->display_name )
				/* translators: %s : date */
				: sprintf( __( 'traité le %s', 'yume-core' ), date_affichee( $ligne['traite_le'] ) )
		) . '</span>';
	}
	$html .= '</p><div class="yn-corrections__actions">';
	$url   = url_paragraphe( $ligne );
	if ( '' !== $url ) {
		$html .= lien_onglet(
			$url,
			__( 'Voir le paragraphe', 'yume-core' ),
			/* translators: %d : numéro du paragraphe */
			sprintf( __( 'paragraphe %d', 'yume-core' ), $ligne['paragraphe'] + 1 )
		);
	}
	$html .= $peut ? formulaire_signalement( $ligne ) : '<p class="yn-muted yn-corrections__reserve">' . esc_html__( 'Seul un éditeur peut traiter ce signalement.', 'yume-core' ) . '</p>';
	return $html . '</div></li>';
}

/**
 * Carte d'un chapitre et de ses signalements.
 *
 * @param int   $chapitre_id Chapitre.
 * @param array $lignes      Signalements du chapitre (même page).
 */
function carte_chapitre( int $chapitre_id, array $lignes ): string {
	$peut     = $chapitre_id > 0 && get_post( $chapitre_id ) && current_user_can( 'edit_post', $chapitre_id );
	$en_ligne = publie( $chapitre_id, 'yume_chapitre' );
	$titre    = 'yn-corrections-ch-' . $chapitre_id;
	$nouveaux = count( array_filter( $lignes, static fn( array $l ): bool => 'nouveau' === $l['statut'] ) );
	$html     = '<section class="yn-card yn-corrections__chapitre" aria-labelledby="' . esc_attr( $titre ) . '">';
	$html    .= '<div class="yn-corrections__tete"><div><h3 class="yn-corrections__titre" id="' . esc_attr( $titre ) . '">' . esc_html( implode( ' › ', fil( $lignes[0] ) ) ) . '</h3>';
	/* translators: %d : nombre de signalements */
	$html .= '<p class="yn-muted yn-corrections__nombre">' . esc_html( sprintf( _n( '%d signalement', '%d signalements', count( $lignes ), 'yume-core' ), count( $lignes ) ) );
	if ( ! $en_ligne ) {
		$html .= ' <span class="yn-chip yn-chip--info">' . esc_html__( 'Chapitre hors ligne', 'yume-core' ) . '</span>';
	}
	$html .= '</p></div><div class="yn-corrections__boutons">';
	if ( $en_ligne ) {
		$html .= lien_onglet( (string) get_permalink( $chapitre_id ), __( 'Voir le chapitre', 'yume-core' ) );
	}
	$editer = $peut ? get_edit_post_link( $chapitre_id, 'raw' ) : '';
	if ( $editer ) {
		$html .= lien_onglet( (string) $editer, __( 'Corriger dans l’éditeur', 'yume-core' ) );
	}
	if ( $peut && $nouveaux > 0 ) {
		$html .= '<form class="yn-corrections__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="yume_correction_chapitre"><input type="hidden" name="chapitre" value="' . $chapitre_id . '">';
		$html .= wp_nonce_field( 'yume_correction_chapitre_' . $chapitre_id, '_yume_nonce', true, false );
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-corrections__ok" name="op" value="corrige">' . esc_html__( 'Tout marquer corrigé', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( libelle_chapitre( $chapitre_id ) ) . '</span></button></form>';
	}
	$html .= '</div></div><ol class="yn-corrections__liste">';
	foreach ( $lignes as $ligne ) {
		$html .= item_signalement( $ligne, $peut );
	}
	return $html . '</ol></section>';
}

/**
 * Libellés du filtre de statut et du compteur.
 *
 * @return array<string,string>
 */
function libelles_statuts(): array {
	return array(
		'nouveau' => __( 'Nouveaux', 'yume-core' ),
		'corrige' => __( 'Corrigés', 'yume-core' ),
		'rejete'  => __( 'Rejetés', 'yume-core' ),
		'tous'    => __( 'Tous', 'yume-core' ),
	);
}

/**
 * Compteur « 12 nouveaux · 48 corrigés · 3 rejetés ».
 *
 * @param array<string,int> $comptes statut => nombre.
 */
function resume_comptes( array $comptes ): string {
	return implode(
		' · ',
		array(
			/* translators: %d : nombre */
			sprintf( _n( '%d nouveau', '%d nouveaux', $comptes['nouveau'], 'yume-core' ), $comptes['nouveau'] ),
			/* translators: %d : nombre */
			sprintf( _n( '%d corrigé', '%d corrigés', $comptes['corrige'], 'yume-core' ), $comptes['corrige'] ),
			/* translators: %d : nombre */
			sprintf( _n( '%d rejeté', '%d rejetés', $comptes['rejete'], 'yume-core' ), $comptes['rejete'] ),
		)
	);
}

/**
 * Vue « Corrections » (?vue=corrections).
 */
function rendu_vue_corrections(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--corrections' );
	$html .= navigation_equipe( VUE_CLE );
	$html .= '<div class="yn-team__principal">';
	if ( ! current_user_can( 'yume_voir_equipe' ) ) {
		$html .= tete_vue( __( 'Corrections signalées par les lecteurs', 'yume-core' ), '' );
		return $html . '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les membres de l’équipe peuvent consulter les corrections.', 'yume-core' ) . '</p></div></div></div>';
	}
	wp_enqueue_style( POIGNEE_VUE );
	$f       = filtres_vue();
	$dernier = lister_coquilles(
		array(
			'statut' => 'nouveau',
			'limite' => 1,
		)
	)['lignes'];
	$bouton  = $dernier && '' !== url_paragraphe( $dernier[0] ) ? lien_onglet( url_paragraphe( $dernier[0] ), __( 'Voir le dernier chapitre signalé', 'yume-core' ) ) : '';
	$html   .= tete_vue( __( 'Corrections signalées par les lecteurs', 'yume-core' ), $bouton );
	$html   .= '<div id="' . CIBLE_RETOUR . '">' . zone_retour( retour_formulaire( get_current_user_id() ) ) . '</div>';

	$choix = choix_filtres( $f['oeuvre'] );
	$html .= '<div class="yn-corrections__barre"><form class="yn-card yn-team__filtres yn-corrections__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Filtrer les corrections', 'yume-core' ) . '">' . champs_caches_vue( VUE_CLE );
	$html .= champ_select( 'yn-corrections-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), $choix['oeuvres'], $f['oeuvre'] ? (string) $f['oeuvre'] : '' );
	$html .= champ_select( 'yn-corrections-tome', 'tome', __( 'Tome', 'yume-core' ), $choix['tomes'], $f['tome'] ? (string) $f['tome'] : '' );
	$html .= champ_select( 'yn-corrections-statut', 'statut', __( 'Statut', 'yume-core' ), libelles_statuts(), $f['statut'] );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p></form>';
	$html .= '<p class="yn-muted yn-corrections__comptes">' . esc_html( resume_comptes( compter_par_statut( $f ) ) ) . '</p></div>';

	$liste = lister_coquilles(
		array(
			'statut' => $f['statut'],
			'oeuvre' => $f['oeuvre'],
			'tome'   => $f['tome'],
			'page'   => $f['pg'],
			'limite' => PAR_PAGE,
		)
	);
	if ( ! $liste['lignes'] ) {
		$vides = array(
			'nouveau' => __( 'Aucune coquille à traiter : merci à l’équipe et aux lecteurs !', 'yume-core' ),
			'corrige' => __( 'Aucun signalement corrigé pour ces filtres.', 'yume-core' ),
			'rejete'  => __( 'Aucun signalement rejeté pour ces filtres.', 'yume-core' ),
			'tous'    => __( 'Aucun signalement pour ces filtres.', 'yume-core' ),
		);
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html( $vides[ $f['statut'] ] ) . '</p>';
	} else {
		$html .= '<div class="yn-corrections__groupes">';
		foreach ( groupes_par_chapitre( $liste['lignes'] ) as $chapitre_id => $lignes ) {
			$html .= carte_chapitre( (int) $chapitre_id, $lignes );
		}
		$html .= '</div>';
		$pages = (int) ceil( $liste['total'] / PAR_PAGE );
		$html .= pagination_vue(
			VUE_CLE,
			array(
				'oeuvre' => $f['oeuvre'],
				'tome'   => $f['tome'],
				'statut' => 'nouveau' === $f['statut'] ? '' : $f['statut'],
			),
			$f['pg'],
			$f['pg'] < $pages,
			$pages
		);
	}
	if ( reglage( 'coquilles_discord', true ) ) {
		$html .= '<p class="yn-muted yn-corrections__note">' . esc_html__( 'Chaque signalement est aussi annoncé dans le salon Discord de l’équipe, au plus un message par chapitre toutes les 10 minutes.', 'yume-core' ) . '</p>';
	}
	return $html . '</div></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Actions (admin-post.php)
 * -----------------------------------------------------------------------------
 */

/**
 * Retour d'erreur d'une action.
 *
 * @param string $message Message.
 * @return array{type:string,message:string,cible:string}
 */
function retour_erreur( string $message ): array {
	return array(
		'type'    => 'erreur',
		'message' => $message,
		'cible'   => CIBLE_RETOUR,
	);
}

/**
 * Valeur entière et opération d'un formulaire d'action, nonce vérifié.
 *
 * @param array  $post   Données POST (brutes).
 * @param string $champ  Champ de l'identifiant.
 * @param string $action Préfixe de l'action du nonce.
 * @return array{0:int,1:string}|null Identifiant et opération, null si le nonce est invalide.
 */
function lire_action( array $post, string $champ, string $action ): ?array {
	$id    = isset( $post[ $champ ] ) && is_scalar( $post[ $champ ] ) ? absint( $post[ $champ ] ) : 0;
	$op    = isset( $post['op'] ) && is_scalar( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	if ( ! $id || ! wp_verify_nonce( $nonce, $action . $id ) ) {
		return null;
	}
	return array( $id, $op );
}

/**
 * Action sur un signalement (Corrigé, Rejeté, Rouvrir) : nonce yume_correction_{id}, edit_post
 * sur le chapitre.
 *
 * @param array $post    Données POST (brutes).
 * @param int   $user_id Compte.
 * @return array{type:string,message:string,cible:string}
 */
function traiter_correction( array $post, int $user_id ): array {
	$lu = lire_action( $post, 'coquille', 'yume_correction_' );
	if ( ! $lu ) {
		return retour_erreur( __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	$statuts = array(
		'corrige' => 'corrige',
		'rejete'  => 'rejete',
		'rouvrir' => 'nouveau',
	);
	if ( ! isset( $statuts[ $lu[1] ] ) ) {
		return retour_erreur( __( 'Action inconnue.', 'yume-core' ) );
	}
	$resultat = changer_statut( $lu[0], $statuts[ $lu[1] ], $user_id );
	if ( is_wp_error( $resultat ) ) {
		return retour_erreur( $resultat->get_error_message() );
	}
	$messages = array(
		'corrige' => __( 'Signalement marqué corrigé.', 'yume-core' ),
		'rejete'  => __( 'Signalement rejeté.', 'yume-core' ),
		'rouvrir' => __( 'Signalement rouvert.', 'yume-core' ),
	);
	return array(
		'type'    => 'ok',
		'message' => $messages[ $lu[1] ],
		'cible'   => CIBLE_RETOUR,
	);
}

/**
 * « Tout marquer corrigé » pour un chapitre : nonce yume_correction_chapitre_{chapitre},
 * edit_post sur le chapitre.
 *
 * @param array $post    Données POST (brutes).
 * @param int   $user_id Compte.
 * @return array{type:string,message:string,cible:string}
 */
function traiter_correction_chapitre( array $post, int $user_id ): array {
	$lu = lire_action( $post, 'chapitre', 'yume_correction_chapitre_' );
	if ( ! $lu ) {
		return retour_erreur( __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	if ( ! in_array( $lu[1], array( 'corrige', 'rejete' ), true ) ) {
		return retour_erreur( __( 'Action inconnue.', 'yume-core' ) );
	}
	if ( ! user_can( $user_id, 'edit_post', $lu[0] ) ) {
		return retour_erreur( __( 'Seul un compte qui peut modifier ce chapitre (éditeur, gérant) peut traiter ses signalements.', 'yume-core' ) );
	}
	$n = traiter_chapitre( $lu[0], $lu[1], $user_id );
	return array(
		'type'    => 'ok',
		'message' => 'corrige' === $lu[1]
			/* translators: %d : nombre de signalements */
			? sprintf( _n( '%d signalement marqué corrigé.', '%d signalements marqués corrigés.', $n, 'yume-core' ), $n )
			/* translators: %d : nombre de signalements */
			: sprintf( _n( '%d signalement rejeté.', '%d signalements rejetés.', $n, 'yume-core' ), $n ),
		'cible'   => CIBLE_RETOUR,
	);
}

/**
 * Enregistre le retour et revient sur la vue.
 *
 * @param array $retour Retour.
 */
function revenir( array $retour ): void {
	if ( ! function_exists( 'Yume\\Core\\Planning\\rediriger_retour' ) ) {
		wp_safe_redirect( admin_url() );
		exit;
	}
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_retour( $retour );
}

/**
 * Action yume_correction (admin-post.php).
 */
function admin_post_correction(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_correction().
	revenir( traiter_correction( $_POST, get_current_user_id() ) );
}
add_action( 'admin_post_yume_correction', __NAMESPACE__ . '\\admin_post_correction' );

/**
 * Action yume_correction_chapitre (admin-post.php).
 */
function admin_post_correction_chapitre(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_correction_chapitre().
	revenir( traiter_correction_chapitre( $_POST, get_current_user_id() ) );
}
add_action( 'admin_post_yume_correction_chapitre', __NAMESPACE__ . '\\admin_post_correction_chapitre' );

/**
 * Visiteur non connecté : vers la connexion, retour sur la vue.
 */
function admin_post_correction_anonyme(): void {
	$vue = function_exists( 'Yume\\Core\\Planning\\url_vue_equipe' ) ? url_vue_equipe( VUE_CLE ) : home_url( '/' );
	wp_safe_redirect( wp_login_url( $vue ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_correction', __NAMESPACE__ . '\\admin_post_correction_anonyme' );
add_action( 'admin_post_nopriv_yume_correction_chapitre', __NAMESPACE__ . '\\admin_post_correction_anonyme' );
