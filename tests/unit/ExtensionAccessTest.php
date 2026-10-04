<?php
use LlamaHire\Capabilities;
use LlamaHire\Contracts\Application_Repository;
use LlamaHire\Services\Extension_Access;

final class ExtensionAccessTest extends PHPUnit\Framework\TestCase {
	private $repository;
	private $access;

	protected function setUp(): void {
		$GLOBALS['unit_user_id'] = 7;
		$GLOBALS['unit_site_id'] = 3;
		$GLOBALS['unit_options'] = array();
		$GLOBALS['unit_users'] = array(
			7 => array( 'roles' => array( Capabilities::EMPLOYER_ROLE ), 'caps' => array( 'edit_llamahire_jobs' => true, Capabilities::VIEW_APPLICATIONS => true ) ),
			8 => array( 'roles' => array(), 'caps' => array( 'edit_others_llamahire_jobs' => true, 'edit_post' => true, Capabilities::VIEW_APPLICATIONS => true ) ),
			9 => array( 'roles' => array(), 'caps' => array( 'manage_options' => true ) ),
		);
		$GLOBALS['unit_jobs'] = array(
			11 => (object) array( 'ID' => 11, 'post_type' => 'llamahire_job', 'post_author' => 7 ),
			12 => (object) array( 'ID' => 12, 'post_type' => 'llamahire_job', 'post_author' => 9 ),
			13 => (object) array( 'ID' => 13, 'post_type' => 'page', 'post_author' => 7 ),
		);
		$this->repository = $this->createMock( Application_Repository::class );
		$this->access = new Extension_Access( $this->repository );
	}

	public function test_context_contains_only_numeric_binding_and_normalized_mode(): void {
		$this->assertSame( array( 'site_id' => 3, 'mode' => 'company', 'job_id' => 11, 'owner_id' => 7 ), $this->access->job_context( 11 ) );
		$GLOBALS['unit_options'][ \LlamaHire\Settings::OPTION ] = array( 'site_mode' => 'job_board' );
		$this->assertSame( 'job_board', $this->access->site_context()['mode'] );
		$GLOBALS['unit_site_id'] = 4;
		$this->assertSame( 4, $this->access->site_context()['site_id'] );
	}

	public function test_foreign_missing_and_non_job_contexts_are_denied(): void {
		foreach ( array( 12, 13, 999 ) as $job_id ) {
			$this->assertNull( $this->access->job_context( $job_id ) );
		}
		$this->assertNotNull( $this->access->job_context( 12, 8 ) );
	}

	public function test_anonymous_scope_is_denial_instead_of_unscoped_access(): void {
		$GLOBALS['unit_user_id'] = 0;
		$this->assertNull( $this->access->application_scope( Capabilities::VIEW_APPLICATIONS ) );
		$this->assertNull( $this->access->job_context( 11 ) );
	}

	public function test_query_scope_requires_candidate_capability_and_distinguishes_board_manager(): void {
		$this->assertSame( array( 'author_id' => 7 ), $this->access->application_scope( Capabilities::VIEW_APPLICATIONS ) );
		$this->assertSame( array( 'author_id' => 0 ), $this->access->application_scope( Capabilities::VIEW_APPLICATIONS, 8 ) );
		$this->assertNull( $this->access->application_scope( Capabilities::VIEW_APPLICATIONS, 9 ) );
	}

	public function test_unrelated_and_missing_capabilities_do_not_read_candidate_storage(): void {
		$this->repository->expects( $this->never() )->method( 'find' );
		$this->assertFalse( $this->access->can_access_application( 20, 'manage_options', 9 ) );
		$this->assertFalse( $this->access->can_access_application( 20, Capabilities::ERASE_APPLICATIONS ) );
	}

	public function test_candidate_record_requires_job_ownership_after_capability(): void {
		$this->repository->method( 'find' )->willReturnMap( array( array( 20, (object) array( 'job_id' => 11 ) ), array( 21, (object) array( 'job_id' => 12 ) ), array( 22, null ) ) );
		$this->assertTrue( $this->access->can_access_application( 20, Capabilities::VIEW_APPLICATIONS ) );
		$this->assertFalse( $this->access->can_access_application( 21, Capabilities::VIEW_APPLICATIONS ) );
		$this->assertFalse( $this->access->can_access_application( 22, Capabilities::VIEW_APPLICATIONS ) );
		$this->assertTrue( $this->access->can_access_application( 21, Capabilities::VIEW_APPLICATIONS, 8 ) );
	}

	public function test_final_repository_replacement_is_used(): void {
		$this->repository->expects( $this->never() )->method( 'find' );
		$replacement = $this->createMock( Application_Repository::class );
		$replacement->expects( $this->once() )->method( 'find' )->with( 20 )->willReturn( (object) array( 'job_id' => 11 ) );
		$this->access->set_repository( $replacement );
		$this->assertTrue( $this->access->can_access_application( 20, Capabilities::VIEW_APPLICATIONS ) );
	}
}
