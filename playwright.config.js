const { defineConfig, devices } = require( '@playwright/test' );

// Defaults to the local dev WordPress. Override via env var in CI.
const baseURL =
	process.env.PLAYWRIGHT_BASE_URL ||
	'http://wordpress-stable-docker-mariadb.test:8282';

// @wordpress/e2e-test-utils-playwright reads WP_BASE_URL from env at module
// load time, so set it here before any test files import the package.
process.env.WP_BASE_URL = baseURL;

module.exports = defineConfig( {
	testDir: './tests/playwright',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: 'html',
	use: {
		baseURL,
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /auth\.setup\.js/,
		},
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				// Reuse admin login session across tests.
				storageState: 'tests/playwright/.auth/admin.json',
			},
			dependencies: [ 'setup' ],
		},
	],
} );
