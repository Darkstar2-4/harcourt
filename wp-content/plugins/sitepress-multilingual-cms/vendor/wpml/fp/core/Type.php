<?php

namespace WPML\FP;

use WPML\Collect\Support\Traits\Macroable;

class Type {

	use Macroable;

	public static function init() {
		self::macro( 'isNull', curryN( 1, 'is_null' ) );
		self::macro( 'isBool', curryN( 1, 'is_bool' ) );
		self::macro( 'isInt', curryN( 1, 'is_int' ) );
		self::macro( 'isNumeric', curryN( 1, 'is_numeric' ) );
		self::macro( 'isFloat', curryN( 1, 'is_float' ) );
		self::macro( 'isString', curryN( 1, 'is_string' ) );
		self::macro( 'isScalar', curryN( 1, 'is_scalar' ) );
		self::macro( 'isArray', curryN( 1, 'is_array' ) );
		self::macro( 'isObject', curryN( 1, 'is_object' ) );
		self::macro( 'isCallable', curryN( 1, 'is_callable' ) );

		self::macro( 'isSerialized', curryN( 1, function( $data ) {
			if ( ! is_string( $data ) ) {
				return false;
			}
			$data = trim( $data );
			if ( 'N;' === $data ) {
				return true;
			}
			if ( strlen( $data ) < 4 ) {
				return false;
			}
			if ( ':' !== $data[1] ) {
				return false;
			}

			$lastc = substr( $data, -1 );
			if ( ';' !== $lastc && '}' !== $lastc ) {
				return false;
			}

			$token = $data[0];
			switch ( $token ) {
				case 's':
					if ( '"' !== substr( $data, -2, 1 ) ) {
						return false;
					}
				case 'a':
				case 'O':
					return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
				case 'b':
				case 'i':
				case 'd':
					return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$/", $data );
			}
			return false;
		} ) );

		self::macro( 'isJson', curryN( 1, function( $value ) {
			if ( ! $value || ! is_string( $value ) ) {
				return false;
			}

			if ( ! in_array( $value[0], [ '{', '[' ], true ) ) {
				return false;
			}

			json_decode( $value, true );

			return json_last_error() === JSON_ERROR_NONE;
		} ) );
	}
}

Type::init();
