<?php
/**
 * Résultat d'une conversion DOCX ou EPUB (contrat §9).
 *
 * - chapters : liste de chapitres ['numero' => ?float, 'nature' => string, 'titre' => string,
 *   'sous_titre' => string, 'sous_numero' => ?int (sous-chapitre : rang dans le chapitre parent,
 *   dont il porte le numéro ; null sinon), 'chapitre_parent' => string (sous-chapitre : titre du
 *   chapitre parent, '' sinon), 'blocks' => string (blocs Gutenberg, images par jeton
 *   {{yume-image:<clé>}}), 'nb_mots' => int, 'stats' => array, 'images' => string[]] ;
 * - front_images : clés des images placées avant le premier chapitre (galerie du tome) ;
 * - images : clé => ['nom', 'mime', 'chemin_zip', 'largeur', 'hauteur', 'octets', 'alt'] (plus
 *   'conversion' => 'metafichier' pour une image EMF/WMF convertie en PNG à l'extraction ; 'mime'
 *   est alors le type de l'image produite) ;
 * - warnings : avertissements lisibles, en français ;
 * - stats : chiffres globaux (format, fichier, octets, hash, chapitres, mots, dialogues…) ;
 * - candidats : débuts de chapitre possibles, pour le découpage manuel (au plus CANDIDATS_MAX) :
 *   ['ancre' => 'e12-3fa9c1', 'rang' => int, 'type' => raison principale, 'raisons' => string[]
 *   (marqueur, titre, image, saut_page, separateur, gras, centre, ligne_courte, debut),
 *   'extrait' => 80 caractères, 'nature' et 'titre' proposés, 'auto' => la détection automatique
 *   (ou le découpage appliqué) commence un chapitre ici, 'avant' => élément placé avant le
 *   premier chapitre] ;
 * - decoupages : découpages rapides, ancres des débuts proposés ('images' : chaque illustration
 *   (la première d'une suite), 'ouvertures' : première illustration de chaque suite d'au moins
 *   deux (pages d'ouverture de chapitre), 'sauts' : chaque saut de page ; les ornements —
 *   petites images de Chapter_Builder::ORNEMENT_MAX pixels au plus — ne sont jamais proposés ;
 *   'titres' et 'sous_chapitres' : titres de niveau 1 et Titre 2, remplis seulement si le
 *   document a des Titre 2 — « Couper aux Titre 2 (sous-chapitres) ») ;
 * - douteux : paragraphes classés par déduction et non par un style nommé, à vérifier (au plus
 *   DOUTEUX_MAX, total dans stats['douteux'], stats['douteux_tronques'] au-delà) :
 *   ['chapitre' => index du chapitre dans chapters, 'rang' => rang de l'élément (celui des ancres),
 *   'motif' => clé de MOTIFS_DOUTEUX, 'extrait' => EXTRAIT_DOUTEUX caractères au plus].
 *
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Résultat de conversion.
 */
final class Result implements \JsonSerializable {

	/** Nombre maximal de débuts de chapitre possibles relevés. */
	public const CANDIDATS_MAX = 3000;

	/** Nombre maximal de paragraphes douteux relevés. */
	public const DOUTEUX_MAX = 200;

	/** Longueur maximale de l'extrait d'un paragraphe douteux (caractères). */
	public const EXTRAIT_DOUTEUX = 60;

	/**
	 * Motifs des paragraphes douteux : clé => explication lisible.
	 *
	 * @var array<string,string>
	 */
	public const MOTIFS_DOUTEUX = array(
		'tiret'    => 'Tiret tapé dans le texte : converti en dialogue',
		'liste'    => 'Paragraphe de liste sans puce tiret : gardé en récit',
		'italique' => 'Entièrement en italique, sans style Pensée : gardé en récit',
		'centre'   => 'Centré à la main, sans style Centré',
	);

	/**
	 * Chapitres dans l'ordre du document.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $chapters = array();

	/**
	 * Clés des images placées avant le premier chapitre.
	 *
	 * @var string[]
	 */
	public array $front_images = array();

	/**
	 * Images conservées : clé => description.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $images = array();

	/**
	 * Avertissements.
	 *
	 * @var string[]
	 */
	public array $warnings = array();

	/**
	 * Statistiques globales.
	 *
	 * @var array<string,mixed>
	 */
	public array $stats = array();

	/**
	 * Débuts de chapitre possibles, dans l'ordre du document.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $candidats = array();

	/**
	 * Paragraphes douteux, dans l'ordre du document.
	 *
	 * @var array<int,array{chapitre:int,rang:int,motif:string,extrait:string}>
	 */
	public array $douteux = array();

	/**
	 * Découpages rapides : 'images', 'ouvertures' et 'sauts' => ancres.
	 *
	 * @var array<string,string[]>
	 */
	public array $decoupages = array();

	/**
	 * Chemin du fichier source (pour extraire les images).
	 *
	 * @var string
	 */
	public string $source = '';

	/**
	 * Ajoute un avertissement (sans doublon).
	 *
	 * @param string $message Message.
	 */
	public function avertir( string $message ): void {
		if ( ! in_array( $message, $this->warnings, true ) ) {
			$this->warnings[] = $message;
		}
	}

	/**
	 * Extrait d'un paragraphe douteux : EXTRAIT_DOUTEUX caractères au plus, points de suspension
	 * compris.
	 *
	 * @param string $texte Texte brut.
	 */
	public static function extrait( string $texte ): string {
		$texte = Texte::espaces( $texte );
		if ( mb_strlen( $texte, 'UTF-8' ) <= self::EXTRAIT_DOUTEUX ) {
			return $texte;
		}
		return rtrim( mb_substr( $texte, 0, self::EXTRAIT_DOUTEUX - 1, 'UTF-8' ) ) . '…';
	}

	/**
	 * Copie une image de l'archive source vers un fichier (mémoire bornée). Un métafichier
	 * EMF/WMF est converti à ce moment (Metafichier::convertir()).
	 *
	 * @param string $cle         Clé de l'image.
	 * @param string $destination Fichier de destination.
	 */
	public function copier_image( string $cle, string $destination ): bool {
		if ( ! isset( $this->images[ $cle ] ) || '' === $this->source ) {
			return false;
		}
		try {
			$zip = Zip::ouvrir( $this->source, 'fichier' );
		} catch ( Import_Exception $e ) {
			return false;
		}
		$entree = (string) $this->images[ $cle ]['chemin_zip'];
		if ( 'metafichier' === ( $this->images[ $cle ]['conversion'] ?? '' ) ) {
			$ok = ! isset( Metafichier::convertir( $zip, $entree, $destination )['erreur'] );
		} else {
			$ok = $zip->copier( $entree, $destination );
		}
		$zip->fermer();
		return $ok;
	}

	/**
	 * Libellé court d'un chapitre (« 3 · La Crête », « Postface »).
	 *
	 * @param array<string,mixed> $chapitre Chapitre.
	 */
	public static function libelle( array $chapitre ): string {
		$titre = (string) $chapitre['titre'];
		return '' !== (string) $chapitre['sous_titre'] ? $titre . ' — ' . $chapitre['sous_titre'] : $titre;
	}

	/**
	 * Rapport sans le contenu des chapitres (analyse, API REST, outil en ligne de commande).
	 *
	 * @return array<string,mixed>
	 */
	public function rapport(): array {
		$chapitres = array();
		foreach ( $this->chapters as $i => $c ) {
			$chapitres[] = array(
				'index'           => $i,
				'numero'          => $c['numero'],
				'nature'          => $c['nature'],
				'titre'           => $c['titre'],
				'sous_titre'      => $c['sous_titre'],
				'sous_numero'     => $c['sous_numero'] ?? null,
				'chapitre_parent' => $c['chapitre_parent'] ?? '',
				'nb_mots'         => $c['nb_mots'],
				'stats'           => $c['stats'] ?? array(),
				'images'          => $c['images'] ?? array(),
			);
		}
		$images = array();
		foreach ( $this->images as $cle => $image ) {
			$images[ $cle ] = array_intersect_key( $image, array_flip( array( 'nom', 'mime', 'largeur', 'hauteur', 'octets', 'alt' ) ) );
		}
		return array(
			'chapitres'      => $chapitres,
			'front_images'   => $this->front_images,
			'images'         => $images,
			'avertissements' => $this->warnings,
			'stats'          => $this->stats,
			'candidats'      => $this->candidats,
			'decoupages'     => $this->decoupages,
			'douteux'        => $this->douteux,
		);
	}

	/**
	 * Tableau complet (contenu des chapitres compris).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'chapters'     => $this->chapters,
			'front_images' => $this->front_images,
			'images'       => $this->images,
			'warnings'     => $this->warnings,
			'stats'        => $this->stats,
		);
	}

	/**
	 * Sérialisation JSON.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->to_array();
	}
}
