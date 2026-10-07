<?php

class WPML_TM_Post_Data {

	public static function strip_slashes_for_single_quote( $data ) {
		return str_replace( '\\\'', '\'', $data );
	}

}