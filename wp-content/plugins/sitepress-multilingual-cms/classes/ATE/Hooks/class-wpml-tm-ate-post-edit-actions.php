<?php

class WPML_TM_ATE_Post_Edit_Actions implements IWPML_Action {
	private $endpoints;

	public function __construct( WPML_TM_ATE_AMS_Endpoints $endpoints ) {
		$this->endpoints = $endpoints;
	}

	public function add_hooks() {
		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) );
	}

	public function allowed_redirect_hosts( $hosts ) {
		$hosts[] = $this->endpoints->get_AMS_host();
		$hosts[] = $this->endpoints->get_ATE_host();

		return $hosts;
	}
}