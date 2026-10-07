<?php

namespace WPML\FP;

use WPML\FP\Functor\Functor;
use WPML\FP\Functor\Pointed;

class Wrapper {
	use Functor;
	use Pointed;

	public function map( callable $fn ) {
		return self::of( $fn( $this->value ) );
	}

	public function filter( $fn = null ) {
		$fn = $fn ?: Fns::identity();
		return $fn( $this->value ) ? $this->value : null;
	}

	public function join() {
		if( ! $this->value instanceof Wrapper ) {
			return $this->value;
		}
		return $this->value->join();
	}

	public function ap( $value ) {
		return self::of( call_user_func( $this->value, $value ) );
	}

	public function get() {
		return $this->value;
	}

}
