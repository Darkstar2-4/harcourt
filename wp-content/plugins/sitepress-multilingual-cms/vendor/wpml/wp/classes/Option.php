<?php

namespace WPML\LIB\WP;

use WPML\Collect\Support\Traits\Macroable;
use function WPML\FP\curryN;
use function WPML\FP\partialRight;

class Option {
	use Macroable;

	public static function init() {
		self::macro( 'get', curryN( 1, 'get_option' ) );
		self::macro( 'getOr', curryN( 2, 'get_option' ) );

		self::macro( 'update', curryN( 2, 'update_option' ) );
		self::macro( 'updateWithoutAutoLoad', curryN( 2, partialRight( 'update_option', false ) ) );

		self::macro( 'delete', curryN( 1, 'delete_option' ) );

		self::macro( 'getRaw', curryN( 1, function( $name ) {
			global $wpdb;
			$p = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name );
			return $wpdb->get_var( $p ) ?: null;
		} ) );

		self::macro( 'attemptSerializedRecovery', curryN( 1, function( $name, $default ) {
			$value = $default;
			$dbValue = self::getRaw( $name );
			if ( $dbValue && is_serialized( $dbValue ) ) {
				$dbValueRecoveringStringIntegrityChecks = preg_replace_callback(
					'/(?<=^|\{|;)s:(\d+):[\"|\'](.*?)[\"|\'];(?=[asbdiO]\:\d|N;|\}|$)/s',
					function($m){
						return 's:' . strlen($m[2]) . ':"' . $m[2] . '";';
					},
					$dbValue
				);
				$restoredValue = maybe_unserialize( $dbValueRecoveringStringIntegrityChecks );
				if ( ( !is_string($restoredValue) || $dbValueRecoveringStringIntegrityChecks != $restoredValue ) && $restoredValue !== false ) {
					$value = $restoredValue;
				}
				self::update( $name, $value );
			}
			return $value;
		} ) );
	}

	public static function getOrAttemptRecovery( $name = null, $default = null ) {
		return call_user_func_array( curryN( 2,
			function( $name, $default ) {
				$value = self::get( $name );
				if ( $value === false ) {
					return self::attemptSerializedRecovery($name, $default);
				}
				return $value;
			}), func_get_args() );
	}
}

Option::init();
