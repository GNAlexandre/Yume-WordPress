<?php
/**
 * Rendu du bloc yume/theme-toggle : bouton bascule Nuit ↔ Papier.
 *
 * Le bouton n'est visible qu'avec JavaScript (classe html.yn-js posée par le script
 * d'initialisation). Son nom accessible est « Thème clair » et son état est porté par
 * aria-pressed (mis à jour par assets/js/yume.js ; « false » par défaut, thème Nuit).
 *
 * @package Yume
 *
 * @var array    $attributes Attributs du bloc.
 * @var string   $content    Contenu interne (vide).
 * @var WP_Block $block      Instance du bloc.
 */

defined( 'ABSPATH' ) || exit;

$yume_bascule_attributs = get_block_wrapper_attributes(
	array(
		'class'                => 'yn-theme-toggle',
		'type'                 => 'button',
		'aria-pressed'         => 'false',
		'data-yn-theme-toggle' => 'nuit-papier',
		'title'                => __( 'Passer au thème Papier (clair)', 'yume' ),
	)
);
?>
<button <?php echo $yume_bascule_attributs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé par get_block_wrapper_attributes(). ?>>
	<svg class="yn-theme-toggle__icone yn-theme-toggle__icone--lune" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
	<svg class="yn-theme-toggle__icone yn-theme-toggle__icone--soleil" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
	<span class="yn-visually-hidden"><?php esc_html_e( 'Thème clair', 'yume' ); ?></span>
</button>
