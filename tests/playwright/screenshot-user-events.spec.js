const { test } = require( '@playwright/test' );
const path = require( 'path' );

const SIMPLE_HISTORY_PAGE =
	'/wp-admin/admin.php?page=simple_history_admin_menu_page';

// Stacks the two `deedee` user lifecycle events from events.php — Alex
// editing her profile (Douglas Colvin → Dee Dee Ramone) above the original
// `user_created` row — into a single tight clip. Tells the "users created or
// changed" story in one frame and shows the diff for first name, last name,
// website, and display name. Saves to .wordpress-org/screenshot-3.png.
test.use( {
	viewport: { width: 1100, height: 1200 },
	deviceScaleFactor: 2,
} );

test( 'capture stacked user events from playground', async ( { page } ) => {
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

	// Park cursor + dispatch mouseleave so no row carries a hover background.
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

	const editEvent = page.locator( '.SimpleHistoryLogitem', {
		hasText: 'Edited the profile for user deedee',
	} );
	const createEvent = page.locator( '.SimpleHistoryLogitem', {
		hasText: 'Created user deedee',
	} );

	await editEvent.waitFor( { state: 'visible', timeout: 30_000 } );
	await createEvent.waitFor( { state: 'visible', timeout: 30_000 } );

	// Scroll the create event into view so both rows are within the viewport
	// before measuring bounding boxes.
	await createEvent.scrollIntoViewIfNeeded();
	await page.waitForTimeout( 300 );

	const editBox = await editEvent.boundingBox();
	const createBox = await createEvent.boundingBox();

	if ( ! editBox || ! createBox ) {
		throw new Error( 'Could not measure stacked user events' );
	}

	// Combined bounding box across both rows + a small padding.
	const pad = 12;
	const top = Math.min( editBox.y, createBox.y ) - pad;
	const left = Math.min( editBox.x, createBox.x ) - pad;
	const right = Math.max(
		editBox.x + editBox.width,
		createBox.x + createBox.width
	);
	const bottom = Math.max(
		editBox.y + editBox.height,
		createBox.y + createBox.height
	);

	const outputPath = path.join(
		__dirname,
		'../../.wordpress-org/screenshot-3.png'
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
