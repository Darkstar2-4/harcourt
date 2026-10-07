<?php

namespace WPML\TM\ATE\ClonedSites;

class AliasDomainResetFlag {

	const OPTION = 'wpml_cloned_site_banner_context';
	const VALUE  = 'alias_domain_reset';

	public static function set() {
		update_option( self::OPTION, self::VALUE, 'no' );
	}

	public static function isSet(): bool {
		return get_option( self::OPTION, '' ) === self::VALUE;
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
