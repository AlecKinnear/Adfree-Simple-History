<?php

namespace Simple_History\Dropins;

use Simple_History\Helpers;
use Simple_History\Simple_History;

/**
 * Dropin Name: Add donate links
 * Dropin Description: Add donate links to Installed Plugins listing screen and to Simple History settings screen.
 * Dropin URI: http://simple-history.com/
 * Author: Pär Thernström
 */

/**
 * Simple History Donate dropin
 * Put some donate messages here and there
 */
class Donate_Dropin extends Dropin {
	/** @inheritDoc */
	public function loaded() {
		// Prio 50 so it's added after the built in settings.
		add_action( 'admin_menu', array( $this, 'add_settings' ), 50 );
		add_filter( 'plugin_row_meta', array( $this, 'action_plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Add link to the donate page in the Plugins » Installed plugins screen.
	 *
	 * Called from filter 'plugin_row_meta'.
	 *
	 * @param array<string,string> $links with added links.
	 * @param string               $file plugin file.
	 * @return array<string,string> $links with added links
	 */
	public function action_plugin_row_meta( $links, $file ) {
		if ( $file == $this->simple_history->plugin_basename ) {
			$links[] = sprintf(
				'<a href="https://www.paypal.me/eskapism">%1$s</a>',
				__( 'Donate using PayPal', 'simple-history' )
			);

			$links[] = sprintf(
				'<a href="https://github.com/sponsors/bonny">%1$s</a>',
				__( 'Become a GitHub sponsor', 'simple-history' )
			);
		}

		return $links;
	}

	/**
	 * Add settings section.
	 */
	public function add_settings() {
		Helpers::add_settings_section(
			'simple_history_settings_section_donate',
			[ _x( 'Support development', 'donate settings headline', 'simple-history' ), 'volunteer_activism' ],
			array( $this, 'settings_section_output' ),
			Simple_History::SETTINGS_MENU_SLUG // same slug as for options menu page.
		);

		// Add a dummy settings field, required to make the after_section-html be output due to bug in do_settings_sections().
		add_settings_field(
			'simple_history_settings_field_donate',
			'',
			'__return_empty_string',
			Simple_History::SETTINGS_MENU_SLUG,
			'simple_history_settings_section_donate'
		);
	}

	/**
	 * Output settings field HTML.
	 */
	public function settings_field_output() {
		echo '';
	}

	/**
	 * Output settings section HTML.
	 */
	public function settings_section_output() {
		echo '<p>';
		printf(
			wp_kses(
				// translators: 1 is a link to PayPal, 2 is a link to GitHub sponsors.
				__(
					'If you find Simple History useful please <a href="%1$s" target="_blank" class="sh-ExternalLink">donate using PayPal</a> or <a href="%2$s" target="_blank" class="sh-ExternalLink">become a GitHub sponsor</a>.',
					'simple-history'
				),
				array(
					'a' => array(
						'href' => array(),
						'class' => [],
						'target' => [],
					),
				)
			),
			'https://www.paypal.me/eskapism',
			'https://github.com/sponsors/bonny',
		);
		echo '</p>';
	}
}
