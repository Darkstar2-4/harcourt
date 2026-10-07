<?php

class WPML_XML2Array implements WPML_XML_Transform {
	private $contents;
	private $get_attributes;

	public function get( $contents, $get_attributes = true ) {
		$this->contents       = $contents;
		$this->get_attributes = (bool) $get_attributes;

		$xml_values = array();

		if ( $this->contents && function_exists( 'xml_parser_create' ) ) {
			$parser = xml_parser_create();
			xml_parser_set_option( $parser, XML_OPTION_CASE_FOLDING, 0 );
			xml_parser_set_option( $parser, XML_OPTION_SKIP_WHITE, 1 );
			xml_parse_into_struct( $parser, $this->contents, $xml_values );
			unset( $parser );
		}

		$xml_array = array();

		$current = &$xml_array;

		foreach ( $xml_values as $data ) {
			unset( $attributes, $value );

			$tag        = $data['tag'];
			$type       = $data['type'];
			$level      = $data['level'];
			$value      = isset( $data['value'] ) ? $data['value'] : '';
			$attributes = isset( $data['attributes'] ) ? $data['attributes'] : array();
			$item       = $this->get_item( $value, $attributes );

			if ( 'open' === $type ) {
				$parent[ $level - 1 ] = &$current;

				if ( ! is_array( $current ) || ( ! isset( $current[ $tag ] ) ) ) {
					$current[ $tag ] = $item;
					$current         = &$current[ $tag ];
				} else {
					if ( isset( $current[ $tag ][0] ) ) {
						$current[ $tag ][] = $item;
					} else {
						$current[ $tag ] = array( $current[ $tag ], $item );
					}
					$last    = count( $current[ $tag ] ) - 1;
					$current = &$current[ $tag ][ $last ];
				}
			} elseif ( 'complete' === $type ) {
				if ( ! isset( $current[ $tag ] ) ) {
					$current[ $tag ] = $item;
				} else {
					if ( ( is_array( $current[ $tag ] ) && ! $this->get_attributes )
						 || ( isset( $current[ $tag ][0] ) && is_array( $current[ $tag ][0] ) && $this->get_attributes )
					) {
						$current[ $tag ][] = $item;
					} else {
						$current[ $tag ] = array( $current[ $tag ], $item );
					}
				}
			} elseif ( 'close' === $type && isset( $parent ) ) {
				$current = &$parent[ $level - 1 ];
			}
		}

		return $xml_array;
	}

	private function get_item( $value, array $attributes ) {
		$item = array();

		if ( $this->get_attributes ) {
			if ( null !== $value ) {
				$item['value'] = $value;
			}

			if ( null !== $attributes ) {
				foreach ( $attributes as $attr => $val ) {
					$item['attr'][ $attr ] = $val;
				}
			}
		} else {
			$item = $value;
		}

		return $item;
	}
}
