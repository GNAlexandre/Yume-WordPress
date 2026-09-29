<?php
/**
 * Feuilles de style d'un EPUB, réduites à ce qui sert à la conversion.
 *
 * Les EPUB produits par Calibre, Sigil ou InDesign portent l'italique, le gras et le centrage
 * dans des classes génériques (« calibre5 », « p1 », « char-style-override-3 ») définies dans
 * une feuille de style (fichier .css lié par <link rel="stylesheet"> ou élément <style>).
 * Ce lecteur minimal en extrait une table sélecteur simple → propriétés utiles :
 * - propriétés : font-style (italic, oblique, normal), font-weight (bold, 600 à 900, normal…),
 *   raccourci font, text-align (center ; left, right, justify… annulent le centrage) ;
 * - sélecteurs : composés simples uniquement (« .classe », « p.classe », « span.a.b », « em »,
 *   « i ») ; sélecteurs multiples séparés par des virgules ; tout sélecteur avec
 *   combinateur, attribut ou pseudo-classe est ignoré (jamais appliqué à tort) ;
 * - commentaires retirés ; @media et @supports aplatis (leur contenu est lu) ; autres
 *   règles @ (font-face, page, keyframes…) ignorées ; @import suivi par l'appelant ;
 * - cascade : spécificité (classes, puis balise) puis ordre d'apparition ; le style en ligne
 *   l'emporte (Epub_Css::declarations()).
 *
 * Aucune exécution, taille et nombre de règles bornés. Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Table de styles simplifiée.
 */
final class Epub_Css {

	/** Taille maximale d'une feuille lue (octets). */
	public const TAILLE_MAX = 1024 * 1024;

	/** Nombre maximal de règles retenues pour un document. */
	private const REGLES_MAX = 20000;

	/** Profondeur maximale d'imbrication (@media dans @supports…). */
	private const PROFONDEUR_MAX = 4;

	/**
	 * Règles indexées par clé (première classe « .x » ou balise « p ») : liste de
	 * [spécificité, ordre, balise, classes, propriétés].
	 *
	 * @var array<string,array<int,array{0:int,1:int,2:string,3:string[],4:array<string,bool>}>>
	 */
	private array $index = array();

	/**
	 * Nombre de règles retenues (ordre d'apparition).
	 *
	 * @var int
	 */
	private int $n = 0;

	/**
	 * Feuilles importées (@import) rencontrées pendant la dernière lecture.
	 *
	 * @var string[]
	 */
	public array $imports = array();

	/**
	 * Aucune règle utile ?
	 */
	public function vide(): bool {
		return 0 === $this->n;
	}

	/**
	 * Lit une feuille de style et retient ses règles utiles.
	 *
	 * @param string $css Contenu.
	 */
	public function ajouter( string $css ): void {
		$this->imports = array();
		$css           = substr( $css, 0, self::TAILLE_MAX );
		$css           = (string) preg_replace( '#/\*.*?(?:\*/|$)#s', ' ', $css );
		$this->bloc( $css, 0 );
	}

	/**
	 * Parcourt une suite de règles (niveau supérieur ou contenu d'un @media).
	 *
	 * @param string $css        Texte.
	 * @param int    $profondeur Profondeur d'imbrication.
	 */
	private function bloc( string $css, int $profondeur ): void {
		$longueur = strlen( $css );
		$i        = 0;
		while ( $i < $longueur && $this->n < self::REGLES_MAX ) {
			$accolade = strpos( $css, '{', $i );
			$point    = strpos( $css, ';', $i );
			if ( false === $accolade && false === $point ) {
				return;
			}
			// Instruction sans bloc : import, jeu de caractères, espace de noms.
			if ( false !== $point && ( false === $accolade || $point < $accolade ) ) {
				$instruction = trim( substr( $css, $i, $point - $i ) );
				if ( preg_match( '/^@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)/i', $instruction, $m ) ) {
					$this->imports[] = $m[1];
				}
				$i = $point + 1;
				continue;
			}
			$prelude = trim( substr( $css, $i, $accolade - $i ) );
			$fin     = self::fermante( $css, $accolade );
			$contenu = substr( $css, $accolade + 1, $fin - $accolade - 1 );
			$i       = $fin + 1;
			if ( '' !== $prelude && '@' === $prelude[0] ) {
				if ( $profondeur < self::PROFONDEUR_MAX && preg_match( '/^@(media|supports)\b/i', $prelude ) && ! preg_match( '/^@media\s+(?:only\s+)?print\s*$/i', $prelude ) ) {
					$this->bloc( $contenu, $profondeur + 1 );
				}
				continue;
			}
			$proprietes = self::declarations( $contenu );
			if ( ! $proprietes ) {
				continue;
			}
			foreach ( explode( ',', $prelude ) as $selecteur ) {
				$this->retenir( trim( $selecteur ), $proprietes );
			}
		}
	}

	/**
	 * Position de l'accolade fermante correspondante (fin du texte si absente).
	 *
	 * @param string $css      Texte.
	 * @param int    $ouvrante Position de l'accolade ouvrante.
	 */
	private static function fermante( string $css, int $ouvrante ): int {
		$niveau   = 0;
		$longueur = strlen( $css );
		for ( $j = $ouvrante; $j < $longueur; $j++ ) {
			if ( '{' === $css[ $j ] ) {
				++$niveau;
			} elseif ( '}' === $css[ $j ] ) {
				--$niveau;
				if ( 0 === $niveau ) {
					return $j;
				}
			}
		}
		return $longueur;
	}

	/**
	 * Retient une règle si son sélecteur est un composé simple (balise et/ou classes).
	 *
	 * @param string             $selecteur  Sélecteur.
	 * @param array<string,bool> $proprietes Propriétés utiles.
	 */
	private function retenir( string $selecteur, array $proprietes ): void {
		$selecteur = strtolower( $selecteur );
		if ( ! preg_match( '/^([a-z][a-z0-9]*|\*)?((?:\.[_a-z0-9\-]+)*)$/', $selecteur, $m ) || '' === $selecteur ) {
			return;
		}
		$balise  = '*' === ( $m[1] ?? '' ) ? '' : (string) ( $m[1] ?? '' );
		$classes = array_values( array_filter( explode( '.', (string) ( $m[2] ?? '' ) ), 'strlen' ) );
		if ( ! $classes && '' === $balise ) {
			return; // « * » seul (feuilles de remise à zéro) : jamais appliqué, il effacerait <em>.
		}
		$cle = $classes ? '.' . $classes[0] : $balise;

		$this->index[ $cle ][] = array( 100 * count( $classes ) + ( '' !== $balise ? 1 : 0 ), $this->n++, $balise, $classes, $proprietes );
	}

	/**
	 * Propriétés utiles d'un bloc de déclarations (ou d'un attribut style).
	 *
	 * @param string $declarations Texte « propriété: valeur; … ».
	 * @return array<string,bool> Clés i (italique), b (gras), c (centré) ; absente si non définie.
	 */
	public static function declarations( string $declarations ): array {
		$resultat = array();
		foreach ( explode( ';', strtolower( $declarations ) ) as $declaration ) {
			$deux = explode( ':', $declaration, 2 );
			if ( 2 !== count( $deux ) ) {
				continue;
			}
			$propriete = trim( $deux[0] );
			$valeur    = trim( str_replace( '!important', '', $deux[1] ) );
			if ( preg_match( '/^(inherit|unset|revert)|var\(/', $valeur ) ) {
				continue; // Valeur héritée ou variable : le style du parent s'applique.
			}
			switch ( $propriete ) {
				case 'font-style':
					$resultat['i'] = (bool) preg_match( '/^(italic|oblique)\b/', $valeur );
					break;
				case 'font-weight':
					$resultat['b'] = (bool) preg_match( '/^(bold|bolder|[6-9]00)$/', $valeur );
					break;
				case 'font':
					$resultat['i'] = (bool) preg_match( '/(^|\s)(italic|oblique)(\s|$)/', $valeur );
					$resultat['b'] = (bool) preg_match( '/(^|\s)(bold|bolder|[6-9]00)(\s|$)/', $valeur );
					break;
				case 'text-align':
					$resultat['c'] = (bool) preg_match( '/^(center|-webkit-center)$/', $valeur );
					break;
			}
		}
		return $resultat;
	}

	/**
	 * Style calculé d'un élément par les feuilles (sans le style en ligne).
	 *
	 * @param string $balise  Nom de l'élément (minuscules).
	 * @param string $classes Classes (minuscules, séparées par des espaces).
	 * @return array<string,bool> Clés i, b, c ; absente si aucune règle ne la définit.
	 */
	public function style( string $balise, string $classes ): array {
		if ( 0 === $this->n ) {
			return array();
		}
		$liste    = array_values( array_filter( explode( ' ', $classes ), 'strlen' ) );
		$candidat = $this->index[ $balise ] ?? array();
		foreach ( array_unique( $liste ) as $classe ) {
			$candidat = array_merge( $candidat, $this->index[ '.' . $classe ] ?? array() );
		}
		$retenues = array();
		foreach ( $candidat as $regle ) {
			if ( ( '' === $regle[2] || $regle[2] === $balise ) && ! array_diff( $regle[3], $liste ) ) {
				$retenues[] = $regle;
			}
		}
		usort( $retenues, static fn( $a, $b ) => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
		$style = array();
		foreach ( $retenues as $regle ) {
			$style = array_merge( $style, $regle[4] );
		}
		return $style;
	}
}
