<?php

namespace OTGS\Installer\FP\Traits;

use function OTGS\Installer\FP\curryN;

trait Pointed {
	public static function of( $value = null ) {
		$of = function ( $value ) {
			return new static( $value );
		};

		return call_user_func_array( curryN( 1, $of ), func_get_args() );

	}
}