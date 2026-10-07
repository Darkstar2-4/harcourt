<?php

namespace WPML\ST\Utils;

use SitePress;
use WPML_String_Translation;

class LanguageResolution {

	private $sitepress;

	private $string_translation;

	private $admin_language;

	public function __construct( SitePress $sitepress, WPML_String_Translation $string_translation ) {
		$this->sitepress          = $sitepress;
		$this->string_translation = $string_translation;
	}

	public function getCurrentLanguage() {
		if ( $this->string_translation->should_use_admin_language() ) {
			$current_lang = $this->getAdminLanguage();
		} else {
			$current_lang = $this->sitepress->get_current_language();
		}

		if ( ! $current_lang ) {
			$current_lang = $this->sitepress->get_default_language();
			if ( ! $current_lang ) {
				$current_lang = 'en';
			}
		}

		return $current_lang;
	}

	public function getCurrentLocale() {
		return $this->sitepress->get_locale( $this->getCurrentLanguage() );
	}

	private function getAdminLanguage() {
		if ( $this->sitepress->is_wpml_switch_language_triggered() ) {
			return $this->sitepress->get_admin_language();
		}

		if ( ! $this->admin_language ) {
			$this->admin_language = $this->sitepress->get_admin_language();
		}

		return $this->admin_language;
	}
}
