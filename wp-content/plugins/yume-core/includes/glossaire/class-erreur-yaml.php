<?php
/**
 * Erreur de lecture d'un glossaire YAML (voir class-lecteur-yaml.php).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

/**
 * Erreur de lecture YAML (message en français, numéro de ligne à partir de 1, 0 si inconnu).
 */
class Erreur_Yaml extends \RuntimeException {

	/**
	 * Ligne fautive.
	 *
	 * @var int
	 */
	public int $ligne;

	/**
	 * Constructeur.
	 *
	 * @param string $message Message.
	 * @param int    $ligne   Ligne (1 = première).
	 */
	public function __construct( string $message, int $ligne = 0 ) {
		$this->ligne = $ligne;
		parent::__construct( $ligne > 0 ? sprintf( 'Ligne %d : %s', $ligne, $message ) : $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut, échappé à l'affichage.
	}
}
