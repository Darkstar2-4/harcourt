<?php

use WPML\Language\Detection\Frontend;

class WPML_Frontend_Request extends WPML_Request {
	private $frontend;

	public function __construct( $url_converter, $active_languages, $default_language, $cookieLanguage, $wp_api ) {
		parent::__construct( $url_converter, $active_languages, $default_language, $cookieLanguage );
		$this->frontend = new Frontend(
			$url_converter,
			$active_languages,
			$default_language,
			$cookieLanguage,
			$wp_api
		);
	}

	public function get_requested_lang() {
		return $this->frontend->get_requested_lang();
	}

	protected function get_cookie_name() {
		return $this->cookieLanguage->getFrontendCookieName();
	}
}
