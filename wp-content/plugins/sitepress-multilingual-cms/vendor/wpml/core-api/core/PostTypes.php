<?php

namespace WPML\API;

use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\LIB\WP\PostType;
use WPML\Settings\PostType\Automatic;

class PostTypes {

	public static function getTranslatable() {
		global $sitepress;

		return Obj::keys( $sitepress->get_translatable_documents() );
	}

	public static function getTranslatableWithInfo() {
		global $sitepress;

		$postTypes = $sitepress->get_translatable_documents( true );
		return \apply_filters( 'wpml_get_translatable_types', $postTypes );
	}

	public static function getDisplayAsTranslated() {
		global $sitepress;

		return Obj::keys( $sitepress->get_display_as_translated_documents() );
	}

	public static function getOnlyTranslatable() {
		return Obj::values( Lst::diff( self::getTranslatable(), self::getDisplayAsTranslated() ) );
	}

	public static function getAutomaticTranslatable() {
		$types = self::getTranslatable();

		$filters = Logic::complement( Relation::equals( 'attachment' ) );

		return Fns::filter( $filters, $types );
	}

	public static function withNames( $postTypes ) {
		$getPostTypeName = function ( $postType ) {
			return PostType::getPluralName( $postType )->getOrElse( $postType );
		};
		return Fns::map( $getPostTypeName, $postTypes );
	}
}
