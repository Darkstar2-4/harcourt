<?php

use WPML\Language\Detection\Backend;

class WPML_Backend_Request extends WPML_Request {
	private $backend;

	public function __construct( $url_converter, $active_languages, $default_language, $cookieLanguage ) {
		parent::__construct( $url_converter, $active_languages, $default_language, $cookieLanguage );
		$this->backend = new Backend(
			$url_converter,
			$active_languages,
			$default_language,
			$cookieLanguage
		);
	}

	public function get_requested_lang() {
		return $this->backend->get_requested_lang();
	}

	protected function get_cookie_name() {
		return $this->cookieLanguage->getBackendCookieName();
	}
}
