<?php
/**
 * Title: Questions fréquentes
 * Slug: yume/questions-frequentes
 * Categories: yume, text
 * Keywords: faq, questions, réponses, aide
 * Description: Liste de questions dépliables (bloc Détails), accessible au clavier, pour la page FAQ.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"yn-faq","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group yn-faq"><!-- wp:details -->
<details class="wp-block-details"><summary><?php esc_html_e( 'Les traductions sont-elles gratuites ?', 'yume' ); ?></summary><!-- wp:paragraph -->
<p><?php esc_html_e( 'Oui. Yume Novel est une équipe de fans bénévoles : la lecture en ligne et les téléchargements sont gratuits. Les œuvres licenciées en France sont retirées.', 'yume' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary><?php esc_html_e( 'Quand sortent les prochains chapitres ?', 'yume' ); ?></summary><!-- wp:paragraph -->
<p><?php esc_html_e( 'Les sorties ont lieu en général le mercredi, le samedi et le dimanche. Le planning public indique l’avancement de chaque tome.', 'yume' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary><?php esc_html_e( 'Comment rejoindre l’équipe ?', 'yume' ); ?></summary><!-- wp:paragraph -->
<p><?php esc_html_e( 'Passez nous voir sur Discord : nous cherchons régulièrement des traducteurs, des relecteurs et des graphistes.', 'yume' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details --></div>
<!-- /wp:group -->
