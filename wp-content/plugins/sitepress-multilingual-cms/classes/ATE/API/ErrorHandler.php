<?php

namespace WPML\TM\ATE\API;

class ErrorHandler {

	public static function createError( $message, $rawResponse = null ) {

		if ( null !== $rawResponse ) {
			$message['raw_response'] = self::normalizeRawResponse( $rawResponse );
		}

		return $message;
	}

	private static function normalizeRawResponse( $rawResponse ) {
		if ( is_wp_error( $rawResponse ) ) {
			return self::normalizeWpError( $rawResponse );
		}

		if ( is_array( $rawResponse ) ) {
			return self::normalizeHttpResponse( $rawResponse );
		}

		return [
			'type' => 'unknown',
			'data' => $rawResponse,
		];
	}

	private static function normalizeWpError( $error ) {
		return [
			'type'          => 'wp_error',
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			'error_data'    => $error->get_error_data(),
		];
	}

	private static function normalizeHttpResponse( $response ) {
		$normalized = [
			'type' => 'http_response',
		];

		if ( isset( $response['response'] ) ) {
			$normalized['status_code'] = isset( $response['response']['code'] ) ? $response['response']['code'] : null;
			$normalized['status_message'] = isset( $response['response']['message'] ) ? $response['response']['message'] : null;
		}

		if ( isset( $response['headers'] ) ) {
			$normalized['headers'] = is_object( $response['headers'] )
				? $response['headers']->getAll()
				: $response['headers'];
		}

		if ( isset( $response['body'] ) ) {
			$normalized['body'] = $response['body'];
		}

		return $normalized;
	}

}
