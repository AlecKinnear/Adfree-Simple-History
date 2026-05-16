<?php

require_once 'functions.php';

use Simple_History\Simple_History;
use Simple_History\Loggers\User_Logger;
use function Simple_History\tests\get_latest_row;
use function Simple_History\tests\get_latest_context;

/**
 * Tests that User_Logger captures profile updates made via REST API and WP-CLI.
 *
 * Issue #185: on_pre_insert_user_data_collect() bails when get_current_screen()
 * returns null (no screen under REST or WP-CLI). With no snapshot, the commit
 * step also bails, producing no event.
 *
 * Affected: wp user update (display_name, user_pass, user_email),
 *           wp user reset-password, REST POST /wp/v2/users/<id>.
 * Unaffected: wp user create/delete, wp user add-role/remove-role (those
 *             already have explicit WP_CLI hooks).
 *
 * Test ordering: negative test and REST tests run before the WP_CLI test.
 *
 * Run with:
 * docker compose run --rm php-cli vendor/bin/codecept run wpunit UserLoggerRestCliTest
 */
class UserLoggerRestCliTest extends \Codeception\TestCase\WPTestCase {
	/** @var Simple_History */
	private $sh;

	/** @var User_Logger */
	private $logger;

	/** @var int */
	private $admin_user_id;

	/** @var int */
	private $target_user_id;

	public function setUp(): void {
		parent::setUp();

		$this->sh     = Simple_History::get_instance();
		$this->logger = $this->sh->get_instantiated_logger_by_slug( 'SimpleUserLogger' );

		$this->admin_user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->target_user_id = $this->factory->user->create( array(
			'role'         => 'subscriber',
			'display_name' => 'Original Name',
			'user_email'   => 'original@example.com',
		) );

		wp_set_current_user( $this->admin_user_id );
	}

	public function test_logger_exists_and_is_loaded() {
		$this->assertNotNull( $this->logger );
		$this->assertInstanceOf( User_Logger::class, $this->logger );
	}

	/**
	 * Negative: wp_update_user() from an arbitrary context (e.g. plugin code)
	 * should NOT log, to avoid noise from background processes.
	 *
	 * NOTE: Whether to allow all wp_update_user() calls or gate by source is a
	 * decision noted in the issue. This test encodes the conservative choice
	 * (allowlist). If the decision is to log all updates, delete this test.
	 *
	 * Must run before any test that defines WP_CLI.
	 */
	public function test_does_not_log_user_update_from_arbitrary_context() {
		$this->assertFalse(
			defined( 'WP_CLI' ) && WP_CLI,
			'Negative test requires WP_CLI to be undefined'
		);

		$count_before = $this->get_event_count();

		wp_update_user( array(
			'ID'           => $this->target_user_id,
			'display_name' => 'Changed by plugin code',
		) );

		$this->assertEquals(
			$count_before,
			$this->get_event_count(),
			'User_Logger must not log wp_update_user() from a non-admin/REST/CLI context'
		);
	}

	/**
	 * REST: POST /wp/v2/users/<id> with a display_name change must produce a
	 * user_updated_profile event.
	 */
	public function test_logs_user_display_name_change_via_rest_api() {
		// rest_do_request() in the test environment does not define REST_REQUEST
		// (that constant is only set during a real HTTP bootstrap). Define it here
		// so the logger's REST-context detection sees it.
		// Once defined it cannot be undefined — this test must run before any test
		// that expects REST_REQUEST to be absent.
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$count_before = $this->get_event_count();

		$request = new WP_REST_Request( 'POST', "/wp/v2/users/{$this->target_user_id}" );
		$request->set_param( 'name', 'REST Updated Name' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'REST user update should succeed' );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'REST user update must produce exactly one log row'
		);

		$row = get_latest_row();
		$this->assertEquals( 'SimpleUserLogger', $row['logger'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'user_updated_profile' );
	}

	/**
	 * REST: password changes via REST must be logged with the password-changed flag.
	 *
	 * Currently FAILS — same screen-guard root cause.
	 */
	public function test_logs_password_change_via_rest_api() {
		$count_before = $this->get_event_count();

		$request = new WP_REST_Request( 'POST', "/wp/v2/users/{$this->target_user_id}" );
		$request->set_param( 'password', 'NewP@ssw0rd!' . wp_generate_password( 6, false ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'REST user password update should succeed' );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'REST password change must produce a log row'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, 'edited_user_password_changed', '1' );
	}

	/**
	 * WP-CLI: wp_update_user() with WP_CLI=true must produce a log row.
	 *
	 * Currently FAILS — same screen-guard root cause as REST.
	 */
	public function test_logs_user_display_name_change_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$count_before = $this->get_event_count();

		wp_update_user( array(
			'ID'           => $this->target_user_id,
			'display_name' => 'CLI Updated Name',
		) );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'WP-CLI wp_update_user() must produce a user_updated_profile log row'
		);

		$row = get_latest_row();
		$this->assertEquals( 'SimpleUserLogger', $row['logger'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'user_updated_profile' );
	}

	/**
	 * WP-CLI: password changes via wp_update_user() with WP_CLI=true must log
	 * the password-changed flag.
	 *
	 * Currently FAILS — same root cause.
	 */
	public function test_logs_password_change_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$count_before = $this->get_event_count();

		wp_update_user( array(
			'ID'        => $this->target_user_id,
			'user_pass' => 'NewCLIP@ss' . wp_generate_password( 6, false ),
		) );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'WP-CLI password change must produce a log row'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, 'edited_user_password_changed', '1' );
	}

	private function get_event_count(): int {
		global $wpdb;
		$db_table = $this->sh->get_events_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$db_table} WHERE logger = %s",
				'SimpleUserLogger'
			)
		);
	}

	private function assert_context_has( array $context, string $key, string $value ): void {
		$this->assertContains(
			array( 'key' => $key, 'value' => $value ),
			$context,
			sprintf( 'Context should contain %s=%s', $key, $value )
		);
	}
}
