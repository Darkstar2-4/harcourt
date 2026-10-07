<?php

class WPML_TF_Backend_Options_Styles {

	const HANDLE = 'wpml-tf-backend-options';

	public function enqueue() {
		$style = ICL_PLUGIN_URL . '/res/css/translation-feedback/backend-options.css';
		wp_register_style( self::HANDLE, $style, array(), ICL_SITEPRESS_SCRIPT_VERSION );
		wp_enqueue_style( self::HANDLE );
	}
}
