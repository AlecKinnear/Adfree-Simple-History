const { test } = require( '@playwright/test' );
const path = require( 'path' );

const SIMPLE_HISTORY_PAGE =
	'/wp-admin/admin.php?page=simple_history_admin_menu_page';

// Clips the stacked plugin lifecycle from events.php — Hello Dolly
// deactivated, Yoast SEO activated, Yoast SEO installed — into a single
// frame. Shows the breadth of plugin tracking (install + activate +
// deactivate) plus the rich context (description, source, version, author,
// URL) rendered inline. Saves to .wordpress-org/screenshot-4.png.
test.use( {
	viewport: { width: 1100, height: 1400 },
	deviceScaleFactor: 2,
} );

test( 'capture plugin lifecycle stack from playground', async ( { page } ) => {
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
	await page.waitForTimeout( 2000 );

	await page.evaluate( () => {
		document
			.querySelectorAll( '#wpbody-content .notice' )
			.forEach( ( el ) => ( el.style.display = 'none' ) );
	} );

	// Park cursor + dispatch mouseleave so the rows don't carry a hover bg.
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

	// The deactivate event fires last in events.php → newest → topmost.
	const deactivateEvent = page.locator( '.SimpleHistoryLogitem', {
		hasText: 'Deactivated plugin "Hello Dolly"',
	} );
	const activateEvent = page.locator( '.SimpleHistoryLogitem', {
		hasText: 'Activated plugin "Yoast SEO"',
	} );
	const installEvent = page.locator( '.SimpleHistoryLogitem', {
		hasText: 'Installed plugin "Yoast SEO"',
	} );

	await deactivateEvent.waitFor( { state: 'visible', timeout: 30_000 } );
	await activateEvent.waitFor( { state: 'visible', timeout: 30_000 } );
	await installEvent.scrollIntoViewIfNeeded();
	await activateEvent.scrollIntoViewIfNeeded();
	await deactivateEvent.scrollIntoViewIfNeeded();
	await page.waitForTimeout( 300 );

	const deactivateBox = await deactivateEvent.boundingBox();
	const installBox = await installEvent.boundingBox();

	if ( ! deactivateBox || ! installBox ) {
		throw new Error( 'Could not measure plugin lifecycle stack' );
	}

	const pad = 12;
	const top = Math.min( deactivateBox.y, installBox.y ) - pad;
	const left = Math.min( deactivateBox.x, installBox.x ) - pad;
	const right = Math.max(
		deactivateBox.x + deactivateBox.width,
		installBox.x + installBox.width
	);
	const bottom = Math.max(
		deactivateBox.y + deactivateBox.height,
		installBox.y + installBox.height
	);

	const outputPath = path.join(
		__dirname,
		'../../.wordpress-org/screenshot-4.png'
	);

	await page.screenshot( {
		path: outputPath,
		clip: {
			x: left,
			y: top,
			width: right - left + pad,
			height: bottom - top + pad,
		},
	} );
} );
