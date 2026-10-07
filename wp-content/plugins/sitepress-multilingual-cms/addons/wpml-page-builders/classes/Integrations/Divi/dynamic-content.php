<?php

namespace WPML\Compatibility\Divi;

use WPML\Compatibility\BaseDynamicContent;

class DynamicContent extends BaseDynamicContent {

	const ENCODED_CONTENT_START = '@ET-DC@';
	const ENCODED_CONTENT_END   = '@';

	protected $positions = [ 'before', 'after', 'custom_text' ];

	public function decode_dynamic_content( $string, $encoding ) {
		if ( $this->is_dynamic_content( $string ) ) {
			$field = $this->decode_field( $string );

			$decodedContent = [
				'et-dynamic-content' => [
					'value'     => $string,
					'translate' => false,
				],
			];

			foreach ( $this->positions as $position ) {
				if ( ! empty( $field['settings'][ $position ] ) ) {
					$decodedContent[ $position ] = [
						'value'     => $field['settings'][ $position ],
						'translate' => true,
					];
				}
			}

			return $decodedContent;
		}

		return $string;
	}

	public function encode_dynamic_content( $string, $encoding ) {
		if ( is_array( $string ) && isset( $string['et-dynamic-content'] ) ) {
			$field = $this->decode_field( $string['et-dynamic-content'] );

			foreach ( $this->positions as $position ) {
				if ( isset( $string[ $position ] ) ) {
					$field['settings'][ $position ] = $string[ $position ];
				}
			}

			return $this->encode_field( $field );
		}

		return $string;
	}

	protected function is_dynamic_content( $string ) {
		return substr( $string, 0, strlen( self::ENCODED_CONTENT_START ) ) === self::ENCODED_CONTENT_START;
	}

	protected function decode_field( $string ) {
		$start = strlen( self::ENCODED_CONTENT_START );
		$end   = strlen( self::ENCODED_CONTENT_END );

		return json_decode( base64_decode( substr( $string, $start, -$end ) ), true );
	}

	protected function encode_field( $field ) {
		return self::ENCODED_CONTENT_START
			. base64_encode( wp_json_encode( $field ) )
			. self::ENCODED_CONTENT_END;
	}
}
