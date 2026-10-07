<?php

class WPML_TM_AMS_ATE_Factories {

	public function get_ate_api() {
		return WPML\Container\make( WPML_TM_ATE_API::class );
	}

	public function get_ams_api() {
		return WPML\Container\make( WPML_TM_AMS_API::class );
	}

	public function is_ate_active() {
		if ( ! WPML_TM_ATE_Status::is_active() ) {
			try {
				$this->get_ams_api()->get_status();

				return WPML_TM_ATE_Status::is_active();
			} catch ( Exception $ex ) {
				return false;
			}
		}

		return true;
	}
}
