<?php

namespace OTGS\Installer\Api\Client;

use OTGS\Installer\Api\Exception\ClientException;

class Client {
	private $http;

	private $url;

	const TIMEOUT = 45;

	public function __construct(
		\WP_Http $http,
		$url
	) {
		$this->http = $http;
		$this->url = $url;
	}

	public function post( $body ) {
		$args = [
			'timeout' => self::TIMEOUT,
			'body'    => $body,
		];

		$response = $this->http->post( $this->url, $args );

		if ( is_wp_error( $response ) ) {
			throw new ClientException( $response->get_error_message() );
		}

		return $response;
	}
}