<?php
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","align":"full","className":"llamahire-job-template","style":{"spacing":{"padding":{"top":"var(--wp--preset--spacing--60,3rem)","right":"var(--wp--preset--spacing--30,1rem)","bottom":"var(--wp--preset--spacing--70,4rem)","left":"var(--wp--preset--spacing--30,1rem)"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group alignfull llamahire-job-template" style="padding-top:var(--wp--preset--spacing--60,3rem);padding-right:var(--wp--preset--spacing--30,1rem);padding-bottom:var(--wp--preset--spacing--70,4rem);padding-left:var(--wp--preset--spacing--30,1rem)"><!-- wp:group {"align":"wide","className":"llamahire-single-shell","style":{"spacing":{"blockGap":"var(--wp--preset--spacing--30,1rem)"}},"layout":{"type":"default"}} -->
<div class="wp-block-group alignwide llamahire-single-shell"><!-- wp:post-terms {"term":"llamahire_department","separator":" · ","className":"llamahire-job-template__department"} /-->
<!-- wp:post-title {"level":1} /-->
<!-- wp:post-excerpt {"moreText":"","showMoreOnNewLine":false} /-->
<!-- wp:post-content {"lock":{"move":false,"remove":true},"layout":{"type":"default"}} /--></div>
<!-- /wp:group --></main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->
