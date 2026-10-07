<?php

namespace WPML\FP;
use WPML\Collect\Support\Traits\Macroable;

class FP {

	use Macroable;

	public static function init(){
		self::macro( 'map', curryN(2, function( $fn, $target ){
			if ( is_object( $target ) ) {
				return $target->map( $fn );
			}
			if ( is_array( $target ) ) {
				return array_map( $fn, $target );
			}
			throw( new \InvalidArgumentException( 'target should be an object with map method or an array' ) );
		}));

		self::macro( 'identity', curryN( 1, function( $value ) { return $value; }));

		self::macro( 'always', curryN( 2, function ( $value, $_ ) { return $value; } ) );

		self::macro( 'reduce', curryN( 3, function( $fn, $initial, $target) {
			if ( is_object( $target ) ) {
				return $target->reduce( $fn, $initial );
			}
			if ( is_array( $target ) ) {
				return array_reduce( $target, $fn, $initial );
			}
			throw( new \InvalidArgumentException( 'target should be an object with reduce method or an array' ) );
		} ));

		self::macro( 'converge', curryN( 3, function( $convergingFn, array $branchingFns, $data ) {
			$apply = function ( $fn ) use ( $data ) { return $fn( $data ); };
			return call_user_func_array( $convergingFn, self::map( $apply, $branchingFns ) );
		}));
	}
}

FP::init();
