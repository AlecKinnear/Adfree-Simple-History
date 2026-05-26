<?php

namespace Simple_History\Services;

use Simple_History\Helpers;
use Simple_History\Menu_Page;
use Simple_History\Services\Setup_Settings_Page;

/**
 * Settings tab for hidden / advanced settings that are not shown on the main
 * settings page.
 *
 * Options registered here can also be set via `wp-config.php` constants or
 * filters, but this tab gives admins a GUI for the less-obvious knobs.
 */
class Hidden_Settings_Page extends Service {
	private const SETTINGS_PAGE_SLUG    = 'simple_history_settings_menu_slug_hidden_settings';
	public const SETTINGS_OPTION_GROUP  = 'simple_history_settings_group_hidden_settings';
	private const SETTINGS_SECTION_ID   = 'simple_history_settings_section_hidden_settings';
	public const SETTINGS_SUBTAB_SLUG   = 'general_settings_subtab_hidden_settings';

	/**
	 * @inheritdoc
	 */
	public function loaded() {
		add_action( 'admin_menu', [ $this, 'add_settings_menu_tab' ], 15 );
		add_action( 'init', [ $this, 'on_init_add_settings' ], 20 );
	}

	/**
	 * Register settings after plugins have loaded.
	 */
	public function on_init_add_settings() {
		add_action( 'admin_menu', [ $this, 'register_and_add_settings' ] );
	}

	/**
	 * Add the "Hidden Settings" tab as a subtab of the main settings tab.
	 */
	public function add_settings_menu_tab() {
		$menu_manager = $this->simple_history->get_menu_manager();

		if ( ! $menu_manager->page_exists( Setup_Settings_Page::SETTINGS_GENERAL_SUBTAB_SLUG ) ) {
			return;
		}

		( new Menu_Page() )
			->set_page_title( __( 'Hidden Settings', 'simple-history' ) )
			->set_menu_title( __( 'Hidden Settings', 'simple-history' ) )
			->set_menu_slug( self::SETTINGS_SUBTAB_SLUG )
			->set_callback( [ $this, 'settings_output' ] )
			->set_order( 45 ) // Between Log Forwarding (40) and Licences (50).
			->set_parent( Setup_Settings_Page::SETTINGS_GENERAL_SUBTAB_SLUG )
			->add();
	}

	/**
	 * Register settings sections and fields.
	 */
	public function register_and_add_settings() {
		Helpers::add_settings_section(
			self::SETTINGS_SECTION_ID,
			[ __( 'Hidden Settings', 'simple-history' ), 'settings' ],
			[ $this, 'settings_section_output' ],
			self::SETTINGS_PAGE_SLUG
		);

		register_setting(
			self::SETTINGS_OPTION_GROUP,
			'simple_history_retention_days',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ Helpers::class, 'sanitize_retention_days_input' ],
				'default'           => 60,
			]
		);

		add_settings_field(
			'simple_history_retention_days',
			Helpers::get_settings_field_title_output( __( 'Log retention', 'simple-history' ), 'schedule' ),
			[ $this, 'settings_field_retention_days' ],
			self::SETTINGS_PAGE_SLUG,
			self::SETTINGS_SECTION_ID
		);
	}

	/**
	 * Settings field for how long to keep events in the database.
	 */
	public function settings_field_retention_days() {
		$current_retention_days   = Helpers::get_retention_days_setting();
		$retention_default_values = [ 30, 60, 90, 180, 365, 0 ];

		echo '<div id="simple_history_retention_days">';

		if (
			has_filter( 'simple_history/db_purge_days_interval' )
			|| has_filter( 'simple_history_db_purge_days_interval' )
		) {
			$effective_days = Helpers::get_clear_history_interval();

			printf(
				'<input type="text" readonly value="%1$s" class="small-text" />',
				esc_html( $effective_days )
			);

			echo '<p class="description">';
			esc_html_e( 'Retention is controlled by a filter and cannot be changed here.', 'simple-history' );
			echo '</p>';

			echo '</div>';

			return;
		}

		?>
		<select name="simple_history_retention_days" id="simple_history_retention_days_select">
			<?php
			foreach ( $retention_default_values as $one_value ) {
				if ( $one_value === 0 ) {
					$label = __( 'Keep forever', 'simple-history' );
				} else {
					$label = sprintf(
						// translators: %d is number of days.
						_n( '%d day', '%d days', $one_value, 'simple-history' ),
						$one_value
					);
				}

				printf(
					'<option %1$s value="%2$s">%3$s</option>',
					selected( $current_retention_days, $one_value, false ),
					esc_attr( $one_value ),
					esc_html( $label )
				);
			}

			if ( ! in_array( $current_retention_days, $retention_default_values, true ) ) {
				$custom_label = $current_retention_days > 0
					? sprintf(
						// translators: %d is number of days.
						_n( '%d day', '%d days', $current_retention_days, 'simple-history' ),
						$current_retention_days
					)
					: __( 'Keep forever', 'simple-history' );

				printf(
					'<option selected="selected" value="%1$s">%2$s</option>',
					esc_attr( $current_retention_days ),
					esc_html( $custom_label )
				);
			}
			?>
		</select>
		<p class="description">
			<?php
			esc_html_e(
				'Events older than this are automatically removed to keep your database lean. Choose "Keep forever" to disable automatic cleanup.',
				'simple-history'
			);
			?>
		</p>
		<?php

		echo '</div>';
	}

	/**
	 * Intro text for the settings section.
	 */
	public function settings_section_output() {
		?>
		<div class="sh-SettingsSectionIntroduction">
			<p>
				<?php
				esc_html_e(
					'These are the settings which the standard Simple History plugin does not show.',
					'simple-history'
				);
				?>
			</p>
		</div>
		<?php

		/**
		 * Fires inside the Hidden Settings section so that other code can
		 * inject its own fields here.
		 *
		 * @since 5.x
		 */
		do_action( 'simple_history/hidden_settings/section_output' );
	}

	/**
	 * Render the Hidden Settings tab page.
	 */
	public function settings_output() {
		?>
		<div class="wrap sh-Page-content">
			<div class="sh-grid sh-grid-cols-2/3">
				<form method="post" action="options.php">
					<?php
					do_settings_sections( self::SETTINGS_PAGE_SLUG );
					settings_fields( self::SETTINGS_OPTION_GROUP );
					submit_button();
					?>
				</form>
			</div>
		</div>
		<?php
	}
}
