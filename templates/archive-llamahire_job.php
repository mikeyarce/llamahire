<?php
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","align":"full","className":"llamahire-jobs-template","style":{"spacing":{"padding":{"top":"var(--wp--preset--spacing--60,3rem)","right":"var(--wp--preset--spacing--30,1rem)","bottom":"var(--wp--preset--spacing--70,4rem)","left":"var(--wp--preset--spacing--30,1rem)"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group alignfull llamahire-jobs-template" style="padding-top:var(--wp--preset--spacing--60,3rem);padding-right:var(--wp--preset--spacing--30,1rem);padding-bottom:var(--wp--preset--spacing--70,4rem);padding-left:var(--wp--preset--spacing--30,1rem)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var(--wp--preset--spacing--30,1rem)"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"level":1,"align":"wide"} -->
<h1 class="wp-block-heading alignwide"><?php esc_html_e( 'Open positions', 'llamahire' ); ?></h1>
<!-- /wp:heading --><!-- wp:paragraph {"align":"wide"} -->
<p class="alignwide"><?php esc_html_e( 'Find the role where you can do your best work.', 'llamahire' ); ?></p>
<!-- /wp:paragraph --><!-- wp:llamahire/job-search {"align":"wide"} /--><!-- wp:llamahire/job-filters {"align":"wide"} /--><!-- wp:llamahire/jobs-directory {"align":"wide","showFilters":false} /--></div>
<!-- /wp:group --></main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->
