<?php

namespace Simple_History;

/**
 * Class to contain logic for exporting events.
 *
 * Used by core export module and export searched events feature in premium plugin.
 */
class Export {
	/** @var array $query_args Query arguments. Will be passed to log query class. */
	protected $query_args = [];

	/** @var string $format Export format. "json", "csv", or "html".  */
	protected $format = 'json';

	/** @var Simple_History $simple_history Simple History instance. */
	protected $simple_history;

	/** @var array $options Export options. */
	protected $options = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->simple_history = Simple_History::get_instance();
	}

	/**
	 * Set the query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return self Chainable method.
	 */
	public function set_query_args( $args ) {
		$this->query_args = $args;

		return $this;
	}

	/**
	 * Set the export format.
	 *
	 * @param string $format Export format. "csv", "json", or "html.
	 * @return self Chainable method.
	 */
	public function set_download_format( $format ) {
		$this->format = $format;

		return $this;
	}

	/**
	 * Set export options.
	 *
	 * @param array $options Export options.
	 * @return self Chainable method.
	 */
	public function set_options( $options ) {
		$this->options = $options;

		return $this;
	}

	/**
	 * Get an export option value.
	 *
	 * @param string $key Option key.
	 * @param mixed  $default_value Default value if option not set.
	 * @return mixed Option value or default if not set.
	 */
	protected function get_option( $key, $default_value = null ) {
		return $this->options[ $key ] ?? $default_value;
	}

	/**
	 * Add hooks that modify the output of the events.
	 */
	protected function add_hooks() {
		// Disable relative time output in header.
		add_filter( 'simple_history/header_time_ago_max_time', '__return_zero' );
		add_filter( 'simple_history/header_just_now_max_time', '__return_zero' );

		// Don't use "You" if event is initiated by the same user that does the export.
		add_filter( 'simple_history/header_initiator_use_you', '__return_false' );
	}

	/**
	 * Download events that match query args.
	 *
	 * Events are fetched in batches, with each batch being output to the export file.
	 *
	 * The export file is output to the browser and then the script exits.
	 *
	 * @return never
	 */
	public function download() {
		$this->add_hooks();

		wp_raise_memory_limit( 'admin' );

		$export_format = $this->format;

		$query = new Log_Query();

		$download_query_args = $this->query_args;

		// Exports are flat lists, no grouping needed.
		$download_query_args['ungrouped'] = true;

		// Cap batch size to avoid memory exhaustion.
		$max_batch_size = 250;
		$download_query_args['posts_per_page'] = min( $max_batch_size, max( 1, (int) ( $download_query_args['posts_per_page'] ?? $max_batch_size ) ) );

		$query_result = $query->query( $download_query_args );

		if ( is_wp_error( $query_result ) ) {
			wp_die( esc_html( $query_result->get_error_message() ) );
		}

		$pages_count  = $query_result['pages_count'];
		$page_current = $query_result['page_current'];

		// Count is established; skip redundant COUNT(*) on subsequent pages.
		$download_query_args['skip_count_query'] = true;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Does work, see https://github.com/WordPress/WordPress-Coding-Standards/issues/295
		$fp = fopen( 'php://output', 'w' );

		$attachment_header_template = 'Content-Disposition: attachment; filename="%1$s"';

		if ( $export_format === 'csv' ) {
			$filename = 'simple-history-export-' . time() . '.csv';
			header( 'Content-Type: text/plain' );
			header( sprintf( $attachment_header_template, $filename ) );
		} elseif ( $export_format === 'json' ) {
			$filename = 'simple-history-export-' . time() . '.json';
			header( 'Content-Type: application/json' );
			header( sprintf( $attachment_header_template, $filename ) );
		} elseif ( $export_format === 'html' ) {
			$filename = 'simple-history-export-' . time() . '.html';
			header( 'Content-Type: text/html' );
			header( sprintf( $attachment_header_template, $filename ) );
		}

		// Some formats need to output some stuff before the actual loops.
		if ( $export_format === 'json' ) {
			$json_row = '[';
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- This is a known file pointer and it's not writing to a physical file.
			fwrite( $fp, $json_row );
		} elseif ( $export_format === 'html' ) {
			$html = sprintf(
				'
			<!doctype html>
			<meta charset="utf-8">
			<title>Simple History export</title>
			<ul>
			'
			);
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- This is a known file pointer and it's not writing to a physical file.
			fwrite( $fp, $html );
		}

		// Paginate through all pages and all their rows.
		$row_loop = 0;
		while ( $page_current <= $pages_count + 1 ) {

			foreach ( $query_result['log_rows'] as $one_row ) {

				set_time_limit( 30 );

				if ( $export_format === 'csv' ) {
					$this->output_csv_row( $fp, $one_row );
				} elseif ( $export_format === 'json' ) {
					$this->output_json_row( $fp, $one_row, $row_loop );
				} elseif ( $export_format === 'html' ) {
					$this->output_html_row( $fp, $one_row );
				}

				++$row_loop;
			}

			flush();

			// Free memory from processed batch before fetching next page.
			$query_result['log_rows'] = null;

			// Fetch next page.
			++$page_current;
			$download_query_args['paged'] = $page_current;
			$query_result                 = $query->query( $download_query_args );

			if ( is_wp_error( $query_result ) ) {
				break;
			}
		}

		if ( $export_format === 'json' ) {
			$json_row = ']';
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- This is a known file pointer and it's not writing to a physical file.
			fwrite( $fp, $json_row );
		} elseif ( $export_format === 'html' ) {
			$html = sprintf( '</ul>' );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- This is a known file pointer and it's not writing to a physical file.
			fwrite( $fp, $html );
		}

		fclose( $fp );
		flush();

		exit;
	}

	/**
	 * Get CSV headers.
	 *
	 * @return array Array of CSV column headers.
	 */
	protected function get_csv_headers() {
		return array(
			_x( 'Date (UTC)', 'CSV export header', 'simple-history' ),
			_x( 'Date (local)', 'CSV export header', 'simple-history' ),
			_x( 'Logger', 'CSV export header', 'simple-history' ),
			_x( 'Level', 'CSV export header', 'simple-history' ),
			_x( 'Initiator', 'CSV export header', 'simple-history' ),
			_x( 'Message Key', 'CSV export header', 'simple-history' ),
			_x( 'User Email', 'CSV export header', 'simple-history' ),
			_x( 'User Login', 'CSV export header', 'simple-history' ),
			_x( 'User Roles', 'CSV export header', 'simple-history' ),
			_x( 'IP Address', 'CSV export header', 'simple-history' ),
			_x( 'Header Message', 'CSV export header', 'simple-history' ),
			_x( 'Details', 'CSV export header', 'simple-history' ),
			_x( 'Occasions', 'CSV export header', 'simple-history' ),
		);
	}

	/**
	 * Output a CSV row.
	 *
	 * @param resource $fp File pointer.
	 * @param object   $one_row Log row.
	 */
	protected function output_csv_row( $fp, $one_row ) {
		static $headers_outputted = false;

		$header_output = wp_strip_all_tags( html_entity_decode( $this->simple_history->get_log_row_header_output( $one_row ), ENT_QUOTES, 'UTF-8' ) );
		$header_output = trim( preg_replace( '/\s\s+/', ' ', $header_output ) );

		$message_output = wp_strip_all_tags( html_entity_decode( $this->simple_history->get_log_row_plain_text_output( $one_row ), ENT_QUOTES, 'UTF-8' ) );

		$user_email = empty( $one_row->context['_user_email'] ) ? null : $one_row->context['_user_email'];
		$user_login = empty( $one_row->context['_user_login'] ) ? null : $one_row->context['_user_login'];
		$ip_address = $this->get_csv_ip_address( $one_row );
		$user_roles = [];

		if ( $user_email ) {
			$user       = get_user_by( 'email', $user_email );
			$user_roles = $user->roles ?? array();
		}

		$user_roles_comma_separated = implode( ', ', $user_roles );

		$date_local = wp_date( 'Y-m-d H:i:s', strtotime( $one_row->date ) );

		// Output headers if this is the first row and headers are enabled.
		if ( ! $headers_outputted && $this->get_option( 'include_headers', false ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
			fputcsv( $fp, $this->get_csv_headers() );
			$headers_outputted = true;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
		fputcsv(
			$fp,
			array(
				Helpers::esc_csv_field( $one_row->date ),
				Helpers::esc_csv_field( $date_local ),
				Helpers::esc_csv_field( $one_row->logger ),
				Helpers::esc_csv_field( $one_row->level ),
				Helpers::esc_csv_field( $one_row->initiator ),
				Helpers::esc_csv_field( $one_row->context_message_key ),
				Helpers::esc_csv_field( $user_email ),
				Helpers::esc_csv_field( $user_login ),
				Helpers::esc_csv_field( $user_roles_comma_separated ),
				Helpers::esc_csv_field( $ip_address ),
				Helpers::esc_csv_field( $header_output ),
				Helpers::esc_csv_field( $message_output ),
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				Helpers::esc_csv_field( $one_row->subsequentOccasions ),
			)
		);
	}

	/**
	 * IP address to write for one event.
	 *
	 * A comment that was just posted keeps its full address on the comment
	 * (comment_author_IP). The copy stored as _server_remote_addr was originally anonymized.
	 *
	 * Detective Mode stores $_SERVER['REMOTE_ADDR'] unchanged as
	 * detective_mode_server_remote_addr. That address is used when the comment
	 * address is not available. Otherwise the potentially anonymized _server_remote_addr
	 * is written.
	 *
	 * @param object $one_row Log row.
	 * @return string
	 */
	protected function get_csv_ip_address( $one_row ) {
		$context = isset( $one_row->context ) && is_array( $one_row->context ) ? $one_row->context : [];

		$comment_ip = $this->get_posted_comment_ip_address( $one_row, $context );

		if ( $comment_ip !== '' ) {
			return $comment_ip;
		}

		$raw_ip = $this->validated_ip_address( $context['detective_mode_server_remote_addr'] ?? '' );

		if ( $raw_ip !== '' ) {
			return $raw_ip;
		}

		$stored_ip = $context['_server_remote_addr'] ?? '';

		return is_string( $stored_ip ) ? $stored_ip : '';
	}

	/**
	 * Full IP of a comment that this event recorded as being posted.
	 *
	 * Reads the comment when it still exists. If it was deleted, uses the
	 * address copied from the comment onto the event when it was logged.
	 *
	 * Approving, editing, or deleting a comment is a later action. Those
	 * events keep the actor's address, which may be anonymized.
	 *
	 * @param object              $one_row Log row.
	 * @param array<string,mixed> $context Event context.
	 * @return string Valid IP, or an empty string when this is not a posted comment.
	 */
	private function get_posted_comment_ip_address( $one_row, $context ) {
		if ( ! $this->is_posted_comment_event( $one_row ) ) {
			return '';
		}

		$comment_id = absint( $context['comment_ID'] ?? 0 );

		if ( $comment_id ) {
			$comment = get_comment( $comment_id );

			if ( $comment instanceof \WP_Comment ) {
				$comment_ip = $this->validated_ip_address( $comment->comment_author_IP );

				if ( $comment_ip !== '' ) {
					return $comment_ip;
				}
			}
		}

		return $this->validated_ip_address( $context['comment_author_IP'] ?? '' );
	}

	/**
	 * Whether the row is a comment, trackback, or pingback being added.
	 *
	 * @param object $one_row Log row.
	 * @return bool
	 */
	private function is_posted_comment_event( $one_row ) {
		$logger = $one_row->logger ?? '';

		if ( $logger !== 'SimpleCommentsLogger' ) {
			return false;
		}

		$message_key = $one_row->context_message_key ?? '';

		if ( ! is_string( $message_key ) ) {
			$message_key = '';
		}

		if ( $message_key === '' ) {
			$context     = $one_row->context ?? [];
			$message_key = is_array( $context ) ? ( $context['_message_key'] ?? '' ) : '';
		}

		if ( ! is_string( $message_key ) ) {
			return false;
		}

		$posted_message_keys = [
			'anon_comment_added',
			'user_comment_added',
			'anon_trackback_added',
			'user_trackback_added',
			'anon_pingback_added',
			'user_pingback_added',
		];

		return in_array( $message_key, $posted_message_keys, true );
	}

	/**
	 * Return a trimmed IP when it is a real address.
	 *
	 * Anonymized values such as "203.0.113.x" are rejected.
	 *
	 * @param mixed $ip_address Candidate address.
	 * @return string
	 */
	private function validated_ip_address( $ip_address ) {
		if ( ! is_string( $ip_address ) ) {
			return '';
		}

		$ip_address = trim( $ip_address );
		$validated  = filter_var( $ip_address, FILTER_VALIDATE_IP );

		if ( $validated === false ) {
			return '';
		}

		return $validated;
	}

	/**
	 * Output a JSON row.
	 *
	 * @param resource $fp File pointer.
	 * @param object   $one_row Log row.
	 * @param int      $row_loop Row loop counter.
	 */
	protected function output_json_row( $fp, $one_row, $row_loop ) {
		$comma    = $row_loop === 0 ? "\n" : ",\n";
		$json_row = $comma . Helpers::json_encode( $one_row );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
		fwrite( $fp, $json_row );
	}

	/**
	 * Output an HTML row.
	 *
	 * @param resource $fp File pointer.
	 * @param object   $one_row Log row.
	 */
	protected function output_html_row( $fp, $one_row ) {
		$html = sprintf(
			'
			<li>
				<div>%1$s</div>
				<div>%2$s</div>
				<div>%3$s</div>
			</li>
			',
			$this->simple_history->get_log_row_header_output( $one_row ),
			$this->simple_history->get_log_row_plain_text_output( $one_row ),
			$this->simple_history->get_log_row_details_output( $one_row )
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
		fwrite( $fp, $html );
	}
}
