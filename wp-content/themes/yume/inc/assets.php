<?php
/**
 * Chargement des feuilles de style, du script du thème et du script d'initialisation
 * du thème de couleurs (Nuit / Papier / Sépia) avant le premier rendu.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thèmes de couleurs reconnus (valeurs de localStorage['yn.theme'] et de html[data-yn-theme]).
 *
 * @return string[]
 */
function yume_theme_themes_couleurs(): array {
	return array( 'nuit', 'papier', 'sepia' );
}

/**
 * Version d'un fichier du thème pour le cache navigateur (?ver= des feuilles et scripts, ?v= des
 * logos saisonniers) : la version du thème suivie de l'empreinte du fichier, sa date de
 * modification en hexadécimal (« 2.7.0-6720f1c4 »). Toute modification du fichier change donc
 * son URL, en production comme en local, même sans montée de version du thème (correctif de CSS,
 * nouveau logo saisonnier). Si la date du fichier est illisible, la version du thème seule.
 *
 * @param string $relatif Chemin du fichier relatif au dossier du thème.
 * @return string
 */
function yume_theme_version_fichier( string $relatif ): string {
	$version = (string) wp_get_theme( get_template() )->get( 'Version' );
	if ( '' === $version ) {
		$version = '2.0.0';
	}
	$chemin = get_theme_file_path( $relatif );
	if ( is_readable( $chemin ) ) {
		$date = filemtime( $chemin );
		if ( false !== $date ) {
			$version .= '-' . dechex( $date );
		}
	}
	return $version;
}

/**
 * Feuilles de style et script du thème, en façade.
 *
 * Priorité 20 : après les styles globaux (theme.json) pour que les surcharges
 * du thème l'emportent à spécificité égale.
 */
function yume_theme_charger_ressources(): void {
	wp_enqueue_style(
		'yume',
		get_theme_file_uri( 'assets/css/yume.css' ),
		array(),
		yume_theme_version_fichier( 'assets/css/yume.css' )
	);
	wp_enqueue_style(
		'yume-lecteur',
		get_theme_file_uri( 'assets/css/reader.css' ),
		array( 'yume' ),
		yume_theme_version_fichier( 'assets/css/reader.css' )
	);
	wp_enqueue_script(
		'yume',
		get_theme_file_uri( 'assets/js/yume.js' ),
		array(),
		yume_theme_version_fichier( 'assets/js/yume.js' ),
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'yume_theme_charger_ressources', 20 );

/**
 * Script en ligne, tout en haut du <head> : applique le thème mémorisé
 * (localStorage['yn.theme']) avant le premier rendu pour éviter tout flash,
 * et signale que JavaScript est disponible (classe html.yn-js).
 */
function yume_theme_script_initialisation(): void {
	$themes = wp_json_encode( yume_theme_themes_couleurs() );
	$script = '(function(d){var t="nuit",l=' . $themes . ';try{var s=window.localStorage.getItem("yn.theme");if(l.indexOf(s)>-1){t=s;}}catch(e){}d.setAttribute("data-yn-theme",t);d.classList.add("yn-js");}(document.documentElement));';
	wp_print_inline_script_tag( $script, array( 'id' => 'yume-theme-init' ) );
}
add_action( 'wp_head', 'yume_theme_script_initialisation', 0 );

/**
 * Couleur de l'interface du navigateur mobile et préchargement des deux polices
 * utilisées sur toutes les pages (titres 800 et corps 400, sous-ensemble latin).
 *
 * La couleur est celle de la bande du thème Nuit (posé par défaut) ; inc/saisons.php la
 * remplace par celle de la saison active (filtre yume_theme_couleur_navigateur), et
 * assets/js/yume.js la met à jour quand le lecteur change de thème.
 */
function yume_theme_entete_document(): void {
	/**
	 * Filtre la couleur de l'interface du navigateur (meta theme-color) au premier rendu.
	 *
	 * @param string $couleur Couleur hexadécimale (bande du thème Nuit).
	 */
	$couleur = (string) apply_filters( 'yume_theme_couleur_navigateur', '#241740' );
	printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( $couleur ) );
	$polices = array(
		'assets/fonts/outfit/outfit-latin-800-normal.woff2',
		'assets/fonts/nunito-sans/nunito-sans-latin-400-normal.woff2',
	);
	foreach ( $polices as $police ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_theme_file_uri( $police ) )
		);
	}
}
add_action( 'wp_head', 'yume_theme_entete_document', 2 );

/**
 * Thème Nuit par défaut sur la balise <html> : valable sans JavaScript et avant
 * l'exécution du script d'initialisation.
 *
 * @param string $attributs Attributs de langue déjà calculés.
 * @return string
 */
function yume_theme_attributs_html( $attributs ) {
	if ( is_admin() || ! is_string( $attributs ) || str_contains( $attributs, 'data-yn-theme' ) ) {
		return $attributs;
	}
	return $attributs . ' data-yn-theme="nuit"';
}
add_filter( 'language_attributes', 'yume_theme_attributs_html' );

/**
 * Éditeur d'un chapitre : le texte s'affiche avec la typographie du lecteur (Literata,
 * 18 px, interligne 1,6, colonne de 68 caractères, justification et césure) pour que
 * l'équipe relise dans les conditions de la lecture en ligne.
 *
 * @param array                   $reglages Réglages de l'éditeur de blocs.
 * @param WP_Block_Editor_Context $contexte Contexte de l'éditeur.
 * @return array
 */
function yume_theme_editeur_chapitre( $reglages, $contexte ) {
	if ( ! is_array( $reglages ) || ! $contexte instanceof WP_Block_Editor_Context || ! $contexte->post instanceof WP_Post || 'yume_chapitre' !== $contexte->post->post_type ) {
		return $reglages;
	}
	$reglages['styles']   = isset( $reglages['styles'] ) && is_array( $reglages['styles'] ) ? $reglages['styles'] : array();
	$reglages['styles'][] = array(
		'css' => '.editor-styles-wrapper .is-root-container{max-width:68ch;margin-inline:auto;font-family:var(--wp--preset--font-family--lecture);font-size:18px;line-height:1.6;text-align:justify;-webkit-hyphens:auto;hyphens:auto}.editor-styles-wrapper .is-root-container > p{margin-block:0 .8em}',
	);
	return $reglages;
}
add_filter( 'block_editor_settings_all', 'yume_theme_editeur_chapitre', 10, 2 );
