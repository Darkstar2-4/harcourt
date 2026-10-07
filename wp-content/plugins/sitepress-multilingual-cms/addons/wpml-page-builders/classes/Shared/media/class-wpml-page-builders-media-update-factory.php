<?php

abstract class WPML_Page_Builders_Media_Update_Factory implements IWPML_PB_Media_Update_Factory {

	protected function get_media_translate( $find_usage_instead_of_translate, $create_noop_media_find_usage = false ) {
		global $sitepress;

		$element_factory = new WPML_Translation_Element_Factory( $sitepress );
		if ( $find_usage_instead_of_translate ) {
			return $create_noop_media_find_usage ? new WPML_Page_Builders_Media_Find_Usage_Noop() : new WPML_Page_Builders_Media_Find_Usage();
		}

		$image_translate = new WPML_Media_Image_Translate(
			$sitepress,
			new WPML_Media_Attachment_By_URL_Factory(),
			new \WPML\Media\Factories\WPML_Media_Attachment_By_URL_Query_Factory()
		);

		return new WPML_Page_Builders_Media_Translate( $element_factory, $image_translate );
	}
}
