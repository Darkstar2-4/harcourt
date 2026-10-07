<?php

class WPML_PHP_Functions {

	public function defined( $constant_name ) {
		return defined( $constant_name );
	}

	public function constant( $constant_name ) {
		return $this->defined( $constant_name ) ? constant( $constant_name ) : null;
	}

	public function function_exists( $function_name ) {
		return function_exists( $function_name );
	}

	public function class_exists( $class_name, $autoload = true ) {
		return class_exists( $class_name, $autoload );
	}

	public function extension_loaded( $name ) {
		return extension_loaded( $name );
	}

	public function mb_strtolower( $string ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $string );
		}

		return strtolower( $string );
	}

	public function phpversion( $extension = null ) {
		if ( defined( 'PHP_VERSION' ) ) {
			return PHP_VERSION;
		} else {
			return phpversion( $extension );
		}
	}

	public function version_compare( $version1, $version2, $operator = null ) {
		return version_compare( $version1 ?: '0.0.0', $version2 ?: '0.0.0', $operator );
	}

	public function array_unique( $array, $sort_flags = SORT_REGULAR ) {
		return wpml_array_unique( $array, $sort_flags );
	}

	public function error_log( $message, $message_type = 0, $destination = null, $extra_headers = null ) {
		return error_log( $message, $message_type, $destination, $extra_headers );
	}

	public function exit_php() {
		exit();
	}
}
