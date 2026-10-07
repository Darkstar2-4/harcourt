<?php

namespace WPML\Container;

use WPML\Auryn\Injector as AurynInjector;

class Container {

	private static $instance = null;

	private $injector = null;

	private function __construct() {
		$this->injector = new AurynInjector();
	}

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new Container();
		}

		return self::$instance;
	}

	public static function share( array $names_or_instances ) {
		$injector = self::get_instance()->injector;

		wpml_collect( $names_or_instances )->each(
			function ( $name_or_instance ) use ( $injector ) {
				$injector->share( $name_or_instance );
			}
		);
	}

	public static function alias( array $aliases ) {
		$injector = self::get_instance()->injector;

		wpml_collect( $aliases )->each(
			function ( $alias, $original ) use ( $injector ) {
				$injector->alias( $original, $alias );
			}
		);
	}

	public static function delegate( array $delegated ) {
		$injector = self::get_instance()->injector;

		wpml_collect( $delegated )->each(
			function ( $instantiator, $class_name ) use ( $injector ) {
				$injector->delegate( $class_name, $instantiator );
			}
		);
	}

	public static function make( $class_name, array $args = array() ) {
		return self::get_instance()->injector->make( $class_name, $args );
	}

	public static function execute( $callableOrMethodStr, array $args = [] ) {
		return self::get_instance()->injector->execute( $callableOrMethodStr, $args );
	}
}
