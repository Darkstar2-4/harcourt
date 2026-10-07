<?php

class WPML_TF_Frontend_AJAX_Hooks_Factory extends WPML_AJAX_Base_Factory {

	const AJAX_ACTION = 'wpml-tf-frontend-feedback';

	public function create() {
		global $sitepress, $wpdb;

		if ( $this->is_valid_action( self::AJAX_ACTION ) ) {
			return new WPML_TF_Frontend_AJAX_Hooks(
				new WPML_TF_Data_Object_Storage( new WPML_TF_Feedback_Post_Convert() ),
				new WPML_TF_Document_Information( $sitepress ),
				new WPML_TF_Post_Rating_Metrics( $wpdb ),
				class_exists( 'WPML_TP_Client_Factory' ) ? new WPML_TP_Client_Factory() : null,
				$_POST
			);
		}

		return null;
	}
}
