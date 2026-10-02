const { execFile } = require( 'node:child_process' );
const { promisify } = require( 'node:util' );
const { test, expect } = require( '@playwright/test' );
const run = promisify( execFile );

async function wp( ...args ) {
 const { stdout } = await run( './node_modules/.bin/wp-env', [ '--config=.wp-env.ci.json', 'run', 'cli', 'wp', ...args ], { timeout: 180_000, maxBuffer: 1024 * 1024 } );
 return stdout;
}
const matrix = [
 [ 'twentytwentyfive', 'default' ], [ 'twentytwentyfive', 'midnight' ],
 [ 'astra', 'default' ], [ 'generatepress', 'default' ],
 [ 'hello-elementor', 'default' ], [ 'hello-elementor', 'elementor' ]
];

test.describe( 'launch theme compatibility', () => {
 test.skip( process.env.LLAMAHIRE_THEME_MATRIX !== '1', 'Run npm run test:e2e:themes against the disposable site.' );
 test.describe.configure( { mode: 'serial', timeout: 180_000 } );
 let originalTheme;
 let elementorWasActive;
 let originalStyles;
 let fixturesCreated = false;
 let elementorInstalled = false;
 test.beforeAll( async ( { baseURL } ) => {
  if ( baseURL !== 'http://localhost:8897' ) { throw new Error( 'Theme switching is restricted to the disposable wp-env site.' ); }
  originalTheme = ( await wp( 'eval', 'echo "THEME:" . get_stylesheet();' ) ).match( /THEME:([a-z0-9-]+)/ )[ 1 ];
  elementorWasActive = ( await wp( 'eval', 'echo "STATE:" . (is_plugin_active("elementor/elementor.php") ? "ACTIVE" : "INACTIVE");' ) ).includes( 'STATE:ACTIVE' );
  await wp( 'theme', 'install', 'twentytwentyfive', 'astra', 'generatepress', 'hello-elementor' );
  await wp( 'plugin', 'install', 'elementor' );
  elementorInstalled = true;
  fixturesCreated = true;
  await wp( 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/setup.php' );
  await wp( 'eval', 'wp_set_current_user(get_user_by("login","admin")->ID); $s=\\LlamaHire\\Settings::get(); $s["site_mode"]="job_board"; update_option(\\LlamaHire\\Settings::OPTION,\\LlamaHire\\Settings::sanitize($s));' );
  await wp( 'eval', '$p=WP_Block_Patterns_Registry::get_instance()->get_registered("llamahire/careers-page"); $id=wp_insert_post(array("post_type"=>"page","post_status"=>"publish","post_title"=>"LlamaHire E2E Careers","post_name"=>"llamahire-e2e-careers","post_content"=>$p["content"])); $s=\\LlamaHire\\Settings::get(); $s["careers_page_id"]=$id; update_option(\\LlamaHire\\Settings::OPTION,$s);' );
  await wp( 'theme', 'activate', 'twentytwentyfive' );
  const output = await wp( 'eval', 'echo "STYLES:" . base64_encode(get_post(WP_Theme_JSON_Resolver::get_user_global_styles_post_id())->post_content);' );
  originalStyles = output.match( /STYLES:([A-Za-z0-9+/=]+)/ )[ 1 ];
 } );
 test.afterAll( async () => {
  if ( originalStyles ) { await wp( 'theme', 'activate', 'twentytwentyfive' ); await wp( 'eval', `wp_update_post(array("ID"=>WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),"post_content"=>wp_slash(base64_decode("${ originalStyles }"))));` ); }
  if ( elementorInstalled && elementorWasActive ) { await wp( 'plugin', 'activate', 'elementor' ); } else if ( elementorInstalled ) { await wp( 'plugin', 'deactivate', 'elementor' ); }
  if ( originalTheme ) { await wp( 'theme', 'activate', originalTheme ); }
  if ( fixturesCreated ) { await wp( 'eval-file', 'wp-content/plugins/llamahire/tests/e2e/cleanup.php' ); }
 } );
 for ( const [ theme, variant ] of matrix ) {
  test( `${ theme } / ${ variant }`, async ( { page, context }, testInfo ) => {
   await wp( 'theme', 'activate', theme );
   await wp( 'plugin', variant === 'elementor' ? 'activate' : 'deactivate', 'elementor' );
   if ( theme === 'twentytwentyfive' ) {
    await wp( 'eval', variant === 'midnight'
     ? '$v=json_decode(file_get_contents(get_template_directory()."/styles/08-midnight.json"),true); $v["isGlobalStylesUserThemeJSON"]=true; wp_update_post(array("ID"=>WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),"post_content"=>wp_slash(wp_json_encode($v))));'
     : `wp_update_post(array("ID"=>WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),"post_content"=>wp_slash(base64_decode("${ originalStyles }"))));` );
   }
   await testInfo.attach( 'theme-version', { body: await wp( 'theme', 'get', theme, '--field=version' ), contentType: 'text/plain' } );
   for ( const width of [ 1440, 360 ] ) {
    await page.setViewportSize( { width, height: 1000 } );
    for ( const url of [ '/llamahire-e2e-careers/', '/llamahire-e2e-patterns/', '/llamahire-e2e-department/', '/jobs/', '/jobs/llamahire-e2e-job/', '/employer-registration/' ] ) {
     await page.goto( url );
     await page.evaluate( () => document.fonts.ready );
     if ( url === '/jobs/' && theme !== 'twentytwentyfive' ) {
      await expect( page.getByRole( 'link', { name: 'LlamaHire Browser Test Role', exact: true } ).first() ).toBeVisible();
     } else { await expect( page.locator( '.llamahire-job-card,.llamahire-application,.llamahire-employer-registration' ).first() ).toBeVisible(); }
     expect( await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth ), `${ url } at ${ width }px` ).toBeLessThanOrEqual( 1 );
     if ( url.includes( '/jobs/llamahire-' ) ) {
      await expect( page.locator( '.llamahire-application' ) ).toHaveCount( 1 );
      await expect( page.locator( 'input[name="email"]' ) ).toBeVisible();
      await expect( page.getByRole( 'button', { name: 'Submit application', exact: true } ) ).toBeVisible();
      const contrasts = await page.evaluate( () => {
       const canvas = document.createElement( 'canvas' ); canvas.width = canvas.height = 1;
       const ctx = canvas.getContext( '2d' );
       function rgba( value ) { ctx.clearRect( 0, 0, 1, 1 ); ctx.fillStyle = value; ctx.fillRect( 0, 0, 1, 1 ); return Array.from( ctx.getImageData( 0, 0, 1, 1 ).data ); }
       function luminance( rgb ) { return rgb.slice( 0, 3 ).map( ( v ) => { const c = v / 255; return c <= .04045 ? c / 12.92 : ( ( c + .055 ) / 1.055 ) ** 2.4; } ).reduce( ( sum, c, i ) => sum + c * [ .2126, .7152, .0722 ][ i ], 0 ); }
       return Array.from( document.querySelectorAll( '.llamahire-job-facts dt,.llamahire-application small,.llamahire-required,.llamahire-application button' ) ).map( ( el ) => {
        let ancestor = el; let background;
        while ( ancestor ) { background = rgba( getComputedStyle( ancestor ).backgroundColor ); if ( background[ 3 ] === 255 ) { break; } ancestor = ancestor.parentElement; }
        if ( ! ancestor ) { background = [ 255, 255, 255, 255 ]; }
        const text = luminance( rgba( getComputedStyle( el ).color ) ); const surface = luminance( background );
        return { label: el.tagName, ratio: ( Math.max( text, surface ) + .05 ) / ( Math.min( text, surface ) + .05 ) };
       } );
      } );
      for ( const contrast of contrasts ) { expect( contrast.ratio, `${ theme } ${ contrast.label } contrast` ).toBeGreaterThanOrEqual( 4.5 ); }

      if ( width === 1440 ) {
       await page.locator( '.llamahire-single-layout' ).evaluate( ( el ) => { el.parentElement.style.maxWidth = '600px'; } );
       const columns = await page.locator( '.llamahire-single-layout' ).evaluate( ( el ) => getComputedStyle( el ).gridTemplateColumns );
       expect( columns.trim().split( /\s+/ ) ).toHaveLength( 1 );
      }
     }
     await testInfo.attach( `${ theme }-${ variant }-${ width }-${ url.replaceAll( '/', '_' ) }`, { body: await page.screenshot( { fullPage: true, path: testInfo.outputPath( `${ width }-${ url.replaceAll( '/', '_' ) }.png` ) } ), contentType: 'image/png' } );
    }
   }
   await page.goto( '/llamahire-e2e-careers/?job_search=NoSuchThemeMatrixRole' );
   await expect( page.locator( '.llamahire-empty' ) ).toBeVisible();
   for ( const state of [ 'required', 'success' ] ) {
    await page.goto( `/jobs/llamahire-e2e-job/?application=${ state }` );
    await expect( page.locator( `.llamahire-notice.is-${ state === 'success' ? 'success' : 'error' }` ) ).toBeVisible();
    await testInfo.attach( `notice-${ state }`, { body: await page.screenshot( { fullPage: true, path: testInfo.outputPath( `notice-${ state }.png` ) } ), contentType: 'image/png' } );
   }
   await page.goto( '/wp-login.php' );
   await page.locator( '#user_login' ).fill( 'llamahire-employer' );
   await page.locator( '#user_pass' ).fill( 'password' );
   await page.locator( '#wp-submit' ).click();
   await expect( page ).not.toHaveURL( /wp-login\.php/ );
   for ( const width of [ 1440, 360 ] ) {
    await page.setViewportSize( { width, height: 1000 } );
    for ( const url of [ '/my-jobs/', '/employer-account/', '/submit-a-job/' ] ) {
     await page.goto( url );
     await expect( page.locator( '.llamahire-employer-portal,.llamahire-employer-account' ).first() ).toBeVisible();
     expect( await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth ), `${ url } at ${ width }px` ).toBeLessThanOrEqual( 1 );
     await testInfo.attach( `employer-${ width }-${ url.replaceAll( '/', '_' ) }`, { body: await page.screenshot( { fullPage: true, path: testInfo.outputPath( `employer-${ width }-${ url.replaceAll( '/', '_' ) }.png` ) } ), contentType: 'image/png' } );
    }
   }
   await context.clearCookies();
  } );
 }
} );
