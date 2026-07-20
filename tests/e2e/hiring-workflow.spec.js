const fs = require( 'node:fs/promises' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const candidateEmail = 'browser-test@example.test';
const adminUser = process.env.WP_ADMIN_USER || 'admin';
const adminPassword = process.env.WP_ADMIN_PASSWORD || 'password';

async function logIn( page ) {
	await page.goto( '/wp-login.php' );
	const username = page.locator( 'input[name="log"]' );
	const password = page.locator( 'input[name="pwd"]' );
	await password.fill( adminPassword );
	// Fill the username last because Chromium can intermittently replace it while handling the password field.
	await username.fill( adminUser );
	await expect( username ).toHaveValue( adminUser );
	await expect( password ).toHaveValue( adminPassword );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.locator( '#wp-submit' ).click()
	] );
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

test.describe.serial( 'complete hiring workflow', () => {
	test( 'administrator can skip and complete first-run organization setup', async ( { page } ) => {
		await logIn( page );
		await expect( page.getByRole( 'heading', { name: 'Welcome to LlamaHire' } ) ).toBeVisible();
		const setupProgress = page.getByRole( 'progressbar', { name: 'Setup progress: organization, privacy, and Careers page' } );
		await expect( setupProgress ).toHaveAttribute( 'value', '0' );
		await expect( setupProgress ).toHaveAttribute( 'aria-valuetext', 'Not complete' );
		await expect( page.locator( '[id="llamahire_save_setup_nonce"]' ) ).toHaveCount( 1 );
		await expect( page.locator( '[id="llamahire_skip_setup_nonce"]' ) ).toHaveCount( 1 );
		await page.getByRole( 'button', { name: 'Skip for now' } ).click();
		await expect( page ).toHaveURL( /post_type=llamahire_job.*llamahire_setup=skipped/ );
		await expect( page.getByText( 'Setup skipped for now.' ) ).toBeVisible();

		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-setup' );
		await page.getByLabel( 'Organization name', { exact: true } ).fill( 'LlamaHire CI Employer' );
		await page.getByLabel( 'Default city or locality', { exact: true } ).fill( 'Vancouver' );
		await page.getByLabel( 'Default state, province, or region', { exact: true } ).fill( 'BC' );
		await page.getByLabel( 'Default country', { exact: true } ).fill( 'CA' );
		await page.getByLabel( 'Default currency', { exact: true } ).fill( 'CAD' );
		await page.getByLabel( 'Hiring inbox', { exact: true } ).fill( 'hiring@example.test' );
		await page.getByLabel( 'Candidate privacy text', { exact: true } ).fill( 'We use candidate information only to review this application.' );
		await page.getByLabel( 'Privacy policy page', { exact: true } ).selectOption( { label: 'LlamaHire E2E Privacy' } );
		await page.getByLabel( 'Create a new Careers page using the LlamaHire pattern', { exact: true } ).check();
		await expect( page.getByLabel( 'New page title', { exact: true } ) ).toBeEnabled();
		await expect( page.getByLabel( 'Existing Careers page', { exact: true } ) ).toBeDisabled();
		await page.getByLabel( 'New page title', { exact: true } ).fill( 'LlamaHire E2E Careers' );
		const saveSetup = page.getByRole( 'button', { name: 'Complete setup' } );
		await saveSetup.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /post_type=llamahire_job.*llamahire_setup=completed/ );
		await expect( page.getByText( 'LlamaHire setup is complete.' ) ).toBeVisible();

		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-settings' );
		await expect( page.getByLabel( 'Organization name', { exact: true } ) ).toHaveValue( 'LlamaHire CI Employer' );
		await expect( page.getByLabel( 'Default city or locality', { exact: true } ) ).toHaveValue( 'Vancouver' );
		await expect( page.getByLabel( 'Hiring inbox', { exact: true } ) ).toHaveValue( 'hiring@example.test' );
		await expect( page.getByLabel( 'Candidate privacy text', { exact: true } ) ).toHaveValue( 'We use candidate information only to review this application.' );
		await expect( page.getByLabel( 'Candidate privacy policy', { exact: true } ) ).toHaveValue( /\d+/ );
		await expect( page.getByLabel( 'Careers page', { exact: true } ) ).toHaveValue( /\d+/ );

		await page.goto( '/llamahire-e2e-careers/' );
		await expect( page.getByRole( 'heading', { name: 'Do your best work with us' } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'LlamaHire Browser Test Role' } ) ).toBeVisible();
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
		await expect( page.getByRole( 'link', { name: 'Read our privacy policy.' } ) ).toHaveAttribute( 'href', /llamahire-e2e-privacy/ );
		await expect( page.locator( 'input[name="resume"]' ) ).toHaveAttribute( 'aria-describedby', 'llamahire-resume-help' );
		await expect( page.getByRole( 'button', { name: 'Submit application' } ) ).toHaveAttribute( 'aria-describedby', 'llamahire-application-privacy' );

		await page.goto( '/jobs/llamahire-e2e-job/?application=required#llamahire-application' );
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Please provide your name and a valid email address.' );

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

		await page.locator( 'input[name="name"]' ).fill( 'Browser Test Candidate' );
		await page.locator( 'input[name="email"]' ).fill( candidateEmail );
		await page.locator( 'input[name="phone"]' ).fill( '555-0199' );
		await page.locator( 'textarea[name="cover_letter"]' ).fill( '=CI formula safety check' );
		await page.locator( 'input[name="resume"]' ).setInputFiles( path.resolve( 'tests/fixture-resume.pdf' ) );
		await page.getByRole( 'button', { name: 'Submit application' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks! Your application has been received.' );
	} );

	test( 'recruiter reviews, exports, and downloads securely', async ( { page } ) => {
		await logIn( page );
		await page.goto( '/wp-admin/admin.php?page=llamahire-applications' );
		await expect( page.getByText( candidateEmail ) ).toBeVisible();
		await page.getByRole( 'link', { name: 'Browser Test Candidate', exact: true } ).click();

		await page.getByLabel( 'Status', { exact: true } ).selectOption( 'reviewing' );
		await page.getByLabel( 'Private notes', { exact: true } ).fill( 'Reviewed by the browser integration suite.' );
		const saveChanges = page.getByRole( 'button', { name: 'Save changes' } );
		await saveChanges.focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ) ).toContainText( 'Application review saved.' );
		await expect( page.getByLabel( 'Status', { exact: true } ) ).toHaveValue( 'reviewing' );
		await expect( page.getByLabel( 'Private notes', { exact: true } ) ).toHaveValue( 'Reviewed by the browser integration suite.' );

		const resumePromise = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Download resume' } ).focus();
		await page.keyboard.press( 'Enter' );
		const resume = await resumePromise;
		expect( resume.suggestedFilename() ).toBe( 'fixture-resume.pdf' );
		const resumeBytes = await fs.readFile( await resume.path() );
		expect( resumeBytes.subarray( 0, 4 ).toString() ).toBe( '%PDF' );

		await page.goto( '/wp-admin/admin.php?page=llamahire-applications' );
		await expect( page.getByRole( 'link', { name: 'All', exact: true } ) ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.getByText( 'Candidate applications', { exact: true } ) ).toBeAttached();
		const csvPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Export CSV' } ).focus();
		await page.keyboard.press( 'Enter' );
		const csv = await csvPromise;
		const csvContents = await fs.readFile( await csv.path(), 'utf8' );
		expect( csvContents ).toContain( candidateEmail );
		expect( csvContents ).toContain( "'=CI formula safety check" );
	} );
} );
