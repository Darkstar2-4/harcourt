<?php

namespace WPML\PB\Gutenberg\ConvertIdsInBlock;

use WPML\Convert\Ids;

class Base {

	public function convert( array $block ) {
		return $block;
	}

	public static function convertIds( $ids, $elementSlug, $elementType = null ) {
		$fallbackToOriginal = $elementType ? self::isDisplayedAsTranslated( $elementSlug, $elementType ) : true;

		return Ids::convert( $ids, $elementSlug, $fallbackToOriginal );
	}

	private static function isDisplayedAsTranslated( $slug, $type ) {
		global $sitepress;

		return 'post' === $type
			? $sitepress->is_display_as_translated_post_type( $slug )
			: $sitepress->is_display_as_translated_taxonomy( $slug );
	}
}
