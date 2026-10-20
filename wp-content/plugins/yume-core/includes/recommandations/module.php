<?php
/**
 * Module « recommandations » (lot L3 du chantier « lecteurs », docs/06 §8 sexies) :
 * « Si vous avez aimé… » sur la fiche d'une œuvre et rubrique « Pour vous » de Mon compte.
 *
 * - fonctions.php : candidates, score (genres, type, co-favoris agrégés à partir de 3 lecteurs),
 *   cache versionné (transients yume_reco_{oeuvre}_{version}) et invalidation par événements ;
 * - rendu.php     : bloc yume/recommandations (blocks/recommandations) et cartes partagées ;
 * - compte.php    : rubrique « Pour vous » (#yn-pour-vous) par les filtres de la page compte ;
 * - rest.php      : GET /yume/v1/recommandations/{oeuvre} (publique) et /moi/recommandations.
 *
 * Aucune requête externe, aucune table, aucun réglage (filtres seulement). Au chargement, le
 * module n'appelle aucune fonction d'un autre module : il accroche des hooks ; les appels à
 * Social\*, Library\* et à l'API du core se font dans des callbacks, gardés par function_exists.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Recommandations;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/rendu.php';
require_once __DIR__ . '/compte.php';
require_once __DIR__ . '/rest.php';
