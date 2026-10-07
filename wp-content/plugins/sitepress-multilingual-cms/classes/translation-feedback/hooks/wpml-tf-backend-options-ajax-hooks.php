<?php

class WPML_TF_Backend_Options_AJAX_Hooks implements IWPML_Action {

	private $settings;

	private $settings_write;

	private $promote_notices;

	private $post_data;

	public function __construct(
		WPML_TF_Settings $settings,
		WPML_TF_Settings_Write $settings_write,
		WPML_TF_Promote_Notices $promote_notices,
		array $post_data
	) {
		$this->settings        = $settings;
		$this->settings_write  = $settings_write;
		$this->promote_notices = $promote_notices;
		$this->post_data       = $post_data;
	}

	public function add_hooks() {
		add_action( 'wp_ajax_' . WPML_TF_Backend_Options_AJAX_Hooks_Factory::AJAX_ACTION, array( $this, 'save_settings_callback' ) );
	}

	public function save_settings_callback() {
		$new_settings = array();

		if ( isset( $this->post_data['settings'] ) ) {
			parse_str( $this->post_data['settings'], $new_settings );
		}

		if ( isset( $new_settings['enabled'] ) ) {
			$this->settings->set_enabled( true );
			$this->promote_notices->remove();
		} else {
			$this->settings->set_enabled( false );
		}

		if ( isset( $new_settings['button_mode'] ) ) {
			$this->settings->set_button_mode( $new_settings['button_mode'] );
		}

		if ( isset( $new_settings['icon_style'] ) ) {
			$this->settings->set_icon_style( $new_settings['icon_style'] );
		}

		if ( isset( $new_settings['languages_to'] ) ) {
			$this->settings->set_languages_to( $new_settings['languages_to'] );
		} else {
			$this->settings->set_languages_to( array() );
		}

		if ( isset( $new_settings['display_mode'] ) ) {
			$this->settings->set_display_mode( $new_settings['display_mode'] );
		}

		if ( isset( $new_settings['expiration_mode'] ) ) {
			$this->settings->set_expiration_mode( $new_settings['expiration_mode'] );
		}

		if ( isset( $new_settings['expiration_delay_quantity'] ) ) {
			$this->settings->set_expiration_delay_quantity( $new_settings['expiration_delay_quantity'] );
		}

		if ( isset( $new_settings['expiration_delay_unit'] ) ) {
			$this->settings->set_expiration_delay_unit( $new_settings['expiration_delay_unit'] );
		}

		$this->settings_write->save( $this->settings );

		wp_send_json_success( __( 'Settings saved', 'sitepress' ) );
	}
}
