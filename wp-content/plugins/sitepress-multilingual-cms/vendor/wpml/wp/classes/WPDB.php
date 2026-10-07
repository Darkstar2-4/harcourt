<?php

namespace WPML\LIB\WP;

class WPDB {

	public static function withoutError( callable $func ) {
		global $wpdb;

		$originalSuppressErrors = $wpdb->suppress_errors;
		$wpdb->suppress_errors( true );
		$result = $func();
		$wpdb->suppress_errors( $originalSuppressErrors );
		return $result;
	}
}
