<?php

namespace OTGS\Installer\FP;

use OTGS\Installer\Collect\Support\Macroable;

class Relation {

	use Macroable;

	public static function init() {

		self::macro( 'equals', curryN( 2, function ( $a, $b ) {
			return $a === $b;
		} ) );

		self::macro( 'lt', curryN( 2, function ( $a, $b ) {
			if ( is_string( $a ) && is_string( $b ) ) {
				return strcmp( $a, $b ) < 0;
			}

			return $a < $b;
		} ) );

		self::macro( 'gt', curryN( 2, function ( $a, $b ) {
			return self::lt( $b, $a );
		} ) );

		self::macro( 'lte', curryN( 2, function ( $a, $b ) {
			return ! self::gt( $a, $b );
		} ) );

		self::macro( 'gte', curryN( 2, function ( $a, $b ) {
			return ! self::lt( $a, $b );
		} ) );

		self::macro( 'propEq', curryN( 3, function ( $prop, $value, $obj ) {
			return Obj::prop( $prop, $obj ) === $value;
		} ) );

	}
}

Relation::init();