<?php

namespace WPML\Core\Menu;

use WPML\LIB\WP\Hooks;
use WPML\LIB\WP\Post;
use function WPML\FP\spreadArgs;

class Translate implements \IWPML_Frontend_Action {

	public function add_hooks() {
		Hooks::onFilter( 'wp_get_nav_menu_items', 10, 2 )
		     ->then( spreadArgs( [ self::class, 'translate' ] ) );
	}

	public static function translate( $items, $menu ) {
		if ( self::doesNotHaveMenuInCurrentLanguage( $menu ) ) {

			$items = wpml_collect( $items )
				->filter( [ self::class, 'hasTranslation' ] )
				->map( [ self::class, 'translateItem' ] )
				->filter( [ self::class, 'canView' ] )
				->values()
				->toArray();
		}

		return $items;
	}

	public static function hasTranslation( $item ) {
		global $sitepress;
		return 'post_type' !== $item->type || (bool) self::getTranslatedId( $item ) || $sitepress->is_display_as_translated_post_type( $item->object );
	}

	public static function translateItem( $item ) {
		if ( 'post_type' === $item->type ) {
			$translatedId = self::getTranslatedId( $item, true );
			$post         = Post::get( $translatedId );
			if ( ! $post instanceof \WP_Post ) {
				return $item;
			}
			foreach ( get_object_vars( $post ) as $key => $value ) {
				if ( ! in_array( $key, [ 'menu_order', 'post_type', 'ID' ] ) ) {
					$item->$key = $value;
				}
			}
			$item->object_id = (string) $translatedId;
			$item->title     = $item->post_title;
		}

		return $item;
	}

	public static function canView( $item ) {
		return current_user_can( 'administrator' ) || 'post_type' !== $item->type || 'draft' !== $item->post_status;
	}

	private static function doesNotHaveMenuInCurrentLanguage( $menu ) {
		return ! wpml_object_id_filter( $menu->term_id, 'nav_menu' );
	}

	private static function getTranslatedId( $item, $return_original_if_missing = false ) {
		return wpml_object_id_filter( $item->object_id, $item->object, $return_original_if_missing );
	}
}
