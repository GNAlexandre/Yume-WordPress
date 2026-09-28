<?php
/**
 * Liens du thème résolus à l'exécution.
 *
 * Les modèles contiennent des adresses par défaut (« /planning/ », « https://ko-fi.com/ynovel »…)
 * lisibles dans l'éditeur. Un bloc qui porte la classe `yn-lien-<clé>` voit son premier lien
 * remplacé en façade par l'adresse réelle : page créée par la migration (option `yume_pages`
 * via yume_url_page()), réglage de l'extension (yume_setting()), page de WordPress ou valeur
 * de repli. Le thème fonctionne ainsi avec ou sans l'extension Yume Core.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clés de liens connues et leur définition.
 *
 * - `page`    : clé de yume_url_page() (extension) ;
 * - `option`  : clé de l'option `yume_pages` (page publiée enregistrée sous cette clé) ;
 * - `chemin`  : chemin de page WordPress à rechercher, puis adresse de repli ;
 * - `reglage` : clé de yume_setting() (extension) et valeur par défaut du contrat §6.
 *
 * @return array<string,array<string,string>>
 */
function yume_theme_definitions_liens(): array {
	return array(
		'accueil'       => array(),
		'bibliotheque'  => array(
			'page'   => 'bibliotheque',
			'chemin' => 'bibliotheque',
		),
		'sorties'       => array(
			'page'   => 'bibliotheque',
			'chemin' => 'bibliotheque',
		),
		'planning'      => array(
			'page'   => 'planning',
			'chemin' => 'planning',
		),
		'compte'        => array(
			'page'   => 'compte',
			'chemin' => 'compte',
		),
		'actualites'    => array(
			'chemin' => 'actualites',
		),
		'equipe'        => array(
			'chemin' => 'lequipe',
		),
		'la-yume-novel' => array(
			'chemin' => 'la-yume-novel',
		),
		'faq'           => array(
			'chemin' => 'yume-faq',
		),
		'reseaux'       => array(
			'chemin' => 'a-propos',
		),
		'contact'       => array(
			'option' => 'contactez-nous',
			'chemin' => 'contactez-nous',
		),
		'mentions'      => array(
			'chemin' => 'mentions-legales',
		),
		'kofi'          => array(
			'reglage' => 'kofi_url',
			'defaut'  => 'https://ko-fi.com/ynovel',
		),
		'discord'       => array(
			'reglage' => 'discord_invite',
			'defaut'  => 'https://discord.gg/SMBZqhgUv8',
		),
		'twitter'       => array(
			'reglage' => 'twitter_url',
			'defaut'  => 'https://x.com/YumeNovel',
		),
	);
}

/**
 * Adresse d'une page publiée à partir de son chemin (« lequipe », « planning »…).
 *
 * @param string $chemin Chemin de la page, sans barre oblique.
 * @return string Adresse, ou chaîne vide si la page n'existe pas ou n'est pas publiée.
 */
function yume_theme_url_page_par_chemin( string $chemin ): string {
	static $pages = null, $version = '';
	// Toutes les pages racines des liens du thème en une requête, au lieu d'un get_page_by_path()
	// (deux requêtes) par lien ; rechargées si une page a changé depuis.
	$derniere = wp_cache_get_last_changed( 'posts' );
	if ( null === $pages || $version !== $derniere ) {
		$version = $derniere;
		$chemins = array_filter( array_column( yume_theme_definitions_liens(), 'chemin' ) );
		$chemins = array_values( array_unique( array_merge( $chemins, array( 'mentions-legales' ) ) ) );
		$pages   = array();
		foreach ( get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'post_parent'      => 0,
				'post_name__in'    => $chemins,
				'posts_per_page'   => count( $chemins ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $trouvee ) {
			$pages[ $trouvee->post_name ] ??= $trouvee;
		}
	}
	if ( isset( $pages[ $chemin ] ) ) {
		return (string) get_permalink( $pages[ $chemin ] );
	}
	if ( str_contains( $chemin, '/' ) ) {
		$page = get_page_by_path( $chemin, OBJECT, 'page' );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			return (string) get_permalink( $page );
		}
	}
	return '';
}

/**
 * Adresse réelle d'un lien du thème.
 *
 * @param string $cle Clé (voir yume_theme_definitions_liens()).
 * @return string Adresse absolue (jamais vide).
 */
function yume_theme_lien( string $cle ): string {
	static $cache = array();
	if ( isset( $cache[ $cle ] ) ) {
		return $cache[ $cle ];
	}

	$definitions = yume_theme_definitions_liens();
	$definition  = $definitions[ $cle ] ?? array();
	$url         = '';

	if ( 'accueil' === $cle ) {
		$url = home_url( '/' );
	} elseif ( 'actualites' === $cle ) {
		$page_articles = (int) get_option( 'page_for_posts' );
		if ( $page_articles > 0 && 'page' === get_option( 'show_on_front' ) && 'publish' === get_post_status( $page_articles ) ) {
			$url = (string) get_permalink( $page_articles );
		}
	} elseif ( 'mentions' === $cle ) {
		$url = yume_theme_url_page_par_chemin( 'mentions-legales' );
		if ( '' === $url ) {
			$url = (string) get_privacy_policy_url();
		}
	}

	if ( '' === $url && isset( $definition['reglage'] ) ) {
		$url = $definition['defaut'];
		if ( function_exists( 'yume_setting' ) ) {
			$valeur = yume_setting( $definition['reglage'], $definition['defaut'] );
			if ( is_string( $valeur ) && '' !== trim( $valeur ) ) {
				$url = trim( $valeur );
			}
		}
	}

	if ( '' === $url && isset( $definition['page'] ) && function_exists( 'yume_url_page' ) ) {
		$url = (string) yume_url_page( $definition['page'] );
	}

	if ( '' === $url && 'bibliotheque' === ( $definition['page'] ?? '' ) && post_type_exists( 'yume_oeuvre' ) ) {
		$archive = get_post_type_archive_link( 'yume_oeuvre' );
		$url     = is_string( $archive ) ? $archive : '';
	}

	if ( '' === $url && isset( $definition['option'] ) ) {
		$pages = get_option( 'yume_pages', array() );
		$id    = is_array( $pages ) ? absint( $pages[ $definition['option'] ] ?? 0 ) : 0;
		if ( $id > 0 && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			$url = (string) get_permalink( $id );
		}
	}

	if ( '' === $url && isset( $definition['chemin'] ) ) {
		$url = yume_theme_url_page_par_chemin( $definition['chemin'] );
		if ( '' === $url && 'actualites' === $cle ) {
			$categorie = get_category_by_slug( 'actualites' );
			if ( $categorie instanceof WP_Term ) {
				$url = (string) get_category_link( $categorie );
			}
		}
		if ( '' === $url ) {
			$url = home_url( '/' . $definition['chemin'] . '/' );
		}
	}

	if ( 'sorties' === $cle ) {
		$url = add_query_arg( 'tri', 'recent', $url );
	}

	if ( '' === $url ) {
		$url = home_url( '/' );
	}

	/**
	 * Filtre l'adresse d'un lien du thème.
	 *
	 * @param string $url Adresse résolue.
	 * @param string $cle Clé du lien.
	 */
	$url = (string) apply_filters( 'yume_theme_lien', $url, $cle );

	$cache[ $cle ] = $url;
	return $url;
}

/**
 * Clé de lien portée par un nom de classe (« yn-lien-planning » → « planning »).
 *
 * @param string $classes Classes CSS du bloc.
 * @return string Clé connue, ou chaîne vide.
 */
function yume_theme_cle_lien_depuis_classes( string $classes ): string {
	if ( '' === $classes || ! str_contains( $classes, 'yn-lien-' ) ) {
		return '';
	}
	if ( preg_match( '/(?:^|\s)yn-lien-([a-z0-9-]+)(?:\s|$)/', $classes, $trouve ) ) {
		$cle = $trouve[1];
		return array_key_exists( $cle, yume_theme_definitions_liens() ) ? $cle : '';
	}
	return '';
}

/**
 * Blocs portant une classe yn-lien-* (paragraphe, bouton, lien de navigation…) : le premier
 * lien du rendu reçoit l'adresse réelle. Les liens de navigation à chemin relatif
 * (« /planning/ ») deviennent absolus, ce qui fonctionne aussi dans un sous-dossier.
 *
 * @param string $contenu Rendu du bloc.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_rendu_liens( $contenu, $bloc ) {
	if ( ! is_string( $contenu ) || '' === $contenu || ! is_array( $bloc ) ) {
		return $contenu;
	}
	$nom        = (string) ( $bloc['blockName'] ?? '' );
	$navigation = in_array( $nom, array( 'core/navigation-link', 'core/navigation-submenu' ), true );
	$cle        = yume_theme_cle_lien_depuis_classes( (string) ( $bloc['attrs']['className'] ?? '' ) );
	if ( '' === $cle && ! $navigation ) {
		return $contenu;
	}
	$balises = new WP_HTML_Tag_Processor( $contenu );
	if ( ! $balises->next_tag( 'a' ) ) {
		return $contenu;
	}
	if ( '' !== $cle ) {
		$balises->set_attribute( 'href', esc_url( yume_theme_lien( $cle ) ) );
		return $balises->get_updated_html();
	}
	$href = (string) $balises->get_attribute( 'href' );
	if ( '' !== $href && str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' ) ) {
		$balises->set_attribute( 'href', esc_url( home_url( $href ) ) );
		return $balises->get_updated_html();
	}
	return $contenu;
}
add_filter( 'render_block', 'yume_theme_rendu_liens', 10, 2 );

/**
 * Chemin de la requête courante, normalisé (sans barre finale, en minuscules).
 *
 * @return string
 */
function yume_theme_chemin_courant(): string {
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	$chemin = (string) wp_parse_url( $uri, PHP_URL_PATH );
	return strtolower( untrailingslashit( $chemin ) );
}

/**
 * Marque le lien de navigation de la page courante (aria-current="page"), y compris
 * pour les liens personnalisés que WordPress ne sait pas reconnaître.
 *
 * @param string $contenu Rendu du lien.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_lien_courant( $contenu, $bloc ) {
	if ( ! is_string( $contenu ) || '' === $contenu || is_admin() || str_contains( $contenu, 'aria-current' ) ) {
		return $contenu;
	}
	$balises = new WP_HTML_Tag_Processor( $contenu );
	if ( ! $balises->next_tag( 'a' ) ) {
		return $contenu;
	}
	$url     = (string) $balises->get_attribute( 'href' );
	$classes = (string) ( $bloc['attrs']['className'] ?? '' );
	if ( 'actualites' === yume_theme_cle_lien_depuis_classes( $classes ) && is_home() && ! is_front_page() ) {
		$courant = true;
	} else {
		$hote_lien = (string) wp_parse_url( $url, PHP_URL_HOST );
		$hote_site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$chemin    = strtolower( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$courant   = '' !== $url && ( '' === $hote_lien || $hote_lien === $hote_site ) && '' !== $chemin && yume_theme_chemin_courant() === $chemin;
	}
	if ( ! $courant ) {
		return $contenu;
	}
	$balises->set_attribute( 'aria-current', 'page' );
	$contenu = $balises->get_updated_html();
	$balises = new WP_HTML_Tag_Processor( $contenu );
	if ( $balises->next_tag( 'li' ) ) {
		$balises->add_class( 'current-menu-item' );
		$contenu = $balises->get_updated_html();
	}
	return $contenu;
}
add_filter( 'render_block_core/navigation-link', 'yume_theme_lien_courant', 10, 2 );

/**
 * Année courante dans la mention de copyright du pied de page (classe yn-copyright) :
 * « © 2026 » reste lisible dans l'éditeur et se met à jour tout seul en façade.
 *
 * @param string $contenu Rendu du bloc.
 * @param array  $bloc    Bloc analysé.
 * @return string
 */
function yume_theme_annee_copyright( $contenu, $bloc ) {
	if ( ! is_string( $contenu ) || ! str_contains( (string) ( $bloc['attrs']['className'] ?? '' ), 'yn-copyright' ) ) {
		return $contenu;
	}
	$annee = wp_date( 'Y' );
	return (string) preg_replace( '/©(\s|&nbsp;|\x{00A0})*\d{4}/u', '© ' . $annee, $contenu, 1 );
}
add_filter( 'render_block_core/paragraph', 'yume_theme_annee_copyright', 10, 2 );
