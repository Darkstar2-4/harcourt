<?php

namespace WPML\TM\TranslationProxy\Services\Project;

class SiteDetails {
	private $sitepress;

	public function __construct( \SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function getDeliveryMethod() {
		return (int) $this->sitepress->get_setting( 'translation_pickup_method' ) === ICL_PRO_TRANSLATION_PICKUP_XMLRPC
			? 'xmlrpc'
			: 'polling';
	}

	public function getBlogInfo() {
		return [
			'url'         => get_option( 'siteurl' ),
			'name'        => get_option( 'blogname' ),
			'description' => get_option( 'blogdescription' ),
		];
	}

	public function getClientData() {
		$current_user = wp_get_current_user();

		return [
			'email' => $current_user->user_email,
			'name'  => $current_user->display_name,
		];
	}
}
