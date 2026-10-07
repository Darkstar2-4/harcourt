<?php

class WPML_Upgrade_Fix_Non_Admin_With_Admin_Cap implements IWPML_Upgrade_Command {

	private $results = array();

	public function run_admin() {
		$user = new WP_User( 'admin' );

		if ( $user->exists() && ! is_super_admin( $user->get( 'ID' ) ) ) {
			$wpml_capabilities = wpml_get_capability_keys();
			foreach ( $wpml_capabilities as $capability ) {
				$user->remove_cap( $capability );
			}
		}

		return true;
	}

	public function run_ajax() {
		return false;
	}

	public function run_frontend() {
		return false;
	}

	public function get_results() {
		return $this->results;
	}
}
