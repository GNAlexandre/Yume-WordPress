<?php
/**
 * Moteur commun de l'exécution et de l'annulation de la migration : une suite d'étapes, chacune
 * composée d'éléments traités un par un, par lots limités dans le temps (pour tenir dans le
 * délai d'exécution de l'hébergeur), avec reprise au dernier élément non traité.
 *
 * L'état (étape, curseur, progression, messages) et le journal (correspondances,
 * sauvegardes) sont tenus en mémoire par le moteur et enregistrés par Migration_Runner.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Suite d'étapes traitées par lots.
 */
abstract class Migration_Moteur {

	/**
	 * Plan de migration.
	 *
	 * @var array<string,mixed>
	 */
	protected array $plan;

	/**
	 * Journal (correspondances, sauvegardes, modifications).
	 *
	 * @var array<string,mixed>
	 */
	protected array $journal;

	/**
	 * État (statut, étape, curseur, progression, messages…).
	 *
	 * @var array<string,mixed>
	 */
	protected array $etat;

	/**
	 * Options du lot (forcer, choix…).
	 *
	 * @var array<string,mixed>
	 */
	protected array $options;

	/**
	 * Enregistrement de l'avancement (journal et état) pendant un lot, ou null.
	 *
	 * @var callable|null
	 */
	private $persistance = null;

	/**
	 * Constructeur.
	 *
	 * @param array $plan    Plan.
	 * @param array $journal Journal.
	 * @param array $etat    État.
	 * @param array $options Options.
	 */
	public function __construct( array $plan, array $journal, array $etat, array $options = array() ) {
		$this->plan    = $plan;
		$this->journal = $journal;
		$this->etat    = $etat;
		$this->options = $options;
	}

	/**
	 * Étapes (clé => libellé), dans l'ordre.
	 *
	 * @return array<string,string>
	 */
	abstract public static function etapes(): array;

	/**
	 * Nombre d'éléments d'une étape.
	 *
	 * @param string $etape Étape.
	 */
	abstract public function total( string $etape ): int;

	/**
	 * Traite un élément d'une étape.
	 *
	 * @param string $etape Étape.
	 * @param int    $index Index de l'élément.
	 * @throws \RuntimeException Erreur bloquante (l'élément sera retenté à la reprise).
	 */
	abstract protected function traiter( string $etape, int $index ): void;

	/**
	 * Fin de l'opération (toutes les étapes traitées) : statut final.
	 */
	abstract protected function finir(): void;

	/**
	 * Journal courant.
	 *
	 * @return array<string,mixed>
	 */
	public function journal(): array {
		return $this->journal;
	}

	/**
	 * État courant.
	 *
	 * @return array<string,mixed>
	 */
	public function etat(): array {
		return $this->etat;
	}

	/**
	 * Nombre total d'éléments de toutes les étapes.
	 */
	public function total_general(): int {
		$total = 0;
		foreach ( array_keys( static::etapes() ) as $etape ) {
			$total += $this->total( $etape );
		}
		return $total;
	}

	/**
	 * Éléments déjà traités (étapes terminées et curseur de l'étape courante).
	 */
	public function fait(): int {
		$fait = 0;
		foreach ( array_keys( static::etapes() ) as $etape ) {
			if ( $etape === $this->etat['etape'] ) {
				return $fait + min( (int) $this->etat['curseur'], $this->total( $etape ) );
			}
			$fait += $this->total( $etape );
		}
		return $fait;
	}

	/**
	 * Ajoute un message au journal lisible de l'opération.
	 *
	 * @param string $texte Message.
	 * @param string $type  info, succes, avertissement ou erreur.
	 */
	protected function message( string $texte, string $type = 'info' ): void {
		Migration_State::message( $this->etat, $texte, $type );
	}

	/**
	 * Incrémente un compteur de l'état.
	 *
	 * @param string $cle Compteur (« oeuvres_crees »…).
	 * @param int    $n   Valeur ajoutée.
	 */
	protected function compter( string $cle, int $n = 1 ): void {
		$this->etat['comptes'][ $cle ] = (int) ( $this->etat['comptes'][ $cle ] ?? 0 ) + $n;
	}

	/**
	 * Traite des éléments jusqu'à épuisement du budget de temps (au moins un élément).
	 *
	 * @param float         $budget     Secondes.
	 * @param callable|null $sauvegarde Appelée régulièrement pour enregistrer l'avancement.
	 * @return bool Vrai si l'opération est terminée.
	 */
	public function avancer( float $budget, ?callable $sauvegarde = null ): bool {
		$etapes               = array_keys( static::etapes() );
		$fin                  = microtime( true ) + max( 0.0, $budget );
		$traites              = 0;
		$derniere             = microtime( true );
		$this->etat['erreur'] = '';
		$this->persistance    = $sauvegarde;
		while ( true ) {
			$etape = (string) $this->etat['etape'];
			$pos   = array_search( $etape, $etapes, true );
			if ( false === $pos ) {
				$this->etat['etape']   = $etapes[0];
				$this->etat['curseur'] = 0;
				continue;
			}
			if ( (int) $this->etat['curseur'] >= $this->total( $etape ) ) {
				if ( ! in_array( $etape, (array) $this->etat['etapes'], true ) ) {
					$this->etat['etapes'][] = $etape;
				}
				if ( ! isset( $etapes[ $pos + 1 ] ) ) {
					$this->finir();
					$this->etat['progression'] = array(
						'fait'  => $this->total_general(),
						'total' => $this->total_general(),
					);
					return true;
				}
				$this->etat['etape']   = $etapes[ $pos + 1 ];
				$this->etat['curseur'] = 0;
				continue;
			}
			if ( $traites > 0 && microtime( true ) >= $fin ) {
				break;
			}
			try {
				$this->traiter( $etape, (int) $this->etat['curseur'] );
			} catch ( \Throwable $e ) {
				$this->etat['erreur'] = sprintf( '%s (étape « %s », élément %d) : %s', static::etapes()[ $etape ], $etape, (int) $this->etat['curseur'] + 1, $e->getMessage() );
				$this->message( $this->etat['erreur'], 'erreur' );
				break;
			}
			++$this->etat['curseur'];
			++$traites;
			$this->etat['progression'] = array(
				'fait'  => $this->fait(),
				'total' => $this->total_general(),
			);
			if ( $sauvegarde && microtime( true ) - $derniere > 1.0 ) {
				$sauvegarde( $this );
				$derniere = microtime( true );
			}
		}
		$this->etat['progression'] = array(
			'fait'  => $this->fait(),
			'total' => $this->total_general(),
		);
		return false;
	}

	/**
	 * Enregistre tout de suite le journal et l'état (après une modification de la base qu'une
	 * reprise ne saurait pas reconstituer : sans cela, un arrêt brutal du processus PHP avant
	 * l'enregistrement de fin de lot ferait oublier la modification à l'annulation).
	 */
	protected function persister(): void {
		if ( null !== $this->persistance ) {
			( $this->persistance )( $this );
		}
	}

	/**
	 * Un élément vient d'être ignoré à la demande de l'équipe (à surcharger).
	 *
	 * @param string $etape Étape.
	 * @param int    $index Index de l'élément.
	 */
	protected function noter_ignore( string $etape, int $index ): void {
		unset( $etape, $index );
	}

	/**
	 * Saute l'élément courant (erreur que l'équipe choisit d'ignorer).
	 */
	public function ignorer_element(): void {
		$etape = (string) $this->etat['etape'];
		if ( '' !== (string) $this->etat['erreur'] && (int) $this->etat['curseur'] < $this->total( $etape ) ) {
			$this->message( sprintf( 'Élément %d de l’étape « %s » ignoré à la demande.', (int) $this->etat['curseur'] + 1, static::etapes()[ $etape ] ?? $etape ), 'avertissement' );
			$this->noter_ignore( $etape, (int) $this->etat['curseur'] );
			++$this->etat['curseur'];
			$this->compter( 'ignores' );
		}
		$this->etat['erreur'] = '';
	}

	/**
	 * Premier administrateur du site (auteur par défaut).
	 */
	protected static function premier_admin(): int {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Change le statut d'un contenu sans réécrire son contenu ni sa date de modification
	 * (requête directe, exacte et réversible), puis prévient les extensions à l'écoute des
	 * changements de statut (cache, synchronisation) comme le fait wp_publish_post().
	 *
	 * @param int         $id      Contenu.
	 * @param string      $statut  Nouveau statut.
	 * @param string|null $modifie Date de modification locale à poser (null : inchangée).
	 */
	protected static function changer_statut( int $id, string $statut, ?string $modifie = null ): void {
		global $wpdb;
		$avant = get_post( $id );
		if ( ! $avant instanceof \WP_Post ) {
			return;
		}
		$champs = array( 'post_status' => $statut );
		if ( null !== $modifie && '' !== $modifie ) {
			$champs['post_modified']     = $modifie;
			$champs['post_modified_gmt'] = get_gmt_from_date( $modifie );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->posts, $champs, array( 'ID' => $id ) );
		clean_post_cache( $id );
		$apres = get_post( $id );
		if ( $apres instanceof \WP_Post && $avant->post_status !== $statut ) {
			wp_transition_post_status( $statut, $avant->post_status, $apres );
		}
	}
}
