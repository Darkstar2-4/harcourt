<?php

class WPML_TM_Rest_Jobs_Language_Names {
	private $sitepress;

	private $active_languages;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function get( $code ) {
		$languages = $this->get_active_languages();

		return isset( $languages[ $code ] ) ? $languages[ $code ] : $code;
	}

	public function get_active_languages() {
		if ( ! $this->active_languages ) {
			foreach ( $this->sitepress->get_active_languages() as $code => $data ) {
				$this->active_languages[ $code ] = $data['display_name'];
			}
		}

		return $this->active_languages;
	}
}
