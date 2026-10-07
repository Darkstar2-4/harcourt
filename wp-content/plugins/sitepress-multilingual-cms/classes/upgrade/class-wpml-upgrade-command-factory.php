<?php

class WPML_Upgrade_Command_Factory {
	public function create_command_definition(
		$class_name,
		array $dependencies,
		array $scopes,
		?string $method = null,
		?callable $factory_method = null
	) {
		return new WPML_Upgrade_Command_Definition( $class_name, $dependencies, $scopes, $method, $factory_method );
	}
}
