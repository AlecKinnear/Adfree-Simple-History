const { test } = require( '@playwright/test' );
const path = require( 'path' );

const EMAIL_REPORTS_SETTINGS =
	'/wp-admin/admin.php?page=simple_history_settings_page' +
	'&selected-tab=general_settings_subtab_general' +
	'&selected-sub-tab=general_settings_subtab_email_reports';

// Captures the Email Reports settings tab — where users enable weekly email
// summaries, pick recipients, and preview the report. Saves to
// .wordpress-org/screenshot-10.png (adjacent to screenshot-11.png which shows
// the actual email preview, so the email story reads as a pair).
test.use( {
	viewport: { width: 950, height: 950 },
	deviceScaleFactor: 2,
} );

test( 'capture email reports settings from playground', async ( { page } ) => {
	test.setTimeout( 120_000 );

	const adminUser = process.env.WP_ADMIN_USER || 'admin';
	const adminPassword = process.env.WP_ADMIN_PASSWORD || 'password';

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPassword );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	page.on( 'pageerror', ( err ) =>
		console.log( 'PAGE-ERR', err.message, '\n', err.stack )
	);

	await page.goto( EMAIL_REPORTS_SETTINGS );
	await page.waitForSelector( '#wpbody-content', { timeout: 30_000 } );
	await page.waitForTimeout( 2000 );

	await page.evaluate( () => {
		document
			.querySelectorAll( '#wpbody-content .notice' )
			.forEach( ( el ) => ( el.style.display = 'none' ) );
	} );

	const viewport = page.viewportSize();
	await page.mouse.move( 0, viewport.height - 1 );
	await page.waitForTimeout( 200 );

	const outputPath = path.join(
		__dirname,
		'../../.wordpress-org/screenshot-10.png'
	);

	await page.screenshot( { path: outputPath, fullPage: false } );
} );
