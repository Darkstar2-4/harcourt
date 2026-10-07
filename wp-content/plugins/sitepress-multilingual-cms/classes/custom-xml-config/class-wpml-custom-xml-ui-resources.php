<?php

use WPML\Core\WP\App\Resources;
use WPML\LIB\WP\App\Resources as LibResources;

class WPML_Custom_XML_UI_Resources {
	private $wpml_wp_api;

	private $wpml_core_url;

	function __construct( WPML_WP_API $wpml_wp_api) {
		$this->wpml_wp_api   = $wpml_wp_api;
		$this->wpml_core_url = $this->wpml_wp_api->constant( 'ICL_PLUGIN_URL' );
	}

	function admin_enqueue_scripts() {
		if ( $this->wpml_wp_api->is_tm_page( 'custom-xml-config', 'settings' ) ) {
			$core_version  = $this->wpml_wp_api->constant( 'ICL_SITEPRESS_SCRIPT_VERSION' );
			$plugin_path   = $this->wpml_wp_api->constant( 'WPML_PLUGIN_PATH' );
			$site_url      = get_rest_url();
			$api_root_path = $site_url . 'wpml/v1/custom-xml-config';

			LibResources::enqueueWithDeps(
				'xmlConfigEditor',
				$this->wpml_core_url,
				$plugin_path,
				$core_version,
				'sitepress',
				[
					'name' => 'wpmlCustomXML',
					'data' => [
						'restNonce'   => wp_create_nonce( 'wp_rest' ),
						'apiEndpoint' => $api_root_path,
					],
				],
				[ Resources::vendorAsDependency() ]
			);
		}
	}
}
