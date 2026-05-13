const { test } = require( '@playwright/test' );
const path = require( 'path' );

const SIMPLE_HISTORY_PAGE =
	'/wp-admin/admin.php?page=simple_history_admin_menu_page';

// Clips the standalone "History Insights" sidebar widget (`.sh-SidebarStats`)
// rendered next to the event log. Highlights the quick stats / activity chart
// / most active users tile that sells the data-density story even when the
// main log isn't in frame. Saves to .wordpress-org/screenshot-7.png.
test.use( {
	viewport: { width: 1600, height: 1200 },
	deviceScaleFactor: 2,
} );

test( 'capture insights sidebar widget from playground', async ( { page } ) => {
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

	await page.goto( SIMPLE_HISTORY_PAGE );
	await page.waitForSelector( '.SimpleHistoryLogitems.is-loaded', {
		timeout: 60_000,
	} );
	await page.waitForSelector( '.sh-SidebarStats', { timeout: 30_000 } );
	await page.waitForTimeout( 2000 );

	await page.evaluate( () => {
		document
			.querySelectorAll( '#wpbody-content .notice' )
			.forEach( ( el ) => ( el.style.display = 'none' ) );
	} );

	// Park cursor + dispatch mouseleave so nothing inside the sidebar carries
	// a hover background.
	const viewport = page.viewportSize();
	await page.mouse.move( 0, viewport.height - 1 );
	await page.evaluate( () => {
		document
			.querySelectorAll( '.sh-SidebarStats, .sh-SidebarStats *' )
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

	const widget = page.locator( '.sh-SidebarStats' ).first();
	await widget.waitFor( { state: 'visible', timeout: 30_000 } );

	const outputPath = path.join(
		__dirname,
		'../../.wordpress-org/screenshot-7.png'
	);

	await widget.screenshot( { path: outputPath } );
} );
