---
name: testing
description: Guidance for writing and running tests in Simple History. Covers which framework to use, how to run existing tests, and how to write new ones.
allowed-tools: Read, Bash, Edit, Write
---

# Testing in Simple History

## Which framework to use

| What you're testing                                | Framework                    | Why                                              |
| -------------------------------------------------- | ---------------------------- | ------------------------------------------------ |
| Browser UI, admin pages, visual behaviour          | **Playwright**               | Fast, visible, modern — new default for UI tests |
| PHP logic, WordPress integration, database queries | **Codeception / WPUnit**     | Full WordPress environment loaded in PHP         |
| HTTP-level WordPress behaviour                     | **Codeception / Functional** | No browser needed                                |

**Rule of thumb:** If a human would test it by clicking around in a browser, use Playwright. If it's PHP logic, use Codeception.

## Running tests

```bash
# Playwright (UI tests) — runs on host machine against the dev WordPress
npm run test:playwright          # headless, output in playwright-report/
npm run test:playwright:ui       # interactive UI mode (recommended for writing/debugging)

# Codeception (PHP tests) — runs inside Docker
npm run test:wpunit              # PHP unit + WordPress integration
npm run test:functional          # HTTP-level tests
npm run test:acceptance          # legacy browser tests (Selenium — prefer Playwright for new ones)

# Full PHP suite (Codeception only — does NOT include Playwright)
npm test
```

**Note:** `npm test` runs only the Codeception suite. To get full coverage, run both `npm run test:playwright` and `npm test` separately.

## Playwright setup

-   **Config:** `playwright.config.js`
-   **Tests:** `tests/playwright/*.spec.js`
-   **Auth:** login cached in `tests/playwright/.auth/admin.json` (gitignored) — regenerated on every run by `auth.setup.js` before tests execute. If auth breaks (wrong credentials, WordPress unreachable), delete `tests/playwright/.auth/admin.json` and re-run.
-   **Target:** dev WordPress at `http://wordpress-stable-docker-mariadb.test:8282` (override with `PLAYWRIGHT_BASE_URL` env var)
-   **Admin credentials:** `claude` / `claude` (override with `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD`)
-   **HTML report:** written to `playwright-report/` after each run — open it to debug failures

### Writing a new Playwright test

Basic test (no test data needed) — import from `@playwright/test`:

```js
const { test, expect } = require( '@playwright/test' );

test( 'my test', async ( { page } ) => {
	await page.goto(
		'/wp-admin/admin.php?page=simple_history_admin_menu_page'
	);
	// Always wait for the log list to finish loading before asserting.
	await page.waitForSelector( '.SimpleHistoryLogitems.is-loaded' );
	await expect(
		page.locator( '.SimpleHistoryLogitem__text' ).first()
	).toBeVisible();
} );
```

Test that needs to create WordPress data — import from `./fixtures` to get `requestUtils`:

```js
const { test, expect } = require( './fixtures' );

test.beforeEach( async ( { requestUtils } ) => {
	post = await requestUtils.createPost( {
		title: 'Test post',
		status: 'publish',
	} );
} );

test.afterEach( async ( { requestUtils } ) => {
	// Delete by ID — never use deleteAllPosts() against the live dev site (see warning below).
	await requestUtils.rest( {
		path: `/wp/v2/posts/${ post.id }`,
		method: 'DELETE',
		params: { force: true },
	} );
} );

test( 'logs post creation', async ( { page } ) => {
	await page.goto(
		'/wp-admin/admin.php?page=simple_history_admin_menu_page'
	);
	await page.waitForSelector( '.SimpleHistoryLogitems.is-loaded' );
	await expect(
		page
			.locator( '.SimpleHistoryLogitem__text', { hasText: 'Test post' } )
			.first()
	).toBeVisible();
} );
```

`requestUtils` uses the saved admin session (cookie auth) — no application password needed.

### Test data and state

-   Tests run against the live dev WordPress — your existing log events and content stay untouched
-   Write assertions that are true regardless of existing data ("at least one event", not "exactly 5 events")
-   When a test needs specific data, create it in `beforeEach` via `requestUtils` and clean up in `afterEach`
-   Cleanup deletions are also logged by Simple History — that's expected and correct, just accept it

### Available `requestUtils` methods (selection)

```js
requestUtils.createPost( payload ); // returns post object with .id
requestUtils.createPage( payload ); // returns page object with .id
requestUtils.createUser( payload ); // returns user object
requestUtils.rest( { path, method, params, data } ); // arbitrary REST API call
```

> **Warning:** Do NOT use `deleteAllPosts()`, `deleteAllPages()`, or similar bulk-delete methods. Tests run against the live dev WordPress — bulk deletes will wipe real content. Always delete by ID using `requestUtils.rest()`.

## Codeception PHP tests

-   **Config:** `codeception.dist.yml`, `tests/*.suite.yml`
-   **Tests:** `tests/wpunit/`, `tests/functional/`, `tests/acceptance/`
-   **Environment:** `tests/.env.testing`
-   All PHP tests run inside Docker via `docker compose run --rm php-cli`

## Migrating old acceptance tests to Playwright

Don't migrate proactively. When you're already working on a feature that has a Codeception acceptance test (`tests/acceptance/*Cest.php`), migrate it to Playwright at that point. Leave the rest as-is.
