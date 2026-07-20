<?php
/**
 * Title: Careers page
 * Slug: llamahire/careers-page
 * Categories: featured
 * Description: A polished careers page with a welcoming hero and searchable jobs directory.
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}},"color":{"background":"#f7f6fb"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#f7f6fb;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--40)"><!-- wp:heading {"textAlign":"center","level":1,"fontSize":"xx-large"} -->
<h1 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e( 'Do your best work with us', 'llamahire' ); ?></h1>
<!-- /wp:heading --><!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"><?php esc_html_e( 'Join a thoughtful team solving meaningful problems together.', 'llamahire' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --><!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Open positions', 'llamahire' ); ?></h2>
<!-- /wp:heading --><!-- wp:llamahire/jobs-directory {"align":"wide"} /--></div>
<!-- /wp:group -->
