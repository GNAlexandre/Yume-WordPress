<?php
/**
 * Module « import » : convertisseurs DOCX et EPUB → chapitres (docs/04 §2 et §3, contrat §9).
 *
 * Les classes de l'espace de noms Yume\Core\Import n'utilisent aucune fonction WordPress :
 * elles sont aussi chargées par l'outil en ligne de commande tools/docx2chapters/.
 * Le module n'accroche aucun hook : le module « publication » appelle
 * Docx_Converter::convert_file() et Epub_Converter::convert_file().
 *
 * @package Yume\Core
 */

namespace Yume\Core\Import;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/autoload.php';
