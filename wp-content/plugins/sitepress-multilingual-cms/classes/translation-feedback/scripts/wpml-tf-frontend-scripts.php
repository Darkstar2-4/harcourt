<?php

class WPML_TF_Frontend_Scripts {

	const HANDLE = 'wpml-tf-frontend';

	public function enqueue() {
		$script = ICL_PLUGIN_URL . '/res/js/translation-feedback/wpml-tf-frontend-script.js';
		wp_register_script( self::HANDLE, $script, array(), ICL_SITEPRESS_SCRIPT_VERSION );
		wp_script_add_data( self::HANDLE, 'strategy', 'defer' );
		wp_enqueue_script( self::HANDLE );
	}
}
