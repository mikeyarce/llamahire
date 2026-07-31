const fs = require( 'node:fs/promises' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const candidateEmail = 'browser-test@example.test';
const adminUser = process.env.WP_ADMIN_USER || 'admin';
const adminPassword = process.env.WP_ADMIN_PASSWORD || 'password';
const employerUser = 'llamahire-employer';
const employerPassword = 'password';

async function logIn( page, login = adminUser, loginPassword = adminPassword ) {
	await page.goto( '/wp-login.php' );
	const username = page.locator( 'input[name="log"]' );
	const password = page.locator( 'input[name="pwd"]' );
	await password.fill( loginPassword );
	// Fill the username last because Chromium can intermittently replace it while handling the password field.
	await username.fill( login );
	if ( await password.inputValue() !== loginPassword ) {
		await password.fill( loginPassword );
	}
	await expect( username ).toHaveValue( login );
	await expect( password ).toHaveValue( loginPassword );
	await page.locator( '#wp-submit' ).evaluate( ( button ) => button.click() );
	await page.waitForLoadState( 'domcontentloaded' );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
}

async function openEditorPanel( page, title ) {
	let panel = page.getByRole( 'button', { name: title, exact: true } );
	if ( ! await panel.isVisible().catch( () => false ) ) {
		const settings = page.getByRole( 'button', { name: 'Settings', exact: true } ).first();
		if ( await settings.isVisible().catch( () => false ) ) {
			await settings.click();
		}
		panel = page.getByRole( 'button', { name: title, exact: true } );
	}
	await expect( panel ).toBeVisible();
	if ( await panel.getAttribute( 'aria-expanded' ) === 'false' ) {
		await panel.focus();
		await page.keyboard.press( 'Enter' );
		await expect( panel ).toHaveAttribute( 'aria-expanded', 'true' );
	}
}

async function openSettingsSection( page, name ) {
	const link = page.getByRole( 'navigation', { name: 'Settings sections' } ).getByRole( 'link', { name, exact: true } );
	await link.evaluate( ( element ) => element.click() );
	await expect( link ).toHaveAttribute( 'aria-current', 'page' );
}

test.describe.serial( 'complete hiring workflow', () => {
	test( 'administrator can choose a site purpose and complete first-run setup', async ( { page } ) => {
		await logIn( page );
		await expect( page.getByRole( 'heading', { name: 'Set up LlamaHire' } ) ).toBeVisible();
		await expect( page.getByText( 'Step 1 of 4 — Site purpose', { exact: true } ) ).toBeVisible();
		const setupProgress = page.getByRole( 'progressbar', { name: 'Setup progress: purpose, identity and defaults, applications and privacy, and public jobs page' } );
		await expect( setupProgress ).toHaveAttribute( 'value', '0' );
		await expect( setupProgress ).toHaveAttribute( 'max', '4' );
		await expect( setupProgress ).toHaveAttribute( 'aria-valuetext', 'Not complete' );
		await expect( page.locator( '[id="llamahire_save_setup_nonce"]' ) ).toHaveCount( 1 );
		await expect( page.locator( '[id="llamahire_skip_setup_nonce"]' ) ).toHaveCount( 0 );
		const companyMode = page.getByRole( 'radio', { name: /Company careers site/ } );
		const jobBoardMode = page.getByRole( 'radio', { name: /Multi-employer job board/ } );
		await expect( companyMode ).toBeChecked();
		await jobBoardMode.evaluate( ( input ) => input.click() );
		await expect( jobBoardMode ).toBeChecked();
		await expect( page.getByLabel( 'Organization website', { exact: true } ) ).toBeHidden();
		await expect( page.getByLabel( /Candidate privacy text/ ) ).toHaveValue( 'Candidate information is used only to review this application.' );
		await companyMode.evaluate( ( input ) => input.click() );
		await expect( companyMode ).toBeChecked();
		await expect( page.getByLabel( 'Organization website', { exact: true } ) ).toBeEnabled();
		await page.getByRole( 'button', { name: 'Continue' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByText( 'Step 2 of 4 — Identity & defaults', { exact: true } ) ).toBeVisible();
		await expect( setupProgress ).toHaveAttribute( 'value', '1' );
		await expect( page.getByRole( 'heading', { name: 'Identity & job defaults' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Organization identity' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Job defaults' } ) ).toBeVisible();
		await expect( page.getByLabel( 'Organization website', { exact: true } ) ).toBeVisible();
		await page.getByLabel( 'Organization name (required)', { exact: true } ).fill( 'Draft LlamaHire Employer' );
		await page.getByRole( 'button', { name: 'Save and finish later' } ).click();
		await expect( page ).toHaveURL( /post_type=llamahire_job.*llamahire_setup=skipped/ );
		await expect( page.getByText( /Setup progress saved\./ ) ).toBeVisible();

		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-setup' );
		await expect( page.getByText( 'Your saved Setup progress has been restored.' ) ).toBeVisible();
		await expect( page.getByText( 'Step 2 of 4 — Identity & defaults', { exact: true } ) ).toBeVisible();
		await expect( page.getByLabel( 'Organization name (required)', { exact: true } ) ).toHaveValue( 'Draft LlamaHire Employer' );
		await page.getByLabel( 'Organization name (required)', { exact: true } ).fill( 'LlamaHire CI Employer' );
		await page.getByLabel( 'Default city or locality', { exact: true } ).fill( 'Vancouver' );
		await page.getByLabel( 'Default state, province, or region', { exact: true } ).fill( 'BC' );
		await page.getByLabel( 'Default country', { exact: true } ).selectOption( 'CA' );
		await page.getByLabel( 'Default currency (required)', { exact: true } ).selectOption( 'CAD' );
		await page.getByRole( 'button', { name: 'Back' } ).evaluate( ( button ) => button.click() );
		await expect( companyMode ).toBeChecked();
		await page.getByRole( 'button', { name: 'Continue' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByLabel( 'Organization name (required)', { exact: true } ) ).toHaveValue( 'LlamaHire CI Employer' );
		await page.getByRole( 'button', { name: 'Continue' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByText( 'Step 3 of 4 — Applications & privacy', { exact: true } ) ).toBeVisible();
		await page.getByLabel( 'Hiring inbox (required)', { exact: true } ).fill( 'hiring@example.test' );
		await page.getByLabel( 'Candidate privacy text (required)', { exact: true } ).fill( 'We use candidate information only to review this application.' );
		await page.getByLabel( 'Privacy policy page', { exact: true } ).selectOption( { label: 'LlamaHire E2E Privacy' } );
		await page.getByLabel( 'Application retention', { exact: true } ).selectOption( '730' );
		const privacyPreview = page.getByRole( 'complementary', { name: 'Application privacy notice' } );
		await expect( privacyPreview ).toContainText( 'We use candidate information only to review this application.' );
		await expect( privacyPreview ).toContainText( 'LlamaHire E2E Privacy' );
		await page.getByRole( 'button', { name: 'Continue' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByText( 'Step 4 of 4 — Public jobs page', { exact: true } ) ).toBeVisible();
		await page.getByLabel( 'Create and publish a new public jobs page', { exact: true } ).check();
		await expect( page.getByLabel( 'New page title (required)', { exact: true } ) ).toBeEnabled();
		await expect( page.getByLabel( 'Existing public jobs page (required)', { exact: true } ) ).toBeDisabled();
		await page.getByLabel( 'New page title (required)', { exact: true } ).fill( 'LlamaHire E2E Careers' );
		await expect( page.getByText( 'Notifications: hiring@example.test', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( 'Retention: 2 years', { exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Edit applications and privacy' } ) ).toBeVisible();
		const saveSetup = page.getByRole( 'button', { name: 'Complete setup' } );
		await saveSetup.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /post_type=llamahire_job.*llamahire_setup=completed/ );
		await expect( page.getByText( 'LlamaHire setup is complete.' ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Setup', exact: true } ) ).toHaveCount( 0 );

		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-settings' );
		await expect( page.getByText( 'Setup complete', { exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Restart setup', exact: true } ) ).toBeVisible();
		await openSettingsSection( page, 'Site purpose' );
		await expect( page.getByRole( 'radio', { name: /Company careers site/ } ) ).toBeChecked();
		await openSettingsSection( page, 'Organization' );
		await expect( page.getByLabel( 'Organization name', { exact: true } ) ).toHaveValue( 'LlamaHire CI Employer' );
		await openSettingsSection( page, 'Job defaults' );
		await expect( page.getByLabel( 'Default city or locality', { exact: true } ) ).toHaveValue( 'Vancouver' );
		await openSettingsSection( page, 'Notifications' );
		await expect( page.getByLabel( 'Hiring inbox', { exact: true } ) ).toHaveValue( 'hiring@example.test' );
		await openSettingsSection( page, 'Applications & privacy' );
		await expect( page.getByLabel( 'Phone', { exact: true } ) ).toHaveValue( 'required' );
		await expect( page.getByLabel( 'Resume', { exact: true } ) ).toHaveValue( 'required' );
		await expect( page.getByLabel( 'Cover letter', { exact: true } ) ).toHaveValue( 'required' );
		await expect( page.getByLabel( 'Privacy notice', { exact: true } ) ).toHaveValue( 'We use candidate information only to review this application.' );
		await expect( page.locator( '#llamahire-privacy-page' ) ).toHaveValue( /\d+/ );
		await expect( page.getByLabel( 'Application retention', { exact: true } ) ).toHaveValue( '730' );
		await openSettingsSection( page, 'Pages' );
		await expect( page.getByRole( 'combobox', { name: 'Careers page', exact: true } ) ).toHaveValue( 'LlamaHire E2E Careers' );
		await expect( page.locator( '#llamahire-careers-page' ) ).toHaveValue( /\d+/ );
		await page.goto( '/llamahire-e2e-careers/' );
		await expect( page.getByRole( 'heading', { name: 'Do your best work with us' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await expect( page.getByText( '1 open role', { exact: true } ) ).toBeVisible();
		const filterForm = page.getByRole( 'search', { name: 'Filter jobs' } );
		await filterForm.getByLabel( 'Employment type' ).selectOption( 'full_time' );
		await filterForm.getByRole( 'button', { name: 'Apply filters' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /employment_type=full_time/ );
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		const searchForm = page.getByRole( 'search', { name: 'Search jobs' } );
		await searchForm.getByLabel( 'Search jobs' ).fill( 'No such role' );
		await searchForm.getByRole( 'button', { name: 'Search' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /job_search=No(?:\+|%20)such(?:\+|%20)role/ );
		await expect( page.getByRole( 'heading', { name: 'No matching open roles' } ) ).toBeVisible();
		const emptyStateClear = page.locator( '.llamahire-empty' ).getByRole( 'link', { name: 'Clear filters' } );
		await emptyStateClear.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).not.toHaveURL( /job_search|employment_type/ );
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
	} );

	test( 'administrator can review email settings and rendered previews', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-settings' );
		await openSettingsSection( page, 'Notifications' );
		await expect( page.getByLabel( 'Sender name', { exact: true } ) ).toHaveValue( 'LlamaHire Hiring' );
		await expect( page.getByLabel( 'Sender email', { exact: true } ) ).toHaveValue( 'jobs@example.test' );
		await expect( page.getByLabel( 'Employer subject', { exact: true } ) ).toHaveValue( 'New candidate: {candidate_name} for {job_title}' );
		await expect( page.getByLabel( 'Candidate message', { exact: true } ) ).toContainText( 'Thanks for applying to {site_name}.' );
		const employerPreview = page.locator( 'details' ).filter( { hasText: 'Employer email preview' } );
		await employerPreview.locator( 'summary' ).click();
		await expect( employerPreview ).toContainText( 'New candidate: Alex Candidate for Sample role' );
		const candidatePreview = page.locator( 'details' ).filter( { hasText: 'Candidate email preview' } );
		await candidatePreview.locator( 'summary' ).click();
		await expect( candidatePreview ).toContainText( 'Application received for Sample role' );
		await expect( page.getByRole( 'button', { name: 'Send test email' } ) ).toBeVisible();
	} );

	test( 'careers patterns compose at a narrow viewport', async ( { page } ) => {
		await page.setViewportSize( { width: 360, height: 800 } );
		await page.goto( '/llamahire-e2e-patterns/' );
		await expect( page.getByRole( 'heading', { name: 'Build your next chapter with us' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Featured opportunities' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Explore open roles' } ) ).toHaveAttribute( 'href', '#open-roles' );
		const patternsOverflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		expect( patternsOverflow ).toBeLessThanOrEqual( 1 );

		await page.goto( '/llamahire-e2e-department/' );
		await expect( page.getByRole( 'heading', { name: 'Do meaningful work with this team' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		const departmentForm = page.getByRole( 'search', { name: 'Search and filter jobs' } );
		await expect( departmentForm.locator( 'input[type="hidden"][name="department"][value="llamahire-e2e-engineering"]' ) ).toHaveCount( 1 );
		await expect( departmentForm.getByLabel( 'Department' ) ).toHaveCount( 0 );
		const departmentOverflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		expect( departmentOverflow ).toBeLessThanOrEqual( 1 );
	} );

	test( 'job editor saves structured Google Jobs fields', async ( { page } ) => {
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job' );
		await page.getByRole( 'link', { name: 'LlamaHire Browser Test Role', exact: true } ).first().click();

		const welcome = page.getByRole( 'dialog', { name: 'Welcome to the editor' } );
		const welcomeClose = welcome.getByRole( 'button', { name: 'Close', exact: true } );
		if ( await welcomeClose.waitFor( { state: 'visible', timeout: 5_000 } ).then( () => true ).catch( () => false ) ) {
			await page.keyboard.press( 'Escape' );
			await expect( welcome ).toBeHidden();
		}

		await openEditorPanel( page, 'Google Jobs readiness' );
		await expect( page.locator( '.components-notice__content' ).getByText( 'Required Google Jobs fields are complete.' ) ).toBeVisible();
		await openEditorPanel( page, 'Role and hiring status' );
		await expect( page.locator( '.components-notice__content' ).filter( { hasText: 'Published — accepting applications until its deadline.' } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Preview job', exact: true } ) ).toHaveAttribute( 'target', '_blank' );

		await openEditorPanel( page, 'Compensation' );
		await page.getByLabel( 'Minimum salary', { exact: true } ).fill( '95000' );

		await openEditorPanel( page, 'Hiring organization' );
		await page.getByLabel( 'Organization name override', { exact: true } ).fill( 'LlamaHire CI Employer Updated' );

		const save = page.getByRole( 'button', { name: /^(Save|Update)$/ } ).last();
		await expect( save ).toBeEnabled();
		await save.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByText( /saved|updated/i ).first() ).toBeVisible();

		await page.reload();
		await openEditorPanel( page, 'Compensation' );
		await expect( page.getByLabel( 'Minimum salary', { exact: true } ) ).toHaveValue( '95000' );
		await openEditorPanel( page, 'Hiring organization' );
		await expect( page.getByLabel( 'Organization name override', { exact: true } ) ).toHaveValue( 'LlamaHire CI Employer Updated' );
		expect( errors.filter( ( message ) => message !== 'Transition was skipped' ) ).toEqual( [] );
	} );

	test( 'candidate sees matching schema and submits a resume', async ( { page } ) => {
		await page.goto( '/jobs/llamahire-e2e-job/' );
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await expect( page.getByText( 'LlamaHire CI Employer Updated' ) ).toBeVisible();
		await expect( page.getByText( /95,000.*110,000/ ) ).toBeVisible();
		await expect( page.getByText( 'We use candidate information only to review this application.' ) ).toBeVisible();
		await expect( page.getByText( 'Application records are scheduled for deletion from this site after 365 days.' ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Read our privacy policy.' } ) ).toHaveAttribute( 'href', /llamahire-e2e-privacy/ );
		await expect( page.locator( 'input[name="resume"]' ) ).toHaveAttribute( 'aria-describedby', 'llamahire-resume-help' );
		await expect( page.locator( 'input[name="phone"]' ) ).toHaveAttribute( 'required', '' );
		await expect( page.locator( 'input[name="resume"]' ) ).toHaveAttribute( 'required', '' );
		await expect( page.locator( 'textarea[name="cover_letter"]' ) ).toHaveAttribute( 'required', '' );
		await expect( page.getByRole( 'button', { name: 'Submit application' } ) ).toHaveAttribute( 'aria-describedby', 'llamahire-application-privacy' );
		await expect( page.locator( '[data-upload-feedback]' ) ).toBeHidden();

		await page.goto( '/jobs/llamahire-e2e-job/?application=required#llamahire-application' );
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Please complete all required fields and provide a valid email address.' );

		const schemaBlocks = await page.locator( 'script[type="application/ld+json"]' ).allTextContents();
		const entities = schemaBlocks.flatMap( ( block ) => {
			const value = JSON.parse( block );
			return value[ '@graph' ] || [ value ];
		} );
		const jobPosting = entities.find( ( entity ) => entity[ '@type' ] === 'JobPosting' );
		expect( jobPosting ).toBeTruthy();
		expect( jobPosting.hiringOrganization.name ).toBe( 'LlamaHire CI Employer Updated' );
		expect( jobPosting.baseSalary.value.minValue ).toBe( 95000 );
		expect( jobPosting.baseSalary.value.maxValue ).toBe( 110000 );

		await page.goto( '/jobs/llamahire-e2e-job/' );
		await expect( page.getByRole( 'alert' ) ).toHaveCount( 0 );
		await page.locator( 'input[name="name"]' ).fill( 'Browser Test Candidate' );
		await page.locator( 'input[name="email"]' ).fill( candidateEmail );
		await page.locator( 'input[name="phone"]' ).fill( '555-0199' );
		await page.locator( 'textarea[name="cover_letter"]' ).fill( '=CI formula safety check' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		let interruptNextSubmission = true;
		await page.route( '**/wp-admin/admin-post.php', async ( route ) => {
			if ( interruptNextSubmission ) {
				interruptNextSubmission = false;
				await new Promise( ( resolve ) => setTimeout( resolve, 750 ) );
				await route.abort( 'failed' );
				return;
			}
			await route.continue();
		} );
		const submitApplication = page.getByRole( 'button', { name: 'Submit application' } );
		await Promise.all( [
			expect( submitApplication ).toBeDisabled(),
			expect( page.locator( '[data-upload-feedback]' ) ).toBeVisible(),
			expect( page.getByRole( 'progressbar', { name: 'Resume upload progress' } ) ).toBeVisible(),
			submitApplication.click()
		] );
		await expect( page.locator( '[data-upload-status]' ) ).toHaveAttribute( 'role', 'alert' );
		await expect( page.locator( '[data-upload-status]' ) ).toContainText( 'Check your connection and try again.' );
		await expect( page.locator( '[data-upload-status]' ) ).toBeFocused();
		await expect( submitApplication ).toBeEnabled();
		await expect( page.locator( 'form[data-llamahire-application-form]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
		await expect( page.locator( 'input[name="resume"]' ) ).toHaveValue( /fixture-resume\.pdf$/ );
		await page.locator( 'input[name="phone"]' ).fill( 'abcd' );
		await submitApplication.click();
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Not a valid phone number.' );
		await expect( page.locator( 'input[name="phone"]' ) ).toHaveValue( 'abcd' );
		await expect( page.locator( 'input[name="resume"]' ) ).toHaveValue( '' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.locator( 'input[name="phone"]' ).fill( '' );
		await page.locator( 'input[name="phone"]' ).evaluate( ( field ) => field.removeAttribute( 'required' ) );
		await submitApplication.click();
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Please complete all required fields' );
		await expect( page.locator( 'input[name="name"]' ) ).toHaveValue( 'Browser Test Candidate' );
		await expect( page.locator( 'input[name="email"]' ) ).toHaveValue( candidateEmail );
		await expect( page.locator( 'textarea[name="cover_letter"]' ) ).toHaveValue( '=CI formula safety check' );
		await expect( page.locator( 'input[name="resume"]' ) ).toHaveValue( '' );
		await page.unroute( '**/wp-admin/admin-post.php' );

		await page.locator( 'input[name="phone"]' ).fill( '555-0199' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Submit application' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks! Your application has been received.' );

		await page.goto( '/jobs/llamahire-e2e-job/' );
		await page.locator( 'input[name="name"]' ).fill( 'Duplicate Browser Candidate' );
		await page.locator( 'input[name="email"]' ).fill( candidateEmail.toUpperCase() );
		await page.locator( 'input[name="phone"]' ).fill( '555-0101' );
		await page.locator( 'textarea[name="cover_letter"]' ).fill( 'This repeat submission must not replace the original.' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Submit application' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'We already have your application for this role. No further action is needed.' );
		await expect( page.locator( 'form[data-llamahire-application-form]' ) ).toHaveCount( 0 );
	} );

	test( 'recruiter reviews, exports, and downloads securely', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/admin.php?page=llamahire-applications' );
		await expect( page.getByText( candidateEmail ) ).toHaveCount( 1 );
		await expect( page.getByRole( 'link', { name: 'Duplicate Browser Candidate', exact: true } ) ).toHaveCount( 0 );
		await page.getByRole( 'link', { name: 'Browser Test Candidate', exact: true } ).click();
		const candidateCard = page.locator( '.card' ).filter( { has: page.getByRole( 'heading', { name: 'Notifications', exact: true } ) } );
		await expect( candidateCard ).toContainText( 'Attempts: 1' );

		await page.getByLabel( 'Status', { exact: true } ).selectOption( 'reviewing' );
		await page.getByLabel( 'Private notes', { exact: true } ).fill( 'Reviewed by the browser integration suite.' );
		const saveChanges = page.getByRole( 'button', { name: 'Save changes' } );
		await saveChanges.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Application review saved.' );
		await expect( page.getByLabel( 'Status', { exact: true } ) ).toHaveValue( 'reviewing' );
		await expect( page.getByLabel( 'Private notes', { exact: true } ) ).toHaveValue( 'Reviewed by the browser integration suite.' );
		const activityCard = page.locator( '.card' ).filter( { has: page.getByRole( 'heading', { name: 'Activity', exact: true } ) } );
		await expect( activityCard ).toContainText( 'Application status changed: New → Reviewing' );

		const resumePromise = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Download resume' } ).focus();
		await page.keyboard.press( 'Enter' );
		const resume = await resumePromise;
		expect( resume.suggestedFilename() ).toBe( 'fixture-resume.pdf' );
		const resumeBytes = await fs.readFile( await resume.path() );
		expect( resumeBytes.subarray( 0, 4 ).toString() ).toBe( '%PDF' );
		await page.locator( '#llamahire-replacement-resume' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.locator( 'button', { hasText: 'Replace resume' } ).press( 'Enter' );
		await expect( page.getByRole( 'status' ) ).toContainText( 'The private resume was replaced.' );
		await expect( page.getByRole( 'link', { name: 'Download resume' } ) ).toBeVisible();
		const confirmResumeDeletion = page.getByLabel( 'I understand the current resume will be permanently deleted.' );
		await confirmResumeDeletion.evaluate( ( input ) => input.click() );
		await expect( confirmResumeDeletion ).toBeChecked();
		await page.getByRole( 'button', { name: 'Delete resume permanently' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ) ).toContainText( 'The private resume was permanently deleted.' );
		await expect( page.getByRole( 'link', { name: 'Download resume' } ) ).toHaveCount( 0 );

		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications' );
		const applicationSearch = page.getByRole( 'searchbox', { name: 'Search candidates' } );
		await applicationSearch.fill( candidateEmail );
		await expect( page ).toHaveURL( /s=browser-test%40example\.test/ );
		await expect( page.getByRole( 'status' ) ).toContainText( '1 matching applications' );
		const candidateResultRow = page.getByRole( 'row' ).filter( { hasText: candidateEmail } );
		await expect( candidateResultRow ).toBeVisible();
		const candidateEmailStatus = ( await candidateResultRow.getByRole( 'cell' ).nth( 3 ).innerText() ).trim();
		await page.getByRole( 'button', { name: 'Add filter' } ).evaluate( ( button ) => button.click() );
		await page.getByRole( 'menuitem', { name: 'Job', exact: true } ).evaluate( ( item ) => item.click() );
		await page.getByRole( 'option', { name: 'LlamaHire Browser Test Role', exact: true } ).evaluate( ( option ) => option.click() );
		await expect( page ).toHaveURL( /job_ids=\d+/ );
		await expect( page.getByRole( 'status' ) ).toContainText( '1 matching applications' );
		await page.getByRole( 'button', { name: 'Add filter' } ).evaluate( ( button ) => button.click() );
		await page.getByRole( 'menuitem', { name: 'Status', exact: true } ).evaluate( ( item ) => item.click() );
		await page.getByRole( 'option', { name: 'Reviewing', exact: true } ).evaluate( ( option ) => option.click() );
		await expect( page ).toHaveURL( /statuses=reviewing/ );
		await page.getByRole( 'button', { name: 'Add filter' } ).evaluate( ( button ) => button.click() );
		await page.getByRole( 'menuitem', { name: 'Email status', exact: true } ).evaluate( ( item ) => item.click() );
		await page.getByRole( 'option', { name: candidateEmailStatus, exact: true } ).evaluate( ( option ) => option.click() );
		await expect( page ).toHaveURL( new RegExp( `notification_statuses=${ candidateEmailStatus.toLowerCase() }` ) );
		await expect( page.getByRole( 'status' ) ).toContainText( '1 matching applications' );
		await expect( page.getByRole( 'row' ).filter( { hasText: candidateEmail } ) ).toBeVisible();
		const csvPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Export filtered CSV' } ).evaluate( ( link ) => link.click() );
		const csv = await csvPromise;
		const csvContents = await fs.readFile( await csv.path(), 'utf8' );
		expect( csvContents ).toContain( candidateEmail );
		expect( csvContents ).toContain( "'=CI formula safety check" );
		const receivedDate = new Date().toISOString().slice( 0, 10 );
		await page.goto( `/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications&candidate=Browser%20Test&email=${ encodeURIComponent( candidateEmail ) }&received_operator=on&received=${ receivedDate }` );
		await expect( page.getByRole( 'status' ) ).toContainText( '1 matching applications' );
		await page.getByRole( 'button', { name: 'Filter', exact: true } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'button', { name: /Candidate contains:/ } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: /Candidate email contains:/ } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: /Received is:/ } ) ).toBeVisible();
		await page.reload();
		await expect( page ).toHaveURL( /candidate=Browser(?:%20|\+)Test/ );
		await expect( page.getByRole( 'status' ) ).toContainText( '1 matching applications' );
		await page.setViewportSize( { width: 390, height: 844 } );
		await expect( page.getByRole( 'button', { name: 'Filter', exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Export filtered CSV' } ) ).toBeVisible();
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) ).toBeTruthy();
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( '/wp-admin/admin.php?page=llamahire-activity' );
		await expect( page.getByRole( 'heading', { name: 'Hiring activity' } ) ).toBeVisible();
		await expect( page.getByText( 'Application status changed: New → Reviewing' ).first() ).toBeVisible();
		await expect( page.getByText( candidateEmail ) ).toHaveCount( 0 );
	} );

	test( 'approved employer submits a branded job for board moderation', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-settings' );
		await openSettingsSection( page, 'Site purpose' );
		await page.getByRole( 'radio', { name: /Community job board/ } ).check();
		await page.getByRole( 'button', { name: 'Save changes' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /#llamahire-settings-site-purpose$/ );
		await expect( page.getByText( 'Settings saved.', { exact: true } ) ).toBeVisible();
		await openSettingsSection( page, 'Site purpose' );
		await expect( page.getByRole( 'heading', { name: 'Employer access' } ) ).toBeVisible();
		await openSettingsSection( page, 'Pages' );
		await expect( page.getByLabel( 'Submit a Job page' ) ).toHaveValue( /\d+/ );
		await expect( page.getByLabel( 'My Jobs page' ) ).toHaveValue( /\d+/ );

		await page.context().clearCookies();
		await logIn( page, employerUser, employerPassword );
		await page.goto( '/submit-a-job/' );
		await expect( page.getByRole( 'heading', { name: 'Submit a job', level: 2, exact: true } ) ).toBeVisible();
		await page.getByLabel( 'Job title' ).fill( 'Employer Browser Test Role' );
		await page.getByLabel( 'Job description' ).fill( 'A moderated job submitted from the employer portal.' );
		await page.getByLabel( 'Company name' ).fill( 'Browser Test Company' );
		await page.getByLabel( 'Company tagline' ).fill( 'Good work, close to home.' );
		await page.getByLabel( 'Company website' ).fill( 'https://example.test/' );
		await page.getByLabel( 'Company logo' ).setInputFiles( path.resolve( 'docs/audits/2026-07-20-accessibility-review/01-setup.png' ) );
		await page.getByLabel( 'Location' ).fill( 'Hamilton, ON' );
		await page.getByLabel( 'Work style' ).selectOption( 'hybrid' );
		await page.getByLabel( 'Employment type' ).selectOption( 'FULL_TIME' );
		await page.getByLabel( 'How candidates apply' ).selectOption( 'internal' );
		await page.getByLabel( 'Application email or URL' ).fill( 'employer-applications@example.test' );
		await page.getByRole( 'button', { name: 'Submit for review' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /\/my-jobs\/.*job_submitted=1/ );
		await expect( page.getByRole( 'status' ) ).toContainText( 'submitted for moderation' );
		const employerRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await expect( employerRow ).toContainText( 'Pending' );
		await page.goto( await employerRow.getByRole( 'link', { name: 'Edit' } ).getAttribute( 'href' ) );
		await expect( page.getByRole( 'heading', { name: 'Edit job listing', level: 2, exact: true } ) ).toBeVisible();
		await expect( page.getByLabel( 'Job title' ) ).toHaveValue( 'Employer Browser Test Role' );
		await expect( page.getByLabel( 'Company logo' ).locator( 'xpath=preceding-sibling::img' ) ).toHaveCount( 1 );

		await page.context().clearCookies();
		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job' );
		await page.goto( await page.getByRole( 'link', { name: 'Employer Browser Test Role', exact: true } ).first().getAttribute( 'href' ) );
		const welcome = page.getByRole( 'dialog', { name: 'Welcome to the editor' } );
		if ( await welcome.isVisible().catch( () => false ) ) {
			await page.keyboard.press( 'Escape' );
		}
		const publish = page.getByRole( 'button', { name: 'Publish', exact: true } ).first();
		await expect( publish ).toBeVisible();
		await publish.evaluate( ( button ) => button.click() );
		const confirmPublish = page.getByRole( 'button', { name: 'Publish', exact: true } ).last();
		if ( await confirmPublish.isVisible().catch( () => false ) ) {
			await confirmPublish.evaluate( ( button ) => button.click() );
		}
		await expect( page.getByText( /published/i ).first() ).toBeVisible();

		await page.context().clearCookies();
		await logIn( page, employerUser, employerPassword );
		await page.goto( '/my-jobs/' );
		const publishedRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await expect( publishedRow ).toContainText( 'Published' );
		await expect( publishedRow.getByRole( 'link', { name: 'Preview' } ) ).toHaveAttribute( 'target', '_blank' );
		await page.goto( '/jobs/employer-browser-test-role/' );
		await expect( page.getByText( 'Your application will be shared with Browser Test Company for hiring review.', { exact: true } ) ).toBeVisible();
		await page.goto( '/submit-a-job/' );
		await expect( page.getByLabel( 'Company name' ) ).toHaveValue( 'Browser Test Company' );
		await expect( page.getByLabel( 'Application email or URL' ) ).toHaveValue( 'employer-applications@example.test' );
		await page.goto( '/my-jobs/' );
		const deleteRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await deleteRow.getByText( 'Delete', { exact: true } ).evaluate( ( summary ) => summary.click() );
		await deleteRow.getByLabel( 'Move this listing to the trash and remove it from My Jobs.' ).evaluate( ( checkbox ) => { checkbox.checked = true; checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
		await deleteRow.getByRole( 'button', { name: 'Confirm delete' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ) ).toContainText( 'job listing was deleted' );
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } ) ).toHaveCount( 0 );
	} );
} );
