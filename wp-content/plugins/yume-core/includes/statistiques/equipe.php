<?php
/**
 * Statistiques, vue de l’espace équipe (lot D, fichier réservé) : ?vue=statistiques (onglets
 * Aperçu, Trafic, Œuvres, Lecteurs ; fiche d’une œuvre), rendu serveur sans JavaScript, feuille
 * assets/statistiques.css (handle yume-statistiques).
 *
 * Fonctions attendues (plan, §3.8) : declarer_vue() (filtre yume_vues_equipe, priorité 5),
 * url_statistiques().
 *
 * Bouchon du socle (lot 0) : aucune fonction.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;
