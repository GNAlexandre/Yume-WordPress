<?php
/**
 * Statistiques, trafic (lot A, fichier réservé) : adaptateur Jetpack (WordPress.com Stats,
 * classe Automattic\Jetpack\Stats\WPCOM_Stats), cache yume_stats_trafic_* 15 minutes,
 * dégradation propre (Jetpack absent ou non connecté : source « aucune »).
 *
 * Fonctions attendues (plan, §3.4) : jetpack_disponible(), source_trafic(), requete_stats(),
 * trafic_resume(), trafic_contenus(), trafic_referents(), trafic_recherches(), trafic_pays(),
 * trafic_post(), trafic_vues_par_oeuvre_jour(), trafic_visites_jour(), derniere_erreur_trafic() ;
 * filtres yume_statistiques_source_trafic et yume_statistiques_requete_stats.
 *
 * Bouchon du socle (lot 0) : aucune fonction. Les routes REST (rest.php) répondent 503
 * yume_statistiques_indisponible tant que la fonction appelée n’existe pas.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;
