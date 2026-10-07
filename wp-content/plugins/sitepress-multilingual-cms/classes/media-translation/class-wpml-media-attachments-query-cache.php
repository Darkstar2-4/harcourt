<?php

namespace WPML\Media\Classes;

class WPML_Media_Attachments_Query_Cache {

	private static $cache = [];

	public static function setCacheItem( $cache_prop, $item_index_in_cache, $item ) {
		self::$cache[ $cache_prop ][ $item_index_in_cache ] = $item;
	}

	public static function getCacheItem( $cache_prop, $item_index_in_cache ) {
		return isset( self::$cache[ $cache_prop ][ $item_index_in_cache ] ) ? self::$cache[ $cache_prop ][ $item_index_in_cache ] : null;
	}

	public static function hasCacheItem( $cache_prop, $item_index_in_cache ) {
		return array_key_exists( $item_index_in_cache, isset( self::$cache[ $cache_prop ] ) ? self::$cache[ $cache_prop ] : [] );
	}
}
