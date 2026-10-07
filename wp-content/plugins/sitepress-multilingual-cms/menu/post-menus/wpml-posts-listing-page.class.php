<?php

use WPML\Setup\Option;

class WPML_Posts_Listing_Page {
	public function __construct() {
		global $pagenow;

		if ( ! Option::isTMAllowed() ) {
			// Blog License. No extra preload required as Review is not available.
			return;
		}

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		add_action( 'wp', [ $this, 'pre_populate_caches' ] );
	}

	public function pre_populate_caches() {
		global $wp_query, $wpml_post_translations;

		if ( ! $wp_query->posts ) {
			return;
		}

		$post_ids = array_map( function( $post ) {
			return $post->ID;
		}, $wp_query->posts );

		$wpml_post_translations->prefetch_ids( $post_ids );

		$wpml_tm_element_translations = wpml_tm_load_element_translations();
		$wpml_tm_element_translations->init_jobs( $wpml_post_translations->get_trids() );
	}
}
