<?php

namespace WPML\FP;

use WPML\Collect\Support\Collection;
use WPML\Collect\Support\Traits\Macroable;
use WPML\Collect\Support\Arr;

class Lst {

	use Macroable;

	public static function init() {

		self::macro( 'append', curryN( 2, function ( $newItem, array $data ) {
			$data[] = $newItem;

			return $data;
		} ) );

		self::macro( 'fromPairs', curryN( 1, function ( array $data ) {
			$fromPair = function ( array $result, array $pair ) {
				$result[ $pair[0] ] = $pair[1];

				return $result;
			};

			return Fns::reduce( $fromPair, [], $data );
		} ) );

		self::macro( 'toObj', curryN( 1, function ( array $data ) { return (object) $data; } ) );

		self::macro( 'pluck', curryN( 2, function ( $prop, $data ) {
			return Fns::map( Obj::prop( $prop ), $data );
		} ) );

		self::macro( 'partition', curryN( 2, function ( $predicate, $data ) {
			return [ Fns::filter( $predicate, $data ), Fns::reject( $predicate, $data ) ];
		} ) );

		self::macro( 'sort', curryN( 2, function ( $compare, $data ) {
			if ( $data instanceof Collection ) {
				return wpml_collect( self::sort( $compare, $data->toArray() ) );
			}
			$intCompare = function ( $a, $b ) use ( $compare ) {
				return (int) $compare( $a, $b );
			};
			usort( $data, $intCompare );

			return $data;
		} ) );

		self::macro( 'unfold', curryN( 2, function ( $fn, $seed ) {
			$result = [];
			do {
				$iteratorResult = $fn( $seed );
				if ( is_array( $iteratorResult ) ) {
					$result[] = $iteratorResult[0];
					$seed     = $iteratorResult[1];
				}
			} while ( $iteratorResult !== false );

			return $result;

		} ) );

		self::macro( 'zip', curryN( 2, function ( $a, $b ) {
			$result = [];
			for ( $i = 0; $i < min( count( $a ), count( $b ) ); $i ++ ) {
				$result[] = [ $a[ $i ], $b[ $i ] ];
			}

			return $result;
		} ) );

		self::macro( 'zipObj', curryN( 2, function ( $a, $b ) {
			$result = [];
			for ( $i = 0; $i < min( count( $a ), count( $b ) ); $i ++ ) {
				$result[ $a[ $i ] ] = $b[ $i ];
			}

			return $result;
		} ) );

		self::macro( 'zipWith', curryN( 3, function ( $fn, $a, $b ) {
			$result = [];
			for ( $i = 0; $i < min( count( $a ), count( $b ) ); $i ++ ) {
				$result[] = $fn( $a[ $i ], $b[ $i ] );
			}

			return $result;
		} ) );

		self::macro( 'join', curryN( 2, 'implode' ) );

		self::macro( 'joinWithCommasAndAnd', curryN( 1, function ( $array ) {
			$last = Lst::last( $array );
			if ( $last ) {
				if ( Lst::length( $array ) > 1 ) {
					return str_replace( '  ', ' ', Lst::join( ', ', Lst::dropLast( 1, $array ) ) . ' ' . __( ' and ', 'sitepress' ) . ' ' . $last );
				} else {
					return $last;
				}
			} else {
				return '';
			}
		} ) );

		self::macro( 'concat', curryN( 2, 'array_merge' ) );

		self::macro( 'find', curryN( 2, function ( $predicate, $array ) {
			foreach ( $array as $value ) {
				if ( $predicate( $value ) ) {
					return $value;
				}
			}

			return null;
		} ) );

		self::macro( 'flattenToDepth', curryN( 2, flip( Arr::class . '::flatten' ) ) );

		self::macro( 'flatten', curryN( 1, Arr::class . '::flatten' ) );

		self::macro( 'includes', curryN( 2, function ( $val, $array ) {
			return in_array( $val, $array, true );
		} ) );

		self::macro( 'includesAll', curryN( 2, function ( $values, $array ) {
			foreach ( $values as $val ) {
				if ( ! in_array( $val, $array, true ) ) {
					return false;
				}
			}
			return true;
		} ) );

		self::macro( 'nth', curryN( 2, function ( $n, $array ) {
			$count = count( $array );
			if ( $n < 0 ) {
				$n += $count;
			}

			return $n >= 0 && $n < $count ? $array[ $n ] : null;

		} ) );

		self::macro( 'first', self::nth( 0 ) );

		self::macro( 'last', self::nth( - 1 ) );

		self::macro( 'length', curryN( 1, 'count' ) );

		self::macro( 'take', curryN( 2, function ( $n, $array ) {
			return array_slice( $array, 0, $n );
		} ) );

		self::macro( 'takeLast', curryN( 2, function ( $n, $array ) {
			return array_slice( $array, - $n, $n );
		} ) );

		self::macro( 'slice', curryN( 3, function ( $offset, $limit, $array ) {
			return array_slice( $array, $offset, $limit );
		} ) );

		self::macro( 'drop', curryN( 2, self::slice( Fns::__, null ) ) );

		self::macro( 'dropLast', curryN( 2, function ( $n, $array ) {
			$len = count( $array );

			return self::take( $n < $len ? $len - $n : 0, $array );
		} ) );

		self::macro( 'makePair', curryN( 2, function ( $a, $b ) {
			return [ $a, $b ];
		} ) );

		self::macro( 'make', curryN( 1, function ( ...$args ) {
			return $args;
		} ) );

		self::macro( 'insert', curryN( 3, function ( $index, $v, $array ) {
			$values = array_values( $array );

			array_splice( $values, $index, 0, [ $v ] );

			return $values;
		} ) );

		self::macro( 'range', curryN( 2, 'range' ) );

		self::macro( 'xprod', curryN( 2, function ( $a, $b ) {
			$result = [];
			foreach ( $a as $el1 ) {
				foreach ( $b as $el2 ) {
					$result[] = [ $el1, $el2 ];
				}
			}

			return $result;
		} ) );

		self::macro( 'prepend', Lst::insert( 0 ) );

		self::macro( 'reverse', curryN( 1, 'array_reverse' ) );
	}

	public static function keyBy( $key = null, $array = null ) {
		$keyBy = function ( $key, $array ) {
			$apply = Fns::converge( Lst::zipObj(), [ Lst::pluck( $key ), Fns::identity() ] );

			return $apply( $array );
		};

		return call_user_func_array( curryN( 2, $keyBy ), func_get_args() );
	}

	public static function keyWith( $key = null, $array = null ) {
		$keyWith = function ( $key, $array ) {
			return Fns::map( function ( $item ) use ( $key ) {
				return [ $key => $item ];
			}, $array );
		};

		return call_user_func_array( curryN( 2, $keyWith ), func_get_args() );
	}

	public static function diff( $array1 = null, $array2 = null ) {
		$diff = function( $array1, $array2){
			if ( is_object( $array1)) {
				return $array1->diff($array2);
			} else {
				return array_diff( $array1, $array2 );
			}
		};
		return call_user_func_array( curryN(2, $diff), func_get_args());
	}

	public static function repeat( $val = null, $times = null ) {
		$repeat = flip( partial( 'array_fill', 0 ) );

		return call_user_func_array( curryN( 2, $repeat ), func_get_args() );
	}

	public static function sum( $param = null ) {
		$sum = function ( $param ) {
			return is_object( $param ) ? $param->sum() : array_sum( $param );
		};

		return call_user_func_array( curryN( 1, $sum ), func_get_args() );
	}
}

Lst::init();
