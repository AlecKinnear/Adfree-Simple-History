<?php

require_once 'functions.php';

use Simple_History\Simple_History;
use Simple_History\Loggers\User_Logger;

/**
 * Test failed application-password authentication logging.
 *
 * Covers the bug where XML-RPC brute force attempts logged an empty username
 * because `$_SERVER['PHP_AUTH_USER']` is never populated for XML-RPC requests.
 * See issue 200.
 *
 * Background — two code paths fire `application_password_failed_authentication`:
 *
 * 1. **XML-RPC**: `class-wp-xmlrpc-server.php` calls `wp_authenticate()` which runs the
 *    `authenticate` filter chain. WP's `wp_authenticate_application_password` runs at
 *    priority 20; our `capture_attempted_username` runs at priority 19 just before it
 *    and stashes the username. PHP_AUTH_USER is NOT populated for XML-RPC.
 *
 * 2. **REST API**: WP's `wp_validate_application_password` is hooked on
 *    `determine_current_user` (priority 20) and calls `wp_authenticate_application_password()`
 *    *directly* — bypassing the `authenticate` filter chain. Our capture does NOT run.
 *    PHP_AUTH_USER is populated by the web server from the Basic-auth header and is the
 *    only source available in this path.
 *
 * The logger must handle both: prefer the captured value when present (XML-RPC),
 * fall back to PHP_AUTH_USER when not (REST API).
 *
 * Run with:
 * docker compose run --rm php-cli vendor/bin/codecept run wpunit UserLoggerAppPasswordTest
 */
class UserLoggerAppPasswordTest extends \Codeception\TestCase\WPTestCase {
	/** @var Simple_History */
	private $sh;

	/** @var User_Logger */
	private $logger;

	/**
	 * Captured data from the most recent `simple_history/log_insert_data_and_context`
	 * filter invocation. Lets the tests assert on what the logger tried to write
	 * without relying on the events table — which is unreliable under the wpunit
	 * transactional setup.
	 *
	 * @var array{0:array,1:array}|null
	 */
	private $captured_log = null;

	public function setUp(): void {
		parent::setUp();

		// The action handler is only registered when this filter returns true.
		add_filter( 'simple_history/log_failed_app_password_auth', '__return_true' );

		$this->sh = Simple_History::get_instance();
		$logger = $this->sh->get_instantiated_logger_by_slug( 'SimpleUserLogger' );

		if ( ! $logger instanceof User_Logger ) {
			$logger = new User_Logger( $this->sh );
			$logger->loaded();
		}

		$this->logger = $logger;

		// Reset both internal state properties so each test starts clean.
		$reflection = new ReflectionClass( $this->logger );

		$captured = $reflection->getProperty( 'last_attempted_username' );
		$captured->setAccessible( true );
		$captured->setValue( $this->logger, null );

		$logged = $reflection->getProperty( 'app_password_failure_logged' );
		$logged->setAccessible( true );
		$logged->setValue( $this->logger, false );

		// Clear any PHP_AUTH_USER from the test environment — leftover values
		// would mask bugs (or accidentally pass tests that should fail).
		unset( $_SERVER['PHP_AUTH_USER'] );

		// Capture what the logger tries to write via the late-stage filter.
		$this->captured_log = null;
		add_filter(
			'simple_history/log_insert_data_and_context',
			[ $this, 'capture_log_write' ],
			10,
			2
		);
	}

	public function tearDown(): void {
		remove_filter( 'simple_history/log_insert_data_and_context', [ $this, 'capture_log_write' ], 10 );
		remove_filter( 'simple_history/log_failed_app_password_auth', '__return_true' );
		unset( $_SERVER['PHP_AUTH_USER'] );
		parent::tearDown();
	}

	/**
	 * Filter callback that snapshots the most recent SimpleUserLogger log write.
	 *
	 * @param array{0:array,1:array} $data_and_context [$data, $context] tuple.
	 * @param mixed                  $instance         Logger instance writing the row.
	 * @return array{0:array,1:array} Unchanged — we only observe.
	 */
	public function capture_log_write( $data_and_context, $instance ) {
		if ( $instance instanceof User_Logger ) {
			$this->captured_log = $data_and_context;
		}

		return $data_and_context;
	}

	/**
	 * XML-RPC path. The username arrives via the `authenticate` filter chain
	 * (our capture at priority 19, just before WP's wp_authenticate_application_password
	 * at 20). PHP_AUTH_USER is empty for XML-RPC requests.
	 *
	 * This is the path that was broken before the fix — it logged an empty username
	 * because the original code only read from PHP_AUTH_USER.
	 */
	public function test_xmlrpc_path_logs_username_from_authenticate_filter() {
		// Simulate WP's authenticate filter chain running with the XML-RPC body's username.
		$this->logger->capture_attempted_username( null, 'xmlrpc_target_user', 'wrong-password' );

		// PHP_AUTH_USER stays unset — XML-RPC never populates it.
		$this->assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER );

		$error = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log, 'Expected the logger to write a row.' );

		[ $data, $context ] = $this->captured_log;

		$this->assertSame( 'SimpleUserLogger', $data['logger'] );
		$this->assertSame( 'warning', $data['level'] );
		$this->assertSame( 'user_application_password_login_failed', $context['_message_key'] );
		$this->assertSame( 'xmlrpc_target_user', $context['login'] );
		$this->assertSame( 'incorrect_password', $context['error_code'] );
	}

	/**
	 * REST API path. WP's `wp_validate_application_password` (hooked on
	 * `determine_current_user`) calls `wp_authenticate_application_password()` directly,
	 * skipping the `authenticate` filter chain — so our capture never runs.
	 * PHP_AUTH_USER is the only source for the attempted username in this path.
	 *
	 * No prior `capture_attempted_username()` call in this test on purpose: that
	 * matches the actual REST production flow.
	 */
	public function test_rest_path_falls_back_to_php_auth_user() {
		// REST production flow: PHP_AUTH_USER set by the web server, capture filter
		// never ran (last_attempted_username remains null from setUp).
		$_SERVER['PHP_AUTH_USER'] = 'rest_target_user';

		$error = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log, 'Expected the logger to write a row.' );

		[ , $context ] = $this->captured_log;

		$this->assertSame( 'user_application_password_login_failed', $context['_message_key'] );
		$this->assertSame( 'rest_target_user', $context['login'] );
		$this->assertSame( 'incorrect_password', $context['error_code'] );
	}

	/**
	 * Unknown-user branch via XML-RPC: error code `invalid_username` should log
	 * the attempted username under `failed_username` (not `login`).
	 */
	public function test_unknown_user_branch_xmlrpc() {
		$this->logger->capture_attempted_username( null, 'no_such_user', 'wrong-password' );

		$error = new WP_Error( 'invalid_username', 'Unknown username.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log );

		[ , $context ] = $this->captured_log;

		$this->assertSame( 'user_application_password_unknown_login_failed', $context['_message_key'] );
		$this->assertSame( 'no_such_user', $context['failed_username'] );
		$this->assertSame( 'invalid_username', $context['error_code'] );
	}

	/**
	 * Unknown-user branch via REST: same as above but with the username coming from
	 * PHP_AUTH_USER, since the REST path never triggers our capture filter.
	 */
	public function test_unknown_user_branch_rest() {
		$_SERVER['PHP_AUTH_USER'] = 'no_such_rest_user';

		$error = new WP_Error( 'invalid_username', 'Unknown username.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log );

		[ , $context ] = $this->captured_log;

		$this->assertSame( 'user_application_password_unknown_login_failed', $context['_message_key'] );
		$this->assertSame( 'no_such_rest_user', $context['failed_username'] );
	}

	/**
	 * Precedence: when the capture is set, it must win over PHP_AUTH_USER. The
	 * captured value is the more authoritative source (it's the exact `$username`
	 * passed to the authenticate filter); PHP_AUTH_USER is only the fallback
	 * for paths that bypass `wp_authenticate()`.
	 */
	public function test_captured_username_wins_over_php_auth_user() {
		$this->logger->capture_attempted_username( null, 'captured_user', 'wrong-password' );
		$_SERVER['PHP_AUTH_USER'] = 'php_auth_user_value';

		$error = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log );

		[ , $context ] = $this->captured_log;

		$this->assertSame( 'captured_user', $context['login'] );
	}

	/**
	 * Worst case: neither source available. Should still log the row (so the
	 * attack itself isn't invisible) with an empty `login`. The admin will see
	 * "for user """ — not ideal, but better than dropping the event entirely,
	 * and matches the pre-fix behavior for paths with no username source.
	 */
	public function test_logs_empty_username_when_no_source_available() {
		// last_attempted_username is null (setUp), PHP_AUTH_USER is unset (setUp).
		$error = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log );

		[ , $context ] = $this->captured_log;

		$this->assertSame( 'user_application_password_login_failed', $context['_message_key'] );
		$this->assertSame( '', $context['login'] );
	}

	/**
	 * Dedupe guard. WP fires the action twice per request (once via `authenticate`,
	 * once via `determine_current_user`); we only want one log row per request.
	 */
	public function test_recursion_guard_dedupes_second_call() {
		$this->logger->capture_attempted_username( null, 'dedupe_user', 'wrong-password' );

		$error = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNotNull( $this->captured_log, 'Expected the first call to write a row.' );

		// Reset capture so a second write would be visible.
		$this->captured_log = null;

		$this->logger->on_application_password_failed_authentication( $error );

		$this->assertNull( $this->captured_log, 'Second invocation must be guarded as a no-op.' );
	}

	/**
	 * Realistic combined path: an attacker brute-forces both XML-RPC and REST.
	 * The first action call comes from XML-RPC (capture set, PHP_AUTH_USER empty).
	 * After reset, the next request is REST (capture null, PHP_AUTH_USER set).
	 * Each must log the right username via its appropriate source.
	 */
	public function test_xmlrpc_then_rest_use_different_sources() {
		// First request: XML-RPC.
		$this->logger->capture_attempted_username( null, 'attacker_target_xmlrpc', 'wrong-password' );
		$error = new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
		$this->logger->on_application_password_failed_authentication( $error );

		[ , $first_context ] = $this->captured_log;
		$this->assertSame( 'attacker_target_xmlrpc', $first_context['login'] );

		// Simulate end-of-request: reset the per-request guards as setUp does.
		$reflection = new ReflectionClass( $this->logger );
		$captured = $reflection->getProperty( 'last_attempted_username' );
		$captured->setAccessible( true );
		$captured->setValue( $this->logger, null );
		$logged = $reflection->getProperty( 'app_password_failure_logged' );
		$logged->setAccessible( true );
		$logged->setValue( $this->logger, false );
		$this->captured_log = null;

		// Second request: REST API.
		$_SERVER['PHP_AUTH_USER'] = 'attacker_target_rest';
		$this->logger->on_application_password_failed_authentication( $error );

		[ , $second_context ] = $this->captured_log;
		$this->assertSame( 'attacker_target_rest', $second_context['login'] );
	}
}
