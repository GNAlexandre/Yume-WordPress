<?php
/**
 * Unités de mise en page d'un chapitre (docs/10-mise-en-page-chapitre.md, lot E).
 *
 * Le contenu d'un chapitre reste une suite de blocs Gutenberg standard produits par
 * Import\Blocks. La vue « Mettre en page » travaille sur une liste d'unités dérivée de
 * parse_blocks() :
 *
 *   { index, type: "paragraphe", style: recit|dialogue|pensee|centre, html }
 *   { index, type: "separateur" }
 *   { index, type: "intertitre", niveau: 2…4, html }
 *   { index, type: "autre", apercu, rendu }   image, galerie, notes, bloc inconnu : intouchable
 *
 * - `html` ne garde que les balises en ligne autorisées (nettoyer()) ; pour un dialogue, le tiret
 *   de tête est retiré (il est posé par le style, jamais par la frappe) et remis à l'enregistrement
 *   par Texte::normaliser_dialogue( $html, true ).
 * - appliquer() reconstruit le contenu : une unité inchangée recopie son bloc d'origine à
 *   l'identique ; une unité modifiée ou nouvelle est reconstruite par Import\Blocks
 *   (paragraphe(), separateur(), titre()) après Texte::typographie() et les apostrophes
 *   typographiques ; les blocs « autre » sont recopiés tels quels, dans le même ordre.
 *
 * @package Yume\Core
 */

namespace Yume\Core\MiseEnPage;

use Yume\Core\Import\Blocks;
use Yume\Core\Import\Texte;

defined( 'ABSPATH' ) || exit;

/**
 * Découpage d'un chapitre en unités et reconstruction des blocs.
 */
final class Unites {

	/** Styles d'un paragraphe : style => classe Yume (récit : aucune). */
	public const STYLES = array(
		'recit'    => '',
		'dialogue' => 'yn-dialogue',
		'pensee'   => 'yn-thought',
		'centre'   => 'yn-center',
	);

	/** Types d'unité. */
	public const TYPES = array( 'paragraphe', 'separateur', 'intertitre', 'autre' );

	/** Longueur maximale du HTML d'une unité (caractères). */
	public const HTML_MAX = 50000;

	/** Nombre maximal d'unités envoyées. */
	public const UNITES_MAX = 20000;

	/**
	 * Balises en ligne autorisées dans le texte d'une unité (wp_kses). Le cahier des charges cite
	 * em, strong, u, br et l'appel de note (sup.yn-note et son lien) ; sub, sup et les liens
	 * externes, produits par l'import (Import\Inline), sont gardés aussi pour ne rien perdre en
	 * changeant le style d'un paragraphe.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function balises(): array {
		return array(
			'em'     => array(),
			'strong' => array(),
			'u'      => array(),
			'br'     => array(),
			'sub'    => array(),
			'sup'    => array( 'class' => true ),
			'a'      => array(
				'href' => true,
				'id'   => true,
			),
		);
	}

	/**
	 * Unités d'un contenu, avec leurs données internes (bloc d'origine sérialisé « bloc »,
	 * classes supplémentaires « classes »).
	 *
	 * @param string $contenu Contenu du chapitre (blocs).
	 * @return array<int,array<string,mixed>>
	 */
	public static function decouper( string $contenu ): array {
		$unites = array();
		foreach ( parse_blocks( $contenu ) as $bloc ) {
			$unite = self::unite( $bloc );
			if ( null === $unite ) {
				continue;
			}
			$unite['index'] = count( $unites );
			$unites[]       = $unite;
		}
		return $unites;
	}

	/**
	 * Unités telles qu'envoyées à la vue (sans les données internes).
	 *
	 * @param array<int,array<string,mixed>> $unites Unités de decouper().
	 * @return array<int,array<string,mixed>>
	 */
	public static function publiques( array $unites ): array {
		return array_map(
			static function ( array $u ): array {
				unset( $u['bloc'], $u['classes'] );
				return $u;
			},
			$unites
		);
	}

	/**
	 * Unité d'un bloc de premier niveau (null : blanc entre deux blocs).
	 *
	 * @param array<string,mixed> $bloc Bloc de parse_blocks().
	 * @return array<string,mixed>|null
	 */
	private static function unite( array $bloc ): ?array {
		$nom   = (string) ( $bloc['blockName'] ?? '' );
		$attrs = is_array( $bloc['attrs'] ?? null ) ? $bloc['attrs'] : array();
		$inner = (string) ( $bloc['innerHTML'] ?? '' );
		if ( '' === $nom ) {
			if ( '' === trim( $inner ) ) {
				return null;
			}
			return self::autre( $inner, __( 'HTML libre', 'yume-core' ), $inner );
		}
		$serialise = serialize_block( $bloc );
		if ( 'core/paragraph' === $nom && ! array_diff( array_keys( $attrs ), array( 'className', 'align' ) )
			&& preg_match( '#^\s*<p\b[^>]*>(.*)</p>\s*$#s', $inner, $m ) ) {
			$classes = self::classes( (string) ( $attrs['className'] ?? '' ) );
			$style   = 'recit';
			if ( in_array( 'yn-dialogue', $classes, true ) ) {
				$style = 'dialogue';
			} elseif ( in_array( 'yn-thought', $classes, true ) ) {
				$style = 'pensee';
			} elseif ( in_array( 'yn-center', $classes, true ) || 'center' === ( $attrs['align'] ?? '' ) ) {
				$style = 'centre';
			}
			$html = self::nettoyer( $m[1] );
			return array(
				'type'    => 'paragraphe',
				'style'   => $style,
				'html'    => 'dialogue' === $style ? self::sans_tiret( $html ) : $html,
				'bloc'    => $serialise,
				'classes' => array_values( array_diff( $classes, array_filter( self::STYLES ) ) ),
			);
		}
		if ( 'core/heading' === $nom && ! array_diff( array_keys( $attrs ), array( 'level' ) )
			&& preg_match( '#^\s*<h([1-6])\b[^>]*>(.*)</h\1>\s*$#s', $inner, $m ) ) {
			return array(
				'type'   => 'intertitre',
				'niveau' => max( 2, min( 4, (int) ( $attrs['level'] ?? 2 ) ) ),
				'html'   => self::nettoyer( $m[2] ),
				'bloc'   => $serialise,
			);
		}
		if ( 'core/separator' === $nom ) {
			return array(
				'type' => 'separateur',
				'bloc' => $serialise,
			);
		}
		return self::autre( $serialise, self::apercu( $nom, $attrs, $inner, count( (array) ( $bloc['innerBlocks'] ?? array() ) ) ), wp_kses_post( render_block( $bloc ) ) );
	}

	/**
	 * Unité « autre » (intouchable).
	 *
	 * @param string $serialise Bloc d'origine sérialisé.
	 * @param string $apercu    Description courte.
	 * @param string $rendu     Rendu HTML (assaini) affiché tel quel dans la vue.
	 * @return array<string,mixed>
	 */
	private static function autre( string $serialise, string $apercu, string $rendu ): array {
		return array(
			'type'   => 'autre',
			'apercu' => $apercu,
			'rendu'  => wp_kses_post( $rendu ),
			'bloc'   => $serialise,
		);
	}

	/**
	 * Description courte d'un bloc intouchable (« Image : Carte de la colline », « Notes (3) »).
	 *
	 * @param string $nom   Nom du bloc.
	 * @param array  $attrs Attributs.
	 * @param string $inner HTML du bloc.
	 * @param int    $n     Nombre de blocs imbriqués (éléments d'une liste).
	 */
	private static function apercu( string $nom, array $attrs, string $inner, int $n ): string {
		switch ( $nom ) {
			case 'core/image':
				$alt = preg_match( '/\balt="([^"]*)"/', $inner, $m ) ? trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
				/* translators: %s : texte alternatif */
				return '' !== $alt ? sprintf( __( 'Image : %s', 'yume-core' ), $alt ) : __( 'Image', 'yume-core' );
			case 'core/gallery':
				return __( 'Galerie d’images', 'yume-core' );
			case 'core/list':
				if ( in_array( 'yn-notes', self::classes( (string) ( $attrs['className'] ?? '' ) ), true ) ) {
					/* translators: %d : nombre de notes */
					return sprintf( _n( 'Notes (%d)', 'Notes (%d)', $n, 'yume-core' ), $n );
				}
				return __( 'Liste', 'yume-core' );
			case 'core/quote':
				return __( 'Citation', 'yume-core' );
			case 'core/paragraph':
				return __( 'Paragraphe mis en forme dans l’éditeur', 'yume-core' );
			case 'core/heading':
				return __( 'Titre mis en forme dans l’éditeur', 'yume-core' );
		}
		/* translators: %s : nom technique du bloc */
		return sprintf( __( 'Bloc %s', 'yume-core' ), $nom );
	}

	/**
	 * Classes d'un attribut className.
	 *
	 * @param string $classes Classes séparées par des espaces.
	 * @return string[]
	 */
	private static function classes( string $classes ): array {
		$liste = preg_split( '/\s+/', trim( $classes ) );
		return array_values( array_filter( false === $liste ? array() : $liste ) );
	}

	/**
	 * Nettoie le HTML en ligne d'une unité : scripts et styles retirés avec leur contenu, <b> et
	 * <i> convertis en <strong> et <em>, wp_kses() avec balises(), lien gardé seulement vers une
	 * note (#yn-note-N) ou une adresse http(s), classe de sup limitée à yn-note, id de lien limité
	 * à yn-ref-N, retours à la ligne réduits à une espace.
	 *
	 * @param string $html HTML saisi.
	 */
	public static function nettoyer( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|template|iframe|object|noscript|textarea|select)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$html = (string) preg_replace( '#<(/?)b(?=[\s>/])[^>]*>#i', '<$1strong>', $html );
		$html = (string) preg_replace( '#<(/?)i(?=[\s>/])[^>]*>#i', '<$1em>', $html );
		$html = (string) preg_replace( '/[\r\n\t]+/', ' ', $html );
		$html = (string) preg_replace( '#\s*(<br\s*/?>)\s*#i', '$1', $html );
		$html = force_balance_tags( wp_kses( $html, self::balises(), array( 'http', 'https' ) ) );
		$html = (string) preg_replace_callback(
			'#<sup\b([^>]*)>#i',
			static function ( array $m ): string {
				return preg_match( '/\bclass="yn-note"/', $m[1] ) ? '<sup class="yn-note">' : '<sup>';
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			static function ( array $m ): string {
				$href = preg_match( '/\bhref="([^"]*)"/', $m[1], $h ) ? html_entity_decode( $h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				$id   = preg_match( '/\bid="(yn-ref-\d+)"/', $m[1], $i ) ? $i[1] : '';
				if ( ! preg_match( '/^#yn-note-\d+$/', $href ) && ! preg_match( '#^https?://#i', $href ) ) {
					return $m[2];
				}
				return '<a href="' . esc_attr( $href ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . $m[2] . '</a>';
			},
			$html
		);
		$html = trim( (string) preg_replace( '/ {2,}/', ' ', $html ) );
		// Sauts de ligne en tête ou en fin de paragraphe : sans effet à la lecture, retirés.
		return trim( (string) preg_replace( '#^(?:<br\s*/?>\s*)+|(?:\s*<br\s*/?>)+$#i', '', $html ) );
	}

	/**
	 * Retire le tiret de dialogue de tête (dans la première portion de texte, éventuellement
	 * dans une balise ouvrante) et les blancs qui le suivent.
	 *
	 * @param string $html HTML en ligne.
	 */
	public static function sans_tiret( string $html ): string {
		$jetons = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( false === $jetons ) {
			return $html;
		}
		$blancs = '[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]';
		foreach ( $jetons as $i => $jeton ) {
			if ( '<' === $jeton[0] ) {
				continue;
			}
			$sans = (string) preg_replace( '/^' . $blancs . '+/u', '', $jeton );
			if ( '' === $sans ) {
				continue;
			}
			if ( in_array( mb_substr( $sans, 0, 1, 'UTF-8' ), Texte::TIRETS, true ) ) {
				$jetons[ $i ] = (string) preg_replace( '/^' . $blancs . '+/u', '', mb_substr( $sans, 1, null, 'UTF-8' ) );
			}
			break;
		}
		return implode( '', $jetons );
	}

	/**
	 * Apostrophes typographiques (’) dans le texte, jamais dans les balises.
	 *
	 * @param string $html HTML en ligne.
	 */
	public static function apostrophes( string $html ): string {
		$jetons = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( false === $jetons ) {
			return $html;
		}
		foreach ( $jetons as $i => $jeton ) {
			if ( '<' !== $jeton[0] ) {
				$jetons[ $i ] = str_replace( array( "'", '&#039;', '&#39;', '&apos;' ), '’', $jeton );
			}
		}
		return implode( '', $jetons );
	}

	/**
	 * Typographie d'un texte reconstruit : apostrophes, puis Texte::typographie() (insécables
	 * avant « : ; ? ! » et à l'intérieur des guillemets).
	 *
	 * @param string $html HTML en ligne nettoyé.
	 */
	public static function typographie( string $html ): string {
		return Texte::typographie( self::apostrophes( $html ) );
	}

	/**
	 * Le texte d'un HTML en ligne est-il vide ?
	 *
	 * @param string $html HTML en ligne.
	 */
	public static function vide( string $html ): bool {
		return '' === trim( str_replace( array( "\u{00A0}", "\u{202F}" ), ' ', Texte::texte( $html ) ) );
	}

	/**
	 * Erreur 400 d'une unité invalide.
	 *
	 * @param string $message Message.
	 * @param int    $rang    Rang de l'unité dans l'envoi.
	 */
	private static function erreur( string $message, int $rang ): \WP_Error {
		/* translators: 1: rang de l'unité (1, 2…), 2: motif */
		return new \WP_Error( 'yume_mise_en_page_unite', sprintf( __( 'Unité %1$d refusée : %2$s', 'yume-core' ), $rang + 1, $message ), array( 'status' => 400 ) );
	}

	/**
	 * Applique les unités saisies au contenu d'origine.
	 *
	 * Chaque unité saisie : { index (unité d'origine, ou null pour une unité nouvelle : moitié
	 * d'un paragraphe scindé, séparateur inséré), type, style, html, niveau }. Une unité d'origine
	 * absente est supprimée (fusion, paragraphe vide) ; les unités « autre » doivent toutes être
	 * présentes, dans le même ordre, avec leur type. Un paragraphe ou intertitre nouveau ou modifié
	 * dont le texte est vide n'est pas écrit (un paragraphe vide d'origine, inchangé, est gardé).
	 *
	 * @param string $contenu Contenu d'origine (blocs).
	 * @param mixed  $saisie  Unités reçues.
	 * @return array{contenu:string,modifies:int}|\WP_Error 400 yume_mise_en_page_unite.
	 */
	public static function appliquer( string $contenu, $saisie ) {
		if ( ! is_array( $saisie ) || ! array_is_list( $saisie ) || count( $saisie ) > self::UNITES_MAX ) {
			return new \WP_Error( 'yume_mise_en_page_unite', __( 'La liste des unités est invalide.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$origine  = self::decouper( $contenu );
		$autres   = array_keys( array_filter( $origine, static fn( array $u ): bool => 'autre' === $u['type'] ) );
		$vus      = array();
		$autres_v = array();
		$blocs    = array();
		$modifies = 0;
		foreach ( $saisie as $rang => $u ) {
			if ( ! is_array( $u ) ) {
				return self::erreur( __( 'format inconnu.', 'yume-core' ), $rang );
			}
			$type  = is_string( $u['type'] ?? null ) ? $u['type'] : '';
			$index = $u['index'] ?? null;
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return self::erreur( __( 'type inconnu.', 'yume-core' ), $rang );
			}
			if ( null !== $index && ( ! is_int( $index ) && ! ( is_string( $index ) && ctype_digit( $index ) ) ) ) {
				return self::erreur( __( 'index invalide.', 'yume-core' ), $rang );
			}
			$index = null === $index ? null : (int) $index;
			if ( null !== $index && ( ! isset( $origine[ $index ] ) || isset( $vus[ $index ] ) ) ) {
				return self::erreur( __( 'index inconnu ou répété.', 'yume-core' ), $rang );
			}
			$avant = null === $index ? null : $origine[ $index ];
			if ( null !== $index ) {
				$vus[ $index ] = true;
			}
			if ( 'autre' === $type || ( $avant && 'autre' === $avant['type'] ) ) {
				if ( ! $avant || 'autre' !== $avant['type'] || 'autre' !== $type ) {
					return self::erreur( __( 'une image, une galerie ou des notes ne changent pas de type.', 'yume-core' ), $rang );
				}
				$autres_v[] = $index;
				$blocs[]    = (string) $avant['bloc'];
				continue;
			}
			if ( 'separateur' === $type ) {
				if ( $avant && 'separateur' === $avant['type'] ) {
					$blocs[] = (string) $avant['bloc'];
				} else {
					$blocs[] = Blocks::separateur();
					++$modifies;
				}
				continue;
			}
			$html = $u['html'] ?? '';
			if ( ! is_string( $html ) || strlen( $html ) > self::HTML_MAX || str_contains( $html, '<!--' ) || str_contains( $html, '-->' ) ) {
				return self::erreur( __( 'HTML refusé.', 'yume-core' ), $rang );
			}
			$html = self::nettoyer( $html );
			if ( 'intertitre' === $type ) {
				$niveau = isset( $u['niveau'] ) && is_numeric( $u['niveau'] ) ? max( 2, min( 4, (int) $u['niveau'] ) ) : 3;
				if ( $avant && 'intertitre' === $avant['type'] && $niveau === $avant['niveau'] && $html === $avant['html'] ) {
					$blocs[] = (string) $avant['bloc'];
					continue;
				}
				if ( self::vide( $html ) ) {
					// Intertitre vidé : retiré (compté s'il existait).
					$modifies += $avant ? 1 : 0;
					continue;
				}
				$blocs[] = Blocks::titre( self::typographie( self::sans_tiret( $html ) ), $niveau );
				++$modifies;
				continue;
			}
			$style = is_string( $u['style'] ?? null ) ? $u['style'] : '';
			if ( ! isset( self::STYLES[ $style ] ) ) {
				return self::erreur( __( 'style inconnu.', 'yume-core' ), $rang );
			}
			if ( $avant && 'paragraphe' === $avant['type'] && $style === $avant['style'] && $html === $avant['html'] ) {
				$blocs[] = (string) $avant['bloc'];
				continue;
			}
			if ( self::vide( $html ) ) {
				// Paragraphe nouveau ou vidé : jamais de bloc vide (compté s'il existait).
				$modifies += $avant ? 1 : 0;
				continue;
			}
			$classes = $avant && isset( $avant['classes'] ) ? (array) $avant['classes'] : array();
			if ( 'dialogue' === $style ) {
				$html = Texte::normaliser_dialogue( $html, true );
			}
			$html = self::typographie( $html );
			if ( '' !== self::STYLES[ $style ] ) {
				array_unshift( $classes, self::STYLES[ $style ] );
			}
			$blocs[] = Blocks::paragraphe( $html, $classes, 'centre' === $style );
			++$modifies;
		}
		if ( $autres_v !== $autres ) {
			return new \WP_Error( 'yume_mise_en_page_unite', __( 'Les images, galeries et notes ne peuvent être ni déplacées ni supprimées.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$modifies += count( $origine ) - count( $vus );
		return array(
			'contenu'  => Blocks::assembler( $blocs ),
			'modifies' => $modifies,
		);
	}
}
