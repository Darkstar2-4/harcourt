<?php

namespace WPML\PB\Elementor\V4\Component;

use WPML\LIB\WP\Hooks;
use function WPML\FP\spreadArgs;

class Cache implements \IWPML_Frontend_Action, \IWPML_Backend_Action {

	public function add_hooks() {
		Hooks::onAction( 'wpml_pro_translation_completed' )
			->then( spreadArgs( [ $this, 'flush' ] ) );
	}

	public function flush( $postId ) {
		if ( QueryHooks::POST_TYPE === get_post_type( $postId ) ) {
			try {
				\Elementor\Plugin::instance()->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
			}
		}
	}
}
