<?php

namespace WPML\API;

use WPML\FP\Lst;
use WPML\FP\Obj;

class Settings {

	const WPML_DOWNLOADED_LOCALES_KEY = 'wpml_downloaded_locales';

	public static function get( $key, $default = false ) {
		return self::getOr( $default, $key );
	}

	public static function getOr( $default, $key ) {
		global $sitepress;
		return $sitepress->get_setting( $key, $default );
	}

	public static function set( $key, $value ) {
		global $sitepress;
		return $sitepress->set_setting( $key, $value, false );
	}

	public static function setAndSave( $key, $value ) {
		global $sitepress;
		return $sitepress->set_setting( $key, $value, true );
	}

	public static function assoc( $key, $subKey, $value ) {
		return self::setAndSave( $key, Obj::assoc( $subKey, $value, self::getOr([], $key ) ) );
	}

	public static function pathOr( $default, $path ) {
		$key = Lst::nth( 0, $path );

		return Obj::pathOr( $default, Lst::drop( 1, $path ), self::getOr( [], $key ) );
	}
}
