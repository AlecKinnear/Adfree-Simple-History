<?php

namespace Simple_History\Loggers;

use Simple_History\Helpers;

/**
 * Logs changes to API keys managed by the WordPress Connectors API (WP 7.0+).
 *
 * The Connectors API stores third-party service credentials (AI providers like
 * Anthropic/OpenAI/Google, anti-spam services like Akismet, etc.) as regular
 * options keyed under `connectors_*`. This logger tracks add/update/remove of
 * those credentials so site owners have an audit trail of who connected, changed,
 * or disconnected an external service — without ever storing the key value.
 */
class Connectors_Logger extends Logger {
	/** @var string Logger slug, stored in the database. */
	public $slug = 'ConnectorsLogger';

	/**
	 * Connector metadata keyed by setting_name, populated when hooks are bound.
	 *
	 * @var array<string, array{id: string, data: array}>
	 */
	protected $connectors_by_setting = array();

	/**
	 * Option values captured in the `delete_option` action, before the DB row
	 * is removed. Read in `deleted_option` so we have the pre-delete value to log.
	 *
	 * @var array<string, mixed>
	 */
	protected $pre_delete_values = array();

	/**
	 * Return logger info.
	 *
	 * @return array
	 */
	public function get_info() {
		return array(
			'name'        => _x( 'Connectors Logger', 'ConnectorsLogger', 'simple-history' ),
			'description' => __( 'Logs when API keys for third-party connectors (AI providers, anti-spam, etc.) are added, changed, or removed.', 'simple-history' ),
			'capability'  => 'manage_options',
			'messages'    => array(
				'connector_api_key_added'   => __( 'Added API key for connector "{connector_name}"', 'simple-history' ),
				'connector_api_key_updated' => __( 'Updated API key for connector "{connector_name}"', 'simple-history' ),
				'connector_api_key_removed' => __( 'Removed API key for connector "{connector_name}"', 'simple-history' ),
			),
			'labels'      => array(
				'search' => array(
					'label'     => _x( 'Connectors', 'Connectors logger: search', 'simple-history' ),
					'label_all' => _x( 'All connector changes', 'Connectors logger: search', 'simple-history' ),
					'options'   => array(
						_x( 'Added API key', 'Connectors logger: search', 'simple-history' )   => array(
							'connector_api_key_added',
						),
						_x( 'Updated API key', 'Connectors logger: search', 'simple-history' ) => array(
							'connector_api_key_updated',
						),
						_x( 'Removed API key', 'Connectors logger: search', 'simple-history' ) => array(
							'connector_api_key_removed',
						),
					),
				),
			),
		);
	}

	/**
	 * Hook into WordPress.
	 *
	 * Defer until `init` priority 30, after core registers default connector
	 * settings (priority 20) so `wp_get_connectors()` returns the full set.
	 *
	 * Trade-off: any connector registered at `init` priority 31+ is invisible
	 * to this snapshot and won't have its API key changes logged. In practice
	 * connectors register on the earlier `wp_connectors_init` action (which
	 * fires during core's priority-20 hook), so this is rarely an issue —
	 * but note the constraint when third-party connectors misbehave.
	 */
	public function loaded() {
		add_action( 'init', array( $this, 'register_connector_hooks' ), 30 );
	}

	/**
	 * Bind add/update/delete option hooks for each registered connector's API key.
	 *
	 * Add and update hooks are bound per-setting via the dynamic `add_option_*`
	 * and `update_option_*` action names. Deletion needs two passes: the
	 * `delete_option` action fires *before* the row is removed (so we can read
	 * the pre-delete value), and `deleted_option` fires *after* a successful
	 * delete (so we only log when the delete actually happened). Both are
	 * registered once and dispatch by looking up the option name in
	 * `$this->connectors_by_setting`.
	 */
	public function register_connector_hooks() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return;
		}

		foreach ( wp_get_connectors() as $connector_id => $connector_data ) {
			$auth = $connector_data['authentication'] ?? array();

			if ( empty( $auth['method'] ) || $auth['method'] !== 'api_key' || empty( $auth['setting_name'] ) ) {
				continue;
			}

			$setting_name = $auth['setting_name'];

			// First connector to claim a setting name wins. Two connectors
			// sharing one setting would otherwise produce duplicate events.
			if ( isset( $this->connectors_by_setting[ $setting_name ] ) ) {
				continue;
			}

			$this->connectors_by_setting[ $setting_name ] = array(
				'id'   => $connector_id,
				'data' => $connector_data,
			);

			add_action(
				"add_option_{$setting_name}",
				function ( $option, $value ) use ( $connector_id, $connector_data ) {
					$this->on_connector_option_added( $connector_id, $connector_data, $value );
				},
				10,
				2
			);

			add_action(
				"update_option_{$setting_name}",
				function ( $old_value, $new_value ) use ( $connector_id, $connector_data ) {
					$this->on_connector_option_updated( $connector_id, $connector_data, $old_value, $new_value );
				},
				10,
				2
			);
		}

		if ( empty( $this->connectors_by_setting ) ) {
			return;
		}

		add_action( 'delete_option', array( $this, 'capture_value_before_delete' ) );
		add_action( 'deleted_option', array( $this, 'handle_deleted_option' ) );
	}

	/**
	 * Stash a connector setting's value before WordPress deletes it.
	 *
	 * Fired by the global `delete_option` action, which runs *before* the
	 * DB delete. Only stashes values for setting names that belong to a
	 * registered connector.
	 *
	 * @param string $option Option name about to be deleted.
	 */
	public function capture_value_before_delete( $option ) {
		if ( ! isset( $this->connectors_by_setting[ $option ] ) ) {
			return;
		}

		$this->pre_delete_values[ $option ] = get_option( $option, '' );
	}

	/**
	 * Log a connector option deletion using the value stashed in `capture_value_before_delete`.
	 *
	 * Fired by the global `deleted_option` action, which only runs after a
	 * successful `$wpdb->delete()` — so any log entry here corresponds to a real removal.
	 *
	 * @param string $option Option name that was deleted.
	 */
	public function handle_deleted_option( $option ) {
		if ( ! isset( $this->connectors_by_setting[ $option ] ) ) {
			return;
		}

		$old_value = $this->pre_delete_values[ $option ] ?? '';
		unset( $this->pre_delete_values[ $option ] );

		$connector_info = $this->connectors_by_setting[ $option ];
		$this->on_connector_option_deleted( $connector_info['id'], $connector_info['data'], $old_value );
	}

	/**
	 * Handle a brand-new API key being stored for a connector.
	 *
	 * @param string $connector_id   Connector identifier (e.g. "anthropic").
	 * @param array  $connector_data Connector data from wp_get_connectors().
	 * @param mixed  $value          New option value.
	 */
	public function on_connector_option_added( $connector_id, $connector_data, $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return;
		}

		$context = $this->build_base_context( $connector_id, $connector_data );

		$context['api_key_new_last_4'] = Helpers::mask_secret( $value );

		$this->info_message( 'connector_api_key_added', $context );
	}

	/**
	 * Handle a connector API key being updated.
	 *
	 * Treats empty → non-empty as an add and non-empty → empty as a removal so
	 * we report what the admin actually did, not which WP hook fired.
	 *
	 * @param string $connector_id   Connector identifier.
	 * @param array  $connector_data Connector data from wp_get_connectors().
	 * @param mixed  $old_value      Previous option value.
	 * @param mixed  $new_value      New option value.
	 */
	public function on_connector_option_updated( $connector_id, $connector_data, $old_value, $new_value ) {
		$old_string = is_string( $old_value ) ? $old_value : '';
		$new_string = is_string( $new_value ) ? $new_value : '';

		if ( $old_string === $new_string ) {
			return;
		}

		$context = $this->build_base_context( $connector_id, $connector_data );

		if ( $old_string === '' && $new_string !== '' ) {
			$context['api_key_new_last_4'] = Helpers::mask_secret( $new_string );
			$this->info_message( 'connector_api_key_added', $context );
			return;
		}

		if ( $old_string !== '' && $new_string === '' ) {
			$context['api_key_prev_last_4'] = Helpers::mask_secret( $old_string );
			$this->warning_message( 'connector_api_key_removed', $context );
			return;
		}

		$context['api_key_prev_last_4'] = Helpers::mask_secret( $old_string );
		$context['api_key_new_last_4']  = Helpers::mask_secret( $new_string );

		$this->notice_message( 'connector_api_key_updated', $context );
	}

	/**
	 * Handle a connector option being deleted entirely.
	 *
	 * @param string $connector_id   Connector identifier.
	 * @param array  $connector_data Connector data from wp_get_connectors().
	 * @param mixed  $old_value      Value before deletion.
	 */
	public function on_connector_option_deleted( $connector_id, $connector_data, $old_value ) {
		if ( ! is_string( $old_value ) || $old_value === '' ) {
			return;
		}

		$context = $this->build_base_context( $connector_id, $connector_data );

		$context['api_key_prev_last_4'] = Helpers::mask_secret( $old_value );

		$this->warning_message( 'connector_api_key_removed', $context );
	}

	/**
	 * Build the shared context (connector identity + setting metadata).
	 *
	 * @param string $connector_id   Connector identifier.
	 * @param array  $connector_data Connector data from wp_get_connectors().
	 * @return array
	 */
	protected function build_base_context( $connector_id, $connector_data ) {
		return array(
			'connector_id'           => $connector_id,
			'connector_name'         => $connector_data['name'] ?? $connector_id,
			'connector_type'         => $connector_data['type'] ?? '',
			'connector_setting_name' => $connector_data['authentication']['setting_name'] ?? '',
		);
	}
}
