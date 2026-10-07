<?php


namespace WPML\TM\Editor;


use WPML\LIB\WP\Option;

class ATERetry {

	public static function hasFailed( $jobId ) {
		return self::getCount( $jobId ) >= 0;
	}

	public static function getCount( $jobId ) {
		return (int) Option::getOr( self::getOptionName( $jobId ), - 1 );
	}

	public static function incrementCount( $jobId ) {
		Option::update( self::getOptionName( $jobId ), self::getCount( $jobId ) + 1 );
	}

	public static function reset( $jobId ) {
		Option::delete( self::getOptionName( $jobId ) );
	}

	public static function getOptionName( $jobId ) {
		return sprintf( 'wpml-ate-job-retry-counter-%d', $jobId );
	}
}