<?php
/**
 * Lecture YAML (sous-ensemble) pour les glossaires de Yume-Trad.
 *
 * WordPress.com n'a pas l'extension PECL yaml et le dépôt n'embarque pas symfony/yaml : ce
 * lecteur couvre le YAML « bloc » que produisent les outils courants (et Yume-Trad) :
 * - mappings et séquences en bloc, séquence au même retrait que sa clé (« clé:\n- a ») ;
 * - scalaires simples sur plusieurs lignes (repliés), entre apostrophes ('' échappé) ou entre
 *   guillemets (échappements \n \t \" \\ \/ \xHH \uHHHH \UHHHHHHHH), sur plusieurs lignes ;
 * - scalaires en bloc | et > avec indicateurs de fin (- +) et de retrait ;
 * - collections en flux [a, 'b'] et {c: d}, imbriquées, sur une ou plusieurs lignes ;
 * - commentaires (# en début de ligne ou après une espace), marqueurs --- et ... ;
 * - null (~, null, vide), booléens (true/false), entiers et décimaux.
 * Refusés avec un message situé (ligne) : ancres et alias (& *), étiquettes (!), clés
 * complexes (?), tabulations de retrait, documents multiples.
 *
 * Aucune dépendance à WordPress : testable en PHP seul.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

// Messages d'erreur en texte brut : échappés par l'appelant à l'affichage.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

require_once __DIR__ . '/class-erreur-yaml.php';

/**
 * Lecteur YAML.
 */
class Lecteur_Yaml {

	/** Taille maximale acceptée (octets). */
	const TAILLE_MAX = 4194304;

	/** Profondeur d'imbrication maximale. */
	const PROFONDEUR_MAX = 64;

	/**
	 * Lignes du document (sans fin de ligne).
	 *
	 * @var string[]
	 */
	private array $lignes = array();

	/**
	 * Ligne courante (index).
	 *
	 * @var int
	 */
	private int $i = 0;

	/**
	 * Profondeur courante.
	 *
	 * @var int
	 */
	private int $profondeur = 0;

	/**
	 * Analyse un document YAML.
	 *
	 * @param string $texte Document.
	 * @return mixed Tableau associatif, liste, scalaire ou null (document vide).
	 * @throws Erreur_Yaml Document invalide ou hors du sous-ensemble.
	 */
	public static function analyser( string $texte ) {
		if ( strlen( $texte ) > self::TAILLE_MAX ) {
			throw new Erreur_Yaml( 'fichier trop volumineux (4 Mo au plus).' );
		}
		if ( str_starts_with( $texte, "\xEF\xBB\xBF" ) ) {
			$texte = substr( $texte, 3 );
		}
		if ( ! preg_match( '//u', $texte ) ) {
			throw new Erreur_Yaml( 'le fichier n’est pas en UTF-8.' );
		}
		$lecteur         = new self();
		$lecteur->lignes = preg_split( '/\r\n|\r|\n/', $texte );
		return $lecteur->document();
	}

	/**
	 * Document entier.
	 *
	 * @return mixed
	 * @throws Erreur_Yaml Erreur.
	 */
	private function document() {
		$this->sauter_vides();
		if ( $this->i < count( $this->lignes ) && preg_match( '/^---(\s|$)/', $this->lignes[ $this->i ] ) ) {
			$reste = trim( substr( $this->lignes[ $this->i ], 3 ) );
			if ( '' !== $reste && ! str_starts_with( $reste, '#' ) ) {
				throw new Erreur_Yaml( 'contenu après « --- » non pris en charge.', $this->i + 1 );
			}
			++$this->i;
			$this->sauter_vides();
		}
		if ( $this->fin() ) {
			return null;
		}
		$valeur = $this->bloc( $this->retrait( $this->i ), -1 );
		$this->sauter_vides();
		if ( ! $this->fin() ) {
			$ligne = $this->lignes[ $this->i ];
			if ( preg_match( '/^(---|\.\.\.)(\s|$)/', $ligne ) ) {
				++$this->i;
				$this->sauter_vides();
				if ( ! $this->fin() ) {
					throw new Erreur_Yaml( 'un seul document YAML par fichier.', $this->i + 1 );
				}
				return $valeur;
			}
			throw new Erreur_Yaml( 'retrait incohérent.', $this->i + 1 );
		}
		return $valeur;
	}

	/**
	 * Fin du document atteinte ?
	 */
	private function fin(): bool {
		return $this->i >= count( $this->lignes );
	}

	/**
	 * Ligne vide ou commentaire seul ?
	 *
	 * @param string $ligne Ligne.
	 */
	private static function est_vide( string $ligne ): bool {
		$t = ltrim( $ligne, ' ' );
		return '' === trim( $t ) || str_starts_with( $t, '#' );
	}

	/**
	 * Avance jusqu'à la prochaine ligne de contenu.
	 */
	private function sauter_vides(): void {
		while ( ! $this->fin() && self::est_vide( $this->lignes[ $this->i ] ) ) {
			++$this->i;
		}
	}

	/**
	 * Retrait (espaces) d'une ligne ; les tabulations de retrait sont refusées.
	 *
	 * @param int $n Index.
	 * @throws Erreur_Yaml Tabulation.
	 */
	private function retrait( int $n ): int {
		$ligne   = $this->lignes[ $n ];
		$retrait = strspn( $ligne, ' ' );
		if ( isset( $ligne[ $retrait ] ) && "\t" === $ligne[ $retrait ] && '' !== trim( $ligne ) ) {
			throw new Erreur_Yaml( 'tabulation dans le retrait (utiliser des espaces).', $n + 1 );
		}
		return $retrait;
	}

	/**
	 * Nœud en bloc commençant à la ligne courante, au retrait donné.
	 *
	 * @param int $retrait Retrait de la ligne courante.
	 * @param int $seuil Retrait du parent (seuil des lignes de continuation).
	 * @return mixed
	 * @throws Erreur_Yaml Erreur.
	 */
	private function bloc( int $retrait, int $seuil ) {
		if ( ++$this->profondeur > self::PROFONDEUR_MAX ) {
			throw new Erreur_Yaml( 'imbrication trop profonde.', $this->i + 1 );
		}
		$texte = substr( $this->lignes[ $this->i ], $retrait );
		if ( '-' === $texte || str_starts_with( $texte, '- ' ) ) {
			$valeur = $this->sequence( $retrait );
		} elseif ( null !== $this->cle( $texte ) ) {
			$valeur = $this->mapping( $retrait );
		} else {
			$valeur = $this->scalaire( $texte, $seuil );
		}
		--$this->profondeur;
		return $valeur;
	}

	/**
	 * Séquence en bloc.
	 *
	 * @param int $retrait Retrait des tirets.
	 * @return array<int,mixed>
	 * @throws Erreur_Yaml Erreur.
	 */
	private function sequence( int $retrait ): array {
		$liste = array();
		while ( ! $this->fin() ) {
			$this->sauter_vides();
			if ( $this->fin() ) {
				break;
			}
			$r = $this->retrait( $this->i );
			if ( $r < $retrait ) {
				break;
			}
			$texte = substr( $this->lignes[ $this->i ], $r );
			if ( $r > $retrait ) {
				throw new Erreur_Yaml( 'retrait incohérent dans une liste.', $this->i + 1 );
			}
			if ( '-' !== $texte && ! str_starts_with( $texte, '- ' ) ) {
				break;
			}
			$apres = ltrim( substr( $texte, 1 ), ' ' );
			if ( '' === $apres || str_starts_with( $apres, '#' ) ) {
				++$this->i;
				$this->sauter_vides();
				if ( ! $this->fin() && $this->retrait( $this->i ) > $retrait ) {
					$liste[] = $this->bloc( $this->retrait( $this->i ), $retrait );
				} else {
					$liste[] = null;
				}
				continue;
			}
			// Élément en ligne : la ligne devient une ligne virtuelle au retrait de son contenu
			// (« - clé: v » suivi de « ␣␣clé2: v » forme un seul mapping).
			$colonne                  = $r + ( strlen( $texte ) - strlen( $apres ) );
			$this->lignes[ $this->i ] = str_repeat( ' ', $colonne ) . $apres;
			$liste[]                  = $this->bloc( $colonne, $retrait );
		}
		return $liste;
	}

	/**
	 * Clé d'une ligne « clé: … » : array{0: string clé, 1: string reste} ou null.
	 *
	 * @param string $texte Ligne sans retrait.
	 * @return array{0:string,1:string}|null
	 * @throws Erreur_Yaml Clé complexe ou ancre.
	 */
	private function cle( string $texte ): ?array {
		if ( '' === $texte ) {
			return null;
		}
		$premier = $texte[0];
		if ( '?' === $premier && ( '?' === $texte || ' ' === ( $texte[1] ?? '' ) ) ) {
			throw new Erreur_Yaml( 'clés complexes (?) non prises en charge.', $this->i + 1 );
		}
		if ( '"' === $premier || "'" === $premier ) {
			$fin = $this->fin_guillemets( $texte, 0 );
			if ( null === $fin ) {
				return null;
			}
			$apres = substr( $texte, $fin + 1 );
			if ( ! preg_match( '/^\s*:(\s|$)/', $apres ) ) {
				return null;
			}
			$cle = $this->decoder_guillemets( substr( $texte, 0, $fin + 1 ) );
			return array( $cle, ltrim( (string) substr( ltrim( $apres ), 1 ) ) );
		}
		if ( in_array( $premier, array( '[', '{', '#', '|', '>', '&', '*', '!', '%', '@', '`' ), true ) ) {
			return null;
		}
		// Le premier « : » suivi d'une espace (ou en fin de ligne) sépare la clé ; un « # » précédé
		// d'une espace ouvre un commentaire avant lui.
		$pos = null;
		$len = strlen( $texte );
		for ( $k = 0; $k < $len; $k++ ) {
			if ( '#' === $texte[ $k ] && $k > 0 && ' ' === $texte[ $k - 1 ] ) {
				break;
			}
			if ( ':' === $texte[ $k ] && ( $k + 1 === $len || ' ' === $texte[ $k + 1 ] ) ) {
				$pos = $k;
				break;
			}
		}
		if ( null === $pos ) {
			return null;
		}
		$cle = rtrim( substr( $texte, 0, $pos ) );
		if ( '' === $cle ) {
			return null;
		}
		return array( $cle, ltrim( substr( $texte, $pos + 1 ) ) );
	}

	/**
	 * Mapping en bloc.
	 *
	 * @param int $retrait Retrait des clés.
	 * @return array<string,mixed>
	 * @throws Erreur_Yaml Erreur.
	 */
	private function mapping( int $retrait ): array {
		$map = array();
		while ( ! $this->fin() ) {
			$this->sauter_vides();
			if ( $this->fin() ) {
				break;
			}
			$r = $this->retrait( $this->i );
			if ( $r < $retrait ) {
				break;
			}
			if ( $r > $retrait ) {
				throw new Erreur_Yaml( 'retrait incohérent.', $this->i + 1 );
			}
			$texte = substr( $this->lignes[ $this->i ], $r );
			if ( '-' === $texte || str_starts_with( $texte, '- ' ) ) {
				break;
			}
			$cle = $this->cle( $texte );
			if ( null === $cle ) {
				throw new Erreur_Yaml( 'une ligne « clé: valeur » est attendue.', $this->i + 1 );
			}
			list( $nom, $reste ) = $cle;
			$reste               = $this->sans_commentaire( $reste );
			if ( '' === $reste ) {
				++$this->i;
				$this->sauter_vides();
				if ( $this->fin() ) {
					$valeur = null;
				} else {
					$r2    = $this->retrait( $this->i );
					$suite = substr( $this->lignes[ $this->i ], $r2 );
					if ( $r2 > $retrait ) {
						$valeur = $this->bloc( $r2, $retrait );
					} elseif ( $r2 === $retrait && ( '-' === $suite || str_starts_with( $suite, '- ' ) ) ) {
						$valeur = $this->sequence( $retrait );
					} else {
						$valeur = null;
					}
				}
			} elseif ( '|' === $reste[0] || '>' === $reste[0] ) {
				$valeur = $this->bloc_scalaire( $reste, $retrait );
			} else {
				$this->lignes[ $this->i ] = str_repeat( ' ', $r ) . $reste;
				$valeur                   = $this->scalaire( $reste, $retrait );
			}
			$map[ $nom ] = $valeur;
		}
		return $map;
	}

	/**
	 * Retire un commentaire de fin de ligne (hors guillemets) et les espaces.
	 *
	 * @param string $texte Texte.
	 */
	private function sans_commentaire( string $texte ): string {
		if ( '' === $texte ) {
			return '';
		}
		if ( '#' === $texte[0] ) {
			return '';
		}
		if ( '"' === $texte[0] || "'" === $texte[0] || '[' === $texte[0] || '{' === $texte[0] ) {
			return rtrim( $texte );
		}
		$pos = strpos( $texte, ' #' );
		return rtrim( false === $pos ? $texte : substr( $texte, 0, $pos ) );
	}

	/**
	 * Scalaire (simple, entre guillemets ou en flux) commençant sur la ligne courante ; consomme
	 * ses lignes de continuation (retrait > $seuil).
	 *
	 * @param string $texte  Début (sans retrait).
	 * @param int    $seuil Retrait du parent.
	 * @return mixed
	 * @throws Erreur_Yaml Erreur.
	 */
	private function scalaire( string $texte, int $seuil ) {
		$debut = $this->i;
		$texte = ltrim( $texte, ' ' );
		$c     = $texte[0] ?? '';
		if ( '&' === $c || '*' === $c ) {
			throw new Erreur_Yaml( 'ancres et alias (& *) non pris en charge.', $debut + 1 );
		}
		if ( '!' === $c ) {
			throw new Erreur_Yaml( 'étiquettes (!) non prises en charge.', $debut + 1 );
		}
		if ( '"' === $c || "'" === $c ) {
			return $this->scalaire_guillemets( $texte );
		}
		if ( '[' === $c || '{' === $c ) {
			return $this->flux_lignes( $texte, $seuil );
		}
		if ( '|' === $c || '>' === $c ) {
			return $this->bloc_scalaire( $texte, $seuil );
		}
		// Scalaire simple, éventuellement sur plusieurs lignes (repliées par une espace, une
		// ligne vide = saut de ligne).
		$morceaux = array( $this->sans_commentaire( $texte ) );
		$coupe    = str_contains( $texte, ' #' );
		++$this->i;
		$vides = 0;
		while ( ! $coupe && ! $this->fin() ) {
			$ligne = $this->lignes[ $this->i ];
			if ( '' === trim( $ligne ) ) {
				++$vides;
				++$this->i;
				continue;
			}
			$r = $this->retrait( $this->i );
			$t = substr( $ligne, $r );
			if ( $r <= $seuil || str_starts_with( $t, '#' ) ) {
				break;
			}
			if ( null !== $this->cle( $t ) ) {
				// « : » suivi d'une espace est interdit dans un scalaire simple : c'est une clé
				// mal alignée.
				throw new Erreur_Yaml( 'retrait incohérent (clé inattendue).', $this->i + 1 );
			}
			$morceaux[] = ( $vides ? str_repeat( "\n", $vides ) : ' ' ) . $this->sans_commentaire( $t );
			$coupe      = str_contains( $t, ' #' );
			$vides      = 0;
			++$this->i;
		}
		// Les lignes vides finales n'appartiennent pas au scalaire : les rendre au parent.
		$this->i -= $vides;
		$brut     = implode( '', $morceaux );
		return 1 === count( $morceaux ) ? self::resoudre( $brut ) : $brut;
	}

	/**
	 * Valeur d'un scalaire simple (null, booléen, nombre ou chaîne).
	 *
	 * @param string $brut Texte.
	 * @return mixed
	 */
	public static function resoudre( string $brut ) {
		$t = trim( $brut );
		if ( '' === $t || '~' === $t || 'null' === strtolower( $t ) ) {
			return null;
		}
		if ( 'true' === strtolower( $t ) ) {
			return true;
		}
		if ( 'false' === strtolower( $t ) ) {
			return false;
		}
		if ( preg_match( '/^[-+]?(0|[1-9][0-9]*)$/', $t ) && strlen( ltrim( $t, '+-' ) ) < 19 ) {
			return (int) $t;
		}
		if ( preg_match( '/^[-+]?([0-9]+\.[0-9]*|\.[0-9]+)([eE][-+]?[0-9]+)?$/', $t ) ) {
			return (float) $t;
		}
		return $t;
	}

	/**
	 * Position du guillemet fermant (même type qu'en $depart), null s'il manque.
	 *
	 * Sur un texte qui s'allonge ligne après ligne, $reprise mémorise où l'analyse s'est arrêtée :
	 * l'appel suivant reprend là au lieu de tout relire (temps linéaire, pas quadratique).
	 *
	 * @param string   $texte   Texte.
	 * @param int      $depart  Position du guillemet ouvrant.
	 * @param int|null $reprise Position de reprise (entrée et sortie), null : juste après $depart.
	 */
	private function fin_guillemets( string $texte, int $depart, ?int &$reprise = null ): ?int {
		$q   = $texte[ $depart ];
		$len = strlen( $texte );
		$k   = null !== $reprise ? $reprise : $depart + 1;
		for ( ; $k < $len; $k++ ) {
			$c = $texte[ $k ];
			if ( '"' === $q && '\\' === $c ) {
				++$k;
				continue;
			}
			if ( $c === $q ) {
				if ( "'" === $q && "'" === ( $texte[ $k + 1 ] ?? '' ) ) {
					++$k;
					continue;
				}
				return $k;
			}
		}
		$reprise = $k;
		return null;
	}

	/**
	 * Scalaire entre guillemets, éventuellement sur plusieurs lignes.
	 *
	 * @param string $texte Début.
	 * @return string
	 * @throws Erreur_Yaml Guillemet non fermé ou contenu après.
	 */
	private function scalaire_guillemets( string $texte ): string {
		$debut   = $this->i;
		$acc     = $texte;
		$reprise = null;
		$fin     = $this->fin_guillemets( $acc, 0, $reprise );
		while ( null === $fin ) {
			++$this->i;
			if ( $this->fin() ) {
				throw new Erreur_Yaml( 'guillemet non fermé.', $debut + 1 );
			}
			$acc .= "\n" . $this->lignes[ $this->i ];
			$fin  = $this->fin_guillemets( $acc, 0, $reprise );
		}
		$reste = trim( substr( $acc, $fin + 1 ) );
		if ( '' !== $reste && ! str_starts_with( $reste, '#' ) ) {
			throw new Erreur_Yaml( 'contenu inattendu après une chaîne entre guillemets.', $this->i + 1 );
		}
		++$this->i;
		return $this->decoder_guillemets( substr( $acc, 0, $fin + 1 ) );
	}

	/**
	 * Décode une chaîne entre guillemets (guillemets compris), avec repli des lignes.
	 *
	 * @param string $s Chaîne.
	 * @throws Erreur_Yaml Échappement invalide.
	 */
	private function decoder_guillemets( string $s ): string {
		$q     = $s[0];
		$corps = substr( $s, 1, -1 );
		// Repli : lignes jointes par une espace, lignes vides → sauts de ligne.
		$lignes = explode( "\n", $corps );
		if ( count( $lignes ) > 1 ) {
			$out   = rtrim( $lignes[0], ' ' );
			$vides = 0;
			$n     = count( $lignes );
			for ( $k = 1; $k < $n; $k++ ) {
				$l = trim( $lignes[ $k ], ' ' );
				if ( '' === $l && $k < $n - 1 ) {
					++$vides;
					continue;
				}
				if ( '"' === $q && str_ends_with( $out, '\\' ) && ! str_ends_with( $out, '\\\\' ) ) {
					$out = substr( $out, 0, -1 ) . $l;
				} else {
					$out .= ( $vides ? str_repeat( "\n", $vides ) : ' ' ) . $l;
				}
				$vides = 0;
			}
			$corps = $out;
		}
		if ( "'" === $q ) {
			return str_replace( "''", "'", $corps );
		}
		return (string) preg_replace_callback(
			'/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/su',
			function ( array $m ): string {
				$e = $m[1];
				$c = $e[0];
				if ( 'x' === $c || 'u' === $c || 'U' === $c ) {
					$code = hexdec( substr( $e, 1 ) );
					return (string) mb_chr( (int) $code, 'UTF-8' );
				}
				$table = array(
					'0'  => "\0",
					'a'  => "\x07",
					'b'  => "\x08",
					't'  => "\t",
					"\t" => "\t",
					'n'  => "\n",
					'v'  => "\x0B",
					'f'  => "\x0C",
					'r'  => "\r",
					'e'  => "\x1B",
					' '  => ' ',
					'"'  => '"',
					'/'  => '/',
					'\\' => '\\',
					'N'  => "\u{85}",
					'_'  => "\u{A0}",
					'L'  => "\u{2028}",
					'P'  => "\u{2029}",
				);
				if ( ! isset( $table[ $e ] ) ) {
					throw new Erreur_Yaml( sprintf( 'échappement « \\%s » invalide.', $e ), $this->i + 1 );
				}
				return $table[ $e ];
			},
			$corps
		);
	}

	/**
	 * Scalaire en bloc (| ou >) : l'en-tête est sur la ligne courante.
	 *
	 * @param string $entete En-tête (« |- », « >+2 »…).
	 * @param int    $seuil Retrait du parent.
	 * @throws Erreur_Yaml En-tête invalide.
	 */
	private function bloc_scalaire( string $entete, int $seuil ): string {
		$entete = $this->sans_commentaire( $entete );
		if ( ! preg_match( '/^([|>])([-+]?)([1-9]?)([-+]?)$/', $entete, $m ) ) {
			throw new Erreur_Yaml( 'en-tête de bloc « ' . $entete . ' » invalide.', $this->i + 1 );
		}
		$litteral = '|' === $m[1];
		$chomp    = '' !== $m[2] ? $m[2] : $m[4];
		$retrait  = '' !== $m[3] ? $seuil + (int) $m[3] : null;
		++$this->i;
		$lignes = array();
		while ( ! $this->fin() ) {
			$ligne = $this->lignes[ $this->i ];
			if ( '' === trim( $ligne ) ) {
				$lignes[] = '';
				++$this->i;
				continue;
			}
			$r = strspn( $ligne, ' ' );
			if ( null === $retrait ) {
				if ( $r <= $seuil ) {
					break;
				}
				$retrait = $r;
			}
			if ( $r < $retrait ) {
				break;
			}
			$lignes[] = substr( $ligne, $retrait );
			++$this->i;
		}
		// Lignes vides finales : comptées pour « + », rendues au parent sinon.
		$finales = 0;
		for ( $k = count( $lignes ) - 1; $k >= 0 && '' === $lignes[ $k ]; $k-- ) {
			++$finales;
		}
		$contenu = array_slice( $lignes, 0, count( $lignes ) - $finales );
		if ( $litteral ) {
			$texte = implode( "\n", $contenu );
		} else {
			$texte = '';
			$prec  = null;
			foreach ( $contenu as $l ) {
				if ( null === $prec ) {
					$texte = $l;
				} elseif ( '' === $l ) {
					$texte .= "\n";
				} elseif ( '' === $prec || str_starts_with( $l, ' ' ) || str_starts_with( $prec, ' ' ) ) {
					$texte .= ( '' === $prec ? '' : "\n" ) . $l;
				} else {
					$texte .= ' ' . $l;
				}
				$prec = $l;
			}
		}
		if ( '' === $texte && ! $contenu ) {
			return '+' === $chomp ? str_repeat( "\n", $finales ) : '';
		}
		if ( '-' === $chomp ) {
			return $texte;
		}
		if ( '+' === $chomp ) {
			return $texte . "\n" . str_repeat( "\n", $finales );
		}
		return $texte . "\n";
	}

	/**
	 * Collection en flux, éventuellement sur plusieurs lignes.
	 *
	 * @param string $texte  Début.
	 * @param int    $seuil Retrait du parent.
	 * @return array<mixed>
	 * @throws Erreur_Yaml Erreur.
	 */
	private function flux_lignes( string $texte, int $seuil ): array {
		$debut = $this->i;
		$acc   = $texte;
		$etat  = array();
		while ( ! $this->flux_equilibre( $acc, $etat ) ) {
			++$this->i;
			if ( $this->fin() || ( '' !== trim( $this->lignes[ $this->i ] ) && strspn( $this->lignes[ $this->i ], ' ' ) <= $seuil ) ) {
				throw new Erreur_Yaml( 'crochet ou accolade non fermé.', $debut + 1 );
			}
			$acc .= ' ' . trim( $this->lignes[ $this->i ] );
		}
		++$this->i;
		$pos    = 0;
		$valeur = $this->flux_valeur( $acc, $pos, $debut + 1 );
		$reste  = trim( substr( $acc, $pos ) );
		if ( '' !== $reste && ! str_starts_with( $reste, '#' ) ) {
			throw new Erreur_Yaml( 'contenu inattendu après une liste en ligne.', $debut + 1 );
		}
		return $valeur;
	}

	/**
	 * Crochets et accolades équilibrés (hors guillemets) ? Analyse incrémentale : $etat garde la
	 * position, le niveau et une chaîne entre guillemets encore ouverte d'un appel à l'autre.
	 *
	 * @param string $s    Texte (qui ne fait que s'allonger d'un appel à l'autre).
	 * @param array  $etat État (vide au premier appel).
	 */
	private function flux_equilibre( string $s, array &$etat ): bool {
		$k        = (int) ( $etat['k'] ?? 0 );
		$niveau   = (int) ( $etat['niveau'] ?? 0 );
		$ouvert   = $etat['guillemet'] ?? null;
		$reprise  = $etat['reprise'] ?? null;
		$len      = strlen( $s );
		$resultat = false;
		while ( $k < $len ) {
			if ( null !== $ouvert ) {
				$fin = $this->fin_guillemets( $s, $ouvert, $reprise );
				if ( null === $fin ) {
					$k = $len;
					break;
				}
				$k       = $fin + 1;
				$ouvert  = null;
				$reprise = null;
				continue;
			}
			$c = $s[ $k ];
			if ( '"' === $c || "'" === $c ) {
				$ouvert  = $k;
				$reprise = null;
				continue;
			}
			if ( '#' === $c && ( 0 === $k || ' ' === $s[ $k - 1 ] ) && 0 === $niveau ) {
				break;
			}
			if ( '[' === $c || '{' === $c ) {
				++$niveau;
			} elseif ( ']' === $c || '}' === $c ) {
				--$niveau;
				if ( 0 === $niveau ) {
					$resultat = true;
					break;
				}
			}
			++$k;
		}
		$etat = array(
			'k'         => $k,
			'niveau'    => $niveau,
			'guillemet' => $ouvert,
			'reprise'   => $reprise,
		);
		return $resultat;
	}

	/**
	 * Valeur en flux à la position $pos.
	 *
	 * @param string $s     Texte.
	 * @param int    $pos   Position (avancée).
	 * @param int    $ligne Ligne pour les erreurs.
	 * @return mixed
	 * @throws Erreur_Yaml Erreur.
	 */
	private function flux_valeur( string $s, int &$pos, int $ligne ) {
		if ( ++$this->profondeur > self::PROFONDEUR_MAX ) {
			throw new Erreur_Yaml( 'imbrication trop profonde.', $ligne );
		}
		$this->flux_espaces( $s, $pos );
		$c = $s[ $pos ] ?? '';
		if ( '[' === $c || '{' === $c ) {
			$liste = '[' === $c;
			$fin   = $liste ? ']' : '}';
			$out   = array();
			++$pos;
			while ( true ) {
				$this->flux_espaces( $s, $pos );
				if ( ! isset( $s[ $pos ] ) ) {
					throw new Erreur_Yaml( 'liste en ligne non fermée.', $ligne );
				}
				if ( $s[ $pos ] === $fin ) {
					++$pos;
					break;
				}
				if ( $liste ) {
					$out[] = $this->flux_valeur( $s, $pos, $ligne );
				} else {
					$cle = $this->flux_valeur( $s, $pos, $ligne );
					$this->flux_espaces( $s, $pos );
					$v = null;
					if ( ':' === ( $s[ $pos ] ?? '' ) ) {
						++$pos;
						$this->flux_espaces( $s, $pos );
						$v = in_array( $s[ $pos ] ?? '', array( ',', '}' ), true ) ? null : $this->flux_valeur( $s, $pos, $ligne );
					}
					$out[ is_scalar( $cle ) ? (string) ( is_bool( $cle ) ? ( $cle ? 'true' : 'false' ) : $cle ) : '' ] = $v;
				}
				$this->flux_espaces( $s, $pos );
				if ( ',' === ( $s[ $pos ] ?? '' ) ) {
					++$pos;
				} elseif ( ( $s[ $pos ] ?? '' ) !== $fin ) {
					throw new Erreur_Yaml( 'virgule attendue dans une liste en ligne.', $ligne );
				}
			}
			--$this->profondeur;
			return $out;
		}
		if ( '"' === $c || "'" === $c ) {
			$f = $this->fin_guillemets( $s, $pos );
			if ( null === $f ) {
				throw new Erreur_Yaml( 'guillemet non fermé.', $ligne );
			}
			$v   = $this->decoder_guillemets( substr( $s, $pos, $f - $pos + 1 ) );
			$pos = $f + 1;
			--$this->profondeur;
			return $v;
		}
		if ( '&' === $c || '*' === $c || '!' === $c ) {
			throw new Erreur_Yaml( 'ancres, alias et étiquettes non pris en charge.', $ligne );
		}
		$debut = $pos;
		$len   = strlen( $s );
		while ( $pos < $len ) {
			$ch = $s[ $pos ];
			if ( ',' === $ch || ']' === $ch || '}' === $ch ) {
				break;
			}
			if ( ':' === $ch && ( $pos + 1 >= $len || in_array( $s[ $pos + 1 ], array( ' ', ',', ']', '}' ), true ) ) ) {
				break;
			}
			++$pos;
		}
		--$this->profondeur;
		return self::resoudre( substr( $s, $debut, $pos - $debut ) );
	}

	/**
	 * Saute les espaces.
	 *
	 * @param string $s   Texte.
	 * @param int    $pos Position.
	 */
	private function flux_espaces( string $s, int &$pos ): void {
		$len = strlen( $s );
		while ( $pos < $len && ( ' ' === $s[ $pos ] || "\t" === $s[ $pos ] ) ) {
			++$pos;
		}
	}
}
