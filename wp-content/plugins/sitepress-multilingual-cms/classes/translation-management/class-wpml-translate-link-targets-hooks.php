<?php

class WPML_Translate_Link_Targets_Hooks {

	public function __construct( $translate_link_targets, $wp_api ) {
		$wp_api->add_filter( 'wpml_translate_link_targets', array( $translate_link_targets, 'convert_text' ), 10, 1 );
	}

}

