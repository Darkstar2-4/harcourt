<?php

namespace WPML\PB\Helper;

class LanguageNegotiation {

	public static function isUsingDomains() {
		return apply_filters( 'wpml_setting', [], 'language_domains' )
			   && constant( 'WPML_LANGUAGE_NEGOTIATION_TYPE_DOMAIN' ) === (int) apply_filters( 'wpml_setting', 1, 'language_negotiation_type' );
	}

	public static function getDomainByLanguage( $languageCode ) {
		return wpml_collect( self::getMappedDomains() )->first( function( $domain, $code ) use ( $languageCode ) {
			return $languageCode === $code;
		} );
	}

	private static function getMappedDomains() {
		$defaultLanguage = apply_filters( 'wpml_default_language', null );
		$homeUrl         = apply_filters( 'wpml_permalink', get_home_url(), $defaultLanguage );
		$defaultDomain   = wp_parse_url( $homeUrl, PHP_URL_HOST );
		$domains         = apply_filters( 'wpml_setting', [], 'language_domains' );

		$domains[ $defaultLanguage ] = $defaultDomain;

		return $domains;
	}
}
