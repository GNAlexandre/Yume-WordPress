<?php
/**
 * Outils de texte du convertisseur : titres de chapitres, séparateurs de scène, tirets de
 * dialogue, typographie française, décompte des mots.
 *
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Fonctions de texte pures.
 */
final class Texte {

	/** Espace insécable. */
	public const INSECABLE = "\u{00A0}";

	/** Espace fine insécable. */
	public const FINE = "\u{202F}";

	/** Tiret de dialogue normalisé : tiret cadratin suivi d'une espace insécable. */
	public const TIRET_DIALOGUE = "—\u{00A0}";

	/**
	 * Caractères reconnus comme tiret de dialogue (y compris les puces « tiret » des polices
	 * Symbol : U+F02D et U+F0BE).
	 *
	 * @var string[]
	 */
	public const TIRETS = array( '-', "\u{2010}", "\u{2011}", "\u{2012}", '–', '—', '―', "\u{2212}", "\u{2043}", "\u{F02D}", "\u{F0BE}", "\u{FE58}", "\u{FE63}", "\u{FF0D}" );

	/**
	 * Blancs ignorés devant et après un tiret de dialogue (expression régulière, classe de
	 * caractères) : espaces Unicode (insécable, fine, cadratin…) et caractères invisibles
	 * (espace sans chasse, liant, marque d'ordre des octets).
	 */
	private const BLANCS = '[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]';

	/**
	 * Nombres écrits en toutes lettres (titres « Chapitre un »).
	 *
	 * @var array<string,int>
	 */
	private const NOMBRES = array(
		'un'          => 1,
		'une'         => 1,
		'premier'     => 1,
		'deux'        => 2,
		'trois'       => 3,
		'quatre'      => 4,
		'cinq'        => 5,
		'six'         => 6,
		'sept'        => 7,
		'huit'        => 8,
		'neuf'        => 9,
		'dix'         => 10,
		'onze'        => 11,
		'douze'       => 12,
		'treize'      => 13,
		'quatorze'    => 14,
		'quinze'      => 15,
		'seize'       => 16,
		'dix-sept'    => 17,
		'dix-huit'    => 18,
		'dix-neuf'    => 19,
		'vingt'       => 20,
		'one'         => 1,
		'two'         => 2,
		'three'       => 3,
		'four'        => 4,
		'five'        => 5,
		'seven'       => 7,
		'eight'       => 8,
		'nine'        => 9,
		'ten'         => 10,
		'eleven'      => 11,
		'twelve'      => 12,
		'thirteen'    => 13,
		'fourteen'    => 14,
		'fifteen'     => 15,
		'sixteen'     => 16,
		'seventeen'   => 17,
		'eighteen'    => 18,
		'nineteen'    => 19,
		'twenty'      => 20,
		'vingt-et-un' => 21,
	);

	/**
	 * Chapitres spéciaux reconnus en tête de titre : motif => nature.
	 *
	 * @var array<string,string>
	 */
	private const SPECIAUX = array(
		'prologue'                        => 'prologue',
		'[ée]pilogue'                     => 'epilogue',
		'interlude'                       => 'interlude',
		'interm[èe]de'                    => 'interlude',
		'entracte'                        => 'interlude',
		'post[\s\-]?face'                 => 'postface',
		'afterword'                       => 'postface',
		'mot\s+de\s+la\s+fin'             => 'postface',
		'mot\s+de\s+l[\'’]auteur'         => 'postface',
		'histoires?\s+bonus'              => 'bonus',
		'bonus'                           => 'bonus',
		'extra'                           => 'bonus',
		'side\s*story'                    => 'bonus',
		'histoires?\s+courtes?'           => 'bonus',
		'illustrations?(?:\s+couleurs?)?' => 'illustrations',
		'galerie'                         => 'illustrations',
	);

	/**
	 * Descriptions d'image générées automatiquement par Word / Office (texte de remplacement
	 * proposé par la reconnaissance d'image), à ne jamais reprendre comme texte alternatif.
	 *
	 * Expressions régulières appliquées au texte normalisé par alt_automatique() : minuscules,
	 * sans accents (sans_accents()), apostrophes droites, espaces réduites, ponctuation finale
	 * retirée. Un motif ancré (^) reconnaît un début de phrase ; les autres, une mention
	 * n'importe où (Word accole souvent la description et la mention « généré par l'IA »).
	 * Les débuts trop génériques (« Gros plan de », « A close-up of ») ne sont pas repris :
	 * un humain peut légitimement les écrire.
	 *
	 * @var string[]
	 */
	public const ALT_AUTOMATIQUES = array(
		// Français (Office 365, Word 2019 et suivants).
		'/^une image contenant\b/',
		'/le contenu genere par l\'ia peut etre incorrect/',
		'/description generee automatiquement/',
		'/description generee avec un niveau de confiance (?:tres )?(?:eleve|moyen|faible)/',
		// Anglais.
		'/^a picture containing\b/',
		'/ai-generated content may be incorrect/',
		'/description automatically generated/',
	);

	/**
	 * Libellés des natures de chapitre.
	 *
	 * @var array<string,string>
	 */
	public const LIBELLES = array(
		'chapitre'      => 'Chapitre',
		'prologue'      => 'Prologue',
		'interlude'     => 'Interlude',
		'epilogue'      => 'Épilogue',
		'postface'      => 'Postface',
		'bonus'         => 'Bonus',
		'illustrations' => 'Illustrations',
	);

	/**
	 * Normalise les espaces d'un texte brut : insécables et tabulations → espace, espaces
	 * multiples réduites, extrémités retirées.
	 *
	 * @param string $texte Texte.
	 */
	public static function espaces( string $texte ): string {
		$texte = str_replace( array( self::INSECABLE, self::FINE, "\u{2007}", "\t", "\r", "\n" ), ' ', $texte );
		return trim( (string) preg_replace( '/ {2,}/', ' ', $texte ) );
	}

	/**
	 * Le texte alternatif est-il une description générée automatiquement par Word / Office
	 * (« Une image contenant texte, oiseau, croquis Le contenu généré par l'IA peut être
	 * incorrect. », « A picture containing text Description automatically generated »…) ?
	 * Casse, accents, espaces et ponctuation finale indifférents (motifs : ALT_AUTOMATIQUES).
	 *
	 * @param string $alt Texte alternatif.
	 */
	public static function alt_automatique( string $alt ): bool {
		$texte = self::sans_accents( mb_strtolower( self::espaces( $alt ), 'UTF-8' ) );
		$texte = str_replace( array( '’', '‘', '`' ), "'", $texte );
		$texte = (string) preg_replace( '/[\s.…!?:;,]+$/u', '', $texte );
		if ( '' === $texte ) {
			return false;
		}
		foreach ( self::ALT_AUTOMATIQUES as $motif ) {
			if ( preg_match( $motif, $texte ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Texte brut d'un fragment HTML (balises retirées, entités décodées).
	 *
	 * @param string $html HTML.
	 */
	public static function texte( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '/<br\s*\/?>/i', ' ', $html );
		return html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}

	/**
	 * Compte les mots d'un contenu (même règle que le module core : « l’homme » compte pour
	 * un mot, commentaires de blocs et balises exclus).
	 *
	 * @param string $html Contenu HTML ou blocs.
	 */
	public static function compter_mots( string $html ): int {
		$nb = preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', self::texte( $html ) );
		return false === $nb ? 0 : (int) $nb;
	}

	/**
	 * Le texte commence-t-il par un tiret de dialogue ?
	 *
	 * @param string $texte Texte brut.
	 */
	public static function commence_par_tiret( string $texte ): bool {
		$texte = (string) preg_replace( '/^' . self::BLANCS . '+/u', '', self::espaces( $texte ) );
		if ( '' === $texte ) {
			return false;
		}
		$premier = mb_substr( $texte, 0, 1, 'UTF-8' );
		if ( ! in_array( $premier, self::TIRETS, true ) ) {
			return false;
		}
		// « -1 », « --- » ou un tiret seul ne sont pas des répliques.
		$reste = (string) preg_replace( '/^' . self::BLANCS . '+/u', '', mb_substr( $texte, 1, null, 'UTF-8' ) );
		return '' !== $reste && ! preg_match( '/^[\d\-–—]/u', $reste );
	}

	/**
	 * Le texte est-il une puce « tiret » (texte de niveau de liste Word) ?
	 *
	 * @param string $texte Texte du niveau (lvlText).
	 */
	public static function est_puce_tiret( string $texte ): bool {
		$texte = trim( str_replace( self::INSECABLE, ' ', $texte ) );
		return '' !== $texte && in_array( $texte, self::TIRETS, true );
	}

	/**
	 * Le paragraphe est-il un séparateur de scène (***, * * *, ◇, ✿, #…) ?
	 *
	 * @param string $texte Texte brut du paragraphe.
	 */
	public static function est_separateur( string $texte ): bool {
		$texte = self::espaces( $texte );
		if ( '' === $texte || mb_strlen( $texte, 'UTF-8' ) > 40 ) {
			return false;
		}
		return (bool) preg_match( '/^(?:[*∗⁎⁑⁂＊✱✲✳✴✵✶✷✸✹✺✻✼✽✾✿❀❁❂❃❄❅❆❇❈❉❊❋◇◆◈◊♢♦❖○●◎◯☆★✦✧✩✪·•∙#＃~～=_⋆❦❧♠♣♥♡❥]\s*){1,15}$/u', $texte );
	}

	/**
	 * Convertit un nombre romain (I à CC) en entier, ou null.
	 *
	 * @param string $romain Chiffres romains.
	 */
	public static function romain( string $romain ): ?int {
		$romain = strtoupper( $romain );
		if ( '' === $romain || ! preg_match( '/^C{0,2}(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/', $romain ) ) {
			return null;
		}
		$valeurs = array(
			'I' => 1,
			'V' => 5,
			'X' => 10,
			'L' => 50,
			'C' => 100,
		);
		$total   = 0;
		$n       = strlen( $romain );
		for ( $i = 0; $i < $n; $i++ ) {
			$v = $valeurs[ $romain[ $i ] ];
			if ( $i + 1 < $n && $valeurs[ $romain[ $i + 1 ] ] > $v ) {
				$total -= $v;
			} else {
				$total += $v;
			}
		}
		return $total > 0 ? $total : null;
	}

	/**
	 * Numéro lisible par une URL (« 3 », « 12.5 »).
	 *
	 * @param float $numero Numéro.
	 */
	public static function numero_url( float $numero ): string {
		if ( floor( $numero ) === $numero ) {
			return (string) (int) $numero;
		}
		return rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * Numéro affiché en français (« 3 », « 26,5 »).
	 *
	 * @param float $numero Numéro.
	 */
	public static function numero_fr( float $numero ): string {
		return str_replace( '.', ',', self::numero_url( $numero ) );
	}

	/**
	 * Lit un numéro de chapitre écrit en chiffres, en romains ou en toutes lettres.
	 *
	 * @param string $brut Numéro brut.
	 */
	private static function lire_numero( string $brut ): ?float {
		$brut = trim( $brut );
		if ( preg_match( '/^\d+(?:[.,]\d+)?$/', $brut ) ) {
			return round( (float) str_replace( ',', '.', $brut ), 3 );
		}
		$mot = mb_strtolower( $brut, 'UTF-8' );
		if ( isset( self::NOMBRES[ $mot ] ) ) {
			return (float) self::NOMBRES[ $mot ];
		}
		$romain = self::romain( $brut );
		return null === $romain ? null : (float) $romain;
	}

	/**
	 * Analyse le texte d'un titre de chapitre.
	 *
	 * Reconnaît « Chapitre 3 », « Chapitre2 », «  Chapitre 13 », « Chapitre 12 bis »,
	 * « Chapitre 12.5 », « Chapitre IV », « Chapter 3 », « Chapitre 3 : Le titre »,
	 * « Prologue », « Épilogue », « Interlude 2 », « PostFace », « Bonus »…
	 *
	 * @param string $brut Texte brut du titre.
	 * @return array{motif:string,nature:string,numero:?float,titre:string,sous_titre:string,corrections:string[]}
	 *         motif : « chapitre », « special » ou « inconnu ».
	 */
	public static function analyser_titre( string $brut ): array {
		$corrections = array();
		$propre      = self::espaces( $brut );
		$sans_ins    = str_replace( array( self::INSECABLE, self::FINE ), ' ', $brut );
		if ( $propre !== $sans_ins ) {
			$corrections[] = 'espaces';
		}
		$resultat = array(
			'motif'       => 'inconnu',
			'nature'      => 'chapitre',
			'numero'      => null,
			'titre'       => $propre,
			'sous_titre'  => '',
			'corrections' => $corrections,
		);
		if ( '' === $propre ) {
			return $resultat;
		}

		$mots = array_keys( self::NOMBRES );
		usort( $mots, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$mots_nombre = implode( '|', array_map( static fn( string $m ): string => preg_quote( $m, '/' ), $mots ) );
		$motif       = '/^(chapitre|chapter|chap\.?|ch\.)(\s*)(\d+(?:[.,]\d+)?|[IVXLC]+\b|(?:' . $mots_nombre . ')\b)(?:\s*(bis|ter)\b)?(?:\s*[:.\-–—|]\s*|\s+)?(.*)$/iu';
		if ( preg_match( $motif, $propre, $m ) ) {
			$numero = self::lire_numero( $m[3] );
			if ( null !== $numero ) {
				$suffixe = strtolower( (string) $m[4] );
				if ( 'bis' === $suffixe ) {
					$numero += 0.5;
				} elseif ( 'ter' === $suffixe ) {
					$numero += 0.6;
				}
				$titre = 'Chapitre ' . ( '' !== $suffixe ? self::numero_fr( floor( $numero ) ) . ' ' . $suffixe : self::numero_fr( $numero ) );
				if ( '' === $m[2] && ctype_digit( substr( $m[3], 0, 1 ) ) ) {
					$corrections[] = 'espace_manquante';
				}
				if ( ! preg_match( '/^chapitre$/iu', $m[1] ) ) {
					$corrections[] = 'libelle';
				}
				if ( '' !== $suffixe ) {
					$corrections[] = 'suffixe';
				}
				return array(
					'motif'       => 'chapitre',
					'nature'      => 'chapitre',
					'numero'      => round( $numero, 3 ),
					'titre'       => $titre,
					'sous_titre'  => trim( (string) $m[5] ),
					'corrections' => $corrections,
				);
			}
		}

		foreach ( self::SPECIAUX as $expression => $nature ) {
			if ( preg_match( '/^(?:' . $expression . ')\b(?:\s*(\d+|[IVX]+\b))?(?:\s*[:.\-–—|]\s*|\s+)?(.*)$/iu', $propre, $m ) ) {
				$numero = isset( $m[1] ) && '' !== $m[1] ? self::lire_numero( $m[1] ) : null;
				if ( 'prologue' === $nature ) {
					$numero = 0.0;
				}
				$titre = self::LIBELLES[ $nature ];
				if ( null !== $numero && in_array( $nature, array( 'interlude', 'bonus' ), true ) ) {
					$titre .= ' ' . self::numero_fr( $numero );
				}
				return array(
					'motif'       => 'special',
					'nature'      => $nature,
					'numero'      => $numero,
					'titre'       => $titre,
					'sous_titre'  => trim( (string) ( $m[2] ?? '' ) ),
					'corrections' => $corrections,
				);
			}
		}
		return $resultat;
	}

	/**
	 * Paragraphe marqueur de début de chapitre, écrit dans le document : « [chapitre] »,
	 * « [chapitre] Titre », « [bonus] Titre », « [prologue] », « [interlude] … », « [épilogue] … »,
	 * « [postface] … » (casse et accents indifférents). Tout le paragraphe doit être le marqueur.
	 *
	 * @param string $texte Texte brut du paragraphe.
	 * @return array{nature:string,titre:string}|null Nature et titre (éventuellement vide), ou null.
	 */
	public static function marqueur( string $texte ): ?array {
		$texte = self::espaces( $texte );
		if ( '' === $texte || '[' !== $texte[0] || mb_strlen( $texte, 'UTF-8' ) > 260 ) {
			return null;
		}
		if ( ! preg_match( '/^\[\s*([\p{L}]+)\s*\]\s*(?:[:.\-–—]\s*)?(.*)$/u', $texte, $m ) ) {
			return null;
		}
		$natures = array(
			'chapitre'  => 'chapitre',
			'bonus'     => 'bonus',
			'prologue'  => 'prologue',
			'interlude' => 'interlude',
			'epilogue'  => 'epilogue',
			'postface'  => 'postface',
		);
		$mot     = self::sans_accents( mb_strtolower( $m[1], 'UTF-8' ) );
		if ( ! isset( $natures[ $mot ] ) ) {
			return null;
		}
		return array(
			'nature' => $natures[ $mot ],
			'titre'  => mb_substr( trim( $m[2] ), 0, 200, 'UTF-8' ),
		);
	}

	/**
	 * Le titre correspond-il à un chapitre spécial (prologue, postface…) ?
	 *
	 * @param string $texte Texte brut.
	 */
	public static function est_special( string $texte ): bool {
		$analyse = self::analyser_titre( $texte );
		return 'special' === $analyse['motif'] && '' === $analyse['sous_titre'];
	}

	/**
	 * Découpe un fragment HTML en jetons (balises et textes).
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private static function jetons( string $html ): array {
		$jetons = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		return false === $jetons ? array( $html ) : $jetons;
	}

	/**
	 * Typographie française : espace insécable avant « ? ! : ; » et à l'intérieur des
	 * guillemets « », y compris quand une balise sépare l'espace de la ponctuation.
	 * Les espaces insécables déjà présentes sont conservées.
	 *
	 * @param string $html HTML en ligne.
	 */
	public static function typographie( string $html ): string {
		$jetons = self::jetons( $html );
		$prec   = -1; // Index du dernier jeton de texte.
		foreach ( $jetons as $i => $jeton ) {
			if ( '' === $jeton || '<' === $jeton[0] ) {
				continue;
			}
			$jeton = (string) preg_replace( '/ (?=[?!:;»])/u', self::INSECABLE, $jeton );
			$jeton = (string) preg_replace( '/« /u', '«' . self::INSECABLE, $jeton );
			if ( $prec >= 0 ) {
				$avant = $jetons[ $prec ];
				if ( preg_match( '/^[?!:;»]/u', $jeton ) && str_ends_with( $avant, ' ' ) ) {
					$jetons[ $prec ] = substr( $avant, 0, -1 ) . self::INSECABLE;
				}
				if ( str_ends_with( $jetons[ $prec ], '«' ) && str_starts_with( $jeton, ' ' ) ) {
					$jeton = self::INSECABLE . substr( $jeton, 1 );
				}
			}
			$jetons[ $i ] = $jeton;
			$prec         = $i;
		}
		return implode( '', $jetons );
	}

	/**
	 * Normalise le tiret de dialogue en tête d'un paragraphe : « — » + espace insécable.
	 * Un tiret existant (quel qu'il soit, éventuellement dans une balise) est remplacé ;
	 * sinon, avec $ajouter, le tiret est ajouté (puce « — » d'une liste Word).
	 *
	 * @param string $html    HTML en ligne.
	 * @param bool   $ajouter Ajouter le tiret s'il manque.
	 */
	public static function normaliser_dialogue( string $html, bool $ajouter ): string {
		$jetons = self::jetons( $html );
		foreach ( $jetons as $i => $jeton ) {
			if ( '<' === $jeton[0] ) {
				continue;
			}
			$sans = (string) preg_replace( '/^' . self::BLANCS . '+/u', '', $jeton );
			if ( '' === $sans ) {
				continue;
			}
			$premier = mb_substr( $sans, 0, 1, 'UTF-8' );
			if ( in_array( $premier, self::TIRETS, true ) ) {
				$reste        = (string) preg_replace( '/^' . self::BLANCS . '*./u', '', $jeton, 1 );
				$reste        = (string) preg_replace( '/^' . self::BLANCS . '+/u', '', $reste );
				$jetons[ $i ] = self::TIRET_DIALOGUE . $reste;
				return implode( '', $jetons );
			}
			break;
		}
		if ( ! $ajouter ) {
			return $html;
		}
		return self::TIRET_DIALOGUE . ltrim( $html );
	}

	/**
	 * Clé ASCII (a-z, 0-9, tirets) pour identifier une image.
	 *
	 * @param string $texte Texte (nom de fichier…).
	 */
	public static function cle( string $texte ): string {
		$texte = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', self::sans_accents( $texte ) ) );
		$texte = trim( $texte, '-' );
		return '' !== $texte ? substr( $texte, 0, 60 ) : 'image';
	}

	/**
	 * Nom de style normalisé pour la comparaison (minuscules, sans accents ni espaces).
	 *
	 * @param string $nom Nom de style Word.
	 */
	public static function nom_style( string $nom ): string {
		return (string) preg_replace( '/[^a-z0-9]+/', '', self::sans_accents( mb_strtolower( $nom, 'UTF-8' ) ) );
	}

	/**
	 * Remplace les lettres accentuées courantes par leur lettre de base.
	 *
	 * @param string $texte Texte.
	 */
	public static function sans_accents( string $texte ): string {
		return strtr(
			$texte,
			array(
				'À' => 'A',
				'Â' => 'A',
				'Ä' => 'A',
				'É' => 'E',
				'È' => 'E',
				'Ê' => 'E',
				'Ë' => 'E',
				'Î' => 'I',
				'Ï' => 'I',
				'Ô' => 'O',
				'Ö' => 'O',
				'Ù' => 'U',
				'Û' => 'U',
				'Ü' => 'U',
				'Ç' => 'C',
				'Œ' => 'OE',
				'œ' => 'oe',
				'Æ' => 'AE',
				'æ' => 'ae',
				'à' => 'a',
				'â' => 'a',
				'ä' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'ë' => 'e',
				'î' => 'i',
				'ï' => 'i',
				'ô' => 'o',
				'ö' => 'o',
				'ù' => 'u',
				'û' => 'u',
				'ü' => 'u',
				'ç' => 'c',
			)
		);
	}
}
