const { defineConfig } = require( '@playwright/test' );

// Candidate fixtures must never become automatic DOM snapshots in failure artifacts.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	globalSetup: require.resolve( './tests/e2e/privacy-setup.js' ),
	testMatch: '**/*.spec.js',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	reporter: process.env.CI ? [ [ 'github' ], [ 'line' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8897',
		launchOptions: process.env.PLAYWRIGHT_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH } : {},
		trace: 'off',
		screenshot: 'off',
		video: 'off'
	}
} );
