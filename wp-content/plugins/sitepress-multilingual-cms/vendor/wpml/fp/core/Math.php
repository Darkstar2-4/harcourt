<?php

namespace WPML\FP;

use WPML\Collect\Support\Traits\Macroable;

class Math {

	use Macroable;

	public static function init() {

		self::macro( 'multiply', curryN( 2, function ( $a, $b ) { return $a * $b; } ) );

		self::macro( 'divide', curryN( 2, function ( $a, $b ) { return $a / $b; } ) );

		self::macro( 'add', curryN( 2, function ( $a, $b ) { return $a + $b; } ) );

		self::macro( 'product', curryN(1, Fns::reduce( self::multiply(), 1 ) ) );
	}
}

Math::init();
