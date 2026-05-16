<?php

require_once 'functions.php';

use Simple_History\Simple_History;
use Simple_History\Loggers\Post_Logger;
use function Simple_History\tests\get_latest_row;
use function Simple_History\tests\get_latest_context;

/**
 * Tests that Post_Logger captures post changes made outside wp-admin:
 * REST API (already works via on_rest_after_insert) and WP-CLI.
 *
 * Issue #185: wp_update_post() via WP-CLI produces no event because
 * maybe_log_post_change() bails when is_admin() is false and no WP_CLI
 * branch exists. A second gap: save_prev_post_data() is never called for
 * WP-CLI, so diffs are empty even if the gate were lifted.
 *
 * Test ordering matters: the negative test and REST tests run BEFORE the
 * WP-CLI tests, because defining WP_CLI is irreversible within a process.
 *
 * Run with:
 * docker compose run --rm php-cli vendor/bin/codecept run wpunit PostLoggerRestCliTest
 */
class PostLoggerRestCliTest extends \Codeception\TestCase\WPTestCase {
	/** @var Simple_History */
	private $sh;

	/** @var Post_Logger */
	private $logger;

	/** @var int */
	private $admin_user_id;

	public function setUp(): void {
		parent::setUp();

		$this->sh     = Simple_History::get_instance();
		$this->logger = $this->sh->get_instantiated_logger_by_slug( 'SimplePostLogger' );

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	public function test_logger_exists_and_is_loaded() {
		$this->assertNotNull( $this->logger );
		$this->assertInstanceOf( Post_Logger::class, $this->logger );
	}

	/**
	 * Negative: a plain wp_update_post() from a non-admin, non-REST, non-CLI
	 * context (e.g. a cron task or plugin hook) must NOT produce a log row.
	 *
	 * Must run before any test that defines WP_CLI.
	 */
	public function test_does_not_log_post_update_from_arbitrary_context() {
		$this->assertFalse(
			defined( 'WP_CLI' ) && WP_CLI,
			'Negative test requires WP_CLI to be undefined'
		);

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$count_before = $this->get_event_count();

		// Plain wp_update_post() — no admin, no REST, no CLI.
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'arbitrary update' ) );

		$this->assertEquals(
			$count_before,
			$this->get_event_count(),
			'Post_Logger must not log plain wp_update_post() from a non-admin/REST/CLI context'
		);
	}

	/**
	 * Regression: REST API post updates already work via on_rest_after_insert.
	 * This test must stay green after the WP-CLI fix lands (no regressions).
	 *
	 * NOTE: rest_do_request() in the test environment does not define REST_REQUEST
	 * (that constant is only set during a real HTTP bootstrap). The Post Logger's
	 * REST path relies on REST_REQUEST, so we define it explicitly here.
	 * Once defined it cannot be undefined — this test must run before any test
	 * that expects REST_REQUEST to be absent.
	 */
	public function test_logs_post_update_via_rest_api() {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$post_id = $this->factory->post->create( array(
			'post_status'  => 'publish',
			'post_content' => 'original content',
		) );

		$count_before = $this->get_event_count();

		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$post_id}" );
		$request->set_param( 'content', 'content updated via REST' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'REST post update should succeed' );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'REST post update must produce exactly one log row'
		);

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'post_updated' );
	}

	/**
	 * WP-CLI: wp_update_post() with WP_CLI=true must produce a log row.
	 *
	 * Currently FAILS — maybe_log_post_change() bails at the is_admin() gate
	 * with no WP_CLI override branch.
	 */
	public function test_logs_post_update_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$count_before = $this->get_event_count();

		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'updated via wp-cli' ) );

		$this->assertEquals(
			$count_before + 1,
			$this->get_event_count(),
			'WP-CLI wp_update_post() must produce a post_updated log row'
		);

		$row = get_latest_row();
		$this->assertEquals( 'SimplePostLogger', $row['logger'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'post_updated' );
	}

	/**
	 * WP-CLI: the logged event must include a content diff (prev vs new).
	 *
	 * Currently FAILS — save_prev_post_data() is never called for WP-CLI, so
	 * old_post_data is absent and the diff is empty even if the gate is lifted.
	 */
	public function test_logs_post_update_includes_content_diff_via_wp_cli() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$original_content = 'original content before cli edit';
		$new_content      = 'new content after cli edit';

		$post_id = $this->factory->post->create( array(
			'post_status'  => 'publish',
			'post_content' => $original_content,
		) );

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );

		$context = get_latest_context();

		$context_keys = array_column( $context, 'key' );
		$this->assertContains(
			'post_prev_post_content',
			$context_keys,
			'post_prev_post_content must be present in context when content changes via WP-CLI'
		);
		$this->assertContains(
			'post_new_post_content',
			$context_keys,
			'post_new_post_content must be present in context when content changes via WP-CLI'
		);
	}

	private function get_event_count(): int {
		global $wpdb;
		$db_table = $this->sh->get_events_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$db_table} WHERE logger = %s",
				'SimplePostLogger'
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
