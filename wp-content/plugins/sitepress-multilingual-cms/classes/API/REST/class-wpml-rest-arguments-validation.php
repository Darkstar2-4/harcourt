<?php

class WPML_REST_Arguments_Validation {

	static function boolean( $value ) {
		return null !== filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	static function integer( $value ) {
		return false !== filter_var( $value, FILTER_VALIDATE_INT );
	}

	static function float( $value ) {
		return false !== filter_var( $value, FILTER_VALIDATE_FLOAT );
	}

	static function url( $value ) {
		return false !== filter_var( $value, FILTER_VALIDATE_URL );
	}

	static function email( $value ) {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	static function is_array( $value ) {
		return is_array( $value );
	}

	static function date( $value ) {
		try {
			$d = new DateTime( $value );

			return $d && $d->format( 'Y-m-d' ) == $value;
		} catch ( Exception $e ) {
			return false;
		}
	}
}
