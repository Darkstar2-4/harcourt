<?php

class WPML_WP_Cache_Item {

	private $key;

	private $cache;

	public function __construct( WPML_WP_Cache $cache, $key ) {
		if ( is_array( $key ) ) {
			$key = md5( (string) json_encode( $key ) );
		}
		$this->cache = $cache;
		$this->key   = $key;
	}

	public function exists() {

		$found = false;
		$this->cache->get( $this->key, $found );
		return $found;
	}

	public function get() {
		$found = false;
		return $this->cache->get( $this->key, $found );
	}

	public function set( $value ) {
		$this->cache->set( $this->key, $value );
	}

}
