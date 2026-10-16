<?php
/**
 * Module « wordend » : easter egg WordEnd (SukaSuka). Mini-jeu 2D où Chtholly élimine des
 * Timeres, ouvert par le code Konami en façade, par un appui long sur la bascule de thème
 * (écrans tactiles) ou par le papillon discret de la fiche de l'œuvre.
 *
 * Seul un petit script déclencheur (defer) est chargé sur les pages publiques ; le jeu, sa
 * feuille de style et la planche de Chtholly sont chargés à la demande.
 *
 * Contrat : docs/06-contrat-technique.md (§1, §14) ; documentation : docs/wordend.md.
 * Au chargement, les fichiers ne font qu'accrocher des hooks (§0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\WordEnd;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/facade.php';
