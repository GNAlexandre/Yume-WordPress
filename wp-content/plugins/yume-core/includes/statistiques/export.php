<?php
/**
 * Statistiques, exports CSV (lot C, fichier réservé) : classement des œuvres (yume_voir_statistiques)
 * et liste nominative (yume_voir_lecteurs, journalisée) par admin-post.php?action=yume_statistiques_export
 * et nonce ; UTF-8 avec BOM, séparateur « ; », cellules protégées.
 *
 * Fonctions attendues (plan, §3.6) : url_export(), csv_classement(), csv_lecteurs(),
 * admin_post_export().
 *
 * Bouchon du socle (lot 0) : constantes seulement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Action admin-post.php des exports CSV. */
const ACTION_EXPORT = 'yume_statistiques_export';

/** Action du nonce des exports CSV. */
const NONCE_EXPORT = 'yume_statistiques_export';
