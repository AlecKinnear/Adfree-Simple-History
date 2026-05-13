const { test } = require( '@playwright/test' );
const path = require( 'path' );

const SIMPLE_HISTORY_PAGE =
	'/wp-admin/admin.php?page=simple_history_admin_menu_page';

// Capture spec used by tests/screenshot/run.sh against a fresh WordPress
// Playground instance. Logs in as admin/password (the playground defaults
// from the blueprint's `login` step) and saves to .wordpress-org/screenshot-1.png.
test.use( {
	viewport: { width: 1600, height: 1130 },
	deviceScaleFactor: 2,
} );

test( 'capture main screenshot from playground', async ( { page } ) => {
	// Generous overall budget — playground first-page hit can be slow when
	// the blueprint populated ~70 historical events.
	test.setTimeout( 120_000 );

	const adminUser = process.env.WP_ADMIN_USER || 'admin';
	const adminPassword = process.env.WP_ADMIN_PASSWORD || 'password';

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPassword );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	// Surface JS errors / failed network requests so we know if React crashed.
	page.on( 'pageerror', ( err ) =>
		console.log( 'PAGE-ERR', err.message, '\n', err.stack )
	);
	page.on( 'response', ( res ) => {
		if ( res.status() >= 400 ) {
			console.log( 'BAD-RESP', res.status(), res.url() );
		}
	} );

	await page.goto( SIMPLE_HISTORY_PAGE );
	await page.waitForSelector( '.SimpleHistoryLogitems.is-loaded', {
		timeout: 60_000,
	} );

	// Let avatars / attachment thumbs finish loading.
	await page.waitForTimeout( 2000 );

	// Hide any admin notices for a clean shot.
	await page.evaluate( () => {
		document
			.querySelectorAll( '#wpbody-content .notice' )
			.forEach( ( el ) => ( el.style.display = 'none' ) );
	} );

	// Park the mouse far down/left, then dispatch a synthetic mouseleave on
	// the event-row container so any in-flight hover state from cursor
	// movement during navigation is cleared before the screenshot.
	const viewport = page.viewportSize();
	await page.mouse.move( 0, viewport.height - 1 );
	await page.evaluate( () => {
		document
			.querySelectorAll(
				'.SimpleHistoryLogitem, .SimpleHistoryLogitem__senderImage, .SimpleHistoryLogitems'
			)
			.forEach( ( el ) => {
				el.dispatchEvent(
					new MouseEvent( 'mouseleave', { bubbles: true } )
				);
				el.dispatchEvent(
					new MouseEvent( 'mouseout', { bubbles: true } )
				);
			} );
	} );
	await page.waitForTimeout( 200 );

	const outputPath = path.join(
		__dirname,
		'../../.wordpress-org/screenshot-1.png'
	);
	await page.screenshot( { path: outputPath, fullPage: false } );
} );
