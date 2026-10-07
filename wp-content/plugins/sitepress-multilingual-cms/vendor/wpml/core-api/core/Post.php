<?php


namespace WPML\Element\API;

use WPML\FP\Curryable;

class Post {

	use Curryable;

	public static function init() {

		self::curryN( 'getLang', 1, function ( $postId ) {
			global $wpml_post_translations;

			return $wpml_post_translations->get_element_lang_code( $postId );
		} );

	}

}

Post::init();