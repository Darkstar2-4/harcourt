<?php

class WPML_Cookie_Setting {

	const COOKIE_SETTING_FIELD = 'store_frontend_cookie';

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function get_setting() {
		return $this->sitepress->get_setting( self::COOKIE_SETTING_FIELD );
	}

	public function set_setting( $value ) {
		$this->sitepress->set_setting( self::COOKIE_SETTING_FIELD, $value );
		$this->sitepress->save_settings();
	}
}
