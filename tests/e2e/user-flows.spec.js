const { execFile } = require( 'node:child_process' );
const { promisify } = require( 'node:util' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const run = promisify( execFile );
const password = 'Flow-test-password-123';
const adminApplications = '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications';
const settingsURL = '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-settings';

async function fixtures( action, ...args ) {
	const { stdout } = await run( './node_modules/.bin/wp-env', [ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/flow-fixtures.php', action, ...args ], { timeout: 120_000, maxBuffer: 1024 * 1024 } );
	if ( [ 'setup', 'state', 'access' ].includes( action ) ) {
		const line = stdout.split( '\n' ).find( ( item ) => item.trim().startsWith( '{' ) );
		if ( ! line ) { throw new Error( 'Flow fixture helper did not return JSON.' ); }
		return JSON.parse( line );
	}
}

async function login( page, user = process.env.WP_ADMIN_USER || 'admin', pass = process.env.WP_ADMIN_PASSWORD || 'password' ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_pass' ).fill( pass );
	await page.locator( '#user_login' ).fill( user );
	await page.locator( '#wp-submit' ).press( 'Enter' );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
}

async function section( page, name ) {
	await page.getByRole( 'navigation', { name: 'Settings sections' } ).getByRole( 'link', { name, exact: true } ).press( 'Enter' );
}

async function saveSettings( page ) {
	await page.getByRole( 'button', { name: 'Save changes', exact: true } ).press( 'Enter' );
	await expect( page.getByText( 'Settings saved.', { exact: true } ) ).toBeVisible();
}

async function apply( page, url, email, resume = false ) {
	await page.goto( url );
	await page.locator( 'input[name="candidate_name"]' ).fill( 'Flow Submitted Candidate' );
	await page.locator( 'input[name="email"]' ).fill( email );
	if ( resume ) { await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) ); }
	await page.getByRole( 'button', { name: 'Submit application', exact: true } ).press( 'Enter' );
	await expect( page.getByRole( 'status' ) ).toContainText( 'Your application has been received' );
}

async function register( page, f ) {
	await page.goto( f.pages.employer_registration );
	await page.getByLabel( 'Your name', { exact: true } ).fill( 'Flow Employer Contact' );
	await page.getByLabel( 'Company name', { exact: true } ).fill( 'Flow Registered Company' );
	await page.getByLabel( 'Work email', { exact: true } ).fill( f.registration_email );
	await page.getByLabel( /^Password/ ).fill( password );
	await page.locator( '[name="accept_policy"]' ).press( 'Space' );
	await page.getByRole( 'button', { name: 'Create employer account' } ).press( 'Enter' );
	await expect( page.getByRole( 'status' ) ).toContainText( 'Check your email' );
	const state = await fixtures( 'state' );
	expect( state.registration_status ).toBe( 'pending_email' );
	expect( state.verification ).toContain( 'llamahire_employer_verify' );
	return state;
}

async function editorPanel( page, title ) {
	const welcome = page.getByRole( 'dialog', { name: 'Welcome to the editor' } );
	if ( await welcome.isVisible() ) { await page.keyboard.press( 'Escape' ); }
	const jobTab = page.getByRole( 'tab', { name: 'Job', exact: true } );
	if ( ! await jobTab.isVisible() ) {
		await page.getByRole( 'button', { name: 'Settings', exact: true } ).first().press( 'Enter' );
	}
	await jobTab.press( 'Enter' );
	const panel = page.getByRole( 'button', { name: title, exact: true } );
	await expect( panel ).toBeVisible();
	if ( await panel.getAttribute( 'aria-expanded' ) === 'false' ) { await panel.press( 'Enter' ); }
}

async function saveJob( page, id ) {
	const response = page.waitForResponse( ( item ) => item.request().method() === 'POST' && new URL( item.url() ).pathname.endsWith( `/wp/v2/llamahire_job/${ id }` ) );
	await page.getByRole( 'button', { name: /^(Save|Update)$/ } ).last().press( 'Enter' );
	expect( ( await response ).ok() ).toBeTruthy();
}

// Tests share no product state. One worker is required because settings are site-wide.
test.describe( '@flows independent user journeys', () => {
	let f;
	test.beforeEach( async ( {}, info ) => { f = await fixtures( 'setup', info.title.includes( '@recruiter' ) ? 'company' : 'job_board' ); } );
	test.afterEach( async () => { await fixtures( 'cleanup' ); } );

	test( '@employer registration, verification and manual operator approval unlock job submission', async ( { page } ) => {
		const state = await register( page, f );
		await login( page, state.registration_user, password );
		await page.goto( f.pages.submit_job );
		await expect( page.locator( '[name="job_title"]' ) ).toHaveCount( 0 );
		await page.context().clearCookies();
		await page.goto( state.verification );
		await expect( page.getByRole( 'status' ) ).toContainText( 'operator will review' );
		expect( ( await fixtures( 'state' ) ).registration_status ).toBe( 'pending_approval' );
		await login( page, state.registration_user, password );
		await page.goto( f.pages.submit_job );
		await expect( page.locator( '[name="job_title"]' ) ).toHaveCount( 0 );
		await page.context().clearCookies();
		await login( page );
		await page.goto( '/wp-admin/users.php' );
		const row = page.getByRole( 'row' ).filter( { hasText: f.registration_email } );
		await row.getByRole( 'link', { name: /Approve employer/ } ).first().press( 'Enter' );
		expect( ( await fixtures( 'state' ) ).registration_status ).toBe( 'approved' );
		await page.context().clearCookies();
		await login( page, state.registration_user, password );
		await page.goto( f.pages.submit_job );
		await expect( page.locator( '[name="job_title"]' ) ).toBeVisible();
	} );

	test( '@employer registration validation rejects missing policy and weak passwords before a successful retry', async ( { page } ) => {
		await page.goto( f.pages.employer_registration );
		await page.getByLabel( 'Your name', { exact: true } ).fill( 'Flow Invalid Employer' );
		await page.getByLabel( 'Company name', { exact: true } ).fill( 'Flow Company' );
		await page.getByLabel( 'Work email', { exact: true } ).fill( f.registration_email );
		await page.getByLabel( /^Password/ ).fill( password );
		const form = page.locator( 'form' ).filter( { has: page.locator( '[name=contact_name]' ) } );
		// Bypass HTML validation to exercise the connected server error response.
		await form.evaluate( ( element ) => { element.noValidate = true; } );
		await page.getByRole( 'button', { name: 'Create employer account' } ).press( 'Enter' );
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Accept the listing policy' );
		expect( ( await fixtures( 'state' ) ).registration_user ).toBe( '' );
		await page.getByLabel( 'Your name', { exact: true } ).fill( 'Flow Invalid Employer' );
		await page.getByLabel( 'Company name', { exact: true } ).fill( 'Flow Company' );
		await page.getByLabel( 'Work email', { exact: true } ).fill( f.registration_email );
		await page.getByLabel( /^Password/ ).fill( 'short' );
		await page.locator( '[name=accept_policy]' ).press( 'Space' );
		await form.evaluate( ( element ) => { element.noValidate = true; } );
		await page.getByRole( 'button', { name: 'Create employer account' } ).press( 'Enter' );
		await expect( page.getByRole( 'alert' ) ).toContainText( 'at least 12 characters' );
		expect( ( await fixtures( 'state' ) ).registration_user ).toBe( '' );
		await register( page, f );
	} );

	test( '@settings automatic approval applies after verification and the verification link is single-use', async ( { page } ) => {
		await login( page );
		await page.goto( settingsURL );
		await section( page, 'Site purpose' );
		await page.getByLabel( 'After email verification' ).selectOption( 'automatic' );
		await saveSettings( page );
		await page.reload();
		await section( page, 'Site purpose' );
		await expect( page.getByLabel( 'After email verification' ) ).toHaveValue( 'automatic' );
		await page.context().clearCookies();
		const state = await register( page, f );
		await page.goto( state.verification );
		await expect( page.getByRole( 'status' ) ).toContainText( 'account is ready' );
		expect( ( await fixtures( 'state' ) ).registration_status ).toBe( 'approved' );
		await page.goto( state.verification );
		await expect( page.getByRole( 'alert' ) ).toContainText( /verification link/i );
		await login( page, state.registration_user, password );
		await page.goto( f.pages.submit_job );
		await expect( page.locator( '[name="job_title"]' ) ).toBeVisible();
	} );

	for ( const [ key, action, status ] of [ [ 'changes', 'Request changes', 'draft' ], [ 'decline', 'Decline', 'trash' ] ] ) {
		test( `@jobs moderation ${ action.toLowerCase() } is reflected in the employer portal`, async ( { page } ) => {
			await login( page );
			await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&post_status=pending' );
			const row = page.locator( `#post-${ f.jobs[ key ] }` );
			await row.getByRole( 'link', { name: action, exact: true } ).press( 'Enter' );
			expect( ( await fixtures( 'state' ) ).jobs[ key ].status ).toBe( status );
			await page.context().clearCookies();
			await login( page, f.users.owner, password );
			await page.goto( f.pages.my_jobs );
			const employerRow = page.getByRole( 'row' ).filter( { hasText: `Flow ${ key } ` } );
			if ( status === 'draft' ) { await expect( employerRow ).toContainText( 'Draft' ); }
			else { await expect( employerRow ).toHaveCount( 0 ); }
			await page.context().clearCookies();
			await page.goto( f.urls[ key ] );
			await expect( page.locator( '[data-llamahire-application-form]' ) ).toHaveCount( 0 );
		} );
	}

	test( '@jobs administrator creates and publishes a job whose public facts match schema', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/post-new.php?post_type=llamahire_job' );
		await editorPanel( page, 'Role and hiring status' );
		const title = `Flow Admin ${ f.registration_email.match( /flow-register-(.*)@/ )[1] }`;
		const canvas = page.getByRole( 'region', { name: 'Editor content' } ).frameLocator( 'iframe' );
		await canvas.getByRole( 'textbox', { name: 'Add job title' } ).fill( title );
		await canvas.getByRole( 'button', { name: 'Add default block' } ).press( 'Enter' );
		await canvas.getByRole( 'document', { name: /Empty block; start writing/ } ).last().fill( 'A new administrator-authored role for browser testing.' );
		const draftResponse = page.waitForResponse( ( response ) => response.request().method() === 'POST' && /\/wp\/v2\/llamahire_job\/\d+$/.test( new URL( response.url() ).pathname ) );
		await page.getByRole( 'button', { name: 'Save draft', exact: true } ).press( 'Enter' );
		const draft = await ( await draftResponse ).json();
		await fixtures( 'track-authored', String( draft.id ) );
		await editorPanel( page, 'Role and hiring status' );
		await page.getByLabel( 'Location type', { exact: true } ).selectOption( 'remote' );
		await editorPanel( page, 'Remote eligibility' );
		await page.getByLabel( 'Eligible applicant countries', { exact: true } ).fill( 'CA' );
		await editorPanel( page, 'Hiring organization' );
		await page.getByLabel( 'Organization name override', { exact: true } ).fill( 'Flow Admin Employer' );
		await editorPanel( page, 'Compensation' );
		await page.getByLabel( 'Minimum salary', { exact: true } ).fill( '80000' );
		await page.getByLabel( 'Maximum salary', { exact: true } ).fill( '100000' );
		await page.getByLabel( 'Currency', { exact: true } ).fill( 'CAD' );
		await editorPanel( page, 'Application routing' );
		await page.getByLabel( 'How candidates apply', { exact: true } ).selectOption( 'internal' );
		await page.getByLabel( 'Notification email', { exact: true } ).fill( 'flow-inbox@example.test' );
		await editorPanel( page, 'Google Jobs readiness' );
		await expect( page.locator( '.llamahire-readiness' ) ).toContainText( 'Ready to generate Google job data after publishing' );
		await page.getByRole( 'button', { name: 'Publish', exact: true } ).press( 'Enter' );
		const published = page.waitForResponse( ( response ) => response.request().method() === 'POST' && new URL( response.url() ).pathname.endsWith( `/wp/v2/llamahire_job/${ draft.id }` ) && response.request().postDataJSON().status === 'publish' );
		await page.locator( '.editor-post-publish-panel' ).getByRole( 'button', { name: 'Publish', exact: true } ).press( 'Enter' );
		const job = await ( await published ).json();
		expect( job.status ).toBe( 'publish' );
		await page.context().clearCookies();
		await page.goto( job.link );
		await expect( page.getByRole( 'heading', { name: title, level: 1 } ) ).toBeVisible();
		await expect( page.getByText( /CAD 80,000.*100,000/ ) ).toBeVisible();
		await expect( page.locator( '[data-llamahire-application-form]' ) ).toBeVisible();
		const schema = JSON.parse( await page.locator( 'script[type="application/ld+json"]' ).textContent() );
		expect( schema.title ).toBe( title );
		expect( schema.hiringOrganization.name ).toBe( 'Flow Admin Employer' );
		expect( schema.baseSalary.currency ).toBe( 'CAD' );
		expect( schema.baseSalary.value.minValue ).toBe( 80000 );
		expect( schema.baseSalary.value.maxValue ).toBe( 100000 );
	} );

	test( '@jobs employer closes a published job and administrator reopens its application path and schema', async ( { page } ) => {
		await login( page, f.users.owner, password );
		await page.goto( f.pages.my_jobs );
		await page.getByRole( 'row' ).filter( { hasText: 'Flow open ' } ).getByRole( 'button', { name: 'Close', exact: true } ).press( 'Enter' );
		expect( ( await fixtures( 'state' ) ).jobs.open.meta.closed ).toBe( '1' );
		await page.goto( f.urls.open );
		await expect( page.locator( '[data-llamahire-application-form]' ) ).toHaveCount( 0 );
		await expect( page.locator( 'script[type="application/ld+json"]' ) ).toHaveCount( 0 );
		await page.context().clearCookies();
		await login( page );
		await page.goto( `/wp-admin/post.php?post=${ f.jobs.open }&action=edit` );
		await editorPanel( page, 'Role and hiring status' );
		await page.getByLabel( 'Close applications without unpublishing' ).press( 'Space' );
		await saveJob( page, f.jobs.open );
		await page.context().clearCookies();
		await page.goto( f.urls.open );
		await expect( page.locator( '[data-llamahire-application-form]' ) ).toBeVisible();
		await expect( page.locator( 'script[type="application/ld+json"]' ) ).toHaveCount( 1 );
	} );

	test( '@jobs renewal extends expiration without changing the deadline', async ( { page } ) => {
		const before = ( await fixtures( 'state' ) ).jobs.renew.meta;
		await login( page, f.users.owner, password );
		await page.goto( f.pages.my_jobs );
		const row = page.getByRole( 'row' ).filter( { hasText: 'Flow renew ' } );
		await row.getByRole( 'button', { name: 'Renew', exact: true } ).press( 'Enter' );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'listing was renewed' } ) ).toBeVisible();
		const after = ( await fixtures( 'state' ) ).jobs.renew.meta;
		expect( after.deadline ).toBe( before.deadline );
		expect( Date.parse( after.listing_expires ) - Date.parse( before.listing_expires ) ).toBe( 30 * 86400000 );
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Flow renew ' } ).getByRole( 'button', { name: 'Renew', exact: true } ) ).toHaveCount( 0 );
	} );

	test( '@jobs expired listing must return through draft, preview and moderation when relisted', async ( { page } ) => {
		await page.goto( f.urls.expired );
		await expect( page.locator( '[data-llamahire-application-form]' ) ).toHaveCount( 0 );
		await login( page, f.users.owner, password );
		await page.goto( f.pages.my_jobs );
		await page.getByRole( 'row' ).filter( { hasText: 'Flow expired ' } ).getByRole( 'button', { name: 'Relist' } ).press( 'Enter' );
		await expect( page.getByRole( 'status' ) ).toContainText( 'now a draft' );
		let state = await fixtures( 'state' );
		expect( state.jobs.expired.status ).toBe( 'draft' );
		expect( state.jobs.expired.meta.listing_expires ).toBe( '' );
		await page.getByRole( 'button', { name: 'Save and preview' } ).press( 'Enter' );
		await expect( page ).toHaveURL( /preview=true/ );
		await page.getByRole( 'button', { name: 'Submit for review' } ).press( 'Enter' );
		state = await fixtures( 'state' );
		expect( state.jobs.expired.status ).toBe( 'pending' );
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Flow expired ' } ) ).toContainText( 'Awaiting review' );
	} );

	for ( const key of [ 'draft', 'closed', 'expired', 'deadline' ] ) {
		test( `@candidate ${ key } jobs expose no form and reject a POST with a valid job nonce`, async ( { page } ) => {
			await page.goto( f.urls[ key ] );
			await expect( page.locator( '[data-llamahire-application-form]' ) ).toHaveCount( 0 );
			// CLI generates a logged-out nonce; the request is deliberately anonymous.
			const { stdout } = await run( './node_modules/.bin/wp-env', [ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', 'eval', `echo wp_create_nonce('llamahire_apply_${ f.jobs[ key ] }');` ] );
			const nonce = stdout.match( /\b[a-f0-9]{10}\b/ )[0];
			const before = ( await fixtures( 'state' ) ).applications.total;
			const response = await page.request.post( '/wp-admin/admin-post.php', { form: { action: 'llamahire_apply', job_id: String( f.jobs[ key ] ), llamahire_nonce: nonce, name: 'Unavailable Fixture', email: f.registration_email } } );
			expect( response.url() ).toContain( 'application=invalid' );
			expect( ( await fixtures( 'state' ) ).applications.total ).toBe( before );
		} );
	}

	for ( const [ key, label, href ] of [ [ 'url', 'Apply on the employer website', 'https://example.test/apply' ], [ 'email', 'Apply by email', 'mailto:jobs@example.test?subject=' ] ] ) {
		test( `@candidate external ${ key } applications use the configured destination`, async ( { page } ) => {
			await page.goto( f.urls[ key ] );
			const link = page.getByRole( 'link', { name: label, exact: true } );
			if ( key === 'url' ) { await expect( link ).toHaveAttribute( 'href', href ); }
			else { expect( await link.getAttribute( 'href' ) ).toBe( href + encodeURIComponent( `Flow email ${ f.registration_email.match( /flow-register-(.*)@/ )[1] }` ) ); }
			await expect( page.locator( '[data-llamahire-application-form]' ) ).toHaveCount( 0 );
		} );
	}

	test( '@recruiter complete candidate lifecycle persists every stage and rejection can be cancelled', async ( { page } ) => {
		await login( page );
		for ( const stage of [ 'new', 'reviewing', 'interviewing', 'offer', 'hired' ] ) {
			await page.goto( `${ adminApplications }&application=${ f.candidate }` );
			await page.locator( 'select[name="status"]' ).selectOption( stage );
			await page.getByRole( 'button', { name: 'Save status', exact: true } ).press( 'Enter' );
			await page.reload();
			await expect( page.locator( 'select[name="status"]' ) ).toHaveValue( stage );
			expect( ( await fixtures( 'state' ) ).candidate.status ).toBe( stage );
		}
		await page.goto( `/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-hiring&application=${ f.candidate }&job_id=${ f.jobs.open }` );
		// Open the candidate via its card to preserve the workspace return context.
		if ( ! await page.locator( '.llamahire-candidate-drawer' ).isVisible() ) { await page.getByRole( 'link', { name: /Flow Private Candidate/ } ).press( 'Enter' ); }
		const reject = page.getByRole( 'button', { name: 'Reject candidate', exact: true } );
		await reject.press( 'Enter' );
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toContainText( 'Flow Private Candidate' );
		await dialog.getByRole( 'button', { name: 'Cancel', exact: true } ).press( 'Enter' );
		await expect( reject ).toBeFocused();
		expect( ( await fixtures( 'state' ) ).candidate.status ).toBe( 'hired' );
		await reject.press( 'Enter' );
		await dialog.getByRole( 'button', { name: 'Reject candidate', exact: true } ).press( 'Enter' );
		expect( ( await fixtures( 'state' ) ).candidate.status ).toBe( 'rejected' );
		await page.goto( `${ adminApplications }&application=${ f.candidate }` );
		await expect( page.locator( 'select[name="status"]' ) ).toHaveValue( 'rejected' );
		await expect( page.locator( '#llamahire-application-activity' ) ).toContainText( 'Hired → Rejected' );
	} );

	test( '@privacy extension access authorizes own candidate REST records and conceals foreign records', async ( { page } ) => {
		for ( const user of [ 'owner', 'other' ] ) {
			await page.context().clearCookies();
			await login( page, f.users[ user ], password );
			const cookie = ( await page.context().cookies() ).find( item => item.name.startsWith( 'wordpress_logged_in_' ) );
			const access = await fixtures( 'access', cookie.value, user );
			expect( access.valid_session ).toBeTruthy();
			expect( access.contract_allowed ).toBe( user === 'owner' );
			expect( access.invalid_identity_denied ).toBeTruthy();
			if ( user === 'owner' ) {
				expect( access.job_context.job_id ).toBe( f.jobs.open );
				expect( access.job_context.mode ).toBe( 'job_board' );
			} else {
				expect( access.job_context ).toBeNull();
			}
			const response = await page.request.get( `/wp-json/llamahire/v1/applications/${ f.candidate }`, { headers: { 'X-WP-Nonce': access.rest_nonce } } );
			expect( response.status() ).toBe( user === 'owner' ? 200 : 404 );
			if ( user === 'owner' ) {
				expect( ( await response.json() ).id ).toBe( f.candidate );
			} else {
				expect( ( await response.json() ).code ).toBe( 'llamahire_application_not_found' );
			}
		}
	} );

	test( '@privacy employers see only their own jobs, candidates, resumes and export rows', async ( { page } ) => {
		await login( page );
		await page.goto( `${ adminApplications }&application=${ f.candidate }` );
		await page.getByText( 'Manage candidate data', { exact: true } ).press( 'Enter' );
		await page.locator( '#llamahire-replacement-resume' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Upload resume', exact: true } ).press( 'Enter' );
		await expect( page.getByRole( 'link', { name: 'Download resume', exact: true } ) ).toBeVisible();
		await page.context().clearCookies();
		await login( page, f.users.other, password );
		await page.goto( `${ f.pages.submit_job }?job_id=${ f.jobs.open }` );
		await expect( page.getByText( 'Job listing not found.', { exact: true } ) ).toBeVisible();
		await expect( page.locator( '[name="job_title"]' ) ).toHaveCount( 0 );
		await page.goto( `${ f.pages.my_jobs }?employer_view=applications&job_id=${ f.jobs.open }&application_id=${ f.candidate }` );
		await expect( page.getByText( 'Flow Private Candidate', { exact: true } ) ).toHaveCount( 0 );
		await expect( page.locator( '.llamahire-employer-application-review' ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'link', { name: 'Download', exact: true } ) ).toHaveCount( 0 );
		const cookies = await page.context().cookies();
		const cookie = cookies.find( ( item ) => item.name.startsWith( 'wordpress_logged_in_' ) );
		const access = await fixtures( 'access', cookie.value );
		expect( access.valid_session ).toBeTruthy();
		const resume = await page.request.get( access.resume );
		expect( resume.status() ).toBe( 403 );
		expect( await resume.text() ).toContain( 'You cannot access this resume.' );
		const exported = await page.request.get( `${ access.export }&job_ids=${ f.jobs.open }` );
		expect( exported.ok() ).toBeTruthy();
		expect( exported.headers()['content-type'] ).toContain( 'text/csv' );
		const csv = await exported.text();
		expect( csv ).not.toContain( 'Flow Private Candidate' );
		expect( csv ).not.toContain( 'Flow open ' );
		expect( csv.replace( /^\uFEFF/, '' ).trim().split( /\r?\n/ ) ).toHaveLength( 1 );
		const erase = await page.request.post( '/wp-admin/admin-post.php', { form: { action: 'llamahire_erase_application', application: String( f.candidate ), _wpnonce: access.erase_nonce, confirm_erase: '1' } } );
		expect( erase.status() ).toBe( 403 );
		expect( await erase.text() ).toContain( 'You cannot erase candidate data.' );
		expect( ( await fixtures( 'state' ) ).candidate.status ).toBe( 'new' );
	} );

	test( '@privacy erasure removes candidate, notes and previously downloadable resume', async ( { page } ) => {
		await login( page );
		await page.goto( `${ adminApplications }&application=${ f.candidate }` );
		await page.locator( 'textarea[name="note"]' ).fill( 'Private erasure fixture note' );
		await page.getByRole( 'button', { name: 'Add note', exact: true } ).press( 'Enter' );
		await page.getByText( 'Manage candidate data', { exact: true } ).press( 'Enter' );
		await page.locator( '#llamahire-replacement-resume' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Upload resume', exact: true } ).press( 'Enter' );
		const url = await page.getByRole( 'link', { name: 'Download resume' } ).getAttribute( 'href' );
		const before = await page.request.get( url );
		expect( before.ok() ).toBeTruthy();
		expect( ( await before.body() ).subarray( 0, 4 ).toString() ).toBe( '%PDF' );
		await page.getByText( 'Manage candidate data', { exact: true } ).press( 'Enter' );
		await page.locator( '[name="confirm_erase"]' ).press( 'Space' );
		await page.getByRole( 'button', { name: 'Erase application', exact: true } ).press( 'Enter' );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'permanently erased' } ) ).toBeVisible();
		expect( ( await fixtures( 'state' ) ).candidate ).toBeNull();
		const after = await page.request.get( url );
		expect( after.ok() ).toBeFalsy();
		await page.goto( `${ adminApplications }&application=${ f.candidate }` );
		await expect( page.getByText( 'Private erasure fixture note', { exact: true } ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'link', { name: 'Download resume' } ) ).toHaveCount( 0 );
	} );

	test( '@notifications mail failure preserves submission and retry delivers once', async ( { page } ) => {
		await fixtures( 'mail-fail' );
		await apply( page, f.urls.open, f.registration_email );
		let state = await fixtures( 'state' );
		const candidate = state.applications.items.find( ( item ) => item.email === f.registration_email );
		expect( candidate.notification_status ).toBe( 'failed' );
		expect( state.applications.total ).toBe( 2 );
		await fixtures( 'mail-success' );
		await login( page );
		await page.goto( `${ adminApplications }&application=${ candidate.id }` );
		await expect( page.locator( '.llamahire-application-detail__email-delivery' ) ).toContainText( 'Attempts: 1' );
		await page.getByRole( 'button', { name: 'Retry missing emails' } ).press( 'Enter' );
		await expect( page.locator( '.llamahire-application-detail__email-delivery' ) ).toContainText( 'Sent' );
		await expect( page.locator( '.llamahire-application-detail__email-delivery' ) ).toContainText( 'Attempts: 2' );
		await expect( page.getByRole( 'button', { name: 'Retry missing emails' } ) ).toHaveCount( 0 );
		state = await fixtures( 'state' );
		const mailCount = state.mail_count;
		await page.context().clearCookies();
		await apply( page, f.urls.open, f.registration_email );
		state = await fixtures( 'state' );
		expect( state.applications.total ).toBe( 2 );
		expect( state.mail_count ).toBe( mailCount );
	} );

	test( '@settings application requirements and privacy notice affect the public form and server validation', async ( { page } ) => {
		await login( page );
		await page.goto( settingsURL );
		await section( page, 'Applications & privacy' );
		await page.getByLabel( 'Phone', { exact: true } ).selectOption( 'required' );
		await page.getByLabel( 'Resume', { exact: true } ).selectOption( 'hidden' );
		await page.getByLabel( 'Cover letter', { exact: true } ).selectOption( 'hidden' );
		await page.getByLabel( 'Privacy notice', { exact: true } ).fill( 'Flow privacy: only the receiving employer reviews this application.' );
		await saveSettings( page );
		await page.context().clearCookies();
		await page.goto( f.urls.open );
		await expect( page.locator( '[name="phone"]' ) ).toHaveAttribute( 'required', '' );
		await expect( page.locator( '[name="resume"]' ) ).toHaveCount( 0 );
		await expect( page.locator( '[name="cover_letter"]' ) ).toHaveCount( 0 );
		await expect( page.getByText( 'Flow privacy: only the receiving employer reviews this application.' ) ).toBeVisible();
		const nonce = await page.locator( '[name="llamahire_nonce"]' ).inputValue();
		const response = await page.request.post( '/wp-admin/admin-post.php', { form: { action: 'llamahire_apply', job_id: String( f.jobs.open ), llamahire_nonce: nonce, name: 'Required Phone Fixture', email: f.registration_email } } );
		expect( response.url() ).toContain( 'application=required' );
		expect( ( await fixtures( 'state' ) ).applications.total ).toBe( 1 );
	} );

	for ( const [ provider, widget, tokenField ] of [ [ 'turnstile', '.cf-turnstile', 'cf-turnstile-response' ], [ 'recaptcha', '.g-recaptcha', 'g-recaptcha-response' ] ] ) {
		test( `@settings ${ provider } activation is selective and failed verification persists neither candidate nor employer`, async ( { page } ) => {
			// Do not depend on external widgets or provider network availability.
			await page.route( /challenges.cloudflare.com|google.com\/recaptcha/, ( route ) => route.abort() );
			await login( page );
			await page.goto( settingsURL );
			await section( page, 'Applications & privacy' );
			await page.getByLabel( 'Provider', { exact: true } ).selectOption( provider );
			await page.getByLabel( 'Site key', { exact: true } ).fill( 'flow-public-key' );
			await saveSettings( page );
			await page.goto( f.urls.open );
			await expect( page.locator( widget ) ).toHaveCount( 0 );
			await page.goto( settingsURL );
			await section( page, 'Applications & privacy' );
			await page.getByLabel( 'Secret key', { exact: true } ).fill( 'flow-secret-key' );
			await saveSettings( page );
			await page.context().clearCookies();
			await page.goto( f.urls.open );
			await expect( page.locator( widget ) ).toHaveCount( 1 );
			await page.locator( '[name="candidate_name"]' ).fill( 'Spam Fixture' );
			await page.locator( '[name="email"]' ).fill( f.registration_email );
			await page.locator( '[data-llamahire-application-form]' ).evaluate( ( form, field ) => { const input = document.createElement( 'input' ); input.type = 'hidden'; input.name = field; input.value = 'invalid-fixture-token'; form.append( input ); }, tokenField );
			await page.getByRole( 'button', { name: 'Submit application' } ).press( 'Enter' );
			await expect( page.getByRole( 'alert' ) ).toContainText( /spam protection/i );
			expect( ( await fixtures( 'state' ) ).applications.total ).toBe( 1 );
			await page.goto( f.pages.employer_registration );
			await expect( page.locator( widget ) ).toHaveCount( 1 );
			await page.getByLabel( 'Your name', { exact: true } ).fill( 'Spam Employer' );
			await page.getByLabel( 'Company name', { exact: true } ).fill( 'Spam Company' );
			await page.getByLabel( 'Work email', { exact: true } ).fill( f.registration_email );
			await page.getByLabel( /^Password/ ).fill( password );
			await page.locator( '[name="accept_policy"]' ).press( 'Space' );
			await page.locator( 'form' ).filter( { has: page.locator( '[name=contact_name]' ) } ).evaluate( ( form, field ) => { const input = document.createElement( 'input' ); input.type = 'hidden'; input.name = field; input.value = 'invalid-fixture-token'; form.append( input ); }, tokenField );
			await page.getByRole( 'button', { name: 'Create employer account' } ).press( 'Enter' );
			await expect( page.getByRole( 'alert' ) ).toContainText( /spam protection/i );
			const state = await fixtures( 'state' );
			expect( state.registration_user ).toBe( '' );
			expect( state.spam_checks ).toBe( 2 );
			await login( page );
			await page.goto( settingsURL );
			await section( page, 'Applications & privacy' );
			await page.getByLabel( 'Candidate applications', { exact: true } ).press( 'Space' );
			await saveSettings( page );
			await page.context().clearCookies();
			await page.goto( f.urls.open );
			await expect( page.locator( widget ) ).toHaveCount( 0 );
			await page.goto( f.pages.employer_registration );
			await expect( page.locator( widget ) ).toHaveCount( 1 );
		} );

	}

	test( '@candidate @no-js application with a resume succeeds with JavaScript disabled', async ( { browser } ) => {
		const context = await browser.newContext( { javaScriptEnabled: false, baseURL: test.info().project.use.baseURL } );
		try {
			const page = await context.newPage();
			await apply( page, f.urls.open, f.registration_email, true );
			const state = await fixtures( 'state' );
			expect( state.applications.total ).toBe( 2 );
			const candidate = state.applications.items.find( ( item ) => item.email === f.registration_email );
			expect( candidate.status ).toBe( 'new' );
		} finally { await context.close().catch( () => {} ); }
	} );

	test( '@employer @no-js job draft, preview and submission work with JavaScript disabled', async ( { browser } ) => {
		const context = await browser.newContext( { javaScriptEnabled: false, baseURL: test.info().project.use.baseURL } );
		try {
			const page = await context.newPage();
			await login( page, f.users.owner, password );
			await page.goto( f.pages.submit_job );
			await page.locator( '[name="job_title"]' ).fill( 'Flow No JS Listing' );
			await page.getByRole( 'button', { name: 'Save draft', exact: true } ).press( 'Enter' );
			const row = page.getByRole( 'row' ).filter( { hasText: 'Flow No JS Listing' } );
			await expect( row ).toContainText( 'Draft' );
			await row.getByRole( 'link', { name: 'Edit', exact: true } ).press( 'Enter' );
			await page.getByLabel( 'Job description' ).fill( 'Created through the server-rendered employer form.' );
			await page.getByLabel( 'Company name' ).fill( 'Flow No JS Company' );
			await page.getByLabel( 'Location type' ).selectOption( 'onsite' );
			await page.locator( '[name=address_locality]' ).fill( 'Vancouver' );
			await page.locator( '[name=address_country]' ).selectOption( 'CA' );
			await page.getByLabel( 'How candidates apply' ).selectOption( 'internal' );
			await page.getByRole( 'textbox', { name: /^(Notification email|Application email)/ } ).fill( 'flow-inbox@example.test' );
			await page.getByRole( 'button', { name: 'Save and preview' } ).press( 'Enter' );
			await expect( page ).toHaveURL( /preview=true/ );
			await expect( page.getByRole( 'heading', { name: 'Flow No JS Listing', level: 1 } ) ).toBeVisible();
			await page.getByRole( 'button', { name: 'Submit for review' } ).press( 'Enter' );
			await expect( page.getByRole( 'row' ).filter( { hasText: 'Flow No JS Listing' } ) ).toContainText( 'Awaiting review' );
		} finally { await context.close().catch( () => {} ); }
	} );
} );
