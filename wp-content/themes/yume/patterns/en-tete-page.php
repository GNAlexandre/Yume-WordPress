<?php
/**
 * Title: En-tête de page
 * Slug: yume/en-tete-page
 * Categories: yume, header
 * Keywords: en-tête, titre, introduction, bandeau
 * Block Types: core/group
 * Description: Bande dégradée pleine largeur avec surtitre, titre de page et introduction.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"full","className":"yn-entete-page","layout":{"type":"constrained","contentSize":"1344px","justifyContent":"left"}} -->
<div class="wp-block-group alignfull yn-entete-page"><!-- wp:paragraph {"className":"yn-label"} -->
<p class="yn-label"><?php esc_html_e( 'Yume Novel', 'yume' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php esc_html_e( 'Titre de la page', 'yume' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"yn-entete-page__intro"} -->
<p class="yn-entete-page__intro"><?php esc_html_e( 'Une phrase d’introduction qui dit en quelques mots ce que le lecteur trouvera sur cette page.', 'yume' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
