<?php

abstract class WPML_TM_Resources_Factory {
	protected $ajax_actions;
	protected $wpml_wp_api;

	public function __construct( &$wpml_wp_api ) {
		$this->wpml_wp_api = &$wpml_wp_api;
		add_action( 'admin_enqueue_scripts', array( $this, 'register_resources' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_resources' ), 20 );
	}

	abstract public function enqueue_resources( $hook_suffix );
	abstract public function register_resources( $hook_suffix );
}
