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
 * Tome publié chapitre par chapitre (parution « en cours ») : sa première sortie est annoncée
 * avec la variante « SukaMoka, Tome 2 : Prologue disponible ! » (titre_chapitres()) ; quand
 * l'équipe le marque complet, un second article (méta _yume_annonce_complet) dit « Le tome 2 de
 * SukaMoka est complet : PDF et EPUB disponibles » (complet()). Un tome complet publié d'un
 * coup garde le modèle habituel (réglage modele_annonce).
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

	/** Méta de l'article « tome complet » d'un tome publié chapitre par chapitre : tome annoncé. */
	public const META_COMPLET = '_yume_annonce_complet';

	/**
	 * Méta de l'article : annonce retirée avec son tome (dépublié), à republier à son retour
	 * en ligne ['statut' => publish|future, 'date' => locale, 'date_gmt' => GMT].
	 */
	public const META_RETIREE = '_yume_annonce_retiree';

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
	 * @param int    $tome_id Tome.
	 * @param string $cle     Méta de l'article : META_TOME (annonce de sortie) ou META_COMPLET
	 *                        (annonce « tome complet »).
	 */
	public static function existant( int $tome_id, string $cle = self::META_TOME ): int {
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
				'meta_key'         => self::META_COMPLET === $cle ? self::META_COMPLET : self::META_TOME,
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
	 * Variantes d'un tome publié chapitre par chapitre : $sortie['parution'] = en_cours avec
	 * les chapitres de la sortie (« SukaMoka, Tome 2 : Prologue disponible ! », voir
	 * titre_chapitres()) ou complet (« Le tome 2 de SukaMoka est complet : PDF et EPUB
	 * disponibles », voir titre_complet()). Sans variante : le modèle, inchangé.
	 *
	 * @param int                 $tome_id Tome.
	 * @param array<string,mixed> $sortie  parution (en_cours, complet ou vide), chapitres (ID).
	 */
	public static function titre( int $tome_id, array $sortie = array() ): string {
		$parution  = (string) ( $sortie['parution'] ?? '' );
		$chapitres = array_values( array_filter( array_map( 'intval', (array) ( $sortie['chapitres'] ?? array() ) ) ) );
		if ( 'en_cours' === $parution && $chapitres ) {
			$titre = self::titre_chapitres( $tome_id, $chapitres );
		} elseif ( 'complet' === $parution ) {
			$titre = self::titre_complet( $tome_id );
		} else {
			$titre = self::titre_modele( $tome_id );
		}
		/**
		 * Filtre le titre de l'article d'annonce d'une sortie.
		 *
		 * @param string              $titre   Titre.
		 * @param int                 $tome_id Tome.
		 * @param array<string,mixed> $sortie  parution (en_cours, complet ou vide), chapitres.
		 */
		return (string) apply_filters( 'yume_publication_titre_annonce', $titre, $tome_id, $sortie );
	}

	/**
	 * Titre selon le modèle du réglage modele_annonce (sortie d'un tome complet).
	 *
	 * @param int $tome_id Tome.
	 */
	private static function titre_modele( int $tome_id ): string {
		$modele = (string) yume_setting( 'modele_annonce' );
		if ( '' === trim( $modele ) ) {
			$modele = 'Le {nature} {numero} de {oeuvre} est disponible !';
		}
		$titre = strtr(
			$modele,
			array(
				'{nature}'  => self::nature_minuscule( (string) get_post_meta( $tome_id, 'yume_nature', true ) ),
				'{numero}'  => self::numero_texte( $tome_id ),
				// Titre brut (sans entités de wptexturize) : il est enregistré dans post_title.
				'{oeuvre}'  => self::oeuvre_texte( $tome_id ),
				'{titre}'   => (string) ( ( (array) get_post_meta( $tome_id, Service::META, true ) )['titre'] ?? '' ),
				'{libelle}' => yume_libelle_tome( $tome_id ),
			)
		);
		return trim( (string) preg_replace( '/\s{2,}/', ' ', self::elision( $titre ) ) );
	}

	/**
	 * Numéro du tome au format français (« 26,5 »), vide sans numéro.
	 *
	 * @param int $tome_id Tome.
	 */
	private static function numero_texte( int $tome_id ): string {
		$numero = get_post_meta( $tome_id, 'yume_numero', true );
		return is_numeric( $numero ) ? str_replace( '.', ',', rtrim( rtrim( number_format( (float) $numero, 3, '.', '' ), '0' ), '.' ) ) : '';
	}

	/**
	 * Titre brut de l'œuvre du tome (sans entités), vide sans œuvre.
	 *
	 * @param int $tome_id Tome.
	 */
	private static function oeuvre_texte( int $tome_id ): string {
		$oeuvre_id = yume_get_oeuvre_id( $tome_id );
		return $oeuvre_id ? Service::titre_texte( $oeuvre_id ) : '';
	}

	/**
	 * Libellé d'une sortie de chapitres pour un titre : « Prologue », « chapitre 3 »,
	 * « chapitres 1 à 3 » (un chapitre numéroté s'écrit en minuscule après les deux-points).
	 *
	 * @param int[] $chapitres Chapitres de la sortie, dans l'ordre de lecture.
	 */
	public static function libelle_sortie( array $chapitres ): string {
		$libelle = Service::libelle_groupe( $chapitres );
		if ( str_starts_with( $libelle, __( 'Chapitre', 'yume-core' ) ) ) {
			$libelle = mb_strtolower( mb_substr( $libelle, 0, 1 ) ) . mb_substr( $libelle, 1 );
		}
		return $libelle;
	}

	/**
	 * Titre de la première sortie d'un tome publié chapitre par chapitre : « SukaMoka, Tome 2 :
	 * Prologue disponible ! », « SukaMoka, Tome 2 : chapitres 1 à 3 disponibles ! ».
	 *
	 * @param int   $tome_id   Tome.
	 * @param int[] $chapitres Chapitres de la sortie.
	 */
	public static function titre_chapitres( int $tome_id, array $chapitres ): string {
		$oeuvre = self::oeuvre_texte( $tome_id );
		return sprintf(
			/* translators: 1: œuvre et tome (SukaMoka, Tome 2), 2: chapitres (Prologue, chapitres 1 à 3) */
			_n( '%1$s : %2$s disponible !', '%1$s : %2$s disponibles !', count( $chapitres ), 'yume-core' ),
			( '' !== $oeuvre ? $oeuvre . ', ' : '' ) . yume_libelle_tome( $tome_id ),
			self::libelle_sortie( $chapitres )
		);
	}

	/**
	 * Formats téléchargeables d'un tome, pour une annonce : « PDF et EPUB disponibles »,
	 * « PDF disponible », « EPUB disponible », vide sans lien.
	 *
	 * @param int $tome_id Tome.
	 */
	public static function formats_disponibles( int $tome_id ): string {
		$liens = yume_liens_telechargement( $tome_id );
		if ( '' !== $liens['pdf'] && '' !== $liens['epub'] ) {
			return __( 'PDF et EPUB disponibles', 'yume-core' );
		}
		if ( '' !== $liens['pdf'] ) {
			return __( 'PDF disponible', 'yume-core' );
		}
		return '' !== $liens['epub'] ? __( 'EPUB disponible', 'yume-core' ) : '';
	}

	/**
	 * Titre de l'annonce d'un tome devenu complet : « Le tome 2 de SukaMoka est complet : PDF et
	 * EPUB disponibles » (sans lien de téléchargement : « Le tome 2 de SukaMoka est complet ! »).
	 *
	 * @param int $tome_id Tome.
	 */
	public static function titre_complet( int $tome_id ): string {
		$formats = self::formats_disponibles( $tome_id );
		$titre   = sprintf(
			/* translators: 1: nature du tome en minuscules (tome, arc), 2: numéro, 3: œuvre */
			__( 'Le %1$s %2$s de %3$s est complet', 'yume-core' ),
			self::nature_minuscule( (string) get_post_meta( $tome_id, 'yume_nature', true ) ),
			self::numero_texte( $tome_id ),
			self::oeuvre_texte( $tome_id )
		) . ( '' !== $formats ? ' : ' . $formats : ' !' );
		return trim( (string) preg_replace( '/\s{2,}/', ' ', self::elision( $titre ) ) );
	}

	/**
	 * Élision de l'article défini : « Le arc » → « L’arc », « le épisode » → « l’épisode ».
	 *
	 * @param string $texte Texte.
	 */
	public static function elision( string $texte ): string {
		$texte = (string) preg_replace( '/\b([Ll])e\s+(?=[aeiouyàâéèêëîïôûùAEIOUYÀÂÉÈÊËÎÏÔÛÙ])/u', '$1’', $texte );
		$texte = (string) preg_replace( '/\b([Dd])e\s+[Ll]es\s+/u', '$1es ', $texte );
		return (string) preg_replace( '/\b([Dd])e\s+[Ll]e\s+/u', '$1u ', $texte );
	}

	/**
	 * Contenu de l'annonce (blocs).
	 *
	 * @param int                 $tome_id Tome.
	 * @param string              $lien    Lien de lecture.
	 * @param array<string,mixed> $infos   nb_chapitres, speciaux (libellés), credits, sortie
	 *                                     (variante : parution, chapitres, voir titre()).
	 */
	public static function contenu( int $tome_id, string $lien, array $infos ): string {
		$oeuvre_id = yume_get_oeuvre_id( $tome_id );
		$oeuvre    = $oeuvre_id ? get_the_title( $oeuvre_id ) : '';
		$libelle   = yume_libelle_tome( $tome_id );
		$liens     = yume_liens_telechargement( $tome_id );
		$blocs     = array();

		$lien_oeuvre = $oeuvre_id ? '<a href="' . esc_url( (string) get_permalink( $oeuvre_id ) ) . '">' . esc_html( $oeuvre ) . '</a>' : esc_html( $oeuvre );
		$le_tome     = esc_html( self::elision( 'Le ' . mb_strtolower( mb_substr( $libelle, 0, 1 ) ) . mb_substr( $libelle, 1 ) ) );
		$sortie      = (array) ( $infos['sortie'] ?? array() );
		$parution    = (string) ( $sortie['parution'] ?? '' );
		$chapitres   = array_values( array_filter( array_map( 'intval', (array) ( $sortie['chapitres'] ?? array() ) ) ) );
		if ( 'en_cours' === $parution && $chapitres ) {
			// Première sortie d'un tome publié chapitre par chapitre.
			$intro = sprintf(
				/* translators: 1: libellé du tome (Tome 2), 2: œuvre (lien), 3: chapitres (Prologue, chapitres 1 à 3) */
				_n( '%1$s de %2$s : %3$s est disponible en lecture en ligne sur le site.', '%1$s de %2$s : %3$s sont disponibles en lecture en ligne sur le site.', count( $chapitres ), 'yume-core' ),
				esc_html( $libelle ),
				$lien_oeuvre,
				esc_html( self::libelle_sortie( $chapitres ) )
			) . ' ' . esc_html__( 'Les chapitres suivants paraîtront au fil de l’eau : suivez l’œuvre pour être prévenu de chaque sortie.', 'yume-core' );
		} elseif ( 'complet' === $parution ) {
			$intro = sprintf(
				/* translators: 1: Le tome 10, 2: œuvre (lien) */
				__( '%1$s de %2$s est désormais complet : tous ses chapitres sont à lire en ligne sur le site.', 'yume-core' ),
				$le_tome,
				$lien_oeuvre
			);
			if ( '' !== $liens['pdf'] || '' !== $liens['epub'] ) {
				$intro .= ' ' . __( 'Vous pouvez aussi le télécharger en PDF ou en EPUB.', 'yume-core' );
			}
		} else {
			$intro = sprintf(
				/* translators: 1: Le tome 10, 2: œuvre (lien) */
				__( '%1$s de %2$s est disponible en lecture en ligne sur le site.', 'yume-core' ),
				$le_tome,
				$lien_oeuvre
			);
			if ( '' !== $liens['pdf'] || '' !== $liens['epub'] ) {
				$intro .= ' ' . __( 'Vous pouvez aussi le télécharger en PDF ou en EPUB.', 'yume-core' );
			}
		}
		$blocs[] = self::paragraphe( $intro );

		// Première sortie d'un tome en cours : pas de « Au programme » (le tome n'est pas complet).
		$nb = 'en_cours' === $parution && $chapitres ? 0 : (int) ( $infos['nb_chapitres'] ?? 0 );
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
	 * @param array<string,mixed> $infos   nb_chapitres, speciaux, credits, sortie (variante, voir titre()).
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
			'post_title'    => self::titre( $tome_id, (array) ( $infos['sortie'] ?? array() ) ),
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

	/**
	 * Annonce « tome complet » d'un tome publié chapitre par chapitre : article publié tout de
	 * suite dans « Sorties » (« Le tome 2 de SukaMoka est complet : PDF et EPUB disponibles »),
	 * relié à l'œuvre, boutons Lire / PDF / EPUB, couverture du tome. Un seul par tome : s'il
	 * existe déjà, il est republié tel quel (jamais régénéré une fois paru).
	 *
	 * @param int $tome_id Tome (publié).
	 * @return int|\WP_Error ID de l'article.
	 */
	public static function complet( int $tome_id ) {
		$article_id = self::existant( $tome_id, self::META_COMPLET );
		$article    = $article_id ? get_post( $article_id ) : null;
		if ( $article && in_array( $article->post_status, array( 'publish', 'private' ), true ) ) {
			return $article_id;
		}
		$speciaux = array();
		$nb       = 0;
		foreach ( yume_get_chapitres( $tome_id ) as $chapitre ) {
			$nature = (string) get_post_meta( $chapitre->ID, 'yume_nature', true );
			if ( '' === $nature || 'chapitre' === $nature ) {
				++$nb;
			} else {
				$speciaux[] = yume_libelle_chapitre( (int) $chapitre->ID );
			}
		}
		$credits = get_post_meta( $tome_id, 'yume_credits', true );
		$lien    = (string) get_permalink( $tome_id );
		$donnees = array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => self::titre( $tome_id, array( 'parution' => 'complet' ) ),
			'post_content'  => self::contenu(
				$tome_id,
				$lien,
				array(
					'nb_chapitres' => $nb,
					'speciaux'     => $speciaux,
					'credits'      => is_array( $credits ) ? $credits : array(),
					'sortie'       => array( 'parution' => 'complet' ),
				)
			),
			'post_category' => array_filter( array( self::categorie() ) ),
		);
		if ( $article ) {
			$donnees['ID'] = $article_id;
		} else {
			$donnees['post_author'] = get_current_user_id() ? get_current_user_id() : (int) get_post_field( 'post_author', $tome_id );
		}
		$resultat = $article ? wp_update_post( wp_slash( $donnees ), true ) : wp_insert_post( wp_slash( $donnees ), true );
		if ( is_wp_error( $resultat ) ) {
			return $resultat;
		}
		$article_id = (int) $resultat;
		update_post_meta( $article_id, self::META_COMPLET, (string) $tome_id );
		self::noter_empreinte( $article_id );
		update_post_meta( $article_id, self::META_LIEN, $lien );
		self::lier_oeuvre( $article_id, yume_get_oeuvre_id( $tome_id ) );
		$couverture = yume_get_cover_id( $tome_id );
		if ( $couverture ) {
			set_post_thumbnail( $article_id, $couverture );
		}
		return $article_id;
	}

	/**
	 * Suit le statut du tome (transition_post_status) : un tome qui quitte le site (brouillon,
	 * en attente, privé, corbeille) remet son annonce parue ou programmée en brouillon ; à son
	 * retour en ligne (ou à sa programmation), cette même annonce est republiée plutôt qu'une
	 * nouvelle créée.
	 *
	 * @param string   $nouveau Nouveau statut.
	 * @param string   $ancien  Ancien statut.
	 * @param \WP_Post $post    Contenu.
	 */
	public static function suivre_tome( $nouveau, $ancien, $post ): void {
		if ( ! $post instanceof \WP_Post || 'yume_tome' !== $post->post_type || $nouveau === $ancien ) {
			return;
		}
		$en_ligne = array( 'publish', 'future' );
		if ( in_array( $nouveau, $en_ligne, true ) ) {
			if ( 'future' === $nouveau && 'publish' === $ancien ) {
				self::retirer( (int) $post->ID ); // Tome reprogrammé : l'annonce le suit.
			}
			self::remettre( $post, (string) $nouveau );
		} elseif ( in_array( $ancien, $en_ligne, true ) ) {
			self::retirer( (int) $post->ID );
		}
	}

	/**
	 * Remet en brouillon les annonces parues ou programmées d'un tome qui n'est plus en ligne
	 * (annonce de sortie et, s'il y en a une, annonce « tome complet »).
	 *
	 * @param int $tome_id Tome.
	 */
	private static function retirer( int $tome_id ): void {
		foreach ( array( self::META_TOME, self::META_COMPLET ) as $cle ) {
			self::retirer_article( self::existant( $tome_id, $cle ) );
		}
	}

	/**
	 * Remet en brouillon un article d'annonce paru ou programmé (date d'origine notée).
	 *
	 * @param int $article_id Article (0 : aucun).
	 */
	private static function retirer_article( int $article_id ): void {
		$article = $article_id ? get_post( $article_id ) : null;
		if ( ! $article || ! in_array( $article->post_status, array( 'publish', 'future' ), true ) ) {
			return;
		}
		update_post_meta(
			$article_id,
			self::META_RETIREE,
			array(
				'statut'   => $article->post_status,
				'date'     => $article->post_date,
				'date_gmt' => $article->post_date_gmt,
			)
		);
		$garder = self::empreinte_intacte( $article );
		wp_update_post(
			array(
				'ID'          => $article_id,
				'post_status' => 'draft',
			)
		);
		if ( $garder ) {
			self::noter_empreinte( $article_id );
		}
	}

	/**
	 * Le contenu de l'article est-il encore celui généré (non retouché à la main) ?
	 *
	 * @param \WP_Post $article Article.
	 */
	private static function empreinte_intacte( \WP_Post $article ): bool {
		return md5( (string) $article->post_content ) === (string) get_post_meta( $article->ID, self::META_EMPREINTE, true );
	}

	/**
	 * Enregistre l'empreinte du contenu actuel de l'article.
	 *
	 * @param int $article_id Article.
	 */
	private static function noter_empreinte( int $article_id ): void {
		update_post_meta( $article_id, self::META_EMPREINTE, md5( (string) get_post_field( 'post_content', $article_id, 'raw' ) ) );
	}

	/**
	 * Tome de retour en ligne (ou programmé) : son annonce retirée est republiée à sa date
	 * d'origine si elle était déjà parue, sinon à la date du tome.
	 *
	 * @param \WP_Post $tome   Tome.
	 * @param string   $statut publish ou future.
	 */
	private static function remettre( \WP_Post $tome, string $statut ): void {
		foreach ( array( self::META_TOME, self::META_COMPLET ) as $cle ) {
			self::remettre_article( $tome, $statut, self::existant( (int) $tome->ID, $cle ) );
		}
	}

	/**
	 * Republie un article d'annonce retiré avec son tome (voir remettre()).
	 *
	 * @param \WP_Post $tome       Tome.
	 * @param string   $statut     publish ou future.
	 * @param int      $article_id Article (0 : aucun).
	 */
	private static function remettre_article( \WP_Post $tome, string $statut, int $article_id ): void {
		$retiree = $article_id ? get_post_meta( $article_id, self::META_RETIREE, true ) : '';
		if ( ! is_array( $retiree ) ) {
			return;
		}
		delete_post_meta( $article_id, self::META_RETIREE );
		$article = get_post( $article_id );
		if ( ! $article || in_array( $article->post_status, array( 'publish', 'future', 'private' ), true ) ) {
			return; // Déjà republiée à la main.
		}
		$date     = (string) $tome->post_date;
		$date_gmt = (string) $tome->post_date_gmt;
		$gmt      = (string) ( $retiree['date_gmt'] ?? '' );
		if ( 'publish' === $statut && 'publish' === ( $retiree['statut'] ?? '' ) && '' !== $gmt && '0000-00-00 00:00:00' !== $gmt && strtotime( $gmt . ' UTC' ) <= time() ) {
			$date     = (string) $retiree['date'];
			$date_gmt = $gmt;
		}
		$lien   = (string) get_permalink( $tome );
		$garder = self::empreinte_intacte( $article );
		wp_update_post(
			wp_slash(
				array(
					'ID'            => $article_id,
					'post_status'   => $statut,
					'post_date'     => $date,
					'post_date_gmt' => $date_gmt,
					'edit_date'     => true,
					'post_content'  => self::remplacer_lien( (string) $article->post_content, (string) get_post_meta( $article_id, self::META_LIEN, true ), $lien ),
				)
			)
		);
		update_post_meta( $article_id, self::META_LIEN, $lien );
		if ( $garder ) {
			self::noter_empreinte( $article_id );
		}
	}
}
