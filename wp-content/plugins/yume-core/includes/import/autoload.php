<?php
/**
 * Chargement automatique des classes du convertisseur (espace de noms Yume\Core\Import).
 *
 * Ce fichier n'utilise aucune fonction WordPress : il est inclus par le module « import »
 * et par l'outil en ligne de commande tools/docx2chapters/docx2chapters.php.
 * Correspondance : Yume\Core\Import\Docx_Converter → class-docx-converter.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

if ( ! function_exists( __NAMESPACE__ . '\\charger_classe' ) ) {
	/**
	 * Charge le fichier d'une classe de l'espace de noms Yume\Core\Import.
	 *
	 * @param string $classe Nom complet de la classe.
	 */
	function charger_classe( string $classe ): void {
		$prefixe = __NAMESPACE__ . '\\';
		if ( 0 !== strncmp( $classe, $prefixe, strlen( $prefixe ) ) ) {
			return;
		}
		$nom     = substr( $classe, strlen( $prefixe ) );
		$fichier = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $nom ) ) . '.php';
		if ( is_readable( $fichier ) ) {
			require_once $fichier;
		}
	}
	spl_autoload_register( __NAMESPACE__ . '\\charger_classe' );
}
