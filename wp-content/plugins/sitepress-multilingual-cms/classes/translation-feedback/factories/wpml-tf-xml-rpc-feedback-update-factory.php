<?php

class WPML_TF_XML_RPC_Feedback_Update_Factory {

	public function create() {
		global $sitepress;

		$translation_service  = $sitepress->get_setting( 'translation_service' );
		$translation_projects = $sitepress->get_setting( 'icl_translation_projects' );
		$tp_project           = new WPML_TP_Project( $translation_service, $translation_projects );

		$feedback_storage = new WPML_TF_Data_Object_Storage( new WPML_TF_Feedback_Post_Convert() );

		return new WPML_TF_XML_RPC_Feedback_Update( $feedback_storage, $tp_project );
	}
}
