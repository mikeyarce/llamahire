<?php
/**
 * Title: Careers page
 * Slug: llamahire/careers-page
 * Categories: featured
 * Description: A polished careers page with a welcoming hero and searchable jobs directory.
 * Keywords: careers, jobs, hiring
 * Viewport Width: 1440
 * Post Types: page
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"llamahire-pattern-section","align":"full","style":{"spacing":{"padding":{"top":"var(--wp--preset--spacing--80,5rem)","bottom":"var(--wp--preset--spacing--80,5rem)","left":"var(--wp--preset--spacing--40,1.5rem)","right":"var(--wp--preset--spacing--40,1.5rem)"}},"color":{"background":"var(--llamahire-soft,#f7f6fb)"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group llamahire-pattern-section alignfull has-background" style="background-color:var(--llamahire-soft,#f7f6fb);padding-top:var(--wp--preset--spacing--80,5rem);padding-right:var(--wp--preset--spacing--40,1.5rem);padding-bottom:var(--wp--preset--spacing--80,5rem);padding-left:var(--wp--preset--spacing--40,1.5rem)"><!-- wp:heading {"textAlign":"center","level":1,"fontSize":"xx-large"} -->
<h1 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e( 'Do your best work with us', 'llamahire' ); ?></h1>
<!-- /wp:heading --><!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"><?php esc_html_e( 'Join a thoughtful team solving meaningful problems together.', 'llamahire' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --><!-- wp:group {"className":"llamahire-pattern-section","anchor":"open-roles","align":"wide","style":{"spacing":{"padding":{"top":"var(--wp--preset--spacing--70,4rem)","bottom":"var(--wp--preset--spacing--70,4rem)"}}},"layout":{"type":"constrained"}} -->
<div id="open-roles" class="wp-block-group llamahire-pattern-section alignwide" style="padding-top:var(--wp--preset--spacing--70,4rem);padding-bottom:var(--wp--preset--spacing--70,4rem)"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Open positions', 'llamahire' ); ?></h2>
<!-- /wp:heading --><!-- wp:llamahire/job-search {"align":"wide"} /--><!-- wp:llamahire/job-filters {"align":"wide"} /--><!-- wp:llamahire/jobs-directory {"align":"wide","showFilters":false} /--></div>
<!-- /wp:group -->
