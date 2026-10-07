<?php

class WPML_Upgrade_Remove_Translation_Services_Transient implements IWPML_Upgrade_Command {

	public function run_admin() {
		delete_transient( 'wpml_translation_service_list' );
		return true;
	}

	public function run_ajax() {
		return false;
	}

	public function run_frontend() {
		return false;
	}

	public function get_results() {
		return array();
	}
}