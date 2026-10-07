<?php

namespace WPML\LIB\WP;

use WPML\FP\Either;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Promise;
use function WPML\FP\pipe;

class Hooks {

	public static function onAction( $action, $priority = 10, $accepted_args = 1 ) {
		return self::onHook( 'add_action', $action, $priority, $accepted_args );
	}

	public static function onFilter( $filter, $priority = 10, $accepted_args = 1 ) {
		return self::onHook( 'add_filter', $filter, $priority, $accepted_args );
	}

	public static function onHook( callable $fn, $actionOrFilter, $priority = 10, $accepted_args = 1 ) {

		$actionsOrFilters = is_array( $actionOrFilter ) ? $actionOrFilter : [ $actionOrFilter ];

		$promise = new Promise();

		$callback = function () use ( $promise ) {
			return $promise->resolve( Either::right( func_get_args() ) )->getOrElse( null );
		};

		foreach ( $actionsOrFilters as $actionOrFilter ) {
			$fn( $actionOrFilter, $callback, $priority, $accepted_args );
		};

		return $promise;
	}

	public static function callWithFilter( $fn, $name, $filterFn, $priority = 10, $acceptedArgs = 1 ) {
		add_filter( $name, $filterFn, $priority, $acceptedArgs );
		$result = $fn();
		remove_filter( $name, $filterFn, $priority, $acceptedArgs );

		return $result;
	}

	public static function getArgs( array $argsLabels ) {
		return pipe(
			Obj::pick( Obj::keys( $argsLabels ) ),
			Obj::values(),
			Lst::zipObj( Obj::values( $argsLabels ) )
		);
	}
}


