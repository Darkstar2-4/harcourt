<?php

class WPML_Upgrade_WPML_Site_ID_Remaining implements IWPML_Upgrade_Command {

	const SCOPE_ATE = 'ate';

	public function run() {
		if ( $this->old_and_new_options_exist() ) {

			$value_from_old_option = get_option( WPML_Site_ID::SITE_ID_KEY, null );
			$value_from_new_option = get_option( WPML_Site_ID::SITE_ID_KEY . ':' . WPML_Site_ID::SITE_SCOPES_GLOBAL, null );

			update_option( WPML_Site_ID::SITE_ID_KEY . ':' . WPML_Site_ID::SITE_SCOPES_GLOBAL, $value_from_old_option, false );

			if ( $this->option_exists( WPML_Site_ID::SITE_ID_KEY . ':' . self::SCOPE_ATE ) ) {
				$ate_uuid = get_option( WPML_Site_ID::SITE_ID_KEY . ':' . self::SCOPE_ATE, null );

				if ( $ate_uuid === $value_from_new_option ) {
					update_option( WPML_Site_ID::SITE_ID_KEY . ':' . self::SCOPE_ATE, $value_from_old_option, false );
				}
			}

			return delete_option( WPML_Site_ID::SITE_ID_KEY );
		}

		return true;
	}

	protected function old_and_new_options_exist() {
		return $this->option_exists( WPML_Site_ID::SITE_ID_KEY )
			&& $this->option_exists( WPML_Site_ID::SITE_ID_KEY . ':' . WPML_Site_ID::SITE_SCOPES_GLOBAL );
	}

	private function option_exists( $key ) {
		get_option( $key, null );
		$notoptions = wp_cache_get( 'notoptions', 'options' );

		return false === $notoptions || ! array_key_exists( $key, $notoptions );
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
