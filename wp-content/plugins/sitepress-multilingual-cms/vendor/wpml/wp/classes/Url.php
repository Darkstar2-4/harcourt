<?php


namespace WPML\LIB\WP;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Str;
use function WPML\FP\curryN;

class Url {
	use Macroable;
	
	public static function init() {
		self::macro( 'isLogin', curryN( 1, function ( $url ) {
			return Str::includes( wp_login_url(), $url );
		} ) );
		
		self::macro( 'isAdmin', curryN( 1, function ( $url ) {
			return Str::includes( admin_url(), $url );
		} ) );
		
		self::macro( 'isContentDirectory', curryN( 1, function ( $url ) {
			return Str::includes( WP_CONTENT_URL, $url );
		} ) );
	}
}

Url::init();