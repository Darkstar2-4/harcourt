<?php

namespace WPML\TM\ATE\API\CacheStorage;

use WPML\FP\Obj;

class StaticVariable implements Storage {
	private static $cache = [];

	private static $instance;

	public static function getInstance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function get( $key, $default = null ) {
		return Obj::propOr( $default, $key, self::$cache );
	}

	public function save( $key, $value ) {
		self::$cache[ $key ] = $value;
	}

	public function delete( $key ) {
		self::$cache = [];
	}
}