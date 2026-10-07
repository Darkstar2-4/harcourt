<?php

class WPML_UI_Unlock_Button {

	public function render( $disabled, $unlocked, $radio_name, $unlocked_name ) {
		if ( $disabled ) {

			if ( ! $unlocked ) { ?>
				<button type="button"
						class="button-secondary wpml-button-lock js-wpml-sync-lock"
						title="<?php esc_html_e( 'This setting is controlled by a wpml-config.xml file. Click here to unlock and override this setting.', 'sitepress' ); ?>"
						data-radio-name="<?php echo $radio_name; ?>"
						data-unlocked-name="<?php echo $unlocked_name; ?>"
				>
					<i class="otgs-ico-lock"></i>
				</button>
			<?php } else { ?>
				<button type="button"
						class="button-secondary wpml-button-lock"
						title="<?php esc_html_e( 'This setting has been unlocked.', 'sitepress' ); ?>"
						disabled
				>
					<i class="otgs-ico-unlock"></i>
				</button>
			<?php } ?>

		<?php } ?>

		<input type="hidden" name="<?php echo $unlocked_name; ?>" value="<?php echo $unlocked ? '1' : '0'; ?>">

		<?php
	}
}
