<?php

namespace WPML\Translation;

use WPML\Core\Component\Translation\Application\Service\PreviousState\PreviousStateService;

class PreviousStateServiceFactory {
	private static $instance = null;

	public static function create(): PreviousStateService {
		if ( self::$instance === null ) {
			self::$instance = self::createNewInstance();
		}

		return self::$instance;
	}

	public static function setService( PreviousStateService $instance ) {
		self::$instance = $instance;
	}

	private static function createNewInstance(): PreviousStateService {
		global $wpml_dic;

		return $wpml_dic->make( PreviousStateService::class );
	}
}