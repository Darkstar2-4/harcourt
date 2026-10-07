<?php

namespace WPML\FP;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Functor\Functor;
use WPML\FP\Functor\Pointed;

abstract class Either {
	use Functor;
	use Macroable;

	public static function init() {
		self::macro( 'of', Right::of() );

		self::macro( 'left', Left::of() );

		self::macro( 'right', Right::of() );

		self::macro( 'fromNullable', curryN( 1, function ( $value ) {
			return is_null( $value ) ? self::left( $value ) : self::right( $value );
		} ) );

		self::macro( 'fromBool', curryN( 1, function ( $value ) {
			return (bool) $value ? self::right( $value ) : self::left( $value );
		} ) );

	}

	public function join() {
		if ( ! $this->value instanceof Either ) {
			return $this;
		}

		return $this->value->join();
	}

	abstract public function chain( callable $fn );

	abstract public function bichain( callable $leftFn, callable $rightFn);

	abstract public function orElse( callable $fn );
	abstract public function bimap( callable $leftFn, callable $rightFn );
	abstract public function coalesce( callable $leftFn, callable $rightFn );
	abstract public function alt( Either $alt );
	abstract public function filter( callable $fn );

	}

class Left extends Either {

	use ConstApplicative;
	use Pointed;

	public function map( callable $fn ) {
		return $this;
	}

	public function bimap( callable $leftFn, callable $rightFn ) {
		return Either::left( $leftFn( $this->value ) );
	}

	public function coalesce( callable $leftFn, callable $rightFn ) {
		return Either::of( $leftFn( $this->value ) );
	}

	public function get() {
		throw new \Exception( "Can't extract the value of Left" );
	}

	public function getOrElse( $other ) {
		return $other;
	}

	public function orElse( callable $fn ) {
		return Either::right( $fn( $this->value ) );
	}

	public function chain( callable $fn ) {
		return $this;
	}

	public function bichain( callable $leftFn, callable $rightFn ) {
		return $leftFn( $this->value );
	}

	public function getOrElseThrow( $value ) {
		throw new \Exception( $value );
	}

	public function filter( callable $fn ) {
		return $this;
	}

	public function tryCatch( callable $fn ) {
		return $this;
	}

	public function alt( Either $alt ) {
		return $alt;
	}
}

class Right extends Either {

	use Applicative;
	use Pointed;

	public function map( callable $fn ) {
		return Either::of( $fn( $this->value ) );
	}

	public function bimap( callable $leftFn, callable $rightFn ) {
		return $this->map( $rightFn );
	}

	public function coalesce( callable $leftFn, callable $rightFn ) {
		return $this->map( $rightFn );
	}

	public function getOrElse( $other ) {
		return $this->value;
	}

	public function orElse( callable $fn ) {
		return $this;
	}

	public function getOrElseThrow( $value ) {
		return $this->value;
	}

	public function chain( callable $fn ) {
		return $this->map( $fn )->join();
	}

	public function bichain( callable $leftFn, callable $rightFn ) {
		return $rightFn( $this->value );
	}

	public function filter( callable $fn ) {
		return Logic::ifElse( $fn, Either::right(), Either::left(), $this->value );
	}

	public function tryCatch( callable $fn ) {
		return tryCatch( function () use ( $fn ) {
			return $fn( $this->value );
		} );
	}

	public function alt( Either $alt ) {
		return $this;
	}
}

Either::init();
