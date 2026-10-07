<?php

class WPML_TF_Backend_Styles {

	const HANDLE = 'wpml-tf-backend';

	public function enqueue() {
		$style = ICL_PLUGIN_URL . '/res/css/translation-feedback/backend-feedback-list.css';
		wp_register_style( self::HANDLE, $style, array( 'otgs-icons' ), ICL_SITEPRESS_SCRIPT_VERSION );
		wp_enqueue_style( self::HANDLE );
	}
}
