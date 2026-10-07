<?php

class WPML_Translate_Link_Targets_In_Custom_Fields_Hooks {

	public function __construct( $translate_links, &$wp_api ) {

		if ( $translate_links->has_meta_keys() ) {
			$wp_api->add_filter( 'get_post_metadata', array( $translate_links, 'maybe_translate_link_targets' ), 10, 4 );
		}
	}

}

