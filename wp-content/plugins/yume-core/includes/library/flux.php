<?php
/**
 * Flux RSS d'une œuvre (AMEL-05) : /oeuvres/{o}/feed/ liste les sorties publiées de l'œuvre,
 * tomes et chapitres, et les articles d'actualité qui lui sont liés (taxonomie
 * yume_oeuvre_liee), du plus récent au plus ancien.
 *
 * Avant ce module, l'adresse servait le flux (natif) des commentaires de l'œuvre : il reste
 * disponible avec le paramètre ?commentaires=1. Le lien <link rel="alternate"> natif des
 * commentaires de la fiche est remplacé par celui des sorties.
 *
 * Tomes et chapitres sont mis en cache avec les autres listes du module (transients
 * versionnés, voir donnees.php) ; les articles liés sont lus à chaque requête.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/** Nombre maximal d'éléments du flux d'une œuvre (filtre yume_flux_oeuvre_max). */
const FLUX_OEUVRE_MAX = 50;

/**
 * Nombre maximal d'éléments du flux.
 */
function flux_oeuvre_max(): int {
	/**
	 * Filtre le nombre maximal d'éléments du flux RSS d'une œuvre.
	 *
	 * @param int $max Nombre (défaut 50).
	 */
	return max( 1, min( 200, (int) apply_filters( 'yume_flux_oeuvre_max', FLUX_OEUVRE_MAX ) ) );
}

/**
 * Œuvre dont la requête courante demande le flux des sorties, ou 0 (autre requête, flux des
 * commentaires demandé par ?commentaires=1, œuvre non publiée ou protégée par mot de passe).
 */
function oeuvre_du_flux(): int {
	if ( ! is_feed() || ! is_singular( TYPE_OEUVRE ) ) {
		return 0;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix du flux, lecture seule.
	if ( isset( $_GET['commentaires'] ) ) {
		return 0;
	}
	$oeuvre = get_queried_object();
	if ( ! $oeuvre instanceof \WP_Post || TYPE_OEUVRE !== $oeuvre->post_type || 'publish' !== $oeuvre->post_status || '' !== $oeuvre->post_password ) {
		return 0;
	}
	return (int) $oeuvre->ID;
}

/**
 * Sert le flux des sorties d'une œuvre à la place du flux natif des commentaires.
 * Priorité 20 : après les redirections canoniques (9 et 10).
 */
function servir_flux_oeuvre(): void {
	$oeuvre_id = oeuvre_du_flux();
	if ( ! $oeuvre_id ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/rss+xml; charset=UTF-8' );
	echo rss_oeuvre( $oeuvre_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- XML construit et échappé par rss_oeuvre().
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\servir_flux_oeuvre', 20 );

/**
 * Adresse du flux des sorties d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function url_flux_oeuvre( int $oeuvre_id ): string {
	return (string) get_post_comments_feed_link( $oeuvre_id, 'rss2' );
}

/**
 * Tomes et chapitres publiés d'une œuvre, pour le flux (mis en cache).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array<string,mixed>> Éléments : id, type, titre, lien, ts, resume.
 */
function sorties_flux_oeuvre( int $oeuvre_id ): array {
	return en_cache(
		'flux_oeuvre',
		array( $oeuvre_id ),
		static function () use ( $oeuvre_id ): array {
			$oeuvre    = titre( $oeuvre_id );
			$tomes     = function_exists( 'yume_get_tomes' ) ? yume_get_tomes( $oeuvre_id, array( 'status' => 'publish' ) ) : array();
			$tomes     = array_values(
				array_filter(
					$tomes,
					static function ( $t ): bool {
						return $t instanceof \WP_Post && '' === $t->post_password;
					}
				)
			);
			$chapitres = chapitres_des_tomes( wp_list_pluck( $tomes, 'ID' ), array( 'publish' ) );
			$elements  = array();
			foreach ( $tomes as $tome ) {
				$libelle    = libelle_tome( (int) $tome->ID );
				$resume     = trim( wp_strip_all_tags( (string) $tome->post_excerpt ) );
				$elements[] = array(
					'id'     => (int) $tome->ID,
					'type'   => 'tome',
					'titre'  => titre( (int) $tome->ID ),
					'lien'   => (string) get_permalink( $tome ),
					'ts'     => horodatage( $tome ),
					/* translators: 1: œuvre, 2: tome (« Tome 2 ») */
					'resume' => '' !== $resume ? $resume : sprintf( __( 'Nouvelle sortie : %1$s, %2$s.', 'yume-core' ), $oeuvre, $libelle ),
				);
				foreach ( $chapitres[ (int) $tome->ID ] ?? array() as $chapitre ) {
					if ( '' !== $chapitre->post_password ) {
						continue;
					}
					$elements[] = array(
						'id'     => (int) $chapitre->ID,
						'type'   => 'chapitre',
						'titre'  => $oeuvre . ' · ' . $libelle . ' · ' . titre( (int) $chapitre->ID ),
						'lien'   => (string) get_permalink( $chapitre ),
						'ts'     => horodatage( $chapitre ),
						/* translators: 1: œuvre, 2: tome, 3: chapitre (« Chapitre 3 ») */
						'resume' => sprintf( __( 'Nouveau chapitre à lire en ligne : %1$s, %2$s, %3$s.', 'yume-core' ), $oeuvre, $libelle, libelle_chapitre( (int) $chapitre->ID ) ),
					);
				}
			}
			return $elements;
		}
	);
}

/**
 * Articles d'actualité publiés liés à une œuvre (taxonomie yume_oeuvre_liee).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array<string,mixed>>
 */
function articles_flux_oeuvre( int $oeuvre_id ): array {
	if ( ! taxonomy_exists( 'yume_oeuvre_liee' ) ) {
		return array();
	}
	$terme_id = (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true );
	if ( ! $terme_id ) {
		$terme    = get_term_by( 'slug', (string) get_post_field( 'post_name', $oeuvre_id ), 'yume_oeuvre_liee' );
		$terme_id = $terme instanceof \WP_Term ? (int) $terme->term_id : 0;
	}
	if ( ! $terme_id ) {
		return array();
	}
	$articles = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'has_password'     => false,
			'posts_per_page'   => flux_oeuvre_max(),
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'yume_oeuvre_liee',
					'field'    => 'term_id',
					'terms'    => array( $terme_id ),
				),
			),
		)
	);
	$elements = array();
	foreach ( $articles as $article ) {
		$elements[] = array(
			'id'     => (int) $article->ID,
			'type'   => 'article',
			'titre'  => titre( (int) $article->ID ),
			'lien'   => (string) get_permalink( $article ),
			'ts'     => horodatage( $article ),
			'resume' => trim( html_entity_decode( wp_strip_all_tags( (string) get_the_excerpt( $article ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
		);
	}
	return $elements;
}

/**
 * Éléments du flux d'une œuvre, du plus récent au plus ancien (tomes, chapitres, articles).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array<string,mixed>>
 */
function elements_flux_oeuvre( int $oeuvre_id ): array {
	$elements = array_merge( sorties_flux_oeuvre( $oeuvre_id ), articles_flux_oeuvre( $oeuvre_id ) );
	usort(
		$elements,
		static function ( array $a, array $b ): int {
			$cmp = $b['ts'] <=> $a['ts'];
			return 0 !== $cmp ? $cmp : $b['id'] <=> $a['id'];
		}
	);
	/**
	 * Filtre les éléments du flux RSS d'une œuvre (déjà triés, avant la coupe).
	 *
	 * @param array $elements  Éléments : id, type (tome, chapitre, article), titre, lien, ts, resume.
	 * @param int   $oeuvre_id Œuvre.
	 */
	$elements = (array) apply_filters( 'yume_flux_oeuvre_elements', $elements, $oeuvre_id );
	return array_slice( $elements, 0, flux_oeuvre_max() );
}

/**
 * Flux RSS 2.0 des sorties d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function rss_oeuvre( int $oeuvre_id ): string {
	$x          = static function ( string $texte ): string {
		return htmlspecialchars( $texte, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};
	$rfc        = static function ( int $ts ): string {
		return gmdate( 'D, d M Y H:i:s', $ts ) . ' +0000';
	};
	$categories = array(
		'tome'     => __( 'Tome', 'yume-core' ),
		'chapitre' => __( 'Chapitre', 'yume-core' ),
		'article'  => __( 'Actualité', 'yume-core' ),
	);
	$oeuvre     = titre( $oeuvre_id );
	$site       = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$elements   = elements_flux_oeuvre( $oeuvre_id );

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
	$xml .= '<title>' . $x( $oeuvre . ( '' !== $site ? ' — ' . $site : '' ) ) . '</title>';
	$xml .= '<link>' . $x( (string) get_permalink( $oeuvre_id ) ) . '</link>';
	$xml .= '<atom:link href="' . $x( url_flux_oeuvre( $oeuvre_id ) ) . '" rel="self" type="application/rss+xml"/>';
	/* translators: %s : œuvre */
	$xml .= '<description>' . $x( sprintf( __( '%s : sorties des tomes et des chapitres, actualités.', 'yume-core' ), $oeuvre ) ) . '</description>';
	$xml .= '<language>fr-FR</language>';
	if ( $elements ) {
		$xml .= '<lastBuildDate>' . $x( $rfc( (int) $elements[0]['ts'] ) ) . '</lastBuildDate>';
	}
	foreach ( $elements as $e ) {
		$xml .= '<item><title>' . $x( (string) $e['titre'] ) . '</title>';
		$xml .= '<link>' . $x( (string) $e['lien'] ) . '</link>';
		$xml .= '<guid isPermaLink="false">' . $x( home_url( '/?p=' . (int) $e['id'] ) ) . '</guid>';
		$xml .= '<pubDate>' . $x( $rfc( (int) $e['ts'] ) ) . '</pubDate>';
		$xml .= '<category>' . $x( $categories[ $e['type'] ] ?? '' ) . '</category>';
		if ( '' !== (string) $e['resume'] ) {
			$xml .= '<description>' . $x( (string) $e['resume'] ) . '</description>';
		}
		$xml .= '</item>';
	}
	return $xml . '</channel></rss>';
}

/**
 * Fiche d'une œuvre : pas de lien natif vers le flux des commentaires (l'adresse sert les
 * sorties), remplacé par lien_flux_oeuvre().
 *
 * @param bool $afficher Afficher le lien natif.
 */
function masquer_flux_commentaires_oeuvre( $afficher ) {
	return is_singular( TYPE_OEUVRE ) ? false : $afficher;
}
add_filter( 'feed_links_extra_show_post_comments_feed', __NAMESPACE__ . '\\masquer_flux_commentaires_oeuvre' );

/**
 * <link rel="alternate"> du flux des sorties sur la fiche d'une œuvre publiée.
 */
function lien_flux_oeuvre(): void {
	if ( ! is_singular( TYPE_OEUVRE ) || is_feed() ) {
		return;
	}
	$oeuvre = get_queried_object();
	if ( ! $oeuvre instanceof \WP_Post || 'publish' !== $oeuvre->post_status || '' !== $oeuvre->post_password ) {
		return;
	}
	/* translators: %s : œuvre */
	$titre = sprintf( __( '%s : sorties (RSS)', 'yume-core' ), titre( (int) $oeuvre->ID ) );
	printf( '<link rel="alternate" type="application/rss+xml" title="%s" href="%s" />' . "\n", esc_attr( $titre ), esc_url( url_flux_oeuvre( (int) $oeuvre->ID ) ) );
}
add_action( 'wp_head', __NAMESPACE__ . '\\lien_flux_oeuvre', 4 );
