<?php

namespace WPML\PB\Elementor\V4\Component;

class QueryHooks implements \IWPML_REST_Action {

	const POST_TYPE  = 'elementor_component';
	const REST_ROUTE = '/elementor/v1/components';

	public function add_hooks() {
		add_action( 'pre_get_posts', [ $this, 'handleComponentQuery' ] );
	}

	public function handleComponentQuery( $query ) {
		if ( ! $this->isComponentRestQuery( $query ) ) {
			return;
		}

		$query->query_vars['suppress_filters'] = false;
	}

	private function isComponentRestQuery( $query ) {
		if ( ( $query->query_vars['post_type'] ?? null ) !== self::POST_TYPE ) {
			return false;
		}

		$requestUri = $_SERVER['REQUEST_URI'] ?? '';

		return strpos( $requestUri, self::REST_ROUTE ) !== false;
	}
}
