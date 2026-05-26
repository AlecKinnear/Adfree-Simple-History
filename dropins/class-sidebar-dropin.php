<?php

namespace Simple_History\Dropins;

use Simple_History\Helpers;

/**
 * Dropin Name: Sidebar
 * Drop Description: Outputs HTML and filters for a sidebar
 * Dropin URI: https://simple-history.com/
 * Author: Pär Thernström
 */
class Sidebar_Dropin extends Dropin {
	/** @inheritdoc */
	public function loaded() {
		add_action( 'simple_history/history_page/after_gui', array( $this, 'output_sidebar_html' ) );
	}

	/**
	 * Output default sidebar contents:
	 * - Review box
	 * - Support box
	 */
	public function default_sidebar_contents() {
		// Hide sidebar boxes if promo boxes should not be shown.
		if ( ! Helpers::show_promo_boxes() ) {
			return;
		}

		// Box about support.
		$boxSupport = sprintf(
			'
			<div class="postbox sh-PremiumFeaturesPostbox">
				<h3 class="hndle">%1$s</h3>
				<div class="inside">
					<p>%2$s</p>
				</div>
			</div>
			',
			_x( 'Need help?', 'Sidebar box', 'simple-history' ), // 1
			sprintf(
				// translators: 1 is a link to the support forum.
				_x( '<a href="%1$s">Visit the support forum</a> if you need help or have questions.', 'Sidebar box', 'simple-history' ),
				'https://wordpress.org/support/plugin/simple-history'
			) // 2
		);

		$arrBoxes = array(
			'boxDonate' => $boxDonate,
			'boxSupport' => $boxSupport,
		);

		/**
		 * Filter the default boxes to output in the sidebar
		 *
		 * @since 2.0.17
		 *
		 * @param array $arrBoxes array with boxes to output. Check the key to determine which box is which.
		 */
		$arrBoxes = apply_filters( 'simple_history/SidebarDropin/default_sidebar_boxes', $arrBoxes );

		echo implode( '', $arrBoxes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Output the outline for the sidebar
	 * Plugins and dropins simple use the filters to output contents to the sidebar
	 * Example HTML code to generate meta box:
	 *
	 *  <div class="postbox">
	 *      <h3 class="hndle">Title</h3>
	 *      <div class="inside">
	 *          <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
	 *      </div>
	 *  </div>
	 */
	public function output_sidebar_html() {
		?>
		<div class="SimpleHistory__pageSidebar">

			<div class="metabox-holder">
				<?php
				/**
				 * Allows to output HTML in sidebar
				 *
				 * @since 2.0.16
				 */
				do_action( 'simple_history/dropin/sidebar/sidebar_html' );
				?>
			</div>

		</div>
		<?php
	}
}
