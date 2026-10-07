<?php

use WPML\FP\Str;

class WPML_URL_Converter_Lang_Param_Helper {
	private static $cache = array();

	private $active_languages;

	public function __construct( array $active_languages ) {
		$this->active_languages = $active_languages;
	}

	public function lang_by_param( $url, $only_admin = true ) {
		if ( isset( self::$cache[ $url ] ) ) {
			return self::$cache[ $url ];
		}

		$lang = $this->extract_lang_param_from_url( $url, $only_admin );

		self::$cache[ $url ] = $lang;

		return $lang;
	}

	private function extract_lang_param_from_url( $url, $only_admin ) {
		$url             = wpml_strip_subdir_from_url( $url );
		$url_query_parts = wpml_parse_url( $url );
		$url_query       = $this->has_query_part( $only_admin, $url_query_parts ) ? untrailingslashit( $url_query_parts['query'] ) : null;
		$isLoginPageUrl  = Str::includes( 'wp-login.php', $_SERVER['REQUEST_URI'] );
		$isLoginPage     = function( $vars ) use ( $isLoginPageUrl ) {
			return $isLoginPageUrl && isset( $vars['wp_lang'] ) && is_string( $vars['wp_lang'] );
		};
		$getWpLang       = function( $vars ) {
			$wp_lang = explode( '_', $vars['wp_lang'] );
			$wp_lang = ( count( $wp_lang ) > 0 ) ? strtolower( $wp_lang[0] ) : null;

			return in_array( $wp_lang, $this->active_languages, true ) ? $wp_lang : null;
		};

		if ( null !== $url_query ) {
			parse_str( $url_query, $vars );
			if ( $this->can_retrieve_lang_from_query( $only_admin, $vars ) ) {
				return $vars['lang'];
			} else if ( $isLoginPage( $vars ) ) {
				return $getWpLang( $vars );
			}
		}

		if ( is_array( $url_query_parts ) && isset( $url_query_parts['query'] ) && is_string( $url_query_parts['query'] ) ) {
			parse_str( $url_query_parts['query'], $vars );
			if ( $isLoginPage( $vars ) ) {
				return $getWpLang( $vars );
			}
		}

		return null;
	}

	private function has_query_part( $only_admin, $url_query_parts ) {
		if ( ! isset( $url_query_parts['query'] ) ) {
			return false;
		}

		if ( false === $only_admin ) {
			return true;
		}

		if ( ! isset( $url_query_parts['path'] ) ) {
			return false;
		}

		if ( false === strpos( $url_query_parts['path'], '/wp-admin' ) ) {
			return false;
		}

		return true;
	}

	private function can_retrieve_lang_from_query( $only_admin, $vars ) {
		if ( ! isset( $vars['lang'] ) ) {
			return false;
		}

		if ( $only_admin && 'all' === $vars['lang'] ) {
			return true;
		}

		if ( in_array( $vars['lang'], $this->active_languages, true ) ) {
			return true;
		}

		return false;
	}
}
