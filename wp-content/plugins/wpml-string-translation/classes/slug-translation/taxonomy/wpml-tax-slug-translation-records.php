<?php

class WPML_Tax_Slug_Translation_Records extends WPML_Slug_Translation_Records {

	const STRING_NAME = 'URL %s tax slug';

	protected function get_string_name( $slug ) {
		return sprintf( self::STRING_NAME, $slug );
	}

	protected function get_element_type() {
		return 'taxonomy';
	}
}
