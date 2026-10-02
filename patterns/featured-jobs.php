<?php
/**
 * Title: Featured jobs section
 * Slug: llamahire/featured-jobs
 * Categories: llamahire, posts
 * Description: A heading, introduction, and grid of featured open roles.
 * Keywords: featured jobs, open roles
 * Viewport Width: 1200
 * Post Types: page
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"llamahire-pattern-section","anchor":"open-roles","align":"wide","style":{"spacing":{"padding":{"top":"clamp(2.5rem,6vw,5rem)","bottom":"clamp(2.5rem,6vw,5rem)"}}},"layout":{"type":"constrained"}} -->
<div id="open-roles" class="wp-block-group llamahire-pattern-section alignwide" style="padding-top:clamp(2.5rem,6vw,5rem);padding-bottom:clamp(2.5rem,6vw,5rem)"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'Featured opportunities', 'llamahire' ); ?></h2>
<!-- /wp:heading --><!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php esc_html_e( 'A few roles where we are especially excited to meet great people.', 'llamahire' ); ?></p>
<!-- /wp:paragraph --><!-- wp:llamahire/featured-jobs {"align":"wide","showHeading":false,"perPage":3} /--></div>
<!-- /wp:group -->
