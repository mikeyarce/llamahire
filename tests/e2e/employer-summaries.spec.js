const { execFile } = require( 'node:child_process' );
const { promisify } = require( 'node:util' );
const { test, expect } = require( '@playwright/test' );
const run = promisify( execFile );

test.use( { javaScriptEnabled: false, trace: 'off', screenshot: 'off', video: 'off' } );

async function fixtures( action, ...args ) {
	const { stdout } = await run( './node_modules/.bin/wp-env', [ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/flow-fixtures.php', action, ...args ], { timeout: 120_000, maxBuffer: 1024 * 1024 } );
	if ( action === 'setup' ) {
		return JSON.parse( stdout.split( '\n' ).find( ( line ) => line.trim().startsWith( '{' ) ) );
	}
}

async function login( page, user ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( user );
	await page.locator( '#user_pass' ).fill( 'Flow-test-password-123' );
	await page.locator( '#wp-submit' ).press( 'Enter' );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
}

test.describe( 'Employer summary extension contract', () => {
	let f;
	test.beforeEach( async () => {
		f = await fixtures( 'setup', 'job_board' );
		await fixtures( 'summaries', 'on' );
	} );
	test.afterEach( async () => { await fixtures( 'cleanup' ); } );

	for ( const width of [ 1280, 390 ] ) {
		test( `no-JS owner sees safe status and keyboard action at ${ width }px`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 900 } );
			await login( page, f.users.owner );
			await page.goto( `${ f.pages.my_jobs }?my_jobs_status=draft` );
			const row = page.getByRole( 'row' ).filter( { hasText: 'Flow draft' } );
			await expect( row.getByText( 'Draft', { exact: true } ) ).toBeVisible();
			await expect( row.getByText( 'Payment needed', { exact: true } ) ).toBeVisible();
			await expect( row.getByText( 'Review the listing price before checkout.' ) ).toBeVisible();
			await expect( row.getByText( '<img src=x onerror=alert(1)>', { exact: true } ) ).toBeVisible();
			await expect( row.locator( 'img' ) ).toHaveCount( 0 );
			await expect( row.getByRole( 'link', { name: 'Unsafe payment link' } ) ).toHaveCount( 0 );
			expect( await row.innerHTML() ).not.toContain( 'summary-private-reference' );
			expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) ).toBe( true );
			const action = row.getByRole( 'link', { name: 'Review payment', exact: true } );
			await action.focus();
			await expect( action ).toBeFocused();
			await action.press( 'Enter' );
			await expect( page ).toHaveURL( f.pages.submit_job );
		} );
	}

	test( 'foreign and anonymous visitors see no payment summaries', async ( { page } ) => {
		await page.goto( f.pages.my_jobs );
		await expect( page.getByText( 'Payment needed', { exact: true } ) ).toHaveCount( 0 );
		await login( page, f.users.other );
		await page.goto( `${ f.pages.my_jobs }?job_id=${ f.jobs.draft }` );
		await expect( page.getByText( 'Payment needed', { exact: true } ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'link', { name: 'Review payment', exact: true } ) ).toHaveCount( 0 );
	} );

	test( 'extension off restores baseline draft and editing actions', async ( { page } ) => {
		await fixtures( 'summaries', 'off' );
		await login( page, f.users.owner );
		await page.goto( `${ f.pages.my_jobs }?my_jobs_status=draft` );
		const row = page.getByRole( 'row' ).filter( { hasText: 'Flow draft' } );
		await expect( row.getByText( 'Draft', { exact: true } ) ).toBeVisible();
		await expect( row.getByRole( 'link', { name: 'Edit', exact: true } ) ).toBeVisible();
		await expect( row.locator( '.llamahire-employer-portal__extension-summary' ) ).toHaveCount( 0 );
	} );
} );
