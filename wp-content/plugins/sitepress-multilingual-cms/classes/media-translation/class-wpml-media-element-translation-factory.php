<?php

namespace WPML\Media\Classes;

class WPML_Media_Element_Translation_Factory {

	public static function create( $mediaId ) {
		global $sitepress;

		return ( new \WPML_Translation_Element_Factory( $sitepress ) )->create_post( $mediaId );
	}
}
