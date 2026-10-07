<?php

class WPML_Compatibility_2017 {
	function init_hooks() {
		$num_sections = twentyseventeen_panel_count();

		for ( $i = 1; $i <= $num_sections; $i ++ ) {
			add_filter( 'theme_mod_panel_' . $i, array( $this, 'get_translated_panel_id' ) );
		}
	}

	function get_translated_panel_id( $id ) {
		return apply_filters( 'wpml_object_id', $id, 'page', true );
	}
}
