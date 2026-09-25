<?php
/**
 * Title: Grille d’actualités (liste des articles)
 * Slug: yume/grille-articles-liste
 * Categories: yume
 * Inserter: no
 * Description: Grille des derniers articles, indépendante de la page affichée (page « Actualités ») : image, catégorie, titre, extrait, date, pagination.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:query {"queryId":21,"query":{"perPage":12,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"align":"wide","className":"yn-actus"} -->
<div class="wp-block-query alignwide yn-actus"><!-- wp:post-template {"className":"yn-actus__grille","layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"280px"}} -->
<!-- wp:group {"tagName":"article","className":"yn-card yn-carte-article","layout":{"type":"default"}} -->
<article class="wp-block-group yn-card yn-carte-article"><!-- wp:post-featured-image {"aspectRatio":"16/9","sizeSlug":"yume-vignette","className":"yn-carte-article__image yn-vignette"} /-->

<!-- wp:group {"className":"yn-carte-article__corps","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group yn-carte-article__corps"><!-- wp:post-terms {"term":"category","separator":" · ","className":"yn-label"} /-->

<!-- wp:post-title {"level":2,"isLink":true,"className":"yn-carte-article__titre"} /-->

<!-- wp:post-excerpt {"excerptLength":24,"className":"yn-carte-article__extrait"} /-->

<!-- wp:post-date {"format":"j F Y","className":"yn-carte-article__date"} /--></div>
<!-- /wp:group --></article>
<!-- /wp:group -->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"paginationArrow":"arrow","className":"yn-pagination","layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous {"label":"<?php echo esc_attr__( 'Page précédente', 'yume' ); ?>"} /-->

<!-- wp:query-pagination-numbers /-->

<!-- wp:query-pagination-next {"label":"<?php echo esc_attr__( 'Page suivante', 'yume' ); ?>"} /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:group {"className":"yn-card yn-vide","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group yn-card yn-vide"><!-- wp:paragraph {"className":"yn-label"} -->
<p class="yn-label"><?php esc_html_e( 'Rien pour l’instant', 'yume' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Aucune actualité n’a encore été publiée ici. Les annonces de sorties arrivent aussi sur notre Discord.', 'yume' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query -->
