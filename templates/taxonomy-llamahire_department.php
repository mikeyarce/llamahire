<?php
defined( 'ABSPATH' ) || exit;
?>
<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","align":"full","className":"llamahire-jobs-template","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","right":"var:preset|spacing|30","bottom":"var:preset|spacing|70","left":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group alignfull llamahire-jobs-template" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--30)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide"} /--><!-- wp:term-description {"align":"wide"} /--><!-- wp:llamahire/job-search {"align":"wide"} /--><!-- wp:llamahire/job-filters {"align":"wide","showDepartment":false} /--><!-- wp:llamahire/jobs-directory {"align":"wide","showFilters":false} /--></div>
<!-- /wp:group --></main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->
