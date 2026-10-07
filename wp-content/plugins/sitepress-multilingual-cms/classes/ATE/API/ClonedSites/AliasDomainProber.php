<?php

namespace WPML\TM\ATE\ClonedSites;

class AliasDomainProber {

	const TIMEOUT_SECONDS = 10;

	public function probe( $aliasUrl, $token ) {
		$url = add_query_arg(
			AliasDomainCheckHandler::GET_PARAM,
			$token,
			trailingslashit( $aliasUrl )
		);

		$response = wp_remote_get( $url, [
			'timeout'   => self::TIMEOUT_SECONDS,
			'sslverify' => false,
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		return $code === 200
			&& strpos( $body, AliasDomainCheckHandler::RESPONSE_BODY ) !== false;
	}

	public function isSameDatabase( $expectedToken ) {
		return AliasDomainCheckHandler::getAndDeleteToken() === $expectedToken;
	}

	public function verify( $aliasUrl ) {
		$token = wp_generate_password( 32, false );

		if ( ! $this->probe( $aliasUrl, $token ) ) {
			return false;
		}

		return $this->isSameDatabase( $token );
	}
}
