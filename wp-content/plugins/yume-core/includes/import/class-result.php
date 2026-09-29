<?php
/**
 * Résultat d'une conversion DOCX ou EPUB (contrat §9).
 *
 * - chapters : liste de chapitres ['numero' => ?float, 'nature' => string, 'titre' => string,
 *   'sous_titre' => string, 'blocks' => string (blocs Gutenberg, images par jeton
 *   {{yume-image:<clé>}}), 'nb_mots' => int, 'stats' => array, 'images' => string[]] ;
 * - front_images : clés des images placées avant le premier chapitre (galerie du tome) ;
 * - images : clé => ['nom', 'mime', 'chemin_zip', 'largeur', 'hauteur', 'octets', 'alt'] (plus
 *   'conversion' => 'metafichier' pour une image EMF/WMF convertie en PNG à l'extraction ; 'mime'
 *   est alors le type de l'image produite) ;
 * - warnings : avertissements lisibles, en français ;
 * - stats : chiffres globaux (format, fichier, octets, hash, chapitres, mots, dialogues…).
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
				'index'      => $i,
				'numero'     => $c['numero'],
				'nature'     => $c['nature'],
				'titre'      => $c['titre'],
				'sous_titre' => $c['sous_titre'],
				'nb_mots'    => $c['nb_mots'],
				'stats'      => $c['stats'] ?? array(),
				'images'     => $c['images'] ?? array(),
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
