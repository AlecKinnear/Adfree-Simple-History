<?php

require_once 'functions.php';

use Simple_History\Simple_History;
use Simple_History\Loggers\Options_Logger;
use function Simple_History\tests\get_latest_row;
use function Simple_History\tests\get_latest_context;

/**
 * Tests that Options_Logger captures option changes made outside the
 * wp-admin Settings forms (REST API and WP-CLI).
 *
 * Issue #184: changes to built-in options like `blogdescription` (tagline)
 * via POST /wp/v2/settings or `wp option update` are silently dropped
 * because the logger only attaches its `updated_option` listener inside
 * the `load-options.php` / `load-options-permalink.php` page-load hooks.
 *
 * Source gating is preserved: only changes from
 *  - the wp-admin built-in Settings pages,
 *  - WP core's /wp/v2/settings REST endpoint, or
 *  - WP-CLI
 * should be logged. Arbitrary code paths (plugin admin pages, plugin REST
 * endpoints, cron, etc.) must NOT produce log rows even for tracked options.
 *
 * Test ordering matters: the negative test and the REST tests must run
 * BEFORE the WP-CLI tests, because defining the WP_CLI constant is
 * irreversible within a single PHPUnit process.
 *
 * Run with:
 * docker compose run --rm php-cli vendor/bin/codecept run wpunit OptionsLoggerRestCliTest
 */
class OptionsLoggerRestCliTest extends \Codeception\TestCase\WPTestCase {
	/** @var Simple_History */
	private $sh;

	/** @var Options_Logger */
	private $logger;

	/** @var int */
	private $admin_user_id;

	public function setUp(): void {
		parent::setUp();

		$this->sh     = Simple_History::get_instance();
		$this->logger = $this->sh->get_instantiated_logger_by_slug( 'SimpleOptionsLogger' );

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	public function tearDown(): void {
		// Reset any $_REQUEST values a test may have set so we don't leak
		// admin-form context into the next test.
		unset( $_REQUEST['option_page'] );
		parent::tearDown();
	}

	public function test_logger_exists_and_is_loaded() {
		$this->assertNotNull( $this->logger, 'Options_Logger should be instantiated' );
		$this->assertInstanceOf( Options_Logger::class, $this->logger );
		$this->assertEquals( 'SimpleOptionsLogger', $this->logger->get_slug() );
	}

	/**
	 * Negative: a plain update_option() call from a non-admin, non-REST,
	 * non-CLI context (e.g. a plugin's settings handler or background task)
	 * must NOT produce a log row, even for a tracked option like
	 * blogdescription.
	 *
	 * IMPORTANT: This test must run before any test that defines WP_CLI,
	 * otherwise the source gate would treat it as a CLI call.
	 */
	public function test_does_not_log_blogdescription_change_from_arbitrary_context() {
		$this->assertFalse(
			defined( 'WP_CLI' ) && WP_CLI,
			'Negative test requires WP_CLI to be undefined — fix the test order if this fails'
		);

		$original = get_option( 'blogdescription' );
		$new_value = 'Set by plugin code ' . wp_generate_password( 6, false );

		$count_before = $this->get_options_logger_event_count();

		update_option( 'blogdescription', $new_value );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals(
			$count_before,
			$count_after,
			'Options_Logger should not log changes from arbitrary code paths (no admin form, no REST settings route, no CLI)'
		);

		// Restore.
		update_option( 'blogdescription', $original );
	}

	/**
	 * Regression: the wp-admin Settings → General path must keep working.
	 * It posts `option_page=general` in $_REQUEST; the handler should detect
	 * the admin form, log the change, and tag it with option_page=general.
	 */
	public function test_logs_blogdescription_change_via_admin_form_general() {
		$_REQUEST['option_page'] = 'general';

		$original = get_option( 'blogdescription' );
		$new_value = 'Tagline via admin form ' . wp_generate_password( 6, false );

		$count_before = $this->get_options_logger_event_count();

		update_option( 'blogdescription', $new_value );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals(
			$count_before + 1,
			$count_after,
			'Admin Settings → General form submit must still log (no regression)'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'option_updated' );
		$this->assert_context_has( $context, 'option', 'blogdescription' );
		$this->assert_context_has( $context, 'new_value', $new_value );
		$this->assert_context_has( $context, 'option_page', 'general' );

		// Restore.
		update_option( 'blogdescription', $original );
	}

	/**
	 * REST: POST /wp/v2/settings with a new `description` (= blogdescription / tagline)
	 * should produce an option_updated log row, just like changing it via
	 * Settings → General.
	 */
	public function test_logs_blogdescription_change_via_rest_api() {
		$original = get_option( 'blogdescription' );
		$new_value = 'Tagline set via REST ' . wp_generate_password( 6, false );

		$count_before = $this->get_options_logger_event_count();

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_param( 'description', $new_value );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'REST settings update should succeed' );
		$this->assertEquals( $new_value, get_option( 'blogdescription' ), 'Option should have been updated' );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals(
			$count_before + 1,
			$count_after,
			'Exactly one Options_Logger event should be recorded for the REST settings update'
		);

		$row = get_latest_row();
		$this->assertEquals( 'SimpleOptionsLogger', $row['logger'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'option_updated' );
		$this->assert_context_has( $context, 'option', 'blogdescription' );
		$this->assert_context_has( $context, 'new_value', $new_value );

		// Restore.
		update_option( 'blogdescription', $original );
	}

	/**
	 * REST: same path, different option. Verifies the fix isn't accidentally
	 * tied to blogdescription. WP core exposes the site title as `title` on
	 * the /wp/v2/settings endpoint, which writes to `blogname`.
	 */
	public function test_logs_blogname_change_via_rest_api() {
		$original = get_option( 'blogname' );
		$new_value = 'Site title via REST ' . wp_generate_password( 6, false );

		$count_before = $this->get_options_logger_event_count();

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_param( 'title', $new_value );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'REST settings update should succeed' );
		$this->assertEquals( $new_value, get_option( 'blogname' ), 'Option should have been updated' );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals(
			$count_before + 1,
			$count_after,
			'Exactly one Options_Logger event should be recorded for the REST settings update'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'option_updated' );
		$this->assert_context_has( $context, 'option', 'blogname' );
		$this->assert_context_has( $context, 'new_value', $new_value );

		// Restore.
		update_option( 'blogname', $original );
	}

	/**
	 * WP-CLI: `wp option update blogdescription "..."` bootstraps WordPress
	 * with `WP_CLI` defined as true, then invokes update_option() from a
	 * non-admin code path. The logger should still record the change.
	 *
	 * We reproduce that environment by defining WP_CLI and calling
	 * update_option() — the same sequence wp-cli executes internally.
	 *
	 * NOTE: PHPUnit cannot un-define a constant after the test. Defining
	 * WP_CLI here leaks into the rest of the test run, which is acceptable
	 * because the fix's source gate only consults WP_CLI to enable logging,
	 * never to disable it. Tests that rely on WP_CLI being undefined must
	 * run before this one.
	 */
	public function test_logs_blogdescription_change_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$this->assertTrue( defined( 'WP_CLI' ) && WP_CLI, 'WP_CLI should be defined to simulate wp-cli runtime' );

		$original = get_option( 'blogdescription' );
		$new_value = 'Tagline set via WP-CLI ' . wp_generate_password( 6, false );

		$count_before = $this->get_options_logger_event_count();

		update_option( 'blogdescription', $new_value );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals(
			$count_before + 1,
			$count_after,
			'Exactly one Options_Logger event should be recorded for a WP-CLI update_option call'
		);

		$row = get_latest_row();
		$this->assertEquals( 'SimpleOptionsLogger', $row['logger'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'option_updated' );
		$this->assert_context_has( $context, 'option', 'blogdescription' );
		$this->assert_context_has( $context, 'new_value', $new_value );

		// Restore.
		update_option( 'blogdescription', $original );
	}

	/**
	 * WP-CLI: also test an option with a custom context-extension handler
	 * (default_category triggers add_context_for_option_default_category)
	 * to verify the per-option enrichment path still runs for CLI changes.
	 */
	public function test_logs_default_category_change_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$original = get_option( 'default_category' );

		// Create a target category to switch to.
		$new_category_id = $this->factory->category->create( array( 'name' => 'CLI Target Category' ) );

		$count_before = $this->get_options_logger_event_count();

		update_option( 'default_category', $new_category_id );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals(
			$count_before + 1,
			$count_after,
			'Exactly one Options_Logger event should be recorded for a WP-CLI default_category update'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'option_updated' );
		$this->assert_context_has( $context, 'option', 'default_category' );
		$this->assert_context_has( $context, 'new_value', (string) $new_category_id );
		// Per-option enrichment should resolve the category name.
		$this->assert_context_has( $context, 'new_category_name', 'CLI Target Category' );

		// Restore.
		update_option( 'default_category', $original );
		wp_delete_term( $new_category_id, 'category' );
	}

	/**
	 * WP-CLI: `permalink_structure` lives in the 'permalinks' (plural) page
	 * group internally, but the admin URL is options-permalink.php (singular).
	 * Verify the reverse-lookup path normalizes to the singular slug so the
	 * Open-page link in the log row resolves correctly.
	 */
	public function test_logs_permalink_structure_change_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$original = get_option( 'permalink_structure' );

		// Seed with a structurally-valid permalink so the next update_option
		// fires `updated_option`. WP's sanitize_option rejects permalink
		// structures without a tag like %postname%, so a "plain" seed string
		// gets silently dropped and the second call would fall back to
		// add_option (which fires `added_option` instead).
		update_option( 'permalink_structure', '/seed/%postname%/' );

		$new_value = '/cli-test-' . wp_generate_password( 6, false ) . '/%postname%/';

		$count_before = $this->get_options_logger_event_count();

		update_option( 'permalink_structure', $new_value );

		$count_after = $this->get_options_logger_event_count();

		$this->assertEquals( $new_value, get_option( 'permalink_structure' ), 'Option should have been updated' );

		$this->assertEquals(
			$count_before + 1,
			$count_after,
			'Exactly one Options_Logger event should be recorded for a WP-CLI permalink_structure update'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'option_updated' );
		$this->assert_context_has( $context, 'option', 'permalink_structure' );
		$this->assert_context_has( $context, 'new_value', $new_value );
		// Plural mapping key must be normalized to the singular admin URL slug.
		$this->assert_context_has( $context, 'option_page', 'permalink' );

		// Restore.
		update_option( 'permalink_structure', $original );
	}

	/**
	 * Count the number of Options_Logger events currently in the log table.
	 */
	private function get_options_logger_event_count(): int {
		global $wpdb;

		$db_table = $this->sh->get_events_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$db_table} WHERE logger = %s",
				'SimpleOptionsLogger'
			)
		);
	}

	/**
	 * Assert that a (key => value) row is present in the latest context.
	 */
	private function assert_context_has( array $context, string $key, string $value ): void {
		$this->assertContains(
			array(
				'key'   => $key,
				'value' => $value,
			),
			$context,
			sprintf( 'Context should contain %s=%s', $key, $value )
		);
	}
}
