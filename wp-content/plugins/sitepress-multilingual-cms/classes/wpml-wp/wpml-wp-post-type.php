<?php

class WPML_WP_Post_Type implements IWPML_WP_Element_Type {

	public function get_wp_element_type_object( $post_type ) {
		return get_post_type_object( $post_type );
	}

}
