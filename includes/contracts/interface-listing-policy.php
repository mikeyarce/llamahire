<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/** Read-only, site/job/owner-bound eligibility; it never grants moderation approval. */
interface Listing_Policy {
	/**
	 * Return null for an unmanaged job, or a bounded publication decision.
	 *
	 * Decisions contain eligible (bool), period_required (bool), and period
	 * (null or {id, predecessor, days}). IDs are opaque non-secret references.
	 * Exceptions, malformed decisions and storage failures fail closed.
	 * No provider/network calls, writes or notifications are allowed here.
	 */
	public function evaluate( array $context );
}
