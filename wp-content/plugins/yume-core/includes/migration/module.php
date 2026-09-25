<?php
/**
 * Module « migration » : analyse de l'ancien site (pages, articles) et plan de migration.
 *
 * Partie 1 (ce dossier) : classes d'analyse et de transformation PURES (aucun accès à la base
 * de données) chargées à la demande par un chargeur automatique. Elles transforment l'export
 * du site (tools/migrate/export/, ou le même format construit depuis la base) en un plan de
 * migration sérialisable en JSON (voir Migration_Planner). L'exécution du plan (création des
 * contenus, redirections, page Yume → Migrer) s'appuie sur ce plan.
 *
 * Au chargement, ce fichier n'appelle aucune fonction d'un autre module : il ne fait
 * qu'enregistrer le chargeur de classes (voir docs/06-contrat-technique.md §0).
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
