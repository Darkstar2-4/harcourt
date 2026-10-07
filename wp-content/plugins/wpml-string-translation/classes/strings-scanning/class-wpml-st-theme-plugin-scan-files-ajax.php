<?php

class WPML_ST_Theme_Plugin_Scan_Files_Ajax implements IWPML_Action {

	private $string_scanner;

	public function __construct( IWPML_ST_String_Scanner $string_scanner ) {
		$this->string_scanner = $string_scanner;
	}

	public function add_hooks() {
		add_action( 'wp_ajax_wpml_st_scan_chunk', array( $this, 'scan' ) );
	}

	public function scan() {
		if ( ! current_user_can( 'wpml_manage_theme_and_plugin_localization' ) ) {
			wp_send_json_error( __( 'not allowed', 'wpml-string-translation' ) );
			return;
		}

		wpml_get_admin_notices()->remove_notice(
			WPML_ST_Themes_And_Plugins_Settings::NOTICES_GROUP,
			WPML_ST_Themes_And_Plugins_Updates::WPML_ST_SCAN_NOTICE_ID
		);

		wpml_get_admin_notices()->remove_notice(
			WPML_ST_Themes_And_Plugins_Settings::NOTICES_GROUP,
			WPML_ST_Themes_And_Plugins_Updates::WPML_ST_SCAN_ACTIVE_ITEMS_NOTICE_ID
		);

		$this->clear_items_needs_scan_buffer();
		$this->string_scanner->scan();
	}

	public function clear_items_needs_scan_buffer() {
		delete_option( WPML_ST_Themes_And_Plugins_Updates::WPML_ST_ITEMS_TO_SCAN );
	}
}
