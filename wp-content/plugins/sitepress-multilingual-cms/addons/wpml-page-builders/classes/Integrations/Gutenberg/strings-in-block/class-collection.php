<?php

namespace WPML\PB\Gutenberg\StringsInBlock;

class Collection implements StringsInBlock {

	private $parsers = [];

	public function __construct( array $parsers ) {
		$this->parsers = $parsers;
	}

	public function find( \WP_Block_Parser_Block $block ) {
		$strings = [];

		foreach ( $this->parsers as $parser ) {
			$strings = array_merge( $strings, $parser->find( $block ) );
		}

		return apply_filters( 'wpml_found_strings_in_block', $strings, $block );
	}

	public function update( \WP_Block_Parser_Block $block, array $string_translations, $lang ) {
		$originalInnerHTML = $block->innerHTML;
		foreach ( $this->parsers as $parser ) {
			$block = $parser->update( $block, $string_translations, $lang );
		}

		if ( $originalInnerHTML !== $block->innerHTML ) {
			$block->attrs['translatedWithWPMLTM'] = '1';
		}

		return apply_filters( 'wpml_update_strings_in_block', $block, $string_translations, $lang );
	}
}
