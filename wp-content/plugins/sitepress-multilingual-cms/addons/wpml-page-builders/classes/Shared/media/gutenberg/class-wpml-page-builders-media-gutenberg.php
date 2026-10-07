<?php

class WPML_Page_Builders_Media_Gutenberg {

	const TYPE_URL = 'media-url';
	const TYPE_IDS = 'media-ids';

	private $media_translate;

	private $target_lang;

	private $source_lang;

	private $config;

	private $block_name;

	public function __construct( IWPML_PB_Media_Find_And_Translate $media_translate, array $config ) {
		$this->media_translate = $media_translate;
		$this->config          = $config;
	}

	public function translate( array $block ) {
		if ( ! isset( $block['blockName'], $block['attrs'] ) ) {
			return $block;
		}

		$this->block_name = $block['blockName'];
		$block_config     = $this->get_block_config( $block['blockName'] );

		if ( $block_config && isset( $block_config['key'] ) ) {
			$block['attrs'] = $this->translate_attributes( $block['attrs'], $block_config['key'] );
		}

		return $block;
	}

	private function get_block_config( $block_name ) {
		list( $namespace ) = explode( '/', $block_name, 2 );

		return array_merge(
			isset( $this->config[ $namespace ] ) ? $this->config[ $namespace ] : [],
			isset( $this->config[ $block_name ] ) ? $this->config[ $block_name ] : []
		);
	}

	private function translate_attributes( array $attrs, array $keys_config ) {
		foreach ( $keys_config as $path => $type ) {
			if ( self::TYPE_URL === $type || self::TYPE_IDS === $type ) {
				$attrs = $this->translate_by_path( $attrs, explode( '>', $path ), $type );
			}
		}

		return $attrs;
	}

	private function translate_by_path( $attrs, $path, $type ) {
		$current_key = reset( $path );
		$next_path   = array_slice( $path, 1 );

		if ( $current_key && isset( $attrs[ $current_key ] ) ) {
			if ( $next_path ) {
				$attrs[ $current_key ] = $this->translate_by_path( $attrs[ $current_key ], $next_path, $type );
			} else {
				$attrs[ $current_key ] = $this->translate_value( $attrs[ $current_key ], $type );
			}
		}

		return $attrs;
	}

	private function translate_value( $value, $type ) {
		if ( ! is_string( $value ) || empty( $value ) ) {
			return $value;
		}

		if ( self::TYPE_URL === $type ) {
			return $this->media_translate->translate_image_url( $value, $this->target_lang, $this->source_lang, $this->block_name );
		}

		if ( self::TYPE_IDS === $type ) {
			return $this->translate_ids( $value );
		}

		return $value;
	}

	private function translate_ids( $value ) {
		$ids = explode( ',', $value );

		foreach ( $ids as &$id ) {
			$id = $this->media_translate->translate_id( (int) $id, $this->target_lang );
		}

		return implode( ',', $ids );
	}

	public function set_target_lang( $target_lang ) {
		$this->target_lang = $target_lang;

		return $this;
	}

	public function set_source_lang( $source_lang ) {
		$this->source_lang = $source_lang;

		return $this;
	}

	public function get_media() {
		return $this->media_translate->get_used_media_in_post();
	}
}
