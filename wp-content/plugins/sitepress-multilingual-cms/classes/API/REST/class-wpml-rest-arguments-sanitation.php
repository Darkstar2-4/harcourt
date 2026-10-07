<?php

use WPML\API\Sanitize;

class WPML_REST_Arguments_Sanitation {

	static function boolean( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	static function integer( $value ) {
		return (int) self::float( $value );
	}

	static function float( $value ) {
		return (float) filter_var( $value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
	}

	static function string( $value ) {
		return Sanitize::string( $value );
	}

	static function url( $value ) {
		return filter_var( $value, FILTER_SANITIZE_URL );
	}

	static function email( $value ) {
		return filter_var( $value, FILTER_SANITIZE_EMAIL );
	}

	static function array_of_integers( $value ) {
		return array_map( 'intval', $value );
	}
}
