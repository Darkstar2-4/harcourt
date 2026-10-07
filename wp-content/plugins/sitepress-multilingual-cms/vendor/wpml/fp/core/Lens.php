<?php

namespace WPML\FP;

use WPML\Collect\Support\Traits\Macroable;

class Lens {

	use Macroable;

	public static function init() {

		self::macro( 'iso', curryN( 2, function( $get, $reverseGet ) {
			return function ( $toFunctorFn ) use ( $get, $reverseGet ) {
				return function ( $target ) use ( $toFunctorFn, $get, $reverseGet ) {
					$value = $get( $target );
					return Fns::map( $reverseGet, $toFunctorFn( $value ) );
				};
			};
		} ) );

		self::macro( 'isoIdentity', function() {
			return self::iso( Fns::identity(), Fns::identity() );
		} );

		self::macro( 'isoUnserialized', function() {
			return self::iso( 'unserialize', 'serialize' );
		} );

		self::macro( 'isoJsonDecoded', function() {
			return self::iso( 'json_decode', 'json_encode' );
		} );

		self::macro( 'isoUrlDecoded', function() {
			return self::iso( 'urldecode', 'urlencode' );
		} );

		self::macro( 'isoBase64Decoded', function() {
			return self::iso( 'base64_decode', 'base64_encode' );
		} );

		self::macro( 'isoParsedUrl', function() {
			return self::iso( 'parse_url', 'http_build_url' );
		} );

		self::macro( 'isoParsedQuery', function() {
			return self::iso( Str::parse(), 'http_build_query' );
		} );
	}
}

Lens::init();
