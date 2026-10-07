<?php

namespace OTGS\Installer;

class Collection {
	private $array;

	protected $items = [];

	private function __construct( array $array ) {
		$this->array = $array;
	}

	public static function of( array $array ) {
		return new static( $array );
	}

	public function filter( callable $fn ) {
		return self::of( array_filter( $this->array, $fn ) );
	}

	public function map( callable $fn ) {
		$keys = array_keys( $this->array );

		$items = array_map( $fn, $this->array, $keys );

		$combined = array_combine( $keys, $items );

		return self::of( false !== $combined ? $combined : [] );
	}

	public function entities() {
		$toPairs = function ( $value, $key ) {
			return [ $key, $value ];
		};

		return $this->map( $toPairs );
	}

	public function pluck( $column ) {
		return self::of( array_column( $this->array, $column ) );
	}

	public function reduce( callable $fn, $initial = 0 ) {
		return array_reduce( $this->array, $fn, $initial );
	}

	public function values() {
		return self::of( array_values( $this->array ) );
	}

	public function mergeRecursive( array $other ) {
		return self::of( array_merge_recursive( $this->array, $other ) );
	}

	public function get( $key = null ) {
		if ( null !== $key ) {
			$data = array_key_exists( $key, $this->array ) ? $this->array[ $key ] : null;
			if ( is_array( $data ) ) {
				return self::of( $data );
			} elseif ( is_null( $data ) ) {
				return new NullCollection();
			}

			return $data;
		}

		return $this->array;
	}

	public function getOrNull( $key = null ) {
		return $this->get( $key );
	}

	public function contains( $value ) {
		return in_array( $value, $this->array, true );
	}

	public function head() {
		if ( count( $this->array ) ) {
			$temp   = array_values( $this->array );
			$result = $temp[0];
			if ( is_array( $result ) ) {
				return self::of( $result );
			}

			return $result;
		}

		return new NullCollection();
	}

	public function offsetExists( $key ) {
		return array_key_exists( $key, $this->items );
	}

	public function has( $key ) {
		return $this->offsetExists( $key );
	}

	public function any( callable $fn ) {
		return $this->filter( $fn )->count() > 0;
	}

	public function count() {
		return count( $this->array );
	}

	public function firstIndex( callable $fn ) {
		foreach ( $this->array as $index=>$item ) {
			if ( $fn( $item ) ) {
				return $index;
			}
		}
		return -1;

	}
}

class NullCollection {

	public function map( callable $fn ) {
		return $this;
	}

	public function filter( callable $fn ) {
		return $this;
	}

	public function head() {
		return $this;
	}

	public function pluck() {
		return $this;
	}

	public function get() {
		return $this;
	}

	public function getOrNull() {
		return null;
	}

}
