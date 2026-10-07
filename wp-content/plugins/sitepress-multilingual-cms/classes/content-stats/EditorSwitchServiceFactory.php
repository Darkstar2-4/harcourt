<?php

namespace WPML\ContentStats;

use WPML\Core\Component\ReportContentStats\Application\Service\EditorSwitchService;

class EditorSwitchServiceFactory {

	private static $instance = null;


	public static function create(): EditorSwitchService {
		if ( self::$instance === null ) {
			self::$instance = self::createNewInstance();
		}

		return self::$instance;
	}


	private static function createNewInstance(): EditorSwitchService {
		global $wpml_dic;

		return $wpml_dic->make( EditorSwitchService::class );
	}

}
