<?php
/**
 * Statistiques, œuvres (lot B, fichier réservé) : compteurs par œuvre (favoris, suiveurs alertés,
 * listes de lecture, lecteurs, lecteurs actifs sur 30 jours, chapitres lus estimés), classements,
 * fiche statistique d’une œuvre et résumé des lecteurs du site (agrégats seulement).
 *
 * Fonctions attendues (plan, §3.5) : compteurs_oeuvres(), compteurs_oeuvre(), classement(),
 * fiche_oeuvre(), resume_lecteurs() ; invalidation du cache (cache_vider()) sur
 * yume_favori_ajoute, yume_favori_retire, yume_tome_publie, yume_chapitre_publie.
 *
 * Bouchon du socle (lot 0) : aucune fonction.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;
