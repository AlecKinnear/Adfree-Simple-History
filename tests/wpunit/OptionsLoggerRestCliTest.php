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

	public function test_logger_exists_and_is_loaded() {
		$this->assertNotNull( $this->logger, 'Options_Logger should be instantiated' );
		$this->assertInstanceOf( Options_Logger::class, $this->logger );
		$this->assertEquals( 'SimpleOptionsLogger', $this->logger->get_slug() );
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
	 * WP-CLI: `wp option update blogdescription "..."` bootstraps WordPress
	 * with `WP_CLI` defined as true, then invokes update_option() from a
	 * non-admin code path. The logger should still record the change.
	 *
	 * We reproduce that environment by defining WP_CLI and calling
	 * update_option() — the same sequence wp-cli executes internally.
	 *
	 * NOTE: PHPUnit cannot un-define a constant after the test. Defining
	 * WP_CLI here leaks into the rest of the test run, which is acceptable
	 * because the fix should hook `updated_option` unconditionally (not
	 * gate behavior on WP_CLI).
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
