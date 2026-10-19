<?php
/**
 * Bloc yume/recommandations (fiche d'une œuvre) et cartes partagées avec la rubrique « Pour
 * vous » de Mon compte (maquettes Decouverte.dc.html et Compte.dc.html).
 *
 * Cache de page (§0) : pour un visiteur, le HTML ne dépend que de l'œuvre et des données
 * agrégées (aucun identifiant aléatoire, aucune donnée personnelle) ; pour un membre connecté,
 * page jamais servie depuis le cache, ses favoris et lectures sont retirés côté serveur.
 * Couvertures et pastilles : composants de la bibliothèque (Library\couverture(), .yn-chip),
 * couleurs : variables du thème (§15).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Recommandations;

defined( 'ABSPATH' ) || exit;

/** Dossier du bloc. */
const DOSSIER_BLOC = __DIR__ . '/blocks/recommandations';

/** Nom du bloc. */
const NOM_BLOC = 'yume/recommandations';

/**
 * Enregistre le bloc (yume_register_dynamic_block, comme les autres blocs du plugin) et
 * versionne sa feuille de style (version de l'extension et date du fichier).
 */
function enregistrer_bloc(): void {
	if ( ! function_exists( 'yume_register_dynamic_block' ) ) {
		return;
	}
	$type = yume_register_dynamic_block( DOSSIER_BLOC );
	if ( ! $type instanceof \WP_Block_Type || ! is_readable( DOSSIER_BLOC . '/style.css' ) ) {
		return;
	}
	$version = ( defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '2' ) . '.' . (string) filemtime( DOSSIER_BLOC . '/style.css' );
	foreach ( (array) $type->style_handles as $poignee ) {
		if ( 'yume-bibliotheque' !== $poignee && isset( wp_styles()->registered[ $poignee ] ) ) {
			wp_styles()->registered[ $poignee ]->ver = $version;
		}
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc' );

/**
 * Met en file les feuilles du bloc (rubrique « Pour vous » de Mon compte, rendue hors du bloc).
 */
function charger_styles(): void {
	$type = \WP_Block_Type_Registry::get_instance()->get_registered( NOM_BLOC );
	if ( $type instanceof \WP_Block_Type ) {
		foreach ( (array) $type->style_handles as $poignee ) {
			wp_enqueue_style( $poignee );
		}
	}
}

/**
 * Attributs HTML de la racine du bloc (classes de l'éditeur comprises pendant le rendu d'un bloc).
 *
 * @param string               $classe Classe racine (§10).
 * @param array<string,string> $extra  Attributs supplémentaires.
 */
function attributs_racine( string $classe, array $extra = array() ): string {
	$extra['class'] = trim( $classe . ' ' . ( $extra['class'] ?? '' ) );
	if ( class_exists( '\WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render ) ) {
		return get_block_wrapper_attributes( $extra );
	}
	$html = '';
	foreach ( $extra as $nom => $valeur ) {
		$html .= ' ' . esc_attr( (string) $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return ltrim( $html );
}

/**
 * Rendu en cours dans l'éditeur (aperçu serveur) ?
 */
function apercu_editeur(): bool {
	return function_exists( '\Yume\Core\Library\apercu_editeur' ) && \Yume\Core\Library\apercu_editeur();
}

/**
 * Couverture d'une carte (composant de la bibliothèque, repli minimal sans ce module).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $titre     Titre (couverture de substitution).
 */
function couverture( int $oeuvre_id, string $titre ): string {
	$image = couverture_id( $oeuvre_id );
	if ( function_exists( '\Yume\Core\Library\couverture' ) ) {
		// Texte alternatif vide : le titre de l'œuvre suit dans le même lien.
		return \Yume\Core\Library\couverture(
			$image,
			array(
				'texte' => $titre,
				'sizes' => '(min-width: 600px) 200px, 50vw',
			)
		);
	}
	$img = $image && function_exists( 'yume_image_couverture' ) ? yume_image_couverture(
		$image,
		'yume-couverture',
		array(
			'alt'     => '',
			'loading' => 'lazy',
		)
	) : '';
	return '<span class="yn-cover">' . ( '' !== $img ? $img : '<span class="yn-cover__texte" aria-hidden="true">' . esc_html( $titre ) . '</span>' ) . '</span>';
}

/**
 * Cartes de recommandations (bloc de la fiche et rubrique « Pour vous »).
 *
 * @param array $recommandations Recommandations (recommandations_oeuvre() ou recommandations_membre()).
 * @param array $options         niveau (balise du titre des cartes, h3 par défaut), raisons
 *                               (« parce que vous suivez {X} » d'après parce_que, faux par défaut),
 *                               classe (classes supplémentaires de la liste).
 * @return string HTML échappé ('' sans recommandation).
 */
function rendu_cartes( array $recommandations, array $options ): string {
	$o      = wp_parse_args(
		$options,
		array(
			'niveau'  => 'h3',
			'raisons' => false,
			'classe'  => '',
		)
	);
	$niveau = in_array( $o['niveau'], array( 'h2', 'h3', 'h4', 'p' ), true ) ? (string) $o['niveau'] : 'h3';
	$cartes = '';
	foreach ( $recommandations as $reco ) {
		if ( ! is_array( $reco ) || empty( $reco['id'] ) ) {
			continue;
		}
		$id     = (int) $reco['id'];
		$titre  = (string) ( $reco['titre'] ?? '' );
		$url    = (string) ( $reco['url'] ?? '' );
		$carte  = '<li class="yn-recommandations__item">';
		$carte .= '<a class="yn-recommandations__lien" href="' . esc_url( $url ) . '">' . couverture( $id, $titre )
			. '<' . $niveau . ' class="yn-recommandations__nom">' . esc_html( $titre ) . '</' . $niveau . '></a>';
		if ( '' !== (string) ( $reco['type'] ?? '' ) ) {
			$carte .= '<p class="yn-recommandations__type yn-muted">' . esc_html( (string) $reco['type'] ) . '</p>';
		}
		$genres = array_filter( array_map( 'strval', (array) ( $reco['genres_communs'] ?? array() ) ), 'strlen' );
		if ( $genres ) {
			$carte .= '<ul class="yn-recommandations__genres" aria-label="' . esc_attr__( 'Genres en commun', 'yume-core' ) . '">';
			foreach ( $genres as $genre ) {
				$carte .= '<li><span class="yn-chip">' . esc_html( $genre ) . '</span></li>';
			}
			$carte .= '</ul>';
		}
		$cofavoris = isset( $reco['cofavoris'] ) ? (int) $reco['cofavoris'] : 0;
		if ( $cofavoris >= SEUIL_COFAVORIS ) {
			$carte .= '<p class="yn-recommandations__cofavoris">' . esc_html(
				/* translators: %s : nombre de lecteurs (au moins 3). */
				sprintf( _n( '%s lecteur la suit aussi', '%s lecteurs la suivent aussi', $cofavoris, 'yume-core' ), number_format_i18n( $cofavoris ) )
			) . '</p>';
		}
		$sources = array_values( array_filter( array_map( 'intval', (array) ( $reco['parce_que'] ?? array() ) ) ) );
		if ( $o['raisons'] && $sources ) {
			$carte .= '<p class="yn-recommandations__raison yn-muted">' . esc_html(
				/* translators: %s : titre d'une œuvre suivie par le membre. */
				sprintf( __( 'parce que vous suivez %s', 'yume-core' ), titre( $sources[0] ) )
			) . '</p>';
		}
		$cartes .= $carte . '</li>';
	}
	if ( '' === $cartes ) {
		return '';
	}
	return '<ul class="' . esc_attr( trim( 'yn-recommandations__grille ' . (string) $o['classe'] ) ) . '">' . $cartes . '</ul>';
}

/**
 * Œuvre de la fiche affichée : postId du contexte du bloc, sinon objet de la requête.
 *
 * @param \WP_Block|null $bloc Instance du bloc.
 */
function oeuvre_contexte( $bloc = null ): int {
	$candidats = array();
	if ( $bloc instanceof \WP_Block && ! empty( $bloc->context['postId'] ) ) {
		$candidats[] = (int) $bloc->context['postId'];
	}
	$candidats[] = (int) get_queried_object_id();
	foreach ( $candidats as $id ) {
		if ( $id > 0 && TYPE_OEUVRE === get_post_type( $id ) ) {
			return $id;
		}
	}
	return 0;
}

/**
 * Message de l'aperçu dans l'éditeur (rien en façade).
 *
 * @param string $texte Message.
 */
function rendu_apercu( string $texte ): string {
	if ( ! apercu_editeur() ) {
		return '';
	}
	return '<div ' . attributs_racine( 'yn-recommandations yn-apercu-editeur' ) . '><p class="yn-muted">' . esc_html( $texte ) . '</p></div>';
}

/**
 * Rendu du bloc yume/recommandations : « Si vous avez aimé {Œuvre} » et ses cartes. Rien hors de
 * la fiche d'une œuvre publiée, ni sans assez de données.
 *
 * @param array          $attributs Attributs (count : 4 par défaut, 1 à 12).
 * @param \WP_Block|null $bloc      Instance du bloc.
 */
function rendu_bloc( array $attributs = array(), $bloc = null ): string {
	$oeuvre_id = oeuvre_contexte( $bloc );
	if ( ! $oeuvre_id ) {
		return rendu_apercu( __( 'Si vous avez aimé… : suggestions affichées sur la fiche d’une œuvre publiée (genres, type et lecteurs en commun).', 'yume-core' ) );
	}
	$nombre = isset( $attributs['count'] ) && is_numeric( $attributs['count'] ) ? (int) $attributs['count'] : 4;
	$membre = is_user_logged_in() ? get_current_user_id() : 0;
	$recos  = recommandations_oeuvre( $oeuvre_id, borner( $nombre ), $membre );
	if ( ! $recos ) {
		return rendu_apercu( __( 'Si vous avez aimé… : pas encore assez de données pour cette œuvre.', 'yume-core' ) );
	}
	$id_titre = 'yn-recommandations-' . $oeuvre_id;
	$html     = '<section ' . attributs_racine( 'yn-recommandations', array( 'aria-labelledby' => $id_titre ) ) . '>';
	$html    .= '<div class="yn-recommandations__tete"><h2 class="yn-recommandations__titre" id="' . esc_attr( $id_titre ) . '">'
		/* translators: %s : titre de l'œuvre. */
		. esc_html( sprintf( __( 'Si vous avez aimé %s', 'yume-core' ), titre( $oeuvre_id ) ) ) . '</h2></div>';
	$html .= rendu_cartes( $recos, array( 'niveau' => 'h3' ) );
	return $html . '</section>';
}

/**
 * Insère yume/recommandations après yume/oeuvre-news dans le modèle « Œuvre »
 * (single-yume_oeuvre) quand il ne le contient pas encore (modèle personnalisé dans l'éditeur de
 * site avant la version 2.5) ; le modèle du thème le contient déjà, les sous-pages (actualités…)
 * ne le reçoivent pas. L'équipe peut le retirer dans l'éditeur de site.
 *
 * @param string[]                               $accroches Blocs à insérer.
 * @param string                                 $position  before, after, first_child, last_child.
 * @param string                                 $ancre     Bloc d'ancrage.
 * @param \WP_Block_Template|\WP_Post|array|null $contexte  Modèle, partie ou composition.
 * @return string[]
 */
function accrocher_au_modele( $accroches, $position, $ancre, $contexte ) {
	$accroches = is_array( $accroches ) ? $accroches : array();
	if ( 'yume/oeuvre-news' !== $ancre || 'after' !== $position || ! $contexte instanceof \WP_Block_Template || 'single-yume_oeuvre' !== $contexte->slug ) {
		return $accroches;
	}
	if ( str_contains( (string) $contexte->content, 'wp:' . NOM_BLOC ) || in_array( NOM_BLOC, $accroches, true ) || ! actives() ) {
		return $accroches;
	}
	$accroches[] = NOM_BLOC;
	return $accroches;
}
add_filter( 'hooked_block_types', __NAMESPACE__ . '\\accrocher_au_modele', 10, 4 );
