<?php
/**
 * Title: Department landing page
 * Slug: llamahire/department-landing-page
 * Categories: llamahire, featured
 * Description: A team introduction and job directory ready to be limited to a selected department.
 * Keywords: department, team jobs, landing page
 * Viewport Width: 1440
 * Post Types: page
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"llamahire-pattern-section","align":"full","style":{"spacing":{"padding":{"top":"clamp(3rem,7vw,6rem)","right":"clamp(1.25rem,4vw,3rem)","bottom":"clamp(3rem,7vw,6rem)","left":"clamp(1.25rem,4vw,3rem)"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group llamahire-pattern-section alignfull" style="padding-top:clamp(3rem,7vw,6rem);padding-right:clamp(1.25rem,4vw,3rem);padding-bottom:clamp(3rem,7vw,6rem);padding-left:clamp(1.25rem,4vw,3rem)"><!-- wp:heading {"textAlign":"center","level":1,"fontSize":"xx-large"} -->
<h1 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e( 'Do meaningful work with this team', 'llamahire' ); ?></h1>
<!-- /wp:heading --><!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"><?php esc_html_e( 'Meet the people, purpose, and opportunities behind this part of our organization.', 'llamahire' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --><!-- wp:group {"className":"llamahire-pattern-section","anchor":"open-roles","align":"wide","style":{"spacing":{"padding":{"top":"clamp(2.5rem,6vw,5rem)","bottom":"clamp(3rem,7vw,6rem)"}}},"layout":{"type":"constrained"}} -->
<div id="open-roles" class="wp-block-group llamahire-pattern-section alignwide" style="padding-top:clamp(2.5rem,6vw,5rem);padding-bottom:clamp(3rem,7vw,6rem)"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Open roles on this team', 'llamahire' ); ?></h2>
<!-- /wp:heading --><!-- wp:paragraph -->
<p><?php esc_html_e( 'See where your experience could make an impact with this team.', 'llamahire' ); ?></p>
<!-- /wp:paragraph --><!-- wp:llamahire/job-search {"align":"wide"} /--><!-- wp:llamahire/jobs-directory {"align":"wide","showFilters":true} /--></div>
<!-- /wp:group -->
