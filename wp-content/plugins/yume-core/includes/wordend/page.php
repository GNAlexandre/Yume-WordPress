<?php
/**
 * Page du jeu WordEnd (clé `jeu` de yume_pages, slug `jeu`, créée par « Recréer les pages
 * manquantes ») : bloc dynamique yume/wordend. Le jeu est le projet Yume-WordEnd (Godot, export
 * Web statique servi par GitHub Pages sur https://jeu.yumenovel.fr/) affiché dans une iframe ;
 * WordPress n'exécute rien du jeu (Yume-WordEnd, PLAN.md section 10 et docs/web.md).
 *
 * L'iframe n'est créée qu'au clic sur « Lancer le jeu » (view.js) : la page reste légère, rien
 * du jeu n'est téléchargé à l'affichage. Le rendu est identique pour tous les visiteurs
 * (Batcache). Adresse du jeu : filtre yume_wordend_url.
 *
 * @package Yume\Core
 */

namespace Yume\Core\WordEnd;

defined( 'ABSPATH' ) || exit;

/** Adresse du jeu (export Web de Yume-WordEnd). */
const URL_JEU = 'https://jeu.yumenovel.fr/';

/** Dernière version Windows du jeu (Releases GitHub de Yume-WordEnd). */
const URL_WINDOWS = 'https://github.com/GNAlexandre/Yume-WordEnd/releases/latest';

/** Crédits des images et des assets du jeu. */
const URL_CREDITS = 'https://github.com/GNAlexandre/Yume-WordEnd/blob/main/assets/CREDITS.md';

/**
 * Enregistre le bloc yume/wordend.
 */
function enregistrer_bloc_page(): void {
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/wordend' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc_page' );

/**
 * Adresse du jeu, en https seulement (repli sur l'adresse par défaut).
 */
function url_jeu(): string {
	/**
	 * Adresse de l'export Web de WordEnd affiché par la page du jeu (https).
	 *
	 * @param string $url Par défaut https://jeu.yumenovel.fr/.
	 */
	$url = esc_url_raw( (string) apply_filters( 'yume_wordend_url', URL_JEU ), array( 'https' ) );
	return '' !== $url ? $url : URL_JEU;
}

/**
 * Lien externe ouvert dans un nouvel onglet (annoncé aux lecteurs d'écran).
 *
 * @param string $url     Adresse.
 * @param string $libelle Libellé.
 * @param string $classe  Classes.
 */
function lien_externe_jeu( string $url, string $libelle, string $classe = '' ): string {
	return '<a' . ( '' !== $classe ? ' class="' . esc_attr( $classe ) . '"' : '' ) . ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $libelle )
		. '<span class="yn-visually-hidden"> ' . esc_html__( '(nouvel onglet)', 'yume-core' ) . '</span></a>';
}

/**
 * Rendu de la page du jeu : présentation, affiche puis iframe du jeu, actions (plein écran,
 * nouvel onglet, version Windows), configuration requise, commandes, sauvegarde et crédits.
 */
function rendu_page(): string {
	$classe = 'yn-wordend-page';
	$attrs  = class_exists( '\WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render )
		? get_block_wrapper_attributes( array( 'class' => $classe ) )
		: 'class="' . esc_attr( $classe ) . '"';
	$url    = url_jeu();

	$html = '<div ' . $attrs . ' data-yn-jeu>';

	$html .= '<div class="yn-wordend-page__intro">'
		. '<p class="yn-wordend-page__accroche">' . esc_html__( 'WordEnd, l’action-aventure de Yume Novel : Chtholly et son épée Seniorious face aux Timeres, sur l’île n° 68 de SukaSuka.', 'yume-core' ) . '</p>'
		. '<p>' . esc_html__( 'L’entrepôt des fées, les bois du marais, la veille au bord du Couchant, le port et la colline des étoiles : l’acte 1, « Dans la forêt céleste », suit le volume 1 de notre traduction. Il se joue au clavier, à la souris, à la manette ou au toucher, directement dans cette page.', 'yume-core' ) . '</p>'
		. '</div>';

	// Zone 16:9 : affiche, remplacée par l'iframe du jeu au clic (view.js).
	$html .= '<div class="yn-wordend-page__cadre" data-yn-jeu-cadre data-src="' . esc_url( $url ) . '">'
		. '<div class="yn-wordend-page__affiche" style="background-image: url(' . esc_url( url_asset( 'decor.webp' ) ) . ');">'
		. '<p class="yn-wordend-page__titre-jeu">WordEnd</p>'
		. '<button type="button" class="yn-btn yn-btn--primary yn-wordend-page__lancer" data-yn-jeu-lancer>' . esc_html__( 'Lancer le jeu', 'yume-core' ) . '</button>'
		. '<p class="yn-wordend-page__poids">' . esc_html__( 'Le jeu se télécharge au lancement (plusieurs dizaines de Mo), puis votre navigateur le garde en cache.', 'yume-core' ) . '</p>'
		. '<noscript><p class="yn-wordend-page__poids">' . lien_externe_jeu( $url, __( 'Ouvrir le jeu', 'yume-core' ) ) . '</p></noscript>'
		. '</div></div>';

	$html .= '<div class="yn-wordend-page__actions">'
		. '<button type="button" class="yn-btn" data-yn-jeu-plein-ecran hidden>' . esc_html__( 'Plein écran', 'yume-core' ) . '</button>'
		. lien_externe_jeu( $url, __( 'Ouvrir dans un nouvel onglet', 'yume-core' ), 'yn-btn' )
		. lien_externe_jeu( URL_WINDOWS, __( 'Version Windows', 'yume-core' ), 'yn-btn' )
		. '</div>'
		. '<p class="yn-muted yn-wordend-page__note">' . esc_html__( 'Nécessite un navigateur compatible WebGL 2 (Firefox, Chrome, Edge, Safari 15 et plus). Cliquez dans le jeu pour lui donner le clavier ; Échap quitte le plein écran. Sur iPhone, jouez téléphone à l’horizontale.', 'yume-core' ) . '</p>';

	$html     .= '<section class="yn-wordend-page__section" aria-labelledby="yn-wordend-page-commandes">'
		. '<h2 id="yn-wordend-page-commandes">' . esc_html__( 'Commandes', 'yume-core' ) . '</h2>'
		. '<div class="yn-wordend-page__defile"><table class="yn-wordend-page__tableau"><caption class="yn-visually-hidden">' . esc_html__( 'Commandes du jeu', 'yume-core' ) . '</caption>'
		. '<thead><tr><th scope="col">' . esc_html__( 'Action', 'yume-core' ) . '</th><th scope="col">' . esc_html__( 'Clavier et souris', 'yume-core' ) . '</th><th scope="col">' . esc_html__( 'Manette', 'yume-core' ) . '</th></tr></thead><tbody>';
	$commandes = array(
		array( __( 'Marcher', 'yume-core' ), __( 'ZQSD, WASD ou flèches', 'yume-core' ), __( 'stick gauche', 'yume-core' ) ),
		array( __( 'Courir', 'yume-core' ), __( 'Maj', 'yume-core' ), __( 'L3', 'yume-core' ) ),
		array( __( 'Sauter', 'yume-core' ), __( 'Espace', 'yume-core' ), __( 'A', 'yume-core' ) ),
		array( __( 'Parler, ramasser', 'yume-core' ), __( 'E', 'yume-core' ), __( 'A', 'yume-core' ) ),
		array( __( 'Épée (trois coups enchaînés)', 'yume-core' ), __( 'J ou X', 'yume-core' ), __( 'X', 'yume-core' ) ),
		array( __( 'Charge magique', 'yume-core' ), __( 'K ou C maintenu', 'yume-core' ), __( 'B maintenu', 'yume-core' ) ),
		array( __( 'Verrouiller une cible', 'yume-core' ), __( 'clic molette', 'yume-core' ), __( 'R3', 'yume-core' ) ),
		array( __( 'Sac', 'yume-core' ), __( 'I', 'yume-core' ), __( 'Y', 'yume-core' ) ),
		array( __( 'Journal de quêtes', 'yume-core' ), __( 'Tab ou L', 'yume-core' ), __( 'Select', 'yume-core' ) ),
		array( __( 'Pause', 'yume-core' ), __( 'Échap', 'yume-core' ), __( 'Start', 'yume-core' ) ),
	);
	foreach ( $commandes as $ligne ) {
		$html .= '<tr><th scope="row">' . esc_html( $ligne[0] ) . '</th><td>' . esc_html( $ligne[1] ) . '</td><td>' . esc_html( $ligne[2] ) . '</td></tr>';
	}
	$html .= '</tbody></table></div></section>';

	$html .= '<section class="yn-wordend-page__section" aria-labelledby="yn-wordend-page-sauvegarde">'
		. '<h2 id="yn-wordend-page-sauvegarde">' . esc_html__( 'Sauvegarde', 'yume-core' ) . '</h2>'
		. '<p>' . esc_html__( 'La partie est sauvegardée dans votre navigateur, la même ici et sur jeu.yumenovel.fr. Elle est perdue en navigation privée ou si vous effacez les données du site ; Safari peut l’effacer après sept jours sans visite.', 'yume-core' ) . '</p>'
		. '</section>';

	$html .= '<section class="yn-wordend-page__section" aria-labelledby="yn-wordend-page-credits">'
		. '<h2 id="yn-wordend-page-credits">' . esc_html__( 'Crédits', 'yume-core' ) . '</h2>'
		. '<p>' . esc_html__( 'Hommage non commercial. Chtholly, Seniorious, les Timeres et l’entrepôt des fées viennent de SukaSuka (« Que faites-vous à la fin du monde ? Êtes-vous occupés ? Voulez-vous bien nous sauver ? »), le roman d’Akira Kareno, lu dans la traduction française de Yume Novel.', 'yume-core' ) . ' '
		. lien_externe_jeu( URL_CREDITS, __( 'Crédits des dessins et des assets', 'yume-core' ) ) . '</p>'
		. '</section>';

	return $html . '</div>';
}
