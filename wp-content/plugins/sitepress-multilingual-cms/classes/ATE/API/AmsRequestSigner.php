<?php

namespace WPML\TM\ATE\API;

class AmsRequestSigner {

	private $wpHttp;

	private $auth;

	private $fingerprintGenerator;

	public function __construct(
		\WP_Http $wpHttp,
		\WPML_TM_ATE_Authentication $auth,
		FingerprintGenerator $fingerprintGenerator
	) {
		$this->wpHttp               = $wpHttp;
		$this->auth                 = $auth;
		$this->fingerprintGenerator = $fingerprintGenerator;
	}

	public function send( string $url, string $method, array $queryParams = [] ) {
		$headers = [
			'Accept'                                          => 'application/json',
			'Content-Type'                                    => 'application/json',
			FingerprintGenerator::NEW_SITE_FINGERPRINT_HEADER => $this->fingerprintGenerator->getSiteFingerprint(),
		];

		$url_parts = wp_parse_url( $url );

		$query          = [ 'token' => uuid_v5( wp_generate_uuid4(), $url ) ];
		$query          = array_merge( $queryParams, $query );
		$url_parts['query'] = http_build_query( $query );

		$url        = http_build_url( $url_parts );
		$signed_url = $this->auth->signUrl( $method, $url );

		return $this->wpHttp->request( $signed_url, [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $this->getTimeout(),
		] );
	}

	private function getTimeout(): int {
		$max_execution_time = ini_get( 'max_execution_time' );
		$timeout = $max_execution_time ? (int) $max_execution_time / 2 : 1;

		return max( $timeout, 10 );
	}
}
