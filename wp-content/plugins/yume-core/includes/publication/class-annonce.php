<?php
/**
 * Article d'annonce d'une sortie : brouillon dans la catégorie « Sorties » (créée si
 * absente), relié à l'œuvre (taxonomie yume_oeuvre_liee), titre selon le réglage
 * « modele_annonce », boutons Lire / PDF / EPUB, image mise en avant = couverture du tome.
 *
 * L'article est créé une seule fois par tome (méta _yume_annonce_tome) ; tant qu'il n'est
 * pas publié, il est régénéré à chaque préparation, sauf s'il a été modifié à la main
 * (empreinte _yume_annonce_empreinte), auquel cas seuls les liens sont mis à jour.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

/**
 * Article d'annonce.
 */
final class Annonce {

	/** Méta de l'article : tome annoncé. */
	public const META_TOME = '_yume_annonce_tome';

	/** Méta de l'article : empreinte du contenu généré. */
	public const META_EMPREINTE = '_yume_annonce_empreinte';

	/** Méta de l'article : lien de lecture utilisé dans le contenu. */
	public const META_LIEN = '_yume_annonce_lien';

	/**
	 * Catégorie « Sorties » (créée si absente).
	 *
	 * @return int ID du terme (0 si impossible).
	 */
	public static function categorie(): int {
		$terme = get_term_by( 'slug', 'sorties', 'category' );
		if ( $terme instanceof \WP_Term ) {
			return (int) $terme->term_id;
		}
		$cree = wp_insert_term( __( 'Sorties', 'yume-core' ), 'category', array( 'slug' => 'sorties' ) );
		if ( is_wp_error( $cree ) ) {
			$existant = $cree->get_error_data( 'term_exists' );
			return is_numeric( $existant ) ? (int) $existant : 0;
		}
		return (int) $cree['term_id'];
	}

	/**
	 * Article d'annonce existant d'un tome (hors corbeille), ou 0.
	 *
	 * @param int $tome_id Tome.
	 */
	public static function existant( int $tome_id ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_key'         => self::META_TOME,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => (string) $tome_id,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Libellé en minuscules de la nature d'un tome (« tome », « arc », « tome EX »).
	 *
	 * @param string $nature Nature.
	 */
	private static function nature_minuscule( string $nature ): string {
		$natures = yume_natures_tome();
		$libelle = (string) ( $natures[ $nature ] ?? $natures['tome'] );
		return mb_strtolower( mb_substr( $libelle, 0, 1 ) ) . mb_substr( $libelle, 1 );
	}

	/**
	 * Titre de l'annonce selon le modèle (réglage modele_annonce).
	 *
	 * Variables : {nature}, {numero}, {oeuvre}, {titre}, {libelle}. « Le arc » devient « L’arc ».
	 *
	 * @param int $tome_id Tome.
	 */
	public static function titre( int $tome_id ): string {
		$modele = (string) yume_setting( 'modele_annonce' );
		if ( '' === trim( $modele ) ) {
			$modele = 'Le {nature} {numero} de {oeuvre} est disponible !';
		}
		$numero    = get_post_meta( $tome_id, 'yume_numero', true );
		$numero    = is_numeric( $numero ) ? str_replace( '.', ',', rtrim( rtrim( number_format( (float) $numero, 3, '.', '' ), '0' ), '.' ) ) : '';
		$oeuvre_id = yume_get_oeuvre_id( $tome_id );
		$titre     = strtr(
			$modele,
			array(
				'{nature}'  => self::nature_minuscule( (string) get_post_meta( $tome_id, 'yume_nature', true ) ),
				'{numero}'  => $numero,
				'{oeuvre}'  => $oeuvre_id ? get_the_title( $oeuvre_id ) : '',
				'{titre}'   => (string) ( ( (array) get_post_meta( $tome_id, Service::META, true ) )['titre'] ?? '' ),
				'{libelle}' => yume_libelle_tome( $tome_id ),
			)
		);
		return trim( (string) preg_replace( '/\s{2,}/', ' ', self::elision( $titre ) ) );
	}

	/**
	 * Élision de l'article défini : « Le arc » → « L’arc », « le épisode » → « l’épisode ».
	 *
	 * @param string $texte Texte.
	 */
	public static function elision( string $texte ): string {
		return (string) preg_replace( '/\b([Ll])e\s+(?=[aeiouyàâéèêëîïôûùAEIOUYÀÂÉÈÊËÎÏÔÛÙ])/u', '$1’', $texte );
	}

	/**
	 * Contenu de l'annonce (blocs).
	 *
	 * @param int                 $tome_id Tome.
	 * @param string              $lien    Lien de lecture.
	 * @param array<string,mixed> $infos   nb_chapitres, speciaux (libellés), credits.
	 */
	public static function contenu( int $tome_id, string $lien, array $infos ): string {
		$oeuvre_id = yume_get_oeuvre_id( $tome_id );
		$oeuvre    = $oeuvre_id ? get_the_title( $oeuvre_id ) : '';
		$libelle   = yume_libelle_tome( $tome_id );
		$liens     = yume_liens_telechargement( $tome_id );
		$blocs     = array();

		$intro = sprintf(
			/* translators: 1: « Le tome 10 », 2: œuvre (lien) */
			__( '%1$s de %2$s est disponible en lecture en ligne sur le site.', 'yume-core' ),
			esc_html( self::elision( 'Le ' . mb_strtolower( mb_substr( $libelle, 0, 1 ) ) . mb_substr( $libelle, 1 ) ) ),
			$oeuvre_id ? '<a href="' . esc_url( (string) get_permalink( $oeuvre_id ) ) . '">' . esc_html( $oeuvre ) . '</a>' : esc_html( $oeuvre )
		);
		if ( '' !== $liens['pdf'] || '' !== $liens['epub'] ) {
			$intro .= ' ' . __( 'Vous pouvez aussi le télécharger en PDF ou en EPUB.', 'yume-core' );
		}
		$blocs[] = self::paragraphe( $intro );

		$nb = (int) ( $infos['nb_chapitres'] ?? 0 );
		if ( $nb > 0 ) {
			$programme = sprintf(
				/* translators: %d : nombre de chapitres */
				_n( 'Au programme : %d chapitre', 'Au programme : %d chapitres', $nb, 'yume-core' ),
				$nb
			);
			$speciaux = array_filter( (array) ( $infos['speciaux'] ?? array() ) );
			if ( $speciaux ) {
				$programme .= ' + ' . implode( ', ', array_map( 'mb_strtolower', $speciaux ) );
			}
			$blocs[] = self::paragraphe( esc_html( $programme . '.' ) );
		}

		$boutons = array( self::bouton( __( 'Lire en ligne', 'yume-core' ), $lien, '' ) );
		if ( '' !== $liens['pdf'] ) {
			$boutons[] = self::bouton( __( 'Télécharger le PDF', 'yume-core' ), $liens['pdf'], 'is-style-yn-secondaire' );
		}
		if ( '' !== $liens['epub'] ) {
			$boutons[] = self::bouton( __( 'Télécharger l’EPUB', 'yume-core' ), $liens['epub'], 'is-style-yn-secondaire' );
		}
		$blocs[] = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">" . implode( "\n\n", $boutons ) . "</div>\n<!-- /wp:buttons -->";

		$credits = array_filter( (array) ( $infos['credits'] ?? array() ) );
		if ( $credits ) {
			$libelles = array(
				'traduction' => __( 'Traduction', 'yume-core' ),
				'relecture'  => __( 'Relecture', 'yume-core' ),
				'edition'    => __( 'Édition', 'yume-core' ),
			);
			$parts    = array();
			foreach ( $libelles as $cle => $nom ) {
				if ( ! empty( $credits[ $cle ] ) ) {
					$parts[] = esc_html( $nom . ' : ' . $credits[ $cle ] );
				}
			}
			if ( $parts ) {
				$blocs[] = self::paragraphe( implode( ' · ', $parts ) );
			}
		}
		$blocs[] = self::paragraphe( esc_html__( 'Bonne lecture !', 'yume-core' ) );
		return implode( "\n\n", $blocs );
	}

	/**
	 * Bloc paragraphe (HTML déjà échappé).
	 *
	 * @param string $html Contenu.
	 */
	private static function paragraphe( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Bloc bouton (core/button).
	 *
	 * @param string $texte  Libellé.
	 * @param string $url    Lien.
	 * @param string $classe Style de bouton (classe).
	 */
	private static function bouton( string $texte, string $url, string $classe ): string {
		$attrs = '' !== $classe ? ' ' . serialize_block_attributes( array( 'className' => $classe ) ) : '';
		return '<!-- wp:button' . $attrs . " -->\n"
			. '<div class="wp-block-button' . ( '' !== $classe ? ' ' . esc_attr( $classe ) : '' ) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '">' . esc_html( $texte ) . '</a></div>'
			. "\n<!-- /wp:button -->";
	}

	/**
	 * Crée ou met à jour l'article d'annonce d'un tome (brouillon).
	 *
	 * @param int                 $tome_id Tome.
	 * @param array<string,mixed> $infos   nb_chapitres, speciaux, credits.
	 * @return int|\WP_Error ID de l'article.
	 */
	public static function preparer( int $tome_id, array $infos ) {
		$article_id = self::existant( $tome_id );
		$article    = $article_id ? get_post( $article_id ) : null;
		if ( $article && in_array( $article->post_status, array( 'publish', 'private' ), true ) ) {
			return $article_id; // Annonce déjà parue : jamais modifiée.
		}
		$lien    = (string) get_permalink( $tome_id );
		$contenu = self::contenu( $tome_id, $lien, $infos );
		$donnees = array(
			'post_type'     => 'post',
			'post_title'    => self::titre( $tome_id ),
			'post_status'   => $article ? $article->post_status : 'draft',
			'post_category' => array_filter( array( self::categorie() ) ),
		);
		if ( $article ) {
			$donnees['ID'] = $article_id;
			$modifie       = md5( (string) $article->post_content ) !== (string) get_post_meta( $article_id, self::META_EMPREINTE, true );
			if ( $modifie ) {
				// Article retouché à la main : on garde son texte et son titre, on met les liens à jour.
				unset( $donnees['post_title'] );
				$donnees['post_content'] = self::remplacer_lien( (string) $article->post_content, (string) get_post_meta( $article_id, self::META_LIEN, true ), $lien );
			} else {
				$donnees['post_content'] = $contenu;
			}
		} else {
			$donnees['post_content'] = $contenu;
			$donnees['post_author']  = get_current_user_id();
		}
		$resultat = $article ? wp_update_post( wp_slash( $donnees ), true ) : wp_insert_post( wp_slash( $donnees ), true );
		if ( is_wp_error( $resultat ) ) {
			return $resultat;
		}
		$article_id = (int) $resultat;
		update_post_meta( $article_id, self::META_TOME, (string) $tome_id );
		update_post_meta( $article_id, self::META_EMPREINTE, md5( (string) get_post_field( 'post_content', $article_id, 'raw' ) ) );
		update_post_meta( $article_id, self::META_LIEN, $lien );
		self::lier_oeuvre( $article_id, yume_get_oeuvre_id( $tome_id ) );
		$couverture = yume_get_cover_id( $tome_id );
		if ( $couverture ) {
			set_post_thumbnail( $article_id, $couverture );
		}
		return $article_id;
	}

	/**
	 * Relie l'article à l'œuvre (terme yume_oeuvre_liee créé par core).
	 *
	 * @param int $article_id Article.
	 * @param int $oeuvre_id  Œuvre.
	 */
	private static function lier_oeuvre( int $article_id, int $oeuvre_id ): void {
		if ( ! $oeuvre_id || ! taxonomy_exists( 'yume_oeuvre_liee' ) ) {
			return;
		}
		$terme_id = (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true );
		if ( ! $terme_id || ! term_exists( $terme_id, 'yume_oeuvre_liee' ) ) {
			$terme    = get_term_by( 'slug', (string) get_post_field( 'post_name', $oeuvre_id ), 'yume_oeuvre_liee' );
			$terme_id = $terme instanceof \WP_Term ? (int) $terme->term_id : 0;
		}
		if ( $terme_id ) {
			wp_set_object_terms( $article_id, array( $terme_id ), 'yume_oeuvre_liee', true );
		}
	}

	/**
	 * Remplace un ancien lien de lecture par le nouveau dans le contenu.
	 *
	 * @param string $contenu Contenu.
	 * @param string $ancien  Ancien lien.
	 * @param string $nouveau Nouveau lien.
	 */
	private static function remplacer_lien( string $contenu, string $ancien, string $nouveau ): string {
		if ( '' === $ancien || $ancien === $nouveau ) {
			return $contenu;
		}
		// Le lien peut avoir été enregistré échappé (&#038;), normalisé par kses (&amp;) ou brut.
		$formes = array_unique( array( esc_url( $ancien ), esc_attr( $ancien ), str_replace( '&', '&amp;', $ancien ), $ancien ) );
		return str_replace( $formes, esc_url( $nouveau ), $contenu );
	}

	/**
	 * Au moment de la sortie : met à jour les liens (le tome a maintenant son adresse
	 * définitive) puis publie ou programme l'article.
	 *
	 * @param int    $tome_id  Tome.
	 * @param string $statut   'publish' ou 'future'.
	 * @param string $date     Date locale « Y-m-d H:i:s ».
	 * @param string $date_gmt Date GMT « Y-m-d H:i:s ».
	 * @return int ID de l'article (0 si aucun).
	 */
	public static function sortir( int $tome_id, string $statut, string $date, string $date_gmt ): int {
		$article_id = self::existant( $tome_id );
		$article    = $article_id ? get_post( $article_id ) : null;
		if ( ! $article || in_array( $article->post_status, array( 'publish', 'private' ), true ) ) {
			return $article_id;
		}
		$lien    = (string) get_permalink( $tome_id );
		$ancien  = (string) get_post_meta( $article_id, self::META_LIEN, true );
		$contenu = self::remplacer_lien( (string) $article->post_content, $ancien, $lien );
		$garder  = md5( (string) $article->post_content ) === (string) get_post_meta( $article_id, self::META_EMPREINTE, true );
		wp_update_post(
			wp_slash(
				array(
					'ID'            => $article_id,
					'post_status'   => $statut,
					'post_date'     => $date,
					'post_date_gmt' => $date_gmt,
					'edit_date'     => true,
					'post_content'  => $contenu,
				)
			)
		);
		update_post_meta( $article_id, self::META_LIEN, $lien );
		if ( $garder ) {
			update_post_meta( $article_id, self::META_EMPREINTE, md5( (string) get_post_field( 'post_content', $article_id, 'raw' ) ) );
		}
		return $article_id;
	}
}
