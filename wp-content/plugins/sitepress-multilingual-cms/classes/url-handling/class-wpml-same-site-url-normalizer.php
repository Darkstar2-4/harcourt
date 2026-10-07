<?php

class WPML_Same_Site_Url_Normalizer {

	private static $cached_home_url = null;

	private static function get_home_url_cached() {
		if ( self::$cached_home_url === null ) {
			$home                  = get_option( 'home' );
			self::$cached_home_url = ( is_string( $home ) && $home !== '' ) ? $home : get_home_url();
			add_action( 'update_option_home', [ __CLASS__, 'invalidate_home_url_cache' ] );
		}

		return self::$cached_home_url;
	}

	public static function reset_cached_home_url() {
		self::$cached_home_url = null;
	}

	public static function invalidate_home_url_cache() {
		self::$cached_home_url = null;
	}

	public static function get_normalized_domain( $url ) {
		$without_scheme = preg_replace( '/^https?:\/\//', '', $url );
		$without_scheme = rtrim( $without_scheme, '/' );
		$host           = wp_parse_url( 'http://' . $without_scheme, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			$host = $without_scheme;
		}
		return preg_replace( '/^www\./i', '', $host );
	}

	public static function is_same_site( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return false;
		}
		if ( preg_match( '/^https?:\/\//', $url ) !== 1 ) {
			return false;
		}
		return self::get_normalized_domain( self::get_home_url_cached() ) === self::get_normalized_domain( $url );
	}

	public static function is_home_url( $url ) {
		if ( ! self::is_same_site( $url ) ) {
			return false;
		}
		$home_url  = self::get_home_url_cached();
		$home_path = trim( (string) wp_parse_url( $home_url, PHP_URL_PATH ), '/' );
		$parsed    = wp_parse_url( $url );
		$url_path  = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
		return $url_path === $home_path;
	}

	public static function get_domain_regex_pattern( $domain, $delimiter = '@' ) {
		$bare_domain = preg_replace( '/^www\./i', '', $domain );

		return '(?:www\.)?' . preg_quote( $bare_domain, $delimiter );
	}

	public static function normalize_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}
		if ( ! self::is_same_site( $url ) ) {
			return $url;
		}
		$home_parsed   = wp_parse_url( self::get_home_url_cached() );
		$canonical_host = isset( $home_parsed['host'] ) ? $home_parsed['host'] : '';
		if ( $canonical_host === '' ) {
			return $url;
		}
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || ! isset( $parsed['host'] ) ) {
			return $url;
		}
		$current_host = $parsed['host'];
		if ( strtolower( $current_host ) === strtolower( $canonical_host ) ) {
			return $url;
		}
		return preg_replace(
			'/^([^:]+:\/\/)([^\/?#]+)/i',
			'$1' . $canonical_host,
			$url,
			1
		);
	}

}