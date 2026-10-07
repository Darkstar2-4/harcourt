<?php

namespace WPML\Translation;

use WPML\Core\Component\Translation\Application\Service\CompletedTranslationService;

class CompletedTranslationServiceFactory {
	private static $instance = null;

	public static function create(): CompletedTranslationService {
		if ( self::$instance === null ) {
			self::$instance = self::createNewInstance();
		}

		return self::$instance;
	}

	public static function setService( CompletedTranslationService $instance ) {
		self::$instance = $instance;
	}

	private static function createNewInstance(): CompletedTranslationService {
		global $wpml_dic;

		return $wpml_dic->make( CompletedTranslationService::class );
	}
}
