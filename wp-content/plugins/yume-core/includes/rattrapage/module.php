<?php
/**
 * Module « rattrapage » (lot L5) : « Nouveaux chapitres depuis votre dernière lecture ».
 *
 * Pour chaque série commencée par un membre (table progression), les chapitres publiés après
 * sa position dans l'ordre de lecture (Reader\plan_de_lecture()) : section de l'accueil (bloc
 * yume/rattrapage, dans templates/front-page.html), rubrique « À rattraper » de Mon compte
 * (#yn-rattrapage, après « Reprendre la lecture ») et route GET /yume/v1/moi/rattrapage.
 *
 * Cache de page (Batcache) : une page anonyme est servie à tous les visiteurs ; le bloc ne
 * produit donc RIEN pour un visiteur (chaîne vide, aucun gabarit caché, aucune donnée), et ne
 * se rend que côté serveur pour un membre connecté (requête jamais mise en cache).
 *
 * Fichiers : fonctions.php (calcul, cache, rendu), rest.php (route), compte.php (rubrique),
 * blocks/rattrapage/ (bloc). Contrat : docs/06-contrat-technique.md §8 sexies, §10, §12.
 * Au chargement, les fichiers du module ne font qu'accrocher des hooks (§0) ; les modules
 * reader et social ne sont appelés que dans des callbacks, gardés par function_exists.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Rattrapage;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/compte.php';
