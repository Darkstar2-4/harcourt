<?php

namespace WPML\LIB\WP;

use WPML\Collect\Support\Collection;
use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Either;
use function WPML\FP\curryN;
use function WPML\FP\partial;

class Nonce {

	use Macroable;

	public static function init() {
		self::macro( 'verify', curryN( 2, function ( $action, Collection $postData ) {
			return wp_verify_nonce( $postData->get( 'nonce' ), $action ?: $postData->get( 'endpoint' ) )
				? Either::right( $postData )
				: Either::left( 'Nonce error' );
		} ) );

		self::macro( 'verifyEndPoint', self::verify( '' ) );

		self::macro( 'create', curryN( 1, function( $str ) { return wp_create_nonce( $str ); } ) );
	}

}

Nonce::init();
