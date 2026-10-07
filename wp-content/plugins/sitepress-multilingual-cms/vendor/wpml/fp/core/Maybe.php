<?php

namespace WPML\FP;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Functor\Functor;
use WPML\FP\Functor\Pointed;

class Maybe {

	use Macroable;

	public static function init() {
		self::macro( 'just', Just::of() );

		self::macro( 'of', Just::of() );

		self::macro( 'fromNullable', curryN( 1, function( $value ) {
			return is_null( $value ) || $value === false ? self::nothing() : self::just( $value );
		} ) );

		Maybe::macro( 'safe', curryN( 1, function( $fn ) {
			return pipe( $fn, self::fromNullable() );
		} ) );

		Maybe::macro( 'safeAfter', curryN( 2, function( $predicate, $fn ) {
			return pipe( $fn, Logic::ifElse( $predicate, self::just(), [ self::class, 'nothing' ] ) );
		} ) );

		Maybe::macro( 'safeBefore', curryN( 2, function( $predicate, $fn ) {
			return pipe( Logic::ifElse( $predicate, self::just(), [ self::class, 'nothing' ] ), Fns::map( $fn ) );
		} ) );

	}

	public static function nothing() {
		return new Nothing();
	}

	public function isNothing() {
		return false;
	}

	public function isJust() {
		return false;
	}
}


class Just extends Maybe {
	use Functor;
	use Pointed;
	use Applicative;

	public function map( callable $fn ) {
		return Maybe::fromNullable( $fn( $this->value ) );
	}

	public function getOrElse( $other ) {
		return $this->value;
	}

	public function filter( $fn = null ) {
		$fn = $fn ?: Fns::identity();
		return Maybe::fromNullable( $fn( $this->value ) ? $this->value : null );
	}

	public function reject( $fn = null ) {
		$fn = $fn ?: Fns::identity();
		return $this->filter( Logic::complement( $fn ) );
	}

	public function chain( callable $fn ) {
		return $fn( $this->value );
	}

	public function isJust() {
		return true;
	}
}

class Nothing extends Maybe {
	use ConstApplicative;

	public function map( callable $fn ) {
		return $this;
	}

	public function get() {
		throw new \Exception( "Can't extract the value of Nothing" );
	}

	public function getOrElse( $other ) {
		return value( $other );
	}

	public function filter( callable $fn ) {
		return $this;
	}

	public function reject( callable $fn ) {
		return $this;
	}

	public function chain( callable $fn ) {
		return $this;
	}

	public function isNothing() {
		return true;
	}

}

Maybe::init();
