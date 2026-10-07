<?php


namespace WPML\FP;

use BadMethodCallException;
use Closure;

trait Curryable {
	protected static $curried = [];

	public static function curryN( $name, $argCount, callable $fn ) {
		static::$curried[ $name ] = [ $argCount, $fn ];
	}

	public static function hasCurry( $name ) {
		return isset( static::$curried[ $name ] );
	}

	public static function __callStatic( $method, $parameters ) {
		if ( ! static::hasCurry( $method ) ) {
			throw new BadMethodCallException( "Method {$method} does not exist." );
		}

		if ( static::$curried[ $method ][1] instanceof Closure ) {
			return call_user_func_array( self::curryItStaticCall( ...static::$curried[ $method ] ), $parameters );
		}

		return call_user_func_array( static::$curried[ $method ][1], $parameters );
	}

	public function __call( $method, $parameters ) {
		throw new BadMethodCallException( "Curryable does not support methods in object scope. This is a limitation of PHP 5.x." );
		if ( ! static::hasCurry( $method ) ) {
			throw new BadMethodCallException( "Method {$method} does not exist." );
		}

		if ( static::$curried[ $method ][1] instanceof Closure ) {
			return call_user_func_array( $this->curryItCall( ...self::$curried[ $method ] ), $parameters );
		}

		return call_user_func_array( static::$curried[ $method ][1], $parameters );
	}

	private function curryItCall( $count, Closure $fn ) {
		return curryN( $count, $fn->bindTo( $this, static::class ) );
	}

	private static function curryItStaticCall( $count, Closure $fn ) {
		return curryN( $count, Closure::bind( $fn, null, static::class ) );
	}

}
