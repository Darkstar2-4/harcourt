<?php

namespace WPML\ATE\Proxies;

use WPML_TM_ATE_AMS_Endpoints;

class ProxyRoutingRules {

	public static function getAllowedDomains() {
		$ateEndpoints = new WPML_TM_ATE_AMS_Endpoints();

		return [
			$ateEndpoints->get_ATE_host(),
			$ateEndpoints->get_AMS_host(),
		];
	}

	public function getBypassedHttpRequests() {
		$ateEndpoints = new WPML_TM_ATE_AMS_Endpoints();
		return [
			$ateEndpoints->get_AMS_base_url() . '/api/wpml',
		];
	}
}
