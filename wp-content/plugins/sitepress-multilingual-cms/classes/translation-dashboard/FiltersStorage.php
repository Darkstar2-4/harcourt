<?php

namespace WPML\TM\TranslationDashboard;

use WPML\API\Sanitize;
use WPML\Element\API\Languages;
use WPML\FP\Obj;

class FiltersStorage {
	public static function get() {
		$result = [];

		$dashboard_filter = Sanitize::stringProp( 'wp-translation_dashboard_filter', $_COOKIE );
		if ( $dashboard_filter ) {
			parse_str( $dashboard_filter, $result );
		}

		return $result;
	}

	public static function getFromLanguage() {
		return Obj::propOr( Languages::getCurrentCode(), 'from_lang', self::get() );
	}
}
