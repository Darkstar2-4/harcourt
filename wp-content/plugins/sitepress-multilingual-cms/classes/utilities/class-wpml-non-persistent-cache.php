<?php

class WPML_Non_Persistent_Cache {

	private static $cache = array();

	public static function get( $key, $group = 'default', &$found = null ) {
		if (
			isset( self::$cache[ $group ] ) &&
			( isset( self::$cache[ $group ][ $key ] ) || array_key_exists( $key, self::$cache[ $group ] ) )
		) {
			$found = true;

			return self::$cache[ $group ][ $key ];
		}
		$found = false;

		return false;
	}

	public static function set( $key, $data, $group = 'default' ) {
		if ( is_object( $data ) ) {
			$data = clone $data;
		}
		self::$cache[ $group ][ $key ] = $data;

		return true;
	}

	public static function execute_and_cache( $key, $callback, $group = 'default' ) {
		$data = self::get( $key, $group, $found );
		if ( ! $found ) {
			$data = $callback();
			self::set( $key, $data, $group );
		}

		return $data;
	}

	public static function flush() {
		self::$cache = array();

		return true;
	}

	public static function flush_group( $groups = 'default' ) {
		$groups = (array) $groups;
		foreach ( $groups as $group ) {
			unset( self::$cache[ $group ] );
		}

		return true;
	}
}
