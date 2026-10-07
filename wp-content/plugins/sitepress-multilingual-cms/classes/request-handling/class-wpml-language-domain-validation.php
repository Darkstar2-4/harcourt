<?php

class WPML_Language_Domain_Validation {
	const VALIDATE_DOMAIN_KEY = '____icl_validate_domain';

	private $wp_api;
	private $http;
	private $url;
	private $validation_url;


	public function __construct( WPML_WP_API $wp_api, WP_Http $http) {
		$this->wp_api         = $wp_api;
		$this->http           = $http;
	}

	public function is_valid( $url ) {
		$this->url            = $url;
		$this->validation_url = $this->get_validation_url();
		if ( ! $this->has_scheme_and_host() ) {
			return false;
		}

		if ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL ) {
			return true;
		}

		$response = $this->get_validation_response();

		if ( $this->is_valid_response( $response ) ) {
			return in_array( $response['body'], $this->get_accepted_responses( $this->validation_url ), true );
		}

		return false;
	}

	private function has_scheme_and_host() {
		$url_parts = wpml_parse_url( $this->url );
		return array_key_exists( 'scheme', $url_parts ) && array_key_exists( 'host', $url_parts );
	}

	private function get_validation_url() {
		return add_query_arg( array( self::VALIDATE_DOMAIN_KEY => 1 ), trailingslashit( $this->url ) );
	}

	private function get_accepted_responses( $url ) {
		$accepted_responses = array(
			'<!--' . untrailingslashit( $this->wp_api->get_home_url() ) . '-->',
			'<!--' . untrailingslashit( $this->wp_api->get_site_url() ) . '-->',
		);
		if ( defined( 'SUNRISE' ) && SUNRISE === 'on' ) {
			$accepted_responses[] = '<!--' . str_replace( '?' . self::VALIDATE_DOMAIN_KEY . '=1', '', $url ) . '-->';
			return $accepted_responses;
		}
		return $accepted_responses;
	}

	private function get_validation_response() {
		return $this->http->request( $this->validation_url, 'timeout=15' );
	}

	private function is_valid_response( $response ) {
		return ! is_wp_error( $response ) && '200' === (string) $response['response']['code'];
	}
}
