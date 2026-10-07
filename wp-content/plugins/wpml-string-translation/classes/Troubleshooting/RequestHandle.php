<?php

namespace WPML\ST\Troubleshooting;

class RequestHandle implements \IWPML_Action {

	private $action;

	private $callback;

	public function __construct( $action, $callback ) {
		$this->action   = $action;
		$this->callback = $callback;
	}

	public function add_hooks() {
		add_action( 'wp_ajax_' . $this->action, [ $this, 'handle' ] );
	}

	public function handle() {
		if ( ! current_user_can( 'wpml_manage_troubleshooting' ) ) {
			wp_send_json_error( 'not allowed', 403 );
			return;
		}

		if ( wp_verify_nonce( $_POST['nonce'], BackendHooks::NONCE_KEY ) ) {
			call_user_func( $this->callback );
			wp_send_json_success();
		} else {
			wp_send_json_error( 'Invalid nonce value', 500 );
		}
	}
}
