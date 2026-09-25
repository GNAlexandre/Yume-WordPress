<?php
/**
 * Title: Commentaires
 * Slug: yume/commentaires
 * Categories: yume
 * Inserter: no
 * Description: Fil de commentaires (réponses imbriquées, pagination) et formulaire, en français.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:comments {"className":"yn-commentaires"} -->
<div class="wp-block-comments yn-commentaires"><!-- wp:heading {"className":"yn-commentaires__titre"} -->
<h2 class="wp-block-heading yn-commentaires__titre"><?php esc_html_e( 'Commentaires', 'yume' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:comment-template -->
<!-- wp:group {"className":"yn-commentaire","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group yn-commentaire"><!-- wp:avatar {"size":40} /-->

<!-- wp:group {"className":"yn-commentaire__corps","layout":{"type":"default"}} -->
<div class="wp-block-group yn-commentaire__corps"><!-- wp:group {"className":"yn-commentaire__entete","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group yn-commentaire__entete"><!-- wp:comment-author-name /-->

<!-- wp:comment-date {"format":"j F Y à G\\hi","isLink":true} /--></div>
<!-- /wp:group -->

<!-- wp:comment-content /-->

<!-- wp:comment-reply-link /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:comment-template -->

<!-- wp:comments-pagination {"className":"yn-pagination","layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:comments-pagination-previous {"label":"<?php echo esc_attr__( 'Commentaires précédents', 'yume' ); ?>"} /-->

<!-- wp:comments-pagination-numbers /-->

<!-- wp:comments-pagination-next {"label":"<?php echo esc_attr__( 'Commentaires suivants', 'yume' ); ?>"} /-->
<!-- /wp:comments-pagination -->

<!-- wp:post-comments-form /--></div>
<!-- /wp:comments -->
