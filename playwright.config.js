const { defineConfig, devices } = require( '@playwright/test' );

// Defaults to the local dev WordPress. Override via env var in CI.
const baseURL =
	process.env.PLAYWRIGHT_BASE_URL ||
	'http://wordpress-stable-docker-mariadb.test:8282';

// Specs captured by tests/screenshot/run.sh against the fresh Playground
// instance (plus `banner`, which renders local HTML over file://).
const screenshotSpecs = [
	'playground',
	'dashboard-widget',
	'inline-diff',
	'event-details',
	'user-events',
	'plugin-install',
	'insights-widget',
	'stats-page',
	'email-settings',
	'ip-popover',
	'email-preview',
	'banner',
];
const screenshotTestMatch = new RegExp(
	`screenshot-(${ screenshotSpecs.join( '|' ) })\\.spec\\.js$`
);

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
		{
			// Used by tests/screenshot/run.sh against a fresh WP Playground
			// instance. No setup dependency — logs in fresh per run. Matches
			// both the main log screenshot and the dashboard widget screenshot.
			name: 'screenshot',
			use: {
				...devices[ 'Desktop Chrome' ],
				baseURL:
					process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:9445',
			},
			testMatch: screenshotTestMatch,
		},
	],
} );
