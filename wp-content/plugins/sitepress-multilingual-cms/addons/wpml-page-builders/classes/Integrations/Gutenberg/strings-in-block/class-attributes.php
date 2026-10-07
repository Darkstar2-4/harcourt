<?php

namespace WPML\PB\Gutenberg\StringsInBlock;

use WPML\FP\Obj;

class Attributes extends Base {

	public function find( \WP_Block_Parser_Block $block ) {
		$strings = [];
		$attrs   = $this->getAttributes( $block );

		if ( $attrs ) {
			$keys    = $this->getKeyConfig( $block );
			$strings = $this->findStringsRecursively( $attrs, $keys, $block );
		}

		return $strings;
	}

	private function findStringsRecursively( array $attrs, array $config_keys, \WP_Block_Parser_Block $block ) {
		$strings = [];

		foreach ( $attrs as $attr_key => $attr_value ) {
			$matching_key = $this->getMatchingConfigKey( $attr_key, $config_keys );

			if ( ! $matching_key ) {
				continue;
			}

			if ( $this->shouldSkipMediaType( $config_keys, $matching_key ) ) {
				continue;
			}

			if ( $this->hasJsonEncoding( $attr_key, $config_keys ) ) {
				$attr_value = json_decode( urldecode( $attr_value ), true );
			}

			if ( is_array( $attr_value ) ) {
				$children_config_keys = $this->getChildrenConfigKeys( $config_keys, $matching_key );

				$strings = array_merge(
					$strings,
					$this->findStringsRecursively( $attr_value, $children_config_keys, $block )
				);
			} elseif ( ! is_numeric( $attr_value ) ) {
				$type      = $this->get_attribute_string_type( $attr_value, $attr_key, $config_keys );
				$string_id = $this->get_string_id( $block->blockName, $attr_value );
				$label     = isset( $config_keys[ $attr_key ]['label'] ) ? $config_keys[ $attr_key ]['label'] : $this->get_block_label( $block );
				$strings[] = $this->build_string( $string_id, $label, $attr_value, $type );
			}
		}

		return $strings;
	}

	private function get_attribute_string_type( $attr_value, $attr_key, $config_keys ) {
		$config_type = $config_keys[ $attr_key ]['type'] ?? null;

		if ( 'link' === $config_type ) {
			return \WPML_TM_Page_Builders::FIELD_STYLE_LINK;
		}

		return self::get_string_type( $attr_value );
	}

	private function getMatchingConfigKey( $attr_key, array $config_keys ) {
		if ( isset( $config_keys[ $attr_key ] ) ) {
			return $attr_key;
		}

		foreach ( $config_keys as $config_key => $key_attrs ) {

			if ( preg_match( $this->getRegex( $config_key, $key_attrs ), $attr_key ) ) {
				return $config_key;
			}
		}

		return null;
	}

	private function getChildrenConfigKeys( array $config_keys, $matching_key ) {
		return isset( $config_keys[ $matching_key ]['children'] )
			? $config_keys[ $matching_key ]['children']
			: $this->getMatchAllKey();
	}

	private function getRegex( $config_key, array $key_attrs ) {
		if ( $this->isRegex( $key_attrs ) ) {
			return $config_key;
		}

		return self::getWildcardRegex( $config_key );
	}

	public static function getWildcardRegex( $config_key ) {
		return '/^' . str_replace( '*', 'S+', preg_quote( $config_key, '/' ) ) . '$/';
	}

	private function isRegex( array $key_attrs ) {
		return isset( $key_attrs['search-method'] )
			&& \WPML_Gutenberg_Config_Option::SEARCH_METHOD_REGEX === $key_attrs['search-method'];
	}

	public function update( \WP_Block_Parser_Block $block, array $string_translations, $lang ) {
		$attrs = $this->getAttributes( $block );

		if ( $attrs ) {
			$keys         = $this->getKeyConfig( $block );
			$block->attrs = $this->updateStringsRecursively( $attrs, $keys, $string_translations, $lang, $block->blockName );
		}

		return $block;
	}

	public function updateStringsRecursively( array $attrs, array $config_keys, array $translations, $lang, $block_name ) {
		foreach ( $attrs as $attr_key => $attr_value ) {
			$matching_key = $this->getMatchingConfigKey( $attr_key, $config_keys );

			if ( ! $matching_key ) {
				continue;
			}

			if ( $this->hasJsonEncoding( $attr_key, $config_keys ) ) {
				$attr_value = json_decode( urldecode( $attr_value ), true );
			}

			if ( is_array( $attr_value ) ) {
				$children_config_keys = $this->getChildrenConfigKeys( $config_keys, $matching_key );
				$attrs[ $attr_key ]   = $this->updateStringsRecursively( $attr_value, $children_config_keys, $translations, $lang, $block_name );
			} else {
				$string_id = $this->get_string_id( $block_name, $attr_value );

				if (
					isset( $translations[ $string_id ][ $lang ] ) &&
					ICL_TM_COMPLETE === (int) $translations[ $string_id ][ $lang ]['status']
				) {
					$attrs[ $attr_key ] = $translations[ $string_id ][ $lang ]['value'];
				}
			}

			if ( $this->hasJsonEncoding( $attr_key, $config_keys ) ) {
				$attrs[ $attr_key ] = rawurlencode( wp_json_encode( $attrs[ $attr_key ] ) );
			}
		}

		return $attrs;
	}

	private function hasJsonEncoding( $attr_key, $config_keys ) {
		return 'json' === Obj::path( [ $attr_key, 'encoding' ], $config_keys );
	}

	private function getAttributes( \WP_Block_Parser_Block $block ) {
		return is_array( $block->attrs ) && $block->blockName ? $block->attrs : [];
	}

	private function getKeyConfig( \WP_Block_Parser_Block $block ) {
		$config = $this->get_block_config( $block, 'key' );

		return $config ? $config : [];
	}

	private function getMatchAllKey() {
		return [
			'*' => [
				'search-method' => \WPML_Gutenberg_Config_Option::SEARCH_METHOD_WILDCARD,
			],
		];
	}

	private function shouldSkipMediaType( array $configKeys, $matchingKey ) {
		$configType = $configKeys[ $matchingKey ]['type'] ?? null;

		return in_array(
			$configType,
			[
				\WPML_Page_Builders_Media_Gutenberg::TYPE_URL,
				\WPML_Page_Builders_Media_Gutenberg::TYPE_IDS,
			],
			true
		);
	}
}
