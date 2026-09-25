<?php
/**
 * Outils HTML purs pour l'analyse de l'ancien site : extraction de texte, découpage en
 * lignes, liens, nettoyage du balisage en ligne, détection de l'italique.
 *
 * Aucune dépendance à la base de données. Fonctions WordPress utilisées : wp_strip_all_tags,
 * remove_accents (formatage pur).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions statiques de manipulation HTML.
 */
final class Html {

	/** Balises en ligne conservées par nettoyer_inline() (après normalisation b→strong, i→em…). */
	private const BALISES_CONSERVEES = array( 'em', 'strong', 'u', 's', 'sup', 'sub', 'br', 'a' );

	/** Équivalences de balises en ligne. */
	private const EQUIVALENCES = array(
		'b'      => 'strong',
		'i'      => 'em',
		'del'    => 's',
		'strike' => 's',
		'ins'    => 'u',
	);

	/** Tirets pouvant ouvrir une réplique de dialogue. */
	public const TIRETS = '—–―';

	/**
	 * Décode les entités et remplace les espaces insécables par des espaces ordinaires.
	 *
	 * @param string $texte Texte.
	 */
	public static function decoder( string $texte ): string {
		$texte = html_entity_decode( $texte, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace( array( "\u{00A0}", "\u{202F}", "\u{2007}" ), ' ', $texte );
	}

	/**
	 * Texte brut d'un fragment HTML : balises retirées, entités décodées, blancs réduits.
	 * Les <br> deviennent des espaces.
	 *
	 * @param string $html Fragment HTML.
	 */
	public static function texte( string $html ): string {
		$html  = preg_replace( '/<br\s*\/?>/i', ' ', $html );
		$texte = self::decoder( wp_strip_all_tags( (string) $html ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $texte ) );
	}

	/**
	 * Découpe un fragment HTML sur les <br> (le HTML de chaque ligne est conservé).
	 *
	 * @param string $html Fragment HTML.
	 * @return string[]
	 */
	public static function lignes_html( string $html ): array {
		return preg_split( '/<br\s*\/?>/i', $html );
	}

	/**
	 * Premier lien (attribut href) présent dans un fragment, ou chaîne vide.
	 *
	 * @param string $html Fragment HTML.
	 */
	public static function premier_href( string $html ): string {
		if ( preg_match( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			return trim( self::decoder( $m[2] ) );
		}
		return '';
	}

	/**
	 * Liens d'un fragment : [['url' => …, 'texte' => …], …] dans l'ordre du document.
	 * Les ancres sans href sont ignorées.
	 *
	 * @param string $html Fragment HTML.
	 * @return array<int,array{url:string,texte:string}>
	 */
	public static function liens( string $html ): array {
		$liens = array();
		if ( preg_match_all( '/<a\s([^>]*)>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $lien ) {
				if ( ! preg_match( '/href\s*=\s*(["\'])(.*?)\1/is', $lien[1], $h ) ) {
					continue;
				}
				$url = trim( self::decoder( $h[2] ) );
				if ( '' === $url ) {
					continue;
				}
				$liens[] = array(
					'url'   => $url,
					'texte' => self::texte( $lien[2] ),
				);
			}
		}
		return $liens;
	}

	/**
	 * Normalise une chaîne pour la comparaison : minuscules, sans accents, ponctuation
	 * remplacée par des espaces, blancs réduits.
	 *
	 * @param string $texte Texte.
	 */
	public static function normaliser( string $texte ): string {
		$texte = remove_accents( self::decoder( $texte ) );
		$texte = mb_strtolower( $texte, 'UTF-8' );
		$texte = str_replace( array( '’', '\'', '`', '‘' ), ' ', $texte );
		$texte = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $texte );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $texte ) );
	}

	/**
	 * Convertit un nombre écrit « 26,5 » ou « 26.5 » en flottant, ou null.
	 *
	 * @param string $valeur Valeur.
	 */
	public static function nombre( string $valeur ): ?float {
		$valeur = str_replace( ',', '.', trim( $valeur ) );
		return is_numeric( $valeur ) ? round( (float) $valeur, 3 ) : null;
	}

	/**
	 * Met en forme « phrase » un texte saisi en capitales (« RENTRÉE ACADÉMIQUE » →
	 * « Rentrée académique »). Un texte déjà en casse mixte est laissé tel quel.
	 *
	 * @param string $texte Texte.
	 */
	public static function casse_phrase( string $texte ): string {
		$texte = trim( $texte );
		if ( '' === $texte || mb_strtoupper( $texte, 'UTF-8' ) !== $texte ) {
			return $texte;
		}
		$bas = mb_strtolower( $texte, 'UTF-8' );
		return mb_strtoupper( mb_substr( $bas, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $bas, 1, null, 'UTF-8' );
	}

	/**
	 * Échappe une valeur d'attribut HTML.
	 *
	 * @param string $valeur Valeur.
	 */
	public static function attr( string $valeur ): string {
		return htmlspecialchars( $valeur, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false );
	}

	/**
	 * Une URL est-elle acceptable dans un lien (http, https, relative, ancre, mailto) ?
	 *
	 * @param string $url URL.
	 */
	public static function url_valide( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || preg_match( '/[\x00-\x1f\x7f\s]/', $url ) ) {
			return false;
		}
		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto' ), true );
		}
		return true;
	}

	/**
	 * Charge un fragment HTML dans un DOMDocument et renvoie l'élément racine qui l'enveloppe.
	 *
	 * @param string $html Fragment HTML (UTF-8).
	 */
	private static function racine_dom( string $html ): ?\DOMElement {
		$doc     = new \DOMDocument( '1.0', 'UTF-8' );
		$ancien  = libxml_use_internal_errors( true );
		$charge  = $doc->loadHTML(
			'<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="yume-racine">' . $html . '</div></body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $ancien );
		if ( ! $charge ) {
			return null;
		}
		$racine = $doc->getElementById( 'yume-racine' );
		return $racine instanceof \DOMElement ? $racine : null;
	}

	/**
	 * Nettoie le balisage en ligne : ne conserve que em, strong, u, s, sup, sub, br et a[href]
	 * (b → strong, i → em, del/strike → s), sans aucun attribut autre que href ; les autres
	 * balises (span, mark, font…) sont retirées en gardant leur texte. Les balises identiques
	 * adjacentes sont fusionnées et les balises vides supprimées.
	 *
	 * @param string $html Fragment HTML.
	 */
	public static function nettoyer_inline( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		$racine = self::racine_dom( $html );
		if ( null === $racine ) {
			return self::attr( self::texte( $html ) );
		}
		$sortie = self::serialiser( $racine );
		// Fusion des balises identiques adjacentes (<em>a</em><em>b</em> → <em>ab</em>).
		do {
			$avant  = $sortie;
			$sortie = (string) preg_replace( '#</(em|strong|u|s|sup|sub)>(\s*)<\1>#u', '$2', $sortie );
			$sortie = (string) preg_replace( '#<(em|strong|u|s|sup|sub)>(\s*)</\1>#u', '$2', $sortie );
		} while ( $avant !== $sortie );
		return trim( $sortie );
	}

	/**
	 * Sérialise récursivement les enfants d'un nœud selon les règles de nettoyer_inline().
	 *
	 * @param \DOMNode $noeud Nœud parent.
	 */
	private static function serialiser( \DOMNode $noeud ): string {
		$sortie = '';
		foreach ( $noeud->childNodes as $enfant ) {
			if ( $enfant instanceof \DOMText ) {
				$sortie .= htmlspecialchars( $enfant->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				continue;
			}
			if ( ! $enfant instanceof \DOMElement ) {
				continue;
			}
			$balise = strtolower( $enfant->nodeName );
			$balise = self::EQUIVALENCES[ $balise ] ?? $balise;
			if ( in_array( $balise, array( 'script', 'style', 'iframe', 'object', 'noscript', 'template' ), true ) ) {
				continue;
			}
			if ( 'br' === $balise ) {
				$sortie .= '<br>';
				continue;
			}
			$interieur = self::serialiser( $enfant );
			if ( ! in_array( $balise, self::BALISES_CONSERVEES, true ) ) {
				$sortie .= $interieur;
				continue;
			}
			// Mise en forme posée sur de la seule ponctuation (« <strong>— </strong> », « <em>...</em> ») : retirée.
			if ( in_array( $balise, array( 'em', 'strong', 'u', 's' ), true ) && false === strpos( $interieur, '<br>' ) && ! preg_match( '/[\p{L}\p{N}]/u', self::texte( $interieur ) ) ) {
				$sortie .= $interieur;
				continue;
			}
			if ( 'a' === $balise ) {
				$href = trim( $enfant->getAttribute( 'href' ) );
				if ( '' === trim( self::texte( $interieur ) ) && false === strpos( $interieur, '<br>' ) ) {
					continue;
				}
				$sortie .= self::url_valide( $href ) ? '<a href="' . self::attr( $href ) . '">' . $interieur . '</a>' : $interieur;
				continue;
			}
			$sortie .= '<' . $balise . '>' . $interieur . '</' . $balise . '>';
		}
		return $sortie;
	}

	/**
	 * Compte les lettres et chiffres en italique et hors italique d'un fragment.
	 *
	 * @param string $html Fragment HTML.
	 * @return array{italique:int,droit:int}
	 */
	public static function mesure_italique( string $html ): array {
		$compte = array(
			'italique' => 0,
			'droit'    => 0,
		);
		$racine = self::racine_dom( $html );
		if ( null === $racine ) {
			return $compte;
		}
		self::parcourir_italique( $racine, false, $compte );
		return $compte;
	}

	/**
	 * Parcourt l'arbre en comptant les caractères significatifs selon qu'ils sont en italique.
	 *
	 * @param \DOMNode $noeud     Nœud.
	 * @param bool     $italique  Un ancêtre est-il en italique ?
	 * @param array    $compte    Compteurs (par référence).
	 */
	private static function parcourir_italique( \DOMNode $noeud, bool $italique, array &$compte ): void {
		foreach ( $noeud->childNodes as $enfant ) {
			if ( $enfant instanceof \DOMText ) {
				$n = (int) preg_match_all( '/[\p{L}\p{N}]/u', (string) $enfant->nodeValue );
				$compte[ $italique ? 'italique' : 'droit' ] += $n;
			} elseif ( $enfant instanceof \DOMElement ) {
				$balise = strtolower( $enfant->nodeName );
				self::parcourir_italique( $enfant, $italique || in_array( $balise, array( 'em', 'i' ), true ), $compte );
			}
		}
	}

	/**
	 * Le fragment est-il entièrement en italique (hors ponctuation et blancs) ?
	 *
	 * @param string $html Fragment HTML.
	 */
	public static function entierement_italique( string $html ): bool {
		$compte = self::mesure_italique( $html );
		return $compte['italique'] > 0 && 0 === $compte['droit'];
	}

	/**
	 * Retire les balises d'italique (em, i) en conservant leur contenu.
	 *
	 * @param string $html Fragment HTML.
	 */
	public static function sans_italique( string $html ): string {
		return trim( (string) preg_replace( '#</?(?:em|i)(?:\s[^>]*)?>#i', '', $html ) );
	}

	/**
	 * Le texte commence-t-il par un tiret de dialogue (—, –, ― ou « - » suivi d'une espace) ?
	 *
	 * @param string $texte Texte brut (décodé).
	 */
	public static function commence_par_tiret( string $texte ): bool {
		$texte = ltrim( $texte );
		return (bool) preg_match( '/^(?:[' . self::TIRETS . ']|-(?=\s))/u', $texte );
	}

	/**
	 * Retire le tiret de dialogue initial d'un fragment nettoyé (y compris quand il est
	 * enveloppé dans des balises en ligne) et renvoie « — » + espace insécable + réplique.
	 *
	 * @param string $html Fragment nettoyé par nettoyer_inline().
	 */
	public static function normaliser_dialogue( string $html ): string {
		$blanc     = '(?:\s|\x{00A0}|\x{202F}|&nbsp;)';
		$ouvrantes = '(?:<(?:em|strong|u|s)>)';
		// Le premier caractère significatif est le tiret : on le retire avec les blancs qui le suivent.
		$html = (string) preg_replace( '/^((?:' . $ouvrantes . '|' . $blanc . ')*?)(?:[' . self::TIRETS . ']|-)' . $blanc . '*/u', '$1', $html, 1 );
		// Balises devenues vides après le retrait du tiret (<em><strong></strong></em>).
		do {
			$avant = $html;
			$html  = (string) preg_replace( '#<(em|strong|u|s)>(' . $blanc . '*)</\1>#u', '$2', $html );
		} while ( $avant !== $html );
		$html = (string) preg_replace( '/^' . $blanc . '+/u', '', $html );
		$html = (string) preg_replace( '/^(' . $ouvrantes . '+)' . $blanc . '+/u', '$1', $html );
		return "—\u{00A0}" . $html;
	}

	/**
	 * Le texte n'est-il qu'un séparateur de scène (***, * * *, ◇, ◆…) ?
	 *
	 * @param string $texte Texte brut.
	 */
	public static function est_separateur_texte( string $texte ): bool {
		$texte = trim( $texte );
		return '' !== $texte && (bool) preg_match( '/^[\*＊◇◆◈❖✦✧•·~〜※♢♦\s]+$/u', $texte ) && (bool) preg_match( '/[^\s]/u', $texte );
	}

	/**
	 * Nombre de mots d'un texte (suites de lettres/chiffres, apostrophes et traits d'union
	 * internes compris).
	 *
	 * @param string $texte Texte brut.
	 */
	public static function nombre_mots( string $texte ): int {
		return (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $texte );
	}

	/**
	 * Chemin (sans domaine) d'une URL du site, avec barre finale, ou chaîne vide si l'URL
	 * pointe vers un autre site.
	 *
	 * @param string   $url      URL absolue ou relative.
	 * @param string[] $domaines Domaines considérés comme le site (sans « www. »).
	 */
	public static function chemin_local( string $url, array $domaines ): string {
		$url = trim( $url );
		if ( '' === $url || '#' === $url[0] ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return '';
		}
		if ( ! empty( $parts['host'] ) ) {
			$hote = strtolower( (string) preg_replace( '/^www\./i', '', $parts['host'] ) );
			if ( ! in_array( $hote, $domaines, true ) ) {
				return '';
			}
		} elseif ( ! empty( $parts['scheme'] ) ) {
			return '';
		}
		$chemin = (string) ( $parts['path'] ?? '/' );
		if ( '' === $chemin || '/' !== $chemin[0] ) {
			$chemin = '/' . $chemin;
		}
		$chemin = (string) preg_replace( '#/+#', '/', $chemin );
		return rtrim( $chemin, '/' ) . '/';
	}

	/**
	 * Dernier segment non vide d'un chemin (« /a/b/ » → « b »).
	 *
	 * @param string $chemin Chemin.
	 */
	public static function dernier_segment( string $chemin ): string {
		$segments = array_values( array_filter( explode( '/', $chemin ), 'strlen' ) );
		return $segments ? (string) end( $segments ) : '';
	}
}
