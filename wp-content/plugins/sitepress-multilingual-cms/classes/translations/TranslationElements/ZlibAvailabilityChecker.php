<?php

namespace WPML\Translation\TranslationElements;

class ZlibAvailabilityChecker {

	public static function isGzcompressAvailable() {
		if ( defined( 'WPML_SIMULATE_MISSING_ZLIB' ) && WPML_SIMULATE_MISSING_ZLIB === true ) {
			return false;
		}
		return function_exists( 'gzcompress' );
	}

	public static function isGzuncompressAvailable() {
		if ( defined( 'WPML_SIMULATE_MISSING_ZLIB' ) && WPML_SIMULATE_MISSING_ZLIB === true ) {
			return false;
		}
		return function_exists( 'gzuncompress' );
	}
}
