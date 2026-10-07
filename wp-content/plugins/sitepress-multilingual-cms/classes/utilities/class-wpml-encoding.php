<?php

class WPML_Encoding {

	public static function decode( $string, $encodings ) {
		$decoded_data = $string;

		foreach ( array_reverse( explode( ',', $encodings ) ) as $encoding ) {
			switch ( $encoding ) {
				case 'json':
					$decoded_data = json_decode( $decoded_data, true );
					break;

				case 'base64':
					$decoded_data = base64_decode( $decoded_data );
					break;

				case 'urlencode':
					$decoded_data = urldecode( $decoded_data );
					break;
			}
		}

		return apply_filters( 'wpml_decode_string', $decoded_data, $string, $encodings );
	}

	public static function encode( $data, $encodings ) {
		$encoded_data = $data;

		foreach ( explode( ',', $encodings ) as $encoding ) {
			switch ( $encoding ) {
				case 'json':
					$encoded_data = wp_json_encode( $encoded_data );
					break;

				case 'base64':
					$encoded_data = base64_encode( $encoded_data );
					break;

				case 'urlencode':
					$encoded_data = urlencode( $encoded_data );
					break;
			}
		}

		return apply_filters( 'wpml_encode_string', $encoded_data, $data, $encodings );
	}
}

