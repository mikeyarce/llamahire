<?php
namespace LlamaHire\Contracts;

defined( 'ABSPATH' ) || exit;

/** Trusted extension boundary for moderation, policy and canonical listing periods. */
interface Job_Lifecycle {
	/** Numeric/state-only current-site context; null means missing, errors remain errors. */
	public function context( $job_id );

	/** Record approval only for a current user authorized to publish and edit this job. */
	public function approve( $job_id );

	/** Reconcile saved approval and all policies; background calls cannot grant approval. */
	public function reconcile( $job_id );

	/** Fail closed by making a managed listing private, without deleting job/candidate data. */
	public function suspend( $job_id );

	/** Read an immutable period by its scoped key, or the job's current period when omitted. */
	public function period( $job_id, $period_key = '' );
}
