<?php
/**
 * Erreur bloquante du convertisseur (fichier illisible, format invalide…).
 *
 * Le message est toujours rédigé en français et destiné à l'équipe : il est affiché
 * tel quel dans le formulaire de publication, l'API REST et l'outil en ligne de commande.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

/**
 * Exception levée par Docx_Converter et Epub_Converter.
 */
class Import_Exception extends \RuntimeException {

	/**
	 * Code machine de l'erreur (ex. « fichier_illisible », « docx_invalide »).
	 *
	 * @var string
	 */
	private string $code_erreur;

	/**
	 * Constructeur.
	 *
	 * @param string          $message     Message en français.
	 * @param string          $code_erreur Code machine.
	 * @param \Throwable|null $precedente  Exception d'origine.
	 */
	public function __construct( string $message, string $code_erreur = 'import_invalide', ?\Throwable $precedente = null ) {
		parent::__construct( $message, 0, $precedente );
		$this->code_erreur = $code_erreur;
	}

	/**
	 * Code machine de l'erreur.
	 */
	public function code_erreur(): string {
		return $this->code_erreur;
	}
}
