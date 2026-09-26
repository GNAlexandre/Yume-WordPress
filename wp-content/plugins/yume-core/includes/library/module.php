<?php
/**
 * Module « bibliothèque » : blocs d'affichage publics (contrat §10).
 *
 * - En-tête et accueil : yume/library-menu, yume/banner, yume/latest-releases ;
 * - bibliothèque : yume/library-grid (filtres GET type, statut, genre, tri) ;
 * - fiche d'une œuvre : yume/oeuvre-header, yume/oeuvre-infos, yume/tome-list ;
 * - page d'un tome : yume/tome-header, yume/tome-toc ;
 * - lecteur : yume/chapter-header, yume/chapter-nav.
 *
 * Données publiques de schema.org (JSON-LD) et balises rel=prev/next dans <head> : seo.php.
 * Ordre des résultats de la recherche (œuvres correspondantes d'abord) : recherche.php.
 * Les listes calculées sont mises en cache (transients versionnés, voir donnees.php) et
 * invalidées à chaque enregistrement ou suppression d'une œuvre, d'un tome ou d'un chapitre.
 *
 * Au chargement, ce module n'appelle aucune fonction d'un autre module : les appels à
 * l'API du module core (yume_get_tomes(), yume_libelle_tome()…) se font pendant le rendu.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/donnees.php';
require_once __DIR__ . '/composants.php';
require_once __DIR__ . '/rendus-accueil.php';
require_once __DIR__ . '/rendus-fiches.php';
require_once __DIR__ . '/rendus-lecture.php';
require_once __DIR__ . '/blocs.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/recherche.php';
