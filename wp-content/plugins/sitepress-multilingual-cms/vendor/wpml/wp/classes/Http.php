<?php

namespace WPML\LIB\WP;


use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Either;
use WPML\FP\Fns;
use function WPML\FP\curryN;

class Http {

	use Macroable;

	public static function init() {
		self::macro( 'post', curryN( 2, function ( $url, $args ) {
			return WordPress::handleError( Fns::make( \WP_Http::class )->post( $url, $args ) );
		} ) );
	}

}

Http::init();
