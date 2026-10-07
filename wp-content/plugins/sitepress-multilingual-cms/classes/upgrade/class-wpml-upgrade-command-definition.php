<?php

class WPML_Upgrade_Command_Definition {
	private $class_name;
	private $dependencies = array();
	private $scopes = array();
	private $method;

	private $factory_method;

	public function __construct(
		$class_name,
		array $dependencies,
		array $scopes,
		?string $method = null,
		?callable $factory_method = null
	) {
		$this->class_name     = $class_name;
		$this->dependencies   = $dependencies;
		$this->scopes         = $scopes;
		$this->method         = $method;
		$this->factory_method = $factory_method;
	}

	public function get_dependencies() {
		return $this->dependencies;
	}

	public function get_class_name() {
		return $this->class_name;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_scopes() {
		return $this->scopes;
	}

	public function get_factory_method() {
		return $this->factory_method;
	}

	public function create() {
		if ( $this->get_factory_method() ) {
			$factory_method = $this->get_factory_method();

			return $factory_method();
		}

		$class_name = $this->get_class_name();

		return new $class_name( $this->get_dependencies() );
	}
}
