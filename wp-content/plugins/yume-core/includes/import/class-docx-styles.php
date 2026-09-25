<?php
/**
 * Styles et numérotations d'un DOCX (word/styles.xml, word/numbering.xml).
 *
 * La détection est robuste d'un fichier à l'autre : un style est reconnu par son
 * identifiant ET par son nom (« heading 1 », « Titre 1 », « Pensée », « Pense »…), ou par
 * son niveau hiérarchique (outlineLvl), en suivant l'héritage (basedOn). Les puces de liste
 * sont résolues jusqu'au texte du niveau (lvlText), y compris via un style de numérotation
 * (numStyleLink) ou une numérotation portée par le style du paragraphe.
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
 * Résolution des styles Word.
 */
final class Docx_Styles {

	/** Espace de noms WordprocessingML (transitionnel). */
	public const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/** Espace de noms WordprocessingML (strict). */
	public const W_STRICT = 'http://purl.oclc.org/ooxml/wordprocessingml/main';

	/**
	 * Rôle d'un style de paragraphe d'après son nom ou son identifiant normalisé.
	 *
	 * @var array<string,string>
	 */
	private const ROLES = array(
		'heading1'                 => 'titre1',
		'titre1'                   => 'titre1',
		'heading2'                 => 'titre2',
		'titre2'                   => 'titre2',
		'heading3'                 => 'titre3',
		'titre3'                   => 'titre3',
		'heading4'                 => 'titre3',
		'titre4'                   => 'titre3',
		'heading5'                 => 'titre3',
		'titre5'                   => 'titre3',
		'pensee'                   => 'pensee',
		'pense'                    => 'pensee',
		'pensees'                  => 'pensee',
		'thought'                  => 'pensee',
		'thoughts'                 => 'pensee',
		'dialogue'                 => 'dialogue',
		'dialogues'                => 'dialogue',
		'dialog'                   => 'dialogue',
		'replique'                 => 'dialogue',
		'repliques'                => 'dialogue',
		'separateur'               => 'separateur',
		'separateurdescene'        => 'separateur',
		'scenebreak'               => 'separateur',
		'separator'                => 'separateur',
		'toc1'                     => 'sommaire',
		'toc2'                     => 'sommaire',
		'toc3'                     => 'sommaire',
		'tm1'                      => 'sommaire',
		'tm2'                      => 'sommaire',
		'tm3'                      => 'sommaire',
		'tocheading'               => 'sommaire',
		'entetedetabledesmatieres' => 'sommaire',
	);

	/**
	 * Styles : id => description.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $styles = array();

	/**
	 * Style de paragraphe par défaut.
	 *
	 * @var string
	 */
	private string $defaut = '';

	/**
	 * Mise en forme par défaut du document (docDefaults).
	 *
	 * @var array<string,mixed>
	 */
	private array $rpr_defaut = array();

	/**
	 * Numérotations abstraites : id => ['niveaux' => ilvl => [format, texte], 'lien' => ?string].
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $abstraites = array();

	/**
	 * Numérotations : numId => ['abstraite' => id, 'surcharges' => ilvl => [format, texte]].
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $nums = array();

	/**
	 * Caches de résolution.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cache_p = array();

	/**
	 * Caches de résolution (caractère).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cache_c = array();

	/**
	 * Constructeur.
	 *
	 * @param \DOMDocument|null $styles      word/styles.xml.
	 * @param \DOMDocument|null $numerotation word/numbering.xml.
	 */
	public function __construct( ?\DOMDocument $styles, ?\DOMDocument $numerotation ) {
		if ( $styles ) {
			$this->lire_styles( $styles );
		}
		if ( $numerotation ) {
			$this->lire_numerotation( $numerotation );
		}
	}

	/**
	 * L'élément appartient-il à l'espace de noms WordprocessingML ?
	 *
	 * @param \DOMNode $noeud Nœud.
	 */
	public static function est_w( \DOMNode $noeud ): bool {
		return in_array( $noeud->namespaceURI, array( self::W, self::W_STRICT ), true );
	}

	/**
	 * Attribut WordprocessingML (w:val…), quel que soit l'espace de noms utilisé.
	 *
	 * @param \DOMElement|null $el  Élément.
	 * @param string           $nom Nom local de l'attribut.
	 */
	public static function attr( ?\DOMElement $el, string $nom = 'val' ): ?string {
		if ( null === $el ) {
			return null;
		}
		foreach ( array( self::W, self::W_STRICT ) as $ns ) {
			if ( $el->hasAttributeNS( $ns, $nom ) ) {
				return $el->getAttributeNS( $ns, $nom );
			}
		}
		return $el->hasAttribute( $nom ) ? $el->getAttribute( $nom ) : null;
	}

	/**
	 * Premier enfant élément de nom local donné.
	 *
	 * @param \DOMElement|null $el  Parent.
	 * @param string           $nom Nom local.
	 */
	public static function enfant( ?\DOMElement $el, string $nom ): ?\DOMElement {
		if ( null === $el ) {
			return null;
		}
		for ( $n = $el->firstChild; null !== $n; $n = $n->nextSibling ) {
			if ( $n instanceof \DOMElement && $n->localName === $nom ) {
				return $n;
			}
		}
		return null;
	}

	/**
	 * Enfants éléments de nom local donné.
	 *
	 * @param \DOMElement $el  Parent.
	 * @param string      $nom Nom local.
	 * @return \DOMElement[]
	 */
	public static function enfants( \DOMElement $el, string $nom ): array {
		$liste = array();
		for ( $n = $el->firstChild; null !== $n; $n = $n->nextSibling ) {
			if ( $n instanceof \DOMElement && $n->localName === $nom ) {
				$liste[] = $n;
			}
		}
		return $liste;
	}

	/**
	 * Valeur d'une propriété « bascule » (w:b, w:i…) : true, false ou null si absente.
	 *
	 * @param \DOMElement|null $el Élément de la propriété.
	 */
	private static function bascule( ?\DOMElement $el ): ?bool {
		if ( null === $el ) {
			return null;
		}
		$val = self::attr( $el );
		return null === $val || ! in_array( strtolower( $val ), array( '0', 'false', 'off', 'none' ), true );
	}

	/**
	 * Lit les propriétés de caractère utiles d'un w:rPr.
	 *
	 * @param \DOMElement|null $rpr Élément w:rPr.
	 * @return array{b:?bool,i:?bool,u:?bool,va:?string,cache:?bool,style:?string}
	 */
	public static function lire_rpr( ?\DOMElement $rpr ): array {
		$va  = self::attr( self::enfant( $rpr, 'vertAlign' ) );
		$sou = self::enfant( $rpr, 'u' );
		return array(
			'b'     => self::bascule( self::enfant( $rpr, 'b' ) ),
			'i'     => self::bascule( self::enfant( $rpr, 'i' ) ),
			'u'     => null === $sou ? null : 'none' !== strtolower( (string) self::attr( $sou ) ),
			'va'    => null === $va ? null : ( 'superscript' === $va ? 'sup' : ( 'subscript' === $va ? 'sub' : '' ) ),
			'cache' => self::bascule( self::enfant( $rpr, 'vanish' ) ) ?? self::bascule( self::enfant( $rpr, 'specVanish' ) ),
			'style' => self::attr( self::enfant( $rpr, 'rStyle' ) ),
		);
	}

	/**
	 * Fusionne des propriétés de caractère (les valeurs non nulles de $dessus l'emportent).
	 *
	 * @param array<string,mixed> $dessous Propriétés héritées.
	 * @param array<string,mixed> $dessus  Propriétés prioritaires.
	 * @return array<string,mixed>
	 */
	public static function fusionner( array $dessous, array $dessus ): array {
		foreach ( array( 'b', 'i', 'u', 'va', 'cache' ) as $cle ) {
			if ( isset( $dessus[ $cle ] ) ) {
				$dessous[ $cle ] = $dessus[ $cle ];
			}
		}
		return $dessous;
	}

	/**
	 * Lecture de styles.xml.
	 *
	 * @param \DOMDocument $doc Document.
	 */
	private function lire_styles( \DOMDocument $doc ): void {
		$racine = $doc->documentElement;
		if ( null === $racine ) {
			return;
		}
		$defauts = self::enfant( $racine, 'docDefaults' );
		if ( $defauts ) {
			$this->rpr_defaut = self::lire_rpr( self::enfant( self::enfant( $defauts, 'rPrDefault' ), 'rPr' ) );
		}
		foreach ( self::enfants( $racine, 'style' ) as $el ) {
			$id = (string) self::attr( $el, 'styleId' );
			if ( '' === $id ) {
				continue;
			}
			$type                = (string) ( self::attr( $el, 'type' ) ?? 'paragraph' );
			$ppr                 = self::enfant( $el, 'pPr' );
			$numpr               = self::enfant( $ppr, 'numPr' );
			$num                 = self::attr( self::enfant( $numpr, 'numId' ) );
			$ilvl                = self::attr( self::enfant( $numpr, 'ilvl' ) );
			$ol                  = self::attr( self::enfant( $ppr, 'outlineLvl' ) );
			$this->styles[ $id ] = array(
				'id'      => $id,
				'type'    => $type,
				'nom'     => (string) self::attr( self::enfant( $el, 'name' ) ),
				'base'    => self::attr( self::enfant( $el, 'basedOn' ) ),
				'lien'    => self::attr( self::enfant( $el, 'link' ) ),
				'num_id'  => $num,
				'a_num'   => null !== $numpr && null !== $num,
				'ilvl'    => null === $ilvl ? null : (int) $ilvl,
				'jc'      => self::attr( self::enfant( $ppr, 'jc' ) ),
				'outline' => null === $ol ? null : (int) $ol,
				'rpr'     => self::lire_rpr( self::enfant( $el, 'rPr' ) ),
			);
			if ( 'paragraph' === $type && in_array( strtolower( (string) self::attr( $el, 'default' ) ), array( '1', 'true', 'on' ), true ) ) {
				$this->defaut = $id;
			}
		}
	}

	/**
	 * Lecture de numbering.xml.
	 *
	 * @param \DOMDocument $doc Document.
	 */
	private function lire_numerotation( \DOMDocument $doc ): void {
		$racine = $doc->documentElement;
		if ( null === $racine ) {
			return;
		}
		foreach ( self::enfants( $racine, 'abstractNum' ) as $el ) {
			$id      = (string) self::attr( $el, 'abstractNumId' );
			$niveaux = array();
			foreach ( self::enfants( $el, 'lvl' ) as $lvl ) {
				$niveaux[ (int) self::attr( $lvl, 'ilvl' ) ] = self::lire_niveau( $lvl );
			}
			$this->abstraites[ $id ] = array(
				'niveaux' => $niveaux,
				'lien'    => self::attr( self::enfant( $el, 'numStyleLink' ) ),
			);
		}
		foreach ( self::enfants( $racine, 'num' ) as $el ) {
			$surcharges = array();
			foreach ( self::enfants( $el, 'lvlOverride' ) as $surcharge ) {
				$lvl = self::enfant( $surcharge, 'lvl' );
				if ( $lvl ) {
					$surcharges[ (int) self::attr( $surcharge, 'ilvl' ) ] = self::lire_niveau( $lvl );
				}
			}
			$this->nums[ (string) self::attr( $el, 'numId' ) ] = array(
				'abstraite'  => (string) self::attr( self::enfant( $el, 'abstractNumId' ) ),
				'surcharges' => $surcharges,
			);
		}
	}

	/**
	 * Lit un niveau de liste (w:lvl).
	 *
	 * @param \DOMElement $lvl Élément.
	 * @return array{format:string,texte:string}
	 */
	private static function lire_niveau( \DOMElement $lvl ): array {
		return array(
			'format' => (string) ( self::attr( self::enfant( $lvl, 'numFmt' ) ) ?? 'decimal' ),
			'texte'  => (string) ( self::attr( self::enfant( $lvl, 'lvlText' ) ) ?? '' ),
		);
	}

	/**
	 * Style de paragraphe par défaut (« Normal »).
	 */
	public function defaut_paragraphe(): string {
		return $this->defaut;
	}

	/**
	 * Mise en forme par défaut du document.
	 *
	 * @return array<string,mixed>
	 */
	public function rpr_defaut(): array {
		return $this->rpr_defaut;
	}

	/**
	 * Rôle propre d'un style d'après son nom, puis son identifiant.
	 *
	 * @param array<string,mixed> $style Style.
	 */
	private static function role_propre( array $style ): ?string {
		foreach ( array( $style['nom'], $style['id'] ) as $candidat ) {
			$cle = Texte::nom_style( (string) $candidat );
			if ( isset( self::ROLES[ $cle ] ) ) {
				return self::ROLES[ $cle ];
			}
		}
		return null;
	}

	/**
	 * Chaîne d'héritage d'un style (lui-même d'abord).
	 *
	 * @param string $id Identifiant.
	 * @return array<int,array<string,mixed>>
	 */
	private function chaine( string $id ): array {
		$chaine = array();
		$vus    = array();
		for ( $profondeur = 0; $profondeur < 30 && null !== $id && isset( $this->styles[ $id ] ) && ! isset( $vus[ $id ] ); $profondeur++ ) {
			$vus[ $id ] = true;
			$chaine[]   = $this->styles[ $id ];
			$id         = $this->styles[ $id ]['base'];
		}
		return $chaine;
	}

	/**
	 * Propriétés effectives d'un style de paragraphe.
	 *
	 * @param string $id Identifiant du style (chaîne vide : style par défaut).
	 * @return array{role:?string,num_id:?string,ilvl:?int,jc:?string,outline:?int,rpr:array<string,mixed>}
	 */
	public function paragraphe( string $id ): array {
		if ( '' === $id ) {
			$id = $this->defaut;
		}
		if ( isset( $this->cache_p[ $id ] ) ) {
			return $this->cache_p[ $id ];
		}
		$chaine     = $this->chaine( $id );
		$props      = array(
			'role'    => null,
			'num_id'  => null,
			'ilvl'    => null,
			'jc'      => null,
			'outline' => null,
			'rpr'     => $this->rpr_defaut,
		);
		$num_trouve = false;
		foreach ( $chaine as $style ) {
			if ( null === $props['role'] ) {
				$props['role'] = self::role_propre( $style );
			}
			if ( ! $num_trouve && $style['a_num'] ) {
				$num_trouve      = true;
				$props['num_id'] = (string) $style['num_id'];
				$props['ilvl']   = $style['ilvl'];
			}
			if ( null === $props['jc'] && null !== $style['jc'] ) {
				$props['jc'] = $style['jc'];
			}
			if ( null === $props['outline'] && null !== $style['outline'] ) {
				$props['outline'] = $style['outline'];
			}
		}
		foreach ( array_reverse( $chaine ) as $style ) {
			$props['rpr'] = self::fusionner( $props['rpr'], $style['rpr'] );
		}
		if ( null === $props['role'] && null !== $props['outline'] && $props['outline'] < 9 ) {
			$props['role'] = self::role_niveau( $props['outline'] );
		}
		$this->cache_p[ $id ] = $props;
		return $props;
	}

	/**
	 * Rôle correspondant à un niveau hiérarchique (outlineLvl 0 = titre 1).
	 *
	 * @param int $niveau Niveau (0 à 8).
	 */
	public static function role_niveau( int $niveau ): ?string {
		if ( $niveau < 0 || $niveau >= 9 ) {
			return null;
		}
		return 0 === $niveau ? 'titre1' : ( 1 === $niveau ? 'titre2' : 'titre3' );
	}

	/**
	 * Propriétés effectives d'un style de caractère.
	 *
	 * @param string $id Identifiant.
	 * @return array{rpr:array<string,mixed>,role:?string}
	 */
	public function caractere( string $id ): array {
		if ( isset( $this->cache_c[ $id ] ) ) {
			return $this->cache_c[ $id ];
		}
		$chaine = $this->chaine( $id );
		$rpr    = array();
		$role   = null;
		foreach ( array_reverse( $chaine ) as $style ) {
			$rpr = self::fusionner( $rpr, $style['rpr'] );
		}
		foreach ( $chaine as $style ) {
			$nom = Texte::nom_style( (string) $style['nom'] );
			if ( preg_match( '/^(pensee|pense|thought)/', $nom ) || preg_match( '/^(pensee|pense|thought)/', Texte::nom_style( (string) $style['id'] ) ) ) {
				$role = 'pensee';
				break;
			}
			// Style de caractère lié à un style de paragraphe « Pensée ».
			if ( null !== $style['lien'] && isset( $this->styles[ $style['lien'] ] ) && 'pensee' === $this->paragraphe( (string) $style['lien'] )['role'] ) {
				$role = 'pensee';
				break;
			}
		}
		$this->cache_c[ $id ] = array(
			'rpr'  => $rpr,
			'role' => $role,
		);
		return $this->cache_c[ $id ];
	}

	/**
	 * Niveau de liste effectif d'une numérotation.
	 *
	 * @param string $num_id Identifiant de numérotation (« 0 » : aucune).
	 * @param int    $ilvl   Niveau.
	 * @return array{format:string,texte:string,tiret:bool,ordonnee:bool}|null
	 */
	public function niveau( string $num_id, int $ilvl ): ?array {
		if ( '' === $num_id || '0' === $num_id || ! isset( $this->nums[ $num_id ] ) ) {
			return null;
		}
		$num       = $this->nums[ $num_id ];
		$niveau    = $num['surcharges'][ $ilvl ] ?? null;
		$abstraite = $this->abstraites[ $num['abstraite'] ] ?? null;
		$garde     = 0;
		// Numérotation définie par un style de numérotation (numStyleLink).
		while ( null === $niveau && null !== $abstraite && ! $abstraite['niveaux'] && null !== $abstraite['lien'] && $garde++ < 5 ) {
			$style = $this->styles[ $abstraite['lien'] ] ?? null;
			if ( null === $style || null === $style['num_id'] || ! isset( $this->nums[ $style['num_id'] ] ) ) {
				break;
			}
			$num       = $this->nums[ $style['num_id'] ];
			$niveau    = $num['surcharges'][ $ilvl ] ?? null;
			$abstraite = $this->abstraites[ $num['abstraite'] ] ?? null;
		}
		if ( null === $niveau && null !== $abstraite ) {
			$niveau = $abstraite['niveaux'][ $ilvl ] ?? ( $abstraite['niveaux'][0] ?? null );
		}
		if ( null === $niveau ) {
			return null;
		}
		$format = strtolower( $niveau['format'] );
		$texte  = $niveau['texte'];
		$tiret  = ! str_contains( $texte, '%' ) && Texte::est_puce_tiret( $texte );
		return array(
			'format'   => $format,
			'texte'    => $texte,
			'tiret'    => $tiret,
			'ordonnee' => ! $tiret && ! in_array( $format, array( 'bullet', 'none' ), true ),
		);
	}
}
