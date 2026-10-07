<?php

class WPML_Endpoints_Support_Factory implements IWPML_Frontend_Action_Loader, IWPML_Backend_Action_Loader, IWPML_Deferred_Action_Loader {

	public function get_load_action() {
		return 'plugins_loaded';
	}

	public function create() {
		global $sitepress, $wpml_post_translations;

		if ( $this->are_st_functions_loaded() ) {
			return new WPML_Endpoints_Support( $wpml_post_translations, $sitepress->get_current_language(), $sitepress->get_default_language() );
		}

		return null;
	}

	private function are_st_functions_loaded() {
		return function_exists( 'icl_get_string_id' );
	}
}