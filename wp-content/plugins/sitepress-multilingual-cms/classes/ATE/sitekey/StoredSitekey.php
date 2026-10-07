<?php

namespace WPML\TM\ATE\Sitekey;

class StoredSitekey {

	const TRANSIENT_KEY = 'wpml_tm_stored_sitekey';
	const EXPIRATION = 3 * DAY_IN_SECONDS;

	public static function store( $sitekey ) {
		set_transient( self::TRANSIENT_KEY, $sitekey, self::EXPIRATION );
	}

	public static function get() {
		$value = get_transient( self::TRANSIENT_KEY );

		return $value ?: null;
	}

	public static function clear() {
		delete_transient( self::TRANSIENT_KEY );
	}
}
