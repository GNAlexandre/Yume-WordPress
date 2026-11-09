<?php
/**
 * Module « mise-en-page » (2.9.0, lot E de docs/10-mise-en-page-chapitre.md) : vue d'équipe
 * « Mettre en page un chapitre » (?vue=chapitre&id=ID, hors menu).
 *
 * - Unités (class-unites.php) : le contenu du chapitre, suite de blocs Gutenberg produits par
 *   Import\Blocks, est vu comme une liste de paragraphes (Récit, Dialogue, Pensée, Centré),
 *   séparateurs de scène, intertitres et blocs intouchables (images, notes).
 * - REST (rest.php) : GET et PUT /yume/v1/chapitres/{id}/mise-en-page, POST …/presence.
 *   Enregistrement par wp_update_post (révision), sans annonce, journal « mise_en_page » privé.
 * - Vue (vue.php, assets/editeur.js, assets/editeur.css) : colonne de lecture identique au site,
 *   palette de styles, texte en place, actions groupées, annuler / rétablir.
 *
 * Contrat : docs/06-contrat-technique.md §7, §10, §12. Les fonctions des modules import,
 * publication et planning ne sont appelées que dans des callbacks (§0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\MiseEnPage;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/class-unites.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/vue.php';
