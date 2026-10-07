<?php

namespace WPML\PB\Gutenberg\StringsInBlock;

abstract class Base implements StringsInBlock {

	const LONG_STRING_LENGTH = 80;

	private $block_types;

	private $config_option;

	public function __construct( \WPML_Gutenberg_Config_Option $config_option ) {
		$this->config_option = $config_option;
	}

	protected function get_block_config( \WP_Block_Parser_Block $block, $type ) {
		if ( null === $this->block_types ) {
			$this->block_types = $this->config_option->get();
		}

		if ( isset( $block->blockName, $this->block_types[ $block->blockName ][ $type ] ) ) {
			return $this->block_types[ $block->blockName ][ $type ];
		}

		$namespace_config = $this->get_namespace_config( $block, $type );

		if ( $namespace_config ) {
			return $namespace_config;
		}

		if ( $this->has_empty_config( $block ) ) {
			return [];
		}

		return null;
	}


	protected function get_block_label( \WP_Block_Parser_Block $block ) {
		$label = $this->get_block_config( $block, 'label' );
		if ( ! is_string( $label ) ) {
			$label = $block->blockName;
		}

		return $label;
	}

	public function get_namespace_config( \WP_Block_Parser_Block $block, $type ) {
		if ( isset( $block->blockName ) ) {
			$block_name_arr  = explode( '/', $block->blockName );
			$block_namespace = reset( $block_name_arr );

			if ( isset( $this->block_types[ $block_namespace ][ $type ] ) ) {
				return $this->block_types[ $block_namespace ][ $type ];
			}
		}

		return null;
	}

	private function has_empty_config( \WP_Block_Parser_Block $block ) {
		return isset( $block->blockName, $this->block_types[ $block->blockName ] );
	}

	public static function get_string_type( $string ) {
		$type = 'LINE';
		$string = is_null( $string ) ? '' : $string;

		if ( strpos( $string, "\n" ) !== false || mb_strlen( $string ) > self::LONG_STRING_LENGTH ) {
			$type = 'AREA';
		}

		if ( strpos( $string, '<' ) !== false ) {
			$type = 'VISUAL';
		}

		return $type;
	}

	protected function build_string( $id, $name, $text, $type ) {
		return (object) array(
			'id'    => $id,
			'name'  => $name,
			'value' => $text,
			'type'  => $type,
		);
	}

	protected function get_string_id( $name, $text ) {
		return md5( $name . $text );
	}
}
