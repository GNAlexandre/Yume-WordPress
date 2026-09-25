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
 * Aucune fonction WordPress.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Constructeur de chapitres.
 */
final class Chapter_Builder {

	/** Compteurs statistiques d'un chapitre. */
	public const COMPTEURS = array( 'paragraphes', 'dialogues', 'pensees', 'centres', 'separateurs', 'images', 'notes', 'listes', 'titres' );

	/** En dessous de ce nombre de mots, une section liminaire sans titre de chapitre n'est pas un chapitre. */
	public const MOTS_LIMINAIRE = 400;

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
	 * Constructeur.
	 *
	 * @param Result $resultat Résultat à remplir.
	 */
	public function __construct( Result $resultat ) {
		$this->resultat = $resultat;
		$this->avant    = $this->nouveau( 'chapitre', null, '', '', false );
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
	 * Un chapitre est-il ouvert ?
	 */
	public function en_chapitre(): bool {
		return null !== $this->courant;
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
	 * Le chapitre vient-il d'être ouvert, sans contenu ni sous-titre ?
	 */
	public function attend_sous_titre(): bool {
		return null !== $this->courant && $this->courant['attend_sous_titre'] && ! $this->courant['blocs'] && null === $this->liste;
	}

	/**
	 * Le chapitre courant a-t-il été ouvert par un titre de chapitre reconnu ?
	 */
	public function titre_reconnu(): bool {
		return null !== $this->courant && $this->courant['reconnu'];
	}

	/**
	 * Définit le sous-titre du chapitre courant.
	 *
	 * @param string $texte Texte brut.
	 */
	public function sous_titre( string $texte ): void {
		if ( null === $this->courant ) {
			return;
		}
		$this->courant['sous_titre']        = Texte::espaces( $texte );
		$this->courant['attend_sous_titre'] = false;
	}

	/**
	 * Ouvre un chapitre à partir de l'analyse de son titre (Texte::analyser_titre()).
	 *
	 * @param array<string,mixed> $analyse Analyse du titre.
	 * @param string              $brut    Texte brut du titre (pour les avertissements).
	 */
	public function ouvrir( array $analyse, string $brut ): void {
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
		$this->vides                 = 0;
	}

	/**
	 * Ajoute un bloc au chapitre courant (ou au contenu d'avant le premier chapitre).
	 *
	 * @param string $bloc Balisage.
	 * @param string $type Type (paragraphe, separateur, image, liste, titre, citation).
	 */
	private function ajouter( string $bloc, string $type ): void {
		if ( 'liste' !== $type ) {
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
		$this->liste['items'][] = $html;
		$this->compter( 'listes' );
	}

	/**
	 * Séparateur de scène explicite (***, ◇…).
	 */
	public function separateur(): void {
		$this->vider_liste();
		$this->vides = 0;
		$cible       = null !== $this->courant ? 'courant' : 'avant';
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
		if ( null === $this->courant || '' === trim( $html ) ) {
			return '';
		}
		$n                               = count( $this->courant['notes'] ) + 1;
		$this->courant['notes'][ $n ]    = $html;
		$this->courant['stats']['notes'] = $n;
		return Blocks::appel_note( $n );
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
	 * « chapitre unique », contrôles et statistiques.
	 */
	public function terminer(): void {
		if ( null === $this->courant ) {
			$this->vider_liste();
		}
		$this->fermer();
		$avant = $this->avant;

		// Sections courtes, avant le premier chapitre reconnu, sans titre de chapitre : pages liminaires.
		$reconnu_vu = false;
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
			$gardes[] = $chapitre;
		}
		$this->termines = $gardes;

		// Numéros déduits pour les titres sans numéro.
		$dernier = 0.0;
		foreach ( $this->termines as $i => $chapitre ) {
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
