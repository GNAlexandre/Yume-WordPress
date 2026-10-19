<?php
/**
 * Statistiques, historique quotidien (lot B, fichier réservé) : calcul des lignes de la table
 * yume_stats_jour (site et chaque œuvre publiée), rattrapage des jours manquants, purge au-delà de
 * la rétention, séries pour les graphiques.
 *
 * Fonctions attendues (plan, §3.5) : calculer_jour(), tache_quotidienne(), serie(), points(),
 * reconstituer_favoris(). tache_quotidienne() est appelée par lancer_tache_jour() (install.php),
 * déjà accrochée à HOOK_JOUR : ne pas l’accrocher une seconde fois.
 *
 * Bouchon du socle (lot 0) : aucune fonction.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;
