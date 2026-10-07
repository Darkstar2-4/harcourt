<?php

namespace WPML\TM\TranslationProxy\Services;

class Storage {
	private $sitepress;

	public function __construct( \SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function getCurrentService() {
		return $this->sitepress->get_setting( 'translation_service' );
	}

	public function setCurrentService( \stdClass $service ) {
		do_action( 'wpml_tm_before_set_translation_service', $service );
		$this->sitepress->set_setting( 'translation_service', $service, true );
	}
}
