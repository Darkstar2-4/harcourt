<?php

namespace WPML\PB\Elementor;

class DataConvert {

	public static function serialize( $data, $escape = true ) {
		$data = wp_json_encode( $data );
		if ( $escape ) {
			$data = wp_slash( $data );
		}

		return $data;
	}

	public static function unserialize( $data, $associative = true ) {
		if ( self::isElementorArray( $data ) ) {
			return $data;
		}

		$value = is_array( $data ) ? $data[0] : $data;

		if ( self::isElementorArray( $value ) ) {
			return $value;
		}

		return self::unserializeString( $value, $associative );
	}

	private static function unserializeString( $string, $associative ) {
		return is_serialized( $string ) ? unserialize( $string ) : json_decode( $string, $associative );
	}

	private static function isElementorArray( $data ) {
		return is_array( $data ) && count( $data ) > 0 && isset( $data[0]['id'] );
	}
}
