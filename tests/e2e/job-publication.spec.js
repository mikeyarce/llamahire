const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );
test.setTimeout( 120_000 );
function fixture( action ) {
	const output = execFileSync( './node_modules/.bin/wp-env', [ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/publication-fixtures.php', action ], { encoding: 'utf8', timeout: 120_000 } );
	const json = output.split( '\n' ).find( line => line.trim().startsWith( '{' ) );
	return json ? JSON.parse( json ) : null;
}
for ( const paymentFirst of [ false, true ] ) {
	test( `Native moderation and public availability, payment first=${ paymentFirst }`, async ( { page, browser } ) => {
		const data = fixture( 'setup' );
		const publicContext = await browser.newContext();
		const visitor = await publicContext.newPage();
		try {
			if ( paymentFirst ) {
				expect( fixture( 'pay' ) ).toMatchObject( { status: 'draft', approved: false, period: null, expiry: '' } );
			}
			expect( ( await visitor.goto( data.url ) ).status() ).toBe( 404 );
			await page.bringToFront();
			await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
			if ( await page.evaluate( () => typeof window.wp_attempt_focus === 'function' ) ) await expect( page.locator( '#user_login' ) ).toBeFocused();
			await page.locator( '#user_pass' ).fill( 'password' );
			await page.locator( '#user_login' ).fill( 'admin' );
			await Promise.all( [ page.waitForURL( url => url.pathname.startsWith( '/wp-admin/' ) || url.searchParams.get( 'action' ) === 'confirm_admin_email', { waitUntil: 'commit' } ), page.locator( '#wp-submit' ).press( 'Enter' ) ] );
			if ( new URL( page.url() ).searchParams.get( 'action' ) === 'confirm_admin_email' ) await page.locator( '#correct-admin-email' ).press( 'Enter' );
			await page.goto( '/wp-admin/edit.php?post_type=llamahire_job&post_status=draft' );
			const row = page.locator( `#post-${ data.job }` );
			await row.hover();
			await row.locator( '.editinline' ).press( 'Enter' );
			const editor = page.locator( `#edit-${ data.job }` );
			await editor.locator( 'select[name="_status"]' ).selectOption( 'publish' );
			await editor.locator( '.save' ).press( 'Enter' );
			await expect( editor ).toHaveCount( 0 );
			let state = fixture( 'state' );
			if ( ! paymentFirst ) {
				expect( state ).toMatchObject( { status: 'pending', approved: true, period: null, expiry: '' } );
				expect( ( await visitor.goto( data.url ) ).status() ).toBe( 404 );
				state = fixture( 'pay' );
			}
			expect( state.status ).toBe( 'publish' );
			expect( state.period.expires ).toBe( state.expiry );
			await visitor.bringToFront();
			expect( ( await visitor.goto( data.url ) ).status() ).toBe( 200 );
			await expect( visitor.locator( '[data-llamahire-application-form]' ) ).toBeVisible();
			const schema = await visitor.locator( 'script[type="application/ld+json"]' ).allTextContents();
			expect( schema.some( value => value.includes( 'JobPosting' ) && value.includes( state.expiry ) ) ).toBe( true );
			expect( fixture( 'pay' ).period ).toEqual( state.period );
			fixture( 'revoke' );
			expect( ( await visitor.goto( data.url ) ).status() ).toBe( 404 );
			await expect( visitor.locator( '[data-llamahire-application-form]' ) ).toHaveCount( 0 );
		} finally {
			try { fixture( 'cleanup' ); } finally { await publicContext.close(); }
		}
	} );
}
