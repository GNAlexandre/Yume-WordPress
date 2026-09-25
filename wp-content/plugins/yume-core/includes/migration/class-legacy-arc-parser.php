<?php
/**
 * Analyse d'une page « ARC » de l'ancien site (Secrets of the Silent Witch) :
 * titre « ARC 7 | TOURNOI D'ÉCHEC – Silent Witch », synopsis éventuel, équivalence
 * (« Cet arc équivaut au tome 3 du LN. »), liste ordonnée des chapitres (liens vers les pages
 * chapitres déjà traduites, texte seul pour les chapitres annoncés) et liens PDF / EPUB.
 *
 * Classe pure (aucun accès à la base) : parse_blocks et fonctions de formatage uniquement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Page ARC → tome de nature « arc » + chapitres annoncés.
 */
final class Legacy_Arc_Parser {

	/**
	 * Reconnaît un titre de page d'arc.
	 *
	 * @param string $titre Titre de la page.
	 * @return array{numero:int,titre:string,oeuvre:string}|null
	 */
	public static function analyser_titre( string $titre ): ?array {
		$titre = trim( (string) preg_replace( '/\s+/u', ' ', Html::decoder( $titre ) ) );
		if ( ! preg_match( '/^arc\s*(\d+)\s*[|:]\s*(.+?)(?:\s+[–—-]\s+(.+))?$/iu', $titre, $m ) ) {
			return null;
		}
		return array(
			'numero' => (int) $m[1],
			'titre'  => Html::casse_phrase( trim( $m[2] ) ),
			'oeuvre' => trim( $m[3] ?? '' ),
		);
	}

	/**
	 * Analyse une page d'arc.
	 *
	 * @param array $page    Page de l'export.
	 * @param array $options 'domaines' => string[].
	 * @return array<string,mixed>
	 */
	public static function parse( array $page, array $options = array() ): array {
		$domaines = $options['domaines'] ?? array( 'yumenovel.fr', 'yumenovel.wordpress.com' );
		$avert    = array();
		$titre    = self::analyser_titre( (string) ( $page['title'] ?? '' ) );
		if ( null === $titre ) {
			$avert[] = sprintf( 'Page %d : titre d’arc non reconnu (« %s »).', (int) ( $page['id'] ?? 0 ), $page['title'] ?? '' );
			$titre   = array(
				'numero' => 0,
				'titre'  => (string) ( $page['title'] ?? '' ),
				'oeuvre' => '',
			);
		}

		$blocs       = Legacy_Hub_Parser::aplatir( parse_blocks( (string) ( $page['content'] ?? '' ) ) );
		$equivalence = '';
		$equiv_tome  = null;
		$synopsis    = array();
		$chapitres   = array();
		$liens       = array();
		$vides       = array();
		foreach ( $blocs as $bloc ) {
			$nom  = $bloc['blockName'];
			$html = (string) preg_replace( '#^\s*<(?:p|h\d)[^>]*>|</(?:p|h\d)>\s*$#i', '', trim( (string) $bloc['innerHTML'] ) );
			if ( 'core/button' === $nom ) {
				$url   = Html::premier_href( $html );
				$texte = Html::texte( $html );
				if ( '' === $url ) {
					$vides[] = $texte;
				} else {
					$liens[] = array(
						'type'  => Legacy_Oeuvre_Parser::type_lien( $url, $texte, $texte ),
						'url'   => $url,
						'texte' => $texte,
					);
				}
				continue;
			}
			if ( 'core/paragraph' !== $nom ) {
				continue;
			}
			$texte = Html::texte( $html );
			if ( '' === $texte ) {
				continue;
			}
			if ( preg_match( '/[ée]quivaut\s+au\s+tome\s+(\d+)/iu', $texte, $m ) ) {
				$equivalence = trim( (string) preg_replace( '/\s+([.!?])$/u', '$1', $texte ) );
				$equiv_tome  = (int) $m[1];
				continue;
			}
			if ( preg_match( '/^liste des chapitres$/iu', $texte ) ) {
				continue;
			}
			$lignes = self::lignes_chapitres( $html, $domaines );
			if ( $lignes ) {
				$chapitres = array_merge( $chapitres, $lignes );
				continue;
			}
			if ( preg_match( '/\b(pdf|epub)\b/iu', $texte ) && mb_strlen( $texte, 'UTF-8' ) < 80 ) {
				foreach ( Html::liens( $html ) as $lien ) {
					$liens[] = array(
						'type'  => Legacy_Oeuvre_Parser::type_lien( $lien['url'], $lien['texte'], $texte ),
						'url'   => $lien['url'],
						'texte' => $lien['texte'],
					);
				}
				if ( ! Html::liens( $html ) ) {
					$vides[] = $texte;
				}
				continue;
			}
			$synopsis[] = $html;
		}

		$pdf  = '';
		$epub = '';
		foreach ( $liens as $lien ) {
			if ( 'pdf' === $lien['type'] && '' === $pdf ) {
				$pdf = $lien['url'];
			} elseif ( 'epub' === $lien['type'] && '' === $epub ) {
				$epub = $lien['url'];
			}
		}
		foreach ( $vides as $vide ) {
			$avert[] = sprintf( 'Arc %d : « %s » sans lien.', $titre['numero'], $vide );
		}
		if ( '' === $equivalence ) {
			$avert[] = sprintf( 'Arc %d : équivalence avec le light novel absente.', $titre['numero'] );
		}
		if ( ! $chapitres ) {
			$avert[] = sprintf( 'Arc %d : liste des chapitres introuvable.', $titre['numero'] );
		}

		// Numérotation : ordre et continuité.
		$attendu = 1;
		foreach ( $chapitres as $chapitre ) {
			if ( null !== $chapitre['numero'] && (float) $attendu !== $chapitre['numero'] ) {
				$avert[] = sprintf( 'Arc %d : chapitre %s annoncé à la place du chapitre %d.', $titre['numero'], Legacy_Oeuvre_Parser::numero_fr( $chapitre['numero'] ), $attendu );
			}
			$attendu = (int) floor( (float) ( $chapitre['numero'] ?? $attendu ) ) + 1;
		}

		$blocs_synopsis = array();
		$texte_synopsis = array();
		foreach ( $synopsis as $html ) {
			$propre = Html::nettoyer_inline( $html );
			if ( Html::entierement_italique( $propre ) ) {
				$propre = Html::sans_italique( $propre );
			}
			$blocs_synopsis[] = Blocks::paragraphe( $propre );
			$texte_synopsis[] = Html::texte( $propre );
		}

		return array(
			'source_id'           => (int) ( $page['id'] ?? 0 ),
			'source_slug'         => (string) ( $page['slug'] ?? '' ),
			'source_url'          => (string) ( $page['link'] ?? '' ),
			'source_titre'        => (string) ( $page['title'] ?? '' ),
			'numero'              => $titre['numero'],
			'titre'               => $titre['titre'],
			'oeuvre_indice'       => $titre['oeuvre'],
			'equivalence'         => $equivalence,
			'equivalence_tome_ln' => $equiv_tome,
			'synopsis'            => implode( "\n\n", $texte_synopsis ),
			'contenu'             => Blocks::assembler( $blocs_synopsis ),
			'chapitres'           => $chapitres,
			'nb_traduits'         => count( array_filter( $chapitres, static fn( $c ) => $c['traduit'] ) ),
			'lien_pdf'            => $pdf,
			'lien_epub'           => $epub,
			'liens'               => $liens,
			'boutons_vides'       => $vides,
			'image_id'            => (int) ( $page['featured_media'] ?? 0 ),
			'date'                => (string) ( $page['date'] ?? '' ),
			'avertissements'      => $avert,
		);
	}

	/**
	 * Lignes « N : Titre » d'un paragraphe de sommaire (au moins deux lignes numérotées).
	 * Les liens sont retrouvés même quand une balise <a> enjambe un <br>.
	 *
	 * @param string   $html     HTML du paragraphe.
	 * @param string[] $domaines Domaines du site.
	 * @return array<int,array{numero:?float,titre:string,url:string,chemin:string,slug:string,traduit:bool}>
	 */
	public static function lignes_chapitres( string $html, array $domaines ): array {
		$lignes = array();
		foreach ( Html::lignes_html( $html ) as $ligne ) {
			$texte = Html::texte( $ligne );
			if ( '' === $texte ) {
				continue;
			}
			if ( ! preg_match( '/^(\d+(?:[.,]\d+)?)\s*[:：]\s*(.+)$/u', $texte, $m ) ) {
				return array();
			}
			$url      = Html::premier_href( $ligne );
			$chemin   = '' !== $url ? Html::chemin_local( $url, $domaines ) : '';
			$lignes[] = array(
				'numero'  => Html::nombre( $m[1] ),
				'titre'   => trim( $m[2] ),
				'url'     => $url,
				'chemin'  => $chemin,
				'slug'    => Html::dernier_segment( $chemin ),
				'traduit' => '' !== $chemin,
			);
		}
		return count( $lignes ) >= 2 ? $lignes : array();
	}
}
