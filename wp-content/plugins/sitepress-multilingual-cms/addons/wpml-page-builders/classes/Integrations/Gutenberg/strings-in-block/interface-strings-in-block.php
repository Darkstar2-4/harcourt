<?php

namespace WPML\PB\Gutenberg\StringsInBlock;

interface StringsInBlock {

	public function find( \WP_Block_Parser_Block $block );

	public function update( \WP_Block_Parser_Block $block, array $string_translations, $lang );
}
