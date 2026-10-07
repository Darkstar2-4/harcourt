<?php

use function WPML\Container\make;
use WPML\TM\ATE\ReturnedJobs;

class WPML_TM_ATE_Jobs_Actions_Factory implements IWPML_Backend_Action_Loader, \IWPML_REST_Action_Loader {
	private $current_screen;

	public function create() {
		$ams_ate_factories = wpml_tm_ams_ate_factories();

		if ( WPML_TM_ATE_Status::is_enabled_and_activated() ) {
			$sitepress      = $this->get_sitepress();
			$current_screen = $this->get_current_screen();

			$ate_api  = $ams_ate_factories->get_ate_api();
			$records  = wpml_tm_get_ate_job_records();
			$ate_jobs = new WPML_TM_ATE_Jobs( $records );

			$translator_activation_records = new WPML_TM_AMS_Translator_Activation_Records( new WPML_WP_User_Factory() );
			$wp_api                        = new WPML_WP_API();

			return new WPML_TM_ATE_Jobs_Actions(
				$ate_api,
				$ate_jobs,
				$sitepress,
				$current_screen,
				$translator_activation_records,
				$wp_api
			);
		}

		return null;
	}

	private function get_sitepress() {
		global $sitepress;

		return $sitepress;
	}

	private function get_current_screen() {
		if ( ! $this->current_screen ) {
			$this->current_screen = new WPML_Current_Screen();
		}

		return $this->current_screen;
	}
}
