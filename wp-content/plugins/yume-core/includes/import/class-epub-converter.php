<?php
/**
 * Conversion EPUB → chapitres (docs/04 §3, contrat §9). L'EPUB est accepté en dépannage :
 * le DOCX reste la source de référence.
 *
 * 1. META-INF/container.xml → OPF → manifest + spine.
 * 2. Pages liminaires exclues via le guide (EPUB 2) ou les landmarks (EPUB 3) : couverture,
 *    table des matières, page de titre, colophon, copyright ; document de navigation et NCX
 *    exclus ; fichiers de notes exclus (leurs notes sont rattachées aux appels).
 * 3. Découpe sur <h1> ; un fichier sans <h1> prolonge le chapitre en cours. Si le livre n'a
 *    aucun <h1>, chaque entrée de la table des matières ouvre un chapitre (titre du TOC).
 *    Sous-titre : <h2> (ou p.subtitle) juste après le titre.
 * 4. Nettoyage en liste blanche (p, h2, h3, em, strong, br, figure, img, blockquote, hr, sup,
 *    ul, ol, li), classes connues (dialogue, pensee, center…) et heuristiques : un paragraphe
 *    commençant par un tiret → dialogue, un paragraphe entièrement en italique → pensée.
 * 5. Images JPG/PNG/WebP/GIF conservées (jeton), avec la couverture en tête de la galerie.
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
 * Convertisseur EPUB.
 */
final class Epub_Converter {

	/** Espace de noms epub:type. */
	private const NS_EPUB = 'http://www.idpf.org/2007/ops';

	/** Espace de noms XLink (image SVG). */
	private const NS_XLINK = 'http://www.w3.org/1999/xlink';

	/** Types de pages liminaires exclues (guide EPUB 2, landmarks EPUB 3). */
	private const EXCLUS = array( 'cover', 'toc', 'title-page', 'titlepage', 'copyright-page', 'colophon', 'loi', 'lot', 'landmarks', 'index', 'halftitlepage', 'imprint' );

	/** Types de fichiers de notes. */
	private const NOTES = array( 'footnotes', 'endnotes', 'rearnotes', 'footnote', 'endnote', 'rearnote', 'note' );

	/** Éléments en ligne (fusionnés dans le paragraphe en cours). */
	private const EN_LIGNE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'img', 'image', 'ins', 'kbd', 'label', 'mark', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'svg', 'time', 'tt', 'u', 'var', 'wbr' );

	/** Éléments ignorés avec leur contenu. */
	private const IGNORES = array( 'script', 'style', 'head', 'title', 'noscript', 'template', 'form', 'input', 'button', 'select', 'textarea', 'audio', 'video', 'iframe', 'object', 'embed', 'math', 'nav', 'rt', 'rp', 'meta', 'link', 'canvas', 'map', 'area' );

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
	 * Manifeste : id => ['chemin', 'mime', 'proprietes'].
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $manifeste = array();

	/**
	 * Libellés de la table des matières : chemin => libellé.
	 *
	 * @var array<string,string>
	 */
	private array $libelles = array();

	/**
	 * Fichiers exclus (pages liminaires, navigation).
	 *
	 * @var array<string,bool>
	 */
	private array $exclus = array();

	/**
	 * Premier fichier du corps du livre (landmark bodymatter / guide text), si connu.
	 *
	 * @var string
	 */
	private string $debut_corps = '';

	/**
	 * Documents XHTML chargés (notes) : chemin => DOMDocument.
	 *
	 * @var array<string,\DOMDocument|null>
	 */
	private array $documents = array();

	/**
	 * Éléments de notes déjà repris : « chemin#id » => vrai.
	 *
	 * @var array<string,bool>
	 */
	private array $notes_reprises = array();

	/**
	 * Fichier en cours de traitement.
	 *
	 * @var string
	 */
	private string $fichier = '';

	/**
	 * Chemin du fichier OPF.
	 *
	 * @var string
	 */
	private string $opf = '';

	/**
	 * Le livre utilise-t-il des <h1> pour ses chapitres ?
	 *
	 * @var bool
	 */
	private bool $avec_h1 = false;

	/**
	 * Le fichier en cours fait-il partie des pages liminaires (avant le corps du livre) ?
	 *
	 * @var bool
	 */
	private bool $liminaire = false;

	/**
	 * Images ignorées (externes, SVG, formats non pris en charge).
	 *
	 * @var int
	 */
	private int $ignorees = 0;

	/**
	 * Dernier élément de bloc traité était-il un titre de chapitre (pour le sous-titre) ?
	 *
	 * @var bool
	 */
	private bool $apres_titre = false;

	/**
	 * Constructeur.
	 *
	 * @param string              $chemin  Fichier EPUB.
	 * @param array<string,mixed> $options Options (typographie).
	 */
	private function __construct( string $chemin, array $options ) {
		$this->chemin  = $chemin;
		$this->options = $options + array( 'typographie' => true );
	}

	/**
	 * Convertit un fichier EPUB (même Result que Docx_Converter::convert_file()).
	 *
	 * @param string              $path    Chemin du fichier.
	 * @param array<string,mixed> $options typographie (bool, défaut true) ; volume_max (int,
	 *                                     octets) : texte converti maximal (défaut
	 *                                     Chapter_Builder::VOLUME_MAX).
	 * @throws Import_Exception Fichier illisible ou qui n'est pas un EPUB.
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
		$this->zip      = Zip::ouvrir( $this->chemin, 'EPUB' );
		$precedent      = libxml_use_internal_errors( true );
		try {
			$this->resultat->source = $this->zip->chemin();
			$this->opf              = $this->localiser_opf();
			$spine                  = $this->lire_opf( $this->opf );
			$this->chapitres        = new Chapter_Builder( $this->resultat, (int) ( $this->options['volume_max'] ?? Chapter_Builder::VOLUME_MAX ) );
			$this->couverture();
			$this->avec_h1 = $this->utilise_h1( $spine );
			$corps_atteint = '' === $this->debut_corps;
			foreach ( $spine as $chemin ) {
				if ( $chemin === $this->debut_corps ) {
					$corps_atteint = true;
				}
				if ( isset( $this->exclus[ $chemin ] ) ) {
					continue;
				}
				$this->liminaire = ! $corps_atteint && ! $this->chapitres->en_chapitre();
				$this->fichier_spine( $chemin );
			}
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
	 * Trouve le fichier OPF (META-INF/container.xml).
	 *
	 * @throws Import_Exception Ce n'est pas un EPUB.
	 */
	private function localiser_opf(): string {
		$container = $this->charger_xml( 'META-INF/container.xml' );
		if ( null === $container ) {
			if ( $this->zip->existe( 'word/document.xml' ) ) {
				throw new Import_Exception( 'Ce fichier est un document Word : déposez-le avec l’extension .docx.', 'mauvais_format' );
			}
			throw new Import_Exception( 'Ce fichier n’est pas un EPUB valide : META-INF/container.xml est absent.', 'epub_invalide' );
		}
		foreach ( $container->getElementsByTagNameNS( '*', 'rootfile' ) as $rootfile ) {
			if ( ! $rootfile instanceof \DOMElement ) {
				continue;
			}
			$type   = $rootfile->getAttribute( 'media-type' );
			$chemin = Zip::normaliser( $rootfile->getAttribute( 'full-path' ) );
			if ( '' !== $chemin && ( '' === $type || 'application/oebps-package+xml' === $type ) && $this->zip->existe( $chemin ) ) {
				return (string) $this->zip->nom_reel( $chemin );
			}
		}
		throw new Import_Exception( 'Ce fichier n’est pas un EPUB valide : le fichier de description du livre (OPF) est introuvable.', 'epub_invalide' );
	}

	/**
	 * Charge une partie XML (sans DTD ni réseau).
	 *
	 * @param string $entree Chemin dans l'archive.
	 * @throws Import_Exception Partie invalide.
	 */
	private function charger_xml( string $entree ): ?\DOMDocument {
		$xml = $this->zip->lire( $entree, 16 * 1024 * 1024 );
		if ( null === $xml ) {
			return null;
		}
		$xml = self::sans_doctype( $xml );
		$doc = new \DOMDocument();
		if ( ! $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT ) ) {
			throw new Import_Exception( sprintf( 'EPUB endommagé : la partie « %s » est illisible.', $entree ), 'epub_endommage' );
		}
		if ( self::dtd_suspecte( $doc ) ) {
			throw new Import_Exception( sprintf( 'EPUB refusé : la partie « %s » déclare des entités (DTD).', $entree ), 'epub_suspect' );
		}
		return $doc;
	}

	/**
	 * DTD restée après sans_doctype() (déclaration que l'expression n'a pas su retirer) portant
	 * un sous-ensemble interne ou des entités : jamais légitime dans un EPUB.
	 *
	 * @param \DOMDocument $doc Document chargé.
	 */
	private static function dtd_suspecte( \DOMDocument $doc ): bool {
		$dtd = $doc->doctype;
		return null !== $dtd && ( '' !== trim( (string) $dtd->internalSubset ) || $dtd->entities->length > 0 || '' !== (string) $dtd->systemId );
	}

	/**
	 * Retire la déclaration de type (DTD) et remplace les entités HTML nommées par leur
	 * caractère : le XML reste lisible sans jamais charger de DTD.
	 *
	 * @param string $xml XML ou XHTML.
	 */
	private static function sans_doctype( string $xml ): string {
		$xml = (string) preg_replace( '/<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>/is', '', $xml, 1 );
		return (string) preg_replace_callback(
			'/&([A-Za-z][A-Za-z0-9]{1,31});/',
			static function ( array $m ): string {
				if ( in_array( $m[1], array( 'amp', 'lt', 'gt', 'quot', 'apos' ), true ) ) {
					return $m[0];
				}
				$car = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return $car !== $m[0] ? htmlspecialchars( $car, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) : '';
			},
			$xml
		);
	}

	/**
	 * Lit l'OPF : manifeste, spine, guide, navigation.
	 *
	 * @param string $opf Chemin de l'OPF.
	 * @return string[] Chemins des documents de la spine (entrées linéaires).
	 * @throws Import_Exception OPF invalide.
	 */
	private function lire_opf( string $opf ): array {
		$doc = $this->charger_xml( $opf );
		if ( null === $doc ) {
			throw new Import_Exception( 'EPUB endommagé : fichier OPF illisible.', 'epub_endommage' );
		}
		foreach ( $doc->getElementsByTagNameNS( '*', 'item' ) as $item ) {
			if ( ! $item instanceof \DOMElement ) {
				continue;
			}
			$this->manifeste[ $item->getAttribute( 'id' ) ] = array(
				'chemin'     => Zip::resoudre( $opf, $item->getAttribute( 'href' ) ),
				'mime'       => strtolower( $item->getAttribute( 'media-type' ) ),
				'proprietes' => ' ' . strtolower( $item->getAttribute( 'properties' ) ) . ' ',
			);
		}
		$ncx   = '';
		$spine = array();
		foreach ( $doc->getElementsByTagNameNS( '*', 'spine' ) as $noeud ) {
			if ( ! $noeud instanceof \DOMElement ) {
				continue;
			}
			$ncx = $noeud->getAttribute( 'toc' );
			foreach ( $noeud->getElementsByTagNameNS( '*', 'itemref' ) as $ref ) {
				if ( ! $ref instanceof \DOMElement ) {
					continue;
				}
				$item = $this->manifeste[ $ref->getAttribute( 'idref' ) ] ?? null;
				if ( null === $item || 'no' === strtolower( $ref->getAttribute( 'linear' ) ) ) {
					continue;
				}
				if ( ! in_array( $item['mime'], array( 'application/xhtml+xml', 'text/html', 'application/xml', 'text/xml' ), true ) ) {
					continue;
				}
				$spine[] = $item['chemin'];
			}
			break;
		}
		if ( ! $spine ) {
			throw new Import_Exception( 'EPUB vide : aucun contenu de lecture (spine) n’a été trouvé.', 'epub_vide' );
		}

		// Guide (EPUB 2).
		foreach ( $doc->getElementsByTagNameNS( '*', 'reference' ) as $ref ) {
			if ( ! $ref instanceof \DOMElement ) {
				continue;
			}
			$type   = strtolower( $ref->getAttribute( 'type' ) );
			$chemin = Zip::resoudre( $opf, $ref->getAttribute( 'href' ) );
			if ( in_array( $type, self::EXCLUS, true ) ) {
				$this->exclus[ $chemin ] = true;
			} elseif ( in_array( $type, array( 'text', 'start', 'bodymatter' ), true ) && '' === $this->debut_corps ) {
				$this->debut_corps = $chemin;
			}
		}

		// Document de navigation (EPUB 3) et NCX (EPUB 2).
		foreach ( $this->manifeste as $id => $item ) {
			if ( str_contains( $item['proprietes'], ' nav ' ) ) {
				$this->exclus[ $item['chemin'] ] = true;
				$this->lire_nav( $item['chemin'] );
			}
			if ( 'application/x-dtbncx+xml' === $item['mime'] || ( '' !== $ncx && $id === $ncx ) ) {
				$this->exclus[ $item['chemin'] ] = true;
				$this->lire_ncx( $item['chemin'] );
			}
		}
		return $spine;
	}

	/**
	 * Lit le document de navigation EPUB 3 (table des matières et landmarks).
	 *
	 * @param string $chemin Chemin.
	 */
	private function lire_nav( string $chemin ): void {
		$doc = $this->document( $chemin );
		if ( null === $doc ) {
			return;
		}
		foreach ( $doc->getElementsByTagName( 'nav' ) as $nav ) {
			if ( ! $nav instanceof \DOMElement ) {
				continue;
			}
			$type = ' ' . strtolower( $nav->getAttributeNS( self::NS_EPUB, 'type' ) . ' ' . $nav->getAttribute( 'role' ) ) . ' ';
			foreach ( $nav->getElementsByTagName( 'a' ) as $a ) {
				if ( ! $a instanceof \DOMElement ) {
					continue;
				}
				$cible = Zip::resoudre( $chemin, $a->getAttribute( 'href' ) );
				if ( str_contains( $type, ' toc ' ) || str_contains( $type, 'doc-toc' ) ) {
					if ( ! isset( $this->libelles[ $cible ] ) ) {
						$this->libelles[ $cible ] = Texte::espaces( $a->textContent );
					}
				} elseif ( str_contains( $type, ' landmarks ' ) ) {
					$repere = strtolower( $a->getAttributeNS( self::NS_EPUB, 'type' ) );
					if ( in_array( $repere, self::EXCLUS, true ) ) {
						$this->exclus[ $cible ] = true;
					} elseif ( 'bodymatter' === $repere ) {
						$this->debut_corps = $cible;
					}
				}
			}
		}
	}

	/**
	 * Lit la table des matières NCX (EPUB 2).
	 *
	 * @param string $chemin Chemin.
	 */
	private function lire_ncx( string $chemin ): void {
		$doc = $this->charger_xml( $chemin );
		if ( null === $doc ) {
			return;
		}
		foreach ( $doc->getElementsByTagNameNS( '*', 'navPoint' ) as $point ) {
			if ( ! $point instanceof \DOMElement ) {
				continue;
			}
			$contenu = null;
			$libelle = '';
			foreach ( $point->childNodes as $enfant ) {
				if ( $enfant instanceof \DOMElement && 'content' === $enfant->localName ) {
					$contenu = $enfant;
				}
				if ( $enfant instanceof \DOMElement && 'navLabel' === $enfant->localName ) {
					$libelle = Texte::espaces( $enfant->textContent );
				}
			}
			if ( $contenu ) {
				$cible = Zip::resoudre( $chemin, $contenu->getAttribute( 'src' ) );
				if ( ! isset( $this->libelles[ $cible ] ) ) {
					$this->libelles[ $cible ] = $libelle;
				}
			}
		}
	}

	/**
	 * Image de couverture déclarée dans l'OPF : ajoutée en tête de la galerie du tome.
	 */
	private function couverture(): void {
		$chemin = '';
		foreach ( $this->manifeste as $item ) {
			if ( str_contains( $item['proprietes'], ' cover-image ' ) ) {
				$chemin = $item['chemin'];
				break;
			}
		}
		if ( '' === $chemin ) {
			$doc = $this->charger_xml( $this->opf );
			if ( $doc ) {
				foreach ( $doc->getElementsByTagNameNS( '*', 'meta' ) as $meta ) {
					if ( $meta instanceof \DOMElement && 'cover' === strtolower( $meta->getAttribute( 'name' ) ) ) {
						$chemin = $this->manifeste[ $meta->getAttribute( 'content' ) ]['chemin'] ?? '';
					}
				}
			}
		}
		if ( '' === $chemin ) {
			return;
		}
		$cle = Docx_Converter::analyser_image( $this->zip, $this->resultat, $chemin, 'Couverture' );
		if ( null !== $cle ) {
			$this->resultat->stats['couverture'] = $cle;
			$this->chapitres->image( $cle );
		}
	}

	/**
	 * Le livre découpe-t-il ses chapitres avec des <h1> ?
	 *
	 * @param string[] $spine Documents.
	 */
	private function utilise_h1( array $spine ): bool {
		foreach ( $spine as $chemin ) {
			if ( isset( $this->exclus[ $chemin ] ) ) {
				continue;
			}
			$doc = $this->document( $chemin );
			if ( null === $doc ) {
				continue;
			}
			foreach ( $doc->getElementsByTagName( 'h1' ) as $h1 ) {
				if ( '' !== Texte::espaces( $h1->textContent ) ) {
					$this->documents = array();
					return true;
				}
			}
		}
		$this->documents = array();
		return false;
	}

	/**
	 * Charge un document XHTML (mis en cache pour les notes).
	 *
	 * @param string $chemin Chemin dans l'archive.
	 */
	private function document( string $chemin ): ?\DOMDocument {
		if ( array_key_exists( $chemin, $this->documents ) ) {
			return $this->documents[ $chemin ];
		}
		$doc = null;
		$xml = $this->zip->lire( $chemin, 32 * 1024 * 1024 );
		if ( null !== $xml ) {
			$propre = self::sans_doctype( $xml );
			$doc    = new \DOMDocument();
			if ( ! $doc->loadXML( $propre, LIBXML_NONET | LIBXML_COMPACT ) ) {
				// XHTML mal formé : lecture tolérante en HTML.
				$doc = new \DOMDocument();
				if ( ! $doc->loadHTML( '<?xml encoding="UTF-8">' . $propre, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
					$doc = null;
				}
			} elseif ( self::dtd_suspecte( $doc ) ) {
				$doc = null;
			}
		}
		if ( count( $this->documents ) > 20 ) {
			$this->documents = array_slice( $this->documents, -10, null, true );
		}
		$this->documents[ $chemin ] = $doc;
		return $doc;
	}

	/**
	 * Premier élément <body> d'un document.
	 *
	 * @param \DOMDocument $doc Document.
	 */
	private static function corps( \DOMDocument $doc ): ?\DOMElement {
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		return $body instanceof \DOMElement ? $body : $doc->documentElement;
	}

	/**
	 * Type EPUB (epub:type et rôle ARIA) d'un élément, en minuscules, encadré d'espaces.
	 *
	 * @param \DOMElement $el Élément.
	 */
	private static function type_epub( \DOMElement $el ): string {
		return ' ' . strtolower( trim( $el->getAttributeNS( self::NS_EPUB, 'type' ) . ' ' . $el->getAttribute( 'epub:type' ) . ' ' . str_replace( 'doc-', '', $el->getAttribute( 'role' ) ) ) ) . ' ';
	}

	/**
	 * Le type epub contient-il l'un des mots donnés ?
	 *
	 * @param \DOMElement $el   Élément.
	 * @param string[]    $mots Mots.
	 */
	private static function a_type( \DOMElement $el, array $mots ): bool {
		$type = self::type_epub( $el );
		foreach ( $mots as $mot ) {
			if ( str_contains( $type, ' ' . $mot . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Traite un document de la spine.
	 *
	 * @param string $chemin Chemin.
	 */
	private function fichier_spine( string $chemin ): void {
		$doc = $this->document( $chemin );
		if ( null === $doc ) {
			$this->resultat->avertir( sprintf( 'Page « %s » illisible dans l’EPUB : ignorée.', basename( $chemin ) ) );
			return;
		}
		$corps = self::corps( $doc );
		if ( null === $corps ) {
			return;
		}
		// Fichier de notes : ses notes sont rattachées à leurs appels.
		if ( self::a_type( $corps, self::NOTES ) ) {
			return;
		}
		foreach ( $corps->childNodes as $enfant ) {
			if ( $enfant instanceof \DOMElement && in_array( strtolower( $enfant->localName ), array( 'section', 'div', 'aside' ), true ) && self::a_type( $enfant, self::NOTES ) && '' === Texte::espaces( self::texte_hors( $corps, $enfant ) ) ) {
				return;
			}
		}
		$this->fichier = $chemin;

		// Livre sans <h1> : chaque entrée de la table des matières ouvre un chapitre.
		if ( ! $this->avec_h1 && ! $this->liminaire ) {
			$libelle = $this->libelles[ $chemin ] ?? '';
			$texte   = Texte::espaces( $corps->textContent );
			if ( '' !== $texte && ( '' !== $libelle || ! $this->chapitres->en_chapitre() ) ) {
				$titre   = '' !== $libelle ? $libelle : Texte::espaces( (string) ( $doc->getElementsByTagName( 'title' )->item( 0 )->textContent ?? '' ) );
				$analyse = Texte::analyser_titre( $titre );
				if ( 'inconnu' !== $analyse['motif'] || $this->chapitres->en_chapitre() || Texte::compter_mots( $texte ) >= Chapter_Builder::MOTS_LIMINAIRE ) {
					$this->chapitres->ouvrir( $analyse, $titre );
					$this->apres_titre = true;
				}
			}
		}
		$this->blocs( $corps, array() );
	}

	/**
	 * Texte d'un élément sans celui d'un de ses descendants.
	 *
	 * @param \DOMElement $el     Élément.
	 * @param \DOMElement $retire Descendant à exclure.
	 */
	private static function texte_hors( \DOMElement $el, \DOMElement $retire ): string {
		$texte = '';
		foreach ( $el->childNodes as $enfant ) {
			if ( $enfant === $retire ) {
				continue;
			}
			$texte .= $enfant->textContent;
		}
		return $texte;
	}

	/**
	 * Classes d'un élément (minuscules).
	 *
	 * @param \DOMElement $el Élément.
	 */
	private static function classes( \DOMElement $el ): string {
		return ' ' . strtolower( (string) preg_replace( '/\s+/', ' ', $el->getAttribute( 'class' ) ) ) . ' ';
	}

	/**
	 * Parcourt les enfants d'un élément de niveau bloc.
	 *
	 * @param \DOMElement $conteneur Élément.
	 * @param string[]    $herite    Classes héritées des conteneurs.
	 */
	private function blocs( \DOMElement $conteneur, array $herite ): void {
		$tampon = array();
		foreach ( $conteneur->childNodes as $noeud ) {
			if ( $noeud instanceof \DOMText ) {
				if ( '' !== trim( $noeud->data ) ) {
					$tampon[] = $noeud;
				}
				continue;
			}
			if ( ! $noeud instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $noeud->localName );
			if ( in_array( $tag, self::EN_LIGNE, true ) && ! self::contient_bloc( $noeud ) ) {
				$tampon[] = $noeud;
				continue;
			}
			$this->paragraphe_tampon( $tampon, $conteneur, $herite );
			$tampon = array();
			$this->bloc( $noeud, $herite );
		}
		$this->paragraphe_tampon( $tampon, $conteneur, $herite );
	}

	/**
	 * Un élément en ligne contient-il des éléments de bloc (a > div, span > p…) ?
	 *
	 * @param \DOMElement $el Élément.
	 */
	private static function contient_bloc( \DOMElement $el ): bool {
		foreach ( array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'section', 'table', 'figure', 'hr' ) as $bloc ) {
			if ( $el->getElementsByTagName( $bloc )->length > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Émet comme paragraphe du texte en ligne « flottant » dans un conteneur.
	 *
	 * @param array<int,\DOMNode> $noeuds    Nœuds en ligne.
	 * @param \DOMElement         $conteneur Conteneur.
	 * @param string[]            $herite    Classes héritées.
	 */
	private function paragraphe_tampon( array $noeuds, \DOMElement $conteneur, array $herite ): void {
		if ( ! $noeuds ) {
			return;
		}
		$items = array();
		foreach ( $noeuds as $noeud ) {
			$this->en_ligne( $noeud, array(), $items );
		}
		$this->emettre( $items, $conteneur, $herite );
	}

	/**
	 * Élément de niveau bloc.
	 *
	 * @param \DOMElement $el     Élément.
	 * @param string[]    $herite Classes héritées.
	 */
	private function bloc( \DOMElement $el, array $herite ): void {
		$tag = strtolower( $el->localName );
		if ( in_array( $tag, self::IGNORES, true ) ) {
			return;
		}
		if ( $this->note_reprise( $el ) ) {
			return;
		}
		switch ( $tag ) {
			case 'h1':
				$this->titre_h1( $el );
				return;
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$texte = Texte::espaces( $el->textContent );
				if ( '' === $texte ) {
					return;
				}
				if ( 'h2' === $tag && $this->apres_titre && $this->chapitres->attend_sous_titre() ) {
					$this->chapitres->sous_titre( $texte );
					return;
				}
				if ( ! $this->avec_h1 && ! $this->chapitres->en_chapitre() ) {
					$analyse = Texte::analyser_titre( $texte );
					if ( 'inconnu' !== $analyse['motif'] ) {
						$this->chapitres->ouvrir( $analyse, $texte );
						$this->apres_titre = true;
						return;
					}
				}
				$this->apres_titre = false;
				$this->chapitres->titre( Blocks::texte( $texte ), 'h2' === $tag ? 2 : ( 'h3' === $tag ? 3 : 4 ) );
				return;
			case 'hr':
				$this->apres_titre = false;
				$this->chapitres->separateur();
				return;
			case 'img':
			case 'svg':
			case 'image':
				$items = array();
				$this->en_ligne( $el, array(), $items );
				$this->emettre( $items, $el, $herite );
				return;
			case 'ul':
			case 'ol':
				$this->apres_titre = false;
				foreach ( $el->childNodes as $li ) {
					if ( $li instanceof \DOMElement && 'li' === strtolower( $li->localName ) ) {
						$items = array();
						foreach ( $li->childNodes as $enfant ) {
							$this->en_ligne( $enfant, array(), $items );
						}
						$html = $this->html( $items, false );
						if ( '' !== $html ) {
							$this->chapitres->element_liste( $html, 'ol' === $tag );
						}
					}
				}
				return;
			case 'blockquote':
				$this->apres_titre = false;
				$paragraphes       = array();
				$ps                = $el->getElementsByTagName( 'p' );
				if ( 0 === $ps->length ) {
					$items = array();
					foreach ( $el->childNodes as $enfant ) {
						$this->en_ligne( $enfant, array(), $items );
					}
					$paragraphes[] = $this->html( $items, false );
				} else {
					foreach ( $ps as $p ) {
						$items = array();
						foreach ( $p->childNodes as $enfant ) {
							$this->en_ligne( $enfant, array(), $items );
						}
						$paragraphes[] = $this->html( $items, false );
					}
				}
				$this->chapitres->citation( $paragraphes );
				return;
			case 'aside':
				if ( self::a_type( $el, self::NOTES ) ) {
					return;
				}
				$this->blocs( $el, array_merge( $herite, array( self::classes( $el ) ) ) );
				return;
			case 'table':
				foreach ( $el->getElementsByTagName( 'tr' ) as $tr ) {
					$cellules = array();
					foreach ( $tr->childNodes as $td ) {
						if ( $td instanceof \DOMElement && in_array( strtolower( $td->localName ), array( 'td', 'th' ), true ) ) {
							$items = array();
							foreach ( $td->childNodes as $enfant ) {
								$this->en_ligne( $enfant, array(), $items );
							}
							$html = $this->html( $items, false );
							if ( '' !== $html ) {
								$cellules[] = $html;
							}
						}
					}
					if ( $cellules ) {
						$this->chapitres->paragraphe( implode( ' — ', $cellules ) );
					}
				}
				return;
			case 'p':
			case 'pre':
			case 'dt':
			case 'dd':
			case 'figcaption':
			case 'caption':
			case 'address':
				if ( self::contient_bloc( $el ) ) {
					$this->blocs( $el, array_merge( $herite, array( self::classes( $el ) ) ) );
					return;
				}
				$items = array();
				foreach ( $el->childNodes as $enfant ) {
					$this->en_ligne( $enfant, array(), $items, 'pre' === $tag );
				}
				$this->emettre( $items, $el, $herite, 'figcaption' === $tag || 'caption' === $tag );
				return;
			default:
				// Conteneurs (div, section, article, figure, header, main, li…).
				$this->blocs( $el, array_merge( $herite, array( self::classes( $el ) ) ) );
		}
	}

	/**
	 * Titre <h1> : nouveau chapitre (ou titre de page liminaire ignoré).
	 *
	 * @param \DOMElement $h1 Élément.
	 */
	private function titre_h1( \DOMElement $h1 ): void {
		$lignes = array();
		$ligne  = '';
		foreach ( $h1->childNodes as $enfant ) {
			if ( $enfant instanceof \DOMElement && 'br' === strtolower( $enfant->localName ) ) {
				$lignes[] = $ligne;
				$ligne    = '';
				continue;
			}
			if ( $enfant instanceof \DOMElement && preg_match( '/\b(subtitle|sous-?titre)\b/', self::classes( $enfant ) ) ) {
				$lignes[] = $ligne;
				$ligne    = $enfant->textContent;
				continue;
			}
			$ligne .= $enfant->textContent;
		}
		$lignes[] = $ligne;
		$lignes   = array_values( array_filter( array_map( array( Texte::class, 'espaces' ), $lignes ), static fn( $l ) => '' !== $l ) );
		if ( ! $lignes ) {
			return;
		}
		if ( $this->liminaire ) {
			$this->chapitres->paragraphe( Blocks::texte( implode( ' ', $lignes ) ) );
			return;
		}
		$analyse = Texte::analyser_titre( $lignes[0] );
		if ( 'inconnu' === $analyse['motif'] && $this->apres_titre && $this->chapitres->attend_sous_titre() && $this->chapitres->titre_reconnu() ) {
			$this->chapitres->sous_titre( implode( ' ', $lignes ) );
			return;
		}
		$this->chapitres->ouvrir( $analyse, $lignes[0] );
		if ( count( $lignes ) > 1 && '' === $analyse['sous_titre'] ) {
			$this->chapitres->sous_titre( implode( ' ', array_slice( $lignes, 1 ) ) );
		}
		$this->apres_titre = true;
	}

	/**
	 * Convertit un nœud en ligne en segments.
	 *
	 * @param \DOMNode                       $noeud  Nœud.
	 * @param array<string,mixed>            $f      Mise en forme héritée.
	 * @param array<int,array<string,mixed>> $items  Segments (modifié).
	 * @param bool                           $pre    Texte préformaté (retours conservés).
	 */
	private function en_ligne( \DOMNode $noeud, array $f, array &$items, bool $pre = false ): void {
		if ( $noeud instanceof \DOMText ) {
			$texte = $noeud->data;
			if ( $pre && str_contains( $texte, "\n" ) ) {
				foreach ( explode( "\n", $texte ) as $i => $morceau ) {
					if ( $i > 0 ) {
						$items[] = Inline::saut();
					}
					$items[] = Inline::texte( $morceau, $f );
				}
				return;
			}
			$items[] = Inline::texte( $texte, $f );
			return;
		}
		if ( ! $noeud instanceof \DOMElement ) {
			return;
		}
		$tag = strtolower( $noeud->localName );
		if ( in_array( $tag, self::IGNORES, true ) ) {
			return;
		}
		$classes = self::classes( $noeud );
		$style   = strtolower( $noeud->getAttribute( 'style' ) );
		switch ( $tag ) {
			case 'br':
				$items[] = Inline::saut();
				return;
			case 'img':
			case 'image':
				$this->image_en_ligne( $noeud, $items );
				return;
			case 'svg':
				foreach ( $noeud->getElementsByTagNameNS( '*', 'image' ) as $image ) {
					if ( $image instanceof \DOMElement ) {
						$this->image_en_ligne( $image, $items );
					}
				}
				return;
			case 'a':
				if ( $this->est_appel_note( $noeud ) ) {
					$appel = $this->note( $noeud );
					if ( '' !== $appel ) {
						$items[] = Inline::brut( $appel );
					}
					return;
				}
				break;
			case 'sup':
				foreach ( $noeud->getElementsByTagName( 'a' ) as $a ) {
					if ( $a instanceof \DOMElement && $this->est_appel_note( $a ) ) {
						$appel = $this->note( $a );
						if ( '' !== $appel ) {
							$items[] = Inline::brut( $appel );
						}
						return;
					}
				}
				$f['va'] = 'sup';
				break;
			case 'sub':
				$f['va'] = 'sub';
				break;
			case 'em':
			case 'i':
			case 'cite':
			case 'dfn':
			case 'var':
				$f['i'] = true;
				break;
			case 'strong':
			case 'b':
				$f['b'] = true;
				break;
			case 'u':
			case 'ins':
				$f['u'] = true;
				break;
			case 'ruby':
				foreach ( $noeud->childNodes as $enfant ) {
					if ( $enfant instanceof \DOMText || ( $enfant instanceof \DOMElement && ! in_array( strtolower( $enfant->localName ), array( 'rt', 'rp' ), true ) ) ) {
						$this->en_ligne( $enfant, $f, $items, $pre );
					}
				}
				return;
		}
		// Mise en forme portée par une classe ou un style en ligne (span, p…).
		if ( preg_match( '/\b(italic|italique|ital|emph|em)\b/', $classes ) || str_contains( $style, 'font-style: italic' ) || str_contains( $style, 'font-style:italic' ) ) {
			$f['i'] = true;
		}
		if ( preg_match( '/\b(bold|gras|strong)\b/', $classes ) || preg_match( '/font-weight:\s*(bold|[6-9]00)/', $style ) ) {
			$f['b'] = true;
		}
		if ( preg_match( '/\b(underline|souligne)\b/', $classes ) || preg_match( '/text-decoration:[^;]*underline/', $style ) ) {
			$f['u'] = true;
		}
		if ( preg_match( '/\b(sup|superscript|exposant)\b/', $classes ) || str_contains( $style, 'vertical-align: super' ) || str_contains( $style, 'vertical-align:super' ) ) {
			$f['va'] = 'sup';
		}
		foreach ( $noeud->childNodes as $enfant ) {
			$this->en_ligne( $enfant, $f, $items, $pre || 'pre' === $tag );
		}
	}

	/**
	 * Image (img, image SVG) : ajoutée comme segment « image ».
	 *
	 * @param \DOMElement                    $el    Élément.
	 * @param array<int,array<string,mixed>> $items Segments (modifié).
	 */
	private function image_en_ligne( \DOMElement $el, array &$items ): void {
		$src = $el->getAttribute( 'src' );
		if ( '' === $src ) {
			$src = $el->getAttributeNS( self::NS_XLINK, 'href' );
		}
		if ( '' === $src ) {
			$src = $el->getAttribute( 'href' );
		}
		if ( '' === $src || preg_match( '#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $src ) ) {
			if ( '' !== $src ) {
				$this->resultat->avertir( 'Une image externe (lien hors du fichier EPUB) a été ignorée.' );
				++$this->ignorees;
			}
			return;
		}
		$chemin = Zip::resoudre( $this->fichier, $src );
		$cle    = null;
		foreach ( $this->resultat->images as $existante => $image ) {
			if ( $image['chemin_zip'] === $chemin ) {
				$cle = $existante;
				break;
			}
		}
		if ( null === $cle ) {
			$alt = trim( $el->getAttribute( 'alt' ) );
			$cle = Docx_Converter::analyser_image( $this->zip, $this->resultat, $chemin, $alt );
			if ( null === $cle ) {
				++$this->ignorees;
			}
		}
		if ( null !== $cle ) {
			$items[] = array(
				'type' => 'image',
				'cle'  => $cle,
			);
		}
	}

	/**
	 * Le lien est-il un appel de note ?
	 *
	 * @param \DOMElement $a Lien.
	 */
	private function est_appel_note( \DOMElement $a ): bool {
		if ( self::a_type( $a, array( 'noteref' ) ) || preg_match( '/\b(noteref|note-?ref|footnote-?ref|fnref|footnote-?link|renvoi)\b/', self::classes( $a ) ) ) {
			return true;
		}
		$cible = $this->cible_note( $a );
		return null !== $cible && ( self::a_type( $cible, self::NOTES ) || preg_match( '/\b(footnotes?|endnotes?|notes?|fn)\b/', self::classes( $cible ) ) || $this->dans_notes( $cible ) );
	}

	/**
	 * L'élément est-il dans un conteneur de notes ?
	 *
	 * @param \DOMElement $el Élément.
	 */
	private function dans_notes( \DOMElement $el ): bool {
		for ( $n = $el->parentNode; $n instanceof \DOMElement; $n = $n->parentNode ) {
			if ( self::a_type( $n, self::NOTES ) || preg_match( '/\b(footnotes|endnotes|notes)\b/', self::classes( $n ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Élément ciblé par un lien interne (#id, fichier.xhtml#id), ou null.
	 *
	 * @param \DOMElement $a Lien.
	 */
	private function cible_note( \DOMElement $a ): ?\DOMElement {
		$href = $a->getAttribute( 'href' );
		$pos  = strpos( $href, '#' );
		if ( false === $pos || preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			return null;
		}
		$id      = rawurldecode( substr( $href, $pos + 1 ) );
		$fichier = 0 === $pos ? $this->fichier : Zip::resoudre( $this->fichier, substr( $href, 0, $pos ) );
		$doc     = $fichier === $this->fichier ? $a->ownerDocument : $this->document( $fichier );
		if ( null === $doc || '' === $id ) {
			return null;
		}
		$xpath  = new \DOMXPath( $doc );
		$trouve = $xpath->query( '//*[@id=' . self::litteral_xpath( $id ) . ']' );
		$cible  = $trouve ? $trouve->item( 0 ) : null;
		return $cible instanceof \DOMElement ? $cible : null;
	}

	/**
	 * Littéral XPath sûr pour une chaîne quelconque.
	 *
	 * @param string $texte Texte.
	 */
	private static function litteral_xpath( string $texte ): string {
		if ( ! str_contains( $texte, "'" ) ) {
			return "'" . $texte . "'";
		}
		if ( ! str_contains( $texte, '"' ) ) {
			return '"' . $texte . '"';
		}
		return "concat('" . str_replace( "'", "', \"'\", '", $texte ) . "')";
	}

	/**
	 * Rend la note ciblée par un appel et renvoie l'appel numéroté du chapitre.
	 *
	 * @param \DOMElement $a Lien d'appel.
	 */
	private function note( \DOMElement $a ): string {
		$cible = $this->cible_note( $a );
		if ( null === $cible ) {
			return '';
		}
		// La note est le bloc qui contient la cible (aside, li, p, div).
		$bloc = $cible;
		while ( $bloc instanceof \DOMElement && in_array( strtolower( $bloc->localName ), array( 'a', 'span', 'sup', 'b', 'strong', 'em', 'i' ), true ) && $bloc->parentNode instanceof \DOMElement ) {
			$bloc = $bloc->parentNode;
		}
		$fichier = $this->fichier_de( $cible );
		$this->notes_reprises[ $fichier . '#' . $cible->getAttribute( 'id' ) ] = true;
		if ( $bloc !== $cible && $bloc->hasAttribute( 'id' ) ) {
			$this->notes_reprises[ $fichier . '#' . $bloc->getAttribute( 'id' ) ] = true;
		}
		$items = array();
		foreach ( $bloc->childNodes as $enfant ) {
			if ( $enfant instanceof \DOMElement && 'a' === strtolower( $enfant->localName ) && ( $enfant === $cible || self::a_type( $enfant, array( 'backlink', 'referrer' ) ) || preg_match( '/^[\s\[\]\(\)\d*.↩↑^]*$/u', $enfant->textContent ) ) ) {
				continue; // Lien de retour ou numéro de la note.
			}
			if ( $enfant instanceof \DOMElement && in_array( strtolower( $enfant->localName ), array( 'p', 'div' ), true ) ) {
				if ( $items ) {
					$items[] = Inline::saut();
				}
				foreach ( $enfant->childNodes as $petit ) {
					if ( $petit instanceof \DOMElement && 'a' === strtolower( $petit->localName ) && ( self::a_type( $petit, array( 'backlink', 'referrer' ) ) || preg_match( '/^[\s\[\]\(\)\d*.↩↑^]*$/u', $petit->textContent ) ) ) {
						continue;
					}
					$this->en_ligne( $petit, array(), $items );
				}
				continue;
			}
			if ( $enfant instanceof \DOMElement && in_array( strtolower( $enfant->localName ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				continue;
			}
			$this->en_ligne( $enfant, array(), $items );
		}
		$items = array_values( array_filter( $items, static fn( $i ) => 'image' !== $i['type'] && 'brut' !== $i['type'] ) );
		// Numéro en tête du texte (« 1. », « [1] ») retiré.
		foreach ( $items as $k => $item ) {
			if ( 'texte' !== $item['type'] ) {
				continue;
			}
			if ( '' === trim( $item['texte'] ) ) {
				continue;
			}
			$items[ $k ]['texte'] = (string) preg_replace( '/^\s*(?:\[\d+\]|\(\d+\)|\d+[.)])\s*/u', '', $item['texte'] );
			break;
		}
		return $this->chapitres->note( $this->html( $items, false ) );
	}

	/**
	 * Chemin du fichier (dans l'archive) auquel appartient un élément.
	 *
	 * @param \DOMElement $el Élément.
	 */
	private function fichier_de( \DOMElement $el ): string {
		foreach ( $this->documents as $chemin => $doc ) {
			if ( $doc === $el->ownerDocument ) {
				return $chemin;
			}
		}
		return $this->fichier;
	}

	/**
	 * L'élément est-il une note déjà reprise dans un chapitre (à ne pas répéter) ?
	 *
	 * @param \DOMElement $el Élément.
	 */
	private function note_reprise( \DOMElement $el ): bool {
		$id = $el->getAttribute( 'id' );
		if ( '' === $id ) {
			foreach ( $el->childNodes as $enfant ) {
				if ( $enfant instanceof \DOMElement && '' !== $enfant->getAttribute( 'id' ) && isset( $this->notes_reprises[ $this->fichier . '#' . $enfant->getAttribute( 'id' ) ] ) && 'a' === strtolower( $enfant->localName ) ) {
					return true;
				}
			}
			return false;
		}
		return isset( $this->notes_reprises[ $this->fichier . '#' . $id ] );
	}

	/**
	 * HTML en ligne assaini (typographie comprise).
	 *
	 * @param array<int,array<string,mixed>> $items   Segments.
	 * @param bool                           $inverse Italique inversé (pensée).
	 */
	private function html( array $items, bool $inverse ): string {
		$html = Inline::html( array_values( array_filter( $items, static fn( $i ) => 'image' !== $i['type'] ) ), $inverse );
		return $this->options['typographie'] && '' !== $html ? Texte::typographie( $html ) : $html;
	}

	/**
	 * Émet un paragraphe (et les images qu'il contient) selon ses classes et son texte.
	 *
	 * @param array<int,array<string,mixed>> $items   Segments.
	 * @param \DOMElement                    $el      Élément porteur (p, div…).
	 * @param string[]                       $herite  Classes héritées.
	 * @param bool                           $legende Légende d'illustration (centrée).
	 */
	private function emettre( array $items, \DOMElement $el, array $herite, bool $legende = false ): void {
		$a_image = false;
		foreach ( $items as $item ) {
			$a_image = $a_image || 'image' === $item['type'];
		}
		if ( ! $a_image && '' === Texte::espaces( Inline::texte_brut( $items ) ) ) {
			if ( in_array( strtolower( $el->localName ), array( 'p', 'div' ), true ) ) {
				$this->chapitres->vide();
			}
			return;
		}
		$partie = array();
		foreach ( $items as $item ) {
			if ( 'image' === $item['type'] ) {
				$this->paragraphe( $partie, $el, $herite, $legende );
				$partie            = array();
				$this->apres_titre = false;
				$this->chapitres->image( (string) $item['cle'] );
				continue;
			}
			$partie[] = $item;
		}
		$this->paragraphe( $partie, $el, $herite, $legende );
	}

	/**
	 * Paragraphe de texte.
	 *
	 * @param array<int,array<string,mixed>> $items   Segments (sans image).
	 * @param \DOMElement                    $el      Élément porteur.
	 * @param string[]                       $herite  Classes héritées.
	 * @param bool                           $legende Légende d'illustration.
	 */
	private function paragraphe( array $items, \DOMElement $el, array $herite, bool $legende ): void {
		$texte = Texte::espaces( Inline::texte_brut( $items ) );
		if ( '' === $texte ) {
			return;
		}
		$classes = self::classes( $el ) . implode( ' ', array_slice( $herite, -1 ) );
		$style   = strtolower( $el->getAttribute( 'style' ) );

		if ( preg_match( '/\b(subtitle|sous-?titre|soustitre|chapter-?subtitle)\b/', $classes ) && $this->chapitres->attend_sous_titre() ) {
			$this->chapitres->sous_titre( $texte );
			return;
		}
		if ( ! $this->avec_h1 && ! $this->liminaire && preg_match( '/\b(chapter-?title|titre-?chapitre|chapter-?head(?:ing)?|ch-?title)\b/', $classes ) ) {
			$analyse = Texte::analyser_titre( $texte );
			if ( 'inconnu' !== $analyse['motif'] ) {
				$this->chapitres->ouvrir( $analyse, $texte );
				$this->apres_titre = true;
				return;
			}
		}
		$this->apres_titre = false;
		if ( Texte::est_separateur( $texte ) || ( preg_match( '/\b(scene-?break|separateur|separator|asterism|dinkus|ornament|sep)\b/', $classes ) && mb_strlen( $texte, 'UTF-8' ) <= 15 ) ) {
			$this->chapitres->separateur();
			return;
		}

		$total  = 0;
		$italic = 0;
		foreach ( $items as $item ) {
			if ( 'texte' === $item['type'] ) {
				$n       = mb_strlen( trim( (string) $item['texte'] ), 'UTF-8' );
				$total  += $n;
				$italic += $item['i'] ? $n : 0;
			}
		}
		$centre = $legende || (bool) preg_match( '/\b(center|centre|centered|centree|centr|ctr|text-center|aligncenter|align-center|has-text-align-center)\b/', $classes ) || (bool) preg_match( '/text-align:\s*center/', $style );

		if ( preg_match( '/\b(dialogue|dialog|tiret|replique|dlg)\b/', $classes ) ) {
			$this->chapitres->paragraphe( Texte::normaliser_dialogue( $this->html( $items, false ), true ), 'dialogue' );
			return;
		}
		if ( Texte::commence_par_tiret( $texte ) ) {
			$this->chapitres->paragraphe( Texte::normaliser_dialogue( $this->html( $items, false ), false ), 'dialogue' );
			return;
		}
		$pensee_classe = (bool) preg_match( '/\b(pensee|pense|thought|thoughts|monologue|interieur|inner)\b/', $classes );
		if ( $pensee_classe || ( $total > 0 && $italic === $total && ! $centre ) ) {
			$this->chapitres->paragraphe( $this->html( $items, $italic * 2 >= max( 1, $total ) ), 'pensee', $centre );
			return;
		}
		$this->chapitres->paragraphe( $this->html( $items, false ), '', $centre );
	}

	/**
	 * Statistiques finales.
	 *
	 * @param float $debut Horodatage de début.
	 */
	private function conclure( float $debut ): void {
		$stats                    = &$this->resultat->stats;
		$stats['format']          = 'epub';
		$stats['fichier']         = basename( $this->chemin );
		$stats['octets']          = (int) filesize( $this->resultat->source );
		$stats['hash']            = (string) sha1_file( $this->resultat->source );
		$stats['images_gardees']  = count( $this->resultat->images );
		$stats['images_ignorees'] = $this->ignorees;
		$stats['images_emf']      = 0;
		$stats['sauts_de_page']   = 0;
		$stats['duree_ms']        = (int) round( ( microtime( true ) - $debut ) * 1000 );
		$stats['memoire_max_mo']  = round( memory_get_peak_usage( true ) / 1048576, 1 );
	}
}
