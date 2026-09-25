<?php
/**
 * Pilotage de la migration : simulation (plan calculé depuis la base), démarrage de
 * l'exécution ou de l'annulation, avancement par lots limités dans le temps (REST, formulaire
 * d'administration, WP-CLI), verrou, et neutralisation des notifications pendant les lots
 * (filtre yume_core_notifier, e-mails et appels Discord bloqués).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

// Messages d'exception internes, échappés là où ils sont affichés (esc_html dans
// l'administration, WP_Error en JSON pour l'API REST, WP-CLI en console).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Orchestration de la migration.
 */
final class Migration_Runner {

	/**
	 * Budget par défaut d'un lot lancé depuis la page (secondes) : court pour que la barre de
	 * progression avance souvent et que chaque requête reste loin du délai de l'hébergeur.
	 */
	public const BUDGET_REST = 2.0;

	/**
	 * Calcule le plan depuis la base du site.
	 *
	 * @param array $options Options de Site_Source::export().
	 * @return array<string,mixed>
	 */
	public static function calculer_plan( array $options = array() ): array {
		/**
		 * Options de lecture de l'ancien site (domaine, domaines_alias, home, url_medias).
		 *
		 * @param array $options Options de Site_Source::export().
		 */
		$options = (array) apply_filters( 'yume_migration_source_options', $options );
		$export  = Site_Source::export( $options );
		return ( new Migration_Planner( $export, array( 'date_import' => current_time( 'mysql' ) ) ) )->plan();
	}

	/**
	 * Simulation : calcule le plan depuis la base et l'enregistre (aucun contenu modifié).
	 *
	 * @param array $options Options de Site_Source::export().
	 * @return array<string,mixed> Plan.
	 * @throws \RuntimeException Migration en cours ou déjà faite.
	 */
	public static function simuler( array $options = array() ): array {
		$etat = Migration_State::etat();
		if ( in_array( $etat['statut'], array( 'en_cours', 'annulation', 'migre' ), true ) ) {
			throw new \RuntimeException( __( 'Simulation impossible : la migration est en cours ou déjà faite. Annulez-la d’abord pour simuler à nouveau.', 'yume-core' ) );
		}
		$plan = self::calculer_plan( $options );
		Migration_State::enregistrer_plan( $plan, 'simulation' );
		return $plan;
	}

	/**
	 * Démarre une exécution (nouvelle, ou relance forcée d'une migration terminée).
	 *
	 * @param array $options 'forcer' (bool : ignorer les problèmes bloquants, relancer une
	 *                       migration déjà faite), 'source' (options de Site_Source).
	 * @return array<string,mixed> État.
	 * @throws \RuntimeException Démarrage impossible.
	 */
	public static function demarrer_execution( array $options = array() ): array {
		return self::sous_verrou( static fn() => self::demarrer_execution_verrouille( $options ) );
	}

	/**
	 * Démarrage de l'exécution (verrou pris).
	 *
	 * @param array $options Voir demarrer_execution().
	 * @return array<string,mixed> État.
	 * @throws \RuntimeException Démarrage impossible.
	 */
	private static function demarrer_execution_verrouille( array $options ): array {
		$forcer = ! empty( $options['forcer'] );
		$etat   = Migration_State::etat();
		if ( in_array( $etat['statut'], array( 'en_cours', 'annulation' ), true ) ) {
			throw new \RuntimeException( __( 'Une opération est déjà en cours : reprenez-la.', 'yume-core' ) );
		}
		if ( 'migre' === $etat['statut'] ) {
			if ( ! $forcer || ! Migration_State::plan() ) {
				throw new \RuntimeException( __( 'La migration est déjà faite.', 'yume-core' ) );
			}
			// Relance : même plan, même sauvegarde ; les contenus existants sont mis à jour.
			$etat['statut']    = 'en_cours';
			$etat['operation'] = 'executer';
			$etat['etape']     = 'oeuvres';
			$etat['curseur']   = 0;
			$etat['etapes']    = array( 'preparer' );
			$etat['erreur']    = '';
			$etat['comptes']   = array();
			$etat['debut']     = current_time( 'mysql', true );
			$etat['par']       = get_current_user_id();
			Migration_State::message( $etat, __( 'Relance de la migration (mise à jour des contenus existants).', 'yume-core' ) );
			$etat['historique'][] = array(
				'date'   => $etat['debut'],
				'action' => 'relance',
				'par'    => get_current_user_id(),
			);
			$moteur               = new Migration_Executor( (array) Migration_State::plan(), Migration_State::journal(), $etat );
			$etat['progression']  = array(
				'fait'  => $moteur->fait(),
				'total' => $moteur->total_general(),
			);
			Migration_State::enregistrer_etat( $etat );
			return $etat;
		}

		$simulation = Migration_State::meta_plan();
		$plan       = self::calculer_plan( (array) ( $options['source'] ?? array() ) );
		$problemes  = Migration_Executor::problemes( $plan );
		if ( $problemes && ! $forcer ) {
			throw new \RuntimeException( implode( "\n", $problemes ) );
		}
		$meta = Migration_State::enregistrer_plan( $plan, 'execution' );
		$run  = gmdate( 'YmdHis' );

		$journal        = Migration_State::journal_defaut();
		$journal['run'] = $run;
		Migration_State::enregistrer_journal( $journal );

		$nouveau               = Migration_State::etat_defaut();
		$nouveau['statut']     = 'en_cours';
		$nouveau['operation']  = 'executer';
		$nouveau['run']        = $run;
		$nouveau['etape']      = 'preparer';
		$nouveau['debut']      = current_time( 'mysql', true );
		$nouveau['par']        = get_current_user_id();
		$nouveau['historique'] = array_merge(
			(array) $etat['historique'],
			array(
				array(
					'date'   => $nouveau['debut'],
					'action' => 'execution',
					'par'    => get_current_user_id(),
				),
			)
		);
		$nouveau['plan']       = array(
			'signature'            => $meta['signature'],
			'signature_simulation' => $simulation['signature'] ?? '',
			'comptes'              => $meta['comptes'],
		);
		if ( $simulation && ( $simulation['signature'] ?? '' ) !== $meta['signature'] ) {
			Migration_State::message( $nouveau, __( 'Le contenu du site a changé depuis la dernière simulation : le plan a été recalculé.', 'yume-core' ), 'avertissement' );
		}
		foreach ( $problemes as $probleme ) {
			Migration_State::message( $nouveau, $probleme, 'avertissement' );
		}
		Migration_State::message( $nouveau, __( 'Migration démarrée.', 'yume-core' ) );
		$moteur                 = new Migration_Executor( $plan, $journal, $nouveau );
		$nouveau['progression'] = array(
			'fait'  => 0,
			'total' => $moteur->total_general(),
		);
		Migration_State::enregistrer_etat( $nouveau );
		return $nouveau;
	}

	/**
	 * Démarre l'annulation.
	 *
	 * @return array<string,mixed> État.
	 * @throws \RuntimeException Rien à annuler.
	 */
	public static function demarrer_annulation(): array {
		return self::sous_verrou( static fn() => self::demarrer_annulation_verrouille() );
	}

	/**
	 * Démarrage de l'annulation (verrou pris).
	 *
	 * @return array<string,mixed> État.
	 * @throws \RuntimeException Rien à annuler.
	 */
	private static function demarrer_annulation_verrouille(): array {
		$etat = Migration_State::etat();
		if ( 'annulation' === $etat['statut'] ) {
			return $etat;
		}
		if ( ! in_array( $etat['statut'], array( 'migre', 'en_cours' ), true ) ) {
			throw new \RuntimeException( __( 'Aucune migration à annuler.', 'yume-core' ) );
		}
		if ( ! Migration_State::plan() ) {
			throw new \RuntimeException( __( 'Plan de la migration introuvable : annulation impossible.', 'yume-core' ) );
		}
		$journal              = Migration_State::journal();
		$etat['statut']       = 'annulation';
		$etat['operation']    = 'annuler';
		$etat['etape']        = 'redirections';
		$etat['curseur']      = 0;
		$etat['etapes']       = array();
		$etat['erreur']       = '';
		$etat['comptes']      = array();
		$etat['debut']        = current_time( 'mysql', true );
		$etat['par']          = get_current_user_id();
		$etat['annulation']   = Migration_Rollback::listes( $journal );
		$etat['historique'][] = array(
			'date'   => $etat['debut'],
			'action' => 'annulation',
			'par'    => get_current_user_id(),
		);
		Migration_State::message( $etat, __( 'Annulation démarrée.', 'yume-core' ) );
		$moteur              = new Migration_Rollback( (array) Migration_State::plan(), $journal, $etat );
		$etat['progression'] = array(
			'fait'  => 0,
			'total' => $moteur->total_general(),
		);
		Migration_State::enregistrer_etat( $etat );
		return $etat;
	}

	/**
	 * Exécute une fonction sous le verrou de la migration.
	 *
	 * @param callable $fonction Fonction.
	 * @return mixed Valeur de la fonction.
	 * @throws \RuntimeException Verrou déjà pris (code 409).
	 */
	private static function sous_verrou( callable $fonction ) {
		$jeton = Migration_State::verrouiller();
		if ( '' === $jeton ) {
			throw new \RuntimeException( __( 'Une opération de migration est déjà en cours d’exécution (autre onglet ou autre administrateur). Réessayez dans un instant.', 'yume-core' ), 409 );
		}
		try {
			return $fonction();
		} finally {
			Migration_State::deverrouiller( $jeton );
		}
	}

	/**
	 * Fait avancer l'opération en cours d'un lot.
	 *
	 * @param array $options 'budget' (secondes), 'ignorer' (sauter l'élément en erreur),
	 *                       'forcer'.
	 * @return array<string,mixed> État après le lot.
	 * @throws \RuntimeException Verrou pris ou plan introuvable.
	 */
	public static function lot( array $options = array() ): array {
		$etat = Migration_State::etat();
		if ( ! in_array( $etat['statut'], array( 'en_cours', 'annulation' ), true ) ) {
			return $etat;
		}
		$jeton = Migration_State::verrouiller();
		if ( '' === $jeton ) {
			throw new \RuntimeException( __( 'Un lot est déjà en cours d’exécution (autre onglet ou autre administrateur). Réessayez dans un instant.', 'yume-core' ), 409 );
		}
		$neutralisation = self::neutraliser_notifications();
		try {
			$etat = Migration_State::etat();
			$plan = Migration_State::plan();
			if ( null === $plan ) {
				throw new \RuntimeException( __( 'Plan de la migration introuvable.', 'yume-core' ) );
			}
			$classe = 'annulation' === $etat['statut'] ? Migration_Rollback::class : Migration_Executor::class;
			$moteur = new $classe(
				$plan,
				Migration_State::journal(),
				$etat,
				array(
					'forcer' => ! empty( $options['forcer'] ),
					'choix'  => Migration_State::choix(),
				)
			);
			if ( ! empty( $options['ignorer'] ) ) {
				$moteur->ignorer_element();
			}
			$sauver = static function ( Migration_Moteur $m ): void {
				Migration_State::enregistrer_journal( $m->journal() );
				Migration_State::enregistrer_etat( $m->etat() );
			};
			/**
			 * Budget de temps (secondes) d'un lot de migration.
			 *
			 * @param float $budget Budget.
			 */
			$budget = (float) apply_filters( 'yume_migration_budget', (float) ( $options['budget'] ?? self::BUDGET_REST ) );
			$moteur->avancer( $budget, $sauver );
			$sauver( $moteur );
			return $moteur->etat();
		} finally {
			$neutralisation();
			Migration_State::deverrouiller( $jeton );
		}
	}

	/**
	 * Exécute (ou annule) jusqu'au bout, lot après lot (WP-CLI, tests).
	 *
	 * @param callable|null $suivi Appelée après chaque lot avec l'état.
	 * @param array         $options Options de lot().
	 * @return array<string,mixed> État final.
	 */
	public static function terminer( ?callable $suivi = null, array $options = array() ): array {
		$options = array_merge( array( 'budget' => 30.0 ), $options );
		$etat    = Migration_State::etat();
		$garde   = 0;
		while ( in_array( $etat['statut'], array( 'en_cours', 'annulation' ), true ) && $garde < 10000 ) {
			$etat = self::lot( $options );
			if ( $suivi ) {
				$suivi( $etat );
			}
			if ( '' !== (string) $etat['erreur'] ) {
				break;
			}
			++$garde;
		}
		return $etat;
	}

	/**
	 * Pendant un lot : aucun événement de publication (yume_core_notifier), aucun e-mail,
	 * aucun appel Discord, pas de filtrage kses des contenus migrés (issus du site lui-même).
	 *
	 * @return callable Fonction qui rétablit l'état d'origine.
	 */
	public static function neutraliser_notifications(): callable {
		$mail    = static fn() => false;
		$discord = static function ( $reponse, $args, $url ) {
			$hote = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
			if ( preg_match( '/(^|\.)(discord\.com|discordapp\.com)$/', $hote ) ) {
				return new \WP_Error( 'yume_migration', __( 'Aucun appel Discord pendant la migration.', 'yume-core' ) );
			}
			return $reponse;
		};
		add_filter( 'yume_core_notifier', '__return_false', 99 );
		add_filter( 'pre_wp_mail', $mail, 99 );
		add_filter( 'pre_http_request', $discord, 99, 3 );
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		wp_defer_term_counting( true );
		return static function () use ( $mail, $discord, $kses ): void {
			wp_defer_term_counting( false );
			if ( $kses ) {
				kses_init_filters();
			}
			remove_filter( 'pre_http_request', $discord, 99 );
			remove_filter( 'pre_wp_mail', $mail, 99 );
			remove_filter( 'yume_core_notifier', '__return_false', 99 );
		};
	}

	/**
	 * Résumé de l'état pour l'interface (REST, JavaScript).
	 *
	 * @param array $etat État.
	 * @return array<string,mixed>
	 */
	public static function resume( array $etat ): array {
		$etapes  = 'annulation' === $etat['statut'] || 'annuler' === $etat['operation'] ? Migration_Rollback::etapes() : Migration_Executor::etapes();
		$total   = max( 0, (int) ( $etat['progression']['total'] ?? 0 ) );
		$fait    = min( $total, max( 0, (int) ( $etat['progression']['fait'] ?? 0 ) ) );
		$termine = ! in_array( $etat['statut'], array( 'en_cours', 'annulation' ), true );
		return array(
			'statut'         => $etat['statut'],
			'statut_libelle' => Migration_State::STATUTS[ $etat['statut'] ] ?? $etat['statut'],
			'operation'      => $etat['operation'],
			'etape'          => $etat['etape'],
			'etape_libelle'  => $etapes[ $etat['etape'] ] ?? '',
			'fait'           => $fait,
			'total'          => $total,
			'pourcentage'    => $total ? (int) floor( $fait * 100 / $total ) : ( $termine ? 100 : 0 ),
			'termine'        => $termine,
			'erreur'         => (string) $etat['erreur'],
			'messages'       => array_slice( (array) $etat['messages'], -15 ),
			'comptes'        => (array) $etat['comptes'],
			'controle'       => (array) ( $etat['controle'] ?? array() ),
		);
	}
}
