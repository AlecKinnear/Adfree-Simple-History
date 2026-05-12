<?php

namespace Simple_History\Loggers;

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
	 */
	public function loaded() {
		add_action( 'init', array( $this, 'register_connector_hooks' ), 30 );
	}

	/**
	 * Bind add/update/delete option hooks for each registered connector's API key.
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

			add_action(
				"delete_option_{$setting_name}",
				function () use ( $connector_id, $connector_data, $setting_name ) {
					$old_value = get_option( $setting_name, '' );
					$this->on_connector_option_deleted( $connector_id, $connector_data, $old_value );
				}
			);
		}
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

		$context['api_key_new_last_4'] = $this->last_4( $value );

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
			$context['api_key_new_last_4'] = $this->last_4( $new_string );
			$this->info_message( 'connector_api_key_added', $context );
			return;
		}

		if ( $old_string !== '' && $new_string === '' ) {
			$context['api_key_prev_last_4'] = $this->last_4( $old_string );
			$this->warning_message( 'connector_api_key_removed', $context );
			return;
		}

		$context['api_key_prev_last_4'] = $this->last_4( $old_string );
		$context['api_key_new_last_4']  = $this->last_4( $new_string );

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

		$context['api_key_prev_last_4'] = $this->last_4( $old_value );

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

	/**
	 * Returns the last four characters of an API key for low-risk identification.
	 *
	 * @param string $key API key value.
	 * @return string
	 */
	protected function last_4( $key ) {
		if ( strlen( $key ) <= 4 ) {
			return $key;
		}

		return substr( $key, -4 );
	}
}
