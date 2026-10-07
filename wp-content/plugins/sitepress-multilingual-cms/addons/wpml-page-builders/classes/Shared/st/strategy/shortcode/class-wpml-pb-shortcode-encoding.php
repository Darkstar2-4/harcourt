<?php

class WPML_PB_Shortcode_Encoding {
	const ENCODE_TYPES_BASE64                 = 'base64';
	const ENCODE_TYPES_VISUAL_COMPOSER_LINK   = 'vc_link';
	const ENCODE_TYPES_VISUAL_COMPOSER_VALUES = 'vc_values';
	const ENCODE_TYPES_ENFOLD_LINK            = 'av_link';

	public function decode( $content, $encoding, $encoding_condition = '' ) {
		$encoded_content = $content;

		if (
			! is_string( $content ) ||
			( $encoding_condition && ! $this->should_decode( $encoding_condition ) )
		) {
			return html_entity_decode( $content );
		}

		switch ( $encoding ) {
			case self::ENCODE_TYPES_BASE64:
				$content = html_entity_decode( rawurldecode( base64_decode( wp_strip_all_tags( $content ) ) ) );
				break;

			case self::ENCODE_TYPES_VISUAL_COMPOSER_LINK:
				$parts   = explode( '|', $content );
				$content = [];
				foreach ( $parts as $part ) {
					$data = explode( ':', $part );
					if ( count( $data ) === 2 ) {
						if ( in_array( $data[0], [ 'url', 'title' ], true ) ) {
							$content[ $data[0] ] = [
								'value'     => urldecode( $data[1] ),
								'translate' => true,
							];
						} else {
							$content[ $data[0] ] = [
								'value'     => urldecode( $data[1] ),
								'translate' => false,
							];
						}
					}
				}
				break;

			case self::ENCODE_TYPES_VISUAL_COMPOSER_VALUES:
				$content = [];
				$rows    = (array) json_decode( urldecode( $encoded_content ), true );
				foreach ( $rows as $i => $row ) {
					foreach ( $row as $key => $value ) {
						if ( 'label' === $key ) {
							$content[ $key . '_' . $i ] = [
								'value'     => $value,
								'translate' => true,
							];
						} else {
							$content[ $key . '_' . $i ] = [
								'value'     => $value,
								'translate' => false,
							];
						}
					}
				}
				break;

			case self::ENCODE_TYPES_ENFOLD_LINK:
				$link = explode( ',', $content, 2 );
				if ( 'manually' === $link[0] ) {
					$content = $link[1];
				} elseif ( post_type_exists( $link[0] ) ) {
					$content = get_permalink( (int) $link[1] );
				} elseif ( taxonomy_exists( $link[0] ) ) {
					$term_link = get_term_link( get_term( (int) $link[1], $link[0] ) );
					if ( ! is_wp_error( $term_link ) ) {
						$content = $term_link;
					}
				}
				break;
		}

		return apply_filters( 'wpml_pb_shortcode_decode', $content, $encoding, $encoded_content );
	}

	public function encode( $content, $encoding ) {
		$decoded_content = $content;

		switch ( $encoding ) {
			case self::ENCODE_TYPES_BASE64:
				$content = base64_encode( $content );
				break;

			case self::ENCODE_TYPES_VISUAL_COMPOSER_LINK:
				$output = '';
				if ( is_array( $content ) ) {
					foreach ( $content as $key => $value ) {
						$output .= $key . ':' . rawurlencode( $value ) . '|';
					}
				}
				$content = $output;
				break;

			case self::ENCODE_TYPES_VISUAL_COMPOSER_VALUES:
				$output = [];
				foreach ( (array) $decoded_content as $combined_key => $value ) {
					$parts = explode( '_', $combined_key );
					$i     = array_pop( $parts );
					$key   = implode( '_', $parts );
					if ( ! isset( $output[ $i ] ) ) {
						$output[ $i ] = [];
					}
					$output[ $i ][ $key ] = $value;
				}
				$content = rawurlencode( wp_json_encode( $output ) );
				break;

			case self::ENCODE_TYPES_ENFOLD_LINK:
				$link = explode( ',', $content, 2 );
				if ( 'lightbox' !== $link[0] ) {
					$content = 'manually,' . $content;
				}
				break;

		}

		return apply_filters( 'wpml_pb_shortcode_encode', $content, $encoding, $decoded_content );
	}

	private function should_decode( $condition ) {
		preg_match( '/(?P<type>\w+):(?P<field>\w+)=(?P<value>\w+)/', $condition, $matches );

		return 'option' === $matches['type'] && get_option( $matches['field'] ) === $matches['value'];
	}
}
