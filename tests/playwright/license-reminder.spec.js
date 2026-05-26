const { test, expect } = require( './fixtures' );

const HISTORY_PAGE = '/wp-admin/admin.php?page=simple_history_admin_menu_page';
const SETTINGS_PAGE = '/wp-admin/admin.php?page=simple_history_settings_page';
const LICENSES_TAB =
	'/wp-admin/admin.php?page=simple_history_settings_page&selected-tab=general_settings_subtab_general&selected-sub-tab=general_settings_subtab_licenses';

const PREMIUM_FILE = 'simple-history-premium/simple-history-premium.php';
const PREMIUM_SLUG = 'simple-history-premium';

// Sets up the "premium active, no license key" state via the dev-tools REST
// endpoints (gated by SIMPLE_HISTORY_DEV). If premium isn't installed at all,
// skips the suite so the tests fail loudly rather than silently passing in the
// wrong environment.
//
// Serial mode: the last test mutates the license key option, which would race
// other tests in parallel mode (fullyParallel is true in playwright.config.js).
test.describe.configure( { mode: 'serial' } );

test.describe( 'License reminder card', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		let status;
		try {
			status = await requestUtils.rest( {
				path: 'simple-history/v1/dev-tools/plugin-status',
				params: { plugin: PREMIUM_FILE },
			} );
		} catch ( err ) {
			test.skip(
				true,
				`Dev-tools REST endpoint unreachable — is SIMPLE_HISTORY_DEV enabled? (${ err.message })`
			);
			return;
		}

		if ( ! status.is_active ) {
			await requestUtils.rest( {
				method: 'POST',
				path: 'simple-history/v1/dev-tools/toggle-plugin',
				data: { plugin: PREMIUM_FILE },
			} );
		}

		await requestUtils.rest( {
			method: 'POST',
			path: 'simple-history/v1/dev-tools/set-license-key',
			data: { slug: PREMIUM_SLUG, key: '' },
		} );
	} );

	test( 'shows on history page when premium active without license', async ( {
		page,
	} ) => {
		await page.goto( HISTORY_PAGE );
		const card = page.locator( '.sh-LicenseReminder' );
		await expect( card ).toBeVisible();
		await expect( card ).toContainText(
			'Add your Simple History Premium license key'
		);
		await expect(
			card.getByRole( 'link', { name: 'Add license key' } )
		).toBeVisible();
	} );

	test( 'shows on settings page', async ( { page } ) => {
		await page.goto( SETTINGS_PAGE );
		await expect( page.locator( '.sh-LicenseReminder' ) ).toBeVisible();
	} );

	test( 'hides on the licenses sub-tab itself', async ( { page } ) => {
		await page.goto( LICENSES_TAB );
		await expect( page.locator( '.sh-LicenseReminder' ) ).toHaveCount( 0 );
	} );

	test( 'CTA links to the licenses sub-tab', async ( { page } ) => {
		await page.goto( HISTORY_PAGE );
		const link = page.locator( '.sh-LicenseReminder a.button-primary' );
		const href = await link.getAttribute( 'href' );
		expect( href ).toContain( 'general_settings_subtab_licenses' );
	} );

	test( 'hides when license key is set, reappears when cleared', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: 'simple-history/v1/dev-tools/set-license-key',
			data: { slug: PREMIUM_SLUG, key: 'TEST-KEY-VISIBLE-WHEN-CLEARED' },
		} );

		await page.goto( HISTORY_PAGE );
		await expect( page.locator( '.sh-LicenseReminder' ) ).toHaveCount( 0 );

		await requestUtils.rest( {
			method: 'POST',
			path: 'simple-history/v1/dev-tools/set-license-key',
			data: { slug: PREMIUM_SLUG, key: '' },
		} );

		await page.goto( HISTORY_PAGE );
		await expect( page.locator( '.sh-LicenseReminder' ) ).toBeVisible();
	} );
} );
