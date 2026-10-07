<?php

namespace WPML\UrlHandling;

class WPLoginUrlConverterFactory implements \IWPML_Frontend_Action_Loader, \IWPML_Backend_Action_Loader {

	public function create() {
		global $wpml_url_converter, $sitepress;

		$rules = new WPLoginUrlConverterRules();

		return WPLoginUrlConverter::isEnabled()
			? array_filter( [ $wpml_url_converter->get_wp_login_url_converter( $sitepress ), $rules ] )
			: [ $rules ];
	}
}
