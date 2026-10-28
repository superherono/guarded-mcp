/**
 * Playwright config for the E2E tests: block markup saved through the
 * plugin's abilities must open in the block editor without a block
 * validation error.
 *
 * Runs against the wp-env development site (http://localhost:8888) unless
 * WP_BASE_URL points elsewhere. PW_CHANNEL=chrome uses an installed Chrome
 * instead of the Chromium that `npx playwright install chromium` downloads.
 */
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	globalSetup: require.resolve( './global-setup.js' ),
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	timeout: 90 * 1000,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never', outputFolder: 'report' } ] ] : 'list',
	outputDir: 'artifacts',
	use: {
		baseURL,
		storageState: require( 'path' ).join( __dirname, '.auth', 'admin.json' ),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				channel: process.env.PW_CHANNEL || undefined,
			},
		},
	],
} );
