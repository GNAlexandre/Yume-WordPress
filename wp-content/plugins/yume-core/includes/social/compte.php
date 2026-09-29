<?php
/**
 * Rendu du bloc yume/account (maquette Compte.dc.html ; titres à partir de <h2>, le modèle
 * page-large affichant déjà le titre de la page en <h1>).
 *
 * - Connecté : navigation par rubriques (onglets accessibles avec JavaScript, sections
 *   empilées et ancres sans JavaScript) : Lecture en cours, Mes statistiques, Favoris et alertes, Mes
 *   listes (listes.php), Notifications (notifications-lecteur.php), Notes et commentaires, Réglages de lecture, Alertes, Profil et sécurité, Données et suppression.
 * - Déconnecté : connexion (formulaire propre, connexion.php), mot de passe oublié et inscription en façade.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Rubriques de la page compte : identifiant d'ancre => libellé.
 *
 * @param int $nb_favoris Nombre de favoris (affiché dans le libellé).
 * @return array<string,string>
 */
function rubriques_compte( int $nb_favoris = 0 ): array {
	return array(
		'yn-lecture'       => __( 'Lecture en cours', 'yume-core' ),
		'yn-stats'         => __( 'Mes statistiques', 'yume-core' ),
		/* translators: %d : nombre de favoris. */
		'yn-favoris'       => $nb_favoris > 0 ? sprintf( __( 'Favoris et alertes (%d)', 'yume-core' ), $nb_favoris ) : __( 'Favoris et alertes', 'yume-core' ),
		'yn-listes'        => __( 'Mes listes', 'yume-core' ),
		'yn-notifications' => __( 'Notifications', 'yume-core' ),
		'yn-notes'         => __( 'Notes et commentaires', 'yume-core' ),
		'yn-reglages'      => __( 'Réglages de lecture', 'yume-core' ),
		'yn-alertes'       => __( 'Alertes', 'yume-core' ),
		'yn-profil'        => __( 'Profil et sécurité', 'yume-core' ),
		'yn-donnees'       => __( 'Données et suppression', 'yume-core' ),
	);
}

/**
 * Rendu du bloc yume/account.
 */
function rendu_compte(): string {
	if ( apercu_editeur() ) {
		return rendu_apercu( 'yn-account', __( 'Page compte : lecture en cours, statistiques, favoris et alertes, notes, réglages, profil, données (connexion et inscription pour les visiteurs).', 'yume-core' ) );
	}
	$html = is_user_logged_in() ? compte_connecte() : compte_visiteur();
	return $html;
}

/**
 * Initiales d'un nom (avatar sans service externe).
 *
 * @param string $nom Nom.
 */
function initiales( string $nom ): string {
	$mots = preg_split( '/[\s._\-@]+/u', trim( $nom ), -1, PREG_SPLIT_NO_EMPTY );
	$ini  = '';
	foreach ( array_slice( (array) $mots, 0, 2 ) as $mot ) {
		$ini .= mb_strtoupper( mb_substr( $mot, 0, 1 ) );
	}
	return '' !== $ini ? $ini : '?';
}

/**
 * Couverture réduite (image ou dégradé de substitution avec initiales).
 *
 * @param int    $post_id Tome, chapitre ou œuvre.
 * @param string $texte   Texte de substitution.
 * @param string $classe  Classe supplémentaire.
 */
function mini_couverture( int $post_id, string $texte, string $classe = '' ): string {
	$image = function_exists( 'yume_get_cover_id' ) ? yume_get_cover_id( $post_id ) : 0;
	$html  = $image ? wp_get_attachment_image(
		$image,
		'yume-couverture',
		false,
		array(
			'alt'     => '',
			'loading' => 'lazy',
		)
	) : '';
	return '<span class="yn-cover yn-account__couverture ' . esc_attr( $classe ) . '" aria-hidden="true">' . ( $html ? $html : '<span>' . esc_html( $texte ) . '</span>' ) . '</span>';
}

/**
 * Étoiles d'une note (texte décoratif + équivalent lisible).
 *
 * @param int $note Note 1–5.
 */
function etoiles( int $note ): string {
	$note = max( 0, min( 5, $note ) );
	return '<span class="yn-account__etoiles" aria-hidden="true">' . str_repeat( '★', $note ) . '<span class="yn-account__etoiles-vides">' . str_repeat( '☆', 5 - $note ) . '</span></span>'
		/* translators: %d : note. */
		. '<span class="yn-visually-hidden">' . esc_html( sprintf( __( '%d sur 5', 'yume-core' ), $note ) ) . '</span>';
}

/**
 * Pastille d'état (icône + texte, jamais la couleur seule).
 *
 * @param string $variante ok, warn, err, info.
 * @param string $icone    Symbole.
 * @param string $texte    Texte.
 */
function pastille( string $variante, string $icone, string $texte ): string {
	return '<span class="yn-chip yn-chip--' . esc_attr( $variante ) . '"><span aria-hidden="true">' . esc_html( $icone ) . '</span> ' . esc_html( $texte ) . '</span>';
}

/**
 * « Où en est la traduction » d'une œuvre : prochain tome du planning, sinon statut.
 *
 * @param int $oeuvre_id Œuvre.
 */
function etat_traduction( int $oeuvre_id ): string {
	if ( function_exists( 'yume_get_planning' ) ) {
		$lignes = array_values(
			array_filter(
				(array) yume_get_planning(
					array(
						'oeuvre_id' => $oeuvre_id,
						'a_venir'   => true,
						'limit'     => 5,
					)
				),
				static function ( $ligne ): bool {
					// Un tome déjà sorti n'est plus « en cours de traduction ».
					return is_array( $ligne ) && 'publish' !== ( $ligne['statut'] ?? '' ) && 'publie' !== ( $ligne['etape'] ?? '' );
				}
			)
		);
		if ( $lignes ) {
			$ligne  = $lignes[0];
			$etapes = function_exists( 'yume_etapes' ) ? yume_etapes() : array();
			$etape  = (string) ( $ligne['etape'] ?? '' );
			$texte  = (string) ( $ligne['tome'] ?? '' );
			if ( isset( $etapes[ $etape ] ) ) {
				$texte     .= ' · ' . mb_strtolower( $etapes[ $etape ] );
				$avancement = (array) ( $ligne['avancement'] ?? array() );
				if ( isset( $avancement[ $etape ] ) ) {
					$texte .= ' ' . (int) $avancement[ $etape ] . ' %';
				}
			}
			$etat = (string) ( $ligne['etat'] ?? '' );
			if ( 'en_retard' === $etat ) {
				$chip = pastille( 'warn', '!', __( 'en retard', 'yume-core' ) );
			} elseif ( 'bloque' === $etat ) {
				$chip = pastille( 'err', '■', __( 'bloqué', 'yume-core' ) );
			} elseif ( ! empty( $ligne['date_cible'] ) ) {
				$chip = pastille( 'ok', '●', wp_date( 'D j', (int) strtotime( $ligne['date_cible'] . ' 12:00:00' ) ) );
			} else {
				$chip = pastille( 'ok', '●', __( 'à l’heure', 'yume-core' ) );
			}
			return esc_html( $texte ) . ' ' . $chip;
		}
	}
	$termes = get_the_terms( $oeuvre_id, 'yume_statut' );
	if ( is_array( $termes ) && $termes ) {
		$slug      = $termes[0]->slug;
		$variantes = array(
			'terminee'   => array( 'ok', '✓' ),
			'en-cours'   => array( 'info', '●' ),
			'en-pause'   => array( 'warn', '‖' ),
			'licenciee'  => array( 'info', '©' ),
			'abandonnee' => array( 'err', '×' ),
		);
		$v         = $variantes[ $slug ] ?? array( 'info', '●' );
		return pastille( $v[0], $v[1], $termes[0]->name );
	}
	return '<span class="yn-muted">—</span>';
}

/**
 * Détails d'une œuvre favorite : « 9 tomes · dernier le 20 sept. ».
 *
 * @param int $oeuvre_id Œuvre.
 */
function details_oeuvre( int $oeuvre_id ): string {
	$nb       = function_exists( 'yume_get_tomes' ) ? count( yume_get_tomes( $oeuvre_id ) ) : 0;
	$morceaux = array();
	if ( $nb ) {
		/* translators: %d : nombre de tomes. */
		$morceaux[] = sprintf( _n( '%d tome', '%d tomes', $nb, 'yume-core' ), $nb );
	}
	$derniere = (string) get_post_meta( $oeuvre_id, 'yume_derniere_sortie', true );
	$ts       = '' !== $derniere ? strtotime( $derniere . ' UTC' ) : false;
	if ( $ts ) {
		/* translators: %s : date. */
		$morceaux[] = sprintf( __( 'dernier le %s', 'yume-core' ), wp_date( 'j M', $ts ) );
	}
	return implode( ' · ', $morceaux );
}

/*
 * -----------------------------------------------------------------------------
 * Membre connecté
 * -----------------------------------------------------------------------------
 */

/**
 * Page compte d'un membre connecté.
 */
function compte_connecte(): string {
	$user      = wp_get_current_user();
	$user_id   = (int) $user->ID;
	$favoris   = array_values(
		array_filter(
			favoris_utilisateur( $user_id ),
			static function ( array $ligne ): bool {
				return oeuvre_publiee( $ligne['oeuvre_id'] );
			}
		)
	);
	$rubriques = rubriques_profil_public( rubriques_compte( count( $favoris ) ), $user_id );
	$donnees   = array_merge( donnees_rest(), array( 'libelles' => libelles_frequences() ) );

	$html  = '<div ' . attributs_racine(
		'yn-account',
		array(
			'id'             => 'yn-compte',
			'data-yn-compte' => wp_json_encode( $donnees ),
		)
	) . '>';
	$html .= '<div class="yn-account__messages">' . confirmation_desabonnement() . html_messages( messages_courants() ) . '</div>';
	$html .= '<div class="yn-account__grille">';

	// Navigation.
	$depuis = wp_date( 'F Y', (int) strtotime( $user->user_registered . ' UTC' ) );
	$html  .= '<nav class="yn-account__nav" aria-label="' . esc_attr__( 'Rubriques du compte', 'yume-core' ) . '">'
		. '<div class="yn-account__profil"><span class="yn-account__avatar" aria-hidden="true">' . esc_html( initiales( $user->display_name ) ) . '</span>'
		. '<div><p class="yn-account__pseudo">' . esc_html( $user->display_name ) . '</p>'
		/* translators: %s : mois et année d'inscription. */
		. '<p class="yn-muted yn-account__depuis">' . esc_html( sprintf( __( 'membre depuis %s', 'yume-core' ), $depuis ) ) . '</p></div></div>'
		. '<ul class="yn-account__onglets" data-yn-onglets>';
	foreach ( $rubriques as $ancre => $libelle ) {
		$html .= '<li><a class="yn-account__onglet" href="#' . esc_attr( $ancre ) . '" id="' . esc_attr( $ancre . '-onglet' ) . '" data-yn-onglet="' . esc_attr( $ancre ) . '">' . esc_html( $libelle ) . '</a></li>';
	}
	$html .= '</ul>'
		. '<p class="yn-account__deconnexion"><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Se déconnecter', 'yume-core' ) . '</a></p>'
		. '</nav>';

	$html .= '<div class="yn-account__contenu">';
	$html .= section_lecture( $user_id );
	$html .= section_statistiques( $user_id );
	$html .= section_favoris( $user_id, $favoris );
	$html .= section_listes( $user_id );
	$html .= section_notifications( $user_id );
	$html .= section_notes( $user_id );
	$html .= section_reglages( $user_id );
	$html .= section_alertes( $user_id );
	$html .= section_profil( $user );
	$html .= section_profil_public( $user );
	$html .= section_donnees( $user );
	$html .= '</div></div>';
	$html .= '<p class="yn-visually-hidden" role="status" aria-live="polite" data-yn-annonce></p>';
	$html .= '</div>';
	return $html;
}

/**
 * Ouverture d'une section de rubrique.
 *
 * @param string $ancre Identifiant.
 * @param string $titre Titre (h2).
 * @param string $aide  Texte d'aide à droite du titre.
 */
function debut_section( string $ancre, string $titre, string $aide = '' ): string {
	return '<section class="yn-account__panneau" id="' . esc_attr( $ancre ) . '" aria-labelledby="' . esc_attr( $ancre . '-titre' ) . '" data-yn-panneau="' . esc_attr( $ancre ) . '" tabindex="-1">'
		. '<div class="yn-account__entete"><h2 class="yn-account__titre" id="' . esc_attr( $ancre . '-titre' ) . '">' . esc_html( $titre ) . '</h2>'
		. ( '' !== $aide ? '<p class="yn-muted yn-account__aide">' . esc_html( $aide ) . '</p>' : '' ) . '</div>';
}

/**
 * Lecture en cours.
 *
 * @param int $user_id Membre.
 */
function section_lecture( int $user_id ): string {
	$html   = debut_section( 'yn-lecture', __( 'Reprendre la lecture', 'yume-core' ), __( 'Position enregistrée automatiquement pendant la lecture', 'yume-core' ) );
	$cartes = '';
	$n      = 0;
	if ( function_exists( '\Yume\Core\Reader\enrichir_ligne' ) ) {
		foreach ( yume_get_progression( $user_id ) as $ligne ) {
			$position = \Yume\Core\Reader\enrichir_ligne( $ligne );
			if ( ! $position || $n >= 12 ) {
				continue;
			}
			$detail  = implode( ' · ', array_filter( array( $position['tome'], mb_strtolower( $position['chapitre'] ), libelle_part_chapitre( (int) $position['pourcentage'] ) ), 'strlen' ) );
			$cartes .= '<article class="yn-card yn-account__carte">'
				. mini_couverture( $position['chapitre_id'], $position['tome'] ? $position['oeuvre'] . ' ' . $position['tome'] : $position['oeuvre'] )
				. '<div class="yn-account__carte-corps">'
				. '<h3 class="yn-account__carte-titre"><a href="' . esc_url( (string) get_permalink( $position['oeuvre_id'] ) ) . '">' . esc_html( $position['oeuvre'] ) . '</a></h3>'
				. '<p class="yn-muted yn-account__carte-detail">' . esc_html( $detail ) . '</p>'
				. '<span class="yn-bar" aria-hidden="true"><span style="--v:' . esc_attr( (string) $position['pourcentage'] ) . '%"></span></span>'
				. '<a class="yn-btn yn-btn--sm' . ( 0 === $n ? ' yn-btn--primary' : '' ) . ' yn-account__continuer" href="' . esc_url( $position['url_reprise'] ) . '">' . esc_html__( 'Continuer', 'yume-core' )
				. '<span class="yn-visually-hidden"> : ' . esc_html( $position['titre'] ) . '</span></a>'
				. '</div></article>';
			++$n;
		}
	}
	if ( '' === $cartes ) {
		$bibliotheque = function_exists( 'yume_url_page' ) ? yume_url_page( 'bibliotheque' ) : home_url( '/' );
		$html        .= '<p class="yn-account__vide">' . esc_html__( 'Aucune lecture en cours pour l’instant.', 'yume-core' ) . ' <a href="' . esc_url( $bibliotheque ) . '">' . esc_html__( 'Parcourir la bibliothèque', 'yume-core' ) . '</a></p>';
	} else {
		$html .= '<div class="yn-account__cartes">' . $cartes . '</div>';
	}
	return $html . '</section>';
}

/**
 * Mes statistiques (PAGE-06) : chiffres clés et état de chaque série commencée, calculés à
 * partir des positions de lecture du membre connecté seulement (Reader\statistiques_lecture()).
 *
 * @param int $user_id Membre.
 */
function section_statistiques( int $user_id ): string {
	$html = debut_section( 'yn-stats', __( 'Mes statistiques', 'yume-core' ), __( 'Estimées d’après votre position dans chaque série', 'yume-core' ) );
	if ( ! function_exists( '\Yume\Core\Reader\statistiques_lecture' ) ) {
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Le lecteur en ligne n’est pas disponible.', 'yume-core' ) . '</p></section>';
	}
	$stats = \Yume\Core\Reader\statistiques_lecture( $user_id );
	if ( ! $stats['series'] ) {
		$bibliotheque = function_exists( 'yume_url_page' ) ? yume_url_page( 'bibliotheque' ) : home_url( '/' );
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Vos statistiques apparaîtront après votre premier chapitre lu en étant connecté.', 'yume-core' ) . ' <a href="' . esc_url( $bibliotheque ) . '">' . esc_html__( 'Parcourir la bibliothèque', 'yume-core' ) . '</a></p></section>';
	}
	$chiffres = array(
		'tomes'     => array( __( 'Tomes terminés', 'yume-core' ), number_format_i18n( $stats['tomes_termines'] ) ),
		'chapitres' => array( __( 'Chapitres lus', 'yume-core' ), number_format_i18n( $stats['chapitres_lus'] ) ),
		'temps'     => array( __( 'Temps de lecture estimé', 'yume-core' ), \Yume\Core\Reader\duree_lisible( $stats['minutes'] ) ),
		'en-cours'  => array( __( 'Séries en cours', 'yume-core' ), number_format_i18n( $stats['series_en_cours'] ) ),
		'a-jour'    => array( __( 'Séries à jour', 'yume-core' ), number_format_i18n( $stats['series_a_jour'] ) ),
	);
	$html    .= '<div class="yn-card yn-account__bloc yn-account__stats"><p class="yn-label">' . esc_html__( 'En chiffres', 'yume-core' ) . '</p><dl class="yn-account__resume">';
	foreach ( $chiffres as $cle => $chiffre ) {
		$html .= '<dt class="yn-muted">' . esc_html( $chiffre[0] ) . '</dt><dd data-yn-stat="' . esc_attr( $cle ) . '"><strong>' . esc_html( $chiffre[1] ) . '</strong></dd>';
	}
	$html .= '</dl></div>';

	$html .= '<h3 class="yn-account__sous-titre">' . esc_html__( 'Séries commencées', 'yume-core' ) . '</h3><div class="yn-account__cartes" data-yn-stats-series>';
	foreach ( $stats['series'] as $serie ) {
		$part = $serie['chapitres'] > 0 ? (int) floor( 100 * $serie['chapitres_lus'] / $serie['chapitres'] ) : 0;
		if ( $serie['a_jour'] ) {
			$etat = pastille( 'ok', '✓', __( 'À jour', 'yume-core' ) );
		} else {
			/* translators: %d : nombre de chapitres publiés restant à lire. */
			$etat = pastille( 'info', '●', sprintf( _n( '%d chapitre à lire', '%d chapitres à lire', $serie['reste'], 'yume-core' ), $serie['reste'] ) );
		}
		$detail = sprintf(
			/* translators: 1 : chapitres lus, 2 : chapitres publiés, 3 : tomes terminés, 4 : tomes publiés. */
			__( 'Chapitres lus : %1$d sur %2$d · tomes terminés : %3$d sur %4$d', 'yume-core' ),
			$serie['chapitres_lus'],
			$serie['chapitres'],
			$serie['tomes_termines'],
			$serie['tomes']
		);
		$html .= '<article class="yn-card yn-account__carte" data-yn-serie="' . esc_attr( (string) $serie['oeuvre_id'] ) . '">'
			. mini_couverture( $serie['oeuvre_id'], $serie['titre'] )
			. '<div class="yn-account__carte-corps">'
			. '<h4 class="yn-account__carte-titre"><a href="' . esc_url( $serie['url'] ) . '">' . esc_html( $serie['titre'] ) . '</a></h4>'
			. '<p class="yn-muted yn-account__carte-detail">' . esc_html( $detail ) . '</p>'
			. '<span class="yn-bar" aria-hidden="true"><span style="--v:' . esc_attr( (string) $part ) . '%"></span></span>'
			. '<p class="yn-account__carte-detail">' . $etat . '</p>'
			. '</div></article>';
	}
	$html .= '</div>';
	$html .= '<p class="yn-muted yn-account__aide">' . esc_html__( 'Un chapitre compte comme lu quand vous l’avez parcouru jusqu’à 90 % ou que vous êtes passé au suivant. Temps estimé à partir de la longueur des chapitres. « À jour » : vous avez lu tous les chapitres publiés de la série.', 'yume-core' ) . '</p>';
	return $html . '</section>';
}

/**
 * Favoris et alertes par œuvre.
 *
 * @param int   $user_id Membre.
 * @param array $favoris Favoris (œuvres publiées).
 */
function section_favoris( int $user_id, array $favoris ): string {
	$html = debut_section( 'yn-favoris', __( 'Favoris et alertes', 'yume-core' ), __( 'Vous recevez un e-mail à chaque sortie selon le réglage de chaque œuvre', 'yume-core' ) );
	if ( ! $favoris ) {
		$bibliotheque = function_exists( 'yume_url_page' ) ? yume_url_page( 'bibliotheque' ) : home_url( '/' );
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Aucun favori pour l’instant : ajoutez une œuvre avec le bouton « Favori » de sa fiche.', 'yume-core' ) . ' <a href="' . esc_url( $bibliotheque ) . '">' . esc_html__( 'Parcourir la bibliothèque', 'yume-core' ) . '</a></p></section>';
	}
	$libelles = libelles_frequences();
	$action   = esc_url( admin_url( 'admin-post.php' ) );
	$html    .= '<div class="yn-card yn-account__tableau-conteneur"><table class="yn-account__tableau" data-yn-favoris>'
		. '<caption class="yn-visually-hidden">' . esc_html__( 'Vos œuvres favorites et leurs alertes', 'yume-core' ) . '</caption>'
		. '<thead><tr class="yn-label">'
		. '<th scope="col"><span class="yn-visually-hidden">' . esc_html__( 'Couverture', 'yume-core' ) . '</span></th>'
		. '<th scope="col">' . esc_html__( 'Œuvre', 'yume-core' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Où en est la traduction', 'yume-core' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Alerte', 'yume-core' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Ma note', 'yume-core' ) . '</th>'
		. '<th scope="col"><span class="yn-visually-hidden">' . esc_html__( 'Actions', 'yume-core' ) . '</span></th>'
		. '</tr></thead><tbody>';
	foreach ( $favoris as $ligne ) {
		$oeuvre_id = $ligne['oeuvre_id'];
		$titre     = wp_strip_all_tags( get_the_title( $oeuvre_id ) );
		$url       = (string) get_permalink( $oeuvre_id );
		$ma_note   = note( $user_id, $oeuvre_id );
		$id_select = 'yn-alerte-' . $oeuvre_id;
		$html     .= '<tr data-yn-oeuvre="' . esc_attr( (string) $oeuvre_id ) . '">'
			. '<td class="yn-account__cellule-couverture">' . mini_couverture( $oeuvre_id, initiales( $titre ), 'yn-account__couverture--petite' ) . '</td>'
			. '<th scope="row" class="yn-account__cellule-oeuvre"><a href="' . esc_url( $url ) . '">' . esc_html( $titre ) . '</a>'
			. '<span class="yn-muted">' . esc_html( details_oeuvre( $oeuvre_id ) ) . '</span></th>'
			. '<td class="yn-account__cellule-etat"><span class="yn-account__etiquette yn-label" aria-hidden="true">' . esc_html__( 'Traduction', 'yume-core' ) . '</span>' . etat_traduction( $oeuvre_id ) . '</td>'
			. '<td class="yn-account__cellule-alerte"><form method="post" action="' . $action . '" data-yn-form="alerte">'
			. champs_action( 'yume_social_alerte', $oeuvre_id, 'yn-favoris' )
			/* translators: %s : titre de l'œuvre. */
			. '<label class="yn-visually-hidden" for="' . esc_attr( $id_select ) . '">' . esc_html( sprintf( __( 'Alerte pour %s', 'yume-core' ), $titre ) ) . '</label>'
			. '<select id="' . esc_attr( $id_select ) . '" name="yn_frequence" data-yn-frequence>';
		foreach ( $libelles as $cle => $libelle ) {
			$html .= '<option value="' . esc_attr( $cle ) . '"' . selected( $ligne['frequence'], $cle, false ) . '>' . esc_html( $libelle ) . '</option>';
		}
		$html .= '</select> <button type="submit" class="yn-btn yn-btn--sm yn-sans-js">' . esc_html__( 'OK', 'yume-core' ) . '</button></form></td>'
			. '<td class="yn-account__cellule-note">' . ( $ma_note ? etoiles( $ma_note ) : '<a href="' . esc_url( $url . '#yn-oeuvre-actions' ) . '">' . esc_html__( 'Noter', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html( $titre ) . '</span></a>' ) . '</td>'
			. '<td class="yn-account__cellule-actions"><form method="post" action="' . $action . '" data-yn-form="retirer">'
			. champs_action( 'yume_social_favori', $oeuvre_id, 'yn-favoris' )
			. '<input type="hidden" name="yn_faire" value="retirer">'
			. '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Retirer', 'yume-core' )
			/* translators: %s : titre de l'œuvre. */
			. '<span class="yn-visually-hidden"> ' . esc_html( sprintf( __( '%s de mes favoris', 'yume-core' ), $titre ) ) . '</span></button></form></td>'
			. '</tr>';
	}
	$html .= '</tbody></table></div>';
	return $html . '</section>';
}

/**
 * Notes et commentaires.
 *
 * @param int $user_id Membre.
 */
function section_notes( int $user_id ): string {
	$html  = debut_section( 'yn-notes', __( 'Mes notes et commentaires', 'yume-core' ) );
	$html .= '<div class="yn-account__deux">';

	$html .= '<div class="yn-card yn-account__bloc"><h3 class="yn-account__sous-titre">' . esc_html__( 'Mes notes', 'yume-core' ) . '</h3>';
	$notes = array_values(
		array_filter(
			notes_utilisateur( $user_id ),
			static function ( array $ligne ): bool {
				return oeuvre_publiee( $ligne['oeuvre_id'] );
			}
		)
	);
	if ( $notes ) {
		$html .= '<ul class="yn-account__liste">';
		foreach ( $notes as $ligne ) {
			$html .= '<li><a href="' . esc_url( (string) get_permalink( $ligne['oeuvre_id'] ) . '#yn-oeuvre-actions' ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $ligne['oeuvre_id'] ) ) ) . '</a> '
				. etoiles( $ligne['note'] )
				. ' <span class="yn-muted">' . esc_html( wp_date( 'j M Y', (int) strtotime( $ligne['updated_at'] . ' UTC' ) ) ) . '</span></li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-account__vide">' . esc_html__( 'Vous n’avez encore noté aucune œuvre.', 'yume-core' ) . '</p>';
	}
	$html .= '</div>';

	$html        .= '<div class="yn-card yn-account__bloc"><h3 class="yn-account__sous-titre">' . esc_html__( 'Mes derniers commentaires', 'yume-core' ) . '</h3>';
	$commentaires = get_comments(
		array(
			'user_id' => $user_id,
			'status'  => array( 'approve', 'hold' ),
			'number'  => 10,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		)
	);
	if ( $commentaires ) {
		$html .= '<ul class="yn-account__liste yn-account__commentaires">';
		foreach ( $commentaires as $commentaire ) {
			$en_attente = '0' === (string) $commentaire->comment_approved;
			$html      .= '<li><p class="yn-account__commentaire-sur">'
				/* translators: %s : titre de la page commentée. */
				. esc_html( sprintf( __( 'Sur « %s »', 'yume-core' ), wp_strip_all_tags( get_the_title( (int) $commentaire->comment_post_ID ) ) ) )
				/* translators: %s : durée écoulée. */
				. ' <span class="yn-muted">· ' . esc_html( sprintf( __( 'il y a %s', 'yume-core' ), human_time_diff( (int) strtotime( $commentaire->comment_date_gmt . ' UTC' ) ) ) ) . '</span>'
				. ( $en_attente ? ' ' . pastille( 'warn', '…', __( 'en attente de modération', 'yume-core' ) ) : '' ) . '</p>'
				. '<p class="yn-account__commentaire-texte">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $commentaire->comment_content ), 30, '…' ) ) . '</p>'
				. ( $en_attente ? '' : '<a href="' . esc_url( (string) get_comment_link( $commentaire ) ) . '">' . esc_html__( 'Voir le commentaire', 'yume-core' ) . '</a>' )
				. '</li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-account__vide">' . esc_html__( 'Aucun commentaire pour l’instant.', 'yume-core' ) . '</p>';
	}
	$html .= '</div></div>';
	return $html . '</section>';
}

/**
 * Réglages de lecture : résumé et formulaire (sans JavaScript).
 *
 * @param int $user_id Membre.
 */
function section_reglages( int $user_id ): string {
	$html = debut_section( 'yn-reglages', __( 'Réglages de lecture', 'yume-core' ), __( 'Synchronisés entre vos appareils', 'yume-core' ) );
	if ( ! function_exists( '\Yume\Core\Reader\reglages_utilisateur' ) ) {
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Le lecteur en ligne n’est pas disponible.', 'yume-core' ) . '</p></section>';
	}
	$reglages = \Yume\Core\Reader\reglages_utilisateur( $user_id );
	$bornes   = \Yume\Core\Reader\bornes_reglages();
	$derniere = position_membre( 0 );
	$lecteur  = $derniere ? $derniere['url'] : ( function_exists( 'yume_url_page' ) ? yume_url_page( 'bibliotheque' ) : home_url( '/' ) );

	$html .= '<div class="yn-account__deux">';
	$html .= '<div class="yn-card yn-account__bloc"><p class="yn-label">' . esc_html__( 'Réglages de lecture synchronisés', 'yume-core' ) . '</p><dl class="yn-account__resume">';
	foreach ( \Yume\Core\Reader\resume_reglages( $reglages ) as $libelle => $valeur ) {
		$html .= '<dt class="yn-muted">' . esc_html( $libelle ) . '</dt><dd>' . esc_html( $valeur ) . '</dd>';
	}
	$html .= '</dl><a class="yn-account__lien" href="' . esc_url( $lecteur ) . '">' . esc_html__( 'Modifier depuis le lecteur', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></div>';

	$html .= '<form class="yn-card yn-account__bloc yn-account__formulaire" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="yume_compte_reglages">'
		. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
		. champ_nonce( 'yume_compte_reglages' )
		. '<p class="yn-label">' . esc_html__( 'Modifier ici', 'yume-core' ) . '</p>'
		. '<div class="yn-account__champs">';
	$html .= '<p class="yn-account__champ"><label for="yn-r-font">' . esc_html__( 'Police', 'yume-core' ) . '</label><select id="yn-r-font" name="yn_font">';
	foreach ( \Yume\Core\Reader\polices() as $slug => $police ) {
		$html .= '<option value="' . esc_attr( $slug ) . '"' . selected( $reglages['font'], $slug, false ) . '>' . esc_html( $police['label'] ) . '</option>';
	}
	$html .= '</select></p>';
	$html .= '<p class="yn-account__champ"><label for="yn-r-theme">' . esc_html__( 'Thème', 'yume-core' ) . '</label><select id="yn-r-theme" name="yn_theme">';
	foreach ( \Yume\Core\Reader\libelles_themes() as $slug => $libelle ) {
		$html .= '<option value="' . esc_attr( $slug ) . '"' . selected( $reglages['theme'], $slug, false ) . '>' . esc_html( $libelle ) . '</option>';
	}
	$html  .= '</select></p>';
	$champs = array(
		'size'    => array( __( 'Taille (px)', 'yume-core' ), $bornes['size'][0], $bornes['size'][1], 1, $reglages['size'] ),
		'lh'      => array( __( 'Interligne', 'yume-core' ), $bornes['lh'][0], $bornes['lh'][1], 0.05, $reglages['lh'] ),
		'width'   => array( __( 'Largeur (caractères)', 'yume-core' ), $bornes['width'][0], $bornes['width'][1], 1, $reglages['width'] ),
		'bgalpha' => array( __( 'Opacité du fond (%)', 'yume-core' ), $bornes['bgAlpha'][0] * 100, $bornes['bgAlpha'][1] * 100, 1, (int) round( $reglages['bgAlpha'] * 100 ) ),
	);
	foreach ( $champs as $cle => $champ ) {
		$html .= '<p class="yn-account__champ"><label for="yn-r-' . esc_attr( $cle ) . '">' . esc_html( $champ[0] ) . '</label>'
			. '<input type="number" id="yn-r-' . esc_attr( $cle ) . '" name="yn_' . esc_attr( $cle ) . '" min="' . esc_attr( (string) $champ[1] ) . '" max="' . esc_attr( (string) $champ[2] ) . '" step="' . esc_attr( (string) $champ[3] ) . '" value="' . esc_attr( (string) $champ[4] ) . '" inputmode="decimal"></p>';
	}
	$html .= '</div><div class="yn-account__boutons">'
		. '<button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button>'
		. '<button type="submit" class="yn-btn" name="yn_reinitialiser" value="1">' . esc_html__( 'Réinitialiser par défaut', 'yume-core' ) . '</button>'
		. '</div></form></div>';
	return $html . '</section>';
}

/**
 * Préférences d'alerte globales.
 *
 * @param int $user_id Membre.
 */
function section_alertes( int $user_id ): string {
	$html  = debut_section( 'yn-alertes', __( 'Alertes', 'yume-core' ), __( 'E-mails envoyés par Yume Novel', 'yume-core' ) );
	$prefs = preferences_alertes( $user_id );
	if ( ! emails_actifs() ) {
		$html .= '<p class="yn-avis yn-avis--info">' . esc_html__( 'Les e-mails aux lecteurs sont suspendus pour le moment par l’équipe : vos préférences seront appliquées à leur reprise.', 'yume-core' ) . '</p>';
	}
	$options = array(
		'sorties'      => array( __( 'E-mail à chaque sortie d’un favori', 'yume-core' ), __( 'Pour les œuvres dont l’alerte est « Immédiate ».', 'yume-core' ) ),
		'hebdo'        => array( __( 'Récapitulatif hebdomadaire (dimanche)', 'yume-core' ), __( 'Toutes les sorties de vos favoris de la semaine, en un seul e-mail. Les œuvres en alerte « Hebdomadaire » y figurent toujours.', 'yume-core' ) ),
		'commentaires' => array( __( 'Réponses à mes commentaires', 'yume-core' ), __( 'Quand quelqu’un répond à l’un de vos commentaires.', 'yume-core' ) ),
	);
	$html   .= '<form class="yn-card yn-account__bloc yn-account__alertes" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="yume_compte_alertes">'
		. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
		. champ_nonce( 'yume_compte_alertes' )
		. '<p class="yn-label">' . esc_html__( 'Alertes', 'yume-core' ) . '</p><ul class="yn-account__interrupteurs">';
	foreach ( $options as $cle => $option ) {
		$id    = 'yn-pref-' . $cle;
		$html .= '<li class="yn-account__interrupteur"><label for="' . esc_attr( $id ) . '"><span class="yn-account__interrupteur-texte">' . esc_html( $option[0] )
			. '<span class="yn-muted" id="' . esc_attr( $id . '-aide' ) . '">' . esc_html( $option[1] ) . '</span></span>'
			. '<input type="checkbox" role="switch" class="yn-interrupteur" id="' . esc_attr( $id ) . '" name="yn_' . esc_attr( $cle ) . '" value="1" aria-describedby="' . esc_attr( $id . '-aide' ) . '"' . checked( $prefs[ $cle ], true, false ) . '></label></li>';
	}
	if ( push_actif() ) {
		// Notifications navigateur (AMEL-06) : activées appareil par appareil (push.php).
		$html .= '<li class="yn-account__interrupteur"><span class="yn-account__interrupteur-texte">' . esc_html__( 'Notifications navigateur', 'yume-core' )
			. '<span class="yn-muted">' . esc_html__( 'À activer sur chaque appareil dans la rubrique', 'yume-core' ) . ' <a href="#yn-notifications">' . esc_html__( 'Notifications', 'yume-core' ) . '</a>.</span></span></li>';
	}
	$html .= '</ul><div class="yn-account__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer les alertes', 'yume-core' ) . '</button></div></form>';
	return $html . '</section>';
}

/**
 * Profil et sécurité.
 *
 * @param \WP_User $user Membre.
 */
function section_profil( \WP_User $user ): string {
	$html    = debut_section( 'yn-profil', __( 'Profil et sécurité', 'yume-core' ) );
	$attente = get_user_meta( $user->ID, META_EMAIL_ATTENTE, true );
	$html   .= '<form class="yn-card yn-account__bloc yn-account__formulaire" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="yume_compte_profil">'
		. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
		. champ_nonce( 'yume_compte_profil' )
		. '<div class="yn-account__champs">'
		. '<p class="yn-account__champ"><label for="yn-pseudo">' . esc_html__( 'Pseudo', 'yume-core' ) . '</label>'
		. '<input type="text" id="yn-pseudo" name="yn_pseudo" value="' . esc_attr( $user->display_name ) . '" minlength="2" maxlength="40" autocomplete="nickname" aria-describedby="yn-pseudo-aide">'
		/* translators: %s : identifiant de connexion. */
		. '<span class="yn-muted yn-account__note" id="yn-pseudo-aide">' . esc_html( sprintf( __( 'Affiché avec vos commentaires. Identifiant de connexion : %s (non modifiable).', 'yume-core' ), $user->user_login ) ) . '</span></p>'
		. '<p class="yn-account__champ"><label for="yn-email">' . esc_html__( 'Adresse e-mail', 'yume-core' ) . '</label>'
		. '<input type="email" id="yn-email" name="yn_email" value="' . esc_attr( $user->user_email ) . '" autocomplete="email" aria-describedby="yn-email-aide">'
		. '<span class="yn-muted yn-account__note" id="yn-email-aide">'
		. ( is_array( $attente ) && ! empty( $attente['email'] ) && (int) ( $attente['expire'] ?? 0 ) >= time()
			/* translators: %s : nouvelle adresse. */
			? esc_html( sprintf( __( 'Changement en attente de confirmation : %s (lien envoyé à cette adresse).', 'yume-core' ), $attente['email'] ) )
			: esc_html__( 'Un lien de confirmation est envoyé à la nouvelle adresse.', 'yume-core' ) )
		. '</span></p>'
		. '</div>'
		. '<fieldset class="yn-account__groupe"><legend>' . esc_html__( 'Changer de mot de passe', 'yume-core' ) . '</legend><div class="yn-account__champs">'
		. '<p class="yn-account__champ"><label for="yn-mdp-nouveau">' . esc_html__( 'Nouveau mot de passe', 'yume-core' ) . '</label>'
		. '<input type="password" id="yn-mdp-nouveau" name="yn_mdp_nouveau" minlength="8" autocomplete="new-password" aria-describedby="yn-mdp-aide">'
		. '<span class="yn-muted yn-account__note" id="yn-mdp-aide">' . esc_html__( '8 caractères au minimum.', 'yume-core' ) . '</span></p>'
		. '<p class="yn-account__champ"><label for="yn-mdp-confirmation">' . esc_html__( 'Confirmer le nouveau mot de passe', 'yume-core' ) . '</label>'
		. '<input type="password" id="yn-mdp-confirmation" name="yn_mdp_confirmation" minlength="8" autocomplete="new-password"></p>'
		. '</div></fieldset>'
		. '<p class="yn-account__champ"><label for="yn-mdp-actuel">' . esc_html__( 'Mot de passe actuel', 'yume-core' ) . '</label>'
		. '<input type="password" id="yn-mdp-actuel" name="yn_mdp_actuel" autocomplete="current-password" aria-describedby="yn-mdp-actuel-aide">'
		. '<span class="yn-muted yn-account__note" id="yn-mdp-actuel-aide">' . esc_html__( 'Obligatoire pour changer d’adresse e-mail ou de mot de passe.', 'yume-core' ) . '</span></p>'
		. '<div class="yn-account__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button></div>'
		. '</form>';
	return $html . '</section>';
}

/**
 * Données et suppression.
 *
 * @param \WP_User $user Membre.
 */
function section_donnees( \WP_User $user ): string {
	$html   = debut_section( 'yn-donnees', __( 'Données et suppression', 'yume-core' ) );
	$export = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( REST_NS . '/moi/export' ) );
	$html  .= '<div class="yn-account__deux">'
		. '<div class="yn-card yn-account__bloc"><h3 class="yn-account__sous-titre">' . esc_html__( 'Vos données', 'yume-core' ) . '</h3>'
		. '<p>' . esc_html__( 'Nous conservons uniquement votre pseudo, votre adresse e-mail, vos favoris et alertes, vos listes de lecture, vos notifications, vos notes, vos positions de lecture, vos réglages de lecture et vos commentaires.', 'yume-core' ) . '</p>'
		. '<p><a class="yn-btn" href="' . esc_url( $export ) . '" download>' . esc_html__( 'Télécharger mes données (JSON)', 'yume-core' ) . '</a></p></div>';

	$html .= '<div class="yn-card yn-account__bloc yn-account__danger"><h3 class="yn-account__sous-titre">' . esc_html__( 'Supprimer mon compte', 'yume-core' ) . '</h3>';
	if ( peut_supprimer_compte( (int) $user->ID ) ) {
		$html .= '<p>' . esc_html__( 'La suppression est définitive : vos favoris, listes, notifications, notes, positions, réglages et préférences sont effacés ; vos commentaires restent publiés mais anonymisés.', 'yume-core' ) . '</p>'
			. '<form class="yn-account__formulaire" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="yume_compte_supprimer">'
			. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
			. champ_nonce( 'yume_compte_supprimer' )
			. '<p class="yn-account__champ"><label for="yn-suppression-confirmation">' . esc_html__( 'Saisissez SUPPRIMER pour confirmer', 'yume-core' ) . '</label>'
			. '<input type="text" id="yn-suppression-confirmation" name="yn_confirmation" required autocomplete="off" spellcheck="false" pattern="[Ss][Uu][Pp][Pp][Rr][Ii][Mm][Ee][Rr]"></p>'
			. '<p class="yn-account__champ"><label for="yn-suppression-mdp">' . esc_html__( 'Mot de passe actuel', 'yume-core' ) . '</label>'
			. '<input type="password" id="yn-suppression-mdp" name="yn_mdp" required autocomplete="current-password"></p>'
			. '<div class="yn-account__boutons"><button type="submit" class="yn-btn yn-account__bouton-danger">' . esc_html__( 'Supprimer définitivement mon compte', 'yume-core' ) . '</button></div>'
			. '</form>';
	} else {
		$html .= '<p>' . esc_html__( 'Les comptes de l’équipe et des administrateurs ne peuvent pas être supprimés depuis cette page. Adressez-vous à un gérant.', 'yume-core' ) . '</p>';
	}
	$html .= '</div></div>';
	return $html . '</section>';
}

/*
 * -----------------------------------------------------------------------------
 * Visiteur : connexion, mot de passe oublié, inscription
 * -----------------------------------------------------------------------------
 */

/**
 * Page d'accès d'un visiteur.
 */
function compte_visiteur(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- adresse de retour validée.
	$demandee = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
	$demandee = '' !== $demandee ? wp_validate_redirect( $demandee, '' ) : '';
	$action   = esc_url( admin_url( 'admin-post.php' ) );
	$ici      = url_courante();

	$html  = '<div ' . attributs_racine(
		'yn-account yn-account--acces',
		array(
			'id'             => 'yn-compte',
			'data-yn-compte' => '{}',
		)
	) . '>';
	$html .= '<div class="yn-account__messages">' . confirmation_desabonnement() . html_messages( messages_courants() ) . '</div>';
	$html .= '<div class="yn-account__acces">';

	// Connexion.
	$html .= '<section class="yn-card yn-account__bloc" id="yn-bloc-connexion" aria-labelledby="yn-connexion-titre" tabindex="-1">'
		. '<h2 class="yn-account__titre" id="yn-connexion-titre">' . esc_html__( 'Se connecter', 'yume-core' ) . '</h2>'
		. formulaire_connexion( $demandee );
	$html .= '<details class="yn-account__oubli" id="yn-oubli"><summary>' . esc_html__( 'Mot de passe oublié ?', 'yume-core' ) . '</summary>'
		. '<form class="yn-account__formulaire" method="post" action="' . $action . '">'
		. '<input type="hidden" name="action" value="yume_oubli">'
		. '<input type="hidden" name="yn_retour" value="' . esc_url( $ici ) . '">'
		. champ_nonce( 'yume_oubli' )
		. '<p class="yn-account__champ"><label for="yn-oubli-identifiant">' . esc_html__( 'Pseudo ou adresse e-mail', 'yume-core' ) . '</label>'
		. '<input type="text" id="yn-oubli-identifiant" name="yn_identifiant" required autocomplete="username"></p>'
		. '<div class="yn-account__boutons"><button type="submit" class="yn-btn">' . esc_html__( 'Recevoir un lien de réinitialisation', 'yume-core' ) . '</button></div>'
		. '</form></details>'
		. lien_connexion_administrateur( $demandee )
		. '</section>';

	// Inscription.
	$html .= '<section class="yn-card yn-account__bloc" id="yn-inscription" aria-labelledby="yn-inscription-titre" tabindex="-1">'
		. '<h2 class="yn-account__titre" id="yn-inscription-titre">' . esc_html__( 'Créer un compte', 'yume-core' ) . '</h2>'
		. '<p class="yn-muted">' . esc_html__( 'Favoris, notes, reprise de lecture sur tous vos appareils et alertes de sortie. Gratuit et sans publicité.', 'yume-core' ) . '</p>';
	if ( inscriptions_ouvertes() ) {
		$mentions = function_exists( 'yume_theme_lien' ) ? yume_theme_lien( 'mentions' ) : home_url( '/mentions-legales/' );
		$html    .= '<form class="yn-account__formulaire" method="post" action="' . $action . '">'
			. '<input type="hidden" name="action" value="yume_inscription">'
			. '<input type="hidden" name="yn_retour" value="' . esc_url( $ici ) . '">'
			. '<input type="hidden" name="yn_jeton" value="' . esc_attr( jeton_formulaire() ) . '">'
			. champ_nonce( 'yume_inscription' )
			. '<p class="yn-account__champ"><label for="yn-inscription-pseudo">' . esc_html__( 'Pseudo', 'yume-core' ) . '</label>'
			. '<input type="text" id="yn-inscription-pseudo" name="yn_pseudo" required minlength="3" maxlength="40" autocomplete="username" aria-describedby="yn-inscription-pseudo-aide">'
			. '<span class="yn-muted yn-account__note" id="yn-inscription-pseudo-aide">' . esc_html__( '3 à 40 caractères : lettres, chiffres, espaces, points, tirets et tirets bas.', 'yume-core' ) . '</span></p>'
			. '<p class="yn-account__champ"><label for="yn-inscription-email">' . esc_html__( 'Adresse e-mail', 'yume-core' ) . '</label>'
			. '<input type="email" id="yn-inscription-email" name="yn_email" required autocomplete="email"></p>'
			. '<div class="yn-account__pot" aria-hidden="true"><label for="yn-site-web">' . esc_html__( 'Site web (laisser vide)', 'yume-core' ) . '</label>'
			. '<input type="text" id="yn-site-web" name="yn_site_web" value="" tabindex="-1" autocomplete="off"></div>'
			. '<div class="yn-account__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Créer mon compte', 'yume-core' ) . '</button></div>'
			. '<p class="yn-muted yn-account__note">' . esc_html__( 'Vous recevrez un e-mail pour choisir votre mot de passe. Nous ne gardons que votre pseudo et votre adresse e-mail.', 'yume-core' )
			. ' <a href="' . esc_url( $mentions ) . '">' . esc_html__( 'Mentions légales', 'yume-core' ) . '</a></p>'
			. '</form>';
	} else {
		$html .= '<p class="yn-avis yn-avis--info">' . esc_html__( 'Les inscriptions sont fermées pour le moment.', 'yume-core' ) . '</p>';
	}
	$html .= '</section></div></div>';
	return $html;
}
