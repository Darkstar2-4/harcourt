<?php

class WPML_Upgrade_WPML_Site_ID implements IWPML_Upgrade_Command {

	public function run() {
		if ( $this->old_option_exists() ) {

			$value_from_old_option = get_option( WPML_Site_ID::SITE_ID_KEY, null );
			update_option( WPML_Site_ID::SITE_ID_KEY . ':' . WPML_Site_ID::SITE_SCOPES_GLOBAL, $value_from_old_option, false );
			return delete_option( WPML_Site_ID::SITE_ID_KEY );
		}

		return true;
	}

	protected function old_option_exists() {
		get_option( WPML_Site_ID::SITE_ID_KEY, null );
		$notoptions = wp_cache_get( 'notoptions', 'options' );

		return false === $notoptions || ! array_key_exists( WPML_Site_ID::SITE_ID_KEY, $notoptions );
	}

	public function run_admin() {
		return $this->run();
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return null;
	}
}
