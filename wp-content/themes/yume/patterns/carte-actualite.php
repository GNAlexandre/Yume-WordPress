<?php
/**
 * Title: Carte actualité
 * Slug: yume/carte-actualite
 * Categories: yume
 * Keywords: actualité, annonce, sortie, carte, article
 * Block Types: core/group
 * Description: Carte d'annonce avec illustration, surtitre, titre, texte et bouton « Lire ».
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"yn-card yn-carte-article","layout":{"type":"default"}} -->
<div class="wp-block-group yn-card yn-carte-article"><!-- wp:group {"className":"yn-carte-article__visuel","layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"bottom"}} -->
<div class="wp-block-group yn-carte-article__visuel"><!-- wp:paragraph {"className":"yn-chip yn-chip--new"} -->
<p class="yn-chip yn-chip--new"><?php esc_html_e( 'Nouveau', 'yume' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"yn-carte-article__corps","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group yn-carte-article__corps"><!-- wp:paragraph {"className":"yn-label"} -->
<p class="yn-label"><?php esc_html_e( 'Actualités', 'yume' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"className":"yn-carte-article__titre"} -->
<h3 class="wp-block-heading yn-carte-article__titre"><?php esc_html_e( 'Le tome 9 de Grimgar est disponible', 'yume' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"yn-carte-article__extrait"} -->
<p class="yn-carte-article__extrait"><?php esc_html_e( 'Remplacez ce texte par un court résumé de l’annonce : ce qui sort, où le lire et ce qui arrive ensuite.', 'yume' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e( 'Lire', 'yume' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
