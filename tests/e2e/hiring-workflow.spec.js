const fs = require( 'node:fs/promises' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const candidateEmail = 'browser-test@example.test';
const adminUser = process.env.WP_ADMIN_USER || 'admin';
const adminPassword = process.env.WP_ADMIN_PASSWORD || 'password';

async function logIn( page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( adminUser );
	await page.locator( '#user_pass' ).fill( adminPassword );
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
		const csvPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Export CSV' } ).focus();
		await page.keyboard.press( 'Enter' );
		const csv = await csvPromise;
		const csvContents = await fs.readFile( await csv.path(), 'utf8' );
		expect( csvContents ).toContain( candidateEmail );
		expect( csvContents ).toContain( "'=CI formula safety check" );
	} );
} );
