const { test } = require( '@playwright/test' );
const path = require( 'path' );

const SIMPLE_HISTORY_PAGE =
	'/wp-admin/admin.php?page=simple_history_admin_menu_page';

// Zoom on a single event with rich inline diff details ("About us" page
// update from events.php). Clips to the event row + its diff so the
// before/after comparison is the entire frame. Saves to
// .wordpress-org/screenshot-2.png.
test.use( {
	viewport: { width: 1100, height: 1000 },
	deviceScaleFactor: 2,
} );

test( 'capture inline diff zoom from playground', async ( { page } ) => {
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
	page.on( 'response', ( res ) => {
		if ( res.status() >= 400 ) {
			console.log( 'BAD-RESP', res.status(), res.url() );
		}
	} );

	await page.goto( SIMPLE_HISTORY_PAGE );
	await page.waitForSelector( '.SimpleHistoryLogitems.is-loaded', {
		timeout: 60_000,
	} );
	await page.waitForTimeout( 2000 );

	await page.evaluate( () => {
		document
			.querySelectorAll( '#wpbody-content .notice' )
			.forEach( ( el ) => ( el.style.display = 'none' ) );
	} );

	// Park cursor so no row has a hover background.
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

	// Locate the "About us" page-update event by its message text. It's the
	// only event with both a title and content diff visible.
	const aboutEvent = page.locator( '.SimpleHistoryLogitem', {
		hasText: 'Updated page "About us"',
	} );

	await aboutEvent.waitFor( { state: 'visible', timeout: 30_000 } );

	const outputPath = path.join(
		__dirname,
		'../../.wordpress-org/screenshot-2.png'
	);

	await aboutEvent.screenshot( { path: outputPath } );
} );
