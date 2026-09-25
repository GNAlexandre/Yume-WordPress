<?php
/**
 * Thème Yume : amorçage.
 *
 * Le thème est un thème bloc autonome : il ne dépend d'aucune fonction de l'extension
 * Yume Core. Quand une fonction de l'extension est utile (URL des pages, réglages), elle est
 * appelée derrière un function_exists() avec une valeur de repli raisonnable.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

define( 'YUME_THEME_DIR', get_template_directory() );

require_once YUME_THEME_DIR . '/inc/setup.php';
require_once YUME_THEME_DIR . '/inc/assets.php';
require_once YUME_THEME_DIR . '/inc/liens.php';
require_once YUME_THEME_DIR . '/inc/blocs.php';
require_once YUME_THEME_DIR . '/inc/commentaires.php';
require_once YUME_THEME_DIR . '/inc/gabarits.php';
