<?php

namespace WPML\WP;

use function WPML\FP\curryN;

class OptionManager {

	private $group_keys_key = 'WPML_Group_Keys';

	public function get( $group, $key, $default = false ) {
		$data = get_option( $this->get_key( $group ), array() );

		return isset( $data[ $key ] ) ? $data[ $key ] : $default;
	}

	public function set( $group, $key, $value, $autoload = true ) {
		$group_key = $this->get_key( $group );

		$data         = get_option( $group_key, array() );
		$data[ $key ] = $value;
		update_option( $group_key, $data, $autoload );

		$this->store_group_key( $group_key );
	}

	private function get_key( $group ) {
		return 'WPML(' . $group . ')';
	}

	private function store_group_key( $group_key ) {
		$group_keys   = get_option( $this->group_keys_key, array() );
		$group_keys[] = $group_key;
		update_option( $this->group_keys_key, array_unique( $group_keys ) );
	}

	public function reset_options( $options ) {
		$options[] = $this->group_keys_key;

		return array_merge( $options, get_option( $this->group_keys_key, array() ) );
	}

	public static function updateWithoutAutoLoad( $group = null, $key = null, $value = null ) {
		$update = function ( $group, $key, $value ) {
			( new OptionManager() )->set( $group, $key, $value, false );
		};

		return call_user_func_array( curryN( 3, $update ), func_get_args() );
	}

	public static function update( $group = null, $key = null, $value = null ) {
		return call_user_func_array( curryN( 3, [ new OptionManager(), 'set' ] ), func_get_args() );
	}

	public static function getOr( $default = null, $group = null, $key = null ) {
		$get = function ( $default, $group, $key ) {
			return ( new OptionManager() )->get( $group, $key, $default );
		};

		return call_user_func_array( curryN( 3, $get ), func_get_args() );
	}
}
