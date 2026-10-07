<?php

namespace WPML\TM\ATE;

use WPML\ATE\Proxies\ProxyInterceptorLoader;
use WPML_TM_ATE_AMS_Endpoints;

class ATEDashboardLoader {
	const ATE_DASHBOARD_ID = 'eate_dashboard';

	private $proxy;
	private $endpoints;

	function __construct( ProxyInterceptorLoader $proxy, WPML_TM_ATE_AMS_Endpoints $endpoints ) {
		$this->proxy     = $proxy;
		$this->endpoints = $endpoints;
	}


	public function registerScript() {
		if ( $this->proxy->shouldEnableProxy() ) {
			return self::registerScriptUsingProxy();
		} else {
			return self::registerScriptWithoutProxy();
		}
	}

	public function initializeScript( $params ) {
		$initializer_handle = self::ATE_DASHBOARD_ID . '-init';

		wp_register_script( $initializer_handle, '', [ self::ATE_DASHBOARD_ID ], ICL_SITEPRESS_SCRIPT_VERSION, true );
		wp_enqueue_script( $initializer_handle );

		wp_add_inline_script( $initializer_handle, 'window.addEventListener("load", function() {window.ateDashboard(' . wp_json_encode( $params ) . '); });' );
	}

	private function registerScriptUsingProxy() {
		$handle = self::ATE_DASHBOARD_ID;
		$this->proxy->enqueueJS(
			$handle,
			$this->getATEDashboardUrl(), [ ProxyInterceptorLoader::HANDLE_JS ], ICL_SITEPRESS_SCRIPT_VERSION );

		return $handle;
	}

	private function registerScriptWithoutProxy() {
		$handle    = self::ATE_DASHBOARD_ID;
		$src       = $this->getATEDashboardUrl();
		$deps      = [];
		$in_footer = true;
		wp_register_script( self::ATE_DASHBOARD_ID, $src, $deps, ICL_SITEPRESS_SCRIPT_VERSION, $in_footer );
		wp_enqueue_script( $handle );

		return $handle;
	}

	private function getATEDashboardUrl() {
		return $this->endpoints->get_ate_dashboard_url();
	}

	public function getRegisteredScriptUrl() {
		$wp_scripts = wp_scripts();

		if ( isset( $wp_scripts->registered[ self::ATE_DASHBOARD_ID ] ) ) {
			return $wp_scripts->registered[ self::ATE_DASHBOARD_ID ]->src;
		}

		return false;
	}
}
