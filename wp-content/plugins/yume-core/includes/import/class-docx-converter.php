<?php
/**
 * Conversion DOCX → chapitres (docs/04 §2, contrat §9).
 *
 * Implémentation native (ZipArchive + XMLReader/DOMDocument), sans PHPWord :
 * - word/document.xml est lu en continu (XMLReader) : un élément de premier niveau du corps
 *   (paragraphe, tableau…) est développé à la fois, la mémoire reste bornée même pour un
 *   document de plusieurs dizaines de mégaoctets ; les images ne sont jamais lues en entier
 *   (seules leurs dimensions sont relevées) ;
 * - styles reconnus par identifiant, nom et niveau hiérarchique (Docx_Styles) ;
 * - Titre 1 → nouveau chapitre ; Titre 2 juste après → sous-titre ; Titre 2 isolé
 *   (« PostFace ») → chapitre spécial ; puce « — » ou paragraphe commençant par un tiret →
 *   dialogue ; style Pensée (ou texte au style de caractère Pensée) → pensée ; centré →
 *   yn-center ; ***, ◇, ✿… et vides multiples → séparateur ; autres puces → liste ;
 * - gras, italique, souligné, exposant, indice, sauts de ligne, liens externes ; runs
 *   contigus fusionnés ; espaces insécables françaises conservées (et ajoutées devant ? ! : ;) ;
 * - notes de bas de page et de fin → appels + liste yn-notes en fin de chapitre ;
 * - images JPG/PNG/WebP/GIF conservées (jeton) ; EMF/WMF/EMZ/WMZ converties en PNG quand elles
 *   portent une image bitmap (Metafichier), sinon ignorées et signalées avec leur raison ;
 *   images placées avant le premier chapitre → galerie du tome (front_images) ;
 * - sauts de page et de section relevés (débuts de chapitre possibles du découpage manuel),
 *   en-têtes et pieds de page, zones de texte ignorés.
 *
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

// Propriétés natives de DOM / XMLReader / ZipArchive (camelCase imposé par PHP).
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
// Messages d'exception en texte brut : échappés à l'affichage (esc_html, réponse JSON, terminal).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Convertisseur DOCX.
 */
final class Docx_Converter {

	/** Espaces de noms des relations (transitionnel et strict). */
	private const NS_R = array(
		'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
		'http://purl.oclc.org/ooxml/officeDocument/relationships',
	);

	/** Types MIME d'images conservées. */
	public const MIMES_IMAGES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** Extensions des métafichiers Word (convertis s'ils portent une image bitmap). */
	private const EXT_WORD = array( 'emf', 'wmf', 'emz', 'wmz' );

	/**
	 * Fichier source.
	 *
	 * @var string
	 */
	private string $chemin;

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Archive.
	 *
	 * @var Zip
	 */
	private Zip $zip;

	/**
	 * Résultat.
	 *
	 * @var Result
	 */
	private Result $resultat;

	/**
	 * Assemblage des chapitres.
	 *
	 * @var Chapter_Builder
	 */
	private Chapter_Builder $chapitres;

	/**
	 * Styles.
	 *
	 * @var Docx_Styles
	 */
	private Docx_Styles $styles;

	/**
	 * Partie principale (word/document.xml).
	 *
	 * @var string
	 */
	private string $partie = 'word/document.xml';

	/**
	 * Relations de la partie principale : rId => ['cible', 'externe', 'type'].
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $relations = array();

	/**
	 * Notes : « footnote:ID » / « endnote:ID » => élément w:footnote.
	 *
	 * @var array<string,\DOMElement>
	 */
	private array $notes = array();

	/**
	 * Documents XML des notes (gardés en vie tant que les éléments sont utilisés).
	 *
	 * @var \DOMDocument[]
	 */
	private array $docs_notes = array();

	/**
	 * Images déjà analysées : chemin dans l'archive => clé (null si ignorée).
	 *
	 * @var array<string,?string>
	 */
	private array $images_vues = array();

	/**
	 * Images EMF/WMF ignorées : [nom, position, raison].
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private array $vectorielles = array();

	/**
	 * Raison du refus de chaque métafichier non converti : chemin dans l'archive => raison.
	 *
	 * @var array<string,string>
	 */
	private array $raisons_emf = array();

	/**
	 * Nombre de métafichiers EMF/WMF convertis (fichiers distincts).
	 *
	 * @var int
	 */
	private int $emf_convertis = 0;

	/**
	 * Compteurs divers.
	 *
	 * @var array<string,int>
	 */
	private array $compteurs = array(
		'sauts_de_page'   => 0,
		'sections'        => 0,
		'zones_de_texte'  => 0,
		'images_ignorees' => 0,
		'objets'          => 0,
	);

	/**
	 * État des champs Word (pile : « instr » ou « resultat »).
	 *
	 * @var string[]
	 */
	private array $champs = array();

	/**
	 * Étiquette de chapitre en attente (« Prologue », « 1 », « Bonus »… centrée ou en gras) :
	 * arguments de paragraphe_contenu(), pour l'émettre telle quelle si aucun titre ne la suit.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $etiquette = null;

	/**
	 * Constructeur.
	 *
	 * @param string              $chemin  Fichier DOCX.
	 * @param array<string,mixed> $options Options (typographie : bool, défaut vrai).
	 */
	private function __construct( string $chemin, array $options ) {
		$this->chemin  = $chemin;
		$this->options = $options + array( 'typographie' => true );
	}

	/**
	 * Convertit un fichier DOCX (contrat §7).
	 *
	 * @param string              $path    Chemin du fichier.
	 * @param array<string,mixed> $options typographie (bool, défaut true) : espaces insécables
	 *                                     ajoutées devant ? ! : ; et dans les guillemets ;
	 *                                     volume_max (int, octets) : texte converti maximal
	 *                                     (défaut Chapter_Builder::VOLUME_MAX) ; plan (array) :
	 *                                     découpage manuel (Chapter_Builder::normaliser_plan()).
	 * @throws Import_Exception Fichier illisible ou qui n'est pas un DOCX.
	 */
	public static function convert_file( string $path, array $options = array() ): Result {
		return ( new self( $path, $options ) )->convertir();
	}

	/**
	 * Conversion.
	 *
	 * @throws Import_Exception Fichier invalide.
	 */
	private function convertir(): Result {
		$debut          = microtime( true );
		$this->resultat = new Result();
		$this->zip      = Zip::ouvrir( $this->chemin, 'document DOCX' );
		$precedent      = libxml_use_internal_errors( true );
		try {
			$this->resultat->source = $this->zip->chemin();
			$this->localiser_document();
			$this->relations = $this->lire_relations( $this->partie );
			$this->styles    = new Docx_Styles( $this->xml_relation( 'styles' ), $this->xml_relation( 'numbering' ) );
			$this->lire_notes( 'footnotes', 'footnote' );
			$this->lire_notes( 'endnotes', 'endnote' );
			$this->chapitres = new Chapter_Builder( $this->resultat, (int) ( $this->options['volume_max'] ?? Chapter_Builder::VOLUME_MAX ), is_array( $this->options['plan'] ?? null ) ? $this->options['plan'] : null );
			$this->parcourir();
			$this->vider_etiquette();
			$this->chapitres->terminer();
		} finally {
			$this->zip->fermer();
			libxml_clear_errors();
			libxml_use_internal_errors( $precedent );
		}
		$this->conclure( $debut );
		return $this->resultat;
	}

	/**
	 * Trouve la partie principale (_rels/.rels → officeDocument).
	 *
	 * @throws Import_Exception Ce n'est pas un document Word.
	 */
	private function localiser_document(): void {
		$rels = $this->lire_relations( '' );
		foreach ( $rels as $rel ) {
			if ( ! $rel['externe'] && str_ends_with( (string) $rel['type'], '/officeDocument' ) && $this->zip->existe( (string) $rel['cible'] ) ) {
				$this->partie = (string) $this->zip->nom_reel( (string) $rel['cible'] );
				return;
			}
		}
		if ( $this->zip->existe( 'word/document.xml' ) ) {
			$this->partie = (string) $this->zip->nom_reel( 'word/document.xml' );
			return;
		}
		if ( $this->zip->existe( 'META-INF/container.xml' ) || $this->zip->existe( 'mimetype' ) ) {
			throw new Import_Exception( 'Ce fichier est un EPUB : déposez-le avec l’extension .epub.', 'mauvais_format' );
		}
		throw new Import_Exception( 'Ce fichier n’est pas un document Word (.docx) : le texte principal est introuvable.', 'docx_invalide' );
	}

	/**
	 * Charge une partie XML en DOM (sans DTD ni accès réseau).
	 *
	 * @param string $entree Chemin dans l'archive.
	 * @throws Import_Exception XML invalide ou suspect.
	 */
	private function charger_xml( string $entree ): ?\DOMDocument {
		$xml = $this->zip->lire( $entree );
		if ( null === $xml ) {
			return null;
		}
		if ( false !== stripos( substr( $xml, 0, 2048 ), '<!DOCTYPE' ) ) {
			throw new Import_Exception( 'Document refusé : il contient une déclaration de type (DOCTYPE) inattendue.', 'docx_suspect' );
		}
		$doc = new \DOMDocument();
		if ( ! $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT ) ) {
			throw new Import_Exception( sprintf( 'Le document Word est endommagé (partie « %s » illisible).', $entree ), 'docx_endommage' );
		}
		// DOCTYPE repoussé après les 2 premiers Ko ou encodé autrement (UTF-16) : refusé aussi.
		if ( null !== $doc->doctype ) {
			throw new Import_Exception( 'Document refusé : il contient une déclaration de type (DOCTYPE) inattendue.', 'docx_suspect' );
		}
		return $doc;
	}

	/**
	 * Relations d'une partie (fichier _rels/<partie>.rels).
	 *
	 * @param string $partie Partie (chaîne vide : relations du paquet).
	 * @return array<string,array<string,mixed>>
	 */
	private function lire_relations( string $partie ): array {
		if ( '' === $partie ) {
			$fichier = '_rels/.rels';
		} else {
			$dossier = str_contains( $partie, '/' ) ? substr( $partie, 0, (int) strrpos( $partie, '/' ) + 1 ) : '';
			$fichier = $dossier . '_rels/' . basename( $partie ) . '.rels';
		}
		$doc = $this->charger_xml( $fichier );
		if ( null === $doc || null === $doc->documentElement ) {
			return array();
		}
		$relations = array();
		foreach ( $doc->documentElement->childNodes as $el ) {
			if ( ! $el instanceof \DOMElement || 'Relationship' !== $el->localName ) {
				continue;
			}
			$externe                                = 'external' === strtolower( $el->getAttribute( 'TargetMode' ) );
			$cible                                  = $el->getAttribute( 'Target' );
			$relations[ $el->getAttribute( 'Id' ) ] = array(
				'cible'   => $externe ? $cible : Zip::resoudre( '' === $partie ? '' : $partie, $cible ),
				'externe' => $externe,
				'type'    => $el->getAttribute( 'Type' ),
			);
		}
		return $relations;
	}

	/**
	 * Partie XML liée à la partie principale par un type de relation (styles, numbering…).
	 *
	 * @param string $type Fin du type de relation.
	 */
	private function xml_relation( string $type ): ?\DOMDocument {
		foreach ( $this->relations as $rel ) {
			if ( ! $rel['externe'] && str_ends_with( (string) $rel['type'], '/' . $type ) ) {
				return $this->charger_xml( (string) $rel['cible'] );
			}
		}
		return null;
	}

	/**
	 * Indexe les notes de bas de page ou de fin.
	 *
	 * @param string $type    Type de relation (footnotes, endnotes).
	 * @param string $element Élément (footnote, endnote).
	 */
	private function lire_notes( string $type, string $element ): void {
		$doc = $this->xml_relation( $type );
		if ( null === $doc || null === $doc->documentElement ) {
			return;
		}
		$this->docs_notes[] = $doc;
		foreach ( Docx_Styles::enfants( $doc->documentElement, $element ) as $note ) {
			$genre = (string) Docx_Styles::attr( $note, 'type' );
			if ( in_array( $genre, array( 'separator', 'continuationSeparator', 'continuationNotice' ), true ) ) {
				continue;
			}
			$this->notes[ $element . ':' . Docx_Styles::attr( $note, 'id' ) ] = $note;
		}
	}

	/**
	 * Parcours en continu du corps du document.
	 *
	 * @throws Import_Exception Document illisible.
	 */
	private function parcourir(): void {
		if ( $this->zip->taille( $this->partie ) > Zip::DOCUMENT_MAX ) {
			throw new Import_Exception( sprintf( 'Document refusé : son texte décompressé dépasse %d Mo.', (int) ( Zip::DOCUMENT_MAX / ( 1024 * 1024 ) ) ), 'docx_trop_grand' );
		}
		// Taux de compression anormal (bombe ZIP) : refus avant toute lecture.
		$this->zip->controler_taux( $this->partie );
		$debut = $this->zip->debut( $this->partie, 2048 );
		if ( false !== stripos( $debut, '<!DOCTYPE' ) ) {
			throw new Import_Exception( 'Document refusé : il contient une déclaration de type (DOCTYPE) inattendue.', 'docx_suspect' );
		}
		$lecteur = new \XMLReader();
		if ( ! $lecteur->open( $this->zip->uri( $this->partie ), null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			throw new Import_Exception( 'Le texte du document Word est illisible.', 'docx_endommage' );
		}
		$profondeur = -1;
		while ( $lecteur->read() ) {
			// DOCTYPE repoussé après les 2 premiers Ko ou encodé autrement (UTF-16) : refusé aussi.
			if ( \XMLReader::DOC_TYPE === $lecteur->nodeType ) {
				$lecteur->close();
				throw new Import_Exception( 'Document refusé : il contient une déclaration de type (DOCTYPE) inattendue.', 'docx_suspect' );
			}
			if ( \XMLReader::ELEMENT === $lecteur->nodeType && 'body' === $lecteur->localName ) {
				$profondeur = $lecteur->depth;
				break;
			}
		}
		if ( $profondeur < 0 ) {
			$lecteur->close();
			throw new Import_Exception( 'Le document Word ne contient pas de texte (corps absent).', 'docx_vide' );
		}
		$base   = new \DOMDocument();
		$traite = 0;
		$fini   = false;
		$ok     = $lecteur->read();
		while ( $ok ) {
			if ( \XMLReader::END_ELEMENT === $lecteur->nodeType && $lecteur->depth === $profondeur ) {
				$fini = true;
				break;
			}
			if ( \XMLReader::ELEMENT === $lecteur->nodeType && $lecteur->depth === $profondeur + 1 ) {
				$noeud = $lecteur->expand( $base );
				if ( $noeud instanceof \DOMElement ) {
					$this->bloc( $noeud );
				}
				++$traite;
				if ( 0 === $traite % 500 ) {
					$base = new \DOMDocument(); // Libère les nœuds déjà traités.
				}
				$ok = $lecteur->next();
				continue;
			}
			$ok = $lecteur->read();
		}
		$lecteur->close();
		if ( ! $fini ) {
			throw new Import_Exception( 'Le document Word est endommagé ou tronqué (XML invalide).', 'docx_endommage' );
		}
	}

	/**
	 * Élément de premier niveau du corps (ou d'une cellule, d'un contrôle de contenu).
	 *
	 * @param \DOMElement $el Élément.
	 */
	private function bloc( \DOMElement $el ): void {
		if ( 'p' !== $el->localName && 'sectPr' !== $el->localName ) {
			$this->vider_etiquette();
		}
		switch ( $el->localName ) {
			case 'p':
				$this->paragraphe( $el );
				break;
			case 'tbl':
				foreach ( Docx_Styles::enfants( $el, 'tr' ) as $ligne ) {
					foreach ( Docx_Styles::enfants( $ligne, 'tc' ) as $cellule ) {
						$this->blocs_enfants( $cellule );
					}
				}
				break;
			case 'sdt':
				$contenu = Docx_Styles::enfant( $el, 'sdtContent' );
				if ( $contenu ) {
					$this->blocs_enfants( $contenu );
				}
				break;
			case 'customXml':
			case 'ins':
			case 'moveTo':
				$this->blocs_enfants( $el );
				break;
			case 'sectPr':
				++$this->compteurs['sections'];
				break;
			case 'altChunk':
				$this->resultat->avertir( 'Un contenu importé (fragment HTML ou RTF incorporé) a été ignoré.' );
				break;
		}
	}

	/**
	 * Traite les éléments enfants de niveau bloc.
	 *
	 * @param \DOMElement $conteneur Conteneur.
	 */
	private function blocs_enfants( \DOMElement $conteneur ): void {
		for ( $n = $conteneur->firstChild; null !== $n; $n = $n->nextSibling ) {
			if ( $n instanceof \DOMElement ) {
				$this->bloc( $n );
			}
		}
	}

	/**
	 * Paragraphe.
	 *
	 * @param \DOMElement $p Élément w:p.
	 */
	private function paragraphe( \DOMElement $p ): void {
		$ppr      = Docx_Styles::enfant( $p, 'pPr' );
		$style_id = (string) Docx_Styles::attr( Docx_Styles::enfant( $ppr, 'pStyle' ) );
		$props    = $this->styles->paragraphe( $style_id );
		if ( Docx_Styles::enfant( $ppr, 'sectPr' ) ) {
			++$this->compteurs['sections'];
		}

		// Numérotation : directe, sinon celle du style (numId « 0 » : aucune).
		$num_id = $props['num_id'];
		$ilvl   = (int) ( $props['ilvl'] ?? 0 );
		$numpr  = Docx_Styles::enfant( $ppr, 'numPr' );
		if ( $numpr ) {
			$direct = Docx_Styles::attr( Docx_Styles::enfant( $numpr, 'numId' ) );
			if ( null !== $direct ) {
				$num_id = $direct;
			}
			$niveau_direct = Docx_Styles::attr( Docx_Styles::enfant( $numpr, 'ilvl' ) );
			if ( null !== $niveau_direct ) {
				$ilvl = (int) $niveau_direct;
			}
		}
		$niveau = null !== $num_id ? $this->styles->niveau( (string) $num_id, $ilvl ) : null;

		$jc      = Docx_Styles::attr( Docx_Styles::enfant( $ppr, 'jc' ) ) ?? $props['jc'];
		$role    = $props['role'];
		$outline = Docx_Styles::attr( Docx_Styles::enfant( $ppr, 'outlineLvl' ) );
		if ( null !== $outline && ! in_array( $role, array( 'pensee', 'dialogue', 'sommaire' ), true ) ) {
			$role = Docx_Styles::role_niveau( (int) $outline ) ?? ( in_array( $role, array( 'titre1', 'titre2', 'titre3' ), true ) ? null : $role );
		}

		$etat         = array(
			'items'      => array(),
			'car_total'  => 0,
			'car_pensee' => 0,
			'car_italic' => 0,
			'car_gras'   => 0,
			'ignorees'   => 0,
			'saut'       => '', // Saut de page : « avant » ou « apres » le texte du paragraphe.
		);
		$this->champs = array();
		$this->contenu( $p, $props['rpr'], $etat, '' );
		$items = $etat['items'];

		// Saut de page (début de chapitre possible) : avant le paragraphe, ou après lui (saut
		// placé après son texte, fin d'une section « page suivante »).
		$avant_para = Docx_Styles::enfant( $ppr, 'pageBreakBefore' );
		if ( $avant_para && ! in_array( (string) Docx_Styles::attr( $avant_para ), array( '0', 'false', 'off' ), true ) ) {
			$etat['saut'] = 'avant';
		}
		$section = Docx_Styles::enfant( $ppr, 'sectPr' );
		$apres   = $section && 'continuous' !== (string) Docx_Styles::attr( Docx_Styles::enfant( $section, 'type' ) );
		if ( 'avant' === $etat['saut'] ) {
			$this->chapitres->indice( 'saut_page' );
		}
		$apres = $apres || 'apres' === $etat['saut'];
		$this->paragraphe_contenu( $etat, $role, $niveau, $jc, $ppr );
		if ( $apres ) {
			$this->chapitres->indice( 'saut_page' );
		}
	}

	/**
	 * Émet le contenu d'un paragraphe lu (titre, ligne vide, texte et images).
	 *
	 * @param array<string,mixed>      $etat   Segments et compteurs du paragraphe.
	 * @param string|null              $role   Rôle du style.
	 * @param array<string,mixed>|null $niveau Niveau de liste.
	 * @param string|null              $jc     Alignement.
	 * @param \DOMElement|null         $ppr    Propriétés du paragraphe.
	 * @param bool                     $etiquettes Reconnaître une étiquette de chapitre.
	 */
	private function paragraphe_contenu( array $etat, ?string $role, ?array $niveau, ?string $jc, ?\DOMElement $ppr, bool $etiquettes = true ): void {
		$items = $etat['items'];

		$texte  = Texte::espaces( Inline::texte_brut( array_filter( $items, static fn( $i ) => 'image' !== $i['type'] ) ) );
		$images = array_values( array_filter( $items, static fn( $i ) => 'image' === $i['type'] ) );

		// Étiquette de chapitre (« Prologue », « 1 », « Bonus »…) sur sa propre ligne, centrée ou
		// en gras, juste avant le titre : elle donne sa nature et son numéro au chapitre.
		if ( null !== $this->etiquette ) {
			if ( '' === $texte && ! $images ) {
				return; // Ligne vide entre l'étiquette et le titre.
			}
			if ( '' === $texte || ! in_array( $role, array( 'titre1', 'titre2' ), true ) ) {
				$this->vider_etiquette();
			}
		} elseif ( $etiquettes && $this->est_etiquette( $texte, $images, $role, $niveau, $jc, $etat ) ) {
			$this->etiquette = array(
				'etat'   => $etat,
				'role'   => $role,
				'niveau' => $niveau,
				'jc'     => $jc,
				'ppr'    => $ppr,
				'texte'  => $texte,
			);
			return;
		}

		if ( '' !== $texte && in_array( $role, array( 'titre1', 'titre2', 'titre3' ), true ) ) {
			$brut = Inline::texte_brut( array_filter( $items, static fn( $i ) => 'texte' === $i['type'] || 'br' === $i['type'] ) );
			if ( null !== $this->etiquette ) {
				$brut            = self::titre_etiquete( (string) $this->etiquette['texte'], $brut );
				$role            = 'titre1';
				$this->etiquette = null;
			}
			$this->titre( $role, $brut );
			foreach ( $images as $image ) {
				$this->chapitres->image( (string) $image['cle'] );
			}
			return;
		}
		if ( '' === $texte && ! $images ) {
			if ( $etat['ignorees'] > 0 ) {
				// Image ignorée (EMF…) : ni contenu ni ligne vide.
				$this->chapitres->neutre();
			} elseif ( 'sommaire' !== $role && ! Docx_Styles::enfant( $ppr, 'sectPr' ) ) {
				// Un paragraphe qui ne porte qu'un saut de section n'est pas une ligne vide.
				$this->chapitres->vide();
			}
			return;
		}
		if ( 'sommaire' === $role ) {
			return; // Table des matières Word : jamais reprise.
		}

		// Découpe du paragraphe autour des images qu'il contient.
		$partie = array();
		foreach ( $items as $item ) {
			if ( 'image' === $item['type'] ) {
				$this->emettre( $partie, $role, $niveau, $jc, $etat );
				$partie = array();
				$this->chapitres->image( (string) $item['cle'] );
				continue;
			}
			$partie[] = $item;
		}
		$this->emettre( $partie, $role, $niveau, $jc, $etat );
	}

	/**
	 * Paragraphe d'étiquette de chapitre : texte seul « Prologue », « Épilogue », « Interlude »,
	 * « Bonus », « Postface », « Chapitre 3 », un nombre (« 1 ») ou un chiffre romain, centré ou
	 * entièrement en gras, hors liste, dialogue et pensée.
	 *
	 * @param string                   $texte  Texte du paragraphe.
	 * @param array                    $images Images du paragraphe.
	 * @param string|null              $role   Rôle du style.
	 * @param array<string,mixed>|null $niveau Niveau de liste.
	 * @param string|null              $jc     Alignement.
	 * @param array<string,mixed>      $etat   Compteurs de caractères.
	 */
	private function est_etiquette( string $texte, array $images, ?string $role, ?array $niveau, ?string $jc, array $etat ): bool {
		if ( '' === $texte || $images || null !== $niveau || null !== $role || mb_strlen( $texte ) > 40 ) {
			return false;
		}
		$gras = (int) $etat['car_total'] > 0 && (int) $etat['car_gras'] >= (int) $etat['car_total'];
		if ( 'center' !== $jc && ! $gras ) {
			return false;
		}
		return (bool) preg_match( '/^(?:\d{1,3}|[IVXLC]{1,6}|(?:chapitre|chapter)\s+\S+|prologue|[ée]pilogue|interlude(?:\s+\S+)?|bonus(?:\s+\S+)?|histoire\s+bonus|postface|extra)$/iu', $texte );
	}

	/**
	 * Titre complété par son étiquette : « 1 » + « Le Début… » → « Chapitre 1 : Le Début… »,
	 * « Bonus » + « L’Histoire d’Ira » → « Bonus : L’Histoire d’Ira ». Un titre qui porte
	 * déjà son numéro ou sa nature (« Chapitre 3 : … ») reste tel quel.
	 *
	 * @param string $etiquette Texte de l'étiquette.
	 * @param string $titre     Texte brut du titre.
	 */
	private static function titre_etiquete( string $etiquette, string $titre ): string {
		$premiere = (string) ( explode( "\n", ltrim( $titre, "\n" ) )[0] ?? '' );
		if ( 'inconnu' !== Texte::analyser_titre( $premiere )['motif'] ) {
			return $titre;
		}
		$libelle = preg_match( '/^(?:\d{1,3}|[IVXLC]{1,6})$/u', $etiquette ) ? 'Chapitre ' . $etiquette : $etiquette;
		return $libelle . ' : ' . ltrim( $titre, "\n" );
	}

	/**
	 * Émet l'étiquette en attente comme un paragraphe ordinaire (aucun titre ne la suit).
	 */
	private function vider_etiquette(): void {
		if ( null === $this->etiquette ) {
			return;
		}
		$e               = $this->etiquette;
		$this->etiquette = null;
		$this->paragraphe_contenu( $e['etat'], $e['role'], $e['niveau'], $e['jc'], $e['ppr'], false );
	}

	/**
	 * Émet la partie texte d'un paragraphe selon sa nature.
	 *
	 * @param array<int,array<string,mixed>> $items  Segments.
	 * @param string|null                    $role   Rôle du style.
	 * @param array<string,mixed>|null       $niveau Niveau de liste.
	 * @param string|null                    $jc     Alignement.
	 * @param array<string,int|array>        $etat   Compteurs de caractères du paragraphe.
	 */
	private function emettre( array $items, ?string $role, ?array $niveau, ?string $jc, array $etat ): void {
		$texte = Texte::espaces( Inline::texte_brut( $items ) );
		if ( '' === $texte ) {
			return;
		}
		if ( 'separateur' === $role || Texte::est_separateur( $texte ) ) {
			$this->chapitres->separateur();
			return;
		}
		$total = max( 1, (int) $etat['car_total'] );
		// Paragraphe entièrement en gras : début de chapitre possible (titre sans style).
		if ( (int) $etat['car_gras'] >= (int) $etat['car_total'] && (int) $etat['car_total'] > 0 ) {
			$this->chapitres->indice( 'gras' );
		}
		$pensee  = 'pensee' === $role || ( null === $niveau && (int) $etat['car_pensee'] * 2 >= $total && (int) $etat['car_pensee'] > 0 );
		$inverse = $pensee && (int) $etat['car_italic'] * 2 >= $total;
		$html    = Inline::html( $items, $inverse );
		if ( '' === $html ) {
			return;
		}
		if ( $this->options['typographie'] ) {
			$html = Texte::typographie( $html );
		}
		if ( $pensee ) {
			$this->chapitres->paragraphe( $html, 'pensee', 'center' === $jc );
			return;
		}
		if ( null !== $niveau && $niveau['tiret'] ) {
			$this->chapitres->paragraphe( Texte::normaliser_dialogue( $html, true ), 'dialogue' );
			return;
		}
		if ( null !== $niveau ) {
			$this->chapitres->element_liste( $html, (bool) $niveau['ordonnee'] );
			return;
		}
		if ( 'dialogue' === $role ) {
			$this->chapitres->paragraphe( Texte::normaliser_dialogue( $html, true ), 'dialogue' );
			return;
		}
		if ( Texte::commence_par_tiret( $texte ) ) {
			$this->chapitres->paragraphe( Texte::normaliser_dialogue( $html, false ), 'dialogue' );
			return;
		}
		$this->chapitres->paragraphe( $html, '', 'center' === $jc );
	}

	/**
	 * Titre (Titre 1, Titre 2, Titre 3…).
	 *
	 * @param string $role  titre1, titre2 ou titre3.
	 * @param string $brut  Texte brut (sauts de ligne compris).
	 */
	private function titre( string $role, string $brut ): void {
		$lignes = array_values( array_filter( array_map( array( Texte::class, 'espaces' ), explode( "\n", $brut ) ), static fn( $l ) => '' !== $l ) );
		$ligne  = (string) ( $lignes[0] ?? '' );
		$reste  = implode( ' ', array_slice( $lignes, 1 ) );
		$brut_1 = (string) ( explode( "\n", ltrim( $brut, "\n" ) )[0] ?? $ligne );

		if ( 'titre1' === $role ) {
			$analyse = Texte::analyser_titre( $brut_1 );
			if ( 'inconnu' === $analyse['motif'] && $this->chapitres->attend_sous_titre() && $this->chapitres->titre_reconnu() ) {
				// Deux lignes au style Titre 1 : la seconde est le sous-titre.
				$this->chapitres->sous_titre( trim( $ligne . ' ' . $reste ) );
				return;
			}
			$this->chapitres->ouvrir( $analyse, $brut_1 );
			if ( '' !== $reste && '' === $analyse['sous_titre'] ) {
				$this->chapitres->sous_titre( $reste );
			}
			return;
		}

		$tout = trim( $ligne . ' ' . $reste );
		if ( 'titre2' === $role && $this->chapitres->attend_sous_titre() ) {
			$this->chapitres->sous_titre( $tout );
			return;
		}
		$analyse = Texte::analyser_titre( $brut_1 );
		if ( 'titre2' === $role && 'inconnu' !== $analyse['motif'] ) {
			if ( 'chapitre' === $analyse['motif'] ) {
				$this->resultat->avertir( sprintf( 'Titre « %s » au style Titre 2 : traité comme un nouveau chapitre (utilisez le style Titre 1).', $ligne ) );
			}
			$this->chapitres->ouvrir( $analyse, $brut_1 );
			if ( '' !== $reste && '' === $analyse['sous_titre'] ) {
				$this->chapitres->sous_titre( $reste );
			}
			return;
		}
		$this->chapitres->titre( Blocks::texte( $tout ), 'titre2' === $role ? 2 : 3 );
	}

	/**
	 * Lit le contenu en ligne d'un élément (paragraphe, lien, insertion…).
	 *
	 * @param \DOMElement         $conteneur Élément.
	 * @param array<string,mixed> $rpr_p     Mise en forme du style de paragraphe.
	 * @param array<string,mixed> $etat      Segments et compteurs (modifié).
	 * @param string              $lien      URL du lien englobant.
	 */
	private function contenu( \DOMElement $conteneur, array $rpr_p, array &$etat, string $lien ): void {
		for ( $n = $conteneur->firstChild; null !== $n; $n = $n->nextSibling ) {
			if ( ! $n instanceof \DOMElement ) {
				continue;
			}
			switch ( $n->localName ) {
				case 'r':
					$this->run( $n, $rpr_p, $etat, $lien );
					break;
				case 'hyperlink':
					$url = '';
					foreach ( self::NS_R as $ns ) {
						if ( $n->hasAttributeNS( $ns, 'id' ) ) {
							$rel = $this->relations[ $n->getAttributeNS( $ns, 'id' ) ] ?? null;
							if ( $rel && $rel['externe'] && preg_match( '#^https?://#i', (string) $rel['cible'] ) ) {
								$url = (string) $rel['cible'];
							}
						}
					}
					$this->contenu( $n, $rpr_p, $etat, $url );
					break;
				case 'ins':
				case 'moveTo':
				case 'smartTag':
				case 'customXml':
				case 'fldSimple':
				case 'bdo':
				case 'dir':
					$this->contenu( $n, $rpr_p, $etat, $lien );
					break;
				case 'sdt':
					$sdt = Docx_Styles::enfant( $n, 'sdtContent' );
					if ( $sdt ) {
						$this->contenu( $sdt, $rpr_p, $etat, $lien );
					}
					break;
				case 'AlternateContent':
					$choix = Docx_Styles::enfant( $n, 'Choice' ) ?? Docx_Styles::enfant( $n, 'Fallback' );
					if ( $choix ) {
						$this->contenu( $choix, $rpr_p, $etat, $lien );
					}
					break;
			}
		}
	}

	/**
	 * Lit un run (w:r).
	 *
	 * @param \DOMElement         $r     Run.
	 * @param array<string,mixed> $rpr_p Mise en forme du style de paragraphe.
	 * @param array<string,mixed> $etat  Segments et compteurs (modifié).
	 * @param string              $lien  URL du lien englobant.
	 */
	private function run( \DOMElement $r, array $rpr_p, array &$etat, string $lien ): void {
		$direct = Docx_Styles::lire_rpr( Docx_Styles::enfant( $r, 'rPr' ) );
		$forme  = $rpr_p;
		$pensee = false;
		if ( null !== $direct['style'] ) {
			$car    = $this->styles->caractere( (string) $direct['style'] );
			$forme  = Docx_Styles::fusionner( $forme, $car['rpr'] );
			$pensee = 'pensee' === $car['role'];
		}
		$forme = Docx_Styles::fusionner( $forme, $direct );
		if ( ! empty( $forme['cache'] ) ) {
			return; // Texte masqué.
		}
		$f     = array(
			'b'    => ! empty( $forme['b'] ),
			'i'    => ! empty( $forme['i'] ),
			'u'    => ! empty( $forme['u'] ),
			'va'   => (string) ( $forme['va'] ?? '' ),
			'lien' => $lien,
		);
		$texte = '';
		$vider = static function () use ( &$texte, &$etat, $f, $pensee ): void {
			if ( '' === $texte ) {
				return;
			}
			$etat['items'][]     = Inline::texte( $texte, $f );
			$longueur            = mb_strlen( trim( $texte ), 'UTF-8' );
			$etat['car_total']  += $longueur;
			$etat['car_pensee'] += $pensee ? $longueur : 0;
			$etat['car_italic'] += $f['i'] ? $longueur : 0;
			$etat['car_gras']   += $f['b'] ? $longueur : 0;
			$texte               = '';
		};
		for ( $n = $r->firstChild; null !== $n; $n = $n->nextSibling ) {
			if ( ! $n instanceof \DOMElement ) {
				continue;
			}
			$instr = $this->champs && 'instr' === end( $this->champs );
			switch ( $n->localName ) {
				case 't':
					if ( ! $instr ) {
						$texte .= $n->textContent;
					}
					break;
				case 'tab':
				case 'ptab':
					$texte .= $instr ? '' : ' ';
					break;
				case 'noBreakHyphen':
					$texte .= "\u{2011}";
					break;
				case 'br':
					$genre = (string) Docx_Styles::attr( $n, 'type' );
					if ( 'page' === $genre ) {
						++$this->compteurs['sauts_de_page'];
						if ( '' === $etat['saut'] ) {
							$etat['saut'] = $etat['car_total'] > 0 || '' !== trim( $texte ) ? 'apres' : 'avant';
						}
						$texte .= ' ';
					} elseif ( 'column' === $genre ) {
						$texte .= ' ';
					} elseif ( ! $instr ) {
						$vider();
						$etat['items'][] = Inline::saut();
					}
					break;
				case 'cr':
					$vider();
					$etat['items'][] = Inline::saut();
					break;
				case 'sym':
					$code = strtoupper( (string) Docx_Styles::attr( $n, 'char' ) );
					if ( in_array( $code, array( 'F02D', '002D', 'F0BE' ), true ) ) {
						$texte .= '—';
					}
					break;
				case 'fldChar':
					$genre = (string) Docx_Styles::attr( $n, 'fldCharType' );
					if ( 'begin' === $genre ) {
						$this->champs[] = 'instr';
					} elseif ( 'separate' === $genre && $this->champs ) {
						$this->champs[ count( $this->champs ) - 1 ] = 'resultat';
					} elseif ( 'end' === $genre ) {
						array_pop( $this->champs );
					}
					break;
				case 'footnoteReference':
				case 'endnoteReference':
					$vider();
					$genre = 'footnoteReference' === $n->localName ? 'footnote' : 'endnote';
					$appel = $this->note( $genre . ':' . Docx_Styles::attr( $n, 'id' ) );
					if ( '' !== $appel ) {
						$etat['items'][] = Inline::brut( $appel );
					}
					break;
				case 'drawing':
				case 'pict':
				case 'object':
					$vider();
					$this->images( $n, $etat );
					break;
				case 'AlternateContent':
					$choix = Docx_Styles::enfant( $n, 'Choice' ) ?? Docx_Styles::enfant( $n, 'Fallback' );
					if ( $choix ) {
						$vider();
						$this->images( $choix, $etat );
					}
					break;
				case 'ruby':
					$base = Docx_Styles::enfant( $n, 'rubyBase' );
					if ( $base ) {
						$vider();
						$this->contenu( $base, $rpr_p, $etat, $lien );
					}
					break;
			}
		}
		$vider();
	}

	/**
	 * Images d'un dessin (w:drawing), d'un objet VML (w:pict) ou OLE (w:object).
	 *
	 * @param \DOMElement         $el   Élément.
	 * @param array<string,mixed> $etat Segments (modifié).
	 */
	private function images( \DOMElement $el, array &$etat ): void {
		if ( $el->getElementsByTagNameNS( '*', 'txbxContent' )->length > 0 ) {
			++$this->compteurs['zones_de_texte'];
		}
		$alt = '';
		foreach ( $el->getElementsByTagNameNS( '*', 'docPr' ) as $doc_pr ) {
			if ( $doc_pr instanceof \DOMElement ) {
				$alt = trim( $doc_pr->getAttribute( 'descr' ) );
				if ( '' === $alt ) {
					$alt = trim( $doc_pr->getAttribute( 'title' ) );
				}
				break;
			}
		}
		$references = array();
		foreach ( $el->getElementsByTagNameNS( '*', 'blip' ) as $blip ) {
			if ( ! $blip instanceof \DOMElement ) {
				continue;
			}
			foreach ( self::NS_R as $ns ) {
				if ( $blip->hasAttributeNS( $ns, 'embed' ) ) {
					$references[] = $blip->getAttributeNS( $ns, 'embed' );
				} elseif ( $blip->hasAttributeNS( $ns, 'link' ) ) {
					$this->resultat->avertir( 'Une image liée (non incorporée au document) a été ignorée : insérez-la dans le fichier Word.' );
					++$this->compteurs['images_ignorees'];
					++$etat['ignorees'];
				}
			}
		}
		foreach ( $el->getElementsByTagNameNS( '*', 'imagedata' ) as $donnees ) {
			if ( ! $donnees instanceof \DOMElement ) {
				continue;
			}
			foreach ( self::NS_R as $ns ) {
				if ( $donnees->hasAttributeNS( $ns, 'id' ) ) {
					$references[] = $donnees->getAttributeNS( $ns, 'id' );
				}
			}
		}
		if ( 'object' === $el->localName && ! $references ) {
			++$this->compteurs['objets'];
		}
		foreach ( array_unique( $references ) as $rid ) {
			$rel = $this->relations[ $rid ] ?? null;
			if ( null === $rel ) {
				continue;
			}
			if ( $rel['externe'] ) {
				$this->resultat->avertir( 'Une image liée (non incorporée au document) a été ignorée : insérez-la dans le fichier Word.' );
				++$this->compteurs['images_ignorees'];
				++$etat['ignorees'];
				continue;
			}
			$cle = $this->image( (string) $rel['cible'], $alt );
			if ( null !== $cle ) {
				$etat['items'][] = array(
					'type' => 'image',
					'cle'  => $cle,
				);
			} else {
				++$etat['ignorees'];
			}
		}
	}

	/**
	 * Analyse et enregistre une image de l'archive.
	 *
	 * @param string $chemin Chemin dans l'archive.
	 * @param string $alt    Texte alternatif (description Word).
	 * @return string|null Clé de l'image, ou null si elle est ignorée.
	 */
	private function image( string $chemin, string $alt ): ?string {
		$nom = basename( $chemin );
		$ext = strtolower( pathinfo( $chemin, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::EXT_WORD, true ) ) {
			if ( ! array_key_exists( $chemin, $this->images_vues ) ) {
				$this->images_vues[ $chemin ] = $this->metafichier( $chemin, $alt );
			}
			if ( null === $this->images_vues[ $chemin ] ) {
				$this->vectorielles[] = array( $nom, $this->chapitres->en_chapitre() ? $this->position() : 'avant le premier chapitre', $this->raisons_emf[ $chemin ] ?? '' );
				++$this->compteurs['images_ignorees'];
			}
			return $this->images_vues[ $chemin ];
		}
		if ( array_key_exists( $chemin, $this->images_vues ) ) {
			return $this->images_vues[ $chemin ];
		}
		$this->images_vues[ $chemin ] = null;
		$cle                          = self::analyser_image( $this->zip, $this->resultat, $chemin, $alt );
		if ( null === $cle ) {
			++$this->compteurs['images_ignorees'];
		}
		$this->images_vues[ $chemin ] = $cle;
		return $cle;
	}

	/**
	 * Métafichier Word (EMF, WMF, EMZ, WMZ) : ajouté au Result s'il porte une image bitmap
	 * convertible (la conversion en PNG a lieu à l'extraction, Result::copier_image()).
	 *
	 * @param string $chemin Chemin dans l'archive.
	 * @param string $alt    Texte alternatif.
	 * @return string|null Clé, ou null (raison dans $this->raisons_emf).
	 */
	private function metafichier( string $chemin, string $alt ): ?string {
		if ( ! $this->zip->existe( $chemin ) ) {
			$this->raisons_emf[ $chemin ] = 'introuvable dans le fichier';
			return null;
		}
		$infos = Metafichier::analyser( $this->zip, $chemin );
		if ( isset( $infos['erreur'] ) ) {
			$this->raisons_emf[ $chemin ] = (string) $infos['erreur'];
			return null;
		}
		++$this->emf_convertis;
		return self::enregistrer_image(
			$this->resultat,
			array(
				'nom'        => basename( $chemin ),
				'mime'       => (string) $infos['mime'],
				'chemin_zip' => (string) $this->zip->nom_reel( $chemin ),
				'largeur'    => (int) $infos['largeur'],
				'hauteur'    => (int) $infos['hauteur'],
				'octets'     => $this->zip->taille( $chemin ),
				'alt'        => $alt,
				'conversion' => 'metafichier',
			)
		);
	}

	/**
	 * Ajoute une image au Result sous une clé unique tirée de son nom.
	 *
	 * @param Result              $resultat Résultat.
	 * @param array<string,mixed> $image    Description (nom, mime, chemin_zip, largeur, hauteur, octets, alt…).
	 * @return string Clé.
	 */
	private static function enregistrer_image( Result $resultat, array $image ): string {
		$base = Texte::cle( (string) pathinfo( (string) $image['nom'], PATHINFO_FILENAME ) );
		$cle  = $base;
		for ( $i = 2; isset( $resultat->images[ $cle ] ); $i++ ) {
			$cle = $base . '-' . $i;
		}
		$image['alt']             = mb_substr( Texte::espaces( (string) $image['alt'] ), 0, 250, 'UTF-8' );
		$resultat->images[ $cle ] = $image;
		return $cle;
	}

	/**
	 * Vérifie une image (existence, taille, type réel) et l'ajoute au Result.
	 *
	 * @param Zip    $zip      Archive.
	 * @param Result $resultat Résultat.
	 * @param string $chemin   Chemin dans l'archive.
	 * @param string $alt      Texte alternatif.
	 * @return string|null Clé, ou null si l'image est refusée (avertissement ajouté).
	 */
	public static function analyser_image( Zip $zip, Result $resultat, string $chemin, string $alt ): ?string {
		$nom = basename( $chemin );
		if ( ! $zip->existe( $chemin ) ) {
			$resultat->avertir( sprintf( 'Image « %s » introuvable dans le fichier : ignorée.', $nom ) );
			return null;
		}
		$octets = $zip->taille( $chemin );
		if ( $octets > Zip::IMAGE_MAX ) {
			$resultat->avertir( sprintf( 'Image « %s » trop volumineuse : ignorée.', $nom ) );
			return null;
		}
		$ext = strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			$resultat->avertir( sprintf( 'Image vectorielle « %s » (SVG) ignorée : format non pris en charge (JPG, PNG, WebP ou GIF).', $nom ) );
			return null;
		}
		$infos = @getimagesize( $zip->uri( $chemin ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime  = is_array( $infos ) ? (string) ( $infos['mime'] ?? '' ) : '';
		if ( ! in_array( $mime, self::MIMES_IMAGES, true ) ) {
			$resultat->avertir(
				'' === $mime
					? sprintf( 'Image « %s » illisible : ignorée.', $nom )
					: sprintf( 'Image « %1$s » au format %2$s non pris en charge (JPG, PNG, WebP ou GIF) : ignorée.', $nom, $mime )
			);
			return null;
		}
		return self::enregistrer_image(
			$resultat,
			array(
				'nom'        => $nom,
				'mime'       => $mime,
				'chemin_zip' => (string) $zip->nom_reel( $chemin ),
				'largeur'    => (int) $infos[0],
				'hauteur'    => (int) $infos[1],
				'octets'     => $octets,
				'alt'        => $alt,
			)
		);
	}

	/**
	 * Position courante pour les messages (« Chapitre 11 »).
	 */
	private function position(): string {
		return $this->chapitres->nom_courant();
	}

	/**
	 * Rend une note et renvoie son appel.
	 *
	 * @param string $cle « footnote:ID » ou « endnote:ID ».
	 */
	private function note( string $cle ): string {
		$note = $this->notes[ $cle ] ?? null;
		if ( null === $note || ! $this->chapitres->en_chapitre() ) {
			return '';
		}
		$items = array();
		foreach ( Docx_Styles::enfants( $note, 'p' ) as $p ) {
			$ppr          = Docx_Styles::enfant( $p, 'pPr' );
			$props        = $this->styles->paragraphe( (string) Docx_Styles::attr( Docx_Styles::enfant( $ppr, 'pStyle' ) ) );
			$etat         = array(
				'items'      => array(),
				'car_total'  => 0,
				'car_pensee' => 0,
				'car_italic' => 0,
				'car_gras'   => 0,
				'ignorees'   => 0,
				'saut'       => '',
			);
			$champs       = $this->champs;
			$this->champs = array();
			$this->contenu( $p, $props['rpr'], $etat, '' );
			$this->champs = $champs;
			$morceau      = array_values( array_filter( $etat['items'], static fn( $i ) => 'image' !== $i['type'] && 'brut' !== $i['type'] ) );
			if ( '' === trim( Inline::texte_brut( $morceau ) ) ) {
				continue;
			}
			if ( $items ) {
				$items[] = Inline::saut();
			}
			$items = array_merge( $items, $morceau );
		}
		$html = Inline::html( $items );
		if ( $this->options['typographie'] ) {
			$html = Texte::typographie( $html );
		}
		return $this->chapitres->note( $html );
	}

	/**
	 * Statistiques finales et avertissements récapitulatifs.
	 *
	 * @param float $debut Horodatage de début.
	 */
	private function conclure( float $debut ): void {
		if ( $this->vectorielles ) {
			$details = array();
			foreach ( $this->vectorielles as $v ) {
				$details[] = $v[0] . ' (' . $v[1] . ( '' !== $v[2] ? ' : ' . $v[2] : '' ) . ')';
			}
			$n = count( $this->vectorielles );
			$this->resultat->avertir(
				sprintf(
					1 === $n
						? '%1$d image au format Word EMF/WMF ignorée (aucune image convertible) : %2$s. Dans Word, faites un clic droit sur l’image, « Enregistrer en tant qu’image… » au format PNG ou JPG, puis insérez ce fichier à la place pour la publier.'
						: '%1$d images au format Word EMF/WMF ignorées (aucune image convertible) : %2$s. Dans Word, faites un clic droit sur chaque image, « Enregistrer en tant qu’image… » au format PNG ou JPG, puis insérez ces fichiers à la place pour les publier.',
					$n,
					implode( ', ', $details )
				)
			);
		}
		if ( $this->compteurs['zones_de_texte'] > 0 ) {
			$this->resultat->avertir( sprintf( '%d zone(s) de texte Word ignorée(s) : placez ce texte dans un paragraphe normal pour le publier.', $this->compteurs['zones_de_texte'] ) );
		}
		if ( $this->compteurs['objets'] > 0 ) {
			$this->resultat->avertir( sprintf( '%d objet(s) incorporé(s) (formule, graphique…) ignoré(s).', $this->compteurs['objets'] ) );
		}
		$stats                          = &$this->resultat->stats;
		$stats['format']                = 'docx';
		$stats['fichier']               = basename( $this->chemin );
		$stats['octets']                = (int) filesize( $this->resultat->source );
		$stats['hash']                  = (string) sha1_file( $this->resultat->source );
		$stats['images_gardees']        = count( $this->resultat->images );
		$stats['images_ignorees']       = $this->compteurs['images_ignorees'];
		$stats['images_emf']            = count( $this->vectorielles );
		$stats['images_emf_converties'] = $this->emf_convertis;
		$stats['sauts_de_page']         = $this->compteurs['sauts_de_page'];
		$stats['sections']              = $this->compteurs['sections'];
		$stats['zones_de_texte']        = $this->compteurs['zones_de_texte'];
		$stats['duree_ms']              = (int) round( ( microtime( true ) - $debut ) * 1000 );
		$stats['memoire_max_mo']        = round( memory_get_peak_usage( true ) / 1048576, 1 );
	}
}
