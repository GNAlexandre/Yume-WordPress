<?php
/**
 * Statistiques, listes nominatives (lot C, fichier réservé) : favoris et suiveurs d’une œuvre
 * (pseudo, date d’ajout, fréquence d’alerte, badge équipe ; jamais d’e-mail ni d’identifiant de
 * connexion), réservées à la capacité yume_voir_lecteurs ; journal des exports nominatifs.
 *
 * Fonctions attendues (plan, §3.6) : peut_voir_lecteurs(), lecteurs_oeuvre(),
 * journaliser_export(), exports_recents().
 *
 * Bouchon du socle (lot 0) : aucune fonction.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;
