<?php

class WPML_Page_Builders_Media_Shortcodes {

	const TYPE_URL = 'media-url';
	const TYPE_IDS = 'media-ids';

	private $media_translate;

	private $target_lang;

	private $source_lang;

	private $config;

	private $tag_name;

	public function __construct( IWPML_PB_Media_Find_And_Translate $media_translate, array $config ) {
		$this->media_translate = $media_translate;
		$this->config          = $config;
	}

	public function translate( $content ) {
		$urls = $this->extract_media_urls_from_content( $content );
		if ( ! empty( $urls ) ) {
			$this->media_translate->prefetch_media_urls( $urls, $this->source_lang );
		}

		foreach ( $this->config as $shortcode ) {
			$shortcode = $this->sanitize_shortcode( $shortcode );
			$tag_name  = isset( $shortcode['tag']['name'] ) ? $shortcode['tag']['name'] : '';

			$this->tag_name = $tag_name;

			if ( ! empty( $shortcode['attributes'] ) ) {
				$content = $this->translate_attributes( $content, $tag_name, $shortcode['attributes'] );
			}

			if ( ! empty( $shortcode['content'] ) ) {
				$content = $this->translate_content( $content, $tag_name, $shortcode['content'] );
			}
		}

		return $content;
	}

	private function extract_media_urls_from_content( $content ) {
		$urls = array();

		foreach ( $this->config as $shortcode ) {
			$shortcode = $this->sanitize_shortcode( $shortcode );
			$tag_name  = isset( $shortcode['tag']['name'] ) ? $shortcode['tag']['name'] : '';

			if ( ! $tag_name ) {
				continue;
			}

			if ( ! empty( $shortcode['attributes'] ) ) {
				$urls = array_merge( $urls, $this->extract_urls_from_shortcode_attributes( $content, $tag_name, $shortcode['attributes'] ) );
			}

			if ( ! empty( $shortcode['content'] ) && isset( $shortcode['content']['type'] ) && self::TYPE_URL === $shortcode['content']['type'] ) {
				$urls = array_merge( $urls, $this->extract_urls_from_shortcode_content( $content, $tag_name ) );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function extract_urls_from_shortcode_attributes( $content, $tag_name, array $attributes ) {
		$urls = array();

		foreach ( $attributes as $attribute => $data ) {
			$type = isset( $data['type'] ) ? $data['type'] : '';
			if ( self::TYPE_URL !== $type ) {
				continue;
			}
			$pattern = $this->get_attribute_pattern( $tag_name, $attribute );
			if ( preg_match_all( $pattern, $content, $matches ) && ! empty( $matches[2] ) ) {
				$urls = array_merge( $urls, $matches[2] );
			}
		}

		return $urls;
	}

	private function extract_urls_from_shortcode_content( $content, $tag_name ) {
		$pattern = $this->get_content_pattern( $tag_name );
		if ( preg_match_all( $pattern, $content, $matches ) && ! empty( $matches[2] ) ) {
			return array_map( 'trim', $matches[2] );
		}

		return array();
	}

	public function has_media_shortcode( $content ) {
		if ( false === strpos( $content, '[' ) ) {
			return false;
		};

		foreach ( $this->config as $shortcode ) {
			$shortcode = $this->sanitize_shortcode( $shortcode );
			$tag_name  = isset( $shortcode['tag']['name'] ) ? $shortcode['tag']['name'] : '';

			if ( $tag_name && false !== strpos( $content, '[' . $tag_name ) ) {
				return true;
			}
		}

		return false;
	}

	private function sanitize_shortcode( array $shortcode ) {
		$defaults = array(
			'attributes' => null,
		);

		return array_merge( $defaults, $shortcode );
	}

	private function get_attribute_pattern( $tag, $attribute ) {
		return '/(\[' . $tag . '(?: [^\]]* | )' . $attribute . '=(?:"|\'))([^"\']*)/';
	}

	private function get_content_pattern( $tag ) {
		return '/(\[(?:' . $tag . ')[^\]]*\])([^\[]+)/';
	}

	private function translate_attributes( $content, $tag, array $attributes ) {
		foreach ( $attributes as $attribute => $data ) {
			$pattern = $this->get_attribute_pattern( $tag, $attribute );
			$type    = isset( $data['type'] ) ? $data['type'] : '';
			$content = preg_replace_callback( $pattern, $this->get_callback( $type ), $content );
		}

		return $content;
	}

	private function translate_content( $content, $tag, array $data ) {
		$pattern = $this->get_content_pattern( $tag );
		$type    = isset( $data['type'] ) ? $data['type'] : '';
		return preg_replace_callback( $pattern, $this->get_callback( $type ), $content );
	}

	private function get_callback( $type ) {
		if ( self::TYPE_URL === $type ) {
			return [ $this, 'replace_url_callback' ];
		}

		return [ $this, 'replace_ids_callback' ];
	}

	private function replace_url_callback( array $matches ) {
		$translated_url = $this->media_translate->translate_image_url( $matches[2], $this->target_lang, $this->source_lang, $this->tag_name );

		return $matches[1] . $translated_url;
	}

	private function replace_ids_callback( array $matches ) {
		$ids = explode( ',', $matches[2] );

		foreach ( $ids as &$id ) {
			$id = $this->media_translate->translate_id( (int) $id, $this->target_lang );
		}

		return $matches[1] . implode( ',', $ids );
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
