<?php

namespace WPML\ICLToATEMigration;

use WPML\FP\Obj;
use WPML\LIB\WP\Option;

class Data {

	const OPTION_KEY = 'wpml_icl_to_ate_migration';

	const MEMORY_MIGRATED = 'memory_migrated';
	const ICL_DEACTIVATED = 'icl-deactivated';
	const ICL_CREDENTIALS = 'icl-credentials';

	public static function setMemoryMigrated( $flag = true ) {
		self::save( self::MEMORY_MIGRATED, $flag );
	}

	public static function isMemoryMigrated() {
		return self::get( self::MEMORY_MIGRATED );
	}

	public static function setICLDeactivated( $flag = true ) {
		self::save( self::ICL_DEACTIVATED, $flag );
	}

	public static function isICLDeactivated() {
		return self::get( self::ICL_DEACTIVATED );
	}

	public static function saveICLCredentials( array $credentials ) {
		self::save( self::ICL_CREDENTIALS, $credentials );
	}

	public static function getICLCredentials() {
		return self::get( self::ICL_CREDENTIALS );
	}

	private static function save( $name, $value ) {
		$options          = Option::getOr( self::OPTION_KEY, [] );
		$options[ $name ] = $value;

		Option::update( self::OPTION_KEY, $options );
	}

	private static function get( $name ) {
		return Obj::propOr( false, $name, Option::getOr( self::OPTION_KEY, [] ) );
	}
}
