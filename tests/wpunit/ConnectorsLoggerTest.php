<?php

require_once 'functions.php';

use Simple_History\Simple_History;
use Simple_History\Loggers\Connectors_Logger;
use function Simple_History\tests\get_latest_row;
use function Simple_History\tests\get_latest_context;

/**
 * Test Connectors Logger functionality.
 *
 * Run with:
 * docker compose run --rm php-cli vendor/bin/codecept run wpunit ConnectorsLoggerTest
 */
class ConnectorsLoggerTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * @var Simple_History
	 */
	private $sh;

	/**
	 * @var Connectors_Logger
	 */
	private $logger;

	/**
	 * Fake connector data used to drive the logger handlers directly.
	 *
	 * @var array
	 */
	private $fake_connector = array(
		'name'           => 'Anthropic',
		'description'    => 'Text generation with Claude.',
		'type'           => 'ai_provider',
		'authentication' => array(
			'method'       => 'api_key',
			'setting_name' => 'connectors_ai_anthropic_api_key',
		),
	);

	public function setUp(): void {
		parent::setUp();

		$this->sh     = Simple_History::get_instance();
		$this->logger = $this->sh->get_instantiated_logger_by_slug( 'ConnectorsLogger' );

		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
	}

	public function test_logger_exists_and_is_loaded() {
		$this->assertNotNull( $this->logger, 'Connectors Logger should be instantiated' );
		$this->assertInstanceOf( Connectors_Logger::class, $this->logger );
		$this->assertEquals( 'ConnectorsLogger', $this->logger->get_slug() );
	}

	public function test_logger_info_shape() {
		$info = $this->logger->get_info();

		$this->assertIsArray( $info );
		$this->assertArrayHasKey( 'name', $info );
		$this->assertArrayHasKey( 'description', $info );
		$this->assertEquals( 'manage_options', $info['capability'] );

		$this->assertArrayHasKey( 'connector_api_key_added', $info['messages'] );
		$this->assertArrayHasKey( 'connector_api_key_updated', $info['messages'] );
		$this->assertArrayHasKey( 'connector_api_key_removed', $info['messages'] );
	}

	public function test_logs_when_api_key_is_added() {
		$this->logger->on_connector_option_added( 'anthropic', $this->fake_connector, 'sk-ant-test-abcd1234EFGH' );

		$row = get_latest_row();
		$this->assertEquals( 'ConnectorsLogger', $row['logger'] );
		$this->assertEquals( 'info', $row['level'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_added' );
		$this->assert_context_has( $context, 'connector_id', 'anthropic' );
		$this->assert_context_has( $context, 'connector_name', 'Anthropic' );
		$this->assert_context_has( $context, 'connector_type', 'ai_provider' );
		$this->assert_context_has( $context, 'connector_setting_name', 'connectors_ai_anthropic_api_key' );
		$this->assert_context_has( $context, 'api_key_new_last_4', 'EFGH' );

		$this->assert_context_does_not_contain_full_key( $context, 'sk-ant-test-abcd1234EFGH' );
	}

	public function test_logs_when_api_key_is_updated() {
		$this->logger->on_connector_option_updated( 'anthropic', $this->fake_connector, 'old-key-ABCD', 'new-key-WXYZ' );

		$row = get_latest_row();
		$this->assertEquals( 'ConnectorsLogger', $row['logger'] );
		$this->assertEquals( 'notice', $row['level'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_updated' );
		$this->assert_context_has( $context, 'api_key_prev_last_4', 'ABCD' );
		$this->assert_context_has( $context, 'api_key_new_last_4', 'WXYZ' );

		$this->assert_context_does_not_contain_full_key( $context, 'old-key-ABCD' );
		$this->assert_context_does_not_contain_full_key( $context, 'new-key-WXYZ' );
	}

	public function test_logs_when_api_key_is_removed_via_delete() {
		$this->logger->on_connector_option_deleted( 'anthropic', $this->fake_connector, 'sk-ant-test-ZZZZ' );

		$row = get_latest_row();
		$this->assertEquals( 'ConnectorsLogger', $row['logger'] );
		$this->assertEquals( 'warning', $row['level'] );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_removed' );
		$this->assert_context_has( $context, 'api_key_prev_last_4', 'ZZZZ' );
	}

	public function test_update_from_empty_logs_as_added() {
		$this->logger->on_connector_option_updated( 'anthropic', $this->fake_connector, '', 'fresh-key-1234' );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_added' );
		$this->assert_context_has( $context, 'api_key_new_last_4', '1234' );
	}

	public function test_update_to_empty_logs_as_removed() {
		$this->logger->on_connector_option_updated( 'anthropic', $this->fake_connector, 'expiring-key-9876', '' );

		$row     = get_latest_row();
		$context = get_latest_context();

		$this->assertEquals( 'warning', $row['level'] );
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_removed' );
		$this->assert_context_has( $context, 'api_key_prev_last_4', '9876' );
	}

	public function test_no_log_when_value_unchanged() {
		global $wpdb;
		$db_table = $this->sh->get_events_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table}" );

		$this->logger->on_connector_option_updated( 'anthropic', $this->fake_connector, 'same-key', 'same-key' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table}" );

		$this->assertEquals( $count_before, $count_after, 'No log entry should be created when value is unchanged' );
	}

	public function test_no_log_when_empty_value_added() {
		global $wpdb;
		$db_table = $this->sh->get_events_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table}" );

		$this->logger->on_connector_option_added( 'anthropic', $this->fake_connector, '' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table}" );

		$this->assertEquals( $count_before, $count_after, 'No log entry should be created for empty initial value' );
	}

	/**
	 * Short keys (≤ 4 chars) must be masked entirely, not stored verbatim.
	 */
	public function test_short_key_is_masked_not_leaked() {
		$this->logger->on_connector_option_added( 'anthropic', $this->fake_connector, 'abcd' );

		$context = get_latest_context();

		$this->assert_context_has( $context, 'api_key_new_last_4', '****' );
		$this->assert_context_does_not_contain_full_key( $context, 'abcd' );
	}

	/**
	 * Full delete flow: simulate the captured pre-value and run the deleted_option handler.
	 * Regression test — earlier version used `delete_option_{$option}` which fires AFTER
	 * the row is gone, so `get_option()` returned an empty default and we silently logged nothing.
	 */
	public function test_handle_deleted_option_logs_via_global_hook_pair() {
		$setting_name = $this->fake_connector['authentication']['setting_name'];

		$reflection                    = new ReflectionClass( Connectors_Logger::class );
		$connectors_by_setting_prop    = $reflection->getProperty( 'connectors_by_setting' );
		$pre_delete_values_prop        = $reflection->getProperty( 'pre_delete_values' );
		$connectors_by_setting_prop->setAccessible( true );
		$pre_delete_values_prop->setAccessible( true );

		$connectors_by_setting_prop->setValue(
			$this->logger,
			array(
				$setting_name => array(
					'id'   => 'anthropic',
					'data' => $this->fake_connector,
				),
			)
		);
		$pre_delete_values_prop->setValue( $this->logger, array( $setting_name => 'sk-ant-test-DELE' ) );

		$this->logger->handle_deleted_option( $setting_name );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_removed' );
		$this->assert_context_has( $context, 'api_key_prev_last_4', 'DELE' );
		$this->assert_context_does_not_contain_full_key( $context, 'sk-ant-test-DELE' );

		// The stash entry should have been consumed.
		$this->assertSame( array(), $pre_delete_values_prop->getValue( $this->logger ) );
	}

	/**
	 * Real end-to-end delete via WordPress: ensure that `delete_option()` on a
	 * connector setting produces a log entry. This is the path the reviewer
	 * flagged as broken in the original implementation.
	 */
	public function test_delete_option_on_connector_setting_logs_removal() {
		$setting_name = $this->fake_connector['authentication']['setting_name'];

		$reflection                 = new ReflectionClass( Connectors_Logger::class );
		$connectors_by_setting_prop = $reflection->getProperty( 'connectors_by_setting' );
		$connectors_by_setting_prop->setAccessible( true );
		$connectors_by_setting_prop->setValue(
			$this->logger,
			array(
				$setting_name => array(
					'id'   => 'anthropic',
					'data' => $this->fake_connector,
				),
			)
		);

		// Register the global delete hooks (mirrors what register_connector_hooks does).
		remove_action( 'delete_option', array( $this->logger, 'capture_value_before_delete' ) );
		remove_action( 'deleted_option', array( $this->logger, 'handle_deleted_option' ) );
		add_action( 'delete_option', array( $this->logger, 'capture_value_before_delete' ) );
		add_action( 'deleted_option', array( $this->logger, 'handle_deleted_option' ) );

		add_option( $setting_name, 'sk-ant-real-DELL' );

		delete_option( $setting_name );

		$context = get_latest_context();
		$this->assert_context_has( $context, '_message_key', 'connector_api_key_removed' );
		$this->assert_context_has( $context, 'api_key_prev_last_4', 'DELL' );
	}

	/**
	 * Assert that a key/value pair exists in the context array.
	 *
	 * @param array  $context Context rows from get_latest_context().
	 * @param string $key     Context key.
	 * @param string $value   Expected value.
	 */
	private function assert_context_has( array $context, string $key, string $value ): void {
		$this->assertContains(
			array( 'key' => $key, 'value' => $value ),
			$context,
			sprintf( 'Context should contain %s=%s', $key, $value )
		);
	}

	/**
	 * Ensure no context value contains the full API key.
	 *
	 * @param array  $context  Context rows.
	 * @param string $full_key The full key value that must NOT appear.
	 */
	private function assert_context_does_not_contain_full_key( array $context, string $full_key ): void {
		foreach ( $context as $row ) {
			$this->assertNotEquals(
				$full_key,
				$row['value'] ?? '',
				sprintf( 'Context key "%s" should not contain the full API key', $row['key'] ?? '' )
			);
		}
	}
}
