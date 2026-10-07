<?php

class WPML_WP_Cache {

	const KEYS = 'WPML_WP_Cache__group_keys';

	private $group;

	public function __construct( $group = '' ) {
		$this->group = $group;
	}

	public function get( $key, &$found = null ) {
		$value = wp_cache_get( $key, $this->group, false, $found );
		if ( is_array( $value ) && array_key_exists( 'data', $value ) ) {
			$found = true;

			return $value['data'];
		} else {
			$found = false;

			return $value;
		}
	}

	public function set( $key, $data, $expire = 0 ) {
		$keys = $this->get_keys( true );
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			wp_cache_set( self::KEYS, $keys, $this->group );
		}

		return wp_cache_set( $key, [ 'data' => $data ], $this->group, $expire );
	}

	public function flush_group_cache( $force = false ) {
		$keys = $this->get_keys( $force );

		foreach ( $keys as $cache_key ) {
			wp_cache_delete( $cache_key, $this->group );
		}

		wp_cache_delete( self::KEYS, $this->group );
	}

	public function execute_and_cache( $key, $callback ) {
		list( $result, $found ) = $this->get_with_found( $key );
		if ( ! $found ) {
			$result = $callback();
			$this->set( $key, $result );
		}

		return $result;
	}

	public function get_with_found( $key ) {
		$found  = false;
		$result = $this->get( $key, $found );

		return [ $result, $found ];
	}

	private function get_keys( $force = false ) {
		$found = false;
		$keys  = wp_cache_get( self::KEYS, $this->group, $force, $found );
		if ( $found && is_array( $keys ) ) {
			return $keys;
		}

		return [];
	}
}
