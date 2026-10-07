<?php

namespace WPML\PB\Gutenberg\StringsInBlock\DOMHandler;

class ListItemBlock extends StandardBlock {


	public function applyStringTranslations( \WP_Block_Parser_Block $block, \DOMNode $element, $translation, $originalValue = null) {
		if ( $element instanceof \DOMElement && $element->childNodes->length > 0 ) {
			$originalValueVariations = $this->getOriginalVariationsForBrAndImgTags( $originalValue );

			$block->innerHTML = str_replace( $originalValueVariations, $translation, $block->innerHTML );
			foreach ( $block->innerContent as &$inner_content ) {
				if ( $inner_content ) {
					$inner_content = str_replace( $originalValueVariations, $translation, $inner_content );
				}
			}
			return $block;
		}

		return parent::applyStringTranslations( $block, $element, $translation, $originalValue );
	}

	private function getOriginalVariationsForBrAndImgTags( $originalValue ) {
		$extractInsideBrOrImgTagsPattern = '/<(br[^>\/\s]*|img[^>]*[^>\/\s])(?:\s*\/)?\s*>/';

		preg_match( $extractInsideBrOrImgTagsPattern, $originalValue, $matches );

		if ( $matches ) {
			return array_unique(
				array_filter(
					[
						$originalValue,
						preg_replace( $extractInsideBrOrImgTagsPattern, '<${1}/>', $originalValue ),
						preg_replace( $extractInsideBrOrImgTagsPattern, '<${1}>', $originalValue ),
					]
				)
			);
		}

		return [ $originalValue ];
	}
}
