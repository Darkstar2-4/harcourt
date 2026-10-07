<?php

namespace OTGS\Installer\FP;

function partial( callable $function, $args ) {
	$args = array_slice( func_get_args(), 1 );

	return function () use ( $function, $args ) {
		return call_user_func_array( $function, array_merge( $args, func_get_args() ) );
	};
}

function pipe( callable $f, callable $g ) {
	return call_user_func_array( 'OTGS\Installer\FP\compose', array_reverse( func_get_args() ) );
}

function compose( callable $f, callable $g ) {
	$functions = func_get_args();

	return function () use ( $functions ) {
		$args = func_get_args();
		foreach ( array_reverse( $functions ) as $function ) {
			$args = array( call_user_func_array( $function, $args ) );
		}

		return current( $args );
	};
}


function flip( callable $fn ) {
	return function () use ( $fn ) {
		$args = func_get_args();
		if ( count( $args ) > 1 ) {
			$temp    = $args[0];
			$args[0] = $args[1];
			$args[1] = $temp;
		}

		return call_user_func_array( $fn, $args );
	};
}

function gatherArgs( callable $fn ) {
	return function ( ...$args ) use ( $fn ) {
		return $fn( $args );
	};
}

function invoke( $fnName ) {
	return new _Invoker( $fnName );
}

function curryN( $count, Callable $fn ) {
	$accumulator = function ( array $arguments ) use ( $count, $fn, &$accumulator ) {
		return function () use ( $count, $fn, $arguments, $accumulator ) {
			$oldArguments = $arguments;
			$arguments    = array_merge( $arguments, func_get_args() );

			$replacementIndex = count( $oldArguments );
			for ( $i = 0; $i < $replacementIndex; $i ++ ) {
				if ( count( $arguments ) <= $replacementIndex ) {
					break;
				}
				if ( $arguments[ $i ] === Fns::__ ) {
					$arguments[ $i ] = $arguments[ $replacementIndex ];
					unset( $arguments[ $replacementIndex ] );
					$arguments = array_values( $arguments );
				}
			}

			if ( ! in_array( Fns::__, $arguments, true ) && $count <= count( $arguments ) ) {
				return call_user_func_array( $fn, $arguments );
			}

			return $accumulator( $arguments );
		};
	};

	return $accumulator( [] );
}

function tryCatch( callable $fn ) {
	try {
		return Right::of( $fn() );
	} catch ( \Exception $e ) {
		return Either::left( $e );
	}
}