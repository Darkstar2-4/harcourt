<?php

class WPML_TF_WP_Cron_Events_Factory implements IWPML_Backend_Action_Loader, IWPML_Frontend_Action_Loader {

	public function create() {
		return new WPML_TF_WP_Cron_Events(
			new WPML_TF_Settings_Read(),
			new WPML_TF_TP_Ratings_Synchronize_Factory()
		);
	}
}
