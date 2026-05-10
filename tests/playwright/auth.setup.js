const { test: setup } = require( '@playwright/test' );
const path = require( 'path' );

const adminUser = process.env.WP_ADMIN_USER || 'claude';
const adminPassword = process.env.WP_ADMIN_PASSWORD || 'claude';

// Logs in once and saves the authenticated browser state so all tests can
// reuse it without logging in again on each run.
setup( 'authenticate as admin', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPassword );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin|wp-login/ );

	// WordPress may show a "confirm admin email" interstitial — skip it.
	if ( page.url().includes( 'action=confirm_admin_email' ) ) {
		await page.click(
			'#confirm_admin_email_form [name="remind_me_later"]'
		);
		await page.waitForURL( '**/wp-admin/**' );
	}

	await page.context().storageState( {
		path: path.join( __dirname, '.auth/admin.json' ),
	} );
} );
