<?php

class WPML_TF_Backend_Options_Scripts {

	const HANDLE = 'wpml-tf-backend-options';

	public function enqueue() {
		$script = ICL_PLUGIN_URL . '/res/js/translation-feedback/wpml-tf-backend-options-script.js';
		wp_register_script( self::HANDLE, $script, array( 'jquery', 'wp-util' ), ICL_SITEPRESS_SCRIPT_VERSION );
		wp_enqueue_script( self::HANDLE );
	}
}
