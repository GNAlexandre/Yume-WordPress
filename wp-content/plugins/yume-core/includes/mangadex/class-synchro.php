<?php
/**
 * Synchronisation des chapitres MangaDex du groupe avec le site (contrat §8 bis).
 *
 * Chaque heure (WP-Cron, une fois activée), les chapitres en français du groupe sont lus sur
 * MangaDex et rapprochés des œuvres liées à leur série (yume_mdx_serie) :
 *
 * - un volume MangaDex est un tome de l'œuvre (nature « tome », yume_numero = volume) ; les
 *   chapitres sans volume vont dans un tome de nature « chapitres ». Le tome de même nature et
 *   numéro est repris s'il existe (par exemple créé par l'équipe au planning avec « Nouveau
 *   tome ») ; sinon il est créé. Un tome jamais publié, ou rangé par la synchronisation parce
 *   qu'il était vide (_yume_mdx_tome_range), est publié avec la parution « en cours »
 *   (yume_parution) à son premier chapitre ; un tome déjà paru puis retiré par l'équipe reste
 *   hors ligne (alerte) ;
 * - un chapitre nouveau devient un chapitre publié du site, daté de sa mise en lecture sur
 *   MangaDex, sans contenu (ses pages sont relayées à la lecture) ; un chapitre connu dont la
 *   version a changé est mis à jour en place (même adresse, mêmes commentaires) ;
 * - un chapitre disparu du flux, ou devenu externe, passe en brouillon (méta _yume_mdx_retire) ;
 *   il revient en ligne s'il réapparaît.
 *
 * Annonces : la **première synchronisation d'une œuvre** est un ajout au catalogue, sans aucune
 * annonce (ni Discord, ni e-mail : _yume_publie_notifie = catalogue et filtre yume_core_notifier
 * coupé). Ensuite, le circuit habituel de core s'applique : un nouveau tome (nouveau volume) est
 * annoncé avec ses chapitres (yume_tome_publie, sortie partielle), un nouveau chapitre d'un tome
 * déjà en ligne est annoncé seul (yume_chapitre_publie) ; un contenu daté de plus de 2 jours ne
 * l'est jamais (core).
 *
 * Règles : une œuvre « Licenciée » (yume_statut) est ignorée entièrement, rien n'est créé ni
 * remis en ligne (sa lecture a été retirée par le changement d'état, qui la rétablit à la
 * sortie) ; un chapitre mis en brouillon à la main par l'équipe n'est jamais remis en ligne
 * (seuls ceux que la synchronisation a retirés, méta _yume_mdx_retire, le sont).
 *
 * Garde-fous : rien n'est modifié si la lecture du flux échoue ; aucun retrait si le flux est vide,
 * ni si plus de la moitié des chapitres MangaDex du site devaient être retirés d'un coup (alerte
 * dans le rapport) ; œuvre non publiée ignorée ; une seule synchronisation à la fois (verrou) ;
 * mode simulation qui n'écrit rien.
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/**
 * Une exécution de la synchronisation.
 */
final class Synchro {

	/** Tâche WP-Cron (toutes les heures). */
	public const CRON = 'yume_mangadex_synchro';

	/** Option : rapport de la dernière synchronisation (non autochargée). */
	public const OPTION_RAPPORT = 'yume_mangadex_synchro';

	/** Option : verrou d'exécution (heure de prise, abandonné après 15 min). */
	public const VERROU = 'yume_mdx_synchro_verrou';

	/** Méta d'une œuvre : date GMT de sa première synchronisation (absente : import initial). */
	public const META_SYNCHRONISEE = '_yume_mdx_synchronisee';

	/** Méta d'un chapitre retiré parce qu'il a disparu de MangaDex (date GMT). */
	public const META_RETIRE = '_yume_mdx_retire';

	/** Méta d'un tome créé par la synchronisation. */
	public const META_TOME_SYNCHRO = '_yume_mdx_tome';

	/** Méta d'un tome mis en brouillon par la synchronisation parce qu'il était vide (date GMT). */
	public const META_TOME_RANGE = '_yume_mdx_tome_range';

	/** État d'une œuvre (yume_statut) qui retire sa lecture du site : la synchronisation l'ignore. */
	public const ETAT_LICENCIEE = 'licenciee';

	/** Méta de core : notification de publication traitée (valeur « catalogue » : jamais annoncé). */
	public const META_NOTIFIE = '_yume_publie_notifie';

	/** Part maximale des chapitres MangaDex du site retirés en une fois. */
	public const PART_MAX_RETRAITS = 0.5;

	/**
	 * Simulation : rien n'est écrit, le rapport dit ce qui serait fait.
	 *
	 * @var bool
	 */
	private bool $simulation;

	/**
	 * Rapport en cours.
	 *
	 * @var array<string,mixed>
	 */
	private array $rapport;

	/**
	 * Tomes publiés à la fin du traitement d'une œuvre (créés ou en brouillon).
	 *
	 * @var array<int,array{date:string,catalogue:bool}>
	 */
	private array $tomes_a_publier = array();

	/**
	 * Simulation : tomes qui seraient créés (œuvre|nature|numéro => true).
	 *
	 * @var array<string,bool>
	 */
	private array $tomes_simules = array();

	/**
	 * Tomes hors ligne déjà signalés (identifiant => vrai).
	 *
	 * @var array<int,bool>
	 */
	private array $tomes_hors_ligne = array();

	/**
	 * La synchronisation peut-elle tourner (réglage mangadex_synchro et lecteur livré) ?
	 */
	public static function active(): bool {
		$active = function_exists( 'yume_setting' ) && (bool) yume_setting( 'mangadex_synchro', false ) && lecteur_pret();
		/**
		 * Active ou coupe la synchronisation des chapitres MangaDex.
		 *
		 * @param bool $active Réglage « Synchroniser les chapitres » coché et lecteur livré.
		 */
		return (bool) apply_filters( 'yume_mangadex_synchro_active', $active );
	}

	/**
	 * Lance une synchronisation et enregistre son rapport (sauf simulation).
	 *
	 * @param bool                                $simulation Ne rien écrire.
	 * @param array<int,array<string,mixed>>|null $chapitres  Chapitres déjà lus (tests) ; null : lus sur MangaDex.
	 * @return array<string,mixed> Rapport.
	 */
	public static function executer( bool $simulation = false, ?array $chapitres = null ): array {
		$synchro = new self( $simulation );
		return $synchro->lancer( $chapitres );
	}

	/**
	 * Rapport de la dernière synchronisation enregistrée (tableau vide si aucune).
	 *
	 * @return array<string,mixed>
	 */
	public static function dernier_rapport(): array {
		$rapport = get_option( self::OPTION_RAPPORT, array() );
		return is_array( $rapport ) ? $rapport : array();
	}

	/**
	 * Constructeur.
	 *
	 * @param bool $simulation Ne rien écrire.
	 */
	private function __construct( bool $simulation ) {
		$this->simulation = $simulation;
		$this->rapport    = array(
			'date'                 => current_time( 'mysql', true ),
			'simulation'           => $simulation,
			'ok'                   => false,
			'erreur'               => '',
			'groupe'               => groupe(),
			'chapitres_mangadex'   => 0,
			'tomes_crees'          => 0,
			'crees'                => 0,
			'maj'                  => 0,
			'maj_hors_ligne'       => 0,
			'remis_en_ligne'       => 0,
			'retires'              => 0,
			'externes'             => 0,
			'tomes_vides'          => 0,
			'oeuvres'              => array(),
			'series_non_liees'     => array(),
			'oeuvres_non_publiees' => array(),
			'oeuvres_licenciees'   => array(),
			'alertes'              => array(),
		);
	}

	/**
	 * Exécution : verrou, lecture du flux, traitement par série, rapport.
	 *
	 * @param array<int,array<string,mixed>>|null $chapitres Chapitres déjà lus, ou null.
	 * @return array<string,mixed>
	 */
	private function lancer( ?array $chapitres ): array {
		if ( ! $this->simulation && ! add_option( self::VERROU, time(), '', false ) ) {
			$depuis = (int) get_option( self::VERROU );
			if ( $depuis > time() - 15 * MINUTE_IN_SECONDS ) {
				$this->rapport['erreur'] = __( 'Une synchronisation est déjà en cours.', 'yume-core' );
				return $this->rapport;
			}
			update_option( self::VERROU, time(), false ); // Verrou abandonné (plus de 15 min).
		}
		try {
			if ( null === $chapitres ) {
				$chapitres = Client::chapitres_du_groupe( $this->rapport['groupe'] );
			}
			if ( is_wp_error( $chapitres ) ) {
				$this->rapport['erreur'] = $chapitres->get_error_message();
				return $this->terminer();
			}
			$this->rapport['chapitres_mangadex'] = count( $chapitres );
			$par_serie                           = array();
			foreach ( $chapitres as $chapitre ) {
				$par_serie[ (string) $chapitre['serie']['id'] ][] = $chapitre;
			}
			$retraits_permis = $this->retraits_permis( $chapitres );
			foreach ( $par_serie as $serie => $liste ) {
				$this->traiter_serie( (string) $serie, $liste, $retraits_permis );
			}
			// Œuvres liées dont la série a disparu du flux : leurs chapitres sont retirés.
			foreach ( $this->oeuvres_liees() as $oeuvre_id => $serie ) {
				if ( ! isset( $par_serie[ $serie ] ) && 'publish' === get_post_status( $oeuvre_id ) ) {
					$this->retirer_absents( $oeuvre_id, array(), $retraits_permis );
				}
			}
			$this->rapport['ok'] = true;
			return $this->terminer();
		} finally {
			if ( ! $this->simulation ) {
				delete_option( self::VERROU );
			}
		}
	}

	/**
	 * Enregistre le rapport (sauf simulation) et le renvoie.
	 *
	 * @return array<string,mixed>
	 */
	private function terminer(): array {
		if ( ! $this->simulation ) {
			update_option( self::OPTION_RAPPORT, $this->rapport, false );
		}
		return $this->rapport;
	}

	/**
	 * Les retraits sont-ils permis ? Non si le flux est vide, ou si plus de PART_MAX_RETRAITS des
	 * chapitres MangaDex publiés du site en seraient retirés (réglage de groupe erroné, panne).
	 *
	 * @param array<int,array<string,mixed>> $chapitres Flux.
	 */
	private function retraits_permis( array $chapitres ): bool {
		$sur_site = $this->chapitres_du_site( array( 'publish', 'future' ) );
		if ( ! $sur_site ) {
			return true;
		}
		if ( ! $chapitres ) {
			$this->rapport['alertes'][] = __( 'MangaDex ne renvoie aucun chapitre pour ce groupe : aucun chapitre n’a été retiré du site. Vérifiez le réglage « Groupe MangaDex ».', 'yume-core' );
			return false;
		}
		$lisibles = array();
		foreach ( $chapitres as $chapitre ) {
			if ( '' === $chapitre['externe'] ) {
				$lisibles[ (string) $chapitre['id'] ] = true;
			}
		}
		$absents = count( array_diff_key( $sur_site, $lisibles ) );
		if ( $absents > self::PART_MAX_RETRAITS * count( $sur_site ) ) {
			/* translators: 1 : chapitres absents, 2 : chapitres MangaDex du site */
			$this->rapport['alertes'][] = sprintf( __( '%1$d chapitres sur %2$d auraient été retirés d’un coup : retraits suspendus par prudence. Vérifiez le réglage « Groupe MangaDex » et la page du groupe sur MangaDex.', 'yume-core' ), $absents, count( $sur_site ) );
			return false;
		}
		return true;
	}

	/**
	 * Chapitres MangaDex du site (identifiant MangaDex => ID), aux statuts demandés.
	 *
	 * @param string[] $statuts Statuts.
	 * @param int      $oeuvre_id Œuvre (0 : toutes).
	 * @return array<string,int>
	 */
	private function chapitres_du_site( array $statuts, int $oeuvre_id = 0 ): array {
		$requete = array(
			'post_type'      => 'yume_chapitre',
			'post_status'    => $statuts,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tâche planifiée, une fois par heure.
			'meta_query'     => array(
				array(
					'key'     => 'yume_mdx_chapitre',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);
		if ( $oeuvre_id ) {
			$requete['meta_query'][] = array(
				'key'   => 'yume_oeuvre_id',
				'value' => $oeuvre_id,
			);
		}
		$chapitres = array();
		foreach ( get_posts( $requete ) as $id ) {
			$uuid = extraire_uuid( get_post_meta( (int) $id, 'yume_mdx_chapitre', true ) );
			if ( '' !== $uuid ) {
				$chapitres[ $uuid ] = (int) $id;
			}
		}
		return $chapitres;
	}

	/**
	 * Œuvres liées à une série MangaDex (ID => série).
	 *
	 * @return array<int,string>
	 */
	private function oeuvres_liees(): array {
		$oeuvres = array();
		$ids     = get_posts(
			array(
				'post_type'      => 'yume_oeuvre',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tâche planifiée, une fois par heure.
				'meta_query'     => array(
					array(
						'key'     => 'yume_mdx_serie',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$serie = yume_mangadex_serie( (int) $id );
			if ( '' !== $serie && yume_mangadex_oeuvre_par_serie( $serie ) === (int) $id ) {
				$oeuvres[ (int) $id ] = $serie;
			}
		}
		return $oeuvres;
	}

	/**
	 * Traite les chapitres d'une série : œuvre liée, tomes, chapitres, retraits.
	 *
	 * @param string                         $serie           Série.
	 * @param array<int,array<string,mixed>> $chapitres       Chapitres de la série (ordre de mise en lecture).
	 * @param bool                           $retraits_permis Retraits autorisés.
	 */
	private function traiter_serie( string $serie, array $chapitres, bool $retraits_permis ): void {
		$oeuvre_id = yume_mangadex_oeuvre_par_serie( $serie );
		if ( ! $oeuvre_id ) {
			$this->rapport['series_non_liees'][] = array(
				'serie'     => $serie,
				'titre'     => (string) ( $chapitres[0]['serie']['titre'] ?? '' ),
				'chapitres' => count( $chapitres ),
			);
			return;
		}
		if ( 'publish' !== get_post_status( $oeuvre_id ) ) {
			$this->rapport['oeuvres_non_publiees'][] = array(
				'oeuvre' => $oeuvre_id,
				'titre'  => get_the_title( $oeuvre_id ),
			);
			return;
		}
		// Licenciée : lecture retirée du site, aucun chapitre créé, modifié ni remis en ligne.
		if ( has_term( self::ETAT_LICENCIEE, 'yume_statut', $oeuvre_id ) ) {
			$this->rapport['oeuvres_licenciees'][] = array(
				'oeuvre'    => $oeuvre_id,
				'titre'     => get_the_title( $oeuvre_id ),
				'chapitres' => count( $chapitres ),
			);
			return;
		}
		$catalogue             = ! metadata_exists( 'post', $oeuvre_id, self::META_SYNCHRONISEE );
		$this->tomes_a_publier = array();
		$avant                 = array(
			'crees'   => $this->rapport['crees'],
			'maj'     => $this->rapport['maj'],
			'retires' => $this->rapport['retires'],
		);
		$coupure               = static function () {
			return false;
		};
		if ( $catalogue && ! $this->simulation ) {
			// Import initial : aucune annonce, même si un chapitre est récent.
			add_filter( 'yume_core_notifier', $coupure, 99 );
		}
		try {
			$lisibles = array();
			foreach ( $chapitres as $chapitre ) {
				if ( '' !== $chapitre['externe'] ) {
					++$this->rapport['externes'];
					continue;
				}
				$lisibles[ (string) $chapitre['id'] ] = true;
				$this->traiter_chapitre( $oeuvre_id, $chapitre, $catalogue );
			}
			$this->publier_tomes();
			$this->retirer_absents( $oeuvre_id, $lisibles, $retraits_permis );
			$this->ranger_tomes_vides( $oeuvre_id );
			if ( ! $this->simulation && $catalogue ) {
				update_post_meta( $oeuvre_id, self::META_SYNCHRONISEE, current_time( 'mysql', true ) );
			}
		} finally {
			remove_filter( 'yume_core_notifier', $coupure, 99 );
		}
		$this->rapport['oeuvres'][] = array(
			'oeuvre'    => $oeuvre_id,
			'titre'     => get_the_title( $oeuvre_id ),
			'import'    => $catalogue,
			'chapitres' => count( $lisibles ),
			'crees'     => $this->rapport['crees'] - $avant['crees'],
			'maj'       => $this->rapport['maj'] - $avant['maj'],
			'retires'   => $this->rapport['retires'] - $avant['retires'],
		);
	}

	/**
	 * Crée ou met à jour le chapitre du site d'un chapitre MangaDex.
	 *
	 * @param int                 $oeuvre_id Œuvre.
	 * @param array<string,mixed> $chapitre  Chapitre normalisé.
	 * @param bool                $catalogue Import initial (sans annonce).
	 */
	private function traiter_chapitre( int $oeuvre_id, array $chapitre, bool $catalogue ): void {
		$existant = yume_mangadex_chapitre_par_uuid( (string) $chapitre['id'] );
		$champs   = self::champs_chapitre( $chapitre );
		if ( $existant ) {
			$retire = metadata_exists( 'post', $existant, self::META_RETIRE );
			// La version suit updatedAt : tout changement sur MangaDex (titre, volume, pages) la
			// modifie. Le titre stocké n'est pas comparé (WordPress peut le filtrer à l'écriture).
			$a_jour = (string) get_post_meta( $existant, 'yume_mdx_version', true ) === $chapitre['version'];
			if ( $a_jour && ! $retire ) {
				return;
			}
			// Mis hors ligne par l'équipe (brouillon, licence…) : mis à jour mais compté à part,
			// sans toucher à son tome (qui serait sinon publié).
			$hors_ligne = ! $retire && ! in_array( get_post_status( $existant ), array( 'publish', 'future' ), true );
			// Version changée : le volume a pu être renseigné ou modifié sur MangaDex (un chapitre
			// sorti sans volume rejoint son tome).
			$tome_cible = $a_jour || $hors_ligne ? 0 : $this->tome( $oeuvre_id, (string) $chapitre['volume'], (string) $chapitre['publie'], $catalogue );
			if ( $tome_cible && (int) get_post_meta( $existant, 'yume_tome_id', true ) !== $tome_cible ) {
				$champs['meta']['yume_tome_id'] = $tome_cible;
			}
			if ( $retire ) {
				++$this->rapport['remis_en_ligne'];
			} elseif ( $hors_ligne ) {
				++$this->rapport['maj_hors_ligne'];
			} else {
				++$this->rapport['maj'];
			}
			if ( $this->simulation ) {
				return;
			}
			$donnees = array(
				'ID'         => $existant,
				'post_title' => $champs['titre'],
				'meta_input' => $champs['meta'],
			);
			if ( $retire ) {
				$donnees['post_status'] = 'publish';
				delete_post_meta( $existant, self::META_RETIRE );
			}
			wp_update_post( wp_slash( $donnees ) );
			return;
		}

		++$this->rapport['crees'];
		$tome_id = $this->tome( $oeuvre_id, (string) $chapitre['volume'], (string) $chapitre['publie'], $catalogue );
		if ( $this->simulation ) {
			return;
		}
		if ( ! $tome_id ) {
			--$this->rapport['crees'];
			/* translators: %s : chapitre MangaDex */
			$this->rapport['alertes'][] = sprintf( __( 'Tome introuvable ou impossible à créer pour le chapitre %s.', 'yume-core' ), $chapitre['id'] );
			return;
		}
		$dates = self::dates( (string) $chapitre['publie'] );
		$meta  = array_merge(
			$champs['meta'],
			array(
				'yume_tome_id'   => $tome_id,
				'yume_oeuvre_id' => $oeuvre_id,
			)
		);
		if ( $catalogue ) {
			$meta[ self::META_NOTIFIE ] = 'catalogue';
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'     => 'yume_chapitre',
					'post_status'   => 'publish',
					'post_title'    => $champs['titre'],
					'post_name'     => $champs['slug'],
					'post_content'  => '',
					'menu_order'    => $champs['ordre'],
					'post_date'     => $dates['local'],
					'post_date_gmt' => $dates['gmt'],
					'meta_input'    => $meta,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			--$this->rapport['crees'];
			/* translators: 1 : chapitre MangaDex, 2 : erreur */
			$this->rapport['alertes'][] = sprintf( __( 'Chapitre %1$s non créé : %2$s', 'yume-core' ), $chapitre['id'], $id->get_error_message() );
		}
	}

	/**
	 * Titre, adresse, ordre et métas d'un chapitre du site d'après le chapitre MangaDex.
	 *
	 * @param array<string,mixed> $chapitre Chapitre normalisé.
	 * @return array{titre:string,slug:string,ordre:int,meta:array<string,mixed>}
	 */
	public static function champs_chapitre( array $chapitre ): array {
		$numero     = self::numero( (string) $chapitre['chapitre'] );
		$sous_titre = trim( (string) $chapitre['titre'] );
		if ( null !== $numero ) {
			$libelle = __( 'Chapitre', 'yume-core' ) . ' ' . str_replace( '.', ',', self::numero_texte( $numero ) );
			$slug    = 'chapitre-' . str_replace( '.', '-', self::numero_texte( $numero ) );
			$nature  = 'chapitre';
		} else {
			$libelle = '' !== $sous_titre ? $sous_titre : __( 'Oneshot', 'yume-core' );
			$slug    = sanitize_title( $libelle );
			$nature  = 'bonus';
		}
		$titre = $libelle . ( null !== $numero && '' !== $sous_titre ? ' — ' . $sous_titre : '' );
		$noms  = array_values( array_filter( array_column( (array) $chapitre['groupes'], 'nom' ) ) );
		$meta  = array(
			'yume_nature'       => $nature,
			'yume_sous_titre'   => null !== $numero ? $sous_titre : '',
			'yume_mdx_chapitre' => (string) $chapitre['id'],
			'yume_mdx_pages'    => (int) $chapitre['pages'],
			'yume_mdx_version'  => (string) $chapitre['version'],
			'yume_mdx_groupes'  => $noms,
			'yume_credits'      => array(
				'traduction' => implode( ', ', $noms ),
				'relecture'  => '',
				'edition'    => '',
			),
		);
		if ( null !== $numero ) {
			$meta['yume_numero'] = $numero;
		}
		return array(
			'titre' => $titre,
			'slug'  => $slug,
			'ordre' => null !== $numero ? (int) round( $numero * 10 ) : 0,
			'meta'  => $meta,
		);
	}

	/**
	 * Tome du site pour un volume MangaDex (créé en brouillon s'il manque, publié à la fin du
	 * traitement de l'œuvre).
	 *
	 * @param int    $oeuvre_id Œuvre.
	 * @param string $volume    Volume MangaDex ('' : chapitres sans volume).
	 * @param string $date      Mise en lecture du premier chapitre (ISO 8601).
	 * @param bool   $catalogue Import initial.
	 */
	private function tome( int $oeuvre_id, string $volume, string $date, bool $catalogue ): int {
		$numero = self::numero( $volume );
		$nature = null === $numero ? 'chapitres' : 'tome';
		foreach ( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ) as $tome ) {
			if ( (string) get_post_meta( $tome->ID, 'yume_nature', true ) !== $nature ) {
				continue;
			}
			$sien = self::numero( (string) get_post_meta( $tome->ID, 'yume_numero', true ) );
			if ( $sien === $numero ) {
				if ( 'publish' !== $tome->post_status && ! isset( $this->tomes_a_publier[ (int) $tome->ID ] ) ) {
					// Tome créé par la synchronisation, ou par l'équipe au planning (« Nouveau tome »)
					// et jamais publié : il sort avec son premier chapitre. Un tome déjà paru puis
					// retiré par l'équipe reste hors ligne (alerte).
					if ( ! metadata_exists( 'post', $tome->ID, self::META_NOTIFIE ) || metadata_exists( 'post', $tome->ID, self::META_TOME_RANGE ) ) {
						$this->tomes_a_publier[ (int) $tome->ID ] = array(
							'date'      => $date,
							'catalogue' => $catalogue,
						);
					} elseif ( ! isset( $this->tomes_hors_ligne[ (int) $tome->ID ] ) ) {
						$this->tomes_hors_ligne[ (int) $tome->ID ] = true;
						$this->rapport['alertes'][]                = sprintf(
							/* translators: %s : tome */
							__( '« %s » est hors ligne (retiré par l’équipe) : ses chapitres MangaDex n’y sont pas lisibles tant qu’il n’est pas republié.', 'yume-core' ),
							get_post_field( 'post_title', $tome->ID, 'raw' )
						);
					}
				}
				return (int) $tome->ID;
			}
		}
		$cle = $oeuvre_id . '|' . $nature . '|' . ( null === $numero ? '' : self::numero_texte( $numero ) );
		if ( $this->simulation && isset( $this->tomes_simules[ $cle ] ) ) {
			return -1;
		}
		++$this->rapport['tomes_crees'];
		if ( $this->simulation ) {
			$this->tomes_simules[ $cle ] = true;
			return -1;
		}
		$libelle = null === $numero ? __( 'Chapitres', 'yume-core' ) : __( 'Tome', 'yume-core' ) . ' ' . str_replace( '.', ',', self::numero_texte( $numero ) );
		$meta    = array(
			'yume_oeuvre_id'        => $oeuvre_id,
			'yume_nature'           => $nature,
			'yume_parution'         => 'en_cours',
			self::META_TOME_SYNCHRO => 1,
		);
		if ( null !== $numero ) {
			$meta['yume_numero'] = $numero;
		}
		if ( $catalogue ) {
			$meta[ self::META_NOTIFIE ] = 'catalogue';
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'draft',
					'post_title'  => get_post_field( 'post_title', $oeuvre_id, 'raw' ) . ' — ' . $libelle,
					'post_name'   => null === $numero ? 'chapitres' : 'tome-' . str_replace( '.', '-', self::numero_texte( $numero ) ),
					'menu_order'  => null === $numero ? 100000 : (int) round( $numero * 10 ),
					'meta_input'  => $meta,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			--$this->rapport['tomes_crees'];
			return 0;
		}
		$this->tomes_a_publier[ (int) $id ] = array(
			'date'      => $date,
			'catalogue' => $catalogue,
		);
		return (int) $id;
	}

	/**
	 * Publie les tomes créés (ou restés en brouillon) pendant le traitement de l'œuvre, datés de
	 * leur premier chapitre : core annonce alors le tome et ses chapitres (sortie partielle),
	 * sauf à l'import initial.
	 */
	private function publier_tomes(): void {
		if ( $this->simulation ) {
			return;
		}
		foreach ( $this->tomes_a_publier as $tome_id => $infos ) {
			$dates = self::dates( $infos['date'] );
			if ( $infos['catalogue'] && ! metadata_exists( 'post', $tome_id, self::META_NOTIFIE ) ) {
				update_post_meta( $tome_id, self::META_NOTIFIE, 'catalogue' );
			}
			delete_post_meta( $tome_id, self::META_TOME_RANGE );
			// Tome de l'équipe (planning) : « Planifié » → « En cours de publication ».
			if ( ! in_array( (string) get_post_meta( $tome_id, 'yume_parution', true ), array( 'en_cours', 'complet' ), true ) ) {
				update_post_meta( $tome_id, 'yume_parution', 'en_cours' );
			}
			wp_update_post(
				array(
					'ID'            => $tome_id,
					'post_status'   => 'publish',
					'post_date'     => $dates['local'],
					'post_date_gmt' => $dates['gmt'],
					// Sans edit_date, WordPress date de maintenant un brouillon qu'on publie.
					'edit_date'     => true,
				)
			);
		}
		$this->tomes_a_publier = array();
	}

	/**
	 * Met en brouillon les chapitres MangaDex publiés d'une œuvre absents du flux.
	 *
	 * @param int                $oeuvre_id       Œuvre.
	 * @param array<string,bool> $lisibles        Chapitres présents et lisibles (identifiant => true).
	 * @param bool               $retraits_permis Retraits autorisés.
	 */
	private function retirer_absents( int $oeuvre_id, array $lisibles, bool $retraits_permis ): void {
		if ( ! $retraits_permis ) {
			return;
		}
		foreach ( $this->chapitres_du_site( array( 'publish', 'future' ), $oeuvre_id ) as $uuid => $id ) {
			if ( isset( $lisibles[ $uuid ] ) ) {
				continue;
			}
			++$this->rapport['retires'];
			if ( $this->simulation ) {
				continue;
			}
			update_post_meta( $id, self::META_RETIRE, current_time( 'mysql', true ) );
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}
	}

	/**
	 * Met en brouillon les tomes créés par la synchronisation qui n'ont plus aucun chapitre en
	 * ligne ou programmé (chapitres retirés, ou passés dans leur volume).
	 *
	 * @param int $oeuvre_id Œuvre.
	 */
	private function ranger_tomes_vides( int $oeuvre_id ): void {
		foreach ( yume_get_tomes( $oeuvre_id ) as $tome ) {
			if ( ! metadata_exists( 'post', $tome->ID, self::META_TOME_SYNCHRO ) || yume_get_chapitres( (int) $tome->ID, array( 'status' => array( 'publish', 'future' ) ) ) ) {
				continue;
			}
			++$this->rapport['tomes_vides'];
			if ( ! $this->simulation ) {
				update_post_meta( (int) $tome->ID, self::META_TOME_RANGE, current_time( 'mysql', true ) );
				wp_update_post(
					array(
						'ID'          => (int) $tome->ID,
						'post_status' => 'draft',
					)
				);
			}
		}
	}

	/**
	 * Numéro d'un volume ou d'un chapitre MangaDex (« 3 », « 3.5 »), null s'il n'en a pas.
	 *
	 * @param string $texte Texte.
	 */
	public static function numero( string $texte ): ?float {
		$texte = str_replace( ',', '.', trim( $texte ) );
		return '' !== $texte && is_numeric( $texte ) && (float) $texte >= 0 ? (float) $texte : null;
	}

	/**
	 * Numéro sans zéros inutiles (3, 3.5).
	 *
	 * @param float $numero Numéro.
	 */
	private static function numero_texte( float $numero ): string {
		if ( floor( $numero ) === $numero ) {
			return (string) (int) $numero;
		}
		return rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * Dates locale et GMT (format MySQL) d'une date ISO 8601 de MangaDex ; maintenant si elle
	 * est absente ou invalide.
	 *
	 * @param string $iso Date ISO 8601.
	 * @return array{local:string,gmt:string}
	 */
	private static function dates( string $iso ): array {
		$horodatage = '' !== $iso ? strtotime( $iso ) : false;
		$horodatage = false === $horodatage ? time() : min( $horodatage, time() );
		return array(
			'local' => wp_date( 'Y-m-d H:i:s', $horodatage ),
			'gmt'   => gmdate( 'Y-m-d H:i:s', $horodatage ),
		);
	}
}
