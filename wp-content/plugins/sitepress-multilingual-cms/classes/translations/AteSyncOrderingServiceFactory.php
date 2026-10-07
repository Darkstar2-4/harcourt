<?php

namespace WPML\Translation;

use WPML\Core\Component\Translation\Application\Service\Priority\AteSyncOrderingService;

class AteSyncOrderingServiceFactory {
	private static $instance = null;

	public static function create(): AteSyncOrderingService {
		if ( null === self::$instance ) {
			self::$instance = self::createNewInstance();
		}

		return self::$instance;
	}

	public static function setService( $instance ) {
		self::$instance = $instance;
	}

	private static function createNewInstance(): AteSyncOrderingService {
		global $wpml_dic;

		return $wpml_dic->make( AteSyncOrderingService::class );
	}
}
