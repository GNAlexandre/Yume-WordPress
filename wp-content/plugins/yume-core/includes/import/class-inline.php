<?php
/**
 * Construction du HTML en ligne d'un paragraphe à partir de segments de texte formatés
 * (runs Word ou nœuds EPUB).
 *
 * - Fusion des segments contigus de même mise en forme.
 * - Imbrication minimale des balises (<em>je <strong>dois</strong> partir</em>).
 * - Balises produites : strong, em, u, sup, sub, br, a (liens externes) et HTML brut
 *   déjà assaini (appels de note).
 * - Dans une pensée (paragraphe en italique), l'italique est inversé : un segment droit
 *   devient <em>, rendu en romain par le thème (.yn-thought em).
 *
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Fabrique de HTML en ligne.
 */
final class Inline {

	/** Ordre d'ouverture des balises (de l'extérieur vers l'intérieur). */
	private const ORDRE = array( 'a', 'strong', 'em', 'u', 'sup', 'sub' );

	/**
	 * Segment de texte.
	 *
	 * @param string $texte Texte brut.
	 * @param array  $f     Mise en forme : b, i, u (bool), va ('sup'|'sub'|''), lien (URL|'').
	 * @return array<string,mixed>
	 */
	public static function texte( string $texte, array $f = array() ): array {
		return array(
			'type'  => 'texte',
			'texte' => $texte,
			'b'     => ! empty( $f['b'] ),
			'i'     => ! empty( $f['i'] ),
			'u'     => ! empty( $f['u'] ),
			'va'    => in_array( $f['va'] ?? '', array( 'sup', 'sub' ), true ) ? $f['va'] : '',
			'lien'  => (string) ( $f['lien'] ?? '' ),
		);
	}

	/**
	 * Saut de ligne.
	 *
	 * @return array<string,string>
	 */
	public static function saut(): array {
		return array( 'type' => 'br' );
	}

	/**
	 * HTML brut déjà assaini (appel de note…).
	 *
	 * @param string $html HTML.
	 * @return array<string,string>
	 */
	public static function brut( string $html ): array {
		return array(
			'type' => 'brut',
			'html' => $html,
		);
	}

	/**
	 * Texte brut des segments (pour les tests de séparateur, de tiret…).
	 *
	 * @param array<int,array<string,mixed>> $items Segments.
	 */
	public static function texte_brut( array $items ): string {
		$texte = '';
		foreach ( $items as $item ) {
			if ( 'texte' === $item['type'] ) {
				$texte .= $item['texte'];
			} elseif ( 'br' === $item['type'] ) {
				$texte .= "\n";
			}
		}
		return $texte;
	}

	/**
	 * Nettoie le texte d'un segment : caractères de contrôle retirés, tabulations et retours
	 * convertis en espaces.
	 *
	 * @param string $texte Texte.
	 */
	private static function nettoyer( string $texte ): string {
		$texte = str_replace( array( "\t", "\r", "\n", "\u{FEFF}" ), array( ' ', ' ', ' ', '' ), $texte );
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{FFFE}\x{FFFF}]/u', '', $texte );
	}

	/**
	 * Clé de mise en forme d'un segment.
	 *
	 * @param array<string,mixed> $item Segment.
	 */
	private static function forme( array $item ): string {
		return ( $item['b'] ? 'b' : '' ) . ( $item['i'] ? 'i' : '' ) . ( $item['u'] ? 'u' : '' ) . '|' . $item['va'] . '|' . $item['lien'];
	}

	/**
	 * Prépare les segments : nettoyage, espaces réduites, extrémités retirées, fusion des
	 * segments de même forme, espaces isolées rattachées à la forme commune de leurs voisins.
	 *
	 * @param array<int,array<string,mixed>> $items Segments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function preparer( array $items ): array {
		$sortie = array();
		foreach ( $items as $item ) {
			if ( 'texte' === $item['type'] ) {
				$item['texte'] = self::nettoyer( (string) $item['texte'] );
				if ( '' === $item['texte'] ) {
					continue;
				}
			}
			$sortie[] = $item;
		}

		// Espaces multiples, y compris d'un segment à l'autre.
		$espace = true; // Début de paragraphe : les espaces de tête disparaissent.
		foreach ( $sortie as $k => $item ) {
			if ( 'br' === $item['type'] ) {
				$espace = true;
				continue;
			}
			if ( 'brut' === $item['type'] ) {
				$espace = false;
				continue;
			}
			$texte = (string) preg_replace( '/ {2,}/', ' ', $item['texte'] );
			if ( $espace ) {
				$texte = ltrim( $texte, ' ' );
			}
			if ( '' !== $texte ) {
				$espace = str_ends_with( $texte, ' ' );
			}
			$sortie[ $k ]['texte'] = $texte;
		}
		$sortie = array_values( array_filter( $sortie, static fn( $i ) => 'texte' !== $i['type'] || '' !== $i['texte'] ) );

		// Espaces avant un saut de ligne ou en fin de paragraphe.
		for ( $k = count( $sortie ) - 1, $fin = true; $k >= 0; $k-- ) {
			$item = $sortie[ $k ];
			if ( 'br' === $item['type'] ) {
				$fin = true;
				continue;
			}
			if ( 'brut' === $item['type'] ) {
				$fin = false;
				continue;
			}
			if ( $fin ) {
				$sortie[ $k ]['texte'] = rtrim( $item['texte'], ' ' );
				if ( '' === $sortie[ $k ]['texte'] ) {
					continue;
				}
			}
			$fin = false;
		}
		$sortie = array_values( array_filter( $sortie, static fn( $i ) => 'texte' !== $i['type'] || '' !== $i['texte'] ) );

		// Sauts de ligne en tête et en fin de paragraphe.
		while ( $sortie && 'br' === $sortie[0]['type'] ) {
			array_shift( $sortie );
		}
		while ( $sortie && 'br' === end( $sortie )['type'] ) {
			array_pop( $sortie );
		}

		// Une espace seule entre deux segments prend leur forme commune (pas de « <em> </em> ») ;
		// une ponctuation seule ne garde une mise en forme que si un voisin la partage (pas de
		// virgule isolée en gras).
		$n = count( $sortie );
		for ( $k = 0; $k < $n; $k++ ) {
			if ( 'texte' !== $sortie[ $k ]['type'] || preg_match( '/[\p{L}\p{N}]/u', $sortie[ $k ]['texte'] ) ) {
				continue;
			}
			$prec   = $k > 0 && 'texte' === $sortie[ $k - 1 ]['type'] ? $sortie[ $k - 1 ] : null;
			$suiv   = $k + 1 < $n && 'texte' === $sortie[ $k + 1 ]['type'] ? $sortie[ $k + 1 ] : null;
			$espace = '' === trim( $sortie[ $k ]['texte'], " \u{00A0}\u{202F}" );
			foreach ( array( 'b', 'i', 'u' ) as $f ) {
				if ( $espace ) {
					if ( $prec && $suiv ) {
						$sortie[ $k ][ $f ] = $prec[ $f ] && $suiv[ $f ];
					}
					continue;
				}
				$sortie[ $k ][ $f ] = $sortie[ $k ][ $f ] && ( ( $prec && $prec[ $f ] ) || ( $suiv && $suiv[ $f ] ) );
			}
			if ( '' !== $sortie[ $k ]['va'] && ! ( ( $prec && $prec['va'] === $sortie[ $k ]['va'] ) || ( $suiv && $suiv['va'] === $sortie[ $k ]['va'] ) ) ) {
				$sortie[ $k ]['va'] = '';
			}
			if ( $espace && $prec && $suiv && '' === $sortie[ $k ]['lien'] && $prec['lien'] === $suiv['lien'] ) {
				$sortie[ $k ]['lien'] = $prec['lien'];
			}
		}

		// Fusion des segments contigus de même forme.
		$fusion = array();
		foreach ( $sortie as $item ) {
			$dernier = count( $fusion ) - 1;
			if ( 'texte' === $item['type'] && $dernier >= 0 && 'texte' === $fusion[ $dernier ]['type'] && self::forme( $fusion[ $dernier ] ) === self::forme( $item ) ) {
				$fusion[ $dernier ]['texte'] .= $item['texte'];
				continue;
			}
			$fusion[] = $item;
		}
		return $fusion;
	}

	/**
	 * Balises souhaitées pour un segment, dans l'ordre d'imbrication.
	 *
	 * @param array<string,mixed> $item    Segment.
	 * @param bool                $inverse Italique inversé (pensée).
	 * @return array<int,array{0:string,1:string}> Liste de [balise, attributs].
	 */
	private static function balises( array $item, bool $inverse ): array {
		$voulu = array();
		if ( '' !== $item['lien'] ) {
			$voulu['a'] = ' href="' . Blocks::attr( $item['lien'] ) . '"';
		}
		if ( $item['b'] ) {
			$voulu['strong'] = '';
		}
		if ( $item['i'] xor $inverse ) {
			$voulu['em'] = '';
		}
		if ( $item['u'] ) {
			$voulu['u'] = '';
		}
		if ( '' !== $item['va'] ) {
			$voulu[ $item['va'] ] = '';
		}
		$liste = array();
		foreach ( self::ORDRE as $balise ) {
			if ( isset( $voulu[ $balise ] ) ) {
				$liste[] = array( $balise, $voulu[ $balise ] );
			}
		}
		return $liste;
	}

	/**
	 * Ferme les balises ouvertes au-delà des $garde premières.
	 *
	 * @param array<int,array{0:string,1:string}> $pile  Balises ouvertes (modifiée).
	 * @param int                                 $garde Nombre de balises conservées.
	 */
	private static function fermer( array &$pile, int $garde ): string {
		$html = '';
		for ( $n = count( $pile ); $n > $garde; $n-- ) {
			$fermee = array_pop( $pile );
			$html  .= '</' . $fermee[0] . '>';
		}
		return $html;
	}

	/**
	 * Construit le HTML en ligne.
	 *
	 * @param array<int,array<string,mixed>> $items   Segments (texte(), saut(), brut()).
	 * @param bool                           $inverse Paragraphe en italique (pensée) : italique inversé.
	 */
	public static function html( array $items, bool $inverse = false ): string {
		$items = self::preparer( $items );
		$html  = '';
		$pile  = array(); // Liste de [balise, attributs] ouvertes.
		foreach ( $items as $k => $item ) {
			if ( 'br' === $item['type'] ) {
				// Les balises que la ligne suivante ne partage pas sont fermées avant le saut.
				$suivant = array();
				for ( $j = $k + 1, $n = count( $items ); $j < $n; $j++ ) {
					if ( 'texte' === $items[ $j ]['type'] ) {
						$suivant = self::balises( $items[ $j ], $inverse );
						break;
					}
				}
				$garde = 0;
				foreach ( $pile as $ouverte ) {
					if ( ! in_array( $ouverte, $suivant, true ) ) {
						break;
					}
					++$garde;
				}
				$html .= self::fermer( $pile, $garde ) . '<br>';
				continue;
			}
			if ( 'brut' === $item['type'] ) {
				$html .= $item['html'];
				continue;
			}
			$voulu = self::balises( $item, $inverse );
			// Garder le plus long préfixe de la pile entièrement voulu.
			$garde = 0;
			foreach ( $pile as $ouverte ) {
				if ( ! in_array( $ouverte, $voulu, true ) ) {
					break;
				}
				++$garde;
			}
			$html .= self::fermer( $pile, $garde );
			foreach ( $voulu as $balise ) {
				if ( ! in_array( $balise, $pile, true ) ) {
					$html  .= '<' . $balise[0] . $balise[1] . '>';
					$pile[] = $balise;
				}
			}
			$html .= Blocks::texte( (string) $item['texte'] );
		}
		while ( $pile ) {
			$fermee = array_pop( $pile );
			$html  .= '</' . $fermee[0] . '>';
		}
		return $html;
	}
}
