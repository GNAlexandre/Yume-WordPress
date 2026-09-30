<?php
/**
 * Façade du module wordend : script déclencheur sur les pages publiques et papillon discret
 * après le titre de la fiche des œuvres déclencheuses (yume/oeuvre-header), qui ouvre l'univers
 * de l'œuvre (data-yn-wordend-univers).
 *
 * @package Yume\Core
 */

namespace Yume\Core\WordEnd;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre le script déclencheur (pied de page, defer) et la feuille du papillon.
 */
function enregistrer_ressources(): void {
	wp_register_script(
		POIGNEE,
		plugins_url( 'assets/declencheur.js', __FILE__ ),
		array(),
		version_fichier( 'declencheur.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_register_style( POIGNEE_SECRET, plugins_url( 'assets/secret.css', __FILE__ ), array(), version_fichier( 'secret.css' ) );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_ressources', 5 );

/**
 * Met le déclencheur en file sur les pages publiques (jamais en administration, flux, embed).
 * La configuration est posée avant le script : un script « after » ferait perdre le defer.
 */
function enfiler_declencheur(): void {
	if ( is_admin() || is_feed() || is_embed() || ! actif() ) {
		return;
	}
	$config = configuration();
	if ( ! $config['univers'] ) {
		return; // Aucun univers jouable (registre vidé par yume_wordend_univers).
	}
	wp_add_inline_script( POIGNEE, 'window.ynWordEnd = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	wp_enqueue_script( POIGNEE );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enfiler_declencheur' );

/**
 * HTML du papillon qui ouvre le jeu (vrai bouton, accessible au clavier).
 *
 * @param string $univers Slug de l'univers ouvert ('' : univers par défaut).
 * @param string $titre   Titre de l'univers (libellé).
 */
function html_papillon( string $univers = '', string $titre = 'WordEnd' ): string {
	/* translators: %s : titre de l'univers du jeu (WordEnd). */
	$libelle  = sprintf( __( 'Un papillon bleu s’est posé ici… (jeu caché %s)', 'yume-core' ), $titre );
	$svg      = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
		. '<path fill="currentColor" d="M11.3 11.2C9.9 7.4 6.6 4.3 3.9 4.1 2 4 1.6 6 2.4 8.3c.6 1.7 1.9 2.8 3.6 3.2-1.9.6-2.9 2.2-2.4 4 .6 2.2 3 2.6 4.9.9 1.4-1.2 2.4-3 2.8-5.2zm1.4 0c1.4-3.8 4.7-6.9 7.4-7.1 1.9-.1 2.3 1.9 1.5 4.2-.6 1.7-1.9 2.8-3.6 3.2 1.9.6 2.9 2.2 2.4 4-.6 2.2-3 2.6-4.9.9-1.4-1.2-2.4-3-2.8-5.2z"/>'
		. '</svg>';
	$attribut = '' !== $univers ? ' data-yn-wordend-univers="' . esc_attr( $univers ) . '"' : '';
	return '<button type="button" class="yn-wordend-secret" data-yn-wordend-ouvrir' . $attribut . ' aria-label="' . esc_attr( $libelle ) . '" title="' . esc_attr( $libelle ) . '">' . $svg . '</button>';
}

/**
 * Ajoute le papillon au rendu de yume/oeuvre-header pour une œuvre déclencheuse : il ouvre
 * l'univers de l'œuvre (univers_par_oeuvre) ou, pour une œuvre ajoutée par yume_wordend_oeuvres
 * sans univers propre, l'univers par défaut.
 *
 * @param string         $html     Rendu du bloc.
 * @param array          $bloc     Bloc analysé.
 * @param \WP_Block|null $instance Instance du bloc.
 */
function ajouter_papillon( $html, $bloc = array(), $instance = null ) {
	$html = (string) $html;
	if ( '' === $html || ! actif() || false === strpos( $html, 'yn-oeuvre-header' ) ) {
		return $html;
	}
	$oeuvre_id = ( $instance instanceof \WP_Block && ! empty( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : (int) get_queried_object_id();
	if ( $oeuvre_id <= 0 || 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return $html;
	}
	$slug_oeuvre = (string) get_post_field( 'post_name', $oeuvre_id );
	if ( ! in_array( $slug_oeuvre, oeuvres_declencheuses(), true ) ) {
		return $html;
	}
	$liste   = univers();
	$univers = univers_par_oeuvre( $slug_oeuvre );
	$titre   = $liste[ '' !== $univers ? $univers : univers_par_defaut( $liste ) ]['titre'] ?? '';
	if ( '' === $titre ) {
		return $html; // Aucun univers jouable.
	}
	// Juste après le titre, dans .yn-oeuvre-header__tete (la racine est en display: contents).
	$fin = strpos( $html, '</h1>' );
	if ( false === $fin ) {
		return $html;
	}
	$fin += strlen( '</h1>' );
	wp_enqueue_style( POIGNEE_SECRET );
	return substr( $html, 0, $fin ) . html_papillon( $univers, $titre ) . substr( $html, $fin );
}
add_filter( 'render_block_yume/oeuvre-header', __NAMESPACE__ . '\\ajouter_papillon', 10, 3 );
