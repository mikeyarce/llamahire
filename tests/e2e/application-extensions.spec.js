const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );

test.use( { trace: 'off', screenshot: 'off', video: 'off' } );
test.setTimeout( 120_000 );
function fixture( action ) {
	const output = execFileSync( './node_modules/.bin/wp-env', [ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/application-extension-fixtures.php', action ], { encoding: 'utf8', timeout: 120_000 } );
	const json = output.split( '\n' ).find( line => line.trim().startsWith( '{' ) );
	return json ? JSON.parse( json ) : null;
}
async function login( page, user, password ) {
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.locator( '#user_pass' ).fill( password );
	// Chromium can replace the username while handling the password field.
	await page.locator( '#user_login' ).fill( user );
	if ( await page.locator( '#user_pass' ).inputValue() !== password ) {
		await page.locator( '#user_pass' ).fill( password );
	}
	expect( ( await page.locator( '#user_login' ).inputValue() ) === user ).toBe( true );
	await Promise.all( [ page.waitForURL( url => url.pathname.includes( '/wp-admin' ) || url.searchParams.get( 'action' ) === 'confirm_admin_email' || url.pathname !== '/wp-login.php', { waitUntil: 'commit' } ), page.locator( '#wp-submit' ).click( { noWaitAfter: true } ) ] );
	if ( new URL( page.url() ).searchParams.get( 'action' ) === 'confirm_admin_email' ) {
		await Promise.all( [ page.waitForURL( url => url.pathname !== '/wp-login.php', { waitUntil: 'commit' } ), page.locator( '#correct-admin-email' ).click( { noWaitAfter: true } ) ] );
	}
}
for ( const javaScriptEnabled of [ true, false ] ) {
	test( `Required extension writes recover and remain private; JavaScript=${ javaScriptEnabled }`, async ( { browser } ) => {
		const data = fixture( 'setup' );
		const context = await browser.newContext( { javaScriptEnabled, baseURL: process.env.WP_BASE_URL || 'http://localhost:8897' } );
		const page = await context.newPage();
		try {
			await page.goto( data.urls.closed );
			await expect( page.locator( '#fixture-answer' ) ).toHaveCount( 0 );
			await page.goto( data.urls.url );
			await expect( page.locator( '#fixture-answer' ) ).toHaveCount( 0 );
			await page.goto( javaScriptEnabled ? data.block_url : data.urls.open );
			await expect( page.locator( '[data-llamahire-application-form]' ) ).toHaveAttribute( 'action', javaScriptEnabled ? data.block_url : data.urls.open );
			await page.locator( '[name="candidate_name"]' ).fill( 'Fictional extension applicant' );
			await page.locator( '[name="email"]' ).fill( 'extension-browser@example.test' );
			await page.locator( '#fixture-answer' ).fill( 'javascript:invalid' );
			const validationResponse = page.waitForResponse( response => response.request().method() === 'POST' );
			await page.getByRole( 'button', { name: 'Submit application', exact: true } ).click();
			const invalidResponse = await validationResponse;
			expect( invalidResponse.status() ).toBe( 422 );
			expect( ( await invalidResponse.text() ).includes( 'Enter a complete HTTP or HTTPS portfolio URL.' ) ).toBe( true );
			await expect( page.locator( '#fixture-answer-error' ) ).toContainText( 'HTTP or HTTPS' );
			await expect( page.locator( '#fixture-answer' ) ).toHaveAttribute( 'aria-invalid', 'true' );
			expect( ( await page.locator( '[name="email"]' ).inputValue() ) === 'extension-browser@example.test' ).toBe( true );
			expect( fixture( 'state' ) ).toMatchObject( { applications: 1, answers: 0, mail_count: 0 } );
			await page.locator( '#fixture-answer' ).fill( 'https://example.test/original' );
			fixture( 'fail' );
			await page.getByRole( 'button', { name: 'Submit application', exact: true } ).click();
			await expect( page.getByRole( 'alert' ).filter( { hasText: 'not been sent' } ) ).toBeVisible();
			expect( fixture( 'state' ) ).toMatchObject( { applications: 1, answers: 0, mail_count: 0 } );
			fixture( 'recover' );
			await page.getByRole( 'button', { name: 'Submit application', exact: true } ).click();
			await expect( page.getByRole( 'status' ).filter( { hasText: 'has been received' } ) ).toBeVisible();
			const state = fixture( 'state' );
			expect( state ).toMatchObject( { applications: 2, answers: 1, mail_count: 2 } );
			await page.goto( data.urls.open );
			await page.locator( '[name="candidate_name"]' ).fill( 'Fictional extension applicant' );
			await page.locator( '[name="email"]' ).fill( 'extension-browser@example.test' );
			await page.locator( '#fixture-answer' ).fill( 'https://example.test/changed' );
			await page.getByRole( 'button', { name: 'Submit application', exact: true } ).click();
			await expect( page.getByRole( 'status' ).filter( { hasText: 'has been received' } ) ).toBeVisible();
			expect( fixture( 'state' ) ).toEqual( state );
			await login( page, data.users.owner, 'Flow-test-password-123' );
			const review = new URL( data.pages.my_jobs );
			review.searchParams.set( 'employer_view', 'applications' );
			review.searchParams.set( 'application_id', state.id );
			await page.goto( review.href );
			await expect( page.getByRole( 'heading', { name: 'Historical extra answers' } ) ).toBeVisible();
			expect( ( await page.locator( '.llamahire-application-extra a' ).getAttribute( 'href' ) ) === 'https://example.test/original' ).toBe( true );
			await context.clearCookies();
			await login( page, data.users.other, 'Flow-test-password-123' );
			await page.goto( review.href );
			await expect( page.locator( '.llamahire-application-extra' ) ).toHaveCount( 0 );
			await context.clearCookies();
			await login( page, 'admin', 'password' );
			if ( javaScriptEnabled ) {
				await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications' );
				await page.locator( `button[data-llamahire-review-trigger="${ state.id }"]` ).click();
				await expect( page.getByRole( 'heading', { name: 'Historical extra answers' } ) ).toBeVisible();
			}
			await page.goto( `/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications&application=${ state.id }` );
			await expect( page.getByRole( 'heading', { name: 'Historical extra answers' } ) ).toBeVisible();
		} finally { await context.close(); fixture( 'cleanup' ); }
	} );
}

test( 'Admin quick view shows an explicit empty extension-answer state', async ( { page } ) => {
	const data = fixture( 'setup' );
	const runtimeErrors = [];
	page.on( 'pageerror', error => runtimeErrors.push( { name: error.name, frames: ( error.stack || '' ).split( '\n' ).slice( 1 ).map( line => line.match( /[a-z-]+\.js(?:\?[^: ]*)?:\d+:\d+/ )?.[ 0 ] || 'unknown' ) } ) );
	try {
		await login( page, 'admin', 'password' );
		await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications' );
		expect( runtimeErrors ).toEqual( [] );
		await expect( page.locator( `button[data-llamahire-review-trigger="${ data.candidate }"]` ) ).toBeVisible();
		await page.locator( `button[data-llamahire-review-trigger="${ data.candidate }"]` ).click();
		await expect( page.getByText( 'No additional answers were submitted.', { exact: true } ) ).toBeVisible();
		expect( runtimeErrors ).toEqual( [] );
	} finally { fixture( 'cleanup' ); }
} );
