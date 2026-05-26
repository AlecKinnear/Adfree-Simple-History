const { test, expect } = require( '@playwright/test' );

test.describe( 'Simple History dashboard widget', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-admin/' );
	} );

	test( 'shows the widget heading on the dashboard', async ( { page } ) => {
		await expect(
			page.getByRole( 'heading', { name: 'Simple History' } )
		).toBeVisible();
	} );

	test( 'links to the full activity log', async ( { page } ) => {
		await page
			.getByRole( 'link', { name: 'View full activity log →' } )
			.click();

		await page.waitForSelector( '.SimpleHistoryLogitems.is-loaded' );

		await expect( page ).toHaveURL( /page=simple_history_admin_menu_page/ );
	} );
} );
