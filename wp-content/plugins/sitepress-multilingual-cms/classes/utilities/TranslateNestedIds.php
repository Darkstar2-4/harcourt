<?php

namespace WPML\Utils;

use WPML\Convert\Ids;
use WPML\FP\Obj;
use WPML\FP\Lst;
use WPML\FP\Str;
use WPML\Utils\XmlTranslatableIds;

class TranslateNestedIds {

	private $sitepress;

	public function __construct( $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function convertIds( $ids, $elementType, $elementSlug ) {
		$fallbackToOriginal = $this->isDisplayedAsTranslated( $elementType, $elementSlug );
		return Ids::convert( $ids, $elementSlug, $fallbackToOriginal );
	}

	public function convertByPath( $entry, $path, $elementType, $elementSlug ) {
		$currentKey = reset( $path );
		$nextPath    = Lst::drop( 1, $path );
		$hasWildCard = false !== strpos( $currentKey, '*' );

		if ( $hasWildCard && is_array( $entry ) ) {
			$regex = $this->getWildcardRegex( $currentKey );

			foreach ( $entry as $key => $attr ) {
				if ( Str::match( $regex, $key ) ) {
					$entry[ $key ] = $this->convertByPath( $attr, $nextPath, $elementType, $elementSlug );
				}
			}
		} elseif ( $currentKey && isset( $entry[ $currentKey ] ) ) {
			$entry[ $currentKey ] = $this->convertByPath( $entry[ $currentKey ], $nextPath, $elementType, $elementSlug );
		} elseif ( ! $nextPath ) {
			$entry = $this->convertIds( $entry, $elementType, $elementSlug );
		}

		return $entry;
	}

	private function getWildcardRegex( $key ) {
		return '/^' . str_replace( '*', 'S+', preg_quote( $key, '/' ) ) . '$/';
	}

	private function isDisplayedAsTranslated( $type, $slug ) {
		if ( in_array( $slug, [ Ids::ANY_POST, Ids::ANY_TERM ], true ) ) {
			return true;
		}
		if ( XmlTranslatableIds::TYPE_POST_IDS === $type && $this->sitepress->is_display_as_translated_post_type( $slug ) ) {
			return true;
		}
		if ( XmlTranslatableIds::TYPE_TAXONOMY_IDS === $type && $this->sitepress->is_display_as_translated_taxonomy( $slug ) ) {
			return true;
		}
		return false;
	}

}
