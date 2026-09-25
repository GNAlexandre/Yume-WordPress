<?php
/**
 * Rendu du bloc yume/publish-form (voir Formulaire::rendu()).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

// Tout le HTML est échappé dans Formulaire::rendu() et le gabarit.
echo \Yume\Core\Publication\Formulaire::rendu( is_array( $attributes ?? null ) ? $attributes : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
