<?php
/**
 * Title: Bandeau Ko-fi / Discord
 * Slug: yume/bandeau-kofi-discord
 * Categories: yume, call-to-action
 * Keywords: ko-fi, discord, soutien, communauté, don
 * Description: Bandeau dégradé qui invite à rejoindre le Discord et à soutenir l'équipe sur Ko-fi (adresses issues des réglages Yume).
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","className":"yn-card yn-bandeau-reprise yn-bandeau-soutien","layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group alignwide yn-card yn-bandeau-reprise yn-bandeau-soutien"><!-- wp:group {"className":"yn-bandeau-reprise__repli","layout":{"type":"default"}} -->
<div class="wp-block-group yn-bandeau-reprise__repli"><!-- wp:paragraph {"className":"yn-label"} -->
<p class="yn-label"><?php esc_html_e( 'Communauté', 'yume' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"yn-bandeau-reprise__titre"} -->
<p class="yn-bandeau-reprise__titre"><?php esc_html_e( 'Suivez les sorties avec l’équipe et soutenez les traductions', 'yume' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"yn-muted"} -->
<p class="yn-muted"><?php esc_html_e( 'Yume Novel est une équipe bénévole : chaque don sur Ko-fi finance les licences de logiciels et l’hébergement.', 'yume' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"yn-lien-discord"} -->
<div class="wp-block-button yn-lien-discord"><a class="wp-block-button__link wp-element-button" href="https://discord.gg/tuMB3rmmWB"><?php esc_html_e( 'Rejoindre le Discord', 'yume' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-yn-kofi yn-lien-kofi"} -->
<div class="wp-block-button is-style-yn-kofi yn-lien-kofi"><a class="wp-block-button__link wp-element-button" href="https://ko-fi.com/ynovel"><?php esc_html_e( 'Soutenir sur Ko-fi', 'yume' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
