<?php
/**
 * Balisage de blocs Gutenberg (contrat §9) produit par le convertisseur.
 *
 * Le balisage reproduit exactement la sortie « save » des blocs natifs (paragraphe,
 * séparateur, image, liste, titre, citation) pour que l'éditeur les reconnaisse comme
 * valides. Les illustrations non encore versées dans la médiathèque sont des blocs
 * core/image dont la source est le jeton {{yume-image:<clé>}} ; le module publication
 * les remplace par le bloc définitif (avec l'ID de la pièce jointe) via remplacer_images().
 *
 * Aucune fonction WordPress (sérialisation des attributs identique à serialize_block_attributes()).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Fabrique de blocs sérialisés.
 */
final class Blocks {

	/** Préfixe du jeton d'image. */
	public const JETON = '{{yume-image:';

	/** Expression d'un bloc image portant un jeton (groupe 1 : clé). */
	public const MOTIF_IMAGE = '/<!-- wp:image (?:\{[^\n]*?\} )?-->\n<figure[^>]*><img src="\{\{yume-image:([a-z0-9\-]+)\}\}"[^>]*\/>(?:<figcaption[^>]*>.*?<\/figcaption>)?<\/figure>\n<!-- \/wp:image -->/s';

	/**
	 * Sérialise des attributs de bloc comme serialize_block_attributes() de WordPress.
	 *
	 * @param array<string,mixed> $attrs Attributs.
	 */
	public static function attributs( array $attrs ): string {
		// Sans WordPress (outil en ligne de commande) : json_encode, comme wp_json_encode().
		$json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$json = (string) preg_replace( '/--/', '\\u002d\\u002d', $json );
		$json = (string) preg_replace( '/</', '\\u003c', $json );
		$json = (string) preg_replace( '/>/', '\\u003e', $json );
		$json = (string) preg_replace( '/&/', '\\u0026', $json );
		return (string) preg_replace( '/\\\\"/', '\\u0022', $json );
	}

	/**
	 * Échappe un texte pour le contenu d'un élément.
	 *
	 * @param string $texte Texte brut.
	 */
	public static function texte( string $texte ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $texte );
	}

	/**
	 * Échappe une valeur d'attribut HTML.
	 *
	 * @param string $valeur Valeur brute.
	 */
	public static function attr( string $valeur ): string {
		return htmlspecialchars( $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Commentaire d'ouverture.
	 *
	 * @param string              $nom   Nom court (paragraph…) ou complet (yume/…).
	 * @param array<string,mixed> $attrs Attributs.
	 */
	private static function ouvrir( string $nom, array $attrs = array() ): string {
		return '<!-- wp:' . $nom . ( $attrs ? ' ' . self::attributs( $attrs ) : '' ) . ' -->';
	}

	/**
	 * Commentaire de fermeture.
	 *
	 * @param string $nom Nom du bloc.
	 */
	private static function fermer( string $nom ): string {
		return '<!-- /wp:' . $nom . ' -->';
	}

	/**
	 * Paragraphe (core/paragraph). Le HTML en ligne doit déjà être assaini.
	 *
	 * @param string   $html    Contenu en ligne.
	 * @param string[] $classes Classes Yume (yn-dialogue, yn-thought, yn-center).
	 * @param bool     $centre  Alignement centré (attribut « align » du contrat §9).
	 */
	public static function paragraphe( string $html, array $classes = array(), bool $centre = false ): string {
		$classes = array_values( array_unique( array_filter( $classes ) ) );
		$attrs   = array();
		$html_cl = array();
		if ( $centre ) {
			$attrs['align'] = 'center';
			$html_cl[]      = 'has-text-align-center';
		}
		if ( $classes ) {
			$attrs['className'] = implode( ' ', $classes );
			$html_cl            = array_merge( $html_cl, $classes );
		}
		$balise = $html_cl ? '<p class="' . self::attr( implode( ' ', $html_cl ) ) . '">' : '<p>';
		return self::ouvrir( 'paragraph', $attrs ) . "\n" . $balise . $html . '</p>' . "\n" . self::fermer( 'paragraph' );
	}

	/**
	 * Séparateur de scène (core/separator, classe yn-scene-break).
	 */
	public static function separateur(): string {
		return self::ouvrir( 'separator', array( 'className' => 'yn-scene-break' ) ) . "\n"
			. '<hr class="wp-block-separator has-alpha-channel-opacity yn-scene-break"/>' . "\n"
			. self::fermer( 'separator' );
	}

	/**
	 * Titre intermédiaire (core/heading, niveaux 2 à 4).
	 *
	 * @param string $html   Contenu en ligne.
	 * @param int    $niveau Niveau.
	 */
	public static function titre( string $html, int $niveau = 2 ): string {
		$niveau = max( 2, min( 4, $niveau ) );
		$attrs  = 2 === $niveau ? array() : array( 'level' => $niveau );
		return self::ouvrir( 'heading', $attrs ) . "\n" . '<h' . $niveau . ' class="wp-block-heading">' . $html . '</h' . $niveau . '>' . "\n" . self::fermer( 'heading' );
	}

	/**
	 * Liste (core/list + core/list-item).
	 *
	 * @param array<int,string|array{html:string,id?:string}> $elements Éléments (HTML en ligne, ou tableau avec ancre).
	 * @param bool                                            $ordonnee Liste numérotée.
	 * @param string                                          $classe   Classe de la liste (ex. yn-notes).
	 */
	public static function liste( array $elements, bool $ordonnee = false, string $classe = '' ): string {
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
			$html    = is_array( $element ) ? (string) $element['html'] : (string) $element;
			$id      = is_array( $element ) && ! empty( $element['id'] ) ? ' id="' . self::attr( (string) $element['id'] ) . '"' : '';
			$items[] = self::ouvrir( 'list-item' ) . "\n" . '<li' . $id . '>' . $html . '</li>' . "\n" . self::fermer( 'list-item' );
		}
		return self::ouvrir( 'list', $attrs ) . "\n"
			. '<' . $balise . ' class="' . self::attr( trim( 'wp-block-list ' . $classe ) ) . '">' . implode( "\n\n", $items ) . '</' . $balise . '>' . "\n"
			. self::fermer( 'list' );
	}

	/**
	 * Citation (core/quote) contenant des paragraphes.
	 *
	 * @param string[] $paragraphes HTML en ligne de chaque paragraphe.
	 */
	public static function citation( array $paragraphes ): string {
		$blocs = array();
		foreach ( $paragraphes as $html ) {
			$blocs[] = self::paragraphe( $html );
		}
		return self::ouvrir( 'quote' ) . "\n" . '<blockquote class="wp-block-quote">' . implode( "\n\n", $blocs ) . '</blockquote>' . "\n" . self::fermer( 'quote' );
	}

	/**
	 * Illustration en attente : bloc core/image dont la source est le jeton de l'image.
	 *
	 * @param string $cle Clé de l'image (Result::$images).
	 * @param string $alt Texte alternatif.
	 */
	public static function image_jeton( string $cle, string $alt = '' ): string {
		return self::image( 0, self::JETON . $cle . '}}', $alt );
	}

	/**
	 * Illustration (core/image, taille « large », classe yn-illustration).
	 *
	 * @param int    $id      ID de la pièce jointe (0 si inconnu).
	 * @param string $url     URL de l'image.
	 * @param string $alt     Texte alternatif.
	 * @param string $legende Légende (HTML en ligne assaini), facultative.
	 */
	public static function image( int $id, string $url, string $alt = '', string $legende = '' ): string {
		$attrs = array();
		if ( $id > 0 ) {
			$attrs['id'] = $id;
		}
		$attrs['sizeSlug']        = 'large';
		$attrs['linkDestination'] = 'none';
		$attrs['className']       = 'yn-illustration';
		$img                      = '<img src="' . self::attr( $url ) . '" alt="' . self::attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . '/>';
		$figure                   = '<figure class="wp-block-image size-large yn-illustration">' . $img
			. ( '' !== $legende ? '<figcaption class="wp-element-caption">' . $legende . '</figcaption>' : '' ) . '</figure>';
		return self::ouvrir( 'image', $attrs ) . "\n" . $figure . "\n" . self::fermer( 'image' );
	}

	/**
	 * Appel de note (contrat §9).
	 *
	 * @param int $n Numéro de la note dans le chapitre.
	 */
	public static function appel_note( int $n ): string {
		return '<sup class="yn-note"><a href="#yn-note-' . $n . '" id="yn-ref-' . $n . '">' . $n . '</a></sup>';
	}

	/**
	 * Liste finale des notes d'un chapitre (core/list ordonnée, classe yn-notes).
	 *
	 * @param array<int,string> $notes Numéro => HTML en ligne de la note.
	 */
	public static function notes( array $notes ): string {
		$elements = array();
		foreach ( $notes as $n => $html ) {
			$elements[] = array(
				'html' => $html . ' <a href="#yn-ref-' . (int) $n . '" aria-label="Retour au texte">↩</a>',
				'id'   => 'yn-note-' . (int) $n,
			);
		}
		return self::liste( $elements, true, 'yn-notes' );
	}

	/**
	 * Assemble des blocs sérialisés (séparés par une ligne vide, comme l'éditeur).
	 *
	 * @param string[] $blocs Blocs.
	 */
	public static function assembler( array $blocs ): string {
		return implode( "\n\n", array_filter( $blocs, static fn( $b ) => '' !== $b ) );
	}

	/**
	 * Clés des images référencées par des jetons, dans l'ordre d'apparition.
	 *
	 * @param string $blocs Balisage de blocs.
	 * @return string[]
	 */
	public static function cles_images( string $blocs ): array {
		preg_match_all( self::MOTIF_IMAGE, $blocs, $m );
		return array_values( array_unique( $m[1] ?? array() ) );
	}

	/**
	 * Remplace chaque bloc image à jeton par le résultat d'un rappel.
	 *
	 * Le rappel reçoit la clé et le texte alternatif, et renvoie le balisage du bloc de
	 * remplacement (chaîne vide : le bloc est retiré, avec la ligne vide qui le sépare).
	 *
	 * @param string   $blocs   Balisage de blocs.
	 * @param callable $rappel  function( string $cle, string $alt ): string.
	 */
	public static function remplacer_images( string $blocs, callable $rappel ): string {
		$sortie = (string) preg_replace_callback(
			self::MOTIF_IMAGE,
			static function ( array $m ) use ( $rappel ): string {
				$alt = preg_match( '/ alt="([^"]*)"/', $m[0], $a ) ? html_entity_decode( $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				return (string) $rappel( $m[1], $alt );
			},
			$blocs
		);
		$sortie = (string) preg_replace( "/\n{3,}/", "\n\n", $sortie );
		return trim( $sortie );
	}
}
