<?php

namespace WPML\Compatibility\Divi;

use SitePress;
use WPML\FP\Obj;

class ThemeBuilder implements \IWPML_Action {

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function add_hooks() {
		if ( ! defined( 'ET_THEME_BUILDER_DIR' ) ) {
			return;
		}

		if ( $this->sitepress->is_setup_complete() ) {
			add_filter( 'wpml_document_view_item_link', [ $this, 'document_view_layout_link' ], 10, 5 );
			add_filter( 'wpml_document_edit_item_link', [ $this, 'document_edit_layout_link' ], 10, 5 );

			if ( is_admin() ) {
				add_action( 'init', [ $this, 'make_layouts_editable' ], 1000 );
			} else {
				add_filter( 'get_post_metadata', [ $this, 'translate_layout_ids' ], 10, 4 );
			}
		}
	}

	private static function get_types() {
		return [
			ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE,
			ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE,
			ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE,
		];
	}

	public function make_layouts_editable() {
		global $wp_post_types;

		foreach ( $this->get_types() as $type ) {
			$wp_post_types[ $type ]->show_ui      = true;
			$wp_post_types[ $type ]->show_in_menu = false;
			$wp_post_types[ $type ]->_edit_link   = 'post.php?post=%d';
		}
	}

	public function translate_layout_ids( $value, $post_id, $key, $single ) {

		if ( in_array( $key, [ '_et_header_layout_id', '_et_body_layout_id', '_et_footer_layout_id' ], true ) ) {
			remove_filter( 'get_post_metadata', [ $this, 'translate_layout_ids' ], 10 );
			$original_id = get_post_meta( $post_id, $key, true );
			add_filter( 'get_post_metadata', [ $this, 'translate_layout_ids' ], 10, 4 );

			$type  = substr( $key, 1, -3 );
			$value = $this->sitepress->get_object_id( $original_id, $type, true );

			if ( ! $single ) {
				$value = [ $value ];
			}
		}

		return $value;
	}

	public function document_view_layout_link( $link, $text, $job, $prefix, $type ) {
		if ( $this->is_theme_layout_row( $prefix, $type ) ) {
			$link = '';
		}

		return $link;
	}

	public function document_edit_layout_link( $link, $oldLabel, $object, $prefix, $type ) {
		if ( $this->is_theme_layout_row( $prefix, $type ) ) {
			$id   = (int) Obj::prop( 'ID', $object );
			$link = admin_url( sprintf( 'post.php?post=%d&action=edit', $id ) );
		}

		return $link;
	}

	private function is_theme_layout_row( $prefix, $type ) {
		return 'post' === $prefix && in_array( $type, $this->get_types(), true );
	}
}
