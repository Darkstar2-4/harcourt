<?php

abstract class WPML_AJAX_Base_Factory implements IWPML_AJAX_Action_Loader, IWPML_Deferred_Action_Loader {

	private $ajax_action_validation;

	public function get_load_action() {
		return 'plugins_loaded';
	}

	public function is_valid_action( $ajax_action ) {
		return $this->ajax_action_validation->is_valid( $ajax_action );
	}

	public function set_ajax_action_validation( WPML_AJAX_Action_Validation $ajax_action_validation ) {
		$this->ajax_action_validation = $ajax_action_validation;
	}
}
