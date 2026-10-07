<?php

class WPML_AJAX_Action_Validation {

	public function is_valid( $action_name ) {
		$is_valid = false;

		if ( array_key_exists( 'action', $_POST ) && $action_name === $_POST['action'] ) {

			if ( array_key_exists( 'nonce', $_POST ) && wp_verify_nonce( $_POST['nonce'], $action_name ) ) {
				$is_valid = true;
			} else {
				wp_send_json_error( esc_html__( 'Invalid request!', 'sitepress' ) );
			}
		}

		return $is_valid;
	}
}
