<?php
/**
 * Module « discord » : connexion et création de compte avec Discord (OAuth2 Authorization Code +
 * PKCE S256, scopes identify et email), liaison et déliaison depuis Mon compte (lot L2 du chantier
 * « lecteurs », contrat : docs/06-contrat-technique.md §8 quinquies).
 *
 * - fonctions.php : configuration (constantes > réglages), métas, liaison, comptes refusés ;
 * - client.php    : appels HTTP à Discord (jeton, profil, révocation), jamais de jeton stocké ;
 * - connexion.php : départ (admin-post yume_discord_depart), retour REST GET /discord/retour,
 *                   résolution (traiter_retour()), ouverture de session, boutons visiteur ;
 * - compte.php    : rubrique « Connexion Discord » de Mon compte, déliaison (admin-post yume_discord_delier) ;
 * - reglages.php  : section « Connexion Discord » de Yume → Réglages, test Santé du site yume_discord ;
 * - rgpd.php      : export (clé discord, exportateur yume-core-discord) et effacement des métas.
 *
 * Tant que discord_configure() est faux, le site est inchangé : aucun bouton, route de retour en
 * 404 (yume_discord_inactif). Au chargement, les fichiers ne font qu'accrocher des hooks (§0) ; les
 * fonctions du module social ne sont appelées que dans des callbacks (discord_configure() est faux
 * sans lui).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/compte.php';
require_once __DIR__ . '/reglages.php';
require_once __DIR__ . '/rgpd.php';
