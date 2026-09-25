<?php
/**
 * Module « migration » : passage de l'ancien site yumenovel.fr (pages et articles) aux types
 * Yume, sur place, au Go de l'équipe.
 *
 * - Analyse (classes pures) : Html, Blocks, Export_Loader, analyseurs Legacy_*,
 *   Migration_Planner (plan JSON), Plan_Report (rapport Markdown, CSV des redirections).
 * - Source : Site_Source lit l'ancien contenu dans la base (même entrée qu'Export_Loader).
 * - Exécution : Migration_Runner (lots, verrou, notifications neutralisées),
 *   Migration_Executor (étapes), Migration_Rollback (annulation), Migration_State (état,
 *   journal, plan), Media_Mapper, Migration_Empreinte (contrôle après annulation).
 * - Redirections 301 : Redirections (table en option, template_redirect priorité 1).
 * - Interface : Yume → Migrer (Migration_Admin), REST yume/v1/migration (Migration_Rest),
 *   WP-CLI « wp yume migrer » (Migration_Cli).
 *
 * Au chargement, ce fichier n'appelle aucune fonction d'un autre module : il enregistre le
 * chargeur de classes et accroche des hooks (docs/06-contrat-technique.md §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\charger_classe' ) ) {
	/**
	 * Chargeur automatique des classes Yume\Core\Migration\Nom_De_Classe
	 * depuis includes/migration/class-nom-de-classe.php.
	 *
	 * @param string $classe Nom complet de la classe demandée.
	 */
	function charger_classe( string $classe ): void {
		$prefixe = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $classe, $prefixe ) ) {
			return;
		}
		$nom = substr( $classe, strlen( $prefixe ) );
		if ( '' === $nom || false !== strpos( $nom, '\\' ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $nom ) ) {
			return;
		}
		$fichier = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $nom ) ) . '.php';
		if ( is_readable( $fichier ) ) {
			require_once $fichier;
		}
	}

	spl_autoload_register( __NAMESPACE__ . '\\charger_classe' );
}

if ( ! function_exists( __NAMESPACE__ . '\\accrocher' ) ) {
	/**
	 * Accroche les hooks du module (une seule fois, même si le fichier est relu par les tests).
	 */
	function accrocher(): void {
		// Redirections 301 des anciennes URL : avant la redirection canonique du cœur (priorité
		// 9), celle de WordPress (10) et le modèle 404.
		add_action( 'template_redirect', array( Redirections::class, 'gerer' ), 1 );

		// Administration : Yume → Migrer et actions des formulaires.
		add_action( 'admin_menu', array( Migration_Admin::class, 'menu' ), 20 );
		add_action( 'admin_post_yume_migration_simuler', array( Migration_Admin::class, 'action_simuler' ) );
		add_action( 'admin_post_yume_migration_choix', array( Migration_Admin::class, 'action_choix' ) );
		add_action( 'admin_post_yume_migration_executer', array( Migration_Admin::class, 'action_executer' ) );
		add_action( 'admin_post_yume_migration_annuler', array( Migration_Admin::class, 'action_annuler' ) );
		add_action( 'admin_post_yume_migration_telecharger', array( Migration_Admin::class, 'action_telecharger' ) );

		// API REST (exécution par lots depuis la page).
		add_action( 'rest_api_init', array( Migration_Rest::class, 'routes' ) );

		// WP-CLI : wp yume migrer [--simuler] [--annuler].
		add_action(
			'cli_init',
			static function (): void {
				if ( class_exists( '\WP_CLI' ) ) {
					\WP_CLI::add_command( 'yume migrer', Migration_Cli::class );
				}
			}
		);

		// Désactivation de l'extension : le verrou éventuel est libéré.
		add_action(
			'yume_core_deactivate',
			static function (): void {
				delete_option( Migration_State::OPTION_VERROU );
			}
		);
	}

	accrocher();
}
