<?php
/**
 * Analyse d'une page « hub » de l'ancien site (Yume LN, Yume Manga) : sections
 * « Série Terminé / Série en cours / Série Licenciée/Abandonnée… » suivies de vignettes
 * cliquables menant aux fiches des œuvres.
 *
 * Classe pure (aucun accès à la base) : parse_blocks et fonctions de formatage uniquement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Hub → statut Yume et vignette de chaque œuvre.
 */
final class Legacy_Hub_Parser {

	/**
	 * Aplatit un arbre de blocs dans l'ordre du document (conteneurs remplacés par leurs enfants).
	 *
	 * @param array $blocs Blocs issus de parse_blocks().
	 * @return array<int,array>
	 */
	public static function aplatir( array $blocs ): array {
		$sortie = array();
		foreach ( $blocs as $bloc ) {
			if ( empty( $bloc['blockName'] ) ) {
				continue;
			}
			if ( ! empty( $bloc['innerBlocks'] ) && in_array( $bloc['blockName'], array( 'core/columns', 'core/column', 'core/group', 'core/gallery', 'core/cover', 'core/media-text', 'core/buttons' ), true ) ) {
				$sortie = array_merge( $sortie, self::aplatir( $bloc['innerBlocks'] ) );
				continue;
			}
			$sortie[] = $bloc;
		}
		return $sortie;
	}

	/**
	 * Statuts Yume (termes yume_statut) évoqués par un libellé de section.
	 * Un libellé ambigu (« Licenciée/Abandonnée ») renvoie plusieurs statuts.
	 *
	 * @param string $libelle Libellé (« Série en cours »…).
	 * @return string[]
	 */
	public static function statuts_depuis_libelle( string $libelle ): array {
		$n       = Html::normaliser( $libelle );
		$statuts = array();
		if ( preg_match( '/\btermin/', $n ) ) {
			$statuts[] = 'terminee';
		}
		if ( preg_match( '/\ben cours\b/', $n ) ) {
			$statuts[] = 'en-cours';
		}
		if ( preg_match( '/\blicenci/', $n ) ) {
			$statuts[] = 'licenciee';
		}
		if ( preg_match( '/\babandon/', $n ) ) {
			$statuts[] = 'abandonnee';
		}
		if ( preg_match( '/\ben pause\b|\bpause\b/', $n ) ) {
			$statuts[] = 'en-pause';
		}
		return $statuts;
	}

	/**
	 * Analyse une page hub.
	 *
	 * @param array    $page     Page de l'export (id, slug, title, content…).
	 * @param string[] $domaines Domaines du site (liens internes).
	 * @return array{source_id:int,source_slug:string,titre:string,type:string,sections:array,entrees:array,avertissements:string[]}
	 */
	public static function parse( array $page, array $domaines = array( 'yumenovel.fr', 'yumenovel.wordpress.com' ) ): array {
		$slug     = (string) ( $page['slug'] ?? '' );
		$type     = false !== strpos( Html::normaliser( $slug . ' ' . ( $page['title'] ?? '' ) ), 'manga' ) ? 'manga' : 'light-novel';
		$sections = array();
		$entrees  = array();
		$avert    = array();
		$courante = -1;
		$ordre    = 0;
		foreach ( self::aplatir( parse_blocks( (string) ( $page['content'] ?? '' ) ) ) as $bloc ) {
			$nom = $bloc['blockName'];
			if ( in_array( $nom, array( 'core/paragraph', 'core/heading' ), true ) ) {
				$texte = Html::texte( (string) $bloc['innerHTML'] );
				if ( preg_match( '/^s[ée]ries?\b/iu', $texte ) ) {
					$sections[] = array(
						'libelle' => $texte,
						'statuts' => self::statuts_depuis_libelle( $texte ),
						'slugs'   => array(),
					);
					$courante   = count( $sections ) - 1;
				}
				continue;
			}
			if ( 'core/image' !== $nom ) {
				continue;
			}
			$html   = (string) $bloc['innerHTML'];
			$href   = Html::premier_href( $html );
			$chemin = '' !== $href ? Html::chemin_local( $href, $domaines ) : '';
			if ( '' === $chemin ) {
				$avert[] = sprintf( 'Hub « %s » : vignette sans lien vers une fiche (image %d).', $page['title'] ?? $slug, (int) ( $bloc['attrs']['id'] ?? 0 ) );
				continue;
			}
			$cible = Html::dernier_segment( $chemin );
			$id    = (int) ( $bloc['attrs']['id'] ?? 0 );
			if ( ! $id && preg_match( '/wp-image-(\d+)/', $html, $m ) ) {
				$id = (int) $m[1];
			}
			$src = preg_match( '/<img\s[^>]*src="([^"]+)"/i', $html, $m ) ? Html::decoder( $m[1] ) : '';
			if ( $courante < 0 ) {
				$avert[] = sprintf( 'Hub « %s » : vignette « %s » hors de toute section.', $page['title'] ?? $slug, $cible );
			}
			$entrees[ $cible ] = array(
				'slug'      => $cible,
				'url'       => $href,
				'libelle'   => $courante >= 0 ? $sections[ $courante ]['libelle'] : '',
				'statuts'   => $courante >= 0 ? $sections[ $courante ]['statuts'] : array(),
				'image_id'  => $id,
				'image_url' => $src,
				'type_hub'  => $type,
				'hub_id'    => (int) ( $page['id'] ?? 0 ),
				'ordre'     => ++$ordre,
			);
			if ( $courante >= 0 ) {
				$sections[ $courante ]['slugs'][] = $cible;
			}
		}
		return array(
			'source_id'      => (int) ( $page['id'] ?? 0 ),
			'source_slug'    => $slug,
			'titre'          => (string) ( $page['title'] ?? '' ),
			'type'           => $type,
			'sections'       => $sections,
			'entrees'        => $entrees,
			'avertissements' => $avert,
		);
	}
}
