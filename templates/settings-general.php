<?php
namespace Simple_History;

use Simple_History\Dropins\Sidebar_Add_Ons_Dropin;
use Simple_History\Simple_History;

defined( 'ABSPATH' ) || die();

/**
 * This is the output of the general settings page.
 */
?>
<div class="wrap sh-Page-content">
	<div class="sh-grid sh-grid-cols-2/3">
		<form method="post" action="options.php">
			<?php
			// Prints out all settings sections added to a particular settings page.
			do_settings_sections( Simple_History::SETTINGS_MENU_SLUG );

			// Output nonce, action, and option_page fields.
			settings_fields( Simple_History::SETTINGS_GENERAL_OPTION_GROUP );

			submit_button();
			?>
		</form>
	</div>
</div>
