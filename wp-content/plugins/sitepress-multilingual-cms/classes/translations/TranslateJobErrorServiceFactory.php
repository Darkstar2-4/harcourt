<?php

namespace WPML\Translation;

use WPML\Core\Component\Translation\Application\Service\TranslateJobErrorService;

class TranslateJobErrorServiceFactory {
	private static $instance = null;

	public static function create(): TranslateJobErrorService {
		if ( null === self::$instance ) {
			self::$instance = self::createNewInstance();
		}

		return self::$instance;
	}

	public static function setService( $instance ) {
		self::$instance = $instance;
	}

	private static function createNewInstance(): TranslateJobErrorService {
		global $wpml_dic;

		return $wpml_dic->make( TranslateJobErrorService::class );
	}
}
