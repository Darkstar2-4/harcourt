<?php

namespace WPML\TM\Menu\TranslationServices;
use WPML\LIB\WP\User;

class Section implements \IWPML_TM_Admin_Section {
	const SLUG = 'translators';

	private $sitepress;

	private $wp_api;

	private $template;

	public function __construct(
		\SitePress $sitepress,
		$template
	) {
		$this->sitepress = $sitepress;
		$this->wp_api    = $sitepress->get_wp_api();
		$this->template  = $template;
	}

	public function get_order() {
		return 400;
	}

	public function render() {
		call_user_func( $this->template );
	}

	public function is_visible() {
		return ! $this->wp_api->constant( 'ICL_HIDE_TRANSLATION_SERVICES' ) && ( $this->wp_api->constant( 'WPML_BYPASS_TS_CHECK' ) || ! $this->sitepress->get_setting( 'translation_service_plugin_activated' ) );
	}

	public function get_slug() {
		return self::SLUG;
	}

	public function get_capabilities() {
		return [ User::CAP_MANAGE_TRANSLATIONS, User::CAP_ADMINISTRATOR ];
	}

	public function get_caption() {
		return __( 'Translation Services', 'wpml-translation-management' );
	}

	public function get_callback() {
		return array( $this, 'render' );
	}

	public function admin_enqueue_scripts( $hook ) {}
}
