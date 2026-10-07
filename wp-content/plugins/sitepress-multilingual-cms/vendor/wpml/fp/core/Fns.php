<?php

namespace WPML\FP;

use WPML\Collect\Support\Traits\Macroable;

class Fns {

	use Macroable;

	const __ = '__CURRIED_PLACEHOLDER__';

	public static function init() {
		self::macro( 'always', function ( $value ) {
			return function () use ( $value ) { return $value; };
		} );

		self::macro( 'converge', curryN( 2, function ( $convergingFn, array $branchingFns ) {
			return function ( $data ) use ( $convergingFn, $branchingFns ) {
				$apply = function ( $fn ) use ( $data ) { return $fn( $data ); };

				return call_user_func_array( $convergingFn, self::map( $apply, $branchingFns ) );
			};
		} ) );

		self::macro( 'map', curryN( 2, function ( $fn, $target ) {
			if ( ! Logic::isMappable( $target ) ) {
				throw( new \InvalidArgumentException( 'target should be an object with map method or an array' ) );
			}

			if ( is_object( $target ) ) {
				return $target->map( $fn );
			} else {
				$keys = array_keys( $target );

				return array_combine( $keys, array_map( $fn, $target, $keys ) );
			}
		} ) );

		self::macro( 'each', curryN( 2, function ( $fn, $target ) {
			return self::map( self::tap( $fn ), $target );
		} ) );

		self::macro( 'identity', curryN( 1, function ( $value ) { return $value; } ) );

		self::macro( 'tap', curryN( 2, function ( $fn, $value ) {
			$fn( $value );

			return $value;
		} ) );

		self::macro( 'reduce', curryN( 3, function ( $fn, $initial, $target ) {
			if ( is_object( $target ) ) {
				return $target->reduce( $fn, $initial );
			}
			if ( is_array( $target ) ) {
				return array_reduce( $target, $fn, $initial );
			}
			throw( new \InvalidArgumentException( 'target should be an object with reduce method or an array' ) );
		} ) );

		self::macro( 'reduceRight', curryN( 3, function ( $fn, $initial, $target ) {
			if ( is_object( $target ) ) {
				return $target->reverse()->reduce( $fn, $initial );
			}
			if ( is_array( $target ) ) {
				return array_reduce( array_reverse( $target ), $fn, $initial );
			}
			throw( new \InvalidArgumentException( 'target should be an object with reduce method or an array' ) );
		} ) );

		self::macro( 'filter', curryN( 2, function ( $predicate, $target ) {
			if ( is_object( $target ) ) {
				return $target->filter( $predicate );
			}
			if ( is_array( $target ) ) {
				return array_values( array_filter( $target, $predicate ) );
			}
			throw( new \InvalidArgumentException( 'target should be an object with filter method or an array' ) );
		} ) );

		self::macro( 'reject', curryN( 2, function ( $predicate, $target ) {
			return self::filter( pipe( $predicate, Logic::not() ), $target );
		} ) );

		self::macro( 'value', curryN( 1, function ( $value ) {
			return is_callable( $value ) ? $value() : $value;
		} ) );

		self::macro( 'constructN', curryN( 2, function ( $argCount, $className ) {
			$maker = function () use ( $className ) {
				$args = func_get_args();

				return new $className( ...$args );
			};

			return curryN( $argCount, $maker );
		} ) );

		self::macro( 'ascend', curryN( 3, function ( $fn, $a, $b ) {
			$aa = $fn( $a );
			$bb = $fn( $b );

			return $aa < $bb ? - 1 : ( $aa > $bb ? 1 : 0 );
		} ) );

		self::macro( 'descend', curryN( 3, function ( $fn, $a, $b ) {
			return self::ascend( $fn, $b, $a );
		} ) );

		self::macro( 'useWith', curryN( 2, function ( $fn, $transformations ) {
			return curryN( count( $transformations ), function () use ( $fn, $transformations ) {
				$apply = function ( $arg, $transform ) {
					return $transform( $arg );
				};

				$args = Fns::map( spreadArgs( $apply ), Lst::zip( func_get_args(), $transformations ) );

				return $fn( ...$args );
			} );
		} ) );

		self::macro( 'nthArg', curryN( 1, function ( $n ) {
			return function () use ( $n ) {
				return Lst::nth( $n, func_get_args() );
			};
		} ) );

		self::macro( 'either', curryN( 3, function ( callable $f, callable $g, Either $e ) {
			if ( $e instanceof Left ) {
				return $e->orElse( $f )->get();
			}

			return $e->map( $g )->get();
		} ) );

		self::macro( 'maybe', curryN( 3, function ( $v, callable $f, Maybe $m ) {
			if ( $m->isNothing() ) {
				return $v;
			}

			return $m->map( $f )->get();
		} ) );

		self::macro( 'isRight', curryN( 1, function ( $e ) {
			return $e instanceof Right;
		} ) );

		self::macro( 'isLeft', curryN( 1, function ( $e ) {
			return $e instanceof Left;
		} ) );

		self::macro( 'isJust', curryN( 1, function ( $m ) {
			return $m instanceof Just;
		} ) );

		self::macro( 'isNothing', curryN( 1, function ( $m ) {
			return $m instanceof Nothing;
		} ) );

		self::macro( 'safe', curryN( 1, function ( $fn ) {
			return pipe( $fn, Maybe::fromNullable() );
		} ) );

		self::macro( 'make', curryN( 1, function ( $className ) {
			return \WPML\Container\make( $className );
		} ) );

		self::macro( 'makeN', curryN( 2, function ( $argCount, $className ) {
			$maker = spreadArgs( curryN( $argCount, function () use ( $className ) {
				return \WPML\Container\make( $className, func_get_args() );
			} ) );

			return $maker( Lst::drop( 2, func_get_args() ) );
		} ) );

		self::macro( 'unary', curryN( 1, function ( $fn ) {
			return function ( $arg ) use ( $fn ) {
				return $fn( $arg );
			};
		} ) );

		self::macro( 'memorizeWith', curryN( 2, function ( $cacheKeyFn, $fn ) {
			return function () use ( $cacheKeyFn, $fn ) {
				static $cache = [];

				$args = func_get_args();
				$key  = call_user_func_array( $cacheKeyFn, $args );
				if ( array_key_exists( $key, $cache ) ) {
					return $cache[ $key ];
				}

				$result        = call_user_func_array( $fn, $args );
				$cache[ $key ] = $result;

				return $result;
			};
		} ) );

		self::macro(
			'memorize',
			self::memorizeWith( gatherArgs( pipe( Fns::map( 'json_encode'), Lst::join('|') ) ) )
		);

		self::macro( 'once', curryN( 1, function ( $fn ) {
			return function () use ( $fn ) {
				static $result = [];
				if ( array_key_exists( 'data', $result ) ) {
					return $result['data'];
				}
				$result['data'] = call_user_func_array( $fn, func_get_args() );

				return $result['data'];
			};
		} ) );

		self::macro( 'withNamedLock', curryN( 3, function ( $name, $returnFn, $fn ) {
			static $inProgress = [];

			return function () use ( &$inProgress, $name, $returnFn, $fn ) {

				$args = func_get_args();
				if ( Obj::prop( $name, $inProgress ) ) {
					return call_user_func_array( $returnFn, $args );
				}
				$inProgress[ $name ] = true;
				$result              = call_user_func_array( $fn, $args );
				$inProgress[ $name ] = false;

				return $result;
			};
		} ) );

		self::macro( 'withoutRecursion', curryN( 2, function ( $returnFn, $fn ) {
			return function () use ( $returnFn, $fn ) {
				static $inProgress = false;

				$args = func_get_args();
				if ( $inProgress ) {
					return call_user_func_array( $returnFn, $args );
				}
				$inProgress = true;
				$result     = call_user_func_array( $fn, $args );
				$inProgress = false;

				return $result;
			};
		} ) );

		self::macro( 'liftA2', curryN( 3, function ( $fn, $monadA, $monadB ) {
			return $monadA->map( $fn )->ap( $monadB );
		} ) );

		self::macro( 'liftA3', curryN( 4, function ( $fn, $monadA, $monadB, $monadC ) {
			return $monadA->map( $fn )->ap( $monadB )->ap( $monadC );
		} ) );

		self::macro( 'liftN', function ( $n, $fn ) {
			$liftedFn = curryN( $n, function () use ( $n, $fn ) {
				$args   = func_get_args();
				$result = $args[0]->map( curryN( $n, $fn ) );

				return Fns::reduce(
					function ( $result, $monad ) { return $result->ap( $monad ); },
					$result,
					Lst::drop( 1, $args )
				);
			} );

			return call_user_func_array( $liftedFn, Lst::drop( 2, func_get_args() ) );
		} );


		self::macro( 'until', curryN( 3, function ( $predicate, array $fns, ...$args ) {
			foreach ( $fns as $fn ) {
				$result = $fn( ...$args );
				if ( $predicate( $result ) ) {
					return $result;
				}
			}

			return null;
		} ) );
	}

	public static function noop() {
		return function () { };
	}

	public static function maybeToEither( $or = null, $maybe = null ) {
		$toEither = function ( $or, Maybe $maybe ) {
			return self::isJust( $maybe ) ? Either::right( $maybe->getOrElse( null ) ) : Either::left( $or );
		};

		return call_user_func_array( curryN( 2, $toEither ), func_get_args() );
	}
}

Fns::init();
