<?php

class WPML_TF_Backend_Promote_Hooks_Factory implements IWPML_Backend_Action_Loader, IWPML_Deferred_Action_Loader {

	public function get_load_action() {
		return 'wpml_after_tm_loaded';
	}

	public function create() {
		global $sitepress;

		$hooks = null;

		$settings_read = new WPML_TF_Settings_Read();
		$tf_settings = $settings_read->get( 'WPML_TF_Settings' );

		if ( ! $tf_settings->is_enabled() ) {
			$setup_complete = $sitepress->is_setup_complete();

			$translation_service = new WPML_TF_Translation_Service(
				class_exists( 'WPML_TP_Client_Factory' ) ? new WPML_TP_Client_Factory() : null
			);

			$hooks = new WPML_TF_Backend_Promote_Hooks(
				new WPML_TF_Promote_Notices( $sitepress ),
				$setup_complete,
				$translation_service
			);
		}

		return $hooks;
	}
}
