<?php
/**
 * Blocs : bloc « Bascule de thème » du thème, intégration des blocs de l'extension
 * dans la navigation, rendus de repli et petites retouches de rendu des blocs natifs.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre le bloc yume/theme-toggle (bouton Nuit ↔ Papier de l'en-tête).
 */
function yume_theme_enregistrer_blocs(): void {
	if ( WP_Block_Type_Registry::get_instance()->is_registered( 'yume/theme-toggle' ) ) {
		return;
	}
	register_block_type( YUME_THEME_DIR . '/blocks/theme-toggle' );
}
add_action( 'init', 'yume_theme_enregistrer_blocs' );

/**
 * Blocs qui, placés dans un bloc Navigation, doivent être enveloppés dans un <li>
 * comme un lien de menu.
 *
 * @param string[] $blocs Noms des blocs concernés.
 * @return string[]
 */
function yume_theme_blocs_de_navigation( $blocs ) {
	$blocs = is_array( $blocs ) ? $blocs : array();
	return array_values( array_unique( array_merge( $blocs, array( 'yume/library-menu', 'yume/auth-links', 'yume/theme-toggle' ) ) ) );
}
add_filter( 'block_core_navigation_listable_blocks', 'yume_theme_blocs_de_navigation' );

/**
 * Rendus de repli des blocs d'en-tête de l'extension quand celle-ci est désactivée :
 * l'en-tête garde un lien vers la bibliothèque et un accès à la connexion.
 *
 * @param string $contenu Rendu du bloc (vide pour un bloc non enregistré).
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_repli_blocs_extension( $contenu, $bloc ) {
	$nom = is_array( $bloc ) ? (string) ( $bloc['blockName'] ?? '' ) : '';
	if ( ! in_array( $nom, array( 'yume/library-menu', 'yume/auth-links' ), true ) ) {
		return $contenu;
	}
	if ( '' !== trim( (string) $contenu ) || WP_Block_Type_Registry::get_instance()->is_registered( $nom ) ) {
		return $contenu;
	}

	if ( 'yume/library-menu' === $nom ) {
		return sprintf(
			'<a class="yn-library-menu yn-library-menu--repli wp-block-navigation-item__content" href="%1$s"><span class="wp-block-navigation-item__label">%2$s</span></a>',
			esc_url( yume_theme_lien( 'bibliotheque' ) ),
			esc_html__( 'Bibliothèque', 'yume' )
		);
	}

	if ( is_user_logged_in() ) {
		return sprintf(
			'<div class="yn-auth yn-auth--repli"><a class="yn-btn yn-btn--primary yn-btn--sm" href="%1$s">%2$s</a></div>',
			esc_url( get_edit_profile_url() ),
			esc_html__( 'Mon compte', 'yume' )
		);
	}

	$liens = sprintf(
		'<a class="yn-btn yn-btn--primary yn-btn--sm" href="%1$s">%2$s</a>',
		esc_url( wp_login_url( yume_theme_url_courante() ) ),
		esc_html__( 'Connexion', 'yume' )
	);
	if ( get_option( 'users_can_register' ) ) {
		$liens .= sprintf(
			'<a class="yn-btn yn-btn--sm" href="%1$s">%2$s</a>',
			esc_url( wp_registration_url() ),
			esc_html__( 'Inscription', 'yume' )
		);
	}
	return '<div class="yn-auth yn-auth--repli">' . $liens . '</div>';
}
add_filter( 'render_block', 'yume_theme_repli_blocs_extension', 9, 2 );

/**
 * Adresse de la page courante (pour revenir après la connexion).
 *
 * @return string
 */
function yume_theme_url_courante(): string {
	global $wp;
	$requete = ( $wp instanceof WP && is_string( $wp->request ) ) ? trim( $wp->request, '/' ) : '';
	return '' === $requete ? home_url( '/' ) : home_url( user_trailingslashit( $requete ) );
}

/**
 * Image mise en avant absente : les blocs « Image mise en avant » portant la classe
 * yn-cover (couverture 2:3) ou yn-vignette (actualité 16:9) affichent un dégradé de
 * substitution plutôt que de disparaître, pour garder une grille régulière.
 *
 * @param string   $contenu  Rendu du bloc.
 * @param array    $bloc     Bloc analysé.
 * @param WP_Block $instance Instance du bloc (contexte postId).
 * @return string
 */
function yume_theme_image_de_substitution( $contenu, $bloc, $instance = null ) {
	if ( '' !== trim( (string) $contenu ) ) {
		return $contenu;
	}
	$classes = (string) ( $bloc['attrs']['className'] ?? '' );
	$cover   = str_contains( $classes, 'yn-cover' );
	$vignet  = str_contains( $classes, 'yn-vignette' );
	if ( ! $cover && ! $vignet ) {
		return $contenu;
	}
	$post_id = ( $instance instanceof WP_Block && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return $contenu;
	}
	$texte = $cover ? get_the_title( $post_id ) : get_bloginfo( 'name' );
	return sprintf(
		'<figure class="wp-block-post-featured-image %1$s yn-substitution" aria-hidden="true"><span class="yn-substitution__texte">%2$s</span></figure>',
		esc_attr( trim( $classes ) ),
		esc_html( wp_strip_all_tags( (string) $texte ) )
	);
}
add_filter( 'render_block_core/post-featured-image', 'yume_theme_image_de_substitution', 10, 3 );

/**
 * Titre des résultats de recherche en français, quelle que soit la langue installée :
 * « Résultats pour « terme » ».
 *
 * @param string $contenu Rendu du bloc Titre de requête.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_titre_recherche( $contenu, $bloc ) {
	if ( 'search' !== ( $bloc['attrs']['type'] ?? '' ) || ! is_search() ) {
		return $contenu;
	}
	$niveau = (int) ( $bloc['attrs']['level'] ?? 1 );
	$niveau = ( $niveau >= 1 && $niveau <= 6 ) ? $niveau : 1;
	$terme  = get_search_query( false );
	$titre  = '' === $terme
		? esc_html__( 'Recherche', 'yume' )
		/* translators: %s : termes recherchés. */
		: sprintf( esc_html__( 'Résultats pour « %s »', 'yume' ), esc_html( $terme ) );

	$balises = new WP_HTML_Tag_Processor( (string) $contenu );
	$classes = 'wp-block-query-title';
	if ( $balises->next_tag() ) {
		$classes = (string) $balises->get_attribute( 'class' );
	}
	return sprintf( '<h%1$d class="%2$s">%3$s</h%1$d>', $niveau, esc_attr( $classes ), $titre );
}
add_filter( 'render_block_core/query-title', 'yume_theme_titre_recherche', 10, 2 );

/**
 * Le conteneur du lecteur (groupe .yn-reader) est déclaré en français : la césure
 * automatique (hyphens: auto) et la justification suivent alors les règles du français,
 * même si la langue de l'interface WordPress est différente.
 *
 * @param string $contenu Rendu du groupe.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_langue_lecteur( $contenu, $bloc ) {
	$classes = (string) ( $bloc['attrs']['className'] ?? '' );
	if ( ! is_string( $contenu ) || '' === $contenu || ! preg_match( '/(?:^|\s)yn-reader(?:\s|$)/', $classes ) ) {
		return $contenu;
	}
	$balises = new WP_HTML_Tag_Processor( $contenu );
	if ( $balises->next_tag() && null === $balises->get_attribute( 'lang' ) ) {
		$balises->set_attribute( 'lang', 'fr' );
		return $balises->get_updated_html();
	}
	return $contenu;
}
add_filter( 'render_block_core/group', 'yume_theme_langue_lecteur', 10, 2 );

/**
 * Libellé du type de contenu d'un résultat de recherche (paragraphe portant la classe
 * yn-type-contenu dans une boucle de requête) : « Actualité », « Œuvre », « Tome »…
 *
 * @param string $contenu Rendu du paragraphe.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_type_de_contenu( $contenu, $bloc ) {
	$classes = (string) ( $bloc['attrs']['className'] ?? '' );
	if ( ! is_string( $contenu ) || ! str_contains( $classes, 'yn-type-contenu' ) ) {
		return $contenu;
	}
	$type = get_post_type();
	if ( ! $type ) {
		return '';
	}
	$libelles = array(
		'post'          => __( 'Actualité', 'yume' ),
		'page'          => __( 'Page', 'yume' ),
		'yume_oeuvre'   => __( 'Œuvre', 'yume' ),
		'yume_tome'     => __( 'Tome', 'yume' ),
		'yume_chapitre' => __( 'Chapitre', 'yume' ),
	);
	$objet   = get_post_type_object( $type );
	$libelle = $libelles[ $type ] ?? ( $objet ? $objet->labels->singular_name : '' );
	if ( '' === $libelle ) {
		return '';
	}
	$balises = new WP_HTML_Tag_Processor( $contenu );
	$classe  = 'yn-label yn-type-contenu';
	if ( $balises->next_tag( 'p' ) ) {
		$classe = (string) $balises->get_attribute( 'class' );
	}
	return sprintf( '<p class="%1$s">%2$s</p>', esc_attr( $classe ), esc_html( $libelle ) );
}
add_filter( 'render_block_core/paragraph', 'yume_theme_type_de_contenu', 10, 2 );
