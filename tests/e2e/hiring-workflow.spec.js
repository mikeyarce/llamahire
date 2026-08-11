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
	test( '@critical @setup @company @discovery administrator can choose a site purpose and complete first-run setup', async ( { page } ) => {
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
		await expect( page.getByRole( 'heading', { name: 'Job defaults', exact: true } ) ).toBeVisible();
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
		await expect( page.getByLabel( 'Provider', { exact: true } ) ).toHaveValue( 'none' );
		await expect( page.getByLabel( 'Candidate applications', { exact: true } ) ).toBeChecked();
		await openSettingsSection( page, 'Pages' );
		await expect( page.getByRole( 'combobox', { name: 'Careers page', exact: true } ) ).toHaveValue( 'LlamaHire E2E Careers' );
		await expect( page.locator( '#llamahire-careers-page' ) ).toHaveValue( /\d+/ );
		await page.goto( '/llamahire-e2e-careers/' );
		await expect( page.getByRole( 'heading', { name: 'Do your best work with us' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await expect( page.getByText( '1 open role', { exact: true } ) ).toBeVisible();
		const filterForm = page.getByRole( 'search', { name: 'Filter jobs' } );
		await filterForm.locator( '.llamahire-filter-menu--location summary' ).evaluate( ( summary ) => summary.click() );
		const locationSearch = filterForm.getByRole( 'searchbox', { name: 'Search available locations' } );
		await locationSearch.fill( 'Vancouver' );
		await expect( filterForm.getByRole( 'checkbox', { name: /Vancouver/ } ) ).toBeVisible();
		await locationSearch.press( 'Enter' );
		await expect( page ).toHaveURL( /location=Vancouver/ );
		await expect( locationSearch ).toHaveValue( '' );
		await expect( locationSearch ).toBeFocused();
		await expect( page.getByRole( 'link', { name: /Remove Vancouver.*filter/ } ) ).toBeVisible();
		await page.getByRole( 'link', { name: /Remove Vancouver.*filter/ } ).evaluate( ( link ) => link.click() );
		await expect( page ).not.toHaveURL( /location=/ );
		await filterForm.locator( '.llamahire-filter-menu--employment_type summary' ).evaluate( ( summary ) => summary.click() );
		await filterForm.getByRole( 'checkbox', { name: 'Full time' } ).evaluate( ( input ) => { input.focus(); input.click(); } );
		await expect( page ).toHaveURL( /employment_type=full_time/ );
		const partTimeFilter = filterForm.getByRole( 'checkbox', { name: 'Part time' } );
		await partTimeFilter.evaluate( ( input ) => { input.focus(); input.click(); } );
		await expect( page ).toHaveURL( /employment_type=full_time%2Cpart_time/ );
		await expect( partTimeFilter ).toBeFocused();
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Remove Full time filter' } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Remove Part time filter' } ) ).toBeVisible();
		const jobsFeedLink = page.getByRole( 'link', { name: /Subscribe to these jobs \(RSS\)/ } );
		await expect( jobsFeedLink ).toHaveAttribute( 'target', '_blank' );
		const jobsFeedUrl = await jobsFeedLink.getAttribute( 'href' );
		const parsedJobsFeedUrl = new URL( jobsFeedUrl );
		expect( parsedJobsFeedUrl.pathname ).toBe( '/feed/llamahire-jobs/' );
		expect( parsedJobsFeedUrl.searchParams.get( 'employment_type' ) ).toBe( 'full_time,part_time' );
		const jobsFeedResponse = await page.request.get( jobsFeedUrl );
		expect( jobsFeedResponse.headers()['content-type'] ).toContain( 'text/xml' );
		expect( await jobsFeedResponse.text() ).toContain( '<title>LlamaHire Browser Test Role</title>' );
		await expect( filterForm.getByRole( 'button', { name: 'Apply filters' } ) ).toBeHidden();
		const searchForm = page.getByRole( 'search', { name: 'Search jobs' } );
		await searchForm.getByLabel( 'Search jobs' ).fill( 'No such role' );
		await expect( page ).toHaveURL( /job_search=No(?:\+|%20)such(?:\+|%20)role/ );
		await expect( page.getByRole( 'heading', { name: 'No matching open roles' } ) ).toBeVisible();
		const emptyStateClear = page.locator( '.llamahire-empty' ).getByRole( 'link', { name: 'Clear filters' } );
		await emptyStateClear.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).not.toHaveURL( /job_search|employment_type/ );
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
	} );

	test( '@settings @notifications administrator can review email settings and rendered previews', async ( { page } ) => {
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

	test( '@public @responsive careers patterns compose at a narrow viewport', async ( { page } ) => {
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

	test( '@jobs @admin job editor saves structured Google Jobs fields', async ( { page } ) => {
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
		await expect( page.locator( '.components-notice__content' ).filter( { hasText: 'Published — accepting applications until its application deadline or listing expiration.' } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Preview job', exact: true } ) ).toHaveAttribute( 'target', '_blank' );

		await openEditorPanel( page, 'Compensation' );
		await page.getByLabel( 'Minimum salary', { exact: true } ).fill( '95000' );

		await openEditorPanel( page, 'Hiring organization' );
		await page.getByLabel( 'Organization name override', { exact: true } ).fill( 'LlamaHire CI Employer Updated' );

		const save = page.getByRole( 'button', { name: /^(Save|Update)$/ } ).last();
		await expect( save ).toBeEnabled();
		const saveResponse = page.waitForResponse( ( response ) => response.request().method() === 'POST' && /\/wp-json\/wp\/v2\/llamahire_job\/\d+$/.test( new URL( response.url() ).pathname ) );
		await save.focus();
		await page.keyboard.press( 'Enter' );
		expect( ( await saveResponse ).ok() ).toBe( true );
		await expect( save ).toBeDisabled();

		await page.reload();
		await openEditorPanel( page, 'Compensation' );
		await expect( page.getByLabel( 'Minimum salary', { exact: true } ) ).toHaveValue( '95000' );
		await openEditorPanel( page, 'Hiring organization' );
		await expect( page.getByLabel( 'Organization name override', { exact: true } ) ).toHaveValue( 'LlamaHire CI Employer Updated' );
		expect( errors.filter( ( message ) => message !== 'Transition was skipped' ) ).toEqual( [] );
	} );

	test( '@candidate @applications candidate sees matching schema and submits a resume', async ( { page } ) => {
		await page.goto( '/jobs/llamahire-e2e-job/' );
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await expect( page.getByText( 'LlamaHire CI Employer Updated' ) ).toBeVisible();
		await expect( page.getByText( /95,000.*110,000/ ) ).toBeVisible();
		await expect( page.getByText( 'We use candidate information only to review this application.' ) ).toBeVisible();
		await expect( page.getByText( 'Application records are scheduled for deletion from this site after 730 days.' ) ).toBeVisible();
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
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks! Your application has been received.' );
		await expect( page.locator( 'form[data-llamahire-application-form]' ) ).toHaveCount( 0 );
	} );

	test( '@critical @candidate @applications candidate submits the core application flow', async ( { page } ) => {
		await page.goto( '/jobs/llamahire-e2e-job/' );
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
		await page.locator( 'input[name="name"]' ).fill( 'Critical Flow Candidate' );
		await page.locator( 'input[name="email"]' ).fill( 'critical-browser@example.test' );
		await page.locator( 'input[name="phone"]' ).fill( '555-0123' );
		await page.locator( 'textarea[name="cover_letter"]' ).fill( 'Fast release-path application.' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Submit application' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks! Your application has been received.' );
	} );

	test( '@critical @recruiter @applications recruiter advances the core candidate lifecycle', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/admin.php?page=llamahire-applications' );
		const candidateDetailUrl = await page.getByRole( 'link', { name: 'Critical Flow Candidate', exact: true } ).getAttribute( 'href' );
		await page.goto( candidateDetailUrl );
		await page.getByLabel( 'Status', { exact: true } ).selectOption( 'reviewing' );
		await page.getByRole( 'button', { name: 'Save status' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Application review saved.' );
		await page.getByLabel( 'Add private note', { exact: true } ).fill( 'Reviewed in the fast release path.' );
		await page.getByRole( 'button', { name: 'Add note' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Private note added.' );
		await expect( page.getByRole( 'link', { name: 'Download resume' } ) ).toBeVisible();
	} );

	test( '@recruiter @applications recruiter reviews a candidate and manages the private resume', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/admin.php?page=llamahire-applications' );
		await expect( page.getByText( candidateEmail ) ).toHaveCount( 1 );
		await expect( page.getByRole( 'link', { name: 'Duplicate Browser Candidate', exact: true } ) ).toHaveCount( 0 );
		const candidateDetailUrl = await page.getByRole( 'link', { name: 'Browser Test Candidate', exact: true } ).getAttribute( 'href' );
		await page.goto( candidateDetailUrl );
		const candidateCard = page.locator( '.card' ).filter( { has: page.getByRole( 'heading', { name: 'Notifications', exact: true } ) } );
		await expect( candidateCard ).toContainText( 'Attempts: 1' );

		await page.getByLabel( 'Status', { exact: true } ).selectOption( 'reviewing' );
		const saveStatus = page.getByRole( 'button', { name: 'Save status' } );
		await saveStatus.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Application review saved.' );
		await expect( page.getByLabel( 'Status', { exact: true } ) ).toHaveValue( 'reviewing' );
		await page.getByLabel( 'Add private note', { exact: true } ).fill( 'Reviewed by the browser integration suite.' );
		const addNote = page.getByRole( 'button', { name: 'Add note' } );
		await addNote.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Private note added.' );
		await expect( page.getByText( 'Reviewed by the browser integration suite.' ) ).toBeVisible();
		await expect( page.getByLabel( 'Add private note', { exact: true } ) ).toHaveValue( '' );
		const activityCard = page.locator( '.card' ).filter( { has: page.getByRole( 'heading', { name: 'Activity', exact: true } ) } );
		await expect( activityCard ).toContainText( 'Application status changed: New → Reviewing' );
		await expect( page.getByRole( 'link', { name: 'View resume' } ) ).toHaveAttribute( 'target', '_blank' );

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
	} );

	test( '@recruiter @applications recruiter filters, exports, and bulk-updates candidates', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications' );
		await page.getByRole( 'button', { name: 'Received', exact: true } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /order=asc/ );
		const ensureViewOptionsOpen = async () => {
			const button = page.getByRole( 'button', { name: 'View options' } );
			if ( 'true' !== await button.getAttribute( 'aria-expanded' ) ) {
				await button.click( { force: true } );
			}
			await expect( button ).toHaveAttribute( 'aria-expanded', 'true' );

			return button;
		};
		await ensureViewOptionsOpen();
		const resetSortedApplicationView = page.getByRole( 'button', { name: 'Reset view' } );
		await expect( resetSortedApplicationView ).toBeEnabled();
		await resetSortedApplicationView.click( { force: true } );
		await expect( page ).not.toHaveURL( /order=asc/ );
		await ensureViewOptionsOpen();
		await page.getByRole( 'radio', { name: 'Compact' } ).check( { force: true } );
		await page.getByRole( 'radio', { name: '50', exact: true } ).check( { force: true } );
		await expect( page ).not.toHaveURL( /(?:per_page|layout)=/ );
		await page.reload();
		await ensureViewOptionsOpen();
		await expect( page.getByRole( 'radio', { name: 'Compact' } ) ).toBeChecked();
		await expect( page.getByRole( 'radio', { name: '50', exact: true } ) ).toBeChecked();
		const resetApplicationView = page.getByRole( 'button', { name: 'Reset view' } );
		await expect( resetApplicationView ).toBeEnabled();
		await resetApplicationView.click( { force: true } );
		await expect( page.getByRole( 'radio', { name: 'Balanced' } ) ).toBeChecked();
		await expect( page.getByRole( 'radio', { name: '20', exact: true } ) ).toBeChecked();
		await expect( resetApplicationView ).toBeDisabled();
		const applicationSearch = page.getByRole( 'searchbox', { name: 'Search candidates' } );
		await applicationSearch.fill( candidateEmail );
		await expect( page ).toHaveURL( /s=browser-test%40example\.test/ );
		await expect( page.getByRole( 'status' ) ).toContainText( '1 matching applications' );
		const candidateResultRow = page.getByRole( 'row' ).filter( { hasText: candidateEmail } );
		await expect( candidateResultRow ).toBeVisible();
		const candidateReviewLink = candidateResultRow.getByRole( 'link', { name: 'Browser Test Candidate', exact: true } );
		await candidateReviewLink.focus();
		await page.keyboard.press( 'Enter' );
		const inlineReview = page.getByRole( 'region', { name: 'Review Browser Test Candidate' } );
		await expect( inlineReview ).toBeVisible();
		await expect( inlineReview ).toBeFocused();
		await expect( inlineReview.getByText( 'Text response' ) ).toBeVisible();
		const coverLetterTrigger = inlineReview.getByRole( 'button', { name: 'View', exact: true } );
		await coverLetterTrigger.focus();
		await page.keyboard.press( 'Enter' );
		const coverLetterDialog = page.getByRole( 'dialog', { name: 'Cover letter' } );
		await expect( coverLetterDialog ).toContainText( '=CI formula safety check' );
		await coverLetterDialog.getByRole( 'button', { name: 'Close' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( coverLetterTrigger ).toBeFocused();
		await inlineReview.getByLabel( 'Add private note' ).fill( 'Reviewed from the compact application row.' );
		await inlineReview.getByRole( 'button', { name: 'Add note' } ).evaluate( ( button ) => button.click() );
		await expect( inlineReview.getByRole( 'status' ) ).toContainText( 'Private note added.' );
		await expect( inlineReview.getByText( 'Reviewed from the compact application row.' ) ).toBeVisible();
		await expect( inlineReview.getByLabel( 'Add private note' ) ).toHaveValue( '' );
		const feedback = inlineReview.locator( '.llamahire-inline-review__confirmation' );
		const firstFeedbackBox = await feedback.boundingBox();
		await inlineReview.getByLabel( 'Add private note' ).fill( 'Second compact review note.' );
		await inlineReview.getByRole( 'button', { name: 'Add note' } ).evaluate( ( button ) => button.click() );
		await expect( inlineReview.getByText( 'Second compact review note.' ) ).toBeVisible();
		const secondFeedbackBox = await feedback.boundingBox();
		expect( secondFeedbackBox?.height ).toBe( firstFeedbackBox?.height );
		expect( secondFeedbackBox?.y ).toBe( firstFeedbackBox?.y );
		await inlineReview.getByRole( 'button', { name: 'View all activity' } ).evaluate( ( button ) => button.click() );
		const activityHistoryDialog = page.getByRole( 'dialog', { name: 'Activity history' } );
		await expect( activityHistoryDialog ).toContainText( 'Private note added' );
		await activityHistoryDialog.getByRole( 'button', { name: 'Close' } ).evaluate( ( button ) => button.click() );
		await inlineReview.getByRole( 'button', { name: 'View all notes' } ).evaluate( ( button ) => button.click() );
		const noteHistoryDialog = page.getByRole( 'dialog', { name: 'Private notes' } );
		await expect( noteHistoryDialog ).toContainText( 'Reviewed from the compact application row.' );
		await expect( noteHistoryDialog ).toContainText( 'Second compact review note.' );
		expect( await noteHistoryDialog.locator( '.llamahire-history-list' ).evaluate( ( list ) => getComputedStyle( list ).overflowY ) ).toBe( 'visible' );
		await noteHistoryDialog.getByRole( 'button', { name: 'Close' } ).evaluate( ( button ) => button.click() );
		await inlineReview.getByRole( 'button', { name: 'Collapse review' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( inlineReview ).toHaveCount( 0 );
		await expect( candidateReviewLink ).toBeFocused();
		const candidateEmailStatus = ( await candidateResultRow.getByRole( 'cell' ).nth( 4 ).innerText() ).trim();
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
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications' );
		await page.getByRole( 'button', { name: 'Add filter' } ).evaluate( ( button ) => button.click() );
		await page.getByRole( 'menuitem', { name: 'Job', exact: true } ).evaluate( ( item ) => item.click() );
		await page.getByRole( 'option', { name: 'LlamaHire Browser Test Role', exact: true } ).evaluate( ( option ) => option.click() );
		await page.getByRole( 'checkbox', { name: 'Browser Test Candidate' } ).evaluate( ( input ) => { input.focus(); input.click(); } );
		await page.getByRole( 'checkbox', { name: 'Bulk Workflow Candidate' } ).evaluate( ( input ) => { input.focus(); input.click(); } );
		await expect( page.getByText( '2 Items selected' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Change status' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'dialog', { name: 'Change status for 2 candidates' } ) ).toBeVisible();
		await page.getByLabel( 'Move selected candidates to' ).selectOption( 'interviewing' );
		await expect( page.getByText( '2 candidates will move to Interviewing.' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Confirm status change' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ).filter( { hasText: '2 candidates moved to Interviewing.' } ) ).toBeVisible();
		await expect( page ).toHaveURL( /job_ids=\d+/ );
		await expect( page.getByText( '2 Items selected' ) ).toBeVisible();
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

	test( '@critical @employer @job-board approved employer drafts, previews, and submits a complete job for moderation', async ( { page } ) => {
		test.setTimeout( 90_000 );
		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-settings' );
		await openSettingsSection( page, 'Site purpose' );
		await page.getByRole( 'radio', { name: /Community job board/ } ).check();
		await page.getByRole( 'button', { name: 'Save changes' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /#llamahire-settings-site-purpose$/ );
		await expect( page.getByText( 'Settings saved.', { exact: true } ) ).toBeVisible();
		await openSettingsSection( page, 'Site purpose' );
		await expect( page.getByRole( 'heading', { name: 'Employer registration' } ) ).toBeVisible();
		await expect( page.getByLabel( 'After email verification' ) ).toHaveValue( 'manual' );
		await expect( page.getByLabel( 'Registration agreement' ) ).not.toHaveValue( '' );
		await openSettingsSection( page, 'Applications & privacy' );
		await expect( page.getByLabel( 'Employer registration', { exact: true } ) ).toBeChecked();
		await expect( page.getByLabel( 'Provider', { exact: true } ) ).toHaveValue( 'none' );
		await openSettingsSection( page, 'Listing policy' );
		await expect( page.getByLabel( 'Active listings per employer' ) ).toHaveValue( '0' );
		await expect( page.getByLabel( 'Default listing duration' ) ).toHaveValue( '30' );
		await openSettingsSection( page, 'Pages' );
		await expect( page.locator( '#llamahire-submit-job-page' ) ).toHaveValue( /\d+/ );
		await expect( page.locator( '#llamahire-my-jobs-page' ) ).toHaveValue( /\d+/ );
		await expect( page.locator( '#llamahire-employer-registration-page' ) ).toHaveValue( /\d+/ );

		await page.context().clearCookies();
		await page.goto( '/employer-registration/' );
		await expect( page.getByRole( 'heading', { name: 'Register as an employer' } ) ).toBeVisible();
		await expect( page.getByLabel( 'Your name' ) ).toBeVisible();
		await expect( page.getByLabel( 'Company name' ) ).toBeVisible();
		await expect( page.getByLabel( 'Work email' ) ).toHaveAttribute( 'type', 'email' );
		await expect( page.getByLabel( /Password/ ) ).toHaveAttribute( 'minlength', '12' );
		await expect( page.getByRole( 'checkbox', { name: /listing rules/ } ) ).toBeVisible();
		await logIn( page, employerUser, employerPassword );
		await page.goto( '/submit-a-job/' );
		await expect( page.getByRole( 'heading', { name: 'Create job listing', level: 2, exact: true } ) ).toBeVisible();
		await page.locator( 'input[name="job_title"]' ).fill( 'Employer Browser Test Role' );
		await page.getByRole( 'button', { name: 'Save draft' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /\/my-jobs\/.*job_draft_saved=1/ );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'draft was saved' } ) ).toBeVisible();
		let employerRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await expect( employerRow ).toContainText( 'Draft' );
		await page.goto( await employerRow.getByRole( 'link', { name: 'Edit' } ).getAttribute( 'href' ) );
		await expect( page.getByRole( 'heading', { name: 'Edit job listing', level: 2, exact: true } ) ).toBeVisible();
		await page.getByLabel( 'Short summary' ).fill( 'A schema-ready employer listing.' );
		await page.getByRole( 'button', { name: 'Save and preview' } ).evaluate( ( button ) => { button.form.noValidate = true; button.click(); } );
		await expect( page ).toHaveURL( /job_error=required.*job_id=/ );
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Complete the job description, company, and application destination' );
		await expect( page.getByLabel( 'Short summary' ) ).toHaveValue( 'A schema-ready employer listing.' );
		await page.getByLabel( 'Job description' ).fill( 'A moderated job submitted from the employer portal.' );
		await page.getByLabel( 'Company name' ).fill( 'Browser Test Company' );
		await page.getByLabel( 'Company tagline' ).fill( 'Good work, close to home.' );
		await page.getByLabel( 'Company website' ).fill( 'https://example.test/' );
		await page.getByLabel( 'Company logo' ).setInputFiles( path.resolve( 'docs/audits/2026-07-20-accessibility-review/01-setup.png' ) );
		await page.getByLabel( 'Location type' ).selectOption( 'remote' );
		await expect( page.locator( 'input[name="address_locality"]' ) ).toBeHidden();
		await expect( page.getByLabel( 'Eligible applicant countries' ) ).toBeVisible();
		await page.getByLabel( 'Eligible applicant countries' ).fill( 'CA' );
		await page.getByLabel( 'Location type' ).selectOption( 'hybrid' );
		await expect( page.locator( 'input[name="address_locality"]' ) ).toBeVisible();
		await expect( page.getByLabel( 'Eligible applicant countries' ) ).toBeHidden();
		await page.locator( 'input[name="address_locality"]' ).fill( 'Hamilton' );
		await page.getByLabel( 'State, province, or region' ).fill( 'Ontario' );
		await page.getByLabel( 'Postal code' ).fill( 'L8P 1A1' );
		await page.locator( 'select[name="address_country"]' ).selectOption( 'CA' );
		await page.getByLabel( 'Employment type' ).selectOption( 'full_time' );
		await page.getByLabel( 'Minimum salary' ).fill( '70000' );
		await page.getByLabel( 'Maximum salary' ).fill( '90000' );
		await page.getByLabel( 'Currency' ).selectOption( 'CAD' );
		await page.getByLabel( 'Pay period' ).selectOption( 'YEAR' );
		const employerDeadline = new Date();
		employerDeadline.setUTCDate( employerDeadline.getUTCDate() + 30 );
		await page.getByLabel( 'Application deadline' ).fill( employerDeadline.toISOString().slice( 0, 10 ) );
		await page.getByLabel( 'How candidates apply' ).selectOption( 'internal' );
		await page.getByLabel( 'How candidates apply' ).selectOption( 'external_url' );
		await expect( page.getByLabel( 'Application website URL' ) ).toHaveAttribute( 'type', 'url' );
		await expect( page.getByLabel( 'Application website URL' ) ).toHaveAttribute( 'inputmode', 'url' );
		await expect( page.getByLabel( 'Application website URL' ) ).toHaveAttribute( 'autocomplete', 'url' );
		await page.getByLabel( 'How candidates apply' ).selectOption( 'internal' );
		await expect( page.getByLabel( 'Notification email' ) ).toHaveAttribute( 'inputmode', 'email' );
		await page.getByLabel( 'Notification email' ).fill( 'employer-applications@example.test' );
		await page.getByRole( 'button', { name: 'Save and preview' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /preview=true/ );
		await expect( page.getByRole( 'heading', { name: 'Employer Browser Test Role', level: 1 } ) ).toBeVisible();
		await expect( page.getByText( 'A schema-ready employer listing.', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( /CAD 70,000.*90,000/ ) ).toBeVisible();
		await expect( page.getByText( 'This is how your listing will appear to candidates.' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Submit for review' } ) ).toBeVisible();
		await expect( page.getByRole( 'navigation', { name: 'Job preview' } ).getByRole( 'link', { name: 'Back to My Jobs' } ) ).toBeVisible();
		await page.getByRole( 'link', { name: 'Continue editing' } ).evaluate( ( link ) => link.click() );
		await expect( page.getByRole( 'heading', { name: 'Edit job listing', level: 2, exact: true } ) ).toBeVisible();
		await page.getByLabel( 'How candidates apply' ).selectOption( 'external_url' );
		await page.getByLabel( 'Application website URL' ).fill( 'ftp://example.test/job' );
		await page.getByRole( 'button', { name: 'Save and preview' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /\/submit-a-job\/\?job_error=application_url&job_id=/ );
		await expect( page.getByRole( 'alert' ) ).toContainText( 'complete application website URL' );
		await expect( page.getByLabel( 'How candidates apply' ) ).toHaveValue( 'internal' );
		await expect( page.getByLabel( 'Notification email' ) ).toHaveValue( 'employer-applications@example.test' );
		await page.getByRole( 'button', { name: 'Save and preview' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /preview=true/ );
		await page.getByRole( 'button', { name: 'Submit for review' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /\/my-jobs\/.*job_submitted=1/ );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'submitted for moderation' } ) ).toBeVisible();
		employerRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await expect( employerRow ).toContainText( 'Awaiting review' );
		await page.goto( await employerRow.getByRole( 'link', { name: 'Edit' } ).getAttribute( 'href' ) );
		await expect( page.getByRole( 'heading', { name: 'Edit job listing', level: 2, exact: true } ) ).toBeVisible();
		await expect( page.locator( 'input[name="job_title"]' ) ).toHaveValue( 'Employer Browser Test Role' );
		await expect( page.getByLabel( 'Short summary' ) ).toHaveValue( 'A schema-ready employer listing.' );
		await expect( page.locator( 'input[name="address_locality"]' ) ).toHaveValue( 'Hamilton' );
		await expect( page.getByLabel( 'Minimum salary' ) ).toHaveValue( '70000' );
		await expect( page.getByLabel( 'Company logo' ).locator( 'xpath=preceding-sibling::img' ) ).toHaveCount( 1 );

		await page.context().clearCookies();
		await logIn( page );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-dashboard' );
		await expect( page.getByText( /job listing is waiting for review/ ) ).toBeVisible();
		await page.getByRole( 'link', { name: 'Review listings' } ).evaluate( ( link ) => link.click() );
		const moderationRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await expect( moderationRow.getByRole( 'link', { name: 'Approve and publish' } ) ).toBeVisible();
		await moderationRow.getByRole( 'link', { name: 'Approve and publish' } ).evaluate( ( link ) => link.click() );
		await expect( page.getByText( 'The job was approved and published.' ) ).toBeVisible();

		await page.context().clearCookies();
		await page.goto( '/jobs/employer-browser-test-role/' );
		await page.locator( 'input[name="name"]' ).fill( 'Employer Portal Candidate' );
		await page.locator( 'input[name="email"]' ).fill( 'employer-portal-browser@example.test' );
		await page.locator( 'input[name="phone"]' ).fill( '555-0188' );
		await page.locator( 'textarea[name="cover_letter"]' ).fill( 'Frontend employer application review.' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Submit application' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks! Your application has been received.' );

		await logIn( page, employerUser, employerPassword );
		await expect( page ).toHaveURL( /\/my-jobs\// );
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications' );
		await expect( page ).toHaveURL( /\/my-jobs\/.*employer_view=applications/ );
		await expect( page.getByRole( 'heading', { name: 'Applications', level: 2 } ) ).toBeVisible();
		await page.goto( await page.getByRole( 'link', { name: 'Employer Portal Candidate', exact: true } ).getAttribute( 'href' ) );
		const employerReview = page.locator( '.llamahire-employer-application-review' );
		await expect( employerReview.getByRole( 'heading', { name: 'Employer Portal Candidate' } ) ).toBeVisible();
		await employerReview.locator( 'select[name="status"]' ).selectOption( 'reviewing' );
		await employerReview.getByRole( 'button', { name: 'Save status' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'Application status saved.' } ) ).toBeVisible();
		await employerReview.locator( 'textarea[name="note"]' ).fill( 'Reviewed from the frontend employer portal.' );
		await employerReview.getByRole( 'button', { name: 'Add note' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'Private note added.' } ) ).toBeVisible();
		await expect( employerReview.getByText( 'Reviewed from the frontend employer portal.' ) ).toBeVisible();
		await expect( employerReview.getByRole( 'link', { name: 'Preview' } ) ).toBeVisible();
		await expect( employerReview.getByRole( 'link', { name: 'Download' } ) ).toBeVisible();

		await page.goto( '/my-jobs/' );
		await page.getByRole( 'searchbox', { name: 'Search' } ).fill( 'Employer Browser Test Role' );
		await page.getByRole( 'combobox', { name: 'Status' } ).selectOption( 'published' );
		await page.getByRole( 'button', { name: 'Filter jobs' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /my_jobs_search=Employer\+Browser\+Test\+Role.*my_jobs_status=published/ );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'Showing 1–1 of 1 jobs' } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Clear filters' } ) ).toBeVisible();
		const publishedRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await expect( publishedRow ).toContainText( 'Published' );
		await expect( publishedRow ).toContainText( 'Applications close' );
		await expect( publishedRow.getByRole( 'link', { name: 'Preview' } ) ).toHaveAttribute( 'target', '_blank' );
		await publishedRow.getByRole( 'button', { name: 'Duplicate' } ).evaluate( ( button ) => button.click() );
		await expect( page ).toHaveURL( /\/submit-a-job\/.*job_duplicated=1/ );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'fresh draft was created' } ) ).toBeVisible();
		await expect( page.locator( 'input[name="job_title"]' ) ).toHaveValue( 'Employer Browser Test Role (Copy)' );
		await expect( page.getByLabel( 'Application deadline' ) ).toHaveValue( '' );
		await page.goto( '/my-jobs/' );
		const copiedRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role (Copy)' } );
		await expect( copiedRow ).toContainText( 'Draft' );
		const copiedRowHeight = await copiedRow.evaluate( ( row ) => row.getBoundingClientRect().height );
		await copiedRow.getByText( 'Delete', { exact: true } ).evaluate( ( summary ) => summary.click() );
		await expect( copiedRow.locator( '.llamahire-employer-portal__delete-panel' ) ).toBeVisible();
		expect( Math.abs( ( await copiedRow.evaluate( ( row ) => row.getBoundingClientRect().height ) ) - copiedRowHeight ) ).toBeLessThan( 2 );
		await copiedRow.getByLabel( 'Move this listing to the trash and remove it from My Jobs.' ).evaluate( ( checkbox ) => { checkbox.checked = true; checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
		await copiedRow.getByRole( 'button', { name: 'Confirm delete' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role (Copy)' } ) ).toHaveCount( 0 );
		await page.goto( '/jobs/employer-browser-test-role/' );
		await expect( page.getByText( 'A schema-ready employer listing.', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( 'Listing ends', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( /CAD 70,000.*90,000/ ) ).toBeVisible();
		await expect( page.getByText( 'Your application will be shared with Browser Test Company for hiring review.', { exact: true } ) ).toBeVisible();
		await page.goto( '/submit-a-job/' );
		await expect( page.getByLabel( 'Company name' ) ).toHaveValue( 'Browser Test Company' );
		await expect( page.getByLabel( 'Notification email' ) ).toHaveValue( 'employer-applications@example.test' );
		await page.goto( '/my-jobs/' );
		const deleteRow = page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } );
		await deleteRow.getByText( 'Delete', { exact: true } ).evaluate( ( summary ) => summary.click() );
		await deleteRow.getByLabel( 'Move this listing to the trash and remove it from My Jobs.' ).evaluate( ( checkbox ) => { checkbox.checked = true; checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } );
		await deleteRow.getByRole( 'button', { name: 'Confirm delete' } ).evaluate( ( button ) => button.click() );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'job listing was deleted' } ) ).toBeVisible();
		await expect( page.getByRole( 'row' ).filter( { hasText: 'Employer Browser Test Role' } ) ).toHaveCount( 0 );
	} );
} );
