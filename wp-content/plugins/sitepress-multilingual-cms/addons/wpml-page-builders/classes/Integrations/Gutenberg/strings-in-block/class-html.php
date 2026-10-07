<?php

namespace WPML\PB\Gutenberg\StringsInBlock;

use WPML\FP\Str;
use WPML\FP\Obj;
use WPML\PB\Gutenberg\StringsInBlock\DOMHandler\DOMHandle;
use WPML\PB\Gutenberg\StringsInBlock\DOMHandler\HtmlBlock;
use WPML\PB\Gutenberg\StringsInBlock\DOMHandler\StandardBlock;
use WPML\PB\Gutenberg\StringsInBlock\DOMHandler\ListBlock;
use WPML\PB\Gutenberg\StringsInBlock\DOMHandler\ListItemBlock;
use WPML\PB\Gutenberg\XPath;

class HTML extends Base {

	const LIST_BLOCK_NAME      = 'core/list';
	const LIST_ITEM_BLOCK_NAME = 'core/list-item';
	const HTML_BLOCK_NAME      = 'core/html';

	public function find( \WP_Block_Parser_Block $block ) {
		$strings = array();

		$block_queries = $this->get_block_queries( $block );

		if ( is_array( $block_queries ) && isset( $block->innerHTML ) ) {
			$dom_handle = $this->get_dom_handler( $block );
			$xpath      = $dom_handle->getDomxpath( $block->innerHTML );

			foreach ( $block_queries as $blockQuery ) {
				list( $query, $definedType, $label ) = XPath::parse( $blockQuery );
				$elements                            = $xpath->query( $query );
				foreach ( $elements as $element ) {
					list( $text, $type ) = $dom_handle->getPartialInnerHTML( $element );
					if ( $text ) {
						$string_id = $this->get_string_id( $block->blockName, $text );
						$strings[] = $this->build_string(
							$string_id,
							$label ?: $this->get_block_label( $block ),
							$text,
							$definedType ? $definedType : $type
						);
					}
				}
			}
		} else {

			$string_id = $this->get_block_string_id( $block );
			if ( $string_id ) {
				$strings[] = $this->build_string(
					$string_id,
					$this->get_block_label( $block ),
					$block->innerHTML,
					'VISUAL'
				);
			}
		}

		return $strings;
	}

	public function update( \WP_Block_Parser_Block $block, array $string_translations, $lang ) {

		$block_queries = $this->get_block_queries( $block );

		if ( $block_queries && isset( $block->innerHTML ) ) {
			$dom_handle = $this->get_dom_handler( $block );
			$dom        = $dom_handle->getDom( $block->innerHTML );
			$xpath      = new \DOMXPath( $dom );

			foreach ( $block_queries as $query ) {
				list( $query, ) = XPath::parse( $query );
				$elements       = $xpath->query( $query );
				foreach ( $elements as $element ) {
					list( $text, ) = $dom_handle->getPartialInnerHTML( $element );
					$translation   = $this->getTranslation( $text, $lang, $block, $string_translations );
					$block         = $this->updateTranslationInBlock(
						$text,
						$this->apply_placeholders_for_html_entities( $translation ),
						$block,
						$element,
						$dom_handle
					);
				}
			}
			$content          = $dom_handle->getFullInnerHTML( $dom->documentElement );
			$block->innerHTML = $this->restore_placeholders_for_html_entities( reset( $content ) );

		} elseif ( isset( $block->blockName, $block->innerHTML ) && '' !== trim( $block->innerHTML ) ) {

			$translation = $this->getTranslation( $block->innerHTML, $lang, $block, $string_translations );

			if ( $translation ) {
				$block->innerHTML = $translation;
			}
		}

		return $block;
	}

	private function get_block_string_id( \WP_Block_Parser_Block $block ) {
		if ( isset( $block->blockName, $block->innerHTML ) && '' !== trim( $block->innerHTML ) ) {
			return $this->get_string_id( $block->blockName, $block->innerHTML );
		} else {
			return null;
		}
	}

	private function get_block_queries( \WP_Block_Parser_Block $block ) {
		return $this->get_block_config( $block, 'xpath' );
	}

	private function get_dom_handler( \WP_Block_Parser_Block $block ) {
		$class = wpml_collect(
			[
				self::LIST_BLOCK_NAME      => ListBlock::class,
				self::HTML_BLOCK_NAME      => HtmlBlock::class,
				self::LIST_ITEM_BLOCK_NAME => ListItemBlock::class,
			]
		)->get( $block->blockName, StandardBlock::class );

		return new $class();
	}

	private function updateTranslationInBlock( $text, $translation, \WP_Block_Parser_Block $block, $element, $dom_handle ) {
		if ( $translation ) {
			$block = $dom_handle->applyStringTranslations( $block, $element, $translation, $text );
			$dom_handle->setElementValue( $element, $translation );
		}

		return $block;
	}

	private function getTranslation( $text, $lang, \WP_Block_Parser_Block $block, array $string_translations ) {
		$translationFromPageBuilder = apply_filters( 'wpml_pb_update_translations_in_content', $text, $lang );
		if ( $translationFromPageBuilder === $text ) {
			$string_id = $this->get_string_id( $block->blockName, $text );
			if ( (int) Obj::path( [ $string_id, $lang, 'status' ], $string_translations ) === ICL_TM_COMPLETE ) {
				$translation = $string_translations[ $string_id ][ $lang ]['value'];
				$translation = $this->encodeStandaloneLessThan( $translation );
				return self::preserveNewLines( $text, $translation );
			} else {
				return null;
			}
		} else {
			return $translationFromPageBuilder;
		}
	}

	private function encodeStandaloneLessThan( $text ) {
		return preg_replace( '/<(?![a-zA-Z][a-zA-Z0-9-:]*[\s\/>]|\/[a-zA-Z]|!)/', '&lt;', $text );
	}

	private static function preserveNewLines( $original, $translation ) {
		$endsWith = function ( $find, $s ) {
			return Str::sub( - Str::len( $find ), $s ) === $find;
		};

		if ( Str::startsWith( "\n", $original ) && ! Str::startsWith( "\n", $translation ) ) {
			$translation = "\n" . $translation;
		}

		if ( $endsWith( "\n", $original ) && ! $endsWith( "\n", $translation ) ) {
			$translation .= "\n";
		}

		return $translation;
	}

	const HTML_ENTITY_PLACEHOLDERS = [
		'&apos;' => 'WPML_PLACEHOLDER_APOS',
		'&quot;' => 'WPML_PLACEHOLDER_QUOT',
	];

	private function apply_placeholders_for_html_entities( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		return str_replace(
			array_keys( self::HTML_ENTITY_PLACEHOLDERS ),
			array_values( self::HTML_ENTITY_PLACEHOLDERS ),
			$content
		);
	}

	private function restore_placeholders_for_html_entities( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		return str_replace(
			array_values( self::HTML_ENTITY_PLACEHOLDERS ),
			array_keys( self::HTML_ENTITY_PLACEHOLDERS ),
			$content
		);
	}
}
