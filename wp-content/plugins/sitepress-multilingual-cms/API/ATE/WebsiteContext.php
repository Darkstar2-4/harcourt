<?php

namespace WPML\TM\API\ATE;

use WPML\FP\Either;
use WPML\FP\Fns;
use WPML\LIB\WP\WordPress;
use function WPML\Container\make;

class WebsiteContext {

	public static function getWebsiteContext() {
		$websiteContext = make( \WPML_TM_ATE_API::class )->get_website_context();

		if ( $websiteContext instanceof \WP_Error ) {
			return [
				'error' => $websiteContext->get_error_message(),
			];
		}

		return (array) $websiteContext;
	}
}
