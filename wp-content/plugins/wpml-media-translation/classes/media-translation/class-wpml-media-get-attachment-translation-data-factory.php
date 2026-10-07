<?php

/**
 * Class WPML_Media_Get_Attachment_Translation_Data_Factory
 */
class WPML_Media_Get_Attachment_Translation_Data_Factory implements IWPML_Backend_Action_Loader {

	public function create() {
		global $sitepress, $wpdb;

		return new WPML_Media_Get_Attachment_Translation_Data( $sitepress, $wpdb );
	}

}
