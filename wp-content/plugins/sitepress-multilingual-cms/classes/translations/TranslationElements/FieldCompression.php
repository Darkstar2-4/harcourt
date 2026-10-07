<?php

namespace WPML\Translation\TranslationElements;

class FieldCompression {

	public static function isCompressed( $data ) {
		if ( $data === null || $data === '' || ! ZlibAvailabilityChecker::isGzuncompressAvailable() ) {
			return false;
		}

		$decoded = base64_decode( $data, true );
		if ( $decoded === false ) {
			return false;
		}

		$decompressed = @gzuncompress( $decoded );
		return $decompressed !== false;
	}

	public static function fixDoubleCompression( $data ) {
		if ( $data === null || $data === '' || ! ZlibAvailabilityChecker::isGzuncompressAvailable() ) {
			return [
				'data'                  => $data,
				'was_double_compressed' => false
			];
		}

		if ( ! self::isCompressed( $data ) ) {
			return [
				'data'                  => $data,
				'was_double_compressed' => false
			];
		}

		$decompressed_once = self::decompress( $data, true );

		if ( self::isCompressed( $decompressed_once ) ) {
			return [
				'data'                  => $decompressed_once,
				'was_double_compressed' => true
			];
		}

		return [
			'data'                  => $data,
			'was_double_compressed' => false
		];
	}

	public static function compress( $data, bool $isAlreadyBase64Compressed = true ) {
		if ( $data === null ) {
			return null;
		}

		if ( self::isCompressed( $data ) ) {
			return $data;
		}

		if ( ! ZlibAvailabilityChecker::isGzcompressAvailable() || $data === '' ) {
			return $isAlreadyBase64Compressed ? $data : base64_encode( $data );
		}

		$decoded = $isAlreadyBase64Compressed ? base64_decode( $data ) : $data;
		if ( $decoded === false ) {
			return $data;
		}

		$compressed = gzcompress( $decoded );
		if ( $compressed === false ) {
			return $data;
		}

		return base64_encode( $compressed );
	}

	public static function compressAndTrack( $data, bool $isAlreadyBase64Compressed = true, $job_id = null ) {
		$compressed = self::compress( $data, $isAlreadyBase64Compressed );

		if (
			$job_id !== null
			&& ZlibAvailabilityChecker::isGzcompressAvailable()
			&& $compressed !== $data
			&& $data !== null
			&& $data !== ''
		) {
			CompressionTracker::recordCompression( $job_id );
		}

		return $compressed;
	}

	public static function decompress( $data, bool $preserveBase64Encoding = false ) {
		if ( $data === null ) {
			return null;
		}

		if ( ! ZlibAvailabilityChecker::isGzuncompressAvailable() || $data === '' ) {
			return $preserveBase64Encoding ? $data : base64_decode( $data );
		}

		$decoded = base64_decode( $data );
		if ( $decoded === false ) {
			return $data;
		}

		$decompressed = @gzuncompress( $decoded );
		if ( $decompressed === false ) {
			return $preserveBase64Encoding ? $data : $decoded;
		}

		return $preserveBase64Encoding ? base64_encode( $decompressed ) : $decompressed;
	}
}