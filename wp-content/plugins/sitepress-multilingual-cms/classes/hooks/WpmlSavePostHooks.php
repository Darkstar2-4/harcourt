<?php

namespace WPML\Hooks;

class WpmlSavePostHooks {

	private $sitepress;

	private $wpdb;

	public function __construct( \SitePress $sitepress, \wpdb $wpdb ) {
		$this->sitepress = $sitepress;
		$this->wpdb      = $wpdb;
	}

	public function init_hooks() {
		add_action( 'wp_after_insert_post', array( $this, 'on_post_save' ), 100, 4 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'on_product_save' ), 100, 1 );
	}

	public function on_product_save( $post ) {
		$post = get_post( $post );
		$this->process_post_save( $post, false, null, true );
	}

	private function is_original_post( $post ) {
		if ( 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}

		return (int) $post->ID === (int) $this->sitepress->get_original_element_id( $post->ID, 'post_' . $post->post_type, false, false, false, true );
	}

	private function is_valid_post( $post ) {
		if (
			(int) $post->ID === 0 ||
			'attachment' === $post->post_type ||
			wp_is_post_revision( $post->ID ) ||
			wp_is_post_autosave( $post->ID ) ||
			$post->post_status === 'auto-draft' ||
			$post->post_status === 'trash' ||
			$post->post_status === 'inherit' ||
			! $this->sitepress->is_translated_post_type( $post->post_type )
		) {
			return false;
		}

		return true;
	}

	public function on_post_save( $post_id, $post, $wp_update, $post_before = null ) {
		$this->process_post_save( $post_id, $wp_update, $post_before );
	}

	public function process_post_save( $post, $wp_update, $post_before = null, $is_product = false ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post instanceof \WP_Post || ! $this->is_valid_post( $post ) || ! $this->is_original_post( $post ) ) {
			return;
		}

		if ( $is_product ) {
			$this->executeSaveProductHook( $post );
			return;
		}

		$this->executeSavePostHook( $post );
	}

	public function executeOnPostTranslationSave( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! is_object( $post) || ! $this->is_valid_post( $post ) || $this->is_original_post( $post ) ) {
			return;
		}

		if ( $post->post_type === 'product' ) {
			$this->executeSaveProductHook( $post, true );
			return;
		}

		$this->executeSavePostHook( $post, true );
	}

	private function executeSavePostHook( \WP_Post $post, $isPostTranslation = false ) {
		do_action( 'wpml_save_post', $post->ID, $isPostTranslation );
		do_action( 'wpml_save_post_' . $post->post_type, $post->ID, $isPostTranslation );
	}

	private function executeSaveProductHook( \WP_Post $post, $isPostTranslation = false ) {
		do_action( 'wpml_save_product', $post->ID, $isPostTranslation );
	}
}
