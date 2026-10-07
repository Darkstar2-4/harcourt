<?php

namespace WPML\Compatibility\Divi\V5\WooCommerce;

use WPML\LIB\WP\Hooks;
use function WPML\FP\spreadArgs;

class ProductDescriptionCache implements \IWPML_Backend_Action, \IWPML_Frontend_Action {

	const CACHE_KEY_PREFIX = 'divi_wc_product_desc_';

	public function add_hooks() {
		Hooks::onAction( 'wpml_pro_translation_completed' )
			->then( spreadArgs( [ $this, 'clearProductDescriptionCache' ] ) );
	}

	public function clearProductDescriptionCache( $newPostId ) {
		if ( 'product' !== get_post_type( $newPostId ) ) {
			return;
		}

		$this->deleteTransient( $newPostId, 'description' );
		$this->deleteTransient( $newPostId, 'short_description' );
	}

	private function deleteTransient( $productId, $descriptionType ) {
		$cacheKey = self::CACHE_KEY_PREFIX . md5( $productId . '_' . $descriptionType );
		delete_transient( $cacheKey );
	}
}
