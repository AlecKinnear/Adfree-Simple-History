<?php

namespace Simple_History\Services;

use Simple_History\Helpers;
use Simple_History\Menu_Manager;
use Simple_History\Services\AddOns_Licences;

/**
 * Shows a reminder card on Simple History admin pages
 * when one or more add-ons are installed but have no license key entered.
 *
 * Without a license key, the user does not receive updates — which has been
 * a recurring support topic. The card surfaces this state and links straight
 * to the license entry field.
 */
class License_Reminder_Service extends Service {
	/** @inheritdoc */
	public function loaded() {
		add_action( 'simple_history/admin_page/after_header', [ $this, 'maybe_output_card' ] );
	}

	/**
	 * Render the reminder card if the user has add-ons missing license keys.
	 */
	public function maybe_output_card() {
		if ( ! current_user_can( Helpers::get_view_settings_capability() ) ) {
			return;
		}

		// Avoid redundancy with the form on the Licences sub-tab.
		if ( $this->is_on_licenses_settings_tab() ) {
			return;
		}

		$addons_without_license = $this->get_addons_missing_license();

		if ( empty( $addons_without_license ) ) {
			return;
		}

		$this->render_card( $addons_without_license );
	}

	/**
	 * Are we currently on the Licences settings sub-tab?
	 *
	 * @return bool
	 */
	private function is_on_licenses_settings_tab() {
		return Menu_Manager::get_current_sub_tab_slug() === 'general_settings_subtab_licenses';
	}

	/**
	 * Get add-ons that are registered but have no license key entered.
	 *
	 * @return array<\Simple_History\AddOn_Plugin>
	 */
	private function get_addons_missing_license() {
		/** @var AddOns_Licences|null $licences_service */
		$licences_service = $this->simple_history->get_service( AddOns_Licences::class );

		if ( ! $licences_service instanceof AddOns_Licences ) {
			return [];
		}

		if ( ! $licences_service->has_add_ons() ) {
			return [];
		}

		$addons_without_license = [];

		foreach ( $licences_service->get_addon_plugins() as $addon ) {
			$key = $addon->get_license_key();

			if ( ! empty( $key ) ) {
				continue;
			}

			$addons_without_license[] = $addon;
		}

		return $addons_without_license;
	}

	/**
	 * Output the card HTML.
	 *
	 * @param array<\Simple_History\AddOn_Plugin> $addons_without_license Add-ons missing their license key.
	 */
	private function render_card( $addons_without_license ) {
		$licenses_url = Helpers::get_settings_page_sub_tab_url( 'general_settings_subtab_licenses' );

		if ( count( $addons_without_license ) === 1 ) {
			$title = sprintf(
				/* translators: %s: add-on name, e.g. "Simple History Premium" */
				__( 'Add your %s license key', 'simple-history' ),
				$addons_without_license[0]->name
			);

			$description = __( 'Enter your license key to enable automatic updates and access support.', 'simple-history' );
		} else {
			$title       = __( 'Add your add-on license keys', 'simple-history' );
			$description = __( 'You have add-ons that need license keys. Enter them to enable automatic updates and access support.', 'simple-history' );
		}

		$button_label = __( 'Add license key', 'simple-history' );
		?>
		<div class="sh-LicenseReminder" role="region" aria-label="<?php esc_attr_e( 'License key required', 'simple-history' ); ?>">
			<div class="sh-LicenseReminder-icon" aria-hidden="true">
				<span class="dashicons dashicons-admin-network"></span>
			</div>
			<div class="sh-LicenseReminder-body">
				<p class="sh-LicenseReminder-title"><?php echo esc_html( $title ); ?></p>
				<p class="sh-LicenseReminder-text"><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="sh-LicenseReminder-action">
				<a href="<?php echo esc_url( $licenses_url ); ?>" class="button button-primary">
					<?php echo esc_html( $button_label ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
