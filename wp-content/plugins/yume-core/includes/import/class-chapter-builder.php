<?php
/**
 * Assemblage des chapitres, commun aux convertisseurs DOCX et EPUB.
 *
 * Reçoit les éléments dans l'ordre du document (ouverture de chapitre, paragraphes,
 * séparateurs, images, listes, notes…) et produit les chapitres du Result :
 * - le contenu placé avant le premier chapitre n'est pas un chapitre (images → galerie
 *   du tome, texte ignoré et signalé) ; sans aucun titre de chapitre, tout le document
 *   devient un chapitre unique ;
 * - une courte section d'avant le premier vrai chapitre dont le titre n'est pas un titre de
 *   chapitre (« Crédits », titre du livre…) est traitée comme page liminaire ;
 * - un titre sans numéro (« La Crête ») reçoit le numéro du chapitre précédent + 1 ;
 * - paragraphes vides compactés : un vide isolé est ignoré, plusieurs vides consécutifs
 *   au milieu d'un chapitre deviennent un séparateur de scène ;
 * - séparateurs dédoublonnés, jamais en tête ni en fin de chapitre ;
 * - éléments de liste consécutifs regroupés ; notes numérotées par chapitre ;
 * - nombre de mots, statistiques et avertissements (numéro en double, manquant, chapitre vide).
 *
 * Découpage manuel (docs/06 §9) :
 * - chaque élément de contenu reçoit une ancre stable « e{rang}-{empreinte} » (rang dans le
 *   document, 6 chiffres hexadécimaux du CRC32 de son texte normalisé) : le même fichier donne
 *   les mêmes ancres d'une conversion à l'autre ;
 * - les débuts de chapitre possibles (titres, illustrations, lignes courtes, centrées ou en gras,
 *   paragraphe qui suit un saut de page ou un séparateur, début du document) sont relevés dans
 *   Result::$candidats, avec les découpages rapides (Result::$decoupages) ;
 * - un découpage (option « plan » des convertisseurs) devient la SEULE source des débuts de
 *   chapitre : les titres détectés deviennent des intertitres, un titre placé exactement sur un
 *   début manuel sert de titre par défaut ; aucune page liminaire n'est écartée ; le texte
 *   d'ouverture peut être conservé (garder_avant) ;
 * - sans découpage, un paragraphe marqueur « [chapitre] Titre », « [bonus] Titre », « [prologue] »…
 *   force un début de chapitre (il n'est pas publié). Les requêtes des convertisseurs
 *   (en_chapitre(), attend_sous_titre()…) répondent toujours comme la détection automatique :
 *   les éléments, donc les ancres, sont les mêmes avec ou sans découpage.
 *
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Constructeur de chapitres.
 */
final class Chapter_Builder {

	/**
	 * Volume maximal du texte converti gardé en mémoire (octets de balisage, plus un coût fixe
	 * par élément) : un tome réel en produit quelques Mo. Au-delà (document démesuré, bombe
	 * de compression), la conversion s'arrête proprement au lieu d'épuiser la mémoire de PHP.
	 */
	public const VOLUME_MAX = 32 * 1024 * 1024;

	/** Nombre maximal d'éléments (paragraphes, titres, notes…) : un tome réel en compte quelques milliers. */
	public const ELEMENTS_MAX = 150000;

	/** Coût mémoire fixe compté pour chaque élément (tableaux PHP). */
	private const COUT_ELEMENT = 160;

	/**
	 * Volume maximal pour ce document (octets).
	 *
	 * @var int
	 */
	private int $volume_max;

	/**
	 * Volume déjà produit (octets).
	 *
	 * @var int
	 */
	private int $volume = 0;

	/**
	 * Nombre d'éléments déjà produits.
	 *
	 * @var int
	 */
	private int $elements = 0;

	/** Compteurs statistiques d'un chapitre. */
	public const COMPTEURS = array( 'paragraphes', 'dialogues', 'pensees', 'centres', 'separateurs', 'images', 'notes', 'listes', 'titres' );

	/** Côté maximal (pixels) d'un ornement : une image plus petite n'est pas une illustration. */
	public const ORNEMENT_MAX = 200;

	/** En dessous de ce nombre de mots, une section liminaire sans titre de chapitre n'est pas un chapitre. */
	public const MOTS_LIMINAIRE = 400;

	/** Ancre d'un élément : « e » + rang dans le document, tiret, empreinte du texte (e12-3fa9c1). */
	public const MOTIF_ANCRE = '/^e[1-9][0-9]{0,6}-[0-9a-f]{6}\z/';

	/** Nombre maximal de débuts de chapitre d'un découpage manuel. */
	public const PLAN_MAX = 3000;

	/** Longueur maximale d'un titre de chapitre saisi (caractères). */
	public const TITRE_MAX = 200;

	/** Ligne courte (début de chapitre possible) : au plus ce nombre de mots. */
	public const MOTS_LIGNE_COURTE = 12;

	/**
	 * Résultat alimenté.
	 *
	 * @var Result
	 */
	private Result $resultat;

	/**
	 * Chapitre en cours (null avant le premier).
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $courant = null;

	/**
	 * Contenu placé avant le premier chapitre.
	 *
	 * @var array<string,mixed>
	 */
	private array $avant;

	/**
	 * Chapitres terminés, en attente des contrôles finaux.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $termines = array();

	/**
	 * Nombre de paragraphes vides consécutifs en attente.
	 *
	 * @var int
	 */
	private int $vides = 0;

	/**
	 * Liste en cours de constitution : ['ordonnee' => bool, 'items' => string[]].
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $liste = null;

	/**
	 * Rang du dernier élément de contenu (ancres).
	 *
	 * @var int
	 */
	private int $rang = 0;

	/**
	 * Indices du prochain élément donnés par le convertisseur (saut_page, gras, centre) ou
	 * relevés ici (separateur).
	 *
	 * @var array<string,bool>
	 */
	private array $indices = array();

	/**
	 * Type de l'élément précédent (image : illustrations consécutives).
	 *
	 * @var string
	 */
	private string $type_precedent = '';

	/**
	 * Découpage manuel : ['debuts' => ancre => [nature, titre, numero], 'garder_avant' => bool],
	 * ou null (détection automatique).
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $plan = null;

	/**
	 * Ancres du découpage rencontrées dans le document.
	 *
	 * @var array<string,bool>
	 */
	private array $trouves = array();

	/**
	 * Aucun début manuel encore appliqué (le texte d'ouverture peut rejoindre le premier).
	 *
	 * @var bool
	 */
	private bool $premiere_coupure = true;

	/**
	 * Découpage manuel : que devient le dernier titre détecté ? « coupure » (titre du chapitre
	 * ouvert), « bloc » (intertitre), « invisible » (titre de table des matières, absent du texte).
	 *
	 * @var string
	 */
	private string $dernier_titre = '';

	/**
	 * État qu'aurait la détection automatique (réponses aux convertisseurs) : chapitre ouvert,
	 * titre reconnu, sous-titre attendu, chapitre de marqueur encore vide.
	 *
	 * @var array<string,bool>
	 */
	private array $ombre = array(
		'chapitre'      => false,
		'reconnu'       => false,
		'attend'        => false,
		'marqueur_vide' => false,
	);

	/**
	 * Découpage manuel : notes dont l'appel n'est pas encore placé (numérotées dans le chapitre
	 * qui reçoit le paragraphe, après une éventuelle coupure).
	 *
	 * @var string[]
	 */
	private array $notes_attente = array();

	/**
	 * Nombre de notes reçues avec un découpage manuel (rang de la prochaine).
	 *
	 * @var int
	 */
	private int $notes_vues = 0;

	/**
	 * Débuts de chapitre possibles, dans l'ordre du document.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $candidats = array();

	/**
	 * Constructeur.
	 *
	 * @param Result                   $resultat   Résultat à remplir.
	 * @param int                      $volume_max Volume maximal du texte converti (octets, défaut VOLUME_MAX).
	 * @param array<string,mixed>|null $plan       Découpage manuel (voir normaliser_plan()), ou null.
	 */
	public function __construct( Result $resultat, int $volume_max = self::VOLUME_MAX, ?array $plan = null ) {
		$this->resultat   = $resultat;
		$this->volume_max = $volume_max > 0 ? $volume_max : self::VOLUME_MAX;
		$this->avant      = $this->nouveau( 'chapitre', null, '', '', false );
		$this->plan       = self::normaliser_plan( $plan );
	}

	/**
	 * Normalise un découpage manuel (options des convertisseurs). Les entrées invalides sont
	 * ignorées ici : le contrôle strict, avec message d'erreur, est fait à la réception
	 * (Publication\Service::plan()).
	 *
	 * @param mixed $plan ['debuts' => [['ancre', 'nature', 'titre', 'numero'?], …] (ou ancre =>
	 *                    [nature, titre, numero]), 'garder_avant' => bool].
	 * @return array{debuts:array<string,array{nature:string,titre:string,numero:?float}>,garder_avant:bool}|null
	 */
	public static function normaliser_plan( $plan ): ?array {
		if ( ! is_array( $plan ) || ! isset( $plan['debuts'] ) || ! is_array( $plan['debuts'] ) ) {
			return null;
		}
		$debuts = array();
		foreach ( $plan['debuts'] as $cle => $entree ) {
			if ( ! is_array( $entree ) || count( $debuts ) >= self::PLAN_MAX ) {
				continue;
			}
			$ancre  = is_string( $cle ) ? $cle : (string) ( $entree['ancre'] ?? '' );
			$nature = (string) ( $entree['nature'] ?? 'chapitre' );
			if ( ! preg_match( self::MOTIF_ANCRE, $ancre ) || ! isset( Texte::LIBELLES[ $nature ] ) ) {
				continue;
			}
			$titre            = is_scalar( $entree['titre'] ?? '' ) ? Texte::espaces( Texte::texte( (string) ( $entree['titre'] ?? '' ) ) ) : '';
			$numero           = $entree['numero'] ?? null;
			$debuts[ $ancre ] = array(
				'nature' => $nature,
				'titre'  => mb_substr( $titre, 0, self::TITRE_MAX, 'UTF-8' ),
				'numero' => is_numeric( $numero ) && (float) $numero >= 0 ? round( (float) $numero, 3 ) : null,
			);
		}
		if ( ! $debuts ) {
			return null;
		}
		return array(
			'debuts'       => $debuts,
			'garder_avant' => ! empty( $plan['garder_avant'] ),
		);
	}

	/**
	 * Numéros et libellés des chapitres d'un découpage manuel, dans l'ordre : chapitres numérotés
	 * à la suite (un numéro saisi fixe la suite), prologue 0, chapitres spéciaux de même nature
	 * numérotés 1, 2… quand il y en a plusieurs (« Bonus 1 », « Bonus 2 »).
	 *
	 * @param array<int,array{nature:string,numero:?float}> $chapitres Nature et numéro saisi (ou null).
	 * @return array<int,array{numero:?float,titre:string}>
	 */
	public static function numeroter( array $chapitres ): array {
		$par_nature = array_count_values( array_map( static fn( $c ) => (string) $c['nature'], $chapitres ) );
		$rangs      = array();
		$dernier    = 0.0;
		$resultat   = array();
		foreach ( $chapitres as $i => $chapitre ) {
			$nature = (string) $chapitre['nature'];
			$numero = isset( $chapitre['numero'] ) ? (float) $chapitre['numero'] : null;
			if ( 'chapitre' === $nature ) {
				$numero         = null !== $numero ? $numero : floor( $dernier ) + 1;
				$dernier        = $numero;
				$resultat[ $i ] = array(
					'numero' => $numero,
					'titre'  => 'Chapitre ' . Texte::numero_fr( $numero ),
				);
				continue;
			}
			$rangs[ $nature ] = ( $rangs[ $nature ] ?? 0 ) + 1;
			if ( null === $numero && 'prologue' === $nature ) {
				$numero = (float) ( $rangs[ $nature ] - 1 );
			} elseif ( null === $numero && $par_nature[ $nature ] > 1 ) {
				$numero = (float) $rangs[ $nature ];
			}
			$titre = Texte::LIBELLES[ $nature ] ?? Texte::LIBELLES['chapitre'];
			if ( null !== $numero && in_array( $nature, array( 'interlude', 'bonus' ), true ) ) {
				$titre .= ' ' . Texte::numero_fr( $numero );
			}
			$resultat[ $i ] = array(
				'numero' => $numero,
				'titre'  => $titre,
			);
		}
		return $resultat;
	}

	/**
	 * Indice sur le prochain élément de contenu : « saut_page » (il commence une page),
	 * « gras » (paragraphe entièrement en gras), « centre » (paragraphe centré).
	 *
	 * @param string $nom Indice.
	 */
	public function indice( string $nom ): void {
		if ( in_array( $nom, array( 'saut_page', 'gras', 'centre' ), true ) ) {
			$this->indices[ $nom ] = true;
		}
	}

	/**
	 * Nouveau chapitre vide.
	 *
	 * @param string     $nature     Nature.
	 * @param float|null $numero     Numéro.
	 * @param string     $titre      Titre.
	 * @param string     $sous_titre Sous-titre.
	 * @param bool       $reconnu    Titre de chapitre reconnu (et non déduit).
	 * @return array<string,mixed>
	 */
	private function nouveau( string $nature, ?float $numero, string $titre, string $sous_titre, bool $reconnu ): array {
		return array(
			'numero'            => $numero,
			'nature'            => $nature,
			'titre'             => $titre,
			'sous_titre'        => $sous_titre,
			'reconnu'           => $reconnu,
			'titre_brut'        => '',
			'blocs'             => array(),
			'types'             => array(),
			'notes'             => array(),
			'images'            => array(),
			'stats'             => array_fill_keys( self::COMPTEURS, 0 ),
			'attend_sous_titre' => '' === $sous_titre,
			// Début manuel (découpage ou marqueur) : ancre de l'élément qui ouvre le chapitre,
			// titre saisi.
			'manuel'            => false,
			'ancre'             => '',
			'titre_plan'        => '',
		);
	}

	/**
	 * Nom du chapitre courant pour les messages (« Chapitre 11 », « avant le premier chapitre »).
	 */
	public function nom_courant(): string {
		if ( null === $this->courant ) {
			return 'avant le premier chapitre';
		}
		return '' !== (string) $this->courant['titre'] ? (string) $this->courant['titre'] : '« ' . $this->courant['titre_brut'] . ' »';
	}

	/**
	 * Un chapitre est-il ouvert ? (Réponse de la détection automatique, même avec un
	 * découpage manuel : le convertisseur émet ainsi les mêmes éléments.)
	 */
	public function en_chapitre(): bool {
		return $this->ombre['chapitre'];
	}

	/**
	 * Un chapitre au titre reconnu a-t-il déjà été ouvert ?
	 */
	public function chapitre_reconnu_vu(): bool {
		foreach ( $this->termines as $chapitre ) {
			if ( $chapitre['reconnu'] ) {
				return true;
			}
		}
		return null !== $this->courant && $this->courant['reconnu'];
	}

	/**
	 * Le chapitre vient-il d'être ouvert, sans contenu ni sous-titre ? (Détection automatique.)
	 */
	public function attend_sous_titre(): bool {
		return $this->ombre['chapitre'] && $this->ombre['attend'];
	}

	/**
	 * Le chapitre courant a-t-il été ouvert par un titre de chapitre reconnu ? (Détection
	 * automatique.)
	 */
	public function titre_reconnu(): bool {
		return $this->ombre['chapitre'] && $this->ombre['reconnu'];
	}

	/**
	 * Définit le sous-titre du chapitre courant. Avec un découpage manuel : sous-titre du
	 * chapitre ouvert sur ce titre (s'il n'en a pas déjà un), sinon intertitre.
	 *
	 * @param string $texte Texte brut.
	 */
	public function sous_titre( string $texte ): void {
		if ( $this->ombre['chapitre'] ) {
			$this->ombre['attend'] = false;
		}
		$texte = Texte::espaces( $texte );
		if ( null !== $this->plan ) {
			if ( '' === $texte ) {
				return;
			}
			if ( 'coupure' === $this->dernier_titre && null !== $this->courant ) {
				$actuel = (string) $this->courant['sous_titre'];
				if ( self::meme_texte( $actuel, $texte ) ) {
					return;
				}
				if ( '' === $actuel ) {
					$this->courant['sous_titre'] = $texte;
					return;
				}
			}
			$this->compter( 'titres' );
			$this->ajouter( Blocks::titre( Blocks::texte( $texte ), 'bloc' === $this->dernier_titre ? 3 : 2 ), 'titre' );
			return;
		}
		if ( null === $this->courant ) {
			return;
		}
		$this->courant['sous_titre']        = $texte;
		$this->courant['attend_sous_titre'] = false;
	}

	/**
	 * Deux textes sont-ils identiques (casse, espaces et ponctuation finale ignorées) ?
	 *
	 * @param string $a Texte.
	 * @param string $b Texte.
	 */
	private static function meme_texte( string $a, string $b ): bool {
		$normaliser = static fn( string $t ): string => (string) preg_replace( '/[\s.:!?…]+$/u', '', mb_strtolower( Texte::espaces( $t ), 'UTF-8' ) );
		return '' !== $normaliser( $a ) && $normaliser( $a ) === $normaliser( $b );
	}

	/**
	 * Texte brut d'un contenu, sans les appels de note (leur numéro dépend du découpage).
	 *
	 * @param string $html HTML en ligne.
	 */
	private static function texte_sans_appels( string $html ): string {
		return Texte::espaces( Texte::texte( (string) preg_replace( '#<sup class="yn-note"[^>]*>.*?</sup>#s', '', $html ) ) );
	}

	/**
	 * Enregistre un élément de contenu : ancre, début de chapitre possible (candidat).
	 *
	 * @param string              $type  titre, intertitre, paragraphe, marqueur, image, liste ou citation.
	 * @param string              $texte Texte brut (clé de l'image pour une illustration).
	 * @param array<string,mixed> $infos analyse (titre), marqueur, role et centre (paragraphe).
	 * @return string Ancre de l'élément.
	 */
	private function element( string $type, string $texte, array $infos = array() ): string {
		++$this->rang;
		$texte   = Texte::espaces( $texte );
		$ancre   = 'e' . $this->rang . '-' . substr( hash( 'crc32b', 'image' === $type ? 'image:' . $texte : mb_strtolower( $texte, 'UTF-8' ) ), 0, 6 );
		$indices = $this->indices;
		// Un élément qui suit plusieurs lignes vides (séparateur implicite) ou un séparateur.
		if ( $this->vides >= 2 && $this->rang > 1 ) {
			$indices['separateur'] = true;
		}
		$this->indices = array();
		if ( null === $this->courant && '' === $this->avant['ancre'] ) {
			$this->avant['ancre'] = $ancre;
		}
		$precedent            = $this->type_precedent;
		$this->type_precedent = $type;

		// Ornement (petite image répétée : fleuron, séparateur dessiné) : jamais un début de
		// chapitre, et il n'interrompt pas une suite d'illustrations.
		if ( 'image' === $type && $this->est_ornement( $texte ) ) {
			$this->type_precedent = $precedent;
			return $ancre;
		}

		$paragraphe = 'paragraphe' === $type;
		$mots       = 'image' === $type ? 0 : Texte::compter_mots( Blocks::texte( $texte ) );
		$courte     = $paragraphe && 'dialogue' !== ( $infos['role'] ?? '' ) && $mots > 0 && $mots <= self::MOTS_LIGNE_COURTE;
		$raisons    = array();
		$conditions = array(
			'marqueur'     => 'marqueur' === $type,
			'titre'        => in_array( $type, array( 'titre', 'intertitre' ), true ),
			'image'        => 'image' === $type,
			'saut_page'    => ! empty( $indices['saut_page'] ),
			'separateur'   => ! empty( $indices['separateur'] ),
			'gras'         => $paragraphe && ! empty( $indices['gras'] ),
			'centre'       => $paragraphe && ( ! empty( $infos['centre'] ) || ! empty( $indices['centre'] ) ),
			'ligne_courte' => $courte,
			'debut'        => 1 === $this->rang,
		);
		foreach ( $conditions as $raison => $vrai ) {
			if ( $vrai ) {
				$raisons[] = $raison;
			}
		}
		if ( ! $raisons ) {
			return $ancre;
		}
		if ( count( $this->candidats ) >= Result::CANDIDATS_MAX ) {
			$this->resultat->stats['candidats_tronques'] = true;
			return $ancre;
		}

		// Nature et titre proposés.
		$nature = 'chapitre';
		$titre  = '';
		if ( 'marqueur' === $type ) {
			$nature = (string) $infos['marqueur']['nature'];
			$titre  = (string) $infos['marqueur']['titre'];
		} elseif ( 'titre' === $type || 'intertitre' === $type || $courte ) {
			$analyse = $infos['analyse'] ?? Texte::analyser_titre( $texte );
			if ( 'inconnu' !== $analyse['motif'] ) {
				$nature = (string) $analyse['nature'];
				$titre  = (string) $analyse['sous_titre'];
			} elseif ( ! $courte || array_intersect( $raisons, array( 'gras', 'centre', 'saut_page', 'separateur' ) ) ) {
				$titre = $texte;
			}
		}
		if ( 'image' === $type ) {
			$image   = $this->resultat->images[ $texte ] ?? array();
			$legende = '' !== (string) ( $image['alt'] ?? '' ) ? (string) $image['alt'] : (string) ( $image['nom'] ?? '' );
			$extrait = 'Illustration' . ( '' !== $legende ? ' : ' . $legende : '' );
		} else {
			$extrait = $texte;
		}
		$this->candidats[] = array(
			'ancre'   => $ancre,
			'rang'    => $this->rang,
			'type'    => $raisons[0],
			'raisons' => $raisons,
			'extrait' => mb_strlen( $extrait, 'UTF-8' ) > 80 ? rtrim( mb_substr( $extrait, 0, 79, 'UTF-8' ) ) . '…' : $extrait,
			'nature'  => $nature,
			'titre'   => mb_substr( $titre, 0, self::TITRE_MAX, 'UTF-8' ),
			'auto'    => false,
			'avant'   => false,
			// Première d'une suite d'illustrations (découpage « à chaque illustration »).
			'groupe'  => 'image' === $type && 'image' !== $precedent,
		);
		return $ancre;
	}

	/**
	 * Image trop petite pour être une illustration (ornement, fleuron) : au plus
	 * ORNEMENT_MAX pixels de côté ; une image de dimensions inconnues n'en est pas un.
	 *
	 * @param string $cle Clé de l'image.
	 */
	private function est_ornement( string $cle ): bool {
		$image   = $this->resultat->images[ $cle ] ?? array();
		$largeur = (int) ( $image['largeur'] ?? 0 );
		$hauteur = (int) ( $image['hauteur'] ?? 0 );
		return $largeur > 0 && $hauteur > 0 && max( $largeur, $hauteur ) <= self::ORNEMENT_MAX;
	}

	/**
	 * Le découpage manuel demande-t-il un début de chapitre à cette ancre ?
	 *
	 * @param string $ancre Ancre.
	 */
	private function au_plan( string $ancre ): bool {
		if ( null === $this->plan || ! isset( $this->plan['debuts'][ $ancre ] ) ) {
			return false;
		}
		$this->trouves[ $ancre ] = true;
		return true;
	}

	/**
	 * Début de chapitre manuel (découpage) avant l'élément d'ancre donnée.
	 *
	 * @param string $ancre  Ancre.
	 * @param string $defaut Titre par défaut (titre détecté à cet endroit).
	 * @param string $html   HTML de l'élément (ses notes rejoignent le nouveau chapitre).
	 */
	private function couper( string $ancre, string $defaut, string $html = '' ): void {
		$entree = $this->plan['debuts'][ $ancre ];
		$this->consommer( 1024 );
		$this->notes_orphelines( $html );
		$this->fermer();
		$titre = '' !== $entree['titre'] ? $entree['titre'] : Texte::espaces( $defaut );
		if ( $this->premiere_coupure && $this->plan['garder_avant'] && $this->a_contenu( $this->avant ) ) {
			// Texte d'ouverture conservé : il forme le début du premier chapitre.
			$chapitre                      = $this->avant;
			$this->avant                   = $this->nouveau( 'chapitre', null, '', '', false );
			$chapitre['nature']            = $entree['nature'];
			$chapitre['numero']            = $entree['numero'];
			$chapitre['sous_titre']        = $titre;
			$chapitre['reconnu']           = true;
			$chapitre['attend_sous_titre'] = false;
		} else {
			$chapitre = $this->nouveau( $entree['nature'], $entree['numero'], '', $titre, true );
		}
		$chapitre['manuel']     = true;
		$chapitre['ancre']      = $ancre;
		$chapitre['titre_plan'] = $entree['titre'];
		$chapitre['titre_brut'] = '' !== $titre ? $titre : ( Texte::LIBELLES[ $entree['nature'] ] ?? '' );
		$this->premiere_coupure = false;
		$this->courant          = $chapitre;
		$this->vides            = 0;
	}

	/**
	 * Détection automatique : un élément de contenu a été ajouté.
	 */
	private function ombre_contenu(): void {
		$this->ombre['attend']        = false;
		$this->ombre['marqueur_vide'] = false;
		$this->dernier_titre          = '';
	}

	/**
	 * Compte le volume produit ; arrête la conversion au-delà des limites.
	 *
	 * @param int $octets Taille du balisage ajouté.
	 * @throws Import_Exception Document trop volumineux.
	 */
	private function consommer( int $octets ): void {
		$this->volume += $octets + self::COUT_ELEMENT;
		++$this->elements;
		if ( $this->volume > $this->volume_max || $this->elements > self::ELEMENTS_MAX ) {
			throw new Import_Exception(
				sprintf(
					'Document refusé : son texte converti dépasse la taille maximale acceptée (%d Mo ou %s éléments). Découpez-le en plusieurs fichiers.',
					(int) ceil( $this->volume_max / ( 1024 * 1024 ) ),
					number_format( self::ELEMENTS_MAX, 0, ',', ' ' )
				),
				'document_trop_grand'
			);
		}
	}

	/**
	 * Ouvre un chapitre à partir de l'analyse de son titre (Texte::analyser_titre()). Avec un
	 * découpage manuel, le titre ouvre un chapitre seulement s'il porte un début manuel (il en
	 * donne alors le titre par défaut) ; sinon il devient un intertitre.
	 *
	 * @param array<string,mixed> $analyse    Analyse du titre.
	 * @param string              $brut       Texte brut du titre (pour les avertissements).
	 * @param bool                $dans_texte Le titre figure dans le texte (faux : libellé de la
	 *                                        table des matières d'un EPUB sans titres).
	 */
	public function ouvrir( array $analyse, string $brut, bool $dans_texte = true ): void {
		$this->consommer( 1024 + strlen( $brut ) ); // Chapitre : tableau, statistiques, titre.
		$ancre = $this->element( 'titre', $brut, array( 'analyse' => $analyse ) );
		if ( null !== $this->plan ) {
			$this->ombre_ouvrir( $analyse, $brut );
			if ( $this->au_plan( $ancre ) ) {
				$this->couper( $ancre, 'inconnu' !== $analyse['motif'] ? (string) $analyse['sous_titre'] : $brut );
				$this->dernier_titre = 'coupure';
				return;
			}
			if ( $dans_texte && '' !== Texte::espaces( $brut ) ) {
				$this->compter( 'titres' );
				$this->ajouter( Blocks::titre( Blocks::texte( Texte::espaces( $brut ) ), 2 ), 'titre' );
				$this->dernier_titre = 'bloc';
			} else {
				$this->dernier_titre = 'invisible';
			}
			return;
		}
		if ( $this->ombre['marqueur_vide'] && null !== $this->courant && $this->courant['manuel'] && ! $this->courant['blocs'] && null === $this->liste ) {
			// Titre juste après un marqueur : il complète le chapitre du marqueur.
			if ( '' === (string) $this->courant['sous_titre'] ) {
				$this->courant['sous_titre'] = 'inconnu' !== $analyse['motif'] ? (string) $analyse['sous_titre'] : Texte::espaces( $brut );
			}
			if ( 'chapitre' === $this->courant['nature'] && 'chapitre' === $analyse['nature'] && null !== $analyse['numero'] ) {
				$this->courant['numero'] = (float) $analyse['numero'];
			}
			$this->courant['attend_sous_titre'] = '' === (string) $this->courant['sous_titre'];
			$this->ombre['attend']              = $this->courant['attend_sous_titre'];
			$this->ombre['marqueur_vide']       = false;
			return;
		}
		$this->ombre_ouvrir( $analyse, $brut );
		$this->fermer();
		$reconnu = 'inconnu' !== $analyse['motif'];
		if ( $reconnu ) {
			$titre        = (string) $analyse['titre'];
			$numero       = $analyse['numero'];
			$corrections  = (array) $analyse['corrections'];
			$brut_espaces = str_replace( array( Texte::INSECABLE, Texte::FINE, "\t" ), ' ', $brut );
			if ( str_starts_with( $brut_espaces, ' ' ) ) {
				$this->resultat->avertir( sprintf( '%s : titre précédé d’une espace — corrigé automatiquement.', $titre ) );
			} elseif ( str_contains( trim( $brut_espaces ), '  ' ) ) {
				$this->resultat->avertir( sprintf( '%s : espaces en trop dans le titre — corrigé automatiquement.', $titre ) );
			}
			if ( in_array( 'espace_manquante', $corrections, true ) ) {
				$this->resultat->avertir( sprintf( '%1$s : titre « %2$s » sans espace — corrigé automatiquement.', $titre, Texte::espaces( $brut ) ) );
			}
			if ( in_array( 'libelle', $corrections, true ) ) {
				$this->resultat->avertir( sprintf( 'Titre « %1$s » renommé « %2$s ».', Texte::espaces( $brut ), $titre ) );
			}
			if ( in_array( 'suffixe', $corrections, true ) && null !== $numero ) {
				$this->resultat->avertir( sprintf( '%1$s : numéroté %2$s pour l’ordre de lecture.', $titre, Texte::numero_fr( (float) $numero ) ) );
			}
			$this->courant = $this->nouveau( (string) $analyse['nature'], null === $numero ? null : (float) $numero, $titre, (string) $analyse['sous_titre'], true );
		} else {
			// Titre sans numéro : numéro et libellé fixés à la fin (terminer()).
			$this->courant = $this->nouveau( 'chapitre', null, '', '', false );
		}
		$this->courant['titre_brut'] = Texte::espaces( $brut );
		$this->courant['ancre']      = $ancre;
		$this->vides                 = 0;
	}

	/**
	 * Détection automatique : un titre ouvre un chapitre.
	 *
	 * @param array<string,mixed> $analyse Analyse du titre.
	 * @param string              $brut    Texte brut du titre.
	 */
	private function ombre_ouvrir( array $analyse, string $brut ): void {
		if ( $this->ombre['marqueur_vide'] ) {
			// Chapitre du marqueur complété par ce titre (voir ouvrir()).
			$this->ombre['attend']        = $this->ombre['attend'] && ( 'inconnu' !== $analyse['motif'] ? '' === (string) $analyse['sous_titre'] : '' === Texte::espaces( $brut ) );
			$this->ombre['marqueur_vide'] = false;
			return;
		}
		$this->ombre['chapitre'] = true;
		$this->ombre['reconnu']  = 'inconnu' !== $analyse['motif'];
		$this->ombre['attend']   = '' === (string) ( $this->ombre['reconnu'] ? $analyse['sous_titre'] : '' );
	}

	/**
	 * Paragraphe marqueur « [bonus] Titre » sans découpage manuel : début de chapitre forcé.
	 *
	 * @param string                            $ancre    Ancre du marqueur.
	 * @param array{nature:string,titre:string} $marqueur Nature et titre.
	 */
	private function ouvrir_marqueur( string $ancre, array $marqueur ): void {
		$this->consommer( 1024 );
		$this->fermer();
		$this->courant               = $this->nouveau( $marqueur['nature'], 'prologue' === $marqueur['nature'] ? 0.0 : null, '', $marqueur['titre'], true );
		$this->courant['manuel']     = true;
		$this->courant['ancre']      = $ancre;
		$this->courant['titre_plan'] = $marqueur['titre'];
		$this->courant['titre_brut'] = '[' . $marqueur['nature'] . ']' . ( '' !== $marqueur['titre'] ? ' ' . $marqueur['titre'] : '' );
		$this->vides                 = 0;
	}

	/**
	 * Détection automatique : un marqueur ouvre un chapitre.
	 *
	 * @param array{nature:string,titre:string} $marqueur Nature et titre.
	 */
	private function ombre_marqueur( array $marqueur ): void {
		$this->ombre['chapitre']      = true;
		$this->ombre['reconnu']       = true;
		$this->ombre['attend']        = '' === $marqueur['titre'];
		$this->ombre['marqueur_vide'] = true;
		$this->dernier_titre          = '';
	}


	/**
	 * Ajoute un bloc au chapitre courant (ou au contenu d'avant le premier chapitre).
	 *
	 * @param string $bloc Balisage.
	 * @param string $type Type (paragraphe, separateur, image, liste, titre, citation).
	 */
	private function ajouter( string $bloc, string $type ): void {
		if ( 'liste' !== $type ) {
			// Une liste est comptée élément par élément (element_liste()).
			$this->consommer( strlen( $bloc ) );
			$this->vider_liste();
		}
		$cible = null !== $this->courant ? 'courant' : 'avant';
		if ( 'separateur' !== $type && $this->vides >= 2 && $this->a_contenu( $this->{$cible} ) ) {
			$this->pousser_separateur( $cible );
		}
		$this->vides                         = 0;
		$this->{$cible}['blocs'][]           = $bloc;
		$this->{$cible}['types'][]           = $type;
		$this->{$cible}['attend_sous_titre'] = false;
	}

	/**
	 * Le chapitre a-t-il du contenu (autre que des séparateurs) ?
	 *
	 * @param array<string,mixed> $chapitre Chapitre.
	 */
	private function a_contenu( array $chapitre ): bool {
		return count( array_diff( $chapitre['types'], array( 'separateur' ) ) ) > 0;
	}

	/**
	 * Ajoute un séparateur s'il n'en suit pas déjà un.
	 *
	 * @param string $cible 'courant' ou 'avant'.
	 */
	private function pousser_separateur( string $cible ): void {
		$types = $this->{$cible}['types'];
		if ( ! $types || 'separateur' === end( $types ) ) {
			return;
		}
		$this->{$cible}['blocs'][] = Blocks::separateur();
		$this->{$cible}['types'][] = 'separateur';
	}

	/**
	 * Termine la liste en cours.
	 */
	private function vider_liste(): void {
		if ( null === $this->liste ) {
			return;
		}
		$liste       = $this->liste;
		$this->liste = null;
		// Les vides rencontrés depuis le début de la liste s'appliquent au bloc suivant.
		$vides       = $this->vides;
		$this->vides = 0;
		$this->ajouter( Blocks::liste( $liste['items'], (bool) $liste['ordonnee'] ), 'liste' );
		$this->vides = $vides;
	}

	/**
	 * Compte une statistique du chapitre courant (ou du contenu d'avant).
	 *
	 * @param string $compteur Compteur.
	 * @param int    $n        Incrément.
	 */
	private function compter( string $compteur, int $n = 1 ): void {
		if ( null !== $this->courant ) {
			$this->courant['stats'][ $compteur ] += $n;
		} else {
			$this->avant['stats'][ $compteur ] += $n;
		}
	}

	/**
	 * Paragraphe.
	 *
	 * @param string $html   HTML en ligne assaini (non vide).
	 * @param string $role   '' (narration), 'dialogue' ou 'pensee'.
	 * @param bool   $centre Paragraphe centré.
	 */
	public function paragraphe( string $html, string $role = '', bool $centre = false ): void {
		$texte    = self::texte_sans_appels( $html );
		$marqueur = Texte::marqueur( $texte );
		$ancre    = $this->element(
			null !== $marqueur ? 'marqueur' : 'paragraphe',
			$texte,
			array(
				'marqueur' => $marqueur,
				'role'     => $role,
				'centre'   => $centre,
			)
		);
		if ( null === $this->plan && null !== $marqueur ) {
			// Marqueur : début de chapitre forcé, jamais publié.
			$this->ombre_marqueur( $marqueur );
			$this->ouvrir_marqueur( $ancre, $marqueur );
			return;
		}
		if ( null !== $this->plan ) {
			if ( null !== $marqueur ) {
				$this->ombre_marqueur( $marqueur );
			} else {
				$this->ombre_contenu();
			}
			if ( $this->au_plan( $ancre ) ) {
				// Ligne de titre placée sur le début manuel (marqueur, « Chapitre 3 : La Crête »,
				// texte identique au titre choisi) : elle devient le titre et n'est pas répétée.
				$analyse = null === $marqueur && Texte::compter_mots( Blocks::texte( $texte ) ) <= self::MOTS_LIGNE_COURTE ? Texte::analyser_titre( $texte ) : null;
				$titre   = null !== $marqueur ? $marqueur['titre'] : ( null !== $analyse && 'inconnu' !== $analyse['motif'] ? (string) $analyse['sous_titre'] : '' );
				$this->couper( $ancre, $titre, $html );
				if ( null !== $marqueur || ( null !== $analyse && 'inconnu' !== $analyse['motif'] ) || self::meme_texte( (string) $this->plan['debuts'][ $ancre ]['titre'], $texte ) ) {
					return;
				}
			} elseif ( null !== $marqueur ) {
				return;
			}
			$html = $this->notes_en_place( $html );
		} else {
			$this->ombre_contenu();
		}
		$classes = array();
		if ( 'dialogue' === $role ) {
			$classes[] = 'yn-dialogue';
			$centre    = false;
			$this->compter( 'dialogues' );
		} elseif ( 'pensee' === $role ) {
			$classes[] = 'yn-thought';
			$this->compter( 'pensees' );
		}
		if ( $centre ) {
			$classes[] = 'yn-center';
			$this->compter( 'centres' );
		}
		$this->compter( 'paragraphes' );
		$this->ajouter( Blocks::paragraphe( $html, $classes, $centre ), 'paragraphe' );
	}

	/**
	 * Élément de liste (puces ou numéros autres que le tiret de dialogue).
	 *
	 * @param string $html     HTML en ligne.
	 * @param bool   $ordonnee Liste numérotée.
	 */
	public function element_liste( string $html, bool $ordonnee ): void {
		$ancre = $this->element( 'liste', self::texte_sans_appels( $html ) );
		$this->ombre_contenu();
		if ( $this->au_plan( $ancre ) ) {
			$this->couper( $ancre, '', $html );
		}
		$html = $this->notes_en_place( $html );
		if ( null !== $this->liste && $this->liste['ordonnee'] !== $ordonnee ) {
			$this->vider_liste();
		}
		if ( null === $this->liste ) {
			$cible = null !== $this->courant ? 'courant' : 'avant';
			if ( $this->vides >= 2 && $this->a_contenu( $this->{$cible} ) ) {
				$this->pousser_separateur( $cible );
			}
			$this->vides = 0;
			$this->liste = array(
				'ordonnee' => $ordonnee,
				'items'    => array(),
			);
			if ( null !== $this->courant ) {
				$this->courant['attend_sous_titre'] = false;
			}
		}
		$this->consommer( strlen( $html ) + 32 );
		$this->liste['items'][] = $html;
		$this->compter( 'listes' );
	}

	/**
	 * Séparateur de scène explicite (***, ◇…).
	 */
	public function separateur(): void {
		$this->vider_liste();
		$this->vides                 = 0;
		$this->indices['separateur'] = true;
		$cible                       = null !== $this->courant ? 'courant' : 'avant';
		if ( $this->a_contenu( $this->{$cible} ) ) {
			$this->pousser_separateur( $cible );
		}
	}

	/**
	 * Élément ignoré (image non convertible…) : interrompt une suite de paragraphes vides
	 * sans rien ajouter.
	 */
	public function neutre(): void {
		$this->vides = 0;
	}

	/**
	 * Paragraphe vide.
	 */
	public function vide(): void {
		++$this->vides;
	}

	/**
	 * Illustration (clé d'image du Result).
	 *
	 * @param string $cle Clé.
	 */
	public function image( string $cle ): void {
		$ancre = $this->element( 'image', $cle );
		$this->ombre_contenu();
		if ( $this->au_plan( $ancre ) ) {
			$this->couper( $ancre, '' );
		}
		$alt = (string) ( $this->resultat->images[ $cle ]['alt'] ?? '' );
		if ( null === $this->courant ) {
			$this->avant['images'][] = $cle;
		} else {
			$this->courant['images'][] = $cle;
		}
		$this->compter( 'images' );
		$this->ajouter( Blocks::image_jeton( $cle, $alt ), 'image' );
	}

	/**
	 * Intertitre dans un chapitre.
	 *
	 * @param string $html   HTML en ligne.
	 * @param int    $niveau Niveau (2 à 4).
	 */
	public function titre( string $html, int $niveau ): void {
		$ancre = $this->element( 'intertitre', self::texte_sans_appels( $html ) );
		$this->ombre_contenu();
		if ( $this->au_plan( $ancre ) ) {
			$this->couper( $ancre, self::texte_sans_appels( $html ), $html );
			$this->dernier_titre = 'coupure';
			return;
		}
		$html = $this->notes_en_place( $html );
		$this->compter( 'titres' );
		$this->ajouter( Blocks::titre( $html, $niveau ), 'titre' );
	}

	/**
	 * Citation (EPUB blockquote).
	 *
	 * @param string[] $paragraphes HTML en ligne de chaque paragraphe.
	 */
	public function citation( array $paragraphes ): void {
		$paragraphes = array_values( array_filter( $paragraphes, static fn( $p ) => '' !== trim( $p ) ) );
		if ( ! $paragraphes ) {
			return;
		}
		$ancre = $this->element( 'citation', self::texte_sans_appels( implode( ' ', $paragraphes ) ) );
		$this->ombre_contenu();
		if ( $this->au_plan( $ancre ) ) {
			$this->couper( $ancre, '', implode( '', $paragraphes ) );
		}
		foreach ( $paragraphes as $i => $paragraphe ) {
			$paragraphes[ $i ] = $this->notes_en_place( $paragraphe );
		}
		$this->compter( 'paragraphes', count( $paragraphes ) );
		$this->ajouter( Blocks::citation( $paragraphes ), 'citation' );
	}

	/**
	 * Enregistre une note de bas de page du chapitre courant et renvoie son appel.
	 *
	 * @param string $html HTML en ligne de la note.
	 * @return string Appel de note (chaîne vide hors chapitre).
	 */
	public function note( string $html ): string {
		if ( null !== $this->plan && '' !== trim( $html ) ) {
			// Découpage manuel : le chapitre qui recevra l'appel n'est connu qu'avec le paragraphe.
			$this->consommer( strlen( $html ) + 128 );
			$k                         = $this->notes_vues++;
			$this->notes_attente[ $k ] = $html;
			return '<sup class="yn-note" data-yn-attente="' . $k . '"></sup>';
		}
		if ( null === $this->courant || '' === trim( $html ) ) {
			return '';
		}
		$this->consommer( strlen( $html ) + 128 );
		$n                               = count( $this->courant['notes'] ) + 1;
		$this->courant['notes'][ $n ]    = $html;
		$this->courant['stats']['notes'] = $n;
		return Blocks::appel_note( $n );
	}

	/**
	 * Découpage manuel : numérote dans le chapitre qui reçoit le contenu (ou dans le texte
	 * d'ouverture) les notes dont l'appel provisoire figure dans ce HTML, et place leurs appels.
	 *
	 * @param string $html HTML en ligne (appels provisoires).
	 */
	private function notes_en_place( string $html ): string {
		if ( ! $this->notes_attente || ! str_contains( $html, 'data-yn-attente' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#<sup class="yn-note" data-yn-attente="(\d+)"></sup>#',
			function ( array $m ): string {
				$n = $this->placer_note( (int) $m[1] );
				return $n > 0 ? Blocks::appel_note( $n ) : '';
			},
			$html
		);
	}

	/**
	 * Découpage manuel : numérote une note en attente dans le chapitre courant (ou le texte
	 * d'ouverture).
	 *
	 * @param int $k Rang de la note en attente.
	 * @return int Numéro dans le chapitre (0 : note déjà placée).
	 */
	private function placer_note( int $k ): int {
		if ( ! isset( $this->notes_attente[ $k ] ) ) {
			return 0;
		}
		$cible                            = null !== $this->courant ? 'courant' : 'avant';
		$n                                = count( $this->{$cible}['notes'] ) + 1;
		$this->{$cible}['notes'][ $n ]    = $this->notes_attente[ $k ];
		$this->{$cible}['stats']['notes'] = $n;
		unset( $this->notes_attente[ $k ] );
		return $n;
	}

	/**
	 * Découpage manuel : place les notes en attente dont l'appel n'a pas été repris (note d'un
	 * titre), sauf celles de l'élément en cours ; comme sans découpage, elles restent en fin
	 * du chapitre courant.
	 *
	 * @param string $html HTML de l'élément en cours.
	 */
	private function notes_orphelines( string $html = '' ): void {
		preg_match_all( '#data-yn-attente="(\d+)"#', $html, $m );
		$gardees = array_map( 'intval', $m[1] );
		foreach ( array_keys( $this->notes_attente ) as $k ) {
			if ( ! in_array( $k, $gardees, true ) ) {
				$this->placer_note( $k );
			}
		}
	}

	/**
	 * Ferme le chapitre courant.
	 */
	private function fermer(): void {
		$this->vider_liste();
		$this->vides = 0;
		if ( null === $this->courant ) {
			return;
		}
		$chapitre = $this->courant;
		while ( $chapitre['types'] && 'separateur' === end( $chapitre['types'] ) ) {
			array_pop( $chapitre['types'] );
			array_pop( $chapitre['blocs'] );
		}
		if ( $chapitre['notes'] ) {
			$chapitre['blocs'][] = Blocks::notes( $chapitre['notes'] );
		}
		$chapitre['stats']['separateurs'] = count( array_keys( $chapitre['types'], 'separateur', true ) );
		$chapitre['blocks']               = Blocks::assembler( $chapitre['blocs'] );
		$chapitre['nb_mots']              = Texte::compter_mots( $chapitre['blocks'] );
		unset( $chapitre['blocs'] );
		$this->termines[] = $chapitre;
		$this->courant    = null;
	}

	/**
	 * Termine la conversion : dernier chapitre, pages liminaires, numéros déduits, repli
	 * « chapitre unique », contrôles et statistiques, débuts de chapitre possibles.
	 */
	public function terminer(): void {
		$this->notes_orphelines();
		if ( null === $this->courant ) {
			$this->vider_liste();
		}
		$this->fermer();
		$avant = $this->avant;

		// Sections courtes, avant le premier chapitre reconnu, sans titre de chapitre : pages
		// liminaires (jamais avec un découpage manuel : chaque début a été choisi). Seules les
		// sections qui précèdent tout chapitre gardé sont concernées : une section courte placée
		// après un vrai chapitre (histoire bonus avant la postface…) est toujours publiée.
		$reconnu_vu = null !== $this->plan;
		$gardes     = array();
		foreach ( $this->termines as $chapitre ) {
			$reconnu_vu = $reconnu_vu || $chapitre['reconnu'];
			if ( ! $reconnu_vu && ! $chapitre['reconnu'] && $chapitre['nb_mots'] < self::MOTS_LIMINAIRE && $this->a_des_chapitres_reconnus() ) {
				$this->resultat->avertir( sprintf( 'Section « %s » placée avant le premier chapitre : traitée comme une page liminaire (non publiée).', $chapitre['titre_brut'] ) );
				$avant['images'] = array_merge( $avant['images'], $chapitre['images'] );
				foreach ( self::COMPTEURS as $compteur ) {
					$avant['stats'][ $compteur ] += $chapitre['stats'][ $compteur ];
				}
				continue;
			}
			$gardes[]   = $chapitre;
			$reconnu_vu = true;
		}
		$this->termines = $gardes;

		// Découpage manuel : numéros et libellés d'après la nature choisie.
		if ( null !== $this->plan ) {
			$numeros = self::numeroter(
				array_map(
					static fn( $c ) => array(
						'nature' => $c['nature'],
						'numero' => $c['numero'],
					),
					$this->termines
				)
			);
			foreach ( $this->termines as $i => $chapitre ) {
				$this->termines[ $i ]['numero'] = $numeros[ $i ]['numero'];
				$this->termines[ $i ]['titre']  = $numeros[ $i ]['titre'];
				if ( self::meme_texte( (string) $chapitre['sous_titre'], $numeros[ $i ]['titre'] ) || self::meme_texte( (string) $chapitre['sous_titre'], Texte::LIBELLES[ $chapitre['nature'] ] ?? '' ) ) {
					$this->termines[ $i ]['sous_titre'] = ''; // « Prologue — Prologue », « Bonus 2 — Bonus ».
				}
			}
		}

		// Numéros déduits pour les titres sans numéro (et les marqueurs « [chapitre] »).
		$dernier = 0.0;
		foreach ( null === $this->plan ? $this->termines : array() as $i => $chapitre ) {
			if ( $chapitre['manuel'] ) {
				if ( 'chapitre' === $chapitre['nature'] && null === $chapitre['numero'] ) {
					$this->termines[ $i ]['numero'] = floor( $dernier ) + 1;
				}
				$numero                        = $this->termines[ $i ]['numero'];
				$this->termines[ $i ]['titre'] = 'chapitre' === $chapitre['nature']
					? 'Chapitre ' . Texte::numero_fr( (float) $numero )
					: self::numeroter(
						array(
							array(
								'nature' => $chapitre['nature'],
								'numero' => $numero,
							),
						)
					)[0]['titre'];
				if ( 'chapitre' === $chapitre['nature'] ) {
					$dernier = max( $dernier, (float) $numero );
				}
				continue;
			}
			if ( ! $chapitre['reconnu'] ) {
				$numero                             = floor( $dernier ) + 1;
				$this->termines[ $i ]['numero']     = $numero;
				$this->termines[ $i ]['titre']      = 'Chapitre ' . Texte::numero_fr( $numero );
				$this->termines[ $i ]['sous_titre'] = '' !== (string) $chapitre['sous_titre'] ? (string) $chapitre['sous_titre'] : (string) $chapitre['titre_brut'];
				$this->resultat->avertir( sprintf( 'Titre « %1$s » sans numéro de chapitre : numéroté %2$s d’après l’ordre du document.', $chapitre['titre_brut'], Texte::numero_fr( $numero ) ) );
				$dernier = $numero;
				continue;
			}
			if ( 'chapitre' === $chapitre['nature'] && null !== $chapitre['numero'] ) {
				$dernier = max( $dernier, (float) $chapitre['numero'] );
			}
		}

		// Aucun chapitre : le document entier forme un chapitre unique.
		if ( ! $this->termines ) {
			if ( $this->a_contenu( $avant ) ) {
				$this->resultat->avertir( 'Aucun titre de chapitre (style Titre 1) : le document entier forme un seul chapitre.' );
				$this->courant            = $avant;
				$this->courant['numero']  = 1.0;
				$this->courant['titre']   = 'Chapitre 1';
				$this->courant['reconnu'] = true;
				$this->notes_orphelines();
				$this->fermer();
				$avant = $this->nouveau( 'chapitre', null, '', '', false );
			} else {
				$this->resultat->avertir( 'Aucun chapitre trouvé dans le document.' );
			}
		}

		// Galerie du tome et texte ignoré avant le premier chapitre.
		$this->resultat->front_images = array_values( array_unique( $avant['images'] ) );
		$ignores                      = (int) $avant['stats']['paragraphes'] + (int) $avant['stats']['listes'];
		if ( $ignores > 0 ) {
			$this->resultat->avertir(
				sprintf(
					1 === $ignores ? '%d paragraphe placé avant le premier chapitre a été ignoré.' : '%d paragraphes placés avant le premier chapitre ont été ignorés.',
					$ignores
				)
			);
		}

		// Chapitres du Result et statistiques.
		$stats = array_fill_keys( self::COMPTEURS, 0 );
		$mots  = 0;
		foreach ( $this->termines as $chapitre ) {
			if ( ! $this->a_contenu( $chapitre ) ) {
				$this->resultat->avertir( sprintf( '%s : chapitre vide (aucun paragraphe).', $chapitre['titre'] ) );
			}
			$this->resultat->chapters[] = array(
				'numero'     => $chapitre['numero'],
				'nature'     => $chapitre['nature'],
				'titre'      => $chapitre['titre'],
				'sous_titre' => $chapitre['sous_titre'],
				'blocks'     => $chapitre['blocks'],
				'nb_mots'    => $chapitre['nb_mots'],
				'stats'      => $chapitre['stats'],
				'images'     => array_values( array_unique( $chapitre['images'] ) ),
			);
			foreach ( self::COMPTEURS as $compteur ) {
				$stats[ $compteur ] += $chapitre['stats'][ $compteur ];
			}
			$mots += $chapitre['nb_mots'];
		}
		$this->resultat->stats                         = array_merge( $this->resultat->stats, $stats );
		$this->resultat->stats['chapitres']            = count( $this->resultat->chapters );
		$this->resultat->stats['mots']                 = $mots;
		$this->resultat->stats['paragraphes_ignores']  = $ignores;
		$this->resultat->stats['images_avant_premier'] = count( $this->resultat->front_images );
		$this->controler_numeros();
		$this->conclure_decoupage();
	}

	/**
	 * Débuts de chapitre possibles (Result::$candidats) : début automatique ou non, élément
	 * placé avant le premier chapitre, nature et titre proposés ; découpages rapides ; débuts
	 * manuels introuvables.
	 */
	private function conclure_decoupage(): void {
		$debuts = array();
		foreach ( $this->termines as $chapitre ) {
			if ( '' !== (string) $chapitre['ancre'] ) {
				$debuts[ (string) $chapitre['ancre'] ] = $chapitre;
			}
		}
		$premier    = $debuts ? (int) substr( (string) array_key_first( $debuts ), 1 ) : 0;
		$decoupages = array(
			'images'     => array(),
			'ouvertures' => array(),
			'sauts'      => array(),
		);
		// Ouvertures illustrées : premières illustrations d'une suite d'au moins deux
		// (pages d'ouverture de chapitre en double page, par exemple), hors début du document.
		// Une suite commence à une illustration marquée « groupe » (précédée d'autre chose
		// qu'une illustration, les ornements ne comptant pas).
		$suites = array();
		foreach ( $this->candidats as $candidat ) {
			if ( 'image' !== $candidat['type'] ) {
				continue;
			}
			if ( $candidat['groupe'] || ! $suites ) {
				$suites[] = array( $candidat );
			} else {
				$suites[ count( $suites ) - 1 ][] = $candidat;
			}
		}
		foreach ( $suites as $suite ) {
			if ( count( $suite ) >= 2 && 1 !== $suite[0]['rang'] ) {
				$decoupages['ouvertures'][] = $suite[0]['ancre'];
			}
		}
		foreach ( $this->candidats as $i => $candidat ) {
			$chapitre = $debuts[ $candidat['ancre'] ] ?? null;
			if ( null !== $chapitre ) {
				// Début retenu : nature et titre du chapitre produit.
				$this->candidats[ $i ]['auto']   = true;
				$this->candidats[ $i ]['nature'] = (string) $chapitre['nature'];
				$this->candidats[ $i ]['titre']  = mb_substr( Texte::espaces( (string) $chapitre['sous_titre'] ), 0, self::TITRE_MAX, 'UTF-8' );
			}
			$this->candidats[ $i ]['avant'] = $candidat['rang'] < $premier;
			if ( $candidat['groupe'] ) {
				$decoupages['images'][] = $candidat['ancre'];
			}
			if ( in_array( 'saut_page', $candidat['raisons'], true ) ) {
				$decoupages['sauts'][] = $candidat['ancre'];
			}
			unset( $this->candidats[ $i ]['groupe'] );
		}
		$this->resultat->candidats                   = $this->candidats;
		$this->resultat->decoupages                  = $decoupages;
		$this->resultat->stats['elements']           = $this->rang;
		$this->resultat->stats['candidats']          = count( $this->candidats );
		$this->resultat->stats['candidats_tronques'] = ! empty( $this->resultat->stats['candidats_tronques'] );
		$this->resultat->stats['decoupage_manuel']   = null !== $this->plan;
		foreach ( null !== $this->plan ? array_keys( $this->plan['debuts'] ) : array() as $ancre ) {
			if ( ! isset( $this->trouves[ $ancre ] ) ) {
				$this->resultat->avertir( sprintf( 'Début de chapitre manuel introuvable : %s (le fichier a changé depuis l’analyse ? Relancez l’analyse et refaites le découpage).', $ancre ) );
			}
		}
	}

	/**
	 * Au moins un chapitre terminé porte-t-il un titre reconnu ?
	 */
	private function a_des_chapitres_reconnus(): bool {
		foreach ( $this->termines as $chapitre ) {
			if ( $chapitre['reconnu'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Numéros de chapitres en double, manquants ou dans le désordre.
	 */
	private function controler_numeros(): void {
		$vus       = array();
		$precedent = null;
		foreach ( $this->resultat->chapters as $chapitre ) {
			if ( null === $chapitre['numero'] ) {
				continue;
			}
			$cle = $chapitre['nature'] . ':' . Texte::numero_url( (float) $chapitre['numero'] );
			if ( isset( $vus[ $cle ] ) ) {
				$this->resultat->avertir( sprintf( '%s : numéro en double (déjà utilisé plus haut dans le document).', $chapitre['titre'] ) );
			}
			$vus[ $cle ] = true;
			if ( 'chapitre' !== $chapitre['nature'] ) {
				continue;
			}
			$n = (float) $chapitre['numero'];
			if ( null !== $precedent ) {
				if ( $n < $precedent ) {
					$this->resultat->avertir( sprintf( '%1$s placé après le chapitre %2$s : vérifiez l’ordre des chapitres.', $chapitre['titre'], Texte::numero_fr( $precedent ) ) );
				} elseif ( floor( $n ) > floor( $precedent ) + 1 ) {
					$manquants = floor( $n ) - floor( $precedent ) - 1;
					$this->resultat->avertir(
						1.0 === $manquants
							? sprintf( 'Chapitre %1$s absent : le document passe du chapitre %2$s au chapitre %3$s.', Texte::numero_fr( floor( $precedent ) + 1 ), Texte::numero_fr( $precedent ), Texte::numero_fr( $n ) )
							: sprintf( 'Chapitres %1$s à %2$s absents : le document passe du chapitre %3$s au chapitre %4$s.', Texte::numero_fr( floor( $precedent ) + 1 ), Texte::numero_fr( floor( $n ) - 1 ), Texte::numero_fr( $precedent ), Texte::numero_fr( $n ) )
					);
				}
			}
			$precedent = null === $precedent ? $n : max( $precedent, $n );
		}
	}
}
