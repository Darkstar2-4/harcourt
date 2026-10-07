<?php

namespace WPML\Translation\TranslationElements;

class CompressionTracker {

	const OPTION_NAME = 'wpml_tm_last_compressed_job_id';

	public static function recordCompression( $job_id ) {
		if ( ! is_numeric( $job_id ) || $job_id <= 0 ) {
			return false;
		}

		$current = get_option( self::OPTION_NAME, null );

		if ( $current === null || $job_id > (int) $current['job_id'] ) {
			$data = [
				'job_id'    => (int) $job_id,
				'timestamp' => time(),
				'version'   => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : 'unknown',
			];

			return update_option( self::OPTION_NAME, $data, false );
		}

		return false;
	}

	public static function shouldShowMissingZlibError( $job_id ) {
		if ( ZlibAvailabilityChecker::isGzuncompressAvailable() ) {
			return false;
		}

		$tracked = get_option( self::OPTION_NAME, null );

		if ( $tracked === null ) {
			return false;
		}

		return (int) $job_id <= (int) $tracked['job_id'];
	}
}
