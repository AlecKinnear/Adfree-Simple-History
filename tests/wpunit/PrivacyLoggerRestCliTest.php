<?php

require_once 'functions.php';

use Simple_History\Simple_History;
use Simple_History\Loggers\Privacy_Logger;
use function Simple_History\tests\get_latest_row;
use function Simple_History\tests\get_latest_context;

/**
 * Tests that Privacy_Logger captures privacy-page selection made outside
 * the wp-admin options-privacy.php form.
 *
 * Issue #185: on_load_privacy_page() registers the update_option_wp_page_for_privacy_policy
 * hook only when the admin page fires with a specific $_POST['action']. Under
 * WP-CLI (wp option update wp_page_for_privacy_policy <id>) the hook is never
 * registered, so the option update goes unlogged.
 *
 * Fix: register the option hook unconditionally in loaded(). Detect create vs
 * set inside the callback by comparing old/new value to 0.
 *
 * Run with:
 * docker compose run --rm php-cli vendor/bin/codecept run wpunit PrivacyLoggerRestCliTest
 */
class PrivacyLoggerRestCliTest extends \Codeception\TestCase\WPTestCase {
	/** @var Simple_History */
	private $sh;

	/** @var Privacy_Logger */
	private $logger;

	/** @var int */
	private $admin_user_id;

	/** @var int */
	private $privacy_page_id;

	public function setUp(): void {
		parent::setUp();

		$this->sh     = Simple_History::get_instance();
		$this->logger = $this->sh->get_instantiated_logger_by_slug( 'SH_Privacy_Logger' );

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->privacy_page_id = $this->factory->post->create( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Privacy Policy',
		) );
	}

	public function tearDown(): void {
		// Remove the privacy page option so tests don't bleed into each other.
		delete_option( 'wp_page_for_privacy_policy' );
		parent::tearDown();
	}

	public function test_logger_exists_and_is_loaded() {
		$this->assertNotNull( $this->logger );
		$this->assertInstanceOf( Privacy_Logger::class, $this->logger );
	}

	/**
	 * WP-CLI: update_option('wp_page_for_privacy_policy', <page_id>) with
	 * WP_CLI=true must produce a log event.
	 *
	 * Simulates: wp option update wp_page_for_privacy_policy <page_id>
	 *
	 * Currently FAILS — the update_option hook is only registered when the
	 * options-privacy.php admin page loads with a specific POST action.
	 */
	public function test_logs_privacy_page_selection_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		// Ensure starting from 0 so this reads as "set page" not "create page".
		update_option( 'wp_page_for_privacy_policy', 0 );
		$count_before = $this->get_event_count();

		update_option( 'wp_page_for_privacy_policy', $this->privacy_page_id );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'WP-CLI update_option(wp_page_for_privacy_policy) must produce a log row'
		);

		$row = get_latest_row();
		$this->assertEquals( 'SH_Privacy_Logger', $row['logger'] );
	}

	/**
	 * WP-CLI: setting the privacy page for the first time (0 → page_id) should
	 * log as "set" (not "create", which is for generating a new page).
	 *
	 * Currently FAILS — same root cause.
	 */
	public function test_distinguishes_set_vs_create_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		// No existing privacy page → this is a "set" action.
		delete_option( 'wp_page_for_privacy_policy' );
		update_option( 'wp_page_for_privacy_policy', $this->privacy_page_id );

		$context = get_latest_context();

		// The message key should reflect a page-set action, not page-create.
		// Exact key name depends on implementation; we just verify it's logged
		// and contains the page id.
		$context_keys = array_column( $context, 'key' );
		$this->assertNotEmpty( $context_keys, 'Context must not be empty for privacy page selection' );

		$context_values = array_column( $context, 'value' );
		$this->assertContains(
			(string) $this->privacy_page_id,
			$context_values,
			'The privacy page id must appear somewhere in the logged context'
		);
	}

	private function get_event_count(): int {
		global $wpdb;
		$db_table = $this->sh->get_events_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$db_table} WHERE logger = %s",
				'SH_Privacy_Logger'
			)
		);
	}
}
