const { execFileSync } = require( 'node:child_process' );
const { test, expect } = require( '@playwright/test' );

const fixtures = ( action ) => execFileSync(
	'./node_modules/.bin/wp-env',
	[ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/review-fixtures.php', action ],
	{ encoding: 'utf8', timeout: 120_000 }
);
let fixture;
test.describe.serial( '@review-fixes cached forms and complete hiring pipelines', () => {
	test.beforeAll( () => {
		const output = fixtures( 'setup' );
		fixture = JSON.parse( output.match( /\{"job":\d+,"url":.*?\}/ )[0] );
	} );
	test.afterAll( () => { fixtures( 'cleanup' ); } );

	test( 'two applicants can use the same cached form without JavaScript', async ( { page } ) => {
		await page.goto( fixture.url );
		const form = page.locator( '[data-llamahire-application-form]' );
		const nonce = await form.locator( '[name="llamahire_nonce"]' ).inputValue();
		const key = await form.locator( '[name="submission_key"]' ).inputValue();
		for ( const email of [ 'cached-first@example.test', 'cached-second@example.test', 'cached-second@example.test' ] ) {
			const response = await page.request.post( '/wp-admin/admin-post.php', {
				form: { action: 'llamahire_apply', job_id: String( fixture.job ), llamahire_nonce: nonce, submission_key: key, name: 'Cached Form Fixture', email },
			} );
			expect( response.url() ).toContain( 'application=success' );
		}
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( process.env.WP_ADMIN_USER || 'admin' );
		await page.locator( '#user_pass' ).fill( process.env.WP_ADMIN_PASSWORD || 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.goto( `/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications&job_id=${ fixture.job }&candidate=Cached%20Form%20Fixture` );
		await expect( page.getByRole( 'link', { name: 'cached-first@example.test', exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'cached-second@example.test', exact: true } ) ).toHaveCount( 1 );
	} );

	test( 'keyboard pagination reaches older offers and preserves review context at desktop and narrow widths', async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( process.env.WP_ADMIN_USER || 'admin' );
		await page.locator( '#user_pass' ).fill( process.env.WP_ADMIN_PASSWORD || 'password' );
		await page.locator( '#wp-submit' ).click();
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( `/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-hiring&job_id=${ fixture.job }&candidate=Pagination%20Fixture` );
			await expect( page.locator( '[data-candidate-id]' ) ).toHaveCount( 100 );
			await expect( page.locator( '[data-stage="offer"] [data-stage-count]' ) ).toHaveText( '1' );
			const pager = page.getByRole( 'navigation', { name: 'Candidate pages' } );
			await expect( pager ).toContainText( 'of 101 candidates' );
			await pager.getByRole( 'link', { name: 'Next', exact: true } ).press( 'Enter' );
			await expect( page.locator( '[data-candidate-id]' ) ).toHaveCount( 1 );
			await expect( page ).toHaveURL( /hiring_page=2/ );
			// Exercise keyboard access to the older candidate, including off-screen stages.
			await page.locator( '.llamahire-candidate-main' ).press( 'Enter' );
			await expect( page ).toHaveURL( /hiring_page=2/ );
			await expect( page.locator( '.llamahire-candidate-drawer' ) ).toBeVisible();
			await expect( page.locator( '.llamahire-drawer-form [name="redirect_to"]' ).first() ).toHaveValue( /hiring_page=2/ );
			await page.getByRole( 'link', { name: 'Close candidate details' } ).press( 'Enter' );
			await expect( page ).toHaveURL( /hiring_page=2/ );
			await pager.getByRole( 'link', { name: 'Previous', exact: true } ).press( 'Enter' );
			await expect( page.locator( '[data-candidate-id]' ) ).toHaveCount( 100 );
			expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) ).toBeTruthy();
		}
	} );
} );
