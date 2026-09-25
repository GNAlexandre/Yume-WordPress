<?php
/**
 * Commande WP-CLI « wp yume migrer ».
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Migre l'ancien contenu de yumenovel.fr vers les œuvres, tomes et chapitres Yume.
 */
final class Migration_Cli {

	/**
	 * Simule, exécute ou annule la migration de l'ancien site.
	 *
	 * Sans option, exécute la migration (après confirmation) jusqu'au bout ; une exécution
	 * interrompue reprend où elle s'était arrêtée.
	 *
	 * ## OPTIONS
	 *
	 * [--simuler]
	 * : Calcule le plan depuis la base et affiche les comptes et avertissements, sans rien modifier.
	 *
	 * [--annuler]
	 * : Annule la migration (supprime ce qu'elle a créé, restaure pages, articles, catégories et options).
	 *
	 * [--etat]
	 * : Affiche l'état de la migration.
	 *
	 * [--rapport=<fichier>]
	 * : Avec --simuler, écrit le rapport Markdown dans ce fichier.
	 *
	 * [--plan=<fichier>]
	 * : Avec --simuler, écrit le plan JSON dans ce fichier.
	 *
	 * [--forcer]
	 * : Exécute malgré les erreurs du plan, ou relance une migration terminée (mise à jour sans doublon).
	 *
	 * [--ignorer]
	 * : Saute l'élément en erreur avant de reprendre.
	 *
	 * [--yes]
	 * : Ne demande pas de confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yume migrer --simuler --rapport=rapport.md
	 *     wp yume migrer --yes
	 *     wp yume migrer --annuler --yes
	 *
	 * @param array $args       Arguments positionnels.
	 * @param array $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! empty( $assoc_args['etat'] ) ) {
			$this->etat();
			return;
		}
		if ( ! empty( $assoc_args['simuler'] ) ) {
			$this->simuler( $assoc_args );
			return;
		}
		if ( ! empty( $assoc_args['annuler'] ) ) {
			$this->annuler( $assoc_args );
			return;
		}
		$this->executer( $assoc_args );
	}

	/**
	 * Affiche l'état.
	 */
	private function etat(): void {
		$resume = Migration_Runner::resume( Migration_State::etat() );
		\WP_CLI::log( sprintf( 'Statut : %s', $resume['statut_libelle'] ) );
		if ( ! $resume['termine'] ) {
			\WP_CLI::log( sprintf( 'Étape : %s (%d / %d)', $resume['etape_libelle'], $resume['fait'], $resume['total'] ) );
		}
		if ( '' !== $resume['erreur'] ) {
			\WP_CLI::warning( $resume['erreur'] );
		}
		foreach ( $resume['comptes'] as $cle => $n ) {
			\WP_CLI::log( sprintf( '  %s : %d', $cle, $n ) );
		}
	}

	/**
	 * Simulation.
	 *
	 * @param array $assoc_args Options.
	 */
	private function simuler( array $assoc_args ): void {
		try {
			$plan = Migration_Runner::simuler();
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		$c = $plan['comptes'];
		\WP_CLI::log( sprintf( 'Œuvres : %d · Tomes : %d · Chapitres : %d (%d migrés, %d planifiés)', $c['oeuvres']['total'], $c['tomes']['total'], $c['chapitres']['total'], $c['chapitres']['migres'], $c['chapitres']['planifies'] ) );
		\WP_CLI::log( sprintf( 'Articles : %d · Redirections : %d · Pages : %s', $c['articles']['total'], $c['redirections'], wp_json_encode( $c['pages_plan'] ) ) );
		\WP_CLI::log( sprintf( 'Avertissements : %s', wp_json_encode( $c['avertissements'] ) ) );
		foreach ( Migration_Admin::statuts_a_valider( $plan ) as $ligne ) {
			\WP_CLI::log( sprintf( '  Statut à valider — %s : %s (hub « %s », fiche « %s »)', $ligne['titre'], $ligne['statut'], $ligne['hub'], $ligne['fiche'] ) );
		}
		foreach ( Migration_Executor::problemes( $plan ) as $probleme ) {
			\WP_CLI::warning( $probleme );
		}
		$fichiers = array(
			'rapport' => static fn() => Plan_Report::markdown( $plan ),
			'plan'    => static fn() => (string) wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
		foreach ( $fichiers as $option => $contenu ) {
			if ( ! empty( $assoc_args[ $option ] ) ) {
				$chemin = (string) $assoc_args[ $option ];
				if ( false === file_put_contents( $chemin, $contenu() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					\WP_CLI::error( sprintf( 'Écriture impossible : %s', $chemin ) );
				}
				\WP_CLI::log( sprintf( 'Écrit : %s', $chemin ) );
			}
		}
		\WP_CLI::success( 'Simulation enregistrée (aucun contenu modifié).' );
	}

	/**
	 * Suivi d'un lot.
	 *
	 * @param array $etat État.
	 */
	private function suivre( array $etat ): void {
		$resume = Migration_Runner::resume( $etat );
		\WP_CLI::log( sprintf( '[%3d %%] %s — %d / %d', $resume['pourcentage'], '' !== $resume['etape_libelle'] ? $resume['etape_libelle'] : $resume['statut_libelle'], $resume['fait'], $resume['total'] ) );
	}

	/**
	 * Fin d'une opération : succès ou erreur.
	 *
	 * @param array  $etat   État final.
	 * @param string $succes Message de succès.
	 */
	private function conclure( array $etat, string $succes ): void {
		if ( '' !== (string) $etat['erreur'] ) {
			\WP_CLI::error( $etat['erreur'] . ' — relancez la commande pour reprendre (--ignorer pour sauter l’élément).' );
		}
		foreach ( (array) $etat['comptes'] as $cle => $n ) {
			\WP_CLI::log( sprintf( '  %s : %d', $cle, $n ) );
		}
		$controle = (array) ( $etat['controle'] ?? array() );
		if ( 'annule' === $etat['statut'] && ! empty( $controle['verifie'] ) ) {
			if ( empty( $controle['differences'] ) ) {
				\WP_CLI::log( 'Contrôle : contenus touchés revenus à leur état d’origine.' );
			} else {
				\WP_CLI::warning( 'Contrôle : différences — ' . implode( ', ', (array) $controle['differences'] ) );
			}
		}
		\WP_CLI::success( $succes );
	}

	/**
	 * Exécution.
	 *
	 * @param array $assoc_args Options.
	 */
	private function executer( array $assoc_args ): void {
		$etat   = Migration_State::etat();
		$forcer = ! empty( $assoc_args['forcer'] );
		if ( 'annulation' === $etat['statut'] ) {
			\WP_CLI::error( 'Une annulation est en cours : reprenez-la avec --annuler.' );
		}
		if ( 'migre' === $etat['statut'] && ! $forcer ) {
			\WP_CLI::log( sprintf( 'La migration est déjà faite (%s GMT). Utilisez --forcer pour la relancer.', $etat['migre_le'] ) );
			return;
		}
		if ( 'en_cours' !== $etat['statut'] ) {
			\WP_CLI::confirm( 'Exécuter la migration de l’ancien site sur cette base ?', $assoc_args );
			try {
				Migration_Runner::demarrer_execution( array( 'forcer' => $forcer ) );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
		}
		$options = array(
			'budget'  => 30.0,
			'ignorer' => ! empty( $assoc_args['ignorer'] ),
		);
		try {
			$etat = Migration_Runner::lot( $options );
			$this->suivre( $etat );
			if ( '' === (string) $etat['erreur'] ) {
				$etat = Migration_Runner::terminer( fn( array $e ) => $this->suivre( $e ), array( 'budget' => 30.0 ) );
			}
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->conclure( $etat, 'Migration terminée.' );
	}

	/**
	 * Annulation.
	 *
	 * @param array $assoc_args Options.
	 */
	private function annuler( array $assoc_args ): void {
		$etat = Migration_State::etat();
		if ( 'annulation' !== $etat['statut'] ) {
			if ( ! in_array( $etat['statut'], array( 'migre', 'en_cours' ), true ) ) {
				\WP_CLI::error( 'Aucune migration à annuler.' );
			}
			\WP_CLI::confirm( 'Annuler la migration et revenir à l’ancien site ?', $assoc_args );
			try {
				Migration_Runner::demarrer_annulation();
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
		}
		try {
			$etat = Migration_Runner::lot(
				array(
					'budget'  => 30.0,
					'ignorer' => ! empty( $assoc_args['ignorer'] ),
				)
			);
			$this->suivre( $etat );
			if ( '' === (string) $etat['erreur'] ) {
				$etat = Migration_Runner::terminer( fn( array $e ) => $this->suivre( $e ), array( 'budget' => 30.0 ) );
			}
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->conclure( $etat, 'Migration annulée.' );
	}
}
