<?php

use WPML\SuperGlobals\Server;
use WPML\UrlHandling\WPLoginUrlConverter;

class WPML_URL_Converter {
	private $strategy;

	protected $default_language;

	protected $active_languages;

	protected $home_url_helper;

	protected $lang_param;

	protected $slash_helper;

	protected $object_url_helper;

	public function __construct(
		IWPML_URL_Converter_Strategy $strategy,
		IWPML_Resolve_Object_Url $object_url_helper,
		$default_language,
		$active_languages
	) {
		$this->strategy          = $strategy;
		$this->object_url_helper = $object_url_helper;
		$this->default_language  = $default_language;
		$this->active_languages  = $active_languages;

		$this->lang_param   = new WPML_URL_Converter_Lang_Param_Helper( $active_languages );
		$this->slash_helper = new WPML_Slash_Management();
	}

	public function get_strategy() {
		return $this->strategy;
	}

	public function set_url_helper( WPML_URL_Converter_Url_Helper $url_helper ) {
		$this->home_url_helper = $url_helper;

		if ( $this->strategy instanceof WPML_URL_Converter_Abstract_Strategy ) {
			$this->strategy->set_url_helper( $url_helper );
		}
	}

	public function get_url_helper() {
		if ( ! $this->home_url_helper ) {
			$this->home_url_helper = new WPML_URL_Converter_Url_Helper();
		}

		return $this->home_url_helper;
	}

	public function get_abs_home() {
		return $this->get_url_helper()->get_abs_home();
	}

	public function set_lang_param_helper( WPML_URL_Converter_Lang_Param_Helper $lang_param_helper ) {
		$this->lang_param = $lang_param_helper;
	}

	public function set_slash_helper( WPML_Slash_Management $slash_helper ) {
		$this->slash_helper = $slash_helper;
	}

	public function get_default_site_url() {
		return $this->get_url_helper()->get_unfiltered_home_option();
	}

	public function convert_url( $url, $lang_code = false ) {
		if ( ! $url ) {
			return $url;
		}

		global $sitepress;

		$new_url = false;
		if ( ! $lang_code ) {
			$lang_code = $sitepress->get_current_language();
		}
		$language_from_url = $this->get_language_from_url( $url );

		if ( $language_from_url === $lang_code || 'all' === $lang_code ) {
			$new_url = $url;
		} else {
			if ( $this->can_resolve_object_url( $url ) ) {
				$new_url = $this->object_url_helper->resolve_object_url( $url, $lang_code );
			}

			if ( false === $new_url ) {
				$new_url = $this->strategy->convert_url_string( $url, $lang_code );
			}
		}

		return $new_url;
	}

	public function get_language_from_url( $url ) {
		$http_referer_factory = new WPML_URL_HTTP_Referer_Factory();
		$http_referer         = $http_referer_factory->create();
		$url                  = $http_referer->get_url( $url );
		$language             = $this->lang_param->lang_by_param( $url ) ?: $this->get_strategy()->get_lang_from_url_string( $url );

		$language = apply_filters( 'wpml_get_language_from_url', $language, $url );

		return $this->get_strategy()->validate_language( $language, $url );
	}

	public function get_home_url_relative( $url, $language ) {
		return $this->get_strategy()->get_home_url_relative( $url, $language );
	}

	public function get_wp_login_url_converter( $sitepress ) {
		return $this->strategy->use_wp_login_url_converter()
			? new WPLoginUrlConverter( $sitepress, $this )
			: null;
	}

	private function can_resolve_object_url( $url ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$server_name = strpos( $request_uri, '/' ) === 0
			? untrailingslashit( Server::getServerName() ) : trailingslashit( Server::getServerName() );
		$request_url = stripos( get_option( 'siteurl' ), 'https://' ) === 0
			? 'https://' . $server_name . $request_uri : 'http://' . $server_name . $request_uri;

		$is_request_url     = trailingslashit( $request_url ) === trailingslashit( $url );
		$is_home_url        = trailingslashit( $this->get_url_helper()->get_abs_home() ) === trailingslashit( $url );
		$is_home_url_filter = current_filter() === 'home_url';

		return $is_request_url && ! $is_home_url && ! $is_home_url_filter;
	}

	public static function getGlobalInstance() {
		global $wpml_url_converter;
		return $wpml_url_converter;
	}
}
