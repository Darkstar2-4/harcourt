<?php

namespace WPML\Rest;

use \WP_REST_Request;

class Adaptor extends \WPML_REST_Base {

	private $target;

	public function set_target( ITarget $target ) {
		$this->target    = $target;
		$this->namespace = $target->get_namespace();
	}

	public function add_hooks() {
		$routes = $this->target->get_routes();
		foreach ( $routes as $route ) {
			$this->register_route( $route['route'], $route['args'] );
		}
	}

	public function get_allowed_capabilities( WP_REST_Request $request ) {
		return $this->target->get_allowed_capabilities( $request );
	}


}
