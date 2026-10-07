<?php

class WPML_User_Jobs_Notification_Settings_Render {

	private $section_template;

	public function __construct( WPML_User_Jobs_Notification_Settings_Template $notification_settings_template ) {
		$this->section_template = $notification_settings_template;
	}

	public function add_hooks() {
		if ( current_user_can( 'translate' ) || current_user_can( 'manage_translations' ) ) {
			add_action( 'wpml_user_profile_options', array( $this, 'render_options' ) );
		}
	}

	public function render_options( $user_id ) {
		$field_checked = checked( true, WPML_User_Jobs_Notification_Settings::is_new_job_notification_enabled( $user_id ), false );
		echo $this->get_notification_template()->get_setting_section( $field_checked );
	}

	private function get_notification_template() {
		return $this->section_template;
	}
}
