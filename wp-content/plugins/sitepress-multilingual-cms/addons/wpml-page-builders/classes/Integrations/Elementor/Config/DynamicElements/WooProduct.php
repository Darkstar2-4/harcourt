<?php

namespace WPML\PB\Elementor\Config\DynamicElements;

use WPML\FP\Obj;

class WooProduct {

	const DYNAMIC_KEYS = [
		'title_text',
		'description_text',
		'image',
		'link',
		'editor',
		'title',
		'text',
		'content',
		'url',
	];

	private static function get( $tagName ) {
		$converters = [];

		foreach ( self::DYNAMIC_KEYS as $dynamicKey ) {
			$converters[] = self::getConverter( $tagName, $dynamicKey );
		}

		return $converters;
	}

	private static function getConverter( $tagName, $dynamicKey ) {
		$dynamicPath = [ 'settings', '__dynamic__', $dynamicKey ];

		$hasDynamicTag = function ( $item ) use ( $dynamicPath, $tagName ) {
			$value = Obj::path( $dynamicPath, $item );

			return is_string( $value )
				&& strpos( $value, '[elementor-tag' ) !== false
				&& strpos( $value, 'name="' . $tagName . '"' ) !== false;
		};

		$dynamicLens = Obj::lensPath( $dynamicPath );

		return [ $hasDynamicTag, $dynamicLens, $tagName, 'product_id' ];
	}

	public static function getAll() {
		return array_merge(
			self::get( 'woocommerce-product-title-tag' ),
			self::get( 'woocommerce-product-image-tag' ),
			self::get( 'woocommerce-product-price-tag' ),
			self::get( 'woocommerce-product-short-description-tag' ),
			self::get( 'woocommerce-product-content-tag' ),
			self::get( 'woocommerce-product-gallery-tag' ),
			self::get( 'woocommerce-product-stock-tag' ),
			self::get( 'woocommerce-product-rating-tag' ),
			self::get( 'woocommerce-product-sale-tag' ),
			self::get( 'woocommerce-product-terms-tag' ),
			self::get( 'woocommerce-product-add-to-cart-tag' ),
			self::get( 'woocommerce-product-sku-tag' )
		);
	}
}
