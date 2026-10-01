<?php
/**
 * Espace équipe, vue « Lecture à compléter » (?vue=lecture, capacité yume_publier) : les tomes
 * déjà parus qui n'ont aucun chapitre en ligne (tomes migrés avec leurs seuls liens PDF/EPUB),
 * groupés par œuvre, avec la progression « X tomes sur Y ont la lecture en ligne », un filtre
 * par œuvre et, pour chaque tome, le bouton « Ajouter le DOCX » (formulaire de publication
 * prérempli, case « Ajout au catalogue » cochée d'office puisque le tome est publié).
 *
 * Un tome quitte la liste dès qu'un de ses chapitres est publié.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Chapitres des tomes par statut, en une requête : publiés, en préparation (brouillons, en
 * attente, programmés) et, parmi eux, programmés.
 *
 * @return array<int,array{publies:int,attente:int,programmes:int}> Tome => nombres.
 */
function chapitres_par_tome(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes  = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.meta_value AS tome_id, p.post_status AS statut, COUNT(DISTINCT p.ID) AS nb FROM {$wpdb->posts} p"
			. " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_tome_id'"
			. " WHERE p.post_type = %s AND p.post_status IN ('publish', 'draft', 'pending', 'future') GROUP BY m.meta_value, p.post_status",
			'yume_chapitre'
		)
	);
	$nombres = array();
	foreach ( $lignes as $ligne ) {
		$tome_id = (int) $ligne->tome_id;
		if ( $tome_id <= 0 ) {
			continue;
		}
		$nombres[ $tome_id ]          = $nombres[ $tome_id ] ?? array(
			'publies'    => 0,
			'attente'    => 0,
			'programmes' => 0,
		);
		$cle                          = 'publish' === $ligne->statut ? 'publies' : 'attente';
		$nombres[ $tome_id ][ $cle ] += (int) $ligne->nb;
		if ( 'future' === $ligne->statut ) {
			$nombres[ $tome_id ]['programmes'] += (int) $ligne->nb;
		}
	}
	return $nombres;
}

/**
 * Tomes parus sans lecture en ligne, groupés par œuvre (œuvres par titre, tomes par numéro).
 *
 * @param int $oeuvre_id Œuvre (0 : toutes).
 * @return array{total:int,avec:int,oeuvres:array<int,array{id:int,titre:string,total:int,tomes:array<int,array<string,mixed>>}>}
 *         total : tomes publiés ; avec : tomes qui ont la lecture en ligne ; oeuvres : œuvres
 *         ayant des tomes publiés (tomes = ceux sans lecture en ligne).
 */
function lecture_a_completer( int $oeuvre_id = 0 ): array {
	$args = array(
		'post_type'        => 'yume_tome',
		'post_status'      => 'publish',
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
	);
	if ( $oeuvre_id ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- quelques dizaines de tomes.
		$args['meta_query'] = array(
			array(
				'key'   => 'yume_oeuvre_id',
				'value' => (string) $oeuvre_id,
			),
		);
	}
	$chapitres = chapitres_par_tome();
	$resultat  = array(
		'total'   => 0,
		'avec'    => 0,
		'oeuvres' => array(),
	);
	foreach ( get_posts( $args ) as $tome ) {
		$oeuvre = (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true );
		if ( ! $oeuvre || 'yume_oeuvre' !== get_post_type( $oeuvre ) || 'trash' === get_post_status( $oeuvre ) ) {
			continue;
		}
		++$resultat['total'];
		if ( ! isset( $resultat['oeuvres'][ $oeuvre ] ) ) {
			$resultat['oeuvres'][ $oeuvre ] = array(
				'id'    => $oeuvre,
				'titre' => titre_brut( $oeuvre ),
				'total' => 0,
				'tomes' => array(),
			);
		}
		++$resultat['oeuvres'][ $oeuvre ]['total'];
		$nb = $chapitres[ (int) $tome->ID ] ?? array(
			'publies' => 0,
			'attente' => 0,
		);
		if ( $nb['publies'] > 0 ) {
			++$resultat['avec'];
			continue;
		}
		$numero                                    = get_post_meta( $tome->ID, 'yume_numero', true );
		$resultat['oeuvres'][ $oeuvre ]['tomes'][] = array(
			'id'      => (int) $tome->ID,
			'libelle' => yume_libelle_tome( (int) $tome->ID ),
			'ex'      => 'ex' === get_post_meta( $tome->ID, 'yume_nature', true ),
			'numero'  => is_numeric( $numero ) ? (float) $numero : PHP_FLOAT_MAX,
			'ordre'   => (int) $tome->menu_order,
			'pdf'     => '' !== trim( (string) get_post_meta( $tome->ID, 'yume_lien_pdf', true ) ),
			'epub'    => '' !== trim( (string) get_post_meta( $tome->ID, 'yume_lien_epub', true ) ),
			'attente' => (int) $nb['attente'],
		);
	}
	foreach ( $resultat['oeuvres'] as &$groupe ) {
		usort(
			$groupe['tomes'],
			static function ( array $a, array $b ): int {
				return array( $a['ex'], $a['numero'], $a['ordre'], $a['id'] ) <=> array( $b['ex'], $b['numero'], $b['ordre'], $b['id'] );
			}
		);
	}
	unset( $groupe );
	uasort(
		$resultat['oeuvres'],
		static function ( array $a, array $b ): int {
			return strnatcasecmp( remove_accents( $a['titre'] ), remove_accents( $b['titre'] ) );
		}
	);
	return $resultat;
}

/**
 * Ligne d'un tome à compléter : couverture, libellé, liens présents, boutons.
 *
 * @param array<string,mixed> $tome   Tome (voir lecture_a_completer()).
 * @param string              $oeuvre Titre de l'œuvre.
 */
function ligne_lecture_a_completer( array $tome, string $oeuvre ): string {
	$id         = (int) $tome['id'];
	$couverture = yume_get_cover_id( $id );
	$contexte   = '<span class="yn-visually-hidden"> — ' . esc_html( $oeuvre . ', ' . $tome['libelle'] ) . '</span>';
	$html       = '<li class="yn-lecture__tome" id="yn-lecture-' . $id . '">';
	$html      .= '<span class="yn-lecture__couverture" aria-hidden="true">';
	$html      .= $couverture ? yume_image_couverture( $couverture, 'thumbnail', array( 'alt' => '' ) ) : '<span class="yn-lecture__sans-couverture">' . esc_html( mb_strtoupper( mb_substr( $oeuvre, 0, 1 ) ) ) . '</span>';
	$html      .= '</span><div class="yn-lecture__infos"><p class="yn-lecture__libelle">' . esc_html( (string) $tome['libelle'] ) . '</p><p class="yn-lecture__puces">';
	foreach ( array(
		'pdf'  => 'PDF',
		'epub' => 'EPUB',
	) as $cle => $format ) {
		$html .= ! empty( $tome[ $cle ] )
			/* translators: %s : PDF ou EPUB */
			? '<span class="yn-chip yn-chip--ok"><span aria-hidden="true">✓</span> ' . esc_html( sprintf( __( '%s présent', 'yume-core' ), $format ) ) . '</span>'
			: '<span class="yn-chip">' . esc_html( 'pdf' === $cle ? __( 'Pas de PDF', 'yume-core' ) : __( 'Pas d’EPUB', 'yume-core' ) ) . '</span>';
	}
	if ( $tome['attente'] > 0 ) {
		/* translators: %d : nombre de chapitres */
		$html .= '<span class="yn-chip yn-chip--warn">' . esc_html( sprintf( _n( '%d chapitre préparé, pas encore en ligne', '%d chapitres préparés, pas encore en ligne', (int) $tome['attente'], 'yume-core' ), (int) $tome['attente'] ) ) . '</span>';
	}
	$html .= '</p></div><p class="yn-lecture__actions">';
	$html .= '<a class="yn-btn yn-btn--primary yn-btn--sm" href="' . esc_url( add_query_arg( 'tome', $id, yume_url_page( 'publier' ) ) ) . '">' . esc_html__( 'Ajouter le DOCX', 'yume-core' ) . $contexte . '</a>';
	$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir la fiche', 'yume-core' ) . $contexte . '</a>';
	return $html . '</p></li>';
}

/**
 * Vue « Lecture à compléter » (?vue=lecture).
 */
function rendu_vue_lecture(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--lecture' );
	$html .= navigation_equipe( 'lecture' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( 'yume_publier' ) ) {
		$html .= tete_vue( __( 'Lecture en ligne à compléter', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent ajouter la lecture en ligne d’un tome.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}

	$oeuvre = get_entier( 'oeuvre' );
	$oeuvre = $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) ? $oeuvre : 0;
	$tout   = lecture_a_completer();
	$donnes = $oeuvre ? lecture_a_completer( $oeuvre ) : $tout;
	$reste  = $donnes['total'] - $donnes['avec'];

	$html .= tete_vue( __( 'Lecture en ligne à compléter', 'yume-core' ), '<a class="yn-btn" href="' . esc_url( yume_url_page( 'publier' ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a>' );
	$html .= '<p class="yn-muted">' . esc_html__( 'Tomes déjà parus qui n’ont que leurs liens PDF ou EPUB. « Ajouter le DOCX » ouvre le formulaire de publication prérempli, en mode « Ajout au catalogue » : les chapitres sont mis en ligne sans annonce (ni article, ni Discord, ni e-mail) et le tome quitte cette liste.', 'yume-core' ) . '</p>';

	// Progression.
	$pct   = $donnes['total'] > 0 ? (int) floor( 100 * $donnes['avec'] / $donnes['total'] ) : 100;
	$html .= '<section class="yn-card yn-lecture__progression" aria-labelledby="yn-lecture-progression">';
	$html .= '<p class="yn-label" id="yn-lecture-progression">' . esc_html( $oeuvre ? titre_brut( $oeuvre ) : __( 'Tout le catalogue', 'yume-core' ) ) . '</p>';
	$html .= '<p class="yn-lecture__resume"><strong>' . esc_html(
		sprintf(
			/* translators: 1: tomes avec lecture en ligne, 2: tomes publiés */
			_n( '%1$d tome sur %2$d a la lecture en ligne', '%1$d tomes sur %2$d ont la lecture en ligne', max( 1, $donnes['avec'] ), 'yume-core' ),
			$donnes['avec'],
			$donnes['total']
		)
	) . '</strong>';
	$html .= $reste > 0
		/* translators: %d : tomes à compléter */
		? ' <span class="yn-muted">· ' . esc_html( sprintf( _n( '%d à compléter', '%d à compléter', $reste, 'yume-core' ), $reste ) ) . '</span>'
		: '';
	$html .= '</p><span class="yn-bar yn-bar--ok" aria-hidden="true"><span style="--v:' . $pct . '%"></span></span></section>';

	// Filtre par œuvre (œuvres qui ont des tomes publiés, avec leur reste à compléter).
	$choix = array( '' => __( 'Toutes', 'yume-core' ) );
	foreach ( $tout['oeuvres'] as $id => $groupe ) {
		$nb                    = count( $groupe['tomes'] );
		$choix[ (string) $id ] = $groupe['titre'] . ( $nb ? ' (' . $nb . ')' : '' );
	}
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Filtrer par œuvre', 'yume-core' ) . '">' . champs_caches_vue( 'lecture' );
	$html .= champ_select( 'yn-l-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), $choix, $oeuvre ? (string) $oeuvre : '' );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p>';
	$html .= '</form>';

	$html .= '<section class="yn-team__section" id="yn-lecture-liste" aria-labelledby="yn-lecture-liste-titre"><div class="yn-team__section-tete">';
	$html .= '<h2 id="yn-lecture-liste-titre">' . esc_html(
		$reste > 0
			/* translators: %d : tomes à compléter */
			? sprintf( _n( '%d tome sans lecture en ligne', '%d tomes sans lecture en ligne', $reste, 'yume-core' ), $reste )
			: __( 'Aucun tome à compléter', 'yume-core' )
	) . '</h2>';
	if ( $oeuvre ) {
		$html .= '<a href="' . esc_url( url_vue_equipe( 'lecture' ) ) . '">' . esc_html__( 'Toutes les œuvres', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	if ( $reste <= 0 ) {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html(
			$donnes['total'] > 0
				? __( 'Tous les tomes parus ont leur lecture en ligne.', 'yume-core' )
				: __( 'Aucun tome publié pour le moment.', 'yume-core' )
		) . '</p>';
	}
	foreach ( $donnes['oeuvres'] as $id => $groupe ) {
		if ( ! $groupe['tomes'] ) {
			continue;
		}
		$titre_id = 'yn-lecture-oeuvre-' . (int) $id;
		$html    .= '<section class="yn-card yn-lecture__oeuvre" aria-labelledby="' . esc_attr( $titre_id ) . '"><div class="yn-lecture__oeuvre-tete">';
		$html    .= '<h3 id="' . esc_attr( $titre_id ) . '">' . esc_html( $groupe['titre'] ) . '</h3>';
		$html    .= '<span class="yn-muted">' . esc_html(
			sprintf(
				/* translators: 1: tomes à compléter, 2: tomes publiés de l'œuvre */
				_n( '%1$d à compléter sur %2$d tome paru', '%1$d à compléter sur %2$d tomes parus', $groupe['total'], 'yume-core' ),
				count( $groupe['tomes'] ),
				$groupe['total']
			)
		) . '</span></div><ul class="yn-lecture__tomes">';
		foreach ( $groupe['tomes'] as $tome ) {
			$html .= ligne_lecture_a_completer( $tome, $groupe['titre'] );
		}
		$html .= '</ul></section>';
	}
	$html .= '</section>';

	return $html . '</div></div>';
}
