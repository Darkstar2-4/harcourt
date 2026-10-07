<?php

namespace WPML\LIB\WP;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Fns;
use WPML\FP\Just;
use WPML\FP\Maybe;
use WPML\FP\Nothing;
use function WPML\FP\curryN;

class Cache {

	const KEYS = 'WPML_WP_Cache__group_keys';

	use Macroable;

	public static function init() {
		self::macro( 'get', curryN( 2, [ self::class, 'getInternal' ] ) );

		self::macro( 'set', curryN( 4, function ( $group, $key, $expire, $value ) {
			$keys = self::getKeysInGroup( $group, true );
			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
				\wp_cache_set( $group, [ 'data' => $keys ], self::KEYS );
			}

			return \wp_cache_set( $key, [ 'data' => $value ], $group, $expire );
		} ) );

		self::macro( 'memorizeWithCheck', curryN( 4, function ( $group, $checkingFn, $expire, $fn ) {
			return function () use ( $fn, $group, $checkingFn, $expire ) {
				$args = func_get_args();
				$key = self::_buildKeyForFunctionArguments( $args );

				$result = Cache::get( $group, $key );
				if ( Fns::isNothing( $result ) || ! $checkingFn( $result->get() ) ) {
					$result = call_user_func_array( $fn, $args );
					Cache::set( $group, $key, $expire, $result );

					return $result;
				}

				return $result->get();
			};
		} ) );

		self::macro( 'memorize', self::memorizeWithCheck( Fns::__, Fns::always( true ), Fns::__, Fns::__ ) );

	}

	public static function getInternal( $group, $key, $force = false ) {
		$found  = false;
		$result = wp_cache_get( $key, $group, $force, $found );

		if ( $found && is_array( $result ) && array_key_exists( 'data', $result ) ) {
			return Maybe::just( $result['data'] );
		}

		return Maybe::nothing();
	}

	public static function flushGroup( $group, $force = false ) {
		$keys = self::getKeysInGroup( $group, $force );

		foreach ( $keys as $key ) {
			wp_cache_delete( $key, $group );
		}

		wp_cache_delete( $group, self::KEYS );
	}

	public static function clearMemoizedFunction( $group, ...$functionArgs ) {
		self::delete( $group, self::_buildKeyForFunctionArguments( $functionArgs ) );
	}

	public static function delete( $group, $key ) {
		wp_cache_delete( $key, $group );

		$keys = self::getKeysInGroup( $group, true );
		$keys = array_values( array_diff( $keys, [ $key ] ) );
		wp_cache_set( $group, [ 'data' => $keys ], self::KEYS );
	}

	public static function getKeysInGroup( $group, $force = false ) {
		return self::getInternal( self::KEYS, $group, $force )->getOrElse( [] );
	}

	public static function _buildKeyForFunctionArguments( array $args ) {
		return serialize( $args );
	}
}

Cache::init();
