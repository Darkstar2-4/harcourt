<?php

namespace WPML\Container;

use function WPML\FP\curryN;

if ( ! function_exists( 'WPML\Container\make' ) ) {
	function make( $class_name = null, ?array $args = null ) {
		$make = function ( $class_name, $args = [] ) {
			if ( class_exists( $class_name ) || interface_exists( $class_name ) ) {
				return Container::make( $class_name, $args );
			}

			return null;
		};

		return call_user_func_array( curryN( 1, $make ), func_get_args() );
	}
}

if ( ! function_exists( 'WPML\Container\share' ) ) {

	function share( array $names_or_instances ) {
		Container::share( $names_or_instances );
	}
}

if ( ! function_exists( 'WPML\Container\alias' ) ) {

	function alias( array $aliases ) {
		Container::alias( $aliases );
	}
}

if ( ! function_exists( 'WPML\Container\delegate' ) ) {

	function delegate( array $delegated ) {
		Container::delegate( $delegated );
	}
}

if ( ! function_exists( 'WPML\Container\execute' ) ) {

	function execute( $callableOrMethodStr = null, ?array $args = null ) {
		return call_user_func_array( curryN( 1, [ Container::class, 'execute' ] ), func_get_args() );
	}
}
