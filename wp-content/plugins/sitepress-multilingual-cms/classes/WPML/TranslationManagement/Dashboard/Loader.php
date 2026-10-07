<?php

namespace WPML\TranslationManagement\Dashboard;

use WPML\UIPage;

class Loader implements \IWPML_Backend_Action {

	public function add_hooks() {
		$isTmDashboardPage = UIPage::isTMDashboard( $_GET );

		if ( $isTmDashboardPage ) {
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdminScripts' ] );
		}
	}

	public function enqueueAdminScripts() {
		wp_enqueue_script(
			'wpml-tm-custom-events',
			WPML_TM_URL . '/dist/js/wpml-tm-dashboard-events/app.js',
			[],
			ICL_SITEPRESS_SCRIPT_VERSION,
			true
		);
	}

}
