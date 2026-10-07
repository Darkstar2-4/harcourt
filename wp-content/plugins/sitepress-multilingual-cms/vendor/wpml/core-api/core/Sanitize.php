<?php

namespace WPML\API;

class Sanitize {
	public static function string( $value, $flags = ENT_QUOTES ) {
		return is_string( $value ) || is_numeric( $value )
			? str_replace( '&amp;', '&', htmlspecialchars( strip_tags( $value ), $flags ) ) : false;
	}

	public static function stringProp( $property, $arr, $flags = ENT_QUOTES ) {
		return isset( $arr[ $property ] ) ? self::string( $arr[ $property ], $flags ) : null;
	}
}