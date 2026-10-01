<?php
/**
 * Espace équipe, vue « Tous les tomes » (?vue=tomes, capacité yume_publier, celle du formulaire
 * de publication) : tous les tomes du catalogue, publiés compris (publiés, programmés,
 * brouillons), groupés par œuvre, avec des filtres œuvre / statut / recherche dans le titre
 * (GET, sans JavaScript) et une pagination.
 *
 * Pour chaque tome : couverture, libellé, parution (« À paraître », « En cours », « Complet » :
 * yume_parution_tome()), date, chapitres en ligne (« 3 chapitres sur 12 en ligne » quand le
 * nombre de chapitres prévus est connu), prochain chapitre programmé, et les actions « Ajouter
 * des chapitres » (formulaire de publication prérempli par ?tome=ID), « Voir » (tome publié) et
 * « Modifier » (fiche du tome dans l'espace équipe, ?vue=tomes&modifier=ID, si l'utilisateur
 * peut modifier le tome). Le filtre « Statut » porte sur la parution, plus « Programmés » (tome
 * ou chapitre programmé) et « Brouillons ».
 *
 * Sous-vues de la même entrée de menu (tome-fiche-equipe.php) : « Nouveau tome »
 * (?vue=tomes&nouveau=1) et « Modifier le tome » (?vue=tomes&modifier=ID).
 *
 * La section « Tomes en préparation » du tableau de bord ne liste que le planning à venir :
 * cette vue est le seul endroit de l'espace équipe où retrouver un tome déjà paru.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Tomes par page de la vue « Tous les tomes ». */
const CATALOGUE_PAR_PAGE = 60;

/**
 * Filtre « Statut » de la vue : clé => libellé.
 *
 * @return array<string,string>
 */
function statuts_vue_tomes(): array {
	return array(
		''           => __( 'Tous', 'yume-core' ),
		'a_paraitre' => __( 'À paraître', 'yume-core' ),
		'en_cours'   => __( 'En cours', 'yume-core' ),
		'complet'    => __( 'Complets', 'yume-core' ),
		'programme'  => __( 'Programmés', 'yume-core' ),
		'brouillon'  => __( 'Brouillons', 'yume-core' ),
	);
}

/**
 * Le tome correspond-il au filtre « Statut » ?
 *
 * @param array<string,mixed> $tome   Tome (voir tomes_equipe()).
 * @param string              $statut Clé (voir statuts_vue_tomes()).
 */
function tome_dans_statut( array $tome, string $statut ): bool {
	switch ( $statut ) {
		case 'a_paraitre':
		case 'en_cours':
		case 'complet':
			return $tome['parution'] === $statut;
		case 'programme':
			// Tome programmé, ou chapitre programmé (publication au fil de l'eau).
			return 'future' === $tome['statut'] || null !== $tome['prochain'];
		case 'brouillon':
			return in_array( $tome['statut'], array( 'draft', 'pending' ), true );
		default:
			return true;
	}
}

/**
 * Prochain chapitre programmé de chaque tome, en une requête : tome => array{id, ts}.
 *
 * @return array<int,array{id:int,ts:int}>
 */
function prochains_chapitres_programmes(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes    = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.meta_value AS tome_id, p.ID AS id, p.post_date_gmt AS date_gmt FROM {$wpdb->posts} p"
			. " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_tome_id'"
			. ' WHERE p.post_type = %s AND p.post_status = %s ORDER BY p.post_date_gmt ASC, p.ID ASC',
			'yume_chapitre',
			'future'
		)
	);
	$prochains = array();
	foreach ( $lignes as $ligne ) {
		$tome_id = (int) $ligne->tome_id;
		if ( $tome_id > 0 && ! isset( $prochains[ $tome_id ] ) ) {
			$prochains[ $tome_id ] = array(
				'id' => (int) $ligne->id,
				'ts' => ts_gmt( (string) $ligne->date_gmt ),
			);
		}
	}
	if ( $prochains ) {
		// Libellés des chapitres (« Chapitre 3 ») : contenus et métadonnées en un appel.
		_prime_post_caches( array_column( $prochains, 'id' ), false, true );
	}
	return $prochains;
}

/**
 * Parution d'un tome de la liste (yume_parution_tome()) sans requête par tome : pour un tome en
 * ligne antérieur à la méta yume_parution (arc, recueil ou web novel à l'étape « publié »), la
 * règle historique compte les chapitres programmés ou en brouillon, déjà comptés par
 * chapitres_par_tome().
 *
 * @param \WP_Post $tome    Tome.
 * @param int      $attente Chapitres programmés, en brouillon ou en attente.
 */
function parution_tome_liste( \WP_Post $tome, int $attente ): string {
	$meta  = (string) get_post_meta( $tome->ID, 'yume_parution', true );
	$etape = (string) get_post_meta( $tome->ID, 'yume_etape', true );
	if ( 'publish' !== $tome->post_status || '' !== $meta || ( '' !== $etape && 'publie' !== $etape ) ) {
		return yume_parution_tome( (int) $tome->ID );
	}
	$nature      = (string) get_post_meta( $tome->ID, 'yume_nature', true );
	$oeuvre_id   = (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true );
	$progressive = in_array( $nature, array( 'arc', 'chapitres' ), true ) || ( $oeuvre_id > 0 && has_term( 'web-novel', 'yume_type', $oeuvre_id ) );
	$etat        = $progressive && $attente > 0 ? 'en_cours' : 'complet';
	/** Ce filtre est documenté dans yume_parution_tome(). */
	return (string) apply_filters( 'yume_parution_tome', $etat, (int) $tome->ID );
}

/**
 * Tomes de la vue, filtrés, triés (œuvres par titre, tomes par numéro, extras à la fin),
 * paginés, puis groupés par œuvre.
 *
 * @param array{oeuvre?:int,statut?:string,recherche?:string} $filtres Filtres.
 * @param int                                                 $page    Page (1…).
 * @return array{total:int,page:int,pages:int,oeuvres:array<int,array{id:int,titre:string,tomes:array<int,array<string,mixed>>}>}
 */
function tomes_equipe( array $filtres, int $page = 1 ): array {
	$args = array(
		'post_type'        => 'yume_tome',
		'post_status'      => array( 'publish', 'private', 'future', 'draft', 'pending' ),
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
	);
	if ( ! empty( $filtres['oeuvre'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- quelques centaines de tomes.
		$args['meta_query'] = array(
			array(
				'key'   => 'yume_oeuvre_id',
				'value' => (string) (int) $filtres['oeuvre'],
			),
		);
	}
	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		// Titre du tome seulement (« Œuvre — Tome 3 : Sous-titre ») : le nom de l'œuvre y figure.
		$args['s']              = $recherche;
		$args['search_columns'] = array( 'post_title' );
	}
	$statut    = (string) ( $filtres['statut'] ?? '' );
	$chapitres = chapitres_par_tome();
	$prochains = prochains_chapitres_programmes();
	$titres    = array();
	$liste     = array();
	foreach ( get_posts( $args ) as $tome ) {
		$oeuvre = (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true );
		if ( ! $oeuvre || 'yume_oeuvre' !== get_post_type( $oeuvre ) || 'trash' === get_post_status( $oeuvre ) ) {
			continue;
		}
		$numero = get_post_meta( $tome->ID, 'yume_numero', true );
		$nb     = $chapitres[ (int) $tome->ID ] ?? array(
			'publies'    => 0,
			'attente'    => 0,
			'programmes' => 0,
		);
		$ligne  = array(
			'id'         => (int) $tome->ID,
			'oeuvre'     => $oeuvre,
			'libelle'    => yume_libelle_tome( (int) $tome->ID ),
			'statut'     => (string) $tome->post_status,
			'parution'   => parution_tome_liste( $tome, (int) $nb['attente'] ),
			'date'       => ts_contenu( $tome, in_array( $tome->post_status, array( 'publish', 'private', 'future' ), true ) ? 'post_date' : 'post_modified' ),
			'etape'      => (string) get_post_meta( $tome->ID, 'yume_etape', true ),
			'date_cible' => (string) get_post_meta( $tome->ID, 'yume_date_cible', true ),
			'ex'         => 'ex' === get_post_meta( $tome->ID, 'yume_nature', true ),
			'numero'     => is_numeric( $numero ) ? (float) $numero : PHP_FLOAT_MAX,
			'ordre'      => (int) $tome->menu_order,
			'publies'    => (int) $nb['publies'],
			'attente'    => (int) $nb['attente'],
			'programmes' => (int) $nb['programmes'],
			'prevus'     => (int) get_post_meta( $tome->ID, 'yume_chapitres_prevus', true ),
			'prochain'   => $prochains[ (int) $tome->ID ] ?? null,
			'pdf'        => '' !== trim( (string) get_post_meta( $tome->ID, 'yume_lien_pdf', true ) ),
			'epub'       => '' !== trim( (string) get_post_meta( $tome->ID, 'yume_lien_epub', true ) ),
		);
		if ( ! tome_dans_statut( $ligne, $statut ) ) {
			continue;
		}
		$titres[ $oeuvre ] = $titres[ $oeuvre ] ?? titre_brut( $oeuvre );
		$liste[]           = $ligne;
	}
	usort(
		$liste,
		static function ( array $a, array $b ) use ( $titres ): int {
			$cmp = strnatcasecmp( remove_accents( $titres[ $a['oeuvre'] ] ), remove_accents( $titres[ $b['oeuvre'] ] ) );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return array( $a['oeuvre'], $a['ex'], $a['numero'], $a['ordre'], $a['id'] ) <=> array( $b['oeuvre'], $b['ex'], $b['numero'], $b['ordre'], $b['id'] );
		}
	);
	$total    = count( $liste );
	$pages    = max( 1, (int) ceil( $total / CATALOGUE_PAR_PAGE ) );
	$page     = min( max( 1, $page ), $pages );
	$resultat = array(
		'total'   => $total,
		'page'    => $page,
		'pages'   => $pages,
		'oeuvres' => array(),
	);
	foreach ( array_slice( $liste, ( $page - 1 ) * CATALOGUE_PAR_PAGE, CATALOGUE_PAR_PAGE ) as $tome ) {
		$oeuvre = $tome['oeuvre'];
		if ( ! isset( $resultat['oeuvres'][ $oeuvre ] ) ) {
			$resultat['oeuvres'][ $oeuvre ] = array(
				'id'    => $oeuvre,
				'titre' => $titres[ $oeuvre ],
				'tomes' => array(),
			);
		}
		$resultat['oeuvres'][ $oeuvre ]['tomes'][] = $tome;
	}
	return $resultat;
}

/**
 * Pastille de parution d'un tome : « À paraître », « ● En cours », « ✓ Complet ».
 *
 * @param string $parution a_paraitre | en_cours | complet.
 */
function pastille_parution( string $parution ): string {
	$libelles = yume_parutions();
	if ( 'en_cours' === $parution ) {
		return '<span class="yn-chip yn-chip--ok"><span aria-hidden="true">●</span> ' . esc_html( $libelles['en_cours'] ) . '</span>';
	}
	if ( 'complet' === $parution ) {
		return '<span class="yn-chip yn-chip--ok"><span aria-hidden="true">✓</span> ' . esc_html( $libelles['complet'] ) . '</span>';
	}
	return '<span class="yn-chip yn-chip--warn">' . esc_html( $libelles['a_paraitre'] ) . '</span>';
}

/**
 * Date d'un tome selon sa parution : « Paru le 12 juin 2026 », « Depuis le 27 sept. 2026 »,
 * « Sortie le 3 oct. 2026 » (tome programmé), « Traduction · date cible 15 janv. 2027 » ou
 * « Modifié le … » (brouillon).
 *
 * @param array<string,mixed> $tome Tome (voir tomes_equipe()).
 */
function texte_date_tome( array $tome ): string {
	$date = $tome['date'] ? format_fr( (int) $tome['date'], 'j M Y' ) : '';
	if ( 'complet' === $tome['parution'] && in_array( $tome['statut'], array( 'publish', 'private' ), true ) ) {
		/* translators: %s : date de sortie */
		return '' !== $date ? sprintf( __( 'Paru le %s', 'yume-core' ), $date ) : '';
	}
	if ( 'en_cours' === $tome['parution'] ) {
		/* translators: %s : date de sortie du tome */
		return '' !== $date ? sprintf( __( 'Depuis le %s', 'yume-core' ), $date ) : '';
	}
	if ( 'future' === $tome['statut'] ) {
		/* translators: %s : date de sortie programmée */
		return '' !== $date ? sprintf( __( 'Sortie le %s', 'yume-core' ), $date ) : '';
	}
	$etapes = yume_etapes();
	if ( '' !== $tome['date_cible'] && valider_date( $tome['date_cible'] ) ) {
		$cible = sprintf(
			/* translators: %s : date cible */
			__( 'date cible %s', 'yume-core' ),
			format_fr( ts_date( $tome['date_cible'] ), 'j M Y' )
		);
		return isset( $etapes[ $tome['etape'] ] ) ? $etapes[ $tome['etape'] ] . ' · ' . $cible : majuscule( $cible );
	}
	/* translators: %s : date de dernière modification */
	return '' !== $date ? sprintf( __( 'Modifié le %s', 'yume-core' ), $date ) : '';
}

/**
 * Pastilles de chapitres d'un tome : « 3 chapitres sur 12 en ligne » (chapitres prévus connus),
 * « 2 chapitres en ligne », prochain chapitre programmé, chapitres en brouillon, PDF / EPUB.
 *
 * @param array<string,mixed> $tome Tome (voir tomes_equipe()).
 */
function pastilles_chapitres_tome( array $tome ): string {
	$publies = (int) $tome['publies'];
	$classe  = $publies > 0 ? 'yn-chip yn-chip--ok' : 'yn-chip';
	if ( $tome['prevus'] > 0 ) {
		$html = '<span class="' . $classe . '">' . esc_html(
			sprintf(
				/* translators: 1: chapitres en ligne, 2: chapitres prévus */
				_n( '%1$d chapitre sur %2$d en ligne', '%1$d chapitres sur %2$d en ligne', $publies, 'yume-core' ),
				$publies,
				(int) $tome['prevus']
			)
		) . '</span>';
	} elseif ( $publies > 0 ) {
		/* translators: %d : nombre de chapitres */
		$html = '<span class="' . $classe . '">' . esc_html( sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', $publies, 'yume-core' ), $publies ) ) . '</span>';
	} elseif ( in_array( $tome['statut'], array( 'publish', 'private' ), true ) ) {
		$html = '<span class="yn-chip">' . esc_html__( 'Pas de lecture en ligne', 'yume-core' ) . '</span>';
	} else {
		$html = '<span class="yn-chip">' . esc_html__( 'Pas encore de chapitre', 'yume-core' ) . '</span>';
	}
	if ( $tome['prochain'] ) {
		$html .= '<span class="yn-chip yn-chip--info">' . esc_html(
			sprintf(
				/* translators: 1: chapitre (« Chapitre 3 »), 2: date */
				__( '%1$s programmé le %2$s', 'yume-core' ),
				yume_libelle_chapitre( (int) $tome['prochain']['id'] ),
				format_fr( (int) $tome['prochain']['ts'], 'j M' )
			)
		) . '</span>';
	}
	$brouillons = (int) $tome['attente'] - (int) $tome['programmes'];
	if ( $brouillons > 0 ) {
		/* translators: %d : nombre de chapitres */
		$html .= '<span class="yn-chip yn-chip--warn">' . esc_html( sprintf( _n( '%d chapitre préparé, pas encore en ligne', '%d chapitres préparés, pas encore en ligne', $brouillons, 'yume-core' ), $brouillons ) ) . '</span>';
	}
	$formats = array_keys(
		array_filter(
			array(
				'PDF'  => $tome['pdf'],
				'EPUB' => $tome['epub'],
			)
		)
	);
	if ( $formats ) {
		$html .= '<span class="yn-chip">' . esc_html( implode( ' · ', $formats ) ) . '</span>';
	}
	return $html;
}

/**
 * Ligne d'un tome : couverture, libellé, parution, date, chapitres, actions.
 *
 * @param array<string,mixed> $tome   Tome (voir tomes_equipe()).
 * @param string              $oeuvre Titre de l'œuvre.
 */
function ligne_tome_equipe( array $tome, string $oeuvre ): string {
	$id         = (int) $tome['id'];
	$couverture = yume_get_cover_id( $id );
	$contexte   = '<span class="yn-visually-hidden"> — ' . esc_html( $oeuvre . ', ' . $tome['libelle'] ) . '</span>';
	$date       = texte_date_tome( $tome );
	$html       = '<li class="yn-lecture__tome" id="yn-tomes-' . $id . '">';
	$html      .= '<span class="yn-lecture__couverture" aria-hidden="true">';
	$html      .= $couverture ? yume_image_couverture( $couverture, 'thumbnail', array( 'alt' => '' ) ) : '<span class="yn-lecture__sans-couverture">' . esc_html( mb_strtoupper( mb_substr( $oeuvre, 0, 1 ) ) ) . '</span>';
	$html      .= '</span><div class="yn-lecture__infos"><p class="yn-lecture__libelle">' . esc_html( (string) $tome['libelle'] ) . '</p><p class="yn-lecture__puces">';
	$html      .= pastille_parution( (string) $tome['parution'] );
	$html      .= '' !== $date ? '<span class="yn-muted yn-tomes__date">' . esc_html( $date ) . '</span>' : '';
	$html      .= pastilles_chapitres_tome( $tome );
	$html      .= '</p></div><p class="yn-lecture__actions">';
	$html      .= '<a class="yn-btn yn-btn--primary yn-btn--sm" href="' . esc_url( url_publier_tome( $id ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . $contexte . '</a>';
	if ( in_array( $tome['statut'], array( 'publish', 'private' ), true ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir', 'yume-core' ) . $contexte . '</a>';
	}
	if ( current_user_can( 'edit_post', $id ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_modifier_tome( $id ) ) . '">' . esc_html__( 'Modifier', 'yume-core' ) . $contexte . '</a>';
	}
	return $html . '</p></li>';
}

/**
 * Texte recherché (paramètre GET « recherche »).
 */
function get_recherche_tomes(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre d'affichage en lecture seule.
	$brut = isset( $_GET['recherche'] ) && is_string( $_GET['recherche'] ) ? sanitize_text_field( wp_unslash( $_GET['recherche'] ) ) : '';
	return mb_substr( trim( $brut ), 0, 100 );
}

/**
 * Message d'accès refusé d'une vue de l'espace équipe (navigation et racine comprises).
 *
 * @param string $html    Début de la vue (racine, navigation, colonne principale ouverte).
 * @param string $titre   Titre de la vue.
 * @param string $message Message.
 * @param string $retour  Adresse du bouton de retour.
 * @param string $libelle Libellé du bouton de retour.
 */
function acces_refuse_vue( string $html, string $titre, string $message, string $retour = '', string $libelle = '' ): string {
	$html .= tete_vue( $titre, '' );
	$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html( $message ) . '</p>';
	$html .= '<p><a class="yn-btn" href="' . esc_url( '' !== $retour ? $retour : url_vue_equipe() ) . '">' . esc_html( '' !== $libelle ? $libelle : __( 'Retour au tableau de bord', 'yume-core' ) ) . '</a></p></div>';
	return $html . '</div></div>';
}

/**
 * Vue « Tous les tomes » (?vue=tomes) ; ?nouveau=1 : « Nouveau tome » ; ?modifier=ID :
 * « Modifier le tome ».
 */
function rendu_vue_tomes(): string {
	if ( get_entier( 'nouveau' ) ) {
		return rendu_nouveau_tome();
	}
	if ( get_entier( 'modifier' ) ) {
		return rendu_modifier_tome( get_entier( 'modifier' ) );
	}

	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--tomes' );
	$html .= navigation_equipe( 'tomes' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( 'yume_publier' ) ) {
		return acces_refuse_vue( $html, __( 'Tous les tomes', 'yume-core' ), __( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent gérer la lecture en ligne des tomes.', 'yume-core' ) );
	}

	$oeuvre    = get_entier( 'oeuvre' );
	$oeuvre    = $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) ? $oeuvre : 0;
	$statut    = get_cle( 'statut' );
	$statut    = isset( statuts_vue_tomes()[ $statut ] ) ? $statut : '';
	$recherche = get_recherche_tomes();
	$filtres   = array_filter(
		array(
			'oeuvre'    => $oeuvre,
			'statut'    => $statut,
			'recherche' => $recherche,
		)
	);
	$donnees   = tomes_equipe( $filtres, max( 1, get_entier( 'pg' ) ) );

	$boutons = '';
	if ( current_user_can( 'yume_maj_planning_tous' ) ) {
		$boutons .= '<a class="yn-btn" href="' . esc_url( url_nouveau_tome( $oeuvre, 'tomes' ) ) . '">' . esc_html__( 'Nouveau tome', 'yume-core' ) . '</a>';
	}
	$boutons .= '<a class="yn-btn yn-btn--primary" href="' . esc_url( yume_url_page( 'publier' ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a>';
	$html    .= tete_vue( __( 'Tous les tomes', 'yume-core' ), $boutons );
	$html    .= '<p class="yn-muted">' . esc_html__( 'Tous les tomes du catalogue, publiés compris. « Ajouter des chapitres » ouvre le formulaire de publication avec le tome déjà choisi : un chapitre, plusieurs ou le tome entier. « Modifier » ouvre la fiche du tome ici, dans l’espace équipe : ses champs, « Tome complet » et ses chapitres (publier, programmer, retirer).', 'yume-core' ) . '</p>';

	// Filtres (GET, sans JavaScript).
	$choix = choix_oeuvres( __( 'Toutes', 'yume-core' ) );
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" role="search" aria-label="' . esc_attr__( 'Filtrer les tomes', 'yume-core' ) . '">' . champs_caches_vue( 'tomes' );
	$html .= champ_select( 'yn-t-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), $choix, $oeuvre ? (string) $oeuvre : '' );
	$html .= champ_select( 'yn-t-statut', 'statut', __( 'Statut', 'yume-core' ), statuts_vue_tomes(), $statut );
	$html .= champ_saisie( 'yn-t-recherche', 'recherche', __( 'Titre contient', 'yume-core' ), $recherche, 'search', array( 'maxlength' => 100 ) );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p>';
	$html .= '</form>';

	$html .= '<section class="yn-team__section" id="yn-tomes-liste" aria-labelledby="yn-tomes-liste-titre"><div class="yn-team__section-tete">';
	$html .= '<h2 id="yn-tomes-liste-titre">' . esc_html(
		$donnees['total'] > 0
			/* translators: %d : nombre de tomes */
			? sprintf( _n( '%d tome', '%d tomes', $donnees['total'], 'yume-core' ), $donnees['total'] )
			: __( 'Aucun tome', 'yume-core' )
	) . '</h2>';
	if ( $filtres ) {
		$html .= '<a href="' . esc_url( url_vue_equipe( 'tomes' ) ) . '">' . esc_html__( 'Effacer les filtres', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	if ( ! $donnees['total'] ) {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html(
			$filtres
				? __( 'Aucun tome ne correspond à ces filtres.', 'yume-core' )
				: __( 'Aucun tome pour le moment.', 'yume-core' )
		) . '</p>';
	}
	foreach ( $donnees['oeuvres'] as $id => $groupe ) {
		$titre_id = 'yn-tomes-oeuvre-' . (int) $id;
		$html    .= '<section class="yn-card yn-lecture__oeuvre" aria-labelledby="' . esc_attr( $titre_id ) . '"><div class="yn-lecture__oeuvre-tete">';
		$html    .= '<h3 id="' . esc_attr( $titre_id ) . '">' . esc_html( $groupe['titre'] ) . '</h3>';
		$html    .= '<span class="yn-muted">' . esc_html(
			/* translators: %d : nombre de tomes */
			sprintf( _n( '%d tome', '%d tomes', count( $groupe['tomes'] ), 'yume-core' ), count( $groupe['tomes'] ) )
		) . '</span></div><ul class="yn-lecture__tomes">';
		foreach ( $groupe['tomes'] as $tome ) {
			$html .= ligne_tome_equipe( $tome, $groupe['titre'] );
		}
		$html .= '</ul></section>';
	}
	$html .= pagination_vue( 'tomes', $filtres, $donnees['page'], $donnees['page'] < $donnees['pages'], $donnees['pages'] );
	$html .= '</section>';

	return $html . '</div></div>';
}
