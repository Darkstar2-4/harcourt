<?php

namespace WPML\TM\ATE\API\CacheStorage;

interface Storage {
	public function get( $key, $default = null );

	public function save( $key, $value );

	public function delete( $key );
}