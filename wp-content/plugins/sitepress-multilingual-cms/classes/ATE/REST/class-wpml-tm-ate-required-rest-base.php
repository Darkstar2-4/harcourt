<?php

abstract class WPML_TM_ATE_Required_Rest_Base extends WPML_REST_Base {

	const REST_NAMESPACE = 'wpml/tm/v1';

	public function __construct() {
		parent::__construct( self::REST_NAMESPACE );
	}

	public function validate_permission( WP_REST_Request $request ) {
		return WPML_TM_ATE_Status::is_enabled() && parent::validate_permission( $request );
	}

	static function get_url( $endpoint ) {
		return get_rest_url( null, '/' . self::REST_NAMESPACE . $endpoint );
	}

}
