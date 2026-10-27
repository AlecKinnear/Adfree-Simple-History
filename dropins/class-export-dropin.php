<?php
namespace Simple_History\Dropins;

use Simple_History\Simple_History;
use Simple_History\Export;
use Simple_History\Helpers;
use Simple_History\Log_Initiators;
use Simple_History\Log_Levels;
use Simple_History\Menu_Page;
use Simple_History\Services\Admin_Pages;

/**
 * Dropin Name: Export
 * Dropin Description: Adds a tab with export options under the Tools menu
 * Dropin URI: https://simple-history.com/
 * Author: Pär Thernström
 */
class Export_Dropin extends Dropin {
	/** @var string Slug for the export menu tab. */
	const MENU_SLUG = 'simple_history_tools_export';

	/**
	 * Admin-post action and nonce for exporting the log page's current filters.
	 *
	 * @var string
	 */
	const FILTERED_EXPORT_ACTION = 'simple_history_export_filtered';

	/** @var int Maximum accepted length of the posted query JSON. */
	const FILTERED_EXPORT_JSON_MAX_BYTES = 20000;

	/**
	 * @inheritdoc
	 */
	public function loaded() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'download_export' ) );
		add_action( 'admin_post_' . self::FILTERED_EXPORT_ACTION, array( $this, 'download_filtered_export' ) );
		add_action( 'simple_history/enqueue_admin_scripts', array( $this, 'enqueue_filtered_export_data' ), 20 );
	}

	/**
	 * Add export subtab under Tools > Tools tab.
	 */
	public function add_menu() {
		if ( ! Helpers::setting_show_as_menu_page() ) {
			return;
		}

		$admin_page_location = Helpers::get_menu_page_location();

		// Determine parent based on location.
		// When location is 'top' or 'bottom', use the Tools main tab object.
		// When inside dashboard/tools, use the Tools menu page slug directly.
		if ( in_array( $admin_page_location, [ 'top', 'bottom' ], true ) ) {
			$tools_parent = Tools_Menu_Dropin::get_tools_main_tab();
			if ( ! $tools_parent ) {
				$tools_parent = Tools_Menu_Dropin::TOOLS_TAB_SLUG;
			}
		} else {
			$tools_parent = Tools_Menu_Dropin::MENU_SLUG;
		}

		( new Menu_Page() )
			->set_page_title( _x( 'Export History', 'export subtab title', 'simple-history' ) )
			->set_menu_title( _x( 'Export', 'export subtab name', 'simple-history' ) )
			->set_menu_slug( self::MENU_SLUG )
			->set_callback( [ $this, 'output_export_page' ] )
			->set_order( 2 )
			->set_parent( $tools_parent )
			->add();
	}

	/**
	 * Download export file.
	 */
	public function download_export() {
		$page   = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$action = sanitize_key( wp_unslash( $_POST['simple-history-action'] ?? '' ) );

		// Bail if not correct page.
		// Check for the tools page, export tab, and settings page (when tools is shown as a tab).
		// Examples:
		// - Main menu: http://example.com/wp-admin/admin.php?page=simple_history_tools&selected-tab=simple_history_tools_export
		// - Settings tab: http://example.com/wp-admin/options-general.php?page=simple_history_settings_page&selected-tab=simple_history_tools.
		if ( $page !== Tools_Menu_Dropin::MENU_SLUG && $page !== Simple_History::SETTINGS_MENU_PAGE_SLUG ) {
			return;
		}

		// Bail if not export action.
		if ( $action !== 'export-history' ) {
			return;
		}

		// Will die if nonce not valid.
		check_admin_referer( self::class . '-action-export' );

		$export_format = sanitize_text_field( wp_unslash( $_POST['format'] ?? 'json' ) );

		$csv_include_headers = isset( $_POST['csv_include_headers'] );

		$export = new Export();
		$export->set_query_args(
			[
				'paged'          => 1,
				// 3000 is batch size.
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- This is rarely used and only used on demand.
				'posts_per_page' => 3000,
			]
		);
		$export->set_download_format( $export_format );
		$export->set_options(
			[
				'include_headers' => $csv_include_headers,
			]
		);
		$export->download();
	}

	/**
	 * Output for the export tab on the settings page.
	 */
	public function output_export_page() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Admin_Pages::header_output();
		?>

		<div class="wrap">
			<?php
			echo wp_kses(
				Helpers::get_settings_section_title_output(
					__( 'Export history', 'simple-history' ),
					'download'
				),
				array(
					'span' => array(
						'class' => array(),
					),
				)
			);
			?>
			
			<p><?php echo esc_html_x( 'The export function will export the full history.', 'Export dropin: introtext', 'simple-history' ); ?></p>

			<form method="post">

				<h3>
					<?php echo esc_html_x( 'Choose format to export to', 'Export dropin: format', 'simple-history' ); ?>
				</h3>

				<p>
					<label>
						<input type="radio" name="format" value="csv" checked>
						<?php echo esc_html_x( 'CSV', 'Export dropin: export format', 'simple-history' ); ?>
					</label>
				</p>

				<p style="margin-left: 1rem;">
					<label>
						<input type="checkbox" name="csv_include_headers" value="1">
						<?php echo esc_html_x( 'Include headers', 'Export dropin: include headers', 'simple-history' ); ?>
					</label>
				</p>

				<p>
					<label>
						<input type="radio" name="format" value="json">
						<?php echo esc_html_x( 'JSON', 'Export dropin: export format', 'simple-history' ); ?>
					</label>
				</p>

				<p>
					<label>
						<input type="radio" name="format" value="html">
						<?php echo esc_html_x( 'HTML', 'Export dropin: export format', 'simple-history' ); ?>
					</label>
				</p>

				<p>
					<button type="submit" class="button button-primary">
						<?php echo esc_html_x( 'Download Export File', 'Export dropin: submit button', 'simple-history' ); ?>
					</button>
					<input type="hidden" name="simple-history-action" value="export-history">
				</p>

				<?php
				wp_nonce_field( self::class . '-action-export' );
				?>

			</form>
		
		</div>
		<?php
	}

	/**
	 * Pass the filtered-export URL and nonce to the log page script.
	 *
	 * Runs after the React bundle is registered.
	 */
	public function enqueue_filtered_export_data() {
		if ( ! wp_script_is( 'simple_history_wp_scripts', 'registered' ) ) {
			return;
		}

		wp_localize_script(
			'simple_history_wp_scripts',
			'simpleHistoryFilteredExport',
			array(
				'url'    => admin_url( 'admin-post.php' ),
				'action' => self::FILTERED_EXPORT_ACTION,
				'nonce'  => wp_create_nonce( self::FILTERED_EXPORT_ACTION ),
			)
		);
	}

	/**
	 * Download a CSV of the events matching the log page's current filters.
	 *
	 * The Tools page export is a separate request and is left unchanged.
	 */
	public function download_filtered_export() {
		if ( ! current_user_can( Helpers::get_view_history_capability() ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to export events.', 'simple-history' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::FILTERED_EXPORT_ACTION );

		$query_args = self::sanitize_filtered_query_args( self::posted_query_args() );

		$export = new Export();
		$export->set_query_args( $query_args );
		$export->set_download_format( 'csv' );
		$export->set_options(
			array(
				'include_headers' => true,
			)
		);
		$export->download();
	}

	/**
	 * Decode the posted filter JSON.
	 *
	 * Invalid JSON is rejected. An empty object is a valid "no extra filters" export.
	 *
	 * @return array<string,mixed>
	 */
	private static function posted_query_args() {
		check_admin_referer( self::FILTERED_EXPORT_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded, then each field is sanitized in sanitize_filtered_query_args().
		$query_args_json = isset( $_POST['query_args'] ) ? wp_unslash( $_POST['query_args'] ) : '';

		if ( ! is_string( $query_args_json ) || strlen( $query_args_json ) > self::FILTERED_EXPORT_JSON_MAX_BYTES ) {
			self::reject_filtered_export_request();
		}

		$decoded = json_decode( $query_args_json, true );

		if ( ! is_array( $decoded ) ) {
			self::reject_filtered_export_request();
		}

		return $decoded;
	}

	/**
	 * Stop a filtered export whose posted filters could not be read.
	 *
	 * @return never
	 */
	private static function reject_filtered_export_request() {
		wp_die(
			esc_html__( 'The export request was not valid.', 'simple-history' ),
			'',
			array( 'response' => 400 )
		);
	}

	/**
	 * Keep only the filter arguments Log_Query understands, and only in safe shapes.
	 *
	 * Pagination, field lists, and the surrounding-events query are dropped.
	 * The export walks every matching page itself.
	 *
	 * @param array<string,mixed> $raw Posted query arguments.
	 * @return array<string,mixed> Arguments for Export::set_query_args().
	 */
	public static function sanitize_filtered_query_args( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$args = array();

		foreach ( array( 'search', 'exclude_search', 'metadata_search' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( $raw[ $key ] );
			if ( $value !== '' && strlen( $value ) <= 500 ) {
				$args[ $key ] = $value;
			}
		}

		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( $raw[ $key ] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$args[ $key ] = $value;
			}
		}

		if ( isset( $raw['dates'] ) && is_string( $raw['dates'] ) && self::is_allowed_dates_filter( sanitize_text_field( $raw['dates'] ) ) ) {
			$args['dates'] = sanitize_text_field( $raw['dates'] );
		}

		if ( isset( $raw['ip_address'] ) && is_string( $raw['ip_address'] ) ) {
			$ip_address = sanitize_text_field( $raw['ip_address'] );
			if ( Helpers::is_valid_ip_address_filter( $ip_address ) ) {
				$args['ip_address'] = $ip_address;
			}
		}

		foreach ( array( 'loglevels', 'exclude_loglevels' ) as $key ) {
			$levels = array_values(
				array_filter(
					self::sanitize_string_list( $raw[ $key ] ?? null ),
					array( Log_Levels::class, 'is_valid_level' )
				)
			);
			if ( $levels ) {
				$args[ $key ] = $levels;
			}
		}

		foreach ( array( 'loggers', 'exclude_loggers' ) as $key ) {
			$loggers = array();
			foreach ( self::sanitize_string_list( $raw[ $key ] ?? null ) as $logger_slug ) {
				if ( self::is_logger_slug( $logger_slug ) ) {
					$loggers[] = $logger_slug;
				}
			}
			if ( $loggers ) {
				$args[ $key ] = $loggers;
			}
		}

		foreach ( array( 'messages', 'exclude_messages' ) as $key ) {
			$messages = self::sanitize_message_filters( $raw[ $key ] ?? null );
			if ( $messages ) {
				$args[ $key ] = $messages;
			}
		}

		foreach ( array( 'users', 'exclude_users' ) as $key ) {
			$user_ids = self::sanitize_id_list( $raw[ $key ] ?? null );
			if ( $user_ids ) {
				$args[ $key ] = $user_ids;
			}
		}

		foreach ( array( 'user', 'exclude_user' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
				continue;
			}

			$user_id = absint( $raw[ $key ] );
			if ( $user_id ) {
				$args[ $key ] = $user_id;
			}
		}

		$valid_initiators = Log_Initiators::get_valid_initiators();
		foreach ( array( 'initiator', 'exclude_initiator' ) as $key ) {
			$initiators = array_values(
				array_filter(
					self::sanitize_string_list( $raw[ $key ] ?? null ),
					static function ( $initiator ) use ( $valid_initiators ) {
						return in_array( $initiator, $valid_initiators, true );
					}
				)
			);
			if ( $initiators ) {
				$args[ $key ] = $initiators;
			}
		}

		foreach ( array( 'include_sticky', 'ai_only' ) as $key ) {
			if ( self::is_truthy_flag( $raw[ $key ] ?? null ) ) {
				$args[ $key ] = true;
			}
		}

		if ( isset( $raw['context_filters'] ) ) {
			$context_filters = self::sanitize_context_filters( $raw['context_filters'] );
			if ( $context_filters ) {
				$args['context_filters'] = $context_filters;
			}
		}

		return $args;
	}

	/**
	 * Date presets the log page actually sends.
	 *
	 * @param string $dates One dates value, such as "lastdays:30" or "month:2026-04".
	 * @return bool
	 */
	private static function is_allowed_dates_filter( $dates ) {
		if ( $dates === 'yesterday' || $dates === 'allDates' ) {
			return true;
		}

		if ( preg_match( '/^lastdays:(\d+)$/', $dates, $matches ) ) {
			$days = (int) $matches[1];
			return $days >= 1 && $days <= 3650;
		}

		if ( preg_match( '/^month:(\d{4})-(\d{2})$/', $dates, $matches ) ) {
			$month = (int) $matches[2];
			return $month >= 1 && $month <= 12;
		}

		return false;
	}

	/**
	 * @param mixed $value A string, or a list of strings.
	 * @return string[]
	 */
	private static function sanitize_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = sanitize_text_field( (string) $item );
			if ( $item === '' ) {
				continue;
			}

			$items[] = $item;
			if ( count( $items ) >= 100 ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * @param mixed $value Comma-separated "LoggerSlug:message_key" string, or a list of them.
	 * @return string[]
	 */
	private static function sanitize_message_filters( $value ) {
		$messages = array();
		foreach ( self::sanitize_string_list( $value ) as $item ) {
			if ( preg_match( '/^[A-Za-z0-9_]+:[A-Za-z0-9_-]+$/', $item ) ) {
				$messages[] = $item;
			}
		}

		return $messages;
	}

	/**
	 * @param mixed $value A user id, or a list of user ids.
	 * @return int[]
	 */
	private static function sanitize_id_list( $value ) {
		if ( is_numeric( $value ) ) {
			$value = array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( $value as $item ) {
			if ( ! is_numeric( $item ) ) {
				continue;
			}

			$id = absint( $item );
			if ( ! $id ) {
				continue;
			}

			$ids[] = $id;
			if ( count( $ids ) >= 100 ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * @param mixed $value Context filters object.
	 * @return array<string,string>
	 */
	private static function sanitize_context_filters( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$filters = array();
		foreach ( $value as $key => $item ) {
			if ( count( $filters ) >= 20 || ! is_string( $key ) || ! is_scalar( $item ) ) {
				continue;
			}

			$key  = sanitize_text_field( $key );
			$item = sanitize_text_field( (string) $item );
			if ( $key === '' || $item === '' || strlen( $key ) > 100 || strlen( $item ) > 200 ) {
				continue;
			}

			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
				continue;
			}

			$filters[ $key ] = $item;
		}

		return $filters;
	}

	/**
	 * @param string $slug Logger slug.
	 * @return bool
	 */
	private static function is_logger_slug( $slug ) {
		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $slug );
	}

	/**
	 * @param mixed $value Flag from JSON.
	 * @return bool
	 */
	private static function is_truthy_flag( $value ) {
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}
}
