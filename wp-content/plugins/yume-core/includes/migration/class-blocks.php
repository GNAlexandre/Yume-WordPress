<?php
/**
 * Fabrique de balisage de blocs Gutenberg (format du contrat §9) pour les contenus migrés.
 *
 * Aucune dépendance à la base de données. Fonction WordPress utilisée :
 * serialize_block_attributes (sérialisation pure des attributs).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Construction de blocs sérialisés.
 */
final class Blocks {

	/**
	 * Commentaire d'ouverture d'un bloc.
	 *
	 * @param string $nom   Nom court (paragraph, separator…) ou complet (yume/…).
	 * @param array  $attrs Attributs.
	 */
	private static function ouvrir( string $nom, array $attrs = array() ): string {
		$nom = str_starts_with( $nom, 'core/' ) ? substr( $nom, 5 ) : $nom;
		return '<!-- wp:' . $nom . ( $attrs ? ' ' . serialize_block_attributes( $attrs ) : '' ) . ' -->';
	}

	/**
	 * Commentaire de fermeture d'un bloc.
	 *
	 * @param string $nom Nom du bloc.
	 */
	private static function fermer( string $nom ): string {
		$nom = str_starts_with( $nom, 'core/' ) ? substr( $nom, 5 ) : $nom;
		return '<!-- /wp:' . $nom . ' -->';
	}

	/**
	 * Paragraphe. Contenu HTML en ligne déjà nettoyé.
	 *
	 * @param string   $html    Contenu du paragraphe.
	 * @param string[] $classes Classes (yn-dialogue, yn-thought, yn-center…).
	 * @param string   $align   '' ou 'center'.
	 */
	public static function paragraphe( string $html, array $classes = array(), string $align = '' ): string {
		$attrs   = array();
		$classes = array_values( array_unique( array_filter( $classes ) ) );
		$html_cl = array();
		if ( 'center' === $align ) {
			$attrs['align'] = 'center';
			$html_cl[]      = 'has-text-align-center';
		}
		if ( $classes ) {
			$attrs['className'] = implode( ' ', $classes );
			$html_cl            = array_merge( $html_cl, $classes );
		}
		$ouvrante = $html_cl ? '<p class="' . Html::attr( implode( ' ', $html_cl ) ) . '">' : '<p>';
		return self::ouvrir( 'paragraph', $attrs ) . "\n" . $ouvrante . $html . '</p>' . "\n" . self::fermer( 'paragraph' );
	}

	/**
	 * Titre (h2 à h4).
	 *
	 * @param string $html   Contenu en ligne.
	 * @param int    $niveau Niveau (2 à 4).
	 */
	public static function titre( string $html, int $niveau = 2 ): string {
		$niveau = max( 2, min( 4, $niveau ) );
		$attrs  = 2 === $niveau ? array() : array( 'level' => $niveau );
		return self::ouvrir( 'heading', $attrs ) . "\n" . '<h' . $niveau . ' class="wp-block-heading">' . $html . '</h' . $niveau . '>' . "\n" . self::fermer( 'heading' );
	}

	/**
	 * Séparateur de scène.
	 *
	 * @param string $classe Classe (yn-scene-break par défaut).
	 */
	public static function separateur( string $classe = 'yn-scene-break' ): string {
		$attrs = '' !== $classe ? array( 'className' => $classe ) : array();
		$cl    = trim( 'wp-block-separator has-alpha-channel-opacity ' . $classe );
		return self::ouvrir( 'separator', $attrs ) . "\n" . '<hr class="' . Html::attr( $cl ) . '"/>' . "\n" . self::fermer( 'separator' );
	}

	/**
	 * Image (illustration) : taille « large », identifiant de pièce jointe si connu.
	 *
	 * @param int    $id      ID de la pièce jointe (0 si inconnu).
	 * @param string $url     URL de l'image.
	 * @param string $alt     Texte alternatif.
	 * @param string $classe  Classe du bloc (yn-illustration par défaut).
	 * @param string $legende Légende (HTML en ligne nettoyé), facultative.
	 */
	public static function image( int $id, string $url, string $alt = '', string $classe = 'yn-illustration', string $legende = '' ): string {
		$attrs = array();
		if ( $id > 0 ) {
			$attrs['id'] = $id;
		}
		$attrs['sizeSlug']        = 'large';
		$attrs['linkDestination'] = 'none';
		if ( '' !== $classe ) {
			$attrs['className'] = $classe;
		}
		$img  = '<img src="' . Html::attr( $url ) . '" alt="' . Html::attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . '/>';
		$fig  = '<figure class="' . Html::attr( trim( 'wp-block-image size-large ' . $classe ) ) . '">' . $img;
		$fig .= '' !== $legende ? '<figcaption class="wp-element-caption">' . $legende . '</figcaption>' : '';
		$fig .= '</figure>';
		return self::ouvrir( 'image', $attrs ) . "\n" . $fig . "\n" . self::fermer( 'image' );
	}

	/**
	 * Liste (éléments HTML en ligne déjà nettoyés), au format core/list + core/list-item.
	 *
	 * @param string[] $elements Éléments.
	 * @param string   $classe   Classe de la liste.
	 * @param bool     $ordonnee Liste numérotée.
	 */
	public static function liste( array $elements, string $classe = '', bool $ordonnee = false ): string {
		$attrs = array();
		if ( $ordonnee ) {
			$attrs['ordered'] = true;
		}
		if ( '' !== $classe ) {
			$attrs['className'] = $classe;
		}
		$balise = $ordonnee ? 'ol' : 'ul';
		$items  = array();
		foreach ( $elements as $element ) {
			$items[] = self::ouvrir( 'list-item' ) . "\n" . '<li>' . $element . '</li>' . "\n" . self::fermer( 'list-item' );
		}
		return self::ouvrir( 'list', $attrs ) . "\n" . '<' . $balise . ' class="' . Html::attr( trim( 'wp-block-list ' . $classe ) ) . '">'
			. implode( "\n\n", $items ) . '</' . $balise . '>' . "\n" . self::fermer( 'list' );
	}

	/**
	 * Bloc dynamique sans contenu (<!-- wp:yume/xxx /-->).
	 *
	 * @param string $nom   Nom complet (yume/library-grid…).
	 * @param array  $attrs Attributs.
	 */
	public static function dynamique( string $nom, array $attrs = array() ): string {
		return '<!-- wp:' . $nom . ( $attrs ? ' ' . serialize_block_attributes( $attrs ) : '' ) . ' /-->';
	}

	/**
	 * Assemble des blocs sérialisés (séparés par une ligne vide, comme l'éditeur).
	 *
	 * @param string[] $blocs Blocs.
	 */
	public static function assembler( array $blocs ): string {
		return implode( "\n\n", array_filter( $blocs, static fn( $b ) => '' !== $b ) );
	}
}
